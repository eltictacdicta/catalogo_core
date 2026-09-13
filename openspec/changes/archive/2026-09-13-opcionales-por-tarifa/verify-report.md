```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:145c2e4414c65d4d27e37f850deea8041e42b63c4d65c4ac57de654915704c39
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 6/6
scenarios: 14/14
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:b458eef2b9ef19c1806edc23f708b4b9e9f1bcdf8f8765bc9efafe2345200d61
build_command: ddev exec composer phpstan
build_exit_code: 0
build_output_hash: sha256:0b9439133e886daed09f416d29a5f87f002a1780c9b614256acc883c748ff6c9
```

# Verification Report — Re-verification (residuals)

**Change**: `opcionales-por-tarifa` (plugin-local SDD, `plugins/catalogo_core/openspec/changes/opcionales-por-tarifa/`)
**Delta spec**: `opcionales-tarifa-management` — 6 requirements / 14 scenarios (native `### Requirement:` / `#### Scenario:` count)
**Mode**: Strict TDD (`strict_tdd: true`, runner `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`)
**Verification type**: independent third re-verification, after the residual-remediation work unit (items A–G). Every artifact was re-read, every command re-executed, all read-only guards re-run, the non-destructive audit re-run, and the fresh-install scratch-DB smoke re-executed live. No apply claim was trusted.

**Implementation span**: two standalone git repos. `plugins/catalogo_core` (Units 1–4 + remediation rounds 1, 2 and residual) and `plugins/tarifario` (Unit 5, hybrid + residual item D). All changes are uncommitted/staged by design (delivery is human-owned). The residual work unit touched `tarifario` for item D only.

**Why re-verified**: the residual work unit changed `controller/tarif_opcional_edit.php`, `extras/TarifarioOpcionalStateTrait.php`, `model/tarif_opcional.php`, `model/tarif_tarifa_opcional.php`, `plugins/tarifario/controller/tarif_tarifas.php` and three test files. The prior report's `evidence_revision` and test hashes described pre-residual bytes. All hashes below are fresh.

**Evidence digest definition**: `evidence_revision` is the sha256 of the sorted `"<sha256>  <plugin>/<path>"` listing of the 21 implementation/test files changed by this change (openspec artifacts, the gitignored `tmp/` scratch harness and the gitignored `vendor/` excluded), recorded at `/tmp/opencode/verify3/evidence_revision_r3.txt`. `test_output_hash` / `build_output_hash` are the sha256 of the full captured stdout with the single volatile `Time:` line removed; raw captures live at `/tmp/opencode/verify3/catalogo_core.txt`, `/tmp/opencode/verify3/tarifario.txt`, `/tmp/opencode/verify3/phpstan.txt`. The `build_output_hash` is identical to the round-2 value, confirming the same digest definition.

## Residual Items A–G (independent confirmation)

