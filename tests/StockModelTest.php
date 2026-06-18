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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Tests for the stock adjacent model (CAM-03, CAM-04, CAM-09).
 * This model extends fs_extended_model (which extends fs_model).
 */
class StockModelTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        if (!defined('FS_STOCK_NEGATIVO')) {
            define('FS_STOCK_NEGATIVO', false);
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_extended_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/stock.php';
    }

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists('FSFramework\model\stock'));
    }

    public function testExtendsFsExtendedModel(): void
    {
        $model = new \FSFramework\model\stock(false);
        $this->assertInstanceOf(\fs_extended_model::class, $model);
        $this->assertInstanceOf(\fs_model::class, $model);
    }

    public function testConstructorWithFalseClearsProperties(): void
    {
        $model = new \FSFramework\model\stock(false);

        $this->assertSame(0.0, $model->cantidad);
        $this->assertSame(0.0, $model->cantidadultreg);
        $this->assertSame(0.0, $model->disponible);
        $this->assertSame(0.0, $model->pterecibir);
        $this->assertSame(0.0, $model->reservada);
        $this->assertSame(0.0, $model->stockmax);
        $this->assertSame(0.0, $model->stockmin);
        $this->assertSame('', $model->nombre);
    }

    public function testConstructorHydratesFromArray(): void
    {
        $model = new \FSFramework\model\stock([
            'idstock' => '1',
            'referencia' => 'REF-001',
            'codalmacen' => 'ALG001',
            'cantidad' => '50.5',
            'disponible' => '45.0',
            'reservada' => '5.5',
            'stockmax' => '100',
            'stockmin' => '10',
            'ubicacion' => 'A-1-2',
        ]);

        $this->assertSame('REF-001', $model->referencia);
        $this->assertSame('ALG001', $model->codalmacen);
        $this->assertSame('A-1-2', $model->ubicacion);
    }
}
