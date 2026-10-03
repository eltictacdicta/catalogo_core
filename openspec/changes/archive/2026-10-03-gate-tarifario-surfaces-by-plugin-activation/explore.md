# Exploration — Gate tarifario-owned surfaces by plugin activation

- Change: `gate-tarifario-surfaces-by-plugin-activation`
- SDD owner: `plugins/catalogo_core/openspec/` (plugin-local; core `openspec/` MUST NOT get an entry)
- Secondary plugin touched: `plugins/clientes_core/`
- Artifact store: openspec
- Status: exploration complete — ready for `sdd-propose`

---

## 1. Problem restatement

A deliberate architecture migration moved the tarifa / opcionales / familias-tarifa
domain OUT of `plugins/tarifario` INTO `plugins/catalogo_core`; `tarifario` now
`require = "catalogo_core"` (`plugins/tarifario/fsframework.ini:6`). As a
consequence the controllers, views and feature/UI flags that conceptually belong
to the optional `tarifario` plugin live in an always-enabled plugin
(`catalogo_core`, plus `clientes_core`). When `tarifario` is DEACTIVATED:

- tarifario-owned surfaces still render in listings, create and edit views;
- direct-URL access to the moved tarifa endpoints still works;
- the menu entries `tarif_tarifas` / `tarif_familias` survive, because
  `fs_plugin_manager::cleanupOrphanPages()` only removes `fs_pages` rows whose
  controller file is gone (`base/fs_plugin_manager.php:688-713`,
  `fs_page_has_controller()` `base/fs_functions.php:164-183`) and those
  controllers now live in `catalogo_core`.

Required outcome (maintainer-confirmed):

1. Data STAYS in the DB. Deactivation hides surfaces; it never deletes rows.
2. tarifario-owned surfaces MUST NOT render in listings, create or edit views
   when `tarifario` is inactive.
3. Endpoints MUST be blocked too, so a direct URL is not a bypass.
4. Menu entries for `tarif_tarifas` / `tarif_familias` MUST be hidden.
5. The gate MUST use the bare plugin-name string `'tarifario'` with
   `\FSFramework\Core\Plugins::isEnabled()`; it MUST NOT reference a plugin
   path or `@tarifario/` namespace, which catalogo_core ownership tests forbid
   (`tests/ArticuloTarifaPrecioOwnershipTest.php:214-228`,
   `tests/ArticuloListaCanonicaOwnershipTest.php:214-215`).

---

## 2. Verified grounding (spot-checked against real files)

Confirmed as stated:

| Claim | Verified location |
|---|---|
| `Plugins::isEnabled(string): bool` reads the enabled set | `src/Core/Plugins.php:77-80` |
| `Plugins::enabled()` returns `$GLOBALS['plugins'] ?? []` | `src/Core/Plugins.php:72-75` |
| Legacy equivalent | `base/fs_plugin_manager.php:421-424` |
| `$GLOBALS['plugins']` seeded from `tmp/{FS_TMP_NAME}enabled_plugins.list` | `base/config2.php:115-128` |
| `token` list order / boot | `index.php:98` → `Kernel::boot()` → `Plugins::init()` `src/Core/Kernel.php:55-60` |
| Precedent controller bool `tarif_tarifas::$tarifario_activo` | `controller/tarif_tarifas.php:68,103-105`; view `View/tarif_tarifas.html.twig:65,123,129,166,200,277,324` |
| `en_catalogo`/`en_tarifa` registered only by tarifario, `origen='tarifario'` | `plugins/tarifario/Init.php:122-129` |
| catalogo_core DEFAULTS only `medidas` | `Services/CaracteristicaRegistry.php:40-52` |
| Definition ownership filter drops plugin-owned rows when off | `Services/CaracteristicaResolver.php:82-102`, `Services/CaracteristicaOwnership.php:49-67` |
| Moved page controllers + menu flags | `tarif_tarifas.php:92` (folder `tarifario`, shmenu default TRUE), `tarif_familias.php:80` (folder `catalogo`, default TRUE), `tarif_opcional_edit.php:63`, `tarif_tab_precios.php:71`, `tarif_opcional_tab.php:55` (all shmenu FALSE) |
| View leak line ranges | `View/ventas_articulos.html.twig:83-92,141-151,176-208`; `View/ventas_articulos.html.twig` filter/columns; `View/ventas_opcionales.html.twig:117-157,203-254,317-334,385-398,467-484`; `View/ventas_articulo.html.twig:41-74,238-257`; `View/partials/articulos/modal_nuevo_articulo.html.twig:75-100` |
| clientes_core leak | `controller/ventas_clientes.php:317-334`; `view/ventas_clientes.html.twig:206,215,252-255`; `themes/AdminLTE/view/terceros/grupo_list.html.twig:40,51` |

