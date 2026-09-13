# Exploration: unify opcionales management into `ventas_opcionales` and retire `tarif_opcionales`

- **Change**: `unificar-opcionales-en-ventas-opcionales`
- **Owner (SDD)**: `plugins/catalogo_core/openspec/` (plugin-local, `ownership: plugin-local`)
- **Secondary plugin**: `tarifario` (retirement/repointing only — no new tarifario feature work)
- **Artifact store**: `openspec`
- **Exploration date**: 2026-09-12
- **Baseline suite**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **475 tests, 1517 assertions, OK**. This is the regression floor.
- **Change dir**: `plugins/catalogo_core/openspec/changes/unificar-opcionales-en-ventas-opcionales/`
- **Hard rule enforced**: all SDD artifacts live only under `plugins/catalogo_core/openspec/`; nothing is created in the core `openspec/`.

---

## 1. Intent

Consolidate the opcionales management surface so there is **one canonical page**, `ventas_opcionales`
(`Controller/VentasOpcionales.php`, PSR-4, `extends PageController`, menu `catalogo`), which absorbs
every richer behavior currently living on the legacy `tarif_opcionales` page (tarifa selector,
per-tarifa `activa`/`en_catalogo`/`en_tarifa` state, per-tarifa price display/edit, filters by
tarifa/familia/query, new-opcional creation, Excel export) and adopts **htmx 4 + Alpine.js CSP**.
`tarif_opcionales` is then **eliminated** (controller, view, tests, links, `fs_page` row) with no
redirect alias. Finally, the whole opcionales package becomes `catalogo_core`-owned: the injected
"Tarifas" tab on `ventas_opcional` (`ventas_opcional_tabs_after` + `ventas_opcional_tab_pane_after`
hooks, the opcional price-rows partial and the opcional surface of `tarif_tab_precios`) moves from
`tarifario` to `catalogo_core`, while the **article** surface of that same tab/controller stays in
`tarifario`.

These decisions are user-confirmed and are not re-litigated in this document.

---

## 2. Current-state findings (file:line evidence)

### 2.1 `tarif_opcionales` feature inventory (to port)

**Controller** — `plugins/catalogo_core/controller/tarif_opcionales.php` (`class tarif_opcionales extends fbase_controller`, `:39-41`):

| Behavior | Lines | Notes |
|---|---|---|
| Uses `TarifarioOpcionalStateTrait` | `:41` | loads `$tarifas`, `$tarifa_defecto`, `$codtarifa`, `run_in_transaction`, `parse_price_input` |
| `TOGGLE_ACTIONS` = `toggle_activa`, `toggle_en_catalogo`, `toggle_en_tarifa` | `:47-51` | |
| Public state props (`b_codfamilia`, `b_codtarifa`, `b_solo_activos`, `b_url`, `familia`, `offset`, `resultados`, `total_resultados`, `tarifa_seleccionada`) | `:53-61` | |
| `$precios_cache`, `$opcionales_state_cache` (private) | `:67, :73` | |
| Constructor: menu `tarifario` | `:75-78` | page `title` = "Opcionales" |
| `private_core()` dispatch: toggles `:91-93`, Excel `:94-97`, new opcional `:98-99`, delete `:100-102` | `:80-108` | then `ini_filters`/`search`/state cache/price cache |
| `ini_filters()`: offset, `b_codfamilia`, tarifa resolution (requested → default → first active), `b_solo_activos`, `b_url` | `:110-155` | `b_url` carries `query` + `b_codfamilia` + `b_codtarifa` + `b_solo_activos` |
| `search_opcionales()`: `tarif_opcional::search(...)` + `count_filtered(...)` for real total | `:157-171` | pagination by true total, not page size |
| `opcional_master_state()` overridable seam → `new tarif_tarifa_opcional()` | `:181-184` | |
| `opcional_precio_model()` overridable seam → `new tarif_opcional_precio()` | `:191-194` | |
| `load_opcionales_state_cache()`: `master->effective()` per row | `:200-215` | reads only, never persists |
| `load_precios_cache()`: price per row | `:222-239` | |
| `get_precio_opcional_tarifa($id)` | `:246-252` | |
| `opcional_activo_en_tarifa` reads `['activa']` | `:261-267` | master is sole activation source |
| `opcional_en_catalogo_tarifa` reads `['en_catalogo']` | `:276-282` | |
| `opcional_en_tarifa_flag` reads `['en_tarifa']` | `:290-296` | |
| `new_opcional()`: code gen, duplicate check, `familias[]`, per-tarifa `precio_tarifa_<code>` inputs via `floatval(str_replace(',','.',...))`, `set_precio_tarifa` | `:298-341` | **note**: no explicit CSRF check (relies on legacy `pre_private_core`) |
| `delete_opcional()`: GET `delete`, `allow_delete` gate | `:343-357` | |
| `paginas()` → `tarif_paginas()` (fbase pagination) | `:359-362` | trait `:142-145` calls `fbase_paginas` |
| `toggle_opcional_state()`: `guard_mutating_action()`, POST `id`+`codtarifa`, `set_*` | `:370-403` | |
| `guard_mutating_action()`: POST + `requireCsrf()` | `:410-418` | |
| `redirect_to_list()` preserves `query`, `b_codfamilia`, `b_solo_activos`, `offset` | `:425-444` | |
| `export_excel_opcionales()`: Spreadsheet, headers `Codigo (No editar)`, `Ref SAP:`, `Descripción`, `Precio`, `Familia`, `Subfamilia`; family/subfamily resolution via `tarif_familia->all()`; `all(0,99999)`; selected-tarifa price; download | `:450-611` | |

