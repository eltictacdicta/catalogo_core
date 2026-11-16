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

use FSFramework\Plugins\catalogo_core\Services\ArticulosPriceScope;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateScopes;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Scope "articulos" (SDD unificar-superficies-precio-catalogo-core, WU-1.2 —
 * R-BU-001, R-BU-002, D11.1, D11.3 order):
 * AND-combined family (+ recursive subfamilies) / article-group selection,
 * complete pagination of a family and a deterministic global order by
 * referencia. The scope never sees the target.
 */
#[CoversClass(ArticulosPriceScope::class)]
#[CoversClass(PriceUpdateScopes::class)]
final class ArticulosPriceUpdateScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_grupo_articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateScopes.php';
    }

    // ---- factory ----

    public function testFactoryResolvesArticulosAndFailsClosedOnUnknownCodes(): void
    {
        $this->assertSame('articulos', PriceUpdateScopes::defaultCode());
        $this->assertSame('articulos', PriceUpdateScopes::forCode('articulos')?->code());
        $this->assertNull(PriceUpdateScopes::forCode('nope'));
        $this->assertNull(PriceUpdateScopes::forCode(''));
    }

    public function testLabelsListEveryScopeCodeWithItsLabel(): void
    {
        $this->assertSame(['articulos' => 'Artículos'], PriceUpdateScopes::labels());
    }

    public function testArticulosScopeHasNoCanonicalBookSoTheTargetDecides(): void
    {
        $scope = $this->makeScope(['F' => ['A']], []);

        $this->assertNull($scope->canonicalBook('L1'), 'Article storage depends on the target (D1)');
    }

    // ---- D11.1: complete pagination ----

    public function testFamilyLargerThanItemLimitIsFullyPaginated(): void
    {
        $refs = [];
        for ($i = 1; $i <= 120; $i++) {
            $refs[] = sprintf('R%03d', $i);
        }
        $scope = $this->makeScope(['F' => $refs], []);

        $rows = $scope->match(['codfamilia' => 'F']);

        $this->assertCount(120, $rows, 'A family above FS_ITEM_LIMIT must not be silently truncated');
        $this->assertSame('R001', $rows[0]['key']);
        $this->assertSame('R120', $rows[119]['key']);
    }

    public function testFamilyExactlyAtItemLimitIsComplete(): void
    {
        $refs = [];
        for ($i = 1; $i <= FS_ITEM_LIMIT; $i++) {
            $refs[] = sprintf('R%03d', $i);
        }
        $scope = $this->makeScope(['F' => $refs], []);

        $this->assertCount(FS_ITEM_LIMIT, $scope->match(['codfamilia' => 'F']));
    }

    // ---- D11.3: deterministic order ----

    public function testRowsAreOrderedByReferenciaAcrossFamilies(): void
    {
        $scope = $this->makeScope(
            ['F' => ['A3', 'A1'], 'F1' => ['A2', 'A4']],
            ['F' => ['F1']],
        );

        $rows = $scope->match(['codfamilia' => 'F', 'incluir_subfamilias' => true]);

        $this->assertSame(['A1', 'A2', 'A3', 'A4'], array_column($rows, 'key'));
    }

    // ---- R-BU-002: filters ----

    public function testEmptyFiltersReturnEmptyList(): void
    {
        $scope = $this->makeScope(['F' => ['A']], []);

        $this->assertSame([], $scope->match([]));
        $this->assertSame([], $scope->match(['codfamilia' => '', 'codgrupo' => null]));
    }

    public function testTargetKeysAreNotScopeFilters(): void
    {
        $scope = $this->makeScope(['F' => ['A']], []);

        $this->assertSame([], $scope->match(['codlista' => 'L1', 'codtarifa' => 'T1']));
    }

    public function testRecursiveSubfamilies(): void
    {
        $scope = $this->makeScope(
            ['F' => ['A3'], 'F1' => ['A1'], 'F2' => ['A2']],
            ['F' => ['F1', 'F2']],
        );

        $rows = $scope->match(['codfamilia' => 'F', 'incluir_subfamilias' => true]);

        $this->assertSame(['A1', 'A2', 'A3'], array_column($rows, 'key'));
    }

    public function testNonRecursiveFamilyLimit(): void
    {
        $scope = $this->makeScope(
            ['F' => ['A3'], 'F1' => ['A1'], 'F2' => ['A2']],
            ['F' => ['F1', 'F2']],
        );

        $rows = $scope->match(['codfamilia' => 'F', 'incluir_subfamilias' => false]);

        $this->assertSame(['A3'], array_column($rows, 'key'));
    }

    public function testGroupFilterIsAnded(): void
    {
        $scope = $this->makeScope(
            ['F' => ['A1', 'A2', 'A3']],
            [],
            [5 => ['A2', 'A3', 'AX']],
        );

        $rows = $scope->match(['codfamilia' => 'F', 'codgrupo' => 5]);

        $this->assertSame(['A2', 'A3'], array_column($rows, 'key'));
    }

    public function testGroupFilterAloneScopesToGroupMembers(): void
    {
        $scope = $this->makeScope([], [], [7 => ['B2', 'B1']]);

        $rows = $scope->match(['codgrupo' => 7]);

        $this->assertSame(['B1', 'B2'], array_column($rows, 'key'));
    }

    public function testLabelUsesDescriptionWhenKnownAndFallsBackToReferencia(): void
    {
        $scope = $this->makeScope(['F' => ['A1']], [], [5 => ['A1', 'G9']], ['A1' => 'Tornillo']);

        $byFamily = $scope->match(['codfamilia' => 'F']);
        $byGroup = $scope->match(['codgrupo' => 5]);

        $this->assertSame('Tornillo', $byFamily[0]['label']);
        $this->assertSame('G9', $byGroup[1]['label'], 'No description known: the label is the key');
    }

    // ---- factory of the scope under test ----

    /**
     * @param array<string, list<string>> $byFamily family code => references
     * @param array<string, list<string>> $tree parent code => child codes
     * @param array<int, list<string>> $groups group id => references
     * @param array<string, string> $descriptions reference => description
     */
    private function makeScope(array $byFamily, array $tree, array $groups = [], array $descriptions = []): ArticulosPriceScope
    {
        $scope = new ArticulosPriceScope();
        $scope->setFamiliaModel($this->fakeFamiliaModel($tree));
        $scope->setArticuloModel($this->fakeArticuloModel($byFamily, $descriptions));
        $scope->setGrupoArticuloModel($this->fakeGrupoArticuloModel($groups));

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

    /** Honors $offset/$limit exactly like articulo::all_from_familia(). */
    private function fakeArticuloModel(array $byFamily, array $descriptions): object
    {
        return new class($byFamily, $descriptions) extends \FSFramework\model\articulo {
            public function __construct(
                private readonly array $fByFamily,
                private readonly array $fDescriptions,
            ) {
                parent::__construct();
            }

            public function all_from_familia($cod, $offset = 0, $limit = FS_ITEM_LIMIT)
            {
                $out = [];
                foreach (array_slice($this->fByFamily[$cod] ?? [], (int) $offset, (int) $limit) as $ref) {
                    $art = new \FSFramework\model\articulo();
                    $art->referencia = $ref;
                    $art->codfamilia = $cod;
                    $art->descripcion = $this->fDescriptions[$ref] ?? '';
                    $out[] = $art;
                }

                return $out;
            }
        };
    }

    private function fakeGrupoArticuloModel(array $byGroup): object
    {
        return new class($byGroup) extends \FSFramework\model\catalogo_grupo_articulo {
            public function __construct(private readonly array $fByGroup)
            {
                parent::__construct();
            }

            public function all_from_grupo($id_grupo)
            {
                $out = [];
                foreach ($this->fByGroup[(int) $id_grupo] ?? [] as $ref) {
                    $row = new \FSFramework\model\catalogo_grupo_articulo();
                    $row->id_grupo = (int) $id_grupo;
                    $row->referencia = $ref;
                    $out[] = $row;
                }

                return $out;
            }
        };
    }
}
