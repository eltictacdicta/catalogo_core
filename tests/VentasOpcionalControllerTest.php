<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

class VentasOpcionalControllerTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];
    }

    public function testModernControllerFileExists(): void
    {
        $file = FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcional.php';
        $this->assertFileExists($file, 'PSR-4 controller VentasOpcional.php must exist');
    }

    public function testModernControllerHasPrivateCoreMethod(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcional.php';

        $this->assertTrue(
            method_exists(
                \FSFramework\Plugins\catalogo_core\Controller\VentasOpcional::class,
                'privateCore'
            ),
            'VentasOpcional must implement privateCore()'
        );
    }

    public function testGetPageDataReturnsVentasOpcionalName(): void
    {
        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcional.php'
        );
        $this->assertStringContainsString(
            "'name' => 'ventas_opcional'",
            $source,
            'getPageData() must return name=ventas_opcional'
        );
    }

    public function testModernControllerAcceptsIdParameter(): void
    {
        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcional.php'
        );
        $this->assertStringContainsString(
            "query->getInt('id')",
            $source,
            'VentasOpcional must accept the id query parameter'
        );
        $this->assertStringContainsString(
            'buscar_articulo',
            $source,
            'VentasOpcional must support article autocomplete search'
        );
    }

    public function testLegacyWrapperExtendsModernController(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcional.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/controller/ventas_opcional.php';

        $reflection = new \ReflectionClass('ventas_opcional');
        $this->assertTrue(
            $reflection->isSubclassOf(
                \FSFramework\Plugins\catalogo_core\Controller\VentasOpcional::class
            ),
            'Legacy wrapper ventas_opcional must extend VentasOpcional'
        );
    }

    public function testTwigViewIncludesCsrfField(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/ventas_opcional.html.twig'
        );

        $this->assertStringContainsString(
            '{{ csrf_field() }}',
            $content,
            'Twig view must include csrf_field() in POST forms'
        );
        $this->assertStringContainsString(
            'buscar_articulo',
            $content,
            'Twig view must include article search autocomplete'
        );
        $this->assertStringNotContainsString(
            '|raw',
            $content,
            'Twig view must not use |raw filter'
        );
    }

    public function testCatalogoOpcionalUrlPointsToVentasOpcional(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';

        $opcional = new \FSFramework\model\catalogo_opcional([
            'id' => 7,
            'codigo' => 'OPC0007',
            'nombre' => 'Test',
            'descripcion' => '',
            'precio' => 0,
            'activo' => true,
        ]);

        $this->assertSame(
            'index.php?page=ventas_opcional&id=7',
            $opcional->url()
        );
    }
}
