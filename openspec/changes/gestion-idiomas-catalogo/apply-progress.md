# Apply Progress: gestion-idiomas-catalogo

| Field | Value |
|---|---|
| **Change** | `gestion-idiomas-catalogo` |
| **SDD owner** | `plugins/catalogo_core/openspec/` — `ownership: plugin-local` |
| **Artifact store** | `openspec` (core `openspec/` received nothing) |
| **Phase** | `apply` |
| **Mode** | **Strict TDD** (`strict_tdd: true`) |
| **Slice** | **1a + 1b (slice 1 complete) + slice 2 complete + slice 3 complete + slice 4a complete + slice 4a-fix (writer gate) complete** |
| **Delivery** | `auto-chain`, `chain_strategy: stacked-to-main`, `review_budget_lines: 800` |
| **Cut boundary used** | **No for `1b`** — 626 authored lines ≤ 800, landed whole. **No cut for slice 2** — 862 authored lines, complete and green; overage reported for `size:exception` (see "Budget measurement"). **No cut for slice 3** — 386 authored lines ≤ 800, landed whole. **No cut for slice 4a** — 861 authored lines, complete and green; overage reported for `size:exception` (see "Budget measurement"). **No cut for slice 4a-fix** — 113 authored lines ≤ 800, landed whole. The pre-declared `1a`/`1b` boundary **was** used for the `1a` PR (1023 lines), which the maintainer accepted as a `size:exception` (see "Budget measurement"). |
| **Status** | **success** — slices 1 (`1a` + `1b`), 2, 3, 4a and 4a-fix complete and green |

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

## Slice-3 task status — Article editor language selector

Delivered whole (tasks 3.1–3.8 all `[x]` in `tasks.md`). The duplicated base
description and the per-language loop are gone; the editor now renders exactly
one description / short-description pair for the selected language.

| Task | Tag(s) | State | Evidence |
|---|---|---|---|
| 3.1 RED — rewrite the `fsc.articulo.descripcion` assertion into a selector contract on the partial + the "base textarea is gone" guard | `[GDI-11, GDI-12; D-05]` | [x] | `tests/VentasArticuloControllerTest.php`; RED first (2 failures), then GREEN |
| 3.2 RED — replace every `sdescripcion` POST with `codidioma` + `descripcion_<cod>`; add the three GDI-11 scenarios | `[GDI-11, ART-01; D-05]` | [x] | `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`; RED first (3 failures), then GREEN |
| 3.3 GREEN — rewrite `tab_multiidioma.html.twig` in place: link selector, hidden `codidioma`, exactly one dynamic pair | `[GDI-11, GDI-12; D-05, D-08]` | [x] | `View/partials/articulos/tab_multiidioma.html.twig`; Twig parse check OK |
| 3.4 GREEN — remove the base `sdescripcion` textarea + hint from the Datos panel | `[GDI-11, GDI-12, ART-05; D-05]` | [x] | `View/ventas_articulo.html.twig`; `#multiidioma`/include/markers byte-unchanged (marker test green) |
| 3.5 GREEN — `public string $codidioma`, `resolve_codidioma()`, set from `loadCatalogData()`, drop `$art->descripcion = sdescripcion` | `[GDI-11; D-05]` | [x] | `Controller/VentasArticulo.php`; unknown/stale codes fall to the effective default |
| 3.6 GREEN — rewrite `saveMultiidiomaDescriptions()` per D-05 | `[GDI-11, ART-01; D-05, D-01]` | [x] | single destination slot; both `continue` branches and the trailing `$art->save()` gone |
| 3.7 VERIFY — `CatalogoCoreHookMarkersTest` green **unedited** | `[GDI-12, ART-05]` | [x] | **12 tests, 68 assertions, OK**; `git status --short` on the test file is empty |
| 3.8 VERIFY — `openspec/specs/**` untouched | `[ART-01, ART-05]` | [x] | `git status --short plugins/catalogo_core/openspec/specs/` is empty |

> **Collateral (documented):** removing `$art->descripcion = sdescripcion` also
> invalidated two `VentasArticuloPerTarifaPaneTest` assertions that used the base
> description as their "other article field persists" exemplar. The exemplar was
> switched to `sobservaciones` (still form-driven) and the harness gained the
> DB-free language seams, preserving the test's intent without weakening it.

---

## Slice-4a task status — Language-agnostic search + cache invalidation

Delivered whole (tasks 4a.1–4a.8 all `[x]` in `tasks.md`). Description writes now
invalidate the search cache through a single entry point, and article text search
matches every language through one isolated predicate.

