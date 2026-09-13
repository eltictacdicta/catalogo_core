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
 * Ownership contract for the per-tarifa article price model absorbed from
 * tarifario (spec ATT-01/ATT-07, AD-1/AD-4/AD-8).
 *
 * DB-free by design: the moved model file is required (never instantiated with
 * a DB), its namespace + class + table_name source contracts are pinned, the
 * soft history guard is asserted, the catalogo_core production tree is grep-
 * audited for tarifario coupling, and the standalone bootstrap source contract
 * is pinned.
 */
final class ArticuloTarifaPrecioOwnershipTest extends TestCase
{
    private const MODEL_RELATIVE = 'plugins/catalogo_core/model/tarif_articulo_precio.php';
    private const XML_RELATIVE = 'plugins/catalogo_core/model/table/tarif_articulo_precios.xml';
    private const FQCN = 'FSFramework\\model\\tarif_articulo_precio';
    private const TABLE_NAME = 'tarif_articulo_precios';

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

    public function test_model_lives_in_catalogo_core_with_original_fqcn_and_stable_table_name(): void
    {
        $src = $this->source(self::MODEL_RELATIVE);

        $this->assertStringContainsString(
            'namespace FSFramework\\model;',
            $src,
            'The moved model must keep the FSFramework\\model namespace'
        );
        $this->assertMatchesRegularExpression(
            '/class\s+tarif_articulo_precio\b/',
            $src,
            'The moved model must keep its class name'
        );
        $this->assertMatchesRegularExpression(
            "/parent::__construct\('" . self::TABLE_NAME . "'\)/",
            $src,
            'The moved model must keep table_name ' . self::TABLE_NAME
        );

        require_once FS_FOLDER . '/' . self::MODEL_RELATIVE;

        $this->assertTrue(
            class_exists(self::FQCN, false),
            'Class ' . self::FQCN . ' must be defined by ' . self::MODEL_RELATIVE
        );
        $this->assertTrue(
            is_subclass_of(self::FQCN, \fs_model::class),
            'Class ' . self::FQCN . ' must extend fs_model'
        );

        // The physical table name is hardcoded by catalogo_core, so it must stay byte-stable.
        foreach ([
            'plugins/catalogo_core/model/tarif_tarifa.php',
            'plugins/tarifario/controller/tarif_configurador_opcionales.php',
        ] as $consumer) {
            $this->assertStringContainsString(
                self::TABLE_NAME,
                $this->source($consumer),
                $consumer . ' hardcodes the stable table name and must keep resolving it'
            );
        }
    }

    public function test_model_xml_schema_lives_in_catalogo_core(): void
    {
        $xml = $this->source(self::XML_RELATIVE);

        foreach (['referencia', 'codtarifa', 'precio', 'activo', 'en_tarifa', 'en_catalogo', 'en_sap'] as $column) {
            $this->assertStringContainsString(
                '<nombre>' . $column . '</nombre>',
                $xml,
                'The moved XML schema must declare column ' . $column
            );
        }

        $this->assertStringContainsString(
            'PRIMARY KEY (referencia, codtarifa)',
            $xml,
            'The XML schema must stay keyed by (referencia, codtarifa)'
        );
    }

    public function test_tarifario_no_longer_ships_the_model_file(): void
    {
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/tarifario/model/tarif_articulo_precio.php',
            'No class file may exist in both plugins (model)'
        );
        $this->assertFileDoesNotExist(
            FS_FOLDER . '/plugins/tarifario/model/table/tarif_articulo_precios.xml',
            'No schema may exist in both plugins (XML)'
        );
    }

    public function test_history_write_is_class_exists_soft(): void
    {
        $save = $this->methodSource($this->source(self::MODEL_RELATIVE), 'public function save()');

        // Source text carries the escaped FQCN with doubled backslashes.
        $guard = 'class_exists(\'FSFramework\\\\model\\\\tarif_precio_historial\')';
        $guardPos = strpos($save, $guard);
        $writePos = strpos($save, 'tarif_precio_historial::registrar_cambio(');

        $this->assertNotFalse($guardPos, 'save() must wrap the history write in a class_exists guard (AD-4)');
        $this->assertNotFalse($writePos, 'save() must still write history when the model is active');
        $this->assertLessThan(
            $writePos,
            $guardPos,
            'The class_exists guard must run before registrar_cambio() so standalone catalogo_core never fatals'
        );

        $openBrace = strpos($save, '{', $guardPos);
        $closeBrace = strpos($save, '}', $writePos);
        $this->assertNotFalse($openBrace, 'The class_exists guard must open a block');
        $this->assertNotFalse($closeBrace, 'The class_exists guard must close a block');
        $this->assertTrue(
            $openBrace < $writePos && $writePos < $closeBrace,
            'registrar_cambio() must be enclosed by the class_exists guard block'
        );
    }

    public function test_catalogo_core_has_zero_tarifario_paths_for_the_moved_surface(): void
    {
        $roots = [
            FS_FOLDER . '/plugins/catalogo_core/controller',
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
            "catalogo_core production code must not reference the moved tarifario surface:\n" . implode("\n", $hits)
        );
    }

    public function test_catalogo_core_bootstraps_the_article_price_table_standalone(): void
    {
        $src = $this->source('plugins/catalogo_core/Init.php');

        $this->assertStringContainsString(
            'public static function ensureArticuloTarifaTables(): void',
            $src,
            'Init must expose the standalone article price table bootstrap (AD-8)'
        );

        $bootstrap = $this->methodSource($src, 'public static function ensureArticuloTarifaTables(): void');
        $this->assertStringContainsString(
            '/plugins/catalogo_core/model/tarif_articulo_precio.php',
            $bootstrap,
            'The bootstrap must require the moved model from catalogo_core'
        );
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
            'tarif_tarifas must be ensured before the article price table (FK safety)'
        );

        $init = $this->methodSource($src, 'public function init(): void');
        $posOpcionales = strpos($init, 'self::ensureOpcionalesTarifaTables();');
        $posArticulo = strpos($init, 'self::ensureArticuloTarifaTables();');
        $this->assertNotFalse($posOpcionales, 'init() must keep the opcionales bootstrap');
        $this->assertNotFalse($posArticulo, 'init() must wire the article price bootstrap');
        $this->assertLessThan(
            $posArticulo,
            $posOpcionales,
            'The article price bootstrap must run after ensureOpcionalesTarifaTables()'
        );

        $upgrade = $this->methodSource($src, 'public static function upgrade(): void');
        $this->assertStringContainsString(
            'self::ensureArticuloTarifaTables();',
            $upgrade,
            'upgrade() must wire the article price bootstrap'
        );
    }
}
