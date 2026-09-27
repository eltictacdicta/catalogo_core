# Apply Progress: caracteristicas-plugin-scope

> Change: `caracteristicas-plugin-scope` — plugin `catalogo_core`
> (`ownership: plugin-local`, `strict_tdd: true`, artifact store `openspec`).
> SDD root: `plugins/catalogo_core/openspec/`.
> This artifact covers **Work Unit 1 / PR 1** (Phase 1–2), **Work Unit 2 /
> PR 2** (Phase 3), **Work Unit 3 / PR 3** (Phase 4), **Work Unit 4 /
> PR 4** (Phase 5–6) and **Work Unit 5 / PR 5** (Phase 7 — W1 remediation).
> All phases are complete. Batch boundary for the later commit/PR step is
> recorded under "Workload / PR Boundary".

## Mode

**Strict TDD** (`strict_tdd: true`, runner
`ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`).

## Completed Tasks

### Phase 1 — Foundation: ownership predicate (D1, D2, D7)

- [x] 1.1 RED — created `tests/CaracteristicaOwnershipTest.php` with the DB-free
  `enabled_plugins()` seam cases (`''` ⇒ true; enabled ⇒ true; disabled ⇒ false;
  whitespace trimmed).
- [x] 1.2 RED — CAR-20 (d) fail-closed: real helper with `$GLOBALS['plugins'] = []`.
- [x] 1.3 GREEN — created `Services/CaracteristicaOwnership.php`.
- [x] 1.4 RED — CAR-11 scenario: plugin-owned inert while disabled and
  `is_deletable() === false`; re-enable on a fresh helper keeps it non-deletable.
- [x] 1.5 Verify Phase 1 in isolation — all green, no DB access.

### Phase 2 — Resolver choke point (D3, D8, D9)

- [x] 2.1 RED — owner-disabled definition absent from `definitions()` and all
  flagged helpers; `resolve()`/`resolve_bool()` ⇒ `null` like missing/inactive;
  no value row created or modified.
- [x] 2.2 GREEN — `CaracteristicaResolver`: `require_once` the helper; untyped
  `protected ownership()`; `'origen'` in **both** `to_definition()` branches;
  `definitions()` skips `codigo === ''` or inert rows. `flagged_definitions()`
  unchanged.
- [x] 2.3 RED — CAR-12 owner-disabled visibility resolves `null` for every tarifa
  with no write.
- [x] 2.4 Verify 2.2 satisfies 2.3 — `--filter OpcionalVisibility` green.
- [x] 2.5 RED — store with a resolver-backed `definitions()`: inert `codigo` makes
  `assign_bool`/`assign_custom`/`clear` return `false` with zero scope models;
  re-enable on a fresh store restores the write with no row touched during the
  inert period (CAR-20 b).
- [x] 2.6 Verify Phase 2 combined — green.

### Phase 3 — Batch reader: constant-query inert filter (D5)

- [x] 3.1 RED — extended
  `tests/Controller/VentasArticulosListCaracteristicasTest.php` with a counting DB
  double returning mixed operator/inert definition rows plus a reader that uses the
  real `CaracteristicaOwnership` through the default seam:
  `test_inert_listable_definition_emits_no_column_and_base_headers_stay_intact`,
  `test_enabling_the_owner_restores_the_listable_column`,
  `test_feature_query_count_is_constant_across_page_size_and_inert_row_count`,
  `test_no_tarifa_selected_emits_no_feature_column_for_inert_definitions`. Seed
  `$GLOBALS['plugins'] = []` in `setUp()`. Failed for the right reason.
- [x] 3.2 GREEN — `CaracteristicaValorBatchReader`: `require_once` the helper;
  untyped `protected ownership()`; `origen` added to the `definition_rows()`
  `SELECT`; rows filtered with one `array_values(array_filter(...))` PHP pass over
  the existing single fetch. No per-row query, no SQL `IN (...)` of plugin names,
  no extra round-trip.
- [x] 3.3 Verify Phase 3 — `columns()` excludes inert definitions and
  `for_referencias()` inherits the same filter (its value-map keys drop inert
  codigos); query count independent of N and of the inert-row count.

### Phase 4 — Panel listing + fail-closed action guards (D4, D6)

