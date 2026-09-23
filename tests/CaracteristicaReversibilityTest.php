<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Services\CaracteristicaColumnDropMigration;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver;
use PHPUnit\Framework\TestCase;

/**
 * CAR-15 "Reversibility from feature values" (WU-7 7.1b).
 *
 * The spec scenario:
 *
 *   GIVEN dropped columns and intact feature values
 *   WHEN nullable columns are re-added and re-derived
 *   THEN every previously stored legacy value is reproduced
 *
 * The proof has three parts, and only the middle one is non-trivial:
 *
 *  1. every clause drops **only** the legacy visibility columns and never
 *     touches the feature tables (the source of truth);
 *  2. the feature tables alone reproduce **every** legacy value: the real
 *     `CaracteristicaResolver` walks the in-memory feature state and the
 *     result is compared, key by key and tarifa by tarifa, against a pre-drop
 *     snapshot of the legacy representation;
 *  3. the physical reversal restores the pre-drop column set, and the restored
 *     columns come back **NULL** — so part 2 cannot be an artefact of reading
 *     the restored column: the values can only come from the feature tables.
 *
 * Scope of the pre-drop snapshot: the legacy representation of the *derived*
 * visibility — what the soak materialises through the CAR-13 backfill / CAR-14
 * dual-write / DEV-18 mirror, and what CAR-12's parity test gates. This test
 * proves the derivation is reproducible from the feature store; it does not
 * re-litigate the D12 semantics (that is CAR-12's parity gate).
 *
 * DB-free by construction: no `fs_db2` is ever connected (the in-memory probe
 * double skips `parent::__construct()`), so nothing needs cleaning up. That is
 * deliberate: `fs_db2::exec()` auto-commits, so a synthetic write cannot be
 * rolled back and must never be used as test scaffolding.
 */
final class ReversibilitySpyDb extends \fs_db2
{
    /** @var list<string> */
    public array $execStatements = [];

    /** @var list<string> */
    public array $selectStatements = [];

    /** @var list<string> every re-added column, as `table.column => DDL fragment` */
    public array $addedColumns = [];

    /** @var array<string, null> table.column => the restored value (always NULL) */
    public array $restoredValues = [];

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

        if (preg_match('/ALTER TABLE\s+(\S+)\s+DROP COLUMN\s+([A-Za-z0-9_]+)\s*;?\s*$/i', $sql, $matches)) {
            $table = $matches[1];
            $column = $matches[2];
            $this->schema[$table] = array_values(array_filter(
                $this->schema[$table] ?? [],
                static fn (string $name): bool => $name !== $column
            ));

            return true;
        }

        if (preg_match('/ALTER TABLE\s+(\S+)\s+ADD COLUMN\s+([A-Za-z0-9_]+)\s+([^;]*);?\s*$/i', $sql, $matches)) {
            $table = $matches[1];
            $column = $matches[2];
            $this->schema[$table][] = $column;
            $this->addedColumns[$table . '.' . $column] = trim($matches[3]);
            // A dropped flag comes back as an empty (NULL) column: the legacy
            // representation is rebuilt, its value is not recovered from it.
            $this->restoredValues[$table . '.' . $column] = null;

            return true;
        }

        return true;
    }
}

/**
 * In-memory scope table. Mirrors the shape the real scope models expose to the
 * resolver (`get($codtarifa, array $key, $idCaracteristica)` + `key_columns()`).
 */
final class ReversibilityScopeModel
{
    /** @var array<string, object> */
    public array $rows = [];

    public $codtarifa = null;
    public $referencia = null;
    public $codfamilia = null;
    public $id_caracteristica = null;
    public $id_valor = null;
    public $valor = null;
    public $custom = false;

    /** @param list<string> $keyColumns */
    public function __construct(private array $keyColumns)
    {
    }

    /** @return list<string> */
    public function key_columns(): array
    {
        return $this->keyColumns;
    }

