#!/usr/bin/env bash
# Install JaySub 3x-ui panel DB backup worker (every 4 hours). Run as root on the VPS.
set -euo pipefail

INSTALL_DIR="${JAYSUB_INSTALL_DIR:-/var/www/vpn-panel}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
LOG_FILE="${INSTALL_DIR}/logs/xui-backup.log"

if [[ ! -f "${INSTALL_DIR}/worker/xui_backup_worker.php" ]]; then
  echo "Not found: ${INSTALL_DIR}/worker/xui_backup_worker.php"
  exit 1
fi

mkdir -p "${INSTALL_DIR}/logs" "${INSTALL_DIR}/storage/backups/xui"
chown -R www-data:www-data "${INSTALL_DIR}/logs" "${INSTALL_DIR}/storage/backups" 2>/dev/null || true

CRON_LINE="0 */4 * * * cd ${INSTALL_DIR} && ${PHP_BIN} worker/xui_backup_worker.php >> ${LOG_FILE} 2>&1"

( crontab -l 2>/dev/null | grep -v "xui_backup_worker.php" || true; echo "$CRON_LINE" ) | crontab -

echo "Cron installed:"
echo "  $CRON_LINE"
echo ""
echo "Test once:"
echo "  cd ${INSTALL_DIR} && ${PHP_BIN} worker/xui_backup_worker.php"
echo "  tail -10 ${LOG_FILE}"
