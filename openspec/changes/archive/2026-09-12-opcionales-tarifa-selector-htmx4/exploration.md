# Exploration: tarifa selector + htmx 4 / Alpine adoption for opcionales

- **Change**: `opcionales-tarifa-selector-htmx4`
- **Owner (SDD)**: `plugins/catalogo_core/openspec/` (plugin-local, `ownership: plugin-local`)
- **Secondary plugin**: `tarifario` (reference pilot only, not modified by this change)
- **Artifact store**: `openspec`
- **Exploration date**: 2026-09-12
- **Baseline suite**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **455 tests, 1381 assertions, OK** (25 warnings, 1 skipped). This is the regression floor.

---

## 1. Intent

The user (verbatim, Spanish):

> "en vista_opcionales me falta el selector de tarifa para poder ver los precios que se ha dado y editar teniendo en cuenta la tarifa, tambien necesito que hagas una adaptación a htmx 4 + alpine js. Usa SDD pero no me preguntes en cada paso, divide el trabajo como lo necesites e intenta no pedirme confirmaciones ya que no voy a estar presente."

Two goals:

1. **Missing tarifa selector**: in the opcionales management surface, one view lets the user view/edit per-tarifa prices but has no control to pick the tarifa.
2. **htmx 4 + Alpine adoption**: migrate the opcionales management views from the current jQuery/bootbox/`onclick`/full-page-reload style to the htmx 4 + Alpine CSP patterns already proven in this repo (tarifario catalog pilot + catalogo_core `tarif_familias`).

There is no page/file literally named `vista_opcionales`; the phrase names the opcionales management surface owned by `catalogo_core`.

---

## 2. Current-state findings (file:line evidence)

### 2.1 Which views are reachable and how

| View / page | Controller | Reachable from | Tarifa selector? |
|---|---|---|---|
| `tarif_opcionales` (list) | `controller/tarif_opcionales.php` | menu "Opcionales" | **YES** |
| `tarif_opcional_edit` (single opcional) | `controller/tarif_opcional_edit.php` | list "código" link + `tarif_opcional::url()` | **NO** |
| `tarif_opcional_precios` (single opcional + tarifa) | `controller/tarif_opcional_precios.php` | list row/price/buttons | **YES** |

Routing evidence:

- `model/tarif_opcional.php:98-105` — `url()` returns `index.php?page=tarif_opcional_edit&id={id}`.
- `View/tarif_opcionales.html.twig:147` — row `clickableRow` href → `tarif_opcional_precios&id=...&codtarifa={{ fsc.b_codtarifa }}`.
- `View/tarif_opcionales.html.twig:149` — código link → `{{ value.url() }}` (i.e. `tarif_opcional_edit`).
- `View/tarif_opcionales.html.twig:173, 224, 227` — price cell / tags button → `tarif_opcional_precios`; edit button → `value.url()`.
- `View/tarif_opcional_edit.html.twig:107, 113, 118` — back/refresh/"Precios por tarifa" → list / self / `tarif_opcional_precios`.

All three surfaces are reachable. The **only one missing a tarifa selector is `tarif_opcional_edit`**.

### 2.2 Tarifa selector state (exact lines)

- `View/tarif_opcionales.html.twig:99-107` — `<select name="b_codtarifa" onchange="this.form.submit()">` inside `f_buscar`. Selector exists.
- `View/tarif_opcional_precios.html.twig:49-64` — GET form with `<select name="codtarifa" onchange="this.form.submit()">`. Selector exists.
- `View/tarif_opcional_edit.html.twig` — **no `name="codtarifa"` select anywhere**. `fsc.codtarifa` is only:
  - read from `$_REQUEST['codtarifa']` or the default in `extras/TarifarioOpcionalStateTrait.php:124-129`;
  - echoed into hidden inputs (line 162) and into URLs;
  - used to scope the **etiquetas** forms (lines 209-213) and the `save_etiquetas_familia` action.
  - The "Precios por tarifa" tab (lines 291-346) renders **one form per tarifa** (bulk matrix), each with `guardar_precio_tarifa` + `codtarifa` hidden + price + `tarif.toggle_button_group`; i.e. it can edit all tarifas but has no selected-tarifa control and does not scope the rest of the page.

