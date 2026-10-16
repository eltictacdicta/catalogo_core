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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Effective-price resolution (multitarifa task 3.1, R-MT-003):
 * effective(articulo, lista) = per-list row ?? (lista == default ? articulos.pvp : null).
 * The resolver NEVER rewrites articulos.pvp.
 */
#[CoversClass(CatalogoPriceResolver::class)]
final class CatalogoPriceResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once FS_FOLDER . '/base/fs_functions.php';
        require_once FS_FOLDER . '/base/fs_model.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
        require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
    }

    /** Explicit per-list row wins over the default fallback. */
    public function test_explicit_row_wins_over_default_fallback(): void
    {
        $resolver = new CatalogoPriceResolver();
        $resolver->setPrecioModel($this->fakePrecioModel(['L1,A' => 99.5]));
        $resolver->setListaModel($this->fakeListaModel('DEF'));
        $resolver->setArticuloModel($this->fakeArticuloModel(['A' => 10.0]));

        $this->assertSame(99.5, $resolver->effective('A', 'L1'));
    }

    /** Default list without a row falls back to articulos.pvp (R-MT-003). */
    public function test_default_list_falls_back_to_article_pvp(): void
    {
        $resolver = new CatalogoPriceResolver();
        $resolver->setPrecioModel($this->fakePrecioModel([]));
        $resolver->setListaModel($this->fakeListaModel('DEF'));
        $resolver->setArticuloModel($this->fakeArticuloModel(['A' => 12.3]));

        $this->assertSame(12.3, $resolver->effective('A', 'DEF'));
    }

    /** Non-default list without a row resolves to null (no fallback). */
    public function test_non_default_list_without_row_resolves_null(): void
    {
        $resolver = new CatalogoPriceResolver();
        $resolver->setPrecioModel($this->fakePrecioModel([]));
        $resolver->setListaModel($this->fakeListaModel('DEF'));
        $resolver->setArticuloModel($this->fakeArticuloModel(['A' => 12.3]));

        $this->assertNull($resolver->effective('A', 'L2'));
    }

    /** Unknown article on the default list resolves to null (fail-closed). */
    public function test_unknown_article_resolves_null(): void
    {
        $resolver = new CatalogoPriceResolver();
        $resolver->setPrecioModel($this->fakePrecioModel([]));
        $resolver->setListaModel($this->fakeListaModel('DEF'));
        $resolver->setArticuloModel($this->fakeArticuloModel([]));

        $this->assertNull($resolver->effective('GHOST', 'DEF'));
    }

    // ---- test doubles ----

    /**
     * @param array<string, float> $rows keyed "codlista,referencia"
     */
    private function fakePrecioModel(array $rows): object
    {
        return new class($rows) extends \FSFramework\model\catalogo_articulo_precio {
            public function __construct(private readonly array $fRows)
            {
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

    /**
     * @param array<string, float> $pvps
     */
    private function fakeArticuloModel(array $pvps): object
    {
        return new class($pvps) extends \FSFramework\model\articulo {
            public function __construct(private readonly array $fPvps)
            {
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
        };
    }
}
