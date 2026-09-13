# Proposal: Restore percentage pricing and opcional groups, and unify the single-opcional detail

- **Change**: `opcionales-detalle-porcentaje-y-grupos`
- **Owner (SDD)**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Secondary plugin**: `tarifario` (one link repoint only)
- **Artifact store**: `openspec` (plugin-local; core `openspec/` untouched)
- **Baseline suite**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **511 tests / 1756 assertions OK** (regression floor)
- **Source of truth**: `exploration.md` in this change dir (all file:line evidence)

## Intent

The `ventas_opcionales` unification regressed two capabilities and left the single-opcional detail duplicated. (1) Percentage pricing (`tipo_precio=porcentaje`) is still supported by `catalogo_opcional` and edited by `VentasOpcional`, but every unified-list surface renders/edits only a fixed `precio`, showing `0,00 €`. (2) The original list showed an opcional **Grupo** column and group assignment; the rewrite dropped the column and has no `id_grupo` filter, though the management link survived (`View/ventas_opcionales.html.twig:149`). (3) `tarif_opcional_precios` is a near-duplicate of `tarif_opcional_edit`. Confirmed decisions are not re-litigated: `tarif_opcional_edit` is canonical and absorbs `precios` (deleted, no alias); percentage returns across the whole UI/export; groups return to the unified list; the detail keeps the htmx 4 + Alpine.js posture.

## Scope

