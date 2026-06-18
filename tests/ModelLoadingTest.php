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
 * Verifies that catalogo_core models can be loaded and instantiated correctly.
 * The framework's fs_model_autoloader handles model loading in runtime,
 * respecting plugin dependency order and allowing overrides.
 */
class ModelLoadingTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        if (!defined('FS_VENTAS_SIN_STOCK')) {
            define('FS_VENTAS_SIN_STOCK', false);
        }

        require_once FS_FOLDER . '/base/fs_model.php';
    }

    public function testArticuloLoadsFromCatalogoCore(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
        $this->assertTrue(class_exists('FSFramework\model\articulo', false));
        
        $model = new \FSFramework\model\articulo();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testFamiliaLoadsFromCatalogoCore(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        $this->assertTrue(class_exists('FSFramework\model\familia', false));
        
        $model = new \FSFramework\model\familia();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testFabricanteLoadsFromCatalogoCore(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/fabricante.php';
        $this->assertTrue(class_exists('FSFramework\model\fabricante', false));
        
        $model = new \FSFramework\model\fabricante();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testImpuestoLoadsFromCatalogoCore(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/impuesto.php';
        $this->assertTrue(class_exists('FSFramework\model\impuesto', false));
        
        $model = new \FSFramework\model\impuesto();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testAlmacenLoadsFromCatalogoCore(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/almacen.php';
        $this->assertTrue(class_exists('almacen', false));
        
        $model = new \almacen();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testDivisaLoadsFromCatalogoCore(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/divisa.php';
        $this->assertTrue(class_exists('divisa', false));
        
        $model = new \divisa();
        $this->assertInstanceOf('fs_model', $model);
    }

    public function testPaisLoadsFromCatalogoCore(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/pais.php';
        $this->assertTrue(class_exists('pais', false));
        
        $model = new \pais();
        $this->assertInstanceOf('fs_model', $model);
    }
}
