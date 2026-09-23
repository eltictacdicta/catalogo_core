<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
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

namespace Tests\CatalogoCore\Integration;

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Event\TwigInitEvent;
use FSFramework\Event\TwigLoaderEvent;
use FSFramework\Plugins\catalogo_core\Init;
use FSFramework\View\ViewHookRegistry;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * D4 hook retirement: catalogo_core registers ZERO article hooks and the host
 * `ventas_articulo.html.twig` renders the Tarifas surface as a section of the
 * unified `#datos` pane (spec ATT-03/ATT-04, catalogo-render-hooks MODIFIED).
 *
 * Boots catalogo_core Init and simulates the core Twig build sequence
 * (TwigLoaderEvent at build start, TwigInitEvent at env init), exactly like
 * CatalogoOpcionalesHookOwnershipTest. The retired article pair must stay
 * unregistered across rebuilds, both hook templates must be gone from the tree,
 * and the opcional pair must keep registering once behind the static guard.
 * The endpoint/rows/hygiene contracts of the kept WU-1 surface stay covered.
 */
final class CatalogoArticuloHookOwnershipTest extends TestCase
{
    /** @var list<string> article hooks catalogo_core MUST NOT register any more */
    private const ARTICLE_HOOKS = [
        'ventas_articulo_tabs_after',
        'ventas_articulo_tab_pane_after',
    ];

    /** @var array<string, string> opcional hook → catalogo_core template */
    private const OPCIONAL_HOOKS = [
        'ventas_opcional_tabs_after' => '@catalogo_core/Hooks/ventas_opcional_tabs_after.html.twig',
        'ventas_opcional_tab_pane_after' => '@catalogo_core/Hooks/ventas_opcional_tab_pane_after.html.twig',
    ];

    private const ENDPOINT_SLUG = 'tarif_tab_precios';

    private const HOST_VIEW = '/plugins/catalogo_core/View/ventas_articulo.html.twig';
    private const ROWS_PARTIAL = '/plugins/catalogo_core/View/Hooks/partials/articulo_precios_rows.html.twig';
    private const SAVE_SCRIPT = '/plugins/catalogo_core/View/Hooks/partials/tab_save_script.html.twig';

    /** @var list<string> retired article hook templates that MUST be deleted */
    private const RETIRED_TEMPLATES = [
        '/plugins/catalogo_core/View/Hooks/ventas_articulo_tabs_after.html.twig',
        '/plugins/catalogo_core/View/Hooks/ventas_articulo_tab_pane_after.html.twig',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetStaticState();
    }

    protected function tearDown(): void
    {
        $this->resetStaticState();
        parent::tearDown();
    }

    private function resetStaticState(): void
    {
        FSEventDispatcher::reset();

        // Init::init() runs boot migrations that need a functional fs_db2.
        // Production guarantees one before Kernel::boot() (index.php calls
        // fs_schema::selfHealCoreTables(), which constructs a real fs_db2).
        // The container's lazy 'db' service is a Symfony ghost whose
        // constructor never runs, so it cannot seed fs_db2's static engine on
        // its own. Mirror the production precondition so init() boots exactly
        // as it does in production.
        new \fs_db2();

        $prop = (new \ReflectionClass(ViewHookRegistry::class))->getProperty('hooks');
        $prop->setAccessible(true);
        $prop->setValue(null, []);

        require_once FS_FOLDER . '/plugins/catalogo_core/Init.php';
        $initRef = new \ReflectionClass(Init::class);
        foreach (['hooksRegistered', 'viewExtensionsRegistered'] as $static) {
            if ($initRef->hasProperty($static)) {
                $property = $initRef->getProperty($static);
                $property->setAccessible(true);
                $property->setValue(null, false);
            }
        }
    }

    /** Boots catalogo_core Init and simulates the core Twig build. */
    private function bootTwigBuild(): FilesystemLoader
    {
        (new Init())->init();

        $dispatcher = FSEventDispatcher::getInstance();
        $loader = new FilesystemLoader();
        $dispatcher->dispatch(new TwigLoaderEvent($loader), TwigLoaderEvent::NAME);
        $dispatcher->dispatch(new TwigInitEvent(new Environment(new ArrayLoader([]))), TwigInitEvent::NAME);

        return $loader;
    }

    private function movedSource(string $relative): string
    {
        $path = FS_FOLDER . $relative;
        $this->assertFileExists($path, "missing kept hook file: {$relative}");

        return (string) file_get_contents($path);
    }

