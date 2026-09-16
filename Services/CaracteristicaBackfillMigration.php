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
 * Idempotent supersession backfill (CAR-13).
 *
 * Maps the legacy `en_catalogo`/`en_tarifa` visibility columns into the
 * feature value tables so the resolver becomes the single source of truth.
 * Every statement is an `INSERT ... WHERE NOT EXISTS` guarded on the target
 * primary key + `id_caracteristica`, so a double run inserts nothing and an
 * operator edit is never overwritten (never an `UPDATE`).
 *
 * The migration runs from `catalogo_core::Init::init()` and `::upgrade()`, and
 * again from tarifario's boot once its definitions exist. It short-circuits
 * when either definition (or its `'1'`/`'0'` catalog value) is absent, so the
 * cold-boot order cannot corrupt anything.
 *
 * `tarif_familia_ext` is a retired read-only surface without visibility
 * columns (design §8.6 divergence 1): its clause is introspection-guarded and
 * therefore a no-op on the live schema.
 */
final class CaracteristicaBackfillMigration
{
    private const FLAG_CODIGOS = ['en_catalogo', 'en_tarifa'];

    private const DEF_TARIFA = 'DEF';

    public static function migrateIfNeeded(\fs_db2 $db): void
    {
        $flags = self::flag_definitions($db);
        if ($flags === null) {
            return;
        }

        foreach ($flags as $flag) {
            self::backfill_articulo_precio($db, $flag);
            self::backfill_tarifa_articulo($db, $flag);
            self::backfill_tarifa_familia($db, $flag);
            self::backfill_familia_ext($db, $flag);
            self::backfill_opcional_articulo($db, $flag);
            self::backfill_opcional_familia($db, $flag);
            self::backfill_opcional_global($db, $flag);
        }
    }

    // =====================================================================
    // Legacy surfaces (design §8.2)
    // =====================================================================

    /**
     * (a) `tarif_articulo_precios` → articulo scope, per flag.
     *
     * @param array{codigo: string, id: int, one: int, zero: int} $flag
     */
    private static function backfill_articulo_precio(\fs_db2 $db, array $flag): void
    {
        if (!self::surface_ready($db, 'tarif_articulo_precios', $flag['codigo'])) {
            return;
        }

        self::insert_pending(
            $db,
            'catalogo_caracteristica_articulo',
            'codtarifa, referencia, id_caracteristica, id_valor, valor, custom',
            'p.codtarifa, p.referencia, ' . $flag['id']
                . ', CASE WHEN p.' . $flag['codigo'] . ' THEN ' . $flag['one']
                . ' ELSE ' . $flag['zero'] . ' END, NULL, 0',
            'tarif_articulo_precios p',
            self::flag_not_null($flag, 'p') . ' AND ' . self::not_exists_articulo($flag, 'p.codtarifa', 'p.referencia')
        );
    }

    /**
     * (b) `tarif_tarifa_articulo` → articulo scope; an existing price row wins.
     *
     * @param array{codigo: string, id: int, one: int, zero: int} $flag
     */
    private static function backfill_tarifa_articulo(\fs_db2 $db, array $flag): void
    {
        if (!self::surface_ready($db, 'tarif_tarifa_articulo', $flag['codigo'])) {
            return;
        }

        $where = self::flag_not_null($flag, 't')
            . ' AND NOT EXISTS (SELECT 1 FROM tarif_articulo_precios pr'
            . ' WHERE pr.referencia = t.referencia AND pr.codtarifa = t.codtarifa)'
            . ' AND ' . self::not_exists_articulo($flag, 't.codtarifa', 't.referencia');

        self::insert_pending(
            $db,
            'catalogo_caracteristica_articulo',
            'codtarifa, referencia, id_caracteristica, id_valor, valor, custom',
            't.codtarifa, t.referencia, ' . $flag['id']
                . ', CASE WHEN t.' . $flag['codigo'] . ' THEN ' . $flag['one']
                . ' ELSE ' . $flag['zero'] . ' END, NULL, 0',
            'tarif_tarifa_articulo t',
            $where
        );
    }

    /**
     * (c) `tarif_tarifa_familia` → familia scope, per flag.
     *
     * @param array{codigo: string, id: int, one: int, zero: int} $flag
     */
    private static function backfill_tarifa_familia(\fs_db2 $db, array $flag): void
    {
        if (!self::surface_ready($db, 'tarif_tarifa_familia', $flag['codigo'])) {
            return;
        }

        $where = self::flag_not_null($flag, 'f')
            . ' AND ' . self::not_exists_familia($flag, 'f.codtarifa', 'f.codfamilia');

        self::insert_pending(
            $db,
            'catalogo_caracteristica_familia',
            'codtarifa, codfamilia, id_caracteristica, id_valor, valor, custom',
            'f.codtarifa, f.codfamilia, ' . $flag['id']
                . ', CASE WHEN f.' . $flag['codigo'] . ' THEN ' . $flag['one']
                . ' ELSE ' . $flag['zero'] . ' END, NULL, 0',
            'tarif_tarifa_familia f',
            $where
        );
    }

