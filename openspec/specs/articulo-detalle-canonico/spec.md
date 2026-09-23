# articulo-detalle-canonico Specification

## Purpose

Owned by `catalogo_core`: the canonical `ventas_articulo` detail
(`Controller/VentasArticulo.php` + `View/ventas_articulo.html.twig`) is the
single article detail surface (delivery unit WU-2 of the absorption program),
absorbing tarifario's `tarif_articulo_edit` and `tarif_articulo_precios` edit
surfaces. The article Tarifas tab and `tarif_articulo_precio` are owned by the
`articulo-tarifa-tab-management` capability; this spec does not redefine them.
Class names, the `page=ventas_articulo` slug and the `ref`/`id` + `codtarifa`
query parameters stay stable. No data migration, no table rename. The four frozen
hook markers in `ventas_articulo.html.twig` MUST NOT change name or position.

## Requirements

### Requirement: ART-01 — Canonical detail absorbs article edit behavior

`Controller/VentasArticulo.php` MUST absorb `tarif_articulo_edit` behavior:
reference change (via the article reference setter with duplicate/invalid
rejection), `codfamilia` assignment, article flags, per-tarifa
`tarif_tarifa_articulo` sync for the active `codtarifa` — persisting the
surviving per-tarifa state (`codfamilia`, `orden`) **and** the per-tarifa
visibility as articulo-scope feature values through the feature store — etiquetas
persistence through `tarif_tarifa_articulo_etiqueta`, and the images entry point
(list, upload, delete, feature) for the loaded article. Saving MUST remain guarded
so a failure in the per-tarifa or etiquetas step does not silently drop the basic
article save. With a tarifa selected, `editarArticulo()` MUST NOT write
`articulos.pvp`; it MUST write `articulos.pvp` from the form ONLY when no active
tarifa is selected, keeping the base-price edit path reachable. The multi-language
description surface MUST be selector-driven (`gestion-idiomas` `GDI-11`): the view
renders one description / short-description pair for the selected language, and
`saveMultiidiomaDescriptions()` (`Controller/VentasArticulo.php:824-859`) persists
that pair for the selected language and clears the language's row when both fields
are empty — it MUST NOT overwrite the posted default-language value with
`$art->descripcion` and MUST NOT skip empty input. The current
`VentasArticuloControllerTest` contracts (public `idiomas` /
`articulo_opcionales` arrays, `saveMultiidiomaDescriptions`,
`addOpcionalArticulo`, `#multiidioma` / `#opcionales` partial includes, `ref`
acceptance, `articulo::get()`) MUST stay green; the assertions that locked the
duplicated base `fsc.articulo.descripcion` textarea are re-expressed for the
selector in the same slice.
(Previously: the canonical save wrote `articulos.pvp` from the form on every save,
regardless of the selected tarifa, and the detail rendered a base
`fsc.articulo.descripcion` textarea plus a per-language loop that duplicated the
default language; `saveMultiidiomaDescriptions()` discarded the posted
default-language value in favour of `$art->descripcion` and skipped empty input.)

_Strength: MUST._

#### Scenario: Reference change and familia persist from the canonical detail

- GIVEN a saved article and a POST that changes `referencia` and `codfamilia`
- WHEN the canonical save runs
- THEN the article reference and family are updated through the model setters
- AND an invalid or duplicate reference is rejected with an error and no article is renamed
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

#### Scenario: Per-tarifa sync and etiquetas persist for the active tarifa

- GIVEN an article and an active `codtarifa`
- WHEN the detail saves with per-tarifa values and etiquetas
- THEN the surviving `tarif_tarifa_articulo` row for `(codtarifa, referencia)` is written, the visibility is stored as articulo-scope feature values, and the etiqueta rows are replaced for that article/family
- AND a rejected etiqueta save reports an error without discarding the successful article save
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

#### Scenario: Images entry point operates on the loaded article

- GIVEN a saved article and a multipart image upload or a delete/feature request
- WHEN the canonical detail handles it
- THEN the image set for that reference is uploaded, deleted or flagged accordingly
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

#### Scenario: With a tarifa selected the base price is not written

