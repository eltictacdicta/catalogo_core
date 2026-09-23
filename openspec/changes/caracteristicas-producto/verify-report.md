```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:d95245f0b75dc9d673745f88a8bfac3e25946df9f07b442d16b4a94e645137b8
verdict: fail
blockers: 1
critical_findings: 0
requirements: 47/48
scenarios: 153/154
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:89b1814f3bfc9f6b0e6b8f21b07fe03a59903b9c62ff50e39b8bbb52540e2302
build_command: ddev exec composer phpstan
build_exit_code: 1
build_output_hash: sha256:0075802ab4eed10c03cd032383a368e2eb34465573cda723db014a7ecc373ac1
```

## Verification Report

**Change**: `caracteristicas-producto`
**Version**: N/A (delta spec, no version field). Owner plugin `catalogo_core` at `1.6.3`, secondary `tarifario` at `3.2.1` — neither version was bumped by this change (bumps 1.6.1/1.6.2/1.6.3 and 3.2.1 come from unrelated commits, see `Committed state`).
**Mode**: Standard (both plugin configs declare `strict_tdd: true`, but no `strict-tdd-verify.md` runner is wired; the apply RED→GREEN record is audited in the TDD section)
**SDD root**: `plugins/catalogo_core/openspec/` (owner, `ownership: plugin-local`) + `plugins/tarifario/openspec/` (secondary). Core `openspec/` holds **no** entry for this change (`ls openspec/changes/` → 12 unrelated core changes; `openspec/changes/caracteristicas-producto` absent).
**Supersedes**: the previous `verify-report.md`, which is stale on its central finding: it reported WU-7's post-soak half as an open blocker. Since then the maintainer **split CAR-15's delivery across two releases**, clause 1 was **auto-wired and delivered** (`c4fa9ecd`, task 7.8), and the read path's **default was inverted** to the feature tables (`17b07cdb`, task 7.7). Every number below was re-derived and re-executed in this pass; the stale envelope (`47/48`, `153/154`, `794/3450`, `256/1043`, root `1476/5554`, `build_exit_code: 0`) was not carried over.
**Verified slices**: **WU-1, WU-2, WU-3, WU-4, WU-5, WU-6, WU-8**, **WU-7 7.1 / 7.1b / 7.2 / 7.3**, **7.7 (read-path default inversion)** and **7.8 (CAR-15 clause 1 auto-delivered at deploy)**.
**Staged (not defects)**: WU-7 **clause 2 — 7.5 (soak) and 7.6 (gated article/family drop) — is deferred to a later release by explicit maintainer decision**, recorded coherently in the amended CAR-15 delta, `design.md` §8.5 and `tasks.md`.

### Scope and Slice Boundaries

| Slice | Work units | Status in this report |
|---|---|---|
| PR-1 | WU-1 (foundation) + WU-2 (resolver/store/list/export) | Re-executed — plugin suite green |
| PR-2 | WU-3 (backfill, read-through flag, dual-write) | Re-executed — `CaracteristicaBackfillTest` + `CaracteristicaReadThroughTest` 6/6 |
| PR-3 | WU-4 (D12 derivation, flag/toggle removal, hook context) | Re-executed — drop + reversal classes green |
| PR-4 | WU-5 (clone step 12) + WU-6 (tarifario defaults + consumer rewrites) | Re-executed — tarifario suite 266/0 |
| PR-5 (pre-soak) | WU-8 (DEV-18/DEV-17/DEV-16, SUGGESTION 2/4) + WU-7 7.1b + 7.3 | Re-executed — reversibility 10/10, runner dry-run + refusals re-confirmed |
| PR-5 (clause 1) | WU-7 **7.7** (feature path default) + **7.8** (clause-1 auto-drop) | **Newly verified** — `CaracteristicaColumnDropTest` 31/31, `CaracteristicaValorStoreTest` 6/6; runner dry run emits no DDL |
| PR-6 (later release) | WU-7 **7.5** (soak) + **7.6** (clause-2 gated drop) | **DEFERRED — staged, not part of this release** |

**Committed state**: `catalogo_core` HEAD `c4fa9ecd` on `main` (the two refreshed work units `17b07cdb` + `c4fa9ecd` are the two newest commits, both 2026-09-23); `tarifario` HEAD `eb64517` on `master`, 5 commits ahead of `origin/master` — the Excel-fixture opt-out pin plus 4 commits of the sibling `gestion-idiomas-catalogo` change. Working trees: `tarifario` clean; `catalogo_core` has one untracked sibling artifact `openspec/changes/gestion-idiomas-catalogo/verify-report.md`; the repository root has an unrelated `M opencode.json` (tooling config, not core production code).

### Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 73 |
| Tasks complete | 70 |
| Tasks partial | 1 (`7.4`) |
| Tasks deferred (unchecked, staged) | 2 (`7.5`, `7.6`) |

Derived from `tasks.md` with `grep -c '^- \[x\]'` → **70**, `'^- \[ \]'` → **2**, `'^- \[~\]'` → **1** (73 total). The total grew from the previous envelope's 71 because tasks **7.7** and **7.8** were added and closed. Every task in WU-1..WU-6, WU-8, the CAR-19 gate (G1.1–G1.3), WU-7 `7.1`/`7.1b`/`7.2`/`7.3`, and the two new items `7.7`/`7.8` is `[x]`. `7.4` stays `[~]` only because the report update belongs to this phase. The two open items are the **staged** clause-2 tasks.

### Build & Tests Execution

All commands below were executed in this pass under `ddev` on PHP 8.3.33 / PHPUnit 11.5.56. Raw outputs are retained as byte-reproducible artifacts under `tmp/verify_car_evidence/` (gitignored scratch); `evidence_revision` is the SHA-256 of the sorted `basename:sha256` manifest of those `*.txt` artifacts (13 files).

