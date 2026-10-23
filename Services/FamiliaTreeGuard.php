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

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Guards the mother/daughter hierarchy against cycles before a re-parent.
 *
 * The base `familias` table and `model/core/familia.php` stay untouched
 * (R-FJ-001); the check lives here so the controller save path can invoke a
 * testable service instead of embedding graph logic.
 */
final class FamiliaTreeGuard
{
    /**
     * @throws \RuntimeException when the new mother is the family itself or
     *                           one of its descendants, or the existing chain
     *                           is already cyclic.
     */
    public static function assertAcyclic(\fs_db2 $db, string $codfamilia, string $newMadre): void
    {
        if ($newMadre === '') {
            return;
        }

        if ($newMadre === $codfamilia) {
            throw new \RuntimeException(self::cycleMessage($codfamilia, $newMadre));
        }

        $visited = [];
        $current = $newMadre;

        while ($current !== '') {
            if ($current === $codfamilia) {
                throw new \RuntimeException(self::cycleMessage($codfamilia, $newMadre));
            }

            if (isset($visited[$current])) {
                throw new \RuntimeException(
                    'Family hierarchy cycle detected: the mother chain of "' . $newMadre . '" is already cyclic.'
                );
            }
            $visited[$current] = true;

            $rows = $db->select(
                'SELECT madre FROM familias WHERE codfamilia = ' . $db->var2str($current) . ' LIMIT 1;'
            );

            if (!$rows) {
                // A mother that does not exist yet cannot close a cycle.
                return;
            }

            $mother = $rows[0]['madre'] ?? null;
            $current = is_string($mother) ? $mother : '';
        }
    }

    private static function cycleMessage(string $codfamilia, string $newMadre): string
    {
        return 'Family hierarchy cycle detected: "' . $codfamilia
            . '" cannot become a child of "' . $newMadre . '".';
    }
}
