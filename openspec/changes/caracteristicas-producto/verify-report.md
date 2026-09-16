```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:18016830d55010e3cfdcfd531fd44eeda107145d0645ac6219f44d288f2d7a29
verdict: fail
blockers: 1
critical_findings: 0
requirements: 47/48
scenarios: 153/154
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:93db8f54bc16b9a6fd0dda518ea4af9584178e96fcb49c58a09f45766256b788
build_command: ddev exec composer phpstan
build_exit_code: 0
build_output_hash: sha256:aa8f5b1f12073416ecfbdd25eeeec34517ca9191d7d009d2ebbcf6050296b525
```

## Verification Report

**Change**: `caracteristicas-producto`
**Version**: N/A (delta spec, no version field; catalogo_core `1.6.0`, tarifario `3.2.0`)
**Mode**: Standard (the plugin configs declare `strict_tdd: true` but no `strict-tdd-verify.md` runner is wired into this phase; the apply RED→GREEN record is audited in the TDD section instead)
**SDD root**: `plugins/catalogo_core/openspec/` (owner, `ownership: plugin-local`) + `plugins/tarifario/openspec/` (secondary). Core `openspec/` holds **no** entry for this change (`ls openspec/changes/` → 8 unrelated core changes).
**Supersedes**: the previous `verify-report.md`, which was stale on two axes — it predated (a) the **WU-7 pre-soak prep slice** (7.1b reversibility test + 7.3 closed-loop entry point) and (b) the **CodeRabbit review rounds**. Every number below was re-derived from the current tree and re-executed in this pass; the stale envelope (`46/48`, `151/153`, `768/3196`, `242/996`, root `1439/5257`) was not carried over.
**Verified slices**: **WU-1, WU-2, WU-3, WU-4, WU-5, WU-6, WU-8** (soak-blocker closure) **and the WU-7 pre-soak prep (7.1b + 7.3)**, plus the **CodeRabbit review fixes**. **WU-7 items 7.5 (soak) and 7.6 (gated drop) are the only remaining slice.**

### Scope and Slice Boundaries

| Slice | Work units | Status in this report |
|---|---|---|
| PR-1 | WU-1 (foundation) + WU-2 (resolver/store/list/export) | Re-executed — plugin suite green; focused classes 64/64 |
| PR-2 | WU-3 (backfill, read-through flag, dual-write) | Re-executed — `CaracteristicaBackfillTest` 7/7, `CaracteristicaReadThroughTest` 6/6 |
| PR-3 | WU-4 (D12 derivation, flag/toggle removal, hook context) | Re-executed — plugin suite green; drop + reversal classes 35/35 |
| PR-4 | WU-5 (clone step 12) + WU-6 (tarifario defaults + consumer rewrites) | Re-executed — `CaracteristicaCloneTest` 7/7, `TarifTarifasHeredarCaracteristicaTest` 5/5, tarifario suite green |
| PR-5 (pre-soak) | WU-8 (DEV-18, DEV-17, DEV-16, SUGGESTION 2/4) **+ WU-7 7.1b + 7.3** | Newly verified — reversibility 10/10, entry point 8 new tests, runner dry-run + refusal re-executed |
| PR-5 (post-soak) | WU-7 `7.5` soak + `7.6` gated drop | **NOT CLOSED — the only remaining slice** |

Committed state: catalogo_core `caracteristicas-producto` 3 commits ahead of `main` (110 files, +20,261/−690); tarifario `caracteristicas-producto` 3 commits ahead of `master` (37 files, +6,621/−176). Both plugin working trees are **clean** (`git status --short` empty); the root tree is clean.

### Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 71 |
| Tasks complete | 68 |
| Tasks partial | 1 (`7.4`) |
| Tasks incomplete | 2 (`7.5`, `7.6`) |

Derived from `tasks.md` with `grep -c '^- \[x\]'` → **68**, `'^- \[ \]'` → **2**, `'^- \[~\]'` → **1** (71 total; the marker counts are identical with and without the leading-space variants, so there are no nested checkboxes distorting the total). Every task in WU-1..WU-6, WU-8 (8.1–8.5), the CAR-19 gate (G1.1–G1.3) **and** the WU-7 pre-soak items `7.1b`/`7.3` is `[x]`. The three open/partial items are all inside WU-7 and all post-soak: `7.4` (reversibility evidence ratification — the evidence landed, the item stays `[~]` because the report update belongs to this phase), `7.5` (the read-through soak), `7.6` (the gated destructive drop).

### Build & Tests Execution

All commands below were executed in this pass under `ddev` on PHP 8.3.33 / PHPUnit 11.5.56. Raw outputs are retained as byte-reproducible artifacts under `tmp/verify_car_evidence/` (gitignored scratch); `evidence_revision` is the SHA-256 of the sorted `basename:sha256` manifest of those artifacts.

**Build (type gate)**: ✅ Passed (`exit 0`)
```text
$ ddev exec composer phpstan
Note: Using configuration file /var/www/html/phpstan.neon.
   188/188 [============================] 100%
 [OK] No errors
exit 0
```
`build_output_hash: sha256:aa8f5b1f12073416ecfbdd25eeeec34517ca9191d7d009d2ebbcf6050296b525` (`tmp/verify_car_evidence/phpstan.txt`).
**Scope limitation (must be recorded, not assumed)**: `phpstan.neon` declares `paths: [src, tests]`, so this green run is the repository-root gate only and is **not** plugin type-safety evidence. The plugin suites are the plugin gate, supplemented by `php -l` on the touched plugin files (10/10 clean, see Correctness).

**Tests — catalogo_core plugin suite (owner, the config-declared runner)**: ✅ `exit 0`
```text
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
Tests: 794, Assertions: 3450, Warnings: 26, Skipped: 1.
OK, but there were issues!   exit 0
```
`test_output_hash: sha256:93db8f54bc16b9a6fd0dda518ea4af9584178e96fcb49c58a09f45766256b788` (`tmp/verify_car_evidence/cc_suite.txt`). The 26 warnings are the pre-existing plugin-wide warning set (26 in every prior gate), and the 1 skip is the pre-existing conditional skip — neither is new.

