# Delta for articulo-tarifa-tab-management

Decisions A1 + B2 + D4 (proposal.md:32-39) change `ATT-03` and `ATT-04`. The
Tarifas surface stops being injected through the article hook pair and becomes a
section of the host's unified `#datos` pane, while `page=tarif_tab_precios`
(`tarif_tab_precios.php`) stays byte-for-byte the ONLY writer of the per-tarifa
`precio`/`activo` and visibility. The `page=tarif_tab_precios` slug, the physical
table `tarif_articulo_precios`, the CSRF/permission gates, the htmx 4 + Alpine CSP
hygiene, the RBAC boundary and the locked-test migration requirements are
unchanged. `ATT-01`, `ATT-02`, `ATT-05`, `ATT-06` and `ATT-07` are untouched.

`ATT-04` is authored on top of the sibling change `caracteristicas-producto`
post-merge text (`changes/caracteristicas-producto/specs/catalogo-core/
articulo-tarifa-tab-management/spec.md`), which moves `en_tarifa`/`en_catalogo`
to articulo-scope feature values. That sibling change MUST archive before this
one. The canonical spec files are NOT edited by this change. `ATT-03` keeps its
canonical heading so the archive replacement is unambiguous; its body is inverted
by D4.

## MODIFIED Requirements

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
