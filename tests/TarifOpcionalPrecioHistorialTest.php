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
 * D3 price history semantics (spec "Changed price writes one history row"):
 * the adapter appends exactly one history row when `abs(old - new) >= 0.0001`
 * and appends none when the value is unchanged.
 */
final class TarifOpcionalPrecioHistorialTest extends TestCase
{
    private function makeTrackedModel(float $storedPrice): object
    {
        return new class($storedPrice) extends \FSFramework\model\tarif_opcional_precio {
            public $db;

            /** @var list<array{0: mixed, 1: mixed, 2: float, 3: float}> */
            public array $historial = [];

            public float $storedPrice = 0.0;

            public function __construct(float $storedPrice = 0.0)
            {
                $this->storedPrice = $storedPrice;
                $this->table_name = \FSFramework\model\catalogo_opcional_precio::TABLE;
                $this->db = new class {
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

                    public function exec($sql, $transaction = null, $params = [], $batch = false)
                    {
                        return true;
                    }
                };
            }

            public function exists()
            {
                return true;
            }

            public function delete()
            {
                return true;
            }

            public function get($id_opcional, $codlista)
            {
                $row = new static($this->storedPrice);
                $row->id_opcional = intval($id_opcional);
                $row->codlista = $codlista;
                $row->precio = $this->storedPrice;

                return $row;
            }

            protected function registrar_cambio_historial($id_opcional, $codlista, $precio_anterior, $precio_nuevo): bool
            {
                $this->historial[] = [$id_opcional, $codlista, $precio_anterior, $precio_nuevo];

                return true;
            }
        };
    }

    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional_precio.php';
    }

    public function test_changed_price_appends_exactly_one_history_row(): void
    {
        if (!method_exists(\FSFramework\model\tarif_opcional_precio::class, 'registrar_cambio_historial')) {
            self::fail(
                'adapter must expose the history seam registrar_cambio_historial() (design D3)'
            );
        }

        $model = $this->makeTrackedModel(10.0);
        $model->id_opcional = 1;
        $model->codlista = 'DEF';
        $model->precio = 12.0;

        $this->assertTrue($model->save());
        $this->assertCount(1, $model->historial, 'a changed price must append exactly one history row');
        $this->assertSame([1, 'DEF', 10.0, 12.0], $model->historial[0]);
    }

    public function test_unchanged_price_appends_no_history_row(): void
    {
        if (!method_exists(\FSFramework\model\tarif_opcional_precio::class, 'registrar_cambio_historial')) {
            self::fail(
                'adapter must expose the history seam registrar_cambio_historial() (design D3)'
            );
        }

        $model = $this->makeTrackedModel(10.0);
        $model->id_opcional = 1;
        $model->codlista = 'DEF';
        $model->precio = 10.0;

        $this->assertTrue($model->save());
        $this->assertCount(0, $model->historial, 'saving the same value must append no history row');
    }
}
