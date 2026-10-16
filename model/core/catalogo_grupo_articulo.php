<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
namespace FSFramework\model;

/**
 * Miembro de grupo de acceso a precios (referencia).
 */
class catalogo_grupo_articulo extends \fs_model
{
    public const TABLE = 'catalogo_grupo_articulos';

    public $id_grupo;
    public $referencia;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id_grupo = isset($data['id_grupo']) ? intval($data['id_grupo']) : null;
            $this->referencia = $data['referencia'];
        } else {
            $this->id_grupo = null;
            $this->referencia = null;
        }
    }

    protected function install()
    {
        return '';
    }

    public function get($id_grupo, $referencia)
    {
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($id_grupo)
            . ' AND referencia = ' . $this->var2str($referencia) . ';';
        $data = $this->db->select($sql);
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function all_from_grupo($id_grupo)
    {
        $list = [];
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($id_grupo)
            . ' ORDER BY referencia ASC;';
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    public function exists()
    {
        if (is_null($this->id_grupo) || is_null($this->referencia)) {
            return false;
        }

        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($this->id_grupo)
            . ' AND referencia = ' . $this->var2str($this->referencia) . ';';

        return $this->db->select($sql);
    }

    public function test()
    {
        $this->referencia = $this->no_html(trim((string) $this->referencia));
        if (is_null($this->id_grupo) || $this->id_grupo < 1) {
            $this->new_error_msg('Grupo no válido.');
            return false;
        }
        if (is_null($this->referencia) || mb_strlen($this->referencia) < 1 || mb_strlen($this->referencia) > 20) {
            $this->new_error_msg('Referencia de artículo no válida.');
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
            return true;
        }

        $sql = 'INSERT INTO ' . $this->table_name . ' (id_grupo, referencia) VALUES ('
            . $this->var2str($this->id_grupo) . ','
            . $this->var2str($this->referencia) . ');';

        return $this->db->exec($sql);
    }

    public function delete()
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($this->id_grupo)
            . ' AND referencia = ' . $this->var2str($this->referencia) . ';';

        return $this->db->exec($sql);
    }

    public function delete_from_grupo($id_grupo)
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($id_grupo) . ';';

        return $this->db->exec($sql);
    }
}