### Corrections / additional findings (grounding was incomplete or wrong here)

1. **`ResponseTrait` does not exist.** `src/Traits/` contains only
   `ValidatorTrait.php`; a repo-wide search finds no `trait ResponseTrait`, no
   `notFound()`, no `redirectToPage()` (the `AGENTS.md` "Response Helpers"
   section is stale). Legacy `fs_controller` exposes no redirect/notFound
   helper; legacy code uses raw `header('Location: ...'); exit;`
   (`base/fs_controller.php:245,649,668`). The modern `Controller` only has
   `redirect()` (`src/Core/Base/Controller.php:493`). **The endpoint guard must
   build its own response.**
2. **`pre_private_core()` is `private`, not an override seam.**
   `base/fs_controller.php:979-998`. It is also where CSRF is validated.
   Therefore the shared surface guard cannot be injected through a
   pre-hook; it MUST be called at the very top of each target
   `private_core()` (a shared trait method is the right unit).
3. **Menu hiding cannot rely on `fs_user::compose_menu()`.** For
   administrators `compose_menu()` returns ALL pages unfiltered
   (`model/core/fs_user.php:358-362`). The sidebar instead iterates
   `fsc.pages(folder)` which filters `show_on_menu`
   (`base/fs_controller.php:602-611`, `themes/AdminLTE/view/header.html.twig:258`).
   So persisting `show_on_menu = false` on the `fs_pages` row DOES hide the
   entry for admins too; but the row must be flipped, and it must be flipped
   even when nobody visits the page.
4. **`tarif_familias` is a modern `HtmxCrudController`,** not `fbase_controller`
   (`controller/tarif_familias.php:43`), and dispatches by `$_REQUEST['action']`
   inside `private_core()` (`:127-131,152-207`). A guard must run before that
   dispatch or the fragment actions execute for an inactive owner.
5. **`tarif_tarifas::$tarifario_activo` is derived from `class_exists()` of
   tarifario models, NOT from `Plugins::isEnabled()`** (`tarif_tarifas.php:103-105,163-172`).
   It must be re-based on the gate; the view already consumes it, so the
   contract surface is stable.
6. **The `en_catalogo`/`en_tarifa` article columns already self-gate.**
   `CaracteristicaValorBatchReader::columns()` applies
   `CaracteristicaOwnership::is_active()` (`Services/CaracteristicaValorBatchReader.php:123,139`)
   and `CaracteristicaResolver::definitions()` drops them too. No extra work is
   needed for those characteristic columns; the leak is the hardcoded UI, not
   the feature registry.
7. **`terceros/grupo_list.html.twig` appears unreferenced inside
   `clientes_core`.** No PHP/Twig resolves `grupo_list`; `controller/ventas_grupo.php`
   is a redirect stub to `ventas_clientes&tab=grupos` (`ventas_grupo.php:28-40`).
   It may be a dead theme override or resolved by another plugin by convention.
   It has a display column only (no `codtarifa` input); the input is in
   `view/ventas_clientes.html.twig:252-255`.
