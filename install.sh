#!/usr/bin/env bash
set -Eeuo pipefail

REPO_URL="${REPO_URL:-https://github.com/PEDIHS/freebot.git}"
BRANCH="${BRANCH:-main}"
APP_DIR="${APP_DIR:-/var/www/freebot}"
DOMAIN="${DOMAIN:-}"
DB_NAME="${DB_NAME:-freebot}"
DB_USER="${DB_USER:-freebot}"
DB_PASS="${DB_PASS:-}"
SKIP_DB="${SKIP_DB:-0}"
SKIP_SSL="${SKIP_SSL:-0}"
AUTO_UPDATE="${AUTO_UPDATE:-0}"

log(){ printf '\n[freebot] %s\n' "$*"; }
fail(){ printf '\n[freebot] ERROR: %s\n' "$*" >&2; exit 1; }

[[ "$EUID" -eq 0 ]] || fail "با root اجرا کن."
command -v apt-get >/dev/null 2>&1 || fail "فعلاً Ubuntu/Debian پشتیبانی می‌شود."
if [[ -z "$DOMAIN" && -t 0 ]]; then read -rp "دامنه ربات: " DOMAIN; fi
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || fail "DOMAIN معتبر نیست."
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || fail "DB_NAME معتبر نیست."
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || fail "DB_USER معتبر نیست."

log "نصب پیش‌نیازها"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y ca-certificates curl git rsync unzip zip nginx mariadb-server \
  php-fpm php-cli php-mysql php-curl php-zip php-mbstring php-xml php-intl \
  certbot python3-certbot-nginx ffmpeg aria2 mediainfo jq python3-venv
systemctl enable --now nginx mariadb >/dev/null
PHP_FPM_SERVICE="$(systemctl list-unit-files --type=service --no-legend 'php*-fpm.service' | awk '{print $1}' | sort -V | tail -1)"
[[ -n "$PHP_FPM_SERVICE" ]] || fail "PHP-FPM پیدا نشد."
systemctl enable --now "$PHP_FPM_SERVICE" >/dev/null
PHP_SOCKET="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' | sort -V | tail -1)"
[[ -S "$PHP_SOCKET" ]] || fail "سوکت PHP-FPM پیدا نشد."

log "نصب yt-dlp"
python3 -m venv /opt/freebot-tools
/opt/freebot-tools/bin/pip install --disable-pip-version-check --upgrade pip yt-dlp
ln -sfn /opt/freebot-tools/bin/yt-dlp /usr/local/bin/yt-dlp

log "دریافت repository از GitHub"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
git -c http.version=HTTP/1.1 clone --depth 1 --single-branch --branch "$BRANCH" "$REPO_URL" "$TMP_DIR/repo" \
  || fail "git clone ناموفق بود."
REL="$TMP_DIR/repo/release"
mkdir -p "$TMP_DIR/src"

