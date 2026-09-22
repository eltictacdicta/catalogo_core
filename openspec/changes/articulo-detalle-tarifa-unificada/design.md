# Design: articulo-detalle-tarifa-unificada

Plugin-local change under `plugins/catalogo_core/openspec/`. Owner-confirmed
decisions A1 + B2 + C1 + D4. The core `openspec/` is not a tracker for this
change and the canonical specs under `plugins/catalogo_core/openspec/specs/` are
not edited here.

## Technical Approach

`page=ventas_articulo` renders ONE unified `#datos` pane preceded by a validated
tarifa selector. The selector clones the ratified `tarif_opcional_edit` pattern
and swaps the whole `<body>` so the server re-renders every section scoped to the
selected tarifa. The per-tarifa price/state block stays served by the unchanged
WU-1 endpoint `page=tarif_tab_precios` (so `fsc` remains an `fbase_controller`
with `simbolo_divisa()` and no currency helper is added to the host). Price
mutations keep posting to that endpoint; the article form posts the rest.
`articulos.pvp` is written only when no active tarifa exists. catalogo_core drops
the article hook registration, keeps the four frozen markers inert, and retires
the two article hook templates.

## Architecture Decisions

### AD-1 — Selector triggers a full-body htmx swap (A1)

**Choice**: `hx-get="{{ fsc.url() }}"`, `hx-trigger="change"`, `hx-target="body"`,
`hx-select="body"`, `hx-swap="outerHTML"`, `hx-push-url="true"`, `hx-boost="true"`
on a `<select name="codtarifa">`, rendered only inside
`{% if fsc.tarifas|length > 0 %}`. The `<select>` value is submitted as
`&codtarifa=…`, which `resolveCodtarifa()` consumes from the query.
**Alternatives**: partial `#datos`-only swap (A2, needs a second fragment action
and weaker back/forward semantics); client-side switching (A3, duplicates server
truth).
**Rationale**: the repo has already ratified and tested this pattern
(`View/tarif_opcional_edit.html.twig:28-62`); the server stays the single source
of truth.
**Consequences**: every tarifa change re-renders the page and discards unsaved
pane input (same property as `tarif_opcional_edit`); re-init is mandatory (AD-8).

### AD-2 — WU-1 endpoint remains the only price/state writer (B2, ART-02 intact)

**Choice**: the per-tarifa price/state section lives inside `#datos`; its rows
are rendered by `page=tarif_tab_precios` and every mutation keeps
`hx-post="index.php?page=tarif_tab_precios"` with `hx-include="closest tr"`.
`Controller/VentasArticulo.php` MUST NOT contain `guardar_precio_tab` or any
duplicated row/save logic.
**Alternatives**: absorb the price save into the article form POST (B1) — would
amend ART-02 and break the locked absorption assertion.
**Rationale**: zero logic duplication, smallest spec churn, and it sidesteps the
currency-helper gap (R3) because the rendering `fsc` stays `tarif_tab_precios`
(`extends fbase_controller`).
**Consequences**: two save affordances coexist in one pane (article form + row
save button); documented in the spec delta, not a defect.

### AD-3 — Per-tarifa read is served as a **scoped** WU-1 fragment (resolves R3)

**Choice** (option a, scoped): the host embeds
`<div id="tab_tarifario_precios_rows" hx-get="index.php?page=tarif_tab_precios&action=rows&tipo=articulo&ref=<ref>&codtarifa=<selected>" hx-trigger="load" hx-swap="outerHTML">`.
`tarif_tab_precios` gains an **additive read filter only**: a
`protected string $rows_scope = '';` set in `render_rows_action()` from
`$_REQUEST['codtarifa']` (optional) and in `guardar_precio_articulo()` from the
saved row's `codtarifa`; `render_rows_fragment()` narrows `$this->tarifas` to the
matching tariff when the scope is non-empty. No writer changes; the legacy
scope-less call keeps the current all-tarifas output byte-for-byte.
**Alternatives**: (b) add a `simbolo_divisa()` helper to the host and render the
row in the host view — rejected: it contradicts ATT-04's MUST ("rows load from
`action=rows&tipo=articulo&ref=…`") and re-introduces currency logic in a
`Controller` that deliberately has none.
**Rationale**: satisfies ART-10 ("other tarifas' values are not shown") without
duplicating price rendering; the endpoint identity and base parameters
(`action=rows&tipo=articulo&ref=`) are unchanged; `codtarifa` is an additive
scope, which keeps ATT-04's "unchanged endpoint" true.
**Consequences**: `TarifTabPreciosTest` is migrated (the two-tarifa save/delete
response test now asserts the scoped single-row fragment).

