
from __future__ import annotations
import voluptuous as vol
from homeassistant import config_entries
from homeassistant.helpers import selector
from .const import (
    DOMAIN, CONF_SERVER_URL, CONF_PV_NOW, CONF_BATT_SOC, CONF_BATT_IN, CONF_BATT_OUT,
    CONF_INTERVAL, DEFAULT_INTERVAL, CONF_AUTH_MODE, AUTH_MODE_API_KEY, AUTH_MODE_HMAC,
    CONF_API_KEY, CONF_HMAC_SECRET
)

AUTH_SELECTOR = selector.SelectSelector(selector.SelectSelectorConfig(
    options=[AUTH_MODE_API_KEY, AUTH_MODE_HMAC], mode=selector.SelectSelectorMode.DROPDOWN
))

class PenguinPVDashConfigFlow(config_entries.ConfigFlow, domain=DOMAIN):
    VERSION = 1

    async def async_step_user(self, user_input=None):
        if user_input is not None:
            return self.async_create_entry(title="PenguinPVDash", data=user_input)

        schema = vol.Schema({
            vol.Required(CONF_SERVER_URL): str,
            vol.Required(CONF_AUTH_MODE, default=AUTH_MODE_API_KEY): AUTH_SELECTOR,
            vol.Optional(CONF_API_KEY, default=""): str,
            vol.Optional(CONF_HMAC_SECRET, default=""): str,
            vol.Required(CONF_PV_NOW): selector.EntitySelector(selector.EntitySelectorConfig()),
            vol.Required(CONF_BATT_SOC): selector.EntitySelector(selector.EntitySelectorConfig()),
            vol.Required(CONF_BATT_IN): selector.EntitySelector(selector.EntitySelectorConfig()),
            vol.Required(CONF_BATT_OUT): selector.EntitySelector(selector.EntitySelectorConfig()),
            vol.Optional(CONF_INTERVAL, default=DEFAULT_INTERVAL): int
        })
        return self.async_show_form(step_id="user", data_schema=schema)

    async def async_step_import(self, user_input=None):
        return await self.async_step_user(user_input)

    async def async_get_options_flow(self, config_entry):
        return PenguinPVDashOptionsFlow(config_entry)

class PenguinPVDashOptionsFlow(config_entries.OptionsFlow):
    def __init__(self, entry):
        self.entry = entry

    async def async_step_init(self, user_input=None):
        if user_input is not None:
            return self.async_create_entry(title="", data=user_input)

        data = {**self.entry.data, **self.entry.options}
        schema = vol.Schema({
            vol.Required(CONF_SERVER_URL, default=data.get(CONF_SERVER_URL, "")): str,
            vol.Required(CONF_AUTH_MODE, default=data.get(CONF_AUTH_MODE, AUTH_MODE_API_KEY)): AUTH_SELECTOR,
            vol.Optional(CONF_API_KEY, default=data.get(CONF_API_KEY, "")): str,
            vol.Optional(CONF_HMAC_SECRET, default=data.get(CONF_HMAC_SECRET, "")): str,
            vol.Required(CONF_PV_NOW, default=data.get(CONF_PV_NOW, "")): str,
            vol.Required(CONF_BATT_SOC, default=data.get(CONF_BATT_SOC, "")): str,
            vol.Required(CONF_BATT_IN, default=data.get(CONF_BATT_IN, "")): str,
            vol.Required(CONF_BATT_OUT, default=data.get(CONF_BATT_OUT, "")): str,
            vol.Optional(CONF_INTERVAL, default=data.get(CONF_INTERVAL, DEFAULT_INTERVAL)): int,
        })
        return self.async_show_form(step_id="init", data_schema=schema)