### In Scope
- **Percentage**: canonical read `es_precio_porcentaje()` + `get_porcentaje($codlista)` + `etiqueta_precio_lista()`; add `tarif_opcional::set_porcentaje_tarifa()`; make `set_precio_tarifa()` percentage-symmetric (clears stale `porcentaje`); restore it in the unified list, create modal, injected Tarifas tab, detail scoped panel and Excel export.
- **Groups**: `Grupo` column in the unified list, `id_grupo` filter (optional trailing param), create-modal group assignment, controller-side group-name map (no N+1).
- **Detail**: absorb parity in `tarif_opcional_edit`; delete `controller/tarif_opcional_precios.php` + `View/tarif_opcional_precios.html.twig`; repoint links (preserving `codtarifa`); idempotent `retireTarifOpcionalPreciosPage()` in `Init::upgrade()`.
- **htmx 4 + Alpine.js**: absorbed panel plus the new percentage/group controls follow the existing posture (colon events, nonce'd `Alpine.data()`, `[x-cloak]`).

### Out of Scope
- `tarif_tarifa_opcional` master model, `tarif_configurador_opcionales`, `tpvmod`, the tarifario article surface.
- Historical `tarif_opcional_precios` **DB table** migration (`Services/CatalogLegacyTableMigration.php:355-484`) and its dead-table tests — the name refers to a table, not the page.
- No DB schema migration and no Composer dependency.

## Capabilities

### New Capabilities
- None.

### Modified Capabilities
- `opcionales-management`: extend `OUM-03` (percentage-aware price display), `OUM-05` (create accepts `tipo_precio`/`porcentaje`/`id_grupo`), `OUM-07` (export emits percentage), add the group column/filter to `OUM-02`, and replace `OUM-11` with the canonical-detail contract (`tarif_opcional_precios` no longer survives; `tarif_opcional_edit` absorbs it).
- `opcionales-tarifa-selector`: **must be reconciled** — its Purpose and `OTS-08`/`OTS-09` still name `tarif_opcional_precios` as a migrated/surviving surface. When the page dies, those requirements must drop precios and re-scope to `tarif_opcional_edit` + `ventas_opcionales`.

## Approach

Percentage: **global mode + per-lista value with global fallback**, read/written through the inherited `catalogo_opcional` contract. Add an adapter-based `set_porcentaje_tarifa($codtarifa, $pct)` (preserving the `tarif_opcional_precio` history/read-modify-write path) and make `set_precio_tarifa()` clear `porcentaje`; `set_porcentaje_tarifa()` zeroes `precio`. The create modal sets global `tipo_precio`/`porcentaje` (mirroring `Controller/VentasOpcional.php:198-236`) and writes one row per provided tarifa.

Groups: load `catalogo_opcional_grupo::all_activos()` once into a map and expose an accessor (avoid the `etiqueta_grupo()` N+1); append an optional trailing `$id_grupo` to `catalogo_opcional::search()`/`tarif_opcional::search()`/`count_filtered()` (never reorder or require existing params) and wire `b_id_grupo` in the trait filters.

Detail: delete precios, repoint the links listed in `exploration.md §2.5`, retire its `fs_page` idempotently, and re-target the precios half of the locked tests at `edit`.

Resolved open questions: group column source = controller-side map; filter key = `b_id_grupo` (+ "Sin grupo"); create = global percentage then per-tarifa editing in detail/tab; export = numeric percentage with a `0.00"%"` format; retire `fs_page` = yes.

## Affected Areas

### `plugins/catalogo_core/`

| Area | Impact | Description |
|---|---|---|
| `extras/VentasOpcionalesListTrait.php` | Modified | Percentage cache/display/export, group map + column data + `b_id_grupo` filter, create-modal `tipo_precio`/`porcentaje`/`id_grupo` |
| `model/tarif_opcional.php` | Modified | New `set_porcentaje_tarifa()`; `set_precio_tarifa()` percentage-symmetric; optional `id_grupo` search param |
| `model/core/catalogo_opcional.php` | Modified | Optional `id_grupo` param in `search()`/`count_filtered()` |
| `View/ventas_opcionales.html.twig` | Modified | `Grupo` column, group filter, percentage price cell, create-modal fields |
| `controller/tarif_opcional_edit.php` | Modified | Percentage read/write in the scoped panel |
| `View/tarif_opcional_edit.html.twig` | Modified | Percentage input branch; htmx4/Alpine; drop redundant `:79` link |
| `controller/tarif_opcional_tab.php` | Modified | Persist `porcentaje` when the opcional is percentage mode |
| `View/Hooks/partials/opcional_precios_rows.html.twig` | Modified | Percentage input branch |
| `controller/tarif_opcional_precios.php` | Removed | Detail consolidated into `tarif_opcional_edit`; no alias |
| `View/tarif_opcional_precios.html.twig` | Removed | Detail consolidated; no alias |
| `Init.php` | Modified | Idempotent `retireTarifOpcionalPreciosPage()` |
| `openspec/specs/opcionales-management/spec.md` | Modified | Delta merged at archive |
| `openspec/specs/opcionales-tarifa-selector/spec.md` | Modified | Reconcile precios references out |

### `plugins/tarifario/`

| Area | Impact | Description |
|---|---|---|
| `View/tarif_articulo_precios.html.twig` | Modified | Opcional price button repointed to `tarif_opcional_edit&codtarifa=..` |

### Tests (`plugins/catalogo_core/tests/`)

| Area | Impact | Description |
|---|---|---|
| `CatalogoOpcionalesPercentageTest.php` | New | Display, writer symmetry, export, tab percentage |
| `CatalogoOpcionalesUnifiedControllerTest.php` | Modified | Percentage/group scenarios; drop the precios view from the repoint list |
| `CatalogoOpcionalesHtmxContractTest.php` | Modified | Group column/filter + percentage modal contracts |
| `TarifOpcionalesControllerContractTest.php` | Modified | `CONTROLLER_SLUGS` 3 → 2 survivors |
| `TarifOpcionalesControllerMasterStateTest.php` | Modified | Drop `PRECIOS_*`; re-target master coverage at `edit` |
| `TarifOpcionalesHtmxContractTest.php` | Modified | Drop the two precios tests |
| `TarifOpcionalPreciosControllerTest.php` | Modified | Delete the controller test; keep adapter tests |
| `TarifOpcionalTabEndpointTest.php` | Modified | Percentage-row scenarios |
| `TarifOpcionalEditTarifaSelectorTest.php` | Unchanged (guard) | Keep the single `guardar_precio_tarifa` form |
| `InitUpgradeTest.php` | Modified | Page-retirement step |

**Unchanged guards**: `Services/CatalogLegacyTableMigration.php`, `tests/DeadOpcionalTableReferenceTest.php`, `tests/OpcionalPriceUnificationTest.php`, `plugins/tarifario/model/table/tarif_opcional_precios.xml`.

## Risks

| # | Risk | Likelihood | Mitigation |
|---|---|---|---|
| R1 | Percentage/price divergence: `set_precio_tarifa()` leaves a stale `porcentaje` (and fixed→percentage leaves a stale `precio`) | High | Make the writers symmetric; regression-test switches in both directions; assert `get_porcentaje()` agrees with `precio_en_tarifa()` and the UI. |
| R2 | Locked-contract churn: contract slugs (3→2), master/htmx precios portions, precios controller test | High | Update tests in the same WU as the deletion; keep edit/list assertions byte-compatible; run the full suite at every boundary. |
| R3 | `TarifOpcionalEditTarifaSelectorTest` locks exactly one `name="guardar_precio_tarifa"` | Med | Add the percentage input inside the single scoped form; never duplicate the hidden action field. |
| R4 | Group filter / search-signature change breaks stub call-shape assertions | Med | Append `$id_grupo` as an optional trailing param only; run that test first. |
| R5 | `etiqueta_grupo()` N+1 on the list | Med | Load one group map in the controller; never call `etiqueta_grupo()` per row from Twig. |
| R6 | `id_grupo` assignment silently deletes direct article relations | Med | Mirror the `VentasOpcional` flow; document; test the intentional cleanup. |
| R7 | CSRF mismatch: list uses `validateFormToken()`, edit/tab use `requireCsrf()` | Med | Keep each surface on its host guard; new mutations use it; assert in tests. |
| R8 | Deleted precios page leaves an orphan `fs_page` row | Med | Idempotent `retireTarifOpcionalPreciosPage()`; extend `InitUpgradeTest`. |
| R9 | Repoint misses a link and a dead page link survives | Med | Grep audit for `page=tarif_opcional_precios` (excluding `openspec/` and DB-table refs); add a contract assertion. |
| R10 | Tab save erases a percentage row (`guardar_precio_tab` writes only `precio`) | Med | Branch on the opcional mode; persist `porcentaje`; extend `TarifOpcionalTabEndpointTest`. |
| R11 | htmx v2 names / `\|raw` / `bootbox` creep, or Alpine CSP inline eval | Low | Reuse the boot macros + `Alpine.data()`; keep the htmx contract bans green. |

## Rollback Plan

No DB migration and no schema/data changes. WU boundaries are independent revert points: reverting WU-1 restores the regression (percentage/groups absent) with no other effect; reverting WU-2 restores `tarif_opcional_precios` and its links (the `fs_page` retirement is idempotent — re-adding the row restores the menu); reverting WU-3/WU-4 restores the prior edit/tab/export behavior. Full rollback = `git revert` the change commit(s). Clear the Twig cache after any revert.

## Dependencies

- `tarifario` depends on `catalogo_core` (direction unchanged); no new Composer dependency.
- ddev, PHP 8.2+, Symfony 7.4, Twig 3, PHPUnit 11; htmx 4 + Alpine CSP macros already shipped.

## Success Criteria

- [ ] Percentage opcionales render `etiqueta_precio_lista()` (not `0,00 €`) in the unified list, detail, tab and Excel export.
- [ ] `set_porcentaje_tarifa()`/`set_precio_tarifa()` are symmetric; switching modes never leaves a stale value.
- [ ] The unified list shows the `Grupo` column, filters by `b_id_grupo` (incl. "Sin grupo"), and assigns a group in the create modal, with the group map loaded once (no N+1).
- [ ] `tarif_opcional_precios` controller/view are gone with no alias; all links repointed; its `fs_page` row retired idempotently.
- [ ] `tarif_opcional_edit` remains the single canonical detail on htmx 4 + Alpine.js (colon events, nonce'd scripts, no v2 names, no `|raw`, no `bootbox`).
- [ ] Plugin suite green at or above baseline **511 tests / 1756 assertions OK**; strict TDD (red → green) per WU.
- [ ] All SDD artifacts stay under `plugins/catalogo_core/openspec/`; core `openspec/` untouched.
