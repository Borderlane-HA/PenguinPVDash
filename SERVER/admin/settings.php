<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/web_auth.php';
require_once __DIR__ . '/../inc/i18n.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/ui.php';

pvdash_require_admin();
$pdo = db();
$message = (string) ($_SESSION['pvdash_settings_message'] ?? '');
$error = (string) ($_SESSION['pvdash_settings_error'] ?? '');
unset($_SESSION['pvdash_settings_message'], $_SESSION['pvdash_settings_error']);

function settings_redirect(string $message = '', string $error = ''): never
{
    if ($message !== '') {
        $_SESSION['pvdash_settings_message'] = $message;
    }
    if ($error !== '') {
        $_SESSION['pvdash_settings_error'] = $error;
    }
    header('Location: settings.php');
    exit;
}

function settings_password_valid(string $password, int $minimum): bool
{
    return strlen($password) >= $minimum && strlen($password) <= 512;
}

function settings_remove_custom_logo(): void
{
    $directory = dirname((string) pvdash_config('sqlite_path'));
    foreach (glob($directory . '/branding-logo.*') ?: [] as $file) {
        if (preg_match('/branding-logo\.(png|jpe?g|webp)$/i', basename($file)) === 1) {
            @unlink($file);
        }
    }
}

function settings_process_logo_upload(): ?string
{
    if (!isset($_FILES['custom_logo']) || !is_array($_FILES['custom_logo'])) {
        return null;
    }
    $file = $_FILES['custom_logo'];
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException(t('settings_logo_upload_failed'));
    }
    $size = (int) ($file['size'] ?? 0);
    $tmp = (string) ($file['tmp_name'] ?? '');
    if ($size <= 0 || $size > 2 * 1024 * 1024 || $tmp === '' || !is_uploaded_file($tmp)) {
        throw new RuntimeException(t('settings_logo_invalid'));
    }

    $info = @getimagesize($tmp);
    if (!is_array($info) || ($info[0] ?? 0) < 32 || ($info[1] ?? 0) < 32 || ($info[0] ?? 0) > 4096 || ($info[1] ?? 0) > 4096) {
        throw new RuntimeException(t('settings_logo_invalid'));
    }
    $mime = (string) ($info['mime'] ?? '');
    $extensions = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) {
        throw new RuntimeException(t('settings_logo_invalid'));
    }

    $directory = dirname((string) pvdash_config('sqlite_path'));
    if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
        throw new RuntimeException(t('settings_logo_upload_failed'));
    }
    $filename = 'branding-logo.' . $extensions[$mime];
    $target = $directory . DIRECTORY_SEPARATOR . $filename;
    $temporaryTarget = $directory . DIRECTORY_SEPARATOR . '.branding-upload-' . bin2hex(random_bytes(6)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($tmp, $temporaryTarget)) {
        throw new RuntimeException(t('settings_logo_upload_failed'));
    }
    @chmod($temporaryTarget, 0660);
    settings_remove_custom_logo();
    if (!rename($temporaryTarget, $target)) {
        @unlink($temporaryTarget);
        throw new RuntimeException(t('settings_logo_upload_failed'));
    }
    @chmod($target, 0660);
    return $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!pvdash_verify_csrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException(t('auth_session_expired'));
        }

        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'save_general') {
            $defaultDevice = trim((string) ($_POST['default_device'] ?? ''));
            if (!pvdash_valid_device_id($defaultDevice)) {
                throw new InvalidArgumentException(t('settings_invalid_device'));
            }

            $feedRaw = str_replace(',', '.', trim((string) ($_POST['feed_in_ct'] ?? '0')));
            if ($feedRaw === '' || !is_numeric($feedRaw)) {
                throw new InvalidArgumentException(t('settings_invalid_feed_in'));
            }
            $feedIn = (float) $feedRaw;
            if ($feedIn < 0 || $feedIn > 1000) {
                throw new InvalidArgumentException(t('settings_invalid_feed_in'));
            }

            $backupRetention = (int) ($_POST['backup_retention'] ?? 5);
            if ($backupRetention < 1 || $backupRetention > 25) {
                throw new InvalidArgumentException(t('settings_invalid_backup_retention'));
            }

            pvdash_runtime_settings_update([
                'default_device' => $defaultDevice,
                'feed_in_ct' => $feedIn,
                'backup_retention' => $backupRetention,
                'guest_can_view_stats' => isset($_POST['guest_can_view_stats']),
                'guest_can_view_compensation' => isset($_POST['guest_can_view_compensation']),
            ]);
            pvdash_prune_database_backups(dirname(pvdash_database_path()));
            settings_redirect(t('settings_general_saved'));
        }

        if ($action === 'save_appearance') {
            $siteTitle = trim((string) ($_POST['site_title'] ?? ''));
            if ($siteTitle === '' || (function_exists('mb_strlen') ? mb_strlen($siteTitle) : strlen($siteTitle)) > 80) {
                throw new InvalidArgumentException(t('settings_site_title_invalid'));
            }
            $theme = (string) ($_POST['theme'] ?? 'standard');
            $accent = strtolower((string) ($_POST['accent_color'] ?? '#4e8cff'));
            $density = (string) ($_POST['table_density'] ?? 'comfortable');
            if (!in_array($theme, ['standard', 'dark', 'light'], true)) {
                throw new InvalidArgumentException(t('settings_theme_invalid'));
            }
            if (!pvdash_valid_hex_color($accent)) {
                throw new InvalidArgumentException(t('settings_color_invalid'));
            }
            if (!in_array($density, ['comfortable', 'compact'], true)) {
                throw new InvalidArgumentException(t('settings_density_invalid'));
            }

            $metricColors = [];
            foreach (pvdash_metric_keys() as $metric) {
                $min = strtolower((string) ($_POST['metric_' . $metric . '_min'] ?? ''));
                $max = strtolower((string) ($_POST['metric_' . $metric . '_max'] ?? ''));
                if (!pvdash_valid_hex_color($min) || !pvdash_valid_hex_color($max)) {
                    throw new InvalidArgumentException(t('settings_color_invalid'));
                }
                $metricColors[$metric] = ['min' => $min, 'max' => $max];
            }

            $patch = [
                'site_title' => $siteTitle,
                'theme' => $theme,
                'accent_color' => $accent,
                'table_density' => $density,
                'highlight_extremes' => isset($_POST['highlight_extremes']),
                'metric_colors' => $metricColors,
            ];

            if (!empty($_POST['remove_logo'])) {
                settings_remove_custom_logo();
                $patch['custom_logo_file'] = '';
            } else {
                $uploadedLogo = settings_process_logo_upload();
                if ($uploadedLogo !== null) {
                    $patch['custom_logo_file'] = $uploadedLogo;
                }
            }

            pvdash_runtime_settings_update($patch);
            settings_redirect(t('settings_appearance_saved'));
        }

        if ($action === 'reset_appearance') {
            settings_remove_custom_logo();
            pvdash_runtime_settings_update([
                'site_title' => 'PenguinPVDash',
                'theme' => 'standard',
                'accent_color' => '#4e8cff',
                'table_density' => 'comfortable',
                'highlight_extremes' => true,
                'metric_colors' => pvdash_default_metric_colors(),
                'custom_logo_file' => '',
            ]);
            settings_redirect(t('settings_appearance_reset_done'));
        }

        if ($action === 'change_admin_password') {
            $current = (string) ($_POST['current_admin_password'] ?? '');
            $new = (string) ($_POST['new_admin_password'] ?? '');
            $confirm = (string) ($_POST['confirm_admin_password'] ?? '');

            if (!pvdash_password_matches((string) pvdash_config('admin_password', ''), $current)) {
                throw new RuntimeException(t('settings_current_password_wrong'));
            }
            if (!settings_password_valid($new, 8)) {
                throw new RuntimeException(t('settings_admin_password_length'));
            }
            if (!hash_equals($new, $confirm)) {
                throw new RuntimeException(t('settings_password_mismatch'));
            }

            $hash = password_hash($new, PASSWORD_DEFAULT);
            if (!is_string($hash)) {
                throw new RuntimeException(t('settings_save_failed'));
            }
            pvdash_runtime_settings_update(['admin_password' => $hash]);
            pvdash_set_session_role('admin');
            settings_redirect(t('settings_admin_password_saved'));
        }

        if ($action === 'change_guest_password') {
            $mode = (string) ($_POST['guest_password_mode'] ?? 'keep');
            if ($mode === 'public') {
                pvdash_runtime_settings_update(['guest_password' => '']);
                settings_redirect(t('settings_guest_public_saved'));
            }
            if ($mode !== 'change') {
                settings_redirect(t('settings_guest_unchanged'));
            }

            $new = (string) ($_POST['new_guest_password'] ?? '');
            $confirm = (string) ($_POST['confirm_guest_password'] ?? '');
            if (!settings_password_valid($new, 6)) {
                throw new RuntimeException(t('settings_guest_password_length'));
            }
            if (!hash_equals($new, $confirm)) {
                throw new RuntimeException(t('settings_password_mismatch'));
            }

            $hash = password_hash($new, PASSWORD_DEFAULT);
            if (!is_string($hash)) {
                throw new RuntimeException(t('settings_save_failed'));
            }
            pvdash_runtime_settings_update(['guest_password' => $hash]);
            settings_redirect(t('settings_guest_password_saved'));
        }

        if ($action === 'save_api_device') {
            $currentDevice = trim((string) ($_POST['current_device'] ?? ''));
            $newDevice = trim((string) ($_POST['device_id'] ?? ''));
            $newKey = trim((string) ($_POST['api_key'] ?? ''));
            $migrateData = isset($_POST['migrate_data']);

            if (!pvdash_valid_device_id($newDevice)) {
                throw new InvalidArgumentException(t('settings_invalid_device'));
            }

            $keys = (array) pvdash_config('api_keys', []);
            $isNew = $currentDevice === '';
            if ($isNew) {
                if (isset($keys[$newDevice])) {
                    throw new RuntimeException(t('settings_device_already_configured'));
                }
                if ($newKey === '') {
                    throw new RuntimeException(t('settings_api_key_required'));
                }
                $keys[$newDevice] = $newKey;
            } else {
                if (!isset($keys[$currentDevice])) {
                    throw new RuntimeException(t('settings_device_not_configured'));
                }
                if ($newDevice !== $currentDevice && isset($keys[$newDevice])) {
                    throw new RuntimeException(t('settings_device_already_configured'));
                }

                $effectiveKey = $newKey !== '' ? $newKey : (string) $keys[$currentDevice];
                $updated = [];
                foreach ($keys as $deviceId => $key) {
                    $updated[$deviceId === $currentDevice ? $newDevice : $deviceId] = $deviceId === $currentDevice ? $effectiveKey : $key;
                }
                $keys = $updated;

                if ($migrateData && $newDevice !== $currentDevice && pvdash_device_data_exists($pdo, $currentDevice)) {
                    pvdash_rename_device_data($pdo, $currentDevice, $newDevice, false);
                }
            }

            $patch = ['api_keys' => $keys];
            if (!$isNew && $newDevice !== $currentDevice && pvdash_default_device() === $currentDevice) {
                $patch['default_device'] = $newDevice;
            }
            pvdash_runtime_settings_update($patch);
            settings_redirect(t($isNew ? 'settings_api_added' : 'settings_api_saved'));
        }

        if ($action === 'delete_api_device') {
            $currentDevice = trim((string) ($_POST['current_device'] ?? ''));
            $keys = (array) pvdash_config('api_keys', []);
            if (!isset($keys[$currentDevice])) {
                throw new RuntimeException(t('settings_device_not_configured'));
            }
            if ((bool) pvdash_config('require_ingest_auth', true) && count($keys) <= 1) {
                throw new RuntimeException(t('settings_last_api_key'));
            }
            unset($keys[$currentDevice]);

            $patch = ['api_keys' => $keys];
            if (pvdash_default_device() === $currentDevice && $keys !== []) {
                $patch['default_device'] = (string) array_key_first($keys);
            }
            pvdash_runtime_settings_update($patch);
            settings_redirect(t('settings_api_deleted'));
        }

        throw new InvalidArgumentException('Unknown settings action.');
    } catch (Throwable $e) {
        settings_redirect('', $e->getMessage());
    }
}

