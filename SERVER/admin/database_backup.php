<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/web_auth.php';
require_once __DIR__ . '/../inc/i18n.php';
require_once __DIR__ . '/../inc/db.php';

pvdash_require_admin();

function database_backup_redirect(string $type, string $message): never
{
    $_SESSION['pvdash_admin_' . $type] = $message;
    header('Location: index.php');
    exit;
}

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$filename = (string) ($_GET['file'] ?? $_POST['file'] ?? '');

try {
    $path = pvdash_database_backup_path($filename);

    if ($action === 'download') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
            http_response_code(405);
            exit;
        }
        header('Content-Type: application/vnd.sqlite3');
        header('Content-Disposition: attachment; filename="' . basename($path) . '"');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        session_write_close();
        readfile($path);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        exit;
    }
    if (!pvdash_verify_csrf($_POST['csrf'] ?? null)) {
        throw new RuntimeException(t('auth_session_expired'));
    }

    if ($action === 'delete') {
        if (!unlink($path)) {
            throw new RuntimeException(t('database_backup_delete_failed'));
        }
        @unlink($path . '-wal');
        @unlink($path . '-shm');
        database_backup_redirect('message', t('database_backup_deleted'));
    }

    if ($action === 'restore') {
        $restoredName = basename($path);
        $backupName = pvdash_replace_database_from_file($path);
        $message = t('database_backup_restored', ['backup' => $restoredName]);
        if ($backupName !== '') {
            $message .= ' ' . t('database_backup_previous_saved', ['backup' => $backupName]);
        }
        database_backup_redirect('message', $message);
    }

    throw new InvalidArgumentException('Unknown backup action.');
} catch (Throwable $e) {
    database_backup_redirect('error', $e->getMessage());
}
