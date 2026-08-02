<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/web_auth.php';
require_once __DIR__ . '/../inc/db.php';
pvdash_require_stats(true);

header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$device = (string) ($_GET['device'] ?? pvdash_default_device());
$st = $pdo->prepare('SELECT MIN(day) AS min_day, MAX(day) AS max_day FROM daily_totals WHERE device=?');
$st->execute([$device]);
$row = $st->fetch();
$min = $row['min_day'] ?? null;
$max = $row['max_day'] ?? null;
if (!$min || !$max) {
    $st2 = $pdo->prepare('SELECT MIN(ts) AS min_ts, MAX(ts) AS max_ts FROM samples WHERE device=?');
    $st2->execute([$device]);
    $r2 = $st2->fetch();
    if (!empty($r2['min_ts'])) $min = date('Y-m-d', (int) $r2['min_ts']);
    if (!empty($r2['max_ts'])) $max = date('Y-m-d', (int) $r2['max_ts']);
}
echo json_encode(['device' => $device, 'min_day' => $min, 'max_day' => $max], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
