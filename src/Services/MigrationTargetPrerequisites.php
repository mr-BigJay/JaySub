<?php

declare(strict_types=1);

namespace App\Services;

/** Pre-flight checks on the destination VPS (no package downloads from GitHub). */
final class MigrationTargetPrerequisites
{
    /**
     * Commands required before offline SCP/install. Does not run apt — only reports.
     */
    public static function remoteOfflineInstallPrerequisiteScript(): string
    {
        return <<<'BASH'
set -euo pipefail
missing=""
for cmd in tar gzip sha256sum mktemp systemctl install uname od head tr cut readlink file openssl; do
  command -v "$cmd" >/dev/null 2>&1 || missing="$missing $cmd"
done
if ! command -v sqlite3 >/dev/null 2>&1; then
  if ! command -v python3 >/dev/null 2>&1 || ! python3 -c 'import sqlite3' >/dev/null 2>&1; then
    missing="$missing sqlite3-or-python3-sqlite3"
  else
    echo "JaySub SQLite backend: Python 3 standard sqlite3 module (offline capable)"
  fi
fi
[ -d /run/systemd/system ] || { echo "Offline x-ui install requires systemd (Ubuntu/Debian)" >&2; exit 1; }
if [ -n "$missing" ]; then
  echo "Missing prerequisites on target for offline x-ui install:$missing" >&2
  echo "Requires sqlite3 CLI OR Python 3 with the standard sqlite3 module for database integrity checks and restore." >&2
  echo "Prepare the destination Ubuntu/Debian VPS first (from the OS package repository or local media): apt-get update && apt-get install -y file sqlite3 openssl tar gzip ca-certificates coreutils systemd" >&2
  echo "The offline 3x-ui installer never downloads from GitHub or silently runs apt." >&2
  exit 1
fi
echo "JaySub offline prerequisites OK on target"
exit 0
BASH;
    }
}