    /** @return string[] registered templates for a hook (reflection over the private map) */
    private function registeredTemplates(string $hook): array
    {
        $prop = (new \ReflectionClass(ViewHookRegistry::class))->getProperty('hooks');
        $prop->setAccessible(true);
        $hooks = (array) $prop->getValue();

        return $hooks[$hook] ?? [];
    }

    // =====================================================================
    // D4 — zero article registration, idempotent across rebuilds, templates
    // retired; the opcional pair stays registered.
    // =====================================================================

    public function test_catalogo_core_registers_zero_article_hooks_across_rebuilds(): void
    {
        $this->bootTwigBuild();
        $this->bootTwigBuild();

        foreach (self::ARTICLE_HOOKS as $hook) {
            $this->assertFalse(
                ViewHookRegistry::has($hook),
                "Hook {$hook} must NOT be registered after the Twig build (D4)"
            );
            $this->assertSame(
                [],
                $this->registeredTemplates($hook),
                "Hook {$hook} must stay unregistered across rebuilds"
            );
        }

        // The opcional pair must be untouched by the retirement.
        foreach (self::OPCIONAL_HOOKS as $hook => $template) {
            $this->assertTrue(ViewHookRegistry::has($hook), "Hook {$hook} must stay registered");
            $this->assertSame(
                [$template],
                $this->registeredTemplates($hook),
                "Hook {$hook} must be registered exactly once under the static guard"
            );
        }
    }

    public function test_retired_article_hook_templates_and_init_mappings_are_gone(): void
    {
        foreach (self::RETIRED_TEMPLATES as $relative) {
            $this->assertFileDoesNotExist(
                FS_FOLDER . $relative,
                "Retired article hook template must be deleted: {$relative}"
            );
        }

        $init = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/Init.php');
        $this->assertStringNotContainsString(
            'ARTICULO_HOOK_TEMPLATES',
            $init,
            'Init.php must not keep the retired article hook mapping'
        );
        foreach (self::ARTICLE_HOOKS as $hook) {
            $this->assertStringNotContainsString(
                $hook,
                $init,
                "Init.php must not reference the retired article hook {$hook}"
            );
        }

        $this->assertStringContainsString('OPCIONAL_HOOK_TEMPLATES', $init, 'The opcional mapping must stay');
        foreach (self::OPCIONAL_HOOKS as $hook => $template) {
            $this->assertStringContainsString($hook, $init, "The opcional hook {$hook} must stay registered");
            $this->assertStringContainsString($template, $init, "The opcional template {$template} must stay mapped");
        }
    }

    // =====================================================================
    // ATT-04 — the host renders the unified pane and injects no Tarifas
    // surface at the two frozen markers.
    // =====================================================================

    public function test_host_renders_the_unified_pane_without_an_injected_tarifas_surface(): void
    {
        $twig = $this->catalogoHostEnvironment();
        $html = $twig->render('ventas_articulo.html.twig', $this->hostContext($this->fullHostFsc(false, 'REF-777', true)));

        // The unified pane owns the article-scoped Tarifas section.
        $this->assertStringContainsString('id="datos"', $html, 'The host must render the unified #datos pane');
        $this->assertStringContainsString('id="precios-tarifa"', $html, 'The host must expose the in-pane prices section');
        $this->assertStringContainsString('id="tab_tarifario_precios"', $html, 'The unified pane must carry the frozen price box id');
        $this->assertStringContainsString('data-tipo="articulo"', $html, 'The unified pane must identify the article surface');
        $this->assertStringContainsString('REF-777', $html, 'The unified pane must carry the host reference');
        $this->assertStringContainsString(
            'page=tarif_tab_precios&action=rows&tipo=articulo&ref=REF-777',
            $html,
            'The unified pane must load its rows from the unchanged WU-1 endpoint'
        );
        $this->assertStringContainsString(
            'codtarifa=T2',
            $html,
            'The unified pane must scope the rows request to the selected tarifa'
        );

        // No hook-injected Tarifas surface remains: the retired tab header link
        // and the injected pane shell contribute nothing at the frozen markers.
        $this->assertStringNotContainsString(
            'href="#tab_tarifario_precios"',
            $html,
            'The retired Tarifas tab header must not be injected at ventas_articulo_tabs_after'
        );
        $this->assertStringNotContainsString(
            '<div class="tab-pane" id="tab_tarifario_precios"',
            $html,
            'The retired Tarifas pane must not be injected at ventas_articulo_tab_pane_after'
        );
        $this->assertStringNotContainsString(
            'Abre la pestaña para cargar los precios por tarifa.',
            $html,
            'The retired injected pane placeholder must be gone'
        );
    }