| Task | Tag(s) | State | Evidence |
|---|---|---|---|
| 4a.1 RED — `tests/ArticuloSearchCacheInvalidationTest.php` (2 GDI-08 scenarios + a direct-delete case + the D-03 writer gate) | `[GDI-08; D-03]` | [x] | 4 tests, RED first (3 failures: cache survived save/delete/clearing; grep gate 0 ≠ 2), then GREEN |
| 4a.2 GREEN — `articulo::invalidate_search_cache()` (public static) + `clean_cache()` delegation | `[GDI-08; D-03]` | [x] | `model/core/articulo.php`; real test cache adapter exercised |
| 4a.3 GREEN — `articulo_descripcion::save()` / `delete()` invalidate; the empty-pair branch invalidates through `delete()` | `[GDI-08; D-03]` | [x] | `model/core/articulo_descripcion.php`; clearing test asserts the cached key is gone |
| 4a.4 RED — `tests/ArticuloSearchMultiidiomaTest.php` (3 GDI-09 scenarios incl. the predicate grep gate) | `[GDI-09; D-04]` | [x] | 3 tests, RED first (2 failures + 2 errors: predicate/join undefined; the term was unfindable), then GREEN |
| 4a.5 GREEN — `languageDescriptionPredicate()` + `languageDescriptionJoin()`; every base reference `a.`-qualified; all three text paths share the helper | `[GDI-09; D-04, D11]` | [x] | `Services/ArticuloSearchQueryBuilder.php`; the D11 note is verbatim inline |
| 4a.6 GREEN — `search()` emits `SELECT DISTINCT a.* FROM articulos a` + join + `ORDER BY a.referencia ASC`; `buildSearchWhereClause()` qualifies family/fabricante/stock/bloqueado | `[GDI-09; D-04]` | [x] | `model/core/articulo.php`; collation rationale commented |
| 4a.7 RED → GREEN — `tests/Services/ArticuloSearchQueryBuilderTest.php` updated to `a.`-qualified expectations + predicate/join/ambiguity assertions | `[GDI-09; D-04]` | [x] | 9 tests, RED first (11 failures + 2 errors across the three files), then GREEN |
| 4a.8 VERIFY — D-03 writer gate encoded as a durable test (allowlist: the description model, the language-delete cleanup, the activation migration) | `[GDI-08; D-03]` | [x] | passes; the design's literal "only `CatalogLegacyTableMigration` elsewhere" is incomplete — see deviation 16 |

> **Discovered gap (documented, not silently fixed):** `catalogo_idioma::delete()`
> (slice 1a) removes the language's `articulo_descripciones` rows with raw SQL and
> does **not** invalidate the search cache. GDI-08's letter ("every write that
> deletes a row") therefore has one uncovered writer outside
> `articulo_descripcion`. It is out of the declared slice-4a scope (task 4a.3 names
> only the description model's `save()`/`delete()`), so it is reported here and in
> "Deviations" rather than fixed silently. See deviation 16.

---

## Slice-4a-fix task status — Close the `catalogo_idioma::delete()` writer gap (GDI-08)

Follow-up work unit `slice-4a-fix-writer-gate`, applied on top of slice 4a after the gap
reported in deviation 16 was reviewed. The interrupted first run left the change written
but unverified; this run inspected the pending diff, verbatim, **without rewriting it**,
proved it with tests, closed the gate and committed it. Task `4a.8` is annotated in
`tasks.md`; the gate is now honest instead of permissive.

### The gap (restated)

Requirement **GDI-08** says *every* write that creates, updates or deletes an
`articulo_descripciones` row MUST invalidate the `articulos_search_*` cache family.
Slice 4a closed the description-model writers (`articulo_descripcion::save()` /
`delete()`) but `catalogo_idioma::delete()` (shipped in slice 1a for GDI-03) deletes
the language's description rows with raw SQL and never invalidated the cache. A stale
`articulos_search_<tag>` entry could therefore keep returning a match for a language
that no longer existed.

### Design root cause (D-03)

D-03's proof was *"the only remaining writer is the legacy→canonical copy in
`CatalogLegacyTableMigration`"* (design.md:169). That claim assumed a **single writer
file** — `articulo_descripcion` — and never accounted for slice 1a's raw-SQL cleanup.
The design decision is sound for its named callers; its **proof** was incomplete. The
fix therefore does not change D-03's mechanism (still one public static
`articulo::invalidate_search_cache()` entry point) — it corrects the enumeration of
writers and makes the gate encode the corrected enumeration. This is a design-defect
correction, not a mechanism change.

### The fix

`catalogo_idioma::delete()` (`model/core/catalogo_idioma.php`):

- A cheap `SELECT 1 FROM articulo_descripciones WHERE codidioma = <code> LIMIT 1`
  pre-check runs **before** the transaction and records whether the language owns
  description rows.
- After `commit()` succeeds and only when the pre-check saw rows, the method calls
  `articulo::invalidate_search_cache()` (the slice-4a entry point, commit `1df1a7eb`).
- The pre-check cannot make a real write skip invalidation: the DELETE and the
  pre-check use the same `var2str($this->codidioma)` value, and `normalize_default()`
  only touches `catalogo_idiomas`, never `articulo_descripciones` — so the explicit
  DELETE is the only description-row write in the method. A rolled-back delete wrote
  nothing and correctly does not invalidate.
- `articulo` resolves to `FSFramework\model\articulo` (same namespace as
  `catalogo_idioma`); `base/fs_model_autoloader.php` maps `FSFramework\model\*` to the
  plugin's `model/core/` directory. The call is static, so it never constructs
  `articulo` and never triggers the lazy schema path.

