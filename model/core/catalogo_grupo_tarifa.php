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
 * Miembro de grupo de acceso a precios (codlista).
 */
class catalogo_grupo_tarifa extends \fs_model
{
    public const TABLE = 'catalogo_grupo_tarifas';

    public $id_grupo;
    public $codlista;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id_grupo = isset($data['id_grupo']) ? intval($data['id_grupo']) : null;
            $this->codlista = $data['codlista'];
        } else {
            $this->id_grupo = null;
            $this->codlista = null;
        }
    }

    protected function install()
    {
        return '';
    }

    public function get($id_grupo, $codlista)
    {
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($id_grupo)
            . ' AND codlista = ' . $this->var2str($codlista) . ';';
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
            . ' ORDER BY codlista ASC;';
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
        if (is_null($this->id_grupo) || is_null($this->codlista)) {
            return false;
        }

        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($this->id_grupo)
            . ' AND codlista = ' . $this->var2str($this->codlista) . ';';

        return $this->db->select($sql);
    }

    public function test()
    {
        $this->codlista = $this->no_html(trim((string) $this->codlista));
        if (is_null($this->id_grupo) || $this->id_grupo < 1) {
            $this->new_error_msg('Grupo no válido.');
            return false;
        }
        if (mb_strlen($this->codlista) < 1 || mb_strlen($this->codlista) > 20) {
            $this->new_error_msg('Código de lista no válido.');
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

        $sql = 'INSERT INTO ' . $this->table_name . ' (id_grupo, codlista) VALUES ('
            . $this->var2str($this->id_grupo) . ','
            . $this->var2str($this->codlista) . ');';

        return $this->db->exec($sql);
    }

    public function delete()
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($this->id_grupo)
            . ' AND codlista = ' . $this->var2str($this->codlista) . ';';

        return $this->db->exec($sql);
    }

    public function delete_from_grupo($id_grupo)
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->var2str($id_grupo) . ';';

        return $this->db->exec($sql);
    }
}
