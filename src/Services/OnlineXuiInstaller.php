<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Online 3x-ui install on migration targets (pinned upstream install.sh + v3.4.2).
 *
 * Manual reference: bash <(curl -Ls …/master/install.sh) v3.4.2
 * JaySub pins install.sh by commit/SHA and requires release checksum confirmation in the install log.
 */
final class OnlineXuiInstaller
{
    public const TARGET_VERSION = 'v3.4.2';

    /** Pinned install.sh commit (not a floating branch). */
    public const INSTALL_SH_COMMIT = 'c9207b6f3ea64eb91c080479efb390bb9c807c3a';

    public const INSTALL_SH_SHA256 = '9684bae4f769b2127fcceceb4f200045657381311867529e6ba76c6b58e2825d';

    public static function installShRawUrl(): string
    {
        return 'https://raw.githubusercontent.com/MHSanaei/3x-ui/'
            . self::INSTALL_SH_COMMIT
            . '/install.sh';
    }

    public static function bashFetchPinnedInstallScript(): string
    {
        $url = self::installShRawUrl();
        $sha = self::INSTALL_SH_SHA256;
        $dest = '/tmp/jaysub-xui-installer.sh';

        return <<<BASH
fetch_install_script() {
  local url='{$url}'
  local dest='{$dest}'
  local expect='{$sha}'
  local attempt digest
  for attempt in 1 2 3; do
    if curl -fsSL --connect-timeout 30 --max-time 300 "\$url" -o "\$dest.tmp"; then
      digest="\$(sha256sum "\$dest.tmp" | awk '{print \$1}')"
      if [ "\$digest" = "\$expect" ]; then
        chmod 700 "\$dest.tmp"
        mv -f "\$dest.tmp" "\$dest"
        return 0
      fi
      echo "install.sh SHA-256 mismatch (attempt \$attempt)" >&2
    else
      echo "install.sh download failed (attempt \$attempt)" >&2
    fi
    rm -f "\$dest.tmp"
    sleep 5
  done
  return 1
}
BASH;
    }

    public static function remoteInstallScript(): string
    {
        $ver = escapeshellarg(self::TARGET_VERSION);
        $body = <<<'BASH'

set -euo pipefail
export DEBIAN_FRONTEND=noninteractive XUI_NONINTERACTIVE=1 XUI_DB_TYPE=sqlite XUI_SSL_MODE=none XUI_ENABLE_FAIL2BAN=false
LOG=/tmp/jaysub-xui-install.log
INSTALLER=/tmp/jaysub-xui-installer.sh
wait_apt() {
  local max=600 w=0
  while fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 \
    || fuser /var/lib/apt/lists/lock >/dev/null 2>&1 \
    || pgrep -x apt-get >/dev/null 2>&1 || pgrep -x apt >/dev/null 2>&1; do
    if [ "$w" -ge "$max" ]; then echo "apt lock held after ${max}s" >&2; return 1; fi
    echo "Waiting for apt lock (${w}s)..." >&2
    sleep 5
    w=$((w+5))
  done
}
detect_partial_xui() {
  local found=0
  for p in /usr/local/x-ui /etc/systemd/system/x-ui.service /etc/x-ui/x-ui.db; do
    [ -e "$p" ] && found=1
  done
  if [ "$found" -eq 1 ] && [ ! -x /usr/local/x-ui/x-ui ]; then
    echo "Partial x-ui install without /usr/local/x-ui/x-ui — stop and recover manually" >&2
    return 1
  fi
  return 0
}
detect_partial_xui || exit 1
fetch_install_script || { echo "Could not download verified install.sh" >&2; exit 1; }
wait_apt
rm -f "$LOG"
if ! bash "$INSTALLER" __VERSION__ >>"$LOG" 2>&1; then
  tail -n 120 "$LOG" 2>/dev/null || true
  echo "Official install.sh exited with error" >&2
  exit 1
fi
if grep -qi "Checksum mismatch" "$LOG" 2>/dev/null; then
  tail -n 120 "$LOG" 2>/dev/null || true
  echo "Official release archive checksum failed during online install" >&2
  exit 1
fi
if ! grep -qi "Checksum verified" "$LOG" 2>/dev/null; then
  tail -n 120 "$LOG" 2>/dev/null || true
  echo "Release v3.4.2 checksum not confirmed in install log" >&2
  exit 1
fi
case "$(uname -m)" in
  x86_64|amd64) ARCH=amd64; EXPECT_SHA='__SHA256_AMD64__' ;;
  aarch64|arm64|armv8|armv8l) ARCH=arm64; EXPECT_SHA='__SHA256_ARM64__' ;;
  *) echo "Unsupported architecture" >&2; exit 1 ;;
esac
for candidate in "/usr/local/x-ui-linux-${ARCH}.tar.gz" "/root/x-ui-linux-${ARCH}.tar.gz" "./x-ui-linux-${ARCH}.tar.gz" "/tmp/x-ui-linux-${ARCH}.tar.gz"; do
  if [ -f "$candidate" ]; then
    if ! gzip -t "$candidate" 2>/dev/null; then
      echo "Downloaded release archive failed gzip integrity test: $candidate" >&2
      exit 1
    fi
    printf '%s  %s\n' "$EXPECT_SHA" "$candidate" | sha256sum -c - || exit 1
    break
  fi
done
if grep -qiE "Failed to extract|invalid compressed data|Error is not recoverable|cannot access .x-ui" "$LOG" 2>/dev/null; then
  tail -n 120 "$LOG" 2>/dev/null || true
  echo "x-ui install log contains extract/binary errors" >&2
  exit 1
fi
if [ ! -x /usr/local/x-ui/x-ui ]; then
  tail -n 120 "$LOG" 2>/dev/null || true
  echo "x-ui panel binary missing after online install" >&2
  exit 1
fi
tail -n 80 "$LOG" 2>/dev/null || true
echo "JaySub online install.sh finished; awaiting JaySub health verify"
exit 0
BASH;

        $body = str_replace('__VERSION__', $ver, $body);
        $body = str_replace(
            ['__SHA256_AMD64__', '__SHA256_ARM64__'],
            [
                OfflineXuiInstaller::releaseSha256('amd64'),
                OfflineXuiInstaller::releaseSha256('arm64'),
            ],
            $body
        );

        return self::bashFetchPinnedInstallScript() . "\n" . $body;
    }
}
