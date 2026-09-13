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

require_once FS_FOLDER . '/plugins/catalogo_core/extras/VentasOpcionalesListTrait.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;

/**
 * Canonical opcionales management page (spec opcionales-management OUM-01).
 *
 * Stays on `PageController` (design AD-2) and absorbs the legacy
 * `tarif_opcionales` rich list through `VentasOpcionalesListTrait`: tarifa
 * selector, filters, per-tarifa state/price, CSRF-guarded toggles, creation,
 * delete, Excel export and native pagination.
 */
class VentasOpcionales extends PageController
{
    use \VentasOpcionalesListTrait;

    /**
     * Per-row state toggles dispatched by `privateCore()`.
     */
    public const TOGGLE_ACTIONS = ['toggle_activa', 'toggle_en_catalogo', 'toggle_en_tarifa'];

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
            'menu' => 'catalogo',
            'showonmenu' => true,
            'ordernum' => 108,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        // Load the active-tarifa context before any create/delete dispatch so
        // the create-modal per-tarifa prices/percentage persist (the mutation
        // reads $this->tarifas / $this->tarifa_seleccionada).
        $this->init_opcionales_list();

        $action = (string) $this->request->query->get('action', '');

        if (in_array($action, self::TOGGLE_ACTIONS, true)) {
            $this->toggle_opcional_state($action);

            return;
        }

        if ($action === 'export_excel_opcionales') {
            $this->export_excel_opcionales();

            return;
        }

        if ($this->request->request->has('delete')) {
            $this->delete_opcional();
        }

        if ($this->request->request->has('codigo') && $this->request->request->has('nombre')) {
            $this->new_opcional();
        }

        $this->load_opcionales_state_cache();
        $this->load_precios_cache();
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
}
