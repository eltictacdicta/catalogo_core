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
declare(strict_types=1);

require_once FS_FOLDER . '/plugins/catalogo_core/model/core/catalogo_idioma.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_tarifa.php';
require_once FS_FOLDER . '/plugins/catalogo_core/model/tarif_articulo_precio.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/ArticuloTarifaPrecioBatchReader.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaValorBatchReader.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CaracteristicaConfig.php';
require_once FS_FOLDER . '/plugins/catalogo_core/Services/CatalogoCurrencyFormatter.php';

use FSFramework\Plugins\catalogo_core\Services\ArticuloTarifaPrecioBatchReader;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaConfig;
use FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorBatchReader;
use FSFramework\Plugins\catalogo_core\Services\CatalogoCurrencyFormatter;

/**
 * Controller-agnostic article list logic absorbed from the legacy
 * `tarif_articulos` page (spec ALC-01/ALC-02/ALC-03, design AD-W3-2/AD-W3-3/
 * AD-W3-4/AD-W3-5).
 *
 * The trait only touches the `PageController` surface (`$this->request`,
 * `$this->url()`, `$this->validateFormToken()`, `$this->new_message()` /
 * `$this->new_error_msg()`) so the canonical `Controller/VentasArticulos.php`
 * keeps `extends PageController`, `privateCore()` and the legacy wrapper
 * contract.
 */
trait VentasArticulosListTrait
{
    /** The two visibility features resolved through the read-through flag (CAR-14). */
    private const VISIBILITY_CODIGOS = ['en_catalogo', 'en_tarifa'];

    /** Effective familia filter (canonical `codfamilia` wins over `b_codfamilia`). */
    public $b_codfamilia = '';

    /** Effective tarifa filter (resolved: requested → default → first active). */
    public $b_codtarifa = '';

    /** TRUE (default) restricts the search to non-blocked articles. */
    public $b_solo_activos = true;

    /** @var array<int, object> Active tarifas for the selector and price writes. */
    public array $tarifas = [];

    /** @var object|null */
    public $tarifa_seleccionada;

    /** @var array<int, object> Active idiomas, used by the moved import/export actions. */
    public array $idiomas = [];

    /**
     * Per-row tarifa price/state map for the current page, loaded with one
     * batched query (ALC-02/AD-W3-4).
     *
     * @var array<string, array{precio: float, activo: bool, en_tarifa: bool, en_catalogo: bool}>
     */
    private array $articulo_tarifa_columns = [];

    /**
     * Resolver-driven visibility per referencia while the read-through flag is
     * on (CAR-14); empty on the legacy branch.
     *
     * @var array<string, array<string, ?string>>
     */
    private array $articulo_visibility_features = [];

    // =====================================================================
    // Seams
    // =====================================================================

    /**
     * Tarifa model seam (unit tests inject a DB-free stub).
     *
     * @return \FSFramework\model\tarif_tarifa
     */
    protected function tarifa_model()
    {
        return new \FSFramework\model\tarif_tarifa();
    }

    /**
     * Batch-reader seam for the per-tarifa columns.
     */
    protected function articulo_precio_batch_reader(): ArticuloTarifaPrecioBatchReader
    {
        return new ArticuloTarifaPrecioBatchReader();
    }

    /**
     * Price-model seam used by the quick-create per-tarifa writes.
     *
     * @return \FSFramework\model\tarif_articulo_precio
     */
    protected function articulo_precio_model()
    {
        return new \FSFramework\model\tarif_articulo_precio();
    }

    // =====================================================================
    // Filters (ALC-01, AD-W3-3)
    // =====================================================================

    /**
     * Loads the active tarifas and normalizes the absorbed filter aliases.
     * The canonical `search` / `codfamilia` keys win on conflict.
     */
    protected function init_list_filters(): void
    {
        $this->tarifas = (array) $this->tarifa_model()->all_activas();

        $requested = (string) $this->request->query->get('b_codtarifa', '');
        if ($requested === '') {
            $requested = (string) $this->request->query->get('codtarifa', '');
        }

        $this->b_codtarifa = $this->resolve_b_codtarifa($requested);
        $this->tarifa_seleccionada = $this->find_tarifa($this->b_codtarifa);

        $this->b_codfamilia = $this->codfamilia_filter();
        $this->b_solo_activos = $this->solo_activos_filter();
    }

    /**
     * Resolves the selected tarifa: a requested active member wins, otherwise
     * the default tarifa, otherwise the first active one, otherwise `''`.
     */
    public function resolve_b_codtarifa(string $requested): string
    {
        $tarifas = $this->tarifas;
        if ($tarifas === []) {
            $tarifas = (array) $this->tarifa_model()->all_activas();
        }

        $requested = trim($requested);
        if ($requested !== '') {
            foreach ($tarifas as $tarifa) {
                if ((string) $tarifa->codtarifa === $requested) {
                    return $requested;
                }
            }
        }

        $default = $this->tarifa_model()->get_default();
        if ($default) {
            return (string) $default->codtarifa;
        }

        if ($tarifas !== []) {
            return (string) $tarifas[0]->codtarifa;
        }

        return '';
    }

