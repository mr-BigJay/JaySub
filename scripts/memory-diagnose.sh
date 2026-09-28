#!/usr/bin/env bash
# JaySub — RAM usage on small VPS (e.g. 1 GB). Run as root on the server.
set -euo pipefail

INSTALL_DIR="${JAYSUB_INSTALL_DIR:-/var/www/vpn-panel}"

echo "=== Memory (JaySub diagnose) ==="
free -h
echo ""

echo "--- Top processes by RAM ---"
ps aux --sort=-%mem 2>/dev/null | head -15 || ps -eo pid,user,%mem,rss,args --sort=-%mem | head -15
echo ""

echo "--- PHP processes (count / RSS sum) ---"
PHP_LINES=$(pgrep -af 'php' 2>/dev/null || true)
if [[ -z "$PHP_LINES" ]]; then
  echo "  no php processes"
else
  echo "$PHP_LINES" | head -20
  echo "  count: $(echo "$PHP_LINES" | wc -l)"
fi
echo ""

echo "--- JaySub workers / cron ---"
crontab -l 2>/dev/null | grep -E 'traffic_worker|xui_backup|ssl_backup|migration-run' || echo "  (no JaySub lines in root crontab)"
echo ""

if [[ -d "$INSTALL_DIR" ]]; then
  for lock in worker.lock xui_backup.lock ssl_backup.lock; do
    p="$INSTALL_DIR/storage/$lock"
    if [[ -f "$p" ]]; then
      echo "  lock $lock: present (worker may be running)"
    fi
  done
  if [[ -d "$INSTALL_DIR/storage/migrations" ]]; then
    JOBS=$(find "$INSTALL_DIR/storage/migrations" -name '*.json' 2>/dev/null | wc -l)
    echo "  migration job files: $JOBS"
  fi
fi
echo ""

echo "--- Suggestions (1 GB RAM) ---"
cat <<'TXT'
1) MySQL often uses 300–500 MB by default — tune /etc/mysql/mariadb.conf.d/50-server.cnf:
     innodb_buffer_pool_size = 128M
     performance_schema = OFF
   then: systemctl restart mariadb

2) PHP-FPM — in pool www.conf reduce concurrency:
     pm = ondemand
     pm.max_children = 4
     pm.process_idle_timeout = 10s
   then: systemctl restart php8.3-fpm

3) JaySub traffic sync every minute × many panels = extra PHP RAM.
   Change cron to every 3 minutes:
     bash scripts/install-worker-cron-lowmem.sh

4) Add 1G swap if none: fallocate -l 1G /swapfile && chmod 600 /swapfile && mkswap /swapfile && swapon /swapfile

5) Kill stuck migration: pkill -f 'migration-run.php'  (only if no transfer is running)
TXT
