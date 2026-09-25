# opcionales-management Specification

## Purpose

Unified opcionales (optional per-tarifa customer catalog) management owned by
`catalogo_core`: `ventas_opcionales` is the single canonical opcionales
management page, absorbing the tarifa selector, filters, real-total pagination,
per-tarifa master state and price display, CSRF-guarded toggles, creation,
deletion and Excel export, while `tarif_opcionales` is eliminated. The
capability also owns the opcional "Tarifas" tab on `ventas_opcional` (hooks and
the `tarif_opcional_tab` endpoint). It consumes the per-tarifa master model and
accessors owned by the `opcionales-tarifa-management` capability and does not
redefine them.

## Requirements

### Requirement: OUM-01 — Canonical unified opcionales management page

`ventas_opcionales` MUST be the single canonical opcionales management page,
served by `Controller/VentasOpcionales.php` (on `PageController`) plus its legacy
wrapper, and MUST absorb the tarifa selector with validated resolution: a
requested tarifa that is a member of the active set is used, otherwise resolution
MUST fall back to the default tarifa and then to the first active tarifa. No
second opcionales management page SHALL remain.

#### Scenario: Canonical page serves the absorbed behavior

- **GIVEN** catalogo_core active
- **WHEN** `page=ventas_opcionales` is requested
- **THEN** the modern controller and its legacy wrapper serve it with page data `name=ventas_opcionales` and `showonmenu=true`
- **AND** selector, filters, per-tarifa state/price, toggles, create, delete and Excel export are reachable from it

#### Scenario: Tarifa selector resolves with safe fallback

- **GIVEN** an active set of tarifas and a default tarifa
- **WHEN** the controller resolves the selected tarifa from `codtarifa`
- **THEN** a requested member is selected, while an unknown or absent value resolves to the default, or to the first active tarifa when no default exists

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

### Requirement: OUM-03 — Per-tarifa state and price display

The unified list MUST display `activa` resolved through the master model
`effective()` accessor. `en_catalogo` and `en_tarifa` MUST be displayed as a
derived **read-only** indicator resolved from the parent product's effective
feature values for the selected tarifa (D12 existential union; no opcional-owned
flag exists). The list MUST also display the selected tarifa's value in the
opcional's mode: a fixed price for a fixed-price opcional, or the effective
percentage label for a percentage-price opcional.
(Previously: `en_catalogo`/`en_tarifa` were read from the per-tarifa master
state; before the percentage fix, only a fixed price was displayed, so percentage
opcionales rendered a zero amount.)

#### Scenario: Effective master state drives the row badges

- GIVEN a row with a stored or inherited master `activa` for the selected tarifa
- WHEN the list renders
- THEN `activa` reflects `effective()` and the read performs no persistence

#### Scenario: Visibility indicator is derived and read-only

- GIVEN a row whose parent product's effective `en_catalogo` is TRUE
- WHEN the list renders
- THEN the catalog indicator shows visible for the selected tarifa
- AND the render writes no visibility flag and exposes no visibility toggle
- Test: `plugins/catalogo_core/tests/Controller/VentasOpcionalesControllerMasterStateTest.php`

#### Scenario: Selected-tarifa value renders in the right mode

- GIVEN a row for the selected tarifa
- WHEN the list renders
- THEN a fixed-price opcional shows its price and a percentage-price opcional shows its effective percentage label, never a zero currency amount

### Requirement: OUM-04 — CSRF-guarded state toggles

The surviving state toggle `toggle_activa` MUST be a POST mutation guarded by
CSRF validation and MUST persist through the master `set_activa()` accessor. The
`toggle_en_catalogo` and `toggle_en_tarifa` actions and their `set_en_catalogo()` /
`set_en_tarifa()` persistence MUST be removed: opcional catalog/tarifa visibility
is derived from the parent product and MUST NOT be mutable from the opcionales
list.
(Previously: the list exposed three toggles, including the two visibility toggles.)

#### Scenario: Valid toggle persists

- GIVEN a POST `toggle_activa` with `id` and `codtarifa` and a valid CSRF token
- WHEN the toggle action runs
- THEN the master `activa` is updated and the list redirects preserving the active filters

#### Scenario: Removed visibility toggles do not mutate anything

- GIVEN a POST using the retired `toggle_en_catalogo` or `toggle_en_tarifa` action
- WHEN the request is handled
- THEN no state is persisted and no opcional visibility flag is written
- Test: `plugins/catalogo_core/tests/Controller/VentasOpcionalesControllerMasterStateTest.php`

#### Scenario: Invalid or missing CSRF is rejected

- GIVEN a toggle POST with a missing or invalid CSRF token
- WHEN the toggle action runs
- THEN no state is persisted and an explicit rejection is reported

#### Scenario: Toggle action registry no longer lists visibility toggles

- GIVEN the controller's public toggle-action constant
- WHEN it is inspected
- THEN it contains `toggle_activa` and neither `toggle_en_catalogo` nor `toggle_en_tarifa`
- Test: `plugins/catalogo_core/tests/Controller/VentasOpcionalesControllerMasterStateTest.php`

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

