<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';

/**
 * Rechnet die Tageswerte aus den Roh-Samples neu (inkl. Einspeisung aus negativem Netzbezug).
 *
 * Aufruf:
 *   php server/tools/rebuild_daily.php home
 */
$device = $argv[1] ?? 'home';

$pdo = db();

/* optional: nur für dieses device löschen */
$pdo->prepare('DELETE FROM daily_totals WHERE device=?')->execute([$device]);

$st = $pdo->prepare('SELECT ts, unit, pv_power, feed_in, battery_charge, battery_discharge, consumption, grid_import
                     FROM samples WHERE device=? ORDER BY ts ASC');
$st->execute([$device]);

$prev = null;
$adds = []; // day => ['pv'=>..,'fi'=>..,'bi'=>..,'bo'=>..,'cons'=>..,'imp'=>..]

while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
  $ts = intval($r['ts']);
  $u  = $r['unit'];

  // in kW normalisieren
  $pv  = as_kW($r['pv_power'],          $u);
  $bi  = as_kW($r['battery_charge'],    $u);
  $bo  = as_kW($r['battery_discharge'], $u);
  $gi  = as_kW($r['grid_import'],       $u);
  $fiS = as_kW($r['feed_in'],           $u);
  $cons= as_kW($r['consumption'],       $u);

  // Einspeisung: erst echten feed_in nehmen, sonst aus negativem Netzbezug ableiten
  $fi  = ($r['feed_in'] !== null && $fiS !== null) ? max(0.0, $fiS) : max(0.0, -1.0 * ($gi ?? 0.0));

  $valsNow = ['pv'=>$pv ?? 0, 'fi'=>$fi, 'bi'=>$bi ?? 0, 'bo'=>$bo ?? 0, 'cons'=>$cons ?? 0, 'imp'=>$gi ?? 0];

  if ($prev) {
    $t0 = $prev['ts'];
    foreach ($valsNow as $k=>$v1) {
      $v0 = $prev[$k];
      $parts = split_interval($t0, $v0, $ts, $v1); // kWh je Tag
      foreach ($parts as $day=>$kwh) {
        if (!isset($adds[$day])) $adds[$day] = ['pv'=>0,'fi'=>0,'bi'=>0,'bo'=>0,'cons'=>0,'imp'=>0];
        $adds[$day][$k] += $kwh;
      }
    }
  }

  $prev = ['ts'=>$ts] + $valsNow;
}

/* Persistieren */
$up = $pdo->prepare('INSERT INTO daily_totals (device,day,pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh,created_ts,updated_ts)
                     VALUES (?,?,?,?,?,?,?,?,?,?)
                     ON CONFLICT(device,day) DO UPDATE SET
                       pv_kwh=excluded.pv_kwh,
                       feed_in_kwh=excluded.feed_in_kwh,
                       batt_in_kwh=excluded.batt_in_kwh,
                       batt_out_kwh=excluded.batt_out_kwh,
                       consumption_kwh=excluded.consumption_kwh,
                       grid_import_kwh=excluded.grid_import_kwh,
                       updated_ts=excluded.updated_ts');

$now = time();
foreach ($adds as $day=>$valsDay) {
  $up->execute([
    $device, $day,
    $valsDay['pv'], $valsDay['fi'], $valsDay['bi'], $valsDay['bo'], $valsDay['cons'], $valsDay['imp'],
    $now, $now
  ]);
}

echo "Rebuild complete for {$device}.\n";
