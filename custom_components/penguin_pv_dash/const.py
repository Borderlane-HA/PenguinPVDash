DOMAIN = "penguin_pvdash"

CONF_SERVER_URL = "server_url"
CONF_API_KEY = "api_key"
CONF_DEVICE_ID = "device_id"
CONF_INTERVAL = "interval"
CONF_OUTPUT_UNIT = "output_unit"

CONF_PV_ENTITY = "pv_entity"
CONF_BATT_POWER_ENTITY = "batt_power_entity"
CONF_BATT_SOC_ENTITY = "batt_soc_entity"

# Newly requested entities
CONF_FEEDIN_ENTITY = "feedin_entity"           # Momentane Einspeisung
CONF_CONSUMPTION_ENTITY = "consumption_entity" # Aktueller Stromverbrauch
CONF_GRID_IMPORT_ENTITY = "grid_import_entity"  # Aktueller Netzbezug

DEFAULT_INTERVAL = 10
DEFAULT_OUTPUT_UNIT = "kW"  # or "W"
DECIMALS = 2

HEADER_SIG = "X-PVDash-Signature"
HEADER_TS = "X-PVDash-Timestamp"
HEADER_DEV = "X-PVDash-Device"