<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Services\CaracteristicaBackfillMigration;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the legacy visibility backfill (CAR-13).
 *
 * DB-free: a `\fs_db2` fake answers the driver-aware introspection queries,
 * the definition/value lookups and the pending probes, and executes the
 * `INSERT … WHERE NOT EXISTS` statements against in-memory tables. That makes
 * idempotency, operator-edit preservation and opcional recovery observable as
 * real row state instead of SQL-string assertions.
 */
final class BackfillFakeDb extends \fs_db2
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables = [];

    /** @var array<string, list<string>> */
    public array $columns = [];

    /** @var list<string> */
    public array $executed = [];

    /**
     * @param array<string, list<array<string, mixed>>> $tables
     * @param array<string, list<string>> $columns
     */
    public function __construct(array $tables = [], array $columns = [])
    {
        // Deliberately skip parent::__construct(): no engine, no DB connection.
        $this->tables = $tables;
        $this->columns = $columns;
    }

    public function var2str($val)
    {
        if ($val === null) {
            return 'NULL';
        }
        if (is_bool($val)) {
            return $val ? '1' : '0';
        }
        if (is_int($val) || is_float($val)) {
            return (string) $val;
        }

        return "'" . addslashes((string) $val) . "'";
    }

    public function select($sql, $params = [])
    {
        $sql = trim($sql);

        if (preg_match("/^SHOW TABLES LIKE '(.+?)';?$/i", $sql, $m)) {
            return array_key_exists($m[1], $this->tables) ? [['Tables_in_db' => $m[1]]] : [];
        }

        if (preg_match('/^SHOW COLUMNS FROM `?([a-zA-Z0-9_]+)`? LIKE \'(.+?)\';?$/i', $sql, $m)) {
            return in_array($m[2], $this->columns[$m[1]] ?? [], true) ? [['Field' => $m[2]]] : [];
        }

        if (preg_match("/^SELECT id FROM catalogo_caracteristicas WHERE codigo = '(.+?)' LIMIT 1;?$/i", $sql, $m)) {
            foreach ($this->tables['catalogo_caracteristicas'] ?? [] as $row) {
                if ((string) $row['codigo'] === $m[1]) {
                    return [['id' => (int) $row['id']]];
                }
            }

            return [];
        }

        if (preg_match(
            "/^SELECT id FROM catalogo_caracteristica_valores WHERE id_caracteristica = (\d+) AND valor = '(.+?)' LIMIT 1;?$/i",
            $sql,
            $m
        )) {
            foreach ($this->tables['catalogo_caracteristica_valores'] ?? [] as $row) {
                if ((int) $row['id_caracteristica'] === (int) $m[1] && (string) $row['valor'] === $m[2]) {
                    return [['id' => (int) $row['id']]];
                }
            }

            return [];
        }

        if (preg_match('/^SELECT 1 FROM (.+?) WHERE (.+?) LIMIT 1;?$/is', $sql, $m)) {
            return $this->probe_is_pending($m[1], $m[2]) ? [['1' => 1]] : [];
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $this->executed[] = trim($sql);
        $this->apply_insert(trim($sql));

        return true;
    }

    // =====================================================================
    // Probe evaluation (mirrors the production LEFT JOIN / NOT EXISTS shapes)
    // =====================================================================

    private function probe_is_pending(string $from, string $where): bool
    {
        $flag = $this->flag_of($where);
        $guard = $this->guard_of($where);
        if ($flag === null || $guard === null) {
            return false;
        }

        foreach ($this->source_rows($from) as $row) {
            if (!$this->row_passes_guards($row, $where, $flag)) {
                continue;
            }
            if ($this->target_has($guard, $this->tarifa_of($row, $guard['tarifa']), $row)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function apply_insert(string $sql): void
    {
        if (!preg_match('/^INSERT INTO ([a-zA-Z0-9_]+) \(([^)]+)\)\s*SELECT (.+?) FROM (.+?) WHERE (.+?);?$/is', $sql, $m)) {
            return;
        }

        $target = $m[1];
        $columns = array_map('trim', explode(',', $m[2]));
        $select = $m[3];
        $from = $m[4];
        $where = $m[5];

        $flag = $this->flag_of($where);
        $guard = $this->guard_of($where);
        if ($flag === null || $guard === null || !preg_match('/CASE WHEN \w+\.\w+ THEN (\d+) ELSE (\d+) END/i', $select, $c)) {
            return;
        }
        $one = (int) $c[1];
        $zero = (int) $c[2];

        $keyColumn = ($columns[0] === 'codtarifa' && ($columns[1] ?? '') !== 'id_caracteristica')
            ? $columns[1]
            : null;

        foreach ($this->source_rows($from) as $row) {
            if (!$this->row_passes_guards($row, $where, $flag)) {
                continue;
            }

            $tarifa = $this->tarifa_of($row, $guard['tarifa']);
            if ($this->target_has($guard, $tarifa, $row)) {
                continue;
            }

            $insert = [
                'codtarifa' => $tarifa,
                'id_caracteristica' => $guard['id'],
                'id_valor' => (($row[$flag] ?? false) ? $one : $zero),
                'valor' => null,
                'custom' => false,
            ];
            if ($keyColumn !== null) {
                $insert[$keyColumn] = $row[$keyColumn];
            }

            $this->tables[$target][] = $insert;
        }
    }

    private function flag_of(string $where): ?string
    {
        return preg_match('/\w+\.(en_catalogo|en_tarifa) IS NOT NULL/i', $where, $m) ? $m[1] : null;
    }

    /**
     * @return array{target: string, key: string, id: int, tarifa: string}|null
     */
    private function guard_of(string $where): ?array
    {
        if (preg_match(
            '/NOT EXISTS \(SELECT 1 FROM ([a-zA-Z0-9_]+) c WHERE c\.codtarifa = (.+?) AND c\.(\w+) = [\w.]+ AND c\.id_caracteristica = (\d+)\)/is',
            $where,
            $m
        )) {
            return ['target' => $m[1], 'key' => $m[3], 'id' => (int) $m[4], 'tarifa' => trim($m[2])];
        }

        if (preg_match(
            '/NOT EXISTS \(SELECT 1 FROM ([a-zA-Z0-9_]+) c WHERE c\.codtarifa = (.+?) AND c\.id_caracteristica = (\d+)\)/is',
            $where,
            $g
        )) {
            return ['target' => $g[1], 'key' => '', 'id' => (int) $g[3], 'tarifa' => trim($g[2])];
        }

        return null;
    }

    /**
     * @param array{target: string, key: string, id: int, tarifa: string} $guard
     * @param array<string, mixed> $row
     */
    private function target_has(array $guard, string $tarifa, array $row): bool
    {
        foreach ($this->tables[$guard['target']] ?? [] as $existing) {
            if ((string) $existing['codtarifa'] !== (string) $tarifa) {
                continue;
            }
            if ((int) $existing['id_caracteristica'] !== $guard['id']) {
                continue;
            }
            if ($guard['key'] !== '' && (string) $existing[$guard['key']] !== (string) $row[$guard['key']]) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function tarifa_of(array $row, string $tarifaExpr): string
    {
        $tarifaExpr = trim($tarifaExpr);
        if (str_starts_with($tarifaExpr, "'")) {
            return trim($tarifaExpr, "'");
        }

        $column = str_contains($tarifaExpr, '.') ? explode('.', $tarifaExpr)[1] : $tarifaExpr;

        return (string) ($row[$column] ?? '');
    }

    /**
     * @param array<string, mixed> $row
     */
    private function row_passes_guards(array $row, string $where, string $flag): bool
    {
        if (($row[$flag] ?? null) === null) {
            return false;
        }

        if (str_contains($where, 'tarif_articulo_precios pr')) {
            foreach ($this->tables['tarif_articulo_precios'] ?? [] as $price) {
                if ((string) $price['referencia'] === (string) $row['referencia']
                    && (string) $price['codtarifa'] === (string) $row['codtarifa']) {
                    return false;
                }
            }
        }

        if (str_contains($where, 'catalogo_articulo_opcional ao')) {
            foreach ($this->tables['catalogo_articulo_opcional'] ?? [] as $attached) {
                if ((int) $attached['id_opcional'] === (int) $row['id_opcional']) {
                    return false;
                }
            }
        }

        if (str_contains($where, 'catalogo_opcional_familia ofam')) {
            foreach ($this->tables['catalogo_opcional_familia'] ?? [] as $assigned) {
                if ((int) $assigned['id_opcional'] === (int) $row['id_opcional']) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function source_rows(string $from): array
    {
        $from = trim($from);
        if (!preg_match(
            '/^([a-zA-Z0-9_]+) (\w+)(?:\s+INNER JOIN ([a-zA-Z0-9_]+) (\w+) ON \4\.id_opcional = \2\.id_opcional)?$/is',
            $from,
            $m
        )) {
            return [];
        }

        $base = $this->tables[$m[1]] ?? [];
        if (empty($m[3])) {
            return $base;
        }

        $joined = $this->tables[$m[3]] ?? [];
        $rows = [];
        foreach ($base as $extRow) {
            foreach ($joined as $parentRow) {
                if ((int) $parentRow['id_opcional'] !== (int) $extRow['id_opcional']) {
                    continue;
                }
                $rows[] = array_merge($extRow, $parentRow);
            }
        }

        return $rows;
    }
}

final class CaracteristicaBackfillTest extends TestCase
{
    private const INIT = 'plugins/catalogo_core/Init.php';

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaBackfillMigration.php';
    }

    private function baseTables(): array
    {
        return [
            'catalogo_caracteristicas' => [
                ['id' => 10, 'codigo' => 'en_catalogo'],
                ['id' => 11, 'codigo' => 'en_tarifa'],
            ],
            'catalogo_caracteristica_valores' => [
                ['id' => 101, 'id_caracteristica' => 10, 'valor' => '1'],
                ['id' => 102, 'id_caracteristica' => 10, 'valor' => '0'],
                ['id' => 111, 'id_caracteristica' => 11, 'valor' => '1'],
                ['id' => 112, 'id_caracteristica' => 11, 'valor' => '0'],
            ],
            'catalogo_caracteristica_articulo' => [],
            'catalogo_caracteristica_familia' => [],
            'catalogo_caracteristica_global' => [],
        ];
    }

    private function baseColumns(): array
    {
        return [
            'tarif_articulo_precios' => ['referencia', 'codtarifa', 'en_catalogo', 'en_tarifa'],
            'tarif_tarifa_articulo' => ['referencia', 'codtarifa', 'en_catalogo', 'en_tarifa'],
            'tarif_tarifa_familia' => ['codfamilia', 'codtarifa', 'en_catalogo', 'en_tarifa'],
            'tarif_familia_ext' => ['codfamilia', 'capitulo', 'nivel'],
            'tarif_opcional_ext' => ['id_opcional', 'en_catalogo', 'en_tarifa'],
            'catalogo_articulo_opcional' => ['referencia', 'id_opcional'],
            'catalogo_opcional_familia' => ['codfamilia', 'id_opcional'],
        ];
    }

    private function newDb(array $tables, ?array $columns = null): BackfillFakeDb
    {
        return new BackfillFakeDb($tables, $columns ?? $this->baseColumns());
    }

    private function executes(BackfillFakeDb $db): array
    {
        return array_values(array_filter(
            $db->executed,
            static fn (string $sql): bool => stripos($sql, 'INSERT INTO') === 0
        ));
    }

    // =====================================================================
    // CAR-13 — legacy surfaces map into feature values
    // =====================================================================

    public function test_backfill_maps_article_and_family_legacy_surfaces(): void
    {
        $tables = $this->baseTables();
        $tables['tarif_articulo_precios'] = [
            ['referencia' => 'A1', 'codtarifa' => 'T1', 'en_catalogo' => 1, 'en_tarifa' => 1],
            ['referencia' => 'A2', 'codtarifa' => 'T1', 'en_catalogo' => 0, 'en_tarifa' => null],
        ];
        // A3 has no price row: the tarif_tarifa_articulo flag must map instead.
        $tables['tarif_tarifa_articulo'] = [
            ['codtarifa' => 'T1', 'referencia' => 'A3', 'en_catalogo' => 1, 'en_tarifa' => 0],
            ['codtarifa' => 'T1', 'referencia' => 'A1', 'en_catalogo' => 0, 'en_tarifa' => 0],
        ];
        $tables['tarif_tarifa_familia'] = [
            ['codtarifa' => 'T1', 'codfamilia' => 'F1', 'en_catalogo' => 1, 'en_tarifa' => 0],
        ];

        $db = $this->newDb($tables);
        CaracteristicaBackfillMigration::migrateIfNeeded($db);

        $articulo = $db->tables['catalogo_caracteristica_articulo'];
        $this->assertSame(101, $this->valueFor($articulo, 'A1', 'T1', 10), 'A1 en_catalogo TRUE maps to the "1" value');
        $this->assertSame(111, $this->valueFor($articulo, 'A1', 'T1', 11), 'A1 en_tarifa TRUE maps to the "1" value');
        $this->assertSame(102, $this->valueFor($articulo, 'A2', 'T1', 10), 'a stored FALSE maps to the "0" value');
        $this->assertNull($this->valueFor($articulo, 'A2', 'T1', 11), 'a NULL legacy flag is not materialized');
        $this->assertSame(101, $this->valueFor($articulo, 'A3', 'T1', 10), 'the tarifa-article flag maps when the price row is absent');

        $familia = $db->tables['catalogo_caracteristica_familia'];
        $this->assertCount(2, $familia, 'both legacy flags materialize for the same family key');
        $this->assertSame('F1', $familia[0]['codfamilia']);
        $this->assertSame(101, $this->valueFor($familia, 'F1', 'T1', 10), 'en_catalogo TRUE maps to the "1" value');
        $this->assertSame(112, $this->valueFor($familia, 'F1', 'T1', 11), 'a stored FALSE family flag maps to the "0" value');
    }

    public function test_tarif_familia_ext_without_flags_is_an_introspection_guarded_noop(): void
    {
        $tables = $this->baseTables();
        $tables['tarif_familia_ext'] = [['codfamilia' => 'F1', 'capitulo' => '1', 'nivel' => '']];

        $db = $this->newDb($tables);
        CaracteristicaBackfillMigration::migrateIfNeeded($db);

        foreach ($db->executed as $sql) {
            $this->assertStringNotContainsString(
                'tarif_familia_ext',
                $sql,
                'the retired table must never be read for its missing visibility columns'
            );
        }
        $this->assertSame([], $db->tables['catalogo_caracteristica_familia'], 'no family row is invented');
    }

    public function test_double_run_is_a_noop(): void
    {
        $tables = $this->baseTables();
        $tables['tarif_articulo_precios'] = [
            ['referencia' => 'A1', 'codtarifa' => 'T1', 'en_catalogo' => 1, 'en_tarifa' => 0],
        ];

        $db = $this->newDb($tables);
        CaracteristicaBackfillMigration::migrateIfNeeded($db);
        $firstInserts = count($this->executes($db));
        $firstState = $db->tables['catalogo_caracteristica_articulo'];

        CaracteristicaBackfillMigration::migrateIfNeeded($db);

        $this->assertGreaterThan(0, $firstInserts, 'the first run must map the legacy row');
        $this->assertSame($firstInserts, count($this->executes($db)), 'the second run must emit no insert');
        $this->assertSame($firstState, $db->tables['catalogo_caracteristica_articulo'], 'the value tables are unchanged');
    }

    public function test_operator_edits_are_never_overwritten(): void
    {
        $tables = $this->baseTables();
        $tables['tarif_articulo_precios'] = [
            ['referencia' => 'A1', 'codtarifa' => 'T1', 'en_catalogo' => 1, 'en_tarifa' => 0],
        ];
        // The operator already materialized a custom value for the same key.
        $tables['catalogo_caracteristica_articulo'] = [
            ['codtarifa' => 'T1', 'referencia' => 'A1', 'id_caracteristica' => 10, 'id_valor' => null, 'valor' => 'OPERADOR', 'custom' => true],
        ];

        $db = $this->newDb($tables);
        CaracteristicaBackfillMigration::migrateIfNeeded($db);

        $operator = array_values(array_filter(
            $db->tables['catalogo_caracteristica_articulo'],
            static fn (array $r): bool => (int) $r['id_caracteristica'] === 10
        ));
        $this->assertCount(1, $operator, 'the backfill must not add a second row for the same key');
        $this->assertSame('OPERADOR', (string) $operator[0]['valor'], 'the operator value survives the backfill');
    }

    public function test_legacy_opcional_flags_are_recovered_through_parents(): void
    {
        $tables = $this->baseTables();
        $tables['tarif_opcional_ext'] = [
            ['id_opcional' => 1, 'en_catalogo' => 1, 'en_tarifa' => 0],
        ];
        $tables['catalogo_articulo_opcional'] = [
            ['id_opcional' => 1, 'referencia' => 'A9'],
        ];

        $db = $this->newDb($tables);
        CaracteristicaBackfillMigration::migrateIfNeeded($db);

        $rows = $db->tables['catalogo_caracteristica_articulo'];
        $this->assertSame(101, $this->valueFor($rows, 'A9', 'DEF', 10), 'the parent article is seeded for DEF with TRUE');
    }

    public function test_absent_definition_returns_early(): void
    {
        $tables = $this->baseTables();
        // Remove the en_tarifa definition: the backfill must not run at all.
        $tables['catalogo_caracteristicas'] = [
            ['id' => 10, 'codigo' => 'en_catalogo'],
        ];
        $tables['tarif_articulo_precios'] = [
            ['referencia' => 'A1', 'codtarifa' => 'T1', 'en_catalogo' => 1, 'en_tarifa' => 1],
        ];

        $db = $this->newDb($tables);
        CaracteristicaBackfillMigration::migrateIfNeeded($db);

        $this->assertSame([], $this->executes($db), 'a missing definition must short-circuit the whole backfill');
    }

    public function test_init_wires_the_backfill_into_init_and_upgrade(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . '/' . self::INIT);

        $this->assertSame(
            2,
            substr_count($src, 'CaracteristicaBackfillMigration::migrateIfNeeded'),
            'the backfill must run from both init() and upgrade()'
        );
        $this->assertStringContainsString(
            'plugins/catalogo_core/Services/CaracteristicaBackfillMigration.php',
            $src,
            'Init must require the backfill service'
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function valueFor(array $rows, string $key, string $tarifa, int $idCaracteristica): ?int
    {
        foreach ($rows as $row) {
            if ((string) ($row['referencia'] ?? $row['codfamilia'] ?? '') !== $key) {
                continue;
            }
            if ((string) $row['codtarifa'] !== $tarifa) {
                continue;
            }
            if ((int) $row['id_caracteristica'] !== $idCaracteristica) {
                continue;
            }

            return $row['id_valor'] === null ? null : (int) $row['id_valor'];
        }

        return null;
    }
}
