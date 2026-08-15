<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
 */
namespace FSFramework\Plugins\catalogo_core\Controller;

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_opcional.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use FSFramework\model\catalogo_opcional;
use Symfony\Component\HttpFoundation\Request;

class VentasOpcionales extends PageController
{
    /** @var array<int, catalogo_opcional> */
    public array $resultados = [];
    public string $search_query = '';
    public bool $allow_delete = false;

    public function __construct()
    {
        parent::__construct('VentasOpcionales');
        $this->setTemplate('ventas_opcionales');
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->loadExtensions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'ventas_opcionales',
            'title' => 'Opcionales',
            'menu' => 'ventas',
            'showonmenu' => true,
            'ordernum' => 108,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        if ($this->request->request->has('delete')) {
            $this->eliminarOpcional($this->request);
        }

        $this->search_query = trim((string) $this->request->query->get('search', ''));
        $opcional = new catalogo_opcional();
        $this->resultados = $this->search_query !== ''
            ? $opcional->search($this->search_query)
            : $opcional->all();
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

    private function eliminarOpcional(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar en esta página.');
            return;
        }

        if (defined('FS_DEMO') && FS_DEMO) {
            $this->new_error_msg('En el modo demo no puedes eliminar opcionales.');
            return;
        }

        $id = $request->request->getInt('delete');
        $opcional = new catalogo_opcional();
        $item = $opcional->get($id);

        if (!$item) {
            $this->new_error_msg('Opcional no encontrado.');
            return;
        }

        if ($item->delete()) {
            $this->new_message('Opcional ' . $item->codigo . ' eliminado correctamente.');
            return;
        }

        $this->new_error_msg('No se pudo eliminar el opcional.');
    }
}