    /**
     * (d) `tarif_familia_ext` → `DEF` familia scope.
     *
     * Introspection-guarded no-op on the live schema: the retired read-only
     * table carries only `codfamilia`/`capitulo`/`nivel` (design §8.6
     * divergence 1), so both visibility columns must be probed before the
     * clause is emitted. The family visibility parity is covered by (c) plus
     * the resolver `DEF` fallback.
     *
     * @param array{codigo: string, id: int, one: int, zero: int} $flag
     */
    private static function backfill_familia_ext(\fs_db2 $db, array $flag): void
    {
        if (!self::tableExists($db, 'tarif_familia_ext')) {
            return;
        }

        foreach (self::FLAG_CODIGOS as $required) {
            if (!self::columnExists($db, 'tarif_familia_ext', $required)) {
                return;
            }
        }

        $where = self::flag_not_null($flag, 'e')
            . ' AND ' . self::not_exists_familia($flag, self::quote(self::DEF_TARIFA), 'e.codfamilia');

        self::insert_pending(
            $db,
            'catalogo_caracteristica_familia',
            'codtarifa, codfamilia, id_caracteristica, id_valor, valor, custom',
            self::quote(self::DEF_TARIFA) . ', e.codfamilia, ' . $flag['id']
                . ', CASE WHEN e.' . $flag['codigo'] . ' THEN ' . $flag['one']
                . ' ELSE ' . $flag['zero'] . ' END, NULL, 0',
            'tarif_familia_ext e',
            $where
        );
    }

    /**
     * (e1) Article-attached opcional: seed the parent article at the `DEF`
     * tarifa when no value row exists yet (CAR-13 opcional recovery).
     *
     * @param array{codigo: string, id: int, one: int, zero: int} $flag
     */
    private static function backfill_opcional_articulo(\fs_db2 $db, array $flag): void
    {
        if (!self::tableExists($db, 'catalogo_articulo_opcional')
            || !self::surface_ready($db, 'tarif_opcional_ext', $flag['codigo'])) {
            return;
        }

        $where = self::flag_not_null($flag, 'oe')
            . ' AND ' . self::not_exists_articulo(
                $flag,
                self::quote(self::DEF_TARIFA),
                'ao.referencia'
            );

        self::insert_pending(
            $db,
            'catalogo_caracteristica_articulo',
            'codtarifa, referencia, id_caracteristica, id_valor, valor, custom',
            self::quote(self::DEF_TARIFA) . ', ao.referencia, ' . $flag['id']
                . ', CASE WHEN oe.' . $flag['codigo'] . ' THEN ' . $flag['one']
                . ' ELSE ' . $flag['zero'] . ' END, NULL, 0',
            'catalogo_articulo_opcional ao INNER JOIN tarif_opcional_ext oe'
                . ' ON oe.id_opcional = ao.id_opcional',
            $where
        );
    }

    /**
     * (e2) Family-assigned opcional: seed the assigned family at the `DEF`
     * tarifa.
     *
     * @param array{codigo: string, id: int, one: int, zero: int} $flag
     */
    private static function backfill_opcional_familia(\fs_db2 $db, array $flag): void
    {
        if (!self::tableExists($db, 'catalogo_opcional_familia')
            || !self::surface_ready($db, 'tarif_opcional_ext', $flag['codigo'])) {
            return;
        }

        $where = self::flag_not_null($flag, 'oe')
            . ' AND ' . self::not_exists_familia(
                $flag,
                self::quote(self::DEF_TARIFA),
                'ofam.codfamilia'
            );

        self::insert_pending(
            $db,
            'catalogo_caracteristica_familia',
            'codtarifa, codfamilia, id_caracteristica, id_valor, valor, custom',
            self::quote(self::DEF_TARIFA) . ', ofam.codfamilia, ' . $flag['id']
                . ', CASE WHEN oe.' . $flag['codigo'] . ' THEN ' . $flag['one']
                . ' ELSE ' . $flag['zero'] . ' END, NULL, 0',
            'catalogo_opcional_familia ofam INNER JOIN tarif_opcional_ext oe'
                . ' ON oe.id_opcional = ofam.id_opcional',
            $where
        );
    }

