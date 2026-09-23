# Apply Progress: gestion-idiomas-catalogo

| Field | Value |
|---|---|
| **Change** | `gestion-idiomas-catalogo` |
| **SDD owner** | `plugins/catalogo_core/openspec/` — `ownership: plugin-local` |
| **Artifact store** | `openspec` (core `openspec/` received nothing) |
| **Phase** | `apply` |
| **Mode** | **Strict TDD** (`strict_tdd: true`) |
| **Slice** | **1a + 1b (slice 1 complete) + slice 2 complete + slice 3 complete + slice 4a complete + slice 4a-fix (writer gate) complete + slice 4b complete (`4b₁` export + `4b₂` import) + slice 4b-wiring complete (task 4b.7 + the controller pass-through) + slice 4b-mapping complete (server `field_options` in the mapping dropdown) + slice 4c complete (4c.1–4c.4 + 4c.6) + slice 4c5 complete (trait `codidioma` resolution; the previously deferred 4c.5) + slice 5 complete (the `tarifario` consumer migration, the last slice)** |
| **Delivery** | `auto-chain`, `chain_strategy: stacked-to-main`, `review_budget_lines: 800` |
| **Cut boundary used** | **No for `1b`** — 626 authored lines ≤ 800, landed whole. **No cut for slice 2** — 862 authored lines, complete and green; overage reported for `size:exception` (see "Budget measurement"). **No cut for slice 3** — 386 authored lines ≤ 800, landed whole. **No cut for slice 4a** — 861 authored lines, complete and green; overage reported for `size:exception` (see "Budget measurement"). **No cut for slice 4a-fix** — 113 authored lines ≤ 800, landed whole. **Slice 4b used the pre-declared `4b₁`/`4b₂` boundary** — the whole slice measured 853 authored lines (> 800), so the export unit (`4b₁`, 144) and the import unit (`4b₂`, 709) landed as two review units, each ≤ 800. The pre-declared `1a`/`1b` boundary **was** used for the `1a` PR (1023 lines), which the maintainer accepted as a `size:exception` (see "Budget measurement"). **No cut for slice 4c** — 511 authored lines ≤ 800, landed whole as one review unit. **No cut for slice 4c5** — 118 authored lines ≤ 800, landed whole. **No cut for slice 5** — 491 authored lines ≤ 800, landed whole as four review units (one per work unit). |
| **Status** | **success** — slices 1 (`1a` + `1b`), 2, 3, 4a, 4a-fix, 4b, 4b-wiring, 4b-mapping, 4c, 4c5 and **5** complete and green; every task in `tasks.md` is `[x]` (including `4c.5`, closed by the `slice-4c5` follow-up, and the nine slice-5 tasks). **All apply slices are delivered; the change is ready for `verify`.** |

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

## Slice-4b task status — Excel export/import with per-language columns

Delivered at the pre-declared `4b₁`/`4b₂` boundary (853 authored lines > the 800
budget). The export appends one `descripcion_<codidioma>` and one
`descripcion_corta_<codidioma>` column per active language after the byte-identical
base headers, and the import targets an explicit language whose destination rule is
the design's literal reading (the suffix qualifies importability, the target decides
the destination). Unknown or deactivated locale suffixes are ignored and never
create or mutate a `catalogo_idiomas` row.

| Task | Tag(s) | State | Evidence |
|---|---|---|---|
| 4b.1 RED — `tests/Services/ArticuloExcelIdiomasTest.php` (3 Export Excel scenarios + default-first order + GDI-10 base cell) | `[Export Excel; D-07]` | [x] | 17 tests, RED first (**13 errors + 3 failures**), then GREEN |
| 4b.2 GREEN — `descriptionHeaders()`, `orderedCodes()`, `writeLocaleCells()`, locale offset in `writeFeatureCells()`, base cell via `get_descripcion_idioma(null)` | `[Export Excel, GDI-10; D-07]` | [x] | `Services/ArticuloExcelExportService.php`; `EXPORT_HEADERS` untouched |
| 4b.3 RED — 4 Import wizard scenarios (target default/override; unknown `zz`; deactivated `fr`; legacy base-only) + the destination rule + mapped-but-empty pair | `[Import wizard 3 pasos; D-07]` | [x] | included in the 17-test file |
| 4b.4 GREEN — `languageFieldCatalog()`, `resolveTargetCodidioma()`, `resolveLocalePair()`, `orderedLanguages()`; `preview(..., array $idiomas = [])`, `fieldOptions(..., array $idiomas = [])`, `extra_field_aliases(array $idiomas = [])` | `[Import wizard 3 pasos; D-07]` | [x] | `Services/ArticuloExcelImportWizardService.php`; `FIELD_CATALOG` untouched; `$idiomas = []` reproduces the old behaviour |
| 4b.5 GREEN — `applyDescripcionIdioma()` + private `currentLanguagePair()`; base `applyDescripcion` case untouched | `[Persistencia; D-07, D-10]` | [x] | `Services/ArticuloExcelRowUpdater.php`; writes route through `articulo::set_descripcion_idioma()` |
| 4b.6 GREEN — `handleCatalogoStart()` reads/validates `target_codidioma`, loads `all_activos()`, recovers mapped-but-empty locale fields; `catalogoApplyWizardFields()` + new `catalogoApplyLocaleFields()` apply the resolved pair | `[Import wizard 3 pasos, Persistencia; D-07]` | [x] | `process_excel_wizard_dispatch.php`; `$idiomaModel` is read-only (`ensure_defaults`/`all_activos`/`get_effective_default_code`), never `save()` |
| 4b.7 GREEN — modal target-language `<select>` + JS `target_codidioma=` | `[Import wizard 3 pasos; D-07]` | [ ] | **DEFERRED — out of scope for this run.** The launch prompt excludes any view/controller change. The dispatch accepts the parameter and falls back to the configured default when absent, so the feature degrades safely. |
| 4b.8 RED — 3 Persistencia scenarios + the unmapped-component-preserved case + the registry-immutability case + the dispatch source gate | `[Persistencia, GDI-06; D-07, D-01]` | [x] | included in the 17-test file (18 with the dispatch gate) |
| 4b.9 VERIFY — `ArticuloExcelExportServiceTest` gains the additive-layout test; the two other Excel service tests pass **unchanged**; full plugin suite + PHPStan | `[Export Excel, Import wizard 3 pasos; D-07]` | [x] | full plugin suite **887 tests OK**; `ArticuloExcelImportWizardServiceTest` / `ArticuloExcelRowUpdaterTest` byte-unchanged |

> **Deferred wiring (documented, not silently dropped).** Task 4b.7 (modal + JS) is
> excluded by the launch prompt ("any view/controller change"). Its consequence: the
> controller (`Controller/VentasArticulos.php`) still calls
> `buildSpreadsheet($articulos)` and `preview($filePath, $sheet, $n)` without
> `$this->idiomas`, so the locale columns are **inert in production** until that
> pass-through lands. The services default to `$idiomas = []`, which reproduces the
> pre-change behaviour exactly, so nothing regresses. See "Deviations" 22 and
> "Remaining work".

---

## Slice-4b-wiring task status — wire the locale columns and target language into the live UI

Follow-up work unit `slice-4b-wiring`, applied on top of slice 4b after the deferred
wiring reported in deviation 22 was reviewed. Task **4b.7** was the only unchecked task
in the change; it is now `[x]`, and the controller pass-through that left the locale
columns inert is closed. No Excel service logic was changed — the service layer shipped
in `8aa5f420` / `5a36de09` is byte-unchanged.

### What was inert

The **Export Excel** and **Import wizard 3 pasos** requirements were satisfied by the
services but not by the live surfaces: `Controller/VentasArticulos.php` called
`buildSpreadsheet($articulos)` and `preview($filePath, $sheet, $n)` without the page's
active languages, and
`View/partials/articulos/modal_importar_excel_wizard.html.twig` never sent
`target_codidioma`. The locale columns and the target-language parameter existed and
were unit-tested, but no production request produced them.

### The wiring

- **Controller pass-through** (`Controller/VentasArticulos.php`): `buildSpreadsheet()`
  now receives `idiomas: $this->idiomas` and `codidioma_defecto: $this->codidioma_defecto`
  on **both** call sites (the filtered/full export and the template/empty export), and
  `preview()` receives `$this->idiomas` as its fourth argument.
- **Trait** (`extras/VentasArticulosListTrait.php`): `public string $codidioma_defecto`
  is resolved once in `load_list_idiomas()` through
  `catalogo_idioma::get_effective_default_code()`, alongside the existing
  `$idiomas` / `$idiomas_todos` load.
- **Import modal**
  (`View/partials/articulos/modal_importar_excel_wizard.html.twig`): step 1 gains a
  `<select id="articulos-wizard-target-idioma" name="target_codidioma">` iterating
  `fsc.idiomas`, the configured default rendered first and `selected`. The name matches
  what `process_excel_wizard_dispatch.php` reads; the `tasks.md` text said
  `wizard_target_codidioma`, which was a spec-phase slip (corrected in the task line).
  Two new translation keys (`excel-import-target-language`,
  `excel-import-target-language-help`) were added to both locales.
- **Wizard JS** (`View/js/articulos-excel-import-wizard.js`): `getTargetCodidioma()`
  mirrors the existing `getDefaultCodimpuesto()` reader, and `startApply()` pushes
  `target_codidioma=` alongside `default_action` / `round_price`.

### Tests

| Test | Tag(s) | State | Evidence |
|---|---|---|---|
| `VentasArticulosExcelIdiomasWiringTest::test_export_passes_the_active_languages_to_the_spreadsheet_builder` | `[Export Excel; D-07]` | [x] | Source gate over the `exportExcel()` body: `buildSpreadsheet(` + `idiomas: $this->idiomas` + `codidioma_defecto: $this->codidioma_defecto`. **RED before the wiring.** |
| `VentasArticulosExcelIdiomasWiringTest::test_template_export_passes_the_active_languages_too` | `[Export Excel; D-07]` | [x] | Source gate over the `exportExcelTemplate()` body (the empty-export call site). **RED before.** |
| `VentasArticulosExcelIdiomasWiringTest::test_preview_passes_the_active_languages_to_the_wizard_service` | `[Import wizard 3 pasos; D-07]` | [x] | Source gate over the `getPreview()` body: `preview($filePath, $sheet, $n, $this->idiomas)`. **RED before.** |
| `VentasArticulosExcelIdiomasWiringTest::test_list_trait_exposes_the_resolved_default_language` | `[Export Excel; D-07]` | [x] | The trait declares `public string $codidioma_defecto` and resolves it via `get_effective_default_code()` in `load_list_idiomas()`. **RED before.** |
| `VentasArticulosExcelIdiomasWiringTest::test_import_modal_renders_the_target_language_select_default_first` | `[Import wizard 3 pasos; D-07]` | [x] | The **real** modal partial is rendered through a minimal Twig environment with a stub `fsc`; the select's options are `['en','es']` (configured default first) and `en` carries `selected`. **RED before.** |
| `VentasArticulosExcelIdiomasWiringTest::test_import_modal_target_select_lists_every_active_language` | `[Import wizard 3 pasos; D-07]` | [x] | The rendered select lists every active language with its `nombre`. **RED before.** |
| `VentasArticulosExcelIdiomasWiringTest::test_wizard_js_sends_the_target_language_with_the_apply_request` | `[Import wizard 3 pasos; D-07]` | [x] | The JS reads `articulos-wizard-target-idioma` and pushes `target_codidioma=` in `startApply()`. **RED before.** |

