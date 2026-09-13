# Proposal: Unify opcionales management into `ventas_opcionales` and retire `tarif_opcionales`

- **Change**: `unificar-opcionales-en-ventas-opcionales`
- **Owner (SDD)**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Secondary plugin**: `tarifario` (retirement/repointing only)
- **Baseline suite**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **475 tests / 1517 assertions OK** (regression floor)

## Intent

Opcionales management is split across two pages with inverted ownership: the canonical modern page
`ventas_opcionales` (`Controller/VentasOpcionales.php`, `extends PageController`, menu `catalogo`) is a
thin search+delete list, while the richer legacy page `tarif_opcionales` (`fbase_controller`) holds the
tarifa selector, per-tarifa state/price columns, filters, creation and Excel export. The richer behavior
is also served through a "Tarifas" tab injected into `ventas_opcional` by `tarifario`, even though the
opcional domain already lives in `catalogo_core`. This change makes `ventas_opcionales` the single
canonical page, eliminates `tarif_opcionales`, and moves the whole opcional surface (tab, endpoint,
partials, links) to `catalogo_core`, migrated to htmx 4 + Alpine.js CSP. Decisions are user-confirmed
and are not re-litigated here.

## Scope

### In Scope
- Absorb all `tarif_opcionales` behavior into `ventas_opcionales`: tarifa resolution, filters (`query`,
  `b_codfamilia`, `b_codtarifa`, `b_solo_activos`, `offset`), `search`+`count_filtered`, master-state and
  price caches, accessors, POST+CSRF toggles, new-opcional creation (CSRF added), delete, Excel export,
  native pagination.
- Rewrite `View/ventas_opcionales.html.twig` on htmx 4 + Alpine CSP (colon events only, nonce'd
  `Alpine.data()`, `csrf_field()`/`validateFormToken()`), no bootbox, no `|raw`.
- Delete `tarif_opcionales` entirely (controller, view, list-specific tests, and its `fs_page` row — no
  redirect alias).
- Repoint every `tarif_opcionales` link (`model/tarif_opcional.php`, `model/tarif_opcional_precio.php`,
  3 catalogo_core opcional views, 4 tarifario views).
- Move the opcional "Tarifas" tab to `catalogo_core`: hook templates, opcional rows partial, save script,
  and a dedicated `catalogo_core` `fbase_controller` endpoint; split `tarif_tab_precios` so only the
  artículo surface stays in `tarifario`.
- Migrate the moved tab/endpoint to htmx 4 + Alpine CSP.

### Out of Scope
- `tarif_opcional_edit`, `tarif_opcional_precios`, `tarif_configurador_opcionales`: stay
  `catalogo_core`-owned on `fbase_controller` and reachable (locked by three tests).
- The **article** surface of `tarif_tab_precios` and its `ventas_articulo_*` hooks.
- `tarif_tarifa_opcional` master model, `tarif_opcional_precio` adapter semantics, slugs/class/table names.
- Historical `tarif_opcionales` **DB-table** migration services (`CatalogLegacyTableMigration`,
  `TarifOpcionalExtMigration`) and the dead-table tests — the word refers to a table, not the page.
- Switching the unified list base class: it stays on `PageController` (exploration §3).

## Capabilities

### New Capabilities
- `opcionales-management`: one canonical `ventas_opcionales` page owning the unified opcionales list
  (filters, per-tarifa state/price display, toggles, create/delete, Excel export, pagination, htmx/Alpine),
  retirement of `tarif_opcionales`, and `catalogo_core` ownership of the opcional "Tarifas" tab
  (hooks + dedicated endpoint).

### Modified Capabilities
- None. The sibling unarchived changes (`opcionales-tarifa-management`, `catalogo-render-hooks`,
  `opcionales-tarifa-selector`) are NOT entangled or redefined; this capability describes only the
  page-consolidation surface.

## Approach