**Build (type gate)**: ❌ **Failed (`exit 1`)** — and the failure is **out of this change's scope**.
```text
$ ddev exec composer phpstan
 ------ ---------------------------------------------------------------------------------------
  Line   tests/Core/PluginEnableAjaxSafetyTest.php
 ------ ---------------------------------------------------------------------------------------
  308    Method Tests\Core\AjaxGuardTestPluginManager::applyPluginSchemaUpdates() should return
         array{success: bool, changes: list<string>, errors: list<string>} but returns
         array{success: true, errors: array{}}.
         🪪  return.type
 ------ ---------------------------------------------------------------------------------------
 [ERROR] Found 1 error
exit 1
```
`build_output_hash: sha256:0075802ab4eed10c03cd032383a368e2eb34465573cda723db014a7ecc373ac1` (`tmp/verify_car_evidence/phpstan.txt`). The previous envelope recorded `build_exit_code: 0`; the regression is **not** caused by this change. `tests/Core/PluginEnableAjaxSafetyTest.php` is a **root/core** test **newly added** by core release commit `14a4c7b7` ("chore(release): bump core VERSION to 0.22.1", 2026-09-20), which is about FS_DEMO removal and the plugin-enable AJAX wizard; the contract it fails against was centralized earlier by `f157f74f` (2026-08-25). Neither work unit touches any root `tests/` file (see `git -C plugins/catalogo_core show --stat` for both commits). `phpstan.neon` declares `paths: [src, tests]`, so it never analysed `plugins/` in the first place: the plugin suites are the plugin type gate (plus `php -l`, see Correctness). **This is a pre-existing, out-of-scope core failure, not this change's regression** — but it makes the shared root gate red.

**Tests — catalogo_core plugin suite (owner, the config-declared runner)**: ✅ `exit 0`
```text
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
Tests: 919, Assertions: 4002, Warnings: 2, Skipped: 1.
OK, but there were issues!   exit 0
```
`test_output_hash: sha256:89b1814f3bfc9f6b0e6b8f21b07fe03a59903b9c62ff50e39b8bbb52540e2302` (`tmp/verify_car_evidence/cc_suite.txt`). Baseline **919 tests / 0 failures** confirmed. Warnings are down to **2** (from the 26 recorded in the previous envelope — the tarifario/plugin warning set was reduced by intervening commits); the 1 skip is pre-existing.

**Tests — tarifario plugin suite (secondary)**: ✅ `exit 0`
```text
$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
Tests: 266, Assertions: 1093, Skipped: 2.
OK, but some tests were skipped!   exit 0
```
`sha256:7010c9e53d5dd9941317a44409d3d67908f3778d2b3724642586427409f23c65` (`tmp/verify_car_evidence/tarif_suite.txt`). Baseline **266 tests / 0 failures** confirmed; the 2 skips are pre-existing conditional skips.

**Tests — root `Plugins` suite (cross-plugin gate)**: ❌ 7 failures / 2165 total, `exit 1` — **all 7 are `OidcProvider`; none is `catalogo_core` or `tarifario`**.
```text
$ ddev exec php vendor/bin/phpunit --testsuite Plugins
Tests: 2165, Assertions: 8605, Failures: 7, Warnings: 1, Skipped: 46.
1) ...OidcRegisterControllerMinimalClienteTest::testPublicRegistrationAfterFullClienteDeleteCreatesNewPendingRequest
2) ...OidcLegacySchemaParityTest::testProductionLikeLegacySchemaIsRepairedAndAuthCodeRoundTripWorks
3) ...OidcSchemaContractTest::testTimestampColumnsMatchXmlContractAfterMigration
4) ...migration011_cliente_gruposTest::testMigrationCreatesTableWhenMissing
5) ...migration011_cliente_gruposTest::testBackfillInsertsOnlyValidatedProfiles
6) ...migration011_cliente_gruposTest::testBackfillLeavesExistingMembershipsAlone
7) ...migration011_cliente_gruposTest::testBackfillBatchesAt500Rows
```
`sha256:aac9f8da8167a2fdf6b5a036cec4e67c05f06d06989e1b41d8b7bbcaf44d8fd0` (`tmp/verify_car_evidence/root_plugins.txt`). **The failure set is NOT byte-identical to the previous envelope's** — the previous 2 failures (`TarifTarifasGuardarTest::test_guardar_tarifa_normalizes_lowercase_coddivisa` and `LegacySupportTest::testPluginVersionIsBumpedTo120ForTheBaseJsMove`) are now **gone** (fixed by intervening commits), and 7 OidcProvider failures appear in their place. `grep -c 'CatalogoCore|catalogo_core'` over the output → **0**; no `TarifTarifasGuardar` / `LegacySupport` line remains. The OidcProvider failures are DB-migration assertions in a plugin this change never touches (the change modifies only `plugins/catalogo_core/**` and `plugins/tarifario/**`), so they are structurally independent of this change. Recorded as an out-of-scope residual, not as a this-change regression.

**Targeted in-scope runs (all `exit 0`, re-executed in this pass)** — `tmp/verify_car_evidence/focused_runs.txt`, `focused_drop_reversibility.txt`, `focused_car12.txt`:

| Run | Executed result |
|---|---|
| `--filter Caracteristica` (whole feature surface) | **155 tests / 975 assertions**, `exit 0` |
| `--filter CaracteristicaColumnDropTest` | **31 tests / 170 assertions**, `exit 0` |
| `--filter CaracteristicaReversibilityTest` | **10 tests / 125 assertions**, `exit 0` |
| `--filter CaracteristicaValorStoreTest` (default semantics) | **6 tests / 23 assertions**, `exit 0` |
| `--filter CaracteristicaReadThroughTest` (CAR-14) | **6 tests / 30 assertions**, `exit 0` |
| `--filter OpcionalVisibilityParityTest` (CAR-12 gate) | **4 tests / 15 assertions**, `exit 0` |
| `--filter OpcionalVisibilityDerivationTest` (CAR-12) | **12 tests / 19 assertions**, `exit 0` |
| runner dry run (no flags) | 12 gated columns, `FEATURE (default)`, **no DDL**, `exit 0` |
| live harness | **17/17 PASS**, `exit 0` |
| live introspection (read-only) | `exit 0` |

