<?php
/**
 * Server-authoritative reorder plan for familia chapters.
 *
 * Pure, DB-free function that accepts a flat code list and the current
 * madre structure map, validates the permutation, and computes a complete
 * chapter map via BFS.
 *
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Server-authoritative reorder service for familia chapters.
 *
 * Accepts a flat code list (top-to-bottom visual order) and the current
 * madre-by-code structure map.  Validates the payload is an exact
 * permutation of the current code set, then computes a complete chapter
 * map via BFS: roots numbered sequentially, children prefixed with
 * their mother's chapter.
 *
 * Design: plugins/catalogo_core/Services/TarifaFamiliaReorder.php (D1)
 */
final class TarifaFamiliaReorder
{
    /**
     * Compute a chapter map for the given visual order.
     *
     * @param list<string>                $flatCodes   top-to-bottom visual order (drag payload)
     * @param array<string, string|null>  $madreByCode current structure map (cod => madre|null)
     * @return array{ok: bool, error: ?string, chapters: array<string, string>}
     *         chapters maps EVERY familia (incl. untouched descendants) to its new capitulo
     */
    public static function plan(array $flatCodes, array $madreByCode): array
    {
        // 1. Permutation validation — non-empty, set-equal, no duplicates
        $codes = array_keys($madreByCode);
        $flatSet = array_values(array_unique($flatCodes));

        if (empty($flatCodes)) {
            return ['ok' => false, 'error' => 'Flat codes list is empty', 'chapters' => []];
        }

        if (count($flatSet) !== count($flatCodes)) {
            return ['ok' => false, 'error' => 'Duplicate codes in flat list', 'chapters' => []];
        }

        sort($codes);
        sort($flatSet);

        if ($codes !== $flatSet) {
            $missing = array_diff($codes, $flatSet);
            $extra = array_diff($flatSet, $codes);
            if (!empty($missing)) {
                return ['ok' => false, 'error' => 'Missing codes: ' . implode(', ', $missing), 'chapters' => []];
            }
            return ['ok' => false, 'error' => 'Unknown codes: ' . implode(', ', $extra), 'chapters' => []];
        }

        // 2. Structure sanity — every madre resolves inside the set, cycle detection
        foreach ($madreByCode as $cod => $madre) {
            if ($madre !== null && !array_key_exists($madre, $madreByCode)) {
                return ['ok' => false, 'error' => "Unknown madre reference '$madre' for code '$cod'", 'chapters' => []];
            }
        }

        // Cycle detection via DFS
        $visited = [];
        $inStack = [];
        $detectCycle = function (string $cod) use (&$detectCycle, &$visited, &$inStack, $madreByCode): bool {
            if (isset($inStack[$cod])) {
                return true; // cycle
            }
            if (isset($visited[$cod])) {
                return false;
            }
            $visited[$cod] = true;
            $inStack[$cod] = true;
            $madre = $madreByCode[$cod] ?? null;
            if ($madre !== null && $detectCycle($madre)) {
                return true;
            }
            unset($inStack[$cod]);
            return false;
        };

        foreach ($madreByCode as $cod => $_) {
            if (isset($visited[$cod])) {
                continue;
            }
            if ($detectCycle($cod)) {
                return ['ok' => false, 'error' => "Cycle detected involving code '$cod'", 'chapters' => []];
            }
        }

        // 3. BFS chapter computation — roots sequential, child = mother.chapter.i
        //
        // Group ALL codes by madre, ordered by first appearance in flatCodes.
        $childrenByMadre = [];
        foreach ($flatCodes as $cod) {
            $madre = $madreByCode[$cod] ?? null;
            $key = $madre ?? '__ROOT__';
            $childrenByMadre[$key] = $childrenByMadre[$key] ?? [];
            if (!in_array($cod, $childrenByMadre[$key], true)) {
                $childrenByMadre[$key][] = $cod;
            }
        }
        // Also include codes not in flatCodes (untouched descendants)
        foreach ($madreByCode as $cod => $madre) {
            $key = $madre ?? '__ROOT__';
            $childrenByMadre[$key] = $childrenByMadre[$key] ?? [];
            if (!in_array($cod, $childrenByMadre[$key], true)) {
                $childrenByMadre[$key][] = $cod;
            }
        }

        $chapters = [];
        $rootNum = 1;

        // BFS queue: [[code, parentChapter, positionAmongSiblings]]
        $queue = [];
        $enqueued = [];

        // Seed roots in flatCodes order
        $rootChildren = $childrenByMadre['__ROOT__'] ?? [];
        $pos = 0;
        foreach ($rootChildren as $rootCode) {
            if (isset($enqueued[$rootCode])) {
                continue;
            }
            $enqueued[$rootCode] = true;
            $pos++;
            $queue[] = [$rootCode, null, $pos];
        }

        while (!empty($queue)) {
            [$code, $parentChapter, $siblingPos] = array_shift($queue);

            if ($parentChapter === null) {
                $chapters[$code] = (string) $siblingPos;
            } else {
                $chapters[$code] = $parentChapter . '.' . $siblingPos;
            }

            // Enqueue children in flatCodes order
            $myChapter = $chapters[$code];
            $myChildren = $childrenByMadre[$code] ?? [];
            $childPos = 0;
            foreach ($myChildren as $childCode) {
                if (isset($enqueued[$childCode])) {
                    continue;
                }
                $enqueued[$childCode] = true;
                $childPos++;
                $queue[] = [$childCode, $myChapter, $childPos];
            }
        }

        return ['ok' => true, 'error' => null, 'chapters' => $chapters];
    }
}
