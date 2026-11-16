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

use FSFramework\Plugins\catalogo_core\Services\CanonicalArticlePriceBook;
use FSFramework\Plugins\catalogo_core\Services\CanonicalPriceBooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Canonical article price book (SDD unificar-superficies-precio-catalogo-core,
 * WU-1.2 — R-BU-001, R-BU-003, R-BU-007): same rule as CatalogoPriceResolver
 * (list row first; fallback to articulos.pvp only on the default list), read
 * in one batch. An absent key means "no price" and is never defaulted to 0.0.
 */
#[CoversClass(CanonicalArticlePriceBook::class)]
#[CoversClass(CanonicalPriceBooks::class)]
final class CanonicalArticlePriceBookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CanonicalPriceBooks.php';
    }

    public function testCanonicalRowWins(): void
    {
        $book = $this->makeBook('L1', ['L1,A' => 20.0], 'DEF', ['A' => 10.0]);

        $this->assertSame(['A' => 20.0], $book->currentPrices(['A']));
    }

    public function testDefaultListFallsBackToPvp(): void
    {
        $book = $this->makeBook('DEF', [], 'DEF', ['A' => 12.3]);

        $this->assertSame(['A' => 12.3], $book->currentPrices(['A']));
    }

    public function testNonDefaultListMissingKeyIsOmitted(): void
    {
        $book = $this->makeBook('L1', ['L2,A' => 5.0], 'DEF', ['A' => 12.3]);

        $this->assertSame([], $book->currentPrices(['A']), 'Another list\'s row and the pvp are not prices of L1');
    }

    public function testAbsentKeyIsNotZero(): void
    {
        $book = $this->makeBook('L1', ['L1,A' => 7.5], 'DEF', []);

        $prices = $book->currentPrices(['A', 'B']);

        $this->assertArrayHasKey('A', $prices);
        $this->assertArrayNotHasKey('B', $prices);
    }

    public function testAnExplicitZeroRowIsAPrice(): void
    {
        $book = $this->makeBook('L1', ['L1,A' => 0.0], 'DEF', ['A' => 9.0]);

        $this->assertSame(['A' => 0.0], $book->currentPrices(['A']));
    }

    public function testOnlyRequestedKeysAreReturned(): void
    {
        $book = $this->makeBook('L1', ['L1,A' => 1.0, 'L1,B' => 2.0, 'L1,C' => 3.0], 'DEF', []);

        $this->assertSame(['B' => 2.0], $book->currentPrices(['B']));
        $this->assertSame([], $book->currentPrices([]));
    }

    public function testFallbackSkipsArticlesThatDoNotExist(): void
    {
        $book = $this->makeBook('DEF', [], 'DEF', ['A' => 4.0]);

        $this->assertSame(['A' => 4.0], $book->currentPrices(['A', 'GHOST']));
    }

    public function testPersistWritesPerRow(): void
    {
        $book = $this->makeBook('L2', [], 'DEF', [], ['BAD'], $capture);

        $this->assertTrue($book->persist('A', 8.8));
        $this->assertFalse($book->persist('BAD', 1.0));
        $this->assertSame([['A', 'L2', 8.8], ['BAD', 'L2', 1.0]], $capture->set_precio_calls);
        $this->assertSame([], $capture->set_pvp_calls, 'articulos.pvp must never be rewritten');
    }

    public function testFactoryBuildsTheArticleBookForAList(): void
    {
        $this->assertInstanceOf(CanonicalArticlePriceBook::class, CanonicalPriceBooks::articulos('L1'));
    }

    // ---- factory of the book under test ----

    /**
     * @param array<string, float> $rows 'codlista,referencia' => precio
     * @param array<string, float> $pvps referencia => pvp
     * @param list<string> $failRefs
     */
    private function makeBook(
        string $codlista,
        array $rows,
        string $defaultList,
        array $pvps,
        array $failRefs = [],
        ?\stdClass &$capture = null,
    ): CanonicalArticlePriceBook {
        $capture = new \stdClass();
        $capture->set_precio_calls = [];
        $capture->set_pvp_calls = [];

        return new CanonicalArticlePriceBook(
            $codlista,
            $this->fakePrecioModel($rows, $failRefs, $capture),
            $this->fakeListaModel($defaultList),
            $this->fakeArticuloModel($pvps, $capture),
        );
    }

    private function fakePrecioModel(array $rows, array $failRefs, \stdClass $capture): object
    {
        return new class($rows, $failRefs, $capture) extends \FSFramework\model\catalogo_articulo_precio {
            public function __construct(
                private readonly array $fRows,
                private readonly array $fFailRefs,
                private readonly \stdClass $fCapture,
            ) {
                parent::__construct();
            }

            public function all_from_lista($codlista)
            {
                $out = [];
                foreach ($this->fRows as $key => $precio) {
                    [$lista, $referencia] = explode(',', (string) $key, 2);
                    if ($lista !== (string) $codlista) {
                        continue;
                    }
                    $row = new \FSFramework\model\catalogo_articulo_precio();
                    $row->referencia = $referencia;
                    $row->codlista = $lista;
                    $row->precio = $precio;
                    $out[] = $row;
                }

                return $out;
            }

            public function set_precio($referencia, $codlista, $precio)
            {
                $this->fCapture->set_precio_calls[] = [$referencia, $codlista, $precio];

                return !in_array((string) $referencia, $this->fFailRefs, true);
            }
        };
    }

    private function fakeListaModel(string $defaultCode): object
    {
        return new class($defaultCode) extends \FSFramework\model\catalogo_lista_precio {
            public function __construct(private readonly string $fDefault)
            {
                parent::__construct();
            }

            public function get_default()
            {
                $lista = new \FSFramework\model\catalogo_lista_precio();
                $lista->codlista = $this->fDefault;

                return $lista;
            }
        };
    }

    private function fakeArticuloModel(array $pvps, \stdClass $capture): object
    {
        return new class($pvps, $capture) extends \FSFramework\model\articulo {
            public function __construct(
                private readonly array $fPvps,
                private readonly \stdClass $fCapture,
            ) {
                parent::__construct();
            }

            public function get($ref)
            {
                if (!array_key_exists((string) $ref, $this->fPvps)) {
                    return false;
                }

                $art = new \FSFramework\model\articulo();
                $art->referencia = (string) $ref;
                $art->pvp = $this->fPvps[(string) $ref];

                return $art;
            }

            public function set_pvp($p)
            {
                $this->fCapture->set_pvp_calls[] = $p;

                return true;
            }
        };
    }
}
