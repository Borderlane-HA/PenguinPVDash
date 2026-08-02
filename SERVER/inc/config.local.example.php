<?php
/**
 * Copy this file to config.local.php and change the values.
 * config.local.php is ignored by Git and should never be published.
 */
return [
    'timezone' => 'Europe/Berlin',
    'language' => 'de',
    'feed_in_ct' => 10.45,
    'default_device' => 'home',

    'admin_password' => 'replace-with-a-strong-admin-password',

    // Empty string = public read-only dashboard.
    'guest_password' => '',
    'guest_can_view_stats' => true,
    'guest_can_view_compensation' => false,

    'require_ingest_auth' => true,
    'api_keys' => [
        'home' => 'same-api-key-as-in-the-home-assistant-integration',
    ],
];
