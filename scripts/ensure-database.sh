#!/usr/bin/env bash
# Tamam kardan / repair database + admin user
set -euo pipefail

INSTALL_DIR="${JAYSUB_INSTALL_DIR:-/var/www/vpn-panel}"
ADMIN_PASS="${1:-}"

if [[ -z "$ADMIN_PASS" ]]; then
  read -rsp "Ramz-e admin panel: " ADMIN_PASS </dev/tty
  echo ""
fi

if [[ ! -f "${INSTALL_DIR}/config/config.php" ]]; then
  echo "Khata: ${INSTALL_DIR}/config/config.php peyda nashod."
  exit 1
fi

echo "==> Import schema (root mysql)..."
if command -v mysql &>/dev/null; then
  mysql < "${INSTALL_DIR}/database/schema.sql" 2>/dev/null || true
fi

echo "==> install.php..."
php "${INSTALL_DIR}/scripts/install.php" "${ADMIN_PASS}"

if ! php "${INSTALL_DIR}/scripts/verify-database.php"; then
  echo "==> Retry schema..."
  mysql < "${INSTALL_DIR}/database/schema.sql" 2>/dev/null || true
  php "${INSTALL_DIR}/scripts/install.php" "${ADMIN_PASS}"
  php "${INSTALL_DIR}/scripts/verify-database.php"
fi

echo "Database amade ast."
