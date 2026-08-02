<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/web_auth.php';
require_once __DIR__ . '/../inc/i18n.php';
require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/ui.php';

pvdash_require_admin();
$pdo = db();
$devices = pvdash_devices($pdo);
$device = (string) ($_GET['device'] ?? $_POST['device'] ?? pvdash_default_device());
if (!in_array($device, $devices, true)) {
    $devices[] = $device;
    sort($devices, SORT_NATURAL | SORT_FLAG_CASE);
}

$today = date('Y-m-d');
$from = (string) ($_GET['from'] ?? $_POST['from'] ?? date('Y-m-d', strtotime('-60 days')));
$to = (string) ($_GET['to'] ?? $_POST['to'] ?? $today);
$message = (string) ($_SESSION['pvdash_admin_message'] ?? $_GET['message'] ?? '');
$error = (string) ($_SESSION['pvdash_admin_error'] ?? '');
unset($_SESSION['pvdash_admin_message'], $_SESSION['pvdash_admin_error']);

function valid_day(string $day): bool
{
    $date = DateTime::createFromFormat('Y-m-d', $day);
    return $date !== false && $date->format('Y-m-d') === $day;
}

function numeric_input(string $name): float
{
    $raw = str_replace(',', '.', trim((string) ($_POST[$name] ?? '0')));
    if ($raw === '' || !is_numeric($raw)) {
        throw new InvalidArgumentException($name . ' must be numeric.');
    }
    $value = (float) $raw;
    if ($value < 0 || $value > 1000000000) {
        throw new InvalidArgumentException($name . ' is outside the allowed range.');
    }
    return $value;
}

