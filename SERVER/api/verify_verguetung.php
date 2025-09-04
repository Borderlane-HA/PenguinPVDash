<?php
// api/verify_verguetung.php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../inc/config.php';

// Falls kein Code gesetzt ist -> niemals freigeben
$expected = isset($verguetung_code) ? (string)$verguetung_code : '';
if ($expected === '') {
    echo json_encode(['ok' => false]); exit;
}

// Eingabe lesen (JSON oder x-www-form-urlencoded)
$input = file_get_contents('php://input');
$data  = json_decode($input, true);
if (!is_array($data)) {
    $data = $_POST;
}
$code = isset($data['code']) ? (string)$data['code'] : '';

// Vergleich timing-sicher mit Hash
$ok = hash_equals(hash('sha256', $expected), hash('sha256', $code));
echo json_encode(['ok' => $ok]);
