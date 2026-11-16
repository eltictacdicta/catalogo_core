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

use FSFramework\Plugins\catalogo_core\Services\PriceUpdateFilterInterface;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateFilterRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Neutral registry of additional price update filters (SDD
 * unificar-superficies-precio-catalogo-core, WU-3.1 — R-BU-002, R-CRH-001, D5).
 *
 * The view hook can only render HTML; a filter that narrows rows needs a PHP
 * seam. catalogo_core owns the seam, a consumer registers its filter from its
 * own Init. The registry may only NARROW the scope's rows (never widen them)
 * and fails closed when a filter misbehaves.
 */
#[CoversClass(PriceUpdateFilterRegistry::class)]
final class PriceUpdateFilterRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateFilterRegistry.php';
        PriceUpdateFilterRegistry::reset();
    }

    protected function tearDown(): void
    {
        PriceUpdateFilterRegistry::reset();
        parent::tearDown();
    }

    public function testRegistryKeysViewContextAndNarrow(): void
    {
        PriceUpdateFilterRegistry::register($this->filter(
            keys: ['grupo_x'],
            context: ['grupos' => ['G1', 'G2']],
            keep: static fn (array $filters, array $row): bool => $row['key'] !== 'B',
        ));

        $this->assertSame(['grupo_x'], PriceUpdateFilterRegistry::keys('articulos'));
        $this->assertSame(['grupos' => ['G1', 'G2']], PriceUpdateFilterRegistry::viewContext('articulos'));
        $this->assertSame(
            [['key' => 'A', 'label' => 'a'], ['key' => 'C', 'label' => 'c']],
            PriceUpdateFilterRegistry::narrow('articulos', ['grupo_x' => '1'], $this->rows('A', 'B', 'C')),
        );
    }

    public function testRegistryResetAndRegistrationOrder(): void
    {
        $this->assertSame([], PriceUpdateFilterRegistry::keys('articulos'), 'An empty registry contributes nothing');
        $this->assertSame([], PriceUpdateFilterRegistry::viewContext('articulos'));
        $this->assertSame($this->rows('A', 'B'), PriceUpdateFilterRegistry::narrow('articulos', [], $this->rows('A', 'B')), 'An empty registry keeps every row');

        PriceUpdateFilterRegistry::register($this->filter(keys: ['uno']));
        PriceUpdateFilterRegistry::register($this->filter(keys: ['dos', 'uno']));
        $this->assertSame(['uno', 'dos'], PriceUpdateFilterRegistry::keys('articulos'), 'Registration order, without duplicates');

        PriceUpdateFilterRegistry::reset();
        $this->assertSame([], PriceUpdateFilterRegistry::keys('articulos'));
    }

    public function testRegisteringTheSameInstanceTwiceIsIdempotent(): void
    {
        $calls = new \stdClass();
        $calls->narrow = 0;
        $filter = $this->filter(keys: ['k'], calls: $calls);

        PriceUpdateFilterRegistry::register($filter);
        PriceUpdateFilterRegistry::register($filter);
        PriceUpdateFilterRegistry::narrow('articulos', [], $this->rows('A'));

        $this->assertSame(1, $calls->narrow, 'The same instance must not run twice');
    }

    public function testFiltersOnlyActOnTheScopesTheySupport(): void
    {
        PriceUpdateFilterRegistry::register($this->filter(
            keys: ['solo_articulos'],
            context: ['x' => 1],
            supports: ['articulos'],
            keep: static fn (): bool => false,
        ));

        $this->assertSame([], PriceUpdateFilterRegistry::keys('opcionales'));
        $this->assertSame([], PriceUpdateFilterRegistry::viewContext('opcionales'));
        $this->assertSame($this->rows('A'), PriceUpdateFilterRegistry::narrow('opcionales', [], $this->rows('A')), 'An unsupported scope is not narrowed');
        $this->assertSame([], PriceUpdateFilterRegistry::narrow('articulos', [], $this->rows('A')));
    }

    public function testKeysDropReservedAndInvalidNames(): void
    {
        PriceUpdateFilterRegistry::register($this->filter(keys: [
            'target', 'scope', 'pct', 'action', 'confirm', 'page', '_token',
            'codfamilia', 'incluir_subfamilias', 'codgrupo', 'codlista',
            'Bad-Name', '1abc', '', 'ok_key',
        ]));

        $this->assertSame(['ok_key'], PriceUpdateFilterRegistry::keys('articulos'), 'A consumer can never repost the engine\'s own request fields');
    }

    public function testNarrowCannotWidenTheRows(): void
    {
        PriceUpdateFilterRegistry::register($this->widening());

        $rows = PriceUpdateFilterRegistry::narrow('articulos', [], $this->rows('A', 'B'));

        $this->assertSame($this->rows('A', 'B'), $rows, 'Rows invented by a filter are discarded; the scope selection is the ceiling');
    }

    public function testNarrowKeepsTheScopeOrder(): void
    {
        PriceUpdateFilterRegistry::register($this->reversing());

        $rows = PriceUpdateFilterRegistry::narrow('articulos', [], $this->rows('A', 'B', 'C'));

        $this->assertSame(['A', 'B', 'C'], array_column($rows, 'key'), 'The canonical order belongs to the scope');
    }

    public function testNarrowFailsClosedWhenAFilterThrows(): void
    {
        PriceUpdateFilterRegistry::register($this->throwing());

        $previous = ini_set('error_log', '/dev/null');
        try {
            $rows = PriceUpdateFilterRegistry::narrow('articulos', [], $this->rows('A', 'B'));
        } finally {
            ini_set('error_log', (string) $previous);
        }

        $this->assertSame([], $rows, 'A broken filter must never broaden a price update: no rows');
    }

    public function testFiltersNarrowInSequence(): void
    {
        PriceUpdateFilterRegistry::register($this->filter(keep: static fn (array $f, array $row): bool => $row['key'] !== 'A'));
        PriceUpdateFilterRegistry::register($this->filter(keep: static fn (array $f, array $row): bool => $row['key'] !== 'C'));

        $rows = PriceUpdateFilterRegistry::narrow('articulos', [], $this->rows('A', 'B', 'C'));

        $this->assertSame(['B'], array_column($rows, 'key'));
    }

    public function testViewContextMergesSupportingFilters(): void
    {
        PriceUpdateFilterRegistry::register($this->filter(context: ['a' => 1, 'b' => 1]));
        PriceUpdateFilterRegistry::register($this->filter(context: ['b' => 2, 'c' => 3]));

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], PriceUpdateFilterRegistry::viewContext('articulos'));
    }

    // ---- doubles ----

    /** @return list<array{key: string, label: string}> */
    private function rows(string ...$keys): array
    {
        return array_map(static fn (string $k): array => ['key' => $k, 'label' => strtolower($k)], $keys);
    }

    /**
     * @param list<string> $keys
     * @param array<string, mixed> $context
     * @param list<string> $supports
     */
    private function filter(
        array $keys = [],
        array $context = [],
        array $supports = ['articulos', 'opcionales'],
        ?\Closure $keep = null,
        ?\stdClass $calls = null,
    ): PriceUpdateFilterInterface {
        return new class($keys, $context, $supports, $keep, $calls) implements PriceUpdateFilterInterface {
            public function __construct(
                private readonly array $fKeys,
                private readonly array $fContext,
                private readonly array $fSupports,
                private readonly ?\Closure $fKeep,
                private readonly ?\stdClass $fCalls,
            ) {
            }

            public function supports(string $scope): bool
            {
                return in_array($scope, $this->fSupports, true);
            }

            public function keys(string $scope): array
            {
                return $this->fKeys;
            }

            public function viewContext(string $scope): array
            {
                return $this->fContext;
            }

            public function narrow(string $scope, array $filters, array $rows): array
            {
                if ($this->fCalls !== null) {
                    $this->fCalls->narrow++;
                }
                if ($this->fKeep === null) {
                    return $rows;
                }

                return array_values(array_filter($rows, fn (array $row): bool => ($this->fKeep)($filters, $row)));
            }
        };
    }

    private function widening(): PriceUpdateFilterInterface
    {
        return $this->misbehaving(static fn (array $rows): array => array_merge($rows, [['key' => 'GHOST', 'label' => 'ghost']]));
    }

    private function reversing(): PriceUpdateFilterInterface
    {
        return $this->misbehaving(static fn (array $rows): array => array_reverse($rows));
    }

    private function throwing(): PriceUpdateFilterInterface
    {
        return $this->misbehaving(static function (array $rows): array {
            throw new \RuntimeException('boom');
        });
    }

    private function misbehaving(\Closure $narrow): PriceUpdateFilterInterface
    {
        return new class($narrow) implements PriceUpdateFilterInterface {
            public function __construct(private readonly \Closure $fNarrow)
            {
            }

            public function supports(string $scope): bool
            {
                return true;
            }

            public function keys(string $scope): array
            {
                return [];
            }

            public function viewContext(string $scope): array
            {
                return [];
            }

            public function narrow(string $scope, array $filters, array $rows): array
            {
                return ($this->fNarrow)($rows);
            }
        };
    }
}
