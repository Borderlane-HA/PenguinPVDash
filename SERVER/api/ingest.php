function apply_metric(&$T,&$prevAdds,$key,$total_key,$last_key,$now_val_raw,$unit,$state,$ts){
  $today = date('Y-m-d',$ts);

  // Tages-Totals aus Payload (kWh)?
  $total = $total_key ? (isset($GLOBALS['vals'][$total_key]) ? $GLOBALS['vals'][$total_key] : null) : null;

  if ($total !== null) {
    $accepted = floatval($total);

    // Tagwechsel?
    $rolled = $state && !empty($state['last_ts']) && date('Y-m-d', intval($state['last_ts'])) !== $today;

    // Schonfrist nach Mitternacht
    $mins_since_midnight = ($ts - strtotime($today.' 00:00:00')) / 60.0;
    $RESET_EPS_KWH = 0.5;  // „klein“ genug für frisches Total
    $GRACE_MIN     = 10;   // Minuten Schonfrist

    $may_use_total = (!$rolled) || ($accepted <= $RESET_EPS_KWH) || ($mins_since_midnight >= $GRACE_MIN);

    if ($may_use_total) {
      // WICHTIG: Bei neuem Tag den Sensor-Startwert setzen (nicht max),
      // ansonsten (gleicher Tag) weiterhin max zur Entstörung.
      if ($rolled) {
        $T[$key] = $accepted;
      } else {
        $T[$key] = max($T[$key], $accepted);
      }
      return;
    }
    // sonst: Total ignorieren und unten aus Leistung integrieren
  }

  // --- Integration aus Leistung (kW) zwischen last_ts und ts ---
  if (!$state || empty($state['last_ts'])) return;
  $t0 = intval($state['last_ts']); $t1 = intval($ts); if ($t1 <= $t0) return;

  $last_unit = $state['last_unit'] ?? $unit;
  $v0 = as_kW(floatval($state[$last_key] ?? 0), $last_unit);
  $v1 = as_kW(floatval($now_val_raw ?? 0), $unit);

  $parts = split_interval($t0,$v0,$t1,$v1);
  foreach ($parts as $day=>$kwh){
    if ($day === $today) $T[$key] += $kwh;
    else $prevAdds[$key] += $kwh;
  }
}