$devices = pvdash_devices($pdo);
$defaultDevice = pvdash_default_device();
if (!in_array($defaultDevice, $devices, true)) {
    $devices[] = $defaultDevice;
    sort($devices, SORT_NATURAL | SORT_FLAG_CASE);
}
$apiKeys = (array) pvdash_config('api_keys', []);
$guestProtected = (string) pvdash_config('guest_password', '') !== '';
$csrf = pvdash_csrf_token();
$metricColors = pvdash_metric_colors();
$metricLabels = [
    'pv_kwh' => 't20',
    'feed_in_kwh' => 't21',
    'batt_in_kwh' => 't22',
    'batt_out_kwh' => 't23',
    'consumption_kwh' => 't24',
    'grid_import_kwh' => 't25',
];
?>
<!doctype html>
<html lang="<?= htmlspecialchars(APP_LANG, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= th('settings_title') ?> – <?= htmlspecialchars(pvdash_site_title(), ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="../assets/style.css">
</head>
<body <?= pvdash_body_attributes() ?>>
<div class="wrap wide-wrap">
  <header class="topbar">
    <div>
      <?php pvdash_render_brand_heading(t('settings_title'), '../'); ?>
      <p class="muted no-margin"><?= th('settings_intro') ?></p>
    </div>
    <?php pvdash_render_navigation('settings', '../'); ?>
  </header>

  <?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <section class="card">
    <div class="card-head"><h2><?= th('settings_general_title') ?></h2></div>
    <p class="muted"><?= th('settings_storage_help') ?></p>
    <form method="post" class="settings-grid">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="save_general">
      <label><?= th('settings_default_device') ?>
        <select name="default_device" required><?php foreach ($devices as $device): ?><option value="<?= htmlspecialchars($device, ENT_QUOTES, 'UTF-8') ?>" <?= $device === $defaultDevice ? 'selected' : '' ?>><?= htmlspecialchars($device, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
        <span class="field-help"><?= th('settings_default_device_help') ?></span>
      </label>
      <label><?= th('settings_feed_in') ?>
        <input type="text" inputmode="decimal" name="feed_in_ct" value="<?= htmlspecialchars(number_format((float) pvdash_config('feed_in_ct', 0), 3, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required>
        <span class="field-help"><?= th('settings_feed_in_help') ?></span>
      </label>
      <label><?= th('settings_backup_retention') ?>
        <input type="number" name="backup_retention" min="1" max="25" value="<?= (int) pvdash_config('backup_retention', 5) ?>" required>
        <span class="field-help"><?= th('settings_backup_retention_help') ?></span>
      </label>
      <label class="checkbox-label settings-checkbox"><input type="checkbox" name="guest_can_view_stats" value="1" <?= (bool) pvdash_config('guest_can_view_stats', true) ? 'checked' : '' ?>><span><?= th('settings_guest_stats') ?></span></label>
      <label class="checkbox-label settings-checkbox"><input type="checkbox" name="guest_can_view_compensation" value="1" <?= (bool) pvdash_config('guest_can_view_compensation', false) ? 'checked' : '' ?>><span><?= th('settings_guest_compensation') ?></span></label>
      <div class="settings-submit"><button class="button button-primary" type="submit"><?= th('settings_save') ?></button></div>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= th('settings_appearance_title') ?></h2></div>
    <p class="muted"><?= th('settings_appearance_intro') ?></p>
    <form method="post" enctype="multipart/form-data" class="appearance-form" id="appearance-form">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <div class="appearance-main">
        <div class="settings-panel appearance-fields">
          <label><?= th('settings_site_title') ?><input type="text" name="site_title" maxlength="80" value="<?= htmlspecialchars(pvdash_site_title(), ENT_QUOTES, 'UTF-8') ?>" required></label>
          <label><?= th('settings_theme') ?><select name="theme" id="theme-select"><option value="standard" <?= pvdash_theme() === 'standard' ? 'selected' : '' ?>><?= th('settings_theme_standard') ?></option><option value="dark" <?= pvdash_theme() === 'dark' ? 'selected' : '' ?>><?= th('settings_theme_dark') ?></option><option value="light" <?= pvdash_theme() === 'light' ? 'selected' : '' ?>><?= th('settings_theme_light') ?></option></select></label>
          <label><?= th('settings_accent_color') ?><div class="color-input-row"><input type="color" name="accent_color" value="<?= htmlspecialchars(pvdash_accent_color(), ENT_QUOTES, 'UTF-8') ?>"><span class="color-value"><?= htmlspecialchars(pvdash_accent_color(), ENT_QUOTES, 'UTF-8') ?></span></div></label>
          <label><?= th('settings_table_density') ?><select name="table_density"><option value="comfortable" <?= pvdash_table_density() === 'comfortable' ? 'selected' : '' ?>><?= th('settings_density_comfortable') ?></option><option value="compact" <?= pvdash_table_density() === 'compact' ? 'selected' : '' ?>><?= th('settings_density_compact') ?></option></select></label>
          <label class="checkbox-label settings-checkbox"><input type="checkbox" name="highlight_extremes" value="1" <?= (bool) pvdash_config('highlight_extremes', true) ? 'checked' : '' ?>><span><?= th('settings_highlight_extremes') ?></span></label>
          <label><?= th('settings_custom_logo') ?><input type="file" name="custom_logo" accept="image/png,image/jpeg,image/webp"><span class="field-help"><?= th('settings_custom_logo_help') ?></span></label>
          <?php if (pvdash_custom_logo_path() !== null): ?><label class="checkbox-label settings-checkbox"><input type="checkbox" name="remove_logo" value="1"><span><?= th('settings_remove_logo') ?></span></label><?php endif; ?>
        </div>
        <aside class="appearance-preview" id="appearance-preview"><div class="preview-header"><img src="<?= htmlspecialchars(pvdash_logo_url('../'), ENT_QUOTES, 'UTF-8') ?>" alt=""><strong id="preview-title"><?= htmlspecialchars(pvdash_site_title(), ENT_QUOTES, 'UTF-8') ?></strong></div><div class="preview-card"><span><?= th('settings_preview') ?></span><button type="button" class="button button-primary"><?= th('settings_preview_button') ?></button></div></aside>
      </div>

      <div class="subsection-head"><h3><?= th('settings_metric_colors_title') ?></h3></div>
      <p class="muted"><?= th('settings_metric_colors_help') ?></p>
      <div class="metric-color-grid">
        <?php foreach ($metricLabels as $metric => $label): ?>
          <article class="metric-color-card"><strong><?= th($label) ?></strong><label><?= th('settings_min_color') ?><div class="color-input-row"><input type="color" name="metric_<?= $metric ?>_min" value="<?= htmlspecialchars($metricColors[$metric]['min'], ENT_QUOTES, 'UTF-8') ?>"><span class="color-value"><?= htmlspecialchars($metricColors[$metric]['min'], ENT_QUOTES, 'UTF-8') ?></span></div></label><label><?= th('settings_max_color') ?><div class="color-input-row"><input type="color" name="metric_<?= $metric ?>_max" value="<?= htmlspecialchars($metricColors[$metric]['max'], ENT_QUOTES, 'UTF-8') ?>"><span class="color-value"><?= htmlspecialchars($metricColors[$metric]['max'], ENT_QUOTES, 'UTF-8') ?></span></div></label><div class="metric-preview"><span style="--preview-color:<?= htmlspecialchars($metricColors[$metric]['min'], ENT_QUOTES, 'UTF-8') ?>"><?= th('settings_min_short') ?></span><span style="--preview-color:<?= htmlspecialchars($metricColors[$metric]['max'], ENT_QUOTES, 'UTF-8') ?>"><?= th('settings_max_short') ?></span></div></article>
        <?php endforeach; ?>
      </div>
      <div class="settings-submit appearance-actions"><button class="button" type="submit" name="action" value="reset_appearance" onclick="return confirm('<?= htmlspecialchars(t('settings_appearance_reset_confirm'), ENT_QUOTES, 'UTF-8') ?>')"><?= th('settings_appearance_reset') ?></button><button class="button button-primary" type="submit" name="action" value="save_appearance"><?= th('settings_save') ?></button></div>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= th('settings_api_title') ?></h2><span class="badge"><?= count($apiKeys) ?></span></div>
    <p class="muted"><?= th('settings_api_intro') ?></p>
    <div class="api-settings-list">
      <?php foreach ($apiKeys as $deviceId => $apiKey): ?>
        <form method="post" class="api-settings-row">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="current_device" value="<?= htmlspecialchars((string) $deviceId, ENT_QUOTES, 'UTF-8') ?>">
          <label><?= th('settings_device_id') ?><input type="text" name="device_id" maxlength="64" pattern="[A-Za-z0-9_.:-]+" value="<?= htmlspecialchars((string) $deviceId, ENT_QUOTES, 'UTF-8') ?>" required></label>
          <label><?= th('settings_new_api_key') ?><div class="input-action-row"><input type="password" name="api_key" maxlength="512" autocomplete="new-password" placeholder="<?= th('settings_api_keep_placeholder') ?>"><button class="button" type="button" data-generate-key><?= th('settings_generate') ?></button></div><span class="field-help"><?= th('settings_api_fingerprint', ['fingerprint' => pvdash_api_key_fingerprint((string) $apiKey)]) ?></span></label>
          <label class="checkbox-label settings-checkbox"><input type="checkbox" name="migrate_data" value="1"><span><?= th('settings_migrate_device_data') ?></span></label>
          <div class="api-actions"><button class="button button-primary" type="submit" name="action" value="save_api_device"><?= th('settings_save') ?></button><button class="button button-danger" type="submit" name="action" value="delete_api_device" onclick="return confirm('<?= htmlspecialchars(t('settings_api_delete_confirm'), ENT_QUOTES, 'UTF-8') ?>')"><?= th('admin_delete') ?></button></div>
        </form>
      <?php endforeach; ?>
      <form method="post" class="api-settings-row api-settings-add"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="current_device" value=""><input type="hidden" name="action" value="save_api_device"><label><?= th('settings_device_id') ?><input type="text" name="device_id" maxlength="64" pattern="[A-Za-z0-9_.:-]+" placeholder="home2" required></label><label><?= th('settings_api_key') ?><div class="input-action-row"><input type="password" name="api_key" maxlength="512" autocomplete="new-password" required><button class="button" type="button" data-generate-key><?= th('settings_generate') ?></button></div></label><div class="api-actions"><button class="button button-primary" type="submit"><?= th('settings_api_add') ?></button></div></form>
    </div>
    <div class="alert alert-info settings-warning"><?= th('settings_api_ha_warning') ?></div>
  </section>

  <section class="card settings-two-column">
    <article class="settings-panel"><h2><?= th('settings_admin_password_title') ?></h2><p class="muted"><?= th('settings_admin_password_help') ?></p><form method="post" class="stack-form"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="change_admin_password"><label><?= th('settings_current_admin_password') ?><input type="password" name="current_admin_password" autocomplete="current-password" required></label><label><?= th('settings_new_admin_password') ?><input type="password" name="new_admin_password" minlength="8" maxlength="512" autocomplete="new-password" required></label><label><?= th('settings_confirm_password') ?><input type="password" name="confirm_admin_password" minlength="8" maxlength="512" autocomplete="new-password" required></label><button class="button button-primary" type="submit"><?= th('settings_change_password') ?></button></form></article>
    <article class="settings-panel"><h2><?= th('settings_guest_password_title') ?></h2><p class="muted"><?= $guestProtected ? th('settings_guest_currently_protected') : th('settings_guest_currently_public') ?></p><form method="post" class="stack-form" id="guest-password-form"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="change_guest_password"><label><?= th('settings_guest_mode') ?><select name="guest_password_mode" id="guest-password-mode"><option value="keep"><?= th('settings_guest_keep') ?></option><option value="change"><?= th('settings_guest_change') ?></option><option value="public"><?= th('settings_guest_public') ?></option></select></label><div id="guest-new-fields" hidden><label><?= th('settings_new_guest_password') ?><input type="password" name="new_guest_password" minlength="6" maxlength="512" autocomplete="new-password"></label><label><?= th('settings_confirm_password') ?><input type="password" name="confirm_guest_password" minlength="6" maxlength="512" autocomplete="new-password"></label></div><button class="button button-primary" type="submit"><?= th('settings_save') ?></button></form></article>
  </section>
</div>
<script>
for (const button of document.querySelectorAll('[data-generate-key]')) {
  button.addEventListener('click', () => {
    const bytes = new Uint8Array(32); crypto.getRandomValues(bytes);
    const key = btoa(String.fromCharCode(...bytes)).replaceAll('+', '-').replaceAll('/', '_').replaceAll('=', '');
    const input = button.closest('.input-action-row').querySelector('input'); input.type = 'text'; input.value = key; input.focus(); input.select();
  });
}
const guestMode = document.getElementById('guest-password-mode');
const guestFields = document.getElementById('guest-new-fields');
function updateGuestFields() { const visible = guestMode.value === 'change'; guestFields.hidden = !visible; for (const input of guestFields.querySelectorAll('input')) input.required = visible; }
guestMode.addEventListener('change', updateGuestFields); updateGuestFields();
for (const picker of document.querySelectorAll('input[type="color"]')) {
  const value = picker.closest('.color-input-row')?.querySelector('.color-value');
  picker.addEventListener('input', () => { if (value) value.textContent = picker.value; if (picker.name === 'accent_color') document.getElementById('appearance-preview').style.setProperty('--accent', picker.value); });
}
document.querySelector('input[name="site_title"]').addEventListener('input', (event) => { document.getElementById('preview-title').textContent = event.target.value || 'PenguinPVDash'; });
</script>
</body>
</html>
