#!/usr/bin/env bash
set -Eeuo pipefail

INSTALL_DIR="${FREEBOT_INSTALL_DIR:-/var/www/freebot}"
CONFIG_DIR="/etc/freebot"
CONFIG_FILE="$CONFIG_DIR/channel-scanner.env"
SESSION_DIR="/var/lib/freebot-mtproto"
SESSION_PATH="$SESSION_DIR/freebot"

[[ ${EUID} -eq 0 ]] || { echo "Run as root (sudo)." >&2; exit 1; }
[[ -f "$INSTALL_DIR/scripts/channel_history_scan.py" ]] || { echo "FreeBot scanner script not found. Update FreeBot first." >&2; exit 1; }

read -r -p "Telegram API ID (my.telegram.org): " API_ID
read -r -s -p "Telegram API Hash: " API_HASH
printf '\n'
read -r -p "Phone number with country code (example +49123...): " PHONE
[[ "$API_ID" =~ ^[0-9]+$ ]] || { echo "Invalid API ID." >&2; exit 1; }
[[ "$API_HASH" =~ ^[A-Fa-f0-9]{20,64}$ ]] || { echo "Invalid API Hash." >&2; exit 1; }
[[ "$PHONE" =~ ^\+[0-9]{7,18}$ ]] || { echo "Invalid phone number." >&2; exit 1; }

if [[ ! -x /opt/freebot-tools/bin/pip ]]; then python3 -m venv /opt/freebot-tools; fi
/opt/freebot-tools/bin/pip install --disable-pip-version-check --upgrade 'Telethon>=1.36,<2'
install -d -o root -g www-data -m 0750 "$CONFIG_DIR"
install -d -o www-data -g www-data -m 0700 "$SESSION_DIR"
umask 0027
{
  printf 'TELEGRAM_API_ID=%s\n' "$API_ID"
  printf 'TELEGRAM_API_HASH=%s\n' "$API_HASH"
  printf 'TELEGRAM_PHONE=%s\n' "$PHONE"
  printf 'TELEGRAM_SESSION=%s\n' "$SESSION_PATH"
} > "$CONFIG_FILE"
chown root:www-data "$CONFIG_FILE"
chmod 0640 "$CONFIG_FILE"

echo "Telegram will send a login code. Enter it below; a 2FA password may also be requested."
runuser -u www-data -- /opt/freebot-tools/bin/python "$INSTALL_DIR/scripts/channel_history_scan.py" --config "$CONFIG_FILE" --session "$SESSION_PATH" --login-only
find "$SESSION_DIR" -type f -exec chmod 0600 {} +
echo "Historical channel scanner is configured."