### Non-tautology proof

The seven cases were written first and failed **7/7** (`Tests: 7, Assertions: 9,
Failures: 7`) against the pre-wiring bytes, then passed **7/7** after the wiring. The
controller/trait cases are source gates — the repo's established controller-contract
convention (`CatalogoIdiomaPermissionTest::test_get_cannot_mutate_and_the_page_has_no_admin_only`,
`CatalogoIdiomaManagementTest::test_management_surface_dispatches_the_four_language_actions`);
the modal case renders the shipped bytes, so it cannot pass on a stale copy.

### Files

| File | Action | What Was Done |
|---|---|---|
| `Controller/VentasArticulos.php` | Modified | `idiomas` + `codidioma_defecto` forwarded to `buildSpreadsheet()` on both export call sites; `idiomas` forwarded to `preview()` (+16 / −3) |
| `extras/VentasArticulosListTrait.php` | Modified | `public string $codidioma_defecto` + resolution in `load_list_idiomas()` (+8) |
| `View/partials/articulos/modal_importar_excel_wizard.html.twig` | Modified | target-language select, default first and preselected (+17) |
| `View/js/articulos-excel-import-wizard.js` | Modified | `getTargetCodidioma()` + `target_codidioma=` in `startApply()` (+9) |
| `translations/messages.es_ES.yaml` / `messages.en_EN.yaml` | Modified | two `excel-import-target-language*` keys each (+2 / +2) |
| `tests/Controller/VentasArticulosExcelIdiomasWiringTest.php` | Created | the seven wiring cases (+268) |

---

## Slice-4b-mapping task status — offer the server field options in the mapping dropdown

Follow-up work unit `slice-4b-mapping`, applied on top of slice 4b-wiring after the
residual gap recorded as **deviation 26b** was reviewed. The export columns, the target
language and the dispatch were already live; the only missing leg was that the wizard's
per-column dropdown was still built from the JS `FIELD_OPTIONS` constant and ignored the
server's `field_options` response, so a locale column could not be mapped and per-language
import was unreachable through the UI even though the server supported it. The Excel
service layer is **byte-unchanged**.

### The gap (restated)

