# Apply Progress: Percentage pricing + opcional groups + unified detail (WU-1..WU-4)

- **Change**: `opcionales-detalle-porcentaje-y-grupos` (plugin-local, `catalogo_core`)
- **Artifact store**: `openspec` (plugin-local only; nothing written to the core `openspec/`)
- **Mode**: Strict TDD (`strict_tdd: true` in `plugins/catalogo_core/openspec/config.yaml`)
- **Scope applied**: Phase 1 (WU-1) + Phase 2 (WU-2) + Phase 3 (WU-3) + Phase 4 (WU-4)
- **Runner**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- **Baseline**: 511 tests / 1756 assertions OK (25 warnings, 1 skipped)
- **Final**: **528 tests / 1826 assertions OK** (25 warnings, 1 skipped; delta from WU-3 removed 6 precios tests / 12 assertions, WU-3 ordering +4 tests / 7 assertions, WU-4 +1 test / 27 assertions)

## TDD Cycle Evidence

> WU-1 was written test-first. WU-2 production wiring landed in the same GREEN
> batch as WU-1; its RED evidence was reproduced by temporarily neutering the
> exact production seam each test targets, observed failing, then restoring
> (the plugin tree is gitignored at the repo root, so revert-to-HEAD was not
> available).

| Task | RED | GREEN | REFACTOR |
|---|---|---|---|
| 1.1 | `--filter CatalogoOpcionalesPercentageTest` → `Tests: 11, Assertions: 9, Errors: 5, Failures: 3` (undefined `set_porcentaje_tarifa`, `limpiar_porcentaje`, missing `valor_precio_export`, percentage label rendered `0,00 €`) | `--filter CatalogoOpcionalesPercentageTest` → `OK (11 tests, 36 assertions)` | Accessors renamed collision-free; helpers extracted (`etiqueta_porcentaje_opcional`, `valor_precio_export`) |
| 1.2 | same run (errors 1–3) | same GREEN run | `set_precio_tarifa`/`set_porcentaje_tarifa` share the `opcional_precio_model()` factory |
| 1.3 | same run (error 4) | same GREEN run | force flag named `porcentaje_limpiado`; guard documented |
| 1.4 | same run (failures 2–3) | same GREEN run | export loop collapsed to one `valor_precio_export()` call used by both family branches |
| 1.5 | n/a — views are contract-locked by existing tests; ran those suites | `--filter TarifOpcionalTabEndpointTest` → `OK (7 tests, 45 assertions)` | percentage input branch kept inside the single `guardar_precio_tarifa` form |
| 1.6 | guard suites green pre-change | full suite `OK (528 tests, 1838 assertions)` | — |
| 2.1 | `--filter test_grupo_column_map_loads_once_without_n_plus_one` → `ERROR: Method ... load_grupos_cache() does not exist`; `--filter test_pagination_uses_count_filtered_true_total_and_preserves_filters` → `the group filter must reach count_filtered()` expected `'5'`, got `''` | `--filter CatalogoOpcionalesUnifiedControllerTest` → `OK (19 tests, 111 assertions)`; `--filter CatalogoOpcionalesHtmxContractTest` → `OK (8 tests, 83 assertions)` | group label map built once from `all_activos()`; `where_id_grupo()` shared by both models |
| 2.2 | (RED above: neutered trailing arg) | same GREEN runs | `where_id_grupo()` helper centralizes `''`/`'0'`/numeric semantics |
| 2.3 | (RED above: method renamed) | same GREEN runs | `opcional_grupo_model()` seam added |
| 2.4 | (view contract RED via missing `b_id_grupo`/`Grupo`/`sid_grupo` assertions) | same GREEN runs | group filter appended to every hx-get and to the toggle hidden inputs |
| 2.5 | n/a | full suite `OK (528/1838)` | — |
| 3.1 | `--filter CatalogoOpcionalesUnifiedControllerTest` → `test_deleted_page_has_no_controller_or_view` fails (`controller/tarif_opcional_precios.php` still exists); `--filter InitUpgradeTest` → `upgradeRetiresTarifOpcionalPreciosPageIdempotently` fails (method absent) | `--filter 'TarifOpcionalesControllerContractTest|TarifOpcionalPreciosControllerTest'` → `OK (5 / 3 tests)`; `--filter CatalogoOpcionalesUnifiedControllerTest` → `OK (19/110)` | Precios-specific methods removed, adapter tests kept; `CONTROLLER_SLUGS` frozen at two |
| 3.2 | (same RED: the source-contract/`assertFileDoesNotExist` assertions above) | controller+view deleted; `cat plugins/catalogo_core/controller/tarif_opcional_precios.php` → not found | Edit view already carried the selector/scoped panel/overview, so parity held without new markup |
| 3.3 | `grep -rn 'page=tarif_opcional_precios'` → 4 hits | same grep → `exit=1` (no hits) | Repointed through `value.url()`/`opcional.url()` + `&codtarifa=`; dropped the redundant edit button |
| 3.4 | `--filter InitUpgradeTest` → RED (method absent) | `--filter InitUpgradeTest` → `OK (3 tests, 11 assertions)` | `retireTarifOpcionalPreciosPage()` mirrors `retireTarifOpcionalesPage()`; own `try/catch` sibling |
| 3.5 | n/a | full suite `OK (528/1826)`; grep audit clean | — |
| 3.6/3.7 | `--filter VentasOpcionalesControllerTest` → `testPrivateCoreLoadsTheListBeforeDispatchingCreate` fails `640 < 598` (init after create); runtime test errors on missing stub (fixed locally) | `--filter VentasOpcionalesControllerTest` → `OK (7 tests, 15 assertions)` | `init_opcionales_list()` hoisted to the top of `privateCore()`; export branch drops the now-redundant `ini_filters()` |
| 4.1 | `--filter TarifOpcionalesHtmxContractTest` (new absorbed-panel + no-`\|raw`/bootbox assertions) — view already satisfied the contract | `--filter TarifOpcionalesHtmxContractTest` → `OK (12 tests, 110 assertions)` | No production change needed; WU-4 posture already present in the unified detail |
| 4.2 | n/a | same GREEN run | — |
| 4.3 | guard green pre-change | `--filter TarifOpcionalEditTarifaSelectorTest` → `OK (9 tests, 28 assertions)`; full suite `OK (528/1826)` | — |

