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
require_once 'plugins/catalogo_core/model/tarif_familia.php';
require_once 'plugins/catalogo_core/model/tarif_opcional_precio.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_etiqueta_familia.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_opcional_etiqueta.php';

use FSFramework\model\tarif_familia;
use FSFramework\model\tarif_tarifa_etiqueta_familia;
use FSFramework\model\tarif_tarifa_opcional_etiqueta;
use FSFramework\model\tarif_opcional;
use FSFramework\model\tarif_articulo_opcional;
use FSFramework\model\tarif_opcional_precio;

/**
 * Controlador para ver/editar un opcional del tarifario.
 */
class tarif_opcional_edit extends fbase_controller
{
    use TarifarioOpcionalStateTrait;

    public $opcional;
    public $familia;
    public $familias_asignadas;
    public $articulos;
    public $articulos_disponibles;
    public $precios_tarifas;
    public $tarifa_etiqueta_familia;
    public $tarifa_opcional_etiqueta;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Opcional Tarifario', 'tarifario', FALSE, FALSE);
    }

    protected function private_core()
    {
        parent::private_core();
        $this->init_tarifario_opcional_state();

        $this->familia = new tarif_familia();
        $this->tarifa_etiqueta_familia = new tarif_tarifa_etiqueta_familia();
        $this->tarifa_opcional_etiqueta = new tarif_tarifa_opcional_etiqueta();
        $this->opcional = FALSE;

        if (isset($_REQUEST['id'])) {
            $opc = new tarif_opcional();
            $this->opcional = $opc->get($_REQUEST['id']);
        }

        if ($this->opcional) {
            $this->page->title = $this->opcional->codigo;

            // Procesar acciones
            if (isset($_POST['codigo'])) {
                $this->modificar();
            } else if (isset($_POST['save_etiquetas_familia'])) {
                $this->save_etiquetas_familia();
            } else if (isset($_POST['guardar_precios_tarifas'])) {
                $this->guardar_precios_tarifas();
            } else if (isset($_GET['add_familia'])) {
                $this->add_familia();
            } else if (isset($_GET['remove_familia'])) {
                $this->remove_familia();
            } else if (isset($_GET['add_articulo'])) {
                $this->add_articulo();
            } else if (isset($_GET['remove_articulo'])) {
                $this->remove_articulo();
            } else if (isset($_REQUEST['buscar_articulo'])) {
                $this->tarif_buscar_articulo($_REQUEST['buscar_articulo']);
            }

            // Cargar datos
            $this->familias_asignadas = $this->opcional->get_familias();
            $this->articulos = $this->opcional->get_articulos();
            
            // Cargar precios por tarifa
            $this->load_precios_tarifas();
        } else {
            $this->new_error_msg('Opcional no encontrado.');
        }
    }

    private function modificar()
    {
        $this->opcional->nombre = $_POST['nombre'];
        $this->opcional->descripcion = isset($_POST['descripcion']) ? $_POST['descripcion'] : '';
        $this->opcional->ref_sap = isset($_POST['ref_sap']) ? trim($_POST['ref_sap']) : null;
        if ($this->opcional->ref_sap === '') {
            $this->opcional->ref_sap = null;
        }
        $this->opcional->activo = isset($_POST['activo']);

        if ($this->opcional->save()) {
            $this->new_message('Datos guardados correctamente.');
        } else {
            $this->new_error_msg('Error al guardar los datos.');
        }
    }

    private function add_familia()
    {
        $codfamilia = $_GET['add_familia'];
        $propagate = isset($_GET['propagate']) && $_GET['propagate'] === 'true';
        
        if ($propagate) {
            // Usar propagación: añade a familia Y a todos los artículos de esa familia
            $result = $this->opcional->add_familia($codfamilia);
            if ($result['familia']) {
                $this->new_message('Familia añadida correctamente.');
                if ($result['articulos'] > 0) {
                    $this->new_message('Opcional asignado a ' . $result['articulos'] . ' artículo(s) de la familia.');
                } else {
                    $this->new_advice('La familia no tiene artículos o todos ya tenían este opcional.');
                }
            } else {
                $this->new_error_msg('Error al añadir la familia.');
            }
        } else {
            // Sin propagación: solo añade la relación familia-opcional
            if ($this->opcional->add_familia_only($codfamilia)) {
                $this->new_message('Familia añadida correctamente (sin propagar a artículos).');
            } else {
                $this->new_error_msg('Error al añadir la familia.');
            }
        }
    }

    private function remove_familia()
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar.');
            return;
        }

        $codfamilia = $_GET['remove_familia'];
        $propagate = isset($_GET['propagate']) && $_GET['propagate'] === 'true';
        
        if ($propagate) {
            // Usar propagación: elimina de familia Y de todos los artículos de esa familia
            $result = $this->opcional->remove_familia($codfamilia);
            if ($result['familia']) {
                $this->new_message('Familia eliminada correctamente.');
                if ($result['articulos'] > 0) {
                    $this->new_message('Opcional eliminado de ' . $result['articulos'] . ' artículo(s) de la familia.');
                }
            } else {
                $this->new_error_msg('Error al eliminar la familia.');
            }
        } else {
            // Sin propagación: solo elimina la relación familia-opcional
            if ($this->opcional->remove_familia_only($codfamilia)) {
                $this->new_message('Familia eliminada correctamente (sin eliminar de artículos).');
            } else {
                $this->new_error_msg('Error al eliminar la familia.');
            }
        }
    }

    private function add_articulo()
    {
        $rel = new tarif_articulo_opcional();
        $referencia = $_GET['add_articulo'];

        if ($rel->add($referencia, $this->opcional->id)) {
            $this->new_message('Artículo añadido correctamente.');
        } else {
            $this->new_error_msg('Error al añadir el artículo.');
        }
    }

    private function remove_articulo()
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar.');
            return;
        }

        $rel = new tarif_articulo_opcional();
        $referencia = $_GET['remove_articulo'];

        if ($rel->remove($referencia, $this->opcional->id)) {
            $this->new_message('Artículo eliminado correctamente.');
        } else {
            $this->new_error_msg('Error al eliminar el artículo.');
        }
    }

    /**
     * Devuelve las familias no asignadas a este opcional.
     * @return array
     */
    public function get_familias_disponibles()
    {
        $todas = $this->familia->all();
        $asignadas = array_map(function ($f) {
            return $f->codfamilia; }, $this->familias_asignadas);

        return array_filter($todas, function ($f) use ($asignadas) {
            return !in_array($f->codfamilia, $asignadas);
        });
    }

    /**
     * Carga los precios del opcional en todas las tarifas.
     */
    private function load_precios_tarifas()
    {
        $this->precios_tarifas = [];
        
        // Crear array indexado por codtarifa
        foreach ($this->tarifas as $tarifa) {
            $this->precios_tarifas[$tarifa->codtarifa] = [
                'tarifa' => $tarifa,
                'precio' => null,
                'en_catalogo' => false
            ];
        }
        
        // Cargar precios existentes
        foreach ($this->opcional->get_precios_tarifas() as $precio) {
            if (isset($this->precios_tarifas[$precio->codtarifa])) {
                $this->precios_tarifas[$precio->codtarifa]['precio'] = $precio->precio;
                $this->precios_tarifas[$precio->codtarifa]['en_catalogo'] = $precio->en_catalogo;
            }
        }
    }

    /**
     * Guarda los precios del opcional en las tarifas.
     * Ahora también maneja la activación/desactivación y el catálogo por tarifa.
     */
    private function guardar_precios_tarifas()
    {
        $guardados = 0;
        $eliminados = 0;
        $precio_model = new tarif_opcional_precio();
        
        foreach ($this->tarifas as $tarifa) {
            $activo_key = 'activo_tarifa_' . $tarifa->codtarifa;
            $catalogo_key = 'catalogo_tarifa_' . $tarifa->codtarifa;
            $precio_key = 'precio_tarifa_' . $tarifa->codtarifa;
            
            // Si está marcado como activo en esta tarifa
            if (isset($_POST[$activo_key]) && $_POST[$activo_key]) {
                // Obtener el precio (puede ser 0)
                $precio_str = isset($_POST[$precio_key]) ? trim($_POST[$precio_key]) : '0';
                $precio_valor = floatval(str_replace(',', '.', $precio_str));
                
                // Obtener si está en catálogo
                $en_catalogo = isset($_POST[$catalogo_key]) && $_POST[$catalogo_key];
                
                // Buscar o crear registro
                $precio = $precio_model->get($this->opcional->id, $tarifa->codtarifa);
                if (!$precio) {
                    $precio = new tarif_opcional_precio();
                    $precio->id_opcional = $this->opcional->id;
                    $precio->codtarifa = $tarifa->codtarifa;
                }
                $precio->precio = $precio_valor;
                $precio->en_catalogo = $en_catalogo;
                
                if ($precio->save()) {
                    $guardados++;
                }
            } else {
                // Si no está activo, eliminar el precio de esta tarifa
                if ($this->opcional->delete_precio_tarifa($tarifa->codtarifa)) {
                    $eliminados++;
                }
            }
        }
        
        // Actualizar los campos en_tarifa y en_catalogo basados en la configuración
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
        
        $this->new_message("Tarifas actualizadas: $guardados activas, $eliminados desactivadas.");
    }

    /**
     * Guarda etiquetas para el opcional en una familia concreta de la tarifa actual.
     */
    private function save_etiquetas_familia()
    {
        $codfamilia = isset($_POST['codfamilia']) ? trim($_POST['codfamilia']) : '';
        if (empty($codfamilia)) {
            $this->new_error_msg('Familia no indicada para guardar etiquetas.');
            return;
        }

        if (isset($_POST['etiquetas_familia']) && is_array($_POST['etiquetas_familia'])) {
            $submitted = $_POST['etiquetas_familia'];
        } else {
            $raw = isset($_POST['etiquetas_familia']) ? trim($_POST['etiquetas_familia']) : '';
            $submitted = preg_split('/[,\n]+/', $raw);
        }

        $allowed = $this->tarifa_etiqueta_familia->get_etiquetas_familia($this->codtarifa, $codfamilia);
        $allowed_index = [];
        foreach ($allowed as $tag) {
            $allowed_index[$tag] = true;
        }

        $filtered = [];
        foreach ((array) $submitted as $tag) {
            $tag = trim((string) $tag);
            if (!empty($tag) && isset($allowed_index[$tag])) {
                $filtered[] = $tag;
            }
        }

        if ($this->tarifa_opcional_etiqueta->replace_etiquetas_opcional($this->codtarifa, $this->opcional->id, $codfamilia, $filtered)) {
            $this->new_message('Etiquetas del opcional guardadas para la familia ' . $codfamilia . '.');
        } else {
            $this->new_error_msg('Error al guardar las etiquetas del opcional.');
        }
    }

    /**
     * Obtiene el precio del opcional en una tarifa específica.
     * @param string $codtarifa
     * @return float|null
     */
    public function get_precio_tarifa($codtarifa)
    {
        if (isset($this->precios_tarifas[$codtarifa])) {
            return $this->precios_tarifas[$codtarifa]['precio'];
        }
        return null;
    }
    
    /**
     * Verifica si el opcional está activo en una tarifa específica.
     * Un opcional está activo en una tarifa si tiene un registro de precio (incluso si es 0).
     * @param string $codtarifa
     * @return bool
     */
    public function opcional_activo_en_tarifa($codtarifa)
    {
        if (isset($this->precios_tarifas[$codtarifa])) {
            return $this->precios_tarifas[$codtarifa]['precio'] !== null;
        }
        return false;
    }
    
    /**
     * Verifica si el opcional está en el catálogo de una tarifa específica.
     * @param string $codtarifa
     * @return bool
     */
    public function opcional_en_catalogo_tarifa($codtarifa)
    {
        if (isset($this->precios_tarifas[$codtarifa])) {
            return $this->precios_tarifas[$codtarifa]['en_catalogo'];
        }
        return false;
    }

    /**
     * Etiquetas disponibles en una familia para la tarifa actual.
     *
     * @param string $codfamilia
     * @return array
     */
    public function get_etiquetas_disponibles_familia($codfamilia)
    {
        if (empty($this->codtarifa) || empty($codfamilia)) {
            return [];
        }

        return $this->tarifa_etiqueta_familia->get_etiquetas_familia($this->codtarifa, $codfamilia);
    }

    /**
     * Etiquetas seleccionadas para este opcional en una familia de la tarifa actual.
     *
     * @param string $codfamilia
     * @return array
     */
    public function get_etiquetas_opcional_familia($codfamilia)
    {
        if (empty($this->codtarifa) || empty($codfamilia)) {
            return [];
        }

        return $this->tarifa_opcional_etiqueta->get_etiquetas_opcional($this->codtarifa, $this->opcional->id, $codfamilia);
    }

    /**
     * Etiquetas disponibles en una familia como texto separado por comas.
     *
     * @param string $codfamilia
     * @return string
     */
    public function get_etiquetas_disponibles_familia_text($codfamilia)
    {
        $tags = $this->get_etiquetas_disponibles_familia($codfamilia);
        return implode(', ', $tags);
    }

    /**
     * Etiquetas ya asignadas al opcional para una familia en la tarifa actual.
     *
     * @param string $codfamilia
     * @return string
     */
    public function get_etiquetas_opcional_familia_text($codfamilia)
    {
        if (empty($this->codtarifa) || empty($codfamilia)) {
            return '';
        }

        $tags = $this->tarifa_opcional_etiqueta->get_etiquetas_opcional($this->codtarifa, $this->opcional->id, $codfamilia);
        return implode(', ', $tags);
    }
}