`ArticuloExcelImportWizardService::preview()` already returns `field_options`, and
`Controller/VentasArticulos.php::getPreview()` already forwards it in the `get_preview`
JSON (the same payload the wizard's `loadPreview()` reads). `field_options` includes one
`descripcion_<cod>` and one `descripcion_corta_<cod>` entry per active language through
`languageFieldCatalog()`. But `View/js/articulos-excel-import-wizard.js::renderMapping()`
rendered the `<option>` list from the module-local `FIELD_OPTIONS` constant alone and
`loadPreview()` discarded `json.field_options`. A `descripcion_en` header therefore
resolved to `__ignorar__` in the dropdown.

### The fix

`View/js/articulos-excel-import-wizard.js` (no server change):

- `loadPreview()` now stores `json.field_options` on the wizard state through a new
  `normalizeFieldOptions()` sanitizer (the same shape the constant uses: `{value,label}`;
  invalid entries dropped, an empty/absent payload returns `null`).
- A new `optionList()` returns the server list when present, otherwise the
  `FIELD_OPTIONS` constant — so the base fields render exactly as today when the response
  carries no options.
- `renderMapping()` builds the dropdown from `optionList()`, so locale (and feature)
  entries are selectable.
- A new `fieldLabels()` resolves labels from the active option list (sentinel excluded, so
  an unmapped column still shows `—`); `renderPreviewTable()` and
  `renderMappedSummaryTable()` use it, so a mapped locale column is labelled with its
  server-provided name instead of a raw key.
- The now-unused module-local `FIELD_LABELS` map was removed.

No new endpoint, no new request shape and no parallel catalog: the existing
`get_preview` response is the single source.

### Tests

| Test | Tag(s) | State | Evidence |
|---|---|---|---|
| `VentasArticulosExcelMappingOptionsTest::test_wizard_js_consumes_the_server_field_options` | `[Import wizard 3 pasos; D-07]` | [x] | `loadPreview()` reads `json.field_options` and stores it on the wizard state. **RED before.** |
| `VentasArticulosExcelMappingOptionsTest::test_mapping_dropdown_renders_the_server_options` | `[Import wizard 3 pasos; D-07]` | [x] | `renderMapping()` uses `optionList()` and no longer calls `FIELD_OPTIONS.forEach`. **RED before.** |
| `VentasArticulosExcelMappingOptionsTest::test_mapping_dropdown_falls_back_to_the_base_options` | `[Import wizard 3 pasos; D-07]` | [x] | `optionList()` leads with `this.fieldOptions` and falls back to `FIELD_OPTIONS`. **RED before.** |
| `VentasArticulosExcelMappingOptionsTest::test_preview_labels_resolve_from_the_active_options` | `[Import wizard 3 pasos; D-07]` | [x] | `renderPreviewTable()` labels through `fieldLabels()`. **RED before.** |
| `VentasArticulosExcelMappingOptionsTest::test_server_field_options_offer_the_locale_columns` | `[Import wizard 3 pasos; D-07]` | [x] | The **real** `preview()` over the shipped fixture returns `field_options` with `descripcion_es`, `descripcion_corta_es`, `descripcion_en`, `descripcion_corta_en` and the locale labels, sentinel first. Green before and after (payload contract). |
| `VentasArticulosExcelMappingOptionsTest::test_server_field_options_keep_the_base_catalog_intact` | `[Import wizard 3 pasos; D-07]` | [x] | Every `FIELD_CATALOG` field stays selectable with its label and an inactive language (`fr`) is not offered. Green before and after (no regression). |

### Non-tautology proof

The four JS gates were written first and failed **4/6** against the pre-change bytes
(`Tests: 6, Assertions: 26, Failures: 4`), then passed **6/6** (`29 assertions`) after the
change. The two server-payload cases are deliberate characterization guards for the data
contract the UI consumes; they pass before and after and are not counted as RED. The JS
gates are source gates — the repo's established JS convention
(`VentasArticulosExcelIdiomasWiringTest::test_wizard_js_sends_the_target_language_with_the_apply_request`)
— and one gate carries a negative assertion (`renderMapping` must **not** call
`FIELD_OPTIONS.forEach`), so the constant can no longer be the direct source.

The option-list logic was additionally exercised outside the suite with a Node stub
(`node --check` plus a `require` of the file under a minimal `document`/`window` stub):
the server payload is used verbatim, labels exclude the sentinel, an absent payload falls
back to the 8 base options (`— Ignorar —` first) and an invalid payload is sanitized to the
fallback. This is a verification step, not a committed test (the repo has no JS test
runner).

### Files

| File | Action | What Was Done |
|---|---|---|
| `View/js/articulos-excel-import-wizard.js` | Modified | `normalizeFieldOptions()`, `optionList()`, `fieldLabels()`; `loadPreview()` consumes `json.field_options`; `renderMapping()` renders from `optionList()`; the two render methods label through `fieldLabels()`; unused `FIELD_LABELS` removed (+56 / −10) |
| `tests/Controller/VentasArticulosExcelMappingOptionsTest.php` | Created | the four JS gates + the two server-payload guards (+216) |

---

## Slice-4c task status — `catalogo_core` no-context consumers

Delivered whole for the four named consumers (tasks 4c.1–4c.4 + the 4c.6
verification). Task **4c.5** was deferred by the original launch prompt's explicit
"do not touch" scope (deviation 29) and was subsequently **closed by the
`slice-4c5` follow-up** — see "Slice-4c5 task status" below. Every consumer with no
language context now reads descriptions through the configured default language
(D10), and the frozen base column stays the last-resort fallback.

| Task | Tag(s) | State | Evidence |
|---|---|---|---|
| 4c.1 RED — `tests/ConsumidoresIdiomaDefaultTest.php` (2 GDI-10 scenarios + 3 SQL-join gates + the quick-create and trait gates) | `[GDI-10; D-10]` | [x] | 8 tests, RED first (**3 errors + 3 failures**), then GREEN |
| 4c.2 GREEN — public API + Opcional autocomplete | `[GDI-10; D-10]` | [x] | `Model/CatalogoApiService.php` (`articulo_model()` seam + `get_descripcion_idioma()`); `Controller/VentasOpcional.php` (`articulo_model()` seam, `buscarSugerencias()` extraction, `descripcion_idioma(null, 50)` / `get_descripcion_idioma()`) |
| 4c.3 GREEN — `tarif_articulo_precio::all_by_familias()` | `[GDI-10; D-10]` | [x] | `default_codidioma()` seam + left join + `COALESCE(d.descripcion, a.descripcion) AS descripcion` |
| 4c.4 GREEN — `tarif_tarifa_articulo` six queries + the search `LIKE` | `[GDI-10; D-10]` | [x] | all six joins + `lower(COALESCE(d.descripcion, a.descripcion)) LIKE` |
| 4c.5 GREEN — trait `codidioma` resolution | `[GDI-10; D-02, D-10]` | [x] | **closed by `slice-4c5`** — the trait no longer seeds `'es'` nor reads `get_default()`; a new protected `resolve_codidioma()` delegates to `get_effective_default_code()` (explicit request still wins). 3 behavioral cases in `tests/TarifarioOpcionalStateTraitTest.php` (RED first: 3 errors, then GREEN). See "Slice-4c5 task status" |
| 4c.6 VERIFY — quick-create stays base-only | `[GDI-06; D-13]` | [x] | source gate: `Controller/VentasArticulos.php` contains `$art->descripcion = $descripcion;` and **zero** `set_descripcion_idioma` / `articulo_descripcion` tokens; `git diff` empty for that file |

### Non-tautology proof (mutation evidence)

The eight cases were written first and failed **3 errors + 3 failures** against
the pre-change bytes (`Class "articulo" not found` because the real
`articulo::search()` ran before the seam existed; `buscarSugerencias()` was
undefined; both SQL readers emitted `SELECT ... a.descripcion ...` with no
description join), then passed **8 tests, 48 assertions, OK**. The three SQL
cases assert the emitted statement itself, so they cannot pass on a stale copy;
the two source gates are the repo's established controller-contract convention
(`CatalogoIdiomaPermissionTest`, `VentasArticulosExcelIdiomasWiringTest`). The
`falls_back_to_the_base_column_...` case and the trait gate are deliberate
characterization/regression guards that pass before and after and are not
counted as RED.

### Files

| File | Action | What Was Done |
|---|---|---|
| `Model/CatalogoApiService.php` | Modified | `articulo_model()` seam; `'descripcion' => $a->get_descripcion_idioma()` (+18 / −2) |
| `Controller/VentasOpcional.php` | Modified | `articulo_model()` seam; `buscarSugerencias()` extracted out of the `exit`-ing response; language-aware accessors (+37 / −10) |
| `model/tarif_articulo_precio.php` | Modified | `default_codidioma()` seam; description join + `COALESCE` in `all_by_familias()` (+17 / −1) |
| `model/tarif_tarifa_articulo.php` | Modified | `default_codidioma()` seam; the six `COALESCE` joins + the resolved `LIKE` (+27 / −7) |
| `tests/ConsumidoresIdiomaDefaultTest.php` | Created | the 8 cases (+392) |

---

## Slice-4c5 task status — trait `codidioma` resolution

Follow-up work unit `slice-4c5-trait-default`, applied on top of slice 4c after
deviation 29's deferred call-site cleanup was reviewed. Task **4c.5** — the only
unchecked implementation task left in slice 4c — is now closed. The trait's
description accessors were already correct and are byte-unchanged; only the
active-language **resolution** changed.

### The change

`extras/TarifarioOpcionalStateTrait.php`:

- The `'es'` seed plus the `get_default()` + `$default->codidioma` fallback in
  `init_tarifario_opcional_state()` are gone. The method now assigns
  `$this->codidioma = $this->resolve_codidioma($idioma);`.
- A new protected `resolve_codidioma(catalogo_idioma $idioma): string` keeps the
  explicit `$_REQUEST['codidioma']` precedence and otherwise returns
  `$idioma->get_effective_default_code()` — the same total resolver every other
  consumer uses (GDI-10 / D-02, D-10). The extraction mirrors the
  `resolve_codidioma()` extraction slice 3 made on `Controller/VentasArticulo.php`
  and the trait's own `run_in_transaction()` / `parse_price_input()` extractions,
  and is what makes the resolution DB-free testable.
- The description reads (`descripcion_idioma($this->codidioma, 50)` at `:169`,
  `get_descripcion_idioma($this->codidioma)` at `:172`) are **not touched**, as
  required; the durable gate `ConsumidoresIdiomaDefaultTest::test_opcional_state_trait_already_reads_through_the_language_api`
  stays green.

Behaviour: an explicit request value is unchanged; the fallback moves from
"hard-coded `'es'` unless a configured active default exists" to the total
resolver. Under the GDI-02 invariant the two agree when an active default exists;
they differ (and the old code returned `'es'`) only when no `por_defecto` flag is
set, which is exactly the case the new behavioral test pins.

### Tests

| Test | Tag(s) | State | Evidence |
|---|---|---|---|
| `TarifarioOpcionalStateTraitTest::test_codidioma_resolution_follows_the_configured_default` | `[GDI-10; D-02, D-10]` | [x] | Configured default `en` (never `es`), active `es` present → resolves `en`. **RED first** (`resolve_codidioma()` undefined). |
| `TarifarioOpcionalStateTraitTest::test_codidioma_resolution_is_total_without_a_configured_default` | `[GDI-10; D-02, D-10]` | [x] | No `por_defecto` flag, `en` + `es` active → resolves the lowest active code `en`; the old `'es'` seed would have returned `es`. |
| `TarifarioOpcionalStateTraitTest::test_explicit_request_language_wins_over_the_resolver` | `[GDI-10; D-02, D-10]` | [x] | `$_REQUEST['codidioma'] = 'es'` with configured default `en` → resolves `es` (precedence preserved). |

`setUp()`/`tearDown()` unset `$_REQUEST['codidioma']` so the request case cannot
leak into the other cases.

### Non-tautology proof

The three cases were written first and failed **3 errors** against the pre-change
bytes (`Error: Call to undefined method class@anonymous::resolve_codidioma()` at
`TarifarioOpcionalStateTraitTest.php:155`), then passed **23 tests, 33 assertions,
OK** after the change. The "no configured default" case is the one that would fail
against a naive `get_default()`-only implementation, so the test has teeth on the
actual behaviour change rather than only on the method's existence.

### Files

| File | Action | What Was Done |
|---|---|---|
| `extras/TarifarioOpcionalStateTrait.php` | Modified | Removed the `'es'` seed + `get_default()` fallback; new protected `resolve_codidioma()`; `init_tarifario_opcional_state()` delegates to it (+18 / −9) |
| `tests/TarifarioOpcionalStateTraitTest.php` | Modified | 3 resolution cases + `setUp()`/`tearDown()` request isolation + the `resolve()` wrapper on the trait subject (+91) |

---

## Slice-5 task status — `tarifario` consumer migration

Delivered whole (tasks 5.1–5.9 all `[x]` in `tasks.md`). Every `tarifario` reader
that had no language context now resolves the configured default through the
language API or a joined `COALESCE` (R-TAR-HOOK-013 / GDI-10), the two dead calls
in the base controller are corrected, and the four `tarif_controller` subclasses
still load and instantiate. **All four code commits live in the `plugins/tarifario`
repository** (separate from `catalogo_core` and the repo root); the SDD artifacts
stay under `plugins/catalogo_core/openspec/`.

| Task | Tag(s) | State | Evidence |
|---|---|---|---|
| 5.1 RED — `tests/Model/TarifGrupoArticuloIdiomaTest.php` (2 R-TAR-HOOK-013 scenarios) | `[R-TAR-HOOK-013; D-10]` | [x] | 2 tests; RED first (2 failures: no description join, the bare base column still selected) |
| 5.2 GREEN — `tarif_grupo_articulo.php`: `default_codidioma()` seam + join + `COALESCE` in `get_articulos_grupo()` and `buscar_articulos_disponibles()` | `[R-TAR-HOOK-013; D-10]` | [x] | `model/tarif_grupo_articulo.php`; the `LOWER(...) LIKE` now runs over `COALESCE(d.descripcion, a.descripcion)` |
| 5.3 RED — `tests/Controller/TarifHistorialPreciosIdiomaTest.php` (price-history export scenario) | `[R-TAR-HOOK-013; D-10]` | [x] | 2 tests; RED first (1 error: `get_descripcion_articulo()` undefined, 1 source-gate failure) |
| 5.4 GREEN — `tarif_historial_precios.php` (`get_descripcion_articulo()` through the language API); `tarif_actualizar_precios.php` (`default_codidioma()` seam + join + `COALESCE`); `tarif_roles.php` verified | `[R-TAR-HOOK-013; D-10]` | [x] | the export resolves through `get_descripcion_idioma($this->codidioma)`; the preview joins the configured default |
| 5.5 RED — `tests/Controller/TarifControllerLanguageCallsTest.php` (grep gate + load/instantiate assertion) | `[R-TAR-HOOK-013, GDI-10 defect a]` | [x] | 3 tests; RED first (2 source-gate failures: the dead calls present, `get_effective_default_code()` absent) |
| 5.6 GREEN — `extras/tarif_controller.php`: `:240`/`:243` corrected to the language API; the `:104` resolution now `get_effective_default_code()` | `[R-TAR-HOOK-013, GDI-10 defect a; D-02, D-10]` | [x] | `tarif_buscar_articulo()` uses `descripcion_idioma($this->codidioma, 50)` / `get_descripcion_idioma($this->codidioma)`; the `'es'` seed is gone |
| 5.7 RED→GREEN — `tests/Integration/TarifIdiomaLegacyAliasTest.php` | `[R-TAR-HOOK-013]` | [x] | 2 tests; characterization guard (green before and after — the aliases already resolved; the test freezes it) |
| 5.8 VERIFY — already-correct readers unchanged | `[R-TAR-HOOK-013; D-10]` | [x] | `git diff --quiet` is empty for `Services/ExcelRowUpdater.php`, `Services/ArticuloListActionHandler.php`, `controller/tarif_catalogo_view.php`, `controller/tarif_configurador_opcionales.php` **and** `model/tarif_articulo.php` (incl. `:234-329` `search_tarifario`) |
| 5.9 VERIFY — suites green | `[R-TAR-HOOK-013]` | [x] | `-c plugins/tarifario/phpunit.xml` → **266 tests, 1093 assertions, 2 skipped, OK**; `--testsuite Plugins` (root) → **2155 tests, 7 failures, all pre-existing `OidcProvider`** |

### Non-tautology proof (RED first)

The nine cases were written first and failed **1 error + 5 failures** against the
pre-change bytes (`Tests: 9, Assertions: 19, Errors: 1, Failures: 5`): the model
scenarios saw no description join and the bare base column; `get_descripcion_articulo()`
was undefined; the base controller still carried both dead calls and the `'es'` seed.
The two legacy-alias cases and the load/instantiate case were green before and after
— deliberate characterization/regression guards, not counted as RED. After the change:
**9 tests, 35 assertions, OK**.

The three grep/source gates have teeth: they fail on the pre-change source and would
fail again if a dead call or the `'es'` seed returned. The `TarifHistorialPreciosIdiomaTest`
behavioural case runs the real `get_descripcion_articulo()` body through a stub article
that answers per language, so a base-column read or a hard-coded `es` fails it.

### Files

| File | Action | What Was Done |
|---|---|---|
| `model/tarif_grupo_articulo.php` | Modified | `require_once` of `catalogo_idioma`; `default_codidioma()` seam; description join + `COALESCE` in `get_articulos_grupo()` and `buscar_articulos_disponibles()` (+25 / −3) |
| `controller/tarif_historial_precios.php` | Modified | `get_descripcion_articulo()` through the language API; `export_excel()` uses it (+24 / −2) |
| `controller/tarif_actualizar_precios.php` | Modified | `default_codidioma()` seam; description join + `COALESCE` in `load_preview_articulos()` (+18 / −1) |
| `extras/tarif_controller.php` | Modified | Both dead calls corrected to the language API; `get_effective_default_code()` replaces the `'es'` seed + `get_default()` fallback (+4 / −7) |
| `tests/Model/TarifGrupoArticuloIdiomaTest.php` | Created | 2 R-TAR-HOOK-013 group-article SQL scenarios (+132) |
| `tests/Controller/TarifHistorialPreciosIdiomaTest.php` | Created | 2 price-history export scenarios (+116) |
| `tests/Controller/TarifControllerLanguageCallsTest.php` | Created | grep gate + total-resolution gate + load/instantiate assertion (+100) |
| `tests/Integration/TarifIdiomaLegacyAliasTest.php` | Created | legacy-alias characterization guard (+62) |

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
| 4b.1–4b.8 | `--filter ArticuloExcelIdiomasTest` → **17 tests, 4 assertions, 13 errors + 3 failures** (`resolveTargetCodidioma` / `languageFieldCatalog` / `resolveLocalePair` / `applyDescripcionIdioma` undefined; the export emitted no locale column; the base `Descripción` cell returned the raw base column) | same filter → **18 tests, 54 assertions, OK** | The dispatch wiring was added after the service layer was green, then the whole `--filter Excel` run → **46 tests, 142 assertions, OK**; the new test file was kept at one file per the tasks resolution |
| 4b.9 | included in the 4b RED run (the export test update was written with the export service change) | `--filter Excel` → **46 tests, 142 assertions, OK**; full plugin suite → **887 tests, 3813+ assertions, OK** | `ArticuloExcelImportWizardServiceTest` / `ArticuloExcelRowUpdaterTest` needed no edit (the additive shape is backward compatible) |
| 4b.7 (slice-4b-wiring) | `--filter VentasArticulosExcelIdiomasWiringTest` → **7 tests, 9 assertions, 7 failures** (no pass-through in the controller, no `codidioma_defecto` on the trait, no `target_codidioma` in the modal or the JS) | same filter → **7 tests, 20 assertions, OK** | None — the wiring is purely additive; no refactor was needed |
| 4b-mapping (slice-4b-mapping) | `--filter VentasArticulosExcelMappingOptionsTest` → **6 tests, 26 assertions, 4 failures** (the JS still rendered the dropdown from `FIELD_OPTIONS`; `loadPreview` ignored `json.field_options`; no `optionList()`/`fieldLabels()`; `renderPreviewTable` used `FIELD_LABELS`) | same filter → **6 tests, 29 assertions, OK** | None — the change is additive; the only cleanup was removing the now-unused `FIELD_LABELS` map |
| 4c.1–4c.4 + 4c.6 (slice-4c) | `--filter ConsumidoresIdiomaDefaultTest` → **8 tests, 9 assertions, 3 errors + 3 failures** (`Class "articulo" not found` because the real `articulo::search()` ran before the seam existed; `buscarSugerencias()` undefined; both SQL readers emitted `SELECT ... a.descripcion ...` with no description join) | same filter → **8 tests, 48 assertions, OK** | Two testability seams extracted (`articulo_model()` on `CatalogoApiService` and `VentasOpcional`, `default_codidioma()` on the two tarif models) plus the `buscarSugerencias()` extraction out of the `exit`-ing response; re-ran green |

| 4c.5 (slice-4c5) | `--filter TarifarioOpcionalStateTraitTest` → **23 tests, 30 assertions, 3 errors** (`Call to undefined method class@anonymous::resolve_codidioma()`; the 20 pre-existing cases green) | same filter → **23 tests, 33 assertions, OK** | None — the `resolve_codidioma()` extraction is behavior-preserving; the 20 pre-existing trait cases (transaction/price) stayed green throughout |
| 5.1–5.7 (slice 5) | focused run over the four new files → **9 tests, 19 assertions, 1 error + 5 failures** (`get_descripcion_articulo()` undefined; no description join and the bare base column in both model readers; the dead calls and the `'es'` seed still present). The two alias cases + the load/instantiate case were green (characterization guards). | same focused run → **9 tests, 35 assertions, OK**; full `-c plugins/tarifario/phpunit.xml` → **266 tests, 1093 assertions, 2 skipped, OK** | None — the readers are isolated substitutions; the only extraction was `get_descripcion_articulo()` (the export's description resolution), made so the path is testable without the spreadsheet writer |

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

## Work Unit Evidence (slice 4b)

### Work unit `4b₁` — additive per-language export columns (Export Excel, GDI-10)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter Excel` → **OK (46 tests, 142 assertions)**. The three Export Excel scenarios plus the default-first ordering and the GDI-10 base-cell leg live in `tests/Services/ArticuloExcelIdiomasTest.php` (committed with `4b₂`, see below) and the additive-layout guard in `tests/Services/ArticuloExcelExportServiceTest.php` (this unit). |
| **Runtime harness command/scenario and exact result** | `N/A` — pure workbook-shape function with no routing, HTTP or process boundary; it is exercised over `Spreadsheet` objects with array rows and the DB-free `FakeArticulo` over `IdiomaRegistryFake`. A real-browser "export completo" download smoke remains for `verify`. |
| **Rollback boundary** | Revert commit `8aa5f420`: `Services/ArticuloExcelExportService.php` and `tests/Services/ArticuloExcelExportServiceTest.php`. `EXPORT_HEADERS` was never edited, so the base workbook shape returns intact; the import path is independent and stays. |

### Work unit `4b₂` — explicit-target-language import (Import wizard 3 pasos, Persistencia)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter ArticuloExcelIdiomasTest` → **OK (18 tests, 54 assertions)**; RED first was **13 errors + 3 failures**. |
| **Runtime harness command/scenario and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **OK — 887 tests, 3873 assertions, 2 warnings, 1 skipped** (baseline before slice 4b: 868 tests, 3814 assertions; +19 tests, no regressions). The persistence boundary is exercised DB-free through `FakeArticulo` + `IdiomaRegistryFake`, which mutate the seeded `articulo_descripciones` rows exactly like the emitted SQL; the registry is asserted byte-identical after every write. The SSE/dispatch wiring is pinned by a source gate (reads `target_codidioma`, routes through `resolveLocalePair`, never `$idiomaModel->save(`); a real-DB SSE import smoke remains for `verify`. |
| **Rollback boundary** | Revert commit `5a36de09`: `Services/ArticuloExcelImportWizardService.php`, `Services/ArticuloExcelRowUpdater.php`, `process_excel_wizard_dispatch.php` and `tests/Services/ArticuloExcelIdiomasTest.php`. Every addition is optional/backward compatible (`$idiomas = []` reproduces the old behaviour), so the wizard keeps importing base fields exactly as before. The export unit is independent and stays. |

### Work unit `4b-wiring` — live UI wiring for the locale columns and the target language (Export Excel, Import wizard 3 pasos)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticulosExcelIdiomasWiringTest` → **OK (7 tests, 20 assertions)**; RED first was **7 failures** |
| **Runtime harness command/scenario and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **OK — 894 tests, 3893 assertions, 2 warnings, 1 skipped** (baseline before this unit: 887 tests, 3873 assertions; +7 tests, no regressions). The modal boundary is exercised by rendering the real partial through a minimal Twig environment; a real-browser export/import smoke remains for `verify`. |
| **Rollback boundary** | Revert commit `cf02b7f2`: `Controller/VentasArticulos.php` (the three call sites), `extras/VentasArticulosListTrait.php` (`codidioma_defecto`), `View/partials/articulos/modal_importar_excel_wizard.html.twig` (the select), `View/js/articulos-excel-import-wizard.js` (`getTargetCodidioma()` + the param), the two translation-key additions and the new test file. The services keep their optional `$idiomas = []` defaults, so reverting only makes the locale columns inert again; nothing else depends on the wiring. Clear the Twig cache after reverting the view. |

### Work unit `4b-mapping` — server field options in the mapping dropdown (Import wizard 3 pasos, D-07)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticulosExcelMappingOptionsTest` → **OK (6 tests, 29 assertions)**; RED first was **4 failures** (the JS gates) with the two server-payload guards already green. |
| **Runtime harness command/scenario and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **OK — 900 tests, 3922 assertions, 2 warnings, 1 skipped** (baseline before this unit: 894 tests, 3893 assertions; +6 tests, no regressions). The UI boundary is pinned by source gates over the shipped JS plus a real `preview()` over the shipped fixture; the option-list logic was also exercised under a Node stub (verification only). A real-browser mapping of a `descripcion_en` column remains for `verify`. |
| **Rollback boundary** | Revert commit `ed340ef9`: `View/js/articulos-excel-import-wizard.js` and `tests/Controller/VentasArticulosExcelMappingOptionsTest.php`. No server, view-partial, controller, model or schema file is touched; reverting only re-hides the locale columns in the dropdown while the server keeps returning them. |

---

## Work Unit Evidence (slice 4c)

### Work unit `4c` — no-context consumers resolve the configured default (GDI-10, D-10)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter ConsumidoresIdiomaDefaultTest` → **OK (8 tests, 48 assertions)**; RED first was **3 errors + 3 failures** (the API/opcional doubles could not reach their seams and both SQL readers emitted `a.descripcion`). |
| **Runtime harness command/scenario and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **OK — 908 tests, 3970 assertions, 2 warnings, 1 skipped** (baseline before this unit: 900 tests, 3922 assertions; +8 tests, no regressions). The API and Opcional boundaries run the **real** method bodies DB-free through the `articulo_model()` seam over `FakeArticulo` + `IdiomaRegistryFake` (configured default `en`, never `es`); the two SQL readers are exercised through a recording engine that captures the emitted statement. A real-DB composed-SQL smoke of the `COALESCE` join on MySQL **and** PostgreSQL remains for `verify` (task 4c's own verification note). |
| **Rollback boundary** | Revert commit `8fd0eb1a`: `Model/CatalogoApiService.php`, `Controller/VentasOpcional.php`, `model/tarif_articulo_precio.php`, `model/tarif_tarifa_articulo.php` and `tests/ConsumidoresIdiomaDefaultTest.php`. Each reader is an isolated substitution: reverting restores the base-column reads without touching schema, views, the language API, the search predicate or `tarifario`. Nothing else depends on the seams. |

### Work unit `4c5` — trait active-language resolution uses the total resolver (GDI-10, D-02/D-10)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifarioOpcionalStateTraitTest` → **OK (23 tests, 33 assertions)**; RED first was **3 errors** (`resolve_codidioma()` undefined) with the 20 pre-existing cases green |
| **Runtime harness command/scenario and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **OK — 911 tests, 3973 assertions, 2 warnings, 1 skipped** (baseline before this unit: 908 tests, 3970 assertions; +3 tests, no regressions). The resolution boundary is exercised DB-free through the trait subject over `FakeCatalogoIdioma` + `IdiomaRegistryFake` (configured default `en`, never `es`); the real controller boot remains for `verify`. |
| **Rollback boundary** | Revert this unit's commit: `extras/TarifarioOpcionalStateTrait.php` and `tests/TarifarioOpcionalStateTraitTest.php`. The revert restores the `'es'` seed + `get_default()` fallback (still correct under the GDI-02 invariant) and removes the three resolution cases; no other consumer, model, view, search, Excel or `tarifario` file is touched, and the description accessors were never changed. |

---

## Work Unit Evidence (slice 5 — `tarifario`, all four commits in the `plugins/tarifario` repo)

### Work unit `5-group` — group-article readers resolve the configured default (R-TAR-HOOK-013)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml plugins/tarifario/tests/Model/TarifGrupoArticuloIdiomaTest.php` → **OK (2 tests, 12 assertions)**; RED first was **2 failures** (no description join; the bare base column still selected) |
| **Runtime harness command/scenario and exact result** | `N/A` — raw-SQL model readers with no routing/HTTP boundary. The emitted statement is exercised DB-free through a recording engine that captures the SQL and returns no row; a real-DB composed-SQL smoke remains for `verify`. |
| **Rollback boundary** | Revert commit `74e58f2`: `model/tarif_grupo_articulo.php` + `tests/Model/TarifGrupoArticuloIdiomaTest.php`. The `default_codidioma()` seam is additive and the readers fall back to the base column; nothing else depends on it. |

### Work unit `5-price` — price readers resolve the configured default (R-TAR-HOOK-013)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml plugins/tarifario/tests/Controller/TarifHistorialPreciosIdiomaTest.php` → **OK (2 tests, 4 assertions)**; RED first was **1 error + 1 failure** (`get_descripcion_articulo()` undefined; the source still read `$articulo->descripcion`) |
| **Runtime harness command/scenario and exact result** | `N/A` — the export's description resolution is exercised DB-free by running the real `get_descripcion_articulo()` body through a stub article that answers per language (the spreadsheet writer and the `exit` are not on the tested path); a real-DB export smoke remains for `verify`. |
| **Rollback boundary** | Revert commit `7b0ca9a`: `controller/tarif_historial_precios.php`, `controller/tarif_actualizar_precios.php` + `tests/Controller/TarifHistorialPreciosIdiomaTest.php`. Each reader is an isolated substitution; reverting restores the base-column reads and removes the `default_codidioma()` seam. |

### Work unit `5-controller` — dead calls corrected + total default (R-TAR-HOOK-013, defect a)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml plugins/tarifario/tests/Controller/TarifControllerLanguageCallsTest.php` → **OK (3 tests, 12 assertions)**; RED first was **2 failures** (both dead calls present; `get_effective_default_code()` absent and the `'es'` seed present) |
| **Runtime harness command/scenario and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → **OK — 266 tests, 1093 assertions, 2 skipped** (baseline before slice 5: 257 tests, 1058 assertions, 2 skipped; +9 tests, no regressions). The load assertion requires the four real controller files and instantiates each with `newInstanceWithoutConstructor()`, so a fatal in the corrected class hierarchy would fail it. A real-browser dispatch of `tarif_buscar_articulo()` (dead in-repo) is not required by any scenario. |
| **Rollback boundary** | Revert commit `d6e837f`: `extras/tarif_controller.php` + `tests/Controller/TarifControllerLanguageCallsTest.php`. Reverting restores the dead calls and the `'es'` seed; the four subclasses keep loading either way (the buggy method is dead in-repo). |

### Work unit `5-aliases` — legacy language aliases stay loadable (R-TAR-HOOK-013)

| Evidence | Value |
|---|---|
| **Focused test command and exact result** | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml plugins/tarifario/tests/Integration/TarifIdiomaLegacyAliasTest.php` → **OK (2 tests, 7 assertions)**; **green before and after** — a characterization guard (the aliases already extended the catalogo_core models), not a RED case |
| **Runtime harness command/scenario and exact result** | `N/A` — a class-hierarchy contract (`class_exists` + `is_subclass_of` + `method_exists`); no routing, HTTP or process boundary. |
| **Rollback boundary** | Revert commit `b58d40f`: `tests/Integration/TarifIdiomaLegacyAliasTest.php` only. No production file is touched; the guard is the only artifact. |

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
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter ArticuloExcelIdiomasTest` (RED, slice 4b) | **RED** — 17 tests, 4 assertions, **13 errors + 3 failures** (`resolveTargetCodidioma` / `languageFieldCatalog` / `resolveLocalePair` / `applyDescripcionIdioma` undefined; no locale column emitted; the base cell returned the raw base column). |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter ArticuloExcelIdiomasTest` (GREEN, slice 4b) | **OK — 18 tests, 54 assertions**. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter Excel` (after slice 4b) | **OK — 46 tests, 142 assertions** (the new file + the additive-layout guard + the three pre-existing Excel service tests). |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (after slice 4b) | **OK** — **887 tests, 3873 assertions, 2 warnings, 1 skipped** (baseline before slice 4b: 868 tests, 3814 assertions; +19 tests, no regressions). |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoCoreHookMarkersTest` (after slice 4b) | **OK — 12 tests, 68 assertions**, test file **unmodified** (`git status --short` empty). |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root, regression, after slice 4b) | **FAILED (pre-existing, unrelated)** — 2121 tests, 8423 assertions, **7 failures**, 1 warning, 46 skipped. All 7 failures are in the untouched `OidcProvider` plugin (`OidcRegisterControllerMinimalClienteTest` ×1, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` ×4); a grep for `catalogo_core` in the failure output returns **0**. |
| `ddev exec composer phpstan` (after slice 4b) | **FAILED (pre-existing, unrelated)** — the same single `tests/Core/PluginEnableAjaxSafetyTest.php:308` (`return.type`) error; phpstan paths are `src` and root `tests` only, so no slice-4b file is analysed by this config. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticulosExcelIdiomasWiringTest` (RED, slice 4b-wiring) | **RED** — 7 tests, 9 assertions, **7 failures** (no pass-through in the controller, no `codidioma_defecto` on the trait, no `target_codidioma` in the modal or the JS). |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticulosExcelIdiomasWiringTest` (GREEN, slice 4b-wiring) | **OK — 7 tests, 20 assertions**. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (after slice 4b-wiring) | **OK** — **894 tests, 3893 assertions, 2 warnings, 1 skipped** (baseline before: 887 tests, 3873 assertions; +7 tests, no regressions). |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoCoreHookMarkersTest` (after slice 4b-wiring) | **OK — 12 tests, 68 assertions**, test file **unmodified** (`git status --short` empty). |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root, regression, after slice 4b-wiring) | **FAILED (pre-existing, unrelated)** — 2128 tests, 8441 assertions, **7 failures**, 1 warning, 46 skipped. All 7 failures are in the untouched `OidcProvider` plugin (`OidcRegisterControllerMinimalClienteTest` ×1, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` ×4); a grep for `catalogo_core` in the failure output returns **0**. |
| `ddev exec composer phpstan` (after slice 4b-wiring) | **FAILED (pre-existing, unrelated)** — the same single `tests/Core/PluginEnableAjaxSafetyTest.php:308` (`return.type`) error; phpstan paths are `src` and root `tests` only, so no slice-4b-wiring file is analysed by this config. |
| Translation parse check (slice 4b-wiring) | Both YAML files parse through `Symfony\Component\Yaml\Yaml::parseFile`: `es_ES` **180 keys**, `en_EN` **149 keys**; `excel-import-target-language` resolves in both. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticulosExcelMappingOptionsTest` (RED, slice 4b-mapping) | **RED** — 6 tests, 26 assertions, **4 failures** (the JS still rendered the dropdown from `FIELD_OPTIONS`; `loadPreview` ignored `json.field_options`; no `optionList()`/`fieldLabels()`; `renderPreviewTable` used `FIELD_LABELS`). The two server-payload guards were green. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticulosExcelMappingOptionsTest` (GREEN, slice 4b-mapping) | **OK — 6 tests, 29 assertions**. |
| `node --check View/js/articulos-excel-import-wizard.js` (slice 4b-mapping) | **JS SYNTAX OK**; a Node stub `require` proved the server payload is used verbatim, labels exclude the sentinel, an absent payload falls back to the 8 base options (`— Ignorar —` first) and an invalid payload is sanitized to the fallback. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (after slice 4b-mapping) | **OK** — **900 tests, 3922 assertions, 2 warnings, 1 skipped** (baseline before: 894 tests, 3893 assertions; +6 tests, no regressions). |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoCoreHookMarkersTest` (after slice 4b-mapping) | **OK — 12 tests, 68 assertions**, test file **unmodified** (`git status --short` empty). |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root, regression, after slice 4b-mapping) | **FAILED (pre-existing, unrelated)** — 2135 tests, 8475 assertions, **7 failures**, 1 warning, 46 skipped. All 7 failures are in the untouched `OidcProvider` plugin (`OidcRegisterControllerMinimalClienteTest` ×1, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` ×4); a grep for `catalogo_core` in the failure output returns **0**. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter ConsumidoresIdiomaDefaultTest` (RED, slice 4c) | **RED** — 8 tests, 9 assertions, **3 errors + 3 failures**: the two API cases errored with `Class "articulo" not found` (the real `articulo::search()` ran because the seam did not exist yet), the Opcional case errored with `Call to undefined method ...::buscarSugerencias()`, and both SQL readers failed (`SELECT ... a.descripcion ...`, no description join; the LIKE still matched only the base column). |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter ConsumidoresIdiomaDefaultTest` (GREEN, slice 4c) | **OK — 8 tests, 48 assertions**. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (after slice 4c) | **OK** — **908 tests, 3970 assertions, 2 warnings, 1 skipped** (baseline before slice 4c: 900 tests, 3922 assertions; +8 tests, no regressions). |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoCoreHookMarkersTest` (after slice 4c) | **OK — 12 tests, 68 assertions**, test file **unmodified** (`git status --short` empty). |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root, regression, after slice 4c) | **FAILED (pre-existing, unrelated)** — 2143 tests, 8514 assertions, **8 failures**, 1 warning, 46 skipped. All 8 failures are in the untouched `OidcProvider` plugin (`OidcRegisterControllerMinimalClienteTest` ×2, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` ×4); a grep for `catalogo_core` in the failure output returns **0**. Two adjacent runs of the same command reported **7** failures with the **same** `OidcProvider` failure set — the count is order/state-sensitive in that untouched plugin (the same behaviour slices 1 and 4a-fix recorded). The first attempt hung because two PHPUnit invocations were alive at once contending on the same runner cache; a single run finishes in ≈90 s. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifarioOpcionalStateTraitTest` (RED, slice 4c5) | **RED** — 23 tests, 30 assertions, **3 errors** (`Call to undefined method class@anonymous::resolve_codidioma()`); the 20 pre-existing transaction/price cases green. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifarioOpcionalStateTraitTest` (GREEN, slice 4c5) | **OK — 23 tests, 33 assertions**. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter ConsumidoresIdiomaDefaultTest` (after slice 4c5) | **OK — 8 tests, 48 assertions**; the GDI-10 trait description-read gate is unmodified and green. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (after slice 4c5) | **OK** — **911 tests, 3973 assertions, 2 warnings, 1 skipped** (baseline before: 908 tests, 3970 assertions; +3 tests, no regressions). |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoCoreHookMarkersTest` (after slice 4c5) | **OK — 12 tests, 68 assertions**, test file **unmodified** (`git status --short` empty). |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root, regression, after slice 4c5) | **FAILED (pre-existing, unrelated)** — 2146 tests, 8528 assertions, **7 failures**, 1 warning, 46 skipped. All 7 failures are in the untouched `OidcProvider` plugin (`OidcRegisterControllerMinimalClienteTest` ×1, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` ×4); a grep for `catalogo_core` in the full failure output returns **0**. |
| `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml plugins/tarifario/tests/Model/TarifGrupoArticuloIdiomaTest.php plugins/tarifario/tests/Controller/TarifHistorialPreciosIdiomaTest.php plugins/tarifario/tests/Controller/TarifControllerLanguageCallsTest.php plugins/tarifario/tests/Integration/TarifIdiomaLegacyAliasTest.php` (RED, slice 5) | **RED** — 9 tests, 19 assertions, **1 error + 5 failures** (`get_descripcion_articulo()` undefined; no description join and the bare base column in both model readers; both dead calls and the `'es'` seed still present). The two alias cases + the load/instantiate case were green (characterization guards). |
| `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml plugins/tarifario/tests/Model/TarifGrupoArticuloIdiomaTest.php plugins/tarifario/tests/Controller/TarifHistorialPreciosIdiomaTest.php plugins/tarifario/tests/Controller/TarifControllerLanguageCallsTest.php plugins/tarifario/tests/Integration/TarifIdiomaLegacyAliasTest.php` (GREEN, slice 5) | **OK — 9 tests, 35 assertions**. |
| `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` (after slice 5) | **OK** — **266 tests, 1093 assertions, 2 skipped** (baseline before slice 5: 257 tests, 1058 assertions, 2 skipped; +9 tests, no regressions). |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (after slice 5) | **OK** — **911 tests, 3973 assertions, 2 warnings, 1 skipped** — identical to the slice-4c5 baseline; no `catalogo_core` file is touched by slice 5. |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoCoreHookMarkersTest` (after slice 5) | **OK — 12 tests, 68 assertions**, test file **unmodified** (`git status --short` empty). |
| `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root, regression, after slice 5) | **FAILED (pre-existing, unrelated)** — 2155 tests, 8561 assertions, **7 failures**, 1 warning, 46 skipped. All 7 failures are in the untouched `OidcProvider` plugin (`OidcRegisterControllerMinimalClienteTest` ×2, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` ×4); a `grep -E "^[0-9]+\) Tests"` over the failure output lists **only** `Tests\OidcProvider\...`. No `catalogo_core` or `tarifario` test failed. |
| `ddev exec composer phpstan` (after slice 5) | **FAILED (pre-existing, unrelated)** — the same single `tests/Core/PluginEnableAjaxSafetyTest.php:308` (`return.type`) error; phpstan paths are `src` and root `tests` only, so no `plugins/tarifario` file is analysed by this config. |

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
| Slice 4b total authored changed lines (additions + deletions) | **853** — `Services/ArticuloExcelExportService.php` +114/−6, `Services/ArticuloExcelImportWizardService.php` +163/−7, `Services/ArticuloExcelRowUpdater.php` +55, `process_excel_wizard_dispatch.php` +84/−2, `tests/Services/ArticuloExcelExportServiceTest.php` +30, `tests/Services/ArticuloExcelIdiomasTest.php` +407 (new) |
| `4b₁` authored changed lines | **144** — `Services/ArticuloExcelExportService.php` +114/−6, `tests/Services/ArticuloExcelExportServiceTest.php` +30 |
| `4b₂` authored changed lines | **709** — `Services/ArticuloExcelImportWizardService.php` +163/−7, `Services/ArticuloExcelRowUpdater.php` +55, `process_excel_wizard_dispatch.php` +84/−2, `tests/Services/ArticuloExcelIdiomasTest.php` +407 |
| Cut used for slice 4b? | **Yes** — the whole slice measured 853 > 800, so the pre-declared `4b₁`/`4b₂` boundary was used: two review units, each ≤ 800. |
| Slice 4b-wiring authored changed lines (additions + deletions) | **325** — `Controller/VentasArticulos.php` +16/−3, `extras/VentasArticulosListTrait.php` +8, `View/partials/articulos/modal_importar_excel_wizard.html.twig` +17, `View/js/articulos-excel-import-wizard.js` +9, `translations/messages.es_ES.yaml` +2, `translations/messages.en_EN.yaml` +2, `tests/Controller/VentasArticulosExcelIdiomasWiringTest.php` +268 (new) |
| Cut used for slice 4b-wiring? | **No** — 325 ≤ 800, landed whole as one review unit. |
| Slice 4b-mapping authored changed lines (additions + deletions) | **282** — `View/js/articulos-excel-import-wizard.js` +56/−10, `tests/Controller/VentasArticulosExcelMappingOptionsTest.php` +216 (new) |
| Cut used for slice 4b-mapping? | **No** — 282 ≤ 800, landed whole as one review unit. |
| Slice 4c authored changed lines (additions + deletions) | **511** — `Controller/VentasOpcional.php` +37/−10, `Model/CatalogoApiService.php` +18/−2, `model/tarif_articulo_precio.php` +17/−1, `model/tarif_tarifa_articulo.php` +27/−7, `tests/ConsumidoresIdiomaDefaultTest.php` +392 (new) |
| Cut used for slice 4c? | **No** — 511 ≤ 800, landed whole as one review unit. The measured size sits above the `tasks.md` 240–330 estimate because the single new test file carries the two GDI-10 scenarios plus the three SQL-emission gates and the two verify gates (392 lines including the licence header, docblocks and the DB-free recording engine). No code, comment, blank line, doc or test was cut or compressed to fit. |
| Slice 4c5 authored changed lines (additions + deletions) | **118** — `extras/TarifarioOpcionalStateTrait.php` +18/−9, `tests/TarifarioOpcionalStateTraitTest.php` +91 |
| Cut used for slice 4c5? | **No** — 118 ≤ 800, landed whole as one review unit. No code, comment, blank line, doc or test was cut or compressed to fit. |
| Slice 5 authored changed lines (additions + deletions) | **491** (`git diff --numstat 20b4053 HEAD` in `plugins/tarifario`: 476 insertions + 15 deletions). Production: `model/tarif_grupo_articulo.php` +25/−3, `controller/tarif_historial_precios.php` +24/−2, `controller/tarif_actualizar_precios.php` +18/−1, `extras/tarif_controller.php` +4/−7. Tests: `tests/Model/TarifGrupoArticuloIdiomaTest.php` +132, `tests/Controller/TarifHistorialPreciosIdiomaTest.php` +116, `tests/Controller/TarifControllerLanguageCallsTest.php` +100, `tests/Integration/TarifIdiomaLegacyAliasTest.php` +62 |
| Cut used for slice 5? | **No** — 491 ≤ 800 and inside the `tasks.md` 420–560 estimate, landed whole as four review units (one per work unit). No code, comment, blank line, doc or test was cut or compressed to fit. |

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

