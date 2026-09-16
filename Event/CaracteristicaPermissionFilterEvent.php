<?php
/**
 * This file is part of the catalogo_core plugin
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

namespace FSFramework\Plugins\catalogo_core\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Neutral permission-filter event dispatched before a feature-panel mutation
 * persists (CAR-18).
 *
 * Resolves to "allow" by default (zero listeners = unchanged behavior). A
 * listener may call deny(reason) to fail the operation before persistence; the
 * host surfaces getDenialReason() on deny. The event is plugin-agnostic: it
 * carries the gating context (codigo, action, nick, optional codtarifa).
 */
class CaracteristicaPermissionFilterEvent extends Event
{
    /** Frozen event name. */
    public const NAME = 'catalogo_core.caracteristica_permission_filter';

    public const ACTION_DELETE_DEFINITION = 'delete_definition';
    public const ACTION_ASSIGN_GLOBAL = 'assign_global';
    public const ACTION_ASSIGN_FAMILIA = 'assign_familia';

    private bool $allowed = true;
    private string $denialReason = '';

    public function __construct(
        private readonly string $codigo,
        private readonly string $action,
        private readonly string $nick,
        private readonly string $codtarifa = ''
    ) {
    }

    /**
     * Denies the gated action with a user-facing reason (fail-closed contract).
     */
    public function deny(string $reason): void
    {
        $this->allowed = false;
        $this->denialReason = $reason;
    }

    /**
     * True unless a listener denied the action (default resolution: allow).
     */
    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    public function getDenialReason(): string
    {
        return $this->denialReason;
    }

    public function getCodigo(): string
    {
        return $this->codigo;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getNick(): string
    {
        return $this->nick;
    }

    public function getCodtarifa(): string
    {
        return $this->codtarifa;
    }
}
