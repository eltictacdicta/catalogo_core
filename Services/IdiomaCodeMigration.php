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

use FSFramework\model\catalogo_idioma;

/**
 * Renames legacy language codes ('es', 'en', 'es_es') to the standard locale
 * form ('es_ES', 'en_GB') in the registry and in every table keyed by it.
 *
 * Data-driven and idempotent: one SELECT on the tiny registry per boot, and
 * no statement at all once every code is canonical.
 */
final class IdiomaCodeMigration
{
    private const CHILD_TABLES = ['articulo_descripciones', 'catalogo_opcional_idiomas', 'catalogo_opcional_grupo_idiomas'];

    public static function migrateIfNeeded(\fs_db2 $db): void
    {
        if (!$db->table_exists('catalogo_idiomas')) {
            return;
        }

        $codes = array_map(
            static fn (array $row): string => (string) $row['codidioma'],
            $db->select('SELECT codidioma FROM catalogo_idiomas;') ?: []
        );

        $children = null;
        $changed = false;
        foreach ($codes as $old) {
            $new = catalogo_idioma::normalize_code($old);
            if ($new === $old) {
                continue;
            }

            // Two rows collapsing into one code ('es' + 'es_es') need a human
            // decision about which translations win: leave both untouched.
            foreach ($codes as $other) {
                if ($other !== $old && catalogo_idioma::normalize_code($other) === $new) {
                    error_log('[catalogo_core] idioma ' . $old . ' no migrado: ' . $new . ' ya existe.');
                    continue 2;
                }
            }

            $children ??= array_filter(self::CHILD_TABLES, static fn (string $t): bool => (bool) $db->table_exists($t));

            $db->begin_transaction();
            $ok = true;
            foreach (['catalogo_idiomas', ...$children] as $table) {
                $ok = $ok && $db->exec(
                    'UPDATE ' . $table . ' SET codidioma = ' . $db->var2str($new)
                    . ' WHERE codidioma = ' . $db->var2str($old) . ';'
                );
            }

            if (!$ok) {
                $db->rollback();
                continue;
            }

            $db->commit();
            $changed = true;
        }

        if ($changed && class_exists(\FSFramework\model\articulo::class)) {
            \FSFramework\model\articulo::invalidate_search_cache();
        }
    }
}
