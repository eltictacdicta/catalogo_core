# Exploration: restore percentage pricing and groups, and unify the single-opcional detail

- **Change**: `opcionales-detalle-porcentaje-y-grupos`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Artifact store**: `openspec` (plugin-local only; nothing is written to the core `openspec/`)
- **Change dir**: `plugins/catalogo_core/openspec/changes/opcionales-detalle-porcentaje-y-grupos/`
- **Exploration date**: 2026-09-12
- **Baseline suite**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **511 tests / 1756 assertions OK** (25 warnings, 1 skipped). This is the regression floor; recomputed in this exploration.
- **User-confirmed decisions** (not re-litigated): (1) `tarif_opcional_edit` is the canonical single-opcional detail and absorbs `tarif_opcional_precios`, which is eliminated with no alias; (2) restore `tipo_precio=porcentaje` across the whole UI/export; (3) restore the opcional groups in the unified list (column + management link + evaluate filter/assignment); (4) htmx 4 + Alpine.js for the unified detail.

---

## 1. Intent

Repair two regressions introduced when the opcionales list was unified into `ventas_opcionales` and finish the single-opcional consolidation:

1. **Percentage pricing regression** — `catalogo_opcional` still supports `tipo_precio=Fijo|porcentaje` with a `porcentaje` value (`catalogo_opcional::es_precio_porcentaje()`, `model/core/catalogo_opcional.php:85`), and `Controller/VentasOpcional.php:198-236` still edits it, but every opcionales surface touched by the unification renders/edits only a fixed `precio`. The unified list, the create modal, the injected "Tarifas" tab, the Excel export and the detail views must show a percentage when `es_precio_porcentaje()` is true.
2. **Groups regression** — the pre-unification `View/ventas_opcionales.html.twig` (`git show HEAD:View/ventas_opcionales.html.twig`) rendered a "Grupo" column via `{{ opcional.etiqueta_grupo() }}` (HEAD:91) and a groups management link (HEAD:48). The rewritten unified list dropped the **column** and has no group **filter**; it does not offer group **assignment** in the create modal (the full `ventas_opcional` form does, `Controller/VentasOpcional.php:213-214`).

Plus the confirmed consolidation: **`tarif_opcional_precios` is eliminated** and its per-tarifa selector + scoped price/state panel absorbed by `tarif_opcional_edit`, which stays the single-opcional detail (htmx 4 + Alpine.js).

**Non-goal / out of scope**: the `tarif_tarifa_opcional` master model, `tarif_configurador_opcionales`, `tpvmod`, the historical `tarif_opcional_precios` **DB table** migration services (`Services/CatalogLegacyTableMigration.php:355-484`), and the tarifario article surface.

> **Correction with evidence (decision unaffected):** the confirmed note says the rewritten list dropped *both* the group column and the groups management link. The column is indeed gone, but the management link **survived** the rewrite: `View/ventas_opcionales.html.twig:149` already points to `index.php?page=ventas_opcional_grupos`. So the groups WU is really *column + filter + create-modal assignment + parity*, not "re-add the button".

---

## 2. Current-state findings (file:line evidence)

### 2.1 Percentage regression inventory

