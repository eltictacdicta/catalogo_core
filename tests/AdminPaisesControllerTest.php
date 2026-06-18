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

class AdminPaisesControllerTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];
    }

    public function testModernControllerFileExists(): void
    {
        $file = FS_FOLDER . '/plugins/catalogo_core/Controller/AdminPaises.php';
        $this->assertFileExists($file, 'PSR-4 controller AdminPaises.php must exist');
    }

    public function testModernControllerHasPrivateCoreMethod(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/AdminPaises.php';

        $this->assertTrue(
            method_exists(
                \FSFramework\Plugins\catalogo_core\Controller\AdminPaises::class,
                'privateCore'
            ),
            'AdminPaises must implement privateCore()'
        );
    }

    public function testModernControllerHasGetPageDataMethod(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/AdminPaises.php';

        $this->assertTrue(
            method_exists(
                \FSFramework\Plugins\catalogo_core\Controller\AdminPaises::class,
                'getPageData'
            ),
            'AdminPaises must implement getPageData()'
        );
    }

    public function testGetPageDataReturnsAdminPaisesName(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/AdminPaises.php';

        $reflection = new \ReflectionClass(
            \FSFramework\Plugins\catalogo_core\Controller\AdminPaises::class
        );
        $method = $reflection->getMethod('getPageData');

        // getPageData does not require $this context for static analysis of return structure
        // We verify the page name constant is defined in the source
        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/AdminPaises.php'
        );
        $this->assertStringContainsString(
            "'name' => 'admin_paises'",
            $source,
            'getPageData() must return name=admin_paises (CPV-05)'
        );
    }

    public function testLegacyWrapperFileExists(): void
    {
        $file = FS_FOLDER . '/plugins/catalogo_core/controller/admin_paises.php';
        $this->assertFileExists($file, 'Legacy wrapper admin_paises.php must exist');
    }

    public function testLegacyWrapperExtendsModernController(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/AdminPaises.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/controller/admin_paises.php';

        $reflection = new \ReflectionClass('admin_paises');
        $this->assertTrue(
            $reflection->isSubclassOf(
                \FSFramework\Plugins\catalogo_core\Controller\AdminPaises::class
            ),
            'Legacy wrapper admin_paises must extend AdminPaises (CPV-10)'
        );
    }

    public function testTwigViewFileExists(): void
    {
        $file = FS_FOLDER . '/plugins/catalogo_core/View/admin_paises.html.twig';
        $this->assertFileExists($file, 'Twig view admin_paises.html.twig must exist');
    }

    public function testTwigViewUsesAutoEscape(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/admin_paises.html.twig'
        );

        // Must not use |raw on user data fields (CPV-06)
        $this->assertStringNotContainsString(
            '|raw',
            $content,
            'Twig view must not use |raw filter (CPV-06)'
        );
    }

    public function testTwigViewIncludesCsrfField(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/admin_paises.html.twig'
        );

        $this->assertStringContainsString(
            '{{ csrf_field() }}',
            $content,
            'Twig view must include csrf_field() in POST forms (CPV-07)'
        );
    }

    public function testTwigViewIncludesHeaderAndFooter(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/admin_paises.html.twig'
        );

        $this->assertStringContainsString(
            "{{ include('header.html.twig') }}",
            $content,
            'Twig view must include header template'
        );
        $this->assertStringContainsString(
            "{{ include('footer.html.twig') }}",
            $content,
            'Twig view must include footer template'
        );
    }
}
