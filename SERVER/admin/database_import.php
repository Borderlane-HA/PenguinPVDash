<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/web_auth.php';
require_once __DIR__ . '/../inc/i18n.php';
require_once __DIR__ . '/../inc/db.php';

pvdash_require_admin();

function database_import_redirect(string $type, string $message): never
{
    $_SESSION['pvdash_admin_' . $type] = $message;
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$lockHandle = null;
$newPath = '';
$oldPath = '';
try {
    $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength > 0 && $_POST === [] && $_FILES === []) {
        throw new RuntimeException(t('database_error_too_large'));
    }
    if (!pvdash_verify_csrf($_POST['csrf'] ?? null)) {
        throw new RuntimeException(t('auth_session_expired'));
    }
    if (empty($_POST['confirm_replace'])) {
        throw new RuntimeException(t('database_error_confirmation'));
    }
    if (!isset($_FILES['database_file']) || !is_array($_FILES['database_file'])) {
        throw new RuntimeException(t('database_error_upload'));
    }

    $file = $_FILES['database_file'];
    $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($uploadError !== UPLOAD_ERR_OK) {
        if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException(t('database_error_too_large'));
        }
        throw new RuntimeException(t('database_error_upload'));
    }

    $uploadedPath = (string) ($file['tmp_name'] ?? '');
    $uploadedSize = (int) ($file['size'] ?? 0);
    if ($uploadedPath === '' || !is_uploaded_file($uploadedPath)) {
        throw new RuntimeException(t('database_error_upload'));
    }
    if ($uploadedSize <= 0 || $uploadedSize > pvdash_database_import_max_bytes()) {
        throw new RuntimeException(t('database_error_too_large'));
    }

    // Validate before touching the live database.
    pvdash_validate_database_file($uploadedPath);

    $targetPath = pvdash_database_path();
    pvdash_ensure_database_directory($targetPath);
    $directory = dirname($targetPath);
    $token = bin2hex(random_bytes(8));
    $newPath = $directory . '/.pvdash-import-' . $token . '.sqlite';
    $oldPath = $directory . '/.pvdash-replaced-' . $token . '.sqlite';

    $lockHandle = pvdash_acquire_database_lock(LOCK_EX);

    if (!copy($uploadedPath, $newPath)) {
        throw new RuntimeException(t('database_error_copy'));
    }
    @chmod($newPath, 0660);

    // Bring older backups to the current schema before replacing the live DB.
    $newPdo = pvdash_open_database($newPath, true);
    pvdash_checkpoint_database($newPdo);
    $newPdo = null;
    @unlink($newPath . '-wal');
    @unlink($newPath . '-shm');
    pvdash_validate_database_file($newPath);

    $backupName = '';
    if (is_file($targetPath)) {
        $backupName = 'pvdash-before-import-' . date('Ymd-His') . '-' . substr($token, 0, 6) . '.sqlite';
        $backupPath = $directory . '/' . $backupName;
        try {
            pvdash_create_database_snapshot($targetPath, $backupPath);
        } catch (Throwable) {
            // A broken database may not support VACUUM INTO. Preserve its raw
            // main/WAL/SHM files anyway so a manual recovery remains possible.
            if (!copy($targetPath, $backupPath)) {
                throw new RuntimeException(t('database_error_backup'));
            }
            @chmod($backupPath, 0660);
            foreach (['-wal', '-shm'] as $suffix) {
                if (is_file($targetPath . $suffix)) {
                    @copy($targetPath . $suffix, $backupPath . $suffix);
                    @chmod($backupPath . $suffix, 0660);
                }
            }
        }
        @unlink($targetPath . '-wal');
        @unlink($targetPath . '-shm');

        if (!rename($targetPath, $oldPath)) {
            throw new RuntimeException(t('database_error_replace'));
        }
    }

    if (!rename($newPath, $targetPath)) {
        if ($oldPath !== '' && is_file($oldPath)) {
            @rename($oldPath, $targetPath);
        }
        throw new RuntimeException(t('database_error_replace'));
    }
    @chmod($targetPath, 0660);
    if ($oldPath !== '' && is_file($oldPath)) {
        @unlink($oldPath);
    }
    pvdash_prune_database_backups($directory);
    pvdash_release_database_lock($lockHandle);
    $lockHandle = null;

    $message = $backupName !== ''
        ? t('database_import_success_backup', ['backup' => $backupName])
        : t('database_import_success');
    database_import_redirect('message', $message);
} catch (Throwable $e) {
    if ($newPath !== '') {
        @unlink($newPath);
        @unlink($newPath . '-wal');
        @unlink($newPath . '-shm');
    }
    if ($oldPath !== '' && is_file($oldPath)) {
        $targetPath = pvdash_database_path();
        if (!is_file($targetPath)) {
            @rename($oldPath, $targetPath);
        }
    }
    pvdash_release_database_lock($lockHandle);
    database_import_redirect('error', $e->getMessage());
}
