<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
namespace FSFramework\model;

/**
 * Relación artículo ↔ grupo de opcionales.
 */
class catalogo_articulo_opcional_grupo extends \fs_model
{
    public const TABLE = 'catalogo_articulo_opcional_grupo';

    public $id;
    public $referencia;
    public $id_grupo;
    public $obligatorio;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = intval($data['id']);
            $this->referencia = $data['referencia'];
            $this->id_grupo = intval($data['id_grupo']);
            $this->obligatorio = $this->str2bool($data['obligatorio'] ?? false);
        } else {
            $this->id = null;
            $this->referencia = null;
            $this->id_grupo = null;
            $this->obligatorio = false;
        }
    }

    protected function install()
    {
        return '';
    }

    public function add(string $referencia, int $idGrupo, bool $obligatorio = false): bool
    {
        if ($this->exists_relation($referencia, $idGrupo)) {
            return $this->set_obligatorio($referencia, $idGrupo, $obligatorio);
        }

        $grupo = new catalogo_opcional_grupo();
        if (!$grupo->get($idGrupo)) {
            return false;
        }

        $sql = 'INSERT INTO ' . $this->table_name . ' (referencia, id_grupo, obligatorio) VALUES ('
            . $this->var2str($referencia) . ','
            . $this->intval($idGrupo) . ','
            . $this->var2str($obligatorio) . ');';

        return (bool) $this->db->exec($sql);
    }

    public function set_obligatorio(string $referencia, int $idGrupo, bool $obligatorio): bool
    {
        return (bool) $this->db->exec(
            'UPDATE ' . $this->table_name
            . ' SET obligatorio = ' . $this->var2str($obligatorio)
            . ' WHERE referencia = ' . $this->var2str($referencia)
            . ' AND id_grupo = ' . $this->intval($idGrupo) . ';'
        );
    }

    public function remove(string $referencia, int $idGrupo): bool
    {
        return (bool) $this->db->exec('DELETE FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($referencia)
            . ' AND id_grupo = ' . $this->intval($idGrupo) . ';');
    }

    public function exists_relation(string $referencia, int $idGrupo): bool
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($referencia)
            . ' AND id_grupo = ' . $this->intval($idGrupo) . ';');

        return (bool) ($data && count($data) > 0);
    }

    /**
     * @return array<int, catalogo_opcional_grupo>
     */
    public function get_grupos_from_articulo(string $referencia): array
    {
        $list = [];
        $data = $this->db->select('SELECT g.*, ag.obligatorio AS obligatorio_en_articulo FROM ' . catalogo_opcional_grupo::TABLE . ' g'
            . ' INNER JOIN ' . $this->table_name . ' ag ON g.id = ag.id_grupo'
            . ' WHERE ag.referencia = ' . $this->var2str($referencia)
            . ' AND g.activo = TRUE'
            . ' ORDER BY g.orden ASC, g.nombre ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new catalogo_opcional_grupo($d);
            }
        }

        return $list;
    }

    /**
     * @return array<int, \articulo>
     */
    public function get_articulos_from_grupo(int $idGrupo): array
    {
        $list = [];
        $data = $this->db->select('SELECT a.* FROM articulos a'
            . ' INNER JOIN ' . $this->table_name . ' ag ON a.referencia = ag.referencia'
            . ' WHERE ag.id_grupo = ' . $this->intval($idGrupo)
            . ' ORDER BY a.referencia ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new \articulo($d);
            }
        }

        return $list;
    }

    public function delete_all_from_articulo(string $referencia): bool
    {
        return (bool) $this->db->exec('DELETE FROM ' . $this->table_name
            . ' WHERE referencia = ' . $this->var2str($referencia) . ';');
    }

    public function delete_all_from_grupo(int $idGrupo): bool
    {
        return (bool) $this->db->exec('DELETE FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->intval($idGrupo) . ';');
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
                . 'referencia = ' . $this->var2str($this->referencia)
                . ', id_grupo = ' . $this->intval($this->id_grupo)
                . ', obligatorio = ' . $this->var2str($this->obligatorio)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (referencia, id_grupo, obligatorio) VALUES ('
                . $this->var2str($this->referencia) . ','
                . $this->intval($this->id_grupo) . ','
                . $this->var2str($this->obligatorio) . ');';
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
        return (bool) $this->db->exec('DELETE FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';');
    }
}