    /**
     * @param array<string, mixed> $key
     * @return object|false
     */
    public function get($codtarifa, $key, $idCaracteristica)
    {
        $key = is_array($key) ? $key : [];

        return $this->rows[$this->rowKey((string) $codtarifa, $key, (int) $idCaracteristica)] ?? false;
    }

    public function save(): bool
    {
        $key = [];
        foreach ($this->keyColumns as $column) {
            $key[$column] = $this->{$column};
        }

        $row = [
            'codtarifa' => $this->codtarifa,
            'id_caracteristica' => $this->id_caracteristica,
            'id_valor' => $this->id_valor,
            'valor' => $this->valor,
            'custom' => $this->custom,
        ];
        foreach ($key as $column => $value) {
            $row[$column] = $value;
        }

        $this->rows[$this->rowKey((string) $this->codtarifa, $key, (int) $this->id_caracteristica)] = (object) $row;

        return true;
    }

    public function delete(): bool
    {
        return true;
    }

    /** @param array<string, mixed> $key */
    private function rowKey(string $codtarifa, array $key, int $idCaracteristica): string
    {
        $parts = [$codtarifa, (string) $idCaracteristica];
        foreach ($this->keyColumns as $column) {
            $parts[] = (string) ($key[$column] ?? '');
        }

        return implode('|', $parts);
    }
}

/**
 * The "intact feature values" the scenario starts from: one scope model per
 * scope, the bool catalog, the two definitions and the D12 parent discovery.
 */
final class ReversibilityFixture
{
    /** @var array<string, ReversibilityScopeModel> */
    public array $scopes = [];

    /** @var array<int, string> id_valor => valor */
    public array $catalog = [1 => '1', 2 => '0'];

    /** @var array<int, array{referencias: list<string>, familias: list<string>}> */
    public array $opcionalParents = [];

    public const DEFINITIONS = [
        'en_catalogo' => ['id' => 10, 'codigo' => 'en_catalogo', 'tipo' => 'bool', 'activo' => true, 'valor_defecto' => null],
        'en_tarifa' => ['id' => 11, 'codigo' => 'en_tarifa', 'tipo' => 'bool', 'activo' => true, 'valor_defecto' => null],
    ];

    public function scope(string $scope): ReversibilityScopeModel
    {
        if (!isset($this->scopes[$scope])) {
            $keys = match ($scope) {
                CaracteristicaResolver::SCOPE_ARTICULO => ['referencia'],
                CaracteristicaResolver::SCOPE_FAMILIA => ['codfamilia'],
                default => [],
            };
            $this->scopes[$scope] = new ReversibilityScopeModel($keys);
        }

        return $this->scopes[$scope];
    }
}

/**
 * The real resolver over the in-memory state: the derivation under test is the
 * production scope walk, not a re-implementation.
 */
final class ReversibilityResolver extends CaracteristicaResolver
{
    public function __construct(private ReversibilityFixture $fixture)
    {
    }

    public function definitions(bool $onlyActive = true): array
    {
        return ReversibilityFixture::DEFINITIONS;
    }

    protected function scope_model(string $scope)
    {
        return $this->fixture->scope($scope);
    }

    protected function catalog_value(int $idValor): ?string
    {
        return $this->fixture->catalog[$idValor] ?? null;
    }

    protected function familia_madre(string $codfamilia): ?string
    {
        return null;
    }

    protected function articulo_family_lookup(string $referencia): ?string
    {
        return null;
    }

    protected function opcional_parents(array $id_opcional): array
    {
        $parents = [];
        foreach ($id_opcional as $id) {
            $parents[(int) $id] = $this->fixture->opcionalParents[(int) $id]
                ?? ['referencias' => [], 'familias' => []];
        }

        return $parents;
    }
}

final class CaracteristicaReversibilityTest extends TestCase
{
    private const SERVICE_RELATIVE = 'plugins/catalogo_core/Services/CaracteristicaColumnDropMigration.php';

    private static bool $baseLoaded = false;

    private ReversibilityFixture $fixture;

    private ReversibilityResolver $resolver;

