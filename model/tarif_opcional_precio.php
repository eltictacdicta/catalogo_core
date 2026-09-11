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

require_once 'plugins/catalogo_core/model/core/catalogo_opcional.php';
require_once 'plugins/catalogo_core/model/core/catalogo_opcional_familia.php';

/**
 * Precio de un opcional en una tarifa específica.
 * Relaciona opcionales con tarifas asignando un precio fijo y estado en catálogo.
 */
class tarif_opcional_precio extends \fs_model
{
    /**
     * ID del opcional. Clave primaria compuesta.
     * @var int
     */
    public $id_opcional;

    /**
     * Código de la tarifa. Clave primaria compuesta.
     * @var string
     */
    public $codtarifa;

    /**
     * Precio del opcional en esta tarifa.
     * @var double
     */
    public $precio;

    /**
     * Indica si el opcional está visible en el catálogo de esta tarifa.
     * @var boolean
     */
    public $en_catalogo;

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_opcional_precios');
        
        if ($data) {
            $this->id_opcional = intval($data['id_opcional']);
            $this->codtarifa = $data['codtarifa'];
            $this->precio = floatval($data['precio']);
            $this->en_catalogo = isset($data['en_catalogo']) ? $this->str2bool($data['en_catalogo']) : false;
        } else {
            $this->id_opcional = NULL;
            $this->codtarifa = NULL;
            $this->precio = 0.0;
            $this->en_catalogo = false;
        }
    }

    protected function install()
    {
        // Forzar que se cree la tabla tarif_tarifas primero
        new tarif_tarifa();
        return '';
    }

    public function url()
    {
        if (is_null($this->id_opcional)) {
            return "index.php?page=tarif_opcionales";
        }
        return "index.php?page=tarif_opcional_edit&id=" . $this->id_opcional;
    }

    /**
     * Obtiene un precio específico de opcional+tarifa.
     * @param int $id_opcional ID del opcional
     * @param string $codtarifa Código de la tarifa
     * @return tarif_opcional_precio|false
     */
    public function get($id_opcional, $codtarifa)
    {
        $sql = "SELECT * FROM " . $this->table_name 
            . " WHERE id_opcional = " . $this->intval($id_opcional) 
            . " AND codtarifa = " . $this->var2str($codtarifa) . ";";
        $data = $this->db->select($sql);
        if ($data) {
            return new tarif_opcional_precio($data[0]);
        }
        return FALSE;
    }

    public function exists()
    {
        if (is_null($this->id_opcional) || is_null($this->codtarifa)) {
            return FALSE;
        }
        $sql = "SELECT * FROM " . $this->table_name 
            . " WHERE id_opcional = " . $this->intval($this->id_opcional) 
            . " AND codtarifa = " . $this->var2str($this->codtarifa) . ";";
        return $this->db->select($sql);
    }

    public function test()
    {
        $this->codtarifa = $this->no_html(trim($this->codtarifa));

        if (is_null($this->id_opcional) || $this->id_opcional < 1) {
            $this->new_error_msg("ID de opcional no válido.");
            return FALSE;
        }

        if (mb_strlen($this->codtarifa) < 1 || mb_strlen($this->codtarifa) > 20) {
            $this->new_error_msg("Código de tarifa no válido.");
            return FALSE;
        }

        if ($this->precio < 0) {
            $this->new_error_msg("El precio no puede ser negativo.");
            return FALSE;
        }

        return TRUE;
    }

    public function save()
    {
        if ($this->test()) {
            $precio_anterior = 0.0;
            $es_update = $this->exists();
            
            if ($es_update) {
                $actual = $this->get($this->id_opcional, $this->codtarifa);
                if ($actual) {
                    $precio_anterior = $actual->precio;
                }
                
                $sql = "UPDATE " . $this->table_name . " SET "
                    . "precio = " . $this->var2str($this->precio) . ","
                    . "en_catalogo = " . $this->var2str($this->en_catalogo)
                    . " WHERE id_opcional = " . $this->intval($this->id_opcional) 
                    . " AND codtarifa = " . $this->var2str($this->codtarifa) . ";";
            } else {
                $sql = "INSERT INTO " . $this->table_name . " (id_opcional, codtarifa, precio, en_catalogo) VALUES ("
                    . $this->intval($this->id_opcional) . ","
                    . $this->var2str($this->codtarifa) . ","
                    . $this->var2str($this->precio) . ","
                    . $this->var2str($this->en_catalogo) . ");";
            }

            $result = $this->db->exec($sql);
            
            if ($result && abs($precio_anterior - $this->precio) >= 0.0001) {
                $coreLog = new \fs_core_log();
                $usuario = $coreLog->user_nick() ?: null;
                tarif_opcional_precio_historial::registrar_cambio(
                    $this->id_opcional,
                    $this->codtarifa,
                    $precio_anterior,
                    $this->precio,
                    $usuario
                );
            }
            
            return $result;
        }
        return FALSE;
    }

    public function delete()
    {
        $sql = "DELETE FROM " . $this->table_name 
            . " WHERE id_opcional = " . $this->intval($this->id_opcional) 
            . " AND codtarifa = " . $this->var2str($this->codtarifa) . ";";
        return $this->db->exec($sql);
    }

    /**
     * Devuelve todos los precios de un opcional en todas las tarifas.
     * @param int $id_opcional ID del opcional
     * @return array
     */
    public function all_from_opcional($id_opcional)
    {
        $list = [];
        $sql = "SELECT * FROM " . $this->table_name 
            . " WHERE id_opcional = " . $this->intval($id_opcional) 
            . " ORDER BY codtarifa ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_opcional_precio($d);
            }
        }
        return $list;
    }

    /**
     * Devuelve todos los precios de opcionales para una tarifa específica.
     * @param string $codtarifa Código de la tarifa
     * @return array
     */
    public function all_from_tarifa($codtarifa)
    {
        $list = [];
        $sql = "SELECT * FROM " . $this->table_name 
            . " WHERE codtarifa = " . $this->var2str($codtarifa) 
            . " ORDER BY id_opcional ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_opcional_precio($d);
            }
        }
        return $list;
    }

    /**
     * Elimina todos los precios de un opcional.
     * @param int $id_opcional ID del opcional
     * @return boolean
     */
    public function delete_from_opcional($id_opcional)
    {
        $sql = "DELETE FROM " . $this->table_name 
            . " WHERE id_opcional = " . $this->intval($id_opcional) . ";";
        return $this->db->exec($sql);
    }

    /**
     * Elimina todos los precios de una tarifa.
     * @param string $codtarifa Código de la tarifa
     * @return boolean
     */
    public function delete_from_tarifa($codtarifa)
    {
        $sql = "DELETE FROM " . $this->table_name 
            . " WHERE codtarifa = " . $this->var2str($codtarifa) . ";";
        return $this->db->exec($sql);
    }

    /**
     * Devuelve todos los registros.
     * @return array
     */
    public function all()
    {
        $list = [];
        $sql = "SELECT * FROM " . $this->table_name . " ORDER BY id_opcional ASC, codtarifa ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_opcional_precio($d);
            }
        }
        return $list;
    }

    /**
     * Establece el precio de un opcional en una tarifa.
     * Crea o actualiza el registro según corresponda.
     * @param int $id_opcional ID del opcional
     * @param string $codtarifa Código de la tarifa
     * @param float $precio Precio a establecer
     * @return boolean
     */
    public function set_precio($id_opcional, $codtarifa, $precio)
    {
        $obj = $this->get($id_opcional, $codtarifa);
        if (!$obj) {
            $obj = new tarif_opcional_precio();
            $obj->id_opcional = $id_opcional;
            $obj->codtarifa = $codtarifa;
        }
        $obj->precio = floatval($precio);
        return $obj->save();
    }

    /**
     * Elimina el precio de un opcional en una tarifa específica.
     * @param int $id_opcional ID del opcional
     * @param string $codtarifa Código de la tarifa
     * @return boolean
     */
    public function delete_precio($id_opcional, $codtarifa)
    {
        $sql = "DELETE FROM " . $this->table_name 
            . " WHERE id_opcional = " . $this->intval($id_opcional) 
            . " AND codtarifa = " . $this->var2str($codtarifa) . ";";
        return $this->db->exec($sql);
    }

    /**
     * Aplica un porcentaje de cambio a múltiples opcionales en una tarifa.
     * Registra automáticamente los cambios en el historial.
     * 
     * @param string $codtarifa Código de la tarifa
     * @param array $ids_opcionales Array de IDs de opcionales
     * @param float $porcentaje Porcentaje a aplicar (positivo = subida, negativo = bajada)
     * @param string|null $usuario Nick del usuario que realiza el cambio
     * @return array ['actualizados' => int, 'errores' => int, 'detalles' => array]
     */
    public function aplicar_porcentaje_masivo($codtarifa, $ids_opcionales, $porcentaje, $usuario = null)
    {
        $resultado = [
            'actualizados' => 0,
            'errores' => 0,
            'detalles' => []
        ];

        if (empty($ids_opcionales) || empty($codtarifa)) {
            return $resultado;
        }

        $factor = 1 + ($porcentaje / 100);

        foreach ($ids_opcionales as $id_opcional) {
            $precio = $this->get($id_opcional, $codtarifa);
            if ($precio) {
                $precio_anterior = $precio->precio;
                $precio_nuevo = round($precio_anterior * $factor, 2);
                $precio->precio = $precio_nuevo;

                if ($precio->save()) {
                    $resultado['actualizados']++;
                    $resultado['detalles'][] = [
                        'id_opcional' => $id_opcional,
                        'precio_anterior' => $precio_anterior,
                        'precio_nuevo' => $precio_nuevo,
                        'status' => 'ok'
                    ];
                } else {
                    $resultado['errores']++;
                    $resultado['detalles'][] = [
                        'id_opcional' => $id_opcional,
                        'precio_anterior' => $precio_anterior,
                        'precio_nuevo' => $precio_nuevo,
                        'status' => 'error'
                    ];
                }
            } else {
                $resultado['errores']++;
                $resultado['detalles'][] = [
                    'id_opcional' => $id_opcional,
                    'precio_anterior' => null,
                    'precio_nuevo' => null,
                    'status' => 'not_found'
                ];
            }
        }

        return $resultado;
    }

    /**
     * Obtiene opcionales con precios en una tarifa filtrados por familias.
     * 
     * @param string $codtarifa Código de la tarifa
     * @param array $familias Array de códigos de familia (vacío = todas)
     * @param int $offset Offset para paginación
     * @param int $limit Límite de resultados
     * @return array
     */
    public function all_by_familias($codtarifa, $familias = [], $offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $list = [];
        
        $sql = "SELECT op.*, o.nombre, o.codigo FROM " . $this->table_name . " op "
            . "INNER JOIN " . catalogo_opcional::TABLE . " o ON op.id_opcional = o.id "
            . "WHERE op.codtarifa = " . $this->var2str($codtarifa);
        
        if (!empty($familias)) {
            $familias_sql = implode(',', array_map(function($f) {
                return $this->var2str($f);
            }, $familias));
            $sql .= " AND EXISTS (SELECT 1 FROM " . catalogo_opcional_familia::TABLE . " tof WHERE tof.id_opcional = op.id_opcional AND tof.codfamilia IN ($familias_sql))";
        }

        $sql .= " ORDER BY o.codigo ASC";

        $data = $this->db->select_limit($sql, $limit, $offset);
        if ($data) {
            foreach ($data as $d) {
                $item = new tarif_opcional_precio($d);
                $item->nombre = $d['nombre'];
                $item->codigo = $d['codigo'];
                $list[] = $item;
            }
        }
        
        return $list;
    }

    /**
     * Cuenta opcionales con precios en una tarifa filtrados por familias.
     * 
     * @param string $codtarifa Código de la tarifa
     * @param array $familias Array de códigos de familia (vacío = todas)
     * @return int
     */
    public function count_by_familias($codtarifa, $familias = [])
    {
        $sql = "SELECT COUNT(*) as total FROM " . $this->table_name . " op "
            . 'INNER JOIN ' . catalogo_opcional::TABLE . ' o ON op.id_opcional = o.id '
            . "WHERE op.codtarifa = " . $this->var2str($codtarifa);
        
        if (!empty($familias)) {
            $familias_sql = implode(',', array_map(function($f) {
                return $this->var2str($f);
            }, $familias));
            $sql .= " AND EXISTS (SELECT 1 FROM " . catalogo_opcional_familia::TABLE . " tof WHERE tof.id_opcional = op.id_opcional AND tof.codfamilia IN ($familias_sql))";
        }
        
        $data = $this->db->select($sql);
        return $data ? intval($data[0]['total']) : 0;
    }
}
