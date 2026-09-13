# Delta for opcionales-tarifa-selector

New capability owned by `catalogo_core`. This delta adds requirements only: there is **no** delta for `opcionales-tarifa-management`, whose requirement set is owned by the still-unarchived sibling change `plugins/catalogo_core/openspec/changes/opcionales-por-tarifa/`. This change consumes the existing per-tarifa master/accessors without redefining them.

## ADDED Requirements

| ID | Requirement | Strength |
|----|-------------|----------|
| OTS-01 | `tarif_opcional_edit` MUST expose a tarifa selector listing `$this->tarifas` with the default tarifa marked, driving the active `tarifa_seleccionada`. | MUST |
| OTS-02 | The selected tarifa MUST scope the editable "Precios por tarifa" panel (price + `activa`/`en_catalogo`/`en_tarifa` through `tarif.toggle_button_group`) and MUST retain a compact all-tarifas read-only overview. | MUST |
| OTS-03 | An unknown or absent `codtarifa` MUST resolve to the default tarifa (or the first active tarifa when no default exists), never to an unscoped or wrong-tarifa state. | MUST |
| OTS-04 | The tarifa selector MUST update through htmx 4 (`hx-get`, `hx-trigger="change"`, `hx-target`/`hx-select` on the body, `hx-swap`, `hx-push-url`) and MUST NOT use a full-page `onchange="this.form.submit()"`. | MUST |
| OTS-05 | Saving a per-tarifa price/state MUST stay CSRF-guarded and MUST preserve the `guardar_precio_tarifa` action and the `tarif.toggle_button_group(` contract. | MUST |
| OTS-06 | Confirm/delete flows and the article search in `tarif_opcional_edit` MUST migrate to Alpine CSP: logic registered via `Alpine.data()` from a nonce'd inline script behind the `alpine:init` guard, no `bootbox` for migrated flows, and idempotent autocomplete re-init on `htmx:after:swap`. | MUST |
| OTS-07 | htmx event handling MUST use htmx 4 colon names only (`htmx:after:swap`, `htmx:after:request`); the v2 names (`htmx:afterSwap`, `htmx:afterRequest`) MUST be absent. | MUST |
| OTS-08 | `tarif_opcional_precios` and `tarif_opcionales` filters MUST migrate to `hx-get` + URL push while preserving the test-locked names (`codtarifa`/`b_codtarifa`, `query`, `b_codfamilia`, `b_solo_activos`, `offset`), `{{ csrf_field() }}`, and `action=toggle_*`. | MUST |
| OTS-09 | Test-locked contracts MUST stay intact: opcional controllers `extends fbase_controller`, the bulk `guardar_precios_tarifas` body, the master `effective(`/`set_*` reads, and the locked view strings. | MUST |
| OTS-10 | The master model, the `tarif_opcional_precio` adapter, `tarif_configurador_opcionales`, `tpvmod`, and `ventas_opcionales` MUST NOT change. | MUST |

### Requirement: OTS-01 — Tarifa selector in tarif_opcional_edit

`tarif_opcional_edit` MUST expose a tarifa selector listing `$this->tarifas` with the default tarifa marked, driving the active `tarifa_seleccionada`.

#### Scenario: Selector lists active tarifas and marks the default

- **GIVEN** an opcional in `tarif_opcional_edit` with active `$this->tarifas` and a `$tarifa_defecto`
- **WHEN** the view renders
- **THEN** a tarifa `<select name="codtarifa">` lists every active tarifa
- **AND** the option for the active `tarifa_seleccionada` (default when none is requested) is marked selected

### Requirement: OTS-02 — Scoped editable panel plus compact overview

The selected tarifa MUST scope the editable "Precios por tarifa" panel (price + `activa`/`en_catalogo`/`en_tarifa` through `tarif.toggle_button_group`) and MUST retain a compact all-tarifas read-only overview.

#### Scenario: Selected tarifa scopes the editable panel

- **GIVEN** `tarifa_seleccionada` is tarifa `T`
- **WHEN** the "Precios por tarifa" panel renders
- **THEN** the price and `activa` / `en_catalogo` / `en_tarifa` controls edit only `T` through `tarif.toggle_button_group`

#### Scenario: All-tarifas read-only overview retained

- **GIVEN** the scoped editable panel for tarifa `T`
- **WHEN** the panel renders
- **THEN** a compact read-only overview of the remaining tarifas is still present

### Requirement: OTS-03 — Safe fallback for unknown or absent codtarifa

An unknown or absent `codtarifa` MUST resolve to the default tarifa (or the first active tarifa when no default exists), never to an unscoped or wrong-tarifa state.

#### Scenario: Unknown codtarifa falls back to the default

- **GIVEN** a requested `codtarifa` that is not a member of `$this->tarifas`
- **WHEN** the controller resolves `tarifa_seleccionada`
- **THEN** it selects `tarifa_defecto` (or the first active tarifa if no default)
- **AND** the panel is scoped to a valid tarifa, never left unscoped

#### Scenario: Absent codtarifa defaults

- **GIVEN** a request with no `codtarifa` parameter
- **WHEN** the controller resolves `tarifa_seleccionada`
- **THEN** the default tarifa is selected, or the first active tarifa when no default exists

### Requirement: OTS-04 — Selector updates through htmx 4

The tarifa selector MUST update through htmx 4 (`hx-get`, `hx-trigger="change"`, `hx-target`/`hx-select` on the body, `hx-swap`, `hx-push-url`) and MUST NOT use a full-page `onchange="this.form.submit()"`.

