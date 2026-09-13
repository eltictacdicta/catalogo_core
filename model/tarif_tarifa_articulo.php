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
 * Configuración de artículos por tarifa.
 * Permite que cada tarifa tenga sus propios artículos con:
 * - Familia específica (puede diferir entre tarifas)
 * - Visibilidad: en_tarifa (exportar) y en_catalogo (web)
 */
class tarif_tarifa_articulo extends \fs_model
{
    /**
     * Código de la tarifa. Clave primaria (parte 1).
     * @var string
     */
    public $codtarifa;

    /**
     * Referencia del artículo. Clave primaria (parte 2).
     * @var string
     */
    public $referencia;

    /**
     * Código de familia en ESTA tarifa.
     * Puede diferir de la familia en otras tarifas.
     * @var string|null
     */
    public $codfamilia;

    /**
     * Si el artículo se exporta a clientes en esta tarifa.
     * @var boolean
     */
    public $en_tarifa;

    /**
     * Si el artículo aparece en el catálogo público de esta tarifa.
     * @var boolean
     */
    public $en_catalogo;

    /**
     * Orden del artículo dentro de su familia en esta tarifa.
     * @var int
     */
    public $orden;

    /**
     * Descripción del artículo (cargada del JOIN).
     * @var string
     */
    public $descripcion;

    /**
     * Descripción de la familia (cargada del JOIN).
     * @var string
     */
    public $familia_descripcion;

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_tarifa_articulo');

