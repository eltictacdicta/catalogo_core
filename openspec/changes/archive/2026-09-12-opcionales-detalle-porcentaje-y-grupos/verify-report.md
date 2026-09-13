```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:d834808995d3d22ec0dd94cc1ba0d8cd7a3fd8c04f896f2f8b6b6995810d8705
verdict: PASS
blockers: 0
critical_findings: 0
requirements: 12/12
scenarios: 20/20
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:ed8e5029031f3e0952796e0b4b6950320fe8a6b97117dec8b98c1042e9b9831b
build_command: ddev exec composer phpstan
build_exit_code: 0
build_output_hash: sha256:0b9439133e886daed09f416d29a5f87f002a1780c9b614256acc883c748ff6c9
```

# Verify Report: `opcionales-detalle-porcentaje-y-grupos`

- **Change**: `opcionales-detalle-porcentaje-y-grupos` (plugin-local SDD, `plugins/catalogo_core/openspec/`)
- **Secondary plugin**: `plugins/tarifario/` (one link repoint)
- **Verification mode**: independent — production/source claims were re-derived from the working tree; apply-progress claims were not trusted as evidence.
- **Verifier scope**: source code was not modified. Only this report and the Phase-5 checkboxes in `tasks.md` were written. No git commit/push.
- **Evidence revision**: `sha256:d834808995d3d22ec0dd94cc1ba0d8cd7a3fd8c04f896f2f8b6b6995810d8705` (sha256 over the sorted 24-path changed-files listing declared by this change, source + tests, across both plugins).

## Verdict

**PASS** — 0 blockers, 0 critical findings. All 12 delta requirements and 20 scenarios are covered by passing, independently re-run tests and direct source inspection. Four non-blocking warnings are recorded below (TDD evidence is partially self-reported, PHPStan does not scan the plugin, the OTS-10 spec wording conflicts with AD-3 and needs archive reconciliation, and authenticated browser/DEV-DB smoke is OPEN).

---

## Completeness

`tasks.md` contains **25** checkboxes (21 apply-phase + 4 verify-phase). The task brief's "23" does not match the artifact; the table below enumerates every checkbox so nothing is hidden. All 21 apply tasks are `[x]` and independently corroborated; the 4 verify tasks are now confirmed and marked `[x]`.