**Coverage**: ➖ Not available — no coverage driver (`php -m` reports neither xdebug nor pcov). Unchanged from prior verifications.

### Spec Compliance Matrix

Counts below are the **measured per-requirement scenario counts** from the current delta tree (fence-aware native heading count via `tmp/verify_car_inventory.php` → `tmp/verify_car_evidence/inventory_rerun.txt` → **13 spec files, 48 requirements, 154 scenarios, 133 pointer lines, 46/46 referenced `plugins/**/*.php` paths exist, 0 dangling**). Statuses: ✅ COMPLIANT (covering test exists and passed in an executed green run) · ⚠️ PARTIAL.

**Authoritative totals did NOT change from the previous envelope: 48 requirements / 154 scenarios.** The CAR-15 delta was amended **in place** to record the two-release staging, so no requirement and no scenario was added or removed; the completed counts are also unchanged (`47/48`, `153/154`). The apply-progress note that the amendment kept "48 requirements / **153** scenarios" is stale by one (see SUGGESTION 2).

| # | Requirement (spec file) | Scen. | Result | Covering evidence (executed this pass) |
|---|---|---|---|---|
| 1 | ART-01 Canonical detail absorbs article edit | 3/3 | ✅ | `VentasArticuloArticleEditAbsorptionTest` |
| 2 | ART-02 Per-tarifa price/state + opcional editing | 2/2 | ✅ | `VentasArticuloArticleEditAbsorptionTest`, `TarifTabPreciosTest` |
| 3 | ALC-02 Per-tarifa columns | 4/4 | ✅ | `VentasArticulosListCaracteristicasTest` + locked `VentasArticulosListAbsorptionTest` / `ArticuloListaCanonicaOwnershipTest` |
| 4 | ATT-02 Article Tarifas tab endpoint | 4/4 | ✅ | `TarifTabPreciosTest`, `CatalogoArticuloHookOwnershipTest` |
| 5 | ATT-04 Canonical detail renders/persists per-tarifa state | 2/2 | ✅ | `TarifTabPreciosTest` |
| 6 | `articulos-excel-import-export` Export Excel | 3/3 | ✅ | `ArticuloExcelCaracteristicaTest`, `ArticuloExcelExportServiceTest`; `EXPORT_HEADERS` byte-identical |
| 7 | `articulos-excel-import-export` Import wizard 3 pasos | **2/3** | ⚠️ **PARTIAL** | Dropdown + auto-suggest covered (`ArticuloExcelCaracteristicaTest`); "Legacy workbook without feature columns imports unchanged" is asserted at the field-catalog/persist-seam level, not end-to-end — see WARNING 6 |
| 8 | `articulos-excel-import-export` Persistencia | 2/2 | ✅ | `ArticuloExcelFeaturePersistenciaTest` |
| 9 | CAR-01 Feature definition entity | 3/3 | ✅ | `CaracteristicaModelTest` |
| 10 | CAR-02 Value type whitelist | 2/2 | ✅ | `CaracteristicaModelTest` |
| 11 | CAR-03 Predefined value catalog | 2/2 | ✅ | `CaracteristicaValorCatalogoTest` |
| 12 | CAR-04 Custom value + triad invariant | 3/3 | ✅ | `CaracteristicaValorTriadTest`, `CaracteristicaResolverTest` |
| 13 | CAR-05 Scope tables keyed by tarifa/scope | 3/3 | ✅ | `CaracteristicaScopeTableTest`, `InitCaracteristicasTablesTest`; live: 5/5 feature tables exist (L1) |
| 14 | CAR-06 Effective-value precedence | 6/6 | ✅ | `CaracteristicaResolverTest` |
| 15 | CAR-07 Reads never persist | 2/2 | ✅ | `CaracteristicaResolverTest`; live: D12 batch derivation issues no writes (L4) |
| 16 | CAR-08 Lazy `DEF` inheritance | 2/2 | ✅ | `CaracteristicaValorStoreTest` |
| 17 | CAR-09 Assignment scopes | 3/3 | ✅ | `CaracteristicaAssignmentScopeTest` |
| 18 | CAR-10 Clone on tarifa copy | 3/3 | ✅ | `CaracteristicaCloneTest`, `TarifTarifasHeredarCaracteristicaTest` |
| 19 | CAR-11 Default feature registration | 5/5 | ✅ | `CaracteristicaDefaultsTest`, tarifario `CaracteristicaDefaultsRegistrationTest`; live: 3 definitions, bool pairs, `registerDefaults()` no-op twice (L2) |
| 20 | CAR-12 D12 opcional visibility derivation | 5/5 | ✅ | `OpcionalVisibilityDerivationTest` **12/12**, `OpcionalVisibilityParityTest` **4/4** (the live clause-1 gate); live read-only derivation returns one entry per opcional (L4) |
| 21 | CAR-13 Supersession backfill | 4/4 | ✅ | `CaracteristicaBackfillTest` |
| 22 | CAR-14 Read-through flag + dual-write soak | 3/3 | ✅ (⚠️ spec-text drift) | `CaracteristicaReadThroughTest` 6/6, `CaracteristicaValorStoreTest` 6/6 (undefined/TRUE/FALSE default semantics), tarifario `TarifCatalogoVisibilityReadThroughTest`; live legacy↔resolver parity on the live familia row (L3). **The default is now the feature path**; the requirement body still says legacy is the default — WARNING 1 |
| 23 | CAR-15 Gated reversible post-soak column drop | 3/3 | ✅ (clause 1 delivered; clause 2 staged) | `CaracteristicaColumnDropTest` **31/31** (clause-1 opt-out refusal + default-runs + boot-entry drop + idempotency + `Init::upgrade()` wiring with `init()` excluded + clause-2 untouched); `CaracteristicaReversibilityTest` **10/10** |
| 24 | CAR-16 `listable` columns in the product list | 4/4 | ✅ | `VentasArticulosListCaracteristicasTest` |
| 25 | CAR-17 `importable`/`exportable` columns | 3/3 | ✅ | `ArticuloExcelCaracteristicaTest`, `ArticuloExcelFeaturePersistenciaTest` |
| 26 | CAR-18 Feature management panel | 5/5 | ✅ | `VentasCaracteristicasControllerTest` |
| 27 | CAR-19 Plugin-local boundaries | 2/2 | ✅ | `CaracteristicaBoundariesTest` + independent shell gate (see Correctness) |
| 28 | `catalogo-render-hooks` Hook context contract | 3/3 | ✅ | `CaracteristicaHookContextTest`, `CatalogoCoreHookMarkersTest` |
| 29 | `catalogo-render-hooks` Article hook tab renders derived values | 2/2 | ✅ | `CatalogoArticuloHookOwnershipTest` |
| 30 | `familias-tarifa-management` Toggle state management | 4/4 | ✅ | `TarifFamiliasToggleTest`, `TarifFamiliasFragmentTest` |
| 31 | OUM-03 Per-tarifa state and price display | 3/3 | ✅ | `VentasOpcionalesControllerMasterStateTest` |
| 32 | OUM-04 CSRF-guarded state toggles | 4/4 | ✅ | `VentasOpcionalesControllerMasterStateTest` |
| 33 | OUM-07 Excel export parity | 3/3 | ✅ | `VentasOpcionalesExportParityTest` |
| 34 | Opcional domain models owned by catalogo_core | 3/3 | ✅ | `OpcionalDomainModelOwnershipTest`, `TarifOpcionalExtMigrationTest` |
| 35 | Per-tarifa opcional master table | 2/2 | ✅ | `TarifTarifaOpcionalTest` |
| 36 | Master state precedence | 3/3 | ✅ | `TarifTarifaOpcionalPrecedenceTest` |
| 37 | Master lifecycle: seed, lazy inherit, copy | 3/3 | ✅ | `TarifTarifaOpcionalLifecycleTest`, `TarifTarifasHeredarOpcionalMasterTest` |
| 38 | Master consumption in UI and export | 3/3 | ✅ | `TarifOpcionalesControllerMasterStateTest`, `TarifCatalogoOpcionalMasterExportTest` |
| 39 | Unchanged boundaries and non-destructive migration | 2/2 | ✅ | `CaracteristicaBoundariesTest`, `TarifOpcionalExtMigrationTest` |
| 40 | OTS-02 Scoped editable panel + overview | 3/3 | ✅ | `TarifOpcionalEditCaracteristicaTest` |
| 41 | OTS-05 CSRF-guarded per-tarifa save | 2/2 | ✅ | `TarifOpcionalEditCaracteristicaTest` |
| 42 | OTS-09 Test-locked contracts intact | 2/2 | ✅ | `TarifOpcionalEditCaracteristicaTest`, `TarifOpcionalesControllerContractTest` |
| 43 | tarifario Import Excel unificado (En SAP/En Tarifa/En Catálogo) | 3/3 | ✅ | `ExcelImportWizardServiceTest`, `ExcelRowUpdaterFeatureVisibilityTest`, `ExcelWizardCreatePathFeatureTest` |
| 44 | tarifario Field catalog (R8) | 6/6 | ✅ | `ExcelImportWizardFieldCatalogTest` (the `grupo` divergence documented in its docblock) |
| 45 | tarifario Create path on match-key miss (R6) | 7/7 | ✅ | `ExcelImportWizardServiceTest` + canary |
| 46 | tarifario Canary contract del create path | 1/1 | ✅ | `ExcelWizardCreatePathFeatureTest::testCanaryCreatePathWritesFeatureDefaults` |
| 47 | tarifario R-TAR-HOOK-002 Equivalent hook surface | 4/4 | ✅ | `OpcionalOwnedVisibilityRemovalTest`, `TarifConfiguradorOpcionalesTest`, `TarifCatalogoOpcionalMasterExportTest`, `VentasOpcionalesControllerMasterStateTest` |
| 48 | tarifario R-TAR-HOOK-011 Visibility consumers resolve feature values | 5/5 | ✅ | `TarifCatalogoVisibilityReadThroughTest`, `TarifCatalogoMembershipFilterRatificationTest`, `TarifCatalogoJsonImportVisibilityTest`, `ExcelRowUpdaterFeatureVisibilityTest`, `ExcelWizardCreatePathFeatureTest` |

