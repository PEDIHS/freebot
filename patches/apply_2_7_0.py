#!/usr/bin/env python3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def replace_once(path: str, old: str, new: str) -> None:
    target = ROOT / path
    text = target.read_text(encoding="utf-8")
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected exactly one match, found {count}: {old[:120]!r}")
    target.write_text(text.replace(old, new, 1), encoding="utf-8")


# Media pipeline: use a bounded sliding window instead of forcing Telegram transfers to one file.
replace_once(
    "media.php",
    "$maxAttempts=max(1,min(5,$maxAttempts));$createdBy=mb_substr($createdBy,0,64);$destinationLimit=max(1,min(100000,$destinationLimit));$pipelineDepth=1;",
    "$maxAttempts=max(1,min(5,$maxAttempts));$createdBy=mb_substr($createdBy,0,64);$destinationLimit=max(1,min(100000,$destinationLimit));$pipelineDepth=max(4,min(8,$pipelineDepth));",
)

replace_once(
    "media.php",
    '''        $orderGuard=$role==='download'\n            ?"(COALESCE(b.sequential_mode,0)=0 OR NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND previous_job.position<=GREATEST(CAST(j.position AS SIGNED)-CAST(CASE WHEN b.source_type='telegram_channel' THEN 1 ELSE GREATEST(1,COALESCE(b.pipeline_depth,1)) END AS SIGNED),0) AND previous_job.status NOT IN ('completed','failed','cancelled')))"\n            :"(COALESCE(b.sequential_mode,0)=0 OR NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND previous_job.position<j.position AND CAST(CASE WHEN OCTET_LENGTH(previous_job.target_channel_id)>0 THEN previous_job.target_channel_id ELSE b.channel_id END AS BINARY)=CAST(CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END AS BINARY) AND previous_job.status NOT IN ('completed','failed','cancelled')))";\n        $engineGuard=$role==='download'?" AND (COALESCE(j.engine,'')<>'telegram-mtproto' OR NOT EXISTS (SELECT 1 FROM media_jobs active_tg WHERE active_tg.engine='telegram-mtproto' AND active_tg.status='downloading' AND active_tg.lock_expires_at>=NOW()))":'';''',
    '''        $orderGuard=$role==='download'\n            ?"(COALESCE(b.sequential_mode,0)=0 OR NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND previous_job.position<=GREATEST(CAST(j.position AS SIGNED)-CAST(GREATEST(1,COALESCE(b.pipeline_depth,1)) AS SIGNED),0) AND previous_job.status NOT IN ('completed','failed','cancelled')))"\n            :"(COALESCE(b.sequential_mode,0)=0 OR (b.source_type='telegram_channel' AND NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND CAST(CASE WHEN OCTET_LENGTH(previous_job.target_channel_id)>0 THEN previous_job.target_channel_id ELSE b.channel_id END AS BINARY)=CAST(CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END AS BINARY) AND COALESCE(previous_job.target_sequence,previous_job.position)<=GREATEST(CAST(COALESCE(j.target_sequence,j.position) AS SIGNED)-CAST(GREATEST(1,COALESCE(b.pipeline_depth,1)) AS SIGNED),0) AND previous_job.status NOT IN ('completed','failed','cancelled'))) OR (b.source_type<>'telegram_channel' AND NOT EXISTS (SELECT 1 FROM media_jobs previous_job WHERE previous_job.batch_id=j.batch_id AND previous_job.position<j.position AND CAST(CASE WHEN OCTET_LENGTH(previous_job.target_channel_id)>0 THEN previous_job.target_channel_id ELSE b.channel_id END AS BINARY)=CAST(CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END AS BINARY) AND previous_job.status NOT IN ('completed','failed','cancelled')))))";''',
)
replace_once(
    "media.php",
    "AND {$orderGuard}{$engineGuard} ORDER BY b.id,j.position,j.id LIMIT 1",
    "AND {$orderGuard} ORDER BY b.id,j.position,j.id LIMIT 1",
)

