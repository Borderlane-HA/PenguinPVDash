from __future__ import annotations

from typing import Any

import voluptuous as vol

from homeassistant import config_entries
from homeassistant.config_entries import ConfigEntry
from homeassistant.core import callback
from homeassistant.data_entry_flow import FlowResult
from homeassistant.helpers import selector

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
)

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
# user clears them. Otherwise the old value from config-entry data/options would
# continue to be used.
_CLEARABLE_FIELDS: tuple[str, ...] = (CONF_API_KEY, *_ENTITY_FIELDS)


def _entity_selector() -> selector.EntitySelector:
    """Return the selector used for all configurable entities."""
    return selector.EntitySelector(
        selector.EntitySelectorConfig(domain=["sensor", "number"])
    )


def _schema(defaults: dict[str, Any] | None = None) -> vol.Schema:
    """Build the setup/options schema with the current values as defaults."""
    current = defaults or {}
    fields: dict[vol.Marker, Any] = {
        vol.Required(
            CONF_SERVER_URL,
            default=current.get(CONF_SERVER_URL, ""),
        ): selector.TextSelector(
            selector.TextSelectorConfig(type=selector.TextSelectorType.URL)
        ),
        vol.Optional(
            CONF_API_KEY,
            default=current.get(CONF_API_KEY, ""),
        ): selector.TextSelector(
            selector.TextSelectorConfig(type=selector.TextSelectorType.PASSWORD)
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
    }

    for key in _ENTITY_FIELDS:
        value = current.get(key)
        if value in (None, ""):
            fields[vol.Optional(key)] = _entity_selector()
        else:
            fields[vol.Optional(key, default=value)] = _entity_selector()

    return vol.Schema(fields)


class ConfigFlow(config_entries.ConfigFlow, domain=DOMAIN):
    """Handle the initial PenguinPVDash configuration."""

    VERSION = 1

    async def async_step_user(
        self, user_input: dict[str, Any] | None = None
    ) -> FlowResult:
        """Handle setup through the Home Assistant UI."""
        if user_input is not None:
            return self.async_create_entry(title="PenguinPVDash", data=user_input)

        return self.async_show_form(step_id="user", data_schema=_schema())

    async def async_step_import(self, import_config: dict[str, Any]) -> FlowResult:
        """Import configuration and pass it through the normal setup step."""
        return await self.async_step_user(import_config)

    @staticmethod
    @callback
    def async_get_options_flow(
        config_entry: ConfigEntry,
    ) -> config_entries.OptionsFlow:
        """Create the options flow.

        Home Assistant now provides the config entry via self.config_entry.
        Passing it to the handler and assigning self.config_entry causes a 500
        error on current Home Assistant versions.
        """
        return PenguinPVDashOptionsFlowHandler()


class PenguinPVDashOptionsFlowHandler(config_entries.OptionsFlow):
    """Allow every PenguinPVDash setting to be changed after setup."""

    async def async_step_init(
        self, user_input: dict[str, Any] | None = None
    ) -> FlowResult:
        """Show and save the editable integration settings."""
        if user_input is not None:
            new_options = dict(self.config_entry.options)
            new_options.update(user_input)

            # A cleared optional selector is omitted from user_input. Store an
            # explicit empty value so it also overrides a value in entry.data.
            for key in _CLEARABLE_FIELDS:
                if key not in user_input:
                    new_options[key] = ""

            return self.async_create_entry(data=new_options)

        defaults = {**self.config_entry.data, **self.config_entry.options}
        return self.async_show_form(
            step_id="init",
            data_schema=_schema(defaults),
        )
