#!/usr/bin/env bash
# Real install tests on clean Ubuntu 24.04 (requires Docker + JaySub PHP on host).
# Run: bash scripts/integration/ubuntu2404-xui-install.sh [online|offline|both]
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
MODE="${1:-both}"
PHP="${PHP:-php}"

if ! command -v docker >/dev/null 2>&1; then
  echo "SKIP: Docker not available — Ubuntu 24.04 online/offline install tests were NOT executed."
  exit 0
fi

TMPD="$(mktemp -d)"
trap 'rm -rf "$TMPD"' EXIT
"$PHP" -r "require '$ROOT/vendor/autoload.php'; file_put_contents('$TMPD/online.sh', App\\Services\\OnlineXuiInstaller::remoteInstallScript());"
"$PHP" -r "require '$ROOT/vendor/autoload.php'; file_put_contents('$TMPD/verify.sh', App\\Services\\MigrationPostInstallVerify::remoteVerifyScript());"

run_online() {
  echo "=== Scenario A: online install on Ubuntu 24.04 ==="
  docker run --rm --privileged \
    -v "$TMPD:/scripts:ro" \
    ubuntu:24.04 bash -lc '
      set -euo pipefail
      export DEBIAN_FRONTEND=noninteractive
      apt-get update -qq
      apt-get install -y -qq curl ca-certificates file sqlite3 openssl tar gzip systemd
      mkdir -p /run/systemd/system || true
      bash /scripts/online.sh
      bash /scripts/verify.sh
      echo "ONLINE_OK"
    '
}

run_offline() {
  echo "=== Scenario B: offline install on Ubuntu 24.04 (no GitHub on target) ==="
  ARCH=amd64
  REMOTE="/tmp/jaysub-xui-$(printf '%032x' 1)-${ARCH}.tar.gz"
  CACHE="$ROOT/storage/xui-offline/v3.4.2/x-ui-linux-${ARCH}.tar.gz"
  if [ ! -f "$CACHE" ]; then
    echo "SKIP offline: cache missing at $CACHE (populate on JaySub management host first)."
    return 0
  fi
  "$PHP" -r "require '$ROOT/vendor/autoload.php'; file_put_contents('$TMPD/offline.sh', App\\Services\\OfflineXuiInstaller::remoteInstallScript('amd64', '$REMOTE'));"
  docker run --rm --privileged \
    -v "$TMPD:/scripts:ro" \
    -v "$CACHE:/pkg.tar.gz:ro" \
    ubuntu:24.04 bash -lc "
      set -euo pipefail
      export DEBIAN_FRONTEND=noninteractive
      apt-get update -qq
      apt-get install -y -qq file sqlite3 openssl tar gzip systemd
      mkdir -p /run/systemd/system || true
      cp /pkg.tar.gz $REMOTE
      SHA=$($PHP -r \"require '$ROOT/vendor/autoload.php'; echo App\\\\Services\\\\OfflineXuiInstaller::releaseSha256('amd64');\")
      printf '%s  %s\n' \"\$SHA\" $REMOTE | sha256sum -c -
      bash /scripts/offline.sh
      bash /scripts/verify.sh
      echo OFFLINE_OK
    "
}

case "$MODE" in
  online) run_online ;;
  offline) run_offline ;;
  both) run_online; run_offline ;;
  *) echo "Usage: $0 [online|offline|both]"; exit 2 ;;
esac
