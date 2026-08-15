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
 * Idiomas disponibles para descripciones multiidioma de artículos.
 */
class catalogo_idioma extends \fs_model
{
    public const TABLE = 'catalogo_idiomas';
    public const DEFAULT_CODE = 'es';

    public $codidioma;
    public $nombre;
    public $activo;
    public $por_defecto;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->codidioma = $data['codidioma'];
            $this->nombre = $data['nombre'];
            $this->activo = $this->str2bool($data['activo']);
            $this->por_defecto = $this->str2bool($data['por_defecto']);
        } else {
            $this->codidioma = null;
            $this->nombre = '';
            $this->activo = true;
            $this->por_defecto = false;
        }
    }

    protected function install()
    {
        return $this->default_idiomas_sql();
    }

    public function ensure_defaults(): void
    {
        $count = $this->db->select('SELECT COUNT(*) as total FROM ' . $this->table_name . ';');
        if ($count && intval($count[0]['total']) > 0) {
            return;
        }

        $this->db->exec($this->default_idiomas_sql());
    }

    private function default_idiomas_sql(): string
    {
        return 'INSERT INTO ' . $this->table_name . ' (codidioma, nombre, activo, por_defecto) VALUES '
            . "('es', 'Español', TRUE, TRUE),"
            . "('en', 'English', TRUE, FALSE);";
    }

    public function url()
    {
        return 'index.php?page=ventas_articulos#idiomas';
    }

    public function get($cod)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE codidioma = ' . $this->var2str($cod) . ';');
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
        if (is_null($this->codidioma)) {
            return false;
        }

        return $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE codidioma = ' . $this->var2str($this->codidioma) . ';');
    }

    public function test()
    {
        $this->codidioma = $this->no_html(strtolower(trim((string) $this->codidioma)));
        $this->nombre = $this->no_html($this->nombre);

        if (mb_strlen($this->codidioma) < 2 || mb_strlen($this->codidioma) > 5) {
            $this->new_error_msg('Código de idioma no válido. Deben ser entre 2 y 5 caracteres.');
            return false;
        }

        if (mb_strlen($this->nombre) < 1 || mb_strlen($this->nombre) > 50) {
            $this->new_error_msg('Nombre de idioma no válido.');
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
            $this->db->exec('UPDATE ' . $this->table_name . ' SET por_defecto = FALSE WHERE codidioma != ' . $this->var2str($this->codidioma) . ';');
        }

        if ($this->exists()) {
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'nombre = ' . $this->var2str($this->nombre)
                . ', activo = ' . $this->var2str($this->activo)
                . ', por_defecto = ' . $this->var2str($this->por_defecto)
                . ' WHERE codidioma = ' . $this->var2str($this->codidioma) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (codidioma, nombre, activo, por_defecto) VALUES ('
                . $this->var2str($this->codidioma) . ','
                . $this->var2str($this->nombre) . ','
                . $this->var2str($this->activo) . ','
                . $this->var2str($this->por_defecto) . ');';
        }

        return $this->db->exec($sql);
    }

    public function delete()
    {
        if ($this->por_defecto) {
            $this->new_error_msg('No se puede eliminar el idioma por defecto.');
            return false;
        }

        return $this->db->exec('DELETE FROM ' . $this->table_name . ' WHERE codidioma = ' . $this->var2str($this->codidioma) . ';');
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

    public function all_activos()
    {
        $list = [];
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE activo = TRUE ORDER BY nombre ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
            }
        }

        return $list;
    }
}
