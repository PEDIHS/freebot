#!/usr/bin/env python3
"""Count historical channel media through an authorized Telegram user session."""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import re
import sys
import time
from pathlib import Path
from types import SimpleNamespace


_result_stream = None


def configure_result_file(path: str) -> None:
    global _result_stream
    if not path:
        return
    target = Path(path)
    if target.parent != Path("/var/lib/freebot-mtproto") or not re.fullmatch(r"result-[a-f0-9]{32}\.ndjson", target.name):
        raise RuntimeError("Result file path is invalid.")
    descriptor = os.open(target, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    _result_stream = os.fdopen(descriptor, "w", encoding="utf-8", buffering=1)


def emit_json(payload: dict[str, object]) -> None:
    # Keep the transport strictly ASCII. Some PHP-FPM/shell locales can corrupt
    # multibyte channel titles before json_decode sees them; \u escapes round-trip
    # Persian text and emoji without depending on the process locale.
    line = json.dumps(payload, ensure_ascii=True, separators=(",", ":"))
    if _result_stream is not None:
        _result_stream.write(line + "\n")
        _result_stream.flush()
    print(line, flush=True)


def close_result_file() -> None:
    global _result_stream
    if _result_stream is not None:
        _result_stream.close()
        _result_stream = None


def load_config(path: str) -> dict[str, str]:
    values: dict[str, str] = {}
    config = Path(path)
    if not config.is_file():
        return values
    for raw in config.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key.strip()] = value.strip().strip('"').strip("'")
    return values


def target_key(value: str) -> str:
    value = value.strip()
    for prefix in ("https://t.me/", "http://t.me/", "t.me/"):
        if value.lower().startswith(prefix):
            value = value[len(prefix):]
            break
    return value.split("/", 1)[0].lstrip("@").strip()


def photo_size(photo: object | None) -> int:
    maximum = 0
    for size in getattr(photo, "sizes", []) or []:
        maximum = max(maximum, int(getattr(size, "size", 0) or 0))
        progressive = getattr(size, "sizes", None)
        if progressive:
            maximum = max(maximum, *(int(item or 0) for item in progressive))
    return maximum


def classify_message(message: object) -> tuple[str | None, int]:
    if getattr(message, "video", None) is not None:
        media = message.video
        return "video", int(getattr(media, "size", 0) or 0)
    if getattr(message, "photo", None) is not None:
        return "photo", photo_size(message.photo)
    document = getattr(message, "document", None)
    if document is not None:
        if getattr(message, "gif", None) is not None:
            kind = "animation"
        elif getattr(message, "audio", None) is not None or getattr(message, "voice", None) is not None:
            kind = "audio"
        else:
            kind = "document"
        return kind, int(getattr(document, "size", 0) or 0)
    return None, 0


def video_metadata(message: object) -> dict[str, object]:
    video = getattr(message, "video", None)
    if video is None:
        raise RuntimeError("پیام انتخاب‌شده ویدیو نیست.")
    file_info = getattr(message, "file", None)
    message_id = int(getattr(message, "id", 0) or 0)
    file_name = str(getattr(file_info, "name", "") or "").strip()
    extension = str(getattr(file_info, "ext", "") or "").strip()
    if not file_name:
        file_name = f"telegram-{message_id}{extension or '.mp4'}"
    text = str(getattr(message, "message", "") or "").strip()
    title = text.splitlines()[0].strip() if text else Path(file_name).stem
    date = getattr(message, "date", None)
    return {
        "message_id": message_id,
        "file_name": file_name,
        "title": title[:500] or f"telegram-{message_id}",
        "file_size": int(getattr(video, "size", 0) or 0),
        "mime_type": str(getattr(video, "mime_type", "") or "video/mp4"),
        "date": date.strftime("%Y-%m-%d %H:%M:%S") if date is not None else "",
    }


