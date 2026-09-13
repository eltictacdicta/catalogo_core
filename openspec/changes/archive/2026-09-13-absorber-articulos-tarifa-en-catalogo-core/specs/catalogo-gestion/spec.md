# Delta for catalogo-gestion

New `catalogo_core` capability: the whole tarifario catalog manager
(`tarif_catalogo_view` + the five `tarif_catalogo*` models/tables + their views,
partials and JS) is absorbed into `catalogo_core` as a catalogo_core-owned page
and **retired** from `tarifario`. This is delivery unit **WU-5** of the
absorption program (depends on WU-1..WU-4, all applied), following **Option A**
from the exploration (§3.5). Canonical page: `ventas_catalogo` on
`PageController`, mirroring the opcionales unification where the old slug is
retired. Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
(baseline **611 tests / 2422 assertions**).

## ADDED Requirements

### Requirement: CAT-01 — `catalogo_core` owns the catalog models and schemas

`FSFramework\model\tarif_catalogo`, `…\tarif_catalogo_articulo`,
`…\tarif_catalogo_def`, `…\tarif_catalogo_def_articulo` and
`…\tarif_catalogo_def_familia` plus their `model/table/*.xml` schemas MUST live
in `catalogo_core`. The FQCNs and the physical table names MUST stay
byte-stable (`tarif_catalogos`, `tarif_catalogo_articulo`, `tarif_catalogo_defs`,
`tarif_catalogo_def_articulo`, `tarif_catalogo_def_familia`); no class or XML MAY
exist in both plugins; and a standalone `catalogo_core` (tarifario inactive) MUST
ensure the five tables idempotently during boot.

_Strength: MUST / MUST NOT._

#### Scenario: Models and schemas resolve only from catalogo_core

- GIVEN catalogo_core active and tarifario inactive
- WHEN the five models are loaded and both plugin trees are searched
- THEN each resolves from `plugins/catalogo_core/model/` with its table name unchanged and its XML only under `plugins/catalogo_core/model/table/`
- AND the tarifario counterparts are absent (no dual class/XML)

#### Scenario: Standalone catalogo_core ensures the catalog tables idempotently

- GIVEN catalogo_core active with tarifario inactive and its Init booted twice
- WHEN the catalog bootstrap runs
- THEN the five tables are ensured on the first run and the rerun is a harmless no-op

### Requirement: CAT-02 — The canonical catalog page is catalogo_core-owned

The catalog manager MUST be served by a catalogo_core-owned page `ventas_catalogo`
(class `VentasCatalogo`) on `PageController`, registered in the plugin page
registry, with no `tarif_controller` base. It MUST preserve the current catalog
role/permission checks (view always allowed; mutations gated) and the
selected-tarifa context. The retired `tarif_catalogo_view` class MUST NOT be
reused.

_Strength: MUST / MUST NOT._

#### Scenario: Canonical page resolves

- GIVEN catalogo_core active
- WHEN `page=ventas_catalogo&codtarifa=…` is requested
- THEN the `VentasCatalogo` page renders with the selected tarifa and its registry/menu entry

#### Scenario: Role/permission checks preserved

- GIVEN a user without catalog mutation rights
- WHEN the catalog page or a mutating catalog action is requested
- THEN the page renders read-only and the mutation is refused with nothing persisted

### Requirement: CAT-03 — Catalog behaviors preserved

The canonical page MUST preserve the catalog tree, search, action dispatch and
the existing HTMX fragment endpoints (`htmx_articulos`,
`htmx_articulos_agrupados`, `htmx_search`) with their current response/swap
shapes, so every pre-existing catalog interaction keeps its behavior.

_Strength: MUST._

#### Scenario: Tree, search and action dispatch preserved

- GIVEN the canonical catalog page
- WHEN the tree renders, a search runs, and an action is dispatched
- THEN the ordered family/articulo tree, the search results and the action result match the previous catalog output

#### Scenario: Fragment endpoints preserved

- GIVEN an HTMX fragment request (`htmx_articulos`, `htmx_articulos_agrupados` or `htmx_search`)
- WHEN it is dispatched to the canonical page
- THEN it returns the same fragment shape and swap target it did from `tarif_catalogo_view`

### Requirement: CAT-04 — htmx 4 + Alpine CSP and catalog entry points

The migrated catalog UI MUST use htmx 4 + Alpine CSP: colon-only events,
nonce'd `Alpine.data()` behind `alpine:init`, `hx-post` for mutations only, no
`|raw` and no `bootbox`. The catalog toolbar/tree and the notes and images entry
points MUST remain reachable.

_Strength: MUST / MUST NOT._

#### Scenario: htmx/Alpine hygiene

