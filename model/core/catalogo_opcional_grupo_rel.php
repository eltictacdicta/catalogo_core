<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
namespace FSFramework\model;

/**
 * Relación opcional ↔ grupo de opcionales (M:N).
 *
 * Single source of truth for opcional group membership. Mirrors
 * catalogo_articulo_opcional_grupo: PRIMARY KEY (id) + UNIQUE
 * (id_opcional, id_grupo), no DB-level foreign key and an application-side
 * cascade (see catalogo_opcional::delete(), catalogo_opcional_grupo::delete()
 * and the tarifario bulk-delete paths).
 */
class catalogo_opcional_grupo_rel extends \fs_model
{
    public const TABLE = 'catalogo_opcional_grupo_rel';

    public $id;
    public $id_opcional;
    public $id_grupo;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = intval($data['id']);
            $this->id_opcional = intval($data['id_opcional']);
            $this->id_grupo = intval($data['id_grupo']);
        } else {
            $this->id = null;
            $this->id_opcional = null;
            $this->id_grupo = null;
        }
    }

    protected function install()
    {
        return '';
    }

    /**
     * Inserts the (id_opcional, id_grupo) pair. Idempotent: an already stored
     * pair is a no-op, so callers never have to pre-check membership.
     */
    public function add(int $idOpcional, int $idGrupo): bool
    {
        if ($this->exists_relation($idOpcional, $idGrupo)) {
            return true;
        }

        return (bool) $this->db->exec(
            'INSERT INTO ' . $this->table_name . ' (id_opcional, id_grupo) VALUES ('
            . $this->intval($idOpcional) . ','
            . $this->intval($idGrupo) . ');'
        );
    }

    public function remove(int $idOpcional, int $idGrupo): bool
    {
        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($idOpcional)
            . ' AND id_grupo = ' . $this->intval($idGrupo) . ';'
        );
    }

    public function exists_relation(int $idOpcional, int $idGrupo): bool
    {
        $data = $this->db->select(
            'SELECT * FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($idOpcional)
            . ' AND id_grupo = ' . $this->intval($idGrupo) . ';'
        );

        return (bool) ($data && count($data) > 0);
    }

    /**
     * @return list<int>
     */
    public function group_ids_for_opcional(int $idOpcional): array
    {
        $list = [];
        $data = $this->db->select(
            'SELECT id_grupo FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($idOpcional)
            . ' ORDER BY id_grupo ASC;'
        );
        if ($data) {
            foreach ($data as $d) {
                $list[] = intval($d['id_grupo']);
            }
        }

        return $list;
    }

    /**
     * Batched membership reader: one query for every requested opcional.
     *
     * @param list<int> $idOpcionales
     * @return array<int, list<int>> map of id_opcional => list<int> id_grupo
     */
    public function map_for_opcionales(array $idOpcionales): array
    {
        $ids = [];
        foreach ($idOpcionales as $id) {
            $id = $this->intval($id);
            if ($id === null || $id <= 0) {
                continue;
            }
            $ids[$id] = $id;
        }

        if ($ids === []) {
            return [];
        }

        $data = $this->db->select(
            'SELECT id_opcional, id_grupo FROM ' . $this->table_name
            . ' WHERE id_opcional IN (' . implode(',', $ids) . ');'
        );

        $map = [];
        if ($data) {
            foreach ($data as $d) {
                $map[intval($d['id_opcional'])][] = intval($d['id_grupo']);
            }
        }

        return $map;
    }

    public function delete_all_from_opcional(int $idOpcional): bool
    {
        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($idOpcional) . ';'
        );
    }

    public function delete_all_from_grupo(int $idGrupo): bool
    {
        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name
            . ' WHERE id_grupo = ' . $this->intval($idGrupo) . ';'
        );
    }

    public function exists()
    {
        if (is_null($this->id)) {
            return false;
        }

        return $this->db->select(
            'SELECT * FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';'
        );
    }

    public function save()
    {
        if ($this->exists()) {
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'id_opcional = ' . $this->intval($this->id_opcional)
                . ', id_grupo = ' . $this->intval($this->id_grupo)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (id_opcional, id_grupo) VALUES ('
                . $this->intval($this->id_opcional) . ','
                . $this->intval($this->id_grupo) . ');';
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
