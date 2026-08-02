<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/web_auth.php';
require_once __DIR__ . '/../inc/db.php';
pvdash_require_stats(true);

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$device = (string) ($_GET['device'] ?? 'home');
$start = (string) ($_GET['start'] ?? '');
$end = (string) ($_GET['end'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'need start=YYYY-MM-DD&end=YYYY-MM-DD']);
    exit;
}
$st = $pdo->prepare('SELECT day,pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh,manual_lock FROM daily_totals WHERE device=? AND day>=? AND day<=? ORDER BY day ASC');
$st->execute([$device, $start, $end]);
echo json_encode(['device' => $device, 'start' => $start, 'end' => $end, 'items' => $st->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
