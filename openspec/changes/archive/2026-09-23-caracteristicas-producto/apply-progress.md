# Apply Progress — caracteristicas-producto

- **Change**: `caracteristicas-producto`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`)
- **Secondary plugin**: `plugins/tarifario`
- **Artifact store**: `openspec` (this file). Prior apply rounds (CodeRabbit rounds 1–5 and the
  WU-7 pre-soak prep) are recorded in the Engram ledger
  `sdd/caracteristicas-producto/apply-progress` (observation #817) — this file is the
  openspec-store ledger and must be merged, never overwritten.
- **Core `openspec/`**: untouched. No repository-root entry for this change.

## Cumulative task envelope

- Done: WU-1 … WU-6, WU-8, plus WU-7 7.1/7.1b/7.2/7.3, 7.7, and **7.8 (this work unit — CAR-15 clause 1 auto-wired)**.
- Partial: 7.4 (reversibility evidence landed; the `verify-report.md` refresh is verify-owned).
- Deferred: 7.5 (soak) and 7.6 (clause-2 gated drop) — **staged for a later release, not done** (clause 2 needs a real soak window). **RELOCATED** (work unit `close-archive-blockers`) to `plugins/catalogo_core/openspec/changes/caracteristicas-post-soak-drop/` so this change can archive cleanly.

---

## WU-7.7 — retire the flag-as-requirement (feature path is the default)

**Work unit**: `wu7-retire-read-through-default`.

**Objective**: remove the production-safety defect where `read_through()` returned `FALSE`
when the constant was undefined. WU-7's gated drop removes 12 legacy `en_catalogo`/`en_tarifa`
columns, so an undefined constant would make any flag-less environment read dropped columns
(at best every visibility flag silently "not visible"). Production must require **no flag at
all**; the constant survives only as a documented **emergency opt-out**.

**Scope**: invert the default. The constant name stays a pinned contract; the legacy read
branches and the spec deltas (which describe both modes) are untouched.

### Files changed

| File | Action | What was done |
|------|--------|---------------|
| `plugins/catalogo_core/Services/CaracteristicaConfig.php` | Modified | `read_through()` now delegates to the new `legacy_read_explicitly_enabled()` (`defined(READ_THROUGH_FLAG) && constant(...) === false`). Undefined and explicit `TRUE` select the feature path; only an explicit `FALSE` opts out to legacy. |
| `plugins/catalogo_core/Services/CaracteristicaColumnDropMigration.php` | Modified | Clause 2 + dead-column gate now refuse on `legacy_read_explicitly_enabled()` (the opt-out), not on the flag being ON. `runPostSoak` report key renamed `read_through` → `legacy_read_explicitly_enabled`; refusal message and docblocks updated. `--dev17-rewritten` attestation and the refusal path preserved. |
| `plugins/catalogo_core/tools/run_caracteristica_column_drop.php` | Modified | Reports the new semantics: `FEATURE (default; feature reads, legacy only on an explicit opt-out)`. |
| `plugins/catalogo_core/tests/CaracteristicaValorStoreTest.php` | Modified | Undefined → feature; explicit `TRUE` → feature; explicit `FALSE` → legacy (the last two run in isolated processes). |
| `plugins/catalogo_core/tests/CaracteristicaColumnDropTest.php` | Modified | Opt-out refusal (no schema change), default-runs (real gate), gate/idempotency/post-soak tests retargeted to the opt-out seam, report key renamed. |
| `plugins/catalogo_core/tests/CaracteristicaReversibilityTest.php` | Modified | Opt-out seam helper renamed; the closed-loop reversal tests run on the feature default. |
| `plugins/catalogo_core/tests/Controller/VentasArticulosListAbsorptionTest.php` | Modified | Fixture pins the trait's read-path seam to legacy so the ALC-02 batch-map assertions stay meaningful. |
| `plugins/tarifario/tests/Services/ExcelHierarchyServiceUpsertFamiliaTest.php` | Modified | Fixture pins the legacy opt-out (no feature store required); the cascade tests are not about read-through. |
| `plugins/tarifario/tests/Services/ExcelHierarchyServiceApplyRowHierarchyTest.php` | Modified | Same pin for the per-row orchestrator tests. |
| `.../design.md` | Modified | §1 pinned table + `[DECISION]` amended; §8.4, §8.5, §15.2 statements updated to the inverted default and the reason recorded. |
| `.../specs/README.md` | Modified | Decision 5 updated (resolver is the default; explicit `FALSE` is the opt-out). |
| `.../tasks.md` | Modified | New task 7.7; stale gate claims in 7.2/7.3 and the soak inventory row refreshed. |

### TDD cycle evidence (strict TDD — RED first)

| Task | RED (test written first) | GREEN (implementation passes) | REFACTOR |
|------|--------------------------|-------------------------------|----------|
| 7.7 | `--filter Caracteristica` → **150 tests, 868 assertions, 2 errors + 15 failures** (missing `legacy_read_explicitly_enabled()`, old default asserted) | `--filter Caracteristica` → **150 tests, 953 assertions, OK** | Docblocks/comments aligned; no behavior beyond the inversion; no comments or tests deleted to fit budget |

### Work unit evidence

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter Caracteristica` → **OK, 150 tests / 953 assertions** |
| Runtime harness command/scenario and exact result | `ddev exec php plugins/catalogo_core/tools/run_caracteristica_column_drop.php` (dry run) → reports `read path (FS_CATALOGO_CARACTERISTICAS_READ_THROUGH): FEATURE (default; …)`, **12 gated columns still present**, `DRY RUN — no DDL was emitted` |
| Rollback boundary | Revert `CaracteristicaConfig.php` + the gate lines in `CaracteristicaColumnDropMigration.php` + the runner line + the doc/task/test edits. `read_through()` returns to flag-ON-only; no schema, no data, and no other work unit depends on 7.7. |

