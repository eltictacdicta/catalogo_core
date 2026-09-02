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
 * Neutral permission-filter event dispatched before an article edit persists.
 *
 * Resolves to "allow" by default (zero listeners = unchanged behavior).
 * A listener may call deny(reason) to fail the operation before persistence;
 * the host surfaces getDenialReason() to the user on deny.
 *
 * The event is plugin-agnostic on purpose: it carries the gating context
 * (referencia, action, nick, optional codtarifa) and nothing else.
 */
class ArticlePermissionFilterEvent extends Event
{
    /** Frozen event name (spec R-TAR-HOOK-004). */
    public const NAME = 'catalogo_core.article_permission_filter';

    /** Action under gate: an article edit on the article page. */
    public const ACTION_EDIT_ARTICLE = 'edit_article';

    private bool $allowed = true;
    private string $denialReason = '';

    public function __construct(
        private readonly string $referencia,
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

    public function getReferencia(): string
    {
        return $this->referencia;
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