### Requirement: OUM-06 — Permission-gated delete

Deleting an opcional from the unified list MUST require the delete permission and
MUST NOT proceed when the permission is absent.

#### Scenario: Authorized delete proceeds

- **GIVEN** a user with delete permission and an existing opcional
- **WHEN** the delete action is requested
- **THEN** the opcional is deleted and the list redirects preserving the active filters

#### Scenario: Unauthorized delete is blocked

- **GIVEN** a user without delete permission
- **WHEN** the delete action is requested
- **THEN** the opcional is not deleted and no destructive side effect occurs

### Requirement: OUM-07 — Excel export parity

Exporting the unified list MUST emit the header set `Codigo (No editar)`,
`Ref SAP:`, `Descripción`, `Precio`, `Familia`, `Subfamilia`, MUST use the value
of the selected tarifa in the opcional's mode (fixed price, or effective
percentage with a percentage number format), and MUST honor the active filters.
The export MUST NOT read or depend on any opcional `en_catalogo`/`en_tarifa`
flag: with the flags removed, the emitted header set, the row set and the
selected-tarifa value MUST remain identical to the pre-change output.
(Previously: the export did not state its independence from the opcional
visibility flags; before the percentage fix it always wrote the numeric fixed
price, silently exporting zero for percentage opcionales.)

#### Scenario: Export headers and selected-tarifa value

- GIVEN a selected tarifa and exported rows
- WHEN the Excel export is generated
- THEN the exact header set above is present and each row carries its selected-tarifa value with resolved familia/subfamilia
- AND percentage-mode rows use a percentage number format instead of a currency amount

#### Scenario: Export is independent of the removed visibility flags

- GIVEN the same opcional data before and after the flag removal
- WHEN the export is generated for the same filters and tarifa
- THEN the header set and the row set are identical
- Test: `plugins/catalogo_core/tests/Controller/VentasOpcionalesExportParityTest.php`

#### Scenario: Export inherits the active filters

- GIVEN active `query`, `b_codfamilia` and `b_solo_activos` filters
- WHEN the export is generated
- THEN only rows matching those filters are exported

### Requirement: OUM-08 — htmx 4 and Alpine CSP interaction

The unified list MUST use htmx 4 for filter and mutation flows and Alpine CSP for
client logic, and MUST NOT use htmx v2 patterns.

#### Scenario: Filters swap the body and push the URL

- **GIVEN** the unified list filters
- **WHEN** they are inspected
- **THEN** they issue `hx-get` with a body target/select, a swap and URL push

#### Scenario: Mutations use POST

- **GIVEN** the toggle/create/delete/export flows
- **WHEN** they are inspected
- **THEN** mutations are issued through `hx-post` or `htmx.ajax('POST')`

#### Scenario: Alpine CSP and event hygiene

- **GIVEN** the unified list script and markup
- **WHEN** inspected
- **THEN** component logic is registered via `Alpine.data()` from a nonce'd classic script behind an `alpine:init` guard with `x-cloak`, no `bootbox` remains
- **AND** only colon event names (`htmx:after:swap`, `htmx:after:request`) appear, v2 names are absent, and no `|raw` is used

### Requirement: OUM-09 — tarif_opcionales elimination and link repointing

`tarif_opcionales` MUST be eliminated (controller, view and list-specific tests
removed, no redirect alias) with its `fs_page` row retired idempotently from
`catalogo_core/Init.php::upgrade()`, and every link or `url()` fallback MUST be
repointed.

#### Scenario: Page is gone and its registry row retired idempotently

- **GIVEN** the applied change
- **WHEN** `page=tarif_opcionales` is requested and `Init::upgrade()` runs twice
- **THEN** no `tarif_opcionales` controller or view exists, the `fs_page` row is retired on the first run and the second run is a no-op
- **AND** no entry for this change exists in the core `openspec/`

#### Scenario: References repointed, historical DB names untouched

- **GIVEN** `tarif_opcional::url()` and `tarif_opcional_precio::url()` no-id fallbacks, the catalogo_core opcional views (`tarif_opcional_edit`, `tarif_opcional`, `tarif_opcional_precios`) and the tarifario views (`tarif_actualizar_precios`, `tarif_articulo_precios`, `tarif_articulos`, `tarif_historial_precios`)
- **WHEN** they are inspected
- **THEN** each points to `ventas_opcionales` and no page link remains
- **AND** references to the historical `tarif_opcionales` DB table in migration services and dead-table tests are unchanged

### Requirement: OUM-10 — catalogo_core owns the opcional Tarifas tab

The opcional "Tarifas" tab on `ventas_opcional` MUST be owned by `catalogo_core`:
it MUST register the two opcional hooks (`ventas_opcional_tabs_after`,
`ventas_opcional_tab_pane_after`) behind an idempotent guard and MUST own the
opcional rows/save endpoint on `fbase_controller` with a currency helper.
`tarifario` MUST keep only the article surface of `tarif_tab_precios` and the
`ventas_articulo_*` hooks, byte-identical.