- GIVEN an active tarifa selected and a POST that carries `spvp`
- WHEN `editarArticulo()` saves the article
- THEN the article's other fields persist and `articulos.pvp` is left unchanged
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php` (RED-first, new)

#### Scenario: With no active tarifas the base price is written

- GIVEN zero active tarifas and a POST that carries `spvp`
- WHEN `editarArticulo()` saves the article
- THEN `articulos.pvp` is written from the submitted base price
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloTarifaSelectionTest.php` (RED-first, new)

#### Scenario: Selector-driven multi-language save persists the posted value

- GIVEN a POST for the default language whose `descripcion_<default>` differs from `$art->descripcion`
- WHEN the canonical save runs
- THEN the posted value is persisted for the default language and is not overwritten by the base column
- AND a POST with both fields empty for a language deletes that language's row
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

### Requirement: ART-02 — Canonical detail absorbs per-tarifa price/state and article-opcional editing

`Controller/VentasArticulo.php` MUST absorb `tarif_articulo_precios` per-article
per-tarifa price/state editing (`precio`, `activo`) and MUST persist
`en_tarifa`/`en_catalogo` for the tarifa as articulo-scope feature values through
the feature store, plus the article-opcional add/remove surface. Mutations MUST
validate CSRF before touching any model and MUST gate through catalogo_core's
neutral permission mechanism, reusing the WU-1 tab endpoint
(`page=tarif_tab_precios`) where applicable rather than duplicating the row/save
logic.
(Previously: the two visibility flags were edited and persisted on the per-tarifa
price row.)

_Strength: MUST._

#### Scenario: Per-article per-tarifa price/state saves and deletes

- GIVEN an article with a per-tarifa row
- WHEN a CSRF-valid, authorized save writes the price and flags, then a blank price is submitted
- THEN `precio`/`activo` persist and the visibility feature values are materialized for the tarifa, and the blank price deletes the price row
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

#### Scenario: CSRF or permission denial persists nothing

- GIVEN a POST that mutates per-tarifa price, visibility or article-opcionales
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

The migrated view MUST present a single unified `#datos` pane (the active tab
pane) containing the Datos, Stock, Idiomas (multi-idioma), Imágenes and
per-tarifa Precios sections, each scoped to the selected tarifa (ART-09..ART-11).
The `#precios` and `#stock` entries MUST NOT remain as separate tabs; `#opcionales`
MUST remain the only secondary tab, and the multi-idioma and opcional partial
includes MUST stay. The exact four frozen host hook markers, including
`ventas_articulo_tabs_after` and `ventas_articulo_tab_pane_after`, MUST keep their
current names, call shape and positions, rendering empty with no registrant (D4).
The locked literals `#multiidioma`, `#opcionales`, `tab_multiidioma.html.twig`,
`tab_opcionales.html.twig` and `fsc.articulo.pvp` MUST survive so ART-08's
`VentasArticuloControllerTest` stays green with no test edits. The Idiomas section
inside the unified pane MUST host the selector-driven single description /
short-description pair (`gestion-idiomas` `GDI-11`), keeping the `#multiidioma`
in-pane anchor (`View/ventas_articulo.html.twig:106`) and the
`partials/articulos/tab_multiidioma.html.twig` include (`:342`); the selector work
MUST NOT add, rename, move or remove any hook marker.
(Previously: the view preserved five separate tabs `#datos`, `#precios`,
`#stock`, `#multiidioma`, `#opcionales` and injected the Tarifas tab at the
frozen markers; the `#multiidioma` section hosted the base textarea plus the
per-language loop.)

_Strength: MUST._

#### Scenario: Unified pane carries the article-scoped sections

- GIVEN a saved article and a selected tarifa
- WHEN `ventas_articulo.html.twig` renders
- THEN one `#datos` pane contains the Datos, Stock, Idiomas, Imágenes and per-tarifa Precios sections
- AND `#opcionales` remains the only secondary tab and the four frozen markers keep their names and positions
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

#### Scenario: Locked literals survive for ART-08

- GIVEN the unified view source
- WHEN `VentasArticuloControllerTest` runs
- THEN `#multiidioma`, `#opcionales`, `tab_multiidioma.html.twig`, `tab_opcionales.html.twig` and `fsc.articulo.pvp` are present
- AND no existing assertion in that test was edited
- Test: `plugins/catalogo_core/tests/VentasArticuloControllerTest.php`

#### Scenario: Frozen markers stay at their positions

- GIVEN the migrated view
- WHEN `CatalogoCoreHookMarkersTest` inspects the source
- THEN the four frozen marker calls keep their exact names, context shape and positions
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: The selector work leaves the frozen markers untouched