### Tests

| Test | Tag(s) | State | Evidence |
|---|---|---|---|
| `ArticuloSearchCacheInvalidationTest::test_language_delete_invalidates_the_cache` | `[GDI-08; D-03]` | [x] | Seeds a cached tag + `en` language owning one description row; asserts the cache key is gone after `delete()`. **RED without the fix**, GREEN with it. |
| `ArticuloSearchCacheInvalidationTest::test_language_delete_without_descriptions_keeps_the_cache` | `[GDI-08; D-03]` | [x] | Seeds the same language with **no** description rows; asserts the cache survives. **RED with an unconditional invalidate**, GREEN with the guard. |
| `ArticuloSearchCacheInvalidationTest::test_every_description_writer_invalidates_or_is_explicitly_exempt` (gate) | `[GDI-08; D-03]` | [x] | Split the old single allowlist into `$invalidating` (must call the entry point) and `$exempt` (the activation migration, exempt by explicit reviewed decision). Asserts the call exists in every `$invalidating` file. **RED if a request-path writer loses the call.** |
| `CatalogoIdiomaDeleteCleanupTest::setUp` | `[GDI-03]` | [x] | Loads `articulo.php` so the delete path can reach the invalidator. |
| `IdiomaRegistryFake::select` | — | [x] | Recognises the new `SELECT 1 ... LIMIT 1` pre-check. |

### Non-tautology proof (mutation checks, all temporary and reverted)

| Mutation | Result |
|---|---|
| Remove `if ($hasDescriptions) { articulo::invalidate_search_cache(); }` from `catalogo_idioma::delete()` | `--filter ArticuloSearchCacheInvalidationTest` → **6 tests, 2 failures**: `test_language_delete_invalidates_the_cache` and the gate. Both tests have teeth. |
| Make the invalidate unconditional (`if (true)`) | **6 tests, 1 failure**: `test_language_delete_without_descriptions_keeps_the_cache`. The guard test has teeth. |
| Add a new plugin file containing `DELETE FROM articulo_descripciones ...` | **6 tests, 1 failure** — the gate reports `DML against articulo_descripciones escaped the allowlist: model/core/__probe_writer.php`. The offender scan has teeth. |

The three mutations were applied one at a time to the working tree, observed, and
reverted from a byte-for-byte backup; the committed bytes are the pending diff exactly.

### Files

| File | Action | What Was Done |
|---|---|---|
| `model/core/catalogo_idioma.php` | Modified | `LIMIT 1` pre-check + post-commit conditional `articulo::invalidate_search_cache()` (+17) |
| `tests/ArticuloSearchCacheInvalidationTest.php` | Modified | Two language-delete scenarios + the split invalidating/exempt writer gate (+75 / −4) |
| `tests/CatalogoIdiomaDeleteCleanupTest.php` | Modified | `require_once` of `articulo.php` on the delete path (+4) |
| `tests/Support/IdiomaRegistryFake.php` | Modified | Pre-check SQL branch (+13) |

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
| 3.1 / 3.3 / 3.4 | focused run over the three files → **2 failures** (partial had no selector; view still rendered `name="sdescripcion"`) | same run → green | — |
| 3.2 / 3.5 / 3.6 | focused run → **3 failures** (`descripcion_es` overwritten by `$art->descripcion`; `descripcion_es` injected into the `en` slot; the empty pair left the row) | same run → **48 tests, 200 assertions, OK** | — |
| 4a.1 / 4a.2 / 4a.3 | focused run over the three new/updated files → **16 tests, 24 assertions, 2 errors + 11 failures**: `languageDescriptionPredicate`/`languageDescriptionJoin` undefined, the cached `articulos_search_*` key survived every description write, the term in the `en` row was unfindable, the writer gate counted 0 invalidations | same run → **16 tests, 65 assertions, OK** | — |
| 4a-fix (gate) | The interrupted first run left the tests and the fix uncommitted together; RED was re-established by removing the invalidate call from `catalogo_idioma::delete()` → **6 tests, 2 failures** (`test_language_delete_invalidates_the_cache` + the gate) | restored bytes → `--filter ArticuloSearchCacheInvalidationTest` **6 tests, 21 assertions, OK** | None — the pending bytes were kept verbatim; only the gate's allowlist split was already present in the pending diff and was reviewed, not rewritten |

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

## Work Unit Evidence (slice 3)

