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

namespace FSFramework\Plugins\catalogo_core\Services;

use FSFramework\Core\Plugins;

/**
 * CAR-20 — enabled-plugin ownership scope for feature definitions.
 *
 * A definition is **active** when its `origen` is empty (operator-created) or
 * when its non-empty `origen` names a plugin the framework registry reports as
 * enabled. A definition whose non-empty `origen` names a disabled plugin is
 * **inert**: the read paths hide it and the write paths refuse it.
 *
 * The rule is plugin-agnostic (CAR-19): it derives its decision solely from the
 * row's `origen` and \FSFramework\Core\Plugins::enabled(), and never references
 * a concrete plugin name or namespace.
 *
 * Fail-closed (D7): an unavailable registry — an empty `$GLOBALS['plugins']` —
 * makes every plugin-owned definition inert. `origen = ''` is the
 * unconditional exception, so operator-created rows stay active.
 *
 * The enabled set is request-stable (D9): a plugin toggle requires a new
 * request, so consumers may memoize the filtered definition map per instance.
 */
class CaracteristicaOwnership
{
    /**
     * Active when `origen` is empty, or names an enabled plugin.
     */
    public function is_active(string $origen): bool
    {
        $owner = trim($origen);
        if ($owner === '') {
            return true;
        }

        return in_array($owner, $this->enabled_plugins(), true);
    }

    /**
     * Enabled-plugin registry seam (overridable in DB-free tests).
     *
     * @return list<string>
     */
    protected function enabled_plugins(): array
    {
        return Plugins::enabled();
    }
}
