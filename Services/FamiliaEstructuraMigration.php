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

/**
 * Copies the legacy per-tariff family structure into the canonical
 * per-price-list tables owned by catalogo_core.
 *
 * This is the ONLY place allowed to read `tarif_tarifa_familia` /
 * `tarif_tarifa_articulo` (D6). The copy is one-way, set-based and
 * non-destructive: the legacy tables are never updated, deleted or dropped.
 *
 * Step order is FK-safe (R-FJ-005): family structures first, article
 * assignments second. Each step runs in its own transaction and is idempotent
 * through the engine's native conflict handling (`INSERT IGNORE` on MySQL,
 * `ON CONFLICT DO NOTHING` on PostgreSQL), so a re-run with unchanged legacy
 * data inserts nothing.
 */
final class FamiliaEstructuraMigration
{
    private const LEGACY_FAMILIES = 'tarif_tarifa_familia';
    private const LEGACY_ARTICLES = 'tarif_tarifa_articulo';
    private const TARGET_FAMILIES = 'catalogo_familia_estructura';
    private const TARGET_ARTICLES = 'catalogo_articulo_familia_estructura';
    private const PRICE_LISTS = 'catalogo_listas_precio';

    private const ORPHAN_LOG = '[catalogo_core] familia-estructura migration: %d orphan rows skipped';

    /**
     * @param string|null $dialect Internal test seam: 'mysql' or 'postgresql'.
     *                             Null resolves the configured engine.
     */
    public static function migrateIfNeeded(\fs_db2 $db, ?string $dialect = null): void
    {
        $postgres = self::isPostgres($dialect);

        if (!self::tableExists($db, self::LEGACY_FAMILIES, $postgres)) {
            // Fresh install without the legacy plugin: nothing to copy.
            return;
        }

        if (!self::tableExists($db, self::PRICE_LISTS, $postgres)) {
            // The canonical list table is the FK target of both copies.
            return;
        }

        $orphans = 0;

        if (self::tableExists($db, self::TARGET_FAMILIES, $postgres)) {
            $orphans += self::countOrphans($db, self::LEGACY_FAMILIES);
            self::runStep(
                $db,
                self::buildFamilyCopySql($postgres),
                self::TARGET_FAMILIES
            );
        }

        if (self::tableExists($db, self::LEGACY_ARTICLES, $postgres)
            && self::tableExists($db, self::TARGET_ARTICLES, $postgres)
        ) {
            $orphans += self::countOrphans($db, self::LEGACY_ARTICLES);
            self::runStep(
                $db,
                self::buildArticleCopySql($postgres),
                self::TARGET_ARTICLES
            );
        }

        if ($orphans > 0) {
            error_log(sprintf(self::ORPHAN_LOG, $orphans));
        }
    }

    /**
     * Runs one copy inside its own transaction. A failure rolls the step back,
     * is logged, and never aborts the remaining steps (partial migrations are
     * resumable because both copies are idempotent).
     */
    private static function runStep(\fs_db2 $db, string $sql, string $target): void
    {
        try {
            $db->begin_transaction();
            $db->exec($sql);
            $db->commit();
        } catch (\Throwable $e) {
            try {
                $db->rollback();
            } catch (\Throwable $rollbackError) {
                // Keep the original failure as the reported one.
            }

            error_log('[catalogo_core] ' . $target . ' familia-estructura migration step failed: ' . $e->getMessage());
        }
    }

    private static function buildFamilyCopySql(bool $postgres): string
    {
        $sql = self::insertHead($postgres, self::TARGET_FAMILIES)
            . ' (codlista, codfamilia, madre, capitulo, nivel, en_catalogo, en_tarifa, activa, orden) '
            . 'SELECT t.codtarifa, t.codfamilia, t.madre, '
            . "COALESCE(t.capitulo, ''), COALESCE(t.nivel, ''), "
            . 'COALESCE(t.en_catalogo, TRUE), COALESCE(t.en_tarifa, FALSE), '
            . 'COALESCE(t.activa, TRUE), COALESCE(t.orden, 0) '
            . 'FROM ' . self::LEGACY_FAMILIES . ' t '
            . 'INNER JOIN ' . self::PRICE_LISTS . ' l ON l.codlista = t.codtarifa';

        return $sql . self::conflictClause($postgres, 'codlista, codfamilia');
    }

    private static function buildArticleCopySql(bool $postgres): string
    {
        $sql = self::insertHead($postgres, self::TARGET_ARTICLES)
            . ' (codlista, referencia, codfamilia, en_tarifa, en_catalogo, orden) '
            . 'SELECT t.codtarifa, t.referencia, t.codfamilia, '
            . 'COALESCE(t.en_tarifa, TRUE), COALESCE(t.en_catalogo, TRUE), '
            . 'COALESCE(t.orden, 0) '
            . 'FROM ' . self::LEGACY_ARTICLES . ' t '
            . 'INNER JOIN ' . self::PRICE_LISTS . ' l ON l.codlista = t.codtarifa';

        return $sql . self::conflictClause($postgres, 'codlista, referencia');
    }

    private static function insertHead(bool $postgres, string $target): string
    {
        return ($postgres ? 'INSERT INTO ' : 'INSERT IGNORE INTO ') . $target;
    }

    private static function conflictClause(bool $postgres, string $keys): string
    {
        return $postgres ? ' ON CONFLICT (' . $keys . ') DO NOTHING;' : ';';
    }

    /**
     * Counts legacy rows whose `codtarifa` has no matching price list. Those
     * rows are skipped by the inner join and reported once after the copies.
     */
    private static function countOrphans(\fs_db2 $db, string $source): int
    {
        $total = $db->select('SELECT COUNT(*) AS total FROM ' . $source . ';');
        if (!$total) {
            return 0;
        }

        $matched = $db->select(
            'SELECT COUNT(*) AS total FROM ' . $source . ' t '
            . 'INNER JOIN ' . self::PRICE_LISTS . ' l ON l.codlista = t.codtarifa;'
        );
        if (!$matched) {
            return (int) ($total[0]['total'] ?? 0);
        }

        return max(0, (int) ($total[0]['total'] ?? 0) - (int) ($matched[0]['total'] ?? 0));
    }

    private static function tableExists(\fs_db2 $db, string $table, bool $postgres): bool
    {
        if ($postgres) {
            $data = $db->select(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = "
                . $db->var2str($table) . ' LIMIT 1;'
            );
        } else {
            $data = $db->select('SHOW TABLES LIKE ' . $db->var2str($table) . ';');
        }

        return (bool) $data;
    }

    private static function isPostgres(?string $dialect): bool
    {
        if ($dialect !== null) {
            return strtolower($dialect) === 'postgresql' || strtolower($dialect) === 'postgres';
        }

        return defined('FS_DB_TYPE') && strtolower((string) FS_DB_TYPE) === 'postgresql';
    }
}