    /** @var array<string, list<string>> */
    private array $preDropSchema = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (self::$baseLoaded) {
            return;
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/base/fs_app.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaConfig.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaResolver.php';
        require_once FS_FOLDER . '/' . self::SERVICE_RELATIVE;
        self::$baseLoaded = true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixture = new ReversibilityFixture();
        $this->resolver = new ReversibilityResolver($this->fixture);
        $this->preDropSchema = $this->legacySchema();
        $this->seedFeatureValues();
    }

    // =====================================================================
    // The pre-drop legacy representation + the intact feature values
    // =====================================================================

    /**
     * The deployed schema before any clause runs. `tarif_familia_ext` is the
     * retired read-only surface: it never had the visibility columns
     * (divergence 1), so it has nothing to restore.
     *
     * @return array<string, list<string>>
     */
    private function legacySchema(): array
    {
        return [
            'tarif_opcional_ext' => ['id_opcional', 'ref_sap', 'en_catalogo', 'en_tarifa'],
            'tarif_tarifa_opcional' => ['codtarifa', 'id_opcional', 'en_catalogo', 'en_tarifa', 'activa', 'orden'],
            'tarif_articulo_precios' => ['referencia', 'codtarifa', 'precio', 'activo', 'en_catalogo', 'en_tarifa'],
            'tarif_tarifa_articulo' => ['codtarifa', 'referencia', 'orden', 'en_catalogo', 'en_tarifa'],
            'tarif_tarifa_familia' => ['codtarifa', 'codfamilia', 'activa', 'orden', 'en_catalogo', 'en_tarifa'],
            'tarif_familia_ext' => ['codfamilia', 'capitulo', 'nivel'],
            'catalogo_opcionales' => ['id', 'codigo', 'nombre', 'activo', 'en_catalogo', 'en_tarifa'],
            'catalogo_opcional_precios' => ['id', 'id_opcional', 'codtarifa', 'precio', 'en_catalogo'],
            'catalogo_caracteristica_articulo' => ['codtarifa', 'referencia', 'id_caracteristica', 'id_valor', 'valor', 'custom'],
            'catalogo_caracteristica_familia' => ['codtarifa', 'codfamilia', 'id_caracteristica', 'id_valor', 'valor', 'custom'],
            'catalogo_caracteristica_global' => ['codtarifa', 'id_caracteristica', 'id_valor', 'valor', 'custom'],
        ];
    }

    /**
     * Pre-drop snapshot of the legacy representation, per surface:
     * `[table, tarifa, key, en_catalogo, en_tarifa]`.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: bool, 4: bool}>
     */
    private function articleLegacySnapshot(): array
    {
        return [
            ['tarif_articulo_precios', 'DEF', 'REF-1', true, false],
            ['tarif_tarifa_articulo', 'T1', 'REF-2', false, true],
        ];
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: bool, 4: bool}>
     */
    private function familyLegacySnapshot(): array
    {
        return [
            ['tarif_tarifa_familia', 'DEF', 'F1', true, true],
            ['tarif_tarifa_familia', 'T1', 'F2', false, false],
        ];
    }

    /**
     * D12 pre-drop snapshot: the per-tarifa visibility the soak materialised on
     * `tarif_opcional_ext` / `tarif_tarifa_opcional` for each opcional.
     *
     * @return list<array{0: int, 1: string, 2: bool, 3: bool}>
     */
    private function opcionalLegacySnapshot(): array
    {
        return [
            [1, 'T1', true, false],
            [2, 'DEF', true, true],
            [3, 'T1', true, false],
        ];
    }

