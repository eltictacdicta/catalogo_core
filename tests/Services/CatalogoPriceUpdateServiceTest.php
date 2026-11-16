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

use FSFramework\Plugins\catalogo_core\Services\CatalogoPriceUpdateService;
use FSFramework\Plugins\catalogo_core\Services\PriceBookInterface;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateScopeInterface;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateTargetInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Batch price update engine, scope × target (SDD
 * unificar-superficies-precio-catalogo-core, WU-1.4 — R-BU-001, R-BU-002,
 * R-BU-003, R-BU-007).
 *
 * Migrated from the list-only engine test: the formula, the
 * "no price on the book → no row" rule and the per-row persistence contract
 * keep their expectations. Row selection is covered by the scope tests; book
 * reading and writing by the book tests. This test pins the composition:
 * canonical-first, no branching on target codes, preview never writes and
 * apply persists exactly the previewed rows.
 */
#[CoversClass(CatalogoPriceUpdateService::class)]
final class CatalogoPriceUpdateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateTargetRegistry.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateScopes.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoPriceUpdateService.php';
    }

    // ---- R-BU-003: rounding formula (unchanged) ----

    /** 10.005 +10% → exactly 11.01 (round half-up to 2 decimals). */
    public function testComputePriceIsUnchanged(): void
    {
        $service = new CatalogoPriceUpdateService();

        $this->assertSame(11.01, $service->computePrice(10.005, 10.0));
        $this->assertSame(7.5, $service->computePrice(10.0, -25.0));
        $this->assertSame(10.0, $service->computePrice(10.0, 0.0));
    }

    public function testPreviewAppliesTheFormulaToTheBookPrice(): void
    {
        $scope = $this->fakeScope([['A', 'Uno']]);
        $target = $this->fakeTarget(book: $this->fakeBook(['A' => 10.005]));

        $rows = (new CatalogoPriceUpdateService())->preview($scope, $target, ['clave' => 'K'], 10.0);

        $this->assertSame(
            [['key' => 'A', 'label' => 'Uno', 'precio_actual' => 10.005, 'precio_nuevo' => 11.01]],
            $rows
        );
    }

    public function testNegativePercentageDecreases(): void
    {
        $scope = $this->fakeScope([['A', 'Uno']]);
        $target = $this->fakeTarget(book: $this->fakeBook(['A' => 10.0]));

        $rows = (new CatalogoPriceUpdateService())->preview($scope, $target, ['clave' => 'K'], -25.0);

        $this->assertSame(7.5, $rows[0]['precio_nuevo']);
    }

    // ---- R-BU-001: preview ----

    public function testPreviewKeepsTheScopeOrderAndOmitsRowsWithoutPrice(): void
    {
        $scope = $this->fakeScope([['B', 'b'], ['A', 'a'], ['C', 'c']]);
        $target = $this->fakeTarget(book: $this->fakeBook(['A' => 1.0, 'C' => 3.0]));

        $rows = (new CatalogoPriceUpdateService())->preview($scope, $target, ['clave' => 'K'], 0.0);

        $this->assertSame(['A', 'C'], array_column($rows, 'key'), 'B has no price in the book and must not appear');
    }

    public function testPreviewWithZeroMatchesReturnsEmpty(): void
    {
        $scope = $this->fakeScope([]);
        $target = $this->fakeTarget(book: $this->fakeBook(['A' => 1.0]));

        $this->assertSame([], (new CatalogoPriceUpdateService())->preview($scope, $target, ['clave' => 'K'], 5.0));
    }

    public function testPreviewNeverPersists(): void
    {
        $book = $this->fakeBook(['A' => 10.0]);
        $scope = $this->fakeScope([['A', 'a']]);
        $target = $this->fakeTarget(book: $book);

        (new CatalogoPriceUpdateService())->preview($scope, $target, ['clave' => 'K'], 10.0);

        $this->assertSame([], $book->persisted);
    }

    // ---- D1: canonical-first composition ----

    public function testCompositionPrefersCanonicalBook(): void
    {
        $canonical = $this->fakeBook(['A' => 100.0]);
        $scope = $this->fakeScope([['A', 'a']], canonical: $canonical);
        $target = $this->fakeTarget(book: null, failIfBookRequested: true);

        $rows = (new CatalogoPriceUpdateService())->preview($scope, $target, ['clave' => 'K'], 10.0);

        $this->assertSame(110.0, $rows[0]['precio_nuevo']);
        $this->assertSame(0, $target->bookRequests, 'The target book must not be consulted when the scope has a canonical book');
    }

    public function testTheTargetBookIsUsedWhenTheScopeHasNoCanonicalBook(): void
    {
        $scope = $this->fakeScope([['A', 'a']]);
        $target = $this->fakeTarget(book: $this->fakeBook(['A' => 20.0]));

        $rows = (new CatalogoPriceUpdateService())->preview($scope, $target, ['clave' => 'K'], 0.0);

        $this->assertSame(20.0, $rows[0]['precio_actual']);
        $this->assertSame([['articulos', 'K']], $target->bookRequestLog);
    }

    // ---- fail closed ----

    public function testMissingTargetKeyMatchesNothing(): void
    {
        $scope = $this->fakeScope([['A', 'a']]);
        $target = $this->fakeTarget(book: $this->fakeBook(['A' => 1.0]));

        $this->assertSame([], (new CatalogoPriceUpdateService())->preview($scope, $target, [], 5.0));
    }

    public function testTargetWithoutABookForTheScopeMatchesNothing(): void
    {
        $scope = $this->fakeScope([['A', 'a']]);
        $target = $this->fakeTarget(book: null);

        $this->assertSame([], (new CatalogoPriceUpdateService())->preview($scope, $target, ['clave' => 'K'], 5.0));
    }

    public function testApplyWithoutKeyOrBookWritesNothing(): void
    {
        $book = $this->fakeBook(['A' => 1.0]);
        $service = new CatalogoPriceUpdateService();
        $rows = [['key' => 'A', 'precio_nuevo' => 2.0]];

        $noKey = $service->apply($this->fakeScope([]), $this->fakeTarget(book: $book), [], $rows);
        $noBook = $service->apply($this->fakeScope([]), $this->fakeTarget(book: null), ['clave' => 'K'], $rows);

        $this->assertSame(['updated' => 0, 'failed' => []], $noKey);
        $this->assertSame(['updated' => 0, 'failed' => ['A']], $noBook);
        $this->assertSame([], $book->persisted);
    }

    // ---- apply ----

    public function testApplyPersistsExactlyPreviewRows(): void
    {
        $book = $this->fakeBook(['A' => 10.0, 'B' => 20.0]);
        $scope = $this->fakeScope([['A', 'a'], ['B', 'b']]);
        $target = $this->fakeTarget(book: $book);
        $service = new CatalogoPriceUpdateService();
        $filters = ['clave' => 'K'];

        $rows = $service->preview($scope, $target, $filters, 10.0);
        $result = $service->apply($scope, $target, $filters, $rows);

        $this->assertSame(['updated' => 2, 'failed' => []], $result);
        $this->assertSame([['A', 11.0], ['B', 22.0]], $book->persisted);
    }

    public function testFailedKeysAreReported(): void
    {
        $book = $this->fakeBook(['A' => 10.0, 'B' => 20.0], failKeys: ['B']);
        $scope = $this->fakeScope([['A', 'a'], ['B', 'b']]);
        $target = $this->fakeTarget(book: $book);
        $service = new CatalogoPriceUpdateService();
        $filters = ['clave' => 'K'];

        $rows = $service->preview($scope, $target, $filters, 0.0);
        $result = $service->apply($scope, $target, $filters, $rows);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(['B'], $result['failed']);
    }

    // ---- doubles ----

    /**
     * @param list<array{string, string}> $rows
     */
    private function fakeScope(array $rows, ?PriceBookInterface $canonical = null): PriceUpdateScopeInterface
    {
        return new class($rows, $canonical) implements PriceUpdateScopeInterface {
            public function __construct(
                private readonly array $fRows,
                private readonly ?PriceBookInterface $fCanonical,
            ) {
            }

            public function code(): string
            {
                return 'articulos';
            }

            public function label(): string
            {
                return 'Artículos';
            }

            public function keyLabel(): string
            {
                return 'Referencia';
            }

            public function match(array $filters): array
            {
                return array_map(static fn (array $r): array => ['key' => $r[0], 'label' => $r[1]], $this->fRows);
            }

            public function canonicalBook(string $key): ?PriceBookInterface
            {
                return $this->fCanonical;
            }
        };
    }

    private function fakeTarget(?PriceBookInterface $book, bool $failIfBookRequested = false): object
    {
        return new class($book, $failIfBookRequested) implements PriceUpdateTargetInterface {
            public int $bookRequests = 0;

            /** @var list<array{string, string}> */
            public array $bookRequestLog = [];

            public function __construct(
                private readonly ?PriceBookInterface $fBook,
                private readonly bool $fFail,
            ) {
            }

            public function code(): string
            {
                return 'fake';
            }

            public function label(): string
            {
                return 'Fake';
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function keyField(): string
            {
                return 'clave';
            }

            public function keyLabel(): string
            {
                return 'clave';
            }

            public function keyOptions(): array
            {
                return [];
            }

            public function keyFromFilters(array $filters): ?string
            {
                $value = trim((string) ($filters['clave'] ?? ''));

                return $value === '' ? null : $value;
            }

            public function bookFor(string $scope, string $key): ?PriceBookInterface
            {
                $this->bookRequests++;
                $this->bookRequestLog[] = [$scope, $key];
                if ($this->fFail) {
                    throw new \LogicException('The target book must not be consulted');
                }

                return $this->fBook;
            }
        };
    }

    /**
     * @param array<string, float> $prices
     * @param list<string> $failKeys
     */
    private function fakeBook(array $prices, array $failKeys = []): object
    {
        return new class($prices, $failKeys) implements PriceBookInterface {
            /** @var list<array{string, float}> */
            public array $persisted = [];

            public function __construct(
                private readonly array $fPrices,
                private readonly array $fFail,
            ) {
            }

            public function currentPrices(array $keys): array
            {
                $out = [];
                foreach ($keys as $key) {
                    if (array_key_exists($key, $this->fPrices)) {
                        $out[$key] = $this->fPrices[$key];
                    }
                }

                return $out;
            }

            public function persist(string $key, float $price): bool
            {
                $this->persisted[] = [$key, $price];

                return !in_array($key, $this->fFail, true);
            }
        };
    }
}
