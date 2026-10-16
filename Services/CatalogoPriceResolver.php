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
 * Effective-price resolution for the multi-tariff lists (multitarifa
 * task 3.1, R-MT-003, design PriceResolver contract):
 *
 *   effective(referencia, codlista) = catalogo_articulo_precios row
 *     ?? (codlista == default list ? articulos.pvp : null)
 *
 * The resolver is read-only: it NEVER rewrites articulos.pvp.
 */
final class CatalogoPriceResolver
{
    /** @var \FSFramework\model\catalogo_articulo_precio|null */
    private $precioModel = null;

    /** @var \FSFramework\model\catalogo_lista_precio|null */
    private $listaModel = null;

    /** @var \FSFramework\model\articulo|null */
    private $articuloModel = null;

    /** Effective price for the article in the given list, or null. */
    public function effective(string $referencia, string $codlista): ?float
    {
        $row = $this->precioModel()->get($referencia, $codlista);
        if ($row !== false) {
            return (float) $row->precio;
        }

        $default = $this->listaModel()->get_default();
        if ($default === false || (string) $default->codlista !== $codlista) {
            return null;
        }

        $articulo = $this->articuloModel()->get($referencia);
        if ($articulo === false) {
            return null;
        }

        return (float) $articulo->pvp;
    }

    // ---- lazy wiring ----

    private function precioModel(): \FSFramework\model\catalogo_articulo_precio
    {
        if ($this->precioModel === null) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_precio.php';
            $this->precioModel = new \FSFramework\model\catalogo_articulo_precio();
        }

        return $this->precioModel;
    }

    private function listaModel(): \FSFramework\model\catalogo_lista_precio
    {
        if ($this->listaModel === null) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
            $this->listaModel = new \FSFramework\model\catalogo_lista_precio();
        }

        return $this->listaModel;
    }

    private function articuloModel(): \FSFramework\model\articulo
    {
        if ($this->articuloModel === null) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
            $this->articuloModel = new \FSFramework\model\articulo();
        }

        return $this->articuloModel;
    }

    // ---- @internal test setters (NOT production surface) ----

    /** @internal testing-only */
    public function setPrecioModel(\FSFramework\model\catalogo_articulo_precio $model): void
    {
        $this->precioModel = $model;
    }

    /** @internal testing-only */
    public function setListaModel(\FSFramework\model\catalogo_lista_precio $model): void
    {
        $this->listaModel = $model;
    }

    /** @internal testing-only */
    public function setArticuloModel(\FSFramework\model\articulo $model): void
    {
        $this->articuloModel = $model;
    }
}
