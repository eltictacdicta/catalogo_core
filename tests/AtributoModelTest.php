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
 * Tests for the atributo and atributo_valor adjacent models (CAM-03, CAM-04, CAM-09).
 */
class AtributoModelTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/atributo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/atributo_valor.php';
    }

    // --- atributo tests ---

    public function testAtributoClassExists(): void
    {
        $this->assertTrue(class_exists('FSFramework\model\atributo'));
    }

    public function testAtributoExtendsFsModel(): void
    {
        $model = new \FSFramework\model\atributo(false);
        $this->assertInstanceOf(\fs_model::class, $model);
    }

    public function testAtributoConstructorWithNullDefaultsProperties(): void
    {
        $model = new \FSFramework\model\atributo(false);

        $this->assertNull($model->codatributo);
        $this->assertNull($model->nombre);
    }

    public function testAtributoConstructorHydratesFromArray(): void
    {
        $model = new \FSFramework\model\atributo([
            'codatributo' => 'ATR001',
            'nombre' => 'Color',
        ]);

        $this->assertSame('ATR001', $model->codatributo);
        $this->assertSame('Color', $model->nombre);
    }

    // --- atributo_valor tests ---

    public function testAtributoValorClassExists(): void
    {
        $this->assertTrue(class_exists('FSFramework\model\atributo_valor'));
    }

    public function testAtributoValorExtendsFsModel(): void
    {
        $model = new \FSFramework\model\atributo_valor(false);
        $this->assertInstanceOf(\fs_model::class, $model);
    }

    public function testAtributoValorConstructorWithNullDefaultsProperties(): void
    {
        $model = new \FSFramework\model\atributo_valor(false);

        $this->assertNull($model->id);
        $this->assertNull($model->codatributo);
        $this->assertNull($model->valor);
    }

    public function testAtributoValorConstructorHydratesFromArray(): void
    {
        $model = new \FSFramework\model\atributo_valor([
            'id' => '1',
            'codatributo' => 'ATR001',
            'valor' => 'Rojo',
        ]);

        $this->assertSame(1, $model->id);
        $this->assertSame('ATR001', $model->codatributo);
        $this->assertSame('Rojo', $model->valor);
    }
}
