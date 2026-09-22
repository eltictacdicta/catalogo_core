# Delta specs — `gestion-idiomas-catalogo`

- **Change**: `gestion-idiomas-catalogo`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`)
- **Secondary plugin**: `plugins/tarifario` (its consumer deltas are authored under this change — same routing precedent as `caracteristicas-producto`)
- **Artifact store**: `openspec`
- **Core `openspec/`**: reference only — no entry is ever created there, in any phase.
- **Authoritative inputs**: `proposal.md` (authoritative), `preproposal.md` (confirmed D1–D12), `explore.md` (7 defects a–g, ~25 consumers), `research.md` (revision 2, outcome `partial`, recommendations R1–R9, gaps G1–G9).
- **Runner**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`

## Why the deltas live under two roots

The change is authored by `catalogo_core`, but it also mutates `tarifario`-owned
consumer behavior. The delta tree therefore carries two explicitly separated roots:

- `specs/catalogo-core/**` — deltas whose canonical specs live in
  `plugins/catalogo_core/openspec/specs/`. These are catalogo_core-owned.
- `specs/tarifario/**` — **cross-plugin** deltas whose canonical specs live in
  `plugins/tarifario/openspec/specs/`. The `tarifario/` folder name is the sync
  marker: any file under it is a tarifario-owned spec delta, never a catalogo_core
  spec.

`specs/catalogo-core/**` and `specs/tarifario/**` MUST NOT be written into either
canonical specs tree during the spec/tasks/apply phases. They are merged **only at
archive time**, after a clean `verify-report.md`, by the `sdd-archive` phase (which
must receive the explicit plugin paths — `gentle-ai sdd-status` only knows the root
`openspec/`).

## Archive sync mapping

| Delta file (this change) | Canonical target at archive | Action |
|---|---|---|
| `specs/catalogo-core/gestion-idiomas/spec.md` | `plugins/catalogo_core/openspec/specs/gestion-idiomas/spec.md` (new) | ADD — create source of truth |
| `specs/catalogo-core/articulo-detalle-canonico/spec.md` | `plugins/catalogo_core/openspec/specs/articulo-detalle-canonico/spec.md` | MODIFIED (ART-01, ART-05) |
| `specs/catalogo-core/articulos-excel-import-export/spec.md` | `plugins/catalogo_core/openspec/specs/articulos-excel-import-export/spec.md` | MODIFIED (Export Excel, Import wizard 3 pasos, Persistencia) |
| `specs/tarifario/catalogo-integration/spec.md` | `plugins/tarifario/openspec/specs/tarifario/catalogo-integration/spec.md` | ADDED (R-TAR-HOOK-013) |

**Not deltad (verified, no contradiction produced):**

- `plugins/tarifario/openspec/specs/catalogo-excel-import-export/spec.md` — **left
  untouched**. Its contract is the `tarif_catalogo_view` modal/export/import shape
  and field catalog, not the per-language write path. Tarifario's
  `Services/ExcelRowUpdater.php:234-290` already takes an explicit `$codidioma`
  and is the canonical correct implementation of the per-language description
  write, so no tarifario Excel delta is required. D8 changes only
  `catalogo_core`'s export/import shape.
- `plugins/tarifario/openspec/specs/tarifario/tarifa/spec.md` — read-only
  reference; `R-TAR-CUR-010` is referenced, not redefined.
- `articulo-lista-canonica` — unchanged by this change (no list-column change is
  proposed here; the `#idiomas` section is a management section, not a list
  column).
- `articulo-detalle-canonico` ART-02/03/04/06/07/08 and `articulos-excel-import-export`
  `Permiso can_import_export` — unchanged.

## Spec-phase decisions (explicit; not in the proposal verbatim)

The proposal is authoritative. The following resolutions make every requirement
testable and are recorded so `tasks` / `apply` / `verify` do not re-litigate them.

