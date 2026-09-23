# articulo-tarifa-tab-management Specification

## Purpose

Owned by `catalogo_core`: the per-tarifa article price/state surface absorbed
from tarifario (delivery unit WU-1 of the article absorption program). The
opcional twin is owned by the `opcionales-tarifa-management` capability; this
spec does not redefine it. The `page=tarif_tab_precios` slug, class basenames and
the physical table `tarif_articulo_precios` stay stable. No data migration, no
table rename, no schema or API change. The canonical `ventas_articulo` host page
and its four frozen hook markers are untouched.

## Requirements

### Requirement: ATT-01 — catalogo_core owns the article per-tarifa price model

`FSFramework\model\tarif_articulo_precio` MUST live in
`plugins/catalogo_core/model/` with its unchanged FQCN and its unchanged
`table_name = 'tarif_articulo_precios'`; its XML schema MUST live in
`plugins/catalogo_core/model/table/tarif_articulo_precios.xml`. The physical
table name MUST stay byte-stable because consumers already hardcode it
(`plugins/catalogo_core/model/tarif_tarifa.php`,
`plugins/tarifario/controller/tarif_configurador_opcionales.php`). The
class file MUST NOT exist in both plugins, and catalogo_core MUST resolve the
model locally — no `plugins/tarifario/` path for it.

_Strength: MUST._

#### Scenario: Model resolves locally with tarifario inactive

- GIVEN catalogo_core active and tarifario inactive
- WHEN `FSFramework\model\tarif_articulo_precio` is instantiated
- THEN it resolves from `plugins/catalogo_core/model/` and reports `table_name = 'tarif_articulo_precios'`
- AND the XML schema exists under `plugins/catalogo_core/model/table/`
- Test: `plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php`

#### Scenario: No dual class and no tarifario path in catalogo_core

- GIVEN the applied change
- WHEN `plugins/tarifario/model/tarif_articulo_precio.php` and any `plugins/tarifario/` reference for the moved model are searched
- THEN no class file exists in both plugins and catalogo_core has zero such references
- Test: `plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php` (grep gate)

### Requirement: ATT-02 — catalogo_core owns the article Tarifas tab endpoint

`plugins/catalogo_core/controller/tarif_tab_precios.php` MUST serve the endpoint
on the `page=tarif_tab_precios` slug, extending catalogo_core's
`fbase_controller` (never `tarif_controller`). It MUST answer GET
`action=rows&tipo=articulo&ref=<referencia>` with the server-rendered per-tarifa
rows fragment and POST `guardar_precio_tab` with the JSON envelope
`{ok, message, html}`. The POST MUST validate CSRF before touching the model and
MUST gate the save through catalogo_core's neutral permission mechanism before
`save()`; a denial or CSRF failure MUST NOT persist anything. The tab's
visibility controls MUST write articulo-scope feature values for the row's tarifa
through the feature store, not the legacy per-tarifa visibility columns.
(Previously: the tab persisted `en_tarifa`/`en_catalogo` on the price row.)

_Strength: MUST._

#### Scenario: GET rows returns the per-tarifa fragment

- GIVEN a saved article and active tarifas with per-row `coddivisa`
- WHEN `action=rows&tipo=articulo&ref=<referencia>` is requested
- THEN the response echoes the rows fragment with each row rendered in its tariff currency and `template = FALSE`
- Test: `plugins/catalogo_core/tests/TarifTabPreciosTest.php` (migrated)

#### Scenario: CSRF and permission gate precede persistence

- GIVEN a POST `guardar_precio_tab` request
- WHEN a missing/invalid CSRF token or a denied permission is evaluated
- THEN the model factory / `save()` is never reached and the response is `{ok: false, message}`
- AND only an authorized, CSRF-valid request persists and answers `{ok: true, message, html}`
- Test: `plugins/catalogo_core/tests/TarifTabPreciosTest.php` (migrated)

#### Scenario: Denied save leaves stored state unchanged

- GIVEN an existing per-tarifa price and a denied permission verdict
- WHEN the save action completes
- THEN the stored `precio`/`activo` and the effective visibility feature values are unchanged
- Test: `plugins/catalogo_core/tests/TarifTabPreciosTest.php` (migrated)

#### Scenario: Visibility controls write feature values

