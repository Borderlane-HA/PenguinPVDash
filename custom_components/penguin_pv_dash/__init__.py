from __future__ import annotations

import base64
from datetime import datetime, timedelta, timezone
import hashlib
import hmac
import json
import logging
from typing import Any

from homeassistant.config_entries import ConfigEntry
from homeassistant.core import HomeAssistant
from homeassistant.helpers.aiohttp_client import async_get_clientsession
from homeassistant.helpers.event import async_track_time_interval

from .const import (
    CONF_API_KEY,
    CONF_BATT_CHARGE_ENTITY,
    CONF_BATT_DISCHARGE_ENTITY,
    CONF_BATT_IN_TOTAL_KWH_ENTITY,
    CONF_BATT_OUT_TOTAL_KWH_ENTITY,
    CONF_BATT_SOC_ENTITY,
    CONF_CONS_TOTAL_KWH_ENTITY,
    CONF_CONSUMPTION_ENTITY,
    CONF_DEVICE_ID,
    CONF_FEEDIN_ENTITY,
    CONF_FEEDIN_TOTAL_KWH_ENTITY,
    CONF_GRID_IMPORT_ENTITY,
    CONF_IMPORT_TOTAL_KWH_ENTITY,
    CONF_INTERVAL,
    CONF_OUTPUT_UNIT,
    CONF_PV_ENTITY,
    CONF_PV_TOTAL_KWH_ENTITY,
    CONF_SERVER_URL,
    DEFAULT_INTERVAL,
    DEFAULT_OUTPUT_UNIT,
    DOMAIN,
    HEADER_DEV,
    HEADER_SIG,
    HEADER_TS,
)

_LOGGER = logging.getLogger(__name__)


async def async_setup(hass: HomeAssistant, config: dict[str, Any]) -> bool:
    """Set up the integration namespace."""
    return True


async def async_setup_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    """Set up one PenguinPVDash config entry."""
    domain_data = hass.data.setdefault(DOMAIN, {})
    state: dict[str, Any] = {
        "entry": entry,
        "session": async_get_clientsession(hass),
    }
    domain_data[entry.entry_id] = state

    def option(key: str, default: Any = None) -> Any:
        """Read a setting, preferring editable options over initial data."""
        current_entry: ConfigEntry = state["entry"]
        return current_entry.options.get(
            key,
            current_entry.data.get(key, default),
        )

    def interval_minutes() -> int:
        """Return a safe sending interval."""
        try:
            return max(1, int(option(CONF_INTERVAL, DEFAULT_INTERVAL)))
        except (TypeError, ValueError):
            return DEFAULT_INTERVAL

    async def send_once(now: datetime | None = None) -> None:
        """Collect the configured states and send one payload."""
        server_url = str(option(CONF_SERVER_URL, "")).strip()
        device_id = str(option(CONF_DEVICE_ID, "home") or "home").strip()
        unit = str(option(CONF_OUTPUT_UNIT, DEFAULT_OUTPUT_UNIT) or DEFAULT_OUTPUT_UNIT).strip()
        api_key = str(option(CONF_API_KEY, "") or "")

        if not server_url:
            _LOGGER.warning("PenguinPVDash: no server URL configured")
            return

        def get_numeric_state(entity_id: str | None) -> float | None:
            """Read one configured entity as a numeric value."""
            if not entity_id:
                return None

            entity_state = hass.states.get(entity_id)
            if entity_state is None:
                return None

            try:
                return float(entity_state.state)
            except (TypeError, ValueError):
                return None

        payload = {
            "ts": int(datetime.now(timezone.utc).timestamp()),
            "device": device_id,
            "unit": unit,
            "pv_power": get_numeric_state(option(CONF_PV_ENTITY)),
            "battery_charge": get_numeric_state(option(CONF_BATT_CHARGE_ENTITY)),
            "battery_discharge": get_numeric_state(option(CONF_BATT_DISCHARGE_ENTITY)),
            "feed_in": get_numeric_state(option(CONF_FEEDIN_ENTITY)),
            "consumption": get_numeric_state(option(CONF_CONSUMPTION_ENTITY)),
            "grid_import": get_numeric_state(option(CONF_GRID_IMPORT_ENTITY)),
            "battery_soc": get_numeric_state(option(CONF_BATT_SOC_ENTITY)),
            "pv_total_kwh": get_numeric_state(option(CONF_PV_TOTAL_KWH_ENTITY)),
            "feed_in_total_kwh": get_numeric_state(
                option(CONF_FEEDIN_TOTAL_KWH_ENTITY)
            ),
            "batt_in_total_kwh": get_numeric_state(
                option(CONF_BATT_IN_TOTAL_KWH_ENTITY)
            ),
            "batt_out_total_kwh": get_numeric_state(
                option(CONF_BATT_OUT_TOTAL_KWH_ENTITY)
            ),
            "consumption_total_kwh": get_numeric_state(
                option(CONF_CONS_TOTAL_KWH_ENTITY)
            ),
            "grid_import_total_kwh": get_numeric_state(
                option(CONF_IMPORT_TOTAL_KWH_ENTITY)
            ),
        }
        body = {key: value for key, value in payload.items() if value is not None}
        data_json = json.dumps(
            body,
            separators=(",", ":"),
            ensure_ascii=False,
        )

        headers = {
            HEADER_TS: str(body["ts"]),
            HEADER_DEV: device_id,
        }
        if api_key:
            signature = hmac.new(
                api_key.encode("utf-8"),
                f"{headers[HEADER_TS]}.{data_json}".encode("utf-8"),
                hashlib.sha256,
            ).digest()
            headers[HEADER_SIG] = base64.b64encode(signature).decode("ascii")

        try:
            async with state["session"].post(
                server_url,
                data=data_json,
                headers=headers,
                timeout=10,
            ) as response:
                if response.status >= 300:
                    response_text = await response.text()
                    _LOGGER.warning(
                        "PenguinPVDash: server responded %s: %s",
                        response.status,
                        response_text[:300],
                    )
        except Exception as err:  # Network errors must not stop future sends.
            _LOGGER.warning("PenguinPVDash: post failed: %s", err)

    def schedule_sender() -> None:
        """Create or recreate the periodic sender."""
        if unsubscribe := state.get("unsub"):
            unsubscribe()

        state["unsub"] = async_track_time_interval(
            hass,
            send_once,
            timedelta(minutes=interval_minutes()),
        )

    async def options_updated(
        hass: HomeAssistant,
        updated_entry: ConfigEntry,
    ) -> None:
        """Apply edited URL, API key, entities and interval immediately."""
        state["entry"] = updated_entry
        schedule_sender()

    schedule_sender()
    entry.async_on_unload(entry.add_update_listener(options_updated))
    return True


async def async_unload_entry(hass: HomeAssistant, entry: ConfigEntry) -> bool:
    """Unload one PenguinPVDash config entry."""
    state = hass.data.get(DOMAIN, {}).pop(entry.entry_id, {})
    if unsubscribe := state.get("unsub"):
        unsubscribe()

    # The HTTP session is Home Assistant's shared session and must not be closed
    # by this custom integration.
    return True
