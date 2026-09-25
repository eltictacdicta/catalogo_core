```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:70aa44ad722d090eff1ac0c8c4f019a786555c1e0ebb9b62561ef7fe1529d239
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 12/12
scenarios: 35/35
test_command: 'ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml && ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml && ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml && ddev exec php vendor/bin/phpunit'
test_exit_code: 0
test_output_hash: sha256:0bbc82e04608724f93518fe5ce885f070b363126a09caded729fd8b7024998ef
build_command: 'ddev exec php -l on the 15 changed production PHP files (catalogo_core + tpvmod + tarifario)'
build_exit_code: 0
build_output_hash: sha256:4a0a876a3760fbd7e922038dc6a8e82acb85340423b7330cf4b593ca16519005
```

# Verification Report — opcional-en-varios-grupos

- **Change**: `opcional-en-varios-grupos`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Artifact store**: `openspec`
- **Mode**: Strict TDD (per `plugins/catalogo_core/openspec/config.yaml` → `strict_tdd: true`)
- **Verifier**: `sdd-verify` (independent re-execution; apply claims not trusted)
- **Verification date**: 2026-09-25
- **Delta spec retrieved**: `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/specs/opcionales-management/spec.md` — 12 `### Requirement:` headings, 35 `#### Scenario:` headings
- **Canonical spec**: `plugins/catalogo_core/openspec/specs/opcionales-management/spec.md` — 14 requirements
- **Core `openspec/changes/opcional-en-varios-grupos/` entry**: NONE (confirmed; plugin-local rule respected)

## Completeness

| Metric | Value |
|--------|-------|
| Requirements (delta) | 12 |
| Scenarios (delta) | 35 |
| Scenarios compliant | 35 (33 test-covered; 2 verified manually/source-level, disclosed below) |
| Work units | WU-0…WU-6 |
| Implementation tasks remaining | None unchecked in WU-0…WU-5 |
| WU-6 tasks | T1–T5 satisfied and ticked; T6/T7 left unticked (see Issues) |

## Build & Tests Execution

**Build (syntax)**: ✅ Passed
```text
ddev exec php -l <15 changed production PHP files>
  → 15 × "No syntax errors detected"   EXIT=0
```
Output hash: `sha256:4a0a876a3760fbd7e922038dc6a8e82acb85340423b7330cf4b593ca16519005`.
(PHP has no compile step; the changed-file syntax lint is the build check used in the
envelope. The configured static analysis is reported separately and is not used as the
envelope build command, because it fails on a pre-existing unrelated core test.)

**Static analysis (configured linter)**: ⚠️ Pre-existing failure, unrelated
```text
ddev exec composer phpstan  → exit 1
[ERROR] Found 1 error
tests/Core/PluginEnableAjaxSafetyTest.php:308 — applyPluginSchemaUpdates() return type
  (Array does not have offset 'changes')
```
Attribution: the only reported error is a core test file last modified by the core
release commit `14a4c7b7` (2026-09-20), before this change; all three plugin working
trees are clean and this change touches no core file. `phpstan.neon` analyses only
`src` and `tests`, so it does not analyse `plugins/` at all — no finding is
attributable to this change. Output hash: `sha256:c1b88994bcdd32bcca76e0a7a1ba77e169326207b0e0a496764deb1e46d5eda8`.

**Tests**: ✅ all four suites pass (exit 0)
```text
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
  OK, but there were issues!  Tests: 983, Assertions: 4296, Warnings: 2, Skipped: 1   EXIT=0
ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml
  OK (181 tests, 1337 assertions)                                                    EXIT=0
ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
  OK, but some tests were skipped!  Tests: 268, Assertions: 1116, Skipped: 2          EXIT=0
ddev exec php vendor/bin/phpunit   (root regression)
  OK, but some tests were skipped!  Tests: 2788, Assertions: 10493, Skipped: 24       EXIT=0
```
Combined evidence bundle hash: `sha256:0bbc82e04608724f93518fe5ce885f070b363126a09caded729fd8b7024998ef`
(`/tmp/opencode/verify_tests.txt`).

**Coverage**: ➖ Not available — no coverage driver configured in any `phpunit.xml`.

