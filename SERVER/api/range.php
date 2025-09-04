<?php
require_once __DIR__ . '/../inc/db.php';
header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$device = $_GET['device'] ?? 'home';
$start = $_GET['start'] ?? null;
$end = $_GET['end'] ?? null;
if (!$start || !$end) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'need start=YYYY-MM-DD&end=YYYY-MM-DD']); exit; }
$st = $pdo->prepare('SELECT day,pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh FROM daily_totals WHERE device=? AND day>=? AND day<=? ORDER BY day ASC');
$st->execute([$device,$start,$end]);
$out = [];
while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $out[] = $r; }
echo json_encode(['device'=>$device,'start'=>$start,'end'=>$end,'items'=>$out], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>