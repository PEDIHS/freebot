#!/usr/bin/env python3
"""Count historical channel media through an authorized Telegram user session."""

from __future__ import annotations

import argparse
import asyncio
import json
import os
import sys
from pathlib import Path
from types import SimpleNamespace


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


def self_test() -> None:
    assert target_key("https://t.me/MyChannel/12") == "MyChannel"
    video = SimpleNamespace(video=SimpleNamespace(size=120), photo=None, document=None)
    assert classify_message(video) == ("video", 120)
    photo = SimpleNamespace(video=None, photo=SimpleNamespace(sizes=[SimpleNamespace(size=50)]), document=None)
    assert classify_message(photo) == ("photo", 50)
    document = SimpleNamespace(video=None, photo=None, document=SimpleNamespace(size=75), gif=None, audio=None, voice=None)
    assert classify_message(document) == ("document", 75)
    print("Channel history scanner self-test passed.")


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


async def run(args: argparse.Namespace) -> dict[str, object]:
    try:
        from telethon import TelegramClient
    except ImportError as error:
        raise RuntimeError("Telethon is not installed. Run setup-channel-scanner.sh.") from error

    config = load_config(args.config)
    api_id = config.get("TELEGRAM_API_ID", os.getenv("TELEGRAM_API_ID", ""))
    api_hash = config.get("TELEGRAM_API_HASH", os.getenv("TELEGRAM_API_HASH", ""))
    phone = config.get("TELEGRAM_PHONE", os.getenv("TELEGRAM_PHONE", ""))
    session = args.session or config.get("TELEGRAM_SESSION", "/var/lib/freebot-mtproto/freebot")
    if not api_id.isdigit() or not api_hash:
        raise RuntimeError("TELEGRAM_API_ID/API_HASH are not configured.")

    client = TelegramClient(session, int(api_id), api_hash)
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
        entity = await resolve_entity(client, args.channel)
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
    parser.add_argument("--self-test", action="store_true")
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return 0
    if not args.login_only and not args.channel:
        parser.error("--channel is required")
    try:
        print(json.dumps(asyncio.run(run(args)), ensure_ascii=False, separators=(",", ":")))
        return 0
    except Exception as error:
        print(json.dumps({"ok": False, "error": str(error)}, ensure_ascii=False, separators=(",", ":")))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