## Spec Compliance Matrix

| Requirement | Scenario | Test | Result |
|---|---|---|---|
| OUM-02 | Filters are preserved across requests | `CatalogoOpcionalesUnifiedControllerTest.php::test_filters_query_primary_and_search_alias_are_carried_in_url` | ✅ COMPLIANT |
| OUM-02 | Pagination uses the filtered count | `CatalogoOpcionalesUnifiedControllerTest.php::test_pagination_uses_count_filtered_true_total_and_preserves_filters` | ✅ COMPLIANT |
| OUM-02 | Group filter matches every membership | `CatalogoOpcionalMembershipTest.php::test_where_id_grupo_uses_exists_and_not_exists_on_the_bridge` + live-DB transactional smoke (opcional 1 matched under group 1 **and** group 2) | ✅ COMPLIANT |
| OUM-02 | "Sin grupo" sentinel returns only loose opcionales | `CatalogoOpcionalMembershipTest.php::test_where_id_grupo_uses_exists_and_not_exists_on_the_bridge` + `::test_all_sin_grupo_uses_the_bridge_anti_join` + live-DB smoke | ✅ COMPLIANT |
| OUM-05 | Creation persists validated per-tarifa values | `CatalogoOpcionalesUnifiedControllerTest.php::test_new_opcional_normalizes_prices_and_persists` | ✅ COMPLIANT |
| OUM-05 | Creation persists multiple memberships together | `CatalogoOpcionalesUnifiedControllerTest.php::test_new_opcional_persists_percentage_and_multiple_groups` + `VentasOpcionalesControllerTest.php::testCreateModalPerTarifaValuesPersistWhenTarifasAreLoaded` | ✅ COMPLIANT |
| OUM-05 | Creation without memberships yields a loose opcional | MANUAL — no dedicated assertion: `test_new_opcional_normalizes_prices_and_persists` posts no `grupos` (exercising the empty-set path) but does not assert the result; loose semantics asserted by `CatalogoOpcionalMembershipTest.php::test_all_sin_grupo_uses_the_bridge_anti_join`. Source path: `new_opcional()` → `set_grupos([])` → zero bridge rows. | ✅ COMPLIANT (manual/source) |
| OUM-05 | Malformed value or missing CSRF is rejected | `CatalogoOpcionalesUnifiedControllerTest.php::test_new_opcional_rejects_duplicate_or_bad_price`, `::test_new_opcional_rejects_a_non_numeric_percentage`; CSRF rejection: `::test_missing_or_invalid_csrf_blocks_toggle_and_persists_nothing` | ✅ COMPLIANT |
| OPG-02 | Group column renders all labels from one batched map | `CatalogoOpcionalesUnifiedControllerTest.php::test_grupo_column_map_loads_all_labels_from_one_batched_map` | ✅ COMPLIANT |
| OPG-02 | Multi-group labels do not depend on `id_grupo` | `CatalogoOpcionalesHtmxContractTest.php::test_list_view_renders_group_column_and_percentage_modal` (asserts `fsc.nombres_grupo_opcional(`, forbids `nombre_grupo_opcional(`, `etiqueta_grupo(` and `.id_grupo`) | ✅ COMPLIANT |
| OPG-03 | An opcional holds several memberships at once | `CatalogoOpcionalGrupoRelModelTest.php::test_add_inserts_a_single_bridge_row`, `::test_add_is_idempotent_for_the_same_pair` + live-DB smoke (rows `1:1`,`1:2` coexist; UNIQUE rejects duplicate) | ✅ COMPLIANT |
| OPG-03 | Bridge schema matches the plugin bridge convention | `CatalogoOpcionalGrupoRelModelTest.php::test_bridge_ddl_declares_pk_and_unique_without_foreign_keys`, `::test_bridge_xml_matches_the_plugin_bridge_convention` + live `SHOW CREATE TABLE` (PK(id), UNIQUE(id_opcional,id_grupo), no FK) | ✅ COMPLIANT |
| OPG-03 | Backfill from `id_grupo` is idempotent | `Services/CatalogLegacyTableMigrationTest.php::test_backfills_the_bridge_from_positive_id_grupo_idempotently` + `Integration/CatalogoOpcionalGroupMigrationTest.php::test_backfills_are_idempotent_in_both_dialects` | ✅ COMPLIANT |
| OPG-03 | `id_grupo` is frozen but still present | `CatalogoOpcionalMembershipTest.php::test_save_writes_no_legacy_group_column`, `::test_model_source_freezes_the_legacy_group_column` + grep audit + live column present | ✅ COMPLIANT |
| OPG-04 | No bridge row means loose | `CatalogoOpcionalMembershipTest.php::test_all_sin_grupo_uses_the_bridge_anti_join` (opcional 8, no bridge, returned) | ✅ COMPLIANT |
| OPG-04 | One membership is enough to stop being loose | `CatalogoOpcionalMembershipTest.php::test_all_sin_grupo_uses_the_bridge_anti_join` (opcional 7 with `[[7,3]]` excluded) + `::test_is_grouped_reflects_bridge_membership` | ✅ COMPLIANT |
| OPG-04 | Inactive group still owns membership | No unit test. Live-DB transactional smoke: opcional 5 bridged to a group with `activo=0` still yields `is_grouped` EXISTS = 1 and is excluded from the loose anti-join | ✅ COMPLIANT (manual DB evidence) |
| OPG-05 | Editing replaces the membership set | `CatalogoOpcionalMembershipTest.php::test_set_grupos_diffs_the_current_set` | ✅ COMPLIANT |
| OPG-05 | Group deletion removes only its own memberships | `CatalogoOpcionalMembershipTest.php::test_group_delete_cascades_its_memberships_only` | ✅ COMPLIANT |
| OPG-05 | Opcional deletion leaves no orphan membership | `CatalogoOpcionalMembershipTest.php::test_delete_cascades_the_bridge_rows` | ✅ COMPLIANT |
| OPG-05 | Removing the last membership does not restore deleted direct relations | `CatalogoOpcionalMembershipTest.php::test_remove_from_grupo_int_deletes_only_that_row_and_never_restores_direct_relations` | ✅ COMPLIANT |
| OPG-06 | Multi-group opcional appears under each group | `CatalogoOpcionalGrupoTest.php::test_multi_group_opcional_appears_under_each_group` | ✅ COMPLIANT |
| OPG-06 | Activos read is bridge-backed | `CatalogoOpcionalGrupoTest.php::test_activos_read_is_bridge_backed` | ✅ COMPLIANT |
| OPG-07 | Member of another group is available | `CatalogoOpcionalGrupoTest.php::test_available_list_offers_member_of_another_group` | ✅ COMPLIANT |
| OPG-07 | Current member is excluded | `CatalogoOpcionalGrupoTest.php::test_available_list_excludes_current_member` | ✅ COMPLIANT |
| OPG-08 | First membership removes direct relations | `CatalogoOpcionalMembershipTest.php::test_add_to_grupo_fires_direct_relation_cleanup_only_on_first_membership`, `::test_set_grupos_fires_the_first_membership_cleanup_once` | ✅ COMPLIANT |
| OPG-08 | Grouped opcional rejects a direct relation | MANUAL — no test references `validate_opcional_for_articulo()` anywhere; source-verified at `model/core/catalogo_articulo_opcional.php:64` (`if ($item->is_grouped()) return '…'`), and `is_grouped()` itself is test-covered by `test_is_grouped_reflects_bridge_membership` | ✅ COMPLIANT (manual/source) |
| OPG-08 | Direct reads exclude grouped opcionales | `Integration/OpcionalGrupoTarifarioCompatTest.php::test_grouped_opcional_is_excluded_identically_with_one_or_two_groups`, `::test_direct_read_predicate_is_a_bridge_anti_join` | ✅ COMPLIANT |
| OPG-09 | Resolved-for-sale read yields one occurrence | `CatalogoOpcionalGrupoTest.php::test_resolved_for_sale_read_yields_one_occurrence` (spec-annotated) | ✅ COMPLIANT |
| OPG-09 | Group-scoped presentation still lists each group | `CatalogoOpcionalGrupoTest.php::test_group_scoped_presentation_lists_each_group` (spec-annotated) | ✅ COMPLIANT |
| OPG-09 | Second selection of the same id is deduped | `TpvmodOpcionalDedupeTest.php::test_pick_opcional_dedupes_before_the_exclusive_replacement_branch` (spec-annotated) | ✅ COMPLIANT |
| OPG-10 | Parents are the union over all groups | `OpcionalVisibilityDerivationTest.php::test_multi_group_opcional_unions_all_group_article_parents` | ✅ COMPLIANT |
| OPG-10 | Query count stays constant | `OpcionalVisibilityDerivationTest.php::test_query_count_is_bounded_and_independent_of_the_page_size` + exact-4 assertion in `test_multi_group_opcional_unions_all_group_article_parents` | ✅ COMPLIANT |
| OPG-11 | Direct read is unchanged with one or two groups | `Integration/OpcionalGrupoTarifarioCompatTest.php::test_grouped_opcional_is_excluded_identically_with_one_or_two_groups`, `::test_direct_read_is_control_non_empty_without_memberships` | ✅ COMPLIANT |
| OPG-11 | Bulk delete leaves no orphan memberships | `Integration/OpcionalGrupoTarifarioCompatTest.php::test_both_tarifario_bulk_delete_paths_clean_the_bridge` (per-method source contract) + code inspection; the destructive live run was intentionally NOT executed | ✅ COMPLIANT (source contract + manual reasoning) |

