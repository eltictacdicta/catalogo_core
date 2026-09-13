# Design: Tarifa selector + htmx 4 / Alpine CSP for opcionales

- **Change**: `opcionales-tarifa-selector-htmx4`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Spec**: `specs/opcionales-tarifa-selector/spec.md` (OTS-01..OTS-10)
- **Baseline suite**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → 455 tests / 1381 assertions OK
- **Status**: design ratified (ready for tasks)

---

## 1. Technical Approach

The change is **UI + selector plus htmx/Alpine adoption** on top of an unchanged
per-tarifa state model. No model, schema, adapter, configurator, `tpvmod` or
`ventas_opcionales` file is touched (OTS-10).

Three coordinated moves:

1. **Resolve the missing selector in `tarif_opcional_edit`.** The controller
   currently carries a raw, unvalidated `$this->codtarifa` (from the
   `TarifarioOpcionalStateTrait`) and never exposes a tarifa object. This
   design adds a controller-local `resolver_tarifa_seleccionada()` that
   validates the requested code against `$this->tarifas`, falls back to the
   default then the first active tarifa, and exposes `$tarifa_seleccionada`.
   The shared trait stays untouched because it is used by the out-of-scope
   `tarif_configurador_opcionales` (OTS-10); validation is therefore
   controller-local.
2. **Drive that selector with htmx 4 full-page swaps** (`hx-get` on the page
   itself + `hx-target="body"` / `hx-select="body"` / `hx-swap="outerHTML"` +
   `hx-push-url="true"`), mirroring the proven `tarifario` catalog toolbar.
   No new fragment endpoint and no `HtmxCrudController` (R1). The
   "Precios por tarifa" tab renders **one scoped editable panel** for the
   selected tarifa (reusing `guardar_precio_tarifa` + `tarif.toggle_button_group`)
   plus a compact read-only all-tarifas overview.
3. **Migrate the edit view's interactive flows to Alpine CSP** (confirm
   add/remove familia, confirm remove artículo, article search, save
   feedback) registered from a single nonce'd classic script behind the
   `alpine:init` guard. All migrated mutations move to `hx-post` (never
   `hx-delete`, which would bypass `requireCsrf()` — see AD-6).

The secondary views only gain htmx 4 filters/selector; their test-locked
forms, names and CSRF fields stay byte-identical.

---

## 2. Architecture Decisions

