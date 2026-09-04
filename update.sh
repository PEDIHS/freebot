#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="${APP_DIR:-/var/www/freebot}"
REPO_URL="${REPO_URL:-https://github.com/PEDIHS/freebot.git}"
BRANCH="${BRANCH:-main}"
QUIET=0; [[ "${1:-}" == "--quiet" ]] && QUIET=1
log(){ [[ "$QUIET" == "1" ]] || printf '[freebot-update] %s\n' "$*"; }
fail(){ printf '[freebot-update] ERROR: %s\n' "$*" >&2; exit 1; }

[[ "$EUID" -eq 0 ]] || fail "با root اجرا کن."
[[ -d "$APP_DIR" ]] || fail "$APP_DIR وجود ندارد."
TMP_DIR="$(mktemp -d)"; trap 'rm -rf "$TMP_DIR"' EXIT

log "Clone آخرین نسخه"
git -c http.version=HTTP/1.1 clone --depth 1 --single-branch --branch "$BRANCH" "$REPO_URL" "$TMP_DIR/repo" >/dev/null 2>&1 \
  || fail "git clone ناموفق بود."
REL="$TMP_DIR/repo/release"
REPAIR="$TMP_DIR/repo/repair_release.py"
[[ -f "$REPAIR" ]] || fail "repair_release.py پیدا نشد."
mkdir -p "$TMP_DIR/src"

unpack(){
  local prefix="$1" sha_file="$2" out="$3"
  python3 "$REPAIR" "$REL" "$prefix" "$sha_file" "$TMP_DIR/$out" \
    || fail "بازسازی $prefix ناموفق بود."
  tar -xzf "$TMP_DIR/$out" -C "$TMP_DIR/src" \
    || fail "Extract $prefix ناموفق بود."
}

log "بازسازی و اعتبارسنجی release"
unpack freebot.tar.gz freebot.tar.gz.sha256 freebot.tar.gz
unpack media-patch.tar.gz media-patch.tar.gz.sha256 media-patch.tar.gz
if compgen -G "$REL/media-hotfix.tar.gz.b64.part-*" >/dev/null; then
  unpack media-hotfix.tar.gz media-hotfix.tar.gz.sha256 media-hotfix.tar.gz
fi

mkdir -p "$TMP_DIR/src/scripts"
install -m 0755 "$TMP_DIR/repo/update.sh" "$TMP_DIR/src/scripts/update.sh"

required_files=(
  "index.php" "webhook.php" "cron.php" "install/index.php" "app/bootstrap.php"
  "database/schema.sql" "scripts/media-supervisor.php" "scripts/media-worker.php" "scripts/update.sh"
)
for required in "${required_files[@]}"; do
  [[ -f "$TMP_DIR/src/$required" ]] || fail "فایل ضروری release موجود نیست: $required"
done
find "$TMP_DIR/src" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/tmp/freebot-php-lint.log 2>&1 \
  || { cat /tmp/freebot-php-lint.log >&2; fail "PHP lint ناموفق بود."; }

NEW="$(cat "$TMP_DIR/src/VERSION" 2>/dev/null || echo unknown)"
OLD="$(cat "$APP_DIR/VERSION" 2>/dev/null || echo unknown)"
mkdir -p "$APP_DIR/storage/backups"
BACKUP="$APP_DIR/storage/backups/pre-update-$(date +%Y%m%d-%H%M%S).tar.gz"
tar -C "$APP_DIR" -czf "$BACKUP" --exclude='./storage/backups' --exclude='./storage/logs' --exclude='./storage/temp' --exclude='./storage/downloads' .

rsync -a --delete --exclude='config/app.php' --exclude='storage/' "$TMP_DIR/src/" "$APP_DIR/"
mkdir -p "$APP_DIR/config" "$APP_DIR/storage"/{backups,logs,locks,temp,downloads} "$APP_DIR/storage/source_queue"/{pending,processing,failed,done}
chown -R root:root "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 755 {} \;
find "$APP_DIR" -type f -exec chmod 644 {} \;
chown -R www-data:www-data "$APP_DIR/config" "$APP_DIR/storage"
find "$APP_DIR/config" "$APP_DIR/storage" -type d -exec chmod 750 {} \;
find "$APP_DIR/config" "$APP_DIR/storage" -type f -exec chmod 640 {} \;
chmod +x "$APP_DIR/install.sh" "$APP_DIR/scripts/"*.sh 2>/dev/null || true
ln -sfn "$APP_DIR/scripts/update.sh" /usr/local/sbin/freebot-update

if [[ -f "$APP_DIR/config/app.php" && -f "$APP_DIR/storage/installed.lock" ]]; then
  php "$APP_DIR/scripts/migrate.php" || fail "Migration ناموفق؛ Backup: $BACKUP"
fi

PHP_FPM_SERVICE="$(systemctl list-unit-files --type=service --no-legend 'php*-fpm.service' | awk '{print $1}' | sort -V | tail -1)"
[[ -z "$PHP_FPM_SERVICE" ]] || systemctl restart "$PHP_FPM_SERVICE" || true
nginx -t >/dev/null && systemctl reload nginx || true
systemctl restart freebot-media.service || true
log "آپدیت کامل شد: $OLD -> $NEW"