        if ($data) {
            $this->codtarifa = $data['codtarifa'];
            $this->referencia = $data['referencia'];
            $this->codfamilia = isset($data['codfamilia']) ? $data['codfamilia'] : null;
            $this->en_tarifa = $this->str2bool($data['en_tarifa']);
            $this->en_catalogo = $this->str2bool($data['en_catalogo']);
            $this->orden = isset($data['orden']) ? intval($data['orden']) : 0;
            // Campos del JOIN
            $this->descripcion = isset($data['descripcion']) ? $data['descripcion'] : '';
            $this->familia_descripcion = isset($data['familia_descripcion']) ? $data['familia_descripcion'] : '';
        } else {
            $this->codtarifa = null;
            $this->referencia = null;
            $this->codfamilia = null;
            $this->en_tarifa = true;
            $this->en_catalogo = true;
            $this->orden = 0;
            $this->descripcion = '';
            $this->familia_descripcion = '';
        }
    }

    protected function install()
    {
        // Asegurar que existen las tablas referenciadas
        new tarif_tarifa();
        new tarif_tarifa_familia();
        
        // Migrar artículos existentes a la tarifa por defecto
        return $this->migrate_existing_articulos();
    }

    /**
     * Migra los artículos existentes a la tarifa por defecto.
     * Usa la familia del artículo y los que tienen precio en tarif_articulo_precios.
     * @return string SQL de migración
     */
    private function migrate_existing_articulos()
    {
        // Obtener la tarifa por defecto
        $tarifa = new tarif_tarifa();
        $default = $tarifa->get_default();
        
        if (!$default) {
            return '';
        }
        
        $codtarifa = $this->var2str($default->codtarifa);
        
        // Migrar artículos que tienen precio en la tarifa por defecto
        // Usamos la familia original del artículo
        $sql = "INSERT INTO " . $this->table_name . " (codtarifa, referencia, codfamilia, en_tarifa, en_catalogo) "
            . "SELECT $codtarifa, p.referencia, a.codfamilia, TRUE, TRUE "
            . "FROM tarif_articulo_precios p "
            . "JOIN articulos a ON p.referencia = a.referencia "
            . "WHERE p.codtarifa = $codtarifa "
            . "AND NOT EXISTS (SELECT 1 FROM " . $this->table_name . " WHERE codtarifa = $codtarifa AND referencia = p.referencia);";
        
        return $sql;
    }

    public function url()
    {
        return "index.php?page=ventas_articulo&ref=" . urlencode($this->referencia)
            . "&codtarifa=" . urlencode($this->codtarifa);
    }

    /**
     * Comprueba si existe el registro.
     * @return bool
     */
    public function exists()
    {
        if (is_null($this->codtarifa) || is_null($this->referencia)) {
            return false;
        }
        return $this->db->select("SELECT * FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
            . " AND referencia = " . $this->var2str($this->referencia) . ";");
    }

    /**
     * Obtiene la configuración de un artículo en una tarifa.
     * @param string $codtarifa
     * @param string $referencia
     * @return tarif_tarifa_articulo|false
     */
    public function get($codtarifa, $referencia)
    {
        $sql = "SELECT ta.*, a.descripcion, f.descripcion as familia_descripcion "
            . "FROM " . $this->table_name . " ta "
            . "LEFT JOIN articulos a ON ta.referencia = a.referencia "
            . "LEFT JOIN familias f ON ta.codfamilia = f.codfamilia "
            . "WHERE ta.codtarifa = " . $this->var2str($codtarifa)
            . " AND ta.referencia = " . $this->var2str($referencia) . ";";
        $data = $this->db->select($sql);
        if ($data) {
            return new tarif_tarifa_articulo($data[0]);
        }
        return false;
    }

    public function test()
    {
        $this->codtarifa = $this->no_html(trim($this->codtarifa));
        $this->referencia = $this->no_html(trim($this->referencia));

        if (empty($this->codtarifa) || empty($this->referencia)) {
            $this->new_error_msg("Código de tarifa y referencia son obligatorios.");
            return false;
        }

        return true;
    }

    public function save()
    {
        if ($this->test()) {
            if ($this->exists()) {
                $sql = "UPDATE " . $this->table_name . " SET "
                    . "codfamilia = " . $this->var2str($this->codfamilia)
                    . ", en_tarifa = " . $this->var2str($this->en_tarifa)
                    . ", en_catalogo = " . $this->var2str($this->en_catalogo)
                    . ", orden = " . $this->var2str($this->orden)
                    . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
                    . " AND referencia = " . $this->var2str($this->referencia) . ";";
            } else {
                // Auto-calcular orden si es 0
                if ($this->orden == 0) {
                    $this->orden = $this->get_next_orden($this->codtarifa, $this->codfamilia);
                }
                $sql = "INSERT INTO " . $this->table_name 
                    . " (codtarifa, referencia, codfamilia, en_tarifa, en_catalogo, orden) VALUES ("
                    . $this->var2str($this->codtarifa) . ","
                    . $this->var2str($this->referencia) . ","
                    . $this->var2str($this->codfamilia) . ","
                    . $this->var2str($this->en_tarifa) . ","
                    . $this->var2str($this->en_catalogo) . ","
                    . $this->var2str($this->orden) . ");";
            }

            return $this->db->exec($sql);
        }
        return false;
    }

    /**
     * Obtiene el siguiente número de orden para una familia en una tarifa.
     * @param string $codtarifa
     * @param string|null $codfamilia
     * @return int
     */
    public function get_next_orden($codtarifa, $codfamilia = null)
    {
        $sql = "SELECT MAX(orden) as max_orden FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa);
        if ($codfamilia) {
            $sql .= " AND codfamilia = " . $this->var2str($codfamilia);
        } else {
            $sql .= " AND codfamilia IS NULL";
        }
        $sql .= ";";
        
        $data = $this->db->select($sql);
        if ($data && $data[0]['max_orden'] !== null) {
            return intval($data[0]['max_orden']) + 1;
        }
        return 1;
    }

    public function delete()
    {
        return $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($this->codtarifa)
            . " AND referencia = " . $this->var2str($this->referencia) . ";");
    }

    /**
     * Devuelve todos los artículos de una tarifa.
     * @param string $codtarifa
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function all_from_tarifa($codtarifa, $offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $list = [];
        $sql = "SELECT ta.*, a.descripcion, f.descripcion as familia_descripcion "
            . "FROM " . $this->table_name . " ta "
            . "LEFT JOIN articulos a ON ta.referencia = a.referencia "
            . "LEFT JOIN familias f ON ta.codfamilia = f.codfamilia "
            . "WHERE ta.codtarifa = " . $this->var2str($codtarifa)
            . " ORDER BY ta.orden ASC, a.referencia ASC";
        $data = $this->db->select_limit($sql, $limit, $offset);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_tarifa_articulo($d);
            }
        }
        return $list;
    }

    /**
     * Devuelve los artículos de una familia en una tarifa.
     * @param string $codtarifa
     * @param string $codfamilia
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function all_from_familia($codtarifa, $codfamilia, $offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $list = [];
        $sql = "SELECT ta.*, a.descripcion, f.descripcion as familia_descripcion "
            . "FROM " . $this->table_name . " ta "
            . "LEFT JOIN articulos a ON ta.referencia = a.referencia "
            . "LEFT JOIN familias f ON ta.codfamilia = f.codfamilia "
            . "WHERE ta.codtarifa = " . $this->var2str($codtarifa)
            . " AND ta.codfamilia = " . $this->var2str($codfamilia)
            . " ORDER BY ta.orden ASC, a.referencia ASC";
        $data = $this->db->select_limit($sql, $limit, $offset);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_tarifa_articulo($d);
            }
        }
        return $list;
    }

    /**
     * Devuelve los artículos en tarifa (exportables) de una tarifa.
     * @param string $codtarifa
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function all_en_tarifa($codtarifa, $offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $list = [];
        $sql = "SELECT ta.*, a.descripcion, f.descripcion as familia_descripcion "
            . "FROM " . $this->table_name . " ta "
            . "LEFT JOIN articulos a ON ta.referencia = a.referencia "
            . "LEFT JOIN familias f ON ta.codfamilia = f.codfamilia "
            . "WHERE ta.codtarifa = " . $this->var2str($codtarifa)
            . " AND ta.en_tarifa = TRUE"
            . " ORDER BY ta.orden ASC, a.referencia ASC";
        $data = $this->db->select_limit($sql, $limit, $offset);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_tarifa_articulo($d);
            }
        }
        return $list;
    }

    /**
     * Devuelve los artículos en catálogo de una tarifa.
     * @param string $codtarifa
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function all_en_catalogo($codtarifa, $offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $list = [];
        $sql = "SELECT ta.*, a.descripcion, f.descripcion as familia_descripcion "
            . "FROM " . $this->table_name . " ta "
            . "LEFT JOIN articulos a ON ta.referencia = a.referencia "
            . "LEFT JOIN familias f ON ta.codfamilia = f.codfamilia "
            . "WHERE ta.codtarifa = " . $this->var2str($codtarifa)
            . " AND ta.en_catalogo = TRUE"
            . " ORDER BY ta.orden ASC, a.referencia ASC";
        $data = $this->db->select_limit($sql, $limit, $offset);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_tarifa_articulo($d);
            }
        }
        return $list;
    }

    /**
     * Busca artículos en una tarifa.
     * @param string $codtarifa
     * @param string $query
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function search($codtarifa, $query = '', $offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $list = [];
        $query = $this->no_html(mb_strtolower($query, 'UTF8'));

        $sql = "SELECT ta.*, a.descripcion, f.descripcion as familia_descripcion "
            . "FROM " . $this->table_name . " ta "
            . "LEFT JOIN articulos a ON ta.referencia = a.referencia "
            . "LEFT JOIN familias f ON ta.codfamilia = f.codfamilia "
            . "WHERE ta.codtarifa = " . $this->var2str($codtarifa);
        
        if (!empty($query)) {
            $like = $this->var2str('%' . $query . '%');
            $sql .= " AND (lower(ta.referencia) LIKE " . $like
                . " OR lower(a.descripcion) LIKE " . $like . ")";
        }
        
        $sql .= " ORDER BY ta.orden ASC, a.referencia ASC";

        $data = $this->db->select_limit($sql, $limit, $offset);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_tarifa_articulo($d);
            }
        }
        return $list;
    }

    /**
     * Cuenta los artículos de una tarifa.
     * @param string $codtarifa
     * @return int
     */
    public function count_from_tarifa($codtarifa)
    {
        $sql = "SELECT COUNT(*) as total FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa) . ";";
        $data = $this->db->select($sql);
        if ($data) {
            return intval($data[0]['total']);
        }
        return 0;
    }

    /**
     * Cuenta los artículos en tarifa (exportables) de una tarifa.
     * @param string $codtarifa
     * @return int
     */
    public function count_en_tarifa($codtarifa)
    {
        $sql = "SELECT COUNT(*) as total FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND en_tarifa = TRUE;";
        $data = $this->db->select($sql);
        if ($data) {
            return intval($data[0]['total']);
        }
        return 0;
    }

    /**
     * Cuenta los artículos en catálogo de una tarifa.
     * @param string $codtarifa
     * @return int
     */
    public function count_en_catalogo($codtarifa)
    {
        $sql = "SELECT COUNT(*) as total FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND en_catalogo = TRUE;";
        $data = $this->db->select($sql);
        if ($data) {
            return intval($data[0]['total']);
        }
        return 0;
    }

    /**
     * Cuenta los artículos de una familia en una tarifa.
     * @param string $codtarifa
     * @param string $codfamilia
     * @return int
     */
    public function count_from_familia($codtarifa, $codfamilia)
    {
        $sql = "SELECT COUNT(*) as total FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND codfamilia = " . $this->var2str($codfamilia) . ";";
        $data = $this->db->select($sql);
        if ($data) {
            return intval($data[0]['total']);
        }
        return 0;
    }

    /**
     * Copia los artículos de una tarifa a otra.
     * @param string $origen Código de tarifa origen
     * @param string $destino Código de tarifa destino
     * @return bool
     */
    public function copy_from_tarifa($origen, $destino)
    {
        // Copying a tarifa onto itself is a no-op: the DELETE would remove the
        // very rows the INSERT ... SELECT is meant to read, wiping the tarifa.
        if ((string) $origen === (string) $destino) {
            return true;
        }

        // The destination is cleared and repopulated in a single transaction:
        // a failed INSERT must not leave the destination tarifa empty. Both
        // statements run with $transaction = false so nothing auto-commits
        // before the explicit commit below.
        if (!$this->db->begin_transaction()) {
            return false;
        }

        $deleted = $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($destino) . ";", false);
        if (!$deleted) {
            $this->db->rollback();
            return false;
        }

        $sql = "INSERT INTO " . $this->table_name 
            . " (codtarifa, referencia, codfamilia, en_tarifa, en_catalogo, orden) "
            . "SELECT " . $this->var2str($destino) . ", referencia, codfamilia, en_tarifa, en_catalogo, orden "
            . "FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($origen) . ";";

        if (!$this->db->exec($sql, false)) {
            $this->db->rollback();
            return false;
        }

        if (!$this->db->commit()) {
            $this->db->rollback();
            return false;
        }

        return true;
    }

    /**
     * Añade un artículo a una tarifa.
     * @param string $codtarifa
     * @param string $referencia
     * @param string|null $codfamilia
     * @return tarif_tarifa_articulo|false
     */
    public function add_articulo_to_tarifa($codtarifa, $referencia, $codfamilia = null)
    {
        // Si no se especifica familia, intentar usar la familia base del artículo
        if (is_null($codfamilia)) {
            $art = $this->db->select("SELECT codfamilia FROM articulos WHERE referencia = " 
                . $this->var2str($referencia) . ";");
            if ($art) {
                $codfamilia = $art[0]['codfamilia'];
            }
        }

        $ta = new tarif_tarifa_articulo();
        $ta->codtarifa = $codtarifa;
        $ta->referencia = $referencia;
        $ta->codfamilia = $codfamilia;
        $ta->en_tarifa = true;
        $ta->en_catalogo = true;

        if ($ta->save()) {
            return $ta;
        }
        return false;
    }

    /**
     * Elimina todos los artículos de una tarifa.
     * @param string $codtarifa
     * @return bool
     */
    public function delete_all_from_tarifa($codtarifa)
    {
        return $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codtarifa = " . $this->var2str($codtarifa) . ";");
    }

    /**
     * Actualiza el orden de un artículo.
     * @param string $codtarifa
     * @param string $referencia
     * @param int $nuevo_orden
     * @param string|null $codfamilia
     * @return bool
     */
    public function update_orden($codtarifa, $referencia, $nuevo_orden, $codfamilia = null)
    {
        $sql = "UPDATE " . $this->table_name 
            . " SET orden = " . $this->var2str($nuevo_orden)
            . " WHERE codtarifa = " . $this->var2str($codtarifa)
            . " AND referencia = " . $this->var2str($referencia);

        // Null-safe family scope: when a familia is provided, a reference that
        // belongs to another family must not be modified.
        if (!is_null($codfamilia)) {
            $sql .= " AND codfamilia = " . $this->var2str($codfamilia);
        }

        return $this->db->exec($sql . ";");
    }

    /**
     * Reordena los artículos de una familia en una tarifa.
     * @param string $codtarifa
     * @param string|null $codfamilia
     * @param array $orden_array Array de referencias en el nuevo orden
     * @return bool
     */
    public function reorder_articulos($codtarifa, $codfamilia, $orden_array)
    {
        $success = true;
        $orden = 1;
        foreach ($orden_array as $referencia) {
            if (!$this->update_orden($codtarifa, $referencia, $orden, $codfamilia)) {
                $success = false;
            }
            $orden++;
        }
        return $success;
    }

    /**
     * Devuelve las tarifas en las que está un artículo.
     * @param string $referencia
     * @return array
     */
    public function tarifas_from_articulo($referencia)
    {
        $list = [];
        $sql = "SELECT ta.*, t.nombre as tarifa_nombre "
            . "FROM " . $this->table_name . " ta "
            . "LEFT JOIN tarif_tarifas t ON ta.codtarifa = t.codtarifa "
            . "WHERE ta.referencia = " . $this->var2str($referencia)
            . " ORDER BY t.nombre ASC;";
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = $d;
            }
        }
        return $list;
    }
}
