## 1.8.8

- Restored a compact battery symbol inside the same green square tile used by the other modern flow nodes.
- Removed the full-square battery bars and centered the small battery glyph precisely inside the tile.
- Kept the state-of-charge fill and percentage inside the compact battery symbol.

## 1.8.7

- Fine-tuned the modern battery tile for a more symmetrical look, especially in the blue theme.
- Set the modern energy-flow view as the default and renamed the classic variant to Flow1.
- Kept the modern theme label as Modern while preserving both selectable flow designs.

## 1.8.6

- Centered the modern flow icons more precisely inside their green square tiles for a cleaner, more symmetrical look.
- Reworked the battery tile again so it now uses the same square size as the other three tiles, with the square itself acting as the battery fill indicator.
- Improved light-theme readability for the daily summary labels and values.

## 1.8.5

- Improved icon alignment in the modern energy-flow tiles so the symbols sit centered more consistently.
- Reworked the battery tile so the battery rectangle itself acts more clearly as the filling state indicator.
- Increased contrast of the daily summary headings in the light theme for better readability.

## 1.8.4

- Fixed the modern energy-flow node positioning so the Home and Battery tiles stay anchored at the bottom as intended.
- Reduced the background opacity of the modern node panels for a cleaner, lighter appearance.
- Minor CSS cleanup for the modern flow layout across desktop and mobile breakpoints.

## 1.8.3

- Refined the modern energy-flow diagram again with clearer node placement and improved visual balance.
- Moved the Home node to the bottom-right position consistently and reduced the visual weight of the value labels.
- Softened the node background panels, centered the icons more cleanly, and redesigned the battery tile to act as a filling battery indicator.

## 1.8.2

- Refined the optional modern energy-flow diagram to match the requested E3/DC-style 4-corner layout more closely.
- Simplified the modern node labels to PV, Grid, Battery and Home, with cleaner squared cards and clearer directional arrows.
- Kept animated flow bars on the active paths so charging, discharging, import and export directions remain visible at a glance.

## 1.8.1

- Fixed battery discharge/charge ratio outliers on days without meaningful charging.
- Daily ratios below 0.05 kWh charge are now shown as unavailable and omitted from the chart.
- Reworked the optional modern energy-flow diagram with directional arrows, moving flow bars and daily autonomy in the center.
- Renamed the classic flow option to simply “Standard”.

## 1.8.0

- Added a statistics submenu with general statistics, battery analysis, and autonomy/self-consumption analysis.
- Added range-aware battery KPIs, tables, and charts, including configurable battery capacity and equivalent full-cycle estimates.
- Added weighted autonomy and self-consumption KPIs, daily tables, trend charts, and energy-distribution charts.
- Added a second modern energy-flow visualization while keeping the existing diagram as the default.
- Added an administrator setting to switch between the standard and modern flow diagram.

## 1.7.7

- Added sticky column headers to the 30-day dashboard table and the statistics table.
- Long tables now scroll inside a viewport-sized area, keeping column names visible while preserving horizontal scrolling on small screens.

## 1.7.6

- Improved energy-flow contrast in the light theme.
- Custom header logos now keep their aspect ratio and can use up to 180 px width.
- Fixed Proxmox SSH setup so optional root password login is applied before conflicting cloud defaults, the SSH service is restarted, and the effective configuration is validated during installation.

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
