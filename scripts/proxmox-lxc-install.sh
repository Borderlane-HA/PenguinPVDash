#!/usr/bin/env bash
set -Eeuo pipefail

APP="PenguinPVDash"
APP_SLUG="penguinpvdash"
IMAGE="ghcr.io/borderlane-ha/penguinpvdash:latest"
DEFAULT_PORT="8092"
DEFAULT_HOSTNAME="penguinpvdash"
DEFAULT_DISK="8"
DEFAULT_MEMORY="1024"
DEFAULT_CORES="2"
DEFAULT_BRIDGE="vmbr0"
DEFAULT_STORAGE="local-lvm"

if [[ ${EUID} -ne 0 ]] || ! command -v pct >/dev/null 2>&1; then
  echo "Run this script as root on a Proxmox VE host." >&2
  exit 1
fi

TMP_DIR="$(mktemp -d)"
cleanup() { rm -rf "$TMP_DIR"; }
trap cleanup EXIT

ask() {
  local prompt="$1" default="${2:-}" value
  if [[ -n "$default" ]]; then
    read -r -p "$prompt [$default]: " value
    printf '%s' "${value:-$default}"
  else
    read -r -p "$prompt: " value
    printf '%s' "$value"
  fi
}

ask_secret() {
  local prompt="$1" value
  read -r -s -p "$prompt: " value
  echo >&2
  printf '%s' "$value"
}

ask_bool() {
  local prompt="$1" default="$2" value
  read -r -p "$prompt [$default]: " value
  value="${value:-$default}"
  case "${value,,}" in
    y|yes|j|ja|true|1) printf 'true' ;;
    *) printf 'false' ;;
  esac
}

next_vmid() {
  pvesh get /cluster/nextid 2>/dev/null || echo 200
}

VMID="$(ask 'Container ID' "$(next_vmid)")"
HOSTNAME="$(ask 'Hostname' "$DEFAULT_HOSTNAME")"
STORAGE="$(ask 'Storage' "$DEFAULT_STORAGE")"
BRIDGE="$(ask 'Network bridge' "$DEFAULT_BRIDGE")"
DISK="$(ask 'Disk size in GB' "$DEFAULT_DISK")"
MEMORY="$(ask 'Memory in MB' "$DEFAULT_MEMORY")"
CORES="$(ask 'CPU cores' "$DEFAULT_CORES")"
PORT="$(ask 'Dashboard port' "$DEFAULT_PORT")"
LANGUAGE="$(ask 'Default language (de/en)' 'de')"
FEED_IN_CT="$(ask 'Feed-in compensation in ct/kWh' '0')"
DEVICE_ID="$(ask 'Home Assistant device ID' 'home')"
API_KEY="$(ask_secret 'Home Assistant API key')"
ADMIN_PASSWORD="$(ask_secret 'Admin password')"
read -r -s -p 'Guest password (empty = public read-only): ' GUEST_PASSWORD
echo >&2
GUEST_STATS="$(ask_bool 'May guests view statistics? (y/n)' 'y')"
GUEST_COMP="$(ask_bool 'May guests view feed-in compensation? (y/n)' 'n')"

if [[ -z "$API_KEY" || -z "$ADMIN_PASSWORD" ]]; then
  echo "API key and admin password must not be empty." >&2
  exit 1
fi
if pct status "$VMID" >/dev/null 2>&1; then
  echo "Container ID $VMID already exists." >&2
  exit 1
fi

TEMPLATE_STORAGE="local"
TEMPLATE="debian-12-standard_12.7-1_amd64.tar.zst"
if ! pveam list "$TEMPLATE_STORAGE" | awk '{print $1}' | grep -q "/$TEMPLATE$"; then
  echo "Updating Proxmox template list..."
  pveam update
  AVAILABLE="$(pveam available --section system | awk '/debian-12-standard.*amd64/{print $2}' | tail -n1)"
  if [[ -z "$AVAILABLE" ]]; then
    echo "No Debian 12 LXC template found." >&2
    exit 1
  fi
  TEMPLATE="$AVAILABLE"
  pveam download "$TEMPLATE_STORAGE" "$TEMPLATE"
