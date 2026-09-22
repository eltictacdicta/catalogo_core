# Explore — gestion-idiomas-catalogo

> Phase: `explore` (read-only). No source code was modified.
> SDD root: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`).
> Core `openspec/` is reference only — **no entries created there** (project anti-pattern).
> Test runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`.

## 1. Goal restated

Close the multi-language description feature in `catalogo_core` by adding the
missing **management** and **editing** surfaces, and by making
`articulo_descripciones` the single source of truth for **every** language
(including the default one):

1. **Language management UI** — create / rename / activate / deactivate /
   set-default / delete a language (`catalogo_idiomas` CRUD). Today nothing
   calls `catalogo_idioma::save()` / `delete()`; `catalogo_idioma::url()` already
   points at `index.php?page=ventas_articulos#idiomas`, but that section does
   not exist.
2. **Language selector in the article editor** — a single
   description / short-description pair is edited for the **currently selected
   language**, eliminating the duplicated default-language field.
3. **Consumer migration** — every direct reader of `articulos.descripcion`
   migrates to `articulo::get_descripcion_idioma()` (or an explicit-language
   equivalent); `articulos.descripcion` stays as a **frozen legacy fallback**.
4. **No re-sync** — `articulos.descripcion` is **not** backfilled into
   `articulo_descripciones`, and (target state) is no longer mirrored on write.

### Confirmed semantic model (user-approved, do not re-litigate)

- `articulo_descripciones` = single source of truth for **all** languages,
  including the default.
- `catalogo_idiomas.por_defecto` = the **only** default pointer; changing the
  default is a flag flip that touches **no** description data.
- `articulos.descripcion` = frozen legacy fallback, never the default pointer
  (binding the default to the base column would force a data move to change the
  default — explicitly forbidden).
- Read chain: **requested language → configured default → `articulos.descripcion` → `''`**.

The canonical precedent is documented verbatim in
`plugins/tarifario/Services/ExcelRowUpdater.php:234-239` and implemented in
`ArticuloListActionHandler.php:685-689,711-715` (`es` → base only when the base
is empty).

## 2. Current-state map

### 2.1 Data model (exists)

**`plugins/catalogo_core/model/table/catalogo_idiomas.xml`** — table
`catalogo_idiomas`: `codidioma varchar(5)` **PK**, `nombre varchar(50) NOT NULL`,
`activo boolean DEFAULT true`, `por_defecto boolean DEFAULT false`.

**`plugins/catalogo_core/model/table/articulo_descripciones.xml`** — table
`articulo_descripciones`: `id serial` PK, `referencia varchar(18) NOT NULL`,
`codidioma varchar(5) NOT NULL`, `descripcion text NOT NULL`,
`descripcion_corta varchar(150) NULL`, `UNIQUE (referencia, codidioma)`,
FK `referencia → articulos(referencia) ON DELETE CASCADE ON UPDATE CASCADE`.
**There is no FK `codidioma → catalogo_idiomas`** (confirmed; restrictions block
is lines 31-44).

**`plugins/catalogo_core/model/core/catalogo_idioma.php`** (`class catalogo_idioma extends \fs_model`):

| Member | Lines | Behaviour / observation |
|---|---|---|
| `TABLE`, `DEFAULT_CODE = 'es'` | 18-19 | const `'es'` used as a hard-coded fallback across the codebase |
| `install()` / `ensure_defaults()` / `default_idiomas_sql()` | 43-63 | seeds exactly `es` (default) + `en`; `ensure_defaults()` no-ops when the table is non-empty |
| `url()` | 65-68 | `index.php?page=ventas_articulos#idiomas` — the management UI seam |
| `get($cod)` | 70-78 | |
| `get_default()` | 80-88 | **returns `false`** when no row has `por_defecto = TRUE` (not total) |
| `exists()` | 90-97 | |
| `test()` | 99-115 | sanitizes, requires `codidioma` 2-5 chars and `nombre` 1-50 |
| `save()` | 117-142 | when `por_defecto` is true, unsets it on every other row (123-125); no guard against a **non-exclusive / zero-default** state otherwise |
| `delete()` | 144-152 | blocks deleting the default; **no cleanup of `articulo_descripciones` rows** |
| `all()` / `all_activos()` | 154-178 | `all_activos()` filters `activo = TRUE` ordered by `nombre` |

**`plugins/catalogo_core/model/core/articulo_descripcion.php`** (`class articulo_descripcion extends \fs_model`):
- `get($id)` 50-58; `get_by_articulo_idioma($referencia, $codidioma)` 60-70;
  `all_from_articulo($referencia)` 72-85; `exists()` 87-94.
- `test()` 96-117 — **rejects an empty `descripcion`** (111-114:
  `if (mb_strlen($this->descripcion) < 1) → error`), so a translation cannot be
  cleared through the model.
