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

ask_secret_confirm() {
  local prompt="$1" first second
  while true; do
    first="$(ask_secret "$prompt")"
    second="$(ask_secret "Confirm $prompt")"
    if [[ -z "$first" ]]; then
      echo "The password must not be empty." >&2
    elif [[ "$first" != "$second" ]]; then
      echo "The passwords do not match. Please try again." >&2
    else
      printf '%s' "$first"
      return 0
    fi
  done
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

validate_vlan() {
  local value="$1"
  [[ -z "$value" ]] && return 0
  [[ "$value" =~ ^[0-9]+$ ]] || return 1
  (( value >= 1 && value <= 4094 ))
}

validate_ipv4_cidr() {
  python3 - "$1" <<'PY'
import ipaddress
import sys
try:
    value = ipaddress.ip_interface(sys.argv[1])
    if value.version != 4:
        raise ValueError
except ValueError:
    raise SystemExit(1)
PY
}

validate_ipv4() {
  python3 - "$1" <<'PY'
import ipaddress
import sys
try:
    value = ipaddress.ip_address(sys.argv[1])
    if value.version != 4:
        raise ValueError
except ValueError:
    raise SystemExit(1)
PY
}

VMID="$(ask 'Container ID' "$(next_vmid)")"
HOSTNAME="$(ask 'Hostname' "$DEFAULT_HOSTNAME")"
STORAGE="$(ask 'Storage' "$DEFAULT_STORAGE")"
BRIDGE="$(ask 'Network bridge' "$DEFAULT_BRIDGE")"

while true; do
  VLAN_TAG="$(ask 'VLAN tag (leave empty for no VLAN)' '')"
  if validate_vlan "$VLAN_TAG"; then
    break
  fi
  echo "Enter a VLAN ID from 1 to 4094, or leave it empty." >&2
done

while true; do
  IP_MODE="$(ask 'IPv4 mode (dhcp/static)' 'dhcp')"
  IP_MODE="${IP_MODE,,}"
  case "$IP_MODE" in
    dhcp|static) break ;;
    *) echo "Enter either 'dhcp' or 'static'." >&2 ;;
  esac
done

STATIC_IP=""
GATEWAY=""
DNS_SERVER=""
if [[ "$IP_MODE" == "static" ]]; then
  while true; do
    STATIC_IP="$(ask 'Static IPv4 address including CIDR (example: 192.168.10.50/24)' '')"
    if validate_ipv4_cidr "$STATIC_IP"; then
      break
    fi
    echo "Enter a valid IPv4 address with CIDR prefix, for example 192.168.10.50/24." >&2
  done

  while true; do
    GATEWAY="$(ask 'IPv4 gateway (example: 192.168.10.1)' '')"
    if validate_ipv4 "$GATEWAY"; then
      break
    fi
    echo "Enter a valid IPv4 gateway." >&2
  done

  while true; do
    DNS_SERVER="$(ask 'DNS server' "$GATEWAY")"
    if validate_ipv4 "$DNS_SERVER"; then
      break
    fi
    echo "Enter a valid IPv4 DNS server." >&2
  done
fi

DISK="$(ask 'Disk size in GB' "$DEFAULT_DISK")"
MEMORY="$(ask 'Memory in MB' "$DEFAULT_MEMORY")"
CORES="$(ask 'CPU cores' "$DEFAULT_CORES")"
PORT="$(ask 'Dashboard port' "$DEFAULT_PORT")"
LANGUAGE="$(ask 'Default language (de/en)' 'de')"