- [x] 4.1 RED — extended `tests/Controller/VentasCaracteristicasControllerTest.php`
  with listing + direct-action refusal cases:
  `test_inert_definitions_are_absent_from_the_panel_listing`,
  `test_operator_owned_definition_stays_listed_and_writable`,
  `test_save_definition_refuses_an_inert_target_and_persists_nothing`,
  `test_toggle_flag_refuses_an_inert_target_and_persists_nothing`,
  `test_delete_definition_refuses_an_inert_target_and_persists_nothing`,
  `test_save_catalogo_valor_refuses_an_inert_target_and_persists_nothing`,
  `test_delete_catalogo_valor_refuses_an_inert_target_and_persists_nothing`. All
  seven failed for the right reason.
- [x] 4.2 GREEN — `Controller/VentasCaracteristicas.php`: `require_once` the helper;
  untyped `protected ownership()`; `private definition_is_inert($definition)`;
  `load_definiciones()` filters `array_values(array_filter(...))`. The view
  (`View/ventas_caracteristicas.html.twig`) is unchanged.
- [x] 4.3 GREEN — five guards added after CSRF/target resolution and before any
  mutation: `save_definition` (after `$existing`), `delete_definition` (after
  `$definition`, before the permission check), `toggle_flag` (after `$definition`),
  `save_catalogo_valor` (target resolved by `get_by_id()`, else by `get()`),
  `delete_catalogo_valor` (value row loaded, its `id_caracteristica` resolved via
  `get_by_id()`). Each emits `new_error_msg(...)` and returns. No guards added to
  `assign_value`/`clear_value` (refused transitively by `CaracteristicaValorStore`).
- [x] 4.4 Verify Phase 4 — `--filter VentasCaracteristicasController` green; every
  refusal left the shared write recorder at zero saves/deletes.

### Phase 5 — Inherited surfaces, boundary gate, cross-path agreement

- [x] 5.1 RED — extended
  `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php` with
  `test_owner_disabled_definition_contributes_no_import_field_alias_or_export_column`
  plus the `resolverFixture()` / `resolverWith()` helpers: an owner-disabled
  `importable`/`exportable` definition contributes no import field, no alias and no
  export column, and the base headers stay byte-identical (CAR-17 owner-disabled).
  The owner is re-enabled at the end on **fresh** resolver instances (D9) to prove
  the empty result was a real fail-closed no-op, not a dead fixture. RED was
  observed by neutralizing the resolver ownership filter: `importable_definitions()`
  / `exportable_definitions()` then included `en_catalogo` and the first assertion
  failed (`the inert definition is not importable`).
- [x] 5.2 GREEN/verify — no wizard production change was needed: the behavior is
  inherited from the Phase 2 resolver filter, and the test passes against it
  (`OK, 7 tests, 64 assertions`).
- [x] 5.3 RED — extended `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php`
  with the token-stripped source-scan gate (CAR-19, CAR-20 e):
  `test_no_root_openspec_entry_exists_for_this_change`,
  `test_ownership_helper_reads_only_origen_and_the_core_registry` (reads only the
  row `origen` and `\FSFramework\Core\Plugins`, contains no
  `FSFramework\Plugins\tarifario` namespace and no hardcoded `'tarifario'` literal)
  and `test_the_three_wiring_sites_call_the_shared_ownership_rule` (the resolver,
  the panel controller and the batch reader import `CaracteristicaOwnership` and
  call `ownership()->is_active(`). The frozen tarifario-reference baseline
  (`test_catalogo_core_production_references_only_the_frozen_baseline`) is
  unchanged and still green. RED was observed with the batch reader's wiring absent
  (the aborted run had removed the filter): the wiring-site gate failed on the
  un-matched `ownership()->is_active(` pattern in `CaracteristicaValorBatchReader.php`.
- [x] 5.4 RED — added
  `tests/CaracteristicaOwnershipTest.php::test_all_three_read_paths_agree_on_the_active_set()`
  plus the `threePathFixture()` / `activeSetsFromThreeReadPaths()` helpers: one
  shared fixture (operator row `origen = ''` + plugin row `origen = 'tarifario'`)
  fed to (1) a resolver with a fixture definition model, (2) the panel
  `load_definiciones()` with a fixture definition model and (3) the batch reader
  with a fixture DB. The three active `codigo` sets are identical for the disabled
  state (`['medidas']`) and again for the re-enabled state
  (`['en_catalogo', 'medidas']`), building fresh instances after the toggle (D9).
  The resolver-neutralization RED made the resolver/panel sets include `en_catalogo`
  while the reader stayed filtered; the batch-reader-wiring RED made the reader
  disagree instead. Both directions were observed.
