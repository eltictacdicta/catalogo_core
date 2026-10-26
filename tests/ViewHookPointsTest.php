<?php
declare(strict_types=1);
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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Frozen-hook contract of the host views (familias-jerarquia task 4.2,
 * R-FJ-007).
 *
 * `tarifario` still renders its tariff UI into `catalogo_core` pages through
 * four ViewHookRegistry hook points. This change absorbs family/catalog UI into
 * `catalogo_core`'s own pages and MUST NOT remove, rename, or shadow any of
 * them, so this is a regression guard: it is green by construction before and
 * after the change and turns red the moment a template edit moves one of the
 * four markers. Templates are the only source of truth here — the registry
 * resolves names at Twig build time, so presence in the host template is the
 * whole contract this side of the coupling owns.
 */
final class ViewHookPointsTest extends TestCase
{
    /** Frozen by spec delta R-FJ-007 — MUST NOT change in this change. */
    private const FROZEN_HOOKS = [
        'ventas_articulo' => [
            'ventas_articulo_tabs_after',
            'ventas_articulo_tab_pane_after',
        ],
        'ventas_opcional' => [
            'ventas_opcional_tabs_after',
            'ventas_opcional_tab_pane_after',
        ],
    ];

    private const VIEWS = [
        'ventas_articulo' => '/View/ventas_articulo.html.twig',
        'ventas_opcional' => '/View/ventas_opcional.html.twig',
    ];

    #[Test]
    public function everyFrozenHookKeepsItsMarkerInItsHostView(): void
    {
        foreach (self::VIEWS as $page => $relative) {
            $path = $this->viewPath($relative);
            $this->assertFileExists($path, sprintf('%s template must exist to be audited', $page));

            $names = $this->extractHookNames($path);
            $this->assertSame(
                self::FROZEN_HOOKS[$page],
                $names,
                sprintf(
                    '%s must still declare exactly its two frozen hook points, in order (R-FJ-007)',
                    $page
                )
            );
        }
    }

    #[Test]
    public function noHookPointIsShadowedOrDuplicatedAnywhereInTheViews(): void
    {
        $occurrences = $this->allHookNamesInViews();

        $this->assertSame(
            array_merge(...array_values(self::FROZEN_HOOKS)),
            $occurrences,
            'Across the whole catalogo_core View/ tree exactly the four frozen hook names may appear, '
            . 'once each and in host order: a duplicate would shadow the marker tarifario resolves (R-FJ-007)'
        );
    }

    #[Test]
    public function hookMarkersKeepTheWhitespaceControlFormThatRendersZeroBytes(): void
    {
        // An unregistered hook must contribute zero bytes; the {{- -}} form is
        // what guarantees that when tarifario is inactive (R-FJ-007 scenario 2).
        foreach (self::VIEWS as $page => $relative) {
            foreach ($this->extractMarkerLines($this->viewPath($relative)) as $line) {
                $this->assertMatchesRegularExpression(
                    '/\{\{-\s*render_hook\(.*-\s*\}\}/',
                    $line,
                    sprintf('%s hook markers must keep the {{- -}} whitespace-control form', $page)
                );
            }
        }
    }

    private function pluginRoot(): string
    {
        return dirname(__DIR__);
    }

    private function viewPath(string $relative): string
    {
        return $this->pluginRoot() . $relative;
    }

    /**
     * @return list<string> hook names in file order
     */
    private function extractHookNames(string $path): array
    {
        preg_match_all("/render_hook\(\s*'([^']+)'/", (string) file_get_contents($path), $matches);

        return $matches[1];
    }

    /**
     * @return list<string> every hook name declared under View/, in discovery order
     */
    private function allHookNamesInViews(): array
    {
        $root = $this->pluginRoot() . '/View';
        $names = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        $files = [];
        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isFile() && $file->getExtension() === 'twig') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        foreach ($files as $path) {
            foreach ($this->extractHookNames($path) as $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return list<string> raw source lines containing a render_hook call, trimmed
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
}
