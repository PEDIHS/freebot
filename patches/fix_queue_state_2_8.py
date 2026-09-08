#!/usr/bin/env python3
from __future__ import annotations

from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]


def read(path: str) -> str:
    return (ROOT / path).read_text(encoding="utf-8")


def write(path: str, text: str) -> None:
    (ROOT / path).write_text(text, encoding="utf-8")


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected exactly one match, found {count}")
    return text.replace(old, new, 1)


def replace_count(text: str, old: str, new: str, expected: int, label: str) -> str:
    count = text.count(old)
    if count != expected:
        raise SystemExit(f"{label}: expected {expected} matches, found {count}")
    return text.replace(old, new)


# ---------------------------------------------------------------------------
# app.php — make pipeline 4 the persistent schema default and repair batches
# that were incorrectly finalized before a useful Telegram scan.
# ---------------------------------------------------------------------------
app = read("app.php")
app = replace_count(
    app,
    "pipeline_depth tinyint unsigned NOT NULL DEFAULT 1",
    "pipeline_depth tinyint unsigned NOT NULL DEFAULT 4",
    1,
    "media_batches create-table pipeline default",
)
app = replace_count(
    app,
    "'pipeline_depth'=>\"tinyint unsigned NOT NULL DEFAULT 1\"",
    "'pipeline_depth'=>\"tinyint unsigned NOT NULL DEFAULT 4\"",
    1,
    "media_batches ensure-column pipeline default",
)
app = replace_once(
    app,
    "            source_scanned_items bigint unsigned NOT NULL DEFAULT 0,\n            source_video_count bigint unsigned NOT NULL DEFAULT 0,",
    "            source_scanned_items bigint unsigned NOT NULL DEFAULT 0,\n            source_skipped_items bigint unsigned NOT NULL DEFAULT 0,\n            source_video_count bigint unsigned NOT NULL DEFAULT 0,",
    "media_batches create-table skipped counter",
)
app = replace_once(
    app,
    "            'source_scanned_items'=>\"bigint unsigned NOT NULL DEFAULT 0\",\n            'source_video_count'=>\"bigint unsigned NOT NULL DEFAULT 0\",",
    "            'source_scanned_items'=>\"bigint unsigned NOT NULL DEFAULT 0\",\n            'source_skipped_items'=>\"bigint unsigned NOT NULL DEFAULT 0\",\n            'source_video_count'=>\"bigint unsigned NOT NULL DEFAULT 0\",",
    "media_batches ensure-column skipped counter",
)
app = replace_once(
    app,
    "            $pdo->exec(\"UPDATE media_batches SET pipeline_depth=1 WHERE source_type='telegram_channel' AND pipeline_depth<>1 AND status IN ('queued','running','paused')\");\n            $pdo->exec(\"INSERT INTO settings (`key`,`value`) VALUES ('schema_version','2.6.0-editable-queues') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)\");",
    "            $pdo->exec(\"ALTER TABLE media_batches MODIFY pipeline_depth tinyint unsigned NOT NULL DEFAULT 4\");\n            $pdo->exec(\"UPDATE media_batches SET pipeline_depth=4 WHERE source_type='telegram_channel' AND pipeline_depth<4 AND status IN ('queued','running','paused')\");\n            $pdo->exec(\"UPDATE media_batches SET status=CASE WHEN scan_status='scanning' THEN 'running' ELSE 'queued' END,completed_at=NULL WHERE source_type='telegram_channel' AND scan_status IN ('queued','scanning') AND status='completed'\");\n            $pdo->exec(\"UPDATE media_batches SET status='queued',scan_status='queued',scan_attempts=0,scan_next_attempt_at=NOW(),scan_error='صف 0/0 قدیمی شناسایی شد؛ اسکن کامل با منطق جدید دوباره اجرا می‌شود.',scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,source_last_message_id=0,source_scanned_items=0,source_skipped_items=0,completed_at=NULL WHERE source_type='telegram_channel' AND status='completed' AND total_items=0 AND ((scan_attempts=0 AND source_last_message_id=0) OR (source_video_count>0 AND source_skipped_items<source_video_count))\");\n            $pdo->exec(\"INSERT INTO settings (`key`,`value`) VALUES ('schema_version','2.8.0-queue-state') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)\");",
    "replace legacy pipeline reset and add batch repair",
)
write("app.php", app)


