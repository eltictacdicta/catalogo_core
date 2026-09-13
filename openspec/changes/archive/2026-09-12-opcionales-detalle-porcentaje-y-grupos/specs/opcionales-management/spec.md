# Delta for opcionales-management

Change: `opcionales-detalle-porcentaje-y-grupos` (plugin-local, `catalogo_core`).

This delta restores percentage pricing and opcional groups on the unified
management surface, makes every percentage write path symmetric, and completes
the single-opcional consolidation. Requirements `OUM-02`, `OUM-03`, `OUM-05`,
`OUM-07`, `OUM-11` and `OUM-12` are replaced in full below; `OPG-01` and
`OPG-02` are new.

## MODIFIED Requirements

### Requirement: OUM-02 — Filters and real-total pagination

The unified list MUST support `query` as the primary filter key with `search` as
an accepted alias, plus `b_codfamilia`, `b_codtarifa`, `b_id_grupo`,
`b_solo_activos` and `offset`, and MUST paginate by the true filtered total, not
by page size. `b_id_grupo` MUST accept a group identifier and a "Sin grupo"
sentinel.
(Previously: the filter set omitted `b_id_grupo`, so the list could not be filtered by group.)

#### Scenario: Filters are preserved across requests

- GIVEN the unified list
- WHEN a filter is applied through `query` (or its `search` alias) with `b_codfamilia`, `b_codtarifa`, `b_id_grupo`, `b_solo_activos` and `offset`
- THEN the filtered rows render and those keys are carried in the resulting URL

#### Scenario: Pagination uses the filtered count

- GIVEN a filter matching more rows than one page
- WHEN `search_opcionales` runs
- THEN the displayed total comes from `count_filtered(` over the same filters, and page links preserve the active filters

### Requirement: OUM-03 — Per-tarifa state and price display

The unified list MUST display the per-tarifa master state (`activa`,
`en_catalogo`, `en_tarifa`) resolved through the master model `effective()`
accessor, and MUST display the selected tarifa's value in the opcional's mode: a
fixed price for a fixed-price opcional, or the effective percentage label for a
percentage-price opcional.
(Previously: only a fixed price was displayed, so percentage opcionales rendered a zero amount.)

#### Scenario: Effective master state drives the row badges

- GIVEN a row with a stored or inherited master state for the selected tarifa
- WHEN the list renders
- THEN `activa`, `en_catalogo` and `en_tarifa` reflect `effective()` and the read performs no persistence

#### Scenario: Selected-tarifa value renders in the right mode

- GIVEN a row for the selected tarifa
- WHEN the list renders
- THEN a fixed-price opcional shows its price and a percentage-price opcional shows its effective percentage label, never a zero currency amount

### Requirement: OUM-05 — Opcional creation with validated prices

Creating an opcional MUST generate its code, reject duplicates, accept per-tarifa
`precio_tarifa_<code>` inputs parsed with comma/dot normalization, MUST accept a
price mode (`fijo`|`porcentaje`) with a validated percentage in percentage mode,
MUST accept an optional group assignment, and MUST be CSRF-guarded.
(Previously: creation accepted only per-tarifa fixed prices and had no percentage or group input.)

#### Scenario: Creation persists validated per-tarifa values

- GIVEN a new opcional payload with a unique code, at least one familia, a price mode and per-tarifa inputs
- WHEN creation runs with a valid CSRF token
- THEN the opcional is saved with its mode, validated percentage and optional group assignment
- AND each provided per-tarifa value is stored in the matching mode

#### Scenario: Malformed value or missing CSRF is rejected

- GIVEN a creation payload with a non-normalizable value or without a valid CSRF token
- WHEN creation runs
- THEN nothing is persisted and an explicit rejection is reported

### Requirement: OUM-07 — Excel export parity

Exporting the unified list MUST emit the header set `Codigo (No editar)`,
`Ref SAP:`, `Descripción`, `Precio`, `Familia`, `Subfamilia`, MUST use the value
of the selected tarifa in the opcional's mode (fixed price, or effective
percentage with a percentage number format), and MUST honor the active filters.
(Previously: the export always wrote the numeric fixed price, silently exporting zero for percentage opcionales.)

#### Scenario: Export headers and selected-tarifa value

- GIVEN a selected tarifa and exported rows
- WHEN the Excel export is generated
- THEN the exact header set above is present and each row carries its selected-tarifa value with resolved familia/subfamilia
- AND percentage-mode rows use a percentage number format instead of a currency amount

#### Scenario: Export inherits the active filters

- GIVEN active `query`, `b_codfamilia` and `b_solo_activos` filters
- WHEN the export is generated
- THEN only rows matching those filters are exported

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

- GIVEN the surviving opcional controllers and the deleted precios surface
- WHEN requested and `Init::upgrade()` runs twice
- THEN `tarif_opcional_edit` and `tarif_configurador_opcionales` are served by catalogo_core and extend `fbase_controller`, and the edit selector and htmx behavior are preserved
- AND no precios controller or view remains, its links point to `tarif_opcional_edit` with `codtarifa`, and the `fs_page` row is retired on the first run while the second run is a no-op

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

- GIVEN the unified controller and view
- WHEN `VentasOpcionalesControllerTest` runs
- THEN file existence, `privateCore`, page-data, legacy wrapper subclass, `{{ csrf_field() }}` and absence of `|raw` all hold

#### Scenario: Repointed and frozen contracts hold

- GIVEN the contract, master-state and htmx tests plus the host views
- WHEN the plugin suite runs
- THEN the contract test locks exactly two survivor slugs, master-state and htmx assertions target the unified controller/view, and `CatalogoCoreHookMarkersTest` still passes on the four frozen host markers
- AND the precios portions are repointed at `tarif_opcional_edit` or removed, and historical database-table references are unchanged

## ADDED Requirements

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

- GIVEN a percentage-mode opcional shown in the unified list, the injected Tarifas tab and the canonical detail's scoped panel
- WHEN each surface renders its selected-tarifa value
- THEN each shows the effective percentage label, never a zero currency amount
- AND fixed-price surfaces keep rendering the tarifa price

#### Scenario: Percentage per-tarifa save persists

- GIVEN a percentage-mode opcional and a tarifa
- WHEN its per-tarifa percentage is saved from the unified form, the tab or the detail panel
- THEN `porcentaje` is stored for that tarifa and `precio` is zero/ignored
- AND the effective reader agrees with what the surface displays

#### Scenario: Fixed-price save clears a stale percentage

- GIVEN a row that previously held a percentage value
- WHEN a fixed price is saved for it
- THEN `porcentaje` is cleared and `precio` is stored

### Requirement: OPG-02 — Grupo column in the unified list

The unified list MUST render a `Grupo` column showing each opcional's group label
(via the group label accessor), and MUST resolve those labels from a single group
map loaded once per request instead of a per-row lookup.

#### Scenario: Group column renders from a single map

- GIVEN a page of opcional rows with and without an assigned group
- WHEN the unified list renders
- THEN each row's `Grupo` cell shows the group label, or a placeholder when unassigned
- AND the labels are resolved from one map loaded once, not one lookup per row