function admin_redirect(string $message, string $device, string $from, string $to): never
{
    $query = http_build_query(['device' => $device, 'from' => $from, 'to' => $to, 'message' => $message]);
    header('Location: index.php?' . $query);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!pvdash_verify_csrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException(t('auth_session_expired'));
        }
        $action = (string) ($_POST['action'] ?? 'save');

        if ($action === 'set_default_device') {
            $target = trim((string) ($_POST['target_device'] ?? ''));
            if (!pvdash_valid_device_id($target)) {
                throw new InvalidArgumentException(t('settings_invalid_device'));
            }
            pvdash_runtime_settings_update(['default_device' => $target]);
            admin_redirect(t('database_device_default_saved', ['device' => $target]), $target, $from, $to);
        }

        if ($action === 'rename_device_data') {
            $source = trim((string) ($_POST['source_device'] ?? ''));
            $target = trim((string) ($_POST['target_device'] ?? ''));
            if (!pvdash_valid_device_id($source) || !pvdash_valid_device_id($target)) {
                throw new InvalidArgumentException(t('settings_invalid_device'));
            }
            $replaceTarget = isset($_POST['replace_target']);
            pvdash_rename_device_data($pdo, $source, $target, $replaceTarget);
            $patch = [];
            if (isset($_POST['set_default']) || pvdash_default_device() === $source) {
                $patch['default_device'] = $target;
            }
            if ($patch !== []) {
                pvdash_runtime_settings_update($patch);
            }
            admin_redirect(t('database_device_renamed', ['from' => $source, 'to' => $target]), $target, $from, $to);
        }

        if ($action === 'delete_device_data') {
            $target = trim((string) ($_POST['target_device'] ?? ''));
            if (!pvdash_valid_device_id($target)) {
                throw new InvalidArgumentException(t('settings_invalid_device'));
            }
            pvdash_delete_device_data($pdo, $target);
            $remaining = array_values(array_filter(pvdash_devices($pdo), static fn (string $item): bool => $item !== $target));
            if (pvdash_default_device() === $target && $remaining !== []) {
                pvdash_runtime_settings_update(['default_device' => (string) $remaining[0]]);
                $device = (string) $remaining[0];
            }
            admin_redirect(t('database_device_deleted', ['device' => $target]), $device, $from, $to);
        }

        $day = (string) ($_POST['day'] ?? '');
        if (!pvdash_valid_device_id($device)) {
            throw new InvalidArgumentException('Invalid device ID.');
        }
        if (!valid_day($day)) {
            throw new InvalidArgumentException('Invalid date.');
        }

        if ($action === 'save') {
            $values = [
                numeric_input('pv_kwh'),
                numeric_input('feed_in_kwh'),
                numeric_input('batt_in_kwh'),
                numeric_input('batt_out_kwh'),
                numeric_input('consumption_kwh'),
                numeric_input('grid_import_kwh'),
            ];
            $note = trim((string) ($_POST['manual_note'] ?? ''));
            $now = time();
            $stmt = $pdo->prepare('INSERT INTO daily_totals (
                    device, day, pv_kwh, feed_in_kwh, batt_in_kwh, batt_out_kwh,
                    consumption_kwh, grid_import_kwh, manual_lock, manual_updated_ts,
                    manual_note, created_ts, updated_ts
                ) VALUES (?,?,?,?,?,?,?,?,1,?,?,?,?)
                ON CONFLICT(device,day) DO UPDATE SET
                    pv_kwh=excluded.pv_kwh,
                    feed_in_kwh=excluded.feed_in_kwh,
                    batt_in_kwh=excluded.batt_in_kwh,
                    batt_out_kwh=excluded.batt_out_kwh,
                    consumption_kwh=excluded.consumption_kwh,
                    grid_import_kwh=excluded.grid_import_kwh,
                    manual_lock=1,
                    manual_updated_ts=excluded.manual_updated_ts,
                    manual_note=excluded.manual_note,
                    updated_ts=excluded.updated_ts');
            $stmt->execute([$device, $day, ...$values, $now, $note, $now, $now]);
            $message = t('admin_saved');
        } elseif ($action === 'unlock') {
            $stmt = $pdo->prepare('UPDATE daily_totals SET manual_lock=0, manual_updated_ts=?, updated_ts=? WHERE device=? AND day=?');
            $stmt->execute([time(), time(), $device, $day]);
            $message = t('admin_unlocked');
        } elseif ($action === 'delete') {
            $stmt = $pdo->prepare('DELETE FROM daily_totals WHERE device=? AND day=?');
            $stmt->execute([$device, $day]);
            $message = t('admin_deleted');
        } else {
            throw new InvalidArgumentException('Unknown action.');
        }

        admin_redirect($message, $device, $from, $to);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (!valid_day($from)) {
    $from = date('Y-m-d', strtotime('-60 days'));
}
if (!valid_day($to)) {
    $to = $today;
}

$stmt = $pdo->prepare('SELECT * FROM daily_totals WHERE device=? AND day>=? AND day<=? ORDER BY day DESC');
$stmt->execute([$device, $from, $to]);
$rows = $stmt->fetchAll();
$deviceSummaries = pvdash_device_summaries($pdo);
$backups = pvdash_database_backup_files();
$csrf = pvdash_csrf_token();
$defaultDevice = pvdash_default_device();
?>
<!doctype html>
<html lang="<?= htmlspecialchars(APP_LANG, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= th('admin_title') ?> – <?= htmlspecialchars(pvdash_site_title(), ENT_QUOTES, 'UTF-8') ?></title>
  <link rel="stylesheet" href="../assets/style.css">