    public function test_unsaved_article_renders_no_price_surface(): void
    {
        $twig = $this->catalogoHostEnvironment();
        $html = $twig->render('ventas_articulo.html.twig', $this->hostContext($this->fullHostFsc(true, '')));

        $this->assertStringNotContainsString(
            'id="tab_tarifario_precios"',
            $html,
            'An unsaved article must render no per-tarifa price box'
        );
        $this->assertStringNotContainsString(
            'tab_tarifario_precios_rows',
            $html,
            'An unsaved article must render no rows placeholder'
        );
        $this->assertStringNotContainsString(
            'name="spvp"',
            $html,
            'An unsaved article must render no base price input'
        );
    }

    // =====================================================================
    // ATT-02/ATT-07 — the endpoint keeps its guards and owns its partials.
    // =====================================================================

    public function test_endpoint_extends_fbase_controller_and_owns_its_partials(): void
    {
        $path = FS_FOLDER . '/plugins/catalogo_core/controller/' . self::ENDPOINT_SLUG . '.php';
        $this->assertFileExists($path, 'Article tab endpoint must live in catalogo_core');
        $src = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            '/class\s+' . preg_quote(self::ENDPOINT_SLUG, '/') . '\s+extends\s+fbase_controller\b/',
            $src,
            'tarif_tab_precios must extend catalogo_core fbase_controller'
        );
        $this->assertStringContainsString('use TarifarioOpcionalStateTrait;', $src, 'Endpoint must reuse the state trait');
        $this->assertStringContainsString('requireCsrf()', $src, 'Endpoint mutations must validate CSRF');
        $this->assertStringContainsString('puede_editar_articulo(', $src, 'Endpoint must gate the price save');
        $this->assertStringContainsString(
            '@catalogo_core/Hooks/partials/articulo_precios_rows.html.twig',
            $src,
            'Endpoint must render the catalogo_core rows partial'
        );