- `save()` 119-148 — INSERT/UPDATE; **never invalidates the article search
  cache** (`articulos_search_*`).
- `delete()` 150-153.

### 2.2 `articulo` model (`plugins/catalogo_core/model/core/articulo.php`)

| Method | Lines | Notes |
|---|---|---|
| `descripcion($len = 120)` | 300-307 | truncates the **base** `articulos.descripcion` column |
| `get_descripciones()` | 1375-1386 | cached `all_from_articulo()` |
| `get_descripcion_idioma($codidioma = 'es')` | 1392-1414 | fallback chain: requested → `get_default()` → `articulos.descripcion`; **hard-codes `'es'` as the parameter default** |
| `descripcion_idioma($codidioma = 'es', $len = 120)` | 1419-1427 | wraps `get_descripcion_idioma`; **hard-codes `'es'`** |
| `set_descripcion_idioma($codidioma, $descripcion, $descripcion_corta = null)` | 1432-1460 | upsert; **mirrors into `$this->descripcion` when `codidioma === default`** (1451-1455) — this is the "re-sync" the user forbids in target state |
| `search(...)` | 1158 | text search over the base column only |
| `appendTextSearchConditions()` | 1219-1225 | delegates to `ArticuloSearchQueryBuilder` |
| `tryCacheSearch()` | 1185-… | caches search results under `articulos_search_<query>` |

### 2.3 Controllers

**`plugins/catalogo_core/Controller/VentasArticulo.php`** (canonical detail, `PageController`):
- `public array $idiomas = []` (76).
- `loadCatalogData()` (861-891): `ensure_defaults()` + `all_activos()` (863-865).
- `editarArticulo(Request)` (431-517): writes `$art->descripcion` from
  `sdescripcion` at **461**, then calls `saveMultiidiomaDescriptions()` at **495**.
- **`saveMultiidiomaDescriptions(\articulo $art, Request $request)` (824-859)** — the
  defect: for the language flagged `por_defecto` it **ignores the posted
  `descripcion_<default>` and copies `$art->descripcion` over it** (833-836,
  `continue`); for every other language it **skips empty input** (847-849), so a
  translation can never be cleared.

**`plugins/catalogo_core/Controller/VentasArticulos.php`** (canonical list, `PageController`):
- `getPageData()` 71-80 → `name: ventas_articulos`, `menu: catalogo`, `ordernum: 120`.
- `privateCore()` 82-… calls `load_list_idiomas()` (86); dispatches POST actions
  via `$this->request->request->has(...)` (93-99). `validateFormToken()` is the
  CSRF gate; `$this->allow_delete` (50/66) is the delete permission.
- `nuevoArticulo(Request)` 454-…: requires `ndescripcion` non-empty (468) and
  writes **only** `$art->descripcion` (488) — never a per-language row.

**`plugins/catalogo_core/Controller/VentasOpcional.php`** (`PageController`):
`buscarArticulo()` (385-407) returns `$art->descripcion(50)` / `descripcion(120)`
(394, 397) — base column, no language context.

### 2.4 Views / partials

**`plugins/catalogo_core/View/ventas_articulo.html.twig`**:
- in-pane nav anchor `#multiidioma` (106);
- **base** `sdescripcion` textarea bound to `fsc.articulo.descripcion` (121);
- `description-default-language-hint` shown when `fsc.idiomas|length > 1` (122-124);
- includes `partials/articulos/tab_multiidioma.html.twig` (342).

**`plugins/catalogo_core/View/partials/articulos/tab_multiidioma.html.twig`**:
loops **every active language** (`{% for idioma in fsc.idiomas %}` line 11) and
renders a textarea `descripcion_<cod>` + `descripcion_corta_<cod>` for each
(19-42). Combined with the base textarea, **the default language renders twice
with the same value**. `no-languages-configured` fallback (44-46).

**`plugins/catalogo_core/View/ventas_articulos.html.twig`**: no `#idiomas`
section exists (grep for `idioma` returns zero hits). The `#idiomas` target of
`catalogo_idioma::url()` is dangling. The page has an article list, a new-article
modal, and Excel import/export modals.

### 2.5 Translations

`plugins/catalogo_core/translations/messages.es_ES.yaml` (70-76) and
`messages.en_EN.yaml` (70-76) already define `languages`, `article-multi-language`,
`default-language`, `description-default-language-hint`, `no-languages-configured`.
**No keys exist** for language CRUD (add/rename/activate/set-default/delete,
"cannot delete default", etc.).

### 2.6 Bootstrap / migrations

- `plugins/catalogo_core/Init.php`: `DEFAULT_SEED_MODELS` includes
  `catalogo_idioma` (50); `ensureCatalogTables()` (593-596) ensures the table;
  seeding runs through `seed_if_empty()` (only when the table is empty).
