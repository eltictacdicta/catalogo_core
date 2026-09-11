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

use PHPUnit\Framework\TestCase;

/**
 * History page opcionales mode (spec "History page renders opcionales mode"):
 * the tarifario controller serves `tipo=opcionales` from the catalogo_core
 * history model, and that model exposes the date-filtered list, counters,
 * statistics and purge the page consumes without fatal when the table is
 * absent.
 */
final class TarifHistorialPreciosControllerTest extends TestCase
{
    private const CONTROLLER_RELATIVE = 'plugins/tarifario/controller/tarif_historial_precios.php';
    private const HISTORY_RELATIVE = 'plugins/catalogo_core/model/tarif_opcional_precio_historial.php';

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/' . self::HISTORY_RELATIVE;
    }

    private function makeModel(bool $tableExists): object
    {
        return new class($tableExists) extends \FSFramework\model\tarif_opcional_precio_historial {
            public $db;

            public function __construct(bool $tableExists = true)
            {
                // Skip parent::__construct(): inject a DB-free fake instead.
                $this->table_name = 'tarif_opcional_precio_historial';
                $this->db = new class($tableExists) {
                    public bool $tableExists;

                    /** @var list<string> */
                    public array $executed = [];

                    public function __construct(bool $tableExists)
                    {
                        $this->tableExists = $tableExists;
                    }

                    public function table_exists($name, $list = false)
                    {
                        return $this->tableExists;
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
                        return [];
                    }

                    public function select_limit($sql, $limit = 50, $offset = 0, $params = [])
                    {
                        return [];
                    }

                    public function exec($sql, $transaction = null, $params = [], $batch = false)
                    {
                        $this->executed[] = trim($sql);

                        return true;
                    }
                };
            }
        };
    }

    public function test_controller_serves_opcionales_mode_from_catalogo_core_model(): void
    {
        $path = FS_FOLDER . '/' . self::CONTROLLER_RELATIVE;
        if (!is_file($path)) {
            self::fail('missing tarifario path: ' . self::CONTROLLER_RELATIVE);
        }

        $source = (string) file_get_contents($path);

        $this->assertStringContainsString(
            self::HISTORY_RELATIVE,
            $source,
            'the history controller must require the catalogo_core opcional history model'
        );
        $this->assertStringContainsString("'opcionales'", $source, 'the controller must accept tipo=opcionales');
        $this->assertStringContainsString(
            '$this->historial_opcionales->count_by_fecha',
            $source,
            'the opcionales branch must expose page counters'
        );
        $this->assertStringContainsString(
            '$this->historial_opcionales->get_estadisticas',
            $source,
            'the opcionales branch must expose stats'
        );
    }

    public function test_opcional_history_model_is_owned_by_catalogo_core(): void
    {
        $this->assertFileExists(FS_FOLDER . '/' . self::HISTORY_RELATIVE);
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/tarifario/model/tarif_opcional_precio_historial.php',
            'the opcional price history model must be owned by catalogo_core'
        );
    }

    public function test_history_reads_are_safe_when_the_table_is_absent(): void
    {
        $model = $this->makeModel(false);

        $this->assertSame([], $model->all_by_fecha('2026-01-01', '2026-01-31', 'DEF'));
        $this->assertSame(0, $model->count_by_fecha('2026-01-01', '2026-01-31', 'DEF'));
        $this->assertSame(
            [
                'total_cambios' => 0,
                'opcionales_afectados' => 0,
                'subidas' => 0,
                'bajadas' => 0,
            ],
            $model->get_estadisticas('2026-01-01', '2026-01-31', 'DEF')
        );
    }

    public function test_history_purge_targets_the_opcional_history_table(): void
    {
        $model = $this->makeModel(true);

        $this->assertTrue($model->purge_by_fecha('2026-01-01', '2026-01-31', 'DEF'));
        $this->assertCount(1, $model->db->executed);
        $this->assertStringContainsString(
            'DELETE FROM tarif_opcional_precio_historial',
            $model->db->executed[0]
        );
    }
}
