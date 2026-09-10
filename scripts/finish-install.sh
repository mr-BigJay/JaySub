#!/usr/bin/env bash
# Tamam kardan-e nasb bad az khata (masalan MySQL Access denied)
set -euo pipefail

INSTALL_DIR="${INSTALL_DIR:-/var/www/vpn-panel}"
CONFIG="${INSTALL_DIR}/config/config.php"

if [[ ! -f "$CONFIG" ]]; then
  echo "Khata: $CONFIG peyda nashod. aval deploy ro ejra konid."
  exit 1
fi

DB_PASS=$(php -r "\$c=require '$CONFIG'; echo \$c['database']['password'];")
ADMIN_PASS="${1:-}"

if [[ -z "$ADMIN_PASS" ]]; then
  read_tty_secret -p "Ramz-e admin (ya Enter baraye Admin@12345): " ADMIN_PASS </dev/tty 2>/dev/null || read -rsp "Ramz-e admin: " ADMIN_PASS
  echo ""
  [[ -z "$ADMIN_PASS" ]] && ADMIN_PASS='Admin@12345'
fi

DB_PASS_SQL="${DB_PASS//\'/\'\'}"
echo "==> Sync MySQL user vpn_panel..."
mysql -e "CREATE DATABASE IF NOT EXISTS vpn_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -e "CREATE USER IF NOT EXISTS 'vpn_panel'@'localhost' IDENTIFIED BY '${DB_PASS_SQL}';"
mysql -e "ALTER USER 'vpn_panel'@'localhost' IDENTIFIED BY '${DB_PASS_SQL}';"
mysql -e "GRANT ALL PRIVILEGES ON vpn_panel.* TO 'vpn_panel'@'localhost';"
mysql -e "FLUSH PRIVILEGES;"

echo "==> Database schema + admin..."
php "${INSTALL_DIR}/scripts/install.php" "$ADMIN_PASS"

DOMAIN=$(php -r "\$c=require '$CONFIG'; echo parse_url(\$c['app']['url'], PHP_URL_HOST) ?: '';")
APP_URL=$(php -r "\$c=require '$CONFIG'; echo \$c['app']['url'];")

if [[ -f /etc/nginx/sites-available/jaysub-vpn-panel ]]; then
  echo "==> Reload nginx..."
  nginx -t && systemctl reload nginx
else
  echo "Note: nginx config peyda nashod — deploy.sh ro dobare ta payan ejra konid."
fi

CRED="${INSTALL_DIR}/storage/install-credentials.txt"
mkdir -p "${INSTALL_DIR}/storage"
cat > "$CRED" <<CREDS
JaySub install summary (finish-install)
Domain: ${DOMAIN}
Admin URL: ${APP_URL}/admin/login
Customer URL: ${APP_URL}/login
Admin user: admin
Admin password: ${ADMIN_PASS}
MySQL user: vpn_panel
MySQL password: ${DB_PASS}
CREDS
chmod 600 "$CRED"

echo ""
echo "=============================================="
echo " JaySub install finished"
echo "=============================================="
echo " Panel:    ${APP_URL}/admin/login"
echo " Customer: ${APP_URL}/login"
echo " Admin:    admin / ${ADMIN_PASS}"
echo " Credentials: ${CRED}"
echo "=============================================="
