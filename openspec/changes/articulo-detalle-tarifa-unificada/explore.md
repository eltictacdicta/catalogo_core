# Exploration: articulo-detalle-tarifa-unificada

> Plugin-local SDD. All artifacts live under
> `plugins/catalogo_core/openspec/`. The core `openspec/` is **not** a tracker
> for this change. `ownership: plugin-local`, `strict_tdd: true`
> (`plugins/catalogo_core/openspec/config.yaml:9,27,38`).
> This document is exploration only; it does not modify code.

## 1. Problem statement

The canonical article detail (`page=ventas_articulo`) exposes five article-scoped
tabs — `#datos`, `#precios`, `#stock`, `#multiidioma`, `#opcionales` — plus an
injected `Tarifas` tab. The price the user edits (`articulos.pvp`) is a single
article-level value, while the per-tarifa truth lives elsewhere
(`tarif_articulo_precio.precio`). The owner wants:

1. a **tarifa selector** at the top of the detail, with the **default tarifa
   preselected**;
2. the article-scoped tabs **unified into one `#datos` pane**: Datos + Stock +
   Idiomas + Imágenes + Precios, all **scoped to the selected tarifa**;
3. the price (and the rest of the per-tarifa state: `activo`, `en_tarifa`,
   `en_catalogo`) to reflect the **selected tarifa**;
4. implemented with **htmx 4 + Alpine.js**, following the existing repo pattern.

`#opcionales` stays its own separate tab.

## 2. Current-state map (verified)

### 2.1 View — `plugins/catalogo_core/View/ventas_articulo.html.twig` (449 lines)

| Element | Lines |
|---|---|
| `x-data="articuloDetalle"` root | 5–6 |
| Single `<form method="post" action="{{ fsc.url() }}">` | 36 |
| CSRF field + hidden `sreferencia` | 37–38 |
| Tab header `<ul id="tab_articulo">` | 40–58 |
| Tab links: `#datos` / `#precios` / `#stock` / `#multiidioma` / `#opcionales` | 43–55 |
| `render_hook('ventas_articulo_tabs_after', …)` | 57 |
| `.tab-content` open | 60 |
| Pane `#datos` (article fields + families/fabricantes/impuesto + **Imágenes** panel) | 62–230 |
| Pane `#precios` (coste / pvp `name="spvp"` / pvpi / margen) | 233–268 |
| Pane `#stock` (stockfis / stockmin / stockmax / nostock) | 271–309 |
| `include 'partials/articulos/tab_multiidioma.html.twig'` (pane `#multiidioma`) | 311 |
| `include 'partials/articulos/tab_opcionales.html.twig'` (pane `#opcionales`) | 312 |
| `render_hook('ventas_articulo_tab_pane_after', …)` | 313 |
| Footer/delete + single **save** button (full-form POST) | 316–327 |
| Alpine component registrations (`articuloDetalle`, `articuloTabs`, `articuloPrecios`, `articuloImagenes`, `articuloOpcionales`) | 350–442 |
| `htmx.boot({'allowScriptTags': false})` + `alpine.boot()` | 446–447 |

Key literals the view currently renders and that locked tests assert:
`fsc.articulo.descripcion` (L72), `fsc.articulo.pvp` (L250), `#multiidioma`
(L52), `#opcionales` (L55), `tab_multiidioma.html.twig` (L311),
`tab_opcionales.html.twig` (L312).

### 2.2 Controller — `plugins/catalogo_core/Controller/VentasArticulo.php` (1261 lines)

- Class extends `FSFramework\Controller\PageController` (L68) →
  `FSFramework\Core\Base\Controller` → **standalone, NOT `fs_controller`**
  (`src/Controller/PageController.php:13`, `src/Core/Base/Controller.php:15`).
- Tarifario-aware state already public: `$codtarifa` (L85), `$tarifas` (L86),
  `$imagenes` (L87), etiqueta lists (L88–89), `$puede_editar` (L90).