**View** — `plugins/catalogo_core/View/tarif_opcionales.html.twig`:

- Imports `Macro/TarifarioComponents.html.twig as tarif` and `Macro/Htmx.html.twig as htmx`; `htmx.boot({'allowScriptTags': false})` (`:1-7`).
- Legacy JS: `show_nuevo_opcional()`, `eliminar_opcional()` (bootbox), `$(document).ready` + `#nuevo` hash (`:9-42`).
- Toolbar: refresh, "Nuevo opcional" (modal), links `tarif_articulos`/`tarif_familias`, "Exportar Excel" modal (`:44-76`).
- Filters via `hx-get` + `hx-trigger` + `hx-target="body"` + `hx-select="body"` + `hx-swap="outerHTML"` + `hx-push-url` + `hx-boost`: `query` (Enter + click fallback `#input_query`, `:90-111`), `b_codfamilia` (`:117-130`), `b_codtarifa` (`:135-147`), `b_solo_activos` (`:153-160`), total badge (`:166`).
- Table columns Código / Ref ERP / Nombre / Familias / Precio / Estado / Tarifa / Catálogo / actions (`:172-293`):
  - row `clickableRow` href → `tarif_opcional_precios&id=...&codtarifa=...` (`:196`);
  - código link → `value.url()` (`:198`);
  - price cell → `fsc.show_precio(fsc.get_precio_opcional_tarifa(value.id), false, true, FS_NF0_ART)` (`:221-227`);
  - three independent POST toggle forms with `{{ csrf_field() }}`, hidden `id`,`codtarifa`,`query`,`b_codfamilia`,`b_solo_activos`,`offset`, and `tarif.toggle_button_group(...)` (`:231-269`);
  - actions: precios / edit / delete (`:271-285`).
- Pagination via `fsc.paginas()` (`:295-303`).
- "Nuevo opcional" modal with per-tarifa price inputs `precio_tarifa_<code>` + currency `fsc.simbolo_divisa(fsc.codtarifa)` and familia multi-select (`:305-388`).
- Export modal with selected-tarifa readout (`:390-433`).

### 2.2 Target `ventas_opcionales` architecture

- `Controller/VentasOpcionales.php` — `extends PageController` (`:17`), `resultados`/`search_query`/`allow_delete` (`:20-22`), page data `name=ventas_opcionales`, `title=Opcionales`, `menu=catalogo`, `showonmenu=true` (`:32-41`), `privateCore(&$response,$user,$permissions)` with `delete` POST + search by `search` (`:43-54`).
- Legacy wrapper `controller/ventas_opcionales.php` — `class ventas_opcionales extends \FSFramework\Plugins\catalogo_core\Controller\VentasOpcionales` (`:10-12`).
- View `View/ventas_opcionales.html.twig` — search form (`name="search"`), table, `{{ csrf_field() }}` at `:125`, legacy `delete_opcional()` bootbox + `form_token` hidden field (`:4-31`), no `|raw`.

**What `PageController` provides vs what the legacy base + trait need**

`PageController` (`src/Controller/PageController.php:13-15`) extends `FSFramework\Core\Base\Controller` (`src/Core/Base/Controller.php`). It provides `request`, `db`, `user`, `permissions`, `core_log`, `new_error_msg/new_message/new_advice`, `url()`, `redirect()`, `validateFormToken()` (`:465-482`, reads `CsrfManager::FIELD_NAME` = `_csrf_token` then `_token`), `setTemplate()`/`$template`, `run()`, `getPageData()`, extension/menu plumbing.

It does **not** provide the `fs_controller`/`fbase_controller` helpers the legacy opcionales code relies on:

