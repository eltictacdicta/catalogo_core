# Exploration: absorb the tarifario article package into `catalogo_core`

- **Change**: `absorber-articulos-tarifa-en-catalogo-core`
- **Owner (SDD)**: `plugins/catalogo_core/openspec/` (plugin-local, `ownership: plugin-local`)
- **Secondary plugin**: `tarifario` (absorption/retirement only)
- **Artifact store**: `openspec`
- **Exploration date**: 2026-09-13
- **Baseline suite**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **528 tests, 1826 assertions, OK** (25 warnings, 1 skipped). This is the regression floor.
- **Change dir**: `plugins/catalogo_core/openspec/changes/absorber-articulos-tarifa-en-catalogo-core/`
- **Hard rule enforced**: all SDD artifacts live only under `plugins/catalogo_core/openspec/`; nothing is created in the core `openspec/`.

---

## 1. Intent

Make `catalogo_core` the **single owner of the article experience** and absorb every article capability that today
lives in `tarifario`: per-tarifa price/state, product catalogs, article families/tags/groups, Excel import/export,
images, revision notes, multiidioma, and the injected article "Tarifas" tab. The canonical article pages stay
`ventas_articulos` (list, `Controller/VentasArticulos.php`) and `ventas_articulo` (detail,
`Controller/VentasArticulo.php` + `View/ventas_articulo.html.twig`), migrated to **htmx 4 + Alpine.js (CSP build)**.
The duplicated `tarif_*` article pages/controllers/views/services are retired once absorbed, their `fs_page` rows
cleaned idempotently, and every inbound link repointed. No feature may be lost.

These user decisions are confirmed and are not re-litigated here:

1. Absorb into `catalogo_core` everything `tarifario` contributes for artículos (per-tarifa prices/state, catalog,
   families/tags/groups, Excel import/export, images, revision notes, multiidioma, injected article `Tarifas` tab).
2. Keep one canonical article experience: list `ventas_articulos` + detail `ventas_articulo`.
3. Migrate the article UI to **htmx 4 + Alpine.js**.
4. Preserve ALL existing functionality; retire the duplicated `tarif_*` article pages/controllers/views/services.
5. Always take the most recommended option; do not ask.

---

## 2. Current-state findings (file:line evidence)

### 2.1 `catalogo_core` article capability — what already exists (target shell)

| Artifact | Evidence | Capability today |
|---|---|---|
| `Controller/VentasArticulos.php` | PSR-4, `extends PageController` (`:38`), page `name=ventas_articulos`/`menu=catalogo`/`showonmenu` (`:64-73`) | List + filters `search`/`codfamilia`/`codfabricante`/`con_stock`/`bloqueados` (`:88-107`), quick-create `nuevoArticulo` with CSRF + neutral permission event (`:394-449`, dispatch `:420-430`), Excel import/export wizard actions (`:138-183`), pagination seams (`:477-528`) |
| `Controller/VentasArticulo.php` | PSR-4, `extends PageController` (`:48`), page `name=ventas_articulo`/`showonmenu=false` (`:70-79`) | Detail load `ref`/`id` (`:588-601`), save basic fields (`:189-239`), multiidioma save (`:241-276`), opcionales/grupos AJAX partial (`:345-527`, `:582-637`), delete (`:639-666`) |
| `View/ventas_articulos.html.twig` | 203 L | Legacy jQuery/bootstrap list; Excel dropdown + modals (`:37-54`, `:190-201`); **no htmx/Alpine**; no hook markers |
| `View/ventas_articulo.html.twig` | 744 L | Legacy jQuery tabs (`#datos/#precios/#stock/#multiidioma/#opcionales`) + bootbox + FSAjaxLoader; hook markers at `:129` (`ventas_articulo_tabs_after`) and `:341` (`ventas_articulo_tab_pane_after`); no htmx/Alpine |
| `View/partials/articulos/*` | `modal_exportar_excel` 34 L, `modal_importar_excel_wizard` 114 L, `modal_nuevo_articulo` 81 L, `tab_multiidioma` 46 L, `tab_opcionales` 196 L | Modal/partial surface |
| `Services/ArticuloExcelExportService.php` | 131 L | Basic article export (3-col-ish) |
| `Services/ArticuloExcelImportWizardService.php` | 311 L | Basic 3-col wizard (fixture `basic-3col.xlsx`) |
| `Services/ArticuloExcelRowUpdater.php` | 201 L | Row persistence for the basic wizard |
| `Services/ArticuloSearchQueryBuilder.php` | 147 L | Search SQL helper (not in the tarifario list) |
| `process_excel_wizard.php` (21 L) + `process_excel_wizard_dispatch.php` (444 L) | shared SSE entry + dispatch (`catalogo_excel_wizard_run`) |
| `View/js/articulos-excel-import-wizard.js` | 683 L | JS wizard for the basic stack |
| `model/core/articulo.php` | `get_descripciones()` `:1375`, `get_descripcion_idioma()` `:1392`, `set_descripcion_idioma()` `:1432`, `get_opcionales()` `:1466` | Core articulo **already has** multiidioma + opcionales |
| `model/tarif_tarifa.php` | `:207` `DELETE FROM tarif_articulo_precios WHERE codtarifa=...` | catalogo_core **already writes** the tarifario article-price table by name |
| `controller/tarif_configurador_opcionales.php` | `:63` `TARIFA_ARTICULO_PRECIO_TABLE = 'tarif_articulo_precios'` | Same hard coupling |

**Gap vs tarifario**: the canonical pages have **no per-tarifa anything** (no Tarifas tab of their own — it is injected),
**no catalog manager**, **no article images**, **no revision notes**, **no per-tarifa article list columns**, and use
**legacy jQuery**, not htmx/Alpine.

### 2.2 `tarifario` article capability — what must move (inventory)

**Controllers / pages**