8. **`View/ventas_articulo.html.twig:41` and `:238` are guarded by
   `fsc.tarifas|length > 0` / `fsc.tarifa_seleccionada`, not by tarifario.**
   Suppressing `$this->tarifas`/`$tarifa_seleccionada` at the controller makes
   those branches degrade (ART-11), but the explicit filter block in
   `ventas_articulos` is NOT inside a `tarifa_seleccionada` guard
   (`:83-92`) and needs its own condition.
9. **`Controller/VentasArticulo.php:230` and the two list traits load tarifas
   unconditionally** (`extras/VentasArticulosListTrait.php:149-163`,
   `Controller/VentasArticulo.php:223-231`); the opcionales list trait behaves
   the same. These are the data-loading seams to gate.
10. **`Init::upgrade()` already mutates `fs_pages` idempotently**
    (`Init.php:415-537` retirees) — but `upgrade()` runs only on activation /
    version change, not per request. Per-request immediacy must hang off
    `Init::init()` (`Init.php:55-106`), which `Plugins::init()` runs on every
    request before any controller is constructed.

---

## 3. Recommended gate mechanism

**Recommendation: Option C — a single catalogo_core `TarifarioGate` service as
the source of truth, surfaced to controllers through a shared trait that exposes
`public bool $tarifario_activo`, consumed by the endpoint guards and by an
`Init`-driven `fs_pages` menu sync. Views gate with `{% if fsc.tarifario_activo %}`.
No new Twig global/function.**

### Shape

```
plugins/catalogo_core/Services/TarifarioGate.php
    final class TarifarioGate
    {
        public function isEnabled(): bool  // Plugins::isEnabled('tarifario')
        protected function enabled_plugins(): array  // seam -> Plugins::enabled()
    }
plugins/catalogo_core/extras/TarifarioSurfaceGateTrait.php
    trait TarifarioSurfaceGateTrait
    {
        public bool $tarifario_activo = false;          // view/controller contract
        protected function tarifario_gate(): TarifarioGate  // seam for tests
        protected function init_tarifario_surface_gate(): void  // sets the bool
        protected function guard_tarifario_surface(): bool       // endpoint guard
    }
```

The gate is plugin-agnostic in implementation but pinned to the string
`'tarifario'` by the surface trait; no `plugins/tarifario/` path or
`@tarifario/` namespace is ever written, satisfying the ownership tests.

### Why Option C (service + controller bool) over the alternatives

| Option | Pros | Cons | Verdict |
|---|---|---|---|
| A — per-controller bool, computed inline, `{% if fsc.tarifario_activo %}` | Follows the existing `tarif_tarifas` precedent; no Twig plumbing; test-friendly | ~8 copies of the same check; endpoint guard logic duplicated | Acceptable but DRY-weak |
| B — service + Twig global `tarifario_activo` registered from `Init` | Views clean; fragments gated without `fsc` | New Twig plumbing; a process-wide global increases test blast radius; every affected template already receives `fsc` (pages AND both fragment endpoints pass `fsc`), so the global buys nothing | Over-engineered here |
| **C — service + controller bool via shared trait** | One source of truth; endpoint guard reuses it; no Twig plumbing; matches the `fsc.tarifario_activo` contract the views already use elsewhere | Adds one service + one trait | **Recommended** |

Rationale details:

- The Twig environment is a memoized singleton created by `Html::render()`
  (`src/Core/Html.php:125-156`) and `TwigInitEvent` fires exactly once
  (`:219-231`), so a global is *technically* viable. It is rejected on
  necessity grounds, not feasibility: every affected template (catalogo_core
  pages plus `@catalogo_core/Hooks/...` fragments) already receives `fsc`, and
  `render_hook(...)` passes `fsc` to the hook templates
  (`View/ventas_opcional.html.twig:127,423`).
- A controller bool lets strict-TDD tests set `$controller->tarifario_activo = true`
  or seed `$GLOBALS['plugins'] = ['tarifario']` without booting Twig. The
  feature-ownership tests already use exactly this seeding pattern
  (`tests/CaracteristicaOwnershipTest.php:149-181`,
  `tests/Services/ArticuloExcelCaracteristicaTest.php:307`).
