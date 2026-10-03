<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
namespace FSFramework\Plugins\catalogo_core\Controller;

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo_rel.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_familia.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/OpcionalImagenService.php';
require_once FS_FOLDER . '/plugins/catalogo_core/extras/CaracteristicaHookContextTrait.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use FSFramework\model\catalogo_articulo_opcional;
use FSFramework\model\catalogo_lista_precio;
use FSFramework\model\catalogo_opcional;
use FSFramework\model\catalogo_opcional_grupo;
use FSFramework\model\catalogo_opcional_familia;
use FSFramework\Plugins\catalogo_core\Services\OpcionalImagenService;
use Symfony\Component\HttpFoundation\Request;

class VentasOpcional extends PageController
{
    use \CaracteristicaHookContextTrait;

    public ?catalogo_opcional $opcional = null;
    public bool $is_new = true;
    public string $lista_precio_defecto = catalogo_lista_precio::DEFAULT_CODE;
    /** @var array<int, \familia> */
    public array $familias = [];
    /** @var array<int, \familia> */
    public array $familias_asignadas = [];
    /** @var array<int, \articulo> */
    public array $articulos = [];
    /** @var array<int, \familia> */
    public array $familias_disponibles = [];
    /** @var array<int, catalogo_opcional_grupo> */
    public array $grupos_opcional = [];
    /** @var array<int, catalogo_opcional_grupo> Current memberships of the edited opcional (active and inactive). */
    public array $grupos_asignados = [];
    /** @var list<int> Checked group ids for the membership checkbox list. */
    public array $grupos_asignados_ids = [];
    /** @var array<int, \FSFramework\model\catalogo_idioma> Active languages for the selector. */
    public array $idiomas = [];
    /** @var array<string, object|array<string, mixed>> Editor prefill keyed by codidioma. */
    public array $idiomas_opcional = [];
    /** @var string Language preselected in the editor selector. */
    public string $codidioma_edit = '';
    public bool $allow_delete = false;

    public function __construct()
    {
        parent::__construct('VentasOpcional');
        $this->setTemplate('ventas_opcional');
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->loadExtensions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'ventas_opcional',
            'title' => 'Opcional',
            'menu' => 'catalogo',
            'showonmenu' => false,
            'ordernum' => 109,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $this->loadListaPrecioDefault();
        $this->loadFamilias();
        $this->loadGruposOpcional();
        $this->loadIdiomas();

        $id = $this->request->query->getInt('id');
        if ($id > 0) {
            $model = new catalogo_opcional();
            $item = $model->get($id);
            if ($item) {
                $this->opcional = $item;
                $this->is_new = false;
            } else {
                $this->new_error_msg('Opcional no encontrado.');
                return;
            }
        } else {
            $model = new catalogo_opcional();
            $this->opcional = $model;
            $this->opcional->codigo = $model->get_new_codigo();
            $this->is_new = true;
        }

        $this->loadGruposAsignados();
        $this->loadIdiomasOpcional();

        if (!$this->is_new && $this->opcional !== null && $this->request->query->has('buscar_articulo')) {
            $this->buscarArticulo((string) $this->request->query->get('buscar_articulo', ''));
            return;
        }

        if ($this->request->request->has('save_opcional')) {
            $this->guardarOpcional($this->request);
        } elseif ($this->request->request->has('add_familia_opcional')) {
            $this->addFamilia($this->request);
        } elseif ($this->request->request->has('remove_familia_opcional')) {
            $this->removeFamilia($this->request);
        } elseif ($this->request->request->has('add_articulo_opcional')) {
            $this->addArticulo($this->request);
        } elseif ($this->request->request->has('remove_articulo_opcional')) {
            $this->removeArticulo($this->request);
        } elseif ($this->request->request->has('delete') && $this->allow_delete) {
            $this->eliminarOpcional($this->request);
        } elseif ($this->request->request->has('upload_opcional_imagen')) {
            $this->subirImagenOpcional($this->request);
        } elseif ($this->request->request->has('delete_opcional_imagen')) {
            $this->eliminarImagenOpcional($this->request);
        }

        if ($this->opcional !== null && !$this->is_new) {
            $this->familias_asignadas = $this->opcional->get_familias();
            $this->articulos = $this->opcional->get_articulos();
            $this->familias_disponibles = $this->buildFamiliasDisponibles();
        }

        $this->loadGruposAsignados();
        $this->loadIdiomasOpcional();
    }

