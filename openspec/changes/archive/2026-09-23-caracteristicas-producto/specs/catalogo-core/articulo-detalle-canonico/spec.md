# Delta for articulo-detalle-canonico

`ART-01` and `ART-02` change because the per-tarifa visibility values they lock
are superseded by feature values (`caracteristicas-producto` `CAR-09`, D3).
`ART-03`…`ART-08` (opcional reuse, htmx/CSP hygiene, tabs and frozen markers,
retired edit surfaces, RBAC boundary, locked contracts) are unchanged, and the
four frozen hook markers keep their names and positions.

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
article save. The current `VentasArticuloControllerTest` contracts (public
`idiomas` / `articulo_opcionales` arrays, `saveMultiidiomaDescriptions`,
`addOpcionalArticulo`, `#multiidioma` / `#opcionales` partial includes, `ref`
acceptance, `articulo::get()`) MUST stay green.
(Previously: the per-tarifa sync persisted `en_tarifa`/`en_catalogo` on the
absorbed per-tarifa table.)

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