fi
TEMPLATE_PATH="$TEMPLATE_STORAGE:vztmpl/$TEMPLATE"

ROOT_PASSWORD="$(openssl rand -hex 12)"

echo "Creating LXC $VMID..."
pct create "$VMID" "$TEMPLATE_PATH" \
  --hostname "$HOSTNAME" \
  --ostype debian \
  --unprivileged 1 \
  --features nesting=1,keyctl=1 \
  --cores "$CORES" \
  --memory "$MEMORY" \
  --swap 512 \
  --rootfs "$STORAGE:$DISK" \
  --net0 "name=eth0,bridge=$BRIDGE,ip=dhcp,type=veth" \
  --password "$ROOT_PASSWORD" \
  --onboot 1 \
  --start 1

for _ in {1..30}; do
  pct exec "$VMID" -- true >/dev/null 2>&1 && break
  sleep 2
done

pct exec "$VMID" -- bash -lc 'export DEBIAN_FRONTEND=noninteractive; apt-get update; apt-get install -y ca-certificates curl; curl -fsSL https://get.docker.com | sh; systemctl enable --now docker'
pct exec "$VMID" -- mkdir -p "/opt/$APP_SLUG/data"

for value_name in API_KEY ADMIN_PASSWORD GUEST_PASSWORD; do
  value="${!value_name}"
  if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    echo "$value_name must not contain line breaks." >&2
    exit 1
  fi
done

php_quote() {
  local value="$1"
  value="${value//\\/\\\\}"
  value="${value//\'/\\\'}"
  printf "'%s'" "$value"
}

CONFIG_CONTENT=$(cat <<CONFIG
<?php
return [
    'timezone' => 'Europe/Berlin',
    'language' => $(php_quote "$LANGUAGE"),
    'feed_in_ct' => $(php_quote "$FEED_IN_CT"),
    'admin_password' => $(php_quote "$ADMIN_PASSWORD"),
    'guest_password' => $(php_quote "$GUEST_PASSWORD"),
    'guest_can_view_stats' => $GUEST_STATS,
    'guest_can_view_compensation' => $GUEST_COMP,
    'require_ingest_auth' => true,
    'api_keys' => [
        $(php_quote "$DEVICE_ID") => $(php_quote "$API_KEY"),
    ],
];
CONFIG
)

printf '%s\n' "$CONFIG_CONTENT" > "$TMP_DIR/config.local.php"
chmod 600 "$TMP_DIR/config.local.php"

cat > "$TMP_DIR/docker-compose.yml" <<COMPOSE
services:
  penguinpvdash:
    image: $IMAGE
    container_name: penguinpvdash
    restart: unless-stopped
    ports:
      - "$PORT:80"
    environment:
      PVDASH_SQLITE: /var/lib/penguinpvdash/pvdash.sqlite
    volumes:
      - ./data:/var/lib/penguinpvdash
      - ./config.local.php:/var/www/html/inc/config.local.php:ro
COMPOSE

pct push "$VMID" "$TMP_DIR/config.local.php" "/opt/$APP_SLUG/config.local.php" --perms 0600
pct push "$VMID" "$TMP_DIR/docker-compose.yml" "/opt/$APP_SLUG/docker-compose.yml" --perms 0644
pct exec "$VMID" -- bash -lc "cd /opt/$APP_SLUG && docker compose pull && docker compose up -d"

IP="$(pct exec "$VMID" -- hostname -I 2>/dev/null | awk '{print $1}')"
echo
echo "$APP installed successfully."
echo "URL: http://${IP:-CONTAINER-IP}:$PORT"
echo "LXC ID: $VMID"
echo "Configuration: /opt/$APP_SLUG/docker-compose.yml"
echo "Data: /opt/$APP_SLUG/data"
echo "Generated LXC root password: $ROOT_PASSWORD"
