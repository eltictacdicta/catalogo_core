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

require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateTargetRegistry.php';

/**
 * Canonical article price book: reads and writes `catalogo_articulo_precios`
 * for one price list (R-BU-001, R-BU-007).
 *
 * Read rule = CatalogoPriceResolver::effective(): the list row wins; the
 * fallback to articulos.pvp exists only on the default list. The whole list is
 * read in one query instead of one query per row. A key without a price is
 * ABSENT from the result (never 0.0). articulos.pvp is never rewritten.
 */
final class CanonicalArticlePriceBook implements PriceBookInterface
{
    /** @var \FSFramework\model\catalogo_articulo_precio|null */
    private $precioModel;

    /** @var \FSFramework\model\catalogo_lista_precio|null */
    private $listaModel;

    /** @var \FSFramework\model\articulo|null */
    private $articuloModel;

    public function __construct(
        private readonly string $codlista,
        ?\FSFramework\model\catalogo_articulo_precio $precioModel = null,
        ?\FSFramework\model\catalogo_lista_precio $listaModel = null,
        ?\FSFramework\model\articulo $articuloModel = null,
    ) {
        $this->precioModel = $precioModel;
        $this->listaModel = $listaModel;
        $this->articuloModel = $articuloModel;
    }

    public function currentPrices(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $wanted = [];
        foreach ($keys as $key) {
            $wanted[(string) $key] = true;
        }

        $found = [];
        foreach ($this->precioModel()->all_from_lista($this->codlista) as $row) {
            $referencia = (string) $row->referencia;
            if (isset($wanted[$referencia])) {
                $found[$referencia] = (float) $row->precio;
            }
        }

        $missing = [];
        foreach ($keys as $key) {
            if (!array_key_exists((string) $key, $found)) {
                $missing[] = (string) $key;
            }
        }

        if ($missing !== [] && $this->isDefaultList()) {
            foreach ($missing as $referencia) {
                $articulo = $this->articuloModel()->get($referencia);
                if ($articulo !== false) {
                    $found[$referencia] = (float) $articulo->pvp;
                }
            }
        }

        // Answer in the order the keys were asked.
        $prices = [];
        foreach ($keys as $key) {
            if (array_key_exists((string) $key, $found)) {
                $prices[(string) $key] = $found[(string) $key];
            }
        }

        return $prices;
    }

    public function persist(string $key, float $price): bool
    {
        return (bool) $this->precioModel()->set_precio($key, $this->codlista, $price);
    }

    private function isDefaultList(): bool
    {
        $default = $this->listaModel()->get_default();

        return $default !== false && (string) $default->codlista === $this->codlista;
    }

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
}

/** Factory of the canonical price books owned by catalogo_core. */
final class CanonicalPriceBooks
{
    public static function articulos(string $codlista): PriceBookInterface
    {
        return new CanonicalArticlePriceBook($codlista);
    }
}
