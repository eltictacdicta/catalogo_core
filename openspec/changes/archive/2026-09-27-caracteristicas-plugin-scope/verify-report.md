# Verify Report: caracteristicas-plugin-scope

> Change: `caracteristicas-plugin-scope` — plugin `catalogo_core`
> (`ownership: plugin-local`, `strict_tdd: true`, artifact store `openspec`).
> SDD root: `plugins/catalogo_core/openspec/`. Phase: **verify** (read-only).
> Verdict scope: the delta at
> `specs/caracteristicas-producto/spec.md` (ADD CAR-20; MODIFY CAR-06/11/12/16/17/18/19)
> against the applied working tree and the tests that assert it.
>
> **Re-verification.** This report supersedes the previous one, which carried an
> open WARNING (W1) and a stale SUGGESTION (S3). Since then the apply phase added
> **Phase 7 — Remediation (W1)**: `Services/CaracteristicaBackfillMigration.php`
> now consults the shared `CaracteristicaOwnership` rule and skips inert
> definitions before any `INSERT`, with new coverage in
> `tests/CaracteristicaBackfillTest.php`. **W1 is now CLOSED** and **S3 is
> closed** (`tasks.md` and `apply-progress.md` agree at 31/31). This phase
> modified nothing except this file; no source, spec, test, task checkbox or
> prior report was altered.

## Scope

| Input | Status |
|---|---|
| Delta spec (`specs/caracteristicas-producto/spec.md`) | Present, read |
| `design.md` (D1–D9) | Present, read (not re-audited this phase) |
| `tasks.md` | Present, read — 31 `[x]`, 0 `[ ]` |
| `apply-progress.md` | Present, read — includes Phase 7 evidence |
| Applied code under `plugins/catalogo_core/` | Present, inspected |
| Strict TDD mode | Active (`strict_tdd: true`); apply-progress carries RED/GREEN evidence |

Applied production files inspected (all under `plugins/catalogo_core/`):

- `Services/CaracteristicaOwnership.php` (new)
- `Services/CaracteristicaResolver.php` (modified)
- `Services/CaracteristicaValorBatchReader.php` (modified)
- `Controller/VentasCaracteristicas.php` (modified)
- `Services/CaracteristicaBackfillMigration.php` (modified — Phase 7 / W1 fix)

New/asserted surfaces inspected: `Services/CaracteristicaValorStore.php`,
`Services/ArticuloExcelImportWizardService.php`, `Services/ArticuloExcelExportService.php`,
`Controller/VentasArticulos.php`, `Init.php`, `model/table/catalogo_caracteristicas.xml`,
`tests/CaracteristicaBackfillTest.php`.

## Observed progress

- `tasks.md` contains **31 checkboxes, all `[x]`, none unchecked** (Phases 1–6
  plus `Phase 7 — Remediation (W1)`). `apply-progress.md:378` states
  "**31/31 tasks complete**", so the previous S3 count mismatch is resolved.
- Plugin git working tree (`plugins/catalogo_core`, its own repository):
  `M Controller/VentasCaracteristicas.php`, `M Services/CaracteristicaBackfillMigration.php`,
  `M Services/CaracteristicaResolver.php`, `M Services/CaracteristicaValorBatchReader.php`,
  and 8 modified test files; `?? Services/CaracteristicaOwnership.php`,
  `?? tests/CaracteristicaOwnershipTest.php`,
  `?? openspec/changes/caracteristicas-plugin-scope/`. Nothing staged/committed.
- Root repository working tree: clean (`git status --short` empty). All change is
  inside the plugin's own repository.

## Checks (exact commands, results)

| # | Command | Exit | Result |
|---|---|---|---|
| 1 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | 0 | `OK, but there were issues! Tests: 1012, Assertions: 4425, Warnings: 2, Skipped: 1.` — 0 failures / 0 errors |
| 2 | `ddev exec php vendor/bin/phpunit` | 0 | `OK, but some tests were skipped! Tests: 2819, Assertions: 10771, Skipped: 24.` — 0 failures / 0 errors |
| 3 | `ddev exec composer phpstan` | 1 | 1 error: `tests/Core/PluginEnableAjaxSafetyTest.php:308` (`return.type`: missing `changes` offset). Pre-existing, unmodified, outside this change's boundary (`phpstan.neon` `paths: [src, tests]`; the plugin tree is not analyzed). |

