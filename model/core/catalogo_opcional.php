<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
namespace FSFramework\model;

/**
 * Opcional del catálogo asociable a artículos y familias.
 */
class catalogo_opcional extends \fs_model
{
    public const TABLE = 'catalogo_opcionales';
    public const TIPO_PRECIO_FIJO = 'fijo';
    public const TIPO_PRECIO_PORCENTAJE = 'porcentaje';

    public $id;
    public $codigo;
    public $nombre;
    public $descripcion;
    public $precio;
    public $tipo_precio;
    public $porcentaje;
    public $activo;
    public $id_grupo;
    /** @var bool Solo relevante al cargar opcionales asignados a un artículo concreto. */
    public $obligatorio_en_articulo = false;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = intval($data['id']);
            $this->codigo = $data['codigo'];
            $this->nombre = $data['nombre'];
            $this->descripcion = $data['descripcion'];
            $this->precio = floatval($data['precio']);
            $this->tipo_precio = $this->normalizeTipoPrecio($data['tipo_precio'] ?? self::TIPO_PRECIO_FIJO);
            $this->porcentaje = isset($data['porcentaje']) && $data['porcentaje'] !== ''
                ? floatval($data['porcentaje'])
                : null;
            $this->activo = $this->str2bool($data['activo']);
            $this->id_grupo = isset($data['id_grupo']) && $data['id_grupo'] !== '' && $data['id_grupo'] !== null
                ? intval($data['id_grupo'])
                : null;
            if (array_key_exists('obligatorio_en_articulo', $data)) {
                $this->obligatorio_en_articulo = $this->str2bool($data['obligatorio_en_articulo']);
            } elseif (array_key_exists('obligatorio', $data)) {
                $this->obligatorio_en_articulo = $this->str2bool($data['obligatorio']);
            }
        } else {
            $this->id = null;
            $this->codigo = null;
            $this->nombre = '';
            $this->descripcion = '';
            $this->precio = 0.0;
            $this->tipo_precio = self::TIPO_PRECIO_FIJO;
            $this->porcentaje = null;
            $this->activo = true;
            $this->id_grupo = null;
        }
    }

    /**
     * @deprecated B1 stage shim — delegates to the bridge; removed by the WU-4 cutover.
     * @return catalogo_opcional_grupo|false
     */
    public function get_grupo()
    {
        $grupos = $this->get_grupos();

        return $grupos[0] ?? false;
    }

    /**
     * @deprecated B1 stage shim — delegates to the bridge; removed by the WU-4 cutover.
     */
    public function etiqueta_grupo(): string
    {
        $labels = $this->grupos_labels();

        return $labels[0] ?? '-';
    }

    /**
     * Bridge-backed membership list, ordered by group `orden` then `nombre`.
     *
     * @return array<int, catalogo_opcional_grupo>
     */
    public function get_grupos(): array
    {
        if (!$this->id) {
            return [];
        }

        $list = [];
        $data = $this->db->select(
            'SELECT g.* FROM ' . catalogo_opcional_grupo::TABLE . ' g'
            . ' INNER JOIN ' . catalogo_opcional_grupo_rel::TABLE . ' r ON r.id_grupo = g.id'
            . ' WHERE r.id_opcional = ' . $this->intval($this->id)
            . ' ORDER BY g.orden ASC, g.nombre ASC;'
        );
        if ($data) {
            foreach ($data as $d) {
                $list[] = new catalogo_opcional_grupo($d);
            }
        }

        return $list;
    }

    /**
     * Bridge-backed group labels (multi-membership replacement for the old
     * single-valued etiqueta_grupo()).
     *
     * @return list<string>
     */
    public function grupos_labels(): array
    {
        if (!$this->id) {
            return [];
        }

        $labels = [];
        $data = $this->db->select(
            'SELECT g.nombre FROM ' . catalogo_opcional_grupo::TABLE . ' g'
            . ' INNER JOIN ' . catalogo_opcional_grupo_rel::TABLE . ' r ON r.id_grupo = g.id'
            . ' WHERE r.id_opcional = ' . $this->intval($this->id)
            . ' ORDER BY g.orden ASC, g.nombre ASC;'
        );
        if ($data) {
            foreach ($data as $d) {
                $labels[] = (string) $d['nombre'];
            }
        }

        return $labels;
    }

    /**
     * @return list<int>
     */
    public function grupo_ids(): array
    {
        if (!$this->id) {
            return [];
        }

        return $this->grupo_rel_model()->group_ids_for_opcional((int) $this->id);
    }

    /**
     * Bridge membership test: grouped ⇔ at least one bridge row.
     */
    public function is_grouped(): bool
    {
        if (!$this->id) {
            return false;
        }

        return (bool) $this->db->select(
            'SELECT 1 FROM ' . catalogo_opcional_grupo_rel::TABLE
            . ' WHERE id_opcional = ' . $this->intval($this->id) . ' LIMIT 1;'
        );
    }

    public function es_precio_porcentaje(): bool
    {
        return $this->tipo_precio === self::TIPO_PRECIO_PORCENTAJE;
    }

    public function get_porcentaje($codlista = null)
    {
        if (!$this->es_precio_porcentaje()) {
            return null;
        }

        if ($codlista !== null && $codlista !== '') {
            $precioLista = $this->get_precio_lista($codlista);
            if ($precioLista && $precioLista->porcentaje !== null) {
                return floatval($precioLista->porcentaje);
            }
        }

        return $this->porcentaje !== null ? floatval($this->porcentaje) : null;
    }

    /**
     * Calcula el PVP del opcional en función del PVP del artículo cuando usa porcentaje.
     *
     * @param object|float $articulo Instancia articulo o PVP numérico
     */
    public function precio_para_articulo($articulo, $codlista = null): float
    {
        if ($this->es_precio_porcentaje()) {
            $porcentaje = $this->get_porcentaje($codlista);
            if ($porcentaje !== null) {
                $pvp = $this->resolveArticuloPvp($articulo);
                $decimals = defined('FS_NF0_ART') ? (int) FS_NF0_ART : 2;

                return bround($pvp * ($porcentaje / 100), $decimals);
            }
        }

        if (!$this->id) {
            return floatval($this->precio);
        }

        return $this->precio_en_lista($codlista);
    }

    public function etiqueta_precio_base(): string
    {
        if ($this->es_precio_porcentaje()) {
            $porcentaje = $this->porcentaje;
            if ($porcentaje === null) {
                return '-';
            }

            return rtrim(rtrim(number_format($porcentaje, 2, ',', '.'), '0'), ',') . '%';
        }

        return number_format($this->precio, 2, ',', '.');
    }

    public function etiqueta_precio_lista($codlista = null): string
    {
        if ($this->es_precio_porcentaje()) {
            $porcentaje = $this->get_porcentaje($codlista);
            if ($porcentaje === null) {
                return '-';
            }

            return rtrim(rtrim(number_format($porcentaje, 2, ',', '.'), '0'), ',') . '%';
        }

        return number_format($this->precio_en_lista($codlista), 2, ',', '.');
    }

    private function normalizeTipoPrecio($tipo): string
    {
        return $tipo === self::TIPO_PRECIO_PORCENTAJE
            ? self::TIPO_PRECIO_PORCENTAJE
            : self::TIPO_PRECIO_FIJO;
    }

    private function resolveArticuloPvp($articulo): float
    {
        if (is_object($articulo)) {
            if (isset($articulo->pvp)) {
                return floatval($articulo->pvp);
            }

            return floatval($articulo->precio ?? 0);
        }

        return floatval($articulo);
    }

    protected function install()
    {
        new catalogo_lista_precio();
        return '';
    }

    public function url()
    {
        if (is_null($this->id)) {
            return 'index.php?page=ventas_opcional';
        }

        return 'index.php?page=ventas_opcional&id=' . urlencode((string) $this->id);
    }

    public function url_nuevo_en_grupo(int $idGrupo): string
    {
        return 'index.php?page=ventas_opcional&id_grupo=' . urlencode((string) $idGrupo);
    }

    /**
     * Adds the opcional to a group through the bridge (idempotent) and fires
     * the "grouped ⇒ no direct article relations" cleanup on the 0 → ≥1
     * membership transition only.
     */
    public function add_to_grupo(int $idGrupo): bool
    {
        if (!$this->id || $idGrupo <= 0) {
            return false;
        }

        if (!$this->grupo_exists($idGrupo)) {
            $this->new_error_msg('Grupo no encontrado.');
            return false;
        }

        $wasGrouped = $this->is_grouped();
        if (!$this->grupo_rel_model()->add((int) $this->id, $idGrupo)) {
            return false;
        }

        $this->syncLegacyProjection();

        if (!$wasGrouped) {
            $this->deleteDirectArticleRelations();
        }

        return true;
    }

    /**
     * @deprecated B1 stage shim — delegates to add_to_grupo(); removed by the WU-4 cutover.
     */
    public function assign_to_grupo(int $idGrupo): bool
    {
        return $this->add_to_grupo($idGrupo);
    }

    /**
     * Removes one bridge membership (int form) or, when called with no
     * argument (legacy form), the currently projected group. Never restores
     * direct article relations.
     */
    public function remove_from_grupo(int $idGrupo = 0): bool
    {
        if (!$this->id) {
            return false;
        }

        if ($idGrupo <= 0) {
            $idGrupo = (int) $this->id_grupo;
        }

        $rel = $this->grupo_rel_model();
        if ($idGrupo > 0) {
            $rel->remove((int) $this->id, $idGrupo);
        } else {
            $rel->delete_all_from_opcional((int) $this->id);
        }

        $this->syncLegacyProjection();

        return true;
    }

    /**
     * Replaces the membership set with the normalized target set (`intval`,
     * `> 0`, `array_unique`). Fires the first-membership cleanup exactly once
     * on the 0 → ≥1 transition.
     */
    public function set_grupos(array $idGrupos): bool
    {
        if (!$this->id) {
            return false;
        }

        $target = [];
        foreach ($idGrupos as $idGrupo) {
            $idGrupo = (int) $idGrupo;
            if ($idGrupo > 0) {
                $target[$idGrupo] = $idGrupo;
            }
        }

        $current = $this->grupo_ids();
        $wasGrouped = $current !== [];

        $rel = $this->grupo_rel_model();
        $ok = true;
        foreach ($target as $idGrupo) {
            if (!in_array($idGrupo, $current, true)) {
                $ok = $rel->add((int) $this->id, $idGrupo) && $ok;
            }
        }
        foreach ($current as $idGrupo) {
            if (!isset($target[$idGrupo])) {
                $ok = $rel->remove((int) $this->id, $idGrupo) && $ok;
            }
        }

        $this->syncLegacyProjection();

        if (!$wasGrouped && $target !== []) {
            $this->deleteDirectArticleRelations();
        }

        return $ok;
    }

    /**
     * Bridge model accessor. Overridable seam so membership writes are
     * unit-testable without a live database.
     */
    protected function grupo_rel_model(): catalogo_opcional_grupo_rel
    {
        return new catalogo_opcional_grupo_rel();
    }

    /**
     * Direct article↔opcional relation accessor. Overridable seam.
     */
    protected function articulo_opcional_model(): catalogo_articulo_opcional
    {
        return new catalogo_articulo_opcional();
    }

    private function grupo_exists(int $idGrupo): bool
    {
        return (bool) $this->db->select(
            'SELECT * FROM ' . catalogo_opcional_grupo::TABLE
            . ' WHERE id = ' . $this->intval($idGrupo) . ';'
        );
    }

    /**
     * Dual-write (B1 stage-safe): projects the bridge membership into the
     * frozen `id_grupo` column so not-yet-migrated readers keep working. The
     * lowest group id wins; no membership projects to NULL.
     */
    private function syncLegacyProjection(): void
    {
        if (!$this->id) {
            return;
        }

        $ids = $this->grupo_ids();
        $this->id_grupo = $ids === [] ? null : (int) $ids[0];

        $this->db->exec(
            'UPDATE ' . $this->table_name
            . ' SET id_grupo = ' . $this->var2str($this->id_grupo)
            . ' WHERE id = ' . $this->intval($this->id) . ';'
        );
    }

    /**
     * Dual-write (B1 stage-safe): reconciles the bridge with the legacy
     * `id_grupo` value written by save(), so both sources agree.
     */
    private function syncBridgeFromLegacyGroup(): void
    {
        if (!$this->id) {
            return;
        }

        $idGrupo = is_numeric($this->id_grupo) ? (int) $this->id_grupo : 0;
        $rel = $this->grupo_rel_model();

        if ($idGrupo <= 0) {
            $rel->delete_all_from_opcional((int) $this->id);
            return;
        }

        if ($rel->group_ids_for_opcional((int) $this->id) === [$idGrupo]) {
            return;
        }

        $rel->delete_all_from_opcional((int) $this->id);
        $rel->add((int) $this->id, $idGrupo);
    }

    private function deleteDirectArticleRelations(): void
    {
        if (!$this->id) {
            return;
        }

        $this->articulo_opcional_model()->delete_all_from_opcional((int) $this->id);
    }

    /**
     * Loose opcionales: no bridge row in any group (OPG-04).
     *
     * @return array<int, static>
     */
    public function all_sin_grupo(int $offset = 0, int $limit = FS_ITEM_LIMIT): array
    {
        $list = [];
        $data = $this->db->select_limit(
            'SELECT * FROM ' . $this->table_name . ' o'
            . ' WHERE NOT EXISTS (SELECT 1 FROM ' . catalogo_opcional_grupo_rel::TABLE . ' r WHERE r.id_opcional = o.id)'
            . ' ORDER BY o.nombre ASC',
            $limit,
            $offset
        );
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    /**
     * Active loose opcionales (bridge anti-join). Used by the TPV available
     * list so grouped opcionales are never offered as loose.
     *
     * @return array<int, static>
     */
    public function all_activos_sin_grupo(int $offset = 0, int $limit = FS_ITEM_LIMIT): array
    {
        $list = [];
        $data = $this->db->select_limit(
            'SELECT * FROM ' . $this->table_name . ' o'
            . ' WHERE o.activo = TRUE AND NOT EXISTS (SELECT 1 FROM '
            . catalogo_opcional_grupo_rel::TABLE . ' r WHERE r.id_opcional = o.id)'
            . ' ORDER BY o.nombre ASC',
            $limit,
            $offset
        );
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    /**
     * Available list for a group editor (OPG-07): every opcional not already
     * in this group, including members of other groups.
     *
     * @return array<int, static>
     */
    public function all_not_in_grupo(int $idGrupo, int $offset = 0, int $limit = FS_ITEM_LIMIT): array
    {
        $list = [];
        $data = $this->db->select_limit(
            'SELECT * FROM ' . $this->table_name . ' o'
            . ' WHERE NOT EXISTS (SELECT 1 FROM ' . catalogo_opcional_grupo_rel::TABLE . ' r'
            . ' WHERE r.id_opcional = o.id AND r.id_grupo = ' . $this->intval($idGrupo) . ')'
            . ' ORDER BY o.nombre ASC',
            $limit,
            $offset
        );
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    public function get($id)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($id) . ';');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function get_by_codigo($codigo)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE codigo = ' . $this->var2str($codigo) . ';');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function get_new_codigo()
    {
        $data = $this->db->select('SELECT MAX(id) as max_id FROM ' . $this->table_name . ';');
        $num = 1;
        if ($data && $data[0]['max_id']) {
            $num = intval($data[0]['max_id']) + 1;
        }

        return 'OPC' . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
    }

    public function get_familias()
    {
        $rel = new catalogo_opcional_familia();
        return $rel->get_familias_from_opcional($this->id);
    }

    public function get_articulos()
    {
        $rel = new catalogo_articulo_opcional();
        return $rel->get_articulos_from_opcional($this->id);
    }

    public function add_familia($codfamilia)
    {
        $rel = new catalogo_opcional_familia();
        return $rel->add_with_propagation($this->id, $codfamilia);
    }

    public function add_familia_only($codfamilia)
    {
        $rel = new catalogo_opcional_familia();
        return $rel->add($this->id, $codfamilia);
    }

    public function remove_familia($codfamilia)
    {
        $rel = new catalogo_opcional_familia();
        return $rel->remove_with_propagation($this->id, $codfamilia);
    }

    public function remove_familia_only($codfamilia)
    {
        $rel = new catalogo_opcional_familia();
        return $rel->remove($this->id, $codfamilia);
    }

    public function exists()
    {
        if (is_null($this->id)) {
            return false;
        }

        return $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
    }

    public function test()
    {
        $this->codigo = $this->no_html(trim((string) $this->codigo));
        $this->nombre = $this->no_html($this->nombre);
        $this->descripcion = $this->no_html($this->descripcion);

        if (mb_strlen($this->codigo) < 1 || mb_strlen($this->codigo) > 20) {
            $this->new_error_msg('Código de opcional no válido. Debe tener entre 1 y 20 caracteres.');
            return false;
        }

        if (mb_strlen($this->nombre) < 1 || mb_strlen($this->nombre) > 100) {
            $this->new_error_msg('Nombre de opcional no válido.');
            return false;
        }

        $existente = $this->get_by_codigo($this->codigo);
        if ($existente && $existente->id != $this->id) {
            $this->new_error_msg('Ya existe un opcional con el código: ' . $this->codigo);
            return false;
        }

        $this->tipo_precio = $this->normalizeTipoPrecio($this->tipo_precio);

        if ($this->es_precio_porcentaje()) {
            if ($this->porcentaje === null || $this->porcentaje < 0) {
                $this->new_error_msg('El porcentaje del opcional no es válido.');
                return false;
            }
        } elseif ($this->precio < 0) {
            $this->new_error_msg('El precio del opcional no puede ser negativo.');
            return false;
        }

        return true;
    }

    public function save()
    {
        if (!$this->test()) {
            return false;
        }

        if ($this->exists()) {
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'codigo = ' . $this->var2str($this->codigo)
                . ', nombre = ' . $this->var2str($this->nombre)
                . ', descripcion = ' . $this->var2str($this->descripcion)
                . ', precio = ' . $this->var2str($this->precio)
                . ', tipo_precio = ' . $this->var2str($this->tipo_precio)
                . ', porcentaje = ' . $this->var2str($this->porcentaje)
                . ', activo = ' . $this->var2str($this->activo)
                . ', id_grupo = ' . $this->var2str($this->id_grupo)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (codigo, nombre, descripcion, precio, tipo_precio, porcentaje, activo, id_grupo) VALUES ('
                . $this->var2str($this->codigo) . ','
                . $this->var2str($this->nombre) . ','
                . $this->var2str($this->descripcion) . ','
                . $this->var2str($this->precio) . ','
                . $this->var2str($this->tipo_precio) . ','
                . $this->var2str($this->porcentaje) . ','
                . $this->var2str($this->activo) . ','
                . $this->var2str($this->id_grupo) . ');';
        }

        if ($this->db->exec($sql)) {
            if (is_null($this->id)) {
                $this->id = $this->db->lastval();
            }

            $this->syncBridgeFromLegacyGroup();

            if ($this->id_grupo) {
                $this->deleteDirectArticleRelations();
            }

            return true;
        }

        return false;
    }

    public function delete()
    {
        if ($this->id) {
            $this->grupo_rel_model()->delete_all_from_opcional((int) $this->id);
        }

        return $this->db->exec('DELETE FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
    }

    public function all($offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $list = [];
        $data = $this->db->select_limit('SELECT * FROM ' . $this->table_name . ' ORDER BY nombre ASC', $limit, $offset);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    public function all_activos($offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $list = [];
        $data = $this->db->select_limit('SELECT * FROM ' . $this->table_name
            . ' WHERE activo = TRUE ORDER BY nombre ASC', $limit, $offset);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    /**
     * Lists opcionales honoring the legacy filters plus an optional trailing
     * group filter (design AD-5/AD-6). `$id_grupo` is appended last so the
     * pre-existing positional callers stay valid.
     *
     * @param string $query
     * @param int    $offset
     * @param string $codfamilia
     * @param string $codlista
     * @param bool   $solo_activos
     * @param string $id_grupo    ''=all, '0'=Sin grupo, numeric=group id
     * @return array
     */
    public function search($query = '', $offset = 0, $codfamilia = '', $codlista = '', $solo_activos = false, $id_grupo = '')
    {
        $list = [];
        $query = $this->no_html(mb_strtolower($query, 'UTF8'));
        $where_conditions = [];
        $familiaTable = catalogo_opcional_familia::TABLE;
        $precioTable = catalogo_opcional_precio::TABLE;
        $grupo_condition = $this->where_id_grupo($id_grupo, 'o');

        if ($codfamilia != '' || ($codlista != '' && $solo_activos) || $grupo_condition !== '') {
            $sql = 'SELECT DISTINCT o.* FROM ' . $this->table_name . ' o';

            if ($codfamilia != '') {
                $sql .= ' INNER JOIN ' . $familiaTable . ' of ON o.id = of.id_opcional';
                $where_conditions[] = 'of.codfamilia = ' . $this->var2str($codfamilia);
            }

            if ($codlista != '' && $solo_activos) {
                $sql .= ' INNER JOIN ' . $precioTable . ' op ON o.id = op.id_opcional';
                $where_conditions[] = 'op.codlista = ' . $this->var2str($codlista);
            }

            if ($grupo_condition !== '') {
                $where_conditions[] = $grupo_condition;
            }

            if ($query != '') {
                $where_conditions[] = "(lower(o.codigo) LIKE '%" . $query . "%' OR lower(o.nombre) LIKE '%" . $query . "%')";
            }

            if (count($where_conditions) > 0) {
                $sql .= ' WHERE ' . implode(' AND ', $where_conditions);
            }
            $sql .= ' ORDER BY o.nombre ASC';
        } else {
            $sql = 'SELECT * FROM ' . $this->table_name;
            if ($query != '') {
                $sql .= " WHERE lower(codigo) LIKE '%" . $query . "%'"
                    . " OR lower(nombre) LIKE '%" . $query . "%'";
            }
            $sql .= ' ORDER BY nombre ASC';
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
     * Builds the group WHERE condition shared by the search paths (design
     * AD-9): `''` disables the filter, `'0'` selects loose rows through the
     * bridge anti-join, a numeric value selects that group through `EXISTS`.
     * `intval()` keeps the id non-injectable.
     *
     * @param string $id_grupo
     * @param string $alias
     * @return string empty when no filter applies
     */
    protected function where_id_grupo($id_grupo, string $alias = 'o'): string
    {
        $id_grupo = trim((string) $id_grupo);
        if ($id_grupo === '') {
            return '';
        }

        $bridge = catalogo_opcional_grupo_rel::TABLE;
        if ($id_grupo === '0') {
            return 'NOT EXISTS (SELECT 1 FROM ' . $bridge . ' r WHERE r.id_opcional = ' . $alias . '.id)';
        }

        return 'EXISTS (SELECT 1 FROM ' . $bridge . ' r WHERE r.id_opcional = ' . $alias
            . '.id AND r.id_grupo = ' . intval($id_grupo) . ')';
    }

    public function count()
    {
        $data = $this->db->select('SELECT COUNT(*) as total FROM ' . $this->table_name . ';');
        if ($data) {
            return intval($data[0]['total']);
        }

        return 0;
    }

    public function get_precios_listas()
    {
        $precio_model = new catalogo_opcional_precio();
        return $precio_model->all_from_opcional($this->id);
    }

    public function get_precio_lista($codlista)
    {
        $precio_model = new catalogo_opcional_precio();
        return $precio_model->get($this->id, $codlista);
    }

    public function precio_en_lista($codlista = null)
    {
        if ($codlista === null || $codlista === '') {
            $default = (new catalogo_lista_precio())->get_default();
            $codlista = $default ? $default->codlista : catalogo_lista_precio::DEFAULT_CODE;
        }

        $precio = $this->get_precio_lista($codlista);
        if ($precio) {
            return $precio->precio;
        }

        return $this->precio;
    }

    public function set_precio_lista($codlista, $precio)
    {
        $precio_model = new catalogo_opcional_precio();
        $p = $precio_model->get($this->id, $codlista);

        if ($p) {
            $p->precio = floatval($precio);
            $p->porcentaje = null;
        } else {
            $p = new catalogo_opcional_precio();
            $p->id_opcional = $this->id;
            $p->codlista = $codlista;
            $p->precio = floatval($precio);
            $p->porcentaje = null;
        }

        return $p->save();
    }

    public function set_porcentaje_lista($codlista, $porcentaje)
    {
        $precio_model = new catalogo_opcional_precio();
        $p = $precio_model->get($this->id, $codlista);

        if ($p) {
            $p->porcentaje = floatval($porcentaje);
            $p->precio = 0.0;
        } else {
            $p = new catalogo_opcional_precio();
            $p->id_opcional = $this->id;
            $p->codlista = $codlista;
            $p->porcentaje = floatval($porcentaje);
            $p->precio = 0.0;
        }

        return $p->save();
    }

    public function delete_precio_lista($codlista)
    {
        $precio_model = new catalogo_opcional_precio();
        $p = $precio_model->get($this->id, $codlista);
        if ($p) {
            return $p->delete();
        }

        return true;
    }

    public function delete_precios_listas()
    {
        $precio_model = new catalogo_opcional_precio();
        return $precio_model->delete_from_opcional($this->id);
    }
}
