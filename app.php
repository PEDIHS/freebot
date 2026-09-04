<?php
declare(strict_types=1);

require_once __DIR__ . '/smart-payment.php';

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    http_response_code(404);
    exit;
}

final class App
{
    private static ?PDO $pdo = null;
    private static ?array $config = null;
    private static ?array $settings = null;
    private static ?array $buttonStyles = null;
    private static bool $migrated = false;

    public static function config(): array
    {
        if (self::$config !== null) return self::$config;
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) throw new RuntimeException('نصب انجام نشده است.');
        $cfg = require $file;
        if (!is_array($cfg)) throw new RuntimeException('config.php نامعتبر است.');
        return self::$config = $cfg;
    }

    public static function db(): PDO
    {
        if (self::$pdo !== null) return self::$pdo;
        $c = self::config()['db'];
        $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset=utf8mb4";
        self::$pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        self::migrate(self::$pdo);
        return self::$pdo;
    }


    private static function migrate(PDO $pdo): void
    {
        if (self::$migrated) return;
        self::$migrated = true;
        try {
            $col = $pdo->query("SHOW COLUMNS FROM menus LIKE 'style'")->fetch();
            if (!$col) $pdo->exec("ALTER TABLE menus ADD COLUMN style varchar(20) NOT NULL DEFAULT '' AFTER label");
            $pdo->exec("CREATE TABLE IF NOT EXISTS button_styles (button_key varchar(120) PRIMARY KEY,label varchar(255) NOT NULL,group_title varchar(255) NOT NULL,style varchar(20) NOT NULL DEFAULT '',sort_order int NOT NULL DEFAULT 100) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            self::ensureMediaSchema($pdo);
            SmartPayment::ensureSchema($pdo);
            self::syncButtonStyles($pdo);
            $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('topup_presets','50000,100000,200000,500000,1000000') ON DUPLICATE KEY UPDATE `value`=IF(TRIM(`value`)='',VALUES(`value`),`value`)");
            $pdo->exec("UPDATE menus SET label='👤 کیف پول من' WHERE `key`='wallet' AND label IN ('💳 افزایش موجودی','کیف پول','افزایش موجودی')");
            $schemaRow = $pdo->query("SELECT `value` FROM settings WHERE `key`='schema_version' LIMIT 1")->fetch();
            $schemaVersion = (string)($schemaRow['value'] ?? '1.0.0');
            if (version_compare($schemaVersion, '1.1.1', '<')) {
                // Keep only the purchase action highlighted; ordinary menu items stay neutral.
                $styles = ['buy'=>'success','wallet'=>'','packs'=>'','proof'=>'','referral'=>'','support'=>''];
                $st = $pdo->prepare("UPDATE menus SET style=? WHERE `key`=?");
                foreach ($styles as $key=>$style) $st->execute([$style,$key]);
                $pdo->exec("ALTER TABLE menus MODIFY style varchar(20) NOT NULL DEFAULT ''");
                $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('schema_version','1.1.1') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
            }
            if (version_compare($schemaVersion, '1.1.2', '<')) {
                $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('schema_version','1.1.2') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
            }
            if (version_compare($schemaVersion, '1.2.0', '<')) {
                $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('schema_version','1.2.0') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
            }
            if (version_compare($schemaVersion, '1.2.1', '<')) {
                $inlineMenuCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'inline_menu_ready'")->fetch();
                if (!$inlineMenuCol) {
                    $pdo->exec("ALTER TABLE users ADD COLUMN inline_menu_ready tinyint(1) NOT NULL DEFAULT 0 AFTER state_data");
                    $pdo->exec("ALTER TABLE users ALTER inline_menu_ready SET DEFAULT 1");
                }
                $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('log_chat_url','') ON DUPLICATE KEY UPDATE `value`=`value`");
                $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('schema_version','1.2.1') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
            }
            $defaults = self::defaultTexts();
            $ins = $pdo->prepare("INSERT INTO texts (`key`,title,`value`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE `value`=IF(TRIM(`value`)='',VALUES(`value`),`value`)");
            foreach ($defaults as $key=>$row) $ins->execute([$key,$row[0],$row[1]]);
            if (version_compare($schemaVersion, '1.2.2', '<')) {
                $professional = $pdo->prepare("INSERT INTO texts (`key`,title,`value`) VALUES (?,?,?) ON DUPLICATE KEY UPDATE title=VALUES(title),`value`=VALUES(`value`)");
                foreach ($defaults as $key=>$row) $professional->execute([$key,$row[0],$row[1]]);
                $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('schema_version','1.2.2') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
            }
            if (version_compare($schemaVersion, '1.2.3', '<')) {
                $menuDefaults = [
                    ['buy','🛒 خرید فیلم','success','shop','',1,10],
                    ['wallet','💳 کیف پول + شارژ','success','wallet','',2,10],
                    ['packs','📦 پک‌های من','primary','my_packs','',3,10],
                    ['proof','🎬 کانال گزارشات و نمونه فیلم','','proof','',4,10],
                    ['referral','👥 زیرمجموعه‌گیری','','referral','',5,10],
                    ['support','💬 پشتیبانی','','support','',5,20],
                ];
                $menuUpsert = $pdo->prepare("INSERT INTO menus (`key`,label,style,action_type,action_value,row_no,sort_order,enabled) VALUES (?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE label=VALUES(label),style=VALUES(style),action_type=VALUES(action_type),action_value=VALUES(action_value),row_no=VALUES(row_no),sort_order=VALUES(sort_order),enabled=1");
                foreach ($menuDefaults as $menuDefault) $menuUpsert->execute($menuDefault);
                $pdo->exec("UPDATE button_styles SET style=CASE WHEN button_key IN ('nav_home','nav_back') THEN 'danger' ELSE '' END WHERE button_key IN ('nav_home','nav_back','proof_open')");
                $pdo->exec("UPDATE texts SET title='متن کانال گزارشات و نمونه فیلم',`value`='🎬 برای مشاهده گزارش‌ها و نمونه فیلم‌ها، وارد کانال زیر شو:' WHERE `key`='proof_button'");
                $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('schema_version','1.2.3') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
            }
            if (version_compare($schemaVersion, '1.2.4', '<')) {
                $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('sample_movie_url','') ON DUPLICATE KEY UPDATE `value`=`value`");
                $menuDefaults = [
                    ['buy','🛒 خرید فیلم','success','shop','',1,10],
                    ['wallet','💳 کیف پول + شارژ','success','wallet','',1,20],
                    ['packs','📦 پک‌های من','primary','my_packs','',2,10],
                    ['proof','📊 گروه گزارشات','primary','proof','',3,10],
                    ['sample_movie','🎬 نمونه فیلم','primary','sample_movie','',4,10],
                    ['referral','👥 زیرمجموعه‌گیری','primary','referral','',5,10],
                    ['support','💬 پشتیبانی','primary','support','',5,20],
                ];
                $menuUpsert = $pdo->prepare("INSERT INTO menus (`key`,label,style,action_type,action_value,row_no,sort_order,enabled) VALUES (?,?,?,?,?,?,?,1) ON DUPLICATE KEY UPDATE label=VALUES(label),style=VALUES(style),action_type=VALUES(action_type),action_value=VALUES(action_value),row_no=VALUES(row_no),sort_order=VALUES(sort_order),enabled=1");
                foreach ($menuDefaults as $menuDefault) $menuUpsert->execute($menuDefault);
                $pdo->exec("UPDATE menus SET style=CASE WHEN `key` IN ('buy','wallet') THEN 'success' ELSE 'primary' END");
                $pdo->exec("UPDATE button_styles SET label='گروه گزارشات',style='primary' WHERE button_key='proof_open'");
                $pdo->exec("UPDATE texts SET title='متن گروه گزارشات',`value`='📊 برای مشاهده گزارش‌ها وارد گروه یا کانال زیر شو:' WHERE `key`='proof_button'");
                $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('schema_version','1.2.4') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
            }
            if (version_compare($schemaVersion, '1.3.0', '<')) {
                SmartPayment::ensureSchema($pdo);
                $pdo->exec("UPDATE button_styles SET style='primary' WHERE button_key='product_item'");
                $oldProduct="🎬 <b>{title}</b>

{description}

🗂 دسته‌بندی: {category}
💳 قیمت: <b>{price}</b>";
                $oldInvoice="🧾 <b>فاکتور پرداخت</b>

مبلغ قابل پرداخت: <b>{amount}</b>
بابت: <b>{product}</b>

اطلاعات کارت:{cards}

بعد از واریز، دکمه «ارسال رسید» را بزن.";
                $stText=$pdo->prepare("UPDATE texts SET `value`=? WHERE `key`=? AND (`value`=? OR TRIM(`value`)='')");
                $stText->execute([$defaults['product_detail'][1],'product_detail',$oldProduct]);
                $stText->execute([$defaults['invoice'][1],'invoice',$oldInvoice]);
                $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('schema_version','1.3.0') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
            }
            if (version_compare($schemaVersion, '1.3.1', '<')) {
                $pdo->exec("UPDATE button_styles SET style='primary' WHERE button_key IN ('product_item','copy_amount','copy_card')");
                $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('schema_version','1.3.1') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
            }
            $old = "👥 <b>زیرمجموعه‌گیری</b>\nلینک شما:\n<code>{link}</code>\n\nتعداد زیرمجموعه: {count}\nدرآمد کل: {earned}\nدرصد هر خرید: {percent}%\nمبلغ ثابت هر خرید: {fixed}";
            $pdo->prepare("UPDATE texts SET `value`=? WHERE `key`='referral_info' AND (`value`=? OR TRIM(`value`)='')")->execute([$defaults['referral_info'][1],$old]);
        } catch (Throwable $e) {
            error_log('film-store migration: '.$e->getMessage());
        }
    }

    private static function ensureMediaSchema(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS media_batches (
            id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
            product_id int unsigned NULL,
            channel_id varchar(64) NOT NULL,
            title varchar(255) NOT NULL,
            caption_template text NULL,
            upload_mode enum('auto','video','document') NOT NULL DEFAULT 'auto',
            status enum('queued','running','paused','completed','completed_with_errors','cancelled') NOT NULL DEFAULT 'queued',
            total_items int unsigned NOT NULL DEFAULT 0,
            completed_items int unsigned NOT NULL DEFAULT 0,
            failed_items int unsigned NOT NULL DEFAULT 0,
            current_item_id bigint unsigned NULL,
            created_by varchar(64) NOT NULL DEFAULT 'panel',
            started_at datetime NULL,
            completed_at datetime NULL,
            notification_status varchar(30) NULL,
            notification_sent_at datetime NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_media_batch_status(status),
            INDEX idx_media_batch_product(product_id),
            CONSTRAINT fk_media_batch_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        if(!$pdo->query("SHOW COLUMNS FROM media_batches LIKE 'notification_status'")->fetch())$pdo->exec("ALTER TABLE media_batches ADD COLUMN notification_status varchar(30) NULL AFTER completed_at");
        if(!$pdo->query("SHOW COLUMNS FROM media_batches LIKE 'notification_sent_at'")->fetch())$pdo->exec("ALTER TABLE media_batches ADD COLUMN notification_sent_at datetime NULL AFTER notification_status");
        $pdo->exec("CREATE TABLE IF NOT EXISTS media_jobs (
            id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
            batch_id bigint unsigned NOT NULL,
            position int unsigned NOT NULL,
            source_url text NOT NULL,
            source_host varchar(255) NOT NULL DEFAULT '',
            detected_title varchar(500) NULL,
            engine varchar(50) NULL,
            status enum('queued','downloading','downloaded','uploading','completed','failed','cancelled') NOT NULL DEFAULT 'queued',
            progress decimal(5,2) NOT NULL DEFAULT 0,
            attempts tinyint unsigned NOT NULL DEFAULT 0,
            download_attempts tinyint unsigned NOT NULL DEFAULT 0,
            upload_attempts tinyint unsigned NOT NULL DEFAULT 0,
            max_attempts tinyint unsigned NOT NULL DEFAULT 3,
            next_attempt_at datetime NULL,
            downloaded_bytes bigint unsigned NOT NULL DEFAULT 0,
            total_bytes bigint unsigned NOT NULL DEFAULT 0,
            download_speed_bps bigint unsigned NOT NULL DEFAULT 0,
            upload_speed_bps bigint unsigned NOT NULL DEFAULT 0,
            eta_seconds int unsigned NULL,
            file_path varchar(1000) NULL,
            file_name varchar(500) NULL,
            mime_type varchar(120) NULL,
            telegram_message_id bigint NULL,
            error_code varchar(80) NULL,
            error_message text NULL,
            locked_by varchar(190) NULL,
            lock_token char(64) NULL,
            lock_expires_at datetime NULL,
            heartbeat_at datetime NULL,
            started_at datetime NULL,
            finished_at datetime NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_media_batch_position(batch_id,position),
            INDEX idx_media_job_pick(status,next_attempt_at,lock_expires_at,id),
            CONSTRAINT fk_media_job_batch FOREIGN KEY(batch_id) REFERENCES media_batches(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $jobColumns=[
            'download_attempts'=>"tinyint unsigned NOT NULL DEFAULT 0 AFTER attempts",
            'upload_attempts'=>"tinyint unsigned NOT NULL DEFAULT 0 AFTER download_attempts",
            'download_speed_bps'=>"bigint unsigned NOT NULL DEFAULT 0 AFTER total_bytes",
            'upload_speed_bps'=>"bigint unsigned NOT NULL DEFAULT 0 AFTER download_speed_bps",
            'eta_seconds'=>"int unsigned NULL AFTER upload_speed_bps",
            'locked_by'=>"varchar(190) NULL AFTER error_message",
            'lock_token'=>"char(64) NULL AFTER locked_by",
            'lock_expires_at'=>"datetime NULL AFTER lock_token",
            'heartbeat_at'=>"datetime NULL AFTER lock_expires_at",
        ];
        foreach($jobColumns as $column=>$definition){
            if(!$pdo->query("SHOW COLUMNS FROM media_jobs LIKE ".$pdo->quote($column))->fetch())$pdo->exec("ALTER TABLE media_jobs ADD COLUMN {$column} {$definition}");
        }
        $statusColumn=$pdo->query("SHOW COLUMNS FROM media_jobs LIKE 'status'")->fetch();
        $statusType=strtolower((string)($statusColumn['Type']??''));
        if(str_contains($statusType,"'done'")||str_contains($statusType,"'retry_wait'")||str_contains($statusType,"'resolving'")){
            $pdo->exec("ALTER TABLE media_jobs MODIFY status enum('queued','resolving','downloading','downloaded','uploading','retry_wait','done','completed','failed','cancelled') NOT NULL DEFAULT 'queued'");
            $pdo->exec("UPDATE media_jobs SET status='completed' WHERE status='done'");
            $pdo->exec("UPDATE media_jobs SET status='queued',next_attempt_at=COALESCE(next_attempt_at,NOW()) WHERE status IN ('retry_wait','resolving')");
            $pdo->exec("ALTER TABLE media_jobs MODIFY status enum('queued','downloading','downloaded','uploading','completed','failed','cancelled') NOT NULL DEFAULT 'queued'");
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS media_job_events (
            id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
            job_id bigint unsigned NOT NULL,
            level enum('info','warning','error','success') NOT NULL DEFAULT 'info',
            stage varchar(50) NOT NULL,
            message text NOT NULL,
            meta longtext NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_media_event_job(job_id,id),
            CONSTRAINT fk_media_event_job FOREIGN KEY(job_id) REFERENCES media_jobs(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS media_workers (
            worker_id varchar(190) PRIMARY KEY,
            role enum('download','upload') NOT NULL,
            hostname varchar(190) NOT NULL,
            pid int unsigned NOT NULL,
            status enum('starting','idle','busy','stopping','stopped','error') NOT NULL DEFAULT 'starting',
            current_job_id bigint unsigned NULL,
            jobs_processed bigint unsigned NOT NULL DEFAULT 0,
            last_error text NULL,
            started_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            heartbeat_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_media_worker_live(role,heartbeat_at),
            CONSTRAINT fk_media_worker_job FOREIGN KEY(current_job_id) REFERENCES media_jobs(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS channel_posts (
            id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
            chat_id varchar(64) NOT NULL,
            message_id bigint NOT NULL,
            media_type varchar(30) NOT NULL DEFAULT 'text',
            file_id varchar(500) NULL,
            file_size bigint unsigned NOT NULL DEFAULT 0,
            source varchar(30) NOT NULL DEFAULT 'telegram_update',
            posted_at datetime NOT NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_channel_message(chat_id,message_id),
            INDEX idx_channel_post_type(chat_id,media_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS channel_stats (
            product_id int unsigned PRIMARY KEY,
            channel_id varchar(64) NOT NULL,
            channel_title varchar(255) NULL,
            channel_username varchar(255) NULL,
            member_count int unsigned NOT NULL DEFAULT 0,
            admin_count int unsigned NOT NULL DEFAULT 0,
            bot_status varchar(30) NULL,
            can_post tinyint(1) NOT NULL DEFAULT 0,
            last_error text NULL,
            refreshed_at datetime NULL,
            CONSTRAINT fk_channel_stats_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS invite_link_events (
            id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
            product_id int unsigned NULL,
            order_id bigint unsigned NULL,
            link_hash char(64) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_invite_link_hash(link_hash),
            INDEX idx_invite_product(product_id),
            CONSTRAINT fk_invite_event_product FOREIGN KEY(product_id) REFERENCES products(id) ON DELETE SET NULL,
            CONSTRAINT fk_invite_event_order FOREIGN KEY(order_id) REFERENCES orders(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        foreach (['downloader_max_mb'=>'45','downloader_temp_hours'=>'24','downloader_ytdlp_path'=>'','downloader_batch_limit'=>'100'] as $key=>$value) {
            $st=$pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=IF(TRIM(`value`)='',VALUES(`value`),`value`)");
            $st->execute([$key,$value]);
        }
        $pdo->exec("INSERT IGNORE INTO invite_link_events(product_id,order_id,link_hash,created_at) SELECT product_id,id,SHA2(invite_link,256),COALESCE(paid_at,created_at) FROM orders WHERE invite_link IS NOT NULL AND invite_link<>''");
    }

    public static function defaultButtonStyles(): array
    {
        return [
            'forced_join_channel'=>['عضویت در کانال اجباری','کاربر — عضویت و ناوبری',''],
            'check_join'=>['بررسی عضویت','کاربر — عضویت و ناوبری','success'],
            'nav_home'=>['خانه / بازگشت به منوی اصلی','کاربر — عضویت و ناوبری','danger'],
            'nav_back'=>['بازگشت به مرحله قبل','کاربر — عضویت و ناوبری','danger'],
            'category_item'=>['انتخاب دسته‌بندی','کاربر — فروشگاه',''],
            'product_item'=>['انتخاب محصول','کاربر — فروشگاه','primary'],
            'shop_open'=>['بازکردن فروشگاه / خرید فیلم','کاربر — فروشگاه','success'],
            'product_buy'=>['خرید محصول','کاربر — فروشگاه','success'],
            'topup_open'=>['افزایش موجودی','کاربر — کیف پول و پرداخت','success'],
            'topup_preset'=>['مبلغ پیشنهادی شارژ','کاربر — کیف پول و پرداخت',''],
            'topup_custom'=>['مبلغ دلخواه','کاربر — کیف پول و پرداخت',''],
            'copy_amount'=>['کپی مبلغ دقیق','کاربر — کیف پول و پرداخت','primary'],
            'copy_card'=>['کپی شماره کارت','کاربر — کیف پول و پرداخت','primary'],
            'receipt_send'=>['پرداخت کردم / ارسال رسید','کاربر — کیف پول و پرداخت','success'],
            'topup_cancel'=>['انصراف از پرداخت','کاربر — کیف پول و پرداخت','danger'],
            'my_packs'=>['پک‌های من','کاربر — سفارش و دسترسی',''],
            'order_join'=>['درخواست عضویت در کانال محصول','کاربر — سفارش و دسترسی','success'],
            'order_link'=>['نمایش لینک سفارش','کاربر — سفارش و دسترسی','primary'],
            'copy_link'=>['کپی لینک','کاربر — سفارش و دسترسی','primary'],
            'proof_open'=>['گروه گزارشات','کاربر — عمومی و پشتیبانی','primary'],
            'sample_movie_open'=>['نمونه فیلم','کاربر — عمومی و پشتیبانی','primary'],
            'external_link'=>['لینک سفارشی','کاربر — عمومی و پشتیبانی','primary'],
            'cancel_state'=>['لغو عملیات','کاربر — عمومی و پشتیبانی','danger'],
            'support_reply'=>['پاسخ پشتیبانی','مدیریت — رسید و پشتیبانی','success'],
            'support_close'=>['بستن تیکت','مدیریت — رسید و پشتیبانی','danger'],
            'support_reopen'=>['بازکردن دوباره تیکت','مدیریت — رسید و پشتیبانی','success'],
            'manage_user'=>['مدیریت کاربر','مدیریت — کاربر','primary'],
            'topup_approve'=>['تأیید رسید','مدیریت — رسید و پشتیبانی','success'],
            'topup_reject'=>['رد رسید','مدیریت — رسید و پشتیبانی','danger'],
            'topup_block'=>['رد رسید و بلاک','مدیریت — رسید و پشتیبانی','danger'],
            'admin_web_panel'=>['ورود مستقیم پنل وب','مدیریت — ناوبری','primary'],
            'admin_home'=>['منوی اصلی مدیریت','مدیریت — ناوبری','primary'],
            'admin_back'=>['بازگشت در مدیریت','مدیریت — ناوبری','primary'],
            'admin_section'=>['ورود به بخش مدیریتی','مدیریت — ناوبری',''],
            'admin_add'=>['افزودن مورد جدید','مدیریت — عملیات عمومی','success'],
            'admin_edit'=>['ویرایش مقدار','مدیریت — عملیات عمومی',''],
            'admin_enable'=>['فعال‌کردن','مدیریت — عملیات عمومی','success'],
            'admin_disable'=>['غیرفعال‌کردن','مدیریت — عملیات عمومی','danger'],
            'admin_delete'=>['حذف','مدیریت — عملیات عمومی','danger'],
            'admin_move'=>['جابجایی بالا / پایین','مدیریت — عملیات عمومی',''],
            'webhook_reset'=>['بازتنظیم Webhook','مدیریت — عملیات عمومی','success'],
            'user_balance_add'=>['افزایش موجودی کاربر','مدیریت — کاربر','success'],
            'user_balance_sub'=>['کاهش موجودی کاربر','مدیریت — کاربر','danger'],
            'user_block'=>['مسدودکردن کاربر','مدیریت — کاربر','danger'],
            'user_unblock'=>['رفع مسدودیت کاربر','مدیریت — کاربر','success'],
            'user_message'=>['ارسال پیام به کاربر','مدیریت — کاربر',''],
            'user_orders'=>['سفارش‌ها و تراکنش‌های کاربر','مدیریت — کاربر',''],
            'order_item'=>['انتخاب سفارش','مدیریت — سفارش',''],
            'order_delete'=>['حذف سفارش','مدیریت — سفارش','danger'],
            'order_delete_refund'=>['حذف سفارش و بازگشت وجه','مدیریت — سفارش','danger'],
            'no_action'=>['دکمه اطلاع‌رسانی بدون عملیات','سایر',''],
        ];
    }

    private static function syncButtonStyles(PDO $pdo): void
    {
        $st = $pdo->prepare("INSERT INTO button_styles(button_key,label,group_title,style,sort_order) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE label=VALUES(label),group_title=VALUES(group_title),sort_order=VALUES(sort_order)");
        $sort = 10;
        foreach (self::defaultButtonStyles() as $key=>$row) {
            $st->execute([$key,$row[0],$row[1],self::normalizeStyle($row[2]) ?? '',$sort]);
            $sort += 10;
        }
    }

    public static function buttonStyleRows(): array
    {
        return self::all('SELECT * FROM button_styles ORDER BY group_title,sort_order,button_key');
    }

    public static function setButtonStyle(string $key, ?string $style): void
    {
        if (!array_key_exists($key, self::defaultButtonStyles())) throw new RuntimeException('کلید دکمه نامعتبر است.');
        self::q('UPDATE button_styles SET style=? WHERE button_key=?', [self::normalizeStyle($style) ?? '', $key]);
        self::$buttonStyles = null;
    }

    public static function resetButtonStyles(): void
    {
        $st = self::db()->prepare('UPDATE button_styles SET style=? WHERE button_key=?');
        foreach (self::defaultButtonStyles() as $key=>$row) $st->execute([self::normalizeStyle($row[2]) ?? '',$key]);
        self::$buttonStyles = null;
    }

    private static function buttonStyleMap(): array
    {
        if (self::$buttonStyles !== null) return self::$buttonStyles;
        self::$buttonStyles = [];
        foreach (self::all('SELECT button_key,style FROM button_styles') as $row) self::$buttonStyles[$row['button_key']] = (string)$row['style'];
        return self::$buttonStyles;
    }

    private static function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) if (stripos($value, $needle) !== false) return true;
        return false;
    }

    private static function buttonStyleKey(array $button): ?string
    {
        if (!empty($button['style_key'])) return (string)$button['style_key'];
        $text = trim((string)($button['text'] ?? ''));
        $cb = (string)($button['callback_data'] ?? '');

        if (isset($button['web_app'])) return 'admin_web_panel';
        if (isset($button['copy_text'])) {
            if (self::containsAny($text,['کارت'])) return 'copy_card';
            if (self::containsAny($text,['مبلغ'])) return 'copy_amount';
            return 'copy_link';
        }

        if ($cb !== '') {
            if (self::containsAny($text,['بازگشت','قبلی','دسته‌بندی‌ها','محصولات'])) return 'nav_back';
            if ($cb === 'home' || self::containsAny($text,['خانه','منوی اصلی'])) return 'nav_home';
            if ($cb === 'shop') return 'shop_open';
            if ($cb === 'check_join') return 'check_join';
            if ($cb === 'topup') return 'topup_open';
            if ($cb === 'topup_custom') return 'topup_custom';
            if ($cb === 'my_packs') return 'my_packs';
            if ($cb === 'cancel_state') return 'cancel_state';
            if ($cb === 'admin_home') return 'admin_home';
            if ($cb === 'adm_webhook_reset') return 'webhook_reset';
            if ($cb === 'noop') return 'no_action';

            $prefixes = [
                'cat:'=>'category_item','product:'=>'product_item','buy:'=>'product_buy',
                'topup_amount:'=>'topup_preset','topup_product:'=>'topup_open','paid:'=>'receipt_send',
                'cancel_topup:'=>'topup_cancel','order_link:'=>'order_link','manage_user:'=>'manage_user',
                'topup_ok:'=>'topup_approve','topup_reject:'=>'topup_reject','topup_block:'=>'topup_block',
                'support_reply:'=>'support_reply','support_close:'=>'support_close','support_reopen:'=>'support_reopen',
                'adm_user_addbal:'=>'user_balance_add','adm_user_subbal:'=>'user_balance_sub',
                'adm_user_message:'=>'user_message','adm_user_orders:'=>'user_orders','adm_user_tx:'=>'user_orders',
                'adm_order_delete:'=>'order_delete','adm_order_delask:'=>'order_delete','adm_order:'=>'order_item',
            ];
            if (str_starts_with($cb,'adm_order_delete:')) return self::containsAny($text,['بازگشت وجه']) ? 'order_delete_refund' : 'order_delete';
            foreach ($prefixes as $prefix=>$key) if (str_starts_with($cb,$prefix)) return $key;
            if (str_starts_with($cb,'adm_user_block:')) return self::containsAny($text,['رفع']) ? 'user_unblock' : 'user_block';
            if (str_starts_with($cb,'adm_')) {
                if (self::containsAny($text,['⬅️','بازگشت','مدیریت اصلی','منوی مدیریت'])) return 'admin_back';
                if (self::containsAny($text,['افزودن','جدید'])) return 'admin_add';
                if (self::containsAny($text,['حذف'])) return 'admin_delete';
                if (self::containsAny($text,['غیرفعال','مسدود کردن'])) return 'admin_disable';
                if (self::containsAny($text,['رفع مسدودیت','فعال کردن']) || trim($text)==='فعال') return 'admin_enable';
                if (self::containsAny($text,['↑','↓','بالا','پایین'])) return 'admin_move';
                if (self::containsAny($text,['تغییر','ویرایش','عنوان','توضیحات','قیمت','کانال','تصویر','عمر لینک','ترتیب','دسته‌بندی','مقدار','ردیف','رنگ','لینک','شماره کارت','صاحب حساب','آیدی چت'])) return 'admin_edit';
                return 'admin_section';
            }
        }

        if (isset($button['url'])) {
            if (self::containsAny($text,['عضویت در'])) return 'forced_join_channel';
            if (self::containsAny($text,['نمونه فیلم'])) return 'sample_movie_open';
            if (self::containsAny($text,['گروه اثبات','مشاهده گروه اثبات','گزارشات'])) return 'proof_open';
            if (self::containsAny($text,['درخواست عضویت'])) return 'order_join';
            if (self::containsAny($text,['پنل'])) return 'admin_web_panel';
            return 'external_link';
        }
        return null;
    }

    public static function defaultTexts(): array
    {
        return [
            'welcome'=>['خوش‌آمدگویی',"سلام <b>{name}</b> 👋\n\nبه فروشگاه خوش آمدی. از منوی زیر بخش موردنظرت را انتخاب کن.\n\n💰 موجودی حساب: <b>{balance}</b>"],
            'forced_join'=>['عضویت اجباری',"🔐 <b>تأیید عضویت</b>\n\nبرای استفاده از ربات، ابتدا در کانال‌های زیر عضو شو و سپس دکمه «بررسی عضویت» را بزن."],
            'select_category'=>['انتخاب دسته‌بندی',"🗂 <b>دسته‌بندی محصولات</b>\n\nیکی از دسته‌بندی‌های زیر را انتخاب کن:"],
            'no_categories'=>['بدون دسته‌بندی',"📭 در حال حاضر دسته‌بندی فعالی وجود ندارد.\nلطفاً کمی بعد دوباره بررسی کن."],
            'select_product'=>['انتخاب محصول',"🎬 <b>محصولات {category}</b>\n\nمحصول موردنظرت را انتخاب کن:"],
            'product_detail'=>['جزئیات محصول',"🎬 <b>{title}</b>\n\n{description}\n\n📊 محتوای ثبت‌شده کانال\n🎞 فیلم: <b>{videos}</b>\n🖼 عکس: <b>{photos}</b>\n\n🗂 دسته‌بندی: {category}\n💳 قیمت: <b>{price}</b>"],
            'low_balance'=>['کمبود موجودی',"⚠️ <b>موجودی کافی نیست</b>\n\nقیمت محصول: <b>{price}</b>\nموجودی شما: <b>{balance}</b>\nمبلغ موردنیاز: <b>{shortage}</b>\n\nبرای ادامه، موجودی حسابت را افزایش بده."],
            'wallet'=>['کیف پول',"💰 موجودی فعلی شما: <b>{balance}</b>"],
            'wallet_profile'=>['پروفایل کیف پول',"👤 <b>حساب کاربری من</b>\n\n<b>{name}</b>\nشناسه کاربری: <code>{user_id}</code>\nتاریخ عضویت: {joined_at}\n\n💰 موجودی: <b>{balance}</b>\n🛍 خریدهای موفق: <b>{orders}</b>\n👥 دوستان دعوت‌شده: <b>{referrals}</b>"],
            'topup_choose_amount'=>['انتخاب مبلغ شارژ',"💳 <b>افزایش موجودی</b>\n\nیکی از مبلغ‌های پیشنهادی را انتخاب کن یا مبلغ دلخواهت را وارد کن.\n\nحداقل افزایش موجودی: <b>{min}</b>"],
            'topup_amount_prompt'=>['درخواست مبلغ',"✍️ مبلغ موردنظرت را فقط به‌صورت عدد وارد کن.\n\nحداقل مبلغ: <b>{min}</b>"],
            'invoice'=>['فاکتور',"🧾 <b>فاکتور پرداخت #{invoice_id}</b>\n\n📦 نام محصول: <b>{product}</b>\n💰 مبلغ پایه فاکتور: <b>{base_amount}</b>\n💳 مبلغ دقیق قابل پرداخت: <b>{amount}</b>\n\n🎞 فیلم موجود در کانال: <b>{videos}</b>\n🖼 عکس موجود در کانال: <b>{photos}</b>\n\n💳 <b>اطلاعات کارت</b>{cards}\n\n🧠 روش بررسی: <b>{payment_mode}</b>\n{payment_notice}"],
            'receipt_prompt'=>['درخواست رسید',"📎 تصویر یا فایل رسید پرداخت را همین‌جا ارسال کن.\nپیام متنی به‌عنوان رسید پذیرفته نمی‌شود."],
            'receipt_invalid'=>['رسید نامعتبر',"⚠️ رسید معتبر نیست. لطفاً تصویر یا فایل رسید پرداخت را ارسال کن."],
            'receipt_sent'=>['رسید ارسال شد',"✅ <b>رسید با موفقیت ثبت شد</b>\n\nرسید برای مدیریت ارسال شد و نتیجه بررسی از همین ربات به تو اطلاع داده می‌شود."],
            'topup_approved'=>['تایید شارژ',"✅ <b>افزایش موجودی تأیید شد</b>\n\nمبلغ تأییدشده: <b>{amount}</b>\nموجودی جدید: <b>{balance}</b>"],
            'topup_rejected'=>['رد رسید',"❌ <b>رسید تأیید نشد</b>\n\nدلیل: {reason}\n\nدر صورت نیاز از بخش پشتیبانی پیام بفرست."],
            'blocked_by_admin'=>['بلاک پس از رسید',"🚫 <b>حساب شما مسدود شد</b>\n\nدلیل: {reason}"],
            'order_success'=>['خرید موفق',"✅ <b>خرید با موفقیت انجام شد</b>\n\nمحصول: <b>{product}</b>\nمبلغ: <b>{amount}</b>\nشماره سفارش: <code>#{order_id}</code>\n\nبرای ورود به کانال محصول، دکمه زیر را بزن."],
            'my_packs_empty'=>['پک خالی',"📦 هنوز محصولی خریداری نکرده‌ای.\nاز فروشگاه دیدن کن و محصول موردنظرت را انتخاب کن."],
            'proof_button'=>['متن گروه گزارشات',"📊 برای مشاهده گزارش‌ها وارد گروه یا کانال زیر شو:"],
            'proof_purchase'=>['گزارش عمومی خرید',"✅ <b>خرید موفق جدید</b>\n\n👤 کاربر: {name}\n🎬 محصول: <b>{product}</b>\n💳 مبلغ: <b>{amount}</b>\n🕒 تاریخ: {date}\n🧾 سفارش: <code>#{order_id}</code>"],
            'referral_info'=>['اطلاعات زیرمجموعه',"🎁 <b>دعوت دوستان و دریافت پاداش</b>\n\nلینک اختصاصی شما:\n<code>{link}</code>\n\n<b>آمار دعوت‌ها</b>\n👥 دوستان دعوت‌شده: <b>{count}</b>\n💰 پاداش دریافتی: <b>{earned}</b>\n🎯 پاداش هر خرید: <b>{percent}٪</b> + <b>{fixed}</b>\n\nهر خرید موفق دوستانت، پاداشش را خودکار به موجودی تو اضافه می‌کند."],
            'referral_commission'=>['پورسانت',"🎉 <b>پاداش دعوت واریز شد</b>\n\nمبلغ: <b>{amount}</b>\nشماره سفارش: <code>#{order_id}</code>\n\nاین مبلغ به موجودی حساب شما اضافه شد."],
            'support_prompt'=>['شروع پشتیبانی',"💬 <b>ارتباط با پشتیبانی</b>\n\nپیام، تصویر یا فایل خود را ارسال کن. پاسخ مدیریت از همین بخش برایت فرستاده می‌شود."],
            'support_sent'=>['ارسال پشتیبانی',"✅ پیام شما برای پشتیبانی ارسال شد. پس از بررسی، پاسخ از همین ربات دریافت می‌کنی."],
            'blocked'=>['کاربر مسدود',"🚫 دسترسی حساب شما محدود شده است. برای پیگیری با پشتیبانی در ارتباط باش."],
            'unknown'=>['پیام ناشناخته',"از دکمه‌های منوی زیر استفاده کن تا سریع‌تر به بخش موردنظرت برسی."],
            'user_blocked_notice'=>['اعلان مسدودی',"🚫 <b>دسترسی حساب محدود شد</b>\n\nبرای دریافت اطلاعات بیشتر با پشتیبانی در ارتباط باش."],
            'user_unblocked_notice'=>['اعلان رفع مسدودی',"✅ محدودیت حساب شما برداشته شد و اکنون می‌توانی دوباره از ربات استفاده کنی."],
            'wallet_adjust_notice'=>['اعلان تغییر موجودی',"💰 <b>موجودی حساب تغییر کرد</b>\n\nنوع تغییر: {action}\nمبلغ تغییر: <b>{amount}</b>\nموجودی جدید: <b>{balance}</b>"],
        ];
    }

    public static function q(string $sql, array $params = []): PDOStatement
    {
        $st = self::db()->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $r = self::q($sql, $params)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::q($sql, $params)->fetchAll();
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        if (self::$settings === null) {
            self::$settings = [];
            foreach (self::all('SELECT `key`,`value` FROM settings') as $r) self::$settings[$r['key']] = $r['value'];
        }
        return self::$settings[$key] ?? $default;
    }

    public static function setSetting(string $key, string $value): void
    {
        self::q('INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)', [$key, $value]);
        self::$settings = null;
    }

    public static function text(string $key, array $vars = [], ?string $fallback = null): string
    {
        $r = self::one('SELECT `value` FROM texts WHERE `key`=?', [$key]);
        $default = self::defaultTexts()[$key][1] ?? $fallback ?? 'پیام پیش‌فرض ربات';
        $txt = trim((string)($r['value'] ?? ''));
        if ($txt === '') $txt = $default;
        $txt = self::normalizeBotText($txt);
        foreach ($vars as $k => $v) $txt = str_replace('{' . $k . '}', (string)$v, $txt);
        return $txt;
    }

    public static function normalizeBotText(string $text): string
    {
        $text = str_replace(["\\r\\n","\\n","\\r","\\t"], ["\n","\n","\r","\t"], $text);
        $text = preg_replace('/\*\*(.+?)\*\*/su','<b>$1</b>',$text) ?? $text;
        $text = preg_replace('/(?<!`)`([^`\n]+)`(?!`)/u','<code>$1</code>',$text) ?? $text;
        return trim($text);
    }

    public static function now(): string { return date('Y-m-d H:i:s'); }
    public static function money(float|string $v): string
    {
        return number_format((float)$v, 0, '.', ',') . ' ' . self::setting('currency', 'تومان');
    }
    public static function h(string|int|float|null $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    public static function j(mixed $v): string { return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }

    public static function encrypt(string $plain): string
    {
        $key = hash('sha256', self::config()['app_key'], true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) throw new RuntimeException('خطا در رمزنگاری');
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 28) throw new RuntimeException('داده رمزگذاری‌شده نامعتبر است');
        $key = hash('sha256', self::config()['app_key'], true);
        $iv = substr($raw, 0, 12); $tag = substr($raw, 12, 16); $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) throw new RuntimeException('خطا در رمزگشایی');
        return $plain;
    }

    public static function token(): string
    {
        $v = (string)self::setting('bot_token', '');
        if ($v === '') throw new RuntimeException('توکن ربات تنظیم نشده است');
        return self::decrypt($v);
    }

    public static function baseUrl(): string { return rtrim((string)self::setting('base_url', ''), '/'); }

    public static function telegram(string $method, array $data = [], ?string $token = null): mixed
    {
        $token ??= self::token();
        $url = 'https://api.telegram.org/bot' . $token . '/' . $method;
        foreach ($data as $k => $v) {
            if (is_array($v) || is_object($v)) $data[$k] = self::j($v);
            elseif (is_bool($v)) $data[$k] = $v ? 'true' : 'false';
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 40,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) throw new RuntimeException('خطای اتصال تلگرام: ' . $err);
        $json = json_decode($body, true);
        if (!is_array($json) || !($json['ok'] ?? false)) {
            $desc = is_array($json) ? ($json['description'] ?? 'خطای نامشخص') : "HTTP {$status}";
            throw new RuntimeException("Telegram API {$status}: {$desc}");
        }
        return $json['result'] ?? true;
    }

    public static function isTelegramEntryUrl(string $url): bool
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') return false;
        $host = strtolower((string)($parts['host'] ?? ''));
        if (!in_array($host, ['t.me','www.t.me','telegram.me','www.telegram.me'], true)) return false;
        return trim((string)($parts['path'] ?? ''), '/') !== '';
    }

    public static function isHttpUrl(string $url): bool
    {
        $url = trim($url);
        return $url !== '' && preg_match('#^https?://#i', $url) === 1 && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    public static function createChatEntryUrl(int|string $chatId, string $purpose = 'chat'): string
    {
        $chatId = trim((string)$chatId);
        if ($chatId === '') throw new RuntimeException('آیدی گروه یا کانال خالی است.');
        try {
            $chat = self::telegram('getChat', ['chat_id'=>$chatId]);
            if (($chat['type'] ?? '') === 'private') throw new RuntimeException('آیدی واردشده متعلق به کاربر است، نه گروه یا کانال.');
            $username = trim((string)($chat['username'] ?? ''), "@ \t\n\r\0\x0B");
            if ($username !== '') return 'https://t.me/'.$username;
            $current = trim((string)($chat['invite_link'] ?? ''));
            if (self::isTelegramEntryUrl($current)) return $current;
            $safePurpose = preg_replace('/[^a-z0-9_-]+/i', '-', $purpose) ?: 'chat';
            $invite = self::telegram('createChatInviteLink', [
                'chat_id'=>$chatId,
                'name'=>substr('superstore-'.$safePurpose.'-'.date('Ymd'), 0, 32),
                'creates_join_request'=>false,
            ]);
            $url = trim((string)($invite['invite_link'] ?? ''));
            if (!self::isTelegramEntryUrl($url)) throw new RuntimeException('تلگرام لینک ورود معتبری برنگرداند.');
            return $url;
        } catch (Throwable $e) {
            throw new RuntimeException('ساخت لینک ورود برای «'.$chatId.'» ناموفق بود. ربات را در آن گروه/کانال ادمین کنید و دسترسی مدیریت لینک دعوت بدهید. جزئیات: '.$e->getMessage(), 0, $e);
        }
    }

    public static function resolveConfiguredChatUrl(string $idKey, string $urlKey, int|string $chatId, string $purpose, string $preferredUrl = ''): string
    {
        $chatId = trim((string)$chatId);
        if ($chatId === '') return self::isTelegramEntryUrl(trim($preferredUrl)) ? trim($preferredUrl) : '';
        $oldId = trim((string)self::setting($idKey, ''));
        $preferredUrl = trim($preferredUrl);
        if ($oldId === $chatId && self::isTelegramEntryUrl($preferredUrl)) return $preferredUrl;
        $oldUrl = trim((string)self::setting($urlKey, ''));
        if ($oldId === $chatId && self::isTelegramEntryUrl($oldUrl)) return $oldUrl;
        return self::createChatEntryUrl($chatId, $purpose);
    }

    public static function saveChatDestination(string $idKey, string $urlKey, int|string $chatId, string $purpose): string
    {
        $chatId = trim((string)$chatId);
        $url = self::resolveConfiguredChatUrl($idKey, $urlKey, $chatId, $purpose);
        self::setSetting($idKey, $chatId);
        self::setSetting($urlKey, $url);
        return $url;
    }

    public static function proofEntryUrl(): string
    {
        $url = trim((string)self::setting('proof_url', ''));
        if (self::isTelegramEntryUrl($url)) return $url;
        $chatId = trim((string)self::setting('proof_chat_id', ''));
        if ($chatId === '') return '';
        try {
            $url = self::createChatEntryUrl($chatId, 'proof');
            self::setSetting('proof_url', $url);
            return $url;
        } catch (Throwable $e) {
            self::logEvent('proof_link_error', $e->getMessage(), ['chat_id'=>$chatId]);
            return '';
        }
    }

    public static function send(int|string $chatId, string $text, ?array $keyboard = null, array $extra = []): mixed
    {
        $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => true];
        if ($keyboard !== null) $data['reply_markup'] = $keyboard;
        return self::telegram('sendMessage', array_merge($data, $extra));
    }

    public static function edit(int|string $chatId, int $messageId, string $text, ?array $keyboard = null): mixed
    {
        $d = ['chat_id'=>$chatId,'message_id'=>$messageId,'text'=>$text,'parse_mode'=>'HTML','disable_web_page_preview'=>true];
        if ($keyboard !== null) $d['reply_markup'] = $keyboard;
        return self::telegram('editMessageText', $d);
    }

    public static function answer(string $callbackId, string $text = '', bool $alert = false): void
    {
        try { self::telegram('answerCallbackQuery', ['callback_query_id'=>$callbackId,'text'=>$text,'show_alert'=>$alert]); } catch (Throwable) {}
    }

    public static function button(string $text, array $action = [], ?string $style = null): array
    {
        $button = ['text'=>$text] + $action;
        $resolved = $style === null
            ? self::guessStyle($text, (string)($action['callback_data'] ?? ''))
            : self::normalizeStyle($style);
        if ($resolved !== null) $button['style'] = $resolved;
        return $button;
    }

    public static function normalizeStyle(?string $style): ?string
    {
        $style = trim((string)$style);
        return in_array($style, ['primary','success','danger'], true) ? $style : null;
    }

    private static function guessStyle(string $text, string $callback = ''): ?string
    {
        $hay = $text.' '.$callback;

        // Red is reserved for destructive or rejecting actions.
        foreach (['حذف','رد','بلاک','مسدود','لغو پرداخت','پشیمون','danger','delete','reject','block'] as $word)
            if (stripos($hay, $word) !== false) return 'danger';

        // Green is only for clear positive/financial confirmations.
        foreach (['تایید','قبول','خرید','پرداخت','افزایش موجودی','ارسال رسید','ثبت سفارش','بررسی عضویت','رفع مسدودیت','success','approve','accept','buy','pay','topup'] as $word)
            if (stripos($hay, $word) !== false) return 'success';

        // Blue is reserved for navigation and entry points.
        foreach (['خانه','بازگشت','منوی اصلی','منوی مدیریت','پنل مدیریت','ورود مستقیم پنل','قبلی','primary','home','back','admin_home'] as $word)
            if (stripos($hay, $word) !== false) return 'primary';

        // Ordinary choices stay in Telegram's default neutral style.
        return null;
    }

    private static function styleRows(array $rows): array
    {
        $overrides = self::buttonStyleMap();
        foreach ($rows as &$row) {
            foreach ($row as &$button) {
                if (is_string($button)) $button = ['text'=>$button];
                if (!is_array($button) || !isset($button['text'])) continue;
                $key = self::buttonStyleKey($button);
                if ($key !== null && array_key_exists($key,$overrides)) {
                    $resolved = self::normalizeStyle($overrides[$key]);
                } else {
                    $resolved = array_key_exists('style', $button)
                        ? self::normalizeStyle((string)$button['style'])
                        : self::guessStyle((string)$button['text'], (string)($button['callback_data'] ?? ''));
                }
                unset($button['style_key']);
                if ($resolved === null) unset($button['style']);
                else $button['style'] = $resolved;
            }
        }
        return $rows;
    }

    public static function inline(array $rows): array { return ['inline_keyboard' => self::styleRows($rows)]; }

    public static function mainKeyboard(int|string|null $telegramId = null): array
    {
        $buttons = self::all('SELECT * FROM menus WHERE enabled=1 ORDER BY row_no, sort_order, id');
        $rows = [];
        foreach ($buttons as $b) {
            $action = (string)$b['action_type'];
            $value = trim((string)$b['action_value']);
            $proofUrl = $action === 'proof' ? self::proofEntryUrl() : '';
            $sampleUrl = $action === 'sample_movie' ? trim((string)self::setting('sample_movie_url','')) : '';
            if ($action === 'custom_url' && self::isHttpUrl($value)) {
                $button = ['text'=>$b['label'],'url'=>$value];
            } elseif ($action === 'proof' && $proofUrl !== '') {
                $button = ['text'=>$b['label'],'url'=>$proofUrl];
            } elseif ($action === 'sample_movie' && self::isHttpUrl($sampleUrl)) {
                $button = ['text'=>$b['label'],'url'=>$sampleUrl];
            } else {
                $button = ['text'=>$b['label'],'callback_data'=>'menu:'.$b['id']];
            }
            $style = self::normalizeStyle($b['style'] ?? null);
            if ($style !== null) $button['style'] = $style;
            $rows[(int)$b['row_no']][] = $button;
        }
        ksort($rows);
        if ($telegramId !== null && self::isAdmin($telegramId)) {
            $rows[9999][] = [
                'text'=>'⚙️ پنل مدیریت',
                'style'=>'primary',
                'web_app'=>['url'=>self::adminPanelUrl($telegramId)],
            ];
        }
        return self::inline(array_values($rows));
    }

    public static function adminPanelUrl(int|string $telegramId): string
    {
        $exp = time() + 600;
        $payload = (string)$telegramId.'|'.$exp;
        $sig = hash_hmac('sha256', $payload, (string)self::config()['app_key']);
        return self::baseUrl().'/admin.php?tg_id='.rawurlencode((string)$telegramId).'&exp='.$exp.'&sig='.$sig;
    }

    public static function verifyAdminPanelToken(int|string $telegramId, int $exp, string $sig): bool
    {
        if ($exp < time() || $exp > time() + 900 || !self::isAdmin($telegramId)) return false;
        $expected = hash_hmac('sha256', (string)$telegramId.'|'.$exp, (string)self::config()['app_key']);
        return hash_equals($expected, $sig);
    }

    public static function menuByLabel(string $label): ?array
    {
        return self::one('SELECT * FROM menus WHERE enabled=1 AND label=? LIMIT 1', [$label]);
    }

    public static function isAdmin(int|string $tgId): bool
    {
        return self::one('SELECT id FROM admins WHERE telegram_id=? AND enabled=1', [(string)$tgId]) !== null;
    }

    public static function getUser(int|string $tgId): ?array
    {
        return self::one('SELECT * FROM users WHERE telegram_id=?', [(string)$tgId]);
    }

    public static function ensureUser(array $from, ?string $startParam = null): array
    {
        $tg = (string)$from['id'];
        $u = self::getUser($tg);
        if ($u) {
            self::q('UPDATE users SET username=?,first_name=?,last_name=?,last_seen_at=NOW() WHERE telegram_id=?', [
                $from['username'] ?? null, $from['first_name'] ?? '', $from['last_name'] ?? '', $tg
            ]);
            return self::getUser($tg) ?? $u;
        }
        $referrer = null;
        if ($startParam && preg_match('/^ref_(\d+)$/', $startParam, $m) && $m[1] !== $tg) {
            $ref = self::getUser($m[1]);
            if ($ref && !(int)$ref['blocked']) $referrer = $ref['id'];
        }
        self::q('INSERT INTO users (telegram_id,username,first_name,last_name,referrer_user_id,created_at,last_seen_at) VALUES (?,?,?,?,?,NOW(),NOW())', [
            $tg, $from['username'] ?? null, $from['first_name'] ?? '', $from['last_name'] ?? '', $referrer
        ]);
        $u = self::getUser($tg);
        self::logEvent('new_user', 'کاربر جدید', ['telegram_id'=>$tg,'username'=>$from['username'] ?? null]);
        self::sendUserLog("👤 <b>کاربر جدید</b>\nنام: " . self::h(trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? '')) ?: 'کاربر تلگرام') . "\nآیدی عددی: <code>{$tg}</code>\nیوزرنیم: " . self::h(isset($from['username']) ? '@'.$from['username'] : 'ثبت نشده'), $tg);
        return $u;
    }

    public static function setState(int|string $tgId, ?string $state, array $data = []): void
    {
        self::q('UPDATE users SET state=?, state_data=? WHERE telegram_id=?', [$state, $state ? self::j($data) : null, (string)$tgId]);
    }

    public static function stateData(array $u): array
    {
        $d = json_decode((string)($u['state_data'] ?? ''), true);
        return is_array($d) ? $d : [];
    }

    public static function isJoinedAll(int|string $userId): array
    {
        $missing = [];
        foreach (self::all('SELECT * FROM forced_channels WHERE enabled=1 ORDER BY sort_order,id') as $ch) {
            try {
                $m = self::telegram('getChatMember', ['chat_id'=>$ch['chat_id'],'user_id'=>$userId]);
                $status = $m['status'] ?? 'left';
                $ok = in_array($status, ['creator','administrator','member'], true) || ($status === 'restricted' && ($m['is_member'] ?? false));
                if (!$ok) $missing[] = $ch;
            } catch (Throwable $e) {
                $missing[] = $ch;
                self::logEvent('forced_join_error', $e->getMessage(), ['chat_id'=>$ch['chat_id']]);
            }
        }
        return $missing;
    }

    public static function requireJoin(int|string $chatId, int|string $userId): bool
    {
        if (self::isAdmin($userId)) return true;
        $missing = self::isJoinedAll($userId);
        if (!$missing) return true;
        $rows = [];
        foreach ($missing as $ch) $rows[] = [['text'=>'عضویت در ' . $ch['title'], 'url'=>$ch['join_url']]];
        $rows[] = [['text'=>'✅ بررسی عضویت','callback_data'=>'check_join']];
        self::send($chatId, self::text('forced_join'), self::inline($rows));
        return false;
    }

    public static function showHome(int|string $chatId, array $u): void
    {
        if (array_key_exists('inline_menu_ready',$u) && !(int)$u['inline_menu_ready']) {
            try {
                self::send($chatId,'✅ منوی دکمه‌ای جدید فعال شد.',['remove_keyboard'=>true]);
                self::q('UPDATE users SET inline_menu_ready=1 WHERE id=?',[(int)$u['id']]);
            } catch (Throwable $e) { self::logEvent('legacy_keyboard_remove_error',$e->getMessage(),['user_id'=>$u['id']]); }
        }
        $name = self::h($u['first_name'] ?: 'کاربر');
        $balance = self::money($u['balance']);
        self::send($chatId, self::text('welcome', ['name'=>$name,'balance'=>$balance]), self::mainKeyboard($chatId));
    }

    public static function topupPresets(): array
    {
        $raw = (string)self::setting('topup_presets', '50000,100000,200000,500000,1000000');
        $values = [];
        foreach (preg_split('/[,\s]+/', $raw) ?: [] as $value) {
            $amount = (int)preg_replace('/\D+/', '', $value);
            if ($amount > 0) $values[$amount] = $amount;
        }
        if (!$values) $values = [50000,100000,200000,500000,1000000];
        return array_values($values);
    }

    public static function topupKeyboard(): array
    {
        $rows = []; $row = [];
        foreach (self::topupPresets() as $amount) {
            $row[] = self::button(self::money($amount), ['callback_data'=>'topup_amount:'.$amount], '');
            if (count($row) === 2) { $rows[] = $row; $row = []; }
        }
        if ($row) $rows[] = $row;
        $rows[] = [self::button('✍️ مبلغ دلخواه', ['callback_data'=>'topup_custom'], '')];
        $rows[] = [self::button('🏠 بازگشت', ['callback_data'=>'home'], 'primary')];
        return self::inline($rows);
    }

    public static function showTopupOptions(array $u, int|string $chatId): void
    {
        self::send($chatId, self::text('topup_choose_amount', ['min'=>self::money(self::setting('min_topup','10000'))]), self::topupKeyboard());
    }

    public static function showWallet(array $u, int|string $chatId): void
    {
        $orders = (int)(self::one("SELECT COUNT(*) c FROM orders WHERE user_id=? AND status='paid'", [$u['id']])['c'] ?? 0);
        $refs = (int)(self::one('SELECT COUNT(*) c FROM users WHERE referrer_user_id=?', [$u['id']])['c'] ?? 0);
        $name = trim(($u['first_name'] ?? '').' '.($u['last_name'] ?? '')) ?: 'کاربر تلگرام';
        $text = self::text('wallet_profile', [
            'name'=>self::h($name),
            'user_id'=>self::h($u['telegram_id']),
            'joined_at'=>self::h(self::formatDate((string)$u['created_at'])),
            'orders'=>$orders,
            'referrals'=>$refs,
            'balance'=>self::money($u['balance']),
        ]);
        $kb = self::inline([
            [self::button('💳 افزایش موجودی', ['callback_data'=>'topup'], 'success')],
            [self::button('📦 پک‌های من', ['callback_data'=>'my_packs'], ''), self::button('🛒 خرید فیلم', ['callback_data'=>'shop'], 'success')],
            [self::button('⬅️ بازگشت به منوی اصلی', ['callback_data'=>'home'], 'danger')],
        ]);
        try {
            $photos = self::telegram('getUserProfilePhotos', ['user_id'=>$u['telegram_id'],'limit'=>1]);
            $sizes = is_array($photos) ? ($photos['photos'][0] ?? []) : [];
            $last = $sizes ? end($sizes) : null;
            $photo = is_array($last) ? ($last['file_id'] ?? null) : null;
            if ($photo) {
                self::telegram('sendPhoto', ['chat_id'=>$chatId,'photo'=>$photo,'caption'=>$text,'parse_mode'=>'HTML','reply_markup'=>$kb]);
                return;
            }
        } catch (Throwable) {}
        self::send($chatId, $text, $kb);
    }

    public static function formatDate(?string $value): string
    {
        if (!$value) return 'ثبت نشده';
        $ts = strtotime($value);
        return $ts ? date('Y/m/d H:i', $ts) : self::h($value);
    }

    public static function sendUserLog(string $html, int|string $userId): void
    {
        $id = trim((string)self::setting('log_chat_id', ''));
        if ($id === '') return;
        $kb = self::inline([[self::button('👤 مدیریت کاربر', ['callback_data'=>'manage_user:'.(string)$userId], '')]]);
        try { self::send($id, $html, $kb); } catch (Throwable $e) { self::logEvent('log_send_error', $e->getMessage()); }
    }

    public static function sendLog(string $html): void
    {
        $id = trim((string)self::setting('log_chat_id', ''));
        if ($id === '') return;
        try { self::send($id, $html); } catch (Throwable $e) { self::logEvent('log_send_error', $e->getMessage()); }
    }

    public static function logEvent(string $type, string $message, array $meta = []): void
    {
        try { self::q('INSERT INTO logs (`type`,`message`,`meta`,`created_at`) VALUES (?,?,?,NOW())', [$type,$message,self::j($meta)]); } catch (Throwable) {}
    }

    public static function userDisplay(array $u): string
    {
        $name = trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: 'بدون نام';
        $username = $u['username'] ? '@'.$u['username'] : 'ندارد';
        return self::h($name) . "\nیوزرنیم: " . self::h($username) . "\nآیدی: <code>" . self::h($u['telegram_id']) . '</code>';
    }

    public static function showCategories(int|string $chatId): void
    {
        $rows=[];
        foreach (self::all('SELECT * FROM categories WHERE enabled=1 ORDER BY sort_order,id') as $c) {
            $rows[]=[['text'=>$c['title'],'callback_data'=>'cat:'.$c['id']]];
        }
        if (!$rows) { self::send($chatId, self::text('no_categories'), self::mainKeyboard($chatId)); return; }
        $rows[]=[['text'=>'🏠 بازگشت','callback_data'=>'home']];
        self::send($chatId, self::text('select_category'), self::inline($rows));
    }

    public static function channelContentStats(string $channelId): array
    {
        if ($channelId==='') return ['videos'=>0,'photos'=>0,'files'=>0,'total'=>0];
        $row=self::one("SELECT
            SUM(media_type='video') videos,
            SUM(media_type='photo') photos,
            SUM(media_type IN ('document','animation','audio')) files,
            COUNT(*) total
            FROM channel_posts WHERE chat_id=?",[$channelId]) ?: [];
        return [
            'videos'=>(int)($row['videos']??0),
            'photos'=>(int)($row['photos']??0),
            'files'=>(int)($row['files']??0),
            'total'=>(int)($row['total']??0),
        ];
    }

    public static function showProducts(int|string $chatId, int $categoryId, ?int $messageId = null): void
    {
        $cat = self::one('SELECT * FROM categories WHERE id=? AND enabled=1', [$categoryId]);
        if (!$cat) { self::send($chatId,'دسته‌بندی پیدا نشد.'); return; }
        $rows=[];
        $products=self::all('SELECT * FROM products WHERE category_id=? AND enabled=1 ORDER BY sort_order,id',[$categoryId]);
        if ($products) {
            // Telegram renders Persian inline rows RTL, so the second stored cell is shown on the right.
            $rows[]=[
                self::button('مبلغ',['callback_data'=>'product_header'],'primary'),
                self::button('نام محصول',['callback_data'=>'product_header'],'primary'),
            ];
            foreach ($products as $p) {
                $callback=['callback_data'=>'buy:'.$p['id'],'style_key'=>'product_item'];
                $rows[]=[
                    self::button(self::money((float)$p['price']),$callback,'primary'),
                    self::button((string)$p['title'],$callback,'primary'),
                ];
            }
        }
        if (!$rows) $rows[]=[['text'=>'محصولی موجود نیست','callback_data'=>'noop']];
        $rows[]=[['text'=>'⬅️ دسته‌بندی‌ها','callback_data'=>'shop'],['text'=>'🏠 خانه','callback_data'=>'home']];
        $text=self::text('select_product',['category'=>self::h($cat['title'])]);
        $messageId ? self::edit($chatId,$messageId,$text,self::inline($rows)) : self::send($chatId,$text,self::inline($rows));
    }

    public static function showProduct(int|string $chatId, int $productId, ?int $messageId = null): void
    {
        $p=self::one('SELECT p.*,c.title category_title FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=? AND p.enabled=1',[$productId]);
        if(!$p){ self::send($chatId,'محصول در دسترس نیست.'); return; }
        $media=self::channelContentStats((string)$p['channel_id']);
        $text=self::text('product_detail',[
            'title'=>self::h($p['title']),'category'=>self::h($p['category_title']),'description'=>self::h($p['description']),
            'price'=>self::money($p['price']),'videos'=>number_format($media['videos']),'photos'=>number_format($media['photos'])
        ]);
        $kb=self::inline([
            [self::button('🛒 خرید محصول',['callback_data'=>'buy:'.$p['id']],'success')],
            [['text'=>'⬅️ محصولات','callback_data'=>'cat:'.$p['category_id']],['text'=>'🏠 خانه','callback_data'=>'home']]
        ]);
        if ($p['image_url'] && !$messageId) {
            try { self::telegram('sendPhoto',['chat_id'=>$chatId,'photo'=>$p['image_url'],'caption'=>$text,'parse_mode'=>'HTML','reply_markup'=>$kb]); return; } catch(Throwable) {}
        }
        $messageId ? self::edit($chatId,$messageId,$text,$kb) : self::send($chatId,$text,$kb);
    }

    public static function createTopup(array $u, float $amount, ?int $productId = null): int
    {
        self::q("INSERT INTO topups (user_id,amount,status,desired_product_id,created_at) VALUES (?,?, 'waiting_receipt', ?, NOW())",[$u['id'],$amount,$productId]);
        $id=(int)self::db()->lastInsertId();
        SmartPayment::prepareTopup($id,$amount);
        return $id;
    }

    public static function showInvoice(int|string $chatId, array $u, int $topupId): void
    {
        $t=self::one('SELECT t.*,p.title product_title,p.channel_id product_channel FROM topups t LEFT JOIN products p ON p.id=t.desired_product_id WHERE t.id=? AND t.user_id=?',[$topupId,$u['id']]);
        if(!$t) return;
        $cards=self::all('SELECT * FROM cards WHERE enabled=1 ORDER BY sort_order,id');
        $cardText=''; $rows=[];
        foreach($cards as $i=>$c){
            $n=$i+1;
            $cardText.="

<b>کارت {$n}</b>
<code>".self::h($c['card_number'])."</code>
".self::h($c['holder_name'] ?: $c['label']);
        }
        $product=$t['product_title'] ?: 'افزایش موجودی کیف پول';
        $media=!empty($t['product_channel'])?self::channelContentStats((string)$t['product_channel']):['videos'=>null,'photos'=>null];
        $payable=(float)($t['payable_amount']?:$t['amount']);
        $copyAmount=self::button('📋 کپی مبلغ',['copy_text'=>['text'=>(string)(int)round($payable)]],'primary');
        if ($cards) {
            $first=$cards[0];
            $rows[]=[
                $copyAmount,
                self::button('💳 کپی شماره کارت',['copy_text'=>['text'=>$first['card_number']]],'primary'),
            ];
            foreach(array_slice($cards,1) as $i=>$c){
                $rows[]=[self::button('💳 کپی شماره کارت '.($i+2),['copy_text'=>['text'=>$c['card_number']]],'primary')];
            }
        } else {
            $rows[]=[ $copyAmount ];
        }
        $mode=(string)($t['payment_mode']?:SmartPayment::mode());
        $notice=$mode==='smart_sms'
            ? '⚠️ <b>مبلغ را دقیقاً همان عدد بالا واریز کن.</b> سپس «پرداخت کردم» را بزن و تصویر رسید را بفرست؛ ربات مبلغ و زمان رسید را با پیامک بانک تطبیق می‌دهد.'
            : ($mode==='amount_unique'?'⚠️ <b>مبلغ را دقیقاً همان عدد بالا واریز کن.</b> سپس «پرداخت کردم» را بزن تا مبلغ یکتا با پیامک بانک تطبیق داده شود.':($mode==='blind_auto'?'بعد از واریز، «پرداخت کردم» را بزن تا فاکتور به‌صورت خودکار تأیید شود.':'بعد از واریز، «پرداخت کردم» را بزن و رسید را ارسال کن.'));
        $text=self::text('invoice',[
            'invoice_id'=>(int)$t['id'],'amount'=>self::money($payable),'base_amount'=>self::money((float)$t['amount']),'product'=>self::h($product),'cards'=>$cardText,
            'videos'=>$media['videos']===null?'—':number_format((int)$media['videos']),'photos'=>$media['photos']===null?'—':number_format((int)$media['photos']),
            'payment_mode'=>self::h(SmartPayment::modeLabel($mode)),'payment_notice'=>$notice
        ]);
        $payButton=((string)$t['payment_mode']==='smart_sms')?'✅ پرداخت کردم | ارسال رسید هوشمند':(((string)$t['payment_mode']==='amount_unique')?'✅ پرداخت کردم | بررسی مبلغ یکتا':(((string)$t['payment_mode']==='blind_auto')?'✅ پرداخت کردم | تایید خودکار':'✅ پرداخت کردم | ارسال رسید'));
        $rows[]=[self::button($payButton,['callback_data'=>'paid:'.$topupId],'success')];
        $rows[]=[['text'=>'❌ انصراف','callback_data'=>'cancel_topup:'.$topupId]];
        self::send($chatId,$text,self::inline($rows));
    }

    public static function notifyReceipt(int $topupId): void
    {
        $t=self::one('SELECT t.*,u.telegram_id,u.username,u.first_name,u.last_name,p.title product_title FROM topups t JOIN users u ON u.id=t.user_id LEFT JOIN products p ON p.id=t.desired_product_id WHERE t.id=?',[$topupId]);
        if(!$t)return;
        $caption="🧾 <b>رسید جدید #{$t['id']}</b>\n".self::userDisplay($t)."\nمبلغ: <b>".self::money((float)($t['payable_amount']?:$t['amount']))."</b>\nمحصول: ".self::h($t['product_title'] ?: 'افزایش موجودی');
        $kb=self::inline([
            [self::button('✅ تایید',['callback_data'=>'topup_ok:'.$t['id']],'success'),self::button('❌ رد',['callback_data'=>'topup_reject:'.$t['id']],'danger'),self::button('🚫 بلاک',['callback_data'=>'topup_block:'.$t['id']],'danger')],
            [self::button('👤 مدیریت کاربر',['callback_data'=>'manage_user:'.$t['telegram_id']],'')]
        ]);
        foreach(self::all('SELECT telegram_id FROM admins WHERE enabled=1') as $a){
            try{
                if($t['receipt_type']==='photo') self::telegram('sendPhoto',['chat_id'=>$a['telegram_id'],'photo'=>$t['receipt_file_id'],'caption'=>$caption,'parse_mode'=>'HTML','reply_markup'=>$kb]);
                else self::telegram('sendDocument',['chat_id'=>$a['telegram_id'],'document'=>$t['receipt_file_id'],'caption'=>$caption,'parse_mode'=>'HTML','reply_markup'=>$kb]);
            }catch(Throwable $e){self::logEvent('receipt_notify_error',$e->getMessage());}
        }
        $log=trim((string)self::setting('log_chat_id',''));
        if($log!==''){
            try{
                if($t['receipt_type']==='photo') self::telegram('sendPhoto',['chat_id'=>$log,'photo'=>$t['receipt_file_id'],'caption'=>$caption,'parse_mode'=>'HTML','reply_markup'=>$kb]);
                else self::telegram('sendDocument',['chat_id'=>$log,'document'=>$t['receipt_file_id'],'caption'=>$caption,'parse_mode'=>'HTML','reply_markup'=>$kb]);
            }catch(Throwable){}
        }
        self::logEvent('topup_receipt','رسید افزایش موجودی ثبت شد',['topup_id'=>$t['id'],'telegram_id'=>$t['telegram_id'],'amount'=>(float)($t['payable_amount']?:$t['amount'])]);
    }

    public static function approveTopup(int $id, int|string $adminTg): string
    {
        $pdo=self::db(); $pdo->beginTransaction();
        try{
            $t=self::one('SELECT * FROM topups WHERE id=? FOR UPDATE',[$id]);
            if(!$t || !in_array($t['status'],['waiting_receipt','pending'],true)){ $pdo->rollBack(); return 'این پرداخت قبلاً بررسی شده است.'; }
            $credit=(float)($t['payable_amount']?:$t['amount']);
            self::q("UPDATE topups SET status='approved',reviewed_by=?,reviewed_at=NOW() WHERE id=?",[(string)$adminTg,$id]);
            self::q('UPDATE users SET balance=balance+? WHERE id=?',[$credit,$t['user_id']]);
            self::q("INSERT INTO wallet_transactions (user_id,type,amount,reference_type,reference_id,description,created_at) VALUES (?,'topup',?,'topup',?,'تایید افزایش موجودی',NOW())",[$t['user_id'],$credit,$id]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        $u=self::one('SELECT * FROM users WHERE id=?',[$t['user_id']]);
        if($u) self::send($u['telegram_id'],self::text('topup_approved',['amount'=>self::money($credit??$t['amount']),'balance'=>self::money($u['balance'])]),self::mainKeyboard($u['telegram_id']));
        self::sendUserLog("✅ <b>افزایش موجودی تایید شد</b>\nرسید: #{$id}\nمبلغ: ".self::money($credit??$t['amount'])."\nادمین: <code>".self::h($adminTg).'</code>', $u['telegram_id'] ?? '0');
        if(!empty($t['desired_product_id']) && $u){
            try{ self::purchase($u,(int)$t['desired_product_id'],true); }catch(Throwable $e){ self::send($u['telegram_id'],'موجودی تایید شد؛ برای خرید محصول دوباره از فروشگاه اقدام کنید.'); }
        }
        return 'رسید تایید شد.';
    }

    public static function rejectTopup(int $id, int|string $adminTg, string $reason, bool $block=false): string
    {
        $t=self::one('SELECT t.*,u.telegram_id FROM topups t JOIN users u ON u.id=t.user_id WHERE t.id=?',[$id]);
        if(!$t || $t['status']!=='pending') return 'این رسید قبلاً بررسی شده است.';
        self::q("UPDATE topups SET status='rejected',reason=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?",[$reason,(string)$adminTg,$id]);
        if($block) self::q('UPDATE users SET blocked=1 WHERE id=?',[$t['user_id']]);
        self::send($t['telegram_id'],self::text($block?'blocked_by_admin':'topup_rejected',['reason'=>self::h($reason)]));
        self::sendUserLog(($block?'🚫 <b>کاربر بلاک شد</b>':'❌ <b>رسید رد شد</b>')."\nرسید: #{$id}\nدلیل: ".self::h($reason), $t['telegram_id']);
        return $block?'رسید رد و کاربر بلاک شد.':'رسید رد شد.';
    }

    public static function purchase(array $u, int $productId, bool $fromApprovedTopup=false): void
    {
        $p=self::one('SELECT * FROM products WHERE id=? AND enabled=1',[$productId]);
        if(!$p){ self::send($u['telegram_id'],'این محصول در دسترس نیست.'); return; }
        $price=(float)$p['price'];
        $fresh=self::getUser($u['telegram_id']); if(!$fresh)return;
        if((float)$fresh['balance']+0.0001<$price){
            $short=$price-(float)$fresh['balance'];
            $kb=self::inline([[['text'=>'💳 افزایش موجودی '.self::money($short),'callback_data'=>'topup_product:'.$productId]],[['text'=>'⬅️ بازگشت','callback_data'=>'product:'.$productId]]]);
            self::send($u['telegram_id'],self::text('low_balance',['balance'=>self::money($fresh['balance']),'price'=>self::money($price),'shortage'=>self::money($short)]),$kb);
            return;
        }
        $pdo=self::db(); $pdo->beginTransaction();
        try{
            $locked=self::one('SELECT * FROM users WHERE id=? FOR UPDATE',[$fresh['id']]);
            if(!$locked || (float)$locked['balance']+0.0001<$price){$pdo->rollBack();self::send($u['telegram_id'],'موجودی کافی نیست.');return;}
            self::q('UPDATE users SET balance=balance-? WHERE id=?',[$price,$fresh['id']]);
            self::q("INSERT INTO orders (user_id,product_id,amount,status,max_invite_uses,created_at,paid_at) VALUES (?,?,?,'creating',?,NOW(),NOW())",[$fresh['id'],$productId,$price,(int)$p['invite_max_uses']]);
            $orderId=(int)$pdo->lastInsertId();
            self::q("INSERT INTO wallet_transactions (user_id,type,amount,reference_type,reference_id,description,created_at) VALUES (?,'purchase',?,'order',?,'خرید محصول',NOW())",[$fresh['id'],-$price,$orderId]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

        $createdInvite='';
        try{
            $expire=time()+max(1,(int)$p['invite_expire_hours'])*3600;
            $link=self::telegram('createChatInviteLink',[
                'chat_id'=>$p['channel_id'],
                'name'=>substr('order-'.$orderId,0,32),
                'expire_date'=>$expire,
                'creates_join_request'=>true,
            ]);
            $invite=$link['invite_link']??'';
            if($invite==='')throw new RuntimeException('لینک عضویت ساخته نشد');
            $createdInvite=$invite;
            self::q("UPDATE orders SET status='paid',invite_link=?,invite_expire_at=FROM_UNIXTIME(?) WHERE id=?",[$invite,$expire,$orderId]);
            self::trackInviteLink($productId,$orderId,$invite);
        }catch(Throwable $e){
            if($createdInvite!==''){try{self::telegram('revokeChatInviteLink',['chat_id'=>$p['channel_id'],'invite_link'=>$createdInvite]);}catch(Throwable){}}
            $pdo=self::db();$pdo->beginTransaction();
            try{
                self::q("UPDATE orders SET status='failed',failure_reason=? WHERE id=?",[$e->getMessage(),$orderId]);
                self::q('UPDATE users SET balance=balance+? WHERE id=?',[$price,$fresh['id']]);
                self::q("INSERT INTO wallet_transactions (user_id,type,amount,reference_type,reference_id,description,created_at) VALUES (?,'refund',?,'order',?,'بازگشت وجه به علت خطای لینک',NOW())",[$fresh['id'],$price,$orderId]);
                $pdo->commit();
            }catch(Throwable $x){if($pdo->inTransaction())$pdo->rollBack();}
            self::send($u['telegram_id'],'❌ لینک دسترسی ساخته نشد و مبلغ کامل به کیف پول شما برگشت داده شد. لطفاً مدیر را مطلع کنید.');
            self::sendLog('⚠️ خطا در ساخت لینک سفارش #'.$orderId.'<br>'.self::h($e->getMessage()));
            return;
        }
        try{self::applyReferral($fresh,$price,$orderId);}catch(Throwable $e){self::logEvent('referral_error',$e->getMessage(),['order'=>$orderId]);}
        $order=self::one('SELECT * FROM orders WHERE id=?',[$orderId]);
        $kb=self::inline([[['text'=>'🎬 درخواست عضویت در کانال','url'=>$order['invite_link']]],[['text'=>'📦 پک‌های من','callback_data'=>'my_packs']]]);
        self::send($u['telegram_id'],self::text('order_success',['product'=>self::h($p['title']),'amount'=>self::money($price),'order_id'=>$orderId]),$kb);
        self::sendUserLog("🛒 <b>سفارش جدید</b>\nسفارش: #{$orderId}\n".self::userDisplay($fresh)."\nمحصول: ".self::h($p['title'])."\nمبلغ: ".self::money($price), $fresh['telegram_id']);
        self::sendProof($fresh,$p,$orderId,$price);
    }

    public static function applyReferral(array $buyer, float $amount, int $orderId): void
    {
        if(empty($buyer['referrer_user_id']))return;
        $ref=self::one('SELECT * FROM users WHERE id=? AND blocked=0',[$buyer['referrer_user_id']]);
        if(!$ref)return;
        $percent=$ref['referral_percent_override']!==null?(float)$ref['referral_percent_override']:(float)self::setting('referral_percent','0');
        $fixed=$ref['referral_fixed_override']!==null?(float)$ref['referral_fixed_override']:(float)self::setting('referral_fixed','0');
        $commission=round($amount*$percent/100+$fixed,0);
        if($commission<=0)return;
        self::q('UPDATE users SET balance=balance+? WHERE id=?',[$commission,$ref['id']]);
        self::q("INSERT INTO wallet_transactions (user_id,type,amount,reference_type,reference_id,description,created_at) VALUES (?,'referral',?,'order',?,'پورسانت زیرمجموعه',NOW())",[$ref['id'],$commission,$orderId]);
        try{self::send($ref['telegram_id'],self::text('referral_commission',['amount'=>self::money($commission),'order_id'=>$orderId]));}catch(Throwable){}
        self::sendLog("👥 پورسانت زیرمجموعه پرداخت شد\nبه: <code>{$ref['telegram_id']}</code>\nمبلغ: ".self::money($commission)."\nسفارش: #{$orderId}");
    }

    public static function sendProof(array $u,array $p,int $orderId,float $price):void
    {
        $chat=trim((string)self::setting('proof_chat_id',''));
        if($chat==='')return;
        $name=trim(($u['first_name']??'').' '.($u['last_name']??''))?:'کاربر';
        $text=self::text('proof_purchase',[
            'name'=>self::h($name),'user_id'=>self::h($u['telegram_id']),'product'=>self::h($p['title']),
            'amount'=>self::money($price),'date'=>self::h(date('Y/m/d H:i')),'order_id'=>$orderId
        ]);
        try{self::send($chat,$text);}catch(Throwable $e){self::logEvent('proof_error',$e->getMessage());}
    }

    public static function showPacks(array $u,int|string $chatId):void
    {
        $orders=self::all("SELECT o.*,p.title FROM orders o JOIN products p ON p.id=o.product_id WHERE o.user_id=? AND o.status='paid' ORDER BY o.id DESC LIMIT 50",[$u['id']]);
        if(!$orders){self::send($chatId,self::text('my_packs_empty'),self::inline([[self::button('🛒 خرید فیلم',['callback_data'=>'shop'],'success')],[self::button('🏠 خانه',['callback_data'=>'home'],'primary')]]));return;}
        $rows=[];$text="📦 <b>پک‌های خریداری‌شده شما</b>\n";
        foreach($orders as $o){
            $remaining=max(0,(int)$o['max_invite_uses']-(int)$o['invite_uses']);
            $text.="\n#{$o['id']} — <b>".self::h($o['title'])."</b>\nاستفاده باقی‌مانده لینک: {$remaining}\n";
            if($remaining>0)$rows[]=[['text'=>'🔗 لینک '.self::h($o['title']),'callback_data'=>'order_link:'.$o['id']]];
        }
        $rows[]=[['text'=>'🏠 خانه','callback_data'=>'home']];
        self::send($chatId,$text,self::inline($rows));
    }

    public static function orderLink(array $u,int $orderId):void
    {
        $o=self::one("SELECT o.*,p.title,p.channel_id,p.invite_expire_hours FROM orders o JOIN products p ON p.id=o.product_id WHERE o.id=? AND o.user_id=? AND o.status='paid'",[$orderId,$u['id']]);
        if(!$o){self::send($u['telegram_id'],'سفارش پیدا نشد.');return;}
        if((int)$o['invite_uses']>=(int)$o['max_invite_uses']){self::send($u['telegram_id'],'ظرفیت دو استفاده این لینک تکمیل شده است.');return;}
        $expired=$o['invite_expire_at'] && strtotime($o['invite_expire_at'])<=time();
        if($expired || empty($o['invite_link']) || (int)$o['invite_revoked']){
            try{
                $expire=time()+max(1,(int)$o['invite_expire_hours'])*3600;
                $link=self::telegram('createChatInviteLink',['chat_id'=>$o['channel_id'],'name'=>substr('order-'.$orderId.'-'.time(),0,32),'expire_date'=>$expire,'creates_join_request'=>true]);
                self::q('UPDATE orders SET invite_link=?,invite_expire_at=FROM_UNIXTIME(?),invite_revoked=0 WHERE id=?',[$link['invite_link'],$expire,$orderId]);
                self::trackInviteLink((int)$o['product_id'],$orderId,(string)$link['invite_link']);
                $o['invite_link']=$link['invite_link'];
            }catch(Throwable $e){self::send($u['telegram_id'],'ساخت لینک جدید ممکن نشد: '.self::h($e->getMessage()));return;}
        }
        self::send($u['telegram_id'],"🎬 لینک اختصاصی سفارش #{$orderId}\nاین لینک حداکثر برای دو کاربر متفاوت تایید می‌شود.",self::inline([[['text'=>'درخواست عضویت','url'=>$o['invite_link']]]]));
    }

    public static function trackInviteLink(int $productId, int $orderId, string $link): void
    {
        if($link==='')return;
        try{self::q('INSERT IGNORE INTO invite_link_events(product_id,order_id,link_hash,created_at) VALUES (?,?,?,NOW())',[$productId,$orderId,hash('sha256',$link)]);}catch(Throwable $e){self::logEvent('invite_track_error',$e->getMessage(),['product_id'=>$productId,'order_id'=>$orderId]);}
    }

    public static function trackChannelPost(array $post,string $source='telegram_update'): void
    {
        $chatId=(string)($post['chat']['id']??'');$messageId=(int)($post['message_id']??0);
        if($chatId===''||$messageId<=0||!self::one('SELECT id FROM products WHERE channel_id=? LIMIT 1',[$chatId]))return;
        $type='text';$fileId=null;$fileSize=0;
        if(isset($post['video'])){$type='video';$fileId=$post['video']['file_id']??null;$fileSize=(int)($post['video']['file_size']??0);}
        elseif(isset($post['photo'])){$type='photo';$photos=$post['photo'];$photo=end($photos);$fileId=$photo['file_id']??null;$fileSize=(int)($photo['file_size']??0);}
        elseif(isset($post['animation'])){$type='animation';$fileId=$post['animation']['file_id']??null;$fileSize=(int)($post['animation']['file_size']??0);}
        elseif(isset($post['document'])){$type='document';$fileId=$post['document']['file_id']??null;$fileSize=(int)($post['document']['file_size']??0);}
        elseif(isset($post['audio'])){$type='audio';$fileId=$post['audio']['file_id']??null;$fileSize=(int)($post['audio']['file_size']??0);}
        $postedAt=date('Y-m-d H:i:s',(int)($post['date']??time()));
        self::q('INSERT INTO channel_posts(chat_id,message_id,media_type,file_id,file_size,source,posted_at) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE media_type=VALUES(media_type),file_id=VALUES(file_id),file_size=VALUES(file_size),source=VALUES(source),posted_at=VALUES(posted_at)',[$chatId,$messageId,$type,$fileId,$fileSize,$source,$postedAt]);
    }

    public static function handleJoinRequest(array $jr):void
    {
        $link=$jr['invite_link']['invite_link']??'';
        if($link==='')return;
        $uid=(string)$jr['from']['id'];
        $chatId=$jr['chat']['id'];
        $pdo=self::db();
        $existing=false;$count=0;$max=0;$orderId=0;
        try{
            $pdo->beginTransaction();
            $o=self::one("SELECT o.*,p.channel_id FROM orders o JOIN products p ON p.id=o.product_id WHERE o.invite_link=? AND o.status='paid' FOR UPDATE",[$link]);
            if(!$o){$pdo->rollBack();return;}
            $orderId=(int)$o['id'];$max=(int)$o['max_invite_uses'];
            $used=self::one('SELECT id FROM invite_uses WHERE order_id=? AND user_telegram_id=?',[$orderId,$uid]);
            if($used){$existing=true;$count=(int)$o['invite_uses'];$pdo->commit();}
            else{
                $count=(int)$o['invite_uses'];
                if($count >= $max){$pdo->commit();try{self::telegram('declineChatJoinRequest',['chat_id'=>$chatId,'user_id'=>$uid]);}catch(Throwable){}return;}
                self::q('INSERT INTO invite_uses (order_id,user_telegram_id,approved_at) VALUES (?,?,NOW())',[$orderId,$uid]);
                $count++;
                self::q('UPDATE orders SET invite_uses=? WHERE id=?',[$count,$orderId]);
                $pdo->commit();
            }
            self::telegram('approveChatJoinRequest',['chat_id'=>$chatId,'user_id'=>$uid]);
            if($count >= $max){
                try{self::telegram('revokeChatInviteLink',['chat_id'=>$chatId,'invite_link'=>$link]);}catch(Throwable){}
                self::q('UPDATE orders SET invite_revoked=1 WHERE id=?',[$orderId]);
            }
            self::sendLog("🔓 عضویت کانال تایید شد\nسفارش: #{$orderId}\nکاربر واردشونده: <code>{$uid}</code>\nاستفاده: {$count}/{$max}");
        }catch(Throwable $e){
            if($pdo->inTransaction())$pdo->rollBack();
            if(!$existing && $orderId>0){
                try{
                    $pdo->beginTransaction();
                    self::q('DELETE FROM invite_uses WHERE order_id=? AND user_telegram_id=?',[$orderId,$uid]);
                    $real=(int)(self::one('SELECT COUNT(*) c FROM invite_uses WHERE order_id=?',[$orderId])['c']??0);
                    self::q('UPDATE orders SET invite_uses=?,invite_revoked=0 WHERE id=?',[$real,$orderId]);
                    $pdo->commit();
                }catch(Throwable){if($pdo->inTransaction())$pdo->rollBack();}
            }
            self::logEvent('join_approve_error',$e->getMessage(),['order'=>$orderId,'user'=>$uid]);
        }
    }

    public static function referralInfo(array $u):void
    {
        $bot=self::setting('bot_username','');
        $link='https://t.me/'.$bot.'?start=ref_'.$u['telegram_id'];
        $count=(int)(self::one('SELECT COUNT(*) c FROM users WHERE referrer_user_id=?',[$u['id']])['c']??0);
        $earned=(float)(self::one("SELECT COALESCE(SUM(amount),0) s FROM wallet_transactions WHERE user_id=? AND type='referral'",[$u['id']])['s']??0);
        $percent=$u['referral_percent_override']!==null?$u['referral_percent_override']:self::setting('referral_percent','0');
        $fixed=$u['referral_fixed_override']!==null?$u['referral_fixed_override']:self::setting('referral_fixed','0');
        $text=self::text('referral_info',['link'=>$link,'count'=>$count,'earned'=>self::money($earned),'percent'=>$percent,'fixed'=>self::money($fixed)]);
        self::send($u['telegram_id'],$text,self::inline([[['text'=>'📋 کپی لینک','copy_text'=>['text'=>$link]]],[self::button('⬅️ بازگشت به منوی اصلی',['callback_data'=>'home'],'danger')]]));
    }

    public static function startSupport(array $u):void
    {
        self::setState($u['telegram_id'],'support_wait');
        self::send($u['telegram_id'],self::text('support_prompt'),self::inline([[['text'=>'❌ لغو','callback_data'=>'cancel_state']]]));
    }

    public static function saveSupportMessage(array $u,array $msg):void
    {
        $ticket=self::one("SELECT * FROM tickets WHERE user_id=? AND status='open' ORDER BY id DESC LIMIT 1",[$u['id']]);
        if(!$ticket){self::q("INSERT INTO tickets (user_id,status,created_at,updated_at) VALUES (?,'open',NOW(),NOW())",[$u['id']]);$ticket=['id'=>(int)self::db()->lastInsertId()];}
        [$type,$text,$file]=self::extractMessage($msg);
        self::q("INSERT INTO ticket_messages (ticket_id,sender_type,message_type,text,file_id,created_at) VALUES (?,'user',?,?,?,NOW())",[$ticket['id'],$type,$text,$file]);
        self::q('UPDATE tickets SET updated_at=NOW() WHERE id=?',[$ticket['id']]);
        self::setState($u['telegram_id'],null);
        $caption="🎫 <b>پیام پشتیبانی #{$ticket['id']}</b>\n".self::userDisplay($u)."\n\n".self::h($text ?: '['.$type.']');
        $kb=self::inline([[['text'=>'✍️ پاسخ','callback_data'=>'support_reply:'.$ticket['id']],['text'=>'✅ بستن','callback_data'=>'support_close:'.$ticket['id']]]]);
        foreach(self::all('SELECT telegram_id FROM admins WHERE enabled=1') as $a){
            try{
                if($type==='photo')self::telegram('sendPhoto',['chat_id'=>$a['telegram_id'],'photo'=>$file,'caption'=>$caption,'parse_mode'=>'HTML','reply_markup'=>$kb]);
                elseif($type==='document')self::telegram('sendDocument',['chat_id'=>$a['telegram_id'],'document'=>$file,'caption'=>$caption,'parse_mode'=>'HTML','reply_markup'=>$kb]);
                else self::send($a['telegram_id'],$caption,$kb);
            }catch(Throwable){}
        }
        self::send($u['telegram_id'],self::text('support_sent'),self::mainKeyboard($u['telegram_id']));
        self::sendLog("🎫 تیکت پشتیبانی جدید #{$ticket['id']}\nکاربر: <code>{$u['telegram_id']}</code>");
    }

    public static function extractMessage(array $msg):array
    {
        if(isset($msg['photo'])){ $p=end($msg['photo']); return ['photo',$msg['caption']??'', $p['file_id']]; }
        if(isset($msg['document']))return ['document',$msg['caption']??'', $msg['document']['file_id']];
        return ['text',$msg['text']??'',null];
    }

    public static function statistics(): array
    {
        $periods = ['today'=>0,'week'=>6,'month'=>29];
        $out = [];
        foreach ($periods as $key=>$days) {
            $date = date('Y-m-d 00:00:00', strtotime('-'.$days.' days'));
            $out[$key] = [
                'users'=>(int)(self::one('SELECT COUNT(*) c FROM users WHERE created_at>=?',[$date])['c']??0),
                'orders'=>(int)(self::one("SELECT COUNT(*) c FROM orders WHERE status='paid' AND paid_at>=?",[$date])['c']??0),
                'sales'=>(float)(self::one("SELECT COALESCE(SUM(amount),0) s FROM orders WHERE status='paid' AND paid_at>=?",[$date])['s']??0),
                'topups'=>(float)(self::one("SELECT COALESCE(SUM(amount),0) s FROM topups WHERE status='approved' AND reviewed_at>=?",[$date])['s']??0),
            ];
        }
        $out['all'] = [
            'users'=>(int)(self::one('SELECT COUNT(*) c FROM users')['c']??0),
            'orders'=>(int)(self::one("SELECT COUNT(*) c FROM orders WHERE status='paid'")['c']??0),
            'sales'=>(float)(self::one("SELECT COALESCE(SUM(amount),0) s FROM orders WHERE status='paid'")['s']??0),
            'pending'=>(int)(self::one("SELECT COUNT(*) c FROM topups WHERE status='pending'")['c']??0),
            'tickets'=>(int)(self::one("SELECT COUNT(*) c FROM tickets WHERE status='open'")['c']??0),
        ];
        return $out;
    }

    public static function statisticsText(): string
    {
        $s = self::statistics();
        return "📊 <b>آمار فروشگاه</b>\n\n".
            "<b>امروز</b>\nکاربر جدید: {$s['today']['users']} | سفارش: {$s['today']['orders']}\nفروش: ".self::money($s['today']['sales'])."\nشارژ تاییدشده: ".self::money($s['today']['topups'])."\n\n".
            "<b>۷ روز اخیر</b>\nکاربر جدید: {$s['week']['users']} | سفارش: {$s['week']['orders']}\nفروش: ".self::money($s['week']['sales'])."\nشارژ تاییدشده: ".self::money($s['week']['topups'])."\n\n".
            "<b>۳۰ روز اخیر</b>\nکاربر جدید: {$s['month']['users']} | سفارش: {$s['month']['orders']}\nفروش: ".self::money($s['month']['sales'])."\nشارژ تاییدشده: ".self::money($s['month']['topups'])."\n\n".
            "<b>کل</b>\nکاربران: {$s['all']['users']} | سفارش‌ها: {$s['all']['orders']}\nفروش کل: ".self::money($s['all']['sales'])."\nرسید معلق: {$s['all']['pending']} | تیکت باز: {$s['all']['tickets']}";
    }

    public static function findUser(string $query): ?array
    {
        $query = trim($query);
        if ($query === '') return null;
        if (preg_match('/^\d+$/', $query)) return self::one('SELECT * FROM users WHERE telegram_id=? OR id=? LIMIT 1',[$query,(int)$query]);
        $query = ltrim($query,'@');
        return self::one('SELECT * FROM users WHERE username=? OR first_name LIKE ? OR last_name LIKE ? ORDER BY id DESC LIMIT 1',[$query,'%'.$query.'%','%'.$query.'%']);
    }

    public static function showAdminUser(int|string $adminChatId, int $userId): void
    {
        $u = self::one('SELECT * FROM users WHERE id=?',[$userId]);
        if (!$u) { self::send($adminChatId,'کاربر پیدا نشد.'); return; }
        $orders = (int)(self::one("SELECT COUNT(*) c FROM orders WHERE user_id=? AND status='paid'",[$u['id']])['c']??0);
        $spent = (float)(self::one("SELECT COALESCE(SUM(amount),0) s FROM orders WHERE user_id=? AND status='paid'",[$u['id']])['s']??0);
        $refs = (int)(self::one('SELECT COUNT(*) c FROM users WHERE referrer_user_id=?',[$u['id']])['c']??0);
        $topups = (float)(self::one("SELECT COALESCE(SUM(amount),0) s FROM topups WHERE user_id=? AND status='approved'",[$u['id']])['s']??0);
        $text = "👤 <b>مدیریت کاربر</b>\n\n".self::userDisplay($u).
            "\nتاریخ عضویت: ".self::formatDate($u['created_at']).
            "\nآخرین فعالیت: ".self::formatDate($u['last_seen_at']).
            "\nوضعیت: <b>".((int)$u['blocked']?'مسدود':'فعال')."</b>".
            "\nموجودی: <b>".self::money($u['balance'])."</b>".
            "\nخرید موفق: <b>{$orders}</b> | مجموع خرید: <b>".self::money($spent)."</b>".
            "\nشارژ تاییدشده: <b>".self::money($topups)."</b>".
            "\nزیرمجموعه‌ها: <b>{$refs}</b>";
        $rows = [
            [self::button('➕ افزایش موجودی',['callback_data'=>'adm_user_addbal:'.$u['id']],'success'),self::button('➖ کاهش موجودی',['callback_data'=>'adm_user_subbal:'.$u['id']],'danger')],
            [self::button('📦 سفارش‌های کاربر',['callback_data'=>'adm_user_orders:'.$u['id']],''),self::button('💳 تراکنش‌ها',['callback_data'=>'adm_user_tx:'.$u['id']],'')],
            [self::button((int)$u['blocked']?'✅ رفع مسدودیت':'🚫 مسدود کردن',['callback_data'=>'adm_user_block:'.$u['id']],(int)$u['blocked']?'success':'danger')],
            [self::button('✉️ ارسال پیام',['callback_data'=>'adm_user_message:'.$u['id']],'')],
            [self::button('📈 درصد زیرمجموعه',['callback_data'=>'adm_user_refp:'.$u['id']],''),self::button('💵 مبلغ ثابت',['callback_data'=>'adm_user_reff:'.$u['id']],'')],
            [self::button('⚙️ مدیریت اصلی',['callback_data'=>'admin_home'],'primary')],
        ];
        self::send($adminChatId,$text,self::inline($rows));
    }

    public static function setUserBlocked(int $userId, bool $blocked, int|string $adminTg): string
    {
        $u = self::one('SELECT * FROM users WHERE id=?',[$userId]);
        if (!$u) return 'کاربر پیدا نشد.';
        self::q('UPDATE users SET blocked=? WHERE id=?',[$blocked?1:0,$userId]);
        $text = $blocked ? self::text('user_blocked_notice') : self::text('user_unblocked_notice');
        try { self::send($u['telegram_id'],$text); } catch (Throwable) {}
        self::logEvent($blocked?'user_blocked':'user_unblocked',$blocked?'کاربر مسدود شد':'رفع مسدودی کاربر',['user_id'=>$userId,'admin'=>(string)$adminTg]);
        self::sendUserLog(($blocked?'🚫 <b>کاربر مسدود شد</b>':'✅ <b>رفع مسدودی انجام شد</b>')."\n".self::userDisplay($u)."\nمدیر: <code>".self::h($adminTg).'</code>',$u['telegram_id']);
        return $blocked?'کاربر مسدود شد.':'محدودیت کاربر برداشته شد.';
    }

    public static function adjustUserBalance(int $userId, float $amount, int|string $adminTg): string
    {
        if ($amount == 0.0) return 'مبلغ نمی‌تواند صفر باشد.';
        $pdo = self::db(); $pdo->beginTransaction();
        try {
            $u = self::one('SELECT * FROM users WHERE id=? FOR UPDATE',[$userId]);
            if (!$u) { $pdo->rollBack(); return 'کاربر پیدا نشد.'; }
            if ((float)$u['balance'] + $amount < 0) { $pdo->rollBack(); return 'موجودی کاربر برای این کاهش کافی نیست.'; }
            self::q('UPDATE users SET balance=balance+? WHERE id=?',[$amount,$userId]);
            self::q("INSERT INTO wallet_transactions(user_id,type,amount,description,created_at) VALUES (?,'admin_adjust',?,?,NOW())",[$userId,$amount,'تغییر توسط مدیر '.$adminTg]);
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
        $u = self::one('SELECT * FROM users WHERE id=?',[$userId]);
        $action = $amount>0?'افزایش':'کاهش';
        try { self::send($u['telegram_id'],self::text('wallet_adjust_notice',['action'=>$action,'amount'=>self::money(abs($amount)),'balance'=>self::money($u['balance'])])); } catch (Throwable) {}
        self::sendUserLog("💰 <b>{$action} موجودی توسط مدیر</b>\n".self::userDisplay($u)."\nمبلغ: ".self::money(abs($amount))."\nموجودی جدید: ".self::money($u['balance']),$u['telegram_id']);
        return "موجودی {$action} یافت.";
    }

    public static function showAdminUserOrders(int|string $chatId, int $userId): void
    {
        $u=self::one('SELECT * FROM users WHERE id=?',[$userId]); if(!$u){self::send($chatId,'کاربر پیدا نشد.');return;}
        $orders=self::all('SELECT o.*,p.title FROM orders o JOIN products p ON p.id=o.product_id WHERE o.user_id=? ORDER BY o.id DESC LIMIT 30',[$userId]);
        $rows=[];
        foreach($orders as $o)$rows[]=[self::button('#'.$o['id'].' | '.$o['title'].' | '.self::money($o['amount']),['callback_data'=>'adm_order:'.$o['id']],'')];
        if(!$rows)$rows[]=[self::button('سفارشی ثبت نشده',['callback_data'=>'noop'],'')];
        $rows[]=[self::button('⬅️ بازگشت به کاربر',['callback_data'=>'adm_user:'.$userId],'primary')];
        self::send($chatId,'📦 سفارش‌های <code>'.$u['telegram_id'].'</code>',self::inline($rows));
    }

    public static function deleteOrder(int $orderId, bool $refund, int|string $adminTg): string
    {
        $pdo=self::db();$pdo->beginTransaction();
        try{
            $o=self::one('SELECT o.*,u.telegram_id,p.channel_id FROM orders o JOIN users u ON u.id=o.user_id JOIN products p ON p.id=o.product_id WHERE o.id=? FOR UPDATE',[$orderId]);
            if(!$o){$pdo->rollBack();return 'سفارش پیدا نشد.';}
            if(!empty($o['invite_link'])){try{self::telegram('revokeChatInviteLink',['chat_id'=>$o['channel_id'],'invite_link'=>$o['invite_link']]);}catch(Throwable){}}
            if($refund && $o['status']==='paid'){
                self::q('UPDATE users SET balance=balance+? WHERE id=?',[$o['amount'],$o['user_id']]);
                self::q("INSERT INTO wallet_transactions(user_id,type,amount,reference_type,reference_id,description,created_at) VALUES (?,'refund',?,'order',?,'بازگشت مبلغ پس از حذف سفارش',NOW())",[$o['user_id'],$o['amount'],$orderId]);
            }
            self::q('DELETE FROM orders WHERE id=?',[$orderId]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
        self::sendUserLog("🗑 <b>سفارش حذف شد</b>\nشماره: #{$orderId}\nبازگشت مبلغ: ".($refund?'بله':'خیر')."\nمدیر: <code>".self::h($adminTg).'</code>',$o['telegram_id']);
        return 'سفارش حذف شد.'.($refund?' مبلغ به کیف پول برگشت.':'');
    }

    public static function adminSettingsMenu(int|string $chatId): void
    {
        $rows=[
            [self::button('🧾 گروه لاگ',['callback_data'=>'adm_setting:log_chat_id'],''),self::button('📊 گروه گزارشات',['callback_data'=>'adm_setting:proof_chat_id'],'')],
            [self::button('🔗 لینک گزارشات',['callback_data'=>'adm_setting:proof_url'],''),self::button('🎬 لینک نمونه فیلم',['callback_data'=>'adm_setting:sample_movie_url'],'')],
            [self::button('💱 واحد پول',['callback_data'=>'adm_setting:currency'],'')],
            [self::button('📈 درصد پورسانت',['callback_data'=>'adm_setting:referral_percent'],''),self::button('💰 پورسانت ثابت',['callback_data'=>'adm_setting:referral_fixed'],'')],
            [self::button('💳 حداقل شارژ',['callback_data'=>'adm_setting:min_topup'],''),self::button('🔢 مبالغ پیشنهادی',['callback_data'=>'adm_setting:topup_presets'],'')],
            [self::button('🌐 آدرس پروژه',['callback_data'=>'adm_setting:base_url'],''),self::button('🤖 توکن جدید',['callback_data'=>'adm_setting:bot_token'],'')],
            [self::button('👤 نام کاربری پنل',['callback_data'=>'adm_setting:panel_username'],''),self::button('🔐 رمز پنل',['callback_data'=>'adm_setting:panel_password'],'')],
            [self::button('👥 مدیران',['callback_data'=>'adm_admins'],''),self::button('🔄 بازتنظیم Webhook',['callback_data'=>'adm_webhook_reset'],'')],
            [self::button('⬅️ منوی مدیریت',['callback_data'=>'admin_home'],'primary')],
        ];
        $entryButtons=[];
        $logUrl=trim((string)self::setting('log_chat_url',''));
        $proofUrl=self::proofEntryUrl();
        if(self::isTelegramEntryUrl($logUrl))$entryButtons[]=self::button('🔗 ورود گروه لاگ',['url'=>$logUrl],'primary');
        if(self::isTelegramEntryUrl($proofUrl))$entryButtons[]=self::button('📊 ورود گروه گزارشات',['url'=>$proofUrl],'primary');
        $sampleUrl=trim((string)self::setting('sample_movie_url',''));
        if(self::isHttpUrl($sampleUrl))$entryButtons[]=self::button('🎬 نمونه فیلم',['url'=>$sampleUrl],'primary');
        if($entryButtons)array_splice($rows,1,0,[$entryButtons]);
        self::send($chatId,"⚙️ <b>تنظیمات کامل</b>\nبرای تغییر هر مورد روی همان گزینه بزن.",self::inline($rows));
    }

    public static function adminSimpleList(int|string $chatId, string $type): void
    {
        $rows=[];$title='';
        if($type==='forced'){
            $title='📢 عضویت اجباری';
            foreach(self::all('SELECT * FROM forced_channels ORDER BY sort_order,id') as $r)$rows[]=[self::button(($r['enabled']?'✅ ':'❌ ').$r['title'],['callback_data'=>'adm_forced:'.$r['id']],'')];
            $rows[]=[self::button('➕ افزودن کانال',['callback_data'=>'adm_forced_add'],'success')];
        }elseif($type==='cards'){
            $title='💳 کارت‌های پرداخت';
            foreach(self::all('SELECT * FROM cards ORDER BY sort_order,id') as $r)$rows[]=[self::button(($r['enabled']?'✅ ':'❌ ').$r['label'].' | '.$r['card_number'],['callback_data'=>'adm_card:'.$r['id']],'')];
            $rows[]=[self::button('➕ افزودن کارت',['callback_data'=>'adm_card_add'],'success')];
        }elseif($type==='menus'){
            $title='🧩 دکمه‌های اصلی';
            foreach(self::all('SELECT * FROM menus ORDER BY row_no,sort_order,id') as $r)$rows[]=[self::button(($r['enabled']?'✅ ':'❌ ').$r['label'],['callback_data'=>'adm_menu:'.$r['id']],$r['style']??'')];
            $rows[]=[self::button('➕ افزودن دکمه',['callback_data'=>'adm_menu_add'],'success')];
        }elseif($type==='texts'){
            $title='📝 متن‌های ربات';
            foreach(self::all('SELECT * FROM texts ORDER BY title LIMIT 60') as $r)$rows[]=[self::button($r['title'],['callback_data'=>'adm_text:'.$r['id']],'')];
        }elseif($type==='admins'){
            $title='👥 مدیران';
            foreach(self::all('SELECT * FROM admins ORDER BY id') as $r)$rows[]=[self::button(($r['enabled']?'✅ ':'❌ ').$r['telegram_id'],['callback_data'=>'adm_admin:'.$r['id']],'')];
            $rows[]=[self::button('➕ افزودن مدیر',['callback_data'=>'adm_admin_add'],'success')];
        }elseif($type==='tickets'){
            $title='🎫 تیکت‌های پشتیبانی';
            foreach(self::all("SELECT t.*,u.telegram_id FROM tickets t JOIN users u ON u.id=t.user_id ORDER BY t.updated_at DESC LIMIT 30") as $r)$rows[]=[self::button('#'.$r['id'].' | '.$r['telegram_id'].' | '.$r['status'],['callback_data'=>'adm_ticket:'.$r['id']],'')];
        }
        $rows[]=[self::button('⬅️ منوی مدیریت',['callback_data'=>'admin_home'],'primary')];
        self::send($chatId,$title,self::inline($rows));
    }

    public static function adminMenu(int|string $chatId):void
    {
        self::send($chatId,"⚙️ <b>مدیریت کامل فروشگاه</b>
همه بخش‌های مهم پنل وب از اینجا نیز قابل مدیریت‌اند.",self::inline([
            [self::button('📦 محصولات',['callback_data'=>'adm_products'],''),self::button('📁 دسته‌بندی‌ها',['callback_data'=>'adm_categories'],'')],
            [self::button('👤 کاربران',['callback_data'=>'adm_users'],''),self::button('🛍 سفارش‌ها',['callback_data'=>'adm_orders'],'')],
            [self::button('🧾 رسیدهای معلق',['callback_data'=>'adm_topups'],''),self::button('🎫 پشتیبانی',['callback_data'=>'adm_tickets'],'')],
            [self::button('📢 عضویت اجباری',['callback_data'=>'adm_forced'],''),self::button('💳 کارت‌ها',['callback_data'=>'adm_cards'],'')],
            [self::button('🧩 دکمه‌ها',['callback_data'=>'adm_menus'],''),self::button('📝 متن‌ها',['callback_data'=>'adm_texts'],'')],
            [self::button('🧠 پرداخت هوشمند',['callback_data'=>'adm_smart_pay'],'primary'),self::button('⚙️ تنظیمات',['callback_data'=>'adm_settings'],'')],
            [self::button('📊 آمار کامل',['callback_data'=>'adm_stats'],'')],
            [self::button('🌐 ورود مستقیم پنل وب',['url'=>self::adminPanelUrl($chatId)],'primary')]
        ]));
    }

    public static function handleAdminState(array $u,array $msg):bool
    {
        $state=$u['state']??''; if(!str_starts_with($state,'admin_'))return false;
        $data=self::stateData($u); $text=trim((string)($msg['text']??''));
        if($text==='/cancel'){self::setState($u['telegram_id'],null);self::adminMenu($u['telegram_id']);return true;}
        switch($state){
            case 'admin_reject_topup': self::rejectTopup((int)$data['id'],$u['telegram_id'],$text?:'بدون توضیح'); self::setState($u['telegram_id'],null); self::adminMenu($u['telegram_id']); return true;
            case 'admin_block_topup': self::rejectTopup((int)$data['id'],$u['telegram_id'],$text?:'تخلف در پرداخت',true); self::setState($u['telegram_id'],null); self::adminMenu($u['telegram_id']); return true;
            case 'admin_reply_ticket':
                $ticket=self::one('SELECT t.*,us.telegram_id FROM tickets t JOIN users us ON us.id=t.user_id WHERE t.id=?',[(int)$data['id']]);
                if($ticket){[$type,$body,$file]=self::extractMessage($msg);self::q("INSERT INTO ticket_messages (ticket_id,sender_type,message_type,text,file_id,created_at) VALUES (?,'admin',?,?,?,NOW())",[$ticket['id'],$type,$body,$file]);
                    if($type==='photo')self::telegram('sendPhoto',['chat_id'=>$ticket['telegram_id'],'photo'=>$file,'caption'=>'پاسخ پشتیبانی:\n'.self::h($body),'parse_mode'=>'HTML']);
                    elseif($type==='document')self::telegram('sendDocument',['chat_id'=>$ticket['telegram_id'],'document'=>$file,'caption'=>'پاسخ پشتیبانی:\n'.self::h($body),'parse_mode'=>'HTML']);
                    else self::send($ticket['telegram_id'],"💬 <b>پاسخ پشتیبانی</b>\n".self::h($body));
                    self::q("UPDATE tickets SET status='open',updated_at=NOW() WHERE id=?",[$ticket['id']]);
                }
                self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'پاسخ ارسال شد.');return true;
            case 'admin_search_user':
                $target=self::findUser($text); if(!$target){self::send($u['telegram_id'],'کاربر پیدا نشد؛ آیدی عددی یا یوزرنیم دقیق بفرست.');return true;}
                self::setState($u['telegram_id'],null);self::showAdminUser($u['telegram_id'],(int)$target['id']);return true;
            case 'admin_user_addbal':
            case 'admin_user_subbal':
                $amount=(float)str_replace([',',' '],'',$text); if($amount<=0){self::send($u['telegram_id'],'مبلغ مثبت و عددی بفرست.');return true;}
                if($state==='admin_user_subbal')$amount=-$amount;
                $result=self::adjustUserBalance((int)$data['id'],$amount,$u['telegram_id']);self::setState($u['telegram_id'],null);self::send($u['telegram_id'],$result);self::showAdminUser($u['telegram_id'],(int)$data['id']);return true;
            case 'admin_user_message':
                $target=self::one('SELECT * FROM users WHERE id=?',[(int)$data['id']]); if(!$target){self::send($u['telegram_id'],'کاربر پیدا نشد.');return true;}
                [$type,$body,$file]=self::extractMessage($msg);
                if($type==='photo')self::telegram('sendPhoto',['chat_id'=>$target['telegram_id'],'photo'=>$file,'caption'=>$body?:'پیام مدیریت']);
                elseif($type==='document')self::telegram('sendDocument',['chat_id'=>$target['telegram_id'],'document'=>$file,'caption'=>$body?:'پیام مدیریت']);
                else self::send($target['telegram_id'],"📨 <b>پیام مدیریت</b>\n".self::h($body));
                self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'پیام ارسال شد.');return true;
            case 'admin_user_ref_percent':
                if($text!==''&&!is_numeric($text)){self::send($u['telegram_id'],'درصد را عددی یا برای پیش‌فرض عدد -1 بفرست.');return true;}
                self::q('UPDATE users SET referral_percent_override=? WHERE id=?',[(float)$text<0?null:(float)$text,(int)$data['id']]);self::setState($u['telegram_id'],null);self::showAdminUser($u['telegram_id'],(int)$data['id']);return true;
            case 'admin_user_ref_fixed':
                if(!is_numeric(str_replace(',','',$text))){self::send($u['telegram_id'],'مبلغ را عددی یا -1 بفرست.');return true;}
                $v=(float)str_replace(',','',$text);self::q('UPDATE users SET referral_fixed_override=? WHERE id=?',[$v<0?null:$v,(int)$data['id']]);self::setState($u['telegram_id'],null);self::showAdminUser($u['telegram_id'],(int)$data['id']);return true;
            case 'admin_setting_value':
                $key=(string)($data['key']??'');
                $allowed=['base_url','currency','log_chat_id','proof_chat_id','proof_url','sample_movie_url','referral_percent','referral_fixed','min_topup','topup_presets','panel_username'];
                if(!in_array($key,$allowed,true)){self::send($u['telegram_id'],'تنظیم نامعتبر است.');return true;}
                if($key==='base_url')$text=rtrim($text,'/');
                if($key==='sample_movie_url' && $text!=='' && !self::isHttpUrl($text)){self::send($u['telegram_id'],'لینک نمونه فیلم معتبر نیست. لینک باید با http:// یا https:// شروع شود.');return true;}
                if(in_array($key,['log_chat_id','proof_chat_id'],true)){
                    try{
                        $urlKey=$key==='log_chat_id'?'log_chat_url':'proof_url';
                        $purpose=$key==='log_chat_id'?'log':'proof';
                        $url=self::saveChatDestination($key,$urlKey,$text,$purpose);
                        self::setState($u['telegram_id'],null);
                        self::send($u['telegram_id'],"✅ چت تنظیم و لینک ورود خودکار ساخته شد:\n".self::h($url));
                        self::adminSettingsMenu($u['telegram_id']);
                    }catch(Throwable $e){self::send($u['telegram_id'],'❌ '.self::h($e->getMessage())."\n\nپس از اصلاح دسترسی، همین آیدی را دوباره بفرست یا /cancel را بزن.");}
                    return true;
                }
                self::setSetting($key,$text);self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'تنظیم ذخیره شد.');self::adminSettingsMenu($u['telegram_id']);return true;
            case 'admin_set_bot_token':
                try{$me=self::telegram('getMe',[],$text);self::setSetting('bot_token',self::encrypt($text));self::setSetting('bot_username',$me['username']??'');self::setWebhook($text);self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'توکن جدید اعتبارسنجی و Webhook تنظیم شد.');}catch(Throwable $e){self::send($u['telegram_id'],'توکن نامعتبر: '.self::h($e->getMessage()));}return true;
            case 'admin_set_panel_password':
                if(mb_strlen($text)<8){self::send($u['telegram_id'],'رمز باید حداقل ۸ کاراکتر باشد.');return true;}
                self::setSetting('panel_password_hash',password_hash($text,PASSWORD_DEFAULT));self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'رمز پنل تغییر کرد.');return true;
            case 'admin_add_admin':
                if(!preg_match('/^\d+$/',$text)){self::send($u['telegram_id'],'آیدی عددی معتبر بفرست.');return true;}
                self::q('INSERT INTO admins(telegram_id,enabled,created_at) VALUES (?,1,NOW()) ON DUPLICATE KEY UPDATE enabled=1',[$text]);self::setState($u['telegram_id'],null);self::adminSimpleList($u['telegram_id'],'admins');return true;
            case 'admin_add_category':
                if($text===''){self::send($u['telegram_id'],'عنوان معتبر بفرست.');return true;}
                self::q("INSERT INTO categories (title,description,enabled,sort_order) VALUES (?,'',1,100)",[$text]); self::setState($u['telegram_id'],null); self::send($u['telegram_id'],'دسته‌بندی اضافه شد.');self::adminMenu($u['telegram_id']);return true;
            case 'admin_category_title':
                if($text===''){self::send($u['telegram_id'],'عنوان خالی نباشد.');return true;}
                self::q('UPDATE categories SET title=? WHERE id=?',[$text,(int)$data['id']]);self::setState($u['telegram_id'],null);self::adminMenu($u['telegram_id']);return true;
            case 'admin_category_description':
                self::q('UPDATE categories SET description=? WHERE id=?',[$text==='-'?'':$text,(int)$data['id']]);self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'توضیحات دسته‌بندی تغییر کرد.');return true;
            case 'admin_category_sort':
                if(!preg_match('/^-?\d+$/',$text)){self::send($u['telegram_id'],'ترتیب را عددی بفرست.');return true;}
                self::q('UPDATE categories SET sort_order=? WHERE id=?',[(int)$text,(int)$data['id']]);self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'ترتیب دسته‌بندی تغییر کرد.');return true;
            case 'admin_product_price':
                if(!is_numeric(str_replace(',','',$text))){self::send($u['telegram_id'],'قیمت را فقط عددی بفرست.');return true;}
                self::q('UPDATE products SET price=? WHERE id=?',[(float)str_replace(',','',$text),(int)$data['id']]);self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'قیمت تغییر کرد.');return true;
            case 'admin_product_title':
                if($text===''){self::send($u['telegram_id'],'نام محصول خالی نباشد.');return true;}
                self::q('UPDATE products SET title=? WHERE id=?',[$text,(int)$data['id']]);self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'نام محصول تغییر کرد.');return true;
            case 'admin_product_desc':
                self::q('UPDATE products SET description=? WHERE id=?',[$text,(int)$data['id']]);self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'توضیحات محصول تغییر کرد.');return true;
            case 'admin_product_channel':
                if(!preg_match('/^-?\d+$/',$text)){self::send($u['telegram_id'],'آیدی کانال نامعتبر است.');return true;}
                self::q('UPDATE products SET channel_id=? WHERE id=?',[$text,(int)$data['id']]);self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'کانال محصول تغییر کرد.');return true;
            case 'admin_product_image':
                self::q('UPDATE products SET image_url=? WHERE id=?',[$text==='-'?'':$text,(int)$data['id']]);self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'تصویر محصول تغییر کرد.');return true;
            case 'admin_product_expire':
                $hours=(int)$text;if($hours<1||$hours>8760){self::send($u['telegram_id'],'عدد ساعت باید بین ۱ تا ۸۷۶۰ باشد.');return true;}
                self::q('UPDATE products SET invite_expire_hours=? WHERE id=?',[$hours,(int)$data['id']]);self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'عمر لینک تغییر کرد.');return true;
            case 'admin_product_sort':
                if(!preg_match('/^-?\d+$/',$text)){self::send($u['telegram_id'],'ترتیب را عددی بفرست.');return true;}
                self::q('UPDATE products SET sort_order=? WHERE id=?',[(int)$text,(int)$data['id']]);self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'ترتیب محصول تغییر کرد.');return true;
            case 'admin_add_product_title':
                $data['title']=$text;self::setState($u['telegram_id'],'admin_add_product_desc',$data);self::send($u['telegram_id'],'توضیحات محصول را بفرست:');return true;
            case 'admin_add_product_desc':
                $data['description']=$text;self::setState($u['telegram_id'],'admin_add_product_price',$data);self::send($u['telegram_id'],'قیمت محصول را عددی بفرست:');return true;
            case 'admin_add_product_price':
                if(!is_numeric(str_replace(',','',$text))){self::send($u['telegram_id'],'قیمت فقط عددی باشد.');return true;}
                $data['price']=(float)str_replace(',','',$text);self::setState($u['telegram_id'],'admin_add_product_channel',$data);self::send($u['telegram_id'],'آیدی عددی کانال خصوصی محصول را بفرست؛ مثال: <code>-1001234567890</code>');return true;
            case 'admin_add_product_channel':
                if(!preg_match('/^-?\d+$/',$text)){self::send($u['telegram_id'],'آیدی کانال نامعتبر است.');return true;}
                self::q('INSERT INTO products (category_id,title,description,price,channel_id,enabled,sort_order,invite_expire_hours,invite_max_uses) VALUES (?,?,?,?,?,1,100,168,2)',[(int)$data['category_id'],$data['title'],$data['description'],$data['price'],$text]);
                self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'محصول با موفقیت ساخته شد.');self::adminMenu($u['telegram_id']);return true;
            case 'admin_forced_add_chat':
                $data['chat_id']=$text;self::setState($u['telegram_id'],'admin_forced_add_title',$data);self::send($u['telegram_id'],'عنوان نمایشی کانال را بفرست:');return true;
            case 'admin_forced_add_title':
                $data['title']=$text;
                try{
                    $url=self::createChatEntryUrl($data['chat_id'],'forced');
                    self::q('INSERT INTO forced_channels(chat_id,title,join_url,enabled,sort_order) VALUES (?,?,?,1,100)',[$data['chat_id'],$data['title'],$url]);
                    self::setState($u['telegram_id'],null);self::send($u['telegram_id'],"✅ کانال اضافه و لینک عضویت خودکار ساخته شد:\n".self::h($url));self::adminSimpleList($u['telegram_id'],'forced');
                }catch(Throwable $e){self::setState($u['telegram_id'],'admin_forced_add_url',$data);self::send($u['telegram_id'],'❌ '.self::h($e->getMessage())."\nاگر لینک دستی دارید آن را بفرست؛ در غیر این صورت /cancel را بزن و دسترسی ربات را اصلاح کن.");}
                return true;
            case 'admin_forced_add_url':
                if(!self::isTelegramEntryUrl($text)){self::send($u['telegram_id'],'لینک ورود تلگرام معتبر بفرست؛ مثال: <code>https://t.me/+...</code>');return true;}
                self::q('INSERT INTO forced_channels(chat_id,title,join_url,enabled,sort_order) VALUES (?,?,?,1,100)',[$data['chat_id'],$data['title'],$text]);self::setState($u['telegram_id'],null);self::adminSimpleList($u['telegram_id'],'forced');return true;
            case 'admin_forced_edit':
                $field=(string)$data['field'];if(!in_array($field,['chat_id','title','join_url','sort_order'],true))return true;
                if($field==='chat_id'){
                    try{$url=self::createChatEntryUrl($text,'forced');self::q('UPDATE forced_channels SET chat_id=?,join_url=? WHERE id=?',[$text,$url,(int)$data['id']]);}
                    catch(Throwable $e){self::send($u['telegram_id'],'❌ '.self::h($e->getMessage())."\nمقدار قبلی تغییر نکرد. آیدی را دوباره بفرست یا /cancel را بزن.");return true;}
                }else{
                    if($field==='join_url'&&!self::isTelegramEntryUrl($text)){self::send($u['telegram_id'],'لینک ورود تلگرام معتبر بفرست.');return true;}
                    self::q("UPDATE forced_channels SET {$field}=? WHERE id=?",[$text,(int)$data['id']]);
                }
                self::setState($u['telegram_id'],null);self::adminSimpleList($u['telegram_id'],'forced');return true;
            case 'admin_card_add_label':
                $data['label']=$text;self::setState($u['telegram_id'],'admin_card_add_holder',$data);self::send($u['telegram_id'],'نام صاحب حساب را بفرست یا یک خط تیره (-):');return true;
            case 'admin_card_add_holder':
                $data['holder']=$text==='-'?'':$text;self::setState($u['telegram_id'],'admin_card_add_number',$data);self::send($u['telegram_id'],'شماره کارت را بفرست:');return true;
            case 'admin_card_add_number':
                $number=preg_replace('/\s+/','',$text);if(!preg_match('/^\d{12,24}$/',$number)){self::send($u['telegram_id'],'شماره کارت معتبر بفرست.');return true;}
                self::q('INSERT INTO cards(label,holder_name,card_number,enabled,sort_order) VALUES (?,?,?,1,100)',[$data['label'],$data['holder'],$number]);self::setState($u['telegram_id'],null);self::adminSimpleList($u['telegram_id'],'cards');return true;
            case 'admin_card_edit':
                $field=(string)$data['field'];if(!in_array($field,['label','holder_name','card_number','sort_order'],true))return true;if($field==='card_number')$text=preg_replace('/\s+/','',$text);
                self::q("UPDATE cards SET {$field}=? WHERE id=?",[$text,(int)$data['id']]);self::setState($u['telegram_id'],null);self::adminSimpleList($u['telegram_id'],'cards');return true;
            case 'admin_menu_add_label':
                $data['label']=$text;self::setState($u['telegram_id'],'admin_menu_add_value',$data);self::send($u['telegram_id'],'متن پاسخ دکمه را بفرست:');return true;
            case 'admin_menu_add_value':
                self::q("INSERT INTO menus(`key`,label,style,action_type,action_value,row_no,sort_order,enabled) VALUES (?,?,'primary','custom_text',?,99,100,1)",['custom_'.time(),$data['label'],$text]);self::setState($u['telegram_id'],null);self::adminSimpleList($u['telegram_id'],'menus');return true;
            case 'admin_menu_edit':
                $field=(string)$data['field'];if(!in_array($field,['label','action_type','action_value','row_no','sort_order'],true))return true;
                if($field==='action_type'&&!in_array($text,['shop','wallet','my_packs','proof','sample_movie','referral','support','custom_text','custom_url'],true)){self::send($u['telegram_id'],'عملکرد معتبر نیست. یکی از این مقادیر را بفرست: shop, wallet, my_packs, proof, sample_movie, referral, support, custom_text, custom_url');return true;}
                self::q("UPDATE menus SET {$field}=? WHERE id=?",[$text,(int)$data['id']]);self::setState($u['telegram_id'],null);self::adminSimpleList($u['telegram_id'],'menus');return true;
            case 'admin_text_edit':
                self::q('UPDATE texts SET `value`=? WHERE id=?',[self::normalizeBotText($text),(int)$data['id']]);self::setState($u['telegram_id'],null);self::adminSimpleList($u['telegram_id'],'texts');return true;
        }
        return false;
    }
    public static function handleMessage(array $msg):void
    {
        if(($msg['chat']['type']??'')!=='private')return;
        $from=$msg['from']??null;if(!$from)return;
        $text=trim((string)($msg['text']??''));
        $param=null;if(str_starts_with($text,'/start '))$param=trim(substr($text,7));
        $u=self::ensureUser($from,$param);
        if((int)$u['blocked'] && !self::isAdmin($u['telegram_id'])){self::send($u['telegram_id'],self::text('blocked'));return;}
        if(self::isAdmin($u['telegram_id']) && self::handleAdminState($u,$msg))return;
        if(in_array($text,['/admin','مدیریت'],true)&&self::isAdmin($u['telegram_id'])){self::setState($u['telegram_id'],null);self::adminMenu($u['telegram_id']);return;}
        if($text==='/cancel'){self::setState($u['telegram_id'],null);self::showHome($u['telegram_id'],$u);return;}
        $state=$u['state']??'';
        if($state==='topup_amount'){
            $amount=(float)str_replace([',',' '],'',$text);
            if($amount<(float)self::setting('min_topup','10000')){self::send($u['telegram_id'],'حداقل مبلغ افزایش موجودی '.self::money(self::setting('min_topup','10000')).' است.');return;}
            $d=self::stateData($u);$id=self::createTopup($u,$amount,$d['product_id']??null);self::setState($u['telegram_id'],null);self::showInvoice($u['telegram_id'],$u,$id);return;
        }
        if($state==='receipt'){
            $d=self::stateData($u);$t=self::one("SELECT * FROM topups WHERE id=? AND user_id=? AND status IN ('waiting_receipt','pending')",[(int)($d['topup_id']??0),$u['id']]);
            if(!$t){self::setState($u['telegram_id'],null);self::send($u['telegram_id'],'درخواست پرداخت معتبر نیست.');return;}
            if(!isset($msg['photo'])&&!isset($msg['document'])){self::send($u['telegram_id'],self::text('receipt_invalid'),self::inline([[self::button('پشیمون شدم',['callback_data'=>'cancel_topup:'.$t['id']],'danger')]]));return;}
            [$type,$cap,$file]=self::extractMessage($msg);self::q("UPDATE topups SET status='pending',receipt_file_id=?,receipt_type=?,receipt_caption=? WHERE id=?",[$file,$type,$cap,$t['id']]);self::setState($u['telegram_id'],null);
            if(in_array((string)($t['payment_mode']??''),['smart_sms','amount_unique'],true)){SmartPayment::queueTopup((int)$t['id']);SmartPayment::processQueue(30);$fresh=self::one('SELECT status FROM topups WHERE id=?',[$t['id']]);if(($fresh['status']??'')==='approved')return;}
            self::send($u['telegram_id'],self::text('receipt_sent'),self::mainKeyboard($u['telegram_id']));self::notifyReceipt((int)$t['id']);return;
        }
        if($state==='support_wait'){self::saveSupportMessage($u,$msg);return;}
        if(!$text && !isset($msg['photo']) && !isset($msg['document']))return;
        if($text==='/start'||str_starts_with($text,'/start ')){self::sendUserLog("🔑 <b>ورود به ربات</b>\n".self::userDisplay($u)."\nزمان: ".self::formatDate(self::now()),$u['telegram_id']);if(!self::requireJoin($u['telegram_id'],$u['telegram_id']))return;self::showHome($u['telegram_id'],$u);return;}
        if(!self::requireJoin($u['telegram_id'],$u['telegram_id']))return;
        if($text==='/shop'){self::showCategories($u['telegram_id']);return;}
        if($text==='/wallet'){self::showWallet($u,$u['telegram_id']);return;}
        if($text==='/packs'){self::showPacks($u,$u['telegram_id']);return;}
        if($text==='/support'){self::startSupport($u);return;}
        $menu=self::menuByLabel($text);
        if($menu){self::runMenu($u,$menu);return;}
        self::send($u['telegram_id'],self::text('unknown'),self::mainKeyboard($u['telegram_id']));
    }

    public static function runMenu(array $u,array $menu,int|string|null $chatId=null):void
    {
        $chatId ??= $u['telegram_id'];
        $action=$menu['action_type'];$value=$menu['action_value'];
        match($action){
            'shop'=>self::showCategories($chatId),
            'wallet'=>self::showWallet($u,$chatId),
            'my_packs'=>self::showPacks($u,$chatId),
            'proof'=>self::proofEntryUrl()!==''
                ? self::send($chatId,self::text('proof_button'),self::inline([[self::button('📊 ورود به گروه گزارشات',['url'=>self::proofEntryUrl()],'primary')],[self::button('⬅️ بازگشت به منوی اصلی',['callback_data'=>'home'],'danger')]]))
                : self::send($chatId,'گروه گزارشات هنوز به‌درستی تنظیم نشده است.',self::mainKeyboard($u['telegram_id'])),
            'sample_movie'=>(($sampleUrl=trim((string)self::setting('sample_movie_url','')))!=='' && self::isHttpUrl($sampleUrl))
                ? self::send($chatId,'🎬 برای مشاهده نمونه فیلم روی دکمه زیر بزن.',self::inline([[self::button('🎬 مشاهده نمونه فیلم',['url'=>$sampleUrl],'primary')],[self::button('⬅️ بازگشت به منوی اصلی',['callback_data'=>'home'],'danger')]]))
                : self::send($chatId,'لینک نمونه فیلم هنوز توسط مدیریت تنظیم نشده است.',self::mainKeyboard($u['telegram_id'])),
            'referral'=>self::referralInfo($u),
            'support'=>self::startSupport($u),
            'custom_text'=>self::send($chatId,$value,self::mainKeyboard($u['telegram_id'])),
            'custom_url'=>self::send($chatId,'برای ادامه روی دکمه زیر بزنید:',self::inline([[['text'=>$menu['label'],'url'=>$value]]])),
            default=>self::send($chatId,'این دکمه هنوز تنظیم نشده است.')
        };
    }

    public static function handleCallback(array $cb):void
    {
        $id=$cb['id'];$data=(string)($cb['data']??'');$from=$cb['from'];$chatId=$cb['message']['chat']['id']??$from['id'];$msgId=(int)($cb['message']['message_id']??0);
        $u=self::ensureUser($from);
        self::answer($id);
        if((int)$u['blocked'] && !self::isAdmin($u['telegram_id'])){self::answer($id,self::text('blocked'),true);return;}
        if($data==='noop'||$data==='product_header')return;
        if($data==='check_join'){if(self::requireJoin($chatId,$u['telegram_id'])){self::answer($id,'عضویت تایید شد');self::showHome($chatId,$u);}return;}
        if(!self::isAdmin($u['telegram_id']) && !in_array($data,['check_join'],true) && !self::requireJoin($chatId,$u['telegram_id']))return;
        if($data==='home'){self::showHome($chatId,$u);return;}
        if(preg_match('/^menu:(\d+)$/',$data,$m)){
            $menu=self::one('SELECT * FROM menus WHERE id=? AND enabled=1',[(int)$m[1]]);
            if(!$menu){self::answer($id,'این دکمه حذف یا غیرفعال شده است.',true);return;}
            self::runMenu($u,$menu,$chatId);return;
        }
        if($data==='shop'){self::showCategories($chatId);return;}
        if($data==='topup'){self::showTopupOptions($u,$chatId);return;}
        if($data==='topup_custom'){self::setState($u['telegram_id'],'topup_amount');self::send($chatId,self::text('topup_amount_prompt',['min'=>self::money(self::setting('min_topup','10000'))]),self::inline([[self::button('لغو',['callback_data'=>'cancel_state'],'danger')]]));return;}
        if(preg_match('/^topup_amount:(\d+)$/',$data,$m)){ $amount=(float)$m[1]; if($amount<(float)self::setting('min_topup','10000')){self::answer($id,'مبلغ کمتر از حداقل است',true);return;} $tid=self::createTopup($u,$amount); self::showInvoice($chatId,$u,$tid); return; }
        if($data==='my_packs'){self::showPacks($u,$chatId);return;}
        if($data==='cancel_state'){self::setState($u['telegram_id'],null);self::showHome($chatId,$u);return;}
        if(preg_match('/^cat:(\d+)$/',$data,$m)){self::showProducts($chatId,(int)$m[1],$msgId);return;}
        if(preg_match('/^product:(\d+)$/',$data,$m)){self::showProduct($chatId,(int)$m[1],$msgId);return;}
        if(preg_match('/^buy:(\d+)$/',$data,$m)){self::purchase($u,(int)$m[1]);return;}
        if(preg_match('/^topup_product:(\d+)$/',$data,$m)){
            $p=self::one('SELECT * FROM products WHERE id=? AND enabled=1',[(int)$m[1]]);if(!$p)return;
            $short=max(0,(float)$p['price']-(float)$u['balance']);$tid=self::createTopup($u,$short,(int)$p['id']);self::showInvoice($chatId,$u,$tid);return;
        }
        if(preg_match('/^paid:(\d+)$/',$data,$m)){
            $topupId=(int)$m[1];
            if(SmartPayment::handlePaidClick($u,$topupId,$chatId))return;
            $t=self::one("SELECT * FROM topups WHERE id=? AND user_id=? AND status IN ('waiting_receipt','pending')",[$topupId,$u['id']]);if(!$t){self::answer($id,'درخواست معتبر نیست',true);return;}
            self::setState($u['telegram_id'],'receipt',['topup_id'=>$topupId]);self::send($chatId,self::text('receipt_prompt'),self::inline([[self::button('پشیمون شدم',['callback_data'=>'cancel_topup:'.$topupId],'danger')]]));return;
        }
        if(preg_match('/^send_receipt:(\d+)$/',$data,$m)){
            $t=self::one("SELECT * FROM topups WHERE id=? AND user_id=? AND status IN ('waiting_receipt','pending')",[(int)$m[1],$u['id']]);if(!$t){self::answer($id,'درخواست معتبر نیست',true);return;}
            self::setState($u['telegram_id'],'receipt',['topup_id'=>(int)$m[1]]);self::send($chatId,self::text('receipt_prompt'),self::inline([[self::button('پشیمون شدم',['callback_data'=>'cancel_topup:'.$m[1]],'danger')]]));return;
        }
        if(preg_match('/^cancel_topup:(\d+)$/',$data,$m)){self::q("UPDATE topups SET status='cancelled' WHERE id=? AND user_id=? AND status IN ('waiting_receipt','pending')",[(int)$m[1],$u['id']]);self::setState($u['telegram_id'],null);self::send($chatId,'درخواست لغو شد.',self::mainKeyboard($u['telegram_id']));return;}
        if(preg_match('/^order_link:(\d+)$/',$data,$m)){self::orderLink($u,(int)$m[1]);return;}
        if(self::isAdmin($u['telegram_id'])){self::handleAdminCallback($u,$data,$chatId,$msgId,$id);}
    }

    public static function handleAdminCallback(array $u,string $data,int|string $chatId,int $msgId,string $callbackId):void
    {
        $private=$u['telegram_id'];
        if($data==='admin_home'){self::adminMenu($private);return;}
        if($data==='adm_stats'){self::send($private,self::statisticsText(),self::inline([[self::button('⬅️ منوی مدیریت',['callback_data'=>'admin_home'],'primary')]]));return;}
        if($data==='adm_smart_pay'){
            $mode=SmartPayment::mode();
            self::send($private,SmartPayment::statusText(),self::inline([
                [self::button(($mode==='manual'?'✅ ':'').'دستی',['callback_data'=>'adm_pay_mode:manual'],$mode==='manual'?'success':'primary'),self::button(($mode==='smart_sms'?'✅ ':'').'پیامک هوشمند',['callback_data'=>'adm_pay_mode:smart_sms'],$mode==='smart_sms'?'success':'primary')],
                [self::button(($mode==='amount_unique'?'✅ ':'').'مبلغ یکتا',['callback_data'=>'adm_pay_mode:amount_unique'],$mode==='amount_unique'?'success':'primary'),self::button(($mode==='blind_auto'?'✅ ':'').'خودکار بدون بررسی',['callback_data'=>'adm_pay_mode:blind_auto'],$mode==='blind_auto'?'danger':'primary')],
                [self::button('🔄 ساخت لینک Shortcut جدید',['callback_data'=>'adm_shortcut_regen'],'danger')],
                [self::button('▶️ اجرای تطبیق الان',['callback_data'=>'adm_smart_process'],'primary')],
                [self::button('⬅️ منوی مدیریت',['callback_data'=>'admin_home'],'danger')]
            ]));return;
        }
        if(preg_match('/^adm_pay_mode:(manual|smart_sms|amount_unique|blind_auto)$/',$data,$m)){SmartPayment::setMode($m[1]);self::answer($callbackId,'حالت پرداخت تغییر کرد');self::handleAdminCallback($u,'adm_smart_pay',$chatId,$msgId,$callbackId);return;}
        if($data==='adm_shortcut_regen'){SmartPayment::shortcutUrl(true);self::answer($callbackId,'لینک قبلی باطل و لینک جدید ساخته شد',true);self::handleAdminCallback($u,'adm_smart_pay',$chatId,$msgId,$callbackId);return;}
        if($data==='adm_smart_process'){$r=SmartPayment::processQueue(50);self::answer($callbackId,'بررسی شد: '.$r['processed'].' | تایید: '.$r['matched'],true);self::handleAdminCallback($u,'adm_smart_pay',$chatId,$msgId,$callbackId);return;}
        if($data==='adm_settings'){self::adminSettingsMenu($private);return;}
        if(preg_match('/^adm_setting:(.+)$/',$data,$m)){
            $key=$m[1];$labels=['log_chat_id'=>'آیدی گروه لاگ','proof_chat_id'=>'آیدی گروه گزارشات','proof_url'=>'لینک گروه گزارشات','sample_movie_url'=>'لینک نمونه فیلم','currency'=>'واحد پول','referral_percent'=>'درصد پورسانت','referral_fixed'=>'مبلغ ثابت پورسانت','min_topup'=>'حداقل شارژ','topup_presets'=>'مبالغ پیشنهادی با کاما','base_url'=>'آدرس HTTPS پروژه','panel_username'=>'نام کاربری پنل'];
            if($key==='bot_token'){self::setState($private,'admin_set_bot_token');self::send($private,'توکن جدید ربات را بفرست. با تغییر توکن، ادامه مدیریت از ربات جدید خواهد بود.');return;}
            if($key==='panel_password'){self::setState($private,'admin_set_panel_password');self::send($private,'رمز جدید پنل را حداقل ۸ کاراکتر بفرست.');return;}
            if(!isset($labels[$key]))return;
            self::setState($private,'admin_setting_value',['key'=>$key]);self::send($private,"مقدار جدید «{$labels[$key]}» را بفرست.\nمقدار فعلی: <code>".self::h((string)self::setting($key,'ثبت نشده')).'</code>');return;
        }
        if($data==='adm_webhook_reset'){try{self::setWebhook();self::answer($callbackId,'Webhook بازتنظیم شد',true);}catch(Throwable $e){self::answer($callbackId,'خطا: '.$e->getMessage(),true);}return;}
        if($data==='adm_admins'){self::adminSimpleList($private,'admins');return;}
        if($data==='adm_admin_add'){self::setState($private,'admin_add_admin');self::send($private,'آیدی عددی مدیر جدید را بفرست:');return;}
        if(preg_match('/^adm_admin:(\d+)$/',$data,$m)){
            $a=self::one('SELECT * FROM admins WHERE id=?',[(int)$m[1]]);if(!$a)return;
            self::send($private,'مدیر: <code>'.$a['telegram_id'].'</code>',self::inline([
                [self::button($a['enabled']?'غیرفعال کردن':'فعال کردن',['callback_data'=>'adm_admin_toggle:'.$a['id']],$a['enabled']?'danger':'success')],
                [self::button('حذف مدیر',['callback_data'=>'adm_admin_delete:'.$a['id']],'danger')],
                [self::button('⬅️ مدیران',['callback_data'=>'adm_admins'],'primary')]
            ]));return;
        }
        if(preg_match('/^adm_admin_toggle:(\d+)$/',$data,$m)){self::q('UPDATE admins SET enabled=1-enabled WHERE id=?',[(int)$m[1]]);self::answer($callbackId,'وضعیت مدیر تغییر کرد');self::adminSimpleList($private,'admins');return;}
        if(preg_match('/^adm_admin_delete:(\d+)$/',$data,$m)){self::q('DELETE FROM admins WHERE id=?',[(int)$m[1]]);self::answer($callbackId,'حذف شد');self::adminSimpleList($private,'admins');return;}

        if($data==='adm_users'){self::setState($private,'admin_search_user');self::send($private,'🔎 آیدی عددی، یوزرنیم یا نام کاربر را بفرست:');return;}
        if(preg_match('/^(?:manage_user|adm_user):(\d+)$/',$data,$m)){
            $target=self::findUser($m[1]);
            if(!$target){self::answer($callbackId,'کاربر پیدا نشد',true);return;}
            if((string)$chatId!==(string)$private)self::answer($callbackId,'پنل کاربر در پیوی شما باز شد.');
            self::showAdminUser($private,(int)$target['id']);return;
        }
        if(preg_match('/^adm_user_block:(\d+)$/',$data,$m)){
            $target=self::one('SELECT * FROM users WHERE id=?',[(int)$m[1]]);if(!$target)return;
            $result=self::setUserBlocked((int)$target['id'],!(bool)$target['blocked'],$private);self::answer($callbackId,$result,true);self::showAdminUser($private,(int)$target['id']);return;
        }
        if(preg_match('/^adm_user_addbal:(\d+)$/',$data,$m)){self::setState($private,'admin_user_addbal',['id'=>(int)$m[1]]);self::send($private,'مبلغ افزایش موجودی را عددی بفرست:');return;}
        if(preg_match('/^adm_user_subbal:(\d+)$/',$data,$m)){self::setState($private,'admin_user_subbal',['id'=>(int)$m[1]]);self::send($private,'مبلغ کاهش موجودی را عددی بفرست:');return;}
        if(preg_match('/^adm_user_message:(\d+)$/',$data,$m)){self::setState($private,'admin_user_message',['id'=>(int)$m[1]]);self::send($private,'پیام، عکس یا فایل موردنظر برای کاربر را بفرست:');return;}
        if(preg_match('/^adm_user_refp:(\d+)$/',$data,$m)){self::setState($private,'admin_user_ref_percent',['id'=>(int)$m[1]]);self::send($private,'درصد اختصاصی را بفرست؛ برای بازگشت به پیش‌فرض -1 بفرست:');return;}
        if(preg_match('/^adm_user_reff:(\d+)$/',$data,$m)){self::setState($private,'admin_user_ref_fixed',['id'=>(int)$m[1]]);self::send($private,'مبلغ ثابت اختصاصی را بفرست؛ برای پیش‌فرض -1 بفرست:');return;}
        if(preg_match('/^adm_user_orders:(\d+)$/',$data,$m)){self::showAdminUserOrders($private,(int)$m[1]);return;}
        if(preg_match('/^adm_user_tx:(\d+)$/',$data,$m)){
            $rows=self::all('SELECT * FROM wallet_transactions WHERE user_id=? ORDER BY id DESC LIMIT 20',[(int)$m[1]]);$text="💳 <b>تراکنش‌های اخیر</b>";
            foreach($rows as $r)$text.="\n\n#{$r['id']} | ".self::money($r['amount'])."\n".self::h($r['description']?:$r['type'])." | ".self::formatDate($r['created_at']);
            if(!$rows)$text.="\n\nتراکنشی ثبت نشده است.";
            self::send($private,$text,self::inline([[self::button('⬅️ بازگشت',['callback_data'=>'adm_user:'.$m[1]],'primary')]]));return;
        }

        if($data==='adm_orders'){
            $rows=[];foreach(self::all('SELECT o.*,u.telegram_id,p.title FROM orders o JOIN users u ON u.id=o.user_id JOIN products p ON p.id=o.product_id ORDER BY o.id DESC LIMIT 30') as $o)$rows[]=[self::button('#'.$o['id'].' | '.$o['title'].' | '.$o['telegram_id'],['callback_data'=>'adm_order:'.$o['id']],'')];
            if(!$rows)$rows[]=[self::button('سفارشی ثبت نشده',['callback_data'=>'noop'],'')];$rows[]=[self::button('⬅️ مدیریت',['callback_data'=>'admin_home'],'primary')];self::send($private,'🛍 آخرین سفارش‌ها',self::inline($rows));return;
        }
        if(preg_match('/^adm_order:(\d+)$/',$data,$m)){
            $o=self::one('SELECT o.*,u.telegram_id,u.first_name,p.title FROM orders o JOIN users u ON u.id=o.user_id JOIN products p ON p.id=o.product_id WHERE o.id=?',[(int)$m[1]]);if(!$o)return;
            self::send($private,"🛍 <b>سفارش #{$o['id']}</b>\nکاربر: ".self::h($o['first_name'])." | <code>{$o['telegram_id']}</code>\nمحصول: ".self::h($o['title'])."\nمبلغ: ".self::money($o['amount'])."\nوضعیت: ".self::h($o['status'])."\nاستفاده لینک: {$o['invite_uses']}/{$o['max_invite_uses']}\nتاریخ: ".self::formatDate($o['created_at']),self::inline([
                [self::button('👤 مدیریت کاربر',['callback_data'=>'adm_user:'.$o['user_id']],'')],
                [self::button('🗑 حذف سفارش',['callback_data'=>'adm_order_delask:'.$o['id']],'danger')]
            ]));return;
        }
        if(preg_match('/^adm_order_delask:(\d+)$/',$data,$m)){self::send($private,'سفارش حذف شود؟ در صورت انتخاب بازگشت وجه، مبلغ خرید به کیف پول کاربر برمی‌گردد.',self::inline([[self::button('حذف بدون بازگشت',['callback_data'=>'adm_order_delete:'.$m[1].':0'],'danger')],[self::button('حذف و بازگشت وجه',['callback_data'=>'adm_order_delete:'.$m[1].':1'],'danger')],[self::button('انصراف',['callback_data'=>'adm_order:'.$m[1]],'primary')]]));return;}
        if(preg_match('/^adm_order_delete:(\d+):([01])$/',$data,$m)){self::answer($callbackId,self::deleteOrder((int)$m[1],$m[2]==='1',$private),true);self::adminMenu($private);return;}

        if($data==='adm_categories'){
            $rows=[];foreach(self::all('SELECT * FROM categories ORDER BY sort_order,id') as $c)$rows[]=[self::button(($c['enabled']?'✅ ':'❌ ').$c['title'],['callback_data'=>'adm_cat:'.$c['id']],'')];
            $rows[]=[self::button('➕ افزودن دسته‌بندی',['callback_data'=>'adm_cat_add'],'success')];$rows[]=[self::button('⬅️ مدیریت',['callback_data'=>'admin_home'],'primary')];self::send($private,'📁 دسته‌بندی‌ها',self::inline($rows));return;
        }
        if($data==='adm_cat_add'){self::setState($private,'admin_add_category');self::send($private,'عنوان دسته‌بندی جدید را بفرست. /cancel برای لغو');return;}
        if(preg_match('/^adm_cat:(\d+)$/',$data,$m)){$c=self::one('SELECT * FROM categories WHERE id=?',[(int)$m[1]]);if(!$c)return;self::send($private,'📁 <b>'.self::h($c['title']).'</b>\n'.self::h($c['description']?:'توضیحی ثبت نشده')."\nترتیب: ".$c['sort_order'],self::inline([[self::button('✏️ تغییر عنوان',['callback_data'=>'adm_cat_title:'.$c['id']],''),self::button('📝 توضیحات',['callback_data'=>'adm_cat_desc:'.$c['id']],'')],[self::button('↕️ ترتیب',['callback_data'=>'adm_cat_sort:'.$c['id']],''),self::button($c['enabled']?'غیرفعال':'فعال',['callback_data'=>'adm_cat_toggle:'.$c['id']],$c['enabled']?'danger':'success')],[self::button('حذف دسته‌بندی',['callback_data'=>'adm_cat_delete:'.$c['id']],'danger')],[self::button('⬅️ دسته‌بندی‌ها',['callback_data'=>'adm_categories'],'primary')]]));return;}
        if(preg_match('/^adm_cat_title:(\d+)$/',$data,$m)){self::setState($private,'admin_category_title',['id'=>(int)$m[1]]);self::send($private,'عنوان جدید را بفرست:');return;}
        if(preg_match('/^adm_cat_desc:(\d+)$/',$data,$m)){self::setState($private,'admin_category_description',['id'=>(int)$m[1]]);self::send($private,'توضیحات جدید را بفرست؛ برای خالی‌کردن - بفرست:');return;}
        if(preg_match('/^adm_cat_sort:(\d+)$/',$data,$m)){self::setState($private,'admin_category_sort',['id'=>(int)$m[1]]);self::send($private,'عدد ترتیب جدید را بفرست:');return;}
        if(preg_match('/^adm_cat_toggle:(\d+)$/',$data,$m)){self::q('UPDATE categories SET enabled=1-enabled WHERE id=?',[(int)$m[1]]);self::answer($callbackId,'تغییر کرد');self::handleAdminCallback($u,'adm_categories',$private,0,$callbackId);return;}
        if(preg_match('/^adm_cat_delete:(\d+)$/',$data,$m)){try{self::q('DELETE FROM categories WHERE id=?',[(int)$m[1]]);self::answer($callbackId,'حذف شد');}catch(Throwable){self::answer($callbackId,'ابتدا محصولات این دسته را حذف یا منتقل کن',true);}self::handleAdminCallback($u,'adm_categories',$private,0,$callbackId);return;}

        if($data==='adm_products'){
            $rows=[];foreach(self::all('SELECT p.*,c.title category FROM products p JOIN categories c ON c.id=p.category_id ORDER BY p.id DESC LIMIT 40') as $p)$rows[]=[self::button(($p['enabled']?'✅ ':'❌ ').$p['title'].' | '.self::money($p['price']),['callback_data'=>'adm_product:'.$p['id']],'')];
            $rows[]=[self::button('➕ محصول جدید',['callback_data'=>'adm_product_add'],'success')];$rows[]=[self::button('⬅️ مدیریت',['callback_data'=>'admin_home'],'primary')];self::send($private,'📦 محصولات',self::inline($rows));return;
        }
        if($data==='adm_product_add'){$rows=[];foreach(self::all('SELECT * FROM categories WHERE enabled=1 ORDER BY sort_order,id') as $c)$rows[]=[self::button($c['title'],['callback_data'=>'adm_product_cat:'.$c['id']],'')];self::send($private,'دسته محصول جدید را انتخاب کن:',self::inline($rows));return;}
        if(preg_match('/^adm_product_cat:(\d+)$/',$data,$m)){self::setState($private,'admin_add_product_title',['category_id'=>(int)$m[1]]);self::send($private,'نام محصول را بفرست:');return;}
        if(preg_match('/^adm_product:(\d+)$/',$data,$m)){
            $p=self::one('SELECT p.*,c.title category FROM products p JOIN categories c ON c.id=p.category_id WHERE p.id=?',[(int)$m[1]]);if(!$p)return;
            self::send($private,"📦 <b>".self::h($p['title'])."</b>\nدسته: ".self::h($p['category'])."\nقیمت: ".self::money($p['price'])."\nکانال: <code>".self::h($p['channel_id'])."</code>\nعمر لینک: {$p['invite_expire_hours']} ساعت\nترتیب: {$p['sort_order']}",self::inline([
                [self::button('✏️ نام',['callback_data'=>'adm_product_title:'.$p['id']],''),self::button('📝 توضیحات',['callback_data'=>'adm_product_desc:'.$p['id']],'')],
                [self::button('💰 قیمت',['callback_data'=>'adm_product_price:'.$p['id']],''),self::button('📣 کانال',['callback_data'=>'adm_product_channel:'.$p['id']],'')],
                [self::button('🖼 تصویر',['callback_data'=>'adm_product_image:'.$p['id']],''),self::button('⏳ عمر لینک',['callback_data'=>'adm_product_expire:'.$p['id']],'')],
                [self::button('↕️ ترتیب',['callback_data'=>'adm_product_sort:'.$p['id']],''),self::button('📁 دسته‌بندی',['callback_data'=>'adm_product_category:'.$p['id']],'')],
                [self::button($p['enabled']?'غیرفعال':'فعال',['callback_data'=>'adm_product_toggle:'.$p['id']],$p['enabled']?'danger':'success')],
                [self::button('🗑 حذف محصول',['callback_data'=>'adm_product_delete:'.$p['id']],'danger')],
                [self::button('⬅️ محصولات',['callback_data'=>'adm_products'],'primary')]
            ]));return;
        }
        foreach(['price'=>'قیمت جدید را فقط عددی بفرست:','title'=>'نام جدید محصول را بفرست:','desc'=>'توضیحات جدید محصول را بفرست:','channel'=>'آیدی عددی کانال خصوصی جدید را بفرست:','image'=>'آدرس تصویر را بفرست؛ برای حذف تصویر - بفرست:','expire'=>'عمر لینک را به ساعت بفرست:','sort'=>'عدد ترتیب نمایش محصول را بفرست:'] as $field=>$prompt){if(preg_match('/^adm_product_'.$field.':(\d+)$/',$data,$m)){self::setState($private,'admin_product_'.$field,['id'=>(int)$m[1]]);self::send($private,$prompt);return;}}
        if(preg_match('/^adm_product_category:(\d+)$/',$data,$m)){$rows=[];foreach(self::all('SELECT * FROM categories WHERE enabled=1 ORDER BY sort_order,id') as $c)$rows[]=[self::button($c['title'],['callback_data'=>'adm_product_setcat:'.$m[1].':'.$c['id']],'')];self::send($private,'دسته‌بندی جدید را انتخاب کن:',self::inline($rows));return;}
        if(preg_match('/^adm_product_setcat:(\d+):(\d+)$/',$data,$m)){self::q('UPDATE products SET category_id=? WHERE id=?',[(int)$m[2],(int)$m[1]]);self::answer($callbackId,'دسته‌بندی تغییر کرد');self::handleAdminCallback($u,'adm_product:'.$m[1],$private,0,$callbackId);return;}
        if(preg_match('/^adm_product_toggle:(\d+)$/',$data,$m)){self::q('UPDATE products SET enabled=1-enabled WHERE id=?',[(int)$m[1]]);self::answer($callbackId,'وضعیت تغییر کرد');self::handleAdminCallback($u,'adm_product:'.$m[1],$private,0,$callbackId);return;}
        if(preg_match('/^adm_product_delete:(\d+)$/',$data,$m)){try{self::q('DELETE FROM products WHERE id=?',[(int)$m[1]]);self::answer($callbackId,'محصول حذف شد');}catch(Throwable){self::answer($callbackId,'محصول دارای سفارش است و قابل حذف نیست؛ آن را غیرفعال کن.',true);}self::handleAdminCallback($u,'adm_products',$private,0,$callbackId);return;}

        if($data==='adm_forced'){self::adminSimpleList($private,'forced');return;}
        if($data==='adm_forced_add'){self::setState($private,'admin_forced_add_chat');self::send($private,'آیدی عددی یا یوزرنیم کانال عضویت اجباری را بفرست:');return;}
        if(preg_match('/^adm_forced:(\d+)$/',$data,$m)){$r=self::one('SELECT * FROM forced_channels WHERE id=?',[(int)$m[1]]);if(!$r)return;self::send($private,"📢 <b>".self::h($r['title'])."</b>\nچت: <code>".self::h($r['chat_id'])."</code>\nلینک: ".self::h($r['join_url'])."\nترتیب: ".$r['sort_order'],self::inline([[self::button('عنوان',['callback_data'=>'adm_forced_edit:'.$r['id'].':title'],''),self::button('آیدی چت',['callback_data'=>'adm_forced_edit:'.$r['id'].':chat_id'],'')],[self::button('لینک',['callback_data'=>'adm_forced_edit:'.$r['id'].':join_url'],''),self::button('ترتیب',['callback_data'=>'adm_forced_edit:'.$r['id'].':sort_order'],'')],[self::button($r['enabled']?'غیرفعال':'فعال',['callback_data'=>'adm_forced_toggle:'.$r['id']],$r['enabled']?'danger':'success')],[self::button('حذف',['callback_data'=>'adm_forced_delete:'.$r['id']],'danger')],[self::button('⬅️ بازگشت',['callback_data'=>'adm_forced'],'primary')]]));return;}
        if(preg_match('/^adm_forced_edit:(\d+):(title|chat_id|join_url|sort_order)$/',$data,$m)){self::setState($private,'admin_forced_edit',['id'=>(int)$m[1],'field'=>$m[2]]);self::send($private,'مقدار جدید را بفرست:');return;}
        if(preg_match('/^adm_forced_toggle:(\d+)$/',$data,$m)){self::q('UPDATE forced_channels SET enabled=1-enabled WHERE id=?',[(int)$m[1]]);self::answer($callbackId,'تغییر کرد');self::adminSimpleList($private,'forced');return;}
        if(preg_match('/^adm_forced_delete:(\d+)$/',$data,$m)){self::q('DELETE FROM forced_channels WHERE id=?',[(int)$m[1]]);self::answer($callbackId,'حذف شد');self::adminSimpleList($private,'forced');return;}

        if($data==='adm_cards'){self::adminSimpleList($private,'cards');return;}
        if($data==='adm_card_add'){self::setState($private,'admin_card_add_label');self::send($private,'عنوان کارت را بفرست:');return;}
        if(preg_match('/^adm_card:(\d+)$/',$data,$m)){$r=self::one('SELECT * FROM cards WHERE id=?',[(int)$m[1]]);if(!$r)return;self::send($private,"💳 <b>".self::h($r['label'])."</b>\nصاحب حساب: ".self::h($r['holder_name']?:'ثبت نشده')."\n<code>".self::h($r['card_number']).'</code>',self::inline([[self::button('عنوان',['callback_data'=>'adm_card_edit:'.$r['id'].':label'],''),self::button('صاحب حساب',['callback_data'=>'adm_card_edit:'.$r['id'].':holder_name'],'')],[self::button('شماره کارت',['callback_data'=>'adm_card_edit:'.$r['id'].':card_number'],''),self::button('ترتیب',['callback_data'=>'adm_card_edit:'.$r['id'].':sort_order'],'')],[self::button($r['enabled']?'غیرفعال':'فعال',['callback_data'=>'adm_card_toggle:'.$r['id']],$r['enabled']?'danger':'success')],[self::button('حذف',['callback_data'=>'adm_card_delete:'.$r['id']],'danger')],[self::button('⬅️ کارت‌ها',['callback_data'=>'adm_cards'],'primary')]]));return;}
        if(preg_match('/^adm_card_edit:(\d+):(label|holder_name|card_number|sort_order)$/',$data,$m)){self::setState($private,'admin_card_edit',['id'=>(int)$m[1],'field'=>$m[2]]);self::send($private,'مقدار جدید را بفرست:');return;}
        if(preg_match('/^adm_card_toggle:(\d+)$/',$data,$m)){self::q('UPDATE cards SET enabled=1-enabled WHERE id=?',[(int)$m[1]]);self::answer($callbackId,'تغییر کرد');self::adminSimpleList($private,'cards');return;}
        if(preg_match('/^adm_card_delete:(\d+)$/',$data,$m)){self::q('DELETE FROM cards WHERE id=?',[(int)$m[1]]);self::answer($callbackId,'حذف شد');self::adminSimpleList($private,'cards');return;}

        if($data==='adm_menus'){self::adminSimpleList($private,'menus');return;}
        if($data==='adm_menu_add'){self::setState($private,'admin_menu_add_label');self::send($private,'عنوان دکمه جدید را بفرست:');return;}
        if(preg_match('/^adm_menu:(\d+)$/',$data,$m)){$r=self::one('SELECT * FROM menus WHERE id=?',[(int)$m[1]]);if(!$r)return;self::send($private,"🧩 <b>".self::h($r['label'])."</b>\nعملکرد: ".self::h($r['action_type'])."\nرنگ: ".self::h($r['style']?:'بدون رنگ')."\nردیف/ترتیب: {$r['row_no']} / {$r['sort_order']}",self::inline([[self::button('عنوان',['callback_data'=>'adm_menu_edit:'.$r['id'].':label'],''),self::button('عملکرد',['callback_data'=>'adm_menu_edit:'.$r['id'].':action_type'],'')],[self::button('مقدار',['callback_data'=>'adm_menu_edit:'.$r['id'].':action_value'],''),self::button('ردیف',['callback_data'=>'adm_menu_edit:'.$r['id'].':row_no'],'')],[self::button('ترتیب',['callback_data'=>'adm_menu_edit:'.$r['id'].':sort_order'],'')],[self::button('رنگ بعدی',['callback_data'=>'adm_menu_style:'.$r['id']],''),self::button($r['enabled']?'غیرفعال':'فعال',['callback_data'=>'adm_menu_toggle:'.$r['id']],$r['enabled']?'danger':'success')],[self::button('↑ بالا',['callback_data'=>'adm_menu_move:'.$r['id'].':up'],''),self::button('↓ پایین',['callback_data'=>'adm_menu_move:'.$r['id'].':down'],'')],[self::button('حذف دکمه',['callback_data'=>'adm_menu_delete:'.$r['id']],'danger')],[self::button('⬅️ دکمه‌ها',['callback_data'=>'adm_menus'],'primary')]]));return;}
        if(preg_match('/^adm_menu_edit:(\d+):(label|action_type|action_value|row_no|sort_order)$/',$data,$m)){self::setState($private,'admin_menu_edit',['id'=>(int)$m[1],'field'=>$m[2]]);self::send($private,'مقدار جدید را بفرست:');return;}
        if(preg_match('/^adm_menu_style:(\d+)$/',$data,$m)){$r=self::one('SELECT style FROM menus WHERE id=?',[(int)$m[1]]);$current=(string)($r['style']??'');$next=[''=>'primary','primary'=>'success','success'=>'danger','danger'=>''][$current]??'';self::q('UPDATE menus SET style=? WHERE id=?',[$next,(int)$m[1]]);self::answer($callbackId,'رنگ: '.($next?:'بدون رنگ'));self::handleAdminCallback($u,'adm_menu:'.$m[1],$private,0,$callbackId);return;}
        if(preg_match('/^adm_menu_toggle:(\d+)$/',$data,$m)){self::q('UPDATE menus SET enabled=1-enabled WHERE id=?',[(int)$m[1]]);self::answer($callbackId,'تغییر کرد');self::adminSimpleList($private,'menus');return;}
        if(preg_match('/^adm_menu_move:(\d+):(up|down)$/',$data,$m)){self::q('UPDATE menus SET sort_order=sort_order+? WHERE id=?',[$m[2]==='up'?-10:10,(int)$m[1]]);self::adminSimpleList($private,'menus');return;}
        if(preg_match('/^adm_menu_delete:(\d+)$/',$data,$m)){self::q('DELETE FROM menus WHERE id=?',[(int)$m[1]]);self::answer($callbackId,'حذف شد');self::adminSimpleList($private,'menus');return;}

        if($data==='adm_texts'){self::adminSimpleList($private,'texts');return;}
        if(preg_match('/^adm_text:(\d+)$/',$data,$m)){$r=self::one('SELECT * FROM texts WHERE id=?',[(int)$m[1]]);if(!$r)return;self::send($private,'📝 <b>'.self::h($r['title'])."</b>\nکلید: <code>".self::h($r['key'])."</code>\n\n".self::h($r['value']?:'متن پیش‌فرض استفاده می‌شود'),self::inline([[self::button('✏️ ویرایش متن',['callback_data'=>'adm_text_edit:'.$r['id']],'')],[self::button('⬅️ متن‌ها',['callback_data'=>'adm_texts'],'primary')]]));return;}
        if(preg_match('/^adm_text_edit:(\d+)$/',$data,$m)){self::setState($private,'admin_text_edit',['id'=>(int)$m[1]]);self::send($private,'متن جدید را بفرست. می‌توانی از متغیرهای فعلی همان متن استفاده کنی:');return;}

        if($data==='adm_topups'){
            $rows=[];foreach(self::all("SELECT t.*,u.telegram_id FROM topups t JOIN users u ON u.id=t.user_id WHERE t.status='pending' ORDER BY t.id DESC LIMIT 30") as $t)$rows[]=[self::button('#'.$t['id'].' | '.self::money((float)($t['payable_amount']?:$t['amount'])).' | '.($t['auto_status']?:'manual').' | '.$t['telegram_id'],['callback_data'=>'adm_topup:'.$t['id']],'')];
            if(!$rows)$rows[]=[self::button('رسید معلقی وجود ندارد',['callback_data'=>'noop'],'')];$rows[]=[self::button('⬅️ مدیریت',['callback_data'=>'admin_home'],'primary')];self::send($private,'🧾 رسیدهای معلق',self::inline($rows));return;
        }
        if(preg_match('/^adm_topup:(\d+)$/',$data,$m)){
            $t=self::one('SELECT t.*,u.telegram_id,u.first_name,u.username FROM topups t JOIN users u ON u.id=t.user_id WHERE t.id=?',[(int)$m[1]]);if(!$t)return;
            $cap="🧾 <b>فاکتور #{$t['id']}</b>\nکاربر: <code>{$t['telegram_id']}</code>\nمبلغ پایه: ".self::money($t['amount'])."\nمبلغ قابل پرداخت: ".self::money((float)($t['payable_amount']?:$t['amount']))."\nروش: ".self::h(SmartPayment::modeLabel($t['payment_mode']?:'manual'))."\nتطبیق: ".self::h($t['auto_status']?:'—')."\nتلاش: ".number_format((int)($t['smart_attempts']??0)).(($t['smart_last_error']??'')!==''?"\nآخرین گزارش: ".self::h($t['smart_last_error']):'');
            $kb=self::inline([[self::button('✅ تایید',['callback_data'=>'topup_ok:'.$t['id']],'success'),self::button('❌ رد',['callback_data'=>'topup_reject:'.$t['id']],'danger'),self::button('🚫 بلاک',['callback_data'=>'topup_block:'.$t['id']],'danger')],[self::button('👤 مدیریت کاربر',['callback_data'=>'manage_user:'.$t['telegram_id']],'')]]);
            if(!empty($t['receipt_file_id'])){
                if($t['receipt_type']==='photo')self::telegram('sendPhoto',['chat_id'=>$private,'photo'=>$t['receipt_file_id'],'caption'=>$cap,'parse_mode'=>'HTML','reply_markup'=>$kb]);
                else self::telegram('sendDocument',['chat_id'=>$private,'document'=>$t['receipt_file_id'],'caption'=>$cap,'parse_mode'=>'HTML','reply_markup'=>$kb]);
            }else self::send($private,$cap."\n\n📎 رسید دستی هنوز ثبت نشده است.",$kb);
            return;
        }
        if(preg_match('/^topup_ok:(\d+)$/',$data,$m)){self::answer($callbackId,self::approveTopup((int)$m[1],$private),true);return;}
        if(preg_match('/^topup_reject:(\d+)$/',$data,$m)){self::setState($private,'admin_reject_topup',['id'=>(int)$m[1]]);self::send($private,'دلیل رد را بفرست:');return;}
        if(preg_match('/^topup_block:(\d+)$/',$data,$m)){self::setState($private,'admin_block_topup',['id'=>(int)$m[1]]);self::send($private,'دلیل رد و بلاک را بفرست:');return;}

        if($data==='adm_tickets'){self::adminSimpleList($private,'tickets');return;}
        if(preg_match('/^adm_ticket:(\d+)$/',$data,$m)){
            $t=self::one('SELECT t.*,u.telegram_id,u.first_name FROM tickets t JOIN users u ON u.id=t.user_id WHERE t.id=?',[(int)$m[1]]);if(!$t)return;
            $messages=self::all('SELECT * FROM ticket_messages WHERE ticket_id=? ORDER BY id DESC LIMIT 8',[$t['id']]);$text="🎫 <b>تیکت #{$t['id']}</b>\nکاربر: ".self::h($t['first_name'])." | <code>{$t['telegram_id']}</code>\nوضعیت: {$t['status']}";
            foreach(array_reverse($messages) as $x)$text.="\n\n<b>".($x['sender_type']==='admin'?'مدیر':'کاربر').":</b> ".self::h($x['text']?:'فایل/تصویر');
            self::send($private,$text,self::inline([[self::button('✍️ پاسخ',['callback_data'=>'support_reply:'.$t['id']],'success'),self::button('بستن تیکت',['callback_data'=>'support_close:'.$t['id']],'danger')],[self::button('👤 مدیریت کاربر',['callback_data'=>'manage_user:'.$t['telegram_id']],'')],[self::button('⬅️ تیکت‌ها',['callback_data'=>'adm_tickets'],'primary')]]));return;
        }
        if(preg_match('/^support_reply:(\d+)$/',$data,$m)){self::setState($private,'admin_reply_ticket',['id'=>(int)$m[1]]);self::send($private,'پاسخ را به‌صورت متن، عکس یا فایل بفرست:');return;}
        if(preg_match('/^support_close:(\d+)$/',$data,$m)){
            self::q("UPDATE tickets SET status='closed',updated_at=NOW() WHERE id=?",[(int)$m[1]]);self::answer($callbackId,'تیکت بسته شد');
            if($msgId>0){try{self::edit($chatId,$msgId,"✅ <b>تیکت #{$m[1]} بسته شد.</b>",self::inline([[self::button('باز کردن دوباره',['callback_data'=>'support_reopen:'.$m[1]],'success')]]));}catch(Throwable){}}
            return;
        }
        if(preg_match('/^support_reopen:(\d+)$/',$data,$m)){self::q("UPDATE tickets SET status='open',updated_at=NOW() WHERE id=?",[(int)$m[1]]);self::answer($callbackId,'تیکت باز شد');self::handleAdminCallback($u,'adm_ticket:'.$m[1],$private,0,$callbackId);return;}
    }
    public static function handleUpdate(array $update):void
    {
        if(isset($update['update_id'])){
            try{self::q('INSERT INTO webhook_updates (update_id,created_at) VALUES (?,NOW())',[(string)$update['update_id']]);}catch(PDOException $e){if($e->getCode()==='23000')return;throw $e;}
        }
        if(isset($update['channel_post'])){self::trackChannelPost($update['channel_post']);return;}
        if(isset($update['edited_channel_post'])){self::trackChannelPost($update['edited_channel_post']);return;}
        if(isset($update['chat_join_request'])){self::handleJoinRequest($update['chat_join_request']);return;}
        if(isset($update['callback_query'])){self::handleCallback($update['callback_query']);return;}
        if(isset($update['message'])){self::handleMessage($update['message']);return;}
    }

    public static function setWebhook(?string $token=null):array
    {
        $token??=self::token();$secret=(string)self::setting('webhook_secret','');if($secret===''){ $secret=bin2hex(random_bytes(24));self::setSetting('webhook_secret',$secret); }
        $url=self::baseUrl().'/webhook.php';
        self::telegram('setWebhook',['url'=>$url,'secret_token'=>$secret,'drop_pending_updates'=>false,'allowed_updates'=>['message','callback_query','chat_join_request','channel_post','edited_channel_post']],$token);
        return ['url'=>$url,'secret'=>$secret];
    }
}

