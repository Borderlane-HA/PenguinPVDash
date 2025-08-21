from __future__ import annotations

import base64
import hashlib
import hmac
import json
import logging
from urllib.parse import urlparse
from datetime import datetime, timedelta

from homeassistant.core import HomeAssistant, callback
from homeassistant.config_entries import ConfigEntry
from homeassistant.helpers.typing import ConfigType
from homeassistant.helpers import aiohttp_client
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
    DEFAULT_INTERVAL,
    DEFAULT_OUTPUT_UNIT,
    DECIMALS,
    HEADER_SIG,
    HEADER_TS,
    HEADER_DEV,
)

_LOGGER = logging.getLogger(__name__)

PLATFORMS: list = []  # sender-only

def _safe_float(state_obj):
    if not state_obj:
        return None
    s = state_obj.state
    if s in (None, "", "unknown", "unavailable"):
        return None
    try:
        return float(s)
    except Exception:
        return None

def _convert_to_output_unit(value: float | None, unit_in: str | None, output: str) -> float | None:
    if value is None:
        return None
    unit_in = (unit_in or "").strip()
    if output == "kW":
        if unit_in == "W":
            return value / 1000.0
        return value
    elif output == "W":
        if unit_in == "kW":
            return value * 1000.0
        return value
    return value

def _round_n(v, n):
    try:
        return None if v is None else round(float(v), n)
    except Exception:
        return None

def _normalize_url(url: str | None) -> str | None:
    if not url:
        return None
    u = str(url).strip()
    if not u.lower().startswith(("http://", "https://")):
        u = "https://" + u
    parsed = urlparse(u)
    if not parsed.scheme or not parsed.netloc:
        return None
    return u

async def async_setup(hass: HomeAssistant, config: ConfigType) -> bool:
    return True

async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    session = aiohttp_client.async_get_clientsession(hass)

    def opt(key, default=None):
        return entry.options.get(key, entry.data.get(key, default))

    async def _send_once(now=None):
        server_url = _normalize_url(opt(CONF_SERVER_URL))
        if not server_url:
            _LOGGER.debug("penguin_pvdash: server_url not set, skip sending")
            return

        api_key = (entry.data.get(CONF_API_KEY) or "").strip()
        device_id = entry.data.get(CONF_DEVICE_ID) or hass.config.location_name or "ha"
        output_unit = opt(CONF_OUTPUT_UNIT, DEFAULT_OUTPUT_UNIT)

        states = hass.states

        def get_val(eid):
            if not eid:
                return None
            st = states.get(eid)
            val = _safe_float(st)
            unit_in = st.attributes.get("unit_of_measurement") if st else None
            return _convert_to_output_unit(val, unit_in, output_unit)

        payload = {"ts": int(datetime.utcnow().timestamp())}

        pv = _round_n(get_val(opt(CONF_PV_ENTITY)), DECIMALS)
        if pv is not None:
            payload["pv_power"] = pv

        batt_charge = _round_n(get_val(opt(CONF_BATT_CHARGE_ENTITY)), DECIMALS)
        if batt_charge is not None:
            payload["battery_charge"] = batt_charge

        batt_discharge = _round_n(get_val(opt(CONF_BATT_DISCHARGE_ENTITY)), DECIMALS)
        if batt_discharge is not None:
            payload["battery_discharge"] = batt_discharge

        batt_soc = _round_n(_safe_float(states.get(opt(CONF_BATT_SOC_ENTITY))), DECIMALS)
        if batt_soc is not None:
            payload["battery_soc"] = batt_soc

        feedin = _round_n(get_val(opt(CONF_FEEDIN_ENTITY)), DECIMALS)
        if feedin is not None:
            payload["feed_in"] = feedin

        consumption = _round_n(get_val(opt(CONF_CONSUMPTION_ENTITY)), DECIMALS)
        if consumption is not None:
            payload["consumption"] = consumption

        grid_import = _round_n(get_val(opt(CONF_GRID_IMPORT_ENTITY)), DECIMALS)
        if grid_import is not None:
            payload["grid_import"] = grid_import

        if len(payload) == 1:
            _LOGGER.debug("penguin_pvdash: no populated metrics for this cycle")
            return

        body = json.dumps(payload, separators=(",", ":")).encode("utf-8")

        headers = {
            "Content-Type": "application/json",
            HEADER_TS: str(payload["ts"]),
            HEADER_DEV: device_id,
        }
        if api_key:
            sig = base64.b64encode(hmac.new(api_key.encode("utf-8"), body, hashlib.sha256).digest()).decode("ascii")
            headers[HEADER_SIG] = sig

        try:
            async with session.post(server_url, data=body, headers=headers, timeout=15) as resp:
                txt = await resp.text()
                if resp.status >= 300:
                    _LOGGER.warning("penguin_pvdash: server responded %s: %s", resp.status, txt[:200])
        except Exception as e:
            _LOGGER.warning("penguin_pvdash send failed: %s", e)

    # schedule
    interval_min = int(opt(CONF_INTERVAL, DEFAULT_INTERVAL))
    await _send_once()

    @callback
    def _reschedule(*args):
        reg = hass.data[DOMAIN][entry.entry_id]
        if reg.get("unsub"):
            reg["unsub"]()
        minutes = int(opt(CONF_INTERVAL, DEFAULT_INTERVAL))
        reg["unsub"] = async_track_time_interval(hass, _send_once, timedelta(minutes=minutes))

    unsub = async_track_time_interval(hass, _send_once, timedelta(minutes=interval_min))
    hass.data.setdefault(DOMAIN, {})[entry.entry_id] = {"unsub": unsub, "reschedule": _reschedule}
    entry.async_on_unload(entry.add_update_listener(_options_updated))
    return True

async def _options_updated(hass: HomeAssistant, entry: ConfigEntry):
    data = hass.data.get(DOMAIN, {}).get(entry.entry_id)
    if data and data.get("reschedule"):
        data["reschedule"]()