# Transfer workers use independent in-memory Telethon sessions cloned from the authorized auth key.
replace_once(
    "media.php",
    '''        $lockName='freebot-mtproto-session';\n        $locked=(int)(App::one('SELECT GET_LOCK(?,0) acquired',[$lockName])['acquired']??0)===1;\n        if(!$locked)throw new MediaQueueException('MTPROTO_BUSY','نشست تلگرام در حال استفاده است؛ Job خودکار دوباره تلاش می‌شود.',10);\n''',
    "",
)
replace_once(
    "media.php",
    "'--session',$scanner['session_base'],'--config',$runtime['config'],'--result-file',$resultFile];",
    "'--session',$scanner['session_base'],'--parallel-session','--config',$runtime['config'],'--result-file',$resultFile];",
)
replace_once(
    "media.php",
    '''            if(is_resource($process)){@proc_terminate($process,9);if(!$closed)@proc_close($process);}\n            try{App::q('SELECT RELEASE_LOCK(?)',[$lockName]);}catch(Throwable){}''',
    '''            if(is_resource($process)){@proc_terminate($process,9);if(!$closed)@proc_close($process);}''',
)

# Native Telegram video is limited to MP4/M4V. Other containers remain documents instead of being mislabeled.
replace_once(
    "media.php",
    '''        $mime=self::detectMime($path,(string)($job['mime_type']??''));$mode=(string)$job['upload_mode'];$target=self::jobTargetChannel($job);\n        $method=$mode==='document'?'sendDocument':(($mode==='video'||str_starts_with($mime,'video/'))?'sendVideo':'sendDocument');''',
    '''        $mime=self::detectMime($path,(string)($job['mime_type']??''));$mode=(string)$job['upload_mode'];$target=self::jobTargetChannel($job);\n        $telegramVideo=in_array(strtolower($mime),['video/mp4','video/x-m4v'],true);\n        $method=$mode==='document'?'sendDocument':(($mode==='video'||($mode==='auto'&&$telegramVideo))?'sendVideo':'sendDocument');\n        if($method==='sendVideo'&&!$telegramVideo){self::event((int)$job['id'],'warning','format','فرمت فایل برای Video استاندارد تلگرام مناسب نیست؛ فایل به‌صورت Document ارسال می‌شود.',['mime'=>$mime]);$method='sendDocument';}''',
)
replace_once(
    "media.php",
    '''        $lockName='freebot-mtproto-session';$locked=(int)(App::one('SELECT GET_LOCK(?,0) acquired',[$lockName])['acquired']??0)===1;\n        if(!$locked)throw new MediaQueueException('MTPROTO_BUSY','نشست تلگرام در حال انتقال فایل دیگری است؛ Job خودکار دوباره تلاش می‌شود.',10);\n''',
    "",
)
replace_once(
    "media.php",
    "'--session',$scanner['session_base'],'--config',$runtime['config'],'--result-file',$resultFile];",
    "'--session',$scanner['session_base'],'--parallel-session','--config',$runtime['config'],'--result-file',$resultFile];",
)
replace_once(
    "media.php",
    '''            if($resultFile!=='')@unlink($resultFile);foreach($pipes as $pipe)if(is_resource($pipe))@fclose($pipe);if(is_resource($process)){@proc_terminate($process,9);if(!$closed)@proc_close($process);}try{App::q('SELECT RELEASE_LOCK(?)',[$lockName]);}catch(Throwable){}''',
    '''            if($resultFile!=='')@unlink($resultFile);foreach($pipes as $pipe)if(is_resource($pipe))@fclose($pipe);if(is_resource($process)){@proc_terminate($process,9);if(!$closed)@proc_close($process);}''',
)

# Bot API sendVideo also receives explicit dimensions and duration.
replace_once(
    "media.php",
    '''        $jobId=(int)$job['id'];$field=$method==='sendVideo'?'video':'document';$data=['chat_id'=>$chatId,$field=>new CURLFile($path,$mime,basename($path))];\n        if($method==='sendVideo')$data['supports_streaming']='true';''',
    '''        $jobId=(int)$job['id'];$field=$method==='sendVideo'?'video':'document';$data=['chat_id'=>$chatId,$field=>new CURLFile($path,$mime,basename($path))];\n        if($method==='sendVideo'){\n            $meta=self::telegramVideoMetadata($path);\n            if($meta===[])throw new MediaQueueException('VIDEO_METADATA','ابعاد و مدت ویدیو با ffprobe قابل تشخیص نیست؛ برای جلوگیری از Preview خراب ارسال متوقف شد.');\n            $data['supports_streaming']='true';$data['width']=(string)$meta['width'];$data['height']=(string)$meta['height'];$data['duration']=(string)$meta['duration'];\n            self::event($jobId,'info','video_metadata','متادیتای واقعی ویدیو برای Telegram ثبت شد.',$meta);\n        }''',
)

