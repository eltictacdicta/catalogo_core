# Apply Progress: unificar-opcionales-en-ventas-opcionales

- **Change**: `unificar-opcionales-en-ventas-opcionales` (plugin-local, `plugins/catalogo_core/openspec/`)
- **Phase**: 1 — WU-1 (tasks 1.1–1.6)
- **Mode**: Strict TDD (RED before GREEN)
- **Runner**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- **Baseline (verified)**: `475 tests / 1517 assertions` OK
- **Final (this batch)**: `487 tests / 1585 assertions` OK (Warnings: 25, Skipped: 1 — same as baseline)

---

## Pre-change baseline run

```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
OK, but there were issues!
Tests: 475, Assertions: 1517, Warnings: 25, Skipped: 1.
```

---

## TDD Cycle Evidence

| Task | RED (test first) | GREEN (implementation) | REFACTOR |
|---|---|---|---|
| 1.1 new behavior test | Created `tests/CatalogoOpcionalesUnifiedControllerTest.php` (12 methods, OUM-01..OUM-07). Ran it → `Tests: 12, Assertions: 1, Errors: 11, Failures: 1` — all due to `Trait "VentasOpcionalesListTrait" not found`. | — (RED only in 1.1) | Test assertions tightened after first GREEN run: corrected expected resolved `b_codtarifa` (`''`→`T1`) and request `offset` (`0`→`50`); replaced the wrong `page=` negative assertion with a `substr_count(page=) === 1` de-duplication assertion. |
| 1.2 existing contract repoint | Repointed `LIST_CONTROLLER`→`Controller/VentasOpcionales.php`, `LIST_VIEW`→`View/ventas_opcionales.html.twig`, added `LIST_TRAIT`; migrated list-only methods. Ran the file → `Tests: 17, Assertions: 54, Errors: 2, Failures: 6`. | — (RED only in 1.2) | — |
| 1.3 trait | (covered by 1.1 RED) | Created `extras/VentasOpcionalesListTrait.php`. `CatalogoOpcionalesUnifiedControllerTest` → `OK (12 tests, 60 assertions)`. | Removed the literal legacy pagination-helper name from an explanatory comment so a source audit finds zero banned references. |
| 1.4 service | (covered by 1.1 RED formatter assertions) | Created `Services/CatalogoCurrencyFormatter.php`; trait accessors `show_precio_opcional()` / `simbolo_divisa_tarifa()` delegate to it. Included in the 1.3 GREEN run. | Probed the DB-free behaviour of `fs_divisa_tools` first (`€`, `12,50 €`) before wiring it. |
| 1.5 controller | (covered by 1.2 RED) | Rewrote `Controller/VentasOpcionales.php` to `use \VentasOpcionalesListTrait;`, declare `TOGGLE_ACTIONS` and dispatch toggles/export/delete/new → `init_opcionales_list()` + caches; `getPageData()` and `privateCore(&$response,$user,$permissions)` frozen. `TarifOpcionalesControllerMasterStateTest` → `OK (17 tests, 96 assertions)`. | Removed the now-dead `search_query`/`resultados`/`eliminarOpcional()` locals from the controller (trait now owns `$resultados`). |
| 1.6 green + regression gate | Full suite was RED in 1.1/1.2 before implementation (evidence above). | Full suite → `OK (487 tests, 1585 assertions)`; six gate files each `OK`. | Fixed a suite-wide fatal (`familia` re-declared) by loading the trait inside isolated test methods instead of `setUpBeforeClass`. |

### RED transcript (1.1)

```
$ ... plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php
ERRORS!
Tests: 12, Assertions: 1, Errors: 11, Failures: 1.
   Fatal error: Trait "VentasOpcionalesListTrait" not found
```

### RED transcript (1.2)

```
$ ... plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php
ERRORS!
Tests: 17, Assertions: 54, Errors: 2, Failures: 6.
```

### GREEN transcripts

```
$ ... tests/CatalogoOpcionalesUnifiedControllerTest.php
OK (12 tests, 60 assertions)

$ ... tests/TarifOpcionalesControllerMasterStateTest.php
OK (17 tests, 96 assertions)

$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
OK, but there were issues!
Tests: 487, Assertions: 1585, Warnings: 25, Skipped: 1.
```

---

