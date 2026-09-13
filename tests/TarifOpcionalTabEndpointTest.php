<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace Tests\CatalogoCore;

require_once FS_FOLDER . '/base/fs_model.php';
require_once FS_FOLDER . '/base/fs_core_log.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional.php';

use FSFramework\model\tarif_tarifa;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Behavior coverage for tarif_opcional_tab — the catalogo_core endpoint that
 * serves and saves the per-tarifa price rows of the opcional Tarifas tab
 * (OUM-10, AD-7). Relocated from tarifario's TarifTabPreciosTest when the
 * opcional surface moved to catalogo_core (WU-4).
 *
 * Scoped scenarios: S27 (rows use the row tariff's own coddivisa), S28 (no
 * bare fsc.simbolo_divisa() calls in the moved hooks tree), S21 persistence
 * side (deny → save unreachable), CSRF-failure path, and the F2 re-render
 * contract (rows_ref echoed so a second save cannot blank the row).
 *
 * Mocking strategy mirrors TarifTabPreciosTest: an anonymous subclass of the
 * controller skips the parent constructor (no DB), overrides private_core() to
 * a no-op and exposes reflection wrappers for the action methods. The
 * permission guard and the price model are stubbed at the preserved seams.
 */
final class TarifOpcionalTabEndpointTest extends TestCase
{
    /** @var object Controller under test (anonymous subclass of tarif_opcional_tab). */
    private $controller;
    /** @var object Tracked opcional price model (anonymous subclass of tarif_opcional_precio). */
    public $trackedModel;

    public bool $guardAllowed = true;
    public int $guardCalls = 0;
    public int $getCalls = 0;
    public int $saveCount = 0;
    public int $deleteCount = 0;
    public ?float $lastSavedPrecio = null;
    public string $lastSavedCodtarifa = '';
    public ?int $lastSavedIdOpcional = null;
    public ?bool $lastSavedEnCatalogo = null;
    public ?float $lastSavedPorcentaje = null;
    public bool $opcionalEsPorcentaje = false;
    public ?TarifOpcionalTabOpcionalStub $opcionalStub = null;
    public ?int $lastDeletedIdOpcional = null;
    public array $pricesByTarifa = [];
    public array $divisaCalls = [];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/base/fs_app.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/extras/fs_divisa_tools.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
        if (file_exists(FS_FOLDER . '/plugins/catalogo_core/controller/tarif_opcional_tab.php')) {
            require_once FS_FOLDER . '/plugins/catalogo_core/controller/tarif_opcional_tab.php';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        $this->resetState();
        $this->resetCoreLog();
        $this->trackedModel = $this->buildTrackedModel();
        $this->opcionalStub = new TarifOpcionalTabOpcionalStub();
        $this->controller = $this->buildController();
        $this->injectTwigWithRealPartial();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        $this->restoreHtmlTwig();
        parent::tearDown();
    }

    private function resetState(): void
    {
        $this->guardAllowed = true;
        $this->guardCalls = 0;
        $this->getCalls = 0;
        $this->saveCount = 0;
        $this->deleteCount = 0;
        $this->lastSavedPrecio = null;
        $this->lastSavedCodtarifa = '';
        $this->lastSavedIdOpcional = null;
        $this->lastSavedEnCatalogo = null;
        $this->lastDeletedIdOpcional = null;
        $this->lastSavedPorcentaje = null;
        $this->opcionalEsPorcentaje = false;
        $this->pricesByTarifa = [];
        $this->divisaCalls = [];
    }

    private function resetCoreLog(): void
    {
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('core_log');
        $prop->setAccessible(true);
        if ($prop->getValue() === null) {
            $prop->setValue(null, new \fs_core_log());
        }
    }

    private function buildTrackedModel(): object
    {
        $outer = $this;
        return new class($outer) extends \FSFramework\model\tarif_opcional_precio {
            public $outer;
            public function __construct($outer)
            {
                // Skip parent::__construct (DB) exactly like TarifTabPreciosTest.
                $this->outer = $outer;
                $this->table_name = 'catalogo_opcional_precios';
                $this->id_opcional = NULL;
                $this->codtarifa = null;
                $this->precio = 0.0;
                $this->en_catalogo = false;
            }
            public function get($id_opcional, $codtarifa)
            {
                $this->outer->getCalls++;
                // DB faithfulness (F2 regression setup): ids start at 1.
                if (intval($id_opcional) < 1) {
                    return FALSE;
                }
                if (!array_key_exists($codtarifa, $this->outer->pricesByTarifa)) {
                    return FALSE;
                }
                $row = new static($this->outer);
                $row->id_opcional = intval($id_opcional);
                $row->codtarifa = $codtarifa;
                $row->precio = $this->outer->pricesByTarifa[$codtarifa];
                return $row;
            }
            public function save(): bool
            {
                $this->outer->saveCount++;
                $this->outer->lastSavedPrecio = $this->precio;
                $this->outer->lastSavedCodtarifa = $this->codtarifa;
                $this->outer->lastSavedIdOpcional = $this->id_opcional === NULL ? null : intval($this->id_opcional);
                $this->outer->lastSavedEnCatalogo = $this->en_catalogo;
                $this->outer->lastSavedPorcentaje = $this->porcentaje === null ? null : (float) $this->porcentaje;
                return TRUE;
            }
            public function delete(): bool
            {
                $this->outer->deleteCount++;
                $this->outer->lastDeletedIdOpcional = $this->id_opcional === NULL ? null : intval($this->id_opcional);
                unset($this->outer->pricesByTarifa[$this->codtarifa]);
                return TRUE;
            }
            public function exists(): bool { return TRUE; }
        };
    }

