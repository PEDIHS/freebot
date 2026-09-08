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


old_claim = '''    private static function claimJob(string $role,string $workerId): ?array
    {
        // Keep the lease operation self-contained: every claimant must have a
        // heartbeat row, including workers that find an empty queue.
        self::registerWorker($workerId,$role);
        $status=$role==='download'?'queued':'downloaded';$lease=self::lockSeconds();
        $orderGuard=$role==='download'
            ?"(COALESCE(b.sequential_mode,0)=0 OR NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND previous_job.position<=GREATEST(CAST(j.position AS SIGNED)-CAST(GREATEST(1,COALESCE(b.pipeline_depth,1)) AS SIGNED),0) AND previous_job.status NOT IN ('completed','failed','cancelled')))"
            :"(COALESCE(b.sequential_mode,0)=0 OR NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND CAST(CASE WHEN OCTET_LENGTH(previous_job.target_channel_id)>0 THEN previous_job.target_channel_id ELSE b.channel_id END AS BINARY)=CAST(CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END AS BINARY) AND previous_job.status NOT IN ('completed','failed','cancelled') AND IF(b.source_type='telegram_channel',COALESCE(previous_job.target_sequence,previous_job.position)<=GREATEST(CAST(COALESCE(j.target_sequence,j.position) AS SIGNED)-CAST(GREATEST(1,COALESCE(b.pipeline_depth,1)) AS SIGNED),0),previous_job.position<j.position)))";
        for($attempt=0;$attempt<10;$attempt++){
            $candidate=App::one("SELECT j.id FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id WHERE j.status=? AND b.status IN ('queued','running') AND COALESCE(b.scan_status,'completed')='completed' AND (j.next_attempt_at IS NULL OR j.next_attempt_at<=NOW()) AND (j.lock_expires_at IS NULL OR j.lock_expires_at<NOW()) AND {$orderGuard} ORDER BY b.id,j.position,j.id LIMIT 1",[$status]);
            if(!$candidate)return null;$token=bin2hex(random_bytes(32));$target=$role==='download'?'downloading':'uploading';$attemptColumn=$role==='download'?'download_attempts':'upload_attempts';
            $claimed=App::q("UPDATE media_jobs SET status=?,progress=IF(?='download',GREATEST(progress,1),GREATEST(progress,72)),attempts=attempts+1,{$attemptColumn}={$attemptColumn}+1,locked_by=?,lock_token=?,lock_expires_at=DATE_ADD(NOW(),INTERVAL {$lease} SECOND),heartbeat_at=NOW(),started_at=COALESCE(started_at,NOW()),next_attempt_at=NULL,error_code=NULL,error_message=NULL,updated_at=NOW() WHERE id=? AND status=? AND (lock_expires_at IS NULL OR lock_expires_at<NOW())",[$target,$role,$workerId,$token,$candidate['id'],$status])->rowCount();
            if($claimed!==1)continue;
            App::q("UPDATE media_batches b JOIN media_jobs j ON j.batch_id=b.id SET b.status='running',b.current_item_id=j.id,b.started_at=COALESCE(b.started_at,NOW()),b.updated_at=NOW() WHERE j.id=?",[$candidate['id']]);
            self::heartbeatWorker($workerId,'busy',(int)$candidate['id']);
            return App::one("SELECT j.*,b.product_id,b.channel_id,b.upload_mode,b.total_items,b.sequential_mode,b.pipeline_depth,b.status batch_status,b.title batch_title,CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END effective_channel_id,p.title product_title FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id LEFT JOIN products p ON p.id=b.product_id WHERE j.id=?",[$candidate['id']]);
        }
        return null;
    }
'''

