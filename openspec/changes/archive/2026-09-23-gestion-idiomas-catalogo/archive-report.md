# Archive Report: gestion-idiomas-catalogo

| Field | Value |
|---|---|
| **Change** | `gestion-idiomas-catalogo` |
| **Archived to** | `plugins/catalogo_core/openspec/changes/archive/2026-09-23-gestion-idiomas-catalogo/` |
| **Archive date** | 2026-09-23 (ISO) |
| **SDD owner** | `plugins/catalogo_core/openspec/` — `ownership: plugin-local` |
| **Secondary plugin** | `plugins/tarifario` (cross-plugin delta into a separate git repository) |
| **Artifact store** | `openspec` |
| **Delivery strategy** | `auto-chain` / `stacked-to-main` |
| **Final verdict at close** | Implementation **PASS**; archive blocker **RESOLVED**; cycle **COMPLETE** |

---

## 1. Final state

The change is archived. All four deltas were merged into their canonical specs with
lossless composition, the change directory was moved byte-identically into the archive,
and both plugin suites were re-run green after the move.

| Metric | Result |
|---|---|
| catalogo_core plugin suite (`-c plugins/catalogo_core/phpunit.xml`) | **920 tests / 0 failures**, exit 0 (2 warnings, 1 skip — pre-existing) |
| tarifario plugin suite (`-c plugins/tarifario/phpunit.xml`) | **266 tests / 0 failures**, exit 0 (2 skips — pre-existing) |
| Requirements / scenarios verified | 18/18 and 54/54 |
| `tasks.md` checkboxes | **65 checked / 8 unchecked** — see §5 |
| Root `openspec/` entries for this change | **0** (confirmed; this is a plugin-local SDD) |

### Ordering constraint — SATISFIED

This change's `verify-report.md` recorded one archive blocker: its
`articulo-detalle-canonico`, `articulos-excel-import-export` and tarifario
`catalogo-integration` deltas had to merge **after** both sibling changes archived,
because all three siblings carried `articulo-detalle-canonico` deltas.

Both siblings are now archived:

- `plugins/catalogo_core/openspec/changes/archive/2026-09-23-caracteristicas-producto/`
- `plugins/catalogo_core/openspec/changes/archive/2026-09-23-articulo-detalle-tarifa-unificada/`

Their canonical syncs (commits `f369faf7` and `f3c36e61`) are ancestors of this change's
re-author commit. The ordering constraint that blocked the first attempt is therefore
unmet-no-longer: **the blocker is resolved by archival, not waived.**

---

## 2. The near-miss worth recording (why the first attempt blocked)

The first archive attempt **correctly blocked** and did not merge anything. The cause was
not a compose-tool failure: `gentle-ai sdd-archive-compose` exits `0` even over a stale
`MODIFIED` block. The failure was semantic and would have been silent.

A `MODIFIED` requirement block in OpenSpec is only safe while it remains a **full copy of
the current canonical requirement**. The delta blocks had been authored in `183bed1e`,
an ancestor of the two sibling canonical syncs (`f369faf7`, `f3c36e61`). Once the siblings
merged, the canonical requirements in `articulo-detalle-canonico`,
`articulos-excel-import-export` and tarifario `catalogo-integration` had moved ahead of the
delta copies. Composing them would have **replaced** the current canonical requirement with
the pre-sibling copy, silently dropping the sibling contribution:

| Target | Canonical | Stale block would have lost | Correct composed result |
|---|---|---|---|
| `articulo-detalle-canonico` | 26 scenarios | **5** | **28** (0 lost) |
| `articulos-excel-import-export` | 8 scenarios | **7** | **17** (0 lost) |
| tarifario `catalogo-integration` | 38 scenarios | 0 | **43** (0 lost) |

The 12 lost scenarios would have reverted two sibling contracts outright: the
`caracteristicas-producto` **feature-store** requirement (in
`articulos-excel-import-export`) and the **unified-pane** requirement (in
`articulo-detalle-canonico`).

