# Exploration: opcional-en-varios-grupos

- **Change**: `opcional-en-varios-grupos`
- **Plugin owner**: `catalogo_core`
- **Artifact store**: `openspec` (plugin-local)
- **Status**: exploration (read-only; no source, test or schema was modified)

## Goal

Make a catalog **opcional** (a variant such as "Roble") able to belong to **more
than one** optional group at the same time. Today `catalogo_opcionales.id_grupo`
is a single nullable integer (an opcional belongs to at most one group). The
product↔group relation is **already** many-to-many
(`catalogo_articulo_opcional_grupo`) and is explicitly **out of scope** — the
gap is **opcional↔group only**.

## Current State (evidence)

### Schema

- Opcional master carries a single nullable FK: `plugins/catalogo_core/model/table/catalogo_opcionales.xml:49-53` (`id_grupo integer NULL`). PK `id`, UNIQUE `codigo`.
- Group master: `plugins/catalogo_core/model/table/catalogo_opcional_grupos.xml` (PK `id`, UNIQUE `codigo`).
- Existing M:N bridge precedent for article↔group: `plugins/catalogo_core/model/table/catalogo_articulo_opcional_grupo.xml:28-35` — PK `id` + `UNIQUE (referencia, id_grupo)`. **No FOREIGN KEY is declared anywhere in these bridge tables**, and none is created by the migration service (`CatalogLegacyTableMigration::syncArticuloOpcionalGrupoTable()`, `Services/CatalogLegacyTableMigration.php:122-157`). Cascade is application-side, not DB-side.
- Another bridge precedent: `catalogo_opcional_familias` (opcional↔familia), model `model/core/catalogo_opcional_familia.php:13`.

### Model reads/writes of `id_grupo`

- `model/core/catalogo_opcional.php`
  - property `:25`; constructor `:44-46`, `:61`
  - `get_grupo()` `:65-73` (single group), `etiqueta_grupo()` `:75-83` (single label)
  - `assign_to_grupo(int)` `:201-222` (overwrites the single group, then `delete_all_from_opcional` `:218-219`)
  - `remove_from_grupo()` `:227-236` (nulls the single column)
  - `all_sin_grupo()` `:241-258` (`WHERE id_grupo IS NULL OR id_grupo = 0`)
  - `save()` writes `id_grupo` `:389` / `:392-400` and, when grouped, deletes direct article relations `:408-411`
  - `search(..., $id_grupo)` `:464-515` + `where_id_grupo()` `:526-539`
- `model/core/catalogo_opcional_grupo.php`
  - `delete()` `:154-167` (`UPDATE catalogo_opcionales SET id_grupo = NULL ...`, then deletes article-group rows)
  - `get_opcionales()` `:172-189`, `count_opcionales()` `:191-204`, `get_opcionales_activos()` `:224-242` — all query `id_grupo = <group>`
- `model/core/catalogo_articulo_opcional.php` treats "has `id_grupo`" as **not a loose opcional**: `validate_opcional_for_articulo()` `:47-60` (`:55`), `get_opcionales_sueltos_from_articulo()` `:114-129` (`:120`), `get_opcionales_directos_from_articulo()` `:175-190` (`:181`). Note `get_opcionales_from_articulo()` `:136-165` already unions loose + all assigned groups and **dedupes by opcional id** via `$seen` (`:141-158`) — the core "resolved for sale" read is already M:N-aware at the article level.
- `model/core/catalogo_opcional_familia.php` propagates by the single group: `add_with_propagation()` `:52-88` (`:67` read, `:70` `catalogo_articulo_opcional_grupo::add`), `remove_with_propagation()` `:90-120` (`:97`, `:100`).
- `model/tarif_opcional.php` extends `catalogo_opcional` and inherits `where_id_grupo()`; `search()` `:234-310` and `count_filtered()` `:324+` pass it through.

### Service (D12 visibility)

