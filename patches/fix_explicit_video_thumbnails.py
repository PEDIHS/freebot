from pathlib import Path


def replace_once(path: str, old: str, new: str) -> None:
    p = Path(path)
    text = p.read_text(encoding="utf-8")
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{path}: expected exactly one match, found {count}: {old[:120]!r}")
    p.write_text(text.replace(old, new, 1), encoding="utf-8")


replace_once(
    "scripts/channel_history_scan.py",
    '''    assert display_dimensions(1920, 1080, 90) == (1080, 1920)\n    assert display_dimensions(1920, 1080, 0) == (1920, 1080)\n    print("Channel history scanner self-test passed.")''',
    '''    assert display_dimensions(1920, 1080, 90) == (1080, 1920)\n    assert display_dimensions(1920, 1080, 0) == (1920, 1080)\n    assert thumbnail_path_for(Path("/tmp/movie.mp4")).name == "movie.mp4.thumb.jpg"\n    print("Channel history scanner self-test passed.")''',
)

anchor = '''def explicit_video_attributes(path: Path, metadata: dict[str, object]) -> list[object]:
    from telethon.tl.types import DocumentAttributeFilename, DocumentAttributeVideo

    return [
        DocumentAttributeFilename(path.name),
        DocumentAttributeVideo(
            duration=int(metadata["duration"]),
            w=int(metadata["width"]),
            h=int(metadata["height"]),
            supports_streaming=bool(metadata["supports_streaming"]),
        ),
    ]
'''
helpers = anchor + '''\n\ndef thumbnail_path_for(video_path: Path) -> Path:
    return video_path.with_name(video_path.name + ".thumb.jpg")


def thumbnail_dimensions(path: Path) -> tuple[int, int]:
    try:
        result = subprocess.run(
            ["ffprobe", "-v", "error", "-select_streams", "v:0", "-show_entries", "stream=width,height", "-of", "json", str(path)],
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        if result.returncode != 0:
            return 0, 0
        payload = json.loads(result.stdout)
        streams = payload.get("streams") or []
        if not streams:
            return 0, 0
        return int(streams[0].get("width") or 0), int(streams[0].get("height") or 0)
    except (OSError, subprocess.SubprocessError, ValueError, TypeError, json.JSONDecodeError):
        return 0, 0


def valid_telegram_thumbnail(path: Path) -> bool:
    if not path.is_file() or path.suffix.lower() not in {".jpg", ".jpeg"}:
        return False
    size = path.stat().st_size
    if size <= 0 or size > 19_500:
        return False
    width, height = thumbnail_dimensions(path)
    return 1 <= width <= 320 and 1 <= height <= 320


def render_telegram_thumbnail(source: Path, target: Path, seek_seconds: float = 0.0) -> Path | None:
    target.parent.mkdir(parents=True, exist_ok=True)
    attempts = ((320, 7), (280, 9), (240, 11), (200, 13), (160, 15), (128, 17))
    for side, quality in attempts:
        temporary = target.with_name(target.name + f".{side}.tmp.jpg")
        temporary.unlink(missing_ok=True)
        command = ["ffmpeg", "-hide_banner", "-loglevel", "error", "-y"]
        if seek_seconds > 0:
            command += ["-ss", f"{seek_seconds:.3f}"]
        command += [
            "-i", str(source),
            "-frames:v", "1",
            "-vf", f"scale={side}:{side}:force_original_aspect_ratio=decrease",
            "-q:v", str(quality),
            "-map_metadata", "-1",
            str(temporary),
        ]
        try:
            result = subprocess.run(command, capture_output=True, text=True, timeout=30, check=False)
        except (OSError, subprocess.SubprocessError):
            temporary.unlink(missing_ok=True)
            continue
        if result.returncode == 0 and valid_telegram_thumbnail(temporary):
            os.replace(temporary, target)
            return target
        temporary.unlink(missing_ok=True)
    return None


async def preserve_or_generate_thumbnail(client: object, message: object, video_path: Path, metadata: dict[str, object]) -> Path | None:
    target = thumbnail_path_for(video_path)
    if valid_telegram_thumbnail(target):
        return target
    source_thumb = video_path.with_name(video_path.name + ".source-thumb.jpg")
    downloaded_thumb: Path | None = None
    try:
        video = getattr(message, "video", None)
        if getattr(video, "thumbs", None):
            downloaded = await client.download_media(message, file=str(source_thumb), thumb=-1)
            if downloaded:
                downloaded_thumb = Path(downloaded)
                rendered = render_telegram_thumbnail(downloaded_thumb, target)
                if rendered is not None:
                    return rendered
    except Exception as error:
        print(f"Source thumbnail could not be preserved: {error}", file=sys.stderr, flush=True)
    finally:
        for candidate in {source_thumb, downloaded_thumb}:
            if isinstance(candidate, Path) and candidate != target:
                candidate.unlink(missing_ok=True)
    duration = max(1, int(metadata.get("duration") or 1))
    seek = min(8.0, max(0.5, duration * 0.08))
    return render_telegram_thumbnail(video_path, target, seek)


def ensure_upload_thumbnail(video_path: Path, metadata: dict[str, object]) -> Path:
    target = thumbnail_path_for(video_path)
    if valid_telegram_thumbnail(target):
        return target
    if target.is_file():
        preserved = render_telegram_thumbnail(target, target)
        if preserved is not None:
            return preserved
    duration = max(1, int(metadata.get("duration") or 1))
    seek = min(8.0, max(0.5, duration * 0.08))
    generated = render_telegram_thumbnail(video_path, target, seek)
    if generated is None:
        raise RuntimeError("ساخت Thumbnail استاندارد JPEG برای ویدیو ممکن نشد؛ ارسال بدون پیش‌نمایش متوقف شد.")
    return generated
'''
replace_once("scripts/channel_history_scan.py", anchor, helpers)