def self_test() -> None:
    assert target_key("https://t.me/MyChannel/12") == "MyChannel"
    video = SimpleNamespace(video=SimpleNamespace(size=120), photo=None, document=None)
    assert classify_message(video) == ("video", 120)
    photo = SimpleNamespace(video=None, photo=SimpleNamespace(sizes=[SimpleNamespace(size=50)]), document=None)
    assert classify_message(photo) == ("photo", 50)
    document = SimpleNamespace(video=None, photo=None, document=SimpleNamespace(size=75), gif=None, audio=None, voice=None)
    assert classify_message(document) == ("document", 75)
    metadata = video_metadata(SimpleNamespace(
        id=42,
        video=SimpleNamespace(size=2048, mime_type="video/mp4"),
        file=SimpleNamespace(name="movie.mp4", ext=".mp4"),
        message="Movie title\nDescription",
        date=None,
    ))
    assert metadata["message_id"] == 42 and metadata["file_name"] == "movie.mp4" and metadata["title"] == "Movie title"
    assert validate_credentials({"api_id": "12345", "api_hash": "a" * 32, "phone": "+49123456789"}) == (12345, "a" * 32, "+49123456789")
    assert validate_transfer_size(1024 * 1024 * 1024) == 1024 * 1024 * 1024
    print("Channel history scanner self-test passed.")


def validate_credentials(data: dict[str, object]) -> tuple[int, str, str]:
    api_id = str(data.get("api_id", "")).strip()
    api_hash = str(data.get("api_hash", "")).strip()
    phone = str(data.get("phone", "")).strip()
    if not api_id.isdigit() or int(api_id) <= 0:
        raise RuntimeError("API ID is invalid.")
    if len(api_hash) < 20 or len(api_hash) > 64 or any(char not in "0123456789abcdefABCDEF" for char in api_hash):
        raise RuntimeError("API Hash is invalid.")
    if not phone.startswith("+") or not phone[1:].isdigit() or not 7 <= len(phone[1:]) <= 18:
        raise RuntimeError("Phone number is invalid; include the country code.")
    return int(api_id), api_hash, phone


def validate_transfer_size(size: int) -> int:
    limit = 1024 * 1024 * 1024
    if size <= 0:
        raise RuntimeError("فایل آپلود خالی است.")
    if size > limit:
        raise RuntimeError("حجم فایل از سقف ۱ گیگابایت بیشتر است.")
    return size


def friendly_error(error: Exception) -> str:
    name = type(error).__name__
    messages = {
        "PhoneNumberInvalidError": "شماره تلفن نامعتبر است؛ شماره را همراه کد کشور وارد کنید.",
        "PhoneCodeInvalidError": "کد ورود تلگرام نادرست است.",
        "PhoneCodeExpiredError": "کد ورود منقضی شده است؛ دوباره کد درخواست کنید.",
        "PhoneCodeHashEmptyError": "درخواست کد معتبر نیست؛ راه‌اندازی را از ابتدا انجام دهید.",
        "PasswordHashInvalidError": "رمز دومرحله‌ای تلگرام نادرست است.",
        "ApiIdInvalidError": "API ID یا API Hash معتبر نیست.",
        "AuthKeyError": "نشست تلگرام معتبر نیست؛ اتصال را دوباره انجام دهید.",
        "ChatWriteForbiddenError": "حساب Telethon اجازه ارسال در کانال مقصد را ندارد؛ این حساب را ادمین مقصد کنید.",
        "ChannelPrivateError": "کانال مقصد برای حساب Telethon قابل دسترسی نیست؛ حساب متصل را عضو و ادمین مقصد کنید.",
        "FilePartMissingError": "یکی از بخش‌های فایل در تلگرام ثبت نشد؛ انتقال دوباره تلاش می‌شود.",
    }
    if name == "FloodWaitError":
        seconds = int(getattr(error, "seconds", 0) or 0)
        return f"تلگرام محدودیت موقت اعمال کرده است؛ {seconds} ثانیه دیگر تلاش کنید."
    return messages.get(name, str(error) or name)


