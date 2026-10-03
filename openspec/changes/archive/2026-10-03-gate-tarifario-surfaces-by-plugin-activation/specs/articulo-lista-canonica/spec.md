# Delta for articulo-lista-canonica

## MODIFIED Requirements

### Requirement: ALC-02 — Per-tarifa columns

The list MUST show per row the tarifa's `precio` and `activo` (currency-priced
price) read from the per-tarifa price row, and `en_tarifa` / `en_catalogo`
resolved for that row through the `caracteristicas-producto` resolver (or, while
the read-through flag is disabled, through the legacy columns). A missing price
row MUST still default to `0 / TRUE / FALSE / FALSE`. In addition, every
`listable` feature definition MUST render as a dynamic column appended **after**
the existing per-tarifa columns (after `Catálogo`, before `Stock`), resolved with
one batched read per page and never with a per-row query. The base columns and
their order MUST NOT change.

The `en_tarifa` / `en_catalogo` visibility header and its per-row cells MUST
render ONLY while that visibility characteristic definition is active for the
current plugin set (per `visibility-characteristic-gating`); when a visibility
definition is inactive, its `Tarifa`/`Catálogo` header and cells MUST NOT render,
while the per-tarifa `precio`/`activo` columns and the `listable` append/order
rule remain unchanged. This gate applies identically in both
`FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` modes.
(Previously: `en_tarifa`/`en_catalogo` were read directly from
`tarif_articulo_precios`, and no feature columns existed. This change: the two
visibility characteristic columns became conditional on their definition's
active state; the multitarifa `precio`/`activo` columns stay unconditional.)

_Strength: MUST / MUST NOT._

#### Scenario: Rows render

- GIVEN rows and a missing row for the tarifa
- WHEN the list renders
- THEN currency-priced price/state, missing row 0/TRUE/FALSE/FALSE

#### Scenario: Visibility flags resolve through the feature resolver

- GIVEN an article whose effective `en_tarifa`/`en_catalogo` feature values differ from its stale per-tarifa columns
- WHEN the list renders with the read-through flag enabled
- THEN the rendered `Tarifa`/`Catálogo` cells follow the resolver
- Test: `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`

#### Scenario: Feature columns append after the per-tarifa block

- GIVEN `listable` feature definitions and a selected tarifa
- WHEN the list renders
- THEN each `listable` column appears after `Catálogo` and before `Stock`, ordered by `orden` then `codigo`
- AND the base header strings and their relative order are byte-identical to the pre-change list
- Test: `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`

#### Scenario: Feature columns use one batched read

- GIVEN a page of N products
- WHEN the list renders
- THEN the feature values are read with a query count independent of N
- Test: `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`

#### Scenario: Visibility columns absent while the definition is inactive

- GIVEN the `en_tarifa` and/or `en_catalogo` definition inactive for the current plugin set
- WHEN the list renders
- THEN no corresponding `Tarifa`/`Catálogo` header and no corresponding per-row cell is emitted
- AND the per-tarifa `precio`/`activo` columns and operator-owned `listable` columns still render with their relative order unchanged
- Test: `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php` (seeded off)