- `loadTarifarioState()` (L209–228): loads `tarifas = all_activas()`,
  `codtarifa = resolveCodtarifa()`, images, etiquetas, permission verdict.
- `resolveCodtarifa()` (L234–247): `codtarifa` from query → request body →
  else `tarif_tarifa::get_default()`. **Does not** validate a supplied
  `codtarifa` against the active set (unlike
  `tarif_opcional_edit::resolver_tarifa_seleccionada()`).
- `editarArticulo()` (L369–449): CSRF first (L371), permission gate (L377),
  then **`$art->pvp = (float) $request->request->get('spvp', 0)` (L400)**,
  `$art->save()` (L426), multi-idioma (L427), `syncTarifaArticulo()` (L431).
- `syncTarifaArticulo()` (L461–482): writes `tarif_tarifa_articulo` row for the
  active tarifa + visibility feature values.
- `persist_articulo_visibility()` (L505–530): posts `en_tarifa`/`en_catalogo`
  as articulo-scope feature values via `CaracteristicaValorStore::assign_bool`,
  only when the request actually carries those controls (L516–518).
- **No** `tarifa_seleccionada` object and **no** per-tarifa price property on the
  controller today; there is no `simbolo_divisa()` / `show_precio()` helper
  (those live on `fs_controller`, `base/fs_controller.php:1210,1230`).

### 2.3 Per-tarifa price/state storage

- `plugins/catalogo_core/model/tarif_articulo_precio.php` — fields
  `referencia`, `codtarifa`, `precio`, `activo`, `en_tarifa`, `en_catalogo`,
  `en_sap` (L32–69); table `tarif_articulo_precios`; `get(ref, codtarifa)`
  (L115–125); `save()` (L161–214); `url()` already repointed to
  `page=ventas_articulo` (L101–107).
- Visibility (`en_tarifa`/`en_catalogo`) is persisted as **articulo-scope
  feature values** through `CaracteristicaValorStore`
  (`CaracteristicaResolver::SCOPE_ARTICULO = 'articulo'`, L40;
  `VISIBILITY_CODIGOS = ['en_catalogo','en_tarifa']`, L50) — never on the legacy
  price-row columns (sibling change `caracteristicas-producto`, D3/D12).
- `tarif_tarifa` — `codtarifa`, `nombre`, `activa`, `por_defecto`, `coddivisa`
  (L32–64); `get_default()` (L116–123); `all_activas()` (L232–242).

### 2.4 Tarifas tab injection (WU-1) — the surface to be absorbed

- `plugins/catalogo_core/Init.php` registers the article hook pair in
  `ARTICULO_HOOK_TEMPLATES` (L39–42), behind static guard `registerHooks()`
  (L220–235).
- `View/Hooks/ventas_articulo_tabs_after.html.twig` (15 lines): a `<li>` with
  `href="#tab_tarifario_precios"` and an `hx-get` to
  `index.php?page=tarif_tab_precios&action=rows&tipo=articulo&ref=…` (L8–14).
- `View/Hooks/ventas_articulo_tab_pane_after.html.twig` (26 lines): the
  `#tab_tarifario_precios` pane shell + `x-data="articuloTabPrecios"` +
  `#tab_tarifario_precios_rows` placeholder (L17–23); includes the nonce'd save
  script (L25).
- `controller/tarif_tab_precios.php` (272 lines): `extends fbase_controller`
  (L53), i.e. `fbase_controller extends fs_controller`
  (`plugins/catalogo_core/extras/fbase_controller.php:25`). GET
  `action=rows&tipo=articulo&ref=` renders
  `View/Hooks/partials/articulo_precios_rows.html.twig`
  (`render_rows_fragment()`, L122–143); POST `guardar_precio_tab` is the only
  price writer (L148–208), with CSRF (L152) + neutral permission gate (L173,
  L247–262) and feature-value visibility persistence (L219–235).
