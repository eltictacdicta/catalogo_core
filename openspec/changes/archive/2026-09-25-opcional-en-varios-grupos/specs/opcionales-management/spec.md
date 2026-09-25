# Delta for opcionales-management

`opcional-en-varios-grupos` lets an opcional belong to **more than one** optional
group. Membership moves from the single-valued `catalogo_opcionales.id_grupo`
column to a new M:N bridge `catalogo_opcional_grupo_rel`, which becomes the single
source of truth. The legacy column is **frozen** this change (no reads, no writes;
physical column kept for rollback) and dropped by a follow-up soak-drop change
(confirmed `column_freeze_then_drop`).

Three existing requirements change: the group filter (`OUM-02`), opcional
creation (`OUM-05`) and the `Grupo` list column (`OPG-02`). Nine requirements are
added (`OPG-03`…`OPG-11`): the membership store and its idempotent backfill, the
loose definition, membership writes and cascade, the group master's bridge-backed
variant reads, the group editor's available list, the "grouped ⇒ no direct
article relations" cleanup, single-charge resolution, the group-union parent
resolution feeding D12, and the `tarifario` consumer guard.

`OUM-01`, `OUM-03`, `OUM-04`, `OUM-06`…`OUM-12` and `OPG-01` are unchanged.
`caracteristicas-producto` `CAR-12` needs no text delta: the D12 existential
union already handles multiple parents; only the resolver's parent discovery
changes (`OPG-10`). No requirement is removed. TPV presentation follows the
confirmed `tpv_dedupe_at_add`; the assignment UI follows `ui_checkbox_list`.
Research lane: skipped (`research_skip`).

## MODIFIED Requirements

### Requirement: OUM-02 — Filters and real-total pagination

The unified list MUST support `query` as the primary filter key with `search` as
an accepted alias, plus `b_codfamilia`, `b_codtarifa`, `b_id_grupo`,
`b_solo_activos` and `offset`, and MUST paginate by the true filtered total, not
by page size. `b_id_grupo` MUST accept a group identifier and a "Sin grupo"
sentinel. A group-identifier value MUST match membership through the M:N bridge
(`EXISTS` on `catalogo_opcional_grupo_rel`), so an opcional belonging to several
groups matches **each** of them; the "Sin grupo" sentinel (`b_id_grupo=0`) MUST
match only opcionales with **no** bridge row (`NOT EXISTS`), which is the loose
definition owned by `OPG-04`. An empty value MUST disable the group filter, and
`count_filtered()` MUST apply the same membership predicate as `search()`.
(Previously: `b_id_grupo` matched the single-valued `catalogo_opcionales.id_grupo`
column, so an opcional could match at most one group and a multi-group opcional
could not be found under its second group.)

#### Scenario: Filters are preserved across requests

- **GIVEN** the unified list
- **WHEN** a filter is applied through `query` (or its `search` alias) with `b_codfamilia`, `b_codtarifa`, `b_id_grupo`, `b_solo_activos` and `offset`
- **THEN** the filtered rows render and those keys are carried in the resulting URL

#### Scenario: Pagination uses the filtered count

- **GIVEN** a filter matching more rows than one page
- **WHEN** `search_opcionales` runs
- **THEN** the displayed total comes from `count_filtered(` over the same filters, and page links preserve the active filters

#### Scenario: Group filter matches every membership

- **GIVEN** an opcional `X` with bridge rows in groups `A` and `B`
- **WHEN** the list is filtered by `b_id_grupo=A` and then by `b_id_grupo=B`
- **THEN** `X` appears in both result sets
- **AND** the rendered total matches `count_filtered()` under the same filter

#### Scenario: "Sin grupo" sentinel returns only loose opcionales

- **GIVEN** an opcional in at least one group and a loose opcional with no bridge row
- **WHEN** the list is filtered by `b_id_grupo=0`
- **THEN** only the loose opcional is returned
- **AND** the filter reads the bridge membership, never `catalogo_opcionales.id_grupo`

