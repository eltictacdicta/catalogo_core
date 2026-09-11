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

use FSFramework\View\ViewHookRegistry;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * Delta spec `catalogo-render-hooks` (D8): the four frozen insertion points
 * live in the catalogo_core opcional/article host views and render nothing
 * when no listener is registered.
 *
 * Two layers:
 * 1. Source contract — the exact four marker calls sit at the frozen positions
 *    (immediately before the `#ul_tabs`/`#tab_articulo` `</ul>` and before each
 *    `.tab-content` closing `</div>`), use Twig whitespace control and the
 *    frozen `{'fsc','user','empresa','i18n'}` context.
 * 2. Render contract — the authored marker expressions, compiled in a Twig
 *    environment wired exactly like `src/Core/Html.php` (`render_hook` is
 *    `is_safe:['html']`), yield `''` with an empty registry, emit the
 *    registered fragment unescaped, swallow + log a throwing template and pass
 *    the frozen context through. The full host views are rendered with stubbed
 *    shells so the marker output is observed at its real position.
 */
final class CatalogoCoreHookMarkersTest extends TestCase
{
    private const VIEW_DIR = '/plugins/catalogo_core/View';

    private const OPCIONAL_VIEW = 'ventas_opcional.html.twig';
    private const ARTICULO_VIEW = 'ventas_articulo.html.twig';

    /** The only four hook names this change is allowed to declare. */
    private const FROZEN_NAMES = [
        'ventas_articulo_tab_pane_after',
        'ventas_articulo_tabs_after',
        'ventas_opcional_tab_pane_after',
        'ventas_opcional_tabs_after',
    ];

