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
 * Naming audit for the multitarifa change (task 1.5, design D7 + R-CEXC-002).
 *
 * D7: `codlista` is the canonical list key; `codtarifa` is tarifario-only
 * legacy vocabulary and MUST NOT appear in new catalogo_core code.
 * R-CEXC-002: no `grupocliente|grupo_clientes|customer group` vocabulary.
 *
 * Audited scope: the files introduced/modified by the multitarifa change.
 */
final class CodlistaNamingAuditTest extends TestCase
{
    /** @var list<string> files introduced or modified by multitarifa (PR1 scope) */
    private const AUDITED_FILES = [
        'model/table/catalogo_articulo_precios.xml',
        'model/table/catalogo_grupo.xml',
        'model/table/catalogo_grupo_roles.xml',
        'model/table/catalogo_grupo_usuarios.xml',
        'model/table/catalogo_grupo_tarifas.xml',
        'model/table/catalogo_grupo_articulos.xml',
        'model/core/catalogo_articulo_precio.php',
        'model/core/catalogo_grupo.php',
        'model/core/catalogo_grupo_rol.php',
        'model/core/catalogo_grupo_usuario.php',
        'model/core/catalogo_grupo_tarifa.php',
        'model/core/catalogo_grupo_articulo.php',
        'model/core/catalogo_lista_precio.php',
        'Services/CatalogoOptions.php',
        'tests/CatalogoArticuloPrecioTest.php',
        'tests/CatalogoListaPrecioSingleDefaultTest.php',
        'tests/CatalogoGrupoModelsTest.php',
        'tests/Services/CatalogoOptionsTest.php',
        'tests/CodlistaNamingAuditTest.php',
    ];

    /** @var list<string> forbidden legacy vocabularies (D7 + R-CEXC-002) */
    private const FORBIDDEN_PATTERNS = [
        'codtarifa' => '/codtarifa/i',
        'grupocliente' => '/grupocliente|grupo_clientes|customer[\s_]?group/i',
    ];

    public function test_new_files_use_codlista_vocabulary_only(): void
    {
        foreach (self::FORBIDDEN_PATTERNS as $label => $regex) {
            $hits = [];
            foreach (self::AUDITED_FILES as $relative) {
                $path = $this->pluginPath($relative);
                if (!is_file($path)) {
                    continue;
                }
                foreach ($this->grepLines($path, $regex) as $line) {
                    $hits[] = $relative . ':' . $line;
                }
            }

            $this->assertSame(
                [],
                $hits,
                sprintf(
                    'Forbidden legacy vocabulary "%s" found in new catalogo_core files (D7/R-CEXC-002): %s',
                    $label,
                    implode(', ', array_slice($hits, 0, 10))
                )
            );
        }
    }

    private function pluginPath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }

    /** @return list<int> zero-based matching line numbers */
    private function grepLines(string $path, string $regex): array
    {
        $hits = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) as $index => $line) {
            if (preg_match($regex, $line) === 1) {
                $hits[] = $index + 1;
            }
        }

        return $hits;
    }
}
