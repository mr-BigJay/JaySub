#!/usr/bin/env bash
set -euo pipefail

INSTALL_DIR="${JAYSUB_INSTALL_DIR:-/var/www/vpn-panel}"
ADMIN_PASS="${1:-}"

if [[ -z "$ADMIN_PASS" ]]; then
  read -rsp "Ramz-e admin panel: " ADMIN_PASS </dev/tty
  echo ""
fi

CONFIG="${INSTALL_DIR}/config/config.php"
SCHEMA="${INSTALL_DIR}/database/schema.sql"

if [[ ! -f "$CONFIG" ]]; then
  echo "Khata: $CONFIG peyda nashod."
  exit 1
fi
if [[ ! -f "$SCHEMA" ]]; then
  echo "Khata: schema.sql peyda nashod. cd $INSTALL_DIR && git pull"
  exit 1
fi

DB_PASS=$(php -r "\$c=require '$CONFIG'; echo \$c['database']['password'];")
DB_PASS_SQL="${DB_PASS//\'/\'\'}"

echo "==> MySQL: database + user vpn_panel..."
if ! command -v mysql &>/dev/null; then
  echo "Khata: mysql CLI peyda nashod."
  exit 1
fi

mysql -e "CREATE DATABASE IF NOT EXISTS vpn_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS 'vpn_panel'@'localhost' IDENTIFIED BY '${DB_PASS_SQL}';"
mysql -e "ALTER USER 'vpn_panel'@'localhost' IDENTIFIED BY '${DB_PASS_SQL}';"
mysql -e "GRANT ALL PRIVILEGES ON vpn_panel.* TO 'vpn_panel'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

echo "==> Import schema.sql (root)..."
mysql < "$SCHEMA"

echo "==> install.php + admin user..."
php "${INSTALL_DIR}/scripts/install.php" "$ADMIN_PASS"

echo "==> Verify..."
php "${INSTALL_DIR}/scripts/verify-database.php"

echo "OK — database amade ast."
