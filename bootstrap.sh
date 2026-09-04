#!/usr/bin/env bash
set -Eeuo pipefail
REPO_URL="${REPO_URL:-https://github.com/PEDIHS/freebot.git}"
BRANCH="${BRANCH:-main}"
log(){ printf '\n[freebot-bootstrap] %s\n' "$*"; }
[[ "$EUID" -eq 0 ]] || { echo 'با root اجرا کن.' >&2; exit 1; }
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y git ca-certificates curl
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
log "Clone repository"
git -c http.version=HTTP/1.1 clone --depth 1 --single-branch --branch "$BRANCH" "$REPO_URL" "$TMP/repo"
log "اجرای Installer محلی"
bash "$TMP/repo/install.sh"
install -m 0755 "$TMP/repo/healthcheck.sh" /usr/local/sbin/freebot-health
printf '\nدستورات: freebot-health | freebot-update | systemctl status freebot-media --no-pager\n'