### Requirement: OUM-05 — Opcional creation with validated prices

Creating an opcional MUST generate its code, reject duplicates, accept per-tarifa
`precio_tarifa_<code>` inputs parsed with comma/dot normalization, MUST accept a
price mode (`fijo`|`porcentaje`) with a validated percentage in percentage mode,
MUST accept **zero or more** group memberships selected through the active-groups
checkbox list (`ui_checkbox_list`) and MUST persist them together with the new
opcional in one save, and MUST be CSRF-guarded.
(Previously: creation accepted a single optional group assignment through one
`select`, so an opcional could be created in at most one group.)

#### Scenario: Creation persists validated per-tarifa values

- **GIVEN** a new opcional payload with a unique code, at least one familia, a price mode and per-tarifa inputs
- **WHEN** creation runs with a valid CSRF token
- **THEN** the opcional is saved with its mode and validated percentage
- **AND** each provided per-tarifa value is stored in the matching mode

#### Scenario: Creation persists multiple memberships together

- **GIVEN** a creation payload with groups `A` and `B` checked
- **WHEN** creation runs with a valid CSRF token
- **THEN** one bridge row per checked group exists for the new opcional, persisted in the same save
- **AND** no membership is derived from or written to `catalogo_opcionales.id_grupo`

#### Scenario: Creation without memberships yields a loose opcional

- **GIVEN** a creation payload with no group checked
- **WHEN** creation runs with a valid CSRF token
- **THEN** the opcional is saved with no bridge row and is treated as loose by `OPG-04`

#### Scenario: Malformed value or missing CSRF is rejected

- **GIVEN** a creation payload with a non-normalizable value or without a valid CSRF token
- **WHEN** creation runs
- **THEN** nothing is persisted and an explicit rejection is reported

### Requirement: OPG-02 — Grupo column in the unified list

The unified list MUST render a `Grupo` column showing **all** groups each opcional
belongs to (via the multi-membership label accessor), and MUST resolve those
labels from a single batched membership map loaded once per request instead of a
per-row or per-membership lookup (no N+1). An opcional with no bridge row MUST
render the placeholder, and an opcional in several groups MUST render every label.
(Previously: the column showed the one label of the single-valued
`catalogo_opcionales.id_grupo`, resolved from a single group-per-row map, so a
multi-group opcional could never show more than one label.)

#### Scenario: Group column renders all labels from one batched map

- **GIVEN** a page of opcional rows: one loose, one in group `A`, and one in groups `A` and `B`
- **WHEN** the unified list renders
- **THEN** the loose row shows the placeholder, the single-group row shows `A`, and the multi-group row shows both labels
- **AND** the labels are resolved from one batched map loaded once per request, not one lookup per row or per membership
- Test: `plugins/catalogo_core/tests/VentasOpcionalesControllerTest.php`

#### Scenario: Multi-group labels do not depend on `id_grupo`

- **GIVEN** a multi-group opcional whose `catalogo_opcionales.id_grupo` column is `NULL`
- **WHEN** the list renders its `Grupo` cell
- **THEN** all bridge-backed labels render, proving the cell never reads the frozen column

## ADDED Requirements

### Requirement: OPG-03 — Opcional↔group membership store (M:N)

An opcional MUST be able to belong to two or more optional groups. Membership
MUST be stored in the bridge `catalogo_opcional_grupo_rel(id, id_opcional,
id_grupo)` with `PRIMARY KEY (id)` and `UNIQUE (id_opcional, id_grupo)`, no
DB-level foreign key and application-side cascade (mirroring
`catalogo_articulo_opcional_grupo`). The bridge MUST be the single source of
truth: the legacy `catalogo_opcionales.id_grupo` column MUST be frozen — no code
MUST read or write it — while the physical column MUST remain present for
rollback and be dropped only by a follow-up soak-drop change. The bridge MUST be
ensured by `catalogo_core/Init.php::ensureCatalogTables()` so consumers that
depend on `catalogo_core` boot with it present. A migration
(`CatalogLegacyTableMigration::migrateOpcionalGroupRelations($db)`, called from
`migrateIfNeeded()`) MUST create the bridge and backfill it from `id_grupo`
values greater than zero, idempotently (`ON CONFLICT … DO NOTHING` on
PostgreSQL, `INSERT IGNORE` on MySQL) and **before** any future drop of the
column.

