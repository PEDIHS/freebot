<?php
declare(strict_types=1);

final class SmartPayment
{
    private static bool $schemaReady = false;
    private static bool $processing = false;

    public static function ensureSchema(PDO $pdo): void
    {
        if (self::$schemaReady) return;
        self::$schemaReady = true;

        $pdo->exec("CREATE TABLE IF NOT EXISTS bank_sms (
            id bigint unsigned AUTO_INCREMENT PRIMARY KEY,
            source varchar(64) NOT NULL DEFAULT 'shortcut',
            raw_message text NOT NULL,
            message_hash char(64) NOT NULL,
            amount_rial bigint unsigned NULL,
            amount_toman bigint unsigned NULL,
            balance_rial bigint unsigned NULL,
            event_at datetime NULL,
            received_at datetime NOT NULL,
            remote_ip_hash char(64) NULL,
            status enum('new','reserved','matched','invalid','ignored') NOT NULL DEFAULT 'new',
            matched_topup_id bigint unsigned NULL,
            parse_error varchar(255) NULL,
            bot_seen_at datetime NULL,
            last_observed_at datetime NULL,
            matched_at datetime NULL,
            UNIQUE KEY uniq_bank_sms_hash(message_hash),
            KEY idx_bank_sms_match(status,amount_toman,event_at,received_at),
            KEY idx_bank_sms_topup(matched_topup_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        foreach ([
            'amount_rial' => "bigint unsigned NULL AFTER message_hash",
            'balance_rial' => "bigint unsigned NULL AFTER amount_toman",
            'bot_seen_at' => "datetime NULL AFTER parse_error",
            'last_observed_at' => "datetime NULL AFTER bot_seen_at",
        ] as $name => $definition) self::ensureColumn($pdo,'bank_sms',$name,$definition);

        foreach ([
            'payable_amount' => "decimal(18,0) NULL AFTER amount",
            'payment_mode' => "varchar(30) NOT NULL DEFAULT 'manual' AFTER payable_amount",
            'auto_status' => "varchar(30) NULL AFTER payment_mode",
            'matched_bank_sms_id' => "bigint unsigned NULL AFTER auto_status",
            'payment_requested_at' => "datetime NULL AFTER matched_bank_sms_id",
            'payment_deadline_at' => "datetime NULL AFTER payment_requested_at",
            'smart_attempts' => "int unsigned NOT NULL DEFAULT 0 AFTER payment_deadline_at",
            'smart_last_checked_at' => "datetime NULL AFTER smart_attempts",
            'smart_last_error' => "varchar(500) NULL AFTER smart_last_checked_at",
        ] as $name => $definition) self::ensureColumn($pdo,'topups',$name,$definition);

        $defaults = [
            'payment_auto_mode' => 'manual',
            'smart_sms_webhook_token' => '',
            'smart_sms_tolerance_toman' => '5000',
            'smart_sms_before_minutes' => '10',
            'smart_sms_after_minutes' => '5',
            'smart_sms_wait_minutes' => '10',
            'payment_unique_suffix_max' => '999',
            'smart_sms_last_run_at' => '',
            'smart_sms_last_result' => '',
        ];
        $st = $pdo->prepare("INSERT INTO settings (`key`,`value`) VALUES (?,?) ON DUPLICATE KEY UPDATE `value`=IF(TRIM(`value`)='',VALUES(`value`),`value`)");
        foreach ($defaults as $key => $value) $st->execute([$key,$value]);
    }

    private static function ensureColumn(PDO $pdo,string $table,string $name,string $definition): void
    {
        try {
            $st=$pdo->query("SHOW COLUMNS FROM `{$table}` LIKE ".$pdo->quote($name));
            if(!$st || !$st->fetch()) $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$name}` {$definition}");
        } catch (Throwable $e) {
            error_log("smart payment column {$table}.{$name}: ".$e->getMessage());
        }
    }

    public static function mode(): string
    {
        $mode = (string)App::setting('payment_auto_mode','manual');
        return in_array($mode,['manual','smart_sms','amount_unique','blind_auto'],true) ? $mode : 'manual';
    }

    public static function modeLabel(?string $mode = null): string
    {
        $mode ??= self::mode();
        return [
            'manual'=>'بررسی دستی مدیر',
            'smart_sms'=>'تطبیق هوشمند پیامک بانکی',
            'amount_unique'=>'مبلغ یکتا + تطبیق دقیق پیامک',
            'blind_auto'=>'تأیید خودکار بدون بررسی',
        ][$mode] ?? 'بررسی دستی مدیر';
    }

    public static function setMode(string $mode): void
    {
        if (!in_array($mode,['manual','smart_sms','amount_unique','blind_auto'],true)) throw new RuntimeException('حالت پرداخت نامعتبر است.');
        App::setSetting('payment_auto_mode',$mode);
    }

    public static function ensureShortcutToken(bool $regenerate=false): string
    {
        $token=(string)App::setting('smart_sms_webhook_token','');
        if($regenerate || !preg_match('/^[a-f0-9]{64}$/',$token)){
            $token=bin2hex(random_bytes(32));
            App::setSetting('smart_sms_webhook_token',$token);
        }
        return $token;
    }

    public static function shortcutUrl(bool $regenerate=false): string
    {
        $base=rtrim((string)App::baseUrl(),'/');
        if($base==='') return '';
        return $base.'/payment-webhook.php?token='.rawurlencode(self::ensureShortcutToken($regenerate));
    }

    public static function normalizeDigits(string $value): string
    {
        return strtr($value,[
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '٬'=>',','،'=>',','٫'=>'.','‌'=>' ',
        ]);
    }

    private static function detectSource(string $text): string
    {
        $banks=[
            'پاسارگاد'=>'pasargad','ملت'=>'mellat','ملی'=>'melli','صادرات'=>'saderat','تجارت'=>'tejarat','سامان'=>'saman',
            'پارسیان'=>'parsian','سپه'=>'sepah','رفاه'=>'refah','کشاورزی'=>'keshavarzi','آینده'=>'ayandeh','آینده'=>'ayandeh',
            'شهر'=>'shahr','دی'=>'dey','رسالت'=>'resalat','گردشگری'=>'gardeshgari','اقتصاد نوین'=>'eghtesad-novin','سینا'=>'sina',
        ];
        foreach($banks as $needle=>$slug) if(strpos($text,$needle)!==false) return $slug;
        return 'bank_sms';
    }

    private static function parseEventAt(string $text,DateTimeImmutable $received): ?string
    {
        $text=self::normalizeDigits($text);
        $date=null;
        if(preg_match('/\b(20\d{2})[\.\/-](\d{1,2})[\.\/-](\d{1,2})\b/u',$text,$m)){
            $date=[(int)$m[1],(int)$m[2],(int)$m[3]];
        }
        $time=null;
        if(preg_match_all('/\b([01]?\d|2[0-3])\s*[:：]\s*([0-5]\d)\b/u',$text,$matches,PREG_SET_ORDER)){
            $m=end($matches);$time=[(int)$m[1],(int)$m[2]];
        }
        if(!$date && !$time) return null;
        $y=$date[0]??(int)$received->format('Y');$mo=$date[1]??(int)$received->format('m');$d=$date[2]??(int)$received->format('d');
        $h=$time[0]??(int)$received->format('H');$mi=$time[1]??(int)$received->format('i');
        try{
            $event=new DateTimeImmutable(sprintf('%04d-%02d-%02d %02d:%02d:00',$y,$mo,$d,$h,$mi),new DateTimeZone('Asia/Tehran'));
            if(!$date && $event->getTimestamp()>$received->getTimestamp()+6*3600)$event=$event->modify('-1 day');
            if(abs($event->getTimestamp()-$received->getTimestamp())>7*86400)return null;
            return $event->format('Y-m-d H:i:s');
        }catch(Throwable){return null;}
    }

    private static function parseBankMessage(string $message,?DateTimeImmutable $received=null): array
    {
        $received ??= new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran'));
        $text=trim((string)preg_replace('/\s+/u',' ',self::normalizeDigits($message)));
        $result=['valid'=>false,'source'=>self::detectSource($text),'amount_rial'=>null,'amount_toman'=>null,'balance_rial'=>null,'event_at'=>null,'error'=>null];
        if($text===''){ $result['error']='empty_message'; return $result; }
        if(preg_match('/برداشت|خرید|کسر از حساب|بدهکار|پرداخت اینترنتی/u',$text) && !preg_match('/واریز|واريز|دریافت|دريافت|بستانکار|نشست|وصول/u',$text)){
            $result['error']='not_incoming_payment';return $result;
        }
        if(!preg_match('/واریز|واريز|دریافت|دريافت|بستانکار|نشست|وصول|انتقال به|\+\s*[0-9]/u',$text)){
            $result['error']='incoming_keyword_not_found';return $result;
        }

        $amountRaw=null;$currency='rial';
        $patterns=[
            '/([0-9][0-9,]*)\s*ریال\s*به\s*حساب\s*شما\s*نشست/u',
            '/(?:واریز|واريز|دریافت|دريافت|بستانکار|وصول|نشست)(?:\s+پول)?[^0-9]{0,45}([0-9][0-9,]*)\s*ریال/u',
            '/(?:مبلغ|amount)\s*[:：]?\s*([0-9][0-9,]*)\s*ریال/iu',
            '/\+\s*([0-9][0-9,]*)\s*(?:ریال)?/u',
            '/انتقال\s*[:：]?\s*([0-9][0-9,]*)\s*(?:ریال)?/u',
        ];
        foreach($patterns as $pattern){if(preg_match($pattern,$text,$m)){$amountRaw=$m[1];break;}}
        if($amountRaw===null && preg_match('/(?:واریز|واريز|دریافت|دريافت|بستانکار|نشست|وصول|مبلغ)[^0-9]{0,45}([0-9][0-9,]*)\s*تومان/u',$text,$m)){$amountRaw=$m[1];$currency='toman';}
        if($amountRaw===null && preg_match('/([0-9][0-9,]*)\s*(تومان|ریال)/u',$text,$m)){$amountRaw=$m[1];$currency=$m[2]==='تومان'?'toman':'rial';}
        if($amountRaw===null){$result['error']='amount_not_found';return $result;}
        $amount=(int)str_replace(',','',$amountRaw);
        if($amount<=0){$result['error']='invalid_amount';return $result;}
        if($currency==='toman'){$result['amount_toman']=$amount;$result['amount_rial']=$amount*10;}
        else{$result['amount_rial']=$amount;$result['amount_toman']=(int)round($amount/10);}
        if(preg_match('/موجودی\s*[:：]?\s*([0-9][0-9,]*)\s*ریال?/u',$text,$m))$result['balance_rial']=(int)str_replace(',','',$m[1]);
        $result['event_at']=self::parseEventAt($text,$received);
        $result['valid']=true;
        return $result;
    }

    public static function ingestSms(string $message,string $source='shortcut',string $remoteIp=''): array
    {
        self::ensureSchema(App::db());
        $message=trim($message);
        if($message===''||strlen($message)>24000)return ['ok'=>false,'error'=>'invalid_message'];
        $received=new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran'));
        $parsed=self::parseBankMessage($message,$received);
        if(trim($source)===''||$source==='shortcut')$source=$parsed['source']?:'shortcut';
        $normalized=(string)preg_replace('/\s+/u',' ',self::normalizeDigits($message));
        $hash=hash('sha256',trim($normalized));
        $remoteHash=$remoteIp!==''?hash('sha256',$remoteIp):null;
        if($remoteHash){
            $n=(int)(App::one("SELECT COUNT(*) c FROM bank_sms WHERE remote_ip_hash=? AND received_at>=DATE_SUB(NOW(),INTERVAL 1 MINUTE)",[$remoteHash])['c']??0);
            if($n>=30)return ['ok'=>false,'error'=>'rate_limited'];
        }
        try{
            App::q("INSERT INTO bank_sms(source,raw_message,message_hash,amount_rial,amount_toman,balance_rial,event_at,received_at,remote_ip_hash,status,parse_error) VALUES (?,?,?,?,?,?,?,NOW(),?,?,?)",[
                mb_substr($source?:'shortcut',0,64,'UTF-8'),$message,$hash,$parsed['amount_rial'],$parsed['amount_toman'],$parsed['balance_rial'],$parsed['event_at'],$remoteHash,$parsed['valid']?'new':'invalid',$parsed['error']
            ]);
            $id=(int)App::db()->lastInsertId();
        }catch(PDOException $e){
            if((int)($e->errorInfo[1]??0)===1062){
                $row=App::one('SELECT id,status,amount_toman,event_at,received_at FROM bank_sms WHERE message_hash=?',[$hash]);
                return ['ok'=>true,'duplicate'=>true,'record'=>$row];
            }
            throw $e;
        }
        $stats=self::processQueue(30);
        return ['ok'=>true,'duplicate'=>false,'record'=>['id'=>$id,'status'=>$parsed['valid']?'new':'invalid','source'=>$source,'amount_toman'=>$parsed['amount_toman'],'event_at'=>$parsed['event_at'],'parse_error'=>$parsed['error']], 'matcher'=>$stats];
    }

    private static function payableAmount(float $base): float
    {
        $mode=self::mode();
        if(!in_array($mode,['smart_sms','amount_unique'],true))return $base;
        $max=max(9,min(9999,(int)App::setting('payment_unique_suffix_max','999')));
        $baseInt=(int)round($base);
        for($i=0;$i<80;$i++){
            $candidate=$baseInt+random_int(1,$max);
            $exists=App::one("SELECT id FROM topups WHERE payable_amount=? AND status IN ('waiting_receipt','pending') AND created_at>=DATE_SUB(NOW(),INTERVAL 2 DAY) LIMIT 1",[$candidate]);
            if(!$exists)return (float)$candidate;
        }
        return (float)$baseInt;
    }

    public static function prepareTopup(int $topupId,float $baseAmount): void
    {
        self::ensureSchema(App::db());
        $mode=self::mode();
        $payable=self::payableAmount($baseAmount);
        $wait=max(1,min(60,(int)App::setting('smart_sms_wait_minutes','10')));
        $autoStatus=$mode==='smart_sms'?'awaiting_receipt':($mode==='amount_unique'?'awaiting_confirmation':null);
        App::q("UPDATE topups SET payable_amount=?,payment_mode=?,auto_status=?,payment_requested_at=NULL,payment_deadline_at=NULL,smart_attempts=0,smart_last_checked_at=NULL,smart_last_error=NULL WHERE id=?",[
            $payable,$mode,$autoStatus,$topupId
        ]);
    }

    public static function queueTopup(int $topupId): void
    {
        $t=App::one('SELECT * FROM topups WHERE id=?',[$topupId]);
        if(!$t)return;
        $mode=(string)($t['payment_mode']?:self::mode());
        if(!in_array($mode,['smart_sms','amount_unique'],true))return;
        $wait=max(1,min(60,(int)App::setting('smart_sms_wait_minutes','10')));
        App::q("UPDATE topups SET status='pending',auto_status='pending',payment_requested_at=NOW(),payment_deadline_at=DATE_ADD(NOW(),INTERVAL ? MINUTE),smart_attempts=0,smart_last_checked_at=NULL,smart_last_error=NULL WHERE id=? AND status IN ('waiting_receipt','pending')",[$wait,$topupId]);
    }

    private static function toleranceFor(array $topup): int
    {
        return ((string)($topup['payment_mode']??''))==='amount_unique' ? 0 : max(0,min(50000,(int)App::setting('smart_sms_tolerance_toman','5000')));
    }

    private static function timestampForSms(array $sms): int
    {
        $times=[];
        foreach(['event_at','received_at'] as $k){if(!empty($sms[$k])){$ts=strtotime((string)$sms[$k]);if($ts!==false)$times[]=$ts;}}
        return $times?min($times):time();
    }

    private static function topupWindow(array $topup): array
    {
        $requestedTs=strtotime((string)($topup['payment_requested_at']?:$topup['created_at'])) ?: time();
        $before=max(1,min(60,(int)App::setting('smart_sms_before_minutes','10')));
        $after=max(0,min(30,(int)App::setting('smart_sms_after_minutes','5')));
        $deadlineTs=!empty($topup['payment_deadline_at'])?(strtotime((string)$topup['payment_deadline_at'])?:$requestedTs):$requestedTs;
        $upper=max($deadlineTs,$requestedTs+$after*60);
        return [$requestedTs-$before*60,$upper,$requestedTs];
    }

    private static function smsInTopupWindow(array $topup,array $sms): bool
    {
        [$from,$to]=self::topupWindow($topup);
        foreach(['event_at','received_at'] as $k){
            if(empty($sms[$k]))continue;$ts=strtotime((string)$sms[$k]);
            if($ts!==false && $ts>=$from && $ts<=$to)return true;
        }
        return false;
    }

    private static function score(array $topup,array $sms): int
    {
        $expected=(int)round((float)($topup['payable_amount']?:$topup['amount']));
        $amountDiff=abs($expected-(int)$sms['amount_toman']);
        $requestedTs=strtotime((string)($topup['payment_requested_at']?:$topup['created_at']))?:time();
        $diffs=[];
        foreach(['event_at','received_at'] as $k){if(!empty($sms[$k])){$ts=strtotime((string)$sms[$k]);if($ts!==false)$diffs[]=abs($requestedTs-$ts);}}
        $timeDiff=$diffs?min($diffs):86400;
        return ($amountDiff*100000)+$timeDiff;
    }

    private static function isBestOwner(array $current,array $sms): bool
    {
        $rows=App::all("SELECT * FROM topups WHERE status IN ('waiting_receipt','pending') AND auto_status='pending' AND payment_mode IN ('smart_sms','amount_unique') ORDER BY id ASC LIMIT 500");
        $best=null;
        foreach($rows as $candidate){
            $expected=(int)round((float)($candidate['payable_amount']?:$candidate['amount']));
            if(abs($expected-(int)$sms['amount_toman'])>self::toleranceFor($candidate))continue;
            if(!self::smsInTopupWindow($candidate,$sms))continue;
            $score=self::score($candidate,$sms);
            if($best===null||$score<$best['score']||($score===$best['score']&&(int)$candidate['id']<(int)$best['id']))$best=['score'=>$score,'id'=>(int)$candidate['id']];
        }
        return $best===null || $best['id']===(int)$current['id'];
    }

    private static function findCandidate(array $topup): ?array
    {
        $expected=(int)round((float)($topup['payable_amount']?:$topup['amount']));
        $tol=self::toleranceFor($topup);
        [$fromTs,$toTs,$requestedTs]=self::topupWindow($topup);
        $from=date('Y-m-d H:i:s',$fromTs);$to=date('Y-m-d H:i:s',$toTs);$requested=date('Y-m-d H:i:s',$requestedTs);
        $candidates=App::all("SELECT *,ABS(amount_toman-?) amount_diff,LEAST(ABS(TIMESTAMPDIFF(SECOND,COALESCE(event_at,received_at),?)),ABS(TIMESTAMPDIFF(SECOND,received_at,?))) time_diff FROM bank_sms
            WHERE status='new' AND amount_toman BETWEEN ? AND ?
              AND ((event_at BETWEEN ? AND ?) OR (received_at BETWEEN ? AND ?))
            ORDER BY amount_diff ASC,time_diff ASC,received_at ASC,id ASC LIMIT 20",[
            $expected,$requested,$requested,$expected-$tol,$expected+$tol,$from,$to,$from,$to
        ]);
        if($candidates){
            $ids=array_map(fn($x)=>(int)$x['id'],$candidates);
            if($ids)App::q("UPDATE bank_sms SET bot_seen_at=COALESCE(bot_seen_at,NOW()),last_observed_at=NOW() WHERE id IN (".implode(',',$ids).")");
        }
        foreach($candidates as $sms)if(self::isBestOwner($topup,$sms))return $sms;
        return null;
    }

    private static function approveMatch(array $topup,array $sms): bool
    {
        $pdo=App::db();
        $pdo->beginTransaction();
        try{
            $lockedTop=App::one('SELECT * FROM topups WHERE id=? FOR UPDATE',[$topup['id']]);
            $lockedSms=App::one('SELECT * FROM bank_sms WHERE id=? FOR UPDATE',[$sms['id']]);
            if(!$lockedTop||!in_array($lockedTop['status'],['waiting_receipt','pending'],true)||($lockedTop['auto_status']??'')!=='pending'||!$lockedSms||$lockedSms['status']!=='new'){
                $pdo->rollBack();return false;
            }
            $difference=abs((int)round((float)($lockedTop['payable_amount']?:$lockedTop['amount']))-(int)$lockedSms['amount_toman']);
            App::q("UPDATE bank_sms SET status='reserved',matched_topup_id=?,matched_at=NOW(),bot_seen_at=COALESCE(bot_seen_at,NOW()),last_observed_at=NOW() WHERE id=?",[$topup['id'],$sms['id']]);
            App::q("UPDATE topups SET status='pending',matched_bank_sms_id=?,auto_status='processing',reason=?,smart_last_error=NULL WHERE id=?",[$sms['id'],'تطبیق هوشمند پیامک بانکی | اختلاف مبلغ: '.number_format($difference).' تومان',$topup['id']]);
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}

        try{
            App::approveTopup((int)$topup['id'],'smart_sms');
            $fresh=App::one('SELECT status FROM topups WHERE id=?',[$topup['id']]);
            if(($fresh['status']??'')==='approved'){
                App::q("UPDATE bank_sms SET status='matched',matched_at=NOW(),last_observed_at=NOW() WHERE id=?",[$sms['id']]);
                App::q("UPDATE topups SET auto_status='matched',smart_last_error=NULL WHERE id=?",[$topup['id']]);
                App::logEvent('smart_payment_matched','پرداخت با پیامک بانکی تطبیق و تایید شد',['topup_id'=>$topup['id'],'sms_id'=>$sms['id'],'amount'=>$sms['amount_toman']]);
                return true;
            }
        }catch(Throwable $e){App::logEvent('smart_payment_approve_error',$e->getMessage(),['topup_id'=>$topup['id'],'sms_id'=>$sms['id']]);}
        App::q("UPDATE bank_sms SET status='new',matched_topup_id=NULL,matched_at=NULL,last_observed_at=NOW() WHERE id=? AND status='reserved'",[$sms['id']]);
        App::q("UPDATE topups SET auto_status='manual_review',smart_last_error='تایید خودکار کامل نشد؛ بررسی دستی لازم است',reason='تایید خودکار کامل نشد؛ بررسی دستی لازم است' WHERE id=? AND status<>'approved'",[$topup['id']]);
        return false;
    }

    public static function processQueue(int $limit=25): array
    {
        self::ensureSchema(App::db());
        if(self::$processing)return ['processed'=>0,'matched'=>0,'manual_review'=>0,'busy'=>true];
        self::$processing=true;
        $stats=['processed'=>0,'matched'=>0,'manual_review'=>0];
        try{
            $limit=max(1,min(100,$limit));
            App::q("UPDATE bank_sms SET bot_seen_at=COALESCE(bot_seen_at,NOW()),last_observed_at=NOW() WHERE status='new'");
            $rows=App::all("SELECT t.*,u.telegram_id FROM topups t JOIN users u ON u.id=t.user_id WHERE t.status IN ('waiting_receipt','pending') AND t.auto_status='pending' AND t.payment_mode IN ('smart_sms','amount_unique') ORDER BY COALESCE(t.payment_requested_at,t.created_at) ASC LIMIT {$limit}");
            foreach($rows as $t){
                $stats['processed']++;
                try{
                    App::q("UPDATE topups SET smart_attempts=smart_attempts+1,smart_last_checked_at=NOW(),smart_last_error=NULL WHERE id=? AND auto_status='pending'",[$t['id']]);
                    $sms=self::findCandidate($t);
                    if($sms && self::approveMatch($t,$sms)){$stats['matched']++;continue;}
                    $expected=(int)round((float)($t['payable_amount']?:$t['amount']));
                    $nearest=App::one("SELECT id,amount_toman,event_at,received_at,ABS(amount_toman-?) amount_diff FROM bank_sms WHERE status='new' ORDER BY ABS(amount_toman-?) ASC,received_at DESC LIMIT 1",[$expected,$expected]);
                    $reason=$nearest?'نزدیک‌ترین پیامک #'.(int)$nearest['id'].' با اختلاف '.number_format((int)$nearest['amount_diff']).' تومان پیدا شد؛ هنوز تطبیق قطعی نیست.':'هنوز پیامک واریز معتبر برای تطبیق ثبت نشده است.';
                    App::q("UPDATE topups SET smart_last_error=? WHERE id=? AND auto_status='pending'",[mb_substr($reason,0,500,'UTF-8'),$t['id']]);
                    if(!empty($t['payment_deadline_at']) && strtotime((string)$t['payment_deadline_at'])<=time()){
                        $manual='مهلت تطبیق هوشمند تمام شد؛ پرداخت رد نشده و برای بررسی دستی/ارسال رسید باز مانده است.';
                        $st=App::q("UPDATE topups SET auto_status='manual_review',smart_last_error=?,reason=IF(COALESCE(reason,'')='',?,reason) WHERE id=? AND auto_status='pending'",[$manual,$manual,$t['id']]);
                        if($st->rowCount()>0){
                            $stats['manual_review']++;
                            try{App::send($t['telegram_id'],"⚠️ <b>تطبیق خودکار قطعی پیدا نشد</b>\n\nپرداخت شما رد نشده است. برای بررسی دستی، رسید واریز را ارسال کن.",App::inline([[App::button('📎 ارسال رسید',['callback_data'=>'send_receipt:'.$t['id']],'primary')],[App::button('🏠 منوی اصلی',['callback_data'=>'home'],'danger')]]));}catch(Throwable){}
                        }
                    }
                }catch(Throwable $e){
                    App::q("UPDATE topups SET smart_last_error=? WHERE id=?",[mb_substr($e->getMessage(),0,500,'UTF-8'),$t['id']]);
                    App::logEvent('smart_payment_queue_error',$e->getMessage(),['topup_id'=>$t['id']]);
                }
            }
            App::setSetting('smart_sms_last_run_at',date('Y-m-d H:i:s'));
            App::setSetting('smart_sms_last_result',json_encode($stats,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
            return $stats;
        }finally{self::$processing=false;}
    }

    public static function handlePaidClick(array $user,int $topupId,int|string $chatId): bool
    {
        $t=App::one("SELECT * FROM topups WHERE id=? AND user_id=? AND status IN ('waiting_receipt','pending')",[$topupId,$user['id']]);
        if(!$t)return false;
        $mode=(string)($t['payment_mode']?:self::mode());
        if($mode==='blind_auto'){
            if($t['status']==='waiting_receipt')App::q("UPDATE topups SET status='pending',auto_status='blind_auto' WHERE id=?",[$topupId]);
            App::approveTopup($topupId,'blind_auto');
            return true;
        }
        if($mode==='smart_sms'){
            // مطابق ربات مرجع: ابتدا رسید دریافت می‌شود و سپس با پیامک بانک تطبیق داده می‌شود.
            return false;
        }
        if($mode==='amount_unique'){
            self::queueTopup($topupId);
            self::processQueue(30);
            $fresh=App::one('SELECT * FROM topups WHERE id=?',[$topupId]);
            if(($fresh['status']??'')==='approved')return true;
            $minutes=max(1,min(60,(int)App::setting('smart_sms_wait_minutes','10')));
            $status=($fresh['auto_status']??'')==='manual_review'?'تطبیق قطعی پیدا نشد و بررسی دستی فعال است.':"تا {$minutes} دقیقه مبلغ دقیق فاکتور با پیامک بانکی تطبیق داده می‌شود.";
            App::send($chatId,"🧠 <b>بررسی مبلغ یکتا</b>

{$status}
اگر تأیید خودکار انجام نشد، رسید را برای بررسی دستی بفرست.",App::inline([
                [App::button('📎 ارسال رسید برای بررسی دستی',['callback_data'=>'send_receipt:'.$topupId],'primary')],
                [App::button('❌ لغو درخواست',['callback_data'=>'cancel_topup:'.$topupId],'danger')],
            ]));
            return true;
        }
        return false;
    }

    public static function statusText(): string
    {
        $mode=self::mode();$url=self::shortcutUrl();$last=(string)App::setting('smart_sms_last_run_at','');
        $tokenLine=$url!==''?"\n\n🔗 <b>لینک محرمانه Shortcut</b>\n<code>".App::h($url)."</code>":'';
        return "🧠 <b>پرداخت هوشمند</b>\n\nحالت فعلی: <b>".App::h(self::modeLabel($mode))."</b>\nاختلاف مجاز مبلغ: <b>".number_format((int)App::setting('smart_sms_tolerance_toman','5000'))." تومان</b>\nبازه قبل از فاکتور: <b>".number_format((int)App::setting('smart_sms_before_minutes','10'))." دقیقه</b>\nمدت انتظار: <b>".number_format((int)App::setting('smart_sms_wait_minutes','10'))." دقیقه</b>\nآخرین اجرای تطبیق: <b>".App::h($last?:'هنوز اجرا نشده')."</b>".$tokenLine."\n\nدر Shortcut آیفون: Get Contents of URL → POST → JSON و فیلد <code>message</code> را برابر متن پیامک بانک بگذار.";
    }
}
