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

use FSFramework\Event\FSEventDispatcher;
use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;

/**
 * Per-row permission gate for the article Excel import (R-CEXC-003, AD-2/AD-5).
 *
 * Centralizes the ArticlePermissionFilterEvent dispatch for `pvp`-mapped
 * rows with fail-closed semantics:
 *
 * - Admin rows pass immediately WITHOUT constructing or dispatching the event
 *   (AD-5): the spec pins "never denied by this filter, regardless of
 *   listeners", which only a skip-dispatch guarantees by construction.
 * - Rows whose mapping does not include `pvp` pass untouched. applyMapping()
 *   drops empty cells, so a `pvp` key in the mapped row means the row writes
 *   pvp (create defaults or update mutation).
 * - Non-admin `pvp`-mapped rows dispatch the frozen ACTION_EDIT_ARTICLE (AD-2:
 *   an imported pvp write IS an article price edit; guest listeners resolve
 *   the matrix from nick + referencia only, so the action is not a factor).
 * - A listener throwing while evaluating a row denies that row only
 *   (fail-closed per row), mirroring the VentasArticulo.php host pattern.
 *
 * Returns null = allowed, or the denial reason string (listener-provided or
 * the generic fail-closed reason) = the row must be skipped.
 */
final class ArticuloExcelPermissionGate
{
    /**
     * @param array<string,string> $mappedRow
     */
    public static function check(array $mappedRow, string $referencia, string $nick, bool $isAdmin): ?string
    {
        // AD-5: admin dominance — skip the dispatch entirely so the outcome
        // never depends on listener correctness.
        if ($isAdmin) {
            return null;
        }

        // applyMapping() drops empty cells ⇒ a pvp key means the row writes pvp.
        if (!isset($mappedRow['pvp'])) {
            return null;
        }

        // AD-2: reuse the frozen action — no new constant (a new one would
        // invite fail-open drift from future action-scoped listeners).
        $event = new ArticlePermissionFilterEvent(
            $referencia,
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            $nick
        );

        try {
            FSEventDispatcher::getInstance()->dispatch($event, ArticlePermissionFilterEvent::NAME);
        } catch (\Throwable) {
            // Fail-closed per row (VentasArticulo.php:208-217 pattern): a
            // listener exception denies only this row; the import continues.
            // The fail-closed reason lands in the discarded-rows CSV (AD-8),
            // which is the audit trail. NOTE: no error_log() here — the host
            // suite runs with processIsolation=true, which surfaces any
            // stderr write as a PHPUnit error.
            return 'permission filter error';
        }

        return $event->isAllowed() ? null : $event->getDenialReason();
    }
}