**Slice 4b over budget — the pre-declared `4b₁`/`4b₂` boundary was used.** The whole slice
measured **853 authored lines** against the 800 budget (53 over, `853/800`) — inside the
`tasks.md` estimate band's upper edge (700–800) plus the two additive tests 4b.9 required.
Rather than ship one oversized review unit or shrink tests to fit, the pre-declared
boundary was applied: `4b₁` = the export work unit (**144** lines, commit `8aa5f420`) and
`4b₂` = the import work unit (**709** lines, commit `5a36de09`), each ≤ 800. The overage is
concentrated in test infrastructure: the new `ArticuloExcelIdiomasTest.php` alone is 407
lines over 18 tests (export + import + Persistencia scenarios), the same test-infrastructure
shape that produced `1a`'s accepted exception and slices 2/4a's requested exceptions. No
code, comment, blank line, doc or test was cut or compressed to fit.

The only imperfect seam: the tasks resolution keeps **one** new test file, and a new file
cannot be partially committed cleanly, so the three Export Excel scenarios in
`ArticuloExcelIdiomasTest.php` land with `4b₂` rather than `4b₁`. `4b₁` still carries its own
export verification (`ArticuloExcelExportServiceTest::testLocaleColumnsAreAdditiveAndLeaveTheBaseHeadersByteIdentical`),
so it is a green, self-verifying work unit. No `size:exception` is requested for either unit.

