<?php
declare(strict_types=1);

final class App
{
    private static ?PDO $pdo=null;
    private static array $settings=['media_lock_seconds'=>'180','downloader_temp_hours'=>'24','downloader_max_mb'=>'1024','telegram_mtproto_upload'=>'1'];
    public static function db(): PDO
    {
        if(self::$pdo)return self::$pdo;$dsn=getenv('FREEBOT_TEST_DSN')?:'mysql:host=127.0.0.1;dbname=freebot_test;charset=utf8mb4';
        return self::$pdo=new PDO($dsn,getenv('FREEBOT_TEST_USER')?:'root',getenv('FREEBOT_TEST_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
    }
    public static function q(string $sql,array $params=[]): PDOStatement{$s=self::db()->prepare($sql);$s->execute($params);return $s;}
    public static function one(string $sql,array $params=[]): ?array{$r=self::q($sql,$params)->fetch(PDO::FETCH_ASSOC);return $r===false?null:$r;}
    public static function all(string $sql,array $params=[]): array{return self::q($sql,$params)->fetchAll(PDO::FETCH_ASSOC);}
    public static function setting(string $key,string $default=''): string{return self::$settings[$key]??$default;}
    public static function telegram(string $method,array $data=[]): mixed{return $method==='getMe'?['id'=>1]:($method==='getChatMember'?['status'=>'creator']:[]);}
    public static function logEvent(string $type,string $message,array $meta=[]): void{}
    public static function sendLog(string $message): void{}
    public static function trackChannelPost(array $message,string $source): void{}
    public static function j(mixed $value): string{return json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
    public static function h(mixed $value): string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function baseUrl(): string{return 'https://example.test';}
    public static function token(): string{return 'test-token';}
}

require dirname(__DIR__).'/media.php';

function expect(bool $condition,string $message): void{if(!$condition)throw new RuntimeException($message);}
$pipePair=stream_socket_pair(STREAM_PF_UNIX,STREAM_SOCK_STREAM,STREAM_IPPROTO_IP);expect(is_array($pipePair),'test pipe pair must be available');fwrite($pipePair[1],'{"ok":true}');fclose($pipePair[1]);$drain=new ReflectionMethod(MediaQueue::class,'drainFinishedPipe');$drain->setAccessible(true);expect($drain->invoke(null,$pipePair[0],1024)==='{"ok":true}','finished process output must be drained completely');fclose($pipePair[0]);
$maxBytes=new ReflectionMethod(MediaQueue::class,'maxBytes');$maxBytes->setAccessible(true);expect($maxBytes->invoke(null)===1024*1024*1024,'media hard limit must be exactly one GiB');
$pdo=App::db();
foreach(['media_job_events','media_workers','media_jobs','media_batches','products'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE products (id int unsigned AUTO_INCREMENT PRIMARY KEY,title varchar(255) NOT NULL,channel_id varchar(64) NOT NULL,enabled tinyint(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE media_batches (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,product_id int unsigned NULL,channel_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,title varchar(255) NOT NULL,caption_template text NULL,upload_mode enum('auto','video','document') NOT NULL DEFAULT 'auto',status enum('queued','running','paused','completed','completed_with_errors','cancelled') NOT NULL DEFAULT 'queued',total_items int unsigned NOT NULL DEFAULT 0,completed_items int unsigned NOT NULL DEFAULT 0,failed_items int unsigned NOT NULL DEFAULT 0,current_item_id bigint unsigned NULL,created_by varchar(64) NOT NULL DEFAULT 'panel',started_at datetime NULL,completed_at datetime NULL,notification_status varchar(30) NULL,notification_sent_at datetime NULL,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("ALTER TABLE media_batches ADD COLUMN source_type varchar(30) NOT NULL DEFAULT 'links',ADD COLUMN source_channel_id varchar(64) NULL,ADD COLUMN source_channel_title varchar(255) NULL,ADD COLUMN source_last_message_id bigint unsigned NOT NULL DEFAULT 0,ADD COLUMN source_scanned_items bigint unsigned NOT NULL DEFAULT 0,ADD COLUMN source_video_count bigint unsigned NOT NULL DEFAULT 0,ADD COLUMN sequential_mode tinyint(1) NOT NULL DEFAULT 0,ADD COLUMN pipeline_depth tinyint unsigned NOT NULL DEFAULT 1,ADD COLUMN distribution_mode varchar(20) NOT NULL DEFAULT 'single',ADD COLUMN destination_channels_json longtext NULL,ADD COLUMN destination_limit int unsigned NOT NULL DEFAULT 0,ADD COLUMN overflow_items int unsigned NOT NULL DEFAULT 0,ADD COLUMN scan_status varchar(20) NOT NULL DEFAULT 'completed',ADD COLUMN scan_attempts tinyint unsigned NOT NULL DEFAULT 0,ADD COLUMN scan_max_attempts tinyint unsigned NOT NULL DEFAULT 3,ADD COLUMN scan_next_attempt_at datetime NULL,ADD COLUMN scan_error text NULL,ADD COLUMN scan_locked_by varchar(190) NULL,ADD COLUMN scan_lock_token char(64) NULL,ADD COLUMN scan_lock_expires_at datetime NULL,ADD COLUMN scan_heartbeat_at datetime NULL,ADD COLUMN scan_options_json longtext NULL");
$pdo->exec("CREATE TABLE media_jobs (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,batch_id bigint unsigned NOT NULL,position int unsigned NOT NULL,source_url text NOT NULL,source_host varchar(255) NOT NULL DEFAULT '',detected_title varchar(500) NULL,engine varchar(50) NULL,status enum('queued','downloading','downloaded','uploading','completed','failed','cancelled') NOT NULL DEFAULT 'queued',progress decimal(5,2) NOT NULL DEFAULT 0,attempts tinyint unsigned NOT NULL DEFAULT 0,download_attempts tinyint unsigned NOT NULL DEFAULT 0,upload_attempts tinyint unsigned NOT NULL DEFAULT 0,max_attempts tinyint unsigned NOT NULL DEFAULT 3,next_attempt_at datetime NULL,downloaded_bytes bigint unsigned NOT NULL DEFAULT 0,total_bytes bigint unsigned NOT NULL DEFAULT 0,download_speed_bps bigint unsigned NOT NULL DEFAULT 0,upload_speed_bps bigint unsigned NOT NULL DEFAULT 0,eta_seconds int unsigned NULL,file_path varchar(1000) NULL,file_name varchar(500) NULL,mime_type varchar(120) NULL,source_chat_id varchar(64) NULL,source_message_id bigint NULL,source_date datetime NULL,target_channel_id varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NULL,target_slot tinyint unsigned NULL,target_sequence int unsigned NULL,telegram_message_id bigint NULL,error_code varchar(80) NULL,error_message text NULL,locked_by varchar(190) NULL,lock_token char(64) NULL,lock_expires_at datetime NULL,heartbeat_at datetime NULL,started_at datetime NULL,finished_at datetime NULL,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uniq_media_batch_position(batch_id,position),INDEX idx_media_job_pick(status,next_attempt_at,lock_expires_at,id),INDEX idx_media_target(target_channel_id,status),CONSTRAINT fk_test_job_batch FOREIGN KEY(batch_id) REFERENCES media_batches(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE media_job_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,job_id bigint unsigned NOT NULL,level enum('info','warning','error','success') NOT NULL DEFAULT 'info',stage varchar(50) NOT NULL,message text NOT NULL,meta longtext NULL,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(job_id,id),CONSTRAINT fk_test_event_job FOREIGN KEY(job_id) REFERENCES media_jobs(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE media_workers (worker_id varchar(190) PRIMARY KEY,role enum('download','upload') NOT NULL,hostname varchar(190) NOT NULL,pid int unsigned NOT NULL,status enum('starting','idle','busy','stopping','stopped','error') NOT NULL DEFAULT 'starting',current_job_id bigint unsigned NULL,jobs_processed bigint unsigned NOT NULL DEFAULT 0,last_error text NULL,started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,heartbeat_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(role,heartbeat_at),CONSTRAINT fk_test_worker_job FOREIGN KEY(current_job_id) REFERENCES media_jobs(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("INSERT INTO products(title,channel_id,enabled) VALUES ('Test product','-100123',1)");

$batchId=MediaQueue::createBatch(1,"https://example.com/a.mp4\nhttps://example.com/b.mp4",'Queue test');
expect((int)App::one('SELECT COUNT(*) c FROM media_jobs WHERE status=\'queued\'')['c']===2,'two jobs must be queued');

$claim=new ReflectionMethod(MediaQueue::class,'claimJob');$claim->setAccessible(true);
$claimScan=new ReflectionMethod(MediaQueue::class,'claimChannelScan');$claimScan->setAccessible(true);
$failScan=new ReflectionMethod(MediaQueue::class,'failChannelScan');$failScan->setAccessible(true);
$route=new ReflectionMethod(MediaQueue::class,'destinationForPosition');$route->setAccessible(true);
$channels=['-10011111','-10022222','-10033333'];
expect($route->invoke(null,$channels,2000,1)===['channel_id'=>'-10011111','slot'=>1,'sequence'=>1],'first video route must be stable');
expect($route->invoke(null,$channels,2000,2000)===['channel_id'=>'-10011111','slot'=>1,'sequence'=>2000],'first destination boundary must be inclusive');
expect($route->invoke(null,$channels,2000,2001)===['channel_id'=>'-10022222','slot'=>2,'sequence'=>1],'second destination must start at 2001');
expect($route->invoke(null,$channels,2000,4000)===['channel_id'=>'-10022222','slot'=>2,'sequence'=>2000],'second destination boundary must be inclusive');
expect($route->invoke(null,$channels,2000,4001)===['channel_id'=>'-10033333','slot'=>3,'sequence'=>1],'third destination must start at 4001');
expect($route->invoke(null,$channels,2000,6001)===null,'overflow must not silently lose videos');
$editableBatch=MediaQueue::createBatch(1,"https://example.com/e1.mp4\nhttps://example.com/e2.mp4\nhttps://example.com/e3.mp4",'Old routing');
App::q("UPDATE media_batches SET source_type='telegram_channel',source_channel_id='-100999',source_last_message_id=333,source_scanned_items=3,source_video_count=10,distribution_mode='chunked',destination_channels_json=?,destination_limit=1,scan_status='failed',scan_attempts=3,scan_max_attempts=3,scan_error='capacity',status='completed_with_errors' WHERE id=?",[App::j(['-100123']),$editableBatch]);
MediaQueue::updateTelegramChannelBatch($editableBatch,'Expanded routing','-100456','-100789',2,5);
$edited=App::one('SELECT title,status,scan_status,scan_attempts,scan_max_attempts,destination_limit,source_last_message_id FROM media_batches WHERE id=?',[$editableBatch]);
expect($edited['title']==='Expanded routing'&&$edited['status']==='queued'&&$edited['scan_status']==='queued'&&(int)$edited['scan_attempts']===0,'editing a failed scan must requeue it without losing its batch');
expect((int)$edited['destination_limit']===2&&(int)$edited['scan_max_attempts']===5&&(int)$edited['source_last_message_id']===333,'capacity, retry and checkpoint must persist after editing');
$editedRoutes=App::all('SELECT position,target_channel_id,target_slot,target_sequence FROM media_jobs WHERE batch_id=? ORDER BY position',[$editableBatch]);
expect(count($editedRoutes)===3&&$editedRoutes[0]['target_channel_id']==='-100123'&&$editedRoutes[1]['target_channel_id']==='-100123'&&$editedRoutes[2]['target_channel_id']==='-100456','existing queued jobs must be rerouted in blocks without deletion');
MediaQueue::cancelBatch($editableBatch);
App::q("INSERT INTO media_batches(product_id,channel_id,title,source_type,source_channel_id,sequential_mode,pipeline_depth,distribution_mode,destination_channels_json,destination_limit,scan_status,scan_max_attempts,scan_next_attempt_at,status,created_by) VALUES (1,'-100123','Async scan','telegram_channel','-100999',1,2,'single',?,0,'queued',3,NOW(),'queued','test')",[App::j(['-100123'])]);
$scanBatchId=(int)App::db()->lastInsertId();$scanClaim=$claimScan->invoke(null,'scan-worker-1');
expect(is_array($scanClaim)&&(int)$scanClaim['id']===$scanBatchId,'download worker must claim queued channel scan');
expect(strlen((string)$scanClaim['scan_lock_token'])===64,'channel scan claim must create a lock token');
expect($claimScan->invoke(null,'scan-worker-2')===null,'scan lease must prevent duplicate claim');
App::q("INSERT INTO media_jobs(batch_id,position,source_url,source_host,engine,status,max_attempts,source_chat_id,source_message_id,created_at,updated_at) VALUES (?,1,'tgmtproto://channel/-100999/321','telegram-mtproto','telegram-mtproto','queued',3,'-100999',321,NOW(),NOW())",[$scanBatchId]);
App::q('UPDATE media_batches SET source_last_message_id=321,source_scanned_items=1,total_items=1 WHERE id=?',[$scanBatchId]);
expect($failScan->invoke(null,$scanClaim,new RuntimeException('temporary scanner failure'))===true,'interrupted scan must be retryable');
expect((int)App::one('SELECT COUNT(*) c FROM media_jobs WHERE batch_id=?',[$scanBatchId])['c']===1,'interrupted scan must preserve checkpoint jobs');
$checkpoint=App::one('SELECT scan_status,source_last_message_id,source_scanned_items FROM media_batches WHERE id=?',[$scanBatchId]);
expect($checkpoint['scan_status']==='queued'&&(int)$checkpoint['source_last_message_id']===321&&(int)$checkpoint['source_scanned_items']===1,'scan checkpoint must survive interruption');
App::q("UPDATE media_batches SET scan_status='failed',scan_error='test',scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,status='completed_with_errors' WHERE id=?",[$scanBatchId]);
expect(MediaQueue::retryFailed($scanBatchId)===1,'failed channel scan must be retryable without browser');
expect(App::one('SELECT scan_status,status FROM media_batches WHERE id=?',[$scanBatchId])['scan_status']==='queued','retry must persist channel scan in queue');
MediaQueue::cancelBatch($scanBatchId);
expect(App::one('SELECT scan_status FROM media_batches WHERE id=?',[$scanBatchId])['scan_status']==='cancelled','cancel must stop queued channel scan');
App::q("INSERT INTO media_batches(product_id,channel_id,title,source_type,source_channel_id,scan_status,scan_attempts,scan_max_attempts,scan_next_attempt_at,status,created_by) VALUES (1,'-100123','Stuck legacy scan','telegram_channel','-100998','queued',3,3,NOW(),'queued','test')");
$stuckBatchId=(int)App::db()->lastInsertId();
MediaQueue::maintenance();
$recovered=App::one('SELECT scan_status,scan_attempts,status,scan_error FROM media_batches WHERE id=?',[$stuckBatchId]);
expect($recovered['scan_status']==='queued'&&(int)$recovered['scan_attempts']===2&&$recovered['status']==='queued','legacy queued scan at its retry ceiling must be recovered automatically');
expect(str_contains((string)$recovered['scan_error'],'Checkpoint'),'recovered scan must explain checkpoint continuation');
MediaQueue::cancelBatch($stuckBatchId);
$first=$claim->invoke(null,'download','test-download-1');$second=$claim->invoke(null,'download','test-download-2');$third=$claim->invoke(null,'download','test-download-3');
expect(is_array($first)&&is_array($second),'two workers must claim jobs');
expect($first['id']!==$second['id'],'lease must prevent duplicate claims');
expect($third===null,'no third download job must exist');
expect(strlen((string)$first['lock_token'])===64,'claim must create a lock token');

App::q("UPDATE media_jobs SET status='downloaded',progress=70,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL WHERE id=?",[$first['id']]);
$upload=$claim->invoke(null,'upload','test-upload-1');
expect((int)$upload['id']===(int)$first['id'],'upload worker must claim downloaded job');
MediaQueue::cancelJob((int)$second['id']);
expect(App::one('SELECT status FROM media_jobs WHERE id=?',[$second['id']])['status']==='cancelled','cancel must persist');
MediaQueue::retryJob((int)$second['id']);
expect(App::one('SELECT status FROM media_jobs WHERE id=?',[$second['id']])['status']==='queued','retry must return cancelled job to queue');
expect(count(MediaQueue::activeWorkers())>=3,'worker heartbeat rows must be registered');
expect(count(MediaQueue::jobEvents((int)$second['id']))>=2,'job events must be stored');

App::q("UPDATE media_jobs SET status='completed',locked_by=NULL,lock_token=NULL,lock_expires_at=NULL");
$sequentialBatch=MediaQueue::createBatch(1,"https://example.com/first.mp4\nhttps://example.com/second.mp4",'Sequential queue');
App::q('UPDATE media_batches SET sequential_mode=1 WHERE id=?',[$sequentialBatch]);
$sequentialFirst=$claim->invoke(null,'download','sequential-download-1');
expect(is_array($sequentialFirst),'first sequential job must be claimable');
expect($claim->invoke(null,'download','sequential-download-2')===null,'second sequential download must wait for the first upload');
App::q("UPDATE media_jobs SET status='downloaded',progress=70,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL WHERE id=?",[$sequentialFirst['id']]);
$sequentialUpload=$claim->invoke(null,'upload','sequential-upload-1');
expect((int)$sequentialUpload['id']===(int)$sequentialFirst['id'],'first sequential upload must be claimed');
expect($claim->invoke(null,'download','sequential-download-3')===null,'next download must stay blocked while prior upload is active');
App::q("UPDATE media_jobs SET status='completed',progress=100,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL WHERE id=?",[$sequentialFirst['id']]);
$sequentialSecond=$claim->invoke(null,'download','sequential-download-4');
expect(is_array($sequentialSecond)&&(int)$sequentialSecond['position']===2,'second sequential job must start after the first completes');

App::q("UPDATE media_jobs SET status='completed',locked_by=NULL,lock_token=NULL,lock_expires_at=NULL");
$pipelineBatch=MediaQueue::createBatch(1,"https://example.com/p1.mp4\nhttps://example.com/p2.mp4\nhttps://example.com/p3.mp4",'Pipeline queue');
App::q('UPDATE media_batches SET source_type=\'telegram_channel\',sequential_mode=1,pipeline_depth=4,distribution_mode=\'chunked\',destination_channels_json=?,destination_limit=2 WHERE id=?',[App::j(['-100123','-100456']),$pipelineBatch]);
App::q("UPDATE media_jobs SET engine='telegram-mtproto',target_channel_id=IF(position<=2,'-100123','-100456'),target_slot=IF(position<=2,1,2),target_sequence=IF(position<=2,position,position-2) WHERE batch_id=?",[$pipelineBatch]);
$pipelineFirst=$claim->invoke(null,'download','pipeline-download-1');
expect(is_array($pipelineFirst)&&(int)$pipelineFirst['position']===1,'pipeline first video must be claimable');
App::q("UPDATE media_jobs SET status='downloaded',progress=70,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL WHERE id=?",[$pipelineFirst['id']]);
$pipelineSecond=$claim->invoke(null,'download','pipeline-download-2');
expect($pipelineSecond===null,'Telegram relay must never download a second file before the first upload completes');
$pipelineUploadOne=$claim->invoke(null,'upload','pipeline-upload-1');
expect(is_array($pipelineUploadOne)&&(int)$pipelineUploadOne['position']===1,'first destination upload must start in order');
App::q("UPDATE media_jobs SET status='completed',progress=100,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL WHERE id=?",[$pipelineUploadOne['id']]);
$pipelineSecond=$claim->invoke(null,'download','pipeline-download-3');
expect(is_array($pipelineSecond)&&(int)$pipelineSecond['position']===2,'next Telegram download must start only after successful upload');
App::q("UPDATE media_jobs SET status='downloaded',progress=70,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL WHERE id=?",[$pipelineSecond['id']]);
$pipelineUploadSecond=$claim->invoke(null,'upload','pipeline-upload-2');
expect(is_array($pipelineUploadSecond)&&(int)$pipelineUploadSecond['position']===2,'second upload must follow the first in strict relay order');
App::q("UPDATE media_jobs SET status='completed',progress=100,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL WHERE id=?",[$pipelineUploadSecond['id']]);
App::q("UPDATE media_jobs SET status='downloaded',progress=70 WHERE batch_id=? AND position=3",[$pipelineBatch]);
$pipelineUploadTwo=$claim->invoke(null,'upload','pipeline-upload-3');
expect(is_array($pipelineUploadTwo)&&(int)$pipelineUploadTwo['position']===3,'destination routing must remain stable');
expect($claim->invoke(null,'upload','pipeline-upload-4')===null,'same destination must preserve video order');
$stats=MediaQueue::batchDestinationStats($pipelineBatch);
expect(count($stats)===2&&(int)$stats[0]['total']===2&&(int)$stats[1]['total']===1,'destination statistics must remain separate');
$persisted=App::one('SELECT target_channel_id,target_slot,target_sequence FROM media_jobs WHERE batch_id=? AND position=3',[$pipelineBatch]);
expect($persisted['target_channel_id']==='-100456'&&(int)$persisted['target_slot']===2&&(int)$persisted['target_sequence']===1,'destination assignment must persist in the job row');

$statusType=(string)App::one("SELECT COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_jobs' AND COLUMN_NAME='status'")['t'];
foreach(['queued','downloading','downloaded','uploading','completed','failed','cancelled'] as $status)expect(str_contains($statusType,"'{$status}'"),"missing status {$status}");
echo "Media Queue and Worker integration test passed.\n";
