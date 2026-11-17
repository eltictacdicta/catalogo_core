<?php
/**
 * This file is part of catalogo_core
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
 * Moves `articulo_descripciones.descripcion_corta` (varchar 150) into the
 * `descripcion_larga` (text) column and drops the old one.
 *
 * Add + copy + drop instead of RENAME COLUMN: it is the same SQL on MySQL and
 * PostgreSQL, and it also covers the case where the schema check already
 * created an empty `descripcion_larga` next to the old column. Idempotent:
 * once the old column is gone it costs one column listing per boot.
 */
final class DescripcionLargaMigration
{
    private const TABLE = 'articulo_descripciones';

    public static function migrateIfNeeded(\fs_db2 $db): void
    {
        if (!$db->table_exists(self::TABLE)) {
            return;
        }

        $columns = array_column($db->get_columns(self::TABLE) ?: [], 'name');
        if (!in_array('descripcion_corta', $columns, true)) {
            return;
        }

        if (
            !in_array('descripcion_larga', $columns, true)
            && !$db->exec('ALTER TABLE ' . self::TABLE . ' ADD COLUMN descripcion_larga text;')
        ) {
            return;
        }

        // The old column is only dropped once its data is safely copied.
        $copied = $db->exec(
            'UPDATE ' . self::TABLE . ' SET descripcion_larga = descripcion_corta'
            . " WHERE descripcion_corta IS NOT NULL AND (descripcion_larga IS NULL OR descripcion_larga = '');"
        );
        if (!$copied) {
            return;
        }

        $db->exec('ALTER TABLE ' . self::TABLE . ' DROP COLUMN descripcion_corta;');
    }
}