- `View/Hooks/partials/articulo_precios_rows.html.twig` (70 lines): one row per
  active tarifa; `precio` / `activo` / `en_tarifa` / `en_catalogo` controls
  (L32–49); each row's save button `hx-post`s to `page=tarif_tab_precios` with
  `hx-include="closest tr"` (L55–59); currency via
  `fsc.simbolo_divisa(tarifa.coddivisa)` (L36).

### 2.5 Reusable selector pattern (to copy)

`View/tarif_opcional_edit.html.twig` L28–62: renders the selector **only when
`fsc.tarifas|length > 0`**, marks the default tarifa with `★` (L45), and swaps
the page with `hx-get` / `hx-trigger="change"` / `hx-target="body"` /
`hx-select="body"` / `hx-swap="outerHTML"` / `hx-push-url="true"` /
`hx-boost="true"` (L37–43). Its controller resolves and validates the selection
in `resolver_tarifa_seleccionada()`
(`controller/tarif_opcional_edit.php:125–152`) and exposes `$tarifa_seleccionada`
(L59). The body-swap + Alpine re-init dance is already solved there
(L403–529, incl. `htmx:after:swap` → `Alpine.initTree`).

### 2.6 Current test baseline (re-measured, overrides the stale figure)

```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
Tests: 794, Assertions: 3450, Warnings: 26, Skipped: 1.   exit 0
```

> The orchestrator's stated baseline `749 / 3091` (from the sibling
> `caracteristicas-producto/tasks.md`) is **stale**. The live baseline is
> **794 tests / 3450 assertions**, matching the sibling's `verify-report.md:68`.

## 3. Design space

### (a) How the unified pane is composed

| Option | Description | Pros | Cons | Effort |
|---|---|---|---|---|
| **A1 — Server-rendered unified pane + full-body htmx swap** | Tarifa selector uses the existing `tarif_opcional_edit` pattern (`hx-get page=ventas_articulo&codtarifa=…`, `hx-target="body"`, `hx-select="body"`, `hx-swap="outerHTML"`, `hx-push-url`). Server renders ONE `#datos` pane containing Datos + Stock + Idiomas + Imágenes + Precios scoped to the selected tarifa. | Mirrors the ratified repo pattern exactly; server is the single source of truth; per-tarifa values render server-side; no new endpoint; Alpine stays trivial. | Whole body re-render on each change (heavier); needs the already-solved htmx4 `allowScriptTags:false` + Alpine re-init handling. | Low–Med |
| **A2 — Partial swap of `#datos` only** | Selector `hx-get`s a fragment (new action) and swaps only `#datos` (`hx-target="#datos"`). | Lighter swap; preserves unrelated form input. | Needs a fragment action and a partial refactor of the pane; URL push/back less clean; more moving parts. | Med |
| **A3 — Client-side switching with preloaded data** | Fetch all tarifas' values once, switch in Alpine. | Instant switch. | Diverges from the repo pattern; duplicates server truth; save path unclear; more CSP/Alpine surface. | High |

**Recommendation: A1.** It is the only option that reuses a pattern the repo
already ratified and tested, and it keeps the per-tarifa state authoritative on
the server. A2 is the fallback if the body swap proves too heavy.

### (b) How the price + per-tarifa state is read and SAVED

**Read (all options):** server resolves `codtarifa` (existing
`resolveCodtarifa()`, default preselected), then:
- `precio`/`activo` → `tarif_articulo_precio::get(referencia, codtarifa)`;
- `en_tarifa`/`en_catalogo` → `CaracteristicaResolver::resolve_bool(codigo, codtarifa, referencia, null)`,
  exactly the maps `tarif_tab_precios::render_rows_fragment()` already builds
  (L128–134).

