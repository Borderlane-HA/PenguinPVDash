from __future__ import annotations

import voluptuous as vol
from homeassistant import config_entries
from homeassistant.core import callback
from homeassistant.helpers.selector import (
    TextSelector, TextSelectorConfig,
    EntitySelector, EntitySelectorConfig,
    SelectSelector, SelectSelectorConfig, SelectOptionDict,
)

from .const import (
    DOMAIN,
    CONF_SERVER_URL, CONF_API_KEY, CONF_DEVICE_ID,
    CONF_INTERVAL, CONF_OUTPUT_UNIT,
    CONF_PV_ENTITY, CONF_BATT_SOC_ENTITY,
    CONF_FEEDIN_ENTITY, CONF_CONSUMPTION_ENTITY, CONF_GRID_IMPORT_ENTITY,
    CONF_BATT_CHARGE_ENTITY, CONF_BATT_DISCHARGE_ENTITY,
    CONF_PV_TOTAL_ENTITY, CONF_FEEDIN_TOTAL_ENTITY,
    CONF_BATT_IN_TOTAL_ENTITY, CONF_BATT_OUT_TOTAL_ENTITY,
    DEFAULT_INTERVAL,
)

class PenguinPVDashFlow(config_entries.ConfigFlow, domain=DOMAIN):
    VERSION = 1

    async def async_step_user(self, user_input=None):
        if user_input is not None:
            # create entry; we'll store everything as options for easy editing
            return self.async_create_entry(title="PenguinPVDash", data=user_input, options=user_input)

        unit_options = [SelectOptionDict(label="kW", value="kW"),
                        SelectOptionDict(label="W", value="W")]

        schema = vol.Schema({
            vol.Required(CONF_SERVER_URL): TextSelector(TextSelectorConfig(type="url")),
            vol.Optional(CONF_API_KEY): TextSelector(TextSelectorConfig(type="password")),
            vol.Optional(CONF_DEVICE_ID, default="home"): TextSelector(TextSelectorConfig(type="text")),
            vol.Optional(CONF_INTERVAL, default=DEFAULT_INTERVAL): vol.All(int, vol.Range(min=1, max=60)),
            vol.Optional(CONF_OUTPUT_UNIT, default="kW"): SelectSelector(SelectSelectorConfig(options=unit_options, mode="dropdown")),

            vol.Optional(CONF_PV_ENTITY): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_BATT_SOC_ENTITY): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_FEEDIN_ENTITY): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_CONSUMPTION_ENTITY): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_GRID_IMPORT_ENTITY): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_BATT_CHARGE_ENTITY): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_BATT_DISCHARGE_ENTITY): EntitySelector(EntitySelectorConfig(domain=["sensor"])),

            vol.Optional(CONF_PV_TOTAL_ENTITY): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_FEEDIN_TOTAL_ENTITY): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_BATT_IN_TOTAL_ENTITY): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_BATT_OUT_TOTAL_ENTITY): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
        })
        return self.async_show_form(step_id="user", data_schema=schema)

    @staticmethod
    @callback
    def async_get_options_flow(config_entry):
        return OptionsFlowHandler(config_entry)

class OptionsFlowHandler(config_entries.OptionsFlow):
    def __init__(self, config_entry):
        self.config_entry = config_entry

    async def async_step_init(self, user_input=None):
        data = {**self.config_entry.data, **self.config_entry.options}
        if user_input is not None:
            return self.async_create_entry(title="", data=user_input)

        unit_options = [SelectOptionDict(label="kW", value="kW"),
                        SelectOptionDict(label="W", value="W")]

        schema = vol.Schema({
            vol.Optional(CONF_SERVER_URL, default=data.get(CONF_SERVER_URL, "")): TextSelector(TextSelectorConfig(type="url")),
            vol.Optional(CONF_API_KEY, default=data.get(CONF_API_KEY, "")): TextSelector(TextSelectorConfig(type="password")),
            vol.Optional(CONF_DEVICE_ID, default=data.get(CONF_DEVICE_ID, "home")): TextSelector(TextSelectorConfig(type="text")),
            vol.Optional(CONF_INTERVAL, default=data.get(CONF_INTERVAL, DEFAULT_INTERVAL)): vol.All(int, vol.Range(min=1, max=60)),
            vol.Optional(CONF_OUTPUT_UNIT, default=data.get(CONF_OUTPUT_UNIT, "kW")): SelectSelector(SelectSelectorConfig(options=unit_options, mode="dropdown")),

            vol.Optional(CONF_PV_ENTITY, default=data.get(CONF_PV_ENTITY)): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_BATT_SOC_ENTITY, default=data.get(CONF_BATT_SOC_ENTITY)): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_FEEDIN_ENTITY, default=data.get(CONF_FEEDIN_ENTITY)): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_CONSUMPTION_ENTITY, default=data.get(CONF_CONSUMPTION_ENTITY)): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_GRID_IMPORT_ENTITY, default=data.get(CONF_GRID_IMPORT_ENTITY)): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_BATT_CHARGE_ENTITY, default=data.get(CONF_BATT_CHARGE_ENTITY)): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_BATT_DISCHARGE_ENTITY, default=data.get(CONF_BATT_DISCHARGE_ENTITY)): EntitySelector(EntitySelectorConfig(domain=["sensor"])),

            vol.Optional(CONF_PV_TOTAL_ENTITY, default=data.get(CONF_PV_TOTAL_ENTITY)): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_FEEDIN_TOTAL_ENTITY, default=data.get(CONF_FEEDIN_TOTAL_ENTITY)): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_BATT_IN_TOTAL_ENTITY, default=data.get(CONF_BATT_IN_TOTAL_ENTITY)): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
            vol.Optional(CONF_BATT_OUT_TOTAL_ENTITY, default=data.get(CONF_BATT_OUT_TOTAL_ENTITY)): EntitySelector(EntitySelectorConfig(domain=["sensor"])),
        })
        return self.async_show_form(step_id="init", data_schema=schema)
