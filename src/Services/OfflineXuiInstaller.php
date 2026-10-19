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
     * Provision a package on the JaySub management server. The destination VPS
     * NEVER contacts GitHub. An already-cached verified file is reused.
     * @param array<string, mixed> $config
     * @return array{path:string,sha256:string}
     */
    public static function ensureArchive(array $config, string $architecture): array
    {
        $destination = self::archivePath($config, $architecture);
        $dir = dirname($destination);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('ساخت پوشه cache روی سرور مدیریت JaySub ناموفق بود.');
        }
        if (is_link($destination)) {
            throw new \RuntimeException('مسیر بسته نصب آفلاین نباید symbolic link باشد.');
        }

        $lockPath = $dir . '/.download-' . $architecture . '.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('ایجاد قفل برای دریافت بسته 3x-ui ناموفق بود.');
        }
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('قفل cache بسته نصب در دسترس نیست.');
            }

            // Never download again when the correct release has been staged.
            if (file_exists($destination) || is_link($destination)) {
                return self::validatedArchive($config, $architecture);
            }
            if (!function_exists('curl_init')) {
                throw new \RuntimeException('برای دریافت اولیه بسته روی JaySub افزونه PHP cURL لازم است.');
            }

            $tmp = tempnam($dir, '.downloading-');
            if ($tmp === false) {
                throw new \RuntimeException('ساخت فایل موقت دانلود روی JaySub ناموفق بود.');
            }
            try {
                $stream = fopen($tmp, 'wb');
                if ($stream === false) {
                    throw new \RuntimeException('فایل موقت دانلود روی JaySub قابل نوشتن نیست.');
                }

                $url = 'https://github.com/MHSanaei/3x-ui/releases/download/'
                    . self::VERSION . '/x-ui-linux-' . $architecture . '.tar.gz';
                $ch = curl_init($url);
                if ($ch === false) {
                    fclose($stream);
                    throw new \RuntimeException('شروع دانلود بسته 3x-ui روی JaySub ناموفق بود.');
                }

                try {
                    curl_setopt_array($ch, [
                        CURLOPT_FILE => $stream,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_MAXREDIRS => 5,
                        CURLOPT_CONNECTTIMEOUT => 30,
                        CURLOPT_TIMEOUT => 600,
                        CURLOPT_SSL_VERIFYPEER => true,
                        CURLOPT_SSL_VERIFYHOST => 2,
                        CURLOPT_FAILONERROR => true,
                        CURLOPT_USERAGENT => 'JaySub-offline-xui/3.4.2',
                        CURLOPT_LOW_SPEED_LIMIT => 1024,
                        CURLOPT_LOW_SPEED_TIME => 90,
                    ]);
                    $ok = curl_exec($ch);
                    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    $error = curl_error($ch);
                } finally {
                    curl_close($ch);
                    fclose($stream);
                }

                if ($ok !== true || $httpCode !== 200) {
                    throw new \RuntimeException(
                        'دریافت اولیه نسخه 3.4.2 روی سرور JaySub ناموفق بود (HTTP '
                        . $httpCode . '): ' . $error
                    );
                }

                $size = filesize($tmp);
                if ($size === false || $size < 10_000_000 || $size > 105_000_000) {
                    throw new \RuntimeException('حجم بسته دانلودشده روی JaySub نامعتبر است.');
                }
                $digest = hash_file('sha256', $tmp);
                if (!is_string($digest) || !hash_equals(self::SHA256[$architecture], strtolower($digest))) {
                    throw new \RuntimeException('SHA-256 دانلود روی JaySub با نسخه رسمی 3x-ui v3.4.2 مطابقت ندارد.');
                }

                chmod($tmp, 0600);
                if (!rename($tmp, $destination)) {
                    throw new \RuntimeException('انتقال بسته دانلودشده به cache روی JaySub ناموفق بود.');
                }

                return self::validatedArchive($config, $architecture);
            } finally {
                if (is_file($tmp)) {
                    @unlink($tmp);
                }
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Validate local cache before transferring to another VPS.
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
     * Canonical SCP destination path on the target VPS (must match {@see remoteInstallScript}).
     */
    public static function remotePackagePath(string $transferId, string $architecture): string
    {
        if (!isset(self::SHA256[$architecture])) {
            throw new \InvalidArgumentException('Unsupported x-ui architecture');
        }
        $id = strtolower(preg_replace('/[^a-f0-9]/', '', $transferId) ?? '');
        if (strlen($id) !== 32) {
            throw new \InvalidArgumentException('Migration transfer id must be 32 hex characters');
        }

        return '/tmp/jaysub-xui-' . $id . '-' . $architecture . '.tar.gz';
    }

    public static function isValidRemotePackagePath(string $path): bool
    {
        return (bool) preg_match(
            '~\A/tmp/jaysub-xui-[a-f0-9]{32}-(?:amd64|arm64)\.tar\.gz\z~D',
            $path
        );
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
        if (!self::isValidRemotePackagePath($remoteArchive)) {
            throw new \InvalidArgumentException('Unexpected remote x-ui package path');
        }

        $lib = XuiRemoteScriptLib::bashPrerequisitesBlock() . "\n"
            . XuiRemoteScriptLib::bashValidateTarArchiveBlock() . "\n"
            . XuiRemoteScriptLib::bashVerifyBinaryBlock() . "\n"
            . XuiRemoteScriptLib::bashWaitServiceHealthyBlock(12, 2);

        $script = $lib . <<<'BASH'

set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "Offline x-ui install requires root" >&2; exit 1; }
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
  if [ -n "${ARCHIVE:-}" ] && [ -f "$ARCHIVE" ]; then rm -f "$ARCHIVE"; fi
  exit "$code"
}
trap cleanup EXIT
validate_tar_members "$ARCHIVE" || exit 1
tar -xzf "$ARCHIVE" -C "$TMP" --no-same-owner --no-same-permissions
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
chmod 755 /usr/local/x-ui /usr/local/x-ui/x-ui /usr/local/x-ui/x-ui.sh
install -m 0755 /usr/local/x-ui/x-ui.sh /usr/bin/x-ui
verify_xui_binary "$ARCH" || exit 1
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
if wait_xui_service_healthy "$XUI_BIN"; then
  if [ ! -s /etc/x-ui/x-ui.db ] && [ ! -s /usr/local/x-ui/x-ui.db ]; then
    echo "x-ui.db missing after offline install" >&2
    exit 1
  fi
  CACHE="/var/lib/jaysub/xui-offline-342-${ARCH}.tar.gz"
  mkdir -p /var/lib/jaysub
  mv "$ARCHIVE" "$CACHE"
  chmod 600 "$CACHE"
  ARCHIVE=""
  success=1
  echo "Offline 3x-ui v3.4.2 installation verified (MainPID/exe + database)."
  exit 0
fi
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
