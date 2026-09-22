# Apply Progress: gestion-idiomas-catalogo

| Field | Value |
|---|---|
| **Change** | `gestion-idiomas-catalogo` |
| **SDD owner** | `plugins/catalogo_core/openspec/` — `ownership: plugin-local` |
| **Artifact store** | `openspec` (core `openspec/` received nothing) |
| **Phase** | `apply` |
| **Mode** | **Strict TDD** (`strict_tdd: true`) |
| **Slice** | **1a + 1b (slice 1 complete) + slice 2 complete** |
| **Delivery** | `auto-chain`, `chain_strategy: stacked-to-main`, `review_budget_lines: 800` |
| **Cut boundary used** | **No for `1b`** — 626 authored lines ≤ 800, landed whole. **No cut for slice 2** — 862 authored lines, complete and green; overage reported for `size:exception` (see "Budget measurement"). The pre-declared `1a`/`1b` boundary **was** used for the `1a` PR (1023 lines), which the maintainer accepted as a `size:exception` (see "Budget measurement"). |
| **Status** | **success** — slice 1 (`1a` + `1b`) and slice 2 complete and green |

---

## Slice-1 task status

### Delivered (1a)

| Task | Tag(s) | State | Evidence |
|---|---|---|---|
| 1.1 RED — `CatalogoIdiomaInvariantsTest.php` (4 GDI-02 scenarios) | `[GDI-02; D-02]` | [x] | 8 tests, RED first (7 errors + 1 failure), then GREEN |
| 1.2 GREEN — `get_effective_default_code()`, `get_default()` + `AND activo = TRUE`, `normalize_default()` | `[GDI-02; D-02]` | [x] | `catalogo_idioma.php` |
| 1.3 RED — `set_default()` assertions (unknown / inactive / same-op clear) | `[GDI-02; D-02]` | [x] | `CatalogoIdiomaInvariantsTest` |
| 1.4 GREEN — transactional `set_default()`, no `articulo_descripciones` statement | `[GDI-02; D-02]` | [x] | flag-flip test asserts zero description SQL |
| 1.5 GREEN — `save()` rejects deactivating the default, forces active, one transaction | `[GDI-02; D-02]` | [x] | deactivation-rejected test |
| 1.6 RED — `CatalogoIdiomaDeleteCleanupTest.php` (2 GDI-03 scenarios) | `[GDI-03; D-01, D-02]` | [x] | RED demonstrated by temporarily removing both guards (2 failures), then restored |
| 1.7 GREEN — last-language guard + application-level orphan cleanup + `normalize_default()` | `[GDI-03; D-01, D-02]` | [x] | `catalogo_idioma::delete()` |
| 1.8 CONFIRM — no `codidioma` FK added | `[GDI-03; D-01]` | [x] | `git diff -- model/table/articulo_descripciones.xml model/table/catalogo_idiomas.xml` is empty |
| 1.9 RED→GREEN — idempotent orphan purge in `CatalogLegacyTableMigration` | `[GDI-03; D-01]` | [x] | RED first (1 failure), then GREEN; second run asserts no DELETE |
| 1.17 RED→GREEN — extend `CatalogoIdiomaTest.php` (`get_default`, `all_activos`, `get_effective_default_code`, `set_default`, `test()` bounds) | `[GDI-01 model half, GDI-02; D-02]` | [x] | 5 new tests |

> **Boundary deviation (documented):** task 1.17 was pulled **forward into `1a`** because it is
> registry test coverage and is cohesive with the registry work unit. The pre-declared boundary
> listed it under `1b` (1.10–1.17); the delivered `1a` is therefore 1.1–1.9 **+ 1.17**, and the
> deferred `1b` starts at 1.10.

### Delivered (1b)

