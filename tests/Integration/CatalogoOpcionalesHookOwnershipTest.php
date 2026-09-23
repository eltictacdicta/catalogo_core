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
 * WU-4/WU-5 hook ownership: the opcional "Tarifas" tab is served by
 * catalogo_core alone (OUM-10, AD-7/AD-8).
 *
 * Boots catalogo_core Init and simulates the core Twig build sequence
 * (TwigLoaderEvent at build start, TwigInitEvent at env init), exactly like
 * tarifario's HookRegistrationTest. The two opcional hooks must register once
 * behind the static guard, the @catalogo_core templates must resolve and render
 * without tarifario, and the moved endpoint must keep CSRF + permission guards.
 */
final class CatalogoOpcionalesHookOwnershipTest extends TestCase
{
    /** @var array<string, string> opcional hook → catalogo_core template */
    private const OPCIONAL_HOOKS = [
        'ventas_opcional_tabs_after' => '@catalogo_core/Hooks/ventas_opcional_tabs_after.html.twig',
        'ventas_opcional_tab_pane_after' => '@catalogo_core/Hooks/ventas_opcional_tab_pane_after.html.twig',
    ];

    private const ENDPOINT_SLUG = 'tarif_opcional_tab';

    private const PANE_TEMPLATE = '/plugins/catalogo_core/View/Hooks/ventas_opcional_tab_pane_after.html.twig';
    private const ROWS_PARTIAL = '/plugins/catalogo_core/View/Hooks/partials/opcional_precios_rows.html.twig';
    private const SAVE_SCRIPT = '/plugins/catalogo_core/View/Hooks/partials/opcional_tab_save_script.html.twig';

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

