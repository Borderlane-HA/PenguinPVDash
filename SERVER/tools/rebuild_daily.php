<?php

declare(strict_types=1);

require_once __DIR__ . '/../inc/db.php';
require_once __DIR__ . '/../inc/helpers.php';

if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/../inc/web_auth.php';
    pvdash_require_admin();
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Run this maintenance tool from the command line.\n";
    exit;
}

/**
 * Rebuild automatically managed daily totals from raw samples.
 * Manually edited and locked days are deliberately preserved.
 *
 * Usage:
 *   php SERVER/tools/rebuild_daily.php home
 */
$device = (string) ($argv[1] ?? 'home');
if (!preg_match('/^[A-Za-z0-9_.:-]{1,64}$/', $device)) {
    fwrite(STDERR, "Invalid device ID.\n");
    exit(2);
}

$pdo = db();
$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM daily_totals WHERE device=? AND manual_lock=0')->execute([$device]);

    $st = $pdo->prepare('SELECT ts, unit, pv_power, feed_in, battery_charge, battery_discharge, consumption, grid_import
                         FROM samples WHERE device=? ORDER BY ts ASC');
    $st->execute([$device]);

    $prev = null;
    $adds = [];

    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $ts = (int) $r['ts'];
        $unit = (string) $r['unit'];

        $pv = as_kW($r['pv_power'], $unit);
        $bi = as_kW($r['battery_charge'], $unit);
        $bo = as_kW($r['battery_discharge'], $unit);
        $gi = as_kW($r['grid_import'], $unit);
        $fiSensor = as_kW($r['feed_in'], $unit);
        $consumption = as_kW($r['consumption'], $unit);

        $feedIn = ($r['feed_in'] !== null && $fiSensor !== null)
            ? max(0.0, $fiSensor)
            : max(0.0, -1.0 * ($gi ?? 0.0));

        $current = [
            'pv' => $pv ?? 0.0,
            'fi' => $feedIn,
            'bi' => $bi ?? 0.0,
            'bo' => $bo ?? 0.0,
            'cons' => $consumption ?? 0.0,
            'imp' => max(0.0, $gi ?? 0.0),
        ];

        if ($prev !== null) {
            foreach ($current as $key => $value) {
                foreach (split_interval((int) $prev['ts'], (float) $prev[$key], $ts, (float) $value) as $day => $kwh) {
                    $adds[$day] ??= ['pv' => 0.0, 'fi' => 0.0, 'bi' => 0.0, 'bo' => 0.0, 'cons' => 0.0, 'imp' => 0.0];
                    $adds[$day][$key] += $kwh;
                }
            }
        }

        $prev = ['ts' => $ts] + $current;
    }

    $upsert = daily_totals_upsert_statement($pdo, true);
    $now = time();
    foreach ($adds as $day => $values) {
        $upsert->execute([
            $device, $day,
            $values['pv'], $values['fi'], $values['bi'], $values['bo'], $values['cons'], $values['imp'],
            $now, $now,
        ]);
    }

    $pdo->commit();
    echo "Rebuild complete for {$device}; manual locks were preserved.\n";
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Rebuild failed: {$e->getMessage()}\n");
    exit(1);
}
