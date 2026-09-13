# Apply Progress — `absorber-articulos-tarifa-en-catalogo-core` (WU-1)

- **Mode**: Strict TDD (RED before GREEN).
- **Delivery unit**: WU-1 only (program = WU-1..WU-8; see proposal/exploration). WU-2..WU-8 are sequenced follow-ups.
- **Delivery**: working tree only — NO commit/push (human-owned; Phase 7 is the documented landing order).
- **Status**: WU-1 complete and green; Phase 6 gates closed. The apply actor was interrupted by a session pause after the atomic move + tests landed; the remaining gates were closed in a follow-up pass and are recorded below.

## Files moved / added / changed

**`plugins/catalogo_core` (additions/edits)**
- `model/tarif_articulo_precio.php` (moved from tarifario; FQCN `FSFramework\model\tarif_articulo_precio` unchanged)
- `model/table/tarif_articulo_precios.xml` (moved; table name `tarif_articulo_precios` byte-stable)
- `controller/tarif_tab_precios.php` (moved from tarifario; reclassed `tarif_controller` → `fbase_controller` + `TarifarioOpcionalStateTrait`; neutral `ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE` gate)
- `View/Hooks/ventas_articulo_tabs_after.html.twig`, `View/Hooks/ventas_articulo_tab_pane_after.html.twig`, `View/Hooks/partials/articulo_precios_rows.html.twig`, `View/Hooks/partials/tab_save_script.html.twig` (article half) — htmx 4 + Alpine CSP
- `Init.php` — `ARTICULO_HOOK_TEMPLATES` registered behind the existing guards
- `tests/ArticuloTarifaPrecioOwnershipTest.php` (new), `tests/Integration/CatalogoArticuloHookOwnershipTest.php` (new), `tests/TarifTabPreciosTest.php` (moved from tarifario)

**`plugins/tarifario` (deletions/repoints)**
- deleted: `model/tarif_articulo_precio.php`, `model/table/tarif_articulo_precios.xml`, `controller/tarif_tab_precios.php`, `View/Hooks/ventas_articulo_tabs_after.html.twig`, `View/Hooks/ventas_articulo_tab_pane_after.html.twig`, `View/Hooks/partials/articulo_precios_rows.html.twig`, `View/Hooks/partials/tab_save_script.html.twig`
- `Init.php` — article hook registration removed (tarifario registers ZERO hooks)
- require repoints in the enumerated tarifario controllers/tests
- `tests/Integration/HookRegistrationTest.php` updated (zero article+opcional hooks)

**Not edited (frozen)**: `View/ventas_articulo.html.twig`, `View/ventas_opcional.html.twig` — the tab renders through the frozen markers.

## RED → GREEN evidence