## Work Unit Evidence

### WU-1 — percentage read/write + all surfaces

| Evidence | Value |
|---|---|
| Focused test command + result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoOpcionalesPercentageTest` → `OK (11 tests, 36 assertions)` |
| Runtime harness | `index.php?page=ventas_opcionales` percentage row renders `etiqueta_porcentaje_opcional()` (`12,5%`), never `0,00 €`; `index.php?page=tarif_opcional_edit&...` scoped panel renders name="porcentaje" with `%` addon; `page=tarif_opcional_tab` rows render `name="porcentaje"` + `modo_porcentaje` marker |
| Rollback boundary | `model/tarif_opcional.php`, `model/tarif_opcional_precio.php`, `extras/VentasOpcionalesListTrait.php`, `controller/tarif_opcional_edit.php`, `controller/tarif_opcional_tab.php`, `View/ventas_opcionales.html.twig`, `View/tarif_opcional_edit.html.twig`, `View/Hooks/partials/opcional_precios_rows.html.twig` + `tests/CatalogoOpcionalesPercentageTest.php`, `tests/TarifOpcionalTabEndpointTest.php` |

### WU-2 — opcional groups in the unified list

| Evidence | Value |
|---|---|
| Focused test command + result | `--filter CatalogoOpcionalesUnifiedControllerTest` → `OK (19 tests, 111 assertions)`; `--filter CatalogoOpcionalesHtmxContractTest` → `OK (8 tests, 83 assertions)` |
| Runtime harness | `index.php?page=ventas_opcionales&b_id_grupo=0` filters to "Sin grupo" (`IS NULL OR = 0`); `&b_id_grupo=<id>` filters one group; the `Grupo` column renders from the controller map (no `etiqueta_grupo()` per row); create modal accepts `sid_grupo` |
| Rollback boundary | `extras/VentasOpcionalesListTrait.php` (group map/filter/create), `model/tarif_opcional.php` + `model/core/catalogo_opcional.php` (trailing `$id_grupo`), `View/ventas_opcionales.html.twig` + the two test files |

### WU-3 — detail unification + create-ordering fix

| Evidence | Value |
|---|---|
| Focused test command + result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'TarifOpcionalesControllerContractTest'` → `OK (5 tests, 14 assertions)`; `--filter 'TarifOpcionalPreciosControllerTest'` → `OK (3 tests, 5 assertions)`; `--filter 'CatalogoOpcionalesUnifiedControllerTest'` → `OK (19 tests, 110 assertions)`; `--filter 'VentasOpcionalesControllerTest'` → `OK (7 tests, 15 assertions)`; `--filter 'InitUpgradeTest'` → `OK (3 tests, 11 assertions)` |
| Runtime harness | `index.php?page=tarif_opcional_edit&id=<id>&codtarifa=<code>` now serves the whole detail (selector + scoped panel + overview); `page=tarif_opcional_precios` has no controller/view. Create from `index.php?page=ventas_opcionales` now persists per-tarifa values because `init_opcionales_list()` runs first. |
| Rollback boundary | `controller/tarif_opcional_precios.php` + `View/tarif_opcional_precios.html.twig` (deleted), `Controller/VentasOpcionales.php::privateCore`, `Init.php::retireTarifOpcionalPreciosPage`, `View/ventas_opcionales.html.twig` (2 links), `View/tarif_opcional_edit.html.twig` (1 link), `plugins/tarifario/View/tarif_articulo_precios.html.twig`, the 6 touched test files |