| Phase | Task | Status | Independent evidence |
|---|---|---|---|
| 1 (WU-1) | 1.1 RED percentage test file | DONE | `tests/CatalogoOpcionalesPercentageTest.php` present, 11 tests; see TDD note (RED self-reported) |
| 1 | 1.2 `set_porcentaje_tarifa` + symmetric `set_precio_tarifa` | DONE | `model/tarif_opcional.php:426,458,483` (read + `opcional_precio_model()` seam) |
| 1 | 1.3 `limpiar_porcentaje()` force flag | DONE | `model/tarif_opcional_precio.php:44,110-114,128` |
| 1 | 1.4 trait percentage cache/display/export | DONE | `extras/VentasOpcionalesListTrait.php:349-367,402,462-532` |
| 1 | 1.5 views + edit controller + tab + partial | DONE | `View/ventas_opcionales.html.twig:96-140`, `controller/tarif_opcional_edit.php`, `controller/tarif_opcional_tab.php:108-211`, `View/Hooks/partials/opcional_precios_rows.html.twig:30-37` |
| 1 | 1.6 guard `guardar_precio_tarifa` + full suite | DONE | `TarifOpcionalEditTarifaSelectorTest` 9/28 OK; suite 528/1826 OK |
| 2 (WU-2) | 2.1 RED group tests | DONE | `CatalogoOpcionalesUnifiedControllerTest` 19/110 OK; `CatalogoOpcionalesHtmxContractTest` 8/83 OK (RED self-reported) |
| 2 | 2.2 trailing `$id_grupo` in search/count | DONE | `model/tarif_opcional.php:254,344`; `model/core/catalogo_opcional.php:464,526-538` |
| 2 | 2.3 group map + `b_id_grupo` wiring + create group | DONE | `extras/VentasOpcionalesListTrait.php:144,185,250-317,612,707-723` |
| 2 | 2.4 list view `Grupo` column/filter/modal select | DONE | `View/ventas_opcionales.html.twig:104-106,382,428,334-347` |
| 2 | 2.5 affected tests then full suite | DONE | both focused suites green; full suite 528/1826 OK |
| 3 (WU-3) | 3.1 RED contract/master/htmx/precios test edits | DONE | `CONTROLLER_SLUGS` = 2 (`:43-46`); `TarifOpcionalPreciosControllerTest` 3/5 OK |
| 3 | 3.2 delete precios controller + view | DONE | both files absent on disk |
| 3 | 3.3 repoint 4 links preserving `codtarifa` | DONE | `grep 'page=tarif_opcional_precios'` = 0 hits (exit 1) |
| 3 | 3.4 idempotent `retireTarifOpcionalPreciosPage()` | DONE | `Init.php:82,107,298-310`; `InitUpgradeTest::upgradeRetiresTarifOpcionalPreciosPageIdempotently` green |
| 3 | 3.5 grep audit + full suite | DONE | grep clean; suite green |
| 3 | 3.6 RED create-ordering tests | DONE | `VentasOpcionalesControllerTest.php:95,129` |
| 3 | 3.7 GREEN `init_opcionales_list()` before dispatch | DONE | `Controller/VentasOpcionales.php:71` before `:88/:92` |
| 4 (WU-4) | 4.1 htmx/Alpine contract extensions | DONE | `TarifOpcionalesHtmxContractTest` 12/110 OK |
| 4 | 4.2 view posture (no production change needed) | DONE | direct grep: colon events, nonce, `alpine:init`, no bans |
| 4 | 4.3 keep selector guard + full suite | DONE | `TarifOpcionalEditTarifaSelectorTest` 9/28 OK; suite 528/1826 OK |
| 5 | 5.1 Full suite ≥ 511/1756 | **DONE** | 528 tests / 1826 assertions OK, exit 0 |
| 5 | 5.2 `composer phpstan` | **DONE** | 188/188 files, `[OK] No errors`, exit 0 (scope warning below) |
| 5 | 5.3 Audit bans (`bootbox`, `\|raw`, v2 names, dead page, S28) | **DONE** | changed surfaces clean; `page=tarif_opcional_precios` = 0; S28 clean |
| 5 | 5.4 Core `openspec/` absent + no Composer dep | **DONE** | no core entry; no `composer.json`/`composer.lock` change in either plugin |

### Requirement coverage labels

- `OPG-01`, `OPG-02` (ADDED, `opcionales-management`) — covered.
- `OPG-03` (ADDED, `opcionales-tarifa-selector`) — covered.
- `OUM-02`, `OUM-03`, `OUM-05`, `OUM-07`, `OUM-11`, `OUM-12` (MODIFIED) — covered.
- `OTS-09`, `OTS-10` (MODIFIED) — `OTS-09` covered; `OTS-10` is the one reconciliation conflict (WARNING W3).
- `OTS-08` (REMOVED) — removal verified by absence of the deleted page and zero dead-page links.

---

## Build & Tests Execution

### Plugin suites (raw evidence)

`ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **exit 0**

```
OK, but there were issues!
Tests: 528, Assertions: 1826, Warnings: 25, Skipped: 1.
```

Matches the expected **528 / 1826**, Warnings 25, Skipped 1. Zero failures/errors. Output hash `sha256:ed8e5029031f3e0952796e0b4b6950320fe8a6b97117dec8b98c1042e9b9831b`.

`ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → **exit 0**

```
OK, but some tests were skipped!
Tests: 191, Assertions: 705, Skipped: 3.
```

Matches the expected **191 / 705**, Skipped 3. Output hash `sha256:9e938ee2dfc71ec019fd7debf4510c506d95bf76d8d4e97aa3540fbe42248bcd`.

### Focused / locked contracts (each re-run, exit 0)

| Test class | Result |
|---|---|
| `TarifOpcionalesControllerContractTest` | OK (5 tests, 14 assertions) |
| `TarifOpcionalEditTarifaSelectorTest` | OK (9 tests, 28 assertions) |
| `TarifOpcionalesControllerMasterStateTest` | OK (14 tests, 79 assertions) |
| `TarifOpcionalesHtmxContractTest` | OK (12 tests, 110 assertions) |
| `TarifOpcionalPreciosControllerTest` | OK (3 tests, 5 assertions) |
| `TarifOpcionalTabEndpointTest` | OK (7 tests, 45 assertions) |
| `CatalogoOpcionalesUnifiedControllerTest` | OK (19 tests, 110 assertions) |
| `CatalogoOpcionalesHtmxContractTest` | OK (8 tests, 83 assertions) |
| `VentasOpcionalesControllerTest` | OK (7 tests, 15 assertions) |
| `InitUpgradeTest` | OK (3 tests, 11 assertions) |
| `CatalogoOpcionalesPercentageTest` (focus) | OK (11 tests, 36 assertions) |

