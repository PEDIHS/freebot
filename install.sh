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

[[ "${EUID}" -eq 0 ]] || fail "این اسکریپت باید با root اجرا شود: sudo -i"
command -v apt-get >/dev/null 2>&1 || fail "فعلاً نصب خودکار برای Ubuntu/Debian آماده شده است."

if [[ -z "$DOMAIN" && -t 0 ]]; then
  read -rp "دامنه ربات (مثال bot.example.com): " DOMAIN
fi
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || fail "DOMAIN معتبر نیست. مثال: bot.example.com"
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || fail "DB_NAME فقط حروف، عدد و _ داشته باشد."
[[ "$DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || fail "DB_USER فقط حروف، عدد و _ داشته باشد."

log "نصب Nginx، PHP، MariaDB، Certbot و ابزارهای دانلود رسانه"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y \
  ca-certificates curl git rsync unzip zip nginx mariadb-server \
  php-fpm php-cli php-mysql php-curl php-zip php-mbstring php-xml php-intl \
  certbot python3-certbot-nginx ffmpeg aria2 mediainfo jq python3-venv

systemctl enable --now nginx mariadb >/dev/null
PHP_FPM_SERVICE="$(systemctl list-unit-files --type=service --no-legend 'php*-fpm.service' | awk '{print $1}' | sort -V | tail -1)"
[[ -n "$PHP_FPM_SERVICE" ]] || fail "PHP-FPM پیدا نشد."
systemctl enable --now "$PHP_FPM_SERVICE" >/dev/null
PHP_SOCKET="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' | sort -V | tail -1)"
[[ -S "$PHP_SOCKET" ]] || fail "سوکت PHP-FPM پیدا نشد."

log "نصب yt-dlp در محیط جداگانه"
python3 -m venv /opt/freebot-tools
/opt/freebot-tools/bin/pip install --disable-pip-version-check --upgrade pip yt-dlp
ln -sf /opt/freebot-tools/bin/yt-dlp /usr/local/bin/yt-dlp

log "دریافت نسخه منتشرشده از GitHub"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT
SHA_URL="https://raw.githubusercontent.com/PEDIHS/freebot/main/release/freebot.tar.gz.sha256"
curl -fsSL "$SHA_URL" -o "$TMP_DIR/freebot.tar.gz.sha256"
: > "$TMP_DIR/freebot.tar.gz.b64"
for part in $(seq -w 0 11); do
  curl -fsSL "https://raw.githubusercontent.com/PEDIHS/freebot/main/release/freebot.tar.gz.b64.part-${part}" >> "$TMP_DIR/freebot.tar.gz.b64"
done
base64 -d "$TMP_DIR/freebot.tar.gz.b64" > "$TMP_DIR/freebot.tar.gz"
EXPECTED_SHA="$(awk '{print $1}' "$TMP_DIR/freebot.tar.gz.sha256")"
ACTUAL_SHA="$(sha256sum "$TMP_DIR/freebot.tar.gz" | awk '{print $1}')"
[[ -n "$EXPECTED_SHA" && "$EXPECTED_SHA" == "$ACTUAL_SHA" ]] || fail "Checksum بسته انتشار معتبر نیست."
mkdir -p "$TMP_DIR/src"
tar -xzf "$TMP_DIR/freebot.tar.gz" -C "$TMP_DIR/src"

# Apply the latest overlay release. This keeps the historical base package small
# while allowing fast feature updates without replacing runtime data.
PATCH_SHA_URL="https://raw.githubusercontent.com/PEDIHS/freebot/main/release/media-patch.tar.gz.sha256"
curl -fsSL "$PATCH_SHA_URL" -o "$TMP_DIR/media-patch.tar.gz.sha256"
: > "$TMP_DIR/media-patch.tar.gz.b64"
for part in $(seq -w 0 3); do
  curl -fsSL "https://raw.githubusercontent.com/PEDIHS/freebot/main/release/media-patch.tar.gz.b64.part-${part}" >> "$TMP_DIR/media-patch.tar.gz.b64"
done
base64 -d "$TMP_DIR/media-patch.tar.gz.b64" > "$TMP_DIR/media-patch.tar.gz"
PATCH_EXPECTED="$(awk '{print $1}' "$TMP_DIR/media-patch.tar.gz.sha256")"
PATCH_ACTUAL="$(sha256sum "$TMP_DIR/media-patch.tar.gz" | awk '{print $1}')"
[[ -n "$PATCH_EXPECTED" && "$PATCH_EXPECTED" == "$PATCH_ACTUAL" ]] || fail "Checksum بسته overlay معتبر نیست."
tar -xzf "$TMP_DIR/media-patch.tar.gz" -C "$TMP_DIR/src"

mkdir -p "$APP_DIR"
rsync -a --delete \
  --exclude='config/app.php' \
  --exclude='storage/' \
  "$TMP_DIR/src/" "$APP_DIR/"

log "ساخت مسیرهای اجرایی"
mkdir -p \
  "$APP_DIR/config" \
  "$APP_DIR/storage/backups" \
  "$APP_DIR/storage/logs" \
  "$APP_DIR/storage/locks" \
  "$APP_DIR/storage/temp" \
  "$APP_DIR/storage/downloads" \
  "$APP_DIR/storage/source_queue/pending" \
  "$APP_DIR/storage/source_queue/processing" \
  "$APP_DIR/storage/source_queue/failed" \
  "$APP_DIR/storage/source_queue/done"

# Application code is read-only to the web worker. Only runtime state is writable.
chown -R root:root "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;
chown -R www-data:www-data "$APP_DIR/config" "$APP_DIR/storage"
find "$APP_DIR/config" "$APP_DIR/storage" -type d -exec chmod 750 {} \;
find "$APP_DIR/config" "$APP_DIR/storage" -type f -exec chmod 640 {} \;

if [[ "$SKIP_DB" != "1" ]]; then
  log "آماده‌سازی دیتابیس MariaDB"
  USER_EXISTS="$(mariadb -Nse "SELECT COUNT(*) FROM mysql.user WHERE User='${DB_USER}' AND Host='localhost';")"
  mariadb -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  if [[ "$USER_EXISTS" == "0" ]]; then
    if [[ -z "$DB_PASS" ]]; then
      DB_PASS="$(php -r 'echo bin2hex(random_bytes(18));')"
    fi
    SQL_PASS="${DB_PASS//\'/\'\'}"
    mariadb -e "CREATE USER '${DB_USER}'@'localhost' IDENTIFIED BY '${SQL_PASS}'; GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
    install -d -m 700 /root/.freebot
    cat > /root/.freebot/install-credentials.txt <<CREDS
DB Host: localhost
DB Port: 3306
DB Name: ${DB_NAME}
DB User: ${DB_USER}
DB Password: ${DB_PASS}
CREDS
    chmod 600 /root/.freebot/install-credentials.txt
  else
    mariadb -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
    log "کاربر دیتابیس ${DB_USER}@localhost از قبل وجود داشت؛ رمز فعلی آن حفظ شد."
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
    client_max_body_size 128M;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        try_files \$uri =404;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:${PHP_SOCKET};
        fastcgi_read_timeout 180;
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

log "فعال‌سازی Cron"
cat > /etc/cron.d/freebot <<CRON
* * * * * www-data /usr/bin/php ${APP_DIR}/cron.php >/dev/null 2>&1
CRON
chmod 644 /etc/cron.d/freebot
systemctl restart cron || true

log "فعال‌سازی موتور Multi-Worker دانلود/آپلود"
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
systemctl enable --now freebot-media.service >/dev/null

ln -sfn "$APP_DIR/scripts/update.sh" /usr/local/sbin/freebot-update
chmod +x "$APP_DIR/install.sh" "$APP_DIR/scripts/"*.sh /usr/local/sbin/freebot-update

if [[ "$AUTO_UPDATE" == "1" ]]; then
  log "فعال‌سازی بررسی آپدیت خودکار هر 5 دقیقه"
  cat > /etc/cron.d/freebot-update <<CRONUP
*/5 * * * * root /usr/local/sbin/freebot-update --quiet >/var/log/freebot-update.log 2>&1
CRONUP
  chmod 644 /etc/cron.d/freebot-update
fi

SSL_OK=0
if [[ "$SKIP_SSL" != "1" ]]; then
  log "تلاش برای دریافت SSL از Let's Encrypt"
  if certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --register-unsafely-without-email --redirect; then
    SSL_OK=1
  else
    log "SSL فعلاً صادر نشد. معمولاً DNS هنوز به این سرور نرسیده است. بعداً اجرا کن: certbot --nginx -d $DOMAIN"
  fi
fi

log "نصب زیرساخت تمام شد"
printf '\nآدرس Installer: %s\n' "https://${DOMAIN}/install/"
printf 'مسیر پروژه: %s\n' "$APP_DIR"
printf 'آپدیت دستی: freebot-update\n'
printf 'نسخه yt-dlp: %s\n' "$(yt-dlp --version 2>/dev/null || true)"
printf 'نسخه ffmpeg: %s\n' "$(ffmpeg -version 2>/dev/null | head -1 || true)"
printf 'Media workers: systemctl status freebot-media --no-pager\n'
if [[ -f /root/.freebot/install-credentials.txt ]]; then
  printf '\nمشخصات دیتابیس برای Installer:\n'
  cat /root/.freebot/install-credentials.txt
  printf '\nاین اطلاعات فقط در /root/.freebot/install-credentials.txt با دسترسی root ذخیره شده است.\n'
fi
if [[ "$SSL_OK" != "1" ]]; then
  printf '\nتا وقتی SSL صادر نشده، Installer اجازه نهایی نصب نمی‌دهد چون Webhook تلگرام HTTPS لازم دارد.\n'
fi
