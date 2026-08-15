<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
namespace FSFramework\model;

/**
 * Relación muchos a muchos entre opcionales y familias.
 */
class catalogo_opcional_familia extends \fs_model
{
    public const TABLE = 'catalogo_opcional_familias';

    public $id;
    public $id_opcional;
    public $codfamilia;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = intval($data['id']);
            $this->id_opcional = intval($data['id_opcional']);
            $this->codfamilia = $data['codfamilia'];
        } else {
            $this->id = null;
            $this->id_opcional = null;
            $this->codfamilia = null;
        }
    }

    protected function install()
    {
        return '';
    }

    public function add($id_opcional, $codfamilia)
    {
        if ($this->exists_relation($id_opcional, $codfamilia)) {
            return true;
        }

        $sql = 'INSERT INTO ' . $this->table_name . ' (id_opcional, codfamilia) VALUES ('
            . $this->intval($id_opcional) . ','
            . $this->var2str($codfamilia) . ');';

        return $this->db->exec($sql);
    }

    public function add_with_propagation($id_opcional, $codfamilia)
    {
        $result = ['familia' => false, 'articulos' => 0, 'errores' => []];

        if ($this->add($id_opcional, $codfamilia)) {
            $result['familia'] = true;
        } else {
            $result['errores'][] = 'Error al añadir la relación familia-opcional';
            return $result;
        }

        $articulos = $this->get_articulos_from_familia($codfamilia);
        $opcional = new catalogo_opcional();
        $op = $opcional->get($id_opcional);

        if ($op && $op->id_grupo) {
            $grupoRel = new catalogo_articulo_opcional_grupo();
            foreach ($articulos as $articulo) {
                if ($grupoRel->add($articulo['referencia'], (int) $op->id_grupo)) {
                    $result['articulos']++;
                }
            }

            return $result;
        }

        $articulo_opcional = new catalogo_articulo_opcional();
        foreach ($articulos as $articulo) {
            if (!$articulo_opcional->exists_relation($articulo['referencia'], $id_opcional)) {
                if ($articulo_opcional->add($articulo['referencia'], $id_opcional)) {
                    $result['articulos']++;
                }
            }
        }

        return $result;
    }

    public function remove_with_propagation($id_opcional, $codfamilia)
    {
        $result = ['familia' => false, 'articulos' => 0, 'errores' => []];
        $articulos = $this->get_articulos_from_familia($codfamilia);
        $opcional = new catalogo_opcional();
        $op = $opcional->get($id_opcional);

        if ($op && $op->id_grupo) {
            $grupoRel = new catalogo_articulo_opcional_grupo();
            foreach ($articulos as $articulo) {
                if ($grupoRel->remove($articulo['referencia'], (int) $op->id_grupo)) {
                    $result['articulos']++;
                }
            }
        } else {
            $articulo_opcional = new catalogo_articulo_opcional();
            foreach ($articulos as $articulo) {
                if ($articulo_opcional->remove($articulo['referencia'], $id_opcional)) {
                    $result['articulos']++;
                }
            }
        }

        if ($this->remove($id_opcional, $codfamilia)) {
            $result['familia'] = true;
        } else {
            $result['errores'][] = 'Error al eliminar la relación familia-opcional';
        }

        return $result;
    }

    public function get_articulos_from_familia($codfamilia)
    {
        $list = [];
        $data = $this->db->select('SELECT referencia FROM articulos'
            . ' WHERE codfamilia = ' . $this->var2str($codfamilia)
            . ' ORDER BY referencia ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = $d;
            }
        }

        return $list;
    }

    public function remove($id_opcional, $codfamilia)
    {
        return $this->db->exec('DELETE FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($id_opcional)
            . ' AND codfamilia = ' . $this->var2str($codfamilia) . ';');
    }

    public function exists_relation($id_opcional, $codfamilia)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($id_opcional)
            . ' AND codfamilia = ' . $this->var2str($codfamilia) . ';');

        return ($data && count($data) > 0);
    }

    public function get_familias_from_opcional($id_opcional)
    {
        $list = [];
        $data = $this->db->select('SELECT f.* FROM familias f'
            . ' INNER JOIN ' . $this->table_name . ' of ON f.codfamilia = of.codfamilia'
            . ' WHERE of.id_opcional = ' . $this->intval($id_opcional)
            . ' ORDER BY f.descripcion ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new familia($d);
            }
        }

        return $list;
    }

    public function get_opcionales_from_familia($codfamilia)
    {
        $list = [];
        $data = $this->db->select('SELECT o.* FROM ' . catalogo_opcional::TABLE . ' o'
            . ' INNER JOIN ' . $this->table_name . ' of ON o.id = of.id_opcional'
            . ' WHERE of.codfamilia = ' . $this->var2str($codfamilia)
            . ' ORDER BY o.nombre ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new catalogo_opcional($d);
            }
        }

        return $list;
    }

    public function delete_all_from_opcional($id_opcional)
    {
        return $this->db->exec('DELETE FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($id_opcional) . ';');
    }

    public function exists()
    {
        if (is_null($this->id)) {
            return false;
        }

        return $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
    }

    public function save()
    {
        if ($this->exists()) {
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'id_opcional = ' . $this->intval($this->id_opcional)
                . ', codfamilia = ' . $this->var2str($this->codfamilia)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (id_opcional, codfamilia) VALUES ('
                . $this->intval($this->id_opcional) . ','
                . $this->var2str($this->codfamilia) . ');';
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
