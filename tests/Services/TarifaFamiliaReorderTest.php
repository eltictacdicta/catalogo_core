<?php
/**
 * This file is part of catalogo_core
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

namespace Tests\CatalogoCore\Services;

use FSFramework\Plugins\catalogo_core\Services\TarifaFamiliaReorder;
use PHPUnit\Framework\TestCase;

/**
 * Pure plan() unit tests — no DB, no model, no framework boot.
 *
 * Covers CRD-03: permutation validation, structure sanity,
 * BFS chapter computation, promote/demote-shaped inputs.
 */
final class TarifaFamiliaReorderTest extends TestCase
{
    // ------------------------------------------------------------------
    // Happy path — valid permutation
    // ------------------------------------------------------------------

    public function test_valid_permutation_produces_sequential_root_chapters(): void
    {
        $flatCodes = ['A', 'B', 'C'];
        $madreByCode = ['A' => null, 'B' => null, 'C' => null];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertTrue($result['ok']);
        $this->assertNull($result['error']);
        $this->assertSame(['A' => '1', 'B' => '2', 'C' => '3'], $result['chapters']);
    }

    public function test_valid_permutation_with_children_assigns_nested_chapters(): void
    {
        // Structure:
        //   A (root)       → chapter 1
        //     A1 (child)   → chapter 1.1
        //     A2 (child)   → chapter 1.2
        //   B (root)       → chapter 2
        //     B1 (child)   → chapter 2.1
        $flatCodes = ['A', 'A1', 'A2', 'B', 'B1'];
        $madreByCode = [
            'A' => null,
            'A1' => 'A',
            'A2' => 'A',
            'B' => null,
            'B1' => 'B',
        ];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertTrue($result['ok']);
        $this->assertEquals([
            'A' => '1',
            'A1' => '1.1',
            'A2' => '1.2',
            'B' => '2',
            'B1' => '2.1',
        ], $result['chapters']);
    }

    public function test_numeric_code_keys_are_not_cast_to_int(): void
    {
        // Regression: PHP casts numeric-string array keys to int. A madre map
        // keyed by '14164907' must not make plan() compare ints against the
        // string codes coming from the JSON payload (CRD-03).
        $numeric = '14164907';
        $flatCodes = [$numeric, 'A'];
        $madreByCode = [$numeric => null, 'A' => $numeric];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertTrue($result['ok'], $result['error'] ?? '');
        $this->assertSame([$numeric => '1', 'A' => '1.1'], $result['chapters']);
    }

    public function test_reorder_changes_chapters_but_preserves_structure(): void
    {
        // B moves before A in the flat list — B gets chapter 1, A gets 2
        $flatCodes = ['B', 'A', 'A1', 'A2', 'B1'];
        $madreByCode = [
            'A' => null,
            'A1' => 'A',
            'A2' => 'A',
            'B' => null,
            'B1' => 'B',
        ];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertTrue($result['ok']);
        $this->assertEquals([
            'B' => '1',
            'A' => '2',
            'A1' => '2.1',
            'A2' => '2.2',
            'B1' => '1.1',
        ], $result['chapters']);
    }

    public function test_three_level_nesting(): void
    {
        // A → A1 → A1a
        $flatCodes = ['A', 'A1', 'A1a', 'B'];
        $madreByCode = [
            'A' => null,
            'A1' => 'A',
            'A1a' => 'A1',
            'B' => null,
        ];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertTrue($result['ok']);
        $this->assertEquals([
            'A' => '1',
            'A1' => '1.1',
            'A1a' => '1.1.1',
            'B' => '2',
        ], $result['chapters']);
    }

    public function test_map_includes_untouched_descendants(): void
    {
        // Full permutation — B moved before A; A1 and A2 are descendants of A
        // that still get correct chapters via BFS cascade
        $flatCodes = ['B', 'B1', 'A', 'A1', 'A2'];
        $madreByCode = [
            'A' => null,
            'A1' => 'A',
            'A2' => 'A',
            'B' => null,
            'B1' => 'B',
        ];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertTrue($result['ok']);
        // B=1, B1=1.1, A=2, A1=2.1, A2=2.2
        $this->assertEquals([
            'B' => '1',
            'B1' => '1.1',
            'A' => '2',
            'A1' => '2.1',
            'A2' => '2.2',
        ], $result['chapters']);
    }

    // ------------------------------------------------------------------
    // Rejection — permutation validation
    // ------------------------------------------------------------------

    public function test_empty_flat_codes_rejects(): void
    {
        $result = TarifaFamiliaReorder::plan([], ['A' => null]);

        $this->assertFalse($result['ok']);
        $this->assertIsString($result['error']);
        $this->assertEmpty($result['chapters']);
    }

    public function test_missing_code_rejects(): void
    {
        // flatCodes is missing 'C' which exists in madreByCode
        $flatCodes = ['A', 'B'];
        $madreByCode = ['A' => null, 'B' => null, 'C' => null];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('missing', strtolower($result['error']));
        $this->assertEmpty($result['chapters']);
    }