- GIVEN the selector-driven Idiomas section inside the unified pane
- WHEN the view source is inspected
- THEN `#multiidioma`, the `tab_multiidioma.html.twig` include and the four frozen markers are present at their pre-change names and positions
- AND no new, renamed or moved marker was introduced
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

### Requirement: ART-09 — Tarifa selector with validated default

The detail MUST render a tarifa selector, reusing the ratified
`tarif_opcional_edit` selector pattern (`hx-get page=ventas_articulo&codtarifa=…`,
change trigger, full-body select/swap, push-url), ONLY when at least one active
tarifa exists, with the default tarifa (`tarif_tarifa::get_default()`) preselected.
A supplied `codtarifa` MUST be validated against the active set: an unknown or
inactive code MUST fall back to the default tarifa, then to the first active
tarifa, and the resolved tarifa MUST be exposed to the view as
`$tarifa_seleccionada`, mirroring
`tarif_opcional_edit::resolver_tarifa_seleccionada()`. The pane MUST NOT be scoped
to a nonexistent tarifa.

_Strength: MUST._

#### Scenario: Default tarifa is preselected on first load

- GIVEN active tarifas with a default marked and no `codtarifa` in the request
- WHEN the detail renders
- THEN the default tarifa is the selected one and the selector marks it
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloTarifaSelectionTest.php` (RED-first, new)

#### Scenario: Stale or unknown codtarifa falls back safely

- GIVEN a `codtarifa` that is not in the active set
- WHEN the detail resolves the selection
- THEN the default tarifa is selected, or the first active tarifa when no default is marked
- AND no nonexistent tarifa ever scopes the pane
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloTarifaSelectionTest.php` (RED-first, new)

### Requirement: ART-10 — Per-tarifa read path for price, state and visibility

With a tarifa selected, the pane MUST render the per-tarifa `precio` and `activo`
read from `tarif_articulo_precio` for `(referencia, codtarifa)`, and the effective
visibility `en_tarifa` / `en_catalogo` read through
`CaracteristicaResolver::resolve_bool()` at articulo scope for that tarifa.
Rendering MUST NOT persist any value. Mutations of `precio` / `activo` / visibility
MUST keep flowing through the unchanged `page=tarif_tab_precios` endpoint, which
remains the ONLY writer; the article form MUST NOT duplicate the per-tarifa
row/save logic (ART-02 unchanged). Rendering the price rows follows the same
`page=tarif_tab_precios` path so the host never needs a currency helper.

_Strength: MUST / MUST NOT._

#### Scenario: Per-tarifa price and state render for the selected tarifa

- GIVEN an article with a `tarif_articulo_precio` row for the selected tarifa
- WHEN the unified pane renders
- THEN the row's `precio` and `activo` are shown while other tarifas' values are not
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php` (RED-first, new)

#### Scenario: Visibility is resolved without persisting

- GIVEN an article with articulo-scope feature values for `en_tarifa` / `en_catalogo`
- WHEN the pane renders for the selected tarifa
- THEN the resolved visibility is shown and no feature value row is written
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php` (RED-first, new)

#### Scenario: Price editing still routes through the WU-1 endpoint

- GIVEN the unified pane source
- WHEN the controller and rows partial are inspected
- THEN the controller does not contain `guardar_precio_tab` and the rows partial posts to `page=tarif_tab_precios`
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

### Requirement: ART-11 — No-active-tarifas degradation branch

When no active tarifa exists, the selector MUST NOT render, the unified pane MUST
render the base article fields with the `pvp` input reachable and editable, and
the per-tarifa price rows surface MUST degrade without fatal error or blank
content. `articulos.pvp` MUST be writable in this branch (ART-01).

_Strength: MUST / MUST NOT._

#### Scenario: No tarifas hides the selector and keeps the base price

- GIVEN zero active tarifas
- WHEN the detail renders
- THEN no selector is emitted and the `pvp` input is present and editable
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloTarifaSelectionTest.php` (RED-first, new)

#### Scenario: Empty rows surface degrades gracefully

- GIVEN zero active tarifas
- WHEN the per-tarifa surface renders
- THEN it reports no active tarifas without fatal error and the page stays complete
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloTarifaSelectionTest.php` (RED-first, new)
