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
 * WU-1 hook ownership: the article "Tarifas" tab is served by catalogo_core
 * alone (spec ATT-03/ATT-04, AD-5).
 *
 * Boots catalogo_core Init and simulates the core Twig build sequence
 * (TwigLoaderEvent at build start, TwigInitEvent at env init), exactly like
 * CatalogoOpcionalesHookOwnershipTest. The two article hooks must register once
 * behind the static guard, the @catalogo_core templates must resolve and render
 * without tarifario, and the relocated endpoint must keep CSRF + neutral
 * permission guards.
 */
final class CatalogoArticuloHookOwnershipTest extends TestCase
{
    /** @var array<string, string> article hook → catalogo_core template */
    private const ARTICLE_HOOKS = [
        'ventas_articulo_tabs_after' => '@catalogo_core/Hooks/ventas_articulo_tabs_after.html.twig',
        'ventas_articulo_tab_pane_after' => '@catalogo_core/Hooks/ventas_articulo_tab_pane_after.html.twig',
    ];

    private const ENDPOINT_SLUG = 'tarif_tab_precios';

    private const PANE_TEMPLATE = '/plugins/catalogo_core/View/Hooks/ventas_articulo_tab_pane_after.html.twig';
    private const ROWS_PARTIAL = '/plugins/catalogo_core/View/Hooks/partials/articulo_precios_rows.html.twig';
    private const SAVE_SCRIPT = '/plugins/catalogo_core/View/Hooks/partials/tab_save_script.html.twig';

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

    /** Minimal host fsc stand-in exposing the members the article hooks read. */
    private function hostFsc(bool $isNew = false, string $ref = 'REF-001'): object
    {
        return new class($isNew, $ref) {
            public $articulo;
            public bool $is_new;
            public array $tarifas = [];
            public array $imagenes = [];
            public array $articulo_etiquetas_disponibles = [];
            public array $articulo_etiquetas_seleccionadas = [];
            public function __construct(bool $isNew, string $ref)
            {
                $this->is_new = $isNew;
                $this->articulo = $isNew ? null : (object) ['referencia' => $ref];
            }

            public function simbolo_divisa($coddivisa = ''): string
            {
                return (string) $coddivisa;
            }
        };
    }

