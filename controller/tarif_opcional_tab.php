<?php
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
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
require_once 'plugins/catalogo_core/model/tarif_opcional.php';
require_once 'plugins/catalogo_core/model/tarif_opcional_precio.php';

use FSFramework\Core\Html;
use FSFramework\model\tarif_opcional;
use FSFramework\model\tarif_opcional_precio;

/**
 * AJAX endpoint for the Tarifas tab injected into catalogo_core's
 * ventas_opcional page (OUM-10, AD-7).
 *
 * Owned by catalogo_core after WU-4: it serves the per-tarifa price rows
 * fragment of a saved opcional and saves single price edits without leaving
 * the host page. Deliberately a legacy controller extending fbase_controller
 * (not a PSR-4 PageController): rows must render currency symbols through
 * simbolo_divisa(), which only exists on fs_controller.
 *
 * Actions:
 *  - GET  action=rows&ref=<id_opcional> echoes the server-rendered rows
 *         fragment (template = FALSE, like tarif_tab_precios).
 *  - POST guardar_precio_tab — CSRF-validated by legacy pre_private_core and
 *    re-checked with requireCsrf(); enforces puede_editar_opcional()
 *    (admin/gestor; no per-opcional assignment model exists); answers JSON
 *    {ok, message, html} with the re-rendered rows.
 */
class tarif_opcional_tab extends fbase_controller
{
    use TarifarioOpcionalStateTrait;

