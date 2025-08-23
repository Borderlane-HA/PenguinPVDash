<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';

header('Content-Type: application/json; charset=utf-8');

/* ---------- Auth (optional HMAC) ---------- */
$raw = file_get_contents('php://input');
list($ok,$err) = verify_hmac($raw);
if (!$ok) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>$err]); exit; }

/* ---------- Payload ---------- */
$pdo = db();
$payload = json_decode($raw, true);
if (!$payload) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid json']); exit; }

$ts     = intval($payload['ts'] ?? time());
$device = $payload['device'] ?? 'home';
$unit   = strtolower(trim($payload['unit'] ?? 'kW')); // 'kW' oder 'w'
$unit   = ($unit === 'w') ? 'w' : 'kW';

/** Live-Werte (Leistung) + optionale Tages-Totals (kWh) */
$keys = [
  'pv_power','battery_charge','battery_discharge','feed_in','consumption','grid_import','battery_soc',
  'pv_total_kwh','feed_in_total_kwh','batt_in_total_kwh','batt_out_total_kwh','consumption_total_kwh','grid_import_total_kwh'
];
$vals = [];
foreach ($keys as $k) { $vals[$k] = array_key_exists($k,$payload) ? floatval($payload[$k]) : null; }

/* ---------- Persist raw sample ---------- */
$ins = $pdo->prepare('INSERT INTO samples (device, ts, unit, pv_power, battery_charge, battery_discharge, feed_in, consumption, grid_import, battery_soc, pv_total_kwh, feed_in_total_kwh, batt_in_total_kwh, batt_out_total_kwh, consumption_total_kwh, grid_import_total_kwh)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$ins->execute([
  $device,$ts,$unit,
  $vals['pv_power'],$vals['battery_charge'],$vals['battery_discharge'],
  $vals['feed_in'],$vals['consumption'],$vals['grid_import'],$vals['battery_soc'],
  $vals['pv_total_kwh'],$vals['feed_in_total_kwh'],$vals['batt_in_total_kwh'],$vals['batt_out_total_kwh'],
  $vals['consumption_total_kwh'],$vals['grid_import_total_kwh']
]);

