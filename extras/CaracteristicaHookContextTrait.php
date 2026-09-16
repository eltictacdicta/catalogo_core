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

require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaResolver.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaValorBatchReader.php';

use FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorBatchReader;

/**
 * Hydrates the `caracteristicas` hook context (catalogo-render-hooks delta).
 *
 * Every frozen marker passes a `caracteristicas` map to the registered guest
 * template: definition `codigo` => effective raw value for the host entity and
 * the selected tarifa. The map is produced by a single batched read
 * (`CaracteristicaValorBatchReader`), never per definition, and the read never
 * persists a value row (CAR-07).
 *
 * Hosts without an article identity (for example the opcional detail page)
 * resolve an empty map: they still pass the key so guests can guard on it, and
 * they issue no query at all. The D12-derived opcional visibility is exposed by
 * the opcional list/panel surfaces, not by the hook context.
 */
trait CaracteristicaHookContextTrait
{
    /**
     * Memoized map, keyed by (referencia, codfamilia, codtarifa) so the two
     * markers of one host view never repeat the batched read.
     *
     * @var array<string, ?string>|null
     */
    private ?array $caracteristicas_context_cache = null;

    private string $caracteristicas_context_cache_key = '';

    /**
     * @return CaracteristicaResolver
     */
    protected function caracteristica_resolver()
    {
        return new CaracteristicaResolver();
    }

    /**
     * @return CaracteristicaValorBatchReader
     */
    protected function caracteristica_batch_reader()
    {
        return new CaracteristicaValorBatchReader();
    }

    /**
     * Referencia of the host entity when the caller does not provide one.
     */
    protected function caracteristicas_host_referencia(): string
    {
        return '';
    }

    /**
     * codfamilia of the host entity when the caller does not provide one.
     */
    protected function caracteristicas_host_familia(): string
    {
        return '';
    }

    /**
     * Tarifa selected by the host page when the caller does not provide one.
     */
    protected function caracteristicas_tarifa_seleccionada(): string
    {
        return '';
    }

    /**
     * Effective feature values for the host entity and the selected tarifa.
     *
     * @return array<string, ?string> codigo => raw effective value
     */
    public function caracteristicas_context(
        ?string $referencia = null,
        ?string $codfamilia = null,
        ?string $codtarifa = null
    ): array {
        if ($referencia === null) {
            $referencia = $this->caracteristicas_host_referencia();
            if ($codfamilia === null) {
                $codfamilia = $this->caracteristicas_host_familia();
            }
        }

        $referencia = trim($referencia);
        $codfamilia = $codfamilia === null ? '' : trim($codfamilia);

        if ($codtarifa === null || trim($codtarifa) === '') {
            $codtarifa = $this->caracteristicas_tarifa_seleccionada();
        }
        $codtarifa = trim($codtarifa);

        $cacheKey = $referencia . '|' . $codfamilia . '|' . $codtarifa;
        if ($this->caracteristicas_context_cache !== null && $this->caracteristicas_context_cache_key === $cacheKey) {
            return $this->caracteristicas_context_cache;
        }
        $this->caracteristicas_context_cache_key = $cacheKey;

        // A host without an article identity contributes a present but empty
        // map: the key must reach the guest, no query is issued.
        if ($referencia === '') {
            return $this->caracteristicas_context_cache = [];
        }

        $codigos = array_keys($this->caracteristica_resolver()->definitions());
        if ($codigos === []) {
            return $this->caracteristicas_context_cache = [];
        }

        $familias = $codfamilia === '' ? null : [$referencia => $codfamilia];
        $rows = $this->caracteristica_batch_reader()->for_referencias(
            [$referencia],
            $codtarifa,
            $familias,
            $codigos
        );

        return $this->caracteristicas_context_cache = $rows[$referencia] ?? [];
    }
}
