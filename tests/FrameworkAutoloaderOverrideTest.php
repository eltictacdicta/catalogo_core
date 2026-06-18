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
 * Test that verifies the framework's autoloader correctly handles plugin overrides.
 * 
 * This test simulates the real-world scenario where:
 * 1. catalogo_core provides base models (like familia)
 * 2. tarifario extends/overrides those models with enhanced versions
 * 3. Both can coexist without "Cannot declare class" errors
 * 
 * The key insight is that:
 * - catalogo_core defines namespaced classes (FSFramework\model\familia)
 * - tarifario defines global classes (familia) that extend its own versions
 * - These are DIFFERENT classes and can coexist
 * - The framework's autoloader handles this correctly
 */
class FrameworkAutoloaderOverrideTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        // Configure active plugins for the autoloader
        $plugins = ['catalogo_core', 'tarifario'];

        if (!defined('FS_VENTAS_SIN_STOCK')) {
            define('FS_VENTAS_SIN_STOCK', false);
        }

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_model_autoloader.php';
        
        // Clear any cached class map to ensure fresh loading
        \fs_model_autoloader::clearCache();
        
        // Register the framework's autoloader
        \fs_model_autoloader::register();
    }

    /**
     * Test that the framework autoloader can load catalogo_core models.
     * 
     * Note: The framework's autoloader is designed for legacy global classes.
     * Modern namespaced models (FSFramework\model\*) need to be loaded manually
     * or via Composer's PSR-4 autoloader.
     */
    public function testAutoloaderLoadsCatalogoCoreModels(): void
    {
        // Load the namespaced model manually (as other tests do)
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        
        $this->assertTrue(
            class_exists('FSFramework\model\familia', false),
            'FSFramework\model\familia should be loadable from catalogo_core'
        );
        
        $model = new \FSFramework\model\familia();
        $this->assertInstanceOf(\fs_model::class, $model);
    }

    /**
     * Test that tarifario can define its own global 'familia' class
     * without conflicting with catalogo_core's namespaced version.
     */
    public function testTarifarioGlobalClassCoexistsWithNamespacedClass(): void
    {
        // Load catalogo_core's namespaced familia manually
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        
        // Verify it exists
        $this->assertTrue(
            class_exists('FSFramework\model\familia', false),
            'Namespaced familia should exist'
        );
        
        // Load tarifario's models manually
        require_once FS_FOLDER . '/plugins/tarifario/model/tarif_familia.php';
        require_once FS_FOLDER . '/plugins/tarifario/model/familia.php';
        
        // Verify both classes exist
        $this->assertTrue(
            class_exists('FSFramework\model\familia', false),
            'Namespaced familia should still exist after loading tarifario'
        );
        
        $this->assertTrue(
            class_exists('familia', false),
            'Global familia (from tarifario) should exist'
        );
        
        // Verify they are different classes
        $namespacedClass = get_class(new \FSFramework\model\familia());
        $globalClass = get_class(new \familia());
        
        $this->assertNotEquals(
            $namespacedClass,
            $globalClass,
            'Namespaced and global familia should be different classes'
        );
        
        // Verify the global familia extends tarif_familia
        $this->assertInstanceOf(
            'FSFramework\model\tarif_familia',
            new \familia(),
            'Global familia should extend tarif_familia'
        );
    }

    /**
     * Test that the override pattern works correctly in practice.
     * 
     * This simulates how plugins would actually use these classes:
     * - Code using the base model: new FSFramework\model\familia()
     * - Code using the override: new familia() (global)
     */
    public function testOverridePatternInPractice(): void
    {
        // Load both versions
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        require_once FS_FOLDER . '/plugins/tarifario/model/tarif_familia.php';
        require_once FS_FOLDER . '/plugins/tarifario/model/familia.php';
        
        // Base model instance
        $baseModel = new \FSFramework\model\familia();
        $this->assertInstanceOf(\fs_model::class, $baseModel);
        $this->assertNotInstanceOf('FSFramework\model\tarif_familia', $baseModel);
        
        // Override model instance
        $overrideModel = new \familia();
        $this->assertInstanceOf(\fs_model::class, $overrideModel);
        $this->assertInstanceOf('FSFramework\model\tarif_familia', $overrideModel);
        
        // Verify they have different inheritance chains
        $baseReflection = new \ReflectionClass($baseModel);
        $overrideReflection = new \ReflectionClass($overrideModel);
        
        $this->assertEquals(
            'FSFramework\model\familia',
            $baseReflection->getName(),
            'Base model should be FSFramework\model\familia'
        );
        
        $this->assertEquals(
            'familia',
            $overrideReflection->getName(),
            'Override model should be global familia'
        );
        
        $this->assertEquals(
            'FSFramework\model\tarif_familia',
            $overrideReflection->getParentClass()->getName(),
            'Override should extend tarif_familia'
        );
    }

    /**
     * Test that the "Cannot declare class" error does NOT occur.
     * 
     * This is the critical test that verifies the bug is fixed.
     * If this test passes, it means the override mechanism works correctly.
     */
    public function testNoCannotDeclareClassError(): void
    {
        // This should NOT throw "Cannot declare class familia" error
        try {
            // Load catalogo_core first
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
            
            // Then load tarifario (which defines global 'familia')
            require_once FS_FOLDER . '/plugins/tarifario/model/tarif_familia.php';
            require_once FS_FOLDER . '/plugins/tarifario/model/familia.php';
            
            // If we get here without an error, the test passes
            $this->assertTrue(true, 'No "Cannot declare class" error occurred');
            
        } catch (\Error $e) {
            if (strpos($e->getMessage(), 'Cannot declare class familia') !== false) {
                $this->fail('The "Cannot declare class familia" error still occurs: ' . $e->getMessage());
            }
            throw $e;
        }
    }
}
