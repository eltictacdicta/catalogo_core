# Tasks: Percentage pricing, opcional groups, unified single-opcional detail

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~900–1200 (additions + deletions) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (WU-1 percentage) → PR 2 (WU-2 groups) → PR 3 (WU-3 detail unification) → PR 4 (WU-4 htmx/Alpine) |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| WU-1 | Percentage read/write symmetry + all surfaces/export | PR 1 | `phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoOpcionalesPercentageTest` | `index.php?page=ventas_opcionales` (percentage row shows `12,5%`) | Model writers + trait + list view + tab/partial + edit panel/tests |
| WU-2 | Grupo column, `b_id_grupo` filter, create assignment | PR 2 | `phpunit ... --filter 'CatalogoOpcionalesUnifiedControllerTest|CatalogoOpcionalesHtmxContractTest'` | List filtered by `b_id_grupo` incl. "Sin grupo" | Trait group map/filter + search params + list view + tests |
| WU-3 | Delete precios detail, repoint links, retire page | PR 3 | `phpunit ... --filter 'TarifOpcionalesControllerContractTest|TarifOpcionalPreciosControllerTest|InitUpgradeTest'` | `index.php?page=tarif_opcional_edit&codtarifa=..` serves the detail | Deletion + link/Init edits + contract test updates |
| WU-4 | htmx 4 + Alpine CSP on unified detail | PR 4 | `phpunit ... --filter 'TarifOpcionalesHtmxContractTest|TarifOpcionalEditTarifaSelectorTest'` | Edit detail selector/panel/toggle under CSP | `View/tarif_opcional_edit.html.twig` + htmx contract test |

## Phase 1: WU-1 — Percentage read/write (AD-1, AD-2, AD-3, AD-4, AD-8, AD-9, AD-13)

- [x] 1.1 RED: create `plugins/catalogo_core/tests/CatalogoOpcionalesPercentageTest.php` with `test_set_porcentaje_tarifa_sets_pct_and_zeroes_precio`, `test_set_precio_tarifa_clears_porcentaje`, `test_get_porcentaje_falls_back_to_global`, `test_show_precio_opcional_renders_percentage_label`, `test_valor_precio_export_uses_percentage_format`, `test_tab_save_persists_porcentaje_and_zeroes_precio`.
- [x] 1.2 GREEN: `model/tarif_opcional.php` — add `opcional_precio_model()` (AD-4), `set_porcentaje_tarifa($codtarifa,$pct)` (AD-2), make `set_precio_tarifa()` clear `porcentaje` via adapter (AD-2).
- [x] 1.3 GREEN: `model/tarif_opcional_precio.php` — add `limpiar_porcentaje()` force flag; `save()` preserves stored value only when absent (AD-3).
- [x] 1.4 GREEN: `extras/VentasOpcionalesListTrait.php` — carry mode/percent in `load_precios_cache`, add percentage-aware accessor, branch `show_precio_opcional` on `es_precio_porcentaje()`/`etiqueta_precio_lista()` (AD-1); `new_opcional` writes `tipo_precio`/`porcentaje`; `export_excel_opcionales` emits numeric `get_porcentaje` + `valor_precio_export()` `0.00"%"` (AD-9).
- [x] 1.5 GREEN: `View/ventas_opcionales.html.twig` percentage price cell + create-modal toggle/percentage fields; `controller/tarif_opcional_edit.php` percentage read/write in scoped panel; `View/tarif_opcional_edit.html.twig` input branch; `controller/tarif_opcional_tab.php` + `View/Hooks/partials/opcional_precios_rows.html.twig` persist `porcentaje` in mode.
- [x] 1.6 REFACTOR + guard: keep `tests/TarifOpcionalEditTarifaSelectorTest.php` single `guardar_precio_tarifa` form green (AD-8/R3); run `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` ≥ baseline.

## Phase 2: WU-2 — Opcional groups (AD-5, AD-6, AD-7, AD-8)

- [x] 2.1 RED: extend `tests/CatalogoOpcionalesUnifiedControllerTest.php` (`test_pagination_uses_count_filtered_true_total_and_preserves_filters:453`, `test_new_opcional_normalizes_prices_and_persists:573`) and `tests/CatalogoOpcionalesHtmxContractTest.php` (`test_list_filters_use_hx_get_body_swap_and_url_push:98`, `test_list_view_preserves_locked_names_csrf_and_toggle_macro:209`) with group column, `b_id_grupo` filter, "Sin grupo" sentinel, one-map load, SQL-injection `intval()` case, and `sid_grupo` create assertions.
- [x] 2.2 GREEN: append optional trailing `$id_grupo=''` to `tarif_opcional::search()`/`count_filtered()` and `catalogo_opcional::search()`; open join/WHERE branch only when non-empty (`''`=all, `'0'`=IS NULL OR =0, numeric=id) (AD-5/AD-6).
- [x] 2.3 GREEN: `extras/VentasOpcionalesListTrait.php` — `load_grupos_cache()` once via `catalogo_opcional_grupo::all_activos()`, `$grupos_opcional` + `nombre_grupo_opcional()` map (no N+1); wire `b_id_grupo` in `ini_filters`/`b_url`/`getListQueryParams`; `new_opcional` persists `id_grupo`.
- [x] 2.4 GREEN: `View/ventas_opcionales.html.twig` — `Grupo` `<th>`/`<td>`, group filter control, create-modal `sid_grupo` select; keep groups link `:149`.
- [x] 2.5 Run affected tests first (R4) then full suite ≥ baseline.

## Phase 3: WU-3 — Detail unification (AD-10, AD-11)

