# Delta for articulo-detalle-canonico

New capability owned by `catalogo_core`: the canonical `ventas_articulo` detail
(`Controller/VentasArticulo.php` + `View/ventas_articulo.html.twig`) becomes the
single article detail surface (delivery unit WU-2 of the absorption program),
absorbing tarifario's `tarif_articulo_edit` and `tarif_articulo_precios` edit
surfaces. WU-1 already owns the article Tarifas tab and `tarif_articulo_precio`;
this spec does not redefine it. Class names, the `page=ventas_articulo` slug and
the `ref`/`id` + `codtarifa` query parameters stay stable. No data migration, no
table rename. The four frozen hook markers in `ventas_articulo.html.twig` MUST NOT
change name or position.

## ADDED Requirements

### Requirement: ART-01 — Canonical detail absorbs article edit behavior

`Controller/VentasArticulo.php` MUST absorb `tarif_articulo_edit` behavior:
reference change (via the article reference setter with duplicate/invalid
rejection), `codfamilia` assignment, article flags, per-tarifa
`tarif_tarifa_articulo` sync for the active `codtarifa`, etiquetas persistence
through `tarif_tarifa_articulo_etiqueta`, and the images entry point (list,
upload, delete, feature) for the loaded article. Saving MUST remain guarded so a
failure in the per-tarifa or etiquetas step does not silently drop the basic
article save. The current `VentasArticuloControllerTest` contracts (public
`idiomas` / `articulo_opcionales` arrays, `saveMultiidiomaDescriptions`,
`addOpcionalArticulo`, `#multiidioma` / `#opcionales` partial includes, `ref`
acceptance, `articulo::get()`) MUST stay green.

_Strength: MUST._

#### Scenario: Reference change and familia persist from the canonical detail

- GIVEN a saved article and a POST that changes `referencia` and `codfamilia`
- WHEN the canonical save runs
- THEN the article reference and family are updated through the model setters
- AND an invalid or duplicate reference is rejected with an error and no article is renamed
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

#### Scenario: Per-tarifa sync and etiquetas persist for the active tarifa

- GIVEN an article and an active `codtarifa`
- WHEN the detail saves with per-tarifa flags and etiquetas
- THEN `tarif_tarifa_articulo` holds the row for `(codtarifa, referencia)` and the etiqueta rows are replaced for that article/family
- AND a rejected etiqueta save reports an error without discarding the successful article save
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

#### Scenario: Images entry point operates on the loaded article

- GIVEN a saved article and a multipart image upload or a delete/feature request
- WHEN the canonical detail handles it
- THEN the image set for that reference is uploaded, deleted or flagged accordingly
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

### Requirement: ART-02 — Canonical detail absorbs per-tarifa price/state and article-opcional editing

`Controller/VentasArticulo.php` MUST absorb `tarif_articulo_precios` per-article
per-tarifa price/state editing (`precio`, `activo`, `en_tarifa`, `en_catalogo`)
and the article-opcional add/remove surface. Mutations MUST validate CSRF before
touching any model and MUST gate through catalogo_core's neutral permission
mechanism, reusing the WU-1 tab endpoint (`page=tarif_tab_precios`) where
applicable rather than duplicating the row/save logic.

_Strength: MUST._

#### Scenario: Per-article per-tarifa price/state saves and deletes

- GIVEN an article with a per-tarifa row
- WHEN a CSRF-valid, authorized save writes the price and flags, then a blank price is submitted
- THEN the flags persist and the blank price deletes that row for the tarifa
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

#### Scenario: CSRF or permission denial persists nothing

- GIVEN a POST that mutates per-tarifa price or article-opcionales
- WHEN the CSRF token is missing/invalid or the permission verdict is denied
- THEN no model factory or `save()` runs and the stored state is unchanged
- AND only an authorized, CSRF-valid request persists
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

### Requirement: ART-03 — Article-opcional add/remove reuses the canonical opcional flow

Adding and removing an article-opcional from the canonical detail MUST reuse the
already-catalogo_core-owned opcional models and the WU-1 tab surface instead of
re-deriving price logic; the current `articulo_opcionales` contract and
`addOpcionalArticulo` entry point MUST remain.

_Strength: SHOULD._

#### Scenario: Add and remove keep the existing opcional contract

- GIVEN an article and an available opcional
- WHEN the opcional is added then removed through the canonical detail
- THEN the article's opcional set reflects the add and the remove and the reused tab data stays consistent
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

### Requirement: ART-04 — Canonical detail view uses htmx 4 and Alpine CSP

`View/ventas_articulo.html.twig` MUST be migrated to htmx 4 + Alpine CSP:
htmx colon event names only, mutations via `hx-post` only, Alpine logic registered
via `Alpine.data()` from a nonce'd script behind the `alpine:init` guard with
`[x-cloak]`, and no `bootbox` and no `|raw`. All scripts MUST load through
`Macro/Htmx.html.twig` / `Macro/Alpine.html.twig` boot helpers so the CSP nonce is
preserved.