| Task | Tag(s) | State | Evidence |
|---|---|---|---|
| 1.10 RED — `ArticuloDescripcionClearingTest.php` (4 GDI-07 scenarios + the no-op case) | `[GDI-07; D-11, D-12]` | [x] | 5 tests, RED first (5/5 failures), then GREEN |
| 1.11 GREEN — `articulo_descripcion::test()` accepts empty `descripcion`; `save()` clears the empty pair | `[GDI-07; D-11, D-12]` | [x] | `model/core/articulo_descripcion.php` |
| 1.12 RED — extend `ArticuloMultiidiomaTest.php` for GDI-05 | `[GDI-05; D-09]` | [x] | RED first (1 error + 2 failures), then GREEN |
| 1.13 GREEN — `get_descripcion_idioma($codidioma = null)` / `descripcion_idioma($codidioma = null, $len = 120)` | `[GDI-05; D-09]` | [x] | `model/core/articulo.php`; `get_effective_default_code()` resolved once |
| 1.14 RED — `ArticuloDescripcionFrozenBaseTest.php` (2 GDI-06 scenarios) | `[GDI-06; D-01, D-10]` | [x] | RED first (1 failure), then GREEN |
| 1.15 GREEN — remove the `set_descripcion_idioma()` mirror branch | `[GDI-06; D-01, D-10]` | [x] | base column asserted unchanged after a default-language save |
| 1.16 GREEN — `articulo::get_descripcion_corta_idioma($codidioma = null)` | `[GDI-11 prefill; D-08, R2]` | [x] | same-language only; RED via `test_get_descripcion_corta_idioma_is_same_language_only` (undefined method) |

> **Ordering note (documented):** `1b` implemented the read-chain work unit (`1.12–1.16`) **before**
> the clearing work unit (`1.10–1.11`), because the clearing scenario "a cleared language falls
> back at read time" needs the read chain exercised DB-free through the new seams. Both work
> units are green and committed; `tasks.md` marks all seven tasks `[x]`.

### Deferred to slice 4a (not slice 1)

| Task | Tag(s) | State | Note |
|---|---|---|---|
| 4a.1–4a.3, 4a.8 — `articulo::invalidate_search_cache()` + callers + grep gate | `[GDI-08; D-03]` | [ ] | **not** implemented here; tasks.md assigns GDI-08 to slice 4a. See "Deviations". |

---

## Slice-2 task status — Language-management UI

Delivered whole (tasks 2.1–2.8 all `[x]` in `tasks.md`). The `#idiomas` anchor
`catalogo_idioma::url()` already pointed at now resolves to a real section.

| Task | Tag(s) | State | Evidence |
|---|---|---|---|
| 2.1 RED — `tests/CatalogoIdiomaManagementTest.php` (GDI-01 lifecycle + url anchor + invalid payload + surface dispatch) | `[GDI-01; D-06]` | [x] | 4 tests, RED first (2 failures: missing `id="idiomas"`, missing action dispatch), then GREEN |
| 2.2 GREEN — POST-only `idioma_action` dispatch in `privateCore()` | `[GDI-01, GDI-04; D-06]` | [x] | `shouldDispatchIdioma()` guard, behaviourally pinned by the permission test |
| 2.3 GREEN — `gestionarIdioma()` + `guardarIdioma()`/`alternarIdioma()`/`borrarIdioma()` | `[GDI-01, GDI-04; D-06, D-12]` | [x] | `Controller/VentasArticulos.php`; `validateFormToken()` → `user->admin` → action dispatch |
| 2.4 RED — `tests/CatalogoIdiomaPermissionTest.php` (GDI-04: non-admin, CSRF, GET, no `AdminOnly`) | `[GDI-04; D-12]` | [x] | 5 tests, RED first (5 errors: `gestionarIdioma`/`shouldDispatchIdioma` missing), then GREEN |
| 2.5 GREEN — `idiomas_todos` + `idioma_model()` seam + `all()` in `load_list_idiomas()` | `[GDI-01; D-06]` | [x] | `extras/VentasArticulosListTrait.php`; `public array $idiomas_todos = []` also declared on `VentasArticulos` |
| 2.6 GREEN — `#idiomas` panel (table + badges + one form per row + add form, each `csrf_field()` + hidden `idioma_action`) | `[GDI-01; D-06]` | [x] | `View/ventas_articulos.html.twig`; Twig parse verified, `CatalogoArticuloListHtmxContractTest` green |
| 2.7 GREEN — 18 `language-*` keys in es + en | `[GDI-01; D-06]` | [x] | both YAML files parse (179 / 148 keys); existing keys reused, none redefined |
| 2.8 VERIFY — slice-2 tests + list absorption + PHPStan | `[GDI-01, GDI-04]` | [x] | full plugin suite **852 tests OK**; `VentasArticulosListAbsorptionTest` green; PHPStan clean except the pre-existing core error |

> **No `#[AdminOnly]` on `ventas_articulos`** — verified by the source assertion in
> `CatalogoIdiomaPermissionTest::test_get_cannot_mutate_and_the_page_has_no_admin_only`
> (the controller source must not contain `AdminOnly` at all, so even the docblock avoids
> the literal). The mutation guard is per-action.