| Option | Save path | Pros | Cons | Spec impact |
|---|---|---|---|---|
| **B1 — Main form POST absorbs the price save** | Unified form POSTs to `ventas_articulo`; the handler writes the per-tarifa price/state (reusing `persist_articulo_visibility()`) in the same save. | One save button; one CSRF token; strongest match to "unify". | **Contradicts ART-02** (`specs/articulo-detalle-canonico/spec.md:62-63`: MUST reuse the WU-1 endpoint "rather than duplicating the row/save logic") and the locked grep test `test_price_editing_reuses_the_wu1_tab_endpoint` (absorption test L755–771, asserts the controller does NOT contain `guardar_precio_tab`). Re-opens the duplication guarantee. | **ART-02 amended + test rewritten** |
| **B2 — WU-1 endpoint stays the only price writer** | The `#datos` pane embeds the per-tarifa price controls; price/state mutations still `hx-post page=tarif_tab_precios` (rows partial L55–59). The rest of the article form POSTs to `ventas_articulo`. | Zero logic duplication; ART-02 and `test_price_editing_reuses_the_wu1_tab_endpoint` stay green; smallest spec churn; also avoids the `simbolo_divisa()` gap (see risk R3). | Two save affordances inside one pane (article form + per-row price buttons) — not literally "one save". | ART-02 unchanged; **ART-05 amended** |
| **B3 — Hybrid auto-save on change** | Same writer as B2, but per-tarifa price/state persists on `change`/`blur` via htmx instead of an explicit button. | Feels closest to "select tarifa → edit its price". | Implicit saves can surprise; more htmx wiring; still two write moments. | ART-05 amended; possibly ATT-05-adjacent |

**Recommendation: B2** (B3 if the owner explicitly wants change-triggered save).
B1 is available but must be presented to the owner as a real tradeoff: it buys a
single save button at the cost of amending the MUST that forbids duplicating the
WU-1 price logic.

### (c) What happens to `articulos.pvp`

Today `editarArticulo()` unconditionally writes `articulos.pvp` from `spvp`
(L400). Once a tarifa is selected, the pane must show/edit that tarifa's price.

| Option | Rule | Pros | Cons |
|---|---|---|---|
| **C1 — pvp is the base price; per-tarifa price is separate** | With a tarifa selected, the pane edits `tarif_articulo_precio.precio`; `articulos.pvp` is NOT written by the unified save. `pvp` remains the article base price, editable only when no tarifas are active. | No clobbering; per-tarifa truth stays in one table; matches the model design. | Changes ART-01 behavior (pvp always written); the `#precios` PVP field must be hidden/relabelled/read-only when a tarifa is selected. |
| **C2 — pvp mirrors the DEF tarifa** | Writing the `DEF` tarifa also writes `articulos.pvp`; other tarifas do not. | Keeps `articulo.pvp` meaningful with minimal branching. | Only coherent for `DEF`; two sources of truth for the same number; edge cases when `DEF` is renamed/inactive. |
| **C3 — pvp always mirrors the selected tarifa** | Overwrite `pvp` from whichever tarifa is being edited. | Single simple rule. | Destroys the base price whenever a non-default tarifa is edited — data loss. **Not recommended.** |

**No active tarifas (`fsc.tarifas|length == 0`):** the selector is hidden (same
guard as `tarif_opcional_edit` L28), the unified pane renders the base article
fields and the `pvp` input exactly as today, and the WU-1 rows partial already
degrades to "No hay tarifas activas." (rows partial L64–66). The base `pvp` save
path must remain reachable in this branch.

**Recommendation: C1.** Keep the literal `fsc.articulo.pvp` in the base-price
branch so the locked `VentasArticuloControllerTest::testTwigViewDisplaysArticlePrice`
(L199–210) stays green.

### (d) Fate of the article hook pair

The Tarifas tab is injected through `ventas_articulo_tabs_after` /
`ventas_articulo_tab_pane_after`, so absorbing it forces a decision about the
registration.

