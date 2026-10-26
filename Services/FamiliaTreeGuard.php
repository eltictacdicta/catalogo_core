<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Cycle guard for the family tree re-parenting path (familias-jerarquia,
 * R-FJ-001 / D5).
 *
 * The base `familias` model is read-only for this capability, so the check
 * lives here and is invoked by the controller save path before any write: a
 * re-parent that would make a family a descendant of itself must abort.
 *
 * The walk reads only `familias.madre` — the authoritative hierarchy. The
 * per-list `catalogo_familia_estructura.madre` is display metadata and is
 * intentionally ignored.
 */
final class FamiliaTreeGuard
{
    /** Defensive ceiling for a corrupt chain that never reaches a root. */
    private const MAX_DEPTH = 1000;

    /**
     * @param string|null $newMadre prospective mother; null/'' clears the mother
     *
     * @throws \RuntimeException when the new mother is the family itself or one
     *                           of its descendants, or when the existing chain
     *                           is already cyclic
     */
    public static function assertAcyclic(\fs_db2 $db, string $codfamilia, ?string $newMadre): void
    {
        $codfamilia = trim($codfamilia);

        if ($codfamilia === '') {
            throw new \RuntimeException('Cannot re-parent a family without a code.');
        }

        $madre = $newMadre === null ? null : trim($newMadre);
        if ($madre === null || $madre === '') {
            return;
        }

        if ($madre === $codfamilia) {
            throw new \RuntimeException(
                'Family cycle detected: "' . $codfamilia . '" cannot be its own mother.'
            );
        }

        $visited = [$codfamilia => true];
        $current = $madre;
        $depth = 0;

        while ($current !== null && $current !== '' && $depth < self::MAX_DEPTH) {
            if (isset($visited[$current])) {
                throw new \RuntimeException(
                    'Family cycle detected: "' . $codfamilia . '" cannot be nested under "' . $madre . '".'
                );
            }

            $visited[$current] = true;
            $current = self::motherOf($db, $current);
            $depth++;
        }

        if ($depth >= self::MAX_DEPTH) {
            throw new \RuntimeException(
                'Family cycle detected: the mother chain of "' . $madre . '" does not terminate.'
            );
        }
    }

    private static function motherOf(\fs_db2 $db, string $codfamilia): ?string
    {
        $data = $db->select(
            'SELECT madre FROM familias WHERE codfamilia = ' . $db->var2str($codfamilia) . ' LIMIT 1;'
        );

        if (!is_array($data) || $data === [] || !isset($data[0]['madre'])) {
            return null;
        }

        $madre = (string) $data[0]['madre'];

        return $madre === '' ? null : $madre;
    }
}
