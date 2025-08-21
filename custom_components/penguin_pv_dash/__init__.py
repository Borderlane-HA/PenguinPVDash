from __future__ import annotations
import json, logging
from datetime import timedelta, datetime, timezone
import aiohttp
from homeassistant.core import HomeAssistant
from homeassistant.config_entries import ConfigEntry
from homeassistant.helpers.event import async_track_time_interval
from .const import *

_LOGGER = logging.getLogger(__name__)

async def async_setup(hass: HomeAssistant, config) -> bool:
    return True

async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    data = hass.data.setdefault(DOMAIN, {})
    state = {}
    data[entry.entry_id] = state

    def opt(key, default=None):
        return entry.options.get(key, entry.data.get(key, default))

    state["session"] = aiohttp.ClientSession()

    async def _send_once(now=None):
        server_url = str(opt(CONF_SERVER_URL))
        device_id  = opt(CONF_DEVICE_ID, "home")
        unit       = (opt(CONF_OUTPUT_UNIT, "kW") or "kW").strip()
        api_key    = opt(CONF_API_KEY, "")

        def _get(eid):
            if not eid: return None
            st = hass.states.get(eid)
            if not st: return None
            try: return float(st.state)
            except Exception: return None

        payload = {
            "ts": int(datetime.now(timezone.utc).timestamp()),
            "device": device_id,
            "unit": unit,
            "pv_power": _get(opt(CONF_PV_ENTITY)),
            "battery_charge": _get(opt(CONF_BATT_CHARGE_ENTITY)),
            "battery_discharge": _get(opt(CONF_BATT_DISCHARGE_ENTITY)),
            "feed_in": _get(opt(CONF_FEEDIN_ENTITY)),
            "consumption": _get(opt(CONF_CONSUMPTION_ENTITY)),
            "grid_import": _get(opt(CONF_GRID_IMPORT_ENTITY)),
            "battery_soc": _get(opt(CONF_BATT_SOC_ENTITY)),
            "pv_total_kwh": _get(opt(CONF_PV_TOTAL_KWH_ENTITY)),
            "feed_in_total_kwh": _get(opt(CONF_FEEDIN_TOTAL_KWH_ENTITY)),
            "batt_in_total_kwh": _get(opt(CONF_BATT_IN_TOTAL_KWH_ENTITY)),
            "batt_out_total_kwh": _get(opt(CONF_BATT_OUT_TOTAL_KWH_ENTITY)),
            "consumption_total_kwh": _get(opt(CONF_CONS_TOTAL_KWH_ENTITY)),
            "grid_import_total_kwh": _get(opt(CONF_IMPORT_TOTAL_KWH_ENTITY)),
        }
        body = {k:v for k,v in payload.items() if v is not None}
        data_json = json.dumps(body, separators=(",", ":"), ensure_ascii=False)

        headers = {HEADER_TS: str(body["ts"]), HEADER_DEV: device_id}
        if api_key:
            import hmac, hashlib, base64
            sig = hmac.new(api_key.encode("utf-8"), (headers[HEADER_TS] + "." + data_json).encode("utf-8"), hashlib.sha256).digest()
            headers[HEADER_SIG] = base64.b64encode(sig).decode("ascii")

        try:
            async with state["session"].post(server_url, data=data_json, headers=headers, timeout=10) as resp:
                if resp.status >= 300:
                    _LOGGER.warning("PenguinPVDash: server responded %s", resp.status)
        except Exception as e:
            _LOGGER.warning("PenguinPVDash: post failed: %s", e)

    interval = int(opt(CONF_INTERVAL, DEFAULT_INTERVAL))
    state["unsub"] = async_track_time_interval(hass, _send_once, timedelta(minutes=interval))

    async def _options_updated(hass: HomeAssistant, updated: ConfigEntry):
        if state.get("unsub"): state["unsub"]()
        new_interval = int(opt(CONF_INTERVAL, DEFAULT_INTERVAL))
        state["unsub"] = async_track_time_interval(hass, _send_once, timedelta(minutes=new_interval))

    entry.async_on_unload(entry.add_update_listener(_options_updated))
    return True

async def async_unload_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    state = hass.data.get(DOMAIN, {}).pop(entry.entry_id, {})
    if state.get("unsub"): state["unsub"]()
    if state.get("session"): await state["session"].close()
    return True