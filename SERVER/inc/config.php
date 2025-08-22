<?php
date_default_timezone_set('Europe/Berlin');
$PVDASH_SQLITE = __DIR__ . '/../data/pvdash.sqlite';
if (!is_dir(__DIR__ . '/../data')) { @mkdir(__DIR__ . '/../data', 0775, true); }
$PVDASH_REQUIRE_AUTH = false;
$PVDASH_API_KEYS = [ /* "home" => "HIERYOURAPIKEY" */ ];
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, X-PVDash-Timestamp, X-PVDash-Device, X-PVDash-Signature');
?>