- [x] 5.5 Audit — ran the four read-only fixtures; all green:
  `CaracteristicaDefaultsTest` (6 tests / 35 assertions),
  `CaracteristicaModelTest` (8 tests / 49 assertions),
  `CaracteristicaHookContextTest` (4 tests / 20 assertions),
  `ArticuloExcelFeaturePersistenciaTest` (7 tests / 21 assertions). None was edited.

### Phase 6 — Verification and boundary confirmation

- [x] 6.1 Plugin suite —
  `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` →
  **`OK, but there were issues! Tests: 1009, Assertions: 4418, Warnings: 2, Skipped: 1.`**
  (0 failures / 0 errors; the 2 warnings and the skip are pre-existing and outside
  the affected files).
- [x] 6.2 Root suite — `ddev exec php vendor/bin/phpunit` →
  **`OK, but some tests were skipped! Tests: 2816, Assertions: 10762, Skipped: 25.`**
  (0 failures / 0 errors; read-only verification, no core file modified).
- [x] 6.3 Plugin linter — `ddev exec composer phpstan` (root `phpstan.neon`,
  `paths: [src, tests]`) → **1 pre-existing error**:
  `tests/Core/PluginEnableAjaxSafetyTest.php:308` (`Tests\Core\AjaxGuardTestPluginManager::applyPluginSchemaUpdates()`
  returns `array{success: true, errors: array{}}` without the declared `changes`
  offset). That file is a repository-root core test, **unmodified** by this change
  (`git status --short tests/Core/PluginEnableAjaxSafetyTest.php` is empty) and
  outside `plugins/catalogo_core/`. No type was weakened and no test was deleted to
  satisfy it; the plugin tree is not in the analyzer's `paths`.
