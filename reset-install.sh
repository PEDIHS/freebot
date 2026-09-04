#!/usr/bin/env bash
set -Eeuo pipefail

INSTALL_DIR="${FREEBOT_INSTALL_DIR:-/var/www/freebot}"
ASSUME_YES=0
while (($#)); do case "$1" in --install-dir) INSTALL_DIR="${2:-}"; shift 2;; --yes) ASSUME_YES=1; shift;; *) echo "Unknown option: $1" >&2; exit 2;; esac; done
[[ ${EUID} -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
[[ "$INSTALL_DIR" == /var/www/* && "$INSTALL_DIR" != /var/www ]] || { echo "Refusing unsafe path: $INSTALL_DIR" >&2; exit 1; }
if [[ $ASSUME_YES -ne 1 ]]; then read -r -p "Archive the current install at $INSTALL_DIR and reset services? Type RESET: " answer; [[ "$answer" == RESET ]] || exit 1; fi

systemctl disable --now 'freebot-download@*.service' 'freebot-upload@*.service' 2>/dev/null || true
BACKUP_ROOT="/var/backups/freebot"
install -d -m 0700 "$BACKUP_ROOT"
if [[ -d "$INSTALL_DIR" ]]; then BACKUP_DIR="$BACKUP_ROOT/previous-$(date -u +%Y%m%dT%H%M%SZ)"; mv "$INSTALL_DIR" "$BACKUP_DIR"; echo "Previous files archived at $BACKUP_DIR"; fi
rm -f /etc/systemd/system/freebot-download@.service /etc/systemd/system/freebot-upload@.service /etc/cron.d/freebot
rm -f /etc/nginx/sites-enabled/freebot /etc/nginx/sites-available/freebot
systemctl daemon-reload
if nginx -t; then systemctl reload nginx; fi
echo "Reset complete. The database was not deleted."
