<?php
declare(strict_types=1);
/**
 * This file is part of the catalogo_core plugin
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

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_caracteristica_valor.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Event/CaracteristicaPermissionFilterEvent.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaResolver.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaValorStore.php';
require_once FS_FOLDER . '/model/fs_extension.php';
require_once FS_FOLDER . '/src/Controller/PageController.php';

use FSFramework\Controller\PageController;
use FSFramework\Event\FSEventDispatcher;
use FSFramework\Plugins\catalogo_core\Event\CaracteristicaPermissionFilterEvent;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver;

/**
 * Feature management panel (CAR-18).
 *
 * Serves definition create/edit, flag editing and predefined-value management
 * under menu `catalogo`. Every mutation validates CSRF first and deletion goes
 * through the neutral permission filter. The three-scope value assignment
 * actions land with the value store in WU-2.
 */
class VentasCaracteristicas extends PageController
{
    /** @var array<int, \FSFramework\model\catalogo_caracteristica> */
    public array $definiciones = [];

    /** @var array<int, \FSFramework\model\catalogo_caracteristica_valor> */
    public array $valores = [];

    public string $selected_codigo = '';

    public bool $allow_delete = false;

    public string $permissionDenialReason = '';

    public function __construct()
    {
        parent::__construct('VentasCaracteristicas');
        $this->setTemplate('ventas_caracteristicas');
        $this->allow_delete = $this->user->admin
            || $this->user->allow_delete_on($this->getPageData()['name']);
    }

    public function getPageData(): array
    {
        return [
            'name' => 'ventas_caracteristicas',
            'title' => 'Características',
            'menu' => 'catalogo',
            'showonmenu' => true,
            'ordernum' => 109,
        ];
    }

    public function privateCore(&$response, $user, $permissions): void
    {
        $this->load_definiciones();

        $this->dispatch_action((string) $this->request->query->get('action', ''));

        $this->selected_codigo = (string) $this->request->query->get(
            'codigo',
            $this->request->request->get('codigo', '')
        );
        $this->load_valores_seleccionados();
    }

    protected function dispatch_action(string $action): void
    {
        switch ($action) {
            case 'save_definition':
                $this->save_definition();
                return;
            case 'delete_definition':
                $this->delete_definition();
                return;
            case 'toggle_flag':
                $this->toggle_flag();
                return;
            case 'save_catalogo_valor':
                $this->save_catalogo_valor();
                return;
            case 'delete_catalogo_valor':
                $this->delete_catalogo_valor();
                return;
            case 'assign_value':
                $this->assign_value();
                return;
            case 'clear_value':
                $this->clear_value();
                return;
        }
    }

    // =====================================================================
    // Read model
    // =====================================================================

    protected function load_definiciones(): void
    {
        $this->definiciones = (array) $this->caracteristica_model()->all();
    }

    protected function load_valores_seleccionados(): void
    {
        if ($this->selected_codigo === '') {
            $this->valores = [];

            return;
        }

        $definition = $this->caracteristica_model()->get($this->selected_codigo);
        if (!$definition || $definition->id === null) {
            $this->valores = [];

            return;
        }

        $this->valores = (array) $this->valor_model()->all_from_caracteristica((int) $definition->id);
    }

    // =====================================================================
    // Definition CRUD
    // =====================================================================

    protected function save_definition(): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $codigo = trim((string) $this->request->request->get('codigo', ''));

        $existing = $codigo !== '' ? $this->caracteristica_model()->get($codigo) : false;
        $definition = $existing ?: $this->caracteristica_model();

        $tipo = (string) $this->request->request->get('tipo', $definition->tipo ?? 'string');

        // `tipo` is immutable after creation once value rows exist.
        if ($existing && $definition->id !== null && $existing->tipo !== $tipo) {
            $values = $this->valor_model()->all_from_caracteristica((int) $definition->id);
            if ($values !== []) {
                $this->new_error_msg('No se puede cambiar el tipo de una característica con valores.');
                return;
            }
        }

        $definition->codigo = $codigo;
        $definition->nombre = (string) $this->request->request->get('nombre', '');
        $definition->tipo = $tipo;
        $definition->activo = $this->request->request->getBoolean('activo', true);
        $definition->importable = $this->request->request->getBoolean('importable', false);
        $definition->exportable = $this->request->request->getBoolean('exportable', false);
        $definition->listable = $this->request->request->getBoolean('listable', false);
        $definition->orden = (int) $this->request->request->get('orden', 0);
        $definition->valor_defecto = $this->request->request->get('valor_defecto', null);

