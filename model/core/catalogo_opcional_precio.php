<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
namespace FSFramework\model;

/**
 * Precio de un opcional en una lista de precio del catálogo.
 */
class catalogo_opcional_precio extends \fs_model
{
    public const TABLE = 'catalogo_opcional_precios';

    public $id_opcional;
    public $codlista;
    public $precio;
    public $porcentaje;
    public $en_catalogo;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id_opcional = intval($data['id_opcional']);
            $this->codlista = $data['codlista'];
            $this->precio = floatval($data['precio']);
            $this->porcentaje = isset($data['porcentaje']) && $data['porcentaje'] !== ''
                ? floatval($data['porcentaje'])
                : null;
            $this->en_catalogo = isset($data['en_catalogo']) ? $this->str2bool($data['en_catalogo']) : false;
        } else {
            $this->id_opcional = null;
            $this->codlista = null;
            $this->precio = 0.0;
            $this->porcentaje = null;
            $this->en_catalogo = false;
        }
    }

    protected function install()
    {
        new catalogo_lista_precio();
        return '';
    }

    public function get($id_opcional, $codlista)
    {
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($id_opcional)
            . ' AND codlista = ' . $this->var2str($codlista) . ';';
        $data = $this->db->select($sql);
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function exists()
    {
        if (is_null($this->id_opcional) || is_null($this->codlista)) {
            return false;
        }

        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($this->id_opcional)
            . ' AND codlista = ' . $this->var2str($this->codlista) . ';';

        return $this->db->select($sql);
    }

    public function test()
    {
        $this->codlista = $this->no_html(trim((string) $this->codlista));

        if (is_null($this->id_opcional) || $this->id_opcional < 1) {
            $this->new_error_msg('ID de opcional no válido.');
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

        if ($this->porcentaje !== null && $this->porcentaje < 0) {
            $this->new_error_msg('El porcentaje no puede ser negativo.');
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
                . 'precio = ' . $this->var2str($this->precio) . ','
                . 'porcentaje = ' . $this->var2str($this->porcentaje) . ','
                . 'en_catalogo = ' . $this->var2str($this->en_catalogo)
                . ' WHERE id_opcional = ' . $this->intval($this->id_opcional)
                . ' AND codlista = ' . $this->var2str($this->codlista) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (id_opcional, codlista, precio, porcentaje, en_catalogo) VALUES ('
                . $this->intval($this->id_opcional) . ','
                . $this->var2str($this->codlista) . ','
                . $this->var2str($this->precio) . ','
                . $this->var2str($this->porcentaje) . ','
                . $this->var2str($this->en_catalogo) . ');';
        }

        return $this->db->exec($sql);
    }

    public function delete()
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($this->id_opcional)
            . ' AND codlista = ' . $this->var2str($this->codlista) . ';';

        return $this->db->exec($sql);
    }

    public function all_from_opcional($id_opcional)
    {
        $list = [];
        $sql = 'SELECT * FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($id_opcional)
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
            . ' ORDER BY id_opcional ASC;';
        $data = $this->db->select($sql);
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }

    public function delete_from_opcional($id_opcional)
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($id_opcional) . ';';

        return $this->db->exec($sql);
    }

    public function delete_from_lista($codlista)
    {
        $sql = 'DELETE FROM ' . $this->table_name
            . ' WHERE codlista = ' . $this->var2str($codlista) . ';';

        return $this->db->exec($sql);
    }

    public function set_precio($id_opcional, $codlista, $precio)
    {
        $obj = $this->get($id_opcional, $codlista);
        if (!$obj) {
            $obj = new static();
            $obj->id_opcional = $id_opcional;
            $obj->codlista = $codlista;
        }
        $obj->precio = floatval($precio);

        return $obj->save();
    }
}