    private function twigFor(FilesystemLoader $loader): Environment
    {
        // The moved pane imports the theme htmx/alpine macros (real bodies), so
        // the macro sources are chained in front of the @catalogo_core loader.
        $macroDir = FS_FOLDER . '/themes/AdminLTE/view/Macro';
        $macros = new ArrayLoader([
            'Macro/Htmx.html.twig' => (string) @file_get_contents($macroDir . '/Htmx.html.twig'),
            'Macro/Alpine.html.twig' => (string) @file_get_contents($macroDir . '/Alpine.html.twig'),
        ]);

        $twig = new Environment(new ChainLoader([$loader, $macros]), ['cache' => false, 'auto_reload' => false]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $params = []): string => $key));
        $twig->addFunction(new TwigFunction('csrf_field', static fn (): string => ''));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (): string => 'csrf-token'));
        $twig->addFunction(new TwigFunction('csp_nonce_attr', static fn (): string => 'nonce="test"'));
        $twig->addFunction(new TwigFunction('csrf_meta', static fn (): string => ''));

        return $twig;
    }

    private function movedSource(string $relative): string
    {
        $path = FS_FOLDER . $relative;
        $this->assertFileExists($path, "missing moved hook file: {$relative}");

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

    // WU-1 — registration is owned by catalogo_core and idempotent.

    public function test_catalogo_core_registers_article_hooks_once_across_rebuilds(): void
    {
        $this->bootTwigBuild();
        $this->bootTwigBuild();

        foreach (self::ARTICLE_HOOKS as $hook => $template) {
            $this->assertTrue(ViewHookRegistry::has($hook), "Hook {$hook} must be registered after the Twig build");
            $this->assertSame(
                [$template],
                $this->registeredTemplates($hook),
                "Hook {$hook} must be registered exactly once under the static guard"
            );
        }
    }

    // WU-1 — the @catalogo_core templates resolve and render without tarifario.

    public function test_catalogo_core_renders_article_tab_and_pane_without_tarifario(): void
    {
        $loader = $this->bootTwigBuild();
        $twig = $this->twigFor($loader);

        $saved = $this->hostFsc(false, 'REF-777');
        $header = ViewHookRegistry::render($twig, 'ventas_articulo_tabs_after', ['fsc' => $saved]);
        $this->assertStringContainsString('tab_tarifario_precios', $header, 'Tab header must target the frozen pane id');
        $this->assertStringContainsString(
            'page=tarif_tab_precios&action=rows&tipo=articulo&ref=REF-777',
            $header,
            'Tab header must point at the catalogo_core article rows endpoint'
        );
        $this->assertStringContainsString('hx-target="#tab_tarifario_precios_rows"', $header, 'Header must target the rows placeholder');
        $this->assertStringContainsString('hx-swap="outerHTML"', $header, 'Header must swap the rows placeholder');

        $pane = ViewHookRegistry::render($twig, 'ventas_articulo_tab_pane_after', ['fsc' => $saved]);
        $this->assertStringContainsString('id="tab_tarifario_precios"', $pane, 'Pane must carry the frozen DOM id');
        $this->assertStringContainsString('data-tipo="articulo"', $pane, 'Pane must identify the article surface');
    }

    // ATT-04 — an unsaved article has no tab (no reference to price).

    public function test_unsaved_article_renders_no_article_tab(): void
    {
        $loader = $this->bootTwigBuild();
        $twig = $this->twigFor($loader);
        $new = $this->hostFsc(true);

        $this->assertSame(
            '',
            trim(ViewHookRegistry::render($twig, 'ventas_articulo_tabs_after', ['fsc' => $new])),
            'An unsaved article must render no tab header'
        );
        $this->assertSame(
            '',
            trim(ViewHookRegistry::render($twig, 'ventas_articulo_tab_pane_after', ['fsc' => $new])),
            'An unsaved article must render no pane'
        );
    }

    // ATT-04 / catalogo-render-hooks — the pair renders at the frozen host markers.

    public function test_article_pair_renders_at_frozen_markers_with_tipo_articulo(): void
    {
        $twig = $this->catalogoHostEnvironment();
        $html = $twig->render('ventas_articulo.html.twig', $this->hostContext($this->fullHostFsc(false, 'REF-777')));

        $this->assertStringContainsString('tipo=articulo', $html, 'The host view must inject the article Tarifas header');
        $this->assertStringContainsString('REF-777', $html, 'The article tab must carry the host reference');
        $this->assertStringContainsString(
            '<div class="tab-pane" id="tab_tarifario_precios" data-tipo="articulo"',
            $html,
            'The host view must inject the article Tarifas pane'
        );
        $this->assertInjectedInTabHeader($html);
        $this->assertInjectedInTabContent($html);
    }

    // ATT-02/ATT-07 — the relocated endpoint keeps its guards and owns its partials.

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
    // ATT-05 — the moved tab runs on htmx 4 + Alpine CSP
    // =====================================================================

    public function test_moved_article_tab_pane_defers_boot_to_the_host(): void
    {
        $pane = $this->movedSource(self::PANE_TEMPLATE);
        $host = $this->movedSource('/plugins/catalogo_core/View/ventas_articulo.html.twig');

        // AD-W2-3: the pane no longer boots — the host owns htmx/Alpine boot.
        $this->assertStringNotContainsString('htmx.boot(', $pane, 'Pane must not boot htmx after WU-2');
        $this->assertStringNotContainsString('alpine.boot()', $pane, 'Pane must not boot Alpine after WU-2');
        $this->assertStringContainsString('[x-cloak]', $pane, 'Pane must keep its [x-cloak] shell');
        $this->assertStringContainsString('x-data="articuloTabPrecios"', $pane, 'Pane must keep its Alpine component');

        $this->assertStringContainsString('htmx.boot(', $host, 'Host view must own the htmx boot');
        $this->assertStringContainsString('alpine.boot()', $host, 'Host view must own the Alpine boot');
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
        $pane = $this->movedSource(self::PANE_TEMPLATE);

        $this->assertStringContainsString('Alpine.data(', $script, 'Script must register components via Alpine.data()');
        $this->assertStringContainsString('alpine:init', $script, 'Registration must happen behind alpine:init');
        $this->assertStringContainsString('csp_nonce_attr()', $script, 'Script tag must be nonce\'d');
        $this->assertStringContainsString('[x-cloak]', $pane, 'Pane must gate hidden Alpine components with [x-cloak]');
        $this->assertStringNotContainsString('innerHTML', $script, 'x-text/plain DOM only — never innerHTML');
    }

    public function test_moved_article_tab_uses_colon_events_and_no_v2_names(): void
    {
        $source = $this->movedSource(self::SAVE_SCRIPT) . $this->movedSource(self::PANE_TEMPLATE);

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

    /**
     * Builds a Twig environment that renders the real ventas_articulo host view
     * with the Init-registered @catalogo_core namespace and the two article
     * hooks. Theme/partial includes are stubbed so only the host marker output
     * is observed; the real view body and macro sources are used.
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

    private function fullHostFsc(bool $isNew, string $referencia): object
    {
        return new CatalogoArticleHookHostFsc($isNew, $referencia);
    }

    private function assertInjectedInTabHeader(string $html): void
    {
        $open = strpos($html, 'id="ul_tabs"');
        if ($open === false) {
            $open = strpos($html, 'id="tab_articulo"');
        }
        $close = strpos($html, '</ul>', (int) $open);
        $header = strpos($html, 'href="#tab_tarifario_precios"');

        $this->assertNotFalse($open, 'Host view must expose its tab list');
        $this->assertNotFalse($close, 'Host view must close its tab list');
        $this->assertNotFalse($header, 'Tarifas tab header must be injected');
        $this->assertGreaterThan($open, $header, 'Tarifas tab header must sit inside the tab list, after the list opens');
        $this->assertLessThan($close, $header, 'Tarifas tab header must sit inside the tab list, before it closes');
    }

    private function assertInjectedInTabContent(string $html): void
    {
        $content = strpos($html, 'class="tab-content"');
        $pane = strpos($html, '<div class="tab-pane" id="tab_tarifario_precios"');

        $this->assertNotFalse($content, 'Host view must expose its .tab-content block');
        $this->assertNotFalse($pane, 'Tarifas pane must be injected');
        $this->assertGreaterThan($content, $pane, 'Tarifas pane must sit inside .tab-content');
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

    public function __construct(bool $isNew, string $referencia)
    {
        $this->is_new = $isNew;
        $this->articulo = new CatalogoArticleHookHostEntity(['referencia' => $referencia]);
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
