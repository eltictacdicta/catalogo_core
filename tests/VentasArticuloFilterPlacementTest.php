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

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Structural placement tests for the article permission filter dispatch
 * inside VentasArticulo::editarArticulo() (R-TAR-HOOK-006, design AD-2).
 *
 * Uses the repo's established source-inspection pattern (cf.
 * VentasArticuloControllerTest) because instantiating the full controller
 * requires the legacy request/session stack.
 *
 * Scenarios covered:
 * - S5: the dispatch happens BEFORE the $art->pvp mutation and BEFORE save().
 * - S6: a denial surfaces an error message embedding getDenialReason().
 * - S7: the allow path (field mutations + save) is intact.
 * - AD-3: listener exceptions are caught and denied fail-closed.
 */
#[CoversNothing]
class VentasArticuloFilterPlacementTest extends TestCase
{
    private const CONTROLLER_FILE = '/plugins/catalogo_core/Controller/VentasArticulo.php';

    private function editarArticuloSource(): string
    {
        $source = file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $start = strpos($source, 'private function editarArticulo(');
        $this->assertNotFalse($start, 'editarArticulo() must exist in VentasArticulo.php');

        $nextMethod = strpos($source, "\n    private function ", $start + 10);
        $this->assertNotFalse($nextMethod, 'editarArticulo() body extraction failed');

        return substr($source, $start, $nextMethod - $start);
    }

    public function testEditarArticuloDispatchesPermissionFilter(): void
    {
        $source = $this->editarArticuloSource();

        $this->assertStringContainsString(
            'ArticlePermissionFilterEvent',
            $source,
            'editarArticulo() must construct and dispatch the permission filter event'
        );
        $this->assertStringContainsString(
            'FSEventDispatcher::getInstance()',
            $source,
            'Dispatch must go through the core FSEventDispatcher singleton (AD-1)'
        );
    }

    public function testDispatchOccursBeforePvpMutation(): void
    {
        $source = $this->editarArticuloSource();

        $dispatchPos = strpos($source, 'dispatch(');
        $pvpPos = strpos($source, '$art->pvp =');

        $this->assertNotFalse($dispatchPos, 'Dispatch call must exist');
        $this->assertNotFalse($pvpPos, 'pvp mutation must exist (allow path intact)');
        $this->assertLessThan(
            $pvpPos,
            $dispatchPos,
            'S5/AD-2: the filter must resolve BEFORE $art->pvp is mutated'
        );
    }

    public function testDispatchOccursBeforeSave(): void
    {
        $source = $this->editarArticuloSource();

        $dispatchPos = strpos($source, 'dispatch(');
        $savePos = strpos($source, '$art->save()');

        $this->assertNotFalse($dispatchPos, 'Dispatch call must exist');
        $this->assertNotFalse($savePos, 'save() call must exist (allow path intact)');
        $this->assertLessThan(
            $savePos,
            $dispatchPos,
            'S5/AD-2: the filter must resolve BEFORE persistence'
        );
    }

    public function testDenyBlocksPersistenceAndSurfacesReason(): void
    {
        $source = $this->editarArticuloSource();

        $denyPos = strpos($source, 'isAllowed()');
        $firstMutationPos = strpos($source, '$art->descripcion =');

        $this->assertNotFalse($denyPos, 'editarArticulo() must check isAllowed()');
        $this->assertNotFalse($firstMutationPos, 'Field mutations must exist (allow path intact)');
        $this->assertLessThan(
            $firstMutationPos,
            $denyPos,
            'S5: on deny, no field mutation may run before the early return'
        );

        // Early return between the deny check and the first mutation => save() unreachable.
        $between = substr($source, $denyPos, $firstMutationPos - $denyPos);
        $this->assertStringContainsString(
            'return;',
            $between,
            'S5: the deny path must return before any field mutation'
        );

        $this->assertStringContainsString(
            'getDenialReason()',
            $source,
            'S6: the denial error message must embed getDenialReason()'
        );
    }

    public function testAllowPathIsIntact(): void
    {
        $source = $this->editarArticuloSource();

        $this->assertStringContainsString(
            "\$art->pvp = (float) \$request->request->get('spvp', 0);",
            $source,
            'S7: allow path keeps the pvp mutation unchanged'
        );
        $this->assertStringContainsString(
            '$art->save()',
            $source,
            'S7: allow path keeps the save call unchanged'
        );
    }

    public function testListenerExceptionDeniesFailClosed(): void
    {
        $source = $this->editarArticuloSource();

        $this->assertStringContainsString(
            'try {',
            $source,
            'AD-3: the dispatch must be wrapped in try/catch'
        );
        $this->assertStringContainsString(
            "deny('permission filter error')",
            $source,
            'AD-3: a throwing listener must result in a fail-closed deny with the generic reason'
        );
    }
}
