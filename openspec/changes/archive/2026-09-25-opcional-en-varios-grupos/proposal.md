# Proposal: Optional belongs to multiple optional groups (M:N)

- **Change**: `opcional-en-varios-grupos`
- **Owner (SDD)**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Consumer plugin touched**: `tarifario` (two bulk-delete cleanups; no signature change)
- **Artifact store**: `openspec` (plugin-local; core `openspec/` untouched)
- **Source of truth**: `exploration.md`, `pending-decisions.md`, `compatibility-tarifario.md` in this change dir
- **Research**: skipped per `research_skip` — no external citations

## Intent

An opcional can belong to at most one optional group today:
`catalogo_opcionales.id_grupo` is a single nullable FK
(`model/table/catalogo_opcionales.xml:49-53`). The product↔group relation is
already M:N (`catalogo_articulo_opcional_grupo`), so the catalog can assign a
product to several groups but a variant can only live in one. The gap is
**opcional↔group only**.

Every reader and writer of `id_grupo` must change either way (a single-valued
column cannot be the truth for a set), so this change replaces the column with a
membership bridge as the **single source of truth**: `id_grupo` is frozen this
change and physically dropped in a follow-up soak-drop change (precedent:
`changes/caracteristicas-post-soak-drop/`).

## Scope

### In Scope

- New bridge `catalogo_opcional_grupo_rel(id, id_opcional, id_grupo)` with
  `PRIMARY KEY (id)` + `UNIQUE (id_opcional, id_grupo)`, **no DB-level FK**,
  app-side cascade — mirroring `catalogo_articulo_opcional_grupo.xml:28-35` and
  the create-table template `Services/CatalogLegacyTableMigration.php:122-157`.
- Idempotent migration + backfill from `catalogo_opcionales.id_grupo`, wired into
  `migrateIfNeeded()` and ordered **before** any future drop.
- Rewrite every `id_grupo` reader/writer to the bridge (models, resolver,
  controllers, trait, views). **Freeze** `id_grupo`: stop reading and writing it,
  keep the physical column for rollback.
- UI: active groups as a **checkbox list** on the opcional; memberships saved
  together (`ui_checkbox_list`). Grouped "Grupo" column renders all labels.
- TPV: `tpv_dedupe_at_add` — an opcional is visible under every group it belongs
  to; the cart resolver ignores a second selection of the same opcional id.
- `plugins/tarifario/Services/ArticuloListActionHandler.php` **both** bulk-delete
  paths — `limpiar_opcionales()` (`:2167-2238`) **and** `limpiar_todo()`
  (`:1742-2050`) — also delete `catalogo_opcional_grupo_rel` rows (AD-7/AD-14,
  `compatibility-tarifario.md` C2/C2b).
- `Init.php::ensureCatalogTables()` model list (`:622-637`) ensures the bridge.

### Out of Scope / Non-goals

- **No drop of `catalogo_opcionales.id_grupo`** in this change (frozen only).
- No change to the articulo↔group bridge (`catalogo_articulo_opcional_grupo`)
  semantics: the product↔group M:N relation is already done and untouched.
- No new Composer or external dependency.
- No new capability; no core `openspec/` entry.

## Capabilities

### New Capabilities

- None.

### Modified Capabilities

- `opcionales-management`: `OUM-02` (`b_id_grupo` filter becomes a bridge
  `EXISTS`/"Sin grupo" `NOT EXISTS` membership test), `OUM-05` (creation accepts
  **multiple** group memberships), `OPG-02` ("Grupo" column renders all labels of
  a multi-group opcional from one batched map). `caracteristicas-producto`
  `CAR-12` needs **no text delta**: group-mediated parents already resolve
  through the existing multi-parent existential union; only the resolver's
  implementation changes.

## Approach

1. **Bridge**: create `catalogo_opcional_grupo_rel` (PG + MySQL variants) via a
   new `migrateOpcionalGroupRelations(\fs_db2 $db)` in
   `CatalogLegacyTableMigration`, called from `migrateIfNeeded()` (`:49-63`).
