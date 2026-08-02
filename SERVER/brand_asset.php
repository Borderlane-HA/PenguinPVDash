<?php

declare(strict_types=1);

require_once __DIR__ . '/inc/config.php';
require_once __DIR__ . '/inc/ui.php';

$path = pvdash_custom_logo_path();
if ($path === null) {
    http_response_code(404);
    exit;
}

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$types = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
];
if (!isset($types[$extension])) {
    http_response_code(415);
    exit;
}

header('Content-Type: ' . $types[$extension]);
header('Content-Length: ' . (string) filesize($path));
header('Cache-Control: public, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($path);
