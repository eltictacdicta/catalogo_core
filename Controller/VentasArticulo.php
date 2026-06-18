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

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/articulo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/fabricante.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/impuesto.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use Symfony\Component\HttpFoundation\Request;

class VentasArticulo extends PageController
{
    public ?\articulo $articulo = null;
    public array $familias = [];
    public array $fabricantes = [];
    public array $impuestos = [];
    public bool $allow_delete = false;

    public function __construct()
    {
        parent::__construct('VentasArticulo');
        $this->setTemplate('ventas_articulo');
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
        $this->loadExtensions();
    }

    public function getPageData(): array
    {
        return [
            'name' => 'ventas_articulo',
            'title' => 'Artículo',
            'menu' => 'ventas',
            'showonmenu' => false,
            'ordernum' => 125,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $this->articulo = new \articulo();

        $ref = $this->request->query->get('ref');
        if ($ref !== null && $ref !== '') {
            $art = $this->articulo->get((string) $ref);
            if ($art) {
                $this->articulo = $art;
            } else {
                $this->new_error_msg('Artículo no encontrado.');
                return;
            }
        }

        if ($this->request->request->has('sreferencia')) {
            $this->editarArticulo($this->request);
        } elseif ($this->request->query->has('delete') && $this->allow_delete) {
            $this->eliminarArticulo($this->request);
        }

        $this->loadFilterOptions();
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

    private function loadFilterOptions(): void
    {
        $familia = new \familia();
        $this->familias = $familia->all();

        $fabricante = new \fabricante();
        $this->fabricantes = $fabricante->all();

        $impuesto = new \impuesto();
        $this->impuestos = $impuesto->all();
    }

    private function editarArticulo(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $referencia = (string) $request->request->get('sreferencia', '');
        $art = $this->articulo->get($referencia);

        if (!$art) {
            $art = new \articulo();
            $art->referencia = $referencia;
        }

        $art->descripcion = (string) $request->request->get('sdescripcion', '');
        $art->pvp = (float) $request->request->get('spvp', 0);

        $codfamilia = $request->request->get('scodfamilia');
        $art->codfamilia = ($codfamilia !== null && $codfamilia !== '') ? (string) $codfamilia : null;

        $codfabricante = $request->request->get('scodfabricante');
        $art->codfabricante = ($codfabricante !== null && $codfabricante !== '') ? (string) $codfabricante : null;

        $codimpuesto = $request->request->get('scodimpuesto');
        $art->codimpuesto = ($codimpuesto !== null && $codimpuesto !== '') ? (string) $codimpuesto : null;

        $art->stockfis = (float) $request->request->get('sstockfis', 0);
        $art->stockmin = (float) $request->request->get('sstockmin', 0);
        $art->stockmax = (float) $request->request->get('sstockmax', 0);

        $art->bloqueado = $request->request->getBoolean('sbloqueado');
        $art->sevende = $request->request->getBoolean('ssevende', true);
        $art->secompra = $request->request->getBoolean('ssecompra', true);
        $art->publico = $request->request->getBoolean('spublico');
        $art->nostock = $request->request->getBoolean('snostock');

        $art->observaciones = (string) $request->request->get('sobservaciones', '');
        $art->codbarras = (string) $request->request->get('scodbarras', '');
        $art->equivalencia = (string) $request->request->get('sequivalencia', '');
        $art->partnumber = (string) $request->request->get('spartnumber', '');

        if ($art->save()) {
            $this->new_message('Artículo ' . $art->referencia . ' guardado correctamente.');
            $this->articulo = $art;
            return;
        }

        $this->new_error_msg('¡Imposible guardar el artículo!');
    }

    private function eliminarArticulo(Request $request): void
    {
        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar en esta página.');
            return;
        }

        if (defined('FS_DEMO') && FS_DEMO) {
            $this->new_error_msg('En el modo demo no puedes eliminar artículos. Otro usuario podría necesitarlos.');
            return;
        }

        $ref = (string) $request->query->get('delete', '');
        $art = $this->articulo->get($ref);

        if (!$art) {
            $this->new_error_msg('¡Artículo no encontrado!');
            return;
        }

        if ($art->delete()) {
            $this->new_message('Artículo ' . $art->referencia . ' eliminado correctamente.');
            $this->redirect('ventas_articulos');
            return;
        }

        $this->new_error_msg('¡Imposible eliminar el artículo!');
    }
}
