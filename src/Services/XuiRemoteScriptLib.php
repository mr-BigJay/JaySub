<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Shared bash fragments for remote x-ui install/restore (embedded in SSH scripts).
 */
final class XuiRemoteScriptLib
{
    /**
     * Prerequisite commands and paths used by install/restore verification.
     */
    public static function bashPrerequisitesBlock(): string
    {
        return <<<'BASH'
for cmd in tar sha256sum mktemp systemctl install uname od head tr cut readlink file; do
  command -v "$cmd" >/dev/null 2>&1 || { echo "Missing local prerequisite: $cmd" >&2; exit 1; }
done
XUI_BIN=/usr/local/x-ui/x-ui
XUI_UNIT=/etc/systemd/system/x-ui.service
BASH;
    }

    /**
     * Validate tar.gz member list (no absolute paths, .., symlinks, hardlinks).
     * Uses assignment + test instead of pipefail-sensitive grep -q pipelines.
     */
    public static function bashValidateTarArchiveBlock(): string
    {
        return <<<'BASH'
validate_tar_members() {
  local archive=$1
  local listing unsafe symlinks
  listing="$(mktemp)"
  unsafe="$(mktemp)"
  symlinks="$(mktemp)"
  cleanup_listing() { rm -f "$listing" "$unsafe" "$symlinks"; }
  if ! tar -tzf "$archive" >"$listing" 2>/dev/null; then
    echo "Cannot list archive members" >&2
    cleanup_listing
    return 1
  fi
  grep -E '(^/|\.\./|(^|/)\.\.(/|$))' "$listing" >"$unsafe" || true
  if [ -s "$unsafe" ]; then
    echo "Unsafe paths in archive" >&2
    cleanup_listing
    return 1
  fi
  tar -tvf "$archive" 2>/dev/null | grep -E '^[lh]' >"$symlinks" || true
  if [ -s "$symlinks" ]; then
    echo "Archive contains symlinks or hardlinks" >&2
    cleanup_listing
    return 1
  fi
  cleanup_listing
  return 0
}
BASH;
    }

