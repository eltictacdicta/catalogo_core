# articulo-lista-canonica Specification

## Purpose

Owned by `catalogo_core`: the list
`Controller/VentasArticulos.php` + `View/ventas_articulos.html.twig` absorbs
tarifario's `tarif_articulos` filters, per-tarifa columns and quick-create. Slug,
FQCN, pagination and RBAC ownership stay stable. Coverage:
`tests/Controller/VentasArticulosListAbsorptionTest.php`,
`tests/ArticuloListaCanonicaOwnershipTest.php`.

## Requirements

### Requirement: ALC-01 — Filter absorption

The list MUST accept `query`, `b_codfamilia`, `b_codtarifa` and
`b_solo_activos` (the first two aliasing `search`/`codfamilia`), select that tarifa
(default, else first) and keep `b_solo_activos`-active articles (default TRUE).

_Strength: MUST._

#### Scenario: Aliases match

- GIVEN `query`/`b_codfamilia` without `b_codtarifa`
- WHEN the `search`/`codfamilia` equivalent runs
- THEN identical rows, default tarifa, `b_solo_activos` TRUE

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

### Requirement: ALC-03 — Quick-create prices

`nuevoArticulo` MUST accept per tarifa a fixed price and/or a percentage (fixed
persists; percentage derives from PVP), ordered CSRF → neutral permission event →
`save()` → prices; failure or denial MUST persist nothing.

_Strength: MUST._

#### Scenario: Prices persist

- GIVEN one fixed price and one percentage in the POST
- WHEN an authorized, CSRF-valid save runs
- THEN each tarifa stores its price

#### Scenario: Denial persists nothing

- GIVEN a quick-create POST with per-tarifa prices
- WHEN CSRF is invalid or permission denied
- THEN nothing is persisted

### Requirement: ALC-04 — htmx 4 and Alpine CSP

`View/ventas_articulos.html.twig` MUST use htmx 4 + Alpine CSP: `hx-get` filters
with `hx-push-url`, `hx-post` mutations only, colon events only, nonce'd
`Alpine.data()` behind `alpine:init`, no `bootbox`/`|raw`; locked
view strings MUST remain.

_Strength: MUST._

#### Scenario: htmx hygiene

- GIVEN the migrated view
- WHEN inspected
- THEN colon events and `hx-post` only, nonce'd `Alpine.data(` behind `alpine:init`, no `bootbox`/`|raw`/v2 names

#### Scenario: Filter navigation

- GIVEN the filter controls
- WHEN a filter changes
- THEN `hx-get` + `hx-push-url` swap rows in the target

### Requirement: ALC-05 — Import/export continuity

Every pre-existing Excel/JSON import-export entry point MUST keep resolving and
the tarifario list's actions MUST NOT be lost on retirement; filtered export MUST
preserve active filters (WU-7 unifies).

_Strength: MUST._

#### Scenario: Entry points

- GIVEN the migrated list and filters
- WHEN export/preview/import entry points run
- THEN all respond and filtered export keeps the filters

### Requirement: ALC-06 — `tarif_articulos` retirement

The `tarif_articulos` controller and view MUST be deleted with no alias; inbound
links/`url()` MUST repoint to `ventas_articulos`; its `fs_page` row MUST retire
idempotently; the extension table stays (WU-4).

_Strength: MUST / MUST NOT._

#### Scenario: No alias

- GIVEN the applied change
- WHEN both plugins are searched
- THEN no file exists and no alias resolves the slug

#### Scenario: Links repointed

- GIVEN inbound links to `page=tarif_articulos`
- WHEN sources are searched
- THEN each targets `ventas_articulos` with filters preserved

#### Scenario: Menu row

- GIVEN the retired `fs_page` row
- WHEN `upgrade()` runs twice
- THEN deleted once, rerun a no-op

### Requirement: ALC-07 — No half-moved state

No WU-3 asset MAY exist in both plugins and no catalogo_core production file MAY
reference `plugins/tarifario/` or `@tarifario/` for the absorbed list; the RBAC
listener and `tarif_grupo_articulo` MUST stay in `tarifario`.

_Strength: MUST / MUST NOT._

#### Scenario: Zero paths + RBAC

- GIVEN catalogo_core production and tarifario booted
- WHEN references and the listener are inspected
- THEN zero tarifario hits, no duplicate, listener live on `ArticlePermissionFilterEvent::NAME`

### Requirement: ALC-08 — Locked contracts

`VentasArticulosControllerTest` MUST stay green unedited; tarifario tests locking
`tarif_articulos` MUST move with their code, MUST NOT assert the slug, and the
suite MUST stay ≥ 576/2147.

_Strength: MUST._

#### Scenario: Locked tests

- GIVEN the migration and the retired surface
- WHEN the controller test and both suites run
- THEN it passes unedited, no tarifario test asserts the slug, suite ≥ 576/2147