# Sort active work to the top of the admin Media list.
replace_once(
    "media.php",
    '''    public static function recentJobs(int $limit=100): array{return App::all("SELECT j.*,b.title batch_title,b.channel_id,CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END effective_channel_id,p.title product_title FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id LEFT JOIN products p ON p.id=b.product_id ORDER BY j.id DESC LIMIT ".max(1,min(500,$limit)));}''',
    '''    public static function recentJobs(int $limit=100): array{return App::all("SELECT j.*,b.title batch_title,b.channel_id,CASE WHEN OCTET_LENGTH(j.target_channel_id)>0 THEN j.target_channel_id ELSE b.channel_id END effective_channel_id,p.title product_title FROM media_jobs j JOIN media_batches b ON b.id=j.batch_id LEFT JOIN products p ON p.id=b.product_id ORDER BY CASE j.status WHEN 'downloading' THEN 0 WHEN 'uploading' THEN 1 WHEN 'downloaded' THEN 2 WHEN 'queued' THEN 3 WHEN 'failed' THEN 4 WHEN 'cancelled' THEN 5 ELSE 6 END,CASE WHEN j.status IN ('downloading','uploading') THEN j.updated_at END DESC,j.id DESC LIMIT ".max(1,min(500,$limit)));}''',
)

# Add a strict ffprobe helper for Bot API video dimensions/duration.
replace_once(
    "media.php",
    '''    private static function aria2Path(): ?string{return self::binaryPath('downloader_aria2_path',['/usr/bin/aria2c','/usr/local/bin/aria2c']);}\n    private static function ffmpegPath(): ?string{return self::binaryPath('downloader_ffmpeg_path',['/usr/bin/ffmpeg','/usr/local/bin/ffmpeg']);}\n    private static function mediainfoPath(): ?string{return self::binaryPath('downloader_mediainfo_path',['/usr/bin/mediainfo','/usr/local/bin/mediainfo']);}''',
    '''    private static function aria2Path(): ?string{return self::binaryPath('downloader_aria2_path',['/usr/bin/aria2c','/usr/local/bin/aria2c']);}\n    private static function ffmpegPath(): ?string{return self::binaryPath('downloader_ffmpeg_path',['/usr/bin/ffmpeg','/usr/local/bin/ffmpeg']);}\n    private static function ffprobePath(): ?string{$ffmpeg=self::ffmpegPath();$defaults=['/usr/bin/ffprobe','/usr/local/bin/ffprobe'];if($ffmpeg!==null)array_unshift($defaults,dirname($ffmpeg).'/ffprobe');foreach(array_unique($defaults) as $path)if(is_file($path)&&is_executable($path))return $path;return null;}\n    private static function mediainfoPath(): ?string{return self::binaryPath('downloader_mediainfo_path',['/usr/bin/mediainfo','/usr/local/bin/mediainfo']);}''',
)
replace_once(
    "media.php",
    '''    private static function probeMedia(string $path): array\n    {''',
    '''    private static function telegramVideoMetadata(string $path): array\n    {\n        $binary=self::ffprobePath();if($binary===null||!self::functionEnabled('proc_open')||!self::isSafeExistingFile($path))return [];\n        $pipes=[];$command=[$binary,'-v','error','-select_streams','v:0','-show_entries','stream=width,height,duration:stream_tags=rotate:stream_side_data=rotation:format=duration,format_name','-of','json',$path];\n        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,null,['bypass_shell'=>true]);if(!is_resource($process))return [];\n        fclose($pipes[0]);$json=stream_get_contents($pipes[1],1048576);$error=stream_get_contents($pipes[2],65536);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0||!is_string($json))return [];\n        $data=json_decode($json,true);$stream=(array)($data['streams'][0]??[]);$width=(int)($stream['width']??0);$height=(int)($stream['height']??0);$duration=(float)($stream['duration']??($data['format']['duration']??0));$rotation=(int)($stream['tags']['rotate']??0);\n        foreach((array)($stream['side_data_list']??[]) as $side)if(isset($side['rotation'])){$rotation=(int)$side['rotation'];break;}\n        if(abs($rotation)%180===90)[$width,$height]=[$height,$width];$seconds=(int)ceil($duration);if($width<2||$height<2||$seconds<1)return [];\n        return ['width'=>$width,'height'=>$height,'duration'=>$seconds,'rotation'=>$rotation,'format'=>(string)($data['format']['format_name']??'')];\n    }\n\n    private static function probeMedia(string $path): array\n    {''',
)