    /**
     * Verify official layout binary: exists, executable, ELF matches ARCH, ldd if present, matches systemd ExecStart.
     */
    public static function bashVerifyBinaryBlock(): string
    {
        return <<<'BASH'
verify_xui_binary() {
  local arch="$1"
  local bin="$XUI_BIN"
  [ -f "$bin" ] || { echo "x-ui binary missing at $bin (systemd 203/EXEC if ExecStart points here)" >&2; return 1; }
  [ -x "$bin" ] || { echo "x-ui binary not executable at $bin (203/EXEC)" >&2; return 1; }
  local file_out
  file_out="$(file -b "$bin" 2>/dev/null || true)"
  case "$file_out" in
    *ELF*) ;;
    *) echo "x-ui is not ELF: $file_out" >&2; return 1 ;;
  esac
  case "$arch" in
    amd64)
      echo "$file_out" | grep -qiE 'x86-64|80386|AMD64' || { echo "ELF architecture mismatch (expected amd64): $file_out" >&2; return 1; }
      ;;
    arm64)
      echo "$file_out" | grep -qiE 'aarch64|ARM64' || { echo "ELF architecture mismatch (expected arm64): $file_out" >&2; return 1; }
      ;;
    *)
      echo "Unknown arch for verify: $arch" >&2; return 1
      ;;
  esac
  if command -v ldd >/dev/null 2>&1; then
    if ldd "$bin" 2>&1 | grep -qi 'not found'; then
      echo "Missing dynamic libraries for x-ui binary" >&2
      ldd "$bin" 2>&1 | grep -i 'not found' >&2 || true
      return 1
    fi
  fi
  if [ -f "$XUI_UNIT" ]; then
    local exec_line want
    exec_line="$(grep -E '^ExecStart=' "$XUI_UNIT" | head -1 || true)"
    want="${exec_line#ExecStart=}"
    want="${want%% *}"
    if [ -n "$want" ] && [ "$want" != "$bin" ]; then
      echo "systemd ExecStart ($want) does not match expected $bin" >&2
      return 1
    fi
  fi
  return 0
}
BASH;
    }

    /**
     * Copy panel binary from extracted release dir (validated tar already extracted under $2/x-ui).
     */
    public static function bashRepairBinaryBlock(): string
    {
        return <<<'BASH'
repair_xui_binary_from_tree() {
  local arch="$1"
  local tree="$2"
  local src="$tree/x-ui/x-ui"
  [ -f "$src" ] || { echo "Repair tree missing x-ui/x-ui" >&2; return 1; }
  mkdir -p /usr/local/x-ui
  install -m 0755 "$src" "$XUI_BIN"
  chmod 755 /usr/local/x-ui "$XUI_BIN"
  verify_xui_binary "$arch"
}
BASH;
    }

    /**
     * Wait for systemd active + stable MainPID whose /proc/PID/exe resolves to expected binary.
     */
    public static function bashWaitServiceHealthyBlock(int $attempts = 15, int $sleepSeconds = 2): string
    {
        $block = <<<'BASH'
wait_xui_service_healthy() {
  local expect="$1"
  local max=__MAX__
  local sleep_sec=__SLEEP__
  local i pid exe stable
  for i in $(seq 1 "$max"); do
    if ! systemctl is-active --quiet x-ui 2>/dev/null; then
      sleep "$sleep_sec"
      continue
    fi
    pid="$(systemctl show x-ui -p MainPID --value 2>/dev/null || echo 0)"
    case "$pid" in
      ''|0) sleep "$sleep_sec"; continue ;;
    esac
    if [ ! -r "/proc/$pid/exe" ]; then
      sleep "$sleep_sec"
      continue
    fi
    exe="$(readlink -f "/proc/$pid/exe" 2>/dev/null || true)"
    if [ "$exe" != "$expect" ]; then
      echo "MainPID $pid exe ($exe) != $expect" >&2
      sleep "$sleep_sec"
      continue
    fi
    if ! kill -0 "$pid" 2>/dev/null; then
      sleep "$sleep_sec"
      continue
    fi
    stable=0
    sleep "$sleep_sec"
    if systemctl is-active --quiet x-ui 2>/dev/null; then
      pid="$(systemctl show x-ui -p MainPID --value 2>/dev/null || echo 0)"
      exe="$(readlink -f "/proc/$pid/exe" 2>/dev/null || true)"
      if [ "$exe" = "$expect" ] && kill -0 "$pid" 2>/dev/null; then
        stable=1
      fi
    fi
    if [ "$stable" -eq 1 ]; then
      return 0
    fi
  done
  echo "x-ui service failed stability check (MainPID/exe)" >&2
  systemctl status x-ui --no-pager 2>&1 | tail -25 >&2 || true
  journalctl -u x-ui -n 30 --no-pager 2>&1 >&2 || true
  return 1
}
BASH;

        return str_replace(
            ['__MAX__', '__SLEEP__'],
            [(string) $attempts, (string) $sleepSeconds],
            $block
        );
    }

    /** Remote SQLite quick_check using sqlite3 CLI when available. */
    public static function bashSqliteQuickCheckBlock(): string
    {
        return <<<'BASH'
sqlite_quick_check() {
  local db=$1
  if command -v sqlite3 >/dev/null 2>&1; then
    local qc
    qc="$(sqlite3 "$db" 'PRAGMA quick_check;' 2>/dev/null || echo fail)"
    [ "$qc" = "ok" ] || { echo "SQLite quick_check failed: $qc" >&2; return 1; }
    return 0
  fi
  head -c 15 "$db" | grep -q 'SQLite format 3' || { echo "Not a SQLite file" >&2; return 1; }
  return 0
}
BASH;
    }
}
