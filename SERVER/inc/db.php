<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Return the configured SQLite path.
 */
function pvdash_database_path(): string
{
    return (string) pvdash_config('sqlite_path');
}

/**
 * Make sure the SQLite data directory exists.
 */
function pvdash_ensure_database_directory(string $path): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Unable to create SQLite data directory.');
    }
}

/**
 * Lock file used to pause normal reads/writes during an export or import.
 *
 * Every normal database request keeps a shared lock until the PHP request ends.
 * Database maintenance obtains an exclusive lock, so a backup is never copied
 * while an ingest request is writing to SQLite.
 *
 * @return resource
 */
function pvdash_acquire_database_lock(int $operation = LOCK_SH)
{
    $path = pvdash_database_path();
    pvdash_ensure_database_directory($path);
    $lockPath = dirname($path) . '/.pvdash-db.lock';
    $handle = fopen($lockPath, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open the database maintenance lock.');
    }
    if (!flock($handle, $operation)) {
        fclose($handle);
        throw new RuntimeException('Unable to acquire the database maintenance lock.');
    }
    return $handle;
}

/** @param resource|null $handle */
function pvdash_release_database_lock($handle): void
{
    if (is_resource($handle)) {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * Open a SQLite database and optionally create/migrate the PenguinPVDash schema.
 */
function pvdash_open_database(string $path, bool $initialize = true): PDO
{
    pvdash_ensure_database_directory($path);
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout=5000');
    $pdo->exec('PRAGMA foreign_keys=ON');

    if ($initialize) {
        $pdo->exec('PRAGMA journal_mode=WAL');
        pvdash_initialize_database($pdo);
    }

    return $pdo;
}

/**
 * Create and migrate all application tables.
 */
function pvdash_initialize_database(PDO $pdo): void
{
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
}

/**
 * Normal application database connection. The shared lock lives until the
 * current PHP request ends and prevents concurrent import/export replacement.
 */
function db(): PDO
{
    static $pdo = null;
    static $lockHandle = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $lockHandle = pvdash_acquire_database_lock(LOCK_SH);
    $pdo = pvdash_open_database(pvdash_database_path(), true);
    return $pdo;
}

/**
 * Flush WAL data into the main SQLite file.
 */
function pvdash_checkpoint_database(PDO $pdo): void
{
    $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
}

/**
 * Create a consistent, standalone SQLite snapshot using SQLite itself.
 * The caller must hold the exclusive database maintenance lock.
 */
function pvdash_create_database_snapshot(string $sourcePath, string $destinationPath): void
{
    if (is_file($destinationPath) && !unlink($destinationPath)) {
        throw new RuntimeException('Unable to replace an existing snapshot file.');
    }

    $pdo = pvdash_open_database($sourcePath, true);
    pvdash_checkpoint_database($pdo);
    $quotedDestination = $pdo->quote($destinationPath);
    $pdo->exec('VACUUM INTO ' . $quotedDestination);
    $pdo = null;

    if (!is_file($destinationPath) || filesize($destinationPath) === 0) {
        throw new RuntimeException('The SQLite snapshot could not be created.');
    }
    @chmod($destinationPath, 0660);
}

/**
 * Validate an uploaded SQLite file before it can replace the live database.
 * Old PenguinPVDash databases are accepted and migrated after validation.
 */
function pvdash_validate_database_file(string $path): void
{
    if (!is_file($path) || filesize($path) < 100) {
        throw new RuntimeException(t('database_error_empty'));
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        throw new RuntimeException(t('database_error_read'));
    }
    $header = fread($handle, 16);
    fclose($handle);
    if ($header !== "SQLite format 3\0") {
        throw new RuntimeException(t('database_error_format'));
    }

    try {
        $pdo = pvdash_open_database($path, false);
        $pdo->exec('PRAGMA query_only=ON');

        $integrityRows = $pdo->query('PRAGMA integrity_check')->fetchAll(PDO::FETCH_COLUMN);
        if ($integrityRows !== ['ok']) {
            throw new RuntimeException(t('database_error_integrity'));
        }

        $triggers = $pdo->query("SELECT name FROM sqlite_master WHERE type='trigger' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
        if ($triggers !== []) {
            throw new RuntimeException(t('database_error_triggers'));
        }

        $requiredColumns = [
            'samples' => ['device', 'ts'],
            'daily_totals' => ['device', 'day'],
        ];
        foreach ($requiredColumns as $table => $columns) {
            $typeStmt = $pdo->prepare("SELECT type FROM sqlite_master WHERE name=?");
            $typeStmt->execute([$table]);
            if ($typeStmt->fetchColumn() !== 'table') {
                throw new RuntimeException(t('database_error_schema'));
            }
            $actualColumns = $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_COLUMN, 1);
            foreach ($columns as $column) {
                if (!in_array($column, $actualColumns, true)) {
                    throw new RuntimeException(t('database_error_schema'));
                }
            }
        }
    } catch (PDOException $e) {
        throw new RuntimeException(t('database_error_open'), 0, $e);
    }
}

function pvdash_database_import_max_bytes(): int
{
    global $PVDASH_DATABASE_IMPORT_MAX_BYTES;
    return max(1024 * 1024, (int) ($PVDASH_DATABASE_IMPORT_MAX_BYTES ?? 536870912));
}

function pvdash_database_backup_retention(): int
{
    global $PVDASH_DATABASE_BACKUP_RETENTION;
    $configured = (int) pvdash_config('backup_retention', (int) ($PVDASH_DATABASE_BACKUP_RETENTION ?? 5));
    return max(1, min(25, $configured));
}

function pvdash_prune_database_backups(string $directory): void
{
    $files = glob($directory . '/pvdash-before-import-*.sqlite') ?: [];
    usort($files, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
    foreach (array_slice($files, pvdash_database_backup_retention()) as $file) {
        @unlink($file);
        @unlink($file . '-wal');
        @unlink($file . '-shm');
    }
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


function pvdash_database_backup_files(): array
{
    $directory = dirname(pvdash_database_path());
    $files = glob($directory . '/pvdash-before-import-*.sqlite') ?: [];
    usort($files, static fn (string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));

    return array_map(static function (string $path): array {
        return [
            'name' => basename($path),
            'path' => $path,
            'size' => (int) (filesize($path) ?: 0),
            'mtime' => (int) (filemtime($path) ?: 0),
        ];
    }, $files);
}

function pvdash_database_backup_path(string $filename): string
{
    $filename = basename($filename);
    if (preg_match('/^pvdash-before-import-[A-Za-z0-9-]+\.sqlite$/', $filename) !== 1) {
        throw new InvalidArgumentException('Invalid backup filename.');
    }
    $path = dirname(pvdash_database_path()) . DIRECTORY_SEPARATOR . $filename;
    if (!is_file($path)) {
        throw new RuntimeException('The selected backup no longer exists.');
    }
    return $path;
}

function pvdash_device_data_exists(PDO $pdo, string $device): bool
{
    foreach (['samples', 'daily_totals', 'integ_state'] as $table) {
        $stmt = $pdo->prepare('SELECT 1 FROM ' . $table . ' WHERE device=? LIMIT 1');
        $stmt->execute([$device]);
        if ($stmt->fetchColumn() !== false) {
            return true;
        }
    }
    return false;
}

function pvdash_delete_device_data(PDO $pdo, string $device): void
{
    $pdo->beginTransaction();
    try {
        foreach (['samples', 'daily_totals', 'integ_state'] as $table) {
            $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE device=?');
            $stmt->execute([$device]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function pvdash_rename_device_data(PDO $pdo, string $from, string $to, bool $replaceTarget = false): void
{
    if ($from === $to) {
        return;
    }
    if (!pvdash_valid_device_id($from) || !pvdash_valid_device_id($to)) {
        throw new InvalidArgumentException('Invalid device ID.');
    }
    if (!pvdash_device_data_exists($pdo, $from)) {
        throw new RuntimeException('The source device does not contain data.');
    }
    if (!$replaceTarget && pvdash_device_data_exists($pdo, $to)) {
        throw new RuntimeException(t('settings_device_target_exists', ['device' => $to]));
    }

    $pdo->beginTransaction();
    try {
        if ($replaceTarget) {
            foreach (['samples', 'daily_totals', 'integ_state'] as $table) {
                $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE device=?');
                $stmt->execute([$to]);
            }
        }
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

function pvdash_device_summaries(PDO $pdo): array
{
    $result = [];
    foreach (array_keys((array) pvdash_config('api_keys', [])) as $device) {
        $result[(string) $device] = [
            'device' => (string) $device,
            'samples' => 0,
            'daily' => 0,
            'first_ts' => null,
            'last_ts' => null,
            'first_day' => null,
            'last_day' => null,
            'has_api_key' => true,
        ];
    }

    foreach ($pdo->query('SELECT device, COUNT(*) AS count, MIN(ts) AS first_ts, MAX(ts) AS last_ts FROM samples GROUP BY device') as $row) {
        $device = (string) $row['device'];
        $result[$device] ??= [
            'device' => $device,
            'samples' => 0,
            'daily' => 0,
            'first_ts' => null,
            'last_ts' => null,
            'first_day' => null,
            'last_day' => null,
            'has_api_key' => false,
        ];
        $result[$device]['samples'] = (int) $row['count'];
        $result[$device]['first_ts'] = $row['first_ts'] !== null ? (int) $row['first_ts'] : null;
        $result[$device]['last_ts'] = $row['last_ts'] !== null ? (int) $row['last_ts'] : null;
    }

    foreach ($pdo->query('SELECT device, COUNT(*) AS count, MIN(day) AS first_day, MAX(day) AS last_day FROM daily_totals GROUP BY device') as $row) {
        $device = (string) $row['device'];
        $result[$device] ??= [
            'device' => $device,
            'samples' => 0,
            'daily' => 0,
            'first_ts' => null,
            'last_ts' => null,
            'first_day' => null,
            'last_day' => null,
            'has_api_key' => false,
        ];
        $result[$device]['daily'] = (int) $row['count'];
        $result[$device]['first_day'] = $row['first_day'] !== null ? (string) $row['first_day'] : null;
        $result[$device]['last_day'] = $row['last_day'] !== null ? (string) $row['last_day'] : null;
    }

    ksort($result, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($result);
}

/**
 * Replace the active database with a validated SQLite file and keep a backup
 * of the previous active database. The caller must not have opened db() in the
 * same request because this operation needs the exclusive maintenance lock.
 */
function pvdash_replace_database_from_file(string $sourcePath): string
{
    pvdash_validate_database_file($sourcePath);

    $targetPath = pvdash_database_path();
    pvdash_ensure_database_directory($targetPath);
    $directory = dirname($targetPath);
    $token = bin2hex(random_bytes(8));
    $newPath = $directory . '/.pvdash-import-' . $token . '.sqlite';
    $oldPath = $directory . '/.pvdash-replaced-' . $token . '.sqlite';
    $lockHandle = null;
    $backupName = '';

    try {
        $lockHandle = pvdash_acquire_database_lock(LOCK_EX);
        if (!copy($sourcePath, $newPath)) {
            throw new RuntimeException(t('database_error_copy'));
        }
        @chmod($newPath, 0660);

        $newPdo = pvdash_open_database($newPath, true);
        pvdash_checkpoint_database($newPdo);
        $newPdo = null;
        @unlink($newPath . '-wal');
        @unlink($newPath . '-shm');
        pvdash_validate_database_file($newPath);

        if (is_file($targetPath)) {
            $backupName = 'pvdash-before-import-' . date('Ymd-His') . '-' . substr($token, 0, 6) . '.sqlite';
            $backupPath = $directory . '/' . $backupName;
            try {
                pvdash_create_database_snapshot($targetPath, $backupPath);
            } catch (Throwable) {
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
            if (is_file($oldPath)) {
                @rename($oldPath, $targetPath);
            }
            throw new RuntimeException(t('database_error_replace'));
        }
        @chmod($targetPath, 0660);
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
        pvdash_prune_database_backups($directory);
        pvdash_release_database_lock($lockHandle);
        return $backupName;
    } catch (Throwable $e) {
        if (is_file($newPath)) {
            @unlink($newPath);
        }
        @unlink($newPath . '-wal');
        @unlink($newPath . '-shm');
        if (is_file($oldPath) && !is_file($targetPath)) {
            @rename($oldPath, $targetPath);
        }
        pvdash_release_database_lock($lockHandle);
        throw $e;
    }
}

function pvdash_data_devices(PDO $pdo): array
{
    $devices = [];
    foreach (['samples', 'daily_totals', 'integ_state'] as $table) {
        $rows = $pdo->query("SELECT DISTINCT device FROM " . $table . " WHERE device IS NOT NULL AND device <> ''")->fetchAll(PDO::FETCH_COLUMN);
        foreach ($rows as $device) {
            $devices[] = (string) $device;
        }
    }
    $devices = array_values(array_unique($devices));
    sort($devices, SORT_NATURAL | SORT_FLAG_CASE);
    return $devices;
}
