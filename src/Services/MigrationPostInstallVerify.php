<?php

declare(strict_types=1);

namespace App\Services;

/** Remote verification after 3x-ui install (online or offline). */
final class MigrationPostInstallVerify
{
    public static function remoteVerifyScript(): string
    {
        $lib = XuiRemoteScriptLib::bashPrerequisitesBlock() . "\n"
            . XuiRemoteScriptLib::bashVerifyBinaryBlock() . "\n"
            . XuiRemoteScriptLib::bashWaitServiceHealthyBlock(15, 2) . "\n"
            . XuiRemoteScriptLib::bashSqliteQuickCheckBlock();

        return $lib . <<<'BASH'

set -euo pipefail
case "$(uname -m)" in
  x86_64|amd64) ARCH=amd64 ;;
  aarch64|arm64|armv8|armv8l) ARCH=arm64 ;;
  *) echo "Unsupported architecture for x-ui verify" >&2; exit 1 ;;
esac
[ -f /etc/systemd/system/x-ui.service ] || { echo "x-ui systemd unit missing after install" >&2; exit 1; }
if [ -x /usr/bin/x-ui ] && [ ! -x /usr/local/x-ui/x-ui ]; then
  echo "Only management script /usr/bin/x-ui present; panel binary /usr/local/x-ui/x-ui missing" >&2
  exit 1
fi
verify_xui_binary "$ARCH" || exit 1
[ -f "/usr/local/x-ui/bin/xray-linux-$ARCH" ] || { echo "Xray binary missing from install" >&2; exit 1; }
systemctl restart x-ui
if ! wait_xui_service_healthy "$XUI_BIN"; then
  echo "x-ui service health check failed after install" >&2
  exit 1
fi
DB=""
for c in /etc/x-ui/x-ui.db /usr/local/x-ui/x-ui.db; do
  [ -f "$c" ] && DB="$c" && break
done
[ -n "$DB" ] || { echo "x-ui.db missing after install" >&2; exit 1; }
sqlite_quick_check "$DB" || exit 1
echo "JaySub post-install verify: binary, systemd, service, and database OK"
exit 0
BASH;
    }

    /** Existing healthy panel: no systemctl restart (non-disruptive). */
    public static function remoteVerifyExistingScript(): string
    {
        $lib = XuiRemoteScriptLib::bashVerifyBinaryBlock() . "\n"
            . XuiRemoteScriptLib::bashProbeServiceHealthyBlock() . "\n"
            . XuiRemoteScriptLib::bashSqliteQuickCheckBlock();

        return $lib . <<<'BASH'

set -euo pipefail
command -v sqlite3 >/dev/null 2>&1 || { echo "Missing sqlite3 for existing panel verify" >&2; exit 1; }
XUI_BIN=/usr/local/x-ui/x-ui
XUI_UNIT=/etc/systemd/system/x-ui.service
case "$(uname -m)" in
  x86_64|amd64) ARCH=amd64 ;;
  aarch64|arm64|armv8|armv8l) ARCH=arm64 ;;
  *) echo "Unsupported architecture for x-ui verify" >&2; exit 1 ;;
esac
verify_xui_binary "$ARCH" || exit 1
if ! probe_xui_service_healthy "$XUI_BIN"; then
  echo "x-ui service probe failed (no restart performed)" >&2
  exit 1
fi
DB=""
for c in /etc/x-ui/x-ui.db /usr/local/x-ui/x-ui.db; do
  [ -f "$c" ] && DB="$c" && break
done
if [ -n "$DB" ]; then
  sqlite_quick_check "$DB" || exit 1
fi
echo "JaySub existing x-ui verify OK (no service restart)"
exit 0
BASH;
    }
}