**Compliance summary**: 35/35 scenarios compliant — 33 by passing automated tests, 2
(OUM-05 s3, OPG-08 s2) by manual/source-level verification allowed by the plugin's
`phase_rules.verify` ("grep audit + phpstan + manual smoke checklist"). The two
manual-only scenarios are separately flagged as test-coverage WARNINGS below; no
scenario is UNTESTED in the sense of lacking any verification.

## Correctness (Static Evidence)

| Requirement | Status | Notes |
|---|---|---|
| OUM-02 | ✅ Implemented | `where_id_grupo()` returns `''` / `NOT EXISTS(…)` / `EXISTS(…)` against the bridge; identical predicate flows to `search()` and `count_filtered()`. |
| OUM-05 | ✅ Implemented | `VentasOpcionalesListTrait::new_opcional()` reads `grupos[]` and calls `set_grupos()` inside the existing transaction. |
| OPG-02 | ✅ Implemented | `nombres_grupo_opcional()` builds labels from one `map_for_opcionales()` call; views render badges + `-` for loose. |
| OPG-03 | ✅ Implemented | Bridge table + model + `migrateOpcionalGroupRelations()` + `Init::ensureOpcionalGrupoRelTable()`; live table present with PK + UNIQUE + no FK. |
| OPG-04 | ✅ Implemented | Anti-join/EXISTS bodies contain no `activo` predicate; verified live with an inactive group. |
| OPG-05 | ✅ Implemented | `set_grupos()`/`add_to_grupo()`/`remove_from_grupo(int)`; cascades in `catalogo_opcional::delete()` and `catalogo_opcional_grupo::delete()`. |
| OPG-06 | ✅ Implemented | `get_opcionales()`, `get_opcionales_activos()`, `count_opcionales()` join/count the bridge. |
| OPG-07 | ✅ Implemented | `all_not_in_grupo($idGrupo)` used by the group editor; `all_sin_grupo()` no longer used there. |
| OPG-08 | ✅ Implemented | `validate_opcional_for_articulo()` migrated to `is_grouped()`; direct reads use the anti-join. |
| OPG-09 | ✅ Implemented | `get_opcionales_from_articulo()` dedupes by id; `tpvmod_pick_opcional()` dedupes before the exclusive replacement. |
| OPG-10 | ✅ Implemented | Query 1 reads `catalogo_opcional_grupo_rel`; union loop merges parents of every membership; query count exactly 4 with memberships, 3 without. |
| OPG-11 | ✅ Implemented | Both `limpiar_opcionales()` (line 2218–2222) and `limpiar_todo()` (`$deleteTable('catalogo_opcional_grupo_rel', …)`, line 1994) delete bridge rows; direct-read predicate is a bridge anti-join. |

