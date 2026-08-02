<?php

declare(strict_types=1);

function pvdash_runtime_settings_path(array $config): string
{
    $configured = getenv('PVDASH_RUNTIME_SETTINGS');
    if ($configured !== false && trim((string) $configured) !== '') {
        return (string) $configured;
    }

    $sqlitePath = (string) ($config['sqlite_path'] ?? (__DIR__ . '/../data/pvdash.sqlite'));
    return dirname($sqlitePath) . '/settings.json';
}

function pvdash_valid_device_id(string $device): bool
{
    return preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $device) === 1;
}

function pvdash_valid_hex_color(string $color): bool
{
    return preg_match('/^#[0-9A-Fa-f]{6}$/', $color) === 1;
}

function pvdash_runtime_metric_keys(): array
{
    return ['pv_kwh', 'feed_in_kwh', 'batt_in_kwh', 'batt_out_kwh', 'consumption_kwh', 'grid_import_kwh'];
}

function pvdash_validate_api_keys(array $keys): array
{
    if (array_is_list($keys)) {
        throw new InvalidArgumentException('API keys must be stored as a JSON object.');
    }

    $validated = [];
    foreach ($keys as $device => $key) {
        $device = trim((string) $device);
        $key = (string) $key;
        if (!pvdash_valid_device_id($device)) {
            throw new InvalidArgumentException('Invalid device ID: ' . $device);
        }
        if ($key === '' || strlen($key) > 512) {
            throw new InvalidArgumentException('The API key for ' . $device . ' is empty or too long.');
        }
        $validated[$device] = $key;
    }
    return $validated;
}

function pvdash_validate_runtime_settings(array $settings): array
{
    $validated = [];

    if (array_key_exists('default_device', $settings)) {
        $device = trim((string) $settings['default_device']);
        if ($device !== '' && !pvdash_valid_device_id($device)) {
            throw new InvalidArgumentException('Invalid default device ID.');
        }
        $validated['default_device'] = $device;
    }

    if (array_key_exists('feed_in_ct', $settings)) {
        $value = (float) str_replace(',', '.', (string) $settings['feed_in_ct']);
        if (!is_finite($value) || $value < 0 || $value > 1000) {
            throw new InvalidArgumentException('Feed-in compensation is outside the allowed range.');
        }
        $validated['feed_in_ct'] = $value;
    }

    foreach (['guest_can_view_stats', 'guest_can_view_compensation', 'require_ingest_auth'] as $key) {
        if (array_key_exists($key, $settings)) {
            $validated[$key] = (bool) $settings[$key];
        }
    }

    foreach (['admin_password', 'guest_password'] as $key) {
        if (array_key_exists($key, $settings)) {
            $value = (string) $settings[$key];
            if (strlen($value) > 1024) {
                throw new InvalidArgumentException($key . ' is too long.');
            }
            $validated[$key] = $value;
        }
    }

    if (array_key_exists('api_keys', $settings)) {
        if (!is_array($settings['api_keys'])) {
            throw new InvalidArgumentException('api_keys must be an object.');
        }
        $validated['api_keys'] = pvdash_validate_api_keys($settings['api_keys']);
    }

    if (array_key_exists('site_title', $settings)) {
        $title = trim((string) $settings['site_title']);
        if ($title === '' || (function_exists('mb_strlen') ? mb_strlen($title) : strlen($title)) > 80) {
            throw new InvalidArgumentException('site_title is empty or too long.');
        }
        $validated['site_title'] = $title;
    }

    if (array_key_exists('theme', $settings)) {
        $theme = (string) $settings['theme'];
        if (!in_array($theme, ['standard', 'dark', 'light'], true)) {
            throw new InvalidArgumentException('Invalid theme.');
        }
        $validated['theme'] = $theme;
    }

    if (array_key_exists('accent_color', $settings)) {
        $color = strtolower((string) $settings['accent_color']);
        if (!pvdash_valid_hex_color($color)) {
            throw new InvalidArgumentException('Invalid accent color.');
        }
        $validated['accent_color'] = $color;
    }

    if (array_key_exists('table_density', $settings)) {
        $density = (string) $settings['table_density'];
        if (!in_array($density, ['comfortable', 'compact'], true)) {
            throw new InvalidArgumentException('Invalid table density.');
        }
        $validated['table_density'] = $density;
    }

    if (array_key_exists('highlight_extremes', $settings)) {
        $validated['highlight_extremes'] = (bool) $settings['highlight_extremes'];
    }

    if (array_key_exists('metric_colors', $settings)) {
        if (!is_array($settings['metric_colors'])) {
            throw new InvalidArgumentException('metric_colors must be an object.');
        }
        $colors = [];
        foreach (pvdash_runtime_metric_keys() as $metric) {
            $entry = $settings['metric_colors'][$metric] ?? null;
            if (!is_array($entry)) {
                continue;
            }
            $min = strtolower((string) ($entry['min'] ?? ''));
            $max = strtolower((string) ($entry['max'] ?? ''));
            if (!pvdash_valid_hex_color($min) || !pvdash_valid_hex_color($max)) {
                throw new InvalidArgumentException('Invalid metric highlight color.');
            }
            $colors[$metric] = ['min' => $min, 'max' => $max];
        }
        $validated['metric_colors'] = $colors;
    }

    if (array_key_exists('custom_logo_file', $settings)) {
        $file = basename((string) $settings['custom_logo_file']);
        if ($file !== '' && preg_match('/^branding-logo\.(png|jpe?g|webp)$/i', $file) !== 1) {
            throw new InvalidArgumentException('Invalid custom logo file.');
        }
        $validated['custom_logo_file'] = $file;
    }

    if (array_key_exists('backup_retention', $settings)) {
        $retention = (int) $settings['backup_retention'];
        if ($retention < 1 || $retention > 25) {
            throw new InvalidArgumentException('Backup retention is outside the allowed range.');
        }
        $validated['backup_retention'] = $retention;
    }

    return $validated;
}

