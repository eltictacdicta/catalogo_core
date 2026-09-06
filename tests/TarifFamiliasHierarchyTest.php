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
 * DB-free hierarchy contract for the moved tarif_familias controller
 * (spec: "Familias hierarchy management" — build_tree/flatten_tree nesting
 * semantics and the public flat-tree API consumed by the view).
 *
 * SQL-bound flows (reorder/promote/demote/capitulo renumbering) need a real
 * database and are deferred to the verify-phase smoke per design.
 */
final class TarifFamiliasHierarchyTest extends TestCase
{
    private const CONTROLLER_PATH = '/plugins/catalogo_core/controller/tarif_familias.php';

    private function loadControllerClass(): void
    {
        if (class_exists('tarif_familias', false)) {
            return;
        }

        if (!is_file(FS_FOLDER . self::CONTROLLER_PATH)) {
            self::fail('missing catalogo_core path: plugins/catalogo_core/controller/tarif_familias.php (moved controller not present)');
        }

        require_once FS_FOLDER . '/base/fs_controller.php';
        require_once FS_FOLDER . self::CONTROLLER_PATH;
    }

    private function makeController()
    {
        $this->loadControllerClass();

        return new class extends \tarif_familias {
            public function __construct()
            {
            }
        };
    }

    private function makeFamilia(string $codfamilia, ?string $madre): \stdClass
    {
        $fam = new \stdClass();
        $fam->codfamilia = $codfamilia;
        $fam->madre = $madre;

        return $fam;
    }

    private function invokePrivate(object $target, string $method, ...$args)
    {
        $ref = new \ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invoke($target, ...$args);
    }

    /** Fixture: A(root) → [B → [D], C] — exercises nesting at three depths. */
    private function fixtureFamilias(): array
    {
        return [
            $this->makeFamilia('A', null),
            $this->makeFamilia('B', 'A'),
            $this->makeFamilia('C', 'A'),
            $this->makeFamilia('D', 'B'),
        ];
    }

    public function test_build_tree_nests_children_under_madre_with_level_increments(): void
    {
        $controller = $this->makeController();
        $tree = $this->invokePrivate($controller, 'build_tree', $this->fixtureFamilias(), null, 0);

        $this->assertCount(1, $tree, 'Only the root familia sits at level 0');
        $root = $tree[0];
        $this->assertSame('A', $root->codfamilia);
        $this->assertSame(0, $root->nivel_tree);

        $this->assertCount(2, $root->hijas_tree, 'A must nest B and C as children');
        $this->assertSame('B', $root->hijas_tree[0]->codfamilia);
        $this->assertSame(1, $root->hijas_tree[0]->nivel_tree);
        $this->assertSame('C', $root->hijas_tree[1]->codfamilia);
        $this->assertSame(1, $root->hijas_tree[1]->nivel_tree);
        $this->assertSame([], $root->hijas_tree[1]->hijas_tree, 'Childless familia flattens to an empty children list');

        $grandchildren = $root->hijas_tree[0]->hijas_tree;
        $this->assertCount(1, $grandchildren);
        $this->assertSame('D', $grandchildren[0]->codfamilia);
        $this->assertSame(2, $grandchildren[0]->nivel_tree, 'nivel_tree increments per nesting level');
    }

    public function test_flatten_tree_preserves_hierarchical_order_and_assigns_nivel_visual(): void
    {
        $controller = $this->makeController();
        $tree = $this->invokePrivate($controller, 'build_tree', $this->fixtureFamilias(), null, 0);

        $flat = $this->invokePrivate($controller, 'flatten_tree', $tree, 0);

        $this->assertSame(['A', 'B', 'D', 'C'], array_column($flat, 'codfamilia'), 'Depth-first order must be preserved');
        $this->assertSame([0, 1, 2, 1], array_column($flat, 'nivel_visual'), 'nivel_visual must match each familia depth');
    }

    public function test_get_familias_flat_exposes_the_public_flat_tree(): void
    {
        $controller = $this->makeController();
        $tree = $this->invokePrivate($controller, 'build_tree', $this->fixtureFamilias(), null, 0);

        $this->assertTrue(method_exists($controller, 'get_familias_flat'), 'get_familias_flat is the view-facing API');

        $controller->familias_tree = $tree;
        $flat = $controller->get_familias_flat();

        $this->assertSame(['A', 'B', 'D', 'C'], array_column($flat, 'codfamilia'));
        $this->assertSame([0, 1, 2, 1], array_column($flat, 'nivel_visual'));
    }

    public function test_get_next_capitulo_action_registered_in_process_action_switch(): void
    {
        $src = (string) file_get_contents(FS_FOLDER . self::CONTROLLER_PATH);
        $this->assertStringContainsString(
            "case 'get_next_capitulo'",
            $src,
            'The get_next_capitulo AJAX action must stay registered for the view'
        );
    }
}
