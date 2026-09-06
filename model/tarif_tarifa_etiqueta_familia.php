<?php
/**
 * This file is part of tarifario
 * Copyright (C) 2025 FSFramework Team
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
 * Etiquetas disponibles por familia y tarifa.
 */
class tarif_tarifa_etiqueta_familia extends \fs_model
{
    public $codtarifa;
    public $codfamilia;
    public $etiqueta;
    public $visible;

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_tarifa_etiqueta_familia');

        if ($data) {
            $this->codtarifa = $data['codtarifa'];
            $this->codfamilia = $data['codfamilia'];
            $this->etiqueta = $data['etiqueta'];
            $this->visible = isset($data['visible']) ? $this->str2bool($data['visible']) : FALSE;
        } else {
            $this->codtarifa = NULL;
            $this->codfamilia = NULL;
            $this->etiqueta = NULL;
            $this->visible = FALSE;
        }
    }

    protected function install()
    {
        new tarif_tarifa();
        return '';
    }

    public function exists_relation($codtarifa, $codfamilia, $etiqueta)
    {
        $data = $this->db->select("SELECT 1 FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND codfamilia = " . $this->var2str($codfamilia)
            . " AND etiqueta = " . $this->var2str($etiqueta) . " LIMIT 1;");

        return ($data && count($data) > 0);
    }

    public function add($codtarifa, $codfamilia, $etiqueta, $visible = FALSE)
    {
        $etiqueta = trim($etiqueta);
        if (empty($etiqueta)) {
            return TRUE;
        }

        if ($this->exists_relation($codtarifa, $codfamilia, $etiqueta)) {
            // Update visible flag if the relation already exists
            return $this->db->exec("UPDATE " . $this->table_name
                . " SET visible = " . $this->var2str($visible)
                . " WHERE codtarifa = " . $this->var2str($codtarifa)
                . " AND codfamilia = " . $this->var2str($codfamilia)
                . " AND etiqueta = " . $this->var2str($etiqueta) . ";");
        }

        return $this->db->exec("INSERT INTO " . $this->table_name
            . " (codtarifa, codfamilia, etiqueta, visible) VALUES ("
            . $this->var2str($codtarifa) . ","
            . $this->var2str($codfamilia) . ","
            . $this->var2str($etiqueta) . ","
            . $this->var2str($visible) . ");");
    }

    public function remove($codtarifa, $codfamilia, $etiqueta)
    {
        return $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND codfamilia = " . $this->var2str($codfamilia)
            . " AND etiqueta = " . $this->var2str($etiqueta) . ";");
    }

    public function get_etiquetas_familia($codtarifa, $codfamilia)
    {
        $list = [];
        $data = $this->db->select("SELECT etiqueta FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND codfamilia = " . $this->var2str($codfamilia)
            . " ORDER BY etiqueta ASC;");

        if ($data) {
            foreach ($data as $row) {
                $list[] = $row['etiqueta'];
            }
        }
        return $list;
    }

    /**
     * Devuelve las etiquetas de una familia con su visibilidad.
     *
     * @param string $codtarifa
     * @param string $codfamilia
     * @return array  Array de objetos con propiedades etiqueta y visible
     */
    public function get_etiquetas_familia_full($codtarifa, $codfamilia)
    {
        $list = [];
        $data = $this->db->select("SELECT etiqueta, visible FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND codfamilia = " . $this->var2str($codfamilia)
            . " ORDER BY etiqueta ASC;");

        if ($data) {
            foreach ($data as $row) {
                $obj = new \stdClass();
                $obj->etiqueta = $row['etiqueta'];
                $obj->visible = $this->str2bool($row['visible']);
                $list[] = $obj;
            }
        }
        return $list;
    }

    public function familia_tiene_etiquetas($codtarifa, $codfamilia)
    {
        $data = $this->db->select("SELECT 1 FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND codfamilia = " . $this->var2str($codfamilia)
            . " LIMIT 1;");

        return ($data && count($data) > 0);
    }

    /**
     * Elimina todas las etiquetas de una familia en una tarifa.
     *
     * @param string $codtarifa
     * @param string $codfamilia
     * @return bool
     */
    public function delete_all_from_familia_tarifa($codtarifa, $codfamilia)
    {
        return $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND codfamilia = " . $this->var2str($codfamilia) . ";");
    }

    /**
     * Reemplaza las etiquetas de una familia en una tarifa.
     *
     * @param string $codtarifa
     * @param string $codfamilia
     * @param array  $etiquetas       Array de nombres de etiqueta
     * @param array  $visibilidad     Array asociativo etiqueta => bool (opcional)
     * @return bool
     */
    public function replace_etiquetas_familia($codtarifa, $codfamilia, $etiquetas, $visibilidad = [])
    {
        $normalized = [];
        foreach ((array) $etiquetas as $etiqueta) {
            $tag = trim((string) $etiqueta);
            if ('' === $tag) {
                continue;
            }
            $normalized[$tag] = true;
        }

        if (!$this->delete_all_from_familia_tarifa($codtarifa, $codfamilia)) {
            return false;
        }

        foreach (array_keys($normalized) as $tag) {
            $vis = isset($visibilidad[$tag]) ? (bool) $visibilidad[$tag] : FALSE;
            if (!$this->add($codtarifa, $codfamilia, $tag, $vis)) {
                return false;
            }
        }

        return true;
    }

    public function exists()
    {
        if (is_null($this->codtarifa) || is_null($this->codfamilia) || is_null($this->etiqueta)) {
            return FALSE;
        }

        return $this->exists_relation($this->codtarifa, $this->codfamilia, $this->etiqueta);
    }

    public function save()
    {
        return $this->add($this->codtarifa, $this->codfamilia, $this->etiqueta, $this->visible);
    }

    public function delete()
    {
        return $this->remove($this->codtarifa, $this->codfamilia, $this->etiqueta);
    }
}
