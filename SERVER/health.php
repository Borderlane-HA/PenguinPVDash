<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/db.php';
header('Content-Type: application/json; charset=utf-8');
try {
    db()->query('SELECT 1')->fetchColumn();
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['ok' => false]);
}