- `plugins/catalogo_core/Services/CatalogLegacyTableMigration.php` maps
  `tarif_idiomas → catalogo_idiomas` (17) and `tarif_descripciones →
  articulo_descripciones` (18). **It does not backfill `articulos.descripcion`.**
- Legacy aliases: `plugins/tarifario/model/tarif_idioma.php` (`extends catalogo_idioma`),
  `plugins/tarifario/model/tarif_descripcion.php` (`extends articulo_descripcion`),
  still instantiated by `tarifario/extras/tarifario_init.php:130`.

### 2.7 List / import / export seam

- `plugins/catalogo_core/extras/VentasArticulosListTrait.php`: `public array $idiomas` (66);
  `load_list_idiomas()` (243-248) loads `all_activos()` for the moved import/export actions.
- `plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php` (103-115): loads
  `$this->idiomas` and resolves `$this->codidioma` from `$_REQUEST['codidioma']` →
  `get_default()` → `'es'`. Its `tarif_buscar_articulo()` (152-171) is the
  **correct** version (`descripcion_idioma($this->codidioma, 50)` at 160,
  `get_descripcion_idioma($this->codidioma)` at 163).

### 2.8 Locked contracts

- `plugins/catalogo_core/openspec/specs/articulo-detalle-canonico/spec.md`:
  **ART-01** (lines 26-29) locks the public `idiomas` array,
  `saveMultiidiomaDescriptions`, and the `#multiidioma` partial include;
  **ART-05** (117-133) locks the tabs `#datos/#precios/#stock/#multiidioma/#opcionales`
  and the four frozen hook markers.
- `articulos-excel-import-export` — the base header list is normative.
- `articulo-lista-canonica` — ALC-02 per-tarifa columns.

## 3. Complete consumer inventory of `articulos.descripcion`

Direct readers that **bypass** `get_descripcion_idioma()`. "Language context"
column states what the call site can know today.

### 3.1 `catalogo_core`

