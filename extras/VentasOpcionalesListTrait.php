<?php
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

require_once 'plugins/catalogo_core/Init.php';
require_once 'plugins/catalogo_core/model/core/catalogo_opcional_grupo.php';
require_once 'plugins/catalogo_core/model/tarif_familia.php';
require_once 'plugins/catalogo_core/model/tarif_opcional.php';
require_once 'plugins/catalogo_core/model/tarif_opcional_precio.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa.php';
require_once 'plugins/catalogo_core/model/tarif_tarifa_opcional.php';
require_once 'plugins/catalogo_core/Services/CaracteristicaResolver.php';

use FSFramework\model\catalogo_opcional_grupo;
use FSFramework\model\tarif_familia;
use FSFramework\model\tarif_opcional;
use FSFramework\model\tarif_opcional_precio;
use FSFramework\model\tarif_tarifa;
use FSFramework\model\tarif_tarifa_opcional;
use FSFramework\Plugins\catalogo_core\Init;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver;
use FSFramework\Plugins\catalogo_core\Services\CatalogoCurrencyFormatter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;

/**
 * Controller-agnostic opcionales management list logic absorbed from the
 * legacy `tarif_opcionales` page (spec opcionales-management OUM-01..OUM-07,
 * OUM-09; design AD-1..AD-6, AD-11).
 *
 * The trait only touches the `PageController` surface (`$this->request`,
 * `$this->db`, `$this->url()`, `$this->redirect()`, `$this->validateFormToken()`,
 * `$this->new_message()` / `$this->new_error_msg()`) so it works on the unified
 * `Controller/VentasOpcionales.php` without binding to `fbase_controller`.
 *
 * `run_in_transaction()` and `parse_price_input()` are ported from
 * `extras/TarifarioOpcionalStateTrait.php` (which keeps serving the surviving
 * edit/precios controllers) because that trait also drags in the legacy
 * fbase pagination helper and the fbase_controller require, which this
 * PageController trait must not reference.
 */
trait VentasOpcionalesListTrait
{
    /**
     * Once-per-process guard for the moved opcional tables.
     */
    private static bool $opcionales_tables_ensured = false;

    public $b_codfamilia;
    public $b_codtarifa;
    public $b_id_grupo = '';
    public $b_solo_activos;
    public $b_url;
    public $offset;
    public $resultados;
    public $total_resultados;
    public $tarifa_seleccionada;
    public $tarifas;
    public $tarifa_defecto;
    public $familia;

    /**
     * Active opcional groups for the create-modal select and the filter.
     * @var array<int, catalogo_opcional_grupo>
     */
    public array $grupos_opcional = [];

    /**
     * Group label per listed opcional id, resolved from one map (no N+1).
     * @var array<int, string>
     */
    private array $nombres_grupo_opcional = [];

    /**
     * Price of every listed opcional for the selected tarifa, carrying the
     * price mode so percentage opcionales render their effective label
     * (design AD-1).
     * @var array<int, array{precio: float|null, porcentaje: float|null, es_porcentaje: bool, en_catalogo: bool}>
     */
    private array $precios_cache = [];

    /**
     * Effective master state per opcional id for the selected tarifa.
     * @var array<int, array<string, mixed>>
     */
    private array $opcionales_state_cache = [];

    /**
     * D12-derived catalog/tarifa visibility per opcional id for the selected
     * tarifa: `[id => ['en_catalogo' => bool, 'en_tarifa' => bool]]`.
     *
     * An opcional owns no visibility flag; it is derived from its parent
     * product's effective feature value (CAR-12 / OUM-03) and is read-only.
     * @var array<int, array<string, bool>>
     */
    private array $opcionales_visibility_cache = [];

    // =====================================================================
    // Seams
    // =====================================================================

    /**
     * Master-state accessor for the selected tarifa. Overridable seam so the
     * per-tarifa state resolution is unit-testable without a live database.
     *
     * @return tarif_tarifa_opcional
     */
    protected function opcional_master_state()
    {
        return new tarif_tarifa_opcional();
    }

    /**
     * Price-model accessor. Overridable seam, same rationale as above.
     *
     * @return tarif_opcional_precio
     */
    protected function opcional_precio_model()
    {
        return new tarif_opcional_precio();
    }

    /**
     * Opcional-model accessor used by search/create/delete. Overridable seam.
     *
     * @return tarif_opcional
     */
    protected function opcional_model()
    {
        return new tarif_opcional();
    }

