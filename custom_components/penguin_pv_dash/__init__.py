from __future__ import annotations

import base64
import hashlib
import hmac
import json
import logging
from urllib.parse import urlparse
from datetime import timedelta, datetime, timezone

from homeassistant.core import HomeAssistant, callback
from homeassistant.config_entries import ConfigEntry
from homeassistant.helpers import aiohttp_client
from homeassistant.helpers.event import async_track_time_interval
from homeassistant.const import STATE_UNAVAILABLE, STATE_UNKNOWN

from .const import (
    DOMAIN,
    CONF_SERVER_URL, CONF_API_KEY, CONF_DEVICE_ID,
    CONF_INTERVAL, CONF_OUTPUT_UNIT,
    CONF_PV_ENTITY, CONF_BATT_SOC_ENTITY, CONF_FEEDIN_ENTITY,
    CONF_CONSUMPTION_ENTITY, CONF_GRID_IMPORT_ENTITY,
    CONF_BATT_CHARGE_ENTITY, CONF_BATT_DISCHARGE_ENTITY,
    CONF_PV_TOTAL_ENTITY, CONF_FEEDIN_TOTAL_ENTITY,
    CONF_BATT_IN_TOTAL_ENTITY, CONF_BATT_OUT_TOTAL_ENTITY,
    HEADER_SIG, HEADER_TS, HEADER_DEV,
    DEFAULT_INTERVAL,
)

_LOGGER = logging.getLogger(__name__)

async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry):
    """Set up scheduled sender."""
    # simple access helpers
    def opt(key, default=None):
        return entry.options.get(key, entry.data.get(key, default))

    server_url = opt(CONF_SERVER_URL)
    if not server_url:
        _LOGGER.error("PenguinPVDash: server_url missing in config entry")
        return False

    # normalize URL: allow direct /api/ingest.php or base path
    ingest_url = server_url
    if not ingest_url.lower().endswith("ingest.php"):
        ingest_url = server_url.rstrip("/") + "/api/ingest.php"

    session = aiohttp_client.async_get_clientsession(hass)

    @callback
    async def _send_once(now=None):
        payload = {
            "ts": int(datetime.now(tz=timezone.utc).timestamp()),
            "device": opt(CONF_DEVICE_ID, "home"),
            "unit": opt(CONF_OUTPUT_UNIT, "kW"),
        }

        def as_float(entity_id):
            if not entity_id:
                return None
            st = hass.states.get(entity_id)
            if not st:
                return None
            if st.state in (STATE_UNKNOWN, STATE_UNAVAILABLE, None, ""):
                return None
            try:
                return float(st.state)
            except (TypeError, ValueError):
                return None

        # instantaneous metrics
        payload["pv_power"]          = as_float(opt(CONF_PV_ENTITY))
        payload["battery_charge"]    = as_float(opt(CONF_BATT_CHARGE_ENTITY))
        payload["battery_discharge"] = as_float(opt(CONF_BATT_DISCHARGE_ENTITY))
        payload["battery_soc"]       = as_float(opt(CONF_BATT_SOC_ENTITY))
        payload["feed_in"]           = as_float(opt(CONF_FEEDIN_ENTITY))
        payload["consumption"]       = as_float(opt(CONF_CONSUMPTION_ENTITY))
        payload["grid_import"]       = as_float(opt(CONF_GRID_IMPORT_ENTITY))

        # daily totals (kWh), optional
        pv_tot   = as_float(opt(CONF_PV_TOTAL_ENTITY))
        feed_tot = as_float(opt(CONF_FEEDIN_TOTAL_ENTITY))
        bin_tot  = as_float(opt(CONF_BATT_IN_TOTAL_ENTITY))
        bout_tot = as_float(opt(CONF_BATT_OUT_TOTAL_ENTITY))
        if pv_tot is not None:   payload["pv_total_kwh"]       = pv_tot
        if feed_tot is not None: payload["feed_in_total_kwh"]  = feed_tot
        if bin_tot is not None:  payload["batt_in_total_kwh"]  = bin_tot
        if bout_tot is not None: payload["batt_out_total_kwh"] = bout_tot

        # Remove keys with only None to keep payload compact
        payload = {k:v for k,v in payload.items() if v is not None}

        raw = json.dumps(payload, separators=(",", ":"), ensure_ascii=False).encode("utf-8")

        headers = {"Content-Type": "application/json; charset=utf-8"}
        if opt(CONF_API_KEY):
            ts = str(int(datetime.now(tz=timezone.utc).timestamp()))
            sig = base64.b64encode(hmac.new(opt(CONF_API_KEY).encode("utf-8"), raw, hashlib.sha256).digest()).decode("ascii")
            headers[HEADER_SIG] = sig
            headers[HEADER_TS]  = ts
            headers[HEADER_DEV] = opt(CONF_DEVICE_ID, "home")

        try:
            async with session.post(ingest_url, data=raw, headers=headers, timeout=10) as resp:
                if resp.status >= 400:
                    text = await resp.text()
                    _LOGGER.warning("PVDash ingest failed: %s %s", resp.status, text)
        except Exception as e:
            _LOGGER.exception("Error sending to PenguinPVDash: %s", e)

    # schedule
    interval_min = int(opt(CONF_INTERVAL, DEFAULT_INTERVAL))
    unsub = async_track_time_interval(hass, _send_once, timedelta(minutes=interval_min))

    # allow reschedule when options change
    def _reschedule():
        nonlocal unsub
        if unsub:
            unsub()
        minutes = int(opt(CONF_INTERVAL, DEFAULT_INTERVAL))
        nonlocal_fn = _send_once  # to keep reference
        unsub = async_track_time_interval(hass, nonlocal_fn, timedelta(minutes=minutes))

    hass.data.setdefault(DOMAIN, {})[entry.entry_id] = {"unsub": unsub, "reschedule": _reschedule}
    entry.async_on_unload(entry.add_update_listener(_options_updated))
    return True

async def _options_updated(hass: HomeAssistant, entry: ConfigEntry):
    data = hass.data.get(DOMAIN, {}).get(entry.entry_id)
    if data and data.get("reschedule"):
        data["reschedule"]()
