from __future__ import annotations

from typing import Any

import voluptuous as vol

from homeassistant import config_entries
from homeassistant.config_entries import ConfigEntry
from homeassistant.core import callback
from homeassistant.data_entry_flow import FlowResult, section
from homeassistant.helpers import selector

from .api import (
    CannotConnect,
    InvalidAuth,
    InvalidServer,
    InvalidServerUrl,
    async_test_server,
    display_server_url,
    normalize_ingest_url,
)
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
    CONF_INSTANCE_NAME,
    CONF_INTERVAL,
    CONF_OUTPUT_UNIT,
    CONF_PV_ENTITY,
    CONF_PV_TOTAL_KWH_ENTITY,
    CONF_SERVER_URL,
    CONF_VERIFY_SERVER,
    DEFAULT_INSTANCE_NAME,
    DEFAULT_INTERVAL,
    DEFAULT_OUTPUT_UNIT,
    DOMAIN,
)

SECTION_CONNECTION = "connection"
SECTION_POWER = "power"
SECTION_BATTERY = "battery"
SECTION_ENERGY = "energy"

_ENTITY_FIELDS: tuple[str, ...] = (
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
    CONF_CONS_TOTAL_KWH_ENTITY,
    CONF_IMPORT_TOTAL_KWH_ENTITY,
)

# Optional fields need an explicit empty value in config-entry options when the
# user clears them. Otherwise a value stored in config-entry data remains active.
_CLEARABLE_FIELDS: tuple[str, ...] = (CONF_API_KEY, *_ENTITY_FIELDS)

_CONNECTION_FIELDS: tuple[str, ...] = (
    CONF_SERVER_URL,
    CONF_API_KEY,
    CONF_DEVICE_ID,
)


def _entity_selector() -> selector.EntitySelector:
    """Return the selector used for all configurable entities."""
    return selector.EntitySelector(
        selector.EntitySelectorConfig(domain=["sensor", "number"])
    )


def _normalise_instance_name(value: Any, fallback: str = DEFAULT_INSTANCE_NAME) -> str:
    """Return a non-empty config-entry title."""
    name = str(value or "").strip()
    return name or fallback


def _optional_entity_field(fields: dict[vol.Marker, Any], key: str, value: Any) -> None:
    """Add one optional entity selector, optionally with a current value."""
    if value in (None, ""):
        fields[vol.Optional(key)] = _entity_selector()
    else:
        fields[vol.Optional(key, default=value)] = _entity_selector()


def _sectioned_schema(defaults: dict[str, Any] | None = None) -> vol.Schema:
    """Build a structured setup/options schema with collapsible sections."""
    current = defaults or {}

    connection_fields: dict[vol.Marker, Any] = {
        vol.Required(
            CONF_INSTANCE_NAME,
            default=current.get(CONF_INSTANCE_NAME, DEFAULT_INSTANCE_NAME),
        ): selector.TextSelector(),
        vol.Required(
            CONF_SERVER_URL,
            default=display_server_url(current.get(CONF_SERVER_URL, "")),
        ): selector.TextSelector(
            selector.TextSelectorConfig(type=selector.TextSelectorType.TEXT)
        ),
        vol.Optional(
            CONF_API_KEY,
            default=current.get(CONF_API_KEY, ""),
        ): selector.TextSelector(
            selector.TextSelectorConfig(
                type=selector.TextSelectorType.PASSWORD,
                autocomplete="current-password",
            )
        ),
        vol.Required(
            CONF_DEVICE_ID,
            default=current.get(CONF_DEVICE_ID, "home"),
        ): selector.TextSelector(),
        vol.Optional(
            CONF_INTERVAL,
            default=current.get(CONF_INTERVAL, DEFAULT_INTERVAL),
        ): selector.NumberSelector(
            selector.NumberSelectorConfig(
                min=1,
                max=30,
                step=1,
                mode=selector.NumberSelectorMode.BOX,
            )
        ),
        vol.Optional(
            CONF_OUTPUT_UNIT,
            default=current.get(CONF_OUTPUT_UNIT, DEFAULT_OUTPUT_UNIT),
        ): selector.SelectSelector(
            selector.SelectSelectorConfig(
                options=["kW", "W"],
                multiple=False,
                mode=selector.SelectSelectorMode.DROPDOWN,
            )
        ),
        vol.Optional(CONF_VERIFY_SERVER, default=True): selector.BooleanSelector(),
    }

    power_fields: dict[vol.Marker, Any] = {}
    for key in (
        CONF_PV_ENTITY,
        CONF_CONSUMPTION_ENTITY,
        CONF_FEEDIN_ENTITY,
        CONF_GRID_IMPORT_ENTITY,
    ):
        _optional_entity_field(power_fields, key, current.get(key))

    battery_fields: dict[vol.Marker, Any] = {}
    for key in (
        CONF_BATT_SOC_ENTITY,
        CONF_BATT_CHARGE_ENTITY,
        CONF_BATT_DISCHARGE_ENTITY,
    ):
        _optional_entity_field(battery_fields, key, current.get(key))

    energy_fields: dict[vol.Marker, Any] = {}
    for key in (
        CONF_PV_TOTAL_KWH_ENTITY,
        CONF_CONS_TOTAL_KWH_ENTITY,
        CONF_FEEDIN_TOTAL_KWH_ENTITY,
        CONF_IMPORT_TOTAL_KWH_ENTITY,
        CONF_BATT_IN_TOTAL_KWH_ENTITY,
        CONF_BATT_OUT_TOTAL_KWH_ENTITY,
    ):
        _optional_entity_field(energy_fields, key, current.get(key))

    return vol.Schema(
        {
            vol.Required(SECTION_CONNECTION): section(
                vol.Schema(connection_fields),
                {"collapsed": False},
            ),
            vol.Required(SECTION_POWER): section(
                vol.Schema(power_fields),
                {"collapsed": False},
            ),
            vol.Required(SECTION_BATTERY): section(
                vol.Schema(battery_fields),
                {"collapsed": True},
            ),
            vol.Required(SECTION_ENERGY): section(
                vol.Schema(energy_fields),
                {"collapsed": True},
            ),
        }
    )