async def resolve_entity(client: object, raw_target: str) -> object:
    from telethon import utils

    target = target_key(raw_target)
    if not target:
        raise RuntimeError("Channel identifier is empty.")
    try:
        return await client.get_entity(int(target) if target.lstrip("-").isdigit() else target)
    except Exception as first_error:
        async for dialog in client.iter_dialogs():
            peer_id = str(utils.get_peer_id(dialog.entity))
            username = str(getattr(dialog.entity, "username", "") or "")
            if peer_id == target or username.lower() == target.lower():
                return dialog.entity
        raise RuntimeError(f"Channel is not accessible to the scanner account: {first_error}") from first_error


async def run_web_action(args: argparse.Namespace, input_data: dict[str, object]) -> dict[str, object]:
    try:
        from telethon import TelegramClient
        from telethon.errors import SessionPasswordNeededError
    except ImportError as error:
        raise RuntimeError("Telethon is not installed. Run update.sh.") from error

    config = load_config(args.config)
    credentials = {
        "api_id": input_data.get("api_id") or config.get("TELEGRAM_API_ID", ""),
        "api_hash": input_data.get("api_hash") or config.get("TELEGRAM_API_HASH", ""),
        "phone": input_data.get("phone") or config.get("TELEGRAM_PHONE", ""),
    }
    api_id, api_hash, phone = validate_credentials(credentials)
    client = TelegramClient(args.session, api_id, api_hash)
    await client.connect()
    try:
        if args.web_action == "send-code":
            sent = await client.send_code_request(phone)
            return {
                "ok": True,
                "step": "code",
                "phone_code_hash": str(sent.phone_code_hash),
                "timeout": int(getattr(sent, "timeout", 0) or 0),
            }
        if args.web_action == "verify-code":
            code = str(input_data.get("code", "")).replace(" ", "").strip()
            phone_code_hash = str(input_data.get("phone_code_hash", "")).strip()
            if not code.isdigit() or not 4 <= len(code) <= 8:
                raise RuntimeError("کد ورود تلگرام نامعتبر است.")
            if not phone_code_hash:
                raise RuntimeError("درخواست کد منقضی شده است؛ دوباره کد بگیرید.")
            try:
                user = await client.sign_in(phone=phone, code=code, phone_code_hash=phone_code_hash)
            except SessionPasswordNeededError:
                return {"ok": True, "step": "password", "needs_password": True}
            return user_result(user)
        if args.web_action == "verify-password":
            password = str(input_data.get("password", ""))
            if not password:
                raise RuntimeError("رمز دومرحله‌ای را وارد کنید.")
            user = await client.sign_in(password=password)
            return user_result(user)
        if args.web_action == "status":
            if not await client.is_user_authorized():
                raise RuntimeError("نشست تلگرام مجاز نیست؛ اتصال را دوباره انجام دهید.")
            return user_result(await client.get_me())
        raise RuntimeError("Web setup action is invalid.")
    except Exception as error:
        raise RuntimeError(friendly_error(error)) from error
    finally:
        await client.disconnect()


def user_result(user: object) -> dict[str, object]:
    first_name = str(getattr(user, "first_name", "") or "")
    last_name = str(getattr(user, "last_name", "") or "")
    return {
        "ok": True,
        "step": "complete",
        "authorized_user_id": int(getattr(user, "id", 0) or 0),
        "name": (first_name + " " + last_name).strip(),
        "username": str(getattr(user, "username", "") or ""),
    }