while true; do
  FEED_IN_CT="$(ask 'Feed-in compensation in ct/kWh (example: 7.5 or 7,5)' '0')"
  FEED_IN_CT="${FEED_IN_CT//,/.}"
  if [[ "$FEED_IN_CT" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
    break
  fi
  echo "Use a positive number such as 7.5 or 7,5. The value is stored as ct/kWh." >&2
done

DEVICE_ID="$(ask 'Home Assistant device ID' 'home')"
API_KEY="$(ask_secret 'Home Assistant API key')"
ADMIN_PASSWORD="$(ask_secret_confirm 'PenguinPVDash admin password')"
read -r -s -p 'PenguinPVDash guest password (empty = public read-only): ' GUEST_PASSWORD
echo >&2
GUEST_STATS="$(ask_bool 'May guests view statistics? (y/n)' 'y')"
GUEST_COMP="$(ask_bool 'May guests view feed-in compensation? (y/n)' 'n')"

ENABLE_ROOT_LOGIN="$(ask_bool 'Enable an LXC root password for Proxmox console login? (y/n)' 'n')"
ROOT_PASSWORD=""
ENABLE_SSH="false"
if [[ "$ENABLE_ROOT_LOGIN" == "true" ]]; then
  ROOT_PASSWORD="$(ask_secret_confirm 'LXC root password')"
  ENABLE_SSH="$(ask_bool 'Enable SSH login as root with this password? (y/n)' 'n')"
fi

if [[ -z "$API_KEY" || -z "$ADMIN_PASSWORD" ]]; then
  echo "API key and PenguinPVDash admin password must not be empty." >&2
  exit 1
fi
if pct status "$VMID" >/dev/null 2>&1; then
  echo "Container ID $VMID already exists." >&2
  exit 1
fi

for value_name in API_KEY ADMIN_PASSWORD GUEST_PASSWORD ROOT_PASSWORD; do
  value="${!value_name}"
  if [[ "$value" == *$'\n'* || "$value" == *$'\r'* ]]; then
    echo "$value_name must not contain line breaks." >&2
    exit 1
  fi
done

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

NET0="name=eth0,bridge=$BRIDGE,type=veth"
if [[ "$IP_MODE" == "dhcp" ]]; then
  NET0+=",ip=dhcp"
else
  NET0+=",ip=$STATIC_IP,gw=$GATEWAY"
fi
if [[ -n "$VLAN_TAG" ]]; then
  NET0+=",tag=$VLAN_TAG"
fi

PCT_CREATE_ARGS=(
  create "$VMID" "$TEMPLATE_PATH"
  --hostname "$HOSTNAME"
  --ostype debian
  --unprivileged 1
  --features nesting=1,keyctl=1
  --cores "$CORES"
  --memory "$MEMORY"
  --swap 512
  --rootfs "$STORAGE:$DISK"
  --net0 "$NET0"
  --onboot 1
  --start 1
)

if [[ "$ENABLE_ROOT_LOGIN" == "true" ]]; then
  PCT_CREATE_ARGS+=(--password "$ROOT_PASSWORD")
fi
if [[ -n "$DNS_SERVER" ]]; then
  PCT_CREATE_ARGS+=(--nameserver "$DNS_SERVER")
fi

echo "Creating LXC $VMID..."
pct "${PCT_CREATE_ARGS[@]}"

for _ in {1..30}; do
  pct exec "$VMID" -- true >/dev/null 2>&1 && break
  sleep 2
done

PACKAGES="ca-certificates curl"
if [[ "$ENABLE_SSH" == "true" ]]; then
  PACKAGES+=" openssh-server"
fi

pct exec "$VMID" -- bash -lc "export DEBIAN_FRONTEND=noninteractive; apt-get update; apt-get install -y $PACKAGES; curl -fsSL https://get.docker.com | sh; systemctl enable --now docker"

if [[ "$ENABLE_ROOT_LOGIN" != "true" ]]; then
  pct exec "$VMID" -- bash -lc 'passwd -l root >/dev/null 2>&1 || true'
fi

if [[ "$ENABLE_SSH" == "true" ]]; then
  pct exec "$VMID" -- bash -lc "install -d -m 0755 /etc/ssh/sshd_config.d; printf '%s\n' 'PermitRootLogin yes' 'PasswordAuthentication yes' > /etc/ssh/sshd_config.d/99-penguinpvdash-root.conf; chmod 0644 /etc/ssh/sshd_config.d/99-penguinpvdash-root.conf; systemctl enable --now ssh"
fi

pct exec "$VMID" -- mkdir -p "/opt/$APP_SLUG/data"

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
    'feed_in_ct' => $FEED_IN_CT,
    'default_device' => $(php_quote "$DEVICE_ID"),
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
chmod 0640 "$TMP_DIR/config.local.php"

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
      PVDASH_RUNTIME_SETTINGS: /var/lib/penguinpvdash/settings.json
    volumes:
      - ./data:/var/lib/penguinpvdash
      - ./config.local.php:/var/www/html/inc/config.local.php:ro
COMPOSE

pct push "$VMID" "$TMP_DIR/config.local.php" "/opt/$APP_SLUG/config.local.php" --perms 0640
pct push "$VMID" "$TMP_DIR/docker-compose.yml" "/opt/$APP_SLUG/docker-compose.yml" --perms 0644

# The PHP process inside the Docker container runs as www-data (GID 33).
# Keep secrets private while allowing the read-only bind mount to be read.
pct exec "$VMID" -- chown 0:33 "/opt/$APP_SLUG/config.local.php"
pct exec "$VMID" -- bash -lc "cd /opt/$APP_SLUG && docker compose pull && docker compose up -d"

IP=""
for _ in {1..30}; do
  IP="$(pct exec "$VMID" -- hostname -I 2>/dev/null | awk '{print $1}')"
  [[ -n "$IP" ]] && break
  sleep 2
done

echo
echo "$APP installed successfully."
echo "URL: http://${IP:-CONTAINER-IP}:$PORT"
echo "LXC ID: $VMID"
echo "Configuration: /opt/$APP_SLUG/config.local.php"
echo "Docker Compose: /opt/$APP_SLUG/docker-compose.yml"
echo "Data: /opt/$APP_SLUG/data"
if [[ -n "$VLAN_TAG" ]]; then
  echo "VLAN tag: $VLAN_TAG"
fi
if [[ "$IP_MODE" == "static" ]]; then
  echo "Static IPv4: $STATIC_IP"
else
  echo "IPv4 mode: DHCP"
fi
if [[ "$ENABLE_ROOT_LOGIN" == "true" ]]; then
  echo "LXC root password login: enabled"
else
  echo "LXC root password login: disabled"
  echo "Administrative access remains available from the Proxmox host with: pct enter $VMID"
fi
if [[ "$ENABLE_SSH" == "true" ]]; then
  echo "SSH root login: enabled"
  echo "SSH command: ssh root@${IP:-CONTAINER-IP}"
else
  echo "SSH root login: disabled"
fi