# ---------------------------------------------------------------------------
# media.php — destination-aware dedupe, resumable cancelled batches, robust
# zero-item state machine, skipped counter, and persistent pipeline depth.
# ---------------------------------------------------------------------------
media = read("media.php")
media = replace_once(
    media,
    "public static function createTelegramChannelBatch(int $productId,string $sourceChannel,string $title='',int $maxAttempts=3,bool $skipExisting=true,string $createdBy='panel',bool $splitDestinations=false,string $secondDestination='',string $thirdDestination='',int $destinationLimit=2000,int $pipelineDepth=2): array",
    "public static function createTelegramChannelBatch(int $productId,string $sourceChannel,string $title='',int $maxAttempts=3,bool $skipExisting=true,string $createdBy='panel',bool $splitDestinations=false,string $secondDestination='',string $thirdDestination='',int $destinationLimit=2000,int $pipelineDepth=4): array",
    "Telegram batch default pipeline",
)
media = replace_once(
    media,
    "public static function updateTelegramChannelBatch(int $batchId,string $title,string $secondDestination='',string $thirdDestination='',int $destinationLimit=2000,int $maxAttempts=3): void",
    "public static function updateTelegramChannelBatch(int $batchId,string $title,string $secondDestination='',string $thirdDestination='',int $destinationLimit=2000,int $maxAttempts=3,int $pipelineDepth=4): void",
    "editable pipeline signature",
)
media = replace_once(
    media,
    "$destinationLimit=max(1,min(100000,$destinationLimit));$maxAttempts=max(1,min(5,$maxAttempts));$distribution=count($destinations)>1?'chunked':'single';$title=trim($title)!==''?mb_substr(trim($title),0,255):(string)$batch['title'];",
    "$destinationLimit=max(1,min(100000,$destinationLimit));$maxAttempts=max(1,min(5,$maxAttempts));$pipelineDepth=max(4,min(8,$pipelineDepth));$distribution=count($destinations)>1?'chunked':'single';$title=trim($title)!==''?mb_substr(trim($title),0,255):(string)$batch['title'];",
    "editable pipeline normalize",
)
media = replace_once(
    media,
    "App::q(\"UPDATE media_batches SET title=?,distribution_mode=?,destination_channels_json=?,destination_limit=?,scan_max_attempts=?,scan_status=?,scan_attempts=?,scan_next_attempt_at=?,scan_error=?,status=?,completed_at=?,updated_at=NOW() WHERE id=?\",[$title,$distribution,App::j($destinations),$distribution==='chunked'?$destinationLimit:0,$maxAttempts,$scanStatus,$scanAttempts,$scanNext,$scanError,$batchStatus,$completedAt,$batchId]);",
    "App::q(\"UPDATE media_batches SET title=?,distribution_mode=?,destination_channels_json=?,destination_limit=?,scan_max_attempts=?,pipeline_depth=?,scan_status=?,scan_attempts=?,scan_next_attempt_at=?,scan_error=?,status=?,completed_at=?,updated_at=NOW() WHERE id=?\",[$title,$distribution,App::j($destinations),$distribution==='chunked'?$destinationLimit:0,$maxAttempts,$pipelineDepth,$scanStatus,$scanAttempts,$scanNext,$scanError,$batchStatus,$completedAt,$batchId]);",
    "persist editable pipeline",
)
media = replace_once(
    media,
    "App::logEvent('telegram_channel_batch_updated','ظرفیت و مقصدهای صف انتقال ویرایش شد.',['batch_id'=>$batchId,'destinations'=>$destinations,'destination_limit'=>$distribution==='chunked'?$destinationLimit:0,'max_attempts'=>$maxAttempts]);",
    "App::logEvent('telegram_channel_batch_updated','ظرفیت، مقصدها و Pipeline صف انتقال ویرایش شد.',['batch_id'=>$batchId,'destinations'=>$destinations,'destination_limit'=>$distribution==='chunked'?$destinationLimit:0,'max_attempts'=>$maxAttempts,'pipeline_depth'=>$pipelineDepth]);",
    "editable pipeline event",
)

media = replace_once(
    media,
    "    private static function destinationForPosition(array $channels,int $limit,int $position): ?array\n    {\n        if($position<1||$limit<1||$channels===[])return null;\n        $slot=(int)floor(($position-1)/$limit)+1;\n        if(!isset($channels[$slot-1]))return null;\n        return ['channel_id'=>(string)$channels[$slot-1],'slot'=>$slot,'sequence'=>(($position-1)%$limit)+1];\n    }",
    "    private static function destinationForPosition(array $channels,int $limit,int $position): ?array\n    {\n        if($position<1||$limit<1||$channels===[])return null;\n        $slot=(int)floor(($position-1)/$limit)+1;\n        if(!isset($channels[$slot-1]))return null;\n        return ['channel_id'=>(string)$channels[$slot-1],'slot'=>$slot,'sequence'=>(($position-1)%$limit)+1];\n    }\n\n    private static function sourceAlreadyCompletedForDestinations(int $productId,string $chatId,int $messageId,int $batchId,array $destinations): bool\n    {\n        $destinations=array_values(array_unique(array_filter(array_map(static fn($value):string=>trim((string)$value),$destinations),static fn(string $value):bool=>$value!=='')));\n        if($destinations===[])return false;\n        $marks=implode(',',array_fill(0,count($destinations),'?'));\n        $params=[$productId,$chatId,$messageId,$batchId,...$destinations];\n        $sql=\"SELECT 1 FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id WHERE b.product_id=? AND j.source_chat_id=? AND j.source_message_id=? AND b.id<>? AND j.status='completed' AND j.telegram_message_id IS NOT NULL AND COALESCE(NULLIF(j.target_channel_id,''),b.channel_id) IN ({$marks}) LIMIT 1\";\n        return App::one($sql,$params)!==null;\n    }",
    "destination-aware successful dedupe helper",
)

