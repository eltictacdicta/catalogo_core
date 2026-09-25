```yaml
schema: gentle-ai.archive-result/v1
verdict: pass_with_warnings
change: opcional-en-varios-grupos
sdd_owner: plugins/catalogo_core/openspec/ (ownership: plugin-local)
artifact_store: openspec
critical_findings: 0
incomplete_implementation_tasks: 0
accepted_gaps: 2   # WU-6.T6 (phpstan), WU-6.T7 (browser smoke)
archived_to: plugins/catalogo_core/openspec/changes/archive/2026-09-25-opcional-en-varios-grupos/
spec_merge_command: gentle-ai sdd-archive-compose --canonical ... --delta ... --output ....compose-tmp
spec_merge_exit_code: 0
archive_move_readback: 'diff -r <snapshot> <destination> -> empty (0 differences), exit 0'
core_openspec_entry: NONE
composer_dependency_added: false
```

# Archive Report — opcional-en-varios-grupos

- **Change**: `opcional-en-varios-grupos`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Artifact store**: `openspec`
- **Archive date**: 2026-09-25
- **Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-09-25-opcional-en-varios-grupos/`
- **Delta spec merged into**: `plugins/catalogo_core/openspec/specs/opcionales-management/spec.md`
- **Core `openspec/changes/opcional-en-varios-grupos/` entry**: NONE (verified — `grep -rl "opcional-en-varios-grupos" openspec/` under the core returned no matches; plugin-local ownership rule respected).

## Pre-Archive Checklist

| Gate | Result |
|---|---|
| All artifacts present (`exploration.md`, `pending-decisions.md`, `compatibility-tarifario.md`, `proposal.md`, `design.md`, `tasks.md`, `specs/`, `apply-progress.md`, `verify-report.md`) | ✅ Present and archived |
| `verify-report.md` has no CRITICAL issues | ✅ `critical_findings: 0`, verdict `pass_with_warnings` |
| No unchecked implementation task (`- [ ]`) in `tasks.md` | ✅ 49/49 checkboxes `- [x]`; 0 `- [ ]` |
| Named exceptions (WU-6.T6, WU-6.T7) | ⚠️ Written as bare bullets (unticked), explicitly accepted as warnings by the maintainer — recorded below, not ticked |
| Round-trip / smoke test | ✅ Already executed in verify (transactional real-DB smoke, `ROLLBACK`ed). Referenced, not re-run |
| Composer dependency added | ❌ None → no `vendor/` commit step required (see below) |
| Delta merged into canonical spec before move | ✅ Via `sdd-archive-compose` (exit 0) |
| Change folder moved to archive (nothing left at change root) | ✅ Source path absent; active `changes/` contains only `archive/`, `caracteristicas-post-soak-drop`, `remove-demo`-unrelated entries |
| Core `openspec/` has no entry for this change name | ✅ Confirmed |

## Task Completion Gate — Reconciliation Note

`tasks.md` has **0 unchecked `- [ ]` implementation tasks** (49 checked). No stale-checkbox
reconciliation was needed.

**Intentional exception (audit-trail transparency)**: `WU-6.T6` and `WU-6.T7` are written as
bare bullets with no checkbox marker — neither `- [x]` nor `- [ ]`. They were left unticked
by apply and the maintainer **explicitly accepted them as accepted gaps (warnings)** for
archival. Per instruction, no tick was faked. They are verification-only tasks (the WU-6
verify pass produces no production change and nothing to revert):

- `WU-6.T6`: `ddev exec composer phpstan` (plugin `phase_rules.linter`) — not satisfied as
  stated because `phpstan.neon` does not analyse `plugins/` and exits 1 on a pre-existing
  unrelated core-test error (see accepted warning B).
- `WU-6.T7`: manual browser smoke checklist (multi-group checkbox list saves losslessly;
  "Grupo" column shows all labels from one map; TPV shows the opcional under each group and
  charges once) — not executed at UI level; the equivalent DB-level behaviour was verified
  transactionally in verify (see accepted warning A).

All implementation tasks (`WU-0` … `WU-5`) are complete and checked.

## Final-State Facts After Apply (authoritative over intermediate snapshots)

These facts supersede any intermediate statements in `apply-progress.md` / `verify-report.md`.
`apply-progress` and `verify-report` are point-in-time snapshots of the cycle.

- **Per-repo commits** (not pushed; no PR opened):
  - `plugins/catalogo_core` HEAD `6732d16f` — `cd0734ea`, `611bf0b0`, `db28c621`, `043e8b5e`,
    `7cf01de0`, `1312ecc0`, `a7dee3fb`, `b40e414e`, `80d7e874`, `3a0f3018`, `478be229`,
    `51e6a9ea`, `6732d16f` (13 commits; the archive commit adds the 14th).
    - Note: verify-report's commit list began at `611bf0b0`; the launch prompt's final-state
      list includes `cd0734ea` (the initial SDD-artifacts commit), which is the authoritative
      list used here.
  - `plugins/tpvmod` — `134cbcc` (`fix(tpvmod): dedupe an opcional selected across several groups`).
  - `plugins/tarifario` — `f9cac3b`, `310015e`.
- **Final suite totals (all exit 0)**:
  - catalogo_core: `983 tests / 4296 assertions` (`OK, but there were issues!` 2 warnings, 1 skipped) — exit 0
  - tpvmod: `181 tests / 1337 assertions` — exit 0
  - tarifario: `268 tests / 1116 assertions` (2 skipped) — exit 0
  - root regression: `2788 tests / 10493 assertions` (24 skipped) — exit 0
- **Schema**: `catalogo_opcionales.id_grupo` is **FROZEN** — the physical column is kept
  (not dropped); no production code reads or writes it (only `CatalogLegacyTableMigration`
  reads it for backfill). The `DROP COLUMN` is deferred to a follow-up soak-drop change.
  `catalogo_opcional_grupo_rel` exists with `PRIMARY KEY (id)`, `UNIQUE (id_opcional, id_grupo)`
  and no DB-level foreign key.
- **Spec source of truth**: canonical `opcionales-management/spec.md` now holds 23 requirements
  (14 pre-existing + 9 added), 62 scenarios.

## Accepted Warnings (carried from verify; quoted with reasons)

The final verdict is **PASS WITH WARNINGS**. The following non-blocking warnings are accepted
and carried verbatim in substance with their reasons:

- **(A) Two scenarios verified manually only.** Per `verify-report.md`, 35/35 scenarios are
  compliant — 33 by passing automated tests, **2 by manual/source-level verification**:
  - `OPG-08` scenario 2 ("Grouped opcional rejects a direct relation") — no test references
    `validate_opcional_for_articulo()`; verified by source inspection at
    `model/core/catalogo_articulo_opcional.php:64` (`if ($item->is_grouped()) return '…'`),
    with `is_grouped()` itself test-covered. A one-line unit test is recommended.
  - `OUM-05` scenario 3 ("Creation without memberships yields a loose opcional") — the covering
    test posts no `grupos` but does not assert the resulting empty membership set; loose
    semantics are covered indirectly by the bridge anti-join test.
  - Reason accepted: the plugin's `phase_rules.verify` explicitly allows "grep audit + phpstan
    + manual smoke checklist"; both scenarios are still verified (source-level / live-DB), just
    not by a dedicated assertion.
- **(B) Configured linter does not lint the plugin and fails on an unrelated core test.** Per
  `verify-report.md`, `phpstan.neon` sets `paths: [src, tests]`, so `ddev exec composer phpstan`
  gives **no coverage of `plugins/`** and exits 1 solely on a pre-existing error at
  `tests/Core/PluginEnableAjaxSafetyTest.php:308` (last touched by core release commit
  `14a4c7b7`, 2026-09-20, before this change). No finding is attributable to this change.
  Reason accepted: out of scope; a plugin-scoped `phpstan.neon` is recommended as a follow-up.
- **(C) Recorded-count drift in `apply-progress.md`** (reported, not fixed): tpvmod final
  recorded as `180 tests / 1309 assertions` vs observed `181 / 1337` (+1 test, +28 assertions);
  Slice A reported the root regression at `2740 / 9900` while Slice B1 named the baseline as
  `2742 / 9948` (mutually inconsistent). Reason accepted: `apply-progress` is an intermediate
  snapshot; the authoritative final totals are the observed run recorded in the Final-State
  facts above, which match catalogo_core / tarifario / root exactly.
- **(D) One stale `- Test:` pointer in the delta/merged spec** (`OPG-02` scenario 1). The
  pointer names `plugins/catalogo_core/tests/VentasOpcionalesControllerTest.php`, but the
  covering batched-map test lives in `CatalogoOpcionalesUnifiedControllerTest.php` (view
  contract in `CatalogoOpcionalesHtmxContractTest.php`). Reason accepted: coverage exists; only
  the pointer is stale. Recorded as a documentation follow-up, not corrected here (archive must
  not alter the verified delta content).

**CRITICAL**: None. No blocker.

## Spec Sync (delta → canonical)

Merge executed with the native compose command (mandatory native composition — no manual
Read/Edit merge):

```bash
gentle-ai sdd-archive-compose \
  --canonical "openspec/specs/opcionales-management/spec.md" \
  --delta "openspec/changes/opcional-en-varios-grupos/specs/opcionales-management/spec.md" \
  --output "openspec/specs/opcionales-management/spec.md.compose-tmp"
