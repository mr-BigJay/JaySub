<?php

declare(strict_types=1);

namespace App\Services;

/** Pre-overwrite snapshot + rollback for migration SSL/DB on the target VPS (one isolated snapshot per job). */
final class MigrationTargetSnapshot
{
    public const ROLLBACK_DIR = '/var/lib/jaysub/migration-rollback';

    /** Remote-safe job id (hex only), same rules as {@see MigrationJobStore::path}. */
    public static function sanitizeJobId(string $jobId): string
    {
        $safe = preg_replace('/[^a-f0-9]/', '', strtolower($jobId)) ?? '';
        if ($safe === '') {
            throw new \InvalidArgumentException('شناسه job نامعتبر برای snapshot');
        }

        return $safe;
    }

    public static function snapshotDirForJob(string $jobId): string
    {
        return self::ROLLBACK_DIR . '/' . self::sanitizeJobId($jobId);
    }

    public static function manifestPathForJob(string $jobId): string
    {
        return self::snapshotDirForJob($jobId) . '/manifest.env';
    }

    public static function remoteCreateSnapshotScript(string $certPath, string $jobId): string
    {
        $certEsc = escapeshellarg($certPath);
        $jid = self::sanitizeJobId($jobId);
        $jidEsc = escapeshellarg($jid);
        $root = self::ROLLBACK_DIR;
        $snapRoot = self::snapshotDirForJob($jid);
        $manifest = self::manifestPathForJob($jid);

        return <<<BASH
set -euo pipefail
umask 077
JOB_ID={$jidEsc}
CERT_DIR={$certEsc}
ROOT='{$root}'
SNAP_ROOT='{$snapRoot}'
MANIFEST='{$manifest}'
mkdir -p "\$SNAP_ROOT"
chmod 700 "\$SNAP_ROOT" 2>/dev/null || true
chmod 700 "\$ROOT" 2>/dev/null || true
STAMP=\$(date +%s)
CERT_BAK="\$SNAP_ROOT/certs-\${STAMP}.tar.gz"
DB=/etc/x-ui/x-ui.db
DB_BAK="\$SNAP_ROOT/x-ui-\${STAMP}.db"
CERT_DIR_EXISTED=0
if [ -d "\$CERT_DIR" ] && [ "\$(find "\$CERT_DIR" -mindepth 1 -print -quit 2>/dev/null | wc -l)" -gt 0 ]; then
  CERT_DIR_EXISTED=1
  parent=\$(dirname "\$CERT_DIR")
  base=\$(basename "\$CERT_DIR")
  tar -czf "\$CERT_BAK" -C "\$parent" "\$base"
  chmod 600 "\$CERT_BAK"
else
  CERT_BAK=""
fi
if [ -f "\$DB" ]; then
  if ! command -v sqlite3 >/dev/null 2>&1; then
    echo "sqlite3 required for WAL-safe x-ui.db snapshot" >&2
    exit 1
  fi
  sqlite3 "\$DB" ".backup '\$DB_BAK'"
  chmod 600 "\$DB_BAK"
else
  DB_BAK=""
fi
umask 077
cat > "\$MANIFEST" <<ENV
JOB_ID=\$JOB_ID
CERT_DIR={$certEsc}
CERT_DIR_EXISTED=\${CERT_DIR_EXISTED}
CERT_BAK=\${CERT_BAK}
DB_BAK=\${DB_BAK}
ENV
chmod 600 "\$MANIFEST"
echo "JaySub migration snapshot ready job=\$JOB_ID manifest=\$MANIFEST"
exit 0
BASH;
    }

    public static function remoteRollbackSnapshotScript(string $jobId): string
    {
        $jid = self::sanitizeJobId($jobId);
        $manifest = self::manifestPathForJob($jid);
        $certRollback = self::bashApplyCertStateFromManifest();

        $lib = XuiRemoteScriptLib::bashSqliteQuickCheckBlock() . "\n"
            . XuiRemoteScriptLib::bashSwapLiveSqliteDatabaseBlock() . "\n"
            . XuiRemoteScriptLib::bashWaitServiceHealthyBlock(15, 2);

        $body = <<<BASH
set -euo pipefail
umask 077
XUI_BIN=/usr/local/x-ui/x-ui
JOB_ID='{$jid}'
MANIFEST='{$manifest}'
DB=/etc/x-ui/x-ui.db
[ -f "\$MANIFEST" ] || { echo "JaySub migration rollback: FAILED job=\$JOB_ID (no manifest at \$MANIFEST)" >&2; exit 1; }
# shellcheck disable=SC1090
. "\$MANIFEST"
rb_ok=1
if [ -n "\${DB_BAK:-}" ] && [ -f "\$DB_BAK" ]; then
  systemctl stop x-ui 2>/dev/null || true
  sleep 2
  if ! jaysub_install_sqlite_db_from_backup "\$DB_BAK" "\$DB"; then
    rb_ok=0
    echo "JaySub migration rollback: DB restore or quick_check failed" >&2
  fi
elif [ -f "\$DB" ] && [ -z "\${DB_BAK:-}" ]; then
  :
fi
{$certRollback}
if [ "\$rb_ok" -ne 1 ]; then
  echo "JaySub migration rollback: FAILED job=\$JOB_ID" >&2
  exit 1
fi
systemctl daemon-reload 2>/dev/null || true
if ! systemctl restart x-ui 2>/dev/null; then
  rb_ok=0
  echo "JaySub migration rollback: x-ui restart failed (job=\$JOB_ID)" >&2
fi
if [ "\$rb_ok" -eq 1 ]; then
  if ! wait_xui_service_healthy "\$XUI_BIN"; then
    rb_ok=0
    echo "JaySub migration rollback: x-ui health check failed after restore (job=\$JOB_ID)" >&2
  fi
fi
if [ "\$rb_ok" -ne 1 ]; then
  echo "JaySub migration rollback: FAILED job=\$JOB_ID" >&2
  exit 1
fi
echo "JaySub migration rollback: SUCCESS job=\$JOB_ID manifest=\$MANIFEST"
exit 0
BASH;

        return $lib . "\n" . $body;
    }

    /** Restore cert dir from snapshot metadata (shared by full rollback and SSL verify failure). */
    public static function bashApplyCertStateFromManifest(): string
    {
        return <<<'BASH'
if [ "${CERT_DIR_EXISTED:-0}" = "1" ] && [ -n "${CERT_BAK:-}" ] && [ -f "$CERT_BAK" ] && [ -n "${CERT_DIR:-}" ]; then
  parent=$(dirname "$CERT_DIR")
  base=$(basename "$CERT_DIR")
  mkdir -p "$CERT_DIR"
  find "$CERT_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true
  if ! tar -xzf "$CERT_BAK" -C "$parent"; then
    rb_ok=0
    echo "JaySub migration rollback: certificate tree restore failed" >&2
  fi
elif [ "${CERT_DIR_EXISTED:-0}" = "0" ] && [ -n "${CERT_DIR:-}" ]; then
  if [ -d "$CERT_DIR" ]; then
    rm -rf "$CERT_DIR" 2>/dev/null || rb_ok=0
    echo "JaySub migration rollback: removed certificate dir created during migration ($CERT_DIR)"
  fi
fi
BASH;
    }
}
