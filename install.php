<?php
declare(strict_types=1);

function requestIsHttps():bool{
    if(isset($_SERVER['HTTPS'])&&strtolower((string)$_SERVER['HTTPS'])!=='off'&&(string)$_SERVER['HTTPS']!=='')return true;
    if((string)($_SERVER['SERVER_PORT']??'')==='443')return true;
    $forwarded=strtolower(trim(explode(',',(string)($_SERVER['HTTP_X_FORWARDED_PROTO']??''))[0]??''));
    return $forwarded==='https';
}
function publicBaseUrl():string{
    $host=trim((string)($_SERVER['HTTP_HOST']??$_SERVER['SERVER_NAME']??''));
    if($host===''||!preg_match('/^(?:\[[0-9a-f:]+\]|[a-z0-9.-]+)(?::\d{1,5})?$/i',$host))throw new RuntimeException('دامنه درخواست قابل تشخیص نیست. اینستالر را مستقیماً با آدرس دامنه باز کنید.');
    $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??'/install.php'));
    $dir=trim(str_replace('\\','/',dirname($script)),'/');
    $parts=$dir===''?[]:array_values(array_filter(explode('/',$dir),static fn(string $part):bool=>$part!==''&&$part!=='.'&&$part!=='..'));
    $path=$parts?'/'.implode('/',array_map(static fn(string $part):string=>rawurlencode(rawurldecode($part)),$parts)):'';
    return 'https://'.$host.$path;
}
function localUrl(string $file):string{
    $script=str_replace('\\','/',(string)($_SERVER['SCRIPT_NAME']??'/install.php'));
    $dir=rtrim(str_replace('\\','/',dirname($script)),'/');
    return ($dir===''?'':$dir).'/'.ltrim($file,'/');
}
if (is_file(__DIR__.'/config.php')) { header('Location: '.localUrl('admin.php'),true,302); exit; }
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