## Contract Checks

| Contract | Result |
|---|---|
| (a) No `ALTER TABLE … DROP COLUMN id_grupo` | ✅ PASS — the only `DROP COLUMN` in the plugin is `CaracteristicaColumnDropMigration.php:408` (a pre-existing, unrelated caracteristicas drop). |
| (b) OPG-11 covers BOTH tarifario bulk-delete paths | ✅ PASS — the delta spec explicitly names `limpiar_opcionales()` and `limpiar_todo()`; both exist in `ArticuloListActionHandler.php`. |
| (c) `CaracteristicaResolver::opcional_parents()` constant query count | ✅ PASS — exactly **4** batched queries with ≥1 membership, **3** without; test `OpcionalVisibilityDerivationTest::test_query_count_is_bounded_and_independent_of_the_page_size` (`assertSame` single vs page, `assertLessThanOrEqual(4)`) and the exact-4 assertion in `test_multi_group_opcional_unions_all_group_article_parents`. |
| (d) "grouped ⇒ no direct article relations" fires once on the first membership | ✅ PASS — `test_add_to_grupo_fires_direct_relation_cleanup_only_on_first_membership` and `test_set_grupos_fires_the_first_membership_cleanup_once` assert the cleanup count stays at 1 across a second add. |

## Residual `id_grupo` Inventory (production code, tests/openspec excluded)