### Commands and results (exact)

| Command | Result |
|---|---|
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **OK, 914 tests / 3980 assertions / 2 warnings / 1 skipped, 0 failures** (baseline 911/0; +3 tests) |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter Caracteristica` | **OK, 150 tests / 953 assertions** |
| `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **OK, 266 tests / 1093 assertions / 2 skipped, 0 failures** (baseline 266/0) |
| `ddev exec php plugins/catalogo_core/tools/run_caracteristica_column_drop.php` | Dry run: `FEATURE (default)`, 12 gated columns, no DDL |

### Constraints re-verified

- Core `openspec/` untouched; no repository-root SDD entry for this change.
- Delta specs' requirements and scenarios were **not** rewritten — the two modes still exist.
- The constant name `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` is unchanged (pinned contract).
- No `--apply` was run; no DDL emitted; the 12 gated columns remain in the live schema.
- Tests never define the constant in-process except inside `#[RunInSeparateProcess]` +
  `#[PreserveGlobalState(false)]` cases, so the root Plugins suite cannot be polluted.

---

## WU-7.8 — deliver CAR-15 clause 1 automatically (no console, no flag)

**Work unit**: `wu7-clause1-auto-drop`.

**Objective**: make the clause-1 D12 drop run automatically at deploy time — production must
need no console and no flag — while clause 2 stays staged for a later release because it needs
a real soak window.

**Scope**: reuse the existing clause-1 engine. No new drop engine, no schema change in this
environment, no `--apply`, no `fsframework.ini` version bump.

### Files changed

| File | Action | What was done |
|------|--------|---------------|
| `plugins/catalogo_core/Services/CaracteristicaColumnDropMigration.php` | Modified | `dropD12OpcionalColumns()` now refuses (returns `false`, no DDL) while `legacy_read_explicitly_enabled()` — the same emergency opt-out clause 2 honours. Added `migrateIfNeeded(\fs_db2 $db): bool`, the deploy-time clause-1 entry point delegating to `dropD12OpcionalColumns()` (idempotent via `hasColumn()`). Class docblock, clause-1 docblock and the runbook updated. |
| `plugins/catalogo_core/Init.php` | Modified | `Init::upgrade()` calls `CaracteristicaColumnDropMigration::migrateIfNeeded($db)` in the sibling `try/catch` + `error_log` convention, after the backfill. `Init::init()` untouched. |
| `plugins/catalogo_core/tests/CaracteristicaColumnDropTest.php` | Modified | +5 tests: clause-1 opt-out refusal; boot entry drops the four opcional columns and is idempotent; boot entry refusal; boot entry leaves the clause-2 columns untouched; `Init::upgrade()` wiring with `Init::init()` excluded. |
| `plugins/catalogo_core/tests/CaracteristicaReversibilityTest.php` | Modified | Reconciled `test_the_legacy_opt_out_blocks_clause_2_and_the_dead_cleanup_but_not_clause_1` → `test_the_legacy_opt_out_blocks_every_gated_clause` (clause 1 now refuses too). |
| `.../tasks.md` | Modified | 7.5/7.6 marked **DEFERRED to a later release** (unchecked, with rationale); new 7.8 `[x]`; STAGED block; inventory rows updated; finding 2 amended. |
| `.../specs/catalogo-core/caracteristicas-producto/spec.md` | Modified | CAR-15 amended with the two-release delivery staging; both clauses' substance preserved and all three scenarios kept verbatim. |
| `.../design.md` | Modified | §8.5 gains the `[DECISION] Two-release delivery` block, the clause-1 auto-wiring, the opt-out refusal and the corrected boot-wiring bullet. |

