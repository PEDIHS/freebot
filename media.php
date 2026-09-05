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
        App::q("UPDATE media_batches SET status='cancelled',completed_at=NOW(),updated_at=NOW() WHERE id=? AND status NOT IN ('completed','completed_with_errors','cancelled')",[$batchId]);
        App::q("UPDATE media_jobs SET status='cancelled',locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,finished_at=NOW(),updated_at=NOW() WHERE batch_id=? AND status NOT IN ('completed','failed','cancelled')",[$batchId]);
        foreach(App::all('SELECT id,file_path FROM media_jobs WHERE batch_id=?',[$batchId]) as $row)self::purgeJobFiles((int)$row['id'],(string)($row['file_path']??''));
        self::eventForBatch($batchId,'warning','control','دسته توسط مدیر لغو شد.');
        self::syncBatch($batchId);
    }

    public static function retryFailed(int $batchId): int
    {
        $count=(int)(App::one("SELECT COUNT(*) c FROM media_jobs WHERE batch_id=? AND status='failed'",[$batchId])['c']??0);
        if($count===0)return 0;
        App::q("UPDATE media_jobs SET status=IF(file_path IS NULL,'queued','downloaded'),progress=IF(file_path IS NULL,0,70),attempts=0,download_attempts=0,upload_attempts=0,next_attempt_at=NULL,error_code=NULL,error_message=NULL,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,finished_at=NULL,updated_at=NOW() WHERE batch_id=? AND status='failed'",[$batchId]);
        App::q("UPDATE media_batches SET status='queued',failed_items=0,completed_at=NULL,updated_at=NOW() WHERE id=?",[$batchId]);
        self::eventForBatch($batchId,'info','retry',"{$count} مورد برای تلاش مجدد وارد صف شد.");
        return $count;
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
        $result=['role'=>'download','processed'=>0,'downloaded'=>0,'failed'=>0,'retried'=>0];
        self::registerWorker($workerId,'download');self::maintenance();
        for($i=0;$i<$limit;$i++){
            $job=self::claimJob('download',$workerId);if(!$job)break;$result['processed']++;
            try{self::processDownloadJob($job);$result['downloaded']++;self::finishWorkerJob($workerId,true);}
            catch(Throwable $e){$retry=self::failJob($job,$e,'download');$retry?$result['retried']++:$result['failed']++;self::finishWorkerJob($workerId,false,$e->getMessage());}
            self::syncBatch((int)$job['batch_id']);
        }
        self::heartbeatWorker($workerId,'idle');return $result;
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
        // Keep the lease operation self-contained: every claimant must have a
        // heartbeat row, including workers that find an empty queue.
        self::registerWorker($workerId,$role);
        $status=$role==='download'?'queued':'downloaded';$lease=self::lockSeconds();
        for($attempt=0;$attempt<10;$attempt++){
            $candidate=App::one("SELECT j.id FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id WHERE j.status=? AND b.status IN ('queued','running') AND (j.next_attempt_at IS NULL OR j.next_attempt_at<=NOW()) AND (j.lock_expires_at IS NULL OR j.lock_expires_at<NOW()) ORDER BY b.id,j.position,j.id LIMIT 1",[$status]);
            if(!$candidate)return null;$token=bin2hex(random_bytes(32));$target=$role==='download'?'downloading':'uploading';$attemptColumn=$role==='download'?'download_attempts':'upload_attempts';
            $claimed=App::q("UPDATE media_jobs SET status=?,progress=IF(?='download',GREATEST(progress,1),GREATEST(progress,72)),attempts=attempts+1,{$attemptColumn}={$attemptColumn}+1,locked_by=?,lock_token=?,lock_expires_at=DATE_ADD(NOW(),INTERVAL {$lease} SECOND),heartbeat_at=NOW(),started_at=COALESCE(started_at,NOW()),next_attempt_at=NULL,error_code=NULL,error_message=NULL,updated_at=NOW() WHERE id=? AND status=? AND (lock_expires_at IS NULL OR lock_expires_at<NOW())",[$target,$role,$workerId,$token,$candidate['id'],$status])->rowCount();
            if($claimed!==1)continue;
            App::q("UPDATE media_batches b JOIN media_jobs j ON j.batch_id=b.id SET b.status='running',b.current_item_id=j.id,b.started_at=COALESCE(b.started_at,NOW()),b.updated_at=NOW() WHERE j.id=?",[$candidate['id']]);
            self::heartbeatWorker($workerId,'busy',(int)$candidate['id']);
            return App::one("SELECT j.*,b.product_id,b.channel_id,b.upload_mode,b.total_items,b.status batch_status,b.title batch_title,p.title product_title FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id LEFT JOIN products p ON p.id=b.product_id WHERE j.id=?",[$candidate['id']]);
        }
        return null;
    }

    private static function processDownloadJob(array $job): void
    {
        $jobId=(int)$job['id'];self::event($jobId,'info','resolve','شناسایی لینک و موتور دانلود آغاز شد.',['url_host'=>$job['source_host'],'worker'=>$job['locked_by']]);
        self::assertLease($job);
        $resolved=self::resolveSource((string)$job['source_url']);
        App::q('UPDATE media_jobs SET engine=?,detected_title=?,mime_type=?,updated_at=NOW() WHERE id=? AND lock_token=?',[$resolved['engine'],$resolved['title']?:null,$resolved['mime']?:null,$jobId,$job['lock_token']]);
        self::event($jobId,'info','resolve','موتور دانلود انتخاب شد.',['engine'=>$resolved['engine'],'title'=>$resolved['title']]);
        $file=$resolved['engine']==='yt-dlp'?self::downloadWithYtDlp($job,$resolved):(self::aria2Path()!==null?self::downloadWithAria2($job,$resolved):self::downloadDirect($job,$resolved));
        $probe=self::probeMedia($file['path']);if($probe!==[])self::event($jobId,'info','mediainfo','مشخصات فایل با MediaInfo بررسی شد.',$probe);
        self::assertLease($job);
        App::q("UPDATE media_jobs SET status='downloaded',file_path=?,file_name=?,mime_type=?,downloaded_bytes=?,total_bytes=?,progress=70,eta_seconds=NULL,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,heartbeat_at=NOW(),error_code=NULL,error_message=NULL,updated_at=NOW() WHERE id=? AND lock_token=?",[$file['path'],$file['name'],$file['mime'],$file['size'],$file['size'],$jobId,$job['lock_token']]);
        self::event($jobId,'success','downloaded','دانلود کامل شد و فایل وارد صف آپلود شد.',['file_size'=>$file['size']]);
    }

    private static function processUploadJob(array $job): void
    {
        $jobId=(int)$job['id'];$path=(string)($job['file_path']??'');self::assertLease($job);
        if(!self::isSafeExistingFile($path))throw new MediaQueueException('UPLOAD_FILE_MISSING','فایل آماده آپلود پیدا نشد.');
        self::event($jobId,'info','upload','آپلود بدون کپشن در کانال مقصد آغاز شد.',['channel_id'=>$job['channel_id'],'worker'=>$job['locked_by']]);
        $message=self::uploadToTelegram($job,$path);
        self::assertLease($job);$messageId=(int)($message['message_id']??0);
        App::q("UPDATE media_jobs SET status='completed',progress=100,telegram_message_id=?,eta_seconds=NULL,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,heartbeat_at=NOW(),finished_at=NOW(),error_code=NULL,error_message=NULL,updated_at=NOW() WHERE id=? AND lock_token=?",[$messageId,$jobId,$job['lock_token']]);
        if($messageId>0)App::trackChannelPost($message,'downloader');
        self::event($jobId,'success','complete','دانلود و آپلود بدون کپشن با موفقیت کامل شد.',['message_id'=>$messageId,'file_size'=>filesize($path)?:0]);
        self::purgeJobFiles($jobId,$path);
    }

    private static function normaliseWorkerId(string $workerId,string $role): string
    {
        $workerId=trim($workerId);if($workerId==='')$workerId=$role.'-'.(gethostname()?:'localhost').'-'.getmypid();
        return mb_substr(preg_replace('/[^a-zA-Z0-9_.:@-]+/','-', $workerId)?:($role.'-worker'),0,190);
    }

    private static function lockSeconds(): int{return max(60,min(900,(int)App::setting('media_lock_seconds','180')));}

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
        $mime=self::detectMime($path,(string)($job['mime_type']??''));$mode=(string)$job['upload_mode'];
        $method=$mode==='document'?'sendDocument':(($mode==='video'||str_starts_with($mime,'video/'))?'sendVideo':'sendDocument');
        try{return self::telegramFileRequest($method,(string)$job['channel_id'],$path,$mime,$job);}
        catch(MediaQueueException $e){if($method==='sendVideo'&&in_array($e->errorCode,['TELEGRAM_API','TELEGRAM_FORMAT'],true)){self::event((int)$job['id'],'warning','upload','ارسال به‌صورت ویدیو پذیرفته نشد؛ تلاش به‌صورت فایل و بدون کپشن انجام می‌شود.');return self::telegramFileRequest('sendDocument',(string)$job['channel_id'],$path,$mime,$job);}throw $e;}
    }

    private static function telegramFileRequest(string $method,string $chatId,string $path,string $mime,array $job): array
    {
        $jobId=(int)$job['id'];$field=$method==='sendVideo'?'video':'document';$data=['chat_id'=>$chatId,$field=>new CURLFile($path,$mime,basename($path))];
        if($method==='sendVideo')$data['supports_streaming']='true';
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
        $permanent=in_array($code,['INVALID_URL','PRIVATE_URL','UNSUPPORTED_SCHEME','SIZE_LIMIT','ENGINE_MISSING','UNSUPPORTED_SOURCE','PROC_OPEN_DISABLED','TELEGRAM_FORMAT'],true);
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
        $batch=App::one('SELECT status,title,channel_id,notification_status FROM media_batches WHERE id=?',[$batchId]);if(!$batch)return;$status=(string)$batch['status'];
        if($status!=='cancelled'&&$status!=='paused'){
            if($pending===0)$status=$failed>0||$cancelled>0?'completed_with_errors':'completed';
            elseif($status!=='running')$status='queued';
        }
        $complete=in_array($status,['completed','completed_with_errors','cancelled'],true);
        App::q('UPDATE media_batches SET status=?,total_items=?,completed_items=?,failed_items=?,current_item_id=NULL,completed_at='.($complete?'COALESCE(completed_at,NOW())':'NULL').',updated_at=NOW() WHERE id=?',[$status,$total,$done,$failed,$batchId]);
        if($complete&&(string)($batch['notification_status']??'')!==$status){
            $icon=$status==='completed'?'✅':($status==='cancelled'?'⛔️':'⚠️');
            App::sendLog($icon.' <b>پایان دسته دانلود #'.$batchId.'</b>'."\nعنوان: ".App::h($batch['title'])."\nکانال: <code>".App::h($batch['channel_id'])."</code>\nموفق: <b>{$done}</b> | خطا: <b>{$failed}</b> | کل: <b>{$total}</b>");
            App::q('UPDATE media_batches SET notification_status=?,notification_sent_at=NOW() WHERE id=?',[$status,$batchId]);
        }
    }

    public static function summary(): array
    {
        $jobs=App::one("SELECT COUNT(*) total,SUM(status='queued') queued,SUM(status IN ('downloading','uploading')) active,SUM(next_attempt_at>NOW() AND status IN ('queued','downloaded')) retry_wait,SUM(status='downloaded') downloaded,SUM(status='completed') done,SUM(status='failed') failed FROM media_jobs")??[];
        $jobs['batches']=(int)(App::one("SELECT COUNT(*) c FROM media_batches WHERE status IN ('queued','running','paused')")['c']??0);
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

    public static function historyScannerStatus(): array
    {
        $python='/opt/freebot-tools/bin/python';
        $script=__DIR__.'/scripts/channel_history_scan.py';
        $config='/etc/freebot/channel-scanner.env';
        $session='/var/lib/freebot-mtproto/freebot.session';
        $ready=is_executable($python)&&is_file($script)&&is_readable($config)&&is_readable($session)&&self::functionEnabled('proc_open');
        return ['ready'=>$ready,'python'=>$python,'script'=>$script,'config'=>$config,'session'=>$session];
    }

    public static function scanChannelHistory(int $productId): array
    {
        $product=App::one('SELECT id,title,channel_id FROM products WHERE id=?',[$productId]);
        if(!$product)throw new RuntimeException('محصول یا کانال پیدا نشد.');
        $scanner=self::historyScannerStatus();
        if(!$scanner['ready'])throw new RuntimeException('اسکنر تاریخچه هنوز راه‌اندازی نشده است؛ ابتدا setup-channel-scanner.sh را روی سرور اجرا کنید.');
        // One Telethon session is shared by all products, so serialize scans to
        // avoid concurrent writes to the access-restricted session database.
        $lockName='freebot-channel-history';
        $locked=(int)(App::one('SELECT GET_LOCK(?,0) acquired',[$lockName])['acquired']??0)===1;
        if(!$locked)throw new RuntimeException('اسکن تاریخچه یک کانال دیگر هم‌اکنون در حال اجراست.');
        try{
            App::q("INSERT INTO channel_stats(product_id,channel_id,history_scan_status,history_scan_error) VALUES (?,?,'running',NULL) ON DUPLICATE KEY UPDATE channel_id=VALUES(channel_id),history_scan_status='running',history_scan_error=NULL",[$productId,$product['channel_id']]);
            $command=[$scanner['python'],$scanner['script'],'--channel',(string)$product['channel_id'],'--config',$scanner['config'],'--session','/var/lib/freebot-mtproto/freebot'];
            $pipes=[];$process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);
            if(!is_resource($process))throw new RuntimeException('اجرای پردازش اسکن تاریخچه ممکن نشد.');
            fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
            $stdout='';$stderr='';$started=microtime(true);$timeout=max(300,min(21600,(int)App::setting('channel_history_scan_timeout','7200')));$exitCode=null;
            while(true){
                $stdout.=stream_get_contents($pipes[1])?:'';$stderr.=stream_get_contents($pipes[2])?:'';
                $processStatus=proc_get_status($process);
                if(!$processStatus['running']){$exitCode=(int)$processStatus['exitcode'];break;}
                if(microtime(true)-$started>$timeout){proc_terminate($process,15);usleep(500000);proc_terminate($process,9);$exitCode=124;break;}
                usleep(100000);
            }
            $stdout.=stream_get_contents($pipes[1])?:'';$stderr.=stream_get_contents($pipes[2])?:'';
            fclose($pipes[1]);fclose($pipes[2]);$closedCode=proc_close($process);if($exitCode===null||$exitCode<0)$exitCode=$closedCode;
            $lines=array_values(array_filter(array_map('trim',preg_split('/\R/',$stdout)?:[])));$payload=$lines?json_decode((string)end($lines),true):null;
            if($exitCode!==0||!is_array($payload)||!($payload['ok']??false)){
                $detail=is_array($payload)?(string)($payload['error']??''):'';
                if($detail==='')$detail=trim($stderr)?:'اسکنر پاسخ معتبر نداد.';
                throw new RuntimeException(self::cleanError($detail));
            }
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

    public static function recentBatches(int $limit=30): array{return App::all('SELECT b.*,p.title product_title FROM media_batches b LEFT JOIN products p ON p.id=b.product_id ORDER BY b.id DESC LIMIT '.max(1,min(100,$limit)));}
    public static function recentJobs(int $limit=100): array{return App::all('SELECT j.*,b.title batch_title,b.channel_id,p.title product_title FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id LEFT JOIN products p ON p.id=b.product_id ORDER BY j.id DESC LIMIT '.max(1,min(500,$limit)));}
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

    private static function probeMedia(string $path): array
    {
        $binary=self::mediainfoPath();if($binary===null||!self::functionEnabled('proc_open')||!self::isSafeExistingFile($path))return [];
        $pipes=[];$process=proc_open([$binary,'--Output=JSON',$path],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__);if(!is_resource($process))return [];
        fclose($pipes[0]);$json=stream_get_contents($pipes[1],2097152);$error=stream_get_contents($pipes[2],4096);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0||!is_string($json))return ['error'=>self::cleanError((string)$error)];
        $data=json_decode($json,true);$tracks=$data['media']['track']??[];$general=is_array($tracks)&&isset($tracks[0])?(array)$tracks[0]:[];
        return array_filter(['format'=>$general['Format']??null,'duration_ms'=>isset($general['Duration'])?(int)$general['Duration']:null,'file_size'=>isset($general['FileSize'])?(int)$general['FileSize']:null],static fn($v):bool=>$v!==null&&$v!=='');
    }

    private static function functionEnabled(string $name): bool{$disabled=array_map('trim',explode(',',(string)ini_get('disable_functions')));return function_exists($name)&&!in_array($name,$disabled,true);}
    private static function storageRoot(): string{$path=__DIR__.'/storage/media';if(!is_dir($path)&&!@mkdir($path,0750,true)&&!is_dir($path))throw new MediaQueueException('STORAGE_CREATE','ساخت پوشه ذخیره‌سازی ممکن نیست.');return $path;}
    private static function jobDirectory(int $jobId): string{$path=self::storageRoot().'/job_'.$jobId;if(!is_dir($path)&&!@mkdir($path,0750,true)&&!is_dir($path))throw new MediaQueueException('STORAGE_CREATE','ساخت پوشه موقت لینک ممکن نیست.');return $path;}
    private static function maxBytes(): int{return max(5,min(1900,(int)App::setting('downloader_max_mb','45')))*1048576;}
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
