# Changelog

## 1.6.0

- Removed the separate gross-consumption display. `consumption` now always means the sensor selected in Home Assistant.
- Added administrator and optional guest access.
- Added independent guest permissions for statistics and feed-in compensation.
- Added an administrator editor for correcting, adding, locking, unlocking and deleting daily totals.
- Manually locked daily totals are protected from automatic ingest and rebuild jobs.
- Split private, advanced and runtime configuration into cleaner files.
- Added Docker, Docker Compose, GHCR publishing workflow and a Proxmox VE LXC installer.
- Added health endpoint and additional protection for sensitive server folders.
