<?php
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/helpers.php';

header('Content-Type: application/json; charset=utf-8');

/* -----------------------------------------------------------
 * Quiet-window Helper (lokale Serverzeit)
 * ---------------------------------------------------------*/
function _pvdash_in_quiet_window($ts){
  if (empty($GLOBALS['PVDASH_QUIET_WINDOW_ENABLED'])) return false;
  // nutzt Payload-TS (wie zuvor)
  $min = intval(date('G',$ts))*60 + intval(date('i',$ts)); // Minuten seit 00:00
  $start = intval($GLOBALS['PVDASH_QUIET_START_MIN'] ?? (23*60+58));
  $end   = intval($GLOBALS['PVDASH_QUIET_END_MIN']   ?? 2);
  if ($end < $start) {
    return ($min >= $start) || ($min <= $end);
  } else {
    return ($min >= $start) && ($min <= $end);
  }
}

/* -----------------------------------------------------------
 * HMAC-Auth (optional)
 * ---------------------------------------------------------*/
$raw = file_get_contents('php://input');
list($ok,$err) = verify_hmac($raw);
if (!$ok) { http_response_code(401); echo json_encode(['ok'=>false,'error'=>$err]); exit; }

/* -----------------------------------------------------------
 * Payload
 * ---------------------------------------------------------*/
$pdo = db();
$payload = json_decode($raw, true);
if (!$payload) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'invalid json']); exit; }

$ts     = intval($payload['ts'] ?? time());
$device = $payload['device'] ?? 'home';
$unit   = strtolower(trim($payload['unit'] ?? 'kW')); // 'kW' oder 'w'
$unit   = ($unit === 'w') ? 'w' : 'kW';

$keys = [
  'pv_power','battery_charge','battery_discharge','feed_in','consumption','grid_import','battery_soc',
  'pv_total_kwh','feed_in_total_kwh','batt_in_total_kwh','batt_out_total_kwh','consumption_total_kwh','grid_import_total_kwh'
];
$vals = [];
foreach ($keys as $k) { $vals[$k] = array_key_exists($k,$payload) ? ($payload[$k] === null ? null : floatval($payload[$k])) : null; }
$GLOBALS['vals'] = $vals; // für apply_metric()

/* -----------------------------------------------------------
 * Quiet-window anwenden (wie zuvor)
 * ---------------------------------------------------------*/
$in_quiet = _pvdash_in_quiet_window($ts);
if ($in_quiet) {
  if (($GLOBALS['PVDASH_QUIET_MODE'] ?? 'ignore_totals') === 'drop_all') {
    echo json_encode(['ok'=>true,'note'=>'quiet-window drop_all']); exit;
  } else {
    foreach ([
      'pv_total_kwh','feed_in_total_kwh','batt_in_total_kwh',
      'batt_out_total_kwh','consumption_total_kwh','grid_import_total_kwh'
    ] as $k) { $vals[$k] = null; }
    $GLOBALS['vals'] = $vals; // aktualisieren
  }
}

/* -----------------------------------------------------------
 * Letztes Sample (vor aktuellem Insert) laden
 * ---------------------------------------------------------*/