| Helper used by `tarif_opcionales`/trait | Defined at | Present on PageController? |
|---|---|---|
| `getRequest()` | `base/fs_controller.php:343` | No (use `$this->request`) |
| `requireCsrf()` | `base/fs_controller.php:449` | No (use `validateFormToken()`) |
| `isHtmxRequest()` | `base/fs_controller.php:485` | No |
| `show_precio()` | `base/fs_controller.php:1177` | No |
| `simbolo_divisa()` | `base/fs_controller.php:1197` | No |
| `fbase_paginas()` | `plugins/catalogo_core/extras/fbase_controller.php:116` | No |
| `$allow_delete` auto-set | `fbase_controller.php:44-52` | No (manually set in `VentasOpcionales`/`VentasOpcional`) |
| `$query` | `fs_controller` public prop | No (read from request) |
| `tarif_paginas()` (trait) | `TarifarioOpcionalStateTrait.php:142-145` | No — it calls `$this->fbase_paginas()` |

The trait itself (`extras/TarifarioOpcionalStateTrait.php`) is otherwise portable: `init_tarifario_opcional_state()` (`:93-130`) uses models + `$_REQUEST`; `run_in_transaction()` (`:184-221`) uses `$this->db`; `parse_price_input()` (`:241-253`) is pure. Only `tarif_paginas()` binds to `fbase_controller`.

### 2.3 Prices / state sources

- `model/tarif_tarifa_opcional.php` — master `(codtarifa, id_opcional)`: `effective()` (`:391-413`), `resolve_activa()` (`:423-428`), `resolve_en_catalogo()` (`:437-442`), `resolve_orden()` (`:451-456`), `get()` (`:191-199`), `set_activa/set_en_catalogo/set_en_tarifa/set_orden` (`:467-512`), `persist_toggle()` (`:526-536`), `update_single_field()` (`:547-562`), `inherited_row()` (`:572-584`).
- `model/tarif_opcional_precio.php` — adapter `extends catalogo_opcional_precio`; `get()` keyed by `codlista` (= `codtarifa`); `url()` (`:91-98`) → `tarif_opcionales` fallback when `id_opcional` is null, else `tarif_opcional_edit`.
- `model/tarif_tarifa.php` — `all_activas()` (`:232-242`), `get_default()` (`:116-123`), `coddivisa` (`:64`).
- `model/tarif_opcional.php` — `search()` (`:245-316`), `count_filtered()` (`:329-372`), `all()` (`:374-388`), `url()` (`:98-105`, fallback `tarif_opcionales`), `get_precio_tarifa()` (`:396-400`), `set_precio_tarifa()` (`:422-437`).

**Repoint requirement**: `tarif_opcional::url():101` and `tarif_opcional_precio::url():94` return `index.php?page=tarif_opcionales` only when no `id` exists; the `id` branch correctly remains `tarif_opcional_edit` (kept reachable). These two fallbacks must point to `ventas_opcionales`.

### 2.4 Tarifario opcionales leftovers inventory

| Artifact | Evidence | Action |
|---|---|---|
| `Init.php` opcional hook map entries | `plugins/tarifario/Init.php:67-68` (inside `HOOK_TEMPLATES:64-69`), registered at `:137-139`/`:146-157` | Remove the 2 opcional entries; keep the 2 artículo entries |
| `View/Hooks/ventas_opcional_tabs_after.html.twig` | 10 lines, endpoint `page=tarif_tab_precios&action=rows&tipo=opcional&ref={{ fsc.opcional.id }}` | Move to catalogo_core |
| `View/Hooks/ventas_opcional_tab_pane_after.html.twig` | 10 lines, pane `#tab_tarifario_precios`, includes shared save script | Move to catalogo_core |
| `View/Hooks/partials/opcional_precios_rows.html.twig` | 51 lines; uses `fsc.simbolo_divisa(tarifa.coddivisa)` (`:32`) | Move to catalogo_core (needs a currency helper or legacy base) |
| `View/Hooks/partials/tab_save_script.html.twig` | shared by artículo + opcional panes | Split: keep artículo in tarifario, opcional copy in catalogo_core |
| `View/Hooks/partials/articulo_precios_rows.html.twig` | artículo surface | Stays in tarifario |
| `controller/tarif_tab_precios.php` opcional surface | `rows_tipo()` `:93-97`; `render_opcional_rows_fragment()` `:125-140`; `opcional_precio_model()` `:145-148`; `guardar_precio_opcional()` `:177-214`; `puede_editar_opcional()` `:220-229`; already `require_once`s `plugins/catalogo_core/model/tarif_opcional_precio.php` (`:22`) | Extract to a catalogo_core endpoint; artículo surface stays |
| Tarifario view links to `tarif_opcionales` | `View/tarif_actualizar_precios.html.twig:75`, `View/tarif_articulo_precios.html.twig:201`, `View/tarif_articulos.html.twig:199`, `View/tarif_historial_precios.html.twig:55` | Repoint to `ventas_opcionales` |
| `tests/Integration/HookRegistrationTest.php` | `FROZEN_HOOKS:55-60`; opcional guarded render `:166-181`; host-view opcional render `:240-266`; bare-`simbolo_divisa` scan `:217-233` | Update: drop opcional hooks from tarifario; add a catalogo_core hook-registration test |
| catalogo_core views referencing `tarif_opcionales` | `View/tarif_opcional_edit.html.twig:68,511`, `View/tarif_opcional.html.twig:68,330`, `View/tarif_opcional_precios.html.twig:15,329` | Repoint back-links to `ventas_opcionales` |