old_retry = """    public static function retryFailed(int $batchId): int
    {
        $count=(int)(App::one(\"SELECT COUNT(*) c FROM media_jobs WHERE batch_id=? AND status='failed'\",[$batchId])['c']??0);
        $scan=(int)(App::one(\"SELECT COUNT(*) c FROM media_batches WHERE id=? AND source_type='telegram_channel' AND scan_status='failed'\",[$batchId])['c']??0);
        if($count>0)App::q(\"UPDATE media_jobs SET status=IF(file_path IS NULL,'queued','downloaded'),progress=IF(file_path IS NULL,0,70),attempts=0,download_attempts=0,upload_attempts=0,next_attempt_at=NULL,error_code=NULL,error_message=NULL,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,finished_at=NULL,updated_at=NOW() WHERE batch_id=? AND status='failed'\",[$batchId]);
        if($scan>0)App::q(\"UPDATE media_batches SET scan_status='queued',scan_attempts=0,scan_next_attempt_at=NOW(),scan_error=NULL,scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,status='queued',failed_items=0,completed_at=NULL,updated_at=NOW() WHERE id=?\",[$batchId]);
        elseif($count>0)App::q(\"UPDATE media_batches SET status='queued',failed_items=0,completed_at=NULL,updated_at=NOW() WHERE id=?\",[$batchId]);
        if($count+$scan>0)self::eventForBatch($batchId,'info','retry',($scan>0?'اسکن کانال و ':'').\"{$count} مورد برای تلاش مجدد وارد صف شد.\");
        return $count+$scan;
    }
"""
new_retry = """    public static function retryFailed(int $batchId): int
    {
        $batch=App::one('SELECT status,source_type,scan_status FROM media_batches WHERE id=?',[$batchId]);if(!$batch)return 0;
        $resumeCancelled=(string)$batch['status']==='cancelled';
        $jobWhere=$resumeCancelled?\"status IN ('failed','cancelled')\":\"status='failed'\";
        $count=(int)(App::one(\"SELECT COUNT(*) c FROM media_jobs WHERE batch_id=? AND {$jobWhere}\",[$batchId])['c']??0);
        $scan=(($batch['source_type']??'')==='telegram_channel'&&in_array((string)($batch['scan_status']??''),['failed','cancelled'],true))?1:0;
        if($count>0)App::q(\"UPDATE media_jobs SET status=IF(file_path IS NULL,'queued','downloaded'),progress=IF(file_path IS NULL,0,70),attempts=0,download_attempts=0,upload_attempts=0,next_attempt_at=NULL,error_code=NULL,error_message=NULL,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL,heartbeat_at=NULL,finished_at=NULL,updated_at=NOW() WHERE batch_id=? AND {$jobWhere}\",[$batchId]);
        if($scan>0)App::q(\"UPDATE media_batches SET scan_status='queued',scan_attempts=0,scan_next_attempt_at=NOW(),scan_error=NULL,scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,status='queued',failed_items=0,completed_at=NULL,notification_status=NULL,notification_sent_at=NULL,updated_at=NOW() WHERE id=?\",[$batchId]);
        elseif($count>0||$resumeCancelled)App::q(\"UPDATE media_batches SET status='queued',failed_items=0,completed_at=NULL,notification_status=NULL,notification_sent_at=NULL,updated_at=NOW() WHERE id=?\",[$batchId]);
        if($count+$scan>0)self::eventForBatch($batchId,'info','retry',($scan>0?'اسکن از Checkpoint و ':'').\"{$count} Job باقی‌مانده برای ادامه واقعی صف فعال شد.\");
        return $count+$scan;
    }
"""
media = replace_once(media, old_retry, new_retry, "resume cancelled batches")

media = replace_once(
    media,
    "$position=(int)(App::one('SELECT COUNT(*) c FROM media_jobs WHERE batch_id=?',[$batchId])['c']??0);$skipped=0;$checkpoint=max(0,(int)($batch['source_last_message_id']??0));$detected=max($position,(int)($batch['source_video_count']??0));$leaseSeconds=self::scanLeaseSeconds();",
    "$position=(int)(App::one('SELECT COUNT(*) c FROM media_jobs WHERE batch_id=?',[$batchId])['c']??0);$skipped=max(0,(int)($batch['source_skipped_items']??0));$checkpoint=max(0,(int)($batch['source_last_message_id']??0));$detected=max($position,(int)($batch['source_video_count']??0));$leaseSeconds=self::scanLeaseSeconds();",
    "persist skipped count across checkpoints",
)
media = replace_once(
    media,
    "if($skipExisting&&App::one(\"SELECT 1 FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id WHERE b.product_id=? AND j.source_chat_id=? AND j.source_message_id=? AND b.id<>? LIMIT 1\",[$productId,$chatId,$messageId,$batchId])){$skipped++;$checkpoint=max($checkpoint,$messageId);App::q('UPDATE media_batches SET source_last_message_id=GREATEST(source_last_message_id,?),updated_at=NOW() WHERE id=?',[$checkpoint,$batchId]);return;}",
    "if($skipExisting&&self::sourceAlreadyCompletedForDestinations($productId,$chatId,$messageId,$batchId,$destinations)){$skipped++;$checkpoint=max($checkpoint,$messageId);App::q('UPDATE media_batches SET source_last_message_id=GREATEST(source_last_message_id,?),source_skipped_items=?,updated_at=NOW() WHERE id=?',[$checkpoint,$skipped,$batchId]);return;}",
    "successful destination-aware skip",
)
media = replace_once(
    media,
    "UPDATE media_batches SET source_channel_id=?,source_channel_title=?,source_last_message_id=?,source_scanned_items=?,source_video_count=?,total_items=?,scan_status='completed'",
    "UPDATE media_batches SET source_channel_id=?,source_channel_title=?,source_last_message_id=?,source_scanned_items=?,source_skipped_items=?,source_video_count=?,total_items=?,scan_status='completed'",
    "final scan skipped field",
)
media = replace_once(
    media,
    "[$summary['channel_id']??$batch['source_channel_id']",
    "[$summary['channel_id']??$batch['source_channel_id']",
    "noop guard",
) if False else media
# The final scan update is a single dense line; insert $skipped into its values
# immediately after $scanned using a guarded fragment.
media = replace_once(
    media,
    "$channelTitle?:null,$lastId,$scanned,$detected,$position,$position>0?'queued':'completed'",
    "$channelTitle?:null,$lastId,$scanned,$skipped,$detected,$position,$position>0?'queued':'completed'",
    "final scan skipped value",
)

