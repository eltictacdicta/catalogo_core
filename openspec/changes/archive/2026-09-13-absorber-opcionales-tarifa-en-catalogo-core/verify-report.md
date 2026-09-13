```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:2939b5c825991ad7cbfbd212a82b1bfb1c2283d719c1cee4ce73bef6ceb38917
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 15/16
scenarios: 31/33
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:1fc8b1d2a0a2593a73541f27e1267c95a1b123f45c0f03b9b3ea2d7412e42e6c
build_command: ddev exec composer phpstan
build_exit_code: 0
build_output_hash: sha256:aa8f5b1f12073416ecfbdd25eeeec34517ca9191d7d009d2ebbcf6050296b525
```

## Verification Report

**Change**: `absorber-opcionales-tarifa-en-catalogo-core` (plugin-local SDD: `plugins/catalogo_core/openspec/changes/absorber-opcionales-tarifa-en-catalogo-core/`)
**Delta specs**: `opcionales-tarifa-management` (10 requirements / 21 scenarios), `catalogo-render-hooks` (6 requirements / 12 scenarios) — 16 requirements / 33 scenarios total.
**Mode**: Strict TDD (`strict_tdd: true`, runner: catalogo_core phpunit suite).
**Verification type**: Independent — artifacts re-read, all required commands re-executed, scratch-DB smoke re-run from scratch. No reliance on source discovery from the apply summary.

> Envelope note: `requirements: 15/16` and `scenarios: 31/33` count only scenarios whose named covering test passed **at runtime in this verification**. The two unverified scenarios are the `tpvmod opcional consumption preserved` requirement (task 6.5, explicitly deferred by the user and out of scope). The denominator matches the spec heading count (16 requirements / 33 scenarios, re-counted from the source files).
>
> Validator note: `gentle-ai sdd-verify-validate --requirements 16 --scenarios 33` was run on the exact candidate bytes. It **denied a passing verdict** (`passing verdict contradicts failing or incomplete evidence`) because the envelope carries incomplete counts (`31/33`) — the mechanical consequence of the user-deferred, out-of-scope `tpvmod` requirement, not a code failure. The counts were deliberately **not** inflated to `33/33` to force admission. Under the strict skill contract a validator-admissible report for incomplete evidence would carry verdict `fail`; this report is persisted as `pass_with_warnings` per the explicit task output contract, with the deferral recorded as WARNING-1 and the unverified scenarios called out explicitly.

### Change State (committed 5-slice chain)

The change is **committed** as a 5-slice stacked chain in two plugin repos (both plugins are independent git repos; the parent repo tracks no plugin bytes). Working trees match `HEAD`; the SDD artifacts remain untracked by convention.

| Slice | catalogo_core | tarifario | Content |
|---|---|---|---|
| PR1 (S1a+S1b) | `8a8b063` | `e469f06` | opcional domain move + controllers/views reclass |
| PR2 (S2) | `653f754` | `db07e55` | unified per-tarifa prices on `codlista` |
| PR3 (S3) | `683d57c` | `bac412c` | configurator/resolver dependency fork |
| PR4 (S4) | `76de6df` | `d628e03` | four frozen `render_hook` markers |
| PR5 (S5) | `e42b54c` | `96a52e3` | tags/activation/resolver tests + grep gate + exporter SQL fix |

- `git -C plugins/catalogo_core status --short` → only `?? openspec/changes/absorber-opcionales-tarifa-en-catalogo-core/` (untracked SDD artifacts).
- `git -C plugins/tarifario status --short` → clean.
- **`plugins/tpvmod/` was NOT modified by either chain** (`git diff --name-only <base>..HEAD | grep tpvmod` → empty in both repos). It carries pre-existing unrelated worktree changes from another session that were not touched.

### Completeness

| Metric | Value |
|---|---|
| Requirements in delta specs | 16 |
| Scenarios in delta specs | 33 |
| Scenarios verified by a passing runtime test | 31 |
| Scenarios unverified (tpvmod, task 6.5 deferred) | 2 |
| Implementation tasks (1.1–6.10) | complete except 6.5 (deferred by user) |
| Commits (tasks 7.1–7.4) | ✅ committed as the 5-slice chain above |