| Step | Command | Observed |
|---|---|---|
| RED | focused new tests before the move | missing catalogo_core paths/classes (`ArticuloTarifaPrecioOwnershipTest`, `Integration/CatalogoArticuloHookOwnershipTest`) |
| GREEN (focused) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` filtered | ownership + hook-ownership + `TarifTabPreciosTest` green |
| GREEN (catalogo_core) | full suite | **554 tests / 1972 assertions OK** (Warnings 25, Skipped 1) — floor was 528/1826 |
| GREEN (tarifario) | full suite | **180 tests / 637 assertions OK** (Skipped 3) — zero failures |
| Gate (root) | `ddev exec php vendor/bin/phpunit --testsuite Plugins` | 1456 tests, 10 failures — all pre-existing/unrelated (7× OidcProvider, 2× `TarifTarifaOpcionalPrecedenceTest` without process isolation, 1× `LegacySupportTest` version drift); zero new |
| Lint | `ddev exec php -l` on moved model + endpoint | no syntax errors |

## Phase 6 gates

- 6.1 catalogo_core suite green (554/1972) ✅
- 6.2 tarifario suite green (180/637, zero failures) ✅
- 6.3 root regression: zero new failures ✅
- 6.4 grep audit: no `plugins/tarifario/` reference for the moved model/endpoint in catalogo_core production ✅
- 6.5 frozen markers green; host views unmodified ✅
- 6.6 atomicity: no class in both plugins (1/0); no core `openspec/` entry ✅

## Deviations / notes

1. **Atomic move executed as copy + `git rm`** (plugins are independent git repos; cross-repo rename detection cannot fire) — no half-moved state; verified at pause and at completion.
2. **Permission gate**: the tab endpoint reuses catalogo_core's neutral `ArticlePermissionFilterEvent` (`ACTION_EDIT_ARTICLE`), default-allow with an admin short-circuit; the tarifario `ArticlePermissionListener` remains the guest RBAC enforcer (boundary preserved).
3. **History guard**: `tarif_articulo_precio::save()` calls `tarif_precio_historial::registrar_cambio()` behind a `class_exists` guard because the history model only moves in WU-6.
4. **Interrupted actor**: the apply sub-agent was cancelled mid-run (user pause); the remaining gates were run afterwards and are recorded here. No `apply-progress.md` existed before this file.
5. **Phase 7 (commits)** intentionally NOT executed; landing order documented for the human.

## Next

- `sdd-verify` for WU-1 (dev-DB smoke: saved article injects the Tarifas tab; save persists `activo`/`en_tarifa`/`en_catalogo`; blank price deletes; standalone catalogo_core creates `tarif_articulo_precios`).
- Then the sequenced program WU-2..WU-8.

---

# Apply Progress — WU-2 (phases 8–13)

- **Mode**: Strict TDD (RED before GREEN), same atomic two-repo mechanics as WU-1.
- **Scope**: exactly the 3 models + 3 XMLs of AD-W2-1 (`tarif_tarifa_articulo`, `tarif_tarifa_articulo_etiqueta`, `tarif_articulo_imagen`). `tarif_articulo` / `tarif_articulos_ext` stayed in tarifario (WU-4); `tarif_descripcion` untouched.
- **Delivery**: working tree only — NO commit/push. Phase 13 is the documented human landing order (catalogo_core FIRST, tarifario immediately after, AD-W2-10).

## Files moved / added / changed

**`plugins/catalogo_core` (additions/edits)**
- `model/tarif_tarifa_articulo.php`, `model/tarif_tarifa_articulo_etiqueta.php`, `model/tarif_articulo_imagen.php` (byte-identical moves from tarifario; FQCN `FSFramework\model\*` and table names unchanged)
- `model/table/tarif_tarifa_articulo.xml`, `model/table/tarif_tarifa_articulo_etiqueta.xml`, `model/table/tarif_articulo_imagenes.xml` (byte-identical moves)
- `model/tarif_tarifa_articulo.php::url()` repointed to `index.php?page=ventas_articulo&ref=…&codtarifa=…` (single authorized model delta)
- `model/tarif_articulo_precio.php::url()` repointed to `ventas_articulo` (null → `ventas_articulos`)
- `Controller/VentasArticulo.php` — absorbed the edit surface: protected seam factories (`articulo_model`, `tarifa_model`, `tarifa_articulo_model`, `etiqueta_model`, `etiqueta_familia_model`, `imagen_model`, `familia_model`, `tarifa_familia_model`), neutral `puedeEditarArticulo()`, `resolveCodtarifa()`, `loadTarifarioState()`, `syncTarifaArticulo()`, `ensureTarifaFamilyAvailable()`, `saveEtiquetasArticulo()`, `getEtiquetasDisponiblesArticulo()`, `getEtiquetasSeleccionadasArticulo()`, `loadImagenes`/`uploadImagen`/`deleteImagen`/`destacarImagen`, htmx fragment responses (`isHtmxRequest()`/`respondHtmx()`/`htmxHandled`), POST-only `eliminarArticulo`; contractual order CSRF → gate → rename (`snueva_referencia`) → save → multiidioma → sync → etiquetas
- `View/ventas_articulo.html.twig` — htmx 4 + Alpine CSP rewrite (host owns `htmx.boot()`/`alpine.boot()`; `articuloDetalle`/`articuloPrecios`/`articuloTabs`/`articuloImagenes`/`articuloOpcionales` registered behind a nonce'd `alpine:init`); five tabs + four frozen markers preserved
- `View/partials/articulos/tab_opcionales.html.twig` — controls migrated to `hx-post` + `hx-target="#opcionales"`/`hx-swap="outerHTML"` (no jQuery)
- `View/Hooks/ventas_articulo_tab_pane_after.html.twig` — boot dropped (host owns it); `[x-cloak]`/`x-data` shell kept
- `Init.php` — `ensureArticuloDetalleTables()` (AD-W2-8, FK-safe) wired into `init()`/`upgrade()`; idempotent `retireTarifArticuloEditPage()` + `retireTarifArticuloPreciosPage()` wired into `upgrade()`
- `tests/ArticuloDetalleCanonicoOwnershipTest.php` (new), `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` (new)
- `tests/Integration/CatalogoArticuloHookOwnershipTest.php` (additive: pane defers boot to host; host fsc gains tarifas/imagenes/etiquetas), `tests/CatalogoOpcionalesUnifiedControllerTest.php` (retired view dropped from the repoint loop)

**`plugins/tarifario` (deletions/repoints)**
- `git rm`: `model/tarif_tarifa_articulo.php`, `model/table/tarif_tarifa_articulo.xml`, `model/tarif_tarifa_articulo_etiqueta.php`, `model/table/tarif_tarifa_articulo_etiqueta.xml`, `model/tarif_articulo_imagen.php`, `model/table/tarif_articulo_imagenes.xml`
- `git rm -f` (retired, no alias): `controller/tarif_articulo_edit.php`, `View/tarif_articulo_edit.html.twig`, `controller/tarif_articulo_precios.php`, `View/tarif_articulo_precios.html.twig`
- `model/tarif_articulo.php` — `url()` (and `url_tarifario()` via delegation) → `ventas_articulo` (null → `ventas_articulos`)
- `controller/tarif_tarifas.php`, `controller/tarif_catalogo_view.php` — moved-model requires repointed to catalogo_core
- `View/tarif_actualizar_precios.html.twig`, `View/tarif_historial_precios.html.twig`, `View/tarif_articulos.html.twig` — inbound links repointed, `codtarifa` preserved

## RED → GREEN evidence

| Phase | Command | Observed |
|---|---|---|
| 8 RED | `… ArticuloDetalleCanonicoOwnershipTest.php` | 6 tests, 5 failures: missing catalogo_core model/XML paths, `/plugins/tarifario/controller/tarif_articulo_edit.php` still exists, retirement methods missing, bootstrap method missing |
| 9 RED | `… Controller/VentasArticuloArticleEditAbsorptionTest.php` | 16 tests, 7 errors + 6 failures (`editarArticulo` private, `puedeEditarArticulo` undefined, view jQuery/bootbox, retired links live) |
| 10 RED | pane/view hygiene | jQuery/bootbox view + pane still booting htmx/Alpine |
| 11 RED | inbound-link grep gate | `tarif_articulo_precio::url()` still emitted `tarif_articulo_edit` |
| 8–11 GREEN | `… ArticuloDetalleCanonicoOwnershipTest.php Controller/VentasArticuloArticleEditAbsorptionTest.php` | **OK (22 tests, 171 assertions)** |
| 10 GREEN | `… Integration/CatalogoCoreHookMarkersTest.php Integration/CatalogoArticuloHookOwnershipTest.php` | **OK (21 tests, 126 assertions)** |
| 12.1 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **576 tests / 2147 assertions OK** (Warnings 25, Skipped 1) — floor 554/1972 |
| 12.2 | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **180 tests / 637 assertions OK** (Skipped 3) — zero failures |
| 12.3 | `ddev exec php vendor/bin/phpunit --testsuite Plugins` | 1478 tests, **10 failures — all pre-existing/unrelated** (7× OidcProvider migration011, 2× `TarifTarifaOpcionalPrecedenceTest`, 1× `LegacySupportTest` version drift); zero new |
| Regression gate | `… VentasArticuloControllerTest VentasArticulosControllerTest Integration/CatalogoCoreHookMarkersTest ArticuloTarifaPrecioOwnershipTest Integration/CatalogoArticuloHookOwnershipTest TarifTabPreciosTest TarifConfiguradorOpcionalesTest CatalogoOpcionalesUnifiedControllerTest VentasOpcionalesControllerTest` | **OK (112 tests, 468 assertions)** |
| Lint | `ddev exec php -l` on 3 moved models, `Init.php`, `Controller/VentasArticulo.php`, repointed tarifario controllers/models | no syntax errors |

## Phase 12 gates

- 12.1 catalogo_core ≥ 554/1972 ✅ (576/2147)
- 12.2 tarifario green, no retired slug asserted ✅
- 12.3 root Plugins: zero new failures ✅
- 12.4 grep audit: zero `plugins/tarifario/` + `@tarifario/` in catalogo_core production ✅; no moved class in both plugins ✅
- 12.5 `CatalogoCoreHookMarkersTest` + `VentasArticuloControllerTest` green; four marker names/positions intact ✅
- 12.6 atomicity: catalogo_core additions/edits, tarifario deletions/repoints, no core `openspec/` entry ✅

## TDD Cycle Evidence

| Task | RED | GREEN | REFACTOR |
|---|---|---|---|
| 8.1–8.7 | `ArticuloDetalleCanonicoOwnershipTest` RED (5 failures) | move + `Init` bootstrap/retire → ownership GREEN | — |
| 9.1–9.9 | `VentasArticuloArticleEditAbsorptionTest` RED (13 failing) | controller absorption → behavior tests GREEN | `resolveCodtarifa()` extracted |
| 10.1–10.6 | view/pane hygiene RED | htmx/Alpine view + pane boot flip → GREEN | — |
| 11.1–11.8 | inbound-link grep RED | deletions + repoints → GREEN | — |

## Deviations / notes

1. **Retirement idempotency test is a source contract, not a behavior test.** Task 8.1 asked for `Init::upgrade()` run twice with a live `fs_page`. A global `fs_page` double leaks from the PHPUnit parent process and fatally collides with the real `model/fs_page.php` (`Cannot declare class fs_page`) — the same failure the `unificar-opcionales` change documented. The final test mirrors the project-standard source contract (`InitUpgradeTest::upgradeRetiresTarifOpcionalPreciosPageIdempotently`) and pins the `if ($existing !== false) { $existing->delete(); }` idempotency guard. Its RED was re-proven against the pre-implementation `Init.php`.
2. **`CatalogoCoreHookMarkersTest` loader extended (assertions untouched).** Task 10.6 requires that test "unedited", but `ventas_articulo.html.twig` now imports `Macro/Htmx.html.twig`/`Macro/Alpine.html.twig` (AD-W2-3/ART-04), and the test's `buildHostEnvironment()` used a bare `ArrayLoader` without the macro sources or `csp_nonce_attr()`/`csrf_token()`. Without chaining the theme macros the real view cannot compile. The change is test-infra-only (macro sources + the three helper functions); every frozen marker assertion is byte-identical and green. This is the only edit outside the task's file list.
3. **`git rm -f` on the four retired surfaces.** `controller/tarif_articulo_precios.php` + `View/tarif_articulo_precios.html.twig` carried uncommitted WU-1 require-repoint edits; the forced removal discards them, which is correct because WU-2 deletes the files outright (no alias, AD-W2-5).
4. **Image delete/feature are GET-scoped, not body-CSRF'd.** `delete_imagen`/`destacar_imagen` keep the legacy GET shape, gated by `allow_delete` + the neutral permission event and scoped to the loaded `referencia` (threat matrix row "File upload"); `upload_imagen` (POST) carries `validateFormToken()`.
5. **Delete confirm uses `hx-confirm`** (htmx native) instead of an Alpine `window.confirm`: the acceptance tests ban bootbox and require `hx-post`; a native `hx-confirm` is CSP-safe and avoids an Alpine/browser round-trip. AD-W2-3's "Alpine confirm" is approximated; behavior (confirmation before POST) is preserved.
6. **`url_tarifario()`** stayed as a one-line delegate to `url()` (as in tarifario) instead of duplicating the repointed literal.

## Next

- `sdd-verify` for WU-2 (dev-DB smoke: canonical detail saves rename/familia/flags/etiquetas, per-tarifa sync writes `tarif_tarifa_articulo`, images list/upload/delete/feature, retired slugs resolve nowhere, `fs_page` rows retired on upgrade).
- Then the sequenced program WU-3..WU-8.

---

# Apply Progress — WU-3 (phases 14–19)

- **Mode**: Strict TDD (RED before GREEN), same atomic two-repo mechanics as WU-1/WU-2.
- **Scope**: exactly AD-W3-1..AD-W3-11. WU-3 moves nothing new; rows come from the canonical `\articulo` model, per-tarifa price/state from the WU-1 `tarif_articulo_precio`. `tarif_articulo` / `tarif_articulos_ext` stayed in tarifario (WU-4). `View/ventas_articulo.html.twig` was **not** edited by WU-3 (its working-tree `M` is the pre-existing WU-2 rewrite) and the four frozen markers stay green.
- **Delivery**: working tree only — NO commit/push. Phase 19 is the documented human landing order (catalogo_core FIRST, tarifario immediately after, AD-W3-10).

## Files added / changed / deleted

**`plugins/catalogo_core` (additions/edits)**
- `extras/VentasArticulosListTrait.php` (new): filter aliases (`query`/`b_codfamilia` canonical-wins), `b_codtarifa` resolution chain, `b_solo_activos` default TRUE, one-query batch-map cache + public accessors (`get_precio_articulo_tarifa`, `articulo_activo_tarifa`, `articulo_en_tarifa_flag`, `articulo_en_catalogo`, `mostrar_precio_tarifa`, `simbolo_divisa_tarifa`), `persist_quick_create_tarifa_prices`, `getListQueryParams`.
- `Services/ArticuloTarifaPrecioBatchReader.php` (new): one-query-per-page map + ALC-02 defaults.
- `Services/ArticuloListActionRegistry.php` (new): `ArticuloListActionHandlerInterface` + `final class ArticuloListActionRegistry` in the same file, with `register()`/`reset()`/`dispatch()`.
- `model/tarif_articulo_precio.php` (additive only): `all_for_referencias(array $refs, string $codtarifa): array` — one `WHERE codtarifa = ? AND referencia IN (…)` query. `save()`/`url()`/`install()` untouched.
- `Controller/VentasArticulos.php`: `use \VentasArticulosListTrait`; alias normalization + batch columns; `puedeCrearArticulo()` gate with the resolved `codtarifa` (4th event arg); per-tarifa fixed + percentage price writes after a successful save (no transaction); `processExcelAction()` falls through to `ArticuloListActionRegistry::dispatch()`; POST-only `eliminarArticulo()`; private `getListQueryParams` removed (trait provides it). Locked CPV strings kept (`search`, `codfamilia`, `codfabricante`, `->search(`).
- `View/ventas_articulos.html.twig` (rewritten): htmx 4 + Alpine CSP; filters `hx-get` + `hx-target/hx-select="#articulos-list"` + `hx-swap="outerHTML"` + `hx-push-url="true"`; per-tarifa columns; quick-create/delete `hx-post`; `articulosList`/`articuloConfirm` registered nonce'd behind `alpine:init`; locked strings (header/footer, `csrf_field()`, modal partials, Excel/JSON carrier + JS) preserved.
- `View/partials/articulos/modal_nuevo_articulo.html.twig`: per-tarifa `precio_tarifa_<codtarifa>` + `porcentaje_tarifa_<codtarifa>` inputs; `hx-post`; keeps `{{ csrf_field() }}`, `nreferencia`/`ndescripcion`/`npvp`, `action`/`method="post"`.
- `Init.php`: idempotent `retireTarifArticulosPage()` wired in `upgrade()` in its own try/catch.
- `View/ventas_opcionales.html.twig`, `View/Macro/TarifarioComponents.html.twig`: inbound links repointed to `ventas_articulos` (`codtarifa` → `b_codtarifa`).
- Tests: `tests/Services/ArticuloTarifaPrecioBatchReaderTest.php` (new), `tests/Controller/VentasArticulosListAbsorptionTest.php` (new), `tests/Integration/CatalogoArticuloListHtmxContractTest.php` (new), `tests/ArticuloListaCanonicaOwnershipTest.php` (new), `tests/InitUpgradeTest.php` (assertion), `tests/CatalogoOpcionalesUnifiedControllerTest.php` + `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` (dropped the deleted view from their loops).

**`plugins/tarifario` (additions/deletions/repoints)**
- `git rm` (retired, no alias): `controller/tarif_articulos.php` (2504 L), `View/tarif_articulos.html.twig` (576 L).
- `Services/ArticuloListActionHandler.php` (new): verbatim relocation of the retired list's non-list methods (`export_template`, `export_articulos`, `import_articulos`, `import_tarifa_json`, `import_json_upload`, `import_json_process`, `limpiar_*`, `process_*_batch` + helpers) implementing `ArticuloListActionHandlerInterface`; internals unchanged.
- `Init.php`: `registerArticuloListActionHandler()` (`class_exists` + static guard) wired in `init()` after `registerPermissionListener()`.
- Repointed views: `View/tarif_tarifas.html.twig`, `View/tarif_actualizar_precios.html.twig`, `View/tarif_historial_precios.html.twig`, `View/tarif_articulo.html.twig` → `ventas_articulos`.
- Repointed locked tests: `tests/Controller/TarifArticulosFamiliaImportTest.php` (source/require/reflection → `Services/ArticuloListActionHandler.php`), `tests/Integration/TarifFamiliaWriteRetirementTest.php` (writer #1/#2 + AD-8 `limpiar_todo` ext-DELETE exception → the handler).

## RED → GREEN evidence

| Phase | Command | Observed |
|---|---|---|
| 14/15 RED | `… phpunit -c plugins/catalogo_core/phpunit.xml tests/Services/ArticuloTarifaPrecioBatchReaderTest tests/Controller/VentasArticulosListAbsorptionTest` | 14 tests, **14 errors** — all “Failed opening required …/Services/ArticuloTarifaPrecioBatchReader.php” (missing catalogo_core path), never a syntax/setup error |
| 16 RED | `… tests/Integration/CatalogoArticuloListHtmxContractTest` | 5 tests, **5 failures** on the jQuery view (missing hx-get/target/push, Alpine.data, per-tarifa modal inputs, csrf_field) |
| 17 RED | `… tests/ArticuloListaCanonicaOwnershipTest tests/InitUpgradeTest` | 9 tests, **5 failures** — retired list/view still present, slug links live, `Init` lacks `retireTarifArticulosPage` |
| 14/15 GREEN | focused pair | **OK (14 tests, 93 assertions)** |
| 16 GREEN | htmx contract + `VentasArticulosControllerTest` | **OK (19 tests, 85 assertions)** |
| 17 GREEN | ownership + `InitUpgradeTest` | **OK (9 tests, 43 assertions)** |
| 17 GREEN | tarifario full suite | **180 tests / 637 assertions OK** (Skipped 3) — repointed handler tests green |
| Locked regression | `VentasArticulosControllerTest` (+ composition + HookRegistration + markers via full suites) | green **unedited** |
| 18.1 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **601 tests / 2338 assertions OK** (Warnings 26, Skipped 1) — floor 576/2147 |
| 18.2 | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **180 tests / 637 assertions OK** (Skipped 3) — floor 180/637 |
| 18.3 | `ddev exec php vendor/bin/phpunit --testsuite Plugins` | 1502 tests, **10 failures — exactly the pre-existing/unrelated set** (7× OidcProvider, 2× `TarifTarifaOpcionalPrecedenceTest`, 1× `LegacySupportTest` version drift); **zero new** |
| Lint | `ddev exec php -l` on the trait, both services, `model/tarif_articulo_precio.php`, both `Init.php`, `Controller/VentasArticulos.php`, the handler, repointed tests | no syntax errors |

## Phase 18 gates

- 18.1 catalogo_core ≥ 576/2147 ✅ (601/2338)
- 18.2 tarifario ≥ 180/637 ✅ (180/637, zero failures)
- 18.3 root `Plugins`: zero new failures ✅
- 18.4 grep audit: zero `plugins/tarifario/` + `@tarifario/` in catalogo_core production ✅; zero `page=tarif_articulos` ✅; `ArticuloTarifaPrecioOwnershipTest` + tarifario `HookRegistrationTest` green unedited ✅
- 18.5 no half-moved state: retired surfaces only in git index-deletion; `tarif_articulo`/`tarif_articulos_ext` tarifario-only ✅
- 18.6 frozen markers + locked tests: `CatalogoCoreHookMarkersTest` + `VentasArticulosControllerTest` green unchanged ✅ (see deviation 2)
- 18.7 Excel/JSON entry points resolve: `test_action_entry_points_delegate_through_the_neutral_seam` + repointed `TarifArticulosFamiliaImportTest` green ✅

## TDD Cycle Evidence

| Task | RED | GREEN | REFACTOR |
|---|---|---|---|
| 14.1–14.8 | batch-reader + absorption tests RED (missing paths/classes) | batch reader + model method + trait + controller aliases → GREEN | alias helpers extracted (`codfamilia_filter`, `list_search_args`) |
| 15.1–15.7 | ALC-03/05/08 methods RED | registry + quick-create writes + POST delete → GREEN | `exportState()` + gate seam extracted |
| 16.1–16.5 | htmx contract RED on the jQuery view | view + modal rewrite → GREEN | `articulosList` component consolidated |
| 17.1–17.10 | ownership + `InitUpgradeTest` RED | deletion + handler relocation + repoints + `Init` retirement → GREEN | verbatim extraction via line-region copy |

## Deviations / notes

1. **Handler relocation is verbatim by source extraction.** `Services/ArticuloListActionHandler.php` was assembled from the retired controller's method region (lines 420–2503, byte-identical bodies) plus a controller-like context header (`idiomas`/`tarifas`/`familia`/`empresa`/filters + `url()`/`new_message()`/`new_error_msg()`/`ini_filters()`). Internals are unchanged; the handler is registered, not subclassed, in production.
2. **18.6 `git status` for the host detail view is non-empty.** `View/ventas_articulo.html.twig` carries the pre-existing, uncommitted **WU-2** rewrite (recorded in the WU-2 apply-progress), not a WU-3 edit. WU-3 never touched it; the frozen-marker contract is proven by `CatalogoCoreHookMarkersTest` green unchanged.
3. **Two catalogo_core tests adjusted (necessary, behavior-preserving).** `CatalogoOpcionalesUnifiedControllerTest` and `VentasArticuloArticleEditAbsorptionTest` iterated `plugins/tarifario/View/tarif_articulos.html.twig`; once WU-3 deletes that view their `source()`/`assertFileExists` would fatal. The deleted view was dropped from both loops; every other assertion is intact. (Not in the task's explicit repoint list, but required to keep the full catalogo_core suite green.)
4. **Delete confirmation uses htmx-native `hx-confirm`.** The delete mutation is a CSRF-guarded `hx-post` form per row (`name="delete"`); `articuloConfirm` is registered for the design's component surface. Behavior (confirm before POST) is preserved and CSP-safe.
5. **Quick-create writes no transaction.** Per AD-W3-5 the locked `VentasArticulosQuickCreateGateCompositionTest` engine stub has no `begin_transaction`; prices are attempted only after a successful `articulo::save()` and are skipped entirely when `$this->tarifas === []`.
6. **Phase 19 (commits) intentionally NOT executed.** Landing order documented for the human (AD-W3-10): catalogo_core commit FIRST, tarifario immediately after, no deploy between, then clear the Twig cache.

## Next

- `sdd-verify` for WU-3 (dev-DB smoke: filtered list + `hx-push-url`, per-tarifa columns read one query, quick-create fixed/percentage persistence, POST delete, `tarif_articulos` resolves nowhere, `fs_page` row retired on upgrade).
- Then the sequenced program WU-4..WU-8.

## Post-verify correction — WU-3 filtered export (WARNING 1)

- **Fix**: `Controller/VentasArticulos.php::loadArticulosForExportFiltered()` and `exportState()` now resolve the filter set through the trait's `list_search_args()` instead of reading the canonical raw keys, so alias-only filters (`query`, `b_codfamilia`) and the `b_solo_activos`→`bloqueados` semantics are **applied at export execution**, not merely carried in the URL.
- **TDD**: new RED test `tests/Controller/VentasArticulosListAbsorptionTest::test_filtered_export_applies_the_resolved_alias_filters` (source contract) failed before the fix and passes after.
- **Evidence**: focused file `OK (12 tests, 82 assertions)`; full suite **602 tests / 2343 assertions OK** (was 601/2338). Twig cache cleared.
- **Not committed** (human-owned; still within the WU-3 slice boundary).

---

# WU-4 — catalogo_core owns `tarif_articulo` + `tarif_articulos_ext`; `tarif_descripcion` deleted

- **Scope**: exactly AD-W4-1..AD-W4-11. Verbatim byte-identical move of `tarif_articulo` + `tarif_articulos_ext` + `model/table/tarif_articulos.xml` into catalogo_core, the `tarif_descripcion` wrapper deletion (+ its `tarifario_init.php` boot block), six require repoints, one new idempotent bootstrap, and the two pinned boundary tests. `tarif_descripciones.xml` untouched (AD-W4-10). No data/table/`fs_page`/Composer change.
- **Delivery**: working tree only — NO commit/push. Phase 25 is the documented human landing order (catalogo_core FIRST, tarifario immediately after, AD-W4-7).

## Files added / changed / deleted

**`plugins/catalogo_core` (additions/edits)**
- `model/tarif_articulo.php` (new, byte-identical move; sha256 `abf9feb6…`): keeps `namespace FSFramework\model;`, `class tarif_articulo extends \FSFramework\model\articulo` (no own table); internal `require_once` already pointed at catalogo_core core `articulo`/`articulo_descripcion`.
- `model/tarif_articulos_ext.php` (new, byte-identical move; sha256 `b3f49af5…`): keeps `table_name = 'tarif_articulos'`, CRUD/`update_field`/`get_extra_fields`.
- `model/table/tarif_articulos.xml` (new, byte-identical move; sha256 `2a56e7b6…`): PK `referencia`, FK `codfamilia → familias`.
- `Init.php`: new `public static function ensureArticuloExtTables(): void` (AD-W4-4/AD-W4-9) — `touchNamespacedModel('familia')` first, `is_file` + `require_once`, `class_exists($fqcn, false)` + `is_subclass_of(..., \fs_model::class)`; wired in `init()` after `ensureArticuloDetalleTables()` in its own `try/catch` + `error_log`, and in `upgrade()` after `self::ensureArticuloDetalleTables();`. The frozen `ensureArticuloTarifaTables()` / `ensureArticuloDetalleTables()` bodies were NOT edited.
- `tests/ArticuloModelosExtOwnershipTest.php` (new): 9 DB-free source/class/grep contracts.
- `tests/ArticuloListaCanonicaOwnershipTest.php`: inverted `test_tarif_articulo_and_ext_stay_in_tarifario_for_wu4` → `test_tarif_articulo_and_ext_are_owned_by_catalogo_core_after_wu4`; docblock updated (AD-W4-5).
- `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`: `:935` source path → `plugins/catalogo_core/model/tarif_articulo.php` (url() assertions unchanged).

**`plugins/tarifario` (deletions/repoints)**
- `git rm` (no dual class): `model/tarif_articulo.php`, `model/tarif_articulos_ext.php`, `model/table/tarif_articulos.xml`, `model/tarif_descripcion.php`.
- `extras/tarifario_init.php`: removed the `tarif_descripcion` boot block; kept the `tarif_articulos_ext` `class_exists` stable-table no-op.
- Repointed requires (FQCN `use` untouched): `controller/tarif_actualizar_precios.php:22`, `controller/tarif_catalogo_view.php:34`, `Services/ArticuloListActionHandler.php:31`, `tests/Model/TarifArticuloFactoryForImportTest.php:46,48`, `tests/Services/ExcelImportWizardServiceTest.php:62,64` → `plugins/catalogo_core/model/…`.

## RED → GREEN evidence

| Phase | Command | Observed |
|---|---|---|
| 20/21/22 RED | `… phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php` | **9 tests, 7 failures** — missing catalogo_core model/XML, tarifario twin present, wrapper present, six live requires, boot block live, bootstrap absent (never a syntax/setup error) |
| 20 GREEN | move + `git rm` + boot-block removal, then focused test | **9 tests, 53 assertions, 2 failures** — only the Phase 21 requires + Phase 22 bootstrap remain (Phase 20 methods green) |
| 21 GREEN | six repoints, focused tests | ownership method green (**9 tests, 62 assertions, 1 failure**); tarifario `TarifArticuloFactoryForImportTest` + `ExcelImportWizardServiceTest` → **OK (48 tests, 193 assertions)** |
| 22 GREEN | `ensureArticuloExtTables()` + wiring | **OK (9 tests, 79 assertions)**; frozen `ArticuloTarifaPrecioOwnershipTest` + `ArticuloDetalleCanonicoOwnershipTest` + `InitUpgradeTest` → **OK (16 tests, 122 assertions)** |
| 23 RED | move in place, old pinned tests | **2 failures** — `test_tarif_articulo_and_ext_stay_in_tarifario_for_wu4` (tarifario file gone) + `VentasArticuloArticleEditAbsorptionTest` missing `plugins/tarifario/model/tarif_articulo.php` |
| 23 GREEN | both pinned tests updated | **OK (21 tests, 128 assertions)** |
| 24.1 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **611 tests / 2422 assertions OK** (Warnings 26, Skipped 1) — floor 602/2343 |
| 24.2 | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **180 tests / 637 assertions OK** (Skipped 3) — floor 180/637 |
| 24.3 | `ddev exec php vendor/bin/phpunit --testsuite Plugins` | 1512 tests, **10 failures = exactly the known pre-existing set** (7× OidcProvider, 2× `TarifTarifaOpcionalPrecedenceTest`, 1× `LegacySupportTest` version drift); **zero new** |
| Lint | `ddev exec php -l` on the 2 moved models, `Init.php`, `tarifario_init.php`, the 3 repointed production files, the 2 repointed tests | no syntax errors |

## Phase 24 gates

- 24.1 catalogo_core ≥ 602/2343 ✅ (611/2422)
- 24.2 tarifario ≥ 180/637 ✅ (180/637, zero failures)
- 24.3 root `Plugins`: zero new failures vs the pre-existing 10 ✅
- 24.4 grep audit ✅ — `test_moved_paths_are_exact_and_do_not_swallow_prefix_siblings` + `test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_models` green; anchors on exact paths + `class\s+tarif_articulo\b` / `parent::__construct('tarif_articulos')`, never bare `tarif_articulo`; excludes `tarif_articulo_precio` / `tarif_articulo_imagen` / `tarif_articulo_edit`
- 24.5 no half-moved state ✅ — each of the 3 moved paths exists in exactly one plugin; `tarif_articulos.xml` is the only `tarif_articulos` schema in either plugin
- 24.6 locked contracts ✅ — `VentasArticulosControllerTest` + `VentasArticuloControllerTest` + `Integration/CatalogoCoreHookMarkersTest` (**43 tests green**) and tarifario `Integration/HookRegistrationTest` + `Integration/VentasArticulosQuickCreateGateCompositionTest` (**6 tests green**) unedited; RBAC listener stays live on `ArticlePermissionFilterEvent::NAME` + `tarif_grupo_articulo` + `ArticlePermissionListener` stay in tarifario
- 24.7 behavior preserved ✅ — `TarifArticuloFactoryForImportTest` + `ExcelImportWizardServiceTest` green with repointed requires and unchanged assertions (`factory_for_import()` still attaches `factory_precio` + `factory_tarif_ext`)
- AD-W4-10 ✅ — `plugins/tarifario/model/table/tarif_descripciones.xml` kept (WU-8 audit)
- Core `openspec/changes/` has **no** entry for this change name ✅

## Work Unit Evidence (WU-4)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php` → **OK (9 tests, 79 assertions)**; pinned pair `ArticuloListaCanonicaOwnershipTest` + `VentasArticuloArticleEditAbsorptionTest` → **OK (21 tests, 128 assertions)** |
| Runtime harness command/scenario and exact result | Real class-load + autoloader path proven by the ownership test (`require_once` + `class_exists(FQCN, false)` + `is_subclass_of`) and by `factory_for_import()` against the moved catalogo_core classes: tarifario `TarifArticuloFactoryForImportTest` + `ExcelImportWizardServiceTest` → **OK (48 tests, 193 assertions)**; standalone DB-backed `ensureArticuloExtTables()` boot smoke is owned by `sdd-verify` (design Open Question) |
| Rollback boundary | Revert the 3 moved catalogo_core files + the `Init` method/wiring + the new test, then restore the 4 tarifario files, the boot block, the six requires and the two pinned-test edits — byte-exact; no data/table/`fs_page` change |

## TDD Cycle Evidence

| Task | RED | GREEN | REFACTOR |
|---|---|---|---|
| 20.1–20.6 | ownership test RED (missing catalogo_core models/XML, tarifario twin + wrapper present) | byte-identical move + `git rm` + boot-block removal → 7/9 → 5 remaining | exact-path/class-token guard extracted; prefix-sibling exclusion list |
| 21.1–21.5 | require-grep + boot-block methods RED | six require repoints → ownership green, tarifario focused pair green | FQCN imports left unchanged (autoloader contract) |
| 22.1–22.5 | bootstrap source-contract RED | `ensureArticuloExtTables()` + `init()`/`upgrade()` wiring → 9/9 | method isolated from the frozen WU-1/WU-2 bodies |
| 23.1–23.4 | both pinned tests RED after the move | method rename/inversion + `:935` path → green | docblock updated to catalogo_core ownership |
| 24.1–24.7 | n/a (verification) | dual suites + root `Plugins` + audits green | — |

## Deviations / notes

1. **`git rm -f` for `tarif_articulo.php`.** The file carried the uncommitted WU-2 `url()` repoint, so plain `git rm` refused; `-f` was used after the byte-identical sha256 copy into catalogo_core (copy-then-remove, never a lost byte).
2. **Phase 24.4 introduces `test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_models`** (the grep gate named by the task) even though the 20.1 method list does not; it is a guard, green both before and after the move (the WU-1..WU-3 tree was already coupling-free).
3. **Two guard tests are not RED drivers.** `test_moved_paths_are_exact_and_do_not_swallow_prefix_siblings` and the coupling grep gate assert invariants that hold pre-move; they are the AD-W4-6 machine check, not the RED signal.
4. **`test_tarifario_boot_no_longer_references_the_deleted_wrapper` matches the single-backslash `new \FSFramework\model\tarif_articulos_ext();`** (raw code), not the doubled-backslash `class_exists` string literal; both forms verified present in the boot file.
5. **Phase 25 (commits) intentionally NOT executed.** Landing order documented for the human (AD-W4-7): catalogo_core commit FIRST, tarifario immediately after, no deploy between, then clear the Twig cache. Plugin `fsframework.ini` bumps deferred to `fsframework-plugin-release`.
6. **Twig cache cleared** (`rm -rf tmp/twig_cache/*` → 0 entries).

## Next

- `sdd-verify` for WU-4 (dev-DB smoke: standalone catalogo_core boot creates `tarif_articulos` via `Init::ensureArticuloExtTables()` idempotently; `factory_for_import()` persists against the moved classes; no half-moved class at runtime).
- Then the sequenced program WU-5..WU-8.

---

# WU-5.A–D — catalogo_core owns the catalog manager; `tarif_catalogo_view` retired

- **Scope**: Phases 26–29 only (WU-5.A models/XML, WU-5.B canonical page, WU-5.C views/partials/JS + hygiene, WU-5.D soft seam + bootstrap + retirement). Phases 30–32 (test relocation/dual-suite/delivery) are owned by a second apply launch. **No `git commit`/`push`/branches** — working tree only.
- **Runner**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (baseline **611 / 2422**).

## Files added / moved / changed / deleted

**`plugins/catalogo_core` (additions/edits)**
- `model/tarif_catalogo.php`, `model/tarif_catalogo_articulo.php`, `model/tarif_catalogo_def.php`, `model/tarif_catalogo_def_articulo.php`, `model/tarif_catalogo_def_familia.php` (byte-identical move; sha256 verified ×5).
- `model/table/tarif_catalogos.xml`, `tarif_catalogo_articulo.xml`, `tarif_catalogo_defs.xml`, `tarif_catalogo_def_articulo.xml`, `tarif_catalogo_def_familia.xml` (byte-identical move; sha256 verified ×5).
- `tests/CatalogoManagerOwnershipTest.php` (new, 14 methods; CAT-01..CAT-08 source/class contracts).
- `extras/VentasCatalogoStateTrait.php` (new; ported per-tarifa state + `roleEnTarifa()` soft seam + `initCatalogoState()` + `ensureCatalogoTablesOnce()` + `isCsrfValid()` shim + `loadTarifarioModel()`).
- `Controller/VentasCatalogo.php` (new, reclassed 5,180-line move of the 5,189-line tarifario controller).
- `controller/ventas_catalogo.php` (new legacy slug wrapper extending the PSR-4 class).
- `View/ventas_catalogo.html.twig`, `View/ventas_catalogo_articulos.html.twig`, `View/ventas_catalogo_articulos_agrupados.html.twig`, `View/ventas_catalogo_search.html.twig` (moved + renamed + hygiene).
- `View/partials/catalogo/*` (12 moved) + `View/js/catalogo/*` (9 moved).
- `View/Macro/TarifarioComponents.html.twig` (`:271` link → `page=ventas_catalogo&codtarifa=…`).
- `tests/Controller/TarifCatalogoHtmxContractTest.php` (new, 8 methods; CAT-03/CAT-04).
- `Init.php`: `ensureCatalogoTables()` (public static, idempotent, FK-safe, wired in `init()` after `ensureArticuloExtTables()` in its own try/catch and in `upgrade()`), `retireTarifCatalogoViewPage()` (gated `fs_page::get('tarif_catalogo_view')` → `delete()`, wired in `upgrade()`); frozen WU-1/WU-2/WU-4 bodies untouched.
- `tests/InitUpgradeTest.php`: `upgradeRetiresTarifCatalogoViewPageIdempotently()` + `test_ensure_catalogo_tables_is_wired_and_fk_safe()`.
- `tests/ArticuloModelosExtOwnershipTest.php`: require-map entry repointed to `plugins/catalogo_core/Controller/VentasCatalogo.php` (see deviation 4).

**`plugins/tarifario` (deletions)**
- `git rm` (10): the 5 `model/tarif_catalogo*.php` + 5 `model/table/tarif_catalogo*.xml`.
- `git rm` (1): `controller/tarif_catalogo_view.php`.
- `git rm` (25): the 4 `View/tarif_catalogo*.html.twig` + 12 `View/partials/catalogo/*` + 9 `View/js/catalogo/*`.
- No edit to `extras/tarifario_init.php` (its `class_exists` blocks stay idempotent no-ops), `Services/Excel*`, `process_excel_wizard.php` (`:477` `descartadas_url` untouched), or the RBAC role tables/listener (CAT-08/CAT-06 boundary).

## RED → GREEN evidence

| Phase | Command | Observed |
|---|---|---|
| 26 RED | `… phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/CatalogoManagerOwnershipTest.php` | **14 tests, 10 failures** — missing catalogo_core models/XML, controller, wrapper, bootstrap, retirement and views; never a syntax/setup error |
| 26 GREEN | sha256 copy ×10 + `git rm` ×10, then focused | **14 tests, 81 assertions, 8 failures** (both model methods + table-name method green); `php -l` clean on the 5 moved models; all 10 sha256 pairs identical |
| 27 RED | focused on the B-owned methods | `test_canonical_page_*`, `test_page_registry_*`, `test_catalog_entry_points_*` failed on missing `Controller/VentasCatalogo.php` / `controller/ventas_catalogo.php` |
| 27 GREEN | reclass + wrapper + trait + `git rm` the tarifario controller | **14 tests, 147 assertions, 4 failures** (page identity/wrapper/entry-points/soft-seam green); `php -l` clean on controller, wrapper, trait |
| 28 RED | `… phpunit … plugins/catalogo_core/tests/Controller/TarifCatalogoHtmxContractTest.php` | **8 tests, 11 assertions, 7 failures** — missing `View/ventas_catalogo*.html.twig`, `View/partials/catalogo/*`, `View/js/catalogo/*`, JS `src` still tarifario, `\|raw` block present |
| 28 GREEN | 25-asset move + hygiene + `TarifCatalogoComponents:271` repoint | **OK (8 tests, 81 assertions)** |
| 29 RED | ownership D-owned methods + `InitUpgradeTest` additions | bootstrap + retirement methods failed (methods absent); `InitUpgradeTest` additions RED |
| 29 GREEN | `Init::ensureCatalogoTables()` + `retireTarifCatalogoViewPage()` + wiring | `CatalogoManagerOwnershipTest` → **OK (14 tests, 188 assertions)**; `InitUpgradeTest` → **OK (6 tests, 42 assertions)** |
| Final gate | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **OK (635 tests, 2717 assertions)** — Warnings 26, Skipped 1; floor 611/2422 (+24 tests, +295 assertions) |
| Lint | `ddev exec php -l` on the 5 moved models, controller, wrapper, trait, `Init.php`, both new tests | no syntax errors |
| Twig parse | 16/16 moved templates tokenized+parsed (framework function stubs) | 0 errors |
| Runtime load | fresh process: trait → controller → wrapper | `VentasCatalogo` extends `FSFramework\Controller\PageController`; `getPageData()` = `ventas_catalogo`/`Catálogo`/`catalogo`/`true`/`130`; wrapper parent = the PSR-4 class |

## Work Unit Evidence (WU-5.A–D)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/CatalogoManagerOwnershipTest.php` → **OK (14 tests, 188 assertions)**; `… plugins/catalogo_core/tests/Controller/TarifCatalogoHtmxContractTest.php` → **OK (8 tests, 81 assertions)**; `… plugins/catalogo_core/tests/InitUpgradeTest.php` → **OK (6 tests, 42 assertions)** |
| Runtime harness command/scenario and exact result | Fresh-process class load proves the trait→controller→wrapper chain resolves and `getPageData()` is correct (above). DB-backed standalone `ensureCatalogoTables()` boot smoke + the endpoint parity runtime are owned by `sdd-verify` (design Open Questions). |
| Rollback boundary | Revert the 10 moved model/XML files, `Controller/VentasCatalogo.php` + wrapper + trait, the 4 renamed views + 21 partials/JS, the macro/`Init.php` edits and the 2 new tests, then restore the 11 tarifario production files + the 25 assets + `ArticuloModelosExtOwnershipTest:356`; the tarifario controller recreates the `fs_page` row on next request. No table/data/Composer change. |

## TDD Cycle Evidence

| Task | RED | GREEN | REFACTOR |
|---|---|---|---|
| 26.1–26.5 | 14 tests / 10 failures (missing catalogo_core paths) | byte-identical move + `git rm` → model/table methods green | exact-path/table regex anchors; `array_keys` scan |
| 27.1–27.8 | B-owned methods RED on missing controller/wrapper | reclass + wrapper + trait + `git rm` → 4 remaining failures | namespaced globals (`\Exception`, `\stdClass`); `$this->db->var2str` |
| 28.1–28.9 | htmx contract 8/7 RED | 25-asset move + htmx/Alpine hygiene → 8/8 | single nonce'd `Alpine.data('catalogoShell')` host + `postJson()` htmx bridge |
| 29.1–29.8 | bootstrap + retirement RED | `ensureCatalogoTables()` + `retireTarifCatalogoViewPage()` → ownership 14/14 + InitUpgrade 6/6 | FK-safe order isolated from the frozen WU-1/WU-2/WU-4 bodies |

## Audits performed

- **Architecture (AD-W5-14)**: `CatalogoManagerOwnershipTest::test_catalogo_core_has_no_tarifario_path_for_the_catalog_surface` green — zero `plugins/tarifario/` / `@tarifario/` in the catalogo_core production tree (tests/vendor/openspec excluded); `page=tarif_catalogo_view` survives only in `plugins/tarifario/process_excel_wizard.php:477` (accepted WU-7 item).
- **No half-moved state**: each moved model/XML/view/partial/JS exists in exactly one plugin; `class tarif_catalogo_view` absent from both.
- **Locked contracts**: `ArticuloListaCanonicaOwnershipTest`, `VentasArticulosControllerTest`, `VentasArticuloControllerTest`, `Integration/CatalogoCoreHookMarkersTest`, `InitFamiliasTablesTest` green unedited (full suite).
- **Security**: no `|raw` in the 16 moved catalog templates; no inline `on*=` handlers; `data-catalogo-config` emitted with `e('html_attr')`; `Alpine.data()` behind `alpine:init` from one nonce'd classic script; colon-only htmx events; `catalogo-main.js` mutations POST-only through `htmx.ajax('POST')`/`postJson()` (no `$.ajax`, no `hx-delete/put/patch`); `isCsrfValid()` shim reads `_csrf_token`/`_token`/`X-CSRF-TOKEN` via `CsrfManager`, honoring `FS_CSRF_SOFT`.
- **RBAC boundary**: `ArticlePermissionListener` stays live on the neutral event at tarifario boot; `tarif_grupo_*` role tables stay in tarifario; catalogo_core resolves roles only through the fail-closed `class_exists` seam.
- **WU-7 boundary**: the three rich wizard services stay in tarifario; catalogo_core's pre-existing basic `process_excel_wizard.php` was not converted into the rich SSE entry.
- **Twig cache**: cleared (`rm -rf tmp/twig_cache/*` → 0 entries). Core `openspec/` has no entry for this change.

## Deviations / notes

1. **Trait created in Phase 27, not 29.4.** The reclassed controller cannot be declared without `use \VentasCatalogoStateTrait;`, so leaving the trait for phase 29 would have left a half-moved (unloadable) class. The trait lands with the controller move; phase 29 wires its `Init::ensureCatalogoTables()` delegation and the bootstrap/retirement. Its content follows 29.4 (soft `roleEnTarifa()`, ported `loadTarifasAccesibles()`/`initUserPermissions()`, `initCatalogoState()`, `ensureCatalogoTablesOnce()`).
2. **`privateCore` diff is slightly wider than 27.4.** `parent::private_core()` → `$this->initCatalogoState()`; the now-subsumed `$this->tarifa = new tarif_tarifa();`, `$this->is_admin = …` and `$this->load_tarifas_accesibles()` were removed and the controller-local permission methods moved to the trait as `initUserPermissions()` (per 29.4). All role/permission semantics are byte-preserved.
3. **Namespacing deltas.** `catch (Exception $e)` → `catch (\Exception $e)` (8 sites) and `new stdClass()` → `new \stdClass()` (5 sites) because the moved body is now namespaced; `$this->var2str(...)` (1 site) → `$this->db->var2str(...)` because `PageController` has no `fs_controller` helper.
4. **Cross-phase necessity: 30.3 done early.** `ArticuloModelosExtOwnershipTest:356` still read the deleted `plugins/tarifario/controller/tarif_catalogo_view.php`, which failed the catalogo_core suite. It was repointed to `plugins/catalogo_core/Controller/VentasCatalogo.php` (assertions unchanged) to satisfy the Phase 29.8 frozen-contract gate. The second launch's Phase 30 will find it already repointed.
5. **Relocated htmx test `setUpBeforeClass` loads `PageController` + the trait only.** Requiring the full controller pulls `tarif_familia` → `model/core/familia.php`, which collides with the `InitUpgradeTest` stub `familia` under `processIsolation` when PHPUnit discovers both files in the parent process. All 8 methods are source-contract, so no controller class load is needed.
6. **Residual WU-8 debt**: `json-import.js`, `images-import.js`, `images-zip.js` keep their jQuery POST fan-outs (outside the four `catalogo-main.js` sites named by AD-W5-6); the deep jQuery DOM layer (`$(...)`, Sortable, `fadeOut`) is byte-stable.
7. **Ownership-test refinements during RED→GREEN**: `roleEnTarifa()` is pinned via `$class = 'FSFramework\\model\\tarif_grupo_usuario'` + `class_exists($class)` (AD-W5-2's exact shape); the wizard test asserts the 3 rich services have no catalogo_core twin (catalogo_core legitimately owns its own basic `process_excel_wizard.php`); the RBAC listener registration is asserted in tarifario `Init.php` while the listener file is asserted via its `use`/`__invoke` contract.

## Next

- **Second apply launch**: Phases 30–32 (relocate `TarifCatalogoHtmxContractTest` + `TarifCatalogoOpcionalMasterExportTest` ownership, repoint the three tarifario audit tests, dual-suite + root `Plugins` gate, delivery note).
- `sdd-verify` for WU-5 after the second launch: dev-DB standalone `ensureCatalogoTables()` idempotency + the endpoint-parity runtime smoke checklist.

---

# WU-5.E–F — test-ownership move + audit repoints + dual-suite gate (phases 30–32)

- **Scope**: Phases 30–31 only (plus the Phase 32 landing note). Phase 30 relocates the second controller contract test into catalogo_core, repoints the three tarifario writer/audit tests and retires the two tarifario controller test files; Phase 31 closes the dual-suite + root `Plugins` + grep/no-half-moved/frozen-marker gates. **No `git commit`/`push`/branches** — working tree only.
- **Runner**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (floor 611 / 2422 after WU-5.A–D had reached 635 / 2717).

## Files added / changed / deleted

**`plugins/catalogo_core` (additions/edits)**
- `tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` (new, relocation of the tarifario copy): namespace `Tests\CatalogoCore\Controller`, class name kept; `CONTROLLER = 'plugins/catalogo_core/Controller/VentasCatalogo.php'`; `setUpBeforeClass()` requires `PageController` + the reclassed controller; the 4 DB-free behavior tests subclass the PSR-4 `VentasCatalogo` and reflect `build_opcionales_export`; the 2 source contracts retargeted to `VentasCatalogo`; no retired-slug assertion.
- `tests/Support/CatalogoCoreSeedStubs.php` (new): the `FSFramework\model\{CatalogoCoreSeedStub,impuesto,familia,fabricante}` seed stubs, extracted from `InitUpgradeTest.php` file scope (see deviation 1).
- `tests/InitUpgradeTest.php` (edit): the stub declarations moved to `tests/Support/CatalogoCoreSeedStubs.php`, now `require_once`d at runtime in `setUp()` instead of being declared at file scope. Test bodies/assertions unchanged.

**`plugins/tarifario` (deletions/repoints)**
- `git rm tests/Controller/TarifCatalogoHtmxContractTest.php` (ownership already relocated to catalogo_core in phase 28).
- `rm tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` (untracked copy; no index entry — removed from the working tree).
- `tests/Integration/FamiliaOverrideRemovalTest.php`: `:94` source read → `plugins/catalogo_core/Controller/VentasCatalogo.php`; docblock updated; the `new familia()` count/read-only assertions unchanged.
- `tests/Integration/TarifFamiliaWriteRetirementTest.php`: writer #3 path → `plugins/catalogo_core/Controller/VentasCatalogo.php` (label updated); assertions unchanged.
- `tests/Integration/LegacyImportRegressionTest.php`: `:88` URL → `page=ventas_catalogo&action=import_excel_chunk`.

## RED → GREEN evidence

| Phase | Command | Observed |
|---|---|---|
| 30 RED (tarifario) | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **164 tests / 549 assertions, 2 errors + 1 failure** — both tarifario controller tests error on `require_once .../controller/tarif_catalogo_view.php` (deleted in phase 27); `FamiliaOverrideRemovalTest::test_catalogo_view_call_sites_are_read_only` fails `0 !== 2`. Never a syntax/setup error. |
| 30.1 RED | master-export test absent at destination (`ls plugins/catalogo_core/tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` → missing) | relocation author-first |
| 30.1 GREEN | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` | **OK (6 tests, 24 assertions)** |
| 30.6/30.7 GREEN | `… plugins/catalogo_core/tests/Controller/TarifCatalogoHtmxContractTest.php .../TarifCatalogoOpcionalMasterExportTest.php .../ArticuloModelosExtOwnershipTest.php .../CatalogoManagerOwnershipTest.php .../ArticuloListaCanonicaOwnershipTest.php .../Integration/CatalogoCoreHookMarkersTest.php .../VentasArticulosControllerTest.php .../VentasArticuloControllerTest.php` | **OK (85 tests, 506 assertions)** — includes both relocated controller contracts + the repointed `ArticuloModelosExtOwnershipTest` + all locked tests |
| 30.8 GREEN | `… plugins/tarifario/tests/Integration/{FamiliaOverrideRemovalTest,TarifFamiliaWriteRetirementTest,LegacyImportRegressionTest}.php` | **OK (16 tests, 43 assertions, Skipped 1)** — the SSE smoke skips without a logged-in session |
| 31.1 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **OK (641 tests, 2741 assertions)** — Warnings 26, Skipped 1; floor 611/2422 (+30 tests, +319 assertions) |
| 31.2 | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **OK (162 tests, 555 assertions)** — Skipped 3, zero failures. Counts drop because the 18 relocated methods (8 + 6 contracts + audit assertion shift) now live in catalogo_core (AD-W5-11); the 180/637 pre-relocation floor no longer applies verbatim. |
| 31.3 | `ddev exec php vendor/bin/phpunit --testsuite Plugins` | 1522 tests, **10 failures = exactly the known pre-existing set** — 7× OidcProvider (items 1–7), 2× `TarifTarifaOpcionalPrecedenceTest`, 1× `LegacySupportTest` version drift; **zero new** |
| Lint | `ddev exec php -l` on the new relocated test, the stubs file, `InitUpgradeTest.php` and the 3 repointed tarifario tests | no syntax errors |
| Twig cache | `rm -rf tmp/twig_cache/*` | 7 → 0 entries |

## Phase 31 gates

- 31.1 catalogo_core ≥ 611/2422 ✅ (**641/2741**)
- 31.2 tarifario green ✅ (**162/555**, zero failures; expected count shift from the relocation)
- 31.3 root `Plugins`: zero new failures vs the known pre-existing 10 ✅
- 31.4 grep audit ✅ — zero `plugins/tarifario/` + `@tarifario/` in catalogo_core production (tests/vendor/openspec excluded); `page=tarif_catalogo_view` survives only in `plugins/tarifario/process_excel_wizard.php:477` (accepted WU-7 item); `CatalogoManagerOwnershipTest` + `ArticuloListaCanonicaOwnershipTest` green
- 31.5 no half-moved state ✅ — each of the 5 models + 5 XMLs + controller + wrapper + 4 views + partials/JS exists in exactly one plugin (catalogo_core); the retired `tarif_catalogo_view` controller/view exist nowhere
- 31.6 locked contracts + frozen markers + RBAC ✅ — `CatalogoCoreHookMarkersTest`, `VentasArticulosControllerTest`, `VentasArticuloControllerTest`, tarifario `Integration/HookRegistrationTest` green unedited; `test_rbac_boundary_stays_in_tarifario` + wizard-internals + `descartadas_url` WU-7 boundary green
- 31.7 standalone boot smoke ⏭ deferred to `sdd-verify` (dev-DB; source-contract coverage green in `CatalogoManagerOwnershipTest`)
- 31.8 no core `openspec/` entry ✅ — `git status --porcelain -- openspec/` empty

## Work Unit Evidence (WU-5.E–F)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` on the 2 relocated controller contracts + 6 locked/ownership tests → **OK (85 tests, 506 assertions)**; tarifario 3 repointed audit tests → **OK (16 tests, 43 assertions, Skipped 1)** |
| Runtime harness command/scenario and exact result | Real class-load: the relocated master-export test boots `VentasCatalogo` (PSR-4, `PageController`) and exercises `build_opcionales_export()` through the overridable `opcional_master_state()` seam → **6/6 green**. DB-backed standalone `ensureCatalogoTables()` boot smoke + endpoint-parity runtime remain `sdd-verify` (design Open Questions). |
| Rollback boundary | Revert the 3 tarifario audit-test repoints + restore the 2 tarifario controller test files; delete the 2 new catalogo_core tests + `tests/Support/CatalogoCoreSeedStubs.php` and restore the `InitUpgradeTest.php` file-scope stubs. No production/table/data change in phases 30–31. |

## TDD Cycle Evidence

| Task | RED | GREEN | REFACTOR |
|---|---|---|---|
| 30.1–30.6 | tarifario controller contracts error on the deleted `tarif_catalogo_view`; destination test absent | relocation-ready `TarifCatalogoOpcionalMasterExportTest` + `git rm`/`rm` the tarifario copies → 6/6 green | stubs extracted to `tests/Support/` to break the process-isolation class collision |
| 30.2–30.4 | `FamiliaOverrideRemovalTest` fails `0 !== 2` reading the retired path | 3 audit-test repoints → 16/16 green with assertions unchanged | docblock/label wording updated to the moved controller |
| 30.3 | n/a (already landed in WU-5.A–D) | `ArticuloModelosExtOwnershipTest` repointed and green | — |
| 31.1–31.8 | n/a (verification) | dual suites + root `Plugins` + grep/no-half-moved/frozen-marker audits green | — |

## Deviations / notes

1. **`InitUpgradeTest` seed stubs moved out of file scope (test-infra, assertions untouched).** The relocated master-export contract must load the full `VentasCatalogo` (its `require_once` chain pulls the real `model/core/familia.php`). With `processIsolation`, PHPUnit replays the parent's included files into each isolated child; `InitUpgradeTest.php` declared a `FSFramework\model\familia` seed stub at file scope, so any later isolated child that loaded the real `model/core/familia.php` fataled with `Cannot declare class FSFramework\model\familia`. The stubs now live in `tests/Support/CatalogoCoreSeedStubs.php` and are `require_once`d at runtime inside `InitUpgradeTest::setUp()`; the parent process no longer declares them. This is the same collision the phase-28 `TarifCatalogoHtmxContractTest` worked around by not loading the controller (documented in that file's `setUpBeforeClass`). No production code changed and no assertion was weakened; `InitUpgradeTest` (6 tests) and `FamiliaModelTest` are green together with the relocated test (15 tests, 75 assertions).
2. **The tarifario master-export copy was untracked.** `git rm` cannot delete an untracked file, so task 30.5's `git rm` applied only to the tracked `TarifCatalogoHtmxContractTest.php`; the untracked `TarifCatalogoOpcionalMasterExportTest.php` was removed from the working tree (`rm`). No index entry is left behind and no test file exists in both plugins.
3. **Task 30.3 was already landed in WU-5.A–D.** `ArticuloModelosExtOwnershipTest:356` was repointed to `plugins/catalogo_core/Controller/VentasCatalogo.php` during phase 29 to keep the catalogo_core suite green (WU-5.A–D deviation 4). The second launch verified it; the checkbox records the verification, not a new edit.
4. **Tarifario suite count/assertion floor shift is expected.** Phase 30 moved 14 contract methods (8 htmx + 6 master-export) plus the audit repoints out of/through the tarifario suite: 162 tests / 555 assertions vs the 180 / 637 pre-relocation floor. Zero failures; catalogo_core absorbed the coverage (+30 tests / +319 assertions over the 611/2422 floor). AD-W5-11/design explicitly anticipated the tarifario reduction ("180 / 637 minus the 18 relocated methods").
5. **`TarifFamiliaWriteRetirementTest` writer #3 was a latent vacuous pass.** Before the repoint it read the deleted path with `file_get_contents` → `(string) false` → the `assertStringNotContainsString` checks passed vacuously. It now audits the real moved controller; assertions are unchanged and meaningful again.
6. **Phase 32 (commits) intentionally NOT executed.** Landing order documented in `tasks.md` Phase 32: **catalogo_core commit FIRST, tarifario immediately after, no deploy between** (AD-W5-12, add-before-remove); revert in reverse order and clear the Twig cache. Plugin `fsframework.ini` bumps deferred to `fsframework-plugin-release`.
7. **Accepted WU-5/WU-7 boundary (unchanged).** `plugins/tarifario/process_excel_wizard.php:477` `descartadas_url` keeps `page=tarif_catalogo_view`, so the SSE "download discarded rows" link 404s until WU-7 repoints it (CAT-08); confirmed by `CatalogoManagerOwnershipTest::test_descartadas_url_repoint_is_a_wu7_item`.

## Next

- `sdd-verify` for WU-5: dev-DB standalone `ensureCatalogoTables()` idempotency (5 catalog tables, two boots) + the endpoint-parity runtime smoke (tree/search/fragment actions, per-tarifa master export, retirement of the `tarif_catalogo_view` `fs_page` row).
- Then the sequenced program WU-6..WU-8.

---

# Revert WU-4 + WU-5 (boundary correction)

- **Trigger**: the user corrected the ownership boundary — the article extension
  fields (`tarif_articulo`, `tarif_articulos_ext` → Ref SAP / Ref Catálogo /
  Configurador) and the catalog manager are **tarifario-exclusive** and must NOT
  live in catalogo_core. WU-4 and WU-5 are reverted surgically; WU-1/WU-2/WU-3
  and the sibling opcionales change stay intact. **Working tree only — no commit/push.**
- **Runner / baselines after the revert**: catalogo_core **602 / 2343** (exact WU-3 end state);
  tarifario **180 / 637** (pre-WU-4/WU-5 baseline); root `Plugins` 10 known failures, zero new.

## Restored (tarifario)

- WU-5: `controller/tarif_catalogo_view.php`, 4 `View/tarif_catalogo*.html.twig`, 12 `View/partials/catalogo/*`, 9 `View/js/catalogo/*`, 5 `model/tarif_catalogo*.php` + 5 `model/table/tarif_catalogo*.xml`, `tests/Controller/TarifCatalogoHtmxContractTest.php`.
- `tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` recreated from the catalogo_core copy and retargeted to the tarifario controller/FQCN.
- WU-4: `model/tarif_articulo.php` (copied back from the catalogo_core move — keeps the WU-2 `url()` repoint + opcionales repoint), `model/tarif_articulos_ext.php`, `model/table/tarif_articulos.xml`, `model/tarif_descripcion.php`.

## Removed (catalogo_core)

- WU-5: `Controller/VentasCatalogo.php`, `controller/ventas_catalogo.php`, `extras/VentasCatalogoStateTrait.php`, the 5 catalog models + 5 XMLs, the 4 renamed views + 21 partials/JS, `tests/CatalogoManagerOwnershipTest.php`, `tests/Controller/TarifCatalogoHtmxContractTest.php`, `tests/Controller/TarifCatalogoOpcionalMasterExportTest.php`, `tests/Support/CatalogoCoreSeedStubs.php`.
- WU-4: `model/tarif_articulo.php`, `model/tarif_articulos_ext.php`, `model/table/tarif_articulos.xml`, `tests/ArticuloModelosExtOwnershipTest.php`.

## Reverted

- `Init.php`: dropped `ensureArticuloExtTables()` (WU-4), `ensureCatalogoTables()` + `retireTarifCatalogoViewPage()` (WU-5), and every `init()`/`upgrade()` wiring line for them. WU-1/WU-2/WU-3 and the opcionales retirements remain.
- `View/Macro/TarifarioComponents.html.twig:271` → back to `page=tarif_catalogo_view&codtarifa=…`.
- `tests/InitUpgradeTest.php`: dropped the 2 WU-5 cases (`upgradeRetiresTarifCatalogoViewPageIdempotently`, `test_ensure_catalogo_tables_is_wired_and_fk_safe`), restored the file-scope seed stubs and the 3 keep retirement cases.
- `tests/ArticuloListaCanonicaOwnershipTest.php` → method inverted back to `test_tarif_articulo_and_ext_stay_in_tarifario_for_wu4` (+ docblock).
- `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php:935` → `plugins/tarifario/model/tarif_articulo.php`.
- WU-4 require repoints reverted to the tarifario model paths: `extras/tarifario_init.php` (restored the `tarif_descripcion` boot block), `controller/tarif_actualizar_precios.php`, `controller/tarif_catalogo_view.php`, `Services/ArticuloListActionHandler.php`, `tests/Model/TarifArticuloFactoryForImportTest.php`, `tests/Services/ExcelImportWizardServiceTest.php`.
- WU-5 audit repoints reverted: `tests/Integration/FamiliaOverrideRemovalTest.php`, `tests/Integration/TarifFamiliaWriteRetirementTest.php`, `tests/Integration/LegacyImportRegressionTest.php`.

## Evidence

| Gate | Command | Observed |
|---|---|---|
| catalogo_core suite | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **OK (602 tests, 2343 assertions)** — Warnings 26, Skipped 1 |
| tarifario suite | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **OK (180 tests, 637 assertions)** — Skipped 3 |
| root regression | `ddev exec php vendor/bin/phpunit --testsuite Plugins` | 1503 tests, **10 failures = exactly the known pre-existing set**; zero new |
| half-moved audit | `ls` both plugins for the WU-4/WU-5 surfaces | tarifario-only for `tarif_articulo`/`tarif_articulos_ext`/`tarif_catalogo*`/controller/views/assets; `VentasCatalogo` exists nowhere |
| coupling grep | catalogo_core production scan | **0** hits for `plugins/tarifario/` + `@tarifario/` (tests/vendor/openspec excluded) |
| slug audit | `grep -rn "page=tarif_catalogo_view"` | resolves in tarifario (controller + macro + tests); `process_excel_wizard.php:477` link now resolves |
| WU-1/2/3 intact | focused suites within the green runs | ownership + hook + locked tests all green |
| Twig cache | `rm -rf tmp/twig_cache/*` | 64 → 0 entries |

## Deviations / notes

1. **`controller/tarif_catalogo_view.php` was reconstructed, not literally `git restore`d.** WU-1/WU-2 require repoints and the opcionales `build_opcionales_export()`/`opcional_master_state()` work predate WU-5 in this file; a HEAD restore would have dropped them. The file is the catalogo_core WU-5 copy with the WU-5 reclass/soft-seam edits reversed, keeping `tarif_articulos_ext` on the tarifario path.
2. **`model/tarif_articulo.php` was copied back from the catalogo_core move** (WU-2 `url()` + opcionales repoints preserved), not restored from HEAD.
3. **`tests/InitUpgradeTest.php`** keeps the 3 non-WU-5 retirement cases; the WU-5.E `tests/Support/` extraction is reverted.
4. **No impossible reconciliation.** After the revert catalogo_core production has no `tarif_articulo`/`tarif_articulos_ext` reference; the tarifario-exclusive `ref_sap`/`ref_catalogo`/`configurador` surfaces live only in the tarifario catalog controller. No soft seam was needed.

---

# Revert configurador (boundary correction)

- **Rationale (user boundary correction)**: the hierarchical opcionales configurator (`tarif_configurador_opcionales`) is **tarifario-exclusive** and must NOT live in catalogo_core. This slice reverts the configurator half of the `opcionales-por-tarifa` absorption (catalogo_core `683d57c` / tarifario `e469f06`) only.
- **Not touched**: the opcionales list/detail/precios (`tarif_opcionales` → `ventas_opcionales`, `tarif_opcional_edit`, `tarif_opcional_precios`), the opcional tab, the master models, and the WU-1/2/3 article work.
- **Delivery**: working tree only — NO `git commit`/`push`.

## Files added (tarifario)

- `controller/tarif_configurador_opcionales.php` (byte-identical copy of the catalogo_core fork; sha256-equal).
- `View/tarif_configurador_opcionales.html.twig` (byte-identical; 25498 B).
- `View/tarif_configurador_tree.html.twig` (byte-identical; 15978 B).
- `View/partials/configurador/styles.html.twig` (byte-identical; 9767 B).
- `tests/TarifConfiguradorOpcionalesTest.php` (relocated from catalogo_core; `namespace Tests\CatalogoCore` → `Tests\Tarifario`; `CONTROLLER_RELATIVE` + the view read repointed to tarifario; catalogo_core model constants kept).

## Files removed (catalogo_core, `git rm`)

- `controller/tarif_configurador_opcionales.php`
- `View/tarif_configurador_opcionales.html.twig`
- `View/tarif_configurador_tree.html.twig`
- `View/partials/configurador/styles.html.twig`
- `tests/TarifConfiguradorOpcionalesTest.php`

## Reverted / repointed

- `plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php`: `CONTROLLER_SLUGS` dropped `tarif_configurador_opcionales` (only `tarif_opcional_edit` remains) + docblock updated.
- `plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php:111`: hardcoded-consumer audit repointed to `plugins/tarifario/controller/tarif_configurador_opcionales.php`.
- No `Init`/menu change: page discovery scans the active plugins' `controller/` dirs; no `Init` referenced the configurator.

## Base-class decision (what actually loads)

The controller keeps `extends fbase_controller` from the catalogo_core fork. `fbase_controller` is required by `catalogo_core/extras/TarifarioOpcionalStateTrait.php` (which the controller also requires), and `tarifario/extras/tarif_controller.php` itself extends `fbase_controller`. Keeping the byte-identical base avoids re-pulling `tarif_controller`'s `private_core()` state init and keeps the fork's zero-`plugins/tarifario/` read path; the moved test's runtime class-load (`new class extends \tarif_configurador_opcionales`) proves it loads.

## Evidence

| Gate | Command | Observed |
|---|---|---|
| focused moved test | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml plugins/tarifario/tests/TarifConfiguradorOpcionalesTest.php` | **OK (17 tests, 89 assertions)** |
| catalogo_core suite | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **OK (585 tests, 2247 assertions)** — Warnings 26, Skipped 1 (was 602/2343; −17 tests / −96 assertions from the relocation + contract-list shrink) |
| tarifario suite | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **OK (197 tests, 726 assertions)** — Skipped 3 (was 180/637; +17 tests / +89 assertions) |
| root regression | `ddev exec php vendor/bin/phpunit --testsuite Plugins` | 1503 tests, **10 failures = exactly the known pre-existing set** (7× OidcProvider, 2× `TarifTarifaOpcionalPrecedenceTest`, 1× `LegacySupportTest`); zero new |
| half-moved audit | `ls` both plugins | the 4 assets + test exist in **tarifario only**; none in catalogo_core |
| reference audit | `grep -rn "catalogo_core/controller/tarif_configurador\|catalogo_core/View/tarif_configurador\|catalogo_core/View/partials/configurador"` | **0** hits (openspec excluded) |
| byte-identity | `sha256sum` catalogo_core source vs tarifario copy (before removal) | 4/4 identical |
| runtime load | moved test's anonymous subclass of `\tarif_configurador_opcionales` | class + base chain load in a fresh process |
| lint | `php -l` on moved controller/test + the 2 repointed tests | no syntax errors |
| Twig cache | `rm -rf tmp/twig_cache/*` | 0 entries |

## Deviations / notes

1. **Byte-identical relocation kept the catalogo_core license header** in the moved controller/views (`This file is part of catalogo_core`). The task required byte-identical files; a follow-up attribution touch (→ tarifario) is optional and was intentionally not folded into this revert.
2. **The moved test is configurator-specific**, so it relocated with the configurator; the catalogo_core-side model tests it also drives (resolver, `catalogo_articulo_opcional`, `catalogo_opcional_familia`) still run from tarifario against the catalogo_core models (dependency direction tarifario → catalogo_core).
3. **No impossible reconciliation.** The opcionales list/detail/precios, the opcional tab, the master models and WU-1/2/3 were not modified; the only catalogo_core production change is the configurator removal.
