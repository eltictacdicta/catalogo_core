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
 * Asignación M:N flujo ↔ familia (por `codfamilia`).
 */
class catalogo_flujo_familia extends \fs_model
{
    public const TABLE = 'catalogo_flujo_familias';

    public $id;
    public $id_flujo;
    public $codfamilia;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = intval($data['id']);
            $this->id_flujo = intval($data['id_flujo']);
            $this->codfamilia = $data['codfamilia'];
        } else {
            $this->id = null;
            $this->id_flujo = null;
            $this->codfamilia = null;
        }
    }

    protected function install()
    {
        return '';
    }

    public function add(int $idFlujo, string $codfamilia): bool
    {
        if ($this->exists_relation($idFlujo, $codfamilia)) {
            return true;
        }

        return (bool) $this->db->exec(
            'INSERT INTO ' . $this->table_name . ' (id_flujo, codfamilia) VALUES ('
            . $this->intval($idFlujo) . ','
            . $this->var2str($codfamilia) . ');'
        );
    }

    public function remove(int $idFlujo, string $codfamilia): bool
    {
        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name
            . ' WHERE id_flujo = ' . $this->intval($idFlujo)
            . ' AND codfamilia = ' . $this->var2str($codfamilia) . ';'
        );
    }

    public function exists_relation(int $idFlujo, string $codfamilia): bool
    {
        $data = $this->db->select(
            'SELECT * FROM ' . $this->table_name
            . ' WHERE id_flujo = ' . $this->intval($idFlujo)
            . ' AND codfamilia = ' . $this->var2str($codfamilia) . ';'
        );

        return (bool) ($data && count($data) > 0);
    }

    public function delete_all_from_flujo(int $idFlujo): bool
    {
        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name . ' WHERE id_flujo = ' . $this->intval($idFlujo) . ';'
        );
    }

    /**
     * Cascada de aplicación: al borrar una familia se eliminan sus
     * asignaciones de flujo.
     */
    public function delete_all_from_familia(string $codfamilia): bool
    {
        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name . ' WHERE codfamilia = ' . $this->var2str($codfamilia) . ';'
        );
    }

    /**
     * @return list<string>
     */
    public function familias_for_flujo(int $idFlujo): array
    {
        $list = [];
        $data = $this->db->select(
            'SELECT * FROM ' . $this->table_name
            . ' WHERE id_flujo = ' . $this->intval($idFlujo) . ' ORDER BY codfamilia ASC;'
        );
        if ($data) {
            foreach ($data as $d) {
                $list[] = (string) $d['codfamilia'];
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
        $this->id_flujo = intval($this->id_flujo);
        $this->codfamilia = $this->no_html(trim((string) $this->codfamilia));

        if ($this->id_flujo <= 0) {
            $this->new_error_msg('La asignación no tiene un flujo válido.');
            return false;
        }

        if ($this->codfamilia === '' || mb_strlen($this->codfamilia) > 8) {
            $this->new_error_msg('Código de familia no válido.');
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
                . 'id_flujo = ' . $this->intval($this->id_flujo)
                . ', codfamilia = ' . $this->var2str($this->codfamilia)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (id_flujo, codfamilia) VALUES ('
                . $this->intval($this->id_flujo) . ','
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
        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($this->id) . ';'
        );
    }
}
