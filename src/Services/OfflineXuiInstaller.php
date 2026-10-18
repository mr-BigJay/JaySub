<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Pinned, verifiable offline installation of the official MHSanaei/3x-ui v3.4.2
 * release package. The release archive is provisioned on the JaySub host and
 * copied over SSH; no Internet request is made by the target VPS.
 */
final class OfflineXuiInstaller
{
    public const VERSION = 'v3.4.2';

    /** SHA-256 digests published by the official v3.4.2 GitHub release. */
    private const SHA256 = [
        'amd64' => '1086716ea4b09f87893da3d83d5957af28ab7094c3a3a374cb3b369f6c0009a6',
        'arm64' => 'f7ce1303a22fa06a0d9de101a8b831c78ce756d84319ea1ff5236ce523e8ecd0',
    ];

    public static function normalizeArchitecture(string $uname): ?string
    {
        return match (trim(strtolower($uname))) {
            'x86_64', 'x64', 'amd64' => 'amd64',
            'aarch64', 'arm64', 'armv8', 'armv8l' => 'arm64',
            default => null,
        };
    }

    /** @return list<string> */
    public static function architectures(): array
    {
        return array_keys(self::SHA256);
    }

    /** @param array<string, mixed> $config */
    public static function archivePath(array $config, string $architecture): string
    {
        if (!isset(self::SHA256[$architecture])) {
            throw new \InvalidArgumentException('معماری سرور برای نصب آفلاین پشتیبانی نمی‌شود: ' . $architecture);
        }
        $storage = rtrim((string) ($config['paths']['storage'] ?? dirname(__DIR__, 2) . '/storage'), '/');

        return $storage . '/xui-offline/' . self::VERSION . '/x-ui-linux-' . $architecture . '.tar.gz';
    }

    /** @param array<string, mixed> $config */
    public static function archiveExists(array $config, string $architecture): bool
    {
        $path = self::archivePath($config, $architecture);

        return is_file($path) && !is_link($path) && is_readable($path);
    }

    /**
     * Validate before transferring (large files must be provided by the admin).
     * @param array<string, mixed> $config
     * @return array{path:string,sha256:string}
     */
    public static function validatedArchive(array $config, string $architecture): array
    {
        $path = self::archivePath($config, $architecture);
        if (!self::archiveExists($config, $architecture)) {
            throw new \RuntimeException(
                'بسته آفلاین 3x-ui ' . self::VERSION . ' (' . $architecture . ') روی سرور JaySub پیدا نشد: ' . $path
            );
        }

        $size = filesize($path);
        if ($size === false || $size < 10_000_000) {
            throw new \RuntimeException('فایل نصب آفلاین بسیار کوچک یا ناقص است.');
        }

        $digest = hash_file('sha256', $path);
        if (!is_string($digest) || !hash_equals(self::SHA256[$architecture], strtolower($digest))) {
            throw new \RuntimeException(
                'SHA-256 بسته نصب آفلاین با فایل رسمی 3x-ui ' . self::VERSION . ' مطابقت ندارد. نصب متوقف شد.'
            );
        }

        return ['path' => $path, 'sha256' => self::SHA256[$architecture]];
    }

    /**
     * Store a verified archive received through the admin-only upload form.
     * @param array<string, mixed> $config
     * @param array<string, mixed> $file PHP $_FILES entry
     */
    public static function stageUpload(array $config, string $architecture, array $file): string
    {
        $destination = self::archivePath($config, $architecture);
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('آپلود بسته 3x-ui کامل نشد (کد ' . $error . '). محدودیت upload_max_filesize و post_max_size را بررسی کنید.');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if ($size < 10_000_000 || $size > 105_000_000 || !is_uploaded_file($tmpName)) {
            throw new \RuntimeException('فایل آپلودشده معتبر نیست یا حجم آن خارج از محدوده بسته رسمی است.');
        }
        $digest = hash_file('sha256', $tmpName);
        if (!is_string($digest) || !hash_equals(self::SHA256[$architecture], strtolower($digest))) {
            throw new \RuntimeException('SHA-256 فایل آپلودشده با نسخه رسمی v3.4.2 و معماری انتخاب‌شده مطابقت ندارد.');
        }

        $dir = dirname($destination);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('پوشه نگهداری بسته آفلاین قابل ایجاد نیست.');
        }
        if (is_link($destination)) {
            throw new \RuntimeException('مسیر بسته آفلاین نباید symbolic link باشد.');
        }
        $staged = tempnam($dir, 'xui-');
        if ($staged === false) {
            throw new \RuntimeException('ساخت فایل موقت برای بسته آفلاین ناموفق بود.');
        }
        try {
            if (!move_uploaded_file($tmpName, $staged)) {
                throw new \RuntimeException('انتقال بسته آپلودشده به storage ناموفق بود.');
            }
            chmod($staged, 0600);
            if (!rename($staged, $destination)) {
                throw new \RuntimeException('جایگزینی بسته آفلاین ناموفق بود.');
            }
        } finally {
            if (is_file($staged)) {
                @unlink($staged);
            }
        }

