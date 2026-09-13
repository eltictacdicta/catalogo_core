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

/**
 * Ownership contract for the `tarif_articulos` list retirement (spec
 * ALC-06/ALC-07/ALC-08, AD-W3-7/AD-W3-11).
 *
 * DB-free: the retired surfaces are asserted absent from both plugins, the
 * production trees are grep-audited for the retired slug and for tarifario
 * coupling, the `fs_page` retirement is pinned as an idempotent source
 * contract, and the WU-4 boundary (`tarif_articulo` / `tarif_articulos_ext`)
 * is asserted to stay owned by tarifario.
 */
final class ArticuloListaCanonicaOwnershipTest extends TestCase
{
    /**
     * Former inbound link sites => the view that must now target the canonical
     * list. `b_codtarifa` replaces `codtarifa` where the link carried it.
     *
     * @var array<string, array{string, string}>
     */
    private const REPOINTED_LINKS = [
        'plugins/catalogo_core/View/ventas_opcionales.html.twig' => ['page=ventas_articulos', ''],
        'plugins/catalogo_core/View/Macro/TarifarioComponents.html.twig' => ['page=ventas_articulos', 'b_codtarifa'],
        'plugins/tarifario/View/tarif_tarifas.html.twig' => ['page=ventas_articulos', ''],
        'plugins/tarifario/View/tarif_actualizar_precios.html.twig' => ['page=ventas_articulos', ''],
        'plugins/tarifario/View/tarif_historial_precios.html.twig' => ['page=ventas_articulos', ''],
        'plugins/tarifario/View/tarif_articulo.html.twig' => ['page=ventas_articulos', ''],
    ];

    private function source(string $relative): string
    {
        $path = FS_FOLDER . '/' . $relative;
        if (!is_file($path)) {
            self::fail('missing path: ' . $relative);
        }

        return (string) file_get_contents($path);
    }

    /** Extracts a method body by brace matching from its signature. */
    private function methodSource(string $src, string $signature): string
    {
        $start = strpos($src, $signature);
        if ($start === false) {
            self::fail('missing catalogo_core method: ' . $signature);
        }

        $open = (int) strpos($src, '{', $start);
        $depth = 0;
        $len = strlen($src);
        for ($i = $open; $i < $len; $i++) {
            if ($src[$i] === '{') {
                $depth++;
            } elseif ($src[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($src, $start, $i - $start + 1);
                }
            }
        }

        self::fail('method body is not terminated: ' . $signature);
    }

