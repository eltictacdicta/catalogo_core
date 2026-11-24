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
require_once __DIR__ . '/TarifaArticlePriceBook.php';

/**
 * Price update target `tarifa` (R-TAR-PRC-001): the per-tariff article prices.
 *
 * Base target of this plugin (R-MT-011): only tariffs exist as a concept, so
 * the batch update targets them directly.
 *
 * Available while at least one tariff is active, the same set the retired
 * page offered. It selects no rows and never reads another storage: for the
 * `opcionales` scope it has no book, because those prices are canonical and the
 * engine asks the scope for its canonical book first.
 */
final class TarifaPriceUpdateTarget implements PriceUpdateTargetInterface
{
    /** @var object|null tarif_tarifa (or a double with all_activas()) */
    private $tarifaModel;

    /** @var object|null tarif_articulo_precio (handed to the book) */
    private $precioModel;

    public function __construct(?object $tarifaModel = null, ?object $precioModel = null)
    {
        $this->tarifaModel = $tarifaModel;
        $this->precioModel = $precioModel;
    }

    public function code(): string
    {
        return 'tarifa';
    }

    public function label(): string
    {
        return 'Tarifa';
    }

    public function isAvailable(): bool
    {
        try {
            return $this->activeTariffs() !== [];
        } catch (\Throwable $e) {
            error_log('[catalogo_core] tarifa price update target unavailable: ' . $e->getMessage());

            return false;
        }
    }

    public function keyField(): string
    {
        return 'codtarifa';
    }

    public function keyLabel(): string
    {
        return 'tarifa';
    }

    public function keyOptions(): array
    {
        $options = [];
        foreach ($this->activeTariffs() as $tarifa) {
            $options[] = ['value' => (string) $tarifa->codtarifa, 'label' => (string) $tarifa->nombre];
        }

        return $options;
    }

    public function keyFromFilters(array $filters): ?string
    {
        $codtarifa = trim((string) ($filters[$this->keyField()] ?? ''));

        return $codtarifa === '' ? null : $codtarifa;
    }

    public function bookFor(string $scope, string $key): ?PriceBookInterface
    {
        if ($key === '') {
            return null;
        }

        return match ($scope) {
            'articulos' => new TarifaArticlePriceBook($key, $this->precioModel),
            default => null,
        };
    }

    /** @return array<int, object> */
    private function activeTariffs(): array
    {
        if ($this->tarifaModel === null) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
            $this->tarifaModel = new \FSFramework\model\tarif_tarifa();
        }

        return (array) $this->tarifaModel->all_activas();
    }
}
