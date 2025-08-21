<?php
require_once __DIR__ . '/config.php';
function verify_hmac($raw_body) {
  global $PVDASH_REQUIRE_AUTH, $PVDASH_API_KEYS;
  if (!$PVDASH_REQUIRE_AUTH) return [true, null];
  $ts = $_SERVER['HTTP_X_PVDASH_TIMESTAMP'] ?? '';
  $dev = $_SERVER['HTTP_X_PVDASH_DEVICE'] ?? '';
  $sig = $_SERVER['HTTP_X_PVDASH_SIGNATURE'] ?? '';
  if (!$ts || !$dev || !$sig) return [false, 'missing headers'];
  if (!isset($PVDASH_API_KEYS[$dev])) return [false, 'unknown device'];
  $key = $PVDASH_API_KEYS[$dev];
  $calc = base64_encode(hash_hmac('sha256', $ts . '.' . $raw_body, $key, true));
  if (!hash_equals($calc, $sig)) return [false, 'bad signature'];
  return [true, null];
}
?>