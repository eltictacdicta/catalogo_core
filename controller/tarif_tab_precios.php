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
require_once 'plugins/catalogo_core/model/tarif_articulo_precio.php';
require_once 'plugins/catalogo_core/Services/CaracteristicaResolver.php';
require_once 'plugins/catalogo_core/Services/CaracteristicaValorStore.php';

use FSFramework\Core\Html;
use FSFramework\Event\FSEventDispatcher;
use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorStore;
use FSFramework\model\tarif_articulo_precio;

/**
 * AJAX endpoint for the article Tarifas tab injected into catalogo_core's
 * ventas_articulo page via render_hook (WU-1, moved from tarifario).
 *
 * Owned by catalogo_core after WU-1: it serves the per-tarifa price rows
 * fragment of a saved article and saves single price edits without leaving the
 * host page. Deliberately a legacy controller extending fbase_controller (not a
 * PSR-4 PageController): rows must render currency symbols through
 * simbolo_divisa(), which only exists on fs_controller. The slug and class
 * basename stay `tarif_tab_precios` so the frozen host markup never changes
 * (AD-2).
 *
 * Actions:
 *  - GET  action=rows&tipo=articulo&ref=... echoes the server-rendered rows
 *         fragment (template = FALSE, like tarif_opcional_tab).
 *  - POST guardar_precio_tab — CSRF-validated by legacy pre_private_core and
 *    re-checked with requireCsrf(); enforces puede_editar_articulo() through
 *    catalogo_core's neutral ArticlePermissionFilterEvent (default-allow with
 *    zero listeners, AD-3); reuses tarif_articulo_precio get()+save();
 *    answers JSON {ok, message, html} with the re-rendered rows.
 */
class tarif_tab_precios extends fbase_controller
{
    use TarifarioOpcionalStateTrait;

    /** Referencia del artículo servido en la acción rows. */
    protected string $rows_ref = '';

    /**
     * Optional per-tarifa read scope for the rows fragment (AD-3). Empty means
     * the legacy all-tarifas output; a non-empty code narrows the fragment to
     * that single active tariff. Set from the `codtarifa` request parameter on
     * the rows action and from the saved row's tariff on a price save. Read
     * only — the endpoint's write behavior is unchanged.
     */
    protected string $rows_scope = '';

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Precios Tarifas (tab)', 'tarifario', FALSE, FALSE);
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
     * Fábrica del modelo de precios (costura de test: permite espiar get/save).
     */
    protected function precio_model(): tarif_articulo_precio
    {
        return new tarif_articulo_precio();
    }

    /**
     * D12 feature-value seams (ATT-02/ATT-04). The tab's visibility controls
     * persist articulo-scope feature values through the store and read the
     * effective value through the resolver — never the legacy per-tarifa
     * visibility columns.
     *
     * @return CaracteristicaResolver
     */
    protected function caracteristica_resolver()
    {
        return new CaracteristicaResolver();
    }

    /**
     * @return CaracteristicaValorStore
     */
    protected function caracteristica_store()
    {
        return new CaracteristicaValorStore();
    }

    /**
     * GET action=rows — vuelca el fragmento de filas por tarifa.
     */
    protected function render_rows_action()
    {
        $this->template = FALSE;
        $this->rows_ref = isset($_REQUEST['ref']) ? $this->no_html(trim($_REQUEST['ref'])) : '';
        $this->rows_scope = isset($_REQUEST['codtarifa'])
            ? $this->no_html(trim((string) $_REQUEST['codtarifa']))
            : '';

        header('Content-Type: text/html; charset=UTF-8');
        echo $this->render_rows_fragment();
    }

    /**
     * Renderiza el fragmento de filas con la divisa de cada tarifa (S27) y la
     * visibilidad derivada del producto (ATT-02, design D12).
     */
    protected function render_rows_fragment(): string
    {
        $precios = [];
        $visibilidad = [];
        $resolver = $this->caracteristica_resolver();

        // AD-3: additive read scope. With no scope the whole active set is
        // rendered, exactly as before (legacy output stays byte-for-byte).
        $tarifas = $this->tarifas;
        if ($this->rows_scope !== '') {
            $tarifas = array_values(array_filter(
                $tarifas,
                fn ($tarifa): bool => (string) $tarifa->codtarifa === $this->rows_scope
            ));
        }

        foreach ($tarifas as $tarifa) {
            $precios[$tarifa->codtarifa] = $this->precio_model()->get($this->rows_ref, $tarifa->codtarifa);
            $visibilidad[$tarifa->codtarifa] = [
                'en_tarifa' => (bool) $resolver->resolve_bool('en_tarifa', (string) $tarifa->codtarifa, $this->rows_ref, null),
                'en_catalogo' => (bool) $resolver->resolve_bool('en_catalogo', (string) $tarifa->codtarifa, $this->rows_ref, null),
            ];
        }

        return Html::render('@catalogo_core/Hooks/partials/articulo_precios_rows.html.twig', [
            'fsc' => $this,
            'referencia' => $this->rows_ref,
            'tarifas' => $tarifas,
            'precios' => $precios,
            'visibilidad' => $visibilidad,
        ]);
    }

