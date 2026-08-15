<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
namespace FSFramework\Plugins\catalogo_core\Controller;

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_articulo_opcional_grupo.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use FSFramework\model\catalogo_articulo_opcional_grupo;
use FSFramework\model\catalogo_opcional;
use FSFramework\model\catalogo_opcional_grupo;
use Symfony\Component\HttpFoundation\Request;

class VentasOpcionalGrupo extends PageController
{
    public ?catalogo_opcional_grupo $grupo = null;
    public bool $is_new = true;
    public bool $allow_delete = false;
    /** @var array<int, catalogo_opcional> */
    public array $opcionales = [];
    /** @var array<int, catalogo_opcional> */
    public array $opcionales_disponibles = [];
    /** @var array<int, \articulo> */
    public array $articulos = [];

    public function __construct()
    {
        parent::__construct('VentasOpcionalGrupo');
        $this->setTemplate('ventas_opcional_grupo');
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->loadExtensions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'ventas_opcional_grupo',
            'title' => 'Grupo de opcionales',
            'menu' => 'ventas',
            'showonmenu' => false,
            'ordernum' => 107,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $id = $this->request->query->getInt('id');
        if ($id > 0) {
            $model = new catalogo_opcional_grupo();
            $item = $model->get($id);
            if ($item) {
                $this->grupo = $item;
                $this->is_new = false;
            } else {
                $this->new_error_msg('Grupo no encontrado.');
                return;
            }
        } else {
            $model = new catalogo_opcional_grupo();
            $this->grupo = $model;
            $this->grupo->codigo = $model->get_new_codigo();
            $this->is_new = true;
        }

        if ($this->request->request->has('save_grupo')) {
            $this->guardarGrupo($this->request);
        } elseif ($this->request->request->has('add_opcional_grupo')) {
            $this->addOpcionalAlGrupo($this->request);
        } elseif ($this->request->request->has('remove_opcional_grupo')) {
            $this->removeOpcionalDelGrupo($this->request);
        } elseif ($this->request->request->has('delete') && $this->allow_delete) {
            $this->eliminarGrupo($this->request);
        }

        if ($this->grupo !== null && !$this->is_new) {
            $this->loadGrupoRelations();
        }
    }

    private function loadGrupoRelations(): void
    {
        if ($this->grupo === null) {
            return;
        }

        $this->opcionales = $this->grupo->get_opcionales();

        $asignados = [];
        foreach ($this->opcionales as $opcional) {
            $asignados[(int) $opcional->id] = true;
        }

        $opcionalModel = new catalogo_opcional();
        $this->opcionales_disponibles = [];
        foreach ($opcionalModel->all_sin_grupo(0, 500) as $opcional) {
            if (!isset($asignados[(int) $opcional->id])) {
                $this->opcionales_disponibles[] = $opcional;
            }
        }

        $rel = new catalogo_articulo_opcional_grupo();
        $this->articulos = $rel->get_articulos_from_grupo((int) $this->grupo->id);
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

    private function guardarGrupo(Request $request): void
    {
        if (!$this->validateFormToken() || $this->grupo === null) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $codigo = trim((string) $request->request->get('scodigo', ''));
        if ($codigo === '') {
            $codigo = $this->grupo->get_new_codigo();
        }

        $this->grupo->codigo = $codigo;
        $this->grupo->nombre = (string) $request->request->get('snombre', '');
        $this->grupo->orden = $request->request->getInt('sorden');
        $this->grupo->exclusivo = $request->request->has('sexclusivo');
        $this->grupo->activo = $request->request->has('sactivo');

        if (!$this->grupo->save()) {
            $this->new_error_msg('No se pudo guardar el grupo.');
            return;
        }

        $this->new_message('Grupo ' . $this->grupo->codigo . ' guardado correctamente.');
        $this->is_new = false;

        if ($this->request->query->getInt('id') !== (int) $this->grupo->id) {
            $this->redirect('ventas_opcional_grupo&id=' . (int) $this->grupo->id);
        }
    }

    private function addOpcionalAlGrupo(Request $request): void
    {
        if (!$this->validateFormToken() || $this->is_new || $this->grupo === null) {
            return;
        }

        $idOpcional = $request->request->getInt('id_opcional');
        if ($idOpcional <= 0) {
            return;
        }

        $opcional = new catalogo_opcional();
        $item = $opcional->get($idOpcional);
        if (!$item) {
            $this->new_error_msg('Opcional no encontrado.');
            return;
        }

        if ($item->assign_to_grupo((int) $this->grupo->id)) {
            $this->new_message('Opcional ' . $item->codigo . ' añadido al grupo.');
            return;
        }

        $errors = $item->get_errors();
        if ($errors !== []) {
            $this->new_error_msg((string) $errors[0]);
            return;
        }

        $this->new_error_msg('No se pudo añadir el opcional al grupo.');
    }

    private function removeOpcionalDelGrupo(Request $request): void
    {
        if (!$this->validateFormToken() || $this->is_new || $this->grupo === null) {
            return;
        }

        $idOpcional = $request->request->getInt('id_opcional');
        if ($idOpcional <= 0) {
            return;
        }

        $opcional = new catalogo_opcional();
        $item = $opcional->get($idOpcional);
        if (!$item || (int) $item->id_grupo !== (int) $this->grupo->id) {
            $this->new_error_msg('Opcional no encontrado en este grupo.');
            return;
        }

        if ($item->remove_from_grupo()) {
            $this->new_message('Opcional ' . $item->codigo . ' quitado del grupo.');
            return;
        }

        $this->new_error_msg('No se pudo quitar el opcional del grupo.');
    }

    private function eliminarGrupo(Request $request): void
    {
        if (!$this->validateFormToken() || $this->is_new || $this->grupo === null) {
            return;
        }

        $id = $request->request->getInt('delete');
        if ($id !== (int) $this->grupo->id) {
            return;
        }

        if ($this->grupo->delete()) {
            $this->new_message('Grupo eliminado.');
            $this->redirect('ventas_opcional_grupos');
        } else {
            $this->new_error_msg('No se pudo eliminar el grupo.');
        }
    }
}