# Python MTProto uploader: clone the authorized SQLite auth key to MemorySession and always probe video metadata explicitly.
replace_once("scripts/channel_history_scan.py", "import re\nimport sys", "import re\nimport subprocess\nimport sys")
replace_once(
    "scripts/channel_history_scan.py",
    "\ndef friendly_error(error: Exception) -> str:\n",
    '''\ndef parallel_memory_session(path: str) -> object:\n    from telethon.sessions import MemorySession, SQLiteSession\n\n    source = SQLiteSession(path)\n    try:\n        if source.auth_key is None or not source.dc_id or not source.server_address or not source.port:\n            raise RuntimeError("نشست تلگرام مجاز نیست؛ اتصال حساب را دوباره انجام دهید.")\n        session = MemorySession()\n        session.set_dc(source.dc_id, source.server_address, source.port)\n        session.auth_key = source.auth_key\n        return session\n    finally:\n        source.close()\n\n\ndef display_dimensions(width: int, height: int, rotation: int) -> tuple[int, int]:\n    if abs(rotation) % 180 == 90:\n        return height, width\n    return width, height\n\n\ndef ffprobe_video_metadata(path: Path) -> dict[str, object]:\n    command = [\n        "ffprobe", "-v", "error", "-select_streams", "v:0",\n        "-show_entries", "stream=width,height,duration,codec_name:stream_tags=rotate:stream_side_data=rotation:format=duration,format_name",\n        "-of", "json", str(path),\n    ]\n    try:\n        result = subprocess.run(command, capture_output=True, text=True, timeout=20, check=False)\n    except (OSError, subprocess.SubprocessError) as error:\n        raise RuntimeError(f"ffprobe برای بررسی ویدیو اجرا نشد: {error}") from error\n    if result.returncode != 0:\n        raise RuntimeError("ffprobe نتوانست مشخصات فایل ویدیو را بخواند: " + (result.stderr.strip() or "unknown error"))\n    try:\n        payload = json.loads(result.stdout)\n    except json.JSONDecodeError as error:\n        raise RuntimeError("خروجی ffprobe معتبر نیست.") from error\n    streams = payload.get("streams") or []\n    if not streams or not isinstance(streams[0], dict):\n        raise RuntimeError("فایل نهایی Stream ویدیویی معتبر ندارد.")\n    stream = streams[0]\n    width = int(stream.get("width") or 0)\n    height = int(stream.get("height") or 0)\n    duration_value = stream.get("duration") or (payload.get("format") or {}).get("duration") or 0\n    try:\n        duration = max(1, int(float(duration_value) + 0.999))\n    except (TypeError, ValueError):\n        duration = 0\n    rotation = 0\n    tags = stream.get("tags") or {}\n    try:\n        rotation = int(float(tags.get("rotate") or 0))\n    except (TypeError, ValueError):\n        rotation = 0\n    for item in stream.get("side_data_list") or []:\n        if isinstance(item, dict) and item.get("rotation") is not None:\n            try:\n                rotation = int(float(item["rotation"]))\n            except (TypeError, ValueError):\n                pass\n            break\n    width, height = display_dimensions(width, height, rotation)\n    if width <= 1 or height <= 1 or duration <= 0:\n        raise RuntimeError(f"ابعاد یا مدت ویدیو معتبر نیست: {width}x{height} / {duration}s")\n    format_name = str((payload.get("format") or {}).get("format_name") or "").lower()\n    suffix = path.suffix.lower()\n    mp4_container = suffix in {".mp4", ".m4v"} or "mp4" in format_name\n    return {\n        "width": width,\n        "height": height,\n        "duration": duration,\n        "rotation": rotation,\n        "codec": str(stream.get("codec_name") or ""),\n        "format": format_name,\n        "telegram_video": mp4_container,\n        "supports_streaming": mp4_container,\n    }\n\n\ndef explicit_video_attributes(path: Path, metadata: dict[str, object]) -> list[object]:\n    from telethon.tl.types import DocumentAttributeFilename, DocumentAttributeVideo\n\n    return [\n        DocumentAttributeFilename(path.name),\n        DocumentAttributeVideo(\n            duration=int(metadata["duration"]),\n            w=int(metadata["width"]),\n            h=int(metadata["height"]),\n            supports_streaming=bool(metadata["supports_streaming"]),\n        ),\n    ]\n\n\ndef friendly_error(error: Exception) -> str:\n''',
)
replace_once(
    "scripts/channel_history_scan.py",
    '''    assert validate_transfer_size(1024 * 1024 * 1024) == 1024 * 1024 * 1024\n    print("Channel history scanner self-test passed.")''',
    '''    assert validate_transfer_size(1024 * 1024 * 1024) == 1024 * 1024 * 1024\n    assert display_dimensions(1920, 1080, 90) == (1080, 1920)\n    assert display_dimensions(1920, 1080, 0) == (1920, 1080)\n    print("Channel history scanner self-test passed.")''',
)
replace_once(
    "scripts/channel_history_scan.py",
    '''    client = TelegramClient(\n        session,\n        int(api_id),''',
    '''    session_object = parallel_memory_session(session) if args.parallel_session else session\n    client = TelegramClient(\n        session_object,\n        int(api_id),''',
)
replace_once(
    "scripts/channel_history_scan.py",
    '''            message = await client.send_file(\n                entity,\n                str(upload),\n                caption=None,\n                force_document=bool(args.force_document),\n                supports_streaming=not bool(args.force_document),\n                part_size_kb=512,\n                progress_callback=upload_progress,\n            )''',
    '''            effective_force_document = bool(args.force_document)\n            upload_metadata: dict[str, object] = {}\n            attributes = None\n            mime_type = None\n            supports_streaming = False\n            if not effective_force_document:\n                upload_metadata = ffprobe_video_metadata(upload)\n                if not bool(upload_metadata["telegram_video"]):\n                    effective_force_document = True\n                else:\n                    attributes = explicit_video_attributes(upload, upload_metadata)\n                    mime_type = "video/mp4"\n                    supports_streaming = bool(upload_metadata["supports_streaming"])\n            message = await client.send_file(\n                entity,\n                str(upload),\n                caption=None,\n                force_document=effective_force_document,\n                supports_streaming=supports_streaming,\n                mime_type=mime_type,\n                attributes=attributes,\n                part_size_kb=512,\n                progress_callback=upload_progress,\n            )''',
)
replace_once(
    "scripts/channel_history_scan.py",
    '''                "chat_id": str(utils_get_peer_id(entity)),\n                "size": size,\n            }''',
    '''                "chat_id": str(utils_get_peer_id(entity)),\n                "size": size,\n                "media_type": "document" if effective_force_document else "video",\n                "video_metadata": upload_metadata,\n            }''',
)
replace_once(
    "scripts/channel_history_scan.py",
    '''    parser.add_argument("--force-document", action="store_true")\n    parser.add_argument("--message-id", type=int, default=0)''',
    '''    parser.add_argument("--force-document", action="store_true")\n    parser.add_argument("--parallel-session", action="store_true")\n    parser.add_argument("--message-id", type=int, default=0)''',
)