- The trait centralizes the endpoint guard and the `$tarifario_activo`
  initialization, so the ~8 controllers share one implementation.

### Controllers that consume the trait (8)

1. `controller/tarif_tarifas.php` (legacy page) — re-base `$tarifario_activo`.
2. `controller/tarif_familias.php` (modern `HtmxCrudController`) — add bool + guard.
3. `controller/tarif_opcional_edit.php` (legacy page) — add bool + guard.
4. `controller/tarif_tab_precios.php` (legacy fragment endpoint) — guard.
5. `controller/tarif_opcional_tab.php` (legacy fragment endpoint) — guard.
6. `Controller/VentasArticulos.php` (page; uses `VentasArticulosListTrait`).
7. `Controller/VentasArticulo.php` (detail page).
8. `Controller/VentasOpcionales.php` (page; uses `VentasOpcionalesListTrait`).
9. `Controller/VentasOpcional.php` (opcional edit page; feeds the two hooks)
   — and the two list traits (`extras/VentasArticulosListTrait.php`,
   `extras/VentasOpcionalesListTrait.php`) inherit the trait.

Data-loading suppression rule (keeps pure methods testable):

- Gate `init_list_filters()` / `init_opcionales_list()` / the detail's
  `loadTarifarioState()`: when off, set `$this->tarifas = []`,
  `$this->b_codtarifa = ''`, `$this->tarifa_seleccionada = null`.
- Do NOT gate pure resolvers (`resolve_b_codtarifa()`,
  `load_articulo_tarifa_columns()`) — existing tests call them directly with
  injected seams (`tests/Controller/VentasArticulosListAbsorptionTest.php:412-489`).

---

## 4. Endpoint guard design

### Interception point

Call `guard_tarifario_surface()` as the first statement of each target
`private_core()` / `privateCore()`, before any action dispatch or model load.
`pre_private_core()` cannot be used (it is `private`, `fs_controller.php:979`).

```php
protected function guard_tarifario_surface(): bool
{
    if ($this->tarifario_activo) {
        return true; // allowed
    }
    http_response_code(404);
    $this->template = false;          // index.php:353 skips rendering
    if ($this->wantsJson()) {         // fragment/JSON endpoints
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => false, 'message' => 'Tarifario no activo.']);
    }
    return false;                     // caller returns immediately
}
```

### Response shape recommendation

**HTTP 404 for all five endpoints**, with the body matched to the surface:

- `tarif_tarifas`, `tarif_familias`, `tarif_opcional_edit` (HTML pages):
  404 + `template = false` + empty/short body. 404 is semantically honest (the
  surface does not exist while its owner plugin is inactive), needs no redirect
  target, and is trivially assertable. A 302-to-`index.php` is friendlier for
  stale bookmarks; that is a UX preference, not a security difference — flag as
  open question (Q1).
- `tarif_tab_precios` `action=rows` / `tarif_opcional_tab` `action=rows`
  (HTML fragments): 404 + empty body so a stale host page cannot swap in
  markup.
- `tarif_tab_precios` / `tarif_opcional_tab` `guardar_precio_tab` (POST JSON):
  404 + `{ok:false, message}` matching the existing JSON envelope
  (`tarif_tab_precios.php:292-296`, `tarif_opcional_tab.php:253-257`).

Notes:

- The guard must also cover the early-return actions in `tarif_familias`
  (`export_excel`, `export_excel_template`, `import_excel_chunk`) and the
  `reorder`/`toggle` fragment actions, since they run inside `process_action()`.
- The guard must run on POST and GET alike; CSRF does not substitute for the
  gate.
- The constructor must also be gate-aware (see §5) so `check_fs_page()` does
  not re-flag the page on a blocked visit.

---

## 5. Menu / page hiding design

Menu pages to hide while `tarifario` is off: `tarif_tarifas` (folder
`tarifario`) and `tarif_familias` (folder `catalogo`).

### No page-level opt-out exists