**Correction to the orchestrator's interpretation**: `tarif_opcionales` does **not** lack a selector (it has `b_codtarifa`); what it lacks is inline per-tarifa price editing (its price cell links out to `tarif_opcional_precios`). The genuinely selector-less view is `tarif_opcional_edit`.

### 2.3 Per-tarifa state model already in place (do NOT re-implement)

- `model/tarif_tarifa_opcional.php` — master keyed by `(codtarifa, id_opcional)`:
  - read: `effective()` (391-413), `resolve_activa()` (423-428), `resolve_en_catalogo()` (437-442), `resolve_orden()` (451-456), `get()` (191-199);
  - write: `set_activa()/set_en_catalogo()/set_en_tarifa()/set_orden()` (467-512) → single-column update (`update_single_field`, 547-562), lazy inherit on first write (`inherited_row`, 572-584);
  - lifecycle: `seed_default_tarifa()` (115-131), `copy_from_tarifa()` (312-352).
- `model/tarif_opcional_precio.php` — price adapter **extends `catalogo_opcional_precio`**, keyed by `codlista` (= `codtarifa`); verified by `tests/TarifOpcionalPreciosControllerTest.php:98-140`.
- `extras/TarifarioOpcionalStateTrait.php` — shared state used by all four opcional controllers: `$tarifas`, `$tarifa_defecto`, `$tarifas_defecto`, `$codtarifa`, `$codidioma` (lines 57-130), plus `run_in_transaction()` (184-221) and `parse_price_input()` (241-253).
- Controller exposure (already wired to the master):
  - list: `load_opcionales_state_cache()` (`tarif_opcionales.php:200-215`), `opcional_activo_en_tarifa` (261-267), `opcional_en_catalogo_tarifa` (276-282), `opcional_en_tarifa_flag` (290-296), `load_precios_cache()` (222-239), `get_precio_opcional_tarifa()` (246-252).
  - edit: `load_precios_tarifas()` (`tarif_opcional_edit.php:247-270`), `get_precio_tarifa` (520-526), `opcional_activo_en_tarifa` (534-540), `opcional_en_catalogo_tarifa` (548-554), `opcional_en_tarifa_flag` (562-568), `guardar_precio_tarifa` (401-474), `guardar_precios_tarifas` (281-395 bulk).
  - precios: `esta_activo_en_tarifa()` (`tarif_opcional_precios.php:159-169`), `esta_en_catalogo()` (176-186), `esta_en_tarifa()` (193-205), `get_precios_todas_tarifas()` (289-308), `guardar_precio_tarifa` (213-282).
- **Conclusion**: the target work is UI/selector + htmx/Alpine, consuming the existing master through the existing accessors. No master changes required.

### 2.4 Existing htmx 4 + Alpine patterns (canonical, reuse verbatim)

**Boot macros** (the ONLY loading points; no global include):
- `themes/AdminLTE/view/Macro/Htmx.html.twig:45-87` — `htmx.boot(config)` emits: optional sanitizer script (when `allowScriptTags:false`), a nonce'd inline bootstrap that sets inherited `hx-headers` `X-CSRF-TOKEN` on `document.documentElement` (lines 80-85), and a nonce'd deferred `<script src="view/js/htmx.min.js">` (line 86).
- `themes/AdminLTE/view/Macro/Alpine.html.twig:31-33` — `alpine.boot()` emits a nonce'd deferred `<script src="view/js/alpine-csp.min.js">`.
- Import contract: `{% import 'Macro/Htmx.html.twig' as htmx %}` + `{{ htmx.boot() }}`; `{% import 'Macro/Alpine.html.twig' as alpine %}` + `{{ alpine.boot() }}` (see `TarifCatalogoHtmxContractTest.php:143-162` for the asserted literal).
- `themes/AdminLTE/view/Macro/HtmxCrud.html.twig:29-34` — `crud.boot(config)` emits a JSON config block and nonce'd deferred `Sortable.min.js` → `fs-dialogs.js` → `htmx-crud.js`. Note (lines 16-18): `crud.boot()` does **not** load htmx core; the view must also call `htmx.boot()`.

**htmx 4 colon event names** (v2 names are banned):
- `view/js/htmx-crud.js:155-159` listens `htmx:after:request`; `:385-386` listens `htmx:after:swap`.
- `plugins/tarifario/View/js/catalogo/catalogo-main.js:534-576` listens `['htmx:after:swap', 'htmx:after:request']`.
- `plugins/tarifario/tests/Controller/TarifCatalogoHtmxContractTest.php:326-337` asserts the colon names and explicitly forbids `htmx:afterSwap` / `htmx:afterRequest`.