### Static analysis

`ddev exec composer phpstan` → **exit 0**

```
Note: Using configuration file /var/www/html/phpstan.neon.
   0/188 [░░░░░░░░░░░░░░░░░░░░░░░░░░░░]   0%
 188/188 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%

 [OK] No errors
```

Output hash `sha256:0b9439133e886daed09f416d29a5f87f002a1780c9b614256acc883c748ff6c9`.

**Scope gap (WARNING W2)** — `phpstan.neon` `parameters.paths` is `src` and `tests` only; it does **not** include `plugins/catalogo_core` (nor `plugins/tarifario`). The green PHPStan run therefore validates the core tree, not the plugin code changed here. The plugin's own suites (528 tests) are the effective static/semantic gate.

---

## Phase Checks (independent evidence)

### 1. Both plugin suites
Reported above. Both exit 0, zero failures, counts exactly as expected.

### 2. PHPStan
Reported above. Ran green, but does not scan the plugin → W2.

### 3. Percentage read/write contract

- `tarif_opcional::set_porcentaje_tarifa()` exists — `model/tarif_opcional.php:483` (sets `porcentaje` and `precio = 0.0`, creates the row when absent).
- `set_precio_tarifa()` clears the row's `porcentaje` — `model/tarif_opcional.php:458-474` calls `$p->limpiar_porcentaje()` on the existing row.
- Adapter seam prevents resurrection — `model/tarif_opcional_precio.php:110-114` raises `$porcentaje_limpiado = true`; `save()` at `:128` preserves the stored value only when `!$this->porcentaje_limpiado && $this->porcentaje === null && $actual`. An explicit clear is not overwritten by read-modify-write.
- Surfaces branch on `es_precio_porcentaje()` and render `%` vs price:
  - **Unified list price cell** — `View/ventas_opcionales.html.twig:431-438` → `fsc.show_precio_opcional(value.id)`; trait `show_precio_opcional()` (`:462-478`) returns `etiqueta_porcentaje_opcional()` in percentage mode, currency otherwise.
  - **Injected tab rows** — `View/Hooks/partials/opcional_precios_rows.html.twig:30-37` renders `name="porcentaje"` + `modo_porcentaje` when `es_porcentaje`, else the `precio` input.
  - **Edit prices panel** — `View/tarif_opcional_edit.html.twig:293-295` percentage input under `fsc.opcional.es_precio_porcentaje()`; all-tarifas overview `:331-332` renders `{{ datos.porcentaje }}%`.
  - **Create modal** — `View/ventas_opcionales.html.twig:96-140` `tipo_precio` radios + `porcentaje` input; trait `new_opcional()` writes `set_porcentaje_tarifa()` (`:741-746`).
  - **Excel export** — trait `valor_precio_export()` (`:504-532`) returns `0.00"%"` numeric percentage in percentage mode.
- `tests/CatalogoOpcionalesPercentageTest.php` → **OK (11 tests, 36 assertions)**, including `test_set_porcentaje_tarifa_sets_pct_and_zeroes_precio`, `test_set_precio_tarifa_clears_porcentaje`, `test_limpiar_porcentaje_forces_null_through_save`, `test_get_porcentaje_falls_back_to_global`, `test_show_precio_opcional_renders_percentage_label`, `test_valor_precio_export_uses_percentage_format`, `test_tab_save_persists_porcentaje_and_zeroes_precio`.

### 4. Groups