---

## TDD Cycle Evidence (Strict TDD)

| Task | RED | GREEN | REFACTOR |
|---|---|---|---|
| 1.1 / 1.2 | `--filter CatalogoIdiomaInvariantsTest` → 7 errors (`undefined method get_effective_default_code/set_default`) + 1 failure | same filter → 8 tests, 37 assertions, OK | extracted `FakeCatalogoIdioma` shared double to remove duplication across three test files; re-ran green |
| 1.3 / 1.4 | included in the 1.1 RED run (`set_default()` undefined) | 8/8 green | — |
| 1.5 | included in the 1.1 RED run (deactivation failure) | 8/8 green | — |
| 1.6 / 1.7 | temporarily removed the last-language guard → 1 failure; temporarily removed the description DELETE → 2 failures; both restored | `--filter CatalogoIdiomaDeleteCleanupTest` → 2 tests, 10 assertions, OK | — |
| 1.9 | `--filter CatalogLegacyTableMigrationTest` → 1 failure (orphan `zz` row survived) | 7 tests, 21 assertions, OK | — |
| 1.17 | `test_registry_validation_bounds` first failed with `new_error() on null` on the DB-free bare model; switched to the `FakeCatalogoIdioma` double | 17 tests, 59 assertions, OK | — |
| 1.12 / 1.13 | `ArticuloMultiidiomaTest` + `ArticuloDescripcionFrozenBaseTest` → 8 tests, 1 error (`get_descripcion_corta_idioma` undefined) + 3 failures (read chain returned the base text, mirror still wrote the base) | same two files → 8 tests, 23 assertions, OK | — |
| 1.14 / 1.15 / 1.16 | included in the 1.12 RED run (mirror still writes the base; short accessor missing) | same two files → 8 tests, 23 assertions, OK | — |
| 1.10 / 1.11 | `ArticuloDescripcionClearingTest` → 5 tests, **5/5 failures** (`test()` rejected the empty description; `save()` neither deleted nor no-opped) | same file → 5 tests, 14 assertions, OK | — |
| 2.1 / 2.2 / 2.5–2.7 | `--filter CatalogoIdiomaManagementTest` → 4 tests, **2 failures** (no `id="idiomas"` section; no language action dispatched) | same filter → 4 tests, 26 assertions, OK | — |
| 2.3 / 2.4 | `--filter CatalogoIdiomaPermissionTest` → 5 tests, **5 errors** (`gestionarIdioma()` / `shouldDispatchIdioma()` undefined) | same filter → 5 tests, 25 assertions, OK | — |

No task was completed without a test-first step. No silent fallback to Standard Mode.

---

## Work Unit Evidence

### Work unit `1a` — language registry invariants

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **832 tests, 3655 assertions, Warnings: 26, Skipped: 1, OK** (baseline before the slice: 816 tests, 3596 assertions; +16 tests, no regressions) |
| **Runtime harness command/scenario and exact result** | `N/A` — model/registry contract change with no routing, HTTP or process boundary. The runtime boundary for slice 1 is the DB-backed boot purge, exercised DB-free through the per-driver fake (`CatalogLegacyTableMigrationTest::test_orphan_descriptions_are_purged_once`) plus the in-memory registry fake. A real-DB boot smoke is deferred to `verify`. |
| **Rollback boundary** | Revert commit `661b94c3`. All files are additive to the registry contract; the only irreversible step is the idempotent orphan purge (`CatalogLegacyTableMigration::purgeOrphanDescriptions()`), which removes only rows whose `codidioma` has no registry row (unreachable through every surface). The operator-level pre-migration dump is the safety net. No schema, view, controller, search or consumer file is touched. |

### Work unit `1b-A` — article description read chain + frozen base (GDI-05, GDI-06, GDI-11 prefill)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/ArticuloMultiidiomaTest.php plugins/catalogo_core/tests/ArticuloDescripcionFrozenBaseTest.php` → **OK (8 tests, 23 assertions)**; RED first was **1 error + 3 failures** |
| **Runtime harness command/scenario and exact result** | `N/A` — pure model read/write contract with no routing, HTTP or process boundary. The DB-free harness is `FakeArticulo` + `FakeArticuloDescripcion` over the slice-1a `IdiomaRegistryFake`, which mutates seeded rows exactly like the emitted SQL; the real-DB boot smoke remains deferred to `verify`. |
| **Rollback boundary** | Revert commit `ee66bd9c`. The change is confined to `model/core/articulo.php` (two protected seams, the read chain, the mirror removal, the short accessor) plus its tests. No schema, view, controller, consumer or `tarifario` file is touched; the base column keeps its data (nothing is rewritten). |

