```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:5199c7c1ecc2940196bb966a843e97ba474950681bde2be4426cb84b4c051712
verdict: fail
blockers: 1
critical_findings: 0
requirements: 46/48
scenarios: 151/153
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:7d2284dec43b836364a1315f7a47ab88b37eb2a0f1fab2dfc55077f85ea376fa
build_command: ddev exec composer phpstan
build_exit_code: 0
build_output_hash: sha256:885faf63ece47998db8240f28d46c622d532a6770bba825d2a2a344151c97400
```

## Verification Report

**Change**: `caracteristicas-producto`
**Version**: N/A (delta spec, no version field)
**Mode**: Standard (the SDD config declares `strict_tdd: true`, but no `strict-tdd-verify.md` runner is wired into this phase; the apply's RED→GREEN evidence is audited below instead)
**SDD root**: `plugins/catalogo_core/openspec/` (owner) + `plugins/tarifario/openspec/` (secondary). Core `openspec/` untouched.
**Supersedes**: the previous `verify-report.md` (recorded `requirements: 45/48`, `scenarios: 148/151`, and WU-1..WU-6 only). Every number in this report was re-derived from the tree and re-executed in this pass; the stale envelope was not carried over.
**Verified slices**: **WU-1, WU-2, WU-3, WU-4, WU-5, WU-6 and WU-8** (the soak-blocker closure). **WU-7 is NOT closed** (see "WU-7 Statement").

### Scope and Slice Boundaries

| Slice | Work units | Status in this report |
|---|---|---|
| PR-1 | WU-1 (foundation) + WU-2 (resolver/store/list/export) | Re-executed — 64/64 in-scope tests |
| PR-2 | WU-3 (backfill, read-through flag, dual-write) | Re-executed — 64/64 in-scope tests |
| PR-3 | WU-4 (D12 derivation, flag/toggle removal, hook context) | Re-executed — 104/104 consumer tests |
| PR-4 | WU-5 (clone step 12) + WU-6 (tarifario defaults + consumer rewrites) | Re-executed — 56/56 and 128/128 |
| PR-5 (pre-soak) | WU-8 (DEV-18, DEV-17, DEV-16, SUGGESTION 2/4) | Newly verified — 21/21 (catalogo_core) + 13/13 (tarifario) |
| PR-5 (post-soak) | WU-7 (reversibility test, closed-loop entry point, soak, gated drop) | **NOT CLOSED — out of scope** (only remaining slice) |

### Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 71 |
| Tasks complete | 66 |
| Tasks partial | 1 (`7.3`) |
| Tasks incomplete | 4 (`7.1b`, `7.4`, `7.5`, `7.6`) |

Derived from `tasks.md` with `grep -c '^- \[x\]'` → **66**, `'^- \[ \]'` → **4**, `'^- \[~\]'` → **1** (71 total).
Everything in WU-1..WU-6 plus the WU-8 section (8.1–8.5) and the CAR-19 gate G1.1–G1.3 is `[x]`.
The five open/partial items are all WU-7: `7.1b` (reversibility test), `7.3` (closed-loop operator entry point — docblock written, invoker absent), `7.4` (reversibility evidence), `7.5` (soak), `7.6` (gated destructive drop).

### Build & Tests Execution

All commands below were executed in this pass under `ddev` on PHP 8.3.33 / PHPUnit 11.5.56. Raw outputs are retained under `tmp/verify_car_evidence/` (gitignored scratch) with their SHA-256 digests, so the evidence is byte-reproducible from the working tree.

**Build (type gate)**: ✅ Passed (`exit 0`)
```text
$ ddev exec composer phpstan
Note: Using configuration file /var/www/html/phpstan.neon.
   188/188 [============================] 100%
 [OK] No errors
exit 0
```
`build_output_hash: sha256:885faf63ece47998db8240f28d46c622d532a6770bba825d2a2a344151c97400` (`tmp/verify_car_evidence/phpstan.txt`).
**Scope limitation (unchanged, must be recorded not assumed)**: `phpstan.neon` declares `paths: [src, tests]`, so this green run is the repository-root gate only and is **not** plugin type-safety evidence. The plugin suites are the plugin gate.

**Tests — catalogo_core plugin suite (owner)**: ✅ `exit 0`
```text
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
Tests: 768, Assertions: 3196, Warnings: 26, Skipped: 1.
OK, but there were issues!   exit 0
```
`test_output_hash: sha256:7d2284dec43b836364a1315f7a47ab88b37eb2a0f1fab2dfc55077f85ea376fa` (`tmp/verify_car_evidence/cc_suite.txt`).

**Tests — tarifario plugin suite (secondary)**: ✅ `exit 0`
```text
$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
Tests: 242, Assertions: 996, Skipped: 3.
OK, but some tests were skipped!   exit 0
```
`tmp/verify_car_evidence/tarif_suite.txt` → `sha256:73dbfc119a75090505d0e2020c7598d2bd9b072b62490c997635b5d03ce52e8d`.

**Tests — root `Plugins` suite (cross-plugin gate, previously only in Engram #817)**: ❌ 2 failures / 1439 total, `exit 1`
```text
$ ddev exec php vendor/bin/phpunit --testsuite Plugins
Tests: 1439, Assertions: 5257, Failures: 2, PHPUnit Deprecations: 12, Skipped: 24.

1) Tests\CatalogoCore\Controller\TarifTarifasGuardarTest::test_guardar_tarifa_normalizes_lowercase_coddivisa
   "No error should be emitted for a valid currency"  (TarifTarifasGuardarTest.php:226)
2) Tests\LegacySupport\LegacySupportTest::testPluginVersionIsBumpedTo120ForTheBaseJsMove
   expected '1.2.0', actual '1.3.0'  (plugins/legacy_support/tests/LegacySupportTest.php:116)
```
`tmp/verify_car_evidence/root_plugins.txt` → `sha256:db633a3266638f3d19400b4e344853fb85d5d80c6297a7939e5dbcecf25d5aeb`.
Exactly the **2 documented pre-existing failures** were reproduced and no others. Neither touches the feature: (1) is a currency-normalization test whose error comes from live divisa state, (2) is a plugin-version expectation in `legacy_support` (unrelated plugin). The stale report's `TarifTarifaOpcionalPrecedenceTest` failures are gone; no flaky 5th failure reproduced.

**Targeted in-scope runs (all `exit 0`, re-executed in this pass)**:

| Run | `--filter` (catalogo_core config unless noted) | Result |
|---|---|---|
| WU-1..WU-3 core services | `CaracteristicaModelTest\|CaracteristicaValorCatalogoTest\|CaracteristicaValorTriadTest\|CaracteristicaScopeTableTest\|InitCaracteristicasTablesTest\|CaracteristicaResolverTest\|CaracteristicaValorStoreTest\|CaracteristicaAssignmentScopeTest\|CaracteristicaBackfillTest\|CaracteristicaReadThroughTest\|CaracteristicaDefaultsTest\|CaracteristicaBoundariesTest` | ✅ 64 tests / 390 assertions |
| WU-4 consumers | `OpcionalVisibilityDerivationTest\|OpcionalVisibilityParityTest\|TarifFamiliasToggleTest\|TarifFamiliasFragmentTest\|TarifTabPreciosTest\|TarifOpcionalesControllerMasterStateTest\|VentasOpcionalesControllerMasterStateTest\|VentasOpcionalesExportParityTest\|TarifOpcionalEditCaracteristicaTest\|CatalogoOpcionalesUnifiedControllerTest` | ✅ 104 tests / 459 assertions |
| WU-4/WU-5 controllers + drop | `CaracteristicaCloneTest\|TarifTarifasHeredarCaracteristicaTest\|CaracteristicaDefaultsTest\|OpcionalVisibilityDerivationTest\|OpcionalVisibilityParityTest\|CaracteristicaColumnDropTest\|CaracteristicaBoundariesTest` | ✅ **56 tests / 341 assertions** (was 50 pre-WU-8 — see Deviation 1) |
| WU-8 catalogo_core | `ArticuloExcelCaracteristicaTest\|CaracteristicaColumnDropTest` | ✅ **21 tests / 87 assertions** |
| WU-8 tarifario | `plugins/tarifario/phpunit.xml` → `TarifCatalogoJsonImportVisibilityTest\|TarifCatalogoMembershipFilterRatificationTest` | ✅ **13 tests / 67 assertions** (8 + 5) |
| CAR-19 boundary gate | `CaracteristicaBoundariesTest` | ✅ 5 tests / 130 assertions |
| Locked ALC-02 / export set | `VentasArticulosListCaracteristicasTest\|VentasArticulosListAbsorptionTest\|ArticuloListaCanonicaOwnershipTest\|ArticuloExcelExportServiceTest\|ArticuloExcelImportWizardServiceTest\|ArticuloExcelCaracteristicaTest\|CaracteristicaReadThroughTest\|ArticuloTarifaPrecioOwnershipTest` | ✅ **47 tests / 244 assertions** (1 warning) |
| Frozen markers + migrated contracts | `VentasArticuloArticleEditAbsorptionTest\|CatalogoCoreHookMarkersTest\|CatalogoArticuloHookOwnershipTest\|CaracteristicaHookContextTest\|ArticuloExcelFeaturePersistenciaTest\|OpcionalDomainModelOwnershipTest\|TarifTarifaOpcionalTest\|TarifTarifaOpcionalPrecedenceTest\|TarifTarifaOpcionalLifecycleTest\|ArticuloTarifaPrecioOwnershipTest` | ✅ 100 tests / 543 assertions |
| tarifario WU-6 + WU-8 in-scope | `plugins/tarifario/phpunit.xml` → 13 files incl. the two new WU-8 suites | ✅ 128 tests / 628 assertions |

**Coverage**: ➖ Not available — no coverage driver (`php -m` reports neither xdebug nor pcov). Unchanged from the previous verification.

### Gatekeeper Deviation Resolution (mandatory)

**Deviation 1 — test-count off-by-one (`CaracteristicaColumnDropTest`).** Re-derived, and **it is worse than an off-by-one**:
- The class **runs 17 tests / 63 assertions** — `phpunit --filter CaracteristicaColumnDropTest` → `17 / 17 (100%)`. `tasks.md` (WU-7 inventory table, line 292) and the previous report's addendum (line 265) both recorded **"16/16 (5 new)"**.
- Per-file arithmetic, derived from two independent executed runs:
  - the pre-WU-8 seven-file in-scope run recorded **50** tests (previous report, line 88) and **the same seven filters now yield 56** → the only file among them modified by WU-8 is `CaracteristicaColumnDropTest`, therefore **11 → 17 = +6**, not +5;
  - `ArticuloExcelCaracteristicaTest` independently moved **3 → 4 = +1** (the locked export run recorded 46 tests pre-WU-8 and yields **47** now, with the extra test being `test_export_orders_feature_columns_by_orden`).
- Suite closure: **768 − 761 = +7 = (+6) + (+1)**. Exact.
- Correct statement to use everywhere: `CaracteristicaColumnDropTest` **17/17** (was 11, **+6** new); `ArticuloExcelCaracteristicaTest` **4/4** (was 3, **+1**). The recorded "16/16 (5 new)" was wrong in both the absolute (16 vs 17) and the delta (5 vs 6). `tasks.md` still carries the stale numbers; this report carries the re-derived ones.

**Deviation 2 — evidence placement (root `Plugins` suite + `phpstan`).** The previous report's inputs held the root-suite and static-analysis results only in Engram apply-progress #817. Both are now recorded as repo artifacts above with the exact command, exact totals, exit codes and output digests, along with the `phpstan` config-scope limitation (`src`+`tests` only, no `plugins/`).

**Deviation 3 — stale envelope.** Re-derived from native heading counts across the delta tree:
- `grep -rhE '^### (Requirement:|REQ-[0-9]+:)' specs/**` → **48**; `grep -rhE '^#### Scenario:'` → **153** (13 markdown files; `specs/README.md` contributes 0).
- Per file: `articulo-detalle-canonico` 2/5 · `articulo-lista-canonica` 1/4 · `articulos-excel-import-export` 3/8 · `articulo-tarifa-tab-management` 2/6 · `caracteristicas-producto` **19/62** · `catalogo-render-hooks` 2/5 · `familias-tarifa-management` 1/4 · `opcionales-management` 3/10 · `opcionales-tarifa-management` 6/16 · `opcionales-tarifa-selector` 3/7 · `tarifario/catalogo-excel-import-export` 4/17 · `tarifario/catalogo-integration` 2/9.
- The stale envelope (`45/48`, `148/151`) was a **total-153-agnostic** undercount. The change-wide totals are **48 requirements / 153 scenarios**; WU-8 added the two new `tarifario/catalogo-integration` scenarios (DEV-17 and DEV-18) and restated `R-TAR-HOOK-011` from 3 to 5 scenarios (151 → 153).
- Completed counts re-derived for this report: **46/48 requirements**, **151/153 scenarios** (the two incomplete scenarios are named in the matrix and the residuals).

### Spec Compliance Matrix

Statuses: ✅ COMPLIANT (covering test executed green) · ⚠️ PARTIAL · ❌ UNTESTED. Every test named below ran inside one of the executed runs above.

| # | Requirement (spec file) | Scen. | Result | Covering evidence (executed this pass) |
|---|---|---|---|---|
| 1 | CAR-01 Feature definition entity | 3/3 | ✅ | `CaracteristicaModelTest` |
| 2 | CAR-02 Value type whitelist | 2/2 | ✅ | `CaracteristicaModelTest` |
| 3 | CAR-03 Predefined value catalog | 2/2 | ✅ | `CaracteristicaValorCatalogoTest` |
| 4 | CAR-04 Custom value + triad invariant | 3/3 | ✅ | `CaracteristicaValorTriadTest`, `CaracteristicaResolverTest` |
| 5 | CAR-05 Scope tables keyed by tarifa/scope | 3/3 | ✅ | `CaracteristicaScopeTableTest`, `InitCaracteristicasTablesTest`; live: 5/5 feature tables exist (L1) |
| 6 | CAR-06 Effective-value precedence | 6/6 | ✅ | `CaracteristicaResolverTest` |
| 7 | CAR-07 Reads never persist | 2/2 | ✅ | `CaracteristicaResolverTest`; live: D12 batch derivation issues no writes |
| 8 | CAR-08 Lazy `DEF` inheritance | 2/2 | ✅ | `CaracteristicaValorStoreTest` |
| 9 | CAR-09 Assignment scopes | 3/3 | ✅ | `CaracteristicaAssignmentScopeTest` |
| 10 | CAR-10 Clone on tarifa copy | 3/3 | ✅ | `CaracteristicaCloneTest` (7/7), `TarifTarifasHeredarCaracteristicaTest` (5/5) |
| 11 | CAR-11 Default feature registration | 5/5 | ✅ | `CaracteristicaDefaultsTest`; `tarifario/tests/CaracteristicaDefaultsRegistrationTest`; live: 3 definitions with `origen`, bool pairs, `registerDefaults()` no-op twice (L2) |
| 12 | CAR-12 D12 opcional visibility derivation | 5/5 | ✅ | `OpcionalVisibilityDerivationTest` (12), `OpcionalVisibilityParityTest` (4); live read-only derivation returns one entry per opcional |
| 13 | CAR-13 Supersession backfill | 4/4 | ✅ | `CaracteristicaBackfillTest` |
| 14 | CAR-14 Read-through flag + dual-write soak | 3/3 | ✅ | `CaracteristicaReadThroughTest`; live flag-OFF parity on `VARI@DEF` (both flags) (L3) |
| 15 | CAR-15 Gated reversible post-soak drop | 2/3 | ⚠️ | Clause-1 gating and clause-2 refusal/drop/idempotency all pass in `CaracteristicaColumnDropTest` (17/17); the **"Reversibility from feature values" scenario is UNTESTED** — see WARNING 1 |
| 16 | CAR-16 `listable` columns in the product list | 4/4 | ✅ | `VentasArticulosListCaracteristicasTest` (7) |
| 17 | CAR-17 `importable`/`exportable` columns | 2/2 | ✅ | `ArticuloExcelCaracteristicaTest` (4/4), incl. the new `orden`-ordering test |
| 18 | CAR-18 Feature management panel | 5/5 | ✅ | `VentasCaracteristicasControllerTest` |
| 19 | CAR-19 Plugin-local boundaries | 2/2 | ✅ | `CaracteristicaBoundariesTest` 5/5 + independent shell gate (see Correctness) |
| 20 | ART-01 Canonical detail absorbs article edit | 3/3 | ✅ | `VentasArticuloArticleEditAbsorptionTest` |
| 21 | ART-02 Per-tarifa price/state + opcional editing | 2/2 | ✅ | `VentasArticuloArticleEditAbsorptionTest`, `TarifTabPreciosTest` |
| 22 | ALC-02 Per-tarifa columns | 4/4 | ✅ | `VentasArticulosListCaracteristicasTest` + locked `VentasArticulosListAbsorptionTest` / `ArticuloListaCanonicaOwnershipTest` (green unedited) |
| 23 | Export Excel | 3/3 | ✅ | `ArticuloExcelCaracteristicaTest`, locked `ArticuloExcelExportServiceTest`; `EXPORT_HEADERS` byte-identical in the `git diff HEAD` |
| 24 | Import wizard 3 pasos | 2/3 | ⚠️ | Dropdown + auto-suggest covered; "Legacy workbook without feature columns imports unchanged" is asserted at mapping level (`test_unmapped_or_empty_feature_column_is_a_no_op`), not end-to-end — see WARNING 4 |
| 25 | Persistencia | 2/2 | ✅ | `CaracteristicaReadThroughTest`, `ArticuloExcelFeaturePersistenciaTest` (previous WARNING 1 resolved) |
| 26 | ATT-02 Article Tarifas tab endpoint | 4/4 | ✅ | `TarifTabPreciosTest`, `CatalogoArticuloHookOwnershipTest` |
| 27 | ATT-04 Canonical detail renders/persists per-tarifa state | 2/2 | ✅ | `TarifTabPreciosTest` |
| 28 | catalogo-render-hooks Hook context contract | 3/3 | ✅ | `CaracteristicaHookContextTest` (4), `CatalogoCoreHookMarkersTest` (frozen four keys + `caracteristicas`) |
| 29 | catalogo-render-hooks Article hook tab renders derived values | 2/2 | ✅ | `CatalogoArticuloHookOwnershipTest` (`renders_resolver_driven_visibility`, `rendering_writes_nothing`) |
| 30 | familias-tarifa-management Toggle state management | 4/4 | ✅ | `TarifFamiliasToggleTest`, `TarifFamiliasFragmentTest` |
| 31 | OUM-03 Per-tarifa state and price display | 3/3 | ✅ | `VentasOpcionalesControllerMasterStateTest` (7) |
| 32 | OUM-04 CSRF-guarded state toggles | 3/3 | ✅ | `VentasOpcionalesControllerMasterStateTest` |
| 33 | OUM-07 Excel export parity | 3/3 | ✅ | `VentasOpcionalesExportParityTest` (3) |
| 34 | Opcional domain models owned by catalogo_core | 4/4 | ✅ | `OpcionalDomainModelOwnershipTest`, `TarifOpcionalExtMigrationTest` |
| 35 | Per-tarifa opcional master table | 3/3 | ✅ | `TarifTarifaOpcionalTest` |
| 36 | Master state precedence | 3/3 | ✅ | `TarifTarifaOpcionalPrecedenceTest` |
| 37 | Master lifecycle: seed, lazy inherit, copy | 3/3 | ✅ | `TarifTarifaOpcionalLifecycleTest`, `TarifTarifasHeredarOpcionalMasterTest` |
| 38 | Master consumption in UI and export | 2/2 | ✅ | `TarifOpcionalesControllerMasterStateTest`, `TarifCatalogoOpcionalMasterExportTest` |
| 39 | Unchanged boundaries and non-destructive migration | 1/1 | ✅ | `CaracteristicaBoundariesTest`, `TarifOpcionalExtMigrationTest` |
| 40 | OTS-02 Scoped editable panel + overview | 3/3 | ✅ | `TarifOpcionalEditCaracteristicaTest` |
| 41 | OTS-05 CSRF-guarded per-tarifa save | 2/2 | ✅ | `TarifOpcionalEditCaracteristicaTest` |
| 42 | OTS-09 Test-locked contracts intact | 2/2 | ✅ | `TarifOpcionalEditCaracteristicaTest`, `TarifOpcionalesControllerContractTest` |
| 43 | tarifario Import Excel unificado (En SAP/En Tarifa/En Catálogo) | 8/8 | ✅ | `ExcelImportWizardServiceTest`, `ExcelRowUpdaterFeatureVisibilityTest`, `ExcelWizardCreatePathFeatureTest`, `Model/TarifArticuloFactoryForImportTest` |
| 44 | tarifario Field catalog (R8) | 5/5 | ✅ | `ExcelImportWizardFieldCatalogTest` (6) — the `grupo` divergence is documented in its docblock |
| 45 | tarifario Create path on match-key miss (R6) | 3/3 | ✅ | `ExcelImportWizardServiceTest` + `ExcelWizardCreatePathFeatureTest` canary |
| 46 | tarifario Canary contract del create path | 1/1 | ✅ | `ExcelWizardCreatePathFeatureTest::testCanaryCreatePathWritesFeatureDefaults` |
| 47 | tarifario R-TAR-HOOK-002 Equivalent hook surface on ventas_opcional | 4/4 | ✅ | `OpcionalOwnedVisibilityRemovalTest`, `TarifConfiguradorOpcionalesTest`, `TarifCatalogoOpcionalMasterExportTest`, `VentasOpcionalesControllerMasterStateTest` |
| 48 | tarifario R-TAR-HOOK-011 Visibility consumers resolve feature values | **5/5** | ✅ | "Export set preserved" → `TarifCatalogoOpcionalMasterExportTest`; "reads follow the resolver" → `TarifCatalogoVisibilityReadThroughTest`; "membership filters ratified" → `TarifCatalogoMembershipFilterRatificationTest` (NEW); "JSON import dual-writes" → `TarifCatalogoJsonImportVisibilityTest` (NEW); "en_sap untouched" → `ExcelRowUpdaterFeatureVisibilityTest` + `ExcelWizardCreatePathFeatureTest`. The restated requirement no longer contains an untestable configurator scenario (DEV-16 closed) |

**Compliance summary**: **151/153 scenarios complete**; **46/48 requirements complete**. The two incomplete scenarios are #15 "Reversibility from feature values" (UNTESTED → WU-7) and #24 "Legacy workbook without feature columns imports unchanged" (PARTIAL).

Pointer audit (performed for this report, script retained at `tmp/verify_car_spec_pointer_audit.php`): 13 spec files, 48 requirements, 153 scenarios, 132 coverage pointer lines. All 45 distinct `plugins/**/*.php` paths referenced by the delta specs **exist** (zero dangling pointers). The 21 scenarios without an explicit pointer were inspected: 14 are in the tarifario Excel spec whose scenario headings are literally test-method names (`testFieldCatalog_*`, `testCreatePath_*`) covered by the tarifario suite, and 7 are in `opcionales-management` / `articulo-lista-canonica` whose requirement-level covering tests exist and pass; they are legacy scenario styles from the earlier changes, not new gaps.

### Correctness (Static Evidence)

| Check | Status | Notes |
|---|---|---|
| CAR-19 — no core production file changed | ✅ | Root `git status --porcelain` is **empty**; `openspec/changes/caracteristicas-producto/` does not exist in the root tree (`ls openspec/changes/` lists only the 8 unrelated core changes); `CaracteristicaBoundariesTest::test_no_entry_exists_in_the_repository_root_openspec` passes |
| CAR-19 — frozen 6-name baseline not grown | ✅ | **Independent re-implementation** of the scan (not the PHPUnit class) over 33 tarifario production files vs 122 catalogo_core production files, comment-stripped via `token_get_all`, 28 tarifario-owned names after the self-diff → `found(6) = tarif_grupo_rol, tarif_grupo_tarifa, tarif_grupo_usuario, tarif_historial_precios, tarif_precio_historial, tarif_tarifa_rol`, `matches_frozen_baseline=YES`, `new_names=` empty. Script: `tmp/verify_car_boundary_gate.php` |
| CAR-19 — dependency direction + no new Composer requirement | ✅ | `catalogo_core/fsframework.ini` → `require = ""`; `tarifario/fsframework.ini` → `require = "catalogo_core"`; no Composer package references tarifario (`CaracteristicaBoundariesTest::test_no_new_composer_requirement_is_introduced`) |
| DEV-18 — JSON import mirrors the feature store | ✅ | Executed `TarifCatalogoJsonImportVisibilityTest` 8/8. Source: `process_familias_batch()` L2842/L2872-2886 and `process_articulos_batch()` L2948/L3011-3026 derive **once** via `visibilidad_importada()` and feed both the legacy columns and `sincronizar_visibilidad_import()` → `assign_with_dual_write()`; the mirror runs after the legacy `save()`; failures are counted in the additive `$stats['visibilidad_sin_sincronizar']` + one `error_log` per batch. Legacy writes retained (soak-window path) |
| DEV-17 — membership filters ratified + gated | ✅ | `MEMBERSHIP_FILTERS_LEGACY_BY_DESIGN` (11 literals across 3 files) + `MEMBERSHIP_HELPER_CALL_SITES_LEGACY_BY_DESIGN` (5 call sites) at `tarif_catalogo_view.php:133/156`; executed `TarifCatalogoMembershipFilterRatificationTest` 5/5. The ordered closure prerequisite (rewrite before the drop) is recorded in the service docblock L58-64, `design.md` §8.4 and `tasks.md` 7.6 |
| DEV-16 — stale design row closed | ✅ | `design.md` §8.4 rows L633 (`tarif_configurador_opcionales`) and L634 (`tarif_articulo`) both state "no visibility read"; prose L474/L478/L1000-1001 corrected; the WU-6 file list (L1128) now names `Services/ExcelRowUpdater.php` and `Services/ArticuloListActionHandler.php` |
| SUGGESTION 2 — dead columns gated | ✅ | `CaracteristicaColumnDropMigration::DEAD_OPCIONAL_TABLES = ['catalogo_opcionales']` + gated, idempotent, introspective `dropDeadOpcionalColumns()` (never boot-wired); `catalogo_opcional_precios.en_catalogo` preserved (checked in `model/table/catalogo_opcional_precios.xml` L27 and asserted in the drop test) |
| SUGGESTION 4 — export `orden` contract | ✅ | `ordered_exportable()` sorts by `orden` then `codigo` and is applied at `buildSpreadsheet()` entry; `ArticuloExcelCaracteristicaTest::test_export_orders_feature_columns_by_orden` passes. `EXPORT_HEADERS` literal unchanged vs `HEAD` (verified in the `git diff HEAD`) |
| WU-8 never wired the destructive drops | ✅ | `grep -rn 'dropLegacyArticleFamilyColumns\|dropDeadOpcionalColumns\|dropD12OpcionalColumns' plugins/ --include='*.php'` (excluding tests/openspec) returns only the service itself plus one documentation comment; no `Init::init()`/boot/CLI wiring |
| Spec pointer integrity | ✅ | All 45 referenced test paths exist (see pointer audit) |
| Live schema not at clause parity | ⚠️ | Read-only introspection: `tarif_opcional_ext`/`tarif_tarifa_opcional` still carry the D12 flags; `tarif_articulo_precios`/`tarif_tarifa_articulo`/`tarif_tarifa_familia` still carry the clause-2 flags; `catalogo_opcionales` still carries the dead flags. Expected — the drops are operator-gated and were not executed (RESIDUAL 2) |

### Coherence (Design)

| Decision | Followed? | Notes |
|---|---|---|
| Pinned class/service names (§1) and WU-8 additions | ✅ | `visibilidad_importada()`/`sincronizar_visibilidad_import()`, `dropDeadOpcionalColumns()`, `ordered_exportable()` all present at the pinned paths |
| Single derivation feeds legacy + feature (DEV-18 design) | ✅ | Stronger than "two writes kept in sync by convention": `visibilidad_importada()` is the one source and the source contract asserts the old inline `(bool) $fam_data['en_tarifa']` casts are gone |
| Clause 2 gated on `read_through()`, not boot | ✅ | `dropLegacyArticleFamilyColumns()` returns FALSE while the flag is off; unit-covered both ways; no boot wiring |
| `tarif_familia_ext` introspection-guarded no-op (divergence 1) | ✅ | Live table has only `codfamilia`/`capitulo`/`nivel`; the drop test asserts no ALTER for it |
| `catalogo_opcional_precios.en_catalogo` stays (constraint 2) | ✅ | Present in the XML and explicitly excluded from the drop |
| Design §8.4 row for `tarif_configurador_opcionales` | ✅ (was ❌) | Corrected by WU-8 for both tables (DEV-16) |
| `final`/untyped-class relaxation for DB-free doubles | ⚠️ accepted | `ArticuloExcelExportService::ordered_exportable()` follows the same deliberate pattern (`final` removed so anonymous subclasses can drive it); documented, breaks no spec |
| WU-6 "new/modified files" list | ✅ (was stale) | Reconciled in `design.md` §15 and `tasks.md` L239 |

### Live-DB Harness Evidence (re-run in this pass)

Both retained harnesses were re-executed; neither is a new write path.

**A. Read-only introspection** — `ddev exec php tmp/verify_car_live_introspect.php` → exit 0:
```text
feature tables: catalogo_caracteristicas rows=3 | catalogo_caracteristica_valores rows=4
              | catalogo_caracteristica_global rows=0 | catalogo_caracteristica_familia rows=2
              | catalogo_caracteristica_articulo rows=0
definitions:   en_catalogo(tarifario,bool) en_tarifa(tarifario,bool) medidas(catalogo_core,string)
legacy columns still present: tarif_opcional_ext yes/yes · tarif_tarifa_opcional yes/yes
              tarif_articulo_precios yes/yes · tarif_tarifa_articulo yes/yes
              tarif_tarifa_familia yes/yes · tarif_familia_ext no/no · catalogo_opcionales yes/yes
data volume:   articulos=326 · tarif_tarifas=1 · tarif_articulo_precios=0
              tarif_tarifa_articulo=0 · tarif_tarifa_familia=1
              catalogo_opcionales=2 · catalogo_articulo_opcional=0 · catalogo_opcional_familias=0
```

**B. Live checks harness** — `ddev exec php tmp/verify_car_live_harness.php` → **17/17 PASS, exit 0** (`sha256:43485c5763df6e54a3c48a0891f47bdcf1b97a83f3c92345adf353bde1d7e196` when last captured; the content is unchanged and was re-run in this pass):
```text
L1 feature schema       5/5 tables exist                                    (CAR-05)
L2 CAR-11 defaults      medidas/en_catalogo/en_tarifa with origen + bool pairs;
                        registerDefaults() no-op (defs 3->3, values 4->4) and again 7/7
L3 CAR-14 live parity   VARI@DEF en_catalogo legacy=true  -> resolver=true
                        VARI@DEF en_tarifa   legacy=false -> resolver=false  3/3
L4 CAR-12 live read     derived {"2":null,"1":null} for tarifa DEF, 1 entry per opcional,
                        zero writes                                         2/2
L5 CAR-15 live schema   tarif_opcional_ext D12 columns still present: YES (2)
                        tarif_articulo_precios clause-2 columns still present: YES (2)
```
Write surface used by B: only `CaracteristicaRegistry::registerDefaults()` / `seedBoolPair()` (the plugin's own idempotent boot statements). Everything else is SELECT/SHOW.

**C. Residual — the WU-6 write-path live claim remains non-reproducible.** `tmp/verify_wu6_caracteristicas.php` is still absent (`ls` → "No such file or directory"), and the live DB still has 0 `tarif_articulo_precios`, 0 `tarif_tarifa_articulo`, 0 `catalogo_articulo_opcional` and 0 `catalogo_opcional_familias` rows, so no parity sample exists without synthetic seeding; and `fs_db2::exec()` auto-commits, so a synthetic write cannot be rolled back (the apply leaked rows once doing exactly that). The store write path stays verified only by the DB-free suites. RESIDUAL 3.

### TDD Evidence Audit

| Check | Result | Details |
|---|---|---|
| TDD evidence reported | ⚠️ | Apply sections cite RED-first per work unit (e.g. WU-8.1 "5 errors + 3 failures before the change"; WU-8.4/8.5 "RED first"), but there is no per-task RED→GREEN transcript in the repo artifacts |
| All implemented behaviours have tests | ✅ | Every WU-1..WU-6 and WU-8 behaviour maps to a named test in the matrix |
| Executed GREEN | ✅ | catalogo_core 768/3196 and tarifario 242/996, plus the 10 targeted runs above, all `exit 0` |
| Deltas honoured | ✅ | WU-8's two new tarifario suites and the two extended catalogo_core suites are the only test deltas; the locked set passed **unedited** (47/47) and the frozen marker set 100/100 |
| Safety net | ✅ | No locked assertion was weakened: the ALC-02/export set and the byte-level marker contracts are green, and each removal (opcional-owned flags) carries an explicit absence assertion |

### Issues Found

**CRITICAL**: **None.** In the delivered scope (WU-1..WU-6 + WU-8) there is no spec violation and no broken locked contract: the locked ALC-02/export set passes 47/47, the frozen byte-level marker contracts pass 100/100, the CAR-19 gate has not grown (independent scan = frozen 6), no core production file changed, both plugin suites exit 0, `phpstan` is clean, and the live read/parity harness is 17/17.

**WARNING**:

1. **WU-7 is not closed — the change is not archive-ready (completeness blocker, not a quality failure).** `tasks.md` `7.1b`, `7.4`, `7.5`, `7.6` are unchecked and `7.3` is partial; `tests/CaracteristicaReversibilityTest.php` does not exist; the read-through flag has never been enabled anywhere; the destructive drops were not executed (live introspection shows every target column still present). CAR-15 remains **2/3** and its reversibility scenario is UNTESTED.
2. **The CAR-15 "Reversibility from feature values" scenario has a spec pointer that overstates coverage.** The scenario's `Test:` line points at `CaracteristicaColumnDropTest.php`, but that class contains no reversibility assertion (its closest test, `test_no_clause_ever_drops_a_feature_value_column`, only proves the feature tables are never dropped). No test reproduces "re-add nullable columns and re-derive every previously stored legacy value". The pointer must be corrected when WU-7 7.1b lands. Same class as SUGGESTION 3.
3. **`tasks.md` still records the wrong `CaracteristicaColumnDropTest` arithmetic** ("16/16", "+5 tests" at lines 292/316). The executed, independently derived numbers are **17/17 (+6)**, with `ArticuloExcelCaracteristicaTest` **4/4 (+1)**, closing 761 → 768 exactly. This report carries the corrected numbers; the task list was not edited (out of scope for verify).
4. **`articulos-excel-import-export` "Legacy workbook without feature columns imports unchanged" is only partially proven** (PARTIAL). The base-field half of the THEN clause is asserted at the mapping/field-catalog level (`test_unmapped_or_empty_feature_column_is_a_no_op`), not end-to-end through an unmapped legacy workbook. Unchanged from the previous verification.
5. **The JSON import mirror is not executed end-to-end.** 4 of the 8 `TarifCatalogoJsonImportVisibilityTest` tests are region-scoped source contracts over `process_familias_batch()` / `process_articulos_batch()`; the other 4 are a real store-write → real resolver-read closed loop over in-memory scope models. That is a genuine behavioural proof of the mirror mechanism, but the batch methods themselves never run against a DB in any suite. Accepted as sufficient for DB-free coverage; recorded so it is not mistaken for end-to-end evidence.

**SUGGESTION**:

1. Correct the CAR-15 reversibility scenario's `Test:` pointer and land `CaracteristicaReversibilityTest.php` as part of WU-7 `7.1b`.
2. Fix the `tasks.md` WU-7 inventory arithmetic (`16/16` → `17/17`, `+5` → `+6`) so the next phase does not inherit the drift.
3. When WU-7's soak starts, enable `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` in staging and watch `visibilidad_sin_sincronizar` during a JSON import — that is the metric DEV-18 introduced and the natural soak signal.
4. Rewrite the DEV-17 membership filters to the feature tables **before** `dropLegacyArticleFamilyColumns()` (the ordering is recorded but not enforced by code); the drop currently has no guard that would fail fast if the rewrite had not landed.
5. Add an end-to-end legacy-workbook import assertion to close scenario #24, or restate the scenario to its mapping-level contract.

### Residuals (explicit)

| # | Residual | Severity | Evidence / impact |
|---|---|---|---|
| 1 | WU-7 open: reversibility test, closed-loop entry point, soak, gated drop | Blocker (completeness) | `tasks.md` 7.1b/7.3/7.4/7.5/7.6; `CaracteristicaReversibilityTest.php` absent; flag never enabled |
| 2 | Destructive drops never executed; live schema not at parity | WARNING | Live introspection: D12 flags, clause-2 flags and the dead `catalogo_opcionales` flags all still present. Code is gated + idempotent + boot-free |
| 3 | WU-6 write-path live checks not reproducible | WARNING | `tmp/verify_wu6_caracteristicas.php` absent; live DB has no legacy rows to sample; `fs_db2::exec()` auto-commits. Mitigated by retaining `tmp/verify_car_live_harness.php` + `tmp/verify_car_live_introspect.php` and by the DB-free suites |
| 4 | Import-mirror batch methods not executed against a DB | WARNING | 4/8 WU-8 import tests are source contracts; the other 4 exercise the real store→resolver loop over in-memory models |
| 5 | CAR-15 reversibility scenario pointer is wrong | WARNING | Spec points at `CaracteristicaColumnDropTest.php`, which has no such test |
| 6 | `tasks.md` test arithmetic drift | SUGGESTION | Lines 292/316 say 16/16 (+5); real is 17/17 (+6) |
| 7 | `phpstan` does not analyse `plugins/` | INFO | `phpstan.neon` paths are `src`+`tests`; the plugin suites are the plugin type gate |

### WU-7 Statement (explicit)

**WU-7 (CAR-15 clause 2's destructive completion) is the ONLY remaining slice and is NOT closed.** Out of the 5 open/partial tasks, all belong to WU-7: `7.1b` (the CAR-15 reversibility test — the only missing test), `7.3` (a non-boot, operator-callable closed-loop entry point that runs clause 1 → clause 2 → `dropDeadOpcionalColumns()`; the runbook is written but no invoker exists), `7.4` (reversibility evidence + report update), `7.5` (the read-through soak in staging), `7.6` (the gated drop on a pre-drop dump, ordered after the DEV-17 rewrite). The read-through flag has never been enabled and no `DROP COLUMN` has ever run. **Clause-2 code itself is NOT the gap**: `dropLegacyArticleFamilyColumns()` and `dropDeadOpcionalColumns()` are implemented, gated, idempotent, introspectively guarded and unit-covered (`CaracteristicaColumnDropTest` 17/17). WU-7's absence is reported as **incompleteness that gates archive**, not as a failure of the delivered work's quality.

### Verdict

**FAIL for the change as a whole — PASS WITH WARNINGS for the verified WU-1..WU-6 + WU-8 slices.**

The envelope is `fail` because the change is **incomplete**, not because the delivered work is wrong: the envelope's own totals exceed the completed counts (`46/48` requirements, `151/153` scenarios) and `blockers: 1` names the single open slice (WU-7). 5 of 71 tasks are open/partial, all in WU-7; a passing envelope would not be admissible.

Within the verified scope the delivered quality is high and now **better evidenced than the stale report**: the two soak blockers are genuinely closed (DEV-18 by a real store→resolver closed loop plus a single-derivation refactor of both batch methods; DEV-17 by a frozen, test-gated inventory with a recorded closure prerequisite), DEV-16 and the doc/spec pointer drift are fixed, SUGGESTION 2 and 4 are implemented, and every count in this report was re-executed rather than inherited. Both plugin suites are green (768/3196 and 242/996), the root `Plugins` suite reproduces **exactly** the 2 documented pre-existing unrelated failures, `phpstan` is clean, the CAR-19 gate still matches the frozen 6-name baseline under an independent re-implementation, and the retained live harness re-runs 17/17. No earlier WARNING was silently dropped: the three unresolved ones (WU-7's scope, the non-reproducible WU-6 write-path live claim, and the partial legacy-workbook scenario) are carried forward with their evidence.

Archive must wait for WU-7.