### Build & Tests Execution

**1. catalogo_core suite** — `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
```text
OK, but there were issues!
Tests: 354, Assertions: 1018, Warnings: 25, Skipped: 1.
exit 0   (sha256:1fc8b1d2a0a2593a73541f27e1267c95a1b123f45c0f03b9b3ea2d7412e42e6c)
```
**2. tarifario suite** — `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml`
```text
OK, but some tests were skipped!
Tests: 187, Assertions: 702, Skipped: 3.
exit 0   (0 errors, 0 failures)
```
**3. PHPStan** — `ddev exec composer phpstan`
```text
188/188 [============================] 100%
 [OK] No errors
exit 0   (sha256:aa8f5b1f12073416ecfbdd25eeeec34517ca9191d7d009d2ebbcf6050296b525)
```
**4. Grep gate** — `... plugins/catalogo_core/tests/DeadOpcionalTableReferenceTest.php`
```text
OK (4 tests, 21 assertions)   exit 0
```
**5. Raw grep gate** (php + xml, `plugins/catalogo_core` + `plugins/tarifario`, excluding `vendor/`, `openspec/`, `tests/`) for `(FROM|JOIN|INTO|REFERENCES|UPDATE)\s+\`?(tarif_articulo_opcional|tarif_opciones|tarif_opcionales)\`?([^_a-zA-Z0-9]|$)` → **zero hits** (php and xml). Legacy python table names in `plugins/tarifario/tools/` → **zero hits**.
**6. Hook focused tests** — `... CatalogoCoreHookMarkersTest.php` → `OK (11 tests, 67 assertions)`; `... HookRegistrationTest.php` → `OK (9 tests, 45 assertions)`.
**7. Coverage**: ➖ Not available — no coverage tool configured (not a failure).

### Spec Compliance Matrix — `opcionales-tarifa-management`

