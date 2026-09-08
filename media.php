<?php
declare(strict_types=1);

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

final class MediaQueueException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message,public readonly ?int $retryAfter=null)
    {
        parent::__construct($message);
    }
}

final class MediaQueue
{
    private const VIDEO_EXTENSIONS = ['mp4','m4v','mov','webm','mkv','avi','flv','ts','m3u8'];
    private const FINAL_STATUSES = ['completed','failed','cancelled'];

    public static function createBatch(int $productId,string $rawLinks,string $title='',string $caption='',string $uploadMode='auto',int $maxAttempts=3,string $createdBy='panel'): int
    {
        $product=App::one('SELECT * FROM products WHERE id=?',[$productId]);
        if(!$product)throw new RuntimeException('محصول یا کانال مقصد پیدا نشد.');
        self::assertCanPost((string)$product['channel_id']);
        $links=self::extractLinks($rawLinks);
        $limit=max(1,min(500,(int)App::setting('downloader_batch_limit','100')));
        if(!$links)throw new RuntimeException('هیچ لینک معتبر HTTP یا HTTPS وارد نشده است.');
        if(count($links)>$limit)throw new RuntimeException("حداکثر {$limit} لینک در هر دسته قابل ثبت است.");
        $uploadMode=in_array($uploadMode,['auto','video','document'],true)?$uploadMode:'auto';
        $maxAttempts=max(1,min(5,$maxAttempts));
        $title=trim($title)!==''?trim($title):'آپلود '.date('Y/m/d H:i');
        $prepared=[];foreach($links as $url){self::validateUrl($url);$prepared[]=['url'=>$url,'host'=>(string)(parse_url($url,PHP_URL_HOST)??'')];}
        $pdo=App::db();$pdo->beginTransaction();
        try{
            App::q("INSERT INTO media_batches(product_id,channel_id,title,caption_template,upload_mode,status,total_items,created_by,created_at,updated_at) VALUES (?,?,?,?,?,'queued',?,?,NOW(),NOW())",[$productId,$product['channel_id'],mb_substr($title,0,255),mb_substr($caption,0,3000),$uploadMode,count($links),mb_substr($createdBy,0,64)]);
            $batchId=(int)$pdo->lastInsertId();
            $st=$pdo->prepare("INSERT INTO media_jobs(batch_id,position,source_url,source_host,status,max_attempts,created_at,updated_at) VALUES (?,?,?,?,'queued',?,NOW(),NOW())");
            foreach($prepared as $index=>$item){
                $st->execute([$batchId,$index+1,$item['url'],mb_substr(strtolower($item['host']),0,255),$maxAttempts]);
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        App::logEvent('media_batch_created','دسته دانلود جدید ثبت شد',['batch_id'=>$batchId,'product_id'=>$productId,'items'=>count($links)]);
        return $batchId;
    }

    public static function createTelegramChannelBatch(int $productId,string $sourceChannel,string $title='',int $maxAttempts=3,bool $skipExisting=true,string $createdBy='panel',bool $splitDestinations=false,string $secondDestination='',string $thirdDestination='',int $destinationLimit=2000,int $pipelineDepth=4): array
    {
        $sourceChannel=self::normaliseChannelId($sourceChannel,'مبدأ');
        $product=App::one('SELECT id,title,channel_id FROM products WHERE id=?',[$productId]);
        if(!$product)throw new RuntimeException('محصول یا کانال مقصد پیدا نشد.');
        $primary=self::normaliseChannelId((string)$product['channel_id'],'مقصد اول');
        $destinations=[$primary];
        if($splitDestinations){
            $destinations[]=self::normaliseChannelId($secondDestination,'مقصد دوم');
            if(trim($thirdDestination)!=='')$destinations[]=self::normaliseChannelId($thirdDestination,'مقصد سوم');
        }
        if(count(array_unique($destinations))!==count($destinations))throw new RuntimeException('کانال‌های مقصد باید متفاوت باشند.');
        if(in_array($sourceChannel,$destinations,true))throw new RuntimeException('کانال مبدأ نمی‌تواند یکی از کانال‌های مقصد باشد.');
        foreach($destinations as $destination)self::assertCanPost($destination);
        if(!self::historyScannerStatus()['ready'])throw new RuntimeException('ابتدا حساب تلگرام را از بخش «تنظیم اسکنر کانال» متصل کنید.');
        $maxAttempts=max(1,min(5,$maxAttempts));$createdBy=mb_substr($createdBy,0,64);$destinationLimit=max(1,min(100000,$destinationLimit));$pipelineDepth=max(4,min(8,$pipelineDepth));
        $title=trim($title)!==''?trim($title):'انتقال کانال '.$sourceChannel;
        $distributionMode=count($destinations)>1?'chunked':'single';
        App::q("INSERT INTO media_batches(product_id,channel_id,title,caption_template,upload_mode,source_type,source_channel_id,sequential_mode,pipeline_depth,distribution_mode,destination_channels_json,destination_limit,scan_status,scan_attempts,scan_max_attempts,scan_next_attempt_at,scan_options_json,status,total_items,created_by,created_at,updated_at) VALUES (?,?,?,'','auto','telegram_channel',?,1,?,?,?,?, 'queued',0,?,NOW(),?,'queued',0,?,NOW(),NOW())",[$productId,$primary,mb_substr($title,0,255),$sourceChannel,$pipelineDepth,$distributionMode,App::j($destinations),$distributionMode==='chunked'?$destinationLimit:0,$maxAttempts,App::j(['skip_existing'=>$skipExisting]),$createdBy]);
        $batchId=(int)App::db()->lastInsertId();
        App::logEvent('telegram_channel_import_queued','اسکن کانال و ساخت صف مستقل ثبت شد.',['batch_id'=>$batchId,'product_id'=>$productId,'source_channel_id'=>$sourceChannel,'destinations'=>$destinations,'destination_limit'=>$distributionMode==='chunked'?$destinationLimit:0,'pipeline_depth'=>$pipelineDepth]);
        return ['batch_id'=>$batchId,'scanned'=>0,'queued'=>0,'skipped'=>0,'channel_title'=>'','destinations'=>$destinations,'scan_status'=>'queued'];
    }

    public static function updateTelegramChannelBatch(int $batchId,string $title,string $secondDestination='',string $thirdDestination='',int $destinationLimit=2000,int $maxAttempts=3,int $pipelineDepth=4): void
    {
        $batch=App::one('SELECT * FROM media_batches WHERE id=?',[$batchId]);
        if(!$batch||($batch['source_type']??'')!=='telegram_channel')throw new RuntimeException('صف انتقال تلگرامی پیدا نشد.');
        if(($batch['scan_status']??'')==='scanning')throw new RuntimeException('اسکن هم‌اکنون فعال است؛ ابتدا «توقف» را بزنید و پس از توقف Worker صف را ویرایش کنید.');
        if(($batch['status']??'')==='cancelled')throw new RuntimeException('صف لغوشده قابل ویرایش نیست.');
        $primary=self::normaliseChannelId((string)$batch['channel_id'],'مقصد اول');$source=self::normaliseChannelId((string)$batch['source_channel_id'],'مبدأ');$destinations=[$primary];
        if(trim($secondDestination)!=='')$destinations[]=self::normaliseChannelId($secondDestination,'مقصد دوم');
        if(trim($thirdDestination)!==''){
            if(count($destinations)<2)throw new RuntimeException('برای ثبت مقصد سوم، مقصد دوم را نیز وارد کنید.');
            $destinations[]=self::normaliseChannelId($thirdDestination,'مقصد سوم');
        }
        if(count(array_unique($destinations))!==count($destinations))throw new RuntimeException('کانال‌های مقصد باید متفاوت باشند.');
        if(in_array($source,$destinations,true))throw new RuntimeException('کانال مبدأ نمی‌تواند یکی از کانال‌های مقصد باشد.');
        foreach($destinations as $destination)self::assertCanPost($destination);
        $destinationLimit=max(1,min(100000,$destinationLimit));$maxAttempts=max(1,min(5,$maxAttempts));$pipelineDepth=max(4,min(8,$pipelineDepth));$distribution=count($destinations)>1?'chunked':'single';$title=trim($title)!==''?mb_substr(trim($title),0,255):(string)$batch['title'];
        $pdo=App::db();$pdo->beginTransaction();
        try{
            $locked=App::one('SELECT * FROM media_batches WHERE id=? FOR UPDATE',[$batchId]);
            if(!$locked||($locked['scan_status']??'')==='scanning')throw new RuntimeException('Worker اسکن صف را هم‌زمان فعال کرده است؛ ابتدا صف را متوقف کنید.');
            $busy=(int)(App::one("SELECT COUNT(*) c FROM media_jobs WHERE batch_id=? AND (status<>'queued' OR file_path IS NOT NULL)",[$batchId])['c']??0);
            if($busy>0)throw new RuntimeException('پس از شروع دانلود یا آپلود، تغییر مسیر مقصد امن نیست. فقط عنوان و Retry را در نسخه بعدی می‌توان جداگانه ویرایش کرد.');
            $jobCount=(int)(App::one('SELECT COUNT(*) c FROM media_jobs WHERE batch_id=?',[$batchId])['c']??0);$capacity=$distribution==='chunked'?count($destinations)*$destinationLimit:PHP_INT_MAX;
            if($jobCount>$capacity)throw new RuntimeException('ظرفیت جدید از تعداد لینک‌های ساخته‌شده کمتر است. حداقل ظرفیت کل لازم: '.number_format($jobCount).' ویدیو.');
            $wasFailed=($locked['scan_status']??'')==='failed';$scanStatus=$wasFailed?'queued':(string)$locked['scan_status'];$scanAttempts=$wasFailed?0:min((int)$locked['scan_attempts'],$maxAttempts);$scanNext=$wasFailed?date('Y-m-d H:i:s'):($locked['scan_next_attempt_at']??null);$scanError=$wasFailed?null:($locked['scan_error']??null);$batchStatus=$wasFailed?'queued':(string)$locked['status'];$completedAt=$wasFailed?null:($locked['completed_at']??null);
            App::q("UPDATE media_batches SET title=?,distribution_mode=?,destination_channels_json=?,destination_limit=?,scan_max_attempts=?,pipeline_depth=?,scan_status=?,scan_attempts=?,scan_next_attempt_at=?,scan_error=?,status=?,completed_at=?,updated_at=NOW() WHERE id=?",[$title,$distribution,App::j($destinations),$distribution==='chunked'?$destinationLimit:0,$maxAttempts,$pipelineDepth,$scanStatus,$scanAttempts,$scanNext,$scanError,$batchStatus,$completedAt,$batchId]);
            foreach($destinations as $index=>$destination){$slot=$index+1;$start=$distribution==='chunked'?$index*$destinationLimit+1:1;$end=$distribution==='chunked'?($index+1)*$destinationLimit:PHP_INT_MAX;App::q('UPDATE media_jobs SET target_channel_id=?,target_slot=?,target_sequence=position-?+1,max_attempts=?,updated_at=NOW() WHERE batch_id=? AND position BETWEEN ? AND ?',[$destination,$slot,$start,$maxAttempts,$batchId,$start,$end]);}
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        App::logEvent('telegram_channel_batch_updated','ظرفیت، مقصدها و Pipeline صف انتقال ویرایش شد.',['batch_id'=>$batchId,'destinations'=>$destinations,'destination_limit'=>$distribution==='chunked'?$destinationLimit:0,'max_attempts'=>$maxAttempts,'pipeline_depth'=>$pipelineDepth]);
        self::eventForBatch($batchId,'info','routing','ظرفیت و مقصدهای صف ویرایش شد؛ Jobها و Checkpoint حفظ شدند.');
    }

    private static function normaliseChannelId(string $channelId,string $label): string
    {
        $channelId=trim($channelId);
        if(!preg_match('/^-?[1-9][0-9]{4,20}$/',$channelId))throw new RuntimeException("آیدی کانال {$label} باید عددی باشد؛ مانند ‎-1001234567890.");
        return $channelId;
    }

    private static function destinationForPosition(array $channels,int $limit,int $position): ?array
    {
        if($position<1||$limit<1||$channels===[])return null;
        $slot=(int)floor(($position-1)/$limit)+1;
        if(!isset($channels[$slot-1]))return null;
        return ['channel_id'=>(string)$channels[$slot-1],'slot'=>$slot,'sequence'=>(($position-1)%$limit)+1];
    }

    private static function sourceAlreadyCompletedForDestinations(int $productId,string $chatId,int $messageId,int $batchId,array $destinations): bool
    {
        $destinations=array_values(array_unique(array_filter(array_map(static fn($value):string=>trim((string)$value),$destinations),static fn(string $value):bool=>$value!=='')));
        if($destinations===[])return false;
        // Do not COALESCE target_channel_id with batch.channel_id in SQL: old
        // databases may keep these columns under different collations and native
        // prepared parameters can arrive as binary, which makes MariaDB raise 1270.
        $marks=implode(',',array_fill(0,count($destinations),'CAST(? AS BINARY)'));
        $params=[$productId,$chatId,$messageId,$batchId,...$destinations,...$destinations];
        $sql="SELECT 1 FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id WHERE b.product_id=? AND j.source_chat_id=? AND j.source_message_id=? AND b.id<>? AND j.status='completed' AND j.telegram_message_id IS NOT NULL AND ((COALESCE(OCTET_LENGTH(j.target_channel_id),0)>0 AND CAST(j.target_channel_id AS BINARY) IN ({$marks})) OR (COALESCE(OCTET_LENGTH(j.target_channel_id),0)=0 AND CAST(b.channel_id AS BINARY) IN ({$marks}))) LIMIT 1";
        return App::one($sql,$params)!==null;
    }

    public static function extractLinks(string $raw): array
    {
        preg_match_all('~https?://[^\s<>"\']+~iu',$raw,$matches);
        $seen=[];$links=[];
        foreach($matches[0]??[] as $url){
            $url=preg_replace('/[.,;!?)،؛]+$/u','',trim($url))??trim($url);
            if($url!==''&&!isset($seen[$url])){$seen[$url]=true;$links[]=$url;}
        }
        return $links;
    }

    public static function pauseBatch(int $batchId): void
    {
        App::q("UPDATE media_batches SET status='paused',updated_at=NOW() WHERE id=? AND status IN ('queued','running')",[$batchId]);
        self::eventForBatch($batchId,'warning','control','دسته توسط مدیر متوقف شد.');
    }

    public static function resumeBatch(int $batchId): void
    {
        App::q("UPDATE media_batches SET status='queued',completed_at=NULL,updated_at=NOW() WHERE id=? AND status='paused'",[$batchId]);
        self::eventForBatch($batchId,'info','control','پردازش دسته ادامه یافت.');
    }

    public static function cancelBatch(int $batchId): void
    {
        App::q("UPDATE media_batches SET status='cancelled',scan_status=IF(scan_status IN ('queued','scanning'),'cancelled',scan_status),scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,completed_at=NOW(),updated_at=NOW() WHERE id=? AND status NOT IN ('completed','completed_with_errors','cancelled')",[$batchId]);
        App::q("UPDATE media_jobs SET status='cancelled',locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,finished_at=NOW(),updated_at=NOW() WHERE batch_id=? AND status NOT IN ('completed','failed','cancelled')",[$batchId]);
        foreach(App::all('SELECT id,file_path FROM media_jobs WHERE batch_id=?',[$batchId]) as $row)self::purgeJobFiles((int)$row['id'],(string)($row['file_path']??''));
        self::eventForBatch($batchId,'warning','control','دسته توسط مدیر لغو شد.');
        self::syncBatch($batchId);
    }

    public static function retryFailed(int $batchId): int
    {
        $batch=App::one('SELECT status,source_type,scan_status FROM media_batches WHERE id=?',[$batchId]);if(!$batch)return 0;
        $resumeCancelled=(string)$batch['status']==='cancelled';
        $jobWhere=$resumeCancelled?"status IN ('failed','cancelled')":"status='failed'";
        $count=(int)(App::one("SELECT COUNT(*) c FROM media_jobs WHERE batch_id=? AND {$jobWhere}",[$batchId])['c']??0);
        $scan=(($batch['source_type']??'')==='telegram_channel'&&in_array((string)($batch['scan_status']??''),['failed','cancelled'],true))?1:0;
        if($count>0)App::q("UPDATE media_jobs SET status=IF(file_path IS NULL,'queued','downloaded'),progress=IF(file_path IS NULL,0,70),attempts=0,download_attempts=0,upload_attempts=0,next_attempt_at=NULL,error_code=NULL,error_message=NULL,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,heartbeat_at=NULL,finished_at=NULL,updated_at=NOW() WHERE batch_id=? AND {$jobWhere}",[$batchId]);
        if($scan>0)App::q("UPDATE media_batches SET scan_status='queued',scan_attempts=0,scan_next_attempt_at=NOW(),scan_error=NULL,scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,status='queued',pipeline_depth=IF(source_type='telegram_channel',GREATEST(pipeline_depth,4),pipeline_depth),failed_items=0,completed_at=NULL,notification_status=NULL,notification_sent_at=NULL,updated_at=NOW() WHERE id=?",[$batchId]);
        elseif($count>0||$resumeCancelled)App::q("UPDATE media_batches SET status='queued',pipeline_depth=IF(source_type='telegram_channel',GREATEST(pipeline_depth,4),pipeline_depth),failed_items=0,completed_at=NULL,notification_status=NULL,notification_sent_at=NULL,updated_at=NOW() WHERE id=?",[$batchId]);
        if($count+$scan>0)self::eventForBatch($batchId,'info','retry',($scan>0?'اسکن از Checkpoint و ':'')."{$count} Job باقی‌مانده برای ادامه واقعی صف فعال شد.");
        return $count+$scan;
    }

    public static function processNext(int $limit=1): array
    {
        $download=self::processDownloadNext($limit,'cron-download-'.getmypid());
        $upload=self::processUploadNext($limit,'cron-upload-'.getmypid());
        return ['processed'=>$download['processed']+$upload['processed'],'completed'=>$upload['completed'],'failed'=>$download['failed']+$upload['failed'],'retried'=>$download['retried']+$upload['retried'],'download'=>$download,'upload'=>$upload,'locked'=>false];
    }

    public static function processDownloadNext(int $limit=1,string $workerId=''): array
    {
        $workerId=self::normaliseWorkerId($workerId,'download');$limit=max(1,min(20,$limit));
        $result=['role'=>'download','processed'=>0,'scans'=>0,'downloaded'=>0,'failed'=>0,'retried'=>0];
        self::registerWorker($workerId,'download');self::maintenance();
        for($i=0;$i<$limit;$i++){
            $scan=self::claimChannelScan($workerId);
            if($scan){$result['processed']++;$result['scans']++;try{self::processChannelScanBatch($scan,$workerId);self::finishWorkerJob($workerId,true);}catch(Throwable $e){$retry=self::failChannelScan($scan,$e);$retry?$result['retried']++:$result['failed']++;self::finishWorkerJob($workerId,false,$e->getMessage());}continue;}
            $job=self::claimJob('download',$workerId);if(!$job)break;$result['processed']++;
            try{self::processDownloadJob($job);$result['downloaded']++;self::finishWorkerJob($workerId,true);}
            catch(Throwable $e){$retry=self::failJob($job,$e,'download');$retry?$result['retried']++:$result['failed']++;self::finishWorkerJob($workerId,false,$e->getMessage());}
            self::syncBatch((int)$job['batch_id']);
        }
        self::heartbeatWorker($workerId,'idle');return $result;
    }

    private static function claimChannelScan(string $workerId): ?array
    {
        self::registerWorker($workerId,'download');
        $leaseSeconds=self::scanLeaseSeconds();
        $claimLock='freebot-channel-scan-claim';$locked=(int)(App::one('SELECT GET_LOCK(?,1) acquired',[$claimLock])['acquired']??0)===1;if(!$locked)return null;
        try{
            for($attempt=0;$attempt<10;$attempt++){
                $candidate=App::one("SELECT b.id FROM media_batches b WHERE b.source_type='telegram_channel' AND b.scan_status IN ('queued','scanning') AND b.status IN ('queued','running') AND b.scan_attempts<b.scan_max_attempts AND (b.scan_next_attempt_at IS NULL OR b.scan_next_attempt_at<=NOW()) AND (b.scan_status='queued' OR b.scan_lock_expires_at IS NULL OR b.scan_lock_expires_at<NOW()) AND NOT EXISTS (SELECT 1 FROM media_batches active_scan WHERE active_scan.scan_status='scanning' AND active_scan.scan_lock_expires_at>=NOW()) ORDER BY b.id LIMIT 1");
                if(!$candidate)return null;
                $token=bin2hex(random_bytes(32));
                $claimed=App::q("UPDATE media_batches SET scan_status='scanning',scan_attempts=scan_attempts+1,scan_locked_by=?,scan_lock_token=?,scan_lock_expires_at=DATE_ADD(NOW(),INTERVAL {$leaseSeconds} SECOND),scan_heartbeat_at=NOW(),scan_next_attempt_at=NULL,scan_error=NULL,status='running',started_at=COALESCE(started_at,NOW()),updated_at=NOW() WHERE id=? AND source_type='telegram_channel' AND scan_attempts<scan_max_attempts AND (scan_status='queued' OR scan_lock_expires_at IS NULL OR scan_lock_expires_at<NOW())",[$workerId,$token,$candidate['id']])->rowCount();
                if($claimed!==1)continue;
                self::heartbeatWorker($workerId,'busy');
                return App::one('SELECT * FROM media_batches WHERE id=?',[$candidate['id']]);
            }
            return null;
        }finally{try{App::q('SELECT RELEASE_LOCK(?)',[$claimLock]);}catch(Throwable){}}
    }

    private static function processChannelScanBatch(array $batch,string $workerId): void
    {
        $batchId=(int)$batch['id'];$token=(string)$batch['scan_lock_token'];$productId=(int)$batch['product_id'];
        $destinations=json_decode((string)($batch['destination_channels_json']??''),true);if(!is_array($destinations)||$destinations===[])$destinations=[(string)$batch['channel_id']];$destinations=array_values(array_map('strval',$destinations));
        $options=json_decode((string)($batch['scan_options_json']??''),true);if(!is_array($options))$options=[];$skipExisting=(bool)($options['skip_existing']??true);
        $distribution=(string)$batch['distribution_mode'];$destinationLimit=$distribution==='chunked'?max(1,(int)$batch['destination_limit']):PHP_INT_MAX;$maxAttempts=max(1,(int)$batch['scan_max_attempts']);
        $position=(int)(App::one('SELECT COUNT(*) c FROM media_jobs WHERE batch_id=?',[$batchId])['c']??0);$skipped=max(0,(int)($batch['source_skipped_items']??0));$checkpoint=max(0,(int)($batch['source_last_message_id']??0));$detected=max($position,(int)($batch['source_video_count']??0));$leaseSeconds=self::scanLeaseSeconds();
        $heartbeat=static function()use($batchId,$token,$workerId,$leaseSeconds):bool{
            App::q("UPDATE media_batches SET scan_heartbeat_at=NOW(),scan_lock_expires_at=DATE_ADD(NOW(),INTERVAL {$leaseSeconds} SECOND),updated_at=NOW() WHERE id=? AND scan_lock_token=? AND scan_status='scanning' AND status='running'",[$batchId,$token]);
            self::heartbeatWorker($workerId,'busy');
            return App::one("SELECT 1 ok FROM media_batches WHERE id=? AND scan_lock_token=? AND scan_status='scanning' AND status='running' LIMIT 1",[$batchId,$token])!==null;
        };
        $summary=self::streamTelegramVideoList((string)$batch['source_channel_id'],static function(array $item)use($productId,$batchId,$maxAttempts,$skipExisting,$destinations,$destinationLimit,$distribution,&$position,&$skipped,&$checkpoint):void{
            $chatId=trim((string)($item['source_chat_id']??''));$messageId=max(0,(int)($item['message_id']??0));if($chatId===''||$messageId<=0)return;
            if(App::one('SELECT 1 FROM media_jobs WHERE batch_id=? AND source_chat_id=? AND source_message_id=? LIMIT 1',[$batchId,$chatId,$messageId])){$checkpoint=max($checkpoint,$messageId);App::q('UPDATE media_batches SET source_last_message_id=GREATEST(source_last_message_id,?),updated_at=NOW() WHERE id=?',[$checkpoint,$batchId]);return;}
            if($skipExisting&&self::sourceAlreadyCompletedForDestinations($productId,$chatId,$messageId,$batchId,$destinations)){$skipped++;$checkpoint=max($checkpoint,$messageId);App::q('UPDATE media_batches SET source_last_message_id=GREATEST(source_last_message_id,?),source_skipped_items=?,updated_at=NOW() WHERE id=?',[$checkpoint,$skipped,$batchId]);return;}
            $position++;$route=self::destinationForPosition($destinations,$destinationLimit,$position);if($route===null)throw new RuntimeException('تعداد ویدیوها از ظرفیت مقصدها بیشتر است. ظرفیت فعلی: '.number_format(count($destinations)*$destinationLimit).' ویدیو. سقف هر کانال یا تعداد مقصدها را افزایش دهید.');
            $source='tgmtproto://channel/'.$chatId.'/'.$messageId;$fileName=self::sanitizeFileName((string)($item['file_name']??('telegram-'.$messageId.'.mp4')));if(pathinfo($fileName,PATHINFO_EXTENSION)==='')$fileName.='.mp4';
            $pdo=App::db();$pdo->beginTransaction();try{App::q("INSERT INTO media_jobs(batch_id,position,source_url,source_host,detected_title,engine,status,max_attempts,total_bytes,file_name,mime_type,source_chat_id,source_message_id,source_date,target_channel_id,target_slot,target_sequence,created_at,updated_at) VALUES (?,?,?,?,?,'telegram-mtproto','queued',?,?,?,?,?,?,?,?,?,?,NOW(),NOW())",[$batchId,$position,$source,'telegram-mtproto',mb_substr((string)($item['title']??pathinfo($fileName,PATHINFO_FILENAME)),0,500),$maxAttempts,max(0,(int)($item['file_size']??0)),$fileName,mb_substr((string)($item['mime_type']??'video/mp4'),0,120),$chatId,$messageId,trim((string)($item['date']??''))?:null,$route['channel_id'],$route['slot'],$route['sequence']]);$checkpoint=max($checkpoint,$messageId);App::q('UPDATE media_batches SET source_last_message_id=GREATEST(source_last_message_id,?),source_scanned_items=?,total_items=?,updated_at=NOW() WHERE id=?',[$checkpoint,$position,$position,$batchId]);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$position--;throw $e;}
        },$heartbeat,$checkpoint,static function(array $inventory)use($batchId,$token,$position,&$detected):void{
            $detected=max($position,max(0,(int)($inventory['video_count_total']??0)));
            App::q("UPDATE media_batches SET source_channel_id=COALESCE(NULLIF(?,''),source_channel_id),source_channel_title=COALESCE(NULLIF(?,''),source_channel_title),source_video_count=?,scan_heartbeat_at=NOW(),updated_at=NOW() WHERE id=? AND scan_lock_token=? AND scan_status='scanning'",[(string)($inventory['channel_id']??''),mb_substr((string)($inventory['channel_title']??''),0,255),$detected,$batchId,$token]);
            if(App::one("SELECT 1 ok FROM media_batches WHERE id=? AND scan_lock_token=? AND scan_status='scanning' LIMIT 1",[$batchId,$token])===null)throw new MediaQueueException('LEASE_LOST','قفل اسکن هنگام ثبت آمار اولیه از دست رفت.');
        });
        if(!$heartbeat())throw new MediaQueueException('SCAN_CANCELLED','اسکن توسط مدیر متوقف یا قفل آن منتقل شد.');
        $channelTitle=mb_substr((string)($summary['channel_title']??''),0,255);$scanned=$position;$lastId=max($checkpoint,(int)($summary['last_message_id']??0));
        $detected=max($detected,(int)($summary['video_count_total']??0),$position);
        $updated=App::q("UPDATE media_batches SET source_channel_id=?,source_channel_title=?,source_last_message_id=?,source_scanned_items=?,source_skipped_items=?,source_video_count=?,total_items=?,scan_status='completed',scan_error=NULL,scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,status=?,completed_at=?,updated_at=NOW() WHERE id=? AND scan_lock_token=?",[(string)($summary['channel_id']??$batch['source_channel_id']),$channelTitle?:null,$lastId,$scanned,$skipped,$detected,$position,$position>0?'queued':'completed',$position>0?null:date('Y-m-d H:i:s'),$batchId,$token])->rowCount();
        if($updated!==1)throw new MediaQueueException('LEASE_LOST','قفل اسکن کانال از این Worker گرفته شد.');
        App::logEvent('telegram_channel_imported','کانال مبدأ در Worker اسکن و صف انتقال ساخته شد.',['batch_id'=>$batchId,'product_id'=>$productId,'videos'=>$scanned,'queued'=>$position,'skipped'=>$skipped]);
    }

    private static function failChannelScan(array $batch,Throwable $e): bool
    {
        $batchId=(int)$batch['id'];$fresh=App::one('SELECT status,scan_status,scan_attempts,scan_max_attempts,scan_lock_token FROM media_batches WHERE id=?',[$batchId]);if(!$fresh)return false;
        if((string)$fresh['status']==='cancelled'||(string)$fresh['scan_status']==='cancelled')return false;
        $message=self::cleanError($e->getMessage());$code=$e instanceof MediaQueueException?$e->errorCode:'UNEXPECTED';
        $claimedToken=(string)($batch['scan_lock_token']??'');$freshToken=(string)($fresh['scan_lock_token']??'');
        if($freshToken!==''&&!hash_equals($freshToken,$claimedToken))return true;
        if(in_array($code,['SCAN_CANCELLED','LEASE_LOST'],true)){
            App::q("UPDATE media_batches SET scan_status='queued',scan_attempts=IF(scan_attempts>0,scan_attempts-1,0),scan_next_attempt_at=NOW(),scan_error=?,scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,status=IF(status='paused','paused','queued'),updated_at=NOW() WHERE id=? AND (scan_lock_token=? OR scan_lock_token IS NULL)",[$message,$batchId,$claimedToken]);
            return true;
        }
        $retry=(int)$fresh['scan_attempts']<(int)$fresh['scan_max_attempts'];
        if((string)$fresh['status']==='paused'){$retry=true;App::q("UPDATE media_batches SET scan_status='queued',scan_next_attempt_at=NOW(),scan_error=?,scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,updated_at=NOW() WHERE id=?",[$message,$batchId]);}
        elseif($retry){$delay=min(900,15*(2**max(0,(int)$fresh['scan_attempts']-1)));App::q("UPDATE media_batches SET scan_status='queued',scan_next_attempt_at=DATE_ADD(NOW(),INTERVAL {$delay} SECOND),scan_error=?,scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,status='queued',updated_at=NOW() WHERE id=?",[$message,$batchId]);}
        else App::q("UPDATE media_batches SET scan_status='failed',scan_error=?,scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,status='completed_with_errors',completed_at=NOW(),updated_at=NOW() WHERE id=?",[$message,$batchId]);
        App::logEvent('telegram_channel_import_failed',$message,['batch_id'=>$batchId,'retry'=>$retry]);return $retry;
    }

    public static function processUploadNext(int $limit=1,string $workerId=''): array
    {
        $workerId=self::normaliseWorkerId($workerId,'upload');$limit=max(1,min(20,$limit));
        $result=['role'=>'upload','processed'=>0,'completed'=>0,'failed'=>0,'retried'=>0];
        self::registerWorker($workerId,'upload');self::maintenance();
        for($i=0;$i<$limit;$i++){
            $job=self::claimJob('upload',$workerId);if(!$job)break;$result['processed']++;
            try{self::processUploadJob($job);$result['completed']++;self::finishWorkerJob($workerId,true);}
            catch(Throwable $e){$retry=self::failJob($job,$e,'upload');$retry?$result['retried']++:$result['failed']++;self::finishWorkerJob($workerId,false,$e->getMessage());}
            self::syncBatch((int)$job['batch_id']);
        }
        self::heartbeatWorker($workerId,'idle');return $result;
    }

    private static function claimJob(string $role,string $workerId): ?array
    {
        // Keep transfer execution parallel, but serialize the very short download
        // admission step so large workers cannot over-commit temporary disk space.
        self::registerWorker($workerId,$role);
        $status=$role==='download'?'queued':'downloaded';$lease=self::lockSeconds();
        $orderGuard=$role==='download'
            ?"(COALESCE(b.sequential_mode,0)=0 OR NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND previous_job.position<=GREATEST(CAST(j.position AS SIGNED)-CAST(GREATEST(1,COALESCE(b.pipeline_depth,1)) AS SIGNED),0) AND previous_job.status NOT IN ('completed','failed','cancelled')))"
            :"(COALESCE(b.sequential_mode,0)=0 OR NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND ((COALESCE(OCTET_LENGTH(previous_job.target_channel_id),0)>0 AND COALESCE(OCTET_LENGTH(j.target_channel_id),0)>0 AND CAST(previous_job.target_channel_id AS BINARY)=CAST(j.target_channel_id AS BINARY)) OR (COALESCE(OCTET_LENGTH(previous_job.target_channel_id),0)>0 AND COALESCE(OCTET_LENGTH(j.target_channel_id),0)=0 AND CAST(previous_job.target_channel_id AS BINARY)=CAST(b.channel_id AS BINARY)) OR (COALESCE(OCTET_LENGTH(previous_job.target_channel_id),0)=0 AND COALESCE(OCTET_LENGTH(j.target_channel_id),0)>0 AND CAST(b.channel_id AS BINARY)=CAST(j.target_channel_id AS BINARY)) OR (COALESCE(OCTET_LENGTH(previous_job.target_channel_id),0)=0 AND COALESCE(OCTET_LENGTH(j.target_channel_id),0)=0)) AND previous_job.status NOT IN ('completed','failed','cancelled') AND IF(b.source_type='telegram_channel',COALESCE(previous_job.target_sequence,previous_job.position)<=GREATEST(CAST(COALESCE(j.target_sequence,j.position) AS SIGNED)-CAST(GREATEST(1,COALESCE(b.pipeline_depth,1)) AS SIGNED),0),previous_job.position<j.position)))";
        $claimLock=$role==='download'?'freebot-download-admission':'';$claimLocked=true;
        if($claimLock!=='')$claimLocked=(int)(App::one('SELECT GET_LOCK(?,2) acquired',[$claimLock])['acquired']??0)===1;
        if(!$claimLocked)return null;
        try{
            for($attempt=0;$attempt<10;$attempt++){
                $candidate=App::one("SELECT j.id,j.engine,j.total_bytes FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id WHERE j.status=? AND b.status IN ('queued','running') AND COALESCE(b.scan_status,'completed')='completed' AND (j.next_attempt_at IS NULL OR j.next_attempt_at<=NOW()) AND (j.lock_expires_at IS NULL OR j.lock_expires_at<NOW()) AND {$orderGuard} ORDER BY b.id,j.position,j.id LIMIT 1",[$status]);
                if(!$candidate)return null;
                if($role==='download'&&(string)($candidate['engine']??'')==='telegram-mtproto'&&(int)($candidate['total_bytes']??0)>0){
                    $storage=self::parallelStorageAdmission((int)$candidate['total_bytes']);
                    if(!($storage['ok']??false)){
                        $message='فضای موقت برای دانلود موازی کافی نیست؛ Job پس از آزادشدن فضا خودکار دوباره بررسی می‌شود.';
                        App::q("UPDATE media_jobs SET next_attempt_at=DATE_ADD(NOW(),INTERVAL 60 SECOND),error_code='DISK_SPACE_WAIT',error_message=?,updated_at=NOW() WHERE id=? AND status='queued'",[$message,$candidate['id']]);
                        self::event((int)$candidate['id'],'warning','storage',$message,$storage);
                        continue;
                    }
                }
                $token=bin2hex(random_bytes(32));$target=$role==='download'?'downloading':'uploading';$attemptColumn=$role==='download'?'download_attempts':'upload_attempts';
                $claimed=App::q("UPDATE media_jobs SET status=?,progress=IF(?='download',GREATEST(progress,1),GREATEST(progress,72)),attempts=attempts+1,{$attemptColumn}={$attemptColumn}+1,locked_by=?,lock_token=?,lock_expires_at=DATE_ADD(NOW(),INTERVAL {$lease} SECOND),heartbeat_at=NOW(),started_at=COALESCE(started_at,NOW()),next_attempt_at=NULL,error_code=NULL,error_message=NULL,updated_at=NOW() WHERE id=? AND status=? AND (lock_expires_at IS NULL OR lock_expires_at<NOW())",[$target,$role,$workerId,$token,$candidate['id'],$status])->rowCount();
                if($claimed!==1)continue;
                App::q("UPDATE media_batches b JOIN media_jobs j ON j.batch_id=b.id SET b.status='running',b.current_item_id=j.id,b.started_at=COALESCE(b.started_at,NOW()),b.updated_at=NOW() WHERE j.id=?",[$candidate['id']]);
                self::heartbeatWorker($workerId,'busy',(int)$candidate['id']);
                $row=App::one("SELECT j.*,b.product_id,b.channel_id,b.upload_mode,b.total_items,b.sequential_mode,b.pipeline_depth,b.status batch_status,b.title batch_title,p.title product_title FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id LEFT JOIN products p ON p.id=b.product_id WHERE j.id=?",[$candidate['id']]);
                if($row!==null)$row['effective_channel_id']=trim((string)($row['target_channel_id']??''))!==''?(string)$row['target_channel_id']:(string)$row['channel_id'];
                return $row;
            }
            return null;
        }finally{if($claimLock!=='')try{App::q('SELECT RELEASE_LOCK(?)',[$claimLock]);}catch(Throwable){}}
    }

    private static function processDownloadJob(array $job): void
    {
        $jobId=(int)$job['id'];self::event($jobId,'info','resolve','شناسایی لینک و موتور دانلود آغاز شد.',['url_host'=>$job['source_host'],'worker'=>$job['locked_by']]);
        self::assertLease($job);
        $resolved=self::resolveSource((string)$job['source_url']);
        $resolvedTitle=$resolved['engine']==='telegram-mtproto'&&trim((string)($job['detected_title']??''))!==''?(string)$job['detected_title']:(string)($resolved['title']??'');
        App::q('UPDATE media_jobs SET engine=?,detected_title=?,mime_type=?,updated_at=NOW() WHERE id=? AND lock_token=?',[$resolved['engine'],$resolvedTitle?:null,$resolved['mime']?:null,$jobId,$job['lock_token']]);
        self::event($jobId,'info','resolve','موتور دانلود انتخاب شد.',['engine'=>$resolved['engine'],'title'=>$resolvedTitle]);
        $file=$resolved['engine']==='telegram-mtproto'?self::downloadWithTelethon($job,$resolved):($resolved['engine']==='yt-dlp'?self::downloadWithYtDlp($job,$resolved):(self::aria2Path()!==null?self::downloadWithAria2($job,$resolved):self::downloadDirect($job,$resolved)));
        $probe=self::probeMedia($file['path']);if($probe!==[])self::event($jobId,'info','mediainfo','مشخصات فایل با MediaInfo بررسی شد.',$probe);
        self::assertLease($job);
        App::q("UPDATE media_jobs SET status='downloaded',file_path=?,file_name=?,mime_type=?,downloaded_bytes=?,total_bytes=?,progress=70,eta_seconds=NULL,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,heartbeat_at=NOW(),error_code=NULL,error_message=NULL,updated_at=NOW() WHERE id=? AND lock_token=?",[$file['path'],$file['name'],$file['mime'],$file['size'],$file['size'],$jobId,$job['lock_token']]);
        self::event($jobId,'success','downloaded','دانلود کامل شد و فایل وارد صف آپلود شد.',['file_size'=>$file['size']]);
    }

    private static function processUploadJob(array $job): void
    {
        $jobId=(int)$job['id'];$path=(string)($job['file_path']??'');self::assertLease($job);
        if(!self::isSafeExistingFile($path))throw new MediaQueueException('UPLOAD_FILE_MISSING','فایل آماده آپلود پیدا نشد.');
        $target=self::jobTargetChannel($job);
        self::event($jobId,'info','upload','آپلود بدون کپشن در کانال مقصد آغاز شد.',['channel_id'=>$target,'target_slot'=>(int)($job['target_slot']??1),'target_sequence'=>(int)($job['target_sequence']??$job['position']),'worker'=>$job['locked_by']]);
        $message=self::uploadToTelegram($job,$path);
        self::assertLease($job);$messageId=(int)($message['message_id']??0);$uploadedSize=(int)(filesize($path)?:0);
        $saved=App::q("UPDATE media_jobs SET status='completed',progress=100,telegram_message_id=?,eta_seconds=NULL,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,heartbeat_at=NOW(),finished_at=NOW(),error_code=NULL,error_message=NULL,updated_at=NOW() WHERE id=? AND lock_token=?",[$messageId,$jobId,$job['lock_token']])->rowCount();
        if($saved!==1)throw new MediaQueueException('LEASE_LOST','نتیجه آپلود ثبت نشد زیرا Lease جابه‌جا شده است.');
        self::purgeJobFiles($jobId,$path);
        if($messageId>0){try{App::trackChannelPost($message,'downloader');}catch(Throwable $e){App::logEvent('media_track_warning',$e->getMessage(),['job_id'=>$jobId,'message_id'=>$messageId]);}}
        self::event($jobId,'success','complete','دانلود، آپلود بدون کپشن و حذف فوری فایل با موفقیت کامل شد.',['message_id'=>$messageId,'channel_id'=>$target,'file_size'=>$uploadedSize,'purged'=>true]);
    }

    private static function normaliseWorkerId(string $workerId,string $role): string
    {
        $workerId=trim($workerId);if($workerId==='')$workerId=$role.'-'.(gethostname()?:'localhost').'-'.getmypid();
        return mb_substr(preg_replace('/[^a-zA-Z0-9_.:@-]+/','-', $workerId)?:($role.'-worker'),0,190);
    }

    private static function lockSeconds(): int{return max(60,min(900,(int)App::setting('media_lock_seconds','180')));}
    private static function scanLeaseSeconds(): int{return max(180,min(1800,(int)App::setting('media_scan_lease_seconds','600')));}

    private static function registerWorker(string $workerId,string $role): void
    {
        App::q("INSERT INTO media_workers(worker_id,role,hostname,pid,status,current_job_id,jobs_processed,last_error,started_at,heartbeat_at,updated_at) VALUES (?,?,?,?, 'starting',NULL,0,NULL,NOW(),NOW(),NOW()) ON DUPLICATE KEY UPDATE role=VALUES(role),hostname=VALUES(hostname),pid=VALUES(pid),status='starting',current_job_id=NULL,last_error=NULL,started_at=IF(heartbeat_at<DATE_SUB(NOW(),INTERVAL 10 MINUTE),NOW(),started_at),heartbeat_at=NOW(),updated_at=NOW()",[$workerId,$role,gethostname()?:'localhost',getmypid()]);
    }

    public static function heartbeatWorker(string $workerId,string $status='idle',?int $jobId=null,?string $error=null): void
    {
        $allowed=['starting','idle','busy','stopping','stopped','error'];if(!in_array($status,$allowed,true))$status='idle';
        App::q('UPDATE media_workers SET status=?,current_job_id=?,last_error=?,heartbeat_at=NOW(),updated_at=NOW() WHERE worker_id=?',[$status,$jobId,$error===null?null:self::cleanError($error),$workerId]);
        if($jobId!==null)App::q('UPDATE media_jobs SET heartbeat_at=NOW(),lock_expires_at=DATE_ADD(NOW(),INTERVAL '.self::lockSeconds().' SECOND) WHERE id=? AND locked_by=?',[$jobId,$workerId]);
    }

    private static function finishWorkerJob(string $workerId,bool $success,string $error=''): void
    {
        App::q("UPDATE media_workers SET status=?,current_job_id=NULL,jobs_processed=jobs_processed+1,last_error=?,heartbeat_at=NOW(),updated_at=NOW() WHERE worker_id=?",[$success?'idle':'error',$success?null:self::cleanError($error),$workerId]);
    }

    private static function assertLease(array $job): void
    {
        $fresh=App::one('SELECT status,lock_token,lock_expires_at FROM media_jobs WHERE id=?',[$job['id']]);
        if(!$fresh)throw new MediaQueueException('JOB_MISSING','Job از صف حذف شده است.');
        if((string)$fresh['status']==='cancelled')throw new MediaQueueException('JOB_CANCELLED','Job توسط مدیر لغو شد.');
        if(!hash_equals((string)($fresh['lock_token']??''),(string)($job['lock_token']??'')))throw new MediaQueueException('LEASE_LOST','قفل Job به Worker دیگری منتقل شده است.');
    }

    private static function updateTransferProgress(array $job,string $role,int $bytes,int $total,float $startedAt,float $baseProgress,float $weight): bool
    {
        $elapsed=max(.001,microtime(true)-$startedAt);$speed=(int)round($bytes/$elapsed);$eta=$total>0&&$speed>0?(int)ceil(max(0,$total-$bytes)/$speed):null;
        $ratio=$total>0?min(1,max(0,$bytes/$total)):0;$progress=min(99,max($baseProgress,$baseProgress+$ratio*$weight));
        $speedColumn=$role==='download'?'download_speed_bps':'upload_speed_bps';
        $updated=App::q("UPDATE media_jobs SET progress=?,downloaded_bytes=IF(?='download',?,downloaded_bytes),total_bytes=IF(?='download' AND ?>0,?,total_bytes),{$speedColumn}=?,eta_seconds=?,heartbeat_at=NOW(),lock_expires_at=DATE_ADD(NOW(),INTERVAL ".self::lockSeconds()." SECOND),updated_at=NOW() WHERE id=? AND lock_token=? AND status=?",[$progress,$role,$bytes,$role,$total,$total,$speed,$eta,$job['id'],$job['lock_token'],$role==='download'?'downloading':'uploading'])->rowCount();
        if(!empty($job['locked_by']))self::heartbeatWorker((string)$job['locked_by'],'busy',(int)$job['id']);
        return $updated===1;
    }

    public static function maintenance(): void
    {
        self::cleanupStorage();self::recoverStaleJobs();
        App::q("UPDATE media_batches SET pipeline_depth=4 WHERE source_type='telegram_channel' AND pipeline_depth<4 AND status IN ('queued','running','paused')");
        App::q("UPDATE media_batches SET status=CASE WHEN scan_status='scanning' THEN 'running' ELSE 'queued' END,completed_at=NULL WHERE source_type='telegram_channel' AND scan_status IN ('queued','scanning') AND status='completed'");
        App::q("UPDATE media_batches SET status='queued',scan_status='queued',scan_attempts=0,scan_next_attempt_at=NOW(),scan_error='صف 0/0 قدیمی با منطق تکراری اشتباه ساخته شده بود؛ اسکن از ابتدا بازیابی شد.',scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,source_last_message_id=0,source_scanned_items=0,source_skipped_items=0,completed_at=NULL,updated_at=NOW() WHERE source_type='telegram_channel' AND status='completed' AND total_items=0 AND ((scan_attempts=0 AND source_last_message_id=0) OR (source_video_count>0 AND source_skipped_items<source_video_count))");
        App::q("UPDATE media_batches SET scan_status='queued',scan_attempts=IF(scan_attempts>0,scan_attempts-1,0),scan_next_attempt_at=NOW(),scan_error='Worker اسکن قطع شد؛ ادامه خودکار از آخرین Checkpoint زمان‌بندی شد.',scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,status=IF(status='paused','paused','queued'),updated_at=NOW() WHERE source_type='telegram_channel' AND scan_status='scanning' AND scan_lock_expires_at<NOW() AND status<>'cancelled'");
        App::q("UPDATE media_batches SET scan_attempts=IF(scan_max_attempts>0,scan_max_attempts-1,0),scan_next_attempt_at=NOW(),scan_error='صف گیرکرده قدیمی بازیابی شد؛ ادامه خودکار از آخرین Checkpoint انجام می‌شود.',status='queued',updated_at=NOW() WHERE source_type='telegram_channel' AND scan_status='queued' AND status='queued' AND scan_attempts>=scan_max_attempts");
        App::q("UPDATE media_workers SET status='stopped',current_job_id=NULL,updated_at=NOW() WHERE status NOT IN ('stopped','stopping') AND heartbeat_at<DATE_SUB(NOW(),INTERVAL 5 MINUTE)");
    }

    public static function activeWorkers(): array
    {
        return App::all("SELECT *,(heartbeat_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND)) is_live FROM media_workers ORDER BY is_live DESC,role,worker_id");
    }

    public static function hasLiveWorkers(): bool
    {
        return (int)(App::one("SELECT COUNT(*) c FROM media_workers WHERE heartbeat_at>=DATE_SUB(NOW(),INTERVAL 90 SECOND) AND status IN ('starting','idle','busy')")['c']??0)>0;
    }

    public static function retryJob(int $jobId): void
    {
        $job=App::one('SELECT id,batch_id,status,file_path FROM media_jobs WHERE id=?',[$jobId]);if(!$job)throw new RuntimeException('Job پیدا نشد.');
        if(!in_array($job['status'],['failed','cancelled'],true))throw new RuntimeException('فقط Job ناموفق یا لغوشده قابل Retry است.');
        $hasFile=self::isSafeExistingFile((string)($job['file_path']??''));
        App::q("UPDATE media_jobs SET status=?,progress=?,attempts=0,download_attempts=IF(?,download_attempts,0),upload_attempts=0,next_attempt_at=NULL,error_code=NULL,error_message=NULL,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,finished_at=NULL,updated_at=NOW() WHERE id=?",[$hasFile?'downloaded':'queued',$hasFile?70:0,$hasFile?1:0,$jobId]);
        App::q("UPDATE media_batches SET status='queued',completed_at=NULL,updated_at=NOW() WHERE id=?",[$job['batch_id']]);self::event($jobId,'info','retry','Job به‌صورت دستی دوباره وارد صف شد.');
    }

    public static function cancelJob(int $jobId): void
    {
        $job=App::one('SELECT id,batch_id,file_path,status FROM media_jobs WHERE id=?',[$jobId]);if(!$job)throw new RuntimeException('Job پیدا نشد.');
        if(in_array($job['status'],self::FINAL_STATUSES,true))return;
        App::q("UPDATE media_jobs SET status='cancelled',error_code='ADMIN_CANCELLED',error_message='توسط مدیر لغو شد.',locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,eta_seconds=NULL,finished_at=NOW(),updated_at=NOW() WHERE id=?",[$jobId]);
        self::purgeJobFiles($jobId,(string)($job['file_path']??''));self::event($jobId,'warning','cancelled','Job توسط مدیر لغو شد.');self::syncBatch((int)$job['batch_id']);
    }

    public static function deleteJob(int $jobId): void
    {
        $job=App::one('SELECT id,batch_id,file_path,status FROM media_jobs WHERE id=?',[$jobId]);if(!$job)return;
        if(in_array($job['status'],['downloading','uploading'],true))throw new RuntimeException('ابتدا Job فعال را لغو کن و سپس حذف را بزن.');
        self::purgeJobFiles($jobId,(string)($job['file_path']??''));App::q('DELETE FROM media_jobs WHERE id=?',[$jobId]);self::syncBatch((int)$job['batch_id']);
    }

    private static function resolveSource(string $url): array
    {
        if(preg_match('#^tgmtproto://channel/(-?[0-9]+)/([1-9][0-9]*)$#D',$url,$match))return ['engine'=>'telegram-mtproto','url'=>$url,'chat_id'=>$match[1],'message_id'=>(int)$match[2],'title'=>'telegram-'.$match[2].'.mp4','mime'=>'video/mp4'];
        self::validateUrl($url);
        $path=(string)(parse_url($url,PHP_URL_PATH)??'');$ext=strtolower(pathinfo($path,PATHINFO_EXTENSION));
        if(in_array($ext,self::VIDEO_EXTENSIONS,true)&&$ext!=='m3u8')return ['engine'=>'direct','url'=>$url,'title'=>self::titleFromUrl($url),'mime'=>self::mimeFromExtension($ext)];
        try{
            $head=self::head($url);
            $contentType=strtolower(trim(explode(';',(string)($head['headers']['content-type']??''))[0]));
            if(str_contains($contentType,'mpegurl')||$ext==='m3u8'){
                if(self::ytDlpPath()!==null)return ['engine'=>'yt-dlp','url'=>$url,'title'=>self::titleFromUrl($url),'mime'=>'video/mp4'];
                throw new MediaQueueException('ENGINE_MISSING','این لینک پخش تکه‌ای است و برای دانلود آن باید yt-dlp روی سرور نصب باشد.');
            }
            if(str_starts_with($contentType,'video/')||str_starts_with($contentType,'application/octet-stream'))return ['engine'=>'direct','url'=>$head['url'],'title'=>self::fileNameFromHeaders($head['headers'],$head['url']),'mime'=>$contentType];
            if(str_contains($contentType,'text/html')){
                if(self::ytDlpPath()!==null)return ['engine'=>'yt-dlp','url'=>$url,'title'=>self::titleFromUrl($url),'mime'=>'video/mp4'];
                $page=self::fetchText($head['url'],2*1024*1024);
                $candidate=self::extractVideoFromHtml($page['body'],$page['url']);
                if($candidate!==null)return ['engine'=>'direct','url'=>$candidate['url'],'title'=>$candidate['title'],'mime'=>$candidate['mime']];
                throw new MediaQueueException('ENGINE_MISSING','ویدیو داخل صفحه شناسایی شدنی نیست. برای این سایت باید yt-dlp روی سرور نصب شود.');
            }
        }catch(MediaQueueException $e){if($e->errorCode==='ENGINE_MISSING')throw $e;if(self::ytDlpPath()===null)throw $e;}
        catch(Throwable $e){if(self::ytDlpPath()===null)throw new MediaQueueException('RESOLVE_FAILED','تشخیص لینک ناموفق بود: '.$e->getMessage());}
        if(self::ytDlpPath()!==null)return ['engine'=>'yt-dlp','url'=>$url,'title'=>self::titleFromUrl($url),'mime'=>'video/mp4'];
        throw new MediaQueueException('UNSUPPORTED_SOURCE','این لینک مستقیم نیست و موتور yt-dlp روی سرور در دسترس نیست.');
    }

    private static function downloadWithTelethon(array $job,array $resolved): array
    {
        $scanner=self::historyScannerStatus();
        if(!$scanner['ready'])throw new MediaQueueException('MTPROTO_NOT_READY','حساب تلگرام متصل نیست؛ آن را از بخش تنظیم اسکنر کانال دوباره متصل کنید.');
        $expected=max(0,(int)($job['total_bytes']??0));
        if($expected>self::maxBytes())throw new MediaQueueException('SIZE_LIMIT','حجم ویدیو از سقف '.self::humanBytes(self::maxBytes()).' بیشتر است.');
        self::assertStorageCapacity($expected);
        $process=null;$pipes=[];$closed=false;$resultFile='';
        try{
            $resultFile=self::newScannerResultFile();
            $runtime=self::scannerRuntime();
            if(!is_executable($runtime['python'])||!is_file($runtime['script']))throw new MediaQueueException('MTPROTO_NOT_READY','موتور Telethon نصب نیست؛ update.sh را اجرا کنید.');
            if(!self::functionEnabled('proc_open'))throw new MediaQueueException('PROC_OPEN_DISABLED','تابع proc_open در PHP غیرفعال است.');
            $jobId=(int)$job['id'];$dir=self::jobDirectory($jobId);
            foreach(glob($dir.'/*')?:[] as $old)self::deleteSafeFile($old);
            $name=self::sanitizeFileName((string)($job['file_name']??''));
            if($name===''||$name==='video')$name='telegram-'.(int)$resolved['message_id'].'.mp4';
            if(pathinfo($name,PATHINFO_EXTENSION)==='')$name.='.mp4';
            $target=$dir.'/'.$name;
            $command=[$runtime['python'],$runtime['script'],'--download-message','--channel',(string)$resolved['chat_id'],'--message-id',(string)$resolved['message_id'],'--output',$target,'--session',$scanner['session_base'],'--parallel-session','--config',$runtime['config'],'--result-file',$resultFile];
            $input=self::webScannerCredentials()??[];if($input!==[])$command[]='--json-input';
            $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
            if(!is_resource($process))throw new MediaQueueException('MTPROTO_START','اجرای دانلود Telethon ممکن نشد.');
            if($input!==[])fwrite($pipes[0],App::j($input));fclose($pipes[0]);unset($pipes[0]);
            stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
            App::q("UPDATE media_jobs SET status='downloading',progress=5,download_speed_bps=0,eta_seconds=NULL,updated_at=NOW() WHERE id=? AND lock_token=?",[$jobId,$job['lock_token']]);
            self::event($jobId,'info','download','دانلود مستقیم پیام کانال با نشست امن Telethon آغاز شد.',['source_chat_id'=>$resolved['chat_id'],'source_message_id'=>$resolved['message_id']]);
            $buffer='';$stderr='';$payload=null;$startedAt=microtime(true);$exitCode=null;$timeout=self::downloadTimeout();
            while(true){
                $chunk=stream_get_contents($pipes[1])?:'';$buffer.=$chunk;$stderr.=stream_get_contents($pipes[2])?:'';
                while(($newline=strpos($buffer,"\n"))!==false){
                    $line=trim(substr($buffer,0,$newline));$buffer=substr($buffer,$newline+1);if($line==='')continue;$decoded=json_decode($line,true,512,JSON_BIGINT_AS_STRING|JSON_INVALID_UTF8_SUBSTITUTE);if(!is_array($decoded))continue;
                    if(($decoded['type']??'')==='progress'){
                        $downloaded=max(0,(int)($decoded['downloaded']??0));$total=max($expected,(int)($decoded['total']??0));
                        if($downloaded>self::maxBytes()){proc_terminate($process,15);throw new MediaQueueException('SIZE_LIMIT','حجم ویدیو از سقف مجاز بیشتر است.');}
                        if(!self::updateTransferProgress($job,'download',$downloaded,$total,$startedAt,5,65)){proc_terminate($process,15);throw new MediaQueueException('JOB_CANCELLED','Job توسط مدیر لغو شد.');}
                    }else{$payload=$decoded;}
                }
                if(strlen($buffer)>1048576||strlen($stderr)>1048576){proc_terminate($process,9);throw new MediaQueueException('MTPROTO_OUTPUT','خروجی موتور Telethon بیش از حد مجاز بود.');}
                $status=proc_get_status($process);if(!$status['running']){$exitCode=(int)$status['exitcode'];break;}
                if(microtime(true)-$startedAt>$timeout){proc_terminate($process,15);usleep(300000);proc_terminate($process,9);$exitCode=124;break;}
                usleep(100000);
            }
            $buffer.=self::drainFinishedPipe($pipes[1],2097152-strlen($buffer));$stderr.=self::drainFinishedPipe($pipes[2],1048576-strlen($stderr));
            foreach(array_filter(array_map('trim',preg_split('/\R/',$buffer)?:[])) as $line){$decoded=json_decode($line,true,512,JSON_BIGINT_AS_STRING|JSON_INVALID_UTF8_SUBSTITUTE);if(is_array($decoded))$payload=$decoded;}
            if(is_file($resultFile)&&($stored=fopen($resultFile,'rb'))!==false){while(($line=fgets($stored))!==false){$decoded=json_decode(trim($line),true,512,JSON_BIGINT_AS_STRING|JSON_INVALID_UTF8_SUBSTITUTE);if(is_array($decoded)&&($decoded['type']??'')!=='progress')$payload=$decoded;}fclose($stored);}
            fclose($pipes[1]);fclose($pipes[2]);$pipes=[];$closed=true;$closeCode=proc_close($process);$process=null;if($exitCode===null||$exitCode<0)$exitCode=$closeCode;
            if(($exitCode!==null&&$exitCode>0)||!is_array($payload)||!($payload['ok']??false)){
                $detail=is_array($payload)?(string)($payload['error']??''):'';if($detail==='')$detail=trim($stderr)?:'دانلود Telethon پاسخ معتبر نداد.';
                $code=str_contains($detail,'پیدا نشد')?'TELEGRAM_SOURCE_MISSING':'MTPROTO_DOWNLOAD';throw new MediaQueueException($code,self::cleanError($detail));
            }
            if(!self::isSafeExistingFile($target))throw new MediaQueueException('MTPROTO_NO_FILE','Telethon فایل نهایی را ایجاد نکرد.');
            $size=(int)(filesize($target)?:0);if($size<=0||$size>self::maxBytes()){self::deleteSafeFile($target);throw new MediaQueueException('SIZE_LIMIT','حجم فایل خروجی خارج از سقف مجاز است.');}
            return ['path'=>$target,'name'=>basename($target),'mime'=>self::detectMime($target,(string)($payload['mime_type']??'video/mp4')),'size'=>$size];
        }finally{
            if($resultFile!=='')@unlink($resultFile);
            foreach($pipes as $pipe)if(is_resource($pipe))@fclose($pipe);
            if(is_resource($process)){@proc_terminate($process,9);if(!$closed)@proc_close($process);}
        }
    }

    private static function downloadDirect(array $job,array $resolved): array
    {
        $jobId=(int)$job['id'];$max=self::maxBytes();$dir=self::jobDirectory($jobId);
        $name=self::sanitizeFileName((string)($resolved['title']?:'video_'.$jobId));
        if(pathinfo($name,PATHINFO_EXTENSION)==='')$name.='.mp4';
        $target=$dir.'/'.$name;$part=$target.'.part';$url=(string)$resolved['url'];
        App::q("UPDATE media_jobs SET status='downloading',progress=5,download_speed_bps=0,eta_seconds=NULL,updated_at=NOW() WHERE id=? AND lock_token=?",[$jobId,$job['lock_token']]);
        self::event($jobId,'info','download','دانلود مستقیم فایل آغاز شد.');
        for($redirect=0;$redirect<6;$redirect++){
            $pin=self::validatedPin($url);$headers=[];$downloaded=0;$lastUpdate=0.0;$lastProgress=-1;$startedAt=microtime(true);
            $fp=fopen($part,'wb');if($fp===false)throw new MediaQueueException('STORAGE_WRITE','ساخت فایل موقت ممکن نیست.');
            $ch=curl_init($url);
            $options=[CURLOPT_FILE=>$fp,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>self::downloadTimeout(),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_USERAGENT=>self::userAgent(),CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$headers):int{$len=strlen($line);$p=strpos($line,':');if($p!==false)$headers[strtolower(trim(substr($line,0,$p)))]=trim(substr($line,$p+1));return $len;},CURLOPT_NOPROGRESS=>false,CURLOPT_XFERINFOFUNCTION=>static function($ch,float $total,float $now)use($job,$max,&$downloaded,&$lastUpdate,&$lastProgress,$startedAt):int{$downloaded=(int)$now;if($now>$max)return 1;$percent=$total>0?(int)min(69,max(5,5+($now/$total)*64)):5;$time=microtime(true);if($percent>=$lastProgress+2||$time-$lastUpdate>2){$lastProgress=$percent;$lastUpdate=$time;try{if(!self::updateTransferProgress($job,'download',(int)$now,(int)$total,$startedAt,5,65))return 1;}catch(Throwable){return 1;}}return 0;}];
            if($pin)$options[CURLOPT_RESOLVE]=[$pin];
            if(defined('CURLOPT_PROTOCOLS'))$options[CURLOPT_PROTOCOLS]=CURLPROTO_HTTP|CURLPROTO_HTTPS;
            curl_setopt_array($ch,$options);$ok=curl_exec($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$effective=(string)curl_getinfo($ch,CURLINFO_EFFECTIVE_URL);curl_close($ch);fclose($fp);
            if(in_array($status,[301,302,303,307,308],true)&&isset($headers['location'])){self::deleteSafeFile($part);$url=self::absoluteUrl($headers['location'],$effective?:$url);self::validateUrl($url);continue;}
            if($downloaded>$max){self::deleteSafeFile($part);throw new MediaQueueException('SIZE_LIMIT','حجم فایل از سقف '.self::humanBytes($max).' بیشتر است.');}
            if($ok===false||$status<200||$status>=300){self::deleteSafeFile($part);throw new MediaQueueException('DOWNLOAD_HTTP',"دانلود ناموفق بود (HTTP {$status}): ".self::cleanError($err));}
            $size=(int)(filesize($part)?:0);if($size<=0){self::deleteSafeFile($part);throw new MediaQueueException('EMPTY_FILE','فایل دانلودشده خالی است.');}
            if($size>$max){self::deleteSafeFile($part);throw new MediaQueueException('SIZE_LIMIT','حجم فایل از سقف مجاز بیشتر است.');}
            if(!@rename($part,$target)){self::deleteSafeFile($part);throw new MediaQueueException('STORAGE_MOVE','ثبت فایل دانلودشده ناموفق بود.');}
            $mime=self::detectMime($target,(string)($resolved['mime']??''));
            return ['path'=>$target,'name'=>basename($target),'mime'=>$mime,'size'=>$size];
        }
        throw new MediaQueueException('TOO_MANY_REDIRECTS','تعداد انتقال‌های لینک بیش از حد مجاز است.');
    }

    private static function downloadWithAria2(array $job,array $resolved): array
    {
        $aria=self::aria2Path();if($aria===null)return self::downloadDirect($job,$resolved);
        if(!self::functionEnabled('proc_open'))throw new MediaQueueException('PROC_OPEN_DISABLED','تابع proc_open در PHP غیرفعال است.');
        $jobId=(int)$job['id'];$dir=self::jobDirectory($jobId);$name=self::sanitizeFileName((string)($resolved['title']?:'video_'.$jobId));if(pathinfo($name,PATHINFO_EXTENSION)==='')$name.='.mp4';
        $connections=max(1,min(16,(int)App::setting('media_download_connections','8')));$target=$dir.'/'.$name;
        foreach(glob($dir.'/*')?:[] as $old)self::deleteSafeFile($old);
        $cmd=[$aria,'--allow-overwrite=true','--auto-file-renaming=false','--file-allocation=none','--max-tries=3','--retry-wait=3','--connect-timeout=20','--timeout=30','--summary-interval=1','--console-log-level=notice','--max-file-not-found=3','--max-connection-per-server',(string)$connections,'--split',(string)$connections,'--min-split-size=1M','--dir',$dir,'--out',$name,(string)$resolved['url']];
        $spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];$pipes=[];$process=proc_open($cmd,$spec,$pipes,$dir);if(!is_resource($process))throw new MediaQueueException('ARIA2_START','اجرای aria2c ممکن نشد.');
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);$startedAt=microtime(true);$lastBeat=0.0;$output='';$exit=-1;
        App::q("UPDATE media_jobs SET engine='aria2c',status='downloading',progress=5,download_speed_bps=0,eta_seconds=NULL,updated_at=NOW() WHERE id=? AND lock_token=?",[$jobId,$job['lock_token']]);self::event($jobId,'info','download','دانلود چنداتصاله با aria2c آغاز شد.',['connections'=>$connections]);
        try{
            while(true){
                foreach([1,2] as $index){$chunk=stream_get_contents($pipes[$index]);if($chunk!==false&&$chunk!=='')$output=substr($output.$chunk,-30000);}
                $now=microtime(true);if($now-$lastBeat>=2){$size=is_file($target)?(int)(filesize($target)?:0):0;$total=self::parseAriaTotal($output);if($size>self::maxBytes()){proc_terminate($process,9);throw new MediaQueueException('SIZE_LIMIT','حجم فایل از سقف مجاز بیشتر است.');}if(!self::updateTransferProgress($job,'download',$size,$total,$startedAt,5,65)){proc_terminate($process,15);throw new MediaQueueException('JOB_CANCELLED','Job لغو شد یا Lease آن از دست رفت.');}$lastBeat=$now;}
                $status=proc_get_status($process);if(!$status['running']){$exit=(int)$status['exitcode'];break;}if($now-$startedAt>self::downloadTimeout()){proc_terminate($process,9);throw new MediaQueueException('ARIA2_TIMEOUT','مهلت دانلود aria2c به پایان رسید.');}usleep(200000);
            }
        }finally{foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);$close=proc_close($process);if($exit<0)$exit=$close;}
        if($exit!==0||!self::isSafeExistingFile($target)){self::deleteSafeFile($target);self::deleteSafeFile($target.'.aria2');throw new MediaQueueException('ARIA2_FAILED','aria2c: '.self::cleanError(self::lastLines($output,5)));}
        $size=(int)(filesize($target)?:0);if($size<=0||$size>self::maxBytes()){self::deleteSafeFile($target);throw new MediaQueueException('SIZE_LIMIT','حجم فایل خروجی خارج از سقف مجاز است.');}
        return ['path'=>$target,'name'=>basename($target),'mime'=>self::detectMime($target,(string)($resolved['mime']??'')),'size'=>$size];
    }

