#!/usr/bin/env bash
set -Eeuo pipefail

INSTALL_DIR="${FREEBOT_INSTALL_DIR:-/var/www/freebot}"
[[ ${EUID} -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
[[ "$INSTALL_DIR" == /var/www/* && -d "$INSTALL_DIR/.git" ]] || { echo "Valid FreeBot checkout not found." >&2; exit 1; }

BACKUP_DIR="/var/backups/freebot/$(date -u +%Y%m%dT%H%M%SZ)"
install -d -m 0700 "$BACKUP_DIR"
if [[ -f "$INSTALL_DIR/config.php" ]]; then install -m 0600 "$INSTALL_DIR/config.php" "$BACKUP_DIR/config.php"; fi
runuser -u www-data -- git -C "$INSTALL_DIR" diff > "$BACKUP_DIR/local-changes.patch" || true
runuser -u www-data -- git -C "$INSTALL_DIR" fetch --prune origin main
runuser -u www-data -- git -C "$INSTALL_DIR" merge --ff-only origin/main
if [[ ! -x /opt/freebot-tools/bin/python ]]; then python3 -m venv /opt/freebot-tools; fi
if ! /opt/freebot-tools/bin/python -c 'import telethon' >/dev/null 2>&1; then
  /opt/freebot-tools/bin/pip install --disable-pip-version-check 'Telethon>=1.36,<2'
fi
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
  runuser -u www-data -- /usr/bin/php -r 'require $argv[1]; App::db();' "$INSTALL_DIR/app.php"
fi
nginx -t
systemctl daemon-reload
systemctl restart php8.3-fpm nginx
systemctl restart 'freebot-download@*.service' 'freebot-upload@*.service' || true
sleep 3
"$INSTALL_DIR/healthcheck.sh"
echo "Updated to $(cat "$INSTALL_DIR/VERSION"). Backup: $BACKUP_DIR"