async def run(args: argparse.Namespace, input_data: dict[str, object]) -> dict[str, object]:
    try:
        from telethon import TelegramClient
    except ImportError as error:
        raise RuntimeError("Telethon is not installed. Run setup-channel-scanner.sh.") from error

    config = load_config(args.config)
    api_id = str(input_data.get("api_id") or os.getenv("TELEGRAM_API_ID", "") or config.get("TELEGRAM_API_ID", ""))
    api_hash = str(input_data.get("api_hash") or os.getenv("TELEGRAM_API_HASH", "") or config.get("TELEGRAM_API_HASH", ""))
    phone = str(input_data.get("phone") or os.getenv("TELEGRAM_PHONE", "") or config.get("TELEGRAM_PHONE", ""))
    session = args.session or config.get("TELEGRAM_SESSION", "/var/lib/freebot-mtproto/freebot")
    if not api_id.isdigit() or not api_hash:
        raise RuntimeError("TELEGRAM_API_ID/API_HASH are not configured.")

    client = TelegramClient(
        session,
        int(api_id),
        api_hash,
        connection_retries=10,
        request_retries=10,
        retry_delay=1,
        auto_reconnect=True,
    )
    if args.login_only:
        if not phone:
            raise RuntimeError("TELEGRAM_PHONE is not configured.")
        await client.start(phone=phone)
        me = await client.get_me()
        await client.disconnect()
        return {"ok": True, "authorized_user_id": int(me.id), "name": getattr(me, "first_name", "") or ""}

    await client.connect()
    try:
        if not await client.is_user_authorized():
            raise RuntimeError("Scanner session is not authorized. Run setup-channel-scanner.sh.")
        target = args.destination if args.upload_file else args.channel
        entity = await resolve_entity(client, target)
        if args.upload_file:
            upload = Path(args.upload_file)
            if not upload.is_absolute() or not upload.is_file():
                raise RuntimeError("فایل آماده آپلود پیدا نشد.")
            size = validate_transfer_size(upload.stat().st_size)
            last_emit = 0.0

            def upload_progress(current: int, total: int) -> None:
                nonlocal last_emit
                now = time.monotonic()
                if now - last_emit < 1.0 and int(current) < int(total):
                    return
                last_emit = now
                emit_json({
                    "type": "upload_progress",
                    "uploaded": int(current),
                    "total": int(total),
                })

            message = await client.send_file(
                entity,
                str(upload),
                caption=None,
                force_document=bool(args.force_document),
                supports_streaming=not bool(args.force_document),
                part_size_kb=512,
                progress_callback=upload_progress,
            )
            message_id = int(getattr(message, "id", 0) or 0)
            if message_id <= 0:
                raise RuntimeError("تلگرام شناسه پیام آپلودشده را برنگرداند.")
            return {
                "ok": True,
                "type": "uploaded",
                "message_id": message_id,
                "chat_id": str(utils_get_peer_id(entity)),
                "size": size,
            }
        if args.list_videos:
            total_messages = 0
            video_count = 0
            last_message_id = max(0, int(args.min_message_id))
            async for message in client.iter_messages(entity, reverse=True, min_id=last_message_id):
                total_messages += 1
                last_message_id = max(last_message_id, int(getattr(message, "id", 0) or 0))
                if getattr(message, "video", None) is None:
                    continue
                item = video_metadata(message)
                item.update({
                    "type": "video",
                    "source_chat_id": str(utils_get_peer_id(entity)),
                })
                emit_json(item)
                video_count += 1
                if video_count % 100 == 0:
                    print(f"Found {video_count} videos in {total_messages} messages...", file=sys.stderr, flush=True)
            return {
                "ok": True,
                "type": "summary",
                "channel_id": str(utils_get_peer_id(entity)),
                "channel_title": str(getattr(entity, "title", "") or ""),
                "last_message_id": last_message_id,
                "message_count": total_messages,
                "video_count": video_count,
            }
        if args.download_message:
            if args.message_id <= 0:
                raise RuntimeError("شناسه پیام معتبر نیست.")
            output = Path(args.output)
            if not output.is_absolute():
                raise RuntimeError("مسیر خروجی دانلود باید مطلق باشد.")
            output.parent.mkdir(parents=True, exist_ok=True)
            message = await client.get_messages(entity, ids=args.message_id)
            if message is None or getattr(message, "video", None) is None:
                raise RuntimeError("ویدیوی پیام در کانال مبدأ پیدا نشد یا دیگر قابل دسترسی نیست.")
            metadata = video_metadata(message)
            last_emit = 0.0

            def progress(current: int, total: int) -> None:
                nonlocal last_emit
                now = time.monotonic()
                if now - last_emit < 1.0 and int(current) < int(total):
                    return
                last_emit = now
                print(json.dumps({
                    "type": "progress",
                    "downloaded": int(current),
                    "total": int(total),
                }, separators=(",", ":")), flush=True)

            downloaded = await client.download_media(message, file=str(output), progress_callback=progress)
            if not downloaded or not Path(downloaded).is_file():
                raise RuntimeError("Telethon فایل ویدیو را ایجاد نکرد.")
            return {
                "ok": True,
                "type": "downloaded",
                "path": str(Path(downloaded)),
                "size": Path(downloaded).stat().st_size,
                **metadata,
            }
        counts = {"video": 0, "photo": 0, "document": 0, "animation": 0, "audio": 0}
        total_messages = 0
        total_bytes = 0
        last_message_id = 0
        async for message in client.iter_messages(entity):
            total_messages += 1
            last_message_id = max(last_message_id, int(getattr(message, "id", 0) or 0))
            kind, size = classify_message(message)
            if kind is not None:
                counts[kind] += 1
                total_bytes += max(0, size)
            if total_messages % 1000 == 0:
                print(f"Scanned {total_messages} messages...", file=sys.stderr, flush=True)
        return {
            "ok": True,
            "channel_id": str(utils_get_peer_id(entity)),
            "channel_title": str(getattr(entity, "title", "") or ""),
            "last_message_id": last_message_id,
            "message_count": total_messages,
            "video_count": counts["video"],
            "photo_count": counts["photo"],
            "file_count": counts["document"] + counts["animation"] + counts["audio"],
            "animation_count": counts["animation"],
            "audio_count": counts["audio"],
            "media_count": sum(counts.values()),
            "total_bytes": total_bytes,
        }
    finally:
        await client.disconnect()


