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
 * Tests for the articulo_propiedad adjacent model (CAM-03, CAM-04, CAM-09).
 */
class ArticuloPropiedadModelTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo_propiedad.php';
    }

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists('FSFramework\model\articulo_propiedad'));
    }

    public function testExtendsFsModel(): void
    {
        $model = new \FSFramework\model\articulo_propiedad(false);
        $this->assertInstanceOf(\fs_model::class, $model);
    }

    public function testConstructorWithNullDefaultsProperties(): void
    {
        $model = new \FSFramework\model\articulo_propiedad(false);

        $this->assertNull($model->name);
        $this->assertNull($model->referencia);
        $this->assertNull($model->text);
    }

    public function testConstructorHydratesFromArray(): void
    {
        $model = new \FSFramework\model\articulo_propiedad([
            'name' => 'Material',
            'referencia' => 'REF-001',
            'text' => 'Aluminio',
        ]);

        $this->assertSame('Material', $model->name);
        $this->assertSame('REF-001', $model->referencia);
        $this->assertSame('Aluminio', $model->text);
    }
}
