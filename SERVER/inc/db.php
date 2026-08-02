<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $path = (string) pvdash_config('sqlite_path');
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create SQLite data directory.');
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('PRAGMA foreign_keys=ON');

    $pdo->exec('CREATE TABLE IF NOT EXISTS samples (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        device TEXT NOT NULL,
        ts INTEGER NOT NULL,
        unit TEXT DEFAULT "kW",
        pv_power REAL,
        battery_charge REAL,
        battery_discharge REAL,
        feed_in REAL,
        consumption REAL,
        grid_import REAL,
        battery_soc REAL,
        pv_total_kwh REAL,
        feed_in_total_kwh REAL,
        batt_in_total_kwh REAL,
        batt_out_total_kwh REAL,
        consumption_total_kwh REAL,
        grid_import_total_kwh REAL
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
        manual_lock INTEGER NOT NULL DEFAULT 0,
        manual_updated_ts INTEGER,
        manual_note TEXT,
        created_ts INTEGER,
        updated_ts INTEGER
    )');
    $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS uq_daily_device_day ON daily_totals(device, day)');

    $pdo->exec('CREATE TABLE IF NOT EXISTS integ_state (
        device TEXT PRIMARY KEY,
        last_ts INTEGER,
        last_pv REAL,
        last_feed_in REAL,
        last_bi REAL,
        last_bo REAL,
        last_cons REAL,
        last_gi REAL,
        last_unit TEXT DEFAULT "kW"
    )');

    $migrations = [
        'ALTER TABLE samples ADD COLUMN consumption_total_kwh REAL',
        'ALTER TABLE samples ADD COLUMN grid_import_total_kwh REAL',
        'ALTER TABLE daily_totals ADD COLUMN consumption_kwh REAL DEFAULT 0',
        'ALTER TABLE daily_totals ADD COLUMN grid_import_kwh REAL DEFAULT 0',
        'ALTER TABLE daily_totals ADD COLUMN manual_lock INTEGER NOT NULL DEFAULT 0',
        'ALTER TABLE daily_totals ADD COLUMN manual_updated_ts INTEGER',
        'ALTER TABLE daily_totals ADD COLUMN manual_note TEXT',
        'ALTER TABLE integ_state ADD COLUMN last_cons REAL',
        'ALTER TABLE integ_state ADD COLUMN last_gi REAL',
    ];
    foreach ($migrations as $sql) {
        try {
            $pdo->exec($sql);
        } catch (PDOException) {
            // Column already exists.
        }
    }

    return $pdo;
}

function daily_totals_upsert_statement(PDO $pdo, bool $respectManualLock = true): PDOStatement
{
    $where = $respectManualLock ? ' WHERE daily_totals.manual_lock = 0' : '';
    return $pdo->prepare('INSERT INTO daily_totals (
            device, day, pv_kwh, feed_in_kwh, batt_in_kwh, batt_out_kwh,
            consumption_kwh, grid_import_kwh, created_ts, updated_ts
        ) VALUES (?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(device,day) DO UPDATE SET
            pv_kwh=excluded.pv_kwh,
            feed_in_kwh=excluded.feed_in_kwh,
            batt_in_kwh=excluded.batt_in_kwh,
            batt_out_kwh=excluded.batt_out_kwh,
            consumption_kwh=excluded.consumption_kwh,
            grid_import_kwh=excluded.grid_import_kwh,
            updated_ts=excluded.updated_ts' . $where);
}

function pvdash_devices(PDO $pdo): array
{
    $devices = array_keys((array) pvdash_config('api_keys', []));
    foreach (['samples', 'daily_totals'] as $table) {
        $rows = $pdo->query('SELECT DISTINCT device FROM ' . $table . ' ORDER BY device')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $device) {
            $devices[] = (string) $device;
        }
    }
    $devices = array_values(array_unique(array_filter($devices, static fn ($v) => $v !== '')));
    sort($devices, SORT_NATURAL | SORT_FLAG_CASE);
    return $devices ?: ['home'];
}
