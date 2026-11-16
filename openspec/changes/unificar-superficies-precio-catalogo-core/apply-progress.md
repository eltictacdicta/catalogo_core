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
| WU-1.5 | PR-2 | done (commit e162a10) |
| WU-2.1 | PR-3 | done (commits a0bf4f3 catalogo_core, 9efef13 tarifario) |
| WU-2.2 | PR-3 | done |
| WU-3.1 | PR-4 | done (commit 078862c) |
| WU-3.2 | PR-5 | done (uncommitted, repo tarifario) |
| WU-3.4 | PR-5b | done (uncommitted, repo catalogo_core) |
| WU-3.5 | PR-5b | done (uncommitted, repo tarifario) |

GATE-3 closed (consumer change `aportar-target-tarifa-a-actualizar-precios`, owned by tarifario). Pending: WU-3.3 (PR-5), then F4.

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

## PR-3 (WU-2.1, WU-2.2) evidence

| Unit | RED | GREEN |
|---|---|---|
| WU-2.1 | `OpcionalesPriceUpdateScopeTest` (11) and `CanonicalOpcionalPriceBookTest` (9): errors, classes missing | 11/11 and 9/9 |
| WU-2.2 | Parity harness is test code, so the oracle is the legacy page itself; it was proven able to fail with mutation probes (below) | `PriceUpdateParityTest` 6 tests / 54 assertions |

Mutation probes (reverted afterwards): ordering optionals by id instead of
codigo fails 2 parity tests; a book that answers 0.0 for a missing row fails 4;
a book that inserts a missing row fails `testOptionalBookPersistsExistingRowOnly`.

| Evidence | Value |
|---|---|
| Plugin suite | `catalogo_core` 1671 tests / 6687 assertions, 2 warnings, 1 skipped (same warnings/skips as before) |
| Consumer suite | `tarifario` 284 tests / 1228 assertions, 2 skipped (baseline 278 + 6 parity tests) |
| Static analysis | `composer phpstan:plugins` no errors |
| Guardrails | `PriceSurfaceOwnershipTest` green (no consumer tokens in the seam files, allowlist unchanged) |
| Rollback boundary | Revert the PR-3 commit: the opcionales scope/book and the harness disappear; the page keeps working with articulos |

### Deviations (PR-3)

8. **Membership read.** WU-2.1 says to paginate `catalogo_opcional::all()`. The
   scope instead reads `catalogo_opcional_familia::get_opcionales_from_familia()`
   per family: that query has no `LIMIT`, so there is nothing to paginate and no
   silent cutoff (D11.1 equivalent). `testOptionalPaginationBeyondItemLimit` was
   named `testFamilyWithMoreOptionalsThanItemLimitIsComplete` (120 rows).
9. **Row key and labels.** The key is the opcional `id` (what the book is keyed
   by); `keyLabel()` is `ID` (design said `Código`, which would show an id under
   a "Código" header) and the label is `codigo — nombre`. Order is by `codigo`
   with `id` as tie-break.
10. **`canonicalBook($key)`** receives the target key (the list code), which the
    engine already passes; for `opcionales` it builds the book of that list.
11. **Shared family walk.** `ArticulosPriceScope` and `OpcionalesPriceScope` share
    a `FamilyTreeSelection` trait (behaviour of the articles scope unchanged).
12. **Harness shape.** The fake sits behind `fs_db2` as the static engine (not a
    fake `fs_db2` instance): the legacy page and the real models of both paths
    share one double, and the base book runs the real `catalogo_opcional_precio`
    over it. The article side of the seed (articles, tariff rows, role groups,
    golden file) is left to WU-3.3 with the `tarifa` target.

### Open risk for the user (decide before PR-6 / F4)

The legacy opcional apply saves through `tarif_opcional_precio::save()`, which
appends a row to `tarif_opcional_precio_historial` when the price changes. The
canonical book saves through `catalogo_opcional_precio::save()` (the design's
choice), which records **no history**. Once the legacy page is retired in F4,
batch updates of opcionales would stop being audited. The harness counts the
legacy history writes (`LegacyPriceUpdateFakeDb::$historyWrites`). Options: keep
it as is (history only for articles, as the design says), or add a neutral
post-persist hook so the consumer records the history.

## PR-4 (WU-3.1) evidence

| Evidence | Value |
|---|---|
| RED | `PriceUpdateFilterRegistryTest` (10) / engine (3 new) / controller (5 new) errored on the missing registry file; `CatalogoCoreHookMarkersTest` 3 failures (marker absent) |
| GREEN | registry 10/10; engine 16/16; hook markers 11/11 (50 assertions); controller 36/36 (104 assertions) |
| Plugin suite | `catalogo_core` 1694 tests / 6740 assertions, 2 warnings, 1 skipped (same as before) |
| Consumer suite | `tarifario` 284 tests / 1228 assertions, 2 skipped (unchanged) |
| Static analysis | `composer phpstan:plugins` no errors |
| Guardrails | `SEAM_FILES` extended with `/Services/PriceUpdateFilterRegistry.php`; allowlist still 52; the four frozen hooks untouched and absent of the new hook |
| Rollback boundary | Revert the PR-4 commit: without a registered filter the hook renders `''`, no hidden extras, no narrowing |

