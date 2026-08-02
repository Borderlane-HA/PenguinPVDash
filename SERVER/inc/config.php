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
    'default_device' => '',

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