    /**
     * @return object|null
     */
    protected function find_tarifa(string $codtarifa)
    {
        foreach ($this->tarifas as $tarifa) {
            if ((string) $tarifa->codtarifa === $codtarifa) {
                return $tarifa;
            }
        }

        return null;
    }

    /**
     * Canonical `codfamilia` wins over its `b_codfamilia` alias.
     */
    public function codfamilia_filter(): string
    {
        $canonical = (string) $this->request->query->get('codfamilia', '');
        if ($canonical !== '') {
            return $canonical;
        }

        return (string) $this->request->query->get('b_codfamilia', '');
    }

    /**
     * `b_solo_activos` defaults to TRUE; only an explicit FALSE flips it.
     */
    protected function solo_activos_filter(): bool
    {
        $raw = $this->request->query->get('b_solo_activos', null);

        return $raw === null || mb_strtoupper((string) $raw) !== 'FALSE';
    }

    /**
     * Whether the search must include blocked articles (ALC-01/AD-W3-3).
     */
    protected function include_blocked_filter(): bool
    {
        return !$this->solo_activos_filter()
            || $this->request->query->get('bloqueados', '') === 'TRUE';
    }

    /**
     * Resolved `articulo::search()` arguments (spec ALC-01; canonical key wins
     * on conflict with its alias).
     *
     * @return array<string, mixed>
     */
    public function list_search_args(): array
    {
        $canonicalSearch = (string) $this->request->query->get('search', '');
        $aliasSearch = (string) $this->request->query->get('query', '');

        return [
            'search' => $canonicalSearch !== '' ? $canonicalSearch : $aliasSearch,
            'offset' => (int) $this->request->query->get('offset', 0),
            'codfamilia' => $this->codfamilia_filter(),
            'con_stock' => $this->request->query->get('con_stock', '') === 'TRUE',
            'codfabricante' => (string) $this->request->query->get('codfabricante', ''),
            'bloqueados' => $this->include_blocked_filter(),
            'b_solo_activos' => $this->solo_activos_filter(),
        ];
    }

    /**
     * Loads the active idiomas for the moved import/export actions.
     */
    protected function load_list_idiomas(): void
    {
        $idioma = new \FSFramework\model\catalogo_idioma();
        $idioma->ensure_defaults();
        $this->idiomas = (array) $idioma->all_activos();
    }

    // =====================================================================
    // Per-tarifa columns (ALC-02, AD-W3-4)
    // =====================================================================

    /**
     * Read-through seam (CAR-14). Overridable so unit tests pin both branches
     * without defining the config constant.
     */
    protected function caracteristica_read_through(): bool
    {
        return CaracteristicaConfig::read_through();
    }

    /**
     * Loads the per-tarifa price/state map for the listed references with one
     * batched query (never N+1). When the read-through flag is on, the two
     * visibility features are batched alongside it so the `Tarifa`/`Catálogo`
     * cells follow the resolver (CAR-14).
     *
     * @param array<int, string> $referencias
     */
    public function load_articulo_tarifa_columns(array $referencias): void
    {
        $this->articulo_tarifa_columns = $this->articulo_precio_batch_reader()
            ->for_referencias($referencias, (string) $this->b_codtarifa);

        if (!$this->caracteristica_read_through()) {
            $this->articulo_visibility_features = [];

            return;
        }

        $this->articulo_visibility_features = $this->caracteristica_batch_reader()
            ->for_referencias($referencias, (string) $this->b_codtarifa, null, self::VISIBILITY_CODIGOS);
    }

    /**
     * @return array{precio: float, activo: bool, en_tarifa: bool, en_catalogo: bool}
     */
    private function articulo_tarifa_row(string $referencia): array
    {
        return $this->articulo_tarifa_columns[$referencia] ?? [
            'precio' => 0.0,
            'activo' => true,
            'en_tarifa' => false,
            'en_catalogo' => false,
        ];
    }

    public function get_precio_articulo_tarifa($referencia): float
    {
        return (float) $this->articulo_tarifa_row((string) $referencia)['precio'];
    }

    public function articulo_activo_tarifa($referencia): bool
    {
        return (bool) $this->articulo_tarifa_row((string) $referencia)['activo'];
    }

    public function articulo_en_tarifa_flag($referencia): bool
    {
        return $this->articulo_visibility_bool((string) $referencia, 'en_tarifa');
    }

    public function articulo_en_catalogo($referencia): bool
    {
        return $this->articulo_visibility_bool((string) $referencia, 'en_catalogo');
    }

    /**
     * Visibility resolution: the resolver values while the flag is on, the
     * legacy per-tarifa columns otherwise (ALC-02/CAR-14). A missing resolver
     * value resolves to FALSE — the feature default.
     */
    private function articulo_visibility_bool(string $referencia, string $codigo): bool
    {
        if ($this->caracteristica_read_through()) {
            $value = $this->articulo_visibility_features[$referencia][$codigo] ?? null;

            return $value === 't' || $value === '1';
        }

        return (bool) $this->articulo_tarifa_row($referencia)[$codigo];
    }

