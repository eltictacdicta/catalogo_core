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
 * Tests for the articulo_combinacion adjacent model (CAM-03, CAM-04, CAM-09).
 */
class ArticuloCombinacionModelTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_combinacion.php';
    }

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists('FSFramework\model\articulo_combinacion'));
    }

    public function testExtendsFsModel(): void
    {
        $model = new \FSFramework\model\articulo_combinacion(false);
        $this->assertInstanceOf(\fs_model::class, $model);
    }

    public function testConstructorWithNullDefaultsProperties(): void
    {
        $model = new \FSFramework\model\articulo_combinacion(false);

        $this->assertNull($model->id);
        $this->assertNull($model->codigo);
        $this->assertNull($model->referencia);
        $this->assertNull($model->nombreatributo);
        $this->assertNull($model->valor);
        $this->assertSame(0.0, $model->impactoprecio);
        $this->assertSame(0.0, $model->stockfis);
    }

    public function testConstructorHydratesFromArray(): void
    {
        $model = new \FSFramework\model\articulo_combinacion([
            'id' => '1',
            'codigo' => '1',
            'codigo2' => 'EXT-001',
            'referencia' => 'REF-001',
            'idvalor' => '5',
            'nombreatributo' => 'Color',
            'valor' => 'Rojo',
            'refcombinacion' => 'COMB-001',
            'codbarras' => '123456789',
            'impactoprecio' => '2.50',
            'stockfis' => '10',
        ]);

        $this->assertSame(1, $model->id);
        $this->assertSame('1', $model->codigo);
        $this->assertSame('EXT-001', $model->codigo2);
        $this->assertSame('REF-001', $model->referencia);
        $this->assertSame(5, $model->idvalor);
        $this->assertSame('Color', $model->nombreatributo);
        $this->assertSame('Rojo', $model->valor);
        $this->assertSame('COMB-001', $model->refcombinacion);
        $this->assertSame('123456789', $model->codbarras);
        $this->assertSame(2.50, $model->impactoprecio);
        $this->assertSame(10.0, $model->stockfis);
    }
}
