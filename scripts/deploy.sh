#!/usr/bin/env bash
# JaySub installer — Ubuntu/Debian VPS (interactive or flags)
# Interactive one-line (recommended):
#   sudo bash -c "$(curl -fsSL https://raw.githubusercontent.com/mr-BigJay/JaySub/cursor/vpn-customer-panel-6abb/scripts/deploy.sh)"
set -euo pipefail

# Read from terminal even when script is piped to bash
read_tty() {
  if [[ -r /dev/tty ]]; then
    read -r "$@" </dev/tty
  else
    read -r "$@"
  fi
}

read_tty_secret() {
  if [[ -r /dev/tty ]]; then
    read -r -s "$@" </dev/tty
  else
    read -r -s "$@"
  fi
  echo ""
}

REPO_URL="${REPO_URL:-https://github.com/mr-BigJay/JaySub.git}"
GIT_BRANCH="${GIT_BRANCH:-cursor/vpn-customer-panel-6abb}"
INSTALL_DIR="${INSTALL_DIR:-/var/www/vpn-panel}"
DOMAIN=""
ADMIN_PASS=""
DB_PASS=""
CERTBOT_EMAIL=""
SKIP_SSL=0
SKIP_MYSQL_INSTALL=0

usage() {
  cat <<'EOF'
JaySub automatic installer

Required:
  -d, --domain          Domain for the panel (e.g. panel.example.com)

Recommended:
  -a, --admin-pass      Admin panel password (default: random)
  -p, --db-pass         MySQL password for vpn_panel user (default: random)
  -e, --email           Email for Let's Encrypt (enables HTTPS if set)

Optional:
  --branch NAME         Git branch (default: cursor/vpn-customer-panel-6abb)
  --dir PATH            Install path (default: /var/www/vpn-panel)
  --skip-ssl            Do not run certbot
  --skip-mysql-install  Assume MySQL already installed

Interactive install (asks domain, passwords, SSL):
  sudo bash -c "\$(curl -fsSL https://raw.githubusercontent.com/mr-BigJay/JaySub/cursor/vpn-customer-panel-6abb/scripts/deploy.sh)"

Non-interactive:
  sudo bash -c "\$(curl -fsSL .../deploy.sh)" -s -- -d panel.example.com -a 'StrongPass123!' -e you@example.com
EOF
}

interactive_wizard() {
  echo ""
  echo "=============================================="
  echo "  نصب خودکار پنل JaySub (VPN / 3X-UI)"
  echo "=============================================="
  echo ""

  while [[ -z "$DOMAIN" ]]; do
    read_tty -rp "دامنه پنل (مثال: panel.example.com): " DOMAIN
    DOMAIN="$(echo "$DOMAIN" | tr -d '[:space:]')"
    if [[ -z "$DOMAIN" ]]; then
      echo "دامنه الزامی است."
    fi
  done

  while [[ -z "$ADMIN_PASS" ]]; do
    read_tty_secret -p "رمز ورود ادمین پنل (حداقل ۸ کاراکتر): " ADMIN_PASS
    if [[ ${#ADMIN_PASS} -lt 8 ]]; then
      echo "رمز باید حداقل ۸ کاراکتر باشد."
      ADMIN_PASS=""
      continue
    fi
    local confirm=""
    read_tty_secret -p "تکرار رمز ادمین: " confirm
    if [[ "$ADMIN_PASS" != "$confirm" ]]; then
      echo "رمزها یکسان نیستند."
      ADMIN_PASS=""
    fi
  done

  echo ""
  read_tty -rp "رمز دیتابیس MySQL را خودتان وارد می‌کنید؟ (y/N — در غیر این صورت تصادفی): " db_custom
  if [[ "$db_custom" =~ ^[Yy]$ ]]; then
    while [[ -z "$DB_PASS" ]]; do
      read_tty_secret -p "رمز MySQL برای کاربر vpn_panel: " DB_PASS
      if [[ ${#DB_PASS} -lt 8 ]]; then
        echo "حداقل ۸ کاراکتر."
        DB_PASS=""
      fi
    done
  else
    DB_PASS="$(rand_pass)"
    echo "رمز MySQL به‌صورت تصادفی ساخته شد (در پایان نمایش داده می‌شود)."
  fi

  echo ""
  read_tty -rp "فعال‌سازی HTTPS با Let's Encrypt؟ (Y/n): " ssl_yn
  if [[ ! "$ssl_yn" =~ ^[Nn]$ ]]; then
    SKIP_SSL=0
    while [[ -z "$CERTBOT_EMAIL" ]]; do
      read_tty -rp "ایمیل برای گواهی SSL: " CERTBOT_EMAIL
      CERTBOT_EMAIL="$(echo "$CERTBOT_EMAIL" | tr -d '[:space:]')"
    done
  else
    SKIP_SSL=1
    CERTBOT_EMAIL=""
    echo "نصب فقط روی HTTP (پورت ۸۰)."
  fi

  echo ""
  echo "-------------- خلاصه --------------"
  echo "دامنه:        $DOMAIN"
  echo "مسیر نصب:     $INSTALL_DIR"
  echo "SSL:          $([[ $SKIP_SSL -eq 0 ]] && echo "بله ($CERTBOT_EMAIL)" || echo "خیر")"
  echo "-----------------------------------"
  read_tty -rp "شروع نصب؟ (Y/n): " go
  if [[ "$go" =~ ^[Nn]$ ]]; then
    echo "لغو شد."
    exit 0
  fi
  echo ""
}

rand_pass() {
  openssl rand -base64 24 | tr -dc 'A-Za-z0-9' | head -c 20
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    -d|--domain) DOMAIN="$2"; shift 2 ;;
    -a|--admin-pass) ADMIN_PASS="$2"; shift 2 ;;
    -p|--db-pass) DB_PASS="$2"; shift 2 ;;
    -e|--email) CERTBOT_EMAIL="$2"; shift 2 ;;
    --branch) GIT_BRANCH="$2"; shift 2 ;;
    --dir) INSTALL_DIR="$2"; shift 2 ;;
    --skip-ssl) SKIP_SSL=1; shift ;;
    --skip-mysql-install) SKIP_MYSQL_INSTALL=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1"; usage; exit 1 ;;
  esac
