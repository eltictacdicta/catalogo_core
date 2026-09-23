```yaml
schema: gentle-ai.archive-result/v1
change: caracteristicas-producto
sdd_root: plugins/catalogo_core/openspec/   # owner (ownership: plugin-local)
secondary_root: plugins/tarifario/openspec/  # cross-plugin deltas (separate git repo)
archived_to: plugins/catalogo_core/openspec/changes/archive/2026-09-23-caracteristicas-producto/
archive_date: "2026-09-23"
verdict_at_verify: fail
blockers_at_verify: 1
critical_findings: 0
requirements_at_verify: 47/48
scenarios_at_verify: 153/154
delta_requirements: 48
delta_scenarios: 154
implementation_tasks_checked: 70
implementation_tasks_unchecked: 0
implementation_tasks_partial: 1   # 7.4 (report-update task; satisfied by the refreshed verify-report, kept as a historical marker)
composer_dependency_added: false
delivery: human-owned (committed locally only; not pushed, not PR'd, not tagged, not released)
final_suites:
  catalogo_core: "920 tests / 4013 assertions — 1 FAILURE (archive-induced CaracteristicaBoundariesTest lifecycle assertion), 2 warnings, 1 skipped"
  tarifario: "266 tests / 1093 assertions — 0 failures, 2 skipped"
final_state_commits:
  read_path_default_inverted: 17b07cdb
  car15_clause1_auto_wired: c4fa9ecd
  car14_text_fix_and_clause2_relocation: d044e2ce
  scenario_7_closed_end_to_end: 4ab5e97f
```

# Archive Report — `caracteristicas-producto`

