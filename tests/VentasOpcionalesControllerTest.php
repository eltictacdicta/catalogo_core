<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

class VentasOpcionalesControllerTest extends TestCase
{
    protected function setUp(): void
    {
        global $plugins;
        $plugins = [];
    }

    public function testModernControllerFileExists(): void
    {
        $file = FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcionales.php';
        $this->assertFileExists($file, 'PSR-4 controller VentasOpcionales.php must exist');
    }

    public function testModernControllerHasPrivateCoreMethod(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcionales.php';

        $this->assertTrue(
            method_exists(
                \FSFramework\Plugins\catalogo_core\Controller\VentasOpcionales::class,
                'privateCore'
            ),
            'VentasOpcionales must implement privateCore()'
        );
    }

    public function testGetPageDataReturnsVentasOpcionalesName(): void
    {
        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcionales.php'
        );
        $this->assertStringContainsString(
            "'name' => 'ventas_opcionales'",
            $source,
            'getPageData() must return name=ventas_opcionales'
        );
        $this->assertStringContainsString(
            "'showonmenu' => true",
            $source,
            'ventas_opcionales must appear in menu'
        );
    }

    public function testLegacyWrapperExtendsModernController(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcionales.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/controller/ventas_opcionales.php';

        $reflection = new \ReflectionClass('ventas_opcionales');
        $this->assertTrue(
            $reflection->isSubclassOf(
                \FSFramework\Plugins\catalogo_core\Controller\VentasOpcionales::class
            ),
            'Legacy wrapper ventas_opcionales must extend VentasOpcionales'
        );
    }

    public function testTwigViewIncludesCsrfField(): void
    {
        $content = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/ventas_opcionales.html.twig'
        );

        $this->assertStringContainsString(
            '{{ csrf_field() }}',
            $content,
            'Twig view must include csrf_field()'
        );
        $this->assertStringNotContainsString(
            '|raw',
            $content,
            'Twig view must not use |raw filter'
        );
    }
}