| Artifact | LOC | Slug / base | Capability |
|---|---|---|---|
| `controller/tarif_articulos.php` | 2504 | `tarif_articulos` / `tarif_controller` (`:39`, ctor `:75-78`, menu `tarifario`) | List with per-tarifa price/state caches (`:205-279`), filters `query`/`b_codfamilia`/`b_codtarifa`/`b_solo_activos` (`:138-196`), quick create with per-tarifa prices (`:313-382`), delete (`:384-398`), `fbase_paginas` pagination (`:400-403`), `puede_editar` (`:412-415`), Excel template/export/import (`:420-1100`), JSON/SAP import (`:1116-1928`), cleanup actions (`:1929-2500`) |
| `controller/tarif_catalogo_view.php` | 5189 | `tarif_catalogo_view` / `tarif_controller` (`:97`, ctor `:296-299`) | Per-tarifa catalog manager: roles/permissions (`:443-565`), HTMX fragments (`:730-1101`), JSON/AJAX (`:1146-1255`), catalog tree (`:1256-1685`), action dispatch incl. Excel/image/JSON/notes (`:591-725`), import/export/notas/images methods |
| `controller/tarif_articulo_edit.php` | 403 | `tarif_articulo_edit` (`:38`) | Article edit: reference change, familia, flags, multiidioma, `tarif_tarifa_articulo` sync, images (`:243-331`), etiquetas (`:340-402`) |
| `controller/tarif_articulo_precios.php` | 320 | `tarif_articulo_precios` (`:37`) | Per-article per-tarifa price/state edit (`:132-239`), article-opcional add/remove (`:244-319`) |
| `controller/tarif_tab_precios.php` | 175 | AJAX endpoint / `tarif_controller` (`:46`) | Article Tarifas tab rows + save (`:78-165`); opcional surface already moved out (OUM-10) |

**Views / hooks**

| Artifact | LOC | Note |
|---|---|---|
| `View/tarif_articulos.html.twig` | 576 | List UI (filters, per-tarifa columns, modals, JSON import) |
| `View/tarif_catalogo_view.html.twig` | 281 | Catalog UI; imports `Macro/Htmx.html.twig` + `htmx.boot()` (`:7,18`), `hx-get` lazy family rows (`:106-110`), loads `partials/catalogo/styles` + `View/js/catalogo/index.js` (`:15,65`) |
| `View/tarif_catalogo_articulos.html.twig` | 214 | Fragment: `hx-swap="beforeend"`, `hx-target="#tbody-{familia}"` |
| `View/tarif_catalogo_articulos_agrupados.html.twig` | 239 | Grouped fragment |
| `View/tarif_catalogo_search.html.twig` | 73 | Search fragment |
| `View/tarif_articulo_edit.html.twig` | 239 | Detail edit |
| `View/tarif_articulo.html.twig` | 224 | Detail view |
| `View/tarif_articulo_precios.html.twig` | 277 | Per-tarifa prices view |
| `View/Hooks/ventas_articulo_tabs_after.html.twig` | 11 | Tab header, `data-ajax-url` → `page=tarif_tab_precios&action=rows&tipo=articulo&ref=` |
| `View/Hooks/ventas_articulo_tab_pane_after.html.twig` | 11 | Pane `#tab_tarifario_precios` + shared save script |
| `View/Hooks/partials/articulo_precios_rows.html.twig` | 57 | Server rows; `fsc.simbolo_divisa(tarifa.coddivisa)` (`:32`) |
| `View/Hooks/partials/tab_save_script.html.twig` | 55 | jQuery save wiring via `FSAjaxLoader.securePost` |
| `View/partials/catalogo/*` | 2072 (12 files) | Modals (JSON/Excel/images/notas) + `styles` + `toolbar` |
| `View/js/catalogo/*` | 4117 (9 files) | `catalogo-main` 578, `excel-export` 233, `excel-import-wizard` 879, `images-import` 578, `images-zip` 258, `index` 63, `json-import` 461, `revision-notas` 964, `utils` 103 |

**Models / tables** (all `FSFramework\model\*`, class names stay on move)

| Model | Table | LOC |
|---|---|---|
| `tarif_articulo.php` (`extends \FSFramework\model\articulo`, `:28`) | — | 750 |
| `tarif_articulo_precio.php` | `tarif_articulo_precios` | 424 |
| `tarif_articulo_imagen.php` | `tarif_articulo_imagenes` | 601 |
| `tarif_articulos_ext.php` | `tarif_articulos` | 250 |
| `tarif_catalogo.php` | `tarif_catalogos` | 394 |
| `tarif_catalogo_articulo.php` | `tarif_catalogo_articulo` | 437 |
| `tarif_catalogo_def.php` | `tarif_catalogo_defs` | 490 |
| `tarif_catalogo_def_articulo.php` | `tarif_catalogo_def_articulo` | 437 |
| `tarif_catalogo_def_familia.php` | `tarif_catalogo_def_familia` | 489 |
| `tarif_grupo_articulo.php` | `tarif_grupo_articulos` | 527 |
| `tarif_revision_nota.php` | `tarif_revision_notas` | 769 |
| `tarif_tarifa_articulo.php` | `tarif_tarifa_articulo` | 586 |
| `tarif_tarifa_articulo_etiqueta.php` | `tarif_tarifa_articulo_etiqueta` | 163 |
| `tarif_descripcion.php` (`extends articulo_descripcion`, `:12`) | — | 14 (deprecated wrapper) |
| `tarif_idioma.php` (`extends catalogo_idioma`, `:12`) | — | 18 (compat alias) |
| `tarif_precio_historial.php` | `tarif_precio_historial` | 412 |

**Services / wizard**

