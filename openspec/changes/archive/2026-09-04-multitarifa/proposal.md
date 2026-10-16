# Proposal: Multi-Tariff Pricing for Catalogo Core

## Intent

Catalog articles currently have a single price (`articulos.pvp`). Real sales channels need multiple named price lists (retail, wholesale, campaign) per article, with family-based batch price updates. The `tarifario` plugin proved this model; `catalogo_core` absorbs the reusable parts (multi-tariff pricing, batch update, options store, optional role gating) while staying self-contained and leaving the legacy percent-based `tarifa` model untouched.

## Scope

### In Scope
- Reuse `catalogo_listas_precio` as the tariff entity (activa / por_defecto / coddivisa — matches `tarif_tarifas` 1:1).
- New side table `catalogo_articulo_precios` PK `(referencia, codlista)` for per-list article prices; `articulos.pvp` remains the default-list price, untouched.
- Admin UI for price lists (CRUD) and per-article price editor.
- Batch price update ("actualizar precios"): preview + apply with filters (lista, familia, incluir subfamilias recursively, grupo), `round(p * (1 + pct/100), 2)` formula, CSRF-gated POST (pattern from `tarif_actualizar_precios`).
- Family hierarchy already present in `model/core/familia.php` (madre, hijas(), recursive aux_all()) — no model change, only consumed.
- `opciones_catalogo`: new `Services/CatalogoOptions.php` backed by namespaced fs_settings keys, absorbing `catalogo_excel_roles` plus new flags (multi-tariff on/off, role-groups master setting). Backward-compat shim in `ArticleExcelAccessPolicy` (read new store, fall back to old key); replace the `catalogo_excel_settings` admin page/setting with a general options page.
- Role groups (OPTIONAL, gated): tables `catalogo_grupo_roles`, `catalogo_grupo_usuarios` (nick + rol gestor/editor/revisor/visualizador), `catalogo_grupo_tarifas`, `catalogo_grupo_articulos`; first-party listener on `Event/ArticlePermissionFilterEvent`. Master setting off ⇒ listener short-circuits to allow (provably inert).

### Out of Scope / Non-Goals
- Legacy `tarifa` model (`model/core/tarifa.php`, table `tarifas`) — untouched.
- No data migration from `plugins/tarifario` (separate plugin).
- `catalogo_opcional_precios` (opcionales already priced per list) — no change.
- No changes outside `plugins/catalogo_core/` (core untouched).

## Capabilities

### New Capabilities
- `multi-tariff-pricing`: named price lists, per-article per-list prices, default-list fallback to `articulos.pvp`.
- `batch-price-update`: preview/apply percentage price updates filtered by list/family/subfamilies/group.
- `catalog-options-store`: `opciones_catalogo` settings store absorbing `catalogo_excel_settings` plus new flags.
- `role-based-price-access`: optional role-group gating of list/article editing (inert when master setting off).

### Modified Capabilities
- `articulos-excel-access-settings`: setting read path moves to `CatalogoOptions` with backward-compat fallback to `catalogo_excel_roles`; admin page replaced by general options page.

## Approach

Follow `tarifario` patterns: side table keyed `(referencia, codlista)` keeps `articulos.pvp` authoritative for the default list; group gating reuses the neutral fail-closed `ArticlePermissionFilterEvent` with a first-party listener that is a no-op when the groups master setting is off. `CatalogoOptions` centralizes fs_settings reads so Excel access policy migrates safely (fail-closed preserved).

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `model/table/catalogo_articulo_precios.xml` | New | Per-list article prices |
| `model/table/catalogo_grupo_*.xml` (4) | New | Role-group tables (optional feature) |
| `model/core/catalogo_articulo_precio.php`, `catalogo_grupo_*.php` | New | Models |
| `Services/CatalogoOptions.php` | New | Options store |
| `Event/` + listener service | Modified | Group gating listener on `ArticlePermissionFilterEvent` |
| `Init.php` | Modified | Listener registration |
| `Controller/` (listas, actualizar precios, opciones_catalogo) | New/Modified | Admin pages; replaces `catalogo_excel_settings` page |
| `view/` | New/Modified | Twig templates for the above |
| `Services/ArticleExcelAccessPolicy.php` | Modified | Backward-compat shim to CatalogoOptions |
| `tests/` | New | TDD coverage for models, options store, listener, batch update, shim regression |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Settings migration silently breaks Excel access | Med | Backward-compat fallback to old key; regression tests (existing `CatalogoExcelSettingsPageTest`, `ExcelAccessControlBootstrapRegressionTest`) |
| Fail-closed listener bug locks out editors | Med | Groups-off short-circuit tests; allow-by-default when master setting off |
| FK/install ordering (listas before new tables) | Low | Install sequence: `catalogo_listas_precio` before dependent tables |
| Role groups overreach | Low | Master setting default off; feature provably inert |

## Rollback Plan

Plugin-local change: deactivate/restore the previous plugin version (`fs_plugin_manager` backup `_back`), or revert the commit. Old settings key remains readable via the shim, so a revert of the options store cannot orphan Excel access. New tables are additive and can be dropped.

## Dependencies

- `catalogo_listas_precio` table/model already installed (prerequisite for FKs).
- No new Composer dependencies; no vendor/ commit needed.

## Success Criteria

- [ ] Articles can hold prices per active price list; default list falls back to `articulos.pvp`.
- [ ] Batch price update previews before applying and is CSRF-gated; recursive subfamily inclusion works.
- [ ] `opciones_catalogo` absorbs `catalogo_excel_roles` with zero access change for existing users.
- [ ] Role groups are provably inert while master setting is off (listener allows all).
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` passes (strict TDD).