### Work unit `3` — selector-driven article editor descriptions

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/VentasArticuloControllerTest.php plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php` → **OK (48 tests, 200 assertions)**; RED first was **5 failures** across the two locked files |
| **Runtime harness command/scenario and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **OK — 856 tests, 3753 assertions, 2 warnings, 1 skipped** (baseline before slice 3: 852 tests, 3741 assertions; +4 tests, no regressions). The HTTP boundary is exercised DB-free: `Request::create(..., 'POST', $fields)` + the `IdiomaRegistryFake` / `FakeCatalogoIdioma` / `FakeArticuloDescripcion` doubles, with the real `editarArticulo()` / `saveMultiidiomaDescriptions()` / `resolve_codidioma()` bodies executing through the seams. A real-browser POST of the selector is deferred to `verify`. |
| **Rollback boundary** | Revert the slice's commits: `Controller/VentasArticulo.php` (`$codidioma`, `idioma_model()`, `suppliedCodidioma()`, `resolve_codidioma()`, the `loadCatalogData()` assignment, the `sdescripcion` removal and the rewritten `saveMultiidiomaDescriptions()`), `View/partials/articulos/tab_multiidioma.html.twig`, `View/ventas_articulo.html.twig` (the removed Datos row), the two `translations/messages.*.yaml` key removals and the three test files. No model, schema, search, Excel, consumer or `tarifario` file is touched. Clear the Twig cache after reverting the views. |

---

## Work Unit Evidence (slice 4a)

### Work unit `4a-cache` — search-cache invalidation on every description write (GDI-08)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/ArticuloSearchCacheInvalidationTest.php` → **OK (4 tests, 18 assertions)**; RED first was **3 failures** (the cached key survived the non-default write, the clearing path and the direct delete; the writer gate counted 0 invalidations) |
| **Runtime harness command/scenario and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **OK — 866 tests, 3806 assertions, 2 warnings, 1 skipped** (baseline before slice 4a: 856 tests, 3753 assertions; +10 tests, no regressions). The cache boundary is exercised against the **real** `fs_cache` adapter (Symfony `CacheManager`), with `articulo::$search_tags`/`$cleaned_cache` reset by Reflection. |
| **Rollback boundary** | Revert commit `1df1a7eb`: `model/core/articulo.php` (`invalidate_search_cache()` + the `clean_cache()` delegation), `model/core/articulo_descripcion.php` (the two invalidator calls) and `tests/ArticuloSearchCacheInvalidationTest.php`. No schema, view, search-predicate or consumer file is touched; reverting only re-introduces stale `articulos_search_*` entries — search itself keeps working. |

### Work unit `4a-search` — language-agnostic article search (GDI-09)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/ArticuloSearchMultiidiomaTest.php plugins/catalogo_core/tests/Services/ArticuloSearchQueryBuilderTest.php` → **OK (12 tests, 47 assertions)**; RED first was **2 errors + 8 failures** (`languageDescriptionPredicate`/`languageDescriptionJoin` undefined; the term was unfindable; every expectation was unqualified) |
| **Runtime harness command/scenario and exact result** | Included in the slice run above: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **OK — 866 tests, 3806 assertions, 2 warnings, 1 skipped**. The search boundary is exercised DB-free through `SearchCatalogFake` (records the emitted SQL and evaluates its description predicate against seeded rows), `SearchCacheFake` and the `make_articulo()` seam on `FakeSearchArticulo`. A real-DB composed-SQL smoke remains for `verify`. |
| **Rollback boundary** | Revert commit `210a80bd`: `Services/ArticuloSearchQueryBuilder.php` (the two helpers + the `a.` qualification), `model/core/articulo.php` (`search()` alias/join/DISTINCT/ORDER BY, `buildSearchWhereClause()` qualification, the `make_articulo()` seam), `tests/ArticuloSearchMultiidiomaTest.php`, `tests/Services/ArticuloSearchQueryBuilderTest.php` and the three `tests/Support/` fakes. The invalidator from `4a-cache` is independent and stays; `ORDER BY lower(referencia)` returns with the revert. No schema, view, Excel, API or consumer file is touched. |

