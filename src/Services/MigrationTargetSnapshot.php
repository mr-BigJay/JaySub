<?php

declare(strict_types=1);

namespace App\Services;

/** Pre-overwrite snapshot + rollback for migration SSL/DB on the target VPS. */
final class MigrationTargetSnapshot
{
    public const ROLLBACK_DIR = '/var/lib/jaysub/migration-rollback';

    public static function remoteCreateSnapshotScript(string $certPath): string
    {
        $certEsc = escapeshellarg($certPath);
        $root = self::ROLLBACK_DIR;

        return <<<BASH
set -euo pipefail
CERT_DIR={$certEsc}
ROOT='{$root}'
mkdir -p "\$ROOT"
STAMP=\$(date +%s)
CERT_BAK="\$ROOT/certs-\${STAMP}.tar.gz"
DB=/etc/x-ui/x-ui.db
DB_BAK="\$ROOT/x-ui.db-\${STAMP}.bak"
if [ -d "\$CERT_DIR" ] && [ "\$(find "\$CERT_DIR" -mindepth 1 -print -quit 2>/dev/null | wc -l)" -gt 0 ]; then
  parent=\$(dirname "\$CERT_DIR")
  base=\$(basename "\$CERT_DIR")
  tar -czf "\$CERT_BAK" -C "\$parent" "\$base"
else
  CERT_BAK=""
fi
if [ -f "\$DB" ]; then
  cp -a "\$DB" "\$DB_BAK"
else
  DB_BAK=""
fi
umask 077
cat > "\$ROOT/latest.env" <<ENV
CERT_DIR={$certEsc}
CERT_BAK=\${CERT_BAK}
DB_BAK=\${DB_BAK}
ENV
echo "JaySub migration snapshot ready (cert + db if present)"
exit 0
BASH;
    }

    public static function remoteRollbackSnapshotScript(): string
    {
        $root = self::ROLLBACK_DIR;

        return <<<BASH
set -euo pipefail
ROOT='{$root}'
ENV="\$ROOT/latest.env"
[ -f "\$ENV" ] || { echo "No migration snapshot to roll back" >&2; exit 1; }
# shellcheck disable=SC1090
. "\$ENV"
if [ -n "\${DB_BAK:-}" ] && [ -f "\$DB_BAK" ]; then
  mkdir -p /etc/x-ui
  cp -a "\$DB_BAK" /etc/x-ui/x-ui.db
  chmod 600 /etc/x-ui/x-ui.db 2>/dev/null || true
fi
if [ -n "\${CERT_BAK:-}" ] && [ -f "\$CERT_BAK" ] && [ -n "\${CERT_DIR:-}" ]; then
  parent=\$(dirname "\$CERT_DIR")
  base=\$(basename "\$CERT_DIR")
  mkdir -p "\$CERT_DIR"
  find "\$CERT_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true
  tar -xzf "\$CERT_BAK" -C "\$parent"
fi
systemctl daemon-reload 2>/dev/null || true
systemctl restart x-ui 2>/dev/null || true
echo "JaySub migration rollback applied from snapshot"
exit 0
BASH;
    }
}