#### Scenario: An opcional holds several memberships at once

- **GIVEN** an opcional with no bridge row
- **WHEN** it is added to groups `A` and `B`
- **THEN** two bridge rows exist for its id, one per group, and neither overwrites the other
- **AND** inserting the same `(id_opcional, id_grupo)` pair again is rejected by the UNIQUE constraint

#### Scenario: Bridge schema matches the plugin bridge convention

- **GIVEN** the created `catalogo_opcional_grupo_rel` table
- **WHEN** its schema is inspected on PostgreSQL and MySQL
- **THEN** it declares `PRIMARY KEY (id)` and `UNIQUE (id_opcional, id_grupo)`
- **AND** it declares no DB-level foreign key or `ON DELETE CASCADE`

#### Scenario: Backfill from id_grupo is idempotent

- **GIVEN** `catalogo_opcionales` rows with `id_grupo > 0` and no bridge row
- **WHEN** `migrateOpcionalGroupRelations()` runs, and then runs again
- **THEN** the first run creates exactly one bridge row per positive `id_grupo` and the second run adds none
- **AND** opcionales with `id_grupo IS NULL` or `id_grupo = 0` stay loose

#### Scenario: id_grupo is frozen but still present

- **GIVEN** the applied change
- **WHEN** the plugin source is audited for `catalogo_opcionales.id_grupo` reads/writes and the table is inspected
- **THEN** no code path reads or writes the column
- **AND** the physical column still exists so the change can be rolled back

### Requirement: OPG-04 — Loose opcional semantics under M:N

An opcional MUST be **loose if and only if it has no bridge row** (no membership
in any group). `all_sin_grupo()` and the "Sin grupo" filter MUST be the anti-join
`NOT EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r.id_opcional =
o.id)`; a membership test for group `g` MUST be `EXISTS (… r.id_grupo = g)`. Group
`activo` MUST NOT affect membership: an inactive group still owns its bridge rows
and its members stay non-loose; `activo` only affects listing and TPV
presentation.

#### Scenario: No bridge row means loose

- **GIVEN** an opcional with no bridge row
- **WHEN** `all_sin_grupo()` runs and any group membership test is applied
- **THEN** the opcional is listed as loose and matches no group

#### Scenario: One membership is enough to stop being loose

- **GIVEN** an opcional with a single bridge row
- **WHEN** `all_sin_grupo()` runs
- **THEN** the opcional is excluded from the loose list
- **AND** the anti-join reads the bridge, never `catalogo_opcionales.id_grupo`

#### Scenario: Inactive group still owns membership

- **GIVEN** a group with `activo = FALSE` that owns a bridge row for an opcional
- **WHEN** the opcional's looseness is evaluated
- **THEN** it is not loose, because membership is independent of `activo`

### Requirement: OPG-05 — Membership set writes and cascade

The opcional create/edit form MUST save the checked membership set together
(adding new memberships and removing unchecked ones) so the persisted set matches
the submitted set. Single-valued membership operations MUST be replaced by set
operations, and membership changes MUST NOT be persisted through
`catalogo_opcionales.id_grupo`. Deleting a group MUST delete that group's bridge
rows without touching memberships in other groups; deleting an opcional MUST
delete its bridge rows. Removing the last membership MUST make the opcional loose
again but MUST NOT restore loose article relations that were deleted when it first
became grouped.

#### Scenario: Editing replaces the membership set

- **GIVEN** an opcional currently in groups `A` and `B`
- **WHEN** the edit form is saved with only `B` checked
- **THEN** the bridge row for `A` is removed, the row for `B` remains, and no membership is written to `id_grupo`

