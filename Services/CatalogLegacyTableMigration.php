<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

/**
 * Migra tablas legacy del plugin tarifario a los nombres canónicos del catálogo.
 */
final class CatalogLegacyTableMigration
{
    /** @var array<string, string> */
    private const TABLE_RENAMES = [
        'tarif_idiomas' => 'catalogo_idiomas',
        'tarif_descripciones' => 'articulo_descripciones',
        'tarif_opcionales' => 'catalogo_opcionales',
        'tarif_opcional_familia' => 'catalogo_opcional_familias',
        'tarif_articulo_opcional' => 'catalogo_articulo_opcional',
    ];

    public static function migrateIfNeeded(\fs_db2 $db): void
    {
        foreach (self::TABLE_RENAMES as $legacy => $target) {
            self::renameTableIfNeeded($db, $legacy, $target);
        }

        self::migrateOptionalPrices($db);
        self::syncDefaultPriceListFromTarifario($db);
        self::syncOptionalPricingColumns($db);
        self::syncOptionalGroupColumns($db);
        self::syncArticuloOpcionalGrupoTable($db);
        self::migrateGroupedOptionalAssignments($db);
        self::syncObligatorioColumns($db);
    }

    private static function syncObligatorioColumns(\fs_db2 $db): void
    {
        if (self::tableExists($db, 'catalogo_articulo_opcional')) {
            self::addColumnIfMissing(
                $db,
                'catalogo_articulo_opcional',
                'obligatorio',
                self::isPostgres($db) ? 'boolean NOT NULL DEFAULT FALSE' : 'TINYINT(1) NOT NULL DEFAULT 0'
            );
        }

        if (self::tableExists($db, 'catalogo_articulo_opcional_grupo')) {
            self::addColumnIfMissing(
                $db,
                'catalogo_articulo_opcional_grupo',
                'obligatorio',
                self::isPostgres($db) ? 'boolean NOT NULL DEFAULT FALSE' : 'TINYINT(1) NOT NULL DEFAULT 0'
            );
        }
    }

    private static function syncArticuloOpcionalGrupoTable(\fs_db2 $db): void
    {
        if (self::tableExists($db, 'catalogo_articulo_opcional_grupo')) {
            return;
        }

        if (self::isPostgres($db)) {
            $db->exec(
                'CREATE TABLE catalogo_articulo_opcional_grupo ('
                . 'id serial NOT NULL,'
                . 'referencia character varying(18) NOT NULL,'
                . 'id_grupo integer NOT NULL,'
                . 'obligatorio boolean NOT NULL DEFAULT FALSE,'
                . 'PRIMARY KEY (id),'
                . 'CONSTRAINT catalogo_articulo_opcional_grupo_unique UNIQUE (referencia, id_grupo)'
                . ');'
            );
        } else {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS catalogo_articulo_opcional_grupo ('
                . 'id INT NOT NULL AUTO_INCREMENT,'
                . 'referencia VARCHAR(18) NOT NULL,'
                . 'id_grupo INT NOT NULL,'
                . 'obligatorio TINYINT(1) NOT NULL DEFAULT 0,'
                . 'PRIMARY KEY (id),'
                . 'UNIQUE KEY catalogo_articulo_opcional_grupo_unique (referencia, id_grupo)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;'
            );
        }

        if (!self::tableExists($db, 'catalogo_articulo_opcional_grupo')) {
            require_once FS_FOLDER . '/base/fs_model.php';
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional_grupo.php';
            new \FSFramework\model\catalogo_articulo_opcional_grupo();
        }
    }