There is no `fs_pages` column or framework mechanism expressing "this page
belongs to plugin X". `#[AdminOnly]` is the only declarative page gate and it is
unrelated. Hiding is therefore done by writing `show_on_menu = false` on the
row, plus a constructor-level gate so direct access cannot flip it back.

### Two complementary mechanisms (both core-free, both plugin-local)

1. **Constructor gate (prevents re-flagging on access).**
   Pass the gate value as the `$shmenu` argument of the parent constructor:

   ```php
   // tarif_tarifas
   parent::__construct(__CLASS__, 'Tarifas', 'tarifario', FALSE, $this->tarifario_activo);
   // tarif_familias
   parent::__construct(__CLASS__, 'Familias', 'catalogo', FALSE, $this->tarifario_activo);
   ```

   `check_fs_page()` (`base/fs_controller.php:820-885`) then persists
   `show_on_menu` to match the gate on every visit. Without this, a direct visit
   while off would restore `show_on_menu = true`.

2. **Per-request `Init` sync (immediate, no visit required).**
   Add `syncTarifarioMenuPages()` to `catalogo_core\Init::init()` (runs every
   request via `Plugins::init()`, `src/Core/Kernel.php:59`; verified order:
   boot → Init → controller construction → menu build). For each of the two
   page names: `$p = (new fs_page())->get($name)`; if present and
   `$p->show_on_menu !== $enabled`, set and `save()`. Write only on mismatch;
   `fs_page::save()`/`delete()` clear the `m_fs_page_all` cache
   (`model/fs_page.php:171-208`), and `get()` is uncached (`:161-169`).

   Keep the row (do not delete it): flipping `show_on_menu` hides the entry,
   preserves title/folder for re-enable, and avoids orphaning `fs_rol_access`
   grants. Deleting (the `retire*Page()` pattern, `Init.php:415-537`) would lose
   the row and require controller access to recreate it after re-enable.

### Cost

Two uncached `SELECT`s + at most two `UPDATE`s per request, only when the flag
drifts. Acceptable; the framework already performs a `fs_pages` read on every
controller instantiation. Optional optimization (flag + re-check) rejected
because deactivation must be detected without a trigger.

---

## 6. clientes_core design

`clientes_core` is a secondary plugin with no dependency on `catalogo_core` or
`tarifario` (`fsframework.ini` `require = "business_data"`). It reaches
`\FSFramework\Core\Plugins` (core Composer autoload, loaded at
`index.php:59`), so the same string gate works.

Minimal gate:

- `controller/ventas_clientes.php`: expose `public bool $tarifario_activo`
  (set in `private_core()` from `Plugins::isEnabled('tarifario')`, or via the
  same gate service if it is made reusable). In `nuevo_grupo()`
  (`:317-334`), when the gate is off accept no `codtarifa` (persist `null`)
  instead of writing it.
- `view/ventas_clientes.html.twig`: guard the `tarifa-grupo` `<th>` (`:206`)
  and `<td>` (`:215`), and the `codtarifa` input (`:252-255`), with
  `{% if fsc.tarifario_activo %}`.
- `themes/AdminLTE/view/terceros/grupo_list.html.twig`: guard `<th>` (`:40`)
  and `<td>` (`:51`) with `fsc.tarifario_activo` (display only; no input).
  Confirm the template is actually reachable before investing here (Q5).

Models (`grupo_clientes::codtarifa`, `cliente`) need NO change: the data stays.

### SDD ownership note (hybrid)

This change touches `plugins/clientes_core/` but the primary beneficiary is
`catalogo_core`, so the SDD stays in `plugins/catalogo_core/openspec/`. The
clientes_core have only a `config.yaml` (no `specs/` yet), so there is no
existing requirement to delta; the change must list the clientes_core files in
scope and decide at archive whether to seed `clientes_core/openspec/specs/` or
record a follow-up (Q8).

---

## 7. File inventory

### catalogo_core — new

- `Services/TarifarioGate.php` — single source of truth.
- `extras/TarifarioSurfaceGateTrait.php` — `$tarifario_activo`,
  `init_tarifario_surface_gate()`, `guard_tarifario_surface()`.
