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

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/almacen.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/pais.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use Symfony\Component\HttpFoundation\Request;

class AdminAlmacenes extends PageController
{
    public array $almacenes = [];
    public ?\pais $pais = null;
    public bool $allow_delete = false;

    public function __construct()
    {
        parent::__construct('AdminAlmacenes');
        $this->setTemplate('admin_almacenes');
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->loadExtensions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'admin_almacenes',
            'title' => 'Almacenes',
            'menu' => 'admin',
            'showonmenu' => true,
            'ordernum' => 140,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $almacen = new \almacen();
        $this->pais = new \pais();

        if ($this->request->request->has('scodalmacen')) {
            $this->editarAlmacen($this->request, $almacen);
        } elseif ($this->request->query->has('delete')) {
            $this->eliminarAlmacen($this->request, $almacen);
        } else {
            $this->guardarOpcionesAvanzadas($this->request);
        }

        $this->almacenes = $almacen->all();
        $this->actualizarMultiAlmacen();
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

    private function editarAlmacen(Request $request, \almacen $almacen): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $codalmacen = (string) $request->request->get('scodalmacen', '');
        $al0 = $almacen->get($codalmacen);

        if (!$al0) {
            $al0 = new \almacen();
            $al0->codalmacen = $codalmacen;
        }

        $al0->nombre = (string) $request->request->get('snombre', '');
        $al0->codpais = (string) $request->request->get('scodpais', '');
        $al0->provincia = (string) $request->request->get('sprovincia', '');
        $al0->poblacion = (string) $request->request->get('spoblacion', '');
        $al0->direccion = (string) $request->request->get('sdireccion', '');
        $al0->codpostal = (string) $request->request->get('scodpostal', '');
        $al0->telefono = (string) $request->request->get('stelefono', '');
        $al0->fax = (string) $request->request->get('sfax', '');
        $al0->contacto = (string) $request->request->get('scontacto', '');

        if ($al0->save()) {
            $this->new_message('Almacén ' . $al0->codalmacen . ' guardado correctamente.');
            return;
        }

        $this->new_error_msg('¡Imposible guardar el almacén!');
    }

    private function eliminarAlmacen(Request $request, \almacen $almacen): void
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar almacenes.');
            return;
        }

        $codalmacen = (string) $request->query->get('delete', '');
        $al0 = $almacen->get($codalmacen);

        if (!$al0) {
            $this->new_error_msg('¡Almacén no encontrado!');
            return;
        }

        if (!$this->user->admin) {
            $this->new_error_msg('Solo un administrador puede eliminar un almacén.');
            return;
        }

        if ($al0->delete()) {
            $this->new_message('Almacén ' . $al0->codalmacen . ' eliminado correctamente.');
            return;
        }

        $this->new_error_msg('¡Imposible eliminar el almacén!');
    }

    private function guardarOpcionesAvanzadas(Request $request): void
    {
        $guardar = false;

        foreach ($GLOBALS['config2'] as $key => $value) {
            if ($request->request->has($key)) {
                $GLOBALS['config2'][$key] = $request->request->get($key);
                $guardar = true;
            }
        }

        if (!$guardar) {
            return;
        }

        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $configPath = 'tmp/' . FS_TMP_NAME . 'config2.ini';
        $file = fopen($configPath, 'w');
        if (!$file) {
            $this->new_error_msg('Error al guardar la configuración.');
            return;
        }

        foreach ($GLOBALS['config2'] as $key => $value) {
            if (is_numeric($value)) {
                fwrite($file, $key . " = " . $value . ";\n");
                continue;
            }

            fwrite($file, $key . " = '" . $value . "';\n");
        }

        fclose($file);
        $this->new_message('Datos guardados correctamente.');
    }

    private function actualizarMultiAlmacen(): void
    {
        $fsvar = new \fs_var();
        if (count($this->almacenes) > 1) {
            $fsvar->simple_save('multi_almacen', true);
            return;
        }

        $fsvar->simple_delete('multi_almacen');
    }
}