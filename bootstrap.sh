#!/usr/bin/env bash
set -Eeuo pipefail

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

log "اجرای Installer اصلی FreeBot"
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT
curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/install.sh -o "$TMP"
bash -n "$TMP"
bash "$TMP"

log "نصب health-check"
curl -fsSL https://raw.githubusercontent.com/PEDIHS/freebot/main/healthcheck.sh -o /usr/local/sbin/freebot-health
chmod 0755 /usr/local/sbin/freebot-health

printf '\nدستورات مدیریت:\n'
printf '  freebot-update   # دریافت آخرین نسخه پروژه\n'
printf '  freebot-health   # تست سرویس‌ها، PHP و ابزارهای رسانه\n'