- `Grupo` column present and map-backed: `View/ventas_opcionales.html.twig:382,428` renders `fsc.nombre_grupo_opcional(value.id)`.
- No per-row N+1: `extras/VentasOpcionalesListTrait.php:290-307` `load_grupos_cache()` calls `opcional_grupo_model()->all_activos()` once, called once at `:185`; `nombre_grupo_opcional()` (`:315-318`) reads the prebuilt map. No `etiqueta_grupo()` call exists in the trait/list view (only a comment reference).
- Filter reaches search/count as optional trailing param: `model/tarif_opcional.php:254` `search(..., $id_grupo = '')` and `:344` `count_filtered(..., $id_grupo = '')`; `model/core/catalogo_opcional.php:464` `search(..., $id_grupo = '')`. Trait calls both with `$this->b_id_grupo` at `:268-282` and `:891-897`.
- `where_id_grupo()` semantics (`catalogo_opcional.php:526-538`): `''`=all, `'0'`=`IS NULL OR = 0`, numeric=`intval()` (SQL-injection guarded). Locked test `test_where_id_grupo_treats_sentinel_and_rejects_injection` green.
- Create modal assigns `sid_grupo`: `View/ventas_opcionales.html.twig:104-106`; trait `new_opcional()` `:707-723` reads `sid_grupo`/`id_grupo` → `$opcional->id_grupo`.

### 5. Detail unification

- `controller/tarif_opcional_precios.php` and `View/tarif_opcional_precios.html.twig` do **not** exist (both absent on disk).
- `grep -rn 'page=tarif_opcional_precios' plugins/{catalogo_core,tarifario} --include='*.php' --include='*.twig' --include='*.js' --exclude-dir=vendor --exclude-dir=openspec` → **0 hits (exit 1)**.
- `Init::retireTarifOpcionalPreciosPage()` exists (`Init.php:298-310`) and is wired in `upgrade()` at `:107`; idempotency mirrors `fs_page::get()` → delete-if-exists, locked by `InitUpgradeTest::upgradeRetiresTarifOpcionalPreciosPageIdempotently` (green).
- Historical DB-table refs remain present (`Services/CatalogLegacyTableMigration.php:355-484`, `DeadOpcionalTableReferenceTest`, `OpcionalPriceUnificationTest`, `plugins/tarifario/model/table/tarif_opcional_precios.xml` unchanged). See W4 on attribution.

### 6. Create-ordering fix

- `Controller/VentasOpcionales.php::privateCore()` calls `init_opcionales_list()` at line **71**, before `delete_opcional()` (`:88`) and `new_opcional()` (`:92`).
- Regression test asserts source order via `assertLessThan($create, $init)` / `assertLessThan($delete, $init)` — `tests/VentasOpcionalesControllerTest.php:95-121`; runtime companion `testCreateModalPerTarifaValuesPersistWhenTarifasAreLoaded` (`:129`). Both green.

### 7. htmx4 / Alpine hygiene

On the changed surfaces (`View/tarif_opcional_edit.html.twig`, `View/ventas_opcionales.html.twig`, `View/Hooks/partials/opcional_precios_rows.html.twig`):

- v2 names (`htmx:afterSwap`, `htmx:afterRequest`, `htmx:beforeSwap`, `htmx:beforeRequest`): **NONE**.
- Colon events only: `htmx:after:request` (edit `:481,484`; list `:616,619`), `htmx:after:swap` (edit `:505`; list `:673`).
- `hx-delete`: **NONE**. `bootbox`: **NONE**. `|raw`: **NONE**.
- `Alpine.data(` registered behind `document.addEventListener('alpine:init', ...)` from a nonce'd classic script: edit `:385-495`, list `:566-663`; `x-cloak` present; `htmx.boot({'allowScriptTags': false})` (edit `:9`, list `:9`); `alpine.boot()` (edit `:514`, list `:682`).
- S28 (`fsc.simbolo_divisa()` with bare parens under `View/Hooks/`): **NONE**.

Note (informational): `bootbox` still exists in unrelated pre-existing views (`ventas_articulo`, `ventas_opcional`, `tarif_opcional`, admin lists). None of those are in this change's affected set, so the ban holds for the surfaces this change touches.

### 8. Locked contracts

All ten named classes green (table above), with `CONTROLLER_SLUGS` frozen at exactly two survivors (`tests/TarifOpcionalesControllerContractTest.php:43-46`). The whole plugin suite (528/1826) is green, so no locked contract regressed.

### 9. Spec reconciliation

