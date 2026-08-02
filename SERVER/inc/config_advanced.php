<?php
/**
 * Internal defaults for day rollover and energy integration.
 * Most installations should not change this file.
 */

declare(strict_types=1);

$PVDASH_TRUST_MIDNIGHT_RESETS = false;
$PVDASH_RESET_EPS_KWH = 0.9;
$PVDASH_MONO_DROP_EPS_KWH = 0.05;
$PVDASH_ROLL_GUARD_MIN = 90;
$PVDASH_PV_NIGHT_THRESHOLD_KW = 0.05;
$PVDASH_EARLY_FIX_MIN = 30;

$PVDASH_QUIET_WINDOW_ENABLED = true;
$PVDASH_QUIET_MODE = 'ignore_totals';
$PVDASH_QUIET_START_MIN = 23 * 60 + 55;
$PVDASH_QUIET_END_MIN = 5;

// SQLite import/export limits. These values normally do not need adjustment.
$PVDASH_DATABASE_IMPORT_MAX_BYTES = 512 * 1024 * 1024;
$PVDASH_DATABASE_BACKUP_RETENTION = 5;