### WU-4 — htmx 4 + Alpine CSP on the unified detail

| Evidence | Value |
|---|---|
| Focused test command + result | `--filter 'TarifOpcionalesHtmxContractTest'` → `OK (12 tests, 110 assertions)`; `--filter 'TarifOpcionalEditTarifaSelectorTest'` → `OK (9 tests, 28 assertions)` |
| Runtime harness | `index.php?page=tarif_opcional_edit&id=..` renders under CSP: `htmx.boot({'allowScriptTags': false})` + `alpine.boot()`, colon events only, `Alpine.data()` behind `alpine:init` with `csp_nonce_attr()`, one `hx-post` scoped form, zero `hx-delete`, zero `\|raw`, zero `bootbox`. |
| Rollback boundary | `View/tarif_opcional_edit.html.twig` + `tests/TarifOpcionalesHtmxContractTest.php` (no production change was required; the posture already held, the new contract test locks it) |

## Files Changed

| File | Action | What Was Done |
|---|---|---|
| `plugins/catalogo_core/tests/CatalogoOpcionalesPercentageTest.php` | Created | 11 strict-TDD tests: writer symmetry, force-flag, fallback, label, export format, tab percentage save |
| `plugins/catalogo_core/model/tarif_opcional.php` | Modified | `opcional_precio_model()`, `set_porcentaje_tarifa()`, percentage-clearing `set_precio_tarifa()`, trailing `$id_grupo` in `search()`/`count_filtered()` |
| `plugins/catalogo_core/model/tarif_opcional_precio.php` | Modified | `limpiar_porcentaje()` force flag; `save()` preserves stored `porcentaje` only when not explicitly cleared |
| `plugins/catalogo_core/model/core/catalogo_opcional.php` | Modified | trailing `$id_grupo` in `search()`; shared `where_id_grupo()` helper (`''`/`'0'`/numeric, `intval()` guarded) |
| `plugins/catalogo_core/extras/VentasOpcionalesListTrait.php` | Modified | percentage cache/accessors + `show_precio_opcional` branch + `valor_precio_export()`; `new_opcional` mode/percentage/group; `load_grupos_cache()` + `nombre_grupo_opcional()`; `b_id_grupo` wiring |
| `plugins/catalogo_core/controller/tarif_opcional_edit.php` | Modified | percentage read in `load_precios_tarifas` + `get_porcentaje_tarifa()`; percentage-aware atomic scoped/bulk saves |
| `plugins/catalogo_core/controller/tarif_opcional_tab.php` | Modified | `opcional_model()` seam; rows render percentage mode; `guardar_precio_tab` percentage branch |
| `plugins/catalogo_core/View/ventas_opcionales.html.twig` | Modified | percentage price cell; create-modal mode/percentage/group; `Grupo` column; `b_id_grupo` filter; filter carried across toggles |
| `plugins/catalogo_core/View/tarif_opcional_edit.html.twig` | Modified | percentage input branch + all-tarifas overview percentage; single `guardar_precio_tarifa` form preserved |
| `plugins/catalogo_core/View/Hooks/partials/opcional_precios_rows.html.twig` | Modified | renders `porcentaje` input + `%` addon + `modo_porcentaje` marker in percentage mode |
| `plugins/catalogo_core/tests/TarifOpcionalTabEndpointTest.php` | Modified | DB-free `opcional_model()` seam + percentage-row persistence test |
| `plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php` | Modified | 6-arg search/count stubs, group filter assertions, group map (no N+1), `where_id_grupo()` sentinel/injection, percentage+group create |
| `plugins/catalogo_core/tests/CatalogoOpcionalesHtmxContractTest.php` | Modified | `b_id_grupo` filter/column + percentage create-modal contract |
| `plugins/catalogo_core/controller/tarif_opcional_precios.php` | Deleted | Consolidated into `tarif_opcional_edit`; no alias (OUM-11/AD-10) |
| `plugins/catalogo_core/View/tarif_opcional_precios.html.twig` | Deleted | Consolidated into `tarif_opcional_edit`; no alias (OUM-11/AD-10) |
| `plugins/catalogo_core/Controller/VentasOpcionales.php` | Modified | `privateCore()` now calls `init_opcionales_list()` before the create/delete dispatch (create-ordering fix) |
| `plugins/catalogo_core/Init.php` | Modified | idempotent `retireTarifOpcionalPreciosPage()` + `upgrade()` wiring (AD-11) |
| `plugins/catalogo_core/View/tarif_opcional_edit.html.twig` | Modified | dropped the redundant "Precios por tarifa" button (`:79`); WU-4 posture re-verified |
| `plugins/catalogo_core/View/ventas_opcionales.html.twig` | Modified | repointed 2 links to `value.url()&codtarifa=..` (no `page=tarif_opcional_precios`) |
| `plugins/tarifario/View/tarif_articulo_precios.html.twig` | Modified | repointed the opcional edit link to `opcional.url()&codtarifa=..` |
| `plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php` | Modified | `CONTROLLER_SLUGS` 3 → 2 (`tarif_opcional_precios` removed) |
| `plugins/catalogo_core/tests/TarifOpcionalPreciosControllerTest.php` | Modified | dropped `test_moved_controller_persists_through_the_catalogo_core_adapter`; adapter contract kept |
| `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php` | Modified | dropped `PRECIOS_*`, `buildPreciosController` + 3 precios tests; repointed master/macro/CSRF assertions |
| `plugins/catalogo_core/tests/TarifOpcionalesHtmxContractTest.php` | Modified | dropped `PRECIOS_VIEW` + 2 precios tests; added absorbed-panel + no-`\|raw`/bootbox contracts |
| `plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php` | Modified | dropped the deleted view from the repoint list; added the no-controller/view deletion guard |
| `plugins/catalogo_core/tests/InitUpgradeTest.php` | Modified | added `upgradeRetiresTarifOpcionalPreciosPageIdempotently` |
| `plugins/catalogo_core/tests/VentasOpcionalesControllerTest.php` | Modified | added the create-ordering source + runtime regression tests |
| `.../openspec/changes/opcionales-detalle-porcentaje-y-grupos/tasks.md` | Modified | Phase 1–4 tasks marked `[x]` |
| `.../openspec/changes/opcionales-detalle-porcentaje-y-grupos/apply-progress.md` | Created/Updated | This artifact (WU-1..WU-4 cumulative) |