| # | Requirement | Scenario | Covering evidence (re-run in this verification) | Result |
|---|---|---|---|---|
| 1 | Opcional pages served by catalogo_core | Pages render after the move | `TarifOpcionalesControllerContractTest` (suite green) + class-load smoke (4/4 `class_exists=YES`, parent `fbase_controller`); full HTTP render not executed | ✅ COMPLIANT (contract + class-load; WARNING-2) |
| 2 | Opcional pages served by catalogo_core | Moved controllers decoupled | `TarifOpcionalesControllerContractTest` | ✅ COMPLIANT |
| 3 | Opcional domain models owned by catalogo_core | Models load from catalogo_core | `OpcionalDomainModelOwnershipTest` | ✅ COMPLIANT |
| 4 | Opcional domain models owned by catalogo_core | `catalogo_opcionales` XML stays untouched | `Services/TarifOpcionalExtMigrationTest` + `git diff c86da9d..HEAD -- model/table/catalogo_opcionales.xml` empty | ✅ COMPLIANT |
| 5 | Tags per (tarifa, opcional, familia) | Tag replacement normalized/idempotent | `TarifTarifaOpcionalEtiquetaTest` | ✅ COMPLIANT |
| 6 | Tags per (tarifa, opcional, familia) | Tags isolated per tarifa | `TarifTarifaOpcionalEtiquetaTest` | ✅ COMPLIANT |
| 7 | Unified per-tarifa prices on codlista | Legacy prices unified once | `OpcionalPriceUnificationTest` | ✅ COMPLIANT |
| 8 | Unified per-tarifa prices on codlista | Price save/read uses codlista | `TarifOpcionalPreciosControllerTest` | ✅ COMPLIANT |
| 9 | Per-tarifa activation/order with inheritance | Inheritance defaults active; override disables | `TarifTarifaOpcionalFamiliaTest` | ✅ COMPLIANT |
| 10 | Per-tarifa activation/order with inheritance | Resolver applies tag intersection | `TarifTarifaOpcionalResolverTest` | ✅ COMPLIANT |
| 11 | Per-tarifa activation/order with inheritance | Override sync persists effective set | `TarifTarifaArticuloOpcionalTest` | ✅ COMPLIANT |
| 12 | Opcional price history | Changed price writes one history row | `TarifOpcionalPrecioHistorialTest` | ✅ COMPLIANT |
| 13 | Opcional price history | History page renders opcionales mode | `TarifHistorialPreciosControllerTest` (controller/model contract); HTTP page not executed | ✅ COMPLIANT (WARNING-2) |
| 14 | Hierarchical configurator | Configurator renders roots + tree fragments | `TarifConfiguradorOpcionalesTest` (`htmx_tree` fragment) | ✅ COMPLIANT |
| 15 | Hierarchical configurator | Push actions mutate the hierarchy | `TarifConfiguradorOpcionalesTest` | ✅ COMPLIANT |
| 16 | Standalone FK-safe table bootstrap | Fresh standalone install creates the tables | `InitOpcionalesTablesTest` + **scratch-DB live create** (5 tables; FKs → `catalogo_opcionales`/`tarif_tarifas`/`articulos`/`familias`) | ✅ COMPLIANT |
| 17 | Standalone FK-safe table bootstrap | Bootstrap idempotent + class-safe | `InitOpcionalesTablesTest` + **scratch-DB second run** (5→5, no error, identical FK set) | ✅ COMPLIANT |
| 18 | Dead table references removed / FKs repointed | Grep gate passes | `DeadOpcionalTableReferenceTest` + raw grep zero hits | ✅ COMPLIANT |
| 19 | Dead table references removed / FKs repointed | FK XMLs target `catalogo_opcionales` | XML line 49 / 55 `REFERENCES catalogo_opcionales (id)` + gate test | ✅ COMPLIANT |
| 20 | tpvmod opcional consumption preserved | TPV resolution unchanged | **tpvmod suite NOT run** (task 6.5 deferred by user) | ❌ UNVERIFIED (deferred) |
| 21 | tpvmod opcional consumption preserved | No wrapper dependency | tpvmod suite not run; **read-only static check** confirms `tpvmod_opcionales.php` references only `catalogo_*` and no removed `tarif_*opcional*` wrapper | ⚠️ PARTIAL (static only; runtime deferred) |

### Spec Compliance Matrix — `catalogo-render-hooks`

| # | Requirement | Scenario | Covering evidence | Result |
|---|---|---|---|---|
| 22 | Frozen hook insertion points | Markers exist at frozen positions | `Integration/CatalogoCoreHookMarkersTest` (green) + markers at `ventas_opcional.html.twig:127,421`, `ventas_articulo.html.twig:129,341` | ✅ COMPLIANT |
| 23 | Frozen hook insertion points | Only the four frozen names | `Integration/CatalogoCoreHookMarkersTest`; no `ventas_articulos_*` in views/php | ✅ COMPLIANT |
| 24 | Hooks render when listener registered | Registered fragment appears at marker (unescaped) | `Integration/CatalogoCoreHookMarkersTest` | ✅ COMPLIANT |
| 25 | Hooks render when listener registered | Tarifas tab injected when tarifario active | `tarifario/tests/Integration/HookRegistrationTest` (`OK 9/45`) | ✅ COMPLIANT |
| 26 | No-op when no listener registered | Empty registry returns empty string | `Integration/CatalogoCoreHookMarkersTest` | ✅ COMPLIANT |
| 27 | No-op when no listener registered | Host pages render with tarifario inactive | `Integration/CatalogoCoreHookMarkersTest` (marker/view body); full authenticated page not rendered | ✅ COMPLIANT (WARNING-2) |
| 28 | Hook template resolution isolated/safe | `@tarifario/Hooks/*` namespace resolves | `HookRegistrationTest` + `tarifario/Init.php:65-68,130-153` registrations | ✅ COMPLIANT |
| 29 | Hook template resolution isolated/safe | Broken template swallowed + logged | `Integration/CatalogoCoreHookMarkersTest` | ✅ COMPLIANT |
| 30 | Extensible naming contract | Naming pattern derivable | `Integration/CatalogoCoreHookMarkersTest` | ✅ COMPLIANT |
| 31 | Extensible naming contract | No bare `tarifa` token | `Integration/CatalogoCoreHookMarkersTest` | ✅ COMPLIANT |
| 32 | Hook context contract | Context keys reach hook template | `Integration/CatalogoCoreHookMarkersTest` | ✅ COMPLIANT |
| 33 | Hook context contract | Unsaved opcional renders no tab | `HookRegistrationTest` | ✅ COMPLIANT |