**Slice 4b-wiring fits.** 325 authored lines ≤ 800; the follow-up closes the deferred
wiring that slice 4b reported and is one cohesive work unit (the pass-through, the modal
select, the JS parameter and their tests, committed together). No `size:exception` is
requested, and no code, comment, blank line, doc or test was cut or compressed to fit.

**Slice 4b-mapping fits.** 282 authored lines ≤ 800; the follow-up closes the residual
gap that slice 4b-wiring reported (deviation 26b) and is one cohesive work unit (the JS
consumption of the server payload plus its tests, committed together). No `size:exception`
is requested, and no code, comment, blank line, doc or test was cut or compressed to fit.

**Slice 5 fits.** 491 authored lines ≤ 800 and inside the 420–560 `tasks.md` estimate. The
four work units landed as four reviewable commits in the `plugins/tarifario` repository
(`74e58f2`, `7b0ca9a`, `d6e837f`, `b58d40f`), each with its own tests. No `size:exception`
is requested, and no code, comment, blank line, doc or test was cut or compressed to fit.

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
| `8aa5f420` | `feat(catalogo_core): append per-language description columns to the article Excel export` | 2 | +138 / −6 |
| `5a36de09` | `feat(catalogo_core): import article descriptions into an explicit target language` | 4 | +700 / −9 |
| `cf02b7f2` | `feat(catalogo_core): wire per-language Excel export and import into the live UI` | 7 | +322 / −3 |
| `ed340ef9` | `feat(catalogo_core): offer the server field options in the Excel import mapping dropdown` | 2 | +272 / −10 |
| `8fd0eb1a` | `feat(catalogo_core): resolve the configured default description in no-context readers` | 5 | +491 / −20 |
| `1e86a4e6` | `fix(catalogo_core): resolve the opcional trait language through the effective default` | 2 | +109 / −9 |
| `74e58f2` | `feat(tarifario): resolve the configured default language in the group-article readers` | 2 | +157 / −3 |
| `7b0ca9a` | `feat(tarifario): resolve the configured default language in the price readers` | 3 | +158 / −3 |
| `d6e837f` | `fix(tarifario): correct the dead language calls in tarif_controller` | 2 | +104 / −7 |
| `b58d40f` | `test(tarifario): guard the legacy language aliases stay loadable` | 1 | +62 |

