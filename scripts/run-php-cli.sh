#!/usr/bin/env bash
# Resolve PHP CLI (not php-fpm) for JaySub background jobs.
set -euo pipefail

if [[ -n "${JAYSUB_PHP_CLI:-}" && -x "${JAYSUB_PHP_CLI}" ]]; then
  if "${JAYSUB_PHP_CLI}" -r 'echo PHP_SAPI;' 2>/dev/null | grep -qx cli; then
    exec "${JAYSUB_PHP_CLI}" "$@"
  fi
fi

for candidate in \
  /usr/bin/php8.3-cli \
  /usr/bin/php8.2-cli \
  /usr/bin/php-cli \
  /usr/bin/php8.3 \
  /usr/bin/php8.2 \
  /usr/bin/php; do
  [[ -x "$candidate" ]] || continue
  if "$candidate" -r 'echo PHP_SAPI;' 2>/dev/null | grep -qx cli; then
    exec "$candidate" "$@"
  fi
done

echo "JaySub: PHP CLI not found. Install: apt install php8.3-cli" >&2
exit 127
