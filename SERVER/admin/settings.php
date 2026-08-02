<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/web_auth.php';
require_once __DIR__ . '/../inc/i18n.php';
require_once __DIR__ . '/../inc/db.php';

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

function settings_rename_device_data(PDO $pdo, string $from, string $to): void
{
    if ($from === $to) {
        return;
    }

    foreach (['samples', 'daily_totals', 'integ_state'] as $table) {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $table . ' WHERE device=?');
        $stmt->execute([$to]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException(t('settings_device_target_exists', ['device' => $to]));
        }
    }

    $pdo->beginTransaction();
    try {
        foreach (['samples', 'daily_totals', 'integ_state'] as $table) {
            $stmt = $pdo->prepare('UPDATE ' . $table . ' SET device=? WHERE device=?');
            $stmt->execute([$to, $from]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
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

            pvdash_runtime_settings_update([
                'default_device' => $defaultDevice,
                'feed_in_ct' => $feedIn,
                'guest_can_view_stats' => isset($_POST['guest_can_view_stats']),
                'guest_can_view_compensation' => isset($_POST['guest_can_view_compensation']),
            ]);
            settings_redirect(t('settings_general_saved'));
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
                    if ($deviceId === $currentDevice) {
                        $updated[$newDevice] = $effectiveKey;
                    } else {
                        $updated[$deviceId] = $key;
                    }
                }
                $keys = $updated;

                if ($migrateData && $newDevice !== $currentDevice) {
                    settings_rename_device_data($pdo, $currentDevice, $newDevice);
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
?>
<!doctype html>
<html lang="<?= htmlspecialchars(APP_LANG, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= th('settings_title') ?> – PenguinPVDash</title>
  <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="wrap wide-wrap">
  <header class="topbar">
    <div>
      <h1><?= th('settings_title') ?></h1>
      <p class="muted no-margin"><?= th('settings_intro') ?></p>
    </div>
    <nav class="top-actions">
      <a class="button" href="../"><?= th('nav_dashboard') ?></a>
      <a class="button" href="index.php"><?= th('nav_data_admin') ?></a>
      <?php if (pvdash_can_view_stats()): ?><a class="button" href="../stats.php"><?= th('nav_stats') ?></a><?php endif; ?>
      <a class="button" href="../logout.php"><?= th('nav_logout') ?></a>
    </nav>
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
        <select name="default_device" required>
          <?php foreach ($devices as $device): ?><option value="<?= htmlspecialchars($device, ENT_QUOTES, 'UTF-8') ?>" <?= $device === $defaultDevice ? 'selected' : '' ?>><?= htmlspecialchars($device, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?>
        </select>
        <span class="field-help"><?= th('settings_default_device_help') ?></span>
      </label>
      <label><?= th('settings_feed_in') ?>
        <input type="text" inputmode="decimal" name="feed_in_ct" value="<?= htmlspecialchars(number_format((float) pvdash_config('feed_in_ct', 0), 3, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required>
        <span class="field-help"><?= th('settings_feed_in_help') ?></span>
      </label>
      <label class="checkbox-label settings-checkbox">
        <input type="checkbox" name="guest_can_view_stats" value="1" <?= (bool) pvdash_config('guest_can_view_stats', true) ? 'checked' : '' ?>>
        <span><?= th('settings_guest_stats') ?></span>
      </label>
      <label class="checkbox-label settings-checkbox">
        <input type="checkbox" name="guest_can_view_compensation" value="1" <?= (bool) pvdash_config('guest_can_view_compensation', false) ? 'checked' : '' ?>>
        <span><?= th('settings_guest_compensation') ?></span>
      </label>
      <div class="settings-submit"><button class="button button-primary" type="submit"><?= th('settings_save') ?></button></div>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= th('settings_api_title') ?></h2><span class="badge"><?= count($apiKeys) ?></span></div>
    <p class="muted"><?= th('settings_api_intro') ?></p>
    <div class="api-settings-list">
      <?php foreach ($apiKeys as $deviceId => $apiKey): ?>
        <form method="post" class="api-settings-row">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="current_device" value="<?= htmlspecialchars((string) $deviceId, ENT_QUOTES, 'UTF-8') ?>">
          <label><?= th('settings_device_id') ?>
            <input type="text" name="device_id" maxlength="64" pattern="[A-Za-z0-9_.:-]+" value="<?= htmlspecialchars((string) $deviceId, ENT_QUOTES, 'UTF-8') ?>" required>
          </label>
          <label><?= th('settings_new_api_key') ?>
            <div class="input-action-row">
              <input type="password" name="api_key" maxlength="512" autocomplete="new-password" placeholder="<?= th('settings_api_keep_placeholder') ?>">
              <button class="button" type="button" data-generate-key><?= th('settings_generate') ?></button>
            </div>
            <span class="field-help"><?= th('settings_api_fingerprint', ['fingerprint' => pvdash_api_key_fingerprint((string) $apiKey)]) ?></span>
          </label>
          <label class="checkbox-label settings-checkbox">
            <input type="checkbox" name="migrate_data" value="1">
            <span><?= th('settings_migrate_device_data') ?></span>
          </label>
          <div class="api-actions">
            <button class="button button-primary" type="submit" name="action" value="save_api_device"><?= th('settings_save') ?></button>
            <button class="button button-danger" type="submit" name="action" value="delete_api_device" onclick="return confirm('<?= htmlspecialchars(t('settings_api_delete_confirm'), ENT_QUOTES, 'UTF-8') ?>')"><?= th('admin_delete') ?></button>
          </div>
        </form>
      <?php endforeach; ?>

      <form method="post" class="api-settings-row api-settings-add">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="current_device" value="">
        <input type="hidden" name="action" value="save_api_device">
        <label><?= th('settings_device_id') ?>
          <input type="text" name="device_id" maxlength="64" pattern="[A-Za-z0-9_.:-]+" placeholder="home2" required>
        </label>
        <label><?= th('settings_api_key') ?>
          <div class="input-action-row">
            <input type="password" name="api_key" maxlength="512" autocomplete="new-password" required>
            <button class="button" type="button" data-generate-key><?= th('settings_generate') ?></button>
          </div>
        </label>
        <div class="api-actions"><button class="button button-primary" type="submit"><?= th('settings_api_add') ?></button></div>
      </form>
    </div>
    <div class="alert alert-info settings-warning"><?= th('settings_api_ha_warning') ?></div>
  </section>

  <section class="card settings-two-column">
    <article class="settings-panel">
      <h2><?= th('settings_admin_password_title') ?></h2>
      <p class="muted"><?= th('settings_admin_password_help') ?></p>
      <form method="post" class="stack-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="change_admin_password">
        <label><?= th('settings_current_admin_password') ?><input type="password" name="current_admin_password" autocomplete="current-password" required></label>
        <label><?= th('settings_new_admin_password') ?><input type="password" name="new_admin_password" minlength="8" maxlength="512" autocomplete="new-password" required></label>
        <label><?= th('settings_confirm_password') ?><input type="password" name="confirm_admin_password" minlength="8" maxlength="512" autocomplete="new-password" required></label>
        <button class="button button-primary" type="submit"><?= th('settings_change_password') ?></button>
      </form>
    </article>

    <article class="settings-panel">
      <h2><?= th('settings_guest_password_title') ?></h2>
      <p class="muted"><?= $guestProtected ? th('settings_guest_currently_protected') : th('settings_guest_currently_public') ?></p>
      <form method="post" class="stack-form" id="guest-password-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="action" value="change_guest_password">
        <label><?= th('settings_guest_mode') ?>
          <select name="guest_password_mode" id="guest-password-mode">
            <option value="keep"><?= th('settings_guest_keep') ?></option>
            <option value="change"><?= th('settings_guest_change') ?></option>
            <option value="public"><?= th('settings_guest_public') ?></option>
          </select>
        </label>
        <div id="guest-new-fields" hidden>
          <label><?= th('settings_new_guest_password') ?><input type="password" name="new_guest_password" minlength="6" maxlength="512" autocomplete="new-password"></label>
          <label><?= th('settings_confirm_password') ?><input type="password" name="confirm_guest_password" minlength="6" maxlength="512" autocomplete="new-password"></label>
        </div>
        <button class="button button-primary" type="submit"><?= th('settings_save') ?></button>
      </form>
    </article>
  </section>
</div>
<script>
for (const button of document.querySelectorAll('[data-generate-key]')) {
  button.addEventListener('click', () => {
    const bytes = new Uint8Array(32);
    crypto.getRandomValues(bytes);
    const key = btoa(String.fromCharCode(...bytes)).replaceAll('+', '-').replaceAll('/', '_').replaceAll('=', '');
    const input = button.closest('.input-action-row').querySelector('input');
    input.type = 'text';
    input.value = key;
    input.focus();
    input.select();
  });
}
const guestMode = document.getElementById('guest-password-mode');
const guestFields = document.getElementById('guest-new-fields');
function updateGuestFields() {
  const visible = guestMode.value === 'change';
  guestFields.hidden = !visible;
  for (const input of guestFields.querySelectorAll('input')) input.required = visible;
}
guestMode.addEventListener('change', updateGuestFields);
updateGuestFields();
</script>
</body>
</html>
