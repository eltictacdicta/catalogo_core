# Delta for articulo-detalle-canonico

`ART-01` and `ART-05` change because the multi-language description surface they
lock is re-expressed as a selector-driven single pair (`gestion-idiomas` `GDI-11`,
D2). `ART-02`…`ART-04` and `ART-06`…`ART-08` (per-tarifa price/state editing,
opcional reuse, htmx/CSP hygiene, retired edit surfaces, RBAC boundary, locked
contracts) are unchanged, and the four frozen hook markers keep their names and
positions.

Both blocks below are full copies of the **current** canonical requirement and
all its scenarios, edited in place — never partial blocks. They are re-based on
the canonical after the sibling archives `caracteristicas-producto`
(`f369faf7`, feature-store visibility) and `articulo-detalle-tarifa-unificada`
(`f3c36e61`, unified `#datos` pane), so no sibling-delivered clause or scenario
is reverted.

## MODIFIED Requirements

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