#### Scenario: Group deletion removes only its own memberships

- **GIVEN** a group with bridge rows, including an opcional also in another group
- **WHEN** the group is deleted
- **THEN** that group's bridge rows are deleted
- **AND** the opcional's membership in the other group is untouched

#### Scenario: Opcional deletion leaves no orphan membership

- **GIVEN** an opcional with bridge rows
- **WHEN** the opcional is deleted
- **THEN** its bridge rows are deleted and no orphan `catalogo_opcional_grupo_rel` row remains

#### Scenario: Removing the last membership does not restore deleted direct relations

- **GIVEN** an opcional whose direct article relations were deleted when it first joined a group
- **WHEN** its last membership is removed
- **THEN** the opcional becomes loose
- **AND** the previously deleted direct article relations are not recreated

### Requirement: OPG-06 — Group master variant list is bridge-backed

The group master's variant reads MUST resolve membership through the bridge, so an
opcional that belongs to two groups appears under **each** of them:
`get_opcionales()`, `get_opcionales_activos()` and `count_opcionales()` MUST
return an opcional for every group it belongs to, independent of
`catalogo_opcionales.id_grupo`.

#### Scenario: Multi-group opcional appears under each group

- **GIVEN** an opcional `X` in groups `A` and `B`
- **WHEN** `A->get_opcionales()` and `B->get_opcionales()` are called
- **THEN** `X` is present in both lists and `count_opcionales()` counts it for each group

#### Scenario: Activos read is bridge-backed

- **GIVEN** an active opcional in two groups
- **WHEN** either group's `get_opcionales_activos()` is called
- **THEN** the opcional is returned for both groups, without reading the frozen column
- Test: `plugins/catalogo_core/tests/CatalogoOpcionalGrupoTest.php`

### Requirement: OPG-07 — Group editor available opcionales

The group editor's "available opcionales" list MUST contain the opcionales **not
already in this group**, so an opcional that belongs to another group can be added
to this one. It MUST NOT use the loose-only list (`all_sin_grupo()`).

#### Scenario: Member of another group is available

- **GIVEN** an opcional `X` that belongs only to group `B`
- **WHEN** the editor of group `A` loads its available list
- **THEN** `X` is offered, allowing a second membership

#### Scenario: Current member is excluded

- **GIVEN** an opcional `X` already in group `A`
- **WHEN** the editor of group `A` loads its available list
- **THEN** `X` is not offered
- Test: `plugins/catalogo_core/tests/CatalogoOpcionalGrupoTest.php`

### Requirement: OPG-08 — Grouped implies no direct article relations

A grouped opcional (at least one bridge row) MUST have no direct
article↔opcional relations. The cleanup MUST fire when the opcional gains its
**first** membership and MUST hold while it keeps any membership. Direct
article↔opcional reads and the direct-relation validation MUST treat "grouped" as
"has a bridge row" (the `catalogo_opcionales.id_grupo` predicate MUST be replaced
by a bridge anti-join).

#### Scenario: First membership removes direct relations

- **GIVEN** a loose opcional with direct article relations
- **WHEN** it gains its first group membership
- **THEN** its direct article relations are deleted

#### Scenario: Grouped opcional rejects a direct relation

- **GIVEN** an opcional with at least one membership
- **WHEN** a direct article↔opcional relation is requested for it
- **THEN** the request is rejected and no direct relation is stored

#### Scenario: Direct reads exclude grouped opcionales

- **GIVEN** a loose opcional and a grouped opcional, both directly related to an article
- **WHEN** the loose/direct reads run
- **THEN** only the loose opcional is returned, and the grouped-exclusion test reads the bridge

### Requirement: OPG-09 — Single charge per opcional across groups

When an opcional belongs to several groups that an article also has, the
opcional MUST be selectable under each of its groups (presentation), but the
resolved-for-sale reads MUST return it **at most once**, deduplicated by opcional
id across loose and all assigned groups, so a second selection of the same opcional
id is ignored at cart-add and the opcional is charged once (confirmed
`tpv_dedupe_at_add`).