- `Services/CaracteristicaResolver.php::opcional_parents()` `:267-338` reads `SELECT id, id_grupo FROM catalogo_opcionales` `:277-282`, then maps the **single** group to the group's article parents `:293-323`. Under M:N this must union article parents from **all** groups an opcional belongs to. The method is documented as a **constant number of batched queries** independent of the opcional count `:256-266` — any change must preserve that contract (batch-reader tests depend on it).

### Controllers / trait

- `Controller/VentasOpcional.php`: preset from `?id_grupo` `:87-93`; save `sid_grupo` (single) `:216-227`; `addArticulo` blocks grouped opcionales `:319-322`.
- `Controller/VentasOpcionalGrupo.php`: `loadGrupoRelations()` `:87-110` (available list via `all_sin_grupo()` `:102`); `addOpcionalAlGrupo()` `:160-190` (`assign_to_grupo :178`); `removeOpcionalDelGrupo()` `:192-216` compares `$item->id_grupo !== grupo->id` `:205`.
- `Controller/VentasArticulo.php`: `loadOpcionalesDisponibles()` `:950-967` skips any opcional with `id_grupo` `:960`; group add/remove/toggle `:1012-1140` (article↔group only — untouched).
- `extras/VentasOpcionalesListTrait.php`: filter `b_id_grupo` `:69`, `:273`, `:280`; `search_opcionales()` passes it `:291-306`; `load_grupos_cache()` builds the single group-name map from `$opcional->id_grupo` `:313-330` (`:325`); create path `sid_grupo` `:772-787`.

### Views

- `View/ventas_opcional.html.twig`: single `<select name="sid_grupo">` `:192-204`; article tab gated on `id_grupo` `:333-340`; badge gated `:121-123`.
- `View/ventas_opcionales.html.twig`: create-modal single `<select name="sid_grupo">` `:104-114`; group filter `<select name="b_id_grupo">` `:333-351`; "Grupo" column `:382`, `:426-429`.
- `View/ventas_opcional_grupo.html.twig`: variant list via `get_opcionales()` `:93`, `:102-149`.
- `View/partials/articulos/tab_opcionales.html.twig`: variant list via `get_opcionales_activos()` `:40`.

### External consumer (separate plugin, in-repo)

- `plugins/tpvmod/lib/tpvmod_opcionales.php:261-278` iterates the article's groups and calls `$grupo->get_opcionales_activos()` per group; loose via `get_opcionales_sueltos_from_articulo()` `:280-292`. A grep across `plugins/` confirms **no PHP consumer of `catalogo_opcionales.id_grupo` outside `catalogo_core` and `tpvmod`** (tarifario references it only in archived SDD docs).

### Migration seam and live data

- `Services/CatalogLegacyTableMigration.php`: `migrateIfNeeded()` `:49-63`; `syncOptionalGroupColumns()` adds `id_grupo` `:209-228`; `syncArticuloOpcionalGrupoTable()` `:122-157` is the canonical "create bridge table (PostgreSQL + MySQL) with PK + UNIQUE, no FK" template; `hasPendingRows()` `:387-396` is the cheap idempotency pre-check; `tableExists()` `:573-585`; `addColumnIfMissing()` `:272-283`.
- `Init.php::ensureCatalogTables()` model list `:622-637` (must add the new bridge model).
- Live DB (ddev, database `db`): 1 group `Colores` (id 1); `Roble` (id 1) and `Blanco` (id 2) have `id_grupo = 1`; `Toallero` (id 5) `id_grupo = NULL`; article `0021` → group 1. So the backfill would create `(1,1)` and `(2,1)`; `Toallero` stays loose. There is **no multi-group row yet**, so M:N behavior cannot be observed on the current dataset.

## Decision Points

### DP-1 — Bridge table design

Proposed canonical name: **`catalogo_opcional_grupo_rel`** with model
`FSFramework\model\catalogo_opcional_grupo_rel`. Reasons: `catalogo_opcional_grupos`
is already the **master** table (name collision), and the `_rel` suffix mirrors the
project's `_rel` disambiguation while staying a pure membership bridge.

Schema (mirroring `catalogo_articulo_opcional_grupo.xml:28-35`):

