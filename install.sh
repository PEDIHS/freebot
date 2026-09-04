#!/usr/bin/env bash
set -Eeuo pipefail

REPO_URL="${FREEBOT_REPO_URL:-https://github.com/PEDIHS/freebot.git}"
INSTALL_DIR="${FREEBOT_INSTALL_DIR:-/var/www/freebot}"
DOMAIN="${FREEBOT_DOMAIN:-}"
EMAIL="${FREEBOT_EMAIL:-}"
DOWNLOAD_WORKERS="${FREEBOT_DOWNLOAD_WORKERS:-2}"
UPLOAD_WORKERS="${FREEBOT_UPLOAD_WORKERS:-2}"
ENABLE_SSL=1

usage(){ echo "Usage: sudo bash install.sh --domain bot.example.com [--email you@example.com] [--install-dir /var/www/freebot] [--download-workers 2] [--upload-workers 2] [--no-ssl]"; }
while (($#)); do
  case "$1" in
    --domain) DOMAIN="${2:-}"; shift 2;;
    --email) EMAIL="${2:-}"; shift 2;;
    --install-dir) INSTALL_DIR="${2:-}"; shift 2;;
    --download-workers) DOWNLOAD_WORKERS="${2:-}"; shift 2;;
    --upload-workers) UPLOAD_WORKERS="${2:-}"; shift 2;;
    --no-ssl) ENABLE_SSL=0; shift;;
    -h|--help) usage; exit 0;;
    *) echo "Unknown option: $1" >&2; usage; exit 2;;
  esac
done

[[ ${EUID} -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
[[ "$INSTALL_DIR" == /var/www/* && "$INSTALL_DIR" != /var/www ]] || { echo "Install directory must be a child of /var/www." >&2; exit 1; }
[[ "$DOWNLOAD_WORKERS" =~ ^[1-9][0-9]*$ && "$UPLOAD_WORKERS" =~ ^[1-9][0-9]*$ ]] || { echo "Worker counts must be positive integers." >&2; exit 1; }
if [[ -z "$DOMAIN" ]]; then read -r -p "Domain (example: bot.example.com): " DOMAIN; fi
[[ "$DOMAIN" =~ ^[A-Za-z0-9.-]+$ ]] || { echo "Invalid domain." >&2; exit 1; }
if [[ $ENABLE_SSL -eq 1 && -z "$EMAIL" ]]; then read -r -p "Email for Let's Encrypt: " EMAIL; fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl git unzip rsync nginx mariadb-server software-properties-common python3 python3-venv certbot python3-certbot-nginx aria2 ffmpeg mediainfo
if ! apt-cache show php8.3-fpm >/dev/null 2>&1; then add-apt-repository -y ppa:ondrej/php; apt-get update; fi
apt-get install -y php8.3-cli php8.3-fpm php8.3-mysql php8.3-curl php8.3-mbstring php8.3-xml php8.3-zip php8.3-gd php8.3-intl php8.3-opcache

if [[ ! -x /opt/freebot-tools/bin/yt-dlp ]]; then python3 -m venv /opt/freebot-tools; fi
/opt/freebot-tools/bin/pip install --disable-pip-version-check --upgrade yt-dlp
ln -sfn /opt/freebot-tools/bin/yt-dlp /usr/local/bin/yt-dlp

if [[ -e "$INSTALL_DIR" && ! -d "$INSTALL_DIR/.git" ]]; then echo "$INSTALL_DIR exists but is not a FreeBot git checkout. Run reset-install.sh first." >&2; exit 1; fi
if [[ ! -d "$INSTALL_DIR/.git" ]]; then git clone --depth=1 "$REPO_URL" "$INSTALL_DIR"; else git -C "$INSTALL_DIR" pull --ff-only; fi
install -d -o www-data -g www-data -m 0750 "$INSTALL_DIR/storage/media"
chown -R www-data:www-data "$INSTALL_DIR"
find "$INSTALL_DIR" -type d -exec chmod 0750 {} +
find "$INSTALL_DIR" -type f -exec chmod 0640 {} +
chmod 0750 "$INSTALL_DIR/install.sh" "$INSTALL_DIR/update.sh" "$INSTALL_DIR/reset-install.sh" "$INSTALL_DIR/healthcheck.sh"

cat > /etc/nginx/sites-available/freebot <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${INSTALL_DIR};
    index index.php;
    client_max_body_size 50m;
    location / { try_files \$uri \$uri/ /index.php?\$query_string; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_read_timeout 3600;
    }
    location ~ /\. { deny all; }
    location = /config.php { deny all; }
    location ^~ /storage/ { deny all; }
}
EOF
ln -sfn /etc/nginx/sites-available/freebot /etc/nginx/sites-enabled/freebot
cat > /etc/systemd/system/freebot-download@.service <<EOF
[Unit]
Description=FreeBot Download Worker %i
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${INSTALL_DIR}
ExecStart=/usr/bin/php ${INSTALL_DIR}/worker.php --role=download --id=download-%H-%i --wait-config
Restart=always
RestartSec=3
TimeoutStopSec=30
KillSignal=SIGTERM
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths=${INSTALL_DIR}/storage ${INSTALL_DIR}

[Install]
WantedBy=multi-user.target
EOF
cat > /etc/systemd/system/freebot-upload@.service <<EOF
[Unit]
Description=FreeBot Upload Worker %i
After=network-online.target mariadb.service
Wants=network-online.target

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=${INSTALL_DIR}
ExecStart=/usr/bin/php ${INSTALL_DIR}/worker.php --role=upload --id=upload-%H-%i --wait-config
Restart=always
RestartSec=3
TimeoutStopSec=30
KillSignal=SIGTERM
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ReadWritePaths=${INSTALL_DIR}/storage ${INSTALL_DIR}

[Install]
WantedBy=multi-user.target
EOF
cat > /etc/cron.d/freebot <<EOF
* * * * * www-data /usr/bin/php -q ${INSTALL_DIR}/cron.php >> /var/log/freebot-cron.log 2>&1
EOF
chmod 0644 /etc/cron.d/freebot

nginx -t
systemctl daemon-reload
systemctl enable --now nginx mariadb php8.3-fpm
for ((i=1;i<=DOWNLOAD_WORKERS;i++)); do systemctl enable --now "freebot-download@${i}.service"; done
for ((i=1;i<=UPLOAD_WORKERS;i++)); do systemctl enable --now "freebot-upload@${i}.service"; done
if [[ $ENABLE_SSL -eq 1 ]]; then certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos --redirect -m "$EMAIL"; fi

echo "FreeBot files installed. Open: https://${DOMAIN}/install.php"
echo "After completing the web installer, run: sudo ${INSTALL_DIR}/healthcheck.sh"
