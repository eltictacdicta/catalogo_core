<?php
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\CatalogLegacyTableMigration;
use PHPUnit\Framework\TestCase;

/**
 * DB-free contract tests for the both-tables-exist copy path: when a legacy
 * table and its canonical counterpart coexist (the rename is impossible), the
 * migration must copy pending rows additively, preserve ids, and stay cheap in
 * steady state (no bulk INSERT when nothing is pending).
 *
 * The fake DB emulates only the statements the migration issues.
 */
final class CatalogMigrationFakeDb extends \fs_db2
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

        // Pending pre-check for the both-exist copy: legacy LEFT JOIN target on id.
        if (preg_match(
            '/FROM\s+([a-zA-Z0-9_]+)\s+l\s+LEFT JOIN\s+([a-zA-Z0-9_]+)\s+t\s+ON\s+t\.id\s*=\s*l\.id/si',
            $sql,
            $m
        )) {
            $targetIds = array_column($this->tables[$m[2]] ?? [], 'id');
            foreach ($this->tables[$m[1]] ?? [] as $row) {
                if (!in_array($row['id'] ?? null, $targetIds, false)) {
                    return [['1' => 1]];
                }
            }

            return [];
        }

        // Pending pre-check for optional prices: (id_opcional, codlista) join.
        if (preg_match('/FROM\s+tarif_opcional_precios\s+p\s+LEFT JOIN\s+catalogo_opcional_precios\s+c/si', $sql)) {
            $canonical = [];
            foreach ($this->tables['catalogo_opcional_precios'] ?? [] as $row) {
                $canonical[$row['id_opcional'] . '|' . $row['codlista']] = true;
            }
            foreach ($this->tables['tarif_opcional_precios'] ?? [] as $row) {
                if (!isset($canonical[$row['id_opcional'] . '|' . $row['codtarifa']])) {
                    return [['1' => 1]];
                }
            }

            return [];
        }

        // Pending pre-check for missing price lists.
        if (preg_match('/FROM\s+tarif_opcional_precios\s+p\s+LEFT JOIN\s+catalogo_listas_precio\s+l/si', $sql)) {
            $lists = array_map('strval', array_column($this->tables['catalogo_listas_precio'] ?? [], 'codlista'));
            foreach ($this->tables['tarif_opcional_precios'] ?? [] as $row) {
                if (!in_array((string) $row['codtarifa'], $lists, true)) {
                    return [['1' => 1]];
                }
            }

            return [];
        }

        if (stripos($sql, 'FROM tarif_tarifas') !== false
            && stripos($sql, 'por_defecto') !== false) {
            foreach ($this->tables['tarif_tarifas'] ?? [] as $row) {
                if (!empty($row['por_defecto'])) {
                    return [$row];
                }
            }

            return [];
        }

        if (stripos($sql, 'FROM catalogo_listas_precio') !== false
            && preg_match("/codlista = '([^']*)'/", $sql, $m)) {
            foreach ($this->tables['catalogo_listas_precio'] ?? [] as $row) {
                if ((string) ($row['codlista'] ?? '') === $m[1]) {
                    return [$row];
                }
            }

            return [];
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $sql = trim($sql);
        $this->executed[] = $sql;

        if (preg_match('/^RENAME TABLE `([^`]+)` TO `([^`]+)`/i', $sql, $m)) {
            if (isset($this->tables[$m[1]])) {
                $this->tables[$m[2]] = $this->tables[$m[1]];
                unset($this->tables[$m[1]]);
            }

            return true;
        }

        if (stripos($sql, 'INSERT IGNORE INTO catalogo_opcional_precios') === 0) {
            $canonical = [];
            foreach ($this->tables['catalogo_opcional_precios'] ?? [] as $row) {
                $canonical[$row['id_opcional'] . '|' . $row['codlista']] = true;
            }
            foreach ($this->tables['tarif_opcional_precios'] ?? [] as $row) {
                $key = $row['id_opcional'] . '|' . $row['codtarifa'];
                if (isset($canonical[$key])) {
                    continue;
                }
                $this->tables['catalogo_opcional_precios'][] = [
                    'id_opcional' => $row['id_opcional'],
                    'codlista' => $row['codtarifa'],
                    'precio' => $row['precio'],
                    'en_catalogo' => $row['en_catalogo'] ?? 0,
                ];
                $canonical[$key] = true;
            }

            return true;
        }

        if (preg_match(
            '/^INSERT IGNORE INTO\s+([a-zA-Z0-9_]+)\s*\(([^)]+)\)\s*SELECT\s+(.+?)\s+FROM\s+([a-zA-Z0-9_]+)(?:\s+\w+)?\s*;?$/is',
            $sql,
            $m
        ) && strpos($m[3], '(') === false) {
            $targetCols = array_map('trim', explode(',', $m[2]));
            $exprs = array_map('trim', explode(',', $m[3]));
            $existingIds = array_map('strval', array_column($this->tables[$m[1]] ?? [], 'id'));
            foreach ($this->tables[$m[4]] ?? [] as $row) {
                $new = [];
                foreach ($targetCols as $i => $col) {
                    $new[$col] = $this->evaluateExpr($exprs[$i] ?? 'NULL', $row);
                }
                if (isset($new['id']) && in_array((string) $new['id'], $existingIds, true)) {
                    continue;
                }
                if (isset($new['id'])) {
                    $existingIds[] = (string) $new['id'];
                }
                $this->tables[$m[1]][] = $new;
            }

            return true;
        }

        if (stripos($sql, 'INSERT INTO catalogo_listas_precio') === 0
            && preg_match('/\((.*?)\)\s*VALUES\s*\((.*)\)/is', $sql, $m)) {
            $cols = array_map('trim', explode(',', $m[1]));
            $vals = array_map(static fn ($v) => trim(trim($v), "'"), explode(',', $m[2]));
            $this->tables['catalogo_listas_precio'][] = array_combine($cols, $vals);

            return true;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $row
     * @return mixed
     */
    private function evaluateExpr(string $expr, array $row)
    {
        $expr = trim($expr);
        if ($expr === '0' || strcasecmp($expr, 'false') === 0) {
            return 0;
        }
        if (preg_match('/^[a-z_][a-z0-9_]*$/i', $expr)) {
            return $row[$expr] ?? null;
        }

        return trim($expr, "'");
    }
}

final class CatalogLegacyTableMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogLegacyTableMigration.php';
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function baseTables(): array
    {
        return [
            'tarif_opcionales' => [
                ['id' => 10, 'codigo' => 'OPC-A', 'nombre' => 'Opcional A', 'descripcion' => null, 'precio' => 1.5, 'activo' => 1],
                ['id' => 11, 'codigo' => 'OPC-B', 'nombre' => 'Opcional B', 'descripcion' => null, 'precio' => 2.0, 'activo' => 0],
            ],
            'catalogo_opcionales' => [],
            'tarif_opcional_familia' => [
                ['id' => 100, 'id_opcional' => 10, 'codfamilia' => 'FAM1'],
                ['id' => 101, 'id_opcional' => 11, 'codfamilia' => 'FAM1'],
            ],
            'catalogo_opcional_familias' => [],
            'tarif_articulo_opcional' => [
                ['id' => 200, 'referencia' => 'ART1', 'id_opcional' => 10],
            ],
            'catalogo_articulo_opcional' => [],
            'tarif_opcional_precios' => [
                ['id_opcional' => 10, 'codtarifa' => 'DEF', 'precio' => 1.5, 'en_catalogo' => 1],
                ['id_opcional' => 11, 'codtarifa' => 'DEF', 'precio' => 2.0, 'en_catalogo' => 0],
            ],
            'catalogo_opcional_precios' => [],
            'catalogo_listas_precio' => [
                ['codlista' => 'DEF', 'nombre' => 'Default', 'activa' => 1, 'por_defecto' => 1, 'coddivisa' => 'EUR'],
            ],
            'catalogo_opcional_grupos' => [['id' => 1]],
            'catalogo_articulo_opcional_grupo' => [['id' => 1]],
            'tarif_tarifas' => [
                ['codtarifa' => 'DEF', 'nombre' => 'Default', 'activa' => 1, 'por_defecto' => 1, 'coddivisa' => 'EUR'],
            ],
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function baseColumns(): array
    {
        return [
            'catalogo_opcionales' => ['tipo_precio', 'porcentaje', 'id_grupo'],
            'catalogo_opcional_precios' => ['porcentaje'],
            'catalogo_articulo_opcional' => ['obligatorio'],
            'catalogo_articulo_opcional_grupo' => ['obligatorio'],
        ];
    }

    private function freshDb(): CatalogMigrationFakeDb
    {
        return new CatalogMigrationFakeDb($this->baseTables(), $this->baseColumns());
    }

    private function executedContaining(CatalogMigrationFakeDb $db, string $needle): array
    {
        return array_values(array_filter(
            $db->executed,
            static fn (string $sql): bool => stripos($sql, $needle) !== false
        ));
    }

    public function test_both_exist_copies_pending_opcionales_preserving_ids(): void
    {
        $db = $this->freshDb();

        CatalogLegacyTableMigration::migrateIfNeeded($db);

        $ids = array_column($db->tables['catalogo_opcionales'], 'id');
        $this->assertSame([10, 11], array_map('intval', $ids));
        $this->assertSame('OPC-A', $db->tables['catalogo_opcionales'][0]['codigo']);
        $this->assertArrayNotHasKey(
            'tipo_precio',
            $db->tables['catalogo_opcionales'][0],
            'the copy must not invent pricing-mode columns; defaults are left untouched'
        );
        $this->assertNotEmpty(
            $this->executedContaining($db, 'INSERT IGNORE INTO catalogo_opcionales'),
            'a bulk copy must be emitted when legacy rows are pending'
        );
    }

    public function test_both_exist_copies_pending_familias_and_articulos(): void
    {
        $db = $this->freshDb();

        CatalogLegacyTableMigration::migrateIfNeeded($db);

        $familias = $db->tables['catalogo_opcional_familias'];
        $this->assertSame([100, 101], array_map('intval', array_column($familias, 'id')));

        $articulos = $db->tables['catalogo_articulo_opcional'];
        $this->assertSame([200], array_map('intval', array_column($articulos, 'id')));
        $this->assertSame(0, $articulos[0]['obligatorio'], 'obligatorio must default to 0');
    }

    public function test_steady_state_emits_no_bulk_copy(): void
    {
        $db = $this->freshDb();

        CatalogLegacyTableMigration::migrateIfNeeded($db);
        $db->executed = [];

        CatalogLegacyTableMigration::migrateIfNeeded($db);

        $this->assertSame(
            [],
            $this->executedContaining($db, 'INSERT IGNORE INTO catalogo_opcionales'),
            'the second run must skip the bulk copy when nothing is pending'
        );
        $this->assertSame(
            [],
            $this->executedContaining($db, 'INSERT IGNORE INTO catalogo_opcional_familias'),
            'the second run must skip the familias bulk copy'
        );
        $this->assertSame(
            [],
            $this->executedContaining($db, 'INSERT IGNORE INTO catalogo_articulo_opcional ('),
            'the second run must skip the articulo_opcional bulk copy'
        );
    }

    public function test_partial_target_only_copies_missing_ids(): void
    {
        $tables = $this->baseTables();
        $tables['catalogo_opcionales'] = [
            ['id' => 10, 'codigo' => 'OPC-A', 'nombre' => 'Opcional A', 'descripcion' => null, 'precio' => 1.5, 'activo' => 1],
        ];
        $db = new CatalogMigrationFakeDb($tables, $this->baseColumns());

        CatalogLegacyTableMigration::migrateIfNeeded($db);

        $this->assertSame([10, 11], array_map('intval', array_column($db->tables['catalogo_opcionales'], 'id')));
    }

    public function test_both_exist_copies_pending_prices_mapping_codtarifa_to_codlista(): void
    {
        $db = $this->freshDb();

        CatalogLegacyTableMigration::migrateIfNeeded($db);

        $rows = $db->tables['catalogo_opcional_precios'];
        $this->assertCount(2, $rows);
        $this->assertSame('DEF', $rows[0]['codlista']);
        $this->assertSame(1.5, $rows[0]['precio']);
        $this->assertArrayNotHasKey('porcentaje', $rows[0], 'porcentaje must stay NULL');

        $inserts = $this->executedContaining($db, 'INSERT IGNORE INTO catalogo_opcional_precios');
        $this->assertNotEmpty($inserts);
        $this->assertStringContainsString('codtarifa', $inserts[0]);
    }

    public function test_missing_price_list_is_created_from_tarif_tarifas(): void
    {
        $tables = $this->baseTables();
        $tables['tarif_opcional_precios'] = [
            ['id_opcional' => 10, 'codtarifa' => 'MAYOR', 'precio' => 1.5, 'en_catalogo' => 1],
        ];
        $tables['catalogo_listas_precio'] = [];
        $db = new CatalogMigrationFakeDb($tables, $this->baseColumns());

        CatalogLegacyTableMigration::migrateIfNeeded($db);

        $sqls = $this->executedContaining($db, 'catalogo_listas_precio');
        $this->assertNotEmpty($sqls, 'the migration must ensure the referenced price lists exist');
    }
}