    /**
     * The intact feature values behind every snapshot entry.
     *
     * Article scope rows are per tarifa (CAR-13 (a)/(b)); family scope rows are
     * per tarifa (CAR-13 (c)); the unassigned opcional derives from the global
     * scope (CAR-13 (e3)).
     */
    private function seedFeatureValues(): void
    {
        $this->seedFeature(CaracteristicaResolver::SCOPE_ARTICULO, 'DEF', ['referencia' => 'REF-1'], 10, 1);
        $this->seedFeature(CaracteristicaResolver::SCOPE_ARTICULO, 'DEF', ['referencia' => 'REF-1'], 11, 2);

        $this->seedFeature(CaracteristicaResolver::SCOPE_ARTICULO, 'T1', ['referencia' => 'REF-2'], 10, 2);
        $this->seedFeature(CaracteristicaResolver::SCOPE_ARTICULO, 'T1', ['referencia' => 'REF-2'], 11, 1);

        $this->seedFeature(CaracteristicaResolver::SCOPE_FAMILIA, 'DEF', ['codfamilia' => 'F1'], 10, 1);
        $this->seedFeature(CaracteristicaResolver::SCOPE_FAMILIA, 'DEF', ['codfamilia' => 'F1'], 11, 1);

        $this->seedFeature(CaracteristicaResolver::SCOPE_FAMILIA, 'T1', ['codfamilia' => 'F2'], 10, 2);
        $this->seedFeature(CaracteristicaResolver::SCOPE_FAMILIA, 'T1', ['codfamilia' => 'F2'], 11, 2);

        $this->seedFeature(CaracteristicaResolver::SCOPE_GLOBAL, 'DEF', [], 10, 1);
        $this->seedFeature(CaracteristicaResolver::SCOPE_GLOBAL, 'DEF', [], 11, 2);

        $this->fixture->opcionalParents = [
            1 => ['referencias' => ['REF-1'], 'familias' => []],
            2 => ['referencias' => [], 'familias' => ['F1']],
            3 => ['referencias' => [], 'familias' => []],
        ];
    }

    /** @param array<string, string> $key */
    private function seedFeature(string $scope, string $codtarifa, array $key, int $idCaracteristica, int $idValor): void
    {
        $model = $this->fixture->scope($scope);
        $model->codtarifa = $codtarifa;
        $model->referencia = $key['referencia'] ?? null;
        $model->codfamilia = $key['codfamilia'] ?? null;
        $model->id_caracteristica = $idCaracteristica;
        $model->id_valor = $idValor;
        $model->valor = null;
        $model->custom = false;
        $model->save();
    }

    private function featureTablesSurvive(ReversibilitySpyDb $db): bool
    {
        $expected = [
            'catalogo_caracteristica_articulo' => ['codtarifa', 'referencia', 'id_caracteristica', 'id_valor', 'valor', 'custom'],
            'catalogo_caracteristica_familia' => ['codtarifa', 'codfamilia', 'id_caracteristica', 'id_valor', 'valor', 'custom'],
            'catalogo_caracteristica_global' => ['codtarifa', 'id_caracteristica', 'id_valor', 'valor', 'custom'],
        ];

        foreach ($expected as $table => $columns) {
            if ($db->schema[$table] !== $columns) {
                return false;
            }
        }

        return true;
    }

