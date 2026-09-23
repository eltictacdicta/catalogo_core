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
 * `read_through()`.
 *
 * **The feature path is the DEFAULT.** Production must need no flag at all:
 * the legacy boolean columns are only read when the opt-out constant is
 * defined and explicitly FALSE. That opt-out is an emergency rollback switch,
 * not a production requirement — with it unset (or TRUE) the resolver-backed
 * feature tables are authoritative.
 *
 * This is required for the gated column drop (CAR-15 clause 2): once the
 * legacy columns are dropped, an undefined constant must still select the
 * feature path, or the catalog would read columns that no longer exist.
 */
final class CaracteristicaConfig
{
    public const READ_THROUGH_FLAG = 'FS_CATALOGO_CARACTERISTICAS_READ_THROUGH';

    /**
     * TRUE selects the feature path (the resolver + the feature tables).
     *
     * An undefined constant resolves to the feature path. Only an explicit
     * FALSE opts out to the legacy columns.
     */
    public static function read_through(): bool
    {
        return !self::legacy_read_explicitly_enabled();
    }

    /**
     * TRUE only when the emergency opt-out is defined and explicitly FALSE.
     *
     * Undefined (the production default) and an explicit TRUE both select the
     * feature path. The drop gate uses this to refuse while the legacy path was
     * deliberately selected.
     */
    public static function legacy_read_explicitly_enabled(): bool
    {
        return defined(self::READ_THROUGH_FLAG) && constant(self::READ_THROUGH_FLAG) === false;
    }
}