Scope: `plugins/catalogo_core` + `plugins/tpvmod` + `plugins/tarifario` (`.php`, `.twig`, `.js`, `.xml`).
`plugins/tpvmod` and `plugins/tarifario` production code: **0** matches.
No missed cutover found. Every residual site is legitimate:

| # | Site(s) | Classification | Rationale |
|---|---|---|---|
| 1 | `model/core/catalogo_opcional_grupo_rel.php` (whole model) | Bridge column | `id_grupo` is a column of the new bridge `catalogo_opcional_grupo_rel`. |
| 2 | `model/core/catalogo_opcional_grupo.php:197,215,230,253` | Bridge read/count | `r.id_grupo = …` joins/filters and `WHERE id_grupo = …` counts operate on `catalogo_opcional_grupo_rel`. |
| 3 | `model/core/catalogo_articulo_opcional_grupo.php` (whole model) + `model/table/catalogo_articulo_opcional_grupo.xml` | article↔group bridge | Separate, pre-existing bridge keyed by `(referencia, id_grupo)`; explicitly out of scope. |
| 4 | `model/core/catalogo_opcional.php:74,102,435,713–726` | Bridge join / filter / URL | Joins and EXISTS/NOT EXISTS on the bridge; `?id_grupo=` URL builder. |
| 5 | `model/table/catalogo_opcionales.xml:50` | Frozen physical column | Legacy column intentionally kept for rollback (no DROP). |
| 6 | `model/table/catalogo_opcional_grupo_rel.xml:19,29` | Bridge column | Bridge DDL. |
| 7 | `Services/CatalogLegacyTableMigration.php` (`syncOptionalGroupColumns`, `migrateOpcionalGroupRelations` pre-check/backfill, `migrateGroupedOptionalAssignments`) | Migration | Allowed residual; the **only** reader of `catalogo_opcionales.id_grupo` (`SELECT o.id, o.id_grupo … WHERE o.id_grupo > 0`). |
| 8 | `Services/CaracteristicaResolver.php:279–315` | Bridge read | Query 1 reads `catalogo_opcional_grupo_rel.id_grupo`; query 3 reads the article↔group bridge. |
| 9 | `extras/VentasOpcionalesListTrait.php` (`b_id_grupo`, `:71,286,293,310,318,714,999`) | Search param | List filter key, not a membership source. |
| 10 | `model/tarif_opcional.php:231–329` | Search param passthrough | `$id_grupo` argument forwarded to the inherited bridge-backed `where_id_grupo()`. |
| 11 | `Controller/VentasArticulo.php:1023,1060,1122` | article↔group param | `id_grupo` request param for article↔group add/remove/toggle. |
| 12 | `Controller/VentasOpcional.php:187,202` | URL param | `?id_grupo=<n>` preset seeds the checked set; no model write. |
| 13 | `View/partials/articulos/tab_opcionales.html.twig` | article↔group UI | Group selector for the article↔group bridge. |
| 14 | `View/ventas_opcionales.html.twig`, `View/ventas_opcional_grupo.html.twig` | Search param / URL | `b_id_grupo` filter markup and `?id_grupo=` link. |