    public function test_extra_code_rejects(): void
    {
        // flatCodes has 'D' which doesn't exist in madreByCode
        $flatCodes = ['A', 'B', 'D'];
        $madreByCode = ['A' => null, 'B' => null];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('unknown', strtolower($result['error']));
        $this->assertEmpty($result['chapters']);
    }

    public function test_duplicate_codes_rejects(): void
    {
        $flatCodes = ['A', 'A', 'B'];
        $madreByCode = ['A' => null, 'B' => null];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('duplicate', strtolower($result['error']));
        $this->assertEmpty($result['chapters']);
    }

    // ------------------------------------------------------------------
    // Rejection — structure sanity
    // ------------------------------------------------------------------

    public function test_unknown_madre_reference_rejects(): void
    {
        $flatCodes = ['A', 'B'];
        $madreByCode = ['A' => null, 'B' => 'Z']; // Z doesn't exist

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('madre', strtolower($result['error']));
        $this->assertEmpty($result['chapters']);
    }

    public function test_cycle_rejects(): void
    // ------------------------------------------------------------------
    {
        // A → B → A  (cycle)
        $flatCodes = ['A', 'B'];
        $madreByCode = ['A' => 'B', 'B' => 'A'];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('cycle', strtolower($result['error']));
        $this->assertEmpty($result['chapters']);
    }

    public function test_self_referencing_madre_rejects(): void
    {
        $flatCodes = ['A'];
        $madreByCode = ['A' => 'A'];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertFalse($result['ok']);
        $this->assertEmpty($result['chapters']);
    }

    // ------------------------------------------------------------------
    // Promote / demote shaped inputs
    // ------------------------------------------------------------------

    public function test_promote_shaped_input(): void
    {
        // A1 is child of A; after promote it becomes root (madre=null)
        // flatCodes reflect the new order with A1 as root
        $flatCodes = ['A', 'A1', 'B'];
        $madreByCode = ['A' => null, 'A1' => null, 'B' => null];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertTrue($result['ok']);
        $this->assertSame([
            'A' => '1',
            'A1' => '2',
            'B' => '3',
        ], $result['chapters']);
    }

    public function test_demote_shaped_input(): void
    {
        // A2 becomes child of A1 (madreMap reflects the new structure)
        $flatCodes = ['A', 'A1', 'A2'];
        $madreByCode = ['A' => null, 'A1' => 'A', 'A2' => 'A1'];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertTrue($result['ok']);
        $this->assertSame([
            'A' => '1',
            'A1' => '1.1',
            'A2' => '1.1.1',
        ], $result['chapters']);
    }

    public function test_reorder_with_only_roots(): void
    {
        $flatCodes = ['C', 'A', 'B'];
        $madreByCode = ['A' => null, 'B' => null, 'C' => null];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertTrue($result['ok']);
        $this->assertSame(['C' => '1', 'A' => '2', 'B' => '3'], $result['chapters']);
    }

    public function test_single_root_no_children(): void
    {
        $flatCodes = ['A'];
        $madreByCode = ['A' => null];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertTrue($result['ok']);
        $this->assertSame(['A' => '1'], $result['chapters']);
    }

    // ------------------------------------------------------------------
    // Edge cases
    // ------------------------------------------------------------------

    public function test_disconnected_subtrees_cascade_correctly(): void
    {
        // Two independent subtrees
        //   A → A1 → A1a
        //   B → B1
        $flatCodes = ['A', 'A1', 'A1a', 'B', 'B1'];
        $madreByCode = [
            'A' => null,
            'A1' => 'A',
            'A1a' => 'A1',
            'B' => null,
            'B1' => 'B',
        ];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertTrue($result['ok']);
        $this->assertSame('1', $result['chapters']['A']);
        $this->assertSame('1.1', $result['chapters']['A1']);
        $this->assertSame('1.1.1', $result['chapters']['A1a']);
        $this->assertSame('2', $result['chapters']['B']);
        $this->assertSame('2.1', $result['chapters']['B1']);
    }

    public function test_child_after_another_child_in_flat_list(): void
    {
        // A1 appears AFTER A2 in the flat list — ordering among children
        // is determined by first appearance in flatCodes
        $flatCodes = ['A', 'A2', 'A1'];
        $madreByCode = [
            'A' => null,
            'A1' => 'A',
            'A2' => 'A',
        ];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertTrue($result['ok']);
        $this->assertSame('1', $result['chapters']['A']);
        $this->assertSame('1.1', $result['chapters']['A2']); // first child in flatCodes
        $this->assertSame('1.2', $result['chapters']['A1']); // second child in flatCodes
    }

    public function test_deeply_nested_preserves_prefix(): void
    {
        $flatCodes = ['A', 'A1', 'A1a', 'A1a1'];
        $madreByCode = [
            'A' => null,
            'A1' => 'A',
            'A1a' => 'A1',
            'A1a1' => 'A1a',
        ];

        $result = TarifaFamiliaReorder::plan($flatCodes, $madreByCode);

        $this->assertTrue($result['ok']);
        $this->assertSame('1', $result['chapters']['A']);
        $this->assertSame('1.1', $result['chapters']['A1']);
        $this->assertSame('1.1.1', $result['chapters']['A1a']);
        $this->assertSame('1.1.1.1', $result['chapters']['A1a1']);
    }
}
