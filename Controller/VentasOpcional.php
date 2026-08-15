<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
namespace FSFramework\Plugins\catalogo_core\Controller;

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_familia.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use FSFramework\model\catalogo_articulo_opcional;
use FSFramework\model\catalogo_lista_precio;
use FSFramework\model\catalogo_opcional;
use FSFramework\model\catalogo_opcional_grupo;
use FSFramework\model\catalogo_opcional_familia;
use Symfony\Component\HttpFoundation\Request;

class VentasOpcional extends PageController
{
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
            'menu' => 'ventas',
            'showonmenu' => false,
            'ordernum' => 109,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $this->loadListaPrecioDefault();
        $this->loadFamilias();
        $this->loadGruposOpcional();

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
            $idGrupoPreset = $this->request->query->getInt('id_grupo');
            if ($idGrupoPreset > 0) {
                $grupo = new catalogo_opcional_grupo();
                if ($grupo->get($idGrupoPreset)) {
                    $this->opcional->id_grupo = $idGrupoPreset;
                }
            }
            $this->is_new = true;
        }

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
        }

        if ($this->opcional !== null && !$this->is_new) {
            $this->familias_asignadas = $this->opcional->get_familias();
            $this->articulos = $this->opcional->get_articulos();
            $this->familias_disponibles = $this->buildFamiliasDisponibles();
        }
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
        $this->opcional->nombre = (string) $request->request->get('snombre', '');
        $this->opcional->descripcion = (string) $request->request->get('sdescripcion', '');
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

        $idGrupo = $request->request->getInt('sid_grupo');
        $this->opcional->id_grupo = $idGrupo > 0 ? $idGrupo : null;

        if (!$this->opcional->save()) {
            $this->new_error_msg('No se pudo guardar el opcional.');
            return;
        }

        if ($this->opcional->id_grupo) {
            $rel = new catalogo_articulo_opcional();
            $rel->delete_all_from_opcional((int) $this->opcional->id);
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

        if ($this->opcional->id_grupo) {
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

        if (defined('FS_DEMO') && FS_DEMO) {
            $this->new_error_msg('En el modo demo no puedes eliminar opcionales.');
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

    private function buscarArticulo(string $query): void
    {
        $this->setTemplate(false);

        $articulo = new \FSFramework\model\articulo();
        $suggestions = [];

        foreach ($articulo->search($query) as $art) {
            $suggestions[] = [
                'value' => $art->referencia . ' - ' . $art->descripcion(50),
                'data' => $art->referencia,
                'referencia' => $art->referencia,
                'descripcion' => $art->descripcion(120),
            ];
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'query' => $query,
            'suggestions' => $suggestions,
        ]);
        exit;
    }
}
