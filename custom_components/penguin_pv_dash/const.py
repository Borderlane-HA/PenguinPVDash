DOMAIN = "penguin_pvdash"

CONF_SERVER_URL = "server_url"
CONF_API_KEY = "api_key"
CONF_DEVICE_ID = "device_id"
CONF_INTERVAL = "interval"
CONF_OUTPUT_UNIT = "output_unit"

# instantaneous values (power, kW or W) + battery state of charge
CONF_PV_ENTITY = "pv_entity"
CONF_BATT_SOC_ENTITY = "batt_soc_entity"
CONF_FEEDIN_ENTITY = "feedin_entity"
CONF_CONSUMPTION_ENTITY = "consumption_entity"
CONF_GRID_IMPORT_ENTITY = "grid_import_entity"
CONF_BATT_CHARGE_ENTITY = "batt_charge_entity"
CONF_BATT_DISCHARGE_ENTITY = "batt_discharge_entity"

# NEW: daily totals in kWh (reset at midnight)
CONF_PV_TOTAL_ENTITY = "pv_total_entity"
CONF_FEEDIN_TOTAL_ENTITY = "feedin_total_entity"
CONF_BATT_IN_TOTAL_ENTITY = "batt_in_total_entity"
CONF_BATT_OUT_TOTAL_ENTITY = "batt_out_total_entity"

DEFAULT_INTERVAL = 1  # minutes
DEFAULT_OUTPUT_UNIT = "kW"
DECIMALS = 2

HEADER_SIG = "X-PVDash-Signature"
HEADER_TS = "X-PVDash-Timestamp"
HEADER_DEV = "X-PVDash-Device"