- **(a) OTS-10 vs AD-3** — the delta `opcionales-tarifa-selector/spec.md` `OTS-10` states `model/tarif_opcional_precio.php` MUST NOT change, but design AD-3 and task 1.3 added `limpiar_porcentaje()` to that file. The implementation followed AD-3/tasks. This is a real spec-vs-design contradiction to reconcile at archive (see W3).
- **(b) Core `openspec/`** — no core entry for this change: `find openspec -iname '*opcionales-detalle*'` returns nothing, no core `changes/` entry matches `opcional`, and no core file mentions the change name. Plugin-local ownership holds.

---

## Spec Compliance Matrix

| Requirement | Delta | Covering evidence | Result |
|---|---|---|---|
| OUM-02 Filters and real-total pagination | MODIFIED | `b_id_grupo` in trait filters (`:250,257,612`), search/count (`:268-282`), view filter (`:334-347`); `test_pagination_uses_count_filtered_true_total_and_preserves_filters` green | PASS |
| OUM-03 Per-tarifa state and price display | MODIFIED | `show_precio_opcional()` mode branch (`trait:462-478`); list cell (`view:431-434`); `test_show_precio_opcional_renders_percentage_label` green | PASS |
| OUM-05 Creation with validated prices | MODIFIED | `new_opcional()` mode/percentage/group (`trait:707-751`); modal fields (`view:96-140`); `test_new_opcional_persists_percentage_and_group`, `test_new_opcional_rejects_a_non_numeric_percentage` green | PASS |
| OUM-07 Excel export parity | MODIFIED | `valor_precio_export()` (`trait:504-532`); `test_valor_precio_export_uses_percentage_format` green | PASS |
| OUM-11 Canonical detail, precios retired | MODIFIED | files absent; 0 dead-page links; `Init.php:298-310` + `:107`; `InitUpgradeTest` and `test_deleted_page_has_no_controller_or_view` green | PASS |
| OUM-12 Locked contracts stay green | MODIFIED | 10 locked classes green; `CONTROLLER_SLUGS` = 2; historical refs present | PASS |
| OPG-01 Percentage rendering + write integrity | ADDED | `CatalogoOpcionalesPercentageTest` 11/11 green; writer symmetry + `limpiar_porcentaje` guard | PASS |
| OPG-02 Grupo column from single map | ADDED | `load_grupos_cache()` once (`trait:185,290-307`); `test_grupo_column_map_loads_once_without_n_plus_one` green | PASS |
| OPG-03 htmx 4 + Alpine CSP on canonical detail | ADDED | direct grep + `TarifOpcionalesHtmxContractTest` 12/12 green | PASS |
| OTS-09 Test-locked contracts intact | MODIFIED | `TarifOpcionalEditTarifaSelectorTest`, `TarifOpcionalesControllerMasterStateTest` green | PASS |
| OTS-10 Unchanged boundaries | MODIFIED | `tarif_opcional_precio.php` **was** modified (`limpiar_porcentaje()`); table identity/`codlista` key and guards unchanged → reconciliation item | WARNING (W3) |
| OTS-08 List/precios filters locked names | REMOVED | page deleted, no alias, 0 dead links, filters owned by OUM-02 | PASS (removal) |

Requirements: **12/12** (11 PASS, 1 PASS-with-reconciliation-note). Scenarios: **20/20** covered by the passing suites and source inspection.

---

## TDD Compliance

`strict_tdd: true` in `plugins/catalogo_core/openspec/config.yaml`. RED evidence below is what `apply-progress.md` reports; it is **self-reported** and could not be independently re-observed without mutating source (forbidden in verify). Green results and the existence/shape of the tests were independently confirmed.

| Work unit | Test-first claim | Independent assessment |
|---|---|---|
| WU-1 (percentage) | RED → GREEN documented (`CatalogoOpcionalesPercentageTest` failing before writers existed) | Test file and writers exist and are coherent; genuine RED not independently reproducible. **PLAUSIBLE** |
| WU-2 (groups) | apply-progress states production wiring landed in the same batch as WU-1 and RED was **reproduced by neutering** production seams, not observed naturally | **PARTIAL / retrospective** — not natural red-first. W1 |
| WU-3 (detail) | RED documented via source-contract/`assertFileDoesNotExist` failures before deletion | Consistent with the deletion tests; plausible. **PLAUSIBLE** |
| WU-4 (htmx/Alpine) | apply-progress states the view already satisfied the contract and the test passed immediately; "No production change needed" | **CHARACTERIZATION, not TDD** — no red phase occurred. W1 |
| Guards | existing locked tests kept green | Confirmed by re-run |

