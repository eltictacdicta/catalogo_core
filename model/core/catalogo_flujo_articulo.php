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
 * Asignación M:N flujo ↔ artículo (por `referencia`).
 */
class catalogo_flujo_articulo extends \fs_model
{
    public const TABLE = 'catalogo_flujo_articulos';

    public $id;
    public $id_flujo;
    public $referencia;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = intval($data['id']);
            $this->id_flujo = intval($data['id_flujo']);
            $this->referencia = $data['referencia'];
        } else {
            $this->id = null;
            $this->id_flujo = null;
            $this->referencia = null;
        }
    }

    protected function install()
    {
        return '';
    }

    /**
     * Inserta el par (id_flujo, referencia). Idempotente: un par ya guardado
     * es un no-op.
     */
    public function add(int $idFlujo, string $referencia): bool
    {
        if ($this->exists_relation($idFlujo, $referencia)) {
            return true;
        }

        return (bool) $this->db->exec(
            'INSERT INTO ' . $this->table_name . ' (id_flujo, referencia) VALUES ('
            . $this->intval($idFlujo) . ','
            . $this->var2str($referencia) . ');'
        );
    }

    public function remove(int $idFlujo, string $referencia): bool
    {
        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name
            . ' WHERE id_flujo = ' . $this->intval($idFlujo)
            . ' AND referencia = ' . $this->var2str($referencia) . ';'
        );
    }

    public function exists_relation(int $idFlujo, string $referencia): bool
    {
        $data = $this->db->select(
            'SELECT * FROM ' . $this->table_name
            . ' WHERE id_flujo = ' . $this->intval($idFlujo)
            . ' AND referencia = ' . $this->var2str($referencia) . ';'
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
     * Cascada de aplicación: al borrar un artículo se eliminan sus
     * asignaciones de flujo.
     */
    public function delete_all_from_referencia(string $referencia): bool
    {
        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name . ' WHERE referencia = ' . $this->var2str($referencia) . ';'
        );
    }

    /**
     * @return list<string>
     */
    public function referencias_for_flujo(int $idFlujo): array
    {
        $list = [];
        $data = $this->db->select(
            'SELECT * FROM ' . $this->table_name
            . ' WHERE id_flujo = ' . $this->intval($idFlujo) . ' ORDER BY referencia ASC;'
        );
        if ($data) {
            foreach ($data as $d) {
                $list[] = (string) $d['referencia'];
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
        $this->referencia = $this->no_html(trim((string) $this->referencia));

        if ($this->id_flujo <= 0) {
            $this->new_error_msg('La asignación no tiene un flujo válido.');
            return false;
        }

        if ($this->referencia === '' || mb_strlen($this->referencia) > 18) {
            $this->new_error_msg('Referencia de artículo no válida.');
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
                . ', referencia = ' . $this->var2str($this->referencia)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (id_flujo, referencia) VALUES ('
                . $this->intval($this->id_flujo) . ','
                . $this->var2str($this->referencia) . ');';
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