> **Repository note.** The four slice-5 commits live in the **`plugins/tarifario`** git
> repository (its own repo, `master` branch), not in `catalogo_core` or the repo root. The
> SDD artifacts stay under `plugins/catalogo_core/openspec/`. No push, no PR, no tag, no
> release in either repo — local commits only.

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

Slice-4b files (commit `8aa5f420`, `4b₁`): `Services/ArticuloExcelExportService.php`,
`tests/Services/ArticuloExcelExportServiceTest.php`.
Slice-4b files (commit `5a36de09`, `4b₂`): `Services/ArticuloExcelImportWizardService.php`,
`Services/ArticuloExcelRowUpdater.php`, `process_excel_wizard_dispatch.php`,
`tests/Services/ArticuloExcelIdiomasTest.php`.
The slice-4b SDD bookkeeping (`tasks.md` checkboxes + this artifact) is committed separately.

Slice-4b-wiring files (commit `cf02b7f2`): `Controller/VentasArticulos.php`,
`extras/VentasArticulosListTrait.php`,
`View/partials/articulos/modal_importar_excel_wizard.html.twig`,
`View/js/articulos-excel-import-wizard.js`, `translations/messages.es_ES.yaml`,
`translations/messages.en_EN.yaml`, `tests/Controller/VentasArticulosExcelIdiomasWiringTest.php`.
The slice-4b-wiring SDD bookkeeping (`tasks.md` checkbox 4b.7 + this artifact) is committed separately.

