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

    /**
     * Column maps for the additive copy performed when legacy and canonical
     * tables coexist (the rename is impossible then). Rows are copied
     * preserving their primary id; rows already present are skipped.
     *
     * `select` answers to the target `columns` positionally, so it can carry
     * literals such as the default `obligatorio = 0`.
     *
     * @var array<string, array{columns: list<string>, select: list<string>}>
     */
    private const BOTH_EXIST_COPY = [
        'tarif_opcionales' => [
            'columns' => ['id', 'codigo', 'nombre', 'descripcion', 'precio', 'activo'],
            'select' => ['id', 'codigo', 'nombre', 'descripcion', 'precio', 'activo'],
        ],
        'tarif_opcional_familia' => [
            'columns' => ['id', 'id_opcional', 'codfamilia'],
            'select' => ['id', 'id_opcional', 'codfamilia'],
        ],
        'tarif_articulo_opcional' => [
            'columns' => ['id', 'referencia', 'id_opcional', 'obligatorio'],
            'select' => ['id', 'referencia', 'id_opcional', '0'],
        ],
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
        self::purgeOrphanDescriptions($db);
    }

    /**
     * Removes description rows whose `codidioma` has no registry row.
     *
     * Defensive, idempotent and flag-free (D-01): a cheap LEFT JOIN pre-check
     * keeps steady state at a single `LIMIT 1` read, mirroring
     * hasPendingRows()/copyPendingPrices(). Deleting a language is already
     * handled application-side in catalogo_idioma::delete(); this only cleans up
     * rows orphaned by a removal that predates that guard.
     */
    private static function purgeOrphanDescriptions(\fs_db2 $db): void
    {
        if (!self::tableExists($db, 'articulo_descripciones') || !self::tableExists($db, 'catalogo_idiomas')) {
            return;
        }

        $pending = $db->select(
            'SELECT 1 FROM articulo_descripciones d LEFT JOIN catalogo_idiomas i '
            . 'ON i.codidioma = d.codidioma WHERE i.codidioma IS NULL LIMIT 1;'
        );
        if (!$pending) {
            return;
        }

        if (self::isPostgres($db)) {
            $db->exec(
                'DELETE FROM articulo_descripciones d WHERE NOT EXISTS '
                . '(SELECT 1 FROM catalogo_idiomas i WHERE i.codidioma = d.codidioma);'
            );
        } else {
            $db->exec(
                'DELETE d FROM articulo_descripciones d LEFT JOIN catalogo_idiomas i '
                . 'ON i.codidioma = d.codidioma WHERE i.codidioma IS NULL;'
            );
        }
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
        if (!self::tableExists($db, $legacy)) {
            return;
        }

        if (!self::tableExists($db, $target)) {
            $sql = self::isPostgres($db)
                ? 'ALTER TABLE ' . $legacy . ' RENAME TO ' . $target . ';'
                : 'RENAME TABLE `' . $legacy . '` TO `' . $target . '`;';

            $db->exec($sql);
            return;
        }

        // Both tables exist: a rename is impossible but the legacy table may
        // still hold rows created before the canonical table was provisioned.
        // Copy them additively, preserving ids, and leave legacy data untouched.
        self::copyPendingRows($db, $legacy, $target);
    }

    /**
     * Idempotent legacy → canonical copy for the both-tables-exist case.
     *
     * Only tables with an explicit column map are copied; unmapped renames are
     * left as-is to avoid guessing schemas. A cheap LEFT JOIN pre-check keeps
     * the per-request boot path free of bulk statements in steady state.
     */
    private static function copyPendingRows(\fs_db2 $db, string $legacy, string $target): void
    {
        if (!isset(self::BOTH_EXIST_COPY[$legacy])) {
            return;
        }

        if (!self::hasPendingRows($db, $legacy, $target)) {
            return;
        }

        $map = self::BOTH_EXIST_COPY[$legacy];
        $columns = implode(', ', $map['columns']);
        $select = implode(', ', $map['select']);

        if (self::isPostgres($db)) {
            $db->exec(
                'INSERT INTO ' . $target . ' (' . $columns . ') '
                . 'SELECT ' . $select . ' FROM ' . $legacy . ' '
                . 'ON CONFLICT DO NOTHING;'
            );

            // Rows copied with an explicit id do not advance the target
            // identity sequence, so realign it to MAX(id) to keep later
            // inserts from colliding with the migrated rows.
            $db->exec(
                'SELECT setval(pg_get_serial_sequence(' . $db->var2str($target) . ", 'id'), "
                . 'COALESCE((SELECT MAX(id) FROM ' . $target . '), 1), true);'
            );
            return;
        }

        $db->exec(
            'INSERT IGNORE INTO ' . $target . ' (' . $columns . ') '
            . 'SELECT ' . $select . ' FROM ' . $legacy . ';'
        );
    }

    private static function hasPendingRows(\fs_db2 $db, string $legacy, string $target): bool
    {
        $data = $db->select(
            'SELECT 1 FROM ' . $legacy . ' l '
            . 'LEFT JOIN ' . $target . ' t ON t.id = l.id '
            . 'WHERE t.id IS NULL LIMIT 1;'
        );

        return (bool) $data;
    }

    private static function migrateOptionalPrices(\fs_db2 $db): void
    {
        if (!self::tableExists($db, 'tarif_opcional_precios')) {
            return;
        }

        $targetExists = self::tableExists($db, 'catalogo_opcional_precios');

        if (!$targetExists && !self::tableExists($db, 'catalogo_listas_precio')) {
            new \FSFramework\model\catalogo_lista_precio();
        }

        // The canonical prices table has FKs on (id_opcional, codlista); make
        // sure every referenced price list exists before inserting rows.
        self::syncMissingPriceLists($db);

        if (!$targetExists) {
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

            return;
        }

        // Both tables exist: copy the pending legacy prices additively, leaving
        // porcentaje NULL so the canonical pricing mode keeps its defaults.
        self::copyPendingOptionalPrices($db);
    }

    private static function copyPendingOptionalPrices(\fs_db2 $db): void
    {
        $pending = $db->select(
            'SELECT 1 FROM tarif_opcional_precios p '
            . 'LEFT JOIN catalogo_opcional_precios c '
            . 'ON c.id_opcional = p.id_opcional AND c.codlista = p.codtarifa '
            . 'WHERE c.id_opcional IS NULL LIMIT 1;'
        );

        if (!$pending) {
            return;
        }

        if (self::isPostgres($db)) {
            $db->exec(
                'INSERT INTO catalogo_opcional_precios (id_opcional, codlista, precio, en_catalogo) '
                . 'SELECT p.id_opcional, p.codtarifa, p.precio, COALESCE(p.en_catalogo, FALSE) '
                . 'FROM tarif_opcional_precios p '
                . 'ON CONFLICT (id_opcional, codlista) DO NOTHING;'
            );
            return;
        }

        $db->exec(
            'INSERT IGNORE INTO catalogo_opcional_precios (id_opcional, codlista, precio, en_catalogo) '
            . 'SELECT p.id_opcional, p.codtarifa, p.precio, COALESCE(p.en_catalogo, 0) '
            . 'FROM tarif_opcional_precios p;'
        );
    }

    /**
     * Ensures every tarifa that can be referenced by legacy optional prices has
     * a matching catalogo_listas_precio row, so the FK on codlista can be added.
     * Rows come from tarif_tarifas when available; orphan codtarifa values are
     * synthesized so the copy is never blocked by a missing list.
     */
    private static function syncMissingPriceLists(\fs_db2 $db): void
    {
        if (!self::tableExists($db, 'catalogo_listas_precio')
            || !self::tableExists($db, 'tarif_opcional_precios')) {
            return;
        }

        // Cheap pre-check: only write when a referenced list is actually missing.
        $pending = $db->select(
            'SELECT 1 FROM tarif_opcional_precios p '
            . 'LEFT JOIN catalogo_listas_precio l ON l.codlista = p.codtarifa '
            . 'WHERE l.codlista IS NULL LIMIT 1;'
        );

        if (!$pending) {
            return;
        }

        if (self::tableExists($db, 'tarif_tarifas')) {
            if (self::isPostgres($db)) {
                $db->exec(
                    'INSERT INTO catalogo_listas_precio (codlista, nombre, activa, por_defecto, coddivisa) '
                    . 'SELECT t.codtarifa, COALESCE(NULLIF(t.nombre, \'\'), t.codtarifa), '
                    . 'COALESCE(t.activa, FALSE), COALESCE(t.por_defecto, FALSE), '
                    . 'COALESCE(NULLIF(t.coddivisa, \'\'), \'EUR\') '
                    . 'FROM tarif_tarifas t '
                    . 'WHERE NOT EXISTS (SELECT 1 FROM catalogo_listas_precio l WHERE l.codlista = t.codtarifa) '
                    . 'ON CONFLICT (codlista) DO NOTHING;'
                );
            } else {
                $db->exec(
                    'INSERT IGNORE INTO catalogo_listas_precio (codlista, nombre, activa, por_defecto, coddivisa) '
                    . 'SELECT t.codtarifa, COALESCE(NULLIF(t.nombre, \'\'), t.codtarifa), '
                    . 'COALESCE(t.activa, 0), COALESCE(t.por_defecto, 0), '
                    . 'COALESCE(NULLIF(t.coddivisa, \'\'), \'EUR\') '
                    . 'FROM tarif_tarifas t '
                    . 'LEFT JOIN catalogo_listas_precio l ON l.codlista = t.codtarifa '
                    . 'WHERE l.codlista IS NULL;'
                );
            }
        }

        if (self::isPostgres($db)) {
            $db->exec(
                'INSERT INTO catalogo_listas_precio (codlista, nombre, activa, por_defecto, coddivisa) '
                . 'SELECT DISTINCT p.codtarifa, p.codtarifa, FALSE, FALSE, \'EUR\' '
                . 'FROM tarif_opcional_precios p '
                . 'WHERE NOT EXISTS (SELECT 1 FROM catalogo_listas_precio l WHERE l.codlista = p.codtarifa) '
                . 'ON CONFLICT (codlista) DO NOTHING;'
            );
        } else {
            $db->exec(
                'INSERT IGNORE INTO catalogo_listas_precio (codlista, nombre, activa, por_defecto, coddivisa) '
                . 'SELECT DISTINCT p.codtarifa, p.codtarifa, 0, 0, \'EUR\' '
                . 'FROM tarif_opcional_precios p '
                . 'LEFT JOIN catalogo_listas_precio l ON l.codlista = p.codtarifa '
                . 'WHERE l.codlista IS NULL;'
            );
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