old_sync = """    public static function syncBatch(int $batchId): void
    {
        $counts=App::one(\"SELECT COUNT(*) total,SUM(status='completed') done,SUM(status='failed') failed,SUM(status='cancelled') cancelled,SUM(status NOT IN ('completed','failed','cancelled')) pending FROM media_jobs WHERE batch_id=?\",[$batchId]);if(!$counts)return;
        $total=(int)$counts['total'];$done=(int)$counts['done'];$failed=(int)$counts['failed'];$cancelled=(int)$counts['cancelled'];$pending=(int)$counts['pending'];
        $batch=App::one('SELECT status,scan_status,title,channel_id,destination_channels_json,notification_status FROM media_batches WHERE id=?',[$batchId]);if(!$batch)return;
        if(in_array((string)($batch['scan_status']??'completed'),['queued','scanning'],true))return;
        $status=(string)$batch['status'];
        if($status!=='cancelled'&&$status!=='paused'){
            if($pending===0)$status=$failed>0||$cancelled>0?'completed_with_errors':'completed';
            elseif($status!=='running')$status='queued';
        }
        $complete=in_array($status,['completed','completed_with_errors','cancelled'],true);
        App::q('UPDATE media_batches SET status=?,total_items=?,completed_items=?,failed_items=?,current_item_id=NULL,completed_at='.($complete?'COALESCE(completed_at,NOW())':'NULL').',updated_at=NOW() WHERE id=?',[$status,$total,$done,$failed,$batchId]);
        if($complete&&$total>0&&(string)($batch['notification_status']??'')!==$status){
            $icon=$status==='completed'?'✅':($status==='cancelled'?'⛔️':'⚠️');
            $destinations=json_decode((string)($batch['destination_channels_json']??''),true);if(!is_array($destinations)||$destinations===[])$destinations=[$batch['channel_id']];
            App::sendLog($icon.' <b>پایان دسته دانلود #'.$batchId.'</b>'.\"\\nعنوان: \".App::h($batch['title']).\"\\nمقصدها: <code>\".App::h(implode(' , ',array_map('strval',$destinations))).\"</code>\\nموفق: <b>{$done}</b> | خطا: <b>{$failed}</b> | کل: <b>{$total}</b>\");
            App::q('UPDATE media_batches SET notification_status=?,notification_sent_at=NOW() WHERE id=?',[$status,$batchId]);
        }
    }
"""
new_sync = """    public static function syncBatch(int $batchId): void
    {
        $counts=App::one(\"SELECT COUNT(*) total,SUM(status='completed') done,SUM(status='failed') failed,SUM(status='cancelled') cancelled,SUM(status NOT IN ('completed','failed','cancelled')) pending FROM media_jobs WHERE batch_id=?\",[$batchId]);if(!$counts)return;
        $total=(int)$counts['total'];$done=(int)$counts['done'];$failed=(int)$counts['failed'];$cancelled=(int)$counts['cancelled'];$pending=(int)$counts['pending'];
        $batch=App::one('SELECT status,scan_status,scan_attempts,source_type,source_video_count,source_skipped_items,title,channel_id,destination_channels_json,notification_status FROM media_batches WHERE id=?',[$batchId]);if(!$batch)return;
        $telegram=(string)($batch['source_type']??'')==='telegram_channel';$scanStatus=(string)($batch['scan_status']??'completed');
        if($telegram&&in_array($scanStatus,['queued','scanning'],true)){
            if(!in_array((string)$batch['status'],['paused','cancelled'],true)){$expected=$scanStatus==='scanning'?'running':'queued';App::q('UPDATE media_batches SET status=?,completed_at=NULL,updated_at=NOW() WHERE id=?',[$expected,$batchId]);}
            return;
        }
        if($telegram&&$scanStatus==='completed'&&$total===0){
            $scanAttempts=(int)($batch['scan_attempts']??0);$sourceVideos=(int)($batch['source_video_count']??0);$skipped=(int)($batch['source_skipped_items']??0);
            $provenEmpty=$scanAttempts>0&&$sourceVideos===0;$provenSynced=$scanAttempts>0&&$sourceVideos>0&&$skipped>=$sourceVideos;
            if(!$provenEmpty&&!$provenSynced){
                App::q(\"UPDATE media_batches SET status='queued',scan_status='queued',scan_attempts=0,scan_next_attempt_at=NOW(),scan_error='صف 0/0 نامعتبر بود؛ برای ارزیابی دوباره مقصد و موارد تکراری، اسکن از ابتدا اجرا می‌شود.',scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,source_last_message_id=0,source_scanned_items=0,source_skipped_items=0,completed_at=NULL,updated_at=NOW() WHERE id=?\",[$batchId]);
                return;
            }
        }
        $status=(string)$batch['status'];
        if($status!=='cancelled'&&$status!=='paused'){
            $zeroTelegramComplete=$telegram&&$scanStatus==='completed'&&$total===0;
            if($pending===0&&($total>0||$zeroTelegramComplete))$status=$failed>0||$cancelled>0?'completed_with_errors':'completed';
            elseif($status!=='running')$status='queued';
        }
        $complete=in_array($status,['completed','completed_with_errors','cancelled'],true);
        App::q('UPDATE media_batches SET status=?,total_items=?,completed_items=?,failed_items=?,current_item_id=NULL,completed_at='.($complete?'COALESCE(completed_at,NOW())':'NULL').',updated_at=NOW() WHERE id=?',[$status,$total,$done,$failed,$batchId]);
        if($complete&&($total>0||($telegram&&(int)($batch['source_skipped_items']??0)>0))&&(string)($batch['notification_status']??'')!==$status){
            $icon=$status==='completed'?'✅':($status==='cancelled'?'⛔️':'⚠️');
            $destinations=json_decode((string)($batch['destination_channels_json']??''),true);if(!is_array($destinations)||$destinations===[])$destinations=[$batch['channel_id']];
            App::sendLog($icon.' <b>پایان دسته دانلود #'.$batchId.'</b>'.\"\\nعنوان: \".App::h($batch['title']).\"\\nمقصدها: <code>\".App::h(implode(' , ',array_map('strval',$destinations))).\"</code>\\nموفق: <b>{$done}</b> | خطا: <b>{$failed}</b> | کل: <b>{$total}</b>\");
            App::q('UPDATE media_batches SET notification_status=?,notification_sent_at=NOW() WHERE id=?',[$status,$batchId]);
        }
    }
"""
media = replace_once(media, old_sync, new_sync, "robust batch state machine")