**Tests — tarifario plugin suite (secondary)**: ✅ `exit 0`
```text
$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
Tests: 256, Assertions: 1043, Skipped: 3.
OK, but some tests were skipped!   exit 0
```
`sha256:f514ac89db6c3ac52aee8a2d9635ee029b9527fb3d10ae1c20de94c2fbfecb08` (`tmp/verify_car_evidence/tarif_suite.txt`). The 3 skips are pre-existing conditional skips.

**Tests — root `Plugins` suite (cross-plugin gate)**: ❌ 2 failures / 1476 total, `exit 1`
```text
$ ddev exec php vendor/bin/phpunit --testsuite Plugins
Tests: 1476, Assertions: 5554, Failures: 2, PHPUnit Deprecations: 12, Skipped: 24.

1) Tests\CatalogoCore\Controller\TarifTarifasGuardarTest::test_guardar_tarifa_normalizes_lowercase_coddivisa
   "No error should be emitted for a valid currency"  (plugins/catalogo_core/tests/Controller/TarifTarifasGuardarTest.php:226)
2) Tests\LegacySupport\LegacySupportTest::testPluginVersionIsBumpedTo120ForTheBaseJsMove
   expected '1.2.0', actual '1.3.0'  (plugins/legacy_support/tests/LegacySupportTest.php:116)
```
`sha256:fc55eada8b77b7d4b41a3c5fa3df0d96fcbef7f400dbaa6eeb515bd157d86a6e` (`tmp/verify_car_evidence/root_plugins.txt`). Exactly the **2 documented pre-existing failures** reproduced and no others; both were already recorded as the pre-existing baseline in this same `tasks.md` at earlier gates (WU-4 §4.10 line 213 and WU-6 §6.8 line 248):

- **(1) is a test-hygiene defect in an untouched file, not a feature regression.** `plugins/catalogo_core/tests/Controller/TarifTarifasGuardarTest.php` was last modified by the *previous* change (`36ed79ca`), not by this branch. It **passes** (2/2) inside the catalogo_core suite, which sets `processIsolation="true"`, and **fails** in the shared-process root suite even when filtered to that class alone (`--testsuite Plugins --filter TarifTarifasGuardarTest` → 2 tests, 1 failure). Mechanism verified in source: test #13 emits a currency error through `fs_app::new_error_msg()` → `fs_core_log::new_error()`, which appends to the **static** `fs_core_log::$data_log`; `get_errors()` reads that static via `fs_app::$core_log`; test #14's `resetCoreLog()` only re-instantiates `fs_model::$core_log` when it is `null` and never clears `$data_log`, so the error leaks forward. Process isolation (the plugin suite) hides it; the root suite does not. Independence from this change is therefore structural, not merely asserted.
- **(2) is an unrelated plugin's own version expectation**: `legacy_support` asserts its `fsframework.ini` is `1.2.0` while its repo has since shipped `1.3.0`. No file in this change is involved.

**Targeted in-scope runs (all `exit 0`, re-executed in this pass)** — `tmp/verify_car_evidence/focused_runs.txt`:

| Group | Executed result |
|---|---|
| WU-1 core models/tables | `CaracteristicaModelTest` 8/49 · `CaracteristicaValorCatalogoTest` 3/18 · `CaracteristicaValorTriadTest` 5/13 · `CaracteristicaScopeTableTest` 3/28 · `InitCaracteristicasTablesTest` 6/30 |
| WU-2 resolver/store/scope | `CaracteristicaResolverTest` 10/18 · `CaracteristicaValorStoreTest` 4/18 · `CaracteristicaAssignmentScopeTest` 3/10 |
| WU-3 backfill/read-through | `CaracteristicaBackfillTest` 7/19 · `CaracteristicaReadThroughTest` 6/30 |
| WU-4/WU-5 clone + drop + gate | `CaracteristicaCloneTest` 7/39 · `TarifTarifasHeredarCaracteristicaTest` 5/43 · `CaracteristicaDefaultsTest` 6/35 · `CaracteristicaBoundariesTest` 6/132 · `CaracteristicaColumnDropTest` 25/145 · `CaracteristicaReversibilityTest` **10/126** |
| WU-6 list/panel/export/import | `VentasArticulosListCaracteristicasTest` 6/39 · `VentasCaracteristicasControllerTest` 11/59 · `ArticuloExcelCaracteristicaTest` **5/35** · `ArticuloExcelFeaturePersistenciaTest` 7/21 · `ArticuloExcelExportServiceTest` 2/4 · `ArticuloExcelImportWizardServiceTest` 7/13 |
| WU-8 + DEV-17/DEV-18 (tarifario) | `CaracteristicaDefaultsRegistrationTest` 6/28 · `TarifCatalogoVisibilityReadThroughTest` 9/28 · `TarifCatalogoJsonImportVisibilityTest` **10/37** · `TarifCatalogoMembershipFilterRatificationTest` 5/34 · `ExcelRowUpdaterFeatureVisibilityTest` 8/36 · `ExcelWizardCreatePathFeatureTest` 4/13 · `ExcelImportWizardFieldCatalogTest` 6/102 · `TarifCatalogoOpcionalMasterExportTest` 8/30 |
| CodeRabbit round-2 fix (tarifario atomicity) | `ExcelHierarchyServiceCreateFamiliaRowTest` **13/51** |

**Coverage**: ➖ Not available — no coverage driver (`php -m` reports neither xdebug nor pcov). Unchanged from prior verifications.

### Spec Compliance Matrix

Counts below are the **measured per-requirement scenario counts** from the current delta tree (fence-aware native heading count, `tmp/verify_car_inventory.php` + `tmp/verify_car_count_spec.php` → `tmp/verify_car_evidence/spec_counts.txt` / `inventory_rerun.txt` → **13 spec files, 48 requirements, 154 scenarios, 133 pointer lines**). Statuses: ✅ COMPLIANT (covering test exists and passed in an executed green run) · ⚠️ PARTIAL · ❌ UNTESTED.

