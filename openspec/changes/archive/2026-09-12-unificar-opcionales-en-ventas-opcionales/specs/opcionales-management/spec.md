# Delta for opcionales-management

New capability owned by `catalogo_core`. All requirements are ADDED; there is no
prior canonical `opcionales-management` spec and no MODIFIED/REMOVED block. The
sibling capabilities `opcionales-tarifa-management`, `catalogo-render-hooks` and
`opcionales-tarifa-selector` are not redefined here.

## ADDED Requirements

### Requirement: OUM-01 — Canonical unified opcionales management page

`ventas_opcionales` MUST be the single canonical opcionales management page,
served by `Controller/VentasOpcionales.php` (on `PageController`) plus its legacy
wrapper, and MUST absorb the tarifa selector with validated resolution: a
requested tarifa that is a member of the active set is used, otherwise resolution
MUST fall back to the default tarifa and then to the first active tarifa. No
second opcionales management page SHALL remain.

#### Scenario: Canonical page serves the absorbed behavior

- **GIVEN** catalogo_core active
- **WHEN** `page=ventas_opcionales` is requested
- **THEN** the modern controller and its legacy wrapper serve it with page data `name=ventas_opcionales` and `showonmenu=true`
- **AND** selector, filters, per-tarifa state/price, toggles, create, delete and Excel export are reachable from it

#### Scenario: Tarifa selector resolves with safe fallback

- **GIVEN** an active set of tarifas and a default tarifa
- **WHEN** the controller resolves the selected tarifa from `codtarifa`
- **THEN** a requested member is selected, while an unknown or absent value resolves to the default, or to the first active tarifa when no default exists

### Requirement: OUM-02 — Filters and real-total pagination

The unified list MUST support `query` as the primary filter key with `search` as
an accepted alias, plus `b_codfamilia`, `b_codtarifa`, `b_solo_activos` and
`offset`, and MUST paginate by the true filtered total, not by page size.

#### Scenario: Filters are preserved across requests

- **GIVEN** the unified list
- **WHEN** a filter is applied through `query` (or its `search` alias) with `b_codfamilia`, `b_codtarifa`, `b_solo_activos` and `offset`
- **THEN** the filtered rows render and those keys are carried in the resulting URL

#### Scenario: Pagination uses the filtered count

- **GIVEN** a filter matching more rows than one page
- **WHEN** `search_opcionales` runs
- **THEN** the displayed total comes from `count_filtered(` over the same filters, and page links preserve the active filters

### Requirement: OUM-03 — Per-tarifa state and price display

The unified list MUST display the per-tarifa master state (`activa`,
`en_catalogo`, `en_tarifa`) resolved through the master model `effective()`
accessor, and MUST display the price for the selected tarifa.

#### Scenario: Effective master state drives the row badges

- **GIVEN** a row with a stored or inherited master state for the selected tarifa
- **WHEN** the list renders
- **THEN** `activa`, `en_catalogo` and `en_tarifa` reflect `effective()` and the read performs no persistence

#### Scenario: Selected-tarifa price is shown

- **GIVEN** a row with a price for the selected tarifa
- **WHEN** the list renders
- **THEN** the price cell shows the value for the selected tarifa

### Requirement: OUM-04 — CSRF-guarded state toggles

State toggles (`toggle_activa`, `toggle_en_catalogo`, `toggle_en_tarifa`) MUST
be POST mutations guarded by CSRF validation and MUST persist through the master
`set_*` accessors.

#### Scenario: Valid toggle persists

- **GIVEN** a POST toggle with `id` and `codtarifa` and a valid CSRF token
- **WHEN** the toggle action runs
- **THEN** the targeted master state is updated and the list redirects preserving the active filters

#### Scenario: Invalid or missing CSRF is rejected

- **GIVEN** a toggle POST with a missing or invalid CSRF token
- **WHEN** the toggle action runs
- **THEN** no state is persisted and an explicit rejection is reported

### Requirement: OUM-05 — Opcional creation with validated prices

Creating an opcional MUST generate its code, reject duplicates, accept per-tarifa
`precio_tarifa_<code>` inputs parsed with comma/dot normalization, and MUST be
CSRF-guarded.

#### Scenario: Creation persists validated per-tarifa prices

- **GIVEN** a new opcional payload with a unique code, at least one familia and per-tarifa price inputs
- **WHEN** creation runs with a valid CSRF token
- **THEN** the opcional is saved and each provided per-tarifa price is stored for the selected tarifa

#### Scenario: Malformed price or missing CSRF is rejected

- **GIVEN** a creation payload with a non-normalizable price or without a valid CSRF token
- **WHEN** creation runs
- **THEN** nothing is persisted and an explicit rejection is reported

### Requirement: OUM-06 — Permission-gated delete

Deleting an opcional from the unified list MUST require the delete permission and
MUST NOT proceed when the permission is absent.

#### Scenario: Authorized delete proceeds

- **GIVEN** a user with delete permission and an existing opcional
- **WHEN** the delete action is requested
- **THEN** the opcional is deleted and the list redirects preserving the active filters

#### Scenario: Unauthorized delete is blocked

- **GIVEN** a user without delete permission
- **WHEN** the delete action is requested
- **THEN** the opcional is not deleted and no destructive side effect occurs

### Requirement: OUM-07 — Excel export parity

Exporting the unified list MUST emit the header set `Codigo (No editar)`,
`Ref SAP:`, `Descripción`, `Precio`, `Familia`, `Subfamilia`, MUST use the price
of the selected tarifa, and MUST honor the active filters.