$https=requestIsHttps();
$detectedBase='';$detectError='';
try{$detectedBase=publicBaseUrl();}catch(Throwable $e){$detectError=$e->getMessage();}
$requiredFiles=['app.php','smart-payment.php','payment-webhook.php','media.php','webhook.php','admin.php','cron.php','worker.php','index.php'];
$missingFiles=array_values(array_filter($requiredFiles,static fn(string $file):bool=>!is_file(__DIR__.'/'.$file)));
$requiredExtensions=['pdo_mysql','curl','openssl','mbstring','json'];
$missingExtensions=array_values(array_filter($requiredExtensions,static fn(string $ext):bool=>!extension_loaded($ext)));
$checks=[
    ['label'=>'نسخه PHP 8.1 یا بالاتر','ok'=>version_compare(PHP_VERSION,'8.1.0','>=')],
    ['label'=>'افزونه‌های ضروری PHP','ok'=>$missingExtensions===[]],
    ['label'=>'کامل بودن فایل‌های ربات','ok'=>$missingFiles===[]],
    ['label'=>'دسترسی نوشتن در پوشه ربات','ok'=>is_writable(__DIR__)],
    ['label'=>'اتصال امن HTTPS','ok'=>$https],
    ['label'=>'تشخیص خودکار مسیر عمومی','ok'=>$detectedBase!==''],
];
$ready=!in_array(false,array_column($checks,'ok'),true);
$errors=[];$ok=false;$bot=[];$webhookUrl=$detectedBase===''?'':$detectedBase.'/webhook.php';$configCreated=false;$configTemp='';
function e(string $v):string{return htmlspecialchars($v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function posted(string $key,string $default=''):string{$value=$_POST[$key]??$default;return is_string($value)?$value:$default;}
function tgCall(string $token,string $method,array $data=[]):array{
    $ch=curl_init('https://api.telegram.org/bot'.$token.'/'.$method);
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$data,CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>30,CURLOPT_SSL_VERIFYPEER=>true]);
    $body=curl_exec($ch);$err=curl_error($ch);curl_close($ch);
    if($body===false)throw new RuntimeException('اتصال به تلگرام ناموفق بود: '.$err);
    $j=json_decode($body,true);
    if(!is_array($j)||!($j['ok']??false)){
        $description=(string)($j['description']??'پاسخ نامعتبر تلگرام');
        if(stripos($description,'not found')!==false)$description='توکن ربات معتبر نیست یا ربات در تلگرام پیدا نشد.';
        elseif(stripos($description,'unauthorized')!==false)$description='توکن ربات نادرست است یا از BotFather غیرفعال شده.';
        elseif(stripos($description,'bad webhook')!==false)$description='تلگرام آدرس Webhook را نپذیرفت. SSL دامنه و دسترسی عمومی مسیر ربات را بررسی کنید.';
        throw new RuntimeException($description);
    }
    return $j;
}
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        if(!$ready)throw new RuntimeException('پیش‌نیازهای نصب کامل نیست. موارد قرمز را برطرف و صفحه را تازه‌سازی کنید.');
        $dbHost=trim(posted('db_host','localhost'));$dbName=trim(posted('db_name'));$dbUser=trim(posted('db_user'));$dbPass=posted('db_pass');
        $base=$detectedBase;$token=trim(posted('bot_token'));$owner=trim(posted('owner_id'));
        $panelUser=trim(posted('panel_user','admin'));$panelPass=posted('panel_pass');
        if($dbName===''||$dbUser===''||$token===''||$owner===''||$panelPass==='')throw new RuntimeException('همه فیلدهای ضروری را کامل کنید.');
        if(!preg_match('/^\d+$/',$owner))throw new RuntimeException('آیدی عددی مدیر نامعتبر است.');
        if(strlen($panelPass)<8)throw new RuntimeException('رمز پنل حداقل ۸ کاراکتر باشد.');
        $me=tgCall($token,'getMe');$bot=$me['result'];
        $pdo=new PDO("mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",$dbUser,$dbPass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
        $schema=<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (`key` varchar(100) PRIMARY KEY,`value` longtext NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS admins (id int unsigned AUTO_INCREMENT PRIMARY KEY,telegram_id varchar(32) NOT NULL UNIQUE,enabled tinyint(1) NOT NULL DEFAULT 1,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS users (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,telegram_id varchar(32) NOT NULL UNIQUE,username varchar(100) NULL,first_name varchar(255) NOT NULL DEFAULT '',last_name varchar(255) NOT NULL DEFAULT '',balance decimal(18,0) NOT NULL DEFAULT 0,referrer_user_id bigint unsigned NULL,referral_percent_override decimal(8,2) NULL,referral_fixed_override decimal(18,0) NULL,blocked tinyint(1) NOT NULL DEFAULT 0,state varchar(100) NULL,state_data longtext NULL,inline_menu_ready tinyint(1) NOT NULL DEFAULT 1,created_at datetime NOT NULL,last_seen_at datetime NOT NULL,INDEX(referrer_user_id),CONSTRAINT fk_user_ref FOREIGN KEY(referrer_user_id) REFERENCES users(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS forced_channels (id int unsigned AUTO_INCREMENT PRIMARY KEY,chat_id varchar(64) NOT NULL,title varchar(255) NOT NULL,join_url varchar(500) NOT NULL,enabled tinyint(1) NOT NULL DEFAULT 1,sort_order int NOT NULL DEFAULT 100) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS cards (id int unsigned AUTO_INCREMENT PRIMARY KEY,label varchar(255) NOT NULL,holder_name varchar(255) NULL,card_number varchar(64) NOT NULL,enabled tinyint(1) NOT NULL DEFAULT 1,sort_order int NOT NULL DEFAULT 100) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS categories (id int unsigned AUTO_INCREMENT PRIMARY KEY,title varchar(255) NOT NULL,description text NULL,enabled tinyint(1) NOT NULL DEFAULT 1,sort_order int NOT NULL DEFAULT 100) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS products (id int unsigned AUTO_INCREMENT PRIMARY KEY,category_id int unsigned NOT NULL,title varchar(255) NOT NULL,description text NOT NULL,price decimal(18,0) NOT NULL,channel_id varchar(64) NOT NULL,image_url varchar(1000) NULL,enabled tinyint(1) NOT NULL DEFAULT 1,sort_order int NOT NULL DEFAULT 100,invite_expire_hours int NOT NULL DEFAULT 168,invite_max_uses int NOT NULL DEFAULT 2,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(category_id),CONSTRAINT fk_product_cat FOREIGN KEY(category_id) REFERENCES categories(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS orders (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,user_id bigint unsigned NOT NULL,product_id int unsigned NOT NULL,amount decimal(18,0) NOT NULL,status enum('creating','paid','failed','cancelled') NOT NULL,invite_link varchar(1000) NULL,invite_expire_at datetime NULL,invite_uses int NOT NULL DEFAULT 0,max_invite_uses int NOT NULL DEFAULT 2,invite_revoked tinyint(1) NOT NULL DEFAULT 0,failure_reason text NULL,created_at datetime NOT NULL,paid_at datetime NULL,INDEX(user_id),INDEX(product_id),INDEX(status),CONSTRAINT fk_order_user FOREIGN KEY(user_id) REFERENCES users(id),CONSTRAINT fk_order_product FOREIGN KEY(product_id) REFERENCES products(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS invite_uses (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,order_id bigint unsigned NOT NULL,user_telegram_id varchar(32) NOT NULL,approved_at datetime NOT NULL,UNIQUE KEY uniq_order_user(order_id,user_telegram_id),CONSTRAINT fk_invite_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS topups (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,user_id bigint unsigned NOT NULL,amount decimal(18,0) NOT NULL,status enum('waiting_receipt','pending','approved','rejected','cancelled') NOT NULL,desired_product_id int unsigned NULL,receipt_file_id varchar(500) NULL,receipt_type varchar(30) NULL,receipt_caption text NULL,reason text NULL,reviewed_by varchar(32) NULL,created_at datetime NOT NULL,reviewed_at datetime NULL,INDEX(user_id),INDEX(status),CONSTRAINT fk_topup_user FOREIGN KEY(user_id) REFERENCES users(id),CONSTRAINT fk_topup_product FOREIGN KEY(desired_product_id) REFERENCES products(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS wallet_transactions (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,user_id bigint unsigned NOT NULL,type varchar(30) NOT NULL,amount decimal(18,0) NOT NULL,reference_type varchar(30) NULL,reference_id bigint NULL,description varchar(255) NULL,created_at datetime NOT NULL,INDEX(user_id),INDEX(type),CONSTRAINT fk_wallet_user FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS tickets (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,user_id bigint unsigned NOT NULL,status enum('open','closed') NOT NULL DEFAULT 'open',created_at datetime NOT NULL,updated_at datetime NOT NULL,INDEX(user_id),INDEX(status),CONSTRAINT fk_ticket_user FOREIGN KEY(user_id) REFERENCES users(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS ticket_messages (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,ticket_id bigint unsigned NOT NULL,sender_type enum('user','admin') NOT NULL,message_type varchar(30) NOT NULL,text text NULL,file_id varchar(500) NULL,created_at datetime NOT NULL,INDEX(ticket_id),CONSTRAINT fk_ticket_msg FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS menus (id int unsigned AUTO_INCREMENT PRIMARY KEY,`key` varchar(100) NOT NULL UNIQUE,label varchar(255) NOT NULL,style varchar(20) NOT NULL DEFAULT '',action_type varchar(50) NOT NULL,action_value text NULL,row_no int NOT NULL DEFAULT 1,sort_order int NOT NULL DEFAULT 100,enabled tinyint(1) NOT NULL DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS button_styles (button_key varchar(120) PRIMARY KEY,label varchar(255) NOT NULL,group_title varchar(255) NOT NULL,style varchar(20) NOT NULL DEFAULT '',sort_order int NOT NULL DEFAULT 100) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS texts (id int unsigned AUTO_INCREMENT PRIMARY KEY,`key` varchar(100) NOT NULL UNIQUE,title varchar(255) NOT NULL,`value` longtext NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS logs (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,`type` varchar(80) NOT NULL,`message` text NOT NULL,meta longtext NULL,created_at datetime NOT NULL,INDEX(type),INDEX(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS webhook_updates (update_id varchar(32) PRIMARY KEY,created_at datetime NOT NULL,INDEX(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS media_batches (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,product_id int unsigned NULL,channel_id varchar(64) NOT NULL,title varchar(255) NOT NULL,caption_template text NULL,upload_mode enum('auto','video','document') NOT NULL DEFAULT 'auto',status enum('queued','running','paused','completed','completed_with_errors','cancelled') NOT NULL DEFAULT 'queued',total_items int unsigned NOT NULL DEFAULT 0,completed_items int unsigned NOT NULL DEFAULT 0,failed_items int unsigned NOT NULL DEFAULT 0,current_item_id bigint unsigned NULL,created_by varchar(64) NOT NULL DEFAULT 'panel',started_at datetime NULL,completed_at datetime NULL,notification_status varchar(30) NULL,notification_sent_at datetime NULL,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX(status),INDEX(product_id),CONSTRAINT fk_media_batch_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS media_jobs (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,batch_id bigint unsigned NOT NULL,position int unsigned NOT NULL,source_url text NOT NULL,source_host varchar(255) NOT NULL DEFAULT '',detected_title varchar(500) NULL,engine varchar(50) NULL,status enum('queued','downloading','downloaded','uploading','completed','failed','cancelled') NOT NULL DEFAULT 'queued',progress decimal(5,2) NOT NULL DEFAULT 0,attempts tinyint unsigned NOT NULL DEFAULT 0,download_attempts tinyint unsigned NOT NULL DEFAULT 0,upload_attempts tinyint unsigned NOT NULL DEFAULT 0,max_attempts tinyint unsigned NOT NULL DEFAULT 3,next_attempt_at datetime NULL,downloaded_bytes bigint unsigned NOT NULL DEFAULT 0,total_bytes bigint unsigned NOT NULL DEFAULT 0,download_speed_bps bigint unsigned NOT NULL DEFAULT 0,upload_speed_bps bigint unsigned NOT NULL DEFAULT 0,eta_seconds int unsigned NULL,file_path varchar(1000) NULL,file_name varchar(500) NULL,mime_type varchar(120) NULL,telegram_message_id bigint NULL,error_code varchar(80) NULL,error_message text NULL,locked_by varchar(190) NULL,lock_token char(64) NULL,lock_expires_at datetime NULL,heartbeat_at datetime NULL,started_at datetime NULL,finished_at datetime NULL,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uniq_media_batch_position(batch_id,position),INDEX idx_media_job_pick(status,next_attempt_at,lock_expires_at,id),CONSTRAINT fk_media_job_batch FOREIGN KEY(batch_id) REFERENCES media_batches(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS media_job_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,job_id bigint unsigned NOT NULL,level enum('info','warning','error','success') NOT NULL DEFAULT 'info',stage varchar(50) NOT NULL,message text NOT NULL,meta longtext NULL,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,INDEX(job_id,id),CONSTRAINT fk_media_event_job FOREIGN KEY(job_id) REFERENCES media_jobs(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS media_workers (worker_id varchar(190) PRIMARY KEY,role enum('download','upload') NOT NULL,hostname varchar(190) NOT NULL,pid int unsigned NOT NULL,status enum('starting','idle','busy','stopping','stopped','error') NOT NULL DEFAULT 'starting',current_job_id bigint unsigned NULL,jobs_processed bigint unsigned NOT NULL DEFAULT 0,last_error text NULL,started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,heartbeat_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,INDEX idx_media_worker_live(role,heartbeat_at),CONSTRAINT fk_media_worker_job FOREIGN KEY(current_job_id) REFERENCES media_jobs(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS channel_posts (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,chat_id varchar(64) NOT NULL,message_id bigint NOT NULL,media_type varchar(30) NOT NULL DEFAULT 'text',file_id varchar(500) NULL,file_size bigint unsigned NOT NULL DEFAULT 0,source varchar(30) NOT NULL DEFAULT 'telegram_update',posted_at datetime NOT NULL,updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uniq_channel_message(chat_id,message_id),INDEX idx_channel_post_type(chat_id,media_type)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS channel_stats (product_id int unsigned PRIMARY KEY,channel_id varchar(64) NOT NULL,channel_title varchar(255) NULL,channel_username varchar(255) NULL,member_count int unsigned NOT NULL DEFAULT 0,admin_count int unsigned NOT NULL DEFAULT 0,bot_status varchar(30) NULL,can_post tinyint(1) NOT NULL DEFAULT 0,last_error text NULL,refreshed_at datetime NULL,CONSTRAINT fk_channel_stats_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS invite_link_events (id bigint unsigned AUTO_INCREMENT PRIMARY KEY,product_id int unsigned NULL,order_id bigint unsigned NULL,link_hash char(64) NOT NULL,created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uniq_invite_link_hash(link_hash),INDEX(product_id),CONSTRAINT fk_invite_event_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL,CONSTRAINT fk_invite_event_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE SET NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL;
        foreach(array_filter(array_map('trim',preg_split('/;\s*(?:\r?\n|$)/',$schema))) as $sql)$pdo->exec($sql);
        $appKey=bin2hex(random_bytes(32));$secret=bin2hex(random_bytes(24));
        $config="<?php\nreturn ".var_export(['db'=>['host'=>$dbHost,'name'=>$dbName,'user'=>$dbUser,'pass'=>$dbPass],'app_key'=>$appKey],true).";\n";
        $configTemp=__DIR__.'/.config.'.bin2hex(random_bytes(6)).'.tmp';
        if(file_put_contents($configTemp,$config,LOCK_EX)===false)throw new RuntimeException('امکان ساخت فایل تنظیمات وجود ندارد؛ دسترسی نوشتن پوشه را بررسی کنید.');
        @chmod($configTemp,0640);
        if(is_file(__DIR__.'/config.php'))throw new RuntimeException('ربات هم‌زمان توسط درخواست دیگری نصب شد؛ صفحه را تازه‌سازی کنید.');
        if(!@rename($configTemp,__DIR__.'/config.php'))throw new RuntimeException('ثبت نهایی config.php ناموفق بود؛ سطح دسترسی پوشه را بررسی کنید.');
        $configCreated=true;$configTemp='';
        require_once __DIR__.'/app.php';
        SmartPayment::ensureSchema($pdo);
        $settings=[
            'bot_token'=>App::encrypt($token),'bot_username'=>$bot['username'],'base_url'=>$base,'webhook_secret'=>$secret,
            'panel_username'=>$panelUser,'panel_password_hash'=>password_hash($panelPass,PASSWORD_DEFAULT),'currency'=>'تومان',
            'log_chat_id'=>'','log_chat_url'=>'','proof_chat_id'=>'','proof_url'=>'','sample_movie_url'=>'','referral_percent'=>'5','referral_fixed'=>'0','min_topup'=>'10000',
            'topup_presets'=>'50000,100000,200000,500000,1000000','downloader_max_mb'=>'45','downloader_temp_hours'=>'24',
            'downloader_ytdlp_path'=>'','downloader_aria2_path'=>'','downloader_ffmpeg_path'=>'','downloader_mediainfo_path'=>'',
            'downloader_batch_limit'=>'100','media_download_timeout'=>'3600','media_upload_timeout'=>'3600','media_lock_seconds'=>'180',
            'media_download_connections'=>'8','media_fragment_concurrency'=>'8','schema_version'=>'2.0.0-media-engine'
        ];
        $st=$pdo->prepare('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)');foreach($settings as $k=>$v)$st->execute([$k,(string)$v]);
        $pdo->prepare('INSERT INTO admins (telegram_id,enabled,created_at) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE enabled=1')->execute([$owner]);
        $menus=[
            ['buy','🛒 خرید فیلم','success','shop','',1,10],
            ['wallet','💳 کیف پول + شارژ','success','wallet','',1,20],
            ['packs','📦 پک‌های من','primary','my_packs','',2,10],
            ['proof','📊 گروه گزارشات','primary','proof','',3,10],
            ['sample_movie','🎬 نمونه فیلم','primary','sample_movie','',4,10],
            ['referral','👥 زیرمجموعه‌گیری','primary','referral','',5,10],['support','💬 پشتیبانی','primary','support','',5,20]
        ];
        $ms=$pdo->prepare('INSERT INTO menus (`key`,label,style,action_type,action_value,row_no,sort_order,enabled) VALUES (?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE label=VALUES(label),style=VALUES(style),action_type=VALUES(action_type),action_value=VALUES(action_value),row_no=VALUES(row_no),sort_order=VALUES(sort_order)');foreach($menus as $m)$ms->execute($m);
        $texts=[
            ['welcome','خوش‌آمدگویی','سلام {name} عزیز 👋\n\nبه فروشگاه فیلم خوش آمدی.\nموجودی کیف پول: <b>{balance}</b>'],
            ['forced_join','عضویت اجباری','برای استفاده از ربات ابتدا در کانال‌های زیر عضو شو و سپس «بررسی عضویت» را بزن.'],
            ['select_category','انتخاب دسته‌بندی','📁 دسته‌بندی موردنظر را انتخاب کن:'],
            ['no_categories','بدون دسته‌بندی','در حال حاضر دسته‌بندی فعالی وجود ندارد.'],
            ['select_product','انتخاب محصول','محصول موردنظر از دسته <b>{category}</b> را انتخاب کن:'],
            ['product_detail','جزئیات محصول','🎬 <b>{title}</b>\nدسته: {category}\n\n{description}\n\nقیمت: <b>{price}</b>'],
            ['low_balance','کمبود موجودی','موجودی شما: <b>{balance}</b>\nقیمت محصول: <b>{price}</b>\nمبلغ موردنیاز برای افزایش موجودی: <b>{shortage}</b>'],
            ['wallet','کیف پول','💰 موجودی کیف پول شما: <b>{balance}</b>'],
            ['wallet_profile','پروفایل کیف پول','👤 <b>کیف پول من</b>\n\nنام: <b>{name}</b>\nآیدی عددی: <code>{user_id}</code>\nتاریخ عضویت: {joined_at}\nتعداد خرید موفق: <b>{orders}</b>\nتعداد زیرمجموعه: <b>{referrals}</b>\nموجودی کیف پول: <b>{balance}</b>'],
            ['topup_choose_amount','انتخاب مبلغ شارژ','💳 <b>افزایش موجودی</b>\n\nیکی از مبلغ‌های پیشنهادی را انتخاب کن یا «مبلغ دلخواه» را بزن.\nحداقل مبلغ: <b>{min}</b>'],
            ['topup_amount_prompt','درخواست مبلغ','مبلغ موردنظر برای افزایش موجودی را فقط عددی بفرست.\nحداقل مبلغ: {min}'],
            ['invoice','فاکتور','🧾 <b>فاکتور پرداخت</b>\nمبلغ: <b>{amount}</b>\nبابت: <b>{product}</b>\n\nمبلغ را به یکی از کارت‌های زیر واریز کن:{cards}\n\nبعد از پرداخت دکمه ارسال رسید را بزن.'],
            ['receipt_prompt','درخواست رسید','حالا تصویر رسید پرداخت را ارسال کن. فقط عکس یا فایل رسید پذیرفته می‌شود.'],
            ['receipt_invalid','رسید نامعتبر','باید تصویر یا فایل رسید را ارسال کنی؛ پیام متنی قابل قبول نیست.'],
            ['receipt_sent','رسید ارسال شد','✅ رسید شما برای مدیر ارسال شد. نتیجه بررسی از همین ربات اطلاع داده می‌شود.'],
            ['topup_approved','تایید شارژ','✅ افزایش موجودی به مبلغ {amount} تایید شد.\nموجودی فعلی: <b>{balance}</b>'],
            ['topup_rejected','رد رسید','❌ رسید شما رد شد.\nدلیل: {reason}'],
            ['blocked_by_admin','بلاک پس از رسید','🚫 حساب شما مسدود شد.\nدلیل: {reason}'],
            ['order_success','خرید موفق','✅ خرید با موفقیت انجام شد.\nمحصول: <b>{product}</b>\nمبلغ: {amount}\nشماره سفارش: <code>#{order_id}</code>\n\nروی دکمه زیر بزن؛ ربات درخواست عضویت را خودکار تایید می‌کند.'],
            ['my_packs_empty','پک خالی','هنوز فیلم یا پکی خریداری نکرده‌ای. از فروشگاه دیدن کن و اولین خریدت را انجام بده 🎬'],
            ['proof_button','متن گروه گزارشات','📊 برای مشاهده گزارش‌ها وارد گروه یا کانال زیر شو:'],
            ['proof_purchase','گزارش عمومی خرید','✅ <b>خرید موفق جدید</b>\nکاربر: {name}\nآیدی عددی: <code>{user_id}</code>\nمحصول: <b>{product}</b>\nمبلغ: {amount}\nتاریخ: {date}\nسفارش: #{order_id}'],
            ['referral_info','اطلاعات زیرمجموعه','🎁 <b>از معرفی دوستانت درآمد بگیر!</b>\n\nلینک اختصاصی تو:\n<code>{link}</code>\n\n👥 تعداد دوستان دعوت‌شده: <b>{count}</b>\n💰 درآمد زیرمجموعه‌ها: <b>{earned}</b>\n📈 سهم هر خرید: <b>{percent}%</b> + <b>{fixed}</b>\n\nلینکت را برای دوستانت بفرست؛ بعد از خرید موفق آن‌ها، پورسانت به کیف پولت اضافه می‌شود.'],
            ['referral_commission','پورسانت','🎉 بابت خرید زیرمجموعه، {amount} پورسانت به کیف پولت اضافه شد.\nسفارش: #{order_id}'],
            ['support_prompt','شروع پشتیبانی','پیام، عکس یا فایل خود را برای پشتیبانی ارسال کن.'],
            ['support_sent','ارسال پشتیبانی','✅ پیام شما برای پشتیبانی ارسال شد.'],
            ['blocked','کاربر مسدود','حساب شما توسط مدیریت مسدود شده است.'],
            ['user_blocked_notice','اعلان مسدودی','🚫 حساب شما توسط مدیریت مسدود شد. برای پیگیری با پشتیبانی تماس بگیرید.'],
            ['user_unblocked_notice','اعلان رفع مسدودی','✅ محدودیت حساب شما برداشته شد و دوباره می‌توانید از ربات استفاده کنید.'],
            ['wallet_adjust_notice','اعلان تغییر موجودی','💰 موجودی کیف پول شما توسط مدیریت {action} یافت.\nمبلغ تغییر: <b>{amount}</b>\nموجودی فعلی: <b>{balance}</b>'],
            ['unknown','پیام ناشناخته','لطفاً از دکمه‌های منوی ربات استفاده کن.']
        ];
        $texts=[];foreach(App::defaultTexts() as $key=>$row)$texts[]=[$key,$row[0],$row[1]];
        $ts=$pdo->prepare('INSERT INTO texts (`key`,title,`value`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),`value`=VALUES(`value`)');foreach($texts as $t)$ts->execute($t);
        $webhookUrl=$base.'/webhook.php';
        tgCall($token,'setWebhook',['url'=>$webhookUrl,'secret_token'=>$secret,'drop_pending_updates'=>'true','allowed_updates'=>json_encode(['message','callback_query','chat_join_request','channel_post','edited_channel_post'])]);
        $webhookInfo=tgCall($token,'getWebhookInfo');
        $registeredUrl=(string)($webhookInfo['result']['url']??'');
        if($registeredUrl!==$webhookUrl)throw new RuntimeException('Webhook روی مسیر تشخیص‌داده‌شده ثبت نشد. SSL و تنظیمات دامنه را بررسی کنید.');
        tgCall($token,'setMyCommands',['commands'=>json_encode([
            ['command'=>'start','description'=>'شروع ربات'],['command'=>'shop','description'=>'خرید فیلم'],
            ['command'=>'wallet','description'=>'کیف پول من و افزایش موجودی'],['command'=>'packs','description'=>'پک‌های من'],
            ['command'=>'support','description'=>'پشتیبانی']
        ],JSON_UNESCAPED_UNICODE)]);
        $ok=true;
    }catch(Throwable $e){
        $message=trim($e->getMessage());
        if($message==='Not Found'||stripos($message,'404 Not Found')!==false)$message='مسیر ربات پیدا نشد. اینستالر اکنون مسیر را خودکار می‌سازد؛ SSL و آدرس دامنه را بررسی کنید.';
        $errors[]=$message!==''?$message:'نصب با خطای ناشناخته متوقف شد.';
        if($configTemp!==''&&is_file($configTemp))@unlink($configTemp);
        if($configCreated&&is_file(__DIR__.'/config.php'))@unlink(__DIR__.'/config.php');
    }
}
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>نصب هوشمند ربات فروشگاه فیلم</title>
    <style>
        :root{color-scheme:light;--primary:#2563eb;--primary2:#1d4ed8;--ink:#172033;--muted:#64748b;--line:#dbe3ef;--bg:#f2f5fa;--ok:#14804a;--okbg:#eaf8f0;--bad:#b42318;--badbg:#fff0ef;--warn:#8a5b00;--warnbg:#fff8df}
        *{box-sizing:border-box}body{font-family:tahoma,Arial,sans-serif;background:linear-gradient(145deg,#eef4ff,#f7f9fc 50%,#eef2f7);margin:0;color:var(--ink);min-height:100vh;padding:28px 14px}.shell{max-width:900px;margin:auto}.head{margin-bottom:18px}.head h1{font-size:27px;margin:0 0 8px}.head p{color:var(--muted);margin:0;line-height:1.9}.card{background:#fff;border:1px solid #e8edf5;border-radius:20px;box-shadow:0 18px 55px rgba(32,55,94,.10);padding:24px;margin-bottom:16px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.checks{display:grid;grid-template-columns:1fr 1fr;gap:10px}.check{display:flex;align-items:center;gap:9px;border:1px solid var(--line);padding:11px 12px;border-radius:12px;font-size:13px}.dot{width:10px;height:10px;border-radius:50%;flex:0 0 auto}.pass .dot{background:var(--ok);box-shadow:0 0 0 4px #d9f5e6}.fail .dot{background:var(--bad);box-shadow:0 0 0 4px #ffe0dd}.path{direction:ltr;text-align:left;background:#f7f9fc;border:1px dashed #b9c7dc;border-radius:12px;padding:12px;overflow-wrap:anywhere;color:#334155;margin-top:9px;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:13px}.hint{font-size:12px;color:var(--muted);margin-top:7px;line-height:1.8}.title{font-size:18px;margin:0 0 14px}.field{display:block;font-weight:700;font-size:13px}.field input{width:100%;padding:12px 13px;border:1px solid #cbd5e1;border-radius:11px;margin-top:7px;background:#fff;font:inherit;outline:none;transition:.18s}.field input:focus{border-color:#73a2f8;box-shadow:0 0 0 4px #e8f0ff}.full{grid-column:1/-1}.btn{margin-top:20px;width:100%;padding:14px 18px;border:0;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--primary2));color:#fff;font-weight:700;font-size:16px;cursor:pointer;box-shadow:0 10px 24px rgba(37,99,235,.24)}.btn:disabled{background:#94a3b8;box-shadow:none;cursor:not-allowed}.alert{padding:13px 14px;border-radius:12px;margin-bottom:10px;line-height:1.9}.alert.bad{background:var(--badbg);border:1px solid #ffc9c5;color:var(--bad)}.alert.warn{background:var(--warnbg);border:1px solid #f3dc91;color:var(--warn)}.success{background:var(--okbg);border:1px solid #b7e5cb;padding:22px;border-radius:15px}.success h2{color:var(--ok);margin-top:0}.success p{line-height:1.9}.success a{display:inline-block;text-decoration:none;background:var(--ok);color:white;padding:11px 18px;border-radius:10px;font-weight:700}.meta{background:#fff;border:1px solid #cae8d7;border-radius:11px;padding:11px;margin:12px 0}.meta strong{display:block;margin-bottom:5px}.small{font-size:12px;color:var(--muted)}
        @media(max-width:700px){body{padding:14px 10px}.card{padding:17px;border-radius:16px}.grid,.checks{grid-template-columns:1fr}.full{grid-column:auto}.head h1{font-size:22px}}
    </style>
</head>
<body>
<main class="shell">
    <header class="head">
        <h1>نصب هوشمند ربات فروشگاه فیلم</h1>
        <p>مسیر ربات و Webhook به‌صورت خودکار از همین پوشه تشخیص داده می‌شود؛ نیازی به واردکردن آدرس پروژه نیست.</p>
    </header>

    <?php if($ok): ?>
        <section class="card">
            <div class="success">
                <h2>نصب با موفقیت کامل شد</h2>
                <p>ربات <b dir="ltr">@<?=e((string)($bot['username']??''))?></b> متصل شد و Webhook روی مسیر صحیح ثبت گردید.</p>
                <div class="meta"><strong>Webhook فعال</strong><span class="path"><?=e($webhookUrl)?></span></div>
                <p>اکنون داخل تلگرام دستور <code>/start</code> را برای ربات بفرست.</p>
                <a href="<?=e(localUrl('admin.php'))?>">ورود به پنل مدیریت</a>
            </div>
        </section>
    <?php else: ?>
        <section class="card">
            <h2 class="title">بررسی خودکار سرور</h2>
            <div class="checks">
                <?php foreach($checks as $check): ?>
                    <div class="check <?=($check['ok']?'pass':'fail')?>"><span class="dot"></span><span><?=e($check['label'])?></span></div>
                <?php endforeach; ?>
            </div>
            <?php if($missingExtensions!==[]): ?><p class="hint">افزونه‌های غیرفعال: <span dir="ltr"><?=e(implode(', ',$missingExtensions))?></span></p><?php endif; ?>
            <?php if($missingFiles!==[]): ?><p class="hint">فایل‌های ناقص: <span dir="ltr"><?=e(implode(', ',$missingFiles))?></span></p><?php endif; ?>
        </section>

        <section class="card">
            <h2 class="title">مسیر تشخیص‌داده‌شده ربات</h2>
            <?php if($detectedBase!==''): ?>
                <div class="path"><?=e($detectedBase)?></div>
                <p class="hint">Webhook نهایی: <span dir="ltr"><?=e($webhookUrl)?></span></p>
            <?php else: ?>
                <div class="alert bad"><?=e($detectError!==''?$detectError:'مسیر عمومی ربات قابل تشخیص نیست.')?></div>
            <?php endif; ?>
        </section>

        <section class="card">
            <?php foreach($errors as $error): ?><div class="alert bad"><?=e($error)?></div><?php endforeach; ?>
            <?php if(!$https): ?><div class="alert warn">این صفحه با HTTPS باز نشده است. ابتدا SSL دامنه را فعال کن و همین آدرس را با <span dir="ltr">https://</span> باز کن.</div><?php endif; ?>
            <h2 class="title">اطلاعات اتصال و مدیریت</h2>
            <form method="post" action="<?=e(localUrl('install.php'))?>" autocomplete="off">
                <div class="grid">
                    <label class="field">هاست دیتابیس<input name="db_host" value="<?=e(posted('db_host','localhost'))?>" dir="ltr" required></label>
                    <label class="field">نام دیتابیس<input name="db_name" value="<?=e(posted('db_name'))?>" dir="ltr" required></label>
                    <label class="field">نام کاربری دیتابیس<input name="db_user" value="<?=e(posted('db_user'))?>" dir="ltr" required></label>
                    <label class="field">رمز دیتابیس<input type="password" name="db_pass" dir="ltr" autocomplete="new-password" required></label>
                    <label class="field full">توکن ربات<input name="bot_token" dir="ltr" placeholder="123456:ABC..." autocomplete="off" spellcheck="false" required></label>
                    <label class="field">آیدی عددی مدیر اصلی<input name="owner_id" dir="ltr" inputmode="numeric" value="<?=e(posted('owner_id'))?>" required></label>
                    <label class="field">نام کاربری پنل<input name="panel_user" value="<?=e(posted('panel_user','admin'))?>" dir="ltr" required></label>
                    <label class="field full">رمز پنل (حداقل ۸ کاراکتر)<input type="password" name="panel_pass" dir="ltr" minlength="8" autocomplete="new-password" required></label>
                </div>
                <button class="btn"<?php if(!$ready): ?> disabled<?php endif; ?>>نصب و راه‌اندازی خودکار</button>
            </form>
            <p class="small">پس از نصب، برای تحویل خودکار لینک دعوت باید ربات را در کانال هر محصول ادمین کنی.</p>
        </section>
    <?php endif; ?>
</main>
</body>
</html>