**Frozen-column write audit**: no production SQL writes `catalogo_opcionales.id_grupo`
(no `UPDATE`/`INSERT` targets it); the migration only reads it for backfill.
`catalogo_opcionales.id_grupo` live values (`1`,`1`,`NULL`) are legacy data, as expected.
No residual site reads or writes the frozen column as a membership source.

## TDD Compliance (Strict TDD active)

| Check | Result | Details |
|-------|--------|---------|
| TDD Evidence reported | ✅ | "TDD Cycle Evidence" tables present for Slice A (WU-0/WU-1), B1 (WU-2), B2 (WU-3), B3 (WU-4 + deferred WU-2), and C (WU-5) in `apply-progress.md`. |
| All tasks have tests | ✅ | Every WU test file listed exists on disk and was re-executed in this verification. |
| RED confirmed (tests exist) | ✅ | 5 new catalogo_core test files + 1 new tpvmod test file exist; RED evidence recorded (e.g. `CatalogoOpcionalMembership` 11 errors + 9 failures; tpvmod 9 failures). |
| GREEN confirmed (tests pass) | ✅ | All four suites exit 0 on independent re-execution (983, 181, 268, 2788 tests). |
| Triangulation adequate | ✅ | Bridge model 11 cases; membership 20; migration 5 + 3; compat 4; dedupe 4 — distinct expected values, not single-case. |
| Safety Net for modified files | ✅ | Every updated suite reported an existing green baseline before edits. |

**TDD Compliance**: 6/6 checks passed.

### Test Layer Distribution

| Layer | Tests | Files | Tools |
|-------|-------|-------|-------|
| Unit (fake `fs_db2` / anonymous subclasses) | ~47 change-related | 6 | PHPUnit 11 |
| Integration (source-contract) | ~13 change-related | 4 | PHPUnit 11 |
| E2E | 0 | 0 | not installed |

### Changed File Coverage

Coverage analysis skipped — no coverage driver configured in the plugin `phpunit.xml`.

### Assertion Quality

| File | Line | Assertion | Issue | Severity |
|------|------|-----------|-------|----------|
| — | — | — | No tautologies, ghost loops, or production-code-free assertions found in the change test files | — |

**Assertion quality**: ✅ All spot-checked assertions verify real behavior (SQL bodies,
stored pairs, saved flags, exact query counts, returned id sets). Four `assertSame([])`
checks on empty payloads all have companion non-empty assertions in the same file.

### Quality Metrics
**Linter**: ❌ 1 error — `tests/Core/PluginEnableAjaxSafetyTest.php:308`, pre-existing and unrelated (see Issues)
**Type Checker**: ➖ same command as the linter (`phpstan`), same pre-existing result; the config does not analyse `plugins/`

## Issues Found

**CRITICAL**: None.

**WARNING**:
1. **OPG-08 scenario 2 relies on manual/source verification.** `catalogo_articulo_opcional::validate_opcional_for_articulo()` was migrated to `$item->is_grouped()` (`model/core/catalogo_articulo_opcional.php:64`) but no test in any suite references it. Verified by source inspection; a one-line unit test is recommended.
2. **OUM-05 scenario 3 relies on manual/source verification.** `test_new_opcional_normalizes_prices_and_persists` posts no `grupos` yet never asserts the resulting empty membership set; the empty-set → loose behavior is inferred from `set_grupos([])` and covered indirectly by the loose anti-join test.
3. **Configured linter does not lint the plugin and has a pre-existing core error.** `phpstan.neon` `paths: [src, tests]` excludes `plugins/`; `ddev exec composer phpstan` exits 1 solely on `tests/Core/PluginEnableAjaxSafetyTest.php:308` (last touched by core release commit `14a4c7b7`, 2026-09-20). The change's plugin code receives no static analysis from the configured linter.
4. **Recorded-count drift in `apply-progress.md`** (reported, not fixed):
   - tpvmod final recorded as `180 tests / 1309 assertions`; observed `181 / 1337` (+1 test, +28 assertions).
   - Slice A reported the root regression at `2740 / 9900` while Slice B1 named the baseline as `2742 / 9948` — the two baselines are mutually inconsistent.
   - All catalogo_core / tarifario / root final totals match the observed run exactly (`983/4296`, `268/1116`, `2788/10493`).