| # | Residual claim | Independent verdict | Evidence (source + re-run test) |
|---|----------------|--------------------|----------------------------------|
| A | `controller/tarif_opcional_edit.php::guardar_precios_tarifas()` bulk path validates prices with `parse_price_input()`, no `floatval` | ✅ CONFIRMED FIXED | `controller/tarif_opcional_edit.php:298-316`: each submitted price is pre-validated into `$precios_validados` via `$this->parse_price_input($precio_str)`, `null` → targeted `new_error_msg(...)` + `return`. `grep -n floatval controller/tarif_opcional_edit.php` → no matches. Test: `TarifOpcionalesControllerMasterStateTest::test_edit_bulk_matrix_parses_prices_with_the_validated_parser` asserts `parse_price_input(`, `assertStringNotContainsString('floatval(')` and `new_error_msg(`; class `OK (17 tests, 88 assertions)`. |
| B | `model/tarif_tarifa_opcional.php::save()` full-row UPDATE branch documented as an unreachable defensive fallback (doc only) | ✅ CONFIRMED (documentation only) | `model/tarif_tarifa_opcional.php:208-218` class docblock: production writes go through the whitelisted `update_single_field()`; the `save()` UPDATE branch has no production caller and is "kept only as a defensive fallback". Inline comment at `:230-233` repeats it. No behavior change; `TarifTarifaOpcionalTest` unchanged and green (`19/71`). |
| C | `extras/TarifarioOpcionalStateTrait.php::parse_price_input()` locale convention documented + test asserts single separator = decimal | ✅ CONFIRMED FIXED | Trait `:223-240` docblock states a single `.`/`,` is ALWAYS the decimal separator, never a thousands separator (`1.234`/`1,234` = 1.234; no grouping support). Regex `^[+-]?(?:\d+(?:[.,]\d+)?|[.,]\d+)$` at `:248`. Test: `TarifarioOpcionalStateTraitTest::test_parse_price_input_treats_a_single_separator_as_a_decimal` asserts `assertSame(1.234, ...'1.234')` and `...'1,234'`; class `OK (20 tests, 30 assertions)`. |
| D | `plugins/tarifario/controller/tarif_tarifas.php::copy_precios_opcionales()` copies `precio`, `porcentaje`, `en_catalogo` | ✅ CONFIRMED FIXED | `plugins/tarifario/controller/tarif_tarifas.php:274-277`: `INSERT INTO catalogo_opcional_precios (id_opcional, codlista, precio, porcentaje, en_catalogo) SELECT id_opcional, <destino>, precio, porcentaje, en_catalogo ...`. Test: `TarifTarifasHeredarOpcionalMasterTest::test_copy_precios_opcionales_preserves_the_per_lista_columns` asserts all three tokens; class `OK (5 tests, 33 assertions)`. |
| E | WARNING-1 fixed: `model/tarif_opcional.php::search()` orders the per-tarifa listing by master default `COALESCE(mto.orden, 999999)` with family-scoped override; `count_filtered()` matches | ✅ CONFIRMED FIXED | `model/tarif_opcional.php:262-273`: when `codtarifa != ''` it `LEFT JOIN tarif_tarifa_opcional mto` and sets `$order_expr = 'COALESCE(mto.orden, 999999)'`; when also family-scoped it `LEFT JOIN tarif_tarifa_opcional_familia fto` and upgrades to `'COALESCE(fto.orden, mto.orden, 999999)'`. The expression is selected as `orden_tarifa` (`:292-295`, required for `SELECT DISTINCT` + ORDER BY, MySQL 3065) and used in `ORDER BY orden_tarifa ASC, o.nombre ASC` (`:301-303`). `count_filtered()` (`:329-372`) applies the same join/where row filters for the same `(query, codfamilia, codtarifa, solo_activos)` inputs. Tests: `TarifTarifaOpcionalPrecedenceTest::test_per_tarifa_listing_orders_by_master_orden_default`, `::test_per_tarifa_listing_scoped_family_orden_overrides_the_master_default` drive the real `search()` and assert the generated SQL; class `OK (12 tests, 49 assertions)`. |
| E (guard) | Family/article scoped resolver semantics not broken | ✅ CONFIRMED | `tarif_tarifa_opcional_familia`, `tarif_tarifa_opcional_resolver` and `tarif_tarifa_articulo_opcional` are **not modified** (`git status` / diff empty). `TarifTarifaOpcionalPrecedenceTest::test_scoped_family_orden_overrides_the_master_default` (`:355-374`) still pins `tarif_tarifa_opcional_familia::get_opcionales_activos()` to `COALESCE(tof.orden, 999999) as orden_tarifa`. `TarifTarifaOpcionalFamiliaTest` green (`6/28`). |
| F | WARNING-3 closed: live fresh-install scratch-DB smoke; recorded evidence coherent; re-run if feasible | ✅ CONFIRMED FIXED (re-executed live) | Harness `tmp/smoke_opcionales_fresh_install.php` is coherent (empty scratch DB → `Init::ensureOpcionalesTarifaTables()` twice → PK/FK/sequence/idempotency assertions). Re-ran this pass against a dropped-and-recreated `smoke_cc_fresh` (utf8mb4_general_ci): `run1_master=present`, `run1_master_pk=composite`, `run1_master_fks=2`, both FK targets `present`, `run2_tables_identical=true`, `run2_ddl_identical=true`, `SMOKE_RESULT=PASS`, exit 0. The observed master DDL is byte-identical to the recorded evidence. |
| G | WARNING-2 accepted: physical boolean defaults `0` vs XML `TRUE`, pre-existing framework behavior, runtime unaffected, no code | ✅ CONFIRMED AS ACCEPTED RESIDUAL | Documented in `apply-progress.md` "Accepted residual — WARNING-2". The re-run smoke reproduces `en_catalogo/en_tarifa/activa tinyint(1) NOT NULL DEFAULT 0` from the XML `TRUE/FALSE/TRUE`, while the mirrored `tarif_tarifa_familia` has identical physical defaults. No framework/core file modified for it (`base/`, `src/`, root `controller/`/`model/` unrelated diffs do not reference this change and the modified `base/*` file does not mention it). Not treated as a blocker. |