    /** @param list<string> $tables */
    private function restoreNullableLegacyColumns(ReversibilitySpyDb $db, array $tables): void
    {
        foreach ($tables as $table) {
            foreach (CaracteristicaColumnDropMigration::FLAGS as $column) {
                if (CaracteristicaColumnDropMigration::hasColumn($db, $table, $column)) {
                    continue;
                }

                // The documented reversal statement: re-add the column as
                // nullable, then re-derive its value from the feature tables.
                $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' TINYINT NULL;');
            }
        }
    }

    // =====================================================================
    // Clause 1 — D12 opcional flags
    // =====================================================================

    public function test_clause_1_drop_leaves_the_feature_tables_intact(): void
    {
        $db = new ReversibilitySpyDb($this->preDropSchema);
        $migration = $this->migrationWithLegacyReadExplicit(false);

        self::assertTrue($migration::dropD12OpcionalColumns($db));

        self::assertStringNotContainsString(
            'catalogo_caracteristica',
            implode("\n", $db->execStatements),
            'no clause may touch the source of truth'
        );
        self::assertNotContains('en_catalogo', $db->schema['tarif_opcional_ext']);
        self::assertNotContains('en_tarifa', $db->schema['tarif_tarifa_opcional']);
    }

    public function test_clause_1_reversal_reproduces_every_pre_drop_opcional_value(): void
    {
        $snapshot = $this->opcionalLegacySnapshot();
        $db = new ReversibilitySpyDb($this->preDropSchema);
        $migration = $this->migrationWithLegacyReadExplicit(false);

        // Sanity: the snapshot is the state the feature tables already derive.
        $this->assertDerivesEveryOpcionalValue($snapshot, 'before the drop');

        $migration::dropD12OpcionalColumns($db);
        $this->restoreNullableLegacyColumns($db, CaracteristicaColumnDropMigration::D12_TABLES);

        $this->assertDerivesEveryOpcionalValue($snapshot, 'after the drop and the nullable restore');
        self::assertSame(
            [
                'tarif_opcional_ext.en_catalogo',
                'tarif_opcional_ext.en_tarifa',
                'tarif_tarifa_opcional.en_catalogo',
                'tarif_tarifa_opcional.en_tarifa',
            ],
            array_keys($db->addedColumns),
            'the reversal restores exactly the four opcional flags'
        );
    }

    public function test_clause_1_reversal_values_can_only_come_from_the_feature_tables(): void
    {
        $snapshot = $this->opcionalLegacySnapshot();
        $db = new ReversibilitySpyDb($this->preDropSchema);
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $migration::dropD12OpcionalColumns($db);
        $this->restoreNullableLegacyColumns($db, CaracteristicaColumnDropMigration::D12_TABLES);

        foreach ($db->restoredValues as $column => $value) {
            self::assertNull($value, $column . ' must come back NULL: the value is re-derived, not restored');
        }

        $this->assertDerivesEveryOpcionalValue($snapshot, 'with the restored columns empty');
    }

    // =====================================================================
    // Clause 2 — legacy article/family flags
    // =====================================================================

    public function test_clause_2_drop_leaves_the_feature_tables_intact(): void
    {
        $db = new ReversibilitySpyDb($this->preDropSchema);
        $migration = $this->migrationWithLegacyReadExplicit(false);

        self::assertTrue($migration::dropLegacyArticleFamilyColumns($db));

        self::assertStringNotContainsString('catalogo_caracteristica', implode("\n", $db->execStatements));
        self::assertNotContains('en_catalogo', $db->schema['tarif_articulo_precios']);
        self::assertNotContains('en_tarifa', $db->schema['tarif_tarifa_articulo']);
        self::assertNotContains('en_tarifa', $db->schema['tarif_tarifa_familia']);
    }

    public function test_clause_2_reversal_reproduces_every_pre_drop_article_and_family_value(): void
    {
        $articles = $this->articleLegacySnapshot();
        $families = $this->familyLegacySnapshot();
        $db = new ReversibilitySpyDb($this->preDropSchema);
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $this->assertDerivesEveryArticleValue($articles, 'before the drop');
        $this->assertDerivesEveryFamilyValue($families, 'before the drop');

        $migration::dropLegacyArticleFamilyColumns($db);
        $this->restoreNullableLegacyColumns($db, [
            'tarif_articulo_precios',
            'tarif_tarifa_articulo',
            'tarif_tarifa_familia',
        ]);

        $this->assertDerivesEveryArticleValue($articles, 'after the drop and the nullable restore');
        $this->assertDerivesEveryFamilyValue($families, 'after the drop and the nullable restore');
    }

    public function test_clause_2_reversal_restores_the_pre_drop_column_set(): void
    {
        $db = new ReversibilitySpyDb($this->preDropSchema);
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $migration::dropLegacyArticleFamilyColumns($db);
        $this->restoreNullableLegacyColumns($db, [
            'tarif_articulo_precios',
            'tarif_tarifa_articulo',
            'tarif_tarifa_familia',
        ]);

        foreach (['tarif_articulo_precios', 'tarif_tarifa_articulo', 'tarif_tarifa_familia'] as $table) {
            $expected = $this->preDropSchema[$table];
            $actual = $db->schema[$table];
            sort($expected);
            sort($actual);

            self::assertSame($expected, $actual, $table . ' must carry the same column set as before the drop');
        }

        foreach ($db->addedColumns as $column => $ddl) {
            self::assertStringContainsString('NULL', strtoupper($ddl), $column . ' must be re-added as nullable');
            self::assertStringNotContainsString(
                'NOT NULL',
                strtoupper($ddl),
                $column . ' must not be re-added as non-nullable'
            );
        }

        self::assertTrue($this->featureTablesSurvive($db), 'no feature-table column may be part of the drop');
    }

    public function test_clause_2_reversal_needs_no_restore_for_the_retired_familia_ext_table(): void
    {
        $db = new ReversibilitySpyDb($this->preDropSchema);
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $migration::dropLegacyArticleFamilyColumns($db);

        self::assertStringNotContainsString(
            'tarif_familia_ext',
            implode("\n", $db->execStatements),
            'divergence 1: the retired table is an introspection-guarded no-op'
        );
        self::assertFalse(
            CaracteristicaColumnDropMigration::hasColumn($db, 'tarif_familia_ext', 'en_catalogo'),
            'there is no column to drop and therefore none to restore'
        );
        self::assertSame(
            $this->preDropSchema['tarif_familia_ext'],
            $db->schema['tarif_familia_ext'],
            'the family visibility parity rides tarif_tarifa_familia plus the resolver DEF probe'
        );
    }

    // =====================================================================
    // Dead columns — catalogo_opcionales
    // =====================================================================

    public function test_dead_opcional_reversal_is_shape_level_and_loses_no_derived_visibility(): void
    {
        $snapshot = $this->opcionalLegacySnapshot();
        $db = new ReversibilitySpyDb($this->preDropSchema);
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $migration::dropDeadOpcionalColumns($db);

        self::assertNotContains('en_catalogo', $db->schema['catalogo_opcionales']);
        self::assertNotContains('en_tarifa', $db->schema['catalogo_opcionales']);
        self::assertContains(
            'en_catalogo',
            $db->schema['catalogo_opcional_precios'],
            'constraint 2: the per-lista price inclusion flag stays'
        );
        self::assertStringNotContainsString('catalogo_opcional_precios', implode("\n", $db->execStatements));

        // The dead columns never fed the derivation: nothing was lost.
        $this->assertDerivesEveryOpcionalValue($snapshot, 'after the dead-column drop');

        $this->restoreNullableLegacyColumns($db, CaracteristicaColumnDropMigration::DEAD_OPCIONAL_TABLES);
        $this->assertDerivesEveryOpcionalValue($snapshot, 'after restoring the dead columns as nullable');
    }

    // =====================================================================
    // The full closed loop
    // =====================================================================

    public function test_the_closed_loop_reproduces_the_whole_pre_drop_snapshot(): void
    {
        $snapshot = $this->snapshotMap();
        $db = new ReversibilitySpyDb($this->preDropSchema);
        $migration = $this->migrationWithLegacyReadExplicit(false);

        $before = CaracteristicaColumnDropMigration::pendingVisibilityColumns($db);
        self::assertNotSame([], $before, 'the pre-drop schema must still carry the gated columns');

        self::assertTrue($migration::dropD12OpcionalColumns($db));
        self::assertTrue($migration::dropLegacyArticleFamilyColumns($db));
        self::assertTrue($migration::dropDeadOpcionalColumns($db));

        self::assertSame([], CaracteristicaColumnDropMigration::pendingVisibilityColumns($db));

        // Re-add the whole gated surface as nullable and re-derive.
        $this->restoreNullableLegacyColumns(
            $db,
            array_merge(
                CaracteristicaColumnDropMigration::D12_TABLES,
                ['tarif_articulo_precios', 'tarif_tarifa_articulo', 'tarif_tarifa_familia'],
                CaracteristicaColumnDropMigration::DEAD_OPCIONAL_TABLES
            )
        );

        self::assertSame($before, CaracteristicaColumnDropMigration::pendingVisibilityColumns($db));
        self::assertSame($snapshot, $this->derivedSnapshot(), 'every previously stored legacy value is reproduced');
        self::assertTrue($this->featureTablesSurvive($db), 'the source of truth was never dropped');
    }

    public function test_the_legacy_opt_out_blocks_every_gated_clause(): void
    {
        $snapshot = $this->snapshotMap();
        $db = new ReversibilitySpyDb($this->preDropSchema);
        $migration = $this->migrationWithLegacyReadExplicit(true);

        // The opt-out is the emergency rollback to the legacy reads: while it is
        // set, every gated clause refuses and changes no schema — clause 1
        // included, because the opcional flags are still part of that legacy
        // representation.
        self::assertFalse($migration::dropD12OpcionalColumns($db));
        self::assertFalse($migration::dropLegacyArticleFamilyColumns($db));
        self::assertFalse($migration::dropDeadOpcionalColumns($db));

        self::assertSame([], $db->execStatements, 'a refused clause must change no schema');
        self::assertContains('en_catalogo', $db->schema['tarif_opcional_ext']);
        self::assertContains('en_tarifa', $db->schema['tarif_tarifa_opcional']);
        self::assertContains('en_catalogo', $db->schema['tarif_articulo_precios']);
        self::assertContains('en_tarifa', $db->schema['tarif_tarifa_familia']);
        self::assertContains('en_catalogo', $db->schema['catalogo_opcionales']);
        self::assertContains(
            'en_catalogo',
            $db->schema['catalogo_opcional_precios'],
            'constraint 2: the per-lista price flag is untouched by every clause'
        );

        // A refusal changes no data either: the derivation stays exact.
        self::assertSame($snapshot, $this->derivedSnapshot());
    }

    // =====================================================================
    // Assertion helpers
    // =====================================================================

    /**
     * @return array<string, bool>
     */
    private function snapshotMap(): array
    {
        $map = [];
        foreach ($this->opcionalLegacySnapshot() as [$id, $tarifa, $enCatalogo, $enTarifa]) {
            $map['opcional:' . $id . '@' . $tarifa . ':en_catalogo'] = $enCatalogo;
            $map['opcional:' . $id . '@' . $tarifa . ':en_tarifa'] = $enTarifa;
        }
        foreach ($this->articleLegacySnapshot() as [$table, $tarifa, $referencia, $enCatalogo, $enTarifa]) {
            $map[$table . '@' . $tarifa . ':' . $referencia . ':en_catalogo'] = $enCatalogo;
            $map[$table . '@' . $tarifa . ':' . $referencia . ':en_tarifa'] = $enTarifa;
        }
        foreach ($this->familyLegacySnapshot() as [$table, $tarifa, $codfamilia, $enCatalogo, $enTarifa]) {
            $map[$table . '@' . $tarifa . ':' . $codfamilia . ':en_catalogo'] = $enCatalogo;
            $map[$table . '@' . $tarifa . ':' . $codfamilia . ':en_tarifa'] = $enTarifa;
        }

        return $map;
    }

    /**
     * The same map, but every value re-derived from the feature tables alone.
     * Values stay un-cast so a `null` (no value) can never masquerade as
     * `false` under `assertSame`.
     *
     * @return array<string, bool|null>
     */
    private function derivedSnapshot(): array
    {
        $map = [];
        foreach ($this->opcionalLegacySnapshot() as [$id, $tarifa]) {
            $map['opcional:' . $id . '@' . $tarifa . ':en_catalogo'] = $this->resolver
                ->resolve_opcional_visibility($id, $tarifa, 'en_catalogo');
            $map['opcional:' . $id . '@' . $tarifa . ':en_tarifa'] = $this->resolver
                ->resolve_opcional_visibility($id, $tarifa, 'en_tarifa');
        }
        foreach ($this->articleLegacySnapshot() as [$table, $tarifa, $referencia]) {
            $map[$table . '@' . $tarifa . ':' . $referencia . ':en_catalogo'] = $this->resolver
                ->resolve_bool('en_catalogo', $tarifa, $referencia);
            $map[$table . '@' . $tarifa . ':' . $referencia . ':en_tarifa'] = $this->resolver
                ->resolve_bool('en_tarifa', $tarifa, $referencia);
        }
        foreach ($this->familyLegacySnapshot() as [$table, $tarifa, $codfamilia]) {
            $map[$table . '@' . $tarifa . ':' . $codfamilia . ':en_catalogo'] = $this->resolver
                ->resolve_bool('en_catalogo', $tarifa, null, $codfamilia);
            $map[$table . '@' . $tarifa . ':' . $codfamilia . ':en_tarifa'] = $this->resolver
                ->resolve_bool('en_tarifa', $tarifa, null, $codfamilia);
        }

        return $map;
    }

    /** @param list<array{0: int, 1: string, 2: bool, 3: bool}> $snapshot */
    private function assertDerivesEveryOpcionalValue(array $snapshot, string $phase): void
    {
        foreach ($snapshot as [$id, $tarifa, $enCatalogo, $enTarifa]) {
            self::assertNotNull(
                $this->resolver->resolve_opcional_visibility($id, $tarifa, 'en_catalogo'),
                'opcional ' . $id . ' must carry a defined value ' . $phase
            );
            self::assertSame(
                $enCatalogo,
                $this->resolver->resolve_opcional_visibility($id, $tarifa, 'en_catalogo'),
                'opcional ' . $id . ' en_catalogo ' . $phase
            );
            self::assertSame(
                $enTarifa,
                $this->resolver->resolve_opcional_visibility($id, $tarifa, 'en_tarifa'),
                'opcional ' . $id . ' en_tarifa ' . $phase
            );
        }
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: bool, 4: bool}> $snapshot */
    private function assertDerivesEveryArticleValue(array $snapshot, string $phase): void
    {
        foreach ($snapshot as [$table, $tarifa, $referencia, $enCatalogo, $enTarifa]) {
            self::assertNotNull(
                $this->resolver->resolve_bool('en_catalogo', $tarifa, $referencia),
                $table . ' ' . $referencia . ' must carry a defined value ' . $phase
            );
            self::assertSame(
                $enCatalogo,
                $this->resolver->resolve_bool('en_catalogo', $tarifa, $referencia),
                $table . ' ' . $referencia . ' en_catalogo ' . $phase
            );
            self::assertSame(
                $enTarifa,
                $this->resolver->resolve_bool('en_tarifa', $tarifa, $referencia),
                $table . ' ' . $referencia . ' en_tarifa ' . $phase
            );
        }
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: bool, 4: bool}> $snapshot */
    private function assertDerivesEveryFamilyValue(array $snapshot, string $phase): void
    {
        foreach ($snapshot as [$table, $tarifa, $codfamilia, $enCatalogo, $enTarifa]) {
            self::assertNotNull(
                $this->resolver->resolve_bool('en_catalogo', $tarifa, null, $codfamilia),
                $table . ' ' . $codfamilia . ' must carry a defined value ' . $phase
            );
            self::assertSame(
                $enCatalogo,
                $this->resolver->resolve_bool('en_catalogo', $tarifa, null, $codfamilia),
                $table . ' ' . $codfamilia . ' en_catalogo ' . $phase
            );
            self::assertSame(
                $enTarifa,
                $this->resolver->resolve_bool('en_tarifa', $tarifa, null, $codfamilia),
                $table . ' ' . $codfamilia . ' en_tarifa ' . $phase
            );
        }
    }

    /**
     * The legacy opt-out seam: TRUE means the legacy path was explicitly
     * selected (the gated clauses refuse); FALSE is the feature default.
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
}