    /** Referencia (id del opcional) servida en la acción rows. */
    protected string $rows_ref = '';

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Precios Opcional (tab)', 'tarifario', FALSE, FALSE);
    }

    protected function private_core()
    {
        parent::private_core();
        $this->init_tarifario_opcional_state();

        if (isset($_REQUEST['guardar_precio_tab'])) {
            $this->guardar_precio_tab();
        } else if (isset($_REQUEST['action']) && $_REQUEST['action'] === 'rows') {
            $this->render_rows_action();
        }
    }

    /**
     * Fábrica del modelo de precios de opcionales (costura de test).
     */
    protected function opcional_precio_model(): tarif_opcional_precio
    {
        return new tarif_opcional_precio();
    }

    /**
     * Opcional-model factory (test seam) so the tab can resolve the price mode
     * (fijo/porcentaje) of the referenced opcional.
     */
    protected function opcional_model(): tarif_opcional
    {
        return new tarif_opcional();
    }

    /**
     * GET action=rows — vuelca el fragmento de filas por tarifa.
     */
    protected function render_rows_action(): void
    {
        $this->template = FALSE;
        $this->rows_ref = isset($_REQUEST['ref']) ? $this->no_html(trim($_REQUEST['ref'])) : '';

        header('Content-Type: text/html; charset=UTF-8');
        echo $this->render_rows_fragment();
    }

    /**
     * Fragmento de filas para la superficie opcional (precios por tarifa de un
     * opcional guardado; sin id no hay nada que tarificar).
     */
    protected function render_rows_fragment(): string
    {
        $id_opcional = intval($this->rows_ref);

        $opcional = $id_opcional > 0 ? $this->opcional_model()->get($id_opcional) : false;
        $es_porcentaje = $opcional && $opcional->es_precio_porcentaje();

        $precios = [];
        $porcentajes = [];
        foreach ($this->tarifas as $tarifa) {
            $row = $this->opcional_precio_model()->get($id_opcional, $tarifa->codtarifa);
            $precios[$tarifa->codtarifa] = $row;

            $porcentajes[$tarifa->codtarifa] = null;
            if ($es_porcentaje) {
                if ($row && isset($row->porcentaje)) {
                    $porcentajes[$tarifa->codtarifa] = $row->porcentaje;
                } elseif (isset($opcional->porcentaje)) {
                    $porcentajes[$tarifa->codtarifa] = $opcional->porcentaje;
                }
            }
        }

        return Html::render('@catalogo_core/Hooks/partials/opcional_precios_rows.html.twig', [
            'fsc' => $this,
            'id_opcional' => $id_opcional,
            'tarifas' => $this->tarifas,
            'precios' => $precios,
            'es_porcentaje' => $es_porcentaje,
            'porcentajes' => $porcentajes,
        ]);
    }

    /**
     * POST guardar_precio_tab — guarda el precio de una fila del tab.
     */
    private function guardar_precio_tab(): void
    {
        $this->template = FALSE;

        if (!$this->requireCsrf()) {
            $this->respond_tab_json(false, 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $codtarifa = isset($_POST['codtarifa']) ? $this->no_html(trim($_POST['codtarifa'])) : '';
        $this->codtarifa = $codtarifa;

        $id_opcional = isset($_POST['referencia']) ? intval($_POST['referencia']) : 0;
        // El fragmento de respuesta se re-renderiza leyendo $this->rows_ref;
        // sin esto la respuesta pinta filas en blanco y un segundo guardado
        // sobre la fila en blanco borra el precio recién guardado.
        $this->rows_ref = (string) $id_opcional;

        if ($id_opcional < 1 || !$this->puede_editar_opcional($codtarifa)) {
            $this->respond_tab_json(false, 'No tienes permiso para editar los precios de este opcional en la tarifa seleccionada.');
            return;
        }

        $precio_model = $this->opcional_precio_model();
        $p = $precio_model->get($id_opcional, $codtarifa);

        $modo_porcentaje = !empty($_POST['modo_porcentaje']);
        $en_catalogo = isset($_POST['en_catalogo']);

        if ($modo_porcentaje) {
            $porcentaje_str = trim((string) ($_POST['porcentaje'] ?? ''));
            if ($porcentaje_str === '') {
                if ($p) {
                    $p->delete();
                }
                $message = 'Porcentaje eliminado correctamente.';
            } else {
                $porcentaje = $this->parse_price_input($porcentaje_str);
                if ($porcentaje === null || $porcentaje < 0) {
                    $this->respond_tab_json(false, 'Porcentaje no válido.');
                    return;
                }
                if (!$p) {
                    $p = $this->opcional_precio_model();
                    $p->id_opcional = $id_opcional;
                    $p->codtarifa = $codtarifa;
                }
                $p->porcentaje = $porcentaje;
                $p->precio = 0.0;
                $p->en_catalogo = $en_catalogo;
                if (!$p->save()) {
                    $this->respond_tab_json(false, 'No se pudo guardar el porcentaje.');
                    return;
                }
                $message = 'Porcentaje guardado correctamente.';
            }
        } else {
            $precio_str = trim((string) ($_POST['precio'] ?? ''));
            if ($precio_str === '') {
                if ($p) {
                    $p->delete();
                }
                $message = 'Precio eliminado correctamente.';
            } else {
                if (!$p) {
                    $p = $this->opcional_precio_model();
                    $p->id_opcional = $id_opcional;
                    $p->codtarifa = $codtarifa;
                }
                $p->precio = floatval(str_replace(',', '.', $precio_str));
                $p->en_catalogo = $en_catalogo;
                // Switching back to fixed price clears a stale percentage.
                $p->limpiar_porcentaje();
                if (!$p->save()) {
                    $this->respond_tab_json(false, 'No se pudo guardar el precio.');
                    return;
                }
                $message = 'Precio guardado correctamente.';
            }
        }

        $this->respond_tab_json(true, $message, $this->render_rows_fragment());
    }

    /**
     * Verifica si el usuario actual puede editar precios de opcionales en la
     * tarifa indicada: administrador, o gestor de esa tarifa.
     *
     * The gestor role table belongs to the tarifario plugin, which is optional
     * for this endpoint; resolution is soft (class_exists) and fails closed to
     * admin-only when tarifario is inactive. No cross-plugin require keeps
     * catalogo_core self-contained (OUM-10).
     */
    protected function puede_editar_opcional(string $codtarifa): bool
    {
        if (empty($this->user->nick)) {
            return FALSE;
        }
        if (!empty($this->user->admin)) {
            return TRUE;
        }

        $roleClass = 'FSFramework\\model\\tarif_grupo_usuario';
        if (!class_exists($roleClass)) {
            return FALSE;
        }

        $grupo = new $roleClass();
        return $grupo->get_rol_en_tarifa($codtarifa, $this->user->nick) === 'gestor';
    }

    /**
     * Responde JSON {ok, message, html} con el fragmento re-renderizado.
     */
    private function respond_tab_json(bool $ok, string $message, string $html = ''): void
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array('ok' => $ok, 'message' => $message, 'html' => $html), JSON_UNESCAPED_UNICODE);
    }
}
