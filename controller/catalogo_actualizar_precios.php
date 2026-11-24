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
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoPriceUpdateService.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/PriceUpdateFilterRegistry.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_lista_precio.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_grupo.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/core/familia.php';

use FSFramework\Plugins\catalogo_core\Services\CatalogoPriceUpdateService;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateFilterRegistry;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateScopeInterface;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateScopes;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateTargetInterface;
use FSFramework\Plugins\catalogo_core\Services\PriceUpdateTargetRegistry;

/**
 * Batch price update page (R-BU-001..005, design D6/D9/D11).
 *
 * One page for every price surface: the operator picks a TARGET (where the
 * prices live: a price list, a consumer-registered target) and a SCOPE (which
 * rows: articles). The controller only handles codes and labels; storage and
 * row selection belong to the target/scope/book collaborators.
 *
 * Two-phase flow, each phase an admin-gated CSRF-checked POST:
 *   preview → computes affected rows and prospective prices via
 *   CatalogoPriceUpdateService (zero write); zero matches → warning via
 *   new_message(), nothing applied.
 *   apply → explicit confirm checkbox, persists exactly the previewed rows
 *   through the target book, then shows the resumen (updated count + affected
 *   keys) via new_message(); failed rows via new_error_msg(). The apply form
 *   reposts every filter and the percent, so apply recomputes the same rows.
 *
 * Unknown/unavailable target or scope, an empty registry and a missing target
 * key all fail closed: error message, zero writes. articulos.pvp is never
 * rewritten.
 */
#[\FSFramework\Attribute\AdminOnly]
class catalogo_actualizar_precios extends fs_controller
{
    /** @var array<string, string> available targets, code => label */
    public array $targets = [];

    /** @var array<string, string> scopes, code => label */
    public array $scopes = [];

    public string $selected_target = '';

    public string $selected_scope = '';

    /** Request field that carries the key of the selected target (e.g. codlista). */
    public string $target_key_field = 'codlista';

    public string $target_key_label = '';

    /** @var list<array{value: string, label: string}> key options of the selected target */
    public array $target_key_options = [];

    public string $scope_key_label = 'Referencia';

    /**
     * Neutral notices a redirect may request through `aviso=<code>`. Only these
     * codes resolve to text; anything else is dropped and never reflected.
     *
     * @var array<string, string>
     */
    private const NOTICES = [
        'seleccion_requerida' => 'Selecciona un destino y una clave para actualizar los precios.',
    ];

    /** Resolved notice text ('' when none): read-only, never selects or writes. */
    public string $notice = '';

    /**
     * Values echoed back into the filter and apply forms.
     *
     * @var array{key: string, pct: string, codfamilia: string, incluir_subfamilias: bool, codgrupo: string}
     */
    public array $form = [
        'key' => '',
        'pct' => '0',
        'codfamilia' => '',
        'incluir_subfamilias' => false,
        'codgrupo' => '',
    ];

    /**
     * Posted values of the request keys declared by the registered consumer
     * filters (PriceUpdateFilterRegistry::keys()). Only declared keys are ever
     * here: the apply form reposts them and the hook template echoes them.
     *
     * @var array<string, string>
     */
    public array $filter_extra = [];

    /** Scope the consumer filters were resolved for. */
    private string $filter_scope = '';

    /** @var list<\FSFramework\model\familia> */
    public array $familias = [];

    /** @var list<\FSFramework\model\catalogo_grupo> */
    public array $grupos = [];

    /** @var list<array{key: string, label: string, precio_actual: float, precio_nuevo: float}> preview rows */
    public array $preview_rows = [];

    public function __construct()
    {
        // The 4th argument is OBSOLETE and ignored: #[AdminOnly] is the gate.
        parent::__construct(__CLASS__, 'Actualizar precios', 'ventas', FALSE, TRUE);
    }

    protected function private_core()
    {
        $this->loadFormData();
        $this->prepareNotice();
        $this->prepareSelection();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!$this->isCsrfValid()) {
            $this->new_error_msg('Token de seguridad inválido.');
            return;
        }