    /**
     * @return array<int, \familia>
     */
    private function buildFamiliasDisponibles(): array
    {
        $assigned = [];
        foreach ($this->familias_asignadas as $familia) {
            $assigned[$familia->codfamilia] = true;
        }

        $disponibles = [];
        foreach ($this->familias as $familia) {
            if (!isset($assigned[$familia->codfamilia])) {
                $disponibles[] = $familia;
            }
        }

        return $disponibles;
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

    private function loadListaPrecioDefault(): void
    {
        $lista = new catalogo_lista_precio();
        $lista->ensure_defaults();
        $default = $lista->get_default();
        $this->lista_precio_defecto = $default
            ? (string) $default->codlista
            : catalogo_lista_precio::DEFAULT_CODE;
    }

    private function loadFamilias(): void
    {
        $familia = new \familia();
        $this->familias = $familia->all();
    }

    private function loadGruposOpcional(): void
    {
        $grupo = new catalogo_opcional_grupo();
        $this->grupos_opcional = $grupo->all_activos();
    }

    /**
     * Language registry seam. Overridable so the editor language block can be
     * exercised without a database.
     */
    protected function idioma_registry_model()
    {
        return new \FSFramework\model\catalogo_idioma();
    }

    /**
     * Loads the active languages and the language preselected in the selector.
     */
    private function loadIdiomas(): void
    {
        $registry = $this->idioma_registry_model();
        $registry->ensure_defaults();
        $this->idiomas = $registry->all_activos();
        $this->codidioma_edit = (string) $registry->get_effective_default_code();
    }

    /**
     * Prefills the editor uniformly: every active language gets an entry whose
     * `nombre`/`descripcion` the view can read directly. Under mirror semantics
     * the configured default lives in the base columns, so its slot is always
     * built from the base (never from a stale child row); every other language
     * comes from its own child row.
     */
    private function loadIdiomasOpcional(): void
    {
        $this->idiomas_opcional = [];

        if (
            $this->opcional === null
            || $this->is_new
            || $this->opcional->codigo === null
            || $this->opcional->codigo === ''
        ) {
            return;
        }

        foreach ($this->opcional->get_idiomas() as $row) {
            $this->idiomas_opcional[(string) $row->codidioma] = $row;
        }

        $defaultCode = $this->effective_default_code();
        if ($defaultCode !== '') {
            $this->idiomas_opcional[$defaultCode] = [
                'codidioma' => $defaultCode,
                'nombre' => (string) $this->opcional->nombre,
                'descripcion' => (string) $this->opcional->descripcion,
            ];
        }
    }

    /**
     * Resolves the effective default language code. When the editor has already
     * primed `codidioma_edit` (via `loadIdiomas()`), that value is authoritative;
     * otherwise the registry resolves it. Overridable seam for DB-free tests.
     */
    protected function effective_default_code(): string
    {
        if ($this->codidioma_edit !== '') {
            return $this->codidioma_edit;
        }

        return (string) $this->idioma_registry_model()->get_effective_default_code();
    }

    /**
     * Persists the per-language name/description inputs (`idioma_nombre[cod]` /
     * `idioma_descripcion[cod]`) for the non-default languages. Runs only after
     * the CSRF check in `guardarOpcional()`. The configured default is mirrored
     * into the base columns by `guardarOpcional()` before `save()`, so it is
     * intentionally skipped here; an empty pair clears the row inside the model.
     */
    private function guardarIdiomas(Request $request, string $defaultCode): bool
    {
        if ($this->opcional === null) {
            return true;
        }

        $nombres = (array) $request->request->all('idioma_nombre');
        $descripciones = (array) $request->request->all('idioma_descripcion');

        $ok = true;
        foreach ($nombres as $codidioma => $nombre) {
            $codidioma = trim((string) $codidioma);
            if ($codidioma === '') {
                continue;
            }

            if ($codidioma === $defaultCode) {
                continue;
            }

            $descripcion = (string) ($descripciones[$codidioma] ?? '');
            $ok = $this->opcional->set_idioma($codidioma, (string) $nombre, $descripcion) && $ok;
        }

        return $ok;
    }

    /**
     * Builds the membership checkbox list as the union of the active groups and
     * the opcional's current memberships (lossless: an inactive-group membership
     * still renders and survives a save). `?id_grupo=<n>` presets the checked set
     * for a new opcional without writing the model.
     */
    private function loadGruposAsignados(): void
    {
        $this->grupos_asignados = [];
        $this->grupos_asignados_ids = [];

        if ($this->opcional === null) {
            return;
        }

        if (!$this->is_new) {
            $this->grupos_asignados = $this->opcional->get_grupos();
        } else {
            $idGrupoPreset = $this->request->query->getInt('id_grupo');
            if ($idGrupoPreset > 0) {
                $grupo = new catalogo_opcional_grupo();
                $item = $grupo->get($idGrupoPreset);
                if ($item) {
                    $this->grupos_asignados = [$item];
                }
            }
        }

        $known = [];
        foreach ($this->grupos_opcional as $grupo) {
            $known[(int) $grupo->id] = true;
        }

        foreach ($this->grupos_asignados as $grupo) {
            $this->grupos_asignados_ids[] = (int) $grupo->id;
            if (!isset($known[(int) $grupo->id])) {
                $this->grupos_opcional[] = $grupo;
                $known[(int) $grupo->id] = true;
            }
        }
    }

    private function guardarOpcional(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if ($this->opcional === null) {
            return;
        }

        $codigo = trim((string) $request->request->get('scodigo', ''));
        if ($codigo === '') {
            $codigo = $this->opcional->get_new_codigo();
        }

        $this->opcional->codigo = $codigo;

        // Mirror semantics: the base columns are the configured-default language.
        // The default slot of the language inputs is the one and only source for
        // the base name/description; setting it before save() lets test() enforce
        // the required non-empty name and writes the mirrored base.
        $defaultCode = $this->effective_default_code();
        $idiomaNombres = (array) $request->request->all('idioma_nombre');
        $idiomaDescripciones = (array) $request->request->all('idioma_descripcion');
        if ($defaultCode === '' || !array_key_exists($defaultCode, $idiomaNombres)) {
            $this->new_error_msg('Falta el nombre del idioma por defecto.');
            return;
        }

        $this->opcional->nombre = (string) $idiomaNombres[$defaultCode];
        $this->opcional->descripcion = (string) ($idiomaDescripciones[$defaultCode] ?? '');

        $tipoPrecio = (string) $request->request->get('stipo_precio', catalogo_opcional::TIPO_PRECIO_FIJO);
        $this->opcional->tipo_precio = $tipoPrecio === catalogo_opcional::TIPO_PRECIO_PORCENTAJE
            ? catalogo_opcional::TIPO_PRECIO_PORCENTAJE
            : catalogo_opcional::TIPO_PRECIO_FIJO;

        if ($this->opcional->es_precio_porcentaje()) {
            $this->opcional->porcentaje = (float) str_replace(',', '.', (string) $request->request->get('sporcentaje', '0'));
            $this->opcional->precio = 0.0;
        } else {
            $this->opcional->porcentaje = null;
            $this->opcional->precio = (float) str_replace(',', '.', (string) $request->request->get('sprecio', '0'));
        }

        $this->opcional->activo = $request->request->has('sactivo');

        if (!$this->opcional->save()) {
            $this->new_error_msg('No se pudo guardar el opcional.');
            return;
        }

        $grupos = (array) $request->request->all('grupos');
        if (!$this->opcional->set_grupos($grupos)) {
            $this->new_error_msg('No se pudieron guardar los grupos del opcional.');
        }

        if (!$this->guardarIdiomas($request, $defaultCode)) {
            $this->new_error_msg('No se pudieron guardar los idiomas del opcional.');
        }

        if ($this->opcional->es_precio_porcentaje()) {
            $this->opcional->set_porcentaje_lista(
                $this->lista_precio_defecto,
                (float) ($this->opcional->porcentaje ?? 0)
            );
        } else {
            $this->opcional->set_precio_lista(
                $this->lista_precio_defecto,
                (float) $this->opcional->precio
            );
        }

        $this->new_message('Opcional ' . $this->opcional->codigo . ' guardado correctamente.');
        $this->is_new = false;

        if ($this->request->query->getInt('id') !== (int) $this->opcional->id) {
            $this->redirect('ventas_opcional&id=' . (int) $this->opcional->id);
        }
    }

    private function addFamilia(Request $request): void
    {
        if (!$this->validateFormToken() || $this->is_new || $this->opcional === null) {
            $this->new_error_msg('No se pudo añadir la familia.');
            return;
        }

        $codfamilia = (string) $request->request->get('codfamilia', '');
        if ($codfamilia === '') {
            return;
        }

        $propagate = $request->request->getBoolean('propagate_familia');
        if ($propagate) {
            $result = $this->opcional->add_familia($codfamilia);
            if ($result['familia']) {
                $this->new_message('Familia añadida correctamente.');
                if ($result['articulos'] > 0) {
                    $this->new_message('Opcional asignado a ' . $result['articulos'] . ' artículo(s).');
                }
            } else {
                $this->new_error_msg('Error al añadir la familia.');
            }
            return;
        }

        if ($this->opcional->add_familia_only($codfamilia)) {
            $this->new_message('Familia añadida (sin propagar a artículos).');
            return;
        }

        $this->new_error_msg('Error al añadir la familia.');
    }

    private function removeFamilia(Request $request): void
    {
        if (!$this->validateFormToken() || $this->is_new || $this->opcional === null) {
            return;
        }

        $codfamilia = (string) $request->request->get('codfamilia', '');
        if ($codfamilia === '') {
            return;
        }

        $propagate = $request->request->getBoolean('propagate_familia');
        if ($propagate) {
            $result = $this->opcional->remove_familia($codfamilia);
            if ($result['familia']) {
                $this->new_message('Familia eliminada del opcional.');
            } else {
                $this->new_error_msg('Error al eliminar la familia.');
            }
            return;
        }

        if ($this->opcional->remove_familia_only($codfamilia)) {
            $this->new_message('Familia eliminada (sin quitar de artículos).');
            return;
        }

        $this->new_error_msg('Error al eliminar la familia.');
    }

    private function addArticulo(Request $request): void
    {
        if (!$this->validateFormToken() || $this->is_new || $this->opcional === null) {
            return;
        }

        if ($this->opcional->is_grouped()) {
            $this->new_error_msg('Este opcional pertenece a un grupo. Asigna el grupo al artículo, no cada variante.');
            return;
        }

        $referencia = trim((string) $request->request->get('referencia', ''));
        if ($referencia === '') {
            return;
        }

        $rel = new catalogo_articulo_opcional();
        if ($rel->add($referencia, (int) $this->opcional->id)) {
            $this->new_message('Artículo ' . $referencia . ' añadido al opcional.');
            return;
        }

        $this->new_error_msg('No se pudo añadir el artículo.');
    }

    private function removeArticulo(Request $request): void
    {
        if (!$this->validateFormToken() || $this->is_new || $this->opcional === null) {
            return;
        }

        $referencia = trim((string) $request->request->get('referencia', ''));
        if ($referencia === '') {
            return;
        }

        $rel = new catalogo_articulo_opcional();
        if ($rel->remove($referencia, (int) $this->opcional->id)) {
            $this->new_message('Artículo ' . $referencia . ' eliminado del opcional.');
            return;
        }

        $this->new_error_msg('No se pudo eliminar el artículo.');
    }

    private function eliminarOpcional(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->allow_delete || $this->opcional === null || $this->is_new) {
            $this->new_error_msg('No tienes permiso para eliminar en esta página.');
            return;
        }

        $id = $request->request->getInt('delete');
        if ($id !== (int) $this->opcional->id) {
            $this->new_error_msg('Opcional no encontrado.');
            return;
        }

        if ($this->opcional->delete()) {
            $this->new_message('Opcional eliminado correctamente.');
            $this->redirect('ventas_opcionales');
            return;
        }

        $this->new_error_msg('No se pudo eliminar el opcional.');
    }

