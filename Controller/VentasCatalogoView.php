<?php
declare(strict_types=1);
/**
 * This file is part of catalogo_core
 * Copyright (C) 2026 FSFramework Team
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

require_once FS_FOLDER . '/src/Controller/PageController.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoOptions.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoViewProvider.php';

use FSFramework\Controller\PageController;
use FSFramework\Plugins\catalogo_core\Services\CatalogoOptions;
use FSFramework\Plugins\catalogo_core\Services\CatalogoViewProvider;

/**
 * Catalog view of one price list (familias-jerarquia task 4.1, D5 / R-FJ-004).
 *
 * Read-only page: it renders the visible family tree of the selected list plus
 * the articles assigned to each visible family. Data resolution lives in
 * CatalogoViewProvider; this class only wires the request, the page metadata
 * and the template.
 *
 * The capability is opt-in (D3): with `familyStructureEnabled()` off the page
 * still renders, reports that the capability is disabled, and queries nothing.
 *
 * The absorbed UI renders entirely from catalogo_core's own template, so it does
 * not depend on the plugin's frozen view hooks (R-FJ-007).
 */
class VentasCatalogoView extends PageController
{
    /** Capability flag reported to the template (D3 lifecycle gate). */
    public bool $enabled = false;

    /** @var list<array{codlista: string, nombre: string}> */
    public array $lists = [];

    /** Selected price list, resolved by the provider (never a legacy tarifa code). */
    public ?string $codlista = null;

    public string $list_name = '';

    /** @var list<array<string, mixed>> */
    public array $tree = [];

    /** True when the selected list has no visible family node. */
    public bool $empty = true;

    public function __construct()
    {
        parent::__construct('VentasCatalogoView');
        $this->setTemplate('ventas_catalogo_view');
    }

    public function getPageData(): array
    {
        return [
            'name' => 'ventas_catalogo_view',
            'title' => 'Catálogo',
            'menu' => 'ventas',
            'showonmenu' => (bool) ($this->user->admin ?? false),
            'ordernum' => 130,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $requested = $this->request->query->get('codlista');
        $result = $this->createProvider()->build(
            is_string($requested) && $requested !== '' ? $requested : null
        );

        $this->enabled = $result['enabled'];
        $this->lists = $result['lists'];
        $this->codlista = $result['codlista'];
        $this->list_name = $result['list_name'];
        $this->tree = $result['tree'];
        $this->empty = $result['empty'];
    }

    /**
     * Seam for tests and subclasses (the page owns no data logic of its own).
     */
    protected function createProvider(): CatalogoViewProvider
    {
        return new CatalogoViewProvider($this->db, new CatalogoOptions());
    }
}