Check 1 is +3 tests / +7 assertions over the pre-Phase-7 plugin profile
(1009/4418) — exactly the three Phase 7 backfill cases. Check 2 is +3 tests /
+9 assertions over the earlier root profile (2816/10762); the skip count moved
25 → 24. That −1 skip is an environment-dependent conditional skip (the skipped
set is dominated by "`facturacion_base` plugin is not installed" guards and
`SessionFixationPreventionTest` session-availability guards), not change-caused:
no core file changed, and the plugin diff removes no skip. See Findings/SUGGESTION S-ENV.

Focused runs over the changed/added test files (all green, run under check 1's
configuration):

| Filter | Result |
|---|---|
| `CaracteristicaBackfill` (Phase 7 / W1) | `OK (10 tests, 26 assertions)` |
| `CaracteristicaOwnership` | `OK (6 tests, 24 assertions)` |
| `CaracteristicaResolver` | `OK (13 tests, 32 assertions)` |
| `CaracteristicaValorStore` | `OK (7 tests, 31 assertions)` |
| `OpcionalVisibility` (Derivation + Parity) | `OK (18 tests, 40 assertions)` |
| `CaracteristicaBoundaries` | `OK (9 tests, 151 assertions)` |
| `VentasArticulosListCaracteristicas` | `OK (10 tests, 60 assertions)` |
| `VentasCaracteristicasController` | `OK (18 tests, 74 assertions)` |
| `ArticuloExcelCaracteristica` | `OK (7 tests, 64 assertions)` |

Baseline note: the 2 warnings + 1 skip in check 1 and the 24 skips in check 2
are the recorded pre-existing profile, not change-caused.

## W1 — targeted, independent closure check

Beyond the change's own `CaracteristicaBackfill` test run, W1 was re-confirmed by
driving the **production** `CaracteristicaBackfillMigration::migrateIfNeeded()`
directly through an inline probe (a fake `\fs_db2`, no repository file written),
with the real `CaracteristicaOwnership`/`$GLOBALS['plugins']` path:

| Scenario (`$GLOBALS['plugins']`) | Definitions | `INSERT`s emitted | value rows written |
|---|---|---|---|
| `[]` (registry empty) | both `origen='tarifario'` (inert) | **0** | **0** |
| `[]` (registry empty) | both `origen=''` (operator-owned) | 2 | 2 |
| `['tarifario']` (owner enabled) | both `origen='tarifario'` | 2 | 2 |
| `[]` (registry empty) | one `origen=''` + one `origen='tarifario'` | 1 | 1 |

- **Inert ⇒ zero writes**: with the owner not enabled, `flag_definitions()`
  (`CaracteristicaBackfillMigration.php:356-379`) resolves each definition's
  `origen` via `definition_row()` (`:395-409`) and `continue`s on
  `!self::ownership()->is_active($definition['origen'])` **before** any
  `catalog_value_id()` lookup or `INSERT`. No flags entry is produced, so none of
  the seven `backfill_*` surfaces runs for it.
- **Re-enable resumes**: a fresh run with the owner enabled performs the normal
  additive `INSERT … WHERE NOT EXISTS`, having written nothing during the inert
  period (the probe uses a separate DB per scenario, so the inert run left no
  state).
- **`origen = ''` unaffected**: the operator-owned definitions still backfill
  with an empty registry (2 inserts), and the per-definition skip is proven by
  scenario 4 (1 insert).
- `origen` is a declared column of `catalogo_caracteristicas.xml:60`, so the
  migration's `SELECT id, origen` is schema-safe on the live install.
- The migration diff is minimal and semantics-preserving for active definitions:
  `definition_id()` → `definition_row()`, plus the inert skip; same columns,
  same `INSERT … WHERE NOT EXISTS` shapes, additive-only, idempotent.

## Delta scenario → test mapping (all green)