    private static function downloadWithYtDlp(array $job,array $resolved): array
    {
        $path=self::ytDlpPath();if($path===null)throw new MediaQueueException('ENGINE_MISSING','yt-dlp روی سرور در دسترس نیست.');
        if(!self::functionEnabled('proc_open'))throw new MediaQueueException('PROC_OPEN_DISABLED','تابع proc_open در PHP غیرفعال است.');
        $jobId=(int)$job['id'];$dir=self::jobDirectory($jobId);$maxMb=max(1,(int)floor(self::maxBytes()/1048576));
        foreach(glob($dir.'/*')?:[] as $old)self::deleteSafeFile($old);
        $template=$dir.'/media_%(id)s.%(ext)s';$fragments=max(1,min(16,(int)App::setting('media_fragment_concurrency','8')));
        $cmd=[$path,'--ignore-config','--no-playlist','--no-simulate','--progress','--newline','--restrict-filenames','--no-warnings','--socket-timeout','25','--retries','3','--fragment-retries','5','--concurrent-fragments',(string)$fragments,'--max-filesize',$maxMb.'M','--format','best[ext=mp4]/best','--merge-output-format','mp4','--output',$template,'--print','before_dl:MEDIA_TITLE:%(title)s','--print','after_move:FINAL_FILE:%(filepath)s','--progress-template','download:PROGRESS:%(progress.downloaded_bytes)s|%(progress.total_bytes_estimate)s|%(progress._percent_str)s|%(progress._speed_str)s|%(progress._eta_str)s'];
        if(($ffmpeg=self::ffmpegPath())!==null){$cmd[]='--ffmpeg-location';$cmd[]=dirname($ffmpeg);}
        if(($aria=self::aria2Path())!==null){$connections=max(1,min(16,(int)App::setting('media_download_connections','8')));$cmd[]='--downloader';$cmd[]='http,https:'.$aria;$cmd[]='--downloader-args';$cmd[]='aria2c:-x'.$connections.' -s'.$connections.' -k1M --file-allocation=none';}
        $cmd[]=(string)$resolved['url'];
        $spec=[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']];$pipes=[];$process=proc_open($cmd,$spec,$pipes,$dir);
        if(!is_resource($process))throw new MediaQueueException('YTDLP_START','اجرای yt-dlp ممکن نشد.');
        fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
        App::q("UPDATE media_jobs SET status='downloading',progress=5,engine='yt-dlp',download_speed_bps=0,eta_seconds=NULL,updated_at=NOW() WHERE id=? AND lock_token=?",[$jobId,$job['lock_token']]);
        self::event($jobId,'info','download','دانلود با موتور yt-dlp آغاز شد.',['fragments'=>$fragments,'aria2'=>$aria!==null,'ffmpeg'=>$ffmpeg!==null]);
        $startedAt=microtime(true);$buffer='';$errors='';$final='';$title='';
        try{
            while(true){
                $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);if($out!==false)$buffer.=$out;if($err!==false)$errors=substr($errors.$err,-20000);
                while(($pos=strpos($buffer,"\n"))!==false){$line=trim(substr($buffer,0,$pos));$buffer=substr($buffer,$pos+1);if(str_starts_with($line,'PROGRESS:')){$parts=explode('|',substr($line,9));$bytes=is_numeric($parts[0]??null)?(int)$parts[0]:0;$total=is_numeric($parts[1]??null)?(int)$parts[1]:0;if(!self::updateTransferProgress($job,'download',$bytes,$total,$startedAt,5,65)){proc_terminate($process,15);throw new MediaQueueException('JOB_CANCELLED','Job لغو شد یا Lease آن از دست رفت.');}}elseif(str_starts_with($line,'FINAL_FILE:'))$final=trim(substr($line,11));elseif(str_starts_with($line,'MEDIA_TITLE:')){$title=trim(substr($line,12));App::q('UPDATE media_jobs SET detected_title=? WHERE id=? AND lock_token=?',[mb_substr($title,0,500),$jobId,$job['lock_token']]);}}
                $status=proc_get_status($process);if(!$status['running']){$exit=(int)$status['exitcode'];break;}
                if(microtime(true)-$startedAt>self::downloadTimeout()){proc_terminate($process,9);throw new MediaQueueException('YTDLP_TIMEOUT','مهلت دانلود yt-dlp به پایان رسید.');}
                usleep(200000);
            }
        }finally{foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);$close=proc_close($process);if(!isset($exit)||$exit<0)$exit=$close;}
        $completedFiles=array_values(array_filter(glob($dir.'/*')?:[],static fn(string $f):bool=>is_file($f)&&!str_ends_with($f,'.part')));
        if($exit<0&&$completedFiles)$exit=0;
        if($exit!==0){foreach(glob($dir.'/*')?:[] as $old)self::deleteSafeFile($old);throw new MediaQueueException('YTDLP_FAILED','yt-dlp: '.self::cleanError(self::lastLines($errors,4)));}
        if($final===''||!self::isSafeExistingFile($final))$final=$completedFiles[0]??'';
        if(!self::isSafeExistingFile($final))throw new MediaQueueException('YTDLP_NO_FILE','yt-dlp فایل نهایی ایجاد نکرد.');
        $size=(int)(filesize($final)?:0);if($size<=0||$size>self::maxBytes()){self::deleteSafeFile($final);throw new MediaQueueException('SIZE_LIMIT','حجم فایل خروجی خارج از سقف مجاز است.');}
        return ['path'=>$final,'name'=>basename($final),'mime'=>self::detectMime($final,'video/mp4'),'size'=>$size];
    }

    private static function uploadToTelegram(array $job,string $path): array
    {
        if(!self::isSafeExistingFile($path))throw new MediaQueueException('UPLOAD_FILE_MISSING','فایل آماده آپلود پیدا نشد.');
        $mime=self::detectMime($path,(string)($job['mime_type']??''));$mode=(string)$job['upload_mode'];$target=self::jobTargetChannel($job);
        $telegramVideo=in_array(strtolower($mime),['video/mp4','video/x-m4v'],true);
        $method=$mode==='document'?'sendDocument':(($mode==='video'||($mode==='auto'&&$telegramVideo))?'sendVideo':'sendDocument');
        if($method==='sendVideo'&&!$telegramVideo){self::event((int)$job['id'],'warning','format','فرمت فایل برای Video استاندارد تلگرام مناسب نیست؛ فایل به‌صورت Document ارسال می‌شود.',['mime'=>$mime]);$method='sendDocument';}
        $size=(int)(filesize($path)?:0);
        if(((string)($job['engine']??'')==='telegram-mtproto'||$size>50*1024*1024)&&(string)App::setting('telegram_mtproto_upload','1')==='1'&&self::historyScannerStatus()['ready'])return self::uploadWithTelethon($job,$path,$mime,$method==='sendDocument');
        if($size>50*1024*1024)throw new MediaQueueException('MTPROTO_UPLOAD_REQUIRED','برای آپلود فایل بزرگ‌تر از ۵۰ مگابایت، حساب Telethon باید متصل و «آپلود MTProto» فعال باشد.');
        try{return self::telegramFileRequest($method,$target,$path,$mime,$job);}
        catch(MediaQueueException $e){if($method==='sendVideo'&&in_array($e->errorCode,['TELEGRAM_API','TELEGRAM_FORMAT'],true)){self::event((int)$job['id'],'warning','upload','ارسال به‌صورت ویدیو پذیرفته نشد؛ تلاش به‌صورت فایل و بدون کپشن انجام می‌شود.');return self::telegramFileRequest('sendDocument',$target,$path,$mime,$job);}throw $e;}
    }

    private static function uploadWithTelethon(array $job,string $path,string $mime,bool $forceDocument): array
    {
        $scanner=self::historyScannerStatus();$runtime=self::scannerRuntime();$target=self::jobTargetChannel($job);$jobId=(int)$job['id'];
        if(!$scanner['ready']||!is_executable($runtime['python'])||!is_file($runtime['script']))throw new MediaQueueException('MTPROTO_NOT_READY','موتور آپلود MTProto آماده نیست؛ اتصال حساب تلگرام و update.sh را بررسی کنید.');
        if(!self::functionEnabled('proc_open'))throw new MediaQueueException('PROC_OPEN_DISABLED','تابع proc_open در PHP غیرفعال است.');
        $size=(int)(filesize($path)?:0);if($size<=0||$size>self::maxBytes())throw new MediaQueueException('SIZE_LIMIT','حجم فایل خارج از سقف ۱ گیگابایت است.');
        $process=null;$pipes=[];$resultFile='';$closed=false;
        try{
            $resultFile=self::newScannerResultFile();
            $command=[$runtime['python'],$runtime['script'],'--upload-file',$path,'--destination',$target,'--session',$scanner['session_base'],'--parallel-session','--config',$runtime['config'],'--result-file',$resultFile];
            if($forceDocument)$command[]='--force-document';
            $input=self::webScannerCredentials()??[];if($input!==[])$command[]='--json-input';
            $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,null,['bypass_shell'=>true]);
            if(!is_resource($process))throw new MediaQueueException('MTPROTO_UPLOAD_START','اجرای آپلود MTProto ممکن نشد.');
            if($input!==[])fwrite($pipes[0],App::j($input));fclose($pipes[0]);unset($pipes[0]);
            stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
            self::event($jobId,'info','upload','آپلود پرسرعت MTProto بدون کپشن آغاز شد.',['channel_id'=>$target,'file_size'=>$size,'part_size_kb'=>512]);
            $buffer='';$stderr='';$payload=null;$startedAt=microtime(true);$exitCode=null;$timeout=self::uploadTimeout();
            while(true){
                $buffer.=stream_get_contents($pipes[1])?:'';$stderr.=stream_get_contents($pipes[2])?:'';
                while(($newline=strpos($buffer,"\n"))!==false){
                    $line=trim(substr($buffer,0,$newline));$buffer=substr($buffer,$newline+1);if($line==='')continue;$decoded=json_decode($line,true,512,JSON_BIGINT_AS_STRING|JSON_INVALID_UTF8_SUBSTITUTE);if(!is_array($decoded))continue;
                    if(($decoded['type']??'')==='upload_progress'){$uploaded=max(0,(int)($decoded['uploaded']??0));$total=max($size,(int)($decoded['total']??0));if(!self::updateTransferProgress($job,'upload',$uploaded,$total,$startedAt,72,27)){proc_terminate($process,15);throw new MediaQueueException('JOB_CANCELLED','Job توسط مدیر لغو شد.');}}
                    else $payload=$decoded;
                }
                if(strlen($buffer)>1048576||strlen($stderr)>1048576){proc_terminate($process,9);throw new MediaQueueException('MTPROTO_OUTPUT','خروجی موتور MTProto بیش از حد مجاز بود.');}
                $status=proc_get_status($process);if(!$status['running']){$exitCode=(int)$status['exitcode'];break;}
                if(microtime(true)-$startedAt>$timeout){proc_terminate($process,15);usleep(300000);proc_terminate($process,9);$exitCode=124;break;}
                usleep(100000);
            }
            $buffer.=self::drainFinishedPipe($pipes[1],2097152-strlen($buffer));$stderr.=self::drainFinishedPipe($pipes[2],1048576-strlen($stderr));
            foreach(array_filter(array_map('trim',preg_split('/\R/',$buffer)?:[])) as $line){$decoded=json_decode($line,true,512,JSON_BIGINT_AS_STRING|JSON_INVALID_UTF8_SUBSTITUTE);if(is_array($decoded)&&($decoded['type']??'')!=='upload_progress')$payload=$decoded;}
            if(is_file($resultFile)&&($stored=fopen($resultFile,'rb'))!==false){while(($line=fgets($stored))!==false){$decoded=json_decode(trim($line),true,512,JSON_BIGINT_AS_STRING|JSON_INVALID_UTF8_SUBSTITUTE);if(is_array($decoded)&&($decoded['type']??'')!=='upload_progress')$payload=$decoded;}fclose($stored);}
            fclose($pipes[1]);fclose($pipes[2]);$pipes=[];$closed=true;$closeCode=proc_close($process);$process=null;if($exitCode===null||$exitCode<0)$exitCode=$closeCode;
            if(($exitCode!==null&&$exitCode>0)||!is_array($payload)||!($payload['ok']??false)){$detail=is_array($payload)?(string)($payload['error']??''):'';if($detail==='')$detail=trim($stderr)?:'آپلود MTProto پاسخ معتبر نداد.';throw new MediaQueueException('MTPROTO_UPLOAD',self::cleanError($detail));}
            $messageId=(int)($payload['message_id']??0);if($messageId<=0)throw new MediaQueueException('MTPROTO_UPLOAD_RESULT','شناسه پیام مقصد دریافت نشد.');
            $media=$forceDocument?'document':'video';
            return ['message_id'=>$messageId,'date'=>time(),'chat'=>['id'=>$target],$media=>['file_id'=>null,'file_size'=>$size,'mime_type'=>$mime]];
        }finally{
            if($resultFile!=='')@unlink($resultFile);foreach($pipes as $pipe)if(is_resource($pipe))@fclose($pipe);if(is_resource($process)){@proc_terminate($process,9);if(!$closed)@proc_close($process);}
        }
    }

    private static function jobTargetChannel(array $job): string
    {
        $target=trim((string)($job['effective_channel_id']??$job['target_channel_id']??''));
        return $target!==''?$target:(string)$job['channel_id'];
    }

    private static function telegramFileRequest(string $method,string $chatId,string $path,string $mime,array $job): array
    {
        $jobId=(int)$job['id'];$field=$method==='sendVideo'?'video':'document';$data=['chat_id'=>$chatId,$field=>new CURLFile($path,$mime,basename($path))];
        if($method==='sendVideo'){
            $meta=self::telegramVideoMetadata($path);
            if($meta===[])throw new MediaQueueException('VIDEO_METADATA','ابعاد و مدت ویدیو با ffprobe قابل تشخیص نیست؛ برای جلوگیری از Preview خراب ارسال متوقف شد.');
            $thumb=self::telegramVideoThumbnail($path,$meta);
            if($thumb==='')throw new MediaQueueException('VIDEO_THUMBNAIL','ساخت Thumbnail و Cover ویدیو ممکن نشد؛ ارسال بدون پیش‌نمایش متوقف شد.');
            $data['supports_streaming']='true';$data['width']=(string)$meta['width'];$data['height']=(string)$meta['height'];$data['duration']=(string)$meta['duration'];
            $data['thumbnail']='attach://video_thumb';$data['video_thumb']=new CURLFile($thumb,'image/jpeg','thumbnail.jpg');
            $data['cover']='attach://video_cover';$data['video_cover']=new CURLFile($thumb,'image/jpeg','cover.jpg');
            self::event($jobId,'info','video_metadata','متادیتا، Thumbnail و Cover واقعی ویدیو برای Telegram ثبت شد.',$meta+['thumbnail_size'=>(int)(filesize($thumb)?:0)]);
        }
        $ch=curl_init('https://api.telegram.org/bot'.App::token().'/'.$method);$last=0.0;$lastProgress=-1;$startedAt=microtime(true);
        curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>20,CURLOPT_TIMEOUT=>self::uploadTimeout(),CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_HTTPHEADER=>['Accept: application/json'],CURLOPT_NOPROGRESS=>false,CURLOPT_XFERINFOFUNCTION=>static function($ch,float $dt,float $dn,float $total,float $now)use($job,&$last,&$lastProgress,$startedAt):int{$percent=$total>0?(int)min(99,max(72,72+($now/$total)*27)):72;$time=microtime(true);if($percent>=$lastProgress+2||$time-$last>2){$last=$time;$lastProgress=$percent;try{if(!self::updateTransferProgress($job,'upload',(int)$now,(int)$total,$startedAt,72,27))return 1;}catch(Throwable){return 1;}}return 0;}]);
        $body=curl_exec($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
        if($body===false)throw new MediaQueueException('TELEGRAM_CONNECTION','اتصال آپلود تلگرام قطع شد: '.self::cleanError($err));
        $json=json_decode($body,true);
        if(!is_array($json)||!($json['ok']??false)){
            $description=(string)($json['description']??"HTTP {$status}");
            if($status===429){$retryAfter=max(1,(int)($json['parameters']['retry_after']??30));throw new MediaQueueException('TELEGRAM_RATE_LIMIT','محدودیت موقت تلگرام: '.$description,$retryAfter);}
            if(stripos($description,'wrong file')!==false||stripos($description,'failed to get HTTP URL content')!==false)throw new MediaQueueException('TELEGRAM_FORMAT',$description);
            throw new MediaQueueException('TELEGRAM_API',"Telegram API {$status}: {$description}");
        }
        return (array)($json['result']??[]);
    }

    private static function failJob(array $job,Throwable $e,string $stage): bool
    {
        $jobId=(int)$job['id'];$fresh=App::one('SELECT status,download_attempts,upload_attempts,max_attempts,file_path FROM media_jobs WHERE id=?',[$jobId]);if(!$fresh)return false;
        $code=$e instanceof MediaQueueException?$e->errorCode:'UNEXPECTED';$message=self::cleanError($e->getMessage());
        if($fresh['status']==='cancelled'||$code==='JOB_CANCELLED'){self::event($jobId,'warning','cancelled','پردازش Worker پس از لغو Job متوقف شد.');return false;}
        if($stage==='upload'&&$code==='UPLOAD_FILE_MISSING')$stage='download';
        $attempts=(int)($stage==='download'?$fresh['download_attempts']:$fresh['upload_attempts']);$max=(int)($fresh['max_attempts']??3);
        $batchStatus=(string)(App::one('SELECT status FROM media_batches WHERE id=?',[$job['batch_id']])['status']??'');
        if($batchStatus==='cancelled'){
            App::q("UPDATE media_jobs SET status='cancelled',locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,error_code='BATCH_CANCELLED',error_message='دسته توسط مدیر لغو شد.',finished_at=NOW(),updated_at=NOW() WHERE id=?",[$jobId]);
            self::event($jobId,'warning','cancelled','پردازش به‌دلیل لغو دسته متوقف شد.');return false;
        }
        $permanent=in_array($code,['INVALID_URL','PRIVATE_URL','UNSUPPORTED_SCHEME','SIZE_LIMIT','ENGINE_MISSING','UNSUPPORTED_SOURCE','PROC_OPEN_DISABLED','TELEGRAM_FORMAT','TELEGRAM_SOURCE_MISSING','MTPROTO_UPLOAD_REQUIRED'],true);
        $retry=!$permanent&&$attempts<$max;
        $retryAfter=$e instanceof MediaQueueException&&$e->retryAfter!==null?$e->retryAfter:min(900,15*(2**max(0,$attempts-1)));$retryAfter=max(3,$retryAfter);
        if($stage==='download')self::purgeJobFiles($jobId,(string)($fresh['file_path']??''));
        if($retry){$nextStatus=$stage==='download'?'queued':'downloaded';$progress=$stage==='download'?0:70;App::q("UPDATE media_jobs SET status=?,progress=?,next_attempt_at=DATE_ADD(NOW(),INTERVAL {$retryAfter} SECOND),error_code=?,error_message=?,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,eta_seconds=?,updated_at=NOW() WHERE id=?",[$nextStatus,$progress,$code,$message,$retryAfter,$jobId]);self::event($jobId,'warning','retry',"خطا رخ داد؛ تلاش بعدی حدود {$retryAfter} ثانیه دیگر انجام می‌شود.",['code'=>$code,'stage'=>$stage,'attempt'=>$attempts,'max'=>$max,'error'=>$message]);}
        else{App::q("UPDATE media_jobs SET status='failed',error_code=?,error_message=?,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,eta_seconds=NULL,finished_at=NOW(),updated_at=NOW() WHERE id=?",[$code,$message,$jobId]);self::event($jobId,'error','failed','پردازش این لینک پس از پایان Retryها متوقف شد.',['code'=>$code,'stage'=>$stage,'attempt'=>$attempts,'error'=>$message]);}
        App::logEvent('media_job_error',$message,['job_id'=>$jobId,'batch_id'=>$job['batch_id'],'code'=>$code,'stage'=>$stage,'retry'=>$retry]);
        return $retry;
    }

    public static function syncBatch(int $batchId): void
    {
        $counts=App::one("SELECT COUNT(*) total,SUM(status='completed') done,SUM(status='failed') failed,SUM(status='cancelled') cancelled,SUM(status NOT IN ('completed','failed','cancelled')) pending FROM media_jobs WHERE batch_id=?",[$batchId]);if(!$counts)return;
        $total=(int)$counts['total'];$done=(int)$counts['done'];$failed=(int)$counts['failed'];$cancelled=(int)$counts['cancelled'];$pending=(int)$counts['pending'];
        $batch=App::one('SELECT status,scan_status,scan_attempts,source_type,source_video_count,source_skipped_items,title,channel_id,destination_channels_json,notification_status FROM media_batches WHERE id=?',[$batchId]);if(!$batch)return;
        $telegram=(string)($batch['source_type']??'')==='telegram_channel';$scanStatus=(string)($batch['scan_status']??'completed');
        if($telegram&&in_array($scanStatus,['queued','scanning'],true)){
            if(!in_array((string)$batch['status'],['paused','cancelled'],true)){$expected=$scanStatus==='scanning'?'running':'queued';App::q('UPDATE media_batches SET status=?,completed_at=NULL,updated_at=NOW() WHERE id=?',[$expected,$batchId]);}
            return;
        }
        if($telegram&&$scanStatus==='completed'&&$total===0){
            $scanAttempts=(int)($batch['scan_attempts']??0);$sourceVideos=(int)($batch['source_video_count']??0);$skipped=(int)($batch['source_skipped_items']??0);
            $provenEmpty=$scanAttempts>0&&$sourceVideos===0;$provenSynced=$scanAttempts>0&&$sourceVideos>0&&$skipped>=$sourceVideos;
            if(!$provenEmpty&&!$provenSynced){
                App::q("UPDATE media_batches SET status='queued',scan_status='queued',scan_attempts=0,scan_next_attempt_at=NOW(),scan_error='صف 0/0 نامعتبر بود؛ برای ارزیابی دوباره مقصد و موارد تکراری، اسکن از ابتدا اجرا می‌شود.',scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,source_last_message_id=0,source_scanned_items=0,source_skipped_items=0,completed_at=NULL,updated_at=NOW() WHERE id=?",[$batchId]);
                return;
            }
        }
        $status=(string)$batch['status'];
        if($status!=='cancelled'&&$status!=='paused'){
            $zeroTelegramComplete=$telegram&&$scanStatus==='completed'&&$total===0;
            if($pending===0&&($total>0||$zeroTelegramComplete))$status=$failed>0||$cancelled>0?'completed_with_errors':'completed';
            elseif($status!=='running')$status='queued';
        }
        $complete=in_array($status,['completed','completed_with_errors','cancelled'],true);
        App::q('UPDATE media_batches SET status=?,total_items=?,completed_items=?,failed_items=?,current_item_id=NULL,completed_at='.($complete?'COALESCE(completed_at,NOW())':'NULL').',updated_at=NOW() WHERE id=?',[$status,$total,$done,$failed,$batchId]);
        if($complete&&($total>0||($telegram&&(int)($batch['source_skipped_items']??0)>0))&&(string)($batch['notification_status']??'')!==$status){
            $icon=$status==='completed'?'✅':($status==='cancelled'?'⛔️':'⚠️');
            $destinations=json_decode((string)($batch['destination_channels_json']??''),true);if(!is_array($destinations)||$destinations===[])$destinations=[$batch['channel_id']];
            App::sendLog($icon.' <b>پایان دسته دانلود #'.$batchId.'</b>'."\nعنوان: ".App::h($batch['title'])."\nمقصدها: <code>".App::h(implode(' , ',array_map('strval',$destinations)))."</code>\nموفق: <b>{$done}</b> | خطا: <b>{$failed}</b> | کل: <b>{$total}</b>");
            App::q('UPDATE media_batches SET notification_status=?,notification_sent_at=NOW() WHERE id=?',[$status,$batchId]);
        }
    }

    public static function summary(): array
    {
        $jobs=App::one("SELECT COUNT(*) total,SUM(status='queued') queued,SUM(status IN ('downloading','uploading')) active,SUM(next_attempt_at>NOW() AND status IN ('queued','downloaded')) retry_wait,SUM(status='downloaded') downloaded,SUM(status='completed') done,SUM(status='failed') failed FROM media_jobs")??[];
        $jobs['batches']=(int)(App::one("SELECT COUNT(*) c FROM media_batches WHERE status IN ('queued','running','paused')")['c']??0);
        $jobs['scans']=(int)(App::one("SELECT COUNT(*) c FROM media_batches WHERE scan_status IN ('queued','scanning')")['c']??0);
        return array_map(static fn($v):int=>(int)($v??0),$jobs);
    }

    public static function engineInfo(): array
    {
        $path=self::ytDlpPath();$storage=__DIR__.'/storage/media';$storageError='';try{$storage=self::storageRoot();}catch(Throwable $e){$storageError=$e->getMessage();}
        return ['yt_dlp'=>$path!==null,'yt_dlp_path'=>$path??'','aria2'=>self::aria2Path()!==null,'aria2_path'=>self::aria2Path()??'','ffmpeg'=>self::ffmpegPath()!==null,'ffmpeg_path'=>self::ffmpegPath()??'','mediainfo'=>self::mediainfoPath()!==null,'mediainfo_path'=>self::mediainfoPath()??'','proc_open'=>self::functionEnabled('proc_open'),'storage_writable'=>is_dir($storage)&&is_writable($storage),'storage_path'=>$storage,'storage_error'=>$storageError,'max_bytes'=>self::maxBytes(),'workers'=>self::activeWorkers()];
    }

    public static function refreshChannelStats(?int $productId=null): array
    {
        $products=$productId?App::all('SELECT * FROM products WHERE id=?',[$productId]):App::all('SELECT * FROM products ORDER BY id');$ok=0;$failed=0;
        $me=App::telegram('getMe');$botId=(string)($me['id']??'');
        foreach($products as $product){
            try{
                $chat=App::telegram('getChat',['chat_id'=>$product['channel_id']]);$members=(int)App::telegram('getChatMemberCount',['chat_id'=>$product['channel_id']]);$admins=(array)App::telegram('getChatAdministrators',['chat_id'=>$product['channel_id']]);$member=$botId!==''?App::telegram('getChatMember',['chat_id'=>$product['channel_id'],'user_id'=>$botId]):[];$status=(string)($member['status']??'unknown');$canPost=$status==='creator'||($status==='administrator'&&($member['can_post_messages']??false));
                App::q('INSERT INTO channel_stats(product_id,channel_id,channel_title,channel_username,member_count,admin_count,bot_status,can_post,last_error,refreshed_at) VALUES (?,?,?,?,?,?,?,?,NULL,NOW()) ON DUPLICATE KEY UPDATE channel_id=VALUES(channel_id),channel_title=VALUES(channel_title),channel_username=VALUES(channel_username),member_count=VALUES(member_count),admin_count=VALUES(admin_count),bot_status=VALUES(bot_status),can_post=VALUES(can_post),last_error=NULL,refreshed_at=NOW()',[$product['id'],$product['channel_id'],$chat['title']??null,$chat['username']??null,$members,count($admins),$status,$canPost?1:0]);$ok++;
            }catch(Throwable $e){App::q('INSERT INTO channel_stats(product_id,channel_id,last_error,refreshed_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE channel_id=VALUES(channel_id),last_error=VALUES(last_error),refreshed_at=NOW()',[$product['id'],$product['channel_id'],self::cleanError($e->getMessage())]);$failed++;App::logEvent('channel_stats_error',$e->getMessage(),['product_id'=>$product['id'],'channel_id'=>$product['channel_id']]);}
        }
        return ['ok'=>$ok,'failed'=>$failed];
    }

    private static function webScannerCredentials(): ?array
    {
        $apiId=(string)App::setting('channel_scanner_api_id','');$apiHash=(string)App::setting('channel_scanner_api_hash','');$phone=(string)App::setting('channel_scanner_phone','');
        if($apiId===''||$apiHash===''||$phone==='')return null;
        try{return ['api_id'=>App::decrypt($apiId),'api_hash'=>App::decrypt($apiHash),'phone'=>App::decrypt($phone)];}catch(Throwable){return null;}
    }

    private static function scannerRuntime(): array
    {
        return ['python'=>'/opt/freebot-tools/bin/python','script'=>__DIR__.'/scripts/channel_history_scan.py','config'=>'/etc/freebot/channel-scanner.env','session_base'=>'/var/lib/freebot-mtproto/freebot','session'=>'/var/lib/freebot-mtproto/freebot.session'];
    }

    private static function newScannerResultFile(): string
    {
        $dir='/var/lib/freebot-mtproto';if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('مسیر امن نشست Telethon قابل نوشتن نیست؛ update.sh را اجرا کنید.');
        foreach(glob($dir.'/result-*.ndjson')?:[] as $old)if(is_file($old)&&filemtime($old)<time()-3600)@unlink($old);
        return $dir.'/result-'.bin2hex(random_bytes(16)).'.ndjson';
    }

    private static function newScannerCaptureFile(string $kind): string
    {
        if(!in_array($kind,['stdin','stderr'],true))throw new RuntimeException('نوع فایل خروجی Telethon نامعتبر است.');
        $dir='/var/lib/freebot-mtproto';if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('مسیر امن نشست Telethon قابل نوشتن نیست؛ update.sh را اجرا کنید.');
        foreach(glob($dir.'/capture-*.log')?:[] as $old)if(is_file($old)&&filemtime($old)<time()-3600)@unlink($old);
        $path=$dir.'/capture-'.$kind.'-'.bin2hex(random_bytes(16)).'.log';$handle=@fopen($path,'x');if($handle===false)throw new RuntimeException('ساخت فایل امن خروجی Telethon ممکن نشد.');@chmod($path,0600);fclose($handle);return $path;
    }

    private static function streamTelegramVideoList(string $sourceChannel,callable $onVideo,?callable $heartbeat=null,int $minMessageId=0,?callable $onInventory=null): array
    {
        $runtime=self::scannerRuntime();$scanner=self::historyScannerStatus();
        if(!is_executable($runtime['python'])||!is_file($runtime['script']))throw new RuntimeException('موتور Telethon نصب نیست؛ ابتدا update.sh را اجرا کنید.');
        if(!self::functionEnabled('proc_open'))throw new RuntimeException('تابع proc_open در PHP غیرفعال است؛ update.sh را اجرا کنید.');
        if(!is_executable('/usr/bin/timeout'))throw new RuntimeException('فرمان timeout روی سرور نصب نیست؛ update.sh را اجرا کنید.');
        $lockName='freebot-mtproto-session';$locked=(int)(App::one('SELECT GET_LOCK(?,0) acquired',[$lockName])['acquired']??0)===1;
        if(!$locked)throw new RuntimeException('نشست تلگرام در حال استفاده است؛ پس از پایان دانلود یا اسکن دوباره تلاش کنید.');
        $resultFile='';$inputFile='';$stderrFile='';
        try{
            $resultFile=self::newScannerResultFile();$stderrFile=self::newScannerCaptureFile('stderr');$timeout=max(300,min(21600,(int)App::setting('channel_history_scan_timeout','7200')));
            $command=['/usr/bin/timeout','--signal=TERM','--kill-after=5s',$timeout.'s',$runtime['python'],$runtime['script'],'--list-videos','--channel',$sourceChannel,'--min-message-id',(string)max(0,$minMessageId),'--session',$scanner['session_base'],'--config',$runtime['config'],'--result-file',$resultFile];
            $input=self::webScannerCredentials()??[];
            if($input!==[]){$inputFile=self::newScannerCaptureFile('stdin');if(file_put_contents($inputFile,App::j($input),LOCK_EX)===false)throw new RuntimeException('ثبت ورودی امن Telethon ممکن نشد.');@chmod($inputFile,0600);$command[]='--json-input';}
            $descriptors=[0=>['file',$inputFile!==''?$inputFile:'/dev/null','r'],1=>['file','/dev/null','a'],2=>['file',$stderrFile,'a']];$pipes=[];
            $process=proc_open($command,$descriptors,$pipes,null,null,['bypass_shell'=>true]);if(!is_resource($process))throw new RuntimeException('اجرای مستقل موتور Telethon ممکن نشد.');
            $exitCode=0;$lastHeartbeat=0.0;$reader=null;$readBuffer='';$summary=null;$delivered=[];
            $consume=static function(bool $final=false)use(&$reader,&$readBuffer,&$summary,&$delivered,$resultFile,$onVideo,$onInventory):void{
                if($reader===null&&is_file($resultFile)){$opened=@fopen($resultFile,'rb');if(is_resource($opened))$reader=$opened;}
                if(!is_resource($reader))return;
                $chunk=stream_get_contents($reader);if(is_string($chunk)&&$chunk!=='')$readBuffer.=$chunk;
                $lines=[];while(($newline=strpos($readBuffer,"\n"))!==false){$lines[]=substr($readBuffer,0,$newline);$readBuffer=substr($readBuffer,$newline+1);}if($final&&trim($readBuffer)!==''){$lines[]=$readBuffer;$readBuffer='';}
                foreach($lines as $line){$decoded=json_decode(trim($line),true,512,JSON_BIGINT_AS_STRING|JSON_INVALID_UTF8_SUBSTITUTE);if(!is_array($decoded))continue;$type=(string)($decoded['type']??'');if($type==='video'){$key=(string)($decoded['source_chat_id']??'').':'.(string)($decoded['message_id']??'');if(isset($delivered[$key]))continue;$delivered[$key]=true;$onVideo($decoded);}elseif($type==='inventory'){if($onInventory!==null)$onInventory($decoded);}elseif($type!=='progress')$summary=$decoded;}
            };
            try{
                while(true){$consume();$status=proc_get_status($process);if(!$status['running']){$exitCode=(int)$status['exitcode'];break;}$now=microtime(true);if($heartbeat!==null&&$now-$lastHeartbeat>=3){$lastHeartbeat=$now;if(!$heartbeat()){proc_terminate($process,15);throw new MediaQueueException('SCAN_CANCELLED','اسکن کانال توسط مدیر متوقف شد یا قفل آن منقضی شد.');}}usleep(250000);}
            }catch(Throwable $e){proc_terminate($process,15);usleep(200000);throw $e;}
            finally{$closed=proc_close($process);if($exitCode<0&&$closed>=0)$exitCode=$closed;}
            $consume(true);if(is_resource($reader))fclose($reader);
            clearstatcache(true,$resultFile);clearstatcache(true,$stderrFile);
            $size=(int)(@filesize($resultFile)?:0);if($size<=0)throw new RuntimeException('موتور Telethon فهرست ویدیوها را ثبت نکرد (کد '.$exitCode.').');
            if($size>536870912)throw new RuntimeException('فهرست اسکن از سقف ایمن ۵۱۲ مگابایت بیشتر شد.');
            if($exitCode>0||!is_array($summary)||!($summary['ok']??false)){$detail=is_array($summary)?(string)($summary['error']??''):'';if($detail===''){$stderr=is_file($stderrFile)?file_get_contents($stderrFile,false,null,0,1048577):'';$detail=trim(is_string($stderr)?$stderr:'');}if($detail==='')$detail='موتور Telethon اسکن را ناموفق پایان داد (کد '.$exitCode.'، حجم فهرست '.$size.' بایت).';throw new RuntimeException(self::cleanError($detail));}
            return $summary;
        }finally{
            if(isset($reader)&&is_resource($reader))@fclose($reader);
            foreach([$resultFile,$inputFile,$stderrFile] as $file)if($file!=='')@unlink($file);
            try{App::q('SELECT RELEASE_LOCK(?)',[$lockName]);}catch(Throwable){}
        }
    }

    private static function runHistoryScanner(array $arguments,array $input=[],int $timeout=120): array
    {
        $runtime=self::scannerRuntime();
        if(!is_executable($runtime['python'])||!is_file($runtime['script']))throw new RuntimeException('موتور Telethon نصب نیست؛ ابتدا update.sh را اجرا کنید.');
        if(!self::functionEnabled('exec'))throw new RuntimeException('تابع exec در PHP غیرفعال است؛ تنظیمات PHP-FPM سرور را بررسی کنید.');
        if(!is_executable('/usr/bin/timeout'))throw new RuntimeException('فرمان timeout روی سرور نصب نیست؛ update.sh را اجرا کنید.');
        $resultFile='';$inputFile='';$stderrFile='';
        try{
            $resultFile=self::newScannerResultFile();$stderrFile=self::newScannerCaptureFile('stderr');
            $timeout=max(10,min(21600,$timeout));$command=array_merge(['/usr/bin/timeout','--signal=TERM','--kill-after=5s',$timeout.'s',$runtime['python'],$runtime['script']],$arguments,['--config',$runtime['config'],'--result-file',$resultFile]);
            if($input!==[]){$inputFile=self::newScannerCaptureFile('stdin');if(file_put_contents($inputFile,App::j($input),LOCK_EX)===false)throw new RuntimeException('ثبت ورودی امن Telethon ممکن نشد.');@chmod($inputFile,0600);$command[]='--json-input';}
            $shell=implode(' ',array_map('escapeshellarg',$command)).($inputFile!==''?' < '.escapeshellarg($inputFile):' < /dev/null').' 2> '.escapeshellarg($stderrFile);
            $captured=[];$exitCode=0;exec($shell,$captured,$exitCode);clearstatcache(true,$resultFile);clearstatcache(true,$stderrFile);
            $stdout=implode("\n",$captured);$stored=is_file($resultFile)?file_get_contents($resultFile,false,null,0,1048577):false;$stderr=file_get_contents($stderrFile,false,null,0,1048577);
            if(strlen($stdout)>1048576||(int)(@filesize($resultFile)?:0)>1048576||(int)(@filesize($stderrFile)?:0)>1048576)throw new RuntimeException('خروجی موتور Telethon بیش از حد مجاز بود.');
            $combined=(is_string($stored)&&trim($stored)!=='')?$stored:$stdout;$lines=array_values(array_filter(array_map('trim',preg_split('/\R/',$combined)?:[])));$payload=null;
            for($index=count($lines)-1;$index>=0;$index--){$decoded=json_decode($lines[$index],true,512,JSON_BIGINT_AS_STRING|JSON_INVALID_UTF8_SUBSTITUTE);if(is_array($decoded)){$payload=$decoded;break;}}
            if($exitCode>0||!is_array($payload)||!($payload['ok']??false)){$detail=is_array($payload)?(string)($payload['error']??''):'';if($detail==='')$detail=trim(is_string($stderr)?$stderr:'');if($detail===''&&$stdout!=='')$detail=self::lastLines($stdout,8);if($detail==='')$detail='Telethon هیچ خروجی ثبت نکرد (کد '.$exitCode.'). اجراگر: exec-capture، خطوط: '.count($captured).'، فایل نتیجه: '.(int)(@filesize($resultFile)?:0).' بایت، نسخه: '.substr(@hash_file('sha256',$runtime['script'])?:'unknown',0,12);throw new RuntimeException(self::cleanError($detail));}
            return $payload;
        }finally{
            foreach([$resultFile,$inputFile,$stderrFile] as $file)if($file!=='')@unlink($file);
        }
    }

    public static function historyScannerTransportProbe(): array
    {
        $token=bin2hex(random_bytes(16));$payload=self::runHistoryScanner(['--transport-probe',$token],[],30);
        if(!hash_equals($token,(string)($payload['probe']??''))||($payload['unicode']??'')!=='تست 🎬')throw new RuntimeException('پاسخ تست انتقال Telethon با درخواست یا UTF-8 مطابقت ندارد.');
        return $payload;
    }

    private static function cleanupSetupSession(string $base): void
    {
        if(!preg_match('#^/var/lib/freebot-mtproto/setup-[a-f0-9]{32}$#',$base))return;
        foreach(glob($base.'.session*')?:[] as $file)if(is_file($file))@unlink($file);
    }

    private static function pendingScannerSetup(): array
    {
        $setup=$_SESSION['channel_scanner_setup']??null;if(!is_array($setup)||time()-(int)($setup['created_at']??0)>1200){if(is_array($setup))self::cleanupSetupSession((string)($setup['session_base']??''));unset($_SESSION['channel_scanner_setup']);throw new RuntimeException('زمان راه‌اندازی منقضی شده است؛ دوباره کد ورود بگیرید.');}
        try{return $setup+['api_id_plain'=>App::decrypt((string)$setup['api_id']),'api_hash_plain'=>App::decrypt((string)$setup['api_hash']),'phone_plain'=>App::decrypt((string)$setup['phone']),'phone_code_hash_plain'=>App::decrypt((string)($setup['phone_code_hash']??''))];}catch(Throwable){throw new RuntimeException('اطلاعات موقت راه‌اندازی معتبر نیست؛ دوباره شروع کنید.');}
    }

    public static function historyScannerSetupState(): array
    {
        $setup=$_SESSION['channel_scanner_setup']??null;if(!is_array($setup)||time()-(int)($setup['created_at']??0)>1200)return ['step'=>'start'];
        $phone='';try{$phone=App::decrypt((string)$setup['phone']);}catch(Throwable){}
        $masked=$phone!==''?substr($phone,0,min(4,strlen($phone))).str_repeat('•',max(3,strlen($phone)-6)).substr($phone,-2):'';
        return ['step'=>in_array($setup['step']??'',['code','password'],true)?$setup['step']:'start','phone'=>$masked];
    }

    public static function startHistoryScannerSetup(string $apiId,string $apiHash,string $phone): array
    {
        $apiId=trim($apiId);$apiHash=trim($apiHash);$phone=preg_replace('/[\s()-]+/','',trim($phone))??'';
        if(!preg_match('/^[1-9][0-9]{3,14}$/',$apiId))throw new RuntimeException('API ID معتبر نیست.');
        if(!preg_match('/^[a-fA-F0-9]{20,64}$/',$apiHash))throw new RuntimeException('API Hash معتبر نیست.');
        if(!preg_match('/^\+[0-9]{7,18}$/',$phone))throw new RuntimeException('شماره را همراه کد کشور وارد کنید؛ مانند +49123...');
        $dir='/var/lib/freebot-mtproto';if(!is_dir($dir)||!is_writable($dir))throw new RuntimeException('مسیر امن نشست قابل نوشتن نیست؛ ابتدا update.sh را روی سرور اجرا کنید.');
        if(is_array($_SESSION['channel_scanner_setup']??null))self::cleanupSetupSession((string)$_SESSION['channel_scanner_setup']['session_base']);
        foreach(glob($dir.'/setup-*.session*')?:[] as $file)if(is_file($file)&&filemtime($file)<time()-3600)@unlink($file);
        $base=$dir.'/setup-'.bin2hex(random_bytes(16));$credentials=['api_id'=>$apiId,'api_hash'=>$apiHash,'phone'=>$phone];
        $result=self::runHistoryScanner(['--web-action','send-code','--session',$base],$credentials,120);
        $_SESSION['channel_scanner_setup']=['step'=>'code','created_at'=>time(),'session_base'=>$base,'api_id'=>App::encrypt($apiId),'api_hash'=>App::encrypt($apiHash),'phone'=>App::encrypt($phone),'phone_code_hash'=>App::encrypt((string)$result['phone_code_hash'])];
        return $result;
    }

    public static function verifyHistoryScannerCode(string $code): array
    {
        $setup=self::pendingScannerSetup();$input=['api_id'=>$setup['api_id_plain'],'api_hash'=>$setup['api_hash_plain'],'phone'=>$setup['phone_plain'],'phone_code_hash'=>$setup['phone_code_hash_plain'],'code'=>trim($code)];
        $result=self::runHistoryScanner(['--web-action','verify-code','--session',$setup['session_base']],$input,120);
        if(($result['step']??'')==='password'){$_SESSION['channel_scanner_setup']['step']='password';return $result;}
        self::finalizeHistoryScannerSetup($setup,$result);return $result;
    }

    public static function verifyHistoryScannerPassword(string $password): array
    {
        $setup=self::pendingScannerSetup();$input=['api_id'=>$setup['api_id_plain'],'api_hash'=>$setup['api_hash_plain'],'phone'=>$setup['phone_plain'],'password'=>$password];
        $result=self::runHistoryScanner(['--web-action','verify-password','--session',$setup['session_base']],$input,120);self::finalizeHistoryScannerSetup($setup,$result);return $result;
    }

    private static function finalizeHistoryScannerSetup(array $setup,array $user): void
    {
        $source=$setup['session_base'].'.session';$runtime=self::scannerRuntime();if(!is_file($source))throw new RuntimeException('فایل نشست تأییدشده ساخته نشد.');
        $lock='freebot-mtproto-session';$locked=(int)(App::one('SELECT GET_LOCK(?,0) acquired',[$lock])['acquired']??0)===1;if(!$locked)throw new RuntimeException('نشست تلگرام در حال استفاده است؛ چند لحظه بعد دوباره تأیید کنید.');
        try{if(!@rename($source,$runtime['session']))throw new RuntimeException('ثبت امن نشست تلگرام ناموفق بود.');@chmod($runtime['session'],0600);
            App::setSetting('channel_scanner_api_id',App::encrypt($setup['api_id_plain']));App::setSetting('channel_scanner_api_hash',App::encrypt($setup['api_hash_plain']));App::setSetting('channel_scanner_phone',App::encrypt($setup['phone_plain']));App::setSetting('channel_scanner_account_id',(string)($user['authorized_user_id']??''));App::setSetting('channel_scanner_account_name',(string)($user['name']??''));App::setSetting('channel_scanner_account_username',(string)($user['username']??''));App::setSetting('channel_scanner_configured_at',date('Y-m-d H:i:s'));
        }finally{try{App::q('SELECT RELEASE_LOCK(?)',[$lock]);}catch(Throwable){}}
        self::cleanupSetupSession($setup['session_base']);unset($_SESSION['channel_scanner_setup']);App::logEvent('channel_scanner_connected','حساب اسکنر تاریخچه از پنل وب متصل شد.',['account_id'=>$user['authorized_user_id']??null]);
    }

    public static function cancelHistoryScannerSetup(): void
    {
        $setup=$_SESSION['channel_scanner_setup']??null;if(is_array($setup))self::cleanupSetupSession((string)($setup['session_base']??''));unset($_SESSION['channel_scanner_setup']);
    }

    public static function testHistoryScanner(): array
    {
        $status=self::historyScannerStatus();if(!$status['ready'])throw new RuntimeException('اسکنر تاریخچه آماده نیست.');$input=self::webScannerCredentials()??[];return self::runHistoryScanner(['--web-action','status','--session',$status['session_base']],$input,120);
    }

    public static function disconnectHistoryScanner(): void
    {
        $lock='freebot-mtproto-session';$locked=(int)(App::one('SELECT GET_LOCK(?,0) acquired',[$lock])['acquired']??0)===1;if(!$locked)throw new RuntimeException('نشست تلگرام در حال استفاده است؛ پس از پایان عملیات دوباره تلاش کنید.');
        try{$runtime=self::scannerRuntime();foreach(glob($runtime['session_base'].'.session*')?:[] as $file)if(is_file($file))@unlink($file);foreach(['channel_scanner_api_id','channel_scanner_api_hash','channel_scanner_phone','channel_scanner_account_id','channel_scanner_account_name','channel_scanner_account_username','channel_scanner_configured_at'] as $key)App::setSetting($key,'');self::cancelHistoryScannerSetup();App::logEvent('channel_scanner_disconnected','نشست اسکنر تاریخچه از پنل حذف شد.');}finally{try{App::q('SELECT RELEASE_LOCK(?)',[$lock]);}catch(Throwable){}}
    }

    public static function historyScannerStatus(): array
    {
        $runtime=self::scannerRuntime();$web=self::webScannerCredentials();$cli=is_readable($runtime['config']);$engine=is_executable($runtime['python'])&&is_file($runtime['script'])&&self::functionEnabled('proc_open');$ready=$engine&&is_readable($runtime['session'])&&($web!==null||$cli);
        return $runtime+['ready'=>$ready,'engine'=>$engine,'credentials'=>$web!==null||$cli,'source'=>$web!==null?'web':($cli?'server':'none'),'account_id'=>(string)App::setting('channel_scanner_account_id',''),'account_name'=>(string)App::setting('channel_scanner_account_name',''),'account_username'=>(string)App::setting('channel_scanner_account_username',''),'configured_at'=>(string)App::setting('channel_scanner_configured_at','')];
    }

    public static function scanChannelHistory(int $productId): array
    {
        $product=App::one('SELECT id,title,channel_id FROM products WHERE id=?',[$productId]);
        if(!$product)throw new RuntimeException('محصول یا کانال پیدا نشد.');
        $scanner=self::historyScannerStatus();
        if(!$scanner['ready'])throw new RuntimeException('اسکنر تاریخچه هنوز راه‌اندازی نشده است؛ ابتدا setup-channel-scanner.sh را روی سرور اجرا کنید.');
        // One Telethon session is shared by all products, so serialize scans to
        // avoid concurrent writes to the access-restricted session database.
        $lockName='freebot-mtproto-session';
        $locked=(int)(App::one('SELECT GET_LOCK(?,0) acquired',[$lockName])['acquired']??0)===1;
        if(!$locked)throw new RuntimeException('اسکن تاریخچه یک کانال دیگر هم‌اکنون در حال اجراست.');
        try{
            App::q("INSERT INTO channel_stats(product_id,channel_id,history_scan_status,history_scan_error) VALUES (?,?,'running',NULL) ON DUPLICATE KEY UPDATE channel_id=VALUES(channel_id),history_scan_status='running',history_scan_error=NULL",[$productId,$product['channel_id']]);
            $input=self::webScannerCredentials()??[];$payload=self::runHistoryScanner(['--channel',(string)$product['channel_id'],'--session',$scanner['session_base']],$input,max(300,min(21600,(int)App::setting('channel_history_scan_timeout','7200'))));
            $values=[];foreach(['last_message_id','message_count','video_count','photo_count','file_count','total_bytes'] as $key)$values[$key]=max(0,(int)($payload[$key]??0));
            App::q("UPDATE channel_stats SET channel_title=COALESCE(NULLIF(?,''),channel_title),history_last_message_id=?,history_message_count=?,history_video_count=?,history_photo_count=?,history_file_count=?,history_total_bytes=?,history_scan_status='completed',history_scan_error=NULL,history_scanned_at=NOW() WHERE product_id=?",[(string)($payload['channel_title']??''),$values['last_message_id'],$values['message_count'],$values['video_count'],$values['photo_count'],$values['file_count'],$values['total_bytes'],$productId]);
            App::logEvent('channel_history_scan_completed','تاریخچه کانال با موفقیت اسکن شد.',['product_id'=>$productId,'channel_id'=>$product['channel_id']]+$values);
            return $values+['product_id'=>$productId,'channel_title'=>(string)($payload['channel_title']??'')];
        }catch(Throwable $e){
            App::q("INSERT INTO channel_stats(product_id,channel_id,history_scan_status,history_scan_error) VALUES (?,?,'failed',?) ON DUPLICATE KEY UPDATE history_scan_status='failed',history_scan_error=VALUES(history_scan_error)",[$productId,$product['channel_id'],self::cleanError($e->getMessage())]);
            App::logEvent('channel_history_scan_failed',$e->getMessage(),['product_id'=>$productId,'channel_id'=>$product['channel_id']]);
            throw $e;
        }finally{
            try{App::q('SELECT RELEASE_LOCK(?)',[$lockName]);}catch(Throwable){}
        }
    }

    public static function channelRows(): array
    {
        return App::all("SELECT p.id,p.title product_title,p.channel_id,p.enabled,s.channel_title,s.channel_username,s.member_count,s.admin_count,s.bot_status,s.can_post,s.last_error,s.refreshed_at,s.history_last_message_id,s.history_message_count,s.history_video_count,s.history_photo_count,s.history_file_count,s.history_total_bytes,s.history_scan_status,s.history_scan_error,s.history_scanned_at,
            (SELECT COUNT(*) FROM orders o WHERE o.product_id=p.id AND o.status='paid') sales_count,
            (SELECT COALESCE(SUM(o.amount),0) FROM orders o WHERE o.product_id=p.id AND o.status='paid') sales_amount,
            (SELECT COUNT(*) FROM invite_link_events i WHERE i.product_id=p.id) invite_count,
            COALESCE(s.history_video_count,0)+(SELECT COUNT(*) FROM channel_posts cp WHERE cp.chat_id=p.channel_id AND cp.message_id>COALESCE(s.history_last_message_id,0) AND cp.media_type='video') video_count,
            COALESCE(s.history_photo_count,0)+(SELECT COUNT(*) FROM channel_posts cp WHERE cp.chat_id=p.channel_id AND cp.message_id>COALESCE(s.history_last_message_id,0) AND cp.media_type='photo') photo_count,
            COALESCE(s.history_file_count,0)+(SELECT COUNT(*) FROM channel_posts cp WHERE cp.chat_id=p.channel_id AND cp.message_id>COALESCE(s.history_last_message_id,0) AND cp.media_type IN ('document','animation','audio')) file_count,
            COALESCE(s.history_message_count,0)+(SELECT COUNT(*) FROM channel_posts cp WHERE cp.chat_id=p.channel_id AND cp.message_id>COALESCE(s.history_last_message_id,0)) tracked_posts,
            (SELECT COUNT(*) FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id WHERE b.product_id=p.id AND j.status='completed') downloader_done
            FROM products p LEFT JOIN channel_stats s ON s.product_id=p.id ORDER BY p.id DESC");
    }

    public static function recentBatches(int $limit=30): array
    {
        $rows=App::all("SELECT b.*,p.title product_title FROM media_batches b LEFT JOIN products p ON p.id=b.product_id ORDER BY CASE WHEN b.scan_status='scanning' THEN 0 WHEN b.status='running' THEN 1 WHEN b.scan_status='queued' THEN 2 WHEN b.status='queued' THEN 3 WHEN b.status='paused' THEN 4 WHEN b.status='completed_with_errors' THEN 5 WHEN b.status='cancelled' THEN 6 ELSE 7 END,b.id DESC LIMIT ".max(1,min(100,$limit)));
        foreach($rows as &$row)$row['destination_stats']=self::batchDestinationStats((int)$row['id']);unset($row);return $rows;
    }

    public static function batchDestinationStats(int $batchId): array
    {
        // target_slot is numeric and stable, so group by it and resolve the
        // display channel in PHP instead of mixing differently-collated strings.
        $rows=App::all("SELECT MAX(j.target_channel_id) job_channel_id,MAX(b.channel_id) batch_channel_id,COALESCE(j.target_slot,1) target_slot,COUNT(*) total,SUM(j.status='completed') completed,SUM(j.status='failed') failed,SUM(j.status='cancelled') cancelled,SUM(j.status NOT IN ('completed','failed','cancelled')) pending,COALESCE(SUM(j.total_bytes),0) total_bytes FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id WHERE j.batch_id=? GROUP BY COALESCE(j.target_slot,1) ORDER BY target_slot",[$batchId]);
        foreach($rows as &$row){$target=trim((string)($row['job_channel_id']??''));$row['channel_id']=$target!==''?$target:(string)($row['batch_channel_id']??'');unset($row['job_channel_id'],$row['batch_channel_id']);}unset($row);
        return $rows;
    }

    public static function recentJobs(int $limit=100): array
    {
        $rows=App::all("SELECT j.*,b.title batch_title,b.channel_id,p.title product_title FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id LEFT JOIN products p ON p.id=b.product_id ORDER BY CASE j.status WHEN 'downloading' THEN 0 WHEN 'uploading' THEN 1 WHEN 'downloaded' THEN 2 WHEN 'queued' THEN 3 WHEN 'failed' THEN 4 WHEN 'cancelled' THEN 5 ELSE 6 END,CASE WHEN j.status IN ('downloading','uploading') THEN j.updated_at END DESC,j.id DESC LIMIT ".max(1,min(500,$limit)));
        foreach($rows as &$row)$row['effective_channel_id']=trim((string)($row['target_channel_id']??''))!==''?(string)$row['target_channel_id']:(string)$row['channel_id'];unset($row);
        return $rows;
    }
    public static function jobEvents(int $jobId,int $limit=100): array{return App::all('SELECT * FROM media_job_events WHERE job_id=? ORDER BY id DESC LIMIT '.max(1,min(500,$limit)),[$jobId]);}

    public static function statusLabel(string $status): string
    {
        return ['queued'=>'در صف','running'=>'در حال پردازش','paused'=>'متوقف','completed'=>'تکمیل‌شده','completed_with_errors'=>'کامل با خطا','cancelled'=>'لغوشده','downloading'=>'در حال دانلود','downloaded'=>'دانلودشده / منتظر آپلود','uploading'=>'در حال آپلود','failed'=>'ناموفق'][$status]??$status;
    }

    private static function event(int $jobId,string $level,string $stage,string $message,array $meta=[]): void{try{App::q('INSERT INTO media_job_events(job_id,level,stage,message,meta,created_at) VALUES (?,?,?,?,?,NOW())',[$jobId,$level,$stage,$message,$meta?App::j($meta):null]);}catch(Throwable){}}
    private static function eventForBatch(int $batchId,string $level,string $stage,string $message): void{$job=App::one('SELECT id FROM media_jobs WHERE batch_id=? ORDER BY id LIMIT 1',[$batchId]);if($job)self::event((int)$job['id'],$level,$stage,$message);}

    private static function renderCaption(string $template,array $job): string
    {
        if(trim($template)==='')$template="{title}\n\nقسمت {index} از {total}";
        $vars=['{title}'=>trim((string)($job['detected_title']??''))?:trim((string)$job['batch_title']),'{index}'=>$job['position'],'{total}'=>$job['total_items'],'{source}'=>$job['source_url'],'{product}'=>$job['product_title']??''];
        return trim(strtr($template,array_map('strval',$vars)));
    }

    private static function validateUrl(string $url): void
    {
        if(strlen($url)>4000||!filter_var($url,FILTER_VALIDATE_URL))throw new MediaQueueException('INVALID_URL','ساختار لینک معتبر نیست.');
        $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));if(!in_array($scheme,['http','https'],true))throw new MediaQueueException('UNSUPPORTED_SCHEME','فقط لینک HTTP و HTTPS مجاز است.');
        if(parse_url($url,PHP_URL_USER)!==null||parse_url($url,PHP_URL_PASS)!==null)throw new MediaQueueException('INVALID_URL','لینک دارای نام کاربری یا رمز مجاز نیست.');
        self::validatedPin($url);
    }

    private static function assertCanPost(string $channelId): void
    {
        try{$me=App::telegram('getMe');$member=App::telegram('getChatMember',['chat_id'=>$channelId,'user_id'=>$me['id']??0]);$status=(string)($member['status']??'');$allowed=$status==='creator'||($status==='administrator'&&($member['can_post_messages']??false));if(!$allowed)throw new RuntimeException('ربات در کانال مقصد ادمین نیست یا اجازه ارسال پست ندارد.');}
        catch(RuntimeException $e){throw $e;}catch(Throwable $e){throw new RuntimeException('بررسی کانال مقصد ناموفق بود: '.$e->getMessage());}
    }

    private static function validatedPin(string $url): ?string
    {
        $host=strtolower((string)parse_url($url,PHP_URL_HOST));if($host===''||$host==='localhost'||str_ends_with($host,'.local')||str_ends_with($host,'.internal'))throw new MediaQueueException('PRIVATE_URL','آدرس‌های داخلی سرور قابل دانلود نیستند.');
        $scheme=strtolower((string)parse_url($url,PHP_URL_SCHEME));$port=(int)(parse_url($url,PHP_URL_PORT)?:($scheme==='https'?443:80));
        $ips=[];
        if(filter_var($host,FILTER_VALIDATE_IP))$ips[]=$host;
        else{
            $a=gethostbynamel($host);if(is_array($a))$ips=array_merge($ips,$a);
            if(function_exists('dns_get_record')){foreach(dns_get_record($host,DNS_AAAA)?:[] as $row)if(!empty($row['ipv6']))$ips[]=$row['ipv6'];}
        }
        $ips=array_values(array_unique($ips));if(!$ips)throw new MediaQueueException('DNS_FAILED','دامنه لینک قابل شناسایی نیست.');
        foreach($ips as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))throw new MediaQueueException('PRIVATE_URL','لینک به شبکه داخلی یا رزروشده اشاره می‌کند.');
        if(filter_var($host,FILTER_VALIDATE_IP))return null;
        $ip=$ips[0];if(str_contains($ip,':'))$ip='['.$ip.']';return $host.':'.$port.':'.$ip;
    }

    private static function head(string $url): array
    {
        for($i=0;$i<6;$i++){
            $headers=[];$pin=self::validatedPin($url);$ch=curl_init($url);$opts=[CURLOPT_NOBODY=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_RETURNTRANSFER=>true,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_USERAGENT=>self::userAgent(),CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$headers):int{$len=strlen($line);$p=strpos($line,':');if($p!==false)$headers[strtolower(trim(substr($line,0,$p)))]=trim(substr($line,$p+1));return $len;}];if($pin)$opts[CURLOPT_RESOLVE]=[$pin];if(defined('CURLOPT_PROTOCOLS'))$opts[CURLOPT_PROTOCOLS]=CURLPROTO_HTTP|CURLPROTO_HTTPS;curl_setopt_array($ch,$opts);$body=curl_exec($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            if(in_array($status,[301,302,303,307,308],true)&&isset($headers['location'])){$url=self::absoluteUrl($headers['location'],$url);self::validateUrl($url);continue;}
            if($body===false||$status<200||$status>=400)throw new MediaQueueException('PROBE_HTTP',"بررسی لینک ناموفق بود (HTTP {$status}): ".self::cleanError($err));
            return ['url'=>$url,'headers'=>$headers,'status'=>$status];
        }
        throw new MediaQueueException('TOO_MANY_REDIRECTS','تعداد انتقال‌های لینک بیش از حد مجاز است.');
    }

    private static function fetchText(string $url,int $max): array
    {
        for($i=0;$i<6;$i++){
            $headers=[];$body='';$pin=self::validatedPin($url);$ch=curl_init($url);$opts=[CURLOPT_FOLLOWLOCATION=>false,CURLOPT_RETURNTRANSFER=>false,CURLOPT_CONNECTTIMEOUT=>15,CURLOPT_TIMEOUT=>45,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_USERAGENT=>self::userAgent(),CURLOPT_HEADERFUNCTION=>static function($ch,string $line)use(&$headers):int{$len=strlen($line);$p=strpos($line,':');if($p!==false)$headers[strtolower(trim(substr($line,0,$p)))]=trim(substr($line,$p+1));return $len;},CURLOPT_WRITEFUNCTION=>static function($ch,string $chunk)use(&$body,$max):int{if(strlen($body)+strlen($chunk)>$max)return 0;$body.=$chunk;return strlen($chunk);}];if($pin)$opts[CURLOPT_RESOLVE]=[$pin];if(defined('CURLOPT_PROTOCOLS'))$opts[CURLOPT_PROTOCOLS]=CURLPROTO_HTTP|CURLPROTO_HTTPS;curl_setopt_array($ch,$opts);$ok=curl_exec($ch);$err=curl_error($ch);$status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
            if(in_array($status,[301,302,303,307,308],true)&&isset($headers['location'])){$url=self::absoluteUrl($headers['location'],$url);self::validateUrl($url);continue;}
            if($ok===false&&strlen($body)>=$max)throw new MediaQueueException('HTML_TOO_LARGE','صفحه منبع بیش از حد بزرگ است.');
            if($ok===false||$status<200||$status>=300)throw new MediaQueueException('PAGE_HTTP',"دریافت صفحه ناموفق بود (HTTP {$status}): ".self::cleanError($err));
            return ['url'=>$url,'body'=>$body,'headers'=>$headers];
        }
        throw new MediaQueueException('TOO_MANY_REDIRECTS','تعداد انتقال‌های صفحه بیش از حد مجاز است.');
    }

    private static function extractVideoFromHtml(string $html,string $base): ?array
    {
        $candidates=[];$title='';
        if(class_exists('DOMDocument')){
            $dom=new DOMDocument();@$dom->loadHTML($html);$xp=new DOMXPath($dom);
            foreach($xp->query("//meta[@property='og:title' or @name='twitter:title']/@content")?:[] as $node){$title=trim($node->nodeValue);if($title!=='')break;}
            foreach($xp->query("//meta[@property='og:video' or @property='og:video:url' or @property='og:video:secure_url' or @name='twitter:player:stream']/@content | //video/@src | //video/source/@src")?:[] as $node)$candidates[]=trim($node->nodeValue);
        }
        if(!$candidates&&preg_match_all('~(?:og:video(?::url|:secure_url)?|twitter:player:stream)[^>]+content=["\']([^"\']+)~iu',$html,$m))$candidates=$m[1];
        foreach($candidates as $candidate){if($candidate==='')continue;$url=self::absoluteUrl(html_entity_decode($candidate,ENT_QUOTES|ENT_HTML5,'UTF-8'),$base);try{self::validateUrl($url);$head=self::head($url);$mime=strtolower(trim(explode(';',(string)($head['headers']['content-type']??'video/mp4'))[0]));if(str_starts_with($mime,'video/')||self::looksLikeVideoUrl($url))return ['url'=>$head['url'],'title'=>$title?:self::titleFromUrl($url),'mime'=>$mime];}catch(Throwable){continue;}}
        return null;
    }

    private static function absoluteUrl(string $location,string $base): string
    {
        $location=trim($location);if(preg_match('~^https?://~i',$location))return $location;$p=parse_url($base);$scheme=$p['scheme']??'https';$host=$p['host']??'';$port=isset($p['port'])?':'.$p['port']:'';if(str_starts_with($location,'//'))return $scheme.':'.$location;if(str_starts_with($location,'/'))return $scheme.'://'.$host.$port.$location;$dir=rtrim(str_replace('\\','/',dirname($p['path']??'/')),'/');$path=$dir.'/'.$location;$segments=[];foreach(explode('/',$path) as $segment){if($segment===''||$segment==='.')continue;if($segment==='..')array_pop($segments);else $segments[]=$segment;}return $scheme.'://'.$host.$port.'/'.implode('/',$segments);
    }

    private static function ytDlpPath(): ?string
    {
        $configured=trim((string)App::setting('downloader_ytdlp_path',''));$candidates=[];if($configured!==''&&str_starts_with($configured,'/'))$candidates[]=$configured;$candidates=array_merge($candidates,['/usr/local/bin/yt-dlp','/usr/bin/yt-dlp',__DIR__.'/bin/yt-dlp']);foreach($candidates as $path)if(is_file($path)&&is_executable($path))return $path;return null;
    }

    private static function binaryPath(string $setting,array $defaults): ?string
    {
        $configured=trim((string)App::setting($setting,''));$candidates=$configured!==''&&str_starts_with($configured,'/')?[$configured]:[];$candidates=array_merge($candidates,$defaults);foreach(array_unique($candidates) as $path)if(is_file($path)&&is_executable($path))return $path;return null;
    }

    private static function aria2Path(): ?string{return self::binaryPath('downloader_aria2_path',['/usr/bin/aria2c','/usr/local/bin/aria2c']);}
    private static function ffmpegPath(): ?string{return self::binaryPath('downloader_ffmpeg_path',['/usr/bin/ffmpeg','/usr/local/bin/ffmpeg']);}
    private static function ffprobePath(): ?string{$ffmpeg=self::ffmpegPath();$defaults=['/usr/bin/ffprobe','/usr/local/bin/ffprobe'];if($ffmpeg!==null)array_unshift($defaults,dirname($ffmpeg).'/ffprobe');foreach(array_unique($defaults) as $path)if(is_file($path)&&is_executable($path))return $path;return null;}
    private static function mediainfoPath(): ?string{return self::binaryPath('downloader_mediainfo_path',['/usr/bin/mediainfo','/usr/local/bin/mediainfo']);}
    private static function downloadTimeout(): int{return max(300,min(21600,(int)App::setting('media_download_timeout','3600')));}
    private static function uploadTimeout(): int{return max(300,min(21600,(int)App::setting('media_upload_timeout','3600')));}

    private static function parseAriaTotal(string $output): int
    {
        if(!preg_match_all('~/(\d+(?:\.\d+)?(?:KiB|MiB|GiB|B))\(~i',$output,$matches)||empty($matches[1]))return 0;return self::parseSize(end($matches[1])?:'');
    }

    private static function parseSize(string $value): int
    {
        if(!preg_match('/^(\d+(?:\.\d+)?)(KiB|MiB|GiB|B)$/i',trim($value),$m))return 0;$number=(float)$m[1];$unit=strtolower($m[2]);$factor=['b'=>1,'kib'=>1024,'mib'=>1048576,'gib'=>1073741824][$unit]??1;return (int)round($number*$factor);
    }

    private static function telegramVideoMetadata(string $path): array
    {
        $binary=self::ffprobePath();if($binary===null||!self::functionEnabled('proc_open')||!self::isSafeExistingFile($path))return [];
        $pipes=[];$command=[$binary,'-v','error','-select_streams','v:0','-show_entries','stream=width,height,duration:stream_tags=rotate:stream_side_data=rotation:format=duration,format_name','-of','json',$path];
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,null,['bypass_shell'=>true]);if(!is_resource($process))return [];
        fclose($pipes[0]);$json=stream_get_contents($pipes[1],1048576);$error=stream_get_contents($pipes[2],65536);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0||!is_string($json))return [];
        $data=json_decode($json,true);$stream=(array)($data['streams'][0]??[]);$width=(int)($stream['width']??0);$height=(int)($stream['height']??0);$duration=(float)($stream['duration']??($data['format']['duration']??0));$rotation=(int)($stream['tags']['rotate']??0);
        foreach((array)($stream['side_data_list']??[]) as $side)if(isset($side['rotation'])){$rotation=(int)$side['rotation'];break;}
        if(abs($rotation)%180===90)[$width,$height]=[$height,$width];$seconds=(int)ceil($duration);if($width<2||$height<2||$seconds<1)return [];
        return ['width'=>$width,'height'=>$height,'duration'=>$seconds,'rotation'=>$rotation,'format'=>(string)($data['format']['format_name']??'')];
    }

    private static function telegramVideoThumbnail(string $path,array $meta=[]): string
    {
        $target=$path.'.thumb.jpg';
        if(self::isSafeExistingFile($target)){clearstatcache(true,$target);$size=(int)(filesize($target)?:0);if($size>0&&$size<=19500)return $target;}
        $binary=self::ffmpegPath();if($binary===null||!self::functionEnabled('proc_open')||!self::isSafeExistingFile($path))return '';
        $duration=max(1,(int)($meta['duration']??1));$seek=min(8.0,max(.5,$duration*.08));
        foreach([[320,7],[280,9],[240,11],[200,13],[160,15],[128,17]] as [$side,$quality]){
            $tmp=$target.'.'.$side.'.tmp.jpg';@unlink($tmp);$pipes=[];
            $command=[$binary,'-hide_banner','-loglevel','error','-y','-ss',number_format($seek,3,'.',''),'-i',$path,'-frames:v','1','-vf','scale='.$side.':'.$side.':force_original_aspect_ratio=decrease','-q:v',(string)$quality,'-map_metadata','-1',$tmp];
            $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,null,['bypass_shell'=>true]);if(!is_resource($process))continue;
            fclose($pipes[0]);stream_get_contents($pipes[1],65536);stream_get_contents($pipes[2],65536);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);clearstatcache(true,$tmp);
            $size=is_file($tmp)?(int)(filesize($tmp)?:0):0;if($exit===0&&$size>0&&$size<=19500){@rename($tmp,$target);@chmod($target,0640);return self::isSafeExistingFile($target)?$target:'';}@unlink($tmp);
        }
        return '';
    }

    private static function probeMedia(string $path): array
    {
        $binary=self::mediainfoPath();if($binary===null||!self::functionEnabled('proc_open')||!self::isSafeExistingFile($path))return [];
        $pipes=[];$process=proc_open([$binary,'--Output=JSON',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);if(!is_resource($process))return [];
        fclose($pipes[0]);$json=stream_get_contents($pipes[1],2097152);$error=stream_get_contents($pipes[2],4096);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0||!is_string($json))return ['error'=>self::cleanError((string)$error)];
        $data=json_decode($json,true);$tracks=$data['media']['track']??[];$general=is_array($tracks)&&isset($tracks[0])?(array)$tracks[0]:[];
        return array_filter(['format'=>$general['Format']??null,'duration_ms'=>isset($general['Duration'])?(int)$general['Duration']:null,'file_size'=>isset($general['FileSize'])?(int)$general['FileSize']:null],static fn($v):bool=>$v!==null&&$v!=='');
    }

    private static function functionEnabled(string $name): bool{$disabled=array_map('trim',explode(',',(string)ini_get('disable_functions')));return function_exists($name)&&!in_array($name,$disabled,true);}
    private static function drainFinishedPipe(mixed $pipe,int $maxBytes): string
    {
        if(!is_resource($pipe)||$maxBytes<=0)return '';
        stream_set_blocking($pipe,false);$data='';$deadline=microtime(true)+2.0;
        while(strlen($data)<$maxBytes&&microtime(true)<$deadline){
            $chunk=stream_get_contents($pipe,$maxBytes-strlen($data));
            if(is_string($chunk)&&$chunk!==''){$data.=$chunk;continue;}
            if(feof($pipe))break;
            $read=[$pipe];$write=[];$except=[];$remaining=max(0.0,$deadline-microtime(true));$seconds=(int)$remaining;$microseconds=(int)(($remaining-$seconds)*1000000);
            $ready=@stream_select($read,$write,$except,$seconds,$microseconds);if($ready===false)break;
        }
        return $data;
    }
    private static function storageRoot(): string{$path=__DIR__.'/storage/media';if(!is_dir($path)&&!@mkdir($path,0750,true)&&!is_dir($path))throw new MediaQueueException('STORAGE_CREATE','ساخت پوشه ذخیره‌سازی ممکن نیست.');return $path;}
    private static function parallelStorageAdmission(int $expected): array
    {
        if($expected<=0)return ['ok'=>true,'expected'=>0,'active_remaining'=>0,'required'=>0];
        $free=@disk_free_space(self::storageRoot());if($free===false)return ['ok'=>true,'expected'=>$expected,'active_remaining'=>0,'required'=>$expected,'free'=>null];
        $row=App::one("SELECT COALESCE(SUM(GREATEST(CAST(total_bytes AS SIGNED)-CAST(downloaded_bytes AS SIGNED),0)),0) remaining FROM media_jobs WHERE status='downloading' AND lock_expires_at>=NOW() AND total_bytes>0");
        $active=max(0,(int)($row['remaining']??0));$future=$active+$expected;$reserve=max(268435456,(int)ceil($future*.10));$required=$future+$reserve;
        return ['ok'=>$free>=$required,'expected'=>$expected,'active_remaining'=>$active,'reserve'=>$reserve,'required'=>$required,'free'=>(int)$free];
    }
    private static function assertStorageCapacity(int $expected): void{if($expected<=0)return;$free=@disk_free_space(self::storageRoot());$reserve=max(268435456,(int)ceil($expected*.10));if($free!==false&&$free<$expected+$reserve)throw new MediaQueueException('DISK_SPACE','فضای موقت کافی نیست؛ برای این فایل حداقل '.self::humanBytes($expected+$reserve).' فضای خالی لازم است.',60);}
    private static function jobDirectory(int $jobId): string{$path=self::storageRoot().'/job_'.$jobId;if(!is_dir($path)&&!@mkdir($path,0750,true)&&!is_dir($path))throw new MediaQueueException('STORAGE_CREATE','ساخت پوشه موقت لینک ممکن نیست.');return $path;}
    private static function maxBytes(): int{return max(5,min(1024,(int)App::setting('downloader_max_mb','1024')))*1048576;}
    private static function isSafeExistingFile(string $path): bool{if($path===''||!is_file($path))return false;$real=realpath($path);$root=realpath(self::storageRoot());return $real!==false&&$root!==false&&str_starts_with($real,$root.DIRECTORY_SEPARATOR);}
    private static function deleteSafeFile(string $path): void{if($path===''||!is_file($path))return;$real=realpath($path);$root=realpath(self::storageRoot());if($real!==false&&$root!==false&&str_starts_with($real,$root.DIRECTORY_SEPARATOR))@unlink($real);}
    private static function purgeJobFiles(int $jobId,string $path=''): void{$dir=__DIR__.'/storage/media/job_'.$jobId;self::deleteSafeFile($path);if(is_dir($dir)){foreach(glob($dir.'/*')?:[] as $file)self::deleteSafeFile($file);@rmdir($dir);}try{App::q('UPDATE media_jobs SET file_path=NULL WHERE id=?',[$jobId]);}catch(Throwable){}}
    private static function cleanupStorage(): void{$hours=max(1,min(168,(int)App::setting('downloader_temp_hours','24')));foreach(App::all("SELECT id,file_path FROM media_jobs WHERE file_path IS NOT NULL AND updated_at<DATE_SUB(NOW(),INTERVAL {$hours} HOUR) AND status IN ('completed','failed','cancelled')") as $row){self::deleteSafeFile((string)$row['file_path']);App::q('UPDATE media_jobs SET file_path=NULL WHERE id=?',[$row['id']]);}}
    private static function recoverStaleJobs(): void
    {
        foreach(App::all("SELECT id,batch_id,status,file_path FROM media_jobs WHERE status IN ('downloading','uploading') AND lock_expires_at IS NOT NULL AND lock_expires_at<NOW()") as $row){
            $next=$row['status']==='uploading'&&self::isSafeExistingFile((string)($row['file_path']??''))?'downloaded':'queued';$progress=$next==='downloaded'?70:0;if($next==='queued')self::purgeJobFiles((int)$row['id'],(string)($row['file_path']??''));
            App::q("UPDATE media_jobs SET status=?,progress=?,next_attempt_at=NOW(),locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,eta_seconds=NULL,error_code='WORKER_STALE',error_message='Heartbeat پردازش قبلی قطع شده بود.',updated_at=NOW() WHERE id=?",[$next,$progress,$row['id']]);self::event((int)$row['id'],'warning','recovery','Job قفل‌مانده پس از قطع Heartbeat بازیابی و دوباره وارد صف شد.');App::q("UPDATE media_batches SET status='queued',current_item_id=NULL,updated_at=NOW() WHERE id=? AND status='running'",[$row['batch_id']]);
        }
    }
    private static function detectMime(string $path,string $fallback='application/octet-stream'): string{if(function_exists('finfo_open')){$f=finfo_open(FILEINFO_MIME_TYPE);if($f){$mime=finfo_file($f,$path);finfo_close($f);if(is_string($mime)&&$mime!=='')return $mime;}}return $fallback!==''?$fallback:'application/octet-stream';}
    private static function sanitizeFileName(string $name): string{$name=html_entity_decode($name,ENT_QUOTES|ENT_HTML5,'UTF-8');$name=preg_replace('/[\x00-\x1F\x7F\\\/<>:"|?*]+/u','_',trim($name))?:'video';$name=preg_replace('/\s+/u',' ',$name)?:'video';return mb_substr($name,0,180);}
    private static function titleFromUrl(string $url): string{$name=rawurldecode(basename((string)(parse_url($url,PHP_URL_PATH)??'')));return self::sanitizeFileName($name!==''?$name:'video');}
    private static function fileNameFromHeaders(array $headers,string $url): string{if(isset($headers['content-disposition'])&&preg_match('/filename\*?=(?:UTF-8\'\')?["\']?([^"\';]+)/i',$headers['content-disposition'],$m))return self::sanitizeFileName(rawurldecode($m[1]));return self::titleFromUrl($url);}
    private static function looksLikeVideoUrl(string $url): bool{$ext=strtolower(pathinfo((string)parse_url($url,PHP_URL_PATH),PATHINFO_EXTENSION));return in_array($ext,self::VIDEO_EXTENSIONS,true);}
    private static function mimeFromExtension(string $ext): string{return ['mp4'=>'video/mp4','m4v'=>'video/mp4','mov'=>'video/quicktime','webm'=>'video/webm','mkv'=>'video/x-matroska','avi'=>'video/x-msvideo'][$ext]??'application/octet-stream';}
    private static function humanBytes(int $bytes): string{return number_format($bytes/1048576,1).' MB';}
    private static function userAgent(): string{return 'Mozilla/5.0 (compatible; FilmStoreMediaBot/1.2; +'.App::baseUrl().')';}
    private static function cleanError(string $message): string{$message=preg_replace('/bot\d+:[A-Za-z0-9_-]+/','bot[redacted]',$message)??$message;$message=preg_replace('/[\x00-\x1F\x7F]+/u',' ',trim($message))??trim($message);return mb_substr($message,0,1000);}
    private static function lastLines(string $text,int $count): string{$lines=array_values(array_filter(array_map('trim',preg_split('/\R/',$text)?:[])));return implode(' | ',array_slice($lines,-$count));}
}
