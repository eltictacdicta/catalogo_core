# opcionales-tarifa-selector Specification

## Purpose

Selected-tarifa control and scoped consumption for the opcionales (optional
per-tarifa customer catalog) surfaces owned by `catalogo_core`: a tarifa
selector in `tarif_opcional_edit` that scopes the editable "Precios por tarifa"
panel while retaining a compact all-tarifas overview, plus the htmx 4 + Alpine
CSP migration of the opcionales views (`tarif_opcional_edit` and
`ventas_opcionales`). The prior `tarif_opcional_precios` view is deleted with no
alias; its selector and scoped panel behavior now live only in
`tarif_opcional_edit`.

This capability consumes the existing per-tarifa master model and accessors
(`effective(` / `set_*`) owned by the `opcionales-tarifa-management`
capability; it does not redefine them. Persistence is the existing
`guardar_precio_tarifa` / `guardar_precios_tarifas` path, unchanged.

## Requirements

### Requirement: OTS-01 — Tarifa selector in tarif_opcional_edit

`tarif_opcional_edit` MUST expose a tarifa selector listing `$this->tarifas` with the default tarifa marked, driving the active `tarifa_seleccionada`.

#### Scenario: Selector lists active tarifas and marks the default

- **GIVEN** an opcional in `tarif_opcional_edit` with active `$this->tarifas` and a `$tarifa_defecto`
- **WHEN** the view renders
- **THEN** a tarifa `<select name="codtarifa">` lists every active tarifa
- **AND** the option for the active `tarifa_seleccionada` (default when none is requested) is marked selected

### Requirement: OTS-02 — Scoped editable panel plus compact overview

The selected tarifa MUST scope the editable "Precios por tarifa" panel (price +
`activa` through `tarif.toggle_button_group`) and MUST retain a compact
all-tarifas read-only overview. `en_catalogo`/`en_tarifa` MUST be rendered as a
derived **read-only** indicator resolved from the parent product for the selected
tarifa and MUST NOT be editable from this panel.
(Previously: the panel exposed editable `en_catalogo`/`en_tarifa` state controls.)

#### Scenario: Selected tarifa scopes the editable panel

- **GIVEN** `tarifa_seleccionada` is tarifa `T`
- **WHEN** the "Precios por tarifa" panel renders
- **THEN** the price and `activa` controls edit only `T` through `tarif.toggle_button_group`
- **AND** the catalog/tarifa visibility is shown as a derived read-only indicator
- Test: `plugins/catalogo_core/tests/TarifOpcionalEditCaracteristicaTest.php`

#### Scenario: All-tarifas read-only overview retained

- **GIVEN** the scoped editable panel for tarifa `T`
- **WHEN** the panel renders
- **THEN** a compact read-only overview of the remaining tarifas is still present

#### Scenario: No visibility write path from the panel

- **GIVEN** the rendered panel controls
- **WHEN** the form fields are inspected
- **THEN** no editable field targets `en_catalogo` or `en_tarifa`
- Test: `plugins/catalogo_core/tests/TarifOpcionalEditCaracteristicaTest.php`

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
- **WHEN** the markup is searched for `onchange="this.form.submit()`
- **THEN** the tarifa selector does not use it

### Requirement: OTS-05 — CSRF-guarded per-tarifa save

Saving a per-tarifa price/state MUST stay CSRF-guarded and MUST preserve the
`guardar_precio_tarifa` action and the `tarif.toggle_button_group(` contract for
the surviving state control (`activa`). The save MUST NOT accept or persist
`en_catalogo`/`en_tarifa` from the panel.
(Previously: the save also persisted the two visibility flags.)

#### Scenario: Scoped save preserves locked contracts

- **GIVEN** the scoped per-tarifa save form in `tarif_opcional_edit`
- **WHEN** the view source is inspected
- **THEN** it contains `guardar_precio_tarifa` and `{{ csrf_field() }}`
- **AND** it still calls `tarif.toggle_button_group(` for the surviving state control
- Test: `plugins/catalogo_core/tests/TarifOpcionalEditCaracteristicaTest.php`

#### Scenario: Posted visibility fields are ignored

