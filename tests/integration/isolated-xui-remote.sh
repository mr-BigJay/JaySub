#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
PHP="${PHP:-php}"
pass=0
fail=0

ok() { echo "[ok] $1"; pass=$((pass + 1)); }
bad() { echo "[FAIL] $1" >&2; fail=$((fail + 1)); }

INSTALL=$("$PHP" -r "require '$ROOT/vendor/autoload.php'; echo App\\Services\\OfflineXuiInstaller::remoteInstallScript('amd64', '/tmp/jaysub-xui-' . str_repeat('f', 32) . '-amd64.tar.gz');")
RESTORE=$("$PHP" -r "require '$ROOT/vendor/autoload.php'; echo App\\Services\\XuiMigrationRestore::remoteRestoreScript('/tmp/jaysub-restore-fixture.db');")

if bash -n <<<"$INSTALL"; then ok 'offline install bash -n'; else bad 'offline install bash -n'; fi
if bash -n <<<"$RESTORE"; then ok 'restore bash -n'; else bad 'restore bash -n'; fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
mkdir -p "$TMP/st/x-ui"
echo 'stub' >"$TMP/st/x-ui/x-ui"
GOOD="$TMP/good.tar.gz"
tar -czf "$GOOD" -C "$TMP/st" x-ui

LIB=$("$PHP" -r "require '$ROOT/vendor/autoload.php'; echo App\\Services\\XuiRemoteScriptLib::bashValidateTarArchiveBlock();")
SCRIPT="$TMP/validate.sh"
printf '%s\nvalidate_tar_members %q\n' "$LIB" "$GOOD" >"$SCRIPT"
if bash "$SCRIPT"; then ok 'valid tar accepted'; else bad 'valid tar accepted'; fi

echo "--- isolated summary: $pass ok, $fail failed"
[ "$fail" -eq 0 ]