        if ($definition->save()) {
            $this->new_message('Característica guardada correctamente.');
            return;
        }

        $this->new_error_msg('No se pudo guardar la característica.');
    }

    protected function delete_definition(): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar en esta página.');
            return;
        }

        $codigo = (string) $this->request->request->get('codigo', '');
        $definition = $codigo !== '' ? $this->caracteristica_model()->get($codigo) : false;
        if (!$definition) {
            $this->new_error_msg('Característica no encontrada.');
            return;
        }

        if (!$this->puede_gestionar(
            CaracteristicaPermissionFilterEvent::ACTION_DELETE_DEFINITION,
            $codigo
        )) {
            $this->new_error_msg('No tienes permisos para eliminar esta característica.');
            return;
        }

        if (!$definition->is_deletable()) {
            $this->new_error_msg('No se puede eliminar una característica registrada por un plugin.');
            return;
        }

        if ($definition->delete()) {
            $this->new_message('Característica eliminada correctamente.');
            return;
        }

        $this->new_error_msg('No se pudo eliminar la característica.');
    }

    protected function toggle_flag(): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $codigo = (string) $this->request->request->get('codigo', '');
        $field = (string) $this->request->request->get('field', '');
        $allowed = ['activo', 'importable', 'exportable', 'listable'];

        if (!in_array($field, $allowed, true)) {
            $this->new_error_msg('Campo no permitido.');
            return;
        }

        $definition = $codigo !== '' ? $this->caracteristica_model()->get($codigo) : false;
        if (!$definition) {
            $this->new_error_msg('Característica no encontrada.');
            return;
        }

        $definition->{$field} = !$definition->{$field};

        if ($definition->save()) {
            $this->new_message('Característica actualizada.');
            return;
        }

        $this->new_error_msg('No se pudo actualizar la característica.');
    }

    // =====================================================================
    // Predefined-value management
    // =====================================================================

    protected function save_catalogo_valor(): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $valor = $this->valor_model();
        $idCaracteristica = (int) $this->request->request->get('id_caracteristica', 0);
        if ($idCaracteristica <= 0) {
            $codigo = (string) $this->request->request->get('codigo', '');
            $definition = $codigo !== '' ? $this->caracteristica_model()->get($codigo) : false;
            $idCaracteristica = $definition && $definition->id !== null ? (int) $definition->id : 0;
        }
        $valor->id_caracteristica = $idCaracteristica;
        $valor->valor = (string) $this->request->request->get('valor', '');
        $valor->orden = (int) $this->request->request->get('orden', 0);

        if ($valor->save()) {
            $this->new_message('Valor guardado correctamente.');
            return;
        }

        $this->new_error_msg('No se pudo guardar el valor.');
    }

    protected function delete_catalogo_valor(): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar en esta página.');
            return;
        }

        $id = (int) $this->request->request->get('id', 0);
        $valor = $this->valor_model()->get($id);
        if (!$valor) {
            $this->new_error_msg('Valor no encontrado.');
            return;
        }

        if ($valor->delete()) {
            $this->new_message('Valor eliminado correctamente.');
            return;
        }

        $this->new_error_msg('No se pudo eliminar el valor.');
    }

    // =====================================================================
    // Three-scope assignment (CAR-09, CAR-18 scenario 4)
    // =====================================================================

    protected function assign_value(): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $scope = (string) $this->request->request->get('scope', '');
        $codigo = (string) $this->request->request->get('codigo', '');
        $codtarifa = trim((string) $this->request->request->get('codtarifa', ''));
        $key = (array) $this->request->request->all('key');

        if (!$this->valid_scope_key($scope, $key) || $codigo === '' || $codtarifa === '') {
            $this->new_error_msg('Ámbito, característica y tarifa son obligatorios.');
            return;
        }

        if (!$this->puede_asignar($scope, $codigo, $codtarifa)) {
            $this->new_error_msg('No tienes permisos para asignar en este ámbito.');
            return;
        }

        $idValor = $this->request->request->get('id_valor', null);
        if ($idValor !== null && $idValor !== '') {
            $ok = $this->valor_store()->assign_predefined($scope, $codtarifa, $key, $codigo, (int) $idValor);
        } else {
            $valor = (string) $this->request->request->get('valor', '');
            $ok = $this->valor_store()->assign_custom($scope, $codtarifa, $key, $codigo, $valor);
        }

        if ($ok) {
            $this->new_message('Valor asignado correctamente.');
            return;
        }

        $this->new_error_msg('No se pudo asignar el valor.');
    }

    protected function clear_value(): void
    {
        if (!$this->validateFormToken()) {
            $this->new_error_msg('Token de seguridad inválido. Recarga la página e inténtalo de nuevo.');
            return;
        }

        $scope = (string) $this->request->request->get('scope', '');
        $codigo = (string) $this->request->request->get('codigo', '');
        $codtarifa = trim((string) $this->request->request->get('codtarifa', ''));
        $key = (array) $this->request->request->all('key');

        if (!$this->valid_scope_key($scope, $key) || $codigo === '' || $codtarifa === '') {
            $this->new_error_msg('Ámbito, característica y tarifa son obligatorios.');
            return;
        }

        if (!$this->puede_asignar($scope, $codigo, $codtarifa)) {
            $this->new_error_msg('No tienes permisos para asignar en este ámbito.');
            return;
        }

        if ($this->valor_store()->clear($scope, $codtarifa, $key, $codigo)) {
            $this->new_message('Valor eliminado correctamente.');
            return;
        }

        $this->new_error_msg('No se pudo eliminar el valor.');
    }

    /**
     * @param array<string, mixed> $key
     */
    private function valid_scope_key(string $scope, array $key): bool
    {
        return match ($scope) {
            CaracteristicaResolver::SCOPE_ARTICULO => trim((string) ($key['referencia'] ?? '')) !== '',
            CaracteristicaResolver::SCOPE_FAMILIA => trim((string) ($key['codfamilia'] ?? '')) !== '',
            CaracteristicaResolver::SCOPE_GLOBAL => true,
            default => false,
        };
    }

    private function puede_asignar(string $scope, string $codigo, string $codtarifa): bool
    {
        if ($scope === CaracteristicaResolver::SCOPE_GLOBAL) {
            return $this->puede_gestionar(
                CaracteristicaPermissionFilterEvent::ACTION_ASSIGN_GLOBAL,
                $codigo,
                $codtarifa
            );
        }

        if ($scope === CaracteristicaResolver::SCOPE_FAMILIA) {
            return $this->puede_gestionar(
                CaracteristicaPermissionFilterEvent::ACTION_ASSIGN_FAMILIA,
                $codigo,
                $codtarifa
            );
        }

        return true;
    }

    // =====================================================================
    // Permission gate
    // =====================================================================

    /**
     * Neutral permission gate: an admin short-circuits to allow; otherwise the
     * neutral event resolves the action (zero listeners ⇒ allow).
     */
    protected function puede_gestionar(string $action, string $codigo, string $codtarifa = ''): bool
    {
        if (!empty($this->user->admin)) {
            return true;
        }

        $event = new CaracteristicaPermissionFilterEvent(
            $codigo,
            $action,
            (string) ($this->user->nick ?? ''),
            $codtarifa
        );
        FSEventDispatcher::getInstance()->dispatch($event, CaracteristicaPermissionFilterEvent::NAME);

        $this->permissionDenialReason = $event->isAllowed() ? '' : $event->getDenialReason();

        return $event->isAllowed();
    }

    // =====================================================================
    // Seams
    // =====================================================================

    /**
     * Definition-model seam (unit tests inject a DB-free stub).
     *
     * @return \FSFramework\model\catalogo_caracteristica
     */
    protected function caracteristica_model()
    {
        return new \FSFramework\model\catalogo_caracteristica();
    }

    /**
     * Predefined-value-model seam (unit tests inject a DB-free stub).
     *
     * @return \FSFramework\model\catalogo_caracteristica_valor
     */
    protected function valor_model()
    {
        return new \FSFramework\model\catalogo_caracteristica_valor();
    }

    /**
     * Value-store seam (unit tests inject a DB-free stub).
     *
     * @return \FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorStore
     */
    protected function valor_store()
    {
        return new \FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorStore();
    }
}
