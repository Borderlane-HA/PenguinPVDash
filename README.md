# PenguinPVDash

PenguinPVDash publishes photovoltaic and energy data from Home Assistant to a lightweight, externally hosted dashboard. The Home Assistant custom integration sends the selected sensors to the PHP server at a configurable interval. Multiple independently editable server instances are supported.

> Version 1.6.0 introduces administrator/guest access, editable daily totals, Docker and Proxmox deployment, and one consistent **Consumption** value supplied by Home Assistant.

## Features

- Live PV, consumption, battery and grid flow
- Daily totals, 30-day history and extended statistics
- Optional feed-in compensation display
- Administrator and optional guest password
- Independent guest permissions for statistics and compensation
- Admin editor for adding, correcting, locking, unlocking and deleting daily totals
- Manual corrections are protected from later Home Assistant updates
- Multiple Home Assistant integration instances and server targets
- Classic PHP hosting, Docker Compose and Proxmox VE LXC deployment
- German and English interface

## Important consumption change

The server no longer calculates or displays a separate “gross consumption” value. `consumption` and `consumption_total_kwh` are displayed exactly as supplied by Home Assistant.

For systems with an additional balcony PV system, create the correct combined consumption sensor in Home Assistant and select that sensor in the PenguinPVDash integration. Users without this special setup can select their normal consumption sensors.

## 1. Install the Home Assistant integration

1. Open **HACS → Integrations → Custom repositories**.
2. Add `https://github.com/Borderlane-HA/PenguinPVDash` as an **Integration** repository.
3. Install PenguinPVDash and restart Home Assistant.
4. Open **Settings → Devices & services → Add integration → PenguinPVDash**.
5. Enter the complete endpoint URL, including `/api/ingest.php`, the API key, device ID and the desired sensors.

The API key and device ID must match the server configuration. Additional server targets can be added as separate integration entries. Every entry can later be renamed and fully edited.

## 2A. Docker Compose

The prebuilt image becomes available at `ghcr.io/borderlane-ha/penguinpvdash:latest` after the repository workflow has published it. For public Proxmox installations, set the generated GitHub package visibility to public.

```bash
git clone https://github.com/Borderlane-HA/PenguinPVDash.git
cd PenguinPVDash
cp .env.example .env
nano .env
docker compose up -d --build
```

Dashboard URL with the default configuration:

```text
http://SERVER-IP:8092
```

Persistent data is stored in `./data` outside the web root.

### Essential `.env` settings

```dotenv
PVDASH_ADMIN_PASSWORD=replace-with-a-long-admin-password
PVDASH_GUEST_PASSWORD=
PVDASH_GUEST_CAN_VIEW_STATS=true
PVDASH_GUEST_CAN_VIEW_COMPENSATION=false
PVDASH_FEED_IN_CT=10.45
PVDASH_API_KEYS_JSON={"home":"same-api-key-as-in-home-assistant"}
```

An empty guest password makes the dashboard publicly readable. Administration always requires the admin password.

Update the container with:

```bash
docker compose pull
docker compose up -d
```

## 2B. Proxmox VE LXC installer

Run the installer as `root` on the Proxmox VE host:

```bash
bash -c "$(curl -fsSL https://raw.githubusercontent.com/Borderlane-HA/PenguinPVDash/main/scripts/proxmox-lxc-install.sh)"
```

The script creates an unprivileged Debian 12 LXC, installs Docker, asks for the dashboard and Home Assistant credentials, and starts PenguinPVDash. Configuration and SQLite data remain inside `/opt/penguinpvdash` in the container.

The GHCR package must already have been published before the installer can pull it.

## 2C. Classic PHP web hosting

Requirements:

- PHP 8.1 or newer
- PDO SQLite
- write access to `SERVER/data`
- Apache or another web server with equivalent protection for `inc`, `tools` and SQLite files

Copy the contents of `SERVER` to the web directory. Then create the private configuration:

```bash
cd SERVER/inc
cp config.local.example.php config.local.php
nano config.local.php
```

Minimal example:

```php
<?php
return [
    'timezone' => 'Europe/Berlin',
    'language' => 'de',
    'feed_in_ct' => 10.45,

    'admin_password' => 'replace-with-a-long-admin-password',
    'guest_password' => '',
    'guest_can_view_stats' => true,
    'guest_can_view_compensation' => false,

    'require_ingest_auth' => true,
    'api_keys' => [
        'home' => 'same-api-key-as-in-home-assistant',
    ],
];
```

`config.local.php` and SQLite databases are ignored by Git and must never be committed.

Passwords may be plain strings or PHP password hashes. A hash can be generated with:

```bash
php -r "echo password_hash('YOUR-PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
```

## Upgrade from 1.5.x

1. Back up the existing SQLite database and server configuration.
2. Replace the application files, but keep the database.
3. Do not copy private values back into `inc/config.php`. Instead create `inc/config.local.php` from `config.local.example.php`.
4. Configure the admin password, optional guest password, guest permissions, compensation rate and API keys.
5. Open the dashboard once. Database columns for manual corrections are added automatically.

The old compensation access code is no longer used. Compensation is always visible to administrators and is controlled for guests by `guest_can_view_compensation`.

When migrating an existing classic installation to Docker, copy `pvdash.sqlite` into the Docker `./data` directory before starting the container. Stop the old installation first so the SQLite copy is consistent.

## Access roles

### Administrator

The administrator can:

- view the dashboard and statistics
- always see feed-in compensation without a second password
- add or correct historical daily totals
- lock corrected entries against automatic overwrite
- unlock entries for automatic updates
- delete incorrect entries

### Guest

The guest is read-only. Configuration decides independently whether guests may:

- open extended statistics
- see calculated feed-in compensation

Set `guest_password` to an empty string for public read-only access, or configure a password for a protected guest view.

## Correcting missing or incorrect days

Sign in as administrator and open **Edit data**.

A daily entry can be added or changed after the values have settled, for example on the following day. This also allows gaps caused by a server outage to be filled later. Saving a row locks it; future ingest requests and rebuild jobs leave that row unchanged. **Enable automatic updates** removes the lock.

The editor changes daily totals only. Raw minute samples remain untouched.

## API endpoint

Home Assistant must send to the exact endpoint:

```text
https://your-domain.example/path/api/ingest.php
```

For multiple devices or installations, configure a separate key for each device:

```php
'api_keys' => [
    'home' => 'first-key',
    'garage' => 'second-key',
],
```

For Docker, the equivalent one-line JSON value is:

```dotenv
PVDASH_API_KEYS_JSON={"home":"first-key","garage":"second-key"}
```

## Backup

Back up the SQLite file and configuration:

- Docker/Proxmox: data directory plus configuration or `.env`
- classic hosting: `SERVER/data/pvdash.sqlite` and `SERVER/inc/config.local.php`

When SQLite WAL mode is active, stop the container or web service before copying the database, or include the `-wal` and `-shm` files in a consistent backup.

## Maintenance

Automatically managed daily totals can be rebuilt from raw samples. Manually locked rows are preserved:

```bash
php SERVER/tools/rebuild_daily.php home
```

## Status

PenguinPVDash is still under active development. Testing, issue reports and feedback are welcome.
