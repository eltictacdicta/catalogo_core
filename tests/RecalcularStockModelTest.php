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
 * Tests for the recalcular_stock adjacent model (CAM-03, CAM-04).
 *
 * Note: recalcular_stock does NOT extend fs_model — it is a standalone
 * utility class in the FSFramework\model namespace. Its constructor
 * creates DB connections and other model instances, so we test only
 * class existence and namespace via reflection (no instantiation).
 */
class RecalcularStockModelTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];

        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/recalcular_stock.php';
    }

    public function testClassExists(): void
    {
        $this->assertTrue(class_exists('FSFramework\model\recalcular_stock'));
    }

    public function testClassIsInCorrectNamespace(): void
    {
        $ref = new \ReflectionClass('FSFramework\model\recalcular_stock');
        $this->assertSame('FSFramework\model', $ref->getNamespaceName());
    }

    public function testClassDoesNotExtendFsModel(): void
    {
        $ref = new \ReflectionClass('FSFramework\model\recalcular_stock');
        $this->assertFalse($ref->isSubclassOf(\fs_model::class));
    }

    public function testHasExpectedMethod(): void
    {
        $ref = new \ReflectionClass('FSFramework\model\recalcular_stock');
        $this->assertTrue($ref->hasMethod('get_movimientos'));
    }
}
