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
 * Precio de un artículo en una tarifa específica.
 * Relaciona artículos con tarifas asignando un precio fijo, 
 * estado activo, inclusión en tarifa y visibilidad en catálogo.
 */
class tarif_articulo_precio extends \fs_model
{
    /**
     * Referencia del artículo. Clave primaria compuesta.
     * @var string
     */
    public $referencia;

    /**
     * Código de la tarifa. Clave primaria compuesta.
     * @var string
     */
    public $codtarifa;

    /**
     * Precio del artículo en esta tarifa.
     * @var double
     */
    public $precio;

    /**
     * Indica si el artículo está activo en esta tarifa.
     * Si es false, se mantiene solo para consulta histórica.
     * @var boolean
     */
    public $activo;

    /**
     * Indica si el artículo se incluye al exportar esta tarifa a clientes.
     * @var boolean
     */
    public $en_tarifa;

    /**
     * Indica si el artículo está visible en el catálogo de esta tarifa.
     * @var boolean
     */
    public $en_catalogo;

    /**
     * Indica si el artículo ha sido verificado con SAP.
     * @var boolean
     */
    public $en_sap;

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_articulo_precios');

        if ($data) {
            $this->referencia = $data['referencia'];
            $this->codtarifa = $data['codtarifa'];
            $this->precio = floatval($data['precio']);
            $this->activo = isset($data['activo']) ? $this->str2bool($data['activo']) : true;
            $this->en_tarifa = isset($data['en_tarifa']) ? $this->str2bool($data['en_tarifa']) : false;
            $this->en_catalogo = isset($data['en_catalogo']) ? $this->str2bool($data['en_catalogo']) : false;
            $this->en_sap = isset($data['en_sap']) ? $this->str2bool($data['en_sap']) : false;
        } else {
            $this->referencia = NULL;
            $this->codtarifa = NULL;
            $this->precio = 0.0;
            $this->activo = true;
            $this->en_tarifa = false;
            $this->en_catalogo = false;
            $this->en_sap = false;
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
        if (is_null($this->referencia)) {
            return "index.php?page=ventas_articulos";
        }
        return "index.php?page=ventas_articulo&ref=" . urlencode($this->referencia);
    }

    /**
     * Obtiene un precio específico de artículo+tarifa.
     * @param string $ref Referencia del artículo
     * @param string $codtarifa Código de la tarifa
     * @return tarif_articulo_precio|false
     */
    public function get($ref, $codtarifa)
    {
        $sql = "SELECT * FROM " . $this->table_name
            . " WHERE referencia = " . $this->var2str($ref)
            . " AND codtarifa = " . $this->var2str($codtarifa) . ";";
        $data = $this->db->select($sql);
        if ($data) {
            return new tarif_articulo_precio($data[0]);
        }
        return FALSE;
    }

    public function exists()
    {
        if (is_null($this->referencia) || is_null($this->codtarifa)) {
            return FALSE;
        }
        $sql = "SELECT * FROM " . $this->table_name
            . " WHERE referencia = " . $this->var2str($this->referencia)
            . " AND codtarifa = " . $this->var2str($this->codtarifa) . ";";
        return $this->db->select($sql);
    }

