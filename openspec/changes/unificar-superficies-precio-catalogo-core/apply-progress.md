# Apply progress: unificar-superficies-precio-catalogo-core

Mode: Strict TDD. Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`.

## Completed

| Unit | Slice | Status |
|---|---|---|
| WU-0.1 | PR-1a | done (commits e86490c, 5aac2f2) |
| WU-1.1 | PR-1b | done |
| WU-1.2 | PR-1b | done |
| WU-1.3 | PR-1b | done |
| WU-1.4 | PR-1b | done |
| WU-1.5 | PR-2 | done (uncommitted) |

Pending: WU-2.1 (PR-3) onwards.

## TDD evidence (PR-1b)

| Unit | RED (contract absent / signature mismatch) | GREEN |
|---|---|---|
| WU-1.1 | `PriceUpdateTargetRegistryTest`: 8/8 errors, registry file missing | 9/9 (incl. `testRegisterDefaultsIsIdempotent` once WU-1.3 target existed) |
| WU-1.2 | `ArticulosPriceUpdateScopeTest` 12/12 and `CanonicalArticlePriceBookTest` 9/9 errors, files missing | 12/12 and 9/9 |
| WU-1.3 | `PriceListAvailabilityTest` fatal: `ListaPriceUpdateTarget.php` missing | 13/13 |
| WU-1.4 | `CatalogoPriceUpdateServiceTest` TypeError on the old `preview(array, float)` signature | 13/13 |
| Init | `testInitRegistersTheBaseTargetsOnce` failed (0 !== 1) | 9/9 |

Mutation probes (reverted afterwards): removing the pagination loop fails
`testFamilyLargerThanItemLimitIsFullyPaginated`; swapping to target-first
composition fails `testCompositionPrefersCanonicalBook`.

## Work Unit Evidence (PR-1b)

| Evidence | Value |
|---|---|
| Focused tests | `tests/Services` 270 tests / 682 assertions OK |
| Plugin suite | `catalogo_core` 1636 tests / 6598 assertions, 2 warnings, 1 skipped (baseline 1593 / 6503) |
| Consumer suite | `tarifario` 278 tests / 1174 assertions, 2 skipped (baseline unchanged) |
| Static analysis | `composer phpstan:plugins` no errors |
| Runtime harness | N/A: no new runtime boundary is exposed; the page still renders the old form until WU-1.5. A real-DB smoke of the page belongs to WU-1.5 |
| Guardrails | G-1/G-2/G-4/G-6 verified (greps empty, allowlist still 52); `SEAM_FILES` extended with the four new seam files |
| Rollback boundary | Revert the PR-1b commit: removes 4 services + 3 tests, restores the engine, `Init`, controller call sites and the migrated tests |

## Deviations from design / tasks

1. **Delegates not kept.** design §12 and WU-1.4 say to keep
   `matchArticles()`, `preview($filters, $pct)` and `apply($rows, $codlista)`
   as thin delegates. PHP cannot overload `preview`/`apply`, and the new
   contract (design §5) uses the same names with a different signature.
   Instead the controller call sites were migrated in this slice
   (`resolveCollaborators()` + `toViewRows()`, marked transitional). The view
   and the old (still broken) apply form are untouched until WU-1.5.
2. **`ArticulosPriceScope::canonicalBook()` returns `null`** (design D1/§5).
   WU-1.2's note ("nunca devuelve null para artículos con target") contradicts
   D1, WU-1.3 (`bookFor('articulos')` = canonical book) and the engine
   composition. D1 was followed; the canonical article book is provided by the
   `lista` target through `CanonicalPriceBooks::articulos()`.
3. **Batch read of the canonical book** uses `catalogo_articulo_precio::all_from_lista()`
   (one query for the whole list) and `articulo::get()` per missing key only on
   the default list (pvp fallback). No model file was modified.
4. **Row label** is the article `descripcion` when the family path knows it,
   otherwise the reference (the group-only path has no descriptions).
5. **Extra tests** beyond the names in tasks: factory fail-closed, default-code
   fallbacks, key-from-filters fail-closed, `testInitRegistersTheBaseTargetsOnce`.
6. `testEveryPriceListSurfaceUsesTheSameRule` now audits
   `Services/ListaPriceUpdateTarget.php` in addition to the controller (the
   controller is dropped from the list in WU-1.5).
7. `apply()` with a missing book reports every row as failed (instead of
   silently returning 0 updated); a missing key returns `updated 0, failed []`
   as before.

## PR-2 (WU-1.5) evidence

| Evidence | Value |
|---|---|
| RED | 13 controller tests: 10 errors (scope contract not loadable from the test double) + 3 failures from the old view (selectors, repost, neutral nouns) |
| GREEN | `CatalogoActualizarPreciosControllerTest` 30 tests / 91 assertions OK (includes two real Twig render tests: escaped repost of hidden inputs, inert page without targets) |
| Plugin suite | `catalogo_core` 1651 tests / 6654 assertions, 2 warnings, 1 skipped (same warnings/skips as PR-1b) |
| Consumer suite | `tarifario` 278 tests / 1174 assertions, 2 skipped (baseline unchanged) |
| Static analysis | `composer phpstan:plugins` no errors |
| Behaviour fixed | `#[AdminOnly]` declared, 4th ctor arg `FALSE`; apply form reposts target/scope/key/pct/filters (was broken); neutral nouns; page inert without targets; unknown target/scope fail closed |
| Added | `PriceUpdateScopes::labels()` (+ test) for the scope selector |
| Rollback boundary | Revert the PR-2 commit; attribute removal downgrades `fs_pages.admin_only` on next visit, no data to undo |
| Pending | Real-DB browser smoke of the page was not run (no Kernel in tests) |
