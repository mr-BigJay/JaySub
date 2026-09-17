#!/usr/bin/env bash
# Install JaySub weekly SSL cert backup worker (Saturdays 03:15 Tehran ≈ cron on server local time).
set -euo pipefail

INSTALL_DIR="${JAYSUB_INSTALL_DIR:-/var/www/vpn-panel}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
LOG_FILE="${INSTALL_DIR}/logs/ssl-backup.log"

if [[ ! -f "${INSTALL_DIR}/worker/ssl_backup_worker.php" ]]; then
  echo "Not found: ${INSTALL_DIR}/worker/ssl_backup_worker.php"
  exit 1
fi

mkdir -p "${INSTALL_DIR}/logs" "${INSTALL_DIR}/storage/backups/ssl" "${INSTALL_DIR}/storage/ssl-ssh"
touch "${INSTALL_DIR}/storage/ssl-ssh/known_hosts"
chmod 700 "${INSTALL_DIR}/storage/ssl-ssh"
chmod 600 "${INSTALL_DIR}/storage/ssl-ssh/known_hosts" 2>/dev/null || true
chown -R www-data:www-data "${INSTALL_DIR}/logs" "${INSTALL_DIR}/storage/backups" "${INSTALL_DIR}/storage/ssl-ssh" 2>/dev/null || true

# Weekly: Saturday 03:15 (adjust TZ on VPS if you want exact Asia/Tehran)
CRON_LINE="15 3 * * 6 cd ${INSTALL_DIR} && ${PHP_BIN} worker/ssl_backup_worker.php >> ${LOG_FILE} 2>&1"

( crontab -l 2>/dev/null | grep -v "ssl_backup_worker.php" || true; echo "$CRON_LINE" ) | crontab -

echo "Cron installed:"
echo "  $CRON_LINE"
echo ""
echo "On JaySub host for password SSH: apt install -y sshpass"
echo "On each remote server (recommended): apt install -y zip"
echo "If zip is missing, JaySub falls back to tar.gz over SSH."
echo ""
echo "Test once:"
echo "  cd ${INSTALL_DIR} && ${PHP_BIN} worker/ssl_backup_worker.php"
echo "  tail -10 ${LOG_FILE}"
