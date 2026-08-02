<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/web_auth.php';
require_once __DIR__ . '/../inc/db.php';
pvdash_require_view(true);

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$device = (string) ($_GET['device'] ?? 'home');
$maximumDays = pvdash_can_view_stats() ? 3650 : 30;
$days = max(1, min($maximumDays, (int) ($_GET['days'] ?? 30)));
$since = date('Y-m-d', time() - ($days - 1) * 86400);
$st = $pdo->prepare('SELECT day,pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh,manual_lock FROM daily_totals WHERE device=? AND day>=? ORDER BY day ASC');
$st->execute([$device, $since]);
echo json_encode(['device' => $device, 'days' => $days, 'items' => $st->fetchAll()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
