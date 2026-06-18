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
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use Symfony\Component\HttpFoundation\Request;

class VentasArticulos extends PageController
{
    public array $resultados = [];
    public ?\articulo $articulo = null;
    public array $familias = [];
    public array $fabricantes = [];
    public bool $allow_delete = false;
    public int $offset = 0;
    public int $total_resultados = 0;
    public const ITEMS_PER_PAGE = 50;

    public function __construct()
    {
        parent::__construct('VentasArticulos');
        $this->setTemplate('ventas_articulos');
        $this->loadExtensions();
        $this->allow_delete = $this->user->admin || $this->user->allow_delete_on($this->getPageData()['name']);
    }

    public function getPageData(): array
    {
        return [
            'name' => 'ventas_articulos',
            'title' => 'Artículos',
            'menu' => 'ventas',
            'showonmenu' => true,
            'ordernum' => 120,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $this->articulo = new \articulo();

        // Handle new article creation
        if ($this->request->request->has('nreferencia')) {
            $this->nuevoArticulo($this->request);
        }

        // Handle pagination
        $this->offset = (int) $this->request->query->get('offset', 0);

        // Get filter parameters
        $search = $this->request->query->get('search', '');
        $codfamilia = $this->request->query->get('codfamilia', '');
        $codfabricante = $this->request->query->get('codfabricante', '');
        $con_stock = $this->request->query->get('con_stock', '') === 'TRUE';
        $bloqueados = $this->request->query->get('bloqueados', '') === 'TRUE';

        // Load search results with filters
        if ($search !== '' || $codfamilia !== '' || $codfabricante !== '' || $con_stock || $bloqueados) {
            $this->resultados = $this->articulo->search(
                (string) $search,
                $this->offset,
                (string) $codfamilia,
                $con_stock,
                (string) $codfabricante,
                $bloqueados
            );
        } else {
            $this->resultados = $this->articulo->all($this->offset);
        }

        // Load filter options
        $this->loadFilterOptions();
    }

    private function nuevoArticulo(Request $request): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $referencia = (string) $request->request->get('nreferencia', '');
        $descripcion = (string) $request->request->get('ndescripcion', '');
        $codfamilia = $request->request->get('ncodfamilia');
        $codfabricante = $request->request->get('ncodfabricante');
        $pvp = (float) $request->request->get('npvp', 0);

        if ($referencia === '' || $descripcion === '') {
            $this->new_error_msg('Debes indicar referencia y descripción.');
            return;
        }

        // Check if article already exists
        if ($this->articulo->get($referencia)) {
            $this->new_error_msg('Ya existe un artículo con la referencia ' . $referencia);
            return;
        }

        $art = new \articulo();
        $art->referencia = $referencia;
        $art->descripcion = $descripcion;
        $art->codfamilia = ($codfamilia !== null && $codfamilia !== '') ? (string) $codfamilia : null;
        $art->codfabricante = ($codfabricante !== null && $codfabricante !== '') ? (string) $codfabricante : null;
        $art->pvp = $pvp;

        if ($art->save()) {
            $this->new_message('Artículo ' . $art->referencia . ' guardado correctamente.');
        } else {
            $this->new_error_msg('¡Imposible guardar el artículo!');
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

    private function loadFilterOptions(): void
    {
        // Load families for filter dropdown
        $familia = new \familia();
        $this->familias = $familia->all();

        // Load manufacturers for filter dropdown
        $fabricante = new \fabricante();
        $this->fabricantes = $fabricante->all();
    }

    public function getPaginationUrl(int $offset): string
    {
        $params = $this->request->query->all();
        $params['offset'] = $offset;
        $baseUrl = $this->url();
        $separator = (strpos($baseUrl, '?') !== false) ? '&' : '?';
        return $baseUrl . $separator . http_build_query($params);
    }

    public function hasMoreResults(): bool
    {
        return count($this->resultados) >= self::ITEMS_PER_PAGE;
    }
}
