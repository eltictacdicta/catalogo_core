# opcionales-management Specification

## Purpose

Unified opcionales (optional per-tarifa customer catalog) management owned by
`catalogo_core`: `ventas_opcionales` is the single canonical opcionales
management page, absorbing the tarifa selector, filters, real-total pagination,
per-tarifa master state and price display, CSRF-guarded toggles, creation,
deletion and Excel export, while `tarif_opcionales` is eliminated. The
capability also owns the opcional "Tarifas" tab on `ventas_opcional` (hooks and
the `tarif_opcional_tab` endpoint). It consumes the per-tarifa master model and
accessors owned by the `opcionales-tarifa-management` capability and does not
redefine them.

## Requirements

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
an accepted alias, plus `b_codfamilia`, `b_codtarifa`, `b_id_grupo`,
`b_solo_activos` and `offset`, and MUST paginate by the true filtered total, not
by page size. `b_id_grupo` MUST accept a group identifier and a "Sin grupo"
sentinel.
(Previously: the filter set omitted `b_id_grupo`, so the list could not be filtered by group.)

#### Scenario: Filters are preserved across requests

- **GIVEN** the unified list
- **WHEN** a filter is applied through `query` (or its `search` alias) with `b_codfamilia`, `b_codtarifa`, `b_id_grupo`, `b_solo_activos` and `offset`
- **THEN** the filtered rows render and those keys are carried in the resulting URL

#### Scenario: Pagination uses the filtered count

- **GIVEN** a filter matching more rows than one page
- **WHEN** `search_opcionales` runs
- **THEN** the displayed total comes from `count_filtered(` over the same filters, and page links preserve the active filters

### Requirement: OUM-03 — Per-tarifa state and price display

The unified list MUST display the per-tarifa master state (`activa`,
`en_catalogo`, `en_tarifa`) resolved through the master model `effective()`
accessor, and MUST display the selected tarifa's value in the opcional's mode: a
fixed price for a fixed-price opcional, or the effective percentage label for a
percentage-price opcional.
(Previously: only a fixed price was displayed, so percentage opcionales rendered a zero amount.)

#### Scenario: Effective master state drives the row badges

- **GIVEN** a row with a stored or inherited master state for the selected tarifa
- **WHEN** the list renders
- **THEN** `activa`, `en_catalogo` and `en_tarifa` reflect `effective()` and the read performs no persistence

#### Scenario: Selected-tarifa value renders in the right mode

- **GIVEN** a row for the selected tarifa
- **WHEN** the list renders
- **THEN** a fixed-price opcional shows its price and a percentage-price opcional shows its effective percentage label, never a zero currency amount

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
`precio_tarifa_<code>` inputs parsed with comma/dot normalization, MUST accept a
price mode (`fijo`|`porcentaje`) with a validated percentage in percentage mode,
MUST accept an optional group assignment, and MUST be CSRF-guarded.
(Previously: creation accepted only per-tarifa fixed prices and had no percentage or group input.)

#### Scenario: Creation persists validated per-tarifa values

- **GIVEN** a new opcional payload with a unique code, at least one familia, a price mode and per-tarifa inputs
- **WHEN** creation runs with a valid CSRF token
- **THEN** the opcional is saved with its mode, validated percentage and optional group assignment
- **AND** each provided per-tarifa value is stored in the matching mode

#### Scenario: Malformed value or missing CSRF is rejected

- **GIVEN** a creation payload with a non-normalizable value or without a valid CSRF token
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
`Ref SAP:`, `Descripción`, `Precio`, `Familia`, `Subfamilia`, MUST use the value
of the selected tarifa in the opcional's mode (fixed price, or effective
percentage with a percentage number format), and MUST honor the active filters.
(Previously: the export always wrote the numeric fixed price, silently exporting zero for percentage opcionales.)

#### Scenario: Export headers and selected-tarifa value

- **GIVEN** a selected tarifa and exported rows
- **WHEN** the Excel export is generated
- **THEN** the exact header set above is present and each row carries its selected-tarifa value with resolved familia/subfamilia
- **AND** percentage-mode rows use a percentage number format instead of a currency amount

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

`tarif_opcional_edit` MUST be the single canonical single-opcional detail and
MUST absorb the per-tarifa selector and scoped price/state panel. It, together
with `tarif_configurador_opcionales`, MUST stay `catalogo_core`-owned on
`fbase_controller`, reachable, with the prior selector and htmx work preserved.
`tarif_opcional_precios` (controller and view) MUST be deleted with no alias, its
links MUST be repointed to `tarif_opcional_edit` while preserving `codtarifa`, and
its `fs_page` row MUST be retired idempotently from
`catalogo_core/Init.php::upgrade()`.
(Previously: `tarif_opcional_precios` was locked as a surviving controller alongside `tarif_opcional_edit` and `tarif_configurador_opcionales`.)

