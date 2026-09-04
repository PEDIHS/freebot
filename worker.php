<?php
declare(strict_types=1);

if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$options=getopt('', ['role:','id::','limit::','once','sleep::','wait-config']);
$waitConfig=array_key_exists('wait-config',$options);
while(!is_file(__DIR__.'/config.php')){if(!$waitConfig){fwrite(STDERR,"FreeBot is not installed: config.php is missing.\n");exit(2);}sleep(5);clearstatcache(true,__DIR__.'/config.php');}

require_once __DIR__.'/app.php';
require_once __DIR__.'/media.php';

$role=(string)($options['role']??'');
if(!in_array($role,['download','upload'],true)){fwrite(STDERR,"Usage: php worker.php --role=download|upload [--id=name] [--once]\n");exit(2);}
$workerId=trim((string)($options['id']??''));
if($workerId==='')$workerId=$role.'-'.(gethostname()?:'localhost').'-'.getmypid();
$limit=max(1,min(20,(int)($options['limit']??1)));$sleep=max(1,min(30,(int)($options['sleep']??2)));$once=array_key_exists('once',$options);
$running=true;
if(function_exists('pcntl_async_signals')&&function_exists('pcntl_signal')){
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM,static function()use(&$running):void{$running=false;});
    pcntl_signal(SIGINT,static function()use(&$running):void{$running=false;});
}

try{
    while($running){
        $result=$role==='download'?MediaQueue::processDownloadNext($limit,$workerId):MediaQueue::processUploadNext($limit,$workerId);
        if($once){echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;break;}
        if((int)$result['processed']===0){MediaQueue::heartbeatWorker($workerId,'idle');sleep($sleep);}
    }
    MediaQueue::heartbeatWorker($workerId,'stopped');
}catch(Throwable $e){
    try{MediaQueue::heartbeatWorker($workerId,'error',null,$e->getMessage());}catch(Throwable){}
    fwrite(STDERR,'Worker error: '.$e->getMessage().PHP_EOL);exit(1);
}
