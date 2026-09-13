# Proposal: Absorb tarifario's opcional domain into catalogo_core

## Intent

Opcional management is catalog data, but its UI and per-tarifa tables live in `plugins/tarifario` while `catalogo_core` owns `catalogo_opcional*`. That inverts the dependency and blocks standalone delivery. Latent defects block correctness: raw SQL targets dead `tarif_articulo_opcional`, two FKs point at dead `tarif_opcionales`, and `tarifario/Init.php:65-70` registers 4 hooks that never render (`catalogo_core` Twig never calls `render_hook`).

## Scope

Slugs, class names and table names stay unchanged; no data migration; additive; `catalogo_opcionales` XML/API untouched.

### In Scope

- Tags per (tarifa, opcional, familia): `tarif_tarifa_opcional_etiqueta` + `tarif_opcional_edit` families-tab.
- Per-tarifa prices: unify `tarif_opcional_precios` (`codtarifa`) with `catalogo_opcional_precios` (`codlista`) via `CatalogLegacyTableMigration`; price UI/actions.
- Per-tarifa activation/order: `tarif_tarifa_opcional_familia`, `tarif_tarifa_articulo_opcional`, `tarif_tarifa_opcional_resolver`.
- Price history: `tarif_opcional_precio_historial` + `tarif_historial_precios` (`tipo=opcionales`).
- Hierarchical configurator: `tarif_configurador_opcionales` + tree views/partials/js.
- Move `tarif_opcional`/`tarif_opcional_ext` with the domain; `ref_sap`/`en_catalogo`/`en_tarifa` stay in the 1:1 ext table.
- Render hooks: `ventas_opcional_tabs_after`, `ventas_opcional_tab_pane_after`, `ventas_articulo_tabs_after`, `ventas_articulo_tab_pane_after` in catalogo_core views.
- Latent fixes: SQL → `catalogo_articulo_opcional` (`tarif_tarifa_articulo_opcional.php:190,217`); FKs → `catalogo_opcionales` (`tarif_tarifa_opcional_familia.xml:49`, `tarif_tarifa_articulo_opcional.xml:55`); drop dead XMLs and deprecated wrappers.

### Out of Scope

- Promoting `ref_sap`/`en_catalogo`/`en_tarifa` into `catalogo_opcionales` (test-locked XML).
- Schema/API changes, `tpvmod` consumption, data migration, renames.

## Capabilities

### New Capabilities

- `opcionales-tarifa-management`: catalogo_core-owned opcional domain (tags, per-tarifa prices/activation/order, history, configurator, resolver).
- `catalogo-render-hooks`: `render_hook` points in catalogo_core opcional/article views.

### Modified Capabilities

- None.

## Approach

Atomic single-commit move tarifario → catalogo_core, mirroring `archive/2026-09-06-absorber-familias-tarifa-en-catalogo-core`: `git mv`, keep names, update hardcoded requires, extend `catalogo_core/Init.php` to bootstrap the moved tables standalone in FK-safe order. Moved controllers MUST NOT require tarifario files, forcing `tarif_opcional`/`tarif_opcional_ext` to move too. Fix the SQL/FK defects in the move. Hooks resolve `@tarifario/Hooks/*` when active, no-op otherwise. Verify with suites, PHPStan, and a grep gate.

## Affected Areas

| Area | Impact |
|---|---|
| `catalogo_core` model/table/controller/View | New (moved) + hooks |
| `catalogo_core/Init.php` | Bootstrap |
| `tarifario` model/controller/View/tests | Removed/moved + require updates |

## Risks

| Risk | L | Mitigation |
|---|---|---|
| Missed hardcoded require | Med | Post-apply grep gate |
| `tarif_opcional_ext` legacy columns break | Med | Keep 1:1 ext table + migration |
| Hook render breaks views | Low | No-op without listener; smoke pages |

## Rollback Plan

Single-commit move; `git revert` restores both plugins. Tables/data untouched; clear Twig cache after revert.

## Dependencies

- `tarifario` requires `catalogo_core`; ddev, PHP 8.2+, Symfony 7.4, Twig 3, PHPUnit 11.

## Success Criteria

- [ ] Opcional pages served from catalogo_core, unchanged slugs/classes/tables.
- [ ] Standalone catalogo_core creates moved tables FK-safe.
- [ ] 4 hooks render when tarifario is active, no fatal when inactive.
- [ ] No stale `tarif_articulo_opcional`/`tarif_opcionales` refs; dead XMLs/wrappers gone.
- [ ] Plugin + root Plugins suites green; PHPStan clean.