Keep `VentasOpcionales` on `PageController` (preserves `privateCore`, legacy wrapper and sibling
consistency) and port the missing helpers: native pagination (mirroring
`VentasArticulos::hasMoreResults()/getPaginationUrl()/buildListUrl()`), `validateFormToken()` for all
mutations (closing the current `new_opcional` CSRF gap), and a small price/currency formatter.
Extract the list-only logic into a controller-agnostic trait so the existing seam-based tests
(`opcional_master_state()`/`opcional_precio_model()`) run without a controller boot. The opcional rows
endpoint moves to a dedicated `fbase_controller` in `catalogo_core` (currency helper and `requireCsrf`
already available), registered through `TwigLoaderEvent`/`TwigInitEvent` with an idempotency guard while
`tarifario` drops those two hook entries. Work is strict-TDD, split into the exploration's WU-1..WU-6.

**Resolved open questions (exploration §8):**
1. List logic lives in a **controller-agnostic trait** consumed by `VentasOpcionales`.
2. The moved opcional tab uses a **dedicated `catalogo_core` `fbase_controller` endpoint** (currency
   helper available), not an extension of `tarif_opcional_precios`.
3. Filters keep **`query`** as the primary key with **`search`** as an accepted alias.
4. The unified list starts with the proven **full-page `hx-select="body"`** body swap; fragments are
   introduced only if UX demands.

## Affected Areas

### `plugins/catalogo_core/`

| Area | Impact | Description |
|------|--------|-------------|
| `Controller/VentasOpcionales.php` | Modified | Absorbs the unified list logic/actions |
| `extras/VentasOpcionalesListTrait.php` | New | Controller-agnostic list logic (seams preserved) |
| `View/ventas_opcionales.html.twig` | Modified | htmx 4 + Alpine CSP rewrite |
| `controller/tarif_opcionales.php` | Removed | Legacy page deleted, no redirect |
| `View/tarif_opcionales.html.twig` | Removed | Legacy view deleted |
| `controller/tarif_opcional_tab.php` | New | Dedicated `fbase_controller` rows/save endpoint |
| `View/Hooks/ventas_opcional_tabs_after.html.twig` | New (moved) | Injected tab, now catalogo_core-owned |
| `View/Hooks/ventas_opcional_tab_pane_after.html.twig` | New (moved) | Tab pane, htmx/Alpine |
| `View/Hooks/partials/opcional_precios_rows.html.twig` | New (moved) | Per-tarifa price rows |
| `View/Hooks/partials/opcional_tab_save_script.html.twig` | New (split) | Opcional copy of shared save script |
| `Init.php` | Modified | Register 2 hooks + retire the `tarif_opcionales` `fs_page` row |
| `model/tarif_opcional.php` | Modified | `url()` no-id fallback → `ventas_opcionales` |
| `model/tarif_opcional_precio.php` | Modified | `url()` no-id fallback → `ventas_opcionales` |
| `View/tarif_opcional_edit.html.twig` | Modified | Back-link repointed |
| `View/tarif_opcional.html.twig` | Modified | Back-link repointed |
| `View/tarif_opcional_precios.html.twig` | Modified | Back-link repointed |
| `tests/TarifOpcionalesControllerContractTest.php` | Modified | Drop `tarif_opcionales` slug |
| `tests/TarifOpcionalesControllerMasterStateTest.php` | Modified | Repoint list contracts to the unified page |
| `tests/TarifOpcionalesHtmxContractTest.php` | Modified | Drop/repoint `LIST_VIEW` |
| `tests/CatalogoOpcionalesUnifiedControllerTest.php` | New | Unified list behaviors + link repoints |
| `tests/Integration/CatalogoOpcionalesHookOwnershipTest.php` | New | catalogo_core registers/renders opcional tab |

### `plugins/tarifario/`

| Area | Impact | Description |
|------|--------|-------------|
| `Init.php` | Modified | Remove the 2 opcional hook entries (keep artículo pair) |
| `View/Hooks/ventas_opcional_tabs_after.html.twig` | Removed | Moved to catalogo_core |
| `View/Hooks/ventas_opcional_tab_pane_after.html.twig` | Removed | Moved to catalogo_core |
| `View/Hooks/partials/opcional_precios_rows.html.twig` | Removed | Moved to catalogo_core |
| `View/Hooks/partials/tab_save_script.html.twig` | Modified | Keeps only the artículo half |
| `controller/tarif_tab_precios.php` | Modified | Opcional surface extracted; artículo path unchanged |
| `View/tarif_actualizar_precios.html.twig` | Modified | Link repointed |
| `View/tarif_articulo_precios.html.twig` | Modified | Link repointed |
| `View/tarif_articulos.html.twig` | Modified | Link repointed |
| `View/tarif_historial_precios.html.twig` | Modified | Link repointed |
| `tests/Integration/HookRegistrationTest.php` | Modified | Drop opcional hooks from tarifario |