**Alpine CSP build** (no inline eval; logic lives in `Alpine.data()`):
- `plugins/catalogo_core/View/tarif_familias.html.twig:318-438` — inline classic script registers `Alpine.data('familiaAddModal'|'familiaEditModal'|'familiaExportModal'|'familiaImportModal', function () { ... })` with an `if (window.Alpine) ... else document.addEventListener('alpine:init', ...)` guard (lines 432-436), then `{{ alpine.boot() }}` at line 442. `[x-cloak]` style at lines 28-30.
- Same pattern in `plugins/OidcProvider/view/admin_oidc_customer_access.html.twig:47` and `themes/AdminLTE/view/admin_agentes.html.twig:48,82`.
- Alpine boot is deferred and must load **after** the registration script (comment at `tarif_familias.html.twig:440-441`).

**CSP / nonce**:
- `csp_nonce_attr()` Twig function registered in `src/Core/Html.php:304-312`, backed by `SecurityHeaders::nonce()/nonceAttribute()` (`src/Security/SecurityHeaders.php:87-99`).
- Current policy still ships `'unsafe-inline'` (`src/Security/SecurityHeaders.php:23`), so nonce is not yet enforced end-to-end. The macros already nonce their scripts; **new inline scripts should carry `{{ csp_nonce_attr() }}`** to match. Note: existing `tarif_familias.html.twig:305,321` inline scripts are not nonced — an inconsistency to avoid replicating (see Risks).

**htmx fragments in legacy controllers** (two proven styles):
- Legacy style (no new base class): `plugins/tarifario/controller/tarif_catalogo_view.php` — `extends tarif_controller` (line 97), action `switch` (lines 608-612), fragment methods set `$this->template = false` and echo a fragment template (e.g. lines 730-829, 838-1044). HX detection delegates to `fs_controller::isHtmxRequest()`.
- Modern base: `src/Controller/HtmxCrudController.php` — `buildFragment()` (62-119), `renderFragment()` (124-129), `renderRowFragment()` (134-142), `renderTbodyFragment()` (147-158), `noContentWithFlash()` (163-175), `flashPayload()` (181-188), `emit()` (194-204). Used by `catalogo_core` `tarif_familias`.
- Functional filter pattern (full-page swaps, no new endpoint): `plugins/tarifario/View/partials/catalogo/toolbar.html.twig:17-32,144-160` — `hx-get` + `hx-trigger="change"` + `hx-target="body"` + `hx-select="body"` + `hx-swap="outerHTML"` + `hx-push-url="true"` + `hx-boost="true"`, asserted by `TarifCatalogoHtmxContractTest::test_toolbar_filters_use_hx_get_with_url_push` (254-266).
- htmx `HX-Trigger` flash contract: `src/Controller/HtmxCrudController.php:76-92, 163-175`; consumer `view/js/htmx-crud.js`.

### 2.5 Existing opcionales views use legacy patterns (migration surface)

- `View/tarif_opcional_edit.html.twig:4-101` — global `bootbox.confirm` + `onclick` handlers + `$('#buscar_articulo').autocomplete(...)` (jQuery UI), full-page `window.location.href` navigation, `$(document).ready` tab logic.
- `View/tarif_opcionales.html.twig:4-37` — `bootbox.confirm`, `$("#modal_nuevo_opcional").modal('show')`, `$(document).ready`.
- `View/tarif_opcional_precios.html.twig:58` — `onchange="this.form.submit()"` full reload; no JS module.
- `tarif_opcionales.php:91-102` — GET `action` dispatch; mutating toggles are POST + CSRF (`toggle_opcional_state`, 370-403).
- No JS module directory for opcionales (only `View/js/familias/` and `View/js/articulos-excel-import-wizard.js` exist).

### 2.6 Test-locked contracts that MUST NOT break

`plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php`:
- `64-78` — the four controllers exist and keep class names `tarif_opcionales`, `tarif_opcional_edit`, `tarif_opcional_precios`, `tarif_configurador_opcionales`.
- `80-97` — each **`extends fbase_controller`** and must NOT `extends tarif_controller`.
- `99-108` — zero `tarif_controller` references.
- `110-124` — zero `plugins/tarifario/model` references (normalized).
- `126-135` — each `use TarifarioOpcionalStateTrait;`.

`plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php` (the strongest lock):
- `309-325` — all three controllers require `model/tarif_tarifa_opcional.php` and import `FSFramework\model\tarif_tarifa_opcional`.
- `327-342` — list accessors must read `['activa']`/`['en_catalogo']` and **never** `precios_cache`; list source must contain `effective(`.
- `344-360` — list must declare `toggle_activa`, `toggle_en_catalogo`, `toggle_en_tarifa`, contain `requireCsrf()`, `set_activa(`, `set_en_catalogo(`, `set_en_tarifa(`.
- `362-386` — edit `load_precios_tarifas` contains `effective(` and `'activa'`; accessors contain `['activa']`/`['en_catalogo']`; `guardar_precios_tarifas` contains `set_activa(`/`set_en_catalogo(`; `guardar_precio_tarifa` contains `requireCsrf()`/`set_activa(`/`set_en_catalogo(`/`set_en_tarifa(`.
- `388-451` — bulk `guardar_precios_tarifas` keeps `requireCsrf()`, `run_in_transaction(`, `new_error_msg(`, `!$master->set_*`, `!$precio->save()`, `!$opcional->delete_precio_tarifa(`, `parse_price_input(`, and **no** `floatval(`.
- `453-509` — precios accessors use `resolve_activa(`/`resolve_en_catalogo(`; `guardar_precio_tarifa` keeps `requireCsrf()` + all three `set_*` + `run_in_transaction(` + `new_error_msg(`; edit single save keeps `run_in_transaction(`, `parse_price_input(`, `new_error_msg(`, `load_precios_tarifas()`.
- `511-537` — list `redirect_to_list` body carries `query`, `b_codfamilia`, `b_solo_activos`, `offset`; list view has `name="offset"`, `name="query"`, `name="b_codfamilia"`, `name="b_solo_activos"`.
- `539-555` — **all three views** import `'Macro/TarifarioComponents.html.twig' as tarif` and call `tarif.toggle_button_group(`.
- `557-570` — list view has `action=toggle_activa` + `{{ csrf_field() }}`; edit view has `guardar_precio_tarifa` + `{{ csrf_field() }}`; precios view has `guardar_precio_tarifa` + `{{ csrf_field() }}`.

`plugins/catalogo_core/tests/TarifOpcionalPreciosControllerTest.php`:
- `85-96` — adapter `tarif_opcional_precio extends catalogo_opcional_precio`.
- `98-140` — `get()` reads by `codlista`, not `codtarifa`.
- `142-156` — no physical `tarif_opcional_precios` table.
- `158-177` — precios controller requires the adapter and uses `new tarif_opcional_precio()`.

`plugins/catalogo_core/tests/TarifConfiguradorOpcionalesTest.php` (configurator is a separate surface; only relevant if scope is widened):
- zero tarifario requires/uses; declared consts; private SQL helpers; hierarchy actions; `htmx_tree` fragment (311-325); push actions return JSON (332-354); mutating actions require POST+CSRF (389+).

`plugins/tarifario/tests/Controller/TarifCatalogoHtmxContractTest.php:326-337` — bans v2 event names (`htmx:afterSwap`, `htmx:afterRequest`) in the v4 pilot; reuse this rule.

**No test currently locks the opcionales Twig strings for htmx/Alpine** (none exist yet), so new htmx/Alpine contracts are additive.

### 2.7 Test patterns to extend

- DB-free source/contract tests live in `plugins/catalogo_core/tests/`, namespace `Tests\CatalogoCore`, PHPUnit 11; suite is `processIsolation="true"` (`plugins/catalogo_core/phpunit.xml:7`).
- Source-string contract style: `file_get_contents` + `assertStringContainsString`/`assertStringNotContainsString`, with `methodBody()` brace-matching to scope assertions (`TarifOpcionalesControllerMasterStateTest.php:100-122`).
- Controller behavior without DB: anonymous subclass + overridable seam (`opcional_master_state()`) + reflection invoke (`TarifOpcionalesControllerMasterStateTest.php:168-223`).
- Real-Twig partial rendering with `ArrayLoader` + stub `fsc` (`TarifCatalogoHtmxContractTest.php:101-137`).
- Fragment capture: anonymous subclass overriding `emit()`/`renderPartial()` (`TarifFamiliasFragmentTest.php:80-131`).

