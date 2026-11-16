<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Decides whether the price-list UI (list CRUD, per-list prices in the article
 * editor, batch update by list) is offered.
 *
 * Price lists and tariffs are two editors for the same thing. Once a tariff is
 * active, prices are edited per tariff and the lists only mirror the tariffs
 * as storage: offering both shows the operator two prices for one article and
 * lets the list page delete the mirror a tariff depends on. So the list UI is
 * available only with the multi-tariff option on AND no active tariff.
 *
 * In both modes `articulos.pvp` stays what it was: the fallback price.
 */
final class PriceListAvailability
{
    /** @var callable|null test seam: returns whether any tariff is active */
    private $tariffProbe;

    public function __construct(private ?CatalogoOptions $options = null, ?callable $tariffProbe = null)
    {
        $this->tariffProbe = $tariffProbe;
    }

    /** TRUE when at least one active tariff exists. */
    public function tariffsActive(): bool
    {
        if ($this->tariffProbe !== null) {
            return (bool) ($this->tariffProbe)();
        }

        try {
            return (new \FSFramework\model\tarif_tarifa())->all_activas() !== [];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** TRUE when the price-list UI must be offered. */
    public function listUiEnabled(): bool
    {
        return ($this->options ?? new CatalogoOptions())->multiTariffEnabled() && !$this->tariffsActive();
    }
}