    private function buildController(): object
    {
        $outer = $this;
        $controller = new class($outer) extends \tarif_opcional_tab {
            public $outer;
            public function __construct($outer)
            {
                // Skip parent::__construct — heavy FS init would try to
                // connect to a DB and boot the menu.
                $this->outer = $outer;
                $this->tarifas = [];
            }
            public function private_core(): void
            {
                // no-op: the action methods under test are invoked directly.
            }
            public function puede_editar_opcional(string $codtarifa): bool
            {
                $this->outer->guardCalls++;
                return $this->outer->guardAllowed;
            }
            public function simbolo_divisa($coddivisa = FALSE)
            {
                $this->outer->divisaCalls[] = $coddivisa;
                return parent::simbolo_divisa($coddivisa);
            }
            public function opcional_precio_model(): \FSFramework\model\tarif_opcional_precio
            {
                return $this->outer->trackedModel;
            }
            public function opcional_model(): \FSFramework\model\tarif_opcional
            {
                return $this->outer->opcionalStub;
            }
            public function call_guardar_precio_tab(): void
            {
                $method = new \ReflectionMethod(\tarif_opcional_tab::class, 'guardar_precio_tab');
                $method->setAccessible(true);
                $method->invoke($this);
            }
            public function call_render_rows_action(): void
            {
                $method = new \ReflectionMethod(\tarif_opcional_tab::class, 'render_rows_action');
                $method->setAccessible(true);
                $method->invoke($this);
            }
            public function setCsrfValid(bool $valid): void
            {
                $prop = new \ReflectionProperty(\fs_controller::class, 'csrf_valid');
                $prop->setAccessible(true);
                $prop->setValue($this, $valid);
            }
        };

        $requestProp = new \ReflectionProperty(\fs_controller::class, 'request');
        $requestProp->setAccessible(true);
        $requestProp->setValue($controller, Request::create('/index.php?page=tarif_opcional_tab', 'POST', []));

        $controller->setCsrfValid(true);

        $coreLogProp = new \ReflectionProperty(\fs_app::class, 'core_log');
        $coreLogProp->setAccessible(true);
        $coreLogProp->setValue($controller, new \fs_core_log(\tarif_opcional_tab::class));

        $classNameProp = new \ReflectionProperty(\fs_controller::class, 'class_name');
        $classNameProp->setAccessible(true);
        $classNameProp->setValue($controller, \tarif_opcional_tab::class);

        $divisaProp = new \ReflectionProperty(\fs_controller::class, 'divisa_tools');
        $divisaProp->setAccessible(true);
        $divisaProp->setValue($controller, new \fs_divisa_tools('EUR'));

        return $controller;
    }

    private function setTarifas(array $specs): void
    {
        $tarifas = [];
        foreach ($specs as $spec) {
            $tarifas[] = new class($spec) extends tarif_tarifa {
                public function __construct($spec)
                {
                    $this->table_name = 'tarif_tarifas';
                    $this->codtarifa = $spec['codtarifa'];
                    $this->nombre = $spec['nombre'];
                    $this->activa = true;
                    $this->por_defecto = false;
                    $this->coddivisa = $spec['coddivisa'];
                }
            };
        }
        $this->controller->tarifas = $tarifas;
    }

    private function injectTwigWithRealPartial(): void
    {
        $name = 'opcional_precios_rows.html.twig';
        $path = FS_FOLDER . '/plugins/catalogo_core/View/Hooks/partials/' . $name;
        $templates = [];
        if (is_file($path)) {
            $templates['@catalogo_core/Hooks/partials/' . $name] = (string) file_get_contents($path);
        }
        $twig = new Environment(new ArrayLoader($templates));
        $prop = new \ReflectionProperty(\FSFramework\Core\Html::class, 'twig');
        $prop->setAccessible(true);
        $prop->setValue(null, $twig);
    }

