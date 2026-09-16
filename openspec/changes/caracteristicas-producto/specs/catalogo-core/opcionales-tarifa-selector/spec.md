# Delta for opcionales-tarifa-selector

`OTS-02`, `OTS-05` and `OTS-09` change because the opcional
`en_catalogo`/`en_tarifa` flags they lock are removed by D12 of
`caracteristicas-producto`. `OTS-01`, `OTS-03`, `OTS-04`, `OTS-06`, `OTS-07`,
`OTS-10` and `OPG-03` are unchanged.

## MODIFIED Requirements

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
