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
 * Grupo de acceso a precios del catálogo (multitarifa, R-RG-001).
 */
class catalogo_grupo extends \fs_model
{
    public const TABLE = 'catalogo_grupo';

    public const ROLES = ['gestor', 'editor', 'revisor', 'visualizador'];

    public $id;
    public $nombre;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = isset($data['id']) ? intval($data['id']) : null;
            $this->nombre = $data['nombre'];
        } else {
            $this->id = null;
            $this->nombre = '';
        }
    }

    protected function install()
    {
        return '';
    }

    public function get($id)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE id = ' . $this->var2str($id) . ';');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function all()
    {
        $list = [];
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' ORDER BY nombre ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    public function exists()
    {
        if (is_null($this->id)) {
            return false;
        }

        return $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE id = ' . $this->var2str($this->id) . ';');
    }

    public function test()
    {
        $this->nombre = $this->no_html(trim((string) $this->nombre));

        if (mb_strlen($this->nombre) < 1 || mb_strlen($this->nombre) > 50) {
            $this->new_error_msg('Nombre de grupo no válido.');
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
                . 'nombre = ' . $this->var2str($this->nombre)
                . ' WHERE id = ' . $this->var2str($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (nombre) VALUES ('
                . $this->var2str($this->nombre) . ');';
        }

        return $this->db->exec($sql);
    }

    public function delete()
    {
        $sql = 'DELETE FROM ' . $this->table_name . ' WHERE id = ' . $this->var2str($this->id) . ';';

        return $this->db->exec($sql);
    }
}