## Regression Gate

Full suite green. Named gate tests (post-WU-3/WU-4):

- `VentasOpcionalesControllerTest` → `OK (7 tests, 15 assertions)`
- `CatalogoOpcionalesPercentageTest` → `OK (11 tests, 36 assertions)`
- `CatalogoOpcionalesUnifiedControllerTest` → `OK (19 tests, 110 assertions)`
- `CatalogoOpcionalesHtmxContractTest` → `OK (8 tests, 83 assertions)`
- `TarifOpcionalEditTarifaSelectorTest` → `OK (9 tests, 28 assertions)`
- `TarifOpcionalTabEndpointTest` → `OK (7 tests, 45 assertions)`
- `TarifConfiguradorOpcionalesTest` → `OK (17 tests, 89 assertions)`
- `OpcionalDomainModelOwnershipTest` → `OK (5 tests, 66 assertions)`
- `TarifOpcionalesControllerMasterStateTest` → `OK (14 tests, 79 assertions)`
- `TarifOpcionalesHtmxContractTest` → `OK (12 tests, 110 assertions)`
- `TarifOpcionalesControllerContractTest` → `OK (5 tests, 14 assertions)`
- `TarifOpcionalPreciosControllerTest` → `OK (3 tests, 5 assertions)`
- `InitUpgradeTest` → `OK (3 tests, 11 assertions)`
- `plugins/tarifario` suite → `OK (191 tests, 705 assertions, 3 skipped)` — no new failures.

