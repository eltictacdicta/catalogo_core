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

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/pais.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use Symfony\Component\HttpFoundation\Request;

class AdminPaises extends PageController
{
    public array $resultados = [];
    public ?\pais $pais = null;
    public bool $allow_delete = false;

    public function __construct()
    {
        parent::__construct('AdminPaises');
        $this->setTemplate('admin_paises');
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->loadExtensions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'admin_paises',
            'title' => 'Paises',
            'menu' => 'admin',
            'showonmenu' => true,
            'ordernum' => 150,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $this->pais = new \pais();

        if ($this->request->request->has('scodpais')) {
            $this->editarPais($this->request);
        } elseif ($this->request->request->has('delete')) {
            $this->eliminarPais($this->request);
        }

        $this->resultados = $this->pais->all();
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

    private function editarPais(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $codpais = (string) $request->request->get('scodpais', '');
        $pais = $this->pais->get($codpais);

        if (!$pais) {
            $pais = new \pais();
            $pais->codpais = $codpais;
        }

        $pais->codiso = (string) $request->request->get('scodiso', '');
        $pais->nombre = (string) $request->request->get('snombre', '');

        if ($pais->save()) {
            $this->new_message('País ' . $pais->nombre . ' guardado correctamente.');
            return;
        }

        $this->new_error_msg('¡Imposible guardar el país!');
    }

    private function eliminarPais(Request $request): void
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
            $this->new_error_msg('En el modo demo no puedes eliminar países. Otro usuario podría necesitarlo.');
            return;
        }

        $codpais = (string) $request->request->get('delete', '');
        $pais = $this->pais->get($codpais);

        if (!$pais) {
            $this->new_error_msg('¡País no encontrado!');
            return;
        }

        if ($pais->delete()) {
            $this->new_message('País ' . $pais->nombre . ' eliminado correctamente.');
            return;
        }

        $this->new_error_msg('¡Imposible eliminar el país!');
    }
}
