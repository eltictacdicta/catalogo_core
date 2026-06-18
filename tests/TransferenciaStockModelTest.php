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
 * Tests for the transferencia_stock and linea_transferencia_stock adjacent
 * models (CAM-03, CAM-04, CAM-09).
 */
class TransferenciaStockModelTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/transferencia_stock.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/linea_transferencia_stock.php';
    }

    // --- transferencia_stock tests ---

    public function testTransferenciaStockClassExists(): void
    {
        $this->assertTrue(class_exists('FSFramework\model\transferencia_stock'));
    }

    public function testTransferenciaStockExtendsFsModel(): void
    {
        $model = new \FSFramework\model\transferencia_stock(false);
        $this->assertInstanceOf(\fs_model::class, $model);
    }

    public function testTransferenciaStockConstructorWithNullDefaultsProperties(): void
    {
        $model = new \FSFramework\model\transferencia_stock(false);

        $this->assertNull($model->idtrans);
        $this->assertNull($model->codalmadestino);
        $this->assertNull($model->codalmaorigen);
        $this->assertNull($model->usuario);
    }

    public function testTransferenciaStockConstructorHydratesFromArray(): void
    {
        $model = new \FSFramework\model\transferencia_stock([
            'idtrans' => '1',
            'codalmadestino' => 'ALG002',
            'codalmaorigen' => 'ALG001',
            'fecha' => '2026-04-01',
            'hora' => '09:00:00',
            'usuario' => 'admin',
        ]);

        $this->assertSame(1, $model->idtrans);
        $this->assertSame('ALG002', $model->codalmadestino);
        $this->assertSame('ALG001', $model->codalmaorigen);
        $this->assertSame('01-04-2026', $model->fecha);
        $this->assertSame('09:00:00', $model->hora);
        $this->assertSame('admin', $model->usuario);
    }

    // --- linea_transferencia_stock tests ---

    public function testLineaTransferenciaStockClassExists(): void
    {
        $this->assertTrue(class_exists('FSFramework\model\linea_transferencia_stock'));
    }

    public function testLineaTransferenciaStockExtendsFsModel(): void
    {
        $model = new \FSFramework\model\linea_transferencia_stock(false);
        $this->assertInstanceOf(\fs_model::class, $model);
    }

    public function testLineaTransferenciaStockConstructorWithNullDefaultsProperties(): void
    {
        $model = new \FSFramework\model\linea_transferencia_stock(false);

        $this->assertNull($model->idlinea);
        $this->assertNull($model->idtrans);
        $this->assertNull($model->referencia);
        $this->assertSame(0, $model->cantidad);
        $this->assertNull($model->descripcion);
    }

    public function testLineaTransferenciaStockConstructorHydratesFromArray(): void
    {
        $model = new \FSFramework\model\linea_transferencia_stock([
            'idlinea' => '5',
            'idtrans' => '1',
            'referencia' => 'REF-001',
            'cantidad' => '25.5',
            'descripcion' => 'Transfer line',
            'fecha' => '2026-04-01',
            'hora' => '09:00:00',
        ]);

        $this->assertSame(5, $model->idlinea);
        $this->assertSame(1, $model->idtrans);
        $this->assertSame('REF-001', $model->referencia);
        $this->assertSame(25.5, $model->cantidad);
        $this->assertSame('Transfer line', $model->descripcion);
        $this->assertSame('01-04-2026', $model->fecha());
        $this->assertSame('09:00:00', $model->hora());
    }
}
