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

use FSFramework\model\tarif_tarifa;
use FSFramework\Plugins\catalogo_core\Controller\VentasArticulo;
use FSFramework\View\ViewHookRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * ART-09 / ART-11 — validated tarifa selection for the canonical article detail
 * (design AD-4).
 *
 * DB-free by design: an anonymous VentasArticulo subclass skips the controller
 * constructor and stubs the tarifa_model() seam, so the real production
 * resolver bodies run without a database. The supplied `codtarifa` is validated
 * against the active set: a matching code wins, an unknown/inactive code falls
 * back to the default tarifa, then to the first active one, and a detail with
 * zero active tarifas degrades to `codtarifa === ''` with no selection.
 */
final class VentasArticuloTarifaSelectionTest extends TestCase
{
    /** @var object */
    private $controller;

    /** @var tarif_tarifa|false */
    public $defaultTarifa = false;

    /** @var array<int, tarif_tarifa> */
    public array $activeTarifas = [];

    /** Host stand-in handed to the real view when a render case runs. */
    private ?TarifaSelectionViewHostFsc $viewFsc = null;

    private function loadProductionClasses(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';

        if (is_file(FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php')) {
            require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasArticulo.php';
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        $this->loadProductionClasses();
        $this->defaultTarifa = false;
        $this->activeTarifas = [];
        $this->viewFsc = null;
        $this->resetHookRegistry();
        $this->controller = $this->buildController();
    }

    protected function tearDown(): void
    {
        $_POST = [];
        $_GET = [];
        $_REQUEST = [];
        parent::tearDown();
    }

    /** Clears the process-wide hook registry so render cases stay deterministic. */
    private function resetHookRegistry(): void
    {
        $prop = (new \ReflectionClass(ViewHookRegistry::class))->getProperty('hooks');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    private function buildController(): object
    {
        $outer = $this;
        return new class($outer) extends VentasArticulo {
            public $outer;

            public function __construct($outer)
            {
                // Skip parent::__construct — heavy FS init would hit the DB.
                $this->outer = $outer;
                $this->codtarifa = '';
                $this->tarifas = [];
            }

            protected function tarifa_model(): tarif_tarifa
            {
                $outer = $this->outer;
                return new class($outer) extends tarif_tarifa {
                    public $outer;

                    public function __construct($outer)
                    {
                        // Skip parent::__construct (no DB).
                        $this->outer = $outer;
                        $this->table_name = 'tarif_tarifas';
                    }

                    public function get_default()
                    {
                        return $this->outer->defaultTarifa;
                    }
                };
            }

            public function setRequestObj(Request $request): void
            {
                $this->request = $request;
            }

            public function callResolveCodtarifa(): string
            {
                return $this->resolveCodtarifa();
            }

            public function callResolveTarifaSeleccionada(): void
            {
                $this->resolveTarifaSeleccionada();
            }
        };
    }

    private function tarifa(string $code): tarif_tarifa
    {
        return new class($code) extends tarif_tarifa {
            public function __construct(string $code)
            {
                // Skip parent::__construct (no DB); seed the identity fields.
                $this->codtarifa = $code;
                $this->nombre = $code;
                $this->activa = true;
                $this->por_defecto = false;
                $this->coddivisa = 'EUR';
            }
        };
    }

    /**
     * @param array<int, string> $codes
     * @return array<int, tarif_tarifa>
     */
    private function tarifas(array $codes): array
    {
        $list = [];
        foreach ($codes as $code) {
            $list[] = $this->tarifa($code);
        }

        return $list;
    }

    private function request(string $query): Request
    {
        return Request::create('/index.php?page=ventas_articulo' . $query, 'GET');
    }

    /**
     * Loads the resolved active tarifas and request into the controller, then
     * resolves the selection exactly as loadTarifarioState() does.
     */
    private function resolve(array $codes, string $query): void
    {
        $this->activeTarifas = $this->tarifas($codes);
        $this->controller->tarifas = $this->activeTarifas;
        $this->controller->setRequestObj($this->request($query));
        $this->controller->codtarifa = $this->controller->callResolveCodtarifa();
    }

    // =====================================================================
    // ART-09 — validated selection matrix
    // =====================================================================

    public function test_default_tarifa_is_preselected_on_first_load(): void
    {
        $this->defaultTarifa = $this->tarifa('T2');

        // No codtarifa in the request: the default tariff must be resolved.
        $this->resolve(['T1', 'T2'], '');
        self::assertSame('T2', $this->controller->codtarifa, 'an absent code resolves to the default tarifa');

        // The resolved tarifa must be exposed to the view as $tarifa_seleccionada.
        $this->controller->callResolveTarifaSeleccionada();
        self::assertSame(
            'T2',
            $this->controller->tarifa_seleccionada->codtarifa,
            'the default tarifa must be exposed as the selected one'
        );
    }

    public function test_stale_codtarifa_falls_back_to_default(): void
    {
        $this->defaultTarifa = $this->tarifa('T2');

        $this->resolve(['T1', 'T2'], '&codtarifa=STALE');
        self::assertSame('T2', $this->controller->codtarifa, 'a stale code must fall back to the default tarifa');

        $this->controller->callResolveTarifaSeleccionada();
        self::assertSame(
            'T2',
            $this->controller->tarifa_seleccionada->codtarifa,
            'a stale code must never scope the pane to a nonexistent tarifa'
        );
    }

    public function test_unknown_codtarifa_without_default_falls_back_to_first_active(): void
    {
        $this->defaultTarifa = false;

        $this->resolve(['T1', 'T2'], '&codtarifa=UNKNOWN');
        self::assertSame(
            'T1',
            $this->controller->codtarifa,
            'without a default the first active tarifa must win'
        );

        $this->controller->callResolveTarifaSeleccionada();
        self::assertSame('T1', $this->controller->tarifa_seleccionada->codtarifa);
    }

    public function test_inactive_default_is_skipped_for_the_first_active(): void
    {
        // The default tariff exists but is not part of the active set.
        $this->defaultTarifa = $this->tarifa('T9');

        $this->resolve(['T1', 'T2'], '&codtarifa=UNKNOWN');
        self::assertSame(
            'T1',
            $this->controller->codtarifa,
            'an inactive default must not be selected; the first active tarifa wins'
        );
    }

    public function test_valid_active_codtarifa_is_kept(): void
    {
        $this->defaultTarifa = $this->tarifa('T1');

        $this->resolve(['T1', 'T2', 'T3'], '&codtarifa=T2');
        self::assertSame('T2', $this->controller->codtarifa, 'a requested active tarifa must be kept');

        $this->controller->callResolveTarifaSeleccionada();
        self::assertSame('T2', $this->controller->tarifa_seleccionada->codtarifa);
    }

    public function test_empty_codtarifa_falls_back_to_default(): void
    {
        $this->defaultTarifa = $this->tarifa('T2');

        $this->resolve(['T1', 'T2'], '&codtarifa=');
        self::assertSame('T2', $this->controller->codtarifa, 'an empty code is not a valid selection');
    }

    // =====================================================================
    // ART-11 — no-active-tarifas degradation
    // =====================================================================

    public function test_zero_active_tarifas_yields_empty_code_and_null_selection(): void
    {
        $this->defaultTarifa = false;

        $this->resolve([], '&codtarifa=T1');
        self::assertSame('', $this->controller->codtarifa, 'with no active tarifas the code must be empty');

        $this->controller->callResolveTarifaSeleccionada();
        self::assertTrue(
            property_exists($this->controller, 'tarifa_seleccionada'),
            'the controller must expose the resolved tarifa as a public property'
        );
        self::assertNull(
            $this->controller->tarifa_seleccionada,
            'with no active tarifas the selection must be null (no pane scoping)'
        );
    }

    // =====================================================================
    // ART-11 — the view degrades to the editable base price (render contract)
    // =====================================================================

    public function test_view_degrades_to_the_editable_base_price_with_no_active_tarifas(): void
    {
        $this->viewFsc = new TarifaSelectionViewHostFsc(false);

        $html = $this->renderView();

        // No selector without an active tarifa (ART-11).
        self::assertStringNotContainsString(
            'name="codtarifa"',
            $html,
            'With zero active tarifas the detail must not emit the tarifa selector'
        );

        // The base price stays reachable, editable and inside the unified pane.
        $precios = strpos($html, 'id="precios-tarifa"');
        $stock = strpos($html, 'id="stock-articulo"');
        $spvp = strpos($html, 'name="spvp"');
        self::assertNotFalse($precios, 'The unified pane must expose the #precios-tarifa section');
        self::assertNotFalse($stock, 'The unified pane must expose the #stock-articulo section');
        self::assertNotFalse($spvp, 'With zero active tarifas the base pvp input must be present');
        self::assertGreaterThan(
            $precios,
            $spvp,
            'The base price must render inside the #precios-tarifa section of the unified pane'
        );
        self::assertLessThan($stock, $spvp, 'The base price must stay inside the unified pane, before Stock');

        $input = $this->inputTag($html, 'spvp');
        self::assertNotSame('', $input, 'The base pvp input must be a real input tag');
        self::assertStringNotContainsString('readonly', $input, 'The base pvp input must stay editable');
        self::assertStringNotContainsString('disabled', $input, 'The base pvp input must stay editable');
        self::assertStringContainsString(
            'value="42,00"',
            $input,
            'The base pvp input must carry the stored article price'
        );

        // The price surface degrades without fatal error and the page stays complete.
        self::assertStringContainsString('id="tab_articulo"', $html, 'The page must stay complete');
        self::assertStringContainsString('href="#opcionales"', $html, 'The secondary tab must stay reachable');
    }

    public function test_view_scopes_the_selector_to_the_resolved_active_tarifa(): void
    {
        $this->viewFsc = new TarifaSelectionViewHostFsc(true);

        $html = $this->renderView();

        self::assertStringContainsString('name="codtarifa"', $html, 'With active tarifas the selector must render');
        self::assertMatchesRegularExpression(
            '/hx-get="index\.php\?page=ventas_articulo(&|&amp;)ref=REF-1"/',
            $html,
            'The selector must re-request the canonical detail'
        );
        foreach (['hx-trigger="change"', 'hx-target="body"', 'hx-select="body"', 'hx-swap="outerHTML"', 'hx-push-url="true"', 'hx-boost="true"'] as $attr) {
            self::assertStringContainsString($attr, $html, 'The selector must clone the ratified pattern: ' . $attr);
        }
        self::assertStringContainsString(
            '<option value="T2" selected>',
            $html,
            'The resolved tarifa must be the selected option'
        );
        self::assertStringContainsString(
            'href="#datos"',
            $html,
            'The unified pane must be the active tab'
        );
    }

    // =====================================================================
    // View render helpers
    // =====================================================================

    /**
     * Renders the REAL ventas_articulo view with a stubbed theme shell, so the
     * authored selector/degradation branches are exercised without a framework
     * boot. Theme/partial includes are stubbed; only the host body matters.
     */
    private function renderView(): string
    {
        if (!class_exists('FSFramework\\model\\catalogo_opcional', false)) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
        }

        $viewDir = FS_FOLDER . '/plugins/catalogo_core/View';
        $macroDir = FS_FOLDER . '/themes/AdminLTE/view/Macro';
        $templates = [
            'header.html.twig' => '',
            'footer.html.twig' => '',
            'partials/articulos/tab_multiidioma.html.twig' => '',
            'partials/articulos/tab_opcionales.html.twig' => '',
            // AD-6: the host view includes the per-tarifa save wiring inside the
            // fsc.tarifas branch. This ArrayLoader has no @catalogo_core namespace,
            // so the include is stubbed here; this harness covers tarifa
            // selection, not the client save wiring.
            '@catalogo_core/Hooks/partials/tab_save_script.html.twig' => '',
            'Macro/Htmx.html.twig' => (string) @file_get_contents($macroDir . '/Htmx.html.twig'),
            'Macro/Alpine.html.twig' => (string) @file_get_contents($macroDir . '/Alpine.html.twig'),
            'ventas_articulo.html.twig' => (string) file_get_contents($viewDir . '/ventas_articulo.html.twig'),
        ];

        $twig = new Environment(new ArrayLoader($templates), ['cache' => false, 'auto_reload' => false]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $params = []): string => $key));
        $twig->addFunction(new TwigFunction('csrf_field', static fn (): string => ''));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'csrf-token'));
        $twig->addFunction(new TwigFunction('csp_nonce_attr', static fn (): string => 'nonce="test"'));
        $twig->addFunction(new TwigFunction('csrf_meta', static fn (): string => ''));
        // Mirrors src/Core/Html.php: render_hook is is_safe:['html']. Declaring
        // it any other way bakes escaping into the compiled template class,
        // which Twig reuses process-wide and would poison sibling render tests.
        $twig->addFunction(new TwigFunction(
            'render_hook',
            static fn (string $name, array $context = []): string => ViewHookRegistry::render($twig, $name, $context),
            ['is_safe' => ['html']]
        ));

        return $twig->render('ventas_articulo.html.twig', [
            'fsc' => $this->viewFsc,
            'user' => null,
            'empresa' => null,
            'i18n' => null,
        ]);
    }

    /** Extracts the rendered <input> tag carrying the given name attribute. */
    private function inputTag(string $html, string $name): string
    {
        $pattern = '/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*\/?>/';
        if (preg_match($pattern, $html, $matches) !== 1) {
            return '';
        }

        return $matches[0];
    }
}

