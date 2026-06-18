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
 * Tests for the articulo_traza adjacent model (CAM-03, CAM-04, CAM-09).
 */
class ArticuloTrazabilidadModelTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_traza.php';
    }

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists('FSFramework\model\articulo_traza'));
    }

    public function testExtendsFsModel(): void
    {
        $model = new \FSFramework\model\articulo_traza(false);
        $this->assertInstanceOf(\fs_model::class, $model);
    }

    public function testConstructorWithNullDefaultsProperties(): void
    {
        $model = new \FSFramework\model\articulo_traza(false);

        $this->assertNull($model->id);
        $this->assertNull($model->referencia);
        $this->assertNull($model->numserie);
        $this->assertNull($model->lote);
        $this->assertNull($model->idlalbventa);
        $this->assertNull($model->idlfacventa);
        $this->assertNull($model->idlalbcompra);
        $this->assertNull($model->idlfaccompra);
        $this->assertNull($model->fecha_entrada);
        $this->assertNull($model->fecha_salida);
    }

    public function testConstructorHydratesFromArray(): void
    {
        $model = new \FSFramework\model\articulo_traza([
            'id' => '42',
            'referencia' => 'REF-001',
            'numserie' => 'SN-12345',
            'lote' => 'LOT-001',
            'idlalbventa' => '10',
            'idlfacventa' => '20',
            'idlalbcompra' => '30',
            'idlfaccompra' => '40',
            'fecha_entrada' => '2026-01-15',
            'fecha_salida' => '2026-02-20',
        ]);

        $this->assertSame(42, $model->id);
        $this->assertSame('REF-001', $model->referencia);
        $this->assertSame('SN-12345', $model->numserie);
        $this->assertSame('LOT-001', $model->lote);
        $this->assertSame(10, $model->idlalbventa);
        $this->assertSame(20, $model->idlfacventa);
        $this->assertSame(30, $model->idlalbcompra);
        $this->assertSame(40, $model->idlfaccompra);
        $this->assertSame('15-01-2026', $model->fecha_entrada);
    }
}
