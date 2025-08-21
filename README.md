
# PenguinPVDash (HACS)
Home Assistant → PHP Dashboard (öffentlich) für PV + Batterie (ohne Verbrauch).
- Zeitraum: Heute + bis zu 7 Tage zurück
- Konfigurierbares Intervall (Standard 600s)
- Auth-Modi: `api_key` **oder** `hmac_sha256` (Header-Signatur)

## Auth
- **api_key**: Header `X-Api-Key`
- **hmac_sha256**: Header `X-Timestamp` (ms) und `X-Signature = hex(hmac_sha256(secret, f"{ts}.{body_json}"))`
