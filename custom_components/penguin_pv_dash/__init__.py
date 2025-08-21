from __future__ import annotations

import base64
import hashlib
import hmac
import json
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
    CONF_BATT_POWER_ENTITY,
    CONF_BATT_SOC_ENTITY,
    CONF_FEEDIN_ENTITY,
    CONF_CONSUMPTION_ENTITY,
    CONF_GRID_IMPORT_ENTITY,
    DEFAULT_INTERVAL,
    DEFAULT_OUTPUT_UNIT,
    DECIMALS,
    HEADER_SIG,
    HEADER_TS,
    HEADER_DEV,
)

PLATFORMS: list = []  # No entities; sender-only

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
        # assume already kW or unknown -> pass-through
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

async def async_setup(hass: HomeAssistant, config: ConfigType) -> bool:
    return True

async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    data = {**entry.data, **entry.options}

    server_url = data.get(CONF_SERVER_URL)  # may be None (server script later)
    api_key = (data.get(CONF_API_KEY) or "").strip()
    device_id = data.get(CONF_DEVICE_ID) or hass.config.location_name or "ha"
    interval_min = int(data.get(CONF_INTERVAL, DEFAULT_INTERVAL))
    output_unit = data.get(CONF_OUTPUT_UNIT, DEFAULT_OUTPUT_UNIT)

    # Entities
    pv_entity = data.get(CONF_PV_ENTITY)
    batt_power_entity = data.get(CONF_BATT_POWER_ENTITY)
    batt_soc_entity = data.get(CONF_BATT_SOC_ENTITY)
    feedin_entity = data.get(CONF_FEEDIN_ENTITY)
    consumption_entity = data.get(CONF_CONSUMPTION_ENTITY)
    grid_import_entity = data.get(CONF_GRID_IMPORT_ENTITY)

    session = aiohttp_client.async_get_clientsession(hass)

    async def _send_once(now=None):
        # If no server URL yet, do nothing
        if not server_url:
            return

        states = hass.states

        def get_val(eid):
            if not eid:
                return None
            st = states.get(eid)
            val = _safe_float(st)
            unit_in = st.attributes.get("unit_of_measurement") if st else None
            return _convert_to_output_unit(val, unit_in, output_unit)

        # Build payload with only present keys
        payload = {"ts": int(datetime.utcnow().timestamp())}

        pv = _round_n(get_val(pv_entity), DECIMALS)
        if pv is not None:
            payload["pv_power"] = pv

        batt_kw = _round_n(get_val(batt_power_entity), DECIMALS)
        if batt_kw is not None:
            payload["battery_power"] = batt_kw

        batt_soc = _round_n(_safe_float(states.get(batt_soc_entity)), DECIMALS) if batt_soc_entity else None
        if batt_soc is not None:
            payload["battery_soc"] = batt_soc

        feedin = _round_n(get_val(feedin_entity), DECIMALS)
        if feedin is not None:
            payload["feed_in"] = feedin

        consumption = _round_n(get_val(consumption_entity), DECIMALS)
        if consumption is not None:
            payload["consumption"] = consumption

        grid_import = _round_n(get_val(grid_import_entity), DECIMALS)
        if grid_import is not None:
            payload["grid_import"] = grid_import

        # If only timestamp (no metrics), skip sending
        if len(payload) == 1:
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
                await resp.text()
        except Exception as e:
            hass.logger.warning("penguin_pvdash send failed: %s", e)

    # Send immediately once (if URL provided), then on interval
    await _send_once()

    @callback
    def _reschedule(*args):
        reg = hass.data[DOMAIN][entry.entry_id]
        if reg.get("unsub"):
            reg["unsub"]()
        minutes = int(entry.options.get(CONF_INTERVAL, entry.data.get(CONF_INTERVAL, DEFAULT_INTERVAL)))
        reg["unsub"] = async_track_time_interval(hass, _send_once, timedelta(minutes=minutes))

    unsub = async_track_time_interval(hass, _send_once, timedelta(minutes=interval_min))

    hass.data.setdefault(DOMAIN, {})[entry.entry_id] = {"unsub": unsub, "reschedule": _reschedule}

    entry.async_on_unload(entry.add_update_listener(_options_updated))
    return True

async def _options_updated(hass: HomeAssistant, entry: ConfigEntry):
    data = hass.data.get(DOMAIN, {}).get(entry.entry_id)
    if data and data.get("reschedule"):
        data["reschedule"]()

async def async_unload_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    data = hass.data.get(DOMAIN, {}).pop(entry.entry_id, None)
    if data and data.get("unsub"):
        data["unsub"]()
    return True