final class WebAuth
{
    public static function start():void
    {
        if(session_status()===PHP_SESSION_NONE){
            session_name('film_store_admin');
            session_set_cookie_params(['httponly'=>true,'secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'samesite'=>'Lax']);
            session_start();
        }
    }
    public static function login(string $user,string $pass):bool
    {
        $ok=hash_equals((string)App::setting('panel_username','admin'),$user)&&password_verify($pass,(string)App::setting('panel_password_hash',''));
        if($ok){session_regenerate_id(true);$_SESSION['admin']=true;$_SESSION['csrf']=bin2hex(random_bytes(24));}
        return $ok;
    }
    public static function telegramLogin(int|string $telegramId, int $exp, string $sig): bool
    {
        if (!App::verifyAdminPanelToken($telegramId,$exp,$sig)) return false;
        session_regenerate_id(true);
        $_SESSION['admin']=true;
        $_SESSION['admin_tg_id']=(string)$telegramId;
        $_SESSION['csrf']=bin2hex(random_bytes(24));
        return true;
    }
    public static function check():bool{return !empty($_SESSION['admin']);}
    public static function require():void{if(!self::check()){header('Location: admin.php?login=1');exit;}}
    public static function csrf():string{return $_SESSION['csrf']??($_SESSION['csrf']=bin2hex(random_bytes(24)));}
    public static function verify():void{if(!hash_equals(self::csrf(),(string)($_POST['csrf']??'')))throw new RuntimeException('درخواست نامعتبر یا منقضی است.');}
}
