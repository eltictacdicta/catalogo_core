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
 * One-way copy of the legacy per-tariff family structure into the canonical
 * per-price-list tables (familias-jerarquia, D3 / R-FJ-005).
 *
 * `tarif_tarifa_familia` → `catalogo_familia_estructura` and
 * `tarif_tarifa_articulo` → `catalogo_articulo_familia_estructura`, renaming
 * `codtarifa` to `codlista` (multitarifa D7) and retargeting the list FK.
 *
 * Guarantees:
 * - **FK order**: families are copied before article assignments.
 * - **Idempotent**: engine-native "do nothing" on PK conflict
 *   (`ON CONFLICT (...) DO NOTHING` on PostgreSQL, `INSERT IGNORE` on MySQL),
 *   so a re-run copies zero rows and never alters an existing row.
 * - **Non-destructive**: no `DELETE`, `DROP` or `UPDATE`, ever. The legacy
 *   `tarif_*` tables stay the property of their plugin and are read only.
 * - **Orphan-safe**: the inner join against `catalogo_listas_precio` skips
 *   legacy rows whose `codtarifa` was never promoted to a price list, and the
 *   skipped count is written to the error log.
 * - **Per-step transactions**: each copy commits on its own; a failing step is
 *   rolled back, logged, and the following step is still attempted (a partial
 *   run is resumable because the copy is idempotent).
 *
 * It is a no-op when the legacy table is absent (fresh install, standalone
 * catalogo_core) — the canonical tables simply install empty.
 */
final class FamiliaEstructuraMigration
{
    private const LEGACY_FAMILIA_TABLE = 'tarif_tarifa_familia';

    private const LEGACY_ARTICULO_TABLE = 'tarif_tarifa_articulo';

    private const FAMILIA_TABLE = 'catalogo_familia_estructura';

    private const ARTICULO_TABLE = 'catalogo_articulo_familia_estructura';

    private const LIST_TABLE = 'catalogo_listas_precio';

    private const LOG_PREFIX = '[catalogo_core] familia-estructura migration: ';

    public static function migrateIfNeeded(\fs_db2 $db): void
    {
        if (!self::tableExists($db, self::LEGACY_FAMILIA_TABLE)) {
            return;
        }

        self::runStep($db, 'familia', static function () use ($db): void {
            self::copyFamilies($db);
        });

        self::runStep($db, 'articulo', static function () use ($db): void {
            self::copyAssignments($db);
        });
    }

    /**
     * Set-based `INSERT ... SELECT` for the family structure, verbatim except
     * for the `codtarifa` → `codlista` rename (D2).
     *
     * Exposed so the dual-engine SQL contract is testable without a live
     * PostgreSQL server.
     *
     * @internal
     */
    public static function familiesInsertSql(bool $postgres): string
    {
        $columns = '(codlista, codfamilia, madre, capitulo, nivel, en_catalogo, en_tarifa, activa, orden)';
        $select = 'SELECT t.codtarifa, t.codfamilia, t.madre, t.capitulo, t.nivel, '
            . 't.en_catalogo, t.en_tarifa, t.activa, t.orden '
            . 'FROM ' . self::LEGACY_FAMILIA_TABLE . ' t '
            . 'INNER JOIN ' . self::LIST_TABLE . ' l ON l.codlista = t.codtarifa';

        return $postgres
            ? 'INSERT INTO ' . self::FAMILIA_TABLE . ' ' . $columns . ' ' . $select
                . ' ON CONFLICT (codlista, codfamilia) DO NOTHING;'
            : 'INSERT IGNORE INTO ' . self::FAMILIA_TABLE . ' ' . $columns . ' ' . $select . ';';
    }

    /**
     * Set-based `INSERT ... SELECT` for the article assignment, verbatim
     * (`codfamilia` may be NULL and carries no FK — legacy parity, D2).
     *
     * @internal
     */
    public static function articlesInsertSql(bool $postgres): string
    {
        $columns = '(codlista, referencia, codfamilia, en_tarifa, en_catalogo, orden)';
        $select = 'SELECT t.codtarifa, t.referencia, t.codfamilia, t.en_tarifa, t.en_catalogo, t.orden '
            . 'FROM ' . self::LEGACY_ARTICULO_TABLE . ' t '
            . 'INNER JOIN ' . self::LIST_TABLE . ' l ON l.codlista = t.codtarifa';

        return $postgres
            ? 'INSERT INTO ' . self::ARTICULO_TABLE . ' ' . $columns . ' ' . $select
                . ' ON CONFLICT (codlista, referencia) DO NOTHING;'
            : 'INSERT IGNORE INTO ' . self::ARTICULO_TABLE . ' ' . $columns . ' ' . $select . ';';
    }

