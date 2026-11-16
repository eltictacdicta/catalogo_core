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
 * An additional filter contributed to the batch price update (D5). It narrows
 * the rows the scope selected; it never selects rows by itself and knows
 * nothing about the storage of the prices.
 */
interface PriceUpdateFilterInterface
{
    /** Whether the filter applies to the scope (e.g. only to articles). */
    public function supports(string $scope): bool;

    /**
     * Request fields owned by the filter. The page reposts exactly these in the
     * apply form, so apply recomputes the rows the preview showed.
     *
     * @return list<string>
     */
    public function keys(string $scope): array;

    /**
     * Opaque data for the filter's hook template (options of its control).
     *
     * @return array<string, mixed>
     */
    public function viewContext(string $scope): array;

    /**
     * Narrows the rows. The result is intersected with the input by the
     * registry, so a filter can only remove rows, never add or reorder them.
     *
     * @param array<string, mixed> $filters
     * @param list<array{key: string, label: string}> $rows
     * @return list<array{key: string, label: string}>
     */
    public function narrow(string $scope, array $filters, array $rows): array;
}

/**
 * Neutral registry of price update filters. catalogo_core owns the seam; a
 * consumer registers its filter from its own Init::init(), so no catalogo_core
 * file names a consumer plugin (R-PSO-002).
 *
 * Safety properties, enforced here and not left to each filter:
 * - narrow() can only keep a subset of the scope's rows, in the scope's order;
 * - a filter that throws makes the whole selection empty (fail closed): a
 *   broken filter must never broaden a price update;
 * - keys() drops the engine's own request fields and anything that is not a
 *   plain snake_case name, so a filter cannot override or forge them.
 */
final class PriceUpdateFilterRegistry
{
    /** Request fields owned by the page and the engine. */
    private const RESERVED_KEYS = [
        'page', 'action', 'confirm', 'target', 'scope', 'pct', '_token',
        'codfamilia', 'incluir_subfamilias', 'codgrupo', 'codlista',
    ];

    /** @var list<PriceUpdateFilterInterface> */
    private static array $filters = [];

    public static function register(PriceUpdateFilterInterface $filter): void
    {
        foreach (self::$filters as $registered) {
            if ($registered === $filter) {
                return;
            }
        }

        self::$filters[] = $filter;
    }

    public static function reset(): void
    {
        self::$filters = [];
    }

    /**
     * Request keys declared by the filters that support the scope, in
     * registration order, without duplicates.
     *
     * @return list<string>
     */
    public static function keys(string $scope): array
    {
        $keys = [];
        foreach (self::supporting($scope) as $filter) {
            foreach ($filter->keys($scope) as $key) {
                $key = (string) $key;
                if (
                    preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1
                    && !in_array($key, self::RESERVED_KEYS, true)
                    && !in_array($key, $keys, true)
                ) {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /**
     * Merged view context of the supporting filters (later filters win on a
     * repeated key).
     *
     * @return array<string, mixed>
     */
    public static function viewContext(string $scope): array
    {
        $context = [];
        foreach (self::supporting($scope) as $filter) {
            $context = array_merge($context, $filter->viewContext($scope));
        }

        return $context;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<array{key: string, label: string}> $rows
     * @return list<array{key: string, label: string}>
     */
    public static function narrow(string $scope, array $filters, array $rows): array
    {
        foreach (self::supporting($scope) as $filter) {
            try {
                $narrowed = $filter->narrow($scope, $filters, $rows);
            } catch (\Throwable $e) {
                error_log('PriceUpdateFilterRegistry: filter ' . $filter::class . ' failed, selection emptied: ' . $e->getMessage());

                return [];
            }

            $kept = [];
            foreach ($narrowed as $row) {
                if (is_array($row) && isset($row['key'])) {
                    $kept[(string) $row['key']] = true;
                }
            }

            // Only the scope's own rows, in the scope's own order.
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => isset($kept[(string) $row['key']])
            ));
        }

        return $rows;
    }

    /** @return list<PriceUpdateFilterInterface> */
    private static function supporting(string $scope): array
    {
        return array_values(array_filter(
            self::$filters,
            static fn (PriceUpdateFilterInterface $filter): bool => $filter->supports($scope)
        ));
    }
}
