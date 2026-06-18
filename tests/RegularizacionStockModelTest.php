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
 * Tests for the regularizacion_stock adjacent model (CAM-03, CAM-04, CAM-09).
 */
class RegularizacionStockModelTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/regularizacion_stock.php';
    }

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists('FSFramework\model\regularizacion_stock'));
    }

    public function testExtendsFsModel(): void
    {
        $model = new \FSFramework\model\regularizacion_stock(false);
        $this->assertInstanceOf(\fs_model::class, $model);
    }

    public function testConstructorWithNullDefaultsProperties(): void
    {
        $model = new \FSFramework\model\regularizacion_stock(false);

        $this->assertNull($model->id);
        $this->assertNull($model->idstock);
        $this->assertSame(0, $model->cantidadini);
        $this->assertSame(0, $model->cantidadfin);
        $this->assertNull($model->codalmacendest);
        $this->assertSame('', $model->motivo);
        $this->assertNull($model->nick);
    }

    public function testConstructorHydratesFromArray(): void
    {
        $model = new \FSFramework\model\regularizacion_stock([
            'id' => '10',
            'idstock' => '5',
            'cantidadini' => '100.0',
            'cantidadfin' => '80.0',
            'codalmacendest' => 'ALG001',
            'fecha' => '2026-03-15',
            'hora' => '10:30:00',
            'motivo' => 'Ajuste de inventario',
            'nick' => 'admin',
        ]);

        $this->assertSame(10, $model->id);
        $this->assertSame(5, $model->idstock);
        $this->assertSame(100.0, $model->cantidadini);
        $this->assertSame(80.0, $model->cantidadfin);
        $this->assertSame('ALG001', $model->codalmacendest);
        $this->assertSame('15-03-2026', $model->fecha);
        $this->assertSame('10:30:00', $model->hora);
        $this->assertSame('Ajuste de inventario', $model->motivo);
        $this->assertSame('admin', $model->nick);
    }
}
