<?php
declare(strict_types=1);
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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;
use FSFramework\View\ViewHookRegistry;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * Host-side contract for the render_hook markers injected into the
 * ventas_articulo / ventas_opcional views (SDD: tarifario-catalogo-hook-integration,
 * PR1b — spec scenarios S3, S8, S12, S14, S15, S16, S17).
 *
 * The markers are guest-neutral: they resolve hook names through
 * ViewHookRegistry and render '' (zero bytes) when no plugin registers them.
 */
class CatalogoCoreHookMarkersTest extends TestCase
{
    /** Frozen by spec delta R-TAR-HOOK-003 — MUST NOT change. */
    private const FROZEN_HOOKS = [
        'ventas_articulo' => ['ventas_articulo_tabs_after', 'ventas_articulo_tab_pane_after'],
        'ventas_opcional' => ['ventas_opcional_tabs_after', 'ventas_opcional_tab_pane_after'],
    ];

    private const VIEWS = [
        'ventas_articulo' => '/View/ventas_articulo.html.twig',
        'ventas_opcional' => '/View/ventas_opcional.html.twig',
    ];

    private const LIST_VIEW = '/View/ventas_articulos.html.twig';

    /**
     * S3 allowlist — files allowed to mention "tarifario" (case-insensitive) in
     * the production tree. Every entry needs a one-line rationale. Scan excludes
     * vendor/ (third-party), tests/ (test fixtures) and openspec/ (SDD docs,
     * not production code) — the self-skipping override tests live under tests/
     * and are therefore covered by that path exclusion.
     */
    private const TARIFARIO_ALLOWLIST = [
        // Deliberate legacy-migration bridge: migrates legacy tarifario tables to canonical catalog names.
        '/Services/CatalogLegacyTableMigration.php',
        // Header comment only ("estilo tarifario tarif_articulos") — documentation, no code reference.
        '/View/partials/articulos/modal_nuevo_articulo.html.twig',
        // Table comment ("compatibilidad con tarifario") — documentation, no code reference.
        '/model/table/catalogo_listas_precio.xml',
        // Plugin description text mentioning consumer plugins — not code.
        '/description',
    ];

