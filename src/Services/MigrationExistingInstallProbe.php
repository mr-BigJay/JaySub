<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Detect existing x-ui on migration target before any install/overwrite.
 *
 * stdout markers (exit 0 unless noted):
 * - JaySub x-ui probe: absent — nothing to reuse
 * - JaySub x-ui probe: healthy-existing — skip install (exit 0)
 * - JaySub x-ui probe: partial — incomplete tree (exit 2)
 * - JaySub x-ui probe: present-unhealthy — installed but service/binary bad (exit 3)
 */
final class MigrationExistingInstallProbe
{
    public static function remoteProbeScript(): string
    {
        $lib = XuiRemoteScriptLib::bashVerifyBinaryBlock() . "\n"
            . XuiRemoteScriptLib::bashProbeServiceHealthyBlock();

        return <<<'BASH'
set -euo pipefail
for cmd in systemctl readlink file uname; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "Missing probe prerequisite: $cmd" >&2; exit 1; }
done
XUI_BIN=/usr/local/x-ui/x-ui
XUI_UNIT=/etc/systemd/system/x-ui.service

BASH
            . $lib . <<<'BASH'

case "$(uname -m)" in
  x86_64|amd64) ARCH=amd64 ;;
  aarch64|arm64|armv8|armv8l) ARCH=arm64 ;;
  *) echo "Unsupported architecture for x-ui probe" >&2; exit 1 ;;
esac
any=0
partial=0
for p in /usr/local/x-ui /usr/bin/x-ui /etc/systemd/system/x-ui.service /etc/x-ui/x-ui.db; do
  [ -e "$p" ] && any=1
done
if [ "$any" -eq 0 ]; then
  echo "JaySub x-ui probe: absent"
  exit 0
fi
if [ ! -x /usr/local/x-ui/x-ui ]; then
  echo "JaySub x-ui probe: partial"
  exit 2
fi
if [ ! -f /etc/systemd/system/x-ui.service ]; then
  echo "JaySub x-ui probe: partial"
  exit 2
fi
verify_xui_binary "$ARCH" || { echo "JaySub x-ui probe: present-unhealthy"; exit 3; }
if ! systemctl is-active --quiet x-ui 2>/dev/null; then
  echo "JaySub x-ui probe: present-unhealthy"
  exit 3
fi
if ! probe_xui_service_healthy "$XUI_BIN"; then
  echo "JaySub x-ui probe: present-unhealthy"
  exit 3
fi
echo "JaySub x-ui probe: healthy-existing"
exit 0
BASH;
    }

    public static function outputIndicatesHealthyExisting(string $output): bool
    {
        return str_contains($output, 'JaySub x-ui probe: healthy-existing');
    }

    public static function outputIndicatesPartial(string $output): bool
    {
        return str_contains($output, 'JaySub x-ui probe: partial');
    }
}