| Option | Description | Pros | Cons |
|---|---|---|---|
| **D1 — Keep as-is** | Leave Init registration + hook templates. | Zero Init/view churn. | Leaves a second price-editing surface (the injected tab) that duplicates the unified pane — confusing and contradictory. |
| **D2 — Repurpose into no-ops** | Keep registration, empty the templates. | Minimal churn. | Dead hook; contradicts `catalogo-render-hooks` "Hooks render when a listener is registered" intent. |
| **D3 — Retire the pair** | Remove `ARTICULO_HOOK_TEMPLATES` + delete both hook templates; the host renders the unified pane. | Cleanest; no dead code; the absorbed feature no longer needs injection. | Requires amending `catalogo-render-hooks` ("Article hook pair registration owned by catalogo_core", MUST, L132–153) and its `catalogo-core` tests; ART-05's four frozen markers must be reconciled. |
| **D4 — Keep the markers, drop the registration** | The two `render_hook` markers stay in the view (ART-05 / `CatalogoCoreHookMarkersTest` positions intact) but catalogo_core registers nothing for them; the pair becomes a consumer-only insertion point again (or explicitly unused). | View structure and the frozen-marker position tests survive; nothing currently registers them so rendering is unchanged. | Amends the `catalogo-render-hooks` MUST and flips `CatalogoArticuloHookOwnershipTest` to assert zero registration (the mirror of the flip the absorption program just did). |

**Recommendation: D4** (preferred) or **D3** if the owner wants the insertion
point gone. Either way `catalogo-render-hooks` MUST be amended and
`CatalogoArticuloHookOwnershipTest` updated. D4 is cheaper: ART-05's four frozen
markers and `CatalogoCoreHookMarkersTest` stay structurally intact. D1/D2 are not
recommended because they leave a duplicate price surface.

## 4. Blast radius

### 4.1 Files to modify

| File | Change |
|---|---|
| `plugins/catalogo_core/View/ventas_articulo.html.twig` | Add the tarifa selector (top, default preselected); merge `#precios` / `#stock` / `#multiidioma` / `#imagenes` into one `#datos` pane scoped to the selected tarifa; drop the now-redundant tab links; add an Alpine component for the per-tarifa price section; keep the frozen markers at their positions; keep `#opcionales`; keep the literals `#multiidioma`, `#opcionales`, `tab_multiidioma.html.twig`, `tab_opcionales.html.twig`, `fsc.articulo.pvp`, `fsc.articulo.descripcion`. |
| `plugins/catalogo_core/Controller/VentasArticulo.php` | Expose `$tarifa_seleccionada` and the selected tarifa's price/state for the view; validate a supplied `codtarifa` against active tarifas; make the `articulos.pvp` write conditional per option (c); if D4/D3, no hook change here. |
| `plugins/catalogo_core/Init.php` | Only if D3/D4: retire (or drop) `ARTICULO_HOOK_TEMPLATES` (L39–42) and `registerHooks()` entries (L230–232). |
| `plugins/catalogo_core/View/partials/articulos/tab_multiidioma.html.twig` | Likely repurposed in place: remove/adjust the `role="tabpanel"` + `id="multiidioma"` shell so it can be embedded as a section inside `#datos` (or keep the id as an anchor). |
| `plugins/catalogo_core/View/partials/articulos/tab_opcionales.html.twig` | Only if the `#opcionales` tab markup needs to remain but move; the pane itself stays as its own tab. |
| `plugins/catalogo_core/controller/tarif_tab_precios.php` | Only if the embedded rows fragment needs a new action/variant; B2 needs no change. |

### 4.2 Files to add / delete

- Add: none required for A1+B2. Possible `View/partials/articulos/tab_precios_tarifa.html.twig`
  (or an embedded section) if the price block is factored out.
- Delete (only if D3): `View/Hooks/ventas_articulo_tabs_after.html.twig`,
  `View/Hooks/ventas_articulo_tab_pane_after.html.twig`, and the save-script
  partial if unused.
- Do **not** delete `View/Hooks/partials/articulo_precios_rows.html.twig` under
  B2 — it remains the price-rows surface.
- No new DB table, no migration, no table rename.

### 4.3 Specs to amend (all under `plugins/catalogo_core/openspec/specs/`)