5. **Spec `- Test:` annotation drift (OPG-02 scenario 1).** The delta spec points at `plugins/catalogo_core/tests/VentasOpcionalesControllerTest.php`, but the covering batched-map test is in `CatalogoOpcionalesUnifiedControllerTest.php` (view contract in `CatalogoOpcionalesHtmxContractTest.php`). Coverage exists; the pointer is stale.

**SUGGESTION**:
1. `limpiar_todo()` still leaves `catalogo_articulo_opcional_grupo` rows dangling on its deleted-article side — pre-existing, documented and deliberately out of scope.
2. Consider a plugin-scoped PHPStan config (`plugins/catalogo_core/phpstan.neon`) since the root config excludes `plugins/`.
3. Configure a coverage driver so changed-file coverage can be measured in future verifications.
4. WU-6.T6 and WU-6.T7 are intentionally left unticked in `tasks.md`: T6's command exits non-zero (pre-existing/unrelated) and the configured linter does not cover the plugin; T7's UI-level manual checklist (browser checkbox save, TPV charge-once) was not executed — the equivalent DB-level behaviour was verified transactionally instead.

## Behavioural Smoke (real DB, `ddev exec mysql`, non-persistent)

All writes were performed inside an explicit transaction and `ROLLBACK`-ed; verified
post-rollback state is unchanged (`catalogo_opcional_grupos` count = 1, bridge rows
`1:1`,`2:1`).

| Check | Result |
|---|---|
| Bridge table exists with PK + UNIQUE, no FK (MySQL) | ✅ `PRIMARY KEY (id)`, `UNIQUE KEY catalogo_opcional_grupo_rel_unique (id_opcional,id_grupo)`, ENGINE=InnoDB |
| Opcional assigned to TWO groups, both memberships persist | ✅ added `(1, groupB)` → rows `1:1, 1:2` coexist |
| UNIQUE rejects duplicate `(id_opcional,id_grupo)` | ✅ duplicate insert count = 1 |
| `b_id_grupo` filter returns the opcional under EACH group | ✅ group 1 → ids {1,2}; group 2 → id {1} |
| "Sin grupo" anti-join returns only loose | ✅ only id 5 (Toallero, NULL legacy `id_grupo`, no bridge row) |
| Inactive group still owns membership | ✅ group `activo=0` bridged to opcional 5 → is_grouped EXISTS = 1, excluded from loose anti-join |
| `catalogo_opcionales.id_grupo` not written by app code | ✅ only the migration reads it; live column retains legacy values (expected) |
| tarifario bulk-delete leaves no orphan bridge rows | ⚠️ MANUAL (not run) — destructive bulk delete against live data intentionally not executed; proven by `OpcionalGrupoTarifarioCompatTest::test_both_tarifario_bulk_delete_paths_clean_the_bridge` (per-method source contract) + code inspection of `ArticuloListActionHandler.php` |

## Coherence (Design)