### Work unit `1b-B` — clearing semantics (GDI-07)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/ArticuloDescripcionClearingTest.php` → **OK (5 tests, 14 assertions)**; RED first was **5/5 failures** |
| **Runtime harness command/scenario and exact result** | `N/A` — model-only save-path contract. The DB-free harness is `FakeArticuloDescripcion` over `IdiomaRegistryFake`, which removes/updates seeded rows exactly like the emitted SQL. The editor and Excel clearing legs are slices 3 and 4b. |
| **Rollback boundary** | Revert commit `c2269c87`. Confined to `model/core/articulo_descripcion.php` (`test()` accepts empty; `save()` clears the empty pair; private `isEmptyPair()`) plus its test. No schema, view, controller or consumer file is touched. |

---

## Work Unit Evidence (slice 2)

### Work unit `2` — `#idiomas` language-management surface

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoIdiomaManagementTest` → **OK (4 tests, 26 assertions)** and `--filter CatalogoIdiomaPermissionTest` → **OK (5 tests, 25 assertions)**; RED first was 2 failures + 5 errors respectively |
| **Runtime harness command/scenario and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **OK — 852 tests, 3741 assertions, 2 warnings, 1 skipped** (baseline before slice 2: 843 tests, 3690 assertions; +9 tests, no regressions). The HTTP boundary is exercised DB-free: `Request::create(..., 'POST', $fields)` + the `IdiomaRegistryFake`, with the real `gestionarIdioma()` body executing through the reflection seam. A real-browser POST smoke is deferred to `verify`. |
| **Rollback boundary** | Revert the slice's commit(s): `Controller/VentasArticulos.php` (dispatch + `gestionarIdioma()` and its three helpers + `idiomas_todos`), `extras/VentasArticulosListTrait.php` (`idioma_model()` seam + `all()`), `View/ventas_articulos.html.twig` (`#idiomas` panel), the two `translations/messages.*.yaml` additions and the two new test files. No model, schema, search, Excel, consumer or `tarifario` file is touched; the list keeps working without the panel. Clear the Twig cache after reverting the view. |

---

## Test commands and results (exact)

| Command | Result |
|---|---|
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (after `1b`) | **OK** — **843 tests, 3690 assertions, 2 warnings, 1 skipped**. (`1a`-only baseline: 832 tests, 3655 assertions, 26 warnings, 1 skipped. `1b` adds 11 tests: `ArticuloMultiidiomaTest` +4, `ArticuloDescripcionFrozenBaseTest` +2, `ArticuloDescripcionClearingTest` +5. No failures, no regressions. The warning drop comes from the old DB-touching `ArticuloMultiidiomaTest` being rewritten onto DB-free doubles.) |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root, regression, after `1b`) | **FAILED (pre-existing, unrelated)** — 2053 tests, 8111 assertions, **8 failures**, 1 warning, 46 skipped. All 8 failures are in the untouched `OidcProvider` plugin (`OidcRegisterControllerMinimalClienteTest` ×2, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` ×4); this slice touches only `plugins/catalogo_core/`. **No `catalogo_core` test failed.** (`1a`-only baseline: 2031 tests, 8033 assertions, same 8 `OidcProvider` failures.) |
| `ddev exec composer phpstan` | **FAILED (pre-existing, unrelated)** — 1 error at `tests/Core/PluginEnableAjaxSafetyTest.php:308` (`return.type`). phpstan paths are `src` and root `tests` only; plugin code is **not** covered by this config, so no slice-1 file was analysed. The failing file is unmodified by this slice. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (after slice 2) | **OK** — **852 tests, 3741 assertions, 2 warnings, 1 skipped**. Slice 2 adds 9 tests (`CatalogoIdiomaManagementTest` +4, `CatalogoIdiomaPermissionTest` +5). No failures, no regressions; `VentasArticulosListAbsorptionTest`, `CatalogoArticuloListHtmxContractTest`, `CatalogoCoreHookMarkersTest` and `VentasArticulosControllerTest` stay green. |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root, regression, after slice 2) | **FAILED (pre-existing, unrelated)** — 2062 tests, 8173 assertions, **7 failures**, 1 warning, 46 skipped. All 7 failures are in the untouched `OidcProvider` plugin (`OidcRegisterControllerMinimalClienteTest` ×1, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` ×4); this slice touches only `plugins/catalogo_core/`. **No `catalogo_core` test failed.** Slice 1 recorded 8 pre-existing `OidcProvider` failures (the extra `OidcRegisterControllerMinimalClienteTest` case is order/state sensitive in that untouched plugin). |
| `ddev exec composer phpstan` (after slice 2) | **FAILED (pre-existing, unrelated)** — the same single `tests/Core/PluginEnableAjaxSafetyTest.php:308` error; no slice-2 file is analysed by this config. |
| Twig parse check (slice 2) | `View/ventas_articulos.html.twig` tokenizes + parses with stubbed `trans`/`csrf_field`/`csp_nonce_attr` → **TWIG PARSE OK**. |