replace_once(
    "scripts/channel_history_scan.py",
    '''            effective_force_document = bool(args.force_document)
            upload_metadata: dict[str, object] = {}
            attributes = None
            mime_type = None
            supports_streaming = False
            if not effective_force_document:
                upload_metadata = ffprobe_video_metadata(upload)
                if not bool(upload_metadata["telegram_video"]):
                    effective_force_document = True
                else:
                    attributes = explicit_video_attributes(upload, upload_metadata)
                    mime_type = "video/mp4"
                    supports_streaming = bool(upload_metadata["supports_streaming"])
            message = await client.send_file(
                entity,
                str(upload),
                caption=None,
                force_document=effective_force_document,
                supports_streaming=supports_streaming,
                mime_type=mime_type,
                attributes=attributes,
                part_size_kb=512,
                progress_callback=upload_progress,
            )''',
    '''            effective_force_document = bool(args.force_document)
            upload_metadata: dict[str, object] = {}
            attributes = None
            mime_type = None
            supports_streaming = False
            thumbnail: Path | None = None
            if not effective_force_document:
                upload_metadata = ffprobe_video_metadata(upload)
                if not bool(upload_metadata["telegram_video"]):
                    effective_force_document = True
                else:
                    attributes = explicit_video_attributes(upload, upload_metadata)
                    mime_type = "video/mp4"
                    supports_streaming = bool(upload_metadata["supports_streaming"])
                    thumbnail = ensure_upload_thumbnail(upload, upload_metadata)
            message = await client.send_file(
                entity,
                str(upload),
                caption=None,
                force_document=effective_force_document,
                supports_streaming=supports_streaming,
                nosound_video=True if not effective_force_document else None,
                mime_type=mime_type,
                attributes=attributes,
                thumb=str(thumbnail) if thumbnail is not None else None,
                part_size_kb=512,
                progress_callback=upload_progress,
            )''',
)

replace_once(
    "scripts/channel_history_scan.py",
    '''            downloaded = await client.download_media(message, file=str(output), progress_callback=progress)
            if not downloaded or not Path(downloaded).is_file():
                raise RuntimeError("Telethon فایل ویدیو را ایجاد نکرد.")
            return {
                "ok": True,
                "type": "downloaded",
                "path": str(Path(downloaded)),
                "size": Path(downloaded).stat().st_size,
                **metadata,
            }''',
    '''            downloaded = await client.download_media(message, file=str(output), progress_callback=progress)
            if not downloaded or not Path(downloaded).is_file():
                raise RuntimeError("Telethon فایل ویدیو را ایجاد نکرد.")
            downloaded_path = Path(downloaded)
            final_metadata = ffprobe_video_metadata(downloaded_path)
            thumbnail = await preserve_or_generate_thumbnail(client, message, downloaded_path, final_metadata)
            if thumbnail is None or not valid_telegram_thumbnail(thumbnail):
                raise RuntimeError("Thumbnail ویدیو از پیام اصلی قابل استخراج نبود و ساخت Thumbnail جایگزین نیز ناموفق بود.")
            return {
                "ok": True,
                "type": "downloaded",
                "path": str(downloaded_path),
                "size": downloaded_path.stat().st_size,
                "thumbnail_path": str(thumbnail),
                "thumbnail_size": thumbnail.stat().st_size,
                "video_metadata": final_metadata,
                **metadata,
            }''',
)

php_old = '''        if($method==='sendVideo'){
            $meta=self::telegramVideoMetadata($path);
            if($meta===[])throw new MediaQueueException('VIDEO_METADATA','ابعاد و مدت ویدیو با ffprobe قابل تشخیص نیست؛ برای جلوگیری از Preview خراب ارسال متوقف شد.');
            $data['supports_streaming']='true';$data['width']=(string)$meta['width'];$data['height']=(string)$meta['height'];$data['duration']=(string)$meta['duration'];
            self::event($jobId,'info','video_metadata','متادیتای واقعی ویدیو برای Telegram ثبت شد.',$meta);
        }'''