**Fix**: commit `e268bc59` re-authored all `MODIFIED` blocks as verbatim full copies of the
*then-current* canonical plus this change's additive content. Non-loss was then proven
mechanically by compose-and-diff, and this archive run repeated that proof independently
before merging anything (see §3).

**Generalizable rule this incident establishes**: the compose exit code is not evidence of
non-loss for `MODIFIED` blocks. Losslessness has to be proven by comparing the
requirement/scenario sets between the canonical and the composed output. Only a
compose-and-diff proof is admissible.

---

## 3. Specs synced (lossless composition proof)

For each target, the canonical and the delta were composed to a **temporary** path first,
and the canonical requirement/scenario sets were compared against the composed output to
prove every canonical scenario survives verbatim. Only after that proof passed was the
composition merged in place. Nothing was Read/Edit-merged.

| Domain | Canonical | Action | Key | Final | Loss |
|---|---|---|---|---|---|
| `catalogo_core/specs/articulo-detalle-canonico` | 11 req / 26 scen | Updated (ART-01, ART-05) | MODIFIED ×2 | 11 req / **28** scen | **0** |
| `catalogo_core/specs/articulos-excel-import-export` | 4 req / 8 scen | Updated (Export Excel, Import wizard 3 pasos, Persistencia) | MODIFIED ×3 | 4 req / **17** scen | **0** |
| `catalogo_core/specs/gestion-idiomas` | — (new) | **Created** (ADD, no canonical counterpart) | new capability | **12 req / 33 scen** | n/a |
| `tarifario/specs/tarifario/catalogo-integration` | 11 req / 38 scen | Updated (R-TAR-HOOK-013 appended) | ADDED ×1 | **12 req / 43** scen | **0** |

Mechanical proof produced by this run, per target:

1. Requirements unaffected by the delta survived **byte-identical** in the composed
   output: `articulo-detalle-canonico` 9 ≥ 9, `articulos-excel-import-export` 1 ≥ 1,
   `catalogo-integration` 11 ≥ 11. Zero requirement bodies changed outside the delta.
2. Every canonical scenario heading is present in every composed output (`comm -23`
   between canonical and composed headings is empty for all three targets).
3. Scenario-body comparison: `articulo-detalle-canonico` **26/26** verbatim;
   `catalogo-integration` **38/38** verbatim; `articulos-excel-import-export` **8/8**
   verbatim after normalizing a single trailing blank line. The one raw difference
   (`#### Scenario: Invalid feature value does not corrupt the base save`) is a trailing
   empty line inserted by the delta before the next `### Requirement:` heading; every
   canonical line is verbatim present and no scenario text was altered. Recorded here
   rather than smoothed over, because a raw-comparison discrepancy must never be dismissed
   silently.

The new `gestion-idiomas` capability had no canonical counterpart, so it was promoted from
the delta's `ADDED` block by mechanical `cp` to a staged temp file plus `mv`, with
`diff -r` proving byte-identity (empty diff) before the move.

### tarifario canonical scope line — extended per the delta

The tarifario delta carried an explicit **"Archive instruction — canonical scope line"**
section. It was followed (the delta, not the change's own `specs/README.md`, which carries
stale claims — see §7). The stale scope line

> `Scope: exactly R-TAR-HOOK-001…010 (delivered by change tarifario-catalogo-hook-integration, archived 2026-09-03).`

was replaced by:

> `Scope: exactly R-TAR-HOOK-001…011 and R-TAR-HOOK-013`
> `(R-TAR-HOOK-001…010 delivered by change tarifario-catalogo-hook-integration, archived 2026-09-03; R-TAR-HOOK-011 delivered by caracteristicas-producto, archived 2026-09-23; R-TAR-HOOK-013 delivered by gestion-idiomas-catalogo).`

**No requirement was renumbered.** `R-TAR-HOOK-012` is claimed by the
archived-but-unmerged change `2026-09-16-mover-tarifa-catalogo-opcionales-a-tarifario` and
correctly remains **absent** from this canonical until that change is merged.

---