    /**
     * (e3) Fully unassigned opcional: seed the global scope at the `DEF`
     * tarifa so its pre-change visibility survives.
     *
     * @param array{codigo: string, id: int, one: int, zero: int} $flag
     */
    private static function backfill_opcional_global(\fs_db2 $db, array $flag): void
    {
        if (!self::surface_ready($db, 'tarif_opcional_ext', $flag['codigo'])) {
            return;
        }

        $where = self::flag_not_null($flag, 'oe')
            . ' AND NOT EXISTS (SELECT 1 FROM catalogo_articulo_opcional ao'
            . ' WHERE ao.id_opcional = oe.id_opcional)'
            . ' AND NOT EXISTS (SELECT 1 FROM catalogo_opcional_familia ofam'
            . ' WHERE ofam.id_opcional = oe.id_opcional)'
            . ' AND NOT EXISTS (SELECT 1 FROM catalogo_caracteristica_global c'
            . ' WHERE c.codtarifa = ' . self::quote(self::DEF_TARIFA)
            . ' AND c.id_caracteristica = ' . $flag['id'] . ')';

        self::insert_pending(
            $db,
            'catalogo_caracteristica_global',
            'codtarifa, id_caracteristica, id_valor, valor, custom',
            self::quote(self::DEF_TARIFA) . ', ' . $flag['id']
                . ', CASE WHEN oe.' . $flag['codigo'] . ' THEN ' . $flag['one']
                . ' ELSE ' . $flag['zero'] . ' END, NULL, 0',
            'tarif_opcional_ext oe',
            $where
        );
    }

    // =====================================================================
    // Insert helpers
    // =====================================================================

    /**
     * Cheap pre-check: emit the bulk `INSERT` only when at least one legacy
     * row is still pending (the steady state after the first run is a no-op).
     */
    private static function insert_pending(
        \fs_db2 $db,
        string $target,
        string $columns,
        string $select,
        string $from,
        string $where
    ): void {
        if (!self::has_pending($db, $from, $where)) {
            return;
        }

        $db->exec(
            'INSERT INTO ' . $target . ' (' . $columns . ') '
            . 'SELECT ' . $select . ' FROM ' . $from . ' WHERE ' . $where . ';'
        );
    }

    private static function has_pending(\fs_db2 $db, string $from, string $where): bool
    {
        return (bool) $db->select('SELECT 1 FROM ' . $from . ' WHERE ' . $where . ' LIMIT 1;');
    }

    private static function flag_not_null(array $flag, string $alias): string
    {
        return $alias . '.' . $flag['codigo'] . ' IS NOT NULL';
    }

    private static function not_exists_articulo(array $flag, string $tarifaExpr, string $keyExpr): string
    {
        return 'NOT EXISTS (SELECT 1 FROM catalogo_caracteristica_articulo c'
            . ' WHERE c.codtarifa = ' . $tarifaExpr
            . ' AND c.referencia = ' . $keyExpr
            . ' AND c.id_caracteristica = ' . $flag['id'] . ')';
    }

    private static function not_exists_familia(array $flag, string $tarifaExpr, string $keyExpr): string
    {
        return 'NOT EXISTS (SELECT 1 FROM catalogo_caracteristica_familia c'
            . ' WHERE c.codtarifa = ' . $tarifaExpr
            . ' AND c.codfamilia = ' . $keyExpr
            . ' AND c.id_caracteristica = ' . $flag['id'] . ')';
    }

    private static function quote(string $value): string
    {
        return "'" . addslashes($value) . "'";
    }

    // =====================================================================
    // Definition resolution
    // =====================================================================

    /**
     * Resolves both bool definitions and their `'1'`/`'0'` catalog values.
     * Returns null when either definition (or a value) is absent, so the
     * whole backfill short-circuits until tarifario registers them.
     *
     * @return list<array{codigo: string, id: int, one: int, zero: int}>|null
     */
    private static function flag_definitions(\fs_db2 $db): ?array
    {
        $flags = [];
        foreach (self::FLAG_CODIGOS as $codigo) {
            $id = self::definition_id($db, $codigo);
            if ($id === null) {
                return null;
            }

            $one = self::catalog_value_id($db, $id, '1');
            $zero = self::catalog_value_id($db, $id, '0');
            if ($one === null || $zero === null) {
                return null;
            }

            $flags[] = ['codigo' => $codigo, 'id' => $id, 'one' => $one, 'zero' => $zero];
        }

        return $flags;
    }

    private static function definition_id(\fs_db2 $db, string $codigo): ?int
    {
        $data = (array) $db->select(
            'SELECT id FROM catalogo_caracteristicas WHERE codigo = ' . self::quote($codigo) . ' LIMIT 1;'
        );

        return $data === [] ? null : (int) $data[0]['id'];
    }

    private static function catalog_value_id(\fs_db2 $db, int $idCaracteristica, string $valor): ?int
    {
        $data = (array) $db->select(
            'SELECT id FROM catalogo_caracteristica_valores WHERE id_caracteristica = '
            . $idCaracteristica . ' AND valor = ' . self::quote($valor) . ' LIMIT 1;'
        );

        return $data === [] ? null : (int) $data[0]['id'];
    }

    // =====================================================================
    // Driver-aware introspection (mirrors TarifOpcionalExtMigration)
    // =====================================================================

    private static function surface_ready(\fs_db2 $db, string $table, string $column): bool
    {
        return self::tableExists($db, $table) && self::columnExists($db, $table, $column);
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
