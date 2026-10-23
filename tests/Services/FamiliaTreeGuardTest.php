<?php
/**
 * This file is part of the catalogo_core plugin
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

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\FamiliaTreeGuard;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Tests\CatalogoCore\Fixtures\FamiliaEstructuraMigrationFakeDb;

/**
 * Cycle protection for the family tree (R-FJ-001 cycle scenario, D5): a
 * re-parent must never make a family a child of itself or of one of its own
 * descendants.
 */
final class FamiliaTreeGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/plugins/catalogo_core/tests/fixtures/familia_estructura_migration_fake_db.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/FamiliaTreeGuard.php';
    }

    private function db(): FamiliaEstructuraMigrationFakeDb
    {
        return new FamiliaEstructuraMigrationFakeDb([
            'familias' => [
                ['codfamilia' => 'A', 'madre' => null],
                ['codfamilia' => 'B', 'madre' => 'A'],
                ['codfamilia' => 'C', 'madre' => 'B'],
                ['codfamilia' => 'D', 'madre' => null],
                ['codfamilia' => 'E', 'madre' => 'D'],
            ],
        ]);
    }

    #[Test]
    public function selfParentIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cycle');

        FamiliaTreeGuard::assertAcyclic($this->db(), 'A', 'A');
    }

    #[Test]
    public function directCycleIsRejected(): void
    {
        // A would become a child of B, but B is already a child of A.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cycle');

        FamiliaTreeGuard::assertAcyclic($this->db(), 'A', 'B');
    }

    #[Test]
    public function deepCycleIsRejected(): void
    {
        // A would become a child of C, whose chain is C -> B -> A.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('cycle');

        FamiliaTreeGuard::assertAcyclic($this->db(), 'A', 'C');
    }

    #[Test]
    public function validChainPassesAndWalksToRoot(): void
    {
        $db = $this->db();

        FamiliaTreeGuard::assertAcyclic($db, 'A', 'E');

        $this->assertNotEmpty(
            $db->selected,
            'the guard must walk the target mother chain before allowing the move'
        );
        $this->assertStringContainsString("codfamilia = 'E'", $db->selected[0]);
        $this->assertStringContainsString("codfamilia = 'D'", $db->selected[1]);
        $this->assertCount(2, $db->selected, 'the walk must stop at the root (D has no mother)');
    }

    #[Test]
    public function missingMotherRowIsAllowed(): void
    {
        $db = $this->db();

        FamiliaTreeGuard::assertAcyclic($db, 'A', 'ZZZ');

        $this->assertStringContainsString("codfamilia = 'ZZZ'", $db->selected[0]);
    }

    #[Test]
    public function emptyMotherMarksRootWithoutQuerying(): void
    {
        $db = $this->db();

        FamiliaTreeGuard::assertAcyclic($db, 'A', '');

        $this->assertSame([], $db->selected, 'moving to the root must not query the mother chain');
    }
}
