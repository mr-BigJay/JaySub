#!/usr/bin/env bash
# Repair only JaySub's SSH host-key state. Run as root on the MANAGEMENT VPS.
set -Eeuo pipefail

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run as root: sudo bash scripts/repair-ssh-known-hosts.sh [php-fpm-user]" >&2
  exit 1
fi

BASE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)"
PHP_USER="${1:-www-data}"
id "$PHP_USER" >/dev/null 2>&1 || { echo "User does not exist: $PHP_USER" >&2; exit 1; }
PHP_GROUP="$(id -gn "$PHP_USER")"
CONFIG="$BASE/config/config.php"
STORAGE="$BASE/storage"
if [[ -f "$CONFIG" ]]; then
  STORAGE="$(php -r '$c = require $argv[1]; echo $c["paths"]["storage"] ?? $argv[2];' "$CONFIG" "$STORAGE")"
fi
if [[ "$STORAGE" != /* ]]; then
  echo "Expected an absolute storage directory: $STORAGE" >&2
  exit 1
fi

DIR="${STORAGE%/}/ssl-ssh"
KNOWN="$DIR/known_hosts"
if [[ -L "$DIR" || -L "$KNOWN" ]]; then
  echo "Refusing to modify symlinked SSH state path" >&2
  exit 1
fi
install -d -o "$PHP_USER" -g "$PHP_GROUP" -m 0700 -- "$DIR"
if [[ ! -e "$KNOWN" ]]; then
  install -o "$PHP_USER" -g "$PHP_GROUP" -m 0600 /dev/null "$KNOWN"
elif [[ ! -f "$KNOWN" ]]; then
  echo "known_hosts is not a regular file: $KNOWN" >&2
  exit 1
else
  chown -- "$PHP_USER:$PHP_GROUP" "$KNOWN"
  chmod 0600 -- "$KNOWN"
fi

if ! runuser -u "$PHP_USER" -- test -w "$DIR" || ! runuser -u "$PHP_USER" -- test -w "$KNOWN"; then
  echo "SSH state is still not writable as $PHP_USER" >&2
  exit 1
fi
echo "JaySub SSH known_hosts ready for $PHP_USER: $KNOWN"