def utils_get_peer_id(entity: object) -> int:
    from telethon import utils
    return int(utils.get_peer_id(entity))


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--channel", default="")
    parser.add_argument("--config", default="/etc/freebot/channel-scanner.env")
    parser.add_argument("--session", default="")
    parser.add_argument("--login-only", action="store_true")
    parser.add_argument("--list-videos", action="store_true")
    parser.add_argument("--download-message", action="store_true")
    parser.add_argument("--upload-file", default="")
    parser.add_argument("--destination", default="")
    parser.add_argument("--force-document", action="store_true")
    parser.add_argument("--message-id", type=int, default=0)
    parser.add_argument("--min-message-id", type=int, default=0)
    parser.add_argument("--output", default="")
    parser.add_argument("--result-file", default="")
    parser.add_argument("--json-input", action="store_true")
    parser.add_argument("--web-action", choices=("send-code", "verify-code", "verify-password", "status"), default="")
    parser.add_argument("--transport-probe", default="")
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return 0
    if not args.login_only and not args.web_action and not args.channel and not args.upload_file and not args.transport_probe:
        parser.error("--channel is required")
    if args.download_message and not args.output:
        parser.error("--output is required with --download-message")
    if args.list_videos and args.download_message:
        parser.error("choose only one transfer operation")
    if args.upload_file and not args.destination:
        parser.error("--destination is required with --upload-file")
    if args.upload_file and (args.list_videos or args.download_message):
        parser.error("choose only one transfer operation")
    try:
        configure_result_file(args.result_file)
        if args.transport_probe:
            if not re.fullmatch(r"[a-f0-9]{32}", args.transport_probe):
                raise RuntimeError("Transport probe token is invalid.")
            emit_json({"ok": True, "probe": args.transport_probe, "unicode": "تست 🎬", "pid": os.getpid()})
            return 0
        input_data: dict[str, object] = {}
        if args.json_input:
            decoded = json.load(sys.stdin)
            if not isinstance(decoded, dict):
                raise RuntimeError("JSON input must be an object.")
            input_data = decoded
        operation = run_web_action(args, input_data) if args.web_action else run(args, input_data)
        emit_json(asyncio.run(operation))
        return 0
    except Exception as error:
        message = str(error) or type(error).__name__
        print(message, file=sys.stderr, flush=True)
        emit_json({"ok": False, "error": message})
        return 1
    finally:
        close_result_file()


if __name__ == "__main__":
    raise SystemExit(main())