## Deviations from Design

- **Trait accessor names**: design shorthand `get_porcentaje`/`etiqueta_precio_lista` were implemented as `get_porcentaje_opcional_tarifa()` / `etiqueta_porcentaje_opcional()` to avoid clashing with the inherited model methods; `show_precio_opcional()` stays the public entry and branches on the cached mode.
- **Create input aliases**: `new_opcional()` accepts `tipo_precio` (falls back to `stipo_precio`), `porcentaje` (falls back to `sporcentaje`) and `sid_grupo` (falls back to `id_grupo`), so both the unified-modal names and the legacy `VentasOpcional` names work.
- **Percentage create scope**: percentage mode writes the effective per-tarifa row for the selected/default tarifa only; every other tarifa falls back to the global percentage through `get_porcentaje()` (AD-1). Fixed mode keeps per-tarifa rows.
- **Legacy bulk matrix**: in percentage mode the legacy `precio_tarifa_*` value is stored as the per-tarifa percentage with `precio = 0`; in fixed mode it stores the price and clears any stale percentage (the bulk form is no longer rendered by the view).
- **OTS-10 conflict (flag for verify)**: `opcionales-tarifa-selector` OTS-10 lists `model/tarif_opcional_precio.php` (and `model/tarif_tarifa_opcional.php`) as "MUST NOT change", but design AD-3, the Interfaces block and task 1.3 explicitly add `limpiar_porcentaje()` to that adapter. The additive force flag was implemented per design/tasks; the OTS-10 wording needs reconciliation at verify (the adapter's table identity/`codlista` key behavior is unchanged).
- **WU-3 absorption was already in the edit view (no new markup)**: the canonical `tarif_opcional_edit` already carried the per-tarifa selector, the scoped `guardar_precio_tarifa` panel and the all-tarifas overview before WU-3; the absorption therefore reduced to deleting the duplicate page and dropping the now-redundant button. No parity markup had to be added (verified against `TarifOpcionalEditTarifaSelectorTest` and the new `test_edit_view_absorbs_the_scoped_panel_and_percentage_toggle`).
- **WU-4 required no production change**: `View/tarif_opcional_edit.html.twig` already had `htmx.boot({'allowScriptTags': false})`, `alpine.boot()`, colon events, nonce'd `Alpine.data()` behind `alpine:init`, `[x-cloak]` and `hx-post`-only mutations. WU-4 added the contract test that locks the absorbed panel + percentage toggle.
- **Export filter init**: hoisting `init_opcionales_list()` to the top of `privateCore()` removed the export branch's separate `ini_filters()` call; the filter state is now resolved once for every action. `load_opcionales_for_export()` still reads the resolved `$this->b_*` values, so the OUM-07 filter parity is preserved.

## Issues Found

- **Create-ordering gap (RESOLVED in WU-3)**: `Controller/VentasOpcionales::privateCore()` dispatched `new_opcional()` before `init_opcionales_list()`, so `$this->tarifas` / `$this->tarifa_seleccionada` were unset at creation time and the create-modal per-tarifa values were silently dropped. Fixed by loading the list first; locked by `VentasOpcionalesControllerTest::testPrivateCoreLoadsTheListBeforeDispatchingCreate` (source order, RED `640 < 598`) and `testCreateModalPerTarifaValuesPersistWhenTarifasAreLoaded` (runtime persistence).
- **`tarif_opcional_precio` boundary**: the adapter now carries an extra private force flag; no schema/table change and the `OpcionalPriceUnificationTest`/`DeadOpcionalTableReferenceTest` guards stay green.
- **WU-3 removed locked tests**: the precios-specific assertions were removed per OUM-11/OUM-12 (the surface is deleted), so the net assertion count is 1826 (was 1838) while the test count stays 528 (removed 6 precios assertions, added 4 ordering + 1 htmx).

## Remaining Tasks

- [ ] Phase 5 (5.1–5.4) — verify: full suite, `composer phpstan`, grep audits, absence of core `openspec/` entry.

## Status

Phase 1 (6/6) + Phase 2 (5/5) + Phase 3 (7/7) + Phase 4 (3/3) complete. Full suite **528 tests / 1826 assertions OK** (25 warnings, 1 skipped) at/above the 511/1756 floor. Tarifario suite `OK (191 tests, 705 assertions, 3 skipped)`. Ready for independent SDD verification of WU-3 + WU-4.
