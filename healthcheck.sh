#!/usr/bin/env bash
set -u

APP_DIR="${APP_DIR:-/var/www/freebot}"
DOMAIN="${DOMAIN:-}"

ok(){ printf '[OK] %s\n' "$*"; }
warn(){ printf '[WARN] %s\n' "$*"; }
fail(){ printf '[FAIL] %s\n' "$*"; STATUS=1; }
STATUS=0

command -v nginx >/dev/null 2>&1 && ok "nginx installed" || fail "nginx missing"
command -v php >/dev/null 2>&1 && ok "php $(php -r 'echo PHP_VERSION;')" || fail "php missing"
command -v mariadb >/dev/null 2>&1 && ok "mariadb client installed" || fail "mariadb missing"
command -v certbot >/dev/null 2>&1 && ok "certbot installed" || warn "certbot missing"
command -v yt-dlp >/dev/null 2>&1 && ok "yt-dlp $(yt-dlp --version 2>/dev/null)" || fail "yt-dlp missing"
command -v ffmpeg >/dev/null 2>&1 && ok "ffmpeg installed" || fail "ffmpeg missing"
command -v aria2c >/dev/null 2>&1 && ok "aria2 installed" || fail "aria2 missing"
command -v mediainfo >/dev/null 2>&1 && ok "mediainfo installed" || warn "mediainfo missing"

[[ -f "$APP_DIR/index.php" ]] && ok "application files present" || fail "application missing in $APP_DIR"
[[ -f "$APP_DIR/install/index.php" ]] && ok "web installer present" || fail "web installer missing"
[[ -d "$APP_DIR/storage" ]] && ok "storage present" || fail "storage missing"
[[ -d "$APP_DIR/config" ]] && ok "config present" || fail "config missing"
[[ -f /etc/cron.d/freebot ]] && ok "cron installed" || fail "cron missing"
[[ -L /usr/local/sbin/freebot-update || -x /usr/local/sbin/freebot-update ]] && ok "freebot-update installed" || fail "updater missing"

nginx -t >/dev/null 2>&1 && ok "nginx config valid" || fail "nginx config invalid"
php -l "$APP_DIR/index.php" >/dev/null 2>&1 && ok "PHP entry point valid" || fail "PHP syntax check failed"

if [[ -n "$DOMAIN" ]]; then
  if curl -fsSIL --max-time 10 "https://$DOMAIN/" >/dev/null; then
    ok "HTTPS reachable: $DOMAIN"
  else
    warn "HTTPS is not reachable yet: $DOMAIN"
  fi
fi

exit "$STATUS"