**Compliance summary**: **153/154 scenarios** and **47/48 requirements** complete. The single incomplete requirement/scenario pair is **#7 "Legacy workbook without feature columns imports unchanged"** (PARTIAL), unchanged from the previous verification. Every CAR-12/14/15 scenario has a covering test that executed green in this pass.

**Pointer audit** (`inventory_rerun.txt`): 13 spec files, 48 requirements, 154 scenarios, **133** coverage pointer lines, **21** scenarios without an explicit pointer (14 are the tarifario Excel spec whose scenario headings are literally test-method names, covered by the tarifario suite; 7 are legacy-style headings in `opcionales-management` / `articulo-lista-canonica` / `opcionales-tarifa-selector` whose requirement-level covering tests exist and pass). 46/46 referenced `plugins/**/*.php` paths exist (0 dangling).

### Correctness (Static Evidence)

| Check | Status | Notes |
|---|---|---|
| CAR-19 — no core production file changed | ✅ | `git status --short -- base src controller model` → **empty**; `openspec/changes/caracteristicas-producto` does **not** exist in the root tree; `CaracteristicaBoundariesTest::test_no_entry_exists_in_the_repository_root_openspec` passes |
| CAR-19 — frozen 6-name baseline not grown | ✅ | Independent re-implementation (`tmp/verify_car_boundary_gate.php`, `boundary_gate_rerun.txt`): 33 tarifario production files vs 123 catalogo_core production files, `found(6) = tarif_grupo_rol, tarif_grupo_tarifa, tarif_grupo_usuario, tarif_historial_precios, tarif_precio_historial, tarif_tarifa_rol`, `matches_frozen_baseline=YES`, `new_names=` empty |
| CAR-14 — read path default inverted, single source | ✅ | `Services/CaracteristicaConfig.php:41` `read_through()` returns `!legacy_read_explicitly_enabled()`; `:53-55` `legacy_read_explicitly_enabled()` = `defined(READ_THROUGH_FLAG) && constant(...) === false`. Undefined ⇒ feature path; explicit `TRUE` ⇒ feature path; explicit `FALSE` ⇒ legacy. `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` has **no `define()`** in `plugins/`, `src/`, `base/`, `controller/`, `model/` (only in tests, inside isolated processes) |
| CAR-15 clause 1 — delivered automatically, no console, no flag | ✅ | `CaracteristicaColumnDropMigration::migrateIfNeeded(\fs_db2): bool` (`:169`) delegates to `dropD12OpcionalColumns()` (`:146`), which drops `D12_TABLES = ['tarif_opcional_ext','tarif_tarifa_opcional']` (`:93`) and refuses (returns `false`, no DDL) while the opt-out is explicit (`:148`). Wired from `Init::upgrade()` (`Init.php:142`) only; `Init::init()` does **not** call it (grep-verified). Enforced by `test_upgrade_wires_the_clause_1_drop_and_init_does_not` |
| CAR-15 clause 2 — code present, soak-staged | ✅ code, ⏸ deferred | `dropLegacyArticleFamilyColumns()` (`:188`) over `LEGACY_TABLES` and `dropDeadOpcionalColumns()` (`:211`) over `DEAD_OPCIONAL_TABLES`; `runPostSoak(\fs_db2, bool)` (`:281`) keeps its atomic refusal and the attestation with no permissive default. **Not auto-wired and not run** |
| WU-7 — destructive drops never executed | ✅ | Live introspection + runner dry run agree: **all 12 gated columns still present**; no `ALTER … DROP COLUMN` has run in this environment |
| WU-7 — only one `DROP COLUMN` site in either plugin | ✅ | `plugins/catalogo_core/Services/CaracteristicaColumnDropMigration.php:408`; `plugins/tarifario` contains **0** |
| Syntax gate on touched plugin files | ✅ | `php -l` clean on the files changed by `17b07cdb`/`c4fa9ecd` (`CaracteristicaConfig`, `CaracteristicaColumnDropMigration`, `Init`, the runner, both WU-7 tests) |
| Superseded change archived | ✅ | `plugins/tarifario/openspec/changes/archive/2026-09-16-mover-tarifa-catalogo-opcionales-a-tarifario/` with a `SUPERSEDED.md`; no longer in the active `changes/` tree |
| Live schema not at clause parity | ⚠️ by design | Introspection: `tarif_opcional_ext`/`tarif_tarifa_opcional` still carry the D12 flags (clause 1 will drop them on the next version bump), and the clause-2/dead columns remain. Expected — the drop is deploy/operator-gated (RESIDUAL 2) |

