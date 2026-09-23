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
 * Registers feature definitions owned by plugins (CAR-11).
 *
 * Registration is a targeted, idempotent upsert — never `seed_if_empty()` and
 * never an UPDATE — so operator edits to a default (name, flags, orden,
 * valor_defecto) survive while a missing/out-of-band-deleted row is restored.
 * Intended to run from both `init()` and `upgrade()` so boot order cannot skip
 * it. Any plugin may call `registerDefault()`; tarifario registers its two
 * boolean features from its own boot (WU-6).
 */
final class CaracteristicaRegistry
{
    /**
     * catalogo_core-owned defaults (design §9.3).
     *
     * @var list<array<string, mixed>>
     */
    public const DEFAULTS = [
        [
            'codigo' => 'medidas',
            'nombre' => 'Medidas',
            'tipo' => 'string',
            'origen' => 'catalogo_core',
            'orden' => 10,
            'importable' => false,
            'exportable' => false,
            'listable' => false,
            'valor_defecto' => null,
        ],
    ];

    /**
     * Targeted upsert of one definition, then the bool pair when applicable.
     *
     * @param array<string, mixed> $flags importable|exportable|listable|orden
     * @param object|null $db DB seam (any object exposing select/exec/var2str)
     */
    public static function registerDefault(
        string $codigo,
        string $nombre,
        string $tipo,
        string $origen,
        array $flags = [],
        $db = null
    ): bool {
        $db = self::resolveDb($db);
        if ($db === null) {
            return false;
        }

        $insert = self::isPostgres()
            ? 'INSERT INTO catalogo_caracteristicas'
                . ' (codigo, nombre, tipo, activo, importable, exportable, listable, orden, origen, valor_defecto)'
                . ' VALUES ('
                . $db->var2str($codigo) . ','
                . $db->var2str($nombre) . ','
                . $db->var2str($tipo) . ','
                . 'TRUE,'
                . $db->var2str((bool) ($flags['importable'] ?? false)) . ','
                . $db->var2str((bool) ($flags['exportable'] ?? false)) . ','
                . $db->var2str((bool) ($flags['listable'] ?? false)) . ','
                . $db->var2str((int) ($flags['orden'] ?? 0)) . ','
                . $db->var2str($origen) . ','
                . $db->var2str($flags['valor_defecto'] ?? null) . ')'
                . ' ON CONFLICT (codigo) DO NOTHING;'
            : 'INSERT IGNORE INTO catalogo_caracteristicas'
                . ' (codigo, nombre, tipo, activo, importable, exportable, listable, orden, origen, valor_defecto)'
                . ' VALUES ('
                . $db->var2str($codigo) . ','
                . $db->var2str($nombre) . ','
                . $db->var2str($tipo) . ','
                . 'TRUE,'
                . $db->var2str((bool) ($flags['importable'] ?? false)) . ','
                . $db->var2str((bool) ($flags['exportable'] ?? false)) . ','
                . $db->var2str((bool) ($flags['listable'] ?? false)) . ','
                . $db->var2str((int) ($flags['orden'] ?? 0)) . ','
                . $db->var2str($origen) . ','
                . $db->var2str($flags['valor_defecto'] ?? null) . ');';

        if (!$db->exec($insert)) {
            return false;
        }

        if ($tipo !== 'bool') {
            return true;
        }

        $id = self::definitionId($db, $codigo);
        if ($id > 0) {
            self::seedBoolPair($id, $db);
        }

        return true;
    }

    /**
     * Registers every catalogo_core-owned default.
     *
     * @param object|null $db DB seam
     */
    public static function registerDefaults($db = null): void
    {
        $db = self::resolveDb($db);
        if ($db === null) {
            return;
        }

        foreach (self::DEFAULTS as $default) {
            self::registerDefault(
                (string) $default['codigo'],
                (string) $default['nombre'],
                (string) $default['tipo'],
                (string) $default['origen'],
                [
                    'orden' => $default['orden'] ?? 0,
                    'importable' => $default['importable'] ?? false,
                    'exportable' => $default['exportable'] ?? false,
                    'listable' => $default['listable'] ?? false,
                    'valor_defecto' => $default['valor_defecto'] ?? null,
                ],
                $db
            );
        }
    }

    /**
     * Idempotent '1' (Sí, orden 0) / '0' (No, orden 1) seed for a bool definition.
     *
     * @param object|null $db DB seam
     */
    public static function seedBoolPair(int $idCaracteristica, $db = null): void
    {
        $db = self::resolveDb($db);
        if ($db === null) {
            return;
        }

        foreach ([['1', 0], ['0', 1]] as $pair) {
            $insert = self::isPostgres()
                ? 'INSERT INTO catalogo_caracteristica_valores (id_caracteristica, valor, orden, activo)'
                    . ' VALUES (' . $db->var2str($idCaracteristica) . ',' . $db->var2str($pair[0]) . ','
                    . $db->var2str($pair[1]) . ',TRUE)'
                    . ' ON CONFLICT (id_caracteristica, valor) DO NOTHING;'
                : 'INSERT IGNORE INTO catalogo_caracteristica_valores (id_caracteristica, valor, orden, activo)'
                    . ' VALUES (' . $db->var2str($idCaracteristica) . ',' . $db->var2str($pair[0]) . ','
                    . $db->var2str($pair[1]) . ',TRUE);';

            $db->exec($insert);
        }
    }

    /** @param object $db */
    private static function definitionId($db, string $codigo): int
    {
        $data = $db->select('SELECT id FROM catalogo_caracteristicas WHERE codigo = '
            . $db->var2str($codigo) . ';');

        if ($data) {
            return (int) ($data[0]['id'] ?? 0);
        }

        return 0;
    }

    /** @param object|null $db */
    private static function resolveDb($db)
    {
        if ($db !== null) {
            return $db;
        }

        try {
            // Open a real \fs_db2 rather than the container's lazy `db`
            // service: that service is a Symfony proxy whose constructor never
            // runs, so it is unusable unless another fs_db2 already ran in the
            // process (see Init::plugin_db()). The guarded require_once mirrors
            // Init::require_db_class(); require_once keeps it idempotent.
            if (!class_exists('\fs_db2', false)) {
                require_once FS_FOLDER . '/base/fs_core_log.php';
                require_once FS_FOLDER . '/base/fs_db2.php';
            }

            return new \fs_db2();
        } catch (\Throwable $e) {
            error_log('[catalogo_core] CaracteristicaRegistry DB unavailable: ' . $e->getMessage());
            return null;
        }
    }

    private static function isPostgres(): bool
    {
        return defined('FS_DB_TYPE') && FS_DB_TYPE === 'postgresql';
    }
}