| Artifact | LOC | Note |
|---|---|---|
| `Services/ExcelHierarchyService.php` | 574 | Family hierarchy from Excel; reads `tarif_familia`/`tarif_tarifa_familia` (already catalogo_core) |
| `Services/ExcelImportWizardService.php` | 541 | Rich 14/16-col wizard (fixtures `basic-14col`, `basic-16col`, `mixed-modes`, `multi-sheet`, `large-10k`) |
| `Services/ExcelRowUpdater.php` | 572 | Row persistence for the rich wizard |
| `Services/ArticlePermissionListener.php` | 152 | Guest listener on catalogo_core `ArticlePermissionFilterEvent`; depends on `tarif_grupo_usuario`, `tarif_grupo_articulo`, `tarif_tarifa`, `fs_user` |
| `process_excel_wizard.php` | 636 | SSE entry; writes `tarif_articulo`/`tarif_articulos_ext`/`tarif_articulo_precio`, `SELECT ... FROM tarif_articulos` (`:342,:410`), `page=tarif_catalogo_view` redirect (`:477`) |

**Current hook registration split (confirmed)**

- `plugins/tarifario/Init.php:65-68` `HOOK_TEMPLATES` registers **only** `ventas_articulo_tabs_after` / `ventas_articulo_tab_pane_after`; comment `:62-63` states the opcional pair moved to catalogo_core.
- `plugins/catalogo_core/Init.php:26-29` `OPCIONAL_HOOK_TEMPLATES` registers **only** `ventas_opcional_tabs_after` / `ventas_opcional_tab_pane_after`.
- `tarifario/Init.php:101-116` registers `ArticlePermissionListener` at boot (before Twig build), class_exists-guarded.
- `catalogo_core/Init.php:125-161` registers its view extensions + hooks with static idempotency guards.

So after this change **tarifario should register zero hooks and zero article UI**; catalogo_core registers all four hooks
(or a combined map). The permission listener is a separate question (§3.4).

### 2.3 Acceptance / serving chain (why retirement needs `fs_page` cleanup)

- Plugin pages are discovered by scanning active plugins' `controller/` and `Controller/` dirs; the slug is resolved
  from `page=...` and the `getPageData()`/legacy ctor `menu` string becomes `fs_page.folder`
  (`model/fs_page.php` columns `name,title,folder,version,show_on_menu,important,orden`; `save()` `:160-185`,
  `delete()` `:186-190`).
- `fs_user::get_menu()` does **not** filter dead pages, so a deleted controller leaves a broken menu row.
  Precedent: `catalogo_core/Init.php::retireVentasFamiliasPage()` `:254-266`, `retireTarifOpcionalesPage()`
  `:276-288`, `retireTarifOpcionalPreciosPage()` `:298-310` — all idempotent `fs_page::get()` + `delete()`.
- Pages to retire in this change: `tarif_articulos`, `tarif_catalogo_view`, `tarif_articulo_edit`,
  `tarif_articulo_precios` (and any `tarif_articulo` page if present). `tarif_tab_precios` is an endpoint, not a menu
  page (registered `FALSE,FALSE`), so it only needs the class/file move.
- Menu folder label `tarifario` for the remaining pages (`tarif_tarifas`, `tarif_actualizar_precios`,
  `tarif_historial_precios`, `tarif_roles`) can stay (cosmetic; mirrors the familias precedent Q5).
- **Twig resolution is global across active plugin `View` dirs** and the `@catalogo_core` namespace is already
  self-registered (`catalogo_core/Init.php:134-139`), so moved templates resolve without loader changes.

### 2.4 htmx 4 + Alpine patterns already in use (reuse, do not reinvent)

- `themes/AdminLTE/view/Macro/Htmx.html.twig:45-87` `htmx.boot(config)`: `allowScriptTags` scrubber via
  `htmx:before:swap`, nonce'd inherited `hx-headers` `X-CSRF-TOKEN` (`:80-85`), nonce'd deferred
  `view/js/htmx.min.js` (`:86`).
- `themes/AdminLTE/view/Macro/Alpine.html.twig:31-33` nonce'd deferred `alpine-csp.min.js`.
- `themes/AdminLTE/view/Macro/HtmxCrud.html.twig` — optional CRUD stack (`boot/table/toolbar/flashContainer/saveBar`),
  loads `Sortable.min.js`, `fs-dialogs.js`, `htmx-crud.js` only through `boot()`.
- tarifario pilot already live: `View/tarif_catalogo_view.html.twig:7,18` (`htmx.boot()`), `:106-110` (`hx-get`,
  conditional `hx-trigger`, `hx-target`, `hx-innerHTML`), `View/js/catalogo/catalogo-main.js` uses `htmx.ajax(` and
  colon events only.
- catalogo_core owned precedent for the exact tab move: `controller/tarif_opcional_tab.php` (extends
  `fbase_controller`, uses `requireCsrf()`, `simbolo_divisa()`, soft RBAC via `class_exists`) +
  `View/Hooks/ventas_opcional_tab_pane_after.html.twig` (`htmx.boot({'allowScriptTags':false})`, Alpine `x-data`,
  `x-cloak`, `htmx.boot`/`alpine.boot`) + `View/Hooks/ventas_opcional_tabs_after.html.twig` (`hx-get`/`hx-target`/
  `hx-swap`).
- Colon-only events are test-locked: `TarifCatalogoHtmxContractTest:330-336`
  (`htmx:after:swap`, `htmx:after:request`; v2 names banned).
- `csrf_field()` emits `_csrf_token`, which `validateFormToken()` reads (`src/Security/CsrfManager.php`), and legacy
  `requireCsrf()` works on `fbase_controller`; nonce via `{{ csp_nonce_attr() }}`.

### 2.5 Locked contracts / regression surface

`plugins/catalogo_core/tests/` (must stay green; **528/1826** basline):