2. **Backfill (idempotent)**: `INSERT INTO catalogo_opcional_grupo_rel
   (id_opcional, id_grupo) SELECT id, id_grupo FROM catalogo_opcionales WHERE
   id_grupo IS NOT NULL AND id_grupo > 0`, with `ON CONFLICT … DO NOTHING` (PG) /
   `INSERT IGNORE` (MySQL); guarded by a `LEFT JOIN` pre-check mirroring
   `hasPendingRows()` (`:387-396`). Re-runnable because of the UNIQUE constraint.
   `migrateGroupedOptionalAssignments()` (`:163-204`) reads the bridge, not
   `id_grupo`.
3. **Semantics**: loose ⇔ **no bridge row**. `all_sin_grupo()` becomes a
   `NOT EXISTS` anti-join; `where_id_grupo('<id>')` becomes `EXISTS`; the
   `b_id_grupo` "Sin grupo" sentinel maps to the anti-join. Membership operations
   replace the single-valued API (`assign_to_grupo`/`get_grupo`/`etiqueta_grupo`
   → `add_to_grupo`/`remove_from_grupo(int)`/`get_grupos()`/`grupos_labels()`).
4. **Cascade (app-side)**: group deletion deletes its bridge rows
   (`catalogo_opcional_grupo::delete()`); opcional deletion deletes its rows
   (`catalogo_opcional::delete()`). "Grouped ⇒ no direct article relations" still
   fires on first membership.
5. **Resolver**: `CaracteristicaResolver::opcional_parents()` unions article
   parents from **all** groups an opcional belongs to, preserving its
   **constant number of batched queries** contract.
6. **UI**: checkbox list of active groups on the opcional; group editor's
   available list = opcionales **not already in THIS group**.
7. **TPV**: presentation unchanged per group; cart-add resolver dedupes a second
   selection of the same opcional id (mirrors the `$seen` dedupe in
   `catalogo_articulo_opcional::get_opcionales_from_articulo():141-158`).

### Non-blocking defaults (from `pending-decisions.md`)

- Group editor available-list = opcionales not already in **this** group.
- Removing the last membership does **not** restore previously deleted loose
  article relations.
- Group `activo` does **not** affect membership; it only affects listing/TPV
  presentation.

## Affected Areas

### Consumers moving to the bridge (`plugins/catalogo_core/`)

| Area | Impact | Description |
|---|---|---|
| `model/core/catalogo_opcional.php` | Modified | Membership API, loose anti-join, `where_id_grupo`, create/save; stop writing `id_grupo` |
| `model/core/catalogo_opcional_grupo.php` | Modified | `delete()`, `get_opcionales()`, `count_opcionales()`, `get_opcionales_activos()` JOIN the bridge |
| `model/core/catalogo_articulo_opcional.php` | Modified | loose reads (`:55`, `:120`, `:181`) become bridge anti-joins (semantics preserved) |
| `model/core/catalogo_opcional_familia.php` | Modified | propagation iterates **all** the opcional's groups |
| `model/tarif_opcional.php` | Modified | inherits bridge `where_id_grupo`; `search()`/`count_filtered()` unchanged in shape |
| `Services/CaracteristicaResolver.php` | Modified | `opcional_parents()` unions all groups, constant queries |
| `Controller/VentasOpcional.php` | Modified | multi-membership save; `addArticulo` grouped check via bridge |
| `Controller/VentasOpcionalGrupo.php` | Modified | available list = not in this group; add/remove membership |
| `Controller/VentasArticulo.php` | Modified | `loadOpcionalesDisponibles()` uses bridge anti-join (article↔group untouched) |
| `extras/VentasOpcionalesListTrait.php` | Modified | `b_id_grupo` filter, all-labels map, create with memberships |
| `View/ventas_opcional.html.twig`, `View/ventas_opcionales.html.twig`, `View/ventas_opcional_grupo.html.twig`, `View/partials/articulos/tab_opcionales.html.twig` | Modified | checkbox list, group badges/column |
| `Services/CatalogLegacyTableMigration.php` | Modified | create + idempotent backfill |
| `Init.php` | Modified | `ensureCatalogTables()` ensures the bridge |
| `openspec/specs/opcionales-management/spec.md` | Modified | Delta merged at archive (OUM-02, OUM-05, OPG-02) |

