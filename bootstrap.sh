#!/usr/bin/env bash
set -Eeuo pipefail

REPO_URL="${REPO_URL:-https://github.com/PEDIHS/freebot.git}"
BRANCH="${BRANCH:-main}"
log(){ printf '\n[freebot-bootstrap] %s\n' "$*"; }
fail(){ printf '\n[freebot-bootstrap] ERROR: %s\n' "$*" >&2; exit 1; }

[[ "$EUID" -eq 0 ]] || fail "با root اجرا کن."
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y git ca-certificates curl

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || true)"
REPO_DIR=""
TMP=""

if [[ -n "$SCRIPT_DIR" && -f "$SCRIPT_DIR/install.sh" && -f "$SCRIPT_DIR/healthcheck.sh" ]]; then
  REPO_DIR="$SCRIPT_DIR"
  log "استفاده از clone محلی موجود"
else
  TMP="$(mktemp -d)"
  trap '[[ -z "${TMP:-}" ]] || rm -rf "$TMP"' EXIT
  log "Clone repository"
  git -c http.version=HTTP/1.1 clone --depth 1 --single-branch --branch "$BRANCH" "$REPO_URL" "$TMP/repo" \
    || fail "git clone ناموفق بود."
  REPO_DIR="$TMP/repo"
fi

log "اجرای Installer محلی"
bash "$REPO_DIR/install.sh"
install -m 0755 "$REPO_DIR/healthcheck.sh" /usr/local/sbin/freebot-health

printf '\nدستورات مدیریت:\n'
printf '  freebot-health\n'
printf '  freebot-update\n'
printf '  systemctl status freebot-media --no-pager\n'