<?php
/**
 * This file is part of tarifario
 * Copyright (C) 2025 FSFramework Team
 *
 * Extiende catalogo_opcional del plugin catalogo_core con URLs, precios por tarifa
 * y campos de extensión (ref_sap, en_catalogo, en_tarifa) en tarif_opcional_ext.
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

    /** @var bool Visible en catálogo exportado (solo tarifario). */
    public $en_catalogo = false;

    /** @var bool Incluido en tarifa exportada (solo tarifario). */
    public $en_tarifa = false;

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
            if (array_key_exists('en_catalogo', $data)) {
                $this->en_catalogo = $this->str2bool($data['en_catalogo']);
            }
            if (array_key_exists('en_tarifa', $data)) {
                $this->en_tarifa = $this->str2bool($data['en_tarifa']);
            }
        } else {
            $this->ref_sap = null;
            $this->en_catalogo = false;
            $this->en_tarifa = false;
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
            return 'index.php?page=tarif_opcionales';
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
            $this->en_catalogo = $this->str2bool($data[0]['en_catalogo'] ?? false);
            $this->en_tarifa = $this->str2bool($data[0]['en_tarifa'] ?? false);

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
            foreach (['ref_sap', 'codigo2', 'en_catalogo', 'en_tarifa'] as $candidate) {
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
        if (array_key_exists('en_catalogo', $row)) {
            $this->en_catalogo = $this->str2bool($row['en_catalogo']);
        }
        if (array_key_exists('en_tarifa', $row)) {
            $this->en_tarifa = $this->str2bool($row['en_tarifa']);
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
        $ext->en_catalogo = $this->en_catalogo;
        $ext->en_tarifa = $this->en_tarifa;

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
        $sql = 'SELECT o.*, e.ref_sap, e.en_catalogo, e.en_tarifa FROM ' . $this->table_name . ' o '
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

    public function search($query = '', $offset = 0, $codfamilia = '', $codtarifa = '', $solo_activos = false)
    {
        $list = [];
        $query = $this->no_html(mb_strtolower($query, 'UTF8'));
        $where_conditions = [];
        $familiaTable = catalogo_opcional_familia::TABLE;

        if ($codfamilia != '' || ($codtarifa != '' && $solo_activos)) {
            $sql = 'SELECT DISTINCT o.*, e.ref_sap, e.en_catalogo, e.en_tarifa FROM ' . $this->table_name . ' o '
                . 'LEFT JOIN ' . $this->ext_table . ' e ON o.id = e.id_opcional';

            if ($codfamilia != '') {
                $sql .= ' INNER JOIN ' . $familiaTable . ' of ON o.id = of.id_opcional';
                $where_conditions[] = 'of.codfamilia = ' . $this->var2str($codfamilia);
            }

            if ($codtarifa != '' && $solo_activos) {
                $sql .= ' INNER JOIN catalogo_opcional_precios op ON o.id = op.id_opcional';
                $where_conditions[] = 'op.codlista = ' . $this->var2str($codtarifa);
            }

            if ($query != '') {
                $where_conditions[] = "(lower(o.codigo) LIKE '%" . $query . "%' OR lower(o.nombre) LIKE '%" . $query . "%' OR lower(COALESCE(e.ref_sap, '')) LIKE '%" . $query . "%')";
            }

            if (count($where_conditions) > 0) {
                $sql .= ' WHERE ' . implode(' AND ', $where_conditions);
            }
            $sql .= ' ORDER BY o.nombre ASC';
        } else {
            return parent::search($query, $offset, $codfamilia, $codtarifa, $solo_activos);
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
     * @return int
     */
    public function count_filtered($query = '', $codfamilia = '', $codtarifa = '', $solo_activos = false)
    {
        $query = $this->no_html(mb_strtolower($query, 'UTF8'));
        $where_conditions = [];
        $familiaTable = catalogo_opcional_familia::TABLE;

        if ($codfamilia != '' || ($codtarifa != '' && $solo_activos)) {
            $sql = 'SELECT COUNT(DISTINCT o.id) as total FROM ' . $this->table_name . ' o '
                . 'LEFT JOIN ' . $this->ext_table . ' e ON o.id = e.id_opcional';

            if ($codfamilia != '') {
                $sql .= ' INNER JOIN ' . $familiaTable . ' of ON o.id = of.id_opcional';
                $where_conditions[] = 'of.codfamilia = ' . $this->var2str($codfamilia);
            }

            if ($codtarifa != '' && $solo_activos) {
                $sql .= ' INNER JOIN catalogo_opcional_precios op ON o.id = op.id_opcional';
                $where_conditions[] = 'op.codlista = ' . $this->var2str($codtarifa);
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
        $sql = 'SELECT o.*, e.ref_sap, e.en_catalogo, e.en_tarifa FROM ' . $this->table_name . ' o '
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
        $precio_model = new tarif_opcional_precio();
        return $precio_model->get($this->id, $codtarifa);
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

    public function set_precio_tarifa($codtarifa, $precio)
    {
        $precio_model = new tarif_opcional_precio();
        $p = $precio_model->get($this->id, $codtarifa);

        if ($p) {
            $p->precio = floatval($precio);
        } else {
            $p = new tarif_opcional_precio();
            $p->id_opcional = $this->id;
            $p->codtarifa = $codtarifa;
            $p->precio = floatval($precio);
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