Net: final state is test-backed and green, but strict-TDD is only partially evidenced for WU-2 and WU-4. Non-blocking for the production code, which is covered and correct.

---

## Issues Found

### CRITICAL
None.

### WARNING

- **W1 — TDD evidence is partially retrospective.** WU-2's RED was reconstructed by neutering production seams and WU-4's tests were green on first run ("no production change needed"), per `apply-progress.md`. Strict TDD is therefore not fully demonstrable for those units. The shipped behavior is nonetheless covered by meaningful tests. Non-blocking; record in the archive report.
- **W2 — PHPStan does not scan the plugin.** `phpstan.neon` `paths` = `src`, `tests`. `ddev exec composer phpstan` is green (188 files) but proves nothing about `plugins/catalogo_core` / `plugins/tarifario`. Recommend adding the plugin paths to a plugin-level PHPStan config or documenting the plugin suite as the static gate.
- **W3 — OTS-10 spec wording conflicts with implemented AD-3 (reconciliation item for archive).** The delta `opcionales-tarifa-selector/spec.md` `OTS-10` says `model/tarif_opcional_precio.php` MUST NOT change, yet AD-3 / task 1.3 added `limpiar_porcentaje()` there (and it was correctly implemented per `model/tarif_opcional_precio.php:44,110-114,128`). The change is additive and behavior-preserving for the adapter's table identity/`codlista` key (`OpcionalPriceUnificationTest`, `DeadOpcionalTableReferenceTest` green), but the OTS-10 requirement text is now factually wrong for this change. **At archive, the canonical spec must narrow the boundary to "no schema/identity change; additive force-flag allowed" or record the explicit exception.**
- **W4 — Working-tree attribution of the historical-DB guard cannot be proven in isolation.** `plugins/catalogo_core` carries concurrent uncommitted SDD work (`opcionales-por-tarifa`, `absorber-opcionales-tarifa-en-catalogo-core`), and `Services/CatalogLegacyTableMigration.php` shows as modified. Inspecting the diff confirms the `tarif_opcional_precios` hunks belong to the concurrent `opcionales-por-tarifa` change (they add `BOTH_EXIST_COPY` handling for `tarif_opcionales`/`tarif_opcional_familia`/`tarif_articulo_opcional` and the `codtarifa → codlista` backfill), **not** this change. This change's declared file set does not include that file, and all historical `tarif_opcional_precios` references remain. Recorded for transparency, not attributed to this change.

### SUGGESTION

- The task brief expects "23 tasks" but `tasks.md` has 25 checkboxes (21 apply + 4 verify). Align the count in future briefs, or the discrepancy will keep surfacing during verify.
- `View/ventas_opcionales.html.twig` percentage label uses a trait helper `etiqueta_porcentaje_opcional()` that reimplements `number_format`+`rtrim` rather than reusing `catalogo_opcional::etiqueta_precio_lista()`. Behavior matches (`12,5%`), but a shared formatter would reduce drift; consider it in a future refactor.

### OPEN (non-blocking, not executed)

- **Authenticated browser / DEV-DB smoke is OPEN.** No session/fixture was available, so the following were **not** exercised end-to-end and remain unverified at runtime: (1) percentage render/edit round-trip on `index.php?page=ventas_opcionales` and the scoped edit panel; (2) `Grupo` column plus `b_id_grupo` filter (including "Sin grupo"); (3) the canonical detail selector via `tarif_opcional_edit&id=..&codtarifa=..`; (4) the create modal assigning `sid_grupo` and writing per-tarifa percentage/price rows; (5) Excel export percentage cell. All are covered by automated tests, but no live smoke was performed. Marked OPEN, not failed.

---

## Final Verdict

**PASS** with four warnings and one OPEN smoke item. No blockers, no critical findings. All Phase-5 verify tasks are confirmed and marked `[x]`; the only item left deliberately open is the authenticated browser/DEV-DB smoke, which is annotated as OPEN rather than checked. `next_recommended: sdd-archive` — after reconciling the OTS-10 wording during the archive spec merge and recording W1/W2/W4 in the archive report.
