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

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/fabricante.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use Symfony\Component\HttpFoundation\Request;

class VentasFabricantes extends PageController
{
    public array $resultados = [];
    public ?\fabricante $fabricante = null;
    public bool $allow_delete = false;

    public function __construct()
    {
        parent::__construct('VentasFabricantes');
        $this->setTemplate('ventas_fabricantes');
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->loadExtensions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'ventas_fabricantes',
            'title' => 'Fabricantes',
            'menu' => 'ventas',
            'showonmenu' => true,
            'ordernum' => 100,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $this->fabricante = new \fabricante();

        if ($this->request->request->has('scodfabricante')) {
            $this->editarFabricante($this->request);
        } elseif ($this->request->request->has('delete')) {
            $this->eliminarFabricante($this->request);
        }

        $search = $this->request->query->get('search');
        if ($search !== null && $search !== '') {
            $this->resultados = $this->fabricante->search((string) $search);
        } else {
            $this->resultados = $this->fabricante->all();
        }
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

    private function editarFabricante(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $codfabricante = (string) $request->request->get('scodfabricante', '');
        $fab = $this->fabricante->get($codfabricante);

        if (!$fab) {
            $fab = new \fabricante();
            $fab->codfabricante = $codfabricante;
        }

        $fab->nombre = (string) $request->request->get('snombre', '');

        if ($fab->save()) {
            $this->new_message('Fabricante ' . $fab->nombre . ' guardado correctamente.');
            return;
        }

        $this->new_error_msg('¡Imposible guardar el fabricante!');
    }

    private function eliminarFabricante(Request $request): void
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
            $this->new_error_msg('En el modo demo no puedes eliminar fabricantes. Otro usuario podría necesitarlo.');
            return;
        }

        $cod = (string) $request->request->get('delete', '');
        $fab = $this->fabricante->get($cod);

        if (!$fab) {
            $this->new_error_msg('¡Fabricante no encontrado!');
            return;
        }

        if ($fab->delete()) {
            $this->new_message('Fabricante ' . $fab->nombre . ' eliminado correctamente.');
            return;
        }

        $this->new_error_msg('¡Imposible eliminar el fabricante!');
    }
}
