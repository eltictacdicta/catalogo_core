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
 * Grep gate for the dead tarif opcional tables (spec: "Dead table references
 * removed and FKs repointed"). Scans the catalogo_core and tarifario plugin
 * trees (excluding vendor/, openspec/ and tests) for raw SQL/DDL against the
 * dead `tarif_articulo_opcional` and `tarif_opcionales` tables.
 *
 * The whole-token boundary `([^_a-zA-Z0-9]|$)` is the false-positive guard:
 * it must not match live names (`tarif_tarifa_articulo_opcional`,
 * `tarif_tarifa_opcional_familia`, `tarif_opcional_ext`,
 * `tarif_opcional_precios`) nor the intentional legacy read
 * `FROM tarif_opcional_precios` in CatalogLegacyTableMigration.
 *
 * Slice note (S1a): `test_no_dead_table_references_remain` is authored as the
 * full zero-hit gate (task 1.4). After S1a it is expected to report only the
 * four `tarif_articulos.php` raw-SQL sites, which are repointed by S3 (design
 * D4 / task 4.5) before the gate turns fully GREEN.
 */
final class DeadOpcionalTableReferenceTest extends TestCase
{
    private const DEAD_TABLE_PATTERN = '/(FROM|JOIN|INTO|REFERENCES|UPDATE)[[:space:]]+`?(tarif_articulo_opcional|tarif_opcionales)`?([^_a-zA-Z0-9]|$)/';

    /** @return list<string> */
    private function sourceFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $path = $file->getPathname();
            if (strpos($path, '/vendor/') !== false
                || strpos($path, '/openspec/') !== false
                || strpos($path, '/tests/') !== false
            ) {
                continue;
            }

            if (!in_array(strtolower($file->getExtension()), ['php', 'xml'], true)) {
                continue;
            }

            $files[] = $path;
        }

        sort($files);

        return $files;
    }

    /** @return list<string> */
    private function deadReferenceHits(): array
    {
        $hits = [];

        foreach ([FS_FOLDER . '/plugins/catalogo_core', FS_FOLDER . '/plugins/tarifario'] as $root) {
            foreach ($this->sourceFiles($root) as $file) {
                $lines = explode("\n", (string) file_get_contents($file));
                foreach ($lines as $index => $line) {
                    if (preg_match(self::DEAD_TABLE_PATTERN, $line)) {
                        $hits[] = str_replace(FS_FOLDER . '/', '', $file) . ':' . ($index + 1);
                    }
                }
            }
        }

        return $hits;
    }

    public function test_pattern_rejects_live_names_and_matches_dead_tables(): void
    {
        foreach ([
            'FROM tarif_tarifa_articulo_opcional x',
            'FROM tarif_tarifa_opcional_familia x',
            'FROM tarif_opcional_ext x',
            'FROM tarif_opcional_precios x',
            'FROM tarif_opcional_precios op',
        ] as $live) {
            $this->assertDoesNotMatchRegularExpression(
                self::DEAD_TABLE_PATTERN,
                $live,
                'False-positive guard must not flag a live name: ' . $live
            );
        }

        $this->assertMatchesRegularExpression(self::DEAD_TABLE_PATTERN, 'FROM tarif_articulo_opcional ao');
        $this->assertMatchesRegularExpression(self::DEAD_TABLE_PATTERN, 'DELETE FROM tarif_articulo_opcional;');
        $this->assertMatchesRegularExpression(self::DEAD_TABLE_PATTERN, 'REFERENCES tarif_opcionales (id)');
    }

    public function test_no_dead_table_references_remain(): void
    {
        $hits = $this->deadReferenceHits();

        $this->assertSame(
            [],
            $hits,
            "Dead table references remain (must be zero after the change):\n" . implode("\n", $hits)
        );
    }

    public function test_moved_fk_xmls_target_catalogo_opcionales(): void
    {
        foreach ([
            'tarif_tarifa_opcional_familia.xml',
            'tarif_tarifa_articulo_opcional.xml',
        ] as $xml) {
            $path = FS_FOLDER . '/plugins/catalogo_core/model/table/' . $xml;
            $this->assertFileExists($path, 'Moved FK XML must exist in catalogo_core: ' . $xml);

            $source = (string) file_get_contents($path);
            $this->assertStringContainsString(
                'catalogo_opcionales (id)',
                $source,
                $xml . ' must reference catalogo_opcionales (id)'
            );
            $this->assertStringNotContainsString(
                'tarif_opcionales (id)',
                $source,
                $xml . ' must not reference the dead tarif_opcionales table'
            );
        }
    }

    public function test_dead_xmls_and_deprecated_wrappers_are_removed(): void
    {
        foreach ([
            'plugins/tarifario/model/table/tarif_opcionales.xml',
            'plugins/tarifario/model/table/tarif_opcional_familia.xml',
            'plugins/tarifario/model/table/tarif_articulo_opcional.xml',
            'plugins/tarifario/model/table/tarif_opcional_precios.xml',
            'plugins/tarifario/model/tarif_opcional_familia.php',
            'plugins/tarifario/model/tarif_articulo_opcional.php',
        ] as $relative) {
            $this->assertFileDoesNotExist(
                FS_FOLDER . '/' . $relative,
                'Dead artifact must be removed: ' . $relative
            );
        }
    }
}
