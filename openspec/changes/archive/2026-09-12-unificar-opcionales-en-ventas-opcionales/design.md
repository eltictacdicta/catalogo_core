# Design: unificar-opcionales-en-ventas-opcionales

- **Change**: `unificar-opcionales-en-ventas-opcionales`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Secondary plugin**: `tarifario` (retirement/repointing only)
- **Inputs**: `exploration.md` §3–§8, `proposal.md`, delta spec `specs/opcionales-management/spec.md` (OUM-01..OUM-12)
- **Baseline suite**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **475 tests / 1517 assertions OK** (regression floor)
- **Strict TDD**: every WU is red → green; the full plugin suite must stay at or above the baseline after each WU.

---

## Technical Approach

Keep `VentasOpcionales` on `PageController` (preserves `privateCore()`, the legacy wrapper and sibling consistency with `VentasArticulos`/`VentasOpcional`), and port the legacy free helpers it lacks instead of switching base class. All absorbed list logic moves into a **controller-agnostic trait** that owns the tarifa selector, filters, filtered pagination, master-state/price caches, CSRF-guarded toggles, create/delete and Excel export; the trait only touches `PageController` surface (`$this->request`, `$this->db`, `$this->user`, `$this->url()`, `$this->redirect()`, `$this->validateFormToken()`), never `fbase_controller`-only calls.

`tarif_opcionales` is then deleted with no redirect alias, every link/`url()` fallback is repointed, and its `fs_page` row is retired idempotently from `Init::upgrade()`. The opcional "Tarifas" tab on `ventas_opcional` becomes `catalogo_core`-owned: hook templates + a dedicated `fbase_controller` endpoint (`tarif_opcional_tab`) live under `catalogo_core`, registered from `catalogo_core/Init.php` via `TwigLoaderEvent`/`TwigInitEvent` behind a static guard, while `tarifario` keeps only the article pair and the article path of `tarif_tab_precios` byte-identical.

