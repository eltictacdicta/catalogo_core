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

use FSFramework\Plugins\catalogo_core\Services\CatalogoPriceResolver;
use FSFramework\Plugins\catalogo_core\Services\CatalogoPriceUpdateService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Batch price update service (multitarifa task 4.1, R-BU-002 / R-BU-003):
 * AND-combined filter resolution (codlista required + optional family with
 * recursive subfamilies + optional article group) and the half-up rounding
 * formula round(p * (1 + pct/100), 2).
 */
#[CoversClass(CatalogoPriceUpdateService::class)]
final class CatalogoPriceUpdateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_grupo_articulo.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoPriceResolver.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoPriceUpdateService.php';
    }

    // ---- R-BU-002: family filter recursion ----

    /** Recursive: family F with children F1(A1), F2(A2) and A3 directly in F. */
    public function test_recursive_subfamily_inclusion_matches_all_descendants(): void
    {
        $service = $this->makeService(
            families: [
                'F' => [],
                'F1' => ['A1'],
                'F2' => ['A2'],
            ],
            tree: ['F' => ['F1', 'F2']],
            articles: ['A1' => 'F1', 'A2' => 'F2', 'A3' => 'F'],
        );

        $refs = $service->matchArticles([
            'codlista' => 'L1',
            'codfamilia' => 'F',
            'incluir_subfamilias' => true,
        ]);

        $this->assertEqualsCanonicalizing(['A1', 'A2', 'A3'], $refs);
    }

    /** Non-recursive: only direct members of the family are affected. */
    public function test_non_recursive_limits_to_direct_family_members(): void
    {
        $service = $this->makeService(
            families: [
                'F' => ['A3'],
                'F1' => ['A1'],
                'F2' => ['A2'],
            ],
            tree: ['F' => ['F1', 'F2']],
            articles: ['A1' => 'F1', 'A2' => 'F2', 'A3' => 'F'],
        );

        $refs = $service->matchArticles([
            'codlista' => 'L1',
            'codfamilia' => 'F',
            'incluir_subfamilias' => false,
        ]);

        $this->assertSame(['A3'], $refs);
    }

    /** Family + article-group filters combine with AND (intersection). */
    public function test_family_and_group_filters_intersect(): void
    {
        $service = $this->makeService(
            families: [
                'F' => ['A1', 'A2', 'A3'],
            ],
            tree: [],
            articles: ['A1' => 'F', 'A2' => 'F', 'A3' => 'F'],
            groupMembers: [5 => ['A2', 'A3', 'AX']],
        );

        $refs = $service->matchArticles([
            'codlista' => 'L1',
            'codfamilia' => 'F',
            'codgrupo' => 5,
        ]);

        $this->assertEqualsCanonicalizing(['A2', 'A3'], $refs);
    }

    /** Group filter alone scopes to the group's articles. */
    public function test_group_filter_alone_scopes_to_group_members(): void
    {
        $service = $this->makeService(
            families: [],
            tree: [],
            articles: [],
            groupMembers: [7 => ['B1', 'B2']],
        );

        $refs = $service->matchArticles([
            'codlista' => 'L1',
            'codgrupo' => 7,
        ]);

        $this->assertEqualsCanonicalizing(['B1', 'B2'], $refs);
    }

    /** Empty codlista fails closed: no matches, no writes possible. */
    public function test_missing_codlista_matches_nothing(): void
    {
        $service = $this->makeService(
            families: ['F' => ['A1']],
            tree: [],
            articles: ['A1' => 'F'],
        );

        $this->assertSame([], $service->matchArticles([
            'codfamilia' => 'F',
        ]));
    }

    // ---- R-BU-003: rounding formula ----

    /** 10.005 +10% → exactly 11.01 (round half-up to 2 decimals). */
    public function test_increase_rounds_half_up_to_two_decimals(): void
    {
        $service = $this->makeService(
            families: ['F' => ['A']],
            tree: [],
            articles: ['A' => 'F'],
            priceRows: ['L1,A' => 10.005],
        );

        $rows = $service->preview(['codlista' => 'L1', 'codfamilia' => 'F'], 10.0);

        $this->assertCount(1, $rows);
        $this->assertSame(11.01, $rows[0]['precio_nuevo']);
    }

    /** 10.00 −25% → 7.50. */
    public function test_negative_percentage_decreases(): void
    {
        $service = $this->makeService(
            families: ['F' => ['A']],
            tree: [],
            articles: ['A' => 'F'],
            priceRows: ['L1,A' => 10.0],
        );

        $rows = $service->preview(['codlista' => 'L1', 'codfamilia' => 'F'], -25.0);

        $this->assertSame(7.50, $rows[0]['precio_nuevo']);
    }

    /** Preview uses the effective price: per-list row wins (resolver semantics). */
    public function test_preview_uses_effective_per_list_price(): void
    {
        $service = $this->makeService(
            families: ['F' => ['A']],
            tree: [],
            articles: ['A' => 'F'],
            priceRows: ['L1,A' => 20.0],
            defaultList: 'DEF',
            pvps: ['A' => 10.0],
        );

        $rows = $service->preview(['codlista' => 'L1', 'codfamilia' => 'F'], 0.0);

        $this->assertSame(20.0, $rows[0]['precio_actual']);
    }

    /** Preview on the default list falls back to articulos.pvp when no row exists. */
    public function test_preview_falls_back_to_pvp_on_default_list(): void
    {
        $service = $this->makeService(
            families: ['F' => ['A']],
            tree: [],
            articles: ['A' => 'F'],
            priceRows: [],
            defaultList: 'DEF',
            pvps: ['A' => 12.3],
        );

        $rows = $service->preview(['codlista' => 'DEF', 'codfamilia' => 'F'], 0.0);

        $this->assertSame(12.3, $rows[0]['precio_actual']);
    }

    /** Zero matches → empty preview (controller shows the warning; no write). */
    public function test_preview_with_zero_matches_returns_empty(): void
    {
        $service = $this->makeService(
            families: ['F' => []],
            tree: [],
            articles: [],
        );

        $rows = $service->preview(['codlista' => 'L1', 'codfamilia' => 'F'], 5.0);

        $this->assertSame([], $rows);
    }

    // ---- R-BU-002: list-scoped persistence ----

    /** Apply writes exactly the previewed rows for the target list; pvp untouched. */
    public function test_apply_persists_only_target_list_rows_and_never_pvp(): void
    {
        $service = $this->makeService(
            families: ['F' => ['A']],
            tree: [],
            articles: ['A' => 'F'],
            priceRows: ['L1,A' => 10.0, 'L2,A' => 8.0],
            defaultList: 'DEF',
            pvps: ['A' => 10.0],
            applyCapture: $capture,
        );

        $rows = $service->preview(['codlista' => 'L2', 'codfamilia' => 'F'], 10.0);
        $result = $service->apply($rows, 'L2');

        $this->assertSame(1, $result['updated']);
        $this->assertSame([], $result['failed']);
        $this->assertSame([['A', 'L2', 8.8]], $capture->set_precio_calls);
        $this->assertSame([], $capture->set_pvp_calls, 'articulos.pvp must never be rewritten');
    }

    /** A row that fails to persist is reported, not counted as updated. */
    public function test_apply_reports_failed_rows(): void
    {
        $service = $this->makeService(
            families: ['F' => ['A', 'B']],
            tree: [],
            articles: ['A' => 'F', 'B' => 'F'],
            priceRows: ['L1,A' => 10.0, 'L1,B' => 20.0],
            failRefs: ['B'],
            applyCapture: $capture,
        );

        $rows = $service->preview(['codlista' => 'L1', 'codfamilia' => 'F'], 0.0);
        $result = $service->apply($rows, 'L1');

        $this->assertSame(1, $result['updated']);
        $this->assertSame(['B'], $result['failed']);
    }

    // ---- service factory ----

    private function makeService(
        array $families = [],
        array $tree = [],
        array $articles = [],
        array $groupMembers = [],
        array $priceRows = [],
        string $defaultList = 'DEF',
        array $pvps = [],
        array $failRefs = [],
        ?\stdClass &$applyCapture = null,
    ): CatalogoPriceUpdateService {
        $capture = new \stdClass();
        $capture->set_precio_calls = [];
        $capture->set_pvp_calls = [];
        $applyCapture = $capture;

        $resolver = new CatalogoPriceResolver();
        $resolver->setPrecioModel($this->fakePrecioModel($priceRows, $failRefs, $capture));
        $resolver->setListaModel($this->fakeListaModel($defaultList));
        $resolver->setArticuloModel($this->fakeArticuloPvpModel($pvps, $capture));

        $service = new CatalogoPriceUpdateService();
        $service->setResolver($resolver);
        $service->setFamiliaModel($this->fakeFamiliaModel($families, $tree));
        $service->setArticuloModel($this->fakeArticuloModel($articles));
        $service->setGrupoArticuloModel($this->fakeGrupoArticuloModel($groupMembers));
        $service->setPrecioModel($this->fakePrecioModel($priceRows, $failRefs, $capture));

        return $service;
    }

    // ---- test doubles ----

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

            public function get($referencia, $codlista)
            {
                $key = $codlista . ',' . $referencia;
                if (!array_key_exists($key, $this->fRows)) {
                    return false;
                }

                $row = new \FSFramework\model\catalogo_articulo_precio();
                $row->referencia = (string) $referencia;
                $row->codlista = (string) $codlista;
                $row->precio = $this->fRows[$key];

                return $row;
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

    private function fakeArticuloPvpModel(array $pvps, \stdClass $capture): object
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

    private function fakeFamiliaModel(array $families, array $tree): object
    {
        return new class($families, $tree) extends \FSFramework\model\familia {
            public function __construct(
                private readonly array $fFamilies,
                private readonly array $fTree,
            ) {
                // familia's own constructor already calls parent::__construct('familias')
                parent::__construct();
            }

            public function hijas($codmadre = false)
            {
                $children = $this->fTree[$codmadre] ?? [];
                $out = [];
                foreach ($children as $cod) {
                    $fam = new \FSFramework\model\familia();
                    $fam->codfamilia = $cod;
                    $out[] = $fam;
                }

                return $out;
            }
        };
    }

    private function fakeArticuloModel(array $byFamily): object
    {
        return new class($byFamily) extends \FSFramework\model\articulo {
            public function __construct(private readonly array $fByFamily)
            {
                parent::__construct();
            }

            public function all_from_familia($cod, $offset = 0, $limit = 0)
            {
                $out = [];
                foreach ($this->fByFamily as $ref => $fam) {
                    if ($fam !== $cod) {
                        continue;
                    }
                    $art = new \FSFramework\model\articulo();
                    $art->referencia = $ref;
                    $art->codfamilia = $fam;
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