Evidence chain method (stated so it is not mistaken for per-scenario runtime proof): every test class named below was confirmed to exist as a file **inside** one of the two plugin `tests/` trees, both of which are declared wholesale by their `phpunit.xml` (`<directory>tests</directory>`) and both of which executed green in this pass. The 31 classes touched by this change or by the review rounds were additionally re-executed individually and their exact counts are recorded above and in `focused_runs.txt`.

| # | Requirement (spec file) | Scen. | Result | Covering evidence (executed this pass) |
|---|---|---|---|---|
| 1 | ART-01 Canonical detail absorbs article edit | 3/3 | ✅ | `VentasArticuloArticleEditAbsorptionTest` |
| 2 | ART-02 Per-tarifa price/state + opcional editing | 2/2 | ✅ | `VentasArticuloArticleEditAbsorptionTest`, `TarifTabPreciosTest` |
| 3 | ALC-02 Per-tarifa columns | 4/4 | ✅ | `VentasArticulosListCaracteristicasTest` + locked `VentasArticulosListAbsorptionTest` / `ArticuloListaCanonicaOwnershipTest` (green unedited) |
| 4 | ATT-02 Article Tarifas tab endpoint | 4/4 | ✅ | `TarifTabPreciosTest`, `CatalogoArticuloHookOwnershipTest` |
| 5 | ATT-04 Canonical detail renders/persists per-tarifa state | 2/2 | ✅ | `TarifTabPreciosTest` |
| 6 | `articulos-excel-import-export` Export Excel | 3/3 | ✅ | `ArticuloExcelCaracteristicaTest` 5/5, `ArticuloExcelExportServiceTest` 2/2; `EXPORT_HEADERS` byte-identical |
| 7 | `articulos-excel-import-export` Import wizard 3 pasos | **2/3** | ⚠️ | Dropdown + auto-suggest covered (`ArticuloExcelCaracteristicaTest`); "Legacy workbook without feature columns imports unchanged" is asserted at the field-catalog/persist-seam level, not end-to-end — see WARNING 3 |
| 8 | `articulos-excel-import-export` Persistencia | 2/2 | ✅ | `ArticuloExcelFeaturePersistenciaTest` 7/7 (incl. the CodeRabbit round-3 `apply()`-driven rejected-value test) |
| 9 | CAR-01 Feature definition entity | 3/3 | ✅ | `CaracteristicaModelTest` 8/8 |
| 10 | CAR-02 Value type whitelist | 2/2 | ✅ | `CaracteristicaModelTest` |
| 11 | CAR-03 Predefined value catalog | 2/2 | ✅ | `CaracteristicaValorCatalogoTest` 3/3 |
| 12 | CAR-04 Custom value + triad invariant | 3/3 | ✅ | `CaracteristicaValorTriadTest` 5/5, `CaracteristicaResolverTest` |
| 13 | CAR-05 Scope tables keyed by tarifa/scope | 3/3 | ✅ | `CaracteristicaScopeTableTest` 3/3, `InitCaracteristicasTablesTest` 6/6; live: 5/5 feature tables exist (L1) |
| 14 | CAR-06 Effective-value precedence | 6/6 | ✅ | `CaracteristicaResolverTest` 10/10 |
| 15 | CAR-07 Reads never persist | 2/2 | ✅ | `CaracteristicaResolverTest`; live: D12 batch derivation issues no writes (L4) |
| 16 | CAR-08 Lazy `DEF` inheritance | 2/2 | ✅ | `CaracteristicaValorStoreTest` 4/4 |
| 17 | CAR-09 Assignment scopes | 3/3 | ✅ | `CaracteristicaAssignmentScopeTest` 3/3 |
| 18 | CAR-10 Clone on tarifa copy | 3/3 | ✅ | `CaracteristicaCloneTest` 7/7, `TarifTarifasHeredarCaracteristicaTest` 5/5 |
| 19 | CAR-11 Default feature registration | 5/5 | ✅ | `CaracteristicaDefaultsTest` 6/6, tarifario `CaracteristicaDefaultsRegistrationTest` 6/6; live: 3 definitions with `origen`, bool pairs, `registerDefaults()` no-op twice (L2) |
| 20 | CAR-12 D12 opcional visibility derivation | 5/5 | ✅ | `OpcionalVisibilityDerivationTest`, `OpcionalVisibilityParityTest`; live read-only derivation returns one entry per opcional |
| 21 | CAR-13 Supersession backfill | 4/4 | ✅ | `CaracteristicaBackfillTest` 7/7 |
| 22 | CAR-14 Read-through flag + dual-write soak | 3/3 | ✅ | `CaracteristicaReadThroughTest` 6/6, `TarifCatalogoVisibilityReadThroughTest` 9/9; live flag-OFF parity on `VARI@DEF` (both flags) (L3) |
| 23 | CAR-15 Gated reversible post-soak drop | **3/3** (was 2/3) | ✅ | Clause 1/2 gating, refusal, drop, idempotency, entry-point order and boot-freedom in `CaracteristicaColumnDropTest` 25/25; **"Reversibility from feature values" now covered by `CaracteristicaReversibilityTest` 10/10** and its spec pointer was corrected to that file. Fidelity caveat → WARNING 2 |
| 24 | CAR-16 `listable` columns in the product list | 4/4 | ✅ | `VentasArticulosListCaracteristicasTest` 6/6 |
| 25 | CAR-17 `importable`/`exportable` columns | **3/3** (was 2/2) | ✅ | `ArticuloExcelCaracteristicaTest` 5/5 + `ArticuloExcelFeaturePersistenciaTest`; the new third scenario ("A colliding codigo never shadows a base field") is the CodeRabbit round-1 F1 fix, RED-first and covered on all four surfaces |
| 26 | CAR-18 Feature management panel | 5/5 | ✅ | `VentasCaracteristicasControllerTest` 11/11 |
| 27 | CAR-19 Plugin-local boundaries | 2/2 | ✅ | `CaracteristicaBoundariesTest` 6/6 + independent shell gate (see Correctness) |
| 28 | `catalogo-render-hooks` Hook context contract | 3/3 | ✅ | `CaracteristicaHookContextTest`, `CatalogoCoreHookMarkersTest` (frozen four keys + `caracteristicas`) |
| 29 | `catalogo-render-hooks` Article hook tab renders derived values | 2/2 | ✅ | `CatalogoArticuloHookOwnershipTest` (`renders_resolver_driven_visibility`, `rendering_writes_nothing`) |
| 30 | `familias-tarifa-management` Toggle state management | 4/4 | ✅ | `TarifFamiliasToggleTest`, `TarifFamiliasFragmentTest` |
| 31 | OUM-03 Per-tarifa state and price display | 3/3 | ✅ | `VentasOpcionalesControllerMasterStateTest` |
| 32 | OUM-04 CSRF-guarded state toggles | 4/4 | ✅ | `VentasOpcionalesControllerMasterStateTest` |
| 33 | OUM-07 Excel export parity | 3/3 | ✅ | `VentasOpcionalesExportParityTest` |
| 34 | Opcional domain models owned by catalogo_core | 3/3 | ✅ | `OpcionalDomainModelOwnershipTest`, `TarifOpcionalExtMigrationTest` |
| 35 | Per-tarifa opcional master table | 2/2 | ✅ | `TarifTarifaOpcionalTest` |
| 36 | Master state precedence | 3/3 | ✅ | `TarifTarifaOpcionalPrecedenceTest` |
| 37 | Master lifecycle: seed, lazy inherit, copy | 3/3 | ✅ | `TarifTarifaOpcionalLifecycleTest`, `TarifTarifasHeredarOpcionalMasterTest` |
| 38 | Master consumption in UI and export | 3/3 | ✅ | `TarifOpcionalesControllerMasterStateTest`, `TarifCatalogoOpcionalMasterExportTest` 8/8 |
| 39 | Unchanged boundaries and non-destructive migration | 2/2 | ✅ | `CaracteristicaBoundariesTest`, `TarifOpcionalExtMigrationTest` |
| 40 | OTS-02 Scoped editable panel + overview | 3/3 | ✅ | `TarifOpcionalEditCaracteristicaTest` |
| 41 | OTS-05 CSRF-guarded per-tarifa save | 2/2 | ✅ | `TarifOpcionalEditCaracteristicaTest` |
| 42 | OTS-09 Test-locked contracts intact | 2/2 | ✅ | `TarifOpcionalEditCaracteristicaTest`, `TarifOpcionalesControllerContractTest` |
| 43 | tarifario Import Excel unificado (En SAP/En Tarifa/En Catálogo) | 3/3 | ✅ | `ExcelImportWizardServiceTest` 7/7, `ExcelRowUpdaterFeatureVisibilityTest` 8/8, `ExcelWizardCreatePathFeatureTest` 4/4, `TarifArticuloFactoryForImportTest` |
| 44 | tarifario Field catalog (R8) | 6/6 | ✅ | `ExcelImportWizardFieldCatalogTest` 6/102 — the `grupo` divergence is documented in its docblock |
| 45 | tarifario Create path on match-key miss (R6) | 7/7 | ✅ | `ExcelImportWizardServiceTest` + the canary; the CodeRabbit round-4 F2 fix (`$mapping` captured by the row-hook closure) and F4 fix (`getMappedValue()` resolves key **or** value) are both covered |
| 46 | tarifario Canary contract del create path | 1/1 | ✅ | `ExcelWizardCreatePathFeatureTest::testCanaryCreatePathWritesFeatureDefaults` |
| 47 | tarifario R-TAR-HOOK-002 Equivalent hook surface on ventas_opcional | 4/4 | ✅ | `OpcionalOwnedVisibilityRemovalTest`, `TarifConfiguradorOpcionalesTest`, `TarifCatalogoOpcionalMasterExportTest`, `VentasOpcionalesControllerMasterStateTest` |
| 48 | tarifario R-TAR-HOOK-011 Visibility consumers resolve feature values | 5/5 | ✅ | export set → `TarifCatalogoOpcionalMasterExportTest`; resolver reads → `TarifCatalogoVisibilityReadThroughTest`; ratified filters → `TarifCatalogoMembershipFilterRatificationTest` 5/5; JSON import mirror → `TarifCatalogoJsonImportVisibilityTest` 10/10; `en_sap` untouched → `ExcelRowUpdaterFeatureVisibilityTest` + `ExcelWizardCreatePathFeatureTest`. The restated requirement contains no untestable configurator scenario (DEV-16 closed) |