    private ?string $logFile = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resetHookRegistry();

        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'catalogo_hook_log_');
        ini_set('error_log', $this->logFile);
    }

    protected function tearDown(): void
    {
        $this->resetHookRegistry();
        ini_restore('error_log');
        if ($this->logFile !== null && is_file($this->logFile)) {
            @unlink($this->logFile);
        }
        parent::tearDown();
    }

    private function resetHookRegistry(): void
    {
        $prop = (new \ReflectionClass(ViewHookRegistry::class))->getProperty('hooks');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // =====================================================================
    // Source contract — frozen positions
    // =====================================================================

    public function test_ventas_opcional_tabs_marker_sits_immediately_before_the_ul_tabs_close(): void
    {
        $lines = $this->sourceLines(self::OPCIONAL_VIEW);
        $ulOpen = $this->findLine($lines, 'id="ul_tabs"');
        $ulClose = $this->firstClosingTagAfter($lines, $ulOpen, '</ul>');
        $marker = $this->markerLineIndex($lines, 'ventas_opcional_tabs_after');

        $this->assertSame($ulClose - 1, $marker, 'ventas_opcional_tabs_after must be the line directly before the #ul_tabs </ul>');
    }

    public function test_ventas_opcional_pane_marker_sits_immediately_before_the_tab_content_close(): void
    {
        $lines = $this->sourceLines(self::OPCIONAL_VIEW);
        $contentOpen = $this->findLine($lines, 'class="tab-content"');
        $contentClose = $this->matchingCloseDiv($lines, $contentOpen);
        $marker = $this->markerLineIndex($lines, 'ventas_opcional_tab_pane_after');

        $this->assertSame($contentClose - 1, $marker, 'ventas_opcional_tab_pane_after must be the line directly before the .tab-content closing </div>');
    }

    public function test_ventas_articulo_tabs_marker_sits_immediately_before_the_ul_close(): void
    {
        $lines = $this->sourceLines(self::ARTICULO_VIEW);
        $ulOpen = $this->findLine($lines, 'id="tab_articulo"');
        $ulClose = $this->firstClosingTagAfter($lines, $ulOpen, '</ul>');
        $marker = $this->markerLineIndex($lines, 'ventas_articulo_tabs_after');

        $this->assertSame($ulClose - 1, $marker, 'ventas_articulo_tabs_after must be the line directly before the tab </ul>');
    }

    public function test_ventas_articulo_pane_marker_sits_immediately_before_the_tab_content_close(): void
    {
        $lines = $this->sourceLines(self::ARTICULO_VIEW);
        $contentOpen = $this->findLine($lines, 'class="tab-content"');
        $contentClose = $this->matchingCloseDiv($lines, $contentOpen);
        $marker = $this->markerLineIndex($lines, 'ventas_articulo_tab_pane_after');

        $this->assertSame($contentClose - 1, $marker, 'ventas_articulo_tab_pane_after must be the line directly before its .tab-content closing </div>');
    }

    // =====================================================================
    // Source contract — names, whitespace control, frozen context
    // =====================================================================

    public function test_views_declare_exactly_the_four_frozen_names(): void
    {
        $names = array_merge(
            $this->declaredHookNames(self::OPCIONAL_VIEW),
            $this->declaredHookNames(self::ARTICULO_VIEW)
        );
        sort($names);
        $expected = self::FROZEN_NAMES;
        sort($expected);

        $this->assertSame($expected, $names, 'Exactly the four frozen hook names must be declared');

        foreach ($names as $name) {
            $this->assertStringNotContainsString('ventas_articulos_', $name, 'No ventas_articulos_* hook may appear in this change');
        }
    }

    public function test_every_marker_uses_whitespace_control_and_the_frozen_context(): void
    {
        $all = [];
        foreach ([self::OPCIONAL_VIEW, self::ARTICULO_VIEW] as $view) {
            $all += $this->markerLines($view);
        }
        $this->assertCount(4, $all, 'All four frozen markers must be declared');

        foreach ([self::OPCIONAL_VIEW, self::ARTICULO_VIEW] as $view) {
            foreach ($this->markerLines($view) as $name => $line) {
                $this->assertMatchesRegularExpression(
                    '/\{\{- render_hook\(\'' . preg_quote($name, '/') . '\', \{\'fsc\': fsc, \'user\': user, \'empresa\': empresa, \'i18n\': i18n\}\) -\}\}/',
                    $line,
                    "Marker {$name} must use Twig whitespace control and the frozen context"
                );
                $this->assertStringNotContainsString('|raw', $line, "Marker {$name} must not need |raw (render_hook is is_safe:['html'])");
            }
        }
    }

    public function test_naming_pattern_is_derivable_and_has_no_bare_tarifa_token(): void
    {
        $declared = $this->allDeclaredNames();
        $this->assertCount(4, $declared, 'All four frozen markers must be declared');

        foreach ($declared as $name) {
            $this->assertMatchesRegularExpression(
                '/^ventas_[a-z_]+_(tabs_after|tab_pane_after)$/',
                $name,
                "Hook {$name} must follow {page}_tabs_after / {page}_tab_pane_after"
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\btarifa\b/',
                $name,
                "Hook {$name} must not contain the bare token 'tarifa'"
            );
        }

        // A later ventas_articulos_* slice derives cleanly without touching the frozen four.
        $derived = ['ventas_articulos_tabs_after', 'ventas_articulos_tab_pane_after'];
        foreach ($derived as $name) {
            $this->assertMatchesRegularExpression('/^ventas_[a-z_]+_(tabs_after|tab_pane_after)$/', $name);
            $this->assertNotContains($name, self::FROZEN_NAMES);
        }
    }

    // =====================================================================
    // Render contract — empty registry, unescaped fragment, swallowed throw,
    // context propagation
    // =====================================================================

    public function test_empty_registry_marker_contributes_exactly_zero_bytes(): void
    {
        $twig = $this->buildHostEnvironment();

        $names = $this->allDeclaredNames();
        $this->assertCount(4, $names, 'All four frozen markers must be declared');

        foreach ($names as $name) {
            $expression = $this->markerExpression($name);
            $rendered = $twig->createTemplate("A\n" . $expression . "\nB")->render();
            $this->assertSame('AB', $rendered, "Empty marker {$name} must trim to zero bytes");
        }
    }

    public function test_registered_fragment_renders_unescaped_at_the_host_marker(): void
    {
        $twig = $this->buildHostEnvironment([
            '@test/opcional_tabs.html.twig' => '<li id="hook-opcional-tab"><b>HOOK-OPCIONAL-TABS</b></li>',
            '@test/opcional_pane.html.twig' => '<div id="hook-opcional-pane"><b>HOOK-OPCIONAL-PANE</b></div>',
            '@test/articulo_tabs.html.twig' => '<li id="hook-articulo-tab"><b>HOOK-ARTICULO-TABS</b></li>',
            '@test/articulo_pane.html.twig' => '<div id="hook-articulo-pane"><b>HOOK-ARTICULO-PANE</b></div>',
        ]);
        ViewHookRegistry::register('ventas_opcional_tabs_after', '@test/opcional_tabs.html.twig');
        ViewHookRegistry::register('ventas_opcional_tab_pane_after', '@test/opcional_pane.html.twig');
        ViewHookRegistry::register('ventas_articulo_tabs_after', '@test/articulo_tabs.html.twig');
        ViewHookRegistry::register('ventas_articulo_tab_pane_after', '@test/articulo_pane.html.twig');

        $opcional = $twig->render(self::OPCIONAL_VIEW, $this->hostContext($this->hostFsc(false)));
        $this->assertStringContainsString('<li id="hook-opcional-tab"><b>HOOK-OPCIONAL-TABS</b></li>', $opcional);
        $this->assertStringContainsString('<div id="hook-opcional-pane"><b>HOOK-OPCIONAL-PANE</b></div>', $opcional);
        $this->assertStringNotContainsString('&lt;b&gt;HOOK-OPCIONAL-TABS', $opcional, 'The fragment must not be HTML-escaped');

        $articulo = $twig->render(self::ARTICULO_VIEW, $this->hostContext($this->hostFsc(false, 'REF-777')));
        $this->assertStringContainsString('<li id="hook-articulo-tab"><b>HOOK-ARTICULO-TABS</b></li>', $articulo);
        $this->assertStringContainsString('<div id="hook-articulo-pane"><b>HOOK-ARTICULO-PANE</b></div>', $articulo);
    }

    public function test_frozen_context_keys_reach_the_hook_template(): void
    {
        $twig = $this->buildHostEnvironment([
            '@test/context.html.twig' => 'CTX[{{ fsc.opcional.id }}|{{ user }}|{{ empresa }}|{{ i18n }}]',
        ]);
        ViewHookRegistry::register('ventas_opcional_tabs_after', '@test/context.html.twig');

        $html = $twig->render(self::OPCIONAL_VIEW, $this->hostContext($this->hostFsc(false)));

        $this->assertStringContainsString('CTX[7|USR-SENTINEL|EMP-SENTINEL|I18N-SENTINEL]', $html);
    }

    public function test_throwing_hook_template_is_swallowed_and_logged(): void
    {
        $twig = $this->buildHostEnvironment([
            '@test/good.html.twig' => '<span>GOOD-HOOK</span>',
            '@test/broken.html.twig' => '{{ this_function_does_not_exist_at_all() }}',
        ]);
        ViewHookRegistry::register('ventas_opcional_tabs_after', '@test/good.html.twig');
        ViewHookRegistry::register('ventas_opcional_tabs_after', '@test/broken.html.twig');

        $html = $twig->render(self::OPCIONAL_VIEW, $this->hostContext($this->hostFsc(false)));

        $this->assertStringContainsString('<span>GOOD-HOOK</span>', $html, 'A throwing template must not drop the already-rendered HTML');
        $this->assertStringContainsString('class="tab-content"', $html, 'The host page must still render after the throw');

        $log = (string) @file_get_contents((string) $this->logFile);
        $this->assertStringContainsString('[ViewHookRegistry] Error rendering hook "ventas_opcional_tabs_after"', $log);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /** @return string[] */
    private function sourceLines(string $view): array
    {
        $path = FS_FOLDER . self::VIEW_DIR . '/' . $view;
        $this->assertFileExists($path, "missing host view: {$path}");

        return file($path, FILE_IGNORE_NEW_LINES) ?: [];
    }

    /** @param string[] $lines */
    private function findLine(array $lines, string $needle, int $from = 0): int
    {
        for ($i = $from, $count = count($lines); $i < $count; $i++) {
            if (str_contains($lines[$i], $needle)) {
                return $i;
            }
        }

        $this->fail("No line containing '{$needle}' was found");
    }

    /** @param string[] $lines */
    private function firstClosingTagAfter(array $lines, int $from, string $tag): int
    {
        $i = $this->findLine($lines, $tag, $from);
        $this->assertSame(trim($lines[$i]), $tag, "Expected a standalone {$tag} line");

        return $i;
    }

    /** @param string[] $lines Index of the </div> that balances the <div> opened at $open. */
    private function matchingCloseDiv(array $lines, int $open): int
    {
        $depth = 0;
        for ($i = $open, $count = count($lines); $i < $count; $i++) {
            $depth += (int) preg_match_all('/<div\b/', $lines[$i]);
            $depth -= (int) preg_match_all('#</div>#', $lines[$i]);
            if ($depth === 0) {
                return $i;
            }
        }

        $this->fail('No balancing </div> found for the .tab-content block');
    }

    /** @param string[] $lines */
    private function markerLineIndex(array $lines, string $name): int
    {
        return $this->findLine($lines, "render_hook('{$name}'");
    }

    /** @return array<string, string> marker name => source line */
    private function markerLines(string $view): array
    {
        $markers = [];
        foreach ($this->sourceLines($view) as $line) {
            if (preg_match_all("/render_hook\('([^']+)'/", $line, $matches)) {
                foreach ($matches[1] as $name) {
                    $markers[$name] = $line;
                }
            }
        }

        return $markers;
    }

    /** @return string[] */
    private function declaredHookNames(string $view): array
    {
        return array_keys($this->markerLines($view));
    }

    /** @return string[] */
    private function allDeclaredNames(): array
    {
        return array_unique(array_merge(
            $this->declaredHookNames(self::OPCIONAL_VIEW),
            $this->declaredHookNames(self::ARTICULO_VIEW)
        ));
    }

    private function markerExpression(string $name): string
    {
        foreach ([self::OPCIONAL_VIEW, self::ARTICULO_VIEW] as $view) {
            foreach ($this->sourceLines($view) as $line) {
                if (str_contains($line, "render_hook('{$name}'") && preg_match('/\{\{- .* -\}\}/', $line, $match)) {
                    return $match[0];
                }
            }
        }

        $this->fail("Marker expression for {$name} was not found");
    }

    private function requireCatalogoOpcionalClass(): void
    {
        if (!class_exists('FSFramework\\model\\catalogo_opcional', false)) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
        }
    }

    /**
     * Builds a Twig environment mirroring src/Core/Html.php for the host views:
     * the real view bodies (so the authored markers are exercised), stubbed
     * shells for the theme/partial includes and the real `render_hook`
     * contract (is_safe html).
     *
     * @param array<string, string> $extraTemplates
     */
    private function buildHostEnvironment(array $extraTemplates = []): Environment
    {
        $this->requireCatalogoOpcionalClass();

        $templates = [
            'header.html.twig' => '',
            'footer.html.twig' => '',
            'partials/articulos/tab_multiidioma.html.twig' => '',
            'partials/articulos/tab_opcionales.html.twig' => '',
            self::OPCIONAL_VIEW => (string) file_get_contents(FS_FOLDER . self::VIEW_DIR . '/' . self::OPCIONAL_VIEW),
            self::ARTICULO_VIEW => (string) file_get_contents(FS_FOLDER . self::VIEW_DIR . '/' . self::ARTICULO_VIEW),
        ] + $extraTemplates;

        $twig = new Environment(new ArrayLoader($templates), ['cache' => false, 'auto_reload' => false]);
        $twig->addFilter(new TwigFilter('trans', static fn (string $key, array $params = []): string => $key));
        $twig->addFunction(new TwigFunction('csrf_field', static fn (): string => '<input type="hidden" name="form_token" value="token"/>'));
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
        return [
            'fsc' => $fsc,
            'user' => 'USR-SENTINEL',
            'empresa' => 'EMP-SENTINEL',
            'i18n' => 'I18N-SENTINEL',
        ];
    }

    private function hostFsc(bool $isNew, string $referencia = 'REF-001'): object
    {
        return new HookMarkerHostFsc($isNew, $referencia);
    }
}

/**
 * Minimal host-controller stand-in exposing exactly the members the two host
 * views read, so their real bodies render without a framework boot.
 */
final class HookMarkerHostFsc
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
    ];

    public HookMarkerHostEntity $opcional;
    public HookMarkerHostEntity $articulo;
    public bool $is_new;

    public function __construct(bool $isNew, string $referencia)
    {
        $this->is_new = $isNew;
        $this->opcional = new HookMarkerHostEntity(['id' => 7, 'id_grupo' => '']);
        $this->articulo = new HookMarkerHostEntity(['referencia' => $referencia]);
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
        return 'index.php?page=ventas_opcional';
    }
}

/**
 * Minimal model stand-in: string properties default to '', numeric ones to 0,
 * and any method call resolves to 0.
 */
final class HookMarkerHostEntity
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
