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

        $keyMatch = XuiRemoteScriptLib::bashCertKeyPubkeySha256Block();
        $rollbackDir = MigrationTargetSnapshot::ROLLBACK_DIR;

        $verify = $keyMatch . "\n" . <<<'BASH'

rollback_cert_from_snapshot() {
  local env="__ROLLBACK_DIR__/latest.env"
  [ -f "$env" ] || return 0
  # shellcheck disable=SC1090
  . "$env"
  if [ -n "${CERT_BAK:-}" ] && [ -f "$CERT_BAK" ] && [ -n "${CERT_DIR:-}" ]; then
    parent=$(dirname "$CERT_DIR")
    base=$(basename "$CERT_DIR")
    mkdir -p "$CERT_DIR"
    find "$CERT_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} + 2>/dev/null || true
    tar -xzf "$CERT_BAK" -C "$parent"
    echo "Rolled back certificate tree from pre-migration snapshot" >&2
  fi
}

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
  local matched=0 certf keyf
  while IFS= read -r -d '' certf; do
    openssl x509 -in "$certf" -noout 2>/dev/null || continue
    while IFS= read -r -d '' keyf; do
      grep -q 'PRIVATE KEY' "$keyf" 2>/dev/null || continue
      if verify_cert_key_match "$certf" "$keyf" "ssl-tree"; then
        matched=1
        break 2
      fi
    done < <(find "$dir" -type f \( -name '*.key' -o -name '*.pem' \) -print0 2>/dev/null)
  done < <(find "$dir" -type f \( -name '*.crt' -o -name '*.cer' -o -name '*.pem' \) -print0 2>/dev/null)
  if [ "$key_count" -gt 0 ] && [ "$matched" -ne 1 ]; then
    echo "No matching private key for certificate under $dir" >&2
    return 1
  fi
  echo "SSL verify: ${valid_cert} certificate(s), ${key_count} private key file(s), cert/key pubkey SHA-256 OK under $dir"
  return 0
}
if ! verify_ssl_tree __CERT__; then
  rollback_cert_from_snapshot
  exit 1
fi
rm -f __ARCHIVE__
echo "JaySub SSL transfer verified"
BASH;

        $verify = str_replace('__CERT__', $certEsc, $verify);
        $verify = str_replace('__ARCHIVE__', $archEsc, $verify);
        $verify = str_replace('__ROLLBACK_DIR__', $rollbackDir, $verify);

        return 'set -euo pipefail; mkdir -p ' . $certEsc . '; ' . $extract . $verify;
    }

    /** After DB restore: paths referenced by x-ui settings must exist and cert/key must match. */
    public static function remoteVerifyDbCertPathsScript(string $certPath): string
    {
        $certEsc = escapeshellarg($certPath);

        $keyMatch = XuiRemoteScriptLib::bashCertKeyPubkeySha256Block();

        $script = $keyMatch . "\n" . <<<'BASH'
set -euo pipefail
command -v sqlite3 >/dev/null 2>&1 || { echo "sqlite3 required to verify panel cert paths in DB" >&2; exit 1; }
command -v openssl >/dev/null 2>&1 || { echo "openssl required to verify cert/key pairs" >&2; exit 1; }
DB=/etc/x-ui/x-ui.db
CERT_ROOT=__CERT__
[ -f "$DB" ] || { echo "x-ui.db missing for SSL path verification" >&2; exit 1; }
[ -d "$CERT_ROOT" ] || { echo "Certificate directory missing: $CERT_ROOT" >&2; exit 1; }
resolve_path() {
  local p=$1
  [ -n "$p" ] || return 1
  case "$p" in
    /*) printf '%s\n' "$p" ;;
    *) printf '%s\n' "$CERT_ROOT/$p" ;;
  esac
}
verify_pair_files() {
  local cert=$1 key=$2 label=$3
  verify_cert_key_match "$cert" "$key" "$label" || return 1
  echo "DB SSL paths OK for $label: $cert"
  return 0
}
checked=0
for keys in "webCertFile webKeyFile web" "subCertFile subKeyFile subscription"; do
  set -- $keys
  ck="$(sqlite3 "$DB" "SELECT value FROM settings WHERE key='$1' LIMIT 1;" 2>/dev/null || true)"
  kk="$(sqlite3 "$DB" "SELECT value FROM settings WHERE key='$2' LIMIT 1;" 2>/dev/null || true)"
  if [ -n "$ck" ] || [ -n "$kk" ]; then
    cp="$(resolve_path "$ck")" || true
    kp="$(resolve_path "$kk")" || true
    verify_pair_files "$cp" "$kp" "$3" || exit 1
    checked=1
  fi
done
if [ "$checked" -eq 0 ]; then
  while IFS='|' read -r k v; do
    case "$k" in
      *Cert*|*cert*|*Key*|*key*)
        if [ -n "$v" ] && [ "${v#/}" != "$v" ]; then
          [ -f "$v" ] || { echo "DB references missing SSL file: $v (key $k)" >&2; exit 1; }
          checked=1
        fi
        ;;
    esac
  done < <(sqlite3 -separator '|' "$DB" "SELECT key, value FROM settings WHERE value LIKE '/%';" 2>/dev/null || true)
fi
[ "$checked" -ge 1 ] || echo "Note: no panel cert paths in DB settings; filesystem SSL tree was verified at transfer"
echo "JaySub DB SSL path verification complete"
exit 0
BASH;

        return str_replace('__CERT__', $certEsc, $script);
    }
}
