# Delta for familias-jerarquia-catalogo

## ADDED Requirements

### Requirement: Hierarchical family tree UI (R-FJ-001)

The plugin MUST provide a family management page (extending the existing `VentasFamilias` page name) that renders and edits the mother/daughter hierarchy already present in the data layer (`familias.madre`, `familias.nivel`, `familia::hijas()`, `familia::aux_all()`), without modifying the base `familias` table schema or `model/core/familia.php` logic beyond read-only use. The tree MUST expose per-node: mother (`madre`), chapter (`capitulo`-equivalent display), level (`nivel`), display order (`orden`), and active flag (`activa`).

#### Scenario: Tree renders nested hierarchy

- GIVEN families `F-MADRE` (nivel 1) and `F-HIJA` with `madre = F-MADRE`
- WHEN the family tree page is requested
- THEN `F-HIJA` is rendered nested under `F-MADRE` and the node shows its `nivel`, `orden`, and `activa` state

#### Scenario: Re-parenting a family updates hierarchy

- GIVEN family `F-HIJA` with `madre = F-MADRE`
- WHEN an authorized user re-parents `F-HIJA` to `F-MADRE-2` via a CSRF-valid POST
- THEN `familias.madre` of `F-HIJA` is `F-MADRE-2` and the tree reflects the new nesting

#### Scenario: Cycle rejected

- GIVEN family `F-MADRE` with mother chain leading back to `F-HIJA`
- WHEN a save attempts to make `F-MADRE` a child of its own descendant `F-HIJA`
- THEN the save is rejected with an error message and no `familias` row is modified

#### Scenario: Base table untouched

- GIVEN the `familias` table schema before this capability
- WHEN the plugin installs and the tree UI is used
- THEN no column is added to or removed from `familias` (all new state lives in extension tables)

### Requirement: Per-list family structure extension table (R-FJ-002)

The system MUST persist per-price-list family structure in a new canonical table `catalogo_familia_estructura` (canonical equivalent of `tarif_tarifa_familia`), with columns mapping the legacy model (`codlista`, `madre`, `capitulo`, `nivel`, `en_catalogo`, `en_tarifa`, `activa`, `orden`), FK to `familias.codfamilia` and FK to `catalogo_listas_precio.codlista` (ON DELETE CASCADE). The table XML MUST live in `model/table/catalogo_familia_estructura.xml` and install after both `familias` and `catalogo_listas_precio`.

#### Scenario: One structure row per family per list

- GIVEN family `F1` and lists `L1`, `L2`
- WHEN structure rows are saved for `F1` on both lists
- THEN two rows exist in `catalogo_familia_estructura` and re-saving `F1/L1` updates the existing row (no duplicate)

#### Scenario: FK integrity to lists

- GIVEN a structure row referencing list `L1`
- WHEN `L1` is deleted from `catalogo_listas_precio`
- THEN the dependent `catalogo_familia_estructura` rows are deleted (cascade)

#### Scenario: Structure flags drive visibility

- GIVEN a structure row with `en_catalogo = true` and `en_tarifa = false` on list `L1`
- WHEN the catalog view for `L1` is rendered
- THEN the family appears in the catalog tree and is excluded from tariff-only views for `L1`

### Requirement: Per-list article-family assignment (R-FJ-003)

The system MUST persist per-list article-to-family-structure assignment in a new canonical table `catalogo_articulo_familia_estructura` (canonical equivalent of `tarif_tarifa_articulo`), FK to `articulos` (referencia) and FK to `catalogo_listas_precio.codlista` (ON DELETE CASCADE), installing after `catalogo_familia_estructura`.

#### Scenario: Assignment per article per list

- GIVEN article `A`, family structure `F1/L1`
- WHEN `A` is assigned to that structure
- THEN `catalogo_articulo_familia_estructura` contains one row for (`A`, `F1`, `L1`) and re-assigning updates instead of duplicating

#### Scenario: Assignment respects list scope

- GIVEN article `A` assigned to family `F1` on list `L1` only
- WHEN the catalog view for list `L2` is rendered
- THEN article `A` is not shown under family `F1` for `L2`

### Requirement: Catalog view per family and list (R-FJ-004)

The plugin MUST provide a catalog view (absorbing the view today rendered by frozen `tarifario` hooks on `catalogo_core` pages) that renders the family tree filtered by price list, honoring `catalogo_familia_estructura.en_catalogo` and article assignments, using the `catalogo_listas_precio` list as the only list source.

#### Scenario: Catalog view filters by list

- GIVEN structures on lists `L1` and `L2` with different article assignments
- WHEN the catalog view is requested for `L1`
- THEN only `L1` structures and assignments are rendered

