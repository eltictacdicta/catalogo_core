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

namespace Tests\CatalogoCore;

use PHPUnit\Framework\TestCase;

/**
 * Ownership contract for the three article-detail models absorbed from
 * tarifario by WU-2 (spec ART-06/ART-07, AD-W2-1/AD-W2-6/AD-W2-8/AD-W2-11).
 *
 * DB-free by design: the moved model files are required (never instantiated
 * with a DB), their namespace + class + table_name source contracts are pinned,
 * the retired edit surfaces are asserted absent from both plugins, the
 * catalogo_core production tree is grep-audited for tarifario coupling, the
 * standalone bootstrap source contract is pinned, and the two retired
 * `fs_page` rows are retired idempotently through a DB-free `fs_page` stub.
 */
final class ArticuloDetalleCanonicoOwnershipTest extends TestCase
{
    /**
     * model => [FQCN, catalogo_core relative path, table name, retired tarifario path]
     *
     * @var array<string, array{string, string, string}>
     */
    private const MOVED_MODELS = [
        'tarif_tarifa_articulo' => [
            'FSFramework\\model\\tarif_tarifa_articulo',
            'plugins/catalogo_core/model/tarif_tarifa_articulo.php',
            'tarif_tarifa_articulo',
        ],
        'tarif_tarifa_articulo_etiqueta' => [
            'FSFramework\\model\\tarif_tarifa_articulo_etiqueta',
            'plugins/catalogo_core/model/tarif_tarifa_articulo_etiqueta.php',
            'tarif_tarifa_articulo_etiqueta',
        ],
        'tarif_articulo_imagen' => [
            'FSFramework\\model\\tarif_articulo_imagen',
            'plugins/catalogo_core/model/tarif_articulo_imagen.php',
            'tarif_articulo_imagenes',
        ],
    ];

    /**
     * Retired slug => [controller path, view path, expected fs_page slug].
     *
     * @var array<string, array{string, string}>
     */
    private const RETIRED_SURFACES = [
        'tarif_articulo_edit' => [
            'plugins/tarifario/controller/tarif_articulo_edit.php',
            'plugins/tarifario/View/tarif_articulo_edit.html.twig',
        ],
        'tarif_articulo_precios' => [
            'plugins/tarifario/controller/tarif_articulo_precios.php',
            'plugins/tarifario/View/tarif_articulo_precios.html.twig',
        ],
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
    }

