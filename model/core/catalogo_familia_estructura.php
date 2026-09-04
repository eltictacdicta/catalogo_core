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

/**
 * Estructura de familias por lista de precio del catálogo (extensión 1:N
 * de la tabla base `familias`, patrón extension-table).
 *
 * Semántica de columnas (design D2, risk notes):
 * - `madre`: metadata de display POR LISTA. La jerarquía autoritativa del
 *   árbol es `familias.madre`; aquí no lleva FK (paridad con legacy) para
 *   tolerar filas donde la madre por lista se crea después que los hijos.
 * - `nivel`: etiqueta de display heredada del legacy. NUNCA confundir con
 *   `familias.nivel`; la profundidad del árbol se deriva de la cadena
 *   `madre`, nunca de esta cadena.
 * - `en_tarifa`: view-visibility flag only (flag de VISIBILIDAD de vista).
 *   NO tiene efecto de precios en catalogo_core (resolución de precios nunca
 *   hace join con la estructura de familias); la vista de catálogo honra
 *   `en_catalogo`, no `en_tarifa`.
 */
class catalogo_familia_estructura extends \fs_model
{
    public const TABLE = 'catalogo_familia_estructura';

    public $codlista;
    public $codfamilia;
    public $madre;
    public $capitulo;
    public $nivel;
    public $en_catalogo;
    public $en_tarifa;
    public $activa;
    public $orden;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->codlista = $data['codlista'];
            $this->codfamilia = $data['codfamilia'];
            $this->madre = isset($data['madre']) && $data['madre'] !== '' ? $data['madre'] : null;
            $this->capitulo = $data['capitulo'] ?? '';
            $this->nivel = $data['nivel'] ?? '';
            $this->en_catalogo = isset($data['en_catalogo']) ? $this->str2bool($data['en_catalogo']) : true;
            $this->en_tarifa = isset($data['en_tarifa']) ? $this->str2bool($data['en_tarifa']) : false;
            $this->activa = isset($data['activa']) ? $this->str2bool($data['activa']) : true;
            $this->orden = isset($data['orden']) ? intval($data['orden']) : 0;
        } else {
            $this->codlista = null;
            $this->codfamilia = null;
            $this->madre = null;
            $this->capitulo = '';
            $this->nivel = '';
            $this->en_catalogo = true;
            $this->en_tarifa = false;
            $this->activa = true;
            $this->orden = 0;
        }
    }

    protected function install()
    {
        // The parent table (catalogo_listas_precio) is ensured earlier by
        // Init::ensureCatalogTables(); nothing to seed.
        return '';
    }

    public function get($codlista, $codfamilia)
    {
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE codlista = ' . $this->var2str($codlista)
            . ' AND codfamilia = ' . $this->var2str($codfamilia) . ';';
        $data = $this->db->select($sql);
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function all_from_list($codlista)
    {
        $list = [];
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE codlista = ' . $this->var2str($codlista) . ';';
        foreach ($this->db->select($sql) as $row) {
            $list[] = new static($row);
        }

        return $list;
    }

    public function exists()
    {
        if (is_null($this->codlista) || is_null($this->codfamilia)) {
            return false;
        }

        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE codlista = ' . $this->var2str($this->codlista)
            . ' AND codfamilia = ' . $this->var2str($this->codfamilia) . ';';

        return $this->db->select($sql);
    }

    public function test()
    {
        $this->codlista = $this->no_html(trim((string) $this->codlista));
        $this->codfamilia = $this->no_html(trim((string) $this->codfamilia));
        $this->madre = $this->madre !== null ? $this->no_html(trim((string) $this->madre)) : null;
        $this->capitulo = $this->no_html((string) $this->capitulo);
        $this->nivel = $this->no_html((string) $this->nivel);

        if (mb_strlen($this->codlista) < 1 || mb_strlen($this->codlista) > 20) {
            $this->new_error_msg('Código de lista no válido.');
            return false;
        }

        if (mb_strlen($this->codfamilia) < 1 || mb_strlen($this->codfamilia) > 8) {
            $this->new_error_msg('Código de familia no válido.');
            return false;
        }

        if ($this->madre !== null && (mb_strlen($this->madre) < 1 || mb_strlen($this->madre) > 8)) {
            $this->new_error_msg('Código de familia madre no válido.');
            return false;
        }

        if ($this->orden < 0) {
            $this->new_error_msg('El orden no puede ser negativo.');
            return false;
        }

        return true;
    }

    public function save()
    {
        if (!$this->test()) {
            return false;
        }

        if ($this->exists()) {
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'madre = ' . $this->var2str($this->madre) . ','
                . 'capitulo = ' . $this->var2str($this->capitulo) . ','
                . 'nivel = ' . $this->var2str($this->nivel) . ','
                . 'en_catalogo = ' . $this->var2str($this->en_catalogo) . ','
                . 'en_tarifa = ' . $this->var2str($this->en_tarifa) . ','
                . 'activa = ' . $this->var2str($this->activa) . ','
                . 'orden = ' . $this->intval($this->orden)
                . ' WHERE codlista = ' . $this->var2str($this->codlista)
                . ' AND codfamilia = ' . $this->var2str($this->codfamilia) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name
                . ' (codlista, codfamilia, madre, capitulo, nivel, en_catalogo, en_tarifa, activa, orden) VALUES ('
                . $this->var2str($this->codlista) . ','
                . $this->var2str($this->codfamilia) . ','
                . $this->var2str($this->madre) . ','
                . $this->var2str($this->capitulo) . ','
                . $this->var2str($this->nivel) . ','
                . $this->var2str($this->en_catalogo) . ','
                . $this->var2str($this->en_tarifa) . ','
                . $this->var2str($this->activa) . ','
                . $this->intval($this->orden) . ');';
        }

        return $this->db->exec($sql);
    }

    public function delete()
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE codlista = ' . $this->var2str($this->codlista)
            . ' AND codfamilia = ' . $this->var2str($this->codfamilia) . ';';

        return $this->db->exec($sql);
    }
}