**Change**: `caracteristicas-producto` (plugin-local SDD, `ownership: plugin-local`, `strict_tdd: true`)
**SDD owner**: `plugins/catalogo_core/openspec/` — the repository-root `openspec/` is **not** a tracker for this change and holds no entry for it.
**Cross-plugin touch**: `plugins/tarifario/openspec/` (secondary root, a **separate git repository**)
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-09-23-caracteristicas-producto/`
**Delivery**: commit / PR / push / tag / release are **human-owned** and were **NOT** performed by this archive phase. No version was bumped (`catalogo_core` stays at `1.6.3`), so the auto-wired clause-1 drop has not fired.

## Final-State Authority

`apply-progress.md` and `verify-report.md` are **intermediate snapshots**. The archived `verify-report.md` was written after `17b07cdb` + `c4fa9ecd` and **before** `d044e2ce` + `4ab5e97f`; its envelope (`verdict: fail`, `blockers: 1`, `47/48`, `153/154`) describes the change **at that time**. The final state is:

| Snapshot claim (archived `verify-report.md`) | Final state | Where the fix landed |
|---|---|---|
| Blockers (i): scenario #7 "Legacy workbook without feature columns imports unchanged" is PARTIAL (mapping-level only) | **CLOSED** — a real end-to-end assertion now covers the scenario | `4ab5e97f` (`test(catalogo_core): cover the legacy workbook import end-to-end`) |
| Blockers (ii): the two deferred clause-2 items (7.5 soak, 7.6 gated drop) remain unchecked tasks inside this change | **RESOLVED by relocation** — both were moved to the follow-up change `plugins/catalogo_core/openspec/changes/caracteristicas-post-soak-drop/` (tasks C2.1–C2.5); the parent now has **0 unchecked tasks** | `d044e2ce` (`docs(caracteristicas-producto): fix the read-path default in the delta and relocate the clause-2 tasks`) |
| Blockers (iii): the shared root type gate `composer phpstan` exits 1 on an out-of-scope core test | **EXPLICITLY WAIVED** by the maintainer (see "Waived / out-of-scope gates") | not this change's regression; the maintainer chose not to touch core |
| WARNING 1: the CAR-14 delta still annotated the legacy path as "(default)" | **CORRECTED** — the delta/design now state the feature path is the default and only an explicit `false` opts out | `d044e2ce` |
| `build_exit_code: 1` (root phpstan) and root **Plugins** suite red | unchanged, **out of scope**; both failure sets are outside `plugins/catalogo_core/**` and `plugins/tarifario/**` | see "Waived / out-of-scope gates" |

`critical_findings: 0` in the archived `verify-report.md` — no CRITICAL issue ever existed in the delivered scope.

Because the native dispatcher (`gentle-ai sdd-status`) only sees the repository-root `openspec/`, plugin SDDs are invisible to it; the archive-readiness signal used here is the orchestrator's launch prompt (the most recent account of the change), which outranks the intermediate snapshots and states that the three named blockers were cleared by the four commits above.

## Delivered Behavior (what shipped)

`catalogo_core` gains a first-class product-characteristics capability, and the legacy `en_catalogo`/`en_tarifa` visibility flags are superseded by it:

- **Definition + catalog (CAR-01..CAR-05)** — `catalogo_caracteristicas` (definition; closed type whitelist `bool|string`), `catalogo_caracteristica_valores` (predefined catalog), and the three tarifa-keyed scope tables (`catalogo_caracteristica_global`/`_familia`/`_articulo`), FK-safe and idempotently bootstrapped from `Init`.
- **Uniform read path (CAR-04, CAR-06, CAR-07)** — `CaracteristicaResolver` walks scopes nearest-first, honors `DEF` lazily, is cycle-guarded, and never persists.
- **Single write path (CAR-08, CAR-09)** — `CaracteristicaValorStore` materializes one row per scope, with lazy `DEF` inheritance and no fan-out.
- **Integration (CAR-10, CAR-16, CAR-17, CAR-18)** — tarifa-copy clone step (step 12 in `heredar_estructura()`), `listable` columns in the article list, `importable`/`exportable` columns in the Excel wizard/export, and the `ventas_caracteristicas` management panel (CSRF + neutral permission gate).
- **Supersession of the legacy flags (CAR-12..CAR-15)** — the read path **defaults to the feature tables** (`17b07cdb`: an undefined `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` selects the feature tables; only an explicit `false` opts out to legacy, an emergency rollback switch). `CAR-13` backfills legacy values additively (`INSERT … WHERE NOT EXISTS`), `CAR-14` dual-writes during the soak, `CAR-12` derives opcional visibility from the parent product (existential union), and `CAR-15` clause 1 **auto-drops** the D12 opcional flags through `CaracteristicaColumnDropMigration::migrateIfNeeded()` wired into `Init::upgrade()` only (never `Init::init()`), idempotent via `hasColumn()` and refusing under the explicit legacy opt-out.
- **Boundary (CAR-19)** — no core production file changed; the `catalogo_core → tarifario` class-reference baseline stays frozen at 6 pre-existing names.

**Production requires no flag**: the feature path is the default, and clause 1 is delivered at deploy without a console.

## Archive-time Spec Sync

Twelve delta files were merged into their canonical targets — **one created, eleven modified** — using the mandated native composer `gentle-ai sdd-archive-compose` (requirement matched by name; unrelated requirements preserved). All eleven composer runs exited `0`. The `## ADDED/MODIFIED Requirements` headers were stripped by the composition; the canonical file is the post-change spec.

| Delta (archived) | Canonical target | Action | Canonical after |
|---|---|---|---|
| `specs/catalogo-core/caracteristicas-producto/spec.md` | `plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md` | **CREATE** | 19 req / 63 scen |
| `specs/catalogo-core/articulo-detalle-canonico/spec.md` | `.../specs/articulo-detalle-canonico/spec.md` | MODIFIED (ART-01, ART-02) | 8 / 15 |
| `specs/catalogo-core/articulo-lista-canonica/spec.md` | `.../specs/articulo-lista-canonica/spec.md` | MODIFIED (ALC-02) | 8 / 15 |
| `specs/catalogo-core/articulos-excel-import-export/spec.md` | `.../specs/articulos-excel-import-export/spec.md` | MODIFIED (Export Excel, Import wizard 3 pasos, Persistencia) | 4 / 8 |
| `specs/catalogo-core/articulo-tarifa-tab-management/spec.md` | `.../specs/articulo-tarifa-tab-management/spec.md` | MODIFIED (ATT-02, ATT-04) | 7 / 16 |
| `specs/catalogo-core/catalogo-render-hooks/spec.md` | `.../specs/catalogo-render-hooks/spec.md` | MODIFIED + ADDED (Hook context contract, Article hook tab) | 8 / 18 |
| `specs/catalogo-core/familias-tarifa-management/spec.md` | `.../specs/familias-tarifa-management/spec.md` | MODIFIED (Toggle state management) | 10 / 24 |
| `specs/catalogo-core/opcionales-management/spec.md` | `.../specs/opcionales-management/spec.md` | MODIFIED (OUM-03, OUM-04, OUM-07) | 14 / 32 |
| `specs/catalogo-core/opcionales-tarifa-management/spec.md` | `.../specs/opcionales-tarifa-management/spec.md` | MODIFIED (6 requirements) + Purpose rewrite | 16 / 37 |
| `specs/catalogo-core/opcionales-tarifa-selector/spec.md` | `.../specs/opcionales-tarifa-selector/spec.md` | MODIFIED (OTS-02, OTS-05, OTS-09) | 10 / 18 |
| `specs/tarifario/catalogo-excel-import-export/spec.md` | `plugins/tarifario/openspec/specs/catalogo-excel-import-export/spec.md` | MODIFIED (4 requirements) | 11 / 41 |
| `specs/tarifario/catalogo-integration/spec.md` | `plugins/tarifario/openspec/specs/tarifario/catalogo-integration/spec.md` | MODIFIED + ADDED (R-TAR-HOOK-002, ADDED R-TAR-HOOK-011) | 11 / 38 |

Composition evidence (representative invocation, one per delta; all returned `exit 0` and wrote nothing on failure):

```text
$ gentle-ai sdd-archive-compose \
    --canonical plugins/catalogo_core/openspec/specs/<domain>/spec.md \
    --delta plugins/catalogo_core/openspec/changes/caracteristicas-producto/specs/<root>/<domain>/spec.md \
    --output <tmp>.spec.md
exit 0
```

- **No requirement was lost or added by mistake.** Requirement/scenario counts were compared before and after every composition: each MODIFIED target kept its requirement count and only grew in scenarios (a MODIFIED block is a full requirement block and was replaced wholesale, as the delta contract states); the two ADDED requirements increased their canonical files by exactly one requirement each.
- **Post-composition whitespace normalization.** The composer glued 10 `### Requirement:` headings to the preceding scenario line (the canonical file had a blank separator there). A single blank line was re-inserted per file; the normalization diff was verified to add **only** blank lines (one per file) and change no content.
- **Purpose rewrite (spec-phase decision 11).** `opcionales-tarifa-management` claimed `familias-tarifa-management` and `articulos-excel-import-export` were unaffected. That sentence was rewritten: `caracteristicas-producto` modifies both, and their per-tarifa visibility now resolves through the feature tables. (The second part of decision 11 — dropping a boundary statement about `en_catalogo`/`en_tarifa` living only in `tarif_opcional_ext` — was already absent from the canonical text; nothing to drop.)
- **New canonical derivation.** `caracteristicas-producto` had no canonical spec. It was created from the delta by a shell header transformation (never a model Read→Write): title `# caracteristicas-producto Specification`, an inserted `## Purpose` heading, and `## ADDED Requirements` → `## Requirements`. `diff` against the delta shows **only** those three header lines; every requirement and scenario body is byte-identical.

Canonical hash trail (before → after; SHA-256):

| Canonical spec | sha256 (before) | sha256 (after) |
|---|---|---|
| `catalogo_core/.../articulo-detalle-canonico/spec.md` | `7af70777…` | `f848862b…` |
| `catalogo_core/.../articulo-lista-canonica/spec.md` | `eabe52d4…` | `836b71fd…` |
| `catalogo_core/.../articulos-excel-import-export/spec.md` | `7679f8e2…` | `62ef7848…` |
| `catalogo_core/.../articulo-tarifa-tab-management/spec.md` | `38e72ada…` | `783d92d0…` |
| `catalogo_core/.../catalogo-render-hooks/spec.md` | `985f01d6…` | `d7618ce8…` |
| `catalogo_core/.../familias-tarifa-management/spec.md` | `94ca3d6f…` | `c92bcd10…` |
| `catalogo_core/.../opcionales-management/spec.md` | `2686bf76…` | `85d13d86…` |
| `catalogo_core/.../opcionales-tarifa-management/spec.md` | `47430360…` | `47aeb34a…` |
| `catalogo_core/.../opcionales-tarifa-selector/spec.md` | `16bdb92c…` | `58928eda…` |
| `catalogo_core/.../caracteristicas-producto/spec.md` | (did not exist) | `ef884979…` |
| `tarifario/.../catalogo-excel-import-export/spec.md` | `7321779c…` | `ffde269f…` |
| `tarifario/.../tarifario/catalogo-integration/spec.md` | `352a5b39…` | `1e0dca1d…` |

## Task Completion Gate

Census of the archived `tasks.md`: **70 checked (`- [x]`) / 0 unchecked (`- [ ]`) / 1 partial (`- [~]`)**.

- The two former open items **7.5** (soak) and **7.6** (gated drop) were converted from unchecked checkboxes into relocation notes pointing at `caracteristicas-post-soak-drop` (commit `d044e2ce`), so the parent carries **zero unchecked implementation tasks**. No exceptional checkbox reconciliation was required.
- **7.4** stays `[~]`: its blocked half ("update `verify-report.md` before opening PR-5") was completed by the refreshed `verify-report.md` archived here, and its other half (reversibility evidence) landed with `7.1b`. It is kept as written to preserve the author's historical marker; nothing in it is an open implementation task.

## Relocated Work (NOT archived with this change)

`plugins/catalogo_core/openspec/changes/caracteristicas-post-soak-drop/` is a **new, active** change that owns CAR-15 **clause 2** only. It MUST NOT be archived with this change:

| Relocated item | Carries |
|---|---|
| C2.1 (was 7.5) | the production **soak** of the feature path (watch `visibilidad_sin_sincronizar`) |
| C2.2 | the DEV-17 membership-filter rewrite onto the feature tables (ordered prerequisite) |
| C2.3 (was 7.6) | the gated clause-2 drop on a pre-drop dump |
| C2.4 | the clause-2 auto-wire into `Init::upgrade()`, exactly as clause 1 is now |
| C2.5 | verification of the above |

**This relocation is an accepted maintainer decision, not a defect.** Clause 2 needs a real soak window that cannot be compressed into this release; clause 1 does not, which is why it shipped here.

## Waived / Out-of-scope Gates (explicit)

| Gate | State | Why it does not block this change |
|---|---|---|
| Root type gate `ddev exec composer phpstan` → `exit 1` | **WAIVED by the maintainer** | The single error is `tests/Core/PluginEnableAjaxSafetyTest.php:308` (`AjaxGuardTestPluginManager::applyPluginSchemaUpdates()` return-shape drift), a **core** test added by core release commit `14a4c7b7` (2026-09-20). Neither plugin change touches any root `tests/` file, and `phpstan.neon` declares `paths: [src, tests]` so it never analysed `plugins/` at all. The maintainer chose **not to touch core**. |
| Root `Plugins` suite — 7 `OidcProvider` failures (per the archived `verify-report.md`) | Out-of-scope residual | None of the failures involves `catalogo_core` or `tarifario`; they are `OidcProvider` DB-migration assertions in a plugin this change never touches. |
| Destructive DDL | **Never executed** | The drop runner's dry run reports **12 gated columns still present** and emits **no DDL**; `catalogo_core` stays at version `1.6.3`, so `Init::upgrade()` (and therefore clause 1) has not run. |

## Final Suite Counts (re-run after the archive move)

| Suite | Command | Result |
|---|---|---|
| `plugins/catalogo_core` | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **920 tests / 4013 assertions — 1 FAILURE** (see below), 2 warnings, 1 skipped |
| `plugins/tarifario` | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **266 tests / 1093 assertions — OK** (2 skipped) |

### Archive-induced finding (new; surfaced honestly)

```text
1) Tests\CatalogoCore\CaracteristicaBoundariesTest::test_no_entry_exists_in_the_repository_root_openspec
the change must live in the plugin-local SDD root
Failed asserting that directory
"/var/www/html/plugins/catalogo_core/openspec/changes/caracteristicas-producto" exists.
```

The CAR-19 boundary test (authored by this change, WU-1) pins the **active** change path as proof of plugin-local ownership. The archive move relocates that directory into `changes/archive/2026-09-23-caracteristicas-producto/`, so the assertion — valid while the change was active — now fails. Its sibling assertion (no entry in the repository-root `openspec/`) still passes.

- **This is a consequence of archiving, not a regression in shipped behavior.** Before the move the suite was green at 920/0 (the `verify-report.md` baseline).
- **Not fixed here by design.** This phase is **read-only on source code**; its write scope is the change/archive artifact trees and the canonical specs. The fix belongs in a follow-up commit and is mechanical: make the ownership assertion lifecycle-safe (assert the plugin-local SDD root owns the change in either its active or archived location, or assert the archived directory), keeping the root-`openspec/` absence assertion unchanged. The one-line location is `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php:83-85`.

## Open Items / Residuals (carried forward)

1. **Archive-induced boundary-test failure** — see above; requires a follow-up test fix outside this phase's write scope.
2. **CAR-15 clause 2 staged** — relocated to `caracteristicas-post-soak-drop`; the destructive drops have never run and the live schema is not at clause parity (12 gated columns present).
3. **Clause-1 deploy gate is test-level, not runtime** — `migrateIfNeeded()` refuses only under the explicit legacy opt-out; the CAR-12 parity gate is a green test, not a deploy-time machine check.
4. **Reversibility proven against a `\fs_db2` double** — `CaracteristicaReversibilityTest` drives the real service against a recording double, not a real MySQL `DROP COLUMN`.
5. **DEV-17 prerequisite is an operator attestation**, not a machine check.
6. **`persist_feature_values()` has no production caller yet** (CAR-17 dispatch row hook not wired).
7. **`tasks.md`/`apply-progress.md` historical counts** (`768 → 786`, `153` vs `154`) are apply/verify-time deltas, not current totals; preserved as the historical record.

## Core `openspec/` Cleanliness

- `ls openspec/changes/` at the repository root lists 12 unrelated core changes; `openspec/changes/caracteristicas-producto` is **ABSENT**.
- The change exists **only** under `plugins/catalogo_core/openspec/` (archived) and its tarifario-targeted canonical specs under `plugins/tarifario/openspec/specs/`.
- The active `plugins/catalogo_core/openspec/changes/` directory no longer contains `caracteristicas-producto`; `caracteristicas-post-soak-drop` remains active by design.

## Archive Verification (mechanical)

```text
$ cp -R "openspec/changes/caracteristicas-producto" "<snapshot>/source"
$ git mv "openspec/changes/caracteristicas-producto" \
         "openspec/changes/archive/2026-09-23-caracteristicas-producto"
$ diff -r "<snapshot>/source" "openspec/changes/archive/2026-09-23-caracteristicas-producto"
DIFF_R_OK: byte-identical (no output above)
=== source gone before comparison: OK ===
```

Empty `diff -r` = byte-identical archive (verbatim `diff -r` output was empty; the only text above is the harness's own success marker). The source directory was gone before the comparison. `archive-report.md` is additive and excluded from the comparison.

Git commits performed by this phase (local only; **no push, no PR, no tag, no release**):

| Repo | Commit | Message |
|---|---|---|
| `plugins/catalogo_core` | `1d1e11b7` | `docs(caracteristicas-producto): record the refreshed verification report` |
| `plugins/catalogo_core` | `f369faf7` | `docs(catalogo_core): sync the caracteristicas-producto deltas into the canonical specs` |
| `plugins/tarifario` | `a9cf443` | `docs(tarifario): sync the caracteristicas-producto deltas into the canonical specs` |
| `plugins/catalogo_core` | (this commit) | `docs(caracteristicas-producto): archive the change` |

The sibling artifact `openspec/changes/gestion-idiomas-catalogo/verify-report.md` (a different, unarchived change) was deliberately left untouched.

## Closing Summary

`caracteristicas-producto` delivers a complete, uniform product-characteristics capability and makes the feature tables the **default** read source, so production needs no flag: the D12 opcional flags are dropped automatically at the next deploy through `Init::upgrade()`, the legacy values were backfilled additively, and the catalogo_core/tarifario consumers now resolve visibility through one resolver. CAR-15's soak-gated clause 2 was deliberately staged into the follow-up `caracteristicas-post-soak-drop` change (a maintainer decision, not a defect), and the shared root type gate that is red is an out-of-scope core test the maintainer explicitly waived. All twelve delta specs were merged into their canonical targets with the native composer, no requirement was lost, and the archive move is byte-identical. The one new open item is an archive-induced assertion in this change's own CAR-19 boundary test, which needs a one-line follow-up fix in a later commit.