---

## Budget measurement and cut decision

| Item | Value |
|---|---|
| Session budget (`review_budget_lines`) | **800** |
| `1a` authored changed lines (additions + deletions) | **1023** (`git show --stat 661b94c3`: 1020 insertions + 3 deletions) |
| `1b` authored changed lines (additions + deletions) | **626** (`git show --stat ee66bd9c` = 418 + 48; `git show --stat c2269c87` = 155 + 5) |
| Slice 1 total authored lines | **1649** across `1a` (1023) + `1b` (626) |
| Slice 2 authored changed lines (additions + deletions) | **862** — `Controller/VentasArticulos.php` +150, `View/ventas_articulos.html.twig` +123, `extras/VentasArticulosListTrait.php` +22/−2, `translations/messages.en_EN.yaml` +18, `translations/messages.es_ES.yaml` +18, `tests/CatalogoIdiomaManagementTest.php` +179 (new), `tests/CatalogoIdiomaPermissionTest.php` +350 (new) |
| Pre-declared boundary | `1a` = tasks 1.1–1.9 · `1b` = tasks 1.10–1.17 |
| Cut used for `1b`? | **No** — 626 ≤ 800, landed whole. |
| Cut used for slice 2? | **No** — the slice was complete and green at 862 lines; no code, comment, blank line, doc or test was cut to fit 800. |

**`1a` overage — accepted `size:exception`.** The `1a` work unit exceeded the 800-line budget
by itself (1023 lines). The single largest contributor is the DB-free registry fake
(`tests/Support/IdiomaRegistryFake.php`, 378 lines), which is test infrastructure, not
production code; the remaining `1a` cost is the registry implementation (130 lines) plus the
four test files. Per the chained-PR rules the code/tests/comments were **not** compressed to
fit: one honest slicing pass was already made (`1a`/`1b`). The maintainer **explicitly accepted
a `size:exception` for the `1a` PR** — decision on record: *"the maintainer explicitly accepted
a `size:exception` for the 1a PR (it measured 1023 authored lines vs the 800 budget)"*. No
further split of `1a` is proposed.

**`1b` fits.** 626 authored lines ≤ 800; no `size:exception` is requested for `1b`, and no code,
comment, blank line, doc or test was shrunk to reach the number.

**Slice 2 overage — `size:exception` requested.** Slice 2 measured **862 authored lines** against
the 800 budget (62 over, `862/800`). The overage is concentrated in the two new test files
(529 lines including licence headers, docblocks and the DB-free controller double) — the same
test-infrastructure shape that produced `1a`'s accepted exception. The slice was delivered
**complete and green**; no code, comment, blank line, doc or test was cut or compressed to fit.

One honest slicing pass was evaluated: the only two cohesive units would be
(a) production + management test (≈512 lines) and (b) the permission test (≈350 lines), but
(b) is a pure verification suite for behavior landed in (a), and the work-unit rule keeps a
behavior's tests in the commit that introduces it, while the controller's gates and actions are
atomic inside `gestionarIdioma()` and cannot split by requirement without shipping an ungated
mutation. No cohesive split therefore fits the budget, so the overage is reported for a
`size:exception` (same disposition the maintainer accepted for `1a`). If the maintainer prefers
review units ≤800, promoting `tests/CatalogoIdiomaPermissionTest.php` to its own follow-up
commit is the only available split.

---

## Commits created

