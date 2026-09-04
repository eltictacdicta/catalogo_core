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
 * Asignación de artículos a familias por lista de precio del catálogo
 * (extensión 1:N de `articulos`, PK compuesta codlista + referencia).
 *
 * Semántica de columnas (design D2, risk notes):
 * - `codfamilia`: NULL-able y SIN FK a propósito (paridad con legacy): la
 *   familia puede no existir aún en la lista y ON DELETE SET NULL no aplica
 *   con FKs compuestas parciales. Integridad a nivel de aplicación.
 * - `en_tarifa`: view-visibility flag only. NO tiene efecto de precios en
 *   catalogo_core (resolución de precios nunca hace join con la estructura
 *   de familias); la vista de catálogo honra `en_catalogo`, no `en_tarifa`.
 */
class catalogo_articulo_familia_estructura extends \fs_model
{
    public const TABLE = 'catalogo_articulo_familia_estructura';

    public $codlista;
    public $referencia;
    public $codfamilia;
    public $en_tarifa;
    public $en_catalogo;
    public $orden;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->codlista = $data['codlista'];
            $this->referencia = $data['referencia'];
            $this->codfamilia = isset($data['codfamilia']) && $data['codfamilia'] !== '' ? $data['codfamilia'] : null;
            $this->en_tarifa = isset($data['en_tarifa']) ? $this->str2bool($data['en_tarifa']) : true;
            $this->en_catalogo = isset($data['en_catalogo']) ? $this->str2bool($data['en_catalogo']) : true;
            $this->orden = isset($data['orden']) ? intval($data['orden']) : 0;
        } else {
            $this->codlista = null;
            $this->referencia = null;
            $this->codfamilia = null;
            $this->en_tarifa = true;
            $this->en_catalogo = true;
            $this->orden = 0;
        }
    }

    protected function install()
    {
        // Parent tables (catalogo_listas_precio, articulos) are ensured
        // earlier; nothing to seed.
        return '';
    }

    public function get($codlista, $referencia)
    {
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE codlista = ' . $this->var2str($codlista)
            . ' AND referencia = ' . $this->var2str($referencia) . ';';
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

    public function all_from_article($referencia)
    {
        $list = [];
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($referencia) . ';';
        foreach ($this->db->select($sql) as $row) {
            $list[] = new static($row);
        }

        return $list;
    }

    public function exists()
    {
        if (is_null($this->codlista) || is_null($this->referencia)) {
            return false;
        }

        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE codlista = ' . $this->var2str($this->codlista)
            . ' AND referencia = ' . $this->var2str($this->referencia) . ';';

        return $this->db->select($sql);
    }

    public function test()
    {
        $this->codlista = $this->no_html(trim((string) $this->codlista));
        $this->referencia = $this->no_html(trim((string) $this->referencia));
        $this->codfamilia = $this->codfamilia !== null && $this->codfamilia !== ''
            ? $this->no_html(trim((string) $this->codfamilia))
            : null;

        if (mb_strlen($this->codlista) < 1 || mb_strlen($this->codlista) > 20) {
            $this->new_error_msg('Código de lista no válido.');
            return false;
        }

        if (mb_strlen($this->referencia) < 1 || mb_strlen($this->referencia) > 18) {
            $this->new_error_msg('Referencia de artículo no válida.');
            return false;
        }

        if ($this->codfamilia !== null && (mb_strlen($this->codfamilia) < 1 || mb_strlen($this->codfamilia) > 8)) {
            $this->new_error_msg('Código de familia no válido.');
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
                . 'codfamilia = ' . $this->var2str($this->codfamilia) . ','
                . 'en_tarifa = ' . $this->var2str($this->en_tarifa) . ','
                . 'en_catalogo = ' . $this->var2str($this->en_catalogo) . ','
                . 'orden = ' . $this->intval($this->orden)
                . ' WHERE codlista = ' . $this->var2str($this->codlista)
                . ' AND referencia = ' . $this->var2str($this->referencia) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name
                . ' (codlista, referencia, codfamilia, en_tarifa, en_catalogo, orden) VALUES ('
                . $this->var2str($this->codlista) . ','
                . $this->var2str($this->referencia) . ','
                . $this->var2str($this->codfamilia) . ','
                . $this->var2str($this->en_tarifa) . ','
                . $this->var2str($this->en_catalogo) . ','
                . $this->intval($this->orden) . ');';
        }

        return $this->db->exec($sql);
    }

    public function delete()
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE codlista = ' . $this->var2str($this->codlista)
            . ' AND referencia = ' . $this->var2str($this->referencia) . ';';

        return $this->db->exec($sql);
    }
}
