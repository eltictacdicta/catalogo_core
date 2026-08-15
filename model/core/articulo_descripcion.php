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
 * Descripción multiidioma de un artículo.
 */
class articulo_descripcion extends \fs_model
{
    public const TABLE = 'articulo_descripciones';

    public $id;
    public $referencia;
    public $codidioma;
    public $descripcion;
    public $descripcion_corta;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = intval($data['id']);
            $this->referencia = $data['referencia'];
            $this->codidioma = $data['codidioma'];
            $this->descripcion = $data['descripcion'];
            $this->descripcion_corta = $data['descripcion_corta'];
        } else {
            $this->id = null;
            $this->referencia = null;
            $this->codidioma = catalogo_idioma::DEFAULT_CODE;
            $this->descripcion = '';
            $this->descripcion_corta = null;
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

    public function get_by_articulo_idioma($referencia, $codidioma)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($referencia)
            . ' AND codidioma = ' . $this->var2str($codidioma) . ';');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function all_from_articulo($referencia)
    {
        $list = [];
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($referencia)
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
        $this->descripcion = $this->no_html($this->descripcion);
        $this->descripcion_corta = $this->no_html($this->descripcion_corta);

        if (is_null($this->referencia) || strlen($this->referencia) < 1) {
            $this->new_error_msg('Referencia de artículo no válida.');
            return false;
        }

        if (is_null($this->codidioma) || strlen($this->codidioma) < 2) {
            $this->new_error_msg('Código de idioma no válido.');
            return false;
        }

        if (mb_strlen($this->descripcion) < 1) {
            $this->new_error_msg('La descripción no puede estar vacía.');
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
                . 'referencia = ' . $this->var2str($this->referencia)
                . ', codidioma = ' . $this->var2str($this->codidioma)
                . ', descripcion = ' . $this->var2str($this->descripcion)
                . ', descripcion_corta = ' . $this->var2str($this->descripcion_corta)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (referencia, codidioma, descripcion, descripcion_corta) VALUES ('
                . $this->var2str($this->referencia) . ','
                . $this->var2str($this->codidioma) . ','
                . $this->var2str($this->descripcion) . ','
                . $this->var2str($this->descripcion_corta) . ');';
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
        return $this->db->exec('DELETE FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
    }
}