media = replace_once(
    media,
    "        self::cleanupStorage();self::recoverStaleJobs();\n        App::q(\"UPDATE media_batches SET scan_status='queued'",
    "        self::cleanupStorage();self::recoverStaleJobs();\n        App::q(\"UPDATE media_batches SET pipeline_depth=4 WHERE source_type='telegram_channel' AND pipeline_depth<4 AND status IN ('queued','running','paused')\");\n        App::q(\"UPDATE media_batches SET status=CASE WHEN scan_status='scanning' THEN 'running' ELSE 'queued' END,completed_at=NULL WHERE source_type='telegram_channel' AND scan_status IN ('queued','scanning') AND status='completed'\");\n        App::q(\"UPDATE media_batches SET status='queued',scan_status='queued',scan_attempts=0,scan_next_attempt_at=NOW(),scan_error='صف 0/0 قدیمی با منطق تکراری اشتباه ساخته شده بود؛ اسکن از ابتدا بازیابی شد.',scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,source_last_message_id=0,source_scanned_items=0,source_skipped_items=0,completed_at=NULL,updated_at=NOW() WHERE source_type='telegram_channel' AND status='completed' AND total_items=0 AND ((scan_attempts=0 AND source_last_message_id=0) OR (source_video_count>0 AND source_skipped_items<source_video_count))\");\n        App::q(\"UPDATE media_batches SET scan_status='queued'",
    "maintenance self-heal",
)

media = replace_once(
    media,
    "public static function recentBatches(int $limit=30): array\n    {\n        $rows=App::all('SELECT b.*,p.title product_title FROM media_batches b LEFT JOIN products p ON p.id=b.product_id ORDER BY b.id DESC LIMIT '.max(1,min(100,$limit)));",
    "public static function recentBatches(int $limit=30): array\n    {\n        $rows=App::all(\"SELECT b.*,p.title product_title FROM media_batches b LEFT JOIN products p ON p.id=b.product_id ORDER BY CASE WHEN b.scan_status='scanning' THEN 0 WHEN b.status='running' THEN 1 WHEN b.scan_status='queued' THEN 2 WHEN b.status='queued' THEN 3 WHEN b.status='paused' THEN 4 WHEN b.status='completed_with_errors' THEN 5 WHEN b.status='cancelled' THEN 6 ELSE 7 END,b.id DESC LIMIT \".max(1,min(100,$limit)));",
    "active batches first",
)
write("media.php", media)


