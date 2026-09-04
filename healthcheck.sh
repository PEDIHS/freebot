#!/usr/bin/env bash
set -Eeuo pipefail

INSTALL_DIR="${FREEBOT_INSTALL_DIR:-/var/www/freebot}"
FAILURES=0
pass(){ printf 'PASS  %s\n' "$1"; }
fail(){ printf 'FAIL  %s\n' "$1"; FAILURES=$((FAILURES+1)); }
check_cmd(){ if command -v "$1" >/dev/null 2>&1; then pass "$1 available"; else fail "$1 missing"; fi; }

for cmd in php nginx mariadb aria2c ffmpeg mediainfo yt-dlp; do check_cmd "$cmd"; done
if php -r 'exit(version_compare(PHP_VERSION,"8.3.0",">=")?0:1);'; then pass "PHP >= 8.3"; else fail "PHP < 8.3"; fi
if find "$INSTALL_DIR" -maxdepth 1 -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null; then pass "PHP lint"; else fail "PHP lint"; fi
if nginx -t >/dev/null 2>&1; then pass "Nginx config"; else fail "Nginx config"; fi
if [[ -w "$INSTALL_DIR/storage/media" ]]; then pass "Media storage writable"; else fail "Media storage not writable"; fi
if [[ -f "$INSTALL_DIR/config.php" ]]; then
  # shellcheck disable=SC2016
  if php -r '$c=require $argv[1];$d=$c["db"];$p=new PDO("mysql:host={$d["host"]};dbname={$d["name"]};charset=utf8mb4",$d["user"],$d["pass"]);foreach(["media_jobs","media_workers","media_job_events"] as $t){$p->query("SELECT 1 FROM `$t` LIMIT 1");}' "$INSTALL_DIR/config.php"; then pass "Database and Media schema"; else fail "Database or Media schema"; fi
  if systemctl is-active --quiet 'freebot-download@1.service'; then pass "Download worker"; else fail "Download worker inactive"; fi
  if systemctl is-active --quiet 'freebot-upload@1.service'; then pass "Upload worker"; else fail "Upload worker inactive"; fi
else
  echo "INFO  Web installer is not completed; database and worker runtime checks skipped."
fi
((FAILURES==0)) || exit 1
echo "FreeBot healthcheck passed."
