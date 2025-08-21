<?php
require_once __DIR__ . '/../inc/db.php';
header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$device = $_GET['device'] ?? 'home';
$st = $pdo->prepare('SELECT * FROM samples WHERE device=? ORDER BY ts DESC LIMIT 1');
$st->execute([$device]);
$row = $st->fetch(PDO::FETCH_ASSOC);
$today = gmdate('Y-m-d');
$dt = $pdo->prepare('SELECT pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh FROM daily_totals WHERE device=? AND day=?');
$dt->execute([$device,$today]);
$tot = $dt->fetch(PDO::FETCH_ASSOC);
echo json_encode(['latest'=>$row, 'today'=>$tot, 'day'=>$today], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>