    private static function copyFamilies(\fs_db2 $db): void
    {
        if (!self::tableExists($db, self::FAMILIA_TABLE) || !self::tableExists($db, self::LIST_TABLE)) {
            return;
        }

        self::logOrphans(self::countListOrphans($db, self::LEGACY_FAMILIA_TABLE), '(unmatched codlista)');

        $db->exec(self::familiesInsertSql(self::isPostgres($db)), false);
    }

    private static function copyAssignments(\fs_db2 $db): void
    {
        if (!self::tableExists($db, self::ARTICULO_TABLE) || !self::tableExists($db, self::LIST_TABLE)) {
            return;
        }

        self::logOrphans(self::countListOrphans($db, self::LEGACY_ARTICULO_TABLE), '(unmatched codlista)');
        self::logOrphans(
            self::countAssignmentOrphans($db),
            '(no family structure row for list)'
        );

        $db->exec(self::articlesInsertSql(self::isPostgres($db)), false);
    }

    /**
     * Runs one copy step inside its own transaction. A failure rolls the step
     * back and is logged; the caller keeps going with the remaining steps.
     *
     * `exec(..., false)` disables fs_db2's per-statement auto transaction so
     * the step transaction opened here is the one that commits or rolls back.
     */
    private static function runStep(\fs_db2 $db, string $step, callable $copy): void
    {
        $began = false;

        try {
            $began = (bool) $db->begin_transaction();
            $copy();

            if ($began) {
                $db->commit();
            }
        } catch (\Throwable $e) {
            if ($began) {
                try {
                    $db->rollback();
                } catch (\Throwable $rollbackError) {
                    error_log(
                        '[catalogo_core] familia-estructura migration rollback failed for step '
                        . $step . ': ' . $rollbackError->getMessage()
                    );
                }
            }

            error_log(
                '[catalogo_core] familia-estructura migration step ' . $step . ' failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Legacy rows whose `codtarifa` has no canonical price list. They are
     * skipped by the inner join of every insert.
     */
    private static function countListOrphans(\fs_db2 $db, string $legacyTable): int
    {
        $data = $db->select(
            'SELECT COUNT(*) AS total FROM ' . $legacyTable . ' t '
            . 'WHERE NOT EXISTS (SELECT 1 FROM ' . self::LIST_TABLE . ' l WHERE l.codlista = t.codtarifa);'
        );

        return self::intFromCount($data);
    }

    /**
     * Assignment rows that carry a `codfamilia` with no migrated structure row
     * for the same list. They are copied anyway (legacy has no FK there, D2)
     * but counted so the divergence is visible in the log.
     */
    private static function countAssignmentOrphans(\fs_db2 $db): int
    {
        if (!self::tableExists($db, self::FAMILIA_TABLE)) {
            return 0;
        }

        $data = $db->select(
            'SELECT COUNT(*) AS total FROM ' . self::LEGACY_ARTICULO_TABLE . ' t '
            . 'INNER JOIN ' . self::LIST_TABLE . ' l ON l.codlista = t.codtarifa '
            . 'WHERE t.codfamilia IS NOT NULL AND NOT EXISTS ('
            . 'SELECT 1 FROM ' . self::FAMILIA_TABLE . ' e '
            . 'WHERE e.codlista = l.codlista AND e.codfamilia = t.codfamilia);'
        );

        return self::intFromCount($data);
    }

    /**
     * @param array<int, array<string, mixed>>|false $data
     */
    private static function intFromCount($data): int
    {
        if (!is_array($data) || !isset($data[0]['total'])) {
            return 0;
        }

        return (int) $data[0]['total'];
    }

    private static function logOrphans(int $count, string $reason): void
    {
        if ($count <= 0) {
            return;
        }

        error_log(self::LOG_PREFIX . $count . ' orphan rows skipped ' . $reason);
    }

    private static function tableExists(\fs_db2 $db, string $table): bool
    {
        if (self::isPostgres($db)) {
            $data = $db->select(
                "SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = "
                . $db->var2str($table) . ' LIMIT 1;'
            );
        } else {
            $data = $db->select('SHOW TABLES LIKE ' . $db->var2str($table) . ';');
        }

        return (bool) $data;
    }

    private static function isPostgres(\fs_db2 $db): bool
    {
        return defined('FS_DB_TYPE') && FS_DB_TYPE === 'postgresql';
    }
}