- GIVEN a CSRF-valid, authorized save that flips `en_catalogo` for a tarifa
- WHEN the save completes
- THEN an articulo-scope feature value is materialized for that tarifa
- AND no legacy per-tarifa visibility column is written
- Test: `plugins/catalogo_core/tests/TarifTabPreciosTest.php` (migrated)

### Requirement: ATT-03 — Hook ownership transfers idempotently to catalogo_core

`plugins/catalogo_core/Init.php` MUST NOT register `ventas_articulo_tabs_after` or
`ventas_articulo_tab_pane_after` any more: the `ARTICULO_HOOK_TEMPLATES` mapping
and the `registerHooks()` entries for the article pair MUST be removed, and both
`@catalogo_core/Hooks/ventas_articulo_*.html.twig` templates MUST be deleted. The
article Tarifas surface MUST be rendered by the host `ventas_articulo.html.twig`
as a section of the unified `#datos` pane (ATT-04). `tarifario` MUST still
register zero article hooks.
(Previously: catalogo_core registered both article hooks behind a static
idempotency guard and injected the Tarifas tab header and pane templates at the
frozen markers.)

_Strength: MUST / MUST NOT._

#### Scenario: Zero article hook registration across rebuilds

- GIVEN a fresh catalogo_core Init and two consecutive simulated Twig builds
- WHEN the registry is inspected for the article pair
- THEN neither hook is registered and a rebuild registers none
- Test: `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` (flipped)

#### Scenario: Retired hook templates leave no registrant

- GIVEN the catalogo_core tree after the change
- WHEN the article hook templates and their registration entries are searched
- THEN both `@catalogo_core/Hooks/ventas_articulo_*.html.twig` files are gone and no article mapping remains
- Test: `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` (grep gate)

#### Scenario: tarifario registers no article hook

- GIVEN tarifario booted through its Init and a simulated Twig build
- WHEN `ViewHookRegistry` is inspected for the article and opcional pairs
- THEN all four names are unregistered by tarifario
- Test: `plugins/tarifario/tests/Integration/HookRegistrationTest.php`

### Requirement: ATT-04 — Canonical detail renders the tab and persists per-tarifa state

The canonical `ventas_articulo` detail MUST render the article Tarifas surface as
a section of the unified `#datos` pane (not as a hook-injected tab at the frozen
markers), carrying `tipo=articulo` and the host reference, and MUST render no
price surface for an unsaved article. The surface MUST keep loading its rows from
the unchanged `page=tarif_tab_precios` endpoint
(`action=rows&tipo=articulo&ref=<referencia>`) and MUST keep
`page=tarif_tab_precios` (`guardar_precio_tab`) as the only writer of the
per-tarifa `precio`/`activo` state and of the `en_tarifa`/`en_catalogo`
articulo-scope feature values; the article form MUST NOT duplicate that save.
Saving a row MUST persist `precio` and `activo` for that tarifa on the per-tarifa
price row, MUST materialize `en_tarifa` and `en_catalogo` for that tarifa as
articulo-scope feature values through the feature store, and a blanked price MUST
delete the price row without deleting the feature values. The four frozen host
marker names and positions MUST NOT change.
(Previously: the tab header and pane were injected at the two frozen hook
positions; the pane content itself already edited `precio`/`activo` on the price
row and the visibility as feature values.)

_Strength: MUST._

#### Scenario: Tarifas surface renders inside the unified pane

- GIVEN catalogo_core active and a saved article with a selected tarifa
- WHEN `ventas_articulo.html.twig` renders
- THEN the Tarifas surface appears inside the `#datos` pane carrying `tipo=articulo` and the reference
- AND no Tarifas header or pane is injected at the two frozen markers, and an unsaved article produces no price surface
- Test: `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` (flipped)

#### Scenario: Rows stay served by the unchanged endpoint

- GIVEN the unified pane source and the endpoint
- WHEN the rows request and the save target are inspected
- THEN rows load from `page=tarif_tab_precios&action=rows&tipo=articulo&ref=…` and every price mutation posts to `page=tarif_tab_precios`
- AND the article form carries no duplicated per-tarifa save logic
- Test: `plugins/catalogo_core/tests/TarifTabPreciosTest.php` (migrated)

#### Scenario: Save and delete persist per-tarifa state