</head>
<body <?= pvdash_body_attributes() ?>>
<div class="wrap wide-wrap">
  <header class="topbar">
    <div>
      <?php pvdash_render_brand_heading(t('admin_title'), '../'); ?>
      <p class="muted no-margin"><?= th('admin_intro') ?></p>
    </div>
    <?php pvdash_render_navigation('data', '../'); ?>
  </header>

  <?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <section class="card">
    <div class="card-head"><div><h2><?= th('database_title') ?></h2><p class="muted no-margin"><?= th('database_intro') ?></p></div></div>
    <div class="database-tools">
      <article class="database-tool">
        <h3><?= th('database_export_title') ?></h3>
        <p class="muted"><?= th('database_export_help') ?></p>
        <form method="post" action="database_export.php">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <button class="button button-primary" type="submit"><?= th('database_export_button') ?></button>
        </form>
      </article>

      <article class="database-tool database-tool-warning">
        <h3><?= th('database_import_title') ?></h3>
        <p class="muted"><?= th('database_import_help') ?></p>
        <p class="tiny muted"><?= th('database_import_limit', ['max' => (string) round(pvdash_database_import_max_bytes() / 1024 / 1024)]) ?></p>
        <form method="post" action="database_import.php" enctype="multipart/form-data" class="database-import-form" onsubmit="return confirm('<?= htmlspecialchars(t('database_import_confirm_dialog'), ENT_QUOTES, 'UTF-8') ?>')">
          <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="MAX_FILE_SIZE" value="<?= pvdash_database_import_max_bytes() ?>">
          <label><?= th('database_import_file') ?>
            <input type="file" name="database_file" accept=".sqlite,.sqlite3,.db,application/vnd.sqlite3,application/x-sqlite3" required>
          </label>
          <label class="checkbox-label">
            <input type="checkbox" name="adopt_single_device" value="1">
            <span><?= th('database_adopt_single_device', ['device' => $defaultDevice]) ?></span>
          </label>
          <label class="checkbox-label">
            <input type="checkbox" name="confirm_replace" value="1" required>
            <span><?= th('database_import_confirmation') ?></span>
          </label>
          <button class="button button-danger" type="submit"><?= th('database_import_button') ?></button>
        </form>
      </article>
    </div>

    <div class="subsection-head"><h3><?= th('database_device_manager_title') ?></h3><span class="badge"><?= count($deviceSummaries) ?></span></div>
    <p class="muted"><?= th('database_device_manager_help') ?></p>
    <div class="table-wrap">
      <table class="fancy device-table">
        <thead><tr><th><?= th('settings_device_id') ?></th><th><?= th('database_samples') ?></th><th><?= th('database_days') ?></th><th><?= th('database_period') ?></th><th><?= th('database_api_status') ?></th><th><?= th('admin_actions') ?></th></tr></thead>
        <tbody>
        <?php foreach ($deviceSummaries as $summary): $summaryDevice = (string) $summary['device']; ?>
          <tr>
            <td><strong><?= htmlspecialchars($summaryDevice, ENT_QUOTES, 'UTF-8') ?></strong><?php if ($summaryDevice === $defaultDevice): ?><span class="status-pill status-auto inline-pill"><?= th('database_default_badge') ?></span><?php endif; ?></td>
            <td><?= number_format((int) $summary['samples'], 0, ',', '.') ?></td>
            <td><?= number_format((int) $summary['daily'], 0, ',', '.') ?></td>
            <td class="tiny"><?php if ($summary['first_day'] !== null): ?><?= htmlspecialchars((string) $summary['first_day'], ENT_QUOTES, 'UTF-8') ?> – <?= htmlspecialchars((string) $summary['last_day'], ENT_QUOTES, 'UTF-8') ?><?php elseif ($summary['last_ts'] !== null): ?><?= htmlspecialchars(date('Y-m-d H:i', (int) $summary['last_ts']), ENT_QUOTES, 'UTF-8') ?><?php else: ?>–<?php endif; ?></td>
            <td><?= $summary['has_api_key'] ? th('database_api_configured') : th('database_api_missing') ?></td>
            <td><div class="inline-actions">
              <?php if ($summaryDevice !== $defaultDevice): ?>
              <form method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="set_default_device"><input type="hidden" name="target_device" value="<?= htmlspecialchars($summaryDevice, ENT_QUOTES, 'UTF-8') ?>"><button class="button" type="submit"><?= th('database_set_default') ?></button></form>
              <?php endif; ?>
              <?php if ((int) $summary['samples'] > 0 || (int) $summary['daily'] > 0): ?>
              <form method="post" onsubmit="return confirm('<?= htmlspecialchars(t('database_device_delete_confirm', ['device' => $summaryDevice]), ENT_QUOTES, 'UTF-8') ?>')"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="delete_device_data"><input type="hidden" name="target_device" value="<?= htmlspecialchars($summaryDevice, ENT_QUOTES, 'UTF-8') ?>"><button class="button button-danger" type="submit"><?= th('database_delete_device_data') ?></button></form>
              <?php endif; ?>
            </div></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <form method="post" class="device-migration-form" onsubmit="return confirm('<?= htmlspecialchars(t('database_device_rename_confirm'), ENT_QUOTES, 'UTF-8') ?>')">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="rename_device_data">
      <label><?= th('database_source_device') ?><select name="source_device" required><?php foreach ($deviceSummaries as $summary): if ((int) $summary['samples'] === 0 && (int) $summary['daily'] === 0) continue; ?><option value="<?= htmlspecialchars((string) $summary['device'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $summary['device'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
      <label><?= th('database_target_device') ?><input type="text" name="target_device" maxlength="64" pattern="[A-Za-z0-9_.:-]+" placeholder="home2" required></label>
      <label class="checkbox-label settings-checkbox"><input type="checkbox" name="replace_target" value="1"><span><?= th('database_replace_target') ?></span></label>
      <label class="checkbox-label settings-checkbox"><input type="checkbox" name="set_default" value="1" checked><span><?= th('database_set_target_default') ?></span></label>
      <button class="button button-primary" type="submit"><?= th('database_rename_device') ?></button>
    </form>

    <div class="subsection-head"><h3><?= th('database_backups_title') ?></h3><span class="badge"><?= count($backups) ?></span></div>
    <p class="muted"><?= th('database_backups_help', ['count' => (string) pvdash_database_backup_retention()]) ?></p>
    <?php if ($backups !== []): ?>
    <div class="table-wrap"><table class="fancy backup-table"><thead><tr><th><?= th('database_backup_file') ?></th><th><?= th('database_backup_date') ?></th><th><?= th('database_backup_size') ?></th><th><?= th('admin_actions') ?></th></tr></thead><tbody>
    <?php foreach ($backups as $backup): ?>
      <tr><td><code><?= htmlspecialchars((string) $backup['name'], ENT_QUOTES, 'UTF-8') ?></code></td><td><?= htmlspecialchars(date('Y-m-d H:i:s', (int) $backup['mtime']), ENT_QUOTES, 'UTF-8') ?></td><td><?= htmlspecialchars(number_format((int) $backup['size'] / 1024 / 1024, 2, ',', '.'), ENT_QUOTES, 'UTF-8') ?> MB</td><td><div class="inline-actions"><a class="button" href="database_backup.php?action=download&amp;file=<?= rawurlencode((string) $backup['name']) ?>"><?= th('database_backup_download') ?></a><form method="post" action="database_backup.php" onsubmit="return confirm('<?= htmlspecialchars(t('database_backup_restore_confirm'), ENT_QUOTES, 'UTF-8') ?>')"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="restore"><input type="hidden" name="file" value="<?= htmlspecialchars((string) $backup['name'], ENT_QUOTES, 'UTF-8') ?>"><button class="button" type="submit"><?= th('database_backup_restore') ?></button></form><form method="post" action="database_backup.php" onsubmit="return confirm('<?= htmlspecialchars(t('database_backup_delete_confirm'), ENT_QUOTES, 'UTF-8') ?>')"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="<?= htmlspecialchars((string) $backup['name'], ENT_QUOTES, 'UTF-8') ?>"><button class="button button-danger" type="submit"><?= th('admin_delete') ?></button></form></div></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php else: ?><p class="muted"><?= th('database_backups_empty') ?></p><?php endif; ?>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= th('admin_filter') ?></h2></div>
    <form method="get" class="filter-grid">
      <label><?= th('admin_device') ?><select name="device"><?php foreach ($devices as $dev): ?><option value="<?= htmlspecialchars($dev, ENT_QUOTES, 'UTF-8') ?>" <?= $dev === $device ? 'selected' : '' ?>><?= htmlspecialchars($dev, ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
      <label><?= th('admin_from') ?><input type="date" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES, 'UTF-8') ?>"></label>
      <label><?= th('admin_to') ?><input type="date" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES, 'UTF-8') ?>"></label>
      <button class="button button-primary" type="submit"><?= th('admin_apply') ?></button>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= th('admin_add_day') ?></h2></div>
    <p class="muted"><?= th('admin_lock_help') ?></p>
    <form method="post" class="daily-editor add-editor">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="device" value="<?= htmlspecialchars($device, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES, 'UTF-8') ?>">
      <input type="hidden" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES, 'UTF-8') ?>">
      <label><?= th('t19') ?><input type="date" name="day" required value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>"></label>
      <?php foreach (['pv_kwh' => 't20', 'feed_in_kwh' => 't21', 'batt_in_kwh' => 't22', 'batt_out_kwh' => 't23', 'consumption_kwh' => 't24', 'grid_import_kwh' => 't25'] as $field => $label): ?>
        <label><?= th($label) ?><input type="number" min="0" step="0.001" name="<?= $field ?>" value="0" required></label>
      <?php endforeach; ?>
      <label class="editor-note"><?= th('admin_note') ?><input type="text" name="manual_note" maxlength="200"></label>
      <button class="button button-primary" type="submit"><?= th('admin_save_lock') ?></button>
    </form>
  </section>

  <section class="card">
    <div class="card-head"><h2><?= th('admin_entries') ?></h2><span class="badge"><?= count($rows) ?></span></div>
    <div class="table-wrap admin-table-wrap">
      <table class="fancy admin-table">
        <thead><tr><th><?= th('t19') ?></th><th><?= th('t20') ?></th><th><?= th('t21') ?></th><th><?= th('t22') ?></th><th><?= th('t23') ?></th><th><?= th('t24') ?></th><th><?= th('t25') ?></th><th><?= th('admin_status') ?></th><th><?= th('admin_actions') ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $row): $formId = 'row-' . preg_replace('/[^0-9]/', '', (string) $row['day']); ?>
          <tr class="<?= (int) $row['manual_lock'] === 1 ? 'manual-row' : '' ?>">
            <td><form id="<?= $formId ?>" method="post"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="device" value="<?= htmlspecialchars($device, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="day" value="<?= htmlspecialchars((string) $row['day'], ENT_QUOTES, 'UTF-8') ?>"></form><strong><?= htmlspecialchars((string) $row['day'], ENT_QUOTES, 'UTF-8') ?></strong><?php if ($row['day'] === $today): ?><div class="tiny muted"><?= th('admin_today_warning') ?></div><?php endif; ?><input form="<?= $formId ?>" type="text" name="manual_note" maxlength="200" value="<?= htmlspecialchars((string) ($row['manual_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= th('admin_note') ?>"></td>
            <?php foreach (['pv_kwh','feed_in_kwh','batt_in_kwh','batt_out_kwh','consumption_kwh','grid_import_kwh'] as $field): ?><td><input form="<?= $formId ?>" type="number" min="0" step="0.001" name="<?= $field ?>" value="<?= htmlspecialchars(number_format((float) $row[$field], 3, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required></td><?php endforeach; ?>
            <td><?php if ((int) $row['manual_lock'] === 1): ?><span class="status-pill status-manual"><?= th('admin_manual_locked') ?></span><?php else: ?><span class="status-pill status-auto"><?= th('admin_automatic') ?></span><?php endif; ?></td>
            <td class="action-cell"><button form="<?= $formId ?>" class="button button-primary" name="action" value="save" type="submit"><?= th('admin_save_lock') ?></button><?php if ((int) $row['manual_lock'] === 1): ?><button form="<?= $formId ?>" class="button" name="action" value="unlock" type="submit"><?= th('admin_unlock') ?></button><?php endif; ?><button form="<?= $formId ?>" class="button button-danger" name="action" value="delete" type="submit" onclick="return confirm('<?= htmlspecialchars(t('admin_delete_confirm'), ENT_QUOTES, 'UTF-8') ?>')"><?= th('admin_delete') ?></button></td>
          </tr>
        <?php endforeach; ?>
        <?php if ($rows === []): ?><tr><td colspan="9" class="muted"><?= th('admin_no_entries') ?></td></tr><?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</div>
</body>
</html>
