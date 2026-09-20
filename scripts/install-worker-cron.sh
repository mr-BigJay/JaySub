#!/usr/bin/env bash
# Install JaySub traffic worker (every minute). Run as root on the VPS.
set -euo pipefail

INSTALL_DIR="${JAYSUB_INSTALL_DIR:-/var/www/vpn-panel}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
LOG_FILE="${INSTALL_DIR}/logs/worker.log"

if [[ ! -f "${INSTALL_DIR}/worker/traffic_worker.php" ]]; then
  echo "Not found: ${INSTALL_DIR}/worker/traffic_worker.php"
  exit 1
fi

mkdir -p "${INSTALL_DIR}/logs"
chown www-data:www-data "${INSTALL_DIR}/logs" 2>/dev/null || true

CRON_LINE="* * * * * cd ${INSTALL_DIR} && ${PHP_BIN} worker/traffic_worker.php >> ${LOG_FILE} 2>&1"

( crontab -l 2>/dev/null | grep -v "traffic_worker.php" || true; echo "$CRON_LINE" ) | crontab -

echo "Cron installed:"
echo "  $CRON_LINE"
echo ""
echo "Test once:"
echo "  cd ${INSTALL_DIR} && ${PHP_BIN} worker/traffic_worker.php"
echo "  tail -5 ${LOG_FILE}"