# EXIT=0  →  mv compose-tmp → spec.md  (atomic replace)
```

| Domain | Action | Details |
|---|---|---|
| `opcionales-management` | Updated | 3 requirements MODIFIED (`OUM-02`, `OUM-05`, `OPG-02`); 9 requirements ADDED (`OPG-03` … `OPG-11`); 0 removed/renamed. 14 → 23 requirements, 62 scenarios |

**Non-destructive verification**: `diff` of the pre-merge canonical against the composed output
showed ONLY the three expected MODIFIED bodies/scenarios plus the nine appended requirements.
No unrelated requirement was dropped, reordered or renumbered: `OUM-01`, `OUM-03`, `OUM-04`,
`OUM-06`…`OUM-12`, `OPG-01` are byte-for-byte preserved, and the canonical file's format and
ordering conventions are retained.

## Composer / Vendor

This change adds **NO** Composer dependency to any of `catalogo_core`, `tpvmod` or `tarifario`.
Therefore **no `vendor/` commit step is required** for this change, and `git add plugins/*/vendor/`
was intentionally not performed. The "commit `vendor/` with `composer.json`/`composer.lock`" rule
does not apply here.

## Archive Verification

- **Mechanical move**: `git mv openspec/changes/opcional-en-varios-grupos openspec/changes/archive/2026-09-25-opcional-en-varios-grupos` → OK.
- **Mandatory `diff -r` readback** (pre-move recursive snapshot vs. archived destination), verbatim:
  ```text
  $ diff -r "$snapshot_root/source" "openspec/changes/archive/2026-09-25-opcional-en-varios-grupos"
  (no output)
  DIFF_EXIT=0
  ```
  Empty output (0 differences, exit 0) — byte-identical. `archive-report.md` is additive-only and
  excluded from the comparison.
- Active `openspec/changes/` no longer contains `opcional-en-varios-grupos`.
- Archive contains all artifacts: `exploration.md`, `pending-decisions.md`,
  `compatibility-tarifario.md`, `proposal.md`, `design.md`, `tasks.md`, `apply-progress.md`,
  `verify-report.md`, `specs/opcionales-management/spec.md`, plus this `archive-report.md`.

## Behavioural Evidence Reference (not re-run)

The verify phase already executed a **transactional real-DB smoke** (`ddev exec mysql`) with an
explicit transaction and `ROLLBACK`: two-group membership coexistence, UNIQUE rejection of
duplicates, per-group filter listing, "Sin grupo" anti-join, inactive-group membership, and the
frozen-column non-write audit. Post-rollback state was unchanged. It was **not re-run
destructively** during archive. All four suites were re-executed by verify and passed (exit 0).

## Intentional Partial / Warning Archive

This archive is **intentional-with-warnings**: `WU-6.T6`/`WU-6.T7` remain unticked (accepted by
the maintainer), and the four warnings above are carried, not resolved. No CRITICAL finding and
no incomplete implementation task exists; no stale-checkbox reconciliation was performed.

## Verdict

**PASS WITH WARNINGS — archived.**

All 12 delta requirements and 35 scenarios are verified (33 automated, 2 manual/source), all four
test suites pass (exit 0), the M:N bridge is the sole membership source, the legacy
`catalogo_opcionales.id_grupo` column is frozen (physical column retained, drop deferred), and
both `tarifario` bulk-delete paths clean the bridge. The 9 added requirements and 3 modified
requirements are merged into the canonical `opcionales-management` spec. No core `openspec/` entry
was created, no production code/tests/DB were modified by this archive, and no Composer dependency
was introduced.

### SDD Cycle Complete

The change `opcional-en-varios-grupos` has been planned, specified, designed, implemented,
verified and archived. Ready for the next change.
