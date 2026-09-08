#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected 1 match, got {count}")
    return text.replace(old, new, 1)


media_path = ROOT / "media.php"
media = media_path.read_text(encoding="utf-8")

old = '''        $marks=implode(',',array_fill(0,count($destinations),'?'));
        $params=[$productId,$chatId,$messageId,$batchId,...$destinations];
        $sql="SELECT 1 FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id WHERE b.product_id=? AND j.source_chat_id=? AND j.source_message_id=? AND b.id<>? AND j.status='completed' AND j.telegram_message_id IS NOT NULL AND COALESCE(NULLIF(j.target_channel_id,''),b.channel_id) IN ({$marks}) LIMIT 1";
        return App::one($sql,$params)!==null;'''
new = '''        // Do not COALESCE target_channel_id with batch.channel_id in SQL: old
        // databases may keep these columns under different collations and native
        // prepared parameters can arrive as binary, which makes MariaDB raise 1270.
        $marks=implode(',',array_fill(0,count($destinations),'CAST(? AS BINARY)'));
        $params=[$productId,$chatId,$messageId,$batchId,...$destinations,...$destinations];
        $sql="SELECT 1 FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id WHERE b.product_id=? AND j.source_chat_id=? AND j.source_message_id=? AND b.id<>? AND j.status='completed' AND j.telegram_message_id IS NOT NULL AND ((COALESCE(OCTET_LENGTH(j.target_channel_id),0)>0 AND CAST(j.target_channel_id AS BINARY) IN ({$marks})) OR (COALESCE(OCTET_LENGTH(j.target_channel_id),0)=0 AND CAST(b.channel_id AS BINARY) IN ({$marks}))) LIMIT 1";
        return App::one($sql,$params)!==null;'''
media = replace_once(media, old, new, "destination-aware dedupe")

old = '''CAST(CASE WHEN OCTET_LENGTH(previous_job.target_channel_id)>0 THEN previous_job.target_channel_id ELSE b.channel_id END AS BINARY)=CAST(CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END AS BINARY)'''
new = '''((COALESCE(OCTET_LENGTH(previous_job.target_channel_id),0)>0 AND COALESCE(OCTET_LENGTH(j.target_channel_id),0)>0 AND CAST(previous_job.target_channel_id AS BINARY)=CAST(j.target_channel_id AS BINARY)) OR (COALESCE(OCTET_LENGTH(previous_job.target_channel_id),0)>0 AND COALESCE(OCTET_LENGTH(j.target_channel_id),0)=0 AND CAST(previous_job.target_channel_id AS BINARY)=CAST(b.channel_id AS BINARY)) OR (COALESCE(OCTET_LENGTH(previous_job.target_channel_id),0)=0 AND COALESCE(OCTET_LENGTH(j.target_channel_id),0)>0 AND CAST(b.channel_id AS BINARY)=CAST(j.target_channel_id AS BINARY)) OR (COALESCE(OCTET_LENGTH(previous_job.target_channel_id),0)=0 AND COALESCE(OCTET_LENGTH(j.target_channel_id),0)=0))'''
media = replace_once(media, old, new, "upload destination order guard")

old = '''                return App::one("SELECT j.*,b.product_id,b.channel_id,b.upload_mode,b.total_items,b.sequential_mode,b.pipeline_depth,b.status batch_status,b.title batch_title,CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END effective_channel_id,p.title product_title FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id LEFT JOIN products p ON p.id=b.product_id WHERE j.id=?",[$candidate['id']]);'''
new = '''                $row=App::one("SELECT j.*,b.product_id,b.channel_id,b.upload_mode,b.total_items,b.sequential_mode,b.pipeline_depth,b.status batch_status,b.title batch_title,p.title product_title FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id LEFT JOIN products p ON p.id=b.product_id WHERE j.id=?",[$candidate['id']]);
                if($row!==null)$row['effective_channel_id']=trim((string)($row['target_channel_id']??''))!==''?(string)$row['target_channel_id']:(string)$row['channel_id'];
                return $row;'''
media = replace_once(media, old, new, "claimed job effective channel")

