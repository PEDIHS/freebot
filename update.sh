#!/usr/bin/env bash
set -Eeuo pipefail

INSTALL_DIR="${FREEBOT_INSTALL_DIR:-/var/www/freebot}"
DOWNLOAD_WORKERS="${FREEBOT_DOWNLOAD_WORKERS:-4}"
UPLOAD_WORKERS="${FREEBOT_UPLOAD_WORKERS:-4}"
[[ ${EUID} -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
[[ "$INSTALL_DIR" == /var/www/* && -d "$INSTALL_DIR/.git" ]] || { echo "Valid FreeBot checkout not found." >&2; exit 1; }
[[ "$DOWNLOAD_WORKERS" =~ ^[1-9][0-9]*$ && "$UPLOAD_WORKERS" =~ ^[1-9][0-9]*$ ]] || { echo "Worker counts must be positive integers." >&2; exit 1; }

BACKUP_DIR="/var/backups/freebot/$(date -u +%Y%m%dT%H%M%SZ)"
install -d -m 0700 "$BACKUP_DIR"
if [[ -f "$INSTALL_DIR/config.php" ]]; then install -m 0600 "$INSTALL_DIR/config.php" "$BACKUP_DIR/config.php"; fi
runuser -u www-data -- git -C "$INSTALL_DIR" diff > "$BACKUP_DIR/local-changes.patch" || true
runuser -u www-data -- git -C "$INSTALL_DIR" fetch --prune origin main
runuser -u www-data -- git -C "$INSTALL_DIR" merge --ff-only origin/main
if [[ ! -x /opt/freebot-tools/bin/python ]]; then python3 -m venv /opt/freebot-tools; fi
/opt/freebot-tools/bin/pip install --disable-pip-version-check --upgrade 'Telethon>=1.36,<2' 'cryptg>=0.4,<1' 'hachoir>=3.3,<4'
chown -R www-data:www-data "$INSTALL_DIR"
install -d -o www-data -g www-data -m 0750 "$INSTALL_DIR/storage/media"
chmod 0750 "$INSTALL_DIR/setup-channel-scanner.sh"
install -d -o root -g www-data -m 0750 /etc/freebot
install -d -o www-data -g www-data -m 0700 /var/lib/freebot-mtproto
if [[ -f /etc/nginx/sites-available/freebot ]]; then
  sed -i 's/fastcgi_read_timeout 3600;/fastcgi_read_timeout 21600;/' /etc/nginx/sites-available/freebot
fi
if [[ -f "$INSTALL_DIR/config.php" ]]; then
  # shellcheck disable=SC2016
  runuser -u www-data -- /usr/bin/php -r 'require $argv[1]; App::db(); App::q("UPDATE media_batches SET pipeline_depth=4 WHERE source_type=? AND pipeline_depth<4 AND status IN (?,?,?)",["telegram_channel","queued","running","paused"]); App::q("UPDATE media_batches SET status=\"queued\",scan_status=\"queued\",scan_attempts=0,scan_next_attempt_at=NOW(),scan_error=\"صف 0/0 قدیمی بازیابی شد؛ اسکن با منطق جدید دوباره اجرا می‌شود.\",scan_locked_by=NULL,scan_lock_token=NULL,scan_lock_expires_at=NULL,source_last_message_id=0,source_scanned_items=0,source_skipped_items=0,completed_at=NULL WHERE source_type=\"telegram_channel\" AND status=\"completed\" AND total_items=0 AND ((scan_attempts=0 AND source_last_message_id=0) OR (source_video_count>0 AND source_skipped_items<source_video_count))");' "$INSTALL_DIR/app.php"
fi
nginx -t
systemctl daemon-reload
systemctl restart php8.3-fpm nginx
for ((i=1;i<=DOWNLOAD_WORKERS;i++)); do systemctl enable --now "freebot-download@${i}.service"; done
for ((i=1;i<=UPLOAD_WORKERS;i++)); do systemctl enable --now "freebot-upload@${i}.service"; done
systemctl restart 'freebot-download@*.service' 'freebot-upload@*.service' || true
sleep 3
"$INSTALL_DIR/healthcheck.sh"
echo "Updated to $(cat "$INSTALL_DIR/VERSION"). Backup: $BACKUP_DIR"
