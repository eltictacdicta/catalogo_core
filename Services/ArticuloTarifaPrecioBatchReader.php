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

/**
 * Per-tarifa article price/state batch reader (spec ALC-02, AD-W3-4).
 *
 * One `WHERE codtarifa = ? AND referencia IN (...)` query per page; the
 * returned map is keyed by referencia and every requested reference resolves
 * to the ALC-02 defaults when it has no stored row:
 * `precio 0.0 / activo TRUE / en_tarifa FALSE / en_catalogo FALSE`.
 *
 * Display formatting stays with {@see CatalogoCurrencyFormatter}.
 */
class ArticuloTarifaPrecioBatchReader
{
    /** @var array{precio: float, activo: bool, en_tarifa: bool, en_catalogo: bool} */
    private const DEFAULTS = [
        'precio' => 0.0,
        'activo' => true,
        'en_tarifa' => false,
        'en_catalogo' => false,
    ];

    /**
     * Overridable model seam (unit tests inject a DB-free stub).
     *
     * @return \FSFramework\model\tarif_articulo_precio
     */
    protected function precio_model()
    {
        return new \FSFramework\model\tarif_articulo_precio();
    }

    /**
     * @param array<int, string> $refs
     * @return array<string, array{precio: float, activo: bool, en_tarifa: bool, en_catalogo: bool}>
     */
    public function for_referencias(array $refs, string $codtarifa): array
    {
        if ($refs === []) {
            return [];
        }

        $map = $this->precio_model()->all_for_referencias($refs, $codtarifa);

        $result = [];
        foreach ($refs as $ref) {
            $key = (string) $ref;
            $result[$key] = $map[$key] ?? self::DEFAULTS;
        }

        return $result;
    }
}