- **GIVEN** a CSRF-valid save POST carrying `en_catalogo`/`en_tarifa`
- **WHEN** the save runs
- **THEN** no visibility value is persisted and the derived indicator is unchanged
- Test: `plugins/catalogo_core/tests/TarifOpcionalEditCaracteristicaTest.php`

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

### Requirement: OTS-09 — Test-locked contracts intact

Test-locked contracts MUST stay intact: opcional controllers `extends
fbase_controller`, the bulk `guardar_precios_tarifas` body, the master
`effective(`/`set_activa(` reads, and the locked view strings. The removed
`set_en_catalogo(` / `set_en_tarifa(` accessors MUST NOT be re-asserted, and the
visibility MUST be asserted through the derived indicator. The surviving opcional
controller set is `tarif_configurador_opcionales` and `tarif_opcional_edit`;
`tarif_opcional_precios` is deleted and MUST NOT be locked.
(Previously: the scenario asserted the master `set_en_catalogo(` /
`set_en_tarifa(` reads; an earlier revision also listed `tarif_opcional_precios`
among the inspected controllers.)

#### Scenario: Parent class and surviving master reads unchanged

- **GIVEN** the opcional controllers `tarif_configurador_opcionales` and `tarif_opcional_edit`
- **WHEN** the contract tests inspect them
- **THEN** each still `extends fbase_controller` (never `HtmxCrudController` or `tarif_controller`)
- **AND** the master `effective(` / `set_activa(` reads remain and no `set_en_catalogo(` / `set_en_tarifa(` read is asserted
- Test: `plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php`

#### Scenario: Bulk save and locked view strings preserved

- **GIVEN** the bulk `guardar_precios_tarifas` method and the locked view strings
- **WHEN** the master-state test runs
- **THEN** the bulk method body still carries its locked contracts (`requireCsrf()`, `run_in_transaction(`, `parse_price_input(`, `!$master->set_*`, `!$precio->save()`, no `floatval(`)
- **AND** the views keep `tarif.toggle_button_group(`, `guardar_precio_tarifa`, and `{{ csrf_field() }}`
- Test: `plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php`

### Requirement: OTS-10 — Unchanged boundaries

The master model, `tarif_configurador_opcionales`, `tpvmod`, and the historical
`tarif_opcional_precios` database table with its migration and dead-table
references MUST NOT change. The `tarif_opcional_precio` adapter MUST NOT change
its table identity or `codlista` key; additive, behavior-preserving members
(such as the percentage force flag introduced by OPG-01) MAY be added without
altering stored-value semantics.
(Previously: the boundary also asserted `ventas_opcionales` MUST NOT change — this change modifies it — and did not protect the historical database table explicitly. Reconciled at archive: the adapter clause now permits the additive force flag while still forbidding schema/identity changes.)

#### Scenario: Out-of-scope surfaces are untouched

- **GIVEN** `model/tarif_tarifa_opcional.php`, `controller/tarif_configurador_opcionales.php`, `tpvmod`, and the migration/dead-table references to the historical `tarif_opcional_precios` table
- **WHEN** the change diff is inspected
- **THEN** none of those files is modified by this change
- **AND** the `tarif_opcional_precio` adapter keeps its table identity and `codlista` key, with only additive behavior-preserving members allowed

### Requirement: OPG-03 — htmx 4 and Alpine CSP on the canonical detail

The canonical `tarif_opcional_edit` detail MUST use htmx 4 for its selector,
scoped panel and mutations and Alpine CSP for client logic: colon event names
only, `Alpine.data()` registered from a nonce'd classic script behind the
`alpine:init` guard with `x-cloak`, `hx-post` for every mutation, and no `|raw`
or `bootbox`.

#### Scenario: htmx 4 hygiene

- **GIVEN** the canonical detail markup and scripts
- **WHEN** their sources are inspected
- **THEN** only colon event names (`htmx:after:swap`, `htmx:after:request`) appear, mutations issue `hx-post`, and no `|raw` is present

#### Scenario: Alpine CSP registration

- **GIVEN** the canonical detail client logic
- **WHEN** the script is inspected
- **THEN** component logic is registered via `Alpine.data(` from a nonce'd classic script behind the `alpine:init` guard with `x-cloak`
- **AND** no `bootbox` call remains
