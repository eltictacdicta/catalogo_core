<?php
/**
 * This file is part of tarifario
 * Copyright (C) 2025 FSFramework Team
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */
namespace FSFramework\model;

/**
 * Modelo para la tabla de extensión de familias.
 * Esta tabla almacena los campos extendidos (capitulo, nivel) 
 * con relación 1:1 a la tabla familias.
 */
class tarif_familia_ext extends \fs_model
{
    public $codfamilia;
    public $capitulo;
    public $nivel;

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_familia_ext');

        if ($data) {
            $this->codfamilia = $data['codfamilia'];
            $this->capitulo = isset($data['capitulo']) ? $data['capitulo'] : '';
            $this->nivel = isset($data['nivel']) ? $data['nivel'] : '';
        } else {
            $this->codfamilia = NULL;
            $this->capitulo = '';
            $this->nivel = '';
        }
    }

    protected function install()
    {
        // Forzar la creación de la tabla familias primero
        new \FSFramework\model\familia();
        return '';
    }

    public function exists()
    {
        if (is_null($this->codfamilia)) {
            return FALSE;
        }
        return $this->db->select("SELECT * FROM " . $this->table_name
            . " WHERE codfamilia = " . $this->var2str($this->codfamilia) . ";");
    }

    public function get($cod)
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name
            . " WHERE codfamilia = " . $this->var2str($cod) . ";");
        if ($data) {
            return new tarif_familia_ext($data[0]);
        }
        return FALSE;
    }

    public function save()
    {
        if ($this->exists()) {
            $sql = "UPDATE " . $this->table_name . " SET "
                . "capitulo = " . $this->var2str($this->capitulo)
                . ", nivel = " . $this->var2str($this->nivel)
                . " WHERE codfamilia = " . $this->var2str($this->codfamilia) . ";";
        } else {
            $sql = "INSERT INTO " . $this->table_name . " (codfamilia, capitulo, nivel) VALUES ("
                . $this->var2str($this->codfamilia) . ","
                . $this->var2str($this->capitulo) . ","
                . $this->var2str($this->nivel) . ");";
        }
        return $this->db->exec($sql);
    }

    public function delete()
    {
        return $this->db->exec("DELETE FROM " . $this->table_name
            . " WHERE codfamilia = " . $this->var2str($this->codfamilia) . ";");
    }
}