        $compact = (string) preg_replace('/[\s\'"]+/', '', $src);
        $this->assertStringNotContainsString(
            'plugins/tarifario/',
            $compact,
            'Endpoint must be self-contained in catalogo_core (no plugins/tarifario/... references)'
        );
    }

    // =====================================================================
    // ATT-05 — the host view owns the htmx 4 + Alpine CSP boot
    // =====================================================================

    public function test_host_view_owns_the_article_tab_boot(): void
    {
        $script = $this->movedSource(self::SAVE_SCRIPT);
        $host = $this->movedSource(self::HOST_VIEW);

        // AD-W2-3: the article surface no longer boots — the host owns boot.
        $this->assertStringNotContainsString('htmx.boot(', $script, 'Save wiring must not boot htmx');
        $this->assertStringNotContainsString('alpine.boot()', $script, 'Save wiring must not boot Alpine');

        $this->assertStringContainsString('htmx.boot(', $host, 'Host view must own the htmx boot');
        $this->assertStringContainsString('alpine.boot()', $host, 'Host view must own the Alpine boot');
        $this->assertStringContainsString('x-data="articuloTabPrecios"', $host, 'Host view must keep the per-tarifa Alpine component');
        $this->assertStringContainsString('[x-cloak]', $host, 'Host view must keep its [x-cloak] shell');
    }

    public function test_moved_article_tab_mutations_use_hx_post(): void
    {
        $source = $this->movedSource(self::ROWS_PARTIAL) . $this->movedSource(self::SAVE_SCRIPT);

        $this->assertMatchesRegularExpression(
            '/hx-post|htmx\.ajax\(\'POST\'/',
            $source,
            'Moved tab mutations must be POST-based'
        );
        $this->assertStringNotContainsString('hx-delete', $source, 'htmx 4 colon migration forbids hx-delete');
    }

    public function test_moved_article_tab_registers_alpine_data_with_nonce_and_x_cloak(): void
    {
        $script = $this->movedSource(self::SAVE_SCRIPT);
        $host = $this->movedSource(self::HOST_VIEW);

        $this->assertStringContainsString('Alpine.data(', $script, 'Script must register components via Alpine.data()');
        $this->assertStringContainsString('alpine:init', $script, 'Registration must happen behind alpine:init');
        $this->assertStringContainsString('csp_nonce_attr()', $script, 'Script tag must be nonce\'d');
        $this->assertStringContainsString('[x-cloak]', $host, 'Host view must gate hidden Alpine components with [x-cloak]');
        $this->assertStringNotContainsString('innerHTML', $script, 'x-text/plain DOM only — never innerHTML');
    }

    public function test_moved_article_tab_uses_colon_events_and_no_v2_names(): void
    {
        $source = $this->movedSource(self::SAVE_SCRIPT) . $this->movedSource(self::HOST_VIEW);

        $this->assertMatchesRegularExpression(
            '/htmx:after:(swap|request)/',
            $source,
            'Moved tab must use htmx 4 colon event names'
        );
        foreach (['htmx:afterSwap', 'htmx:afterRequest'] as $banned) {
            $this->assertStringNotContainsString($banned, $source, "htmx v2 event name {$banned} is forbidden");
        }
        $this->assertStringNotContainsString('bootbox', $source, 'bootbox is forbidden');
        $this->assertStringNotContainsString('|raw', $source, '|raw is forbidden in the moved tab');
    }

    public function test_moved_article_rows_render_each_tariff_currency(): void
    {
        $rows = $this->movedSource(self::ROWS_PARTIAL);

        $this->assertStringContainsString(
            'fsc.simbolo_divisa(tarifa.coddivisa)',
            $rows,
            'Every price must use the row tariff\'s own coddivisa'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/fsc\.simbolo_divisa\(\s*\)/',
            $rows,
            'Bare fsc.simbolo_divisa() calls are forbidden (S28)'
        );
    }

    // =====================================================================
    // ART-10 / ATT-04 — the rows read renders resolver-driven visibility and
    // persists nothing while rendering.
    // =====================================================================

    public function test_tab_renders_resolver_driven_visibility(): void
    {
        $rows = $this->movedSource(self::ROWS_PARTIAL);
        $endpoint = $this->movedSource('/plugins/catalogo_core/controller/' . self::ENDPOINT_SLUG . '.php');

        $this->assertStringContainsString(
            'visibilidad[tarifa.codtarifa].en_tarifa',
            $rows,
            'The en_tarifa control must render the resolver-derived visibility'
        );
        $this->assertStringContainsString(
            'visibilidad[tarifa.codtarifa].en_catalogo',
            $rows,
            'The en_catalogo control must render the resolver-derived visibility'
        );

        // The dropped legacy per-tarifa columns are never read by the partial.
        $this->assertStringNotContainsString(
            'precio.en_tarifa',
            $rows,
            'The rows partial must not read the dropped legacy en_tarifa column'
        );
        $this->assertStringNotContainsString(
            'precio.en_catalogo',
            $rows,
            'The rows partial must not read the dropped legacy en_catalogo column'
        );

        // ... and the fragment builds that map through the resolver.
        $fragment = $this->methodBody($endpoint, 'render_rows_fragment');
        $this->assertNotSame('', $fragment, 'render_rows_fragment() body must be found');
        $this->assertStringContainsString("resolve_bool('en_tarifa'", $fragment);
        $this->assertStringContainsString("resolve_bool('en_catalogo'", $fragment);
        $this->assertStringContainsString("'visibilidad' => \$visibilidad", $fragment);
    }

    public function test_rendering_the_tab_writes_nothing(): void
    {
        $endpoint = $this->movedSource('/plugins/catalogo_core/controller/' . self::ENDPOINT_SLUG . '.php');

        foreach (['render_rows_action', 'render_rows_fragment'] as $method) {
            $body = $this->methodBody($endpoint, $method);
            $this->assertNotSame('', $body, $method . '() body must be found');

            foreach (['->save(', '->delete(', 'assign_bool(', 'assign_predefined(', 'assign_custom(', '->exec('] as $write) {
                $this->assertStringNotContainsString(
                    $write,
                    $body,
                    $method . '() must not persist anything while rendering'
                );
            }
        }

        // The render path only reads through the resolver.
        $this->assertStringContainsString(
            'resolve_bool(',
            $this->methodBody($endpoint, 'render_rows_fragment'),
            'Rendering resolves the effective value, it does not store it'
        );
    }

    private function methodBody(string $src, string $method): string
    {
        $pattern = '/function\s+' . preg_quote($method, '/') . '\s*\([^)]*\)[^{]*\{/';
        if (!preg_match($pattern, $src, $matches, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $matches[0][1] + strlen($matches[0][0]);
        $depth = 1;
        $length = strlen($src);
        for ($i = $start; $i < $length; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start);
                }
            }
        }

        return '';
    }

    /**
     * Builds a Twig environment that renders the real ventas_articulo host view
     * with the Init-registered @catalogo_core namespace. Theme/partial includes
     * are stubbed so only the host body is observed; the real view body and
     * macro sources are used.
     */
    private function catalogoHostEnvironment(): Environment
    {
        if (!class_exists('FSFramework\\model\\catalogo_opcional', false)) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
        }

        (new Init())->init();

        $namespaceLoader = new FilesystemLoader();
        $dispatcher = FSEventDispatcher::getInstance();
        $dispatcher->dispatch(new TwigLoaderEvent($namespaceLoader), TwigLoaderEvent::NAME);
        $dispatcher->dispatch(new TwigInitEvent(new Environment(new ArrayLoader([]))), TwigInitEvent::NAME);

        $viewDir = FS_FOLDER . '/plugins/catalogo_core/View';
        $macroDir = FS_FOLDER . '/themes/AdminLTE/view/Macro';
        $hostLoader = new ArrayLoader([
            'header.html.twig' => '',
            'footer.html.twig' => '',
            'partials/articulos/tab_multiidioma.html.twig' => '',
            'partials/articulos/tab_opcionales.html.twig' => '',
            'Macro/Htmx.html.twig' => (string) @file_get_contents($macroDir . '/Htmx.html.twig'),
            'Macro/Alpine.html.twig' => (string) @file_get_contents($macroDir . '/Alpine.html.twig'),
            'ventas_articulo.html.twig' => (string) file_get_contents($viewDir . '/ventas_articulo.html.twig'),
        ]);

        $twig = new Environment(new ChainLoader([$hostLoader, $namespaceLoader]), ['cache' => false, 'auto_reload' => false]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $params = []): string => $key));
        $twig->addFunction(new TwigFunction('csrf_field', static fn (): string => ''));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'csrf-token'));
        $twig->addFunction(new TwigFunction('csp_nonce_attr', static fn (): string => 'nonce="test"'));
        $twig->addFunction(new TwigFunction('csrf_meta', static fn (): string => ''));
        $twig->addFunction(new TwigFunction(
            'render_hook',
            static fn (string $name, array $context = []): string => ViewHookRegistry::render($twig, $name, $context),
            ['is_safe' => ['html']]
        ));

        return $twig;
    }

    /** @return array<string, mixed> */
    private function hostContext(object $fsc): array
    {
        return ['fsc' => $fsc, 'user' => null, 'empresa' => null, 'i18n' => null];
    }

    private function fullHostFsc(bool $isNew, string $referencia, bool $withTarifas = false): object
    {
        return new CatalogoArticleHookHostFsc($isNew, $referencia, $withTarifas);
    }
}

