# Delta for opcionales-management

## MODIFIED Requirements

### Requirement: OUM-03 — Per-tarifa state and price display

The unified list MUST display `activa` resolved through the master model
`effective()` accessor. `en_catalogo` and `en_tarifa` MUST be displayed as a
derived **read-only** indicator resolved from the parent product's effective
feature values for the selected tarifa (D12 existential union; no opcional-owned
flag exists). The list MUST also display the selected tarifa's value in the
opcional's mode: a fixed price for a fixed-price opcional, or the effective
percentage label for a percentage-price opcional.

The derived `en_catalogo` / `en_tarifa` visibility indicator (its `Tarifa`/
`Catálogo` header and per-row cells) MUST render ONLY while that visibility
characteristic definition is active for the current plugin set (per
`visibility-characteristic-gating`); when a visibility definition is inactive,
its indicator MUST NOT render, while the master `activa` cell and the
selected-tarifa value cell remain. This gate applies identically in both
`FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` modes.

(Previously: `en_catalogo`/`en_tarifa` were read from the per-tarifa master
state; before the percentage fix, only a fixed price was displayed, so percentage
opcionales rendered a zero amount. This change: the two visibility indicators
became conditional on their definition's active state; the master `activa` and
the selected-tarifa value stay unconditional.)

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

#### Scenario: Visibility indicators absent while the definition is inactive

- GIVEN the `en_catalogo` and/or `en_tarifa` definition inactive for the current plugin set
- WHEN the unified list renders
- THEN no corresponding `Tarifa`/`Catálogo` header and no corresponding derived per-row cell is emitted
- AND the master `activa` cell and the selected-tarifa value cell still render
- Test: `plugins/catalogo_core/tests/Controller/VentasOpcionalesControllerMasterStateTest.php` (seeded off)
