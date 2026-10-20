<?php

declare(strict_types=1);

namespace App\Services;

/** Remote SSL cert deploy + validation for migration target VPS. */
final class SslMigrationTransfer
{
    public static function remoteExtractAndVerifyScript(string $certPath, string $remoteArchivePath, string $extension): string
    {
        $certEsc = escapeshellarg($certPath);
        $archEsc = escapeshellarg($remoteArchivePath);
        $ext = strtolower($extension);
        if (!in_array($ext, ['.zip', '.tar.gz'], true)) {
            throw new \InvalidArgumentException('Unsupported SSL archive extension');
        }

        $extractZip = <<<'BASH'
if ! command -v unzip >/dev/null 2>&1; then
  echo "unzip required for SSL zip archive" >&2
  exit 1
fi
d="$(mktemp -d /tmp/jaysub-cert.XXXXXXXX)"
unzip -t __ARCHIVE__ >/dev/null 2>&1 || { echo "SSL zip archive failed integrity test" >&2; rm -rf "$d"; exit 1; }
unzip -o __ARCHIVE__ -d "$d" || { echo "SSL zip extract failed" >&2; rm -rf "$d"; exit 1; }
if [ -d "$d/__FOLDER__" ]; then cp -a "$d/__FOLDER__/." __CERT__/; else cp -a "$d/." __CERT__/; fi
rm -rf "$d"
BASH;

        $extractTar = <<<'BASH'
if ! command -v tar >/dev/null 2>&1; then
  echo "tar required for SSL tar.gz archive" >&2
  exit 1
fi
if ! gzip -t __ARCHIVE__ 2>/dev/null; then
  echo "SSL tar.gz failed gzip integrity test" >&2
  exit 1
fi
d="$(mktemp -d /tmp/jaysub-cert.XXXXXXXX)"
tar -xzf __ARCHIVE__ -C "$d" || { echo "SSL tar extract failed" >&2; rm -rf "$d"; exit 1; }
if [ -d "$d/__FOLDER__" ]; then cp -a "$d/__FOLDER__/." __CERT__/; else cp -a "$d/." __CERT__/; fi
rm -rf "$d"
BASH;

        $folder = preg_replace('/[^a-zA-Z0-9._-]/', '', basename($certPath)) ?: 'cert';
        $extract = $ext === '.zip' ? $extractZip : $extractTar;
        $extract = str_replace(
            ['__ARCHIVE__', '__CERT__', '__FOLDER__'],
            [$archEsc, $certEsc, $folder],
            $extract
        );

        $verify = <<<'BASH'

verify_ssl_tree() {
  local dir=$1
  [ -d "$dir" ] || { echo "Certificate directory missing after extract" >&2; return 1; }
  local empty=1 f
  while IFS= read -r -d '' f; do
    [ -s "$f" ] || { echo "Empty SSL file: $f" >&2; return 1; }
    empty=0
  done < <(find "$dir" -type f -print0 2>/dev/null)
  [ "$empty" -eq 0 ] || { echo "No SSL files found under $dir" >&2; return 1; }
  if ! command -v openssl >/dev/null 2>&1; then
    echo "openssl required on destination to validate certificates" >&2
    return 1
  fi
  local cert_count=0 key_count=0 valid_cert=0
  while IFS= read -r -d '' f; do
    case "$f" in
      *.key|*.pem)
        if grep -q 'PRIVATE KEY' "$f" 2>/dev/null; then
          key_count=$((key_count + 1))
          chmod 600 "$f" 2>/dev/null || true
        elif openssl x509 -in "$f" -noout 2>/dev/null; then
          cert_count=$((cert_count + 1))
          valid_cert=$((valid_cert + 1))
        fi
        ;;
      *.crt|*.cer|fullchain.pem|cert.pem)
        if openssl x509 -in "$f" -noout 2>/dev/null; then
          cert_count=$((cert_count + 1))
          valid_cert=$((valid_cert + 1))
        fi
        ;;
    esac
  done < <(find "$dir" -type f \( -name '*.crt' -o -name '*.cer' -o -name '*.pem' -o -name '*.key' \) -print0 2>/dev/null)
  [ "$valid_cert" -gt 0 ] || { echo "No valid X.509 certificate found under $dir" >&2; return 1; }
  echo "SSL verify: ${valid_cert} certificate(s), ${key_count} private key file(s) under $dir"
  return 0
}
verify_ssl_tree __CERT__ || exit 1
rm -f __ARCHIVE__
echo "JaySub SSL transfer verified"
BASH;

        $verify = str_replace('__CERT__', $certEsc, $verify);
        $verify = str_replace('__ARCHIVE__', $archEsc, $verify);

        return 'set -euo pipefail; mkdir -p ' . $certEsc . '; ' . $extract . $verify;
    }
}
