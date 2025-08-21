from __future__ import annotations

import voluptuous as vol
from homeassistant import config_entries
from homeassistant.core import HomeAssistant
from homeassistant.data_entry_flow import FlowResult

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
    DEFAULT_OUTPUT_UNIT,
)

def _schema(defaults: dict | None = None) -> vol.Schema:
    d = defaults or {}
    return vol.Schema({
        vol.Required(CONF_SERVER_URL, default=d.get(CONF_SERVER_URL, "")): str,
        vol.Optional(CONF_API_KEY, default=d.get(CONF_API_KEY, "")): str,
        vol.Required(CONF_DEVICE_ID, default=d.get(CONF_DEVICE_ID, "home")): str,
        vol.Optional(CONF_INTERVAL, default=d.get(CONF_INTERVAL, DEFAULT_INTERVAL)): int,
        vol.Optional(CONF_OUTPUT_UNIT, default=d.get(CONF_OUTPUT_UNIT, DEFAULT_OUTPUT_UNIT)): str,

        vol.Optional(CONF_PV_ENTITY, default=d.get(CONF_PV_ENTITY, "")): str,
        vol.Optional(CONF_BATT_SOC_ENTITY, default=d.get(CONF_BATT_SOC_ENTITY, "")): str,
        vol.Optional(CONF_FEEDIN_ENTITY, default=d.get(CONF_FEEDIN_ENTITY, "")): str,
        vol.Optional(CONF_CONSUMPTION_ENTITY, default=d.get(CONF_CONSUMPTION_ENTITY, "")): str,
        vol.Optional(CONF_GRID_IMPORT_ENTITY, default=d.get(CONF_GRID_IMPORT_ENTITY, "")): str,
        vol.Optional(CONF_BATT_CHARGE_ENTITY, default=d.get(CONF_BATT_CHARGE_ENTITY, "")): str,
        vol.Optional(CONF_BATT_DISCHARGE_ENTITY, default=d.get(CONF_BATT_DISCHARGE_ENTITY, "")): str,

        vol.Optional(CONF_PV_TOTAL_KWH_ENTITY, default=d.get(CONF_PV_TOTAL_KWH_ENTITY, "")): str,
        vol.Optional(CONF_FEEDIN_TOTAL_KWH_ENTITY, default=d.get(CONF_FEEDIN_TOTAL_KWH_ENTITY, "")): str,
        vol.Optional(CONF_BATT_IN_TOTAL_KWH_ENTITY, default=d.get(CONF_BATT_IN_TOTAL_KWH_ENTITY, "")): str,
        vol.Optional(CONF_BATT_OUT_TOTAL_KWH_ENTITY, default=d.get(CONF_BATT_OUT_TOTAL_KWH_ENTITY, "")): str,
    })

class ConfigFlow(config_entries.ConfigFlow, domain=DOMAIN):
    VERSION = 1

    async def async_step_user(self, user_input=None) -> FlowResult:
        if user_input is not None:
            return self.async_create_entry(title="PenguinPVDash", data=user_input)
        return self.async_show_form(step_id="user", data_schema=_schema())

    async def async_step_import(self, import_config):
        return await self.async_step_user(import_config)

    @staticmethod
    def async_get_options_flow(config_entry):
        return PenguinPVDashOptionsFlowHandler(config_entry)

class PenguinPVDashOptionsFlowHandler(config_entries.OptionsFlow):
    def __init__(self, config_entry):
        self.config_entry = config_entry

    async def async_step_init(self, user_input=None):
        if user_input is not None:
            # merge fix: keep existing, override with new
            new_options = {**self.config_entry.options, **user_input}
            return self.async_create_entry(title="", data=new_options)
        defaults = {**self.config_entry.data, **self.config_entry.options}
        return self.async_show_form(step_id="init", data_schema=_schema(defaults))