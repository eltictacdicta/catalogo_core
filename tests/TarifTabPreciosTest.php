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

use FSFramework\model\tarif_articulo_precio;
use FSFramework\model\tarif_tarifa;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Tests for tarif_tab_precios — the catalogo_core endpoint that serves and
 * saves the per-tarifa price rows injected into the host ventas_articulo page.
 * Relocated from tarifario when the article surface moved to catalogo_core
 * (WU-1, AD-2/AD-3/AD-9).
 *
 * Scoped scenarios: S11 (per-tarifa editing), S21 persistence-side (deny →
 * save unreachable), S27 (rows use the row tariff's own coddivisa), S28 (no
 * bare fsc.simbolo_divisa() calls in hook templates), CSRF-failure path, the
 * neutral permission gate default-allow contract (ATT-07) and the structural
 * contract (extends fbase_controller + guard-before-save).
 *
 * Mocking strategy (cf. TarifOpcionalTabEndpointTest): an anonymous subclass of
 * the controller skips the parent constructor (no DB, no FS init), overrides
 * private_core() to a no-op and exposes reflection wrappers for the private
 * action methods. The permission guard is stubbed at the inherited
 * puede_editar_articulo() seam; the price model is a tracked anonymous subclass
 * injected through the controller's precio_model() seam so every
 * get()/save()/delete() call is observable.
 */
final class TarifTabPreciosTest extends TestCase
{
    /** @var object Controller under test (anonymous subclass of tarif_tab_precios). */
    private $controller;
    /** @var object Tracked article price model (anonymous subclass of tarif_articulo_precio). */
    public $trackedModel;