| Spec | Requirement | Why |
|---|---|---|
| `articulo-detalle-canonico/spec.md` | **ART-05 (MUST, L117–133)** | Currently requires preserving five tabs `#datos`/`#precios`/`#stock`/`#multiidioma`/`#opcionales`. Unification removes four of them. Must be rewritten to require the unified `#datos` pane + the four frozen hook markers. |
| `articulo-detalle-canonico/spec.md` | **ART-02 (MUST, L56–80)** | Only if option **B1** is chosen: its "reuse the WU-1 endpoint rather than duplicating the row/save logic" clause must be relaxed. Under B2/B3 it stays untouched. |
| `articulo-detalle-canonico/spec.md` | ART-01 (MUST, L17–31) | Only if option **C1** is chosen: the `articulos.pvp` write becomes conditional on "no tarifa selected". |
| `articulo-detalle-canonico/spec.md` | ART-08 (MUST, L193–214) | Its "no test edits" stance for `VentasArticuloControllerTest` must be preserved; the absorption-test updates belong to this change's delta, not to ART-08. |
| `catalogo-render-hooks/spec.md` | "Article hook pair registration owned by catalogo_core" (MUST, L132–153) and the purpose clause (L7–10) | Required under **D3/D4**: the article pair stops being registered/injected. |
| `articulo-tarifa-tab-management/spec.md` | ATT-03 / ATT-04 (MUST, L79–126) | Likely touched: the tab stops being injected through hooks and becomes a section of the host pane. |

### 4.4 Tests to update (coherently, per the new contract)

| Test | Contract today | Expected change |
|---|---|---|
| `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | `test_view_preserves_tabs_and_frozen_markers` (L906–923) asserts `#datos`,`#precios`,`#stock`,`#multiidioma`,`#opcionales` + both frozen markers; `test_price_editing_reuses_the_wu1_tab_endpoint` (L755–771) asserts no `guardar_precio_tab` in the controller and `page=tarif_tab_precios` in the rows partial; `test_article_opcional_contract_is_preserved` (L831–842); `test_view_uses_htmx4_and_alpine_hygiene` (L874–895). | Rewrite the tab-set assertion to the unified pane; keep the frozen-marker assertion; keep/adjust the WU-1 reuse assertion (stays green under B2/B3); keep `#opcionales`. |
| `tests/Integration/CatalogoCoreHookMarkersTest.php` | `FROZEN_NAMES` (L55–60) + exact marker positions (L114–132) + four-name enumeration (L138–153). | If D4: unchanged (markers stay). If D3: remove the two article names and their position tests. |
| `tests/Integration/CatalogoArticuloHookOwnershipTest.php` | Asserts catalogo_core registers and renders the article pair (L168–242). | D4/D3: flip to assert zero registration and that the host renders the unified pane; keep the endpoint guard assertions (L246–272). |
| `tests/VentasArticuloControllerTest.php` | `testTwigViewIncludesCatalogTabs` (L238–248) asserts `#multiidioma`,`#opcionales`,`tab_multiidioma.html.twig`,`tab_opcionales.html.twig`; `testTwigViewDisplaysArticlePrice` (L199–210) asserts `fsc.articulo.pvp`; `testTwigViewDisplaysArticleDescription` (L186–197); `testControllerExposesMultiLanguageAndOptionals` (L226–236). | **ART-08 (MUST) forbids editing these assertions**; the view must keep those literals. Plan the pane so they survive. |
| New tests (strict_tdd) | — | A RED-first test for: default tarifa preselected; per-tarifa price/state rendered for the selected tarifa; `articulos.pvp` semantics per (c); no-tarifas degradation; save path per (b). |

### 4.5 Sibling-change interaction (must be resolved before apply)

- **`plugins/catalogo_core/openspec/changes/caracteristicas-producto/` is still
  ACTIVE and unarchived**, with `verdict: fail`, `blockers: 1`
  (`verify-report.md:4-8`), 68/71 tasks done; only WU-7 `7.5` (soak) and `7.6`
  (gated drop) remain (L21–48). The live tree already reflects it (794/3450).
- Its delta `changes/caracteristicas-producto/specs/catalogo-core/articulo-detalle-canonico/spec.md`
  **MODIFIES ART-01 and ART-02** (L9–66) and **explicitly leaves ART-03…ART-08
  unchanged** (L3–7). It also carries deltas for `articulo-tarifa-tab-management`
  and `catalogo-render-hooks`.
