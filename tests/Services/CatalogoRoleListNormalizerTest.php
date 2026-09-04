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

use FSFramework\Plugins\catalogo_core\Services\CatalogoRoleListNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Shared role-list normalizer (multitarifa task 2.3, R-CO-004).
 *
 * Extracted verbatim from the legacy catalogo_excel_settings controller's
 * catalogo_excel_roles_normalize(): split on ',', trim, drop empties,
 * validate each codrol against the existing fs_roles (save-time whitelist),
 * dedupe preserving first-seen order, implode ','.
 *
 * Fail-closed: unparsable input or zero valid roles yield '' (no grants).
 */
#[CoversClass(CatalogoRoleListNormalizer::class)]
final class CatalogoRoleListNormalizerTest extends TestCase
{
    public function test_keeps_valid_ordered_list(): void
    {
        $this->assertSame(
            'A,B',
            CatalogoRoleListNormalizer::normalize('A,B', ['A', 'B'])
        );
    }

    public function test_trims_and_drops_empties(): void
    {
        $this->assertSame(
            'A,B',
            CatalogoRoleListNormalizer::normalize(' A ,, B , ', ['A', 'B'])
        );
    }

    public function test_drops_unknown_roles_save_time_whitelist(): void
    {
        $this->assertSame(
            'A',
            CatalogoRoleListNormalizer::normalize('A,GHOST', ['A', 'B']),
            'a codrol missing from fs_roles must be dropped at save time'
        );
    }

    public function test_dedupes_preserving_first_seen_order(): void
    {
        $this->assertSame(
            'B,A',
            CatalogoRoleListNormalizer::normalize('B,A,B', ['A', 'B'])
        );
    }

    public function test_unparsable_input_fails_closed_to_empty(): void
    {
        $this->assertSame('', CatalogoRoleListNormalizer::normalize('', ['A']));
        $this->assertSame('', CatalogoRoleListNormalizer::normalize(',,', ['A']));
        $this->assertSame('', CatalogoRoleListNormalizer::normalize('GHOST', ['A']));
        $this->assertSame('', CatalogoRoleListNormalizer::normalize('A', []), 'no existing roles ⇒ no grants');
    }
}