# ---------------------------------------------------------------------------
# admin.php — actually submit/edit pipeline depth, better state display, expose
# skipped count and make resume available for cancelled scans/batches.
# ---------------------------------------------------------------------------
admin = read("admin.php")
admin = replace_once(
    admin,
    "(int)($_POST['destination_limit']??2000),1);",
    "(int)($_POST['destination_limit']??2000),(int)($_POST['pipeline_depth']??4));",
    "submit selected pipeline",
)
admin = replace_once(
    admin,
    "MediaQueue::updateTelegramChannelBatch($batchId,(string)($_POST['title']??''),(string)($_POST['second_destination_id']??''),(string)($_POST['third_destination_id']??''),(int)($_POST['destination_limit']??2000),(int)($_POST['max_attempts']??3));",
    "MediaQueue::updateTelegramChannelBatch($batchId,(string)($_POST['title']??''),(string)($_POST['second_destination_id']??''),(string)($_POST['third_destination_id']??''),(int)($_POST['destination_limit']??2000),(int)($_POST['max_attempts']??3),(int)($_POST['pipeline_depth']??4));",
    "edit selected pipeline",
)
admin = replace_once(
    admin,
    "function mediaEta(mixed $seconds):string{if($seconds===null||$seconds===''||(int)$seconds<0)return '—';$seconds=(int)$seconds;if($seconds<60)return $seconds.' ثانیه';if($seconds<3600)return intdiv($seconds,60).' دقیقه';return intdiv($seconds,3600).' ساعت '.intdiv($seconds%3600,60).' دقیقه';}",
    "function mediaEta(mixed $seconds):string{if($seconds===null||$seconds===''||(int)$seconds<0)return '—';$seconds=(int)$seconds;if($seconds<60)return $seconds.' ثانیه';if($seconds<3600)return intdiv($seconds,60).' دقیقه';return intdiv($seconds,3600).' ساعت '.intdiv($seconds%3600,60).' دقیقه';}\nfunction mediaBatchDisplay(array $batch):array{$status=(string)($batch['status']??'queued');if(($batch['source_type']??'')==='telegram_channel'){$scan=(string)($batch['scan_status']??'queued');if($scan==='queued')return ['در انتظار اسکن','wait'];if($scan==='scanning')return ['در حال ساخت فهرست','wait'];if($scan==='failed')return ['خطای اسکن','bad'];if($scan==='cancelled'&&$status==='cancelled')return ['لغوشده؛ قابل ادامه','bad'];$videos=(int)($batch['source_video_count']??0);$skipped=(int)($batch['source_skipped_items']??0);if($status==='completed'&&(int)($batch['total_items']??0)===0&&$videos>0&&$skipped>=$videos)return ['همگام؛ مورد جدیدی نبود','good'];}return [MediaQueue::statusLabel($status),mediaStatusClass($status)];}",
    "batch display helper",
)
admin = replace_once(
    admin,
    ".event.warning{border-color:#d88312;background:#fff9ec}@media(max-width:850px)",
    ".event.warning{border-color:#d88312;background:#fff9ec}.queue-row-live td{background:#f6fbff}.queue-row-live td:first-child{border-right:3px solid #2167d3}.destination-box{padding:7px 9px;margin:4px 0;border:1px solid #e5eaf1;border-radius:9px;background:#fafcff}.queue-meta{display:flex;gap:5px;flex-wrap:wrap;margin-top:5px}.queue-meta .badge{font-size:10px}.progress strong{font-size:11px}@media(max-width:850px)",
    "queue visual polish",
)
admin = replace_once(
    admin,
    "<label>حداکثر Retry<select name=\"max_attempts\"><?php foreach([2,3,4,5] as $attempt):?><option value=\"<?=$attempt?>\" <?=$attempt===(int)$editMediaBatch['scan_max_attempts']?'selected':''?>><?=$attempt?> بار</option><?php endforeach;?></select></label>",
    "<label>Pipeline همزمان<select name=\"pipeline_depth\"><?php foreach([4,5,6,7,8] as $depth):?><option value=\"<?=$depth?>\" <?=$depth===(int)$editMediaBatch['pipeline_depth']?'selected':''?>><?=$depth?> فایل</option><?php endforeach;?></select></label><label>حداکثر Retry<select name=\"max_attempts\"><?php foreach([2,3,4,5] as $attempt):?><option value=\"<?=$attempt?>\" <?=$attempt===(int)$editMediaBatch['scan_max_attempts']?'selected':''?>><?=$attempt?> بار</option><?php endforeach;?></select></label>",
    "edit pipeline field",
)
admin = replace_once(
    admin,
    "<script>document.addEventListener('DOMContentLoaded',()=>{const p=document.querySelector('[name=\"pipeline_depth\"]');if(p)p.closest('label')?.remove();const m=document.querySelector('[name=\"downloader_max_mb\"]');",
    "<script>document.addEventListener('DOMContentLoaded',()=>{const m=document.querySelector('[name=\"downloader_max_mb\"]');",
    "do not hide pipeline selector",
)
admin = replace_once(
    admin,
    "<label>عمق Pipeline (فایل همزمان روی دیسک)<select name=\"pipeline_depth\"><option value=\"1\">۱ — حداقل فضا</option><option value=\"2\" selected>۲ — پیشنهادی</option><option value=\"3\">۳</option><option value=\"4\">۴ — سریع‌تر</option></select></label>",
    "<label>عمق Pipeline (دانلود/آپلود همزمان)<select name=\"pipeline_depth\"><option value=\"4\" selected>۴ — متوازن</option><option value=\"5\">۵</option><option value=\"6\">۶ — سرور سریع</option><option value=\"7\">۷</option><option value=\"8\">۸ — حداکثر</option></select></label>",
    "new pipeline options",
)
admin = replace_once(
    admin,
    "$batchProgress=(int)$batch['total_items']>0?(int)round(((int)$batch['completed_items']+(int)$batch['failed_items'])/(int)$batch['total_items']*100):0;$scanLabel=",
    "$batchProgress=(int)$batch['total_items']>0?(int)round(((int)$batch['completed_items']+(int)$batch['failed_items'])/(int)$batch['total_items']*100):((($batch['source_type']??'')==='telegram_channel'&&($batch['scan_status']??'')==='completed'&&(int)($batch['source_video_count']??0)>0&&(int)($batch['source_skipped_items']??0)>=(int)$batch['source_video_count'])?100:0);$batchDisplay=mediaBatchDisplay($batch);$scanLabel=",
    "batch progress and display state",
)
admin = replace_once(
    admin,
    "<tr><td>#<?=$batch['id']?></td>",
    "<tr class=\"<?=in_array($batch['status'],['queued','running'],true)||in_array(($batch['scan_status']??''),['queued','scanning'],true)?'queue-row-live':''?>\"><td>#<?=$batch['id']?></td>",
    "highlight live queue rows",
)
admin = replace_once(
    admin,
    "فهرست <?=number_format((int)($batch['source_scanned_items']??0))?> از <?=number_format((int)($batch['source_video_count']??0))?> ویدیو • Checkpoint: <?=number_format((int)($batch['source_last_message_id']??0))?>",
    "فهرست <?=number_format((int)($batch['source_scanned_items']??0))?> از <?=number_format((int)($batch['source_video_count']??0))?> ویدیو • ردشده موفق: <?=number_format((int)($batch['source_skipped_items']??0))?> • Checkpoint: <?=number_format((int)($batch['source_last_message_id']??0))?>",
    "show skipped count",
)
admin = replace_once(
    admin,
    "<div><code><?=App::h($destination['channel_id'])?></code><br><span class=\"muted\">موفق <?=number_format((int)$destination['completed'])?> / خطا <?=number_format((int)$destination['failed'])?> / کل <?=number_format((int)$destination['total'])?> — <?=mediaBytes($destination['total_bytes'])?></span></div>",
    "<div class=\"destination-box\"><code><?=App::h($destination['channel_id'])?></code><br><span class=\"muted\">موفق <?=number_format((int)$destination['completed'])?> / خطا <?=number_format((int)$destination['failed'])?> / کل <?=number_format((int)$destination['total'])?> — <?=mediaBytes($destination['total_bytes'])?></span></div>",
    "destination visual box",
)
admin = replace_once(
    admin,
    "<td><span class=\"badge <?=mediaStatusClass($batch['status'])?>\"><?=App::h(MediaQueue::statusLabel($batch['status']))?></span></td>",
    "<td><span class=\"badge <?=$batchDisplay[1]?>\"><?=App::h($batchDisplay[0])?></span></td>",
    "effective batch status",
)
admin = replace_once(
    admin,
    "<td><?=$batch['completed_items']?> / <?=$batch['failed_items']?> از <?=$batch['total_items']?></td>",
    "<td><?=$batch['completed_items']?> / <?=$batch['failed_items']?> از <?=$batch['total_items']?><?php if(($batch['source_type']??'')==='telegram_channel'&&(int)($batch['source_skipped_items']??0)>0):?><br><span class=\"muted\">ردشده موفق: <?=number_format((int)$batch['source_skipped_items'])?></span><?php endif;?></td>",
    "batch result details",
)
admin = replace_once(
    admin,
    "if((int)$batch['failed_items']>0||$batch['status']==='completed_with_errors'):",
    "if((int)$batch['failed_items']>0||in_array($batch['status'],['completed_with_errors','cancelled'],true)||in_array(($batch['scan_status']??''),['failed','cancelled'],true)):",
    "resume action visibility",
)
write("admin.php", admin)