/**
 * Minimal catalogo_core host-controller stand-in for the ventas_articulo view.
 */
final class CatalogoArticleHookHostFsc
{
    /** @var array<string, array<int, mixed>> */
    private array $lists = [
        'grupos_opcional' => [],
        'familias_asignadas' => [],
        'familias_disponibles' => [],
        'articulos' => [],
        'familias' => [],
        'fabricantes' => [],
        'impuestos' => [],
        'idiomas' => [],
        'extensions' => [],
        'tarifas' => [],
        'imagenes' => [],
        'articulo_etiquetas_disponibles' => [],
        'articulo_etiquetas_seleccionadas' => [],
    ];

    public CatalogoArticleHookHostEntity $articulo;
    public bool $is_new;
    public ?CatalogoArticleHookHostEntity $tarifa_seleccionada = null;
    public string $codtarifa = '';

    public function __construct(bool $isNew, string $referencia, bool $withTarifas = false)
    {
        $this->is_new = $isNew;
        $this->articulo = new CatalogoArticleHookHostEntity(['referencia' => $referencia]);

        if ($withTarifas) {
            $this->lists['tarifas'] = [
                new CatalogoArticleHookHostEntity(['codtarifa' => 'T1', 'nombre' => 'Tarifa uno', 'por_defecto' => false]),
                new CatalogoArticleHookHostEntity(['codtarifa' => 'T2', 'nombre' => 'Tarifa dos', 'por_defecto' => true]),
            ];
            $this->tarifa_seleccionada = $this->lists['tarifas'][1];
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
        return 'index.php?page=ventas_articulo';
    }
}

/**
 * Minimal model stand-in: string properties default to '', numeric ones to 0,
 * and any method call resolves to 0.
 */
final class CatalogoArticleHookHostEntity
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
