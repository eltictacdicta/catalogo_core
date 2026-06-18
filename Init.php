<?php
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

namespace FSFramework\Plugins\catalogo_core;

/**
 * Plugin initialization for catalogo_core.
 *
 * Model loading is handled by the framework's fs_model_autoloader, which
 * respects plugin dependency order and allows plugins to override models
 * from their dependencies. This is the standard FSFramework mechanism.
 *
 * The fs_model_autoloader automatically:
 * - Searches for models in plugin order (from $GLOBALS['plugins'])
 * - Loads models from model/ and model/core/ directories
 * - Creates class aliases for namespaced models (FSFramework\model\* → global)
 * - Allows dependent plugins to override parent plugin models
 *
 * No custom autoloader or compat layer is needed here.
 */
final class Init
{
    public function init(): void
    {
        // No initialization needed.
        // Model loading and aliasing is handled by fs_model_autoloader.
    }
}