- GIVEN the migrated catalog views and JS
- WHEN inspected
- THEN colon events only, nonce'd `Alpine.data(` behind `alpine:init`, `hx-post` mutations only, and no `bootbox`/`|raw`/v2 event names

#### Scenario: Toolbar, tree and notes/images entry points preserved

- GIVEN the canonical catalog page
- WHEN the toolbar and tree render
- THEN the tree, notes and images entry points are present and wired

### Requirement: CAT-05 — `tarif_catalogo_view` retired with no alias

The `tarif_catalogo_view` controller, its views
(`View/tarif_catalogo_view.html.twig`, `tarif_catalogo_articulos.html.twig`,
`tarif_catalogo_articulos_agrupados.html.twig`, `tarif_catalogo_search.html.twig`),
`View/partials/catalogo/*` and `View/js/catalogo/*` MUST be deleted with no
alias. Every inbound link MUST repoint to `page=ventas_catalogo`, and its
`fs_page` row MUST retire idempotently in `catalogo_core/Init.php::upgrade()`.

_Strength: MUST / MUST NOT._

#### Scenario: No alias

- GIVEN the applied change
- WHEN both plugins are searched
- THEN no `tarif_catalogo_view` controller/view file exists and no alias resolves the slug

#### Scenario: Inbound links repointed

- GIVEN inbound links to `page=tarif_catalogo_view` (including `catalogo_core/View/Macro/TarifarioComponents.html.twig` and tarifario catalog views)
- WHEN the sources are searched
- THEN each targets `page=ventas_catalogo` with the selected tarifa preserved

#### Scenario: Menu row

- GIVEN the retired `tarif_catalogo_view` `fs_page` row
- WHEN `upgrade()` runs twice
- THEN it is deleted once and the rerun is a no-op

### Requirement: CAT-06 — No half-moved state; RBAC stays in tarifario

No catalog asset MAY exist in both plugins, and no `catalogo_core` production
file MAY reference `plugins/tarifario/` or `@tarifario/` for the absorbed
catalog surfaces. `ArticlePermissionListener` and the RBAC role tables MUST stay
in `tarifario`, and catalogo_core MUST gate mutations via the neutral/soft seam.

_Strength: MUST / MUST NOT._

#### Scenario: Zero tarifario paths and no duplicate

- GIVEN catalogo_core production (tests/vendor/openspec excluded)
- WHEN references to the moved catalog surfaces are searched
- THEN zero `plugins/tarifario/` / `@tarifario/` hits remain and no moved asset is duplicated

#### Scenario: RBAC boundary stays in tarifario

- GIVEN tarifario active with its Init booted
- WHEN the permission listener and role tables are inspected
- THEN both remain in tarifario, the listener is live on `ArticlePermissionFilterEvent::NAME`, and catalogo_core owns no RBAC consumer for the catalog

### Requirement: CAT-07 — Locked contracts and suite baseline

`Integration/CatalogoCoreHookMarkersTest` MUST stay green unedited.
`TarifCatalogoHtmxContractTest` and `TarifCatalogoOpcionalMasterExportTest` MUST
move/update with their code, MUST NOT assert the retired slug, and the
catalogo_core suite MUST stay at or above **611 tests / 2422 assertions**.

_Strength: MUST / MUST NOT._

#### Scenario: Locked hook-markers test retained

- GIVEN the migration
- WHEN `Integration/CatalogoCoreHookMarkersTest` runs
- THEN it passes with its four frozen marker names/positions intact

#### Scenario: Moved tests green at the new location and baseline held

- GIVEN the relocated `TarifCatalogoHtmxContractTest` and `TarifCatalogoOpcionalMasterExportTest` in catalogo_core
- WHEN both plugin suites run
- THEN both are green, no test asserts `tarif_catalogo_view`, and catalogo_core is at or above 611 tests / 2422 assertions

### Requirement: CAT-08 — WU-5 boundary: Excel/JSON internals stay out

WU-5 MUST move catalog ownership only. The rich Excel/JSON wizard internals
(`ExcelHierarchyService`, `ExcelImportWizardService`, `ExcelRowUpdater`,
`process_excel_wizard.php`) MUST remain in `tarifario`, and the catalog page MUST
keep exercising the existing entry points. The `process_excel_wizard.php`
`descartadas_url` repoint from `page=tarif_catalogo_view` to
`page=ventas_catalogo` is a known cross-WU coupling owned by **WU-7**; WU-5 MUST
NOT pull the wizard internals in to satisfy it.

_Strength: MUST / MUST NOT._

#### Scenario: Wizard internals and SSE URL untouched by WU-5

- GIVEN the applied WU-5 change
- WHEN the ownership diff and `process_excel_wizard.php` are inspected
- THEN no Excel/JSON wizard service or SSE entry has moved, the catalog entry points still resolve, and the `descartadas_url` repoint remains an explicit WU-7 item