$prevSel = $pdo->prepare('SELECT ts, 
  pv_total_kwh, feed_in_total_kwh, batt_in_total_kwh, batt_out_total_kwh, consumption_total_kwh, grid_import_total_kwh
  FROM samples WHERE device=? ORDER BY ts DESC LIMIT 1');
$prevSel->execute([$device]);
$prevRow = $prevSel->fetch(PDO::FETCH_ASSOC);
$GLOBALS['PVDASH_PREV_TS'] = $prevRow ? intval($prevRow['ts']) : null;

/* -----------------------------------------------------------
 * *** PV-ONLY: Drop-Erkennung für PV gesamt ***
 * Wenn pv_total_kwh_now < pv_total_kwh_prev - eps,
 * und (Nacht ODER Kalendertag gewechselt), dann "roll by PV".
 * ---------------------------------------------------------*/
$PV_DROP_EPS = floatval($GLOBALS['PVDASH_MONO_DROP_EPS_KWH'] ?? 0.05);
$NIGHT_THR   = floatval($GLOBALS['PVDASH_PV_NIGHT_THRESHOLD_KW'] ?? 0.05);

$pv_prev = ($prevRow && $prevRow['pv_total_kwh'] !== null) ? floatval($prevRow['pv_total_kwh']) : null;
$pv_now  = ($vals['pv_total_kwh'] !== null) ? floatval($vals['pv_total_kwh']) : null;

$pv_drop  = ($pv_prev !== null && $pv_now !== null && ($pv_now + $PV_DROP_EPS) < $pv_prev);
$pv_night = as_kW(floatval($vals['pv_power'] ?? 0), $unit) <= $NIGHT_THR;
$ts_day_changed = ($prevRow ? (date('Y-m-d', intval($prevRow['ts'])) !== date('Y-m-d', $ts)) : false);

$ROLL_BY_PV = $pv_drop && ($pv_night || $ts_day_changed);

/* -----------------------------------------------------------
 * Optional: Legacy-Reset für andere Zähler wie zuvor
 * (Anker <= EPS) – NICHT für PV forciert.
 * ---------------------------------------------------------*/
$RESET_EPS_KWH = floatval($GLOBALS['PVDASH_RESET_EPS_KWH'] ?? 0.5);
$anchors_other = [
  'consumption_total_kwh',
  // 'pv_total_kwh',  // PV wird separat behandelt
  'grid_import_total_kwh',
  'feed_in_total_kwh',
  'batt_in_total_kwh',
  'batt_out_total_kwh',
];
$resetDetectedLegacy = false;
if ($prevRow && !$ROLL_BY_PV) {
  foreach ($anchors_other as $ak) {
    $now  = $vals[$ak] ?? null;
    $prev = ($prevRow[$ak] !== null) ? floatval($prevRow[$ak]) : null;
    if ($now !== null && $prev !== null) {
      if ($prev > $RESET_EPS_KWH && $now <= $RESET_EPS_KWH) { $resetDetectedLegacy = true; break; }
    }
  }
}

/* -----------------------------------------------------------
 * Wenn PV-Roll: Vortag fixieren (mit prevRow Totals) + PV-Start heute merken
 * Wenn Legacy-Reset (nicht PV): Vortag wie gehabt fixieren
 * ---------------------------------------------------------*/
$did_fix_prevday = false;
$pv_today_start = null;

if ($ROLL_BY_PV && $prevRow) {
  $yesterday = date('Y-m-d', intval($prevRow['ts']));
  // Vortag laden
  $selDayPrev = $pdo->prepare('SELECT pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh
                               FROM daily_totals WHERE device=? AND day=?');
  $selDayPrev->execute([$device,$yesterday]);
  $had = $selDayPrev->fetch(PDO::FETCH_ASSOC);

  // Finalwerte aus prevRow
  $final = [
    'pv'   => floatval($prevRow['pv_total_kwh']          ?? 0.0),
    'fi'   => floatval($prevRow['feed_in_total_kwh']     ?? 0.0),
    'bi'   => floatval($prevRow['batt_in_total_kwh']     ?? 0.0),
    'bo'   => floatval($prevRow['batt_out_total_kwh']    ?? 0.0),
    'cons' => floatval($prevRow['consumption_total_kwh'] ?? 0.0),
    'imp'  => floatval($prevRow['grid_import_total_kwh'] ?? 0.0),
  ];

  // nie absenken
  $merged = [
    'pv'   => max(floatval($had['pv_kwh']           ?? 0.0), $final['pv']),
    'fi'   => max(floatval($had['feed_in_kwh']      ?? 0.0), $final['fi']),
    'bi'   => max(floatval($had['batt_in_kwh']      ?? 0.0), $final['bi']),
    'bo'   => max(floatval($had['batt_out_kwh']     ?? 0.0), $final['bo']),
    'cons' => max(floatval($had['consumption_kwh']  ?? 0.0), $final['cons']),
    'imp'  => max(floatval($had['grid_import_kwh']  ?? 0.0), $final['imp']),
  ];

  $upPrev = $pdo->prepare('INSERT INTO daily_totals (device,day,pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh,created_ts,updated_ts)
                           VALUES (?,?,?,?,?,?,?,?,?,?)
                           ON CONFLICT(device,day) DO UPDATE SET
                             pv_kwh=excluded.pv_kwh,
                             feed_in_kwh=excluded.feed_in_kwh,
                             batt_in_kwh=excluded.batt_in_kwh,
                             batt_out_kwh=excluded.batt_out_kwh,
                             consumption_kwh=excluded.consumption_kwh,
                             grid_import_kwh=excluded.grid_import_kwh,
                             updated_ts=excluded.updated_ts');
  $nowts = time();
  $upPrev->execute([$device,$yesterday,$merged['pv'],$merged['fi'],$merged['bi'],$merged['bo'],$merged['cons'],$merged['imp'],$nowts,$nowts]);
  $did_fix_prevday = true;

  // HEUTE: Startwert PV vormerken – nachts hart 0
  $pv_today_start = $pv_night ? 0.0 : max(0.0, $pv_now);
}
elseif ($resetDetectedLegacy && $prevRow) {
  // Legacy-Fix (für NICHT-PV-Werte) unverändert
  $yesterday = date('Y-m-d', intval($prevRow['ts']));
  $selDayPrev = $pdo->prepare('SELECT pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh
                               FROM daily_totals WHERE device=? AND day=?');
  $selDayPrev->execute([$device,$yesterday]);
  $had = $selDayPrev->fetch(PDO::FETCH_ASSOC);

  $final = [
    'pv'   => floatval($prevRow['pv_total_kwh']          ?? 0.0),
    'fi'   => floatval($prevRow['feed_in_total_kwh']     ?? 0.0),
    'bi'   => floatval($prevRow['batt_in_total_kwh']     ?? 0.0),
    'bo'   => floatval($prevRow['batt_out_total_kwh']    ?? 0.0),
    'cons' => floatval($prevRow['consumption_total_kwh'] ?? 0.0),
    'imp'  => floatval($prevRow['grid_import_total_kwh'] ?? 0.0),
  ];

  $merged = [
    'pv'   => max(floatval($had['pv_kwh']           ?? 0.0), $final['pv']),
    'fi'   => max(floatval($had['feed_in_kwh']      ?? 0.0), $final['fi']),
    'bi'   => max(floatval($had['batt_in_kwh']      ?? 0.0), $final['bi']),
    'bo'   => max(floatval($had['batt_out_kwh']     ?? 0.0), $final['bo']),
    'cons' => max(floatval($had['consumption_kwh']  ?? 0.0), $final['cons']),
    'imp'  => max(floatval($had['grid_import_kwh']  ?? 0.0), $final['imp']),
  ];

  $upPrev = $pdo->prepare('INSERT INTO daily_totals (device,day,pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh,created_ts,updated_ts)
                           VALUES (?,?,?,?,?,?,?,?,?,?)
                           ON CONFLICT(device,day) DO UPDATE SET
                             pv_kwh=excluded.pv_kwh,
                             feed_in_kwh=excluded.feed_in_kwh,
                             batt_in_kwh=excluded.batt_in_kwh,
                             batt_out_kwh=excluded.batt_out_kwh,
                             consumption_kwh=excluded.consumption_kwh,
                             grid_import_kwh=excluded.grid_import_kwh,
                             updated_ts=excluded.updated_ts');
  $nowts = time();
  $upPrev->execute([$device,$yesterday,$merged['pv'],$merged['fi'],$merged['bi'],$merged['bo'],$merged['cons'],$merged['imp'],$nowts,$nowts]);
  $did_fix_prevday = true;
}

/* -----------------------------------------------------------
 * Raw-Sample jetzt persistieren (nach Reset-Check)
 * ---------------------------------------------------------*/
$ins = $pdo->prepare('INSERT INTO samples (device, ts, unit, pv_power, battery_charge, battery_discharge, feed_in, consumption, grid_import, battery_soc, pv_total_kwh, feed_in_total_kwh, batt_in_total_kwh, batt_out_total_kwh, consumption_total_kwh, grid_import_total_kwh)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
$ins->execute([
  $device,$ts,$unit,
  $vals['pv_power'],$vals['battery_charge'],$vals['battery_discharge'],
  $vals['feed_in'],$vals['consumption'],$vals['grid_import'],$vals['battery_soc'],
  $vals['pv_total_kwh'],$vals['feed_in_total_kwh'],$vals['batt_in_total_kwh'],$vals['batt_out_total_kwh'],
  $vals['consumption_total_kwh'],$vals['grid_import_total_kwh']
]);

/* -----------------------------------------------------------
 * Tagesstand & State laden
 * ---------------------------------------------------------*/
$stateSel = $pdo->prepare('SELECT last_ts,last_pv,last_feed_in,last_bi,last_bo,last_cons,last_gi,last_unit FROM integ_state WHERE device=?');
$stateUpSert = $pdo->prepare('INSERT INTO integ_state (device,last_ts,last_pv,last_feed_in,last_bi,last_bo,last_cons,last_gi,last_unit) VALUES (?,?,?,?,?,?,?,?,?)
ON CONFLICT(device) DO UPDATE SET last_ts=excluded.last_ts,last_pv=excluded.last_pv,last_feed_in=excluded.last_feed_in,last_bi=excluded.last_bi,last_bo=excluded.last_bo,last_cons=excluded.last_cons,last_gi=excluded.last_gi,last_unit=excluded.last_unit');

$today = date('Y-m-d'); // Servertag
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

/* -----------------------------------------------------------
 * apply_metric – wie zuvor, mit kleinem PV-Spezial-Handling
 * ---------------------------------------------------------*/
function apply_metric(&$T,&$prevAdds,$key,$total_key,$last_key,$now_val_raw,$unit,$state,$ts){
  // Servertag
  $todayLocal = date('Y-m-d');

  $trust_midnight = !empty($GLOBALS['PVDASH_TRUST_MIDNIGHT_RESETS']);
  $EARLY_FIX_MIN  = intval($GLOBALS['PVDASH_EARLY_FIX_MIN'] ?? 30);
  $RESET_EPS_KWH  = floatval($GLOBALS['PVDASH_RESET_EPS_KWH'] ?? 0.5);

  // gleicher/neuer Tag via integ_state
  $rolled_state = $state && !empty($state['last_ts']) && date('Y-m-d', intval($state['last_ts'])) !== $todayLocal;
  $mins_since_midnight = (time() - strtotime($todayLocal.' 00:00:00')) / 60.0;

  // PV: Falls wir heute explizit einen Startwert gesetzt haben, zunächst anwenden
  if ($key === 'pv' && ($GLOBALS['pv_today_start_applied'] ?? false) === false && $GLOBALS['pv_today_start'] !== null) {
    $T['pv'] = floatval($GLOBALS['pv_today_start']);
    $GLOBALS['pv_today_start_applied'] = true;
  }

  // Totals aus Payload zuerst
  $total = $total_key ? ($GLOBALS['vals'][$total_key] ?? null) : null;
  if ($total !== null) {
    $accepted = floatval($total);

    if ($rolled_state) {
      if ($trust_midnight || $accepted <= $RESET_EPS_KWH) { $T[$key] = $accepted; return; }
      // sonst ignorieren → unten integrieren
    } else {
      // PV nach gesetztem Start nur nach oben wachsen lassen
      if ($key === 'pv' && ($GLOBALS['pv_today_start_applied'] ?? false)) {
        $T[$key] = max($T[$key], $accepted); return;
      }
      if ($trust_midnight && $mins_since_midnight <= $EARLY_FIX_MIN && $accepted < $T[$key]) {
        $T[$key] = $accepted; return;
      }
      $T[$key] = max($T[$key], $accepted); return;
    }
  }

  // Integration aus Leistung (kW)
  if (!$state || empty($state['last_ts'])) return;
  $t0 = intval($state['last_ts']); $t1 = intval($ts); if ($t1 <= $t0) return;

  $last_unit = $state['last_unit'] ?? $unit;
  $v0 = as_kW(floatval($state[$last_key] ?? 0), $last_unit);
  $v1 = as_kW(floatval($now_val_raw ?? 0), $unit);

  $parts = split_interval($t0,$v0,$t1,$v1);
  foreach ($parts as $day=>$kwh){
    if ($day === $todayLocal) $T[$key] += $kwh;
    else $prevAdds[$key] += $kwh; // Vortag gutschreiben
  }
}

/* -----------------------------------------------------------
 * PV-Startwert an apply_metric signalisieren
 * ---------------------------------------------------------*/
$GLOBALS['pv_today_start'] = $pv_today_start;

/* -----------------------------------------------------------
 * Feed-in robust ableiten (falls kein feed_in geliefert)
 * ---------------------------------------------------------*/
$fi_now = $vals['feed_in'];
if ($fi_now === null && $vals['grid_import'] !== null) {
  $fi_now = max(-1.0 * $vals['grid_import'], 0.0);
}

/* -----------------------------------------------------------
 * Integration/Update je Metrik (andere bleiben wie zuvor)
 * ---------------------------------------------------------*/
$prevAdds = ['pv'=>0,'fi'=>0,'bi'=>0,'bo'=>0,'cons'=>0,'imp'=>0];

apply_metric($T,$prevAdds,'pv','pv_total_kwh','last_pv',$vals['pv_power'],$unit,$state,$ts);
apply_metric($T,$prevAdds,'fi','feed_in_total_kwh','last_feed_in',$fi_now,$unit,$state,$ts);
apply_metric($T,$prevAdds,'bi','batt_in_total_kwh','last_bi',$vals['battery_charge'],$unit,$state,$ts);
apply_metric($T,$prevAdds,'bo','batt_out_total_kwh','last_bo',$vals['battery_discharge'],$unit,$state,$ts);
apply_metric($T,$prevAdds,'cons','consumption_total_kwh','last_cons',$vals['consumption'],$unit,$state,$ts);
apply_metric($T,$prevAdds,'imp','grid_import_total_kwh','last_gi',$vals['grid_import'],$unit,$state,$ts);

/* -----------------------------------------------------------
 * Vortagsanteile (bei Mitternachtssplit) nachtragen
 * ---------------------------------------------------------*/
if (!empty($state['last_ts'])) {
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
}

/* -----------------------------------------------------------
 * Heutigen Tag persistieren & State aktualisieren
 * ---------------------------------------------------------*/
$upsertDay->execute([$device,$today,$T['pv'],$T['fi'],$T['bi'],$T['bo'],$T['cons'],$T['imp'],time(),time()]);

$stateUpSert->execute([
  $device,$ts,
  $vals['pv_power'] ?? 0,
  $fi_now ?? 0,
  $vals['battery_charge'] ?? 0,
  $vals['battery_discharge'] ?? 0,
  $vals['consumption'] ?? 0,
  $vals['grid_import'] ?? 0,
  $unit
]);

echo json_encode(['ok'=>true]);