    /**
     * Image service seam. Overridable so the upload/replace/remove flow is
     * unit-testable against a temporary directory instead of the repository's
     * `imgs/opcionales/`.
     */
    protected function opcional_imagen_service(): OpcionalImagenService
    {
        return new OpcionalImagenService();
    }

    /**
     * Stores the single opcional image (OVE-04). The client filename is never
     * trusted; the previous physical file is deleted only after the new bare
     * filename is persisted, and the just-written file is rolled back when the
     * model save fails.
     */
    private function subirImagenOpcional(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if ($this->opcional === null || $this->is_new) {
            $this->new_error_msg('Guarda el opcional antes de subir una imagen.');
            return;
        }

        if (
            !isset($_FILES['imagen'])
            || !is_array($_FILES['imagen'])
            || (int) ($_FILES['imagen']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
        ) {
            $this->new_error_msg('Error al subir la imagen.');
            return;
        }

        $service = $this->opcional_imagen_service();
        $filename = $service->upload((string) $this->opcional->codigo, $_FILES['imagen']);
        if ($filename === null) {
            $this->new_error_msg('Error al guardar la imagen. Asegúrate de que sea un archivo de imagen válido (JPG, PNG, GIF o WEBP).');
            return;
        }

        $previous = trim((string) $this->opcional->imagen);
        $this->opcional->imagen = $filename;

        if (!$this->opcional->save()) {
            $service->delete_file($filename);
            $this->new_error_msg('No se pudo guardar la imagen del opcional.');
            return;
        }

        if ($previous !== '' && $previous !== $filename) {
            $service->delete_file($previous);
        }

        $this->new_message('Imagen subida correctamente.');
    }

    /**
     * Removes the opcional image: clears the column first, then deletes the
     * physical file (OVE-04).
     */
    private function eliminarImagenOpcional(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if ($this->opcional === null || $this->is_new) {
            $this->new_error_msg('Opcional no encontrado.');
            return;
        }

        $filename = trim((string) $this->opcional->imagen);
        if ($filename === '') {
            $this->new_error_msg('El opcional no tiene imagen.');
            return;
        }

        $this->opcional->imagen = null;
        if (!$this->opcional->save()) {
            $this->new_error_msg('No se pudo eliminar la imagen del opcional.');
            return;
        }

        $this->opcional_imagen_service()->delete_file($filename);
        $this->new_message('Imagen eliminada correctamente.');
    }

    private function buscarArticulo(string $query): void
    {
        $this->setTemplate(false);

        $suggestions = $this->buscarSugerencias($query);

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'query' => $query,
            'suggestions' => $suggestions,
        ]);
        exit;
    }

    /**
     * DB seam: the article model behind the autocomplete finder. Overridable so
     * the no-context consumer is testable without a live database (same seam
     * pattern the plugin's controllers use for `idioma_model()`).
     *
     * @return \FSFramework\model\articulo
     */
    protected function articulo_model()
    {
        return new \FSFramework\model\articulo();
    }

    /**
     * Builds the autocomplete suggestions.
     *
     * No-context consumer (GDI-10 / D-10): this controller has no `codidioma`,
     * so the description is resolved through the configured default language and
     * the chain ends at the frozen base column.
     *
     * @return array<int, array<string, string>>
     */
    protected function buscarSugerencias(string $query): array
    {
        $suggestions = [];

        foreach ($this->articulo_model()->search($query) as $art) {
            $suggestions[] = [
                'value' => $art->referencia . ' - ' . $art->descripcion_idioma(null, 50),
                'data' => $art->referencia,
                'referencia' => $art->referencia,
                'descripcion' => $art->get_descripcion_idioma(),
            ];
        }

        return $suggestions;
    }
}
