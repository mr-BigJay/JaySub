<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Remote shell workflow to restore JaySub 3x-ui SQLite backup (.db) without
 * replacing the panel binary. Verifies systemd MainPID/exe, optional binary
 * repair from the offline install cache, and rolls back on failure.
 */
final class XuiMigrationRestore
{
    public const SQLITE_MAGIC = 'SQLite format 3';

    /** Cached verified offline package on target (see {@see OfflineXuiInstaller}). */
    public static function offlineRepairArchivePath(string $architecture): string
    {
        return '/var/lib/jaysub/xui-offline-342-' . $architecture . '.tar.gz';
    }

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

    public static function remoteRestoreScript(string $remoteBackupPath): string
    {
        if (!self::isValidRemoteBackupPath($remoteBackupPath)) {
            throw new \InvalidArgumentException('Unexpected remote x-ui backup path');
        }

        $lib = XuiRemoteScriptLib::bashPrerequisitesBlock() . "\n"
            . XuiRemoteScriptLib::bashVerifyBinaryBlock() . "\n"
            . XuiRemoteScriptLib::bashRepairBinaryBlock() . "\n"
            . XuiRemoteScriptLib::bashValidateTarArchiveBlock() . "\n"
            . XuiRemoteScriptLib::bashWaitServiceHealthyBlock(15, 2) . "\n"
            . XuiRemoteScriptLib::bashSqliteQuickCheckBlock() . "\n"
            . XuiRemoteScriptLib::bashSwapLiveSqliteDatabaseBlock();

        $script = $lib . <<<'BASH'

set -euo pipefail
RESTORE=__RESTORE__
DB=/etc/x-ui/x-ui.db
[ "$(id -u)" -eq 0 ] || { echo "Restore requires root" >&2; exit 1; }
[ -f "$RESTORE" ] || { echo "Backup file missing on destination" >&2; exit 1; }
case "$(uname -m)" in
  x86_64|amd64) ARCH=amd64 ;;
  aarch64|arm64|armv8|armv8l) ARCH=arm64 ;;
  *) echo "Unsupported architecture for restore" >&2; exit 1 ;;
esac
REPAIR_ARCHIVE="/var/lib/jaysub/xui-offline-342-${ARCH}.tar.gz"
sqlite_quick_check "$RESTORE" || exit 1
had_db=0
[ -f "$DB" ] && had_db=1
if ! verify_xui_binary "$ARCH"; then
  echo "Pre-restore: x-ui binary check failed (203/EXEC risk before DB swap)" >&2
  if [ -f "$REPAIR_ARCHIVE" ]; then
    echo "Attempting binary repair from verified offline cache $REPAIR_ARCHIVE" >&2
    case "$ARCH" in
      amd64) printf '%s  %s\n' '__SHA256_AMD64__' "$REPAIR_ARCHIVE" | sha256sum -c - ;;
      arm64) printf '%s  %s\n' '__SHA256_ARM64__' "$REPAIR_ARCHIVE" | sha256sum -c - ;;
      *) echo "Unknown arch" >&2; exit 1 ;;
    esac || { echo "Repair archive checksum mismatch" >&2; exit 1; }
    validate_tar_members "$REPAIR_ARCHIVE" || exit 1
    RTMP="$(mktemp -d /tmp/jaysub-xui-repair.XXXXXXXX)"
    tar -xzf "$REPAIR_ARCHIVE" -C "$RTMP"
    repair_xui_binary_from_tree "$ARCH" "$RTMP" || { rm -rf "$RTMP"; exit 1; }
    rm -rf "$RTMP"
  else
    echo "No offline repair archive at $REPAIR_ARCHIVE — fix install step first" >&2
    exit 1
  fi
fi
BACKUP_DIR="$(mktemp -d /tmp/jaysub-restore-backup.XXXXXXXX)"
rollback() {
  code=$?
  if [ "$code" -ne 0 ] && [ -d "$BACKUP_DIR" ]; then
    systemctl stop x-ui 2>/dev/null || true
    sleep 2
    if [ -f "$BACKUP_DIR/x-ui.db.bak" ]; then
      if ! jaysub_install_sqlite_db_from_backup "$BACKUP_DIR/x-ui.db.bak" "$DB"; then
        echo "JaySub restore internal rollback: DB swap or quick_check failed" >&2
        code=1
      fi
    elif [ "$had_db" -eq 0 ]; then
      jaysub_remove_sqlite_sidecars "$DB"
    fi
    systemctl daemon-reload 2>/dev/null || true
    if ! systemctl restart x-ui 2>/dev/null; then
      echo "JaySub restore internal rollback: x-ui restart failed" >&2
      code=1
    elif ! wait_xui_service_healthy "$XUI_BIN"; then
      echo "JaySub restore internal rollback: x-ui health check failed" >&2
      code=1
    fi
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
if [ "$had_db" -eq 1 ]; then
  cp -a "$DB" "$BACKUP_DIR/x-ui.db.bak"
fi
if ! jaysub_install_sqlite_db_from_backup "$RESTORE" "$DB"; then
  echo "Restored file failed SQLite install or quick_check" >&2
  exit 1
fi
if ! verify_xui_binary "$ARCH"; then
  echo "Post-restore: binary check failed — restore must not replace executable; likely pre-existing install fault" >&2
  exit 1
fi
systemctl daemon-reload 2>/dev/null || true
systemctl enable x-ui 2>/dev/null || true
systemctl restart x-ui 2>/dev/null || true
if ! wait_xui_service_healthy "$XUI_BIN"; then
  echo "x-ui service unhealthy after restore (203/EXEC or crash loop — see status above)" >&2
  exit 1
fi
echo "x-ui active after restore (MainPID exe verified)"
trap - EXIT
rm -rf "$BACKUP_DIR"
rm -f "$RESTORE"
exit 0
BASH;

        $script = str_replace('__RESTORE__', escapeshellarg($remoteBackupPath), $script);

        return str_replace(
            ['__SHA256_AMD64__', '__SHA256_ARM64__'],
            [
                '1086716ea4b09f87893da3d83d5957af28ab7094c3a3a374cb3b369f6c0009a6',
                'f7ce1303a22fa06a0d9de101a8b831c78ce756d84319ea1ff5236ce523e8ecd0',
            ],
            $script
        );
    }

    /**
     * Validate local JaySub backup before upload (management server).
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

        if (extension_loaded('sqlite3')) {
            $db = new \SQLite3($path, SQLITE3_OPEN_READONLY);
            try {
                $qc = $db->querySingle('PRAGMA quick_check;');
                if (!is_string($qc) || strtolower($qc) !== 'ok') {
                    throw new \RuntimeException('PRAGMA quick_check روی بک‌آپ ناموفق بود.');
                }
            } finally {
                $db->close();
            }

            return;
        }

        $escaped = escapeshellarg($path);
        $out = [];
        $code = 1;
        exec('sqlite3 ' . $escaped . ' "PRAGMA quick_check;" 2>/dev/null', $out, $code);
        if ($code !== 0 || !isset($out[0]) || strtolower(trim((string) $out[0])) !== 'ok') {
            throw new \RuntimeException(
                'PRAGMA quick_check روی بک‌آپ ناموفق بود — php-sqlite3 یا sqlite3 CLI روی JaySub لازم است.'
            );
        }
    }
}
