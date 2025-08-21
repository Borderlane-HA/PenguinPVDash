from __future__ import annotations

import voluptuous as vol
from homeassistant import config_entries
from homeassistant.data_entry_flow import FlowResult
from homeassistant.helpers import selector

from .const import *

def _entity_sel():
    return selector.EntitySelector(selector.EntitySelectorConfig(domain=['sensor','number']))


def _schema(defaults: dict | None = None) -> vol.Schema:
    d = defaults or {}
    # Build fields programmatically to avoid empty-string defaults for selectors
    fields = {}

    fields[vol.Required(CONF_SERVER_URL, default=d.get(CONF_SERVER_URL, ""))] = selector.TextSelector(
        selector.TextSelectorConfig(type=selector.TextSelectorType.URL)
    )
    fields[vol.Optional(CONF_API_KEY, default=d.get(CONF_API_KEY, ""))] = selector.TextSelector(
        selector.TextSelectorConfig(type=selector.TextSelectorType.PASSWORD)
    )
    fields[vol.Required(CONF_DEVICE_ID, default=d.get(CONF_DEVICE_ID, "home"))] = selector.TextSelector()
    fields[vol.Optional(CONF_INTERVAL, default=d.get(CONF_INTERVAL, DEFAULT_INTERVAL))] = selector.NumberSelector(
        selector.NumberSelectorConfig(min=1, max=30, step=1, mode=selector.NumberSelectorMode.BOX)
    )
    fields[vol.Optional(CONF_OUTPUT_UNIT, default=d.get(CONF_OUTPUT_UNIT, DEFAULT_OUTPUT_UNIT))] = selector.SelectSelector(
        selector.SelectSelectorConfig(options=["kW","W"], multiple=False, mode=selector.SelectSelectorMode.DROPDOWN)
    )

    def opt_entity(key):
        val = d.get(key)
        if val in (None, ""):
            fields[vol.Optional(key)] = _entity_sel()
        else:
            fields[vol.Optional(key, default=val)] = _entity_sel()

    for key in (CONF_PV_ENTITY, CONF_BATT_SOC_ENTITY, CONF_FEEDIN_ENTITY, CONF_CONSUMPTION_ENTITY,
                CONF_GRID_IMPORT_ENTITY, CONF_BATT_CHARGE_ENTITY, CONF_BATT_DISCHARGE_ENTITY,
                CONF_PV_TOTAL_KWH_ENTITY, CONF_FEEDIN_TOTAL_KWH_ENTITY, CONF_BATT_IN_TOTAL_KWH_ENTITY, CONF_BATT_OUT_TOTAL_KWH_ENTITY):
        opt_entity(key)

    return vol.Schema(fields)

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
            new_options = {**self.config_entry.options, **user_input}
            return self.async_create_entry(title="", data=new_options)
        defaults = {**self.config_entry.data, **self.config_entry.options}
        return self.async_show_form(step_id="init", data_schema=_schema(defaults))