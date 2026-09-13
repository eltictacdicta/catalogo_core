# Delta for articulo-notas-historial

New `catalogo_core` capability: the notes + article price-history domain moves
into `catalogo_core` and the notes soft seam is hardened. This is delivery unit
**WU-6** of the absorption program (depends on WU-5). WU-5 moved the catalog
manager and left `tarif_revision_nota` behind a soft `loadTarifarioModel()` seam
(AD-W5-9) and the WU-1 price save behind a soft `class_exists` history guard
(AD-4); this slice replaces both with local ownership. The RBAC seam stays soft
and in `tarifario`. No table rename, no data migration. Runner:
`ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
(baseline **641 tests / 2741 assertions**).

## ADDED Requirements

### Requirement: ANH-01 — `catalogo_core` owns the revision-notes model and resolves it locally

`FSFramework\model\tarif_revision_nota` and its
`model/table/tarif_revision_notas.xml` schema MUST live in `catalogo_core`; the
FQCN and table name (`tarif_revision_notas`) MUST stay byte-stable; no class or
XML MAY exist in both plugins. The catalog notes seam
(`Controller/VentasCatalogo.php::init_revision_notas()`) MUST resolve the notes
model locally and MUST NOT use `loadTarifarioModel()` for it, so the notes
counters, lists and CRUD keep working with `tarifario` inactive.

_Strength: MUST / MUST NOT._

#### Scenario: Notes model and schema resolve only from catalogo_core

- GIVEN catalogo_core active and tarifario inactive
- WHEN the notes model is loaded and both plugin trees are searched
- THEN it resolves from `plugins/catalogo_core/model/` with `tarif_revision_notas` unchanged and its XML only under `plugins/catalogo_core/model/table/`
- AND the tarifario counterpart is absent (no dual class/XML)
- Test: `plugins/catalogo_core/tests/ArticuloNotasHistorialOwnershipTest.php`

#### Scenario: Notes seam resolves locally and bootstraps standalone

- GIVEN catalogo_core active and tarifario inactive with a pending note row
- WHEN the notes actions (`get_notas_*`, `crear_nota`, `resolver_nota`, `cancelar_nota`, `count_notas_pendientes`) run
- THEN they use the local notes model (no `loadTarifarioModel('…tarif_revision_nota')`) with correct counters/lists
- AND the standalone `catalogo_core` Init ensures `tarif_revision_notas` idempotently
- Test: `plugins/catalogo_core/tests/ArticuloNotasHistorialOwnershipTest.php`

### Requirement: ANH-02 — `catalogo_core` owns the article price-history model

`FSFramework\model\tarif_precio_historial` and its
`model/table/tarif_precio_historial.xml` schema MUST live in `catalogo_core`; the
FQCN and table name MUST stay byte-stable; no class or XML MAY exist in both
plugins. Article history reads (date-filtered list, counters, statistics, purge)
and writes (`registrar_cambio()`) MUST resolve the model locally.

_Strength: MUST / MUST NOT._

#### Scenario: History model and schema resolve only from catalogo_core

- GIVEN catalogo_core active and tarifario inactive
- WHEN the history model is loaded and both plugin trees are searched
- THEN it resolves from `plugins/catalogo_core/model/` with `tarif_precio_historial` unchanged and its XML only under `plugins/catalogo_core/model/table/`
- AND the tarifario counterpart is absent (no dual class/XML)

#### Scenario: Article history read and write resolve locally

- GIVEN an article per-tarifa price change and existing history rows
- WHEN the change is recorded and the date-filtered list/statistics are read
- THEN both use the local `tarif_precio_historial`
- AND unchanged values are skipped
- Test: `plugins/catalogo_core/tests/ArticuloNotasHistorialOwnershipTest.php`

### Requirement: ANH-03 — The per-tarifa price save uses a hard local history reference (AD-4)

The WU-1 price model (`tarif_articulo_precio::save()`) MUST write history through
the local `tarif_precio_historial` reference. The soft
`class_exists('FSFramework\model\tarif_precio_historial')` guard added in WU-1
(AD-4) MUST be removed, and a standalone `catalogo_core` save MUST NOT fatal and
MUST NOT silently drop history.

_Strength: MUST / MUST NOT._

#### Scenario: Price change writes history without the soft guard

- GIVEN the moved `tarif_articulo_precio` model
- WHEN a price or flag change is saved
- THEN history is recorded through the local `tarif_precio_historial`
- AND no `class_exists` soft guard for the history model remains

#### Scenario: Standalone save records history

- GIVEN catalogo_core active and tarifario inactive
- WHEN a per-tarifa price change is saved
- THEN the history row is persisted (no fatal, no skipped write)

### Requirement: ANH-04 — The price-history page is catalogo_core-owned

`controller/tarif_historial_precios.php` MUST be a catalogo_core-owned page that
keeps both `tipo=articulos` and `tipo=opcionales` branches coherent, the article
branch reading the local model and the opcional branch the catalogo_core-owned
one. The tarifario duplicate MUST be deleted with no alias and its `fs_page` row
retired idempotently in `catalogo_core/Init.php::upgrade()`; every inbound link
MUST repoint to the canonical page.

_Strength: MUST / MUST NOT._

#### Scenario: Article and opcional branches both served

- GIVEN the canonical catalogo_core history page and both kinds of history rows
- WHEN `tipo=articulos` and `tipo=opcionales` are requested
- THEN each branch lists its rows and renders statistics/purge without fatal

#### Scenario: Tarifario duplicate retired and links repointed

- GIVEN the applied change
- WHEN both plugins are searched and `upgrade()` runs twice
- THEN no tarifario `tarif_historial_precios` controller/view exists (no alias), its `fs_page` row is deleted once with a no-op rerun, and inbound links target the canonical page
- Test: `plugins/catalogo_core/tests/TarifHistorialPreciosControllerTest.php`

### Requirement: ANH-05 — Notes/images/history UI keeps working with htmx 4 and Alpine hygiene

The already-moved notes/images/history UI (`View/partials/catalogo/*`,
`View/js/catalogo/*` and the canonical history page) MUST keep working against
the local models: htmx colon event names only, POST-only mutations, a nonce'd
`Alpine.data()` behind `alpine:init`, and no `|raw` and no `bootbox`.

_Strength: MUST / MUST NOT._

#### Scenario: htmx/Alpine hygiene on notes/images/history surfaces

- GIVEN the notes/images/history views and JS
- WHEN inspected
- THEN colon event names only, POST-only mutations, `Alpine.data(` under `alpine:init` with `csp_nonce_attr()`, and no `|raw`/`bootbox`/v2 event names

#### Scenario: Entry points wired to the local models

- GIVEN the catalog page and the canonical history page
- WHEN the notes, images and history entry points render and act
- THEN each reaches its catalogo_core-owned model/action (no tarifario dependency)

### Requirement: ANH-06 — No half-moved class; RBAC seam stays soft in tarifario

No moved notes/history asset MAY exist in both plugins, and no `catalogo_core`
production file MAY reference `plugins/tarifario/` for the moved notes/history
surfaces. The RBAC seam (`tarif_grupo_articulo`, `tarif_grupo_rol`,
`tarif_grupo_tarifa`) MUST stay in `tarifario`, still resolved through the soft
`loadTarifarioModel()` seam, and `ArticlePermissionListener` MUST remain live.

_Strength: MUST / MUST NOT._

#### Scenario: Zero tarifario paths and no duplicate

- GIVEN catalogo_core production (tests/vendor/openspec excluded)
- WHEN references to the moved notes/history surfaces are searched
- THEN zero `plugins/tarifario/`/`@tarifario/` hits remain and no moved asset is duplicated
- Test: `plugins/catalogo_core/tests/ArticuloNotasHistorialOwnershipTest.php`

#### Scenario: RBAC seam stays soft in tarifario

- GIVEN catalogo_core with `loadTarifarioModel()` retained for the RBAC models
- WHEN the RBAC seam and the tarifario listener are inspected
- THEN `tarif_grupo_*` resolve only through the soft seam, the role tables stay in tarifario, and the listener is live on `ArticlePermissionFilterEvent::NAME`

### Requirement: ANH-07 — Locked contracts and suite baseline

`Integration/CatalogoCoreHookMarkersTest` MUST stay green unedited. The notes and
history tests MUST move/update with their code to the catalogo_core-owned
models/controller, and both plugin suites MUST run green with catalogo_core at or
above **641 tests / 2741 assertions**.

_Strength: MUST._

#### Scenario: Frozen hook markers retained

- GIVEN the WU-6 migration
- WHEN `Integration/CatalogoCoreHookMarkersTest` runs
- THEN it passes with its four frozen marker names/positions intact and no edits
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: Moved tests green at the baseline

- GIVEN the relocated `TarifHistorialPreciosControllerTest` and the notes/history ownership test
- WHEN both plugin suites run
- THEN they are green, no test references the retired tarifario history controller path, and catalogo_core is at or above 641 tests / 2741 assertions