### Work unit `4a-fix` — `catalogo_idioma::delete()` closes the last GDI-08 writer gap (D-03)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter ArticuloSearchCacheInvalidationTest` → **OK (6 tests, 21 assertions)**; RED re-established by removing the invalidate call from `catalogo_idioma::delete()` → **2 failures** (`test_language_delete_invalidates_the_cache`, the writer gate), and by making it unconditional → **1 failure** (`test_language_delete_without_descriptions_keeps_the_cache`) |
| **Runtime harness command/scenario and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **OK — 868 tests, 3814 assertions, 2 warnings, 1 skipped** (baseline before this fix: 866 tests, 3806 assertions; +2 tests, no regressions). The cache boundary is exercised against the **real** `fs_cache` adapter (Symfony `CacheManager`); the delete path executes the real `catalogo_idioma::delete()` body through `FakeCatalogoIdioma` + `IdiomaRegistryFake`. Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins` → 2102 tests, **8 failures, all pre-existing in the untouched `OidcProvider` plugin; no `catalogo_core` test failed**. |
| **Rollback boundary** | Revert this fix's commit: `model/core/catalogo_idioma.php` (the `LIMIT 1` pre-check + the post-commit conditional call), `tests/ArticuloSearchCacheInvalidationTest.php`, `tests/CatalogoIdiomaDeleteCleanupTest.php` and `tests/Support/IdiomaRegistryFake.php`. Reverting only re-introduces the stale `articulos_search_*` entries for a deleted language; search itself keeps working, and no other slice depends on the fix. No schema, view, controller, Excel, API or consumer file is touched. |

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
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (after slice 3) | **OK** — **856 tests, 3753 assertions, 2 warnings, 1 skipped**. Slice 3 adds 4 tests (`VentasArticuloControllerTest` +1 net, `VentasArticuloArticleEditAbsorptionTest` +3). No failures, no regressions. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoCoreHookMarkersTest` (after slice 3) | **OK — 12 tests, 68 assertions**, test file **unedited** (`git status --short` empty). |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root, regression, after slice 3) | **FAILED (pre-existing, unrelated)** — 2071 tests, 8207 assertions, **7 failures**, 1 warning, 46 skipped. All 7 failures are in the untouched `OidcProvider` plugin (`OidcRegisterControllerMinimalClienteTest` ×1, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` ×4). **No `catalogo_core` test failed.** |
| `ddev exec composer phpstan` (after slice 3) | **FAILED (pre-existing, unrelated)** — the same single `tests/Core/PluginEnableAjaxSafetyTest.php:308` error; no slice-3 file is analysed by this config. |
| Twig parse check (slice 3) | `View/partials/articulos/tab_multiidioma.html.twig` tokenizes + parses with a stubbed `trans` filter → **TWIG PARSE OK**. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (after slice 4a) | **OK** — **866 tests, 3806 assertions, 2 warnings, 1 skipped**. Slice 4a adds 10 tests: `ArticuloSearchCacheInvalidationTest` +4 (new), `ArticuloSearchMultiidiomaTest` +3 (new), `ArticuloSearchQueryBuilderTest` +3 (2 new + net from the rewrite). No failures, no regressions. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoCoreHookMarkersTest` (after slice 4a) | **OK — 12 tests, 68 assertions**, test file **unmodified** (`git status --short` empty). |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root, regression, after slice 4a) | **FAILED (pre-existing, unrelated)** — 2088 tests, 8288 assertions, **7 failures**, 1 warning, 46 skipped. All 7 failures are in the untouched `OidcProvider` plugin (`OidcRegisterControllerMinimalClienteTest` ×1, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` ×4). **No `catalogo_core` test failed.** |
| `ddev exec composer phpstan` (after slice 4a) | **FAILED (pre-existing, unrelated)** — the same single `tests/Core/PluginEnableAjaxSafetyTest.php:308` error; phpstan paths are `src` and root `tests` only, so no slice-4a file is analysed by this config. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (after slice 4a-fix) | **OK** — **868 tests, 3814 assertions, 2 warnings, 1 skipped**. The fix adds 2 tests to `ArticuloSearchCacheInvalidationTest`. No failures, no regressions. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoCoreHookMarkersTest` (after slice 4a-fix) | **OK — 12 tests, 68 assertions**, test file **unmodified** (`git status --short` empty). |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root, regression, after slice 4a-fix) | **FAILED (pre-existing, unrelated)** — 2102 tests, 8353 assertions, **8 failures**, 1 warning, 46 skipped. All 8 failures are in the untouched `OidcProvider` plugin (`OidcRegisterControllerMinimalClienteTest` ×2, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` ×4); the count is order/state-sensitive in that plugin (an adjacent run reported 7 with the same failure set). **No `catalogo_core` test failed.** |
| `ddev exec composer phpstan` (after slice 4a-fix) | **Not re-run** — no `catalogo_core` file is analysed by this config (`src` + root `tests` only); the single pre-existing `tests/Core/PluginEnableAjaxSafetyTest.php:308` error is unchanged and unowned by this work unit. |

---

## Budget measurement and cut decision

| Item | Value |
|---|---|
| Session budget (`review_budget_lines`) | **800** |
| `1a` authored changed lines (additions + deletions) | **1023** (`git show --stat 661b94c3`: 1020 insertions + 3 deletions) |
| `1b` authored changed lines (additions + deletions) | **626** (`git show --stat ee66bd9c` = 418 + 48; `git show --stat c2269c87` = 155 + 5) |
| Slice 1 total authored lines | **1649** across `1a` (1023) + `1b` (626) |
| Slice 2 authored changed lines (additions + deletions) | **862** — `Controller/VentasArticulos.php` +150, `View/ventas_articulos.html.twig` +123, `extras/VentasArticulosListTrait.php` +22/−2, `translations/messages.en_EN.yaml` +18, `translations/messages.es_ES.yaml` +18, `tests/CatalogoIdiomaManagementTest.php` +179 (new), `tests/CatalogoIdiomaPermissionTest.php` +350 (new) |
| Slice 3 authored changed lines (additions + deletions) | **386** — `Controller/VentasArticulo.php` +71/−30, `View/partials/articulos/tab_multiidioma.html.twig` +24/−22, `View/ventas_articulo.html.twig` +0/−11, `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` +141/−5, `tests/Controller/VentasArticuloPerTarifaPaneTest.php` +30/−4, `tests/VentasArticuloControllerTest.php` +42/−4, `translations/messages.en_EN.yaml` +0/−1, `translations/messages.es_ES.yaml` +0/−1 |
| Slice 4a authored changed lines (additions + deletions) | **861** — production: `Services/ArticuloSearchQueryBuilder.php` +56/−17, `model/core/articulo.php` +77/−27, `model/core/articulo_descripcion.php` +15/−1. Tests: `tests/ArticuloSearchCacheInvalidationTest.php` +217 (new), `tests/ArticuloSearchMultiidiomaTest.php` +123 (new), `tests/Services/ArticuloSearchQueryBuilderTest.php` +44/−7, `tests/Support/SearchCatalogFake.php` +217 (new), `tests/Support/SearchCacheFake.php` +52 (new), `tests/Support/FakeSearchArticulo.php` +41 (new) |
| Pre-declared boundary | `1a` = tasks 1.1–1.9 · `1b` = tasks 1.10–1.17 |
| Cut used for `1b`? | **No** — 626 ≤ 800, landed whole. |
| Cut used for slice 2? | **No** — the slice was complete and green at 862 lines; no code, comment, blank line, doc or test was cut to fit 800. |
| Cut used for slice 3? | **No** — 386 ≤ 800 and inside the 360–520 estimate, landed whole. |
| Cut used for slice 4a? | **No** — the slice was complete and green at 861 lines; no code, comment, blank line, doc or test was cut to fit 800. |
| Slice 4a-fix authored changed lines (additions + deletions) | **113** — `model/core/catalogo_idioma.php` +17, `tests/ArticuloSearchCacheInvalidationTest.php` +75/−4, `tests/CatalogoIdiomaDeleteCleanupTest.php` +4, `tests/Support/IdiomaRegistryFake.php` +13 |
| Cut used for slice 4a-fix? | **No** — 113 ≤ 800, landed whole. |

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

**Slice 3 fits.** 386 authored lines ≤ 800 and inside the 360–520 estimate; no `size:exception`
is requested, and no code, comment, blank line, doc or test was cut or compressed to fit.

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

**Slice 4a overage — `size:exception` requested.** Slice 4a measured **861 authored lines**
against the 800 budget (61 over, `861/800`), essentially the same disposition as slice 2
(862). The overage is concentrated in test infrastructure: the three new `tests/Support/`
fakes (310 lines) plus the two new test files (340 lines) = 650 of the 861; production code
is only 211 lines across three files, inside the 480–620 slice estimate for the production
side. The slice was delivered **complete and green**; no code, comment, blank line, doc or
test was cut or compressed to fit.

One honest slicing pass was evaluated and **was** applied for reviewability: the two
deliverables are independent work units and were committed separately
(`1df1a7eb` cache invalidation, `210a80bd` language-agnostic search), so each commit is a
reviewable unit. The two units were **not** promoted to separate PR slices because
`tasks.md` defines slice 4a as one slice and slice 4b depends on 4a's D-03 being in place;
splitting them would also split the shared `model/core/articulo.php` file mid-slice. No
cohesive split therefore brings the slice under 800 without deleting test coverage, so the
overage is reported for a `size:exception` (same disposition the maintainer accepted for
`1a` and that slice 2 requests).

**Slice 4a-fix fits.** 113 authored lines ≤ 800; the follow-up closes the gap that slice 4a
reported and is one cohesive work unit (production + its tests, committed together). No
`size:exception` is requested, and no code, comment, blank line, doc or test was cut or
compressed to fit.

---


## Commits created

| Hash | Message | Files | Authored lines |
|---|---|---|---|
| `661b94c3` | `feat(catalogo_core): enforce language-registry default invariants and delete cleanup` | 8 | +1020 / −3 |
| `ee66bd9c` | `feat(catalogo_core): resolve article descriptions through the configured default language` | 5 | +418 / −48 |
| `c2269c87` | `feat(catalogo_core): clear article descriptions through the model save path` | 2 | +155 / −5 |
| `628c4313` | `feat(catalogo_core): manage catalog languages from the #idiomas section on ventas_articulos` | 7 | +860 / −2 |
| `54f15e5a` | `feat(catalogo_core): drive the article editor descriptions from a language selector` | 8 | +308 / −78 |
| `1df1a7eb` | `feat(catalogo_core): invalidate the article search cache on every description write` | 3 | +257 / −9 |
| `210a80bd` | `feat(catalogo_core): search articles across all description languages` | 7 | +560 / −35 |
| `c1420c36` | `fix(catalogo_core): invalidate the search cache when a language is deleted` | 4 | +109 / −4 |

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

Slice-3 files (commit `54f15e5a`): `Controller/VentasArticulo.php`,
`View/partials/articulos/tab_multiidioma.html.twig`, `View/ventas_articulo.html.twig`,
`translations/messages.es_ES.yaml`, `translations/messages.en_EN.yaml`,
`tests/VentasArticuloControllerTest.php`,
`tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`,
`tests/Controller/VentasArticuloPerTarifaPaneTest.php`.
The slice-3 SDD bookkeeping (`tasks.md` checkboxes + this artifact) is committed separately.

Slice-4a files (commit `1df1a7eb`): `model/core/articulo.php` (hunk 1 only),
`model/core/articulo_descripcion.php`, `tests/ArticuloSearchCacheInvalidationTest.php`.
Slice-4a files (commit `210a80bd`): `Services/ArticuloSearchQueryBuilder.php`,
`model/core/articulo.php` (hunks 2–4), `tests/Services/ArticuloSearchQueryBuilderTest.php`,
`tests/ArticuloSearchMultiidiomaTest.php`, `tests/Support/SearchCatalogFake.php`,
`tests/Support/SearchCacheFake.php`, `tests/Support/FakeSearchArticulo.php`.
The slice-4a SDD bookkeeping (`tasks.md` checkboxes + this artifact) is committed separately.

Slice-4a-fix files (commit `c1420c36`): `model/core/catalogo_idioma.php`,
`tests/ArticuloSearchCacheInvalidationTest.php`, `tests/CatalogoIdiomaDeleteCleanupTest.php`,
`tests/Support/IdiomaRegistryFake.php`.
The slice-4a-fix SDD bookkeeping (`tasks.md` annotation + this artifact) is committed separately.

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

### Slice-3 deviations (local decisions, no design change)

11. **Line numbers in `explore.md` / `design.md` / `tasks.md` were stale.** The preceding
    `articulo-detalle-tarifa-unificada` change (commit `1a643357`) rewrote the same files. All
    slice-3 work was driven from the current bytes: the removed Datos row is at
    `View/ventas_articulo.html.twig:117-127` (not `:121-124`), the `#multiidioma` include is at
    `:342`, the two frozen markers at `:90` and `:384`, and the rewritten method was at
    `Controller/VentasArticulo.php:824-859`. Behaviour and contracts matched the artifacts; only
    the offsets drifted.
12. **`resolve_codidioma()` validates against `$this->idiomas` (the already-loaded active set)
    and falls back to `$this->idioma_model()->get_effective_default_code()`.** `$this->idiomas`
    *is* `all_activos()` (loaded by `loadCatalogData()` immediately before), so this is the
    design's "validate against `all_activos()`" without a second query. A new protected
    `idioma_model()` seam (mirroring `articulo_model()` / `tarifa_model()`) makes it DB-free
    testable; production still returns the real model.
13. **`VentasArticuloPerTarifaPaneTest` was updated as collateral.** Removing
    `$art->descripcion = sdescripcion` invalidated two assertions that used the base description
    as their "other article field persists" exemplar. The exemplar moved to `sobservaciones`
    (still form-driven) and the harness gained the DB-free language seams
    (`language_registry()`, `description_model()`, `idioma_model()`), preserving the tests'
    intent. This file was not in the locked list but the ART-01 change necessarily affected it;
    no assertion was weakened or deleted.
14. **The dead `description-default-language-hint` key was removed** from both translation files:
    its only consumer was the removed hint. No new key was needed — the selector reuses
    `article-multi-language`, `default-language`, `description`, `short-description` and
    `no-languages-configured`.

### Slice-4a deviations (local decisions, no design change)

15. **The `make_articulo()` seam on `articulo` (slice 4a).** The design did not name a seam for
    the search path, but `all_from()` hard-coded `new \articulo($data)`, which triggers the lazy
    schema path (a live-database constructor) and makes `search()` untestable DB-free. A
    protected `make_articulo(array $data)` returning `new \articulo($data)` was extracted and
    used by `all_from()`; production behaviour is byte-identical, and `FakeSearchArticulo`
    overrides it. This mirrors the slice-1b `language_registry()` / `description_model()` seams.
16. **The D-03 writer gate is documented as an allowlist, not the design's literal claim.** Task
    4a.8 and design D-03 assert that `CatalogLegacyTableMigration` is the only writer of
    `articulo_descripciones` outside `articulo_descripcion`. That is factually incomplete:
    `catalogo_idioma::delete()` (added in slice 1a for GDI-03) deletes the language's description
    rows with raw SQL. The durable gate therefore allows the description model, the language-delete
    cleanup and the activation migration, and asserts that `articulo_descripcion::save()` /
    `delete()` both invalidate. The `catalogo_idioma::delete()` write **does not invalidate the
    search cache** — a real gap against GDI-08's letter, out of the declared slice-4a caller list
    (task 4a.3 names only the description model), reported for `verify`/a follow-up rather than
    silently patched or silently ignored. **Closed by slice 4a-fix** — see deviation 19 and the
    "Slice-4a-fix task status" section.
17. **The `languageDescriptionPredicate()` `$quote` parameter is accepted but unused.** The
    interface contract fixes the signature `(string $like, callable $quote)`; the LIKE literal is
    quoted by the caller, so the helper only concatenates it. It is documented as intentional
    symmetry with the builder's other condition callbacks rather than removed (which would break
    the contract).
