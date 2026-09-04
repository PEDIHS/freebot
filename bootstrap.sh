#!/usr/bin/env bash
set -Eeuo pipefail

APP_DIR="${APP_DIR:-/var/www/freebot}"
BASE_URL="https://raw.githubusercontent.com/PEDIHS/freebot/main"

log(){ printf '\n[freebot-bootstrap] %s\n' "$*"; }
fail(){ printf '\n[freebot-bootstrap] ERROR: %s\n' "$*" >&2; exit 1; }

[[ "${EUID}" -eq 0 ]] || fail "با root اجرا کن: sudo -i"
command -v apt-get >/dev/null 2>&1 || fail "نصب خودکار فعلاً برای Ubuntu/Debian است."

log "نصب ابزارهای پایه و حرفه‌ای پردازش رسانه"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y \
  ca-certificates curl git rsync unzip zip jq openssl \
  ffmpeg aria2 mediainfo python3 python3-venv

TMP="$(mktemp)"
HOTFIX_DIR="$(mktemp -d)"
trap 'rm -f "$TMP"; rm -rf "$HOTFIX_DIR"' EXIT

log "اجرای Installer اصلی FreeBot"
curl -fsSL "$BASE_URL/install.sh" -o "$TMP"
bash -n "$TMP"
bash "$TMP"

log "اعمال آخرین Hotfix موتور Multi-Worker"
curl -fsSL "$BASE_URL/release/media-hotfix.tar.gz.sha256" -o "$HOTFIX_DIR/media-hotfix.tar.gz.sha256"
curl -fsSL "$BASE_URL/release/media-hotfix.tar.gz.b64.part-00" -o "$HOTFIX_DIR/media-hotfix.tar.gz.b64"
base64 -d "$HOTFIX_DIR/media-hotfix.tar.gz.b64" > "$HOTFIX_DIR/media-hotfix.tar.gz"
HOTFIX_EXPECTED="$(awk '{print $1}' "$HOTFIX_DIR/media-hotfix.tar.gz.sha256")"
HOTFIX_ACTUAL="$(sha256sum "$HOTFIX_DIR/media-hotfix.tar.gz" | awk '{print $1}')"
[[ -n "$HOTFIX_EXPECTED" && "$HOTFIX_EXPECTED" == "$HOTFIX_ACTUAL" ]] || fail "Checksum Hotfix معتبر نیست."
tar -tzf "$HOTFIX_DIR/media-hotfix.tar.gz" >/dev/null || fail "بسته Hotfix خراب است."
tar -xzf "$HOTFIX_DIR/media-hotfix.tar.gz" -C "$APP_DIR"
chmod +x "$APP_DIR/scripts/"*.sh 2>/dev/null || true
ln -sfn "$APP_DIR/scripts/update.sh" /usr/local/sbin/freebot-update
systemctl restart freebot-media.service >/dev/null 2>&1 || true

log "نصب health-check"
curl -fsSL "$BASE_URL/healthcheck.sh" -o /usr/local/sbin/freebot-health
chmod 0755 /usr/local/sbin/freebot-health

printf '\nنصب زیرساخت و Hotfix کامل شد.\n'
printf 'نسخه پروژه: %s\n' "$(cat "$APP_DIR/VERSION" 2>/dev/null || echo unknown)"
printf 'دستورات مدیریت:\n'
printf '  freebot-update   # دریافت آخرین نسخه پروژه\n'
printf '  freebot-health   # تست سرویس‌ها، PHP و ابزارهای رسانه\n'
printf '  systemctl status freebot-media --no-pager\n'
