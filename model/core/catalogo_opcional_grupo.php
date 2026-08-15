<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
namespace FSFramework\model;

/**
 * Grupo de opcionales (p. ej. Color con variantes Blanco, Rojo).
 */
class catalogo_opcional_grupo extends \fs_model
{
    public const TABLE = 'catalogo_opcional_grupos';

    public $id;
    public $codigo;
    public $nombre;
    public $exclusivo;
    public $activo;
    public $orden;
    /** @var bool Solo relevante al cargar grupos asignados a un artículo concreto. */
    public $obligatorio_en_articulo = false;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = intval($data['id']);
            $this->codigo = $data['codigo'];
            $this->nombre = $data['nombre'];
            $this->exclusivo = $this->str2bool($data['exclusivo'] ?? true);
            $this->activo = $this->str2bool($data['activo'] ?? true);
            $this->orden = intval($data['orden'] ?? 0);
            if (array_key_exists('obligatorio_en_articulo', $data)) {
                $this->obligatorio_en_articulo = $this->str2bool($data['obligatorio_en_articulo']);
            } elseif (array_key_exists('obligatorio', $data)) {
                $this->obligatorio_en_articulo = $this->str2bool($data['obligatorio']);
            }
        } else {
            $this->clear();
        }
    }

    public function url()
    {
        if (is_null($this->id)) {
            return 'index.php?page=ventas_opcional_grupo';
        }

        return 'index.php?page=ventas_opcional_grupo&id=' . urlencode((string) $this->id);
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

        return 'GRP' . str_pad((string) $num, 4, '0', STR_PAD_LEFT);
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
        $this->nombre = $this->no_html(trim((string) $this->nombre));
        $this->orden = intval($this->orden);

        if (mb_strlen($this->codigo) < 1 || mb_strlen($this->codigo) > 20) {
            $this->new_error_msg('Código de grupo no válido. Debe tener entre 1 y 20 caracteres.');
            return false;
        }

        if (mb_strlen($this->nombre) < 1 || mb_strlen($this->nombre) > 100) {
            $this->new_error_msg('Nombre de grupo no válido.');
            return false;
        }

        $existente = $this->get_by_codigo($this->codigo);
        if ($existente && $existente->id != $this->id) {
            $this->new_error_msg('Ya existe un grupo con el código: ' . $this->codigo);
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
                . ', exclusivo = ' . $this->var2str($this->exclusivo)
                . ', activo = ' . $this->var2str($this->activo)
                . ', orden = ' . $this->var2str($this->orden)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (codigo, nombre, exclusivo, activo, orden) VALUES ('
                . $this->var2str($this->codigo) . ','
                . $this->var2str($this->nombre) . ','
                . $this->var2str($this->exclusivo) . ','
                . $this->var2str($this->activo) . ','
                . $this->var2str($this->orden) . ');';
        }

        if ($this->db->exec($sql)) {
            if (is_null($this->id)) {
                $this->id = $this->db->lastval();
            }

            return true;
        }

        return false;
    }

    public function delete()
    {
        if (!$this->id) {
            return false;
        }

        $this->db->exec(
            'UPDATE catalogo_opcionales SET id_grupo = NULL WHERE id_grupo = ' . $this->intval($this->id) . ';'
        );
        $rel = new catalogo_articulo_opcional_grupo();
        $rel->delete_all_from_grupo((int) $this->id);

        return $this->db->exec('DELETE FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
    }

    /**
     * @return array<int, catalogo_opcional>
     */
    public function get_opcionales(): array
    {
        if (!$this->id) {
            return [];
        }

        $list = [];
        $data = $this->db->select('SELECT * FROM ' . catalogo_opcional::TABLE
            . ' WHERE id_grupo = ' . $this->intval($this->id)
            . ' ORDER BY nombre ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new catalogo_opcional($d);
            }
        }

        return $list;
    }

    public function count_opcionales(): int
    {
        if (!$this->id) {
            return 0;
        }

        $data = $this->db->select('SELECT COUNT(*) as total FROM ' . catalogo_opcional::TABLE
            . ' WHERE id_grupo = ' . $this->intval($this->id) . ';');
        if ($data) {
            return (int) $data[0]['total'];
        }

        return 0;
    }

    public function count_articulos(): int
    {
        if (!$this->id) {
            return 0;
        }

        $data = $this->db->select('SELECT COUNT(*) as total FROM ' . catalogo_articulo_opcional_grupo::TABLE
            . ' WHERE id_grupo = ' . $this->intval($this->id) . ';');
        if ($data) {
            return (int) $data[0]['total'];
        }

        return 0;
    }

    /**
     * @return array<int, catalogo_opcional>
     */
    public function get_opcionales_activos(): array
    {
        if (!$this->id) {
            return [];
        }

        $list = [];
        $data = $this->db->select('SELECT * FROM ' . catalogo_opcional::TABLE
            . ' WHERE id_grupo = ' . $this->intval($this->id)
            . ' AND activo = TRUE'
            . ' ORDER BY nombre ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new catalogo_opcional($d);
            }
        }

        return $list;
    }

    public function all($offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $list = [];
        $data = $this->db->select_limit(
            'SELECT * FROM ' . $this->table_name . ' ORDER BY orden ASC, nombre ASC',
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

    public function all_activos($offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $list = [];
        $data = $this->db->select_limit(
            'SELECT * FROM ' . $this->table_name . ' WHERE activo = TRUE ORDER BY orden ASC, nombre ASC',
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

    public function search($query, $offset = 0, $limit = FS_ITEM_LIMIT)
    {
        $query = trim((string) $query);
        if ($query === '') {
            return $this->all($offset, $limit);
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $query) . '%';
        $list = [];
        $data = $this->db->select_limit(
            'SELECT * FROM ' . $this->table_name
            . ' WHERE codigo LIKE ' . $this->var2str($like)
            . ' OR nombre LIKE ' . $this->var2str($like)
            . ' ORDER BY orden ASC, nombre ASC',
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

    private function clear()
    {
        $this->id = null;
        $this->codigo = null;
        $this->nombre = '';
        $this->exclusivo = true;
        $this->activo = true;
        $this->orden = 0;
    }
}
