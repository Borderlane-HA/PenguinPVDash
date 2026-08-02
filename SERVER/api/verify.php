<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/auth.php';

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
if ($raw === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'body unavailable']);
    exit;
}

[$ok, $error] = verify_hmac($raw);
if (!$ok) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => $error]);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid json']);
    exit;
}

$device = trim((string) ($payload['device'] ?? ''));
if (preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $device) !== 1) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid device']);
    exit;
}

$authenticatedDevice = (string) ($_SERVER['HTTP_X_PVDASH_DEVICE'] ?? '');
if ($PVDASH_REQUIRE_AUTH && $authenticatedDevice !== $device) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'device mismatch']);
    exit;
}

echo json_encode([
    'ok' => true,
    'service' => 'PenguinPVDash',
    'version' => (string) (getenv('PVDASH_VERSION') ?: '1.8.1'),
    'device' => $device,
    'authentication_verified' => true,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
