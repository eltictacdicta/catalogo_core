# Proposal: articulo-detalle-tarifa-unificada

## Intent

`page=ventas_articulo` keeps five article tabs (`#datos`, `#precios`, `#stock`, `#multiidioma`, `#opcionales`) plus an injected Tarifas tab; the edited price (`articulos.pvp`) is article-level while per-tarifa truth lives in `tarif_articulo_precio.precio`. The goal: one tarifa selector (default preselected) scoping a unified `#datos` pane (Datos + Stock + Idiomas + Imágenes + Precios), with price and per-tarifa state (`activo`, `en_tarifa`, `en_catalogo`) reflecting the selected tarifa, on the ratified htmx 4 + Alpine pattern. `#opcionales` stays separate.

## Scope

In Scope:
- Tarifa selector, default preselected, validated against active tarifas.
- Unified `#datos` pane scoped to the selected tarifa.
- Per-tarifa read: `precio`/`activo` from the price row, `en_tarifa`/`en_catalogo` from feature values.
- Conditional `articulos.pvp` (C1); hook-pair retirement (D4).

Out of Scope:
- No DB migration, table rename or new table.
- `#opcionales` stays separate; ART-03 untouched.
- No edits to `VentasArticuloControllerTest.php` (ART-08 MUST).
- No duplicated save logic; ART-02 intact; no core `openspec/` entry; no research lane.

## Capabilities

New: None.

Modified:
- `articulo-detalle-canonico` — ART-05 (unified `#datos` replaces five-tab preservation); ART-01 (`articulos.pvp` write conditional on "no tarifa selected").
- `catalogo-render-hooks` — article-hook-pair (registration dropped; markers stay inert).
- `articulo-tarifa-tab-management` — ATT-03 (registration retired); ATT-04 (tab renders as a pane section).

## Approach

A1 + B2 + C1 + D4.
- **Selector (A1):** clone `View/tarif_opcional_edit.html.twig:28-62` verbatim (`hx-get page=ventas_articulo&codtarifa=…`, change trigger, body target/select/swap, push-url, boost); render only when tarifas exist.
- **Validation (R4):** port `tarif_opcional_edit::resolver_tarifa_seleccionada()` into `resolveCodtarifa()` (unknown → default → first active); expose `$tarifa_seleccionada`.
- **Pane:** one server-rendered `#datos` (Datos, Stock, Idiomas, Imágenes, per-tarifa price); keep `#multiidioma`/`#opcionales` in-pane anchors so locked literals survive.
- **Read:** `precio`/`activo` via `tarif_articulo_precio::get`; visibility via `CaracteristicaResolver::resolve_bool`. Rows keep rendering through `tarif_tab_precios` (R3: host lacks `simbolo_divisa()`).
- **Save (B2):** price/state keeps its own `hx-post page=tarif_tab_precios`; rest POSTs to `ventas_articulo`.
- **pvp (C1):** `editarArticulo()` writes `articulos.pvp` only with no tarifa selected; base branch keeps `fsc.articulo.pvp`.
- **Hooks (D4):** keep both markers; drop `ARTICULO_HOOK_TEMPLATES`/`registerHooks()`; delete both hook templates. Reuse `allowScriptTags:false` + guarded `Alpine.initTree` on `htmx:after:swap` (R7).

## Blast Radius

- `View/ventas_articulo.html.twig` (modify): selector, unified `#datos`, markers/literals kept.
- `Controller/VentasArticulo.php` (modify): validated selector, per-tarifa read, conditional `pvp`.
- `Init.php` (modify): drop article hook registration.
- `View/Hooks/ventas_articulo_tabs_after.html.twig` + `ventas_articulo_tab_pane_after.html.twig` (delete): retired.
- `View/partials/articulos/tab_multiidioma.html.twig` (modify): embeds as `#multiidioma` section.
- `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` (modify): unified-pane assertions; WU-1 reuse kept.
- `tests/Integration/CatalogoArticuloHookOwnershipTest.php` (modify): assert zero registration.
- `tests/Integration/CatalogoCoreHookMarkersTest.php` (unchanged): markers stay.
- New strict_tdd tests (add): default selection, per-tarifa render, `pvp`, no-tarifas.

## Risks

- Locked literals break ART-08 (High): keep `#multiidioma`, `#opcionales`, both includes, `fsc.articulo.pvp`.
- Sibling merge order (High): author deltas against `caracteristicas-producto` post-merge text; sequence it first; never edit main specs.
- `remove-fs-demo` on the same files (Med): narrow deltas; deliberate rebase.
- Body-swap weight / Alpine re-init (Med): reuse ratified pattern.
- Currency gap R3 (Med): rows render via `tarif_tab_precios`.

## Rollback Plan

Revert the apply commit; restore both hook templates and the `Init.php` registration. View/controller/tests only, no migration or data mutation, so `git revert` needs no state repair.

## Dependencies

- Sibling `caracteristicas-producto` post-merge spec text (merge order).
- WU-1 endpoint `tarif_tab_precios` (unchanged).

## Success Criteria

- [ ] Selector preselects the default; stale `codtarifa` falls back safely.
- [ ] Unified `#datos` scopes all sections to the selected tarifa.
- [ ] With a tarifa selected `articulos.pvp` is not written; base price editable with no tarifas.
- [ ] `#opcionales` stays separate; ART-02 WU-1 reuse stays green.
- [ ] `VentasArticuloControllerTest` passes unedited; suite ≥ 794/3450.