18. **Numeric queries now lower-case the description leg.** Routing the numeric path through the
    shared predicate adds `lower()` to its description match. The previous numeric path used a
    case-sensitive `descripcion LIKE`; on MySQL's default collation `lower()` is a no-op, and on
    PostgreSQL it makes the numeric-query description match case-insensitive like the other two
    paths. This is required to keep exactly one language-agnostic predicate (GDI-09's grep gate).

### Slice-4a-fix deviations (design-defect correction, no mechanism change)

19. **D-03's proof was incomplete; the fix corrects the enumeration, not the mechanism.** D-03
    claimed `CatalogLegacyTableMigration` was "the only remaining writer" outside
    `articulo_descripcion` (design.md:169), assuming a **single** writer file and missing slice
    1a's raw-SQL cleanup in `catalogo_idioma::delete()`. The fix keeps D-03's single public
    static entry point (`articulo::invalidate_search_cache()`) and adds the missing caller, then
    makes the durable gate encode the corrected writer enumeration: `$invalidating` (writers that
    MUST call the entry point) versus `$exempt` (the activation-time migration, exempt by explicit
    reviewed decision). This is the honest reading of task 4a.8 — the earlier single allowlist
    silently *permitted* the `catalogo_idioma.php` writer without asserting invalidation.
20. **The invalidate call is conditional and post-commit.** `delete()` issues a cheap
    `SELECT 1 ... LIMIT 1` before the transaction and invalidates after a successful `commit()`
    only when that pre-check saw description rows. The pre-check cannot make a real write skip
    invalidation: both statements use the same `var2str($this->codidioma)` and
    `normalize_default()` never touches `articulo_descripciones`. The two dynamic tests pin both
    branches; a mutation check (unconditional invalidate) fails the "keeps the cache" scenario,
    proving the guard is real rather than decorative.
21. **The durable gate is structural, not behavioral — and it is now labelled as such.** The
    `$invalidating` loop is a source-text assertion (`assertStringContainsString`); the behavioral
    proof for the two known writers is the dynamic cache tests. It would not detect a call that
    was commented out while the literal text survived, and `$exempt` remains a deliberate
    human-reviewed list. Neither gap weakens GDI-08 today: mutation checks confirm the gate fails
    when the call is removed and when a new writer file is added. This is recorded so the gate is
    not mistaken for a stronger guarantee than it provides.

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
  **unstaged and unmodified by this slice**. (That pending work was committed as `1a643357` before
  slice 3 started, so slice 3 built on a clean tree.)
- **Slice-3 no-drift.** `git status --short plugins/catalogo_core/openspec/specs/` is empty (no
  delta merged) and the core `openspec/` has no entry for this change. The slice-3 commit contains
  only the 8 explicit paths listed below. `tests/Integration/CatalogoCoreHookMarkersTest.php` is
  **unmodified** and green.
- **Slice-4a no-drift.** `git status --short plugins/catalogo_core/openspec/specs/` is empty (no
  delta merged) and the repository-root `openspec/` still has no entry for this change. The two
  slice-4a commits contain only the explicit paths listed in "Commits created" (the second one
  staged the remaining `articulo.php` hunks with `git add -p` for the first). No schema file
  (`model/table/articulo_descripciones.xml`, `model/table/catalogo_idiomas.xml`) was touched; no
  view, controller, Excel, API or consumer file was touched; no new Composer dependency was added.
- **Slice-4a-fix no-drift.** `git status --short plugins/catalogo_core/openspec/specs/` is empty (no
  delta merged) and the repository-root `openspec/` still has no entry for this change. The fix's
  commit contains only the four explicit paths listed in "Commits created"; no schema, view,
  controller, search-predicate, Excel, API or consumer file was touched; no new Composer
  dependency was added.

---

## Remaining work / next

1. **Slices `1a`, `1b`, `2`, `3`, `4a` and `4a-fix` are done** — committed and green.
2. **Slice `4b`** (Excel export/import with per-language columns) is next; it depends on slice 1's
   `set_descripcion_idioma()` and on slice 4a's D-03 for the per-row invalidation claim.
3. **Slice `4c`** (`catalogo_core` no-context consumers) is independent and can run in parallel with
   4b after slice 1.
4. **`verify`** must follow this artifact: slices 1, 2, 3, 4a and 4a-fix are delivered in full. The real-DB
   boot smoke, a real-DB composed-SQL smoke of `articulo::search()` (the D-04 join + `DISTINCT` +
   `ORDER BY a.referencia` against MySQL and PostgreSQL), a real-browser POST of the `#idiomas`
   panel and a real-browser selector round trip (`codidioma` GET → edit → POST → reload) remain for
   `verify`. The `catalogo_idioma::delete()` cache-invalidation gap (deviation 16) is **closed** by
   slice 4a-fix (deviation 19); `verify` should confirm it with a real-DB delete-and-search smoke.
5. **`size:exception` disposition for slice 2** (862 authored lines vs the 800 budget) awaits the
   maintainer, as recorded in "Budget measurement and cut decision".
6. **`size:exception` disposition for slice 4a** (861 authored lines vs the 800 budget) awaits the
   maintainer, as recorded in "Budget measurement and cut decision".
