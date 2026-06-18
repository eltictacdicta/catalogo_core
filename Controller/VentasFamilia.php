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

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use Symfony\Component\HttpFoundation\Request;

class VentasFamilia extends PageController
{
    public ?\familia $familia = null;
    public array $articulos = [];
    public array $madres = [];
    public bool $allow_delete = false;

    public function __construct()
    {
        parent::__construct('VentasFamilia');
        $this->setTemplate('ventas_familia');
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->loadExtensions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'ventas_familia',
            'title' => 'Familia',
            'menu' => 'ventas',
            'showonmenu' => false,
            'ordernum' => 120,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $this->familia = new \familia();

        $cod = $this->request->query->get('cod');
        if ($cod !== null && $cod !== '') {
            $fam = $this->familia->get((string) $cod);
            if ($fam) {
                $this->familia = $fam;
            } else {
                $this->new_error_msg('Familia no encontrada.');
                return;
            }
        }

        if ($this->request->request->has('scodfamilia')) {
            $this->editarFamilia($this->request);
        } elseif ($this->request->request->has('delete') && $this->allow_delete) {
            $this->eliminarFamilia($this->request);
        }

        if ($this->familia->codfamilia !== null) {
            $offset = $this->request->query->getInt('offset', 0);
            $this->articulos = $this->familia->get_articulos($offset);
        }

        $this->madres = $this->familia->madres();
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

    private function editarFamilia(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $codfamilia = (string) $request->request->get('scodfamilia', '');
        $fam = $this->familia->get($codfamilia);

        if (!$fam) {
            $fam = new \familia();
            $fam->codfamilia = $codfamilia;
        }

        $fam->descripcion = (string) $request->request->get('sdescripcion', '');

        $madre = $request->request->get('smadre');
        $fam->madre = ($madre !== null && $madre !== '') ? (string) $madre : null;

        if ($fam->save()) {
            $this->new_message('Familia ' . $fam->descripcion . ' guardada correctamente.');
            $this->familia = $fam;
            return;
        }

        $this->new_error_msg('¡Imposible guardar la familia!');
    }

    private function eliminarFamilia(Request $request): void
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
            $this->new_error_msg('En el modo demo no puedes eliminar familias. Otro usuario podría necesitarlas.');
            return;
        }

        $cod = (string) $request->request->get('delete', '');
        $fam = $this->familia->get($cod);

        if (!$fam) {
            $this->new_error_msg('¡Familia no encontrada!');
            return;
        }

        if ($fam->delete()) {
            $this->new_message('Familia ' . $fam->descripcion . ' eliminada correctamente.');
            $this->redirect('ventas_familias');
            return;
        }

        $this->new_error_msg('¡Imposible eliminar la familia!');
    }
}