    /**
     * Convierte asignaciones directas artículo↔opcional agrupado en artículo↔grupo
     * y elimina relaciones directas inválidas.
     */
    private static function migrateGroupedOptionalAssignments(\fs_db2 $db): void
    {
        if (!self::tableExists($db, 'catalogo_articulo_opcional')
            || !self::tableExists($db, 'catalogo_opcionales')
            || !self::tableExists($db, 'catalogo_articulo_opcional_grupo')) {
            return;
        }

        if (self::isPostgres($db)) {
            $db->exec(
                'INSERT INTO catalogo_articulo_opcional_grupo (referencia, id_grupo) '
                . 'SELECT DISTINCT ao.referencia, o.id_grupo '
                . 'FROM catalogo_articulo_opcional ao '
                . 'INNER JOIN catalogo_opcionales o ON o.id = ao.id_opcional '
                . 'WHERE o.id_grupo IS NOT NULL AND o.id_grupo > 0 '
                . 'ON CONFLICT (referencia, id_grupo) DO NOTHING;'
            );
        } else {
            $db->exec(
                'INSERT IGNORE INTO catalogo_articulo_opcional_grupo (referencia, id_grupo) '
                . 'SELECT DISTINCT ao.referencia, o.id_grupo '
                . 'FROM catalogo_articulo_opcional ao '
                . 'INNER JOIN catalogo_opcionales o ON o.id = ao.id_opcional '
                . 'WHERE o.id_grupo IS NOT NULL AND o.id_grupo > 0;'
            );
        }

        if (self::isPostgres($db)) {
            $db->exec(
                'DELETE FROM catalogo_articulo_opcional ao '
                . 'USING catalogo_opcionales o '
                . 'WHERE ao.id_opcional = o.id '
                . 'AND o.id_grupo IS NOT NULL AND o.id_grupo > 0;'
            );
        } else {
            $db->exec(
                'DELETE ao FROM catalogo_articulo_opcional ao '
                . 'INNER JOIN catalogo_opcionales o ON o.id = ao.id_opcional '
                . 'WHERE o.id_grupo IS NOT NULL AND o.id_grupo > 0;'
            );
        }
    }

    /**
     * Crea la tabla de grupos y añade id_grupo a opcionales en instalaciones existentes.
     */
    private static function syncOptionalGroupColumns(\fs_db2 $db): void
    {
        if (!self::tableExists($db, 'catalogo_opcional_grupos')) {
            require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php';
            new \FSFramework\model\catalogo_opcional_grupo();
        }

        if (self::tableExists($db, 'catalogo_opcionales')) {
            $changed = self::addColumnIfMissing(
                $db,
                'catalogo_opcionales',
                'id_grupo',
                self::isPostgres($db) ? 'integer NULL' : 'INT NULL'
            );

            if ($changed) {
                self::invalidateCheckedTableCache('catalogo_opcionales');
            }
        }
    }

    /**
     * Añade columnas de precio por porcentaje en instalaciones ya existentes.
     * fs_model puede omitir compare_columns si la tabla está en fs_checked_tables.
     */
    private static function syncOptionalPricingColumns(\fs_db2 $db): void
    {
        if (self::tableExists($db, 'catalogo_opcionales')) {
            $changed = false;
            $changed = self::addColumnIfMissing(
                $db,
                'catalogo_opcionales',
                'tipo_precio',
                self::isPostgres($db)
                    ? "character varying(20) NOT NULL DEFAULT 'fijo'"
                    : "VARCHAR(20) NOT NULL DEFAULT 'fijo'"
            ) || $changed;
            $changed = self::addColumnIfMissing(
                $db,
                'catalogo_opcionales',
                'porcentaje',
                self::isPostgres($db) ? 'double precision NULL' : 'DOUBLE NULL'
            ) || $changed;

            if ($changed) {
                self::invalidateCheckedTableCache('catalogo_opcionales');
            }
        }

        if (self::tableExists($db, 'catalogo_opcional_precios')) {
            $changed = self::addColumnIfMissing(
                $db,
                'catalogo_opcional_precios',
                'porcentaje',
                self::isPostgres($db) ? 'double precision NULL' : 'DOUBLE NULL'
            );

            if ($changed) {
                self::invalidateCheckedTableCache('catalogo_opcional_precios');
            }
        }
    }

