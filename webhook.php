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

if(!is_file(__DIR__.'/config.php')){http_response_code(200);exit;}
require_once __DIR__.'/app.php';
try{
    $expected=(string)App::setting('webhook_secret','');
    $received=(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'');
    if($expected===''||!hash_equals($expected,$received)){http_response_code(403);exit;}
    $raw=file_get_contents('php://input');$update=json_decode($raw?:'',true);
    if(is_array($update))App::handleUpdate($update);
}catch(Throwable $e){
    App::logEvent('webhook_error',$e->getMessage(),['trace'=>substr($e->getTraceAsString(),0,3000)]);
}
http_response_code(200);echo 'OK';
