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
 * Descripción multiidioma de una familia. `familias.descripcion` sigue siendo
 * el texto del idioma por defecto; esta tabla guarda las traducciones.
 */
class familia_descripcion extends \fs_model
{
    public $id;
    public $codfamilia;
    public $codidioma;
    public $descripcion;

    public function __construct($data = false)
    {
        parent::__construct('familia_descripciones');

        $this->id = $data ? intval($data['id']) : null;
        $this->codfamilia = $data ? $data['codfamilia'] : null;
        $this->codidioma = $data ? $data['codidioma'] : null;
        $this->descripcion = $data ? $data['descripcion'] : '';
    }

    protected function install()
    {
        new familia();
        return '';
    }

    public function get_by_familia_idioma($codfamilia, $codidioma)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE codfamilia = ' . $this->var2str($codfamilia)
            . ' AND codidioma = ' . $this->var2str($codidioma) . ';');

        return $data ? new static($data[0]) : false;
    }

    /**
     * @return array<string,string> codidioma => descripcion
     */
    public function all_from_familia($codfamilia): array
    {
        $map = [];
        $data = $this->db->select('SELECT codidioma, descripcion FROM ' . $this->table_name
            . ' WHERE codfamilia = ' . $this->var2str($codfamilia) . ';');
        foreach ($data ?: [] as $d) {
            $map[(string) $d['codidioma']] = (string) $d['descripcion'];
        }

        return $map;
    }

    /**
     * Upsert of one translation. An empty text removes the row (absence is
     * "no translation", same rule as articulo_descripcion).
     */
    public function set_descripcion($codfamilia, $codidioma, $descripcion): bool
    {
        $row = $this->get_by_familia_idioma($codfamilia, $codidioma) ?: new static();
        $row->codfamilia = $codfamilia;
        $row->codidioma = $codidioma;
        $row->descripcion = trim((string) $descripcion);

        return (bool) $row->save();
    }

    public function exists()
    {
        return !is_null($this->id)
            && $this->db->select('SELECT id FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
    }

    public function save()
    {
        $this->descripcion = mb_substr($this->no_html((string) $this->descripcion), 0, 100);
        if (empty($this->codfamilia) || strlen((string) $this->codidioma) < 2) {
            $this->new_error_msg('Familia o idioma no válidos.');
            return false;
        }

        if ($this->descripcion === '') {
            return $this->exists() ? (bool) $this->delete() : true;
        }

        if ($this->exists()) {
            return (bool) $this->db->exec('UPDATE ' . $this->table_name
                . ' SET descripcion = ' . $this->var2str($this->descripcion)
                . ' WHERE id = ' . $this->intval($this->id) . ';');
        }

        return (bool) $this->db->exec('INSERT INTO ' . $this->table_name . ' (codfamilia, codidioma, descripcion) VALUES ('
            . $this->var2str($this->codfamilia) . ','
            . $this->var2str($this->codidioma) . ','
            . $this->var2str($this->descripcion) . ');');
    }

    public function delete()
    {
        return $this->db->exec('DELETE FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
    }
}
