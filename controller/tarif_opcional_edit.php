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
require_once 'plugins/catalogo_core/model/tarif_tarifa_opcional.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_etiqueta_familia.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_opcional_etiqueta.php';
require_once 'plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';

use FSFramework\model\tarif_familia;
use FSFramework\model\tarif_tarifa_etiqueta_familia;
use FSFramework\model\tarif_tarifa_opcional_etiqueta;
use FSFramework\model\tarif_opcional;
use FSFramework\model\catalogo_articulo_opcional;
use FSFramework\model\tarif_opcional_precio;
use FSFramework\model\tarif_tarifa_opcional;

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

    /**
     * Tarifa activa resuelta para esta página (tarif_tarifa|false).
     *
     * @var \FSFramework\model\tarif_tarifa|false
     */
    public $tarifa_seleccionada;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Opcional Tarifario', 'tarifario', FALSE, FALSE);
    }

    protected function private_core()
    {
        parent::private_core();
        $this->init_tarifario_opcional_state();
        $this->resolver_tarifa_seleccionada();

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
            } else if (isset($_POST['guardar_precio_tarifa'])) {
                $this->guardar_precio_tarifa();
            } else if (isset($_POST['guardar_precios_tarifas'])) {
                $this->guardar_precios_tarifas();
            } else if (isset($_POST['add_familia'])) {
                $this->add_familia();
            } else if (isset($_POST['remove_familia'])) {
                $this->remove_familia();
            } else if (isset($_POST['add_articulo'])) {
                $this->add_articulo();
            } else if (isset($_POST['remove_articulo'])) {
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

    /**
     * Resolves the tarifa shown/edited by the page from the raw request state.
     *
     * The requested `codtarifa` is validated against the active tarifas loaded
     * by the trait; an unknown or absent code falls back to the default tarifa
     * and then to the first active one. `tarifa_seleccionada` is exposed to the
     * view and `codtarifa` is normalized to the resolved code (OTS-01, OTS-03).
     */
    private function resolver_tarifa_seleccionada(): void
    {
        $selected = null;

        if ($this->codtarifa !== '') {
            foreach ($this->tarifas as $tarifa) {
                if ($tarifa->codtarifa === $this->codtarifa) {
                    $selected = $tarifa;
                    break;
                }
            }
        }
        if ($selected === null && $this->tarifa_defecto) {
            foreach ($this->tarifas as $tarifa) {
                if ($tarifa->codtarifa === $this->tarifa_defecto->codtarifa) {
                    $selected = $tarifa;
                    break;
                }
            }
        }
        if ($selected === null && count($this->tarifas) > 0) {
            $selected = $this->tarifas[0];
        }

        $this->tarifa_seleccionada = $selected !== null ? $selected : false;
        if ($selected !== null) {
            $this->codtarifa = $selected->codtarifa;
        }
    }

    /**
     * Guards a migrated mutation: POST only and a valid CSRF token.
     *
     * GET would bypass requireCsrf(), so the controller never dispatches these
     * mutations from $_GET (AD-6).
     */
    private function guard_mutating_action(): bool
    {
        $request = $this->getRequest();
        if (strtoupper($request->getMethod()) !== 'POST') {
            return false;
        }

        return (bool) $this->requireCsrf();
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

    private function add_familia(): void
    {
        if (!$this->guard_mutating_action()) {
            $this->new_error_msg('Token de seguridad inválido o método no permitido.');
            return;
        }

        $codfamilia = isset($_POST['add_familia']) ? trim((string) $_POST['add_familia']) : '';
        $propagate = isset($_POST['propagate']) && $_POST['propagate'] === 'true';
        
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

    private function remove_familia(): void
    {
        if (!$this->guard_mutating_action()) {
            $this->new_error_msg('Token de seguridad inválido o método no permitido.');
            return;
        }

        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar.');
            return;
        }

        $codfamilia = isset($_POST['remove_familia']) ? trim((string) $_POST['remove_familia']) : '';
        $propagate = isset($_POST['propagate']) && $_POST['propagate'] === 'true';
        
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

    private function add_articulo(): void
    {
        if (!$this->guard_mutating_action()) {
            $this->new_error_msg('Token de seguridad inválido o método no permitido.');
            return;
        }

        $rel = new catalogo_articulo_opcional();
        $referencia = isset($_POST['add_articulo']) ? trim((string) $_POST['add_articulo']) : '';

        if ($rel->add($referencia, $this->opcional->id)) {
            $this->new_message('Artículo añadido correctamente.');
        } else {
            $this->new_error_msg('Error al añadir el artículo.');
        }
    }

    private function remove_articulo(): void
    {
        if (!$this->guard_mutating_action()) {
            $this->new_error_msg('Token de seguridad inválido o método no permitido.');
            return;
        }

        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar.');
            return;
        }

        $rel = new catalogo_articulo_opcional();
        $referencia = isset($_POST['remove_articulo']) ? trim((string) $_POST['remove_articulo']) : '';

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
     * Master-state accessor for the per-tarifa matrix.
     *
     * Overridable seam so the matrix state resolution is unit-testable without
     * reaching a live database.
     *
     * @return tarif_tarifa_opcional
     */
    protected function opcional_master_state()
    {
        return new tarif_tarifa_opcional();
    }

    /**
     * Carga los precios del opcional en todas las tarifas.
     *
     * Master flags (`activa`/`en_catalogo`/`en_tarifa`) come from the
     * per-tarifa master via effective(); a missing row inherits the
     * ext/defaults without persisting (design AD5).
     */
    private function load_precios_tarifas()
    {
        $this->precios_tarifas = [];
        $master = $this->opcional_master_state();
        $es_porcentaje = $this->opcional_es_porcentaje();

        // Crear array indexado por codtarifa
        foreach ($this->tarifas as $tarifa) {
            $state = $master->effective($tarifa->codtarifa, $this->opcional->id);
            $this->precios_tarifas[$tarifa->codtarifa] = [
                'tarifa' => $tarifa,
                'precio' => null,
                'porcentaje' => null,
                'es_porcentaje' => $es_porcentaje,
                'en_catalogo' => (bool) $state['en_catalogo'],
                'en_tarifa' => (bool) $state['en_tarifa'],
                'activa' => (bool) $state['activa'],
            ];
        }

        // Cargar valores existentes (precio o porcentaje según el modo)
        foreach ($this->opcional->get_precios_tarifas() as $precio) {
            if (!isset($this->precios_tarifas[$precio->codtarifa])) {
                continue;
            }

            $this->precios_tarifas[$precio->codtarifa]['precio'] = $precio->precio;
            if ($es_porcentaje) {
                // Per-lista value wins, then the global mode fallback (AD-1).
                $this->precios_tarifas[$precio->codtarifa]['porcentaje'] = $precio->porcentaje !== null
                    ? $precio->porcentaje
                    : ($this->opcional->porcentaje ?? null);
            }
        }
    }

    /**
     * Whether the opcional prices as a percentage. Guards the legacy test
     * doubles that only expose `get_precios_tarifas()`.
     */
    private function opcional_es_porcentaje(): bool
    {
        return $this->opcional
            && method_exists($this->opcional, 'es_precio_porcentaje')
            && $this->opcional->es_precio_porcentaje();
    }

    /**
     * Guarda los precios del opcional en las tarifas.
     * Ahora también maneja la activación/desactivación y el catálogo por tarifa.
     *
     * Legacy bulk matrix path: POST + a valid CSRF token are mandatory, and the
     * whole matrix is persisted inside a single transaction. Any failed master
     * setter or price save/delete aborts the change instead of leaving a
     * partially written matrix; success is reported only after the commit.
     */
    private function guardar_precios_tarifas()
    {
        if (!$this->opcional) {
            return;
        }

        $request = $this->getRequest();
        if (strtoupper($request->getMethod()) !== 'POST' || !$this->requireCsrf()) {
            $this->new_error_msg('Token de seguridad inválido o método no permitido.');
            return;
        }

        $precio_model = new tarif_opcional_precio();
        $master = $this->opcional_master_state();
        $opcional = $this->opcional;
        $tarifas = $this->tarifas;
        $es_porcentaje = $this->opcional_es_porcentaje();

        // Validate every normalized price before opening the transaction so an
        // invalid value is reported explicitly instead of being silently
        // truncated or aborting the matrix midway.
        $precios_validados = [];
        foreach ($tarifas as $tarifa) {
            $activo_key = 'activo_tarifa_' . $tarifa->codtarifa;
            if (!isset($_POST[$activo_key]) || !$_POST[$activo_key]) {
                continue;
            }

            $precio_key = 'precio_tarifa_' . $tarifa->codtarifa;
            $precio_str = isset($_POST[$precio_key]) ? trim($_POST[$precio_key]) : '0';
            $precio_valor = $this->parse_price_input($precio_str);
            if ($precio_valor === null) {
                $this->new_error_msg('Precio no válido en la tarifa ' . $tarifa->codtarifa . '. Introduce un número, por ejemplo 12,50.');
                return;
            }
            $precios_validados[$tarifa->codtarifa] = $precio_valor;
        }

        $guardados = 0;
        $eliminados = 0;

        $ok = $this->run_in_transaction(function () use ($precio_model, $master, $opcional, $tarifas, $precios_validados, $es_porcentaje, &$guardados, &$eliminados) {
            foreach ($tarifas as $tarifa) {
                $activo_key = 'activo_tarifa_' . $tarifa->codtarifa;
                $catalogo_key = 'catalogo_tarifa_' . $tarifa->codtarifa;
                $en_tarifa_key = 'en_tarifa_tarifa_' . $tarifa->codtarifa;

                $activo = isset($_POST[$activo_key]) && $_POST[$activo_key];
                $en_catalogo = isset($_POST[$catalogo_key]) && $_POST[$catalogo_key];
                $en_tarifa = isset($_POST[$en_tarifa_key]) && $_POST[$en_tarifa_key];

                // The master is the authoritative per-tarifa state (design AD2/AD3).
                if (!$master->set_activa($tarifa->codtarifa, $opcional->id, $activo)) {
                    return false;
                }
                if (!$master->set_en_catalogo($tarifa->codtarifa, $opcional->id, $en_catalogo)) {
                    return false;
                }
                if (!$master->set_en_tarifa($tarifa->codtarifa, $opcional->id, $en_tarifa)) {
                    return false;
                }

                // Si está marcado como activo en esta tarifa
                if ($activo) {
                    // Precio validated above with parse_price_input(); the
                    // integral fallback applies only when none was submitted.
                    $precio_valor = $precios_validados[$tarifa->codtarifa] ?? 0.0;

                    // Buscar o crear registro
                    $precio = $precio_model->get($opcional->id, $tarifa->codtarifa);
                    if (!$precio) {
                        $precio = new tarif_opcional_precio();
                        $precio->id_opcional = $opcional->id;
                        $precio->codtarifa = $tarifa->codtarifa;
                    }
                    if ($es_porcentaje) {
                        // Percentage mode: the legacy value carries the
                        // percentage, so store it and zero the price (OPG-01).
                        $precio->porcentaje = $precio_valor;
                        $precio->precio = 0.0;
                    } else {
                        // Fixed mode: clear any stale percentage (OPG-01).
                        $precio->precio = $precio_valor;
                        $precio->limpiar_porcentaje();
                    }
                    $precio->en_catalogo = $en_catalogo;

                    if (!$precio->save()) {
                        return false;
                    }
                    $guardados++;
                } else {
                    // Si no está activo, eliminar el precio de esta tarifa.
                    // Contar solo cuando existía una fila que se borró realmente.
                    $precio_existente = $precio_model->get($opcional->id, $tarifa->codtarifa);
                    if ($precio_existente) {
                        if (!$opcional->delete_precio_tarifa($tarifa->codtarifa)) {
                            return false;
                        }
                        $eliminados++;
                    }
                }
            }

            // Actualizar los campos en_tarifa y en_catalogo basados en la configuración
            $precios = $opcional->get_precios_tarifas();
            $opcional->en_tarifa = (count($precios) > 0);
            $opcional->en_catalogo = false;
            foreach ($precios as $p) {
                if ($p->en_catalogo) {
                    $opcional->en_catalogo = true;
                    break;
                }
            }

            return (bool) $opcional->save();
        });

        if (!$ok) {
            $this->new_error_msg('Error al guardar las tarifas. No se aplicó ningún cambio.');
            return;
        }

        $this->new_message("Tarifas actualizadas: $guardados activas, $eliminados desactivadas.");
    }

    /**
     * Saves the master flags and the price of this opcional for exactly one
     * tarifa, as submitted by a per-tarifa matrix row. POST + valid CSRF only.
     */
    private function guardar_precio_tarifa()
    {
        if (!$this->opcional) {
            return;
        }

        $request = $this->getRequest();
        if (strtoupper($request->getMethod()) !== 'POST' || !$this->requireCsrf()) {
            $this->new_error_msg('Token de seguridad inválido o método no permitido.');
            return;
        }

        $codtarifa = isset($_POST['codtarifa']) ? trim((string) $_POST['codtarifa']) : '';
        if ($codtarifa === '') {
            $this->new_error_msg('Tarifa no indicada.');
            return;
        }

        $activa = isset($_POST['activa']);
        $en_catalogo = isset($_POST['en_catalogo']);
        $en_tarifa = isset($_POST['en_tarifa']);
        $es_porcentaje = $this->opcional_es_porcentaje();

        // Validate the whole normalized value before writing anything, so an
        // invalid value is rejected instead of being silently truncated. The
        // accepted input follows the opcional's price mode (OPG-01).
        $precio_valor = 0.0;
        $porcentaje_valor = 0.0;
        if ($activa) {
            if ($es_porcentaje) {
                $porcentaje_valor = $this->parse_price_input(isset($_POST['porcentaje']) ? $_POST['porcentaje'] : '0');
                if ($porcentaje_valor === null || $porcentaje_valor < 0) {
                    $this->new_error_msg('Porcentaje no válido. Introduce un número, por ejemplo 12,50.');
                    return;
                }
            } else {
                $precio_valor = $this->parse_price_input(isset($_POST['precio']) ? $_POST['precio'] : '0');
                if ($precio_valor === null) {
                    $this->new_error_msg('Precio no válido. Introduce un número, por ejemplo 12,50.');
                    return;
                }
            }
        }

        $master = $this->opcional_master_state();
        $opcional = $this->opcional;

        // The three master flags and the price row are one transaction: every
        // write result is checked and the whole change rolls back on failure.
        $ok = $this->run_in_transaction(function () use ($master, $opcional, $codtarifa, $activa, $en_catalogo, $en_tarifa, $precio_valor, $porcentaje_valor, $es_porcentaje) {
            if (!$master->set_activa($codtarifa, $opcional->id, $activa)) {
                return false;
            }
            if (!$master->set_en_catalogo($codtarifa, $opcional->id, $en_catalogo)) {
                return false;
            }
            if (!$master->set_en_tarifa($codtarifa, $opcional->id, $en_tarifa)) {
                return false;
            }

            if ($activa) {
                $precio_model = new tarif_opcional_precio();
                $precio = $precio_model->get($opcional->id, $codtarifa);
                if (!$precio) {
                    $precio = new tarif_opcional_precio();
                    $precio->id_opcional = $opcional->id;
                    $precio->codtarifa = $codtarifa;
                }

                if ($es_porcentaje) {
                    // Percentage mode: store the percentage and zero the price.
                    $precio->porcentaje = (float) $porcentaje_valor;
                    $precio->precio = 0.0;
                } else {
                    // Fixed mode: store the price and clear any stale percentage.
                    $precio->precio = (float) $precio_valor;
                    $precio->limpiar_porcentaje();
                }
                $precio->en_catalogo = $en_catalogo;

                return (bool) $precio->save();
            }

            return (bool) $opcional->delete_precio_tarifa($codtarifa);
        });

        if (!$ok) {
            $this->new_error_msg('Error al guardar la tarifa. No se aplicó ningún cambio.');
            return;
        }

        $this->new_message('Tarifa actualizada correctamente.');
        $this->load_precios_tarifas();
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
     * Effective percentage of the opcional in a tarifa (percentage mode only),
     * or null in fixed mode / when unset.
     *
     * @param string $codtarifa
     * @return float|null
     */
    public function get_porcentaje_tarifa($codtarifa)
    {
        if (isset($this->precios_tarifas[$codtarifa])) {
            return $this->precios_tarifas[$codtarifa]['porcentaje'] ?? null;
        }
        return null;
    }
    
    /**
     * Verifica si el opcional está activo en una tarifa específica.
     * Per-tarifa master `activa`; price-row existence never activates (AD2).
     * @param string $codtarifa
     * @return bool
     */
    public function opcional_activo_en_tarifa($codtarifa)
    {
        if (isset($this->precios_tarifas[$codtarifa])) {
            return (bool) $this->precios_tarifas[$codtarifa]['activa'];
        }
        return false;
    }
    
    /**
     * Verifica si el opcional está en el catálogo de una tarifa específica.
     * Per-tarifa master `en_catalogo` (AD3).
     * @param string $codtarifa
     * @return bool
     */
    public function opcional_en_catalogo_tarifa($codtarifa)
    {
        if (isset($this->precios_tarifas[$codtarifa])) {
            return (bool) $this->precios_tarifas[$codtarifa]['en_catalogo'];
        }
        return false;
    }

    /**
     * Verifica si el opcional se incluye al exportar una tarifa específica.
     * Per-tarifa master `en_tarifa` (AD3).
     * @param string $codtarifa
     * @return bool
     */
    public function opcional_en_tarifa_flag($codtarifa)
    {
        if (isset($this->precios_tarifas[$codtarifa])) {
            return (bool) $this->precios_tarifas[$codtarifa]['en_tarifa'];
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