---

## 3. Target view decision

**Decision: `tarif_opcional_edit` is the target for the tarifa selector.** It is the only opcionales view with no tarifa control, and it is the natural single-opcional editing surface (reachable from the list's código link and from `tarif_opcional::url()`).

Justification:
- `tarif_opcionales` and `tarif_opcional_precios` already have selectors; adding a second one there is redundant.
- `tarif_opcional_edit` already carries `fsc.codtarifa` state and uses it for the etiquetas tab, but exposes no way to change it — the selector is genuinely missing and immediately useful.
- The per-tarifa price editing logic already exists in the controller (`guardar_precio_tarifa`, `load_precios_tarifas`, `get_precio_tarifa`, `opcional_activo_en_tarifa`), so scoping the view to the selected tarifa is a view/selector change, not a model change.
- Keeping `tarif_opcional_edit` on `fbase_controller` respects the locked contract; the htmx fragment style proven by `tarif_catalogo_view` does not require `HtmxCrudController`.

Scope of the htmx 4 + Alpine adoption: the three opcionales management views (`tarif_opcionales`, `tarif_opcional_edit`, `tarif_opcional_precios`). `tarif_configurador_opcionales` is **out of scope** (already has an `htmx_tree` fragment and its own heavy JS; touching it widens blast radius without serving the request).

---

## 4. Options considered

| Option | Description | Verdict |
|---|---|---|
| **A (recommended)** | Add an htmx-driven tarifa selector at the top of `tarif_opcional_edit`. The "Precios por tarifa" tab renders the **selected tarifa's editable panel** (reusing `guardar_precio_tarifa` + `tarif.toggle_button_group`) plus a compact read-only all-tarifas overview. Keep the bulk `guardar_precios_tarifas` controller path (test-locked) even if the view stops calling it. | Best fit: fills the exact gap, reuses existing state + locked forms, keeps the locked parent class. |
| B (minimal) | Add the selector only to scope the etiquetas tab; leave the bulk matrix untouched. | Too little: does not deliver "ver los precios ... y editar teniendo en cuenta la tarifa". |
| C (consolidate) | Retire the edit view's prices tab; make `tarif_opcional_precios` the only price editor; keep edit for datos/familias/artículos. | Possible, but removes editing context from the single-opcional page the user is on; larger UX churn. |
| D (migrate base) | Migrate the opcional controllers to `HtmxCrudController` (like `tarif_familias`). | Rejected: directly breaks `TarifOpcionalesControllerContractTest` (`extends fbase_controller`); would require an intentional spec + test change for little gain. |

---

## 5. Risks

| # | Risk | Likelihood | Mitigation |
|---|---|---|---|
| R1 | Breaking the locked `extends fbase_controller` contract by reaching for `HtmxCrudController`. | Med | Keep `fbase_controller`; implement fragments with the `tarif_catalogo_view` style (`template=false` + echo fragment template). Add an explicit test forbidding `extends HtmxCrudController` on the opcional controllers. |
| R2 | Breaking locked view strings (`Macro/TarifarioComponents` + `tarif.toggle_button_group(`, `guardar_precio_tarifa`, `{{ csrf_field() }}`, `name="offset/query/b_codfamilia/b_solo_activos"`, `action=toggle_activa`). | High | Keep all locked strings verbatim in the migrated views; run `TarifOpcionalesControllerMasterStateTest` after every WU. |
| R3 | Removing the bulk `guardar_precios_tarifas` method or its body contracts. | Med | Keep the method and its body intact; only stop calling it from the view if needed. Retiring it requires a deliberate spec + test change. |
| R4 | htmx v2 event names creeping in (`htmx:afterSwap`, `htmx:afterRequest`). | Med | Mirror `TarifCatalogoHtmxContractTest:326-337`: assert colon names and `assertStringNotContainsString` v2 names in the new contract test. |
| R5 | Alpine CSP build (no inline eval): inline `x-on`/`x-data` expressions with rich logic fail silently. | Med | Put logic in `Alpine.data()` registered from a classic inline script with the `alpine:init` guard; keep directives to simple method calls; use `[x-cloak]`. |
| R6 | `codtarifa` round-trip: the trait reads `$_REQUEST['codtarifa']` (`TarifarioOpcionalStateTrait.php:124-129`) but does not validate it against `$tarifas`. | Med | Selector sends `codtarifa` (GET); controller validates membership in `$this->tarifas` and falls back to `tarifa_defecto`/first. Add a behavior test for an unknown `codtarifa`. |
| R7 | Full-page reload vs fragment swap: pushing the URL with `hx-select="body"` is simplest but re-runs the whole page; a fragment endpoint is smoother but adds surface. | Med | WU-2 uses `hx-select="body"`/`hx-target="body"`/`hx-push-url` (proven by the tarifario toolbar). Fragment endpoints are an optional later WU, only where the UX demands it. |
| R8 | jQuery UI autocomplete (`#buscar_articulo`, `tarif_opcional_edit.html.twig:70-82`) breaks after htmx body swaps. | Med | Re-init on `htmx:after:swap` (idempotent), or migrate the article search to an htmx fragment + Alpine. |
| R9 | `bootbox` removal leaves other flows dependent on it. | Low | Migrate only the flows in scope; keep the global dependency until the flow is converted, then delete the local calls. |
| R10 | Nonce inconsistency: existing inline scripts in `tarif_familias.html.twig` are not nonced while macros are. | Low | New inline scripts carry `{{ csp_nonce_attr() }}`; do not replicate the un-nonced pattern. |
| R11 | Regression from cross-cutting JS (`htmx-crud.js`, sortable) double-binding after swaps. | Med | Use the idempotent-binding pattern from `catalogo-main.js:534-576` (double-bind guards) and assert it in contract tests. |
| R12 | Changing the list's jQuery POST toggles may break `TarifOpcionalesControllerMasterStateTest:511-570`. | Med | Preserve the toggle forms' locked names/CSRF; if converting to `hx-post`, keep the same `action=` names and hidden fields. |

---

## 6. Recommended scope split (small work units)

Each WU is independently testable with strict TDD (tests first, red → green) and must end with the full plugin suite green (`ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`, baseline 455 OK).

### WU-1 — Tarifa selector + scoped price panel in `tarif_opcional_edit` (core value)
- **Controller** (`controller/tarif_opcional_edit.php`): validate the incoming `codtarifa` against `$this->tarifas`, expose `tarifa_seleccionada` from the trait state; keep `load_precios_tarifas()`/accessors/`guardar_precio_tarifa` unchanged in behavior.
- **View** (`View/tarif_opcional_edit.html.twig`): add the tarifa selector at the top; render the selected tarifa's editable panel using the existing `guardar_precio_tarifa` form + `tarif.toggle_button_group`; keep a compact all-tarifas read-only overview. Preserve every locked string.
- **Tests**: new `TarifOpcionalEditTarifaSelectorTest.php` (source contract for the selector + behavior test for unknown/valid `codtarifa` via the `opcional_master_state()` seam).
- **Do not**: touch the master model; remove `guardar_precios_tarifas`; change the parent class.

### WU-2 — htmx 4 boot + selector-driven update for `tarif_opcional_edit`
- **View**: add `{% import 'Macro/Htmx.html.twig' as htmx %}{{ htmx.boot() }}` and `{% import 'Macro/Alpine.html.twig' as alpine %}`; convert the tarifa selector to `hx-get` + `hx-trigger="change"` + `hx-target="body"` + `hx-select="body"` + `hx-swap="outerHTML"` + `hx-push-url="true"` + `hx-boost="true"` (tarifario toolbar pattern).
- **Tests**: contract test asserting the two macro imports, `htmx.boot()`, the hx attributes, and the absence of `onchange="this.form.submit()"` for the selector.

### WU-3 — Alpine migration of `tarif_opcional_edit` action flows
- Replace `bootbox`/`onclick` handlers (`añadir_familia`, `eliminar_familia`, `eliminar_articulo`) with `Alpine.data()` components registered from a **nonce'd** classic script (with the `alpine:init` guard), driving `hx-post`/`hx-delete` (CSRF via inherited `hx-headers` from `htmx.boot()`) or hx-confirm.
- Re-init `#buscar_articulo` autocomplete on `htmx:after:swap` (idempotent).
- **Tests**: contract test asserting `Alpine.data(`, `alpine:init`, `csp_nonce_attr()`, `hx-post` + CSRF, absence of `bootbox`/inline `onclick="` for the migrated actions, and only colon-style htmx events.

### WU-4 — htmx 4 + Alpine for `tarif_opcional_precios`
- Convert the GET selector (lines 49-64) to `hx-get` with URL push; convert the save form to `hx-post` and swap the panel/row fragment; keep `guardar_precio_tarifa` + CSRF + `tarif.toggle_button_group` (locked).
- **Tests**: contract test for the hx attributes, colon events, and locked strings.

### WU-5 — htmx 4 + Alpine for `tarif_opcionales` list
- Convert the `b_codtarifa`/`b_codfamilia`/`query` filters to `hx-get` + `hx-push-url` (tarifario toolbar pattern); optionally migrate row toggles to `hx-post` row fragments while preserving the locked `action=toggle_*`, hidden filters, and CSRF.
- **Tests**: contract test for the filters and the migrated row actions; assert locked names/CSRF preserved.

### WU-6 (optional, only if requested) — opcionales JS module extraction
- Move new JS into `View/js/opcionales/` as ES modules (mirroring `View/js/familias/`), with a contract test that the module is loaded via a nonce'd script and contains no v2 htmx event names.

**Explicitly out of scope**: `tarif_configurador_opcionales`, `ventas_opcionales`, `tpvmod`, the master model, and the `tarif_opcional_precio` adapter.

---

## 7. Open questions / decisions to resolve during spec/design

1. Should the selector in WU-1 **replace** the bulk matrix or **complement** it (selected panel + all-tarifas read-only overview)? Recommended: complement (keeps information density, avoids retiring a locked controller path).
2. Should WU-2 introduce a dedicated fragment endpoint (e.g. `action=htmx_precios_tarifa`) or use full-page `hx-select="body"`? Recommended: start with `hx-select="body"`; add a fragment endpoint only if the UX requires it.
3. For WU-5, keep the existing POST+redirect row toggles (test-locked) or convert to `hx-post` row fragments? Recommended: convert only if the locked form contract can be preserved; otherwise leave the toggles as-is and migrate the filters only.

---

## 8. Evidence summary (files inspected)

- `plugins/catalogo_core/controller/tarif_opcionales.php`
- `plugins/catalogo_core/controller/tarif_opcional_edit.php`
- `plugins/catalogo_core/controller/tarif_opcional_precios.php`
- `plugins/catalogo_core/View/tarif_opcionales.html.twig`
- `plugins/catalogo_core/View/tarif_opcional_edit.html.twig`
- `plugins/catalogo_core/View/tarif_opcional_precios.html.twig`
- `plugins/catalogo_core/View/tarif_familias.html.twig`
- `plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php`
- `plugins/catalogo_core/extras/fbase_controller.php`
- `plugins/catalogo_core/model/tarif_tarifa_opcional.php`
- `plugins/catalogo_core/model/tarif_opcional.php`
- `plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php`
- `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php`
- `plugins/catalogo_core/tests/TarifOpcionalPreciosControllerTest.php`
- `plugins/catalogo_core/tests/TarifFamiliasControllerContractTest.php`
- `plugins/catalogo_core/tests/TarifFamiliasFragmentTest.php`
- `plugins/catalogo_core/tests/TarifConfiguradorOpcionalesTest.php`
- `plugins/catalogo_core/phpunit.xml`
- `src/Controller/HtmxCrudController.php`
- `src/Security/SecurityHeaders.php`
- `src/Core/Html.php`
- `themes/AdminLTE/view/Macro/Htmx.html.twig`
- `themes/AdminLTE/view/Macro/Alpine.html.twig`
- `themes/AdminLTE/view/Macro/HtmxCrud.html.twig`
- `plugins/tarifario/View/partials/catalogo/toolbar.html.twig`
- `plugins/tarifario/View/js/catalogo/catalogo-main.js`
- `plugins/tarifario/tests/Controller/TarifCatalogoHtmxContractTest.php`
- `plugins/tarifario/controller/tarif_catalogo_view.php`
- `view/js/htmx-crud.js`
- `openspec/changes/archive/2026-09-05-htmx4-core-adoption/specs/tarifario-catalog-htmx-pilot/spec.md`
