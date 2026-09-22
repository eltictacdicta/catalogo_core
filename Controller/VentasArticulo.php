<?php
declare(strict_types=1);
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2025 Javier Trujillo <mistertekcom@gmail.com>
 * Copyright (C) 2014-2017 Carlos Garcia Gomez <neorazorx@gmail.com>
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

namespace FSFramework\Plugins\catalogo_core\Controller;

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/fabricante.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/impuesto.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional_grupo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_familia.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa_articulo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa_familia.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa_etiqueta_familia.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa_articulo_etiqueta.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_articulo_imagen.php';
require_once FS_FOLDER . '/plugins/catalogo_core/extras/CaracteristicaHookContextTrait.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaResolver.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaValorStore.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use FSFramework\Core\Html;
use FSFramework\Event\FSEventDispatcher;
use FSFramework\model\catalogo_articulo_opcional;
use FSFramework\model\catalogo_articulo_opcional_grupo;
use FSFramework\model\catalogo_idioma;
use FSFramework\model\catalogo_lista_precio;
use FSFramework\model\catalogo_opcional;
use FSFramework\model\catalogo_opcional_grupo;
use FSFramework\model\tarif_articulo_imagen;
use FSFramework\model\tarif_tarifa;
use FSFramework\model\tarif_tarifa_articulo;
use FSFramework\model\tarif_tarifa_articulo_etiqueta;
use FSFramework\model\tarif_tarifa_etiqueta_familia;
use FSFramework\model\tarif_tarifa_familia;
use FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorStore;
use FSFramework\Translation\FSTranslator;
use Symfony\Component\HttpFoundation\Request;

class VentasArticulo extends PageController
{
    use \CaracteristicaHookContextTrait;

    public ?\articulo $articulo = null;
    public array $familias = [];
    public array $fabricantes = [];
    public array $impuestos = [];
    public array $idiomas = [];

    /**
     * Language selected in the editor's multi-language selector (GDI-11). It
     * drives the single description / short-description pair the view renders
     * and the single slot `saveMultiidiomaDescriptions()` writes.
     */
    public string $codidioma = '';
    public array $articulo_opcionales = [];
    public array $articulo_grupos = [];
    public array $opcionales_disponibles = [];
    public array $grupos_disponibles = [];
    public string $lista_precio_defecto = catalogo_lista_precio::DEFAULT_CODE;
    public bool $allow_delete = false;

    // WU-2 absorbed state (ported 1:1 from tarifario's tarif_articulo_edit).
    public string $codtarifa = '';

    /**
     * Tarifa resolved for this render (AD-4); null when no active tarifa
     * exists. The view reads `$tarifa_seleccionada.codtarifa` to mark the
     * selector and to scope the per-tarifa pane (ART-09/ART-11).
     *
     * @var tarif_tarifa|null
     */
    public ?tarif_tarifa $tarifa_seleccionada = null;

    public array $tarifas = [];
    public array $imagenes = [];
    public array $articulo_etiquetas_disponibles = [];
    public array $articulo_etiquetas_seleccionadas = [];
    public bool $puede_editar = false;

    /** TRUE once an htmx fragment response was echoed; run() then skips the page. */
    protected bool $htmxHandled = false;