- GIVEN a per-tarifa row for an article
- WHEN the row is saved with price and flags, then saved again with a blank price
- THEN the first save persists `precio`/`activo` on the price row and materializes the `en_tarifa`/`en_catalogo` feature values for that tarifa
- AND the second save deletes the price row and leaves the feature values intact
- Test: `plugins/catalogo_core/tests/TarifTabPreciosTest.php` (migrated)

#### Scenario: Frozen markers keep their names and positions

- GIVEN the migrated view
- WHEN `CatalogoCoreHookMarkersTest` inspects the source
- THEN the four frozen marker calls keep their exact names, context shape and positions
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

### Requirement: ATT-05 — Article tab uses htmx 4 and Alpine CSP

The article tab MUST use htmx 4 colon event names only
(`htmx:after:swap`, `htmx:after:request`; the v2 names are banned), MUST mutate
through `hx-post` only, and MUST NOT use `bootbox` or `|raw`. Alpine logic MUST
be registered via `Alpine.data()` from a nonce'd script behind the `alpine:init`
guard with `[x-cloak]`. Every rendered price MUST use
`fsc.simbolo_divisa(<coddivisa>)` with the row's tariff currency; bare
`fsc.simbolo_divisa()` calls MUST NOT exist.

_Strength: MUST._

#### Scenario: htmx 4 hygiene on the moved tab

- GIVEN the moved tab pane, rows partial and save script
- WHEN their sources are inspected
- THEN only colon event names and `hx-post` appear, and `bootbox` / `|raw` / htmx v2 names are absent
- Test: `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php`

#### Scenario: Alpine CSP registration and currency

- GIVEN the moved tab client logic and rows
- WHEN the script and rows are inspected
- THEN components register via `Alpine.data(` under `alpine:init` with a `csp_nonce_attr()` script and `[x-cloak]` on the pane
- AND every price passes a `coddivisa` and no bare `fsc.simbolo_divisa()` call exists
- Test: `plugins/catalogo_core/tests/TarifTabPreciosTest.php` (migrated)

### Requirement: ATT-06 — Locked contracts migrate coherently

`TarifTabPreciosTest` MUST move to `plugins/catalogo_core/tests/` and pass
against the moved endpoint, and tarifario's `HookRegistrationTest` MUST be
flipped to assert tarifario registers zero article hooks while catalogo_core
owns the pair. No class file may exist in both plugins.

_Strength: MUST._

#### Scenario: Moved endpoint test passes in catalogo_core

- GIVEN `TarifTabPreciosTest` relocated under `plugins/catalogo_core/tests/`
- WHEN the catalogo_core suite runs
- THEN the endpoint contract (rows, CSRF-before-model, permission gate, per-row currency, re-render) passes
- Test: `plugins/catalogo_core/tests/TarifTabPreciosTest.php`

#### Scenario: Hook ownership assertions flipped

- GIVEN the migrated hook tests in both plugins
- WHEN both suites run
- THEN tarifario asserts zero article hooks and catalogo_core asserts ownership of both article hooks
- Test: `plugins/tarifario/tests/Integration/HookRegistrationTest.php` + `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php`

### Requirement: ATT-07 — RBAC boundary preserved in tarifario

No catalogo_core production file MAY reference `plugins/tarifario/` for the moved
article model or tab, and the catalogo_core endpoint gate MUST stay neutral
(default-allow with zero listeners). `tarif_grupo_articulo` and
`ArticlePermissionListener` MUST remain in tarifario, with the listener still
registered on catalogo_core's `ArticlePermissionFilterEvent`.

_Strength: MUST / MUST NOT._

#### Scenario: Zero tarifario coupling for the moved surface

- GIVEN the catalogo_core production tree after the move
- WHEN `plugins/tarifario/` references for the moved article model/tab are searched
- THEN zero hits remain (vendored/openspec/test paths excluded)
- AND the endpoint resolves allow with no permission listener registered
- Test: `plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php` (grep gate)

#### Scenario: RBAC ownership stays in tarifario

- GIVEN tarifario active and its Init booted
- WHEN the permission listener registration and the `tarif_grupo_articulo` model are inspected
- THEN both remain in tarifario and the listener is live on `ArticlePermissionFilterEvent::NAME`
- Test: `plugins/tarifario/tests/Integration/HookRegistrationTest.php`