    public bool $guardAllowed = true;
    public int $guardCalls = 0;
    public int $getCalls = 0;
    public int $saveCount = 0;
    public int $deleteCount = 0;
    public ?float $lastSavedPrecio = null;
    public string $lastSavedCodtarifa = '';
    public string $lastSavedReferencia = '';
    public ?string $lastDeletedCodtarifa = null;
    public array $pricesByTarifa = [];
    public array $divisaCalls = [];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: string, 4: bool}> */
    public array $storeAssignments = [];

    /** @var array<string, array<string, bool>> codtarifa => codigo => value */
    public array $visibilityByTarifa = [];

    /** @var array{0: bool, 1: bool}|null [en_tarifa, en_catalogo] of the last saved price row */
    public ?array $lastSavedVisibilityFlags = null;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Legacy base classes are not autoloaded; load the chain the same way
        // the controller file does. The controller file itself is only required
        // when it exists so the RED phase reports "class not found" per test
        // instead of a fatal require_once error.
        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . '/base/fs_app.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/extras/fs_divisa_tools.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_articulo_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
        if (file_exists(FS_FOLDER . '/plugins/catalogo_core/controller/tarif_tab_precios.php')) {
            require_once FS_FOLDER . '/plugins/catalogo_core/controller/tarif_tab_precios.php';
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
        $this->controller = $this->buildController();
        $this->injectTwigWithRealPartials();
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
        $this->lastSavedReferencia = '';
        $this->lastDeletedCodtarifa = null;
        $this->pricesByTarifa = [];
        $this->divisaCalls = [];
        $this->storeAssignments = [];
        $this->visibilityByTarifa = [];
        $this->lastSavedVisibilityFlags = null;
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
        return new class($outer) extends tarif_articulo_precio {
            public $outer;
            public function __construct($outer)
            {
                // Skip parent::__construct to avoid the DB connection in
                // fs_model::__construct (which creates new fs_db2()).
                $this->outer = $outer;
                $this->table_name = 'tarif_articulo_precios';
                $this->referencia = null;
                $this->codtarifa = null;
                $this->precio = 0.0;
                $this->activo = true;
                $this->en_tarifa = false;
                $this->en_catalogo = false;
                $this->en_sap = false;
            }
            public function get($ref, $codtarifa)
            {
                $this->outer->getCalls++;
                // DB faithfulness (F2 regression setup): no row can have an
                // empty referencia, so get('') must return FALSE.
                if ($ref === '') {
                    return FALSE;
                }
                if (!array_key_exists($codtarifa, $this->outer->pricesByTarifa)) {
                    return FALSE;
                }
                // Fresh snapshot per row: the fragment holds one object per
                // tarifa, so sharing the tracked instance would cross-wire
                // the rendered values.
                $row = new static($this->outer);
                $row->referencia = $ref;
                $row->codtarifa = $codtarifa;
                $row->precio = $this->outer->pricesByTarifa[$codtarifa];
                return $row;
            }
            public function save(): bool
            {
                $this->outer->saveCount++;
                $this->outer->lastSavedPrecio = $this->precio;
                $this->outer->lastSavedCodtarifa = $this->codtarifa;
                $this->outer->lastSavedReferencia = $this->referencia;
                $this->outer->lastSavedVisibilityFlags = [$this->en_tarifa, $this->en_catalogo];
                return TRUE;
            }
            public function delete(): bool
            {
                $this->outer->deleteCount++;
                $this->outer->lastDeletedCodtarifa = $this->codtarifa;
                // DB faithfulness: the delete removes the row, so subsequent
                // get() calls return FALSE.
                unset($this->outer->pricesByTarifa[$this->codtarifa]);
                return TRUE;
            }
            public function exists(): bool { return TRUE; }
        };
    }

    private function buildController(): object
    {
        $outer = $this;
        $controller = new class($outer) extends \tarif_tab_precios {
            public $outer;
            public function __construct($outer)
            {
                // Skip parent::__construct — heavy FS init would try to
                // connect to a DB and boot the menu.
                $this->outer = $outer;
                // Tests that render rows set the active tarifas explicitly.
                $this->tarifas = [];
            }
            public function private_core(): void
            {
                // no-op: the action methods under test are invoked directly.
            }
            public function puede_editar_articulo(string $referencia, string $codtarifa): bool
            {
                $this->outer->guardCalls++;
                return $this->outer->guardAllowed;
            }
            public function simbolo_divisa($coddivisa = FALSE)
            {
                $this->outer->divisaCalls[] = $coddivisa;
                return parent::simbolo_divisa($coddivisa);
            }
            public function precio_model(): tarif_articulo_precio
            {
                return $this->outer->trackedModel;
            }
            /**
             * ATT-02 (WU-4): visibility persists as articulo-scope feature
             * values through the store, and is read from the resolver.
             */
            protected function caracteristica_store()
            {
                return new class($this->outer) {
                    public function __construct(private $outer)
                    {
                    }

                    public function assign_bool(string $scope, string $codtarifa, array $key, string $codigo, bool $valor): bool
                    {
                        $this->outer->storeAssignments[] = [$scope, $codtarifa, $key, $codigo, $valor];

                        return true;
                    }
                };
            }
            protected function caracteristica_resolver()
            {
                return new class($this->outer) {
                    public function __construct(private $outer)
                    {
                    }

                    public function resolve_bool(string $codigo, string $codtarifa, ?string $referencia = null, ?string $codfamilia = null): ?bool
                    {
                        return (bool) ($this->outer->visibilityByTarifa[(string) $codtarifa][$codigo] ?? false);
                    }
                };
            }
            public function call_guardar_precio_tab(): void
            {
                $method = new \ReflectionMethod(\tarif_tab_precios::class, 'guardar_precio_tab');
                $method->setAccessible(true);
                $method->invoke($this);
            }
            public function call_render_rows_action(): void
            {
                $method = new \ReflectionMethod(\tarif_tab_precios::class, 'render_rows_action');
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

        // Wire a POST request so requireCsrf() sees a POST method.
        $requestProp = new \ReflectionProperty(\fs_controller::class, 'request');
        $requestProp->setAccessible(true);
        $requestProp->setValue($controller, Request::create('/index.php?page=tarif_tab_precios', 'POST', []));

        // Simulate the successful pre_private_core() CSRF validation by default;
        // CSRF-failure tests flip this to false (what validateCsrf() leaves behind
        // on a missing/invalid token).
        $controller->setCsrfValid(true);

        // Wire fs_app::$core_log (instance) and fs_controller::$class_name so
        // new_error_msg() pushes into the in-memory error list.
        $coreLogProp = new \ReflectionProperty(\fs_app::class, 'core_log');
        $coreLogProp->setAccessible(true);
        $coreLogProp->setValue($controller, new \fs_core_log(\tarif_tab_precios::class));

        $classNameProp = new \ReflectionProperty(\fs_controller::class, 'class_name');
        $classNameProp->setAccessible(true);
        $classNameProp->setValue($controller, \tarif_tab_precios::class);

        // simbolo_divisa() delegates to divisa_tools (loaded from catalogo_core).
        // The instance builds its symbol table lazily without DB when the
        // `divisa` model class is absent, falling back to default symbols.
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
                    // Skip parent::__construct (no DB).
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

    private function injectTwigWithRealPartials(): void
    {
        $dir = FS_FOLDER . '/plugins/catalogo_core/View/Hooks/partials';
        $templates = [];
        foreach (['articulo_precios_rows.html.twig'] as $name) {
            $path = $dir . '/' . $name;
            if (is_file($path)) {
                $templates['@catalogo_core/Hooks/partials/' . $name] = (string) file_get_contents($path);
            }
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
        // The legacy controller reads $_POST/$_REQUEST directly (legacy style);
        // the Request object is replaced too so requireCsrf() sees a POST.
        $_POST = $fields;
        $_REQUEST = array_merge($_REQUEST, $fields);
        $requestProp = new \ReflectionProperty(\fs_controller::class, 'request');
        $requestProp->setAccessible(true);
        $requestProp->setValue(
            $this->controller,
            Request::create('/index.php?page=tarif_tab_precios', 'POST', $fields)
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
            Request::create('/index.php?page=tarif_tab_precios', 'GET', $_REQUEST)
        );

        ob_start();
        $this->controller->call_render_rows_action();
        return (string) ob_get_clean();
    }

    // =====================================================================
    // Deny path — S21 (persistence side): save() unreachable, clear error
    // =====================================================================

    public function test_deny_blocks_price_save_and_responds_json_error(): void
    {
        $this->guardAllowed = false;
        $payload = $this->postGuard([
            'guardar_precio_tab' => '1',
            'tipo' => 'articulo',
            'referencia' => 'REF-1',
            'codtarifa' => 'USD1',
            'precio' => '19.99',
        ]);

        $this->assertFalse($payload['ok'], 'Denied save must answer ok=false');
        $this->assertStringContainsStringIgnoringCase('permiso', $payload['message']);
        $this->assertSame(1, $this->guardCalls, 'Permission guard must be consulted exactly once');
        $this->assertSame(0, $this->getCalls, 'Price model must not be touched on deny');
        $this->assertSame(0, $this->saveCount, 'tarif_articulo_precio::save() must be unreachable on deny');
        $this->assertSame(0, $this->deleteCount, 'Price model delete must be unreachable on deny');
    }

    /**
     * ATT-02 scenario 3: a denied verdict must not mutate the stored row.
     */
    public function test_denied_save_leaves_stored_values_unchanged(): void
    {
        $this->guardAllowed = false;
        $this->pricesByTarifa = ['USD1' => 19.99];

        $payload = $this->postGuard([
            'guardar_precio_tab' => '1',
            'referencia' => 'REF-1',
            'codtarifa' => 'USD1',
            'precio' => '',
        ]);

        $this->assertFalse($payload['ok']);
        $this->assertSame(
            ['USD1' => 19.99],
            $this->pricesByTarifa,
            'A denied save must leave the stored price/state unchanged'
        );
        $this->assertSame(0, $this->saveCount, 'No save may happen on deny');
        $this->assertSame(0, $this->deleteCount, 'No delete may happen on deny');
    }

    // =====================================================================
    // Allow path — S11: per-tarifa edit persists through get()+save()
    // =====================================================================

    public function test_allow_saves_price_via_articulo_precio_get_and_save(): void
    {
        // Seed an existing price row so the edit flow stays on the tracked
        // get()+save() machinery (the S11 scenario is editing, not creating).
        $this->pricesByTarifa = ['USD1' => 999.0];

        $payload = $this->postGuard([
            'guardar_precio_tab' => '1',
            'tipo' => 'articulo',
            'referencia' => 'REF-1',
            'codtarifa' => 'USD1',
            'precio' => '1234,56',
            'activo' => '1',
            'en_tarifa' => '1',
        ]);

        $this->assertTrue($payload['ok'], 'Allowed save must answer ok=true');
        $this->assertSame(1, $this->guardCalls);
        $this->assertSame(1, $this->getCalls, 'Save must go through tarif_articulo_precio::get()');
        $this->assertSame(1, $this->saveCount, 'tarif_articulo_precio::save() must be invoked');
        $this->assertSame(1234.56, $this->lastSavedPrecio, 'Comma decimal input must be parsed');
        $this->assertSame('USD1', $this->lastSavedCodtarifa);
        $this->assertSame('REF-1', $this->lastSavedReferencia);
        $this->assertArrayHasKey('html', $payload);
        $this->assertNotSame('', $payload['html'], 'Response must carry the re-rendered rows fragment');
    }

    public function test_visibility_controls_write_articulo_scope_feature_values(): void
    {
        $this->pricesByTarifa = ['USD1' => 999.0];

        $payload = $this->postGuard([
            'guardar_precio_tab' => '1',
            'tipo' => 'articulo',
            'referencia' => 'REF-1',
            'codtarifa' => 'USD1',
            'precio' => '10',
            'en_catalogo' => '1',
        ]);

        $this->assertTrue($payload['ok']);

        $byCodigo = [];
        foreach ($this->storeAssignments as $assignment) {
            $byCodigo[$assignment[3]] = $assignment;
        }

        $this->assertArrayHasKey('en_catalogo', $byCodigo, 'the catalog control must write a feature value');
        $this->assertSame('articulo', $byCodigo['en_catalogo'][0], 'ATT-02: the write is articulo-scope');
        $this->assertSame('USD1', $byCodigo['en_catalogo'][1]);
        $this->assertSame(['referencia' => 'REF-1'], $byCodigo['en_catalogo'][2]);
        $this->assertTrue($byCodigo['en_catalogo'][4], 'the checked control materializes TRUE');

        $this->assertArrayHasKey('en_tarifa', $byCodigo, 'the tarifa control must write a feature value');
        $this->assertFalse($byCodigo['en_tarifa'][4], 'an unchecked control materializes FALSE');
    }

    public function test_visibility_write_never_touches_the_legacy_price_columns(): void
    {
        $this->pricesByTarifa = ['USD1' => 999.0];

        $this->postGuard([
            'guardar_precio_tab' => '1',
            'tipo' => 'articulo',
            'referencia' => 'REF-1',
            'codtarifa' => 'USD1',
            'precio' => '10',
            'en_catalogo' => '1',
            'en_tarifa' => '1',
        ]);

        $this->assertFalse(
            $this->lastSavedVisibilityFlags[0] ?? true,
            'the legacy tarif_articulo_precios.en_tarifa column must not be written by the tab'
        );
        $this->assertFalse(
            $this->lastSavedVisibilityFlags[1] ?? true,
            'the legacy tarif_articulo_precios.en_catalogo column must not be written by the tab'
        );
    }

    public function test_blank_price_deletes_the_row_and_keeps_the_feature_values(): void
    {
        $this->pricesByTarifa = ['USD1' => 999.0];

        $payload = $this->postGuard([
            'guardar_precio_tab' => '1',
            'tipo' => 'articulo',
            'referencia' => 'REF-1',
            'codtarifa' => 'USD1',
            'precio' => '',
            'en_catalogo' => '1',
        ]);

        $this->assertTrue($payload['ok']);
        $this->assertSame(1, $this->deleteCount, 'a blank price must delete the per-tarifa price row');
        $this->assertSame(0, $this->saveCount, 'no price row may be written for a blank price');

        $codigos = array_map(static fn (array $a): string => $a[3], $this->storeAssignments);
        $this->assertContains('en_catalogo', $codigos, 'the feature value must still be materialized');
        $this->assertContains('en_tarifa', $codigos);
    }

    // =====================================================================
    // CSRF failure path — token missing/invalid → no save, error response
    // =====================================================================
    public function test_csrf_failure_rejects_before_model_access(): void
    {
        $this->controller->setCsrfValid(false);
        $payload = $this->postGuard([
            'guardar_precio_tab' => '1',
            'tipo' => 'articulo',
            'referencia' => 'REF-1',
            'codtarifa' => 'USD1',
            'precio' => '19.99',
        ]);

        $this->assertFalse($payload['ok'], 'CSRF failure must answer ok=false');
        $this->assertSame(0, $this->getCalls, 'CSRF failure must reject before model access');
        $this->assertSame(0, $this->saveCount, 'No save may happen without a valid CSRF token');
        $this->assertSame(0, $this->guardCalls, 'CSRF check must precede the permission guard');
        $this->assertSame('', $payload['html'], 'No rows fragment on CSRF failure');
    }

    // =====================================================================
    // Neutral permission gate — ATT-07: zero listeners ⇒ default-allow
    // =====================================================================

    public function test_permission_gate_dispatches_neutral_event_and_defaults_allow(): void
    {
        \FSFramework\Event\FSEventDispatcher::reset();

        $controller = new class() extends \tarif_tab_precios {
            public function __construct()
            {
            }
            public function private_core(): void
            {
            }
            public function setUser(object $user): void
            {
                $this->user = $user;
            }
            public function call_guard(string $referencia, string $codtarifa): bool
            {
                return $this->puede_editar_articulo($referencia, $codtarifa);
            }
        };
        $controller->setUser((object) ['admin' => false, 'nick' => 'user1']);

        $this->assertTrue(
            $controller->call_guard('REF-1', 'USD1'),
            'The neutral gate must default-allow when no listener is registered (ATT-07)'
        );
    }

    // =====================================================================
    // Currency-aware rows — S27: each row uses its own tariff coddivisa
    // =====================================================================

    public function test_rows_fragment_renders_row_tariff_coddivisa(): void
    {
        $this->setTarifas([
            ['codtarifa' => 'EUR1', 'nombre' => 'Euro', 'coddivisa' => 'EUR'],
            ['codtarifa' => 'USD1', 'nombre' => 'Dólar', 'coddivisa' => 'USD'],
        ]);
        $this->pricesByTarifa = ['EUR1' => 9.99, 'USD1' => 12.5];

        $html = $this->getRows(['ref' => 'REF-1']);

        $this->assertNotSame('', $html, 'Rows action must echo the rendered fragment');
        $this->assertContains('EUR', $this->divisaCalls, 'EUR row must render via simbolo_divisa(EUR)');
        $this->assertContains('USD', $this->divisaCalls, 'USD row must render via simbolo_divisa(USD)');
        $this->assertStringContainsString('$', $html, 'USD tariff row must show the dollar symbol');
        $this->assertStringContainsString('€', $html, 'EUR tariff row must show the euro symbol');
        $this->assertStringContainsString('12.5', $html, 'Saved USD price must appear in the rows');
        $this->assertStringContainsString('9.99', $html, 'Saved EUR price must appear in the rows');
    }

    // =====================================================================
    // Verify F2 regression — save/delete responses must re-render the rows
    // fragment with the REAL row context ($this->rows_ref), so the client
    // swap reflects saved values in place and the save buttons carry
    // data-referencia. Before the fix the fragment rendered with rows_ref=''
    // (blank rows + second save deletes the just-saved price).
    // =====================================================================

    public function test_save_response_rerenders_rows_with_real_referencia_and_saved_value(): void
    {
        $this->setTarifas([
            ['codtarifa' => 'USD1', 'nombre' => 'Dólar', 'coddivisa' => 'USD'],
        ]);
        $this->pricesByTarifa = ['USD1' => 1234.56];

        $payload = $this->postGuard([
            'guardar_precio_tab' => '1',
            'tipo' => 'articulo',
            'referencia' => 'REF-1',
            'codtarifa' => 'USD1',
            'precio' => '1234,56',
        ]);

        $this->assertTrue($payload['ok']);
        $this->assertSame(1, $this->saveCount);
        $this->assertStringContainsString(
            'data-referencia="REF-1"',
            $payload['html'],
            'F2: the save response fragment must echo the real referencia'
        );
        $this->assertStringContainsString(
            '1234.56',
            $payload['html'],
            'F2: the saved price must be reflected in the re-rendered row'
        );
    }

    public function test_delete_branch_response_rerenders_rows_with_real_context(): void
    {
        $this->setTarifas([
            ['codtarifa' => 'USD1', 'nombre' => 'Dólar', 'coddivisa' => 'USD'],
            ['codtarifa' => 'EUR1', 'nombre' => 'Euro', 'coddivisa' => 'EUR'],
        ]);
        $this->pricesByTarifa = ['USD1' => 19.99, 'EUR1' => 9.99];

        $payload = $this->postGuard([
            'guardar_precio_tab' => '1',
            'tipo' => 'articulo',
            'referencia' => 'REF-1',
            'codtarifa' => 'USD1',
            'precio' => '',
        ]);

        $this->assertTrue($payload['ok']);
        $this->assertSame(1, $this->deleteCount);
        $this->assertStringContainsString(
            'data-referencia="REF-1"',
            $payload['html'],
            'F2: the delete response fragment must echo the real referencia'
        );
        $this->assertStringNotContainsString('19.99', $payload['html'], 'F2: the deleted price must be gone');
        $this->assertStringContainsString('9.99', $payload['html'], 'F2: the other row must stay intact');
    }

    // =====================================================================
    // S28 — no bare fsc.simbolo_divisa() calls in hook templates
    // =====================================================================

    public function test_no_bare_simbolo_divisa_calls_in_hook_partials(): void
    {
        $articuloPartial = FS_FOLDER . '/plugins/catalogo_core/View/Hooks/partials/articulo_precios_rows.html.twig';
        $this->assertFileExists($articuloPartial, 'catalogo_core must own the article rows partial');

        $hooksDir = FS_FOLDER . '/plugins/catalogo_core/View/Hooks';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($hooksDir));
        $bare = [];
        foreach ($iterator as $file) {
            if (!$file->isFile() || !in_array($file->getExtension(), ['twig', 'html'], true)) {
                continue;
            }
            $content = (string) file_get_contents($file->getPathname());
            if (preg_match('/fsc\.simbolo_divisa\(\s*\)/', $content)) {
                $bare[] = $file->getPathname();
            }
        }
        $this->assertSame([], $bare, 'Bare fsc.simbolo_divisa() calls are forbidden under View/Hooks (S28)');
    }

    // =====================================================================
    // Structural contract: extends fbase_controller, trait, guard-before-save
    // =====================================================================

    public function test_controller_structure_enforces_guard_before_save(): void
    {
        $path = FS_FOLDER . '/plugins/catalogo_core/controller/tarif_tab_precios.php';
        $this->assertFileExists($path);
        $src = (string) file_get_contents($path);

        $this->assertStringContainsString(
            'class tarif_tab_precios extends fbase_controller',
            $src,
            'The endpoint must extend catalogo_core fbase_controller (AD-2), not tarif_controller'
        );
        $this->assertStringContainsString(
            'use TarifarioOpcionalStateTrait;',
            $src,
            'The endpoint must reuse the per-tarifa state trait for $tarifas/$codtarifa'
        );
        $this->assertStringContainsString('requireCsrf', $src, 'Mutating flow must carry token validation');
        $this->assertStringContainsString('template = FALSE', $src, 'JSON/fragment answers need template = FALSE');

        $guardPos = strpos($src, 'puede_editar_articulo($referencia, $codtarifa)');
        $savePos = strpos($src, '->save(');
        $this->assertNotFalse($guardPos, 'Controller must enforce puede_editar_articulo($referencia, $codtarifa)');
        $this->assertNotFalse($savePos, 'Controller must persist via the price model save()');
        $this->assertLessThan(
            $savePos,
            $guardPos,
            'The permission guard must run before any model save() call (deny → save unreachable)'
        );
    }
}