### Deviations (PR-4)

13. **Engine file touched.** WU-3.1's file list omits `CatalogoPriceUpdateService.php`,
    but design §4 puts `PriceUpdateFilterRegistry::narrow()` in the engine flow, so
    `preview()` now narrows `scope->match()` through the registry (3 new engine tests).
14. **Registry guarantees beyond D5.** `narrow()` intersects the filter result with
    the scope rows (a filter can only remove, never add or reorder), a throwing
    filter empties the selection (fail closed), `keys()` drops reserved engine
    fields and non snake_case names, and registering the same instance twice is
    idempotent. These make "never widen the default row set" (R-BU-002) a property
    of the registry instead of a promise of each consumer.
15. **Posted values for the hook.** Besides `price_update_filter_context()`
    (registry `viewContext`), the controller exposes `filter_extra` (posted values
    of the DECLARED keys only) so a consumer template can echo its current choice
    and the apply form can repost it.
16. **Hook position.** The marker sits after `pct` inside the filter form, before the
    preview button, in the exact R-CRH-001 form (asserted by source and by render).

17. **Seams for tests in `tarifario/Init.php`.** `targetRegistryClass()`,
    `filterRegistryClass()`, `newPriceUpdateTarget()` and `newPriceUpdateFilter()` are
    `protected` so the registration tests can simulate an absent or older owner and a
    failing registration inside one process (PSR-4 autoload cannot be undone).
18. **Hook template registered only with the filter registry.** The marker ships in
    the same owner release as `PriceUpdateFilterRegistry` (1.18.0), so the template is
    registered only when that class exists.
19. **Services take collaborators as `object`.** The target, the book and the filter
    accept duck-typed model doubles (`all_activas`, `all_for_referencias`/`get`+`save`,
    `all_activos`, `get_referencias_grupo`), so the 30 new tests need no database.
20. **`tarifario` is outside the `phpstan:plugins` scope**, so the new classes are
    linted (`php -l`) and tested but not analysed by that gate.

## TDD evidence (WU-3.2, repo tarifario)

| Unit | RED | GREEN |
|---|---|---|
| WU-3.2 | `TarifaPriceUpdateTargetTest`: error, `TarifaArticlePriceBook.php` missing (the other two files likewise) | 13/13, 9/9 (`TarifaRoleGroupPriceFilterTest`), 8/8 (`PriceUpdateTargetRegistrationTest`); RUN-TAR 314 tests / 1300 assertions (2 skipped, pre-existing) |

Mutation probes (restored and verified with `cmp`): book returns `0.0` for a missing
row → `testArticleWithoutTariffRowIsOmittedNeverZero` fails; filter keeps every row →
4 filter tests fail.

21. **D-OPC-HIST resolved by the user (option 2).** New units WU-3.4 (neutral event
    `catalogo_core.price_update_persisted`, R-BU-009, design D13) and WU-3.5
    (tarifario listener, R-TAR-PRC-008) in a new slice PR-5b, mandatory before PR-6.
    Not in the original task list; added to `tasks.md`, the spec delta and the design.
22. **The history model lives in `catalogo_core`, not in `tarifario`.**
    `tarif_opcional_precio_historial` and the history-writing `tarif_opcional_precio`
    ship in `plugins/catalogo_core/model/`. The decision still holds because the
    only case that lost the audit is `target=tarifa x scope=opcionales`, which is a
    tariff concept; list-target updates never had history.
23. **Engine behaviour of the event.** `apply()` reads the previous prices once for
    the whole batch, only when a listener exists, and a row persisted without a
    previous price reports `0.0` (same as the retired page). A throwing listener is
    logged and ignored; nothing is dispatched when no row persisted.

## TDD evidence (WU-3.4 / WU-3.5)

| Unit | RED | GREEN |
|---|---|---|
| WU-3.4 | `CatalogoPriceUpdateServiceTest`: 7 errors, `PriceUpdatePersistedEvent` missing | 24/24; RUN-CC 1702 tests / 6761 assertions (2 warnings, 1 skipped, pre-existing); phpstan clean |
| WU-3.5 | `TarifaOpcionalHistoryListenerTest` (class missing) and `testInitRegistersTheOpcionalHistoryListenerOnce` (0 !== 1) | 6/6, registration 10/10, parity 9/9; RUN-TAR 325 tests / 1326 assertions (2 skipped, pre-existing) |

Mutation probes (restored, `cmp` verified): previous price taken from the reposted
row, and "always listening", fail 2 engine tests; the listener ignoring the target
check fails 2 tests (list target and article/other target). History parity
(`testOptionalHistoryParityWithLegacyPage`) compares the legacy page's history rows
with the base engine plus the listener over the same fake engine; the zero-price
case leaves no history in either path, so the test also asserts that at least one
case exercises the history path.

### Pending gate