#### Scenario: Export headers and selected-tarifa price

- **GIVEN** a selected tarifa and exported rows
- **WHEN** the Excel export is generated
- **THEN** the exact header set above is present and each row carries the selected-tarifa price with resolved familia/subfamilia

#### Scenario: Export inherits the active filters

- **GIVEN** active `query`, `b_codfamilia` and `b_solo_activos` filters
- **WHEN** the export is generated
- **THEN** only rows matching those filters are exported

### Requirement: OUM-08 — htmx 4 and Alpine CSP interaction

The unified list MUST use htmx 4 for filter and mutation flows and Alpine CSP for
client logic, and MUST NOT use htmx v2 patterns.

#### Scenario: Filters swap the body and push the URL

- **GIVEN** the unified list filters
- **WHEN** they are inspected
- **THEN** they issue `hx-get` with a body target/select, a swap and URL push

#### Scenario: Mutations use POST

- **GIVEN** the toggle/create/delete/export flows
- **WHEN** they are inspected
- **THEN** mutations are issued through `hx-post` or `htmx.ajax('POST')`

#### Scenario: Alpine CSP and event hygiene

- **GIVEN** the unified list script and markup
- **WHEN** inspected
- **THEN** component logic is registered via `Alpine.data()` from a nonce'd classic script behind an `alpine:init` guard with `x-cloak`, no `bootbox` remains
- **AND** only colon event names (`htmx:after:swap`, `htmx:after:request`) appear, v2 names are absent, and no `|raw` is used

### Requirement: OUM-09 — tarif_opcionales elimination and link repointing

`tarif_opcionales` MUST be eliminated (controller, view and list-specific tests
removed, no redirect alias) with its `fs_page` row retired idempotently from
`catalogo_core/Init.php::upgrade()`, and every link or `url()` fallback MUST be
repointed.

#### Scenario: Page is gone and its registry row retired idempotently

- **GIVEN** the applied change
- **WHEN** `page=tarif_opcionales` is requested and `Init::upgrade()` runs twice
- **THEN** no `tarif_opcionales` controller or view exists, the `fs_page` row is retired on the first run and the second run is a no-op
- **AND** no entry for this change exists in the core `openspec/`

#### Scenario: References repointed, historical DB names untouched

- **GIVEN** `tarif_opcional::url()` and `tarif_opcional_precio::url()` no-id fallbacks, the catalogo_core opcional views (`tarif_opcional_edit`, `tarif_opcional`, `tarif_opcional_precios`) and the tarifario views (`tarif_actualizar_precios`, `tarif_articulo_precios`, `tarif_articulos`, `tarif_historial_precios`)
- **WHEN** they are inspected
- **THEN** each points to `ventas_opcionales` and no page link remains
- **AND** references to the historical `tarif_opcionales` DB table in migration services and dead-table tests are unchanged

### Requirement: OUM-10 — catalogo_core owns the opcional Tarifas tab

The opcional "Tarifas" tab on `ventas_opcional` MUST be owned by `catalogo_core`:
it MUST register the two opcional hooks (`ventas_opcional_tabs_after`,
`ventas_opcional_tab_pane_after`) behind an idempotent guard and MUST own the
opcional rows/save endpoint on `fbase_controller` with a currency helper.
`tarifario` MUST keep only the article surface of `tarif_tab_precios` and the
`ventas_articulo_*` hooks, byte-identical.

#### Scenario: catalogo_core registers and serves the opcional tab

- **GIVEN** catalogo_core active and tarifario inactive
- **WHEN** the hooks are registered and the opcional rows/save endpoint is reached
- **THEN** the two opcional hooks are registered at most once and the endpoint renders per-tarifa rows with the correct currency

#### Scenario: tarifario keeps only the article surface

- **GIVEN** tarifario's `Init.php`, hook templates and `tarif_tab_precios`
- **WHEN** inspected
- **THEN** it registers no opcional hook, keeps the `ventas_articulo_*` pair and the article path unchanged byte-for-byte

### Requirement: OUM-11 — Surviving opcional controllers preserved

`tarif_opcional_edit`, `tarif_opcional_precios` and
`tarif_configurador_opcionales` MUST stay `catalogo_core`-owned on
`fbase_controller`, reachable, with their prior selector and htmx work preserved.

#### Scenario: Surviving controllers remain reachable and unchanged

- **GIVEN** the three surviving opcional controllers
- **WHEN** requested and inspected
- **THEN** each is served by catalogo_core and still extends `fbase_controller`
- **AND** the `tarif_opcional_edit` tarifa selector and its htmx behavior are preserved

### Requirement: OUM-12 — Locked contracts stay green

The existing locked contracts MUST remain satisfied: `VentasOpcionalesControllerTest`,
`CatalogoCoreHookMarkersTest` (four frozen markers), the repointed master-state
and htmx list assertions, and the retired `tarif_opcionales` slug.

#### Scenario: Unified controller and view contract holds

- **GIVEN** the unified controller and view
- **WHEN** `VentasOpcionalesControllerTest` runs
- **THEN** file existence, `privateCore`, page-data, legacy wrapper subclass, `{{ csrf_field() }}` and absence of `|raw` all hold

#### Scenario: Repointed and frozen contracts hold

- **GIVEN** the contract, master-state and htmx tests plus the host views
- **WHEN** the plugin suite runs
- **THEN** `TarifOpcionalesControllerContractTest` no longer references the `tarif_opcionales` slug, master-state/htmx list assertions target the unified controller/view, and `CatalogoCoreHookMarkersTest` still passes on the four frozen host markers
