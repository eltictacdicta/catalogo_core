# Delta specs — `caracteristicas-producto`

- **Change**: `caracteristicas-producto`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`)
- **Secondary plugin**: `plugins/tarifario` (registers its two default boolean features; its consumer deltas are authored under this change)
- **Artifact store**: `openspec`
- **Core `openspec/`**: reference only — no entry is ever created there, in any phase.
- **Authoritative inputs**: `proposal.md` (authoritative), `preproposal.md` (confirmed D1–D12), `explore.md`, `research.md`.
- **Supersedes**: `plugins/tarifario/openspec/changes/mover-tarifa-catalogo-opcionales-a-tarifario/` (never applied).

## Why the deltas live under two roots

The change is authored by `catalogo_core`, but it also mutates `tarifario`-owned
behavior and specs (default-feature registration + consumer rewrites). The delta
tree therefore carries two explicitly separated roots:

- `specs/catalogo-core/**` — deltas whose canonical specs live in
  `plugins/catalogo_core/openspec/specs/`. These are catalogo_core-owned.
- `specs/tarifario/**` — **cross-plugin** deltas whose canonical specs live in
  `plugins/tarifario/openspec/specs/`. The `tarifario/` folder name is the sync
  marker: any file under it is a tarifario-owned spec delta, never a
  catalogo_core spec.

`specs/catalogo-core/**` and `specs/tarifario/**` MUST NOT be written into either
canonical specs tree during the spec/tasks/apply phases. They are merged **only at
archive time**, after a clean `verify-report.md`, by the `sdd-archive` phase (which
must receive the explicit plugin paths — `gentle-ai sdd-status` only knows the root
`openspec/`).

## Archive sync mapping

| Delta file (this change) | Canonical target at archive | Action |
|---|---|---|
| `specs/catalogo-core/caracteristicas-producto/spec.md` | `plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md` (new) | ADD — create source of truth |
| `specs/catalogo-core/articulo-lista-canonica/spec.md` | `plugins/catalogo_core/openspec/specs/articulo-lista-canonica/spec.md` | MODIFIED (ALC-02) |
| `specs/catalogo-core/articulos-excel-import-export/spec.md` | `plugins/catalogo_core/openspec/specs/articulos-excel-import-export/spec.md` | MODIFIED (Export Excel, Import wizard 3 pasos, Persistencia) |
| `specs/catalogo-core/opcionales-tarifa-management/spec.md` | `plugins/catalogo_core/openspec/specs/opcionales-tarifa-management/spec.md` | MODIFIED (6 requirements) + Purpose rewrite |
| `specs/catalogo-core/opcionales-management/spec.md` | `plugins/catalogo_core/openspec/specs/opcionales-management/spec.md` | MODIFIED (OUM-03, OUM-04, OUM-07) |
| `specs/catalogo-core/familias-tarifa-management/spec.md` | `plugins/catalogo_core/openspec/specs/familias-tarifa-management/spec.md` | MODIFIED (Toggle state management) |
| `specs/catalogo-core/articulo-tarifa-tab-management/spec.md` | `plugins/catalogo_core/openspec/specs/articulo-tarifa-tab-management/spec.md` | MODIFIED (ATT-02, ATT-04) |
| `specs/catalogo-core/articulo-detalle-canonico/spec.md` | `plugins/catalogo_core/openspec/specs/articulo-detalle-canonico/spec.md` | MODIFIED (ART-01, ART-02) |
| `specs/catalogo-core/opcionales-tarifa-selector/spec.md` | `plugins/catalogo_core/openspec/specs/opcionales-tarifa-selector/spec.md` | MODIFIED (OTS-02, OTS-05, OTS-09) |
| `specs/catalogo-core/catalogo-render-hooks/spec.md` | `plugins/catalogo_core/openspec/specs/catalogo-render-hooks/spec.md` | MODIFIED (Hook context contract) + ADDED |
| `specs/tarifario/catalogo-excel-import-export/spec.md` | `plugins/tarifario/openspec/specs/catalogo-excel-import-export/spec.md` | MODIFIED (Import …, Field catalog, Create path, create-path canary) |
| `specs/tarifario/catalogo-integration/spec.md` | `plugins/tarifario/openspec/specs/tarifario/catalogo-integration/spec.md` | MODIFIED (R-TAR-HOOK-002) + ADDED |

**Not deltad (verified, no contradiction produced):**

- `plugins/tarifario/openspec/specs/tarifario/tarifa/spec.md` — read-only
  reference. `R-TAR-CUR-008` (destination currency respected on
  `heredar_estructura()`) stays valid: the feature-clone step copies feature
  values, never `coddivisa` (see `CAR-10`).
- `articulo-lista-canonica` ALC-01/ALC-03/ALC-04/ALC-05/ALC-06/ALC-07/ALC-08 —
  unchanged by this change.
- `opcionales-tarifa-management` requirements not listed above (tags, unified
  prices, activation/order inheritance, history, configurator ownership,
  FK-safe bootstrap, dead-table references, tpvmod, bootstrap of the master
  table) — unchanged.
- `catalogo-render-hooks` frozen markers and their positions — unchanged; no
  new hook name is introduced.

## Spec-phase decisions (explicit; not in the proposal verbatim)

The proposal is authoritative. The following resolutions are needed to make every
requirement testable, and are recorded here so `tasks` / `apply` / `verify` do not
re-litigate them.

1. **Effective-value algorithm (D5 disambiguation).** D5 states "product >
   family/subfamily > global; within a scope: requested tarifa row > `DEF` row >
   `valor_defecto`/null". Spec-phase resolution: the scope walk runs **first**
   (each scope tries the requested-tarifa row, then the `DEF`-tarifa row, and the
   first scope that yields either wins), and `valor_defecto` → "no value" is the
   **terminal** fallback after every scope failed. Any other reading makes scope
   precedence inert whenever `valor_defecto` is set. Pinned by `CAR-06`
   scenarios.
2. **`scope` key materialization.** The brief's "(codtarifa, scope)" key is
   realized as three tables — `global`, `familia`, `articulo` (Option A′, D2) —
   so the scope is the table identity and `codtarifa` is a PK column in each.
   No polymorphic `scope` / `scope_ref` column is introduced.
3. **`bool` value representation.** One `valor varchar(255)` column; `bool`
   features are **closed**: they may only use the seeded predefined `'1'`/`'0'`
   pair (`Sí`/`No`), custom values are rejected for `tipo=bool`. Non-`bool`
   strings are normalized with `no_html`; `''` and `NULL` remain distinguishable
   (absence of a row is "no value", an empty custom string is a stored value).
4. **Value triad invariant.** A scope value row stores exactly one of `id_valor`
   (predefined, `custom = FALSE`) or `valor` (custom, `custom = TRUE`); a row
   with neither is invalid and MUST be rejected by `test()`.
5. **Read-through flag name.** A single boolean config constant gates legacy
   columns vs. the resolver. **The resolver is the default**: an undefined
   constant selects the feature path, and only an explicit `FALSE` opts out to
   the legacy columns (an emergency rollback switch — production must need no
   flag at all). Specs reference it as "the read-through flag"; the literal
   constant name is fixed at design. Behavior is what is spec-locked, not the
   constant name.
6. **`listable` column position.** Inside the existing
   `{% if fsc.tarifa_seleccionada %}` block of `View/ventas_articulos.html.twig`,
   **after** the `Catálogo` column and **before** the `Stock` column, ordered by
   `orden` ascending then `codigo` ascending. `listable` columns are per-tarifa
   and therefore only render when a tarifa is selected.
7. **Default feature identifiers.** `medidas` (catalogo_core, `string`),
   `en_catalogo` and `en_tarifa` (tarifario, `bool`). `origen` stores the
   registering plugin name; a definition with a non-empty `origen` MUST NOT be
   deletable but MAY be deactivated.
8. **`DEF` is the base tarifa.** Lazy inheritance uses the existing `DEF`
   tarifa convention; `copy_from_tarifa()` no-ops when origin == destination and
   is transactional (DELETE + INSERT…SELECT in one transaction).
9. **D12 existential union.** An opcional attached to N parents is visible when
   **at least one** parent's effective value is visible (existential union), not
   when all are. Stated explicitly in `CAR-12`.
10. **Backfill is additive-only.** Feature values never overwrite operator
    edits: every backfill statement is `INSERT … WHERE NOT EXISTS`; a second run
    is a no-op.
11. **Cross-plugin Purpose rewrites at archive.** At archive, the
    `opcionales-tarifa-management` Purpose sentence claiming that
    `familias-tarifa-management` and `articulos-excel-import-export` are
    unaffected MUST be rewritten (this change modifies both), and its boundary
    statement about `en_catalogo`/`en_tarifa` living only in
    `tarif_opcional_ext` MUST be dropped.

## Locked-contract verification (title match against canonical specs)

Every `MODIFIED` heading below was copied verbatim (including em-dashes and
suffixes) from the canonical file it targets. No intentional deviation remains;
the only non-requirement edit is the archive-time Purpose rewrite above.

| Delta requirement heading | Canonical file |
|---|---|
| `ALC-02 — Per-tarifa columns` | `articulo-lista-canonica/spec.md` |
| `Export Excel`, `Import wizard 3 pasos`, `Persistencia` | `articulos-excel-import-export/spec.md` |
| `Opcional domain models owned by catalogo_core`, `Per-tarifa opcional master table`, `Master state precedence`, `Master lifecycle: seed, lazy inherit, copy`, `Master consumption in UI and export`, `Unchanged boundaries and non-destructive migration` | `opcionales-tarifa-management/spec.md` |
| `OUM-03 — Per-tarifa state and price display`, `OUM-04 — CSRF-guarded state toggles`, `OUM-07 — Excel export parity` | `opcionales-management/spec.md` |
| `Toggle state management` | `familias-tarifa-management/spec.md` |
| `ATT-02 — catalogo_core owns the article Tarifas tab endpoint`, `ATT-04 — Canonical detail renders the tab and persists per-tarifa state` | `articulo-tarifa-tab-management/spec.md` |
| `ART-01 — Canonical detail absorbs article edit behavior`, `ART-02 — Canonical detail absorbs per-tarifa price/state and article-opcional editing` | `articulo-detalle-canonico/spec.md` |
| `OTS-02 — Scoped editable panel plus compact overview`, `OTS-05 — CSRF-guarded per-tarifa save`, `OTS-09 — Test-locked contracts intact` | `opcionales-tarifa-selector/spec.md` |
| `Hook context contract` | `catalogo-render-hooks/spec.md` |
| `Import Excel unificado reconoce En SAP, En Tarifa y En Catálogo`, `Field catalog — mappable field options (R8)`, `Create path on match-key miss (R6)`, `Canary contract del create path (extensión del precedente ExcelHierarchyService)` | `tarifario/openspec/specs/catalogo-excel-import-export/spec.md` |
| `Equivalent hook surface on ventas_opcional (R-TAR-HOOK-002) [PR1 point · PR2 injection]` | `tarifario/openspec/specs/tarifario/catalogo-integration/spec.md` |

## Rules for the archive phase

- Merge every `specs/catalogo-core/**` delta into
  `plugins/catalogo_core/openspec/specs/` and every `specs/tarifario/**` delta
  into `plugins/tarifario/openspec/specs/` **only at archive**, after a clean
  `verify-report.md`.
- Strip the `## ADDED/MODIFIED/REMOVED Requirements` headers when merging; the
  canonical file is the post-change spec.
- `MODIFIED` blocks are full requirement blocks (all scenarios copied and
  edited), so the archive may replace a requirement wholesale without losing
  scenarios.
- Apply the Purpose rewrite from spec-phase decision 11 when merging
  `opcionales-tarifa-management`.
- Run the dead-column grep gate (no `en_catalogo` / `en_tarifa` reads remain on
  the dropped tables) before and after the merge.
- Never create an entry in the repository-root `openspec/`.
