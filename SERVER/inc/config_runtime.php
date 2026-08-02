<?php

declare(strict_types=1);

require_once __DIR__ . '/config_advanced.php';

function pvdash_env_bool(string $name, bool $default): bool
{
    $value = getenv($name);
    if ($value === false || $value === '') {
        return $default;
    }
    $parsed = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    return $parsed ?? $default;
}

function pvdash_env_string(string $name, string $default): string
{
    $value = getenv($name);
    return ($value === false) ? $default : (string) $value;
}

function pvdash_env_float(string $name, float $default): float
{
    $value = getenv($name);
    if ($value === false || trim((string) $value) === '') {
        return $default;
    }
    return (float) str_replace(',', '.', (string) $value);
}

// Environment variables override config.local.php. This is used by Docker.
$PVDASH_CONFIG['timezone'] = pvdash_env_string('TZ', (string) $PVDASH_CONFIG['timezone']);
$PVDASH_CONFIG['language'] = pvdash_env_string('PVDASH_DEFAULT_LANGUAGE', (string) $PVDASH_CONFIG['language']);
$PVDASH_CONFIG['sqlite_path'] = pvdash_env_string('PVDASH_SQLITE', (string) $PVDASH_CONFIG['sqlite_path']);
$PVDASH_CONFIG['feed_in_ct'] = pvdash_env_float('PVDASH_FEED_IN_CT', (float) $PVDASH_CONFIG['feed_in_ct']);
$PVDASH_CONFIG['admin_password'] = pvdash_env_string('PVDASH_ADMIN_PASSWORD', (string) $PVDASH_CONFIG['admin_password']);
$PVDASH_CONFIG['guest_password'] = pvdash_env_string('PVDASH_GUEST_PASSWORD', (string) $PVDASH_CONFIG['guest_password']);
$PVDASH_CONFIG['guest_can_view_stats'] = pvdash_env_bool('PVDASH_GUEST_CAN_VIEW_STATS', (bool) $PVDASH_CONFIG['guest_can_view_stats']);
$PVDASH_CONFIG['guest_can_view_compensation'] = pvdash_env_bool('PVDASH_GUEST_CAN_VIEW_COMPENSATION', (bool) $PVDASH_CONFIG['guest_can_view_compensation']);
$PVDASH_CONFIG['require_ingest_auth'] = pvdash_env_bool('PVDASH_REQUIRE_INGEST_AUTH', (bool) $PVDASH_CONFIG['require_ingest_auth']);

$apiKeysJson = getenv('PVDASH_API_KEYS_JSON');
if ($apiKeysJson !== false && trim((string) $apiKeysJson) !== '') {
    $decoded = json_decode((string) $apiKeysJson, true);
    if (!is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
        throw new RuntimeException('PVDASH_API_KEYS_JSON must be a non-empty JSON object.');
    }
    $validatedKeys = [];
    foreach ($decoded as $configuredDevice => $configuredKey) {
        $configuredDevice = (string) $configuredDevice;
        $configuredKey = (string) $configuredKey;
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $configuredDevice) || $configuredKey === '') {
            throw new RuntimeException('PVDASH_API_KEYS_JSON contains an invalid device ID or empty key.');
        }
        $validatedKeys[$configuredDevice] = $configuredKey;
    }
    $PVDASH_CONFIG['api_keys'] = $validatedKeys;
} else {
    $deviceId = getenv('PVDASH_DEVICE_ID');
    $apiKey = getenv('PVDASH_API_KEY');
    if ($deviceId !== false && $apiKey !== false && $deviceId !== '' && $apiKey !== '') {
        $PVDASH_CONFIG['api_keys'] = [(string) $deviceId => (string) $apiKey];
    }
}

function pvdash_config(string $key, mixed $default = null): mixed
{
    global $PVDASH_CONFIG;
    return $PVDASH_CONFIG[$key] ?? $default;
}

date_default_timezone_set((string) pvdash_config('timezone', 'Europe/Berlin'));

// Backward-compatible globals used by the ingest logic.
$PVDASH_SQLITE = (string) pvdash_config('sqlite_path');
$PVDASH_REQUIRE_AUTH = (bool) pvdash_config('require_ingest_auth', true);
$PVDASH_API_KEYS = (array) pvdash_config('api_keys', []);
