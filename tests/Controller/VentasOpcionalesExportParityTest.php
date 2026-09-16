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

namespace Tests\CatalogoCore\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Delta spec `opcionales-management` OUM-07 (WU-4).
 *
 * The unified list export MUST emit the frozen header set and MUST NOT read or
 * depend on any opcional `en_catalogo`/`en_tarifa` flag: with the flags removed,
 * the emitted header set and the row set stay identical to the pre-change
 * output. The row source honors the active filters.
 */
final class VentasOpcionalesExportParityTest extends TestCase
{
    private const TRAIT = '/plugins/catalogo_core/extras/VentasOpcionalesListTrait.php';

    /** The frozen export header set (OUM-07, byte-identical). */
    private const HEADERS = [
        'Codigo (No editar)',
        'Ref SAP:',
        'Descripción',
        'Precio',
        'Familia',
        'Subfamilia',
    ];

    public function test_export_header_set_is_frozen(): void
    {
        $source = $this->source(self::TRAIT);
        $body = $this->methodBody($source, 'export_excel_opcionales');

        $this->assertNotSame('', $body, 'export_excel_opcionales() body must be found');

        $expected = '$headers = [' . implode(', ', array_map(
            static fn (string $h): string => "'" . $h . "'",
            self::HEADERS
        )) . '];';

        $this->assertStringContainsString(
            $expected,
            $this->normalize($body),
            'the export header set must stay byte-identical to the pre-change output'
        );
    }

    public function test_export_row_set_ignores_the_visibility_flags(): void
    {
        // Same filtered row source on both subjects, but the resolved visibility
        // cache of one of them hides every row: the exported rows must be
        // identical, so the comparison cannot be vacuous.
        $hidden = $this->buildExportSubject('T1', [
            1 => ['en_catalogo' => false, 'en_tarifa' => false],
            2 => ['en_catalogo' => false, 'en_tarifa' => false],
            3 => ['en_catalogo' => false, 'en_tarifa' => false],
        ]);
        $visible = $this->buildExportSubject('T1', [
            1 => ['en_catalogo' => true, 'en_tarifa' => true],
            2 => ['en_catalogo' => true, 'en_tarifa' => true],
            3 => ['en_catalogo' => true, 'en_tarifa' => true],
        ]);

        $this->assertNotSame(
            $visible->visibilityCache(),
            $hidden->visibilityCache(),
            'the fixture must expose differing resolved visibility for at least one row'
        );
        $this->assertFalse(
            $hidden->visibilityCache()[1]['en_catalogo'],
            'the hidden subject must resolve at least one row as not visible'
        );

        // The row source is the filtered search; it never consults the derived
        // visibility cache, so an opcional whose visibility is FALSE is still
        // exported with the same rows.
        $hiddenRows = $hidden->exportRows();
        $visibleRows = $visible->exportRows();

        $this->assertSame(
            $visibleRows,
            $hiddenRows,
            'the exported row set must be identical regardless of resolved visibility'
        );
        $this->assertSame(
            ['OPC-1', 'OPC-2', 'OPC-3'],
            $hiddenRows,
            'all filtered rows must be exported'
        );
    }

    public function test_export_path_never_reads_a_visibility_flag(): void
    {
        $source = $this->source(self::TRAIT);

        $export = $this->methodBody($source, 'export_excel_opcionales');
        $rows = $this->methodBody($source, 'load_opcionales_for_export');

        foreach ([$export, $rows] as $body) {
            $this->assertStringNotContainsString('en_catalogo', $body, 'the export must not read the catalog flag');
            $this->assertStringNotContainsString('en_tarifa', $body, 'the export must not read the tarifa flag');
            $this->assertStringNotContainsString(
                'opcionales_visibility_cache',
                $body,
                'the export must not depend on the derived visibility cache'
            );
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Minimal list subject exposing the export row source.
     *
     * @param array<int, array<string, bool>> $visibilityCache seeded resolved
     *        visibility, so a subject can carry a hidden row set.
     */
    private function buildExportSubject(string $codtarifa, array $visibilityCache = []): object
    {
        if (!class_exists(\VentasOpcionalesListTrait::class, false)) {
            require_once FS_FOLDER . self::TRAIT;
        }

        $request = Request::create('/index.php?page=ventas_opcionales&b_codtarifa=' . $codtarifa, 'GET');

        return new class ($request, $visibilityCache) {
            use \VentasOpcionalesListTrait;

            public $request;
            public $db = null;

            /** @param array<int, array<string, bool>> $visibilityCache */
            public function __construct($request, array $visibilityCache)
            {
                $this->request = $request;
                $this->b_codfamilia = '';
                $this->b_codtarifa = 'T1';
                $this->b_id_grupo = '';
                $this->b_solo_activos = false;
                $this->offset = 0;
                $this->tarifa_seleccionada = (object) ['codtarifa' => 'T1'];
                $this->opcionales_visibility_cache = $visibilityCache;
            }

            /** @return array<int, array<string, bool>> */
            public function visibilityCache(): array
            {
                return $this->opcionales_visibility_cache;
            }

            protected function opcional_model()
            {
                return new class {
                    private int $calls = 0;

                    public function search($query, $offset, $codfamilia, $codtarifa, $solo_activos, $id_grupo)
                    {
                        $this->calls++;
                        if ($this->calls > 1) {
                            return [];
                        }

                        return [
                            (object) ['id' => 1, 'codigo' => 'OPC-1'],
                            (object) ['id' => 2, 'codigo' => 'OPC-2'],
                            (object) ['id' => 3, 'codigo' => 'OPC-3'],
                        ];
                    }
                };
            }

            /** @return list<string> */
            public function exportRows(): array
            {
                $codes = [];
                foreach ($this->load_opcionales_for_export() as $row) {
                    $codes[] = (string) $row->codigo;
                }

                return $codes;
            }
        };
    }

    private function source(string $relative): string
    {
        $full = FS_FOLDER . $relative;
        if (!is_file($full)) {
            self::fail('missing catalogo_core file: ' . $relative);
        }

        return (string) file_get_contents($full);
    }

    private function normalize(string $body): string
    {
        return (string) preg_replace('/\s+/', ' ', $body);
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
}