- `tests/Services/TarifarioGateTest.php` (new, strict TDD).
- `tests/Extras/TarifarioSurfaceGateTraitTest.php` (new).
- `tests/Controller/TarifarioSurfaceGatingTest.php` (new: endpoint 404s + menu sync).

### catalogo_core — controllers

- `controller/tarif_tarifas.php` — constructor shmenu = gate; re-base
  `$tarifario_activo` on the gate; guard.
- `controller/tarif_familias.php` — constructor shmenu = gate; guard before
  `process_action()`.
- `controller/tarif_opcional_edit.php` — trait + guard.
- `controller/tarif_tab_precios.php` — trait + guard (both `rows` and
  `guardar_precio_tab`).
- `controller/tarif_opcional_tab.php` — trait + guard (both actions).
- `extras/TarifarioOpcionalStateTrait.php` — expose the gate / skip tarifa
  loading when off (or have consumers apply it).
- `extras/VentasArticulosListTrait.php` — `init_list_filters()` suppression.
- `extras/VentasOpcionalesListTrait.php` — `init_opcionales_list()` suppression.
- `Controller/VentasArticulos.php` — trait + bool exposure.
- `Controller/VentasArticulo.php` — expose bool; suppress `loadTarifarioState()`
  state when off.
- `Controller/VentasOpcionales.php` — trait + bool exposure.
- `Controller/VentasOpcional.php` — expose bool for the hook templates.
- `Init.php` — add `syncTarifarioMenuPages()` to `init()`; optionally register
  the gate.

### catalogo_core — views

- `View/ventas_articulos.html.twig` — guard the `b_codtarifa` filter block
  (`:83-92`) with `fsc.tarifario_activo`; columns already degrade via
  `tarifa_seleccionada`.
- `View/ventas_articulo.html.twig` — guard selector (`:41-74`) and per-tarifa
  pane (`:238-257`).
- `View/ventas_opcionales.html.twig` — guard `b_codtarifa` filter (`:317-334`),
  create prices (`:117-157`), export modal (`:203-254`), columns (`:385-398`,
  `:467-484`), and the `tarif_familias` button (`:196-199`).
- `View/partials/articulos/modal_nuevo_articulo.html.twig` — guard tarifa
  prices block (`:75-100`).
- `View/Hooks/ventas_opcional_tabs_after.html.twig` — add
  `and fsc.tarifario_activo` (`:6`).
- `View/Hooks/ventas_opcional_tab_pane_after.html.twig` — same (`:10`).
- `View/tarif_tarifas.html.twig` — no change (already reads
  `fsc.tarifario_activo`); page is 404-gated when off.
- `View/tarif_familias.html.twig`, `View/tarif_opcional_edit.html.twig` — no
  change needed if the page guard 404s.

### clientes_core

- `controller/ventas_clientes.php` — bool + `nuevo_grupo()` codtarifa gate.
- `view/ventas_clientes.html.twig` — th/td/input guards.
- `themes/AdminLTE/view/terceros/grupo_list.html.twig` — th/td guards (verify reachability first).
- `tests/` — new gate coverage under `plugins/clientes_core/tests/`.

### Tests at risk (must be updated in the same change; strict TDD)

- `tests/CatalogoOpcionalesHtmxContractTest.php:123,127,226`
- `tests/TarifOpcionalesHtmxContractTest.php:299,302,323`
- `tests/CatalogoOpcionalesUnifiedControllerTest.php:470-485,936-941`
- `tests/Controller/VentasOpcionalesControllerMasterStateTest.php:64-67`
- `tests/Controller/VentasArticulosListAbsorptionTest.php:412-434,469-500,660-690`
- `tests/VentasArticuloControllerTest.php:276-286`
- `tests/CaracteristicaReadThroughTest.php:311-339`
- `tests/Controller/VentasArticuloPerTarifaPaneTest.php`,
  `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`
