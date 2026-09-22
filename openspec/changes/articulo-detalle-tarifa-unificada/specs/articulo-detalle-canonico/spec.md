# Delta for articulo-detalle-canonico

Owner-confirmed decisions A1 + B2 + C1 + D4 (proposal.md:32-39) change `ART-05`
and `ART-01`. `ART-02` (price/state editing reuses the WU-1 endpoint) is
**unchanged**: `page=tarif_tab_precios` stays the ONLY writer of the per-tarifa
`precio`/`activo` plus visibility, and the article form POSTs the rest. `ART-03`
(article-opcional reuse), `ART-04` (htmx 4 + Alpine CSP), `ART-06` (retired edit
surfaces), `ART-07` (RBAC boundary) and `ART-08` (locked contracts) are unchanged,
and the four frozen hook markers keep their names, call shape and positions
(D4).

`ART-01` is authored on top of the sibling change `caracteristicas-producto`
delta post-merge text (`changes/caracteristicas-producto/specs/catalogo-core/
articulo-detalle-canonico/spec.md`), which rewrites the per-tarifa sync to
articulo-scope feature values. That sibling change MUST archive before this one
(see the change summary), otherwise the `ART-01` block below silently re-applies
the sibling wording. The canonical spec files are NOT edited by this change.

## MODIFIED Requirements

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
`VentasArticuloControllerTest` stays green with no test edits.
(Previously: the view preserved five separate tabs `#datos`, `#precios`,
`#stock`, `#multiidioma`, `#opcionales` and injected the Tarifas tab at the
frozen markers.)

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
tarifa is selected, keeping the base-price edit path reachable. The current
`VentasArticuloControllerTest` contracts (public `idiomas` /
`articulo_opcionales` arrays, `saveMultiidiomaDescriptions`,
`addOpcionalArticulo`, `#multiidioma` / `#opcionales` partial includes, `ref`
acceptance, `articulo::get()`) MUST stay green.
(Previously: the canonical save wrote `articulos.pvp` from the form on every save,
regardless of the selected tarifa.)

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

## ADDED Requirements

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
