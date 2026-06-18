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

class VentasArticulosControllerTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];
    }

    public function testModernControllerFileExists(): void
    {
        $file = FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulos.php';
        $this->assertFileExists($file, 'PSR-4 controller VentasArticulos.php must exist');
    }

    public function testModernControllerHasPrivateCoreMethod(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulos.php';

        $this->assertTrue(
            method_exists(
                \FSFramework\Plugins\catalogo_core\Controller\VentasArticulos::class,
                'privateCore'
            ),
            'VentasArticulos must implement privateCore()'
        );
    }

    public function testModernControllerHasGetPageDataMethod(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulos.php';

        $this->assertTrue(
            method_exists(
                \FSFramework\Plugins\catalogo_core\Controller\VentasArticulos::class,
                'getPageData'
            ),
            'VentasArticulos must implement getPageData()'
        );
    }

    public function testGetPageDataReturnsVentasArticulosName(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulos.php';

        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulos.php'
        );
        $this->assertStringContainsString(
            "'name' => 'ventas_articulos'",
            $source,
            'getPageData() must return name=ventas_articulos (CPV-05)'
        );
    }

    public function testLegacyWrapperFileExists(): void
    {
        $file = FS_FOLDER . '/plugins/catalogo_core/controller/ventas_articulos.php';
        $this->assertFileExists($file, 'Legacy wrapper ventas_articulos.php must exist');
    }

    public function testLegacyWrapperExtendsModernController(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulos.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/controller/ventas_articulos.php';

        $reflection = new \ReflectionClass('ventas_articulos');
        $this->assertTrue(
            $reflection->isSubclassOf(
                \FSFramework\Plugins\catalogo_core\Controller\VentasArticulos::class
            ),
            'Legacy wrapper ventas_articulos must extend VentasArticulos (CPV-10)'
        );
    }

    public function testTwigViewFileExists(): void
    {
        $file = FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulos.html.twig';
        $this->assertFileExists($file, 'Twig view ventas_articulos.html.twig must exist');
    }

    public function testTwigViewUsesAutoEscape(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulos.html.twig'
        );

        $this->assertStringNotContainsString(
            '|raw',
            $content,
            'Twig view must not use |raw filter (CPV-06)'
        );
    }

    public function testTwigViewIncludesCsrfFieldIfPostFormsExist(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulos.html.twig'
        );

        // CPV-07: IF there are POST forms, they MUST include csrf_field()
        // This is a list page with only GET forms, so no POST forms expected
        // But if POST forms are added later, they must include csrf_field()
        $hasPostForm = preg_match('/<form[^>]*method=["\']post["\']/i', $content);
        
        if ($hasPostForm) {
            $this->assertStringContainsString(
                '{{ csrf_field() }}',
                $content,
                'Twig view must include csrf_field() in POST forms (CPV-07)'
            );
        } else {
            // No POST forms, test passes
            $this->assertTrue(true, 'No POST forms in list view, CSRF not required');
        }
    }

    public function testTwigViewIncludesHeaderAndFooter(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulos.html.twig'
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

    public function testControllerAcceptsSearchParameter(): void
    {
        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulos.php'
        );

        $this->assertStringContainsString(
            'search',
            $source,
            'Controller must accept search parameter (CPV-04)'
        );
    }

    public function testControllerAcceptsFamiliaFilter(): void
    {
        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulos.php'
        );

        $this->assertStringContainsString(
            'codfamilia',
            $source,
            'Controller must accept codfamilia filter parameter (CPV-04)'
        );
    }

    public function testControllerAcceptsFabricanteFilter(): void
    {
        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulos.php'
        );

        $this->assertStringContainsString(
            'codfabricante',
            $source,
            'Controller must accept codfabricante filter parameter (CPV-04)'
        );
    }

    public function testControllerUsesVar2strForSqlSafety(): void
    {
        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulos.php'
        );

        // The controller should use articulo->search() which internally uses var2str
        // OR it should use var2str directly for any SQL queries
        $this->assertTrue(
            strpos($source, 'var2str') !== false || strpos($source, '->search(') !== false,
            'Controller must use var2str() or model search() for SQL safety (CPV-08)'
        );
    }
}