- `tests/Controller/VentasArticulosListCaracteristicasTest.php`
- moved-page tests: `tests/TarifTabPreciosTest.php`,
  `tests/TarifFamiliasAddFamiliaTest.php`, `tests/TarifFamiliasToggleTest.php`,
  `tests/TarifFamiliasHierarchyTest.php`,
  `tests/Controller/TarifTarifasGuardarTest.php`,
  `tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php`,
  `tests/TarifOpcionalTabEndpointTest.php`,
  `tests/TarifFamiliasControllerContractTest.php`,
  `tests/TarifOpcionalesControllerContractTest.php`
- Ownership-guard tests that MUST keep passing unchanged:
  `tests/ArticuloTarifaPrecioOwnershipTest.php`,
  `tests/ArticuloListaCanonicaOwnershipTest.php`,
  `tests/ArticuloDetalleCanonicoOwnershipTest.php`,
  `tests/OpcionalDomainModelOwnershipTest.php`.

Test strategy: seed `$GLOBALS['plugins'] = ['tarifario', 'catalogo_core', ...]`
for "active" contracts (pattern already used in
`tests/CaracteristicaOwnershipTest.php:158,181,367`), and `[]` for the new
"inactive" contracts; restore in `tearDown()`.

---

## 8. Affected specs (deltas to write in `sdd-spec`)

Canonical specs live in `plugins/catalogo_core/openspec/specs/`. Proposed
delta domains:

- NEW domain `tarifario-surface-gating/spec.md` — ADDED requirements for:
  - the gate source (`Plugins::isEnabled('tarifario')`, bare-string, ownership-safe);
  - listing/create/edit surface suppression;
  - endpoint blocking for the five controllers (404 shape);
  - menu hiding for `tarif_tarifas` / `tarif_familias`;
  - data retention guarantee;
  - clientes_core minimal gate.
- `specs/articulo-lista-canonica/spec.md` — MODIFIED `ALC-01` (filter) and
  `ALC-02` (per-tarifa columns) to require the tarifario gate.
- `specs/articulo-detalle-canonico/spec.md` — MODIFIED `ART-09` (selector),
  `ART-10` (per-tarifa read path), and `ART-11` (extend the no-active-tarifas
  degradation to the plugin-inactive case).
- `specs/articulo-tarifa-tab-management/spec.md` — MODIFIED `ATT-02`
  (endpoint) and `ATT-04` (canonical detail tab) to require the gate.
- `specs/opcionales-management/spec.md` — MODIFIED `OUM-02` (filters),
  `OUM-03` (per-tarifa state/price), `OUM-05` (creation prices),
  `OUM-07` (export parity), `OUM-10` (opcional Tarifas tab),
  `OUM-11` (surviving controllers gated).
- `specs/opcionales-tarifa-selector/spec.md` — MODIFIED `OTS-01`, `OTS-02`,
  `OTS-03` (these apply only while tarifario is active).
- `specs/familias-tarifa-management/spec.md` — MODIFIED "Menu integration &
  plain list retirement" to require plugin-gated `show_on_menu`.
- `specs/catalogo-render-hooks/spec.md` — MODIFIED "Hook context contract"
  to require `fsc.tarifario_activo` on the two opcional hooks (or an equivalent
  gate), if the gate is enforced inside the hook templates.
- `specs/opcionales-tarifa-management/spec.md` — MODIFIED "Master consumption
  in UI and export" / "Unchanged boundaries" only if the gate alters those
  contracts (verify during spec).
- `specs/caracteristicas-producto/spec.md` — likely NO change: CAR-19/CAR-20
  already define the enabled-plugin ownership scope; reference them from the
  new domain rather than redefining. Confirm the article-column self-gating
  claim with a test.
- clientes_core: no existing specs to delta (only `config.yaml`). Handle via
  the new domain + scope note, or seed `plugins/clientes_core/openspec/specs/`
  at archive (Q8).

---

## 9. Open questions / risks

Open questions for the maintainer before spec/design:

1. **404 vs redirect for the three pages.** Recommended 404 (honest, minimal).
   A 302 to `index.php`/`admin_home` is the friendlier alternative. Decision
   affects the endpoint-guard requirement text and its tests.