Note: `Services/CatalogLegacyTableMigration.php:19,35`, `Services/TarifOpcionalExtMigration.php:61` and `DeadOpcionalTableReferenceTest` reference the **historical DB table name** `tarif_opcionales`, not the page. These are unrelated to the page retirement and must NOT be changed.

### 2.5 Hook / tab ownership move mechanics

- `ViewHookRegistry::register()` (`src/View/ViewHookRegistry.php:21-30`) appends templates per hook (idempotent per template); `render()` (`:37-53`) renders each in order and swallows+logs a throwing template.
- `render_hook()` Twig function is bound in `src/Core/Html.php:417-419` with `is_safe: ['html']`.
- **Currently only `tarifario` registers hooks** (`plugins/tarifario/Init.php:28,137-139,146-157`). `catalogo_core/Init.php` does not import `ViewHookRegistry` and does not subscribe to `TwigInitEvent`.
- The host markers are catalogo_core-owned and frozen: `View/ventas_opcional.html.twig:127` (`ventas_opcional_tabs_after`) and `:421` (`ventas_opcional_tab_pane_after`); the article pair lives in `View/ventas_articulo.html.twig`. The four markers are locked byte-for-byte by `CatalogoCoreHookMarkersTest` (names `:55-60`, whitespace/frozen-context regex `:165-169`).
- **To move ownership**: `catalogo_core/Init.php` must subscribe to `TwigLoaderEvent` (register `@catalogo_core` namespace if needed) and `TwigInitEvent`, then `ViewHookRegistry::register('ventas_opcional_tabs_after', '@catalogo_core/Hooks/...')` + the pane hook, with a static idempotency guard mirroring `tarifario\Init::$hooksRegistered`. `tarifario` then registers only the `ventas_articulo_*` pair. The opcional rows endpoint must move into a catalogo_core controller (candidate: extend the existing `tarif_opcional_precios` surface or add a dedicated catalogo_core tab controller on `fbase_controller`, which already has `simbolo_divisa`/`requireCsrf`; a `PageController` would need a currency helper).
- The article half of `tarif_tab_precios` keeps working unchanged because `ventas_articulo_*` hooks and `tarif_tab_precios`'s article path stay in tarifario.

### 2.6 Locked contracts / regression surface

| Test (relative to `plugins/catalogo_core/`) | What it locks | Action |
|---|---|---|
| `tests/VentasOpcionalesControllerTest.php` | file exists (`:20-24`), `privateCore` (`:26-37`), `name=ventas_opcionales`/`showonmenu` strings (`:39-54`), legacy wrapper subclass (`:56-68`), view has `{{ csrf_field() }}` + no `|raw` (`:70-86`) | **KEEP** (must remain green after the view rewrite) |
| `tests/VentasOpcionalControllerTest.php` | edit-page controller/wrapper/view contracts | **KEEP** |
| `tests/TarifOpcionalesControllerContractTest.php` | four slugs incl. `tarif_opcionales` (`:39-44`), all four `extends fbase_controller` (`:80-97`), zero `tarif_controller` (`:99-108`), zero tarifario model requires (`:110-124`), trait use (`:126-135`) | **UPDATE**: drop `tarif_opcionales` from `CONTROLLER_SLUGS` |
| `tests/TarifOpcionalesControllerMasterStateTest.php` | list constants (`:41,45`), `buildListController` (`:168-185`), list behavior (`:229-258`), list source (`:327-360`), list redirect (`:511-537`), shared views (`:539-555`), list CSRF/action (`:557-570`); edit/precios contracts (`:260-303,362-509`) | **UPDATE**: repoint LIST constants + behavior/source assertions to `VentasOpcionales.php` / `ventas_opcionales.html.twig`; keep edit/precios untouched |
| `tests/TarifOpcionalesHtmxContractTest.php` | edit + precios htmx/Alpine (`:116-257`), list filters (`:36,53-56,259-296`), list locked names/CSRF (`:298-335`) | **UPDATE**: drop/repoint `LIST_VIEW`; keep edit/precios |
| `tests/TarifConfiguradorOpcionalesTest.php` | configurator-only (zero tarifario, consts, SQL helpers, htmx_tree, JSON, CSRF) | **KEEP** |
| `tests/TarifOpcionalEditTarifaSelectorTest.php` | selector resolution (`:112-174`), selector htmx contract (`:180-213`), scoped panel (`:219-236`), overview (`:238+`) | **KEEP** (preserved work) |
| `tests/TarifOpcionalPreciosControllerTest.php` | adapter + precios controller | **KEEP** |
| `tests/Integration/CatalogoCoreHookMarkersTest.php` | the four frozen host markers | **KEEP** (host views unchanged) |
| `tests/OpcionalDomainModelOwnershipTest.php` | 8 moved models stay in catalogo_core | **KEEP** |
| `tests/DeadOpcionalTableReferenceTest.php` | dead DB tables, not the page | **KEEP** |
| `tests/Services/CatalogLegacyTableMigrationTest.php`, `tests/Services/TarifOpcionalExtMigrationCopyTest.php` | legacy DB table names | **KEEP** |
| `plugins/tarifario/tests/Integration/HookRegistrationTest.php` | registration of all 4 hooks; opcional tab render | **UPDATE/MOVE**: drop opcional hooks from tarifario; add catalogo_core equivalent |
| **NEW** `tests/CatalogoOpcionalesUnifiedControllerTest.php` | unified list absorbs search/state/price/toggles/export + link repoints | **CREATE** |
| **NEW** catalogo_core hook-ownership test | catalogo_core registers/renders the opcional tab without tarifario | **CREATE** |