```
id          serial   NOT NULL
id_opcional integer  NOT NULL
id_grupo    integer  NOT NULL
PRIMARY KEY (id)
UNIQUE (id_opcional, id_grupo)
```

- **No `obligatorio`**: obligation is per (article, group) and is already owned by `catalogo_articulo_opcional_grupo`.
- **No DB-level FK / ON DELETE CASCADE**: consistent with every existing bridge in the plugin (none declares FKs; cascade is application-side via `delete_all_from_*`). Adding a DB FK here would be the only one in the plugin and would diverge from the create-table template at `CatalogLegacyTableMigration.php:122-157`.
- Cascade responsibility: group deletion must delete its bridge rows (`catalogo_opcional_grupo::delete()`), opcional deletion must delete its bridge rows (`catalogo_opcional::delete()`).

Rejected alternatives:
- Comma/JSON column on `catalogo_opcionales` — not relational, no UNIQUE/FK/join, breaks the `b_id_grupo` filter and the group master's variant list.
- Reuse `catalogo_articulo_opcional_grupo` — different entity (article, not opcional); re-exploring it is explicitly out of scope.

### DP-2 — Migration

Add `migrateOpcionalGroupRelations(\fs_db2 $db)` to `CatalogLegacyTableMigration`
and call it from `migrateIfNeeded()` (`:49-63`), following the file's style:

1. Create `catalogo_opcional_grupo_rel` if missing, PG + MySQL variants, using `syncArticuloOpcionalGrupoTable()` `:122-157` as the template, then `touch` the model via `Init.php::ensureCatalogTables()`.
2. Cheap idempotency pre-check (LEFT JOIN on `id_opcional, id_grupo`, mirroring `hasPendingRows()` `:387-396`) to keep steady-state boot free of bulk writes.
3. Backfill: `INSERT INTO catalogo_opcional_grupo_rel (id_opcional, id_grupo) SELECT id, id_grupo FROM catalogo_opcionales WHERE id_grupo IS NOT NULL AND id_grupo > 0` with `ON CONFLICT (id_opcional, id_grupo) DO NOTHING` (PG) / `INSERT IGNORE` (MySQL). Re-runnable because of the UNIQUE constraint.
4. Ordering: the backfill **must run before** any `id_grupo` drop. `syncOptionalGroupColumns()` `:209-228` must stop being the source of truth for membership (see DP-3).
5. `migrateGroupedOptionalAssignments()` `:163-204` currently derives article↔group rows from `catalogo_opcionales.id_grupo`; it must read the bridge instead once `id_grupo` is retired.

### DP-3 — Keep `id_grupo` (dual source) vs drop it (single source)

**Consequences if `id_grupo` is kept as a legacy/primary column:**

| Consumer | Problem under dual source |
|---|---|
| `CaracteristicaResolver::opcional_parents()` `:277-323` | A single `id_grupo` cannot represent membership in 2 groups, so article parents from the second group are missed → wrong `en_catalogo`/`en_tarifa` visibility. It must read the bridge anyway. |
| `catalogo_opcional_grupo::get_opcionales()/count_opcionales()/get_opcionales_activos()` `:172-242` | Must JOIN the bridge; a stale single column silently omits the second group. |
| `VentasOpcionalesListTrait::load_grupos_cache()` `:313-330` | Single label per row; must become a label list. `id_grupo` is useless. |
| `where_id_grupo()` filter `catalogo_opcional.php:526-539` | Must become a bridge membership test (`EXISTS`/`NOT EXISTS`); `id_grupo = X` is incomplete. |
| `VentasArticulo::loadOpcionalesDisponibles()` `:960` | Must skip opcionales in **any** group → bridge anti-join. |
| `catalogo_articulo_opcional` loose reads `:55`, `:120`, `:181` | Must become bridge anti-joins. |
| `catalogo_opcional_familia` propagation `:67-70`, `:97-100` | Must iterate **all** the opcional's groups. |
| `catalogo_opcional_grupo::delete()` `:160-162` | Must delete bridge rows, not NULL a single column. |
| `tpvmod_opcionales.php:261-278` | `get_opcionales_activos()` must be bridge-backed or the second group shows nothing. |