def _flatten_sections(user_input: dict[str, Any]) -> dict[str, Any]:
    """Flatten the sectioned Home Assistant form into config-entry options."""
    flattened: dict[str, Any] = {}
    for section_name in (
        SECTION_CONNECTION,
        SECTION_POWER,
        SECTION_BATTERY,
        SECTION_ENERGY,
    ):
        section_values = user_input.get(section_name, {})
        if isinstance(section_values, dict):
            flattened.update(section_values)
    return flattened


def _connection_changed(submitted: dict[str, Any], current: dict[str, Any]) -> bool:
    """Return whether values relevant to server validation changed."""
    for key in _CONNECTION_FIELDS:
        new_value = str(submitted.get(key, "") or "").strip()
        old_value = str(current.get(key, "") or "").strip()
        if key == CONF_SERVER_URL:
            try:
                new_value = normalize_ingest_url(new_value)
                old_value = normalize_ingest_url(old_value)
            except InvalidServerUrl:
                return True
        if new_value != old_value:
            return True
    return False


async def _validate_connection(hass, submitted: dict[str, Any]) -> tuple[str, str | None]:
    """Normalize and test the configured server connection."""
    normalized_url = normalize_ingest_url(submitted.get(CONF_SERVER_URL))
    result = await async_test_server(
        hass,
        normalized_url,
        str(submitted.get(CONF_API_KEY, "") or ""),
        str(submitted.get(CONF_DEVICE_ID, "home") or "home").strip(),
    )
    return normalized_url, result.version


class ConfigFlow(config_entries.ConfigFlow, domain=DOMAIN):
    """Handle the initial PenguinPVDash configuration."""

    VERSION = 1

    async def async_step_user(
        self, user_input: dict[str, Any] | None = None
    ) -> FlowResult:
        """Handle setup through the Home Assistant UI."""
        errors: dict[str, str] = {}
        submitted_defaults: dict[str, Any] = {}

        if user_input is not None:
            submitted = _flatten_sections(user_input)
            verify_server = bool(submitted.pop(CONF_VERIFY_SERVER, True))
            submitted_defaults = dict(submitted)

            try:
                submitted[CONF_SERVER_URL] = normalize_ingest_url(
                    submitted.get(CONF_SERVER_URL)
                )
                if verify_server:
                    await _validate_connection(self.hass, submitted)
            except InvalidServerUrl:
                errors["base"] = "invalid_url"
            except InvalidAuth:
                errors["base"] = "invalid_auth"
            except CannotConnect:
                errors["base"] = "cannot_connect"
            except InvalidServer:
                errors["base"] = "invalid_server"
            except Exception:  # Defensive: never expose network details in the UI.
                errors["base"] = "unknown"

            if not errors:
                entry_data = dict(submitted)
                instance_name = _normalise_instance_name(
                    entry_data.pop(CONF_INSTANCE_NAME, DEFAULT_INSTANCE_NAME)
                )
                return self.async_create_entry(title=instance_name, data=entry_data)

        return self.async_show_form(
            step_id="user",
            data_schema=_sectioned_schema(submitted_defaults),
            errors=errors,
            last_step=True,
        )

    async def async_step_import(self, import_config: dict[str, Any]) -> FlowResult:
        """Import configuration and pass it through the normal setup step."""
        sectioned = {
            SECTION_CONNECTION: {
                key: import_config[key]
                for key in (
                    CONF_INSTANCE_NAME,
                    CONF_SERVER_URL,
                    CONF_API_KEY,
                    CONF_DEVICE_ID,
                    CONF_INTERVAL,
                    CONF_OUTPUT_UNIT,
                )
                if key in import_config
            },
            SECTION_POWER: {
                key: import_config[key]
                for key in (
                    CONF_PV_ENTITY,
                    CONF_CONSUMPTION_ENTITY,
                    CONF_FEEDIN_ENTITY,
                    CONF_GRID_IMPORT_ENTITY,
                )
                if key in import_config
            },
            SECTION_BATTERY: {
                key: import_config[key]
                for key in (
                    CONF_BATT_SOC_ENTITY,
                    CONF_BATT_CHARGE_ENTITY,
                    CONF_BATT_DISCHARGE_ENTITY,
                )
                if key in import_config
            },
            SECTION_ENERGY: {
                key: import_config[key]
                for key in (
                    CONF_PV_TOTAL_KWH_ENTITY,
                    CONF_CONS_TOTAL_KWH_ENTITY,
                    CONF_FEEDIN_TOTAL_KWH_ENTITY,
                    CONF_IMPORT_TOTAL_KWH_ENTITY,
                    CONF_BATT_IN_TOTAL_KWH_ENTITY,
                    CONF_BATT_OUT_TOTAL_KWH_ENTITY,
                )
                if key in import_config
            },
        }
        return await self.async_step_user(sectioned)

    @staticmethod
    @callback
    def async_get_options_flow(
        config_entry: ConfigEntry,
    ) -> config_entries.OptionsFlow:
        """Create the options flow."""
        return PenguinPVDashOptionsFlowHandler()