### Which existing method clause 1 maps to, and why

Clause 1's spec tables (`tarif_opcional_ext`, `tarif_tarifa_opcional`) are exactly
`CaracteristicaColumnDropMigration::D12_TABLES`. The existing engine is
`dropD12OpcionalColumns()`, so the deliverable reuses it — `migrateIfNeeded()` is a thin
deploy-time gate over it, not a new drop engine.

### TDD cycle evidence (strict TDD — RED first)

| Task | RED (test written first) | GREEN (implementation passes) | REFACTOR |
|------|--------------------------|-------------------------------|----------|
| 7.8 | `--filter CaracteristicaColumnDropTest` → **3 errors + 2 failures** (`migrateIfNeeded()` missing; `Init::upgrade()` not wired); `--filter CaracteristicaReversibilityTest` → **1 failure** (clause 1 still dropped under the opt-out) | `--filter CaracteristicaColumnDropTest` → **OK, 31 tests / 170 assertions**; `--filter CaracteristicaReversibilityTest` → **OK, 10 tests / 125 assertions** | Docblocks/runbook aligned; no comments or tests deleted to fit budget |

### Work unit evidence

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CaracteristicaColumnDropTest` → **OK, 31 tests / 170 assertions** |
| Runtime harness command/scenario and exact result | `ddev exec php plugins/catalogo_core/tools/run_caracteristica_column_drop.php` (dry run only) → `FEATURE (default)`, **12 gated columns still present**, `DRY RUN — no DDL was emitted` |
| Rollback boundary | Revert the `Init::upgrade()` wiring block and the clause-1 refusal in `CaracteristicaColumnDropMigration.php`; the columns are re-addable as nullable and re-derivable (`CaracteristicaReversibilityTest`). No schema was changed in this environment. |

### Commands and results (exact)

| Command | Result |
|---|---|
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **OK, 919 tests / 4002 assertions / 2 warnings / 1 skipped, 0 failures** (baseline 914/0; +5 tests) |
| `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **OK, 266 tests / 1093 assertions / 2 skipped, 0 failures** (baseline 266/0) |
| `ddev exec php plugins/catalogo_core/tools/run_caracteristica_column_drop.php` | Dry run: `FEATURE (default)`, 12 gated columns, no DDL |

### Constraints re-verified

- **No destructive DDL was executed**: no `--apply`, no `fsframework.ini` version bump; the dry
  run reports 12 gated columns and emits no DDL, so the live schema is untouched.
- Core `openspec/` untouched; no repository-root SDD entry for this change.
- Clause 2 stays staged: `runPostSoak()` is still never boot-wired
  (`test_the_entry_point_is_never_wired_into_a_boot_or_request_path` green).
- The CAR-15 delta has no MODIFIED block (new capability, all ADDED, unarchived), so the
  requirement was amended **in place** in the ADDED block; no scenario was added or removed
  (48 requirements / 153 scenarios unchanged).
- The constant name `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` is unchanged (pinned contract).

---

## Work unit `close-archive-blockers` — close the three verify-report archive blockers

**Work unit**: `close-archive-blockers`.

**Objective**: clear the three items the refreshed `verify-report.md` named as archive
blockers, so the change can archive cleanly: (1) the stale CAR-14 default text, (2) the
deferred-but-present clause-2 tasks 7.5/7.6, (3) the PARTIAL scenario #7.

### 1. CAR-14 default text fixed (correctness — WARNING 1)

`specs/catalogo-core/caracteristicas-producto/spec.md` CAR-14 said the consumers read
through the legacy columns "when it is disabled (default)". That contradicted commit
`17b07cdb` (`CaracteristicaConfig::read_through()` = `!legacy_read_explicitly_enabled()`,
so an **undefined** constant selects the feature path) and the already-amended `design.md`
§1/§8.4/§8.5 and `specs/README.md` decision 5. Fixed:

- CAR-14 requirement body now states the feature path is the default (undefined or
  explicit `TRUE`) and the legacy columns are read only under the explicit emergency
  opt-out (`legacy_read_explicitly_enabled()`, constant defined as `FALSE`).
- CAR-14 scenario 1 renamed `Flag off keeps legacy behavior` → `Legacy opt-out keeps
  legacy behavior` and its GIVEN now names the explicit opt-out; scenario 2 renamed
  `Flag on switches to the resolver` → `Feature path (the default) switches to the
  resolver`.