- [x] 6.4 Boundary confirmation from the applied tree: the root repository status is
  **clean** (all plugin changes live in the plugin's own repository); every changed
  file is under `plugins/catalogo_core/`. `plugins/tarifario/` is untouched; the
  repository-root `openspec/changes/caracteristicas-plugin-scope` does not exist;
  the delta spec and the canonical
  `openspec/specs/caracteristicas-producto/spec.md` are unmodified.
- [x] 6.5 Constant-query contract (CAR-16) asserted by the Phase 3 test
  `VentasArticulosListCaracteristicasTest::test_feature_query_count_is_constant_across_page_size_and_inert_row_count`
  (`plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php:269`):
  the feature query count is independent of page size N and of the inert-row count
  (one PHP pass over the existing single fetch; no per-row query, no extra
  round-trip). Green.

### Phase 7 — Remediation (W1)

> Closes verify W1: the boot-time `CaracteristicaBackfillMigration::migrateIfNeeded()`
> could `INSERT … WHERE NOT EXISTS` mirror value rows for an inert definition
> (non-empty `origen`, owner plugin not enabled), contradicting CAR-20's
> absolute "no writes during the inert period". The migration now consults the
> same `CaracteristicaOwnership` rule as the rest of the change and skips inert
> definitions before any `INSERT`. Delta/canonical specs were not modified (the
> requirement already stated the invariant).

- [x] 7.1 RED — extended `plugins/catalogo_core/tests/CaracteristicaBackfillTest.php`
  with the CAR-20 inert/re-enable case plus two triangulation cases:
  `test_inert_definition_is_not_written_and_re_enabling_backfills_normally`,
  `test_operator_owned_definition_backfills_with_an_empty_registry` and
  `test_only_the_inert_definition_is_skipped`. The fake DB's definition lookup
  now tolerates both `SELECT id` and `SELECT id, origen` and returns the row
  `origen`; the fixture definitions carry `origen`. RED observed against the
  non-CAR-20-aware migration: `Tests: 10, Assertions: 23, Failures: 2` — the
  inert period emitted 2 `INSERT`s (`catalogo_caracteristica_articulo` for
  `id_caracteristica` 10 and 11) and the inert definition materialized value
  `111`.
- [x] 7.2 GREEN — `Services/CaracteristicaBackfillMigration.php`:
  `require_once` the ownership helper; added `private static ownership()`;
  `flag_definitions()` now resolves the definition row via `definition_row()`
  (`SELECT id, origen …`) and `continue`s on
  `!self::ownership()->is_active($definition['origen'])` before any value lookup
  or `INSERT`; the obsolete `definition_id()` was replaced. Active-definition
  mapping semantics are unchanged (same columns, same `INSERT … WHERE NOT EXISTS`
  shapes, additive-only, idempotent). No plugin literal, no tarifario import.
  Focused verify: `--filter CaracteristicaBackfill` → **`OK (10 tests, 26
  assertions)`**.
- [x] 7.3 Verify — full plugin suite green (see Work Unit Evidence); boundary
  re-confirmed (all changes under `plugins/catalogo_core/`; `plugins/tarifario/`
  clean; root tree clean for `base/ src/ controller/ model/`; no repository-root
  `openspec/changes/caracteristicas-plugin-scope`; delta spec and canonical spec
  unmodified). `ddev exec composer phpstan` reproduces only the recorded
  pre-existing error in the unmodified root `tests/Core/PluginEnableAjaxSafetyTest.php:308`.

## Files Changed

| File | Action | What Was Done |
|------|--------|---------------|
| `plugins/catalogo_core/Services/CaracteristicaOwnership.php` | Created | `is_active(string $origen): bool` (`trim`; `''` ⇒ true; else `in_array($owner, $this->enabled_plugins(), true)`) + `protected enabled_plugins(): array` returning `\FSFramework\Core\Plugins::enabled()`; CAR-20/D7/D9 docblock. Non-final class. |
| `plugins/catalogo_core/Services/CaracteristicaResolver.php` | Modified | `require_once` the helper; added `protected ownership()`; added `'origen'` to both `to_definition()` branches; `definitions()` now skips `codigo === ''` or inert `origen`. |
| `plugins/catalogo_core/Services/CaracteristicaValorBatchReader.php` | Modified | `require_once` the helper; added `protected ownership()`; `origen` added to the `definition_rows()` SELECT; inert rows dropped by one PHP pass (`array_values(array_filter(...))`) — constant query count. |
| `plugins/catalogo_core/Controller/VentasCaracteristicas.php` | Modified | `require_once` + `use` the helper; added `protected ownership()` and `private definition_is_inert()`; `load_definiciones()` filters the listing; five fail-closed action guards (`save_definition`, `delete_definition`, `toggle_flag`, `save_catalogo_valor`, `delete_catalogo_valor`). |
| `plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php` | Created | Predicate unit/edge cases, fail-closed real-registry case, CAR-11 inert + non-deletable + re-enable case, and (Phase 5.4) the three-path agreement test with its shared fixture/helpers. |
| `plugins/catalogo_core/tests/CaracteristicaResolverTest.php` | Modified | `buildResolver()` gained the `ownership()` seam; three CAR-20/CAR-06 tests (three-way null, flagged helpers, operator row). |
| `plugins/catalogo_core/tests/OpcionalVisibilityDerivationTest.php` | Modified | `buildResolver()` gained `origen` + ownership seam; CAR-12 owner-disabled visibility test. |
| `plugins/catalogo_core/tests/CaracteristicaValorStoreTest.php` | Modified | Added resolver-backed filtering store + CAR-20 (b) inert/re-enable write test and a local fake definition model. |
| `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php` | Modified | Mixed-rows counting DB double + mixed reader; CAR-16 owner-disabled column exclusion, N/inert-count-independent query count, `for_referencias()` inheritance, base-header byte-identity and no-tarifa cases. |
| `plugins/catalogo_core/tests/Controller/VentasCaracteristicasControllerTest.php` | Modified | Panel fixture model/entity/value doubles with a shared persistence recorder; listing-hidden, operator-writable and five direct-action refusal tests. |
| `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php` | Modified | (Phase 5.1) owner-disabled import/alias/export exclusion test + `resolverFixture()`/`resolverWith()` helpers; `$GLOBALS['plugins']` snapshot/restore in `setUp()`/`tearDown()`. |
| `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php` | Modified | (Phase 5.3) source-scan gate: root-openspec absence, helper reads only `origen` + core registry (no tarifario coupling), and the three wiring sites call the shared rule. Frozen baseline unchanged. |
| `plugins/catalogo_core/Services/CaracteristicaBackfillMigration.php` | Modified | (Phase 7, W1) `require_once` the helper + `private static ownership()`; `flag_definitions()` resolves `id, origen` via `definition_row()` and skips inert definitions before any `INSERT`; `definition_id()` replaced. Active-definition mapping semantics unchanged. |
| `plugins/catalogo_core/tests/CaracteristicaBackfillTest.php` | Modified | (Phase 7, W1) definition-row fake tolerates `SELECT id[, origen]` and returns `origen`; fixture `origen`; inert/re-enable case + two triangulation cases; `withEnabledPlugins()` registry helper. |

No file outside `plugins/catalogo_core/` was created or modified.
`View/ventas_caracteristicas.html.twig`, `plugins/tarifario/` and the
repository-root `openspec/` are untouched.

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1–1.2 | `tests/CaracteristicaOwnershipTest.php` | Unit | N/A (new) | ✅ Written (require of missing helper errored) | ✅ 5/5 passed | ✅ empty/mixed/enabled sets + whitespace + fail-closed | ➖ Clean as written |
| 1.3 | `Services/CaracteristicaOwnership.php` | Unit | N/A (new) | ✅ Covered by 1.1–1.2 | ✅ `--filter CaracteristicaOwnership` 5 tests / 18 assertions | ✅ (see above) | ➖ None needed (3-line predicate) |
| 1.4 | `tests/CaracteristicaOwnershipTest.php` | Integration | N/A (new) | ✅ Written with real registry + model | ✅ Passed with 1.3 | ✅ disabled vs re-enabled on fresh instances | ➖ None needed |
| 2.1 | `tests/CaracteristicaResolverTest.php` | Unit | ✅ 33/33 (filter baseline) | ✅ Written; failed `'X' is null` (inert not filtered) | ✅ Passed with 2.2 | ✅ inert vs missing vs inactive + flagged helpers + operator row | ➖ None needed |
| 2.2 | `Services/CaracteristicaResolver.php` | Unit | ✅ same | ✅ Covered by 2.1/2.3/2.5 | ✅ `--filter CaracteristicaResolver` green | ✅ three new tests | ➖ Docblock only |
| 2.3 | `tests/OpcionalVisibilityDerivationTest.php` | Integration | ✅ 33/33 | ✅ Written; failed `true is null` (inert ignored) | ✅ Passed with 2.2 | ✅ inert + re-enabled fresh instance | ➖ None needed |
| 2.5 | `tests/CaracteristicaValorStoreTest.php` | Unit | ✅ 33/33 | ✅ Written; failed `true is false` (inert codigo writable) | ✅ Passed with 2.2 | ✅ three public write entries + zero-model + re-enable | ➖ None needed |
| 3.1 | `tests/Controller/VentasArticulosListCaracteristicasTest.php` | Integration | ✅ 6/6 (filter baseline) | ✅ Written; failed "an owner-disabled listable definition must emit no column" (`['medidas','en_catalogo']`) | ✅ Passed with 3.2 (`--filter VentasArticulosListCaracteristicas` 10 tests / 60 assertions) | ✅ inert vs re-enabled + N=2 vs N=10 + inert-count + `for_referencias()` keys + no-tarifa | ➖ Test helper extracted (`mixedReader`) |
| 3.2 | `Services/CaracteristicaValorBatchReader.php` | Integration | ✅ same | ✅ Covered by 3.1 | ✅ Passed with 3.2 | ✅ (see 3.1) | ➖ Docblock only |
| 4.1 | `tests/Controller/VentasCaracteristicasControllerTest.php` | Integration | ✅ 11/11 (filter baseline) | ✅ Written; 7 new tests failed (listing showed the inert row; each action wrote once) | ✅ Passed with 4.2/4.3 (`--filter VentasCaracteristicasController` 18 tests / 74 assertions) | ✅ listing-hidden + operator-writable + five actions × (inert refused, zero writes) + `codigo` fallback | ➖ Fixture doubles grouped in one section |
| 4.2/4.3 | `Controller/VentasCaracteristicas.php` | Integration | ✅ same | ✅ Covered by 4.1 | ✅ Passed with 4.2/4.3 | ✅ (see 4.1) | ➖ Docblocks only |
| 5.1/5.2 | `tests/Services/ArticuloExcelCaracteristicaTest.php` | Integration | ✅ 6/6 (filter baseline) | ✅ Observed with the resolver ownership filter neutralized: `importable_definitions()`/`exportable_definitions()` included `en_catalogo`; failed `the inert definition is not importable` | ✅ Passed with the Phase 2 resolver filter (`--filter ArticuloExcelCaracteristicaTest` 7 tests / 64 assertions) | ✅ inert exclusion + operator retention + byte-identical base headers + re-enable control on fresh resolvers | ➖ Fixture/resolver helpers extracted |
| 5.3 | `tests/CaracteristicaBoundariesTest.php` | Boundary (source scan) | ✅ 6/6 (filter baseline) | ✅ Observed with the batch-reader wiring absent: the wiring-site gate failed (no `ownership()->is_active(` in the reader source) | ✅ Passed with all three wiring sites present (`--filter CaracteristicaBoundaries` 9 tests / 151 assertions) | ✅ root-openspec absence + helper-coupling scan + three-site scan + untouched frozen baseline | ➖ New scan cases grouped before the existing baseline tests |
| 5.4 | `tests/CaracteristicaOwnershipTest.php` | Integration (cross-path) | ✅ 5/5 (filter baseline) | ✅ Observed both ways: resolver-neutralized RED made resolver/panel include `en_catalogo`; batch-reader-wiring RED made the reader disagree | ✅ Passed with all three paths wired (`--filter CaracteristicaOwnership` 6 tests / 24 assertions) | ✅ disabled `['medidas']` vs re-enabled `['en_catalogo','medidas']`, fresh instances after the toggle | ➖ Three-path helpers extracted |
| 5.5 | `CaracteristicaDefaultsTest`, `CaracteristicaModelTest`, `CaracteristicaHookContextTest`, `ArticuloExcelFeaturePersistenciaTest` | Audit (read-only) | N/A (asserted-green) | ➖ No new test (audit task) | ✅ 6/35, 8/49, 4/20, 7/21 all green | ➖ None | ➖ None (not edited) |
| 6.1–6.5 | full plugin + root suites, phpstan, boundary checks | Verification | N/A | ➖ No new test (verification task) | ✅ See exact results in Phase 6 above | ➖ None | ➖ None |
| 7.1 | `tests/CaracteristicaBackfillTest.php` | Integration | ✅ 7/7 (filter baseline: 7 tests / 19 assertions) | ✅ Written; inert period emitted 2 `INSERT`s and the inert definition wrote value `111` (`Tests: 10, Failures: 2`) | ✅ Passed with 7.2 (`--filter CaracteristicaBackfill` 10 tests / 26 assertions) | ✅ inert zero-write + re-enable writes + operator `origen=''` exception + per-definition skip | ➖ Test fake made shape-tolerant; `withEnabledPlugins()` helper extracted |
| 7.2 | `Services/CaracteristicaBackfillMigration.php` | Integration | ✅ same | ✅ Covered by 7.1 | ✅ Passed with 7.2 | ✅ (see 7.1) | ➖ Docblock only; `definition_id()` → `definition_row()` |
| 7.3 | full plugin suite + boundary checks | Verification | N/A | ➖ No new test (verification task) | ✅ `OK, but there were issues! Tests: 1012, Assertions: 4425, Warnings: 2, Skipped: 1.` | ➖ None | ➖ None |

RED evidence (Phase 5.1, resolver filter neutralized), exact output:
`Tests: 7, Assertions: 47, Failures: 1` —
`test_owner_disabled_definition_contributes_no_import_field_alias_or_export_column`
failed at `the inert definition is not importable` (expected `['medidas']`,
actual `['medidas', 'en_catalogo']`).

RED evidence (Phase 5.3/5.4, batch-reader wiring absent), exact output:
`Tests: 6, Assertions: 21, Failures: 1` (ownership three-path: the batch reader
returned `['en_catalogo', 'medidas']` while the resolver/panel returned
`['medidas']`); `Tests: 9, Assertions: 151, Failures: 1` (boundaries: the
three-wiring-sites gate found no `ownership()->is_active(` in
`CaracteristicaValorBatchReader.php`).

RED evidence (before 3.2 landed), exact output:
`Tests: 10, Assertions: 45, Failures: 1` — the single failure was
`test_inert_listable_definition_emits_no_column_and_base_headers_stay_intact`
(actual included `'en_catalogo'`).

RED evidence (before 4.2/4.3 landed), exact output:
`Tests: 18, Assertions: 66, Failures: 7` — the listing test saw both rows and
each of the five actions persisted once (`Failed asserting that 1 is identical to 0`).

RED evidence (Phase 7, W1 — before the migration became CAR-20-aware), exact output:
`Tests: 10, Assertions: 23, Failures: 2` —
`test_inert_definition_is_not_written_and_re_enabling_backfills_normally` failed
at `no INSERT may be emitted for an inert definition` (actual: 2 `INSERT`s into
`catalogo_caracteristica_articulo` for `id_caracteristica` 10 and 11 while
`$GLOBALS['plugins'] = []`), and `test_only_the_inert_definition_is_skipped`
failed at `the inert definition writes nothing` (`Failed asserting that 111 is null`).
The third case (`test_operator_owned_definition_backfills_with_an_empty_registry`)
is a guard for the `origen = ''` unconditional exception; it passes before and
after by design.

Full plugin suite (fixture-regression check), exact output:
`OK, but there were issues! Tests: 1009, Assertions: 4418, Warnings: 2, Skipped: 1.`
(0 failures / 0 errors).

## Work Unit Evidence

| Evidence | Required value |
|---|---|
| Focused test command and exact result (Unit 2 / Phase 3) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticulosListCaracteristicas` → **OK (10 tests, 60 assertions)** |
| Focused test command and exact result (Unit 3 / Phase 4) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasCaracteristicasController` → **OK (18 tests, 74 assertions)** |
| Focused test command and exact result (Unit 4 / Phase 5) | `--filter ArticuloExcelCaracteristicaTest` → **OK (7 tests, 64 assertions)**; `--filter CaracteristicaBoundaries` → **OK (9 tests, 151 assertions)**; `--filter CaracteristicaOwnership` → **OK (6 tests, 24 assertions)** |
| Focused test command and exact result (Unit 5 / Phase 7) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CaracteristicaBackfill` → **OK (10 tests, 26 assertions)** |
| Runtime harness command/scenario and exact result | Real `CaracteristicaOwnership` through the default `ownership()` seam with the real `\FSFramework\Core\Plugins::enabled()` / `$GLOBALS['plugins']`, seeded `[]` then `['tarifario']`: the excel import/alias/export exclusion, the batch-reader column/value exclusion, the panel listing/guard refusals, the three-read-path agreement **and the Phase 7 boot-backfill inert skip/re-enable** all run the real registry path (no DB). The whole plugin suite is the integration harness. |
| Whole plugin suite (fixture-regression check) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **OK, Tests: 1009, Assertions: 4418, Warnings: 2, Skipped: 1, 0 failures/errors** (same warning/skip profile as before this batch). After Phase 7 (W1): **OK, but there were issues! Tests: 1012, Assertions: 4425, Warnings: 2, Skipped: 1** (0 failures/errors; +3 tests / +7 assertions from the remediation). |
| Root suite (regression) | `ddev exec php vendor/bin/phpunit` → **OK, Tests: 2816, Assertions: 10762, Skipped: 25, 0 failures/errors**. |
| Plugin linter | `ddev exec composer phpstan` → **1 pre-existing error** in the unmodified repository-root `tests/Core/PluginEnableAjaxSafetyTest.php:308` (outside `plugins/catalogo_core/`; the analyzer `paths` are `src` and `tests` only). No type weakened, no test deleted. |
| Rollback boundary (Unit 4) | Revert the Phase 5 cases in `tests/Services/ArticuloExcelCaracteristicaTest.php`, `tests/CaracteristicaBoundariesTest.php` and the three-path section in `tests/CaracteristicaOwnershipTest.php`. Production behavior is delivered entirely by PR 1–3; the revert removes only the inherited-surface/boundary/cross-path assertions, never any production wiring. |
| Rollback boundary (Unit 2) | Revert `Services/CaracteristicaValorBatchReader.php` (require + `ownership()` + `origen` in the SELECT + PHP filter) and the new cases in `tests/Controller/VentasArticulosListCaracteristicasTest.php`. The resolver (Unit 1) and the controller (Unit 3) wiring stay; the revert restores the prior unfiltered batched read only. |
| Rollback boundary (Unit 3) | Revert `Controller/VentasCaracteristicas.php` (require/use + `ownership()` + `definition_is_inert()` + `load_definiciones()` filter + the five guards) and the new cases in `tests/Controller/VentasCaracteristicasControllerTest.php`. The resolver (Unit 1) and batch reader (Unit 2) stay; the revert restores the prior unfiltered panel listing/actions only. The view was never touched, so a revert restores "list everything" exactly. |
| Rollback boundary (Unit 5 / Phase 7) | Revert `Services/CaracteristicaBackfillMigration.php` (require + `ownership()` + `definition_row()`/inert skip) and the new cases in `tests/CaracteristicaBackfillTest.php`. The three read-path wiring sites (Units 1–3) stay; the revert restores the previous (non-CAR-20-aware) add-only backfill only, with no effect on any other surface. |

## Deviations from Design

- Phase 7 (W1 remediation) is a **deliberate, reviewed extension beyond design
  D1–D9**: the design scoped CAR-20 to the three read paths (resolver, panel,
  batch reader). Verify W1 found the boot-time `CaracteristicaBackfillMigration`
  was a fourth writer that bypassed the rule. The fix reuses the **existing**
  `CaracteristicaOwnership` helper (no new helper, no new contract, no spec
  change) and adds a fourth call site on a write path; the `CaracteristicaBoundariesTest`
  "three wiring sites" gate still pins the read paths and is unchanged.
- None functional otherwise — implementation matches D1–D9 and tasks 2.2 / 3.2 /
  4.2–4.3 / 5.1–5.5.
- Test-seam note: `save_catalogo_valor` now resolves the target with
  `get_by_id()` when `id_caracteristica > 0` (the design requires it so the guard
  can inspect the owner). That is one extra lookup on a mutating POST action only,
  never on the hot read path; no read-path query count changed.
- Batch of 4 aborted mid-way: it had left `Services/CaracteristicaValorBatchReader.php`
  with the CAR-20 PHP filter replaced by `return array_values($rows); // MUTATION-RED`.
  That marker was a temporary RED demonstration artifact, not intended code; the
  filter was restored to the D5 shape (`array_values(array_filter(... ownership()->is_active ...))`)
  and is verified green. Net vs the recorded Phase 3 slice: no new production
  behavior, only the removal of the leftover mutation.

## Issues Found

- `ddev exec composer phpstan` fails on a **pre-existing** core test error
  (`tests/Core/PluginEnableAjaxSafetyTest.php:308`) that is unmodified and outside
  the plugin boundary. It is not caused by this change and must not be "fixed" by
  weakening types or deleting tests; recorded as a known baseline failure.
- New user-facing `new_error_msg()` strings were introduced in Phase 4 / D6,
  following the plugin's existing Spanish convention.

## Remaining Tasks

None. Phases 1–7 are complete.

## Workload / PR Boundary

- Mode: **chained/stacked PR slice** (`delivery_strategy: auto-chain`,
  `chain_strategy: stacked-to-main`).
- Slice boundaries for the commit/PR step:
  - **Slice 1 (Unit 1 / PR 1, base = `main`)** — helper + resolver + their tests.
    Authored count recorded earlier: **~574** lines.
  - **Slice 2 (Unit 2 / PR 2, base = PR 1 branch)** — files:
    `Services/CaracteristicaValorBatchReader.php` + `tests/Controller/VentasArticulosListCaracteristicasTest.php`.
    Authored count recorded earlier: **~276** lines. Within the 400-line budget.
  - **Slice 3 (Unit 3 / PR 3, base = PR 2 branch)** — files:
    `Controller/VentasCaracteristicas.php` + `tests/Controller/VentasCaracteristicasControllerTest.php`.
    Authored count recorded earlier: **~526** lines. **Exceeds the 400-line budget**
    (strict-TDD fixture doubles + seven refusal tests); `size:exception`
    recommended if reviewed as a single PR.
  - **Slice 4 (Unit 4 / PR 4, base = PR 3 branch)** — test-only additions:
    `tests/CaracteristicaBoundariesTest.php` (+53), `tests/Services/ArticuloExcelCaracteristicaTest.php`
    (+174) and the three-path section of `tests/CaracteristicaOwnershipTest.php`
    (~180). Authored count: **~407 lines**, slightly over the 400-line budget,
    driven by the strict-TDD fixtures/helpers. Production behavior is delivered
    entirely by PR 1–3; this slice is the inherited-surface/boundary/cross-path
    verification. `size:exception` recommended if reviewed as a single PR; it was
    not shrunk by deleting tests or comments.
  - **Slice 5 (Unit 5 / PR 5, base = PR 4 branch)** — Phase 7 W1 remediation:
    `Services/CaracteristicaBackfillMigration.php` (+46/−8) and
    `tests/CaracteristicaBackfillTest.php` (+134/−4). Authored count:
    **192 lines**, within the 400-line budget. One additive inert-skip guard on
    the boot backfill, no mapping-semantics change for active definitions.
- No commit/push/PR was performed — delivery is decided by the orchestrator.

## Status

**31/31 tasks complete** (Phases 1–6 plus Phase 7 W1 remediation). Ready for archive.
