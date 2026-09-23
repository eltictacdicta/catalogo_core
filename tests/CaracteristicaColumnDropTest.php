<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Services\CaracteristicaColumnDropMigration;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaConfig;
use PHPUnit\Framework\TestCase;

/**
 * CAR-15 — gated reversible column drop (clause 1: D12 opcional flags).
 *
 * Clause 1 drops `en_catalogo`/`en_tarifa` from `tarif_opcional_ext` and
 * `tarif_tarifa_opcional`. It is gated only on the CAR-12 behaviour-preservation
 * parity test (`OpcionalVisibilityParityTest`), needs no soak, and MUST be
 * idempotent (one `hasColumn()` guard per `ALTER`).
 *
 * Clause 2 (article/family columns, WU-7) MUST refuse to run while the legacy
 * read path was **explicitly** selected (the emergency opt-out) and MUST change
 * no schema. The feature path is the default, so an undefined constant lets the
 * drop run. Its reversibility rests on the feature tables, which no clause may
 * touch.
 */
final class CaracteristicaColumnDropSpyDb extends \fs_db2
{
    /** @var list<string> */
    public array $execStatements = [];

    /** @var list<string> */
    public array $selectStatements = [];

    public bool $execResult = true;

    /**
     * @param array<string, list<string>> $schema table => columns
     */
    public function __construct(public array $schema = [])
    {
        // Deliberately skip parent::__construct(): no engine, no DB connection.
    }

    public function var2str($val): string
    {
        if ($val === null) {
            return 'NULL';
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function escape_string($str)
    {
        return addslashes((string) $str);
    }

    public function select($sql, $params = [])
    {
        $sql = trim((string) $sql);
        $this->selectStatements[] = $sql;

        if (preg_match('/SHOW COLUMNS FROM `([^`]+)` LIKE \'([^\']+)\'/i', $sql, $matches)) {
            $table = $matches[1];
            $column = $matches[2];

            return in_array($column, $this->schema[$table] ?? [], true)
                ? [['Field' => $column]]
                : [];
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false): bool
    {
        $sql = trim((string) $sql);
        $this->execStatements[] = $sql;

        if (!$this->execResult) {
            return false;
        }

        if (preg_match('/ALTER TABLE\s+(\S+)\s+DROP COLUMN\s+([A-Za-z0-9_]+)\s*;?\s*$/i', $sql, $matches)) {
            $table = $matches[1];
            $column = $matches[2];
            $this->schema[$table] = array_values(array_filter(
                $this->schema[$table] ?? [],
                static fn (string $name): bool => $name !== $column
            ));
        }

        return true;
    }
}

final class CaracteristicaColumnDropTest extends TestCase
{
    private const SERVICE_RELATIVE = 'plugins/catalogo_core/Services/CaracteristicaColumnDropMigration.php';

    private static bool $baseLoaded = false;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaConfig.php';
        require_once FS_FOLDER . '/' . self::SERVICE_RELATIVE;
        self::$baseLoaded = true;
    }

    private function fullSchema(): array
    {
        return [
            'tarif_opcional_ext' => ['id_opcional', 'ref_sap', 'en_catalogo', 'en_tarifa'],
            'tarif_tarifa_opcional' => ['codtarifa', 'id_opcional', 'en_catalogo', 'en_tarifa', 'activa', 'orden'],
            'tarif_articulo_precios' => ['referencia', 'codtarifa', 'precio', 'activo', 'en_catalogo', 'en_tarifa'],
            'tarif_tarifa_articulo' => ['codtarifa', 'referencia', 'orden', 'en_catalogo', 'en_tarifa'],
            'tarif_tarifa_familia' => ['codtarifa', 'codfamilia', 'activa', 'orden', 'en_catalogo', 'en_tarifa'],
            // Divergence 1: the live retired table has no visibility columns.
            'tarif_familia_ext' => ['codfamilia', 'capitulo', 'nivel'],
            // SUGGESTION 2: dead columns present live, outside every model.
            'catalogo_opcionales' => ['id', 'codigo', 'nombre', 'activo', 'en_catalogo', 'en_tarifa'],
            // Constraint 3: the per-lista price inclusion flag MUST stay.
            'catalogo_opcional_precios' => ['id', 'id_opcional', 'codtarifa', 'precio', 'en_catalogo'],
        ];
    }

    private function alterations(array $statements): array
    {
        return array_values(array_filter(
            $statements,
            static fn (string $sql): bool => stripos($sql, 'ALTER TABLE') === 0
        ));
    }

    // =====================================================================
    // Clause 1 — D12 drop
    // =====================================================================

    public function test_has_column_detects_the_live_column(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());

        $this->assertTrue(CaracteristicaColumnDropMigration::hasColumn($db, 'tarif_opcional_ext', 'en_catalogo'));
        $this->assertFalse(CaracteristicaColumnDropMigration::hasColumn($db, 'tarif_familia_ext', 'en_catalogo'));
    }

    public function test_d12_drop_removes_the_four_opcional_visibility_columns(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());

        $this->assertTrue(CaracteristicaColumnDropMigration::dropD12OpcionalColumns($db));

        $alterations = $this->alterations($db->execStatements);
        $this->assertCount(4, $alterations, 'exactly the four opcional visibility columns must be dropped');

        $sql = implode("\n", $alterations);
        $this->assertStringContainsString('ALTER TABLE tarif_opcional_ext DROP COLUMN en_catalogo', $sql);
        $this->assertStringContainsString('ALTER TABLE tarif_opcional_ext DROP COLUMN en_tarifa', $sql);
        $this->assertStringContainsString('ALTER TABLE tarif_tarifa_opcional DROP COLUMN en_catalogo', $sql);
        $this->assertStringContainsString('ALTER TABLE tarif_tarifa_opcional DROP COLUMN en_tarifa', $sql);

        $this->assertNotContains('en_catalogo', $db->schema['tarif_opcional_ext']);
        $this->assertNotContains('en_tarifa', $db->schema['tarif_opcional_ext']);
        $this->assertNotContains('en_catalogo', $db->schema['tarif_tarifa_opcional']);
        $this->assertNotContains('en_tarifa', $db->schema['tarif_tarifa_opcional']);
    }

