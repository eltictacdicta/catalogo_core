<?php
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
declare(strict_types=1);

namespace Tests\CatalogoCore\Controller;

use FSFramework\model\tarif_articulo_precio;
use FSFramework\model\tarif_tarifa;
use FSFramework\Plugins\catalogo_core\Controller\VentasArticulo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Tests\CatalogoCore\Support\FakeArticuloDescripcion;
use Tests\CatalogoCore\Support\FakeCatalogoIdioma;
use Tests\CatalogoCore\Support\IdiomaRegistryFake;

/**
 * ART-10 / ATT-04 — scoped per-tarifa read for the unified article pane
 * (design AD-3, R3).
 *
 * The per-tarifa price/state surface keeps being served by the unchanged
 * `page=tarif_tab_precios` endpoint; the pane only adds an ADDITIVE read scope:
 * `codtarifa=<selected>` narrows the rendered rows to that single tariff. These
 * cases pin the scoped read at the endpoint seam:
 *
 *  - only the requested tariff row is rendered (`data-codtarifa`), never the
 *    other active tarifas;
 *  - `precio` and `activo` come from `tarif_articulo_precio::get()` for the
 *    scoped tarifa (and only that tarifa is read);
 *  - visibility is derived through `CaracteristicaResolver::resolve_bool()` at
 *    articulo scope, and rendering never persists anything (zero
 *    `assign_bool` / `save` / `delete`).
 *
 * The second half of the file pins the host save contract (ART-01/ART-11,
 * design AD-5): `editarArticulo()` writes `articulos.pvp` from the form ONLY
 * when no active tarifa is selected, so with a selected tarifa the stored base
 * price is left untouched while the rest of the article still persists.
 *
 * DB-free by design: an anonymous `tarif_tab_precios` subclass skips the
 * controller constructor, stubs the price/resolver/store seams and renders the
 * REAL rows partial through an ArrayLoader, exactly like
 * `TarifTabPreciosTest`; an anonymous `VentasArticulo` subclass runs the real
 * `editarArticulo()` body with the CSRF token, permission gate and post-save
 * side steps stubbed. In the current (unscoped / unconditional-pvp)
 * implementation the scoped-read and base-price assertions below MUST fail
 * first.
 */
final class VentasArticuloPerTarifaPaneTest extends TestCase
{
    /** @var object Controller under test (anonymous subclass of tarif_tab_precios). */
    private $controller;

    /** @var array<string, float> codtarifa => precio returned by the price model. */
    public array $pricesByTarifa = [];

    /** @var array<string, bool> codtarifa => activo returned by the price model. */
    public array $activeByTarifa = [];

    /** @var list<string> codtarifa values passed to tarif_articulo_precio::get(). */
    public array $getTarifas = [];

    /** @var list<string> coddivisa values passed to simbolo_divisa(). */
    public array $divisaCalls = [];

    /** @var list<array{0: string, 1: string, 2: ?string, 3: ?string}> resolve_bool calls. */
    public array $resolveCalls = [];

    /** @var array<string, array<string, bool>> codtarifa => codigo => resolved value. */
    public array $visibilityByTarifa = [];

    /** @var list<array{0: string, 1: string, 2: array<string, mixed>, 3: string, 4: bool}> assign_bool calls. */
    public array $storeAssignments = [];

    public int $saveCount = 0;
    public int $deleteCount = 0;

    /** @var object|null Anonymous VentasArticulo subclass for the host save harness. */
    private $hostController;

    /** @var object|null Tracked anonymous articulo standing in for the stored row. */
    public $trackedArticulo;

    /** @var IdiomaRegistryFake|null In-memory DB for the language registry and descriptions. */
    public $idiomaDb;

    /** @var int Article save() calls observed through the tracked model. */
    public int $articuloSaveCount = 0;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        // Legacy base classes are not autoloaded; load the chain the same way
        // the controller file does.
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
        $this->pricesByTarifa = [];
        $this->activeByTarifa = [];
        $this->getTarifas = [];
        $this->divisaCalls = [];
        $this->resolveCalls = [];
        $this->visibilityByTarifa = [];
        $this->storeAssignments = [];
        $this->saveCount = 0;
        $this->deleteCount = 0;
        $this->hostController = null;
        $this->trackedArticulo = null;
        $this->articuloSaveCount = 0;
        $this->resetCoreLog();
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

