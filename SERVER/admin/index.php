<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/web_auth.php';
require_once __DIR__ . '/../inc/i18n.php';
require_once __DIR__ . '/../inc/db.php';

pvdash_require_admin();
$pdo = db();
$devices = pvdash_devices($pdo);
$device = (string) ($_GET['device'] ?? $_POST['device'] ?? pvdash_default_device());
if (!in_array($device, $devices, true)) {
    $devices[] = $device;
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!pvdash_verify_csrf($_POST['csrf'] ?? null)) {
            throw new RuntimeException(t('auth_session_expired'));
        }
        $action = (string) ($_POST['action'] ?? 'save');
        $day = (string) ($_POST['day'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $device)) {
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

        $query = http_build_query(['device' => $device, 'from' => $from, 'to' => $to, 'message' => $message]);
        header('Location: index.php?' . $query);
        exit;
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
$csrf = pvdash_csrf_token();
?>
<!doctype html>
<html lang="<?= htmlspecialchars(APP_LANG, ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= th('admin_title') ?> – PenguinPVDash</title>
  <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="wrap wide-wrap">
  <header class="topbar">
    <div>
      <h1><?= th('admin_title') ?></h1>
      <p class="muted no-margin"><?= th('admin_intro') ?></p>
    </div>
    <nav class="top-actions">
      <a class="button" href="../"><?= th('nav_dashboard') ?></a>
      <?php if (pvdash_can_view_stats()): ?><a class="button" href="../stats.php"><?= th('nav_stats') ?></a><?php endif; ?>
      <a class="button button-primary" href="settings.php"><?= th('nav_settings') ?></a>
      <a class="button" href="../logout.php"><?= th('nav_logout') ?></a>
    </nav>
  </header>

  <?php if ($message !== ''): ?><div class="alert alert-success"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="alert alert-error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

  <section class="card">
    <div class="card-head"><h2><?= th('database_title') ?></h2></div>
    <p class="muted"><?= th('database_intro') ?></p>
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
            <input type="checkbox" name="confirm_replace" value="1" required>
            <span><?= th('database_import_confirmation') ?></span>
          </label>
          <button class="button button-danger" type="submit"><?= th('database_import_button') ?></button>
        </form>
      </article>
    </div>
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
      <?php foreach ([
          'pv_kwh' => 't20', 'feed_in_kwh' => 't21', 'batt_in_kwh' => 't22',
          'batt_out_kwh' => 't23', 'consumption_kwh' => 't24', 'grid_import_kwh' => 't25'
      ] as $field => $label): ?>
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
        <thead><tr>
          <th><?= th('t19') ?></th><th><?= th('t20') ?></th><th><?= th('t21') ?></th><th><?= th('t22') ?></th><th><?= th('t23') ?></th><th><?= th('t24') ?></th><th><?= th('t25') ?></th><th><?= th('admin_status') ?></th><th><?= th('admin_actions') ?></th>
        </tr></thead>
        <tbody>
        <?php foreach ($rows as $row): $formId = 'row-' . preg_replace('/[^0-9]/', '', (string) $row['day']); ?>
          <tr class="<?= (int) $row['manual_lock'] === 1 ? 'manual-row' : '' ?>">
            <td>
              <form id="<?= $formId ?>" method="post">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="device" value="<?= htmlspecialchars($device, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="from" value="<?= htmlspecialchars($from, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="to" value="<?= htmlspecialchars($to, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="day" value="<?= htmlspecialchars((string) $row['day'], ENT_QUOTES, 'UTF-8') ?>">
              </form>
              <strong><?= htmlspecialchars((string) $row['day'], ENT_QUOTES, 'UTF-8') ?></strong>
              <?php if ($row['day'] === $today): ?><div class="tiny muted"><?= th('admin_today_warning') ?></div><?php endif; ?>
              <input form="<?= $formId ?>" type="text" name="manual_note" maxlength="200" value="<?= htmlspecialchars((string) ($row['manual_note'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="<?= th('admin_note') ?>">
            </td>
            <?php foreach (['pv_kwh','feed_in_kwh','batt_in_kwh','batt_out_kwh','consumption_kwh','grid_import_kwh'] as $field): ?>
              <td><input form="<?= $formId ?>" type="number" min="0" step="0.001" name="<?= $field ?>" value="<?= htmlspecialchars(number_format((float) $row[$field], 3, '.', ''), ENT_QUOTES, 'UTF-8') ?>" required></td>
            <?php endforeach; ?>
            <td>
              <?php if ((int) $row['manual_lock'] === 1): ?><span class="status-pill status-manual"><?= th('admin_manual_locked') ?></span><?php else: ?><span class="status-pill status-auto"><?= th('admin_automatic') ?></span><?php endif; ?>
            </td>
            <td class="action-cell">
              <button form="<?= $formId ?>" class="button button-primary" name="action" value="save" type="submit"><?= th('admin_save_lock') ?></button>
              <?php if ((int) $row['manual_lock'] === 1): ?><button form="<?= $formId ?>" class="button" name="action" value="unlock" type="submit"><?= th('admin_unlock') ?></button><?php endif; ?>
              <button form="<?= $formId ?>" class="button button-danger" name="action" value="delete" type="submit" onclick="return confirm('<?= htmlspecialchars(t('admin_delete_confirm'), ENT_QUOTES, 'UTF-8') ?>')"><?= th('admin_delete') ?></button>
            </td>
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
