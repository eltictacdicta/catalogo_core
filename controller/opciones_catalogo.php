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
require_once FS_FOLDER . '/model/fs_rol.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoOptions.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoRoleListNormalizer.php';

/**
 * Admin-only general options page for catalogo_core (multitarifa task 3.4,
 * R-CO-004, design D6). Replaces the removed catalogo_excel_settings page:
 * edits ALL CatalogoOptions-managed options in one form (multi-tariff flag,
 * role-groups master flag, Excel granted roles).
 *
 * Legacy fs_controller page; POST is CSRF-checked before persistence and
 * input-whitelisted to the three option fields; writes go exclusively
 * through CatalogoOptions (sole writer of the catalogo_core.* keys).
 */
class opciones_catalogo extends fs_controller
{
    /** @var list<array{codrol:string,descripcion:string}> all roles for the checkbox list */
    public array $roles = [];

    /** @var list<string> currently granted codrols (checked state) */
    public array $granted_codes = [];

    public bool $multi_tariff = false;
    public bool $groups_enabled = false;

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Opciones de catálogo', 'admin', TRUE, TRUE);
    }

    protected function private_core()
    {
        $options = new \FSFramework\Plugins\catalogo_core\Services\CatalogoOptions();

        $this->roles = $this->loadRoles();
        $this->multi_tariff = $options->multiTariffEnabled();
        $this->groups_enabled = $options->groupsEnabled();
        $raw = $options->excelRoles();
        $this->granted_codes = is_string($raw) && $raw !== '' ? explode(',', $raw) : [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->isCsrfValid()) {
                $this->new_error_msg('Token de seguridad inválido.');
                return;
            }

            $multiTariff = $this->str2bool((string) ($_POST['multi_tariff'] ?? 'FALSE'));
            $groupsEnabled = $this->str2bool((string) ($_POST['groups_enabled'] ?? 'FALSE'));

            $posted = $_POST['catalogo_excel_roles'] ?? [];
            $rawPosted = is_array($posted)
                ? implode(',', array_map('strval', $posted))
                : (string) $posted;
            $normalized = \FSFramework\Plugins\catalogo_core\Services\CatalogoRoleListNormalizer::normalize(
                $rawPosted,
                array_column($this->roles, 'codrol')
            );

            $options->setMultiTariff($multiTariff);
            $options->setGroupsEnabled($groupsEnabled);
            $options->setExcelRoles($normalized);

            $this->multi_tariff = $multiTariff;
            $this->groups_enabled = $groupsEnabled;
            $this->granted_codes = $normalized === '' ? [] : explode(',', $normalized);

            $this->new_message('Configuración guardada.');
        }
    }

    /**
     * @return list<array{codrol:string,descripcion:string}>
     */
    private function loadRoles(): array
    {
        $roles = [];
        foreach ((new fs_rol())->all() as $item) {
            $roles[] = [
                'codrol' => (string) $item->codrol,
                'descripcion' => (string) $item->descripcion,
            ];
        }

        return $roles;
    }
}