    private function resetCoreLog(): void
    {
        $ref = new \ReflectionClass(\fs_model::class);
        $prop = $ref->getProperty('core_log');
        $prop->setAccessible(true);
        if ($prop->getValue() === null) {
            $prop->setValue(null, new \fs_core_log());
        }
    }

    private function buildController(): object
    {
        $outer = $this;
        $controller = new class($outer) extends \tarif_tab_precios {
            public $outer;

            public function __construct($outer)
            {
                // Skip parent::__construct — heavy FS init would hit the DB.
                $this->outer = $outer;
                $this->tarifas = [];
                $this->codtarifa = '';
            }

            public function private_core(): void
            {
                // no-op: the action method under test is invoked directly.
            }

            public function precio_model(): tarif_articulo_precio
            {
                return $this->outer->trackedModel($this);
            }

            protected function caracteristica_resolver()
            {
                $outer = $this->outer;
                return new class($outer) {
                    public function __construct(private $outer)
                    {
                    }

                    public function resolve_bool(string $codigo, string $codtarifa, ?string $referencia = null, ?string $codfamilia = null): ?bool
                    {
                        $this->outer->resolveCalls[] = [$codigo, (string) $codtarifa, $referencia, $codfamilia];

                        return (bool) ($this->outer->visibilityByTarifa[(string) $codtarifa][$codigo] ?? false);
                    }
                };
            }

            protected function caracteristica_store()
            {
                $outer = $this->outer;
                return new class($outer) {
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

            public function simbolo_divisa($coddivisa = FALSE)
            {
                $this->outer->divisaCalls[] = $coddivisa;
                return parent::simbolo_divisa($coddivisa);
            }

            public function call_render_rows_action(): void
            {
                $method = new \ReflectionMethod(\tarif_tab_precios::class, 'render_rows_action');
                $method->setAccessible(true);
                $method->invoke($this);
            }
        };

        $requestProp = new \ReflectionProperty(\fs_controller::class, 'request');
        $requestProp->setAccessible(true);
        $requestProp->setValue($controller, Request::create('/index.php?page=tarif_tab_precios', 'GET', []));

        $coreLogProp = new \ReflectionProperty(\fs_app::class, 'core_log');
        $coreLogProp->setAccessible(true);
        $coreLogProp->setValue($controller, new \fs_core_log(\tarif_tab_precios::class));

        $classNameProp = new \ReflectionProperty(\fs_controller::class, 'class_name');
        $classNameProp->setAccessible(true);
        $classNameProp->setValue($controller, \tarif_tab_precios::class);

        $divisaProp = new \ReflectionProperty(\fs_controller::class, 'divisa_tools');
        $divisaProp->setAccessible(true);
        $divisaProp->setValue($controller, new \fs_divisa_tools('EUR'));

        return $controller;
    }

    /**
     * Builds the tracked price model for the controller's precio_model() seam.
     * Every get()/save()/delete() call is observable; get() returns a fresh row
     * per (referencia, codtarifa) with the configured precio/activo, and FALSE
     * when no price is configured (DB-faithful absence).
     */
    public function trackedModel(object $controller): tarif_articulo_precio
    {
        $outer = $this;
        return new class($outer) extends tarif_articulo_precio {
            public $outer;

            public function __construct($outer)
            {
                $this->outer = $outer;
                $this->table_name = 'tarif_articulo_precios';
                $this->referencia = null;
                $this->codtarifa = null;
                $this->precio = 0.0;
                $this->activo = true;
            }

            public function get($ref, $codtarifa)
            {
                $this->outer->getTarifas[] = (string) $codtarifa;
                if ($ref === '' || !array_key_exists($codtarifa, $this->outer->pricesByTarifa)) {
                    return FALSE;
                }
                $row = new static($this->outer);
                $row->referencia = $ref;
                $row->codtarifa = $codtarifa;
                $row->precio = $this->outer->pricesByTarifa[$codtarifa];
                $row->activo = $this->outer->activeByTarifa[$codtarifa] ?? true;
                return $row;
            }

            public function save(): bool
            {
                $this->outer->saveCount++;
                return TRUE;
            }

            public function delete(): bool
            {
                $this->outer->deleteCount++;
                return TRUE;
            }

            public function exists(): bool
            {
                return TRUE;
            }
        };
    }

    /**
     * @param array<int, array{codtarifa: string, nombre: string, coddivisa: string}> $specs
     */
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
        $twig = new \Twig\Environment(new \Twig\Loader\ArrayLoader($templates));
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

    /**
     * Invokes the real render_rows_action() with the given request query and
     * returns the echoed fragment.
     *
     * @param array<string, string> $query
     */
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

    /**
     * Extracts a scoped checkbox input tag from the fragment, so the assertion
     * reads the row of one tariff and not a sibling's.
     */
    private function checkboxInput(string $html, string $name, string $codtarifa): string
    {
        $pattern = '/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*data-codtarifa="'
            . preg_quote($codtarifa, '/') . '"[^>]*\/>/';
        if (preg_match($pattern, $html, $matches) !== 1) {
            return '';
        }
        return $matches[0];
    }

    /**
     * Two active tarifas with distinct prices — the scoping fixture. Any
     * unscoped render leaks the sibling row, which is the RED anchor.
     */
    private function twoTarifaFixture(): void
    {
        $this->setTarifas([
            ['codtarifa' => 'EUR1', 'nombre' => 'Euro', 'coddivisa' => 'EUR'],
            ['codtarifa' => 'USD1', 'nombre' => 'Dólar', 'coddivisa' => 'USD'],
        ]);
        $this->pricesByTarifa = ['EUR1' => 9.99, 'USD1' => 12.5];
    }

    // =====================================================================
    // ART-10 — scoped rows: only the requested tariff is rendered
    // =====================================================================

    /**
     * @return array<string, array{0: string}>
     */
    public static function scopedRequestProvider(): array
    {
        return [
            'USD scope hides EUR' => ['USD1'],
            'EUR scope hides USD' => ['EUR1'],
        ];
    }

    #[DataProvider('scopedRequestProvider')]
    public function test_scoped_rows_render_only_the_requested_tarifa(string $scoped): void
    {
        $this->twoTarifaFixture();
        $sibling = $scoped === 'USD1' ? 'EUR1' : 'USD1';

        $html = $this->getRows(['ref' => 'REF-1', 'codtarifa' => $scoped]);

        $this->assertNotSame('', $html, 'Rows action must echo the rendered fragment');
        $this->assertStringContainsString('data-referencia="REF-1"', $html);
        $this->assertStringContainsString(
            'data-codtarifa="' . $scoped . '"',
            $html,
            'The requested tariff row must be rendered'
        );
        $this->assertStringNotContainsString(
            'data-codtarifa="' . $sibling . '"',
            $html,
            'The scoped read must not render the other active tariff'
        );
    }

    // =====================================================================
    // ART-10 — precio/activo come from tarif_articulo_precio::get()
    // =====================================================================

    public function test_scoped_rows_read_precio_and_activo_from_the_price_model(): void
    {
        $this->twoTarifaFixture();
        $this->activeByTarifa = ['USD1' => false];

        $html = $this->getRows(['ref' => 'REF-1', 'codtarifa' => 'USD1']);

        // Only the scoped tariff may be read from the price model.
        $this->assertSame(['USD1'], $this->getTarifas, 'Only the scoped tariff row may be read');
        $this->assertStringContainsString('12.5', $html, 'The scoped row price comes from tarif_articulo_precio::get()');
        $this->assertStringNotContainsString('9.99', $html, 'The sibling tariff price must not leak');
        $this->assertStringNotContainsString('data-codtarifa="EUR1"', $html);

        $activo = $this->checkboxInput($html, 'activo', 'USD1');
        $this->assertNotSame('', $activo, 'The scoped row must expose the activo control');
        $this->assertStringNotContainsString('checked', $activo, 'activo=false from the model must render unchecked');
    }

    public function test_scoped_rows_check_activo_when_the_model_row_is_active(): void
    {
        $this->twoTarifaFixture();
        $this->activeByTarifa = ['USD1' => true];

        $html = $this->getRows(['ref' => 'REF-1', 'codtarifa' => 'USD1']);

        $this->assertSame(['USD1'], $this->getTarifas);
        $activo = $this->checkboxInput($html, 'activo', 'USD1');
        $this->assertNotSame('', $activo);
        $this->assertStringContainsString('checked', $activo, 'activo=true from the model must render checked');
        $this->assertStringNotContainsString('data-codtarifa="EUR1"', $html);
    }

    // =====================================================================
    // ART-10 — visibility resolved without persisting
    // =====================================================================

    public function test_scoped_rows_resolve_visibility_without_persisting(): void
    {
        $this->twoTarifaFixture();
        $this->visibilityByTarifa = [
            'USD1' => ['en_tarifa' => true, 'en_catalogo' => false],
            'EUR1' => ['en_tarifa' => true, 'en_catalogo' => true],
        ];

        $html = $this->getRows(['ref' => 'REF-1', 'codtarifa' => 'USD1']);

        // Scope anchor: the sibling tariff is neither rendered nor resolved.
        $this->assertStringNotContainsString('data-codtarifa="EUR1"', $html);
        $resolvedTarifas = array_map(static fn (array $call): string => $call[1], $this->resolveCalls);
        $this->assertSame(['USD1', 'USD1'], $resolvedTarifas, 'Visibility is resolved for the scoped tariff only');
        $this->assertSame(
            [['en_tarifa', 'USD1', 'REF-1', null], ['en_catalogo', 'USD1', 'REF-1', null]],
            $this->resolveCalls,
            'Visibility comes from CaracteristicaResolver::resolve_bool() at articulo scope'
        );

        $enTarifa = $this->checkboxInput($html, 'en_tarifa', 'USD1');
        $enCatalogo = $this->checkboxInput($html, 'en_catalogo', 'USD1');
        $this->assertNotSame('', $enTarifa);
        $this->assertNotSame('', $enCatalogo);
        $this->assertStringContainsString('checked', $enTarifa, 'The resolved en_tarifa=true must render checked');
        $this->assertStringNotContainsString('checked', $enCatalogo, 'The resolved en_catalogo=false must render unchecked');

        // Rendering must never persist: no feature-value write, no row mutation.
        $this->assertSame([], $this->storeAssignments, 'Rendering must not write feature values');
        $this->assertSame(0, $this->saveCount, 'Rendering must not save a price row');
        $this->assertSame(0, $this->deleteCount, 'Rendering must not delete a price row');
    }

    // =====================================================================
    // ART-01 / ART-11 — conditional articulos.pvp write (design AD-5, C1)
    // =====================================================================

    /**
     * Loads the canonical controller and its model chain in the isolated test
     * process, mirroring the absorption test harness.
     */
    private function loadHostProductionClasses(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';

        foreach (['articulo'] as $short) {
            if (!class_exists($short, false)) {
                \fs_model_autoloader::ensureGlobalAlias($short);
            }
        }

        require_once FS_FOLDER . '/plugins/catalogo_core/tests/Support/IdiomaRegistryFake.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/tests/Support/FakeCatalogoIdioma.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/tests/Support/FakeArticuloDescripcion.php';

        if (is_file(FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php')) {
            require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php';
        }
    }

    /**
     * Tracked anonymous articulo standing in for the stored row. `pvp` is
     * seeded with the persisted base price so a stateless write is observable:
     * if the controller assigns `spvp`, the seed is lost.
     */
    private function buildTrackedArticulo(): object
    {
        $outer = $this;
        return new class($outer) extends \articulo {
            public $outer;

            public function __construct($outer)
            {
                // Skip parent::__construct (no DB); seed the fields the controller writes.
                $this->outer = $outer;
                $this->table_name = 'articulos';
                $this->referencia = 'REF-1';
                $this->descripcion = '';
                $this->pvp = 42.0;
                $this->codfamilia = null;
                $this->codfabricante = null;
                $this->codimpuesto = null;
                $this->stockfis = 0.0;
                $this->stockmin = 0.0;
                $this->stockmax = 0.0;
                $this->bloqueado = false;
                $this->sevende = true;
                $this->secompra = true;
                $this->publico = false;
                $this->nostock = false;
                $this->observaciones = '';
                $this->codbarras = '';
                $this->equivalencia = '';
                $this->partnumber = '';
            }

            public function get($ref)
            {
                return $ref === $this->referencia ? $this : false;
            }

            public function set_referencia($ref)
            {
                $this->referencia = $ref;
                return true;
            }

            public function save(): bool
            {
                $this->outer->articuloSaveCount++;
                return true;
            }

            public function get_errors(): array
            {
                return [];
            }

            protected function language_registry()
            {
                return (new FakeCatalogoIdioma())->useFakeDb($this->outer->idiomaDb);
            }

            protected function description_model()
            {
                return (new FakeArticuloDescripcion())->useFakeDb($this->outer->idiomaDb);
            }
        };
    }

    /**
     * Host controller harness for `editarArticulo()`: the real production save
     * body runs while the CSRF token, the permission gate and the post-save
     * per-tarifa/etiqueta side steps are stubbed, so only the `articulos.pvp`
     * write behavior is under test (Unit 3 scope).
     */
    private function buildHostController(): object
    {
        $outer = $this;
        return new class($outer) extends VentasArticulo {
            public $outer;

            public function __construct($outer)
            {
                // Skip parent::__construct — heavy FS init would hit the DB.
                $this->outer = $outer;
                $this->core_log = new \fs_core_log(VentasArticulo::class);
                $this->idiomas = [];
                $this->codtarifa = '';
                $this->tarifas = [];
            }

            protected function validateFormToken(): bool
            {
                return true;
            }

            protected function articulo_model(): \articulo
            {
                return $this->outer->trackedArticulo;
            }

            protected function idioma_model(): \FSFramework\model\catalogo_idioma
            {
                return (new FakeCatalogoIdioma())->useFakeDb($this->outer->idiomaDb);
            }

            protected function puedeEditarArticulo(string $referencia, string $codtarifa): bool
            {
                return true;
            }

            protected function syncTarifaArticulo(\articulo $art): bool
            {
                return true;
            }

            protected function saveEtiquetasArticulo(\articulo $art): bool
            {
                return true;
            }

            public function setRequestObj(Request $request): void
            {
                $this->request = $request;
            }

            public function setArticulo(?\articulo $articulo): void
            {
                $this->articulo = $articulo;
            }

            public function callEditar(Request $request): void
            {
                $this->editarArticulo($request);
            }
        };
    }

    private function buildHostHarness(): void
    {
        $this->loadHostProductionClasses();
        $this->idiomaDb = new IdiomaRegistryFake();
        $this->trackedArticulo = $this->buildTrackedArticulo();
        $this->hostController = $this->buildHostController();
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function runHostEditar(array $fields): void
    {
        $_POST = $fields;
        $_REQUEST = $fields;
        $request = Request::create('/index.php?page=ventas_articulo', 'POST', $fields);
        $this->hostController->setRequestObj($request);
        $this->hostController->setArticulo($this->trackedArticulo);
        $this->hostController->callEditar($request);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function selectedTarifaSpvpProvider(): array
    {
        return [
            'non-zero spvp' => ['99.99'],
            'zero spvp' => ['0'],
        ];
    }

    #[DataProvider('selectedTarifaSpvpProvider')]
    public function test_with_a_tarifa_selected_the_base_price_is_not_written(string $spvp): void
    {
        $this->buildHostHarness();
        // An active tarifa is selected: AD-5 keys the base-price write off the
        // normalized codtarifa, so a non-empty code means the tariff pane owns
        // the price and the stored base price must survive the save.
        $this->hostController->codtarifa = 'TAR-1';

        $this->runHostEditar([
            'sreferencia' => 'REF-1',
            'sobservaciones' => 'Changed',
            'spvp' => $spvp,
        ]);

        $this->assertSame(
            42.0,
            $this->trackedArticulo->pvp,
            'With a tarifa selected articulos.pvp must be left unchanged'
        );
        $this->assertSame(
            'Changed',
            $this->trackedArticulo->observaciones,
            'The other article fields must still persist with a tarifa selected'
        );
        $this->assertGreaterThanOrEqual(1, $this->articuloSaveCount, 'The article save must still run');
    }

    public function test_with_no_active_tarifas_the_base_price_is_written(): void
    {
        $this->buildHostHarness();
        $this->hostController->codtarifa = '';
        $this->hostController->tarifas = [];

        $this->runHostEditar([
            'sreferencia' => 'REF-1',
            'sobservaciones' => 'Base',
            'spvp' => '99.99',
        ]);

        $this->assertSame(
            99.99,
            $this->trackedArticulo->pvp,
            'With zero active tarifas the submitted base price must be written'
        );
        $this->assertSame('Base', $this->trackedArticulo->observaciones);
        $this->assertGreaterThanOrEqual(1, $this->articuloSaveCount);
    }

    // =====================================================================
    // ART-10 / ATT-04 — the host pane embeds the scoped WU-1 rows placeholder
    // =====================================================================

    /**
     * The unified host pane owns the per-tarifa price box and loads its rows
     * through the unchanged WU-1 endpoint (`page=tarif_tab_precios`), carrying
     * the additive `codtarifa` read scope so only the selected tariff renders.
     */
    public function test_host_pane_embeds_the_scoped_wu1_rows_placeholder(): void
    {
        $view = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulo.html.twig'
        );

        $this->assertStringContainsString(
            'id="tab_tarifario_precios_rows"',
            $view,
            'The unified pane must keep the frozen rows placeholder id'
        );
        $this->assertStringContainsString(
            'id="tab_tarifario_precios"',
            $view,
            'The unified pane must keep the frozen price box id'
        );
        $this->assertStringContainsString(
            'data-tipo="articulo"',
            $view,
            'The unified pane must identify the article surface'
        );
        $this->assertStringContainsString(
            'x-data="articuloTabPrecios" x-cloak',
            $view,
            'The price box must keep its Alpine component and the [x-cloak] shell'
        );

        $this->assertStringContainsString(
            'page=tarif_tab_precios&action=rows&tipo=articulo&ref=',
            $view,
            'The placeholder must request the unchanged WU-1 rows endpoint'
        );
        $this->assertMatchesRegularExpression(
            '/hx-get="index\.php\?page=tarif_tab_precios&action=rows&tipo=articulo'
            . '&ref=\{\{ fsc\.articulo\.referencia\|url_encode \}\}'
            . '&codtarifa=\{\{ fsc\.codtarifa\|url_encode \}\}"/',
            $view,
            'The placeholder must scope the fragment to the selected tarifa'
        );
        $this->assertStringContainsString(
            'hx-trigger="load"',
            $view,
            'The scoped fragment must load without a user gesture'
        );
        $this->assertStringContainsString(
            'hx-swap="outerHTML"',
            $view,
            'The scoped fragment must replace its placeholder'
        );
    }

    // =====================================================================
    // AD-6 — the host view includes the per-tarifa save wiring
    // =====================================================================

    /**
     * The retired pane template was the only includer of
     * `View/Hooks/partials/tab_save_script.html.twig`; AD-6 moves that include to
     * the host view. Without it the page never registers
     * `Alpine.data('articuloTabPrecios')` nor binds the `htmx:after:request`
     * handler that swaps `#tab_tarifario_precios_rows` after a save, so the
     * `x-data="articuloTabPrecios" x-cloak` box stays hidden and a saved row
     * never re-renders.
     *
     * The include must live inside the `fsc.tarifas`-gated branch that renders
     * the per-tarifa box: after its rows placeholder and before the `{% else %}`
     * that opens the no-tarifa base-price branch, so a zero-tarifa article does
     * not pull the wiring in.
     */
    public function test_host_view_includes_the_per_tarifa_save_wiring_in_the_tarifa_branch(): void
    {
        $view = (string) file_get_contents(
            FS_FOLDER . '/plugins/catalogo_core/View/ventas_articulo.html.twig'
        );

        $include = "@catalogo_core/Hooks/partials/tab_save_script.html.twig";
        $includePos = strpos($view, $include);

        $this->assertNotFalse(
            $includePos,
            'The host view must include the per-tarifa save wiring (AD-6)'
        );
        $this->assertSame(
            1,
            substr_count($view, $include),
            'The per-tarifa save wiring must be included exactly once'
        );

        $rowsPos = strpos($view, 'id="tab_tarifario_precios_rows"');
        $this->assertNotFalse($rowsPos, 'The unified pane must keep the scoped rows placeholder');

        $noTarifaBranch = strpos($view, '{% else %}', (int) $rowsPos);
        $this->assertNotFalse(
            $noTarifaBranch,
            'The tarifa branch must close with the no-tarifa {% else %} fallback'
        );

        $this->assertGreaterThan(
            $rowsPos,
            $includePos,
            'The save wiring must travel with the per-tarifa box, not before it'
        );
        $this->assertLessThan(
            $noTarifaBranch,
            $includePos,
            'The save wiring must be inside the fsc.tarifas branch, before the no-tarifa {% else %}'
        );
    }
}
