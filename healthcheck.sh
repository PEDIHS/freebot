#!/usr/bin/env bash
set -Eeuo pipefail

INSTALL_DIR="${FREEBOT_INSTALL_DIR:-/var/www/freebot}"
FAILURES=0
pass(){ printf 'PASS  %s\n' "$1"; }
fail(){ printf 'FAIL  %s\n' "$1"; FAILURES=$((FAILURES+1)); }
check_cmd(){ command -v "$1" >/dev/null 2>&1 && pass "$1 available" || fail "$1 missing"; }

for cmd in php nginx mariadb aria2c ffmpeg mediainfo yt-dlp; do check_cmd "$cmd"; done
php -r 'exit(version_compare(PHP_VERSION,"8.3.0",">=")?0:1);' && pass "PHP >= 8.3" || fail "PHP < 8.3"
find "$INSTALL_DIR" -maxdepth 1 -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null && pass "PHP lint" || fail "PHP lint"
nginx -t >/dev/null 2>&1 && pass "Nginx config" || fail "Nginx config"
[[ -w "$INSTALL_DIR/storage/media" ]] && pass "Media storage writable" || fail "Media storage not writable"
if [[ -f "$INSTALL_DIR/config.php" ]]; then
  php -r '$c=require $argv[1];$d=$c["db"];$p=new PDO("mysql:host={$d["host"]};dbname={$d["name"]};charset=utf8mb4",$d["user"],$d["pass"]);foreach(["media_jobs","media_workers","media_job_events"] as $t){$p->query("SELECT 1 FROM `$t` LIMIT 1");}' "$INSTALL_DIR/config.php" && pass "Database and Media schema" || fail "Database or Media schema"
  systemctl is-active --quiet 'freebot-download@1.service' && pass "Download worker" || fail "Download worker inactive"
  systemctl is-active --quiet 'freebot-upload@1.service' && pass "Upload worker" || fail "Upload worker inactive"
else
  echo "INFO  Web installer is not completed; database and worker runtime checks skipped."
fi
((FAILURES==0)) || exit 1
echo "FreeBot healthcheck passed."
