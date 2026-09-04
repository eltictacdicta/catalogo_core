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
 * Membresía de un usuario en un grupo de acceso a precios (rol dentro del grupo).
 */
class catalogo_grupo_usuario extends \fs_model
{
    public const TABLE = 'catalogo_grupo_usuarios';

    public $id_grupo;
    public $nick;
    public $rol;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id_grupo = isset($data['id_grupo']) ? intval($data['id_grupo']) : null;
            $this->nick = $data['nick'];
            $this->rol = $data['rol'];
        } else {
            $this->id_grupo = null;
            $this->nick = null;
            $this->rol = null;
        }
    }

    protected function install()
    {
        return '';
    }

    public function get($id_grupo, $nick)
    {
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($id_grupo)
            . ' AND nick = ' . $this->var2str($nick) . ';';
        $data = $this->db->select($sql);
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function all_from_nick($nick)
    {
        $list = [];
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE nick = ' . $this->var2str($nick)
            . ' ORDER BY id_grupo ASC;';
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    public function all_from_grupo($id_grupo)
    {
        $list = [];
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($id_grupo)
            . ' ORDER BY nick ASC;';
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
        if (is_null($this->id_grupo) || is_null($this->nick)) {
            return false;
        }

        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($this->id_grupo)
            . ' AND nick = ' . $this->var2str($this->nick) . ';';

        return $this->db->select($sql);
    }

    public function test()
    {
        $this->nick = $this->no_html(trim((string) $this->nick));
        $this->rol = $this->no_html(trim((string) $this->rol));

        if (is_null($this->id_grupo) || $this->id_grupo < 1) {
            $this->new_error_msg('Grupo no válido.');
            return false;
        }

        if (mb_strlen($this->nick) < 1 || mb_strlen($this->nick) > 50) {
            $this->new_error_msg('Nick de usuario no válido.');
            return false;
        }

        if (!in_array($this->rol, catalogo_grupo::ROLES, true)) {
            $this->new_error_msg('Rol de grupo no válido.');
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
                . 'rol = ' . $this->var2str($this->rol)
                . ' WHERE id_grupo = ' . $this->var2str($this->id_grupo)
                . ' AND nick = ' . $this->var2str($this->nick) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (id_grupo, nick, rol) VALUES ('
                . $this->var2str($this->id_grupo) . ','
                . $this->var2str($this->nick) . ','
                . $this->var2str($this->rol) . ');';
        }

        return $this->db->exec($sql);
    }

    public function delete()
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($this->id_grupo)
            . ' AND nick = ' . $this->var2str($this->nick) . ';';

        return $this->db->exec($sql);
    }

    public function delete_from_grupo($id_grupo)
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($id_grupo) . ';';

        return $this->db->exec($sql);
    }
}