**Every read/write path must change either way.** Keeping `id_grupo` as a
"primary" adds an arbitrary choice plus a sync burden, while any un-migrated reader
silently produces wrong results (a single-valued column cannot be the truth for a
set). It has no consumer value once the bridge exists.

**Recommendation: bridge is the single source of truth; retire `id_grupo`.**
Execute in two stages to de-risk (precedent: the project's deliberate
`caracteristicas-post-soak-drop` split, `changes/caracteristicas-post-soak-drop/proposal.md`):

- **This change**: add the bridge, backfill, rewrite every reader/writer to the bridge, and **freeze** `id_grupo` (stop reading and writing it). Keep the physical column so a rollback is possible; remove it from `save()`/`syncOptionalGroupColumns()` usage.
- **Follow-up soak-drop change**: `ALTER TABLE catalogo_opcionales DROP COLUMN id_grupo` (guarded/idempotent), plus deleting the dead column from `catalogo_opcionales.xml` and any residual shim.

If the team prefers a single-release cut, the drop can be appended to
`migrateOpcionalGroupRelations()` after a verified backfill — but the staged path
matches the repo's existing flag-retirement precedent and keeps the destructive DDL
reviewable in isolation.

### DP-4 — Semantics of "opcional sin grupo" (loose) under M:N

The only coherent definition: an opcional is **loose ⇔ it has no bridge row** (it
belongs to **no** group). "Belongs to at least one group" = grouped. "In all
groups" is meaningless.

Consequences:
- `all_sin_grupo()` `:241-258` and `where_id_grupo('0')` `:534-536` become
  `NOT EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r.id_opcional = o.id)`.
- The `b_id_grupo=<id>` filter becomes `EXISTS (... r.id_grupo = <id>)`.
- Group `activo` is **not** part of membership: an inactive group still owns its
  bridge rows; `activo` only affects listing/TPV presentation (mirrors
  `get_grupos_from_articulo()` filtering `g.activo = TRUE` at
  `catalogo_articulo_opcional_grupo.php:96`).
- "Grouped ⇒ no direct article relations" stays true: the first membership still
  triggers `catalogo_articulo_opcional::delete_all_from_opcional()` (today in
  `assign_to_grupo` `:218-219` and `save()` `:408-411`).

### DP-5 — TPV when an opcional belongs to two groups the article also has

Scenario: opcional X ∈ {G1, G2}; article A ∈ {G1, G2}. Options:

- **T1 Duplicate presentation** — X is a selectable variant in both groups. Risk:
  the user selects X in G1 and again in G2 → two cart lines / double charge, since
  `exclusivo` only clears selections *within* a group.
- **T2 Deduplicate per article** — X appears once (first group by `orden`); the
  second group renders empty and is skipped (`tpvmod_opcionales.php:267-269`
  already skips empty groups). No double charge, but a group can appear empty.
- **T3 Forbid the configuration** — reject adding X to a second group when both
  groups are assigned to a shared article. Brittle and couples group editing to
  article assignments.
- **T4 Present in both, dedupe at cart-add** — X is visible under each group it
  belongs to (management intent preserved), but the cart-add resolver ignores a
  second selection of the same opcional id. This mirrors the core
  `catalogo_articulo_opcional::get_opcionales_from_articulo()` `$seen` dedupe
  `:141-158` and guarantees a single charge.

**Recommended: T4**, with T2 as the simpler fallback if the TPV UI cannot
implement cross-group dedupe. This is a product decision and should be confirmed
with the maintainer during the spec phase.

## Cross-cutting Impact Inventory (all must move to the bridge)

