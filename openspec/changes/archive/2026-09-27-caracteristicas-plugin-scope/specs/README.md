# Delta specs — `caracteristicas-producto`

- **Change**: `caracteristicas-plugin-scope`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`)
- **Artifact store**: `openspec`
- **Secondary plugin**: none — this change is **single-plugin**. No `catalogo-core/`
  sub-root marker is needed (unlike the archived `caracteristicas-producto` change,
  whose multi-root layout had `specs/catalogo-core/**` and `specs/tarifario/**`).
- **Core `openspec/`**: reference only — no entry is ever created there, in any
  phase (CAR-19).
- **Authoritative inputs**: `proposal.md` (authoritative), `explore.md`.
- **Canonical target**: `plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md`
  (READ ONLY in the spec phase; merged only at archive time).

## Archive sync mapping

| Delta file (this change) | Canonical target at archive | Action |
|---|---|---|
| `specs/caracteristicas-producto/spec.md` | `plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md` | ADD CAR-20; MODIFIED CAR-06, CAR-11, CAR-12, CAR-16, CAR-17, CAR-18, CAR-19 |

Nothing is written to either canonical specs tree during the spec/tasks/apply
phases. The delta is merged **only at archive time**, after a clean
`verify-report.md`, by the `sdd-archive` phase (which must receive the explicit
plugin paths — `gentle-ai sdd-status` only knows the repository-root `openspec/`).

## Delta contents

**ADDED**

- `CAR-20 — Enabled-plugin ownership scope` — a definition with a non-empty
  `origen` whose owning plugin is not enabled is inert across the resolver, the
  management panel, import/export and product-list columns; rows and values are
  preserved and restored on re-enable with no writes during the inert period;
  `origen = ''` rows stay always active; the rule reads the row's `origen` plus
  the framework enabled-plugin registry only. Scenarios: (a) owner disabled ⇒
  inert everywhere and `resolve()` returns `null`; (b) owner re-enabled ⇒
  definition and values restored unchanged with no writes; (c) operator row
  unaffected; (d) fail-closed when the registry is unavailable; (e) boundary
  assert (registry + `origen`, no tarifario coupling, no hardcoded plugin name).

**MODIFIED** (full requirement blocks copied from the canonical spec, then edited;
each carries a `(Previously: …)` line)

| Requirement | Nature of the modification |
|---|---|
| `CAR-06 — Effective-value precedence` | Adds the clause + scenario pinning that the owner-disabled path is the same `null` contract as a missing/inactive definition. |
| `CAR-11 — Default feature registration` | Adds the clause linking `origen` non-deletability to the enabled-scope rule, plus a scenario. |
| `CAR-12 — D12 opcional visibility derivation` | Adds a scenario confirming the owner-disabled path yields "not visible" with no new return value and no writes. |
| `CAR-16 — listable columns in the product list` | Adds a scenario: an owner-disabled `listable` definition emits no column; query count stays constant. |
| `CAR-17 — importable/exportable feature columns` | Adds a scenario: an owner-disabled definition contributes no import field, no alias and no export column. |
| `CAR-18 — Feature management panel` | Adds a scenario: owner-disabled definitions are not listed and their direct actions are refused/ignored; records the "no delete button for plugin-owned rows" observation as out of scope. |
| `CAR-19 — Plugin-local boundaries` | Adds a scenario asserting the ownership check reads `origen` + the framework registry and adds no tarifario coupling or hardcoded plugin name. |

**Not deltad:** `CAR-01` … `CAR-05`, `CAR-07` … `CAR-10`, `CAR-13` … `CAR-15` are
unchanged by this change.

## Out of scope (recorded, intentionally not specified here)

- **Deletion of definitions** — not part of this change.
- **The "no delete button" rendering (CAR-11)** — plugin-owned rows already render
  no delete button, so the panel shows none today. Recorded as an observation in
  the CAR-18 block; not altered by this change.

## Rules for the archive phase

- Merge `specs/caracteristicas-producto/spec.md` into
  `plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md` **only at
  archive**, after a clean `verify-report.md`.
- Strip the `## ADDED/MODIFIED Requirements` headers when merging; append CAR-20
  and replace the matched CAR-06/11/12/16/17/18/19 blocks wholesale.
- `MODIFIED` blocks are full requirement blocks (all scenarios copied and edited),
  so the archive may replace a requirement without losing scenarios.
- Never create an entry in the repository-root `openspec/`.