    public function test_d12_drop_keeps_the_surviving_state_and_ref_sap_columns(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());

        CaracteristicaColumnDropMigration::dropD12OpcionalColumns($db);

        $this->assertSame(['id_opcional', 'ref_sap'], $db->schema['tarif_opcional_ext']);
        $this->assertSame(['codtarifa', 'id_opcional', 'activa', 'orden'], $db->schema['tarif_tarifa_opcional']);
    }

    public function test_d12_drop_is_idempotent(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());

        CaracteristicaColumnDropMigration::dropD12OpcionalColumns($db);
        $before = count($db->execStatements);

        $this->assertTrue(CaracteristicaColumnDropMigration::dropD12OpcionalColumns($db));
        $this->assertSame(
            $before,
            count($db->execStatements),
            'a second run must find no column and emit no ALTER'
        );
    }

    public function test_d12_drop_does_not_touch_the_article_family_surfaces(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());

        CaracteristicaColumnDropMigration::dropD12OpcionalColumns($db);

        $sql = implode("\n", $db->execStatements);
        foreach (['tarif_articulo_precios', 'tarif_tarifa_articulo', 'tarif_tarifa_familia', 'tarif_familia_ext'] as $table) {
            $this->assertStringNotContainsString($table, $sql, 'clause 1 must not drop from ' . $table);
        }
    }

    public function test_d12_drop_reports_failure_and_stops_on_a_failed_alter(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $db->execResult = false;

        $this->assertFalse(CaracteristicaColumnDropMigration::dropD12OpcionalColumns($db));
        $this->assertCount(1, $this->alterations($db->execStatements), 'the first failure must stop the run');
    }

    public function test_d12_drop_is_marked_as_the_car12_parity_gated_clause(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/' . self::SERVICE_RELATIVE);

        $this->assertStringContainsString('CAR-12', $source, 'clause 1 must name its parity gate');
        $this->assertStringContainsString('OpcionalVisibilityParityTest', $source);
        $this->assertStringContainsString('dropLegacyArticleFamilyColumns', $source, 'clause 2 must exist (WU-7)');
    }

    public function test_d12_drop_refuses_while_the_legacy_path_is_explicitly_selected(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(true);

        self::assertFalse(
            $migration::dropD12OpcionalColumns($db),
            'the emergency legacy opt-out must refuse clause 1 too'
        );
        self::assertSame([], $db->execStatements, 'a refused clause 1 must change no schema');
        self::assertContains('en_catalogo', $db->schema['tarif_opcional_ext']);
        self::assertContains('en_tarifa', $db->schema['tarif_tarifa_opcional']);
    }

    public function test_boot_entry_point_drops_the_four_opcional_columns_and_is_idempotent(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());

        self::assertTrue(CaracteristicaColumnDropMigration::migrateIfNeeded($db));
        self::assertCount(
            4,
            $this->alterations($db->execStatements),
            'the deploy-time entry point drops exactly the four opcional visibility columns'
        );
        self::assertNotContains('en_catalogo', $db->schema['tarif_opcional_ext']);
        self::assertNotContains('en_tarifa', $db->schema['tarif_tarifa_opcional']);

        $before = count($db->execStatements);
        self::assertTrue(CaracteristicaColumnDropMigration::migrateIfNeeded($db));
        self::assertSame($before, count($db->execStatements), 'a second deploy run must be a clean no-op');
    }

    public function test_boot_entry_point_refuses_while_the_legacy_path_is_explicitly_selected(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(true);

        self::assertFalse($migration::migrateIfNeeded($db));
        self::assertSame([], $db->execStatements, 'the opt-out must refuse the deploy-time drop');
    }

    public function test_boot_entry_point_leaves_the_staged_clause_2_columns_untouched(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());

        CaracteristicaColumnDropMigration::migrateIfNeeded($db);

        $sql = implode("\n", $db->execStatements);
        foreach (['tarif_articulo_precios', 'tarif_tarifa_articulo', 'tarif_tarifa_familia', 'tarif_familia_ext', 'catalogo_opcionales'] as $table) {
            self::assertStringNotContainsString(
                $table,
                $sql,
                'clause 1 must leave ' . $table . ' to the staged clause 2 drop'
            );
        }
        self::assertContains('en_catalogo', $db->schema['tarif_articulo_precios']);
        self::assertContains('en_catalogo', $db->schema['catalogo_opcionales']);
    }

    public function test_upgrade_wires_the_clause_1_drop_and_init_does_not(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/Init.php');
        $init = $this->methodSource($src, 'public function init(): void');
        $upgrade = $this->methodSource($src, 'public static function upgrade(): void');

        self::assertStringNotContainsString(
            'CaracteristicaColumnDropMigration',
            $init,
            'a schema DROP must not run on every request (init())'
        );
        self::assertStringContainsString(
            'CaracteristicaColumnDropMigration::migrateIfNeeded(',
            $upgrade,
            'the version-change path (upgrade()) must auto-run the clause-1 drop'
        );
    }

    // =====================================================================
    // Clause 2 — gated post-soak drop (WU-7 stub)
    // =====================================================================

    public function test_legacy_drop_refuses_to_run_while_the_legacy_path_is_explicitly_selected(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(true);

        $this->assertFalse($migration::dropLegacyArticleFamilyColumns($db));
        $this->assertSame([], $db->execStatements, 'a refused gate must change no schema');
        $this->assertSame(
            $this->fullSchema()['tarif_articulo_precios'],
            $db->schema['tarif_articulo_precios'],
            'the legacy visibility columns must be untouched while the opt-out is set'
        );
    }

    public function test_legacy_drop_runs_by_default_because_undefined_selects_the_feature_path(): void
    {
        // The real gate (no seam override): the test environment leaves the
        // constant undefined, which is the production default.
        $this->assertTrue(
            CaracteristicaConfig::read_through(),
            'the feature path is the default'
        );
        $this->assertFalse(
            CaracteristicaConfig::legacy_read_explicitly_enabled(),
            'an undefined constant is not an explicit opt-out'
        );

        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());

        $this->assertTrue(CaracteristicaColumnDropMigration::dropLegacyArticleFamilyColumns($db));
    }

    public function test_legacy_drop_drops_the_article_and_family_columns_once_the_opt_out_is_not_set(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $this->assertTrue($migration::dropLegacyArticleFamilyColumns($db));

        $sql = implode("\n", $this->alterations($db->execStatements));
        foreach (['tarif_articulo_precios', 'tarif_tarifa_articulo', 'tarif_tarifa_familia'] as $table) {
            $this->assertStringContainsString('ALTER TABLE ' . $table . ' DROP COLUMN en_catalogo', $sql);
            $this->assertStringContainsString('ALTER TABLE ' . $table . ' DROP COLUMN en_tarifa', $sql);
        }

        // Divergence 1: the retired table never had the columns ⇒ no ALTER.
        $this->assertStringNotContainsString('tarif_familia_ext', $sql);
    }

    public function test_legacy_drop_is_idempotent_by_default(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $migration::dropLegacyArticleFamilyColumns($db);
        $before = count($db->execStatements);

        $this->assertTrue($migration::dropLegacyArticleFamilyColumns($db));
        $this->assertSame($before, count($db->execStatements), 'a second run must be a no-op');
    }

    /**
     * The legacy opt-out gate is exercised through the service's overridable
     * seam. Defining `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` in a test would
     * be process-wide and cannot be unset, so it would leak into every later
     * test of a non-isolated run (root Plugins suite).
     *
     * @return class-string<CaracteristicaColumnDropMigration>
     */
    private function migrationWithLegacyReadExplicit(bool $explicit): string
    {
        if ($explicit) {
            return get_class(new class () extends CaracteristicaColumnDropMigration {
                protected static function legacy_read_explicitly_enabled(): bool
                {
                    return true;
                }
            });
        }

        return get_class(new class () extends CaracteristicaColumnDropMigration {
            protected static function legacy_read_explicitly_enabled(): bool
            {
                return false;
            }
        });
    }

    // =====================================================================
    // SUGGESTION 2 — dead opcional columns (gated cleanup)
    // =====================================================================

    public function test_dead_opcional_drop_refuses_while_the_legacy_path_is_explicitly_selected(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(true);

        self::assertFalse($migration::dropDeadOpcionalColumns($db));
        self::assertSame([], $db->execStatements, 'a refused gate must change no schema');
        self::assertContains('en_catalogo', $db->schema['catalogo_opcionales']);
        self::assertContains('en_tarifa', $db->schema['catalogo_opcionales']);
    }

    public function test_dead_opcional_drop_removes_the_unused_catalogo_opcionales_flags_by_default(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(false);

        self::assertTrue($migration::dropDeadOpcionalColumns($db));

        $sql = implode("\n", $this->alterations($db->execStatements));
        self::assertStringContainsString('ALTER TABLE catalogo_opcionales DROP COLUMN en_catalogo', $sql);
        self::assertStringContainsString('ALTER TABLE catalogo_opcionales DROP COLUMN en_tarifa', $sql);
        self::assertNotContains('en_catalogo', $db->schema['catalogo_opcionales']);
        self::assertNotContains('en_tarifa', $db->schema['catalogo_opcionales']);
        self::assertContains('nombre', $db->schema['catalogo_opcionales'], 'the surviving columns are untouched');
    }

    public function test_dead_opcional_drop_never_touches_the_per_lista_price_flag(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $migration::dropDeadOpcionalColumns($db);

        $sql = implode("\n", $db->execStatements);
        self::assertStringNotContainsString('catalogo_opcional_precios', $sql, 'constraint 3: the price-inclusion flag stays');
        self::assertContains('en_catalogo', $db->schema['catalogo_opcional_precios']);
    }

    public function test_dead_opcional_drop_is_idempotent(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $migration::dropDeadOpcionalColumns($db);
        $before = count($db->execStatements);

        self::assertTrue($migration::dropDeadOpcionalColumns($db));
        self::assertSame($before, count($db->execStatements), 'a second run must be a no-op');
    }

    public function test_dead_opcional_columns_are_outside_the_car15_clause_2_table_list(): void
    {
        self::assertNotContains(
            'catalogo_opcionales',
            CaracteristicaColumnDropMigration::LEGACY_TABLES,
            'the dead-column cleanup is a dedicated gated step, not part of CAR-15 clause 2'
        );
    }

    public function test_clause_2_and_the_dead_column_step_record_their_documents(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/' . self::SERVICE_RELATIVE);

        self::assertStringContainsString('DEV-17', $source, 'clause 2 must record the membership-filter prerequisite');
        self::assertStringContainsString('tarif_tarifa_articulo.en_catalogo', $source);
        self::assertStringContainsString(
            'dropDeadOpcionalColumns',
            $source,
            'the dead-column cleanup must be a dedicated gated step'
        );
        self::assertStringContainsString(
            'catalogo_opcional_precios',
            $source,
            'constraint 3 (the per-lista price flag stays) must be recorded'
        );
    }

    // =====================================================================
    // Reversibility rests on the feature tables (never dropped)
    // =====================================================================

    public function test_no_clause_ever_drops_a_feature_value_column(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema() + [
            'catalogo_caracteristica_articulo' => ['codtarifa', 'referencia', 'id_caracteristica', 'id_valor', 'valor', 'custom'],
            'catalogo_caracteristica_familia' => ['codtarifa', 'codfamilia', 'id_caracteristica', 'id_valor', 'valor', 'custom'],
            'catalogo_caracteristica_global' => ['codtarifa', 'id_caracteristica', 'id_valor', 'valor', 'custom'],
        ]);
        $migration = $this->migrationWithLegacyReadExplicit(false);

        CaracteristicaColumnDropMigration::dropD12OpcionalColumns($db);
        $migration::dropLegacyArticleFamilyColumns($db);

        $sql = implode("\n", $db->execStatements);
        $this->assertStringNotContainsString('catalogo_caracteristica', $sql);
        $this->assertStringNotContainsString('DROP TABLE', $sql);

        foreach (['catalogo_caracteristica_articulo', 'catalogo_caracteristica_familia', 'catalogo_caracteristica_global'] as $table) {
            $this->assertContains('id_caracteristica', $db->schema[$table], 'the feature source of truth must survive');
        }
    }

    // =====================================================================
    // WU-7 7.3 — closed-loop operator entry point
    // =====================================================================

    public function test_post_soak_entry_point_refuses_while_the_legacy_path_is_explicitly_selected(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(true);

        $report = $migration::runPostSoak($db, true);

        self::assertSame('refused', $report['status']);
        self::assertTrue(
            $report['legacy_read_explicitly_enabled'],
            'the explicit opt-out is the first precondition'
        );
        self::assertSame([], $report['steps'], 'a refused gate must run no step');
        self::assertSame([], $db->execStatements, 'a refused gate must change no schema');
        self::assertStringContainsString('legacy', $report['reason']);
        self::assertStringContainsString('FS_CATALOGO_CARACTERISTICAS_READ_THROUGH', $report['reason']);
    }

    public function test_post_soak_entry_point_refuses_without_the_dev17_attestation(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $report = $migration::runPostSoak($db, false);

        self::assertSame('refused', $report['status']);
        self::assertFalse($report['legacy_read_explicitly_enabled']);
        self::assertFalse($report['dev17_membership_filters_rewritten']);
        self::assertSame([], $report['steps'], 'clause 2 must not run before the DEV-17 rewrite is attested');
        self::assertSame([], $db->execStatements, 'the DEV-17 guard refuses the whole loop, not just clause 2');
        self::assertStringContainsString('DEV-17', $report['reason']);
        self::assertSame(
            $this->fullSchema()['tarif_articulo_precios'],
            $db->schema['tarif_articulo_precios'],
            'the clause-2 columns must be untouched while the prerequisite is unattested'
        );
    }

    public function test_post_soak_entry_point_runs_the_three_clauses_in_the_declared_order(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $report = $migration::runPostSoak($db, true);

        self::assertSame('applied', $report['status']);
        self::assertSame(['clause1', 'clause2', 'dead_opcional'], array_keys($report['steps']));
        self::assertSame(
            ['clause1', 'clause2', 'dead_opcional'],
            array_keys(CaracteristicaColumnDropMigration::POST_SOAK_STEPS),
            'the ordered steps are a declared contract'
        );
        self::assertSame([true, true, true], array_values($report['steps']));

        $tables = $this->alteredTables($db);

        self::assertContains('tarif_opcional_ext', $tables, 'clause 1 must run');
        self::assertContains('tarif_articulo_precios', $tables, 'clause 2 must run');
        self::assertContains('catalogo_opcionales', $tables, 'the dead-column cleanup must run');

        $lastClause1 = max(
            $this->lastIndexOf($tables, CaracteristicaColumnDropMigration::D12_TABLES[0]),
            $this->lastIndexOf($tables, CaracteristicaColumnDropMigration::D12_TABLES[1])
        );
        $firstClause2 = min(
            $this->firstIndexOf($tables, 'tarif_articulo_precios'),
            $this->firstIndexOf($tables, 'tarif_tarifa_articulo'),
            $this->firstIndexOf($tables, 'tarif_tarifa_familia')
        );
        $firstDead = $this->firstIndexOf($tables, 'catalogo_opcionales');

        self::assertLessThan($firstClause2, $lastClause1, 'clause 1 runs before clause 2');
        self::assertLessThan($firstDead, $firstClause2, 'clause 2 runs before the dead-column cleanup');
    }

    public function test_post_soak_entry_point_is_idempotent(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $first = $migration::runPostSoak($db, true);
        $before = count($db->execStatements);

        $second = $migration::runPostSoak($db, true);

        self::assertSame('applied', $first['status']);
        self::assertSame('applied', $second['status']);
        self::assertSame($before, count($db->execStatements), 'a second closed loop must emit no ALTER');
        self::assertSame([], $second['dropped'], 'nothing is left to drop');
        self::assertSame([], $second['remaining']);
    }

    public function test_post_soak_entry_point_reports_the_schema_result_introspectively(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $expectedPending = [];
        foreach (CaracteristicaColumnDropMigration::D12_TABLES as $table) {
            $expectedPending[] = $table . '.en_catalogo';
            $expectedPending[] = $table . '.en_tarifa';
        }
        foreach (['tarif_articulo_precios', 'tarif_tarifa_articulo', 'tarif_tarifa_familia'] as $table) {
            $expectedPending[] = $table . '.en_catalogo';
            $expectedPending[] = $table . '.en_tarifa';
        }
        $expectedPending[] = 'catalogo_opcionales.en_catalogo';
        $expectedPending[] = 'catalogo_opcionales.en_tarifa';

        self::assertSame(
            $expectedPending,
            CaracteristicaColumnDropMigration::pendingVisibilityColumns($db),
            'the probe is introspective, ordered and scoped to the gated tables'
        );
        self::assertSame([], $db->execStatements, 'the introspection probe must emit no DDL');
        self::assertNotContains(
            'catalogo_opcional_precios.en_catalogo',
            CaracteristicaColumnDropMigration::pendingVisibilityColumns($db),
            'constraint 2: the per-lista price flag is never a drop candidate'
        );

        $report = $migration::runPostSoak($db, true);

        self::assertSame($expectedPending, $report['dropped']);
        self::assertSame([], $report['remaining'], 'the closed loop leaves no gated column behind');
    }

    public function test_post_soak_entry_point_reports_failure_when_an_alter_fails(): void
    {
        $db = new CaracteristicaColumnDropSpyDb($this->fullSchema());
        $db->execResult = false;
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $report = $migration::runPostSoak($db, true);

        self::assertSame('failed', $report['status']);
        self::assertFalse($report['steps']['clause1'], 'the failing clause must be reported');
        self::assertStringContainsString('clause1', $report['reason']);
    }

    public function test_the_entry_point_is_never_wired_into_a_boot_or_request_path(): void
    {
        $root = FS_FOLDER . '/plugins/catalogo_core';

        foreach (['Init.php'] as $relative) {
            $source = (string) file_get_contents($root . '/' . $relative);
            self::assertStringNotContainsString('runPostSoak', $source, $relative . ' must not call the closed loop');
        }

        foreach (['Controller', 'controller', 'extras', 'Event', 'View'] as $dir) {
            foreach ((array) glob($root . '/' . $dir . '/*.php') as $file) {
                self::assertStringNotContainsString(
                    'runPostSoak',
                    (string) file_get_contents($file),
                    $file . ' must not call the closed loop'
                );
            }
        }
    }

    public function test_the_entry_point_records_its_dev17_prerequisite_and_runbook(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/' . self::SERVICE_RELATIVE);

        self::assertStringContainsString('runPostSoak', $source);
        self::assertStringContainsString('POST_SOAK_STEPS', $source, 'the ordered steps are a declared contract');
        self::assertStringContainsString('DEV-17', $source);
        self::assertStringContainsString(
            'before clause 2',
            $source,
            'the ordered prerequisite must be recorded next to the entry point'
        );
        self::assertStringContainsString('pendingVisibilityColumns', $source);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * @return list<string> the altered tables in emission order
     */
    private function alteredTables(CaracteristicaColumnDropSpyDb $db): array
    {
        $tables = [];
        foreach ($this->alterations($db->execStatements) as $sql) {
            preg_match('/ALTER TABLE\s+(\S+)/i', $sql, $matches);
            $tables[] = $matches[1];
        }

        return $tables;
    }

    /**
     * Extracts one method body from a PHP source string, so the boot-wiring
     * contract is asserted on the real `Init` code without booting the plugin.
     */
    private function methodSource(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        self::assertNotFalse($start, 'missing method: ' . $signature);

        $open = (int) strpos($src, '{', (int) $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, (int) $start, $i - $start + 1);
                }
            }
        }

        self::fail('method body is not terminated: ' . $signature);
    }

    /** @param list<string> $tables */
    private function firstIndexOf(array $tables, string $table): int
    {
        $index = array_search($table, $tables, true);

        return $index === false ? PHP_INT_MAX : (int) $index;
    }

    /** @param list<string> $tables */
    private function lastIndexOf(array $tables, string $table): int
    {
        $index = array_keys($tables, $table, true);

        return $index === [] ? -1 : (int) max($index);
    }
}