/* ---------- State + heutiger Tagesstand ---------- */
$stateSel = $pdo->prepare('SELECT last_ts,last_pv,last_feed_in,last_bi,last_bo,last_cons,last_gi,last_unit FROM integ_state WHERE device=?');
$stateUpSert = $pdo->prepare('INSERT INTO integ_state (device,last_ts,last_pv,last_feed_in,last_bi,last_bo,last_cons,last_gi,last_unit) VALUES (?,?,?,?,?,?,?,?,?)
ON CONFLICT(device) DO UPDATE SET last_ts=excluded.last_ts,last_pv=excluded.last_pv,last_feed_in=excluded.last_feed_in,last_bi=excluded.last_bi,last_bo=excluded.last_bo,last_cons=excluded.last_cons,last_gi=excluded.last_gi,last_unit=excluded.last_unit');

$today = date('Y-m-d',$ts);
$selDay = $pdo->prepare('SELECT pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh FROM daily_totals WHERE device=? AND day=?');
$upsertDay = $pdo->prepare('INSERT INTO daily_totals (device,day,pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh,created_ts,updated_ts) VALUES (?,?,?,?,?,?,?,?,?,?)
ON CONFLICT(device,day) DO UPDATE SET pv_kwh=excluded.pv_kwh,feed_in_kwh=excluded.feed_in_kwh,batt_in_kwh=excluded.batt_in_kwh,batt_out_kwh=excluded.batt_out_kwh,consumption_kwh=excluded.consumption_kwh,grid_import_kwh=excluded.grid_import_kwh,updated_ts=excluded.updated_ts');

$selDay->execute([$device,$today]);
$rowToday = $selDay->fetch(PDO::FETCH_ASSOC);
$T = [
  'pv'   => $rowToday ? floatval($rowToday['pv_kwh']) : 0.0,
  'fi'   => $rowToday ? floatval($rowToday['feed_in_kwh']) : 0.0,
  'bi'   => $rowToday ? floatval($rowToday['batt_in_kwh']) : 0.0,
  'bo'   => $rowToday ? floatval($rowToday['batt_out_kwh']) : 0.0,
  'cons' => $rowToday ? floatval($rowToday['consumption_kwh']) : 0.0,
  'imp'  => $rowToday ? floatval($rowToday['grid_import_kwh']) : 0.0,
];

$stateSel->execute([$device]);
$state = $stateSel->fetch(PDO::FETCH_ASSOC);

/* ---------- Midnight/Total-Logik als Funktion ---------- */
function apply_metric(&$T,&$prevAdds,$key,$total_key,$last_key,$now_val_raw,$unit,$state,$ts){
  $today = date('Y-m-d',$ts);

  // Tages-Total aus Payload?
  $total = $total_key ? ($GLOBALS['vals'][$total_key] ?? null) : null;
  if ($total !== null) {
    $accepted = floatval($total);

    // Tagwechsel?
    $rolled = $state && !empty($state['last_ts']) && date('Y-m-d', intval($state['last_ts'])) !== $today;

    // Schonfrist nach Mitternacht
    $mins_since_midnight = ($ts - strtotime($today.' 00:00:00')) / 60.0;
    $RESET_EPS_KWH = 0.5;  // Total gilt „frisch“, wenn schon klein
    $GRACE_MIN     = 10;   // Minuten Schonfrist nach 00:00

    $may_use_total = (!$rolled) || ($accepted <= $RESET_EPS_KWH) || ($mins_since_midnight >= $GRACE_MIN);

    if ($may_use_total) {
      // Bei neuem Tag: setzen (Startwert), am selben Tag: max zur Entstörung.
      if ($rolled) $T[$key] = $accepted;
      else $T[$key] = max($T[$key], $accepted);
      return;
    }
    // Sonst: Total ignorieren → unten integrieren
  }

  // Integration aus Leistung (kW)
  if (!$state || empty($state['last_ts'])) return;
  $t0 = intval($state['last_ts']); $t1 = intval($ts);
  if ($t1 <= $t0) return;

  $last_unit = $state['last_unit'] ?? $unit;
  $v0 = as_kW(floatval($state[$last_key] ?? 0), $last_unit);
  $v1 = as_kW(floatval($now_val_raw ?? 0), $unit);

  $parts = split_interval($t0,$v0,$t1,$v1); // kWh pro Tag
  foreach ($parts as $day=>$kwh){
    if ($day === $today) $T[$key] += $kwh;
    else $prevAdds[$key] += $kwh; // Anteil dem Vortag gutschreiben
  }
}

/* ---------- Feed-in robust ableiten ---------- */
// Falls kein getrennter feed_in-Sensor vorhanden ist, aus negativem Netzbezug ableiten.
$fi_now = $vals['feed_in'];
if ($fi_now === null && $vals['grid_import'] !== null) {
  $fi_now = max(-1.0 * $vals['grid_import'], 0.0);
}

/* ---------- Integration/Update je Metrik ---------- */
$prevAdds = ['pv'=>0,'fi'=>0,'bi'=>0,'bo'=>0,'cons'=>0,'imp'=>0];

apply_metric($T,$prevAdds,'pv','pv_total_kwh','last_pv',$vals['pv_power'],$unit,$state,$ts);
apply_metric($T,$prevAdds,'fi','feed_in_total_kwh','last_feed_in',$fi_now,$unit,$state,$ts);           // <- $fi_now verwenden
apply_metric($T,$prevAdds,'bi','batt_in_total_kwh','last_bi',$vals['battery_charge'],$unit,$state,$ts);
apply_metric($T,$prevAdds,'bo','batt_out_total_kwh','last_bo',$vals['battery_discharge'],$unit,$state,$ts);
apply_metric($T,$prevAdds,'cons','consumption_total_kwh','last_cons',$vals['consumption'],$unit,$state,$ts);
apply_metric($T,$prevAdds,'imp','grid_import_total_kwh','last_gi',$vals['grid_import'],$unit,$state,$ts);

/* ---------- Vortagsanteile (bei Mitternachtssplit) nachtragen ---------- */
foreach ($prevAdds as $k=>$inc){
  if ($inc <= 0) continue;
  $prevday = date('Y-m-d', intval($state['last_ts']));
  $sel = $pdo->prepare('SELECT pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh FROM daily_totals WHERE device=? AND day=?');
  $sel->execute([$device,$prevday]);
  $r = $sel->fetch(PDO::FETCH_ASSOC);
  $valsPrev = [
    'pv'   => $r ? floatval($r['pv_kwh']) : 0.0,
    'fi'   => $r ? floatval($r['feed_in_kwh']) : 0.0,
    'bi'   => $r ? floatval($r['batt_in_kwh']) : 0.0,
    'bo'   => $r ? floatval($r['batt_out_kwh']) : 0.0,
    'cons' => $r ? floatval($r['consumption_kwh']) : 0.0,
    'imp'  => $r ? floatval($r['grid_import_kwh']) : 0.0,
  ];
  $valsPrev[$k] += $inc;
  $upsertDay->execute([$device,$prevday,$valsPrev['pv'],$valsPrev['fi'],$valsPrev['bi'],$valsPrev['bo'],$valsPrev['cons'],$valsPrev['imp'],time(),time()]);
}

/* ---------- Heutigen Tag speichern ---------- */
$upsertDay->execute([$device,$today,$T['pv'],$T['fi'],$T['bi'],$T['bo'],$T['cons'],$T['imp'],time(),time()]);

/* ---------- State aktualisieren (Feed-in = abgeleiteter Live-Wert) ---------- */
$stateUpSert->execute([
  $device,$ts,
  $vals['pv_power'] ?? 0,
  $fi_now ?? 0,                             // <- derived feed-in
  $vals['battery_charge'] ?? 0,
  $vals['battery_discharge'] ?? 0,
  $vals['consumption'] ?? 0,
  $vals['grid_import'] ?? 0,
  $unit
]);

echo json_encode(['ok'=>true]);