    private function source(string $relative): string
    {
        $path = FS_FOLDER . '/' . $relative;
        if (!is_file($path)) {
            self::fail('missing catalogo_core path: ' . $relative);
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

    public function test_moved_models_live_only_in_catalogo_core(): void
    {
        foreach (self::MOVED_MODELS as $model => [$fqcn, $relative, $table]) {
            $this->assertFileExists(
                FS_FOLDER . '/' . $relative,
                'Moved model ' . $model . ' must exist in catalogo_core'
            );
            $this->assertFileDoesNotExist(
                FS_FOLDER . '/plugins/tarifario/model/' . $model . '.php',
                'Moved model ' . $model . ' must no longer live in tarifario (AD-W2-11)'
            );

            $src = $this->source($relative);
            $this->assertStringContainsString(
                'namespace FSFramework\\model;',
                $src,
                'Moved model ' . $model . ' must keep the FSFramework\\model namespace'
            );
            $this->assertMatchesRegularExpression(
                '/class\s+' . preg_quote($model, '/') . '\b/',
                $src,
                'Moved model ' . $model . ' must keep its class name'
            );
            $this->assertMatchesRegularExpression(
                "/parent::__construct\('" . preg_quote($table, '/') . "'\)/",
                $src,
                'Moved model ' . $model . ' must keep table_name ' . $table
            );

            require_once FS_FOLDER . '/' . $relative;
            $this->assertTrue(
                class_exists($fqcn, false),
                'Class ' . $fqcn . ' must be defined by ' . $relative
            );
            $this->assertTrue(
                is_subclass_of($fqcn, \fs_model::class),
                'Class ' . $fqcn . ' must extend fs_model'
            );
        }
    }

    public function test_moved_model_xml_schemas_live_in_catalogo_core(): void
    {
        $xml = [
            'plugins/catalogo_core/model/table/tarif_tarifa_articulo.xml' => [
                'PRIMARY KEY (codtarifa, referencia)',
                'tarif_tarifas',
                'articulos',
            ],
            'plugins/catalogo_core/model/table/tarif_tarifa_articulo_etiqueta.xml' => [
                'PRIMARY KEY (codtarifa, referencia, etiqueta)',
            ],
            'plugins/catalogo_core/model/table/tarif_articulo_imagenes.xml' => [
                'PRIMARY KEY (id)',
                'articulos',
            ],
        ];

        foreach ($xml as $relative => $needles) {
            $this->assertFileExists(FS_FOLDER . '/' . $relative, $relative . ' must exist in catalogo_core');
            $this->assertFileDoesNotExist(
                FS_FOLDER . '/plugins/tarifario/' . substr($relative, strlen('plugins/catalogo_core/')),
                $relative . ' must no longer live in tarifario'
            );

            $src = $this->source($relative);
            foreach ($needles as $needle) {
                $this->assertStringContainsString(
                    $needle,
                    $src,
                    $relative . ' must keep ' . $needle
                );
            }
        }
    }

    public function test_retired_edit_surfaces_leave_no_alias(): void
    {
        foreach (self::RETIRED_SURFACES as $slug => [$controller, $view]) {
            $this->assertFileDoesNotExist(FS_FOLDER . '/' . $controller, $slug . ' controller must be retired (ART-06)');
            $this->assertFileDoesNotExist(FS_FOLDER . '/' . $view, $slug . ' view must be retired (ART-06)');

            $catalogoController = str_replace('plugins/tarifario/', 'plugins/catalogo_core/', $controller);
            $catalogoView = str_replace('plugins/tarifario/', 'plugins/catalogo_core/', $view);
            $this->assertFileDoesNotExist(
                FS_FOLDER . '/' . $catalogoController,
                $slug . ' must not gain a catalogo_core alias controller'
            );
            $this->assertFileDoesNotExist(
                FS_FOLDER . '/' . $catalogoView,
                $slug . ' must not gain a catalogo_core alias view'
            );
        }
    }

    /**
     * ART-06 scenario 3: the two retired `fs_page` rows are deleted by
     * `Init::upgrade()` and a second run is a harmless no-op.
     *
     * Source contract (project standard, mirroring
     * `InitUpgradeTest::upgradeRetiresTarifOpcionalPreciosPageIdempotently`):
     * a behavior test would need a global `fs_page` double, and PHPUnit leaks
     * that declaration into the parent process, fatally colliding with the real
     * `model/fs_page.php`. The guard `if ($existing !== false)` is what makes
     * the retirement idempotent because `fs_page::get()` returns FALSE once the
     * row is gone.
     */
    public function test_retired_fs_page_rows_are_deleted_idempotently(): void
    {
        $src = $this->source('plugins/catalogo_core/Init.php');
        $upgrade = $this->methodSource($src, 'public static function upgrade(): void');

        foreach ([
            'retireTarifArticuloEditPage' => 'tarif_articulo_edit',
            'retireTarifArticuloPreciosPage' => 'tarif_articulo_precios',
        ] as $method => $slug) {
            $this->assertStringContainsString(
                'private static function ' . $method . '(): void',
                $src,
                'Init must expose the idempotent ' . $slug . ' page retirement'
            );
            $this->assertStringContainsString(
                'self::' . $method . '();',
                $upgrade,
                'upgrade() must wire the ' . $slug . ' retirement in its own try/catch'
            );

            $retire = $this->methodSource($src, 'private static function ' . $method . '(): void');
            $this->assertStringContainsString(
                "get('" . $slug . "')",
                $retire,
                'The retirement must resolve the fs_page row by name'
            );
            $this->assertStringContainsString(
                '->delete()',
                $retire,
                'The retirement must delete via the fs_page model'
            );
            $this->assertMatchesRegularExpression(
                '/if\s*\(\s*\$existing\s*!==\s*false\s*\)\s*\{\s*\$existing->delete\(\);\s*\}/s',
                $retire,
                'delete() must be gated by the fs_page::get() result so a second upgrade() run is a no-op'
            );
        }
    }

    public function test_catalogo_core_bootstraps_the_absorbed_detail_tables_standalone(): void
    {
        $src = $this->source('plugins/catalogo_core/Init.php');

        $this->assertStringContainsString(
            'public static function ensureArticuloDetalleTables(): void',
            $src,
            'Init must expose the standalone article detail table bootstrap (AD-W2-8)'
        );

        $bootstrap = $this->methodSource($src, 'public static function ensureArticuloDetalleTables(): void');
        foreach (self::MOVED_MODELS as $model => [, $relative]) {
            $this->assertStringContainsString(
                '/' . $relative,
                $bootstrap,
                'The bootstrap must require ' . $model . ' from catalogo_core'
            );
        }
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*is_file\(\$file\s*\)\s*\)\s*\{\s*require_once\s+\$file;\s*\}/',
            $bootstrap,
            'Model requires must be guarded by is_file'
        );
        $this->assertMatchesRegularExpression('/class_exists\(\$fqcn,\s*false\)/', $bootstrap);
        $this->assertMatchesRegularExpression('/is_subclass_of\(\$fqcn,\s*\\\\fs_model::class\)/', $bootstrap);
        $this->assertStringContainsString(
            'self::ensureFamiliasTarifaTables();',
            $bootstrap,
            'tarif_tarifas must be ensured before the FK-dependent detail tables'
        );

        // FK-safe order: tarif_tarifa_articulo → tarif_tarifa_articulo_etiqueta → tarif_articulo_imagen.
        $positions = [];
        foreach (array_keys(self::MOVED_MODELS) as $model) {
            $positions[$model] = strpos($bootstrap, '/' . 'plugins/catalogo_core/model/' . $model . '.php');
            $this->assertNotFalse($positions[$model], $model . ' must be required in the bootstrap');
        }
        $this->assertLessThan($positions['tarif_tarifa_articulo_etiqueta'], $positions['tarif_tarifa_articulo']);
        $this->assertLessThan($positions['tarif_articulo_imagen'], $positions['tarif_tarifa_articulo_etiqueta']);

        $init = $this->methodSource($src, 'public function init(): void');
        $posArticulo = strpos($init, 'self::ensureArticuloTarifaTables();');
        $posDetalle = strpos($init, 'self::ensureArticuloDetalleTables();');
        $this->assertNotFalse($posArticulo, 'init() must keep the WU-1 article price bootstrap');
        $this->assertNotFalse($posDetalle, 'init() must wire the absorbed detail bootstrap');
        $this->assertLessThan(
            $posDetalle,
            $posArticulo,
            'The absorbed detail bootstrap must run after ensureArticuloTarifaTables()'
        );

        $upgrade = $this->methodSource($src, 'public static function upgrade(): void');
        $this->assertStringContainsString(
            'self::ensureArticuloDetalleTables();',
            $upgrade,
            'upgrade() must wire the absorbed detail bootstrap'
        );

        // AD-W2-8: the WU-1-frozen bootstrap body must not absorb the new models.
        $frozen = $this->methodSource($src, 'public static function ensureArticuloTarifaTables(): void');
        foreach (array_keys(self::MOVED_MODELS) as $model) {
            $this->assertStringNotContainsString(
                $model,
                $frozen,
                'ensureArticuloTarifaTables() is frozen (AD-W2-8); ' . $model . ' belongs to ensureArticuloDetalleTables()'
            );
        }
    }

    /**
     * ART-07 scenario 1: zero `plugins/tarifario/` and `@tarifario/` hits in
     * the catalogo_core production tree for the absorbed detail surface.
     */
    public function test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_detail_surface(): void
    {
        $roots = [
            FS_FOLDER . '/plugins/catalogo_core/controller',
            FS_FOLDER . '/plugins/catalogo_core/Controller',
            FS_FOLDER . '/plugins/catalogo_core/model',
            FS_FOLDER . '/plugins/catalogo_core/View',
            FS_FOLDER . '/plugins/catalogo_core/Services',
            FS_FOLDER . '/plugins/catalogo_core/extras',
        ];

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
                if (strpos($path, '/vendor/') !== false || strpos($path, '/openspec/') !== false) {
                    continue;
                }
                if (!in_array(strtolower($file->getExtension()), ['php', 'twig', 'html', 'js'], true)) {
                    continue;
                }
                $files[] = $path;
            }
        }
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
            "catalogo_core production code must not reference the absorbed tarifario surface:\n" . implode("\n", $hits)
        );
    }
}
