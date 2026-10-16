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

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Shared save-pipeline normalizer for comma-separated role lists
 * (multitarifa task 2.3, R-CO-004; extracted from the legacy
 * catalogo_excel_settings controller's catalogo_excel_roles_normalize()).
 *
 * Split on ',', trim, drop empties, validate each codrol against the
 * existing fs_roles (save-time whitelist), dedupe preserving first-seen
 * order, implode ','.
 *
 * Unknown codrols are dropped at save time; the read path (policy) also
 * intersects at read time (deleted-role edge). Fail-closed: unparsable
 * input or zero valid roles yield '' (no grants).
 */
final class CatalogoRoleListNormalizer
{
    /**
     * @param string $raw raw POST value (comma-separated codrols)
     * @param list<string> $existingCodrols existing fs_roles.codrol values
     */
    public static function normalize(string $raw, array $existingCodrols): string
    {
        $valid = array_flip($existingCodrols);
        $kept = [];
        foreach (explode(',', $raw) as $piece) {
            $codrol = trim($piece);
            if ($codrol === '' || !isset($valid[$codrol])) {
                continue;
            }
            if (!in_array($codrol, $kept, true)) {
                $kept[] = $codrol;
            }
        }

        return implode(',', $kept);
    }
}