**Compliance summary**: **153/154 scenarios complete**; **47/48 requirements complete**. The single incomplete scenario is #7 "Legacy workbook without feature columns imports unchanged" (PARTIAL). The previously incomplete #23 "Reversibility from feature values" is **now complete**.

**Pointer audit** (`tmp/verify_car_spec_pointer_audit.php`, re-executed): 13 spec files, 48 requirements, 154 scenarios, **133** coverage pointer lines (was 132; the +1 is the corrected CAR-15 reversibility pointer), **21** scenarios without an explicit pointer. All **46** distinct `plugins/**/*.php` paths referenced by the delta specs **exist** (0 dangling; the stale report's 45 were a subset). The 21 pointer-less scenarios were re-inspected: 14 are the tarifario Excel spec whose scenario headings are literally test-method names (`testFieldCatalog_*`, `testCreatePath_*`) and are covered by the tarifario suite, and 7 are in `opcionales-management` / `articulo-lista-canonica` / `opcionales-tarifa-selector` whose requirement-level covering tests exist and pass. They are legacy scenario styles from earlier changes, not new gaps.

### Correctness (Static Evidence)

| Check | Status | Notes |
|---|---|---|
| CAR-19 — no core production file changed | ✅ | Root `git status --short` is **empty**; `openspec/changes/caracteristicas-producto/` does not exist in the root tree (`grep -c` → 0); `CaracteristicaBoundariesTest::test_no_entry_exists_in_the_repository_root_openspec` passes |
| CAR-19 — frozen 6-name baseline not grown | ✅ | **Independent re-implementation** of the scan (not the PHPUnit class) over 33 tarifario production files vs 123 catalogo_core production files, comment-stripped via `token_get_all`, 28 tarifario-owned names after the self-diff → `found(6) = tarif_grupo_rol, tarif_grupo_tarifa, tarif_grupo_usuario, tarif_historial_precios, tarif_precio_historial, tarif_tarifa_rol`, `matches_frozen_baseline=YES`, `new_names=` empty. Script + output retained (`tmp/verify_car_boundary_gate.php`, `boundary_gate_rerun.txt`) |
| CAR-19 — dependency direction + no new Composer requirement | ✅ | `catalogo_core/fsframework.ini` → `require = ""` (v1.6.0); `tarifario/fsframework.ini` → `require = "catalogo_core"` (v3.2.0); no plugin `vendor/` delta in this change |
| DEV-18 — JSON import mirrors the feature store | ✅ | Executed `TarifCatalogoJsonImportVisibilityTest` **10/10** (grew from 8 with the review rounds). Single-derivation source shape (`visibilidad_importada()` → legacy columns **and** `sincronizar_visibilidad_import()` → `assign_with_dual_write()`), failures counted in the additive `$stats['visibilidad_sin_sincronizar']`, per-operation `\Throwable` catch (CodeRabbit F5), `array_key_exists()` null semantics (F6) |
| DEV-17 — membership filters ratified + gated | ✅ | `MEMBERSHIP_FILTERS_LEGACY_BY_DESIGN` (11 literals) + `MEMBERSHIP_HELPER_CALL_SITES_LEGACY_BY_DESIGN` (5 call sites); executed `TarifCatalogoMembershipFilterRatificationTest` 5/5. The ordered closure prerequisite is recorded in the service docblock, `design.md` §8.4 and `tasks.md` 7.6 |
| DEV-16 — stale design rows closed | ✅ | `design.md` L477/L481/L636/L637/L1052-1053 state "no visibility read" for both `tarif_configurador_opcionales` and `tarif_articulo`; the WU-6 file list (L1181) names `Services/ExcelRowUpdater.php` and `Services/ArticuloListActionHandler.php` |
| WU-7 pre-soak — closed-loop entry point exists | ✅ | `CaracteristicaColumnDropMigration::runPostSoak(\fs_db2, bool): array` (L236), `POST_SOAK_STEPS` (L118: clause1 → clause2 → dead_opcional), `pendingVisibilityColumns()` (L300), `gatedTables()` (L319), `postSoakReport()` (L394). Operator runner `plugins/catalogo_core/tools/run_caracteristica_column_drop.php` |
| WU-7 pre-soak — reversibility test exists | ✅ | `plugins/catalogo_core/tests/CaracteristicaReversibilityTest.php`, **10 tests / 126 assertions**, green. Covers clause-1 reversal, clause-2 reversal, the retired `tarif_familia_ext` no-op, the shape-level dead-column reversal, the whole-snapshot closed loop, and the soak-gate asymmetry (`test_the_soak_gate_blocks_clause_2_and_the_dead_cleanup_but_not_clause_1`) |
| WU-7 pre-soak — two independent refusals, zero DDL | ✅ | `run_caracteristica_column_drop.php` (no flags, dry run) → `exit 0`, flag **OFF**, `gated columns still present: 12`, `DRY RUN — no DDL was emitted`. `--apply` without `--dev17-rewritten` → **REFUSED**, `exit 1`, no DDL. `runPostSoak()` carries no permissive default for the DEV-17 attestation |
| WU-7 — destructive drops never executed | ✅ | Live introspection (L5) + the runner agree: all 12 gated columns still present. No `ALTER … DROP COLUMN` has run |
| WU-7 — entry point never boot-wired | ✅ | `grep -rn 'dropLegacyArticleFamilyColumns\|dropDeadOpcionalColumns\|dropD12OpcionalColumns' plugins/ --include='*.php'` (excluding `tests/` and `openspec/`) returns **only** the service itself (declarations, docblocks, `POST_SOAK_STEPS`) plus one documentation comment in `tarif_catalogo_view.php`. No `Init::init()`/request/CLI wiring; enforced by `test_the_entry_point_is_never_wired_into_a_boot_or_request_path()` |
| Read-through flag defined nowhere | ✅ | `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` has no `define()` in `plugins/`, `src/`, `base/`, `controller/`, `model/` or `tests/`; the runner reports it OFF. Tests exercise the ON branch through the `setReadThrough(?bool)` seam |
| Only one `DROP COLUMN` exists in either plugin | ✅ | Exactly one site: `CaracteristicaColumnDropMigration.php:363`. `plugins/tarifario` contains **0** `DROP COLUMN` |
| Superseded change archived | ✅ | `plugins/tarifario/openspec/changes/archive/2026-09-16-mover-tarifa-catalogo-opcionales-a-tarifario/` with a `SUPERSEDED.md`; no longer in the active `changes/` tree. The active change references it only as "Supersedes" in `tasks.md` |
| Syntax gate on touched plugin files | ✅ | `php -l` clean on all 10 sampled changed files (drop migration, runner, both WU-7 tests, `catalogo_caracteristica`, `ArticuloExcelImportWizardService`, tarifario `ExcelHierarchyService`, `ExcelRowUpdater`, `tarif_catalogo_view`, `process_excel_wizard.php`) |
| Live schema not at clause parity | ⚠️ | Read-only introspection: `tarif_opcional_ext`/`tarif_tarifa_opcional` still carry the D12 flags; `tarif_articulo_precios`/`tarif_tarifa_articulo`/`tarif_tarifa_familia` still carry the clause-2 flags; `catalogo_opcionales` still carries the dead flags. Expected — the drops are operator-gated and were not executed (RESIDUAL 2) |

### Coherence (Design)

| Decision | Followed? | Notes |
|---|---|---|
| Pinned class/service names (§1) and WU-8/WU-7 additions | ✅ | `visibilidad_importada()`/`sincronizar_visibilidad_import()`, `dropDeadOpcionalColumns()`, `ordered_exportable()`, `runPostSoak()`/`POST_SOAK_STEPS`/`pendingVisibilityColumns()` all present at the pinned paths |
| Single derivation feeds legacy + feature (DEV-18 design) | ✅ | `visibilidad_importada()` is the one source for both sides; the source contract asserts the old inline `(bool) $fam_data['en_tarifa']` casts are gone |
| Clause 2 gated on `read_through()`, not boot | ✅ | `dropLegacyArticleFamilyColumns()` returns FALSE while the flag is off; unit-covered both ways; no boot wiring |
| Clause 1 is parity-gated, not soak-gated | ✅ | Matches CAR-15's literal text; pinned by `test_the_soak_gate_blocks_clause_2_and_the_dead_cleanup_but_not_clause_1` |
| DEV-17 guard is an operator attestation, not a machine check | ✅ accepted | `runPostSoak(..., bool $dev17MembershipFiltersRewritten)` has no default; the runner refuses `--apply` alone. Cannot be machine-verified from catalogo_core (CAR-19 forbids referencing tarifario's view) — recorded, not hidden |
| `tarif_familia_ext` introspection-guarded no-op (divergence 1) | ✅ | Live table has only `codfamilia`/`capitulo`/`nivel`; the drop test asserts no ALTER for it |
| `catalogo_opcional_precios.en_catalogo` stays (constraint 2/3) | ✅ | Present in the XML and explicitly excluded from the drop |
| Dead `catalogo_opcionales` flags handled separately (SUGGESTION 2) | ✅ | `DEAD_OPCIONAL_TABLES = ['catalogo_opcionales']`, gated + idempotent, outside `LEGACY_TABLES` |
| Export `orden` contract owned by the export layer (SUGGESTION 4) | ✅ | `ordered_exportable()` sorts by `orden` then `codigo`, applied at `buildSpreadsheet()` entry; `EXPORT_HEADERS` literal unchanged |
| `final`/untyped-class relaxation for DB-free doubles | ⚠️ accepted | Deliberate pattern (also used by `ArticuloExcelExportService::ordered_exportable()`); documented, breaks no spec |
| CodeRabbit rounds kept the pin (G1/G2, F1–F6, T1) | ✅ | G1 doc alignment, G2 memoized `valor_store()`, F1 collision guard, F2 `$mapping` closure capture, F3 `$stats`/`$row` error routing, F4 key-or-value mapping, F5 per-operation catch, F6 `array_key_exists()` — each verified against the current source, none relaxing a locked contract |

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

**B. Live checks harness** — `ddev exec php tmp/verify_car_live_harness.php` → **17/17 PASS, exit 0**, `sha256:43485c5763df6e54a3c48a0891f47bdcf1b97a83f3c92345adf353bde1d7e196` — **byte-identical** to the previously captured run (`live_harness_rerun.txt`), i.e. re-execution added no new state:
```text
L1 feature schema       5/5 tables exist                                    (CAR-05)
L2 CAR-11 defaults      medidas/en_catalogo/en_tarifa with origen + bool pairs;
                        registerDefaults() no-op (defs 3->3, values 4->4) and again  (CAR-11)
L3 CAR-14 live parity   VARI@DEF en_catalogo legacy=true  -> resolver=true
                        VARI@DEF en_tarifa   legacy=false -> resolver=false  3/3   (CAR-14)
L4 CAR-12 live read     derived {"2":null,"1":null} for tarifa DEF, 1 entry per opcional,
                        zero writes                                           2/2   (CAR-12)
L5 CAR-15 live schema   tarif_opcional_ext D12 columns still present: YES (2)
                        tarif_articulo_precios clause-2 columns still present: YES (2)
```
Write surface used by B: only `CaracteristicaRegistry::registerDefaults()` / `seedBoolPair()` (the plugin's own idempotent boot statements). Everything else is `SELECT`/`SHOW`.

**C. Residual — the WU-6 write-path live claim remains non-reproducible.** `tmp/verify_wu6_caracteristicas.php` is still absent (`ls` → "No such file or directory"), and the live DB still has 0 `tarif_articulo_precios`, 0 `tarif_tarifa_articulo`, 0 `catalogo_articulo_opcional` and 0 `catalogo_opcional_familias` rows, so no parity sample exists without synthetic seeding; and `fs_db2::exec()` auto-commits, so a synthetic write cannot be rolled back (the apply leaked rows once doing exactly that). The store write path stays verified only by the DB-free suites (which now include the CodeRabbit round-2 transactional `ExcelHierarchyServiceCreateFamiliaRowTest` 13/13). RESIDUAL 3.

### TODO / Drift Resolution (artifact defects)

1. **The reported `tasks.md` assertion-count drift (`147` / `3404`) does not exist in the current tree.** `grep -rn '3404'` over both plugin `openspec/` trees returns **0**; the active `tasks.md` already carries the measured `126` (reversibility) and `3405` (change-wide total) at lines 278/295/309, and the WU-7 pre-soak apply explicitly corrected the older `16/16 (+5)` defect to `25/25 (+6/+8)`. The premise was stale (it was recorded as an open defect *before* the fix landed); **no edit was needed for those two numbers**.
2. **A different count drift was present and is fixed**: `tasks.md` line 8 declared `48 requirements / 153 scenarios`; the measured native count is **48 / 154**. The +1 is the `CAR-17` collision scenario added by the CodeRabbit round-1 fix. One-line correction applied (the only `tasks.md` edit in this pass).
3. **Still stale (recorded, deliberately not rewritten)**: `tasks.md` line 309 states the plugin suite as `768 → 786 (+18)`, `3196 → 3405` — a true statement of the **WU-7 apply-time delta**, but not the current totals, which are **794 / 3450** after the review rounds. Rewriting an explicitly-framed historical delta would destroy the audit trail, so it is reported here instead (SUGGESTION 1).

### TDD Evidence Audit

| Check | Result | Details |
|---|---|---|
| TDD evidence reported | ⚠️ | Apply sections cite RED-first per work unit (e.g. WU-8.1 "5 errors + 3 failures before the change"; WU-8.4/8.5 "RED first"; CodeRabbit round 2 "12 tests, 47 assertions, 3 failures" before the transaction fix), but there is no per-task RED→GREEN transcript committed as a repo artifact |
| All implemented behaviours have tests | ✅ | Every WU-1..WU-6, WU-8 and WU-7-pre-soak behaviour maps to a named test in the matrix; 59/59 named covering classes exist inside the two executed suites |
| Executed GREEN | ✅ | catalogo_core 794/3450 and tarifario 256/1043, plus 31 targeted class runs, all `exit 0` |
| Deltas honoured | ✅ | The review rounds only **added** tests (`TarifCatalogoJsonImportVisibilityTest` 8→10, `ExcelHierarchyServiceCreateFamiliaRowTest` 12→13, `ArticuloExcelCaracteristicaTest` 4→5, `CaracteristicaReversibilityTest` new 10, `CaracteristicaColumnDropTest` 17→25); no locked assertion was weakened |
| Safety net | ✅ | The ALC-02/export locked set and the byte-level marker contracts are green, and each removal (opcional-owned flags) carries an explicit absence assertion |

### CodeRabbit Review Context (informational, not a verification finding)

| Plugin | Findings | Outcome |
|---|---|---|
| catalogo_core | 16 → 0 outstanding | Rounds 1–3: 12 fixed, 4 triaged as false positives/superseded (F2/F9/F10 were false positives — the delete path does carry the visibility controls, and no root `openspec/` entry ever existed; F3/H3 were "regenerate verify-report", owned by this phase and now executed). H1 (deletion must validate the **persisted** `origen`) and H2 (drive the real `apply()` workflow) are fixed and covered. This report closes the carried H3/F3 item |
| tarifario | 8 → 1 non-actionable | Rounds 4–5: 5 production + 1 test+production findings fixed; the round-2 atomicity finding (`createFamiliaRow` must be transactional) is fixed and verified with a real transaction + spy-DB evidence (13/13). The single remaining finding points at a spec file inside the **superseded** `mover-tarifa-catalogo-opcionales-a-tarifario` change, which was never applied and has since been **archived** (`2026-09-16-…`). It is non-actionable by construction: the tree is no longer a tracker |

### Issues Found

**CRITICAL**: **None.** In the delivered scope (WU-1..WU-6, WU-8, WU-7 pre-soak prep, and the review fixes) there is no spec violation and no broken locked contract: the locked ALC-02/export set passes unedited, the frozen byte-level marker contracts pass, the CAR-19 gate has not grown (independent scan = frozen 6), no core production file changed, both plugin suites `exit 0`, `phpstan` is clean, and the live read/parity harness is 17/17.

**WARNING**:

1. **WU-7's post-soak half is not closed — the change is not archive-ready (completeness blocker, not a quality failure).** `tasks.md` `7.5` and `7.6` are unchecked and `7.4` is `[~]`; the read-through flag has never been enabled anywhere; the destructive drops were not executed (the runner's own dry run reports 12 gated columns still present and emits no DDL). CAR-15 is **3/3 covered** but its *live* clause-2 execution has never happened.
2. **The CAR-15 "Reversibility from feature values" scenario is covered at the model/double level, not against live DDL.** `CaracteristicaReversibilityTest` drives the real drop service against a `\fs_db2` double that records the `ALTER` statements and re-derives through the real `CaracteristicaResolver` over in-memory feature state — the migration logic and the re-derivation path are genuinely executed, but no MySQL engine ever processes the drop. This is the same fidelity class as the rest of the change's DB-free testing and is accepted; it is recorded so that "reversibility proven" is not read as "reversibility proven on a real drop".
3. **`articulos-excel-import-export` "Legacy workbook without feature columns imports unchanged" is only partially proven** (PARTIAL). The "feature values are a no-op" half is asserted at the persist seam (`test_unmapped_or_empty_feature_column_is_a_no_op`) and the base-catalog half at the field-catalog level (`test_import_field_catalog_is_additive_and_byte_identical_base`), not end-to-end through an unmapped legacy workbook applying to articles. Unchanged from the previous verification.
4. **The JSON import mirror is not executed end-to-end.** Part of `TarifCatalogoJsonImportVisibilityTest` is region-scoped source contracts over `process_familias_batch()` / `process_articulos_batch()`; the rest is a real store-write → real resolver-read closed loop over in-memory scope models. That is a genuine behavioural proof of the mirror mechanism, but the batch methods themselves never run against a DB in any suite. Accepted as sufficient for DB-free coverage; recorded so it is not mistaken for end-to-end evidence.
5. **The review-round transactional path (`createFamiliaRow` with read-through ON) is double-only.** The CodeRabbit round-2 fix is verified with a spy DB — no real MySQL connection has exercised `BEGIN`/`COMMIT`/`ROLLBACK`. Consequence of the same DB-free constraint as WARNING 2/4; it will only be truly proven by the 7.5 soak.
6. **`persist_feature_values()` still has no production caller.** The CAR-17 dispatch row hook does not invoke it yet; the round-3 H2 test drives it through `apply()` with the CAR-17 per-row shape, so wiring the dispatch row hook to it remains the outstanding CAR-17 integration step (not a regression, but not delivered either).
7. **The DEV-17 closure prerequisite is an attestation, not a machine check.** `runPostSoak()` requires `$dev17MembershipFiltersRewritten = true` with no default and the runner refuses `--apply` alone, but nothing in code can prove the tarifario membership filters were actually rewritten before the drop. Recorded, not hidden.

**SUGGESTION**:

1. Refresh the two stale numbers in `tasks.md` line 309 (`786` → `794`, `3405` → `3450`) or annotate it as the WU-7 apply-time snapshot, so the next phase does not read it as the current total.
2. When WU-7's soak starts, enable `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` in staging and watch `visibilidad_sin_sincronizar` during a JSON import — that is the metric DEV-18 introduced and the natural soak signal.
3. Rewrite the DEV-17 membership filters to the feature tables **before** `dropLegacyArticleFamilyColumns()`; the ordering is recorded and attested but not machine-enforced.
4. Add an end-to-end legacy-workbook import assertion to close scenario #7, or restate the scenario to its mapping-level contract.
5. Wire the dispatch row hook to `persist_feature_values()` to complete the CAR-17 integration (WARNING 6).
6. Archive handoff: use the explicit paths `plugins/catalogo_core/openspec/` and `plugins/tarifario/openspec/` (the native dispatcher only sees the repository-root `openspec/`), merge `specs/catalogo-core/**` (+ `caracteristicas-producto`, MODIFIED the 9 listed) and `specs/tarifario/**` (MODIFIED 2), apply the archive-time Purpose rewrite for `opcionales-tarifa-management`, and re-confirm no root entry.

### Residuals (explicit)

| # | Residual | Severity | Evidence / impact |
|---|---|---|---|
| 1 | WU-7 post-soak: soak (7.5) + gated drop (7.6) | Blocker (completeness) | `tasks.md` 7.5/7.6; flag defined nowhere; runner dry run reports 12 gated columns and no DDL |
| 2 | Destructive drops never executed; live schema not at parity | WARNING | Live introspection: D12 flags, clause-2 flags and the dead `catalogo_opcionales` flags all still present. Code is gated + idempotent + boot-free |
| 3 | WU-6 write-path live checks not reproducible | WARNING | `tmp/verify_wu6_caracteristicas.php` absent; live DB has no legacy rows to sample; `fs_db2::exec()` auto-commits. Mitigated by the retained harnesses and the DB-free suites |
| 4 | Reversibility proven against a DB double, not live DDL | WARNING | `CaracteristicaReversibilityTest` 10/10 with a recording `\fs_db2` double |
| 5 | Import-mirror batch methods not executed against a DB | WARNING | Source contracts + in-memory closed loop only |
| 6 | Transactional `createFamiliaRow` path not exercised on real MySQL | WARNING | Spy-DB call-log only (`ExcelHierarchyServiceCreateFamiliaRowTest` 13/13) |
| 7 | `persist_feature_values()` has no production caller | WARNING | CAR-17 dispatch row hook not wired |
| 8 | DEV-17 prerequisite is an attestation | WARNING | No machine check possible from catalogo_core without breaking CAR-19 |
| 9 | #7 "Legacy workbook without feature columns imports unchanged" PARTIAL | WARNING | Mapping-level assertion only |
| 10 | `tasks.md` line 309 suite totals stale (786/3405 vs 794/3450) | SUGGESTION | True as a WU-7 delta, not as current totals |
| 11 | `phpstan` does not analyse `plugins/` | INFO | `phpstan.neon` paths are `src`+`tests`; the plugin suites are the plugin type gate, plus `php -l` on touched files |
| 12 | Root `Plugins` suite keeps 2 pre-existing failures | INFO | 1 process-isolation test-hygiene defect in an untouched file + 1 unrelated plugin's version assertion |

### WU-7 Statement (explicit)

**WU-7's post-soak half — `7.5` (the read-through soak) and `7.6` (the gated destructive drop) — is the ONLY remaining slice, and it is NOT closed.** Everything else in WU-7 has landed in this tree: `7.1b` (the CAR-15 reversibility test, `CaracteristicaReversibilityTest` 10/10, with its spec pointer corrected) and `7.3` (the closed-loop entry point `runPostSoak()` + `POST_SOAK_STEPS` + `pendingVisibilityColumns()` + the dry-run-by-default operator runner, never boot-wired) are `[x]`; `7.4` stays `[~]` only because the report update belongs to this phase. The read-through flag has never been enabled and no `DROP COLUMN` has ever run — 12 gated columns remain, confirmed by the runner and by live introspection. **Clause-2 code itself is not the gap**: `dropLegacyArticleFamilyColumns()` and `dropDeadOpcionalColumns()` are implemented, gated, idempotent, introspectively guarded and unit-covered (`CaracteristicaColumnDropTest` 25/25). WU-7's absence is reported as **incompleteness that gates archive**, not as a failure of the delivered work's quality.

### Verdict

**FAIL for the change as a whole — PASS WITH WARNINGS for the verified WU-1..WU-6 + WU-8 + WU-7-pre-soak slices.**

The change-level `fail` is a **completeness** result, not a quality result: the envelope's totals exceed the completed counts (`47/48` requirements, `153/154` scenarios) and `blockers: 1` names the single open slice (WU-7 soak + gated drop). 3 of 71 tasks are open/partial, all post-soak inside WU-7; a passing envelope would not be admissible while they are open.

Within the verified scope the delivered quality is high, and this refresh is strictly better evidenced than the report it replaces. Both soak blockers are genuinely closed (DEV-18 by a real store→resolver closed loop plus a single-derivation refactor of both batch methods; DEV-17 by a frozen, test-gated inventory with a recorded closure prerequisite); DEV-16 and the doc/spec pointer drift are fixed; SUGGESTION 2 and 4 are implemented; the WU-7 pre-soak slice is now real and independently reproduced (reversibility 10/10, entry point 8 new tests, runner dry run and both refusals re-executed with zero DDL); and the CodeRabbit rounds left no outstanding actionable finding. Both plugin suites are green (**794/3450** and **256/1043**), the root `Plugins` suite reproduces **exactly** the 2 documented pre-existing unrelated failures (both diagnosed to root cause in this report), `phpstan` is clean, the CAR-19 gate still matches the frozen 6-name baseline under an independent re-implementation, and the retained live harness re-runs **byte-identically** at 17/17. The asserted `tasks.md` count defect (`147`/`3404`) was checked and found **already resolved** in the tree; the genuine drift found instead (`153` → `154` scenarios) was corrected, and the one remaining stale suite total is recorded rather than silently rewritten.

Archive must wait for WU-7 `7.5` + `7.6`.