#### Scenario: catalogo_core registers and serves the opcional tab

- **GIVEN** catalogo_core active and tarifario inactive
- **WHEN** the hooks are registered and the opcional rows/save endpoint is reached
- **THEN** the two opcional hooks are registered at most once and the endpoint renders per-tarifa rows with the correct currency

#### Scenario: tarifario keeps only the article surface

- **GIVEN** tarifario's `Init.php`, hook templates and `tarif_tab_precios`
- **WHEN** inspected
- **THEN** it registers no opcional hook, keeps the `ventas_articulo_*` pair and the article path unchanged byte-for-byte

### Requirement: OUM-11 — Surviving opcional controllers preserved

`tarif_opcional_edit` MUST be the single canonical single-opcional detail and
MUST absorb the per-tarifa selector and scoped price/state panel. It, together
with `tarif_configurador_opcionales`, MUST stay `catalogo_core`-owned on
`fbase_controller`, reachable, with the prior selector and htmx work preserved.
`tarif_opcional_precios` (controller and view) MUST be deleted with no alias, its
links MUST be repointed to `tarif_opcional_edit` while preserving `codtarifa`, and
its `fs_page` row MUST be retired idempotently from
`catalogo_core/Init.php::upgrade()`.
(Previously: `tarif_opcional_precios` was locked as a surviving controller alongside `tarif_opcional_edit` and `tarif_configurador_opcionales`.)

#### Scenario: Canonical detail survives, precios is retired

- **GIVEN** the surviving opcional controllers and the deleted precios surface
- **WHEN** requested and `Init::upgrade()` runs twice
- **THEN** `tarif_opcional_edit` and `tarif_configurador_opcionales` are served by catalogo_core and extend `fbase_controller`, and the edit selector and htmx behavior are preserved
- **AND** no precios controller or view remains, its links point to `tarif_opcional_edit` with `codtarifa`, and the `fs_page` row is retired on the first run while the second run is a no-op

### Requirement: OUM-12 — Locked contracts stay green

The existing locked contracts MUST remain satisfied once updated coherently:
`VentasOpcionalesControllerTest`, `CatalogoCoreHookMarkersTest` (four frozen
markers), the repointed master-state and htmx list assertions, and the retired
`tarif_opcionales` slug. `TarifOpcionalesControllerContractTest` MUST lock
exactly two surviving opcional controller slugs (`tarif_opcional_edit`,
`tarif_configurador_opcionales`); the precios portions of the master-state and
htmx tests MUST be repointed at `tarif_opcional_edit` or removed; and references
to the historical `tarif_opcional_precios` database table MUST remain untouched.
(Previously: the survivor slug set was not fixed at two, and the precios controller was still locked by the contract tests.)

#### Scenario: Unified controller and view contract holds

- **GIVEN** the unified controller and view
- **WHEN** `VentasOpcionalesControllerTest` runs
- **THEN** file existence, `privateCore`, page-data, legacy wrapper subclass, `{{ csrf_field() }}` and absence of `|raw` all hold

#### Scenario: Repointed and frozen contracts hold

- **GIVEN** the contract, master-state and htmx tests plus the host views
- **WHEN** the plugin suite runs
- **THEN** the contract test locks exactly two survivor slugs, master-state and htmx assertions target the unified controller/view, and `CatalogoCoreHookMarkersTest` still passes on the four frozen host markers
- **AND** the precios portions are repointed at `tarif_opcional_edit` or removed, and historical database-table references are unchanged

### Requirement: OPG-01 — Percentage rendering and write integrity across surfaces

Every opcional price surface MUST render a percentage when the opcional's mode is
percentage, using the effective percentage helper, and a fixed price otherwise.
Every percentage write path MUST persist through the per-tarifa percentage writer
(setting `porcentaje` and zeroing/ignoring `precio`), and every fixed-price
writer MUST clear the row's `porcentaje`, so switching modes never leaves a stale
value. This MUST hold for the unified list, the unified create/edit form, the
injected Tarifas tab and the canonical detail's scoped panel, preserving the
single `guardar_precio_tarifa` form.

#### Scenario: Percentage surfaces render the effective label

- **GIVEN** a percentage-mode opcional shown in the unified list, the injected Tarifas tab and the canonical detail's scoped panel
- **WHEN** each surface renders its selected-tarifa value
- **THEN** each shows the effective percentage label, never a zero currency amount
- **AND** fixed-price surfaces keep rendering the tarifa price

#### Scenario: Percentage per-tarifa save persists

- **GIVEN** a percentage-mode opcional and a tarifa
- **WHEN** its per-tarifa percentage is saved from the unified form, the tab or the detail panel
- **THEN** `porcentaje` is stored for that tarifa and `precio` is zero/ignored
- **AND** the effective reader agrees with what the surface displays

#### Scenario: Fixed-price save clears a stale percentage

- **GIVEN** a row that previously held a percentage value
- **WHEN** a fixed price is saved for it
- **THEN** `porcentaje` is cleared and `precio` is stored

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
