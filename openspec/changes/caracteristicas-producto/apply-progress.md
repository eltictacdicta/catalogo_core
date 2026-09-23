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

- Done: WU-1 … WU-6, WU-8, plus WU-7 7.1/7.1b/7.2/7.3 and **7.7 (this work unit)**.
- Partial: 7.4 (reversibility evidence landed; the `verify-report.md` refresh is verify-owned).
- Open: 7.5 (soak), 7.6 (gated drop on a pre-drop dump).

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
