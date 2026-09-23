# Delta for familias-tarifa-management

Only `Toggle state management` changes: the two family visibility toggles stop
writing `tarif_tarifa_familia.en_catalogo`/`en_tarifa` and write familia-scope
feature values instead (`caracteristicas-producto` `CAR-09`, D3). The family page
ownership, the hierarchy API, the add/edit modal, the article count contract, the
Excel flows, the standalone table bootstrap, the reference-integrity gate, the
test-ownership split and the menu integration are unchanged.

The AJAX contract (action names, `codfamilia` input, JSON envelope
`{success, en_catalogo|en_tarifa|activa}`) MUST stay stable so the existing view
and JS keep working.

## MODIFIED Requirements

### Requirement: Toggle state management

The controller MUST expose AJAX toggles for `en_catalogo`, `en_tarifa`, and
`activa`, persisting the flipped state and responding with JSON. `activa` MUST
keep persisting on the per-tarifa family row. `en_catalogo` and `en_tarifa` MUST
persist as familia-scope feature values for the current tarifa through the
feature store (`caracteristicas-producto` `CAR-09`), MUST NOT write the
`tarif_tarifa_familia` visibility columns, and MUST return the same JSON shape as
before (including the flipped `en_catalogo`/`en_tarifa` value).
(Previously: all three toggles wrote the `tarif_tarifa_familia` columns.)

#### Scenario: Toggle flips state and answers JSON

- GIVEN a valid `codfamilia`
- WHEN each of the three toggles is invoked
- THEN the JSON response reports success and the new value
- AND `activa` flips on the family row while `en_catalogo`/`en_tarifa` flip the familia-scope feature value for that tarifa
- Test: `plugins/catalogo_core/tests/TarifFamiliasToggleTest.php`

#### Scenario: Visibility toggles leave the legacy columns untouched

- GIVEN a valid `codfamilia` and selected tarifa
- WHEN `toggle_catalogo` or `toggle_en_tarifa` runs
- THEN the familia-scope feature value changes and no `tarif_tarifa_familia` visibility column is written
- Test: `plugins/catalogo_core/tests/TarifFamiliasToggleTest.php`

#### Scenario: Toggle rejects unknown familia

- GIVEN a toggle request carrying an unknown `codfamilia`
- WHEN the toggle is invoked
- THEN the response is a JSON error and no HTML is emitted
- AND no feature value row is created
- Test: `plugins/catalogo_core/tests/TarifFamiliasToggleTest.php`

#### Scenario: Family visibility reaches descendant products

- GIVEN a familia with the catalog feature value set FALSE for the tarifa
- WHEN a product of that familia resolves the value with no nearer row
- THEN the value is not visible for that tarifa, lazily (no row copy)
- Test: `plugins/catalogo_core/tests/TarifFamiliasToggleTest.php`