| Test | Locks | Action |
|---|---|---|
| `VentasArticulosControllerTest.php` | modern controller + wrapper `ventas_articulos` (`:33-97`), view exists/no `\|raw`/header+footer (`:99-157`), ctor accepts `search`/`codfamilia`/`codfabricante` (`:159-196`), SQL safety (`:198-210`) | **KEEP** (view rewrite must preserve these strings) |
| `VentasArticuloControllerTest.php` | `VentasArticulo` + wrapper (`:33-120`), view exists/no `\|raw`/`csrf_field()`/header+footer (`:122-171`), displays ref/desc/pvp (`:173-210`), `idiomas`/`articulo_opcionales`/`saveMultiidiomaDescriptions`/`addOpcionalArticulo` (`:226-236`), `#multiidioma`/`#opcionales` + partial includes (`:238-248`) | **KEEP** |
| `Integration/CatalogoCoreHookMarkersTest.php` | frozen 4 marker names (`:55-60`), frozen positions (`:94-132`), whitespace+context regex (`:155-173`), empty-registry zero bytes + registered fragment unescaped + throw swallow (`:206-271`) | **UPDATE (additive)** only if new markers enter `ventas_articulos`; the 4 frozen names must not change. Note it already anticipates `ventas_articulos_*` (`:193-198`) |
| `Services/ArticuloExcel*Test.php`, `ArticuloMultiidiomaTest.php` | catalogo_core basic Excel stack + multiidioma | **KEEP / extend** when the rich wizard moves in |
| `TarifConfiguradorOpcionalesTest.php` / `TarifOpcionales*` | reference `tarif_articulo_precios` table name and `TarifarioComponents.html.twig:271` link | **UPDATE** link/repoint if `tarif_catalogo_view` retires |

`plugins/tarifario/tests/` (article subset ~14 files):

| Test | Locks | Action |
|---|---|---|
| `Integration/HookRegistrationTest.php` | `FROZEN_HOOKS` article pair (`:60-63`), registration at Twig build (`:132-146`), templates on disk (`:148-156`), tab shell render `page=tarif_tab_precios` (`:160-172`), opcional hooks absent (`:140-145`), listener live (`:176-187`), idempotency (`:189-204`), no bare `simbolo_divisa` in hooks (`:208-224`), host view injects article tab (`:233-250`) | **MOVE/UPDATE**: article hooks become catalogo_core-owned; tarifario asserts zero article hooks. Article half of the test migrates next to the moved controller |
| `Controller/TarifTabPreciosTest.php` (563) | deny/allow saving via `tarif_articulo_precio::get/save` (`:352-400`), CSRF-before-model (`:406-422`), per-row `coddivisa` (`:428-445`), F2 re-render (`:455-509`), no bare `simbolo_divisa` (`:515-533`), AD-5 structural (`:539-562`) | **MOVE** to `catalogo_core/tests/` and repoint paths/base to the catalogo_core endpoint |
| `Controller/TarifCatalogoHtmxContractTest.php` (369) | pilot htmx contracts on `tarif_catalogo_view`/fragments/toolbar/`catalogo-main.js` (`:143-367`) | **MOVE/UPDATE** with the catalog WU; colon-event/v2 bans preserved |
| `Controller/TarifCatalogoOpcionalMasterExportTest.php` (334) | `build_opcionales_export` master-state in `tarif_catalogo_view` (`:205-332`) | **MOVE** with the catalog WU (or split opcional-export assertions) |
| `Controller/TarifArticulosFamiliaImportTest.php` (563) | `tarif_articulos` import writers 1/2 family handling (`:114-469`) | **MOVE/UPDATE** with the list/import WU; source-string assertions follow the moved controller |
| `Controller/TarifTarifas*Test.php` | tarifas CRUD (stays tarifario) | **KEEP** |
| `Integration/VentasArticulosQuickCreateGateCompositionTest.php` (449) | drives catalogo_core `VentasArticulos::nuevoArticulo` through the real listener (`:107-449`) | **KEEP** (listener boundary, §3.4) but update require paths if models move |
| `Integration/ArticlePermissionListenerImportContextTest.php` (205), `PermissionListenerInitRegistrationTest.php` (207), `Services/ArticlePermissionListenerTest.php` (240) | listener + init registration | **KEEP** if listener stays; **MOVE** if listener moves (§3.4) |
| `Services/ExcelImportWizardServiceTest.php` (718), `Services/ExcelHierarchyService*Test.php` (848), `Model/TarifArticuloFactoryForImportTest.php` (264) | rich wizard services + factory | **MOVE** with the Excel WU; requires repointed to catalogo_core |
| `Integration/ExcelWizardSseEntryTest.php` (250), `Integration/LegacyImportRegressionTest.php` (188) | SSE entry `plugins/tarifario/process_excel_wizard.php` (`SseEntryTest:85,124`) and `page=tarif_catalogo_view&action=import_excel_chunk` (`Legacy:88`) | **MOVE/UPDATE** with the Excel WU |
| `Integration/FamiliaOverrideRemovalTest.php`, `TarifFamiliaWriteRetirementTest.php` | familias (already catalogo_core) | **KEEP** |
| `Model/TarifIdiomaTest.php` | `tarif_idioma` alias | **KEEP** (alias stays) |
| `Integration/HookRegistrationTest.php` + `tests/Integration/...` fixtures | — | — |

**Fixture ownership**: tarifario `tests/fixtures/excel-wizard/*` (`basic-14col`, `basic-16col`, `empty`, `large-10k`,
`mixed-modes`, `multi-sheet`) must move with the rich wizard; catalogo_core already owns its own
`tests/fixtures/excel-wizard/` (`basic-3col.xlsx`, `empty.xlsx`) — the two directories must be merged without name
collisions (`empty.xlsx` exists in both).

---

## 3. Duplicate vs unique matrix + ownership decision

### 3.1 Per-feature classification