#### Scenario: Canonical detail survives, precios is retired

- **GIVEN** the surviving opcional controllers and the deleted precios surface
- **WHEN** requested and `Init::upgrade()` runs twice
- **THEN** `tarif_opcional_edit` and `tarif_configurador_opcionales` are served by catalogo_core and extend `fbase_controller`, and the edit selector and htmx behavior are preserved
- **AND** no precios controller or view remains, its links point to `tarif_opcional_edit` with `codtarifa`, and the `fs_page` row is retired on the first run while the second run is a no-op

### Requirement: OUM-12 — Locked contracts stay green

The existing locked contracts MUST remain satisfied once updated coherently:
`VentasOpcionalesControllerTest`, `CatalogoCoreHookMarkersTest` (four frozen
markers), the repointed master-state and htmx list assertions, and the retired
`tarif_opcionales` slug. `TarifOpcionalesControllerContractTest` MUST lock
exactly two surviving opcional controller slugs (`tarif_opcional_edit`,
`tarif_configurador_opcionales`); the precios portions of the master-state and
htmx tests MUST be repointed at `tarif_opcional_edit` or removed; and references
to the historical `tarif_opcional_precios` database table MUST remain untouched.
(Previously: the survivor slug set was not fixed at two, and the precios controller was still locked by the contract tests.)

#### Scenario: Unified controller and view contract holds

- **GIVEN** the unified controller and view
- **WHEN** `VentasOpcionalesControllerTest` runs
- **THEN** file existence, `privateCore`, page-data, legacy wrapper subclass, `{{ csrf_field() }}` and absence of `|raw` all hold

#### Scenario: Repointed and frozen contracts hold

- **GIVEN** the contract, master-state and htmx tests plus the host views
- **WHEN** the plugin suite runs
- **THEN** the contract test locks exactly two survivor slugs, master-state and htmx assertions target the unified controller/view, and `CatalogoCoreHookMarkersTest` still passes on the four frozen host markers
- **AND** the precios portions are repointed at `tarif_opcional_edit` or removed, and historical database-table references are unchanged

### Requirement: OPG-01 — Percentage rendering and write integrity across surfaces

Every opcional price surface MUST render a percentage when the opcional's mode is
percentage, using the effective percentage helper, and a fixed price otherwise.
Every percentage write path MUST persist through the per-tarifa percentage writer
(setting `porcentaje` and zeroing/ignoring `precio`), and every fixed-price
writer MUST clear the row's `porcentaje`, so switching modes never leaves a stale
value. This MUST hold for the unified list, the unified create/edit form, the
injected Tarifas tab and the canonical detail's scoped panel, preserving the
single `guardar_precio_tarifa` form.

#### Scenario: Percentage surfaces render the effective label

- **GIVEN** a percentage-mode opcional shown in the unified list, the injected Tarifas tab and the canonical detail's scoped panel
- **WHEN** each surface renders its selected-tarifa value
- **THEN** each shows the effective percentage label, never a zero currency amount
- **AND** fixed-price surfaces keep rendering the tarifa price

#### Scenario: Percentage per-tarifa save persists

- **GIVEN** a percentage-mode opcional and a tarifa
- **WHEN** its per-tarifa percentage is saved from the unified form, the tab or the detail panel
- **THEN** `porcentaje` is stored for that tarifa and `precio` is zero/ignored
- **AND** the effective reader agrees with what the surface displays

#### Scenario: Fixed-price save clears a stale percentage

- **GIVEN** a row that previously held a percentage value
- **WHEN** a fixed price is saved for it
- **THEN** `porcentaje` is cleared and `precio` is stored

### Requirement: OPG-02 — Grupo column in the unified list

The unified list MUST render a `Grupo` column showing each opcional's group label
(via the group label accessor), and MUST resolve those labels from a single group
map loaded once per request instead of a per-row lookup.

#### Scenario: Group column renders from a single map

- **GIVEN** a page of opcional rows with and without an assigned group
- **WHEN** the unified list renders
- **THEN** each row's `Grupo` cell shows the group label, or a placeholder when unassigned
- **AND** the labels are resolved from one map loaded once, not one lookup per row
