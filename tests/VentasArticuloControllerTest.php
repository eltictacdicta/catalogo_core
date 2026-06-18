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

class VentasArticuloControllerTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];
    }

    public function testModernControllerFileExists(): void
    {
        $file = FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php';
        $this->assertFileExists($file, 'PSR-4 controller VentasArticulo.php must exist');
    }

    public function testModernControllerHasPrivateCoreMethod(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php';

        $this->assertTrue(
            method_exists(
                \FSFramework\Plugins\catalogo_core\Controller\VentasArticulo::class,
                'privateCore'
            ),
            'VentasArticulo must implement privateCore()'
        );
    }

    public function testModernControllerHasGetPageDataMethod(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php';

        $this->assertTrue(
            method_exists(
                \FSFramework\Plugins\catalogo_core\Controller\VentasArticulo::class,
                'getPageData'
            ),
            'VentasArticulo must implement getPageData()'
        );
    }

    public function testGetPageDataReturnsVentasArticuloName(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php';

        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php'
        );
        $this->assertStringContainsString(
            "'name' => 'ventas_articulo'",
            $source,
            'getPageData() must return name=ventas_articulo (CPV-05)'
        );
    }

    public function testModernControllerAcceptsRefParameter(): void
    {
        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php'
        );
        $this->assertStringContainsString(
            'ref',
            $source,
            'VentasArticulo must accept the ref query parameter (CPV-04, CPV-09)'
        );
    }

    public function testModernControllerUsesArticuloGet(): void
    {
        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php'
        );
        $this->assertTrue(
            strpos($source, '->get(') !== false,
            'VentasArticulo must use articulo->get($ref) to load article data'
        );
    }

    public function testLegacyWrapperFileExists(): void
    {
        $file = FS_FOLDER . '/plugins/catalogo_core/controller/ventas_articulo.php';
        $this->assertFileExists($file, 'Legacy wrapper ventas_articulo.php must exist');
    }

    public function testLegacyWrapperExtendsModernController(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/controller/ventas_articulo.php';

        $reflection = new \ReflectionClass('ventas_articulo');
        $this->assertTrue(
            $reflection->isSubclassOf(
                \FSFramework\Plugins\catalogo_core\Controller\VentasArticulo::class
            ),
            'Legacy wrapper ventas_articulo must extend VentasArticulo (CPV-10)'
        );
    }

    public function testTwigViewFileExists(): void
    {
        $file = FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulo.html.twig';
        $this->assertFileExists($file, 'Twig view ventas_articulo.html.twig must exist');
    }

    public function testTwigViewUsesAutoEscape(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulo.html.twig'
        );

        $this->assertStringNotContainsString(
            '|raw',
            $content,
            'Twig view must not use |raw filter (CPV-06)'
        );
    }

    public function testTwigViewIncludesCsrfField(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulo.html.twig'
        );

        // Detail page has POST forms for editing article data
        $this->assertStringContainsString(
            '{{ csrf_field() }}',
            $content,
            'Twig view must include csrf_field() in POST forms (CPV-07)'
        );
    }

    public function testTwigViewIncludesHeaderAndFooter(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulo.html.twig'
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

    public function testTwigViewDisplaysArticleReference(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulo.html.twig'
        );

        $this->assertStringContainsString(
            'fsc.articulo.referencia',
            $content,
            'Twig view must display the article reference'
        );
    }

    public function testTwigViewDisplaysArticleDescription(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulo.html.twig'
        );

        $this->assertStringContainsString(
            'fsc.articulo.descripcion',
            $content,
            'Twig view must display the article description'
        );
    }

    public function testTwigViewDisplaysArticlePrice(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulo.html.twig'
        );

        $this->assertStringContainsString(
            'fsc.articulo.pvp',
            $content,
            'Twig view must display the article price (pvp)'
        );
    }

    public function testControllerUsesVar2strOrModelMethodsForSqlSafety(): void
    {
        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php'
        );

        // The controller should use articulo->get() which internally uses var2str
        // OR it should use var2str directly for any SQL queries
        $this->assertTrue(
            strpos($source, 'var2str') !== false || strpos($source, '->get(') !== false,
            'Controller must use var2str() or model get() for SQL safety (CPV-08)'
        );
    }
}
