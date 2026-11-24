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

require_once __DIR__ . '/tarif_tarifa_idioma.php';

/**
 * Tarifas del plugin tarifario.
 * Permite crear tarifas nombradas (ej: "Tarifa 2025", "Tarifa 2026")
 * para asignar precios diferentes a los artículos.
 */
class tarif_tarifa extends \fs_model
{
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
     * ISO 4217 currency code (3 characters). Single source of truth for the
     * tariff's currency; views read it to render the symbol. Which codes can be
     * chosen is decided by the `admin_divisas` catalog (change
     * `tarifas-divisa-catalogo`), not by a hardcoded list here.
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
        // ISO 4217 uses three letters (EUR, USD, MXN): digits are not a currency.
        if (preg_match('/^[A-Z]{3}$/', $this->coddivisa) !== 1) {
            $this->new_error_msg("Moneda no válida. Debe ser un código ISO de 3 letras.");
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

            if (!$this->db->exec($sql)) {
                return FALSE;
            }

            return $this->saveListMirror();
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

        // Idiomas de la tarifa: la FK es en cascada, pero en MySQL sin InnoDB
        // o con la tabla creada antes de la FK el borrado explícito es la garantía.
        if ($this->db->table_exists(tarif_tarifa_idioma::TABLE)) {
            $this->db->exec("DELETE FROM " . tarif_tarifa_idioma::TABLE . " WHERE codtarifa = " . $this->var2str($this->codtarifa) . ";");
        }

        if (!$this->db->exec("DELETE FROM " . $this->table_name . " WHERE codtarifa = " . $this->var2str($this->codtarifa) . ";")) {
            return FALSE;
        }

        return $this->deleteListMirror();
    }

    /**
     * Tablas cuya FK a `catalogo_listas_precio.codlista` es ON DELETE CASCADE.
     * Borrar un espejo referenciado arrastraría precios de opcionales, acceso
     * por grupo y estructura de familias (R-MT-007).
     */
    private const LIST_DEPENDENTS = [
        'catalogo_articulo_precios',
        'catalogo_opcional_precios',
        'catalogo_grupo_tarifas',
        'catalogo_familia_estructura',
        'catalogo_articulo_familia_estructura',
    ];

    private const LIST_TABLE = 'catalogo_listas_precio';

    /**
     * Upsert del espejo en `catalogo_listas_precio` (R-MT-007): mismo código,
     * nombre, flags y moneda. Los lectores canónicos de precio leen la lista,
     * no la tarifa. Sin la tabla de listas la tarifa se guarda igual.
     */
    private function saveListMirror(): bool
    {
        if (!$this->db->table_exists(self::LIST_TABLE)) {
            return TRUE;
        }

        $cod = $this->var2str($this->codtarifa);

        if ($this->por_defecto
            && !$this->db->exec("UPDATE " . self::LIST_TABLE . " SET por_defecto = FALSE WHERE codlista != " . $cod . ";")) {
            return FALSE;
        }

        $exists = $this->db->select("SELECT codlista FROM " . self::LIST_TABLE . " WHERE codlista = " . $cod . ";");
        if ($exists) {
            $sql = "UPDATE " . self::LIST_TABLE . " SET "
                . "nombre = " . $this->var2str($this->nombre)
                . ", activa = " . $this->var2str($this->activa)
                . ", por_defecto = " . $this->var2str($this->por_defecto)
                . ", coddivisa = " . $this->var2str($this->coddivisa)
                . " WHERE codlista = " . $cod . ";";
        } else {
            $sql = "INSERT INTO " . self::LIST_TABLE . " (codlista, nombre, activa, por_defecto, coddivisa) VALUES ("
                . $cod . ","
                . $this->var2str($this->nombre) . ","
                . $this->var2str($this->activa) . ","
                . $this->var2str($this->por_defecto) . ","
                . $this->var2str($this->coddivisa) . ");";
        }

        return (bool) $this->db->exec($sql);
    }

    /**
     * Borra el espejo, o lo desactiva si alguna tabla dependiente lo
     * referencia: sus FK son en cascada y el borrado sería silencioso.
     */
    private function deleteListMirror(): bool
    {
        if (!$this->db->table_exists(self::LIST_TABLE)) {
            return TRUE;
        }

        $cod = $this->var2str($this->codtarifa);

        foreach (self::LIST_DEPENDENTS as $table) {
            if ($this->db->table_exists($table)
                && $this->db->select("SELECT 1 FROM " . $table . " WHERE codlista = " . $cod . " LIMIT 1;")) {
                return (bool) $this->db->exec(
                    "UPDATE " . self::LIST_TABLE . " SET activa = FALSE, por_defecto = FALSE WHERE codlista = " . $cod . ";"
                );
            }
        }

        return (bool) $this->db->exec("DELETE FROM " . self::LIST_TABLE . " WHERE codlista = " . $cod . ";");
    }

    /**
     * Idiomas configurados en la tarifa: codidioma => por_defecto. Vacío si la
     * tarifa no tiene configuración propia (usa todos los idiomas activos).
     *
     * @return array<string, bool>
     */
    public function idiomas_config(): array
    {
        if ($this->codtarifa === NULL || $this->codtarifa === '') {
            return [];
        }

        return $this->tarifa_idioma_model()->config_from_tarifa($this->codtarifa);
    }

    /**
     * Idiomas efectivos de la tarifa (R-TAR-IDI-002): por defecto primero y
     * el resto por código. Ver tarif_tarifa_idioma::resolve().
     *
     * @return array{codes: string[], default: string}
     */
    public function idiomas_efectivos(): array
    {
        $registry = $this->idioma_registry();
        $active = [];
        foreach ((array) $registry->all_activos() as $idioma) {
            $active[] = (string) $idioma->codidioma;
        }

        return tarif_tarifa_idioma::resolve(
            $active,
            $this->idiomas_config(),
            (string) $registry->get_effective_default_code()
        );
    }

    /**
     * Códigos de los idiomas en los que va la tarifa, el de por defecto primero.
     *
     * @return string[]
     */
    public function idiomas_codes(): array
    {
        return $this->idiomas_efectivos()['codes'];
    }

    /**
     * Idioma por defecto de la tarifa.
     */
    public function codidioma_defecto(): string
    {
        return $this->idiomas_efectivos()['default'];
    }

    /**
     * Idiomas (catalogo_idioma) en los que va la tarifa, en el orden de
     * idiomas_codes().
     *
     * @return catalogo_idioma[]
     */
    public function idiomas(): array
    {
        $byCode = [];
        foreach ((array) $this->idioma_registry()->all_activos() as $idioma) {
            $byCode[(string) $idioma->codidioma] = $idioma;
        }

        $list = [];
        foreach ($this->idiomas_codes() as $code) {
            if (isset($byCode[$code])) {
                $list[] = $byCode[$code];
            }
        }

        return $list;
    }

    /**
     * Guarda los idiomas de la tarifa. `$codes` vacío borra la configuración.
     *
     * @param string[] $codes
     */
    public function save_idiomas(array $codes, string $default): bool
    {
        if ($this->codtarifa === NULL || $this->codtarifa === '') {
            return FALSE;
        }

        return $this->tarifa_idioma_model()->save_config($this->codtarifa, $codes, $default);
    }

    /**
     * Seam: registro de idiomas.
     * @return catalogo_idioma
     */
    protected function idioma_registry()
    {
        return new catalogo_idioma();
    }

    /**
     * Seam: persistencia de los idiomas por tarifa.
     * @return tarif_tarifa_idioma
     */
    protected function tarifa_idioma_model()
    {
        return new tarif_tarifa_idioma();
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