done

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Please run as root: sudo bash deploy.sh ..."
  exit 1
fi

if [[ -z "$DOMAIN" ]]; then
  interactive_wizard
else
  [[ -z "$ADMIN_PASS" ]] && ADMIN_PASS="$(rand_pass)"
  [[ -z "$DB_PASS" ]] && DB_PASS="$(rand_pass)"
fi

export DEBIAN_FRONTEND=noninteractive

echo "==> Installing system packages..."
apt-get update -qq
PKGS=(nginx curl git unzip ca-certificates)
if [[ "$SKIP_MYSQL_INSTALL" -eq 0 ]]; then
  PKGS+=(mysql-server)
fi

PHP_VER=""
for v in 8.3 8.2 8.1; do
  if apt-cache show "php${v}-fpm" &>/dev/null; then
    PHP_VER="$v"
    break
  fi
done
if [[ -z "$PHP_VER" ]]; then
  echo "No supported PHP-FPM (8.1+) found in apt."
  exit 1
fi

PKGS+=("php${PHP_VER}-fpm" "php${PHP_VER}-mysql" "php${PHP_VER}-curl" "php${PHP_VER}-mbstring" "php${PHP_VER}-xml" "php${PHP_VER}-cli")
apt-get install -y -qq "${PKGS[@]}"

if ! command -v composer &>/dev/null; then
  echo "==> Installing Composer..."
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
fi

if [[ "$SKIP_MYSQL_INSTALL" -eq 0 ]]; then
  echo "==> Configuring MySQL database..."
  systemctl enable --now mysql 2>/dev/null || systemctl enable --now mariadb 2>/dev/null || true
  mysql -e "CREATE DATABASE IF NOT EXISTS vpn_panel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  mysql -e "CREATE USER IF NOT EXISTS 'vpn_panel'@'localhost' IDENTIFIED BY '${DB_PASS}';"
  mysql -e "GRANT ALL PRIVILEGES ON vpn_panel.* TO 'vpn_panel'@'localhost';"
  mysql -e "FLUSH PRIVILEGES;"
fi

echo "==> Deploying application to ${INSTALL_DIR}..."
mkdir -p "$(dirname "$INSTALL_DIR")"
if [[ -d "${INSTALL_DIR}/.git" ]]; then
  cd "$INSTALL_DIR"
  git fetch origin "$GIT_BRANCH" 2>/dev/null || git fetch origin
  git checkout "$GIT_BRANCH" 2>/dev/null || git checkout -B "$GIT_BRANCH" "origin/${GIT_BRANCH}" 2>/dev/null || true
  git pull origin "$GIT_BRANCH" 2>/dev/null || true
else
  rm -rf "$INSTALL_DIR"
  git clone --branch "$GIT_BRANCH" --depth 1 "$REPO_URL" "$INSTALL_DIR"
  cd "$INSTALL_DIR"
fi