_Strength: MUST._

#### Scenario: htmx 4 and Alpine hygiene on the migrated view

- GIVEN the migrated `ventas_articulo.html.twig`
- WHEN its source is inspected
- THEN only colon event names and `hx-post` appear, components register via `Alpine.data(` under `alpine:init` with `csp_nonce_attr()` and `[x-cloak]`
- AND `bootbox`, `|raw` and htmx v2 event names are absent
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

### Requirement: ART-05 — Canonical detail preserves tabs and frozen hook markers

The migrated view MUST preserve the existing tabs (`#datos`, `#precios`,
`#stock`, `#multiidioma`, `#opcionales`) and the exact four frozen host hook
markers, including `ventas_articulo_tabs_after` and
`ventas_articulo_tab_pane_after`, at their current names and positions, so the
WU-1 tab injection and `CatalogoCoreHookMarkersTest` remain valid.

_Strength: MUST._

#### Scenario: Tabs and markers survive the migration

- GIVEN the WU-1 hook registrations and the migrated view
- WHEN `CatalogoCoreHookMarkersTest` and the detail view assertions run
- THEN the four frozen markers keep their names and positions and the existing tabs render
- AND the article Tarifas tab still injects at the frozen markers
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

### Requirement: ART-06 — Duplicate edit surfaces retired with links repointed

`tarif_articulo_edit` and `tarif_articulo_precios` (controllers and views) MUST be
deleted with no alias and no class file left in either plugin. Every inbound link
and `url()` reference MUST be repointed to `ventas_articulo`, preserving the
`ref`/`id` and `codtarifa` parameters, including
`tarif_articulo_precio::url()` and the tarifario view links that target the
retired slugs. Their `fs_page` rows MUST be retired idempotently in
`catalogo_core/Init.php::upgrade()`.

_Strength: MUST / MUST NOT._

#### Scenario: Retired controllers and views leave no alias

- GIVEN the applied change
- WHEN `tarif_articulo_edit` and `tarif_articulo_precios` controller/view files are searched
- THEN neither exists in tarifario or catalogo_core and no `page` alias resolves them
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

#### Scenario: Inbound links repoint to the canonical detail

- GIVEN every inbound link/`url()` that targeted the retired slugs
- WHEN the sources are searched
- THEN each points at `ventas_articulo` with its `ref`/`id` and `codtarifa` preserved
- AND `tarif_articulo_precio::url()` no longer emits a retired slug
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` (grep gate)

#### Scenario: menu rows retire idempotently

- GIVEN the retired slugs' `fs_page` rows
- WHEN `Init::upgrade()` runs twice
- THEN both rows are deleted on the first run and the second run is a harmless no-op
- Test: `plugins/catalogo_core/tests/ArticuloDetalleCanonicoOwnershipTest.php`

### Requirement: ART-07 — No half-moved class and no tarifario coupling

No model that moves for WU-2 MAY exist in both plugins, and no catalogo_core
production file MAY reference `plugins/tarifario/` for the absorbed surfaces.
`ArticlePermissionListener` and `tarif_grupo_articulo` (with the RBAC role tables)
MUST remain in tarifario, with the listener still registered on catalogo_core's
`ArticlePermissionFilterEvent`.

_Strength: MUST / MUST NOT._

#### Scenario: Zero tarifario paths for the absorbed detail surface

- GIVEN the catalogo_core production tree after the change
- WHEN `plugins/tarifario/` references for the absorbed surfaces are searched
- THEN zero hits remain (tests/vendor/openspec excluded) and no class file is duplicated
- Test: `plugins/catalogo_core/tests/ArticuloDetalleCanonicoOwnershipTest.php` (grep gate)

#### Scenario: RBAC boundary stays in tarifario

- GIVEN tarifario active with its Init booted
- WHEN the permission listener and `tarif_grupo_articulo` are inspected
- THEN both remain in tarifario and the listener is live on `ArticlePermissionFilterEvent::NAME`
- Test: `plugins/tarifario/tests/Integration/HookRegistrationTest.php`

### Requirement: ART-08 — Locked contracts migrate coherently

`VentasArticuloControllerTest` MUST stay green with its existing assertions. The
tests that locked `tarif_articulo_edit` / `tarif_articulo_precios` behavior MUST
move or update together with the code they cover, and the catalogo_core suite
MUST run green at or above its current baseline (554 tests / 1972 assertions).

_Strength: MUST._

#### Scenario: Canonical controller test stays green

- GIVEN the migrated canonical detail controller and view
- WHEN `VentasArticuloControllerTest` runs
- THEN every existing assertion passes with no test edits
- Test: `plugins/catalogo_core/tests/VentasArticuloControllerTest.php`

#### Scenario: Edit-surface tests travel with their code

- GIVEN the retired edit surfaces
- WHEN the catalogo_core and tarifario suites run
- THEN the absorbed behaviors are covered by the new catalogo_core tests and no tarifario test asserts a retired slug
- AND the catalogo_core suite is at or above 554 tests / 1972 assertions
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`
