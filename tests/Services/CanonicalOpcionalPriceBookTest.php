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

use FSFramework\Plugins\catalogo_core\Services\CanonicalOpcionalPriceBook;
use FSFramework\Plugins\catalogo_core\Services\CanonicalPriceBooks;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Canonical opcional price book (SDD unificar-superficies-precio-catalogo-core,
 * WU-2.1 — R-BU-006, R-BU-001, R-BU-003): catalogo_opcional_precios keyed by
 * (id_opcional, codlista). A key without a row is ABSENT (never 0.0) and the
 * apply never inserts: only an existing row is persisted.
 */
#[CoversClass(CanonicalOpcionalPriceBook::class)]
#[CoversClass(CanonicalPriceBooks::class)]
final class CanonicalOpcionalPriceBookTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CanonicalPriceBooks.php';
    }

    public function testCanonicalOptionalBookReadsIdOpcionalAndCodlista(): void
    {
        $book = $this->makeBook('L1', ['L1,7' => 12.5, 'L2,7' => 99.0, 'L1,8' => 3.0]);

        $this->assertSame(['7' => 12.5], $book->currentPrices(['7']), 'Only the row of this list');
    }

    public function testOptionalBookOmitsMissingRows(): void
    {
        $book = $this->makeBook('L1', ['L1,7' => 4.0]);

        $prices = $book->currentPrices(['7', '8']);

        $this->assertArrayHasKey('7', $prices);
        $this->assertArrayNotHasKey('8', $prices, 'A missing row is not a price of 0.0');
    }

    public function testAnExplicitZeroRowIsAPrice(): void
    {
        $book = $this->makeBook('L1', ['L1,7' => 0.0]);

        $this->assertSame(['7' => 0.0], $book->currentPrices(['7']));
    }

    public function testOnlyRequestedKeysAreReturnedInRequestOrder(): void
    {
        $book = $this->makeBook('L1', ['L1,1' => 1.0, 'L1,2' => 2.0, 'L1,3' => 3.0]);

        $this->assertSame(['3' => 3.0, '1' => 1.0], $book->currentPrices(['3', '1']));
        $this->assertSame([], $book->currentPrices([]));
    }

    public function testOptionalBookPersistsExistingRowOnly(): void
    {
        $book = $this->makeBook('L2', ['L2,7' => 10.0], $capture);

        $this->assertTrue($book->persist('7', 11.0));
        $this->assertFalse($book->persist('8', 5.0), 'No row for (8, L2): apply never inserts');
        $this->assertSame([[7, 'L2', 11.0]], $capture->saved);
    }

    public function testPersistKeepsTheStoredPercentage(): void
    {
        $book = $this->makeBook('L2', ['L2,7' => 10.0], $capture, [7 => 15.0]);

        $book->persist('7', 11.0);

        $this->assertSame([15.0], $capture->savedPercentages, 'Updating the price must not clear the stored percentage');
    }

    public function testPersistReportsAFailedSave(): void
    {
        $book = $this->makeBook('L2', ['L2,7' => 10.0], $capture, [], [7]);

        $this->assertFalse($book->persist('7', 11.0));
    }

    public function testNonNumericKeyNeverReachesTheModel(): void
    {
        $book = $this->makeBook('L2', ['L2,7' => 10.0], $capture);

        $this->assertFalse($book->persist('abc', 1.0));
        $this->assertSame([], $capture->saved);
    }

    public function testFactoryBuildsTheOpcionalBookForAList(): void
    {
        $this->assertInstanceOf(CanonicalOpcionalPriceBook::class, CanonicalPriceBooks::opcionales('L1'));
    }

    // ---- factory of the book under test ----

    /**
     * @param array<string, float> $rows 'codlista,id_opcional' => precio
     * @param array<int, float> $percentages id_opcional => stored percentage
     * @param list<int> $failIds
     */
    private function makeBook(
        string $codlista,
        array $rows,
        ?\stdClass &$capture = null,
        array $percentages = [],
        array $failIds = [],
    ): CanonicalOpcionalPriceBook {
        $capture = new \stdClass();
        $capture->saved = [];
        $capture->savedPercentages = [];

        return new CanonicalOpcionalPriceBook($codlista, $this->fakePrecioModel($rows, $percentages, $failIds, $capture));
    }

    private function fakePrecioModel(array $rows, array $percentages, array $failIds, \stdClass $capture): object
    {
        return new class($rows, $percentages, $failIds, $capture) extends \FSFramework\model\catalogo_opcional_precio {
            public function __construct(
                private readonly array $fRows,
                private readonly array $fPercentages,
                private readonly array $fFailIds,
                private readonly \stdClass $fCapture,
            ) {
                parent::__construct();
            }

            public function all_from_lista($codlista)
            {
                $out = [];
                foreach ($this->fRows as $key => $precio) {
                    [$lista, $id] = explode(',', (string) $key, 2);
                    if ($lista === (string) $codlista) {
                        $out[] = $this->row((int) $id, $lista, (float) $precio);
                    }
                }

                return $out;
            }

            public function get($id_opcional, $codlista)
            {
                $key = $codlista . ',' . $id_opcional;
                if (!array_key_exists($key, $this->fRows)) {
                    return false;
                }

                return $this->row((int) $id_opcional, (string) $codlista, (float) $this->fRows[$key]);
            }

            public function save()
            {
                $this->fCapture->saved[] = [$this->id_opcional, $this->codlista, $this->precio];
                $this->fCapture->savedPercentages[] = $this->porcentaje;

                return !in_array($this->id_opcional, $this->fFailIds, true);
            }

            private function row(int $id, string $lista, float $precio): self
            {
                $row = new self($this->fRows, $this->fPercentages, $this->fFailIds, $this->fCapture);
                $row->id_opcional = $id;
                $row->codlista = $lista;
                $row->precio = $precio;
                $row->porcentaje = $this->fPercentages[$id] ?? null;

                return $row;
            }
        };
    }
}
