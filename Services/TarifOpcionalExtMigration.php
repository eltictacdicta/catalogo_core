<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Migra ref_sap, en_catalogo y en_tarifa desde columnas legacy de opcionales
 * hacia tarif_opcional_ext. Retrocompatible con catalogo_opcionales y tarif_opcionales.
 */
final class TarifOpcionalExtMigration
{
    public static function migrateIfNeeded(\fs_db2 $db): void
    {
        self::ensureExtTable($db);

        $sourceTable = self::resolveSourceTable($db);
        if ($sourceTable === null || !self::tableExists($db, 'tarif_opcional_ext')) {
            return;
        }

        // Cheap pre-check: skip the bulk INSERT..SELECT when every legacy row
        // already has its extension row (the steady state after migration).
        if (!self::hasPendingLegacyRows($db, $sourceTable)) {
            return;
        }

        self::copyLegacyRows($db, $sourceTable);
    }

    private static function hasPendingLegacyRows(\fs_db2 $db, string $sourceTable): bool
    {
        $data = $db->select(
            'SELECT 1 FROM ' . $sourceTable . ' o '
            . 'LEFT JOIN tarif_opcional_ext e ON e.id_opcional = o.id '
            . 'WHERE e.id_opcional IS NULL LIMIT 1;'
        );

        return (bool) $data;
    }

    private static function ensureExtTable(\fs_db2 $db): void
    {
        if (self::tableExists($db, 'tarif_opcional_ext')) {
            return;
        }

        require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_opcional_ext.php';
        new \FSFramework\model\tarif_opcional_ext();
    }

    private static function resolveSourceTable(\fs_db2 $db): ?string
    {
        if (self::tableExists($db, 'catalogo_opcionales')) {
            return 'catalogo_opcionales';
        }

        if (self::tableExists($db, 'tarif_opcionales')) {
            return 'tarif_opcionales';
        }

        return null;
    }

    private static function copyLegacyRows(\fs_db2 $db, string $sourceTable): void
    {
        $refExpr = self::buildRefExpression($db, $sourceTable);
        $enCatalogoExpr = self::columnExists($db, $sourceTable, 'en_catalogo')
            ? 'COALESCE(o.en_catalogo, FALSE)'
            : 'FALSE';
        $enTarifaExpr = self::columnExists($db, $sourceTable, 'en_tarifa')
            ? 'COALESCE(o.en_tarifa, FALSE)'
            : 'FALSE';

        if (self::isPostgres($db)) {
            $db->exec(
                'INSERT INTO tarif_opcional_ext (id_opcional, ref_sap, en_catalogo, en_tarifa) '
                . 'SELECT o.id, ' . $refExpr . ', ' . $enCatalogoExpr . ', ' . $enTarifaExpr . ' '
                . 'FROM ' . $sourceTable . ' o '
                . 'WHERE NOT EXISTS ('
                . 'SELECT 1 FROM tarif_opcional_ext e WHERE e.id_opcional = o.id'
                . ') '
                . 'ON CONFLICT (id_opcional) DO NOTHING;'
            );

            return;
        }

        $db->exec(
            'INSERT IGNORE INTO tarif_opcional_ext (id_opcional, ref_sap, en_catalogo, en_tarifa) '
            . 'SELECT o.id, ' . $refExpr . ', ' . $enCatalogoExpr . ', ' . $enTarifaExpr . ' '
            . 'FROM ' . $sourceTable . ' o '
            . 'LEFT JOIN tarif_opcional_ext e ON e.id_opcional = o.id '
            . 'WHERE e.id_opcional IS NULL;'
        );
    }

    private static function buildRefExpression(\fs_db2 $db, string $sourceTable): string
    {
        $hasRefSap = self::columnExists($db, $sourceTable, 'ref_sap');
        $hasCodigo2 = self::columnExists($db, $sourceTable, 'codigo2');

        if ($hasRefSap && $hasCodigo2) {
            return 'NULLIF(TRIM(COALESCE(o.ref_sap, o.codigo2, \'\')), \'\')';
        }

        if ($hasRefSap) {
            return 'NULLIF(TRIM(COALESCE(o.ref_sap, \'\')), \'\')';
        }

        if ($hasCodigo2) {
            return 'NULLIF(TRIM(COALESCE(o.codigo2, \'\')), \'\')';
        }

        return 'NULL';
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

    private static function columnExists(\fs_db2 $db, string $table, string $column): bool
    {
        if (self::isPostgres($db)) {
            $data = $db->select(
                'SELECT 1 FROM information_schema.columns '
                . "WHERE table_schema = 'public' AND table_name = " . $db->var2str($table)
                . ' AND column_name = ' . $db->var2str($column) . ' LIMIT 1;'
            );
        } else {
            $data = $db->select(
                'SHOW COLUMNS FROM `' . $table . '` LIKE ' . $db->var2str($column) . ';'
            );
        }

        return (bool) $data;
    }

    private static function isPostgres(\fs_db2 $db): bool
    {
        return defined('FS_DB_TYPE') && FS_DB_TYPE === 'postgresql';
    }
}
