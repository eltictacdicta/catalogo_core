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
 * Tests for the articulo_proveedor adjacent model (CAM-03, CAM-04, CAM-09).
 * This model extends fs_extended_model (which extends fs_model).
 */
class ArticuloProveedorModelTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_extended_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_proveedor.php';
    }

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists('FSFramework\model\articulo_proveedor'));
    }

    public function testExtendsFsExtendedModel(): void
    {
        $model = new \FSFramework\model\articulo_proveedor(false);
        $this->assertInstanceOf(\fs_extended_model::class, $model);
        $this->assertInstanceOf(\fs_model::class, $model);
    }

    public function testConstructorWithFalseClearsProperties(): void
    {
        $model = new \FSFramework\model\articulo_proveedor(false);

        $this->assertSame(0.0, $model->dto);
        $this->assertTrue($model->nostock);
        $this->assertSame(0.0, $model->precio);
        $this->assertSame(0.0, $model->stock);
    }

    public function testConstructorHydratesFromArray(): void
    {
        $model = new \FSFramework\model\articulo_proveedor([
            'id' => '1',
            'referencia' => 'REF-001',
            'codproveedor' => 'PROV001',
            'refproveedor' => 'REFPROV-001',
            'descripcion' => 'Proveedor description',
            'precio' => '10.50',
            'dto' => '5',
            'codimpuesto' => 'IVA21',
            'stock' => '100',
            'nostock' => '0',
            'codbarras' => '987654321',
            'partnumber' => 'PN-001',
        ]);

        $this->assertSame('REF-001', $model->referencia);
        $this->assertSame('PROV001', $model->codproveedor);
        $this->assertSame('REFPROV-001', $model->refproveedor);
        $this->assertSame('Proveedor description', $model->descripcion);
    }
}
