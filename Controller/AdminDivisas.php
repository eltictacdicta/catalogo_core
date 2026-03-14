<?php
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

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/divisa.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use Symfony\Component\HttpFoundation\Request;

class AdminDivisas extends PageController
{
    public array $divisas = [];
    public ?\divisa $divisa = null;
    public bool $allow_delete = false;

    public function __construct()
    {
        parent::__construct('AdminDivisas');
        $this->setTemplate('admin_divisas');
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->loadExtensions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'admin_divisas',
            'title' => 'Divisas',
            'menu' => 'admin',
            'showonmenu' => true,
            'ordernum' => 130,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $this->divisa = new \divisa();

        if ($this->request->request->has('coddivisa')) {
            $this->editarDivisa($this->request);
        } elseif ($this->request->query->has('delete')) {
            $this->eliminarDivisa($this->request);
        }

        $this->divisas = $this->divisa->all();
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

    private function editarDivisa(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $coddivisa = (string) $request->request->get('coddivisa', '');
        $div0 = $this->divisa->get($coddivisa);

        if (!$div0) {
            $div0 = new \divisa();
            $div0->coddivisa = $coddivisa;
        }

        $div0->simbolo = (string) $request->request->get('simbolo', '');
        $div0->descripcion = (string) $request->request->get('descripcion', '');
        $div0->codiso = (string) $request->request->get('codiso', '');
        $div0->tasaconv = $request->request->getDigits('tasaconv') !== ''
            ? (float) $request->request->get('tasaconv')
            : 1.0;
        $div0->tasaconv_compra = $request->request->getDigits('tasaconv_compra') !== ''
            ? (float) $request->request->get('tasaconv_compra')
            : $div0->tasaconv;

        if ($div0->save()) {
            $this->new_message('Divisa ' . $div0->coddivisa . ' guardada correctamente.');
            return;
        }

        $this->new_error_msg('Error al guardar la divisa.');
    }

    private function eliminarDivisa(Request $request): void
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar divisas.');
            return;
        }

        $coddivisa = (string) $request->query->get('delete', '');
        $div0 = $this->divisa->get($coddivisa);

        if (!$div0) {
            $this->new_error_msg('Divisa no encontrada.');
            return;
        }

        if (!$this->user->admin) {
            $this->new_error_msg('Sólo un administrador puede eliminar divisas.');
            return;
        }

        if ($div0->delete()) {
            $this->new_message('Divisa ' . $div0->coddivisa . ' eliminada correctamente.');
            return;
        }

        $this->new_error_msg('Error al eliminar la divisa ' . $div0->coddivisa . '.');
    }
}