old = '''    public static function batchDestinationStats(int $batchId): array
    {
        return App::all("SELECT MAX(CAST(CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END AS BINARY)) channel_id,MIN(COALESCE(j.target_slot,1)) target_slot,COUNT(*) total,SUM(j.status='completed') completed,SUM(j.status='failed') failed,SUM(j.status='cancelled') cancelled,SUM(j.status NOT IN ('completed','failed','cancelled')) pending,COALESCE(SUM(j.total_bytes),0) total_bytes FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id WHERE j.batch_id=? GROUP BY CAST(CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END AS BINARY) ORDER BY target_slot",[$batchId]);
    }

    public static function recentJobs(int $limit=100): array{return App::all("SELECT j.*,b.title batch_title,b.channel_id,CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END effective_channel_id,p.title product_title FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id LEFT JOIN products p ON p.id=b.product_id ORDER BY CASE j.status WHEN 'downloading' THEN 0 WHEN 'uploading' THEN 1 WHEN 'downloaded' THEN 2 WHEN 'queued' THEN 3 WHEN 'failed' THEN 4 WHEN 'cancelled' THEN 5 ELSE 6 END,CASE WHEN j.status IN ('downloading','uploading') THEN j.updated_at END DESC,j.id DESC LIMIT ".max(1,min(500,$limit)));}'''
new = '''    public static function batchDestinationStats(int $batchId): array
    {
        // target_slot is numeric and stable, so group by it and resolve the
        // display channel in PHP instead of mixing differently-collated strings.
        $rows=App::all("SELECT MAX(j.target_channel_id) job_channel_id,MAX(b.channel_id) batch_channel_id,COALESCE(j.target_slot,1) target_slot,COUNT(*) total,SUM(j.status='completed') completed,SUM(j.status='failed') failed,SUM(j.status='cancelled') cancelled,SUM(j.status NOT IN ('completed','failed','cancelled')) pending,COALESCE(SUM(j.total_bytes),0) total_bytes FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id WHERE j.batch_id=? GROUP BY COALESCE(j.target_slot,1) ORDER BY target_slot",[$batchId]);
        foreach($rows as &$row){$target=trim((string)($row['job_channel_id']??''));$row['channel_id']=$target!==''?$target:(string)($row['batch_channel_id']??'');unset($row['job_channel_id'],$row['batch_channel_id']);}unset($row);
        return $rows;
    }

    public static function recentJobs(int $limit=100): array
    {
        $rows=App::all("SELECT j.*,b.title batch_title,b.channel_id,p.title product_title FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id LEFT JOIN products p ON p.id=b.product_id ORDER BY CASE j.status WHEN 'downloading' THEN 0 WHEN 'uploading' THEN 1 WHEN 'downloaded' THEN 2 WHEN 'queued' THEN 3 WHEN 'failed' THEN 4 WHEN 'cancelled' THEN 5 ELSE 6 END,CASE WHEN j.status IN ('downloading','uploading') THEN j.updated_at END DESC,j.id DESC LIMIT ".max(1,min(500,$limit)));
        foreach($rows as &$row)$row['effective_channel_id']=trim((string)($row['target_channel_id']??''))!==''?(string)$row['target_channel_id']:(string)$row['channel_id'];unset($row);
        return $rows;
    }'''
media = replace_once(media, old, new, "stats and recent jobs collation-safe projection")

media_path.write_text(media, encoding="utf-8")

test_path = ROOT / "tests/queue_test.php"
test = test_path.read_text(encoding="utf-8")
old = '''expect($alreadyDelivered->invoke(null,1,'-100996',777,$skipBatch+1000,['-100123'])===true,'only a successfully uploaded video to a current destination may be skipped');
expect($alreadyDelivered->invoke(null,1,'-100996',777,$skipBatch+1000,['-100456'])===false,'a new destination must not inherit the old destination dedupe state');'''
new = '''expect($alreadyDelivered->invoke(null,1,'-100996',777,$skipBatch+1000,['-100123'])===true,'only a successfully uploaded video to a current destination may be skipped');
expect($alreadyDelivered->invoke(null,1,'-100996',777,$skipBatch+1000,['-100456'])===false,'a new destination must not inherit the old destination dedupe state');
// Production can expose native prepared parameters as binary while legacy
// channel columns use utf8mb4_bin / utf8mb4_unicode_ci. Reproduce that exact
// class of MariaDB 1270 failure and ensure destination dedupe remains safe.
$pdo->exec("SET NAMES binary");
expect($alreadyDelivered->invoke(null,1,'-100996',777,$skipBatch+1000,['-100123','-100456'])===true,'multi-destination dedupe must be safe with binary prepared parameters');
$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");'''
test = replace_once(test, old, new, "binary prepared parameter regression test")

test_path.write_text(test, encoding="utf-8")

version_path = ROOT / "VERSION"
version_path.write_text("2.8.1-collation-safe\n", encoding="utf-8")

print("2.8.1 collation-safe queue patch applied")