        return $destination;
    }

    /**
     * Generate a network-free installer for a fresh Ubuntu/Debian systemd VPS.
     * @param string $remoteArchive a unique, fixed-format path under /tmp
     */
    public static function remoteInstallScript(string $architecture, string $remoteArchive): string
    {
        if (!isset(self::SHA256[$architecture])) {
            throw new \InvalidArgumentException('Unsupported x-ui architecture');
        }
        if (!preg_match('~\A/tmp/jaysub-xui-[a-f0-9]{32}-(?:amd64|arm64)\.tar\.gz\z~D', $remoteArchive)) {
            throw new \InvalidArgumentException('Unexpected remote x-ui package path');
        }

        $script = <<<'BASH'
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Offline x-ui install requires root" >&2; exit 1; }
for cmd in tar sha256sum mktemp systemctl install uname od head tr cut; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "Missing local prerequisite: $cmd" >&2; exit 1; }
done
[ -d /run/systemd/system ] || { echo "Offline installer requires systemd (Ubuntu/Debian)" >&2; exit 1; }
ARCH=__ARCH__
ARCHIVE=__ARCHIVE__
case "$(uname -m)" in
  x86_64|amd64) ACTUAL_ARCH=amd64 ;;
  aarch64|arm64|armv8|armv8l) ACTUAL_ARCH=arm64 ;;
  *) echo "Unsupported destination architecture" >&2; exit 1 ;;
esac
[ "$ACTUAL_ARCH" = "$ARCH" ] || { echo "Archive architecture does not match destination" >&2; exit 1; }
[ -f "$ARCHIVE" ] || { echo "Transferred archive missing" >&2; exit 1; }
printf '%s  %s\n' '__SHA256__' "$ARCHIVE" | sha256sum -c -
# Migration targets must be fresh, to prevent accidental replacement of an existing panel.
for existing in /usr/local/x-ui /usr/bin/x-ui /etc/systemd/system/x-ui.service /etc/x-ui/x-ui.db; do
  [ ! -e "$existing" ] || { echo "Existing x-ui installation detected ($existing); refusing to overwrite it" >&2; exit 1; }
done
umask 077
TMP="$(mktemp -d /tmp/jaysub-xui-install.XXXXXXXX)"
installed=0
success=0
cleanup() {
  code=$?
  if [ "$installed" -eq 1 ] && [ "$success" -ne 1 ]; then
    systemctl stop x-ui >/dev/null 2>&1 || true
    systemctl disable x-ui >/dev/null 2>&1 || true
    rm -rf /usr/local/x-ui
    rm -f /usr/bin/x-ui /etc/systemd/system/x-ui.service /etc/x-ui/x-ui.db
    systemctl daemon-reload >/dev/null 2>&1 || true
  fi
  rm -rf "$TMP"
  rm -f "$ARCHIVE"
  exit "$code"
}
trap cleanup EXIT
tar -xzf "$ARCHIVE" -C "$TMP"
[ -f "$TMP/x-ui/x-ui" ] && [ -f "$TMP/x-ui/x-ui.sh" ] || { echo "Invalid x-ui release layout" >&2; exit 1; }
if [ -f "$TMP/x-ui/x-ui.service" ]; then
  SERVICE="$TMP/x-ui/x-ui.service"
elif [ -f "$TMP/x-ui/x-ui.service.debian" ]; then
  SERVICE="$TMP/x-ui/x-ui.service.debian"
else
  # Some release bundles omit the service; supply a self-contained systemd unit.
  SERVICE="$TMP/jaysub-x-ui.service"
  cat >"$SERVICE" <<'UNIT'
[Unit]
Description=3x-ui Service
After=network.target nss-lookup.target

[Service]
Type=simple
WorkingDirectory=/usr/local/x-ui
ExecStart=/usr/local/x-ui/x-ui
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
fi
[ -f "$TMP/x-ui/bin/xray-linux-$ARCH" ] || { echo "Xray binary missing from release" >&2; exit 1; }
chmod +x "$TMP/x-ui/x-ui" "$TMP/x-ui/x-ui.sh" "$TMP/x-ui/bin/xray-linux-$ARCH"
if [ -f "$TMP/x-ui/bin/mtg-linux-$ARCH" ]; then chmod +x "$TMP/x-ui/bin/mtg-linux-$ARCH"; fi
mkdir -p /etc/x-ui /var/log/x-ui
chmod 700 /etc/x-ui
installed=1
install -m 0644 "$SERVICE" /etc/systemd/system/x-ui.service
mv "$TMP/x-ui" /usr/local/x-ui
install -m 0755 /usr/local/x-ui/x-ui.sh /usr/bin/x-ui
# Generate throwaway randomized credentials while the restore step is pending.
random_hex() { head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n' | cut -c1-20; }
USER_TMP="js$(random_hex)"
PASS_TMP="$(random_hex)$(random_hex)"
PATH_TMP="$(random_hex)"
PORT_TMP=$(( 20000 + 0x$(random_hex | cut -c1-4) % 30000 ))
/usr/local/x-ui/x-ui setting -username "$USER_TMP" -password "$PASS_TMP" -webBasePath "$PATH_TMP" -port "$PORT_TMP" >/dev/null
systemctl daemon-reload
systemctl enable x-ui >/dev/null
systemctl restart x-ui
for i in 1 2 3 4 5 6 7 8 9 10; do
  if systemctl is-active --quiet x-ui && { [ -s /etc/x-ui/x-ui.db ] || [ -s /usr/local/x-ui/x-ui.db ]; }; then
    success=1
    echo "Offline 3x-ui v3.4.2 installation verified (systemd + database)."
    exit 0
  fi
  sleep 2
done
echo "x-ui service or database failed after offline install" >&2
exit 1
BASH;

        return str_replace(
            ['__ARCH__', '__ARCHIVE__', '__SHA256__'],
            [escapeshellarg($architecture), escapeshellarg($remoteArchive), self::SHA256[$architecture]],
            $script
        );
    }
}
