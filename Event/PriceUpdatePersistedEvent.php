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

namespace FSFramework\Plugins\catalogo_core\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Neutral, informative event dispatched ONCE after a batch price update was
 * applied (R-BU-009). It carries the rows the price book persisted with
 * success: the key, the previous price (read from the book at apply time, never
 * from the client) and the new price.
 *
 * It cannot cancel or alter the operation: a listener only learns what
 * happened (e.g. to keep an audit trail it owns). The event names no consumer
 * plugin and no consumer storage.
 */
final class PriceUpdatePersistedEvent extends Event
{
    /** Frozen event name (spec R-BU-009). */
    public const NAME = 'catalogo_core.price_update_persisted';

    /**
     * @param list<array{key: string, precio_anterior: float, precio_nuevo: float}> $rows
     */
    public function __construct(
        private readonly string $scope,
        private readonly string $targetCode,
        private readonly string $targetKey,
        private readonly array $rows,
    ) {
    }

    public function scope(): string
    {
        return $this->scope;
    }

    public function targetCode(): string
    {
        return $this->targetCode;
    }

    public function targetKey(): string
    {
        return $this->targetKey;
    }

    /** @return list<array{key: string, precio_anterior: float, precio_nuevo: float}> */
    public function rows(): array
    {
        return $this->rows;
    }
}
