<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';
$device = $argv[1] ?? 'home';
$pdo = db();
$pdo->prepare('DELETE FROM daily_totals WHERE device=?')->execute([$device]);
$st = $pdo->prepare('SELECT ts, unit, pv_power, feed_in, battery_charge, battery_discharge, consumption, grid_import FROM samples WHERE device=? ORDER BY ts ASC');
$st->execute([$device]);
$prev = null; $adds = [];
while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
  $ts = intval($r['ts']); $u = $r['unit'];
  $vals = ['pv'=>as_kW($r['pv_power'],$u),'fi'=>as_kW($r['feed_in'],$u),'bi'=>as_kW($r['battery_charge'],$u),'bo'=>as_kW($r['battery_discharge'],$u),'cons'=>as_kW($r['consumption'],$u),'imp'=>as_kW($r['grid_import'],$u)];
  if ($prev) {
    $t0=$prev['ts']; $u0=$prev['unit'];
    foreach ($vals as $k=>$v1) {
      $v0 = as_kW($prev[$k], $u0);
      $parts = split_interval($t0,$v0,$ts,$v1);
      foreach ($parts as $day=>$kwh) { if (!isset($adds[$day])) $adds[$day]=['pv'=>0,'fi'=>0,'bi'=>0,'bo'=>0,'cons'=>0,'imp'=>0]; $adds[$day][$k]+=$kwh; }
    }
  }
  $prev = ['ts'=>$ts,'unit'=>$u] + $vals;
}
$up = $pdo->prepare('INSERT INTO daily_totals (device,day,pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh,created_ts,updated_ts) VALUES (?,?,?,?,?,?,?,?,?,?)
ON CONFLICT(device,day) DO UPDATE SET pv_kwh=excluded.pv_kwh,feed_in_kwh=excluded.feed_in_kwh,batt_in_kwh=excluded.batt_in_kwh,batt_out_kwh=excluded.batt_out_kwh,consumption_kwh=excluded.consumption_kwh,grid_import_kwh=excluded.grid_import_kwh,updated_ts=excluded.updated_ts');
foreach ($adds as $day=>$vals) { $up->execute([$device,$day,$vals['pv'],$vals['fi'],$vals['bi'],$vals['bo'],$vals['cons'],$vals['imp'],time(),time()]); }
echo "Rebuild complete for {$device}.\n";