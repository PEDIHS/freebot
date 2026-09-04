<?php
declare(strict_types=1);

final class App
{
    private static ?PDO $pdo=null;
    private static array $settings=['media_lock_seconds'=>'180','downloader_temp_hours'=>'24','downloader_max_mb'=>'45'];
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
    public static function baseUrl(): string{return 'https://example.test';}
    public static function token(): string{return 'test-token';}
}

require dirname(__DIR__).'/media.php';

function expect(bool $condition,string $message): void{if(!$condition)throw new RuntimeException($message);}
$pdo=App::db();
foreach(['media_job_events','media_workers','media_jobs','media_batches','products'] as $table)$pdo->exec("DROP TABLE IF EXISTS `$table`");
$pdo->exec("CREATE TABLE products (id int unsigned AUTO_INCREMENT PRIMARY KEY,title varchar(255) NOT NULL,channel_id varchar(64) NOT NULL,enabled tinyint(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE media_batches (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,product_id int unsigned NULL,channel_id varchar(64) NOT NULL,title varchar(255) NOT NULL,caption_template text NULL,upload_mode enum('auto','video','document') NOT NULL DEFAULT 'auto',status enum('queued','running','paused','completed','completed_with_errors','cancelled') NOT NULL DEFAULT 'queued',total_items int unsigned NOT NULL DEFAULT 0,completed_items int unsigned NOT NULL DEFAULT 0,failed_items int unsigned NOT NULL DEFAULT 0,current_item_id bigint unsigned NULL,created_by varchar(64) NOT NULL DEFAULT 'panel',started_at datetime NULL,completed_at datetime NULL,notification_status varchar(30) NULL,notification_sent_at datetime NULL,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE media_jobs (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,batch_id bigint unsigned NOT NULL,position int unsigned NOT NULL,source_url text NOT NULL,source_host varchar(255) NOT NULL DEFAULT '',detected_title varchar(500) NULL,engine varchar(50) NULL,status enum('queued','downloading','downloaded','uploading','completed','failed','cancelled') NOT NULL DEFAULT 'queued',progress decimal(5,2) NOT NULL DEFAULT 0,attempts tinyint unsigned NOT NULL DEFAULT 0,download_attempts tinyint unsigned NOT NULL DEFAULT 0,upload_attempts tinyint unsigned NOT NULL DEFAULT 0,max_attempts tinyint unsigned NOT NULL DEFAULT 3,next_attempt_at datetime NULL,downloaded_bytes bigint unsigned NOT NULL DEFAULT 0,total_bytes bigint unsigned NOT NULL DEFAULT 0,download_speed_bps bigint unsigned NOT NULL DEFAULT 0,upload_speed_bps bigint unsigned NOT NULL DEFAULT 0,eta_seconds int unsigned NULL,file_path varchar(1000) NULL,file_name varchar(500) NULL,mime_type varchar(120) NULL,telegram_message_id bigint NULL,error_code varchar(80) NULL,error_message text NULL,locked_by varchar(190) NULL,lock_token char(64) NULL,lock_expires_at datetime NULL,heartbeat_at datetime NULL,started_at datetime NULL,finished_at datetime NULL,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uniq_media_batch_position(batch_id,position),INDEX idx_media_job_pick(status,next_attempt_at,lock_expires_at,id),CONSTRAINT fk_test_job_batch FOREIGN KEY(batch_id) REFERENCES media_batches(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE media_job_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,job_id bigint unsigned NOT NULL,level enum('info','warning','error','success') NOT NULL DEFAULT 'info',stage varchar(50) NOT NULL,message text NOT NULL,meta longtext NULL,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(job_id,id),CONSTRAINT fk_test_event_job FOREIGN KEY(job_id) REFERENCES media_jobs(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE media_workers (worker_id varchar(190) PRIMARY KEY,role enum('download','upload') NOT NULL,hostname varchar(190) NOT NULL,pid int unsigned NOT NULL,status enum('starting','idle','busy','stopping','stopped','error') NOT NULL DEFAULT 'starting',current_job_id bigint unsigned NULL,jobs_processed bigint unsigned NOT NULL DEFAULT 0,last_error text NULL,started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,heartbeat_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(role,heartbeat_at),CONSTRAINT fk_test_worker_job FOREIGN KEY(current_job_id) REFERENCES media_jobs(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("INSERT INTO products(title,channel_id,enabled) VALUES ('Test product','-100123',1)");

$batchId=MediaQueue::createBatch(1,"https://example.com/a.mp4\nhttps://example.com/b.mp4",'Queue test');
expect((int)App::one('SELECT COUNT(*) c FROM media_jobs WHERE status=\'queued\'')['c']===2,'two jobs must be queued');

$claim=new ReflectionMethod(MediaQueue::class,'claimJob');$claim->setAccessible(true);
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

$statusType=(string)App::one("SELECT COLUMN_TYPE t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='media_jobs' AND COLUMN_NAME='status'")['t'];
foreach(['queued','downloading','downloaded','uploading','completed','failed','cancelled'] as $status)expect(str_contains($statusType,"'{$status}'"),"missing status {$status}");
echo "Media Queue and Worker integration test passed.\n";