# ---------------------------------------------------------------------------
# update.sh + healthcheck: force migration/self-heal during update and verify
# the new schema counter exists.
# ---------------------------------------------------------------------------
update = read("update.sh")
update = replace_once(
    update,
    "runuser -u www-data -- /usr/bin/php -r 'require $argv[1]; App::db(); App::q(\"UPDATE media_batches SET pipeline_depth=4 WHERE source_type=? AND pipeline_depth<4 AND status IN (?,?,?)\",[\"telegram_channel\",\"queued\",\"running\",\"paused\"]);' \"$INSTALL_DIR/app.php\"",
    "runuser -u www-data -- /usr/bin/php -r 'require $argv[1]; App::db(); App::q(\"UPDATE media_batches SET pipeline_depth=4 WHERE source_type=? AND pipeline_depth<4 AND status IN (?,?,?)\",[\"telegram_channel\",\"queued\",\"running\",\"paused\"]); App::q(\"UPDATE media_batches SET status=\\\"queued\\\",scan_status=\\\"queued\\\",scan_attempts=0,scan_next_attempt_at=NOW(),scan_error=\\\"صف 0/0 قدیمی بازیابی شد؛ اسکن با منطق جدید دوباره اجرا می‌شود.\\\",scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,source_last_message_id=0,source_scanned_items=0,source_skipped_items=0,completed_at=NULL WHERE source_type=\\\"telegram_channel\\\" AND status=\\\"completed\\\" AND total_items=0 AND ((scan_attempts=0 AND source_last_message_id=0) OR (source_video_count>0 AND source_skipped_items<source_video_count))\");' \"$INSTALL_DIR/app.php\"",
    "server update batch repair",
)
write("update.sh", update)

health = read("healthcheck.sh")
health = replace_once(
    health,
    '\"source_last_message_id\",\"source_scanned_items\",\"sequential_mode\"',
    '\"source_last_message_id\",\"source_scanned_items\",\"source_skipped_items\",\"sequential_mode\"',
    "healthcheck skipped schema",
)
write("healthcheck.sh", health)


# ---------------------------------------------------------------------------
# Regression tests: schema/pipeline guard, destination-aware dedupe, invalid
# zero-item repair, and true continuation of a cancelled Telegram batch.
# ---------------------------------------------------------------------------
installer = read("tests/installer_test.sh")
installer = replace_once(
    installer,
    "grep -q \"pipelineDepth=max(4\" \"$ROOT/media.php\"\n",
    "grep -q \"pipelineDepth=max(4\" \"$ROOT/media.php\"\ngrep -q \"pipeline_depth tinyint unsigned NOT NULL DEFAULT 4\" \"$ROOT/app.php\"\n! grep -q \"SET pipeline_depth=1\" \"$ROOT/app.php\"\ngrep -q \"source_skipped_items\" \"$ROOT/app.php\"\ngrep -q \"sourceAlreadyCompletedForDestinations\" \"$ROOT/media.php\"\ngrep -q \"pipeline_depth.*4\" \"$ROOT/admin.php\"\n",
    "installer queue state assertions",
)
write("tests/installer_test.sh", installer)

