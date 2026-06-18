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
 * Tests for the tarifa adjacent model (CAM-03, CAM-04, CAM-09).
 */
class TarifaModelTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/tarifa.php';
    }

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists('FSFramework\model\tarifa'));
    }

    public function testExtendsFsModel(): void
    {
        $model = new \FSFramework\model\tarifa(false);
        $this->assertInstanceOf(\fs_model::class, $model);
    }

    public function testConstructorWithNullDefaultsProperties(): void
    {
        $model = new \FSFramework\model\tarifa(false);

        $this->assertNull($model->codtarifa);
        $this->assertNull($model->nombre);
        $this->assertSame('pvp', $model->aplicar_a);
        $this->assertFalse($model->mincoste);
        $this->assertFalse($model->maxpvp);
    }

    public function testConstructorHydratesFromArray(): void
    {
        $model = new \FSFramework\model\tarifa([
            'codtarifa' => 'TAR001',
            'nombre' => 'Tarifa Mayorista',
            'incporcentual' => '-10',
            'inclineal' => '0',
            'aplicar_a' => 'pvp',
            'mincoste' => '0',
            'maxpvp' => '0',
        ]);

        $this->assertSame('TAR001', $model->codtarifa);
        $this->assertSame('Tarifa Mayorista', $model->nombre);
        $this->assertSame('pvp', $model->aplicar_a);
        $this->assertFalse($model->mincoste);
        $this->assertFalse($model->maxpvp);
    }

    public function testDiffReturnsDescriptionForPvpTarifa(): void
    {
        $model = new \FSFramework\model\tarifa([
            'codtarifa' => 'TAR002',
            'nombre' => 'Descuento 10%',
            'incporcentual' => '-10',
            'inclineal' => '0',
            'aplicar_a' => 'pvp',
            'mincoste' => '0',
            'maxpvp' => '0',
        ]);

        $diff = $model->diff();
        $this->assertIsString($diff);
        $this->assertStringContainsString('Precio de venta', $diff);
    }
}