| tarifario article feature | Class | Evidence / target |
|---|---|---|
| Per-tarifa price/state (`tarif_articulo_precio`) | **MISSING-IN-CATALOGO** | table already written by catalogo_core (`tarif_tarifa.php:207`, `tarif_configurador_opcionales.php:63`); move model+XML |
| Article Tarifas tab (hooks + `tarif_tab_precios` + partials) | **MISSING-IN-CATALOGO** (article half) | opcional half already moved (OUM-10); article half is the last piece in tarifario |
| Article edit (`tarif_articulo_edit`) | **DUPLICATE** of `ventas_articulo` | richer features (images, etiquetas, `tarif_tarifa_articulo` sync) must fold into canonical detail |
| Article list (`tarif_articulos`) | **DUPLICATE** of `ventas_articulos` | add per-tarifa columns/filters + import/JSON actions |
| Catalog manager (`tarif_catalogo_view` + `tarif_catalogo*` + partials/JS) | **MISSING-IN-CATALOGO** | no equivalent; moves as a catalogo_core-owned catalog surface |
| Article images (`tarif_articulo_imagen`) | **MISSING-IN-CATALOGO** | core `articulo::imagen_url()` exists (`model/core/articulo.php:605`) but no multi-image table |
| Revision notes (`tarif_revision_nota`) | **MISSING-IN-CATALOGO** | notes cover articles/families/opcionales; family/opcional parts already near catalogo_core |
| Multiidioma | **ALREADY-IN-CATALOGO** | `articulo::get/set_descripcion_idioma` (`:1392,:1432`) + `tab_multiidioma` + `catalogo_idioma`; `tarif_descripcion` is a deprecated wrapper → DELETE wrapper, keep core |
| `tarif_idioma` alias (`extends catalogo_idioma`) | **ALREADY-IN-CATALOGO** | thin compat alias used by `tarifario_init.php:46` + `TarifIdiomaTest`; keep as alias |
| Article tags (`tarif_tarifa_articulo_etiqueta`) | **MISSING-IN-CATALOGO** | move |
| Article/per-tarifa config (`tarif_tarifa_articulo`) | **MISSING-IN-CATALOGO** | per-tarifa familia/flags/order; move |
| Article ext fields (`tarif_articulos_ext`) | **MISSING-IN-CATALOGO** | `ref_catalogo`, `configurador`; move |
| Article price history (`tarif_precio_historial`) + `tarif_historial_precios` article branch | **MISSING-IN-CATALOGO** | opcional history model already catalogo_core (`tarif_opcional_precio_historial`); article branch must move (controller has a catalogo_core test already) |
| Excel rich wizard (`ExcelHierarchyService`, `ExcelImportWizardService`, `ExcelRowUpdater`, `process_excel_wizard.php`) | **MISSING-IN-CATALOGO** (superset) | catalogo_core has a basic 3-col stack; the rich 14/16-col stack must move and either unify or coexist |
| Article permission listener + `tarif_grupo_articulo` | **TARIFARIO-ONLY-BOUNDARY** | see §3.4 |
| RBAC definitions (`tarif_grupo_rol/usuario/tarifa`, `tarif_tarifa_rol`, `tarif_roles`) | **TARIFARIO-ONLY-BOUNDARY** | roles admin domain, not article UI |
| `tarif_tarifa` model + `tarif_familia*` | **ALREADY-IN-CATALOGO** (prior changes) | do not touch |
| Opcional models/services | **ALREADY-IN-CATALOGO** (prior changes) | do not touch |

### 3.2 Ownership decision per model / table / service

| Artifact (class / table) | Destination | Rationale |
|---|---|---|
| `tarif_articulo` (no own table) | **MOVE → catalogo_core** | article domain extension (`set_precio_tarifa`, `search_tarifario`, `get_imagenes`, `url_tarifario`); merge with/next to core `articulo` |
| `tarif_articulo_precio` / `tarif_articulo_precios` | **MOVE** | per-tarifa price/state; already referenced by catalogo_core |
| `tarif_articulo_imagen` / `tarif_articulo_imagenes` | **MOVE** | article images |
| `tarif_articulos_ext` / `tarif_articulos` | **MOVE** | article ext fields; 1:1 legacy ext table |
| `tarif_tarifa_articulo` / `tarif_tarifa_articulo` | **MOVE** | per-tarifa article config |
| `tarif_tarifa_articulo_etiqueta` / `tarif_tarifa_articulo_etiqueta` | **MOVE** | article tags per tarifa |
| `tarif_catalogo*` (5 models + tables) | **MOVE** | product catalog domain |
| `tarif_revision_nota` / `tarif_revision_notas` | **MOVE** | revision notes |
| `tarif_precio_historial` / `tarif_precio_historial` | **MOVE** | article price history |
| `tarif_descripcion` | **DELETE** | deprecated wrapper; core `articulo_descripcion` is canonical |
| `tarif_idioma` | **KEEP in tarifario** (alias) | compat alias, not article UI; avoid churn |
| `tarif_grupo_articulo` / `tarif_grupo_articulos` | **KEEP in tarifario** (boundary, §3.4) | article-assignment for editor RBAC; coupled to `tarif_grupo_usuario`/`tarif_grupo_rol` |
| `ExcelHierarchyService` | **MOVE** | reads already-moved `tarif_familia`/`tarif_tarifa_familia`; Excel domain |
| `ExcelImportWizardService` / `ExcelRowUpdater` | **MOVE** | rich article/catalog wizard |
| `process_excel_wizard.php` | **MOVE** (route + SSE) | keep the `page=tarif_catalogo_view`/standalone route repointed to the catalogo_core catalog page |
| `ArticlePermissionListener` | **KEEP in tarifario** (boundary, §3.4) | guest RBAC listener; catalogo_core stays neutral (default-allow) |
| `tarif_tab_precios` endpoint | **MOVE** → catalogo_core (mirror `tarif_opcional_tab`) | article Tarifas tab |

### 3.3 The `catalogo_core` → `tarifario` hard couplings to break

