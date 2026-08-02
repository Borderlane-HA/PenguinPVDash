# Changelog

## 1.6.2

- Added administrator-only SQLite export from the web interface.
- Exports are created as consistent standalone snapshots and include active WAL data.
- Added administrator-only SQLite import with CSRF protection, upload limits, header validation, integrity checking and schema validation.
- Imports reject SQLite triggers, migrate older PenguinPVDash schemas and atomically replace the active database.
- A server-side backup is created automatically before every import; the latest five backups are retained.
- Added a database maintenance lock so ingest, reads, exports and imports cannot modify SQLite concurrently.
- Added 512 MB upload limits to the Docker PHP configuration and a `.user.ini` fallback for compatible classic hosting.

## 1.6.1

- Added optional VLAN tagging to the Proxmox LXC installer.
- Added DHCP or static IPv4 configuration, including gateway and DNS prompts.
- Added optional LXC root password login and optional SSH root login.
- Clarified that host-side `pct enter`/`pct exec` access remains available without a guest root password.
- Feed-in compensation now accepts both comma and decimal-point input and is stored as a numeric ct/kWh value.
- Fixed `config.local.php` permissions for the read-only Docker bind mount (`root:www-data`, mode `0640`).

## 1.6.0

- Removed the separate gross-consumption display. `consumption` now always means the sensor selected in Home Assistant.
- Added administrator and optional guest access.
- Added independent guest permissions for statistics and feed-in compensation.
- Added an administrator editor for correcting, adding, locking, unlocking and deleting daily totals.
- Manually locked daily totals are protected from automatic ingest and rebuild jobs.
- Split private, advanced and runtime configuration into cleaner files.
- Added Docker, Docker Compose, GHCR publishing workflow and a Proxmox VE LXC installer.
- Added health endpoint and additional protection for sensitive server folders.
