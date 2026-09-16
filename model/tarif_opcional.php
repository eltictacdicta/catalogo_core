<?php
/**
 * This file is part of tarifario
 * Copyright (C) 2025 FSFramework Team
 *
 * Extiende catalogo_opcional del plugin catalogo_core con URLs, precios por tarifa
 * y el campo de extensión ref_sap en tarif_opcional_ext.
 *
 * Catalog/tarifa visibility is no longer an opcional-owned flag
 * (`caracteristicas-producto` CAR-12 / D12): it is derived from the parent
 * product through `CaracteristicaResolver`.
 */
namespace FSFramework\model;

require_once 'plugins/catalogo_core/model/core/catalogo_opcional.php';
require_once 'plugins/catalogo_core/model/tarif_opcional_precio.php';
require_once 'plugins/catalogo_core/model/tarif_opcional_ext.php';

class tarif_opcional extends catalogo_opcional
{
    protected $ext_table = 'tarif_opcional_ext';

    /** @var string|null Referencia SAP / SKU del opcional (solo tarifario). */
    public $ref_sap;

    /**
     * Process-wide cache of columnExists() probes, keyed by "table|column".
     * @var array<string, bool>
     */
    private static array $column_exists_cache = [];

    /**
     * Legacy extension columns per source table, probed at most once per
     * process (keyed by table name).
     * @var array<string, array<int, string>>
     */
    private static array $legacy_columns_cache = [];

    public function __construct($data = false)
    {
        parent::__construct($data);

        if ($data) {
            $this->ref_sap = $data['ref_sap'] ?? null;
            if ($this->ref_sap === '') {
                $this->ref_sap = null;
            }
        } else {
            $this->ref_sap = null;
        }

        if ($data && $this->id) {
            // Rows coming from the extension LEFT JOIN already carry the
            // `ref_sap` key: trust the joined data and skip the per-row
            // ext_exists() probe (N+1). Only rows without that key (e.g. from
            // a query that did not join tarif_opcional_ext) need an explicit
            // extension load.
            if (!array_key_exists('ref_sap', $data)) {
                $this->load_extension();
            }
        }
    }

    /**
     * Alias retrocompatible usado por importadores legacy.
     */
    public function __get($name)
    {
        if ($name === 'codigo2') {
            return $this->ref_sap;
        }

        return null;
    }

    /**
     * Alias retrocompatible usado por importadores legacy.
     *
     * @param mixed $value
     */
    public function __set($name, $value)
    {
        if ($name === 'codigo2') {
            $this->ref_sap = $value === '' ? null : $value;
        }
    }

    public function url()
    {
        if (is_null($this->id)) {
            return 'index.php?page=ventas_opcionales';
        }

        return 'index.php?page=tarif_opcional_edit&id=' . $this->id;
    }

    protected function load_extension(): void
    {
        if ($this->id === null) {
            return;
        }

        $data = $this->db->select(
            'SELECT * FROM ' . $this->ext_table
            . ' WHERE id_opcional = ' . $this->intval($this->id) . ';'
        );
        if ($data) {
            $this->ref_sap = $data[0]['ref_sap'] ?? null;

            return;
        }

        $this->load_legacy_extension_columns();
    }

    /**
     * Fallback: lee columnas legacy en catalogo_opcionales/tarif_opcionales antes de migrar.
     */
    protected function load_legacy_extension_columns(): void
    {
        $sourceTable = $this->table_name;
        if (!$this->db->select('SELECT 1 FROM ' . $sourceTable . ' WHERE id = ' . $this->intval($this->id) . ' LIMIT 1;')) {
            return;
        }

        // Probe the legacy columns at most once per process and reuse the
        // result for every subsequently hydrated row.
        if (!array_key_exists($sourceTable, self::$legacy_columns_cache)) {
            $columns = ['id'];
            foreach (['ref_sap', 'codigo2'] as $candidate) {
                if ($this->columnExists($sourceTable, $candidate)) {
                    $columns[] = $candidate;
                }
            }
            self::$legacy_columns_cache[$sourceTable] = $columns;
        }

        $columns = self::$legacy_columns_cache[$sourceTable];
        if (count($columns) === 1) {
            return;
        }

        $data = $this->db->select(
            'SELECT ' . implode(', ', $columns)
            . ' FROM ' . $sourceTable
            . ' WHERE id = ' . $this->intval($this->id) . ' LIMIT 1;'
        );
        if (!$data) {
            return;
        }

        $row = $data[0];
        $this->ref_sap = $row['ref_sap'] ?? ($row['codigo2'] ?? null);
        if ($this->ref_sap === '') {
            $this->ref_sap = null;
        }
    }

