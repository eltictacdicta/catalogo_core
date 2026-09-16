<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace FSFramework\model;

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/caracteristica_scope_value.php';

/**
 * Global ("all products") scope of a tarifa feature value (CAR-05/CAR-09).
 */
class catalogo_caracteristica_global extends caracteristica_scope_value
{
    protected const TABLE = 'catalogo_caracteristica_global';
    protected const SCOPE = 'global';

    protected function install(): string
    {
        if (class_exists(tarif_tarifa::class)) {
            new tarif_tarifa();
        }
        if (class_exists(catalogo_caracteristica::class)) {
            new catalogo_caracteristica();
        }

        return '';
    }
}
