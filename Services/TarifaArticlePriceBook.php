<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
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
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

require_once __DIR__ . '/PriceUpdateTargetRegistry.php';

/**
 * Price book of one tariff for articles (R-TAR-PRC-002).
 *
 * Reads and writes ONLY `tarif_articulo_precios`, keyed by (referencia,
 * codtarifa). It never looks at the canonical price storage nor at the base
 * price of the article, so a preview can not read one storage while the apply
 * writes another. A reference without a tariff row is ABSENT from the result
 * (never 0.0) and `persist()` never inserts: the apply only updates rows the
 * preview showed.
 *
 * `persist()` loads the row with `get()` and saves it with `save()`, the path
 * that records the change in `tarif_precio_historial`.
 */
final class TarifaArticlePriceBook implements PriceBookInterface
{
    /** @var object|null tarif_articulo_precio (or a double with the same API) */
    private $precioModel;

    public function __construct(private readonly string $codtarifa, ?object $precioModel = null)
    {
        $this->precioModel = $precioModel;
    }

    public function currentPrices(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $map = $this->precioModel()->all_for_referencias(array_map('strval', $keys), $this->codtarifa);

        $prices = [];
        foreach ($keys as $key) {
            $key = (string) $key;
            if (isset($map[$key]) && array_key_exists('precio', $map[$key])) {
                $prices[$key] = (float) $map[$key]['precio'];
            }
        }

        return $prices;
    }

    public function persist(string $key, float $price): bool
    {
        $row = $this->precioModel()->get($key, $this->codtarifa);
        if (!$row) {
            return false;
        }

        $row->precio = $price;

        return (bool) $row->save();
    }

    private function precioModel(): object
    {
        if ($this->precioModel === null) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_articulo_precio.php';
            $this->precioModel = new \FSFramework\model\tarif_articulo_precio();
        }

        return $this->precioModel;
    }
}
