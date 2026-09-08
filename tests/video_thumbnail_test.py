#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import subprocess
import tempfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "channel_history_scan.py"
spec = importlib.util.spec_from_file_location("freebot_channel_history_scan", SCRIPT)
if spec is None or spec.loader is None:
    raise RuntimeError("Could not load channel_history_scan.py")
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

with tempfile.TemporaryDirectory(prefix="freebot-thumb-test-") as directory:
    temp = Path(directory)
    video = temp / "portrait.mp4"
    subprocess.run(
        [
            "ffmpeg",
            "-hide_banner",
            "-loglevel",
            "error",
            "-y",
            "-f",
            "lavfi",
            "-i",
            "testsrc=size=720x1280:rate=25",
            "-t",
            "3",
            "-c:v",
            "mpeg4",
            "-q:v",
            "5",
            "-pix_fmt",
            "yuv420p",
            "-movflags",
            "+faststart",
            str(video),
        ],
        check=True,
        timeout=60,
    )
    metadata = module.ffprobe_video_metadata(video)
    assert metadata["width"] == 720
    assert metadata["height"] == 1280
    assert metadata["duration"] >= 3
    thumbnail = module.ensure_upload_thumbnail(video, metadata)
    assert thumbnail == module.thumbnail_path_for(video)
    assert module.valid_telegram_thumbnail(thumbnail)
    width, height = module.thumbnail_dimensions(thumbnail)
    assert 1 <= width <= 320
    assert 1 <= height <= 320
    assert 0 < thumbnail.stat().st_size <= 19_500
    assert thumbnail.suffix.lower() == ".jpg"

print("Explicit Telegram video thumbnail test passed.")