    /**
     * POST guardar_precio_tab — guarda el precio de una fila del tab.
     */
    private function guardar_precio_tab()
    {
        $this->template = FALSE;

        if (!$this->requireCsrf()) {
            $this->respond_tab_json(false, 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $codtarifa = isset($_POST['codtarifa']) ? $this->no_html(trim($_POST['codtarifa'])) : '';
        $this->codtarifa = $codtarifa;

        $this->guardar_precio_articulo($codtarifa);
    }

    /**
     * Guarda (o elimina) el precio del artículo en la tarifa de la fila.
     */
    private function guardar_precio_articulo(string $codtarifa)
    {
        $referencia = isset($_POST['referencia']) ? $this->no_html(trim($_POST['referencia'])) : '';
        // F2: el fragmento de respuesta se re-renderiza leyendo $this->rows_ref;
        // sin esto la respuesta pinta filas en blanco y un segundo guardado
        // sobre la fila en blanco borra el precio recién guardado.
        $this->rows_ref = $referencia;
        // AD-3: la respuesta se acota a la tarifa de la fila guardada, de modo
        // que solo se re-renderiza esa fila.
        $this->rows_scope = $codtarifa;
        if ($referencia === '' || !$this->puede_editar_articulo($referencia, $codtarifa)) {
            $this->respond_tab_json(false, 'No tienes permiso para editar los precios de este artículo en la tarifa seleccionada.');
            return;
        }

        $precio_model = $this->precio_model();
        $p = $precio_model->get($referencia, $codtarifa);

        $precio_str = trim((string) ($_POST['precio'] ?? ''));
        if ($precio_str === '') {
            if ($p) {
                $p->delete();
            }
            $message = 'Precio eliminado correctamente.';
        } else {
            if (!$p) {
                $p = new tarif_articulo_precio();
                $p->referencia = $referencia;
                $p->codtarifa = $codtarifa;
            }
            $p->precio = floatval(str_replace(',', '.', $precio_str));
            $p->activo = isset($_POST['activo']);
            if (!$p->save()) {
                $this->respond_tab_json(false, 'No se pudo guardar el precio.');
                return;
            }
            $message = 'Precio guardado correctamente.';
        }

        // ATT-02: the tab's visibility controls persist articulo-scope feature
        // values (never the legacy per-tarifa visibility columns). A blanked
        // price deletes the price row and leaves the feature values intact.
        $this->persist_articulo_visibility($referencia, $codtarifa);

        $this->respond_tab_json(true, $message, $this->render_rows_fragment());
    }

    /**
     * Materializes the posted `en_tarifa`/`en_catalogo` controls as
     * articulo-scope feature values for the row's tarifa.
     *
     * Best-effort by design: the `en_tarifa`/`en_catalogo` definitions are
     * registered by the tarifario plugin (`caracteristicas-producto` WU-6), so a
     * catalogo_core-only install has no definition to write and the price edit
     * must still succeed. The legacy per-tarifa columns are never touched.
     */
    protected function persist_articulo_visibility(string $referencia, string $codtarifa): void
    {
        if ($referencia === '' || $codtarifa === '') {
            return;
        }

        $store = $this->caracteristica_store();
        foreach (['en_tarifa', 'en_catalogo'] as $codigo) {
            $store->assign_bool(
                CaracteristicaResolver::SCOPE_ARTICULO,
                $codtarifa,
                ['referencia' => $referencia],
                $codigo,
                isset($_POST[$codigo])
            );
        }
    }

    /**
     * Verifica si el usuario actual puede editar los precios de este artículo
     * en la tarifa indicada (AD-3, ATT-07).
     *
     * catalogo_core stays neutral: the admin short-circuits, otherwise the
     * neutral ArticlePermissionFilterEvent is dispatched and its verdict
     * returned. With zero listeners the event resolves allow (default-allow);
     * tarifario's ArticlePermissionListener enforces RBAC transitively when
     * the plugin is active.
     */
    protected function puede_editar_articulo(string $referencia, string $codtarifa): bool
    {
        if (!empty($this->user->admin)) {
            return TRUE;
        }

        $event = new ArticlePermissionFilterEvent(
            $referencia,
            ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            (string) ($this->user->nick ?? ''),
            $codtarifa
        );
        FSEventDispatcher::getInstance()->dispatch($event, ArticlePermissionFilterEvent::NAME);

        return $event->isAllowed();
    }

    /**
     * Responde JSON {ok, message, html} con el fragmento re-renderizado.
     */
    private function respond_tab_json(bool $ok, string $message, string $html = '')
    {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array('ok' => $ok, 'message' => $message, 'html' => $html), JSON_UNESCAPED_UNICODE);
    }
}
