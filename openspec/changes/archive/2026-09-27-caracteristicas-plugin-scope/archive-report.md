```yaml
schema: gentle-ai.archive-result/v1
change: caracteristicas-plugin-scope
sdd_root: plugins/catalogo_core/openspec/   # owner (ownership: plugin-local)
archived_to: plugins/catalogo_core/openspec/changes/archive/2026-09-27-caracteristicas-plugin-scope/
archive_date: "2026-09-27"
verdict_at_verify: pass
blockers_at_verify: 0
critical_findings: 0
warnings_at_verify: 0            # W1 closed by Phase 7
suggestions_at_verify: 4         # S1, S2, S4, S5 carried forward; S3 closed
requirements_at_verify: 20/20    # canonical after merge
scenarios_at_verify: 75/75      # canonical after merge
delta_requirements: 8           # 1 ADDED (CAR-20) + 7 MODIFIED (CAR-06/11/12/16/17/18/19)
delta_scenarios: 12             # +1 per MODIFIED block, +5 for CAR-20
implementation_tasks_checked: 31
implementation_tasks_unchecked: 0
composer_dependency_added: false
delivery: human-owned (nothing committed, pushed, PR'd or tagged by this phase)
final_suites:
  catalogo_core: "1012 tests / 4425 assertions — 0 failures, 2 warnings, 1 skipped (pre-existing)"
  root: "2819 tests / 10771 assertions — 0 failures, 24 skipped"
  phpstan: "exit 1 — 1 pre-existing error outside the plugin boundary"
canonical_sha256:
  before: ef884979da79f2165504e4a43af6580f79c0cb239227578a455aa347b0bc705f
  after:  a97318111ec30b3a5cc09bf32b8ccc8ba144a04b15ab14dc8d5671c704354439
```

# Archive Report — `caracteristicas-plugin-scope`

**Change**: `caracteristicas-plugin-scope` (plugin-local SDD, `ownership: plugin-local`, `strict_tdd: true`)
**SDD owner**: `plugins/catalogo_core/openspec/` — the repository-root `openspec/` is **not** a tracker for this change and holds **no entry** for it (CAR-19).
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-09-27-caracteristicas-plugin-scope/`
**Delivery**: commit / push / PR / tag / release are **human-owned** and were **NOT** performed by this archive phase. Every change is in the `plugins/catalogo_core` working tree (its own git repository); the repository root is clean.

## Final-State Authority

`apply-progress.md` and `verify-report.md` are **intermediate snapshots**; they describe the change at the time they were written. The `verify-report.md` archived here is itself a **re-verification**: it superseded an earlier report that carried an open WARNING (W1) and a stale SUGGESTION (S3). The orchestrator's launch prompt is the most recent account of the change and outranks the snapshots. Final state:

| Snapshot claim (earlier verify) | Final state | Where the fix landed |
|---|---|---|
| WARNING 1 — the boot-time `CaracteristicaBackfillMigration::migrateIfNeeded()` could `INSERT … WHERE NOT EXISTS` mirror rows for an inert (`origen` owner disabled) definition | **CLOSED** — the migration reuses `CaracteristicaOwnership` and skips inert definitions before any value lookup or `INSERT`; during an inert period the boot backfill performs **zero writes** | `apply-progress.md` **Phase 7 — Remediation (W1)**; `plugins/catalogo_core/Services/CaracteristicaBackfillMigration.php:356-379`; covered by `plugins/catalogo_core/tests/CaracteristicaBackfillTest.php` (10 tests / 26 assertions, focused) |
| SUGGESTION 3 — `tasks.md` / `apply-progress.md` task-count mismatch | **CLOSED** — `apply-progress.md:378` now says "31/31 tasks complete", consistent with the 31 checkboxes in `tasks.md` | apply phase corrected the count |

`critical_findings: 0` and `warnings: 0` in the archived `verify-report.md` — no CRITICAL or WARNING issue exists in the delivered scope. The four SUGGESTIONS below are carried forward unchanged, per the launch prompt and the archived report.

Because the native dispatcher (`gentle-ai sdd-status`) only sees the repository-root `openspec/`, plugin SDDs are invisible to it; its output would falsely report this change as blocked and was **not** used as authority. The archive-readiness signal was the orchestrator's explicit launch prompt plus the artifact set on disk.

## Delivered Behavior (what shipped)

`catalogo_core` gains an **enabled-plugin ownership scope** for feature definitions, so a definition registered by a plugin that is not enabled becomes **inert** across the whole feature system without being destroyed:

- **New shared rule (CAR-20)** — `Services/CaracteristicaOwnership.php` derives active/inert solely from the definition's `origen` plus the framework enabled-plugin registry (`FSFramework\Core\Plugins::enabled()`). `origen = ''` (operator-created) rows are always active; a non-empty `origen` whose plugin is not enabled is inert; an unavailable registry **fails closed** (only `origen = ''` stays active).
- **Uniform read path (CAR-06, CAR-12, CAR-16, CAR-17)** — the shared predicate is wired at the three read-path sites: `Services/CaracteristicaResolver.php` (owner-disabled resolves `null`, the same contract as a missing/inactive definition), `Services/CaracteristicaValorBatchReader.php` (owner-disabled emits no product-list column, query count stays independent of N), and the D12 opcional derivation (owner-disabled visibility definitions resolve "not visible" through the existing no-value contract).
- **Management panel (CAR-18)** — `Controller/VentasCaracteristicas.php` hides owner-disabled definitions from the listing and refuses every direct mutating action targeting them (save/edit, flag toggle, value assignment, delete), including POST/URL requests that bypass the rendered listing.
- **Default registration (CAR-11)** — non-deletability of a non-empty `origen` definition is now linked to the enabled-scope rule: an owner-disabled definition remains non-deletable while inert, and re-enabling the owner restores it unchanged.
- **Boundary (CAR-19)** — the ownership rule is plugin-agnostic: it reads only `origen` and the framework registry, introduces no `catalogo_core → tarifario` coupling and hardcodes no plugin name. No core file changed and no repository-root `openspec/` entry exists.

**Inert is non-destructive.** Definition rows and their assigned values are preserved; re-enabling the owning plugin restores them byte-identically, with no writes having occurred during the inert period. The Phase 7 remediation extends that invariant to the boot-time backfill.

## Archive-time Spec Sync

One delta file was merged into its canonical target using the mandated native composer `gentle-ai sdd-archive-compose` (requirement matched by name; unrelated requirements preserved byte-for-byte). The composer exited `0` and the `## ADDED Requirements` / `## MODIFIED Requirements` headers were stripped by the composition; the canonical file is the post-change spec.