1. `catalogo_core/model/tarif_tarifa.php:207` deletes from `tarif_articulo_precios` — after the move it points at the same
   stable table name, but the model class must resolve locally. No SQL change needed; the class require changes.
2. `catalogo_core/controller/tarif_configurador_opcionales.php:63` uses the stable table name — no change.
3. `catalogo_core/View/Macro/TarifarioComponents.html.twig:271` links to `page=tarif_catalogo_view` — must be repointed
   to the new catalogo_core catalog page (or removed if it merges into the article list).
4. After the move, no catalogo_core file may reference `plugins/tarifario/` paths (grep gate).

### 3.4 Boundary decision — article permissions / RBAC (explicit, not asked)

**Decision (recommended): keep the RBAC subsystem and `ArticlePermissionListener` in `tarifario`.**

- The listener is a **guest consumer** of catalogo_core's neutral `ArticlePermissionFilterEvent`; the existing spec
  `tarifario/openspec/specs/tarifario/catalogo-integration/spec.md` explicitly assigns "Guest integration (tarifario)"
  and "Host neutrality with tarifario inactive (R-TAR-HOOK-007)". Moving the listener would invert that contract.
- `ArticlePermissionListener` depends on `tarif_grupo_usuario` (`get_rol_en_tarifa`, `ROL_GESTOR/ROL_EDITOR`),
  `tarif_grupo_articulo` and `tarif_tarifa`. Those role tables are the `tarif_roles` admin domain, **not** article UI.
  Moving only `tarif_grupo_articulo` would either drag the whole role package into catalogo_core (out of scope, ~1.5k
  LOC + admin UI) or leave catalogo_core depending on tarifario (forbidden direction).
- **Interpretation of "groups" in decision #1**: it maps to the article/catalog grouping primitives
  (`tarif_catalogo_def_familia`, `tarif_tarifa_articulo_etiqueta`, catalog family grouping), which **do** move. The
  RBAC role tables are a separate domain and stay.
- catalogo_core's moved catalog/tab surfaces gate mutations with the **soft `class_exists` pattern already accepted**
  in `tarif_opcional_tab::puede_editar_opcional()` (`controller/tarif_opcional_tab.php:232-248`) and the neutral
  `ArticlePermissionFilterEvent` for the article quick-create (`Controller/VentasArticulos.php:420-430`).
- **Alternative considered**: move listener + `tarif_grupo_articulo` (and possibly the whole role package) to
  catalogo_core. Rejected for this change: large blast radius, inverts the documented host/guest contract, and
  duplicates the `tarif_roles` admin surface. Record as a possible follow-up if catalogo_core must enforce RBAC
  standalone.

### 3.5 Options for the catalog surface

| Option | Description | Verdict |
|---|---|---|
| **A (recommended)** | Move `tarif_catalogo*` + `tarif_catalogo_view` into catalogo_core as its own catalogo_core-owned page (new `Controller/VentasCatalogo.php` on `PageController`), migrate to htmx 4 + Alpine, retire `tarif_catalogo_view` + `fs_page`, repoint `TarifarioComponents.html.twig:271` | Clean ownership; mirrors the opcionales unification (new canonical page, old slug retired) |
| B | Keep `tarif_catalogo_view` slug but move the class/views to catalogo_core, rewriting it to `PageController` | Avoids `fs_page` churn, but keeps a `tarif_` slug on a catalogo_core page (naming debt) and an inconsistent canonical experience |
| C | Fold the catalog tree into the canonical `ventas_articulos` list (one page, mode switch) | Maximum "single experience", but merges two very different UIs (ordered tree + notes + images vs flat list) in one huge view; high risk |
| D | Leave the catalog in tarifario | Rejected by decision #1 ("catalog" is explicitly in scope) |

---

## 4. Risks

| # | Risk | L | Mitigation |
|---|---|---|---|
| R1 | ~26k LOC non-test package moved/rewritten; chained PR budget blown | High | Slice by feature (WU plan §6), each with its own rollback; measure authored diff per WU |
| R2 | Autoloader class collision during half-moved state (class in both plugins) | Med | Never leave a class file in both plugins; move class + XML + requires atomically per WU; run the plugin suite after each |
| R3 | `tarif_articulo_precio` table name is hardcoded in catalogo_core (`tarif_tarifa.php:207`, `tarif_configurador_opcionales.php:63`) — a rename would break both | Med | Keep the table name byte-stable; only relocate the model class |
| R4 | Rich Excel wizard moves and collides with the existing basic catalogo_core wizard (`empty.xlsx` exists in both fixture dirs; both define `process_excel_wizard*.php`) | High | Decide unify-vs-coexist in design; namespace routes (`page=ventas_articulos&action=excel_import_sse` stays; catalog wizard route repoints); merge fixtures with unique names |
| R5 | SSE entry coupling: `tarifario/process_excel_wizard.php` writes tarifario models and redirects to `page=tarif_catalogo_view` (`:477`); after retirement the route must move and the JS (`excel-import-wizard.js:879`) must repoint | Med | Move the entry + JS together in the Excel WU; update `ExcelWizardSseEntryTest` + `LegacyImportRegressionTest` |
| R6 | Locked source-string tests break on controller/view moves (`TarifTabPreciosTest`, `TarifCatalogoHtmxContractTest`, `TarifArticulosFamiliaImportTest`, `CatalogoCoreHookMarkersTest`) | High | Per WU, update the test paths/assertions in the same commit; keep the 4 frozen hook names/positions untouched |
| R7 | htmx v2 event names or inline-eval Alpine creep into migrated views | Med | Use `Macro/Htmx.html.twig` + `Macro/Alpine.html.twig`; assert colon names and `Alpine.data()` from nonce'd script (mirror `TarifCatalogoHtmxContractTest:326-337`) |
| R8 | Feature loss during the "one canonical experience" merge (images, etiquetas, notes, JSON import, bulk price update, cleanup actions) | High | Build a feature-parity checklist from §2.2; add contract tests per feature before retiring the source page |
| R9 | Retired pages leave orphan `fs_page` menu rows | Med | Add idempotent retirement methods in `catalogo_core/Init.php::upgrade()` mirroring `retireVentasFamiliasPage()` |
| R10 | `TarifarioComponents.html.twig:271` and tarifario views link to retired slugs | Med | Grep-audit every `index.php?page=tarif_articulos|tarif_catalogo_view|tarif_articulo_edit|tarif_articulo_precios` before archive and repoint |
| R11 | `ArticlePermissionListener`/RBAC boundary regression (quick-create gate) | Med | Keep listener in tarifario (§3.4); keep `VentasArticulosQuickCreateGateCompositionTest` green; if catalogo_core article edit gains new gates, dispatch the existing neutral event |
| R12 | tarifario plugin suite (191 methods) breaks while tests move | High | Move tests with their code per WU; run both plugin suites plus the root Plugins suite after each WU |
| R13 | Nonces/CSP: moved inline scripts lose the nonce | Med | Route all script loading through `htmx.boot()`/`alpine.boot()` and `{{ csp_nonce_attr() }}`; no bare `<script>` in swapped fragments |

