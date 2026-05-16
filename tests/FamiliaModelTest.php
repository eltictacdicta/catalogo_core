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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

class FamiliaModelTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
    }

    public function testConstructorHydratesFromArray(): void
    {
        $model = new \FSFramework\model\familia([
            'codfamilia' => 'FAM001',
            'descripcion' => 'Familia de prueba',
            'madre' => null,
            'nivel' => '1',
        ]);

        $this->assertSame('FAM001', $model->codfamilia);
        $this->assertSame('Familia de prueba', $model->descripcion);
        $this->assertNull($model->madre);
        $this->assertSame('1', $model->nivel);
    }

    public function testConstructorWithNullDefaultsProperties(): void
    {
        $model = new \FSFramework\model\familia(false);

        $this->assertNull($model->codfamilia);
        $this->assertSame('', $model->descripcion);
        $this->assertNull($model->madre);
        $this->assertSame('', $model->nivel);
    }

    public function testFamiliaIsInstantiable(): void
    {
        $model = new \FSFramework\model\familia(false);

        $this->assertIsObject($model);
    }
}