#### Scenario: Selector carries htmx 4 attributes

- **GIVEN** the rendered `tarif_opcional_edit` selector
- **WHEN** the markup is inspected
- **THEN** it carries `hx-get`, `hx-trigger="change"`, `hx-target`/`hx-select` targeting the body, `hx-swap`, and `hx-push-url`

#### Scenario: No full-page submit for the tarifa selector

- **GIVEN** the rendered `tarif_opcional_edit` selector
- **WHEN** the markup is searched for `onchange="this.form.submit()"`
- **THEN** the tarifa selector does not use it

### Requirement: OTS-05 — CSRF-guarded per-tarifa save

Saving a per-tarifa price/state MUST stay CSRF-guarded and MUST preserve the `guardar_precio_tarifa` action and the `tarif.toggle_button_group(` contract.

#### Scenario: Scoped save preserves locked contracts

- **GIVEN** the scoped per-tarifa save form in `tarif_opcional_edit`
- **WHEN** the view source is inspected
- **THEN** it contains `guardar_precio_tarifa` and `{{ csrf_field() }}`
- **AND** it still calls `tarif.toggle_button_group(` for the state controls

### Requirement: OTS-06 — Alpine CSP migration of flows

Confirm/delete flows and the article search in `tarif_opcional_edit` MUST migrate to Alpine CSP: logic registered via `Alpine.data()` from a nonce'd inline script behind the `alpine:init` guard, no `bootbox` for migrated flows, and idempotent autocomplete re-init on `htmx:after:swap`.

#### Scenario: Alpine logic is registered without inline eval

- **GIVEN** the migrated confirm/delete flows and article search
- **WHEN** the view source is inspected
- **THEN** component logic is registered via `Alpine.data(` from a script carrying `{{ csp_nonce_attr() }}` and guarded by the `alpine:init` listener
- **AND** no `bootbox` call remains for the migrated flows

#### Scenario: Article search re-initializes idempotently after swap

- **GIVEN** an htmx body swap replaced the article search region
- **WHEN** `htmx:after:swap` fires
- **THEN** the autocomplete/re-init runs at most once per region and binds no duplicate handlers

### Requirement: OTS-07 — htmx 4 colon event names only

htmx event handling MUST use htmx 4 colon names only (`htmx:after:swap`, `htmx:after:request`); the v2 names (`htmx:afterSwap`, `htmx:afterRequest`) MUST be absent.

#### Scenario: Colon event names present, v2 names absent

- **GIVEN** the migrated opcionales views and any opcionales JS
- **WHEN** their sources are searched for htmx event listeners
- **THEN** only `htmx:after:swap` / `htmx:after:request` appear
- **AND** `htmx:afterSwap` / `htmx:afterRequest` do not appear

### Requirement: OTS-08 — List and precios filters migrate with locked names

`tarif_opcional_precios` and `tarif_opcionales` filters MUST migrate to `hx-get` + URL push while preserving the test-locked names (`codtarifa`/`b_codtarifa`, `query`, `b_codfamilia`, `b_solo_activos`, `offset`), `{{ csrf_field() }}`, and `action=toggle_*`.

#### Scenario: Filters use hx-get with URL push

- **GIVEN** the `tarif_opcionales` and `tarif_opcional_precios` filter controls
- **WHEN** the view sources are inspected
- **THEN** the filters issue `hx-get` with URL push instead of a full-page submit

#### Scenario: Locked names, CSRF, and toggle actions preserved

- **GIVEN** the migrated list and precios views
- **WHEN** their sources are inspected
- **THEN** `name="codtarifa"`/`name="b_codtarifa"`, `name="query"`, `name="b_codfamilia"`, `name="b_solo_activos"`, and `name="offset"` remain
- **AND** `{{ csrf_field() }}` and `action=toggle_*` remain

### Requirement: OTS-09 — Test-locked contracts intact

Test-locked contracts MUST stay intact: opcional controllers `extends fbase_controller`, the bulk `guardar_precios_tarifas` body, the master `effective(`/`set_*` reads, and the locked view strings.

#### Scenario: Parent class and master reads unchanged

- **GIVEN** the opcional controllers `tarif_opcionales`, `tarif_opcional_edit`, and `tarif_opcional_precios`
- **WHEN** the contract tests inspect them
- **THEN** each still `extends fbase_controller` (never `HtmxCrudController` or `tarif_controller`)
- **AND** the master `effective(` / `set_activa(` / `set_en_catalogo(` / `set_en_tarifa(` reads remain

#### Scenario: Bulk save and locked view strings preserved

- **GIVEN** the bulk `guardar_precios_tarifas` method and the locked view strings
- **WHEN** the master-state test runs
- **THEN** the bulk method body still carries its locked contracts (`requireCsrf()`, `run_in_transaction(`, `parse_price_input(`, `!$master->set_*`, `!$precio->save()`, no `floatval(`)
- **AND** the views keep `tarif.toggle_button_group(`, `guardar_precio_tarifa`, and `{{ csrf_field() }}`

### Requirement: OTS-10 — Unchanged boundaries

The master model, the `tarif_opcional_precio` adapter, `tarif_configurador_opcionales`, `tpvmod`, and `ventas_opcionales` MUST NOT change.

#### Scenario: Out-of-scope surfaces are untouched

- **GIVEN** `model/tarif_tarifa_opcional.php`, `model/tarif_opcional_precio.php`, `controller/tarif_configurador_opcionales.php` in this plugin, and `tpvmod` / `ventas_opcionales`
- **WHEN** the change diff is inspected
- **THEN** none of those files is modified by this change