- CAR-15 clause 2 and its `Post-soak drop is gated` scenario also asserted the old
  default gating ("GIVEN the read-through flag disabled … refuses; once the flag is
  enabled … drops"). Restated in terms of the explicit opt-out (refuses) and the feature
  path (the default, drops), matching `dropLegacyArticleFamilyColumns()` and
  `CaracteristicaColumnDropTest`'s "opt-out refusal + default-runs" contract.

No requirement or scenario was added or removed: **48 requirements / 154 scenarios**
unchanged (re-counted with `grep -rh '^### Requirement:' specs/ | wc -l` → 48;
`grep -rh '^#### Scenario:' specs/ | wc -l` → 154).

### 2. Deferred clause-2 tasks relocated to a follow-up change

Created `plugins/catalogo_core/openspec/changes/caracteristicas-post-soak-drop/` with a
minimal, honest artifact set:

- `proposal.md` — intent (close CAR-15 clause 2 after a real soak), scope, the
  maintainer's two-release decision, the soak prerequisite and its
  `visibilidad_sin_sincronizar` signal, and the approach (soak → DEV-17 rewrite → gated
  drop → auto-wire).
- `tasks.md` — C2.1 soak, C2.2 DEV-17 membership-filter rewrite, C2.3 gated drop with the
  explicit-opt-out refusal, C2.4 auto-wire to `Init::upgrade()` exactly as clause 1 is now,
  C2.5 verify.

In `caracteristicas-producto/tasks.md`, the two `- [ ]` items 7.5/7.6 were replaced by
non-checkbox pointers that keep their rationale and state **RELOCATED … NOT DONE here**.
The WU-7 open-item inventory rows and the STAGED block were updated to say RELOCATED.
Result: `grep -c '^- \[ \]' tasks.md` → **0** (was 2); `^- \[x\]` → 70; `^- \[~\]` → 1
(7.4, verify-owned). No spec delta was merged and nothing was created in the core
`openspec/`.

### 3. Scenario #7 closed end-to-end (WARNING 6 / SUGGESTION 5)

Added `ArticuloExcelCaracteristicaTest::test_legacy_workbook_without_feature_columns_imports_unchanged`.
It drives the real import entry point `apply()` with a real one-row `.xlsx` carrying
exactly the seven base headers, the base-only `suggestMapping()`, and the wizard's real
per-row workflow (`persist_feature_values()` then `createArticuloFromRow()` + save). An
`importable` definition (`en_catalogo`) is registered, so the no-op is meaningful:
the base fields persist exactly as before (`referencia`, `descripcion`, `codfamilia`,
`codimpuesto`, `pvp`) and the recording store receives **zero** calls. A control
assertion at the end writes a mapped feature value through the same service and asserts
the store IS called, proving the seam is live and the empty call log is a real no-op —
not a dead seam. Only the store (recording double) and `articulo::save()` are doubled,
the same fidelity as `ArticuloExcelFeaturePersistenciaTest`.

The spec pointer for scenario #7 already targeted `ArticuloExcelCaracteristicaTest.php`,
so no spec edit was needed. (This is a coverage closure, not a new production behaviour:
the test passes GREEN on the existing implementation; strict TDD's RED→GREEN applies to
production changes, and there is none here.)

### Commands and results (exact — this work unit)

| Command | Result |
|---|---|
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter ArticuloExcelCaracteristicaTest` | **OK, 6 tests / 46 assertions, 0 failures** (was 5 tests) |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **OK, 920 tests / 4013 assertions / 2 warnings / 1 skipped, 0 failures** (baseline 919/0; +1 test) |
| `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **OK, 266 tests / 1093 assertions / 2 skipped, 0 failures** (baseline 266/0 confirmed) |
| `ddev exec php plugins/catalogo_core/tools/run_caracteristica_column_drop.php` | Dry run only: `FEATURE (default)`, **12 gated columns still present**, `DRY RUN — no DDL was emitted`, `exit 0` |

### Constraints re-verified

- **No destructive DDL was executed**: the runner was invoked with no flags (dry run),
  reports 12 gated columns and emits no DDL; `fsframework.ini` was **not** version-bumped.
- Core `openspec/` untouched; no repository-root entry for this change or the follow-up.
- No spec delta was merged (archive owns that); only requirement/scenario **wording** was
  corrected in place, with the counts unchanged (48 / 154).
- Clause 2 stays staged and never boot-wired; the follow-up change owns its future
  auto-wire.

### Residuals

- `articulo-lista-canonica` ALC-02 and tarifario `catalogo-integration` R-TAR-HOOK-011
  still phrase the two read states as "flag enabled/disabled" rather than
  "feature path (default) / explicit opt-out". They do **not** assert the legacy path is
  the default, so they were left untouched to keep this work unit bounded; a future
  coherence pass may align their wording.
- `tasks.md` 7.4 remains `[~]` (the `verify-report.md` refresh is verify-owned).
- The refreshed `verify-report.md` was already modified in the working tree by the verify
  phase and is left uncommitted by this work unit.
