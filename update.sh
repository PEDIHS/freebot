#!/usr/bin/env bash
set -Eeuo pipefail
APP_DIR="${APP_DIR:-/var/www/freebot}"
REPO_URL="${REPO_URL:-https://github.com/PEDIHS/freebot.git}"
BRANCH="${BRANCH:-main}"
TARGET_VERSION="1.9.3-media-engine"
QUIET=0; [[ "${1:-}" == "--quiet" ]] && QUIET=1
log(){ [[ "$QUIET" == "1" ]] || printf '[freebot-update] %s\n' "$*"; }
fail(){ printf '[freebot-update] ERROR: %s\n' "$*" >&2; exit 1; }
[[ "$EUID" -eq 0 ]] || fail "با root اجرا کن."
[[ -d "$APP_DIR" ]] || fail "$APP_DIR وجود ندارد."
command -v patch >/dev/null || apt-get install -y patch >/dev/null
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
log "Clone آخرین نسخه"
git -c http.version=HTTP/1.1 clone --depth 1 --single-branch --branch "$BRANCH" "$REPO_URL" "$TMP/repo" >/dev/null 2>&1 || fail "git clone ناموفق بود."
REL="$TMP/repo/release"; mkdir -p "$TMP/src"

cat "$REL"/freebot.tar.gz.b64.part-* > "$TMP/base.b64" || fail "خواندن base ناموفق بود."
base64 -d "$TMP/base.b64" > "$TMP/base.tar.gz" || fail "Decode base ناموفق بود."
[[ "$(sha256sum "$TMP/base.tar.gz"|awk '{print $1}')" == "$(awk '{print $1}' "$REL/freebot.tar.gz.sha256")" ]] || fail "Checksum base نامعتبر است."
tar -tzf "$TMP/base.tar.gz" >/dev/null || fail "Base خراب است."
tar -xzf "$TMP/base.tar.gz" -C "$TMP/src" || fail "Extract base ناموفق بود."

cat "$REL"/media-overlay.patch.gz.b64.part-* > "$TMP/overlay.b64" || fail "خواندن overlay ناموفق بود."
base64 -d "$TMP/overlay.b64" > "$TMP/overlay.patch.gz" || fail "Decode overlay ناموفق بود."
[[ "$(sha256sum "$TMP/overlay.patch.gz"|awk '{print $1}')" == "$(awk '{print $1}' "$REL/media-overlay.patch.gz.sha256")" ]] || fail "Checksum overlay نامعتبر است."
gzip -t "$TMP/overlay.patch.gz" || fail "Overlay خراب است."
gzip -dc "$TMP/overlay.patch.gz" > "$TMP/overlay.patch"
set +e
( cd "$TMP/src" && patch -p1 --batch --forward < "$TMP/overlay.patch" ) >"$TMP/overlay.log" 2>&1
PATCH_RC=$?
set -e
[[ "$QUIET" == "1" ]] || cat "$TMP/overlay.log"
if (( PATCH_RC != 0 )); then log "Overlay conflict سازگاری داشت؛ نتیجه با تست کامل تعیین می‌شود."; fi
find "$TMP/src" -type f \( -name '*.rej' -o -name '*.orig' \) -delete
printf '%s\n' "$TARGET_VERSION" > "$TMP/src/VERSION"
mkdir -p "$TMP/src/scripts"
install -m 0755 "$TMP/repo/update.sh" "$TMP/src/scripts/update.sh"

required=(
  index.php webhook.php cron.php install/index.php admin/index.php admin/media-status.php
  app/bootstrap.php app/MediaDownloader.php app/MediaQueue.php app/MediaWorker.php
  database/schema.sql scripts/media-supervisor.php scripts/media-worker.php scripts/migrate.php scripts/update.sh
)
for f in "${required[@]}"; do [[ -f "$TMP/src/$f" ]] || fail "فایل ضروری release موجود نیست: $f"; done
grep -q 'CREATE TABLE IF NOT EXISTS media_jobs' "$TMP/src/database/schema.sql" || fail "media_jobs در schema وجود ندارد."
grep -q 'media_download_workers' "$TMP/src/database/schema.sql" || fail "تنظیمات Worker در schema وجود ندارد."
grep -q 'دانلود و آپلود فیلم' "$TMP/src/admin/index.php" || fail "بخش Media در پنل وجود ندارد."
grep -q 'MediaDownloader.php' "$TMP/src/app/bootstrap.php" || fail "Bootstrap موتور Media ناقص است."
find "$TMP/src" -type f -name '*.php' -print0 | xargs -0 -n1 php -l >/tmp/freebot-update-lint.log 2>&1 || { cat /tmp/freebot-update-lint.log >&2; fail "PHP lint ناموفق بود."; }
php "$TMP/src/tests/run.php" >/tmp/freebot-update-tests.log 2>&1 || { tail -100 /tmp/freebot-update-tests.log >&2; fail "تست پروژه ناموفق بود؛ آپدیت اعمال نشد."; }
grep -Eq 'RESULT: [0-9]+ passed, 0 failed' /tmp/freebot-update-tests.log || { tail -100 /tmp/freebot-update-tests.log >&2; fail "نتیجه تست معتبر نیست."; }
log "Release معتبر است: $(tail -1 /tmp/freebot-update-tests.log)"

NEW="$(cat "$TMP/src/VERSION" 2>/dev/null || echo unknown)"; OLD="$(cat "$APP_DIR/VERSION" 2>/dev/null || echo unknown)"
mkdir -p "$APP_DIR/storage/backups"
BACKUP="$APP_DIR/storage/backups/pre-update-$(date +%Y%m%d-%H%M%S).tar.gz"
tar -C "$APP_DIR" -czf "$BACKUP" --exclude='./storage/backups' --exclude='./storage/logs' --exclude='./storage/temp' --exclude='./storage/downloads' .
rsync -a --delete --exclude='config/app.php' --exclude='storage/' "$TMP/src/" "$APP_DIR/"
mkdir -p "$APP_DIR/config" "$APP_DIR/storage"/{backups,logs,locks,temp,downloads} "$APP_DIR/storage/source_queue"/{pending,processing,failed,done}
chown -R root:root "$APP_DIR"; find "$APP_DIR" -type d -exec chmod 755 {} \;; find "$APP_DIR" -type f -exec chmod 644 {} \;
chown -R www-data:www-data "$APP_DIR/config" "$APP_DIR/storage"; find "$APP_DIR/config" "$APP_DIR/storage" -type d -exec chmod 750 {} \;; find "$APP_DIR/config" "$APP_DIR/storage" -type f -exec chmod 640 {} \;
chmod +x "$APP_DIR/scripts/"*.php "$APP_DIR/scripts/"*.sh 2>/dev/null || true
ln -sfn "$APP_DIR/scripts/update.sh" /usr/local/sbin/freebot-update
if [[ -f "$APP_DIR/config/app.php" && -f "$APP_DIR/storage/installed.lock" ]]; then php "$APP_DIR/scripts/migrate.php" || fail "Migration ناموفق؛ Backup: $BACKUP"; fi
PHP_FPM_SERVICE="$(systemctl list-unit-files --type=service --no-legend 'php*-fpm.service'|awk '{print $1}'|sort -V|tail -1)"; [[ -z "$PHP_FPM_SERVICE" ]] || systemctl restart "$PHP_FPM_SERVICE" || true
nginx -t >/dev/null && systemctl reload nginx || true
systemctl restart freebot-media.service || true
log "آپدیت کامل شد: $OLD -> $NEW"