# Four independent workers per role by default; update existing installs to the same topology.
replace_once("install.sh", 'DOWNLOAD_WORKERS="${FREEBOT_DOWNLOAD_WORKERS:-2}"', 'DOWNLOAD_WORKERS="${FREEBOT_DOWNLOAD_WORKERS:-4}"')
replace_once("install.sh", 'UPLOAD_WORKERS="${FREEBOT_UPLOAD_WORKERS:-2}"', 'UPLOAD_WORKERS="${FREEBOT_UPLOAD_WORKERS:-4}"')
replace_once(
    "install.sh",
    '[--download-workers 2] [--upload-workers 2]',
    '[--download-workers 4] [--upload-workers 4]',
)
replace_once(
    "update.sh",
    '''INSTALL_DIR="${FREEBOT_INSTALL_DIR:-/var/www/freebot}"\n[[ ${EUID} -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }''',
    '''INSTALL_DIR="${FREEBOT_INSTALL_DIR:-/var/www/freebot}"\nDOWNLOAD_WORKERS="${FREEBOT_DOWNLOAD_WORKERS:-4}"\nUPLOAD_WORKERS="${FREEBOT_UPLOAD_WORKERS:-4}"\n[[ ${EUID} -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }''',
)
replace_once(
    "update.sh",
    '''[[ "$INSTALL_DIR" == /var/www/* && -d "$INSTALL_DIR/.git" ]] || { echo "Valid FreeBot checkout not found." >&2; exit 1; }''',
    '''[[ "$INSTALL_DIR" == /var/www/* && -d "$INSTALL_DIR/.git" ]] || { echo "Valid FreeBot checkout not found." >&2; exit 1; }\n[[ "$DOWNLOAD_WORKERS" =~ ^[1-9][0-9]*$ && "$UPLOAD_WORKERS" =~ ^[1-9][0-9]*$ ]] || { echo "Worker counts must be positive integers." >&2; exit 1; }''',
)
replace_once(
    "update.sh",
    '''  runuser -u www-data -- /usr/bin/php -r 'require $argv[1]; App::db();' "$INSTALL_DIR/app.php"''',
    '''  runuser -u www-data -- /usr/bin/php -r 'require $argv[1]; App::db(); App::q("UPDATE media_batches SET pipeline_depth=4 WHERE source_type=\\"telegram_channel\\" AND pipeline_depth<4 AND status IN (\\"queued\\",\\"running\\",\\"paused\\")");' "$INSTALL_DIR/app.php"''',
)
replace_once(
    "update.sh",
    '''systemctl restart php8.3-fpm nginx\nsystemctl restart 'freebot-download@*.service' 'freebot-upload@*.service' || true''',
    '''systemctl restart php8.3-fpm nginx\nfor ((i=1;i<=DOWNLOAD_WORKERS;i++)); do systemctl enable --now "freebot-download@${i}.service"; done\nfor ((i=1;i<=UPLOAD_WORKERS;i++)); do systemctl enable --now "freebot-upload@${i}.service"; done\nsystemctl restart 'freebot-download@*.service' 'freebot-upload@*.service' || true''',
)

(ROOT / "VERSION").write_text("2.7.0-parallel-media\n", encoding="utf-8")

# CI assertions for the new guarantees.
replace_once(
    "tests/installer_test.sh",
    '''grep -q 'ffprobe' "$ROOT/healthcheck.sh"''',
    '''grep -q 'ffprobe' "$ROOT/healthcheck.sh"\ngrep -q 'FREEBOT_DOWNLOAD_WORKERS:-4' "$ROOT/install.sh"\ngrep -q 'FREEBOT_UPLOAD_WORKERS:-4' "$ROOT/install.sh"\ngrep -q -- '--parallel-session' "$ROOT/scripts/channel_history_scan.py"\ngrep -q 'ffprobe_video_metadata' "$ROOT/scripts/channel_history_scan.py"''',
)
replace_once(
    "tests/installer_test.sh",
    '''grep -q "pipelineDepth=1" "$ROOT/media.php"''',
    '''grep -q "pipelineDepth=max(4" "$ROOT/media.php"\ngrep -q "WHEN 'downloading' THEN 0" "$ROOT/media.php"\n! grep -q "active_tg.engine='telegram-mtproto'" "$ROOT/media.php"''',
)

print("2.7.0 guarded source migration applied successfully")
