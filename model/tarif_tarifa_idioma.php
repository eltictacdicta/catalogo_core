<?php
/**
 * This file is part of catalogo_core
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
namespace FSFramework\model;

require_once __DIR__ . '/core/catalogo_idioma.php';

/**
 * Idiomas en los que va una tarifa y su idioma por defecto (R-TAR-IDI-001).
 *
 * Una fila por (tarifa, idioma). Una tarifa SIN filas no está configurada:
 * usa todos los idiomas activos y el idioma por defecto global, de modo que
 * las tarifas existentes no cambian de comportamiento.
 */
class tarif_tarifa_idioma extends \fs_model
{
    public const TABLE = 'tarif_tarifa_idiomas';

    public $codtarifa;
    public $codidioma;
    public $por_defecto;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->codtarifa = $data['codtarifa'];
            $this->codidioma = $data['codidioma'];
            $this->por_defecto = $this->str2bool($data['por_defecto']);
        } else {
            $this->codtarifa = null;
            $this->codidioma = null;
            $this->por_defecto = false;
        }
    }

    /**
     * Resolución total y determinista de los idiomas efectivos de una tarifa.
     *
     * - Habilitados: los configurados que siguen activos. Sin configuración,
     *   o si ninguno de los configurados sigue activo, todos los activos.
     * - Por defecto: el marcado en la tarifa si está habilitado; si no, el
     *   por defecto global si está habilitado; si no, el primero habilitado.
     * - Orden: el idioma por defecto primero y el resto por `codidioma`.
     *
     * @param string[]            $activeCodes  códigos activos del registro
     * @param array<string, bool> $configured   codidioma => por_defecto
     * @param string              $globalDefault idioma por defecto global
     * @return array{codes: string[], default: string}
     */
    public static function resolve(array $activeCodes, array $configured, string $globalDefault): array
    {
        $active = array_values(array_unique(array_map('strval', $activeCodes)));
        sort($active, SORT_STRING);

        $enabled = array_values(array_intersect($active, array_map('strval', array_keys($configured))));
        if ($enabled === []) {
            $enabled = $active;
        }

        if ($enabled === []) {
            return ['codes' => [$globalDefault], 'default' => $globalDefault];
        }

        $default = '';
        foreach ($configured as $code => $isDefault) {
            if ($isDefault && in_array((string) $code, $enabled, true)) {
                $default = (string) $code;
                break;
            }
        }
        if ($default === '') {
            $default = in_array($globalDefault, $enabled, true) ? $globalDefault : $enabled[0];
        }

        $rest = array_values(array_diff($enabled, [$default]));

        return ['codes' => array_merge([$default], $rest), 'default' => $default];
    }

    /**
     * Configuración guardada de la tarifa: codidioma => por_defecto.
     * Vacía cuando la tarifa no tiene idiomas configurados.
     *
     * @return array<string, bool>
     */
    public function config_from_tarifa($codtarifa): array
    {
        $config = [];
        $data = $this->db->select(
            'SELECT codidioma, por_defecto FROM ' . $this->table_name
            . ' WHERE codtarifa = ' . $this->var2str($codtarifa) . ' ORDER BY codidioma ASC;'
        );
        if ($data) {
            foreach ($data as $d) {
                $config[(string) $d['codidioma']] = $this->str2bool($d['por_defecto']);
            }
        }

        return $config;
    }

    /**
     * Sustituye la configuración de idiomas de una tarifa. `$codes` vacío
     * borra la configuración (la tarifa vuelve a usar todos los activos).
     * `$default` debe estar en `$codes`; si no, se usa el primero.
     *
     * @param string[] $codes
     */
    public function save_config($codtarifa, array $codes, string $default): bool
    {
        $codes = array_values(array_unique(array_filter(array_map(
            static fn($c) => catalogo_idioma::normalize_code($c),
            $codes
        ), static fn($c) => $c !== '')));
        $default = catalogo_idioma::normalize_code($default);
        if ($codes !== [] && !in_array($default, $codes, true)) {
            $default = $codes[0];
        }

        $this->db->begin_transaction();

        $ok = (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name . ' WHERE codtarifa = ' . $this->var2str($codtarifa) . ';'
        );

        foreach ($codes as $code) {
            if (!$ok) {
                break;
            }
            $ok = (bool) $this->db->exec(
                'INSERT INTO ' . $this->table_name . ' (codtarifa, codidioma, por_defecto) VALUES ('
                . $this->var2str($codtarifa) . ', '
                . $this->var2str($code) . ', '
                . $this->var2str($code === $default) . ');'
            );
        }

        if (!$ok) {
            $this->db->rollback();
            return false;
        }

        $this->db->commit();
        return true;
    }

    public function delete_from_tarifa($codtarifa): bool
    {
        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name . ' WHERE codtarifa = ' . $this->var2str($codtarifa) . ';'
        );
    }

    public function exists()
    {
        if ($this->codtarifa === null || $this->codidioma === null) {
            return false;
        }

        return (bool) $this->db->select(
            'SELECT 1 FROM ' . $this->table_name
            . ' WHERE codtarifa = ' . $this->var2str($this->codtarifa)
            . ' AND codidioma = ' . $this->var2str($this->codidioma) . ';'
        );
    }

    public function save()
    {
        if ($this->exists()) {
            return (bool) $this->db->exec(
                'UPDATE ' . $this->table_name . ' SET por_defecto = ' . $this->var2str($this->por_defecto)
                . ' WHERE codtarifa = ' . $this->var2str($this->codtarifa)
                . ' AND codidioma = ' . $this->var2str($this->codidioma) . ';'
            );
        }

        return (bool) $this->db->exec(
            'INSERT INTO ' . $this->table_name . ' (codtarifa, codidioma, por_defecto) VALUES ('
            . $this->var2str($this->codtarifa) . ', '
            . $this->var2str($this->codidioma) . ', '
            . $this->var2str($this->por_defecto) . ');'
        );
    }

    public function delete()
    {
        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name
            . ' WHERE codtarifa = ' . $this->var2str($this->codtarifa)
            . ' AND codidioma = ' . $this->var2str($this->codidioma) . ';'
        );
    }
}
