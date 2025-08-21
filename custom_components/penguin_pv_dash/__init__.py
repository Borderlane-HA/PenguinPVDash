
import asyncio
from datetime import timedelta, datetime, timezone
import logging
import aiohttp
import async_timeout
import json
import hmac
import hashlib

from homeassistant.core import HomeAssistant, callback
from homeassistant.config_entries import ConfigEntry
from homeassistant.const import EVENT_HOMEASSISTANT_STOP
from homeassistant.helpers.event import async_track_time_interval
from homeassistant.helpers.aiohttp_client import async_get_clientsession

from .const import (
    DOMAIN, CONF_SERVER_URL, CONF_PV_NOW, CONF_BATT_SOC, CONF_BATT_IN, CONF_BATT_OUT,
    CONF_INTERVAL, DEFAULT_INTERVAL, CONF_AUTH_MODE, AUTH_MODE_API_KEY, AUTH_MODE_HMAC,
    CONF_API_KEY, CONF_HMAC_SECRET
)

_LOGGER = logging.getLogger(__name__)

PLATFORMS: list[str] = []

async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    hass.data.setdefault(DOMAIN, {})

    server_url = entry.data[CONF_SERVER_URL].rstrip('/')
    pv_now_entity = entry.data[CONF_PV_NOW]
    batt_soc_entity = entry.data[CONF_BATT_SOC]
    batt_in_entity = entry.data[CONF_BATT_IN]
    batt_out_entity = entry.data[CONF_BATT_OUT]
    interval = entry.options.get(CONF_INTERVAL, entry.data.get(CONF_INTERVAL, DEFAULT_INTERVAL))

    auth_mode = entry.data.get(CONF_AUTH_MODE, AUTH_MODE_API_KEY)
    api_key = entry.data.get(CONF_API_KEY, "")
    hmac_secret = entry.data.get(CONF_HMAC_SECRET, "")

    session = async_get_clientsession(hass)

    async def _post_payload(now=None):
        def _get_float(entity_id):
            st = hass.states.get(entity_id)
            if st is None or st.state in (None, "unknown", "unavailable"):
                return None
            try:
                return float(st.state)
            except ValueError:
                return None

        def _get_soc(entity_id):
            val = _get_float(entity_id)
            if val is None:
                return None
            if val <= 1.0:
                return round(val * 100.0, 2)
            return round(val, 2)

        ts_ms = int(datetime.now(timezone.utc).timestamp() * 1000)
        payload = {
            "ts": ts_ms,
            "pv_now_w": _get_float(pv_now_entity),
            "battery_soc_percent": _get_soc(batt_soc_entity),
            "battery_in_w": _get_float(batt_in_entity),
            "battery_out_w": _get_float(batt_out_entity),
            "source": "homeassistant",
            "version": "1.1.0"
        }

        url = f"{server_url}/api/ingest.php"
        headers = {"Content-Type": "application/json"}

        if auth_mode == AUTH_MODE_API_KEY:
            headers["X-Api-Key"] = api_key
        elif auth_mode == AUTH_MODE_HMAC:
            # X-Timestamp + X-Signature = HMAC_SHA256(secret, f"{ts}.{json}")
            body = json.dumps(payload, separators=(',', ':'))
            headers["X-Timestamp"] = str(ts_ms)
            sig = hmac.new(hmac_secret.encode("utf-8"), f"{ts_ms}.{body}".encode("utf-8"), hashlib.sha256).hexdigest()
            headers["X-Signature"] = sig
        else:
            _LOGGER.warning("Unknown auth_mode: %s", auth_mode)

        try:
            async with async_timeout.timeout(15):
                async with session.post(url, json=payload, headers=headers) as resp:
                    if resp.status >= 400:
                        txt = await resp.text()
                        _LOGGER.warning("PenguinPVDash: Server responded %s: %s", resp.status, txt[:200])
                    else:
                        _LOGGER.debug("PenguinPVDash: Posted OK")
        except asyncio.TimeoutError:
            _LOGGER.warning("PenguinPVDash: Timeout posting to %s", url)
        except Exception as e:
            _LOGGER.warning("PenguinPVDash: Error posting to %s: %s", url, e)

    # Fire once on load
    hass.async_create_task(_post_payload())
    remove_interval = async_track_time_interval(hass, _post_payload, timedelta(seconds=interval))

    @callback
    def _on_stop(event):
        remove_interval()

    hass.bus.async_listen_once(EVENT_HOMEASSISTANT_STOP, _on_stop)
    hass.data[DOMAIN][entry.entry_id] = {"remove_interval": remove_interval}
    return True

async def async_unload_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    data = hass.data.get(DOMAIN, {}).pop(entry.entry_id, None)
    if data and "remove_interval" in data:
        data["remove_interval"]()
    return True
