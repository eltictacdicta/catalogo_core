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
 * Relación opcional-familia específica por tarifa.
 * Hereda de catalogo_opcional_familias (tabla base global).
 * 
 * Lógica de herencia:
 * - Si una relación existe en catalogo_opcional_familias (base) pero NO aquí, se considera ACTIVA
 * - Si existe aquí con activo=TRUE, la relación está activa para esa tarifa
 * - Si existe aquí con activo=FALSE, la relación está desactivada para esa tarifa
 */
class tarif_tarifa_opcional_familia extends \fs_model
{
    /**
     * Código de tarifa.
     * @var string
     */
    public $codtarifa;

    /**
     * ID del opcional.
     * @var int
     */
    public $id_opcional;

    /**
     * Código de la familia.
     * @var string
     */
    public $codfamilia;

    /**
     * Indica si la relación está activa en esta tarifa.
     * @var bool
     */
    public $activo;

    /**
     * Orden del opcional dentro de la familia para esta tarifa.
     * @var int
     */
    public $orden;

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_tarifa_opcional_familia');

        if ($data) {
            $this->codtarifa = $data['codtarifa'];
            $this->id_opcional = intval($data['id_opcional']);
            $this->codfamilia = $data['codfamilia'];
            $this->activo = $this->str2bool($data['activo']);
            $this->orden = isset($data['orden']) ? intval($data['orden']) : 0;
        } else {
            $this->codtarifa = NULL;
            $this->id_opcional = NULL;
            $this->codfamilia = NULL;
            $this->activo = TRUE;
            $this->orden = 0;
        }
    }

    protected function install()
    {
        // Forzar la creación de tablas dependientes
        new tarif_tarifa();
        new catalogo_opcional_familia();
        return '';
    }

    /**
     * Obtiene una relación específica.
     * @param string $codtarifa
     * @param int $id_opcional
     * @param string $codfamilia
     * @return tarif_tarifa_opcional_familia|false
     */
    public function get($codtarifa, $id_opcional, $codfamilia)
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND id_opcional = " . $this->intval($id_opcional)
            . " AND codfamilia = " . $this->var2str($codfamilia) . ";");
        
        if ($data) {
            return new self($data[0]);
        }
        return FALSE;
    }

    /**
     * Verifica si una relación está activa en una tarifa.
     * Aplica la lógica de herencia: si no hay registro específico, hereda de la tabla base.
     * 
     * @param string $codtarifa
     * @param int $id_opcional
     * @param string $codfamilia
     * @return bool
     */
    public function is_activo_en_tarifa($codtarifa, $id_opcional, $codfamilia)
    {
        // Primero verificar si existe en la tabla base
        $base_model = new catalogo_opcional_familia();
        if (!$base_model->exists_relation($id_opcional, $codfamilia)) {
            return FALSE; // No existe la relación base
        }

        // Buscar registro específico de tarifa
        $data = $this->db->select("SELECT activo FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND id_opcional = " . $this->intval($id_opcional)
            . " AND codfamilia = " . $this->var2str($codfamilia) . ";");
        
        if ($data) {
            return $this->str2bool($data[0]['activo']);
        }

        // No hay registro específico, heredar de base (activo por defecto)
        return TRUE;
    }

    /**
     * Activa o desactiva una relación para una tarifa específica.
     * @param string $codtarifa
     * @param int $id_opcional
     * @param string $codfamilia
     * @param bool $activo
     * @param int $orden Orden del opcional (por defecto 0)
     * @return bool
     */
    public function set_activo($codtarifa, $id_opcional, $codfamilia, $activo, $orden = 0)
    {
        $existing = $this->get($codtarifa, $id_opcional, $codfamilia);
        
        if ($existing) {
            // Actualizar registro existente
            return $this->db->exec("UPDATE " . $this->table_name 
                . " SET activo = " . $this->var2str($activo)
                . ", orden = " . $this->intval($orden)
                . " WHERE codtarifa = " . $this->var2str($codtarifa)
                . " AND id_opcional = " . $this->intval($id_opcional)
                . " AND codfamilia = " . $this->var2str($codfamilia) . ";");
        } else {
            // Crear nuevo registro
            return $this->db->exec("INSERT INTO " . $this->table_name 
                . " (codtarifa, id_opcional, codfamilia, activo, orden) VALUES ("
                . $this->var2str($codtarifa) . ","
                . $this->intval($id_opcional) . ","
                . $this->var2str($codfamilia) . ","
                . $this->var2str($activo) . ","
                . $this->intval($orden) . ");");
        }
    }

    /**
     * Devuelve los opcionales ACTIVOS de una familia para una tarifa específica.
     * Aplica herencia: incluye los de la base que no estén desactivados específicamente.
     * Ordena por el campo orden de la tabla por tarifa, o por nombre si no hay orden específico.
     * 
     * @param string $codtarifa
     * @param string $codfamilia
     * @return array
     */
    public function get_opcionales_activos($codtarifa, $codfamilia)
    {
        $list = [];
        
        $sql = "SELECT o.*, COALESCE(tof.orden, 999999) as orden_tarifa FROM catalogo_opcionales o"
            . " INNER JOIN catalogo_opcional_familias of2 ON o.id = of2.id_opcional"
            . " LEFT JOIN " . $this->table_name . " tof ON tof.codtarifa = " . $this->var2str($codtarifa)
            . "   AND tof.id_opcional = of2.id_opcional"
            . "   AND tof.codfamilia = of2.codfamilia"
            . " WHERE of2.codfamilia = " . $this->var2str($codfamilia)
            . " AND (tof.activo IS NULL OR tof.activo = TRUE)"
            . " ORDER BY orden_tarifa ASC, o.nombre ASC;";
        
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_opcional($d);
            }
        }
        return $list;
    }

    /**
     * Devuelve las familias ACTIVAS de un opcional para una tarifa específica.
     * Aplica herencia: incluye las de la base que no estén desactivadas específicamente.
     * 
     * @param string $codtarifa
     * @param int $id_opcional
     * @return array
     */
    public function get_familias_activas($codtarifa, $id_opcional)
    {
        $list = [];
        
        $sql = "SELECT f.* FROM familias f"
            . " INNER JOIN catalogo_opcional_familias of ON f.codfamilia = of.codfamilia"
            . " WHERE of.id_opcional = " . $this->intval($id_opcional)
            . " AND NOT EXISTS ("
            . "   SELECT 1 FROM " . $this->table_name . " tof"
            . "   WHERE tof.codtarifa = " . $this->var2str($codtarifa)
            . "   AND tof.id_opcional = of.id_opcional"
            . "   AND tof.codfamilia = of.codfamilia"
            . "   AND tof.activo = FALSE"
            . " )"
            . " ORDER BY f.descripcion ASC;";
        
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_familia($d);
            }
        }
        return $list;
    }

    /**
     * Devuelve los IDs de opcionales activos para una familia en una tarifa.
     * @param string $codtarifa
     * @param string $codfamilia
     * @return array
     */
    public function get_ids_opcionales_activos($codtarifa, $codfamilia)
    {
        $ids = [];
        
        $sql = "SELECT of.id_opcional FROM catalogo_opcional_familias of"
            . " WHERE of.codfamilia = " . $this->var2str($codfamilia)
            . " AND NOT EXISTS ("
            . "   SELECT 1 FROM " . $this->table_name . " tof"
            . "   WHERE tof.codtarifa = " . $this->var2str($codtarifa)
            . "   AND tof.id_opcional = of.id_opcional"
            . "   AND tof.codfamilia = of.codfamilia"
            . "   AND tof.activo = FALSE"
            . " );";
        
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $ids[] = intval($d['id_opcional']);
            }
        }
        return $ids;
    }

    /**
     * Copia las configuraciones de relaciones de una tarifa a otra.
     * @param string $codtarifa_origen
     * @param string $codtarifa_destino
     * @return bool
     */
    public function copy_from_tarifa($codtarifa_origen, $codtarifa_destino)
    {
        // Primero eliminar las existentes en destino
        $this->db->exec("DELETE FROM " . $this->table_name 
            . " WHERE codtarifa = " . $this->var2str($codtarifa_destino) . ";");
        
        // Copiar desde origen (incluyendo orden)
        return $this->db->exec("INSERT INTO " . $this->table_name 
            . " (codtarifa, id_opcional, codfamilia, activo, orden)"
            . " SELECT " . $this->var2str($codtarifa_destino) . ", id_opcional, codfamilia, activo, orden"
            . " FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa_origen) . ";");
    }

    /**
     * Elimina todas las configuraciones de una tarifa.
     * @param string $codtarifa
     * @return bool
     */
    public function delete_all_from_tarifa($codtarifa)
    {
        return $this->db->exec("DELETE FROM " . $this->table_name 
            . " WHERE codtarifa = " . $this->var2str($codtarifa) . ";");
    }

    /**
     * Elimina todas las configuraciones de un opcional.
     * @param int $id_opcional
     * @return bool
     */
    public function delete_all_from_opcional($id_opcional)
    {
        return $this->db->exec("DELETE FROM " . $this->table_name 
            . " WHERE id_opcional = " . $this->intval($id_opcional) . ";");
    }

    /**
     * Elimina todas las configuraciones de una familia.
     * @param string $codfamilia
     * @return bool
     */
    public function delete_all_from_familia($codfamilia)
    {
        return $this->db->exec("DELETE FROM " . $this->table_name 
            . " WHERE codfamilia = " . $this->var2str($codfamilia) . ";");
    }

    public function exists()
    {
        if (is_null($this->codtarifa) || is_null($this->id_opcional) || is_null($this->codfamilia)) {
            return FALSE;
        }
        
        $data = $this->db->select("SELECT * FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
            . " AND id_opcional = " . $this->intval($this->id_opcional)
            . " AND codfamilia = " . $this->var2str($this->codfamilia) . ";");
        
        return ($data && count($data) > 0);
    }

    public function save()
    {
        if ($this->exists()) {
            return $this->db->exec("UPDATE " . $this->table_name . " SET "
                . "activo = " . $this->var2str($this->activo)
                . ", orden = " . $this->intval($this->orden)
                . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
                . " AND id_opcional = " . $this->intval($this->id_opcional)
                . " AND codfamilia = " . $this->var2str($this->codfamilia) . ";");
        } else {
            return $this->db->exec("INSERT INTO " . $this->table_name 
                . " (codtarifa, id_opcional, codfamilia, activo, orden) VALUES ("
                . $this->var2str($this->codtarifa) . ","
                . $this->intval($this->id_opcional) . ","
                . $this->var2str($this->codfamilia) . ","
                . $this->var2str($this->activo) . ","
                . $this->intval($this->orden) . ");");
        }
    }

    public function delete()
    {
        return $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
            . " AND id_opcional = " . $this->intval($this->id_opcional)
            . " AND codfamilia = " . $this->var2str($this->codfamilia) . ";");
    }
}