php_new = '''        if($method==='sendVideo'){
            $meta=self::telegramVideoMetadata($path);
            if($meta===[])throw new MediaQueueException('VIDEO_METADATA','ابعاد و مدت ویدیو با ffprobe قابل تشخیص نیست؛ برای جلوگیری از Preview خراب ارسال متوقف شد.');
            $thumb=self::telegramVideoThumbnail($path,$meta);
            if($thumb==='')throw new MediaQueueException('VIDEO_THUMBNAIL','ساخت Thumbnail و Cover ویدیو ممکن نشد؛ ارسال بدون پیش‌نمایش متوقف شد.');
            $data['supports_streaming']='true';$data['width']=(string)$meta['width'];$data['height']=(string)$meta['height'];$data['duration']=(string)$meta['duration'];
            $data['thumbnail']='attach://video_thumb';$data['video_thumb']=new CURLFile($thumb,'image/jpeg','thumbnail.jpg');
            $data['cover']='attach://video_cover';$data['video_cover']=new CURLFile($thumb,'image/jpeg','cover.jpg');
            self::event($jobId,'info','video_metadata','متادیتا، Thumbnail و Cover واقعی ویدیو برای Telegram ثبت شد.',$meta+['thumbnail_size'=>(int)(filesize($thumb)?:0)]);
        }'''
replace_once("media.php", php_old, php_new)

meta_anchor = '''    private static function telegramVideoMetadata(string $path): array
    {
        $binary=self::ffprobePath();if($binary===null||!self::functionEnabled('proc_open')||!self::isSafeExistingFile($path))return [];
        $pipes=[];$command=[$binary,'-v','error','-select_streams','v:0','-show_entries','stream=width,height,duration:stream_tags=rotate:stream_side_data=rotation:format=duration,format_name','-of','json',$path];
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,null,['bypass_shell'=>true]);if(!is_resource($process))return [];
        fclose($pipes[0]);$json=stream_get_contents($pipes[1],1048576);$error=stream_get_contents($pipes[2],65536);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);if($exit!==0||!is_string($json))return [];
        $data=json_decode($json,true);$stream=(array)($data['streams'][0]??[]);$width=(int)($stream['width']??0);$height=(int)($stream['height']??0);$duration=(float)($stream['duration']??($data['format']['duration']??0));$rotation=(int)($stream['tags']['rotate']??0);
        foreach((array)($stream['side_data_list']??[]) as $side)if(isset($side['rotation'])){$rotation=(int)$side['rotation'];break;}
        if(abs($rotation)%180===90)[$width,$height]=[$height,$width];$seconds=(int)ceil($duration);if($width<2||$height<2||$seconds<1)return [];
        return ['width'=>$width,'height'=>$height,'duration'=>$seconds,'rotation'=>$rotation,'format'=>(string)($data['format']['format_name']??'')];
    }
'''
meta_helpers = meta_anchor + '''\n    private static function telegramVideoThumbnail(string $path,array $meta=[]): string
    {
        $target=$path.'.thumb.jpg';
        if(self::isSafeExistingFile($target)){clearstatcache(true,$target);$size=(int)(filesize($target)?:0);if($size>0&&$size<=19500)return $target;}
        $binary=self::ffmpegPath();if($binary===null||!self::functionEnabled('proc_open')||!self::isSafeExistingFile($path))return '';
        $duration=max(1,(int)($meta['duration']??1));$seek=min(8.0,max(.5,$duration*.08));
        foreach([[320,7],[280,9],[240,11],[200,13],[160,15],[128,17]] as [$side,$quality]){
            $tmp=$target.'.'.$side.'.tmp.jpg';@unlink($tmp);$pipes=[];
            $command=[$binary,'-hide_banner','-loglevel','error','-y','-ss',number_format($seek,3,'.',''),'-i',$path,'-frames:v','1','-vf','scale='.$side.':'.$side.':force_original_aspect_ratio=decrease','-q:v',(string)$quality,'-map_metadata','-1',$tmp];
            $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,__DIR__,null,['bypass_shell'=>true]);if(!is_resource($process))continue;
            fclose($pipes[0]);stream_get_contents($pipes[1],65536);stream_get_contents($pipes[2],65536);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($process);clearstatcache(true,$tmp);
            $size=is_file($tmp)?(int)(filesize($tmp)?:0):0;if($exit===0&&$size>0&&$size<=19500){@rename($tmp,$target);@chmod($target,0640);return self::isSafeExistingFile($target)?$target:'';}@unlink($tmp);
        }
        return '';
    }
'''
replace_once("media.php", meta_anchor, meta_helpers)

Path("VERSION").write_text("2.7.1-explicit-thumbnails\n", encoding="utf-8")
print("Explicit Telegram thumbnail patch applied.")
