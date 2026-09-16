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

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Single place that resolves the read-through switch (CAR-14).
 *
 * The literal constant name appears only here; every consumer calls
 * `read_through()`. Defaults to FALSE when the constant is undefined.
 */
final class CaracteristicaConfig
{
    public const READ_THROUGH_FLAG = 'FS_CATALOGO_CARACTERISTICAS_READ_THROUGH';

    public static function read_through(): bool
    {
        return defined(self::READ_THROUGH_FLAG) && (bool) constant(self::READ_THROUGH_FLAG);
    }
}