#### Scenario: Resolved-for-sale read yields one occurrence

- **GIVEN** opcional `X` in groups `G1` and `G2` and an article assigned to both groups
- **WHEN** the article's resolved-for-sale opcionales are read
- **THEN** `X` occurs exactly once in the result
- **AND** it is charged once
- Test: `plugins/catalogo_core/tests/CatalogoOpcionalGrupoTest.php`

#### Scenario: Group-scoped presentation still lists each group

- **GIVEN** opcional `X` in groups `G1` and `G2`
- **WHEN** each group's active variant list is read for presentation
- **THEN** `X` is listed under both groups while the resolved-for-sale read keeps a single occurrence
- Test: `plugins/catalogo_core/tests/CatalogoOpcionalGrupoTest.php`

#### Scenario: Second selection of the same id is deduped

- **GIVEN** a selection adding opcional `X` under `G1` and then again under `G2`
- **WHEN** the cart-add resolution runs
- **THEN** only one line/charge for `X` results, mirroring the existing dedupe-by-id contract
- Test: `plugins/tpvmod/tests/TpvmodOpcionalDedupeTest.php`

### Requirement: OPG-10 — Group-union parents for D12 derivation

Under M:N, the D12 parent discovery (`CaracteristicaResolver::opcional_parents()`)
MUST union the article parents from **all** groups an opcional belongs to, in
addition to its direct article relations and family assignments. The method MUST
keep a **constant** number of batched queries, independent of the number of
requested opcionales, and MUST NOT fall back to a per-opcional loop. The
`caracteristicas-producto` `CAR-12` existential-union semantics (visible if any
parent is visible) MUST be unchanged.

#### Scenario: Parents are the union over all groups

- **GIVEN** opcional `X` in groups `G1` and `G2`, whose group parents are articles `A1` and `A2`
- **WHEN** `opcional_parents([X])` resolves
- **THEN** the parent referral set for `X` includes both `A1` and `A2` (deduplicated)

#### Scenario: Query count stays constant

- **GIVEN** a batch resolving many opcionales, including multi-group ones
- **WHEN** `opcional_parents()` runs
- **THEN** the number of batched queries is the same as for a single opcional (currently four)
- **AND** no query is issued per opcional
- Test: `plugins/catalogo_core/tests/OpcionalVisibilityDerivationTest.php`

### Requirement: OPG-11 — tarifario consumer compatibility guard

The `tarifario` consumer MUST keep seeing the same direct-opcionales set:
`get_opcionales_directos_from_articulo()` MUST keep returning the same result when
an opcional belongs to one or two groups (grouped opcionales are excluded), with
its "grouped ⇒ not loose" semantics preserved through the bridge anti-join. **Both**
`tarifario` bulk-delete paths that remove opcionales — `limpiar_opcionales()` and
`limpiar_todo()` — MUST also delete their `catalogo_opcional_grupo_rel` rows,
leaving no orphan memberships.

#### Scenario: Direct read is unchanged with one or two groups

- **GIVEN** an opcional `X` directly related to article `A`, then added to one group, then to a second group
- **WHEN** `get_opcionales_directos_from_articulo(A)` is read at each step
- **THEN** `X` is excluded once grouped and the result is identical with one or with two groups
- **AND** the predicate is a bridge anti-join, not `catalogo_opcionales.id_grupo`
- Test: new catalogo_core compatibility test seeding a 2-group opcional

#### Scenario: Bulk delete leaves no orphan memberships

- **GIVEN** grouped opcionales deleted through either tarifario bulk-delete path (`limpiar_opcionales()` or `limpiar_todo()`)
- **WHEN** the deletion completes
- **THEN** the corresponding `catalogo_opcional_grupo_rel` rows are deleted
- **AND** no orphan bridge row remains
- Test: new catalogo_core compatibility test seeding a 2-group opcional
