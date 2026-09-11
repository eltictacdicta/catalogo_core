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

namespace Tests\CatalogoCore;

use FSFramework\Plugins\catalogo_core\Services\CatalogLegacyTableMigration;
use PHPUnit\Framework\TestCase;

/**
 * D3 price unification acceptance (spec "Legacy prices are unified once"):
 * with only legacy `tarif_opcional_precios` rows present, the migration bridge
 * copies them into the canonical `catalogo_opcional_precios` table mapping
 * `codtarifa -> codlista`, creates the matching default price list, and the
 * second run is a no-op. The adapter must read the canonical table.
 *
 * The fake DB emulates only the statements the migration issues so the test
 * stays DB-free and deterministic.
 */
final class PriceUnificationFakeDb extends \fs_db2
{
    /** @var array<string, list<array<string, mixed>>> */
    public array $tables = [];

    /** @var list<string> */
    public array $executed = [];

    public function __construct(array $tables = [])
    {
        // Deliberately skip parent::__construct(): no engine, no DB connection.
        $this->tables = $tables;
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

        if (stripos($sql, 'SHOW COLUMNS') === 0) {
            return [];
        }

        if (stripos($sql, 'FROM tarif_tarifas') !== false) {
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

        if (preg_match('/FROM\s+([a-zA-Z0-9_]+)/', $sql, $m)) {
            return [['total' => (string) count($this->tables[$m[1]] ?? [])]];
        }

        return [];
    }

    public function exec($sql, $transaction = null, $params = [], $batch = false)
    {
        $sql = trim($sql);
        $this->executed[] = $sql;

        if (preg_match(
            '/^CREATE TABLE catalogo_opcional_precios\s+SELECT\s+id_opcional,\s*codtarifa AS codlista,\s*precio,\s*en_catalogo\s+FROM tarif_opcional_precios/i',
            $sql
        )) {
            $rows = [];
            foreach ($this->tables['tarif_opcional_precios'] ?? [] as $row) {
                $rows[] = [
                    'id_opcional' => $row['id_opcional'],
                    'codlista' => $row['codtarifa'],
                    'precio' => $row['precio'],
                    'en_catalogo' => $row['en_catalogo'],
                ];
            }
            $this->tables['catalogo_opcional_precios'] = $rows;

            return true;
        }

        if (stripos($sql, 'ALTER TABLE') === 0) {
            return true;
        }

        if (preg_match('/^RENAME TABLE `([^`]+)` TO `([^`]+)`/i', $sql, $m)) {
            if (isset($this->tables[$m[1]])) {
                $this->tables[$m[2]] = $this->tables[$m[1]];
                unset($this->tables[$m[1]]);
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
}

final class OpcionalPriceUnificationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogLegacyTableMigration.php';
    }

    private function freshDb(): PriceUnificationFakeDb
    {
        return new PriceUnificationFakeDb([
            'tarif_opcional_precios' => [
                ['id_opcional' => 1, 'codtarifa' => 'DEF', 'precio' => 10.0, 'en_catalogo' => 1],
                ['id_opcional' => 2, 'codtarifa' => 'DEF', 'precio' => 5.5, 'en_catalogo' => 0],
            ],
            'tarif_tarifas' => [
                [
                    'codtarifa' => 'DEF',
                    'nombre' => 'Default',
                    'activa' => 1,
                    'por_defecto' => 1,
                    'coddivisa' => 'EUR',
                ],
            ],
            'catalogo_listas_precio' => [],
            'catalogo_opcional_grupos' => [['id' => 1]],
            'catalogo_articulo_opcional_grupo' => [['id' => 1]],
        ]);
    }

    public function test_migration_maps_legacy_codtarifa_rows_to_canonical_codlista(): void
    {
        $db = $this->freshDb();

        CatalogLegacyTableMigration::migrateIfNeeded($db);

        $this->assertArrayHasKey(
            'catalogo_opcional_precios',
            $db->tables,
            'migrateIfNeeded must create the canonical price table from the legacy rows'
        );
        $this->assertSame('DEF', $db->tables['catalogo_opcional_precios'][0]['codlista']);
        $this->assertSame(10.0, $db->tables['catalogo_opcional_precios'][0]['precio']);
        $this->assertArrayNotHasKey(
            'codtarifa',
            $db->tables['catalogo_opcional_precios'][0],
            'the canonical row must key on codlista, not codtarifa'
        );
    }

    public function test_migration_creates_matching_default_price_list(): void
    {
        $db = $this->freshDb();

        CatalogLegacyTableMigration::migrateIfNeeded($db);

        $codlistas = array_column($db->tables['catalogo_listas_precio'] ?? [], 'codlista');
        $this->assertContains('DEF', $codlistas, 'the default tarifa must get a matching catalogo price list');
    }

    public function test_second_migration_run_is_a_noop(): void
    {
        $db = $this->freshDb();

        CatalogLegacyTableMigration::migrateIfNeeded($db);
        $firstPriceRows = count($db->tables['catalogo_opcional_precios']);
        $firstLists = count($db->tables['catalogo_listas_precio']);
        $db->executed = [];

        CatalogLegacyTableMigration::migrateIfNeeded($db);

        $this->assertSame($firstPriceRows, count($db->tables['catalogo_opcional_precios']));
        $this->assertSame($firstLists, count($db->tables['catalogo_listas_precio']));

        foreach ($db->executed as $sql) {
            $this->assertStringNotContainsString(
                'CREATE TABLE catalogo_opcional_precios',
                $sql,
                'the second run must not recreate the canonical table'
            );
            $this->assertStringNotContainsString(
                'INSERT INTO catalogo_listas_precio',
                $sql,
                'the second run must not duplicate the default price list'
            );
        }
    }

    public function test_adapter_reads_the_unified_canonical_table(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional_precio.php';

        $this->assertTrue(
            is_subclass_of(
                \FSFramework\model\tarif_opcional_precio::class,
                \FSFramework\model\catalogo_opcional_precio::class
            ),
            'tarif_opcional_precio must extend the canonical catalogo_opcional_precio (design D3)'
        );
    }
}