    /**
     * @return list<string> production files (php/twig/js), tests/vendor/openspec excluded
     */
    private function productionFiles(array $roots): array
    {
        $files = [];
        foreach ($roots as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }
                $path = $file->getPathname();
                if (strpos($path, '/vendor/') !== false || strpos($path, '/openspec/') !== false || strpos($path, '/tests/') !== false) {
                    continue;
                }
                if (!in_array(strtolower($file->getExtension()), ['php', 'twig', 'html', 'js'], true)) {
                    continue;
                }
                $files[] = $path;
            }
        }
        sort($files);

        return $files;
    }

    public function test_retired_list_leaves_no_alias_in_either_plugin(): void
    {
        foreach ([
            'plugins/tarifario/controller/tarif_articulos.php',
            'plugins/tarifario/View/tarif_articulos.html.twig',
            'plugins/catalogo_core/controller/tarif_articulos.php',
            'plugins/catalogo_core/View/tarif_articulos.html.twig',
        ] as $relative) {
            $this->assertFileDoesNotExist(
                FS_FOLDER . '/' . $relative,
                $relative . ' must be retired with no alias (ALC-06 scenario 1)'
            );
        }

        // The action relocation lives in tarifario, not as a controller alias.
        $handler = $this->source('plugins/tarifario/Services/ArticuloListActionHandler.php');
        $this->assertStringContainsString('implements ArticuloListActionHandlerInterface', $handler);
        $this->assertStringContainsString('FSFramework\\Plugins\\tarifario\\Services', $handler);
    }

    public function test_retired_slug_has_zero_inbound_links(): void
    {
        $roots = [
            FS_FOLDER . '/plugins/catalogo_core',
            FS_FOLDER . '/plugins/tarifario',
        ];
        // Composed so this test file never matches its own audit.
        $retiredSlug = 'page=tarif_' . 'articulos';

        $hits = [];
        foreach ($this->productionFiles($roots) as $file) {
            $lines = explode("\n", (string) file_get_contents($file));
            foreach ($lines as $index => $line) {
                if (strpos($line, $retiredSlug) !== false) {
                    $hits[] = str_replace(FS_FOLDER . '/', '', $file) . ':' . ($index + 1);
                }
            }
        }

        $this->assertSame([], $hits, "the retired slug must have zero inbound links:\n" . implode("\n", $hits));

        foreach (self::REPOINTED_LINKS as $relative => [$page, $extra]) {
            $src = $this->source($relative);
            $this->assertStringContainsString($page, $src, $relative . ' must repoint to the canonical list');
            if ($extra !== '') {
                $this->assertStringContainsString($extra, $src, $relative . ' must keep the tarifa filter as ' . $extra);
            }
        }
    }

    public function test_retired_fs_page_row_is_deleted_idempotently(): void
    {
        $src = $this->source('plugins/catalogo_core/Init.php');
        $upgrade = $this->methodSource($src, 'public static function upgrade(): void');

        $this->assertStringContainsString(
            'private static function retireTarifArticulosPage(): void',
            $src,
            'Init must expose the idempotent tarif_articulos page retirement (AD-W3-7)'
        );
        $this->assertStringContainsString(
            'self::retireTarifArticulosPage();',
            $upgrade,
            'upgrade() must wire the retirement in its own try/catch'
        );

        $retire = $this->methodSource($src, 'private static function retireTarifArticulosPage(): void');
        $this->assertStringContainsString("get('tarif_articulos')", $retire, 'the retirement must resolve the fs_page row by name');
        $this->assertStringContainsString('->delete()', $retire, 'the retirement must delete via the fs_page model');
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*\$existing\s*!==\s*false\s*\)\s*\{\s*\$existing->delete\(\);\s*\}/s',
            $retire,
            'delete() must be gated so a second upgrade() run is a no-op (ALC-06 scenario 3)'
        );
    }

    public function test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_list_surface(): void
    {
        $roots = [
            FS_FOLDER . '/plugins/catalogo_core/controller',
            FS_FOLDER . '/plugins/catalogo_core/Controller',
            FS_FOLDER . '/plugins/catalogo_core/model',
            FS_FOLDER . '/plugins/catalogo_core/View',
            FS_FOLDER . '/plugins/catalogo_core/Services',
            FS_FOLDER . '/plugins/catalogo_core/extras',
        ];

        $files = $this->productionFiles($roots);
        $files[] = FS_FOLDER . '/plugins/catalogo_core/Init.php';
        sort($files);

        $hits = [];
        foreach ($files as $file) {
            $lines = explode("\n", (string) file_get_contents($file));
            foreach ($lines as $index => $line) {
                if (strpos($line, 'plugins/tarifario/') !== false || strpos($line, '@tarifario/') !== false) {
                    $hits[] = str_replace(FS_FOLDER . '/', '', $file) . ':' . ($index + 1);
                }
            }
        }

        $this->assertSame(
            [],
            $hits,
            "catalogo_core production code must not reference the absorbed list surface:\n" . implode("\n", $hits)
        );
    }

    public function test_tarif_articulo_and_ext_stay_in_tarifario_for_wu4(): void
    {
        foreach ([
            'plugins/tarifario/model/tarif_articulo.php',
            'plugins/tarifario/model/tarif_articulos_ext.php',
            'plugins/tarifario/model/table/tarif_articulos.xml',
        ] as $relative) {
            $this->assertFileExists(
                FS_FOLDER . '/' . $relative,
                $relative . ' must stay owned by tarifario (WU-4 boundary)'
            );
            $catalogoTwin = str_replace('plugins/tarifario/', 'plugins/catalogo_core/', $relative);
            $this->assertFileDoesNotExist(
                FS_FOLDER . '/' . $catalogoTwin,
                $catalogoTwin . ' must not exist in catalogo_core (WU-4 boundary)'
            );
        }

        // RBAC boundary: the guest listener stays in tarifario (AD-W4-8).
        $this->assertFileExists(FS_FOLDER . '/plugins/tarifario/Services/ArticlePermissionListener.php');
    }
}