| Decision | Followed? | Notes |
|---|---|---|
| AD-1 bridge schema/DDL | ✅ Yes | Live MySQL + XML + model match; no FK/CASCADE. |
| AD-2 migration + backfill | ✅ Yes | Create-if-missing, pair-keyed pre-check, dialect idempotent inserts. |
| AD-3 `migrateGroupedOptionalAssignments()` reads bridge | ✅ Yes | `Integration/CatalogoOpcionalGroupMigrationTest::test_grouped_assignments_join_the_bridge`. |
| AD-4 membership set API | ✅ Yes | Single-valued API and shims removed; `remove_from_grupo(int)`. |
| AD-5 `save()` freeze + side effect on first membership | ✅ Yes | Freeze asserted; cleanup asserted once. |
| AD-6 `$id_grupo` PHP property removed | ✅ Yes | `test_legacy_single_valued_api_is_removed`, `test_model_source_freezes_the_legacy_group_column`. |
| AD-7 cascade points (incl. both tarifario paths) | ✅ Yes | Opcional/group cascades tested; both tarifario paths present. |
| AD-8 dependent readers bridge-backed | ✅ Yes | Group model, articulo_opcional, familia, VentasArticulo. |
| AD-9 `b_id_grupo` EXISTS/NOT EXISTS | ✅ Yes | Sentinel + injection test. |
| AD-10 resolver constant queries (4/3) | ✅ Yes | Exact-4 assertion + bounded test. |
| AD-11 checkbox list UI | ✅ Yes | `name="grupos[]"`; no `sid_grupo`. |
| AD-12 TPV dedupe at add | ✅ Yes | Dedupe precedes exclusive replacement (source contract). |
| AD-13 tpvmod grouped detection | ✅ Yes | `grouped` flag; no removed property read. |
| AD-14 tarifario guard | ✅ Yes | Signature/value set preserved. |
| AD-15 bridge provisioning at boot | ✅ Yes | `ensureCatalogTables()` + `ensureOpcionalGrupoRelTable()`; `InitOpcionalesTablesTest` contracts. |
| AD-16 test strategy | ⚠️ Mostly | All planned files exist; the two manual-only scenarios above are not explicitly pinned. |

Deviations recorded in `apply-progress.md` (omitted post-create model touch, `tpvmod_opcional_is_grouped()` helper, extracted `loadGruposAsignados()`, source-contract-only group editor, test-only tarifario require) are all documented, non-behavioural and acceptable.

## Final-State Facts After Apply

- **Working trees**: clean in root, `plugins/catalogo_core`, `plugins/tpvmod`, `plugins/tarifario` (`git status --short` empty).
- **Not pushed; no PR opened.**
- **Commits — `plugins/catalogo_core`** (HEAD `6732d16f`):
  1. `611bf0b0` `feat(catalogo_core): add opcional-group membership bridge and backfill`
  2. `db28c621` `test(catalogo_core): cover the opcional-group bridge model and backfill`
  3. `043e8b5e` `feat(catalogo_core): add bridge-backed opcional membership API and cascades`
  4. `7cf01de0` `docs(catalogo_core): record the WU-2 B1 slice and reconcile the bridge DDL wording`
  5. `1312ecc0` `refactor(catalogo_core): union D12 opcional parents across every group`
  6. `a7dee3fb` `refactor(catalogo_core): resolve opcional group reads through the bridge`
  7. `b40e414e` `docs(catalogo_core): record the WU-3 dependent-reader slice`
  8. `80d7e874` `feat(catalogo_core): switch opcional group assignment to a checkbox list`
  9. `3a0f3018` `refactor(catalogo_core): freeze the opcional group column and drop the legacy shims`
  10. `478be229` `test(catalogo_core): lock the group-editor membership call sites`
  11. `51e6a9ea` `docs(catalogo_core): record the WU-4 membership cutover slice`
  12. `6732d16f` `test(catalogo_core): lock the tarifario opcional-bridge compatibility`
- **Commits — `plugins/tpvmod`** (HEAD `134cbcc`):
  1. `134cbcc` `fix(tpvmod): dedupe an opcional selected across several groups`
- **Commits — `plugins/tarifario`** (HEAD `310015e`):
  1. `f9cac3b` `fix(tarifario): clean opcional bridge rows on both bulk-delete paths`
  2. `310015e` `test(tarifario): load the opcional bridge model in the configurator contract`
- **Composer/vendor**: no dependency added by this change → no `vendor/` delta required.
- **Schema**: `catalogo_opcional_grupo_rel` exists in MySQL with PK + UNIQUE + no FK; `catalogo_opcionales.id_grupo` physical column preserved (frozen).

## Verdict

**PASS WITH WARNINGS**

All four test suites pass (exit 0), the frozen-column cutover is complete in production
code (no `id_grupo` reads/writes outside the migration, no missed cutover), the bridge is
the sole membership source, and the two tarifario bulk-delete paths clean the bridge.
All 35 delta scenarios are verified (33 by automated tests, 2 by manual/source-level
verification explicitly allowed by the plugin config), with the 2 manual-only scenarios
and the configured-linter gap flagged as WARNINGS. None of these is CRITICAL or
archive-blocking.
