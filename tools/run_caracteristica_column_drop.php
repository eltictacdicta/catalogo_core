<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

/**
 * CAR-15 clause 2 / SUGGESTION 2 — operator runner for the gated post-soak drop.
 *
 * This is the human-facing invoker of the runbook documented in
 * `Services/CaracteristicaColumnDropMigration.php`. It is NOT boot-wired:
 * nothing in the plugin requires this file, and it is never reached from
 * `Init::init()`, `Init::upgrade()` or any request path. Dropping schema is an
 * explicit, operator-gated deploy step.
 *
 * Usage:
 *   # report only — dry run, no DDL at all
 *   ddev exec php plugins/catalogo_core/tools/run_caracteristica_column_drop.php
 *
 *   # the actual closed loop (clause 1 -> clause 2 -> dead-column cleanup)
 *   ddev exec php plugins/catalogo_core/tools/run_caracteristica_column_drop.php \
 *       --apply --dev17-rewritten
 *
 * Before `--apply`:
 *   1. keep a pre-drop dump (operator-level safety net; not automated);
 *   2. confirm the catalog is on the feature path. It is the DEFAULT: leave
 *      `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` undefined (or TRUE). Defining
 *      it as FALSE is the emergency legacy opt-out and refuses this drop;
 *   3. rewrite the DEV-17 legacy catalog membership filters to the feature
 *      tables — clause 2 drops the columns those filters read, so running it
 *      first leaves the membership queries invalid. `--dev17-rewritten` is the
 *      operator's explicit attestation of that rewrite;
 *   4. clear the Twig cache afterwards.
 *
 * Exit codes: 0 = applied (or a clean dry run), 1 = refused or failed.
 */

define('FS_FOLDER', dirname(__DIR__, 3));

/// `fs_db2.php` requires its drivers with root-relative paths.
chdir(FS_FOLDER);

require_once FS_FOLDER . '/config.php';
require_once FS_FOLDER . '/vendor/autoload.php';
require_once FS_FOLDER . '/base/fs_secret_migrator.php';
fs_secret_migrator::ensure();
require_once FS_FOLDER . '/base/config2.php';
require_once FS_FOLDER . '/base/fs_core_log.php';
require_once FS_FOLDER . '/base/fs_db2.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaConfig.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaColumnDropMigration.php';

use FSFramework\Plugins\catalogo_core\Services\CaracteristicaColumnDropMigration;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaConfig;

$arguments = $argv ?? [];
$apply = in_array('--apply', $arguments, true);
$dev17Attested = in_array('--dev17-rewritten', $arguments, true);

echo "CAR-15 post-soak column drop — catalogo_core\n";
echo str_repeat('=', 62) . "\n";

try {
    $db = new \fs_db2();
    $pending = CaracteristicaColumnDropMigration::pendingVisibilityColumns($db);
} catch (\Throwable $e) {
    fwrite(STDERR, "Cannot connect to the database: {$e->getMessage()}\n");
    exit(1);
}

$flagName = CaracteristicaConfig::READ_THROUGH_FLAG;
printf(
    "read path (%s): %s\n",
    $flagName,
    CaracteristicaConfig::legacy_read_explicitly_enabled()
        ? 'LEGACY (explicit opt-out)'
        : 'FEATURE (default; feature reads, legacy only on an explicit opt-out)'
);
printf("DEV-17 attestation (--dev17-rewritten): %s\n", $dev17Attested ? 'given' : 'not given');
printf("gated columns still present: %d\n", count($pending));
foreach ($pending as $column) {
    echo '  - ' . $column . "\n";
}

if (!$apply) {
    echo "\nDRY RUN — no DDL was emitted. Pass --apply --dev17-rewritten to run the closed loop.\n";
    exit(0);
}

if (!$dev17Attested) {
    echo "\nREFUSED — --apply requires the explicit --dev17-rewritten attestation:\n";
    echo "  the DEV-17 legacy membership filters must be rewritten to the feature tables\n";
    echo "  before clause 2 drops the columns they filter on.\n";
    exit(1);
}

printf("\nRunning the closed loop (%s) ...\n", implode(' -> ', array_keys(CaracteristicaColumnDropMigration::POST_SOAK_STEPS)));

try {
    $report = CaracteristicaColumnDropMigration::runPostSoak($db, true);
} catch (\Throwable $e) {
    fwrite(STDERR, "The closed loop failed: {$e->getMessage()}\n");
    exit(1);
}

printf("status: %s\n", $report['status']);
printf("reason: %s\n", $report['reason']);
foreach ($report['steps'] as $step => $ok) {
    printf("  %-14s %s\n", $step, $ok ? 'ok' : 'FAILED');
}
printf("dropped: %d\n", count($report['dropped']));
foreach ($report['dropped'] as $column) {
    echo '  - ' . $column . "\n";
}
printf("remaining: %d\n", count($report['remaining']));
foreach ($report['remaining'] as $column) {
    echo '  ! ' . $column . "\n";
}

if ($report['status'] !== 'applied') {
    echo "\nThe closed loop did not apply cleanly — do not clear the Twig cache yet.\n";
    exit(1);
}

echo "\nDone. Clear the Twig cache before serving the catalog views again.\n";
exit(0);