    private static function addColumnIfMissing(\fs_db2 $db, string $table, string $column, string $definition): bool
    {
        if (self::columnExists($db, $table, $column)) {
            return false;
        }

        $sql = self::isPostgres($db)
            ? 'ALTER TABLE ' . $table . ' ADD COLUMN "' . $column . '" ' . $definition . ';'
            : 'ALTER TABLE `' . $table . '` ADD `' . $column . '` ' . $definition . ';';

        return (bool) $db->exec($sql);
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

    private static function invalidateCheckedTableCache(string $table): void
    {
        try {
            require_once FS_FOLDER . '/base/fs_cache.php';
            $cache = new \fs_cache();
            $checked = $cache->get_array('fs_checked_tables');
            if (!is_array($checked) || !in_array($table, $checked, true)) {
                return;
            }

            $checked = array_values(array_filter(
                $checked,
                static fn (string $name): bool => $name !== $table
            ));
            $cache->set('fs_checked_tables', $checked, 5400);
        } catch (\Throwable $e) {
            error_log('[catalogo_core] Could not invalidate fs_checked_tables for ' . $table . ': ' . $e->getMessage());
        }
    }

    private static function renameTableIfNeeded(\fs_db2 $db, string $legacy, string $target): void
    {
        if (!self::tableExists($db, $legacy) || self::tableExists($db, $target)) {
            return;
        }

        $sql = self::isPostgres($db)
            ? 'ALTER TABLE ' . $legacy . ' RENAME TO ' . $target . ';'
            : 'RENAME TABLE `' . $legacy . '` TO `' . $target . '`;';

        $db->exec($sql);
    }

    private static function migrateOptionalPrices(\fs_db2 $db): void
    {
        if (!self::tableExists($db, 'tarif_opcional_precios')
            || self::tableExists($db, 'catalogo_opcional_precios')) {
            return;
        }

        if (!self::tableExists($db, 'catalogo_listas_precio')) {
            new \FSFramework\model\catalogo_lista_precio();
        }

        if (self::isPostgres($db)) {
            $db->exec(
                'CREATE TABLE catalogo_opcional_precios AS '
                . 'SELECT id_opcional, codtarifa AS codlista, precio, en_catalogo '
                . 'FROM tarif_opcional_precios;'
            );
            $db->exec('ALTER TABLE catalogo_opcional_precios ADD PRIMARY KEY (id_opcional, codlista);');
        } else {
            $db->exec(
                'CREATE TABLE catalogo_opcional_precios '
                . 'SELECT id_opcional, codtarifa AS codlista, precio, en_catalogo '
                . 'FROM tarif_opcional_precios;'
            );
            $db->exec('ALTER TABLE catalogo_opcional_precios ADD PRIMARY KEY (id_opcional, codlista);');
        }
    }

    private static function syncDefaultPriceListFromTarifario(\fs_db2 $db): void
    {
        if (!self::tableExists($db, 'catalogo_listas_precio')
            || !self::tableExists($db, 'tarif_tarifas')) {
            return;
        }

        $defaultTarif = $db->select(
            'SELECT codtarifa, nombre, activa, por_defecto, coddivisa '
            . 'FROM tarif_tarifas WHERE por_defecto = TRUE LIMIT 1;'
        );

        if (!$defaultTarif) {
            return;
        }

        $row = $defaultTarif[0];
        $codlista = $row['codtarifa'];
        $exists = $db->select(
            'SELECT codlista FROM catalogo_listas_precio WHERE codlista = '
            . $db->var2str($codlista) . ' LIMIT 1;'
        );

        if ($exists) {
            return;
        }

        $db->exec(
            'INSERT INTO catalogo_listas_precio (codlista, nombre, activa, por_defecto, coddivisa) VALUES ('
            . $db->var2str($codlista) . ','
            . $db->var2str($row['nombre']) . ','
            . $db->var2str($row['activa']) . ','
            . $db->var2str($row['por_defecto']) . ','
            . $db->var2str($row['coddivisa'] ?? 'EUR') . ');'
        );
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