        $this->handlePost();
    }

    /**
     * Pure resumen builder (R-BU-005): success message with the updated
     * count, plus any failed references.
     *
     * @param list<string> $failed
     */
    public static function summarizeApply(int $updated, array $failed): string
    {
        $resumen = $updated . ' precios actualizados correctamente.';
        if (empty($failed)) {
            return $resumen;
        }

        return $resumen . ' No se pudo actualizar: ' . implode(', ', $failed) . '.';
    }

    /**
     * POST entry point after the CSRF check: resolves target and scope and
     * dispatches the whitelisted action. Everything unresolvable fails closed.
     */
    protected function handlePost(): void
    {
        $target = $this->resolveTarget($this->requestValue('target'));
        if ($target === null) {
            $this->preview_rows = [];
            if (PriceUpdateTargetRegistry::available() === []) {
                $this->new_error_msg('No hay destinos de actualización disponibles.');
            } else {
                $this->new_error_msg('Destino de actualización no válido.');
            }
            return;
        }

        $scopeCode = $this->requestValue('scope');
        $scope = $this->resolveScope($scopeCode !== '' ? $scopeCode : PriceUpdateScopes::defaultCode());
        if ($scope === null) {
            $this->preview_rows = [];
            $this->new_error_msg('Ámbito de actualización no válido.');
            return;
        }

        $filters = $this->readFilters($target, $scope);

        switch ((string) ($_POST['action'] ?? '')) {
            case 'preview':
                $this->runPreview($scope, $target, $filters);
                break;

            case 'apply':
                $this->runApply($scope, $target, $filters);
                break;

            default:
                $this->new_error_msg('Acción no válida.');
        }
    }

    /** Scope lookup; overridable seam for the controller tests. */
    protected function resolveScope(string $code): ?PriceUpdateScopeInterface
    {
        return PriceUpdateScopes::forCode($code);
    }

    /**
     * The requested target when it is registered AND available; the default
     * target when none was requested; null otherwise (fail closed).
     */
    private function resolveTarget(string $requested): ?PriceUpdateTargetInterface
    {
        $available = PriceUpdateTargetRegistry::available();
        if ($available === []) {
            return null;
        }

        $code = $requested !== '' ? $requested : PriceUpdateTargetRegistry::defaultCode();
        if (!isset($available[$code])) {
            return null;
        }

        return PriceUpdateTargetRegistry::resolve((string) $code);
    }

    /** POST value first, then GET (preselection links), as a trimmed string. */
    private function requestValue(string $name): string
    {
        return trim((string) ($_POST[$name] ?? $_GET[$name] ?? ''));
    }

    /**
     * AND-combined filter set from the POST (R-BU-002). The target key is read
     * from the field the TARGET declares; consumer filter keys come from the
     * PriceUpdateFilterRegistry and nothing else the client posts is accepted.
     *
     * @return array<string, mixed>
     */
    private function readFilters(PriceUpdateTargetInterface $target, PriceUpdateScopeInterface $scope): array
    {
        $keyField = $target->keyField();

        // Consumer filters: ONLY the keys the registry declares for this scope.
        $extra = [];
        foreach (PriceUpdateFilterRegistry::keys($scope->code()) as $key) {
            $extra[$key] = trim((string) ($_POST[$key] ?? ''));
        }

        return $extra + [
            $keyField => trim((string) ($_POST[$keyField] ?? '')),
            'codfamilia' => trim((string) ($_POST['codfamilia'] ?? '')),
            // Checkbox posts 'TRUE'; fs_model::str2bool() is model-only and
            // does not understand form values (only DB truthy 't'/'1').
            'incluir_subfamilias' => ($_POST['incluir_subfamilias'] ?? 'FALSE') === 'TRUE',
            'codgrupo' => ($_POST['codgrupo'] ?? '') !== '' ? (int) $_POST['codgrupo'] : null,
        ];
    }

    private function readPct(): float
    {
        return (float) str_replace(',', '.', (string) ($_POST['pct'] ?? '0'));
    }

    /**
     * Preview phase: computes the affected rows without persisting.
     *
     * @param array<string, mixed> $filters
     */
    private function runPreview(
        PriceUpdateScopeInterface $scope,
        PriceUpdateTargetInterface $target,
        array $filters
    ): void {
        if ($target->keyFromFilters($filters) === null) {
            $this->new_error_msg('Debes seleccionar ' . $target->keyLabel() . '.');
            return;
        }

        $rows = $this->service()->preview($scope, $target, $filters, $this->readPct());

        if (empty($rows)) {
            $this->preview_rows = [];
            $this->new_message('Ningún elemento coincide con los filtros seleccionados. No se aplicó ningún cambio.');
            return;
        }

        $this->preview_rows = $rows;
        $this->new_message('Vista previa: ' . count($rows) . ' elementos afectados. Revisa y confirma para aplicar.');
    }

    /**
     * Apply phase: persists exactly the (recomputed) previewed rows.
     *
     * @param array<string, mixed> $filters
     */
    private function runApply(
        PriceUpdateScopeInterface $scope,
        PriceUpdateTargetInterface $target,
        array $filters
    ): void {
        if ($target->keyFromFilters($filters) === null) {
            $this->new_error_msg('Debes seleccionar ' . $target->keyLabel() . '.');
            return;
        }

        if (($_POST['confirm'] ?? 'FALSE') !== 'TRUE') {
            $this->new_error_msg('Debes marcar la casilla de confirmación para aplicar los cambios.');
            return;
        }

        $rows = $this->service()->preview($scope, $target, $filters, $this->readPct());

        if (empty($rows)) {
            $this->preview_rows = [];
            $this->new_message('Ningún elemento coincide con los filtros seleccionados. No se aplicó ningún cambio.');
            return;
        }

        $result = $this->service()->apply($scope, $target, $filters, $rows);

        $affected = array_column($rows, 'key');
        $summary = self::summarizeApply($result['updated'], $result['failed'])
            . ' Afectados: ' . implode(', ', $affected);

        if (empty($result['failed'])) {
            $this->new_message($summary);
        } else {
            $this->new_error_msg($summary);
        }
    }

    /** Whitelisted notice text for a code; '' for anything unknown. */
    public static function noticeFor(string $code): string
    {
        return self::NOTICES[$code] ?? '';
    }

    /** Reads `aviso` (GET) into `$notice`. Informational only. */
    protected function prepareNotice(): void
    {
        $this->notice = self::noticeFor(trim((string) ($_GET['aviso'] ?? '')));
    }

    /**
     * Fills the selectors and the echo values of the forms from the request.
     * A requested target that is not available is NOT reflected: the default
     * is shown instead (POSTs re-validate and fail closed).
     */
    protected function prepareSelection(): void
    {
        $this->targets = PriceUpdateTargetRegistry::available();
        $this->scopes = PriceUpdateScopes::labels();

        $target = $this->resolveTarget($this->requestValue('target'))
            ?? $this->resolveTarget('');
        if ($target !== null) {
            $this->selected_target = $target->code();
            $this->target_key_field = $target->keyField();
            $this->target_key_label = $target->keyLabel();
            $this->target_key_options = $target->keyOptions();
        }

        $scopeCode = $this->requestValue('scope');
        $scope = $this->resolveScope($scopeCode !== '' ? $scopeCode : PriceUpdateScopes::defaultCode())
            ?? $this->resolveScope(PriceUpdateScopes::defaultCode());
        if ($scope !== null) {
            $this->selected_scope = $scope->code();
            $this->scope_key_label = $scope->keyLabel();
            $this->prepareExtraFilters($scope->code());
        }

        $pct = $this->requestValue('pct');
        $this->form = [
            'key' => $this->requestValue($this->target_key_field),
            'pct' => $pct !== '' ? $pct : '0',
            'codfamilia' => $this->requestValue('codfamilia'),
            'incluir_subfamilias' => $this->requestValue('incluir_subfamilias') === 'TRUE',
            'codgrupo' => $this->requestValue('codgrupo'),
        ];
    }

    /**
     * Resolves the consumer filters of the scope: remembers the scope and
     * collects the posted (or preselected) values of the DECLARED keys only.
     */
    protected function prepareExtraFilters(string $scopeCode): void
    {
        $this->filter_scope = $scopeCode;
        $this->filter_extra = [];
        foreach (PriceUpdateFilterRegistry::keys($scopeCode) as $key) {
            $this->filter_extra[$key] = $this->requestValue($key);
        }
    }

    /**
     * Opaque data the consumer filters publish for their hook template (the
     * options of their controls). Empty when nothing is registered.
     *
     * @return array<string, mixed>
     */
    public function price_update_filter_context(): array
    {
        return PriceUpdateFilterRegistry::viewContext($this->filter_scope);
    }

    private function loadFormData(): void
    {
        $this->familias = (new \FSFramework\model\familia())->all();
        $this->grupos = (new \FSFramework\model\catalogo_grupo())->all();
    }

    private function service(): CatalogoPriceUpdateService
    {
        return new CatalogoPriceUpdateService();
    }
}