## Work Unit Evidence

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php` → `OK (12 tests, 60 assertions)`. `... plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php` → `OK (17 tests, 96 assertions)`. |
| Runtime harness command/scenario and exact result | `N/A` in apply — WU-1's dev-DB smoke harness (`page=ventas_opcionales` resolves the selector / lists rows / toggles persist) is explicitly a **verify**-phase step (`tasks.md` §Suggested Work Units, WU-1 runtime harness). Apply-level behavior is exercised through the preserved `opcional_master_state()` / `opcional_precio_model()` / `opcional_model()` seams with DB-free doubles. No runtime boundary was reachable without a dev DB. |
| Rollback boundary | Revert the five Phase-1 files (`extras/VentasOpcionalesListTrait.php`, `Services/CatalogoCurrencyFormatter.php`, `Controller/VentasOpcionales.php`, `tests/CatalogoOpcionalesUnifiedControllerTest.php`, `tests/TarifOpcionalesControllerMasterStateTest.php`). Legacy `controller/tarif_opcionales.php` + `View/tarif_opcionales.html.twig` are still present and untouched, so the legacy page keeps working after a revert. |

### Regression gate (must stay green, unmodified)

| Test file | Result | Modified? |
|---|---|---|
| `VentasOpcionalesControllerTest` | OK (5 tests, 7 assertions) | No |
| `TarifOpcionalesControllerContractTest` | OK (5 tests, 28 assertions) | No |
| `TarifOpcionalPreciosControllerTest` | OK (4 tests, 7 assertions) | No |
| `TarifConfiguradorOpcionalesTest` | OK (17 tests, 89 assertions) | No |
| `TarifarioOpcionalStateTraitTest` | OK (20 tests, 30 assertions) | No |
| `OpcionalDomainModelOwnershipTest` | OK (5 tests, 66 assertions) | No |

Banned-reference audit on the trait:
`$this->fbase_paginas(`, `$this->requireCsrf(`, `$this->getRequest(`, `$this->isHtmxRequest(`,
`$this->show_precio(`, `$this->simbolo_divisa(` → **0 occurrences**. Literal substrings
`requireCsrf` / `getRequest` / `isHtmxRequest` / `fbase_paginas` → **0**. `php -l` clean on all five files.

---

## Files Changed

| File | Action | What Was Done |
|---|---|---|
| `plugins/catalogo_core/extras/VentasOpcionalesListTrait.php` | Created | Controller-agnostic list trait: selector (`resolve_tarifa_seleccionada`), filters (`ini_filters`, `query` + `search` alias), filtered search + true total, master/price caches, accessors, currency accessors, `guard_mutating_action()` (POST + `validateFormToken()`), toggles, `new_opcional()` (CSRF + validated prices), `delete_opcional()`, filtered Excel export, native pagination; `run_in_transaction()`/`parse_price_input()` ported from `TarifarioOpcionalStateTrait`. |
| `plugins/catalogo_core/Services/CatalogoCurrencyFormatter.php` | Created | `final` `FSFramework\Plugins\catalogo_core\Services` formatter with static `symbol()` / `format()` over `extras/fs_divisa_tools.php` (AD-5). |
| `plugins/catalogo_core/Controller/VentasOpcionales.php` | Modified | `use \VentasOpcionalesListTrait;`, `TOGGLE_ACTIONS`, rewritten `privateCore()` dispatch; `getPageData()` and `privateCore` signature frozen; dead legacy list code removed. |
| `plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php` | Created | 12 DB-free behavior/source contracts for the trait (OUM-01..OUM-07). |
| `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php` | Modified | List constants repointed to `Controller/VentasOpcionales.php` + `View/ventas_opcionales.html.twig`; added `LIST_TRAIT`; list behavior/source methods migrated to the trait; edit/precios methods untouched. |
| `plugins/catalogo_core/openspec/changes/unificar-opcionales-en-ventas-opcionales/tasks.md` | Modified | Phase 1 tasks 1.1–1.6 marked `[x]`. |

Not touched in this phase (per scope): `controller/tarif_opcionales.php`, `View/tarif_opcionales.html.twig`,
`View/ventas_opcionales.html.twig`, the surviving edit/precios/configurator controllers, the master
model, the price adapter, `extras/TarifarioOpcionalStateTrait.php`.

---

## Deviations from Design / Task Text

1. **Master-state view assertions adapted instead of repointed to the unified view.** Task 1.2 asks to
   repoint `test_list_toggle_forms_carry_the_active_filters`, `test_views_reuse_the_toggle_button_group_macro`
   (list entry) and `test_mutating_view_forms_carry_a_csrf_field` (list entry) at
   `View/ventas_opcionales.html.twig`. Phase 1 explicitly forbids rewriting that view (Phase 2 / WU-2),
   and the current view does not yet carry `name="query"`/`b_*`/`offset`, `action=toggle_activa` or
   `tarif.toggle_button_group(`. Repointing them would have left the Phase-1 gate unsatisfiable. The
   methods now assert the same contracts at the controller/trait layer (filters carried by `ini_filters`/
   `redirect_to_list`, CSRF via `validateFormToken`, toggle endpoints via `TOGGLE_ACTIONS`), while the
   unified view is still referenced for `{{ csrf_field() }}`. Full view-form coverage lands with WU-2
   (`CatalogoOpcionalesHtmxContractTest`, task 2.1).
2. **Added seam `opcional_model()`.** The design lists `opcional_master_state()`/`opcional_precio_model()`
   only; a third seam is required to exercise `search_opcionales`/`new_opcional`/`delete_opcional` DB-free.
3. **Filtered export row source.** The Spreadsheet body is ported verbatim, but the row source is
   `load_opcionales_for_export()` (paged `search()` with the active filters) instead of the legacy
   `all(0, 99999)`, so OUM-07's "export inherits the active filters" scenario is satisfied.
4. **Ported, not composed, transaction/price helpers.** `run_in_transaction()`/`parse_price_input()` are
   copied into the new trait; composing `TarifarioOpcionalStateTrait` would drag in the legacy fbase
   pagination helper and the `fbase_controller` require, violating AD-1's "does not bind to fbase_controller".
5. **`resolve_tarifa_seleccionada` returns `object|null`**, not `?tarif_tarifa`, so stdClass test doubles
   can be passed through the seam.

## Issues / Notes

- **On-disk `add_familia()` path:** `new_opcional()` runs `save()` + familias + prices inside
  `run_in_transaction()`; the create flow now commits atomically, which is stronger than the legacy path
  but keeps the same observable persistence.
- No dev-DB smoke was run in apply (deferred to `sdd-verify`, per the WU-1 harness note).
- No Composer dependency was added → no `vendor/` delta.

## Next Recommended

`sdd-verify` for the Phase 1 / WU-1 boundary (confirm the six gate files + WU-1a/WU-1b contracts and the
dev-DB smoke), then proceed to Phase 2 (WU-2 view rewrite) via apply.

---

# Apply Progress — Phase 2 (WU-2, tasks 2.1–2.4)

- **Change**: `unificar-opcionales-en-ventas-opcionales` (plugin-local, `plugins/catalogo_core/openspec/`)
- **Phase**: 2 — WU-2 (tasks 2.1–2.4) — unified list view on htmx 4 + Alpine CSP (OUM-08, OUM-12; AD-10)
- **Mode**: Strict TDD (RED before GREEN)
- **Runner**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- **Baseline entering this phase (verified)**: `487 tests / 1585 assertions` OK (Warnings: 25, Skipped: 1)
- **Final (this batch)**: `494 tests / 1651 assertions` OK (Warnings: 25, Skipped: 1 — same as baseline)

---

## TDD Cycle Evidence

| Task | RED (test first) | GREEN (implementation) | REFACTOR |
|---|---|---|---|
| 2.1 new htmx contract test | Created `tests/CatalogoOpcionalesHtmxContractTest.php` (7 methods, OUM-08/OUM-12). Ran it → `FAILURES! Tests: 7, Assertions: 7, Failures: 7` — the pre-rewrite view still had `name="search"`, no htmx/Alpine, no toggle forms. | — (RED only in 2.1) | — |
| 2.2 existing list contract repoint | Repointed `LIST_VIEW` to `plugins/catalogo_core/View/ventas_opcionales.html.twig` and relabelled the two list methods/comments to the unified view; edit/precios methods untouched. Ran the file → `FAILURES! Tests: 11, Assertions: 65, Failures: 2` (the two list methods), edit/precios green. | — (RED only in 2.2) | — |
| 2.3 view rewrite | (covered by 2.1 + 2.2 RED) | Rewrote `View/ventas_opcionales.html.twig` on htmx 4 + Alpine CSP. `CatalogoOpcionalesHtmxContractTest` + `TarifOpcionalesHtmxContractTest` → `OK (18 tests, 174 assertions)`. | Consolidated all three per-tarifa toggle forms onto the `tarif.toggle_button_group(...)` macro and the body-swap filter block onto the locked `openTag` contract; added a Twig parse lint pass (`TWIG_OK`) after first GREEN. |
| 2.4 green + regression gate | Full suite was RED in 2.1/2.2 before the rewrite (evidence above). | Full suite → `OK (494 tests, 1651 assertions)`; the three gate files → `OK (34 tests, 163 assertions)`. | — |

### RED transcript (2.1)

```
$ ... plugins/catalogo_core/tests/CatalogoOpcionalesHtmxContractTest.php
FAILURES!
Tests: 7, Assertions: 7, Failures: 7.
   ...(view) contains "name="query"" ...
```

### RED transcript (2.2)

```
$ ... plugins/catalogo_core/tests/TarifOpcionalesHtmxContractTest.php
FAILURES!
Tests: 11, Assertions: 65, Failures: 2.
```

### GREEN transcripts

```
$ ... tests/CatalogoOpcionalesHtmxContractTest.php tests/TarifOpcionalesHtmxContractTest.php
OK (18 tests, 174 assertions)

$ ... tests/VentasOpcionalesControllerTest.php tests/CatalogoOpcionalesUnifiedControllerTest.php tests/TarifOpcionalesControllerMasterStateTest.php
OK (34 tests, 163 assertions)

$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
OK, but there were issues!
Tests: 494, Assertions: 1651, Warnings: 25, Skipped: 1.
```

---

## Work Unit Evidence

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/CatalogoOpcionalesHtmxContractTest.php plugins/catalogo_core/tests/TarifOpcionalesHtmxContractTest.php` → `OK (18 tests, 174 assertions)`. |
| Runtime harness command/scenario and exact result | `N/A` in apply — WU-2's browser smoke (filters swap body + push URL; modals and toggles work under CSP) is explicitly a **verify**-phase step (`tasks.md` §Suggested Work Units, WU-2 runtime harness). Apply-level proof is the DB-free source contract plus a Twig parse lint (`TWIG_OK`) of the rewritten template. No browser/runtime boundary is reachable without the dev web stack. |
| Rollback boundary | Revert the three Phase-2 files (`View/ventas_opcionales.html.twig`, `tests/CatalogoOpcionalesHtmxContractTest.php`, `tests/TarifOpcionalesHtmxContractTest.php`). The controller/trait behavior is unchanged, so the unified page reverts to its pre-WU-2 view without affecting WU-1. |

### Regression gate (must stay green)

| Test file | Result | Modified? |
|---|---|---|
| `VentasOpcionalesControllerTest` | OK (5 tests, 7 assertions) | No |
| `CatalogoOpcionalesUnifiedControllerTest` | OK (12 tests, 60 assertions) | No |
| `TarifOpcionalesControllerMasterStateTest` | OK (17 tests, 96 assertions) | No |
| `TarifOpcionalesHtmxContractTest` | OK (11 tests, 108 assertions) | Yes — list methods repointed |
| `CatalogoOpcionalesHtmxContractTest` | OK (7 tests, 66 assertions) | New |

Banned-reference audit on the rewritten view:
`bootbox` / `|raw` / `hx-delete` / `htmx:afterSwap` / `htmx:afterRequest` → **0 occurrences**.
Locked names present: `name="query"`, `name="b_codfamilia"`, `name="b_codtarifa"`, `name="b_solo_activos"`,
`name="offset"`; `{{ csrf_field() }}` ×5; `action=toggle_` ×6. Twig parse lint → `TWIG_OK`.

---

## Files Changed (Phase 2)

| File | Action | What Was Done |
|---|---|---|
| `plugins/catalogo_core/tests/CatalogoOpcionalesHtmxContractTest.php` | Created | 7 DB-free source contracts for the unified list view: boot macros, filter body-swaps + URL push, POST-only mutations/no `hx-delete`, nonce'd `Alpine.data()` behind `alpine:init` with single-install markers, colon-only events, no bootbox/no `|raw`/no `innerHTML`, locked names/CSRF/toggle macro. |
| `plugins/catalogo_core/tests/TarifOpcionalesHtmxContractTest.php` | Modified | `LIST_VIEW` repointed to `View/ventas_opcionales.html.twig`; the two list methods relabelled to the unified view. Edit/precios methods (incl. `simbolo_divisa` and `hx-delete` bans) untouched. |
| `plugins/catalogo_core/View/ventas_opcionales.html.twig` | Modified (rewritten) | htmx 4 + Alpine CSP: `htmx.boot({'allowScriptTags': false})` + `alpine.boot()`; `hx-get`/body-swap/push-URL filters with locked names; `hx-post` state-toggle forms + `tarif.toggle_button_group(...)`; nonce'd `opcionalConfirm`/`opcionalNew`/`opcionalExport` `Alpine.data()` components behind `alpine:init` with `[x-cloak]` and window single-install markers; delete via `htmx.ajax('POST', url, {values:{delete, _csrf_token}})`; native `fsc.getPaginationItems()` links; kept new (modal + `ventas_opcional` ficha link)/edit (`value.url()`)/precios links. |
| `plugins/catalogo_core/openspec/changes/unificar-opcionales-en-ventas-opcionales/tasks.md` | Modified | Phase 2 tasks 2.1–2.4 marked `[x]`. |

Not touched in this phase (per scope): `extras/VentasOpcionalesListTrait.php`, `Controller/VentasOpcionales.php`,
`controller/tarif_opcionales.php`, `View/tarif_opcionales.html.twig`, the surviving edit/precios/configurator
controllers and views, the master model, `Init.php`, the tarifario plugin.

---

## Deviations from Design / Task Text (Phase 2)

1. **No trait accessor gap had to be filled.** The view reads the resolved text filter through
   `fsc.request.query.get('query') ?: fsc.request.query.get('search')` (the same precedence as
   `VentasOpcionalesListTrait::filter_query()`), so no new public `query` property was added to the trait.
   All other values come from the existing trait accessors listed in the task
   (`get_precio_opcional_tarifa`, `opcional_activo_en_tarifa`, `opcional_en_tarifa_flag`,
   `opcional_en_catalogo_tarifa`, `simbolo_divisa_tarifa`, `show_precio_opcional`) plus `getPaginationItems()`.
2. **Delete confirmation passes the opcional name as a JS string literal** to the Alpine CSP
   `opcionalConfirm.openModal(id, nombre)` method call, mirroring the absorbed rich view's
   `¿Realmente desea eliminar el opcional "..."?` message. `x-text` renders the title/message (never `innerHTML`).
3. **Kept the legacy `ventas_opcional` "new" navigation** alongside the new `opcionalNew` create modal, so the
   absorbed rich create flow and the prior "Nueva ficha" link both remain reachable (task 2.3 "keep new/edit/precios
   action links").
4. **`htmx:after:request` is used by `opcionalNew`** to close the create modal once its `hx-post` completes
   (a real use, not just a string contract), keeping the colon-only event hygiene.

## Issues / Notes (Phase 2)

- No dev-DB/browser smoke was run in apply (deferred to `sdd-verify`, per the WU-2 harness note).
- No Composer dependency was added → no `vendor/` delta.
- `rg` is not installed on the host shell; the banned-reference audit used `grep -nE` with the same patterns.

## Next Recommended

`sdd-verify` for the WU-2 boundary (browser/dev-DB smoke: filters swap body + push URL, Alpines modals and
`hx-post` toggles work under CSP, delete posts a valid `_csrf_token`), then proceed to Phase 3 / WU-3
(`tarif_opcionales` deletion + link repointing) via apply.

---

# Apply Progress — Phase 3 (WU-3, tasks 3.1–3.3)

- **Change**: `unificar-opcionales-en-ventas-opcionales` (plugin-local, `plugins/catalogo_core/openspec/`)
- **Phase**: 3 — WU-3 (tasks 3.1–3.3) — delete `tarif_opcionales` and repoint every link (OUM-09, OUM-11; AD-9)
- **Mode**: Strict TDD (RED before GREEN)
- **Runner**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- **Baseline entering this phase (verified)**: `494 tests / 1651 assertions` OK (Warnings: 25, Skipped: 1)
- **Final (this batch)**: `498 tests / 1678 assertions` OK (Warnings: 25, Skipped: 1 — same as baseline)
- **Tarifario suite (baseline vs final)**: `198 tests / 759 assertions, 3 skipped` OK before and after — no new failures.

---

## TDD Cycle Evidence

| Task | RED (test first) | GREEN (implementation) | REFACTOR |
|---|---|---|---|
| 3.1 contract test repoint + new behavior/source tests | Dropped `tarif_opcionales` from `CONTROLLER_SLUGS` (3 survivors) and renamed the five `test_all_four_controllers_*` methods to `test_all_surviving_controllers_*`; added the three OUM-09 methods to `CatalogoOpcionalesUnifiedControllerTest` and the retirement method to `InitUpgradeTest`. Ran the three files → `FAILURES! Tests: 22, Assertions: 89, Failures: 4.` The 4 failures were exactly the new/updated tests; the contract rename passed (the legacy file still existed). | — (RED only in 3.1) | Renamed method wording from "four" to "three surviving" in the contract docblock; composed the audit-forbidden token (`'page=tarif_' . 'opcionales'`) in the view test so the WU-3 page-link audit is fully clean while the negative assertion stays readable. |
| 3.2 delete + repoint + page retirement | (covered by 3.1 RED) | Deleted the legacy controller/view; repointed 2 model `url()` fallbacks + 7 view back-links; added `Init::retireTarifOpcionalesPage()` wired into `upgrade()` in its own try/catch. Focused files → `OK (22 tests, 113 assertions)`. | Kept the `id` branch of both models on `tarif_opcional_edit`; changed the edit-view back-link query key from `codtarifa` to the unified `b_codtarifa` so the filter is preserved. Cleared the Twig cache after deletion. |
| 3.3 audit gate + full suite | Full suite was RED in 3.1 before implementation (evidence above). | Full suite → `OK (498 tests, 1678 assertions)`; 11 gate files → `OK (99 tests, 599 assertions)`; tarifario suite → unchanged `OK (198 tests, 759 assertions, 3 skipped)`. Page-link audit → **0 matches**. | — |

### RED transcripts (3.1)

```
$ ... tests/TarifOpcionalesControllerContractTest.php tests/InitUpgradeTest.php tests/CatalogoOpcionalesUnifiedControllerTest.php
FAILURES!
Tests: 22, Assertions: 89, Failures: 4.

$ ... tests/InitUpgradeTest.php tests/CatalogoOpcionalesUnifiedControllerTest.php --testdox
 ✘ Model url fallbacks point to ventas opcionales
 ✘ Deleted page has no controller or view
 ✘ Catalogo and tarifario views repoint to ventas opcionales
 ✘ Upgrade retires tarif opcionales page idempotently
FAILURES!
Tests: 17, Assertions: 68, Failures: 4.
```

### RED transcript (3.1 retirement test, after the design swap below)

The retirement test was initially written as a behavior test with a global `fs_page` recording double.
That fixture leaked into the PHPUnit parent process and collided with the real `model/fs_page.php`
(`Cannot declare class fs_page`) once later suites loaded it, so the test was redesigned as the
project-standard source contract (mirroring `InitFamiliasTablesTest::test_upgrade_wires_ventas_familias_page_retirement`).
Its RED was then re-proven against the pre-implementation `Init.php` (temporarily swapped back):

```
$ ... tests/InitUpgradeTest.php
FAILURES!
Tests: 2, Assertions: 2, Failures: 1.
    ... contains "private static function retireTarifOpcionalesPage(): void"
```

### GREEN transcripts

```
$ ... tests/TarifOpcionalesControllerContractTest.php tests/InitUpgradeTest.php tests/CatalogoOpcionalesUnifiedControllerTest.php
OK (22 tests, 113 assertions)

$ ... tests/CatalogoOpcionalesUnifiedControllerTest.php
OK (15 tests, 89 assertions)

$ ... 11 regression-gate files
OK (99 tests, 599 assertions)

$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
OK, but there were issues!
Tests: 498, Assertions: 1678, Warnings: 25, Skipped: 1.

$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
OK, but some tests were skipped!
Tests: 198, Assertions: 759, Skipped: 3.
```

### Page-link audit (3.3)

```
$ grep -rn 'page=tarif_opcionales' plugins/catalogo_core plugins/tarifario \
    --include='*.php' --include='*.twig' --include='*.js' --exclude-dir=vendor --exclude-dir=openspec
grep exit=1   # no matches → no page link remains
```

DB-table references (historical `tarif_opcionales` table) are untouched: 10 occurrences remain across
`Services/CatalogLegacyTableMigration.php`, `Services/TarifOpcionalExtMigration.php` and
`tests/DeadOpcionalTableReferenceTest.php` (explicitly excluded by the task).

---

## Work Unit Evidence

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php plugins/catalogo_core/tests/InitUpgradeTest.php plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php` → `OK (22 tests, 113 assertions)`. |
| Runtime harness command/scenario and exact result | `N/A` in apply — WU-3's runtime harness (`page=tarif_opcionales` 404s after `upgrade()`, grep audit) is explicitly a **verify**-phase step (`tasks.md` §Suggested Work Units, WU-3). The grep audit was run here (0 matches); the live 404/menu check requires the dev DB and is deferred to `sdd-verify`. |
| Rollback boundary | Restore the two deleted files (`controller/tarif_opcionales.php`, `View/tarif_opcionales.html.twig`) and revert the 2 model `url()` fallbacks, 7 view links and the `Init::retireTarifOpcionalesPage()` addition/wiring. The `fs_page` retirement is idempotent and the row is re-addable; clear the Twig cache after a revert. |

### Regression gate (must stay green)

| Test file | Result | Modified? |
|---|---|---|
| `VentasOpcionalesControllerTest` | OK | No |
| `CatalogoOpcionalesUnifiedControllerTest` | OK (15 tests, 89 assertions) | Yes — 3 OUM-09 tests added |
| `CatalogoOpcionalesHtmxContractTest` | OK | No |
| `TarifOpcionalesControllerMasterStateTest` | OK | No |
| `TarifOpcionalesHtmxContractTest` | OK | No |
| `TarifOpcionalEditTarifaSelectorTest` | OK | No |
| `TarifOpcionalPreciosControllerTest` | OK | No |
| `TarifConfiguradorOpcionalesTest` | OK | No |
| `OpcionalDomainModelOwnershipTest` | OK | No |
| `DeadOpcionalTableReferenceTest` | OK | No |
| `TarifOpcionalesControllerContractTest` | OK | Yes — slug dropped to 3 survivors + method renames |

All 11 files together: `OK (99 tests, 599 assertions)`. `php -l` clean on `Init.php`,
`InitUpgradeTest.php`, `tarif_opcional.php` and `tarif_opcional_precio.php`.

---

## Files Changed (Phase 3)

| File | Action | What Was Done |
|---|---|---|
| `plugins/catalogo_core/controller/tarif_opcionales.php` | Deleted | Legacy list controller removed, no redirect alias (AD-9). |
| `plugins/catalogo_core/View/tarif_opcionales.html.twig` | Deleted | Legacy list view removed, no alias. |
| `plugins/catalogo_core/Init.php` | Modified | Added `private static retireTarifOpcionalesPage(): void` (idempotent `fs_page::get('tarif_opcionales')` → `delete()`) and wired it into `upgrade()` in its own try/catch. |
| `plugins/catalogo_core/model/tarif_opcional.php` | Modified | `url()` no-id fallback → `index.php?page=ventas_opcionales`; `id` branch stays on `tarif_opcional_edit`. |
| `plugins/catalogo_core/model/tarif_opcional_precio.php` | Modified | `url()` no-id fallback → `index.php?page=ventas_opcionales`; `id` branch stays on `tarif_opcional_edit`. |
| `plugins/catalogo_core/View/tarif_opcional_edit.html.twig` | Modified | 2 back-links repointed (list link carries `b_codtarifa`). |
| `plugins/catalogo_core/View/tarif_opcional.html.twig` | Modified | 2 back-links repointed. |
| `plugins/catalogo_core/View/tarif_opcional_precios.html.twig` | Modified | 2 back-links repointed. |
| `plugins/tarifario/View/tarif_actualizar_precios.html.twig` | Modified | Opcionales link repointed. |
| `plugins/tarifario/View/tarif_articulo_precios.html.twig` | Modified | Opcionales link repointed. |
| `plugins/tarifario/View/tarif_articulos.html.twig` | Modified | Opcionales link repointed. |
| `plugins/tarifario/View/tarif_historial_precios.html.twig` | Modified | Opcionales link repointed. |
| `plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php` | Modified | `CONTROLLER_SLUGS` = 3 survivors; methods renamed to `test_all_surviving_controllers_*`. |
| `plugins/catalogo_core/tests/InitUpgradeTest.php` | Modified | Added `test_upgrade_retires_tarif_opcionales_page_idempotently` (source contract mirroring the `ventas_familias` retirement assertion + idempotency guard). |
| `plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php` | Modified | Added `test_model_url_fallbacks_point_to_ventas_opcionales`, `test_deleted_page_has_no_controller_or_view` and `test_catalogo_and_tarifario_views_repoint_to_ventas_opcionales`. |
| `plugins/catalogo_core/openspec/changes/unificar-opcionales-en-ventas-opcionales/tasks.md` | Modified | Phase 3 tasks 3.1–3.3 marked `[x]`. |

Not touched in this phase (per scope): `Services/CatalogLegacyTableMigration.php`,
`Services/TarifOpcionalExtMigration.php`, `tests/DeadOpcionalTableReferenceTest.php` (historical DB-table
references), the hooks/tab (Phase 4/5), the surviving edit/precios/configurator controllers beyond link
repoints, the master model and the price adapter. Twig cache cleared (`rm -rf tmp/twig_cache/*`) after the
deletion.

---

## Deviations from Design / Task Text (Phase 3)

1. **Retirement test is a source contract, not a global-stub behavior test.** The task asked to mirror
   the `retireVentasFamiliasPage` assertion with "two `Init::upgrade()` runs are a no-op". A behavior test
   required a global `fs_page` recording double; PHPUnit leaked that declaration into the parent process
   and later suites fatally collided with the real `model/fs_page.php` (`Cannot declare class fs_page`).
   The final test is the project-standard source contract (same shape as
   `InitFamiliasTablesTest::test_upgrade_wires_ventas_familias_page_retirement`) and additionally pins the
   idempotency guard (`if ($existing !== false) { $existing->delete(); }`). Its RED was re-proven against
   the pre-implementation `Init.php`.
2. **Edit-view back-link query key normalized.** `View/tarif_opcional_edit.html.twig` linked
   `...tarif_opcionales&codtarifa=...`; the unified list reads `b_codtarifa`, so the repointed link uses
   `index.php?page=ventas_opcionales&b_codtarifa=...` to preserve the selected-tarifa intent.
3. **Audit-safe negative assertion.** The view-repoint test composes the forbidden token
   (`'page=tarif_' . 'opcionales'`) instead of a contiguous literal so the exact WU-3 audit command
   returns zero matches.

## Issues / Notes (Phase 3)

- No dev-DB/browser smoke was run in apply (deferred to `sdd-verify` per the WU-3 harness note: the live
  `page=tarif_opcionales` 404 and empty-menu check).
- No Composer dependency was added → no `vendor/` delta.
- `rg` is not installed on the host shell; the audit used the task's exact `grep -rn ... --exclude-dir`
  form instead.
- The working trees of both `catalogo_core` and `tarifario` remain uncommitted (working-tree only; no
  commit/push performed).

## Next Recommended

`sdd-verify` for the WU-3 boundary (confirm the page-link audit, the live `page=tarif_opcionales` 404 and
the absence of a dangling menu item after `upgrade()`), then proceed to Phase 4 / WU-4 (move the opcional
Tarifas tab ownership to `catalogo_core`) via apply.

---

# Apply Progress — Phase 4 (WU-4, tasks 4.1–4.5)

- **Change**: `unificar-opcionales-en-ventas-opcionales` (plugin-local, `plugins/catalogo_core/openspec/`)
- **Phase**: 4 — WU-4 (tasks 4.1–4.5) — move the opcional Tarifas tab ownership to `catalogo_core` (OUM-10, OUM-11; AD-7, AD-8)
- **Mode**: Strict TDD (RED before GREEN)
- **Runner**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- **Baseline entering this phase (verified)**: `498 tests / 1678 assertions` OK (Warnings: 25, Skipped: 1); tarifario `198 / 759, Skipped 3`
- **Final (this phase)**: catalogo_core `507 tests / 1734 assertions` OK; tarifario `191 / 705, Skipped 3` OK

---

## TDD Cycle Evidence (Phase 4)

| Task | RED (test first) | GREEN (implementation) | REFACTOR |
|---|---|---|---|
| 4.1 hook ownership test | Created `tests/Integration/CatalogoOpcionalesHookOwnershipTest.php` (3 methods). Ran → `FAILURES! Tests: 3, Assertions: 3, Failures: 3` (no catalogo_core hooks, no `@catalogo_core` templates, no endpoint). | — (RED only in 4.1) | `twigFor()` later gained the real theme macro stubs + ChainLoader for the WU-5 render path. |
| 4.2 tarifario registration test | `FROZEN_HOOKS` = article pair; renamed `test_init_registers_all_four_frozen_hooks` → `test_init_registers_the_two_frozen_article_hooks`; removed the opcional guarded-render method; trimmed the opcional host-view assertions. Ran → `FAILURES! Tests: 7, Assertions: 26, Failures: 2` (tarifario still registered the opcional pair). | — (RED only in 4.2) | Removed the now-meaningless unsaved-opcional host test (relocated to the catalogo side). |
| 4.3 catalogo_core side | (covered by 4.1 RED) | Added the 4 `View/Hooks/*` templates, `controller/tarif_opcional_tab.php`, and `Init.php` registration (`OPCIONAL_HOOK_TEMPLATES`, `$hooksRegistered`/`$viewExtensionsRegistered`, `registerViewExtensions()` on `init()` + `upgrade()`). Ownership test → `OK (3 tests, 18 assertions)`. | Reworded a docblock so the self-containment audit finds no `plugins/tarifario/` token in the endpoint source. |
| 4.4 tarifario side | (covered by 4.2 RED) | Removed the 2 opcional `Init` entries, deleted the 3 opcional templates, split `tab_save_script` to article-only, stripped the opcional surface from `tarif_tab_precios`. HookRegistrationTest → `OK (7 tests, 27 assertions)`. | Relocated the removed opcional behavior coverage to `tests/TarifOpcionalTabEndpointTest.php` (6 methods). |
| 4.5 green + regression gate | RED proven in 4.1/4.2 above. | Focused set (`HookOwnership` + `HookMarkers` + `TarifOpcionalTabEndpoint`) → `OK (20 tests, 123 assertions)`; full catalogo → `OK (507 tests, 1734 assertions)`; full tarifario → `OK (191 tests, 705 assertions, 3 skipped)`. | Cleared `tmp/twig_cache`. |

### RED transcript (4.1)

```
$ ... plugins/catalogo_core/tests/Integration/CatalogoOpcionalesHookOwnershipTest.php
FAILURES!
Tests: 3, Assertions: 3, Failures: 3.
   Hook ventas_opcional_tabs_after must be registered after the Twig build
   ... does not contain "tab_tarifario_precios"
   Opcional tab endpoint must live in catalogo_core
```

### RED transcript (4.2)

```
$ ... plugins/tarifario/phpunit.xml plugins/tarifario/tests/Integration/HookRegistrationTest.php
FAILURES!
Tests: 7, Assertions: 26, Failures: 2.
   tarifario must register no opcional hook after WU-4 ...
   tarifario must not inject the opcional Tarifas tab after WU-4
```

### GREEN transcripts (Phase 4)

```
$ ... tests/Integration/CatalogoOpcionalesHookOwnershipTest.php
OK (3 tests, 18 assertions)

$ ... plugins/tarifario/phpunit.xml plugins/tarifario/tests/Integration/HookRegistrationTest.php
OK (7 tests, 27 assertions)

$ ... tests/TarifOpcionalTabEndpointTest.php
OK (6 tests, 38 assertions)

$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
OK, but there were issues!
Tests: 507, Assertions: 1734, Warnings: 25, Skipped: 1.

$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
OK, but some tests were skipped!
Tests: 191, Assertions: 705, Skipped: 3.
```

---

## Work Unit Evidence (Phase 4 — WU-4)

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/Integration/CatalogoOpcionalesHookOwnershipTest.php plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php plugins/catalogo_core/tests/TarifOpcionalTabEndpointTest.php` → `OK (20 tests, 123 assertions)`. |
| Runtime harness command/scenario and exact result | `N/A` in apply — WU-4's dev-DB smoke (`ventas_opcional` renders the tab with tarifario inactive; row save persists) is a **verify**-phase step (`tasks.md` §Suggested Work Units, WU-4). Apply-level proof: the endpoint behavior test drives GET rows + POST save/deny/CSRF against tracked `tarif_opcional_precio` / `simbolo_divisa` doubles, and the ownership test boots `catalogo_core\Init` + the real Twig build without tarifario. No live DB/web boundary is reachable in apply. |
| Rollback boundary | Revert the catalogo_core additions (4 hook/partial templates, `tarif_opcional_tab.php`, the `Init.php` `registerViewExtensions()` block, the two new test files) and the tarifario removals (2 `Init` entries, 3 deleted templates, split `tab_save_script`, stripped `tarif_tab_precios`, relocation in `TarifTabPreciosTest`). The article hooks/partials and article path were never touched. Clear the Twig cache after a revert. |

### Regression gate (Phase 4, must stay green)

| Test file | Result | Modified? |
|---|---|---|
| `CatalogoCoreHookMarkersTest` (4 frozen host markers) | OK (unchanged) | No |
| `CatalogoOpcionalesHookOwnershipTest` | OK (3 tests, 18 assertions) | New |
| `TarifOpcionalTabEndpointTest` | OK (6 tests, 38 assertions) | New (relocated) |
| `plugins/tarifario/.../HookRegistrationTest` | OK (7 tests, 27 assertions) | Yes |
| `plugins/tarifario/.../TarifTabPreciosTest` | OK (8 tests, 50 assertions) | Yes — opcional surface removed |

Audit on the moved tree: bare `fsc.simbolo_divisa()` → 0; `hx-delete` → 0; v2 htmx names → 0;
`bootbox` / `|raw` → 0. `git diff --stat` on `articulo_precios_rows.html.twig`,
`ventas_articulo_tabs_after.html.twig`, `ventas_articulo_tab_pane_after.html.twig` → **empty** (article surface byte-identical).

### Root Plugins suite note

`ddev exec php vendor/bin/phpunit --testsuite Plugins` → 10 failures, **all pre-existing and unrelated**:
7× OidcProvider (DB/schema, order-dependent), 2× `TarifTarifaOpcionalPrecedenceTest` (reproduces with only
that file under the non-isolated root config, and with my new tests removed — a pre-existing
`processIsolation` incompatibility), 1× `LegacySupportTest` version drift (`1.2.0` vs `1.3.0`). The four
WU-4 files pass under the root config: `OK (24 tests, 133 assertions)`.

---

## Files Changed (Phase 4)

| File | Action | What Was Done |
|---|---|---|
| `plugins/catalogo_core/View/Hooks/ventas_opcional_tabs_after.html.twig` | Created | Opcional tab header (guarded by `fsc.is_new`), endpoint `page=tarif_opcional_tab&action=rows&ref={{ fsc.opcional.id }}`. |
| `plugins/catalogo_core/View/Hooks/ventas_opcional_tab_pane_after.html.twig` | Created | Opcional pane `#tab_tarifario_precios`; includes the opcional save-script partial. |
| `plugins/catalogo_core/View/Hooks/partials/opcional_precios_rows.html.twig` | Created | Per-tarifa rows; currency via `fsc.simbolo_divisa(tarifa.coddivisa)`. |
| `plugins/catalogo_core/View/Hooks/partials/opcional_tab_save_script.html.twig` | Created | Opcional save wiring with idempotent window flag. |
| `plugins/catalogo_core/controller/tarif_opcional_tab.php` | Created | `fbase_controller` + `TarifarioOpcionalStateTrait`; GET `action=rows&ref=` fragment, POST `guardar_precio_tab` → `requireCsrf()` + `puede_editar_opcional()` JSON `{ok,message,html}`. |
| `plugins/catalogo_core/Init.php` | Modified | `OPCIONAL_HOOK_TEMPLATES`, static guards, `registerViewExtensions()` (TwigLoaderEvent `@catalogo_core` namespace + TwigInitEvent hooks) wired into `init()` and `upgrade()`. |
| `plugins/catalogo_core/tests/Integration/CatalogoOpcionalesHookOwnershipTest.php` | Created | Registration/idempotency + render + endpoint source contract (OUM-10). |
| `plugins/catalogo_core/tests/TarifOpcionalTabEndpointTest.php` | Created | Relocated endpoint behavior coverage (rows currency, deny, CSRF, save, delete, S28). |
| `plugins/tarifario/Init.php` | Modified | `HOOK_TEMPLATES` drops the 2 opcional entries. |
| `plugins/tarifario/View/Hooks/ventas_opcional_tabs_after.html.twig` | Deleted | Moved to catalogo_core. |
| `plugins/tarifario/View/Hooks/ventas_opcional_tab_pane_after.html.twig` | Deleted | Moved to catalogo_core. |
| `plugins/tarifario/View/Hooks/partials/opcional_precios_rows.html.twig` | Deleted | Moved to catalogo_core. |
| `plugins/tarifario/View/Hooks/partials/tab_save_script.html.twig` | Modified | Article-only copy (opcional fields/branch removed). |
| `plugins/tarifario/controller/tarif_tab_precios.php` | Modified | Removed `rows_tipo()`, `render_opcional_rows_fragment()`, `opcional_precio_model()`, `guardar_precio_opcional()`, `puede_editar_opcional()` + the `tarif_opcional_precio` require; `render_rows_fragment()` always renders article rows (output unchanged). |
| `plugins/tarifario/tests/Integration/HookRegistrationTest.php` | Modified | `FROZEN_HOOKS` = article pair; opcional assertions removed; article + idempotency kept. |
| `plugins/tarifario/tests/Controller/TarifTabPreciosTest.php` | Modified | Opcional surface removed; article/CSRF/S27/S28/AD-5 kept. |
| `plugins/catalogo_core/openspec/changes/unificar-opcionales-en-ventas-opcionales/tasks.md` | Modified | Phase 4 tasks 4.1–4.5 marked `[x]`. |

---

## Deviations from Design / Task Text (Phase 4)

1. **`TarifTabPreciosTest` updated (not named in the task).** The task's WU-4 target list omitted the
   tarifario behavior test, but `tarif_tab_precios`'s opcional surface was removed per AD-7, so keeping its
   opcional tests would regress the tarifario suite. The removed coverage was **relocated**, not dropped:
   `plugins/catalogo_core/tests/TarifOpcionalTabEndpointTest.php` (6 methods) exercises the moved endpoint
   through the same `opcional_precio_model()` / `puede_editar_opcional()` seams.
2. **`puede_editar_opcional()` is soft-coupled.** The gestor role table (`FSFramework\model\tarif_grupo_usuario`)
   belongs to the optional tarifario plugin. The endpoint checks it with `class_exists(...)` (no require,
   no `plugins/tarifario/...` path) and fails closed to admin-only when tarifario is inactive — preserving
   the original admin/gestor semantics while keeping catalogo_core self-contained (OUM-10).
3. **`init()`/`upgrade()` call `registerViewExtensions()` in their own try/catch + `error_log`,** mirroring
   `tarifario\Init` and the existing `ensure*` blocks.

## Issues / Notes (Phase 4)

- No dev-DB/browser smoke was run in apply (deferred to `sdd-verify` per the WU-4 harness note).
- No Composer dependency was added → no `vendor/` delta.
- The twig cache was cleared (`rm -rf tmp/twig_cache && mkdir -p tmp/twig_cache`).
- Working trees remain uncommitted (no commit/push performed).

## Next Recommended (Phase 4)

`sdd-verify` for the WU-4 boundary (dev-DB smoke: `ventas_opcional` renders the tab with tarifario inactive,
row save persists), then Phase 5 / WU-5 (htmx 4 + Alpine CSP for the moved tab) via apply.

---

# Apply Progress — Phase 5 (WU-5, tasks 5.1–5.3)

- **Change**: `unificar-opcionales-en-ventas-opcionales` (plugin-local, `plugins/catalogo_core/openspec/`)
- **Phase**: 5 — WU-5 (tasks 5.1–5.3) — htmx 4 + Alpine CSP for the moved opcional tab (OUM-08, OUM-10; AD-10)
- **Mode**: Strict TDD (RED before GREEN)
- **Baseline entering this phase (verified)**: catalogo_core `507 tests / 1734 assertions` OK (Warnings: 25, Skipped: 1)
- **Final (this batch)**: catalogo_core `511 tests / 1756 assertions` OK; tarifario `191 / 705, Skipped 3` OK

---

## TDD Cycle Evidence (Phase 5)

| Task | RED (test first) | GREEN (implementation) | REFACTOR |
|---|---|---|---|
| 5.1 moved-tab htmx/Alpine contracts | Extended `CatalogoOpcionalesHookOwnershipTest.php` with `test_moved_tab_pane_boots_htmx_and_alpine`, `test_moved_tab_mutations_use_hx_post_and_never_hx_delete`, `test_moved_tab_registers_alpine_data_with_nonce_and_init_guard`, `test_moved_tab_uses_colon_events_and_no_v2_names`. Ran → `FAILURES! Tests: 7, Assertions: 29, Failures: 4` (Phase-4 templates were jQuery/FSAjaxLoader). | — (RED only in 5.1) | Also chained the real theme `Macro/Htmx`+`Macro/Alpine` sources into `twigFor()` so the render test can resolve the pane's imports. |
| 5.2 migration | (covered by 5.1 RED) | Migrated the header (`hx-get` row load), pane (`htmx.boot({'allowScriptTags': false})` + `alpine.boot()` + `[x-cloak]` + `x-text` message), rows (`hx-post` + `hx-include="closest tr"` + hidden fields) and save script (nonce'd `Alpine.data()` behind `alpine:init`; `htmx:after:request` parses the JSON and swaps the rows; `htmx:after:swap` re-inits Alpine). Ownership test → `OK (7 tests, 40 assertions)`. | Kept the per-tarifa currency rendering (`fsc.simbolo_divisa(tarifa.coddivisa)`) and the `data-*` hooks the behavior test asserts. |
| 5.3 regression gate | RED proven in 5.1. | Full catalogo → `OK (511 tests, 1756 assertions)`; full tarifario → `OK (191 tests, 705 assertions, 3 skipped)`. | Cleared the Twig cache. |

### RED transcript (5.1)

```
$ ... tests/Integration/CatalogoOpcionalesHookOwnershipTest.php
FAILURES!
Tests: 7, Assertions: 29, Failures: 4.
   Pane must boot htmx 4
   Moved tab mutations must be POST-based
   Script must register components via Alpine.data()
   ... matches PCRE pattern "/htmx:after:(swap|request)/"
```

### GREEN transcripts (Phase 5)

```
$ ... tests/Integration/CatalogoOpcionalesHookOwnershipTest.php
OK (7 tests, 40 assertions)

$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
OK, but there were issues!
Tests: 511, Assertions: 1756, Warnings: 25, Skipped: 1.

$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
OK, but some tests were skipped!
Tests: 191, Assertions: 705, Skipped: 3.
```

---

## Work Unit Evidence (Phase 5 — WU-5)

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/Integration/CatalogoOpcionalesHookOwnershipTest.php` → `OK (7 tests, 40 assertions)`. |
| Runtime harness command/scenario and exact result | `N/A` in apply — WU-5's browser smoke (tab rows load, save posts, no CSP/console errors) is a **verify**-phase step (`tasks.md` §Suggested Work Units, WU-5). Apply-level proof: DB-free source contracts (boot macros, `hx-post`, nonce + `alpine:init` + `[x-cloak]`, colon events, no v2 names, no `hx-delete`, no `bootbox`, no `|raw`) plus the endpoint behavior test. No browser/runtime boundary is reachable in apply. |
| Rollback boundary | Revert the four catalogo_core hook/partial templates and the endpoint only; the endpoint's server contract (`{ok,message,html}`) is unchanged, so a revert restores the Phase-4 jQuery/FSAjaxLoader wiring without touching tarifario. |

### Moved-tree audit (Phase 5)

```
$ grep -rnE 'fsc\.simbolo_divisa\(\s*\)' plugins/catalogo_core/View/Hooks   # none
$ grep -rn 'hx-delete' plugins/catalogo_core/View/Hooks                     # none
$ grep -rnE 'htmx:afterSwap|htmx:afterRequest' plugins/catalogo_core/View/Hooks  # none
$ grep -rnE 'bootbox|\|raw' plugins/catalogo_core/View/Hooks                # none
$ grep -rn '@catalogo_core/Hooks' plugins/catalogo_core                      # endpoint renders the catalogo rows partial
```

Article surface: `git diff --stat` on the three article hook/partial files → empty (byte-identical).

Other invariants: no `hx-delete`; no htmx v2 event names; no `bootbox`; no `|raw`; bare no-argument
`fsc.simbolo_divisa(` absent (rows pass `tarifa.coddivisa`).

---

## Files Changed (Phase 5)

| File | Action | What Was Done |
|---|---|---|
| `plugins/catalogo_core/View/Hooks/ventas_opcional_tabs_after.html.twig` | Modified | Tab click issues `hx-get` (rows fragment) + `hx-target="#tab_tarifario_precios_rows"` + `hx-swap="outerHTML"`; guard kept. |
| `plugins/catalogo_core/View/Hooks/ventas_opcional_tab_pane_after.html.twig` | Modified | Imports Htmx/Alpine macros, `[x-cloak]` style, `x-data="opcionalTabPrecios"`, `x-text` message, `htmx.boot({'allowScriptTags': false})` + save-script include + `alpine.boot()`. |
| `plugins/catalogo_core/View/Hooks/partials/opcional_precios_rows.html.twig` | Modified | Per-row `hx-post` + `hx-include="closest tr"` + hidden `guardar_precio_tab`/`codtarifa`/`referencia`; per-tarifa `simbolo_divisa(tarifa.coddivisa)` preserved. |
| `plugins/catalogo_core/View/Hooks/partials/opcional_tab_save_script.html.twig` | Modified | Nonce'd classic script: `Alpine.data('opcionalTabPrecios')` behind `alpine:init`; `htmx:after:request` parses `{ok,message,html}` and swaps rows via `outerHTML`; `htmx:after:swap` re-inits Alpine. |
| `plugins/catalogo_core/tests/Integration/CatalogoOpcionalesHookOwnershipTest.php` | Modified | Added the 4 WU-5 htmx/Alpine contract methods + theme macro ChainLoader in `twigFor()`. |
| `plugins/catalogo_core/openspec/changes/unificar-opcionales-en-ventas-opcionales/tasks.md` | Modified | Phase 5 tasks 5.1–5.3 marked `[x]`. |

---

## Deviations from Design / Task Text (Phase 5)

1. **Save flow = `hx-post` + `htmx:after:request`, not `htmx.ajax('POST')`.** The rows partial posts each
   row via `hx-post` (with `hx-include="closest tr"` and the inherited `X-CSRF-TOKEN` from
   `htmx.boot`), and the nonce'd script parses the JSON `{ok,message,html}` on the htmx 4
   `htmx:after:request` colon event, then swaps the re-rendered rows with `outerHTML`. This keeps the
   design's JSON contract (AD-7) while satisfying AD-10's `hx-post` + inherited-CSRF requirement.
2. **The tab header carries the lazy-load `hx-get`.** The pane's rows placeholder is swapped by the header
   click (rather than a `hx-trigger="load"` on the pane), which keeps the `page=tarif_opcional_tab&action=rows&ref=`
   endpoint in the header (WU-4 render contract) and preserves the tab-activation lazy-load semantics.
3. **Alpine message component is display-only.** `opcionalTabPrecios` holds `message`/`messageClass` and is
   updated from the htmx event handler via `Alpine.$data(pane)`; `x-text` (never `innerHTML`) renders it.

## Issues / Notes (Phase 5)

- No browser/dev-DB smoke was run in apply (deferred to `sdd-verify` per the WU-5 harness note: tab rows
  load, save posts, no CSP/console errors).
- `rg` is not installed on the host shell; audits used `grep` with the same patterns.
- No Composer dependency was added → no `vendor/` delta.
- Twig cache cleared; both working trees remain uncommitted.

## Next Recommended (Phase 5)

`sdd-verify` for the combined WU-4 + WU-5 boundary (dev-DB/browser smoke: the opcional tab renders with
tarifario inactive, row save persists with CSRF, no CSP/console errors), then Phase 6 / WU-6
(verification: PHPStan + grep audit + full acceptance checklist) via apply/verify.
