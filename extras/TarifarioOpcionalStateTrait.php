<?php
/**
 * This file is part of catalogo_core
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

require_once 'plugins/catalogo_core/extras/fbase_controller.php';
require_once 'plugins/catalogo_core/model/core/catalogo_idioma.php';
require_once 'plugins/catalogo_core/model/core/articulo.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa.php';
require_once 'plugins/catalogo_core/Init.php';

use FSFramework\model\articulo;
use FSFramework\model\catalogo_idioma;
use FSFramework\model\tarif_tarifa;
use FSFramework\Plugins\catalogo_core\Init;

/**
 * Shared per-tarifa state for the opcional controllers absorbed from tarifario.
 *
 * Mirrors the subset of tarifario's tarif_controller that the four moved
 * controllers consume, but backed exclusively by catalogo_core models so the
 * opcional pages keep working with tarifario inactive (design D2):
 *  - $tarifas / $tarifa_defecto / $tarifas_defecto / $codtarifa
 *  - $codidioma / $idiomas
 *  - tarif_paginas() / tarif_buscar_articulo()
 *  - a once-per-process guard that bootstraps the moved opcional tables.
 *
 * Additive only: it never replaces fbase_controller::private_core() nor the
 * host controller's own private_core().
 */
trait TarifarioOpcionalStateTrait
{
    /**
     * Guard: the moved opcional tables are ensured once per controller process.
     * @var boolean
     */
    private static bool $tables_ensured = false;

    /**
     * Idioma activo para mostrar descripciones.
     * @var string
     */
    public $codidioma;

    /**
     * Idiomas disponibles.
     * @var array
     */
    public $idiomas;

    /**
     * Tarifas activas disponibles.
     * @var array
     */
    public $tarifas;

    /**
     * Tarifa seleccionada para mostrar precios.
     * @var string
     */
    public $codtarifa;

    /**
     * Tarifa por defecto (nombre heredado de tarif_controller).
     * @var tarif_tarifa|false
     */
    public $tarifa_defecto;

    /**
     * Tarifa por defecto (nombre del contrato del trait, design D2).
     * @var tarif_tarifa|false
     */
    public $tarifas_defecto;

    /**
     * Carga el estado del tarifario que consumen los controladores movidos y
     * asegura una vez por proceso las tablas de opcionales absorbidas.
     */
    protected function init_tarifario_opcional_state(): void
    {
        if (!self::$tables_ensured) {
            self::$tables_ensured = true;
            if (class_exists(Init::class)) {
                Init::ensureOpcionalesTarifaTables();
            }
        }

        // Cargar idiomas
        $idioma = new catalogo_idioma();
        $this->idiomas = $idioma->all_activos();

        // Idioma por defecto
        $this->codidioma = 'es';
        if (isset($_REQUEST['codidioma'])) {
            $this->codidioma = $_REQUEST['codidioma'];
        } else {
            $default = $idioma->get_default();
            if ($default) {
                $this->codidioma = $default->codidioma;
            }
        }

        // Cargar tarifas
        $tarifa = new tarif_tarifa();
        $this->tarifas = $tarifa->all_activas();
        $this->tarifa_defecto = $tarifa->get_default();
        $this->tarifas_defecto = $this->tarifa_defecto;

        // Tarifa seleccionada
        $this->codtarifa = '';
        if (isset($_REQUEST['codtarifa'])) {
            $this->codtarifa = $_REQUEST['codtarifa'];
        } elseif ($this->tarifa_defecto) {
            $this->codtarifa = $this->tarifa_defecto->codtarifa;
        }
    }

    /**
     * Devuelve un array con los enlaces a las páginas en función de la url,
     * total y el offset proporcionado.
     *
     * @param string  $url
     * @param integer $total
     * @param integer $offset
     *
     * @return array
     */
    protected function tarif_paginas($url, $total, $offset)
    {
        return $this->fbase_paginas($url, $total, $offset);
    }

    /**
     * Busca artículos y devuelve JSON. Backed by catalogo_core's articulo model
     * so the trait never depends on a tarifario PHP class.
     * @param string $query
     */
    protected function tarif_buscar_articulo($query)
    {
        $this->template = FALSE;

        $articulo = new articulo();
        $json = [];
        foreach ($articulo->search($query) as $art) {
            $json[] = array(
                'value' => $art->referencia . ' - ' . $art->descripcion_idioma($this->codidioma, 50),
                'data' => $art->referencia,
                'referencia' => $art->referencia,
                'descripcion' => $art->get_descripcion_idioma($this->codidioma),
                'precio' => $art->pvp,
                'codfamilia' => $art->codfamilia
            );
        }

        header('Content-Type: application/json');
        echo json_encode(array('query' => $query, 'suggestions' => $json));
    }
}
