<?php
require_once __DIR__ . '/../inc/db.php';
header('Content-Type: application/json; charset=utf-8');

$pdo    = db();
$device = $_GET['device'] ?? 'home';

// Versuche zuerst aus daily_totals zu lesen
$st = $pdo->prepare("SELECT MIN(day) AS min_day, MAX(day) AS max_day FROM daily_totals WHERE device=?");
$st->execute([$device]);
$row = $st->fetch(PDO::FETCH_ASSOC);
$min = $row && $row['min_day'] ? $row['min_day'] : null;
$max = $row && $row['max_day'] ? $row['max_day'] : null;

// Fallback: aus samples ableiten (falls daily_totals noch leer)
if (!$min || !$max) {
  $st2 = $pdo->prepare("SELECT MIN(ts) AS min_ts, MAX(ts) AS max_ts FROM samples WHERE device=?");
  $st2->execute([$device]);
  $r2 = $st2->fetch(PDO::FETCH_ASSOC);
  if ($r2 && $r2['min_ts']) { $min = date('Y-m-d', intval($r2['min_ts'])); }
  if ($r2 && $r2['max_ts']) { $max = date('Y-m-d', intval($r2['max_ts'])); }
}

echo json_encode([
  'device'  => $device,
  'min_day' => $min,   // z.B. "2024-03-01"
  'max_day' => $max
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