    /**
     * Opcional-group model accessor. Overridable seam.
     *
     * @return catalogo_opcional_grupo
     */
    protected function opcional_grupo_model()
    {
        return new catalogo_opcional_grupo();
    }

    /**
     * D12 visibility-resolver accessor. Overridable seam so the derived
     * indicator is unit-testable without a live database.
     *
     * @return CaracteristicaResolver
     */
    protected function opcional_visibility_resolver()
    {
        return new CaracteristicaResolver();
    }

    // =====================================================================
    // Bootstrap + filters
    // =====================================================================

    /**
     * Ensures the moved opcional tables once per process.
     */
    protected function ensure_opcionales_tables(): void
    {
        if (self::$opcionales_tables_ensured) {
            return;
        }

        self::$opcionales_tables_ensured = true;

        if (class_exists(Init::class)) {
            Init::ensureOpcionalesTarifaTables();
        }
    }

    /**
     * Boots the unified list: active tarifas, validated selection, filters and
     * the filtered search.
     */
    protected function init_opcionales_list(): void
    {
        $this->ensure_opcionales_tables();

        $this->familia = new tarif_familia();

        $tarifa = new tarif_tarifa();
        $this->tarifas = $tarifa->all_activas();
        $this->tarifa_defecto = $tarifa->get_default();

        $this->ini_filters();
        $this->search_opcionales();
        $this->load_grupos_cache();
    }

    /**
     * Resolves the selected tarifa: a requested member of the active set wins,
     * otherwise the default tarifa, otherwise the first active one.
     *
     * @return object|null
     */
    protected function resolve_tarifa_seleccionada(?string $requested)
    {
        $requested = $requested === null ? '' : trim($requested);

        if ($requested !== '') {
            foreach ((array) $this->tarifas as $tarifa) {
                if ((string) $tarifa->codtarifa === $requested) {
                    return $tarifa;
                }
            }
        }

        if ($this->tarifa_defecto) {
            return $this->tarifa_defecto;
        }

        if (is_array($this->tarifas) && count($this->tarifas) > 0) {
            return $this->tarifas[0];
        }

        return null;
    }

    /**
     * Primary filter key is `query`; `search` is accepted as an alias.
     */
    protected function filter_query(): string
    {
        $query = (string) $this->request->query->get('query', '');
        if ($query === '') {
            $query = (string) $this->request->query->get('search', '');
        }

        return $query;
    }

    /**
     * Reads the active filters and builds the shared list URL.
     */
    protected function ini_filters(): void
    {
        $this->offset = (int) $this->request->query->get('offset', 0);
        if ($this->request->request->has('offset')) {
            $this->offset = (int) $this->request->request->get('offset');
        }

        $this->b_codfamilia = (string) $this->request->query->get('b_codfamilia', '');
        $this->b_codtarifa = (string) $this->request->query->get('b_codtarifa', '');

        $this->tarifa_seleccionada = $this->resolve_tarifa_seleccionada(
            $this->b_codtarifa !== '' ? $this->b_codtarifa : null
        );
        $this->b_codtarifa = $this->tarifa_seleccionada
            ? (string) $this->tarifa_seleccionada->codtarifa
            : '';

        $this->b_id_grupo = trim((string) $this->request->query->get('b_id_grupo', ''));
        $this->b_solo_activos = $this->request->query->get('b_solo_activos', '') === 'TRUE';

        $this->b_url = $this->url()
            . '&query=' . urlencode($this->filter_query())
            . '&b_codfamilia=' . urlencode((string) $this->b_codfamilia)
            . '&b_codtarifa=' . urlencode((string) $this->b_codtarifa)
            . '&b_id_grupo=' . urlencode((string) $this->b_id_grupo)
            . '&b_solo_activos=' . ($this->b_solo_activos ? 'TRUE' : 'FALSE');
    }

    /**
     * Filtered search plus the true filtered total (never the page size).
     */
    protected function search_opcionales(): void
    {
        $opcional = $this->opcional_model();

        $this->resultados = $opcional->search(
            $this->filter_query(),
            $this->offset,
            $this->b_codfamilia,
            $this->b_codtarifa,
            $this->b_solo_activos,
            $this->b_id_grupo
        );

        $this->total_resultados = $opcional->count_filtered(
            $this->filter_query(),
            $this->b_codfamilia,
            $this->b_codtarifa,
            $this->b_solo_activos,
            $this->b_id_grupo
        );
    }

