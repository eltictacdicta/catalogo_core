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
 * Nombre y descripción multiidioma de un opcional.
 *
 * Espeja articulo_descripcion: clave natural (codigo, codidioma), UNIQUE y
 * cascada al borrar el opcional. Un par vacío (nombre y descripción vacíos)
 * significa "ausente" y elimina la fila al guardar.
 */
class catalogo_opcional_idioma extends \fs_model
{
    public const TABLE = 'catalogo_opcional_idiomas';

    public $id;
    public $codigo;
    public $codidioma;
    public $nombre;
    public $descripcion;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = intval($data['id']);
            $this->codigo = $data['codigo'];
            $this->codidioma = $data['codidioma'];
            $this->nombre = $data['nombre'];
            $this->descripcion = $data['descripcion'] ?? null;
        } else {
            $this->id = null;
            $this->codigo = null;
            $this->codidioma = catalogo_idioma::DEFAULT_CODE;
            $this->nombre = '';
            $this->descripcion = null;
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

    public function get_by_opcional_idioma($codigo, $codidioma)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE codigo = ' . $this->var2str($codigo)
            . ' AND codidioma = ' . $this->var2str($codidioma) . ';');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function all_from_opcional($codigo)
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
        $this->nombre = $this->no_html((string) $this->nombre);
        $this->descripcion = $this->no_html($this->descripcion);

        if (mb_strlen($this->codigo) < 1 || mb_strlen($this->codigo) > 20) {
            $this->new_error_msg('Código de opcional no válido.');
            return false;
        }

        if (mb_strlen($this->codidioma) < 2) {
            $this->new_error_msg('Código de idioma no válido.');
            return false;
        }

        // Un nombre vacío es válido: la ausencia para un idioma es un estado
        // legítimo y el par vacío se convierte en borrado en save().
        return true;
    }

    /**
     * Un par vacío (nombre Y descripción vacíos) significa "ausente".
     */
    private function isEmptyPair(): bool
    {
        return mb_strlen((string) $this->nombre) < 1
            && mb_strlen((string) $this->descripcion) < 1;
    }

    public function save()
    {
        if (!$this->test()) {
            return false;
        }

        if ($this->isEmptyPair()) {
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
                . ', descripcion = ' . $this->var2str($this->descripcion)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (codigo, codidioma, nombre, descripcion) VALUES ('
                . $this->var2str($this->codigo) . ','
                . $this->var2str($this->codidioma) . ','
                . $this->var2str($this->nombre) . ','
                . $this->var2str($this->descripcion) . ');';
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
