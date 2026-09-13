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

require_once FS_FOLDER . '/plugins/catalogo_core/extras/fs_divisa_tools.php';

/**
 * Currency formatter backed by the catalogo_core-owned `fs_divisa_tools`.
 *
 * This is the PageController-safe counterpart of the legacy
 * `fbase_controller::simbolo_divisa()` / `show_precio()` helpers (design AD-5):
 * the unified opcionales list is a `PageController`, so it cannot inherit the
 * legacy currency helpers.
 */
final class CatalogoCurrencyFormatter
{
    /**
     * Currency symbol for a divisa code (falls back to the default divisa when
     * the code is empty).
     */
    public static function symbol(string $coddivisa): string
    {
        $tools = new \fs_divisa_tools($coddivisa);

        return (string) $tools->simbolo_divisa($coddivisa !== '' ? $coddivisa : false);
    }

    /**
     * Formatted price for a divisa code (falls back to the default divisa when
     * the code is empty).
     */
    public static function format(float $value, string $coddivisa): string
    {
        $tools = new \fs_divisa_tools($coddivisa);

        return (string) $tools->show_precio($value, $coddivisa !== '' ? $coddivisa : false);
    }
}