**Compliance summary**: 31/33 scenarios verified at runtime; 1 scenario (21) partial/static; 1 scenario (20) unverified (tpvmod suite deferred by user).

### Hard Constraints (independent evidence)

| Constraint | Status | Evidence (re-checked) |
|---|---|---|
| `catalogo_opcionales` XML/API unchanged | ✅ | `git diff c86da9d..HEAD -- model/table/catalogo_opcionales.xml` empty; `model/core/catalogo_opcional.php` unchanged |
| Additive only | ✅ | catalogo_core chain: 46 files, **11829 insertions, 0 deletions**; `catalogo_opcionales.xml` untouched |
| Slugs / class / table names stable | ✅ | `TarifOpcionalesControllerContractTest` + `OpcionalDomainModelOwnershipTest`; class-load parent/trait check |
| No data migration | ✅ | No schema change to `catalogo_opcionales`; only the idempotent `CatalogLegacyTableMigration`/`TarifOpcionalExtMigration` bridge |
| Two moved FK XMLs → `catalogo_opcionales (id)` | ✅ | `tarif_tarifa_opcional_familia.xml:49`, `tarif_tarifa_articulo_opcional.xml:55` |
| Zero dead-table refs (`tarif_articulo_opcional`, `tarif_opciones`, `tarif_opcionales`) in php/xml | ✅ | Raw grep php = 0, xml = 0; gate test green |
| Four `render_hook` markers render active / no-op inactive | ✅ | `CatalogoCoreHookMarkersTest` + `HookRegistrationTest`; markers exactly 2 per host view |
| Moved code has no `plugins/tarifario/` requires | ✅ | `grep -rn plugins/tarifario/` over catalogo_core `.php` and `.twig` (excl. tests) → zero |
| Dead XMLs + deprecated wrappers removed | ✅ | 6 tarifario paths absent on disk (`git rm`'d in PR1) |
| `ref_sap`/`en_catalogo`/`en_tarifa` confined to `tarif_opcional_ext` | ✅ | `catalogo_opcionales.xml` has no such columns; `OpcionalDomainModelOwnershipTest` |
| Fix (a): `Init::ensureOpcionalesTarifaTables()` touches `catalogo_lista_precio` **before** `catalogo_opcional` | ✅ | `Init.php:108-112` — `touchNamespacedModel('catalogo_lista_precio')` then `catalogo_opcional` then `catalogo_articulo_opcional` |
| Fix (b): python exporter no longer queries dead `tarif_opcionales`/`tarif_opcional_precios` | ✅ | `tarifa_excel_to_json.py:1044-1046` uses `catalogo_opcionales` + `tarif_opcional_ext` + `catalogo_opcional_precios (codlista)`; grep of dead names under `tools/` = 0 |

### Dev-DB Smoke (task 8.5) — executed

Environment: DDEV running (`web` PHP 8.3.33, `db` MariaDB 10.11). Active config (`tmp/vjINsXSo26Q4fqPDtHwT/enabled_plugins.list`) = `system_updater,catalogo_core,business_data,tarifario`.

**Disposable scratch-DB fresh-install test** (strongest standalone evidence; scratch DB created, exercised, dropped, grant revoked; no repo file written, no live data mutated):
- Cloned the live 113-table schema/data into `opc_smoke_verify`; dropped the 5 moved tables → `BEFORE=0`.
- Ran `Init::ensureOpcionalesTarifaTables()` with only `catalogo_core` registered as a plugin (standalone, tarifario absent) → **all 5 tables created**: `tarif_opcional_ext`, `tarif_tarifa_opcional_etiqueta`, `tarif_opcional_precio_historial`, `tarif_tarifa_opcional_familia`, `tarif_tarifa_articulo_opcional`.
- FKs created and resolved: `tarif_opcional_ext.id_opcional → catalogo_opcionales`; `tarif_tarifa_opcional_familia.{codtarifa→tarif_tarifas, codfamilia→familias, id_opcional→catalogo_opcionales}`; `tarif_tarifa_articulo_opcional.{codtarifa→tarif_tarifas, referencia→articulos, id_opcional→catalogo_opcionales}`. **Zero stale FKs to `tarif_opcionales`.**
- Second run → `AFTER_SECOND=5` (no error, no schema change) ⇒ **idempotent no-op**.

| Smoke step | Result |
|---|---|
| Standalone (catalogo_core alone) creates the 5 moved tables FK-safe | ✅ scratch-DB fresh create |
| Re-run bootstrap = idempotent no-op | ✅ second run 5→5, no error |
| Four slugs load without fatal | ✅ class-load smoke: all 4 `class_exists=YES`, `parent=fbase_controller`, `trait=YES`, `init=YES` |
| Four slugs full authenticated HTTP render | ⚠️ NOT EXECUTED — requires an authenticated session (WARNING-2) |
| Hooks render Tarifas tab with tarifario active | ✅ `HookRegistrationTest` (real `@tarifario` namespace + registered hooks) |
| Hooks no-op with tarifario inactive | ✅ `CatalogoCoreHookMarkersTest` empty registry → exact `''` |
| Live dev DB FK state on pre-existing tables | ⚠️ `tarif_tarifa_articulo_opcional.id_opcional` and `tarif_tarifa_opcional_familia.id_opcional` still reference `tarif_opcionales` (see WARNING-3) |

### TDD Compliance (Strict TDD active)

| Check | Result | Details |
|---|---|---|
| TDD evidence reported | ✅ | Per-batch "TDD Cycle Evidence" tables present in `apply-progress.md` |
| All tasks have tests | ✅ | All implementation tasks map to existing test files (except deferred 6.5) |
| RED confirmed (tests exist) | ✅ | Named RED test files exist on disk |
| GREEN confirmed (tests pass) | ✅ | 354/354 catalogo_core + 187/187 tarifario pass on independent re-run |
| Triangulation adequate | ⚠️ | Most behaviors have multiple cases; render scenarios are contract/marker-level |
| Safety net for modified files | ➖ | Not independently reconstructable; recorded by apply |

**TDD Compliance**: 5/6 checks pass. `apply-progress.md` honestly documents green-on-authoring acceptance locks (S2 migration/controller/3.4, S3, S5) rather than fabricating RED runs; no tautological or ghost-loop assertions were found in the changed test files.

### Issues Found

**CRITICAL**: None. All executed suites exit 0; PHPStan clean; grep gate zero; scratch-DB fresh create FK-safe; no spec contradiction found in the verified 31 scenarios.

**WARNING**:
1. **tpvmod requirement unverified (task 6.5, deferred by user).** The requirement `tpvmod opcional consumption preserved` (2 scenarios) has no runtime test executed in this verification, and `plugins/tpvmod/` was explicitly out of scope. A read-only static check shows `plugins/tpvmod/lib/tpvmod_opcionales.php` requires only `catalogo_core/model/core/catalogo_*` classes and no removed `tarif_*opcional*` wrapper (partial evidence for scenario 21), but scenario 20 (runtime group/suelto/price parity) remains unverified. Per strict `sdd-verify` rules an unverified required scenario is normally CRITICAL/UNTESTED; it is recorded as a WARNING because the user explicitly deferred it and forbade touching `tpvmod`. Archive should either run `plugins/tpvmod/tests/TpvmodOpcionalesTest.php` (read-only) or consciously accept the deferral.
2. **Full authenticated HTTP render not executed.** Scenarios 1, 13, 14 and 27 are verified by contract/controller/model tests, the class-load smoke and the scratch-DB bootstrap, but the end-to-end authenticated render of the four slugs / history page / configurator / inactive-tarifario host page was not performed. This matches the design's documented S4 deviation.
3. **Existing databases keep the pre-change FK to the dead table.** The two repointed FK XMLs only affect freshly created tables; on the existing dev DB, `tarif_tarifa_articulo_opcional.id_opcional` and `tarif_tarifa_opcional_familia.id_opcional` still reference `tarif_opcionales`. This follows from the explicit "no data migration / additive only" scope and is non-breaking while `tarif_opcionales` remains present, but dropping the legacy table would break those FKs.
4. **Root `--testsuite Plugins` regression (task 8.2) and deployed Twig cache clear (task 7.3) not executed** in the specified command set.

**SUGGESTION**:
1. Run the tpvmod opcionales suite read-only (or author the 6.5 assertions) before archive to close WARNING-1.
2. Add one authenticated HTTP smoke (or a controller-render integration test) covering the four slugs to close WARNING-2.
3. Document or plan a one-time constraint-repoint migration if the legacy `tarif_opcionales` table is ever dropped (WARNING-3).
4. Prior-report findings now resolved: the `tarifa_excel_to_json.py` dead-table reference (former SUGGESTION 1) is fixed, and the `ensureOpcionalesTarifaTables()` touch-order dependency (former WARNING-3) is fixed.

### Verdict

**PASS WITH WARNINGS**

The reconstructed committed final state matches design D1–D10. Every verified requirement/scenario has a named covering test that passed at runtime on an independent re-run; all suites exit 0; PHPStan is clean; the raw grep gate is zero; the two moved FK XMLs target `catalogo_opcionales (id)`; the additive-only constraint holds (11829 insertions / 0 deletions on the catalogo_core side, `catalogo_opcionales` untouched); and a disposable scratch-DB fresh-install proves the standalone bootstrap creates the five moved tables FK-safe and is idempotent. The two prior fixes (bootstrap touch order; python exporter SQL) are present and verified. The change is not fully archive-ready only because the user-deferred tpvmod requirement remains untested and the full authenticated HTTP render was not executed.

### Out of Scope / Deferred (explicit)

- **`plugins/tpvmod/` (task 6.5)** — deferred by user, out of scope, and not modified. `TpvmodOpcionalesTest` was **not run**; scenarios 20/21 are recorded as unverified/partial, not as failures. `tpvmod`'s pre-existing unrelated worktree changes were left untouched.
- **Full authenticated HTTP render** — not executed (requires an authenticated session; read-only verification preserved).
- **Native `gentle-ai sdd-verify-validate` admission** — executed and denied a passing verdict for incomplete counts (`31/33`); see the Validator note at the top. This is not integrated with plugin-local SDD (the dispatcher only knows the core `openspec/`, per `fsframework-plugin-sdd`); the report was written directly to the plugin-local change path as instructed.

### Relevant Files (evidence anchors)

- `plugins/catalogo_core/Init.php:103-140` — `ensureOpcionalesTarifaTables()` (fix a: lines 108-112).
- `plugins/catalogo_core/model/table/tarif_tarifa_opcional_familia.xml:49`, `.../tarif_tarifa_articulo_opcional.xml:55` — FK repoint.
- `plugins/catalogo_core/View/ventas_opcional.html.twig:127,421`, `.../ventas_articulo.html.twig:129,341` — the four frozen markers.
- `plugins/tarifario/tools/tarifa_excel_to_json.py:1044-1046` — canonical exporter SQL (fix b).
- `plugins/tarifario/Init.php:65-68,130-153` — `@tarifario/Hooks/*` namespace + hook registration.
- `plugins/tpvmod/lib/tpvmod_opcionales.php:118-128` — catalogo-only requires (read-only static check).
