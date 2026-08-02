#!/usr/bin/env sh
set -eu

DATA_DIR="$(dirname "${PVDASH_SQLITE:-/var/lib/penguinpvdash/pvdash.sqlite}")"
mkdir -p "$DATA_DIR"
chown -R www-data:www-data "$DATA_DIR"
chmod 0770 "$DATA_DIR"

if [ ! -f /var/www/html/inc/config.local.php ]; then
  if [ "${PVDASH_ADMIN_PASSWORD:-}" = "" ]; then
    echo >&2 "ERROR: PVDASH_ADMIN_PASSWORD must be configured."
    exit 1
  fi

  if [ "${PVDASH_API_KEYS_JSON:-}" = "" ] && { [ "${PVDASH_DEVICE_ID:-}" = "" ] || [ "${PVDASH_API_KEY:-}" = "" ]; }; then
    echo >&2 "ERROR: Configure PVDASH_API_KEYS_JSON or both PVDASH_DEVICE_ID and PVDASH_API_KEY."
    exit 1
  fi
fi

exec docker-php-entrypoint "$@"