    protected function ext_exists(): bool
    {
        if ($this->id === null) {
            return false;
        }

        return (bool) $this->db->select(
            'SELECT * FROM ' . $this->ext_table
            . ' WHERE id_opcional = ' . $this->intval($this->id) . ';'
        );
    }

    protected function save_extension(): bool
    {
        if ($this->id === null) {
            return true;
        }

        $ext = new tarif_opcional_ext();
        $ext->id_opcional = (int) $this->id;
        $ext->ref_sap = $this->ref_sap;

        return $ext->save();
    }

    protected function delete_extension(): bool
    {
        if ($this->id === null) {
            return true;
        }

        $ext = new tarif_opcional_ext();
        $ext->id_opcional = (int) $this->id;

        return $ext->delete();
    }

    public function get($id)
    {
        $sql = 'SELECT o.*, e.ref_sap FROM ' . $this->table_name . ' o '
            . 'LEFT JOIN ' . $this->ext_table . ' e ON o.id = e.id_opcional '
            . 'WHERE o.id = ' . $this->intval($id) . ';';
        $data = $this->db->select($sql);
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function save()
    {
        if (!parent::save()) {
            return false;
        }

        return $this->save_extension();
    }

    public function delete()
    {
        $this->delete_extension();

        return parent::delete();
    }

    /**
     * @param string $query
     * @param int    $offset
     * @param string $codfamilia
     * @param string $codtarifa
     * @param bool   $solo_activos
     * @param string $id_grupo    ''=all, '0'=Sin grupo, numeric=group id (AD-5/AD-6)
     * @return array
     */
    public function search($query = '', $offset = 0, $codfamilia = '', $codtarifa = '', $solo_activos = false, $id_grupo = '')
    {
        $list = [];
        $query = $this->no_html(mb_strtolower($query, 'UTF8'));
        $where_conditions = [];
        $familiaTable = catalogo_opcional_familia::TABLE;
        $grupo_condition = $this->where_id_grupo($id_grupo, 'o');

        if ($codfamilia != '' || $codtarifa != '' || $grupo_condition !== '') {
            $joins = ' FROM ' . $this->table_name . ' o '
                . 'LEFT JOIN ' . $this->ext_table . ' e ON o.id = e.id_opcional';
            $order_expr = null;

            if ($codfamilia != '') {
                $joins .= ' INNER JOIN ' . $familiaTable . ' of ON o.id = of.id_opcional';
                $where_conditions[] = 'of.codfamilia = ' . $this->var2str($codfamilia);
            }

            if ($codtarifa != '') {
                // Master `orden` is the default order for the per-tarifa
                // listing (design AD4); a family-scoped orden overrides it.
                $joins .= ' LEFT JOIN tarif_tarifa_opcional mto ON mto.codtarifa = ' . $this->var2str($codtarifa)
                    . ' AND mto.id_opcional = o.id';
                $order_expr = 'COALESCE(mto.orden, 999999)';

                if ($codfamilia != '') {
                    $joins .= ' LEFT JOIN tarif_tarifa_opcional_familia fto ON fto.codtarifa = ' . $this->var2str($codtarifa)
                        . ' AND fto.id_opcional = o.id AND fto.codfamilia = ' . $this->var2str($codfamilia);
                    $order_expr = 'COALESCE(fto.orden, mto.orden, 999999)';
                }
            }

            if ($codtarifa != '' && $solo_activos) {
                // Activation is authoritative on the per-tarifa master: keep
                // opcionales without a master row (lazy inherit => active) and
                // drop only those explicitly set activa = FALSE. Price rows
                // never activate (design AD2).
                $where_conditions[] = 'NOT EXISTS (SELECT 1 FROM tarif_tarifa_opcional tto'
                    . ' WHERE tto.codtarifa = ' . $this->var2str($codtarifa)
                    . ' AND tto.id_opcional = o.id AND tto.activa = FALSE)';
            }

            if ($grupo_condition !== '') {
                $where_conditions[] = $grupo_condition;
            }

            if ($query != '') {
                $where_conditions[] = "(lower(o.codigo) LIKE '%" . $query . "%' OR lower(o.nombre) LIKE '%" . $query . "%' OR lower(COALESCE(e.ref_sap, '')) LIKE '%" . $query . "%')";
            }

            // SELECT DISTINCT + an ORDER BY expression requires the expression
            // to be in the select list (MySQL error 3065 otherwise).
            $sql = 'SELECT DISTINCT o.*, e.ref_sap';
            if ($order_expr !== null) {
                $sql .= ', ' . $order_expr . ' AS orden_tarifa';
            }
            $sql .= $joins;

            if (count($where_conditions) > 0) {
                $sql .= ' WHERE ' . implode(' AND ', $where_conditions);
            }
            $sql .= $order_expr !== null
                ? ' ORDER BY orden_tarifa ASC, o.nombre ASC'
                : ' ORDER BY o.nombre ASC';
        } else {
            return parent::search($query, $offset, $codfamilia, $codtarifa, $solo_activos, $id_grupo);
        }

        $data = $this->db->select_limit($sql, FS_ITEM_LIMIT, $offset);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    /**
     * Cuenta los opcionales que cumplen los mismos filtros que search():
     * query, familia, tarifa (solo activos) y extensión ref_sap. Devuelve el
     * total real para paginar sin depender del número de filas de la página.
     *
     * @param string $query
     * @param string $codfamilia
     * @param string $codtarifa
     * @param bool $solo_activos
     * @param string $id_grupo    ''=all, '0'=Sin grupo, numeric=group id (AD-5/AD-6)
     * @return int
     */
    public function count_filtered($query = '', $codfamilia = '', $codtarifa = '', $solo_activos = false, $id_grupo = '')
    {
        $query = $this->no_html(mb_strtolower($query, 'UTF8'));
        $where_conditions = [];
        $familiaTable = catalogo_opcional_familia::TABLE;
        $grupo_condition = $this->where_id_grupo($id_grupo, 'o');

        if ($codfamilia != '' || $codtarifa != '' || $grupo_condition !== '') {
            $sql = 'SELECT COUNT(DISTINCT o.id) as total FROM ' . $this->table_name . ' o '
                . 'LEFT JOIN ' . $this->ext_table . ' e ON o.id = e.id_opcional';

            if ($codfamilia != '') {
                $sql .= ' INNER JOIN ' . $familiaTable . ' of ON o.id = of.id_opcional';
                $where_conditions[] = 'of.codfamilia = ' . $this->var2str($codfamilia);
            }

            if ($codtarifa != '' && $solo_activos) {
                // Activation is authoritative on the per-tarifa master: keep
                // opcionales without a master row (lazy inherit => active) and
                // drop only those explicitly set activa = FALSE. Price rows
                // never activate (design AD2).
                $where_conditions[] = 'NOT EXISTS (SELECT 1 FROM tarif_tarifa_opcional tto'
                    . ' WHERE tto.codtarifa = ' . $this->var2str($codtarifa)
                    . ' AND tto.id_opcional = o.id AND tto.activa = FALSE)';
            }

            if ($grupo_condition !== '') {
                $where_conditions[] = $grupo_condition;
            }

            if ($query != '') {
                $where_conditions[] = "(lower(o.codigo) LIKE '%" . $query . "%' OR lower(o.nombre) LIKE '%" . $query . "%' OR lower(COALESCE(e.ref_sap, '')) LIKE '%" . $query . "%')";
            }

            if (count($where_conditions) > 0) {
                $sql .= ' WHERE ' . implode(' AND ', $where_conditions);
            }
        } else {
            $sql = 'SELECT COUNT(*) as total FROM ' . $this->table_name;
            if ($query != '') {
                $sql .= " WHERE lower(codigo) LIKE '%" . $query . "%'"
                    . " OR lower(nombre) LIKE '%" . $query . "%'";
            }
        }

        $data = $this->db->select($sql);

        return $data ? intval($data[0]['total']) : 0;
    }

    public function all($offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $list = [];
        $sql = 'SELECT o.*, e.ref_sap FROM ' . $this->table_name . ' o '
            . 'LEFT JOIN ' . $this->ext_table . ' e ON o.id = e.id_opcional '
            . 'ORDER BY o.nombre ASC';
        $data = $this->db->select_limit($sql, $limit, $offset);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    public function get_precios_tarifas()
    {
        $precio_model = new tarif_opcional_precio();
        return $precio_model->all_from_opcional($this->id);
    }

    public function get_precio_tarifa($codtarifa)
    {
        $precio_model = $this->opcional_precio_model();
        return $precio_model->get($this->id, $codtarifa);
    }

    /**
     * Price-adapter factory (design AD-4). Overridable seam so the writer
     * symmetry is unit-testable without a live database.
     */
    public function opcional_precio_model(): tarif_opcional_precio
    {
        return new tarif_opcional_precio();
    }

    public function precio_en_tarifa($codtarifa, $articulo = null)
    {
        if ($this->es_precio_porcentaje()) {
            if ($articulo !== null) {
                return $this->precio_para_articulo($articulo, $codtarifa);
            }

            $porcentaje = $this->get_porcentaje($codtarifa);

            return $porcentaje ?? 0.0;
        }

        $precio = $this->get_precio_tarifa($codtarifa);
        if ($precio) {
            return $precio->precio;
        }

        return $this->precio_en_lista($codtarifa);
    }

    /**
     * Stores a fixed price for a tarifa and clears any stored percentage
     * (symmetry with `catalogo_opcional::set_precio_lista()`, design AD-2).
     *
     * @param string $codtarifa
     * @param mixed  $precio
     */
    public function set_precio_tarifa($codtarifa, $precio)
    {
        $precio_model = $this->opcional_precio_model();
        $p = $precio_model->get($this->id, $codtarifa);

        if ($p) {
            $p->precio = floatval($precio);
            $p->limpiar_porcentaje();
        } else {
            $p = $this->opcional_precio_model();
            $p->id_opcional = $this->id;
            $p->codtarifa = $codtarifa;
            $p->precio = floatval($precio);
        }

        return $p->save();
    }

    /**
     * Stores an effective percentage for a tarifa and zeroes the price
     * (design AD-2). Counterpart of {@see set_precio_tarifa()}.
     *
     * @param string $codtarifa
     * @param mixed  $porcentaje
     */
    public function set_porcentaje_tarifa($codtarifa, $porcentaje)
    {
        $precio_model = $this->opcional_precio_model();
        $p = $precio_model->get($this->id, $codtarifa);

        if ($p) {
            $p->porcentaje = floatval($porcentaje);
            $p->precio = 0.0;
        } else {
            $p = $this->opcional_precio_model();
            $p->id_opcional = $this->id;
            $p->codtarifa = $codtarifa;
            $p->porcentaje = floatval($porcentaje);
            $p->precio = 0.0;
        }

        return $p->save();
    }

    public function delete_precio_tarifa($codtarifa)
    {
        $precio_model = new tarif_opcional_precio();
        $p = $precio_model->get($this->id, $codtarifa);
        if ($p) {
            return $p->delete();
        }

        return true;
    }

    public function delete_precios_tarifas()
    {
        $precio_model = new tarif_opcional_precio();
        return $precio_model->delete_from_opcional($this->id);
    }

    private function columnExists(string $table, string $column): bool
    {
        $cacheKey = $table . '|' . $column;
        if (array_key_exists($cacheKey, self::$column_exists_cache)) {
            return self::$column_exists_cache[$cacheKey];
        }

        if (defined('FS_DB_TYPE') && FS_DB_TYPE === 'postgresql') {
            $data = $this->db->select(
                'SELECT 1 FROM information_schema.columns '
                . "WHERE table_schema = 'public' AND table_name = " . $this->var2str($table)
                . ' AND column_name = ' . $this->var2str($column) . ' LIMIT 1;'
            );
        } else {
            $data = $this->db->select(
                'SHOW COLUMNS FROM `' . $table . '` LIKE ' . $this->var2str($column) . ';'
            );
        }

        return self::$column_exists_cache[$cacheKey] = (bool) $data;
    }
}
