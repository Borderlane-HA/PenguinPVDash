<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/web_auth.php';
require_once __DIR__ . '/../inc/db.php';
pvdash_require_view(true);

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$device = (string) ($_GET['device'] ?? pvdash_default_device());
$st = $pdo->prepare('SELECT * FROM samples WHERE device=? ORDER BY ts DESC LIMIT 1');
$st->execute([$device]);
$row = $st->fetch();
$today = date('Y-m-d');
$dt = $pdo->prepare('SELECT pv_kwh,feed_in_kwh,batt_in_kwh,batt_out_kwh,consumption_kwh,grid_import_kwh,manual_lock FROM daily_totals WHERE device=? AND day=?');
$dt->execute([$device, $today]);
$tot = $dt->fetch();
echo json_encode(['latest' => $row ?: null, 'today' => $tot ?: null, 'day' => $today], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