## 4. Archive move — mechanical, byte-identical

The change directory was moved with `git mv` (the commit pathspec records both the old and
new path). A recursive pre-move snapshot was taken, and `diff -r` between that snapshot and
the archived destination returned **empty** (no differences) — the only passing evidence
for a mechanical copy. No artifact content passed through a model Read/Write path.

```text
$ git mv openspec/changes/gestion-idiomas-catalogo \
         openspec/changes/archive/2026-09-23-gestion-idiomas-catalogo
$ diff -r "$snapshot_root/source" "$destination"
(empty output — byte-identical)
```

The `archive-report.md` you are reading is additive and was excluded from that comparison
because it did not exist in the pre-move snapshot.

### Path-pinned test check

No test in `plugins/catalogo_core/tests/`, `plugins/tarifario/tests/` or the root `tests/`
pins the **active** change path (`grep -rn "changes/gestion-idiomas-catalogo"` over the
three test trees → 0 non-archive hits). The move therefore cannot have broken a
path-pinned test; both suites were re-run after the move and are green (§1). This is
reported explicitly because the task brief required it: had a test pinned the active path,
the correct action was to report the file and lines, never to edit the test.

### Archived contents

- `proposal.md` ✅
- `preproposal.md` ✅
- `explore.md` ✅
- `research.md` ✅
- `design.md` ✅
- `apply-progress.md` ✅
- `specs/README.md` ✅
- `specs/catalogo-core/articulo-detalle-canonico/spec.md` ✅ (delta, as authored)
- `specs/catalogo-core/articulos-excel-import-export/spec.md` ✅ (delta, as authored)
- `specs/catalogo-core/gestion-idiomas/spec.md` ✅ (delta, as authored)
- `specs/tarifario/catalogo-integration/spec.md` ✅ (delta, as authored)
- `tasks.md` ✅
- `verify-report.md` ✅ (committed as part of this change's artifact set — see §6)
- `archive-report.md` ✅ (this file, additive)

---

## 5. `tasks.md` — 65 checked / 8 unchecked, all eight independently verified TRUE

`tasks.md` carries **65 checked** and **8 unchecked** rows. The 8 unchecked rows are
exactly the **No-Drift Checklist** at the bottom of the file — a set of refutable
assertions the verify phase ticks or refutes, **not implementation tasks**. No unchecked
implementation task remains. This is stated explicitly because the archive gate blocks on
stale unchecked *implementation* tasks, and these are not that.

All eight were independently verified **TRUE** against the repository, not against the
claim:

| # | Item | Result |
|---|---|---|
| 1 | No entry in the repository-root `openspec/` | ✅ TRUE |
| 2 | No `#[AdminOnly]` on `Controller/VentasArticulos.php` | ✅ TRUE |
| 3 | No backfill and no mirror of `articulos.descripcion` | ✅ TRUE |
| 4 | No new Composer dependency (so no `vendor/` commit obligation) | ✅ TRUE |
| 5 | Delta specs merged only at archive | ✅ TRUE |
| 6 | Schema files unchanged (no `codidioma` FK, D-01) | ✅ TRUE |
| 7 | Frozen markers unchanged | ✅ TRUE |
| 8 | `EXPORT_HEADERS` / `FIELD_CATALOG` not edited | ✅ TRUE |

No stale-checkbox reconciliation was performed, and `tasks.md` was not edited by this
phase — the checkbox state is the one `sdd-apply` left.

---

## 6. Final-state authority and post-verify work

Per the final-state authority hierarchy, the launch prompt's explicit final-state facts
outrank the intermediate snapshots. Where the snapshots disagreed with later work, the
final state is recorded here:

- **`verify-report.md` was untracked (`??`) and is now committed** in this change's
  artifact set (commit `b0c3338a` in the `catalogo_core` repository), so the audit trail
  closes with the report actually versioned.
- **The verify report's `blockers: 1` was an archive-ordering blocker only.** It is
  resolved by the siblings' archival (§1), not waived. `critical_findings: 0`.
- **`verdict: fail` in the verify envelope is not an implementation failure.** It was
  forced by two facts, both addressed or explicitly waived below: the sibling merge
  ordering (now resolved) and `composer phpstan` exiting `1` on a pre-existing core test
  (now explicitly waived — see §8).
- **All 32 recorded deviations are closed.** No deviation remains open, including the
  three the orchestrator had initially scoped out (deviation 22 → closed by `cf02b7f2`,
  deviation 26b → closed by `ed340ef9`, deviation 29 → closed by `1e86a4e6`).
- Working-tree noise: root `opencode.json` shows as modified (unrelated agent config) and
  is not part of this change.

---

## 7. `specs/README.md` stale claims — NOT relied upon

The change's own `specs/README.md` contains stale claims that this archive run
deliberately did **not** rely on, in favour of the tarifario delta's archive instruction:

- Its **"Spec-phase decisions" item 6** describes `R-TAR-HOOK-013` as avoiding
  "`011`/`012` reserved by `caracteristicas-producto` (unarchived)". `caracteristicas-producto`
  later took `R-TAR-HOOK-011` and is now archived; the delta's archive instruction — which
  correctly places `011` in the canonical and leaves `012` unmerged — was followed instead.
- Its **"Rules for the archive phase"** bullet describing the scope line as extending to
  `R-TAR-HOOK-001…010` is superseded by the delta's explicit replacement text.

The README is archived verbatim as an authored artifact; the stale claims are recorded
here so a future reader does not treat them as the closing contract.

---

## 8. Verification gate — waived item, recorded as explicitly waived

**`ddev exec composer phpstan` — exit 1. Explicitly waived, not a regression.**

- The single error is `tests/Core/PluginEnableAjaxSafetyTest.php:308`
  (`Method Tests\Core\AjaxGuardTestPluginManager::applyPluginSchemaUpdates() should return
  array{success: bool, changes: list<string>, errors: list<string>} but returns
  array{success: true, errors: array{}}.`).
- It is a **pre-existing core test** introduced by core release `14a4c7b7`, in a file this
  plugin-local change never touches. `phpstan.neon` declares `paths: [src, tests]`, so this
  gate does not analyse `plugins/` at all and is not plugin type-safety evidence.
- The maintainer chose **not to touch the core** for this change. It is therefore recorded
  as an **explicitly waived** out-of-scope gate, never as this change's regression.

**Root `Plugins` suite `OidcProvider` failures — likewise pre-existing.**
`ddev exec php vendor/bin/phpunit --testsuite Plugins` exits `1` on exactly the
pre-existing `OidcProvider` failure set (`OidcRegisterControllerMinimalClienteTest` ×2,
`OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` ×4)
in a plugin this change does not touch. A grep of the failure output for
`catalogo_core`/`tarifario` returns 0. Same disposition: pre-existing, not this change's
regression.

---

## 9. Proof executed vs. not executed

**Executed — deferred real-DB composed-SQL smoke.** The apply phase deferred the real-DB
proof of the new `COALESCE(d.descripcion, a.descripcion)` joins to verify, and it **was
executed** there on **MariaDB 10.11** (the `ddev` DB) and **PostgreSQL 16** (a throwaway
container), inside rolled-back transactions with no persistent change. It confirmed: the
non-default-language term is findable, the base-shim leg is findable, the join does not
multiply rows (`DISTINCT` collapses 2 → 1 for a two-translation article), the `COALESCE`
fallback returns the frozen base text when the configured default has no row, and the
exact composed queries from `tarif_tarifa_articulo::get`,
`tarif_articulo_precio::all_by_familias`, `tarif_actualizar_precios` and the
`tarif_grupo_articulo` search shape all execute with no "column is ambiguous" error and no
`DISTINCT`+`ORDER BY` rejection on either engine. **The deferred proof is closed.**

**Not executed — authenticated real-browser smoke.** The repository has no live
authenticated HTTP harness (the dev DB admin password is an unknown `argon2id` hash), so
the editor-selector round trip, the `#idiomas` POST mutations, the Excel export/import
with a configured default ≠ `es`, and the language-delete → search-invalidation path were
**not** exercised in a real browser. What was done instead: the application boots with the
change (`curl` against the login page → `200`; unauthenticated article page → `302` with no
fatal markers), and the UI behaviour is covered at render/source level by the suite. This
remains the **one honest evidence gap** of the change.

---

## 10. Size exceptions accepted

The change's PR chain ran under a session budget of **800 authored lines**
(`review_budget_lines: 800`, above the framework default of 400). Two slices were declared
and accepted over the pre-declared split boundaries:

- **`1a` — 1023 authored lines.** Accepted by the maintainer as a single review unit.
- **Slice 2 (`slice-4b` chain) — 862 lines.** The measured `4b` diff was 853 lines
  (`4b₁` 144 + `4b₂` 709); it was split at the pre-declared `4b₁`/`4b₂` boundary into two
  review units, with the slice recorded at **862** authored lines. Accepted by the
  maintainer.

No code, comment, blank line, doc or test was shrunk to meet a number.

---

## 11. Repository-root `openspec/` — confirmed clean

`grep -rl "gestion-idiomas-catalogo" openspec/` at the repository root returns **0
matches**. No entry — change, spec or archive — exists for this change in the root
`openspec/` tree. This is a plugin-local SDD and the core was never informed of it. The
still-active changes `caracteristicas-post-soak-drop` and `remove-fs-demo` were **not**
touched by this archive.

---

## 12. Safety constraints honoured

- **No destructive DDL executed.** No column dropped, no legacy data rewritten.
- **`plugins/catalogo_core/fsframework.ini` left at `1.6.3`.** It was **not** bumped:
  a version bump would fire the auto-wired CAR-15 clause-1 drop, which is explicitly out
  of scope for this archive.
- **Read-only on source code.** The only files written were the artifact trees and the
  canonical specs being merged (`articulo-detalle-canonico`, `articulos-excel-import-export`,
  `gestion-idiomas`, tarifario `catalogo-integration`).
- **No push, PR, tag or release** was performed in either repository. Commits are local
  and use explicit pathspecs only.

---

## 13. Commits created by this archive

**`plugins/catalogo_core` repository (`main`):**

| Commit | Purpose |
|---|---|
| `b0c3338a` | `docs(gestion-idiomas-catalogo): commit the verification report for the archive` |
| `4083f6c1` | `docs(catalogo_core): sync the gestion-idiomas-catalogo deltas into the canonical specs` |
| *(archive move commit)* | `docs(gestion-idiomas-catalogo): archive the change` — `git mv` into `archive/2026-09-23-gestion-idiomas-catalogo/` |

**`plugins/tarifario` repository (`master`):**

| Commit | Purpose |
|---|---|
| *(canonical sync commit)* | `docs(tarifario): sync the gestion-idiomas-catalogo delta into the canonical catalogo-integration spec` |

---

## 14. SDD cycle complete

The change was proposed, specified, designed, tasked, implemented, verified, and archived.
The ordering constraint that blocked the first attempt is satisfied; the composition that
the first attempt would have corrupted silently was proven lossless before merging; and the
two waived gates are recorded as waived rather than hidden.

**Ready for the next change.**

---

## Key Learnings

1. `gentle-ai sdd-archive-compose` exits `0` even over a stale `MODIFIED` block, so a
   compose exit code is never evidence of non-loss for modified requirements.
2. A `MODIFIED` requirement block is only safe while it remains a full copy of the current
   canonical; once a sibling canonical sync lands, stale blocks silently revert the sibling.
3. Losslessness must be proven by compose-to-temp-then-compare of the canonical and
   composed requirement/scenario sets before any in-place merge.
4. Trailing-blank-line differences can make a raw scenario-body diff report a false
   positive; normalize trailing whitespace before concluding content was lost.
5. The tarifario delta's inline archive instruction outranked the change's own
   `specs/README.md`, which still carried stale requirement-ID and scope-line claims.