### AD-4 — Validated selection (R4)

**Choice**: port `tarif_opcional_edit::resolver_tarifa_seleccionada()`
semantics into `Controller/VentasArticulo.php`:
`resolveCodtarifa()` returns the supplied code only when it matches an entry of
`$this->tarifas` (`all_activas()`); otherwise it returns the default tariff when
the default is active; otherwise the first active tariff; otherwise `''`.
`loadTarifarioState()` then calls a new `resolveTarifaSeleccionada()` which sets
the new public property from the normalized `codtarifa`.
New public API:

```php
public ?tarif_tarifa $tarifa_seleccionada = null; // FSFramework\model\tarif_tarifa|null
public string $codtarifa = '';                    // normalized, existing property
```

**Alternatives**: keep the unchecked passthrough (today's behavior) — a stale
`codtarifa` would scope the pane to a nonexistent tariff.
**Rationale**: mirrors the ratified resolver; fail-closed to a real active
tariff.
**Consequences**: the selector renders `selected` from
`$tarifa_seleccionada.codtarifa`; `$tarifa_seleccionada === null` is the
"no active tarifas" signal used by AD-5.

### AD-5 — `articulos.pvp` is written only with no tarifa selected (C1)

**Choice**: in `editarArticulo()` the assignment
`$art->pvp = (float) $request->request->get('spvp', 0)` becomes conditional on
`$this->codtarifa === ''` (equivalent to "no active tarifa selected", since
AD-4 normalizes `codtarifa` to `''` iff `$this->tarifas === []`). The base-price
input (`name="spvp"`, value `{{ fsc.articulo.pvp|number_format(2, ',', '.') }}`)
renders only in the no-tarifa branch, keeping the locked literal
`fsc.articulo.pvp` in the view source.
**Alternatives**: mirror `pvp` from the `DEF` tariff (C2, two sources of truth);
overwrite `pvp` from any selected tariff (C3, data loss).
**Rationale**: the per-tarifa price is the truth in `tarif_articulo_precio`;
`pvp` stays the article base price.
**Consequences**: with a tariff selected the pane has no `spvp` input, so the
POST never carries it; with zero tariffs the path is unchanged.

### AD-6 — Hook retirement with frozen markers intact (D4)

**Choice**:
- `Init.php`: delete the `ARTICULO_HOOK_TEMPLATES` const and its docblock
  (current L33-42) and the `foreach (self::ARTICULO_HOOK_TEMPLATES …)` loop
  inside `registerHooks()` (current L230-232). The static guard and the opcional
  loop stay.
- Delete `View/Hooks/ventas_articulo_tabs_after.html.twig` and
  `View/Hooks/ventas_articulo_tab_pane_after.html.twig`.
- Keep both `render_hook(...)` markers in `ventas_articulo.html.twig` with their
  exact names, whitespace control, four frozen keys plus
  `'caracteristicas': fsc.caracteristicas_context()`, and positions: the tabs
  marker is the line immediately before the `#tab_articulo` `</ul>`; the pane
  marker is the line immediately before the `.tab-content` balancing `</div>`.
- Keep `View/Hooks/partials/articulo_precios_rows.html.twig` (the price surface)
  and `View/Hooks/partials/tab_save_script.html.twig` (the save wiring), included
  from the host view instead of the deleted pane.
**Alternatives**: D1/D2 (leave a duplicate price surface), D3 (remove the markers
and rewrite `CatalogoCoreHookMarkersTest`).
**Rationale**: cleanest surface removal while keeping ART-05's four frozen
positions and `CatalogoCoreHookMarkersTest` untouched; `CatalogoArticuloHookOwnershipTest`
flips to assert zero article registration.
**Consequences**: no consumer renders at the two article markers; they remain
consumer-ready insertion points.

### AD-7 — Unified pane composition and locked-literal survival (ART-08)

**Choice**: exact host structure (single `.tab-content`):

```
form[action=fsc.url()][method=post]
  csrf_field + hidden sreferencia
  ul#tab_articulo
     li.active > a href="#datos"        (data-tab)
     li       > a href="#opcionales"    (only secondary tab)
     {{- render_hook('ventas_articulo_tabs_after', …) -}}     ← marker
  div.tab-content
     div#datos.tab-pane.active
        div.articulo-secciones-nav  (anchor index: #datos-ficha,
            #precios-tarifa, #stock-articulo, #multiidioma, #imagenes)
        panel Datos (existing article fields)
        panel id="precios-tarifa"   → tarifas>0 ? per-tarifa box (AD-3)
                                                   : base pvp + coste/pvpi/margen panel
        panel id="stock-articulo"   (existing stock fields)
        include 'partials/articulos/tab_multiidioma.html.twig'
        panel Imágenes (x-data="articuloImagenes", existing, id="imagenes")
     include 'partials/articulos/tab_opcionales.html.twig'   (id="opcionales")
     {{- render_hook('ventas_articulo_tab_pane_after', …) -}} ← marker
  div.panel-footer (delete + save)
```

The per-tarifa box keeps `id="tab_tarifario_precios"`, `data-tipo="articulo"`,
`x-data="articuloTabPrecios"` and `[x-cloak]`, so
`tab_save_script.html.twig` keeps working verbatim (it swaps
`#tab_tarifario_precios_rows` from the JSON `html`).

Locked-literal survival in `View/ventas_articulo.html.twig` (ART-08,
`VentasArticuloControllerTest` unedited):
- `#multiidioma` → the section-index anchor `href="#multiidioma"`, whose target
  is the in-pane section.
- `#opcionales` → the surviving secondary tab `href="#opcionales"`.
- `tab_multiidioma.html.twig` / `tab_opcionales.html.twig` → the two
  `{% include 'partials/articulos/…' %}` lines.
- `fsc.articulo.pvp` → the base-price branch of the `#precios-tarifa` panel.

`View/partials/articulos/tab_multiidioma.html.twig` changes its root from
`<div role="tabpanel" class="tab-pane" id="multiidioma">` to a plain
`<div class="panel panel-default" id="multiidioma">` (same heading/body inside).
A nested `.tab-pane` would be hidden by Bootstrap CSS; removing the tab-pane
classes keeps the section visible inside `#datos` while preserving the anchor.
`tab_opcionales.html.twig` is unchanged (still `role="tabpanel"`, non-active
`tab-pane`, `id="opcionales"`).

**Alternatives**: keep the five tabs and layer a selector (fails ART-05);
`#opcionales` merged too (out of scope).
**Rationale**: one `#datos` pane, `#opcionales` the only secondary tab, all
locked literals alive.
**Consequences**: the absorption test's tab-set assertion is rewritten to the
unified contract; `#precios`/`#stock` survive only as in-pane section anchors,
never as tabs.

### AD-8 — Boot placement and Alpine re-init (R7)

**Choice**: move `{{ htmx.boot({'allowScriptTags': false}) }}` to the top of the
view (immediately after the imports, mirroring
`tarif_opcional_edit.html.twig:9`), keep `{{ alpine.boot() }}` at the bottom, and
keep the existing nonce'd inline script before it. Add the ratified guarded
re-init to the host inline script:

```js
(function () {
    if (window.__ventasArticuloSwapBound === true) { return; }
    window.__ventasArticuloSwapBound = true;
    document.addEventListener('htmx:after:swap', function (evt) {
        if (!window.Alpine || typeof window.Alpine.initTree !== 'function') { return; }
        var ctx = evt && evt.detail ? evt.detail.ctx : null;
        window.Alpine.initTree(ctx && ctx.target ? ctx.target : document.body);
    });
})();
```

and remove the duplicate `htmx:after:swap` block from
`View/Hooks/partials/tab_save_script.html.twig` so `Alpine.initTree` runs once
(the host owns boot, AD-W2-3). The save script keeps its `Alpine.data`
registration and its `htmx:after:request` JSON handler.

**Alternatives**: leave `htmx.boot` at the bottom — the scrubber and header
bootstrap would be installed after most of the body is parsed; keep both
after:swap listeners — double `initTree`.
**Rationale**: verbatim reuse of the ratified boot/re-init; ordering matches the
precedent.
**Consequences**: on a body swap the incoming scripts are scrubbed
(`allowScriptTags:false`) so the once-guarded listeners survive; the swapped
placeholder re-fires `hx-trigger="load"` with the new `codtarifa`.

### AD-9 — Strict-TDD test plan (RED first)

New files (RED before implementation):
1. `tests/Controller/VentasArticuloTarifaSelectionTest.php` — anonymous
   `VentasArticulo` subclass skipping the constructor, stubbing
   `tarifa_model()`, exposing `callResolveCodtarifa()` /
   `callResolveTarifaSeleccionada()`; cases: default preselection; stale
   `codtarifa` → default; unknown + no default → first active; zero tarifas →
   `codtarifa === ''` / `tarifa_seleccionada === null`; host source hides the
   selector and keeps the editable `pvp`; `articulos.pvp` is written with zero
   tarifas.
2. `tests/Controller/VentasArticuloPerTarifaPaneTest.php` — scoped endpoint rows
   plus the host save; cases: `codtarifa=USD1` fragment shows only `USD1`
   (`data-codtarifa`) and not `EUR1`; `precio`/`activo` come from
   `tarif_articulo_precio::get()`; visibility from
   `CaracteristicaResolver::resolve_bool()` with zero `assign_bool`/`save`/
   `delete`; with a tariff selected `articulos.pvp` is not written (tracked
   anonymous `articulo` subclass + seam factories, reusing the absorption test
   harness); host `hx-get` contains
   `page=tarif_tab_precios&action=rows&tipo=articulo&ref=` plus `codtarifa`.

Migrated/flipped:
- `tests/TarifTabPreciosTest.php` (migrated): scoped `rows` filter; scoped save
  response (the two-tariff delete test asserts the single scoped row).
- `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` (migrated):
  `test_view_preserves_tabs_and_frozen_markers` asserted against the unified
  pane; the frozen-marker and WU-1-reuse assertions stay.
- `tests/Integration/CatalogoArticuloHookOwnershipTest.php` (flipped): zero
  article registration across rebuilds, deleted templates, host renders the
  unified pane; endpoint/rows/hygiene assertions kept.
- `tests/Integration/CatalogoCoreHookMarkersTest.php` — **unchanged** (markers
  preserved).
- `tests/VentasArticuloControllerTest.php` — **NOT edited** (ART-08).

Conventions: anonymous `fs_model` subclasses with empty constructors, tracked
mock price models injected through `*_model()` seams, Reflection resets for
`fs_core_log` / `ViewHookRegistry::$hooks` / `Init` static guards, and the
`Config`-free `Request::create(...)` wiring already used by the sibling tests.

### AD-10 — Sibling merge ordering

`caracteristicas-producto` (still active, `verdict: fail`, only WU-7 `7.5`/`7.6`
open) MUST archive before this change: its delta rewrites ART-01/ART-02 in the
canonical `articulo-detalle-canonico` spec, so this change's ART-01/ART-05 blocks
must merge against the sibling post-merge text. `catalogo-render-hooks` is also
touched by both changes, so archive the sibling first as well. Never edit the
canonical specs here. `remove-fs-demo` shares `Controller/VentasArticulo.php`;
keep deltas narrow and rebase deliberately.

## Data Flow

```
selector change ──hx-get page=ventas_articulo&codtarifa=X (body swap)──▶ server
                                                                          │
             resolveCodtarifa() ─▶ tarifa_seleccionada ─▶ render unified #datos
                                                                          │
   placeholder hx-trigger=load ─▶ tarif_tab_precios?action=rows&…&codtarifa=X
                                          │  (fbase_controller, simbolo_divisa)
                                          └─▶ scoped rows fragment (one tariff)
   row save hx-post tarif_tab_precios ─▶ guardar_precio_tab (CSRF + gate)
                                          └─▶ JSON {ok, message, html} ─▶ swap rows
   article form POST ventas_articulo ─▶ editarArticulo ─▶ pvp only when codtarifa=''
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `plugins/catalogo_core/Controller/VentasArticulo.php` | Modify | Validated `resolveCodtarifa()` + `resolveTarifaSeleccionada()` + `public ?tarif_tarifa $tarifa_seleccionada`; conditional `pvp` write. |
| `plugins/catalogo_core/View/ventas_articulo.html.twig` | Modify | Selector; unified `#datos` (Datos/Stock/Idiomas/Imágenes/Precios); section nav anchors; `#opcionales` tab; markers/literals preserved; boot to top; guarded `initTree`. |
| `plugins/catalogo_core/View/partials/articulos/tab_multiidioma.html.twig` | Modify | Drop `role="tabpanel" class="tab-pane"`; keep `id="multiidioma"` as a visible in-pane panel. |
| `plugins/catalogo_core/View/Hooks/partials/tab_save_script.html.twig` | Modify | Remove the duplicate `htmx:after:swap` block (host owns re-init); keep `Alpine.data` + `htmx:after:request`. |
| `plugins/catalogo_core/controller/tarif_tab_precios.php` | Modify | Additive `codtarifa` read-scope on `rows` + save response (`$rows_scope`). No writer change. |
| `plugins/catalogo_core/Init.php` | Modify | Remove `ARTICULO_HOOK_TEMPLATES` + its registration loop. |
| `plugins/catalogo_core/View/Hooks/ventas_articulo_tabs_after.html.twig` | Delete | Retired (D4). |
| `plugins/catalogo_core/View/Hooks/ventas_articulo_tab_pane_after.html.twig` | Delete | Retired (D4). |
| `plugins/catalogo_core/tests/Controller/VentasArticuloTarifaSelectionTest.php` | Create | RED-first selection/degradation tests. |
| `plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php` | Create | RED-first scoped read + conditional `pvp` tests. |
| `plugins/catalogo_core/tests/TarifTabPreciosTest.php` | Modify | Scope-aware rows/save assertions. |
| `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | Modify | Unified-pane tab assertion; markers + WU-1 reuse kept. |
| `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` | Modify | Flip to zero article registration + host-rendered pane. |
| `plugins/catalogo_core/View/Hooks/partials/articulo_precios_rows.html.twig` | Unchanged | Still the price surface. |
| `plugins/catalogo_core/View/partials/articulos/tab_opcionales.html.twig` | Unchanged | Still the secondary tab. |
| `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` | Unchanged | Frozen markers preserved. |
| `plugins/catalogo_core/tests/VentasArticuloControllerTest.php` | Unchanged | ART-08 locked. |

## Interfaces / Contracts

Scoped rows fragment (GET, no CSRF, read-only):
`index.php?page=tarif_tab_precios&action=rows&tipo=articulo&ref=<ref>&codtarifa=<selected>`
→ HTML root `<div class="box" id="tab_tarifario_precios_rows" data-tipo="articulo" data-referencia="<ref>">`
containing exactly the selected tariff row (or the existing "No hay tarifas
activas." row when the scope matches nothing).

Row save (POST, CSRF via `requireCsrf()` + inherited `X-CSRF-TOKEN`):
`index.php?page=tarif_tab_precios` with `guardar_precio_tab=1`, `codtarifa`,
`referencia`, `precio`, `activo`, `en_tarifa`, `en_catalogo` → JSON
`{ok, message, html}` where `html` is the re-rendered **scoped** fragment.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `resolveCodtarifa()` / `resolveTarifaSeleccionada()` matrix; conditional `pvp` | Anonymous `VentasArticulo` subclass, stubbed `tarifa_model()`, tracked `articulo` |
| Unit | Scoped `render_rows_fragment()` read; no-persist visibility | Anonymous `tarif_tab_precios` subclass + tracked price model + `resolve_bool` stub |
| Integration | Host view renders with the stub host fsc; markers/positions; no article hooks registered | `CatalogoCoreHookMarkersTest` (unchanged) + flipped `CatalogoArticuloHookOwnershipTest` |
| Contract | Locked literals in `VentasArticuloControllerTest`; WU-1 reuse in the absorption test | Unedited source-grep tests + migrated assertions |

Gate: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
(baseline 794 tests / 3450 assertions) and `ddev exec composer phpstan`.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file
classification or process-integration boundary is introduced or modified.

## Security Considerations

- **CSRF**: the selector is a read-only GET; the article form keeps
  `csrf_field()`; the price save keeps `requireCsrf()` on the WU-1 endpoint plus
  the `X-CSRF-TOKEN` inherited header emitted by `htmx.boot`. No new mutating
  route.
- **Permission gate**: no new mutation. The article save keeps
  `puedeEditarArticulo()`; the price save keeps `puede_editar_articulo()` through
  the neutral `ArticlePermissionFilterEvent` (default-allow, deny → save
  unreachable). The selector only influences which tariff is *read*.
- **XSS/CSP (ART-04)**: no `bootbox`, no `|raw`, no inline `on*` handlers, htmx 4
  colon events only (`htmx:after:swap`, `htmx:after:request`), all inline scripts
  nonce'd via `csp_nonce_attr()` under `alpine:init`, and
  `allowScriptTags:false` scrubs swapped fragments before insertion.
- **Data integrity**: with a tariff selected `articulos.pvp` is never written;
  the scoped read never persists; a blank price still deletes only its price row
  and leaves feature values intact (ART-02 unchanged).

## Migration / Rollout

No data migration, no schema change, no new table. Deploy order: archive
`caracteristicas-producto` first (AD-10), then apply this change in one commit
(view + controller + Init + endpoint read-scope + tests). Feature-flag not
needed: with zero active tariffs the page degrades to today's base behavior.

## Rollback

`git revert` the apply commit: restore both article hook templates, the
`Init.php` registration, the previous view/partials and the endpoint read path.
View/controller/test-only change with no persisted state, so no repair step is
required.

## Open Questions

None blocking. Two review notes: (1) ATT-04's parenthetical
`action=rows&tipo=articulo&ref=<referencia>` is read as the base parameter set;
`codtarifa` is an additive read scope (AD-3). (2) `tab_save_script.html.twig`
stays under `View/Hooks/partials/` (the spec only retires the two
`ventas_articulo_*.html.twig` hook templates); relocating it out of `Hooks/` is
an optional follow-up.