### Coherence (Design)

| Decision | Followed? | Notes |
|---|---|---|
| **[DECISION — amended] Feature path is the default** (`design.md` §1 pinned table, §8.4/§8.5/§15.2; `specs/README.md` decision 5) | ✅ | `design.md:40` pins "feature path when undefined; only an explicit `FALSE` opts out to legacy"; `CaracteristicaConfig` implements exactly that |
| **[DECISION] Two-release delivery of CAR-15** (`design.md` §8.5 L738+) | ✅ | Clause 1 auto-wired through `migrateIfNeeded()` from `Init::upgrade()`; clause 2 explicitly staged; the CAR-15 delta carries the same staging note. Coherent |
| Clause 1 parity-gated, not soak-gated | ✅ | Matches CAR-15's literal text; `dropD12OpcionalColumns()` has no soak precondition; the CAR-12 parity test is the gate and is green (4/4) |
| Clause 2 gated on the explicit opt-out, not boot | ✅ | `dropLegacyArticleFamilyColumns()` returns `false` while `legacy_read_explicitly_enabled()`; unit-covered; no boot wiring |
| Pinned class/service names (§1) and WU-8/WU-7 additions | ✅ | `visibilidad_importada()`/`sincronizar_visibilidad_import()`, `dropDeadOpcionalColumns()`, `ordered_exportable()`, `runPostSoak()`/`POST_SOAK_STEPS`/`pendingVisibilityColumns()` all present at the pinned paths |
| DEV-17 guard is an operator attestation, not a machine check | ✅ accepted | `runPostSoak(..., bool $dev17MembershipFiltersRewritten)` has no default; the runner refuses `--apply` alone. Cannot be machine-verified from catalogo_core without breaking CAR-19 |
| DEV-18 — single derivation feeds legacy + feature | ✅ | `visibilidad_importada()` is the one source for both sides |
| `tarif_familia_ext` introspection-guarded no-op (divergence 1) | ✅ | Live table has only `codfamilia`/`capitulo`/`nivel`; the drop test asserts no ALTER for it |
| `catalogo_opcional_precios.en_catalogo` stays (constraint 2/3) | ✅ | Present in the XML and excluded from every drop |
| CodeRabbit rounds kept the pin (G1/G2, F1–F6, T1) | ✅ | Each verified against the current source; none relaxed a locked contract |

### Live-DB Evidence (re-run in this pass)

Both retained harnesses were re-executed; neither introduces new production behaviour outside the plugin's own idempotent boot statements.

