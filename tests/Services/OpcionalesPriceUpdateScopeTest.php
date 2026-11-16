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

use FSFramework\Plugins\catalogo_core\Services\OpcionalesPriceScope;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateScopes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Scope "opcionales" (SDD unificar-superficies-precio-catalogo-core, WU-2.1 —
 * R-BU-006, R-BU-001, R-BU-002): opcionales selected by
 * catalogo_opcional_familias (+ recursive subfamilies), deterministic order by
 * codigo, the article group filter does not apply, and the storage is the
 * canonical opcional book regardless of the target.
 */
#[CoversClass(OpcionalesPriceScope::class)]
#[CoversClass(PriceUpdateScopes::class)]
final class OpcionalesPriceUpdateScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateScopes.php';
    }

    // ---- factory ----

    public function testFactoryResolvesOpcionales(): void
    {
        $scope = PriceUpdateScopes::forCode('opcionales');

        $this->assertInstanceOf(OpcionalesPriceScope::class, $scope);
        $this->assertSame('opcionales', $scope->code());
        $this->assertSame('Opcionales', $scope->label());
        $this->assertSame('articulos', PriceUpdateScopes::defaultCode(), 'The default scope does not change');
    }

    public function testLabelsListBothScopesInDisplayOrder(): void
    {
        $this->assertSame(
            ['articulos' => 'Artículos', 'opcionales' => 'Opcionales'],
            PriceUpdateScopes::labels(),
        );
    }

    // ---- R-BU-006: canonical storage, independent of the target ----

    public function testCanonicalBookIsTheOpcionalBookForEveryKey(): void
    {
        $scope = new OpcionalesPriceScope();

        $book = $scope->canonicalBook('L1');

        $this->assertInstanceOf(
            \FSFramework\Plugins\catalogo_core\Services\CanonicalOpcionalPriceBook::class,
            $book,
            'Opcionales always live in catalogo_opcional_precios (D1: the target is never consulted)',
        );
    }

    // ---- membership ----

    public function testOptionalMembershipFiltersByCatalogoOpcionalFamiliasWithRecursion(): void
    {
        $scope = $this->makeScope(
            [
                'F' => [[1, 'OPC0001', 'Uno']],
                'F1' => [[2, 'OPC0002', 'Dos']],
                'F2' => [[3, 'OPC0003', 'Tres']],
                'OTRA' => [[9, 'OPC0009', 'Nueve']],
            ],
            ['F' => ['F1', 'F2']],
        );

        $recursive = $scope->match(['codfamilia' => 'F', 'incluir_subfamilias' => true]);
        $flat = $scope->match(['codfamilia' => 'F', 'incluir_subfamilias' => false]);

        $this->assertSame(['1', '2', '3'], array_column($recursive, 'key'));
        $this->assertSame(['1'], array_column($flat, 'key'));
    }

    public function testAnOptionalInTwoFamiliesAppearsOnce(): void
    {
        $scope = $this->makeScope(
            ['F' => [[1, 'OPC0001', 'Uno']], 'F1' => [[1, 'OPC0001', 'Uno']]],
            ['F' => ['F1']],
        );

        $rows = $scope->match(['codfamilia' => 'F', 'incluir_subfamilias' => true]);

        $this->assertSame(['1'], array_column($rows, 'key'));
    }

    public function testOptionalGroupFilterIsIgnored(): void
    {
        $scope = $this->makeScope(['F' => [[1, 'OPC0001', 'Uno'], [2, 'OPC0002', 'Dos']]], []);

        $rows = $scope->match(['codfamilia' => 'F', 'codgrupo' => 99]);

        $this->assertSame(['1', '2'], array_column($rows, 'key'), 'The canonical article group does not apply to opcionales');
    }

    public function testRowsAreOrderedByCodigo(): void
    {
        $scope = $this->makeScope(
            [
                'F' => [[10, 'OPC0003', 'C'], [30, 'OPC0001', 'A']],
                'F1' => [[20, 'OPC0002', 'B']],
            ],
            ['F' => ['F1']],
        );

        $rows = $scope->match(['codfamilia' => 'F', 'incluir_subfamilias' => true]);

        $this->assertSame(['30', '20', '10'], array_column($rows, 'key'), 'Order is by codigo, not by id nor by family walk');
    }

    public function testLabelCombinesCodigoAndNombre(): void
    {
        $scope = $this->makeScope(['F' => [[1, 'OPC0001', 'Garantía'], [2, '', 'Sin código']]], []);

        $labels = array_column($scope->match(['codfamilia' => 'F']), 'label', 'key');

        $this->assertSame('OPC0001 — Garantía', $labels['1']);
        $this->assertSame('Sin código', $labels['2'], 'Without codigo the label is the name');
    }

    // ---- fail closed ----

    public function testEmptyFiltersReturnEmptyList(): void
    {
        $scope = $this->makeScope(['F' => [[1, 'OPC0001', 'Uno']]], []);

        $this->assertSame([], $scope->match([]));
        $this->assertSame([], $scope->match(['codfamilia' => '', 'codgrupo' => 5]));
    }

    public function testTargetKeysAreNotScopeFilters(): void
    {
        $scope = $this->makeScope(['F' => [[1, 'OPC0001', 'Uno']]], []);

        $this->assertSame([], $scope->match(['codlista' => 'L1', 'codtarifa' => 'T1']));
    }

    // ---- D11.1 equivalent: no silent cutoff ----

    public function testFamilyWithMoreOptionalsThanItemLimitIsComplete(): void
    {
        $members = [];
        for ($i = 1; $i <= 120; $i++) {
            $members[] = [$i, sprintf('OPC%04d', $i), 'N' . $i];
        }
        $scope = $this->makeScope(['F' => $members], []);

        $rows = $scope->match(['codfamilia' => 'F']);

        $this->assertCount(120, $rows, 'A family above FS_ITEM_LIMIT must not be silently truncated');
        $this->assertSame('1', $rows[0]['key']);
        $this->assertSame('120', $rows[119]['key']);
    }

    // ---- factory of the scope under test ----

    /**
     * @param array<string, list<array{0: int, 1: string, 2: string}>> $byFamily family => [id, codigo, nombre]
     * @param array<string, list<string>> $tree parent code => child codes
     */
    private function makeScope(array $byFamily, array $tree): OpcionalesPriceScope
    {
        $scope = new OpcionalesPriceScope();
        $scope->setFamiliaModel($this->fakeFamiliaModel($tree));
        $scope->setOpcionalFamiliaModel($this->fakeOpcionalFamiliaModel($byFamily));

        return $scope;
    }

    private function fakeFamiliaModel(array $tree): object
    {
        return new class($tree) extends \FSFramework\model\familia {
            public function __construct(private readonly array $fTree)
            {
                parent::__construct();
            }

            public function hijas($codmadre = false)
            {
                $out = [];
                foreach ($this->fTree[$codmadre] ?? [] as $cod) {
                    $fam = new \FSFramework\model\familia();
                    $fam->codfamilia = $cod;
                    $out[] = $fam;
                }

                return $out;
            }
        };
    }

    private function fakeOpcionalFamiliaModel(array $byFamily): object
    {
        return new class($byFamily) extends \FSFramework\model\catalogo_opcional_familia {
            public function __construct(private readonly array $fByFamily)
            {
                parent::__construct();
            }

            public function get_opcionales_from_familia($codfamilia)
            {
                $out = [];
                foreach ($this->fByFamily[$codfamilia] ?? [] as [$id, $codigo, $nombre]) {
                    $opcional = new \FSFramework\model\catalogo_opcional();
                    $opcional->id = $id;
                    $opcional->codigo = $codigo;
                    $opcional->nombre = $nombre;
                    $out[] = $opcional;
                }

                return $out;
            }
        };
    }
}
