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
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CanonicalPriceBooks.php';

/**
 * Base target: the canonical price lists (`catalogo_listas_precio`).
 *
 * Available exactly when the list UI is (PriceListAvailability::listUiEnabled():
 * multi-tariff on and no active tariff). Article prices live in the canonical
 * book; this target has no book for any other scope, because the engine asks
 * the scope for its canonical book first (D1).
 */
final class ListaPriceUpdateTarget implements PriceUpdateTargetInterface
{
    public function __construct(
        private ?PriceListAvailability $availability = null,
        private ?\FSFramework\model\catalogo_lista_precio $listaModel = null,
    ) {
    }

    public function code(): string
    {
        return 'lista';
    }

    public function label(): string
    {
        return 'Lista de precio';
    }

    public function isAvailable(): bool
    {
        return ($this->availability ?? new PriceListAvailability(new CatalogoOptions()))->listUiEnabled();
    }

    public function keyField(): string
    {
        return 'codlista';
    }

    public function keyLabel(): string
    {
        return 'lista de precio';
    }

    public function keyOptions(): array
    {
        $options = [];
        foreach ($this->listaModel()->all() as $lista) {
            // Inactive lists are excluded from public flows (R-MT-001).
            if ($lista->activa) {
                $options[] = ['value' => (string) $lista->codlista, 'label' => (string) $lista->nombre];
            }
        }

        return $options;
    }

    public function keyFromFilters(array $filters): ?string
    {
        $codlista = trim((string) ($filters[$this->keyField()] ?? ''));

        return $codlista === '' ? null : $codlista;
    }

    public function bookFor(string $scope, string $key): ?PriceBookInterface
    {
        if ($key === '') {
            return null;
        }

        return match ($scope) {
            'articulos' => CanonicalPriceBooks::articulos($key),
            default => null,
        };
    }

    private function listaModel(): \FSFramework\model\catalogo_lista_precio
    {
        if ($this->listaModel === null) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
            $this->listaModel = new \FSFramework\model\catalogo_lista_precio();
        }

        return $this->listaModel;
    }
}
