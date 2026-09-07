<?php
declare(strict_types=1);

$root=dirname(__DIR__);$which=[];$whichCode=0;exec('command -v python3',$which,$whichCode);
if($whichCode!==0||!isset($which[0])||!is_executable($which[0]))throw new RuntimeException('python3 is unavailable.');
$token=bin2hex(random_bytes(16));$command=[is_executable('/usr/bin/timeout')?'/usr/bin/timeout':'timeout','--signal=TERM','--kill-after=5s','10s',$which[0],$root.'/scripts/channel_history_scan.py','--transport-probe',$token];
$lines=[];$exitCode=0;exec(implode(' ',array_map('escapeshellarg',$command)).' 2>&1',$lines,$exitCode);
if($exitCode!==0)throw new RuntimeException('Transport probe exited with code '.$exitCode.': '.implode("\n",$lines));
$payload=null;for($index=count($lines)-1;$index>=0;$index--){$decoded=json_decode(trim($lines[$index]),true);if(is_array($decoded)){$payload=$decoded;break;}}
if(!is_array($payload)||!($payload['ok']??false)||!hash_equals($token,(string)($payload['probe']??'')))throw new RuntimeException('Transport probe returned invalid JSON: '.implode("\n",$lines));
echo "Telethon exec transport test passed.\n";
