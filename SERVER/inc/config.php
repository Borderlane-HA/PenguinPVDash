<?php
// ---------------------------------------------------------------------------
// Grundkonfiguration
// ---------------------------------------------------------------------------
date_default_timezone_set('Europe/Berlin');

// SQLite-DB Pfad + Verzeichnis sicherstellen
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

// ---------------------------------------------------------------------------
// Mitternachts-/Tageswechsel-Logik
// ---------------------------------------------------------------------------
// Konservativer Modus: nach 00:00 werden Tageszähler NUR übernommen,
// wenn sie „klein“ sind (siehe $PVDASH_RESET_EPS_KWH). Ansonsten
// integrieren wir aus Leistung. Empfohlen: false.
$PVDASH_TRUST_MIDNIGHT_RESETS = false;

// „≈0“-Schwelle in kWh, um einen frischen Tageszähler zu erkennen
// (nur relevant für trust=true oder als Backstop).
$PVDASH_RESET_EPS_KWH = 0.9;

// Monotonie-Drop-Erkennung (now < prev - eps) → Tageswechsel
// Epsilon für Drop-Erkennung in kWh (kleine Sensorjitter ignorieren)
$PVDASH_MONO_DROP_EPS_KWH = 0.05;

// Wie lange nach Mitternacht (in Minuten) ein Drop als Tageswechsel
// gewertet werden darf (bei großen Intervallen sinnvoll).
$PVDASH_ROLL_GUARD_MIN = 90;

// Optional: Nacht-Clamp für PV (nur relevant, falls trust=true).
// Wenn PV-Leistung <= Threshold (kW), wird PV-Start am neuen Tag auf 0 geklemmt.
$PVDASH_PV_NIGHT_THRESHOLD_KW = 0.05; // 50 W

// Frühe Abwärtskorrektur (nur falls trust=true): innerhalb der ersten X Minuten
// darf ein kleinerer Tageszähler den heutigen Wert korrigieren.
$PVDASH_EARLY_FIX_MIN = 30;

// ---------------------------------------------------------------------------
// Quiet-Window rund um Mitternacht (drift-sicher)
// ---------------------------------------------------------------------------
// Aktiviert ein spezielles Verhalten in einem Zeitfenster über Mitternacht.
$PVDASH_QUIET_WINDOW_ENABLED = true;

// Verhalten im Quiet-Window:
//  - 'ignore_totals' : Samples werden angenommen, aber Tageszähler-Felder ignoriert
//  - 'drop_all'      : ganze Samples werden verworfen
$PVDASH_QUIET_MODE = 'ignore_totals';

// Beginn/Ende des Fensters in lokalen Minuten seit 00:00.
$PVDASH_QUIET_START_MIN = 23*60 + 55;   // 23:55
$PVDASH_QUIET_END_MIN   = 5;            // 00:05

// ---------------------------------------------------------------------------
// CORS/Headers
// ---------------------------------------------------------------------------
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
