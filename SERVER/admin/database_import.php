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

$preparedPath = '';
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

    pvdash_validate_database_file($uploadedPath);
    $sourcePath = $uploadedPath;
    $adoptedFrom = '';
    $adoptedTo = '';

    if (!empty($_POST['adopt_single_device'])) {
        $preparedPath = dirname(pvdash_database_path())
            . DIRECTORY_SEPARATOR
            . '.pvdash-adopt-' . bin2hex(random_bytes(8)) . '.sqlite';
        if (!copy($uploadedPath, $preparedPath)) {
            throw new RuntimeException(t('database_error_copy'));
        }
        @chmod($preparedPath, 0660);
        $preparedPdo = pvdash_open_database($preparedPath, true);
        $dataDevices = pvdash_data_devices($preparedPdo);
        if (count($dataDevices) !== 1) {
            throw new RuntimeException(t('database_adopt_requires_single_device'));
        }
        $adoptedFrom = (string) $dataDevices[0];
        $adoptedTo = pvdash_default_device();
        if ($adoptedFrom !== $adoptedTo) {
            pvdash_rename_device_data($preparedPdo, $adoptedFrom, $adoptedTo, false);
        }
        pvdash_checkpoint_database($preparedPdo);
        $preparedPdo = null;
        @unlink($preparedPath . '-wal');
        @unlink($preparedPath . '-shm');
        pvdash_validate_database_file($preparedPath);
        $sourcePath = $preparedPath;
    }

    $backupName = pvdash_replace_database_from_file($sourcePath);
    if ($preparedPath !== '') {
        @unlink($preparedPath);
        $preparedPath = '';
    }

    $message = $backupName !== ''
        ? t('database_import_success_backup', ['backup' => $backupName])
        : t('database_import_success');
    if ($adoptedFrom !== '' && $adoptedFrom !== $adoptedTo) {
        $message .= ' ' . t('database_adopt_success', ['from' => $adoptedFrom, 'to' => $adoptedTo]);
    }
    database_import_redirect('message', $message);
} catch (Throwable $e) {
    if ($preparedPath !== '') {
        @unlink($preparedPath);
        @unlink($preparedPath . '-wal');
        @unlink($preparedPath . '-shm');
    }
    database_import_redirect('error', $e->getMessage());
}