composer install --no-dev --optimize-autoloader --no-interaction 2>/dev/null || true

ENC_KEY="$(openssl rand -base64 32)"
APP_URL="http://${DOMAIN}"
if [[ -n "$CERTBOT_EMAIL" && "$SKIP_SSL" -eq 0 ]]; then
  APP_URL="https://${DOMAIN}"
fi

cat > "${INSTALL_DIR}/config/config.php" <<PHP
<?php
declare(strict_types=1);
return [
    'app' => [
        'name' => 'پنل مدیریت VPN',
        'url' => '${APP_URL}',
        'timezone' => 'Asia/Tehran',
        'debug' => false,
    ],
    'database' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'vpn_panel',
        'user' => 'vpn_panel',
        'password' => '${DB_PASS}',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name' => 'VPN_PANEL_SESS',
        'csrf_token_key' => 'csrf_token',
        'encryption_key' => '${ENC_KEY}',
        'login_max_attempts' => 5,
        'login_lockout_minutes' => 15,
    ],
    'worker' => [
        'lock_file' => __DIR__ . '/../storage/worker.lock',
        'interval_seconds' => 60,
    ],
    'paths' => [
        'storage' => __DIR__ . '/../storage',
        'logs' => __DIR__ . '/../logs',
    ],
];
PHP

php "${INSTALL_DIR}/scripts/install.php" "${ADMIN_PASS}"

WEB_USER="www-data"
if id nginx &>/dev/null; then
  WEB_USER="nginx"
fi
chown -R "${WEB_USER}:${WEB_USER}" "${INSTALL_DIR}/storage" "${INSTALL_DIR}/logs"
chmod 750 "${INSTALL_DIR}/storage" "${INSTALL_DIR}/logs"

PHP_SOCK="/run/php/php${PHP_VER}-fpm.sock"
if [[ ! -S "$PHP_SOCK" ]]; then
  PHP_SOCK="/var/run/php/php${PHP_VER}-fpm.sock"
fi

echo "==> Configuring Nginx..."
cat > "/etc/nginx/sites-available/jaysub-vpn-panel" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name ${DOMAIN};
    root ${INSTALL_DIR}/public;
    index index.php;

    client_max_body_size 4m;

    location /assets/ {
        try_files \$uri =404;
        expires 7d;
    }

    location / {
        try_files \$uri /index.php?\$query_string;
    }

    location ~ \\.php\$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
        fastcgi_pass unix:${PHP_SOCK};
    }

    location ~ /\\. {
        deny all;
    }
}
NGINX

ln -sf /etc/nginx/sites-available/jaysub-vpn-panel /etc/nginx/sites-enabled/jaysub-vpn-panel
rm -f /etc/nginx/sites-enabled/default 2>/dev/null || true
nginx -t
systemctl reload nginx
systemctl enable --now "php${PHP_VER}-fpm" nginx

echo "==> Installing cron worker..."
CRON_LINE="* * * * * /usr/bin/php ${INSTALL_DIR}/worker/traffic_worker.php >> ${INSTALL_DIR}/logs/worker.log 2>&1"
( crontab -l 2>/dev/null | grep -v "traffic_worker.php" || true; echo "$CRON_LINE" ) | crontab -

if [[ -n "$CERTBOT_EMAIL" && "$SKIP_SSL" -eq 0 ]]; then
  echo "==> HTTPS with Let's Encrypt..."
  apt-get install -y -qq certbot python3-certbot-nginx
  certbot --nginx -d "$DOMAIN" --non-interactive --agree-tos -m "$CERTBOT_EMAIL" --redirect || echo "Certbot failed — configure SSL manually."
fi

CREDENTIALS_FILE="${INSTALL_DIR}/storage/install-credentials.txt"
cat > "$CREDENTIALS_FILE" <<CREDS
JaySub install summary ($(date -Iseconds))
Domain: ${DOMAIN}
Admin URL: ${APP_URL}/admin/login
Admin user: admin
Admin password: ${ADMIN_PASS}
MySQL user: vpn_panel
MySQL password: ${DB_PASS}
CREDS
chmod 600 "$CREDENTIALS_FILE"
chown "${WEB_USER}:${WEB_USER}" "$CREDENTIALS_FILE"

echo ""
echo "=============================================="
echo " JaySub installed successfully"
echo "=============================================="
echo " Panel:    ${APP_URL}/admin/login"
echo " Customer: ${APP_URL}/login"
echo " Admin:    admin / ${ADMIN_PASS}"
echo " Credentials saved: ${CREDENTIALS_FILE}"
echo "=============================================="
