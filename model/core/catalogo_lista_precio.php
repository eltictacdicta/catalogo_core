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
 * Lista de precios nombrada del catálogo.
 */
class catalogo_lista_precio extends \fs_model
{
    public const TABLE = 'catalogo_listas_precio';
    public const DEFAULT_CODE = 'DEF';

    public $codlista;
    public $nombre;
    public $activa;
    public $por_defecto;
    public $coddivisa;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->codlista = $data['codlista'];
            $this->nombre = $data['nombre'];
            $this->activa = $this->str2bool($data['activa']);
            $this->por_defecto = $this->str2bool($data['por_defecto']);
            $this->coddivisa = isset($data['coddivisa']) && $data['coddivisa'] !== ''
                ? strtoupper(trim((string) $data['coddivisa']))
                : 'EUR';
        } else {
            $this->codlista = null;
            $this->nombre = '';
            $this->activa = true;
            $this->por_defecto = false;
            $this->coddivisa = 'EUR';
        }
    }

    protected function install()
    {
        return 'INSERT INTO ' . $this->table_name . ' (codlista, nombre, activa, por_defecto, coddivisa) VALUES '
            . "('" . self::DEFAULT_CODE . "', 'Lista por defecto', TRUE, TRUE, 'EUR');";
    }

    public function ensure_defaults(): void
    {
        $count = $this->db->select('SELECT COUNT(*) as total FROM ' . $this->table_name . ';');
        if ($count && intval($count[0]['total']) > 0) {
            return;
        }

        $this->db->exec($this->install());
    }

    public function url()
    {
        return 'index.php?page=ventas_articulos#listas-precio';
    }

    public function get($cod)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE codlista = ' . $this->var2str($cod) . ';');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function get_default()
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE por_defecto = TRUE LIMIT 1;');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function exists()
    {
        if (is_null($this->codlista)) {
            return false;
        }

        return $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE codlista = ' . $this->var2str($this->codlista) . ';');
    }

    public function test()
    {
        $this->codlista = $this->no_html(strtoupper(trim((string) $this->codlista)));
        $this->nombre = $this->no_html($this->nombre);
        $this->coddivisa = strtoupper(trim((string) $this->coddivisa));

        if (mb_strlen($this->codlista) < 1 || mb_strlen($this->codlista) > 20) {
            $this->new_error_msg('Código de lista no válido.');
            return false;
        }

        if (mb_strlen($this->nombre) < 1 || mb_strlen($this->nombre) > 50) {
            $this->new_error_msg('Nombre de lista no válido.');
            return false;
        }

        if (mb_strlen($this->coddivisa) !== 3) {
            $this->new_error_msg('Código de divisa no válido.');
            return false;
        }

        return true;
    }

    public function save()
    {
        if (!$this->test()) {
            return false;
        }

        if ($this->por_defecto) {
            $this->db->exec('UPDATE ' . $this->table_name . ' SET por_defecto = FALSE WHERE codlista != ' . $this->var2str($this->codlista) . ';');
        }

        if ($this->exists()) {
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'nombre = ' . $this->var2str($this->nombre)
                . ', activa = ' . $this->var2str($this->activa)
                . ', por_defecto = ' . $this->var2str($this->por_defecto)
                . ', coddivisa = ' . $this->var2str($this->coddivisa)
                . ' WHERE codlista = ' . $this->var2str($this->codlista) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (codlista, nombre, activa, por_defecto, coddivisa) VALUES ('
                . $this->var2str($this->codlista) . ','
                . $this->var2str($this->nombre) . ','
                . $this->var2str($this->activa) . ','
                . $this->var2str($this->por_defecto) . ','
                . $this->var2str($this->coddivisa) . ');';
        }

        return $this->db->exec($sql);
    }

    public function delete()
    {
        if ($this->por_defecto || $this->codlista === self::DEFAULT_CODE) {
            $this->new_error_msg('No se puede eliminar la lista de precio por defecto.');
            return false;
        }

        return $this->db->exec('DELETE FROM ' . $this->table_name . ' WHERE codlista = ' . $this->var2str($this->codlista) . ';');
    }

    public function all()
    {
        $list = [];
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' ORDER BY codlista ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }
}
