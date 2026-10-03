# Archive Report: gate-tarifario-surfaces-by-plugin-activation

- Change: `gate-tarifario-surfaces-by-plugin-activation`
- SDD owner: `plugins/catalogo_core/openspec/` (plugin-local, `ownership: plugin-local`, `strict_tdd: true`)
- Artifact store: openspec
- Archived at: 2026-10-03
- Archive path: `plugins/catalogo_core/openspec/changes/archive/2026-10-03-gate-tarifario-surfaces-by-plugin-activation/`
- Nested repo: `plugins/catalogo_core`, branch `feat/gate-visibility-characteristics`
- Delivered commits: PR1 `717178d5`, PR2 `ea176fa9`
- Core `openspec/` entry: NONE (verified clean; correct for a 100% plugin-local change)

## Verdict

**`pass_with_warnings`** — carried from `verify-report.md` (2026-10-03).

All functional requirements (VCG-01..VCG-08, narrowed ALC-02, narrowed OUM-03)
are implemented and verified by static inspection plus a green isolated test run.
No CRITICAL findings. The two warnings are process/evidence gaps, not defects in
the delivered behavior, and per the verify Hard Rules they do not gate archive.

### Final-state test evidence

| Run | Result |
|-----|--------|
| Isolated change-specific set (11 files) | **119 tests, 759 assertions, 0 failures** |
| Full plugin suite | **1053 tests, 4648 assertions, 0 failures** (2 warnings, 1 skipped at framework level) |

## Requirements coverage (final)

| Req | Strength | Result |
|-----|----------|--------|
| VCG-01 — Active-visibility derivation | MUST | PASS |
| VCG-02 — Article list gate | MUST | PASS (source-verified) |
| VCG-03 — Article detail gate | MUST / MUST NOT | PASS (runtime partial render) |
| VCG-04 — Opcionales list gate | MUST / MUST NOT | PASS (source-verified) |
| VCG-05 — Opcional detail gate | MUST / MUST NOT | PASS (source-verified) |
| VCG-06 — Uniform across read modes | MUST | PASS |
| VCG-07 — Data preservation | MUST / MUST NOT | PASS (static diff audit) |
| VCG-08 — Ownership safety | MUST / MUST NOT | PASS |
| ALC-02 (narrowed) — Per-tarifa visibility columns | MUST / MUST NOT | PASS (source-verified) |
| OUM-03 (narrowed) — Visibility indicators | MUST / MUST NOT | PASS (source-verified) |

## Findings carried (unchanged at close)

| ID | Severity | Finding |
|----|----------|---------|
| **W1** | WARNING (process) | No `apply-progress.md` / TDD Cycle Evidence table exists for the change. Historical RED/GREEN cannot be independently replayed; the substance is independently verifiable and GREEN, so this is a process-evidence gap, not a functional defect. A missing report does not gate archive. |
| **W2** | WARNING (verification depth) | Three of the four gated templates (`ventas_articulos`, `ventas_opcionales`, `tarif_opcional_edit`) are verified by source-contract tests only; no Twig render harness exists for those full pages. Only `articulo_precios_rows` has a real-render test. |
| **S1** | SUGGESTION | Historical RED proof could be captured, or a minimal `apply-progress.md` added, for full strict-TDD completeness. |
| **S2** | SUGGESTION | Pre-existing `'tarifario'` strings in the two controller constructors/function names remain outside the gate and can create false positives in a naive repo-wide grep. |

No CRITICAL functional, data-loss, ownership, or out-of-scope regression
findings. No post-verify fixes were made after `verify-report.md`.

## Specs synced into canonical source of truth

| Domain | Action | Details |
|--------|--------|---------|
| `visibility-characteristic-gating` | **Created** | New full spec (VCG-01..VCG-08) copied mechanically; no canonical existed. |
| `articulo-lista-canonica` | **Updated** | MODIFIED `ALC-02` — added the visibility-conditional clause, updated `_Strength` to `MUST / MUST NOT`, added the "Visibility columns absent while the definition is inactive" scenario. All 8 ALC requirements preserved. |
| `opcionales-management` | **Updated** | MODIFIED `OUM-03` — added the visibility-conditional clause, expanded the `(Previously: ...)` note, added the "Visibility indicators absent while the definition is inactive" scenario. All 23 OUM/OPG requirements preserved. |

No dropped domain from `SUPERSEDED.md` was resurrected. The superseded
`tarifario-surface-gating` domain and the five dropped deltas remain absent.

Canonical spec paths:

- `plugins/catalogo_core/openspec/specs/visibility-characteristic-gating/spec.md` (new)
- `plugins/catalogo_core/openspec/specs/articulo-lista-canonica/spec.md` (ALC-02 merged)
- `plugins/catalogo_core/openspec/specs/opcionales-management/spec.md` (OUM-03 merged)

### Spec merge mechanism (process note)

The skill mandates native composition via `gentle-ai sdd-archive-compose` for
merging deltas into existing specs. The installed toolchain is **gentle-ai
4.0.0, which ships no SDD subcommands** (the command does not exist), so native
composition was unavailable. A surgical manual merge was performed instead, then
verified structurally:

- Requirement heading counts preserved: `articulo-lista-canonica` 8 → 8;
  `opcionales-management` 23 → 23.
- `diff` against a pre-merge snapshot shows only the intended ALC-02 / OUM-03
  additions; every unrelated requirement is byte-for-byte unchanged.
- The merged ALC-02 and OUM-03 blocks are identical in content to their delta
  requirement blocks (only the inter-requirement blank-line separator differs).
- The new full domain spec was copied with `cp` and `diff -r` returned empty
  (byte-identical).

The `fsframework-plugin-sdd` domain skill and `openspec-convention.md` define
the MODIFIED semantics (replace the full matching requirement block, preserve
the rest), which this merge follows. This deviation is recorded as a process
limitation, not a defect.

## Archive contents

- `proposal.md` — present
- `design.md` — present
- `explore.md` — present (superseded, kept for history)
- `rescope-explore.md` — present (authoritative narrow scope)
- `SUPERSEDED.md` — present
- `specs/` — present (3 delta domains: `visibility-characteristic-gating`,
  `articulo-lista-canonica`, `opcionales-management`)
- `tasks.md` — present, **439 lines, 0 unchecked tasks, 0 unfinished**
- `verify-report.md` — present
- `archive-report.md` — this file (additive; not part of the pre-move snapshot)

The change folder moved mechanically (`git mv` refused because the folder was
untracked; verified source unchanged, then plain `mv`). The `diff -r` readback
between the pre-move snapshot and the archived destination was empty.

## Delivery status

- Delivery is **human-owned**: the commits `717178d5` and `ea176fa9` were
  **not pushed** and **no PR was created** by this archive phase.
- Branch: `feat/gate-visibility-characteristics` in the nested
  `plugins/catalogo_core` repository.
- The archive phase did not commit the canonical spec merges; they remain
  working-tree changes alongside pre-existing concurrent workstream changes.

## Core openspec clean

`openspec/changes/gate-tarifario-surfaces-by-plugin-activation/` does **not**
exist in the core `openspec/`, and no core `openspec/` file references this
change. The change is 100% internal to `plugins/catalogo_core/`, so no core
entry was created (project anti-pattern avoided).

## SDD cycle complete

The change is archived. Implementation is complete within its narrowed scope.
Verification is `pass_with_warnings` with W1 (process evidence) and W2 (render
depth) as the only open findings; neither gates archive. No unfinished tasks and
no unresolved CRITICAL findings remain.