- Because our change MODIFIES ART-05 and (for B1/C1) may modify ART-01/ART-02,
  and both changes touch `catalogo-render-hooks`, the **archive/merge order
  matters**: if `caracteristicas-producto` archives first it rewrites ART-01/02
  in the main spec, changing the base our delta must merge against. Author this
  change's ART deltas against the **sibling post-merge** text, or sequence the
  sibling to archive first. Do not edit the main spec files directly in this
  change.
- **`remove-fs-demo`** is also active (both `plugins/catalogo_core/openspec/changes/remove-fs-demo/`
  and core `openspec/changes/remove-fs-demo/`). Its proposal names
  `Controller/VentasArticulo.php` and `VentasArticuloArticleEditAbsorptionTest.php:856`
  as touch points. The tree currently has **no** `FS_DEMO` read in
  `plugins/catalogo_core/Controller/` (verified), so its code work looks already
  applied; still, it is a third change in flight on the same two files.
- No `articulo-detalle-tarifa-unificada` entry exists in the core
  `openspec/changes/` (verified) — keep it that way.

## 5. Open questions and risks

### Open questions (for the owner / later phases)

1. **Save model (b):** does the owner want ONE save button for the whole pane
   (option B1, which forces an ART-02 amendment), or is it acceptable that the
   per-tarifa price keeps its own WU-1 save while the article form saves the rest
   (option B2)?
2. **`pvp` semantics (c):** must `articulos.pvp` remain the base article price
   (C1), or should it track a tarifa (C2/C3)? C3 risks data loss.
3. **Hook pair (d):** retire the registration and templates (D3) or keep the
   frozen markers as inert insertion points (D4)?
4. **Unified pane shape:** should Imágenes and Idiomas become sub-sections with
   their own ids/anchors (to preserve the literal `#multiidioma` the locked test
   asserts), and is `#opcionales` the only surviving secondary tab?
5. **Selector UX:** does changing the tarifa discard unsaved edits in the pane
   (body swap re-renders), and is that acceptable? The `tarif_opcional_edit`
   pattern has the same property.

### Risks

- **R1 — Locked-test collision (ART-08 / VentasArticuloControllerTest).** ART-08
  (MUST) requires those assertions to stay green **with no test edits**, and
  `testTwigViewIncludesCatalogTabs` asserts the literal `#multiidioma` and
  `tab_multiidioma.html.twig`. A naive unification that removes the old tab
  header breaks it. Mitigation: keep the literals as anchors/sub-sections.
- **R2 — Two MUST amendments in one change.** ART-05 is mandatory; ART-02 and/or
  ART-01 are mandatory *if* B1/C1 are chosen. The delta must MODIFY them
  explicitly and the change must justify each amendment.
- **R3 — Currency helper gap.** The WU-1 rows partial calls
  `fsc.simbolo_divisa(tarifa.coddivisa)` (rows partial L36), which exists on
  `fs_controller`/`fbase_controller` but **not** on the host
  `VentasArticulo` (`PageController` → standalone `Core\Base\Controller`).
  Rendering the rows partial directly from the host view will fail unless (a)
  the price rows keep rendering through `tarif_tab_precios` (B2), or (b) a
  currency helper is added to the host controller. This constrains option (b).
- **R4 — `codtarifa` validation gap.** `resolveCodtarifa()` accepts any supplied
  `codtarifa` without checking it is active (`Controller/VentasArticulo.php:234-247`).
  The new selector should reuse the validated logic from
  `tarif_opcional_edit::resolver_tarifa_seleccionada()` so a stale/unknown code
  cannot scope the pane to a nonexistent tarifa.
- **R5 — Sibling-change merge order** (`caracteristicas-producto` unarchived,
  verdict `fail`). See §4.5. Recommend sequencing so our ART/`catalogo-render-hooks`
  deltas are authored against the sibling's post-merge main spec.