**A. Read-only introspection** — `ddev exec php tmp/verify_car_live_introspect.php` → `exit 0` (`live_introspect_rerun.txt`):
```text
feature tables: catalogo_caracteristicas rows=3 | catalogo_caracteristica_valores rows=4
              | catalogo_caracteristica_global rows=0 | catalogo_caracteristica_familia rows=2
              | catalogo_caracteristica_articulo rows=2
definitions:   en_catalogo(tarifario,bool) en_tarifa(tarifario,bool) medidas(catalogo_core,string)
legacy columns still present: tarif_opcional_ext yes/yes · tarif_tarifa_opcional yes/yes
              tarif_articulo_precios yes/yes · tarif_tarifa_articulo yes/yes
              tarif_tarifa_familia yes/yes · tarif_familia_ext no/no · catalogo_opcionales yes/yes
data volume:   articulos=326 · tarif_tarifas=1 · tarif_articulo_precios=0
              tarif_tarifa_articulo=1 · tarif_tarifa_familia=1
              catalogo_opcionales=3 · catalogo_articulo_opcional=0 · catalogo_opcional_familias=0
```
The live data volume **drifted** since the previous envelope (`catalogo_caracteristica_articulo` 0→2, `tarif_tarifa_articulo` 0→1, `catalogo_opcionales` 2→3) through sibling-change activity, not through this verify pass; the write-path live claim remains non-reproducible (RESIDUAL 3).