| Requirement | Scenario | Test asserted |
|---|---|---|
| CAR-20 | Owner disabled makes the definition inert everywhere | `CaracteristicaOwnershipTest`, `VentasCaracteristicasControllerTest`, `CaracteristicaResolverTest`, `VentasArticulosListCaracteristicasTest` |
| CAR-20 | Re-enabling the owner restores the definition and its values unchanged | `CaracteristicaOwnershipTest`, `CaracteristicaValorStoreTest` — plus the boot-path `CaracteristicaBackfillTest` (Phase 7) |
| CAR-20 | Operator-created definition is unaffected by plugin state | `CaracteristicaOwnershipTest` |
| CAR-20 | Registry unavailable fails closed | `CaracteristicaOwnershipTest` |
| CAR-20 | Ownership check uses the framework registry, no tarifario coupling | `CaracteristicaBoundariesTest` |
| CAR-06 (MOD) | Owner-disabled definition resolves like missing/inactive | `CaracteristicaResolverTest` |
| CAR-11 (MOD) | A plugin-owned definition is inert while its owner is disabled | `CaracteristicaOwnershipTest`; registration/boot-order | `plugins/tarifario/tests/CaracteristicaDefaultsRegistrationTest.php` (discovered by the root suite) |
| CAR-12 (MOD) | Owner-disabled visibility definitions resolve not visible | `OpcionalVisibilityDerivationTest` |
| CAR-16 (MOD) | Owner-disabled `listable` emits no column; constant query count | `VentasArticulosListCaracteristicasTest` |
| CAR-17 (MOD) | Owner-disabled definition contributes no import field/alias/export column | `ArticuloExcelCaracteristicaTest` |
| CAR-18 (MOD) | Owner-disabled definitions hidden; direct actions refused | `VentasCaracteristicasControllerTest` |
| CAR-19 (MOD) | No core change / no core openspec entry; dependency direction; no tarifario coupling | `CaracteristicaBoundariesTest` |

Every delta scenario maps to at least one passing test with concrete assertions.

## Boundary confirmation

| Boundary | Evidence |
|---|---|
| No `plugins/tarifario/` change | `git -C plugins/tarifario status --short` empty (its own repo) |
| No core change | Root `git status --short` empty; `tests/CaracteristicaBackfillTest.php` and the plugin tree are the only writers |
| No repository-root `openspec/` entry | `openspec/changes/caracteristicas-plugin-scope` absent; asserted by `CaracteristicaBoundariesTest:96-102` |
| No tarifario coupling / hardcoded plugin literal in the ownership rule | `CaracteristicaOwnership.php` imports only `FSFramework\Core\Plugins` and reads `origen`; the three production files' only `tarifario` occurrences are docblock prose, removed by the test's `stripComments()` token scan; asserted by `CaracteristicaBoundariesTest:104-121` |
| Three read-path wiring sites call the shared rule | Asserted by `CaracteristicaBoundariesTest:123-147` |
| Dependency direction unchanged; no new Composer package | Asserted by `CaracteristicaBoundariesTest:149-208` (frozen baselines) |
| Delta spec + canonical spec unmodified | `git -C plugins/catalogo_core status --short -- openspec/specs/` empty |

## Findings

### CRITICAL

None. All delta scenarios have a passing test that asserts them; the boundary is
intact; the change is subtractive on the active-set boundary and introduces no new
return contract.

### WARNING

**None. W1 is CLOSED.** The former WARNING — the boot-time
`CaracteristicaBackfillMigration::migrateIfNeeded()` could `INSERT … WHERE NOT
EXISTS` mirror rows for an inert (`origen` owner disabled) definition — is fixed
by Phase 7. The migration now skips inert definitions before any value lookup or
`INSERT` (`CaracteristicaBackfillMigration.php:356-379`), reusing the same
`CaracteristicaOwnership` predicate as the read paths, with no new contract and no
spec change. Verified by the `CaracteristicaBackfill` test run (10 tests / 26
assertions) and independently by the direct production probe above (0 inserts in
the inert case; 2 inserts after re-enable; operator `origen=''` unaffected).

### SUGGESTION

**S1 — `exportable_definitions()` still has no production caller, so the export
surface emits no feature column for any definition (inert or not).**
*(Pre-existing; owned by the `articulos-excel-import-export` delta — unchanged by
this change.)*

- `Services/CaracteristicaResolver.php:123` provides `exportable_definitions()`
  (correctly filtered), and `Services/ArticuloExcelExportService.php:50-76`
  appends columns from its `$exportable` argument (default `[]`).
- The only production call sites, `Controller/VentasArticulos.php:388-392` and
  `:403-408`, call `buildSpreadsheet(...)` with named `idiomas:` /
  `codidioma_defecto:` arguments and never pass `$exportable`.
- Effect on this change: the CAR-20 inert-exclusion contract is correctly
  asserted at the service seam, and no inert column can leak in production only
  because no exportable definition reaches the exporter at all. The additive
  export behavior is `articulos-excel-import-export`'s responsibility.

