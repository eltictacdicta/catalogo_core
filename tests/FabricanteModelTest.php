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

class FabricanteModelTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/fabricante.php';
    }

    public function testConstructorHydratesFromArray(): void
    {
        $model = new \FSFramework\model\fabricante([
            'codfabricante' => 'FAB001',
            'nombre' => 'Fabricante de prueba',
        ]);

        $this->assertSame('FAB001', $model->codfabricante);
        $this->assertSame('Fabricante de prueba', $model->nombre);
    }

    public function testConstructorWithNullDefaultsProperties(): void
    {
        $model = new \FSFramework\model\fabricante(false);

        $this->assertNull($model->codfabricante);
        $this->assertSame('', $model->nombre);
    }

    public function testFabricanteIsInstantiable(): void
    {
        $model = new \FSFramework\model\fabricante(false);

        $this->assertIsObject($model);
    }
}
