## 1.7.5

- Replaced the optional `verify_server` switch with mandatory validation during initial setup.
- Split initial setup into server verification and sensor assignment.
- Connection changes are validated automatically; sensor-only edits remain possible while the server is offline.
- Corrected and simplified German and English config-flow translations.

# Changelog

## 1.7.4

- Added clear German and English labels for every Home Assistant entity mapping.
- Added explanatory help text for live power, battery and daily-energy sensors.
- Renamed ambiguous daily fields from “total” to “today” in the user interface.
- Added compatibility labels at section and step level to prevent raw keys such as `pv_entity` from being displayed.
- Other Home Assistant interface languages fall back to the complete English translation.

## 1.7.3

- Opens the integration configuration directly when editing an entry.
- Removes the intermediate options menu whose labels could appear blank.
- Server verification now runs whenever the verification checkbox is enabled and the form is saved.

## 1.7.2

- Home Assistant integration form grouped into server, PV/grid, battery and daily-energy sections.
- Server addresses can be entered without `/api/ingest.php`; the endpoint is normalized automatically.
- Added a non-writing server, device-ID and API-key connection test.
- Added a configurable dashboard default language and a DE/EN switch in the header.

## 1.7.1

- Fixed dark bands above and below light pages by applying theme variables to the document root.
- Corrected primary, navigation, checkbox and form colors in the light theme.
- Redesigned the header as separated rounded brand and navigation surfaces with improved logo spacing.
- Reduced the README to concise HACS, Docker and Proxmox setup and update instructions.

## 1.7.0

- Removed the obsolete “More / Open statistics” card from the main dashboard.
- Added one shared, consistently ordered navigation bar across dashboard, data management, statistics and settings.
- Renamed “Daily totals” to “Manage data”.
- Replaced the free-text device field in statistics with a dropdown of known device IDs.
- Added a SQLite device-data manager with default-device selection, device-data rename, optional target replacement and device-data deletion.
- Added an import option that renames a single imported device ID to the current default device.
- Added a list of automatic SQLite backups with download, restore and delete actions.
- Added configurable backup retention from 1 to 25 files.
- Added persistent custom logo and dashboard title settings.
- Added standard, dark and light themes, a configurable accent color and compact/comfortable table density.
- Added separately configurable minimum and maximum highlight colors for every energy column.
- Modernized cards, forms, tables, navigation and responsive layouts.

## 1.6.3

- Added an administrator settings page for device IDs and Home Assistant API keys.
- Multiple API credentials can be added, edited, renamed and removed independently.
- Added a selectable default dashboard device, allowing `home2` to stay active while imported `home` history remains available.
- Device renames can optionally migrate existing rows in `samples`, `daily_totals` and `integ_state`.
- Added administrator and guest password changes from the web interface; passwords are stored as secure hashes.
- Added guest statistics and compensation permissions plus feed-in compensation editing to the administrator interface.
- Added browser-side random API-key generation and non-reversible key fingerprints.
- Administrator changes are stored atomically in persistent `settings.json` and override initial environment or `config.local.php` values without a container restart.
- SQLite exports remain data-only and intentionally exclude API keys and passwords.

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