unpack_release(){
  local prefix="$1" sha_file="$2" out="$3"
  local parts=("$REL/${prefix}.b64.part-"*)
  (( ${#parts[@]} > 0 )) || fail "تکه‌های $prefix پیدا نشد."
  cat "${parts[@]}" > "$TMP_DIR/${prefix}.b64"
  base64 -d "$TMP_DIR/${prefix}.b64" > "$TMP_DIR/$out" || fail "Decode $prefix ناموفق بود."
  local expected actual
  expected="$(awk '{print $1}' "$REL/$sha_file")"
  actual="$(sha256sum "$TMP_DIR/$out" | awk '{print $1}')"
  [[ -n "$expected" && "$expected" == "$actual" ]] || fail "Checksum $prefix معتبر نیست."
  tar -tzf "$TMP_DIR/$out" >/dev/null || fail "آرشیو $prefix خراب است."
  tar -xzf "$TMP_DIR/$out" -C "$TMP_DIR/src"
}

log "بازسازی release به صورت محلی"
unpack_release "freebot.tar.gz" "freebot.tar.gz.sha256" "freebot.tar.gz"
unpack_release "media-patch.tar.gz" "media-patch.tar.gz.sha256" "media-patch.tar.gz"
if compgen -G "$REL/media-hotfix.tar.gz.b64.part-*" >/dev/null; then
  unpack_release "media-hotfix.tar.gz" "media-hotfix.tar.gz.sha256" "media-hotfix.tar.gz"
fi

mkdir -p "$APP_DIR"
rsync -a --delete --exclude='config/app.php' --exclude='storage/' "$TMP_DIR/src/" "$APP_DIR/"
# Always install the latest Git-local updater from repository root.
if [[ -f "$TMP_DIR/repo/update.sh" ]]; then
  install -m 0755 "$TMP_DIR/repo/update.sh" "$APP_DIR/scripts/update.sh"
fi

log "ساخت مسیرها و Permission"
mkdir -p "$APP_DIR/config" "$APP_DIR/storage"/{backups,logs,locks,temp,downloads} \
  "$APP_DIR/storage/source_queue"/{pending,processing,failed,done}
chown -R root:root "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;
chown -R www-data:www-data "$APP_DIR/config" "$APP_DIR/storage"
find "$APP_DIR/config" "$APP_DIR/storage" -type d -exec chmod 750 {} \;
find "$APP_DIR/config" "$APP_DIR/storage" -type f -exec chmod 640 {} \;
chmod +x "$APP_DIR/install.sh" "$APP_DIR/scripts/"*.sh 2>/dev/null || true

if [[ "$SKIP_DB" != "1" ]]; then
  log "آماده‌سازی دیتابیس"
  USER_EXISTS="$(mariadb -Nse "SELECT COUNT(*) FROM mysql.user WHERE User='${DB_USER}' AND Host='localhost';")"
  mariadb -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  if [[ "$USER_EXISTS" == "0" ]]; then
    [[ -n "$DB_PASS" ]] || DB_PASS="$(php -r 'echo bin2hex(random_bytes(18));')"
    SQL_PASS="${DB_PASS//\'/\'\'}"
    mariadb -e "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${SQL_PASS}'; GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
    install -d -m 700 /root/.freebot
    printf 'DB Host: localhost\nDB Port: 3306\nDB Name: %s\nDB User: %s\nDB Password: %s\n' "$DB_NAME" "$DB_USER" "$DB_PASS" > /root/.freebot/install-credentials.txt
    chmod 600 /root/.freebot/install-credentials.txt
  else
    mariadb -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
  fi
fi

log "تنظیم Nginx"
cat > /etc/nginx/sites-available/freebot <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${APP_DIR};
    index index.php;
    client_max_body_size 256M;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php$ {
        try_files \$uri =404;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCKET};
        fastcgi_read_timeout 300;
    }
    location ~ /\. { deny all; }
    location ^~ /app/ { deny all; }
    location ^~ /config/ { deny all; }
    location ^~ /database/ { deny all; }
    location ^~ /storage/ { deny all; }
    location ^~ /tests/ { deny all; }
    location ^~ /scripts/ { deny all; }
    location ^~ /deploy/ { deny all; }
    location ~* \.(?:sql|log|lock|md|sh|env|ini)$ { deny all; }
}
NGINX
ln -sfn /etc/nginx/sites-available/freebot /etc/nginx/sites-enabled/freebot
rm -f /etc/nginx/sites-enabled/default
nginx -t
systemctl reload nginx

cat > /etc/cron.d/freebot <<CRON
* * * * * www-data /usr/bin/php ${APP_DIR}/cron.php >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/freebot
systemctl restart cron || true

log "فعال‌سازی Media Multi-Worker"
cat > /etc/systemd/system/freebot-media.service <<SERVICE
[Unit]
Description=FreeBot Media Download/Upload Supervisor
After=network-online.target mariadb.service
Wants=network-online.target
[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/php ${APP_DIR}/scripts/media-supervisor.php
Restart=always
RestartSec=2
KillMode=mixed
TimeoutStopSec=15
Nice=5
NoNewPrivileges=true
PrivateTmp=true
[Install]
WantedBy=multi-user.target
SERVICE
systemctl daemon-reload
systemctl enable --now freebot-media.service >/dev/null 2>&1 || true
ln -sfn "$APP_DIR/scripts/update.sh" /usr/local/sbin/freebot-update

if [[ "$AUTO_UPDATE" == "1" ]]; then
  cat > /etc/cron.d/freebot-update <<CRONUP
*/5 * * * * root /usr/local/sbin/freebot-update --quiet >/var/log/freebot-update.log 2>&1
CRONUP
  chmod 644 /etc/cron.d/freebot-update
fi

if [[ "$SKIP_SSL" != "1" ]]; then
  log "دریافت SSL"
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email --redirect \
    || log "SSL صادر نشد؛ بعداً: certbot --nginx -d $DOMAIN"
fi

log "نصب کامل شد"
printf 'نسخه: %s\n' "$(cat "$APP_DIR/VERSION" 2>/dev/null || echo unknown)"
printf 'Installer: https://%s/install/\n' "$DOMAIN"
printf 'Health: freebot-health\nUpdater: freebot-update\n'
