<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/web_auth.php';
require_once __DIR__ . '/../inc/i18n.php';
require_once __DIR__ . '/../inc/db.php';

pvdash_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if (!pvdash_verify_csrf($_POST['csrf'] ?? null)) {
    http_response_code(403);
    echo th('auth_session_expired');
    exit;
}

$lockHandle = null;
$tempPath = '';
try {
    $lockHandle = pvdash_acquire_database_lock(LOCK_EX);
    $tempPath = dirname(pvdash_database_path())
        . DIRECTORY_SEPARATOR
        . '.pvdash-export-' . bin2hex(random_bytes(8)) . '.sqlite';
    pvdash_create_database_snapshot(pvdash_database_path(), $tempPath);
} catch (Throwable $e) {
    pvdash_release_database_lock($lockHandle);
    if ($tempPath !== '') {
        @unlink($tempPath);
    }
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>PenguinPVDash</title>';
    echo '<p>' . htmlspecialchars(t('database_export_failed') . ' ' . $e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p>';
    echo '<p><a href="index.php">' . th('database_back_admin') . '</a></p>';
    exit;
}
pvdash_release_database_lock($lockHandle);

$downloadName = 'penguinpvdash-backup-' . date('Ymd-His') . '.sqlite';
$size = filesize($tempPath);
header('Content-Type: application/vnd.sqlite3');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . (string) $size);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

session_write_close();
readfile($tempPath);
@unlink($tempPath);
exit;
