#!/usr/bin/env bash
set -Eeuo pipefail

INSTALL_DIR="${FREEBOT_INSTALL_DIR:-/var/www/freebot}"
FAILURES=0
pass(){ printf 'PASS  %s\n' "$1"; }
fail(){ printf 'FAIL  %s\n' "$1"; FAILURES=$((FAILURES+1)); }
check_cmd(){ if command -v "$1" >/dev/null 2>&1; then pass "$1 available"; else fail "$1 missing"; fi; }

for cmd in php nginx mariadb aria2c ffmpeg mediainfo yt-dlp; do check_cmd "$cmd"; done
if php -r 'exit(version_compare(PHP_VERSION,"8.3.0",">=")?0:1);'; then pass "PHP >= 8.3"; else fail "PHP < 8.3"; fi
if find "$INSTALL_DIR" -maxdepth 1 -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null; then pass "PHP lint"; else fail "PHP lint"; fi
if nginx -t >/dev/null 2>&1; then pass "Nginx config"; else fail "Nginx config"; fi
if [[ -w "$INSTALL_DIR/storage/media" ]]; then pass "Media storage writable"; else fail "Media storage not writable"; fi
if [[ -f "$INSTALL_DIR/config.php" ]]; then
  # shellcheck disable=SC2016
  if php -r '$c=require $argv[1];$d=$c["db"];$p=new PDO("mysql:host={$d["host"]};dbname={$d["name"]};charset=utf8mb4",$d["user"],$d["pass"]);foreach(["media_jobs","media_workers","media_job_events","channel_stats"] as $t){$p->query("SELECT 1 FROM `$t` LIMIT 1");}foreach(["file_path","error_message"] as $n){if(!$p->query("SHOW COLUMNS FROM media_jobs LIKE ".$p->quote($n))->fetch())throw new RuntimeException($n);}foreach(["history_last_message_id","history_message_count","history_video_count","history_photo_count","history_file_count","history_total_bytes","history_scan_status","history_scan_error","history_scanned_at"] as $n){if(!$p->query("SHOW COLUMNS FROM channel_stats LIKE ".$p->quote($n))->fetch())throw new RuntimeException($n);}' "$INSTALL_DIR/config.php"; then pass "Database and Media schema"; else fail "Database or Media schema"; fi
  if systemctl is-active --quiet 'freebot-download@1.service'; then pass "Download worker"; else fail "Download worker inactive"; fi
  if systemctl is-active --quiet 'freebot-upload@1.service'; then pass "Upload worker"; else fail "Upload worker inactive"; fi
  # shellcheck disable=SC2016
  if [[ -f /var/lib/freebot-mtproto/freebot.session ]] && /opt/freebot-tools/bin/python -c 'import telethon' >/dev/null 2>&1 && runuser -u www-data -- php -r 'require $argv[1];require $argv[2];exit(MediaQueue::historyScannerStatus()["ready"]?0:1);' "$INSTALL_DIR/app.php" "$INSTALL_DIR/media.php"; then
    pass "Historical channel scanner configured"
  else
    echo "INFO  Historical channel scanner is optional and not configured."
  fi
else
  echo "INFO  Web installer is not completed; database and worker runtime checks skipped."
fi
((FAILURES==0)) || exit 1
echo "FreeBot healthcheck passed."
