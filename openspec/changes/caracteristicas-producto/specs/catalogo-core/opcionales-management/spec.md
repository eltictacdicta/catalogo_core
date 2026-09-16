# Delta for opcionales-management

D12 of `caracteristicas-producto` removes the opcional-owned
`en_catalogo`/`en_tarifa` flags. Three requirements change: the row state display
(`OUM-03`), the state toggles (`OUM-04`) and the Excel export parity (`OUM-07`,
which gains an explicit boundary clause so its locked header set and row set
survive the column removal). `OUM-01`, `OUM-02`, `OUM-05`, `OUM-06`,
`OUM-08`…`OUM-12`, `OPG-01` and `OPG-02` are unchanged.

The list page, its selector, its filters, its pagination, its create/delete flows
and its percent/group behavior are owned by this capability and are not redefined
here.

## MODIFIED Requirements

### Requirement: OUM-03 — Per-tarifa state and price display

The unified list MUST display `activa` resolved through the master model
`effective()` accessor. `en_catalogo` and `en_tarifa` MUST be displayed as a
derived **read-only** indicator resolved from the parent product's effective
feature values for the selected tarifa (D12 existential union; no opcional-owned
flag exists). The list MUST also display the selected tarifa's value in the
opcional's mode: a fixed price for a fixed-price opcional, or the effective
percentage label for a percentage-price opcional.
(Previously: `en_catalogo`/`en_tarifa` were read from the per-tarifa master
state; before the percentage fix, only a fixed price was displayed, so percentage
opcionales rendered a zero amount.)

#### Scenario: Effective master state drives the row badges

- GIVEN a row with a stored or inherited master `activa` for the selected tarifa
- WHEN the list renders
- THEN `activa` reflects `effective()` and the read performs no persistence

#### Scenario: Visibility indicator is derived and read-only

- GIVEN a row whose parent product's effective `en_catalogo` is TRUE
- WHEN the list renders
- THEN the catalog indicator shows visible for the selected tarifa
- AND the render writes no visibility flag and exposes no visibility toggle
- Test: `plugins/catalogo_core/tests/Controller/VentasOpcionalesControllerMasterStateTest.php`

#### Scenario: Selected-tarifa value renders in the right mode

- GIVEN a row for the selected tarifa
- WHEN the list renders
- THEN a fixed-price opcional shows its price and a percentage-price opcional shows its effective percentage label, never a zero currency amount

### Requirement: OUM-04 — CSRF-guarded state toggles

The surviving state toggle `toggle_activa` MUST be a POST mutation guarded by
CSRF validation and MUST persist through the master `set_activa()` accessor. The
`toggle_en_catalogo` and `toggle_en_tarifa` actions and their `set_en_catalogo()` /
`set_en_tarifa()` persistence MUST be removed: opcional catalog/tarifa visibility
is derived from the parent product and MUST NOT be mutable from the opcionales
list.
(Previously: the list exposed three toggles, including the two visibility toggles.)

#### Scenario: Valid toggle persists

- GIVEN a POST `toggle_activa` with `id` and `codtarifa` and a valid CSRF token
- WHEN the toggle action runs
- THEN the master `activa` is updated and the list redirects preserving the active filters

#### Scenario: Removed visibility toggles do not mutate anything

- GIVEN a POST using the retired `toggle_en_catalogo` or `toggle_en_tarifa` action
- WHEN the request is handled
- THEN no state is persisted and no opcional visibility flag is written
- Test: `plugins/catalogo_core/tests/Controller/VentasOpcionalesControllerMasterStateTest.php`

#### Scenario: Invalid or missing CSRF is rejected

- GIVEN a toggle POST with a missing or invalid CSRF token
- WHEN the toggle action runs
- THEN no state is persisted and an explicit rejection is reported

#### Scenario: Toggle action registry no longer lists visibility toggles

- GIVEN the controller's public toggle-action constant
- WHEN it is inspected
- THEN it contains `toggle_activa` and neither `toggle_en_catalogo` nor `toggle_en_tarifa`
- Test: `plugins/catalogo_core/tests/Controller/VentasOpcionalesControllerMasterStateTest.php`

### Requirement: OUM-07 — Excel export parity

Exporting the unified list MUST emit the header set `Codigo (No editar)`,
`Ref SAP:`, `Descripción`, `Precio`, `Familia`, `Subfamilia`, MUST use the value
of the selected tarifa in the opcional's mode (fixed price, or effective
percentage with a percentage number format), and MUST honor the active filters.
The export MUST NOT read or depend on any opcional `en_catalogo`/`en_tarifa`
flag: with the flags removed, the emitted header set, the row set and the
selected-tarifa value MUST remain identical to the pre-change output.
(Previously: the export did not state its independence from the opcional
visibility flags; before the percentage fix it always wrote the numeric fixed
price, silently exporting zero for percentage opcionales.)

#### Scenario: Export headers and selected-tarifa value

- GIVEN a selected tarifa and exported rows
- WHEN the Excel export is generated
- THEN the exact header set above is present and each row carries its selected-tarifa value with resolved familia/subfamilia
- AND percentage-mode rows use a percentage number format instead of a currency amount

#### Scenario: Export is independent of the removed visibility flags

- GIVEN the same opcional data before and after the flag removal
- WHEN the export is generated for the same filters and tarifa
- THEN the header set and the row set are identical
- Test: `plugins/catalogo_core/tests/Controller/VentasOpcionalesExportParityTest.php`

#### Scenario: Export inherits the active filters

- GIVEN active `query`, `b_codfamilia` and `b_solo_activos` filters
- WHEN the export is generated
- THEN only rows matching those filters are exported
