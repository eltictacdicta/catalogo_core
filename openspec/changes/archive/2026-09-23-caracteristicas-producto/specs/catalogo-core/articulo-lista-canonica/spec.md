# Delta for articulo-lista-canonica

Only `ALC-02` changes. The list ownership, pagination and RBAC remain those of the
capability; the feature-column seam is defined by `caracteristicas-producto`
(`CAR-16`) and is only referenced here so the locked list contract stays coherent.

`ALC-04` (htmx 4 and Alpine CSP) and `ALC-01`, `ALC-03`, `ALC-05`…`ALC-08` are
**unchanged** by this change: the base header strings, their relative order and
the locked view strings MUST remain byte-identical, and only the empty-row
`colspan` computation MAY adjust to account for dynamically appended feature
columns.

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
(Previously: `en_tarifa`/`en_catalogo` were read directly from
`tarif_articulo_precios`, and no feature columns existed.)

_Strength: MUST._

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
