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

require_once __DIR__ . '/catalogo_idioma.php';

/**
 * Nombre multiidioma de un grupo de opcionales.
 *
 * Clave natural (codigo, codidioma), UNIQUE y cascada al borrar el grupo.
 * Un nombre vacío significa "ausente" y elimina la fila al guardar.
 */
class catalogo_opcional_grupo_idioma extends \fs_model
{
    public const TABLE = 'catalogo_opcional_grupo_idiomas';

    public $id;
    public $codigo;
    public $codidioma;
    public $nombre;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = intval($data['id']);
            $this->codigo = $data['codigo'];
            $this->codidioma = $data['codidioma'];
            $this->nombre = $data['nombre'];
        } else {
            $this->id = null;
            $this->codigo = null;
            $this->codidioma = catalogo_idioma::DEFAULT_CODE;
            $this->nombre = '';
        }
    }

    protected function install()
    {
        return '';
    }

    public function get($id)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($id) . ';');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function get_by_grupo_idioma($codigo, $codidioma)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE codigo = ' . $this->var2str($codigo)
            . ' AND codidioma = ' . $this->var2str($codidioma) . ';');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    /**
     * @return array<int, static>
     */
    public function all_from_grupo($codigo): array
    {
        $list = [];
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE codigo = ' . $this->var2str($codigo)
            . ' ORDER BY codidioma ASC;');
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

        return $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
    }

    public function test()
    {
        $this->codigo = $this->no_html(trim((string) $this->codigo));
        $this->codidioma = trim((string) $this->codidioma);
        $this->nombre = $this->no_html(trim((string) $this->nombre));

        if (mb_strlen($this->codigo) < 1 || mb_strlen($this->codigo) > 20) {
            $this->new_error_msg('Código de grupo no válido.');
            return false;
        }

        if (mb_strlen($this->codidioma) < 2) {
            $this->new_error_msg('Código de idioma no válido.');
            return false;
        }

        if (mb_strlen($this->nombre) > 100) {
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

        if ($this->nombre === '') {
            if (!$this->exists()) {
                return true;
            }

            return $this->delete();
        }

        if ($this->exists()) {
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'codigo = ' . $this->var2str($this->codigo)
                . ', codidioma = ' . $this->var2str($this->codidioma)
                . ', nombre = ' . $this->var2str($this->nombre)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (codigo, codidioma, nombre) VALUES ('
                . $this->var2str($this->codigo) . ','
                . $this->var2str($this->codidioma) . ','
                . $this->var2str($this->nombre) . ');';
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
        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';'
        );
    }
}