The `{{ csrf_field() }}` string in `VentasOpcionalesControllerTest` is satisfied by the modern `csrf_field()` Twig function; `validateFormToken()` reads `CsrfManager::FIELD_NAME` = `_csrf_token` (`src/Security/CsrfManager.php:65`), which is what `csrf_field()` emits.

### 2.7 htmx 4 + Alpine patterns to reuse

- `themes/AdminLTE/view/Macro/Htmx.html.twig:45-87` — `htmx.boot(config)`: optional `allowScriptTags:false` scrubber (`:46-79`), nonce'd inherited `hx-headers` `X-CSRF-TOKEN` on `document.documentElement` (`:80-85`), nonce'd deferred `view/js/htmx.min.js` (`:86`).
- `themes/AdminLTE/view/Macro/Alpine.html.twig:31-33` — nonce'd deferred `view/js/alpine-csp.min.js`.
- `plugins/catalogo_core/View/tarif_familias.html.twig` — `htmx.boot()` (`:13`), `[x-cloak]` (`:28`), `x-data`/`x-cloak` (`:220`), `hx-post` (`:232`), `hx-get` (`:257`), `Alpine.data(...)` + `alpine:init` guard (`:328-436`), `alpine.boot()` after registration (`:442`).
- Full-page filter pattern already used by `tarif_opcionales.html.twig:90-160`: `hx-get` + `hx-trigger` + `hx-target="body"` + `hx-select="body"` + `hx-swap="outerHTML"` + `hx-push-url="true"` + `hx-boost="true"`.
- htmx 4 colon events only (`htmx:after:swap`, `htmx:after:request`); v2 names (`htmx:afterSwap`, `htmx:afterRequest`) are banned (asserted by `TarifOpcionalesHtmxContractTest:187-195` and `tarifario` pilot `:326-337`).
- Nonce via `{{ csp_nonce_attr() }}` (`src/Core/Html.php:304-312`).

---

## 3. Target architecture decision

**Decision: `VentasOpcionales` stays on `PageController`; the missing legacy helpers are ported into the unified controller and/or a self-contained trait.**

Rationale:

1. **Contract shape**: `VentasOpcionales` is a PSR-4 modern page dispatched through `PageController::run()` / `privateCore($response,$user,$permissions)`, with a legacy wrapper subclass. Switching it to `fbase_controller` (which extends `fs_controller` with a no-arg `private_core()` and a different lifecycle) would break the modern dispatch, the PSR-4+legacy bridge pattern, and diverge from sibling `VentasArticulos`/`VentasOpcional` (`Controller/VentasArticulos.php:38`, `Controller/VentasOpcional.php:27`).
2. **Regression surface**: `VentasOpcionalesControllerTest` never asserts the parent class, but it does require the modern class + wrapper + `privateCore`; `TarifOpcionalesControllerContractTest` locks only the three surviving opcional controllers (`tarif_opcional_edit`/`precios`/`configurador`) to `fbase_controller`. Keeping the surviving trio on `fbase_controller` and the unified list on `PageController` satisfies both.
3. **Portability of the logic**: `TarifarioOpcionalStateTrait` is portable except `tarif_paginas()`; the unified list can paginate natively (mirroring `VentasArticulos::hasMoreResults()/getPaginationUrl()/buildListUrl()`, `:477-514`) and use `validateFormToken()` for CSRF instead of `requireCsrf()`. `run_in_transaction()` and `parse_price_input()` work as-is.
4. **Recommended extraction**: move the list-only logic from `tarif_opcionales` (`ini_filters`, `search_opcionales`, `load_opcionales_state_cache`, `load_precios_cache`, accessors, `toggle_opcional_state`, `redirect_to_list`) into `VentasOpcionales` (or a controller-agnostic trait) so it is testable via the same `opcional_master_state()`/`opcional_precio_model()` seams.
5. **Missing helpers to port explicitly**:
   - pagination: native controller pagination (no `fbase_paginas`);
   - CSRF: `validateFormToken()` (already used by this controller) for all mutations, including `new_opcional` (closing the current missing-CSRF gap at `tarif_opcionales.php:298-341`);
   - currency/price display: reuse `View/tarif_opcional_precios.html.twig`'s model-level helpers or a small formatter; `fbase/fs_controller::show_precio()`/`simbolo_divisa()` are not available on `PageController` (note `fs_controller::simbolo_divisa` has an internal `divisa_tools` dependency and an EUR fallback, `:1197-1210`);
   - `$query`: read `query` (primary) from the request, optionally accept `search` as an alias.
