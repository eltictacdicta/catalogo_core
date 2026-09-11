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
 * Historial de cambios de precios de opcionales.
 * Registra cada modificación de precio con fecha, usuario y valores anterior/nuevo.
 */
class tarif_opcional_precio_historial extends \fs_model
{
    /**
     * ID del registro (autoincremental).
     * @var int
     */
    public $id;

    /**
     * ID del opcional.
     * @var int
     */
    public $id_opcional;

    /**
     * Código de la tarifa.
     * @var string
     */
    public $codtarifa;

    /**
     * Precio antes del cambio.
     * @var float
     */
    public $precio_anterior;

    /**
     * Precio después del cambio.
     * @var float
     */
    public $precio_nuevo;

    /**
     * Fecha y hora del cambio.
     * @var string
     */
    public $fecha_cambio;

    /**
     * Usuario que realizó el cambio.
     * @var string|null
     */
    public $usuario;

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_opcional_precio_historial');

        if ($data) {
            $this->id = intval($data['id']);
            $this->id_opcional = intval($data['id_opcional']);
            $this->codtarifa = $data['codtarifa'];
            $this->precio_anterior = floatval($data['precio_anterior']);
            $this->precio_nuevo = floatval($data['precio_nuevo']);
            $this->fecha_cambio = $data['fecha_cambio'];
            $this->usuario = $data['usuario'] ?? null;
        } else {
            $this->id = NULL;
            $this->id_opcional = NULL;
            $this->codtarifa = NULL;
            $this->precio_anterior = 0.0;
            $this->precio_nuevo = 0.0;
            $this->fecha_cambio = date('Y-m-d H:i:s');
            $this->usuario = NULL;
        }
    }

    protected function install()
    {
        // seed_if_empty() re-ejecuta install() siempre que la tabla esté vacía, por lo
        // que cualquier DDL devuelto aquí debe ser idempotente: crear solo los índices
        // que aún no existen.
        $existing = [];
        if ($this->db->table_exists($this->table_name)) {
            foreach ($this->db->get_indexes($this->table_name) as $idx) {
                $existing[] = $idx['name'] ?? '';
            }
        }

        $indexes = [
            'tarif_opcional_precio_historial_idx_fecha' => '(fecha_cambio DESC)',
            'tarif_opcional_precio_historial_idx_opcional' => '(id_opcional)',
            'tarif_opcional_precio_historial_idx_tarifa' => '(codtarifa)',
        ];

        $sql = '';
        foreach ($indexes as $name => $columns) {
            if (!in_array($name, $existing, true)) {
                $sql .= 'CREATE INDEX ' . $name . ' ON ' . $this->table_name . ' ' . $columns . ';';
            }
        }

        return $sql;
    }

    public function url()
    {
        return "index.php?page=tarif_historial_precios&tipo=opcionales";
    }

    public function exists()
    {
        if (is_null($this->id)) {
            return FALSE;
        }
        $sql = "SELECT id FROM " . $this->table_name . " WHERE id = " . $this->intval($this->id) . ";";
        return $this->db->select($sql);
    }

    public function test()
    {
        $this->codtarifa = $this->no_html(trim($this->codtarifa));
        if ($this->usuario) {
            $this->usuario = $this->no_html(trim($this->usuario));
        }

        if (is_null($this->id_opcional) || $this->id_opcional < 1) {
            $this->new_error_msg("ID de opcional no válido.");
            return FALSE;
        }

        if (mb_strlen($this->codtarifa) < 1) {
            $this->new_error_msg("Código de tarifa no válido.");
            return FALSE;
        }

        return TRUE;
    }

    public function save()
    {
        if ($this->test()) {
            if ($this->exists()) {
                $sql = "UPDATE " . $this->table_name . " SET "
                    . "id_opcional = " . $this->intval($this->id_opcional) . ","
                    . "codtarifa = " . $this->var2str($this->codtarifa) . ","
                    . "precio_anterior = " . $this->var2str($this->precio_anterior) . ","
                    . "precio_nuevo = " . $this->var2str($this->precio_nuevo) . ","
                    . "fecha_cambio = " . $this->var2str($this->fecha_cambio) . ","
                    . "usuario = " . $this->var2str($this->usuario)
                    . " WHERE id = " . $this->intval($this->id) . ";";
                return $this->db->exec($sql);
            } else {
                $sql = "INSERT INTO " . $this->table_name 
                    . " (id_opcional, codtarifa, precio_anterior, precio_nuevo, fecha_cambio, usuario) VALUES ("
                    . $this->intval($this->id_opcional) . ","
                    . $this->var2str($this->codtarifa) . ","
                    . $this->var2str($this->precio_anterior) . ","
                    . $this->var2str($this->precio_nuevo) . ","
                    . $this->var2str($this->fecha_cambio) . ","
                    . $this->var2str($this->usuario) . ");";
                
                if ($this->db->exec($sql)) {
                    $this->id = $this->db->lastval();
                    return TRUE;
                }
            }
        }
        return FALSE;
    }

    public function delete()
    {
        $sql = "DELETE FROM " . $this->table_name . " WHERE id = " . $this->intval($this->id) . ";";
        return $this->db->exec($sql);
    }

    /**
     * Registra un cambio de precio.
     * 
     * @param int $id_opcional ID del opcional
     * @param string $codtarifa Código de la tarifa
     * @param float $precio_anterior Precio antes del cambio
     * @param float $precio_nuevo Precio después del cambio
     * @param string|null $usuario Usuario que realizó el cambio
     * @return bool
     */
    public static function registrar_cambio($id_opcional, $codtarifa, $precio_anterior, $precio_nuevo, $usuario = null)
    {
        if (abs($precio_anterior - $precio_nuevo) < 0.0001) {
            return TRUE;
        }

        $historial = new self();
        $historial->id_opcional = $id_opcional;
        $historial->codtarifa = $codtarifa;
        $historial->precio_anterior = $precio_anterior;
        $historial->precio_nuevo = $precio_nuevo;
        $historial->usuario = $usuario;
        return $historial->save();
    }

    /**
     * Obtiene el historial de cambios de un opcional.
     * 
     * @param int $id_opcional ID del opcional
     * @param string|null $codtarifa Código de tarifa (opcional)
     * @param int $limit Límite de registros
     * @return array
     */
    public function all_from_opcional($id_opcional, $codtarifa = null, $limit = 50)
    {
        $list = [];
        $sql = "SELECT * FROM " . $this->table_name
            . " WHERE id_opcional = " . $this->intval($id_opcional);
        
        if ($codtarifa) {
            $sql .= " AND codtarifa = " . $this->var2str($codtarifa);
        }
        
        $sql .= " ORDER BY fecha_cambio DESC LIMIT " . intval($limit) . ";";
        
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_opcional_precio_historial($d);
            }
        }
        return $list;
    }

    /**
     * Obtiene cambios de precio en un rango de fechas.
     * 
     * @param string $fecha_desde Fecha inicio (Y-m-d)
     * @param string $fecha_hasta Fecha fin (Y-m-d)
     * @param string|null $codtarifa Código de tarifa (opcional)
     * @param int $offset Offset para paginación
     * @param int $limit Límite de registros
     * @return array
     */
    public function all_by_fecha($fecha_desde, $fecha_hasta, $codtarifa = null, $offset = 0, $limit = 50)
    {
        $list = [];
        
        if (!$this->db->table_exists($this->table_name)) {
            return $list;
        }
        
        $sql = "SELECT * FROM " . $this->table_name
            . " WHERE fecha_cambio >= " . $this->var2str($fecha_desde . ' 00:00:00')
            . " AND fecha_cambio <= " . $this->var2str($fecha_hasta . ' 23:59:59');
        
        if ($codtarifa) {
            $sql .= " AND codtarifa = " . $this->var2str($codtarifa);
        }

        $sql .= " ORDER BY fecha_cambio DESC";

        $data = $this->db->select_limit($sql, $limit, $offset);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_opcional_precio_historial($d);
            }
        }
        return $list;
    }

    /**
     * Cuenta cambios de precio en un rango de fechas.
     * 
     * @param string $fecha_desde Fecha inicio (Y-m-d)
     * @param string $fecha_hasta Fecha fin (Y-m-d)
     * @param string|null $codtarifa Código de tarifa (opcional)
     * @return int
     */
    public function count_by_fecha($fecha_desde, $fecha_hasta, $codtarifa = null)
    {
        if (!$this->db->table_exists($this->table_name)) {
            return 0;
        }
        
        $sql = "SELECT COUNT(*) as total FROM " . $this->table_name
            . " WHERE fecha_cambio >= " . $this->var2str($fecha_desde . ' 00:00:00')
            . " AND fecha_cambio <= " . $this->var2str($fecha_hasta . ' 23:59:59');
        
        if ($codtarifa) {
            $sql .= " AND codtarifa = " . $this->var2str($codtarifa);
        }
        
        $data = $this->db->select($sql);
        if ($data) {
            return intval($data[0]['total']);
        }
        return 0;
    }

    /**
     * Obtiene estadísticas de cambios de precio.
     * 
     * @param string $fecha_desde Fecha inicio
     * @param string $fecha_hasta Fecha fin
     * @param string|null $codtarifa Código de tarifa
     * @return array
     */
    public function get_estadisticas($fecha_desde, $fecha_hasta, $codtarifa = null)
    {
        if (!$this->db->table_exists($this->table_name)) {
            return [
                'total_cambios' => 0,
                'opcionales_afectados' => 0,
                'subidas' => 0,
                'bajadas' => 0
            ];
        }
        
        $where = " WHERE fecha_cambio >= " . $this->var2str($fecha_desde . ' 00:00:00')
            . " AND fecha_cambio <= " . $this->var2str($fecha_hasta . ' 23:59:59');
        
        if ($codtarifa) {
            $where .= " AND codtarifa = " . $this->var2str($codtarifa);
        }

        $sql = "SELECT 
                COUNT(*) as total_cambios,
                COUNT(DISTINCT id_opcional) as opcionales_afectados,
                SUM(CASE WHEN precio_nuevo > precio_anterior THEN 1 ELSE 0 END) as subidas,
                SUM(CASE WHEN precio_nuevo < precio_anterior THEN 1 ELSE 0 END) as bajadas
            FROM " . $this->table_name . $where . ";";
        
        $data = $this->db->select($sql);
        if ($data) {
            return [
                'total_cambios' => intval($data[0]['total_cambios']),
                'opcionales_afectados' => intval($data[0]['opcionales_afectados']),
                'subidas' => intval($data[0]['subidas']),
                'bajadas' => intval($data[0]['bajadas'])
            ];
        }
        
        return [
            'total_cambios' => 0,
            'opcionales_afectados' => 0,
            'subidas' => 0,
            'bajadas' => 0
        ];
    }

    /**
     * Cuenta registros anteriores a una fecha/hora.
     * 
     * @param string $fecha_limite Fecha/hora límite
     * @return int
     */
    public function count_before($fecha_limite)
    {
        $sql = "SELECT COUNT(*) as total FROM " . $this->table_name
            . " WHERE fecha_cambio < " . $this->var2str($fecha_limite) . ";";
        $data = $this->db->select($sql);
        return $data ? intval($data[0]['total']) : 0;
    }

    /**
     * Elimina historial anterior a una fecha/hora.
     * 
     * @param string $fecha_limite Fecha/hora límite (se elimina lo anterior)
     * @return bool
     */
    public function purge_before($fecha_limite)
    {
        $sql = "DELETE FROM " . $this->table_name
            . " WHERE fecha_cambio < " . $this->var2str($fecha_limite) . ";";
        return $this->db->exec($sql);
    }

    /**
     * Elimina historial en un rango de fechas y tarifa.
     * Usa los mismos filtros que all_by_fecha/count_by_fecha.
     * 
     * @param string $fecha_desde Fecha inicio (Y-m-d)
     * @param string $fecha_hasta Fecha fin (Y-m-d)
     * @param string|null $codtarifa Código de tarifa (opcional)
     * @return bool
     */
    public function purge_by_fecha($fecha_desde, $fecha_hasta, $codtarifa = null)
    {
        $sql = "DELETE FROM " . $this->table_name
            . " WHERE fecha_cambio >= " . $this->var2str($fecha_desde . ' 00:00:00')
            . " AND fecha_cambio <= " . $this->var2str($fecha_hasta . ' 23:59:59');

        if ($codtarifa) {
            $sql .= " AND codtarifa = " . $this->var2str($codtarifa);
        }

        return $this->db->exec($sql . ";");
    }
}
