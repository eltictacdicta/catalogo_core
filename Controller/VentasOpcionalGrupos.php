<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
namespace FSFramework\Plugins\catalogo_core\Controller;

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional_grupo.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use FSFramework\model\catalogo_opcional_grupo;
use Symfony\Component\HttpFoundation\Request;

class VentasOpcionalGrupos extends PageController
{
    /** @var array<int, catalogo_opcional_grupo> */
    public array $resultados = [];
    public string $search_query = '';
    public bool $allow_delete = false;

    public function __construct()
    {
        parent::__construct('VentasOpcionalGrupos');
        $this->setTemplate('ventas_opcional_grupos');
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->loadExtensions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'ventas_opcional_grupos',
            'title' => 'Grupos de opcionales',
            'menu' => 'ventas',
            'showonmenu' => false,
            'ordernum' => 107,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        if ($this->request->request->has('delete')) {
            $this->eliminarGrupo($this->request);
        }

        $this->search_query = trim((string) $this->request->query->get('search', ''));
        $grupo = new catalogo_opcional_grupo();
        $this->resultados = $this->search_query !== ''
            ? $grupo->search($this->search_query)
            : $grupo->all();
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

    private function eliminarGrupo(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar grupos.');
            return;
        }

        $id = $request->request->getInt('delete');
        if ($id <= 0) {
            return;
        }

        $grupo = new catalogo_opcional_grupo();
        $item = $grupo->get($id);
        if (!$item) {
            $this->new_error_msg('Grupo no encontrado.');
            return;
        }

        if ($item->delete()) {
            $this->new_message('Grupo ' . $item->codigo . ' eliminado.');
        } else {
            $this->new_error_msg('No se pudo eliminar el grupo.');
        }
    }
}