    /**
     * Currency-formatted price of an article in the selected tarifa.
     */
    public function mostrar_precio_tarifa($referencia): string
    {
        if (!$this->tarifa_seleccionada) {
            return '';
        }

        $coddivisa = (string) ($this->tarifa_seleccionada->coddivisa ?? '');

        return CatalogoCurrencyFormatter::format(
            $this->get_precio_articulo_tarifa($referencia),
            $coddivisa
        );
    }

    /**
     * Currency symbol for a tarifa's divisa (AD-W3-4).
     */
    public function simbolo_divisa_tarifa(string $coddivisa): string
    {
        return CatalogoCurrencyFormatter::symbol($coddivisa);
    }

    // =====================================================================
    // listable feature columns (CAR-16)
    // =====================================================================

    /**
     * @var array<int, array<string, mixed>>
     */
    private array $caracteristica_columns = [];

    /**
     * @var array<string, array<string, ?string>>
     */
    private array $caracteristica_values = [];

    /**
     * Batch-reader seam for the listable feature columns.
     */
    protected function caracteristica_batch_reader(): CaracteristicaValorBatchReader
    {
        return new CaracteristicaValorBatchReader();
    }

    /**
     * Loads the listable feature columns and values for the page with one
     * batched read (never N+1). No tarifa selected ⇒ no feature columns.
     *
     * @param array<int, string> $referencias
     */
    public function load_caracteristica_columns(array $referencias): void
    {
        if (!$this->tarifa_seleccionada) {
            $this->caracteristica_columns = [];
            $this->caracteristica_values = [];

            return;
        }

        $reader = $this->caracteristica_batch_reader();
        $this->caracteristica_columns = $reader->columns();
        $this->caracteristica_values = $reader->for_referencias(
            $referencias,
            (string) $this->b_codtarifa
        );
    }

    /**
     * Ordered `listable` definitions; empty when no tarifa is selected.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listable_caracteristicas(): array
    {
        if (!$this->tarifa_seleccionada) {
            return [];
        }

        return $this->caracteristica_columns;
    }

    /**
     * Renders one feature cell: bool ⇒ Sí/No, string ⇒ its escaped value,
     * "no value" ⇒ the neutral placeholder `-`.
     */
    public function caracteristica_cell(string $referencia, string $codigo): string
    {
        $value = $this->caracteristica_values[$referencia][$codigo] ?? null;
        if ($value === null) {
            return '-';
        }

        if ($this->caracteristica_type($codigo) === 'bool') {
            return ($value === 't' || $value === '1') ? 'Sí' : 'No';
        }

        return (string) $value;
    }

    private function caracteristica_type(string $codigo): string
    {
        foreach ($this->caracteristica_columns as $column) {
            if ((string) ($column['codigo'] ?? '') === $codigo) {
                return (string) ($column['tipo'] ?? 'string');
            }
        }

        return 'string';
    }

    // =====================================================================
    // Quick-create per-tarifa prices (ALC-03, AD-W3-5)
    // =====================================================================

    /**
     * Resolved codtarifa of the current list context.
     */
    protected function tarifa_actual(): string
    {
        return (string) $this->b_codtarifa;
    }

    /**
     * Persists the fixed (`precio_tarifa_<codtarifa>`) and percentage
     * (`porcentaje_tarifa_<codtarifa>`, derives `pvp * (1 + p/100)`) prices
     * submitted by the quick-create modal. The fixed value wins for the same
     * tarifa; an empty field writes no row; a malformed value is skipped.
     *
     * @param object $articulo saved \articulo
     */
    public function persist_quick_create_tarifa_prices($articulo, $request): void
    {
        if ($this->tarifas === []) {
            return;
        }

        foreach ($this->tarifas as $tarifa) {
            $codtarifa = (string) $tarifa->codtarifa;
            if ($codtarifa === '') {
                continue;
            }

            $fixedRaw = $request->request->get('precio_tarifa_' . $codtarifa, null);
            $pctRaw = $request->request->get('porcentaje_tarifa_' . $codtarifa, null);

            $precio = null;
            if ($fixedRaw !== null && $fixedRaw !== '') {
                $precio = $this->parse_price_input($fixedRaw);
            } elseif ($pctRaw !== null && $pctRaw !== '') {
                $pct = $this->parse_price_input($pctRaw);
                if ($pct !== null) {
                    $precio = round(((float) $articulo->pvp) * (1 + $pct / 100), 2);
                }
            }

            if ($precio === null) {
                continue;
            }

            $row = $this->articulo_precio_model();
            $row->referencia = (string) $articulo->referencia;
            $row->codtarifa = $codtarifa;
            $row->precio = $precio;
            $row->save();
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

    // =====================================================================
    // Pagination query params (ALC-05)
    // =====================================================================

    /**
     * @return array<string, mixed>
     */
    public function getListQueryParams(): array
    {
        $params = $this->request->query->all();
        unset($params['page'], $params['action']);

        return $params;
    }
}