- [x] 3.1 RED: update `tests/TarifOpcionalesControllerContractTest.php` `CONTROLLER_SLUGS` 3→2 (`test_all_surviving_controllers_are_declared_in_catalogo_core:66`); delete `test_moved_controller_persists_through_the_catalogo_core_adapter:158` from `tests/TarifOpcionalPreciosControllerTest.php` (keep adapter tests 85/98/142); drop `test_precios_reads_master_over_price_row:285`, `test_precios_reads_master_and_persists_it:467`, `test_precios_single_tarifa_save_is_atomic:508` from `tests/TarifOpcionalesControllerMasterStateTest.php`; drop `test_precios_selector_uses_hx_get_with_url_push:240` and list-only-ify `test_list_and_precios_views_preserve_locked_names_csrf_and_toggles:301`; drop precios view from `test_catalogo_and_tarifario_views_repoint_to_ventas_opcionales:747`.
- [x] 3.2 GREEN: delete `controller/tarif_opcional_precios.php` + `View/tarif_opcional_precios.html.twig`; absorb parity into `controller/tarif_opcional_edit.php`/`View/tarif_opcional_edit.html.twig` (AD-10).
- [x] 3.3 GREEN: repoint 4 links preserving `codtarifa` — `View/ventas_opcionales.html.twig:371,431`, drop redundant `View/tarif_opcional_edit.html.twig:79`, `plugins/tarifario/View/tarif_articulo_precios.html.twig:184` (AD-10).
- [x] 3.4 GREEN: `Init.php` — idempotent `retireTarifOpcionalPreciosPage()` + `upgrade()` wiring; extend `tests/InitUpgradeTest.php::upgradeRetiresTarifOpcionalesPageIdempotently:118` (AD-11/R8).
- [x] 3.5 Grep audit `page=tarif_opcional_precios` (excluding `openspec/` and historical DB-table refs) + run full suite ≥ baseline.

### WU-3 create-ordering fix (flagged by WU-1+2)

- [x] 3.6 RED: `VentasOpcionalesControllerTest::testPrivateCoreLoadsTheListBeforeDispatchingCreate` + `testCreateModalPerTarifaValuesPersistWhenTarifasAreLoaded`.
- [x] 3.7 GREEN: `Controller/VentasOpcionales.php::privateCore()` loads `init_opcionales_list()` before the create/delete dispatch.

## Phase 4: WU-4 — htmx 4 + Alpine CSP on unified detail (AD-12)

- [x] 4.1 RED: extend `tests/TarifOpcionalesHtmxContractTest.php` (`test_edit_view_uses_colon_events_and_no_v2_names:187`, `test_edit_view_registers_alpine_data_with_nonce_and_init_guard:177`, `test_edit_view_mutations_use_hx_post_and_never_hx_delete:219`) for the absorbed panel + percentage toggle; assert no `|raw`/`bootbox`/v2 names and XSS-safe `x-text` (AD-12).
- [x] 4.2 GREEN: `View/tarif_opcional_edit.html.twig` percentage/group controls inside `Alpine.data()` behind `alpine:init` + `[x-cloak]`, nonce'd classic script, `hx-post` only; CSRF stays per host (`requireCsrf()` edit/tab, `validateFormToken()` list) (AD-12/R7).
- [x] 4.3 Keep `tests/TarifOpcionalEditTarifaSelectorTest.php` green; run full suite.

## Phase 5: Verification (AD-13)

- [x] 5.1 Full suite: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **528 tests / 1826 assertions OK** (25 warnings, 1 skipped), exit 0. Tarifario: **191 / 705 OK** (3 skipped), exit 0.
- [x] 5.2 `ddev exec composer phpstan` → `[OK] No errors`, exit 0 (188 files). Warning: `phpstan.neon` paths = `src`,`tests` only; it does NOT scan `plugins/catalogo_core` (see verify-report W2).
- [x] 5.3 Audits: changed surfaces have no `bootbox`, no `|raw`, no v2 htmx names, no `hx-delete`; `page=tarif_opcional_precios` = 0 hits; S28 bare `fsc.simbolo_divisa()` = 0. Clean.
- [x] 5.4 Core `openspec/` entry absent (no `opcionales-detalle*`, no mention); no Composer dependency added in either plugin.
- [ ] Phase-5 note: authenticated browser/DEV-DB smoke (percentage round-trip, group column/filter, detail selector, create modal) NOT run — no session/fixture. OPEN, non-blocking; annotated in verify-report.md (see also OTS-10 reconciliation W3).

## Acceptance Notes

- Percentage surfaces show `etiqueta_precio_lista()` (never `0,00 €`); writers symmetric in both directions; group map loaded once (no N+1); precios page deleted with no alias, links repointed, `fs_page` retired idempotently; detail stays htmx 4 + Alpine CSP; suite at/above baseline.

## Dependencies / Follow-ups

- WU-1 and WU-2 are coupled: the create modal never calls a nonexistent writer (ship the minimal `set_porcentaje_tarifa` with its UI).
- `plugins/tarifario` link repoint only; dependency direction `tarifario → catalogo_core` unchanged; no new Composer dependency.
- Canonical `opcionales-tarifa-selector` delta (OTS-08/OTS-09, Purpose) reconciled at archive; sibling unarchived dirs `absorber-opcionales-tarifa-en-catalogo-core/` and `opcionales-por-tarifa/` MUST stay untouched.
- Historical `tarif_opcional_precios` DB table, `Services/CatalogLegacyTableMigration.php`, and dead-table tests remain unchanged (guard).