| Delta (archived) | Canonical target | Action | Before → After |
|---|---|---|---|
| `specs/caracteristicas-producto/spec.md` | `plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md` | **ADD** CAR-20; **MODIFY** CAR-06, CAR-11, CAR-12, CAR-16, CAR-17, CAR-18, CAR-19 | 19 req / 63 scen → **20 req / 75 scen** |

Composition evidence (verbatim invocation):

```text
$ gentle-ai sdd-archive-compose \
    --canonical plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md \
    --delta plugins/catalogo_core/openspec/changes/caracteristicas-plugin-scope/specs/caracteristicas-producto/spec.md \
    --output plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md.compose-tmp
exit 0
$ mv plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md.compose-tmp \
     plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md
```

- **No requirement was lost or added by mistake.** CAR-20 is the single new requirement (+1). Each of the seven MODIFIED blocks is a full requirement block and was replaced wholesale, each gaining exactly one scenario (+7); CAR-20 contributes five scenarios (+5) — 63 + 12 = 75, matching the observed count.
- **Unmodified requirements preserved.** CAR-01..CAR-05 were verified byte-identical before/after the composition (`diff` of the shared region: empty); CAR-07..CAR-10 and CAR-13..CAR-15 are untouched by the delta and were preserved by the composer's name-matched merge.
- **`(Previously: …)` markers retained.** The archived precedent `2026-09-23-caracteristicas-producto` established the convention (markers are kept in the canonical tree — 9 canonical spec files in this plugin carry them), and the composer retained all seven markers in the composed output. `specs/README.md` rule "replace the matched blocks wholesale" is satisfied.
- **Delta headers stripped.** The composed output contains zero `## ADDED Requirements` / `## MODIFIED Requirements` headers.

Canonical hash trail (SHA-256): `ef884979…` → `a9731811…`.

## Task Completion Gate

Census of the archived `tasks.md`: **31 checked (`- [x]`) / 0 unchecked (`- [ ]`) / 31 total** — `Phase 1–6` plus `Phase 7 — Remediation (W1)`. No partial (`- [~]`) tasks. Nothing was reconciled or repaired during archive; the counts are reported as found.

## Boundary Confirmation

| Boundary | Evidence |
|---|---|
| No repository-root `openspec/` entry | `test -e openspec/changes/caracteristicas-plugin-scope` → absent; asserted by `CaracteristicaBoundariesTest::test_no_root_openspec_entry_exists_for_this_change` |
| No core change | Root `git status --short` empty; all writers are inside the `plugins/catalogo_core` tree |
| No `plugins/tarifario/` change | `git -C plugins/tarifario status --short` empty (its own repository) |
| Dependency direction unchanged | `catalogo_core` requires no plugin; `tarifario` keeps requiring `catalogo_core`; no new Composer package |
| No tarifario coupling / hardcoded plugin literal in the ownership rule | `CaracteristicaOwnership.php` imports only `FSFramework\Core\Plugins` and reads `origen` |
| Archive-induced test risk | **None.** The change's CAR-19 boundary gate (`test_no_root_openspec_entry_exists_for_this_change`) pins only the repository-root absence, not the active plugin path, so the archive move does not turn it red. Re-run after the move: `OK (9 tests, 151 assertions)`. (The `test_no_entry_exists_in_the_repository_root_openspec` gate belongs to the earlier `caracteristicas-producto` change and is already lifecycle-safe: it accepts the active or archived copy.) |

