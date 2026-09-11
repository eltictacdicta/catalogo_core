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
 * Relación artículo-opcional específica por tarifa.
 * Hereda de catalogo_articulo_opcional (tabla base global).
 * 
 * Lógica de herencia:
 * - Si una relación existe en catalogo_articulo_opcional (base) pero NO aquí, se considera ACTIVA
 * - Si existe aquí con activo=TRUE, la relación está activa para esa tarifa
 * - Si existe aquí con activo=FALSE, la relación está desactivada para esa tarifa
 */
class tarif_tarifa_articulo_opcional extends \fs_model
{
    /**
     * Código de tarifa.
     * @var string
     */
    public $codtarifa;

    /**
     * Referencia del artículo.
     * @var string
     */
    public $referencia;

    /**
     * ID del opcional.
     * @var int
     */
    public $id_opcional;

    /**
     * Indica si la relación está activa en esta tarifa.
     * @var bool
     */
    public $activo;

    /**
     * Orden del opcional dentro del artículo para esta tarifa.
     * @var int
     */
    public $orden;

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_tarifa_articulo_opcional');

        if ($data) {
            $this->codtarifa = $data['codtarifa'];
            $this->referencia = $data['referencia'];
            $this->id_opcional = intval($data['id_opcional']);
            $this->activo = $this->str2bool($data['activo']);
            $this->orden = isset($data['orden']) ? intval($data['orden']) : 0;
        } else {
            $this->codtarifa = NULL;
            $this->referencia = NULL;
            $this->id_opcional = NULL;
            $this->activo = TRUE;
            $this->orden = 0;
        }
    }

    protected function install()
    {
        // Forzar la creación de tablas dependientes
        new tarif_tarifa();
        new catalogo_articulo_opcional();
        return '';
    }

    /**
     * Obtiene una relación específica.
     * @param string $codtarifa
     * @param string $referencia
     * @param int $id_opcional
     * @return tarif_tarifa_articulo_opcional|false
     */
    public function get($codtarifa, $referencia, $id_opcional)
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND referencia = " . $this->var2str($referencia)
            . " AND id_opcional = " . $this->intval($id_opcional) . ";");
        
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
     * @param string $referencia
     * @param int $id_opcional
     * @return bool
     */
    public function is_activo_en_tarifa($codtarifa, $referencia, $id_opcional)
    {
        // Primero verificar si existe en la tabla base
        $base_model = new catalogo_articulo_opcional();
        if (!$base_model->exists_relation($referencia, $id_opcional)) {
            return FALSE; // No existe la relación base
        }

        // Buscar registro específico de tarifa
        $data = $this->db->select("SELECT activo FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND referencia = " . $this->var2str($referencia)
            . " AND id_opcional = " . $this->intval($id_opcional) . ";");
        
        if ($data) {
            return $this->str2bool($data[0]['activo']);
        }

        // No hay registro específico, heredar de base (activo por defecto)
        return TRUE;
    }

    /**
     * Activa o desactiva una relación para una tarifa específica.
     * @param string $codtarifa
     * @param string $referencia
     * @param int $id_opcional
     * @param bool $activo
     * @param int $orden Orden del opcional (por defecto 0)
     * @return bool
     */
    public function set_activo($codtarifa, $referencia, $id_opcional, $activo, $orden = 0)
    {
        $existing = $this->get($codtarifa, $referencia, $id_opcional);
        
        if ($existing) {
            // Actualizar registro existente
            return $this->db->exec("UPDATE " . $this->table_name 
                . " SET activo = " . $this->var2str($activo)
                . ", orden = " . $this->intval($orden)
                . " WHERE codtarifa = " . $this->var2str($codtarifa)
                . " AND referencia = " . $this->var2str($referencia)
                . " AND id_opcional = " . $this->intval($id_opcional) . ";");
        } else {
            // Crear nuevo registro
            return $this->db->exec("INSERT INTO " . $this->table_name 
                . " (codtarifa, referencia, id_opcional, activo, orden) VALUES ("
                . $this->var2str($codtarifa) . ","
                . $this->var2str($referencia) . ","
                . $this->intval($id_opcional) . ","
                . $this->var2str($activo) . ","
                . $this->intval($orden) . ");");
        }
    }

    /**
     * Devuelve los opcionales ACTIVOS de un artículo para una tarifa específica.
     * Aplica herencia: incluye los de la base que no estén desactivados específicamente.
     * Ordena por el campo orden de la tabla por tarifa, o por nombre si no hay orden específico.
     * 
     * @param string $codtarifa
     * @param string $referencia
     * @return array
     */
    public function get_opcionales_activos($codtarifa, $referencia)
    {
        $list = [];
        
        // Obtener todos los opcionales de la base para este artículo,
        // excluyendo los que están explícitamente desactivados en esta tarifa.
        // Usa COALESCE para obtener el orden de la tabla por tarifa, o 999999 si no existe
        $sql = "SELECT o.*, COALESCE(tao.orden, 999999) as orden_tarifa FROM catalogo_opcionales o"
            . " INNER JOIN catalogo_articulo_opcional ao ON o.id = ao.id_opcional"
            . " LEFT JOIN " . $this->table_name . " tao ON tao.codtarifa = " . $this->var2str($codtarifa)
            . "   AND tao.referencia = ao.referencia"
            . "   AND tao.id_opcional = ao.id_opcional"
            . " WHERE ao.referencia = " . $this->var2str($referencia)
            . " AND (tao.activo IS NULL OR tao.activo = TRUE)"
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
     * Devuelve los IDs de opcionales activos para un artículo en una tarifa.
     * @param string $codtarifa
     * @param string $referencia
     * @return array
     */
    public function get_ids_opcionales_activos($codtarifa, $referencia)
    {
        $ids = [];
        
        $sql = "SELECT ao.id_opcional FROM catalogo_articulo_opcional ao"
            . " WHERE ao.referencia = " . $this->var2str($referencia)
            . " AND NOT EXISTS ("
            . "   SELECT 1 FROM " . $this->table_name . " tao"
            . "   WHERE tao.codtarifa = " . $this->var2str($codtarifa)
            . "   AND tao.referencia = ao.referencia"
            . "   AND tao.id_opcional = ao.id_opcional"
            . "   AND tao.activo = FALSE"
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
            . " (codtarifa, referencia, id_opcional, activo, orden)"
            . " SELECT " . $this->var2str($codtarifa_destino) . ", referencia, id_opcional, activo, orden"
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
     * Elimina todas las configuraciones de un artículo.
     * @param string $referencia
     * @return bool
     */
    public function delete_all_from_articulo($referencia)
    {
        return $this->db->exec("DELETE FROM " . $this->table_name 
            . " WHERE referencia = " . $this->var2str($referencia) . ";");
    }

    /**
     * Cuenta cuántas relaciones tienen configuración específica de tarifa para un artículo.
     * @param string $codtarifa
     * @param string $referencia
     * @return int
     */
    public function count_config_tarifa($codtarifa, $referencia)
    {
        $data = $this->db->select("SELECT COUNT(*) as total FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND referencia = " . $this->var2str($referencia) . ";");
        
        if ($data) {
            return intval($data[0]['total']);
        }
        return 0;
    }

    public function exists()
    {
        if (is_null($this->codtarifa) || is_null($this->referencia) || is_null($this->id_opcional)) {
            return FALSE;
        }
        
        $data = $this->db->select("SELECT * FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
            . " AND referencia = " . $this->var2str($this->referencia)
            . " AND id_opcional = " . $this->intval($this->id_opcional) . ";");
        
        return ($data && count($data) > 0);
    }

    public function save()
    {
        if ($this->exists()) {
            return $this->db->exec("UPDATE " . $this->table_name . " SET "
                . "activo = " . $this->var2str($this->activo)
                . ", orden = " . $this->intval($this->orden)
                . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
                . " AND referencia = " . $this->var2str($this->referencia)
                . " AND id_opcional = " . $this->intval($this->id_opcional) . ";");
        } else {
            return $this->db->exec("INSERT INTO " . $this->table_name 
                . " (codtarifa, referencia, id_opcional, activo, orden) VALUES ("
                . $this->var2str($this->codtarifa) . ","
                . $this->var2str($this->referencia) . ","
                . $this->intval($this->id_opcional) . ","
                . $this->var2str($this->activo) . ","
                . $this->intval($this->orden) . ");");
        }
    }

    public function delete()
    {
        return $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
            . " AND referencia = " . $this->var2str($this->referencia)
            . " AND id_opcional = " . $this->intval($this->id_opcional) . ";");
    }
}