6. **Non-goal**: do not migrate `tarif_opcional_edit`/`precios`/`configurador` off `fbase_controller` (locked by three tests).

**Fork summary — PageController (A) vs fbase_controller (B):**

| Criterion | A: stay on `PageController` | B: switch to `fbase_controller` |
|---|---|---|
| Modern dispatch/`privateCore` signature | Preserved | Broken (needs legacy `private_core()` + `run()` rewrite) |
| Legacy wrapper subclass test | Green | Requires wrapper/lifecycle redesign |
| Sibling consistency | Matches `VentasArticulos`/`VentasOpcional` | Diverges |
| Helper availability | Must port pagination/CSRF/currency | `show_precio`/`simbolo_divisa`/`fbase_paginas` free |
| Trait reuse | Port `tarif_paginas` call away | Trait works as-is |
| Risk to locked contracts | Low | High |
| **Recommendation** | **CHOSEN** | Rejected |

---

## 4. Options considered

| Option | Description | Verdict |
|---|---|---|
| **A (recommended)** | Keep `VentasOpcionales` on `PageController`; absorb the `tarif_opcionales` list logic + view into `ventas_opcionales`; migrate to htmx 4 + Alpine; delete `tarif_opcionales`; repoint links; retire its `fs_page`; move the opcional tab/endpoint ownership to catalogo_core | Best fit: single canonical page, preserves locked modern controller, keeps surviving opcional controllers untouched |
| B | Switch the unified controller to `fbase_controller` to inherit legacy helpers | Rejected: breaks modern lifecycle/wrapper; high blast radius for a UI consolidation |
| C | Keep `tarif_opcionales` as a thin alias/redirect to `ventas_opcionales` | Rejected by user decision (no redirect alias; eliminate entirely) |
| D | Move only the list, leave the tab ownership in tarifario | Rejected by user decision (whole opcionales package must be catalogo_core-owned) |
| E | Absorb the list but keep it jQuery/bootbox | Rejected: user decision mandates htmx 4 + Alpine |

---

## 5. Risks

| # | Risk | Likelihood | Mitigation |
|---|---|---|---|
| R1 | `tarif_opcionales` deletion leaves a dangling `fs_page` menu row (broken menu item) | High | Add an idempotent page-retirement step in `catalogo_core/Init.php::upgrade()` mirroring `retireVentasFamiliasPage()` (`Init.php:162-174`) |
| R2 | Master-state / htmx lock tests still point at the deleted list files | High | Update `TarifOpcionalesControllerMasterStateTest` and `TarifOpcionalesHtmxContractTest` to the unified controller/view; run the plugin suite after each WU |
| R3 | CSRF mismatch between `csrf_field()` (`_csrf_token`) and legacy `form_token` in JS / `validateFormToken()` | Med | Standardize on `csrf_field()` + `validateFormToken()`; assert both in the new contract test; close the `new_opcional` CSRF gap |
| R4 | Escaping/XSS: current view uses `show_precio` output and legacy JS string interpolation (`value.nombre|e('js')`) | Med | Twig autoescape for all outputs; no `|raw` (locked by `VentasOpcionalesControllerTest:81-85`); Alpine `x-text` instead of `innerHTML` |
| R5 | htmx v2 event names creep into the migrated list/tab | Med | Assert colon names and `assertStringNotContainsString` for v2 names (mirror `TarifOpcionalesHtmxContractTest:187-195`) |
| R6 | Alpine CSP build silently drops rich inline expressions | Med | Keep logic in `Alpine.data()` registered from a nonce'd classic script with the `alpine:init` guard + `[x-cloak]` (mirror `tarif_familias.html.twig:328-442`) |
| R7 | Cross-plugin retirement: tarifario still registers/renders the opcional tab or links to the dead page | High | Move hook registration to catalogo_core with an idempotency guard; update `HookRegistrationTest`; repoint the 4 tarifario view links; grep-audit for `tarif_opcionales` before archive |
| R8 | The opcional tab endpoint renders currency via `fsc.simbolo_divisa` (legacy-only) | Med | Keep the moved endpoint on `fbase_controller` (or port a formatter to `PageController`); assert no bare `fsc.simbolo_divisa()` under the new hooks tree |
| R9 | `tarif_tab_precios` split leaves the article surface broken | Med | Keep the artículo path byte-identical; only extract the opcional methods/fragment; assert article rows via a contract test |
| R10 | Excel export parity regression (family/subfamily, selected-tarifa price, headers) | Med | Port `export_excel_opcionales()` logic verbatim; add a source/behavior contract test for headers + filter inheritance |
| R11 | Pagination divergence: `fbase_paginas` vs native pagination changes page-link behavior | Med | Use the `VentasArticulos` pagination contract; keep `offset`/`query`/`b_*` in the URL; assert in the new contract test |
| R12 | `tarif_opcional::url()`/`tarif_opcional_precio::url()` no-id fallback still points at the dead page | Med | Repoint both to `ventas_opcionales`; add a source assertion |

