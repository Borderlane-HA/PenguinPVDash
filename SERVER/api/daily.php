<?php
require_once __DIR__ . '/../inc/db.php';
header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$device = $_GET['device'] ?? 'home';
$days = max(1, min(60, intval($_GET['days'] ?? 30)));
$since = gmdate('Y-m-d', time() - ($days-1)*86400);
$st = $pdo->prepare('SELECT day,pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh FROM daily_totals WHERE device=? AND day>=? ORDER BY day ASC');
$st->execute([$device,$since]);
$out = [];
while ($r = $st->fetch(PDO::FETCH_ASSOC)) { $out[] = $r; }
echo json_encode(['device'=>$device,'days'=>$days,'items'=>$out], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>