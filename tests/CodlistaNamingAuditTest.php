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
    /**
     * Production files introduced or modified by multitarifa (PR1-PR3 scope).
     * Test files are excluded deliberately: they legitimately contain the
     * forbidden strings inside negative assertions.
     *
     * @var list<string>
     */
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
        'Services/GroupPermissionListener.php',
        'Services/CatalogoRoleListNormalizer.php',
        'Services/CatalogoPriceResolver.php',
        'Services/CatalogoPriceUpdateService.php',
        'Init.php',
        'controller/ventas_listas_precio.php',
        'controller/opciones_catalogo.php',
        'controller/catalogo_actualizar_precios.php',
        'Controller/VentasArticulo.php',
        'Controller/VentasArticulos.php',
        'view/ventas_listas_precio.html.twig',
        'view/opciones_catalogo.html.twig',
        'view/catalogo_actualizar_precios.html.twig',
        'View/ventas_articulo.html.twig',
        'View/ventas_articulos.html.twig',
    ];

    /** @var list<string> forbidden legacy vocabularies (D7 + R-CEXC-002) */
    private const FORBIDDEN_PATTERNS = [
        'codtarifa' => '/codtarifa/i',
        'grupocliente' => '/grupocliente|grupo_clientes|customer[\s_]?group/i',
    ];

    public function test_audited_files_exist(): void
    {
        // Guards against a vacuous pass: every audited path must resolve
        // (a missing file would silently skip the scan below).
        $missing = [];
        foreach (self::AUDITED_FILES as $relative) {
            if (!is_file($this->pluginPath($relative))) {
                $missing[] = $relative;
            }
        }

        $this->assertSame([], $missing, 'Audited files must exist: ' . implode(', ', $missing));
    }

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
        // __DIR__ = plugins/catalogo_core/tests → plugin root is one level up.
        return dirname(__DIR__, 1) . '/' . $relative;
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
