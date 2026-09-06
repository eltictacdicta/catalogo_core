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
 * Tarifas del plugin tarifario.
 * Permite crear tarifas nombradas (ej: "Tarifa 2025", "Tarifa 2026")
 * para asignar precios diferentes a los artículos.
 */
class tarif_tarifa extends \fs_model
{
    /**
     * Closed whitelist of currencies allowed per tariff. Any value outside
     * this list fails test() and is rejected by the controller POST handler.
     * Hard whitelist by design (proposal Q1/Q3) — extension requires a new SDD.
     */
    public const ALLOWED_CURRENCIES = ['EUR', 'MXN', 'USD'];

    /**
     * Código de la tarifa. Clave primaria.
     * @var string
     */
    public $codtarifa;

    /**
     * Nombre de la tarifa
     * @var string
     */
    public $nombre;

    /**
     * Si la tarifa está activa
     * @var boolean
     */
    public $activa;

    /**
     * Si es la tarifa por defecto
     * @var boolean
     */
    public $por_defecto;

    /**
     * ISO 4217 currency code (whitelisted: EUR, MXN, USD). Single source of
     * truth for the tariff's currency; views read it to render the symbol.
     * @var string
     */
    public $coddivisa;

    public function __construct($data = FALSE)
    {
        parent::__construct('tarif_tarifas');

        if ($data) {
            $this->codtarifa = $data['codtarifa'];
            $this->nombre = $data['nombre'];
            $this->activa = $this->str2bool($data['activa']);
            $this->por_defecto = $this->str2bool($data['por_defecto']);
            $this->coddivisa = isset($data['coddivisa']) && $data['coddivisa'] !== ''
                ? strtoupper(trim((string) $data['coddivisa']))
                : 'EUR';
        } else {
            $this->codtarifa = NULL;
            $this->nombre = '';
            $this->activa = TRUE;
            $this->por_defecto = FALSE;
            $this->coddivisa = 'EUR';
        }
    }

    protected function install()
    {
        return "INSERT INTO " . $this->table_name . " (codtarifa, nombre, activa, por_defecto, coddivisa) VALUES "
            . "('DEF', 'Tarifa por defecto', TRUE, TRUE, 'EUR');";
    }

    public function url()
    {
        return "index.php?page=tarif_tarifas";
    }

    /**
     * Obtiene una tarifa por su código.
     * @param string $cod
     * @return tarif_tarifa|false
     */
    public function get($cod)
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name . " WHERE codtarifa = " . $this->var2str($cod) . ";");
        if ($data) {
            return new tarif_tarifa($data[0]);
        }
        return FALSE;
    }

    /**
     * Obtiene la tarifa por defecto.
     * @return tarif_tarifa|false
     */
    public function get_default()
    {
        $data = $this->db->select("SELECT * FROM " . $this->table_name . " WHERE por_defecto = TRUE LIMIT 1;");
        if ($data) {
            return new tarif_tarifa($data[0]);
        }
        return FALSE;
    }

    /**
     * Genera un nuevo código de tarifa.
     * @return string
     */
    public function get_new_codigo()
    {
        $data = $this->db->select("SELECT MAX(" . $this->db->sql_to_int('codtarifa') . ") as cod FROM " . $this->table_name . ";");
        if ($data) {
            return sprintf('%06s', (1 + intval($data[0]['cod'])));
        }
        return '000001';
    }

    public function exists()
    {
        if (is_null($this->codtarifa)) {
            return FALSE;
        }
        return $this->db->select("SELECT * FROM " . $this->table_name . " WHERE codtarifa = " . $this->var2str($this->codtarifa) . ";");
    }

    public function test()
    {
        $this->codtarifa = $this->no_html(trim($this->codtarifa));
        $this->nombre = $this->no_html($this->nombre);

        if (mb_strlen($this->codtarifa) < 1 || mb_strlen($this->codtarifa) > 20) {
            $this->new_error_msg("Código de tarifa no válido. Debe tener entre 1 y 20 caracteres.");
            return FALSE;
        }

        if (mb_strlen($this->nombre) < 1 || mb_strlen($this->nombre) > 50) {
            $this->new_error_msg("Nombre de tarifa no válido. Debe tener entre 1 y 50 caracteres.");
            return FALSE;
        }

        $this->coddivisa = strtoupper(trim((string) $this->coddivisa));
        if (!in_array($this->coddivisa, self::ALLOWED_CURRENCIES, true)) {
            $this->new_error_msg("Moneda no permitida. Use EUR, MXN o USD.");
            return FALSE;
        }

        return TRUE;
    }

    public function save()
    {
        if ($this->test()) {
            // Si este es el por defecto, quitamos el flag de los demás
            if ($this->por_defecto) {
                $this->db->exec("UPDATE " . $this->table_name . " SET por_defecto = FALSE WHERE codtarifa != " . $this->var2str($this->codtarifa) . ";");
            }

            if ($this->exists()) {
                $sql = "UPDATE " . $this->table_name . " SET "
                    . "nombre = " . $this->var2str($this->nombre)
                    . ", activa = " . $this->var2str($this->activa)
                    . ", por_defecto = " . $this->var2str($this->por_defecto)
                    . ", coddivisa = " . $this->var2str($this->coddivisa)
                    . " WHERE codtarifa = " . $this->var2str($this->codtarifa) . ";";
            } else {
                $sql = "INSERT INTO " . $this->table_name . " (codtarifa, nombre, activa, por_defecto, coddivisa) VALUES ("
                    . $this->var2str($this->codtarifa) . ","
                    . $this->var2str($this->nombre) . ","
                    . $this->var2str($this->activa) . ","
                    . $this->var2str($this->por_defecto) . ","
                    . $this->var2str($this->coddivisa) . ");";
            }

            return $this->db->exec($sql);
        }
        return FALSE;
    }

    public function delete()
    {
        if ($this->por_defecto) {
            $this->new_error_msg("No se puede eliminar la tarifa por defecto.");
            return FALSE;
        }
        
        // Eliminar también los precios de artículos asociados a esta tarifa
        $this->db->exec("DELETE FROM tarif_articulo_precios WHERE codtarifa = " . $this->var2str($this->codtarifa) . ";");
        
        return $this->db->exec("DELETE FROM " . $this->table_name . " WHERE codtarifa = " . $this->var2str($this->codtarifa) . ";");
    }

    /**
     * Devuelve todas las tarifas.
     * @return array
     */
    public function all()
    {
        $list = [];
        $data = $this->db->select("SELECT * FROM " . $this->table_name . " ORDER BY nombre ASC;");
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_tarifa($d);
            }
        }
        return $list;
    }

    /**
     * Devuelve todas las tarifas activas.
     * @return array
     */
    public function all_activas()
    {
        $list = [];
        $data = $this->db->select("SELECT * FROM " . $this->table_name . " WHERE activa = TRUE ORDER BY nombre ASC;");
        if ($data) {
            foreach ($data as $d) {
                $list[] = new tarif_tarifa($d);
            }
        }
        return $list;
    }
}