---

## 5. Scope split — dependency-ordered WU plan

Size is the headline: the non-test article package is roughly **~8.6k LOC controllers, ~6.8k models, ~2.5k services +
wizard, ~8.3k views/partials/JS**, plus ~5.7k LOC of tarifario tests to move/update. This is a **multi-PR program**,
not a single change. Each WU is strict-TDD, ends with the catalogo_core suite green (baseline **528/1826**) and, where
it touches tarifario, the tarifario suite green, and has an explicit rollback boundary.

### WU-1 — **First slice (user-visible): catalogo_core owns the article Tarifas tab + per-tarifa price/state**
- Move `model/tarif_articulo_precio.php` + `model/table/tarif_articulo_precios.xml` → `catalogo_core/model/` + `model/table/`.
- Add `catalogo_core/controller/tarif_articulo_tab.php` mirroring `tarif_opcional_tab` (extends `fbase_controller`,
  `requireCsrf()`, `simbolo_divisa()`, soft permission gate via the neutral event / `class_exists`). Keep the
  `page=tarif_tab_precios` slug so the host markup does not change in this slice.
- Move the 4 article hook templates + partials to `catalogo_core/View/Hooks/`; register
  `ventas_articulo_tabs_after` / `ventas_articulo_tab_pane_after` from `catalogo_core/Init.php` (extend the hook map,
  extend the `@catalogo_core` registration); remove the article hook registration from `tarifario/Init.php`.
- Migrate the tab UI to htmx 4 + Alpine, mirroring `ventas_opcional_tab_pane_after.html.twig`.
- Repoint `tarifario` article controllers' `require_once` of the moved model (autoloader resolves the class; only the
  hardcoded path requires change), and update `TarifTabPreciosTest` → catalogo_core, `HookRegistrationTest` (tarifario
  article hooks → absent; catalogo_core owns them).
- **Rollback boundary**: catalogo_core new files + `Init` hooks + tarifario hook removal; revert restores tarifario-owned tab.
- **Value**: per-tarifa price/state editing is owned and served by catalogo_core; first slice proves the move mechanics.

### WU-2 — Canonical `ventas_articulo` detail absorbs the tarifario edit surface; htmx 4 + Alpine migration
- Fold `tarif_articulo_edit` behavior into `VentasArticulo` (per-tarifa familia sync via `tarif_tarifa_articulo`, flags,
  etiquetas, images entry point), keeping `VentasArticuloControllerTest` strings intact.
- Rewrite `View/ventas_articulo.html.twig` with `Macro/Htmx.html.twig` + `Macro/Alpine.html.twig`, `htmx.boot()`,
  Alpine CSP components, no bootbox/inline jQuery, `{{ csrf_field() }}`, no `|raw`.
- Retire `tarif_articulo_edit` + `tarif_articulo_precios` (controllers/views), retire their `fs_page` rows, repoint links.
- **Rollback boundary**: canonical controller/view + two deletions + `Init` retirement.
- **Depends on**: WU-1 (price model + tab).

### WU-3 — Canonical `ventas_articulos` list absorbs the tarifario article list; htmx 4 + Alpine
- Port filters `query`/`b_codfamilia`/`b_codtarifa`/`b_solo_activos`, per-tarifa price/state columns, quick-create with
  per-tarifa prices; migrate list to htmx 4 + Alpine (`hx-get` full-page filters + fragment rows).
- Retire `tarif_articulos` controller/view + `fs_page`; keep its Excel/JSON import actions temporarily until WU-7.
- **Rollback boundary**: canonical list controller/view + one deletion + `Init` retirement.
- **Depends on**: WU-1.

### WU-4 — Move the article extension models and tags
- `tarif_articulo`, `tarif_articulos_ext`, `tarif_tarifa_articulo`, `tarif_tarifa_articulo_etiqueta` + XMLs → catalogo_core;
  update all require paths and `tarifario_init.php`; delete `tarif_descripcion`; adjust `TarifArticuloFactoryForImportTest`.
- **Depends on**: WU-2/WU-3 (consumers already canonical).

### WU-5 — Catalog manager ownership
- Move `tarif_catalogo*` models/tables + `tarif_catalogo_view` + `View/tarif_catalogo*` + `View/partials/catalogo/*` +
  `View/js/catalogo/*` into catalogo_core as a catalogo_core-owned catalog page (Option A); migrate to htmx 4 + Alpine
  (it is already 90% there); retire the slug + `fs_page`; repoint `TarifarioComponents.html.twig:271` and tarifario views.
- Move/update `TarifCatalogoHtmxContractTest`, `TarifCatalogoOpcionalMasterExportTest`.
- **Depends on**: WU-4.

