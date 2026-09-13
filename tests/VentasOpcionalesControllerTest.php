<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

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

    /**
     * Regression: privateCore() must load the active-tarifa context BEFORE it
     * dispatches the create/delete mutations. Otherwise $this->tarifas and
     * $this->tarifa_seleccionada are unset during new_opcional(), so the
     * create-modal per-tarifa prices/percentage are silently dropped.
     */
    public function testPrivateCoreLoadsTheListBeforeDispatchingCreate(): void
    {
        $source = file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcionales.php'
        );

        $body = $this->methodBody($source, 'privateCore');
        $this->assertNotSame('', $body, 'privateCore() body must be found');

        $init = strpos($body, 'init_opcionales_list()');
        $create = strpos($body, 'new_opcional()');
        $delete = strpos($body, 'delete_opcional()');

        $this->assertNotFalse($init, 'privateCore() must initialize the opcionales list');
        $this->assertNotFalse($create, 'privateCore() must dispatch new_opcional()');
        $this->assertNotFalse($delete, 'privateCore() must dispatch delete_opcional()');
        $this->assertLessThan(
            $create,
            $init,
            'the list (active tarifas + selected tarifa) must load before new_opcional()'
        );
        $this->assertLessThan(
            $delete,
            $init,
            'the list must load before delete_opcional()'
        );
    }

    /**
     * Runtime proof of the ordering regression: with the active-tarifa context
     * loaded (mirroring the fixed privateCore() order) the create-modal
     * per-tarifa values persist to the matching mode. Without the context the
     * values are dropped, which is exactly what the previous order produced.
     */
    public function testCreateModalPerTarifaValuesPersistWhenTarifasAreLoaded(): void
    {
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/base/fs_core_log.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasOpcionales.php';

        $opcional = new CreateOrderingOpcionalStub();
        $controller = new class($opcional) extends \FSFramework\Plugins\catalogo_core\Controller\VentasOpcionales {
            /** @var mixed */
            public $db;

            private $opcionalStub;

            public function __construct($opcional)
            {
                $this->opcionalStub = $opcional;
            }

            public function exposeNewOpcional(): void
            {
                $this->new_opcional();
            }

            protected function opcional_model()
            {
                return $this->opcionalStub;
            }

            protected function validateFormToken(): bool
            {
                return true;
            }

            protected function run_in_transaction(callable $work): bool
            {
                return (bool) $work();
            }

            public function new_message(string $msg): void
            {
            }

            public function new_error_msg(string $msg): void
            {
            }
        };

        // The fixed privateCore() order: the list (and its active tarifas) is
        // loaded before the mutation dispatch.
        $controller->tarifas = [
            (object) ['codtarifa' => 'T1', 'nombre' => 'Base', 'coddivisa' => 'EUR'],
            (object) ['codtarifa' => 'T2', 'nombre' => 'Otra', 'coddivisa' => 'USD'],
        ];
        $controller->tarifa_seleccionada = (object) ['codtarifa' => 'T1', 'nombre' => 'Base', 'coddivisa' => 'EUR'];
        $controller->tarifa_defecto = false;
        $controller->request = Request::create('/index.php?page=ventas_opcionales', 'POST', [
            'codigo' => 'OPC0002',
            'nombre' => 'Nuevo',
            'precio_tarifa_T1' => '1,50',
            'precio_tarifa_T2' => '2,50',
        ]);

        $controller->exposeNewOpcional();

        $this->assertTrue($opcional->saved, 'the valid payload must be persisted');
        $this->assertSame(
            ['T1' => 1.5, 'T2' => 2.5],
            $opcional->precios,
            'with the tarifa context loaded both create-modal per-tarifa values must persist'
        );
    }

    /** Extracts a method body by brace matching from its signature. */
    private function methodBody(string $source, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/';
        if (!preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $matches[0][1] + strlen($matches[0][0]);
        $depth = 1;
        $length = strlen($source);
        for ($i = $start; $i < $length; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        return '';
    }
}

/**
 * Minimal opcional-model double for the create-ordering regression. Records
 * the persisted families and per-tarifa prices so the test can prove the
 * create-modal values reach the writer.
 */
final class CreateOrderingOpcionalStub
{
    public bool $saved = false;

    /** @var list<string> */
    public array $familias = [];

    /** @var array<string, float> */
    public array $precios = [];

    /** @var int|null */
    public $id_grupo = null;

    public function get_new_codigo(): string
    {
        return 'OPC0002';
    }

    public function get_by_codigo($codigo)
    {
        return false;
    }

    public function save(): bool
    {
        $this->saved = true;

        return true;
    }

    public function add_familia($codfamilia): bool
    {
        $this->familias[] = (string) $codfamilia;

        return true;
    }

    public function set_precio_tarifa($codtarifa, $precio): bool
    {
        $this->precios[(string) $codtarifa] = (float) $precio;

        return true;
    }

    public function set_porcentaje_tarifa($codtarifa, $porcentaje): bool
    {
        return true;
    }
}
