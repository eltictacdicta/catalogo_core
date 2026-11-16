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
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoOptions.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoRoleListNormalizer.php';

/**
 * Admin-only general options page for catalogo_core (multitarifa task 3.4,
 * R-CO-004, design D6). Replaces the removed catalogo_excel_settings page:
 * edits ALL CatalogoOptions-managed options in one form (multi-tariff flag,
 * role-groups master flag, Excel granted roles). Also hosts the catalogue
 * language registry (`#idiomas`, GDI-01/GDI-04), posted as a separate form
 * carrying `idioma_action`.
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

    /**
     * Every language in `catalogo_idiomas`, active or not, for the `#idiomas`
     * management table.
     *
     * @var array<int, \FSFramework\model\catalogo_idioma>
     */
    public array $idiomas_todos = [];

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
        $this->load_idiomas();

        // The rest of this legacy page reads superglobals; fall back to them
        // when no Kernel request was injected.
        $request = $this->request ?? \Symfony\Component\HttpFoundation\Request::createFromGlobals();
        if ($this->shouldDispatchIdioma($request)) {
            $this->gestionarIdioma($request);
            $this->load_idiomas();
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!$this->isCsrfValid()) {
                $this->new_error_msg('Token de seguridad inválido.');
                return;
            }

            // Form checkboxes post 'TRUE'/'FALSE'; fs_model::str2bool() only
            // understands DB truthy values ('t'/'1') and is not available here.
            $multiTariff = ($_POST['multi_tariff'] ?? 'FALSE') === 'TRUE';
            $groupsEnabled = ($_POST['groups_enabled'] ?? 'FALSE') === 'TRUE';

            $posted = $_POST['catalogo_excel_roles'] ?? [];
            $rawPosted = is_array($posted)
                ? implode(',', array_map('strval', $posted))
                : (string) $posted;
            $normalized = \FSFramework\Plugins\catalogo_core\Services\CatalogoRoleListNormalizer::normalize(
                $rawPosted,
                array_column($this->roles, 'codrol')
            );

            $saved = $options->saveAll($multiTariff, $groupsEnabled, $normalized);

            if ($saved) {
                $this->multi_tariff = $multiTariff;
                $this->groups_enabled = $groupsEnabled;
                $this->granted_codes = $normalized === '' ? [] : explode(',', $normalized);

                $this->new_message('Configuración guardada.');
            } else {
                $this->new_error_msg('No se pudo guardar la configuración. Revisá los permisos de escritura de tmp/.');
            }
        }
    }

    protected function idioma_model()
    {
        return new \FSFramework\model\catalogo_idioma();
    }

    private function load_idiomas(): void
    {
        $idioma = $this->idioma_model();
        $idioma->ensure_defaults();
        $this->idiomas_todos = (array) $idioma->all();
    }

    /**
     * Language mutations are POST-only: the trigger field is read from the POST
     * body, so a `?idioma_action=` in the query string never dispatches anything
     * (GDI-04).
     */
    private function shouldDispatchIdioma(\Symfony\Component\HttpFoundation\Request $request): bool
    {
        return $request->isMethod('POST') && $request->request->has('idioma_action');
    }

    /**
     * Administrator-only, POST-only, CSRF-validated language mutations (GDI-04,
     * D-06). The guard is per-action, independent of who can open the page.
     */
    private function gestionarIdioma(\Symfony\Component\HttpFoundation\Request $request): void
    {
        if (!$this->isCsrfValid()) {
            $this->new_error_msg(\FSFramework\Translation\FSTranslator::trans('language-csrf-invalid'));
            return;
        }

        if (empty($this->user->admin)) {
            $this->new_error_msg(\FSFramework\Translation\FSTranslator::trans('language-admin-only'));
            return;
        }

        $codidioma = \FSFramework\model\catalogo_idioma::normalize_code($request->request->get('codidioma', ''));
        if ($codidioma === '') {
            return;
        }

        $idioma = $this->idioma_model();

        switch ((string) $request->request->get('idioma_action', '')) {
            case 'save':
                $this->guardarIdioma($idioma, $codidioma, (string) $request->request->get('nombre', ''));
                return;

            case 'toggle_active':
                $this->alternarIdioma($idioma, $codidioma);
                return;

            case 'set_default':
                if ($idioma->set_default($codidioma)) {
                    $this->new_message(\FSFramework\Translation\FSTranslator::trans('language-default-changed'));
                }
                return;

            case 'delete':
                $this->borrarIdioma($idioma, $codidioma);
                return;
        }
    }

    /**
     * Creates or renames a language through the registry. The persisted flags of
     * an existing row are preserved; a new row starts active and non-default.
     */
    private function guardarIdioma(
        \FSFramework\model\catalogo_idioma $idioma,
        string $codidioma,
        string $nombre
    ): void {
        $current = $this->idiomaEnMemoria($codidioma);

        $idioma->codidioma = $codidioma;
        $idioma->nombre = $nombre;
        $idioma->activo = $current ? (bool) $current->activo : true;
        $idioma->por_defecto = $current ? (bool) $current->por_defecto : false;

        if ($idioma->save()) {
            $this->new_message(\FSFramework\Translation\FSTranslator::trans($current ? 'language-updated' : 'language-created'));
        }
    }

    /**
     * Flips the active flag through the registry, which owns the "the default
     * cannot be deactivated" invariant (slice 1, GDI-02).
     */
    private function alternarIdioma(\FSFramework\model\catalogo_idioma $idioma, string $codidioma): void
    {
        $current = $this->idiomaEnMemoria($codidioma);
        if ($current === null) {
            return;
        }

        $idioma->codidioma = $codidioma;
        $idioma->nombre = (string) $current->nombre;
        $idioma->activo = !(bool) $current->activo;
        $idioma->por_defecto = (bool) $current->por_defecto;

        if ($idioma->save()) {
            $this->new_message(\FSFramework\Translation\FSTranslator::trans('language-updated'));
        }
    }

    /**
     * Deletes a language through the registry, which owns the "default and last
     * language cannot be deleted" guards and the description cleanup
     * (slice 1, GDI-03).
     */
    private function borrarIdioma(\FSFramework\model\catalogo_idioma $idioma, string $codidioma): void
    {
        $current = $this->idiomaEnMemoria($codidioma);
        if ($current === null) {
            return;
        }

        $idioma->codidioma = $codidioma;
        $idioma->por_defecto = (bool) $current->por_defecto;

        if ($idioma->delete()) {
            $this->new_message(\FSFramework\Translation\FSTranslator::trans('language-deleted'));
        }
    }

    /**
     * The loaded registry row for a code, or null. The panel renders from
     * `idiomas_todos`, so the mutation target is already in memory.
     */
    private function idiomaEnMemoria(string $codidioma): ?\FSFramework\model\catalogo_idioma
    {
        foreach ($this->idiomas_todos as $idioma) {
            if ((string) ($idioma->codidioma ?? '') === $codidioma) {
                return $idioma;
            }
        }

        return null;
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
