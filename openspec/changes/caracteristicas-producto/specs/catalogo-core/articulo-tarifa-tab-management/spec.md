# Delta for articulo-tarifa-tab-management

`ATT-02` and `ATT-04` change because the per-tarifa `en_tarifa`/`en_catalogo`
flags they lock are superseded by feature values (`caracteristicas-producto`
`CAR-09`, D3). The slug, ownership, htmx/CSP hygiene, RBAC boundary and
locked-test migration requirements are unchanged.

## MODIFIED Requirements

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

### Requirement: ATT-04 — Canonical detail renders the tab and persists per-tarifa state

The canonical `ventas_articulo` detail MUST render the article Tarifas tab
header and pane at the two frozen hook positions with `tipo=articulo` and the
host reference, and MUST render no tab for an unsaved article. Saving a row MUST
persist `precio` and `activo` for that tarifa on the per-tarifa price row, MUST
persist `en_tarifa` and `en_catalogo` for that tarifa as articulo-scope feature
values through the feature store, and a blanked price MUST delete the price row
without deleting the feature values. The four frozen host marker names and
positions MUST NOT change.
(Previously: all four values were persisted on the per-tarifa price row.)

_Strength: MUST._

#### Scenario: Saved article injects the tab at the frozen markers

- GIVEN catalogo_core active with its article hook registrations and a saved article
- WHEN `ventas_articulo.html.twig` renders
- THEN the tab header and pane appear inside the frozen tab list and `.tab-content`, carrying `tipo=articulo` and the reference
- AND an unsaved article produces no tab, and the four frozen markers keep their names and positions
- Test: `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php`

#### Scenario: Save and delete persist per-tarifa state

- GIVEN a per-tarifa row for an article
- WHEN the row is saved with price and flags, then saved again with a blank price
- THEN the first save persists `precio`/`activo` on the price row and materializes the `en_tarifa`/`en_catalogo` feature values for that tarifa
- AND the second save deletes the price row and leaves the feature values intact
- Test: `plugins/catalogo_core/tests/TarifTabPreciosTest.php` (migrated)