| AD | Decision | Alternatives | Rationale |
|----|----------|--------------|-----------|
| **AD-1** | **Selector update = full-page htmx swap**, no dedicated fragment endpoint. `<select name="codtarifa">` carries `hx-get="{{ fsc.opcional.url() }}"`, `hx-trigger="change"`, `hx-target="body"`, `hx-select="body"`, `hx-swap="outerHTML"`, `hx-push-url="true"`, `hx-boost="true"`. | (a) `action=htmx_precios_tarifa` fragment endpoint with `template=false` + echo fragment, like `tarif_catalogo_view`; (b) plain `onchange="this.form.submit()"`. | OTS-04 **literally mandates** `hx-target`/`hx-select` on the body, so the full-page swap is the spec-intended shape. It reuses the already-proven, already-tested toolbar pattern (`TarifCatalogoHtmxContractTest::test_toolbar_filters_use_hx_get_with_url_push`) and adds **zero** controller surface, so it keeps `extends fbase_controller` trivially. A fragment endpoint (a) would contradict OTS-04 and add a second rendering path + `isHtmxRequest()` branching; (b) is explicitly forbidden by OTS-04. Cost: a full page render per change — acceptable for a single-opcional admin edit page. |
| **AD-2** | **JS organization = one inline nonce'd classic script in `View/tarif_opcional_edit.html.twig`**, no `View/js/opcionales/` module. Load order: classic `<script type="text/javascript" {{ csp_nonce_attr() }}>` during parse, then `{{ alpine.boot() }}` (deferred). No `defer` on the inline script (invalid for inline); no `type="module"`. | (a) new `View/js/opcionales/index.js` ES module mirroring `View/js/familias/`; (b) keep jQuery + bootbox. | OTS-06 **literally requires** "a nonce'd inline script behind the `alpine:init` guard", and `View/tarif_familias.html.twig:318-438` is the canonical in-repo implementation. A module (a) adds a file, a second load mechanism and an ordering contract for no benefit at this logic size; (b) violates OTS-06. The script is parse-time so it is guaranteed to run before the deferred Alpine asset. |
| **AD-3** | **`codtarifa` validation is controller-local to `tarif_opcional_edit`.** New `private function resolver_tarifa_seleccionada(): void` called right after `init_tarifario_opcional_state()`. Resolution order: requested code **if it is a member of `$this->tarifas`** → `$this->tarifa_defecto` **if active** → `$this->tarifas[0]` → `false`. It sets `$this->tarifa_seleccionada` and normalizes `$this->codtarifa` to the resolved code. New `public $tarifa_seleccionada;` property. | (a) validate inside `TarifarioOpcionalStateTrait` (shared by all four controllers); (b) reuse `tarif_tarifa::get()` like `tarif_opcional_precios` does. | (a) would change `tarif_configurador_opcionales` behaviour indirectly → violates OTS-10; the change must be controller-local. (b) queries the DB and can return an **inactive** tarifa absent from `$this->tarifas`, breaking the selector/panel consistency required by OTS-01/OTS-03. Membership against the already-loaded active list is the exact OTS-03 contract and is DB-free. |
| **AD-4** | **Alpine CSP component shape**: register `opcionalConfirmAction`, `opcionalArticuloSearch`, `opcionalSaveFeedback` via `Alpine.data(...)`; every directive is a simple property read, `x-for` iteration or scalar method call (no object literals / no inline logic). Guard with `if (window.Alpine) register(); else document.addEventListener('alpine:init', register);` plus a `window.__opcionalesAlpineRegistered` single-install marker. | (a) regular Alpine build; (b) inline `x-on` expressions with rich logic. | The repo ships the **CSP build** (`view/js/alpine-csp.min.js`) with no `unsafe-eval`; (b) fails silently (R5). (a) would violate the framework CSP. All behaviour therefore lives in plain JS methods; see §6.2 for the exact skeletons, mirroring `tarif_familias.html.twig:318-438`. |
| **AD-5** | **Body-swap safety posture**: the edit (and the two secondary views that use body swaps) call `{{ htmx.boot({'allowScriptTags': false}) }}`. htmx 4 re-executes scripts in swapped content, so the incoming body's boot + registration scripts are scrubbed before insertion. Idempotent re-init is a single guarded `document.addEventListener('htmx:after:swap', ...)` (marker `window.__opcionalesSwapBound`) that calls `Alpine.initTree(target)`; Alpine's own init is idempotent. | (a) bare `{{ htmx.boot() }}` (the toolbar pilot's form); (b) `hx-select` a narrower main region instead of body. | (a) risks double-loading htmx/Alpine and double-registering `Alpine.data` on every swap (R11); the macro explicitly documents the `allowScriptTags:false` scrubber as the posture for "views that swap in server fragments which may carry scripts", which is exactly a self body swap. (b) contradicts OTS-04's body target/select. |
| **AD-6** | **Mutation security**: every migrated mutation (add/remove familia, add/remove artículo) uses **`hx-post`** routed through a new `guard_mutating_action()` (POST + `requireCsrf()`). `hx-delete` is forbidden. | (a) keep the current GET mutations; (b) use `hx-delete`. | `fs_controller::requireCsrf()` returns `true` for any non-POST method, so (b) would be a CSRF bypass. `hx-post` rides the inherited `X-CSRF-TOKEN` set by `htmx.boot()` and validated by `pre_private_core()`. (a) leaves pre-existing CSRF holes on flows we are rewriting anyway. |
| **AD-7** | **Per-view scope**: `tarif_opcional_edit` is the primary surface (selector, scoped panel, Alpine flows). `tarif_opcional_precios` migrates only its selector to `hx-get` + URL push (save stays a normal POST). `tarif_opcionales` migrates only its filters to `hx-get` + URL push; its locked POST toggles and bootbox delete are untouched. | (a) migrate all three views' mutating flows; (b) selector only. | OTS-06 scopes Alpine flows to `tarif_opcional_edit`; OTS-08 scopes list/precios to **filters**. (a) expands blast radius into locked POST toggle contracts (R12); (b) under-delivers OTS-08. |
| **AD-8** | **Article search = Alpine component against the existing JSON endpoint**. `opcionalArticuloSearch` `fetch()`es the unchanged `tarif_buscar_articulo` GET endpoint and renders suggestions with `x-text`; selection issues `htmx.ajax('POST', postUrl, {values:{add_articulo: ref}, target:'body', swap:'outerHTML', select:'body'})`. No new endpoint; the endpoint stays read-only GET. | (a) keep jQuery UI autocomplete + idempotent re-init; (b) new htmx search fragment endpoint. | OTS-06 mandates the article search migrate to Alpine CSP; (a) contradicts it; (b) adds surface for a search that already returns JSON. `x-text` guarantees escaping of suggestion text (XSS). |

**AD count: 8 (AD-1..AD-8).**

---

## 3. Data Flow

```
GET/htmx-GET index.php?page=tarif_opcional_edit&id=N&codtarifa=T2
  │
  ├─ fs_controller::pre_private_core()  (CSRF validated from X-CSRF-TOKEN on POST)
  ├─ private_core()
  │    ├─ init_tarifario_opcional_state()   → $tarifas (all_activas), $tarifa_defecto, raw $codtarifa
  │    ├─ resolver_tarifa_seleccionada()    → validate vs $tarifas → $tarifa_seleccionada + normalized $codtarifa
  │    ├─ load opcional + process actions
  │    │     ├─ POST guarded mutations (add/remove familia, add/remove artículo)
  │    │     ├─ guardar_precio_tarifa() / guardar_precios_tarifas() (unchanged)
  │    │     └─ tarif_buscar_articulo() → JSON (GET, unchanged)
  │    └─ load_precios_tarifas()            → per-tarifa state from master effective()
  │
  └─ render tarif_opcional_edit.html.twig
        ├─ htmx.boot({allowScriptTags:false})   (CSRF bootstrap + htmx 4 + swap scrubber)
        ├─ tarifa <select name="codtarifa" hx-get=self …>   (full-page swap)
        ├─ scoped form (guardar_precio_tarifa + csrf_field + tarif.toggle_button_group) for $tarifa_seleccionada
        ├─ compact read-only overview over fsc.precios_tarifas
        ├─ Alpine components + nonce'd classic registration script
        └─ alpine.boot() (deferred)

User changes selector
  └─ htmx GET self (codtarifa rides on the triggering select)
       └─ server renders full page for the new tarifa
            └─ hx-select="body" extracts body → scrubber strips incoming scripts
                 → hx-swap="outerHTML" replaces <body> → hx-push-url updates URL
                      → htmx:after:swap → guarded Alpine.initTree(target) re-binds Alpine

User confirms a destructive action (Alpine)
  └─ confirm() → htmx.ajax('POST', url, {values, target:'body', swap:'outerHTML', select:'body'})
       └─ POST carries inherited X-CSRF-TOKEN → guard_mutating_action() → flash message in swapped body
```

---

## 4. File Changes

| Path | Action | Description |
|------|--------|-------------|
| `plugins/catalogo_core/controller/tarif_opcional_edit.php` | Modified | Add `public $tarifa_seleccionada;`; add `private resolver_tarifa_seleccionada(): void` called from `private_core()`; add `guard_mutating_action()`; route `add_familia`/`remove_familia`/`add_articulo`/`remove_articulo` through `$_POST` + POST/CSRF guard. Bulk `guardar_precios_tarifas`, `guardar_precio_tarifa`, `load_precios_tarifas`, accessors and the master seam unchanged. |
| `plugins/catalogo_core/View/tarif_opcional_edit.html.twig` | Modified | Import + boot `Macro/Htmx.html.twig` (scrubbed) and `Macro/Alpine.html.twig`; add `[x-cloak]`; keep `Macro/TarifarioComponents` import; add the tarifa selector; replace the bulk per-tarifa forms with the scoped panel + read-only overview; replace bootbox/onclick/jQuery-autocomplete with the Alpine components; add the nonce'd classic registration script and the guarded `htmx:after:swap` re-init; keep all locked strings. |
| `plugins/catalogo_core/View/tarif_opcional_precios.html.twig` | Modified | Convert the tarifa `<select>` (lines 58) from `onchange="this.form.submit()"` to `hx-get` + `hx-trigger="change"` + body target/select/swap + `hx-push-url`; keep the save form, `guardar_precio_tarifa`, `{{ csrf_field() }}` and `tarif.toggle_button_group(` byte-identical. |
| `plugins/catalogo_core/View/tarif_opcionales.html.twig` | Modified | Convert `query` / `b_codfamilia` / `b_codtarifa` / `b_solo_activos` filters to `hx-get` + URL push; preserve `name="query"`, `name="b_codfamilia"`, `name="b_codtarifa"`, `name="b_solo_activos"`, `name="offset"`, `action=toggle_activa`, `{{ csrf_field() }}` and the POST toggle forms. |
| `plugins/catalogo_core/tests/TarifOpcionalEditTarifaSelectorTest.php` | New | Behaviour + source contract for OTS-01..OTS-03, OTS-05 (selector resolution, fallbacks, scoped panel/locked strings). |
| `plugins/catalogo_core/tests/TarifOpcionalesHtmxContractTest.php` | New | htmx 4 / Alpine CSP / colon-events / locked-name contracts for OTS-04, OTS-06, OTS-07, OTS-08. |

No new JS file, no new partial, no model/XML/config change. All paths stay under `plugins/catalogo_core/` (plugin-local SDD; core `openspec/` untouched).

---

## 5. Interfaces / Contracts

### 5.1 Controller (`tarif_opcional_edit`)

```php
public $tarifa_seleccionada;                 // tarif_tarifa|false, exposed to Twig as fsc.tarifa_seleccionada

private function resolver_tarifa_seleccionada(): void
// called in private_core() immediately after init_tarifario_opcional_state()

private function guard_mutating_action(): bool
// POST && requireCsrf(); mirrors tarif_opcionales::guard_mutating_action()

private function add_familia(): void      // reads $_POST['add_familia'], $_POST['propagate']
private function remove_familia(): void   // reads $_POST['remove_familia'], $_POST['propagate'], checks allow_delete
private function add_articulo(): void     // reads $_POST['add_articulo']
private function remove_articulo(): void  // reads $_POST['remove_articulo'], checks allow_delete
```

Unchanged (test-locked): `guardar_precios_tarifas()`, `guardar_precio_tarifa()`,
`load_precios_tarifas()`, `opcional_master_state()`, `get_precio_tarifa()`,
`opcional_activo_en_tarifa()`, `opcional_en_catalogo_tarifa()`,
`opcional_en_tarifa_flag()`, `save_etiquetas_familia()`,
`tarif_buscar_articulo()` (GET JSON). Class still `extends fbase_controller`
and still `use TarifarioOpcionalStateTrait;`.

Resolution reference:

```php
private function resolver_tarifa_seleccionada(): void
{
    $selected = null;

    if ($this->codtarifa !== '') {
        foreach ($this->tarifas as $tarifa) {
            if ($tarifa->codtarifa === $this->codtarifa) { $selected = $tarifa; break; }
        }
    }
    if ($selected === null && $this->tarifa_defecto) {
        foreach ($this->tarifas as $tarifa) {
            if ($tarifa->codtarifa === $this->tarifa_defecto->codtarifa) { $selected = $tarifa; break; }
        }
    }
    if ($selected === null && count($this->tarifas) > 0) {
        $selected = $this->tarifas[0];
    }

    $this->tarifa_seleccionada = $selected;
    if ($selected !== null) {
        $this->codtarifa = $selected->codtarifa;
    }
}
```

### 5.2 htmx attribute contracts

Selector (`tarif_opcional_edit` and `tarif_opcional_precios`):

```twig
<select name="codtarifa"
        hx-get="{{ fsc.url() }}&id={{ fsc.opcional.id }}"   {# precios; edit uses fsc.opcional.url() #}
        hx-trigger="change"
        hx-target="body"
        hx-select="body"
        hx-swap="outerHTML"
        hx-push-url="true"
        hx-boost="true">
```

Mutations (edit view): Alpine `confirm()` uses
`htmx.ajax('POST', url, {values: {...}, target: 'body', swap: 'outerHTML', select: 'body'})`.
`hx-delete` MUST NOT appear. The scoped save form carries `hx-post`, `hx-target="body"`,
`hx-select="body"`, `hx-swap="outerHTML"`, plus the locked
`guardar_precio_tarifa` hidden input and `{{ csrf_field() }}`.

### 5.3 Alpine components (exact skeletons)

```js
(function () {
    if (window.__opcionalesAlpineRegistered === true) { return; }

    function registerOpcionalesAlpineComponents() {
        if (window.__opcionalesAlpineRegistered === true) { return; }
        window.__opcionalesAlpineRegistered = true;

        Alpine.data('opcionalConfirmAction', function () {
            return {
                open: false,
                title: '',
                message: '',
                url: '',
                values: {},
                init: function () { this.url = this.$el.dataset.postUrl || ''; },
                openModal: function (title, message, values) {
                    this.title = title;
                    this.message = message;
                    this.values = values;
                    this.open = true;
                    document.body.classList.add('modal-open');
                },
                askAddFamilia: function (cod, propagate) {
                    this.openModal('Añadir familia', '¿Añadir la familia "' + cod + '" a este opcional?',
                        { add_familia: cod, propagate: propagate });
                },
                askRemoveFamilia: function (cod, nombre, propagate) {
                    this.openModal('Eliminar familia', '¿Eliminar la familia "' + nombre + '" de este opcional?',
                        { remove_familia: cod, propagate: propagate });
                },
                askRemoveArticulo: function (ref) {
                    this.openModal('Eliminar artículo', '¿Eliminar el artículo "' + ref + '" de este opcional?',
                        { remove_articulo: ref });
                },
                close: function () {
                    this.open = false;
                    document.body.classList.remove('modal-open');
                },
                confirm: function () {
                    if (window.htmx && window.htmx.ajax) {
                        window.htmx.ajax('POST', this.url, {
                            values: this.values,
                            target: 'body',
                            swap: 'outerHTML',
                            select: 'body'
                        });
                    }
                    this.close();
                }
            };
        });

        Alpine.data('opcionalArticuloSearch', function () {
            return {
                query: '',
                results: [],
                open: false,
                loading: false,
                lookupUrl: '',
                postUrl: '',
                init: function () {
                    this.lookupUrl = this.$el.dataset.lookupUrl || '';
                    this.postUrl = this.$el.dataset.postUrl || '';
                },
                search: function () {
                    if (this.query.length < 2) { this.results = []; this.open = false; return; }
                    var self = this;
                    this.loading = true;
                    fetch(this.lookupUrl + '&buscar_articulo=' + encodeURIComponent(this.query))
                        .then(function (r) { return r.json(); })
                        .then(function (data) { self.results = data.suggestions || []; self.open = self.results.length > 0; })
                        .finally(function () { self.loading = false; });
                },
                select: function (referencia) {
                    this.open = false;
                    this.query = '';
                    this.results = [];
                    if (window.htmx && window.htmx.ajax) {
                        window.htmx.ajax('POST', this.postUrl, {
                            values: { add_articulo: referencia },
                            target: 'body',
                            swap: 'outerHTML',
                            select: 'body'
                        });
                    }
                },
                close: function () { this.open = false; }
            };
        });

        Alpine.data('opcionalSaveFeedback', function () {
            return {
                saving: false,
                init: function () {
                    this._onDone = this.markIdle.bind(this);
                    document.addEventListener('htmx:after:request', this._onDone);
                },
                destroy: function () {
                    document.removeEventListener('htmx:after:request', this._onDone);
                },
                markSaving: function () { this.saving = true; },
                markIdle: function () { this.saving = false; }
            };
        });
    }

    if (window.Alpine) {
        registerOpcionalesAlpineComponents();
    } else {
        document.addEventListener('alpine:init', registerOpcionalesAlpineComponents);
    }
})();

// Single-install swap re-init (AD-5): htmx 4 re-executes swapped body scripts,
// so this listener and the Alpine.data registrations above are guarded by
// window-level markers.
(function () {
    if (window.__opcionalesSwapBound === true) { return; }
    window.__opcionalesSwapBound = true;

    document.addEventListener('htmx:after:swap', function (evt) {
        if (!window.Alpine || typeof window.Alpine.initTree !== 'function') { return; }
        var ctx = evt && evt.detail ? evt.detail.ctx : null;
        var target = ctx && ctx.target ? ctx.target : document.body;
        window.Alpine.initTree(target);
    });
})();
```

Directive inventory (all CSP-safe: property read / iteration / scalar method call):

| Component | Directives |
|-----------|------------|
| `opcionalConfirmAction` | `x-data="opcionalConfirmAction"`, `x-cloak`, `x-show="open"`, `x-text="title"`, `x-text="message"`, `x-on:click="askAddFamilia('X','true')"`, `x-on:click="askRemoveFamilia('X','Name','false')"`, `x-on:click="askRemoveArticulo('REF')"`, `x-on:click="close()"`, `x-on:click="confirm()"` |
| `opcionalArticuloSearch` | `x-data="opcionalArticuloSearch"`, `x-model="query"`, `x-on:input.debounce.300ms="search()"`, `x-show="open"`, `x-for="item in results"`, `x-text="item.value"`, `x-on:click="select(item.referencia)"` |
| `opcionalSaveFeedback` | `x-data="opcionalSaveFeedback"`, `x-on:submit="markSaving()"`, `:disabled="saving"`, `x-show="saving"` |

Scoped data attributes: the confirm wrapper carries
`data-post-url="{{ fsc.opcional.url() }}&codtarifa={{ fsc.codtarifa|url_encode }}"`;
the search wrapper carries `data-lookup-url` (same URL) and `data-post-url`.

### 5.4 Locked strings (must stay byte-identical)

Preserved verbatim in the migrated views:

- All three views: `{% import 'Macro/TarifarioComponents.html.twig' as tarif %}` and `tarif.toggle_button_group(`.
- `tarif_opcionales.html.twig`: `name="query"`, `name="b_codfamilia"`, `name="b_codtarifa"`, `name="b_solo_activos"`, `name="offset"`, `action=toggle_activa`, `{{ csrf_field() }}`.
- `tarif_opcional_edit.html.twig`: `guardar_precio_tarifa`, `{{ csrf_field() }}`.
- `tarif_opcional_precios.html.twig`: `guardar_precio_tarifa`, `{{ csrf_field() }}`.
- Controllers: class names, `extends fbase_controller`, `use TarifarioOpcionalStateTrait;`, no `tarif_controller` reference, no `plugins/tarifario/model` reference, master `effective(`/`set_*` reads, bulk `guardar_precios_tarifas` body.

---

## 6. Testing Strategy

Strict TDD; runner `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`.
New tests are auto-discovered by the plugin suite (`<directory>tests</directory>`,
`processIsolation="true"`). Baseline 455 tests / 1381 assertions must stay green
plus the new tests.

| Layer | Test file | Test method(s) | Spec refs | Fixture / pattern |
|-------|-----------|----------------|-----------|-------------------|
| Unit (behaviour, DB-free) | `tests/TarifOpcionalEditTarifaSelectorTest.php` | `test_selector_resolution_prefers_requested_active_tarifa` | OTS-01, OTS-03 | Anonymous `extends \tarif_opcional_edit` skipping the constructor; set `$codtarifa`, `$tarifas`, `$tarifa_defecto`; invoke `resolver_tarifa_seleccionada()` via Reflection (same seam style as `TarifOpcionalesControllerMasterStateTest`). |
| Unit (behaviour, DB-free) | `tests/TarifOpcionalEditTarifaSelectorTest.php` | `test_unknown_codtarifa_falls_back_to_default`, `test_absent_codtarifa_falls_back_to_default`, `test_unknown_codtarifa_without_default_falls_back_to_first_active`, `test_no_active_tarifas_yields_false_selection` | OTS-03 | Same anonymous subclass; assert `$tarifa_seleccionada->codtarifa` and that `$codtarifa` is normalized. |
| Contract (source) | `tests/TarifOpcionalEditTarifaSelectorTest.php` | `test_edit_view_exposes_selector_listing_tarifas_with_default_marked`, `test_edit_view_selector_has_no_full_page_submit` | OTS-01, OTS-04 | `file_get_contents` + `assertStringContainsString` / `assertStringNotContainsString` (repo source-contract style). |
| Contract (source) | `tests/TarifOpcionalEditTarifaSelectorTest.php` | `test_edit_view_scoped_panel_preserves_locked_save_contract`, `test_edit_view_retains_all_tarifas_overview` | OTS-02, OTS-05 | Assert `guardar_precio_tarifa`, `{{ csrf_field() }}`, `tarif.toggle_button_group(`, and an all-tarifas loop over `fsc.precios_tarifas`. |
| Contract (source) | `tests/TarifOpcionalesHtmxContractTest.php` | `test_edit_view_boots_htmx_and_alpine_macros` | OTS-06, OTS-07 | Assert both macro imports, `{{ htmx.boot(` and `{{ alpine.boot() }}`. |
| Contract (source) | `tests/TarifOpcionalesHtmxContractTest.php` | `test_edit_view_uses_colon_events_and_no_v2_names` | OTS-07 | Assert `'htmx:after:swap'` and `'htmx:after:request'`; assert absence of `htmx:afterSwap` / `htmx:afterRequest` (mirrors `TarifCatalogoHtmxContractTest:326-337`). |
| Contract (source) | `tests/TarifOpcionalesHtmxContractTest.php` | `test_edit_view_registers_alpine_data_with_nonce_and_init_guard` | OTS-06 | Assert `Alpine.data(`, `alpine:init`, `{{ csp_nonce_attr() }}`, `__opcionalesAlpineRegistered`. |
| Contract (source) | `tests/TarifOpcionalesHtmxContractTest.php` | `test_edit_view_migrates_confirm_and_search_off_bootbox`, `test_edit_view_mutations_use_hx_post_and_never_hx_delete`, `test_edit_view_autocomplete_reinit_is_idempotent_on_after_swap` | OTS-06 | Assert no `bootbox`/`onclick="`, no `hx-delete`, `htmx.ajax('POST'`, `__opcionalesSwapBound` + `Alpine.initTree(`. |
| Contract (source) | `tests/TarifOpcionalesHtmxContractTest.php` | `test_precios_selector_uses_hx_get_with_url_push`, `test_list_filters_use_hx_get_with_url_push` | OTS-08 | Assert `hx-get`, `hx-trigger="change"`, `hx-target="body"`, `hx-select="body"`, `hx-swap="outerHTML"`, `hx-push-url="true"`; assert the old `onchange="this.form.submit()"` is gone from the migrated controls. |
| Contract (source) | `tests/TarifOpcionalesHtmxContractTest.php` | `test_list_and_precios_views_preserve_locked_names_csrf_and_toggles` | OTS-08, OTS-09 | Assert `name="codtarifa"`/`name="b_codtarifa"`, `name="query"`, `name="b_codfamilia"`, `name="b_solo_activos"`, `name="offset"`, `{{ csrf_field() }}`, `action=toggle_`; `guardar_precio_tarifa`; `tarif.toggle_button_group(`. |
| Contract (source) | `tests/TarifOpcionalesHtmxContractTest.php` | `test_edit_controller_rejects_unknown_tarifa_and_exposes_selection`, `test_edit_controller_keeps_fbase_controller_and_bulk_save` | OTS-01, OTS-09 | Assert `resolver_tarifa_seleccionada(`, `tarifa_seleccionada`, `extends fbase_controller`, `guardar_precios_tarifas(`, `requireCsrf()`, no `HtmxCrudController`. |

Regression gate: `TarifOpcionalesControllerContractTest`, `TarifOpcionalesControllerMasterStateTest`,
`TarifOpcionalPreciosControllerTest`, `TarifConfiguradorOpcionalesTest`,
`TarifarioOpcionalStateTraitTest` must all stay green unmodified.

OTS-10 (untouched surfaces) is verified at the **verify** phase by a grep/git-diff
audit plus `ddev exec composer phpstan`, not by a new unit test (a source test can
only assert absence of references, which is weaker than the diff).

---

## 7. Threat Matrix

| Threat | Boundary | Severity | Mitigation | Verification |
|--------|----------|----------|------------|--------------|
| CSRF on migrated mutations (add/remove familia, add/remove artículo) | Untrusted browser → controller | High | `hx-post` only + `guard_mutating_action()` (POST + `requireCsrf()`), riding the inherited `X-CSRF-TOKEN` from `htmx.boot()`. `hx-delete` forbidden because `requireCsrf()` is a no-op for non-POST. | Contract test asserts no `hx-delete`, `htmx.ajax('POST'`, and `requireCsrf()` in the controller. |
| CSRF on per-tarifa save | Untrusted browser → controller | High | Existing `guardar_precio_tarifa()` POST + `requireCsrf()` preserved; form keeps `{{ csrf_field() }}` and now also posts via htmx with the inherited header. | Existing `TarifOpcionalesControllerMasterStateTest:557-570` + new locked-string assertions. |
| CSRF on list row toggles | Untrusted browser → controller | High | Unchanged locked POST forms (`action=toggle_*` + `{{ csrf_field() }}`); no conversion to `hx-delete`. | `TarifOpcionalesControllerMasterStateTest:511-570` unchanged. |
| XSS via dynamic values rendered into Alpine calls / `hx-vals` | Article/familia data → DOM | High | Dynamic values are passed as **scalar method arguments** with `|e('js')` (existing convention) and consumed by `htmx.ajax` values, never concatenated into an `x-*` expression or an `hx-vals` JSON literal. Search suggestions render through `x-text` (escaped), never `x-html`. | Contract test asserts no `x-html` and no dynamic `hx-vals`; source audit. |
| XSS via the swapped body | Server → DOM | Medium | Response is same-origin Twig with autoescape; `htmx.boot({'allowScriptTags': false})` scrubs `script`/`object`/`embed`/`iframe` and `on*`/`javascript:` from incoming fragments before insertion. Alpine directives (`x-on:`) survive; legacy `on*` handlers do not (and are removed by this change anyway). | AD-5 contract + macro behavior. |
| CSRF token exposure | `htmx.boot()` inline bootstrap | Low | The token is written into `hx-headers:inherited` on `document.documentElement` (existing macro contract); the new Alpine script never reads or echoes it. | `Macro/Htmx.html.twig` unchanged; no token in new scripts. |
| Insecure GET mutation left behind | Controller | Medium | All four edit-view mutations move off GET; `tarif_buscar_articulo` stays GET because it is a read-only JSON lookup. | Contract test asserts `$_GET['add_familia']`-style dispatch removed. |
| Open redirect | n/a (no new redirects) | Low | All generated URLs are internal `index.php?page=...`; no user-controlled redirect target introduced. | Source audit. |

No row is N/A: the change has a real mutation/CSRF boundary and a DOM/escaping boundary.

---

## 8. Migration / Rollout

- **Additive, no DB migration.** Views gain htmx/Alpine; the controller adds a
  resolver and POST guards. The master model, XML schemas, `tarif_opcional_precio`
  adapter and out-of-scope controllers are untouched (OTS-10).
- **Twig cache:** after deploying (and after `git revert` during rollback) clear
  the template cache so the edited views recompile.
- **Rollback:** `git revert` the controller/view/test edits; the bulk
  `guardar_precios_tarifas` path and the master model are intact, so the legacy
  full-page flow remains functional. Clear the Twig cache after revert.
- **Sequencing (work units):** WU-1 selector + resolution + scoped panel
  (OTS-01..OTS-03, OTS-05, OTS-09) → WU-2 htmx boot + selector-driven swap
  (OTS-04) → WU-3 Alpine flows + idempotent re-init (OTS-06) → WU-4 precios
  selector (OTS-08) → WU-5 list filters (OTS-08). Each WU ends with the full
  plugin suite green.

---

## 9. Risks (addressed)

| # | Risk | Design response |
|---|------|-----------------|
| R1 | Reaching for `HtmxCrudController` | AD-1 keeps `extends fbase_controller`; OTS-09 contract test asserts no `HtmxCrudController`. |
| R2 | Locked view strings change | §5.4 enumerates every locked string; contract tests assert them; `TarifOpcionalesControllerMasterStateTest` runs after every WU. |
| R3 | Bulk `guardar_precios_tarifas` removed | Method and body untouched; only the view stops calling it; master-state test keeps asserting its body. |
| R4 | htmx v2 event names | AD-5/OTS-07: only `htmx:after:swap` / `htmx:after:request`; contract test forbids v2 names. |
| R5 | Alpine CSP silent failures | AD-4: all logic in `Alpine.data()`; directives are scalar calls; `[x-cloak]` added. |
| R6 | Unknown `codtarifa` | AD-3 resolver with explicit fallback chain + behaviour tests for unknown/absent/no-default/no-tarifas. |
| R7 | Full reload vs fragment | AD-1 ratifies the body swap per OTS-04; AD-5 scrubs scripts so the full relayout is safe. A fragment endpoint remains a future change if UX demands it. |
| R8 | jQuery autocomplete on swap | AD-8 removes jQuery UI autocomplete; the guarded `htmx:after:swap` listener calls `Alpine.initTree(target)` (idempotent); contract test asserts the single-install marker. |
| R10 | Missing nonce | AD-2: the new inline script carries `{{ csp_nonce_attr() }}`; contract test asserts it. |
| R11 | Cross-cutting JS double-binding | AD-5: `window.__opcionalesAlpineRegistered` + `window.__opcionalesSwapBound` single-install markers; `Alpine.initTree` is idempotent; components clean up document listeners in `destroy()`. |
| R12 | List POST toggles break | AD-7: list toggles are left as POST forms with the locked names/CSRF; only filters migrate. |

---

## 10. Open Questions

1. **`hx-trigger="keydown[key === 'Enter']"` under no-`unsafe-eval` CSP.** The
   change mirrors the tarifario pilot for the list text filter. If htmx 4
   evaluates the trigger filter with `Function`, it could be CSP-blocked even
   though the pilot ships it. Fallback if observed at smoke time: switch to a
   plain submit button (`hx-get` + `hx-include`) with no filter expression.
2. **Alpine re-init after a body `outerHTML` swap.** The design relies on
   `Alpine.initTree(target)` in the guarded `htmx:after:swap` listener. The
   exact event detail shape (`evt.detail.ctx.target`) follows the pilot's
   `catalogo-main.js`; if htmx 4 changes it, fall back to `document.body`.
3. **Should the precios save form become `hx-post`?** OTS-08 only mandates the
   selector/filters. This design keeps it a normal POST to minimize risk; it can
   be converted in a later change once the edit-view pattern is proven in
   production.
4. **`htmx.boot({'allowScriptTags': false})` on the secondary views.** Only the
   edit view strictly needs it (Alpine). Applying it to `tarif_opcionales` and
   `tarif_opcional_precios` keeps the posture uniform; verify at smoke time that
   their preserved jQuery/Bootstrap scripts still behave after a swap.
