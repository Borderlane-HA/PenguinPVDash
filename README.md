# PenguinPVDash (v0.3.0)

Home Assistant custom integration to periodically send PV / Battery / Grid metrics to an external server via HTTP.

## What changed in v0.3.0
- Fixed logger (use Home Assistant logger correctly)
- Normalize Server URL (automatically adds `https://` if scheme is missing)
- Split Battery into **two sensors**: charge and discharge
- Keep 2-decimal rounding; send only populated keys

## Entities (all optional; pick what you have)
- PV power
- Battery charge (separate sensor)
- Battery discharge (separate sensor)
- Battery SoC (%)
- Feed-in (to grid)
- Current consumption
- Current grid import

## Notes
- If `server_url` is empty or invalid, nothing is sent.
- If `api_key` is set, HMAC header is attached.