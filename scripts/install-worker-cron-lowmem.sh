#!/usr/bin/env bash
# Traffic sync every 3 minutes (lighter on 1 GB RAM than every minute).
set -euo pipefail

INSTALL_DIR="${JAYSUB_INSTALL_DIR:-/var/www/vpn-panel}"
PHP_BIN="${PHP_BIN:-/usr/bin/php}"
LOG_FILE="${INSTALL_DIR}/logs/worker.log"
INTERVAL="${JAYSUB_WORKER_EVERY_MIN:-3}"

if [[ ! -f "${INSTALL_DIR}/worker/traffic_worker.php" ]]; then
  echo "Not found: ${INSTALL_DIR}/worker/traffic_worker.php"
  exit 1
fi

mkdir -p "${INSTALL_DIR}/logs"

CRON_LINE="*/${INTERVAL} * * * * cd ${INSTALL_DIR} && ${PHP_BIN} worker/traffic_worker.php >> ${LOG_FILE} 2>&1"

( crontab -l 2>/dev/null | grep -v "traffic_worker.php" || true; echo "$CRON_LINE" ) | crontab -

echo "Installed low-memory traffic cron (every ${INTERVAL} min):"
echo "  $CRON_LINE"