#### Scenario: Inactive structures hidden

- GIVEN a structure row with `activa = false`
- WHEN the catalog view renders
- THEN that family node is not shown

### Requirement: Non-destructive data migration from tarifario tables (R-FJ-005)

The plugin MUST provide a migration service (following the `CatalogLegacyTableMigration` precedent) that copies legacy data non-destructively:

1. `tarif_tarifa_familia` → `catalogo_familia_estructura` (mapping `codtarifa` → `codlista`)
2. `tarif_tarifa_articulo` → `catalogo_articulo_familia_estructura`

The migration MUST run in FK order (families and `catalogo_listas_precio` first, then `catalogo_familia_estructura`, then `catalogo_articulo_familia_estructura`), MUST be idempotent (re-running copies no duplicates), MUST be transactional per table with rollback on failure, and MUST NOT delete or modify any `tarif_*` source table.

#### Scenario: Migration copies in FK order

- GIVEN legacy rows in `tarif_tarifa_familia` and `tarif_tarifa_articulo` referencing families and lists that exist
- WHEN the migration runs
- THEN `catalogo_familia_estructura` rows are created before `catalogo_articulo_familia_estructura` rows and every legacy row has exactly one canonical counterpart

#### Scenario: Migration is idempotent

- GIVEN a first migration run completed successfully
- WHEN the migration runs a second time with unchanged legacy data
- THEN no new rows are inserted and existing canonical rows are not altered

#### Scenario: Legacy tables preserved

- GIVEN a completed migration
- WHEN the database is inspected
- THEN `tarif_tarifa_familia` and `tarif_tarifa_articulo` still exist with all original rows unmodified

#### Scenario: Orphan legacy row handled safely

- GIVEN a legacy `tarif_tarifa_familia` row whose `codtarifa` has no matching `catalogo_listas_precio.codlista`
- WHEN the migration runs
- THEN the orphan row is skipped, the error is reported in the migration log, and the migration completes for the remaining valid rows

### Requirement: fs_access preservation (R-FJ-006)

The capability MUST preserve existing user access: the absorbed UI MUST keep the existing `VentasFamilias` page name and `fs_access` rows working. Any new page MUST register under the `ventas` menu with a consistent name. If a page rename is unavoidable, a `fs_access` migration step MUST copy permissions to the new page name with a passing test.

#### Scenario: Existing access rows keep working

- GIVEN a user with an `fs_access` row for `VentasFamilias`
- WHEN the absorbed UI is installed and the user logs in
- THEN the user reaches the same page name with no access loss

#### Scenario: New page registered consistently

- GIVEN the catalog view page is new
- WHEN the page list is built
- THEN the page appears under the `ventas` menu with its `fs_access` row created on first admin visit

### Requirement: Hook compatibility during transition (R-FJ-007)

The 4 view hooks that `tarifario` registers via `ViewHookRegistry` into `catalogo_core` pages (`ventas_articulo_tabs_after`, `ventas_articulo_tab_pane_after`, `ventas_opcional_tabs_after`, `ventas_opcional_tab_pane_after`) MUST keep resolving and rendering during this change: `catalogo_core` MUST NOT remove, rename, or shadow those hook points, and `HookRegistrationTest` in `tarifario` MUST keep passing. The absorbed family/catalog UI MUST live in `catalogo_core`'s own pages and MUST NOT depend on those hooks. Deprecation of the hooks is deferred to a `tarifario` follow-up change; `catalogo_core` documents the frozen-hook contract in this spec until then.

#### Scenario: Hooks keep resolving after install

- GIVEN `tarifario` is active alongside `catalogo_core` with the absorbed UI installed
- WHEN the core Twig build runs (TwigLoaderEvent → TwigInitEvent)
- THEN all 4 hooks resolve to their `@tarifario/Hooks/*.html.twig` templates and render without errors, and `HookRegistrationTest` passes

#### Scenario: Absorbed UI independent of hooks

- GIVEN `tarifario` hooks were hypothetically removed
- WHEN the `catalogo_core` family tree and catalog view pages are requested
- THEN they render fully from `catalogo_core` views with no dependency on `@tarifario` templates

### Requirement: Plugin-prefixed translations (R-FJ-008)

All new UI strings MUST use translation keys with the `catalogo-core-` plugin prefix in `plugins/catalogo_core/translations/*.yaml`. No unprefixed keys and no copied `tarifario` texts without prefix are allowed.

#### Scenario: Every new key is prefixed

- GIVEN the new tree UI and catalog view templates
- WHEN translation keys are audited
- THEN every key used by the new capability starts with the plugin prefix and resolves in `messages.es.yaml` (and fallback locale)
