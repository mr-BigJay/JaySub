#!/usr/bin/env bash
set -euo pipefail
INSTALL_DIR="${JAYSUB_INSTALL_DIR:-/var/www/vpn-panel}"
ADMIN_PASS="${1:?ramz admin lazem ast}"
mkdir -p "${INSTALL_DIR}/storage/sessions"
echo -n "${ADMIN_PASS}" > "${INSTALL_DIR}/storage/.admin-init"
chmod 600 "${INSTALL_DIR}/storage/.admin-init"
php "${INSTALL_DIR}/scripts/install.php" "${ADMIN_PASS}"
php "${INSTALL_DIR}/scripts/verify-database.php"
echo "OK"
