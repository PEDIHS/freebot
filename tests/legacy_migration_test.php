<?php
declare(strict_types=1);

require dirname(__DIR__).'/app.php';

function migrationExpect(bool $condition,string $message): void
{
    if(!$condition)throw new RuntimeException($message);
}

$dsn=getenv('FREEBOT_TEST_DSN')?:'mysql:host=127.0.0.1;port=3306;dbname=freebot_test;charset=utf8mb4';
$pdo=new PDO($dsn,getenv('FREEBOT_TEST_USER')?:'root',getenv('FREEBOT_TEST_PASS')?:'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$tables=['media_workers','media_job_events','channel_stats','channel_posts','invite_link_events','media_jobs','media_batches','webhook_updates','users','orders','products','settings'];
foreach($tables as $table)$pdo->exec("DROP TABLE IF EXISTS `{$table}`");

$pdo->exec("CREATE TABLE settings (`key` varchar(100) PRIMARY KEY,`value` longtext NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE products (id int unsigned AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE orders (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,product_id int unsigned NULL,invite_link varchar(1000) NULL,paid_at datetime NULL,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE users (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,telegram_id varchar(32) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE webhook_updates (update_id varchar(32) PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE media_batches (id bigint unsigned AUTO_INCREMENT PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE media_jobs (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,batch_id bigint unsigned NULL,position int unsigned NOT NULL DEFAULT 0,source_url text NULL,status enum('queued','resolving','done','failed') NOT NULL DEFAULT 'queued',progress decimal(5,2) NOT NULL DEFAULT 0,attempts tinyint unsigned NOT NULL DEFAULT 0,max_attempts tinyint unsigned NOT NULL DEFAULT 3,next_attempt_at datetime NULL,downloaded_bytes bigint unsigned NOT NULL DEFAULT 0,total_bytes bigint unsigned NOT NULL DEFAULT 0,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TABLE media_workers (worker_id varchar(190) PRIMARY KEY) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("INSERT INTO media_batches(id) VALUES (1)");
$pdo->exec("INSERT INTO media_jobs(batch_id,position,source_url,status) VALUES (1,1,'https://example.test/movie.mp4','done'),(1,2,NULL,'resolving')");
$pdo->exec("INSERT INTO media_workers(worker_id) VALUES ('legacy-worker')");

$coreMigration=new ReflectionMethod(App::class,'ensureCoreSchema');
$coreMigration->setAccessible(true);
$coreMigration->invoke(null,$pdo);
$mediaMigration=new ReflectionMethod(App::class,'ensureMediaSchema');
$mediaMigration->setAccessible(true);
$mediaMigration->invoke(null,$pdo);

migrationExpect((bool)$pdo->query("SHOW COLUMNS FROM webhook_updates LIKE 'created_at'")->fetch(),'webhook_updates.created_at must be repaired');
foreach(['username','first_name','last_name','balance','blocked','state','state_data','inline_menu_ready','created_at','last_seen_at'] as $column)migrationExpect((bool)$pdo->query("SHOW COLUMNS FROM users LIKE ".$pdo->quote($column))->fetch(),"missing migrated users.{$column}");

$requiredJobColumns=['source_host','detected_title','engine','download_attempts','upload_attempts','download_speed_bps','upload_speed_bps','eta_seconds','file_path','file_name','mime_type','telegram_message_id','error_code','error_message','locked_by','lock_token','lock_expires_at','heartbeat_at','started_at','finished_at'];
foreach($requiredJobColumns as $column)migrationExpect((bool)$pdo->query("SHOW COLUMNS FROM media_jobs LIKE ".$pdo->quote($column))->fetch(),"missing migrated media_jobs.{$column}");
$requiredWorkerColumns=['role','hostname','pid','status','current_job_id','jobs_processed','last_error','started_at','heartbeat_at','updated_at'];
foreach($requiredWorkerColumns as $column)migrationExpect((bool)$pdo->query("SHOW COLUMNS FROM media_workers LIKE ".$pdo->quote($column))->fetch(),"missing migrated media_workers.{$column}");
$requiredHistoryColumns=['history_last_message_id','history_message_count','history_video_count','history_photo_count','history_file_count','history_total_bytes','history_scan_status','history_scan_error','history_scanned_at'];
foreach($requiredHistoryColumns as $column)migrationExpect((bool)$pdo->query("SHOW COLUMNS FROM channel_stats LIKE ".$pdo->quote($column))->fetch(),"missing migrated channel_stats.{$column}");
$statuses=$pdo->query("SELECT position,status,error_code FROM media_jobs ORDER BY position")->fetchAll(PDO::FETCH_ASSOC);
migrationExpect($statuses[0]['status']==='completed','legacy done job must become completed');
migrationExpect($statuses[1]['status']==='failed'&&$statuses[1]['error_code']==='LEGACY_ROW','incomplete legacy job must be quarantined');
$statusType=(string)$pdo->query("SHOW COLUMNS FROM media_jobs LIKE 'status'")->fetch(PDO::FETCH_ASSOC)['Type'];
migrationExpect($statusType==="enum('queued','downloading','downloaded','uploading','completed','failed','cancelled')",'status enum must be normalized');

foreach($tables as $table)$pdo->exec("DROP TABLE IF EXISTS `{$table}`");
echo "Legacy Media schema migration test passed.\n";
