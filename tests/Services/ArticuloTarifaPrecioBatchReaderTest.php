<?php
declare(strict_types=1);
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

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\ArticuloTarifaPrecioBatchReader;
use PHPUnit\Framework\TestCase;

/**
 * ALC-02 / AD-W3-4 contract for the per-tarifa article price batch reader.
 *
 * DB-free: the model is an anonymous subclass that skips the fs_model
 * constructor and injects a recording `select()` engine, so the tests prove
 * "one query per page" and the ALC-02 defaults without a live database.
 */
final class ArticuloTarifaPrecioBatchReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_articulo_precio.php';
    }

    /**
     * Recording engine: returns the configured rows and counts the selects.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function recordingDb(array $rows): object
    {
        return new class($rows) {
            public int $selectCalls = 0;
            public string $lastSql = '';

            /** @var array<int, array<string, mixed>> */
            private array $rows;

            /** @param array<int, array<string, mixed>> $rows */
            public function __construct(array $rows)
            {
                $this->rows = $rows;
            }

            public function select(string $sql): array
            {
                $this->selectCalls++;
                $this->lastSql = $sql;

                return $this->rows;
            }

            public function var2str($val): string
            {
                if ($val === null) {
                    return 'NULL';
                }
                if (is_bool($val)) {
                    return $val ? 'TRUE' : 'FALSE';
                }
                if (is_int($val) || is_float($val)) {
                    return (string) $val;
                }

                return "'" . str_replace("'", "''", (string) $val) . "'";
            }
        };
    }

    private function model(object $db): object
    {
        return new class($db) extends \FSFramework\model\tarif_articulo_precio {
            public function __construct($db)
            {
                $this->table_name = 'tarif_articulo_precios';
                $this->db = $db;
            }
        };
    }

    private function reader(object $model): ArticuloTarifaPrecioBatchReader
    {
        return new class($model) extends ArticuloTarifaPrecioBatchReader {
            public function __construct(private object $model)
            {
            }

            protected function precio_model()
            {
                return $this->model;
            }
        };
    }

    public function test_single_query_maps_all_referencias(): void
    {
        $db = $this->recordingDb([
            ['referencia' => 'A', 'codtarifa' => 'T1', 'precio' => '10.5', 'activo' => '1', 'en_tarifa' => '1', 'en_catalogo' => '0'],
            ['referencia' => 'B', 'codtarifa' => 'T1', 'precio' => 3, 'activo' => 0, 'en_tarifa' => 1, 'en_catalogo' => 1],
        ]);

        $map = $this->reader($this->model($db))->for_referencias(['A', 'B', 'C'], 'T1');

        $this->assertSame(1, $db->selectCalls, 'N rows must never produce N queries (ALC-02/AD-W3-4)');
        $this->assertStringContainsString('codtarifa', $db->lastSql);
        $this->assertStringContainsString('IN (', $db->lastSql, 'the batch must filter with a single IN (...) list');
        $this->assertStringContainsString("'A'", $db->lastSql, 'requested refs must be quoted through var2str');

        $this->assertSame(10.5, $map['A']['precio']);
        $this->assertTrue($map['A']['activo']);
        $this->assertTrue($map['A']['en_tarifa']);
        $this->assertFalse($map['A']['en_catalogo']);

        $this->assertSame(3.0, $map['B']['precio']);
        $this->assertFalse($map['B']['activo']);
        $this->assertTrue($map['B']['en_tarifa']);
        $this->assertTrue($map['B']['en_catalogo']);
    }

    public function test_missing_row_defaults_to_0_true_false_false(): void
    {
        $db = $this->recordingDb([]);

        $map = $this->reader($this->model($db))->for_referencias(['A'], 'T1');

        $this->assertArrayHasKey('A', $map);
        $this->assertSame(
            ['precio' => 0.0, 'activo' => true, 'en_tarifa' => false, 'en_catalogo' => false],
            $map['A'],
            'a missing per-tarifa row must resolve to the ALC-02 defaults'
        );
    }

    public function test_empty_referencias_makes_no_query(): void
    {
        $db = $this->recordingDb([]);

        $map = $this->reader($this->model($db))->for_referencias([], 'T1');

        $this->assertSame([], $map);
        $this->assertSame(0, $db->selectCalls, 'an empty page must not hit the database');
    }
}