/**
 * Minimal host-controller stand-in for the ventas_articulo view: the members
 * the unified pane reads, with the active tarifas and the resolved selection
 * injected per case (zero tarifas vs a resolved default).
 */
final class TarifaSelectionViewHostFsc
{
    /** @var array<string, array<int, mixed>> */
    private array $lists = [
        'familias' => [],
        'fabricantes' => [],
        'impuestos' => [],
        'idiomas' => [],
        'extensions' => [],
        'imagenes' => [],
        'articulo_opcionales' => [],
        'articulo_grupos' => [],
        'opcionales_disponibles' => [],
        'grupos_disponibles' => [],
        'articulo_etiquetas_disponibles' => [],
        'articulo_etiquetas_seleccionadas' => [],
    ];

    public TarifaSelectionViewHostEntity $articulo;
    /** @var array<int, TarifaSelectionViewHostEntity> */
    public array $tarifas = [];
    public ?TarifaSelectionViewHostEntity $tarifa_seleccionada = null;
    public string $codtarifa = '';
    public bool $allow_delete = false;

    public function __construct(bool $withTarifas)
    {
        $this->articulo = new TarifaSelectionViewHostEntity(['referencia' => 'REF-1', 'pvp' => 42.0]);
        if ($withTarifas) {
            $this->tarifas = [
                new TarifaSelectionViewHostEntity(['codtarifa' => 'T1', 'nombre' => 'Tarifa uno', 'por_defecto' => false]),
                new TarifaSelectionViewHostEntity(['codtarifa' => 'T2', 'nombre' => 'Tarifa dos', 'por_defecto' => true]),
            ];
            $this->tarifa_seleccionada = $this->tarifas[1];
            $this->codtarifa = 'T2';
        }
    }

    public function __get(string $name): mixed
    {
        return $this->lists[$name] ?? '';
    }

    public function __isset(string $name): bool
    {
        return true;
    }

    public function __call(string $name, array $args): mixed
    {
        return 0;
    }

    public function url(): string
    {
        return 'index.php?page=ventas_articulo&ref=REF-1';
    }

    /** @return array<string, ?string> */
    public function caracteristicas_context(): array
    {
        return [];
    }
}

/**
 * Minimal model stand-in: string properties default to '', numeric ones to 0,
 * and any method call resolves to 0.
 */
final class TarifaSelectionViewHostEntity
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    public function __get(string $name): mixed
    {
        if (array_key_exists($name, $this->values)) {
            return $this->values[$name];
        }

        return in_array($name, ['pvp', 'stockfis', 'stockmin', 'stockmax', 'iva'], true) ? 0 : '';
    }

    public function __isset(string $name): bool
    {
        return true;
    }

    public function __call(string $name, array $args): mixed
    {
        return $this->values[$name] ?? 0;
    }
}
