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
for cmd in tar gzip sha256sum mktemp systemctl install uname od head tr cut readlink file sqlite3 openssl; do
  command -v "$cmd" >/dev/null 2>&1 || missing="$missing $cmd"
done
[ -d /run/systemd/system ] || { echo "Offline x-ui install requires systemd (Ubuntu/Debian)" >&2; exit 1; }
if [ -n "$missing" ]; then
  echo "Missing prerequisites on target for offline x-ui install:$missing" >&2
  echo "Prepare the VPS offline (no GitHub): apt-get update && apt-get install -y file sqlite3 openssl tar gzip ca-certificates coreutils systemd" >&2
  exit 1
fi
echo "JaySub offline prerequisites OK on target"
exit 0
BASH;
    }
}
