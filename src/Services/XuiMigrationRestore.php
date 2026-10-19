<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Remote shell workflow to restore a JaySub 3x-ui SQLite backup (.db) without
 * touching the panel binary or systemd unit. Includes rollback on failure.
 */
final class XuiMigrationRestore
{
    /** SQLite file header magic (15 bytes). */
    public const SQLITE_MAGIC = 'SQLite format 3';

    /**
     * Validate a remote backup path under /tmp (JaySub upload naming).
     */
    public static function isValidRemoteBackupPath(string $path): bool
    {
        return (bool) preg_match(
            '~\A/tmp/jaysub-restore-[A-Za-z0-9._-]+\.db\z~D',
            $path
        );
    }

    public static function remoteBackupPath(string $basename): string
    {
        $base = basename($basename);
        if (!preg_match('/\A[A-Za-z0-9._-]+\.db\z/', $base)) {
            throw new \InvalidArgumentException('Invalid JaySub backup filename for remote restore');
        }

        return '/tmp/jaysub-restore-' . $base;
    }

    /**
     * Bash script executed on the destination VPS (root).
     */
    public static function remoteRestoreScript(string $remoteBackupPath): string
    {
        if (!self::isValidRemoteBackupPath($remoteBackupPath)) {
            throw new \InvalidArgumentException('Unexpected remote x-ui backup path');
        }

        $script = <<<'BASH'
set -euo pipefail
RESTORE=__RESTORE__
XUI_BIN=/usr/local/x-ui/x-ui
DB=/etc/x-ui/x-ui.db
[ "$(id -u)" -eq 0 ] || { echo "Restore requires root" >&2; exit 1; }
[ -f "$RESTORE" ] || { echo "Backup file missing on destination" >&2; exit 1; }
[ -f "$XUI_BIN" ] || { echo "x-ui binary missing at $XUI_BIN (203/EXEC risk)" >&2; exit 1; }
if ! head -c 15 "$RESTORE" | grep -q 'SQLite format 3'; then
  echo "Backup is not a SQLite database (JaySub panel backups are .db only)" >&2
  exit 1
fi
if ! file "$XUI_BIN" 2>/dev/null | grep -qE 'ELF .* executable'; then
  echo "x-ui binary is not a valid ELF executable" >&2
  exit 1
fi
chmod 755 /usr/local/x-ui "$XUI_BIN" 2>/dev/null || true
BACKUP_DIR="$(mktemp -d /tmp/jaysub-restore-backup.XXXXXXXX)"
rollback() {
  code=$?
  if [ "$code" -ne 0 ] && [ -d "$BACKUP_DIR" ]; then
    if [ -f "$BACKUP_DIR/x-ui.db.bak" ]; then
      cp -a "$BACKUP_DIR/x-ui.db.bak" "$DB" 2>/dev/null || true
      chmod 600 "$DB" 2>/dev/null || true
    fi
    systemctl daemon-reload 2>/dev/null || true
    systemctl restart x-ui 2>/dev/null || true
  fi
  rm -rf "$BACKUP_DIR"
  rm -f "$RESTORE"
  exit "$code"
}
trap rollback EXIT
mkdir -p /etc/x-ui
chmod 700 /etc/x-ui
systemctl stop x-ui 2>/dev/null || true
sleep 2
if [ -f "$DB" ]; then
  cp -a "$DB" "$BACKUP_DIR/x-ui.db.bak"
fi
install -m 600 "$RESTORE" "$DB.tmp"
mv -f "$DB.tmp" "$DB"
chown root:root "$DB" 2>/dev/null || true
systemctl daemon-reload 2>/dev/null || true
systemctl enable x-ui 2>/dev/null || true
systemctl restart x-ui 2>/dev/null || true
for i in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15; do
  if systemctl is-active --quiet x-ui 2>/dev/null && pgrep -f '/usr/local/x-ui/x-ui' >/dev/null 2>&1; then
    echo "x-ui active after restore"
    trap - EXIT
    rm -rf "$BACKUP_DIR"
    rm -f "$RESTORE"
    exit 0
  fi
  sleep 2
done
echo "x-ui service not running after restore (check: systemctl status x-ui)" >&2
systemctl status x-ui --no-pager 2>&1 | tail -20 >&2 || true
journalctl -u x-ui -n 25 --no-pager 2>&1 >&2 || true
exit 1
BASH;

        return str_replace('__RESTORE__', escapeshellarg($remoteBackupPath), $script);
    }

    /**
     * Validate a local JaySub backup before upload (PHP-side, management server).
     */
    public static function assertLocalSqliteBackup(string $path): void
    {
        if (!is_file($path) || is_link($path)) {
            throw new \RuntimeException('فایل بک‌آپ محلی یافت نشد.');
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('خواندن فایل بک‌آپ ناموفق بود.');
        }
        try {
            $magic = fread($fh, 15);
            if ($magic !== self::SQLITE_MAGIC) {
                throw new \RuntimeException('بک‌آپ JaySub باید فایل SQLite (.db) پنل 3x-ui باشد.');
            }
        } finally {
            fclose($fh);
        }
    }
}