qt = read("tests/queue_test.php")
qt = replace_once(
    qt,
    "ADD COLUMN source_scanned_items bigint unsigned NOT NULL DEFAULT 0,ADD COLUMN source_video_count bigint unsigned NOT NULL DEFAULT 0,ADD COLUMN sequential_mode tinyint(1) NOT NULL DEFAULT 0,ADD COLUMN pipeline_depth tinyint unsigned NOT NULL DEFAULT 1,",
    "ADD COLUMN source_scanned_items bigint unsigned NOT NULL DEFAULT 0,ADD COLUMN source_skipped_items bigint unsigned NOT NULL DEFAULT 0,ADD COLUMN source_video_count bigint unsigned NOT NULL DEFAULT 0,ADD COLUMN sequential_mode tinyint(1) NOT NULL DEFAULT 0,ADD COLUMN pipeline_depth tinyint unsigned NOT NULL DEFAULT 4,",
    "queue test schema v2.8",
)
qt = replace_once(
    qt,
    "$route=new ReflectionMethod(MediaQueue::class,'destinationForPosition');$route->setAccessible(true);",
    "$route=new ReflectionMethod(MediaQueue::class,'destinationForPosition');$route->setAccessible(true);\n$alreadyDelivered=new ReflectionMethod(MediaQueue::class,'sourceAlreadyCompletedForDestinations');$alreadyDelivered->setAccessible(true);",
    "dedupe reflection",
)
qt = replace_once(
    qt,
    "MediaQueue::cancelBatch($scanBatchId);\nexpect(App::one('SELECT scan_status FROM media_batches WHERE id=?',[$scanBatchId])['scan_status']==='cancelled','cancel must stop queued channel scan');",
    "MediaQueue::cancelBatch($scanBatchId);\nexpect(App::one('SELECT scan_status FROM media_batches WHERE id=?',[$scanBatchId])['scan_status']==='cancelled','cancel must stop queued channel scan');\n$resumedCancelled=MediaQueue::retryFailed($scanBatchId);\nexpect($resumedCancelled>=1,'continue from checkpoint must reactivate a cancelled Telegram batch');\n$resumedState=App::one('SELECT scan_status,status FROM media_batches WHERE id=?',[$scanBatchId]);\nexpect($resumedState['scan_status']==='queued'&&$resumedState['status']==='queued','cancelled scan and batch must return to the runnable queue');\nexpect((int)App::one(\"SELECT COUNT(*) c FROM media_jobs WHERE batch_id=? AND status='cancelled'\",[$scanBatchId])['c']===0,'cancelled unfinished jobs must be restored when continuing');\nMediaQueue::cancelBatch($scanBatchId);",
    "cancelled continuation regression",
)
qt = replace_once(
    qt,
    "MediaQueue::cancelBatch($stuckBatchId);\n$first=$claim->invoke(null,'download','test-download-1');",
    "MediaQueue::cancelBatch($stuckBatchId);\n\nApp::q(\"INSERT INTO media_batches(product_id,channel_id,title,source_type,source_channel_id,source_video_count,source_skipped_items,scan_status,scan_attempts,scan_max_attempts,status,total_items,created_by) VALUES (1,'-100123','Broken zero queue','telegram_channel','-100997',12,0,'completed',1,3,'completed',0,'test')\");\n$brokenZero=(int)App::db()->lastInsertId();\nMediaQueue::syncBatch($brokenZero);\n$brokenState=App::one('SELECT status,scan_status,scan_attempts,source_last_message_id FROM media_batches WHERE id=?',[$brokenZero]);\nexpect($brokenState['status']==='queued'&&$brokenState['scan_status']==='queued'&&(int)$brokenState['scan_attempts']===0,'0/0 Telegram batch with unaccounted source videos must self-heal into a fresh scan');\nMediaQueue::cancelBatch($brokenZero);\n\n$skipBatch=MediaQueue::createBatch(1,\"https://example.com/skip.mp4\",'Skip semantics');\nApp::q(\"UPDATE media_batches SET source_type='telegram_channel',source_channel_id='-100996',scan_status='completed',scan_attempts=1,status='completed' WHERE id=?\",[$skipBatch]);\nApp::q(\"UPDATE media_jobs SET status='completed',source_chat_id='-100996',source_message_id=777,target_channel_id='-100123',telegram_message_id=9001 WHERE batch_id=?\",[$skipBatch]);\nexpect($alreadyDelivered->invoke(null,1,'-100996',777,$skipBatch+1000,['-100123'])===true,'only a successfully uploaded video to a current destination may be skipped');\nexpect($alreadyDelivered->invoke(null,1,'-100996',777,$skipBatch+1000,['-100456'])===false,'a new destination must not inherit the old destination dedupe state');\nApp::q(\"UPDATE media_jobs SET status='failed',telegram_message_id=NULL WHERE batch_id=?\",[$skipBatch]);\nexpect($alreadyDelivered->invoke(null,1,'-100996',777,$skipBatch+1000,['-100123'])===false,'failed or cancelled historical jobs must never suppress a new queue');\nMediaQueue::cancelBatch($skipBatch);\n\n$first=$claim->invoke(null,'download','test-download-1');",
    "zero queue and destination dedupe regressions",
)
write("tests/queue_test.php", qt)

write("VERSION", "2.8.0-queue-state\n")

print("FreeBot 2.8.0 queue-state migration applied successfully.")