    private function restoreHtmlTwig(): void
    {
        $prop = new \ReflectionProperty(\FSFramework\Core\Html::class, 'twig');
        $prop->setAccessible(true);
        $prop->setValue(null, null);
    }

    private function postGuard(array $fields): array
    {
        $_POST = $fields;
        $_REQUEST = array_merge($_REQUEST, $fields);
        $requestProp = new \ReflectionProperty(\fs_controller::class, 'request');
        $requestProp->setAccessible(true);
        $requestProp->setValue(
            $this->controller,
            Request::create('/index.php?page=tarif_opcional_tab', 'POST', $fields)
        );

        ob_start();
        $this->controller->call_guardar_precio_tab();
        $json = (string) ob_get_clean();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'Endpoint must answer valid JSON, got: ' . $json);
        return $decoded;
    }

    private function getRows(array $query): string
    {
        $_GET = $query;
        $_REQUEST = array_merge(['action' => 'rows'], $query);
        $requestProp = new \ReflectionProperty(\fs_controller::class, 'request');
        $requestProp->setAccessible(true);
        $requestProp->setValue(
            $this->controller,
            Request::create('/index.php?page=tarif_opcional_tab', 'GET', $_REQUEST)
        );

        ob_start();
        $this->controller->call_render_rows_action();
        return (string) ob_get_clean();
    }

    /** S27: each row renders via the row tariff's own coddivisa. */
    public function test_rows_fragment_renders_row_tariff_coddivisa(): void
    {
        $this->setTarifas([
            ['codtarifa' => 'MXN1', 'nombre' => 'Peso', 'coddivisa' => 'MXN'],
        ]);
        $this->pricesByTarifa = ['MXN1' => 499.0];

        $html = $this->getRows(['ref' => '7']);

        $this->assertNotSame('', $html, 'Rows action must echo the rendered fragment');
        $this->assertStringContainsString('data-tipo="opcional"', $html, 'Fragment must be the opcional partial');
        $this->assertStringContainsString('data-referencia="7"', $html, 'Fragment must carry the opcional id');
        $this->assertContains('MXN', $this->divisaCalls, 'MXN row must render via simbolo_divisa(MXN)');
        $this->assertStringContainsString('MX$', $html, 'MXN tariff row must show the peso symbol');
        $this->assertStringContainsString('499', $html, 'Saved MXN price must appear in the rows');
    }

    /** S21 persistence side: non-admin/non-gestor → model untouched. */
    public function test_save_denied_without_admin_or_gestor_role(): void
    {
        $this->guardAllowed = false;
        $payload = $this->postGuard([
            'guardar_precio_tab' => '1',
            'referencia' => '7',
            'codtarifa' => 'MXN1',
            'precio' => '499',
        ]);

        $this->assertFalse($payload['ok'], 'Denied save must answer ok=false');
        $this->assertStringContainsStringIgnoringCase('permiso', $payload['message']);
        $this->assertSame(1, $this->guardCalls, 'Admin/gestor guard must be consulted');
        $this->assertSame(0, $this->getCalls, 'Price model must not be touched on deny');
        $this->assertSame(0, $this->saveCount, 'save() must be unreachable on deny');
        $this->assertSame(0, $this->deleteCount, 'delete() must be unreachable on deny');
    }

    /** CSRF failure: reject before any guard/model access. */
    public function test_csrf_failure_rejects_before_model_access(): void
    {
        $this->controller->setCsrfValid(false);
        $payload = $this->postGuard([
            'guardar_precio_tab' => '1',
            'referencia' => '7',
            'codtarifa' => 'MXN1',
            'precio' => '499',
        ]);

        $this->assertFalse($payload['ok'], 'CSRF failure must answer ok=false');
        $this->assertSame(0, $this->getCalls, 'CSRF failure must reject before model access');
        $this->assertSame(0, $this->saveCount, 'No save may happen without a valid CSRF token');
        $this->assertSame(0, $this->guardCalls, 'CSRF check must precede the permission guard');
        $this->assertSame('', $payload['html'], 'No rows fragment on CSRF failure');
    }

    /** Allowed save persists precio + en_catalogo, and re-renders with the real id. */
    public function test_save_persists_precio_and_en_catalogo(): void
    {
        $this->setTarifas([
            ['codtarifa' => 'MXN1', 'nombre' => 'Peso', 'coddivisa' => 'MXN'],
        ]);
        // Post-save snapshot: the tracked get() reflects the persisted value so
        // the re-rendered fragment (F2) shows it.
        $this->pricesByTarifa = ['MXN1' => 499.5];

        $payload = $this->postGuard([
            'guardar_precio_tab' => '1',
            'referencia' => '7',
            'codtarifa' => 'MXN1',
            'precio' => '499,50',
            'en_catalogo' => '1',
        ]);

        $this->assertTrue($payload['ok'], 'Allowed save must answer ok=true');
        $this->assertSame(1, $this->guardCalls);
        // Once for the save lookup and once for the re-rendered rows fragment.
        $this->assertSame(2, $this->getCalls, 'Save must go through tarif_opcional_precio::get()');
        $this->assertSame(1, $this->saveCount, 'tarif_opcional_precio::save() must be invoked');
        $this->assertSame(499.50, $this->lastSavedPrecio, 'Comma decimal input must be parsed');
        $this->assertSame('MXN1', $this->lastSavedCodtarifa);
        $this->assertSame(7, $this->lastSavedIdOpcional);
        $this->assertTrue($this->lastSavedEnCatalogo, 'en_catalogo flag must reach the model');
        $this->assertStringContainsString('data-referencia="7"', $payload['html'], 'F2: response must echo the real id');
        $this->assertStringContainsString('499.5', $payload['html'], 'F2: the saved price must be reflected');
    }

    /** OPG-01: a percentage-mode row persists `porcentaje` and zeroes `precio`. */
    public function test_percentage_row_persists_porcentaje_and_zeroes_precio(): void
    {
        $this->setTarifas([
            ['codtarifa' => 'MXN1', 'nombre' => 'Peso', 'coddivisa' => 'MXN'],
        ]);
        $this->opcionalStub->esPorcentaje = true;

        $payload = $this->postGuard([
            'guardar_precio_tab' => '1',
            'referencia' => '7',
            'codtarifa' => 'MXN1',
            'modo_porcentaje' => '1',
            'porcentaje' => '7,5',
            'en_catalogo' => '1',
        ]);

        $this->assertTrue($payload['ok'], 'A percentage row must be accepted');
        $this->assertSame(1, $this->saveCount, 'tarif_opcional_precio::save() must be invoked');
        $this->assertSame(7.5, $this->lastSavedPorcentaje, 'Comma decimal percentage must be parsed');
        $this->assertSame(0.0, $this->lastSavedPrecio, 'A percentage row must zero the stored price');
        $this->assertSame('MXN1', $this->lastSavedCodtarifa);
        $this->assertSame(7, $this->lastSavedIdOpcional);
    }

    /** Delete branch: empty price removes the row and re-renders with the real id. */
    public function test_delete_branch_rerenders_with_real_context(): void
    {
        $this->setTarifas([
            ['codtarifa' => 'MXN1', 'nombre' => 'Peso', 'coddivisa' => 'MXN'],
            ['codtarifa' => 'EUR2', 'nombre' => 'Euro', 'coddivisa' => 'EUR'],
        ]);
        $this->pricesByTarifa = ['MXN1' => 499.0, 'EUR2' => 12.5];

        $payload = $this->postGuard([
            'guardar_precio_tab' => '1',
            'referencia' => '7',
            'codtarifa' => 'MXN1',
            'precio' => '',
        ]);

        $this->assertTrue($payload['ok']);
        $this->assertSame(1, $this->deleteCount);
        $this->assertStringContainsString('data-referencia="7"', $payload['html'], 'F2: response must echo the real id');
        $this->assertStringNotContainsString('499', $payload['html'], 'F2: the deleted price must be gone');
        $this->assertStringContainsString('12.5', $payload['html'], 'F2: the other row must stay intact');
    }

    /** S28: no bare fsc.simbolo_divisa() calls in the moved hooks tree. */
    public function test_no_bare_simbolo_divisa_calls_in_hooks_partials(): void
    {
        $hooksDir = FS_FOLDER . '/plugins/catalogo_core/View/Hooks';
        $this->assertDirectoryExists($hooksDir, 'WU-4 must ship the moved hooks tree');

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($hooksDir));
        $bare = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), ['twig', 'html'], true)) {
                continue;
            }
            if (preg_match('/fsc\.simbolo_divisa\(\s*\)/', (string) file_get_contents($file->getPathname()))) {
                $bare[] = $file->getPathname();
            }
        }
        $this->assertSame([], $bare, 'Bare fsc.simbolo_divisa() calls are forbidden under View/Hooks (S28)');
    }
}

/**
 * Opcional double for the tab endpoint: resolves the price mode without a DB.
 */
final class TarifOpcionalTabOpcionalStub extends \FSFramework\model\tarif_opcional
{
    public bool $esPorcentaje = false;

    /** @var float|null */
    public $porcentaje = null;

    public function __construct()
    {
        // Skip the DB constructor.
    }

    public function es_precio_porcentaje(): bool
    {
        return $this->esPorcentaje;
    }

    public function get($id)
    {
        return $this;
    }
}
