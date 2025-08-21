<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json; charset=utf-8');
$raw = file_get_contents('php://input');
list($ok,$err) = verify_hmac($raw);
if (!$ok) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>$err]); exit; }

$pdo = db();
$payload = json_decode($raw, true);
if (!$payload) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid json']); exit; }

$ts = intval($payload['ts'] ?? time());
$device = $payload['device'] ?? 'home';
$unit = strtolower(trim($payload['unit'] ?? 'kW')); $unit = $unit === 'w' ? 'w' : 'kW';

$keys = ['pv_power','battery_charge','battery_discharge','feed_in','consumption','grid_import','battery_soc',
         'pv_total_kwh','feed_in_total_kwh','batt_in_total_kwh','batt_out_total_kwh','consumption_total_kwh','grid_import_total_kwh'];
$vals = [];
foreach ($keys as $k) { $vals[$k] = array_key_exists($k,$payload) ? floatval($payload[$k]) : null; }

$ins = $pdo->prepare('INSERT INTO samples (device, ts, unit, pv_power, battery_charge, battery_discharge, feed_in, consumption, grid_import, battery_soc, pv_total_kwh, feed_in_total_kwh, batt_in_total_kwh, batt_out_total_kwh, consumption_total_kwh, grid_import_total_kwh)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$ins->execute([$device,$ts,$unit,$vals['pv_power'],$vals['battery_charge'],$vals['battery_discharge'],$vals['feed_in'],$vals['consumption'],$vals['grid_import'],$vals['battery_soc'],$vals['pv_total_kwh'],$vals['feed_in_total_kwh'],$vals['batt_in_total_kwh'],$vals['batt_out_total_kwh'],$vals['consumption_total_kwh'],$vals['grid_import_total_kwh']]);

function as_kW($v,$u){ if ($v===null) return null; return $u==='w' ? $v/1000.0 : $v; }

$stateSel = $pdo->prepare('SELECT last_ts,last_pv,last_feed_in,last_bi,last_bo,last_cons,last_gi,last_unit FROM integ_state WHERE device=?');
$stateUpSert = $pdo->prepare('INSERT INTO integ_state (device,last_ts,last_pv,last_feed_in,last_bi,last_bo,last_cons,last_gi,last_unit) VALUES (?,?,?,?,?,?,?,?,?)
ON CONFLICT(device) DO UPDATE SET last_ts=excluded.last_ts,last_pv=excluded.last_pv,last_feed_in=excluded.last_feed_in,last_bi=excluded.last_bi,last_bo=excluded.last_bo,last_cons=excluded.last_cons,last_gi=excluded.last_gi,last_unit=excluded.last_unit');

$dayUpsert = $pdo->prepare('INSERT INTO daily_totals (device,day,pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh,created_ts,updated_ts) VALUES (?,?,?,?,?,?,?,?,?,?)
ON CONFLICT(device,day) DO UPDATE SET pv_kwh=excluded.pv_kwh,feed_in_kwh=excluded.feed_in_kwh,batt_in_kwh=excluded.batt_in_kwh,batt_out_kwh=excluded.batt_out_kwh,consumption_kwh=excluded.consumption_kwh,grid_import_kwh=excluded.grid_import_kwh,updated_ts=excluded.updated_ts');

$selDay = $pdo->prepare('SELECT pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh FROM daily_totals WHERE device=? AND day=?');

$stateSel->execute([$device]);
$state = $stateSel->fetch(PDO::FETCH_ASSOC);

$today = gmdate('Y-m-d', $ts);
$selDay->execute([$device,$today]);
$row = $selDay->fetch(PDO::FETCH_ASSOC);
$pv_kwh = $row ? floatval($row['pv_kwh']) : 0.0;
$fi_kwh = $row ? floatval($row['feed_in_kwh']) : 0.0;
$bi_kwh = $row ? floatval($row['batt_in_kwh']) : 0.0;
$bo_kwh = $row ? floatval($row['batt_out_kwh']) : 0.0;
$cons_kwh = $row ? floatval($row['consumption_kwh']) : 0.0;
$imp_kwh  = $row ? floatval($row['grid_import_kwh']) : 0.0;

$changed = false;
if ($vals['pv_total_kwh'] !== null) { $pv_kwh = floatval($vals['pv_total_kwh']); $changed = true; }
if ($vals['feed_in_total_kwh'] !== null) { $fi_kwh = floatval($vals['feed_in_total_kwh']); $changed = true; }
if ($vals['batt_in_total_kwh'] !== null) { $bi_kwh = floatval($vals['batt_in_total_kwh']); $changed = true; }
if ($vals['batt_out_total_kwh'] !== null) { $bo_kwh = floatval($vals['batt_out_total_kwh']); $changed = true; }
if ($vals['consumption_total_kwh'] !== null) { $cons_kwh = floatval($vals['consumption_total_kwh']); $changed = true; }
if ($vals['grid_import_total_kwh'] !== null) { $imp_kwh = floatval($vals['grid_import_total_kwh']); $changed = true; }

if (!$changed && $state) {
  $prev_ts = intval($state['last_ts']);
  $last_unit = $state['last_unit'] ?? $unit;
  $pv_prev = as_kW(floatval($state['last_pv']), $last_unit);
  $fi_prev = as_kW(floatval($state['last_feed_in']), $last_unit);
  $bi_prev = as_kW(floatval($state['last_bi']), $last_unit);
  $bo_prev = as_kW(floatval($state['last_bo']), $last_unit);
  $cons_prev = as_kW(floatval($state['last_cons']), $last_unit);
  $gi_prev = as_kW(floatval($state['last_gi']), $last_unit);

  $pv_now = as_kW($vals['pv_power'], $unit);
  $fi_now = as_kW($vals['feed_in'], $unit);
  $bi_now = as_kW($vals['battery_charge'], $unit);
  $bo_now = as_kW($vals['battery_discharge'], $unit);
  $cons_now = as_kW($vals['consumption'], $unit);
  $gi_now = as_kW($vals['grid_import'], $unit);

  $t0 = $prev_ts; $t1 = $ts;
  if ($t0 && $t1 && $t1>$t0) {
    $mid = strtotime(gmdate('Y-m-d 00:00:00', $t1));
    if ($t0 < $mid && $t1 >= $mid) {
      $dt1 = max(0, ($mid - $t0)/3600.0);
      $prevday = gmdate('Y-m-d', $t0);
      $pv_prev_kwh = ($pv_prev + $pv_prev)/2.0 * $dt1;
      $fi_prev_kwh = ($fi_prev + $fi_prev)/2.0 * $dt1;
      $bi_prev_kwh = ($bi_prev + $bi_prev)/2.0 * $dt1;
      $bo_prev_kwh = ($bo_prev + $bo_prev)/2.0 * $dt1;
      $cons_prev_kwh = ($cons_prev + $cons_prev)/2.0 * $dt1;
      $gi_prev_kwh   = ($gi_prev + $gi_prev)/2.0 * $dt1;

      $selDay->execute([$device,$prevday]);
      $r = $selDay->fetch(PDO::FETCH_ASSOC);
      $dayUpsert->execute([$device,$prevday,
        ($r?floatval($r['pv_kwh']):0)+$pv_prev_kwh,
        ($r?floatval($r['feed_in_kwh']):0)+$fi_prev_kwh,
        ($r?floatval($r['batt_in_kwh']):0)+$bi_prev_kwh,
        ($r?floatval($r['batt_out_kwh']):0)+$bo_prev_kwh,
        ($r?floatval($r['consumption_kwh']):0)+$cons_prev_kwh,
        ($r?floatval($r['grid_import_kwh']):0)+$gi_prev_kwh,
        $t0,$t1]);

      $dt2 = max(0, ($t1 - $mid)/3600.0);
      $pv_kwh += ($pv_now + $pv_now)/2.0 * $dt2;
      $fi_kwh += ($fi_now + $fi_now)/2.0 * $dt2;
      $bi_kwh += ($bi_now + $bi_now)/2.0 * $dt2;
      $bo_kwh += ($bo_now + $bo_now)/2.0 * $dt2;
      $cons_kwh += ($cons_now + $cons_now)/2.0 * $dt2;
      $imp_kwh  += ($gi_now + $gi_now)/2.0 * $dt2;
    } else {
      $dt = max(0, ($t1 - $t0)/3600.0);
      $pv_kwh += ($pv_prev + $pv_now)/2.0 * $dt;
      $fi_kwh += ($fi_prev + $fi_now)/2.0 * $dt;
      $bi_kwh += ($bi_prev + $bi_now)/2.0 * $dt;
      $bo_kwh += ($bo_prev + $bo_now)/2.0 * $dt;
      $cons_kwh += ($cons_prev + $cons_now)/2.0 * $dt;
      $imp_kwh  += ($gi_prev + $gi_now)/2.0 * $dt;
    }
  }
}

$dayUpsert->execute([$device,$today,$pv_kwh,$fi_kwh,$bi_kwh,$bo_kwh,$cons_kwh,$imp_kwh,$ts,$ts]);
$stateUpSert->execute([$device,$ts,$vals['pv_power'] ?? 0,$vals['feed_in'] ?? 0,$vals['battery_charge'] ?? 0,$vals['battery_discharge'] ?? 0,$vals['consumption'] ?? 0,$vals['grid_import'] ?? 0,$unit]);

echo json_encode(['ok'=>true]);
?>