**Residual summary**: A, C, D, E, F independently confirmed fixed; B confirmed as documentation-only; G confirmed as an accepted residual. None regressed.

## Completeness

| Metric | Value |
|--------|-------|
| Requirements in delta spec | 6 |
| Scenarios in delta spec | 14 |
| Tasks total (`tasks.md` Phases 1–5) | 20 |
| Tasks complete | 20/20 |
| Phase 6 (verification) checkboxes | `[x]` (6.1–6.4) |
| Unchecked implementation tasks | 0 |

## Build & Tests Execution

**1. catalogo_core suite** — `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
```text
OK, but there were issues!
Tests: 446, Assertions: 1350, Warnings: 25, Skipped: 1.
exit 0   sha256:b458eef2b9ef19c1806edc23f708b4b9e9f1bcdf8f8765bc9efafe2345200d61
```

**2. tarifario suite** — `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml`
```text
OK, but some tests were skipped!
Tests: 198, Assertions: 759, Skipped: 3.
exit 0   sha256:efba75989610a4c4790bf38e413a0c305a4f0fe27105828ba36994859d733803
```

**3. PHPStan** — `ddev exec composer phpstan`
```text
188/188 [============================] 100%
 [OK] No errors
exit 0   sha256:0b9439133e886daed09f416d29a5f87f002a1780c9b614256acc883c748ff6c9
```

**4. Focused class runs** (each independently re-invoked with a single `--filter`; `ddev exec` wraps `bash -c`, so `|`/parenthesised filters were never combined):

| Class | Layer | Result | Exit |
|-------|-------|--------|------|
| `InitOpcionalesTablesTest` (read-only) | Contract | `OK (7 tests, 41 assertions)` | 0 |
| `OpcionalDomainModelOwnershipTest` (read-only) | Contract | `OK (5 tests, 66 assertions)` | 0 |
| `TarifOpcionalesControllerContractTest` (read-only) | Contract | `OK (5 tests, 28 assertions)` | 0 |
| `TarifTarifaOpcionalFamiliaTest` (read-only) | Unit | `OK (6 tests, 28 assertions)` | 0 |
| `TarifOpcionalPreciosControllerTest` (read-only) | Contract | `OK (4 tests, 7 assertions)` | 0 |
| `OpcionalPriceUnificationTest` (read-only) | Unit | `OK (4 tests, 12 assertions)` | 0 |
| `TarifarioOpcionalStateTraitTest` (residual-modified) | Unit (runtime) | `OK (20 tests, 30 assertions)` | 0 |
| `TarifTarifaOpcionalLifecycleTest` (remediation) | Unit (spy SQL) | `OK (12 tests, 52 assertions)` | 0 |
| `TarifOpcionalesControllerMasterStateTest` (residual-modified) | Contract (source) | `OK (17 tests, 88 assertions)` | 0 |
| `TarifTarifaOpcionalTest` (new) | Unit | `OK (19 tests, 71 assertions)` | 0 |
| `TarifTarifaOpcionalPrecedenceTest` (residual-modified) | Unit (spy SQL) | `OK (12 tests, 49 assertions)` | 0 |
| `InitTarifTarifaOpcionalBootstrapTest` (new) | Contract | `OK (4 tests, 16 assertions)` | 0 |
| `TarifCatalogoOpcionalMasterExportTest` (new, tarifario) | Contract + runtime seam | `OK (6 tests, 24 assertions)` | 0 |
| `TarifTarifasHeredarOpcionalMasterTest` (residual-modified, tarifario) | Contract + runtime seam | `OK (5 tests, 33 assertions)` | 0 |

**Coverage**: ➖ Not available — no coverage tool configured (not a failure).

## Spec Compliance Matrix

| # | Requirement | Scenario | Covering evidence (re-run) | Result |
|---|-------------|----------|----------------------------|--------|
| 1 | Per-tarifa opcional master table | Master schema and keys | `TarifTarifaOpcionalTest::test_master_xml_declares_composite_pk_and_cascading_fks` (19/71) + fresh live smoke `SHOW CREATE TABLE tarif_tarifa_opcional`: `PRIMARY KEY (codtarifa,id_opcional)`, FK `ca_tarif_tarifa_opcional_tarifa`→`tarif_tarifas` CASCADE, FK `ca_tarif_tarifa_opcional_opcional`→`catalogo_opcionales` CASCADE | ✅ COMPLIANT |
| 2 | Opcional master table bootstrap | Fresh standalone install creates the table | `InitTarifTarifaOpcionalBootstrapTest::test_master_model_and_xml_exist_at_catalogo_core_paths`, `::test_master_is_ensured_after_the_five_moved_tables` (4/16) + re-executed live smoke (`SMOKE_RESULT=PASS`, master created after the 5 moved tables, both FK targets present) | ✅ COMPLIANT |
| 3 | Opcional master table bootstrap | Bootstrap is idempotent and class-safe | `InitTarifTarifaOpcionalBootstrapTest::test_master_ensure_is_idempotent_and_non_destructive` (no DROP/TRUNCATE/DELETE/ALTER/INSERT/UPDATE, no forced seed), `::test_master_ensure_is_guarded_for_class_safety` (`is_file` + `class_exists(...,false)` + `is_subclass_of`) + live smoke `run2_tables_identical=true`, `run2_ddl_identical=true` | ✅ COMPLIANT |
| 4 | Master state precedence | Activation ignores price-row presence | `TarifTarifaOpcionalPrecedenceTest::test_master_activa_is_authoritative_and_price_rows_never_activate` (12/49), `::test_search_solo_activos_filters_by_master_activa_not_price_rows`, `::test_count_filtered_solo_activos_filters_by_master_activa_not_price_rows`, `TarifOpcionalesControllerMasterStateTest::test_list_state_cache_resolves_effective_master_per_tarifa`, `::test_precios_reads_master_over_price_row` | ✅ COMPLIANT |
| 5 | Master state precedence | Catalog flag precedence | `TarifTarifaOpcionalPrecedenceTest::test_master_en_catalogo_wins_over_the_ext_fallback`, `TarifOpcionalesControllerMasterStateTest::test_list_state_cache_resolves_effective_master_per_tarifa` | ✅ COMPLIANT |
| 6 | Master state precedence | Order defaults with scoped override | `TarifTarifaOpcionalPrecedenceTest::test_master_orden_is_the_default_and_missing_inherits_zero` (resolve_orden contract) + `::test_per_tarifa_listing_orders_by_master_orden_default` (`search()` emits `LEFT JOIN … mto`, `COALESCE(mto.orden, 999999) AS orden_tarifa`, `ORDER BY orden_tarifa`) + `::test_per_tarifa_listing_scoped_family_orden_overrides_the_master_default` (`COALESCE(fto.orden, mto.orden, 999999) AS orden_tarifa`). The master default now has a production consumer (listing); the family/article resolvers are untouched. WARNING-1 CLOSED. | ✅ COMPLIANT |
| 7 | Master lifecycle: seed, lazy inherit, copy | Install seed inherits ext flags | `TarifTarifaOpcionalLifecycleTest::test_install_seeds_the_default_tarifa_from_catalogo_opcionales_left_join_ext` (`LEFT JOIN tarif_opcional_ext`, `COALESCE(...)`), `::test_install_keeps_the_fk_target_guards`, `::test_seed_is_empty_when_there_is_no_default_tarifa`; live smoke proves the install reaches the created master | ✅ COMPLIANT |
| 8 | Master lifecycle: seed, lazy inherit, copy | Missing row lazy-inherits | `TarifTarifaOpcionalTest::test_effective_missing_row_inherits_ext_defaults_without_persisting`, `::test_effective_missing_row_without_ext_row_falls_back_to_defaults`, `TarifTarifaOpcionalPrecedenceTest::test_missing_row_inherits_ext_flags_without_persisting` (asserts no writes) | ✅ COMPLIANT |
| 9 | Master lifecycle: seed, lazy inherit, copy | Tarifa copy inherits the master | `TarifTarifaOpcionalLifecycleTest::test_copy_from_tarifa_clears_the_destination_then_copies_the_master`, `::test_copy_from_tarifa_runs_the_delete_and_insert_in_one_transaction` (BEGIN/COMMIT + `$transaction=false`), `::test_copy_from_tarifa_rolls_back_when_the_copy_fails` (BEGIN/ROLLBACK), `::test_copy_from_tarifa_is_a_noop_when_source_equals_destination` (zero statements/transactions), `TarifTarifasHeredarOpcionalMasterTest::test_copy_tarifa_opcionales_delegates_to_the_master`, `::test_heredar_estructura_copies_the_master_after_the_ten_steps` (step 11) | ✅ COMPLIANT |
| 10 | Master consumption in UI and export | List Estado is per tarifa | `TarifOpcionalesControllerMasterStateTest::test_list_state_cache_resolves_effective_master_per_tarifa`, `::test_list_state_cache_marks_master_active_without_price_rows`, `::test_list_resolves_state_from_master_not_price_rows` | ✅ COMPLIANT |
| 11 | Master consumption in UI and export | Matrices persist master flags | `TarifOpcionalesControllerMasterStateTest::test_edit_matrix_reads_master_flags`, `::test_edit_matrix_reads_and_writes_master`, `::test_precios_reads_master_and_persists_it`, `::test_edit_single_tarifa_save_is_atomic_and_validates_the_price`, `::test_precios_single_tarifa_save_is_atomic`, `::test_list_declares_csrf_guarded_toggle_endpoints`, `::test_edit_bulk_matrix_save_is_csrf_guarded_and_atomic`, `::test_edit_bulk_matrix_parses_prices_with_the_validated_parser` (residual A) | ✅ COMPLIANT |
| 12 | Master consumption in UI and export | Export uses per-tarifa flags | `TarifCatalogoOpcionalMasterExportTest::test_export_skips_opcional_whose_master_activa_is_false`, `::test_export_reads_catalog_and_tarifa_flags_from_the_master`, `::test_export_inherits_active_state_when_no_master_row_exists`, `::test_export_resolves_each_distinct_opcional_once` | ✅ COMPLIANT |
| 13 | Unchanged boundaries and non-destructive migration | Catalog ownership and tpvmod untouched | `OpcionalDomainModelOwnershipTest` (`OK 5/66`) + fresh audit: `model/table/catalogo_opcionales.xml` and `model/core/catalogo_opcional.php` unmodified (`git status` empty; 0 master references); `grep -rn tarif_tarifa_opcional plugins/tpvmod` → 0 hits | ✅ COMPLIANT |
| 14 | Unchanged boundaries and non-destructive migration | Existing database stays valid | Lazy-inherit tests (scenario 8) + no mandatory backfill (seed guarded by `NOT EXISTS`, bootstrap ensure non-destructive, live smoke second run byte-identical); additive deploy | ✅ COMPLIANT |

**Compliance summary**: 14/14 scenarios compliant.

## Non-destructive Audit (independent re-run)

| Check | Result | Evidence |
|-------|--------|----------|
| `catalogo_opcionales` XML/model unchanged | ✅ | `git -C plugins/catalogo_core status --porcelain -- model/table/catalogo_opcionales.xml model/core/catalogo_opcional.php` → empty; `grep -c tarif_tarifa_opcional` → 0/0 |
| `tpvmod` no master reference | ✅ | `grep -rn tarif_tarifa_opcional plugins/tpvmod` → 0 hits. (`plugins/tpvmod` carries unrelated pre-existing working-tree changes from another session — controllers/views/tests — none references the master and none is part of this change.) |
| Cross-plugin edits only the two tarifario controllers + two tests | ✅ | `git -C plugins/tarifario status --porcelain` → `M controller/tarif_catalogo_view.php`, `M controller/tarif_tarifas.php`, `?? tests/Controller/TarifCatalogoOpcionalMasterExportTest.php`, `?? tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php` |
| Family/article scoped resolvers untouched | ✅ | `tarif_tarifa_opcional_familia`, `tarif_tarifa_opcional_resolver`, `tarif_tarifa_articulo_opcional` have no diff; `TarifTarifaOpcionalFamiliaTest` green |
| Additive / no mandatory backfill | ✅ | New model + XML + tests; seed guarded by `NOT EXISTS`; `InitTarifTarifaOpcionalBootstrapTest::test_master_ensure_is_idempotent_and_non_destructive` forbids DDL/DML in the ensure path; live smoke run 2 identical |
| No Composer dependency added | ✅ | `git status --porcelain -- composer.json composer.lock` empty in both plugins; `vendor/` commit step not applicable |
| No core `openspec/` entry | ✅ | `git status --porcelain openspec/` empty; `ls openspec/changes/ \| grep -i opcionales` → none |
| No framework/core file modified for this change | ✅ | Tracked root diffs (`base/fs_maintenance_mode.php`, `controller/admin_agentes.php`, `model/core/agente.php`, `.planning/*`, datepicker contract test) do not reference `tarif_tarifa_opcional` / `opcionales-por-tarifa` (0 hits each) and are unrelated pre-existing work |

## Correctness (Static Evidence)

| Requirement | Status | Notes |
|-------------|--------|-------|
| Per-tarifa opcional master table | ✅ Implemented | `model/tarif_tarifa_opcional.php` + XML: PK `(codtarifa,id_opcional)`, both FK CASCADE; declared defaults `TRUE/FALSE/TRUE/0` |
| Opcional master table bootstrap | ✅ Implemented | `Init.php` dedicated FK-safe step after the pinned 5-table loop (AD6); `is_file`/`class_exists`/`is_subclass_of` guards; live smoke PASS |
| Master state precedence | ✅ Implemented | `effective()`/`resolve_*`; `tarif_opcional::search()/count_filtered()` `solo_activos` uses `NOT EXISTS (… activa = FALSE)`; price rows never activate; master `orden` consumed with family override |
| Master lifecycle: seed, lazy inherit, copy | ✅ Implemented | `install()` → `seed_default_tarifa()`; non-persisting `effective()`; transactional `copy_from_tarifa()` with self-copy no-op; single-column `set_*` toggles |
| Master consumption in UI and export | ✅ Implemented | 3 controllers read the master; `tarif_catalogo_view::build_opcionales_export()` reads `effective()` and skips `activa === false`; `tarif_tarifas::heredar_estructura()` step 11; `copy_precios_opcionales()` preserves `porcentaje`/`en_catalogo` |
| Unchanged boundaries and non-destructive migration | ✅ Implemented | `catalogo_opcionales`/`tpvmod` untouched; additive/lazy |

## Coherence (Design)

| Decision | Followed? | Notes |
|----------|-----------|-------|
| AD1 resolution inside `tarif_tarifa_opcional` via `ext_defaults()` seam | ✅ Yes | `ext_defaults()` protected seam; no new resolver service |
| AD2 master `activa` sole activation; price never activates | ✅ Yes | Model + controllers + `solo_activos` + export |
| AD3 master `en_catalogo` wins; price `en_catalogo` per-lista; ext fallback | ✅ Yes | `effective()` + `build_opcionales_export()` |
| AD4 master `orden` default; family/article scoped override | ✅ Yes | Now consumed by `tarif_opcional::search()` with `COALESCE(fto.orden, mto.orden, 999999)`; family/article resolvers unchanged. WARNING-1 closed. |
| AD5 missing row resolves to ext/defaults, never persists; first toggle creates | ✅ Yes | `effective()` zero writes asserted; `persist_toggle()` create-or-update |
| AD6 dedicated FK-safe bootstrap step after the 5-table `foreach` | ✅ Yes | `Init.php`; pinned `FK_SAFE_SEQUENCE` literal untouched |
| Design test-plan files | ✅ All exist | 5 catalogo_core + 2 tarifario test files; no planned test missing |
| Design open question `copy_precios_opcionales()` | ✅ Resolved (item D) | The `porcentaje`/`en_catalogo` loss was fixed; the design.md checklist entry is stale but the behavior now aligns with the master copy. |

### Design deviations reported by apply (no spec break)

`default_tarifa_code()` seam, `opcional_master_state()`/`opcional_precio_model()` seams, `opcional_en_tarifa_flag()`/`esta_en_tarifa()` readers, per-tarifa edit-matrix forms, the export keeping the legacy `'activo'` field, and the remediation's shared `run_in_transaction()`/`parse_price_input()` trait helpers are additive/test-seam/UI-structure deviations. None breaks a spec scenario.

## TDD Compliance (Strict TDD active)

| Check | Result | Details |
|-------|--------|---------|
| TDD evidence reported | ✅ | Per-batch, round-1 (PVR.1–PVR.7), round-2 (PVR2.1–PVR2.3) and residual (R-A…R-F) "TDD Cycle Evidence" tables in `apply-progress.md` |
| All tasks have tests | ✅ | All 20 Phase 1–5 tasks map to existing test files; all remediation/residual rows map to existing tests |
| RED confirmed (tests exist) | ✅ | All 8 test files exist on disk; residual rows record concrete RED failures for A (1 failure), D (1 failure), E (2 failures); C is an explicit characterization test (behavior already correct), B is docs-only, F is a runtime smoke |
| GREEN confirmed (tests pass) | ✅ | 446/446 catalogo_core + 198/198 tarifario pass on independent re-run |
| Triangulation adequate | ✅ | Residual added single-separator locale cases, the master-default + family-override order cases, the bulk-parser source contract and the price-copy column contract |
| Safety Net for modified files | ✅ | Baselines recorded per batch and per remediation/residual row in `apply-progress.md` |

**TDD Compliance**: 6/6 checks passed.

### Test Layer Distribution

| Layer | Tests | Files | Tools |
|-------|-------|-------|-------|
| Unit / contract (DB-free spy + source contracts) | 95 | 8 | PHPUnit 11 |
| Integration (live DB) | 0 (1 scratch-DB smoke, not a PHPUnit suite) | 1 | manual harness |
| E2E | 0 | 0 | not installed |
| **Total** | **95** | **8** | |

### Assertion Quality

**Assertion quality**: ✅ All assertions verify real behavior. Residual spot audit: `test_edit_bulk_matrix_parses_prices_with_the_validated_parser` pairs positive parser usage with a negative `floatval(` assertion and an error-branch assertion; `test_parse_price_input_treats_a_single_separator_as_a_decimal` asserts concrete numeric values (`1.234`) for both separators; `test_per_tarifa_listing_orders_by_master_orden_default` / `..._scoped_family_orden_overrides_the_master_default` drive the real `search()` and assert the generated SQL semantics; `test_copy_precios_opcionales_preserves_the_per_lista_columns` asserts all three carried columns. No tautologies, ghost loops, or smoke-only tests found.

### Quality Metrics

**Linter/Type Checker**: ✅ PHPStan 188/188, `[OK] No errors`.
**Coverage**: ➖ Not available (no coverage tool configured).

## Issues Found

**CRITICAL**: None.

**WARNING**:
1. **OPEN — Full authenticated HTTP render not executed.** The list/edit/precios matrices and the export are covered by DB-free behavioural seam tests plus Twig/source contracts; a live authenticated HTTP render of the three matrices and the export was not executed in this pass (same deferral as the sibling change). This is the only open warning and it is not a blocker.
2. **ACCEPTED — Physical boolean defaults are `0`, not XML `TRUE`** (WARNING-2, item G). Live DDL confirms `en_catalogo`/`en_tarifa`/`activa tinyint(1) NOT NULL DEFAULT 0` while the XML declares `TRUE/FALSE/TRUE`. Root cause is the pre-existing framework `TypeNormalizer`; the mirrored `tarif_tarifa_familia` has identical defaults; runtime is unaffected (inserts set the columns explicitly). Documented as an accepted residual; no framework/core file modified. Not a blocker.

**SUGGESTION**:
1. Record the post-verify remediation rounds 1, 2 and residuals, and the confirmed findings (1–10 + A–G) in `archive-report.md`.
2. Keep the design.md "Open Questions" entry for `copy_precios_opcionales()` accurate: it was fixed (item D) and is currently stale.
3. Consider one integration test for the three matrices/export once a DB-backed harness exists, to reduce reliance on source-string contract assertions.
4. The full-row `save()` UPDATE branch in `tarif_tarifa_opcional` remains an unreachable defensive fallback (now documented); do not add new callers.
5. The change is well over the 400-line review budget; the chained-PR split recorded in `tasks.md` remains the right delivery boundary.

## Warning Closure Status (closed vs accepted vs open)

| Warning | Prior state | This pass |
|---------|-------------|-----------|
| WARNING-1 — master `orden` had no production consumer | Open | ✅ **CLOSED** — `tarif_opcional::search()` orders by `COALESCE(mto.orden, 999999)` with family override; `count_filtered()` matches; family/article resolvers untouched |
| WARNING-2 — physical boolean defaults `0` vs XML `TRUE` | Open | ➖ **ACCEPTED** — documented residual (item G), no code change, runtime unaffected, not a blocker |
| WARNING-3 — fresh-install standalone bootstrap not live-executed | Open | ✅ **CLOSED** — scratch-DB smoke re-executed live, `SMOKE_RESULT=PASS`, exit 0 (item F) |
| WARNING-4 — full authenticated HTTP render not executed | Open | **OPEN** (residual, non-blocking) |
| WARNING-5 — legacy bulk matrix parsed prices with `floatval()` | Open | ✅ **CLOSED** — bulk path now validates with `parse_price_input()`, no `floatval` (item A) |

## Verdict

**PASS WITH WARNINGS** — 6/6 requirements and 14/14 scenarios are backed by passing coverage; the catalogo_core (446/446), tarifario (198/198) suites and PHPStan (188/188) all exit 0; the read-only guards are green; the non-destructive audit is clean; all residual items A–G are independently confirmed (A/C/D/E/F fixed, B documented, G accepted), and the fresh-install scratch-DB smoke was re-executed live with `SMOKE_RESULT=PASS`. Not fully archive-ready because one residual warning remains open (full authenticated HTTP render not executed, non-blocking) and WARNING-2 is accepted as a pre-existing framework behavior. Recommended next step: `sdd-archive` after the human confirms WARNING-2 acceptance and reconciles the sibling `absorber-opcionales-tarifa-en-catalogo-core` change.
