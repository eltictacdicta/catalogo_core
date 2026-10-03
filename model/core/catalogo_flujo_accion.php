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
 * Acción de un flujo. El sujeto se referencia por `codigo` y la acción
 * pertenece al vocabulario cerrado de catalogo_flujo.
 */
class catalogo_flujo_accion extends \fs_model
{
    public const TABLE = 'catalogo_flujo_acciones';

    public $id;
    public $id_flujo;
    public $accion;
    public $sujeto_tipo;
    public $sujeto_codigo;

    public function __construct($data = false)
    {
        parent::__construct(self::TABLE);

        if ($data) {
            $this->id = intval($data['id']);
            $this->id_flujo = intval($data['id_flujo']);
            $this->accion = $data['accion'];
            $this->sujeto_tipo = $data['sujeto_tipo'];
            $this->sujeto_codigo = $data['sujeto_codigo'];
        } else {
            $this->id = null;
            $this->id_flujo = null;
            $this->accion = '';
            $this->sujeto_tipo = '';
            $this->sujeto_codigo = '';
        }
    }

    protected function install()
    {
        return '';
    }

    public function get($id)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name . ' WHERE id = ' . $this->intval($id) . ';');
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    /**
     * @return array<int, static>
     */
    public function all_from_flujo($idFlujo): array
    {
        $list = [];
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE id_flujo = ' . $this->intval($idFlujo) . ' ORDER BY id ASC;');
        if ($data) {
            foreach ($data as $d) {
                $list[] = new static($d);
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
        $this->accion = strtolower(trim((string) $this->accion));
        $this->sujeto_tipo = strtolower(trim((string) $this->sujeto_tipo));
        $this->sujeto_codigo = $this->no_html(trim((string) $this->sujeto_codigo));

        if ($this->id_flujo <= 0) {
            $this->new_error_msg('La acción no tiene un flujo válido.');
            return false;
        }

        if (!catalogo_flujo::is_valid_action($this->accion)) {
            $this->new_error_msg('Acción de flujo no válida: ' . $this->accion);
            return false;
        }

        if (!catalogo_flujo::is_valid_subject_type($this->sujeto_tipo)) {
            $this->new_error_msg('Tipo de sujeto de la acción no válido: ' . $this->sujeto_tipo);
            return false;
        }

        if ($this->sujeto_codigo === '' || mb_strlen($this->sujeto_codigo) > 20) {
            $this->new_error_msg('Código de sujeto de la acción no válido.');
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
                . ', accion = ' . $this->var2str($this->accion)
                . ', sujeto_tipo = ' . $this->var2str($this->sujeto_tipo)
                . ', sujeto_codigo = ' . $this->var2str($this->sujeto_codigo)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name . ' (id_flujo, accion, sujeto_tipo, sujeto_codigo) VALUES ('
                . $this->intval($this->id_flujo) . ','
                . $this->var2str($this->accion) . ','
                . $this->var2str($this->sujeto_tipo) . ','
                . $this->var2str($this->sujeto_codigo) . ');';
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