- **R6 — Three changes on the same files.** `caracteristicas-producto`,
  `remove-fs-demo` and this change all touch `Controller/VentasArticulo.php` and
  the absorption test. Expect archive-time conflicts; keep deltas narrow and
  rebase/merge deliberately.
- **R7 — Body-swap weight and Alpine re-init.** A1 swaps `<body>` on every
  tarifa change; the repo's answer (`allowScriptTags:false` + guarded
  `Alpine.initTree` on `htmx:after:swap`, `tarif_opcional_edit` L517–529) must
  be reused verbatim, and the view must stay free of `bootbox`, `|raw`, htmx v2
  event names and inline `on*` handlers (ART-04, absorption test L874–904).
- **R8 — Review budget.** Unifying four panes plus a selector is likely below
  the 400-line default, but the spec/test churn is non-trivial; `sdd-tasks`
  should forecast it explicitly.

## 6. Readiness

Enough is known to proceed. The change is plugin-local, touches no core tree, and
its two mandatory spec amendments (ART-05; and ART-02/ART-01 depending on the
owner's answers) are identified with exact file/line anchors. The open questions
in §5 are decisions the owner should confirm before `sdd-propose`, chiefly the
save model (B1 vs B2) and the `pvp` rule (C1 vs C2/C3).

**Ready for proposal:** Yes — pending owner answers to Q1–Q4.

## 7. Verified evidence index

- `plugins/catalogo_core/openspec/config.yaml:9,27,38`
- `plugins/catalogo_core/View/ventas_articulo.html.twig:5-6,36-58,60-314,311-312,316-327,350-447`
- `plugins/catalogo_core/Controller/VentasArticulo.php:68,85-90,209-228,234-247,369-449,400,461-482,505-530`
- `plugins/catalogo_core/View/tarif_opcional_edit.html.twig:28-62`
- `plugins/catalogo_core/controller/tarif_opcional_edit.php:59,125-152`
- `plugins/catalogo_core/View/Hooks/ventas_articulo_tabs_after.html.twig:8-14`
- `plugins/catalogo_core/View/Hooks/ventas_articulo_tab_pane_after.html.twig:17-25`
- `plugins/catalogo_core/controller/tarif_tab_precios.php:53,122-143,148-208,219-235,247-262`
- `plugins/catalogo_core/View/Hooks/partials/articulo_precios_rows.html.twig:32-59,64-66`
- `plugins/catalogo_core/Init.php:39-42,220-235`
- `plugins/catalogo_core/model/tarif_articulo_precio.php:32-69,101-125,161-214`
- `plugins/catalogo_core/model/tarif_tarifa.php:32-64,116-123,232-242`
- `plugins/catalogo_core/Services/CaracteristicaResolver.php:40,50,173`
- `plugins/catalogo_core/extras/fbase_controller.php:25`; `base/fs_controller.php:1210,1230`
- `src/Controller/PageController.php:13`; `src/Core/Base/Controller.php:15`
- `plugins/catalogo_core/openspec/specs/articulo-detalle-canonico/spec.md:7-13,17-31,56-80,117-133,193-214`
- `plugins/catalogo_core/openspec/specs/catalogo-render-hooks/spec.md:7-10,132-153`
- `plugins/catalogo_core/openspec/specs/articulo-tarifa-tab-management/spec.md:79-126`
- `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php:755-771,831-842,874-923`
- `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php:55-60,114-153`
- `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php:50-59,168-272`
- `plugins/catalogo_core/tests/VentasArticuloControllerTest.php:186-248`
- `plugins/catalogo_core/openspec/changes/caracteristicas-producto/specs/catalogo-core/articulo-detalle-canonico/spec.md:3-7,9-66`
- `plugins/catalogo_core/openspec/changes/caracteristicas-producto/verify-report.md:4-8,35,68`
- `plugins/catalogo_core/openspec/changes/remove-fs-demo/proposal.md:44-49`
- Live gate: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → 794 tests / 3450 assertions, exit 0.