function pvdash_runtime_settings_read(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $json = file_get_contents($path);
    if ($json === false) {
        throw new RuntimeException('Runtime settings could not be read.');
    }

    $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException('Runtime settings must contain a JSON object.');
    }

    return pvdash_validate_runtime_settings($decoded);
}

function pvdash_runtime_settings_write(string $path, array $settings): void
{
    $settings = pvdash_validate_runtime_settings($settings);
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException('Runtime settings directory could not be created.');
    }

    $json = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    $temp = tempnam($directory, '.settings-');
    if ($temp === false) {
        throw new RuntimeException('Temporary settings file could not be created.');
    }

    try {
        if (file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Runtime settings could not be written.');
        }
        @chmod($temp, 0600);
        if (!rename($temp, $path)) {
            throw new RuntimeException('Runtime settings could not be activated.');
        }
        @chmod($path, 0600);
    } finally {
        if (is_file($temp)) {
            @unlink($temp);
        }
    }
}

function pvdash_runtime_settings_update(array $patch): array
{
    global $PVDASH_CONFIG, $PVDASH_REQUIRE_AUTH, $PVDASH_API_KEYS;

    $path = (string) ($PVDASH_CONFIG['runtime_settings_path'] ?? pvdash_runtime_settings_path($PVDASH_CONFIG));
    $existing = pvdash_runtime_settings_read($path);
    foreach ($patch as $key => $value) {
        $existing[$key] = $value;
    }
    $existing = pvdash_validate_runtime_settings($existing);
    pvdash_runtime_settings_write($path, $existing);

    foreach ($existing as $key => $value) {
        $PVDASH_CONFIG[$key] = $value;
    }
    $PVDASH_CONFIG['runtime_settings_path'] = $path;
    $PVDASH_REQUIRE_AUTH = (bool) ($PVDASH_CONFIG['require_ingest_auth'] ?? true);
    $PVDASH_API_KEYS = (array) ($PVDASH_CONFIG['api_keys'] ?? []);

    return $existing;
}

function pvdash_default_device(): string
{
    $configured = trim((string) pvdash_config('default_device', ''));
    if ($configured !== '' && pvdash_valid_device_id($configured)) {
        return $configured;
    }

    $first = array_key_first((array) pvdash_config('api_keys', []));
    return is_string($first) && $first !== '' ? $first : 'home';
}

function pvdash_api_key_fingerprint(string $key): string
{
    return strtoupper(substr(hash('sha256', $key), 0, 8));
}