    /** Minimal host fsc stand-in exposing the members the opcional hooks read. */
    private function hostFsc(bool $isNew = false, int $opcionalId = 7): object
    {
        return new class($isNew, $opcionalId) {
            public $opcional;
            public bool $is_new;
            public function __construct(bool $isNew, int $opcionalId)
            {
                $this->is_new = $isNew;
                $this->opcional = (object) ['id' => $opcionalId];
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

    // OUM-10 — registration is owned by catalogo_core and idempotent.

    public function test_catalogo_core_registers_opcional_hooks_once_across_rebuilds(): void
    {
        $this->bootTwigBuild();
        $this->bootTwigBuild();

        foreach (self::OPCIONAL_HOOKS as $hook => $template) {
            $this->assertTrue(ViewHookRegistry::has($hook), "Hook {$hook} must be registered after the Twig build");
            $this->assertSame(
                [$template],
                $this->registeredTemplates($hook),
                "Hook {$hook} must be registered exactly once under the static guard"
            );
        }
    }

    // OUM-10 — the @catalogo_core templates resolve and render without tarifario.

    public function test_catalogo_core_renders_opcional_tab_and_pane_without_tarifario(): void
    {
        $loader = $this->bootTwigBuild();
        $twig = $this->twigFor($loader);

        $saved = $this->hostFsc(false);
        $header = ViewHookRegistry::render($twig, 'ventas_opcional_tabs_after', ['fsc' => $saved]);
        $this->assertStringContainsString('tab_tarifario_precios', $header, 'Tab header must target the frozen pane id');
        $this->assertStringContainsString(
            'page=tarif_opcional_tab&action=rows&ref=7',
            $header,
            'Tab header must point at the catalogo_core opcional rows endpoint'
        );

        $pane = ViewHookRegistry::render($twig, 'ventas_opcional_tab_pane_after', ['fsc' => $saved]);
        $this->assertStringContainsString('id="tab_tarifario_precios"', $pane, 'Pane must carry the frozen DOM id');

        $new = $this->hostFsc(true);
        $this->assertSame(
            '',
            trim(ViewHookRegistry::render($twig, 'ventas_opcional_tabs_after', ['fsc' => $new])),
            'An unsaved opcional must render no tab header'
        );
        $this->assertSame(
            '',
            trim(ViewHookRegistry::render($twig, 'ventas_opcional_tab_pane_after', ['fsc' => $new])),
            'An unsaved opcional must render no pane'
        );
    }

    // OUM-10 — the moved endpoint keeps CSRF + permission guards and owns its templates.

    public function test_tarif_opcional_tab_endpoint_contract_has_csrf_permission_and_no_bare_simbolo_divisa(): void
    {
        $path = FS_FOLDER . '/plugins/catalogo_core/controller/' . self::ENDPOINT_SLUG . '.php';
        $this->assertFileExists($path, 'Opcional tab endpoint must live in catalogo_core');
        $src = (string) file_get_contents($path);

        $this->assertMatchesRegularExpression(
            '/class\s+' . preg_quote(self::ENDPOINT_SLUG, '/') . '\s+extends\s+fbase_controller\b/',
            $src,
            'tarif_opcional_tab must extend catalogo_core fbase_controller'
        );
        $this->assertStringContainsString('use TarifarioOpcionalStateTrait;', $src, 'Endpoint must reuse the state trait');
        $this->assertStringContainsString('requireCsrf()', $src, 'Endpoint mutations must validate CSRF');
        $this->assertStringContainsString('puede_editar_opcional(', $src, 'Endpoint must gate the price save');
        $this->assertStringContainsString(
            '@catalogo_core/Hooks/partials/opcional_precios_rows.html.twig',
            $src,
            'Endpoint must render the catalogo_core rows partial'
        );

        $compact = (string) preg_replace('/[\s\'"]+/', '', $src);
        $this->assertStringNotContainsString(
            'plugins/tarifario/',
            $compact,
            'Endpoint must be self-contained in catalogo_core (no plugins/tarifario/... references)'
        );

        // S28 — no bare no-argument currency helper call anywhere under the moved hooks tree.
        $hooksDir = FS_FOLDER . '/plugins/catalogo_core/View/Hooks';
        $this->assertDirectoryExists($hooksDir, 'Moved hooks tree must exist under catalogo_core');
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

    // =====================================================================
    // WU-5 — the moved tab runs on htmx 4 + Alpine CSP (OUM-08/OUM-10, AD-10)
    // =====================================================================

    public function test_moved_tab_pane_boots_htmx_and_alpine(): void
    {
        $pane = $this->movedSource(self::PANE_TEMPLATE);

        $this->assertStringContainsString('htmx.boot(', $pane, 'Pane must boot htmx 4');
        $this->assertStringContainsString("'allowScriptTags': false", $pane, 'Pane must use the fragment scrubber');
        $this->assertStringContainsString('alpine.boot()', $pane, 'Pane must boot Alpine CSP');
    }

    public function test_moved_tab_mutations_use_hx_post_and_never_hx_delete(): void
    {
        $source = $this->movedSource(self::ROWS_PARTIAL) . $this->movedSource(self::SAVE_SCRIPT);

        $this->assertMatchesRegularExpression(
            '/hx-post|htmx\.ajax\(\'POST\'/',
            $source,
            'Moved tab mutations must be POST-based'
        );
        $this->assertStringNotContainsString('hx-delete', $source, 'htmx 4 colon migration forbids hx-delete');
    }

    public function test_moved_tab_registers_alpine_data_with_nonce_and_init_guard(): void
    {
        $script = $this->movedSource(self::SAVE_SCRIPT);
        $pane = $this->movedSource(self::PANE_TEMPLATE);

        $this->assertStringContainsString('Alpine.data(', $script, 'Script must register components via Alpine.data()');
        $this->assertStringContainsString('alpine:init', $script, 'Registration must happen behind alpine:init');
        $this->assertStringContainsString('csp_nonce_attr()', $script, 'Script tag must be nonce\'d');
        $this->assertStringContainsString('[x-cloak]', $pane, 'Pane must gate hidden Alpine components with [x-cloak]');
        $this->assertStringNotContainsString('innerHTML', $script, 'x-text/plain DOM only — never innerHTML');
    }

    public function test_moved_tab_uses_colon_events_and_no_v2_names(): void
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
}