| File / line | What it reads | Language context available |
|---|---|---|
| `Services/ArticuloSearchQueryBuilder.php:91,110,137` | `descripcion LIKE …` inside `articulo::search()` | **None** — static SQL builder. Migrating to a single language would hide matches in other languages → search must become language-agnostic (join all `articulo_descripciones` rows) |
| `Services/ArticuloExcelExportService.php:220,230` | `(string) $art->descripcion` in `writeRow()` | **None** — export has no language parameter; needs a chosen language (default?) or a per-language column shape |
| `Services/ArticuloExcelRowUpdater.php:69-78` (`applyDescripcion`) | reads `$art->descripcion`, writes `$art->descripcion` | **None** — signature has no `$codidioma` (unlike tarifario's `ExcelRowUpdater::applyDescripcion($value, $art, $codidioma, …)`). Dispatched from `applyMappedFields()` at 166-170 |
| `Services/ArticuloExcelImportWizardService.php:449` | `$art->descripcion = trim($row['descripcion'])` in `createArticuloFromRow()` | **None** |
| `Model/CatalogoApiService.php:50` | `'descripcion' => $a->descripcion` (public catalog API) | **None** |
| `model/tarif_articulo_precio.php:421` | `SELECT ap.*, a.descripcion, …` (`all_by_familias`) | **None** — raw SQL; caller renders the joined value |
| `model/tarif_tarifa_articulo.php:171,269,295,321,347,376` | `SELECT ta.*, a.descripcion, f.descripcion as familia_descripcion` | **None** in the model (no `codidioma`); line 385 searches `lower(a.descripcion) LIKE` |
| `Controller/VentasOpcional.php:394,397` | `$art->descripcion(50)` / `descripcion(120)` in `buscarArticulo()` | **None** (PageController + `CaracteristicaHookContextTrait`; no `$codidioma`) |
| `Controller/VentasArticulos.php:488` | `$art->descripcion = $descripcion` (quick create) | **None** — new article; should seed the default language row (or intentionally stay base-only, see §6) |
| `Controller/VentasArticulo.php:461` | `$art->descripcion = sdescripcion` (detail save) | The page **can** know the selected language once the selector exists |

Already-correct readers in `catalogo_core`: `extras/TarifarioOpcionalStateTrait.php:160,163`;
`model/core/articulo.php` fallback itself.

### 3.2 `tarifario`

| File / line | What it reads | Language context available |
|---|---|---|
| `Services/ExcelRowUpdater.php:234-266` (`applyDescripcion`) | doc says the multi-language table is the source of truth; base written **only when `codidioma === 'es'` and base empty** (256-259); `applyDescripcionEn` (274-290) uses `'en'` | Explicit `$codidioma` param — **the canonical precedent, already correct** |
| `Services/ArticuloListActionHandler.php:685-689` | `if ($codidioma == 'es' && empty($articulo->descripcion))` then write base | iterates `$idioma_columns` — correct |
| `Services/ArticuloListActionHandler.php:711-715` | same for fixed columns | iterates `$this->idiomas` — correct |
| `Services/ArticuloListActionHandler.php:1078-1090,1596-1605` | writes base from `descripcion_es` then `set_descripcion_idioma('es'/'en')` | hard-coded `es`/`en` |
| `model/tarif_articulo.php:196` | `articulo_to_array()` copies `$art->descripcion` into `tarif_articulo` | **None** — tarif_articulo extends articulo; the copy is base-only |
| `model/tarif_articulo.php:234-329` (`search_tarifario`) | `LEFT JOIN articulo_descripciones d` + `a.descripcion OR d.descripcion` (259-329) | **Language-agnostic search — proven precedent for article search** |
| `model/tarif_articulo.php:669` | `$articulo->descripcion = $descripcion` (import create path) | **None** |
| `model/tarif_grupo_articulo.php:150,401-405` | `SELECT a.descripcion …` + `LOWER(a.descripcion) LIKE` | **None** (model has no `codidioma`) |
| `controller/tarif_catalogo_view.php:854,970,1157,1266,1323` | `$art->get_descripcion_idioma($this->codidioma)` | `$this->codidioma` — **already correct** |
| `controller/tarif_catalogo_view.php:1723,1730` | search SQL `a.descripcion OR d.descripcion` | language-agnostic search |
| `controller/tarif_catalogo_view.php:2016` | `set_descripcion_idioma($this->codidioma, $value)` | correct |
| `controller/tarif_catalogo_view.php:2153-2154,2983-2986,3548-3549,3828-3829` | explicit `es`/`en` | hard-coded es/en |
| `controller/tarif_catalogo_view.php:5060` | `get_descripcion_idioma($this->codidioma)` | correct |
| `controller/tarif_configurador_opcionales.php:412` | `get_descripcion_idioma($this->codidioma)` | correct |
| `controller/tarif_historial_precios.php:366` | `$articulo->descripcion` (Excel export) | **None** |
| `controller/tarif_actualizar_precios.php:228` | `SELECT ap.*, a.descripcion, a.codfamilia` | **None** (raw SQL) |
| `controller/tarif_roles.php:718` | `$a->descripcion` from `tarif_grupo_articulo::get_articulos_grupo()` | **None** |
| `extras/tarif_controller.php:240,243` | `$art->descripcion($this->codidioma, 50)` and `$art->get_descripcion($this->codidioma)` | `$this->codidioma` — **buggy, see §4(a)** |

> **Note on `tarifario/extras/tarif_controller.php`:** four tarifario controllers
> still `extends tarif_controller` (`tarif_catalogo_view`, `tarif_historial_precios`,
> `tarif_roles`, `tarif_actualizar_precios`), but **no caller of
> `tarif_buscar_articulo()` remains in tarifario** — the live search endpoint was
> absorbed into `catalogo_core/extras/TarifarioOpcionalStateTrait.php:152`
> (with the correct API). The buggy method is effectively dead but still compiled.

## 4. Defect inventory (fold into this change)

| # | Defect | Evidence | Fix direction |
|---|---|---|---|
| **(a)** | **Misused `descripcion()` signature + nonexistent method.** `tarifario/extras/tarif_controller.php:240` calls `$art->descripcion($this->codidioma, 50)` — `articulo::descripcion($len = 120)` receives `'es'` as `$len` (so `mb_strlen(...) > 'es'` → string cast to `0` → any non-empty text renders `'...'`). Line **243** calls `$art->get_descripcion($this->codidioma)` — **`get_descripcion()` does not exist anywhere** (only `get_descripcion_idioma()`). | `articulo.php:300-307`; grep for `function get_descripcion` returns nothing | Correct to `descripcion_idioma($this->codidioma, 50)` / `get_descripcion_idioma($this->codidioma)`, or delete the dead method (aligns with ART-06/ART-07 no-coupling). Severity **Medium** (latent fatal / wrong output if ever dispatched). |
| **(b)** | **Translations cannot be cleared.** `articulo_descripcion::test()` rejects an empty `descripcion` (111-114); `saveMultiidiomaDescriptions()` also `continue`s on empty input (847-849). | `articulo_descripcion.php:96-117`; `VentasArticulo.php:847-849` | Define the clearing semantics: delete the row on empty (preferred, keeps the `UNIQUE` row absent) **or** allow an empty description. Requires relaxing `test()` accordingly and keeping `descripcion_corta`-only rows meaningful. |
| **(c)** | **No FK `articulo_descripciones.codidioma → catalogo_idiomas`.** Deleting a language leaves orphan description rows (and `catalogo_idioma::delete()` does no cleanup). | `articulo_descripciones.xml:31-44`; `catalogo_idioma.php:144-152` | Add FK `ON DELETE CASCADE` **or** an app-level cleanup in `delete()`. A schema FK needs an idempotent migration (`Init::ensureCatalogTables()` / `selfHealCoreTables()` precedent). |
| **(d)** | **`catalogo_idioma::get_default()` can return `false`** (not total). Consumers that assume a default (`get_descripcion_idioma`, `TarifarioOpcionalStateTrait:111`, list trait) silently fall back to the hard-coded `'es'`. Also: deactivating the default language makes it disappear from `all_activos()`, desynchronising the editor selector from the read chain. | `catalogo_idioma.php:80-88`; `articulo.php:1451-1452` | Make default resolution total + invariant: enforce exactly one active `por_defecto` on save/delete/deactivate; expose a `get_effective_default_code()`. |
| **(e)** | **Legacy articles have no `articulo_descripciones` row.** By design (no re-sync), but the editor must upsert on first save and readers must keep the base fallback. | §2.1/§2.7; read fallback `articulo.php:1413` | Preserve the read fallback and the `set_descripcion_idioma` upsert; no data migration. |
| **(f)** | **Description writes do not invalidate the article search cache.** `articulo::search()` caches under `articulos_search_<query>` (1185-1192, 1336-1342); only `articulo::save()` calls `clean_cache()` (1138). `articulo_descripcion::save()` never does, and `set_descripcion_idioma` does not save the article in the non-default (no-mirror) path. | `articulo_descripcion.php:119-148`; `articulo.php:1128-1140,1330-1342` | Invalidate `articulos_search_*` when a description row changes (explicit hook or call `clean_cache()`); **mandatory** before search migrates to per-language descriptions, otherwise search goes stale. |
| **(g)** | **Default-language duplication + discarded input** (the user-visible bug). Default language rendered twice (base textarea 121 + loop in partial 11) and the posted `descripcion_<default>` is discarded in favour of `$art->descripcion` (`VentasArticulo.php:833-836`). | §2.3/§2.4 | Replace the per-language loop with a single selector-driven pair; remove the default `continue` branch. |

## 5. Existing test inventory

| Test | Coverage today | Missing |
|---|---|---|
| `plugins/catalogo_core/tests/CatalogoIdiomaTest.php` | `install()` seeds `es`+`en`; `ensure_defaults()` no-ops when the table has rows | `get_default()`, `all_activos()`, `save()` default-exclusivity, `delete()` default guard + orphan cleanup, `test()` validation, `url()` |
| `plugins/catalogo_core/tests/ArticuloMultiidiomaTest.php` | `get_descripcion_idioma('es')` falls back to base; `descripcion_idioma` truncation | requested→default→base chain **with rows present**; `set_descripcion_idioma` upsert + (removed?) mirror; `get_descripciones()` cache; empty-description clearing |
| `plugins/catalogo_core/tests/VentasArticuloControllerTest.php` | source assertions: view contains `fsc.articulo.descripcion` (186-197), `#multiidioma` (244), `tab_multiidioma.html.twig` (246); controller has `public array $idiomas`, `saveMultiidiomaDescriptions` (232-236) | the language selector; the single-pair save path; no test on `saveMultiidiomaDescriptions` behaviour |
| `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | POST `sdescripcion` save path (572-600, 801-808); `$this->idiomas = []` (432); locked marker list includes `tab_multiidioma.html.twig` (944) | per-language write assertions; clearing; selector |
| `plugins/catalogo_core/tests/Controller/VentasArticulosListAbsorptionTest.php` | `load_list_idiomas()` presence | — |
| `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` / `CatalogoArticuloHookOwnershipTest.php` | four frozen markers | — |

**No test exists** for: language CRUD (create/rename/activate/set-default/delete),
the language-management UI, the editor language selector, clearing a
translation, orphan cleanup on language delete, or any consumer migration.

## 6. Open questions / risks

1. **"Current language" per consumer.** Readers with a `$this->codidioma` context
   (tarifario controllers, `TarifarioOpcionalStateTrait`, `ExcelRowUpdater`)
   migrate mechanically. Readers with **no** context — Excel export/import,
   `CatalogoApiService`, `tarif_tarifa_articulo`/`tarif_articulo_precio` SQL
   joins, `tarif_grupo_articulo`, `VentasOpcional`, `tarif_historial_precios`,
   `tarif_actualizar_precios`, `tarif_roles` — must choose: **configured default
   language** (recommended, matches the read chain) vs an explicit language
   parameter. Decide once and state it in the spec.
2. **Article search semantics.** Migrating `ArticuloSearchQueryBuilder` to a
   single language would *regress* search (non-default-language articles become
   unfindable). Recommended: keep search **language-agnostic** via a
   `LEFT JOIN articulo_descripciones` + `OR d.descripcion LIKE …`, exactly the
   proven `tarif_articulo::search_tarifario` pattern (259-329). This interacts
   with defect (f): the search cache must be invalidated on description writes.
3. **Export/import shape.** `articulos-excel-import-export` locks the base header
   list. Does the export add **one column per active language** (shape change,
   spec delta) or keep one description resolved to the default language
   (no shape change)? The catalogo_core import wizard has a single `descripcion`
   field (no `descripcion_en`), unlike tarifario's mappers. This is the single
   biggest scope fork.
4. **`set_descripcion_idioma` mirror.** Target architecture says the base is
   "frozen"; today the method mirrors into `$this->descripcion` when the language
   is the default (1451-1455). Removing the mirror is a behaviour change that
   touches `ExcelRowUpdater` (which already re-writes the base explicitly at
   256-259) and the detail save path. Must be an explicit, tested decision.
5. **Where the language-management UI lives.** `catalogo_idioma::url()` strongly
   implies a `#idiomas` section on the `ventas_articulos` list page. Alternatives:
   a dedicated `ventas_idiomas` page (+ menu row + `url()` change) or a panel on
   the detail. **Recommendation: `#idiomas` section on `ventas_articulos`** (no
   new page/menu row; the page already loads `idiomas` and owns `allow_delete`).
6. **Permission / CSRF / `entidad` surface.** The list page is `ventas_articulos`;
   language mutations are catalog-configuration actions. Open: gate by
   `$this->allow_delete` (delete-permission) vs admin-only vs a new permission.
   `#[AdminOnly]` is **not** warranted (the page must stay accessible). CSRF must
   follow the existing `validateFormToken()` pattern. The mutation must never be
   reachable via GET.
7. **Six active-language-setups invariant.** Exactly one default; the default
   cannot be inactive (else the selector omits it while the read chain still
   points at it); the last remaining language cannot be deleted. These are spec
   scenarios, not just model code.
8. **Locked contracts vs the fix.** ART-01/ART-05 + `VentasArticuloControllerTest`
   fight the change directly: the selector replaces the `fsc.articulo.descripcion`
   textarea, and `tab_multiidioma.html.twig` may be replaced. These tests and the
   `articulo-detalle-canonico` spec **must be updated as MODIFIED requirements**,
   with the `#multiidioma` anchor preserved. Budget for this explicitly.
9. **Cross-plugin scope.** tarifario has ~15 touched sites + the dead-code bug;
   catalog_core has ~10. Same routing precedent as `caracteristicas-producto`,
   whose tarifario deltas were authored **under the catalogo_core change**
   (`specs/tarifario/…`).
10. **`processIsolation="true"`** in `plugins/catalogo_core/phpunit.xml` — static
    guards and `$GLOBALS['plugins']` handling must follow existing conventions.

## 7. Scope boundary

### In scope

- **Model foundation** — `catalogo_idioma` (total default, invariants,
  validation, `delete()` cleanup, `set_default`), `articulo_descripcion`
  (clearing semantics), `articulo` language API (dynamic default instead of
  hard-coded `'es'`, mirror decision, optional `descripcion_corta_idioma`),
  FK/cleanup for `codidioma`.
- **Language management UI** — `#idiomas` section in `ventas_articulos` +
  controller actions (CSRF + permission) + view/partial + es/en translation keys.
- **Article editor language selector** — single description/short-description
  pair per selected language; remove the default duplication; keep `#multiidioma`.
- **Consumer migration in catalogo_core** — search (language-agnostic), Excel
  export/import wizard, `CatalogoApiService`, list/model SQL joins,
  `VentasOpcional`, article search-cache invalidation.
- **Consumer migration in tarifario** — the no-language readers, the dead-code
  fix in `extras/tarif_controller.php`; the already-correct readers are verified,
  not rewritten.
- **Spec deltas** — `articulo-detalle-canonico` (ART-01/ART-05 MODIFIED),
  possibly `articulos-excel-import-export` (if the export shape changes), plus
  the new `gestion-idiomas` capability spec.

### Out of scope

- Backfilling `articulos.descripcion` into `articulo_descripciones` (forbidden).
- Dropping `articulos.descripcion` (it stays as the frozen legacy fallback).
- Per-language descriptions for entities other than articles (familias,
  opcionales, documentos) — not requested.
- UI-locale i18n of the application itself (only description content is
  multi-language; the UI stays es/en as today).
- Superseding `tarif_idioma`/`tarif_descripcion` legacy aliases (they already
  extend the new models; deletion is a separate cleanup).

**Honest size:** cross-plugin, ~25 production touch points, 2 locked specs and at
least 3 locked test files to update, plus new model + UI + consumer tests. This is
a **large** change and **must** be chained.

## 8. Suggested delivery slices (`auto-chain`, 800-line review budget)

Chained PRs, each independently reviewable and test-green, ordered by dependency.
Tests travel with their code (strict TDD: RED → GREEN per slice).

| Slice | Scope | Est. review size | Depends on |
|---|---|---|---|
| **1. Model foundation** | `catalogo_idioma` invariants (total default, one-active-default, last-language guard, `set_default`, `delete()` cleanup) + `articulo_descripcion` clearing + `articulo` language API (dynamic default, mirror decision) + FK/cleanup migration + tests | ~400–600 | — |
| **2. Language management UI** | `#idiomas` section on `ventas_articulos` + controller actions (CSRF/permission) + partial + es/en keys + tests | ~400–600 | 1 |
| **3. Article editor selector** | Replace the duplicated default field + per-language loop with the selector-driven single pair; `saveMultiidiomaDescriptions` rewrite; `articulo-detalle-canonico` ART-01/ART-05 delta; update `VentasArticuloControllerTest` + `VentasArticuloArticleEditAbsorptionTest`; tests | ~500–700 | 1 |
| **4. catalogo_core consumer migration** | Search (language-agnostic + cache invalidation), Excel export/import wizard, API, SQL joins, `VentasOpcional`; tests | ~500–800 | 1 (search cache) |
| **5. tarifario consumer migration** | No-language readers + dead-code fix in `extras/tarif_controller.php`; verify the already-correct ones; tests under `plugins/tarifario/tests/`; tarifario spec deltas under this change | ~400–600 | 4 |

Notes:
- Slices 2 and 3 are independent of each other and can be chained in either
  order after 1.
- If the export/import shape changes (open question 3), split slice 4 into
  **4a (search + cache)** and **4b (export/import + API)** to stay under budget.
- The `articulo-detalle-canonico` spec delta belongs to slice 3; the
  `articulos-excel-import-export` delta (if any) belongs to slice 4.

## 9. Risks

1. **Locked contracts (High).** `articulo-detalle-canonico` ART-01/ART-05,
   `VentasArticuloControllerTest`, `VentasArticuloArticleEditAbsorptionTest`
   (`tab_multiidioma.html.twig` in the frozen list) and
   `CatalogoCoreHookMarkersTest` directly constrain the selector work. Requires
   explicit MODIFIED deltas + test updates, coordinated in slice 3.
2. **Search regression (High).** Naive single-language search hides
   non-default-language articles and the cached `articulos_search_*` results go
   stale after description writes (defect f). Must be language-agnostic + cache
   invalidation, with tests.
3. **Semantic fork on the export shape (Medium-High).** One-column-per-language
   vs single resolved column changes the locked header contract and the import
   wizard field catalog. Resolve before slice 4.
4. **Default-less / multi-default state (Medium).** `get_default()` is not total
   and the invariants are not enforced; every consumer that assumes a default can
   diverge. Slice 1 must make it total and idempotent.
5. **Cross-plugin boot/legacy aliases (Medium).** `tarifario` still boots
   `tarif_idioma`/`tarif_descripcion` and 4 controllers extend
   `tarif_controller`; the dead buggy method must be removed without breaking
   those subclasses.
6. **Cache invalidation omissions (Medium).** Any new per-language read path that
   is cached must be invalidated on write; `articulo_descripcion::save()` today
   invalidates nothing.
7. **Permission/CSRF surface (Medium).** Language mutations are configuration
   actions on a non-admin page; the gate must be explicit, CSRF-validated and
   POST-only, with denial tests.
8. **Orphan rows / data integrity (Medium).** Without FK or cleanup, deleting a
   language leaves dangling descriptions that can resurface if the code is
   re-added. Requires an idempotent migration.
9. **Test isolation (Low-Medium).** `processIsolation="true"`; new model static
   state and `$GLOBALS['plugins']` must follow the existing test conventions.

## 10. Relevant files (for later phases)

**catalogo_core — models / schema**
- `plugins/catalogo_core/model/core/catalogo_idioma.php`
- `plugins/catalogo_core/model/core/articulo_descripcion.php`
- `plugins/catalogo_core/model/core/articulo.php` (300-307, 1375-1460, 1158-1225, 1185-1342)
- `plugins/catalogo_core/model/table/catalogo_idiomas.xml`
- `plugins/catalogo_core/model/table/articulo_descripciones.xml`
- `plugins/catalogo_core/model/tarif_articulo_precio.php` (421)
- `plugins/catalogo_core/model/tarif_tarifa_articulo.php` (171,269,295,321,347,376,385)

**catalogo_core — controllers / views / traits**
- `plugins/catalogo_core/Controller/VentasArticulo.php` (76, 431-517, 824-891)
- `plugins/catalogo_core/Controller/VentasArticulos.php` (61-99, 454-504)
- `plugins/catalogo_core/Controller/VentasOpcional.php` (385-407)
- `plugins/catalogo_core/View/ventas_articulo.html.twig` (92-124, 342)
- `plugins/catalogo_core/View/ventas_articulos.html.twig` (no `#idiomas` yet)
- `plugins/catalogo_core/View/partials/articulos/tab_multiidioma.html.twig`
- `plugins/catalogo_core/extras/VentasArticulosListTrait.php` (66, 243-248)
- `plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php` (95-171)
- `plugins/catalogo_core/translations/messages.{es_ES,en_EN}.yaml` (70-76)
- `plugins/catalogo_core/Init.php` (46-50, 118-134, 593-596)
- `plugins/catalogo_core/Services/CatalogLegacyTableMigration.php` (17-18)

**catalogo_core — services / API**
- `plugins/catalogo_core/Services/ArticuloSearchQueryBuilder.php`
- `plugins/catalogo_core/Services/ArticuloExcelExportService.php` (215-243)
- `plugins/catalogo_core/Services/ArticuloExcelRowUpdater.php` (69-78, 155-194)
- `plugins/catalogo_core/Services/ArticuloExcelImportWizardService.php` (50-55, 441-469)
- `plugins/catalogo_core/Model/CatalogoApiService.php` (42-61)

**tarifario**
- `plugins/tarifario/Services/ExcelRowUpdater.php` (234-290) — canonical precedent
- `plugins/tarifario/Services/ArticuloListActionHandler.php` (517, 680-719, 1078-1090, 1596-1605)
- `plugins/tarifario/model/tarif_articulo.php` (28, 189-219, 229-329, 658-670)
- `plugins/tarifario/model/tarif_grupo_articulo.php` (147-164, 396-413)
- `plugins/tarifario/controller/tarif_catalogo_view.php` (854-1323, 1723-1730, 2016, 2153-2154, 2983-2986, 3548-3549, 3828-3829, 5060)
- `plugins/tarifario/controller/tarif_historial_precios.php` (366)
- `plugins/tarifario/controller/tarif_actualizar_precios.php` (228)
- `plugins/tarifario/controller/tarif_roles.php` (718)
- `plugins/tarifario/controller/tarif_configurador_opcionales.php` (412)
- `plugins/tarifario/extras/tarif_controller.php` (47, 100-115, 232-251) — **defect (a)**
- `plugins/tarifario/model/tarif_idioma.php`, `model/tarif_descripcion.php` (legacy aliases)

**Specs / tests**
- `plugins/catalogo_core/openspec/specs/articulo-detalle-canonico/spec.md` (ART-01, ART-05)
- `plugins/catalogo_core/openspec/specs/articulos-excel-import-export/spec.md`
- `plugins/catalogo_core/openspec/specs/articulo-lista-canonica/spec.md`
- `plugins/catalogo_core/tests/{CatalogoIdiomaTest,ArticuloMultiidiomaTest,VentasArticuloControllerTest}.php`
- `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`
- `plugins/catalogo_core/phpunit.xml`

## 11. skill_resolution

- **`fsframework-plugin-sdd` — LOADED and APPLIED (primary).** Routing: the change
  is plugin-local (`plugins/catalogo_core/` primary, `plugins/tarifario/`
  secondary — both plugin openspecs). SDD root is
  `plugins/catalogo_core/openspec/` with `ownership: plugin-local`,
  `change_root: plugins/catalogo_core/openspec/changes/{name}/`,
  `archive_root: …/changes/archive/{YYYY-MM-DD}-{name}/`. **No entry created or
  planned in core `openspec/`** (anti-pattern avoided). Dispatcher limitation
  acknowledged: `gentle-ai sdd-status` only sees the root openspec, so future
  archive must be handed the explicit plugin path.
- **`fsframework-model-crud` — LOADED and APPLIED.** Relevant to the model
  foundation (slice 1): XML schema conventions, `test()`/`save()`/`delete()`
  shape, `var2str()` SQL safety, and PHPUnit patterns (bare anonymous subclass
  with `db` injected). Note: this change **extends existing models**, it does not
  create a new table; the FK for `codidioma` is a schema delta, not a new table.
- **`sdd-explore` — LOADED and APPLIED.** Read-only phase; the single artifact is
  this `explore.md`. No source file was modified.
- Supporting skills expected later: `fsframework-test-writing` (plugin suite
  under `plugins/catalogo_core/tests/`), `fsframework-security-review`
  (CSRF/permission-gated language mutations), `sdd-spec` / `sdd-design` /
  `sdd-tasks` for the remaining phases. `strict_tdd: true` per plugin config;
  runner `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`.
