# Delta for articulo-detalle-canonico

`ART-01` and `ART-05` change because the multi-language description surface they
lock is re-expressed as a selector-driven single pair (`gestion-idiomas` `GDI-11`,
D2). `ART-02`…`ART-04` and `ART-06`…`ART-08` (per-tarifa price/state editing,
opcional reuse, htmx/CSP hygiene, retired edit surfaces, RBAC boundary, locked
contracts) are unchanged, and the four frozen hook markers keep their names and
positions.

Both blocks below are full copies of the canonical requirement and all its
scenarios, edited in place — never partial blocks.

## MODIFIED Requirements

### Requirement: ART-01 — Canonical detail absorbs article edit behavior

`Controller/VentasArticulo.php` MUST absorb `tarif_articulo_edit` behavior:
reference change (via the article reference setter with duplicate/invalid
rejection), `codfamilia` assignment, article flags, per-tarifa
`tarif_tarifa_articulo` sync for the active `codtarifa`, etiquetas persistence
through `tarif_tarifa_articulo_etiqueta`, and the images entry point (list,
upload, delete, feature) for the loaded article. Saving MUST remain guarded so a
failure in the per-tarifa or etiquetas step does not silently drop the basic
article save. The multi-language description surface MUST be selector-driven
(`gestion-idiomas` `GDI-11`): the view renders one description / short-description
pair for the selected language, and `saveMultiidiomaDescriptions()`
(`Controller/VentasArticulo.php:824-859`) persists that pair for the selected
language and clears the language's row when both fields are empty — it MUST NOT
overwrite the posted default-language value with `$art->descripcion` and MUST NOT
skip empty input. The surviving `VentasArticuloControllerTest` contracts (public
`idiomas` / `articulo_opcionales` arrays, `saveMultiidiomaDescriptions`,
`addOpcionalArticulo`, `#multiidioma` / `#opcionales` partial includes, `ref`
acceptance, `articulo::get()`) MUST stay green; the assertions that locked the
duplicated base `fsc.articulo.descripcion` textarea are re-expressed for the
selector in the same slice.
(Previously: the detail rendered a base `fsc.articulo.descripcion` textarea plus a
per-language loop that duplicated the default language, and
`saveMultiidiomaDescriptions()` discarded the posted default-language value in
favour of `$art->descripcion` and skipped empty input.)

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

#### Scenario: Selector-driven multi-language save persists the posted value

- GIVEN a POST for the default language whose `descripcion_<default>` differs from `$art->descripcion`
- WHEN the canonical save runs
- THEN the posted value is persisted for the default language and is not overwritten by the base column
- AND a POST with both fields empty for a language deletes that language's row
- Test: `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`

### Requirement: ART-05 — Canonical detail preserves tabs and frozen hook markers

The migrated view MUST preserve the existing tabs (`#datos`, `#precios`,
`#stock`, `#multiidioma`, `#opcionales`) and the exact four frozen host hook
markers, including `ventas_articulo_tabs_after` and
`ventas_articulo_tab_pane_after`, at their current names and positions, so the
WU-1 tab injection and `CatalogoCoreHookMarkersTest` remain valid. The
`#multiidioma` tab pane MUST host the selector-driven single description /
short-description pair (`gestion-idiomas` `GDI-11`) and MUST keep the
`#multiidioma` anchor (`View/ventas_articulo.html.twig:106`) and the
`partials/articulos/tab_multiidioma.html.twig` include (`:342`). The selector work
MUST NOT add, rename, move or remove any hook marker.
(Previously: the `#multiidioma` pane hosted the base textarea plus the
per-language loop.)

_Strength: MUST._

#### Scenario: Tabs and markers survive the migration

- GIVEN the WU-1 hook registrations and the migrated view
- WHEN `CatalogoCoreHookMarkersTest` and the detail view assertions run
- THEN the four frozen markers keep their names and positions and the existing tabs render
- AND the article Tarifas tab still injects at the frozen markers
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: The selector work leaves the frozen markers untouched

- GIVEN the selector-driven `#multiidioma` pane
- WHEN the view source is inspected
- THEN `#multiidioma`, the `tab_multiidioma.html.twig` include and the four frozen markers are present at their pre-change names and positions
- AND no new or moved marker was introduced
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`