1. **Default resolution contract is observable, not algorithmic.** `GDI-02` fixes
   the *observable* totality ("always an active language code, never `false`, never
   an inactive code, deterministic for the same state") and leaves the fallback
   selection mechanism (seeded `es` vs first active by `codidioma`) to `design`.
   This keeps the requirement testable without locking an implementation.
2. **`set_descripcion_idioma()` mirror removal is a read/write boundary.**
   `GDI-06` states the base column is never mirrored; the tarifario
   `ExcelRowUpdater` still writes the base explicitly at `:256-259` under its own
   rule (`codidioma === 'es'` and base empty), which is preserved as the frozen
   legacy-compat behavior and is not a mirror of the language store.
3. **Search predicate isolation.** `GDI-09` requires exactly one language-agnostic
   description predicate in `Services/ArticuloSearchQueryBuilder.php`, with the
   deviation note inline, so the D11 deviation is discoverable from the code and
   cannot be "fixed" silently.
4. **Cache key family.** `GDI-08` names the cache family `articulos_search_*`
   (as implemented at `articulo.php:1185-1192,1336-1342`); the literal key format
   is not re-specified.
5. **Locale column pairing.** `D8`'s "one column per active language" is
   realized as one `descripcion_<codidioma>` **and** one `descripcion_corta_<codidioma>`
   column per active language (the suffix convention R4 records), ordered default
   first then the remaining active languages by `codidioma`.
6. **Tarifario requirement ID.** `R-TAR-HOOK-013` avoids the `011`/`012` IDs
   already reserved by `caracteristicas-producto` (unarchived) and
   `2026-09-16-mover-tarifa-catalogo-opcionales-a-tarifario` (archived but not
   merged into this canonical spec).
7. **Test files are pointers, not locks.** Every scenario names a target test
   path; `tasks` owns the final file layout, and `verify` may relocate a pointer
   as long as the scenario is covered.

## Carried gaps closed in this phase

`G1`, `G3`, `G6` and `G8` were explicitly accepted into this phase by
`preproposal.md` / `proposal.md` §"Evidence-backed obligations" item 8. They are
closed here, and no confirmed decision depends on them:

| Gap | Question left open by `research.md` | Closed by |
|---|---|---|
| **G1** | PrestaShop read-path fallback for an existing-but-empty `_lang` value | `GDI-05`/`GDI-07`: the fork chooses **absence = fallback** as a **design assumption** (R2/R3). The "absence = fallback" story rests on the write path (S5) and Odoo (S1); the read-path confirmation was never retrieved, so this is recorded as a design assumption, not evidence. |
| **G3** | Normative CSV/XLSX **header** convention for multilingual content | `articulos-excel-import-export` (Export Excel): the locale-suffixed header shape is a **local convention** (R4), validated only by in-repo precedent. |
| **G6** | Akeneo CSV header naming from an Akeneo-published source | Same as G3: no external source exists, so the convention is settled locally by R4. |
| **G8** | Behavior of "adding a language later" for already-exported files | `articulos-excel-import-export` (Import wizard 3 pasos): deactivated languages are omitted from new exports, and unknown/removed locale columns on import are **ignored** — never an error, never a silent language creation, never a registry mutation (R4). |

## Rules for the archive phase

- Merge every `specs/catalogo-core/**` delta into
  `plugins/catalogo_core/openspec/specs/` and every `specs/tarifario/**` delta into
  `plugins/tarifario/openspec/specs/` **only at archive**, after a clean
  `verify-report.md`.
- Strip the `## ADDED/MODIFIED/REMOVED Requirements` headers when merging; the
  canonical file is the post-change spec. The new `gestion-idiomas` spec is
  promoted from the delta's ADDED block.
- `MODIFIED` blocks are full requirement blocks (all scenarios copied and edited),
  so the archive may replace a requirement wholesale without losing scenarios.
- `R-TAR-HOOK-013` is appended to the tarifario `catalogo-integration` spec and its
  scope line (`R-TAR-HOOK-001…010`) is extended accordingly.
- Never create an entry in the repository-root `openspec/`.