Both the unified list and the moved tab are migrated to htmx 4 + Alpine CSP (colon events only, nonce'd `Alpine.data()`, `hx-post` mutations, `[x-cloak]`, no bootbox, no `|raw`). The plugin's existing `fs_divisa_tools` is the single currency source, exposed on `PageController` through a small catalogo_core formatter, so no legacy base class is required.

The work is split into the exploration's WU-1..WU-6; each is an independent revert boundary and no DB/schema/data migration is involved.

---

## Architecture Decisions

| # | Decision | Alternatives | Choice & Rationale |
|---|---|---|---|
| **AD-1** | List logic placement | (A) inline in `VentasOpcionales`; (B) keep it in legacy `tarif_opcionales`; (C) controller-agnostic trait | **(C)** New trait `VentasOpcionalesListTrait` at `plugins/catalogo_core/extras/VentasOpcionalesListTrait.php` (global trait, `require_once`d). Proposal §"Affected Areas" already names `extras/VentasOpcionalesListTrait.php`; the example `OpcionalesListStateTrait.php` in the phase brief is illustrative and is superseded for proposal/design/tasks coherence. It keeps the `opcional_master_state()`/`opcional_precio_model()` seams, is unit-testable without a controller boot (mirrors `TarifarioOpcionalStateTraitTest`), and does not bind to `fbase_controller`. |
| **AD-2** | Base class of the unified page | (A) stay on `PageController`; (B) switch to `fbase_controller` to inherit `show_precio`/`simbolo_divisa`/`fbase_paginas` | **(A)** Switching would break `privateCore(&$response,$user,$permissions)`, the legacy wrapper subclass and sibling consistency; `VentasOpcionalesControllerTest` + `TarifOpcionalesControllerContractTest` lock the surviving trio on `fbase_controller` but never require the list page to change base. The four missing helpers are ported explicitly (AD-3/AD-4/AD-5). |
| **AD-3** | Native pagination on `PageController` | (A) call `tarif_paginas()`/`fbase_paginas`; (B) mirror `VentasArticulos`'s native contract | **(B)** `hasMoreResults()`, `getPaginationUrl(int)`, `buildListUrl(array)`, `getListQueryParams()`, plus `getPaginationItems()` for the view. `getListQueryParams()` keeps `query`, `b_codfamilia`, `b_codtarifa`, `b_solo_activos`, `offset` and strips `page`/`action`; page stepping uses `FS_ITEM_LIMIT` (the `tarif_opcional::search`/`count_filtered` limit), and the true total comes from `count_filtered()`, never from `count($resultados)`. |
| **AD-4** | CSRF standardization | (A) legacy `requireCsrf()` + `form_token`; (B) `validateFormToken()` (`_csrf_token`) everywhere | **(B)** `PageController` has no `requireCsrf()`; `validateFormToken()` reads `CsrfManager::FIELD_NAME` (`_csrf_token`) and `_token`, exactly what `csrf_field()` and the htmx boot `X-CSRF-TOKEN` header use. Single guard `guard_mutating_action(): bool` = `strtoupper($this->request->getMethod()) === 'POST' && $this->validateFormToken()`. Toggles, **new-opcional (closes the current gap)** and delete all use it. `export_excel_opcionales` stays a read-only GET and is not a mutation. |
| **AD-5** | Currency/price display on `PageController` | (A) rely on `fbase_controller::simbolo_divisa()`; (B) inline `number_format` fallback; (C) catalogo_core formatter over `fs_divisa_tools` | **(C)** New `plugins/catalogo_core/Services/CatalogoCurrencyFormatter.php` (`FSFramework\Plugins\catalogo_core\Services`, `final`, static `symbol()`/`format()`), backed by the catalogo_core-owned legacy `extras/fs_divisa_tools.php` (already loaded by core when the plugin is active). No `fbase_controller`; per-tarifa currency (`coddivisa`) is honored; parity with legacy `show_precio`. The trait exposes `show_precio_opcional($id)` and `simbolo_divisa_tarifa($coddivisa)` for Twig. |
| **AD-6** | Filter key + archive actions | (A) switch to `search` only; (B) `query` primary + `search` alias; delete via GET | **(B)** `query` primary (continuity with `redirect_to_list()` and the master-state contract), `search` accepted as alias (`VentasOpcionalesControllerTest` already asserts the view keeps its CSRF field; htmx contract repointed uses `name="query"`). Delete is a POST (`delete=<id>`) guarded by AD-4 and `allow_delete`, matching the current `VentasOpcionales::eliminarOpcional()` semantics; export is GET. |
| **AD-7** | Moved opcional tab endpoint | (A) extend `tarif_opcional_precios` with `rows`/`guardar`; (B) dedicated catalogo_core `fbase_controller` | **(B)** New `plugins/catalogo_core/controller/tarif_opcional_tab.php`, `class tarif_opcional_tab extends fbase_controller`, slug `tarif_opcional_tab`, `use TarifarioOpcionalStateTrait;` (gives `$tarifas`), plus `simbolo_divisa()`/`requireCsrf()` from `fs_controller`. Actions: GET `action=rows&ref=<id_opcional>` (echoes `@catalogo_core/Hooks/partials/opcional_precios_rows.html.twig`, `template=FALSE`) and POST `guardar_precio_tab` (CSRF + `puede_editar_opcional()` admin/gestor, JSON `{ok,message,html}`). The article surface of `tarif_tab_precios` is untouched in behavior; `rows_tipo()`, `render_opcional_rows_fragment()`, `opcional_precio_model()`, `guardar_precio_opcional()`, `puede_editar_opcional()` and the `tarif_opcional_precio` require are **removed** from `tarif_tab_precios`, whose `render_rows_fragment()` then always renders article rows. |
| **AD-8** | Hook ownership move | (A) keep registration in `tarifario`; (B) register from `catalogo_core/Init.php` | **(B)** `catalogo_core/Init.php` subscribes to `TwigLoaderEvent` (self-registers the `@catalogo_core` namespace so the plugin is self-contained and testable without `$GLOBALS['plugins']`) and `TwigInitEvent`, then registers the two opcional hooks behind a static `$hooksRegistered` guard mirroring `tarifario\Init::$hooksRegistered`. `tarifario/Init.php` keeps only the two `ventas_articulo_*` entries; the opcional templates move to `plugins/catalogo_core/View/Hooks/` and the shared save script is split. |
| **AD-9** | `tarif_opcionales` elimination | (A) redirect alias; (B) full delete + repoint + idempotent page retirement | **(B)** Delete `controller/tarif_opcionales.php` and `View/tarif_opcionales.html.twig`; repoint the two model `url()` no-id fallbacks and the seven views; add `Init::retireTarifOpcionalesPage()` (idempotent, mirroring `retireVentasFamiliasPage()`). No alias. Historical DB-table references (`CatalogLegacyTableMigration`, `TarifOpcionalExtMigration`, `DeadOpcionalTableReferenceTest`) are **not** touched. |
| **AD-10** | htmx 4 + Alpine CSP | (A) keep jQuery/bootbox; (B) htmx 4 + Alpine CSP following `tarif_familias.html.twig` | **(B)** `htmx.boot({'allowScriptTags': false})` scrubber + `alpine.boot()`; full-page filters with `hx-get`/`hx-target="body"`/`hx-select="body"`/`hx-swap="outerHTML"`/`hx-push-url="true"`/`hx-boost="true"`; mutations via `hx-post` or `htmx.ajax('POST')` only (never `hx-delete`); Alpine logic in nonce'd `Alpine.data()` components behind `document.addEventListener('alpine:init')` + `[x-cloak]`; colon events only. |
| **AD-11** | Excel export parity | (A) new export service; (B) port `export_excel_opcionales()` verbatim into the trait | **(B)** Port the Spreadsheet logic verbatim (headers `Codigo (No editar)`, `Ref SAP:`, `Descripción`, `Precio`, `Familia`, `Subfamilia`; familia/subfamilia via `tarif_familia->all()`; selected-tarifa price; active-filter inheritance). Keeps AD-7/OUM-07 parity and avoids a second formatting path. |

---

## Data Flow

```
GET ventas_opcionales (filters query/b_codfamilia/b_codtarifa/b_solo_activos/offset)
  VentasOpcionales::privateCore
    └─ action in TOGGLE_ACTIONS?  ── yes ─► guard_mutating_action() ─► master.set_* ─► redirect_to_list() [exit]
    └─ action=export_excel_opcionales? ── yes ─► ini_filters() ─► export_excel_opcionales() [download, exit]
    └─ POST delete? ─► guard_mutating_action() + allow_delete ─► catalogo_opcional::delete()
    └─ POST codigo+nombre? ─► guard_mutating_action() ─► new_opcional() (code, familias, precio_tarifa_<code>)
    └─ init_opcionales_list()
         ├─ tarif_tarifa::all_activas() / get_default()  ─► resolve_tarifa_seleccionada(requested/default/first)
         ├─ tarif_opcional::search(query, offset, familia, tarifa, solo_activos)
         └─ tarif_opcional::count_filtered(...)          ─► total_resultados
    └─ load_opcionales_state_cache()  ─► tarif_tarifa_opcional::effective(codtarifa, id) per row (read-only)
    └─ load_precios_cache()           ─► tarif_opcional_precio::get(id, codtarifa) per row
  View (htmx 4 + Alpine CSP)
    ├─ filters: hx-get body swap + push-url (preserves the filter keys)
    ├─ toggles: hx-post form + inherited X-CSRF-TOKEN
    ├─ delete: Alpine confirm ─► htmx.ajax('POST', url, {delete:id, _csrf_token})
    ├─ create: Alpine modal ─► hx-post form (codigo/nombre/familias[]/precio_tarifa_<code>)
    ├─ export: Alpine modal ─► GET action=export_excel_opcionales
    └─ pagination: fsc.getPaginationItems() links via native URLs

GET ventas_opcional (saved) ── render_hook('ventas_opcional_tabs_after')
  catalogo_core ViewHookRegistry ─► @catalogo_core/Hooks/ventas_opcional_tabs_after.html.twig
       └─ data-ajax-url: index.php?page=tarif_opcional_tab&action=rows&ref=<id>
GET tarif_opcional_tab&action=rows ─► tarif_opcional_tab::render_rows_fragment()
       └─ @catalogo_core/Hooks/partials/opcional_precios_rows.html.twig (simbolo_divisa per tarifa)
POST tarif_opcional_tab guardar_precio_tab ─► requireCsrf + puede_editar_opcional
       └─ tarif_opcional_precio::get/save/delete ─► JSON {ok,message,html}
```

---

## File Changes

### `plugins/catalogo_core/`

| File | Action | Description |
|---|---|---|
| `extras/VentasOpcionalesListTrait.php` | Create | Controller-agnostic list trait: selector, filters, filtered pagination, state/price caches, toggles, create, delete, export, currency accessors, seams. |
| `Services/CatalogoCurrencyFormatter.php` | Create | Static `symbol()`/`format()` over `fs_divisa_tools`; PageController-safe, no `fbase_controller`. |
| `Controller/VentasOpcionales.php` | Modify | `use \VentasOpcionalesListTrait;`; dispatch toggles/export/create/delete; `init_opcionales_list()`; keeps `privateCore` signature, page data, wrapper contract. |
| `View/ventas_opcionales.html.twig` | Modify | htmx 4 + Alpine CSP rewrite: filter body-swaps, `hx-post` toggles, Alpine confirm/new/export components, `tarif.toggle_button_group(...)`, `{{ csrf_field() }}`, `[x-cloak]`, no bootbox, no `|raw`. |
| `controller/tarif_opcionales.php` | Delete | Legacy page removed, no redirect alias. |
| `View/tarif_opcionales.html.twig` | Delete | Legacy view removed. |
| `controller/tarif_opcional_tab.php` | Create | Dedicated `fbase_controller` rows/save endpoint (AD-7). |
| `View/Hooks/ventas_opcional_tabs_after.html.twig` | Create (moved) | Opcional tab header; endpoint `page=tarif_opcional_tab&action=rows&ref={{ fsc.opcional.id }}`; guarded by `{% if not fsc.is_new %}`. |
| `View/Hooks/ventas_opcional_tab_pane_after.html.twig` | Create (moved) | Pane `#tab_tarifario_precios`; includes the opcional save script partial. |
| `View/Hooks/partials/opcional_precios_rows.html.twig` | Create (moved) | Per-tarifa rows; currency via `fsc.simbolo_divisa(tarifa.coddivisa)` (endpoint is `fbase_controller`). |
| `View/Hooks/partials/opcional_tab_save_script.html.twig` | Create (split) | Opcional copy of the shared save wiring; posts to `page=tarif_opcional_tab`; idempotent window flag. |
| `Init.php` | Modify | Register the two opcional hooks (`TwigLoaderEvent` + `TwigInitEvent`, idempotent); add `retireTarifOpcionalesPage()` to `upgrade()`. |
| `model/tarif_opcional.php` | Modify | `url()` no-id fallback → `index.php?page=ventas_opcionales`. |
| `model/tarif_opcional_precio.php` | Modify | `url()` no-id fallback → `index.php?page=ventas_opcionales`. |
| `View/tarif_opcional_edit.html.twig` | Modify | Back-link(s) at lines ~68/511 repointed. |
| `View/tarif_opcional.html.twig` | Modify | Back-link(s) at lines ~68/330 repointed. |
| `View/tarif_opcional_precios.html.twig` | Modify | Back-link(s) at lines ~15/329 repointed. |
| `View/tarif_*` pages that link the dead page | Modify if present | Any remaining `page=tarif_opcionales` in catalogo_core views repointed. |

### `plugins/tarifario/`

| File | Action | Description |
|---|---|---|
| `Init.php` | Modify | `HOOK_TEMPLATES` drops the 2 opcional entries; keeps the 2 `ventas_articulo_*` entries. |
| `View/Hooks/ventas_opcional_tabs_after.html.twig` | Delete | Moved to catalogo_core. |
| `View/Hooks/ventas_opcional_tab_pane_after.html.twig` | Delete | Moved to catalogo_core. |
| `View/Hooks/partials/opcional_precios_rows.html.twig` | Delete | Moved to catalogo_core. |
| `View/Hooks/partials/tab_save_script.html.twig` | Modify | Article-only copy (drop opcional fields/branch); keep article pane behavior. |
| `controller/tarif_tab_precios.php` | Modify | Remove opcional methods/require; `render_rows_fragment()` always renders article rows (article output unchanged). |
| `View/tarif_actualizar_precios.html.twig` | Modify | Link repointed to `ventas_opcionales`. |
| `View/tarif_articulo_precios.html.twig` | Modify | Link repointed. |
| `View/tarif_articulos.html.twig` | Modify | Link repointed. |
| `View/tarif_historial_precios.html.twig` | Modify | Link repointed. |
| `tests/Integration/HookRegistrationTest.php` | Modify | `FROZEN_HOOKS` = article pair only; drop the opcional guarded-render and opcional host-view assertions (relocated to catalogo_core test). |

---

## Interfaces / Contracts

### `VentasOpcionalesListTrait` (`plugins/catalogo_core/extras/VentasOpcionalesListTrait.php`, global trait)

Props: `$b_codfamilia`, `$b_codtarifa`, `$b_solo_activos`, `$b_url`, `$offset`, `$resultados`, `$total_resultados`, `$tarifa_seleccionada`, `$tarifas`, `$tarifa_defecto`, `$familia`, plus private `$precios_cache`, `$opcionales_state_cache`.

Methods:
- `protected function ensure_opcionales_tables(): void` — once-per-process `Init::ensureOpcionalesTarifaTables()`.
- `protected function init_opcionales_list(): void` — table ensure + `$familia`, `$tarifas`, `$tarifa_defecto`, `ini_filters()`, `search_opcionales()`.
- `protected function ini_filters(): void` — `offset`, `b_codfamilia`, `b_codtarifa` (validated fallback), `b_solo_activos`, `b_url`.
- `protected function resolve_tarifa_seleccionada(?string $requested): ?tarif_tarifa` — requested member → default → first active.
- `protected function search_opcionales(): void` — `tarif_opcional::search()` + `count_filtered()`.
- `protected function opcional_master_state()` / `protected function opcional_precio_model()` — overridable seams (defaults `tarif_tarifa_opcional` / `tarif_opcional_precio`).
- `protected function load_opcionales_state_cache(): void` / `protected function load_precios_cache(): void` — read-only.
- `public function get_precio_opcional_tarifa($id)`.
- `public function opcional_activo_en_tarifa($id): bool` / `opcional_en_catalogo_tarifa($id): bool` / `opcional_en_tarifa_flag($id): bool` — read the master cache keys `['activa']`/`['en_catalogo']`/`['en_tarifa']`.
- `public function show_precio_opcional($id): string` / `public function simbolo_divisa_tarifa(string $coddivisa): string` — AD-5.
- `protected function guard_mutating_action(): bool` — POST + `validateFormToken()` (AD-4).
- `protected function toggle_opcional_state(string $action): void` — `set_activa`/`set_en_catalogo`/`set_en_tarifa` + `redirect_to_list()`.
- `protected function redirect_to_list(string $codtarifa): void` — `$this->redirect(...)` preserving `query`, `b_codfamilia`, `b_solo_activos`, `offset`.
- `protected function new_opcional(): void` — code gen, duplicate check, familias, `precio_tarifa_<code>` normalization, CSRF-guarded.
- `protected function delete_opcional(): void` — POST `delete` + `allow_delete`.
- `protected function export_excel_opcionales(): void` — ported verbatim (AD-11).
- Pagination: `public function hasMoreResults(): bool`, `public function getPaginationUrl(int $offset): string`, `public function buildListUrl(array $params = []): string`, `public function getPaginationItems(): array`, `protected function getListQueryParams(): array`.

The trait must not reference `fbase_paginas`, `requireCsrf`, `getRequest`, `isHtmxRequest`, `show_precio` or `simbolo_divisa` (all `fbase_controller`/`fs_controller`-only).

### `VentasOpcionales` (`plugins/catalogo_core/Controller/VentasOpcionales.php`)

```php
class VentasOpcionales extends PageController
{
    use \VentasOpcionalesListTrait;
    public const TOGGLE_ACTIONS = ['toggle_activa', 'toggle_en_catalogo', 'toggle_en_tarifa'];
    public bool $allow_delete = false;

    public function getPageData(): array;          // name=ventas_opcionales, showonmenu=true (frozen)
    public function privateCore(&$response, $user, $permissions): void;
}
```

`privateCore()` order: resolve `allow_delete`; dispatch `action` in `TOGGLE_ACTIONS` → `toggle_opcional_state()` (returns/exits); `action=export_excel_opcionales` → `export_excel_opcionales()` (exits); POST `delete` → `delete_opcional()`; POST `codigo`+`nombre` → `new_opcional()`; then `init_opcionales_list()`, `load_opcionales_state_cache()`, `load_precios_cache()`.

### `tarif_opcional_tab` (`plugins/catalogo_core/controller/tarif_opcional_tab.php`)

```php
class tarif_opcional_tab extends fbase_controller
{
    use TarifarioOpcionalStateTrait;
    public function __construct();                 // __CLASS__, hidden page
    protected function private_core();             // rows (GET) | guardar_precio_tab (POST)
    protected function opcional_precio_model(): tarif_opcional_precio;   // test seam
    protected function render_rows_action(): void; // template=FALSE, text/html
    protected function render_rows_fragment(): string;
    private function guardar_precio_tab(): void;   // requireCsrf + puede_editar_opcional
    protected function puede_editar_opcional(string $codtarifa): bool;  // admin | gestor
    private function respond_tab_json(bool $ok, string $message, string $html = ''): void;
}
```

### Hook registration

```php
// plugins/catalogo_core/Init.php
private const OPCIONAL_HOOK_TEMPLATES = [
    'ventas_opcional_tabs_after'     => '@catalogo_core/Hooks/ventas_opcional_tabs_after.html.twig',
    'ventas_opcional_tab_pane_after' => '@catalogo_core/Hooks/ventas_opcional_tab_pane_after.html.twig',
];
private static bool $hooksRegistered = false;
private static bool $viewExtensionsRegistered = false;
// init() → registerViewExtensions(): TwigLoaderEvent addPath(__DIR__.'/View','catalogo_core');
//                                    TwigInitEvent → registerHooks() (static guard)
```

`fs_page` retirement: `private static function retireTarifOpcionalesPage(): void` loads `model/fs_page.php`, `$page->get('tarif_opcionales')`, `delete()` when found; called in `upgrade()` inside its own try/catch next to `retireVentasFamiliasPage()`.

### Alpine components (`View/ventas_opcionales.html.twig`, nonce'd classic script)

- `opcionalesList` — filters/pagination shell state (optional).
- `opcionalConfirm` — delete confirmation → `htmx.ajax('POST', url, {values:{delete:id, _csrf_token}})`.
- `opcionalNew` — "Nuevo opcional" modal open/close, submit via `hx-post`.
- `opcionalExport` — export modal open/close, GET `action=export_excel_opcionales`.
Guard: `document.addEventListener('alpine:init', ...)` + window/global marker; `[x-cloak]` on modals/panels; `x-text` never `innerHTML`.

---

## Testing Strategy

All tests are DB-free source/behavior contracts (PHPUnit `processIsolation="true"`, bootstrap `tests/bootstrap.php`). Behavior tests use the preserved `opcional_master_state()`/`opcional_precio_model()` seams and anonymous subclasses, mirroring `TarifOpcionalesControllerMasterStateTest`.

| Test file (plugin-relative) | Action | Covers | Approach |
|---|---|---|---|
| `tests/CatalogoOpcionalesUnifiedControllerTest.php` | **Create** | OUM-01, OUM-02, OUM-03, OUM-04, OUM-05, OUM-06, OUM-07, OUM-09 | Trait behavior via anonymous class: tarifa fallback chain; `query`/`search` alias + filter keys; `count_filtered` total + pagination URL preservation; `effective()`-driven state cache (assert no persistence); price cache; `set_*` toggle + CSRF guard (POST-only, invalid token rejected); new-opcional price normalization + duplicate rejection + CSRF; delete permission gate; export header set + filter inheritance; `url()` repoints. |
| `tests/CatalogoOpcionalesHtmxContractTest.php` | **Create** | OUM-08, OUM-12 | Source contract over `View/ventas_opcionales.html.twig` + moved hook templates: htmx/Alpine boot macros, `allowScriptTags:false`, body-swap filters with locked names, colon events, no v2 names, no `hx-delete`, `hx-post`/`htmx.ajax('POST')` mutations, nonce + `alpine:init` guard, `[x-cloak]`, no `bootbox`, no `|raw`, `tarif.toggle_button_group(`. |
| `tests/Integration/CatalogoOpcionalesHookOwnershipTest.php` | **Create** | OUM-10, OUM-12 | Boot `catalogo_core\Init` + simulate `TwigLoaderEvent`/`TwigInitEvent`; assert the 2 hooks registered once (idempotent across rebuilds), opcional tab/pane render with `@catalogo_core` templates, `tarif_opcional_tab` source contract (slug/class/actions/CSRF/permission/no bare `fsc.simbolo_divisa()`). |
| `tests/TarifOpcionalesControllerContractTest.php` | **Update** | OUM-09, OUM-11 | `CONTROLLER_SLUGS` drops `tarif_opcionales` (3 survivors); survivors still `extends fbase_controller`, trait, zero `tarif_controller`/`plugins/tarifario/model`. |
| `tests/TarifOpcionalesControllerMasterStateTest.php` | **Update** | OUM-03, OUM-04, OUM-12 | Repoint `LIST_CONTROLLER`/`LIST_VIEW` to `Controller/VentasOpcionales.php` + `View/ventas_opcionales.html.twig`; keep edit/precios contracts untouched. |
| `tests/TarifOpcionalesHtmxContractTest.php` | **Update** | OUM-08, OUM-12 | Repoint `LIST_VIEW`; keep edit/precios (incl. `simbolo_divisa` and `hx-delete` bans). |
| `plugins/tarifario/tests/Integration/HookRegistrationTest.php` | **Update** | OUM-10 | `FROZEN_HOOKS` = article pair only; remove opcional guarded-render + opcional host-view assertions; keep article assertions and the bare-`simbolo_divisa` scan for the article tree. |
| `tests/VentasOpcionalesControllerTest.php` | **Keep** | OUM-01, OUM-12 | Must stay green after the view rewrite (`{{ csrf_field() }}`, no `|raw`, wrapper subclass, `privateCore`). |
| `tests/InitUpgradeTest.php` | **Update** (assertion) | OUM-09 | Add that `Init::upgrade()` retires `tarif_opcionales` idempotently alongside `ventas_familias`. |
| `tests/TarifOpcionalEditTarifaSelectorTest.php`, `tests/TarifOpcionalPreciosControllerTest.php`, `tests/TarifConfiguradorOpcionalesTest.php`, `tests/VentasOpcionalControllerTest.php`, `tests/OpcionalDomainModelOwnershipTest.php`, `tests/DeadOpcionalTableReferenceTest.php`, `tests/Services/*`, `tests/Integration/CatalogoCoreHookMarkersTest.php`, `tests/TarifarioOpcionalStateTraitTest.php` | **Keep** | unchanged | No repoint needed. |

**Deleted test files: none.** `TarifOpcionalesControllerMasterStateTest`/`TarifOpcionalesHtmxContractTest` are repointed, not removed, because they also lock the surviving edit/precios surface.

Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (must be ≥ 475 tests / 1517 assertions) plus `plugins/tarifario/tests/Integration/HookRegistrationTest.php` via the root Plugins suite.

---

## Threat Matrix

| Threat | Vector | Mitigation | Verified by |
|---|---|---|---|
| **CSRF** | Toggle/create/delete POST without a valid token; `new_opcional` previously unchecked | All mutations funnel through `guard_mutating_action()` (POST + `validateFormToken()`/`_csrf_token`); htmx boot injects `X-CSRF-TOKEN`; forms carry `{{ csrf_field() }}`; never `hx-delete` | `CatalogoOpcionalesUnifiedControllerTest` (missing token rejected, no persistence), `CatalogoOpcionalesHtmxContractTest`, `VentasOpcionalesControllerTest` |
| **XSS** | Price/name output, JS string interpolation, swapped fragments | Twig autoescape; no `|raw`; Alpine `x-text` (never `innerHTML`); `htmx.boot({'allowScriptTags':false})` scrubber strips `script`/`on*`/`javascript:` from fragments | `CatalogoOpcionalesHtmxContractTest`, `VentasOpcionalesControllerTest` (no `|raw`) |
| **Hook registration** | Duplicate hooks across Twig rebuilds; ownership leak between plugins | Static `$hooksRegistered`/`$viewExtensionsRegistered` guards in `catalogo_core\Init`; `ViewHookRegistry::register` is idempotent per template; `tarifario` registers no opcional hook | `CatalogoOpcionalesHookOwnershipTest` (idempotent), updated `HookRegistrationTest` |
| **Escaping / legacy JS** | bootbox / inline `onclick` / `|e('js')` interpolation removed by migration | Alpine components replace `show_nuevo_opcional`/`eliminar_opcional`; colon events only; `[x-cloak]` | `CatalogoOpcionalesHtmxContractTest` |
| **Permission bypass** | Delete without `allow_delete`; opcional price save without admin/gestor | `delete_opcional()` checks `allow_delete`; `tarif_opcional_tab::puede_editar_opcional()` mirrors the admin/gestor gate; endpoint CSRF via `requireCsrf()` | `CatalogoOpcionalesUnifiedControllerTest`, `CatalogoOpcionalesHookOwnershipTest` |
| **Path/param injection** | `ref`/`codtarifa`/`id` from request | `intval()` on ids, `no_html(trim())` on codes, parameterized/`var2str` model writes | `TarifOpcionalPreciosControllerTest` (kept), `CatalogoOpcionalesHookOwnershipTest` |

---

## Migration / Rollout

Strictly additive-then-subtractive; no DB migration, no schema/data change, no Composer dependency, no `vendor/` delta.

| WU | Change | Rollback |
|---|---|---|
| **WU-1** | New `extras/VentasOpcionalesListTrait.php` + `Services/CatalogoCurrencyFormatter.php`; `Controller/VentasOpcionales.php` absorbs the list logic; new/updated controller tests | Revert the controller/trait/service files; legacy `tarif_opcionales` is still present and untouched. |
| **WU-2** | `View/ventas_opcionales.html.twig` rewritten to htmx 4 + Alpine CSP; new htmx contract test | Revert the single view file; controller behavior unchanged. |
| **WU-3** | Delete `tarif_opcionales` controller/view; repoint model + view links; add `retireTarifOpcionalesPage()`; update contract test | `git revert` restores the page files/links; the `fs_page` retirement is idempotent and the row can be recreated; clear the Twig cache. |
| **WU-4** | Add catalogo_core hooks/partials + `tarif_opcional_tab`; register 2 hooks in `catalogo_core/Init.php`; remove opcional hooks from `tarifario/Init.php` and delete tarifario opcional templates; split `tab_save_script`; strip opcional methods from `tarif_tab_precios` | Revert catalogo_core additions and tarifario removals; article hooks/path were never touched. Clear the Twig cache. |
| **WU-5** | Migrate the moved tab rows/save + pane to htmx 4 + Alpine CSP | Revert the catalogo_core hook templates/endpoint only. |
| **WU-6** | Verify pass: full plugin suite, grep-audit for `page=tarif_opcionales`/`bootbox`/`|raw`/v2 htmx names, `verify-report.md` | No production change. |

Deployment notes: clear the Twig cache after every WU and after any rollback; run the plugin suite and the root Plugins suite (tarifario hook test) at each boundary.

---

## Commit Units

1. `feat(catalogo_core): extract unified opcionales list trait and expand ventas_opcionales` (WU-1, tests included).
2. `feat(catalogo_core): migrate ventas_opcionales view to htmx 4 and Alpine CSP` (WU-2, tests included).
3. `refactor(catalogo_core): delete tarif_opcionales page and repoint links` (WU-3, tests included).
4. `refactor(catalogo_core,tarifario): move opcional tarifas tab ownership to catalogo_core` (WU-4, tests included).
5. `feat(catalogo_core): migrate moved opcional tab to htmx 4 and Alpine CSP` (WU-5).

---

## Open Questions

1. **Orphan `fs_access` rows** for the retired `tarif_opcionales` page: should `retireTarifOpcionalesPage()` also clean matching `fs_access` rows, or is a dangling access row harmless once the page is gone? (Default: leave `fs_access` untouched, mirroring `retireVentasFamiliasPage()`.)
2. **`tarif_opcional_tab` visibility**: should the endpoint page be registered `showonmenu=false` only, or also hidden from the page list entirely? (Default: `showonmenu=false`, like `tarif_tab_precios`.)
3. **Filter alias precedence** if both `query` and `search` are present: `query` wins (documented in the trait); confirm no legacy caller depends on the opposite.
4. **`tarif_tab_precios` `tipo` parameter** after extraction: ignore it (article-only) vs reject unknown `tipo`. (Default: ignore — article output must stay byte-identical.)
5. **Empty active-tarifa set**: the list has no selected tarifa and per-tarifa columns render empty; should the page show an explicit "no tarifas" notice (as the new-opcional modal does)? (Default: show a muted notice, no behavior change.)
