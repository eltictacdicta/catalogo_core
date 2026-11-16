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

use FSFramework\Plugins\catalogo_core\Services\PriceBookInterface;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateTargetInterface;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateTargetRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Neutral registry of price update targets (SDD
 * unificar-superficies-precio-catalogo-core, WU-1.1 — R-BU-002, R-PSO-002):
 * unknown codes resolve to null (the controller fails closed), duplicates keep
 * the first registration, availability is declared by each target.
 */
#[CoversClass(PriceUpdateTargetRegistry::class)]
final class PriceUpdateTargetRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateTargetRegistry.php';
        PriceUpdateTargetRegistry::reset();
    }

    protected function tearDown(): void
    {
        PriceUpdateTargetRegistry::reset();
        parent::tearDown();
    }

    public function testRegisterResolveReset(): void
    {
        $target = $this->fakeTarget('alpha');

        PriceUpdateTargetRegistry::register($target);

        $this->assertSame($target, PriceUpdateTargetRegistry::resolve('alpha'));

        PriceUpdateTargetRegistry::reset();

        $this->assertNull(PriceUpdateTargetRegistry::resolve('alpha'));
        $this->assertSame([], PriceUpdateTargetRegistry::available());
    }

    public function testUnknownCodeResolvesToNull(): void
    {
        PriceUpdateTargetRegistry::register($this->fakeTarget('alpha'));

        $this->assertNull(PriceUpdateTargetRegistry::resolve('nope'));
        $this->assertNull(PriceUpdateTargetRegistry::resolve(''));
    }

    public function testDuplicateRegistrationKeepsFirst(): void
    {
        $first = $this->fakeTarget('alpha', 'First');
        $second = $this->fakeTarget('alpha', 'Second');

        PriceUpdateTargetRegistry::register($first);
        PriceUpdateTargetRegistry::register($second);

        $this->assertSame($first, PriceUpdateTargetRegistry::resolve('alpha'));
        $this->assertSame(['alpha' => 'First'], PriceUpdateTargetRegistry::available());
    }

    public function testAvailablePreservesRegistrationOrder(): void
    {
        PriceUpdateTargetRegistry::register($this->fakeTarget('zeta', 'Z'));
        PriceUpdateTargetRegistry::register($this->fakeTarget('alpha', 'A'));
        PriceUpdateTargetRegistry::register($this->fakeTarget('mid', 'M'));

        $this->assertSame(['zeta', 'alpha', 'mid'], array_keys(PriceUpdateTargetRegistry::available()));
    }

    public function testAvailableOmitsTargetsThatDeclareThemselvesUnavailable(): void
    {
        PriceUpdateTargetRegistry::register($this->fakeTarget('on', 'On', true));
        PriceUpdateTargetRegistry::register($this->fakeTarget('off', 'Off', false));

        $this->assertSame(['on' => 'On'], PriceUpdateTargetRegistry::available());
        $this->assertNotNull(PriceUpdateTargetRegistry::resolve('off'), 'resolve() is a lookup; availability is checked by the caller');
    }

    public function testDefaultCodePrefersLista(): void
    {
        PriceUpdateTargetRegistry::register($this->fakeTarget('otro'));
        PriceUpdateTargetRegistry::register($this->fakeTarget('lista'));

        $this->assertSame('lista', PriceUpdateTargetRegistry::defaultCode());
    }

    public function testDefaultCodeFallsBackToFirstAvailableThenEmpty(): void
    {
        $this->assertSame('', PriceUpdateTargetRegistry::defaultCode());

        PriceUpdateTargetRegistry::register($this->fakeTarget('off', 'Off', false));
        $this->assertSame('', PriceUpdateTargetRegistry::defaultCode());

        PriceUpdateTargetRegistry::register($this->fakeTarget('uno'));
        PriceUpdateTargetRegistry::register($this->fakeTarget('dos'));
        $this->assertSame('uno', PriceUpdateTargetRegistry::defaultCode());
    }

    public function testRegisterDefaultsIsIdempotent(): void
    {
        PriceUpdateTargetRegistry::registerDefaults();
        $first = PriceUpdateTargetRegistry::resolve('lista');

        PriceUpdateTargetRegistry::registerDefaults();
        PriceUpdateTargetRegistry::registerDefaults();

        $this->assertNotNull($first, 'registerDefaults() must register the base "lista" target');
        $this->assertSame($first, PriceUpdateTargetRegistry::resolve('lista'));
    }

    public function testInitRegistersTheBaseTargetsOnce(): void
    {
        $source = (string) file_get_contents(FS_FOLDER . '/plugins/catalogo_core/Init.php');

        $this->assertSame(
            1,
            substr_count($source, 'PriceUpdateTargetRegistry::registerDefaults()'),
            'Init::init() registers the base target exactly once, behind a static guard'
        );
        $this->assertStringContainsString('$priceTargetsRegistered', $source);
    }

    private function fakeTarget(string $code, string $label = '', bool $available = true): PriceUpdateTargetInterface
    {
        return new class($code, $label !== '' ? $label : $code, $available) implements PriceUpdateTargetInterface {
            public function __construct(
                private readonly string $fCode,
                private readonly string $fLabel,
                private readonly bool $fAvailable,
            ) {
            }

            public function code(): string
            {
                return $this->fCode;
            }

            public function label(): string
            {
                return $this->fLabel;
            }

            public function isAvailable(): bool
            {
                return $this->fAvailable;
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
                return null;
            }
        };
    }
}
