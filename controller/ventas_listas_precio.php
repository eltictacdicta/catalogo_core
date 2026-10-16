<?php
declare(strict_types=1);
/**
 * This file is part of FSFramework originally based on Facturascript 2017
 * Copyright (C) 2026 Javier Trujillo <mistertekcom@gmail.com>
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

require_once FS_FOLDER . '/base/fs_controller.php';
require_once FS_FOLDER . '/base/fs_settings.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoOptions.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';

/**
 * Admin-gated CRUD page for the catalog price lists (multitarifa task 3.2,
 * R-MT-004, design D6).
 *
 * Legacy fs_controller page (catalogo_excel_settings precedent): constructor
 * args folder='ventas', admin=TRUE, shmenu=TRUE gate the page to admins at
 * the framework level and register it in the ventas menu.
 *
 * POST is CSRF-checked before any write and action-whitelisted
 * (create/edit/activate/deactivate/set_default/delete) over
 * catalogo_lista_precio. The page is the ONLY place where inactive lists
 * remain visible/editable (R-MT-001 reactivation point). It NEVER rewrites
 * articulos.pvp (R-MT-003).
 */
class ventas_listas_precio extends fs_controller
{
    /** @var list<\FSFramework\model\catalogo_lista_precio> all lists (inactive included) */
    public array $listas = [];

    /** Multi-tariff master flag: hides the CRUD UI when off (R-CO-003). */
    public bool $multi_tariff = false;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Listas de precio', 'ventas', TRUE, TRUE);
    }

    protected function private_core()
    {
        $this->multi_tariff = (new \FSFramework\Plugins\catalogo_core\Services\CatalogoOptions())->multiTariffEnabled();
        $this->listas = $this->loadListas();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!$this->isCsrfValid()) {
            $this->new_error_msg('Token de seguridad inválido.');
            return;
        }

        $action = (string) ($_POST['action'] ?? '');
        switch ($action) {
            case 'create':
            case 'edit':
                $this->saveList($action === 'create');
                break;

            case 'activate':
            case 'deactivate':
                $this->setActive($action === 'activate');
                break;

            case 'set_default':
                $this->setDefault();
                break;

            case 'delete':
                $this->deleteList();
                break;

            default:
                $this->new_error_msg('Acción no válida.');
        }

        $this->listas = $this->loadListas();
    }

    /**
     * @return list<\FSFramework\model\catalogo_lista_precio>
     */
    private function loadListas(): array
    {
        return (new \FSFramework\model\catalogo_lista_precio())->all();
    }

    private function saveList(bool $create): void
    {
        $lista = new \FSFramework\model\catalogo_lista_precio();

        if (!$create) {
            $found = $lista->get((string) ($_POST['codlista'] ?? ''));
            if ($found === false) {
                $this->new_error_msg('Lista de precio no encontrada.');
                return;
            }
            $lista = $found;
        }

        $lista->codlista = (string) ($_POST['codlista'] ?? $lista->codlista);
        $lista->nombre = $this->no_html(trim((string) ($_POST['nombre'] ?? '')));
        $lista->coddivisa = (string) ($_POST['coddivisa'] ?? $lista->coddivisa);
        $lista->activa = $this->str2bool((string) ($_POST['activa'] ?? 'TRUE'));

        if ($lista->save()) {
            $this->new_message('Lista de precio guardada correctamente.');
        } else {
            $this->new_error_msg('No se pudo guardar la lista de precio.');
        }
    }

    private function setActive(bool $activa): void
    {
        $lista = $this->findPostedList();
        if ($lista === null) {
            return;
        }

        $lista->activa = $activa;
        if ($lista->save()) {
            $this->new_message($activa ? 'Lista activada.' : 'Lista desactivada.');
        } else {
            $this->new_error_msg('No se pudo cambiar el estado de la lista.');
        }
    }

    private function setDefault(): void
    {
        $lista = $this->findPostedList();
        if ($lista === null) {
            return;
        }

        $lista->por_defecto = true;
        $lista->activa = true;
        if ($lista->save()) {
            $this->new_message('Lista marcada como por defecto.');
        } else {
            $this->new_error_msg('No se pudo marcar la lista como por defecto.');
        }
    }

    private function deleteList(): void
    {
        $lista = $this->findPostedList();
        if ($lista === null) {
            return;
        }

        if ($lista->delete()) {
            $this->new_message('Lista de precio eliminada.');
        } else {
            $this->new_error_msg('No se pudo eliminar la lista de precio.');
        }
    }

    private function findPostedList(): ?\FSFramework\model\catalogo_lista_precio
    {
        $lista = new \FSFramework\model\catalogo_lista_precio();
        $found = $lista->get((string) ($_POST['codlista'] ?? ''));
        if ($found === false) {
            $this->new_error_msg('Lista de precio no encontrada.');

            return null;
        }

        return $found;
    }
}
