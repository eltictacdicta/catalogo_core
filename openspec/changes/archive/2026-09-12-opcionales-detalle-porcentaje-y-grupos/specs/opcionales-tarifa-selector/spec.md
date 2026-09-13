# Delta for opcionales-tarifa-selector

Change: `opcionales-detalle-porcentaje-y-grupos` (plugin-local, `catalogo_core`).

## Purpose

Reconciled purpose: the tarifa selector, the scoped editable "Precios por
tarifa" panel and the compact all-tarifas overview live ONLY in
`tarif_opcional_edit`; the htmx 4 + Alpine CSP migration covers
`tarif_opcional_edit` and `ventas_opcionales` only. `tarif_opcional_precios` is
deleted with no alias, so the main spec's Purpose, `OTS-08` and `OTS-09`
references to it are removed or re-scoped to the canonical detail.

## MODIFIED Requirements

### Requirement: OTS-09 — Test-locked contracts intact

Test-locked contracts MUST stay intact: opcional controllers `extends
fbase_controller`, the bulk `guardar_precios_tarifas` body, the master
`effective(`/`set_*` reads, and the locked view strings. The surviving opcional
controller set is `tarif_configurador_opcionales` and `tarif_opcional_edit`;
`tarif_opcional_precios` is deleted and MUST NOT be locked.
(Previously: the scenario listed `tarif_opcional_precios` among the controllers whose parent class is inspected.)

#### Scenario: Parent class and master reads unchanged

- GIVEN the opcional controllers `tarif_configurador_opcionales` and `tarif_opcional_edit`
- WHEN the contract tests inspect them
- THEN each still `extends fbase_controller` (never `HtmxCrudController` or `tarif_controller`)
- AND the master `effective(` / `set_activa(` / `set_en_catalogo(` / `set_en_tarifa(` reads remain

#### Scenario: Bulk save and locked view strings preserved

- GIVEN the bulk `guardar_precios_tarifas` method and the locked view strings
- WHEN the master-state test runs
- THEN the bulk method body still carries its locked contracts (`requireCsrf()`, `run_in_transaction(`, `parse_price_input(`, `!$master->set_*`, `!$precio->save()`, no `floatval(`)
- AND the views keep `tarif.toggle_button_group(`, `guardar_precio_tarifa`, and `{{ csrf_field() }}`

### Requirement: OTS-10 — Unchanged boundaries

The master model, the `tarif_opcional_precio` adapter,
`tarif_configurador_opcionales`, `tpvmod`, and the historical
`tarif_opcional_precios` database table with its migration and dead-table
references MUST NOT change.
(Previously: the boundary also asserted `ventas_opcionales` MUST NOT change — this change modifies it — and did not protect the historical database table explicitly.)

#### Scenario: Out-of-scope surfaces are untouched

- GIVEN `model/tarif_tarifa_opcional.php`, `model/tarif_opcional_precio.php`, `controller/tarif_configurador_opcionales.php`, `tpvmod`, and the migration/dead-table references to the historical `tarif_opcional_precios` table
- WHEN the change diff is inspected
- THEN none of those files is modified by this change

## ADDED Requirements

### Requirement: OPG-03 — htmx 4 and Alpine CSP on the canonical detail

The canonical `tarif_opcional_edit` detail MUST use htmx 4 for its selector,
scoped panel and mutations and Alpine CSP for client logic: colon event names
only, `Alpine.data()` registered from a nonce'd classic script behind the
`alpine:init` guard with `x-cloak`, `hx-post` for every mutation, and no `|raw`
or `bootbox`.

#### Scenario: htmx 4 hygiene

- GIVEN the canonical detail markup and scripts
- WHEN their sources are inspected
- THEN only colon event names (`htmx:after:swap`, `htmx:after:request`) appear, mutations issue `hx-post`, and no `|raw` is present

#### Scenario: Alpine CSP registration

- GIVEN the canonical detail client logic
- WHEN the script is inspected
- THEN component logic is registered via `Alpine.data(` from a nonce'd classic script behind the `alpine:init` guard with `x-cloak`
- AND no `bootbox` call remains

## REMOVED Requirements

### Requirement: OTS-08 — List and precios filters migrate with locked names

(Reason: it locks the filter names of the deleted `tarif_opcional_precios` page, which is removed with no alias.)
(Migration: the surviving `ventas_opcionales` filter contract is owned by `opcionales-management` `OUM-02`, extended with `b_id_grupo`; no surviving behavior is lost.)
