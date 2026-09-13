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

use FSFramework\Plugins\catalogo_core\Services\TarifOpcionalExtMigration;
use PHPUnit\Framework\TestCase;

/**
 * DB-free contract tests for the source-table resolution of the optional
 * extension migration. The source must be a table that actually carries the
 * legacy columns AND still has pending rows: an empty canonical table must not
 * shadow the populated legacy table.
 */
final class ExtMigrationFakeDb extends \fs_db2
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

        if (preg_match(
            '/FROM\s+([a-zA-Z0-9_]+)\s+o\s+LEFT JOIN\s+tarif_opcional_ext\s+e\s+ON\s+e\.id_opcional\s*=\s*o\.id/si',
            $sql,
            $m
        )) {
            $existing = array_column($this->tables['tarif_opcional_ext'] ?? [], 'id_opcional');
            foreach ($this->tables[$m[1]] ?? [] as $row) {
                if (!in_array($row['id'] ?? null, $existing, false)) {
                    return [['1' => 1]];
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

        if (preg_match(
            '/^INSERT IGNORE INTO tarif_opcional_ext[^(]*\(([^)]+)\)\s*SELECT\s+(.+?)\s+FROM\s+([a-zA-Z0-9_]+)\s+o\b/is',
            $sql,
            $m
        )) {
            foreach ($this->tables[$m[3]] ?? [] as $row) {
                $this->tables['tarif_opcional_ext'][] = [
                    'id_opcional' => $row['id'],
                    'ref_sap' => $row['ref_sap'] ?? null,
                    'en_catalogo' => $row['en_catalogo'] ?? 0,
                    'en_tarifa' => $row['en_tarifa'] ?? 0,
                ];
            }

            return true;
        }

        return true;
    }
}

final class TarifOpcionalExtMigrationCopyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/TarifOpcionalExtMigration.php';
    }

    public function test_populated_legacy_table_wins_over_empty_canonical(): void
    {
        $db = new ExtMigrationFakeDb(
            [
                'catalogo_opcionales' => [],
                'tarif_opcionales' => [
                    ['id' => 10, 'ref_sap' => 'SAP-10', 'en_catalogo' => 1, 'en_tarifa' => 1],
                    ['id' => 11, 'ref_sap' => 'SAP-11', 'en_catalogo' => 0, 'en_tarifa' => 0],
                ],
                'tarif_opcional_ext' => [],
            ],
            [
                'catalogo_opcionales' => ['id', 'codigo'],
                'tarif_opcionales' => ['id', 'ref_sap', 'en_catalogo', 'en_tarifa'],
                'tarif_opcional_ext' => ['id_opcional', 'ref_sap', 'en_catalogo', 'en_tarifa'],
            ]
        );

        TarifOpcionalExtMigration::migrateIfNeeded($db);

        $rows = $db->tables['tarif_opcional_ext'];
        $this->assertCount(2, $rows);
        $this->assertSame([10, 11], array_map('intval', array_column($rows, 'id_opcional')));
        $this->assertSame('SAP-10', $rows[0]['ref_sap']);
    }

    public function test_canonical_table_is_used_when_it_carries_legacy_columns(): void
    {
        $db = new ExtMigrationFakeDb(
            [
                'catalogo_opcionales' => [
                    ['id' => 7, 'ref_sap' => 'SAP-7', 'en_catalogo' => 1, 'en_tarifa' => 1],
                ],
                'tarif_opcionales' => [],
                'tarif_opcional_ext' => [],
            ],
            [
                'catalogo_opcionales' => ['id', 'codigo', 'ref_sap', 'en_catalogo', 'en_tarifa'],
                'tarif_opcionales' => ['id', 'ref_sap', 'en_catalogo', 'en_tarifa'],
                'tarif_opcional_ext' => ['id_opcional', 'ref_sap', 'en_catalogo', 'en_tarifa'],
            ]
        );

        TarifOpcionalExtMigration::migrateIfNeeded($db);

        $rows = $db->tables['tarif_opcional_ext'];
        $this->assertCount(1, $rows);
        $this->assertSame(7, (int) $rows[0]['id_opcional']);
    }

    public function test_steady_state_emits_no_insert(): void
    {
        $db = new ExtMigrationFakeDb(
            [
                'catalogo_opcionales' => [],
                'tarif_opcionales' => [
                    ['id' => 10, 'ref_sap' => 'SAP-10', 'en_catalogo' => 1, 'en_tarifa' => 1],
                ],
                'tarif_opcional_ext' => [
                    ['id_opcional' => 10, 'ref_sap' => 'SAP-10', 'en_catalogo' => 1, 'en_tarifa' => 1],
                ],
            ],
            [
                'catalogo_opcionales' => ['id', 'codigo'],
                'tarif_opcionales' => ['id', 'ref_sap', 'en_catalogo', 'en_tarifa'],
                'tarif_opcional_ext' => ['id_opcional', 'ref_sap', 'en_catalogo', 'en_tarifa'],
            ]
        );

        TarifOpcionalExtMigration::migrateIfNeeded($db);

        $inserts = array_values(array_filter(
            $db->executed,
            static fn (string $sql): bool => stripos($sql, 'tarif_opcional_ext') !== false
        ));
        $this->assertSame([], $inserts, 'the steady state must not emit any ext copy statement');
        $this->assertCount(1, $db->tables['tarif_opcional_ext']);
    }
}