### WU-6 — Images, revision notes, price history
- Move `tarif_articulo_imagen`, `tarif_revision_nota`, `tarif_precio_historial` + XMLs + their views/partials/JS, and the
  article branch of `tarif_historial_precios`; keep `tarif_historial_precios`'s opcional branch coherent or move the
  whole controller.
- **Depends on**: WU-5 (notes panel lives in the catalog UI).

### WU-7 — Excel rich wizard + JSON import unification
- Move `ExcelHierarchyService`, `ExcelImportWizardService`, `ExcelRowUpdater`, `process_excel_wizard.php`,
  `View/js/catalogo/excel-*.js`, `json-import.js`, `images-import.js`, `images-zip.js`, wizard fixtures and modals into
  catalogo_core; decide unify-vs-coexist with the existing basic `ArticuloExcel*` stack; repoint SSE route and JS URLs;
  retire the tarifario entry point; move/update `ExcelImportWizardServiceTest`, `ExcelHierarchyService*Test`,
  `ExcelWizardSseEntryTest`, `LegacyImportRegressionTest`, `TarifArticulosFamiliaImportTest`.
- **Depends on**: WU-5/WU-6.

### WU-8 — Retirement audit, RBAC boundary, verify pass
- Confirm §3.4 boundary: `ArticlePermissionListener` + RBAC stay in tarifario; catalogo_core keeps the neutral event and
  soft gates. Update `HookRegistrationTest` (tarifario registers zero hooks), `PermissionListenerInitRegistrationTest`.
- Grep audit for every retired slug and for `plugins/tarifario/` references inside catalogo_core (must be zero for
  article domain). Confirm `fs_page` retirements. PHPStan + both plugin suites + root Plugins suite. Produce
  `verify-report.md`.
- **Depends on**: all previous.

**Explicitly out of scope**: tarifas CRUD (`tarif_tarifas`), `tarif_actualizar_precios`, `tarif_roles`, the RBAC role
tables (`tarif_grupo_rol/usuario/tarifa`, `tarif_tarifa_rol`), `tarif_idioma` alias, and the already-absorbed
familias/opcionales packages.

---

## 6. Recommendation

Adopt **Option A** for the catalog (§3.5) and keep the RBAC/listener boundary in `tarifario` (§3.4). Land the change
as the WU sequence above, starting with **WU-1** (article Tarifas tab + `tarif_articulo_precio` ownership) as the
minimal, independently testable, user-visible first slice. WU-1 exercises every mechanism the rest of the program
needs (model+table move, `catalogo_core/Init.php` hook ownership, endpoint relocation to `fbase_controller`, htmx 4 +
Alpine tab, locked-contract test migration) while touching a small, clearly bounded surface with a clean rollback.

---

## 7. Evidence summary (files inspected)

- catalogo_core: `Controller/VentasArticulos.php`, `Controller/VentasArticulo.php`,
  `View/ventas_articulos.html.twig`, `View/ventas_articulo.html.twig`, `View/partials/articulos/*`,
  `Services/ArticuloExcel*`, `process_excel_wizard*.php`, `Init.php`, `Event/ArticlePermissionFilterEvent.php`,
  `controller/tarif_opcional_tab.php`, `extras/fbase_controller.php`, `model/core/articulo.php`,
  `model/tarif_tarifa.php`, `controller/tarif_configurador_opcionales.php`, `View/Macro/TarifarioComponents.html.twig`,
  `openspec/config.yaml`, `openspec/specs/*`, archived/active precedents
  (`absorber-familias-tarifa-en-catalogo-core`, `absorber-opcionales-tarifa-en-catalogo-core`,
  `unificar-opcionales-en-ventas-opcionales`), tests (`VentasArticulo(s)ControllerTest`,
  `Integration/CatalogoCoreHookMarkersTest`, `ArticuloMultiidiomaTest`).
- tarifario: `Init.php`, `extras/tarif_controller.php`, `extras/tarifario_init.php`, `controller/tarif_articulos.php`,
  `controller/tarif_catalogo_view.php`, `controller/tarif_articulo_edit.php`, `controller/tarif_articulo_precios.php`,
  `controller/tarif_tab_precios.php`, `controller/tarif_historial_precios.php`, `View/tarif_catalogo_view.html.twig`,
  `View/Hooks/*`, `View/partials/catalogo/*`, `View/js/catalogo/*`, `model/tarif_*`, `Services/*`,
  `process_excel_wizard.php`, all article-related tests, `fsframework.ini`, `openspec/config.yaml`,
  `openspec/specs/*`.
- core/theme: `model/fs_page.php`, `themes/AdminLTE/view/Macro/{Htmx,Alpine,HtmxCrud}.html.twig`.

---

## 8. Open questions to resolve in spec/design (with recommended defaults)

1. **Catalog page identity**: new `Controller/VentasCatalogo.php` page name (recommended `ventas_catalogo`) vs keeping
   `tarif_catalogo_view`. Recommended: new canonical page, retire the slug.
2. **Excel stacks**: unify the tarifario rich wizard into the catalogo_core `ArticuloExcel*` stack, or keep two
   explicit entry points (recommended: keep both entry points but move the rich stack under catalogo_core; unify in a
   later refactor to avoid a risky rewrite inside the move).
3. **`tarif_tab_precios` slug**: keep the slug (recommended for a minimal WU-1 diff) vs rename to a catalogo_core name.
4. **Endpoint base class**: keep the moved tab endpoint on `fbase_controller` (recommended, mirrors
   `tarif_opcional_tab`; currency `simbolo_divisa()` + `requireCsrf()` are free) rather than `PageController`.
5. **`tarif_historial_precios`**: move the whole controller to catalogo_core or keep it in tarifario and only move the
   article history model. Recommended: move the model in WU-6; move the controller only if the opcional branch is also
   repointed (it already reads a catalogo_core model).