- Models: `catalogo_opcional`, `catalogo_opcional_grupo`, `catalogo_articulo_opcional`, `catalogo_opcional_familia`, `tarif_opcional` (inherited `where_id_grupo`).
- Service: `CaracteristicaResolver::opcional_parents()` (keep the constant-query batching).
- Controllers: `VentasOpcional`, `VentasOpcionalGrupo`, `VentasArticulo` (loose availability only), `extras/VentasOpcionalesListTrait` (filter/column/create).
- Views: `ventas_opcional.html.twig`, `ventas_opcionales.html.twig`, `ventas_opcional_grupo.html.twig`, `partials/articulos/tab_opcionales.html.twig`.
- Migration/Init: `CatalogLegacyTableMigration`, `Init.php::ensureCatalogTables()`.
- Consumer: `tpvmod` (semantics only; catalog_core's bridge-backed readers keep it working).
- Tests encoding single-group semantics: `tests/CatalogoOpcionalGrupoTest.php`, `tests/fixtures/opcional_visibility_fakes.php` (`id_opcional => id_grupo` single map `:28-29`), `tests/VentasOpcionalesControllerTest.php` (`id_grupo` property `:243`), `tests/Controller/VentasOpcionalesExportParityTest.php` (`search` signature `:177`), `tests/Controller/...` master-state tests, `tests/Integration/CatalogoCoreHookMarkersTest.php` (`HookMarkerHostEntity id_grupo :483`).
- Spec locks to amend: `specs/opcionales-management/spec.md` OUM-02 `:39-58` (`b_id_grupo` "Sin grupo" sentinel), OUM-05 `:129-148` (optional group assignment on create), OPG-02 `:341-352` (single group-column map). Resolver semantics reference `specs/caracteristicas-producto` CAR-12 (`CaracteristicaResolver.php:183-200`).

## Recommended Direction

1. Add bridge **`catalogo_opcional_grupo_rel(id, id_opcional, id_grupo)`** with PK + `UNIQUE (id_opcional, id_grupo)`, no DB FK, app-side cascade — consistent with `catalogo_articulo_opcional_grupo`.
2. Add `migrateOpcionalGroupRelations()` to `CatalogLegacyTableMigration`, idempotent backfill from `id_grupo`, following the `syncArticuloOpcionalGrupoTable()`/`hasPendingRows()` patterns.
3. Make the bridge the **single source of truth**; rewrite all readers/writers; **freeze `id_grupo`** in this change and drop it in a follow-up soak-drop change.
4. Redefine loose as **no bridge row**; `b_id_grupo` filter uses `EXISTS`/`NOT EXISTS`; the "Grupo" column renders all labels (comma-separated badges) from one batched map.
5. Model API: replace single-valued `assign_to_grupo`/`remove_from_grupo`/`get_grupo`/`etiqueta_grupo`/`all_sin_grupo` with membership operations (`add_to_grupo`, `remove_from_grupo(int)`, `get_grupos()`, `grupos_labels()`, `all_sin_grupo()` bridge anti-join).
6. TPV: resolve T4 with the maintainer; the core resolver already dedupes by opcional id.

## Risks & Open Questions

**Risks**
- **Silent divergence** if any reader keeps using `id_grupo`; mitigations: complete grep audit, freeze-then-drop, tests seeded with a 2-group opcional.
- **Migration ordering / dialect**: the backfill must precede any drop; PG `ON CONFLICT` vs MySQL `INSERT IGNORE` both need the UNIQUE constraint to exist first.
- **Orphan memberships**: deleting a group or an opcional must delete bridge rows, otherwise opcionales stay "grouped" forever and loose anti-joins break.
- **Resolver query contract**: `opcional_parents()` must stay a constant number of batched queries; a per-opcional loop would break the batch-reader assertions.
- **`assign_to_grupo` side effect**: the "grouped ⇒ delete direct article relations" cleanup must fire on first membership and must not be lost when membership moves out of `save()`.
- **No M:N data in the live DB**: behavior cannot be observed on the current dataset; tests must seed a 2-group fixture.

**Open questions (confirm in spec)**
- Multi-select UI: `multiple` select vs checkbox list (both forms currently single-select).
- Should the group master's "available opcionales" list include opcionales already in **other** groups (today `all_sin_grupo()`)?
- Does removing the last group membership restore prior loose article relations? (Assumed no.)
- TPV multi-group semantics T1–T4.
- Drop timing: append to this change vs follow-up soak-drop.
