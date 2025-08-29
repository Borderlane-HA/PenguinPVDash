<?php
// ---------------------------------------------------------------------------
// Grundkonfiguration
// ---------------------------------------------------------------------------
date_default_timezone_set('Europe/Berlin');
$PVDASH_SQLITE = __DIR__ . '/../data/pvdash.sqlite';
if (!is_dir(__DIR__ . '/../data')) { @mkdir(__DIR__ . '/../data', 0775, true); }

// ---------------------------------------------------------------------------
// Authentifizierung (HMAC)
// ---------------------------------------------------------------------------
// true  -> eingehende Posts müssen signiert sein (empfohlen)
// false -> keine Signatur-Prüfung
$PVDASH_REQUIRE_AUTH = true;

// Geräte-IDs und API-Keys (Device-Name muss zur Integration passen)
$PVDASH_API_KEYS = [
  "home" => "MYSECRETAPIKEY",
];

// ---------------------------------------------------------------------------
// Sprache (UI): 'de' oder 'en'
// ---------------------------------------------------------------------------
$lang_from_config = 'de';


$PVDASH_TRUST_MIDNIGHT_RESETS = false;
$PVDASH_RESET_EPS_KWH = 0.9;
$PVDASH_MONO_DROP_EPS_KWH = 0.05;
$PVDASH_ROLL_GUARD_MIN = 90;
$PVDASH_PV_NIGHT_THRESHOLD_KW = 0.05; // 50 W
$PVDASH_EARLY_FIX_MIN = 30;
$PVDASH_QUIET_WINDOW_ENABLED = true;
$PVDASH_QUIET_MODE = 'ignore_totals';
$PVDASH_QUIET_START_MIN = 23*60 + 55;   // 23:55
$PVDASH_QUIET_END_MIN   = 5;            // 00:05

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