    protected function setUp(): void
    {
        // Reset registry static state between tests (same pattern as core ViewHookRegistryTest).
        $ref = new \ReflectionClass(ViewHookRegistry::class);
        $prop = $ref->getProperty('hooks');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    // =====================================================================
    // S15 — frozen names only
    // =====================================================================

    public function testHostViewsDeclareExactlyTheFourFrozenHookNames(): void
    {
        $allNames = [];
        foreach (self::VIEWS as $page => $relative) {
            $names = $this->extractHookNames($this->viewPath($relative));
            $this->assertSame(
                self::FROZEN_HOOKS[$page],
                $names,
                sprintf('%s must declare exactly its two frozen hooks, in order', $page)
            );
            $allNames = array_merge($allNames, $names);
        }

        $this->assertSame(
            array_merge(...array_values(self::FROZEN_HOOKS)),
            $allNames,
            'Across the host views exactly the 4 frozen hook names may exist (S15)'
        );
    }

    // =====================================================================
    // S8 — zero-byte marker property (whitespace-control + empty registry)
    // =====================================================================

    public function testEveryMarkerLineUsesWhitespaceControlZeroByteForm(): void
    {
        foreach (self::VIEWS as $page => $relative) {
            $markerLines = $this->extractMarkerLines($this->viewPath($relative));
            $this->assertCount(
                2,
                $markerLines,
                sprintf('%s must contain exactly 2 marker lines', $page)
            );
            foreach ($markerLines as $line) {
                $this->assertMatchesRegularExpression(
                    '/\{\{-\s*render_hook\(.*-\s*\}\}/',
                    $line,
                    sprintf(
                        '%s: marker must use the {{- -}} whitespace-control form so an unregistered hook contributes zero bytes (S8)',
                        $page
                    )
                );
            }
        }
    }

    public function testMarkersRenderZeroBytesWithEmptyRegistry(): void
    {
        $twig = $this->createTwigWithRenderHook();

        foreach (self::VIEWS as $page => $relative) {
            $markerLines = $this->extractMarkerLines($this->viewPath($relative));
            $this->assertCount(
                2,
                $markerLines,
                sprintf('%s must contain exactly 2 marker lines', $page)
            );
            foreach ($markerLines as $index => $markerLine) {
                // Simulate the on-its-own-line placement with surrounding indentation:
                // {{- -}} must strip ALL of it so the construct emits exactly ''.
                $template = "\n" . str_repeat(' ', 16) . $markerLine . "\n" . str_repeat(' ', 12);

                $output = $twig->createTemplate($template)->render([]);
                $this->assertSame(
                    '',
                    $output,
                    sprintf(
                        '%s marker #%d must render exactly zero bytes with an empty registry (S8/S12/S14)',
                        $page,
                        $index
                    )
                );
            }
        }
    }

    public function testMarkerRenderPathProducesFragmentWithRegisteredListener(): void
    {
        foreach (self::FROZEN_HOOKS as $page => $hooks) {
            foreach ($hooks as $index => $hook) {
                $fragment = 'INJECTED_FRAGMENT_' . $hook;
                ViewHookRegistry::register($hook, 'test/' . $hook . '.html.twig');
                $this->assertTrue(
                    ViewHookRegistry::has($hook),
                    sprintf('Frozen hook %s must be resolvable through the registry', $hook)
                );

                $twig = $this->createTwigWithRenderHook(['test/' . $hook . '.html.twig' => $fragment]);
                $markerLines = $this->extractMarkerLines($this->viewPath(self::VIEWS[$page]));
                $this->assertArrayHasKey(
                    $index,
                    $markerLines,
                    sprintf('%s must contain marker #%d for hook %s', $page, $index, $hook)
                );
                $markerLine = $markerLines[$index];

                $output = $twig->createTemplate($markerLine)->render([]);
                $this->assertSame(
                    $fragment,
                    $output,
                    sprintf('Marker for %s must render the registered template fragment unescaped (is_safe html)', $hook)
                );
            }
        }
    }

    // =====================================================================
    // S16 — no list-page hooks in this slice
    // =====================================================================

    public function testNoListPageHooksExist(): void
    {
        // The two host views must not declare any ventas_articulos_* hook names (S16).
        foreach (self::VIEWS as $page => $relative) {
            foreach ($this->extractHookNames($this->viewPath($relative)) as $name) {
                $this->assertStringStartsNotWith(
                    'ventas_articulos_',
                    $name,
                    sprintf('%s must not declare list-page hooks in this slice (S16)', $page)
                );
            }
        }

        // The list page itself must carry no render_hook markers at all (S16).
        $listView = $this->viewPath(self::LIST_VIEW);
        $this->assertFileExists($listView, 'List view ventas_articulos.html.twig must exist to be audited');
        $this->assertSame(
                [],
                $this->extractHookNames($listView),
                'ventas_articulos list page must not contain render_hook markers in this slice (S16)'
        );
    }

    // =====================================================================
    // S17 — naming pattern extensible
    // =====================================================================

    public function testAllHookNamesFollowPageSuffixPattern(): void
    {
        foreach (self::FROZEN_HOOKS as $page => $hooks) {
            foreach ($hooks as $hook) {
                $this->assertMatchesRegularExpression(
                    '/^[a-z_]+_(tabs_after|tab_pane_after)$/',
                    $hook,
                    'Hook names must follow the {page}_{tabs_after|tab_pane_after} pattern so future slices can derive ventas_articulos_* without breaking this contract (S17)'
                );
            }
        }
    }

    // =====================================================================
    // S3 — zero tarifario coupling (grep audit as PHPUnit test)
    // =====================================================================

    public function testNoTarifarioReferencesOutsideDocumentedAllowlist(): void
    {
        $pluginRoot = $this->pluginRoot();
        $excludedDirs = ['vendor', 'tests', 'openspec', '.git'];
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($pluginRoot, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current) use ($excludedDirs): bool {
                    if ($current->isDir()) {
                        return !in_array($current->getFilename(), $excludedDirs, true);
                    }
                    return true;
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $contents = @file_get_contents($file->getPathname());
            if ($contents === false || stripos($contents, 'tarifario') === false) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($pluginRoot)));
            if (in_array($relative, self::TARIFARIO_ALLOWLIST, true)) {
                continue;
            }
            $violations[] = $relative;
        }

        $this->assertSame(
            [],
            $violations,
            "Zero tarifario coupling (S3): unexpected 'tarifario' references found. " .
            'Either remove the reference or, if it is a documented exception, add it to TARIFARIO_ALLOWLIST with a rationale.'
        );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function pluginRoot(): string
    {
        return dirname(__DIR__);
    }

    private function viewPath(string $relative): string
    {
        return $this->pluginRoot() . $relative;
    }

    /**
     * @return string[] hook names in file order
     */
    private function extractHookNames(string $path): array
    {
        preg_match_all("/render_hook\(\s*'([^']+)'/", (string) file_get_contents($path), $matches);
        return $matches[1];
    }

    /**
     * @return string[] raw source lines containing a render_hook call, trimmed
     */
    private function extractMarkerLines(string $path): array
    {
        $lines = [];
        foreach (file($path) ?: [] as $line) {
            if (strpos($line, 'render_hook(') !== false) {
                $lines[] = trim($line);
            }
        }
        return $lines;
    }

    /**
     * Minimal Twig environment that registers render_hook exactly like the core
     * does in src/Core/Html.php (is_safe: ['html'], delegating to ViewHookRegistry).
     */
    private function createTwigWithRenderHook(array $templates = []): Environment
    {
        $twig = new Environment(new ArrayLoader($templates), ['autoescape' => 'html']);
        $twig->addFunction(new \Twig\TwigFunction(
            'render_hook',
            function (string $name, array $context = []) use ($twig): string {
                return ViewHookRegistry::render($twig, $name, $context);
            },
            ['is_safe' => ['html']]
        ));
        return $twig;
    }
}