## Final Suites (carried from the highest-ranked source)

| Suite | Command | Result |
|---|---|---|
| `plugins/catalogo_core` | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **1012 tests / 4425 assertions — 0 failures**, 2 warnings, 1 skipped (pre-existing profile) |
| root | `ddev exec php vendor/bin/phpunit` | **2819 tests / 10771 assertions — 0 failures**, 24 skipped |
| focused boundary gate (post-archive re-run) | `... --filter CaracteristicaBoundaries` | **OK (9 tests, 151 assertions)** |

Known baseline, **not change-caused**: `ddev exec composer phpstan` exits `1` with a single error at `tests/Core/PluginEnableAjaxSafetyTest.php:308` (unmodified, outside the plugin boundary; `phpstan.neon` analyses `[src, tests]` only, never `plugins/`).

## Carried-forward Suggestions (non-blocking)

1. **S1 — `exportable_definitions()` has no production caller**, so the export surface emits no feature column for any definition (inert or not). Pre-existing; owned by the `articulos-excel-import-export` capability, unchanged by this change.
2. **S2 — per-row ownership-helper instantiation** (allocation-only, O(definitions), no query) in the resolver, the panel and the batch reader; not worth changing on the hot path.
3. **S4 — the boundary source-scan gate pins the three read-path wiring sites but not the Phase 7 fourth call site.** `CaracteristicaBoundariesTest:123-147` enumerates `CaracteristicaResolver.php`, `Controller/VentasCaracteristicas.php` and `CaracteristicaValorBatchReader.php`; the W1 fix added a fourth `ownership()->is_active(` call in `Services/CaracteristicaBackfillMigration.php`, deliberately outside the frozen list. Behavior is covered by `CaracteristicaBackfillTest`, so this is not a defect — optional follow-up: add the migration to the wiring-site list.
4. **S5 — the delta CAR-20 scenario test references do not name `CaracteristicaBackfillTest.php`.** The Phase 7 backfill test is a stronger boot-path assertion of the same no-write invariant, added after the delta was written. Documentation-only; the spec text is already satisfied.

Closed: **W1** (Phase 7) and **S3** (apply-phase count correction).

## Core `openspec/` Cleanliness

- `openspec/changes/` at the repository root contains no `caracteristicas-plugin-scope` entry — **absent**.
- The change exists **only** under `plugins/catalogo_core/openspec/` (now archived).
- The active `plugins/catalogo_core/openspec/changes/` directory no longer contains this change; `caracteristicas-post-soak-drop` and `remove-fs-demo` remain active by design.
- The ```plugins/tarifario/``` tree is untouched (its own repository, clean working tree).

## Archive Verification (mechanical)

```text
$ cp -R "plugins/catalogo_core/openspec/changes/caracteristicas-plugin-scope" "<snapshot>/source"
$ git mv "plugins/catalogo_core/openspec/changes/caracteristicas-plugin-scope" \
         "plugins/catalogo_core/openspec/changes/archive/2026-09-27-caracteristicas-plugin-scope"
fatal: directorio de fuente está vacío (source is untracked in the plugin repo)
git mv failed (status=128) — evaluating guarded mv fallback
source still present after failed git mv
SOURCE_UNCHANGED_VS_SNAPSHOT: OK
PLAIN_MV_OK
SOURCE_GONE_BEFORE_COMPARISON: OK
$ diff -r "<snapshot>/source" "plugins/catalogo_core/openspec/changes/archive/2026-09-27-caracteristicas-plugin-scope"
DIFF_R_OK: byte-identical (no output above)
```

The change directory is untracked in the plugin's git repository (`?? openspec/changes/caracteristicas-plugin-scope/`), so `git mv` refused; the skill's guarded fallback verified the source was byte-unchanged against the pre-move recursive snapshot, then performed a plain `mv`. Empty `diff -r` = byte-identical archive. `archive-report.md` is additive and excluded from the comparison. **No commit, push, PR, tag or release was performed** and no source file was modified by this phase.

## Closing Summary

`caracteristicas-plugin-scope` makes plugin-owned feature definitions **inert** whenever their owning plugin is not enabled, uniformly across the resolver, the management panel, the Excel import/export and the product-list columns, while preserving every row and value non-destructively and restoring them unchanged on re-enable. The rule is plugin-agnostic (registry + `origen`, fail-closed) and stays entirely inside `plugins/catalogo_core/`. W1 — the one gap that let the boot backfill write inert rows — was closed by Phase 7 before archive, and the plugin suite is green at 1012 tests / 4425 assertions. The single delta was merged into the canonical spec with the native composer (19 → 20 requirements, 63 → 75 scenarios; unrelated requirements preserved; markers retained), the archive move is byte-identical, and the repository-root `openspec/` holds no entry. Delivery remains human-owned.
