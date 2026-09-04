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
 * Precio de un artículo en una lista de precio del catálogo (multitarifa).
 * PK compuesta (referencia, codlista); un precio por lista por artículo.
 */
class catalogo_articulo_precio extends \fs_model
{
    public const TABLE = 'catalogo_articulo_precios';

    public $referencia;
    public $codlista;
    public $precio;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->referencia = $data['referencia'];
            $this->codlista = $data['codlista'];
            $this->precio = floatval($data['precio']);
        } else {
            $this->referencia = null;
            $this->codlista = null;
            $this->precio = 0.0;
        }
    }

    protected function install()
    {
        new catalogo_lista_precio();

        return '';
    }

    public function get($referencia, $codlista)
    {
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($referencia)
            . ' AND codlista = ' . $this->var2str($codlista) . ';';
        $data = $this->db->select($sql);
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function exists()
    {
        if (is_null($this->referencia) || is_null($this->codlista)) {
            return false;
        }

        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($this->referencia)
            . ' AND codlista = ' . $this->var2str($this->codlista) . ';';

        return $this->db->select($sql);
    }

    public function test()
    {
        $this->referencia = $this->no_html(trim((string) $this->referencia));
        $this->codlista = $this->no_html(trim((string) $this->codlista));

        if (is_null($this->referencia) || mb_strlen($this->referencia) < 1 || mb_strlen($this->referencia) > 20) {
            $this->new_error_msg('Referencia de artículo no válida.');
            return false;
        }

        if (mb_strlen($this->codlista) < 1 || mb_strlen($this->codlista) > 20) {
            $this->new_error_msg('Código de lista no válido.');
            return false;
        }

        if ($this->precio < 0) {
            $this->new_error_msg('El precio no puede ser negativo.');
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
                . 'precio = ' . $this->var2str($this->precio)
                . ' WHERE referencia = ' . $this->var2str($this->referencia)
                . ' AND codlista = ' . $this->var2str($this->codlista) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (referencia, codlista, precio) VALUES ('
                . $this->var2str($this->referencia) . ','
                . $this->var2str($this->codlista) . ','
                . $this->var2str($this->precio) . ');';
        }

        return $this->db->exec($sql);
    }

    public function delete()
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($this->referencia)
            . ' AND codlista = ' . $this->var2str($this->codlista) . ';';

        return $this->db->exec($sql);
    }

    public function all_from_articulo($referencia)
    {
        $list = [];
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($referencia)
            . ' ORDER BY codlista ASC;';
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    public function all_from_lista($codlista)
    {
        $list = [];
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE codlista = ' . $this->var2str($codlista)
            . ' ORDER BY referencia ASC;';
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    public function delete_from_articulo($referencia)
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($referencia) . ';';

        return $this->db->exec($sql);
    }

    public function delete_from_lista($codlista)
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE codlista = ' . $this->var2str($codlista) . ';';

        return $this->db->exec($sql);
    }

    public function set_precio($referencia, $codlista, $precio)
    {
        $this->referencia = $referencia;
        $this->codlista = $codlista;
        $this->precio = floatval($precio);

        return $this->save();
    }
}