new_claim = '''    private static function claimJob(string $role,string $workerId): ?array
    {
        // Keep transfer execution parallel, but serialize the very short download
        // admission step so large workers cannot over-commit temporary disk space.
        self::registerWorker($workerId,$role);
        $status=$role==='download'?'queued':'downloaded';$lease=self::lockSeconds();
        $orderGuard=$role==='download'
            ?"(COALESCE(b.sequential_mode,0)=0 OR NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND previous_job.position<=GREATEST(CAST(j.position AS SIGNED)-CAST(GREATEST(1,COALESCE(b.pipeline_depth,1)) AS SIGNED),0) AND previous_job.status NOT IN ('completed','failed','cancelled')))"
            :"(COALESCE(b.sequential_mode,0)=0 OR NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND CAST(CASE WHEN OCTET_LENGTH(previous_job.target_channel_id)>0 THEN previous_job.target_channel_id ELSE b.channel_id END AS BINARY)=CAST(CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END AS BINARY) AND previous_job.status NOT IN ('completed','failed','cancelled') AND IF(b.source_type='telegram_channel',COALESCE(previous_job.target_sequence,previous_job.position)<=GREATEST(CAST(COALESCE(j.target_sequence,j.position) AS SIGNED)-CAST(GREATEST(1,COALESCE(b.pipeline_depth,1)) AS SIGNED),0),previous_job.position<j.position)))";
        $claimLock=$role==='download'?'freebot-download-admission':'';$claimLocked=true;
        if($claimLock!=='')$claimLocked=(int)(App::one('SELECT GET_LOCK(?,2) acquired',[$claimLock])['acquired']??0)===1;
        if(!$claimLocked)return null;
        try{
            for($attempt=0;$attempt<10;$attempt++){
                $candidate=App::one("SELECT j.id,j.engine,j.total_bytes FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id WHERE j.status=? AND b.status IN ('queued','running') AND COALESCE(b.scan_status,'completed')='completed' AND (j.next_attempt_at IS NULL OR j.next_attempt_at<=NOW()) AND (j.lock_expires_at IS NULL OR j.lock_expires_at<NOW()) AND {$orderGuard} ORDER BY b.id,j.position,j.id LIMIT 1",[$status]);
                if(!$candidate)return null;
                if($role==='download'&&(string)($candidate['engine']??'')==='telegram-mtproto'&&(int)($candidate['total_bytes']??0)>0){
                    $storage=self::parallelStorageAdmission((int)$candidate['total_bytes']);
                    if(!($storage['ok']??false)){
                        $message='فضای موقت برای دانلود موازی کافی نیست؛ Job پس از آزادشدن فضا خودکار دوباره بررسی می‌شود.';
                        App::q("UPDATE media_jobs SET next_attempt_at=DATE_ADD(NOW(),INTERVAL 60 SECOND),error_code='DISK_SPACE_WAIT',error_message=?,updated_at=NOW() WHERE id=? AND status='queued'",[$message,$candidate['id']]);
                        self::event((int)$candidate['id'],'warning','storage',$message,$storage);
                        continue;
                    }
                }
                $token=bin2hex(random_bytes(32));$target=$role==='download'?'downloading':'uploading';$attemptColumn=$role==='download'?'download_attempts':'upload_attempts';
                $claimed=App::q("UPDATE media_jobs SET status=?,progress=IF(?='download',GREATEST(progress,1),GREATEST(progress,72)),attempts=attempts+1,{$attemptColumn}={$attemptColumn}+1,locked_by=?,lock_token=?,lock_expires_at=DATE_ADD(NOW(),INTERVAL {$lease} SECOND),heartbeat_at=NOW(),started_at=COALESCE(started_at,NOW()),next_attempt_at=NULL,error_code=NULL,error_message=NULL,updated_at=NOW() WHERE id=? AND status=? AND (lock_expires_at IS NULL OR lock_expires_at<NOW())",[$target,$role,$workerId,$token,$candidate['id'],$status])->rowCount();
                if($claimed!==1)continue;
                App::q("UPDATE media_batches b JOIN media_jobs j ON j.batch_id=b.id SET b.status='running',b.current_item_id=j.id,b.started_at=COALESCE(b.started_at,NOW()),b.updated_at=NOW() WHERE j.id=?",[$candidate['id']]);
                self::heartbeatWorker($workerId,'busy',(int)$candidate['id']);
                return App::one("SELECT j.*,b.product_id,b.channel_id,b.upload_mode,b.total_items,b.sequential_mode,b.pipeline_depth,b.status batch_status,b.title batch_title,CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END effective_channel_id,p.title product_title FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id LEFT JOIN products p ON p.id=b.product_id WHERE j.id=?",[$candidate['id']]);
            }
            return null;
        }finally{if($claimLock!=='')try{App::q('SELECT RELEASE_LOCK(?)',[$claimLock]);}catch(Throwable){}}
    }
'''
replace_once("media.php", old_claim, new_claim)

old_storage = '''    private static function assertStorageCapacity(int $expected): void{if($expected<=0)return;$free=@disk_free_space(self::storageRoot());$reserve=max(268435456,(int)ceil($expected*.10));if($free!==false&&$free<$expected+$reserve)throw new MediaQueueException('DISK_SPACE','فضای موقت کافی نیست؛ برای این فایل حداقل '.self::humanBytes($expected+$reserve).' فضای خالی لازم است.',60);}'''
new_storage = '''    private static function parallelStorageAdmission(int $expected): array
    {
        if($expected<=0)return ['ok'=>true,'expected'=>0,'active_remaining'=>0,'required'=>0];
        $free=@disk_free_space(self::storageRoot());if($free===false)return ['ok'=>true,'expected'=>$expected,'active_remaining'=>0,'required'=>$expected,'free'=>null];
        $row=App::one("SELECT COALESCE(SUM(GREATEST(CAST(total_bytes AS SIGNED)-CAST(downloaded_bytes AS SIGNED),0)),0) remaining FROM media_jobs WHERE status='downloading' AND lock_expires_at>=NOW() AND total_bytes>0");
        $active=max(0,(int)($row['remaining']??0));$future=$active+$expected;$reserve=max(268435456,(int)ceil($future*.10));$required=$future+$reserve;
        return ['ok'=>$free>=$required,'expected'=>$expected,'active_remaining'=>$active,'reserve'=>$reserve,'required'=>$required,'free'=>(int)$free];
    }
    private static function assertStorageCapacity(int $expected): void{if($expected<=0)return;$free=@disk_free_space(self::storageRoot());$reserve=max(268435456,(int)ceil($expected*.10));if($free!==false&&$free<$expected+$reserve)throw new MediaQueueException('DISK_SPACE','فضای موقت کافی نیست؛ برای این فایل حداقل '.self::humanBytes($expected+$reserve).' فضای خالی لازم است.',60);}'''
replace_once("media.php", old_storage, new_storage)

print("parallel disk admission guard applied")