**B. Live checks harness** — `ddev exec php tmp/verify_car_live_harness.php` → **17/17 PASS, `exit 0`**, `sha256:6400be6d6698a2e7e4e3a81928cef8ad58da787eb0134e8bfb11708cf1e2ac1a` (`live_harness_rerun.txt`):
```text
L1 feature schema       5/5 tables exist                                     (CAR-05)
L2 CAR-11 defaults      medidas/en_catalogo/en_tarifa with origen + bool pairs;
                        registerDefaults() no-op (defs 3->3, values 4->4), twice  (CAR-11)
L3 CAR-14 live parity   VARI@DEF en_catalogo legacy=true  -> resolver=true
                        VARI@DEF en_tarifa   legacy=false -> resolver=false    (CAR-14)
L4 CAR-12 live read     derived {"2":null,"5":null,"1":null} for tarifa DEF, 1 entry per opcional,
                        zero writes                                          (CAR-12)
L5 CAR-15 live schema   tarif_opcional_ext D12 columns still present: YES (2)
                        tarif_articulo_precios clause-2 columns still present: YES (2)
```
The only write surface is `CaracteristicaRegistry::registerDefaults()` / `seedBoolPair()` (the plugin's own `INSERT IGNORE` idempotent boot statements); everything else is `SELECT`/`SHOW`.

### Safety Assessment of the Auto-Wired Clause-1 Drop (explicit)

The clause-1 drop will emit a real `ALTER TABLE … DROP COLUMN en_catalogo/en_tarifa` on `tarif_opcional_ext` and `tarif_tarifa_opcional` **on the next plugin version bump**, because `PluginSchemaSynchronizer` runs `Init::upgrade()` on a version change. The plugin version was **not** bumped by this change, so the drop has **not** run.

**Proven (unit level + idempotency + refusal):**
- The drop engine is the pre-existing D12 engine, unit-covered 31/31: opt-out refusal changes no schema; drop of exactly the four D12 columns; idempotent second run (`hasColumn()` per column); failure short-circuits (`dropFlags` stops on a failed `ALTER`).
- The deploy-time entry point is wired only into `Init::upgrade()` and explicitly excluded from `Init::init()` (test-enforced).
- `migrateIfNeeded()` reuses the clause-1 refusal under the explicit legacy opt-out, so the safety property is identical on the auto-wired and operator paths.
- The read path defaults to the feature tables (`17b07cdb`), so an undefined constant after the drop still selects the surviving source.
- No DDL was executed: the runner dry run reports **12 gated columns still present** and emits no DDL; live introspection agrees.

**NOT proven (do not read as production-verified):**
- No `ALTER TABLE` has ever been processed by MySQL for this change. The proof is against `\fs_db2` doubles that record SQL strings; no live engine exercised the DDL or its rollback.
- The parity gate that justifies dropping is a **test-level** precondition (`OpcionalVisibilityParityTest`), not a runtime machine check: at deploy time the only runtime refusals are the explicit opt-out and `hasColumn()` idempotency. If the parity test were skipped in a release process, nothing in the code stops the drop.
- The drop's reversibility (re-add nullable + re-derive) is proven at the model/double level (`CaracteristicaReversibilityTest` 10/10), not on a real drop.
- No pre-drop dump was taken in this environment because nothing was dropped.

### TODO / Drift Resolution (artifact defects)

1. **`tasks.md` line 8** already carries the correct `48 requirements / 154 scenarios`; the previous envelope's corrected `+1` (CAR-17 collision scenario) is reflected. No edit needed.
2. **`apply-progress.md:145` still says "48 requirements / 153 scenarios unchanged"** — stale by one. Symptom of the same class as the previous drift; recorded as SUGGESTION 2 (deliberately not rewritten: it is the WU-7.8 apply-time note).
3. **`tasks.md:324` still frames the suite totals as the WU-7 apply-time delta** (`768 → 786`, `3196 → 3405`). True as a historical delta, not as current totals (`919 / 4002`). Recorded as SUGGESTION 1 rather than rewritten, to preserve the audit trail.
4. **The CAR-14 delta requirement body still annotates the legacy path as `(default)`** (see WARNING 1). This is the one genuinely new spec-text defect this refresh found.

### TDD Evidence Audit

| Check | Result | Details |
|---|---|---|
| TDD evidence reported | ⚠️ | Apply sections cite RED-first per work unit, including the two new ones (7.7: `--filter Caracteristica` → 2 errors + 15 failures before the inversion; 7.8: `--filter CaracteristicaColumnDropTest` → 3 errors + 2 failures before the wiring), but there is no per-task RED→GREEN transcript committed as a repo artifact |
| All implemented behaviours have tests | ✅ | Every WU-1..WU-6, WU-8, WU-7-pre-soak and the 7.7/7.8 behaviours map to a named covering test in the matrix |
| Executed GREEN | ✅ | catalogo_core 919/4002 and tarifario 266/1093, plus the targeted class runs, all `exit 0` |
| Deltas honoured | ✅ | 7.7/7.8 only **added** tests (`CaracteristicaColumnDropTest` 26→31, `CaracteristicaValorStoreTest` +default cases) and reconciled one reversibility test name; no locked assertion was weakened |
| Safety net | ✅ | The ALC-02/export locked set and the byte-level marker contracts are green, and `VentasArticulosListAbsorptionTest` explicitly pins the trait's read-path seam to legacy so its batch-map assertions stay meaningful |

### CodeRabbit Review Context (informational, not a verification finding)

| Plugin | Findings | Outcome |
|---|---|---|
| catalogo_core | 16 → 0 outstanding | Rounds 1–3: 12 fixed, 4 triaged as false positives/superseded; the carried H3/F3 "regenerate verify-report" item remains owned by this phase and is now executed |
| tarifario | 8 → 1 non-actionable | Rounds 4–5 fixed 5 production + 1 test/production finding; the single remaining finding points into the **superseded** `mover-tarifa-catalogo-opcionales-a-tarifario` change, which has been archived (`2026-09-16-…`) |

### Issues Found

**CRITICAL**: **None.** In the delivered scope (WU-1..WU-6, WU-8, WU-7 pre-soak, 7.7, 7.8, and the review fixes) there is no spec violation and no broken locked contract: the locked ALC-02/export set passes unedited, the frozen byte-level marker contracts pass, the CAR-19 gate has not grown (independent scan = frozen 6), no core production file changed, both plugin suites `exit 0`, and the live read/parity harness is 17/17. The two red gates below are **out of scope** for this change.

**WARNING**:

1. **CAR-14 delta still asserts the OLD default at the requirement level.** `specs/catalogo-core/caracteristicas-producto/spec.md:499-500` says consumers read "through the legacy columns when it is disabled **(default)**", while the implementation and the amended `design.md` §1/§8.4 + `specs/README.md` decision 5 make the **feature path** the default (legacy only under the explicit opt-out). The three CAR-14 scenarios are still covered, but the requirement body contradicts the code and, if merged at archive, would inject the wrong default into `plugins/catalogo_core/openspec/specs/`. Remedy: amend that sentence to "…and through the legacy columns only when the emergency opt-out is explicitly selected (`legacy_read_explicitly_enabled()`); an undefined constant selects the feature path."
2. **The shared root type gate is red (`composer phpstan` exit 1) on an out-of-scope core test.** `tests/Core/PluginEnableAjaxSafetyTest.php:308` — a test double's return shape drifted from the `applyPluginSchemaUpdates()` contract — a file added by core release `14a4c7b7` (2026-09-20), unrelated to this change. Remedy: fix the double in core (add `changes` to its return) or exclude it; this change is clean for its own scope (plugin suites + `php -l`).
3. **The root `Plugins` suite is red with 7 `OidcProvider` failures — and the failure set changed** from the previous envelope's 2 non-OidcProvider failures. None of the 7 involves `catalogo_core` or `tarifario` (grep 0). The previous 2 are fixed; the new 7 are OidcProvider DB-migration assertions. Recorded as an out-of-scope residual; a green root gate requires the OidcProvider migration environment to be fixed.
4. **Clause 1's parity gate is a test-level precondition, not a runtime check.** At deploy time `migrateIfNeeded()` only refuses on the explicit opt-out; nothing at runtime re-asserts CAR-12 parity. This is the documented design (the gate is the green `OpcionalVisibilityParityTest`), but it means the auto-drop is only as safe as the release process that runs that test. Recorded so "gated" is not read as "machine-guarded at deploy".
5. **Clause 2 is staged, coherently.** `tasks.md` 7.5/7.6 are marked `DEFERRED to a later release`; the CAR-15 delta, `design.md` §8.5 and `apply-progress.md` all record the same two-release split. This is an accepted maintainer decision, **not a defect** — but it is a release-planning fact archive must handle (see the WU-7 Statement).
6. **`articulos-excel-import-export` "Legacy workbook without feature columns imports unchanged" is only PARTIAL.** Unchanged from the previous verification (mapping-level assertion only).
7. **`persist_feature_values()` still has no production caller.** The CAR-17 dispatch row hook is not wired to it; the round-3 H2 test drives it through `apply()`. Outstanding integration, not a regression.
8. **The DEV-17 closure prerequisite is an attestation, not a machine check.** `runPostSoak()` requires `$dev17MembershipFiltersRewritten = true` with no default and the runner refuses `--apply` alone, but nothing in code can prove the tarifario membership filters were rewritten before the clause-2 drop.
9. **Reversibility and the transactional paths are proven against DB doubles, not live DDL/MySQL.** `CaracteristicaReversibilityTest` drives the real service against a recording `\fs_db2` double; `ExcelHierarchyServiceCreateFamiliaRowTest` uses a spy DB. Same fidelity class as the rest of the change's DB-free testing; recorded so "reversibility proven" is not read as "proven on a real drop".

**SUGGESTION**:

1. Refresh or annotate `tasks.md:324` (`768/786`, `3196/3405`) so it is not read as the current suite totals (`919 / 4002`).
2. Correct `apply-progress.md:145` (`153 scenarios` → `154`).
3. When clause 2's soak starts, enable the feature path against a staging copy and watch `visibilidad_sin_sincronizar` during a JSON import — the metric DEV-18 introduced and the natural soak signal.
4. Rewrite the DEV-17 membership filters to the feature tables **before** `dropLegacyArticleFamilyColumns()`; the ordering is recorded and attested but not machine-enforced.
5. Add an end-to-end legacy-workbook import assertion to close scenario #7, or restate the scenario to its mapping-level contract.
6. Wire the dispatch row hook to `persist_feature_values()` to complete the CAR-17 integration (WARNING 7).
7. **Archive mechanics for the chain**: the deferred clause-2 items must be relocated to a follow-up change (or explicitly waived) before archiving; use the explicit paths `plugins/catalogo_core/openspec/` and `plugins/tarifario/openspec/` (the native dispatcher only sees the repository-root `openspec/`); merge `specs/catalogo-core/**` (+ `caracteristicas-producto`, MODIFIED the listed set) and `specs/tarifario/**` (MODIFIED 2); re-confirm no root entry.

### Residuals (explicit)

| # | Residual | Severity | Evidence / impact |
|---|---|---|---|
| 1 | WU-7 clause 2 (7.5 soak + 7.6 drop) staged for a later release | Decision (release plan) | `tasks.md` 7.5/7.6 marked DEFERRED; CAR-15 delta + `design.md` §8.5 record the same split |
| 2 | Destructive drops never executed; live schema not at clause parity | WARNING | Introspection: D12 flags + clause-2 flags + dead `catalogo_opcionales` flags all still present |
| 3 | WU-6 write-path live checks not reproducible | WARNING | Live data drifted (feature-articulo rows 0→2) but no synthetic parity sample; `fs_db2::exec()` auto-commits |
| 4 | Reversibility proven against a DB double, not live DDL | WARNING | `CaracteristicaReversibilityTest` 10/10 with a recording double |
| 5 | Clause-1 deploy gate is test-level, not runtime | WARNING | `migrateIfNeeded()` refuses only on the explicit opt-out |
| 6 | Transactional `createFamiliaRow` path not exercised on real MySQL | WARNING | Spy-DB call-log only |
| 7 | `persist_feature_values()` has no production caller | WARNING | CAR-17 dispatch row hook not wired |
| 8 | DEV-17 prerequisite is an attestation | WARNING | No machine check possible from catalogo_core without breaking CAR-19 |
| 9 | #7 "Legacy workbook without feature columns imports unchanged" PARTIAL | WARNING | Mapping-level assertion only |
| 10 | CAR-14 delta body asserts the old default | WARNING | `specs/.../caracteristicas-producto/spec.md:499-500` |
| 11 | Shared root type gate red on an out-of-scope core test | Out-of-scope | `tests/Core/PluginEnableAjaxSafetyTest.php:308` (core release 0.22.1) |
| 12 | Root `Plugins` suite: 7 OidcProvider failures | Out-of-scope | No `catalogo_core`/`tarifario` failures |
| 13 | `phpstan` does not analyse `plugins/` | INFO | `phpstan.neon` paths are `src`+`tests` |
| 14 | `tasks.md:324` and `apply-progress.md:145` stale counts | SUGGESTION | Historical vs current totals; `153` vs `154` |

### WU-7 Statement (explicit)

**CAR-15 is delivered in one release and staged in the next.** Clause 1 (the D12 opcional flags — `tarif_opcional_ext`, `tarif_tarifa_opcional`) is **delivered now**: its engine (`dropD12OpcionalColumns()`) is parity-gated, idempotent via `hasColumn()`, refuses under the explicit legacy opt-out, and is auto-wired into `Init::upgrade()` through `migrateIfNeeded()` (task 7.8), so production needs no console and no flag. This also required the read path's default to become the feature tables (task 7.7), which is in place. Clause 1's gate (`OpcionalVisibilityParityTest` 4/4) is green, and the runner dry run confirms no DDL and 12 gated columns still present.

**Clause 2 (the legacy article/family columns — `tarif_articulo_precios`, `tarif_tarifa_articulo`, `tarif_tarifa_familia`, `tarif_familia_ext`, plus the dead `catalogo_opcionales` flags) is explicitly deferred to a later release by maintainer decision (tasks `7.5` soak, `7.6` gated drop).** The clause-2 code itself is implemented, gated, idempotent, introspectively guarded and unit-covered; its absence from this release is **not a quality failure and not a defect** — it needs a real soak window, which the spec amendment and `design.md` §8.5 record. The two deferred items remain tracked as unchecked tasks, so archive must relocate them to a follow-up change or record an explicit waiver.

### Verdict

**FAIL for the change as a whole — the delivered scope is QUALITY-GREEN, the envelope is INCOMPLETE and one out-of-scope core gate is RED.**

The change-level `fail` is **not** a quality regression and **not** a rejection of the clause-2 staging. It is the honest result of three facts:

1. **The envelope is incomplete.** `requirements: 47/48` and `scenarios: 153/154` because scenario #7 remains PARTIAL; one incomplete scenario is a real coverage gap, unchanged from the previous verification.
2. **The build command exits non-zero** (`composer phpstan` → 1) on an **out-of-scope core** test added by core release 0.22.1; a green shared gate cannot be claimed until core fixes `tests/Core/PluginEnableAjaxSafetyTest.php:308`.
3. **Archive is not yet permitted**, not because clause 2 is a defect but because its two deferred items (7.5, 7.6) remain unchecked tasks inside this change and must be relocated to a follow-up change or explicitly waived. The clause-2 deferral itself is a coherent, accepted maintainer decision, recorded in the spec, the design and the task list — this report records it as a **staging boundary, not a blocker**.

Within the delivered scope the quality is high and this refresh is strictly better evidenced than the report it replaces: CAR-14's semantics were corrected **and** independently re-checked (default = feature path; undefined/TRUE/FALSE all covered); CAR-15 clause 1 is delivered automatically with a test-enforced `upgrade()`-only wiring and a re-executed dry run that emits no DDL; CAR-12's parity gate — the live justification for clause 1 — is green (4/4 + 12/12); both plugin suites are green (**919/4002** and **266/1093**); the CAR-19 gate still matches the frozen 6-name baseline under an independent re-implementation; and the retained live harness re-runs at **17/17**. The genuine new defect found is the CAR-14 delta's stale `(default)` annotation (WARNING 1).

**Archive readiness: NOT archive-ready.** Blockers: (i) scenario #7 PARTIAL; (ii) the deferred clause-2 items must be relocated or waived; (iii) the shared root type gate is red on an out-of-scope core test. Once (i)–(iii) are cleared, the change may archive as the first link of the `caracteristicas-producto` → `articulo-detalle-tarifa-unificada` → `gestion-idiomas-catalogo` chain.