### External consumer

| Area | Impact | Description |
|---|---|---|
| `plugins/tarifario/Services/ArticuloListActionHandler.php` | Modified | Both bulk-delete paths (`limpiar_opcionales()`, `limpiar_todo()`) also delete `catalogo_opcional_grupo_rel` rows |
| `plugins/tarifario/model/tarif_articulo.php:130-134` | Unchanged (guard) | `get_opcionales_directos_from_articulo()` semantics preserved via bridge anti-join |
| `plugins/tarifario/model/tarif_opcional.php` | Unchanged (guard) | never sets `id_grupo`; frozen write is transparent |

### Tests

`tests/CatalogoOpcionalGrupoTest.php`, `tests/fixtures/opcional_visibility_fakes.php`
(single `id_opcional => id_grupo` map `:28-29`), `tests/VentasOpcionalesControllerTest.php`,
`tests/Controller/VentasOpcionalesExportParityTest.php`,
`tests/Integration/CatalogoCoreHookMarkersTest.php`: updated or extended; **new**
compatibility tests seed a 2-group opcional and prove (a) tarifario's direct
read is unchanged with 1 or 2 groups, (b) the bulk-delete leaves no orphan
bridge rows.

## Risks

| # | Risk | Likelihood | Mitigation |
|---|---|---|---|
| R1 | Silent divergence: an un-migrated reader keeps using `id_grupo`. | High | Grep audit + freeze-then-drop + tests seeded with a 2-group opcional. |
| R2 | Orphan memberships on group/opcional/bulk delete. | Med | App-side cascade on both paths; tarifario bulk-delete cleanup; compatibility test. |
| R3 | Resolver query contract broken (per-opcional loop). | Med | Union all groups in the existing batched read; keep batch-reader assertions green. |
| R4 | Migration ordering/dialect: backfill after a drop, or UNIQUE missing before `ON CONFLICT`. | Med | Backfill first; create table + UNIQUE in one step; guarded pre-check; idempotent re-run. |
| R5 | TPV double charge when an opcional is selectable in two groups. | Med | `tpv_dedupe_at_add` cart resolver mirrors `$seen`; test a 2-group selection. |
| R6 | Lost "grouped ⇒ delete direct article relations" cleanup when membership leaves `save()`. | Med | Fire on first membership add; test the intentional cleanup. |
| R7 | Locked contract churn (group column/map, `search` signature). | Low | Append optional params only; update tests with the code. |

## Rollback Plan

No destructive DDL: `id_grupo` is frozen, not dropped, so the column and its data
survive. Rollback = `git revert` the change commit(s); the bridge becomes inert
and `id_grupo` remains the readable source. The backfill is idempotent and
additive (`ON CONFLICT DO NOTHING`), so re-running it is safe. The follow-up
soak-drop change is a separate release and is not a prerequisite for rollback.

## Dependencies

- `tarifario` depends on `catalogo_core` (direction unchanged); the bridge is
  ensured by `catalogo_core/Init.php::ensureCatalogTables()`.
- ddev, PHP 8.2+, Symfony 7.4, Twig 3, PHPUnit 11; no new Composer dependency.

## Open Questions

- Where exactly does the cart-add dedupe live in `tpvmod` (resolver vs add
  action), and can it read the id across groups without new queries? (spec phase)

## Success Criteria

- [ ] An opcional can belong to ≥2 groups; active groups render as a checkbox
      list and save together.
- [ ] Loose ⇔ no bridge row; `b_id_grupo` and "Sin grupo" filter through
      `EXISTS`/`NOT EXISTS`; "Grupo" column renders all labels from one map.
- [ ] `id_grupo` is frozen: no read/write in code; the column still exists.
- [ ] TPV shows the opcional under every group and charges it once.
- [ ] `get_opcionales_directos_from_articulo()` unchanged for tarifario with 1 or
      2 groups; bulk-delete leaves no orphan bridge rows.
- [ ] `CaracteristicaResolver::opcional_parents()` unions all groups with a
      constant query count.
- [ ] All artifacts stay under `plugins/catalogo_core/openspec/`; core
      `openspec/` untouched.