| Hash | Message | Files | Authored lines |
|---|---|---|---|
| `661b94c3` | `feat(catalogo_core): enforce language-registry default invariants and delete cleanup` | 8 | +1020 / −3 |
| `ee66bd9c` | `feat(catalogo_core): resolve article descriptions through the configured default language` | 5 | +418 / −48 |
| `c2269c87` | `feat(catalogo_core): clear article descriptions through the model save path` | 2 | +155 / −5 |
| `628c4313` | `feat(catalogo_core): manage catalog languages from the #idiomas section on ventas_articulos` | 7 | +860 / −2 |

`1a` files: `model/core/catalogo_idioma.php`, `Services/CatalogLegacyTableMigration.php`,
`tests/CatalogoIdiomaInvariantsTest.php`, `tests/CatalogoIdiomaDeleteCleanupTest.php`,
`tests/CatalogoIdiomaTest.php`, `tests/Services/CatalogLegacyTableMigrationTest.php`,
`tests/Support/IdiomaRegistryFake.php`, `tests/Support/FakeCatalogoIdioma.php`.

`1b` files (commit `ee66bd9c`): `model/core/articulo.php`, `tests/ArticuloMultiidiomaTest.php`,
`tests/ArticuloDescripcionFrozenBaseTest.php`, `tests/Support/FakeArticulo.php`,
`tests/Support/FakeArticuloDescripcion.php`.
`1b` files (commit `c2269c87`): `model/core/articulo_descripcion.php`,
`tests/ArticuloDescripcionClearingTest.php`.

Slice-2 files (commit `628c4313`): `Controller/VentasArticulos.php`,
`extras/VentasArticulosListTrait.php`, `View/ventas_articulos.html.twig`,
`translations/messages.es_ES.yaml`, `translations/messages.en_EN.yaml`,
`tests/CatalogoIdiomaManagementTest.php`, `tests/CatalogoIdiomaPermissionTest.php`.
The slice-2 SDD bookkeeping (`tasks.md` checkboxes + this artifact) is committed separately.

No push, no PR, no tag, no release. Local commits only. Pre-existing unrelated working-tree
changes in `plugins/catalogo_core` were deliberately **not** staged (see "No-drift").

---

## Deviations from design / tasks

1. **Task 1.17 moved into `1a`** (registry test coverage is cohesive with the registry work unit).
2. **Testability seams (resolved in `1b`).** The design's read-chain / mirror-removal tests needed
   a DB-free seam. `1b` chose the repo's lighter convention: two protected seams on `articulo`
   — `language_registry()` and `description_model()` — plus two small support doubles
   (`FakeArticulo`, `FakeArticuloDescripcion`) over the slice-1a `IdiomaRegistryFake`. This is
   the same pattern `tests/CaracteristicaModelTest.php` already uses (`existing_by_codigo()`).
   The seams are behaviour-preserving: real code still returns `new catalogo_idioma()` /
   `new articulo_descripcion()`. Slice 1a's 378-line `IdiomaRegistryFake.php` was **not** modified.
3. **`1b` ordering: read chain before clearing.** The clearing scenario "a cleared language falls
   back at read time" needs the read chain DB-free, so `1.12–1.16` were implemented before
   `1.10–1.11`. All seven tasks are complete and marked `[x]`.
4. **GDI-08 (`invalidate_search_cache`) was not implemented.** `tasks.md` assigns it to slice
   `4a` (tasks 4a.1–4a.3), so it is out of scope for slice `1b`; the `1a`+`1b` GDI-07 clearing
   path deliberately does **not** invalidate the search cache (that obligation lands in `4a`).
5. **No production mechanism differs from `design.md`.** The read chain follows D-09
   (null/empty resolves `get_effective_default_code()` once; unknown code falls through the
   chain), the mirror removal follows D-10/D1/GDI-06, `get_descripcion_corta_idioma()` follows
   D-08 (same-language only, no fallback), and the clearing semantics follow D-11/D-12
   (`test()` accepts empty; empty pair deletes; `descripcion_corta`-only is preserved as a
   labelled local decision).

### Slice-2 deviations (local decisions, no design change)

6. **`idioma_model()` seam on the list trait (slice 2).** The design did not name a seam for
   the management mutations. The trait gained a `protected function idioma_model()` returning
   `new \FSFramework\model\catalogo_idioma()`, mirroring the existing `tarifa_model()` seam, so
   `load_list_idiomas()` and `gestionarIdioma()` can be exercised DB-free. Behaviour-preserving:
   production still returns the real model. `tests/Support/FakeCatalogoIdioma.php` was **not**
   modified.
