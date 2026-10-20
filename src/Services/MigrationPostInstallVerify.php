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
            . XuiRemoteScriptLib::bashWaitServiceHealthyBlock(15, 2);

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
if ! wait_xui_service_healthy "$XUI_BIN"; then
  echo "x-ui service health check failed after install" >&2
  exit 1
fi
echo "JaySub post-install verify: binary, systemd unit, and service OK"
exit 0
BASH;
    }
}