class PenguinPVDashOptionsFlowHandler(config_entries.OptionsFlow):
    """Allow every PenguinPVDash setting to be changed after setup."""

    def _current_values(self) -> dict[str, Any]:
        """Return merged immutable data and editable options."""
        return {
            **self.config_entry.data,
            **self.config_entry.options,
            CONF_INSTANCE_NAME: self.config_entry.title or DEFAULT_INSTANCE_NAME,
        }

    async def async_step_init(
        self, user_input: dict[str, Any] | None = None
    ) -> FlowResult:
        """Open the editable configuration directly."""
        return await self.async_step_configure(user_input)

    async def async_step_configure(
        self, user_input: dict[str, Any] | None = None
    ) -> FlowResult:
        """Edit all settings in structured sections."""
        current = self._current_values()
        errors: dict[str, str] = {}
        form_defaults = current

        if user_input is not None:
            submitted = _flatten_sections(user_input)
            verify_server = bool(submitted.pop(CONF_VERIFY_SERVER, True))
            form_defaults = {**current, **submitted}

            try:
                submitted[CONF_SERVER_URL] = normalize_ingest_url(
                    submitted.get(CONF_SERVER_URL)
                )
                if verify_server:
                    await _validate_connection(self.hass, submitted)
            except InvalidServerUrl:
                errors["base"] = "invalid_url"
            except InvalidAuth:
                errors["base"] = "invalid_auth"
            except CannotConnect:
                errors["base"] = "cannot_connect"
            except InvalidServer:
                errors["base"] = "invalid_server"
            except Exception:
                errors["base"] = "unknown"

            if not errors:
                instance_name = _normalise_instance_name(
                    submitted.pop(CONF_INSTANCE_NAME, self.config_entry.title),
                    self.config_entry.title or DEFAULT_INSTANCE_NAME,
                )

                new_options = dict(self.config_entry.options)
                new_options.update(submitted)

                # A cleared optional selector is omitted from user_input. Store
                # an explicit empty value to override older entry.data values.
                for key in _CLEARABLE_FIELDS:
                    if key not in submitted:
                        new_options[key] = ""

                if instance_name != self.config_entry.title:
                    self.hass.config_entries.async_update_entry(
                        self.config_entry,
                        title=instance_name,
                    )

                return self.async_create_entry(data=new_options)

        return self.async_show_form(
            step_id="configure",
            data_schema=_sectioned_schema(form_defaults),
            errors=errors,
            last_step=True,
        )

    async def async_step_test_connection(
        self, user_input: dict[str, Any] | None = None
    ) -> FlowResult:
        """Test the currently saved server without writing measurement data."""
        current = self._current_values()
        server_url = str(current.get(CONF_SERVER_URL, ""))
        device_id = str(current.get(CONF_DEVICE_ID, "home") or "home")
        errors: dict[str, str] = {}

        if user_input is not None:
            try:
                result = await async_test_server(
                    self.hass,
                    server_url,
                    str(current.get(CONF_API_KEY, "") or ""),
                    device_id,
                )
            except InvalidServerUrl:
                errors["base"] = "invalid_url"
            except InvalidAuth:
                errors["base"] = "invalid_auth"
            except CannotConnect:
                errors["base"] = "cannot_connect"
            except InvalidServer:
                errors["base"] = "invalid_server"
            except Exception:
                errors["base"] = "unknown"
            else:
                reason = (
                    "connection_successful"
                    if result.authentication_verified
                    else "connection_reachable_unverified"
                )
                return self.async_abort(
                    reason=reason,
                    description_placeholders={
                        "url": result.ingest_url,
                        "version": result.version,
                    },
                )

        try:
            display_url = normalize_ingest_url(server_url)
        except InvalidServerUrl:
            display_url = server_url

        return self.async_show_form(
            step_id="test_connection",
            data_schema=vol.Schema({}),
            errors=errors,
            description_placeholders={
                "url": display_url,
                "device": device_id,
            },
            last_step=True,
        )