7. **Mutation target resolved from the already-loaded registry.** `gestionarIdioma()` reads the
   target's current flags from `$this->idiomas_todos` (loaded by `load_list_idiomas()` before
   dispatch, per task 2.2) instead of re-`get()`-ing the row. This keeps the controller free of
   a second DB round-trip and matches the panel, which renders from the same list. An unknown
   code is a silent no-op — nothing persists, which is the required outcome; the design/spec
   define no "unknown language" message and none was invented.
8. **Slice-2 mutations delegate the invariants to slice 1.** `gestionarIdioma()` and its three
   helpers call `save()` / `set_default()` / `delete()` and never re-implement the
   exactly-one-active-default, deactivate-default, delete-default, last-language or orphan-cleanup
   rules (D-02/D-03, tasks 1.1–1.9). The `language-cannot-*` keys are used as view affordances
   (disabled buttons' tooltips); the model remains the authority for the rejection.
9. **The `#idiomas` panel renders its forms unconditionally.** Design D-06 specifies "one form
   per row and an add form" with the guard per-action, not page-level; hiding the forms from
   non-admins was not specified, and `fsc.user` is not a template contract today. The server
   gate rejects a non-admin POST explicitly, which `CatalogoIdiomaPermissionTest` pins.
10. **The controller docblock avoids the literal `AdminOnly`.** The permission test's source gate
    asserts the string `AdminOnly` is absent from `Controller/VentasArticulos.php`, so the comment
    describing the missing page-level attribute is worded without the literal. This keeps the
    grep gate strict (any future attribute immediately fails the test).

---

## No-drift statements

- **No core `openspec/` entry was created.** `git status --short openspec/` (repository root) is
  empty across this phase.
- **No delta spec was merged.** `plugins/catalogo_core/openspec/specs/**` is untouched
  (`git status --short openspec/specs/` inside the plugin is empty). Merge happens only at archive.
- **No schema change.** `model/table/articulo_descripciones.xml` and
  `model/table/catalogo_idiomas.xml` diffs are empty (D-01 rejects the FK).
- **No core files touched.** Every changed path lives under `plugins/catalogo_core/`.
- **No `#[AdminOnly]` on `ventas_articulos`.** The controller source contains no `AdminOnly`
  token at all (asserted by `CatalogoIdiomaPermissionTest`); the guard is per-action
  (`validateFormToken()` + `empty($this->user->admin)` + POST-only).
- **No language mutation outside `gestionarIdioma()`.** The list page's other POST paths
  (`nuevoArticulo`, `eliminarArticulo`, the Excel actions) are untouched; the language forms post
  `idioma_action`, never `nreferencia`/`delete`.
- **Unrelated pre-existing dirty files were NOT staged.** The slice-2 commits contain only the
  7 explicit paths listed below plus the two SDD bookkeeping files. The working tree still carries
  the unrelated `articulo-detalle-tarifa-unificada` changes
  (`Controller/VentasArticulo.php`, `Init.php`, `View/**`, `controller/tarif_tab_precios.php`,
  `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`,
  `tests/Integration/CatalogoArticuloHookOwnershipTest.php`, `tests/TarifTabPreciosTest.php`,
  the two deleted hook templates, the untracked change dir and the two untracked Controller tests)
  **unstaged and unmodified by this slice**.

---

## Remaining work / next

1. **Slices `1a`, `1b` and `2` are done** — committed and green.
2. **Slice 3 (article editor selector)** is the next independent slice (disjoint files from
   slice 2: the **singular** `Controller/VentasArticulo.php`,
   `View/ventas_articulo.html.twig`, `View/partials/articulos/tab_multiidioma.html.twig`).
3. **Slice `4a`:** GDI-08 cache invalidation (`articulo::invalidate_search_cache()` + callers)
   and the language-agnostic search predicate.
4. **`verify`** must follow this artifact: slices 1 and 2 are delivered in full; GDI-08 is a
   documented `4a` gap, not a slice-1/2 failure. The real-DB boot smoke, the composed-SQL smoke
   and a real-browser POST of the `#idiomas` panel remain for `verify`.
5. **`size:exception` disposition for slice 2** (862 authored lines vs the 800 budget) awaits the
   maintainer, as recorded in "Budget measurement and cut decision".