Slice-4b-mapping files (commit `ed340ef9`): `View/js/articulos-excel-import-wizard.js`,
`tests/Controller/VentasArticulosExcelMappingOptionsTest.php`.
The slice-4b-mapping SDD bookkeeping (`tasks.md` annotation 4b.7 + this artifact) is committed separately.

Slice-4c files (commit `8fd0eb1a`): `Model/CatalogoApiService.php`,
`Controller/VentasOpcional.php`, `model/tarif_articulo_precio.php`,
`model/tarif_tarifa_articulo.php`, `tests/ConsumidoresIdiomaDefaultTest.php`.
The slice-4c SDD bookkeeping (`tasks.md` checkboxes 4c.1–4c.4/4c.6 + the 4c.5 annotation
and this artifact) is committed separately.

Slice-4c5 files (commit `1e86a4e6`): `extras/TarifarioOpcionalStateTrait.php`,
`tests/TarifarioOpcionalStateTraitTest.php`.
The slice-4c5 SDD bookkeeping (`tasks.md` checkbox 4c.5 + this artifact) is committed separately.

Slice-5 files (**in the `plugins/tarifario` repo**), commit `74e58f2`: `model/tarif_grupo_articulo.php`,
`tests/Model/TarifGrupoArticuloIdiomaTest.php`. Commit `7b0ca9a`: `controller/tarif_historial_precios.php`,
`controller/tarif_actualizar_precios.php`, `tests/Controller/TarifHistorialPreciosIdiomaTest.php`.
Commit `d6e837f`: `extras/tarif_controller.php`, `tests/Controller/TarifControllerLanguageCallsTest.php`.
Commit `b58d40f`: `tests/Integration/TarifIdiomaLegacyAliasTest.php`.
The slice-5 SDD bookkeeping (`tasks.md` checkboxes 5.1–5.9 + this artifact) is committed
separately in `catalogo_core`.

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

### Slice-4b deviations (scope + local decisions, no design change)

22. **Task 4b.7 (modal target-language `<select>` + JS `target_codidioma=`) was NOT implemented.**
    The launch prompt explicitly scopes this run to the Excel service/dispatch shape and lists
    "any view/controller change" as out of scope, so the view + JS wiring is deferred even though
    `tasks.md` lists it. The consequence is documented, not hidden: `Controller/VentasArticulos.php`
    still calls `buildSpreadsheet($articulos)` / `preview($filePath, $sheet, $n)` without
    `$this->idiomas`, so the locale columns are inert in production until that pass-through and the
    modal select land. Because the services default to `$idiomas = []` and the dispatch falls back
    to `get_effective_default_code()`, nothing regresses. `tasks.md` marks 4b.7 `[ ]` with the
    deferral annotation; `verify` must treat it as a known, deliberate gap, not a defect.
23. **`resolveLocalePair()` is the destination rule, factored out of the dispatch so it is
    DB-free testable.** D-07 described the rule ("the suffix qualifies importability, the target
    decides the destination") but named no seam. The literal rule lives in
    `ArticuloExcelImportWizardService::resolveLocalePair()` and the dispatch only recovers
    mapped-but-empty locale fields (which `applyMapping()` drops) and routes the resolved pair
    through `ArticuloExcelRowUpdater::applyDescripcionIdioma()`. This is the same "keep the pure
    decision in the service, keep the transport in the dispatch" split the feature-column work
    (CAR-17) already uses.
24. **`applyDescripcionIdioma()` preserves an unmapped component instead of clearing it.** The
    delta's Persistencia rule clears a language when its mapped pair is empty; it does not say a
    *single* mapped column should blank the other. The helper therefore treats `null` as "not
    mapped" (current value preserved) and `''` as "mapped but empty" (clearing), reading the
    target language's own row through the same-language-only `get_descripciones()` list so no
    fallback is inherited (R2). `set_descripcion_idioma()` remains the only writer and owns the
    empty-pair deletion and the cache invalidation.
25. **The locale header label for the short description is `Descripción corta (<nombre>)`.** D-07
    only wrote `label 'Descripción (<nombre>)'` for the pair; two identical dropdown labels would
    be ambiguous, so the short column is disambiguated. No scenario pins the label text.

### Slice-4b-wiring deviations (scope correction, no design change)

26. **Deviation 22 is closed; the deferred wiring landed.** The first `4b` run deferred task 4b.7
    and the controller pass-through because that launch prompt excluded "any view/controller
    change". A follow-up run scoped to `slice-4b-wiring` delivered both: the pass-through of
    `$this->idiomas` / `$this->codidioma_defecto` and the modal select + JS parameter. The service
    layer is byte-unchanged. Two notes: (a) `tasks.md`'s 4b.7 text named the field
    `wizard_target_codidioma`, but `process_excel_wizard_dispatch.php` reads `target_codidioma`
    (shipped in `5a36de09`) — the delivered name is `target_codidioma` and the task line was
    corrected; (b) the wizard's mapping dropdown is rendered from the JS `FIELD_OPTIONS` constant
    and does not yet consume the server's `field_options` (which already includes the locale
    fields), so the locale columns remain unselectable per column in the UI. That is a separate
    gap outside this work unit's scope (the unit wires the target language and the export
    columns); it is reported here for `verify`/a follow-up rather than silently patched.

### Slice-4b-mapping deviations (scope correction, no design change)

27. **Deviation 26b is closed; the mapping dropdown now consumes the server options.** The
    wizard's per-column dropdown rendered from the JS `FIELD_OPTIONS` constant and ignored
    the `field_options` array the `get_preview` response already carried (base fields,
    features and the locale columns), so a `descripcion_<cod>` column could not be mapped.
    `loadPreview()` now stores the server list, `renderMapping()` renders from it, and
    `optionList()` falls back to the constant when the payload is absent — so the base
    options behave exactly as today and no new endpoint or parallel catalog was added. The
    `field_options` payload is unchanged; only the UI consumption changed. The
    now-unused module-local `FIELD_LABELS` map was removed, and label resolution moved to a
    `fieldLabels()` helper over the active option list (sentinel excluded, preserving the
    `—` shown for an unmapped column).

### Slice-4c deviations (mechanism implementation + scope exclusion)

28. **The raw-SQL join resolves the default in PHP, not through a `:def` placeholder.**
    Design/tasks wrote the join as `... AND d.codidioma = :def` with "the configured default"
    bound; the launch prompt explicitly preferred resolving the code in PHP over hard-coding
    `'es'` when a raw join cannot resolve it cleanly. The two models therefore gained a
    protected `default_codidioma()` seam returning
    `(new catalogo_idioma())->get_effective_default_code()`, and the code is interpolated
    with `var2str()` — the exact quoting style every other value in those queries already
    uses. A bound parameter would have mixed placeholder binding into statements assembled
    entirely by string interpolation (including the `IN (...)` family list and the `LIKE`
    literals) and would have needed the fake engines to model parameter binding. The seam is
    also what makes the emitted SQL testable without a database. `COALESCE` matches the PHP
    read chain exactly for a no-context reader: the default-language row wins when present,
    the frozen base column is the terminal leg. The join is on
    `d.referencia = a.referencia AND d.codidioma = <default>`, so the `UNIQUE (referencia,
    codidioma)` key guarantees at most one joined row — no row multiplication, no `DISTINCT`
    needed. Neither reader had a `descripcion` column of its own (`ta.*`/`ap.*` do not include
    one), so the alias cannot shadow a real column.
29. **Task 4c.5 is deferred: the launch prompt excludes `TarifarioOpcionalStateTrait.php`.**
    The trait's description accessors were **already correct**
    (`descripcion_idioma($this->codidioma, 50)` / `get_descripcion_idioma($this->codidioma)`),
    and that is now frozen by a durable gate in `ConsumidoresIdiomaDefaultTest` so it cannot
    regress to the base-column truncator. What 4c.5 asked to change is the trait's
    `codidioma` **resolution** (the `'es'` seed at line 107 plus `get_default()` at line 111)
    — and the prompt's scope block says explicitly "do not touch it". The divergence from
    `get_effective_default_code()` is unreachable under the GDI-02 invariant: an active
    default always exists, so `get_default()` never returns `false` and the `'es'` seed is
    always overwritten. It is left as a documented, non-blocking call-site cleanup rather
    than a silent edit outside the declared scope; `tasks.md` marks 4c.5 `[ ]` with the same
    annotation so `verify` reads it as a known, deliberate gap.
    **Closed by slice `4c5`** — the follow-up work unit replaced the `'es'` seed
    + `get_default()` fallback with a protected `resolve_codidioma()` delegating to
    `get_effective_default_code()` (explicit request precedence preserved), and the
    three new behavioral cases prove the total resolution. The description accessors
    remain byte-unchanged. See "Slice-4c5 task status".

### Slice-5 deviations (scope interpretations + local decisions, no design change)

