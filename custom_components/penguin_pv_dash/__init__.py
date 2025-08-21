from __future__ import annotations

import json
import logging
from datetime import timedelta, datetime, timezone
import aiohttp

from homeassistant.core import HomeAssistant, callback
from homeassistant.config_entries import ConfigEntry
from homeassistant.helpers.typing import ConfigType
from homeassistant.helpers.event import async_track_time_interval

from .const import (
    DOMAIN,
    CONF_SERVER_URL,
    CONF_API_KEY,
    CONF_DEVICE_ID,
    CONF_INTERVAL,
    CONF_OUTPUT_UNIT,
    CONF_PV_ENTITY,
    CONF_BATT_SOC_ENTITY,
    CONF_FEEDIN_ENTITY,
    CONF_CONSUMPTION_ENTITY,
    CONF_GRID_IMPORT_ENTITY,
    CONF_BATT_CHARGE_ENTITY,
    CONF_BATT_DISCHARGE_ENTITY,
    CONF_PV_TOTAL_KWH_ENTITY,
    CONF_FEEDIN_TOTAL_KWH_ENTITY,
    CONF_BATT_IN_TOTAL_KWH_ENTITY,
    CONF_BATT_OUT_TOTAL_KWH_ENTITY,
    DEFAULT_INTERVAL,
    HEADER_SIG, HEADER_TS, HEADER_DEV,
)

_LOGGER = logging.getLogger(__name__)

PLATFORMS: list = []

async def async_setup(hass: HomeAssistant, config: ConfigType) -> bool:
    return True

async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    session = aiohttp.ClientSession()

    def opt(key, default=None):
        return entry.options.get(key, entry.data.get(key, default))

    server_url = str(opt(CONF_SERVER_URL))
    api_key    = opt(CONF_API_KEY, "")
    device_id  = opt(CONF_DEVICE_ID, "home")
    interval   = int(opt(CONF_INTERVAL, DEFAULT_INTERVAL))
    unit       = (opt(CONF_OUTPUT_UNIT, "kW") or "kW").strip()

    async def _send_once(now=None):
        # collect states
        def _get(eid):
            if not eid:
                return None
            st = hass.states.get(eid)
            if not st:
                return None
            try:
                return float(st.state)
            except Exception:
                return None

        payload = {
            "ts": int(datetime.now(timezone.utc).timestamp()),
            "device": device_id,
            "unit": unit,
        }

        # power-like (kW or W depending on 'unit')
        payload.update({
            "pv_power": _get(opt(CONF_PV_ENTITY)),
            "battery_charge": _get(opt(CONF_BATT_CHARGE_ENTITY)),
            "battery_discharge": _get(opt(CONF_BATT_DISCHARGE_ENTITY)),
            "feed_in": _get(opt(CONF_FEEDIN_ENTITY)),
            "consumption": _get(opt(CONF_CONSUMPTION_ENTITY)),
            "grid_import": _get(opt(CONF_GRID_IMPORT_ENTITY)),
            "battery_soc": _get(opt(CONF_BATT_SOC_ENTITY)),
        })

        # daily totals (kWh) optional
        payload.update({
            "pv_total_kwh": _get(opt(CONF_PV_TOTAL_KWH_ENTITY)),
            "feed_in_total_kwh": _get(opt(CONF_FEEDIN_TOTAL_KWH_ENTITY)),
            "batt_in_total_kwh": _get(opt(CONF_BATT_IN_TOTAL_KWH_ENTITY)),
            "batt_out_total_kwh": _get(opt(CONF_BATT_OUT_TOTAL_KWH_ENTITY)),
        })

        # strip None to reduce payload
        body = {k: v for k, v in payload.items() if v is not None}
        data = json.dumps(body, separators=(",", ":"), ensure_ascii=False)

        headers = {}
        ts = str(body["ts"])
        headers[HEADER_TS] = ts
        headers[HEADER_DEV] = device_id
        if api_key:
            import hmac, hashlib, base64
            sig = hmac.new(api_key.encode("utf-8"), (ts + "." + data).encode("utf-8"), hashlib.sha256).digest()
            headers[HEADER_SIG] = base64.b64encode(sig).decode("ascii")

        try:
            async with session.post(server_url, data=data, headers=headers, timeout=10) as resp:
                if resp.status >= 300:
                    _LOGGER.warning("PenguinPVDash: server responded %s", resp.status)
        except Exception as e:
            _LOGGER.warning("PenguinPVDash: post failed: %s", e)

    # schedule
    unsub = async_track_time_interval(hass, _send_once, timedelta(minutes=interval))

    async def _options_updated(hass: HomeAssistant, entry: ConfigEntry):
        # reschedule with new interval or target
        unsub()
        new_interval = int(opt(CONF_INTERVAL, DEFAULT_INTERVAL))
        async_track_time_interval(hass, _send_once, timedelta(minutes=new_interval))

    entry.async_on_unload(entry.add_update_listener(_options_updated))
    hass.data.setdefault(DOMAIN, {})[entry.entry_id] = {"unsub": unsub}
    return True

async def async_unload_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    data = hass.data.get(DOMAIN, {}).pop(entry.entry_id, None)
    if data and data.get("unsub"):
        data["unsub"]()
    return True