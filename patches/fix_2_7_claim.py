#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def replace_once(path: str, old: str, new: str) -> None:
    target = ROOT / path
    text = target.read_text(encoding="utf-8")
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected exactly one match, found {count}")
    target.write_text(text.replace(old, new, 1), encoding="utf-8")


replace_once(
    "media.php",
    '''            :"(COALESCE(b.sequential_mode,0)=0 OR (b.source_type='telegram_channel' AND NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND CAST(CASE WHEN OCTET_LENGTH(previous_job.target_channel_id)>0 THEN previous_job.target_channel_id ELSE b.channel_id END AS BINARY)=CAST(CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END AS BINARY) AND COALESCE(previous_job.target_sequence,previous_job.position)<=GREATEST(CAST(COALESCE(j.target_sequence,j.position) AS SIGNED)-CAST(GREATEST(1,COALESCE(b.pipeline_depth,1)) AS SIGNED),0) AND previous_job.status NOT IN ('completed','failed','cancelled'))) OR (b.source_type<>'telegram_channel' AND NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND previous_job.position<j.position AND CAST(CASE WHEN OCTET_LENGTH(previous_job.target_channel_id)>0 THEN previous_job.target_channel_id ELSE b.channel_id END AS BINARY)=CAST(CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END AS BINARY) AND previous_job.status NOT IN ('completed','failed','cancelled')))))";''',
    '''            :"(COALESCE(b.sequential_mode,0)=0 OR NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND CAST(CASE WHEN OCTET_LENGTH(previous_job.target_channel_id)>0 THEN previous_job.target_channel_id ELSE b.channel_id END AS BINARY)=CAST(CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END AS BINARY) AND previous_job.status NOT IN ('completed','failed','cancelled') AND IF(b.source_type='telegram_channel',COALESCE(previous_job.target_sequence,previous_job.position)<=GREATEST(CAST(COALESCE(j.target_sequence,j.position) AS SIGNED)-CAST(GREATEST(1,COALESCE(b.pipeline_depth,1)) AS SIGNED),0),previous_job.position<j.position)))";''',
)

old_test = '''$pipelineFirst=$claim->invoke(null,'download','pipeline-download-1');
expect(is_array($pipelineFirst)&&(int)$pipelineFirst['position']===1,'pipeline first video must be claimable');
App::q("UPDATE media_jobs SET status='downloaded',progress=70,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL WHERE id=?",[$pipelineFirst['id']]);
$pipelineSecond=$claim->invoke(null,'download','pipeline-download-2');
expect($pipelineSecond===null,'Telegram relay must never download a second file before the first upload completes');
$pipelineUploadOne=$claim->invoke(null,'upload','pipeline-upload-1');
expect(is_array($pipelineUploadOne)&&(int)$pipelineUploadOne['position']===1,'first destination upload must start in order');
App::q("UPDATE media_jobs SET status='completed',progress=100,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL WHERE id=?",[$pipelineUploadOne['id']]);
$pipelineSecond=$claim->invoke(null,'download','pipeline-download-3');
expect(is_array($pipelineSecond)&&(int)$pipelineSecond['position']===2,'next Telegram download must start only after successful upload');
App::q("UPDATE media_jobs SET status='downloaded',progress=70,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL WHERE id=?",[$pipelineSecond['id']]);
$pipelineUploadSecond=$claim->invoke(null,'upload','pipeline-upload-2');
expect(is_array($pipelineUploadSecond)&&(int)$pipelineUploadSecond['position']===2,'second upload must follow the first in strict relay order');
App::q("UPDATE media_jobs SET status='completed',progress=100,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL WHERE id=?",[$pipelineUploadSecond['id']]);
App::q("UPDATE media_jobs SET status='downloaded',progress=70 WHERE batch_id=? AND position=3",[$pipelineBatch]);
$pipelineUploadTwo=$claim->invoke(null,'upload','pipeline-upload-3');
expect(is_array($pipelineUploadTwo)&&(int)$pipelineUploadTwo['position']===3,'destination routing must remain stable');
expect($claim->invoke(null,'upload','pipeline-upload-4')===null,'same destination must preserve video order');'''

new_test = '''$pipelineFirst=$claim->invoke(null,'download','pipeline-download-1');
$pipelineSecond=$claim->invoke(null,'download','pipeline-download-2');
$pipelineThird=$claim->invoke(null,'download','pipeline-download-3');
expect(is_array($pipelineFirst)&&(int)$pipelineFirst['position']===1,'pipeline first video must be claimable');
expect(is_array($pipelineSecond)&&(int)$pipelineSecond['position']===2,'second Telegram download must be claimable in parallel');
expect(is_array($pipelineThird)&&(int)$pipelineThird['position']===3,'third Telegram download must be claimable in parallel');
expect($claim->invoke(null,'download','pipeline-download-4')===null,'no fourth job exists in the test pipeline');
$activeJobs=MediaQueue::recentJobs(20);
expect(($activeJobs[0]['status']??'')==='downloading','active downloads must be sorted to the top of the Media list');
App::q("UPDATE media_jobs SET status='downloaded',progress=70,locked_by=NULL,lock_token=NULL,lock_expires_at=NULL WHERE batch_id=?",[$pipelineBatch]);
$pipelineUploadOne=$claim->invoke(null,'upload','pipeline-upload-1');
$pipelineUploadSecond=$claim->invoke(null,'upload','pipeline-upload-2');
$pipelineUploadThird=$claim->invoke(null,'upload','pipeline-upload-3');
expect(is_array($pipelineUploadOne)&&(int)$pipelineUploadOne['position']===1,'first parallel upload must be claimable');
expect(is_array($pipelineUploadSecond)&&(int)$pipelineUploadSecond['position']===2,'second upload to the same destination must be claimable inside the pipeline window');
expect(is_array($pipelineUploadThird)&&(int)$pipelineUploadThird['position']===3,'upload to the second destination must be claimable concurrently');
expect($claim->invoke(null,'upload','pipeline-upload-4')===null,'all pipeline upload jobs must already be leased');'''

replace_once("tests/queue_test.php", old_test, new_test)
print("parallel claim SQL and regression tests fixed")
