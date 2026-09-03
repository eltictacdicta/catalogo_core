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
require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticleExcelAccessPolicy.php';

/**
 * Normalize a raw role-list value into the persisted comma-separated list
 * (AD-6 save pipeline): split on ',', trim, drop empties, validate each
 * codrol against the existing fs_roles, dedupe preserving first-seen order,
 * implode ','.
 *
 * Unknown codrols are dropped at save time (save-time whitelist); the policy
 * also intersects at read time (deleted-role edge, AD-6).
 *
 * @param string $raw Raw POST value (comma-separated codrols)
 * @param list<string> $existingCodrols Existing fs_roles.codrol values
 * @return string Normalized comma-separated list
 */
function catalogo_excel_roles_normalize(string $raw, array $existingCodrols): string
{
    $valid = array_flip($existingCodrols);
    $kept = [];
    foreach (explode(',', $raw) as $piece) {
        $codrol = trim($piece);
        if ($codrol === '' || !isset($valid[$codrol])) {
            continue;
        }
        if (!in_array($codrol, $kept, true)) {
            $kept[] = $codrol;
        }
    }

    return implode(',', $kept);
}

/**
 * Admin-only settings page for the article catalog Excel import/export role
 * grants (change catalogo-excel-access-control, AD-7).
 *
 * Legacy fs_controller page (NOT PageController — whose `admin || have_access_to`
 * gate is the tautology this change removes). Constructor args folder='admin',
 * admin=TRUE, shmenu=TRUE block non-admin users at the framework level and
 * register the page in the admin menu (tpvmod_settings precedent).
 *
 * POST is CSRF-checked, input-whitelisted to the single `catalogo_excel_roles`
 * field, normalized per AD-6 and persisted through fs_settings set()+save()
 * under key catalogo_excel_roles (ArticleExcelAccessPolicy::SETTING_KEY).
 */
class catalogo_excel_settings extends fs_controller
{
    /** @var list<array{codrol:string,descripcion:string}> all roles for the checkbox list */
    public array $roles = [];

    /** @var list<string> currently granted codrols (checked state) */
    public array $granted_codes = [];

    /** @var list<array{codrol:string,descripcion:string}> granted roles with descripcion (summary) */
    public array $granted_roles = [];

    public function __construct()
    {
        parent::__construct(__CLASS__, 'Excel import/export access', 'admin', TRUE, TRUE);
    }

    protected function private_core()
    {
        $settings = new fs_settings();
        $policy = new ArticleExcelAccessPolicy();

        $this->roles = $this->loadRoles();
        $this->granted_codes = $policy->grantedRoles();
        $this->granted_roles = $this->describeRoles($this->granted_codes);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->isCsrfValid()) {
                $this->new_error_msg('Token de seguridad inválido.');
                return;
            }

            // Input whitelist: only the catalogo_excel_roles field is read.
            $posted = $_POST['catalogo_excel_roles'] ?? [];
            $raw = is_array($posted)
                ? implode(',', array_map('strval', $posted))
                : (string) $posted;

            $normalized = catalogo_excel_roles_normalize($raw, array_column($this->roles, 'codrol'));

            $settings->set(ArticleExcelAccessPolicy::SETTING_KEY, $normalized);
            if ($settings->save()) {
                $this->granted_codes = $normalized === '' ? [] : explode(',', $normalized);
                $this->granted_roles = $this->describeRoles($this->granted_codes);
                $this->new_message('Configuración guardada.');
            } else {
                $this->new_error_msg('No se pudo guardar la configuración.');
            }
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

    /**
     * @param list<string> $codrols
     * @return list<array{codrol:string,descripcion:string}>
     */
    private function describeRoles(array $codrols): array
    {
        $byCode = [];
        foreach ($this->roles as $role) {
            $byCode[$role['codrol']] = $role['descripcion'];
        }

        $granted = [];
        foreach ($codrols as $codrol) {
            if (isset($byCode[$codrol])) {
                $granted[] = ['codrol' => $codrol, 'descripcion' => $byCode[$codrol]];
            }
        }

        return $granted;
    }
}