2. **Hide `en_catalogo`/`en_tarifa` characteristic columns on the article
   edit too?** Analysis says they already vanish because the ownership filter
   drops the definitions (`CaracteristicaValorBatchReader.php:139`,
   `CaracteristicaResolver.php:95-97`). Confirm no other render path (e.g. a
   cached column list) bypasses it, then treat as no-op.
3. **Re-check per request vs cache.** `$GLOBALS['plugins']` is request-stable;
   memoizing the gate per request is safe. Confirm the per-request `fs_pages`
   sync cost (2 SELECTs + rare UPDATE) is acceptable, or approve a cheaper
   trigger.
4. **`$GLOBALS['plugins']` unseeded in unit tests.** Fail-closed hides surfaces
   in tests that never seed plugins; the listed contract tests must seed
   `['tarifario']` or set `$controller->tarifario_activo = true`. Confirm the
   team accepts touching ~12-15 test files.
5. **`terceros/grupo_list.html.twig` reachability.** It appears unreferenced in
   `clientes_core` (the `ventas_grupo` controller is a redirect stub). Confirm
   before gating; it may be dead or consumed by another plugin by convention.
6. **Gate service vs reuse of `CaracteristicaOwnership`.** `CaracteristicaOwnership::is_active('tarifario')`
   already answers the question, but is semantically about feature definitions.
   A dedicated `TarifarioGate` is clearer; confirm naming/home (or approve
   extending the existing service).
7. **`fs_pages` sync home.** `Init::init()` is the immediate, core-free option;
   an alternative is a shared service invoked from `Init`. Also confirm we do
   NOT clean `fs_rol_access` rows (keep them; access is blocked by the endpoint
   guard, not by RBAC).
8. **Hybrid ownership / clientes_core specs.** The SDD lives in catalogo_core
   (main beneficiary). Confirm clientes_core is handled inside this change
   (scope note + no delta) vs a follow-up change in `plugins/clientes_core/openspec/`.
9. **Complete surface inventory.** Are there other tarifario-owned catalogo_core
   surfaces outside the listed files (e.g. `ventas_caracteristicas`,
   `VentasOpcionalGrupo(s)`, `tarif_familias` Excel actions)? Scope must be
   explicit before tasks.
10. **Vocabulary.** The word `tarifario` will now appear as a bare literal in
    catalogo_core and clientes_core source. Confirm the ownership tests
    (path/namespace substring checks) are the only constraint and a bare string
    is acceptable (it is, per `:218`).

Risks:

- **Test blast radius.** 38 catalogo_core test files mention `tarifario`; the
  ones that assert tarifa UI will change behavior under fail-closed. Strict TDD
  requires updating them with the plugin-seeding pattern in the same change.
- **Fail-closed default.** If a production install has `catalogo_core` enabled
  and `tarifario` disabled but still expects the tarifa data to be *visible*
  (read-only), this change hides it by design. Data stays, UI does not.
- **Menu sync correctness.** A per-request `fs_pages` write path must not fight
  `check_fs_page()`; the constructor gate and the sync must compute the same
  value, or the row can flip-flop each request.
- **Ownership-test breakage.** Any accidental `plugins/tarifario/` or
  `@tarifario/` string in production code fails the ownership tests; the gate
  must stay on the bare name.
- **Modern `Controller` vs legacy response helpers.** `ResponseTrait`/`notFound`
  do not exist; the guard must not assume them.

---

## 10. Recommended next phase

`sdd-propose` — capture intent, scope (explicit file inventory above), the
chosen mechanism (Option C), the endpoint response decision once Q1 is answered,
and the rollback plan (re-enable `tarifario`; the gate is a pure read of
`$GLOBALS['plugins']`, no destructive migration). Then `sdd-spec` for the new
`tarifario-surface-gating` domain plus the listed MODIFIED requirements.

Ready for proposal: Yes (with Q1–Q3 resolved by the maintainer; the rest can be
answered during spec/design).
