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
 * A price book: the single place that reads the current price of a row AND
 * persists the new one (R-BU-007). Reading and writing share one contract so a
 * preview can never read one storage while the apply writes another.
 */
interface PriceBookInterface
{
    /**
     * Current prices keyed by row key. A key that is absent from the result
     * has NO price in this book: the engine omits that row (it is never
     * defaulted to 0.0).
     *
     * @param list<string> $keys
     * @return array<string, float>
     */
    public function currentPrices(array $keys): array;

    /** Persists one row; the per-row result feeds the "failed" summary (R-BU-005). */
    public function persist(string $key, float $price): bool;
}

/**
 * A destination for a price update: it names the key the operation is
 * scoped to (a price list, a tariff, ...) and provides the price book per scope.
 * The engine never branches on the target code.
 */
interface PriceUpdateTargetInterface
{
    public function code(): string;

    public function label(): string;

    /** Request-time availability, declared by the target itself. */
    public function isAvailable(): bool;

    /** Request field that carries the target key (e.g. a list or tariff code). */
    public function keyField(): string;

    public function keyLabel(): string;

    /** @return list<array{value: string, label: string}> */
    public function keyOptions(): array;

    /** @param array<string, mixed> $filters */
    public function keyFromFilters(array $filters): ?string;

    /** Price book for the scope, or null when the target has none for it. */
    public function bookFor(string $scope, string $key): ?PriceBookInterface;
}

/**
 * Neutral registry of price update targets. catalogo_core owns the seam;
 * consumers register their targets from their own Init::init(), so no
 * catalogo_core file names a consumer plugin (R-PSO-002).
 *
 * resolve() is a lookup, not an execution: it returns null for an unknown code
 * and the caller fails closed. Availability is checked through available().
 */
final class PriceUpdateTargetRegistry
{
    private const BASE_CODE = 'lista';

    /** @var array<string, PriceUpdateTargetInterface> code => target, in registration order */
    private static array $targets = [];

    /** First registration wins: a later target with the same code is ignored. */
    public static function register(PriceUpdateTargetInterface $target): void
    {
        $code = $target->code();
        if (!isset(self::$targets[$code])) {
            self::$targets[$code] = $target;
        }
    }

    /** Test seam: drops every registered target. */
    public static function reset(): void
    {
        self::$targets = [];
    }

    /** Registers the base target. Idempotent (duplicates keep the first). */
    public static function registerDefaults(): void
    {
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/ListaPriceUpdateTarget.php';
        self::register(new ListaPriceUpdateTarget());
    }

    public static function resolve(string $code): ?PriceUpdateTargetInterface
    {
        return self::$targets[$code] ?? null;
    }

    /**
     * Registered targets that are available right now.
     *
     * @return array<string, string> code => label, in registration order
     */
    public static function available(): array
    {
        $available = [];
        foreach (self::$targets as $code => $target) {
            if ($target->isAvailable()) {
                $available[$code] = $target->label();
            }
        }

        return $available;
    }

    /** The base target when available; otherwise the first available; '' when none. */
    public static function defaultCode(): string
    {
        $available = self::available();
        if (isset($available[self::BASE_CODE])) {
            return self::BASE_CODE;
        }

        $codes = array_keys($available);

        return isset($codes[0]) ? (string) $codes[0] : '';
    }
}