30. **`controller/tarif_roles.php:718` is resolved at the model boundary, not by a direct call
    at `:718`.** Task 5.4 and the design's File-by-File map say "`:718` → language API", but
    `$a` there is a `tarif_grupo_articulo` hydrated by `tarif_grupo_articulo::get_articulos_grupo()`,
    **not** an `articulo`: it has no language accessor, and loading a `tarif_articulo` per row to
    call `get_descripcion_idioma()` would be an N+1. Task 5.2's change makes that reader return
    `COALESCE(d.descripcion, a.descripcion)` for the configured default, so the value at `:718`
    *is* the language-API result and the controller is byte-unchanged. The requirement's letter
    ("resolve descriptions through the language API … instead of reading the raw
    `articulos.descripcion` column") is satisfied; the coverage matrix maps the group-article
    scenario to 5.1/5.2 only. `verify` should read `:718` as compliant, not as a missed migration.
31. **`model/tarif_articulo.php` is NOT edited.** The launch prompt's "Migrate (no-context
    readers)" list named its `articulo_to_array()` base copy (`:196`) and its import-create base
    write (`:669`), but the authoritative artifacts say otherwise: design.md's File-by-File map
    marks the whole file "**Verify only** | no edit (D-10)"; the delta's "Already-correct readers
    are unchanged" scenario names `model/tarif_articulo.php`'s description reads as unchanged;
    and task 5.8 scopes the empty-diff requirement to `:234-329` (`search_tarifario`). Editing
    `:669` would also violate D-13 (the create path keeps seeding the frozen base column, exactly
    like `VentasArticulos::nuevoArticulo()`), and editing `:196` would change `get()`'s behaviour
    with no scenario requiring it. The file is byte-unchanged and its description reads stay as
    the artifacts prescribe. Recorded because the prompt and the artifacts conflict here; the
    artifacts win per the prompt's own authority rule.
32. **`controller/tarif_catalogo_view.php`'s explicit `es`/`en` literals are left intact.** The
    delta lists that controller as an **already-correct reader to verify unchanged** (its
    description reads already go through `get_descripcion_idioma($this->codidioma)`), and the
    `es`/`en` literals at `:2153-2154,2983-2986,3548-3549,3828-3829` are its fixed two-language
    export/import column shape — a per-language mapping, not a default-resolution bug. The
    design/tasks prescribe no change to them, so none was made; changing them would contradict
    the "already-correct readers are unchanged" scenario. Recorded so `verify` reads it as a
    deliberate non-change rather than an oversight.

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
- **Slice-4b no-drift.** `git status --short plugins/catalogo_core/openspec/specs/` is empty (no
  delta merged) and the repository-root `openspec/` still has no entry for this change. The two
  slice-4b commits contain only the six explicit paths listed in "Commits created"; no schema,
  view, controller, API, `tarifario` or consumer file was touched. `EXPORT_HEADERS` and
  `FIELD_CATALOG` were **not edited** (verified: the constants are byte-identical, only additive
  helpers were added). `articulos.descripcion` is never written by the language path — the export
  only *reads* it as the read chain's terminal leg, and `applyDescripcionIdioma()` routes every
  write through `articulo::set_descripcion_idioma()`. No new Composer dependency was added.
- **Slice-4b-wiring no-drift.** `git status --short plugins/catalogo_core/openspec/specs/` is empty
  (no delta merged) and the repository-root `openspec/` still has no entry for this change. The
  commit contains only the seven explicit paths listed in "Commits created"; the Excel services
  (`ArticuloExcelExportService.php`, `ArticuloExcelImportWizardService.php`,
  `ArticuloExcelRowUpdater.php`, `process_excel_wizard_dispatch.php`) are **byte-unchanged**. No
  schema, model, search, API or `tarifario` file was touched. No new Composer dependency was added.
- **Slice-4b-mapping no-drift.** `git status --short plugins/catalogo_core/openspec/specs/` is empty
  (no delta merged) and the repository-root `openspec/` still has no entry for this change. The
  commit contains only the two explicit paths listed in "Commits created"; the Excel services
  (`ArticuloExcelExportService.php`, `ArticuloExcelImportWizardService.php`,
  `ArticuloExcelRowUpdater.php`, `process_excel_wizard_dispatch.php`), the controller and the modal
  partial are **byte-unchanged**; only the wizard JS changed. No schema, model, search, API or
  `tarifario` file was touched. No new Composer dependency was added.
- **Slice-4c no-drift.** `git status --short plugins/catalogo_core/openspec/specs/` is empty (no
  delta merged) and the repository-root `openspec/` still has no entry for this change. The commit
  contains only the five explicit paths listed in "Commits created"; no schema, view, search,
  Excel, `tarifario` or language-registry file was touched. `articulos.descripcion` is only ever
  **read** as the read chain's terminal leg (the `COALESCE` fallback); no slice-4c line writes it,
  and `Controller/VentasArticulos.php` still contains no `set_descripcion_idioma` /
  `articulo_descripcion` token (source gate). `tests/Integration/CatalogoCoreHookMarkersTest.php`
  is **unmodified** and green (12 tests). No new Composer dependency was added.
- **Slice-4c5 no-drift.** `git status --short plugins/catalogo_core/openspec/specs/` is empty (no
  delta merged) and the repository-root `openspec/` still has no entry for this change. The
  commit contains only the two explicit paths listed in "Commits created"
  (`extras/TarifarioOpcionalStateTrait.php` and `tests/TarifarioOpcionalStateTraitTest.php`); no
  model, schema, view, controller, search, Excel, API, consumer or `tarifario` file was touched.
  The trait's description accessors are byte-unchanged. `tests/Integration/CatalogoCoreHookMarkersTest.php`
  is **unmodified** and green (12 tests). No new Composer dependency was added.
- **Slice-5 no-drift.** `git status --short plugins/catalogo_core/openspec/specs/` is empty (no
  delta merged) and the repository-root `openspec/` still has no entry for this change. All four
  slice-5 commits live in the **`plugins/tarifario`** repo and contain only the explicit paths
  listed in "Commits created"; the already-correct readers (`Services/ExcelRowUpdater.php`,
  `Services/ArticuloListActionHandler.php`, `controller/tarif_catalogo_view.php`,
  `controller/tarif_configurador_opcionales.php`, `model/tarif_articulo.php`) are **byte-unchanged**
  (`git diff --quiet` is empty). No `catalogo_core` file, schema, view, model or consumer outside
  `plugins/tarifario` was touched; `tarifario`'s own `openspec/specs/**` is untouched (the
  `R-TAR-HOOK-013` delta merges only at archive). No new Composer dependency was added.

---

## Remaining work / next

1. **Slices `1a`, `1b`, `2`, `3`, `4a`, `4a-fix`, `4b`, `4b-wiring`, `4b-mapping` and `4c` are
   done** — committed and green.
2. **Slice `4b`'s wiring is done** — task 4b.7 (modal target-language `<select>` + JS
   `target_codidioma=`) and the `Controller/VentasArticulos.php` pass-through of `$this->idiomas`
   / `$this->codidioma_defecto` to `buildSpreadsheet()` / `preview()` landed in
   `slice-4b-wiring` (commit `cf02b7f2`). The locale columns and the target language are now fed
   by the live UI. The wizard's per-column mapping dropdown now renders from the server's
   `field_options` (`slice-4b-mapping`, commit `ed340ef9`), so the locale columns are individually
   selectable; deviation 26b is closed.
3. **Slice `4c` is done** — the four named no-context consumers (`CatalogoApiService`,
   `tarif_articulo_precio`, `tarif_tarifa_articulo`, `VentasOpcional`) resolve the configured
   default language in `slice-4c` (commit `8fd0eb1a`), and the quick-create path is verified as
   base-only (4c.6). The one deliberately-open task, **4c.5** (the `TarifarioOpcionalStateTrait`
   `codidioma` resolution), is now **closed by the `slice-4c5` follow-up**: the trait delegates
   to `catalogo_idioma::get_effective_default_code()` (explicit request precedence preserved),
   with three behavioral cases. See "Slice-4c5 task status".
4. **`verify`** must follow this artifact: slices 1, 2, 3, 4a, 4a-fix, 4b, 4b-wiring, 4b-mapping
   and 4c are delivered in full. The real-DB
   boot smoke, a real-DB composed-SQL smoke of `articulo::search()` (the D-04 join + `DISTINCT` +
   `ORDER BY a.referencia` against MySQL and PostgreSQL), a real-browser POST of the `#idiomas`
   panel and a real-browser selector round trip (`codidioma` GET → edit → POST → reload) remain for
   `verify`. The `catalogo_idioma::delete()` cache-invalidation gap (deviation 16) is **closed** by
   slice 4a-fix (deviation 19); `verify` should confirm it with a real-DB delete-and-search smoke.
   For slice 4b, `verify` should confirm the locale columns against a real workbook and a real-DB
   composed import (target language row written, base column untouched). Slice 4b-wiring closed the
   deferred 4b.7 + controller pass-through (deviation 26); `verify` should confirm the modal select
   and the `target_codidioma` request end-to-end in a real browser and confirm a
   `descripcion_<cod>` column maps end-to-end (the per-column mapping gap, deviation 26b, is
   closed by `slice-4b-mapping`, commit `ed340ef9`).
   For slice 4c, `verify` should run the **real-DB composed-SQL smoke** of the new
   `COALESCE(d.descripcion, a.descripcion)` join on MySQL **and** PostgreSQL (the
   `articulo_descripciones` left join must not multiply rows and must fall back to the base column
   when the configured default has no row), and confirm the public API and the Opcional
   autocomplete return the configured default text against a seeded registry.
5. **`size:exception` disposition for slice 2** (862 authored lines vs the 800 budget) awaits the
   maintainer, as recorded in "Budget measurement and cut decision".
6. **`size:exception` disposition for slice 4a** (861 authored lines vs the 800 budget) awaits the
   maintainer, as recorded in "Budget measurement and cut decision".
7. **Slice 5 (`tarifario` consumer migration) is done** — the last slice. Tasks 5.1–5.9 are
   `[x]`; the four work units are committed in the `plugins/tarifario` repo (`74e58f2`, `7b0ca9a`,
   `d6e837f`, `b58d40f`). The `tarifario` suite is green (266 tests) and the root Plugins suite
   carries only the pre-existing `OidcProvider` failures. **All apply slices are delivered; the
   change is ready for `verify`.**

## Hand-off to `verify`

- Every task in `tasks.md` is `[x]`; the four delta specs are unmerged (archive-time only); the
  core `openspec/` has no entry.
- The real-DB / real-browser smokes listed in item 4 above remain for `verify`, plus for slice 5:
  a **real-DB composed-SQL smoke** of the new `COALESCE(d.descripcion, a.descripcion)` join in
  `tarif_grupo_articulo::get_articulos_grupo()` / `buscar_articulos_disponibles()` and in
  `tarif_actualizar_precios::load_preview_articulos()` on MySQL **and** PostgreSQL (the
  `articulo_descripciones` left join must not multiply rows and must fall back to the base column
  when the configured default has no row), and a **real-browser export smoke** of
  `tarif_historial_precios`'s Excel with a configured default different from `es`.
- `verify` must read deviations 30–32 as deliberate, artifact-backed non-changes: the
  `tarif_roles.php:718` pass-through (resolved at the model boundary), the untouched
  `model/tarif_articulo.php`, and `tarif_catalogo_view.php`'s `es`/`en` literals.
