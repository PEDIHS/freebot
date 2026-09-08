# FreeBot 2.7.0 — Parallel Media

This release hardens Telegram video delivery and enables real parallel media processing.

- Probe final video files with ffprobe and send explicit width, height, duration and streaming metadata.
- Keep MP4/M4V as native Telegram video; do not mislabel incompatible containers as native video.
- Use independent in-memory Telethon transfer sessions cloned from the authorized session, avoiding shared SQLite transfer locks.
- Default to 4 download workers and 4 upload workers, configurable with FREEBOT_DOWNLOAD_WORKERS and FREEBOT_UPLOAD_WORKERS.
- Use a Telegram pipeline window of 4 (bounded up to 8) and upgrade active Telegram queues to depth 4 during update.
- Sort downloading and uploading jobs to the top of the Media job list.
- Serialize only the short download admission check and account for remaining bytes of active large downloads before claiming another Telegram file, preventing parallel workers from over-committing temporary disk space.
- Add database regression coverage for concurrent Telegram download/upload claims.