    /**
     * Loads the active opcional groups once and builds the per-row label map
     * so the list never calls `etiqueta_grupo()` per row (design AD-7).
     */
    protected function load_grupos_cache(): void
    {
        $this->grupos_opcional = [];
        $this->nombres_grupo_opcional = [];

        $nombres = [];
        foreach ($this->opcional_grupo_model()->all_activos() as $grupo) {
            $this->grupos_opcional[] = $grupo;
            $nombres[(int) $grupo->id] = (string) $grupo->nombre;
        }

        foreach ((array) $this->resultados as $opcional) {
            $idGrupo = isset($opcional->id_grupo) ? (int) $opcional->id_grupo : 0;
            $this->nombres_grupo_opcional[(int) $opcional->id] = ($idGrupo > 0 && isset($nombres[$idGrupo]))
                ? $nombres[$idGrupo]
                : '-';
        }
    }

    /**
     * Group label of a listed opcional, resolved from the one map loaded by
     * {@see load_grupos_cache()} (no N+1).
     *
     * @param int $id_opcional
     */
    public function nombre_grupo_opcional($id_opcional): string
    {
        return $this->nombres_grupo_opcional[(int) $id_opcional] ?? '-';
    }

    // =====================================================================
    // Per-tarifa state + price caches
    // =====================================================================