    public function __construct()
    {
        parent::__construct('VentasArticulo');
        $this->setTemplate('ventas_articulo');
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->loadExtensions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'ventas_articulo',
            'title' => 'Artículo',
            'menu' => 'catalogo',
            'showonmenu' => false,
            'ordernum' => 125,
        ];
    }

    public function url(): string
    {
        if ($this->articulo !== null && $this->articulo->referencia !== null && $this->articulo->referencia !== '') {
            return $this->articulo->url();
        }

        return parent::url();
    }

    public function run(): void
    {
        $pageData = $this->getPageData();
        $this->title = $pageData['title'] ?? $this->className;

        $this->privateCore($this->response, $this->user, $this->permissions);

        if ($this->htmxHandled) {
            return;
        }

        if ($this->isOpcionalesPartialRequest()) {
            if ($this->shouldRenderOpcionalesPartial()) {
                $this->renderOpcionalesPartial();
            } else {
                $this->sendAjaxJson([
                    'ok' => false,
                    'message' => 'Artículo no encontrado.',
                ], 404);
            }

            return;
        }

        if ($this->getTemplate() !== false) {
            $templateName = $this->getTemplate() ?? $this->className;
            echo Html::render($templateName, [
                'fsc' => $this,
                'user' => $this->user,
                'empresa' => $this->empresa,
                'i18n' => new FSTranslator(),
            ]);
        }
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $this->articulo = $this->articulo_model();

        $ref = $this->resolveArticuloReferenciaFromRequest();
        if ($ref !== null) {
            $art = $this->articulo->get($ref);
            if ($art) {
                $this->articulo = $art;
            } else {
                $this->new_error_msg('Artículo no encontrado.');
                return;
            }
        }

        $this->loadFilterOptions();
        $this->loadTarifarioState();
        $this->loadCatalogData();

        if ($this->request->request->has('add_opcional_articulo')) {
            $this->addOpcionalArticulo($this->request);
        } elseif ($this->request->request->has('remove_opcional_articulo')) {
            $this->removeOpcionalArticulo($this->request);
        } elseif ($this->request->request->has('add_grupo_articulo')) {
            $this->addGrupoArticulo($this->request);
        } elseif ($this->request->request->has('remove_grupo_articulo')) {
            $this->removeGrupoArticulo($this->request);
        } elseif ($this->request->request->has('toggle_obligatorio_grupo')) {
            $this->toggleObligatorioGrupo($this->request);
        } elseif ($this->request->request->has('toggle_obligatorio_opcional')) {
            $this->toggleObligatorioOpcional($this->request);
        } elseif ($this->request->request->has('upload_imagen')) {
            $this->uploadImagen($this->articulo);
        } elseif ($this->request->request->has('delete_imagen')) {
            $this->deleteImagen($this->articulo);
        } elseif ($this->request->request->has('destacar_imagen')) {
            $this->destacarImagen($this->articulo);
        } elseif ($this->request->request->has('eliminar_articulo')) {
            $this->eliminarArticulo($this->request);
        } elseif ($this->request->request->has('sreferencia')) {
            $this->editarArticulo($this->request);
        }

        $this->loadCatalogData();
    }

    /**
     * Loads the tarifario state absorbed from tarif_articulo_edit: active
     * tarifas, the selected codtarifa, the loaded article's images and etiqueta
     * lists, and the neutral permission verdict for the edit surface.
     */
    protected function loadTarifarioState(): void
    {
        $tarifa = $this->tarifa_model();
        $this->tarifas = $tarifa->all_activas();
        $this->codtarifa = $this->resolveCodtarifa();
        $this->resolveTarifaSeleccionada();

        $this->puede_editar = false;
        $this->imagenes = [];
        $this->articulo_etiquetas_disponibles = [];
        $this->articulo_etiquetas_seleccionadas = [];

        if ($this->articulo === null || $this->articulo->referencia === null || $this->articulo->referencia === '') {
            return;
        }

        $this->puede_editar = $this->puedeEditarArticulo($this->articulo->referencia, $this->codtarifa);
        $this->loadImagenes($this->articulo);
        $this->articulo_etiquetas_disponibles = $this->getEtiquetasDisponiblesArticulo();
        $this->articulo_etiquetas_seleccionadas = $this->getEtiquetasSeleccionadasArticulo();
    }

    /**
     * Resolves the active codtarifa for the detail (AD-4): the supplied code is
     * kept only when it is part of the active set, otherwise the default tariff
     * when it is active, otherwise the first active tariff, otherwise ''.
     *
     * Ported 1:1 from tarif_opcional_edit::resolver_tarifa_seleccionada(): a
     * stale or unknown code can never scope the pane to a nonexistent tarifa.
     */
    protected function resolveCodtarifa(): string
    {
        $supplied = $this->suppliedCodtarifa();
        if ($supplied !== '' && $this->findActiveTarifa($supplied) !== null) {
            return $supplied;
        }

        $defecto = $this->tarifa_model()->get_default();
        if ($defecto && $this->findActiveTarifa((string) $defecto->codtarifa) !== null) {
            return (string) $defecto->codtarifa;
        }

        if (count($this->tarifas) > 0) {
            return (string) $this->tarifas[0]->codtarifa;
        }

        return '';
    }

    /**
     * Raw codtarifa supplied by the request (query first, then POST body).
     */
    protected function suppliedCodtarifa(): string
    {
        if ($this->request->query->has('codtarifa')) {
            return (string) $this->request->query->get('codtarifa');
        }

        if ($this->request->request->has('codtarifa')) {
            return (string) $this->request->request->get('codtarifa');
        }

        return '';
    }

    /**
     * The active tarifa matching $codtarifa, or null when it is not active.
     */
    protected function findActiveTarifa(string $codtarifa): ?tarif_tarifa
    {
        foreach ($this->tarifas as $tarifa) {
            if ((string) $tarifa->codtarifa === $codtarifa) {
                return $tarifa;
            }
        }

        return null;
    }

    /**
     * Raw codidioma supplied by the request (posted hidden field first, then the
     * selector's query parameter).
     */
    protected function suppliedCodidioma(Request $request): string
    {
        if ($request->request->has('codidioma')) {
            return (string) $request->request->get('codidioma');
        }

        if ($request->query->has('codidioma')) {
            return (string) $request->query->get('codidioma');
        }

        return '';
    }

    /**
     * Resolves the editor's selected language (GDI-11 / D-05): the supplied code
     * is kept only when it is part of the active set, otherwise the configured
     * effective default. A stale or unknown code can never select a
     * nonexistent/deactivated language, and the result is the single slot the
     * multi-language save writes.
     */
    protected function resolve_codidioma(Request $request): string
    {
        $candidate = $this->suppliedCodidioma($request);
        if ($candidate !== '') {
            foreach ($this->idiomas as $idioma) {
                if ((string) $idioma->codidioma === $candidate) {
                    return $candidate;
                }
            }
        }

        return (string) $this->idioma_model()->get_effective_default_code();
    }

    /**
     * Exposes the resolved tarifa to the view (AD-4). Runs after
     * resolveCodtarifa() normalized $codtarifa, so it only has to confirm the
     * normalized code against the active set; with no active tarifa the
     * selection stays null (ART-11).
     */
    protected function resolveTarifaSeleccionada(): void
    {
        $this->tarifa_seleccionada = $this->codtarifa !== ''
            ? $this->findActiveTarifa($this->codtarifa)
            : null;
    }

    // =====================================================================
    // WU-2 seams (protected, overridable by the DB-free test subclass).
    // =====================================================================

    protected function articulo_model(): \articulo
    {
        return new \articulo();
    }

    protected function idioma_model(): catalogo_idioma
    {
        return new catalogo_idioma();
    }

    protected function tarifa_model(): tarif_tarifa
    {
        return new tarif_tarifa();
    }

    protected function tarifa_articulo_model(): tarif_tarifa_articulo
    {
        return new tarif_tarifa_articulo();
    }

    protected function etiqueta_model(): tarif_tarifa_articulo_etiqueta
    {
        return new tarif_tarifa_articulo_etiqueta();
    }

    protected function etiqueta_familia_model(): tarif_tarifa_etiqueta_familia
    {
        return new tarif_tarifa_etiqueta_familia();
    }

    protected function imagen_model(): tarif_articulo_imagen
    {
        return new tarif_articulo_imagen();
    }

    protected function familia_model(): \familia
    {
        return new \familia();
    }

    protected function tarifa_familia_model(): tarif_tarifa_familia
    {
        return new tarif_tarifa_familia();
    }

    /**
     * Referencia of the article loaded by the detail page (hook context).
     */
    protected function caracteristicas_host_referencia(): string
    {
        return (string) ($this->articulo?->referencia ?? '');
    }

    /**
     * codfamilia of the article loaded by the detail page (hook context).
     */
    protected function caracteristicas_host_familia(): string
    {
        return (string) ($this->articulo?->codfamilia ?? '');
    }

    /**
     * Tarifa selected by the detail page (hook context).
     */
    protected function caracteristicas_tarifa_seleccionada(): string
    {
        return (string) $this->codtarifa;
    }

    /**
     * Neutral permission gate (AD-W2-2): admins short-circuit, otherwise the
     * plugin-agnostic ArticlePermissionFilterEvent resolves the verdict
     * (default-allow with zero listeners; tarifario's listener enforces RBAC
     * transitively when active).
     */
    protected function puedeEditarArticulo(string $referencia, string $codtarifa): bool
    {
        if (!empty($this->user->admin)) {
            return true;
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

    private function loadExtensions(): void
    {
        $pageName = $this->getPageData()['name'];
        $fsext = new \fs_extension();
        foreach ($fsext->all() as $ext) {
            if (!in_array($ext->to, [null, $pageName], true)) {
                continue;
            }

            if ($ext->type !== 'config' && !$this->user->have_access_to($ext->from)) {
                continue;
            }

            $this->extensions[] = $ext;
        }
    }

    private function loadFilterOptions(): void
    {
        $familia = new \familia();
        $this->familias = $familia->all();

        $fabricante = new \fabricante();
        $this->fabricantes = $fabricante->all();

        $impuesto = new \impuesto();
        $this->impuestos = $impuesto->all();
    }

    protected function editarArticulo(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $referencia = (string) $request->request->get('sreferencia', '');
        if (!$this->puedeEditarArticulo($referencia, $this->codtarifa)) {
            $this->new_error_msg('No tienes permiso para editar este artículo.');
            return;
        }

        $art = $this->articulo->get($referencia);

        if (!$art) {
            $art = $this->articulo_model();
            $art->referencia = $referencia;
        }

        // Reference rename rides a distinct field so a plain field save never
        // renames by accident (AD-W2-2). A rejected rename aborts before save.
        $nuevaReferencia = trim((string) $request->request->get('snueva_referencia', $referencia));
        if ($nuevaReferencia !== '' && $nuevaReferencia !== $art->referencia) {
            if (!$art->set_referencia($nuevaReferencia)) {
                $this->new_error_msg('No se pudo cambiar la referencia del artículo.');
                return;
            }
        }

        // AD-5 (C1): with an active tarifa selected the per-tarifa price row is
        // the truth, so the base price must not be overwritten from the form.
        // With no active tarifa (codtarifa === '', normalized by AD-4) the
        // base-price path is unchanged and pvp stays editable (ART-01/ART-11).
        if ($this->codtarifa === '') {
            $art->pvp = (float) $request->request->get('spvp', 0);
        }

        $codfamilia = $request->request->get('scodfamilia');
        $art->codfamilia = ($codfamilia !== null && $codfamilia !== '') ? (string) $codfamilia : null;

        $codfabricante = $request->request->get('scodfabricante');
        $art->codfabricante = ($codfabricante !== null && $codfabricante !== '') ? (string) $codfabricante : null;

        $codimpuesto = $request->request->get('scodimpuesto');
        $art->codimpuesto = ($codimpuesto !== null && $codimpuesto !== '') ? (string) $codimpuesto : null;

        $art->stockfis = (float) $request->request->get('sstockfis', 0);
        $art->stockmin = (float) $request->request->get('sstockmin', 0);
        $art->stockmax = (float) $request->request->get('sstockmax', 0);

        $art->bloqueado = $request->request->getBoolean('sbloqueado');
        $art->sevende = $request->request->getBoolean('ssevende', true);
        $art->secompra = $request->request->getBoolean('ssecompra', true);
        $art->publico = $request->request->getBoolean('spublico');
        $art->nostock = $request->request->getBoolean('snostock');

        $art->observaciones = (string) $request->request->get('sobservaciones', '');
        $art->codbarras = (string) $request->request->get('scodbarras', '');
        $art->equivalencia = (string) $request->request->get('sequivalencia', '');
        $art->partnumber = (string) $request->request->get('spartnumber', '');

        if ($art->save()) {
            $this->saveMultiidiomaDescriptions($art, $request);

            // A per-tarifa/etiqueta failure reports an error but never discards
            // the successful article save (ART-01/AD-W2-2).
            if (!$this->syncTarifaArticulo($art)) {
                $this->new_error_msg('Datos guardados, pero no se pudo sincronizar el artículo con la tarifa actual.');
                $this->articulo = $art;
                return;
            }

            if (!$this->saveEtiquetasArticulo($art)) {
                $this->new_error_msg('Datos guardados, pero no se pudieron guardar las etiquetas del artículo.');
                $this->articulo = $art;
                return;
            }

            $this->new_message('Artículo ' . $art->referencia . ' guardado correctamente.');
            $this->articulo = $art;
            return;
        }

        $this->new_error_msg('¡Imposible guardar el artículo!');
    }

    /**
     * Sincroniza el artículo con la tarifa activa (ported 1:1 from
     * tarif_articulo_edit::sync_tarifa_articulo_actual).
     *
     * ART-01/ART-02: the per-tarifa visibility is persisted as articulo-scope
     * feature values through the feature store, never on the legacy
     * `tarif_tarifa_articulo` visibility columns. The visibility step is
     * skipped when the request carries no visibility control at all, so a plain
     * article save never clears an existing feature value.
     */
    protected function syncTarifaArticulo(\articulo $art): bool
    {
        if (empty($this->codtarifa) || empty($art->referencia)) {
            return true;
        }

        if (!$this->ensureTarifaFamilyAvailable($art->codfamilia)) {
            return false;
        }

        $this->persist_articulo_visibility((string) $art->referencia, (string) $this->codtarifa);

        $model = $this->tarifa_articulo_model();
        $row = $model->get($this->codtarifa, $art->referencia);

        if ($row) {
            $row->codfamilia = $art->codfamilia;
            return $row->save();
        }

        return false !== $model->add_articulo_to_tarifa($this->codtarifa, $art->referencia, $art->codfamilia);
    }

    /**
     * Feature-value store seam (ART-01/ART-02 write path). Overridable so the
     * per-tarifa visibility persistence is unit-testable without a live
     * database.
     *
     * @return CaracteristicaValorStore
     */
    protected function caracteristica_store()
    {
        return new CaracteristicaValorStore();
    }

    /**
     * Persists the posted `en_tarifa`/`en_catalogo` as articulo-scope feature
     * values for the tarifa.
     *
     * Best-effort by design: the definitions are registered by the tarifario
     * plugin (`caracteristicas-producto` WU-6), so a catalogo_core-only install
     * has nothing to write and the article save must still succeed. The legacy
     * visibility columns are never touched.
     */
    protected function persist_articulo_visibility(string $referencia, string $codtarifa): void
    {
        if ($referencia === '' || $codtarifa === '') {
            return;
        }

        $request = $this->request;
        if ($request === null) {
            return;
        }

        if (!$request->request->has('en_tarifa') && !$request->request->has('en_catalogo')) {
            return;
        }

        $store = $this->caracteristica_store();
        foreach (CaracteristicaResolver::VISIBILITY_CODIGOS as $codigo) {
            $store->assign_bool(
                CaracteristicaResolver::SCOPE_ARTICULO,
                $codtarifa,
                ['referencia' => $referencia],
                $codigo,
                $request->request->has($codigo)
            );
        }
    }

    /**
     * Asegura que la familia elegida exista en la tarifa activa, creando sus
     * ancestros para mantener la jerarquía navegable (ported 1:1).
     */
    protected function ensureTarifaFamilyAvailable(?string $codfamilia): bool
    {
        if (empty($this->codtarifa) || empty($codfamilia)) {
            return true;
        }

        $tarifaFamilia = $this->tarifa_familia_model();
        if ($tarifaFamilia->get($this->codtarifa, $codfamilia)) {
            return true;
        }

        $familia = $this->familia_model()->get($codfamilia);
        if (!$familia) {
            return false;
        }

        if (!empty($familia->madre) && !$this->ensureTarifaFamilyAvailable($familia->madre)) {
            return false;
        }

        return false !== $tarifaFamilia->add_familia_to_tarifa(
            $this->codtarifa,
            $codfamilia,
            $familia->madre ?: null
        );
    }

    /**
     * Guarda las etiquetas del artículo para la tarifa activa (ported 1:1):
     * only etiquetas previously defined for the selected family may persist.
     */
    protected function saveEtiquetasArticulo(\articulo $art): bool
    {
        if (empty($this->codtarifa) || empty($art->referencia)) {
            return true;
        }

        $selected = $this->request->request->all('etiquetas');
        if (!is_array($selected)) {
            $selected = [];
        }

        if (empty($art->codfamilia)) {
            return $this->etiqueta_model()->replace_etiquetas_articulo(
                $this->codtarifa,
                $art->referencia,
                []
            );
        }

        $allowed = $this->etiqueta_familia_model()->get_etiquetas_familia($this->codtarifa, $art->codfamilia);
        $allowedIndex = [];
        foreach ((array) $allowed as $tag) {
            $allowedIndex[(string) $tag] = true;
        }

        $filtered = [];
        foreach ($selected as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '' && isset($allowedIndex[$tag])) {
                $filtered[] = $tag;
            }
        }

        return $this->etiqueta_model()->replace_etiquetas_articulo(
            $this->codtarifa,
            $art->referencia,
            $filtered
        );
    }

    /**
     * Etiquetas definidas para la familia actual del artículo en la tarifa
     * seleccionada (view API, ported 1:1).
     *
     * @return array
     */
    public function getEtiquetasDisponiblesArticulo(): array
    {
        if (empty($this->articulo) || empty($this->articulo->codfamilia) || empty($this->codtarifa)) {
            return [];
        }

        return $this->etiqueta_familia_model()->get_etiquetas_familia(
            $this->codtarifa,
            $this->articulo->codfamilia
        );
    }

    /**
     * Etiquetas actualmente asignadas al artículo en la tarifa seleccionada
     * (view API, ported 1:1).
     *
     * @return array
     */
    public function getEtiquetasSeleccionadasArticulo(): array
    {
        if (empty($this->articulo) || empty($this->articulo->referencia) || empty($this->codtarifa)) {
            return [];
        }

        return $this->etiqueta_model()->get_etiquetas_articulo(
            $this->codtarifa,
            $this->articulo->referencia
        );
    }

    // =====================================================================
    // Images entry point (ported 1:1 from tarif_articulo_edit, AD-W2-7).
    // =====================================================================

    protected function loadImagenes(\articulo $art): void
    {
        if (empty($art->referencia)) {
            $this->imagenes = [];
            return;
        }

        $this->imagenes = $this->imagen_model()->all_from_articulo($art->referencia);
    }

    protected function uploadImagen(\articulo $art): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->puedeEditarArticulo((string) $art->referencia, $this->codtarifa)) {
            $this->new_error_msg('No tienes permiso para modificar este artículo.');
            return;
        }

        if (!isset($_FILES['imagen']) || $_FILES['imagen']['error'] !== UPLOAD_ERR_OK) {
            $this->new_error_msg('Error al subir la imagen.');
            return;
        }

        $imagesPath = tarif_articulo_imagen::IMAGES_DIR;
        if (!file_exists($imagesPath) && !@mkdir($imagesPath, 0755, true)) {
            $this->new_error_msg('No se pudo crear el directorio de imágenes. Verifica los permisos del servidor.');
            return;
        }

        if (!is_writable($imagesPath)) {
            $this->new_error_msg('El directorio de imágenes no tiene permisos de escritura: ' . $imagesPath);
            return;
        }

        $imagen = tarif_articulo_imagen::upload((string) $art->referencia, $_FILES['imagen']);
        if ($imagen) {
            $this->new_message('Imagen subida correctamente.');
        } else {
            $this->new_error_msg('Error al guardar la imagen. Asegúrate de que sea un archivo de imagen válido (JPG, PNG, GIF o WEBP).');
        }

        $this->respondHtmx(true, 'Imagen procesada.');
    }

    protected function deleteImagen(\articulo $art): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar.');
            return;
        }

        if (!$this->puedeEditarArticulo((string) $art->referencia, $this->codtarifa)) {
            $this->new_error_msg('No tienes permiso para modificar este artículo.');
            return;
        }

        $id = (int) $this->request->request->get('delete_imagen', 0);
        $imagen = $this->imagen_model()->get($id);

        if ($imagen && $imagen->referencia == $art->referencia) {
            if ($imagen->delete()) {
                $this->new_message('Imagen eliminada correctamente.');
                $this->respondHtmx(true, 'Imagen eliminada correctamente.');
            } else {
                $this->new_error_msg('Error al eliminar la imagen.');
            }
            return;
        }

        $this->new_error_msg('Imagen no encontrada.');
    }

    protected function destacarImagen(\articulo $art): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->puedeEditarArticulo((string) $art->referencia, $this->codtarifa)) {
            $this->new_error_msg('No tienes permiso para modificar este artículo.');
            return;
        }

        $id = (int) $this->request->request->get('destacar_imagen', 0);
        $imagen = $this->imagen_model()->get($id);

        if ($imagen && $imagen->referencia == $art->referencia) {
            if ($imagen->set_destacada()) {
                $this->new_message('Imagen marcada como destacada.');
                $this->respondHtmx(true, 'Imagen marcada como destacada.');
            } else {
                $this->new_error_msg('Error al marcar la imagen como destacada.');
            }
            return;
        }

        $this->new_error_msg('Imagen no encontrada.');
    }

    /**
     * Persists the editor's single description / short-description pair for the
     * selected language (GDI-11 / D-05).
     *
     * Only the selected language's fields are read, so a posted value can never
     * land in a different language's slot. The empty-pair clearing decision and
     * the mirror removal live in `articulo::set_descripcion_idioma()` /
     * `articulo_descripcion::save()` (GDI-06, GDI-07); this method does not
     * reimplement them. The default-language copy of `$art->descripcion` and the
     * empty-input skip are deliberately gone (defects g and b).
     */
    private function saveMultiidiomaDescriptions(\articulo $art, Request $request): void
    {
        if ($art->referencia === null || $art->referencia === '') {
            return;
        }

        $codidioma = $this->resolve_codidioma($request);
        $descripcion = (string) $request->request->get('descripcion_' . $codidioma, '');
        $descripcionCorta = (string) $request->request->get('descripcion_corta_' . $codidioma, '');

        $art->set_descripcion_idioma(
            $codidioma,
            $descripcion,
            $descripcionCorta !== '' ? $descripcionCorta : null
        );
    }

    private function loadCatalogData(): void
    {
        $idioma = $this->idioma_model();
        $idioma->ensure_defaults();
        $this->idiomas = $idioma->all_activos();
        $this->codidioma = $this->resolve_codidioma($this->request);

        $lista = new catalogo_lista_precio();
        $lista->ensure_defaults();
        $defaultLista = $lista->get_default();
        $this->lista_precio_defecto = $defaultLista
            ? (string) $defaultLista->codlista
            : catalogo_lista_precio::DEFAULT_CODE;

        $this->articulo_opcionales = [];
        $this->articulo_grupos = [];
        $this->opcionales_disponibles = [];
        $this->grupos_disponibles = [];

        if ($this->articulo === null || $this->articulo->referencia === null || $this->articulo->referencia === '') {
            return;
        }

        $rel = new catalogo_articulo_opcional();
        $this->articulo_opcionales = $rel->get_opcionales_sueltos_from_articulo($this->articulo->referencia);

        $grupoRel = new catalogo_articulo_opcional_grupo();
        $this->articulo_grupos = $grupoRel->get_grupos_from_articulo($this->articulo->referencia);

        $this->loadOpcionalesDisponibles();
        $this->loadGruposDisponibles();
    }

    private function loadGruposDisponibles(): void
    {
        $asignados = [];
        foreach ($this->articulo_grupos as $grupo) {
            $asignados[(int) $grupo->id] = true;
        }

        $grupoModel = new catalogo_opcional_grupo();
        $this->grupos_disponibles = [];
        foreach ($grupoModel->all_activos(0, 500) as $grupo) {
            if (!isset($asignados[(int) $grupo->id])) {
                $this->grupos_disponibles[] = $grupo;
            }
        }
    }

    private function loadOpcionalesDisponibles(): void
    {
        $asignados = [];
        foreach ($this->articulo_opcionales as $opcional) {
            $asignados[(int) $opcional->id] = true;
        }

        $opcionalModel = new catalogo_opcional();
        $this->opcionales_disponibles = [];
        foreach ($opcionalModel->all_activos(0, 500) as $opcional) {
            if ($opcional->id_grupo) {
                continue;
            }
            if (!isset($asignados[(int) $opcional->id])) {
                $this->opcionales_disponibles[] = $opcional;
            }
        }
    }

    private function addOpcionalArticulo(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->respondAjax(false, 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->puedeEditarArticulo((string) $request->request->get('sreferencia', ''), $this->codtarifa)) {
            $this->respondAjax(false, 'No tienes permiso para modificar este artículo.');
            return;
        }

        $referencia = (string) $request->request->get('sreferencia', '');
        $idOpcional = $request->request->getInt('id_opcional');
        $art = $this->articulo->get($referencia);

        if (!$art) {
            $this->respondAjax(false, 'Artículo no encontrado.');
            return;
        }

        if ($idOpcional <= 0) {
            $this->respondAjax(false, 'Selecciona un opcional.');
            return;
        }

        $rel = new catalogo_articulo_opcional();
        $obligatorio = $request->request->getBoolean('obligatorio');
        if ($rel->add($referencia, $idOpcional, $obligatorio)) {
            $this->articulo = $art;
            $this->respondAjax(true, 'Opcional añadido correctamente.', ['reload_opcionales_tab' => true]);
            return;
        }

        $errors = $rel->get_errors();
        if ($errors !== []) {
            $this->respondAjax(false, (string) $errors[0]);
            return;
        }

        $this->respondAjax(false, 'No se pudo añadir el opcional.');
    }

    private function addGrupoArticulo(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->respondAjax(false, 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->puedeEditarArticulo((string) $request->request->get('sreferencia', ''), $this->codtarifa)) {
            $this->respondAjax(false, 'No tienes permiso para modificar este artículo.');
            return;
        }

        $referencia = (string) $request->request->get('sreferencia', '');
        $idGrupo = $request->request->getInt('id_grupo');
        $art = $this->articulo->get($referencia);

        if (!$art) {
            $this->respondAjax(false, 'Artículo no encontrado.');
            return;
        }

        if ($idGrupo <= 0) {
            $this->respondAjax(false, 'Selecciona un grupo.');
            return;
        }

        $rel = new catalogo_articulo_opcional_grupo();
        $obligatorio = $request->request->getBoolean('obligatorio');
        if ($rel->add($referencia, $idGrupo, $obligatorio)) {
            $this->articulo = $art;
            $this->respondAjax(true, 'Grupo de opcionales añadido correctamente.', ['reload_opcionales_tab' => true]);
            return;
        }

        $this->respondAjax(false, 'No se pudo añadir el grupo de opcionales.');
    }

    private function removeGrupoArticulo(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->respondAjax(false, 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->puedeEditarArticulo((string) $request->request->get('sreferencia', ''), $this->codtarifa)) {
            $this->respondAjax(false, 'No tienes permiso para modificar este artículo.');
            return;
        }

        $referencia = (string) $request->request->get('sreferencia', '');
        $idGrupo = $request->request->getInt('id_grupo');
        $art = $this->articulo->get($referencia);

        if (!$art) {
            $this->respondAjax(false, 'Artículo no encontrado.');
            return;
        }

        $rel = new catalogo_articulo_opcional_grupo();
        if ($rel->remove($referencia, $idGrupo)) {
            $this->articulo = $art;
            $this->respondAjax(true, 'Grupo de opcionales eliminado correctamente.', ['reload_opcionales_tab' => true]);
            return;
        }

        $this->respondAjax(false, 'No se pudo eliminar el grupo de opcionales.');
    }

    private function removeOpcionalArticulo(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->respondAjax(false, 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->puedeEditarArticulo((string) $request->request->get('sreferencia', ''), $this->codtarifa)) {
            $this->respondAjax(false, 'No tienes permiso para modificar este artículo.');
            return;
        }

        $referencia = (string) $request->request->get('sreferencia', '');
        $idOpcional = $request->request->getInt('id_opcional');
        $art = $this->articulo->get($referencia);

        if (!$art) {
            $this->respondAjax(false, 'Artículo no encontrado.');
            return;
        }

        $rel = new catalogo_articulo_opcional();
        if ($rel->remove($referencia, $idOpcional)) {
            $this->articulo = $art;
            $this->respondAjax(true, 'Opcional eliminado correctamente.', ['reload_opcionales_tab' => true]);
            return;
        }

        $this->respondAjax(false, 'No se pudo eliminar el opcional.');
    }

    private function toggleObligatorioGrupo(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->respondToggleObligatorio(false, 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->puedeEditarArticulo((string) $request->request->get('sreferencia', ''), $this->codtarifa)) {
            $this->respondToggleObligatorio(false, 'No tienes permiso para modificar este artículo.');
            return;
        }

        $referencia = (string) $request->request->get('sreferencia', '');
        $idGrupo = $request->request->getInt('id_grupo');
        $obligatorio = $request->request->getBoolean('obligatorio');

        $rel = new catalogo_articulo_opcional_grupo();
        if (!$rel->exists_relation($referencia, $idGrupo)) {
            $this->respondToggleObligatorio(false, 'El grupo no está asignado a este artículo.');
            return;
        }

        if ($rel->set_obligatorio($referencia, $idGrupo, $obligatorio)) {
            $this->respondToggleObligatorio(
                true,
                $obligatorio
                    ? 'Grupo marcado como obligatorio en TPV.'
                    : 'Grupo marcado como opcional en TPV.',
                $obligatorio
            );
            return;
        }

        $this->respondToggleObligatorio(false, 'No se pudo actualizar la obligatoriedad del grupo.');
    }

    private function toggleObligatorioOpcional(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->respondToggleObligatorio(false, 'Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->puedeEditarArticulo((string) $request->request->get('sreferencia', ''), $this->codtarifa)) {
            $this->respondToggleObligatorio(false, 'No tienes permiso para modificar este artículo.');
            return;
        }

        $referencia = (string) $request->request->get('sreferencia', '');
        $idOpcional = $request->request->getInt('id_opcional');
        $obligatorio = $request->request->getBoolean('obligatorio');

        $rel = new catalogo_articulo_opcional();
        if (!$rel->exists_relation($referencia, $idOpcional)) {
            $this->respondToggleObligatorio(false, 'El opcional no está asignado a este artículo.');
            return;
        }

        if ($rel->set_obligatorio($referencia, $idOpcional, $obligatorio)) {
            $this->respondToggleObligatorio(
                true,
                $obligatorio
                    ? 'Opcional marcado como obligatorio en TPV.'
                    : 'Opcional marcado como no obligatorio en TPV.',
                $obligatorio
            );
            return;
        }

        $this->respondToggleObligatorio(false, 'No se pudo actualizar la obligatoriedad del opcional.');
    }

    private function isAjaxRequest(): bool
    {
        return $this->request->isXmlHttpRequest()
            || $this->request->query->getBoolean('ajax');
    }

    /**
     * TRUE when the request comes from htmx (AD-W2-4): answers then carry the
     * re-rendered fragment instead of the full page.
     */
    protected function isHtmxRequest(): bool
    {
        return $this->request->headers->has('HX-Request');
    }

    /**
     * Echoes a re-rendered htmx fragment (AD-W2-4) and marks the request done
     * so run() skips the full-page template. Returns TRUE when it handled the
     * response.
     */
    protected function respondHtmx(bool $ok, string $message, string $template = 'partials/articulos/tab_opcionales'): bool
    {
        if (!$this->isHtmxRequest()) {
            return false;
        }

        header('Content-Type: text/html; charset=UTF-8');
        header('HX-Trigger: ' . json_encode([
            'tarifarioMessage' => ['ok' => $ok, 'message' => $message],
        ], JSON_UNESCAPED_UNICODE));
        echo $this->renderPartialHtml($template);
        $this->htmxHandled = true;

        return true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendAjaxJson(array $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function respondToggleObligatorio(bool $ok, string $message, ?bool $obligatorio = null): void
    {
        $extra = $obligatorio !== null ? ['obligatorio' => $obligatorio] : [];
        $this->respondAjax($ok, $message, $extra);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function respondAjax(bool $ok, string $message, array $extra = []): void
    {
        if ($this->isHtmxRequest()) {
            $this->respondHtmx($ok, $message);
            return;
        }

        if ($this->isAjaxRequest()) {
            $reloadTab = !empty($extra['reload_opcionales_tab']);
            unset($extra['reload_opcionales_tab']);

            if ($ok && $reloadTab) {
                $this->loadCatalogData();
                $extra['html'] = $this->renderOpcionalesPartialHtml();
            }

            $payload = array_merge([
                'ok' => $ok,
                'message' => $message,
            ], $extra);

            $this->sendAjaxJson($payload, $ok ? 200 : 400);
        }

        if ($ok) {
            $this->new_message($message);
            return;
        }

        $this->new_error_msg($message);
    }

    private function isOpcionalesPartialRequest(): bool
    {
        return $this->isAjaxRequest()
            && $this->request->query->get('partial') === 'opcionales';
    }

    private function resolveArticuloReferenciaFromRequest(): ?string
    {
        $ref = $this->request->query->get('ref');
        if ($ref !== null && $ref !== '') {
            return (string) $ref;
        }

        $id = $this->request->query->get('id');
        if ($id !== null && $id !== '') {
            return (string) $id;
        }

        return null;
    }

    private function shouldRenderOpcionalesPartial(): bool
    {
        if (!$this->isAjaxRequest()) {
            return false;
        }

        if ($this->request->query->get('partial') !== 'opcionales') {
            return false;
        }

        return $this->articulo !== null
            && $this->articulo->referencia !== null
            && $this->articulo->referencia !== '';
    }

    private function renderOpcionalesPartial(): void
    {
        header('Content-Type: text/html; charset=UTF-8');
        echo $this->renderOpcionalesPartialHtml();
        exit;
    }

    private function renderOpcionalesPartialHtml(): string
    {
        return $this->renderPartialHtml('partials/articulos/tab_opcionales');
    }

    /**
     * Renders a view partial through Html::renderAjax and strips any embedded
     * script tags so htmx only swaps markup (AD-W2-4).
     */
    protected function renderPartialHtml(string $template): string
    {
        $html = Html::renderAjax($template, [
            'fsc' => $this,
            'user' => $this->user,
            'empresa' => $this->empresa,
            'i18n' => new FSTranslator(),
        ]);

        $withoutScripts = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);

        return trim($withoutScripts ?? $html);
    }

    protected function eliminarArticulo(Request $request): void
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar en esta página.');
            return;
        }

        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $ref = (string) $request->request->get('sreferencia', '');
        if (!$this->puedeEditarArticulo($ref, $this->codtarifa)) {
            $this->new_error_msg('No tienes permiso para eliminar este artículo.');
            return;
        }

        $art = $this->articulo->get($ref);

        if (!$art) {
            $this->new_error_msg('¡Artículo no encontrado!');
            return;
        }

        if ($art->delete()) {
            $this->new_message('Artículo ' . $art->referencia . ' eliminado correctamente.');
            $this->redirect('ventas_articulos');
            return;
        }

        $this->new_error_msg('¡Imposible eliminar el artículo!');
    }
}
