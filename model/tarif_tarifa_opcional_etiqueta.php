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
 * Etiquetas requeridas por opcional en familia y tarifa.
 */
class tarif_tarifa_opcional_etiqueta extends \fs_model
{
    public $codtarifa;
    public $id_opcional;
    public $codfamilia;
    public $etiqueta;

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_tarifa_opcional_etiqueta');

        if ($data) {
            $this->codtarifa = $data['codtarifa'];
            $this->id_opcional = intval($data['id_opcional']);
            $this->codfamilia = $data['codfamilia'];
            $this->etiqueta = $data['etiqueta'];
        } else {
            $this->codtarifa = NULL;
            $this->id_opcional = NULL;
            $this->codfamilia = NULL;
            $this->etiqueta = NULL;
        }
    }

    protected function install()
    {
        new tarif_tarifa();
        new tarif_opcional();
        return '';
    }

    public function exists_relation($codtarifa, $id_opcional, $codfamilia, $etiqueta)
    {
        $data = $this->db->select("SELECT 1 FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND id_opcional = " . $this->intval($id_opcional)
            . " AND codfamilia = " . $this->var2str($codfamilia)
            . " AND etiqueta = " . $this->var2str($etiqueta) . " LIMIT 1;");

        return ($data && count($data) > 0);
    }

    public function add($codtarifa, $id_opcional, $codfamilia, $etiqueta)
    {
        $etiqueta = trim($etiqueta);
        if (empty($etiqueta) || $this->exists_relation($codtarifa, $id_opcional, $codfamilia, $etiqueta)) {
            return TRUE;
        }

        return $this->db->exec("INSERT INTO " . $this->table_name
            . " (codtarifa, id_opcional, codfamilia, etiqueta) VALUES ("
            . $this->var2str($codtarifa) . ","
            . $this->intval($id_opcional) . ","
            . $this->var2str($codfamilia) . ","
            . $this->var2str($etiqueta) . ");");
    }

    public function remove($codtarifa, $id_opcional, $codfamilia, $etiqueta)
    {
        return $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND id_opcional = " . $this->intval($id_opcional)
            . " AND codfamilia = " . $this->var2str($codfamilia)
            . " AND etiqueta = " . $this->var2str($etiqueta) . ";");
    }

    public function get_etiquetas_opcional($codtarifa, $id_opcional, $codfamilia)
    {
        $list = [];
        $data = $this->db->select("SELECT etiqueta FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND id_opcional = " . $this->intval($id_opcional)
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
     * Elimina todas las etiquetas de un opcional en una familia y tarifa.
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @param string $codfamilia
     * @return bool
     */
    public function delete_all_from_opcional_familia_tarifa($codtarifa, $id_opcional, $codfamilia)
    {
        return $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND id_opcional = " . $this->intval($id_opcional)
            . " AND codfamilia = " . $this->var2str($codfamilia) . ";");
    }

    /**
     * Reemplaza las etiquetas de un opcional en una familia y tarifa.
     *
     * @param string $codtarifa
     * @param int $id_opcional
     * @param string $codfamilia
     * @param array $etiquetas
     * @return bool
     */
    public function replace_etiquetas_opcional($codtarifa, $id_opcional, $codfamilia, $etiquetas)
    {
        $normalized = [];
        foreach ((array) $etiquetas as $etiqueta) {
            $tag = trim((string) $etiqueta);
            if ('' === $tag) {
                continue;
            }
            $normalized[$tag] = true;
        }

        if (!$this->delete_all_from_opcional_familia_tarifa($codtarifa, $id_opcional, $codfamilia)) {
            return false;
        }

        foreach (array_keys($normalized) as $tag) {
            if (!$this->add($codtarifa, $id_opcional, $codfamilia, $tag)) {
                return false;
            }
        }

        return true;
    }

    public function exists()
    {
        if (is_null($this->codtarifa) || is_null($this->id_opcional) || is_null($this->codfamilia) || is_null($this->etiqueta)) {
            return FALSE;
        }

        return $this->exists_relation($this->codtarifa, $this->id_opcional, $this->codfamilia, $this->etiqueta);
    }

    public function save()
    {
        return $this->add($this->codtarifa, $this->id_opcional, $this->codfamilia, $this->etiqueta);
    }

    public function delete()
    {
        return $this->remove($this->codtarifa, $this->id_opcional, $this->codfamilia, $this->etiqueta);
    }
}