    /**
     * Builds the derived catalog/tarifa visibility for every listed opcional in
     * the selected tarifa (D12 existential union over the parent products).
     *
     * Two batched resolver calls — one per indicator — independent of the page
     * size, and never a per-row query. Reading persists nothing (CAR-07).
     */
    protected function load_opcionales_visibility_cache(): void
    {
        $this->opcionales_visibility_cache = [];

        if (!$this->tarifa_seleccionada || empty($this->resultados)) {
            return;
        }

        $ids = [];
        foreach ((array) $this->resultados as $opcional) {
            $id = (int) ($opcional->id ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return;
        }

        $resolver = $this->opcional_visibility_resolver();
        $codtarifa = (string) $this->tarifa_seleccionada->codtarifa;

        foreach (CaracteristicaResolver::VISIBILITY_CODIGOS as $codigo) {
            foreach ((array) $resolver->resolve_opcionales_visibility($ids, $codtarifa, $codigo) as $id => $visible) {
                $this->opcionales_visibility_cache[(int) $id][$codigo] = (bool) $visible;
            }
        }
    }

    /**
     * Builds the effective master state (activa) for every listed opcional in
     * the selected tarifa. Reading never persists.
     */
    protected function load_opcionales_state_cache(): void
    {
        $this->opcionales_state_cache = [];

        if (!$this->tarifa_seleccionada) {
            return;
        }

        $master = $this->opcional_master_state();
        foreach ((array) $this->resultados as $opcional) {
            $this->opcionales_state_cache[$opcional->id] = $master->effective(
                $this->tarifa_seleccionada->codtarifa,
                $opcional->id
            );
        }
    }

    /**
     * Price rows contribute the price only; activation/catalog state comes
     * from the master cache (design AD2/AD3).
     */
    protected function load_precios_cache(): void
    {
        $this->precios_cache = [];

        if (!$this->tarifa_seleccionada) {
            return;
        }

        $precio_model = $this->opcional_precio_model();
        foreach ((array) $this->resultados as $opcional) {
            $precio = $precio_model->get($opcional->id, $this->tarifa_seleccionada->codtarifa);
            $es_porcentaje = method_exists($opcional, 'es_precio_porcentaje')
                && $opcional->es_precio_porcentaje();

            $porcentaje = null;
            if ($es_porcentaje) {
                // Per-lista value wins, then the global mode fallback (AD-1).
                if ($precio && isset($precio->porcentaje)) {
                    $porcentaje = floatval($precio->porcentaje);
                } elseif (isset($opcional->porcentaje)) {
                    $porcentaje = floatval($opcional->porcentaje);
                }
            }

            $this->precios_cache[$opcional->id] = [
                'precio' => $precio ? $precio->precio : null,
                'porcentaje' => $porcentaje,
                'es_porcentaje' => $es_porcentaje,
                'en_catalogo' => $precio ? $precio->en_catalogo : false,
            ];
        }
    }

    /**
     * @param int $id_opcional
     * @return float|null
     */
    public function get_precio_opcional_tarifa($id_opcional)
    {
        if (isset($this->precios_cache[$id_opcional])) {
            return $this->precios_cache[$id_opcional]['precio'];
        }

        return null;
    }

    /**
     * Effective percentage of a listed opcional for the selected tarifa, or
     * null when the opcional is not in percentage mode.
     *
     * @param int $id_opcional
     * @return float|null
     */
    public function get_porcentaje_opcional_tarifa($id_opcional)
    {
        if (isset($this->precios_cache[$id_opcional]) && !empty($this->precios_cache[$id_opcional]['es_porcentaje'])) {
            return $this->precios_cache[$id_opcional]['porcentaje'];
        }

        return null;
    }

    /**
     * Whether the listed opcional prices as a percentage (design AD-1).
     *
     * @param int $id_opcional
     */
    public function es_opcional_porcentaje($id_opcional): bool
    {
        return !empty($this->precios_cache[$id_opcional]['es_porcentaje']);
    }

    /**
     * @param int $id_opcional
     */
    public function opcional_activo_en_tarifa($id_opcional): bool
    {
        if (isset($this->opcionales_state_cache[$id_opcional])) {
            return (bool) $this->opcionales_state_cache[$id_opcional]['activa'];
        }

        return false;
    }

    /**
     * Derived, read-only catalog visibility of a listed opcional for the
     * selected tarifa (CAR-12 / OUM-03). An opcional owns no visibility flag:
     * the value comes from the parent product's effective feature value.
     *
     * @param int $id_opcional
     */
    public function opcional_en_catalogo_tarifa($id_opcional): bool
    {
        return (bool) ($this->opcionales_visibility_cache[(int) $id_opcional]['en_catalogo'] ?? false);
    }

    /**
     * Derived, read-only export/tarifa visibility of a listed opcional for the
     * selected tarifa (CAR-12 / OUM-03).
     *
     * @param int $id_opcional
     */
    public function opcional_en_tarifa_flag($id_opcional): bool
    {
        return (bool) ($this->opcionales_visibility_cache[(int) $id_opcional]['en_tarifa'] ?? false);
    }

    /**
     * Formatted price of an opcional for the selected tarifa (AD-5).
     *
     * @param int $id_opcional
     */
    public function show_precio_opcional($id_opcional): string
    {
        if ($this->es_opcional_porcentaje($id_opcional)) {
            return $this->etiqueta_porcentaje_opcional($id_opcional);
        }

        $precio = $this->get_precio_opcional_tarifa($id_opcional);
        if ($precio === null) {
            return '';
        }

        $coddivisa = $this->tarifa_seleccionada
            ? (string) ($this->tarifa_seleccionada->coddivisa ?? '')
            : '';

        return CatalogoCurrencyFormatter::format((float) $precio, $coddivisa);
    }

    /**
     * Effective percentage label (`12,5%`) for a listed opcional, or `-` when
     * no percentage is defined (mirrors `etiqueta_precio_lista()`).
     *
     * @param int $id_opcional
     */
    public function etiqueta_porcentaje_opcional($id_opcional): string
    {
        $porcentaje = $this->get_porcentaje_opcional_tarifa($id_opcional);
        if ($porcentaje === null) {
            return '-';
        }

        return rtrim(rtrim(number_format($porcentaje, 2, ',', '.'), '0'), ',') . '%';
    }

    /**
     * Value + Excel number format of an opcional for the selected tarifa in
     * its own mode (fixed price, or percentage with a percentage format) so
     * the export never silently writes a zero amount (design AD-9/OUM-07).
     *
     * @param object $opc
     * @return array{value: float, format: string}
     */
    protected function valor_precio_export($opc): array
    {
        $id = (int) ($opc->id ?? 0);
        $codtarifa = $this->tarifa_seleccionada
            ? (string) $this->tarifa_seleccionada->codtarifa
            : '';

        if (isset($this->precios_cache[$id]) && !empty($this->precios_cache[$id]['es_porcentaje'])) {
            $pct = $this->precios_cache[$id]['porcentaje'];

            return ['value' => $pct === null ? 0.0 : (float) $pct, 'format' => '0.00"%"'];
        }

        if (method_exists($opc, 'es_precio_porcentaje') && $opc->es_precio_porcentaje()) {
            $pct = method_exists($opc, 'get_porcentaje') ? $opc->get_porcentaje($codtarifa) : null;

            return ['value' => $pct === null ? 0.0 : (float) $pct, 'format' => '0.00"%"'];
        }

        $precio = 0.0;
        if ($this->tarifa_seleccionada && method_exists($opc, 'get_precio_tarifa')) {
            $precio_tarifa = $opc->get_precio_tarifa($codtarifa);
            if ($precio_tarifa) {
                $precio = (float) $precio_tarifa->precio;
            }
        }

        return ['value' => $precio, 'format' => '#,##0.00'];
    }

    /**
     * Currency symbol for a tarifa's divisa (AD-5).
     */
    public function simbolo_divisa_tarifa(string $coddivisa): string
    {
        return CatalogoCurrencyFormatter::symbol($coddivisa);
    }

    // =====================================================================
    // Mutations
    // =====================================================================

    /**
     * Allows a mutating action only when it is a POST carrying a valid CSRF
     * token (`validateFormToken()` reads `_csrf_token` / `_token`).
     */
    protected function guard_mutating_action(): bool
    {
        if (strtoupper((string) $this->request->getMethod()) !== 'POST') {
            return false;
        }

        return (bool) $this->validateFormToken();
    }

    /**
     * Persists the activation flag for a (tarifa, opcional) as toggled from the
     * list. POST + a valid CSRF token are mandatory.
     *
     * Catalog/tarifa visibility has no toggle: it is derived from the parent
     * product (OUM-04) and the retired `toggle_en_catalogo`/`toggle_en_tarifa`
     * actions mutate nothing.
     *
     * @param string $action one of Controller/VentasOpcionales::TOGGLE_ACTIONS
     */
    protected function toggle_opcional_state(string $action): void
    {
        if (!$this->guard_mutating_action()) {
            $this->new_error_msg('Token de seguridad inválido o método no permitido.');
            $this->redirect_to_list((string) $this->request->request->get('codtarifa', ''));

            return;
        }

        $id = (int) $this->request->request->get('id', 0);
        $codtarifa = trim((string) $this->request->request->get('codtarifa', ''));
        if ($id <= 0 || $codtarifa === '') {
            $this->new_error_msg('Datos incompletos para cambiar el estado.');
            $this->redirect_to_list($codtarifa);

            return;
        }

        if ($action !== 'toggle_activa') {
            // A retired visibility toggle is never dispatched (TOGGLE_ACTIONS)
            // and never persists anything.
            $this->redirect_to_list($codtarifa);

            return;
        }

        $master = $this->opcional_master_state();
        $ok = $master->set_activa($codtarifa, $id, $this->request->request->has('activa'));

        if ($ok) {
            $this->new_message('Estado del opcional actualizado.');
        } else {
            $this->new_error_msg('Error al guardar el estado del opcional.');
        }

        $this->redirect_to_list($codtarifa);
    }

    /**
     * Full-page redirect back to the list preserving the active filters.
     */
    protected function redirect_to_list(string $codtarifa): void
    {
        $url = $this->url();
        if ($codtarifa !== '') {
            $url .= '&b_codtarifa=' . urlencode($codtarifa);
        }

        foreach (['query', 'b_codfamilia', 'b_id_grupo', 'b_solo_activos', 'offset'] as $key) {
            if (!$this->request->request->has($key)) {
                continue;
            }

            $value = $this->request->request->get($key);
            if (!is_scalar($value) || (string) $value === '') {
                continue;
            }

            $url .= '&' . $key . '=' . urlencode((string) $value);
        }

        $this->redirect($url);
    }

    /**
     * Creates an opcional from the unified list, validating every provided
     * per-tarifa price before persisting and guarded by CSRF (closes the
     * legacy missing-token gap on creation).
     */
    protected function new_opcional(): void
    {
        if (!$this->guard_mutating_action()) {
            $this->new_error_msg('Token de seguridad inválido o método no permitido.');

            return;
        }

        $opcional = $this->opcional_model();

        $codigo = trim((string) $this->request->request->get('codigo', ''));
        if ($codigo === '') {
            $codigo = (string) $opcional->get_new_codigo();
        }

        if ($opcional->get_by_codigo($codigo)) {
            $this->new_error_msg('Ya existe un opcional con el código ' . $codigo);

            return;
        }

        $nombre = trim((string) $this->request->request->get('nombre', ''));
        if ($nombre === '') {
            $this->new_error_msg('El nombre del opcional es obligatorio.');

            return;
        }

        // Price mode: fixed (per-tarifa prices) or percentage (one global value
        // per mode + the effective per-tarifa row; design AD-1/AD-8).
        $tipoPrecio = (string) $this->request->request->get(
            'tipo_precio',
            (string) $this->request->request->get('stipo_precio', 'fijo')
        );
        $esPorcentaje = $tipoPrecio === \FSFramework\model\catalogo_opcional::TIPO_PRECIO_PORCENTAJE;

        // Validate every provided value BEFORE persisting anything so a
        // malformed value can never leave a half-created opcional behind.
        $precios = [];
        $porcentaje = null;
        if ($esPorcentaje) {
            $raw = $this->request->request->get(
                'porcentaje',
                (string) $this->request->request->get('sporcentaje', '')
            );
            $porcentaje = $this->parse_price_input($raw);
            if ($porcentaje === null || $porcentaje < 0) {
                $this->new_error_msg('Porcentaje no válido.');

                return;
            }
        } else {
            foreach ((array) $this->tarifas as $tarifa) {
                $key = 'precio_tarifa_' . $tarifa->codtarifa;
                if (!$this->request->request->has($key)) {
                    continue;
                }

                $raw = $this->request->request->get($key);
                if ($raw === null || $raw === '') {
                    continue;
                }

                $parsed = $this->parse_price_input($raw);
                if ($parsed === null) {
                    $this->new_error_msg('Precio no válido para la tarifa ' . $tarifa->codtarifa . '.');

                    return;
                }

                $precios[(string) $tarifa->codtarifa] = $parsed;
            }
        }

        // Optional group assignment (mirrors Controller/VentasOpcional.php).
        $idGrupo = (int) $this->request->request->get(
            'sid_grupo',
            (int) $this->request->request->get('id_grupo', 0)
        );

        $opcional->codigo = $codigo;
        $opcional->nombre = $nombre;
        $opcional->descripcion = (string) $this->request->request->get('descripcion', '');
        $refSap = trim((string) $this->request->request->get('ref_sap', ''));
        $opcional->ref_sap = $refSap === '' ? null : $refSap;
        $opcional->tipo_precio = $esPorcentaje
            ? \FSFramework\model\catalogo_opcional::TIPO_PRECIO_PORCENTAJE
            : \FSFramework\model\catalogo_opcional::TIPO_PRECIO_FIJO;
        $opcional->porcentaje = $esPorcentaje ? $porcentaje : null;
        $opcional->precio = 0;
        $opcional->id_grupo = $idGrupo > 0 ? $idGrupo : null;

        $tarifaDefecto = '';
        if ($this->tarifa_seleccionada) {
            $tarifaDefecto = (string) $this->tarifa_seleccionada->codtarifa;
        } elseif ($this->tarifa_defecto) {
            $tarifaDefecto = (string) $this->tarifa_defecto->codtarifa;
        }

        $saved = $this->run_in_transaction(function () use ($opcional, $precios, $esPorcentaje, $porcentaje, $tarifaDefecto): bool {
            if (!$opcional->save()) {
                return false;
            }

            foreach ((array) $this->request->request->all('familias') as $codfamilia) {
                $opcional->add_familia($codfamilia);
            }

            if ($esPorcentaje) {
                // One effective per-tarifa row; the rest fall back to the
                // global percentage through get_porcentaje() (AD-1).
                if ($tarifaDefecto !== '') {
                    $opcional->set_porcentaje_tarifa($tarifaDefecto, $porcentaje);
                }
            } else {
                foreach ($precios as $codtarifa => $precio) {
                    $opcional->set_precio_tarifa($codtarifa, $precio);
                }
            }

            return true;
        });

        if ($saved) {
            $this->new_message('Opcional ' . $opcional->codigo . ' creado correctamente.');
        } else {
            $this->new_error_msg('¡Error al crear el opcional!');
        }
    }

    /**
     * Deletes an opcional from the unified list (POST + CSRF + allow_delete).
     */
    protected function delete_opcional(): void
    {
        if (!$this->guard_mutating_action()) {
            $this->new_error_msg('Token de seguridad inválido o método no permitido.');

            return;
        }

        if (!$this->allow_delete) {
            $this->new_error_msg('No tienes permiso para eliminar en esta página.');

            return;
        }

        $id = (int) $this->request->request->get('delete', 0);
        $opcional = $this->opcional_model();
        $item = $opcional->get($id);

        if (!$item) {
            $this->new_error_msg('Opcional no encontrado.');

            return;
        }

        if ($item->delete()) {
            $this->new_message('Opcional ' . $item->codigo . ' eliminado correctamente.');

            return;
        }

        $this->new_error_msg('No se pudo eliminar el opcional.');
    }

    // =====================================================================
    // Native pagination (mirrors Controller/VentasArticulos.php)
    // =====================================================================

    public function getPaginationUrl(int $offset): string
    {
        $params = $this->getListQueryParams();
        $params['offset'] = $offset;

        return $this->buildListUrl($params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function buildListUrl(array $params = []): string
    {
        if ($params === []) {
            return $this->url();
        }

        $baseUrl = $this->url();
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . http_build_query($params);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getListQueryParams(): array
    {
        $params = $this->request->query->all();
        unset($params['page'], $params['action']);

        return $params;
    }

    public function hasMoreResults(): bool
    {
        $limit = defined('FS_ITEM_LIMIT') ? (int) FS_ITEM_LIMIT : 50;

        return count((array) $this->resultados) >= $limit;
    }

    /**
     * Offset-based page links for the list view, preserving active filters.
     *
     * @return list<array{offset: int, url: string, active: bool}>
     */
    public function getPaginationItems(): array
    {
        $limit = defined('FS_ITEM_LIMIT') ? (int) FS_ITEM_LIMIT : 50;
        $total = (int) $this->total_resultados;

        if ($limit <= 0 || $total <= $limit) {
            return [];
        }

        $items = [];
        for ($offset = 0; $offset < $total; $offset += $limit) {
            $items[] = [
                'offset' => $offset,
                'url' => $this->getPaginationUrl($offset),
                'active' => $offset === (int) $this->offset,
            ];
        }

        return $items;
    }

    // =====================================================================
    // Excel export (AD-11)
    // =====================================================================

    /**
     * Exports the currently filtered opcionales to Excel. Headers stay frozen
     * and the price is the selected-tarifa price; the row source honors the
     * active `query` / `b_codfamilia` / `b_codtarifa` / `b_solo_activos`
     * filters (OUM-07).
     *
     * @return list<object>
     */
    protected function load_opcionales_for_export(): array
    {
        $model = $this->opcional_model();
        $limit = defined('FS_ITEM_LIMIT') ? (int) FS_ITEM_LIMIT : 50;
        $rows = [];
        $offset = 0;
        $guard = 0;

        do {
            $page = $model->search(
                $this->filter_query(),
                $offset,
                $this->b_codfamilia,
                $this->b_codtarifa,
                $this->b_solo_activos,
                $this->b_id_grupo
            );
            $page = is_array($page) ? $page : [];
            foreach ($page as $row) {
                $rows[] = $row;
            }

            $offset += $limit;
            $guard++;
        } while (count($page) >= $limit && $guard < 1000);

        return $rows;
    }

    /**
     * Exporta los opcionales a Excel.
     * Columnas: Codigo (No editar), Ref SAP:, Descripción, Precio, Familia, Subfamilia
     */
    protected function export_excel_opcionales(): void
    {
        $this->template = false;

        @ini_set('display_errors', 0);
        error_reporting(0);
        @ini_set('memory_limit', '512M');
        @set_time_limit(300);

        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle('Opcionales');

            // Headers
            $headers = ['Codigo (No editar)', 'Ref SAP:', 'Descripción', 'Precio', 'Familia', 'Subfamilia'];
            foreach ($headers as $col => $header) {
                $cell = chr(65 + $col) . '1';
                $sheet->setCellValue($cell, $header);
            }

            // Estilo de headers
            $headerStyle = [
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => '4472C4'],
                ],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
                'borders' => [
                    'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                ],
            ];
            $sheet->getStyle('A1:F1')->applyFromArray($headerStyle);

            // Anchos de columna
            $sheet->getColumnDimension('A')->setWidth(20);  // Codigo
            $sheet->getColumnDimension('B')->setWidth(20);  // Ref SAP
            $sheet->getColumnDimension('C')->setWidth(50);  // Descripción
            $sheet->getColumnDimension('D')->setWidth(12);  // Precio
            $sheet->getColumnDimension('E')->setWidth(25);  // Familia
            $sheet->getColumnDimension('F')->setWidth(25);  // Subfamilia

            // Obtener todas las familias para construir el mapa de jerarquía
            $familia_model = new tarif_familia();
            $todas_familias = $familia_model->all();
            $familias_map = [];
            foreach ($todas_familias as $fam) {
                $familias_map[$fam->codfamilia] = $fam;
            }

            // Función para obtener familia y subfamilia separadas
            $getFamilyInfo = function ($codfamilia) use ($familias_map) {
                if (empty($codfamilia) || !isset($familias_map[$codfamilia])) {
                    return ['familia' => '', 'subfamilia' => ''];
                }

                $current = $familias_map[$codfamilia];
                $subfamilia = $current->descripcion;
                $familia = '';

                // Si tiene madre, buscar la familia raíz
                if (!empty($current->madre) && isset($familias_map[$current->madre])) {
                    $parent = $familias_map[$current->madre];
                    while (!empty($parent->madre) && isset($familias_map[$parent->madre])) {
                        $parent = $familias_map[$parent->madre];
                    }
                    $familia = $parent->descripcion;
                } else {
                    // Es una familia raíz, no tiene subfamilia
                    $familia = $subfamilia;
                    $subfamilia = '';
                }

                return ['familia' => $familia, 'subfamilia' => $subfamilia];
            };

            // Obtener los opcionales que cumplen los filtros activos
            $opcionales = $this->load_opcionales_for_export();

            $row = 2;
            foreach ($opcionales as $opc) {
                // Obtener familias del opcional
                $familias_opcional = $opc->get_familias();

                // Valor en la tarifa seleccionada en el modo del opcional:
                // importe fijo, o porcentaje con formato porcentual (AD-9/OUM-07).
                $valor_precio = $this->valor_precio_export($opc);

                if (count($familias_opcional) > 0) {
                    // Exportar una fila por cada familia asociada
                    foreach ($familias_opcional as $fam) {
                        $familyInfo = $getFamilyInfo($fam->codfamilia);

                        $sheet->setCellValue('A' . $row, $opc->codigo);
                        $sheet->setCellValue('B' . $row, $opc->ref_sap ?? '');
                        $sheet->setCellValue('C' . $row, $opc->nombre);
                        $sheet->setCellValue('D' . $row, $valor_precio['value']);
                        $sheet->setCellValue('E' . $row, $familyInfo['familia']);
                        $sheet->setCellValue('F' . $row, $familyInfo['subfamilia']);

                        // Formato del valor (importe o porcentaje según el modo)
                        $sheet->getStyle('D' . $row)->getNumberFormat()
                            ->setFormatCode($valor_precio['format']);

                        $row++;
                    }
                } else {
                    // Opcional sin familias - exportar una fila sin familia
                    $sheet->setCellValue('A' . $row, $opc->codigo);
                    $sheet->setCellValue('B' . $row, $opc->ref_sap ?? '');
                    $sheet->setCellValue('C' . $row, $opc->nombre);
                    $sheet->setCellValue('D' . $row, $valor_precio['value']);
                    $sheet->setCellValue('E' . $row, '');
                    $sheet->setCellValue('F' . $row, '');

                    // Formato del valor (importe o porcentaje según el modo)
                    $sheet->getStyle('D' . $row)->getNumberFormat()
                        ->setFormatCode($valor_precio['format']);

                    $row++;
                }
            }

            // Estilo de datos
            if ($row > 2) {
                $dataRange = 'A2:F' . ($row - 1);
                $sheet->getStyle($dataRange)->applyFromArray([
                    'borders' => [
                        'allBorders' => ['borderStyle' => Border::BORDER_THIN],
                    ],
                ]);
            }

            // Congelar primera fila
            $sheet->freezePane('A2');

            // Nombre del archivo
            $tarifa_nombre = $this->tarifa_seleccionada ? $this->tarifa_seleccionada->nombre : 'sin_tarifa';
            $tarifa_nombre_safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $tarifa_nombre);
            $filename = 'opcionales_' . $tarifa_nombre_safe . '_' . date('Y-m-d') . '.xlsx';

            // Headers para descarga
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment;filename="' . $filename . '"');
            header('Cache-Control: max-age=0');

            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
            exit();
        } catch (\Exception $e) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
        }
    }