    public function test()
    {
        $this->referencia = $this->no_html(trim($this->referencia));
        $this->codtarifa = $this->no_html(trim($this->codtarifa));

        if (mb_strlen($this->referencia) < 1 || mb_strlen($this->referencia) > 18) {
            $this->new_error_msg("Referencia de artículo no válida.");
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
                $actual = $this->get($this->referencia, $this->codtarifa);
                if ($actual) {
                    $precio_anterior = $actual->precio;
                }
                
                $sql = "UPDATE " . $this->table_name . " SET "
                    . "precio = " . $this->var2str($this->precio) . ","
                    . "activo = " . $this->var2str($this->activo) . ","
                    . "en_tarifa = " . $this->var2str($this->en_tarifa) . ","
                    . "en_catalogo = " . $this->var2str($this->en_catalogo) . ","
                    . "en_sap = " . $this->var2str($this->en_sap)
                    . " WHERE referencia = " . $this->var2str($this->referencia)
                    . " AND codtarifa = " . $this->var2str($this->codtarifa) . ";";
            } else {
                $sql = "INSERT INTO " . $this->table_name . " (referencia, codtarifa, precio, activo, en_tarifa, en_catalogo, en_sap) VALUES ("
                    . $this->var2str($this->referencia) . ","
                    . $this->var2str($this->codtarifa) . ","
                    . $this->var2str($this->precio) . ","
                    . $this->var2str($this->activo) . ","
                    . $this->var2str($this->en_tarifa) . ","
                    . $this->var2str($this->en_catalogo) . ","
                    . $this->var2str($this->en_sap) . ");";
            }

            $result = $this->db->exec($sql);
            
            if ($result && abs($precio_anterior - $this->precio) >= 0.0001) {
                // AD-4: the history model still lives in tarifario (it moves in
                // WU-6); guard the write so a standalone catalogo_core save()
                // never fatals when tarifario is inactive.
                if (class_exists('FSFramework\\model\\tarif_precio_historial')) {
                    $coreLog = new \fs_core_log();
                    $usuario = $coreLog->user_nick() ?: null;
                    tarif_precio_historial::registrar_cambio(
                        $this->referencia,
                        $this->codtarifa,
                        $precio_anterior,
                        $this->precio,
                        $usuario
                    );
                }
            }
            
            return $result;
        }
        return FALSE;
    }

    public function delete()
    {
        $sql = "DELETE FROM " . $this->table_name
            . " WHERE referencia = " . $this->var2str($this->referencia)
            . " AND codtarifa = " . $this->var2str($this->codtarifa) . ";";
        return $this->db->exec($sql);
    }

    /**
     * Devuelve todos los precios de un artículo en todas las tarifas.
     * @param string $ref Referencia del artículo
     * @return array
     */
    public function all_from_articulo($ref)
    {
        $list = [];
        $sql = "SELECT * FROM " . $this->table_name
            . " WHERE referencia = " . $this->var2str($ref)
            . " ORDER BY codtarifa ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_articulo_precio($d);
            }
        }
        return $list;
    }

    /**
     * Devuelve todos los precios de artículos para una tarifa específica.
     * @param string $codtarifa Código de la tarifa
     * @return array
     */
    public function all_from_tarifa($codtarifa)
    {
        $list = [];
        $sql = "SELECT * FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " ORDER BY referencia ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_articulo_precio($d);
            }
        }
        return $list;
    }

    /**
     * Elimina todos los precios de un artículo.
     * @param string $ref Referencia del artículo
     * @return boolean
     */
    public function delete_from_articulo($ref)
    {
        $sql = "DELETE FROM " . $this->table_name
            . " WHERE referencia = " . $this->var2str($ref) . ";";
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
        $sql = "SELECT * FROM " . $this->table_name . " ORDER BY referencia ASC, codtarifa ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_articulo_precio($d);
            }
        }
        return $list;
    }

    /**
     * Devuelve el precio/estado de varias referencias en una tarifa con UNA
     * sola consulta (spec ALC-02, AD-W3-4). El mapa va indexado por
     * referencia; las referencias sin fila no aparecen (el consumidor aplica
     * los defaults de ALC-02).
     *
     * @param array<int, string> $refs
     * @return array<string, array{precio: float, activo: bool, en_tarifa: bool, en_catalogo: bool}>
     */
    public function all_for_referencias(array $refs, string $codtarifa): array
    {
        $map = [];

        if ($refs === [] || $codtarifa === '') {
            return $map;
        }

        $quoted = [];
        foreach ($refs as $ref) {
            $quoted[] = $this->var2str((string) $ref);
        }

        $sql = "SELECT * FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND referencia IN (" . implode(',', $quoted) . ");";

        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $map[(string) $d['referencia']] = [
                    'precio' => floatval($d['precio']),
                    'activo' => isset($d['activo']) ? $this->str2bool($d['activo']) : true,
                    'en_tarifa' => isset($d['en_tarifa']) ? $this->str2bool($d['en_tarifa']) : false,
                    'en_catalogo' => isset($d['en_catalogo']) ? $this->str2bool($d['en_catalogo']) : false,
                ];
            }
        }

        return $map;
    }

    /**
     * Aplica un porcentaje de cambio a múltiples artículos en una tarifa.
     * Registra automáticamente los cambios en el historial.
     * 
     * @param string $codtarifa Código de la tarifa
     * @param array $referencias Array de referencias de artículos
     * @param float $porcentaje Porcentaje a aplicar (positivo = subida, negativo = bajada)
     * @param string|null $usuario Nick del usuario que realiza el cambio
     * @return array ['actualizados' => int, 'errores' => int, 'detalles' => array]
     */
    public function aplicar_porcentaje_masivo($codtarifa, $referencias, $porcentaje, $usuario = null)
    {
        $resultado = [
            'actualizados' => 0,
            'errores' => 0,
            'detalles' => []
        ];

        if (empty($referencias) || empty($codtarifa)) {
            return $resultado;
        }

        $factor = 1 + ($porcentaje / 100);

        foreach ($referencias as $referencia) {
            $precio = $this->get($referencia, $codtarifa);
            if ($precio) {
                $precio_anterior = $precio->precio;
                $precio_nuevo = round($precio_anterior * $factor, 2);
                $precio->precio = $precio_nuevo;

                if ($precio->save()) {
                    $resultado['actualizados']++;
                    $resultado['detalles'][] = [
                        'referencia' => $referencia,
                        'precio_anterior' => $precio_anterior,
                        'precio_nuevo' => $precio_nuevo,
                        'status' => 'ok'
                    ];
                } else {
                    $resultado['errores']++;
                    $resultado['detalles'][] = [
                        'referencia' => $referencia,
                        'precio_anterior' => $precio_anterior,
                        'precio_nuevo' => $precio_nuevo,
                        'status' => 'error'
                    ];
                }
            } else {
                $resultado['errores']++;
                $resultado['detalles'][] = [
                    'referencia' => $referencia,
                    'precio_anterior' => null,
                    'precio_nuevo' => null,
                    'status' => 'not_found'
                ];
            }
        }

        return $resultado;
    }

    /**
     * Obtiene artículos con precios en una tarifa filtrados por familias.
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
        
        $sql = "SELECT ap.*, a.descripcion, a.codfamilia FROM " . $this->table_name . " ap "
            . "INNER JOIN articulos a ON ap.referencia = a.referencia "
            . "WHERE ap.codtarifa = " . $this->var2str($codtarifa);
        
        if (!empty($familias)) {
            $familias_sql = implode(',', array_map(function($f) {
                return $this->var2str($f);
            }, $familias));
            $sql .= " AND a.codfamilia IN ($familias_sql)";
        }

        $sql .= " ORDER BY a.referencia ASC";

        $data = $this->db->select_limit($sql, $limit, $offset);
        if ($data) {
            foreach ($data as $d) {
                $item = new tarif_articulo_precio($d);
                $item->descripcion = $d['descripcion'];
                $item->codfamilia = $d['codfamilia'];
                $list[] = $item;
            }
        }
        
        return $list;
    }

    /**
     * Cuenta artículos con precios en una tarifa filtrados por familias.
     * 
     * @param string $codtarifa Código de la tarifa
     * @param array $familias Array de códigos de familia (vacío = todas)
     * @return int
     */
    public function count_by_familias($codtarifa, $familias = [])
    {
        $sql = "SELECT COUNT(*) as total FROM " . $this->table_name . " ap "
            . "INNER JOIN articulos a ON ap.referencia = a.referencia "
            . "WHERE ap.codtarifa = " . $this->var2str($codtarifa);
        
        if (!empty($familias)) {
            $familias_sql = implode(',', array_map(function($f) {
                return $this->var2str($f);
            }, $familias));
            $sql .= " AND a.codfamilia IN ($familias_sql)";
        }
        
        $data = $this->db->select($sql);
        return $data ? intval($data[0]['total']) : 0;
    }
}
