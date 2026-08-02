<?php
/**
 * PenguinPVDash configuration loader.
 *
 * Do not store private values in this file. For a classic PHP installation,
 * copy config.local.example.php to config.local.php and edit that file.
 * Docker installations normally use environment variables instead.
 */

declare(strict_types=1);

$PVDASH_CONFIG = [
    'timezone' => 'Europe/Berlin',
    'language' => 'de',
    'sqlite_path' => __DIR__ . '/../data/pvdash.sqlite',

    // Feed-in compensation in ct/kWh.
    'feed_in_ct' => 0.0,
    'battery_capacity_kwh' => 0.0,
    'default_device' => '',

    // Appearance and branding.
    'site_title' => 'PenguinPVDash',
    'theme' => 'standard',
    'flow_diagram_style' => 'modern',
    'accent_color' => '#4e8cff',
    'table_density' => 'comfortable',
    'highlight_extremes' => true,
    'metric_colors' => [
        'pv_kwh' => ['min' => '#8fb8ff', 'max' => '#39d98a'],
        'feed_in_kwh' => ['min' => '#91c9ff', 'max' => '#22d3ee'],
        'batt_in_kwh' => ['min' => '#ffe0a3', 'max' => '#f59e0b'],
        'batt_out_kwh' => ['min' => '#ffc2cf', 'max' => '#fb7185'],
        'consumption_kwh' => ['min' => '#b7caff', 'max' => '#5a8cff'],
        'grid_import_kwh' => ['min' => '#ffd0a1', 'max' => '#f4a84a'],
    ],
    'custom_logo_file' => '',
    'backup_retention' => 5,

    // Web access. An empty guest password means public read-only access.
    'admin_password' => '',
    'guest_password' => '',
    'guest_can_view_stats' => true,
    'guest_can_view_compensation' => false,

    // Home Assistant ingest authentication.
    'require_ingest_auth' => true,
    'api_keys' => [],
];

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    $localValues = require $localConfig;
    if (!is_array($localValues)) {
        throw new RuntimeException('config.local.php must return an array.');
    }
    $PVDASH_CONFIG = array_replace_recursive($PVDASH_CONFIG, $localValues);
}

require_once __DIR__ . '/config_runtime.php';
