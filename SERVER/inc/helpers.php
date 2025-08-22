<?php
function as_kW($v,$u){ if ($v===null) return null; $u = strtolower($u); return ($u==='w') ? $v/1000.0 : $v; }
function day_str($ts){ return date('Y-m-d', $ts); }
function midnight_ts_of($ts){ $d = date('Y-m-d', $ts); return strtotime($d.' 00:00:00'); }
function split_interval($t0,$v0,$t1,$v1){
  $out = [];
  if (!$t0 || !$t1 || $t1 <= $t0) return $out;
  $d0 = day_str($t0); $d1 = day_str($t1);
  if ($d0 === $d1) { $dt = ($t1 - $t0)/3600.0; $out[$d0] = ($v0 + $v1)/2.0 * $dt; return $out; }
  $mid = midnight_ts_of($t1);
  if ($mid <= $t0 || $mid >= $t1) { $dt = ($t1 - $t0)/3600.0; $out[$d0] = ($v0 + $v1)/2.0 * $dt; return $out; }
  $r = ($mid - $t0) / ($t1 - $t0); $vmid = $v0 + ($v1 - $v0) * $r;
  $dt1 = ($mid - $t0)/3600.0; $dt2 = ($t1 - $mid)/3600.0;
  $out[$d0] = ($v0 + $vmid)/2.0 * $dt1; $out[$d1] = ($vmid + $v1)/2.0 * $dt2; return $out;
}
?>