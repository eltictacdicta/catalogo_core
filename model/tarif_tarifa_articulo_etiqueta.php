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
 * Etiquetas asignadas a artículos por tarifa.
 */
class tarif_tarifa_articulo_etiqueta extends \fs_model
{
    public $codtarifa;
    public $referencia;
    public $etiqueta;

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_tarifa_articulo_etiqueta');

        if ($data) {
            $this->codtarifa = $data['codtarifa'];
            $this->referencia = $data['referencia'];
            $this->etiqueta = $data['etiqueta'];
        } else {
            $this->codtarifa = NULL;
            $this->referencia = NULL;
            $this->etiqueta = NULL;
        }
    }

    protected function install()
    {
        new tarif_tarifa();
        return '';
    }

    public function exists_relation($codtarifa, $referencia, $etiqueta)
    {
        $data = $this->db->select("SELECT 1 FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND referencia = " . $this->var2str($referencia)
            . " AND etiqueta = " . $this->var2str($etiqueta) . " LIMIT 1;");

        return ($data && count($data) > 0);
    }

    public function add($codtarifa, $referencia, $etiqueta, $transaction = null)
    {
        $etiqueta = trim($etiqueta);
        if (empty($etiqueta) || $this->exists_relation($codtarifa, $referencia, $etiqueta)) {
            return TRUE;
        }

        return $this->db->exec("INSERT INTO " . $this->table_name
            . " (codtarifa, referencia, etiqueta) VALUES ("
            . $this->var2str($codtarifa) . ","
            . $this->var2str($referencia) . ","
            . $this->var2str($etiqueta) . ");", $transaction);
    }

    public function remove($codtarifa, $referencia, $etiqueta)
    {
        return $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND referencia = " . $this->var2str($referencia)
            . " AND etiqueta = " . $this->var2str($etiqueta) . ";");
    }

    public function get_etiquetas_articulo($codtarifa, $referencia)
    {
        $list = [];
        $data = $this->db->select("SELECT etiqueta FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND referencia = " . $this->var2str($referencia)
            . " ORDER BY etiqueta ASC;");

        if ($data) {
            foreach ($data as $row) {
                $list[] = $row['etiqueta'];
            }
        }
        return $list;
    }

    /**
     * Elimina todas las etiquetas de un artículo en una tarifa.
     *
     * @param string $codtarifa
     * @param string $referencia
     * @return bool
     */
    public function delete_all_from_articulo_tarifa($codtarifa, $referencia, $transaction = null)
    {
        return $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND referencia = " . $this->var2str($referencia) . ";", $transaction);
    }

    /**
     * Reemplaza las etiquetas de un artículo en una tarifa.
     *
     * @param string $codtarifa
     * @param string $referencia
     * @param array $etiquetas
     * @return bool
     */
    public function replace_etiquetas_articulo($codtarifa, $referencia, $etiquetas)
    {
        $normalized = [];
        foreach ((array) $etiquetas as $etiqueta) {
            $tag = trim((string) $etiqueta);
            if ('' === $tag) {
                continue;
            }
            $normalized[$tag] = true;
        }

        // Delete + inserts must land atomically: a partial rewrite would leave
        // the article without its previous tags. Each statement runs inside the
        // outer transaction ($transaction = false) so nothing auto-commits.
        $this->db->begin_transaction();

        if (!$this->delete_all_from_articulo_tarifa($codtarifa, $referencia, false)) {
            $this->db->rollback();
            return false;
        }

        foreach (array_keys($normalized) as $tag) {
            if (!$this->add($codtarifa, $referencia, $tag, false)) {
                $this->db->rollback();
                return false;
            }
        }

        $this->db->commit();

        return true;
    }

    public function exists()
    {
        if (is_null($this->codtarifa) || is_null($this->referencia) || is_null($this->etiqueta)) {
            return FALSE;
        }

        return $this->exists_relation($this->codtarifa, $this->referencia, $this->etiqueta);
    }

    public function save()
    {
        return $this->add($this->codtarifa, $this->referencia, $this->etiqueta);
    }

    public function delete()
    {
        return $this->remove($this->codtarifa, $this->referencia, $this->etiqueta);
    }
}
