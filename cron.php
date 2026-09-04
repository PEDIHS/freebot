<?php
declare(strict_types=1);

// BOT_MANAGER_RUNTIME_GUARD
$__bm_disabled = __DIR__ . '/.botmanager.disabled';
if (is_file($__bm_disabled)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(200);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bot disabled by Bot Manager.';
    }
    exit;
}
unset($__bm_disabled);

if(!is_file(__DIR__.'/config.php'))exit;
require_once __DIR__.'/app.php';
require_once __DIR__.'/media.php';
try{
    App::q("DELETE FROM webhook_updates WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    App::q("UPDATE topups SET status='cancelled',reason='انقضای خودکار' WHERE status='waiting_receipt' AND created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
    App::q("DELETE FROM logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)");
    $smart=SmartPayment::processQueue(50);
    MediaQueue::maintenance();
    $media=MediaQueue::hasLiveWorkers()?['mode'=>'worker-services','processed'=>0]:MediaQueue::processNext(1);
    $lastStats=strtotime((string)App::setting('channel_stats_last_refresh',''))?:0;
    if(time()-$lastStats>=21600){
        try{$stats=MediaQueue::refreshChannelStats();App::setSetting('channel_stats_last_refresh',date('Y-m-d H:i:s'));echo 'CHANNELS: '.$stats['ok'].' OK / '.$stats['failed']." FAILED\n";}catch(Throwable $e){App::logEvent('channel_stats_cron_error',$e->getMessage());}
    }
    echo 'SMART: '.json_encode($smart,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
    echo 'MEDIA: '.json_encode($media,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\nOK\n";
}catch(Throwable $e){echo 'ERROR: '.$e->getMessage()."\n";exit(1);}
