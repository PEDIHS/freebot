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

p,app=rw('app.php')
app=replace_once(
    app,
    '''                $pdo->exec("UPDATE media_batches SET pipeline_depth=4 WHERE source_type='telegram_channel' AND pipeline_depth<4 AND status IN ('queued','running','paused')");''',
    '''                $pdo->exec("UPDATE media_batches SET pipeline_depth=4 WHERE source_type='telegram_channel' AND pipeline_depth<4");''',
    'upgrade all Telegram batch pipeline metadata',
)
p.write_text(app,encoding='utf-8')

p,media=rw('media.php')
media=replace_once(
    media,
    '''        if($scan>0)App::q("UPDATE media_batches SET scan_status='queued',scan_attempts=0,scan_next_attempt_at=NOW(),scan_error=NULL,scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,status='queued',failed_items=0,completed_at=NULL,notification_status=NULL,notification_sent_at=NULL,updated_at=NOW() WHERE id=?",[$batchId]);
        elseif($count>0||$resumeCancelled)App::q("UPDATE media_batches SET status='queued',failed_items=0,completed_at=NULL,notification_status=NULL,notification_sent_at=NULL,updated_at=NOW() WHERE id=?",[$batchId]);''',
    '''        if($scan>0)App::q("UPDATE media_batches SET scan_status='queued',scan_attempts=0,scan_next_attempt_at=NOW(),scan_error=NULL,scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,status='queued',pipeline_depth=IF(source_type='telegram_channel',GREATEST(pipeline_depth,4),pipeline_depth),failed_items=0,completed_at=NULL,notification_status=NULL,notification_sent_at=NULL,updated_at=NOW() WHERE id=?",[$batchId]);
        elseif($count>0||$resumeCancelled)App::q("UPDATE media_batches SET status='queued',pipeline_depth=IF(source_type='telegram_channel',GREATEST(pipeline_depth,4),pipeline_depth),failed_items=0,completed_at=NULL,notification_status=NULL,notification_sent_at=NULL,updated_at=NOW() WHERE id=?",[$batchId]);''',
    'upgrade pipeline immediately on resume',
)
p.write_text(media,encoding='utf-8')

p,test=rw('tests/queue_test.php')
test=replace_once(
    test,
    '''$resumedState=App::one('SELECT scan_status,status FROM media_batches WHERE id=?',[$scanBatchId]);
expect($resumedState['scan_status']==='queued'&&$resumedState['status']==='queued','cancelled scan and batch must return to the runnable queue');''',
    '''$resumedState=App::one('SELECT scan_status,status,pipeline_depth FROM media_batches WHERE id=?',[$scanBatchId]);
expect($resumedState['scan_status']==='queued'&&$resumedState['status']==='queued','cancelled scan and batch must return to the runnable queue');
expect((int)$resumedState['pipeline_depth']>=4,'resumed Telegram batches must upgrade to the parallel pipeline immediately');''',
    'cancelled pipeline regression',
)
p.write_text(test,encoding='utf-8')

print('cancelled Telegram pipeline repair applied')
