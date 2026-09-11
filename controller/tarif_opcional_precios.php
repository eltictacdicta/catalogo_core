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

require_once 'plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa.php';
require_once 'plugins/catalogo_core/model/tarif_opcional_precio.php';
require_once 'plugins/catalogo_core/model/tarif_familia.php';

use FSFramework\model\tarif_familia;
use FSFramework\model\tarif_opcional;
use FSFramework\model\tarif_tarifa;
use FSFramework\model\tarif_opcional_precio;

/**
 * Controlador para gestionar los precios y configuración de un opcional
 * en una tarifa específica.
 */
class tarif_opcional_precios extends fbase_controller
{
    use TarifarioOpcionalStateTrait;

    public $opcional;
    public $tarifa_seleccionada;
    public $precio_opcional;
    public $familia;
    public $familias_asignadas;
    public $articulos;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Precios Opcional', 'tarifario', FALSE, FALSE);
    }

    protected function private_core()
    {
        parent::private_core();
        $this->init_tarifario_opcional_state();

        $this->opcional = FALSE;
        $this->tarifa_seleccionada = FALSE;
        $this->familia = new tarif_familia();

        // Obtener opcional
        if (isset($_REQUEST['id'])) {
            $opc = new tarif_opcional();
            $this->opcional = $opc->get($_REQUEST['id']);
        }

        // Obtener tarifa seleccionada
        $tarifa_model = new tarif_tarifa();
        if (isset($_REQUEST['codtarifa']) && $_REQUEST['codtarifa'] != '') {
            $this->tarifa_seleccionada = $tarifa_model->get($_REQUEST['codtarifa']);
        }
        
        // Si no hay tarifa seleccionada, usar la por defecto
        if (!$this->tarifa_seleccionada) {
            $this->tarifa_seleccionada = $tarifa_model->get_default();
        }
        
        // Si aún no hay tarifa, usar la primera disponible
        if (!$this->tarifa_seleccionada && count($this->tarifas) > 0) {
            $this->tarifa_seleccionada = $this->tarifas[0];
        }

        if ($this->opcional && $this->tarifa_seleccionada) {
            $this->page->title = $this->opcional->codigo . ' - ' . $this->tarifa_seleccionada->nombre;

            // Procesar acciones
            if (isset($_POST['guardar_precio_tarifa'])) {
                $this->guardar_precio_tarifa();
            }

            // Cargar datos
            $this->load_precio_opcional();
            $this->familias_asignadas = $this->opcional->get_familias();
            $this->articulos = $this->opcional->get_articulos();
        } else if (!$this->opcional) {
            $this->new_error_msg('Opcional no encontrado.');
        } else {
            $this->new_error_msg('No hay tarifas configuradas. <a href="index.php?page=tarif_tarifas">Crear tarifas</a>');
        }
    }

    /**
     * URL de esta página con los parámetros actuales.
     */
    public function url_actual()
    {
        $url = $this->url();
        if ($this->opcional) {
            $url .= '&id=' . $this->opcional->id;
        }
        if ($this->tarifa_seleccionada) {
            $url .= '&codtarifa=' . urlencode($this->tarifa_seleccionada->codtarifa);
        }
        return $url;
    }

    /**
     * Carga el precio del opcional en la tarifa seleccionada.
     */
    private function load_precio_opcional()
    {
        $this->precio_opcional = null;
        
        $precio_model = new tarif_opcional_precio();
        $this->precio_opcional = $precio_model->get($this->opcional->id, $this->tarifa_seleccionada->codtarifa);
    }

    /**
     * Obtiene el precio del opcional en la tarifa seleccionada.
     * @return float|null
     */
    public function get_precio()
    {
        if ($this->precio_opcional) {
            return $this->precio_opcional->precio;
        }
        return null;
    }

    /**
     * Verifica si el opcional está activo en la tarifa seleccionada.
     * @return bool
     */
    public function esta_activo_en_tarifa()
    {
        return ($this->precio_opcional !== null);
    }

    /**
     * Verifica si el opcional está en el catálogo de la tarifa seleccionada.
     * @return bool
     */
    public function esta_en_catalogo()
    {
        if ($this->precio_opcional) {
            return $this->precio_opcional->en_catalogo;
        }
        return false;
    }

    /**
     * Guarda el precio del opcional en la tarifa seleccionada.
     */
    private function guardar_precio_tarifa()
    {
        $precio_model = new tarif_opcional_precio();
        
        // Si está marcado como activo en esta tarifa
        if (isset($_POST['activo_tarifa']) && $_POST['activo_tarifa']) {
            // Obtener el precio (puede ser 0)
            $precio_str = isset($_POST['precio']) ? trim($_POST['precio']) : '0';
            $precio_valor = floatval(str_replace(',', '.', $precio_str));
            
            // Obtener si está en catálogo
            $en_catalogo = isset($_POST['en_catalogo']) && $_POST['en_catalogo'];
            
            // Buscar o crear registro
            $precio = $precio_model->get($this->opcional->id, $this->tarifa_seleccionada->codtarifa);
            if (!$precio) {
                $precio = new tarif_opcional_precio();
                $precio->id_opcional = $this->opcional->id;
                $precio->codtarifa = $this->tarifa_seleccionada->codtarifa;
            }
            $precio->precio = $precio_valor;
            $precio->en_catalogo = $en_catalogo;
            
            if ($precio->save()) {
                $this->new_message('Precio guardado correctamente para la tarifa "' . $this->tarifa_seleccionada->nombre . '".');
            } else {
                $this->new_error_msg('Error al guardar el precio.');
            }
        } else {
            // Si no está activo, eliminar el precio de esta tarifa
            if ($this->opcional->delete_precio_tarifa($this->tarifa_seleccionada->codtarifa)) {
                $this->new_message('Opcional desactivado de la tarifa "' . $this->tarifa_seleccionada->nombre . '".');
            }
        }
        
        // Actualizar los campos en_tarifa y en_catalogo basados en la configuración global
        $precios = $this->opcional->get_precios_tarifas();
        $this->opcional->en_tarifa = (count($precios) > 0);
        $this->opcional->en_catalogo = false;
        foreach ($precios as $p) {
            if ($p->en_catalogo) {
                $this->opcional->en_catalogo = true;
                break;
            }
        }
        $this->opcional->save();
        
        // Recargar precio
        $this->load_precio_opcional();
    }

    /**
     * Obtiene los precios del opcional en todas las tarifas.
     * @return array Array indexado por codtarifa
     */
    public function get_precios_todas_tarifas()
    {
        $precios = [];
        $precio_model = new tarif_opcional_precio();
        
        foreach ($this->tarifas as $tarifa) {
            $precio = $precio_model->get($this->opcional->id, $tarifa->codtarifa);
            $precios[$tarifa->codtarifa] = [
                'tarifa' => $tarifa,
                'precio' => $precio ? $precio->precio : null,
                'en_catalogo' => $precio ? $precio->en_catalogo : false,
                'activo' => ($precio !== null)
            ];
        }
        
        return $precios;
    }
}