    // =====================================================================
    // Ported from extras/TarifarioOpcionalStateTrait.php (ported verbatim;
    // the surviving trait keeps serving the edit/precios controllers)
    // =====================================================================

    /**
     * Runs $work inside a single explicit database transaction.
     *
     * @param callable(): bool $work
     */
    protected function run_in_transaction(callable $work): bool
    {
        $previous = $this->db->get_auto_transactions();
        $this->db->set_auto_transactions(false);

        $began = false;
        $committed = false;

        try {
            if (!$this->db->begin_transaction()) {
                return false;
            }
            $began = true;

            if (!$work()) {
                return false;
            }

            if (!$this->db->commit()) {
                return false;
            }
            $committed = true;

            return true;
        } catch (\Throwable $e) {
            return false;
        } finally {
            if ($began && !$committed) {
                $this->db->rollback();
            }

            $this->db->set_auto_transactions($previous);
        }
    }

    /**
     * Parses a user-submitted price into a float; null when invalid.
     *
     * @param mixed $raw
     * @return float|null
     */
    protected function parse_price_input($raw)
    {
        $value = trim((string) $raw);
        if ($value === '') {
            return 0.0;
        }

        if (!preg_match('/^[+-]?(?:\d+(?:[.,]\d+)?|[.,]\d+)$/', $value)) {
            return null;
        }

        return (float) str_replace(',', '.', $value);
    }
}