GATE-3 is closed: the consumer change lives in
`plugins/tarifario/openspec/changes/aportar-target-tarifa-a-actualizar-precios/`
(proposal, delta `tarifario/catalogo-integration` R-TAR-PRC-001..007, tasks). It is
a separate, tarifario-owned change that references this contract without
redefining it. Open decision carried by it: D-OPC-HIST (opcional batch history),
to be resolved before WU-4.2.

## TDD evidence (WU-3.3)

| Unit | RED | GREEN |
|---|---|---|
| WU-3.3 | Driver/fixture de artículos ausentes: `PriceUpdateParityTest` sin casos de artículos | `PriceUpdateParityTest` 17 tests / 209 assertions; RUN-TAR 333 tests / 1465 assertions (2 skipped, pre-existing) |

Mutation probes (restored, `cmp` verified): (1) `TarifaArticlePriceBook::currentPrices()`
reading `catalogo_articulo_precios` (trap row for canonical storage) fails 6 tests,
including `testArticleParityWhenCanonicalAndTariffRowsCoexist`; (2) the role-group
filter keeping every row fails 3 tests. An earlier malformed probe (nonexistent
class) was discarded and redone.

Golden: `Fixtures/price_update_parity_legacy.json` (13 cases) captured from the legacy
page before F4; regenerate only with `UPDATE_PRICE_PARITY_GOLDEN=1` (superseded in WU-4.5: the capture hook went away with the legacy page). It must stay after
WU-4.5 (the replay compares the base engine against the golden once the legacy driver
and fake DB are deleted). RUN-CC was not re-run: no catalogo_core file changed in this unit.

Known limitation: article ordering parity assumes uppercase ASCII references (SQL
collation vs `strcmp` in the fake engine).

## PR-6 / F4 evidence (WU-4.1 .. WU-4.5, atomic, two repos)

| Unit | RED | GREEN |
|---|---|---|
| WU-4.1 | `testSelectionRequiredNoticeIsWhitelisted`, `testNoticeDoesNotPreselectAnyTarget` (no `noticeFor()` / `prepareNotice()`) | `CatalogoActualizarPreciosControllerTest` 38 tests / 118 assertions |
| WU-4.2 | `LegacyRedirectShimTest` (no `build_redirect()`): 5 errors | 10 tests / 60 assertions; shim 85 lines |
| WU-4.3 | `PriceSurfaceOwnershipTest::testRetiredPriceUpdateViewIsAbsent` fails while the view exists | view deleted, three fixture entries removed; reference audit grep = 0 |
| WU-4.4 | `LegacyPricePageRetirementTest` (class missing) | 6 tests / 19 assertions |
| WU-4.5 | `testLegacyDriverIsGone` fails while the driver exists | `PriceUpdateParityTest` 13 tests / 90 assertions |

Full runs after F4: RUN-CC 1705 tests / 6771 assertions (2 warnings, 1 skipped,
pre-existing); RUN-TAR 345 tests / 1423 assertions (2 skipped, pre-existing);
`composer phpstan:plugins` clean.

Mutation probes (restored, `cmp` verified): `noticeFor()` reflecting unknown codes
fails the whitelist test; the redirect emitting `codlista` instead of `codtarifa`
fails 4 shim tests; `retire()` not deleting fails 2 retirement tests.

### Deviations (PR-6)

24. **Order inside the atomic slice.** WU-4.5 was done first so the parity test no
    longer needed the legacy controller before WU-4.2 rewrote it. Same slice, no
    intermediate state is deployable.
25. **Fake engine kept, renamed.** The design asked to delete `LegacyPriceUpdateFakeDb`
    with the driver, but the base engine replay still needs a fake `fs_db2` engine.
    It was renamed `PriceUpdateFakeDb`, its four legacy-only SELECT branches and two
    helpers were removed, and only `LegacyPriceUpdateDriver` was deleted. This is the
    design §7.1 contingency (replay against the golden over a fake engine).
26. **Golden regeneration.** `UPDATE_PRICE_PARITY_GOLDEN=1` is gone with the oracle.
    The golden can only be recaptured from the last revision of `tarifario` that still
    has the legacy page (git history). Removed paired tests: the legacy-vs-base
    comparisons now live in `testGoldenReplayMatchesLegacyOutput`; added
    `testGoldenCasesExerciseRealData`, `testListTargetWritesTheSameCanonicalRowsAsTheGolden`.
27. **Shim base class.** The shim extends `fs_controller` (not `tarif_controller`), so
    `TarifControllerLanguageCallsTest::CONTROLLERS` lost `tarif_actualizar_precios`
    (three subclasses remain). `build_redirect()` takes `(string $codtarifa, bool
    $tarifaTargetAvailable)`; there is no `$mirrorExists`. The percentage is not
    carried through the redirect.
28. **Notice transport.** `catalogo_actualizar_precios` exposes `public string $notice`
    and the view guards it with `fsc.notice is defined`, so existing view tests with
    plain `fsc` doubles keep working. `prepareSelection()` became `protected` as a test seam.
29. **Retirement seam.** `Init::upgrade()` calls `retireLegacyPricePage()`; the service
    swallows and logs failures (`[tarifario] legacy price page retirement failed`).
    The row can reappear hidden when the legacy URL is visited (design D12/C1).