| # | File | Behavior today | Correct behavior |
|---|---|---|---|
| P1 | `extras/VentasOpcionalesListTrait.php:283-299` (`load_precios_cache`) | Stores only `['precio' => $precio->precio, 'en_catalogo' => ...]`; drops the row's `porcentaje` and the opcional's `tipo_precio`. | Also carry the mode/percentage, e.g. `'porcentaje' => $precio ? $precio->porcentaje : null` and/or consult `$opcional->es_precio_porcentaje()`. |
| P2 | `extras/VentasOpcionalesListTrait.php:305-312` (`get_precio_opcional_tarifa`) | Returns the raw `precio` (0.0 for a percentage row) or `null`. | Keep as the fixed-price accessor; add a percentage-aware accessor (or return the row's percentage). |
| P3 | `extras/VentasOpcionalesListTrait.php:355-367` (`show_precio_opcional`) | Always `CatalogoCurrencyFormatter::format((float)$precio, $coddivisa)` → renders `0,00 €` / `-` for percentage opcionales. | Branch on `es_precio_porcentaje()` and render `etiqueta_precio_lista($codtarifa)` (e.g. `12,5%`). The canonical helper already exists (`catalogo_opcional.php:144-156`). |
| P4 | `extras/VentasOpcionalesListTrait.php:468-548` (`new_opcional`) | Reads `codigo/nombre/descripcion/ref_sap/familias[]/precio_tarifa_<code>` only; sets `$opcional->precio = 0`; calls `set_precio_tarifa()`. | Read `tipo_precio`, `porcentaje` and `id_grupo`; when percentage, set `tipo_precio`/`porcentaje` and write the percentage for each tarifa (new writer); when fixed, write price and clear any stale percentage. |
| P5 | `extras/VentasOpcionalesListTrait.php:800-827` (`export_excel_opcionales`) | Writes the numeric `$precio` to column D for every row. | When `es_precio_porcentaje()`, write `$opc->get_porcentaje($codtarifa)` (and a `%`/`0.00"%"` number format) so the export is not silently `0`. |
| P6 | `View/ventas_opcionales.html.twig:368-377` | Price cell renders `show_precio_opcional(value.id)` behind a link to `tarif_opcional_precios`. | Show the percentage label in percentage mode (and repoint the link, see §2.5). |
| P7 | `View/ventas_opcionales.html.twig:50-142` (create modal) | No `tipo_precio` / `porcentaje` / `id_grupo` inputs. | Add the fixed/percentage toggle (Alpine `x-show`), a percentage input, and the group `<select>`, mirroring `View/ventas_opcional.html.twig:156-206`. |
| P8 | `View/Hooks/partials/opcional_precios_rows.html.twig:29-37` | Price input `value="{{ precio ? precio.precio : '' }}"`, currency addon; no percentage. | Render a percentage input/label when the opcional is percentage mode. The endpoint already loads each row (`tarif_opcional_tab.php:96-107`). |
| P9 | `controller/tarif_opcional_tab.php:138-157` (`guardar_precio_tab`) | Sets `$p->precio` and `$p->en_catalogo` only; never touches `porcentaje`. | Detect percentage mode and persist `$p->porcentaje` (and zero/ignore `precio`) so the tab save does not erase the percentage. |
| P10 | `View/tarif_opcional_edit.html.twig:283-309` | Scoped panel renders a single `precio` input + currency addon. | Add the fixed/percentage input branch; the controller already exposes the model (`$fsc.opcional`). |
| P11 | `controller/tarif_opcional_edit.php:329-352,363-477,483-556` | `load_precios_tarifas` reads `$precio->precio` only; `guardar_precio_tarifa`/`guardar_precios_tarifas` validate `precio_tarifa_*` with `parse_price_input` and `set_precio_tarifa`. | Percentage-aware read (`get_porcentaje`) and write; the bulk path additionally writes percentage zeros/stale cleanup. |
| P12 | `View/tarif_opcional_precios.html.twig:116-124,258-264` | Single `precio` input + overview using `show_precio(info.precio, ...)`. | Absorbed into edit (view dies). See §2.3. |
| P13 | `controller/tarif_opcional_precios.php:120-138,213-308` | `get_precio()`, `get_precios_todas_tarifas()`, `guardar_precio_tarifa()` price-only. | Absorbed into edit; view/controller die. |
| P14 | `View/ventas_opcional.html.twig:156-206` and `Controller/VentasOpcional.php:198-236` | **Working reference**: `stipo_precio`/`sprecio`/`sporcentaje` + `sid_grupo`; writes global `tipo_precio`/`porcentaje` and the default-lista row with `set_porcentaje_lista()` / `set_precio_lista()`. | Reuse this exact contract in the unified list and detail. |

### 2.2 Model contract for percentage

- `model/core/catalogo_opcional.php:14-15` — `TIPO_PRECIO_FIJO = 'fijo'`, `TIPO_PRECIO_PORCENTAJE = 'porcentaje'`.
- `:22-23` — public `$tipo_precio`, `$porcentaje`.
- `:85-88` — `es_precio_porcentaje(): bool` = global `tipo_precio === porcentaje`.
- `:90-104` — `get_porcentaje($codlista = null)`: **per-lista row wins** (`get_precio_lista($codlista)->porcentaje !== null`), otherwise falls back to the **global** `$this->porcentaje`.
- `:111-128` — `precio_para_articulo($articulo, $codlista)` uses `get_porcentaje()` × article PVP for percentage mode; otherwise `precio_en_lista()`.
- `:130-142` — `etiqueta_precio_base()` renders the global `porcentaje` as `12,5%`.
- `:144-156` — `etiqueta_precio_lista($codlista)` renders the effective per-lista percentage (`12,5%`) or the per-lista price.
- `:536-553` / `:555-572` — `set_precio_lista()` clears `porcentaje`; `set_porcentaje_lista()` sets `porcentaje` and zeros `precio`. **Both live on `catalogo_opcional` and instantiate `catalogo_opcional_precio`.**
- `model/tarif_opcional.php:396-400` — `get_precio_tarifa($codtarifa)` → `tarif_opcional_precio::get()` (row with both `precio` and `porcentaje`).
- `model/tarif_opcional.php:402-420` — `precio_en_tarifa($codtarifa, $articulo)` already is percentage-aware (`precio_para_articulo` / `get_porcentaje`).
- `model/tarif_opcional.php:422-437` — `set_precio_tarifa()` sets `$p->precio` **without clearing `$p->porcentaje`** (asymmetric with `set_precio_lista()`); **no `get_porcentaje_tarifa()` / `set_porcentaje_tarifa()` exists on the adapter**.
- `model/tarif_opcional.php:15` — `tarif_opcional extends catalogo_opcional`, so `es_precio_porcentaje()`, `get_porcentaje()`, `etiqueta_precio_lista()`, `get_grupo()`, `etiqueta_grupo()` are inherited.
- `model/core/catalogo_opcional_precio.php:18` — `$porcentaje` column; `:74-99` `test()` allows a nullable non-negative percentage; `:101-124` `save()` writes `precio`, `porcentaje`, `en_catalogo`.
- `model/tarif_opcional_precio.php:100-130` (`save()`) — read-modify-write: if the instance has no percentage and a row exists, it reuses the stored one (`:111-113`). History only tracks `precio` deltas (`:120-127`).
- `Services/CatalogLegacyTableMigration.php:194-225,390` — both `catalogo_opcionales.porcentaje` and `catalogo_opcional_precios.porcentaje` exist and are migrated; the price row keeps `porcentaje NULL` by default.

**Canonical answer:** the **mode is global** (`catalogo_opcionales.tipo_precio`) and the **effective value is per-lista/tarifa** (`catalogo_opcional_precios.porcentaje`) with the **global `catalogo_opcionales.porcentaje` as fallback**. `get_porcentaje($codlista)` is the single canonical reader. Read/write must go through it to stay consistent with `precio_para_articulo()`, `etiqueta_precio_lista()` and `precio_en_tarifa()`.

### 2.3 `tarif_opcional_edit` vs `tarif_opcional_precios` feature inventory

`tarif_opcional_edit` (`controller/tarif_opcional_edit.php`, `View/tarif_opcional_edit.html.twig`):

- Tabs: Datos (`:123-153`), Familias (`:155-237`), Artículos (`:239-267`), Precios por tarifa (`:269-352`).
- Tarifa selector with validated resolution (`controller:123-151`; view `:28-62`), htmx body-swap (`:36-47`).
- Scoped editable panel for `tarifa_seleccionada` with `toggle_button_group` + price + htmx POST (`view:276-309`), plus a read-only all-tarifas overview (`view:312-334`).
- Actions: `guardar_precio_tarifa` (per-tarifa, atomic, CSRF) and `guardar_precios_tarifas` (legacy bulk matrix, atomic, CSRF); `modificar`, `add/remove_familia`, `add/remove_articulo`, `save_etiquetas_familia`; article autocomplete; Alpine CSP confirm + search.
- Accessors: `get_precio_tarifa`, `opcional_activo_en_tarifa`, `opcional_en_catalogo_tarifa`, `opcional_en_tarifa_flag`, etiquetas helpers.
- Permission: `$allow_delete` via `from`/bulk guards; `fbase_controller` + `TarifarioOpcionalStateTrait`.

`tarif_opcional_precios` (`controller/tarif_opcional_precios.php`, `View/tarif_opcional_precios.html.twig`):

- Selector (GET + htmx, `view:58-77`) resolving the tarifa from `tarif_tarifa::get()`/default/first (not membership-validated like edit).
- Scoped save form `guardar_precio_tarifa` (atomic, CSRF) + `toggle_button_group` (`view:95-132`).
- `url_actual()`, `load_precio_opcional()`, `get_precio()`, `esta_activo_en_tarifa()`, `esta_en_catalogo()`, `esta_en_tarifa()`, `get_precios_todas_tarifas()`.
- "Información del opcional" panel, "Configuración en todas las tarifas" table, "Artículos que incluyen este opcional" table — all duplicating data edit already shows (Datos/Resumen, all-tarifas overview, Artículos tab).
- Permission: none beyond `fbase_controller` defaults.

**Overlap verdict:** edit **already duplicates** precios' selector, scoped price/state panel and all-tarifa overview (the prior selector change ported them). What precios uniquely has is the read-only "Información del opcional" side panel and its own article list — both already represented in edit. `tarif_opcional_precios` is therefore a near-duplicate; the absorption is mostly **delete + repoint + test update**, plus finishing percentage parity in edit's existing panel. **Remaining duplication after the prior change:** two selector implementations (`edit view:36-47` vs `precios view:63-75`) and two `guardar_precio_tarifa` implementations (`edit controller:483-556` vs `precios controller:213-282`).

### 2.4 Groups inventory

- `model/core/catalogo_opcional.php:25` — `$id_grupo`.
- `:65-73` — `get_grupo()` → `new catalogo_opcional_grupo()->get($id_grupo)` (**N+1 per row** when used in a list).
- `:75-83` — `etiqueta_grupo(): string` → group name or `'-'`.
- `:201-222` — `assign_to_grupo(int)`: sets `id_grupo`, saves, then deletes direct article relations (`catalogo_articulo_opcional::delete_all_from_opcional`).
- `:227-236` — `remove_from_grupo()`.
- `:241-258` — `all_sin_grupo()`.
- `:374-417` (`save()`) — when `id_grupo` is set, direct article relations are deleted (`:408-411`).
- `model/core/catalogo_opcional_grupo.php:261-276` — `all_activos()` (used by `VentasOpcional::loadGruposOpcional()`, `Controller/VentasOpcional.php:173-177`).
- Pages/controllers: `Controller/VentasOpcionalGrupos.php` (list + delete) and `Controller/VentasOpcionalGrupo.php` (edit, `add_opcional_grupo`/`remove_opcional_grupo` using `assign_to_grupo`/`remove_from_grupo`); legacy wrappers `controller/ventas_opcional_grupos.php`, `controller/ventas_opcional_grupo.php`; views `View/ventas_opcional_grupos.html.twig`, `View/ventas_opcional_grupo.html.twig`.
- **`tarif_opcional` inherits `get_grupo()`/`etiqueta_grupo()`** (`model/tarif_opcional.php:15`), and `tarif_opcional::search()` selects `o.*` (`:292`), so `id_grupo` is present on every list row → the unified list's `resultados` can render the group column with no model change.
- **No group filter exists**: `catalogo_opcional::search()` (`model/core/catalogo_opcional.php:451-497`) and `tarif_opcional::search()` (`model/tarif_opcional.php:245-316`) / `count_filtered()` (`:329-372`) have no `id_grupo` parameter or WHERE clause.

**Group-filter feasibility (locked-test analysis):** appending an optional 6th parameter `string $id_grupo = ''` (and a matching WHERE) to `tarif_opcional::search()` / `count_filtered()` is **feasible without breaking the locked tests**. The trait would call with 6 args; `CatalogoOpcionalesUnifiedControllerTest::test_pagination_uses_count_filtered_true_total_and_preserves_filters` (`tests/CatalogoOpcionalesUnifiedControllerTest.php:456-479`) records calls through stub methods with exactly 5 declared parameters — PHP silently ignores extra positional args on userland methods, so `searchCalls`/`countCalls` stay `[['abc',50,'F1','T1',false]]` / `[['abc','F1','T1',false]]` and the assertions hold. Existing `assertStringContainsString` URL assertions tolerate an extra `id_grupo` key. Changing the **position** of existing parameters, or adding a required parameter, would break the stubs — do not.

### 2.5 Link / repoint inventory (`page=tarif_opcional_precios`)

| File:line | Current target | New target |
|---|---|---|
| `View/ventas_opcionales.html.twig:371` | price-cell link to `tarif_opcional_precios&id=..&codtarifa=..` | `tarif_opcional_edit&id=..&codtarifa=..` (preserve `codtarifa`) |
| `View/ventas_opcionales.html.twig:431` | row action button to `tarif_opcional_precios&id=..&codtarifa=..` | `tarif_opcional_edit&id=..&codtarifa=..` |
| `View/tarif_opcional_edit.html.twig:79` | "Precios por tarifa" button → precios | becomes redundant: drop or point at the `#precios_tarifas` tab anchor of the same page |
| `View/tarif_opcional_precios.html.twig:59` | hidden `page` input (self) | view is deleted |
| `plugins/tarifario/View/tarif_articulo_precios.html.twig:184` | opcional price button → precios | `tarif_opcional_edit&id=..&codtarifa=..` |
| `View/tarif_opcional_precios.html.twig:15,329` | back links to `ventas_opcionales` | view is deleted (no change needed) |

Group links that already exist and stay: `View/ventas_opcionales.html.twig:149`, `View/ventas_opcional.html.twig:202,337,339`, `View/ventas_opcional_grupos.html.twig:47`, `View/ventas_opcional_grupo.html.twig:15`, `View/partials/articulos/tab_opcionales.html.twig:8`.

Non-page references to the **historical DB table** `tarif_opcional_precios` (`Services/CatalogLegacyTableMigration.php:355-484`, `tests/DeadOpcionalTableReferenceTest.php:34-168`, `tests/OpcionalPriceUnificationTest.php:111-166`, `plugins/tarifario/extras/tarifario_init.php:242`, `plugins/tarifario/model/table/tarif_opcional_precios.xml`) are **unrelated to the page retirement and must NOT change**.

### 2.6 Locked contracts / regression surface

| Test (plugin-relative) | What it locks | Action |
|---|---|---|
| `tests/VentasOpcionalesControllerTest.php` | modern file/`privateCore`/page-data/wrapper/`{{ csrf_field() }}`/no `\|raw` | **KEEP** |
| `tests/VentasOpcionalControllerTest.php` | single-opcional controller/wrapper/view contract (incl. `buscar_articulo`) | **KEEP** |
| `tests/TarifOpcionalEditTarifaSelectorTest.php` | edit selector resolution (`:112-174`), selector htmx (`:180-213`), scoped panel + locked save (count `name="guardar_precio_tarifa"` === 1, `:219-236`), overview (`:238-247`) | **KEEP** (assertions stay valid after absorption; if the scoped panel gains a percentage input it must keep the locked strings) |
| `tests/TarifOpcionalPreciosControllerTest.php` | adapter `extends catalogo_opcional_precio`, `get()` keyed on `codlista` (`:98-140`), no physical legacy table (`:142-156`), **moved precios controller persists via the adapter (`:158-177`)** | **UPDATE**: keep the adapter tests; **DELETE the controller-specific test method** (controller dies) |
| `tests/TarifOpcionalesControllerMasterStateTest.php` | list + **edit + precios** master wiring; `PRECIOS_CONTROLLER`/`PRECIOS_VIEW`, `buildPreciosController`, `test_precios_reads_master_over_price_row` (`:285-304`), `test_precios_reads_master_and_persists_it` (`:467-483`), `test_precios_single_tarifa_save_is_atomic` (`:508-523`), `test_controllers_load_the_master_model` (`:310-335`), `test_views_reuse_the_toggle_button_group_macro` (`:568-587`), `test_mutating_view_forms_carry_a_csrf_field` (`:589-609`) | **UPDATE**: drop `PRECIOS_*`/`buildPreciosController` and the three precios tests; re-target the absorbed master read/write coverage at `tarif_opcional_edit`; keep the edit/list assertions |
| `tests/TarifOpcionalesHtmxContractTest.php` | edit htmx/Alpine (`:116-234`), **precios selector (`:240-257`)**, list filters (`:259-299`), **precios half of locked names/CSRF/toggles (`:301-339`)** | **UPDATE**: drop the two precios tests; keep edit + list; if a percentage control lands in the edit tab add assertions there |
| `tests/TarifOpcionalesControllerContractTest.php` | `CONTROLLER_SLUGS` = edit/precios/configurador (`:42-46`); survivors `extends fbase_controller`, trait, zero tarifario refs | **UPDATE**: drop `tarif_opcional_precios` → **2 survivors** |
| `tests/TarifOpcionalTabEndpointTest.php` | `tarif_opcional_tab` rows/save, CSRF/permission, coddivisa per row (`:317-442`) | **KEEP**; **add** percentage-row scenarios when the tab becomes percentage-aware |
| `tests/CatalogoOpcionalesUnifiedControllerTest.php` | trait behavior + `search`/`count_filtered` call shape (`:453-479`), state/price caches (`:485-523`), toggles (`:529-567`), creation (`:573-635`), delete (`:641-657`), export (`:663-679`), url repoints (`:685-733`), **7-view repoint list incl. `tarif_opcional_precios.html.twig` (`:747-779`)** | **UPDATE**: drop the precios view from the repoint list; add percentage display + group column/filter/assignment scenarios; the `UnifiedListOpcionalStub` needs the percentage/group writer seams |
| `tests/CatalogoOpcionalesHtmxContractTest.php` | list htmx/Alpine/locked names (`:75-228`) | **UPDATE**: add group column/filter + percentage-modal contract assertions; keep existing |
| `tests/CatalogoOpcionalGrupoTest.php` | group model + `id_grupo` + `url_nuevo_en_grupo` (`:23-108`) | **KEEP** |
| `tests/Integration/CatalogoOpcionalesHookOwnershipTest.php` | 2 opcional hooks + `tarif_opcional_tab` endpoint + moved partials (`:49-306`) | **KEEP** (tab ownership unchanged) |
| `tests/Integration/CatalogoCoreHookMarkersTest.php` | four frozen host markers | **KEEP** |
| `tests/OpcionalDomainModelOwnershipTest.php`, `tests/DeadOpcionalTableReferenceTest.php`, `tests/OpcionalPriceUnificationTest.php`, `tests/Services/*`, `tests/InitUpgradeTest.php` | moved models / historical DB tables / upgrade retirement | **KEEP**; `InitUpgradeTest` **UPDATE** only if a `tarif_opcional_precios` page-retirement step is added |
| **NEW** `tests/CatalogoOpcionalesPercentageTest.php` | percentage display everywhere + `set_porcentaje_tarifa`/`set_precio_tarifa` symmetry + export percentage | **CREATE** |

### 2.7 Create/edit percentage + group flow to reuse

- `Controller/VentasOpcional.php:198-209` — `stipo_precio` normalizes to `TIPO_PRECIO_PORCENTAJE`/`TIPO_PRECIO_FIJO`; percentage mode sets global `porcentaje` (comma→dot) and zeros `precio`; fixed mode nulls `porcentaje`.
- `:213-214` — `sid_grupo` (`getInt`) → `id_grupo` or `null`.
- `:216-224` — save, then if grouped, delete direct article relations.
- `:226-236` — percentage → `set_porcentaje_lista($lista_precio_defecto, ...)`; fixed → `set_precio_lista(...)`.
- `View/ventas_opcional.html.twig:156-206` — radio `stipo_precio`, `#grupo_precio_fijo`/`#grupo_precio_porcentaje` blocks with `sprecio`/`sporcentaje`, and the `sid_grupo` `<select>` over `fsc.grupos_opcional` with a link to `ventas_opcional_grupos`.

The unified list create modal should adopt the same field names (`tipo_precio`, `porcentaje`, `id_grupo` — the trait names are free; `stipo_precio`/`sporcentaje`/`sid_grupo` belong to the `ventas_opcional` form) and the same write semantics.

### 2.8 htmx 4 / Alpine CSP state (already present)

- `View/ventas_opcionales.html.twig:1-9,133-142,163-215,220-495,499-616` — `htmx.boot({'allowScriptTags': false})`, body-swap filters, Alpine `opcionalNew`/`opcionalConfirm`/`opcionalExport`, nonce'd script, `alpine:init`, `[x-cloak]`, `htmx:after:swap`/`htmx:after:request` colon events.
- `View/tarif_opcional_edit.html.twig:1-9,269-352,375-504` — same posture with `opcionalConfirmAction`/`opcionalArticuloSearch`/`opcionalSaveFeedback`.
- Ban: v2 names (`htmx:afterSwap`, `htmx:afterRequest`) asserted absent by `TarifOpcionalesHtmxContractTest:187-195` and `CatalogoOpcionalesHtmxContractTest:185-188`; `hx-delete` banned; no `bootbox`, no `|raw`.
- Macros: `themes/AdminLTE/view/Macro/Htmx.html.twig` (`boot`, `allowScriptTags` scrubber, inherited CSRF header, `htmx.min.js`), `Macro/Alpine.html.twig` (`alpine-csp.min.js`), `View/Macro/TarifarioComponents.html.twig` (`toggle_button_group`, `tarifa_selector`).

---

## 3. Decisions

### 3.1 Percentage contract decision

**Canonical: global mode + per-lista value with global fallback, read/written through `catalogo_opcional::get_porcentaje()` / a new `tarif_opcional::set_porcentaje_tarifa()`.**

- **Read (display/export):** `es_precio_porcentaje()` decides the mode; `get_porcentaje($codtarifa)` / `etiqueta_precio_lista($codtarifa)` yield the value. Do not read `precio` in percentage mode.
- **Write:** add `tarif_opcional::set_porcentaje_tarifa($codtarifa, $pct)` (adapter-based, preserving the `tarif_opcional_precio` history/read-modify-write path) and make `tarif_opcional::set_precio_tarifa()` clear the row's `porcentaje` (symmetry with `catalogo_opcional::set_precio_lista()`), and `set_porcentaje_tarifa()` zero/ignore `precio`. This prevents a stale percentage from surviving a fixed-price save and vice versa. `catalogo_opcional::set_porcentaje_lista()` exists but targets `catalogo_opcional_precio` directly; prefer the adapter-based writer so the tarifario history keeps recording.
- **The create modal** sets global `tipo_precio`/`porcentaje` (like `VentasOpcional`) and writes one row per provided tarifa.
- **Export** emits `get_porcentaje($codtarifa)` with a percentage number format when in percentage mode.

Rejected: row-only authority (would require changing `get_porcentaje()`'s fallback and rewriting the `VentasOpcional` flow → high churn, breaks documented behavior); global-only (drops the already-migrated per-lista `porcentaje` column → data loss).

### 3.2 Groups-restoration decision

Restore full parity with the original list and `ventas_opcional`:

1. **Column**: add a "Grupo" `<th>` and a `<td>{{ value.etiqueta_grupo() }}</td>` in `View/ventas_opcionales.html.twig`'s table (thead `:319-341`, tbody `:343-450`). To avoid the `etiqueta_grupo()` N+1 (`catalogo_opcional.php:65-83`), load a group-name map once in the controller (via `catalogo_opcional_grupo::all_activos()`) and expose an accessor, or add a `LEFT JOIN catalogo_opcional_grupos` to `tarif_opcional::search()` and render the joined name. Prefer a controller-side map cache (smaller SQL blast radius).
2. **Management link**: already present at `View/ventas_opcionales.html.twig:149` — keep it (optionally re-word to match `optional-groups-manage`).
3. **Filter**: append optional `id_grupo` to `tarif_opcional::search()`/`count_filtered()` (position 5, after `$solo_activos`), surface `b_id_grupo` (or `id_grupo`) in `ini_filters()`/`b_url`/`getListQueryParams()` and add a filter control to the list view; include a "Sin grupo" option. Feasible without breaking locked tests (§2.4).
4. **Create-modal assignment**: add a `sid_grupo` select populated from `catalogo_opcional_grupo::all_activos()` and persist `id_grupo` in `new_opcional()` (reusing the `VentasOpcional` semantics, including the direct-relation cleanup that `catalogo_opcional::save()` already performs when `id_grupo` is set).

### 3.3 Detail-unification decision

**`tarif_opcional_edit` is canonical; `tarif_opcional_precios` is deleted with no alias.**

- Absorb any missing read-only detail into edit's existing tabs: the "Información del opcional" summary already maps to edit's Datos/Resumen; the per-tarifa all-tarifas table already maps to edit's "Resumen por tarifa" (`View/tarif_opcional_edit.html.twig:312-334`); articles already map to the Artículos tab.
- Delete `controller/tarif_opcional_precios.php` and `View/tarif_opcional_precios.html.twig`.
- Repoint the four links in §2.5, preserving `codtarifa`, to `tarif_opcional_edit&id=..&codtarifa=..`. The edit link at `View/tarif_opcional_edit.html.twig:79` becomes redundant → drop it (the `#precios_tarifas` tab is on the same page).
- Add an idempotent `retireTarifOpcionalPreciosPage()` in `Init::upgrade()` (mirror `retireTarifOpcionalesPage()`, `Init.php:271-283`) so a dead `fs_page` row cannot render.
- Update `TarifOpcionalesControllerContractTest::CONTROLLER_SLUGS` to 2 survivors and the master-state/htmx/precios tests (§2.6).
- Keep the existing htmx selector + scoped panel in edit (already delivered); the htmx WU is only about ensuring the absorbed functionality and the new percentage/group controls follow the same posture.

---

## 4. Options considered

| Area | Option | Pros | Cons | Verdict |
|---|---|---|---|---|
| Percentage source | **A: global mode + per-lista value + global fallback (via `get_porcentaje`)** | Matches existing model/helpers; no semantic change; per-tarifa percentages preserved | Needs a new writer + price-save symmetry | **CHOSEN** |
| Percentage source | B: per-lista row authoritative | Pure per-tarifa | Rewrites `get_porcentaje` fallback + `VentasOpcional`; breaks documented behavior | Rejected |
| Percentage source | C: global only | Simplest | Drops `catalogo_opcional_precios.porcentaje` (already migrated) | Rejected |
| Groups | **A: column + `id_grupo` filter + create-modal assignment, controller-side group map** | Full parity; no search-SQL blast radius; N+1 avoided | New filter wiring in trait/view | **CHOSEN** |
| Groups | B: column + assignment only | Smallest | No filter parity with `ventas_opcional` | Acceptable fallback |
| Groups | C: JOIN group name into `search()` | One query | Touches the locked-ish search SQL and `SELECT DISTINCT`/`ORDER BY` expression shape (`tarif_opcional.php:290-303`) | Risky |
| Detail | **A: delete precios + repoint + retire page** | Single canonical detail; removes duplicated selector/save | Test churn (contract/master/htmx/precios tests) | **CHOSEN** |
| Detail | B: precios redirect alias | Zero link churn | Explicitly rejected by the user | Rejected |
| Detail | C: keep precios read-only | Less deletion risk | Two pages forever; user wants elimination | Rejected |
| htmx/Alpine | **A: keep edit's existing posture, add percentage/group controls in `Alpine.data()`** | Consistent with the prior change; no v2 regressions | Must preserve locked strings/counts | **CHOSEN** |

---

## 5. Risks

| # | Risk | Likelihood | Mitigation |
|---|---|---|---|
| R1 | Percentage/price divergence: `set_precio_tarifa()` leaves a stale `porcentaje` (and a fixed→percentage switch leaves a stale `precio`) | High | Make the two writers symmetric; add regression tests for switch-in-both-directions; verify `get_porcentaje()` and `precio_en_tarifa()` agree with what the UI shows. |
| R2 | Locked-contract churn: `TarifOpcionalesControllerContractTest::CONTROLLER_SLUGS` (3→2), `TarifOpcionalesControllerMasterStateTest`/`TarifOpcionalesHtmxContractTest` precios portions, `TarifOpcionalPreciosControllerTest` controller test | High | Update the tests in the same WU as the deletion; keep all edit/list assertions byte-compatible; run the full plugin suite at every boundary. |
| R3 | `TarifOpcionalEditTarifaSelectorTest` locks exactly one `name="guardar_precio_tarifa"` (`:231-235`) and the scoped-panel strings | Med | Preserve the single scoped save form; add the percentage input inside it without duplicating the hidden action field. |
| R4 | New group filter or search-signature change breaks the stub-based call-shape assertions (`CatalogoOpcionalesUnifiedControllerTest:456-479`) | Med | Append `$id_grupo` as an optional trailing parameter only; never reorder/require existing params; run that test first. |
| R5 | `etiqueta_grupo()` N+1 on the list (one `catalogo_opcional_grupo::get()` per row) | Med | Load a single group map in the controller; cache per request; do not call `etiqueta_grupo()` per row from Twig. |
| R6 | `id_grupo` assignment silently deletes direct article relations (`catalogo_opcional.php:201-222,408-411`) | Med | Mirror the `VentasOpcional` flow; document the behavior; test that grouped creation removes direct relations intentionally. |
| R7 | CSRF mismatch: list mutations use `validateFormToken()` (`VentasOpcionalesListTrait:385-392`) while edit/tab use `requireCsrf()` (`tarif_opcional_edit:159-167`, `tarif_opcional_tab:116`) | Med | Keep each surface on its existing guard; new percentage/group mutations go through the host's guard; assert with tests. |
| R8 | Deleted precios page leaves an orphan `fs_page` row/menu item | Med | Add idempotent `retireTarifOpcionalPreciosPage()` in `Init::upgrade()` (mirror `Init.php:271-283`) and extend `InitUpgradeTest`. |
| R9 | Repoint misses a link (e.g. `plugins/tarifario/View/tarif_articulo_precios.html.twig:184`) and a dead page link survives | Med | Grep audit for `page=tarif_opcional_precios` excluding `openspec/` and the historical DB-table references; add a contract assertion. |
| R10 | htmx v2 names / `|raw` / `bootbox` creep into new markup | Low | Reuse the existing boot macros and `Alpine.data()`; keep the htmx contract bans green. |
| R11 | Alpine CSP no-eval: a new fixed/percentage toggle using inline expressions or `eval` fails under the CSP build | Med | Implement the toggle with `x-show`/`x-model` bound to an `Alpine.data()` component registered from a nonce'd classic script (`alpine:init` guard + `[x-cloak]`). |
| R12 | Escaping: group names, percentage labels, export strings | Low | Twig autoescape, `x-text` (never `innerHTML`), no `|raw`; assert in the htmx contract test. |
| R13 | Pre-existing uncommitted SDD dirs `absorber-opcionales-tarifa-en-catalogo-core/` and `opcionales-por-tarifa/` remain in `changes/` (both carry verify-reports) and could confuse `sdd-status`/archive tooling | Med | Keep this change in its own dir; do not touch them; flag for a separate archive pass. |
| R14 | Percentage in the injected tab: `guardar_precio_tab` only writes `precio` (`tarif_opcional_tab.php:150-151`) and would erase/zero a percentage row | Med | Branch on the opcional mode; persist `porcentaje`; extend `TarifOpcionalTabEndpointTest`. |

---

## 6. Recommended scope split (work units with rollback boundaries)

Each WU is strict-TDD (red → green) and must end with the full plugin suite green (`ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`, floor **511 tests / 1756 assertions OK**). No DB schema migration and no Composer dependency are involved.

### WU-1 — Restore percentage display and groups in the unified list
- **Server**: `extras/VentasOpcionalesListTrait.php` — carry the mode/percentage in `load_precios_cache` (`:283-299`), make `show_precio_opcional` (`:355-367`) percentage-aware (via inherited `es_precio_porcentaje()`/`etiqueta_precio_lista()`), add the group-name map/accessor, append the optional `id_grupo` filter to `catalogo_opcional::search()`/`tarif_opcional::search()`/`count_filtered()` and wire it in `ini_filters`/`b_url`/`getListQueryParams`.
- **View**: `View/ventas_opcionales.html.twig` — "Grupo" column, group filter control, percentage-aware price cell, percentage field + group select in the create modal, keep the existing groups link (`:149`).
- **Rollback boundary**: `extras/VentasOpcionalesListTrait.php`, `model/core/catalogo_opcional.php`/`model/tarif_opcional.php` (optional filter param), `View/ventas_opcionales.html.twig`, and their tests.
- **Tests**: extend `CatalogoOpcionalesUnifiedControllerTest`/`CatalogoOpcionalesHtmxContractTest`; keep `VentasOpcionalesControllerTest` green.

### WU-2 — Unify the single-opcional detail, delete `tarif_opcional_precios`, repoint links
- Absorb/verify parity in `tarif_opcional_edit`; delete `controller/tarif_opcional_precios.php` + `View/tarif_opcional_precios.html.twig`; repoint the four links in §2.5; add `retireTarifOpcionalPreciosPage()` to `Init::upgrade()`; update `TarifOpcionalesControllerContractTest` (2 survivors), `TarifOpcionalPreciosControllerTest`, `TarifOpcionalesControllerMasterStateTest`, `TarifOpcionalesHtmxContractTest`, `CatalogoOpcionalesUnifiedControllerTest`.
- **Rollback boundary**: deletion + link/Init edits + test updates; `git revert` restores the page and links (the `fs_page` retirement is idempotent; clear the Twig cache).

### WU-3 — htmx 4 + Alpine.js on the unified detail
- Ensure the absorbed scoped panel/overview and the new percentage control in `View/tarif_opcional_edit.html.twig` use the existing `htmx.boot({'allowScriptTags': false})` + `Alpine.data()` posture (colon events, `[x-cloak]`, nonce, `alpine:init`, `Alpine.initTree` re-init); remove any precios leftover markup.
- **Rollback boundary**: `View/tarif_opcional_edit.html.twig` (and its contract test) only.

### WU-4 — Percentage in the write paths, the injected tab and the Excel export
- **Model**: add `tarif_opcional::set_porcentaje_tarifa()` and make `set_precio_tarifa()` clear `porcentaje` (symmetry); keep the `tarif_opcional_precio` history/read-modify-write path.
- **Detail/create**: `controller/tarif_opcional_edit.php` + `View/tarif_opcional_edit.html.twig` (percentage read/write in the scoped panel) and `new_opcional` percentage/group persistence.
- **Tab**: `controller/tarif_opcional_tab.php` + `View/Hooks/partials/opcional_precios_rows.html.twig` percentage input/persistence.
- **Export**: `export_excel_opcionales()` percentage cell/format.
- **Rollback boundary**: model writer + edit controller/view + tab endpoint/partial + export branch.

### WU-5 — Tests and verify pass
- New `tests/CatalogoOpcionalesPercentageTest.php` (display, writer symmetry, export, tab); full plugin suite; grep audit for `page=tarif_opcional_precios`, `bootbox`, `|raw`, v2 htmx names, and bare no-argument currency calls (S28); produce `verify-report.md`.
- **Rollback boundary**: no production change.

**Dependency note**: WU-1's create modal and WU-4's writer are coupled. If WU-1 ships the create-modal percentage field, it must include the minimal model writer (`set_porcentaje_tarifa` + symmetry) or the field is display-only; either sequencing is acceptable as long as the trait never calls a non-existent writer.

---

## 7. Evidence summary (files inspected)

- `plugins/catalogo_core/openspec/config.yaml`, `openspec/specs/opcionales-management/spec.md`, `openspec/specs/opcionales-tarifa-selector/spec.md`
- `openspec/changes/archive/2026-09-12-unificar-opcionales-en-ventas-opcionales/{exploration,design}.md`
- `plugins/catalogo_core/extras/VentasOpcionalesListTrait.php`, `extras/TarifarioOpcionalStateTrait.php`, `Init.php`
- `plugins/catalogo_core/Controller/VentasOpcionales.php`, `VentasOpcional.php`, `VentasOpcionalGrupo.php`, `VentasOpcionalGrupos.php`
- `plugins/catalogo_core/controller/tarif_opcional_edit.php`, `tarif_opcional_precios.php`, `tarif_opcional_tab.php`
- `plugins/catalogo_core/View/ventas_opcionales.html.twig`, `ventas_opcional.html.twig`, `tarif_opcional_edit.html.twig`, `tarif_opcional_precios.html.twig`, `ventas_opcional_grupo.html.twig`, `ventas_opcional_grupos.html.twig`, `Macro/TarifarioComponents.html.twig`
- `plugins/catalogo_core/View/Hooks/{ventas_opcional_tabs_after,ventas_opcional_tab_pane_after}.html.twig`, `View/Hooks/partials/{opcional_precios_rows,opcional_tab_save_script}.html.twig`
- `plugins/catalogo_core/model/core/catalogo_opcional.php`, `catalogo_opcional_precio.php`, `catalogo_opcional_grupo.php`
- `plugins/catalogo_core/model/tarif_opcional.php`, `tarif_opcional_precio.php`
- `plugins/catalogo_core/Services/CatalogLegacyTableMigration.php`, `Services/CatalogoCurrencyFormatter.php`
- Tests: `TarifOpcionalEditTarifaSelectorTest`, `TarifOpcionalPreciosControllerTest`, `TarifOpcionalesControllerMasterStateTest`, `TarifOpcionalesHtmxContractTest`, `TarifOpcionalesControllerContractTest`, `TarifOpcionalTabEndpointTest`, `CatalogoOpcionalesUnifiedControllerTest`, `CatalogoOpcionalesHtmxContractTest`, `VentasOpcionalesControllerTest`, `VentasOpcionalControllerTest`, `CatalogoOpcionalGrupoTest`, `CatalogoOpcionalPrecioTest`, `Integration/CatalogoOpcionalesHookOwnershipTest`
- `git show HEAD:View/ventas_opcionales.html.twig` (group column HEAD:91, groups link HEAD:48)
- `plugins/tarifario/View/tarif_articulo_precios.html.twig`, `plugins/tarifario/extras/tarifario_init.php`

---

## 8. Open questions (resolve in spec/design)

1. **Group column source**: controller-side group map (`all_activos()` once) vs a `LEFT JOIN` in `tarif_opcional::search()`. Recommended: controller-side map (no search-SQL risk).
2. **Group filter key**: `b_id_grupo` (mirrors `b_codfamilia`) vs `id_grupo`. Recommended: `b_id_grupo` for consistency with the list's `b_*` filter namespace, with a "Sin grupo" sentinel.
3. **Create-modal percentage semantics**: one global percentage + per-tarifa rows (mirroring `VentasOpcional`) vs a per-tarifa percentage input in the create wizard. Recommended: global percentage on create, per-tarifa editing afterwards in the detail/tab.
4. **Export percentage format**: numeric `porcentaje` with a `0.00"%"` number format vs a string `12,5%`. Recommended: numeric with `%` format so the column stays sortable.
5. **Retire `fs_page` for the deleted page**: yes/no (default yes, idempotent, mirrors `retireTarifOpcionalesPage()`).