---

## 6. Recommended scope split (small, independently testable work units)

Each WU is strict-TDD (tests first, red → green) and must end with the full plugin suite green
(`ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`, baseline **475 tests / 1517 assertions OK**).

### WU-1 — Unified rich list controller (`ventas_opcionales` absorbs `tarif_opcionales`)
- Port into `Controller/VentasOpcionales.php` (or a controller-agnostic trait): tarifa resolution, filters
  (`query`, `b_codfamilia`, `b_codtarifa`, `b_solo_activos`, `offset`), `search`+`count_filtered`,
  master-state + price caches, accessors, POST+CSRF toggles, new-opcional creation (with CSRF),
  delete, Excel export, native pagination.
- Keep the `opcional_master_state()` / `opcional_precio_model()` seams for unit tests.
- **Rollback boundary**: new/changed `Controller/VentasOpcionales.php` only; legacy `tarif_opcionales` still present.
- **Tests**: update `TarifOpcionalesControllerMasterStateTest` list portions + new `CatalogoOpcionalesUnifiedControllerTest`.

### WU-2 — Unified list view on htmx 4 + Alpine
- Rewrite `View/ventas_opcionales.html.twig` with `Macro/Htmx.html.twig` + `Macro/Alpine.html.twig`,
  `htmx.boot({'allowScriptTags': false})`, full-page filter swaps, Alpine CSP components for
  confirm/new-opcional/export, `tarif.toggle_button_group(...)`, `{{ csrf_field() }}`, no `|raw`, no bootbox.
- **Rollback boundary**: `View/ventas_opcionales.html.twig` only.
- **Tests**: new htmx/Alpine contract for the unified list; keep `VentasOpcionalesControllerTest` green.

### WU-3 — Delete `tarif_opcionales` and repoint every link
- Delete `controller/tarif_opcionales.php`, `View/tarif_opcionales.html.twig`, and the list-specific tests.
- Repoint `model/tarif_opcional.php:101`, `model/tarif_opcional_precio.php:94`, the three catalogo_core
  opcional views (`tarif_opcional_edit`, `tarif_opcional`, `tarif_opcional_precios`) and the four tarifario
  views (`tarif_actualizar_precios`, `tarif_articulo_precios`, `tarif_articulos`, `tarif_historial_precios`).
- Retire the `tarif_opcionales` `fs_page` row in `catalogo_core/Init.php::upgrade()`.
- **Rollback boundary**: deletion + link edits; revert restores the page.
- **Tests**: `TarifOpcionalesControllerContractTest` drops the slug; grep-audit for `tarif_opcionales`.

### WU-4 — Move the opcional Tarifas tab ownership to catalogo_core
- Add `View/Hooks/ventas_opcional_tabs_after.html.twig`, `ventas_opcional_tab_pane_after.html.twig`,
  `partials/opcional_precios_rows.html.twig` and the opcional save-script under `plugins/catalogo_core/View/Hooks/`.
- Register the two opcional hooks from `catalogo_core/Init.php` (TwigLoaderEvent + TwigInitEvent, idempotent guard).
- Add a catalogo_core-owned endpoint for the opcional rows/save (candidate: extend `tarif_opcional_precios`
  or a dedicated `fbase_controller` tab controller).
- Remove the opcional entries from `tarifario/Init.php:67-68` and delete tarifario's opcional hook templates.
- **Rollback boundary**: catalogo_core new files/Init registration + tarifario opcional-hook removals; artículo hooks untouched.
- **Tests**: new catalogo_core hook-registration/render test; update `tarifario/tests/Integration/HookRegistrationTest`.

