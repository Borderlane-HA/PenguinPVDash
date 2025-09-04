<?php
require_once __DIR__ . '/config.php';
function db() {
  global $PVDASH_SQLITE;
  static $pdo = null;
  if ($pdo) return $pdo;
  $pdo = new PDO('sqlite:' . $PVDASH_SQLITE);
  $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
  $pdo->exec('PRAGMA journal_mode=WAL');
  $pdo->exec('CREATE TABLE IF NOT EXISTS samples (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device TEXT NOT NULL,
    ts INTEGER NOT NULL,
    unit TEXT DEFAULT "kW",
    pv_power REAL, battery_charge REAL, battery_discharge REAL,
    feed_in REAL, consumption REAL, grid_import REAL,
    battery_soc REAL,
    pv_total_kwh REAL, feed_in_total_kwh REAL, batt_in_total_kwh REAL, batt_out_total_kwh REAL,
    consumption_total_kwh REAL, grid_import_total_kwh REAL
  )');
  $pdo->exec('CREATE INDEX IF NOT EXISTS idx_samples_device_ts ON samples(device, ts)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS daily_totals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device TEXT NOT NULL,
    day TEXT NOT NULL,
    pv_kwh REAL DEFAULT 0,
    feed_in_kwh REAL DEFAULT 0,
    batt_in_kwh REAL DEFAULT 0,
    batt_out_kwh REAL DEFAULT 0,
    consumption_kwh REAL DEFAULT 0,
    grid_import_kwh REAL DEFAULT 0,
    created_ts INTEGER, updated_ts INTEGER
  )');
  $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_daily_device_day ON daily_totals(device, day)');
  $pdo->exec('CREATE TABLE IF NOT EXISTS integ_state (
    device TEXT PRIMARY KEY,
    last_ts INTEGER,
    last_pv REAL, last_feed_in REAL, last_bi REAL, last_bo REAL,
    last_cons REAL, last_gi REAL,
    last_unit TEXT DEFAULT "kW"
  )');
  try { $pdo->exec('ALTER TABLE samples ADD COLUMN consumption_total_kwh REAL'); } catch (Exception $e) {}
  try { $pdo->exec('ALTER TABLE samples ADD COLUMN grid_import_total_kwh REAL'); } catch (Exception $e) {}
  try { $pdo->exec('ALTER TABLE daily_totals ADD COLUMN consumption_kwh REAL DEFAULT 0'); } catch (Exception $e) {}
  try { $pdo->exec('ALTER TABLE daily_totals ADD COLUMN grid_import_kwh REAL DEFAULT 0'); } catch (Exception $e) {}
  try { $pdo->exec('ALTER TABLE integ_state ADD COLUMN last_cons REAL'); } catch (Exception $e) {}
  try { $pdo->exec('ALTER TABLE integ_state ADD COLUMN last_gi REAL'); } catch (Exception $e) {}
  return $pdo;
}
?>