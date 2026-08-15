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
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use FSFramework\Core\Html;
use FSFramework\model\catalogo_articulo_opcional;
use FSFramework\model\catalogo_articulo_opcional_grupo;
use FSFramework\model\catalogo_idioma;
use FSFramework\model\catalogo_lista_precio;
use FSFramework\model\catalogo_opcional;
use FSFramework\model\catalogo_opcional_grupo;
use FSFramework\Translation\FSTranslator;
use Symfony\Component\HttpFoundation\Request;

class VentasArticulo extends PageController
{
    public ?\articulo $articulo = null;
    public array $familias = [];
    public array $fabricantes = [];
    public array $impuestos = [];
    public array $idiomas = [];
    public array $articulo_opcionales = [];
    public array $articulo_grupos = [];
    public array $opcionales_disponibles = [];
    public array $grupos_disponibles = [];
    public string $lista_precio_defecto = catalogo_lista_precio::DEFAULT_CODE;
    public bool $allow_delete = false;

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
            'menu' => 'ventas',
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
        $this->articulo = new \articulo();

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
        } elseif ($this->request->request->has('sreferencia')) {
            $this->editarArticulo($this->request);
        } elseif ($this->request->query->has('delete') && $this->allow_delete) {
            $this->eliminarArticulo($this->request);
        }

        $this->loadCatalogData();
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

    private function editarArticulo(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $referencia = (string) $request->request->get('sreferencia', '');
        $art = $this->articulo->get($referencia);

        if (!$art) {
            $art = new \articulo();
            $art->referencia = $referencia;
        }

        $art->descripcion = (string) $request->request->get('sdescripcion', '');
        $art->pvp = (float) $request->request->get('spvp', 0);

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
            $this->new_message('Artículo ' . $art->referencia . ' guardado correctamente.');
            $this->articulo = $art;
            return;
        }

        $this->new_error_msg('¡Imposible guardar el artículo!');
    }

    private function saveMultiidiomaDescriptions(\articulo $art, Request $request): void
    {
        if ($art->referencia === null || $art->referencia === '') {
            return;
        }

        foreach ($this->idiomas as $idioma) {
            $codidioma = (string) $idioma->codidioma;

            if (!empty($idioma->por_defecto)) {
                $art->set_descripcion_idioma($codidioma, (string) $art->descripcion);
                continue;
            }

            $descKey = 'descripcion_' . $codidioma;
            $cortaKey = 'descripcion_corta_' . $codidioma;

            if (!$request->request->has($descKey)) {
                continue;
            }

            $descripcion = trim((string) $request->request->get($descKey, ''));
            $descripcionCorta = trim((string) $request->request->get($cortaKey, ''));
            if ($descripcion === '') {
                continue;
            }

            $art->set_descripcion_idioma(
                $codidioma,
                $descripcion,
                $descripcionCorta !== '' ? $descripcionCorta : null
            );
        }

        $art->save();
    }

    private function loadCatalogData(): void
    {
        $idioma = new catalogo_idioma();
        $idioma->ensure_defaults();
        $this->idiomas = $idioma->all_activos();

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
        $html = Html::renderAjax('partials/articulos/tab_opcionales', [
            'fsc' => $this,
            'user' => $this->user,
            'empresa' => $this->empresa,
            'i18n' => new FSTranslator(),
        ]);

        $withoutScripts = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html);

        return trim($withoutScripts ?? $html);
    }

    private function eliminarArticulo(Request $request): void
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar en esta página.');
            return;
        }

        if (defined('FS_DEMO') && FS_DEMO) {
            $this->new_error_msg('En el modo demo no puedes eliminar artículos. Otro usuario podría necesitarlos.');
            return;
        }

        $ref = (string) $request->query->get('delete', '');
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
