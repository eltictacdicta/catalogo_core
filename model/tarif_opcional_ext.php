<?php
/**
 * This file is part of tarifario
 * Copyright (C) 2026 FSFramework Team
 *
 * Tabla de extensión 1:1 para campos de opcional propios del tarifario.
 */
namespace FSFramework\model;

/**
 * Modelo para tarif_opcional_ext (ref_sap, en_catalogo, en_tarifa).
 */
class tarif_opcional_ext extends \fs_model
{
    public $id_opcional;
    public $ref_sap;
    public $en_catalogo;
    public $en_tarifa;

    public function __construct($data = false)
    {
        parent::__construct('tarif_opcional_ext');

        if ($data) {
            $this->id_opcional = intval($data['id_opcional']);
            $this->ref_sap = $data['ref_sap'] ?? null;
            $this->en_catalogo = isset($data['en_catalogo']) ? $this->str2bool($data['en_catalogo']) : false;
            $this->en_tarifa = isset($data['en_tarifa']) ? $this->str2bool($data['en_tarifa']) : false;
        } else {
            $this->id_opcional = null;
            $this->ref_sap = null;
            $this->en_catalogo = false;
            $this->en_tarifa = false;
        }
    }

    protected function install()
    {
        require_once 'plugins/catalogo_core/model/core/catalogo_opcional.php';
        new catalogo_opcional();

        return '';
    }

    public function exists()
    {
        if ($this->id_opcional === null) {
            return false;
        }

        return (bool) $this->db->select(
            'SELECT * FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($this->id_opcional) . ';'
        );
    }

    public function get(int $id_opcional)
    {
        $data = $this->db->select(
            'SELECT * FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($id_opcional) . ';'
        );
        if ($data) {
            return new static($data[0]);
        }

        return false;
    }

    public function save()
    {
        if ($this->id_opcional === null) {
            return false;
        }

        if ($this->ref_sap === '') {
            $this->ref_sap = null;
        }

        if ($this->exists()) {
            $sql = 'UPDATE ' . $this->table_name . ' SET '
                . 'ref_sap = ' . $this->var2str($this->ref_sap)
                . ', en_catalogo = ' . $this->var2str($this->en_catalogo)
                . ', en_tarifa = ' . $this->var2str($this->en_tarifa)
                . ' WHERE id_opcional = ' . $this->intval($this->id_opcional) . ';';
        } else {
            $sql = 'INSERT INTO ' . $this->table_name
                . ' (id_opcional, ref_sap, en_catalogo, en_tarifa) VALUES ('
                . $this->intval($this->id_opcional) . ','
                . $this->var2str($this->ref_sap) . ','
                . $this->var2str($this->en_catalogo) . ','
                . $this->var2str($this->en_tarifa) . ');';
        }

        return (bool) $this->db->exec($sql);
    }

    public function delete()
    {
        if ($this->id_opcional === null) {
            return true;
        }

        return (bool) $this->db->exec(
            'DELETE FROM ' . $this->table_name
            . ' WHERE id_opcional = ' . $this->intval($this->id_opcional) . ';'
        );
    }
}
