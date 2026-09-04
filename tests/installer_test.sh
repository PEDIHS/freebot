#!/usr/bin/env bash
set -Eeuo pipefail
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
bash -n "$ROOT/install.sh" "$ROOT/update.sh" "$ROOT/reset-install.sh" "$ROOT/healthcheck.sh"
shellcheck -x "$ROOT/install.sh" "$ROOT/update.sh" "$ROOT/reset-install.sh" "$ROOT/healthcheck.sh"
test ! -e "$ROOT/config.php"
test -e "$ROOT/config.example.php"
test -e "$ROOT/worker.php"
! find "$ROOT" -maxdepth 1 -type f -name '*.zip' | grep -q .
! grep -RIE 'wireguard|v2ray|xray|3x-ui|wizwiz|mirzabot|vpn proxy' "$ROOT" --exclude-dir=.git --exclude='*.md' --exclude='installer_test.sh'
grep -q 'php8.3-fpm' "$ROOT/install.sh"
grep -q 'freebot-download@' "$ROOT/install.sh"
grep -q 'freebot-upload@' "$ROOT/install.sh"
grep -q 'certbot --nginx' "$ROOT/install.sh"
echo "Installer test passed."