### WU-5 (optional) — htmx 4 + Alpine for the moved opcional tab
- Migrate the moved rows/save fragment and the pane to htmx 4 + Alpine CSP (colon events, nonce'd
  `Alpine.data()`, `hx-post` + inherited CSRF header), preserving currency-per-tarifa rendering.
- **Rollback boundary**: catalogo_core hook templates + endpoint only.

### WU-6 — Verify pass
- Run the full plugin suite; grep-audit for `tarif_opcionales` (page links) and for `bootbox`/`|raw` in the
  unified views; confirm no v2 htmx event names; produce `verify-report.md`.

**Explicitly out of scope**: `tarif_opcional_edit`, `tarif_opcional_precios`, `tarif_configurador_opcionales`
(kept catalogo_core-owned, `fbase_controller`, reachable), the `tarif_tarifa_opcional` master model,
`tarif_opcional_precio` adapter, the **article** surface of `tarif_tab_precios`, and the historical
`tarif_opcionales` DB-table migration services.

---

## 7. Evidence summary (files inspected)

- `plugins/catalogo_core/controller/tarif_opcionales.php`
- `plugins/catalogo_core/View/tarif_opcionales.html.twig`
- `plugins/catalogo_core/controller/tarif_opcional_edit.php`
- `plugins/catalogo_core/controller/tarif_opcional_precios.php`
- `plugins/catalogo_core/Controller/VentasOpcionales.php`
- `plugins/catalogo_core/Controller/VentasOpcional.php`
- `plugins/catalogo_core/Controller/VentasArticulos.php`
- `plugins/catalogo_core/controller/ventas_opcionales.php`
- `plugins/catalogo_core/View/ventas_opcionales.html.twig`
- `plugins/catalogo_core/View/ventas_opcional.html.twig`
- `plugins/catalogo_core/View/tarif_opcional_precios.html.twig`
- `plugins/catalogo_core/View/Macro/TarifarioComponents.html.twig`
- `plugins/catalogo_core/View/tarif_familias.html.twig`
- `plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php`
- `plugins/catalogo_core/extras/fbase_controller.php`
- `plugins/catalogo_core/model/tarif_opcional.php`
- `plugins/catalogo_core/model/tarif_opcional_precio.php`
- `plugins/catalogo_core/model/tarif_tarifa_opcional.php`
- `plugins/catalogo_core/model/tarif_tarifa.php`
- `plugins/catalogo_core/Init.php`
- `plugins/catalogo_core/openspec/config.yaml`
- `plugins/catalogo_core/tests/VentasOpcionalesControllerTest.php`
- `plugins/catalogo_core/tests/VentasOpcionalControllerTest.php`
- `plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php`
- `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php`
- `plugins/catalogo_core/tests/TarifOpcionalesHtmxContractTest.php`
- `plugins/catalogo_core/tests/TarifOpcionalEditTarifaSelectorTest.php`
- `plugins/catalogo_core/tests/TarifOpcionalPreciosControllerTest.php`
- `plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php`
- `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`
- `plugins/tarifario/Init.php`
- `plugins/tarifario/controller/tarif_tab_precios.php`
- `plugins/tarifario/View/Hooks/ventas_opcional_tabs_after.html.twig`
- `plugins/tarifario/View/Hooks/ventas_opcional_tab_pane_after.html.twig`
- `plugins/tarifario/View/Hooks/partials/opcional_precios_rows.html.twig`
- `plugins/tarifario/tests/Integration/HookRegistrationTest.php`
- `src/Controller/PageController.php`
- `src/Core/Base/Controller.php`
- `src/View/ViewHookRegistry.php`
- `src/Core/Html.php`
- `src/Security/CsrfManager.php`
- `base/fs_controller.php`
- `themes/AdminLTE/view/Macro/Htmx.html.twig`
- `themes/AdminLTE/view/Macro/Alpine.html.twig`

---

## 8. Open questions (resolve in spec/design)

1. **List logic placement**: inline in `VentasOpcionales` vs a controller-agnostic trait? Recommended: a
   self-contained trait so the existing seam-based behavior tests can target it without a controller boot.
2. **Endpoint for the moved opcional tab**: extend `tarif_opcional_precios` with a `rows`/`guardar` action,
   or add a dedicated catalogo_core tab controller? Recommended: dedicated catalogo_core controller on
   `fbase_controller` (currency helper + `requireCsrf` already available).
3. **Filter key**: keep `query` (tarif_opcionales continuity + master-state redirect contract) and accept
   `search` as an alias, or standardize on `search`? Recommended: keep `query`, alias `search`.
4. **Full-page `hx-select="body"` vs a fragment endpoint** for the list: start with the proven body-swap
   pattern; introduce fragments only where UX demands.
