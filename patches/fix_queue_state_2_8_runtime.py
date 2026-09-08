#!/usr/bin/env python3
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]

def rw(path):
    p=ROOT/path
    return p,p.read_text(encoding='utf-8')

def replace_once(text,old,new,label):
    count=text.count(old)
    if count!=1:
        raise SystemExit(f'{label}: expected 1 match, got {count}')
    return text.replace(old,new,1)

# Run the heavy queue-state repair exactly once. Keep the generic schema at
# pipeline 1; Telegram queues explicitly use 4-8 and active Telegram queues
# are upgraded to at least 4 by this migration.
p,app=rw('app.php')
old='''            $pdo->exec("ALTER TABLE media_batches MODIFY pipeline_depth tinyint unsigned NOT NULL DEFAULT 4");
            $pdo->exec("UPDATE media_batches SET pipeline_depth=4 WHERE source_type='telegram_channel' AND pipeline_depth<4 AND status IN ('queued','running','paused')");
            $pdo->exec("UPDATE media_batches SET status=CASE WHEN scan_status='scanning' THEN 'running' ELSE 'queued' END,completed_at=NULL WHERE source_type='telegram_channel' AND scan_status IN ('queued','scanning') AND status='completed'");
            $pdo->exec("UPDATE media_batches SET status='queued',scan_status='queued',scan_attempts=0,scan_next_attempt_at=NOW(),scan_error='صف 0/0 قدیمی شناسایی شد؛ اسکن کامل با منطق جدید دوباره اجرا می‌شود.',scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,source_last_message_id=0,source_scanned_items=0,source_skipped_items=0,completed_at=NULL WHERE source_type='telegram_channel' AND status='completed' AND total_items=0 AND ((scan_attempts=0 AND source_last_message_id=0) OR (source_video_count>0 AND source_skipped_items<source_video_count))");
            $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('schema_version','2.8.0-queue-state') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");'''
new='''            if (version_compare($schemaVersion, '2.8.0-queue-state', '<')) {
                $pdo->exec("ALTER TABLE media_batches MODIFY pipeline_depth tinyint unsigned NOT NULL DEFAULT 1");
                $pdo->exec("UPDATE media_batches SET pipeline_depth=4 WHERE source_type='telegram_channel' AND pipeline_depth<4 AND status IN ('queued','running','paused')");
                $pdo->exec("UPDATE media_batches SET status=CASE WHEN scan_status='scanning' THEN 'running' ELSE 'queued' END,completed_at=NULL WHERE source_type='telegram_channel' AND scan_status IN ('queued','scanning') AND status='completed'");
                $pdo->exec("UPDATE media_batches SET status='queued',scan_status='queued',scan_attempts=0,scan_next_attempt_at=NOW(),scan_error='صف 0/0 قدیمی شناسایی شد؛ اسکن کامل با منطق جدید دوباره اجرا می‌شود.',scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,source_last_message_id=0,source_scanned_items=0,source_skipped_items=0,completed_at=NULL WHERE source_type='telegram_channel' AND status='completed' AND total_items=0 AND ((scan_attempts=0 AND source_last_message_id=0) OR (source_video_count>0 AND source_skipped_items<source_video_count))");
                $pdo->exec("INSERT INTO settings (`key`,`value`) VALUES ('schema_version','2.8.0-queue-state') ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)");
            }'''
app=replace_once(app,old,new,'one-time schema migration')
app=replace_once(
    app,
    '            pipeline_depth tinyint unsigned NOT NULL DEFAULT 4,',
    '            pipeline_depth tinyint unsigned NOT NULL DEFAULT 1,',
    'media_batches create default',
)
app=replace_once(
    app,
    "            'pipeline_depth'=>\"tinyint unsigned NOT NULL DEFAULT 4\",",
    "            'pipeline_depth'=>\"tinyint unsigned NOT NULL DEFAULT 1\",",
    'media_batches missing-column default',
)
p.write_text(app,encoding='utf-8')

# update.sh only boots App::db(); app.php owns all migrations. This avoids
# fragile escaped SQL in php -r on production servers.
p,update=rw('update.sh')
old='''  runuser -u www-data -- /usr/bin/php -r 'require $argv[1]; App::db(); App::q(\"UPDATE media_batches SET pipeline_depth=4 WHERE source_type=? AND pipeline_depth<4 AND status IN (?,?,?)\",[\"telegram_channel\",\"queued\",\"running\",\"paused\"]); App::q(\"UPDATE media_batches SET status=\\\"queued\\\",scan_status=\\\"queued\\\",scan_attempts=0,scan_next_attempt_at=NOW(),scan_error=\\\"صف 0/0 قدیمی بازیابی شد؛ اسکن با منطق جدید دوباره اجرا می‌شود.\\\",scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,source_last_message_id=0,source_scanned_items=0,source_skipped_items=0,completed_at=NULL WHERE source_type=\\\"telegram_channel\\\" AND status=\\\"completed\\\" AND total_items=0 AND ((scan_attempts=0 AND source_last_message_id=0) OR (source_video_count>0 AND source_skipped_items<source_video_count))\");' \"$INSTALL_DIR/app.php\"'''
new='''  runuser -u www-data -- /usr/bin/php -r 'require $argv[1]; App::db();' "$INSTALL_DIR/app.php"'''
update=replace_once(update,old,new,'safe update migration command')
p.write_text(update,encoding='utf-8')

# Generic sequential queues retain pipeline 1; the Telegram regression test
# explicitly sets depth 4 and continues to validate parallel claims.
p,queue=rw('tests/queue_test.php')
queue=replace_once(
    queue,
    "ADD COLUMN sequential_mode tinyint(1) NOT NULL DEFAULT 0,ADD COLUMN pipeline_depth tinyint unsigned NOT NULL DEFAULT 4,",
    "ADD COLUMN sequential_mode tinyint(1) NOT NULL DEFAULT 0,ADD COLUMN pipeline_depth tinyint unsigned NOT NULL DEFAULT 1,",
    'queue test generic pipeline default',
)
p.write_text(queue,encoding='utf-8')

p,test=rw('tests/installer_test.sh')
test=replace_once(
    test,
    'grep -q "pipeline_depth tinyint unsigned NOT NULL DEFAULT 4" "$ROOT/app.php"',
    'grep -q "pipeline_depth tinyint unsigned NOT NULL DEFAULT 1" "$ROOT/app.php"',
    'installer generic pipeline default',
)
needle='''grep -q "sourceAlreadyCompletedForDestinations" "$ROOT/media.php"
grep -q "pipeline_depth.*4" "$ROOT/admin.php"
'''
replacement='''grep -q "sourceAlreadyCompletedForDestinations" "$ROOT/media.php"
grep -q "pipeline_depth.*4" "$ROOT/admin.php"
grep -Fq 'version_compare($schemaVersion' "$ROOT/app.php"
grep -Fq '2.8.0-queue-state' "$ROOT/app.php"
grep -Fq 'App::db();' "$ROOT/update.sh"
! grep -Fq 'App::q(\"UPDATE media_batches' "$ROOT/update.sh"
'''
test=replace_once(test,needle,replacement,'runtime migration installer assertions')
p.write_text(test,encoding='utf-8')

print('2.8 runtime migration hardening applied')