**S2 — Per-row ownership-helper instantiation (minor, unchanged).** The resolver
(`CaracteristicaResolver.php:95`), panel (`VentasCaracteristicas.php:130`) and
batch reader (`CaracteristicaValorBatchReader.php:139`) call `$this->ownership()`
inside their per-row closures, allocating a new helper per definition row.
Allocation-only, O(definitions), no query; not worth changing on the hot path
unless a future profile says otherwise. The Phase 7 migration allocates exactly
one helper per definition resolution (`ownership()` inside `flag_definitions`),
which is fine.

**S3 — CLOSED.** `apply-progress.md:378` now says "31/31 tasks complete",
consistent with the 31 checkboxes in `tasks.md`. The previous count mismatch is
gone.

**S4 — The boundary gate pins three read-path wiring sites but not the fourth
(Phase 7) call site.** `CaracteristicaBoundariesTest:123-147` enumerates
`CaracteristicaResolver.php`, `Controller/VentasCaracteristicas.php` and
`CaracteristicaValorBatchReader.php`; the W1 fix added a fourth
`ownership()->is_active(` call in `Services/CaracteristicaBackfillMigration.php`,
deliberately outside that frozen list. The backfill behavior is covered by its own
`CaracteristicaBackfillTest`, so this is not a defect — but the structural gate
would not catch a future un-wiring of that fourth site. Optional follow-up: add
the migration to the wiring-site list (a test change, out of scope for verify).

**S5 — The delta CAR-20 scenario test references do not list
`CaracteristicaBackfillTest.php`.** The scenario "no definition row or value row
was written, deleted or mutated during the inert period" names
`CaracteristicaOwnershipTest`/`CaracteristicaValorStoreTest`; the Phase 7
backfill test is a stronger (boot-path) assertion of the same invariant, added
after the delta was written. Documentation-only; the spec text itself is already
satisfied.

**S-ENV — Root-suite skip count moved 25 → 24 (environmental, not change-caused).**
The skipped set is dominated by uninstalled-plugin guards (`facturacion_base
plugin is not installed`) and `SessionFixationPreventionTest` session-availability
guards, which are conditional. No core file changed and the plugin diff removes
no skip, so this is run-to-run environment flutter, not a regression.

## Historical context

- The previous verify-report concluded **PASS with 1 WARNING (W1) + 3
  SUGGESTIONS**. W1 is now remediated (Phase 7) and closed; S3 is resolved by the
  apply phase's corrected count; S1 and S2 remain valid pre-existing observations.
- `apply-progress.md` records RED/GREEN evidence per task, including Phase 7's
  RED (`Tests: 10, Failures: 2` — the non-CAR-20-aware migration emitted 2
  `INSERT`s for inert definitions) and GREEN (`OK, 10 tests, 26 assertions`).
  This verification did not re-run the historical RED executions; it confirms the
  final GREEN state, the assertions themselves, and the remediated behavior
  independently.
- The known pre-existing phpstan failure (`tests/Core/PluginEnableAjaxSafetyTest.php:308`)
  and the 2 warnings + 1 skip plugin-suite profile are reproduced exactly, so they
  remain baseline context rather than change regressions.

## Summary

- **Verdict: PASS — 0 CRITICAL, 0 WARNING (W1 closed), 4 SUGGESTIONS (S1, S2,
  S4, S5; S3 closed).**
- Verified by execution: plugin suite (1012 tests / 4425 assertions), root suite
  (2819 tests / 10771 assertions), phpstan (only the pre-existing core error), and
  nine focused runs over the changed test files — all green. W1 was additionally
  re-confirmed by a direct production probe: inert ⇒ 0 inserts, re-enable ⇒ normal
  backfill, `origen=''` unaffected.
- Verified by inspection: the ownership predicate, the three read-path wiring
  sites, the five panel guards, the transitive store refusal, the constant-query
  batch filter, and the new inert-skip guard on the boot backfill.
- Every delta scenario maps to a green test; the boundary (no core change, no
  `plugins/tarifario/` change, no repository-root `openspec/` entry, no tarifario
  coupling / hardcoded plugin literal) holds.
- **Recommended next work:** proceed to `sdd-archive`; record the closed W1/S3
  and the still-open pre-existing S1/S2 (plus optional S4/S5) in the archive
  report. No apply work is outstanding.
