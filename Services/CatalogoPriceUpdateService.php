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

require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateTargetRegistry.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateScopes.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateFilterRegistry.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Event/PriceUpdatePersistedEvent.php';

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Plugins\catalogo_core\Event\PriceUpdatePersistedEvent;

/**
 * Batch price update engine, scope x target (R-BU-001, R-BU-002, R-BU-003,
 * R-BU-007):
 *
 *  - the SCOPE selects the rows (and their canonical order);
 *  - the TARGET names the key of the operation and, when the scope has no
 *    canonical storage, provides the price book;
 *  - the price BOOK reads the current price and persists the new one.
 *
 * The book is composed canonical-first: `scope->canonicalBook() ??
 * target->bookFor()`. The engine receives resolved collaborators and never
 * branches on a scope or target code.
 *
 *  - additional filters registered through PriceUpdateFilterRegistry may only
 *    narrow the scope's rows (never widen them);
 *  - preview(): current and prospective price per selected row, persisting
 *    NOTHING. A row without a price in the book is omitted.
 *  - apply(): persists exactly the previewed rows through the book, one by
 *    one, so failures are reported per row (R-BU-005). It never rewrites
 *    articulos.pvp.
 *
 * Formula: round(p * (1 + pct/100), 2) half-up, where p is the current price
 * the book reports.
 */
final class CatalogoPriceUpdateService
{
    /**
     * Compute the preview rows. No write. Fails closed (empty result) when the
     * target key is missing or no book exists for the scope.
     *
     * @param array<string, mixed> $filters
     * @return list<array{key: string, label: string, precio_actual: float, precio_nuevo: float}>
     */
    public function preview(
        PriceUpdateScopeInterface $scope,
        PriceUpdateTargetInterface $target,
        array $filters,
        float $pct
    ): array {
        $key = $target->keyFromFilters($filters);
        if ($key === null) {
            return [];
        }

        $book = $this->bookFor($scope, $target, $key);
        if ($book === null) {
            return [];
        }

        $matched = PriceUpdateFilterRegistry::narrow($scope->code(), $filters, $scope->match($filters));
        if ($matched === []) {
            return [];
        }

        $prices = $book->currentPrices(array_column($matched, 'key'));

        $rows = [];
        foreach ($matched as $row) {
            if (!array_key_exists($row['key'], $prices)) {
                // No price in this book: nothing to update.
                continue;
            }

            $current = $prices[$row['key']];
            $rows[] = [
                'key' => $row['key'],
                'label' => $row['label'],
                'precio_actual' => $current,
                'precio_nuevo' => $this->computePrice($current, $pct),
            ];
        }

        return $rows;
    }

    /**
     * Persist exactly the given rows through the book of the target key.
     * The key and the book are recomputed from the filters; nothing but the
     * row key and the new price is taken from the rows.
     *
     * @param array<string, mixed> $filters
     * @param list<array{key: string, precio_nuevo: float}> $rows
     * @return array{updated: int, failed: list<string>}
     */
    public function apply(
        PriceUpdateScopeInterface $scope,
        PriceUpdateTargetInterface $target,
        array $filters,
        array $rows
    ): array {
        $key = $target->keyFromFilters($filters);
        if ($key === null) {
            return ['updated' => 0, 'failed' => []];
        }

        $book = $this->bookFor($scope, $target, $key);
        $updated = 0;
        $failed = [];

        // Previous prices are read from the book (never from the rows the client
        // reposted), once for the whole batch, and only when someone listens.
        $listening = $book !== null && FSEventDispatcher::getInstance()->hasListeners(PriceUpdatePersistedEvent::NAME);
        $before = $listening ? $book->currentPrices(array_map(static fn (array $r): string => (string) $r['key'], $rows)) : [];
        $persisted = [];

        foreach ($rows as $row) {
            $rowKey = (string) $row['key'];
            $newPrice = (float) $row['precio_nuevo'];
            if ($book !== null && $book->persist($rowKey, $newPrice)) {
                $updated++;
                $persisted[] = [
                    'key' => $rowKey,
                    'precio_anterior' => (float) ($before[$rowKey] ?? 0.0),
                    'precio_nuevo' => $newPrice,
                ];
            } else {
                $failed[] = $rowKey;
            }
        }

        if ($listening && $persisted !== []) {
            $this->notifyPersisted(new PriceUpdatePersistedEvent($scope->code(), $target->code(), $key, $persisted));
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /** Informative only: a failing listener never changes the outcome of the apply. */
    private function notifyPersisted(PriceUpdatePersistedEvent $event): void
    {
        try {
            FSEventDispatcher::getInstance()->dispatch($event, PriceUpdatePersistedEvent::NAME);
        } catch (\Throwable $e) {
            error_log('[catalogo_core] price_update_persisted listener failed: ' . $e->getMessage());
        }
    }

    /** round(p * (1 + pct/100), 2) — half-up (R-BU-003). */
    public function computePrice(float $p, float $pct): float
    {
        return round($p * (1 + $pct / 100), 2, PHP_ROUND_HALF_UP);
    }

    /** Canonical-first composition (D1). */
    private function bookFor(
        PriceUpdateScopeInterface $scope,
        PriceUpdateTargetInterface $target,
        string $key
    ): ?PriceBookInterface {
        return $scope->canonicalBook($key) ?? $target->bookFor($scope->code(), $key);
    }
}
