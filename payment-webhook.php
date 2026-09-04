<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
if(!is_file(__DIR__.'/config.php')){http_response_code(503);echo json_encode(['ok'=>false,'error'=>'not_installed']);exit;}
require_once __DIR__.'/app.php';

function paymentWebhookResponse(int $status,array $payload): never
{
    http_response_code($status);
    echo json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try{
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST'){
        header('Allow: POST');
        paymentWebhookResponse(405,['ok'=>false,'error'=>'method_not_allowed','hint'=>'POST JSON with a message field.']);
    }

    $expected=SmartPayment::ensureShortcutToken(false);
    $provided=(string)($_GET['token']??'');
    if($provided===''&&!empty($_SERVER['HTTP_X_PAYMENT_TOKEN']))$provided=(string)$_SERVER['HTTP_X_PAYMENT_TOKEN'];
    if($provided===''&&!empty($_SERVER['HTTP_AUTHORIZATION'])&&preg_match('/Bearer\s+(.+)/i',(string)$_SERVER['HTTP_AUTHORIZATION'],$m))$provided=trim($m[1]);
    if($provided===''||!hash_equals($expected,$provided))paymentWebhookResponse(403,['ok'=>false,'error'=>'invalid_token']);

    $raw=file_get_contents('php://input')?:'';
    $contentType=strtolower((string)($_SERVER['CONTENT_TYPE']??''));
    $message='';$source='shortcut';
    if(str_contains($contentType,'application/json')){
        $json=json_decode($raw,true);
        if(is_array($json)){
            $message=trim((string)($json['message']??$json['text']??$json['sms']??''));
            $source=trim((string)($json['source']??'shortcut'))?:'shortcut';
        }
    }elseif(!empty($_POST)){
        $message=trim((string)($_POST['message']??$_POST['text']??$_POST['sms']??''));
        $source=trim((string)($_POST['source']??'shortcut'))?:'shortcut';
    }else{
        $message=trim($raw);
    }
    if($message==='')paymentWebhookResponse(422,['ok'=>false,'error'=>'message_required']);

    $result=SmartPayment::ingestSms($message,$source,(string)($_SERVER['REMOTE_ADDR']??''));
    if(empty($result['ok'])){
        $status=($result['error']??'')==='rate_limited'?429:422;
        paymentWebhookResponse($status,$result);
    }
    paymentWebhookResponse(200,$result+['message'=>'SMS stored and smart matcher executed.']);
}catch(Throwable $e){
    try{App::logEvent('payment_webhook_error',$e->getMessage());}catch(Throwable){}
    paymentWebhookResponse(500,['ok'=>false,'error'=>'internal_error']);
}
