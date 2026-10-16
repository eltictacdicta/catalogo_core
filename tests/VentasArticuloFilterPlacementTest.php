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
 * Structural placement tests for the article permission filter on
 * VentasArticulo::editarArticulo() (R-TAR-HOOK-006, design AD-2).
 *
 * The dispatch lives in the neutral gate puedeEditarArticulo() (AD-W2-2);
 * editarArticulo() must consult that gate before any mutation or save.
 * Uses the repo's established source-inspection pattern (cf.
 * VentasArticuloControllerTest) because instantiating the full controller
 * requires the legacy request/session stack.
 *
 * Scenarios covered:
 * - S5: the gate resolves BEFORE the $art->pvp mutation and BEFORE save().
 * - S6: a denial surfaces an error message and returns early.
 * - S7: the allow path (field mutations + save) is intact.
 * - AD-3: listener exceptions are caught and denied fail-closed.
 */
#[CoversNothing]
class VentasArticuloFilterPlacementTest extends TestCase
{
    private const CONTROLLER_FILE = '/plugins/catalogo_core/Controller/VentasArticulo.php';

    private function methodSource(string $signature): string
    {
        $source = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_FILE);
        $start = strpos($source, $signature);
        $this->assertNotFalse($start, $signature . ' must exist in VentasArticulo.php');

        $nextMethod = preg_match(
            '/\n    (?:public|protected|private) (?:static )?function /',
            $source,
            $match,
            PREG_OFFSET_CAPTURE,
            $start + 10
        );
        $this->assertSame(1, $nextMethod, $signature . ' body extraction failed');

        return substr($source, $start, $match[0][1] - $start);
    }

    private function editarArticuloSource(): string
    {
        return $this->methodSource('protected function editarArticulo(');
    }

    private function gateSource(): string
    {
        return $this->methodSource('protected function puedeEditarArticulo(');
    }

    public function testGateDispatchesPermissionFilter(): void
    {
        $source = $this->gateSource();

        $this->assertStringContainsString(
            'new ArticlePermissionFilterEvent(',
            $source,
            'puedeEditarArticulo() must construct the permission filter event'
        );
        $this->assertStringContainsString(
            'FSEventDispatcher::getInstance()->dispatch(',
            $source,
            'Dispatch must go through the core FSEventDispatcher singleton (AD-1)'
        );
    }

    public function testGateOccursBeforePvpMutation(): void
    {
        $source = $this->editarArticuloSource();

        $gatePos = strpos($source, '$this->puedeEditarArticulo(');
        $pvpPos = strpos($source, '$art->pvp =');

        $this->assertNotFalse($gatePos, 'editarArticulo() must consult the permission gate');
        $this->assertNotFalse($pvpPos, 'pvp mutation must exist (allow path intact)');
        $this->assertLessThan($pvpPos, $gatePos, 'S5/AD-2: the filter must resolve BEFORE $art->pvp is mutated');
    }

    public function testGateOccursBeforeSave(): void
    {
        $source = $this->editarArticuloSource();

        $gatePos = strpos($source, '$this->puedeEditarArticulo(');
        $savePos = strpos($source, '$art->save()');

        $this->assertNotFalse($gatePos, 'editarArticulo() must consult the permission gate');
        $this->assertNotFalse($savePos, 'save() call must exist (allow path intact)');
        $this->assertLessThan($savePos, $gatePos, 'S5/AD-2: the filter must resolve BEFORE persistence');
    }

    public function testDenyReturnsBeforeAnyMutation(): void
    {
        $source = $this->editarArticuloSource();

        $gatePos = strpos($source, '$this->puedeEditarArticulo(');
        $firstMutationPos = strpos($source, '$art->');

        $this->assertNotFalse($gatePos, 'editarArticulo() must consult the permission gate');
        $this->assertNotFalse($firstMutationPos, 'Field mutations must exist (allow path intact)');
        $this->assertLessThan($firstMutationPos, $gatePos, 'S5: the gate must precede every article access');

        $between = substr($source, $gatePos, $firstMutationPos - $gatePos);
        $this->assertStringContainsString('new_error_msg(', $between, 'S6: the deny path must surface an error');
        $this->assertStringContainsString('return;', $between, 'S5: the deny path must return before any mutation');
    }

    public function testAllowPathIsIntact(): void
    {
        $source = $this->editarArticuloSource();

        $this->assertStringContainsString(
            "\$art->pvp = (float) \$request->request->get('spvp', 0);",
            $source,
            'S7: allow path keeps the pvp mutation unchanged'
        );
        $this->assertStringContainsString('$art->save()', $source, 'S7: allow path keeps the save call unchanged');
    }

    public function testListenerExceptionDeniesFailClosed(): void
    {
        $source = $this->gateSource();

        $this->assertStringContainsString('try {', $source, 'AD-3: the dispatch must be wrapped in try/catch');
        $this->assertMatchesRegularExpression(
            '/catch \(\\\\Throwable[^)]*\)\s*\{[^}]*return false;/s',
            $source,
            'AD-3: a throwing listener must result in a fail-closed deny'
        );
    }
}
