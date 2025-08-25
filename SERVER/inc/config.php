<?php
date_default_timezone_set('Europe/Berlin');
$PVDASH_SQLITE = __DIR__ . '/../data/pvdash.sqlite';
if (!is_dir(__DIR__ . '/../data')) { @mkdir(__DIR__ . '/../data', 0775, true); }

// Set Config start

// false/true :: true if API Key set
$PVDASH_REQUIRE_AUTH = true;
// Device Name and API Key (Standard: home)
$PVDASH_API_KEYS = [ "home" => "MYSECRETAPIKEY" ];
// Language German (de) or english (en)
$lang_from_config = 'de';

// Trust HA reset at midnight?
$PVDASH_TRUST_MIDNIGHT_RESETS = true;

// Quiet window around midnight to avoid drift issues
$PVDASH_RESET_EPS_KWH = 0.2;

$PVDASH_QUIET_WINDOW_ENABLED = true;      // on/off
$PVDASH_QUIET_MODE = 'ignore_totals';     // 'ignore_totals' or 'drop_all'
$PVDASH_QUIET_START_MIN = 23*60 + 58;     // 23:58 -> Minuten seit 00:00
$PVDASH_QUIET_END_MIN   = 5;              // 00:02

// Set Config end

$allowed = ['de','en'];
$lang = $lang_from_config;
if (isset($_GET['lang']) && in_array($_GET['lang'], $allowed, true)) {
    $lang = $_GET['lang'];
    setcookie('lang', $lang, time() + 31536000, '/'); // 1 Jahr
} elseif (!empty($_COOKIE['lang']) && in_array($_COOKIE['lang'], $allowed, true)) {
    $lang = $_COOKIE['lang'];
}
define('APP_LANG', $lang);

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-PVDash-Timestamp, X-PVDash-Device, X-PVDash-Signature');
?>
