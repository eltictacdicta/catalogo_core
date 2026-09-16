<?php
/**
 * This file is part of the catalogo_core plugin
 * Copyright (C) 2026 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 */
declare(strict_types=1);

namespace FSFramework\model;

/**
 * Predefined value of a feature definition (CAR-03).
 *
 * Per definition and unique on `(id_caracteristica, valor)`. Bool definitions
 * get the seeded `'1'` / `'0'` pair from `CaracteristicaRegistry` (targeted
 * upsert, never `seed_if_empty`).
 */
class catalogo_caracteristica_valor extends \fs_model
{
    /** @var int|null */
    public $id;

    /** @var int|null */
    public $id_caracteristica;

    /** @var string|null */
    public $valor;

    /** @var int */
    public $orden;

    /** @var bool */
    public $activo;

    public function __construct($data = false)
    {
        parent::__construct('catalogo_caracteristica_valores');

        if ($data) {
            $this->id = isset($data['id']) ? (int) $data['id'] : null;
            $this->id_caracteristica = isset($data['id_caracteristica'])
                ? (int) $data['id_caracteristica']
                : null;
            $this->valor = $data['valor'] ?? null;
            $this->orden = isset($data['orden']) ? (int) $data['orden'] : 0;
            $this->activo = isset($data['activo']) ? $this->str2bool($data['activo']) : true;
        } else {
            $this->id = null;
            $this->id_caracteristica = null;
            $this->valor = null;
            $this->orden = 0;
            $this->activo = true;
        }
    }

    /**
     * @param int $idCaracteristica
     * @return array<int, static>
     */
    public function all_from_caracteristica($idCaracteristica)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE id_caracteristica = ' . $this->intval($idCaracteristica)
            . ' ORDER BY orden ASC, id ASC;');

        $list = [];
        if ($data) {
            foreach ($data as $row) {
                $list[] = new static($row);
            }
        }

        return $list;
    }

    /**
     * @param int $id
     * @return static|false
     */
    public function get($id)
    {
        $data = $this->db->select('SELECT * FROM ' . $this->table_name
            . ' WHERE id = ' . $this->intval($id) . ';');

        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function exists()
    {
        if ($this->id === null) {
            return false;
        }

        return (bool) $this->db->select('SELECT id FROM ' . $this->table_name
            . ' WHERE id = ' . $this->intval($this->id) . ';');
    }

    public function test()
    {
        $this->valor = $this->no_html(trim((string) $this->valor));
        $this->orden = (int) $this->orden;
        $this->id_caracteristica = $this->intval($this->id_caracteristica);

        if ($this->id_caracteristica === null || (int) $this->id_caracteristica <= 0) {
            $this->new_error_msg('La característica del valor es obligatoria.');
            return false;
        }

        if ($this->valor === '') {
            $this->new_error_msg('El valor es obligatorio.');
            return false;
        }

        $existing = $this->existing_valor((int) $this->id_caracteristica, (string) $this->valor);
        if ($existing && (int) ($existing['id'] ?? 0) !== (int) ($this->id ?? 0)) {
            $this->new_error_msg('Ya existe ese valor para la característica.');
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
                . 'id_caracteristica = ' . $this->intval($this->id_caracteristica)
                . ', valor = ' . $this->var2str($this->valor)
                . ', orden = ' . $this->intval($this->orden)
                . ', activo = ' . $this->var2str($this->activo)
                . ' WHERE id = ' . $this->intval($this->id) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name
                . ' (id_caracteristica, valor, orden, activo) VALUES ('
                . $this->intval($this->id_caracteristica) . ','
                . $this->var2str($this->valor) . ','
                . $this->intval($this->orden) . ','
                . $this->var2str($this->activo) . ');';
        }

        if (!$this->db->exec($sql)) {
            return false;
        }

        if ($this->id === null) {
            $this->id = (int) $this->db->lastval();
        }

        return true;
    }

    public function delete()
    {
        if ($this->id === null) {
            return false;
        }

        return (bool) $this->db->exec('DELETE FROM ' . $this->table_name
            . ' WHERE id = ' . $this->intval($this->id) . ';');
    }

    /**
     * DB seam so the unique-pair check is unit-testable without a database.
     *
     * @param int $idCaracteristica
     * @param string $valor
     * @return array<string, mixed>|false
     */
    protected function existing_valor($idCaracteristica, string $valor)
    {
        $data = $this->db->select('SELECT id FROM ' . $this->table_name
            . ' WHERE id_caracteristica = ' . $this->intval($idCaracteristica)
            . ' AND valor = ' . $this->var2str($valor) . ';');

        if ($data) {
            return $data[0];
        }

        return false;
    }

    protected function install(): string
    {
        return '';
    }
}