## Risks

| # | Risk | Likelihood | Mitigation |
|---|------|------------|------------|
| R1 | Deletion leaves a dangling `fs_page` row | High | Idempotent page retirement in `catalogo_core/Init.php::upgrade()` (mirror `retireVentasFamiliasPage()`) |
| R2 | Master-state/htmx lock tests still point at deleted list files | High | Repoint both tests to the unified controller/view; run the suite after each WU |
| R3 | CSRF mismatch (`csrf_field()` vs legacy `form_token`) | Med | Standardize on `csrf_field()` + `validateFormToken()`; assert both; close the `new_opcional` gap |
| R4 | Escaping/XSS in price output or JS interpolation | Med | Twig autoescape, no `|raw`, Alpine `x-text` over `innerHTML` |
| R5 | htmx v2 event names creep into migrated list/tab | Med | Assert colon names; forbid v2 names |
| R6 | Alpine CSP silently drops inline expressions | Med | Logic in `Alpine.data()` with `alpine:init` guard + `[x-cloak]` |
| R7 | tarifario still registers/renders the opcional tab or links the dead page | High | Move registration with idempotency guard; update `HookRegistrationTest`; repoint the 4 views; grep-audit before archive |
| R8 | Tab endpoint renders currency via legacy-only `fsc.simbolo_divisa` | Med | Move endpoint to `fbase_controller`; assert no bare `fsc.simbolo_divisa()` under the moved hooks |
| R9 | Splitting `tarif_tab_precios` breaks the artículo surface | Med | Keep artículo path byte-identical; extract only opcional methods/fragment; assert article rows |
| R10 | Excel export parity regression | Med | Port `export_excel_opcionales()` verbatim; contract-test headers and filter inheritance |
| R11 | Pagination divergence (`fbase_paginas` vs native) | Med | Use the `VentasArticulos` pagination contract; keep `offset`/`query`/`b_*` in URL; assert |
| R12 | `url()` no-id fallbacks still point at the dead page | Med | Repoint both model fallbacks to `ventas_opcionales`; add a source assertion |

## Rollback Plan

Strictly additive-then-subtractive, no DB migration and no schema/data changes. Each WU is an
independent revert boundary: reverting WU-1/WU-2 restores the thin unified page with a legacy
`tarif_opcionales` still present; reverting WU-3 restores the page files/links; reverting WU-4 restores
tarifario hook ownership. Full rollback = `git revert` the change commit(s); the `fs_page` retirement is
idempotent and re-adding the row restores the menu if ever needed. Clear the Twig cache after any revert.

## Dependencies

- `tarifario` depends on `catalogo_core` (direction unchanged); no new Composer dependency.
- ddev, PHP 8.2+, Symfony 7.4, Twig 3, PHPUnit 11; htmx 4 + Alpine CSP macros already shipped in the theme.

## Success Criteria

- [ ] `ventas_opcionales` is the single canonical opcionales page and exposes every behavior above.
- [ ] `tarif_opcionales` (controller, view, links, `fs_page` row) is gone with no dangling references.
- [ ] The opcional "Tarifas" tab renders from `catalogo_core` alone; tarifario keeps only its artículo hooks.
- [ ] Unified list and moved tab use htmx 4 + Alpine CSP: colon events only, nonce'd scripts, no v2 names, no bootbox, no `|raw`.
- [ ] Excel export parity holds (headers, familia/subfamilia, selected-tarifa price).
- [ ] Plugin suite green at or above baseline **475 tests / 1517 assertions OK**; strict TDD (red → green) per WU.
- [ ] All SDD artifacts remain under `plugins/catalogo_core/openspec/`; core `openspec/` untouched.
