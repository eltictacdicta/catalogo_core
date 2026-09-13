# Design: catalogo_core owns the article Tarifas tab and per-tarifa price/state (WU-1)

## Technical Approach

WU-1 is the first slice of the multi-WU absorption program. It is an **atomic
multi-repo move** mirroring `absorber-opcionales-tarifa-en-catalogo-core`: one
coordinated work unit = a catalogo_core commit (byte-faithful copy + explicit
`git add`) followed by a tarifario commit (`git rm` + require repoints), no
intermediate deploy (AD-1). `tarif_articulo_precio` keeps its FQCN
(`FSFramework\model\tarif_articulo_precio`) and table name
(`tarif_articulo_precios`), both already hardcoded by catalogo_core
(`model/tarif_tarifa.php:207`, `controller/tarif_configurador_opcionales.php:63`).
The endpoint relocates to `plugins/catalogo_core/controller/tarif_tab_precios.php`,
reclasses `tarif_controller` → `fbase_controller`, and keeps its class
basename/`page` slug so the frozen host markup never changes (AD-2). The save
gate moves to catalogo_core's neutral `ArticlePermissionFilterEvent` (AD-3);
the history write becomes `class_exists`-soft until WU-6 moves the model (AD-4).
Hook ownership transfers to catalogo_core behind its existing static guard
(AD-5), and the tab is rewritten to htmx 4 + Alpine CSP mirroring the opcional
twin (AD-6). The canonical `ventas_articulo.html.twig` is not edited (AD-7).
Strict TDD; both plugin suites plus the root Plugins suite green after the slice.

## WU Program Context (each is its own delivery)

| WU | One-line slice | Depends on |
|---|---|---|
| WU-1 (this) | Article Tarifas tab + `tarif_articulo_precio` ownership; htmx 4 + Alpine | — |
| WU-2 | `ventas_articulo` detail absorbs `tarif_articulo_edit`; retire it + `tarif_articulo_precios` | WU-1 |
| WU-3 | `ventas_articulos` list absorbs `tarif_articulos` filters/columns/quick-create | WU-1 |
| WU-4 | Move `tarif_articulo`, `tarif_articulos_ext`, `tarif_tarifa_articulo`, `…_etiqueta`; delete `tarif_descripcion` | WU-2/3 |
| WU-5 | Catalog manager ownership (`tarif_catalogo*`, views, partials, JS) → catalogo_core page | WU-4 |
| WU-6 | Images, revision notes, article price history (`tarif_precio_historial`) | WU-5 |
| WU-7 | Rich Excel wizard + JSON import unification; repoint SSE | WU-5/6 |
| WU-8 | Retirement audit, RBAC boundary confirmation, verify pass | all |

## Architecture Decisions

| # | Decision | Choice | Alternatives rejected | Rationale |
|---|----------|--------|----------------------|-----------|
| AD-1 | Move mechanics | One atomic WU = **two coordinated commits**: verbatim copy + explicit-path `git add` in `catalogo_core`, then `git rm` + repoint in `tarifario`. FQCN and `table_name` unchanged. Deployed back-to-back with no intermediate state. | Cross-repo `git mv` (impossible — independent repos); separate PRs (broken runtime window); rename table (breaks `tarif_tarifa.php:207`, `tarif_configurador_opcionales.php:63`) | Same mechanics as the familias/opcionales precedents; parent repo tracks no plugin bytes. |
| AD-2 | Endpoint relocation | Move file → `plugins/catalogo_core/controller/tarif_tab_precios.php`; `extends tarif_controller` → `extends fbase_controller`; keep class basename + `page=tarif_tab_precios`; drop `plugins/tarifario/extras/tarif_controller.php` + `plugins/tarifario/model/tarif_articulo_precio.php` requires; add catalogo_core requires; `use TarifarioOpcionalStateTrait` for `$tarifas`/`$codtarifa`/`parse_price_input()`. | `PageController` (loses `simbolo_divisa()`/`requireCsrf()`); rename slug (breaks frozen host markup + adds `fs_page` churn); new `tarif_articulo_tab` class (extra diff, no value) | Mirrors `tarif_opcional_tab` exactly; slug+class stay together (legacy dispatch resolves `page` → `{page}.php`); endpoint is not a menu page (`FALSE,FALSE`), so no `fs_page`. |
| AD-3 | Permission gate | `protected function puede_editar_articulo(string $referencia, string $codtarifa): bool` — admin short-circuit, else dispatch `new ArticlePermissionFilterEvent($referencia, ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE, $nick, $codtarifa)` and return `isAllowed()`. Reuse the existing `ACTION_EDIT_ARTICLE` constant (no new constant). | Soft `class_exists('tarif_grupo_usuario')` gestor-only (loses editor-assignment semantics, duplicates role logic); direct `tarif_grupo_*` calls (catalogo_core → tarifario coupling) | Spec ATT-02/ATT-07 mandate the neutral default-allow mechanism; tarifario's `ArticlePermissionListener` (staying, §3.4) enforces RBAC transitively when active. |
| AD-4 | History coupling | `save()` history write guarded by `class_exists('FSFramework\\model\\tarif_precio_historial')` before `registrar_cambio()`. | Move `tarif_precio_historial` in WU-1 (scope creep, WU-6 owns it); drop history (feature loss); new event (over-engineering) | Preserves current behavior when tarifario is active; standalone catalogo_core `save()` no longer fatals; no `plugins/tarifario/` path in catalogo_core (ATT-07). |
| AD-5 | Hook ownership | Add `ARTICULO_HOOK_TEMPLATES` const to `catalogo_core/Init.php`; `registerHooks()` iterates both maps under the existing single `$hooksRegistered` guard. `@catalogo_core` namespace already maps `View/` (no loader change). `tarifario/Init.php` deletes `HOOK_TEMPLATES`, `$hooksRegistered`, `registerHooks()`, the `TwigInitEvent` listener+import and the `ViewHookRegistry` import; keeps `TwigLoaderEvent`/`@tarifario` for remaining views. | One merged const (rewrites the opcional precedent); separate guard (duplicate state) | Minimal diff, grep-auditable 1:1 map, ATT-03 idempotency preserved; tarifario reaches zero article hooks. |
| AD-6 | htmx 4 + Alpine tab | Rewrite the 4 moved templates mirroring the opcional twins: pane `htmx.boot({'allowScriptTags': false})`+`alpine.boot()`, `[x-cloak]`, `x-data="articuloTabPrecios"`; header `hx-get`/`hx-target="#tab_tarifario_precios_rows"`/`hx-swap="outerHTML"` (lazy-load preserved on tab click, now via htmx instead of FSAjaxLoader `data-ajax-url`); rows `hx-post="index.php?page=tarif_tab_precios"`+`hx-include="closest tr"`+`hx-swap="none"`; nonce'd `Alpine.data()` behind `alpine:init`; colon events only; `x-text` only. | Keep jQuery/`FSAjaxLoader.securePost`/bootbox (fails ATT-05) | Spec ATT-05; tested pattern from `ventas_opcional_tab_pane_after.html.twig` + `CatalogoOpcionalesHookOwnershipTest`. |
| AD-7 | Canonical detail rendering | `View/ventas_articulo.html.twig` is **not edited**: the two frozen markers (lines 129/341) stay; the tab appears only through the catalogo_core-registered hooks. | Move/copy the host view (breaks `CatalogoCoreHookMarkersTest` frozen positions) | Delta `catalogo-render-hooks` freezes names + positions; `render_hook` is already `is_safe:['html']`. |
| AD-8 | Standalone bootstrap | New `Init::ensureArticuloTarifaTables()` (public static): `require_once` the moved model from `plugins/catalogo_core/model/`, instantiate behind `is_file` + `class_exists(..., false)` + `is_subclass_of(..., fs_model)` guards, after `ensureFamiliasTarifaTables()`; called from `init()` + `upgrade()`. | No bootstrap (ATT-01 scenario fails on a standalone install) | Mirrors `ensureFamiliasTarifaTables()`/`ensureOpcionalesTarifaTables()`; `tarifario_init.php`'s class_exists instantiation stays as an idempotent no-op. |
| AD-9 | Test ownership | Move `TarifTabPreciosTest` → catalogo_core (`Tests\CatalogoCore`, catalogo paths, `extends fbase_controller` assertion, permission seam signature, `@catalogo_core/...` template key). New `ArticuloTarifaPrecioOwnershipTest` (ATT-01/ATT-07) and `Integration/CatalogoArticuloHookOwnershipTest` (ATT-03/04/05). Flip tarifario `HookRegistrationTest` to zero article hooks. Keep `CatalogoCoreHookMarkersTest` green. | Keep tests in tarifario (plugin-local ownership rule) | Spec ATT-06; house convention "tests travel with their code". |
| AD-10 | Rollback | Single revert of the two coordinated commits in reverse order (tarifario then catalogo_core), then clear the Twig cache. Tables/data/`fs_page` untouched in WU-1 (no retirements). | Per-file manual restore | Restores the tarifario-owned tab by construction; no down-migration exists. |
| AD-11 | No half-moved state | Per-WU atomic moves: a class file/XML/endpoint/hook template never exists in both plugins. Both plugin suites + root Plugins suite run after the slice. | Big-bang move of all WUs | R2/R12 mitigation from the proposal. |

## Data Flow

    ventas_articulo.html.twig (frozen markers, untouched)
       │  render_hook('ventas_articulo_tabs_after' / '..._tab_pane_after')
       ▼
    ViewHookRegistry  ──(catalogo_core/Init.php registers @catalogo_core/Hooks/*)──▶ fragment render
       │
       ├─ header: hx-get page=tarif_tab_precios&action=rows&tipo=articulo&ref=REF
       │             ▼
       │        tarif_tab_precios::render_rows_action()  (template=FALSE)
       │             └─ tarif_articulo_precio::get(ref, codtarifa) per active tarifa
       │                  └─ Html::render('@catalogo_core/Hooks/partials/articulo_precios_rows.html.twig')
       │
       └─ rows: hx-post page=tarif_tab_precios (+ hx-include closest tr, X-CSRF-TOKEN header)
                     ▼
                tarif_tab_precios::guardar_precio_tab()
                  1. requireCsrf()                    ── fail ─▶ {ok:false,message}
                  2. puede_editar_articulo(ref,codtarifa) ── deny ─▶ {ok:false,message}
                     └─ ArticlePermissionFilterEvent(ACTION_EDIT_ARTICLE) ─▶ [tarifario listener when active]
                  3. tarif_articulo_precio get/save/delete ─▶ {ok:true,message,html:re-rendered rows}

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `plugins/catalogo_core/model/tarif_articulo_precio.php` | Create | Verbatim move (FQCN + `table_name` unchanged); single authorized delta = soft history guard (AD-4) |
| `plugins/catalogo_core/model/table/tarif_articulo_precios.xml` | Create | Verbatim move of the schema (PK `(referencia, codtarifa)`) |
| `plugins/catalogo_core/controller/tarif_tab_precios.php` | Create | Moved endpoint on `fbase_controller` + `TarifarioOpcionalStateTrait`; neutral permission gate (AD-2/AD-3) |
| `plugins/catalogo_core/View/Hooks/ventas_articulo_tabs_after.html.twig` | Create | Moved + rewritten: `hx-get` lazy tab header (AD-6) |
| `plugins/catalogo_core/View/Hooks/ventas_articulo_tab_pane_after.html.twig` | Create | Moved + rewritten: Alpine pane shell + boots (AD-6) |
| `plugins/catalogo_core/View/Hooks/partials/articulo_precios_rows.html.twig` | Create | Moved + rewritten: rows with `hx-post`; per-row `fsc.simbolo_divisa(tarifa.coddivisa)` |
| `plugins/catalogo_core/View/Hooks/partials/tab_save_script.html.twig` | Create | Moved + rewritten: nonce'd `Alpine.data('articuloTabPrecios')` + colon-event handler (AD-6) |
| `plugins/catalogo_core/Init.php` | Modify | Add `ARTICULO_HOOK_TEMPLATES`; iterate both maps in `registerHooks()`; add `ensureArticuloTarifaTables()` called from `init()`/`upgrade()` (AD-5/AD-8) |
| `plugins/catalogo_core/tests/TarifTabPreciosTest.php` | Create | Moved endpoint test, repointed (AD-9) |
| `plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php` | Create | ATT-01 model ownership + ATT-07 grep gate |
| `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` | Create | ATT-03/04/05 hook ownership + htmx/Alpine hygiene |
| `plugins/tarifario/model/tarif_articulo_precio.php` | Delete | `git rm` (AD-1) |
| `plugins/tarifario/model/table/tarif_articulo_precios.xml` | Delete | `git rm` (AD-1) |
| `plugins/tarifario/controller/tarif_tab_precios.php` | Delete | Relocated endpoint (AD-2) |
| `plugins/tarifario/View/Hooks/ventas_articulo_tabs_after.html.twig` | Delete | Moved out (dir removed) |
| `plugins/tarifario/View/Hooks/ventas_articulo_tab_pane_after.html.twig` | Delete | Moved out |
| `plugins/tarifario/View/Hooks/partials/articulo_precios_rows.html.twig` | Delete | Moved out |
| `plugins/tarifario/View/Hooks/partials/tab_save_script.html.twig` | Delete | Moved out |
| `plugins/tarifario/Init.php` | Modify | Delete `HOOK_TEMPLATES`, `$hooksRegistered`, `registerHooks()`, `TwigInitEvent` listener/import, `ViewHookRegistry` import (AD-5) |
| `plugins/tarifario/controller/tarif_articulos.php` | Modify | `:24` require → `plugins/catalogo_core/model/tarif_articulo_precio.php` |
| `plugins/tarifario/controller/tarif_catalogo_view.php` | Modify | `:26` require → catalogo_core path |
| `plugins/tarifario/controller/tarif_actualizar_precios.php` | Modify | `:23` require → catalogo_core path |
| `plugins/tarifario/controller/tarif_articulo_precios.php` | Modify | `:22` require → catalogo_core path |
| `plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php` | Modify | `:63` require → catalogo_core path |
| `plugins/tarifario/tests/Model/TarifArticuloFactoryForImportTest.php` | Modify | `:47` require → catalogo_core path |
| `plugins/tarifario/tests/Controller/TarifArticulosFamiliaImportTest.php` | Modify | `:58` require → catalogo_core path |
| `plugins/tarifario/tests/Integration/HookRegistrationTest.php` | Modify | Flip to zero article hooks; delete host-injection/template-on-disk methods (AD-9) |

Not repointed (no explicit require — class autoloads from active catalogo_core):
`Services/ExcelRowUpdater.php`, `Services/ExcelImportWizardService.php`,
`extras/tarifario_init.php:134`, `process_excel_wizard.php:385`,
`model/tarif_articulo.php`, `model/tarif_tarifa_articulo.php`,
`controller/tarif_tarifas.php`. `extras/tarifario_init.php:241` lists the stable
table name (idempotent no-op, left as-is).

## Interfaces / Contracts

**Endpoint** (`plugins/catalogo_core/controller/tarif_tab_precios.php`):

```php
class tarif_tab_precios extends fbase_controller   // was tarif_controller
{
    use TarifarioOpcionalStateTrait;               // $this->tarifas, $this->codtarifa
    // GET  action=rows&tipo=articulo&ref=<referencia> -> text/html rows fragment (template = FALSE)
    // POST guardar_precio_tab -> JSON {ok, message, html}
    protected function precio_model(): tarif_articulo_precio { return new tarif_articulo_precio(); }
    protected function puede_editar_articulo(string $referencia, string $codtarifa): bool
    {
        if (!empty($this->user->admin)) { return TRUE; }
        $event = new ArticlePermissionFilterEvent(
            $referencia, ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE,
            (string) ($this->user->nick ?? ''), $codtarifa
        );
        FSEventDispatcher::getInstance()->dispatch($event, ArticlePermissionFilterEvent::NAME);
        return $event->isAllowed();
    }
}
```

Order is contractual: `requireCsrf()` → `puede_editar_articulo()` → model `get()/save()/delete()`.
`render_rows_fragment()` renders `@catalogo_core/Hooks/partials/articulo_precios_rows.html.twig`.

**Hook registration** (`catalogo_core/Init.php`):

```php
private const ARTICULO_HOOK_TEMPLATES = [
    'ventas_articulo_tabs_after'     => '@catalogo_core/Hooks/ventas_articulo_tabs_after.html.twig',
    'ventas_articulo_tab_pane_after' => '@catalogo_core/Hooks/ventas_articulo_tab_pane_after.html.twig',
];
// registerHooks(): foreach OPCIONAL_HOOK_TEMPLATES + ARTICULO_HOOK_TEMPLATES under $hooksRegistered
```

**Alpine components**: `articuloTabPrecios` (pane state `message`/`messageClass`/`notify(ok,text)`),
registered once via `Alpine.data()` in the nonce'd script behind `alpine:init`. Window flags
`__tarifarioArticuloTabRegistered` / `__tarifarioArticuloTabRequestBound` / `__tarifarioArticuloTabSwapBound`
keep htmx re-execution idempotent. Tab header **keeps the lazy-load contract** (rows load on tab click),
but the trigger is htmx `hx-get` instead of `FSAjaxLoader`'s `data-ajax-url`.

## Testing Strategy

| Layer | What | Approach (maps to) |
|---|---|---|
| Unit | Model resolves locally, `table_name` stable, XML present, no dual class, zero `plugins/tarifario/` refs | `tests/ArticuloTarifaPrecioOwnershipTest.php` (ATT-01/ATT-07, grep gate) |
| Unit/contract | Rows fragment (per-row `coddivisa`), CSRF-before-model, deny → save unreachable, save/delete persistence + F2 re-render | moved `tests/TarifTabPreciosTest.php` (ATT-02/ATT-04/ATT-05) |
| Integration | Article pair registered once across rebuilds; renders header+pane at frozen markers without tarifario; unsaved = no tab; htmx colon events, `hx-post`, `Alpine.data()` + nonce + `[x-cloak]`, no `bootbox`/`|raw`/v2 names | `tests/Integration/CatalogoArticuloHookOwnershipTest.php` (ATT-03/ATT-04/ATT-05) |
| Integration | tarifario registers zero article+opcional hooks; RBAC listener + `tarif_grupo_articulo` stay live | `plugins/tarifario/tests/Integration/HookRegistrationTest.php` (ATT-03/ATT-06/ATT-07) |
| Integration | Four frozen names/positions + empty-registry zero bytes + unescaped fragment + throw swallow remain unchanged | `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` (**KEEP green**, render-hooks delta) |
| E2E | Dev-DB smoke: saved article injects the tab; save persists flags; blank price deletes; standalone catalogo_core (tarifario inactive) creates `tarif_articulo_precios` | `sdd-verify` smoke checklist |

Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (baseline **528 tests / 1826 assertions**) and
`ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml`, plus the root Plugins suite.

## Threat Matrix

| Boundary | Applicability | Expected safe behavior | Failure behavior | RED test |
|---|---|---|---|---|
| CSRF (POST endpoint) | Applicable | `requireCsrf()` runs before any guard/model access; invalid token ⇒ `{ok:false}`, no write | Token bypass ⇒ unauthorized persistence | `TarifTabPreciosTest::test_csrf_failure_rejects_before_model_access` |
| XSS (swapped fragments/JS) | Applicable | Twig autoescape; no `\|raw`; Alpine `x-text` only; `allowScriptTags:false`; `hx-post` only | Script injection into swapped rows | `CatalogoArticuloHookOwnershipTest` hygiene + `CatalogoCoreHookMarkersTest` unescaped-fragment contract |
| Hook ownership injection | Applicable | catalogo_core registers the article pair once; tarifario zero; frozen markers unchanged; throwing template swallowed+logged | Duplicate/missing/foreign tab injection | `CatalogoArticuloHookOwnershipTest`, tarifario `HookRegistrationTest` |
| Authorization (permission gate) | Applicable | neutral event default-allow (zero listeners); tarifario listener deny ⇒ save unreachable, stored values unchanged | Privilege escalation on per-tarifa prices | `TarifTabPreciosTest` deny scenarios |
| SQL injection (new queries) | Applicable | No new raw SQL; model uses `var2str()`/parameterized queries | — | ownership test grep + existing model coverage |
| Dynamic page resolution | Applicable | `page=tarif_tab_precios` still resolves to the moved file; endpoint not a menu page (no `fs_page` change) | 404 / dead menu row | `CatalogoArticuloHookOwnershipTest` (slug + `extends fbase_controller`) |
| Shell / subprocess / VCS-PR automation / executable classification | N/A | No shell, subprocess, PR automation or executable-file classification introduced | — | — |

## Migration / Rollout

No data migration, no table rename, no schema or API change, no `fs_page`
retirement in WU-1 (`tarif_tab_precios` is `FALSE,FALSE` and stays). Rollout is
the two-commit atomic move (AD-1): catalogo_core commit first, tarifario commit
immediately after, no deploy between. `catalogo_core/Init::ensureArticuloTarifaTables()`
creates the table idempotently on standalone installs; `fs_model` only creates
when missing. Plugin `fsframework.ini` version bumps are deferred to the
`fsframework-plugin-release` skill at release time. **Rollback (AD-10):** revert
the two commits in reverse order and clear the Twig cache; the tarifario-owned
tab is restored byte-for-byte and no data/`fs_page` rows were touched.
Post-rollout cache clear: `CacheManager::clearAll()` + `tmp/twig_cache`.

## Open Questions

- [ ] WU-7 Excel: **unify vs coexist** the tarifario rich 14/16-col wizard with the existing catalogo_core basic `ArticuloExcel*` stack (recommended default: move the rich stack under catalogo_core, keep both entry points, unify in a later refactor — not decided here; blocks WU-7 only).
- [ ] Trait naming debt: `TarifarioOpcionalStateTrait` is reused by the article endpoint; rename/generalize to a neutral per-tarifa state trait in a later WU (recommended default: WU-8 cleanup).
- [ ] `tarif_articulo_precio::url()` still points at `tarif_articulos` / `tarif_articulo_edit` (retired in WU-2/WU-3); repoint then (recommended default: WU-2).
- [ ] `View/Macro/TarifarioComponents.html.twig:271` links to `tarif_catalogo_view`; repoint in WU-5, not WU-1.
- [ ] AD-4's soft `class_exists` history guard becomes a hard reference when WU-6 moves `tarif_precio_historial` into catalogo_core.

---

# Design: WU-2 — canonical `ventas_articulo` detail absorbs the tarifario edit surface

> **Supersession note (re-slice).** The program table above assigns
> `tarif_tarifa_articulo` / `tarif_tarifa_articulo_etiqueta` to **WU-4** and
> article images to **WU-6**. WU-2 fulfils ART-01/ART-07, which forbid the
> canonical detail from referencing `plugins/tarifario/` for the absorbed
> surface; therefore those three models move in **WU-2**. WU-4 shrinks to
> `tarif_articulo`, `tarif_articulos_ext` and the `tarif_descripcion` deletion;
> WU-6 shrinks to revision notes + `tarif_precio_historial`. Nothing else in the
> program changes.

## Technical Approach

WU-2 is the second slice of the multi-WU absorption program and the same
**atomic two-repo move** mechanics as WU-1 (AD-W2-10): one coordinated
delivery = a `catalogo_core` commit (verbatim model/XML copies + explicit
`git add` + controller/view absorption + tests) immediately followed by a
`tarifario` commit (`git rm` of the moved/deleted files + `require_once` and
link repoints), with **no intermediate deploy**. The canonical surface is
`Controller/VentasArticulo.php` (`extends PageController`) and
`View/ventas_articulo.html.twig`; the two duplicate edit pages
(`tarif_articulo_edit`, `tarif_articulo_precios`) are retired with no alias and
their `fs_page` rows removed idempotently. The 744-line legacy view (jQuery +
bootbox + `FSAjaxLoader`) is rewritten to htmx 4 + Alpine CSP while preserving
the five existing tabs and the **exact four frozen hook markers** that WU-1
depends on. Strict TDD; catalogo_core suite at or above **554 tests / 1972
assertions**, tarifario suite and root Plugins suite green after the slice.

The absorption is **feature-parity by construction**, not a rewrite: the moved
logic is a 1:1 port of `tarif_articulo_edit` (`modificar`,
`sync_tarifa_articulo_actual`, `ensure_tarifa_family_available`,
`save_etiquetas_articulo`, `load_imagenes`/`upload_imagen`/`delete_imagen`/
`destacar_imagen`) and of `tarif_articulo_precios` (which is **replaced** by the
already-shipped WU-1 `tarif_tab_precios` endpoint for price/state, and by the
existing canonical `#opcionales` flow for article-opcionales).

## Architecture Decisions

| # | Decision | Choice | Alternatives rejected | Rationale |
|---|----------|--------|----------------------|-----------|
| AD-W2-1 | Exact model moves (WU-2 vs WU-4) | **WU-2 moves now** (verbatim file + XML copy into `catalogo_core`, `git rm` in `tarifario`): `tarif_tarifa_articulo` + `table/tarif_tarifa_articulo.xml`, `tarif_tarifa_articulo_etiqueta` + `table/tarif_tarifa_articulo_etiqueta.xml`, `tarif_articulo_imagen` + `table/tarif_articulo_imagenes.xml`. **Stay for WU-4**: `tarif_articulo`, `tarif_articulos_ext` (+ its XML), `tarif_descripcion` deletion. FQCNs (`FSFramework\model\*`) and table names stay byte-stable. | Move `tarif_articulo`/`tarif_articulos_ext` too (scope creep; they serve the WU-3 list/import, not the detail); leave tags/images in WU-4/WU-6 (violates ART-01/ART-07: the controller would need `plugins/tarifario/` refs); move nothing and call `plugins/tarifario/` by path (forbidden direction) | ART-01 requires per-tarifa `tarif_tarifa_articulo` sync, etiquetas and an images entry point on the canonical detail; ART-07 forbids any `plugins/tarifario/` path in catalogo_core for absorbed surfaces. Re-slice is the only compliant split; no class file/XML may exist in both plugins (AD-W2-11). |
| AD-W2-2 | Canonical controller absorption | `VentasArticulo` gains **protected seam factories** (`articulo_model()`, `tarifa_model()`, `tarifa_articulo_model()`, `etiqueta_model()`, `imagen_model()`), a **protected** `puedeEditarArticulo(string $referencia, string $codtarifa): bool` (admin short-circuit, else `new ArticlePermissionFilterEvent($referencia, ACTION_EDIT_ARTICLE, nick, $codtarifa)` + `FSEventDispatcher::dispatch(..., NAME)` → `isAllowed()`), and the ported methods: `resolveCodtarifa()`, `syncTarifaArticulo(\articulo)`, `ensureTarifaFamilyAvailable(?string)`, `saveEtiquetasArticulo(\articulo)`, `loadImagenes/uploadImagen/deleteImagen/destacarImagen`, `getEtiquetasDisponiblesArticulo()`, `getEtiquetasSeleccionadasArticulo()` (public, view API), `respondHtmx()`. Public view state: `$codtarifa`, `$tarifas`, `$imagenes`, `$articulo_etiquetas_disponibles`, `$articulo_etiquetas_seleccionadas`, `$puede_editar`. Contractual save order: CSRF → permission → field assignment/rename → `articulo::save()` → multiidioma → `syncTarifaArticulo()` → `saveEtiquetasArticulo()`; a per-tarifa/etiqueta failure reports an error and **never discards** the successful article save. | Port `tarif_articulo`/`tarif_articulos_ext` in WU-2 (scope creep, WU-4 owns them); re-implement per-tarifa price rows in `VentasArticulo` (duplicates WU-1, ART-02 forbids); add new nonce'd `requireCsrf` mechanism (the controller already has `validateFormToken()`) | Mirrors the `TarifarioOpcionalStateTrait`/`precio_model()` seam pattern already proven in `TarifTabPreciosTest`; the neutral event is the exact mechanism used by WU-1 and the article quick-create gate. Protected factories keep the controller DB-free testable (anonymous subclass skips the constructor), consistent with house conventions. |
| AD-W2-3 | htmx 4 + Alpine view migration | Rewrite `View/ventas_articulo.html.twig` (744 L) with `{% import 'Macro/Htmx.html.twig' as htmx %}` + `{% import 'Macro/Alpine.html.twig' as alpine %}` and `{{ htmx.boot({'allowScriptTags': false}) }}` / `{{ alpine.boot() }}`, emitted **once by the host**. Components (all registered via a nonce'd classic script behind `alpine:init`, `Alpine.data()`, `[x-cloak]`, `x-text` only): `articuloDetalle` (message/notify + delete confirm), `articuloPrecios` (pvp/pvpi/margen recalc), `articuloTabs` (`x-init` hash→active tab), `articuloOpcionales` (add/remove/toggle confirm + notify), `articuloImagenes` (upload/delete/feature confirm + notify). Replaced legacy flows: `delete_articulo()` bootbox confirm → Alpine confirm + `hx-post`; global `cambiar_pvp/cambiar_pvpi/cambiar_margen/calcular_margen` + `oninput` → `articuloPrecios`; `$(document).ready` jQuery `.tab('show')` → `articuloTabs`; the 365-line `#opcionales` jQuery block (`securePost`, `$.ajax`, `applyOpcionalesHtml`, `reloadOpcionalesTab`, delegated handlers) → `hx-post` forms + `hx-target="#opcionales"`/`hx-swap="outerHTML"`; every inline `onclick=`/`onchange=` → `x-on:click`/`x-on:change` colon form. `View/Hooks/ventas_articulo_tab_pane_after.html.twig` **drops** its `htmx.boot()`/`alpine.boot()` (the host now owns boot; pane keeps `[x-cloak]`/`x-data`). | Keep jQuery/bootbox/`FSAjaxLoader` (fails ART-04); boot htmx in both host and pane (double asset + duplicate scrubber); move the tab pane boot into the host while leaving the pane boot (same duplicate); use `hx-delete` or raw `htmx.ajax('DELETE')` (mutations are `hx-post` only) | ART-04. The pane was WU-1's only boot owner because `ventas_articulo` had no htmx; once the host opts in, a single boot is the correct contract (`Macro/Htmx.html.twig`: "Import once per opting-in view"). `Macro/Alpine.html.twig` restricts inline expressions, so all logic lives in `Alpine.data()`. |
| AD-W2-4 | htmx mutation responses | `privateCore()` handles `upload_imagen`, `?delete_imagen=`, `?destacar_imagen=` and the opcional/image mutations. When `$request->headers->get('HX-Request')` is set, respond with the re-rendered `partials/articulos/tab_opcionales` fragment (or the `#imagenes` fragment), echoed raw with `Content-Type: text/html`, plus an `HX-Trigger`/message reflection; a `$this->htmxHandled` flag makes `run()` skip the full-page template. The existing `isAjaxRequest()` JSON branch (`respondAjax()`/`sendAjaxJson()`) is **retained** unchanged for non-htmx XHR. | Replace the JSON branch (breaks the preserved `#opcionales` AJAX contract without a spec mandate); return JSON to htmx and parse client-side (defeats htmx swap; ART-04 wants `hx-swap`) | `hx-target`/`hx-swap` need HTML, not JSON; keeping the JSON path preserves every existing partial contract and any plugin that overrides the view. |
| AD-W2-5 | Retirement + repoints | Delete with **no alias** `plugins/tarifario/controller/tarif_articulo_edit.php`, `plugins/tarifario/View/tarif_articulo_edit.html.twig`, `plugins/tarifario/controller/tarif_articulo_precios.php`, `plugins/tarifario/View/tarif_articulo_precios.html.twig`. Repoint: `tarif_articulo_precio::url()` → `index.php?page=ventas_articulo&ref=…` (null → `ventas_articulos`); moved `tarif_tarifa_articulo::url()` → `ventas_articulo&ref=…&codtarifa=…`; `tarif_articulo::url()` + `url_tarifario()` → `ventas_articulo&ref=…` (null → `ventas_articulos`); `tarif_actualizar_precios.html.twig:255`, `tarif_historial_precios.html.twig:188` → `ventas_articulo&ref=…`; `tarif_articulos.html.twig:357,373,392` → `ventas_articulo&ref=…&codtarifa=…`. | Redirect aliases (dead `fs_page` rows + broken menu, R9 precedent); leave model `url()` methods (they emit retired slugs, ART-06 scenario 2) | ART-06; every inbound link must preserve `ref`/`id` + `codtarifa`. |
| AD-W2-6 | `fs_page` retirement | Add idempotent `private static function retireTarifArticuloEditPage()` and `retireTarifArticuloPreciosPage()` to `catalogo_core/Init.php` (mirror of `retireTarifOpcionalPreciosPage()`: `fs_page::get('<slug>')` → `->delete()`), each wired in `upgrade()` inside its own `try/catch` with an `error_log` message. | A single loop over both slugs (less grep-auditable, diverges from the one-method-per-slug precedent); no retirement (orphan menu rows) | ART-06 scenario 3; the endpoints are `FALSE,FALSE` but `fs_page` rows are still written on first discovery, and `fs_user::get_menu()` does not filter dead pages. |
| AD-W2-7 | Images ownership | Move `tarif_articulo_imagen` + `tarif_articulo_imagenes.xml` into catalogo_core in WU-2 (not WU-6) and wire it into the standalone bootstrap. `IMAGES_DIR = 'imgs/tarifario/'` stays byte-stable (filesystem path, not a `plugins/tarifario/` reference, so it passes the ART-07 grep gate). | Keep images in tarifario for WU-6 (ART-01 scenario 3 fails on the canonical detail); rename the storage dir (data-at-rest break) | The canonical detail must list/upload/delete/feature images for the loaded article with no tarifario path. |
| AD-W2-8 | Standalone bootstrap | Add `Init::ensureArticuloDetalleTables()` (public static): requires the three moved `FS_FOLDER . '/plugins/catalogo_core/model/*.php'` files behind `is_file` + `class_exists($fqcn, false)` + `is_subclass_of($fqcn, \fs_model::class)` guards, instantiates them FK-safe after `ensureFamiliasTarifaTables()` (`tarif_tarifas` first, then `tarif_tarifa_articulo` → `tarif_tarifa_articulo_etiqueta` → `tarif_articulo_imagen`), wired in `init()` after `ensureArticuloTarifaTables()` and in `upgrade()`. **Does not modify the WU-1-frozen `ensureArticuloTarifaTables()` body.** | Add the models to the WU-1 method (mutates a frozen source contract pinned by `ArticuloTarifaPrecioOwnershipTest::test_catalogo_core_bootstraps_the_article_price_table_standalone`); no bootstrap (standalone installs fatal) | ATT-01-style standalone installs must create the moved tables; a new method keeps the WU-1 contract byte-stable. `tarifario_init.php`'s `class_exists` instantiations stay as idempotent no-ops (catalogo_core boots first as a dependency). |
| AD-W2-9 | Test ownership + locked strings | New `tests/ArticuloDetalleCanonicoOwnershipTest.php` (ART-06/07, grep gate, retirement, bootstrap) and `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` (ART-01..06/08, source + DB-free behavior via an anonymous `VentasArticulo` subclass that skips the constructor and stubs the protected seams). `VentasArticuloControllerTest` and `Integration/CatalogoCoreHookMarkersTest` are **not edited**. `Integration/CatalogoArticuloHookOwnershipTest` gets an additive update (host owns boot, pane no longer boots; host stub gains the new list keys). `CatalogoOpcionalesUnifiedControllerTest` drops the retired `tarif_articulo_precios.html.twig` from its repoint loop. Tarifario `Integration/HookRegistrationTest` stays green unchanged (RBAC boundary). | Keep new tests in tarifario (plugin-local ownership rule); instantiate the real `PageController` in tests (needs DB/kernel); edit the frozen tests to fit the rewrite (violates ART-05/ART-08) | Spec ART-08: locked contracts must migrate coherently and the suite must stay ≥ 554/1972. House convention: tests travel with their code, seams make behavior testable without a DB. |
| AD-W2-10 | Atomic landing + rollback | Two coordinated commits (`catalogo_core` first, `tarifario` immediately after, no deploy between), reversing WU-1's order because WU-2's catalogo_core side adds new files before tarifario deletes them. **Rollback boundary**: revert the two commits in reverse order (`tarifario` then `catalogo_core`) and clear the Twig cache; no data/table/`fs_page` change survives the revert except the two retired `fs_page` rows, which a revert recreates on next access. | Cross-repo `git mv` (independent repos); separate PRs (broken runtime window); per-file manual restore | Same mechanics as the familias/opcionales/WU-1 precedents; parent repo tracks no plugin bytes. |
| AD-W2-11 | No half-moved state | Every moved class file/XML exists in exactly one plugin; after the slice the catalogo_core production tree has zero `plugins/tarifario/` and `@tarifario/` references for absorbed surfaces (grep gate excludes `tests/`, `vendor/`, `openspec/`); both plugin suites + root Plugins suite run green. | Big-bang move of all WUs | R2/R12 mitigation; the ownership test is the machine check. |

## Interfaces / Contracts

**Canonical controller** (`plugins/catalogo_core/Controller/VentasArticulo.php`, `extends PageController`):

```php
// Seams (protected, overridable by the DB-free test subclass)
protected function articulo_model(): \articulo
protected function tarifa_model(): \FSFramework\model\tarif_tarifa
protected function tarifa_articulo_model(): \FSFramework\model\tarif_tarifa_articulo
protected function etiqueta_model(): \FSFramework\model\tarif_tarifa_articulo_etiqueta
protected function imagen_model(): \FSFramework\model\tarif_articulo_imagen
protected function puedeEditarArticulo(string $referencia, string $codtarifa): bool
    // admin short-circuit; else ArticlePermissionFilterEvent(ACTION_EDIT_ARTICLE, nick, codtarifa)
    // dispatched on ArticlePermissionFilterEvent::NAME → isAllowed() (default-allow, zero listeners)

// Absorption (ported 1:1 from tarif_articulo_edit)
protected function syncTarifaArticulo(\articulo $art): bool          // tarif_tarifa_articulo get()/save()/add_articulo_to_tarifa()
protected function ensureTarifaFamilyAvailable(?string $codfamilia): bool  // tarif_tarifa_familia + tarif_familia, recursive ancestors
protected function saveEtiquetasArticulo(\articulo $art): bool        // tarif_tarifa_articulo_etiqueta::replace_etiquetas_articulo()
protected function loadImagenes(\articulo $art): void
protected function uploadImagen(\articulo $art): void                // tarif_articulo_imagen::upload() + imgs/tarifario/ guards
protected function deleteImagen(\articulo $art): void
protected function destacarImagen(\articulo $art): void
public function getEtiquetasDisponiblesArticulo(): array             // tarif_tarifa_etiqueta_familia (already catalogo_core)
public function getEtiquetasSeleccionadasArticulo(): array
```

**Contractual mutation order** (ART-02): `validateFormToken()` → `puedeEditarArticulo()` → model factory / `save()` / `delete()`. Reads for display are exempt. `eliminarArticulo` becomes a POST mutation (`eliminar_articulo` field, CSRF-validated, `allow_delete` + `FS_DEMO` guards) driven by `hx-post`; the legacy `?delete=` GET branch is removed.

**`privateCore()` dispatch additions**: `upload_imagen` (POST), `query delete_imagen`, `query destacar_imagen`; etiquetas + per-tarifa flags ride the existing `sreferencia` save. Reference rename rides the same save through a distinct field (e.g. `snueva_referencia`); on `articulo::set_referencia()` failure the method returns with an error and **no article is renamed**.

**Schema stability**: moved tables keep their exact names — `tarif_tarifa_articulo` (PK `(codtarifa, referencia)`, FKs `tarif_tarifas`/`articulos`), `tarif_tarifa_articulo_etiqueta` (PK `(codtarifa, referencia, etiqueta)`), `tarif_articulo_imagenes` (PK `id`, FK `articulos`). catalogo_core already writes `tarif_tarifa_articulo` by raw SQL (`TarifFamiliasControllerContractTest`), so the move adds no SQL change.

**Hook contract**: `ventas_articulo_tabs_after` stays the last declaration before the `#tab_articulo` `</ul>`; `ventas_articulo_tab_pane_after` stays the last declaration before the `.tab-content` closing `</div>`; both keep `{{- render_hook('<name>', {'fsc': fsc, 'user': user, 'empresa': empresa, 'i18n': i18n}) -}}`. New tab `<li>`s and panes are inserted **before** the markers.

## File Changes

### `plugins/catalogo_core/` (commit 1)

| File | Action | Description |
|------|--------|-------------|
| `model/tarif_tarifa_articulo.php` | Create (moved) | Verbatim move; FQCN + table `tarif_tarifa_articulo` unchanged; `url()` repointed to `ventas_articulo&ref=…&codtarifa=…` (ART-06) |
| `model/table/tarif_tarifa_articulo.xml` | Create (moved) | Verbatim schema (PK `(codtarifa, referencia)`, FKs `tarif_tarifas`/`articulos`) |
| `model/tarif_tarifa_articulo_etiqueta.php` | Create (moved) | Verbatim move; table `tarif_tarifa_articulo_etiqueta` |
| `model/table/tarif_tarifa_articulo_etiqueta.xml` | Create (moved) | Verbatim schema (PK `(codtarifa, referencia, etiqueta)`) |
| `model/tarif_articulo_imagen.php` | Create (moved) | Verbatim move; `IMAGES_DIR = 'imgs/tarifario/'` and `install()` untouched |
| `model/table/tarif_articulo_imagenes.xml` | Create (moved) | Verbatim schema (PK `id`, FK `articulos`) |
| `Controller/VentasArticulo.php` | Modify | Absorb edit surface (AD-W2-2), htmx responses (AD-W2-4), CSRF/permission gate, POST delete |
| `View/ventas_articulo.html.twig` | Modify | htmx 4 + Alpine rewrite; tabs + 4 frozen markers preserved (AD-W2-3) |
| `View/partials/articulos/tab_opcionales.html.twig` | Modify | Add/remove/toggle controls become `hx-post` forms targeting `#opcionales` (no jQuery class-name wiring) |
| `View/Hooks/ventas_articulo_tab_pane_after.html.twig` | Modify | Drop `htmx.boot()`/`alpine.boot()` (host owns boot); keep `[x-cloak]`/`x-data` shell |
| `model/tarif_articulo_precio.php` | Modify | `url()` → `ventas_articulo` (null → `ventas_articulos`) |
| `Init.php` | Modify | Add `ensureArticuloDetalleTables()` + `retireTarifArticuloEditPage()` + `retireTarifArticuloPreciosPage()`; wire into `init()`/`upgrade()` (AD-W2-6/AD-W2-8) |
| `tests/ArticuloDetalleCanonicoOwnershipTest.php` | Create | ART-06/ART-07 ownership, retirement idempotency, zero-tarifario-paths, standalone bootstrap |
| `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | Create | ART-01..ART-06 + ART-08 contracts (source + DB-free behavior) |
| `tests/Integration/CatalogoArticuloHookOwnershipTest.php` | Modify | Additive: host owns htmx/Alpine boot; host fsc stub gains `imagenes`/`tarifas`/etiquetas keys |
| `tests/CatalogoOpcionalesUnifiedControllerTest.php` | Modify | Drop the retired `tarif_articulo_precios.html.twig` from the repoint loop |

### `plugins/tarifario/` (commit 2)

| File | Action | Description |
|------|--------|-------------|
| `model/tarif_tarifa_articulo.php` | Delete | `git rm` after the move (AD-W2-1) |
| `model/table/tarif_tarifa_articulo.xml` | Delete | `git rm` |
| `model/tarif_tarifa_articulo_etiqueta.php` | Delete | `git rm` |
| `model/table/tarif_tarifa_articulo_etiqueta.xml` | Delete | `git rm` |
| `model/tarif_articulo_imagen.php` | Delete | `git rm` |
| `model/table/tarif_articulo_imagenes.xml` | Delete | `git rm` |
| `controller/tarif_articulo_edit.php` | Delete | Retired, no alias (AD-W2-5) |
| `View/tarif_articulo_edit.html.twig` | Delete | Retired, no alias |
| `controller/tarif_articulo_precios.php` | Delete | Retired, no alias |
| `View/tarif_articulo_precios.html.twig` | Delete | Retired, no alias |
| `controller/tarif_tarifas.php` | Modify | `:22,:27` `require_once` → catalogo_core model paths |
| `controller/tarif_catalogo_view.php` | Modify | `:22,:33` model requires + `:2994,:3093` image requires → catalogo_core paths |
| `model/tarif_articulo.php` | Modify | `url()` + `url_tarifario()` → `ventas_articulo&ref=…` (stays in tarifario until WU-4) |
| `View/tarif_actualizar_precios.html.twig` | Modify | `:255` link → `ventas_articulo&ref=…` |
| `View/tarif_historial_precios.html.twig` | Modify | `:188` link → `ventas_articulo&ref=…` |
| `View/tarif_articulos.html.twig` | Modify | `:357,:373,:392` → `ventas_articulo&ref=…&codtarifa=…` |

**No edit required** (verified): `extras/tarifario_init.php` references the moved classes only through `class_exists` instantiation (idempotent no-op; catalogo_core boots first as a declared dependency) and the stable table names in its create list; `controller/tarif_articulos.php`, `model/tarif_grupo_articulo.php` and `controller/tarif_tarifas.php` touch the moved surface only via stable raw-SQL table names. `tarif_articulo.php` and `tarif_articulos_ext` remain in tarifario for WU-4.

## Locked contracts that must stay intact

`VentasArticuloControllerTest` (ART-08, **no edits**) requires, after the rewrite:
`Controller/VentasArticulo.php` exists; `privateCore` + `getPageData` methods; `'name' => 'ventas_articulo'`; the token `ref` and `->get(` present; `var2str` or `->get(`; `public array $idiomas`; `public array $articulo_opcionales`; `saveMultiidiomaDescriptions`; `addOpcionalArticulo`; wrapper `controller/ventas_articulo.php` extends it. View: exists; no `|raw`; `{{ csrf_field() }}`; `{{ include('header.html.twig') }}`; `{{ include('footer.html.twig') }}`; `fsc.articulo.referencia`; `fsc.articulo.descripcion`; `fsc.articulo.pvp`; `#multiidioma`; `#opcionales`; `partials/articulos/tab_multiidioma.html.twig`; `partials/articulos/tab_opcionales.html.twig`.

`CatalogoCoreHookMarkersTest` (**no edits**, ART-05): exactly the four frozen hook names across both host views; each marker uses whitespace control + the frozen context; `ventas_articulo_tabs_after` is the line directly before the `#tab_articulo` `</ul>`; `ventas_articulo_tab_pane_after` is the line directly before the `.tab-content` closing `</div>`; empty registry renders zero bytes.

`ArticuloTarifaPrecioOwnershipTest` (**no edits**): `ensureArticuloTarifaTables()` body and `init()`/`upgrade()` ordering stay byte-stable; catalogo_core production stays free of `plugins/tarifario/` and `@tarifario/`.

`HookRegistrationTest` (tarifario, **no edits**, ART-07 scenario 2): tarifario registers zero article/opcional hooks; `ArticlePermissionListener` + `tarif_grupo_articulo` stay in tarifario and the listener stays live on `ArticlePermissionFilterEvent::NAME`.

## Testing Strategy

| ART | Layer | What is proven | Test file / method |
|---|---|---|---|
| ART-01 | Unit/source+behavior | Rename via `articulo::set_referencia()` with invalid/duplicate rejection and no rename; `codfamilia`/flags; per-tarifa `tarif_tarifa_articulo` sync for the active `codtarifa`; etiquetas replace per article/family; etiqueta failure reports an error without discarding the article save; images list/upload/delete/feature own the loaded article | `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` — `test_reference_change_uses_the_article_reference_setter_and_rejects_invalid`, `test_familia_and_flags_are_assigned_on_save`, `test_per_tarifa_sync_uses_tarif_tarifa_articulo_for_the_active_tarifa`, `test_etiquetas_persist_through_tarif_tarifa_articulo_etiqueta`, `test_etiqueta_failure_does_not_discard_the_article_save`, `test_images_entry_point_is_owned_by_the_canonical_detail` |
| ART-02 | Contract | Price/state editing reuses the WU-1 `page=tarif_tab_precios` endpoint (no duplicated row/save logic); mutations validate CSRF before any model access and gate through `puedeEditarArticulo()`; deny ⇒ no factory/`save()` and unchanged state | `VentasArticuloArticleEditAbsorptionTest` — `test_price_editing_reuses_the_wu1_tab_endpoint`, `test_mutations_gate_through_the_neutral_permission_event`, `test_csrf_failure_runs_no_model_factory_or_save`; WU-1 `tests/TarifTabPreciosTest.php` **KEEP** proves the endpoint persistence paths |
| ART-03 | Contract | `articulo_opcionales` and `addOpcionalArticulo` preserved; add/remove reuse `catalogo_articulo_opcional` + the `#opcionales` tab | `VentasArticuloArticleEditAbsorptionTest` — `test_article_opcional_contract_is_preserved` |
| ART-04 | Source hygiene | Only colon event names and `hx-post`; `Alpine.data(` behind `alpine:init`; `csp_nonce_attr()`; `[x-cloak]`; `htmx.boot({'allowScriptTags': false})` + `alpine.boot()`; no `bootbox`, no `|raw`, no htmx v2 names | `VentasArticuloArticleEditAbsorptionTest` — `test_view_uses_htmx4_and_alpine_hygiene`, `test_view_has_no_legacy_jquery_or_bootbox_flows` |
| ART-05 | Integration | Four frozen marker names/positions + existing tabs survive the rewrite; WU-1 tab still injects at the frozen markers | `tests/Integration/CatalogoCoreHookMarkersTest.php` **KEEP green (no edits)** + `VentasArticuloArticleEditAbsorptionTest::test_view_preserves_tabs_and_frozen_markers` |
| ART-06 | Unit/source | No controller/view file for either retired slug in either plugin; every inbound link/`url()` repoints to `ventas_articulo` preserving `ref`/`id` + `codtarifa`; `tarif_articulo_precio::url()` emits no retired slug; `fs_page` rows retire idempotently | `tests/ArticuloDetalleCanonicoOwnershipTest.php` — `test_retired_edit_surfaces_leave_no_alias`, `test_retired_fs_page_rows_are_deleted_idempotently`, `test_retired_slugs_have_zero_inbound_links`; `VentasArticuloArticleEditAbsorptionTest` — `test_inbound_links_repoint_to_the_canonical_detail` (grep gate) |
| ART-07 | Unit/integration | Zero `plugins/tarifario/`/`@tarifario/` refs in catalogo_core production for the absorbed surface; no class file in both plugins; RBAC boundary stays in tarifario | `ArticuloDetalleCanonicoOwnershipTest` — `test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_detail_surface`, `test_moved_models_live_only_in_catalogo_core`; tarifario `tests/Integration/HookRegistrationTest.php` **KEEP green (no edits)** |
| ART-08 | Suite gate | `VentasArticuloControllerTest` green with no edits; absorbed behaviors covered by the new tests; no tarifario test asserts a retired slug; catalogo_core ≥ **554/1972** | `plugins/catalogo_core/tests/VentasArticuloControllerTest.php` **KEEP green (no edits)**; `CatalogoOpcionalesUnifiedControllerTest` updated; both plugin suites + root Plugins suite |

Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (baseline **554 tests / 1972 assertions**), `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml`, and the root Plugins suite.

## Threat Matrix

| Boundary | Applicability | Expected safe behavior | Failure behavior | RED test |
|---|---|---|---|---|
| CSRF (canonical POST mutations) | Applicable | `validateFormToken()` runs before any model factory/`save()`/`delete()`; invalid token ⇒ error, no write | Token bypass ⇒ unauthorized article/image/etiqueta persistence | `VentasArticuloArticleEditAbsorptionTest::test_csrf_failure_runs_no_model_factory_or_save` |
| Authorization (article edit gate) | Applicable | neutral `ArticlePermissionFilterEvent` default-allow (zero listeners); admin short-circuit; tarifario listener deny ⇒ save/image/etiqueta unreachable, stored values unchanged | Privilege escalation on article/etiqueta writes | `VentasArticuloArticleEditAbsorptionTest::test_mutations_gate_through_the_neutral_permission_event`; tarifario `HookRegistrationTest` (boundary) |
| XSS (htmx swaps + rewritten view) | Applicable | Twig autoescape; no `|raw`; Alpine `x-text` only; `allowScriptTags:false` scrubber; `hx-post` only; escaped names in the delete confirm | Script injection into swapped `#opcionales`/`#imagenes` fragments | `VentasArticuloArticleEditAbsorptionTest` hygiene + `CatalogoCoreHookMarkersTest` unescaped-fragment contract |
| Hook ownership injection | Applicable | exactly four frozen names/positions; catalogo_core registers the article pair once; tarifario zero; throwing hook swallowed+logged | Duplicate/missing/foreign tab injection | `CatalogoCoreHookMarkersTest` (KEEP), `CatalogoArticuloHookOwnershipTest` (additive) |
| SQL injection (moved models + new sync) | Applicable | moved models keep `var2str()`/`prepare` patterns byte-for-byte; no new raw SQL in the controller | Injected SQL through `codfamilia`/`etiqueta`/image fields | ownership grep + `VentasArticuloArticleEditAbsorptionTest` source assertions |
| File upload (article images) | Applicable | `tarif_articulo_imagen::upload()` MIME/validation + `imgs/tarifario/` writability guards preserved; `delete_imagen` scoped to the loaded `referencia`; `delete` gated by `allow_delete` | Arbitrary file write / cross-article image deletion | `VentasArticuloArticleEditAbsorptionTest::test_image_mutations_stay_scoped_to_the_loaded_article` |
| Destructive delete (article) | Applicable | delete is a CSRF-validated POST, `allow_delete` + `FS_DEMO` guards, and redirects to `ventas_articulos` | CSRF-free GET delete | `VentasArticuloArticleEditAbsorptionTest::test_article_delete_is_csrf_guarded_post_only` |
| Dynamic page resolution | Applicable | `page=ventas_articulo` unchanged; retired slugs resolve nowhere (no alias) | 404 / ghost menu row | `ArticuloDetalleCanonicoOwnershipTest` (no-alias + retirement) |
| Shell / subprocess / VCS-PR automation / executable classification | N/A | None introduced | — | — |

## Migration / Rollout

No data migration, no table rename, no schema or API change. Rollout is the
two-commit atomic move (AD-W2-10): the catalogo_core commit adds the three
moved models/XMLs, the absorbed controller/view, the bootstrap and the
retirements, and the new/updated tests; the tarifario commit `git rm`s the
moved/deleted files and repoints requires and links. No deploy between the two.
`Init::ensureArticuloDetalleTables()` creates the three tables idempotently on
standalone installs (`fs_model` only creates when missing); `tarifario_init.php`
stays an idempotent no-op. Plugin `fsframework.ini` version bumps are deferred
to the `fsframework-plugin-release` skill at release time. Post-rollout cache
clear: `CacheManager::clearAll()` + `tmp/twig_cache`.

**Intended behavior change (documented, not silent):** with tarifario active,
the canonical detail now applies the neutral gate, so non-admin users without a
gestor/editor role on any active tarifa can no longer edit an article through
`ventas_articulo` (today that page is ungated, while `tarif_articulo_edit`
already denied them). This is the unification mandated by ART-02; the admin
short-circuit and the zero-listener default-allow keep standalone catalogo_core
and admins unaffected. Surfaced in the WU-8 RBAC audit.

**Rollback (AD-W2-10):** revert the two commits in reverse order (tarifario,
then catalogo_core) and clear the Twig cache. The tarifario-owned edit pages,
the moved models and the previous links are restored byte-for-byte; the only
persistent effect is that the two retired `fs_page` rows are gone, which the
reverted controller recreates on next access.

## Open Questions

- [ ] `ApiField`-style exposure: does the canonical detail need to surface the per-tarifa `codfamilia` on any REST/JSON contract, or is the HTML tab the only consumer? (recommended default: HTML only; no API change — not decided here; affects no WU-2 task).
- [ ] `tarif_articulo_imagen::IMAGES_DIR` (`imgs/tarifario/`) is a storage path that still carries the plugin name; rename to a neutral path is a data-at-rest migration (recommended default: keep byte-stable through WU-8, revisit in the retirement audit).
- [ ] Image-mutation transport: WU-2 uses `hx-post` + multipart for upload and `hx-post` for delete/feature; whether WU-6's revision-notes panel reuses the same `respondHtmx()` seam (recommended default: yes, generalize then).
- [ ] `tarif_articulo_precio::url()` now points at `ventas_articulo` while `tarif_articulos` (the WU-3 list) still exists; whether WU-3 repoints it to the list for the null branch (recommended default: keep `ventas_articulos`, which is the canonical list already).

---

## WU-3 — canonical `ventas_articulos` list absorbs the tarifario article list

> **Scope.** Third slice of the program (depends on WU-1; WU-2 already landed).
> The canonical list `Controller/VentasArticulos.php` + `View/ventas_articulos.html.twig`
> absorbs `tarif_articulos`'s filters, per-tarifa price/state columns and
> quick-create; `tarif_articulos` controller + view retire with no alias and its
> `fs_page` row is removed idempotently. The tarifario list's Excel/JSON/cleanup
> actions stay reachable through a neutral catalogo_core-owned seam until WU-7.
> `tarif_articulo` / `tarif_articulos_ext` **do not move** (WU-4). Strict TDD;
> both plugin suites plus the root Plugins suite green after the slice.

### Technical Approach

WU-3 is the same **atomic two-repo move** as WU-1/WU-2 (AD-W3-10): one
`catalogo_core` commit (list absorption + trait + neutral seam + tests) followed
immediately by a `tarifario` commit (`git rm` of the retired list + action-handler
relocation + link repoints), with **no deploy between**. The absorbed list logic
lives in a controller-agnostic trait mirroring `VentasOpcionalesListTrait`; the
canonical controller keeps `extends PageController`, `privateCore()` and the
legacy wrapper contract. Per-tarifa columns are served by **one batched query per
page** (no N+1), keyed on the WU-1-owned `tarif_articulo_precio` table. The view
is rewritten to htmx 4 + Alpine CSP mirroring the opcionales list. The legacy
`tarif_articulos` list controller/view are deleted; its non-list
Excel/JSON/cleanup methods relocate **within tarifario** into an action handler
registered through a neutral catalogo_core interface, so every pre-existing
`action=` entry point keeps resolving on `ventas_articulos` without any
`plugins/tarifario/` path in catalogo_core.

### Architecture Decisions

| # | Decision | Choice | Alternatives rejected | Rationale |
|---|----------|--------|----------------------|-----------|
| AD-W3-1 | Model boundary WU-3 vs WU-4 | **WU-3 moves nothing new.** Rows come from the canonical `\articulo` model (`articulo::search()` / `ArticuloSearchQueryBuilder`); per-tarifa price/state comes from the **already-moved** WU-1 `tarif_articulo_precio`. `tarif_articulo::search_tarifario` / `count_search_tarifario` are **not needed** (filters alias onto the canonical search set). `tarif_articulo` + `tarif_articulos_ext` stay in tarifario; WU-4 keeps its full scope (`tarif_articulo`, `tarif_articulos_ext`, `tarif_descripcion` deletion). | Move `tarif_articulo` now (drags WU-4 forward; JSON/Excel internals depend on it); call `tarif_articulo` by tarifario path (forbidden direction, ALC-07) | ALC-01 aliases `query`/`b_codfamilia` onto `search`/`codfamilia`, so the list needs no tarifario search method; keeping the WU-4 boundary intact avoids a half-moved class and keeps `TarifArticuloFactoryForImportTest` (WU-7) untouched. |
| AD-W3-2 | List logic placement | New global trait `plugins/catalogo_core/extras/VentasArticulosListTrait.php` (PageController-only surface: `$this->request`, `$this->db`, `$this->user`, `$this->url()`, `$this->validateFormToken()`), mirroring `VentasOpcionalesListTrait`. | Inline in `VentasArticulos` (bloats a 529-L controller; harder to unit-test); move to `fbase_controller` (breaks `privateCore()`/wrapper contract) | Proven opcionales precedent (AD-1/AD-2); keeps absorbed logic DB-free testable via an anonymous subclass. |
| AD-W3-3 | Filter absorption + aliases | Normalize at the top of `privateCore()`: `search = query ?: search`, `codfamilia = b_codfamilia ?: codfamilia` (**canonical wins on conflict**), `b_codtarifa` alias `codtarifa` → requested → `tarif_tarifa::get_default()` → first `all_activas()` → `''`; `b_solo_activos` default **TRUE**, `FALSE` ⇒ include blocked (`bloqueados = (b_solo_activos === 'FALSE') || bloqueados === 'TRUE'`). `codfabricante` / `con_stock` unchanged. All filter keys preserved by `getListQueryParams()` for pagination/export. | `query` primary (canonical bookmarks/export use `search`); drop `codfabricante`/`con_stock` (locked test strings) | ALC-01; keeps `VentasArticulosControllerTest` source strings (`search`, `codfamilia`, `codfabricante`, `->search(`) byte-present. |
| AD-W3-4 | Per-tarifa batch reader | New `Services/ArticuloTarifaPrecioBatchReader.php` + **additive** `tarif_articulo_precio::all_for_referencias(array $refs, string $codtarifa): array` — one `WHERE codtarifa = ? AND referencia IN (…)` query, map keyed by referencia. Trait caches the map per request; accessors `precio_articulo_tarifa($ref)`, `articulo_activo_tarifa($ref)`, `articulo_en_tarifa_flag($ref)`, `articulo_en_catalogo($ref)`. Missing key defaults `precio 0.0 / activo TRUE / en_tarifa FALSE / en_catalogo FALSE`; display uses `CatalogoCurrencyFormatter::format($precio, $tarifa->coddivisa)`. | Per-row `get()` (the current tarifario N+1); eager-load all tarifas (over-fetch) | ALC-02; page size 50 ⇒ exactly 1 query regardless of rows. The WU-1 `ensureArticuloTarifaTables()` body stays untouched (additive model method). |
| AD-W3-5 | Quick-create prices (fixed + percentage) | `nuevoArticulo` keeps the contractual order **CSRF → neutral permission event (now with `codtarifa`) → `articulo::save()` → per-tarifa writes**. Fields `precio_tarifa_<codtarifa>` (fixed) and `porcentaje_tarifa_<codtarifa>` (derives `pvp * (1 + p/100)`, mirroring `aplicar_porcentaje_masivo`'s factor); fixed wins for the same tarifa; empty ⇒ no row (defaults apply). `save()` failure ⇒ prices are never attempted (nothing persists); CSRF/deny occur before any write. Existing `nreferencia`/`ndescripcion`/`npvp` fields, the duplicate check and the `No tienes permisos para crear este artículo: ` prefix stay byte-identical. | Wrap in a DB transaction (the locked `VentasArticulosQuickCreateGateCompositionTest` engine stub has no `begin_transaction`, so it would fatal); move the gate after `save()` (violates ALC-03 order) | ALC-03; the listener ignores `event->getCodtarifa()` (iterates `all_activas()`), so the locked composition test stays green. |
| AD-W3-6 | Excel/JSON continuity until WU-7 | **Relocate the action entry points into `VentasArticulos` and execute them through a neutral seam.** catalogo_core defines `Services/ArticuloListActionRegistry.php` + `ArticuloListActionHandlerInterface` (`supports(string): bool`, `handle(string, Request, array $state): bool`); `processExcelAction()` falls through to `ArticuloListActionRegistry::dispatch()` after its own cases. tarifario relocates the non-list methods of `tarif_articulos` **verbatim** into `Services/ArticuloListActionHandler.php` and registers it from `Init` behind `class_exists` + a static guard. With tarifario inactive the registry is empty and the canonical basic `ArticuloExcel*` stack serves export/preview/import. Filtered export parity (`export_articulos`) rides the handler; the canonical `export_excel_filtered` preserves the new filters via `getListQueryParams()`. | Temporary bridge page (a new slug does **not** keep the literal `action=` entry points resolving; adds slug coupling); soft `class_exists` on tarifario service FQCNs (hidden coupling, not a contract) | ALC-05 (`page=ventas_articulos&action=…` entry points keep resolving) + ALC-07 (zero `plugins/tarifario/`/`@tarifario/` refs); mirrors the host/guest direction of `ArticlePermissionFilterEvent`/`ArticlePermissionListener` (§3.4). The internals' logic is unchanged — WU-7 absorbs it and retires the handler. |
| AD-W3-7 | Retirement + repoints + `fs_page` | Delete `plugins/tarifario/controller/tarif_articulos.php` + `View/tarif_articulos.html.twig`, **no alias**. Repoint every inbound `page=tarif_articulos` (tarifario: `tarif_tarifas.html.twig:53`, `tarif_actualizar_precios.html.twig:71`, `tarif_historial_precios.html.twig:51`, `tarif_articulo.html.twig:23,219`; catalogo_core: `ventas_opcionales.html.twig:190`, `Macro/TarifarioComponents.html.twig:259`) to `page=ventas_articulos`, mapping `codtarifa` → `b_codtarifa`. Add idempotent `Init::retireTarifArticulosPage()` wired in `upgrade()` inside its own try/catch. | Redirect alias (ALC-06 scenario 1); leave links (broken menu/back-links) | ALC-06; mirrors `retireTarifOpcionalesPage()`/`retireTarifArticuloEditPage()`. `tarif_articulo_precio::url()` and `tarif_articulo::url()` already emit canonical slugs (WU-2), no change. |
| AD-W3-8 | List delete mutation | The list-row delete becomes a CSRF-validated POST handled by `VentasArticulos::eliminarArticulo()` (`delete=<ref>`, `allow_delete` + `FS_DEMO` guards), driven by Alpine confirm → `htmx.ajax('POST', …)`; the dead `articulo.url()&delete=` GET link is removed. | Keep the GET link (WU-2 removed the detail's GET delete branch, so it is already broken); nonce-free inline JS | Restores delete after WU-2 and satisfies the XSS/CSRF threat rows; consistent with the canonical detail's POST delete. |
| AD-W3-9 | htmx 4 + Alpine view | Rewrite `View/ventas_articulos.html.twig`: `htmx.boot({'allowScriptTags': false})` + `alpine.boot()`; filters `hx-get` to the same page with `hx-target="#articulos-list"`, `hx-select="#articulos-list"`, `hx-swap="outerHTML"`, `hx-push-url="true"`; quick-create `hx-post` on the modal form; Alpine components `articulosList` (modal/search focus) and `articuloConfirm` (delete) registered nonce'd behind `alpine:init`, `[x-cloak]`, `x-text` only, colon events only. **No separate rows-fragment route** (full-page render + `hx-select`). Locked strings preserved: `{{ include('header.html.twig') }}`, `{{ include('footer.html.twig') }}`, `{{ csrf_field() }}`, no `\|raw`, the modal partial includes and the Excel/JSON config carrier + JS entry points stay. | Fragment endpoint (extra surface; the locked test requires the full view anyway); `hx-delete` (mutations are `hx-post` only); bootbox/jQuery (ALC-04) | ALC-04; mirrors `VentasOpcionalesListTrait`/view precedent. |
| AD-W3-10 | Atomic landing + rollback | Two coordinated commits (`catalogo_core` first, `tarifario` immediately after, no deploy between). **Rollback**: revert the two commits in reverse order (`tarifario` then `catalogo_core`) and clear the Twig cache; the retired `fs_page` row is recreated by the reverted controller on next access. | Separate PRs (broken runtime window); per-file manual restore | Same mechanics as WU-1/WU-2; parent repo tracks no plugin bytes. |
| AD-W3-11 | No half-moved state + test ownership | Every moved/retired file exists in exactly one plugin; catalogo_core production stays free of `plugins/tarifario/`/`@tarifario/` (`ArticuloTarifaPrecioOwnershipTest` grep gate stays green); tarifario tests locking `tarif_articulos` repoint to `Services/ArticuloListActionHandler.php` and never assert the retired slug. New tests live in `catalogo_core/tests/`; `VentasArticulosControllerTest` is **not edited** (ALC-08). | Keep new tests in tarifario (plugin-local ownership); big-bang move of all WUs | R2/R6/R12 mitigation; the ownership tests are the machine check. |

### Data Flow

    GET ventas_articulos (search/query, codfamilia/b_codfamilia, codfabricante,
                          con_stock, bloqueados/b_solo_activos, b_codtarifa, offset)
      VentasArticulos::privateCore
        ├─ normalize aliases ─► resolve b_codtarifa (request → get_default() → first all_activas())
        ├─ action? ─► processExcelAction():
        │      canonical cases (export_excel[_filtered], preview_excel, get_preview,
        │      excel_import_sse, …)  ── else ──► ArticuloListActionRegistry::dispatch()
        │                                              └─[tarifario] ArticuloListActionHandler
        │                                                 (import_json*, import_articulos,
        │                                                  export_articulos/template, limpiar_*)
        ├─ POST delete? ─► validateFormToken() + allow_delete ─► articulo::delete()
        ├─ POST nreferencia? ─► nuevoArticulo(): CSRF → ArticlePermissionFilterEvent(codtarifa)
        │                        → articulo::save() → tarif_articulo_precio fixed/percentage writes
        └─ list search ─► articulo::search(search, offset, codfamilia, con_stock, codfabricante, bloqueados)
                        └─ batch reader: tarif_articulo_precio::all_for_referencias(refs, b_codtarifa)
                             ⇒ per-row precio/activo/en_tarifa/en_catalogo map (1 query)
      View (htmx 4 + Alpine CSP): filters hx-get + hx-push-url → #articulos-list swap;
        quick-create/delete hx-post; Excel/JSON buttons unchanged

### File Changes

#### `plugins/catalogo_core/` (commit 1)

| File | Action | Description |
|------|--------|-------------|
| `extras/VentasArticulosListTrait.php` | Create | Tarifa selector (`b_codtarifa` fallback chain), filter aliases, batch-cache accessors, pagination query params. PageController-only surface. |
| `Services/ArticuloTarifaPrecioBatchReader.php` | Create | One-query-per-page map + ALC-02 defaults; uses `tarif_articulo_precio`. |
| `Services/ArticuloListActionRegistry.php` | Create | Neutral registry + `ArticuloListActionHandlerInterface`; `reset()` test seam. |
| `Controller/VentasArticulos.php` | Modify | `use \VentasArticulosListTrait`; alias normalization; batch cache; permission event gains `codtarifa`; quick-create fixed+percentage writes; POST delete; registry dispatch; filtered export keeps new filters. Locked method/string contract preserved. |
| `View/ventas_articulos.html.twig` | Modify | htmx 4 + Alpine rewrite; `hx-get`/`hx-push-url` filters; `hx-post` quick-create/delete; header/footer/`csrf_field()`/modal partials/Excel-JS entry points kept; no bootbox/jQuery/`\|raw`. |
| `View/partials/articulos/modal_nuevo_articulo.html.twig` | Modify | Add per-tarifa fixed + percentage inputs (currency via `CatalogoCurrencyFormatter`); keep `{{ csrf_field() }}` and `nreferencia`/`ndescripcion`/`npvp`. |
| `model/tarif_articulo_precio.php` | Modify (additive) | Add `all_for_referencias(array $refs, string $codtarifa): array`; no other change (WU-1 contract intact). |
| `Init.php` | Modify | Add idempotent `retireTarifArticulosPage()`; wire into `upgrade()`. |
| `View/ventas_opcionales.html.twig` | Modify | `:190` back-link → `ventas_articulos`. |
| `View/Macro/TarifarioComponents.html.twig` | Modify | `:259` link → `ventas_articulos&b_codtarifa=…`. |
| `tests/Controller/VentasArticulosListAbsorptionTest.php` | Create | ALC-01/02/03/05/06/07 behavior + source contracts. |
| `tests/ArticuloListaCanonicaOwnershipTest.php` | Create | ALC-06/07/08 ownership, retirement idempotency, zero-tarifario-paths, no half-moved. |
| `tests/Integration/CatalogoArticuloListHtmxContractTest.php` | Create | ALC-04 htmx/Alpine hygiene contract. |
| `tests/Services/ArticuloTarifaPrecioBatchReaderTest.php` | Create | ALC-02 single-query map + defaults. |
| `tests/InitUpgradeTest.php` | Modify (assertion) | Assert `upgrade()` retires `tarif_articulos` idempotently. |

#### `plugins/tarifario/` (commit 2)

| File | Action | Description |
|------|--------|-------------|
| `controller/tarif_articulos.php` | Delete | Retired, no alias (ALC-06); list methods absorbed; non-list methods relocated to the handler. |
| `View/tarif_articulos.html.twig` | Delete | Retired, no alias. |
| `Services/ArticuloListActionHandler.php` | Create | Verbatim relocation of the import/export/cleanup methods; implements `ArticuloListActionHandlerInterface`. |
| `Init.php` | Modify | Register the handler into `ArticuloListActionRegistry` at boot (`class_exists` + static guard). |
| `View/tarif_tarifas.html.twig` | Modify | `:53` → `page=ventas_articulos`. |
| `View/tarif_actualizar_precios.html.twig` | Modify | `:71` → `page=ventas_articulos`. |
| `View/tarif_historial_precios.html.twig` | Modify | `:51` → `page=ventas_articulos`. |
| `View/tarif_articulo.html.twig` | Modify | `:23,:219` → `page=ventas_articulos`. |
| `tests/Controller/TarifArticulosFamiliaImportTest.php` | Modify | Repoint source/require assertions to `Services/ArticuloListActionHandler.php`; no slug assertion. |
| `tests/Integration/TarifFamiliaWriteRetirementTest.php` | Modify | Repoint `tarif_articulos.php` source assertions to the handler. |

No edit required: `Services/ExcelImportWizardServiceTest.php`, `Model/TarifArticuloFactoryForImportTest.php` reference stable table names/classes, not the retired slug; `Integration/VentasArticulosQuickCreateGateCompositionTest.php` and `Integration/HookRegistrationTest.php` stay green (listener and hook contracts untouched). `model/tarif_articulo.php` / `tarif_articulos_ext` stay in tarifario for WU-4.

### Interfaces / Contracts

**Neutral action seam** (`catalogo_core`):

```php
interface ArticuloListActionHandlerInterface
{
    public function supports(string $action): bool;
    /** @param array<string,mixed> $state @return bool TRUE when the action was handled */
    public function handle(string $action, Request $request, array $state): bool;
}
final class ArticuloListActionRegistry
{
    public static function register(ArticuloListActionHandlerInterface $handler): void;
    public static function reset(): void;   // test seam
    public static function dispatch(string $action, Request $request, array $state): bool;
}
```

`processExcelAction()` runs its canonical cases first, then `dispatch()`; unknown action ⇒ `false`. `$state` carries `search`, `codfamilia`, `codfabricante`, `con_stock`, `bloqueados`, `b_codtarifa`, `b_solo_activos`, `idiomas`, `tarifas`, `allow_delete`.

**Batch reader**:

```php
// plugins/catalogo_core/model/tarif_articulo_precio.php
public function all_for_referencias(array $refs, string $codtarifa): array;
// => ['REF' => ['precio' => float, 'activo' => bool, 'en_tarifa' => bool, 'en_catalogo' => bool], ...]
```

**Trait accessors** (`public`, Twig API): `get_precio_articulo_tarifa($ref)`, `articulo_activo_tarifa($ref)`, `articulo_en_tarifa_flag($ref)`, `articulo_en_catalogo($ref)`, `tarifa_seleccionada()` (or property), `mostrar_precio_tarifa($ref)`, `simbolo_divisa_tarifa(string $coddivisa)`.

**Quick-create field contract** (POST to `ventas_articulos`): `nreferencia`, `ndescripcion`, `npvp`, `ncodfamilia`, `ncodfabricante`, `ncodimpuesto`, `precio_tarifa_<codtarifa>`, `porcentaje_tarifa_<codtarifa>`, `_csrf_token`. Order: CSRF → permission event (with `codtarifa`) → `save()` → prices.

### Testing Strategy

| ALC | Layer | What is proven | Test file / method |
|---|---|---|---|
| ALC-01 | Unit/behavior | `query`/`b_codfamilia` alias `search`/`codfamilia` (identical rows); `b_codtarifa` default→first-active; `b_solo_activos` default TRUE, FALSE includes blocked | `tests/Controller/VentasArticulosListAbsorptionTest.php` — `test_query_and_b_codfamilia_alias_search_and_codfamilia`, `test_b_codtarifa_resolves_default_then_first_active`, `test_b_solo_activos_default_true_and_false_includes_blocked` |
| ALC-02 | Unit | Single batched query per page; missing row defaults 0/TRUE/FALSE/FALSE; currency-priced per `coddivisa` | `tests/Services/ArticuloTarifaPrecioBatchReaderTest.php` — `test_single_query_maps_all_referencias`, `test_missing_row_defaults_to_0_true_false_false`; `VentasArticulosListAbsorptionTest::test_per_tarifa_columns_read_the_batch_map` |
| ALC-03 | Contract/behavior | Fixed + percentage persist per tarifa; CSRF/deny persist nothing; `save()` failure writes no prices; order CSRF → gate → save → prices | `VentasArticulosListAbsorptionTest` — `test_quick_create_persists_fixed_and_percentage_prices`, `test_quick_create_denied_or_csrf_invalid_persists_nothing`, `test_quick_create_save_failure_persists_no_prices`; tarifario `Integration/VentasArticulosQuickCreateGateCompositionTest.php` **KEEP green (no edits)** |
| ALC-04 | Source hygiene | colon events only, `hx-post` mutations, nonce'd `Alpine.data()` behind `alpine:init`, `[x-cloak]`, no `bootbox`/`\|raw`/v2 names; filters `hx-get` + `hx-push-url`; locked view strings intact | `tests/Integration/CatalogoArticuloListHtmxContractTest.php` — `test_filters_use_hx_get_with_push_url`, `test_mutations_are_hx_post_only`, `test_alpine_and_htmx_hygiene`, `test_locked_view_strings_are_preserved` |
| ALC-05 | Contract | Every pre-existing action entry point resolves on the migrated list (canonical cases + registry seam); filtered export preserves active filters | `VentasArticulosListAbsorptionTest` — `test_action_entry_points_delegate_through_the_neutral_seam`, `test_filtered_export_query_preserves_the_new_filters`; `tests/Controller/TarifArticulosFamiliaImportTest.php` **UPDATE** (repointed, handler behavior preserved) |
| ALC-06 | Ownership | No controller/view for `tarif_articulos` in either plugin; no alias resolves the slug; inbound links repointed; `fs_page` retirement idempotent | `tests/ArticuloListaCanonicaOwnershipTest.php` — `test_retired_list_leaves_no_alias_in_either_plugin`, `test_retired_slug_has_zero_inbound_links`, `test_retired_fs_page_row_is_deleted_idempotently`; `tests/InitUpgradeTest.php` (assertion) |
| ALC-07 | Unit/grep | catalogo_core production has zero `plugins/tarifario/`/`@tarifario/`; no class file in both plugins; `tarif_articulo`/`tarif_articulos_ext` stay tarifario-only; RBAC listener stays in tarifario | `tests/ArticuloListaCanonicaOwnershipTest.php` — `test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_list_surface`, `test_tarif_articulo_and_ext_stay_in_tarifario_for_wu4`; `tests/ArticuloTarifaPrecioOwnershipTest.php` **KEEP green**; tarifario `Integration/HookRegistrationTest.php` **KEEP green** |
| ALC-08 | Suite gate | `VentasArticulosControllerTest` green unedited; tarifario tests do not assert the retired slug; catalogo_core ≥ **576/2147** | `plugins/catalogo_core/tests/VentasArticulosControllerTest.php` **KEEP (no edits)**; `TarifArticulosFamiliaImportTest`/`TarifFamiliaWriteRetirementTest` **UPDATE**; both plugin suites + root Plugins suite |

Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (baseline **576 tests / 2147 assertions**), `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml`, and the root Plugins suite.

### Threat Matrix

| Boundary | Applicability | Expected safe behavior | Failure behavior | RED test |
|---|---|---|---|---|
| CSRF (quick-create / list delete) | Applicable | `validateFormToken()` before any model factory/`save()`/`delete()`; htmx boot injects `X-CSRF-TOKEN`; invalid ⇒ error, no write | Token bypass ⇒ unauthorized article/price/delete persistence | `VentasArticulosListAbsorptionTest::test_quick_create_denied_or_csrf_invalid_persists_nothing`; tarifario composition test (KEEP) |
| Authorization (article quick-create gate) | Applicable | neutral `ArticlePermissionFilterEvent` (now with `codtarifa`) default-allow, admin short-circuit; deny ⇒ no factory/`save()` and no price writes | Privilege escalation on article/price creation | `VentasArticulosQuickCreateGateCompositionTest` (KEEP); `VentasArticulosListAbsorptionTest` |
| XSS (rewritten view + swapped fragments) | Applicable | Twig autoescape; no `\|raw`; Alpine `x-text`; `allowScriptTags:false`; `hx-post` only; escaped confirm text | Script injection into the list swap | `CatalogoArticuloListHtmxContractTest` |
| SQL injection (filters + batch reader) | Applicable | No raw user SQL; `articulo::search()`/`ArticuloSearchQueryBuilder` and `var2str()`-quoted `IN (…)` refs; `b_codtarifa` validated against `all_activas()` | Injected SQL via `search`/`b_codfamilia`/`b_codtarifa` | `VentasArticulosControllerTest` (KEEP, `var2str`/`->search(`) + `ArticuloTarifaPrecioBatchReaderTest` |
| Action-seam injection | Applicable | Registry only dispatches to handlers that `supports()` the action; unknown action ⇒ no-op; tarifario handler keeps its existing path/session guards | Arbitrary action execution via `action=` | `VentasArticulosListAbsorptionTest` (registry dispatch) + `TarifArticulosFamiliaImportTest` (UPDATE) |
| Dynamic page resolution | Applicable | `page=ventas_articulos` unchanged; retired `tarif_articulos` resolves nowhere (no alias) | 404 / ghost menu row | `ArticuloListaCanonicaOwnershipTest` (no-alias + retirement) |
| Hook ownership | N/A | This slice touches no hook templates; the four frozen markers are untouched | — | `CatalogoCoreHookMarkersTest` (KEEP, unaffected) |
| Shell / subprocess / VCS-PR automation / executable classification | N/A | None introduced | — | — |

### Migration / Rollout

No data migration, no table rename, no schema or API change, no Composer
dependency. Rollout is the two-commit atomic move (AD-W3-10): the catalogo_core
commit adds the trait/services/batch reader, the absorbed controller/view, the
`Init` retirement and the new/updated tests; the tarifario commit `git rm`s the
retired list, adds the relocated action handler + registration, and repoints
links/tests. No deploy between the two.
`Init::retireTarifArticulosPage()` removes the orphan `fs_page` row
idempotently (`fs_model`/`fs_page` are created on demand, the row is recreated by
the reverted controller on next access after a rollback). Post-rollout cache
clear: `CacheManager::clearAll()` + `tmp/twig_cache`.

**Intended behavior changes (documented, not silent):** (1) list-row delete
changes from a dead `articulo.url()&delete=` GET link to a CSRF-validated POST on
the list (repairs the WU-2 regression); (2) quick-create now resolves and writes
per-tarifa prices for the selected/default tarifa and dispatches the permission
event with `codtarifa` (the listener still resolves roles across all active
tarifas, so no RBAC behavior change).

**Rollback (AD-W3-10):** revert the two commits in reverse order (tarifario, then
catalogo_core) and clear the Twig cache; the tarifario list and its action entry
points are restored byte-for-byte, and the only persistent effect (the removed
`fs_page` row) is recreated on next access.

### Open Questions

- [ ] Percentage base: ALC-03 says "derives from PVP"; the trait applies `pvp * (1 + p/100)` mirroring `aplicar_porcentaje_masivo`'s factor (recommended default: PVP; rejected base = existing per-tarifa price). Not blocking; affects quick-create only.
- [ ] Action-seam vs bridge: ratified the neutral registry (AD-W3-6); confirm WU-7 absorbs `ArticuloListActionHandler` into catalogo_core and deletes the registry consumer rather than keeping the seam permanently (recommended default: absorb + delete in WU-7).
- [ ] Canonical filter key: ALC-01 mandates `b_codtarifa`; whether to also accept `codtarifa` as a permanent alias (recommended default: accept both, `b_codtarifa` primary; the opcionales list uses `b_codtarifa` too).
- [ ] List delete field: `delete=<ref>` on the list vs reusing the detail's `eliminar_articulo` field (recommended default: list-local `delete`, `allow_delete`-gated, since the detail is a different page).
- [ ] Totals badge: keep `count(resultados)` + `hasMoreResults()` (minimal, locked-test-safe) vs a real `COUNT` for the tarifa-filtered list (recommended default: keep minimal; no count query in WU-3).

---

## WU-4 — catalogo_core owns the article extension models; `tarif_descripcion` deleted

> **Scope.** Fourth slice of the program (depends on WU-2 + WU-3, both applied in
> the working tree). Delta spec: `specs/articulo-modelos-ext/spec.md`
> (AME-01..AME-06). The `AD-W2-1` re-slice already moved
> `tarif_tarifa_articulo`, `tarif_tarifa_articulo_etiqueta` and
> `tarif_articulo_imagen`; WU-4 is limited to **`tarif_articulo`**,
> **`tarif_articulos_ext`** + `model/table/tarif_articulos.xml`, and the
> **`tarif_descripcion` deletion**. No data migration, no table rename, no
> schema/API change. Runner:
> `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
> (baseline **602 tests / 2343 assertions**; tarifario **180 tests / 637
> assertions**, Skipped 3). Strict TDD; both plugin suites plus the root
> `Plugins` suite green after the slice.

### Technical Approach

WU-4 is the same **atomic two-repo move** as WU-2 (AD-W4-7): one
`catalogo_core` commit (verbatim model/XML copies + explicit `git add` +
standalone bootstrap + tests) immediately followed by a `tarifario` commit
(`git rm` of the moved models/XML and the deleted wrapper + `require_once`
repoints + `tarifario_init.php` cleanup), with **no deploy between**. Because
WU-4 **adds before it removes**, the catalogo_core commit lands **first** (the
WU-2 order, not the WU-1 order). `tarif_articulo` keeps extending
`\FSFramework\model\articulo` (no table of its own); `tarif_articulos_ext`
keeps `table_name = 'tarif_articulos'`; both keep the
`FSFramework\model\*` FQCN, so every `use`-import and unqualified
`new tarif_articulo()` in tarifario resolves through `fs_model_autoloader`
against the active catalogo_core tree without source edits
(`base/fs_model_autoloader.php` scans active-plugin `model/` directories; the
`use`-only consumers need no repoint, the explicit `require_once`s do).

The moved models are **byte-identical** copies (no authorized delta in WU-4 —
the `url()` repoint already landed in WU-2 and travels with the file). The only
production change is one new idempotent bootstrap method on
`catalogo_core/Init.php`; the withdrawn `tarif_descripcion` wrapper is deleted
and its boot block removed. The catalog surface (`tarif_catalogo*`) is **not**
touched — WU-5 owns it.

### Architecture Decisions

| # | Decision | Choice | Alternatives rejected | Rationale |
|---|----------|--------|----------------------|-----------|
| AD-W4-1 | Exact model moves | Move **verbatim** into `catalogo_core/model/` (+ `model/table/`): `tarif_articulo.php`, `tarif_articulos_ext.php`, `model/table/tarif_articulos.xml`; `git rm` the tarifario paths. FQCNs (`FSFramework\model\tarif_articulo`, `…\tarif_articulos_ext`) and `table_name = 'tarif_articulos'` stay byte-stable; `tarif_articulo` keeps `extends \FSFramework\model\articulo` (no own table). No class file/XML in both plugins. | Move `tarif_articulo` but leave `tarif_articulos_ext` (half-moved; the factory + importer need both); also move `tarif_descripciones.xml`/catalog models (WU-5/WU-8 scope); rewrite `tarif_articulo` onto core `articulo` and drop the subclass (FQCN/behavior break) | Spec AME-01/AME-04. `tarif_articulo` is the article-domain extension used by the list/import/detail surfaces; `tarif_articulos_ext` backs the `ref_catalogo`/`configurador` ext columns. Byte-stable FQCN + table name preserve the importer, the factory and the raw-SQL writers with zero SQL change. |
| AD-W4-2 | `tarif_descripcion` deletion | Delete `plugins/tarifario/model/tarif_descripcion.php` **and** the boot block `tarifario_init.php:128-131`. Core `articulo_descripcion` (`catalogo_core/model/core/articulo_descripcion.php` + `model/table/articulo_descripciones.xml`) stays the single canonical multiidioma model. Nothing else references the wrapper (grep-verified: only its own declaration + the boot block). | Keep the wrapper as a compat alias (dead code in a plugin that must not own the article model); also delete `tarif_descripciones.xml` (dead-table audit is WU-8) | Spec AME-02. The wrapper is a 14-line `extends articulo_descripcion {}` with no behavior; the `tarif_descripciones` **table** is separately mapped to `articulo_descripciones` by `CatalogLegacyTableMigration.php:18` and is explicitly out of scope (AD-W4-10). |
| AD-W4-3 | Require repoints | Repoint exactly the six hardcoded `require_once`s: `tarifario/controller/tarif_actualizar_precios.php:22` (`tarif_articulo.php`), `tarifario/controller/tarif_catalogo_view.php:34` (`tarif_articulos_ext.php`), `tarifario/Services/ArticuloListActionHandler.php:31` (`tarif_articulo.php`), `tarifario/tests/Model/TarifArticuloFactoryForImportTest.php:46,48`, `tarifario/tests/Services/ExcelImportWizardServiceTest.php:62,64` → `FS_FOLDER . '/plugins/catalogo_core/model/…'` / `plugins/catalogo_core/model/…`. Keep every `use FSFramework\model\…` import and FQCN unchanged. **No require repoint in `tarifario_init.php`** (it has no hardcoded require for either model; only the `tarif_descripcion` block is removed). | Repoint `use` imports too (no-op churn; the FQCN is the stable contract); add requires to the FQCN-only consumers (`tarif_controller.php`, `tarif_historial_precios.php`, `process_excel_wizard.php`, `ExcelImportWizardService.php`, `ExcelRowUpdater.php`, `tarif_catalogo_articulo.php`, `tarif_catalogo_def_articulo.php`) where the autoloader already resolves the class | Spec AME-03. Only explicit path requires break on a move; `fs_model_autoloader` resolves `FSFramework\model\*` from the active catalogo_core, so FQCN consumers need no edit. Keeps the diff mechanical and grep-auditable. |
| AD-W4-4 | Standalone bootstrap | Add **`public static function ensureArticuloExtTables(): void`** to `catalogo_core/Init.php` (mirror of `ensureArticuloDetalleTables()`, AD-W2-8). Shape: `require_once FS_FOLDER . '/base/fs_model.php'`; `self::touchNamespacedModel('familia')` first (the XML declares FK `codfamilia → familias`); then `$file = FS_FOLDER . '/plugins/catalogo_core/model/tarif_articulos_ext.php'` behind `is_file` + `require_once`; instantiate `FSFramework\model\tarif_articulos_ext` behind `class_exists($fqcn, false)` + `is_subclass_of($fqcn, \fs_model::class)`. Wire in `init()` **after** `ensureArticuloDetalleTables()` in its own `try/catch` + `error_log`, and in `upgrade()` after `self::ensureArticuloDetalleTables();`. **Do not modify the frozen bodies** of `ensureArticuloTarifaTables()` / `ensureArticuloDetalleTables()`; `tarif_articulo` has no table and is not instantiated. | Add `tarif_articulos_ext` to the frozen `ensureArticuloDetalleTables()` body (mutates a WU-2-pinned contract); skip the bootstrap (standalone catalogo_core never creates `tarif_articulos`); call `ensureFamiliasTarifaTables()` (heavier; unnecessary — no `tarif_*` FK) | Spec AME-01 scenario 2. `fs_model` creates the table only when missing, so a second boot is a harmless no-op; `tarifario_init.php`'s `class_exists` instantiation stays an idempotent no-op (catalogo_core boots first as a declared dependency). A new method keeps the WU-1/WU-2 contracts byte-stable. |
| AD-W4-5 | Pinned-test updates | Update the two tests that pinned the WU-3 boundary **in the same slice**: (1) `tests/ArticuloListaCanonicaOwnershipTest.php` — rename `test_tarif_articulo_and_ext_stay_in_tarifario_for_wu4` → `test_tarif_articulo_and_ext_are_owned_by_catalogo_core_after_wu4`, asserting the 3 paths exist **only** in catalogo_core and are absent in tarifario (RBAC listener assertion kept); update the class docblock. (2) `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php:935` — `$this->source('/plugins/tarifario/model/tarif_articulo.php')` → `/plugins/catalogo_core/model/tarif_articulo.php` (the `url()` assertions are unchanged). | Leave either test asserting tarifario ownership (spec AME-06 forbids it); delete either test (loses the ownership/url contract) | Spec AME-06 scenario 2. No test may assert `tarif_articulo` / `tarif_articulos_ext` / `tarif_articulos.xml` stay in tarifario after WU-4. |
| AD-W4-6 | Grep-gate precision + no half-moved state | Ownership assertions anchor on **exact paths** (`plugins/tarifario/model/tarif_articulo.php`, `plugins/tarifario/model/tarif_articulos_ext.php`, `plugins/tarifario/model/table/tarif_articulos.xml`) and on **class tokens** (`class\s+tarif_articulo\b`, `class\s+tarif_articulos_ext\b`, `parent::__construct('tarif_articulos')`), **never** on the bare substring `tarif_articulo`. The coupling gate anchors on the literal strings `plugins/tarifario/` + `@tarifario/` over the catalogo_core production tree. A dedicated guard asserts the moved-path list is exact and does **not** swallow `tarif_articulo_precio.php` / `tarif_articulo_imagen.php` / `tarif_articulo_edit` (WU-1/WU-2-owned or retired). | Bare substring matching (would false-positive on `tarif_articulo_precio`, `tarif_articulo_imagen`, `tarif_articulos_ext`, `tarif_articulo_edit`, `tarif_articulo_precios`); per-file manual audit | Spec AME-04. `tarif_articulo` is a strict prefix of five other identifiers; prefix-anchored gates are the machine check for the half-moved state (R2). |
| AD-W4-7 | Atomic landing + rollback | Two coordinated commits: **catalogo_core first** (adds the moved models/XML + bootstrap + tests), **tarifario immediately after** (removes the moved files + wrapper + repoints), no deploy between. **Rollback**: revert the two commits in reverse order (tarifario, then catalogo_core) and clear the Twig cache. No data/table/`fs_page`/Composer change survives the revert. | Separate PRs (broken runtime window where catalogo_core has no local `tarif_articulo` and tarifario has already deleted it); per-file manual restore | Same mechanics as WU-2/AD-W2-10; parent repo tracks no plugin bytes; the move is code-only so a revert is byte-exact. |
| AD-W4-8 | RBAC boundary stays in tarifario | `ArticlePermissionListener`, `tarif_grupo_articulo` and the RBAC role tables remain in `tarifario`; the listener stays registered on catalogo_core's neutral `ArticlePermissionFilterEvent`. catalogo_core owns no RBAC consumer for the moved models. | Move the listener/`tarif_grupo_articulo` with the models (inverts the documented host/guest contract, drags ~1.5k LOC + admin UI; exploration §3.4 rejected) | Spec AME-05 scenario 2; `Integration/HookRegistrationTest.php` stays green **unedited** and remains the machine check. |
| AD-W4-9 | Bootstrap FK safety + frozen bodies | `ensureArticuloExtTables()` ensures the FK destination `familias` (`touchNamespacedModel('familia')`) before instantiating `tarif_articulos_ext`, and is invoked from `init()` inside its own `try/catch`/`error_log` and from `upgrade()`. The `ensureArticuloTarifaTables()` (WU-1) and `ensureArticuloDetalleTables()` (WU-2) bodies are read-only in WU-4. | Instantiate `tarif_articulos_ext` before `familias` exists (DDL FK failure on engines that enforce it); fold the model into the WU-2 method (breaks the pinned body) | Mirrors `ensureOpcionalesTarifaTables()`'s "touch FK destinations first" pattern; a standalone catalogo_core boot must never fatal (guarded by `is_file`/`class_exists`/`is_subclass_of`). |
| AD-W4-10 | `tarif_descripciones.xml` is OUT of scope | Do **not** delete `plugins/tarifario/model/table/tarif_descripciones.xml`; WU-4 deletes only the PHP wrapper. `CatalogLegacyTableMigration` keeps mapping the legacy table to `articulo_descripciones`. The dead-table removal is flagged for the **WU-8 audit**. | Delete the XML now (data-at-rest/legacy-migration surface, out of the WU-4 declared scope) | Spec AME-02 "Out of scope" note; keeps WU-4 a pure code-ownership move with a clean rollback. |
| AD-W4-11 | Test ownership + locked contracts | New ownership/bootstrap/grep test lives in `catalogo_core/tests/ArticuloModelosExtOwnershipTest.php` (namespace `Tests\CatalogoCore`, DB-free source+class contracts mirroring `ArticuloDetalleCanonicoOwnershipTest`). The five locked tests (`VentasArticulosControllerTest`, `VentasArticuloControllerTest`, `Integration/CatalogoCoreHookMarkersTest`, tarifario `Integration/HookRegistrationTest`, `Integration/VentasArticulosQuickCreateGateCompositionTest`) stay **unedited and green**. The two WU-3 boundary tests are updated (AD-W4-5). | Keep the new test in tarifario (plugin-local ownership rule: the new owner is catalogo_core); edit a locked test (spec AME-06 forbids weakening them) | House convention "tests travel with their code"; spec AME-06. |

### File Changes

#### `plugins/catalogo_core/` (commit 1)

| File | Action | Description |
|------|--------|-------------|
| `model/tarif_articulo.php` | Create (moved) | Verbatim move; FQCN + `extends \FSFramework\model\articulo` unchanged; `table_name` inherited (`articulos`), no own table |
| `model/tarif_articulos_ext.php` | Create (moved) | Verbatim move; `table_name = 'tarif_articulos'` byte-stable; CRUD/`update_field`/`get_extra_fields` unchanged |
| `model/table/tarif_articulos.xml` | Create (moved) | Verbatim schema (PK `referencia`, FK `codfamilia → familias`) |
| `Init.php` | Modify | Add `public static function ensureArticuloExtTables(): void` (AD-W4-4/AD-W4-9); wire into `init()` (after `ensureArticuloDetalleTables()`, own `try/catch`) and `upgrade()` (AD-W4-4 mapped to AME-01 scenario 2). Frozen bodies untouched |
| `tests/ArticuloModelosExtOwnershipTest.php` | Create | AME-01..AME-04 ownership + no-half-moved + `tarif_descripcion` deletion + standalone bootstrap source contract + grep gates (AD-W4-6/AD-W4-11) |
| `tests/ArticuloListaCanonicaOwnershipTest.php` | Modify | Invert `test_tarif_articulo_and_ext_stay_in_tarifario_for_wu4` → `test_tarif_articulo_and_ext_are_owned_by_catalogo_core_after_wu4`; update class docblock (AD-W4-5) |
| `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | Modify | `:935` source path → `plugins/catalogo_core/model/tarif_articulo.php`; `url()` assertions unchanged (AD-W4-5) |

#### `plugins/tarifario/` (commit 2)

| File | Action | Description |
|------|--------|-------------|
| `model/tarif_articulo.php` | Delete | `git rm` after the move (AD-W4-1) |
| `model/tarif_articulos_ext.php` | Delete | `git rm` after the move |
| `model/table/tarif_articulos.xml` | Delete | `git rm` after the move |
| `model/tarif_descripcion.php` | Delete | `git rm`; deprecated wrapper, core `articulo_descripcion` stays canonical (AD-W4-2) |
| `controller/tarif_actualizar_precios.php` | Modify | `:22` `require_once` → `plugins/catalogo_core/model/tarif_articulo.php`; `use` FQCN unchanged (AD-W4-3) |
| `controller/tarif_catalogo_view.php` | Modify | `:34` `require_once` → `plugins/catalogo_core/model/tarif_articulos_ext.php`; `use` FQCN unchanged |
| `Services/ArticuloListActionHandler.php` | Modify | `:31` `require_once` → `plugins/catalogo_core/model/tarif_articulo.php`; `use` FQCN unchanged |
| `extras/tarifario_init.php` | Modify | Remove the `tarif_descripcion` block `:128-131` (AME-02); **keep** the `tarif_articulos_ext` `class_exists` instantiation `:123-125` as an idempotent no-op (AME-03) |
| `tests/Model/TarifArticuloFactoryForImportTest.php` | Modify | `:46,:48` requires → catalogo_core model paths; assertions/FQCN unchanged (AME-03/AME-05) |
| `tests/Services/ExcelImportWizardServiceTest.php` | Modify | `:62,:64` requires → catalogo_core model paths; assertions/FQCN unchanged (AME-03/AME-05) |

**No edit required (autoloader resolves the FQCN from catalogo_core):**
`extras/tarif_controller.php` (`use … tarif_articulo` + `new tarif_articulo()`),
`controller/tarif_historial_precios.php`, `controller/tarif_catalogo_view.php:48`
(`use … tarif_articulo`), `process_excel_wizard.php` (FQCN
`\FSFramework\model\tarif_articulo` / `tarif_articulos_ext`),
`Services/ExcelImportWizardService.php`, `Services/ExcelRowUpdater.php`,
`model/tarif_catalogo_articulo.php`, `model/tarif_catalogo_def_articulo.php`.
`extras/tarifario_init.php:123-125` stays as the spec-mandated idempotent
no-op. No catalogo_core production file requires either moved model (the
configurator keeps its raw-SQL path; `TarifConfiguradorOpcionalesTest` stays
green).

### Interfaces / Contracts

**Standalone bootstrap** (`plugins/catalogo_core/Init.php`):

```php
public static function ensureArticuloExtTables(): void
{
    require_once FS_FOLDER . '/base/fs_model.php';

    // tarif_articulos.codfamilia FK → familias (FK-safe: destination first).
    self::touchNamespacedModel('familia');

    $file = FS_FOLDER . '/plugins/catalogo_core/model/tarif_articulos_ext.php';
    if (is_file($file)) {
        require_once $file;
    }

    $fqcn = 'FSFramework\\model\\tarif_articulos_ext';
    if (class_exists($fqcn, false) && is_subclass_of($fqcn, \fs_model::class)) {
        new $fqcn();
    }
}
```

Wiring: `init()` calls `self::ensureArticuloExtTables();` inside its own
`try { … } catch (\Throwable $e) { error_log('[catalogo_core] articulo ext
tables ensure failed: ' . $e->getMessage()); }` **after** the
`ensureArticuloDetalleTables()` block; `upgrade()` calls it inside the
existing main `try` after `self::ensureArticuloDetalleTables();`. The method is
idempotent by construction (`fs_model` creates only when missing), so a second
boot is a no-op (AME-01 scenario 2).

**Moved-model contract (unchanged, spec AME-01/AME-05):**

```php
// plugins/catalogo_core/model/tarif_articulo.php
namespace FSFramework\model;
class tarif_articulo extends \FSFramework\model\articulo {
    public function url();                 // → page=ventas_articulo&ref=… (WU-2)
    public function url_tarifario();
    public function get($ref);
    public function search_tarifario($query, $offset, $codfamilia, $solo_activos);
    public function count_search_tarifario(...);
    public function all_from_familia_tarifario(...);
    public function all_tarifario(...);
    public function search(...);
    public function get_imagenes(); get_imagen_destacada(); imagen_destacada_url();
    public function get_precio_tarifa() / set_precio_tarifa() / delete_precio_tarifa();
    public function get_tarif_extra() / save_tarif_field() / get_tarif_field();
    public static function factory_for_import(array $row, array $mapping, string $codtarifa, ?\fs_db2 $db = null): \FSFramework\model\tarif_articulo;
}

// plugins/catalogo_core/model/tarif_articulos_ext.php
namespace FSFramework\model;
class tarif_articulos_ext extends \fs_model {   // parent::__construct('tarif_articulos')
    public function get($referencia); exists(); save(); delete();
    public function update_field($referencia, $field, $value);
    public function get_extra_fields($referencia);
}
```

No method signature, table name, column list or SQL string changes; the
`factory_for_import()` create-path defaults (`factory_precio` /
`factory_tarif_ext`) and the `IMAGES_DIR`/raw-SQL behavior of sibling models are
untouched.

### Testing Strategy

| AME | Layer | What is proven | Test file / method |
|---|---|---|---|
| AME-01 | Unit/source + class contract | `tarif_articulo` + `tarif_articulos_ext` resolve only from `plugins/catalogo_core/model/`, keep `namespace FSFramework\model;`, `class tarif_articulo extends \FSFramework\model\articulo` (no own table) and `parent::__construct('tarif_articulos')`; the schema exists only at `plugins/catalogo_core/model/table/tarif_articulos.xml`; `class_exists(FQCN, false)` + `is_subclass_of(\fs_model::class)` | `tests/ArticuloModelosExtOwnershipTest.php` — `test_moved_models_live_only_in_catalogo_core`, `test_moved_model_xml_schema_lives_only_in_catalogo_core`, `test_tarif_articulo_extends_core_articulo_without_own_table` |
| AME-01 | Unit/source (bootstrap) | `Init::ensureArticuloExtTables()` is `public static`, requires the catalogo_core path behind `is_file`, instantiates behind `class_exists(..., false)` + `is_subclass_of(..., \fs_model::class)`, touches the `familias` FK destination first, is wired from `init()` after `ensureArticuloDetalleTables()` and from `upgrade()`, and does **not** mutate the frozen `ensureArticuloTarifaTables()`/`ensureArticuloDetalleTables()` bodies | `ArticuloModelosExtOwnershipTest` — `test_catalogo_core_bootstraps_the_article_ext_table_standalone` |
| AME-02 | Unit/source | No `tarif_descripcion` class file exists in either plugin; `tarifario_init.php` no longer references/instantiates it; the canonical `articulo_descripcion` model + `articulo_descripciones.xml` remain in catalogo_core | `ArticuloModelosExtOwnershipTest` — `test_tarif_descripcion_wrapper_is_deleted_and_canonical_description_remains`, `test_tarifario_boot_no_longer_references_the_deleted_wrapper` |
| AME-03 | Grep gate + suite | Zero `plugins/tarifario/model/tarif_articulo.php` / `tarif_articulos_ext.php` requires remain in tarifario production + tests; the catalogo_core paths are present in the three production consumers and the two tests; the FQCNs are unchanged; both plugin suites green | `ArticuloModelosExtOwnershipTest` — `test_zero_tarifario_model_requires_for_the_moved_models`, `test_moved_paths_are_exact_and_do_not_swallow_prefix_siblings`; tarifario `tests/Model/TarifArticuloFactoryForImportTest.php` + `tests/Services/ExcelImportWizardServiceTest.php` (updated requires, unchanged assertions) |
| AME-04 | Unit/grep | The three moved paths exist only in catalogo_core and their tarifario counterparts are absent (no dual class); `tarif_articulos.xml` is the only `tarif_articulos` schema in either plugin; catalogo_core production has zero `plugins/tarifario/` / `@tarifario/` hits | `ArticuloModelosExtOwnershipTest` — `test_moved_models_live_only_in_catalogo_core`, `test_moved_model_xml_schema_lives_only_in_catalogo_core`, `test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_models` |
| AME-05 | Behavior (tarifario, existing) | `tarif_articulo::factory_for_import()` still returns a `tarif_articulo` with `factory_precio` + `factory_tarif_ext` attached; `tarif_articulos_ext` behavior against `tarif_articulos` unchanged; RBAC listener + `tarif_grupo_articulo` stay in tarifario and the listener stays live on `ArticlePermissionFilterEvent::NAME` | tarifario `tests/Model/TarifArticuloFactoryForImportTest.php`, `tests/Services/ExcelImportWizardServiceTest.php` (repointed requires, assertions intact); `tests/Integration/HookRegistrationTest.php` **KEEP green (no edits)** |
| AME-06 | Suite gate + boundary update | The five locked tests stay green with assertions intact; no test asserts the moved models stay in tarifario; catalogo_core ≥ **602/2343** and tarifario green | `tests/VentasArticulosControllerTest.php`, `tests/VentasArticuloControllerTest.php`, `tests/Integration/CatalogoCoreHookMarkersTest.php`, tarifario `tests/Integration/HookRegistrationTest.php`, `tests/Integration/VentasArticulosQuickCreateGateCompositionTest.php` (**KEEP**); `tests/ArticuloListaCanonicaOwnershipTest.php` + `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` (**UPDATED**) |

Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
(baseline **602 tests / 2343 assertions**), `ddev exec php vendor/bin/phpunit -c
plugins/tarifario/phpunit.xml` (**180 / 637**, Skipped 3), and the root
`ddev exec php vendor/bin/phpunit --testsuite Plugins` (zero new failures vs the
known pre-existing 10).

### Threat Matrix

| Boundary | Applicability | Expected safe behavior | Failure behavior | RED test |
|---|---|---|---|---|
| Dual-class autoload collision | Applicable | Each moved class file/XML exists in exactly one plugin; the catalogo_core commit lands before the tarifario removal | Fatal `Cannot declare class` / stale class wins the autoloader | `ArticuloModelosExtOwnershipTest::test_moved_models_live_only_in_catalogo_core` (exact paths + `class_exists`) |
| Grep-gate false negative (prefix collision) | Applicable | Gates anchor on exact paths + `class\s+<name>\b` tokens, never bare `tarif_articulo`; prefix siblings (`tarif_articulo_precio`, `tarif_articulo_imagen`, `tarif_articulo_edit`) are excluded from the moved-path list | A half-moved class passes the gate undetected | `ArticuloModelosExtOwnershipTest::test_moved_paths_are_exact_and_do_not_swallow_prefix_siblings` |
| Standalone DDL / FK failure | Applicable | Bootstrap is `is_file`/`class_exists`/`is_subclass_of`-guarded, ensures `familias` first, and runs in `try/catch` + `error_log`; failure never fatals the boot; second run is a no-op | Standalone catalogo_core boot fatals or leaves `tarif_articulos` missing | `ArticuloModelosExtOwnershipTest::test_catalogo_core_bootstraps_the_article_ext_table_standalone` |
| Frozen-contract mutation | Applicable | `ensureArticuloTarifaTables()` (WU-1) and `ensureArticuloDetalleTables()` (WU-2) bodies remain byte-stable; the new model lives only in `ensureArticuloExtTables()` | Pinned ownership contracts (`ArticuloTarifaPrecioOwnershipTest`, `ArticuloDetalleCanonicoOwnershipTest`) break | `ArticuloModelosExtOwnershipTest` frozen-body assertions; `ArticuloTarifaPrecioOwnershipTest` + `ArticuloDetalleCanonicoOwnershipTest` **KEEP green** |
| Deleted-wrapper reference | Applicable | No `tarif_descripcion` class file or boot reference remains; deps resolve to `articulo_descripcion` | Fatal class-not-found in tarifario boot | `ArticuloModelosExtOwnershipTest::test_tarifario_boot_no_longer_references_the_deleted_wrapper` |
| SQL injection (moved models) | Applicable | Moved models keep their `var2str()`/quote patterns byte-for-byte; no new SQL is authored | Injected SQL through `referencia`/`field` | ownership source contract + existing model coverage (`TarifArticuloFactoryForImportTest`) |
| RBAC / authorization | Applicable | Listener + `tarif_grupo_articulo` stay in tarifario; catalogo_core adds no RBAC consumer | Privilege escalation / inverted host-guest contract | tarifario `Integration/HookRegistrationTest.php` (KEEP) |
| CSRF / file upload / shell / subprocess / VCS-PR automation | N/A | No controller, endpoint, form, file-upload, shell or automation surface changes in WU-4 | — | — |

### Migration / Rollout

No data migration, no table rename, no schema or API change, no `fs_page`
retirement, no Composer dependency (so no `vendor/` commit is required; plugin
`vendor/` stays versioned per `AGENTS.md`). Rollout is the two-commit atomic
move (AD-W4-7): the catalogo_core commit adds the two moved models + XML, the
`ensureArticuloExtTables()` bootstrap/wiring, the new ownership test and the two
boundary-test updates; the tarifario commit `git rm`s the moved files plus the
`tarif_descripcion` wrapper, removes the boot block, repoints the six requires
and updates the two test requires. No deploy between the two.
`Init::ensureArticuloExtTables()` creates `tarif_articulos` idempotently on
standalone installs (`fs_model` only creates when missing); `tarifario_init.php`
stays an idempotent no-op. Plugin `fsframework.ini` version bumps are deferred
to the `fsframework-plugin-release` skill at release time. Post-rollout cache
clear: `CacheManager::clearAll()` + `tmp/twig_cache`.

**Intended behavior change (documented, not silent):** none at runtime. WU-4 is
a pure ownership relocation; the only observable deltas are the source file
locations (autoloader-resolved) and the removal of the unused
`tarif_descripcion` wrapper.

**Rollback (AD-W4-7):** revert the two commits in reverse order (tarifario,
then catalogo_core) and clear the Twig cache. The tarifario-owned models, the
wrapper and the previous require paths are restored byte-for-byte; no
data/table/`fs_page` rows were touched (the `tarif_descripciones` legacy table
included).

### Open Questions

- [ ] `tarif_articulo` keeps article-domain helpers (`search_tarifario`, `get_imagenes`, `get_opcionales`, per-tarifa price helpers) that overlap the canonical `articulo`/`VentasArticulo` surface after WU-2/WU-3; whether to fold them into core `articulo` or retire the now-unused ones is a cleanup for the WU-8 audit (recommended default: keep byte-stable through WU-4; no consumer is removed here).
- [ ] `tarif_articulos` (legacy ext table with `nombre`/`precio`/`codfamilia`/`imagen` duplicated from core `articulos`) is retained untouched; a deprecation/retirement plan for it and its XML belongs to WU-8 (recommended default: keep; WU-4 is a move).
- [ ] `tarif_descripciones.xml` (dead legacy table, already mapped to `articulo_descripciones` by `CatalogLegacyTableMigration`) is explicitly out of scope; confirm its removal (if any) in the WU-8 audit (recommended default: WU-8).
- [ ] Standalone `tarif_articulos` creation is proven at source-contract level (`ArticuloModelosExtOwnershipTest`); the DB-backed boot smoke is owned by `sdd-verify` (recommended default: include `ensureArticuloExtTables()` in the WU-4 dev-DB smoke checklist alongside the WU-1/WU-2/WU-3 tables).
- [ ] `ArticuloListaCanonicaOwnershipTest`'s class docblock currently describes the WU-4 boundary as "stay tarifario-only"; WU-4 rewrites it to describe catalogo_core ownership (recommended default: rewrite in the same slice per AD-W4-5).

## WU-5 — `catalogo_core` owns the catalog manager; `tarif_catalogo_view` retired

> **Scope.** Fifth slice of the program (depends on WU-4, applied in the working
> tree). Delta spec: `specs/catalogo-gestion/spec.md` (CAT-01..CAT-08), following
> **Option A** from the exploration (§3.5). The whole tarifario catalog manager
> (`tarif_catalogo_view` + the five `tarif_catalogo*` models/tables + their views,
> partials and JS) moves into `catalogo_core` as the canonical page
> **`ventas_catalogo`** (`VentasCatalogo` on `PageController`, menu folder
> `catalogo`) and is **retired with no alias** from tarifario. No table rename, no
> schema/API change, no Composer dependency. Runner:
> `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
> (baseline **611 tests / 2422 assertions**; tarifario **180 / 637**, Skipped 3).
> Strict TDD; both plugin suites plus the root `Plugins` suite green after the
> slice. WU-7 boundary: Excel/JSON wizard internals and the
> `process_excel_wizard.php` `descartadas_url` repoint stay out (CAT-08).

### Technical Approach

WU-5 is the same **atomic two-repo move** as WU-2/WU-4 (AD-W2-10, AD-W4-7): a
`catalogo_core` commit (moved models/XML/views/partials/JS + the reclassed
controller + bootstrap + tests) immediately followed by a `tarifario` commit
(`git rm` of every moved asset + test repoints), with **no deploy between**.
Because WU-5 adds before it removes, catalogo_core lands first.

The 5,189-line controller is **reclassed, not rewritten**: `class tarif_catalogo_view
extends tarif_controller` becomes `VentasCatalogo extends PageController`
(AD-W5-1) and the legacy `private_core()` body becomes `privateCore(&$response,
$user, $permissions)` with its first line (`parent::private_core()`) replaced by
`$this->initCatalogoState()`. The five `tarif_catalogo*` models are byte-identical
copies (self-contained `extends \fs_model`, no requires); table names stay
byte-stable. The only production changes are the reclass/state extraction, the
soft RBAC seam (no `tarif_controller`, no `plugins/tarifario/` literal), the
htmx 4 + Alpine hygiene pass on the views, one bootstrap method and one
`fs_page` retirement on `Init.php`.

### Architecture Decisions

| # | Decision | Choice | Alternatives rejected | Rationale |
|---|----------|--------|----------------------|-----------|
| AD-W5-1 | Canonical page identity + reclass | New page name **`ventas_catalogo`**; PSR-4 class `FSFramework\Plugins\catalogo_core\Controller\VentasCatalogo` in `Controller/VentasCatalogo.php`; legacy wrapper `controller/ventas_catalogo.php` (`class ventas_catalogo extends \…\Controller\VentasCatalogo {}`, `require_once` of the PSR-4 file) is the registered slug; `getPageData()` = `['name' => 'ventas_catalogo', 'title' => 'Catálogo', 'menu' => 'catalogo', 'showonmenu' => true, 'ordernum' => 130]`; ctor `parent::__construct('VentasCatalogo'); $this->setTemplate('ventas_catalogo');`. The controller `require_once 'plugins/tarifario/extras/tarif_controller.php'` is **removed**; the class no longer extends `tarif_controller` and declares no `private_core()`. The existing body becomes `privateCore(&$response, $user, $permissions)` (modern signature) and keeps every other method/name byte-stable. The `tarif_catalogo_view` slug is retired with no alias (CAT-02/CAT-05). | Keep the `tarif_catalogo_view` slug on catalogo_core (Option B — `tarif_` naming debt + inconsistent canonical experience); fold the tree into `ventas_articulos` (Option C — merges two very different UIs, high risk); keep `fbase_controller` as base (no PSR-4 page registry, no `getPageData()`; keeps a legacy base while the spec mandates `PageController`) | Option A (exploration §3.5); mirrors the opcionales unification (`ventas_opcionales` retired `tarif_opcionales`) and the WU-2/WU-3 canonical pages. `Html::render()` resolves templates from active plugin `View/` dirs and `Controller::run()` only renders when `$this->template !== false`, so the existing `echo/header/json` fragment paths keep working. |
| AD-W5-2 | Ported per-tarifa state + soft RBAC seam | New additive trait `extras/VentasCatalogoStateTrait.php` (mirror of `TarifarioOpcionalStateTrait`, which stays untouched): loads `$idiomas`/`$codidioma` from `catalogo_idioma`, `$tarifas` (all active) from `tarif_tarifa`, admin flag `$is_admin = $this->user->admin`, then `loadTarifasAccesibles()` + `initUserPermissions()` ported verbatim except `$this->grupo_usuario_model->get_rol_en_tarifa(...)` → a soft `roleEnTarifa(string $codtarifa): string\|false` helper: `$class = 'FSFramework\\model\\tarif_grupo_usuario'; if (!class_exists($class)) return false; (new $class())->get_rol_en_tarifa($codtarifa, (string) $this->user->nick)`. The trait also exposes `ensureCatalogoTablesOnce()` delegating to `Init::ensureCatalogoTables()`. All role/permission semantics (`gestor`/`editor`/`revisor`/`visualizador`, notes flags, `has_access`) are preserved; with tarifario inactive non-admins fail closed (`has_access = false`, restricted view) while the page still renders (CAT-02 "view always allowed"). | Keep `tarif_controller` as base (violates CAT-02, forces a `plugins/tarifario/` require); hard-require `tarif_grupo_usuario` (CAT-06 violation); move the RBAC role tables into catalogo_core (inverts the host/guest contract, ~1.5k LOC — exploration §3.4 rejected) | The soft `class_exists` seam is the accepted OUM-10 pattern (`tarif_opcional_tab::puede_editar_opcional()`), and `TarifarioOpcionalStateTrait` is the proven state-extraction precedent. |
| AD-W5-3 | Legacy-helper parity | No `fbase_controller` base is needed: the catalog never calls `fbase_paginas()`/`simbolo_divisa()`/`requireCsrf()`/`pre_private_core()`; pagination is manual `offset/limit` (htmx) and currency is the class's own `format_precio()` (hardcoded `€`, byte-stable). `PageController` supplies `$this->user`, `$this->db`, `$this->request`, `$this->template`/`setTemplate()`, `$this->url()`, `new_error_msg/new_message`, and `validateFormToken()`. One shim is added to the trait/class: `protected function isCsrfValid(): bool` (mirrors `fs_controller::validateCsrf()`: false for non-POST; otherwise `CsrfManager::isValid()` over the `_csrf_token`/`_token` body field or the `X-CSRF-TOKEN` header, honoring `FS_CSRF_SOFT`), because the moved `preview_excel()` calls `$this->isCsrfValid()` and the modern base only has `validateFormToken()`. `$_REQUEST` superglobals keep working unchanged. | Extend `fbase_controller` (fails CAT-02's `PageController` mandate); rewrite every `isCsrfValid()` call site to `validateFormToken()` (loses the header path htmx uses); add a `PluginController` base (new framework surface, out of scope) | `isCsrfValid()` is the exact behavior the Excel wizard relies on (header + body token, soft mode); a behavior-preserving shim is the smallest authorized delta. |
| AD-W5-4 | Exact model moves | Move **verbatim** into `catalogo_core/model/` (+ `model/table/`): `tarif_catalogo.php` (`tarif_catalogos`), `tarif_catalogo_articulo.php` (`tarif_catalogo_articulo`), `tarif_catalogo_def.php` (`tarif_catalogo_defs`), `tarif_catalogo_def_articulo.php` (`tarif_catalogo_def_articulo`), `tarif_catalogo_def_familia.php` (`tarif_catalogo_def_familia`) and the five matching XMLs; `git rm` the tarifario paths. FQCNs (`FSFramework\model\*`) and `parent::__construct('<table>')` names stay byte-stable; no class file/XML in both plugins. No hard `require_once` of these models exists in tarifario production (only `tarifario_init.php` `class_exists` blocks, kept as idempotent no-ops per the WU-4 precedent), so no require repoint is needed. | Rename the tables/classes (data-at-rest + SQL break); leave one model in tarifario (half-moved, CAT-06); move the `tarif_catalogo_view` controller only and leave models (WCAT? no — models are the ownership core, CAT-01) | CAT-01. Byte-stable FQCN + table names keep every raw-SQL/FK reference (`ca_tarif_catalogo_articulo_catalogo`, etc.) valid with zero SQL change. |
| AD-W5-5 | Fragment / action endpoint parity | All `action=` entry points keep their names, methods, response shapes and swap targets (full map in **Interfaces/Contracts**). Fragment templates rename to `ventas_catalogo_articulos`, `ventas_catalogo_articulos_agrupados`, `ventas_catalogo_search` (`$this->template = '…'`); the `hx-get="{{ fsc.url() }}&…"` URLs keep working because the modern `url()` returns `index.php?page=ventas_catalogo`. JSON actions (`toggle_en_*`, `reorder_articulos`, `inline_edit_articulo`, legacy `get_articulos`/`search`, notes CRUD, `preview_excel`/`get_preview`) keep their envelopes; download actions keep streaming. `process_action`'s `default` JSON error is unchanged. | Add a separate rows-fragment route (extra surface; parity already exists); rename the `action=` names (`ventas_catalogo_*`) — would break every JS/`data-ajax-url` consumer and CAT-03 | CAT-03 requires the exact fragment shapes and swap targets; keeping action names freezes the JS contract. |
| AD-W5-6 | htmx 4 + Alpine hygiene | The view keeps its single `htmx.boot()` **host ownership** (it is the host, not a pane) and adds `{% import 'Macro/Alpine.html.twig' as alpine %}` + `{{ alpine.boot() }}`. One nonce'd classic script behind `document.addEventListener('alpine:init', …)` registers `Alpine.data('catalogoShell', …)`; the tree/toolbar/notes/images entry points move their inline `on*=` handlers to `x-on:` colon bindings delegating to the existing JS globals; `[x-cloak]`/`x-text` used. `\|raw` is removed: the `window.catalogoConfig` block is replaced by a controller-built `public array $catalogo_config` emitted as `data-catalogo-config="{{ fsc.catalogo_config\|json_encode\|e('html_attr') }}"` on `#catalogo-main-region` and parsed once by the nonce'd script. Mutations are POST-only: the four `$.ajax({type:'POST'})` fan-outs in `catalogo-main.js` (`reorder_articulos`, `toggle_en_tarifa`, `toggle_en_catalogo`, `inline_edit_articulo`) become `htmx.ajax('POST', …)` with the same `action=`/payload/JSON envelope, handled by the existing `htmx:after:request` shim; `hx-delete`/`hx-put`/`hx-patch` are banned. Colon htmx event names (`htmx:after:swap`, `htmx:after:request`) and the v2-name ban are preserved; `bootbox` is already absent. **Residual (documented)**: the deep jQuery DOM layer inside the ES modules (`$(...)`, Sortable, `fadeOut`) is preserved for CAT-03 parity; the full ES-module de-jQuery rewrite is WU-8 debt. | Ban all inline `on*=`/jQuery in one pass (42 handlers + 4 JS files; out of a single WU's budget/risk — WU-8); keep `\|raw` (XSS/CSP risk, CAT-04); convert mutation endpoints to HTML swaps (breaks the JSON contracts, CAT-03) | Mirrors the accepted WU-3 `CatalogoArticuloListHtmxContractTest` interpretation (view-level hygiene, v2 ban, `Alpine.data` behind `alpine:init`, `hx-post` mutations, nonce) while honoring CAT-03 behavior parity. |
| AD-W5-7 | Views / partials / JS moves | Move verbatim (renaming only the four top-level templates) into `catalogo_core`: the 12 `View/partials/catalogo/*` and the 9 `View/js/catalogo/*` keep their relative names, so `{% include 'partials/catalogo/…' %}` and the ES-module relative `import './x.js'` keep resolving (global Twig loader over active plugin `View/` dirs; `@catalogo_core` self-registered). The JS load mechanism changes only the URL: `<script type="module" src="plugins/tarifario/View/js/catalogo/index.js">` → `plugins/catalogo_core/View/js/catalogo/index.js`. `View/Macro/TarifarioComponents.html.twig` is catalogo_core-owned already; its `:271` link `page=tarif_catalogo_view` → `page=ventas_catalogo&codtarifa=…`. | Keep `tarif_catalogo_*` template names in catalogo_core (CAT-05 names them as retired); namespace the includes (churn, breaks relative imports) | CAT-05 deletes the four named views/partials/JS from tarifario; renaming the top-level templates avoids a retired slug surviving as a template name while the relative surface stays byte-stable. |
| AD-W5-8 | Standalone bootstrap + `fs_page` retirement | Add `public static function ensureCatalogoTables(): void` to `catalogo_core/Init.php` (mirror of `ensureArticuloExtTables()`, AD-W4-9): `require_once base/fs_model.php`; FK-safe order `tarif_catalogo` (`tarif_catalogos`) → `tarif_catalogo_def` (`tarif_catalogo_defs`) → `tarif_catalogo_articulo` (FK → `tarif_catalogos`) → `tarif_catalogo_def_articulo` (FK → `tarif_catalogo_defs`) → `tarif_catalogo_def_familia` (FK → `tarif_catalogo_defs`), each behind `is_file` + `class_exists($fqcn, false)` + `is_subclass_of(\fs_model::class)`. Wire in `init()` after `ensureArticuloExtTables()` (own `try/catch` + `error_log`) and in `upgrade()` after it. Add idempotent `private static function retireTarifCatalogoViewPage()` (`fs_page::get('tarif_catalogo_view')` → gated `->delete()`) wired in `upgrade()` in its own `try/catch`. **The WU-1/WU-2/WU-4 frozen bodies stay untouched.** | Fold the five tables into `ensureArticuloExtTables()` (mutates a pinned body); skip the bootstrap (standalone catalogo_core never creates `tarif_catalogos`); reuse `ensureCatalogTables()` (private, `model/core/` only — the moved models live in `model/`) | CAT-01 scenario 2 + CAT-05 scenario 3. `fs_model` creates only when missing, so a second boot/`upgrade()` is a no-op. |
| AD-W5-9 | Cross-WU couplings softened (no `plugins/tarifario/` literal) | Remove the three hard requires of tarifario RBAC models (`tarif_grupo_articulo`, `tarif_grupo_rol`, `tarif_grupo_tarifa`) and the `require_once 'plugins/tarifario/model/tarif_revision_nota.php'`; resolve them by FQCN through `fs_model_autoloader` and guard with `class_exists` (`loadTarifarioModel(string $fqcn): ?object`), degrading gracefully (group columns omitted / notes counters at 0, notes flags false) when tarifario is inactive. `use FSFramework\model\tarif_revision_nota;` stays (FQCN, not a `plugins/tarifario/` literal). The two `use FSFramework\Plugins\tarifario\Services\{ExcelHierarchyService,ExcelRowUpdater}` imports stay (CAT-08: the catalog page keeps exercising the rich-wizard entry points) with a `class_exists` guard returning a JSON error envelope when tarifario is inactive. | Hard-require tarifario classes (CAT-06 grep gate fails); move `tarif_revision_nota`/RBAC groups now (WU-6/WU-8 scope; drags the listener + role tables); drop the Excel entry points (violates CAT-08) | CAT-06 + CAT-08; mirrors `puede_editar_opcional()`'s fail-closed soft seam and keeps WU-6/WU-7 boundaries intact. |
| AD-W5-10 | Repoints + writer-audit paths | Repoint `catalogo_core/View/Macro/TarifarioComponents.html.twig:271` → `page=ventas_catalogo&codtarifa=…`; repoint the three tarifario test paths that audit the moved controller: `FamiliaOverrideRemovalTest` (→ `plugins/catalogo_core/Controller/VentasCatalogo.php`, the `new familia()` count/read-only assertions unchanged), `TarifFamiliaWriteRetirementTest` (writer #3 path), `LegacyImportRegressionTest` (URL → `page=ventas_catalogo&action=import_excel_chunk`). **No change** to `process_excel_wizard.php:477` `descartadas_url` (WU-7). No inbound `page=tarif_catalogo_view` link survives in either plugin (CAT-05 scenario 2). | Leave `TarifarioComponents` pointing at the retired slug (broken button); move the `descartadas_url` repoint into WU-5 (CAT-08 forbids); leave writer-audit paths (tests read a deleted file) | CAT-05 + CAT-06. The writer audits must follow the moved controller byte-for-byte (AD-W5-4 keeps it a move), and the SSE descartadas link is the documented WU-7 item. |
| AD-W5-11 | Test ownership + locked contracts | Move `plugins/tarifario/tests/Controller/TarifCatalogoHtmxContractTest.php` and `TarifCatalogoOpcionalMasterExportTest.php` → `plugins/catalogo_core/tests/Controller/` (namespace `Tests\CatalogoCore\Controller`, class names kept per CAT-07), retargeted to `Controller/VentasCatalogo.php` and `page=ventas_catalogo`; update their `setUpBeforeClass` chain for `PageController` and the `privateCore` signature; TCP-05/06/07 fragment/bridge contracts stay, the retired-slug assertions go, TCP-08 is re-expressed for the modern base. Add `tests/CatalogoManagerOwnershipTest.php` (CAT-01/02/05/06/07) and extend `tests/InitUpgradeTest.php` (CAT-01 bootstrap + CAT-05 retirement) and `tests/ArticuloModelosExtOwnershipTest.php` (repoint the `tarif_catalogo_view.php` require-map entry to `Controller/VentasCatalogo.php`). **KEEP unedited and green**: `Integration/CatalogoCoreHookMarkersTest`, `ArticuloListaCanonicaOwnershipTest` (becomes the grep gate for the moved controller), `VentasArticulosControllerTest`, `VentasArticuloControllerTest`, tarifario `Integration/HookRegistrationTest`. New tests live in catalogo_core (the new owner). | Keep the new tests in tarifario (plugin-local ownership: catalogo_core owns the code); edit the locked tests to fit (CAT-07 forbids); assert the retired slug anywhere (CAT-06/CAT-07) | House convention "tests travel with their code"; CAT-07 suite floor **611 / 2422**. |
| AD-W5-12 | Atomic landing + rollback | Two coordinated commits: **catalogo_core first** (moved models/XML/views/partials/JS + reclassed controller + trait + bootstrap + tests), **tarifario immediately after** (`git rm` of every moved asset, retiring the two moved test files, repointing the three audit tests), no deploy between. **Rollback**: revert the two commits in reverse order (tarifario, then catalogo_core) and clear the Twig cache; the restored tarifario controller recreates the `tarif_catalogo_view` `fs_page` row on next access. No data/table/Composer change survives; `ensureCatalogoTables()` only created tables that remain (data-preserving). | Separate PRs (broken runtime window); per-file manual restore | Same mechanics as WU-2/WU-4; the parent repo tracks no plugin bytes; the move is code-only. |
| AD-W5-13 | RBAC boundary stays in tarifario | `ArticlePermissionListener`, `tarif_grupo_articulo` and the RBAC role tables (`tarif_grupo_usuario/rol/tarifa`, `tarif_tarifa_rol`) remain in tarifario; the listener stays live on catalogo_core's neutral `ArticlePermissionFilterEvent::NAME`. catalogo_core owns no RBAC consumer: the catalog page resolves roles only through the fail-closed `class_exists` seam (AD-W5-2). | Move the listener/role tables with the catalog (inverts the documented host/guest contract; exploration §3.4 rejected) | CAT-06 scenario 2; tarifario `Integration/HookRegistrationTest` stays green unedited as the machine check. |
| AD-W5-14 | No half-moved state + grep gate | Every moved class file/XML/template/partial/JS exists in exactly one plugin; after the slice catalogo_core production has zero `plugins/tarifario/` and `@tarifario/` literals for the absorbed surface (the `ArticuloListaCanonicaOwnershipTest` gate now covers the moved `Controller/`, `View/`, and `extras/` trees); `fs_model_autoloader` resolves `FSFramework\model\tarif_catalogo*` only from catalogo_core. | Big-bang move of all remaining WUs | R2/R6/R12 mitigation; the ownership + grep tests are the machine check. |

### Data Flow

    page=ventas_catalogo ──→ controller/ventas_catalogo.php (slug wrapper)
                                │
                                └─→ Controller\VentasCatalogo::run()  [PageController]
                                       privateCore() → initCatalogoState()  [soft RBAC]
                                       ├─ action=… → process_action() → JSON | fragment template
                                       └─ default   → load_catalogo()  → View/ventas_catalogo.html.twig
                                                                          (+ partials/catalogo/* + js/catalogo/*)

    htmx fragment:  hx-get  index.php?page=ventas_catalogo&action=htmx_articulos&codfamilia=…  → HTML swap
                    hx-post htmx.ajax('POST', …action=toggle_en_tarifa|reorder_articulos|…)      → JSON
    bootstrap:      Init::init()/upgrade() → ensureCatalogoTables() → 5 tables (idempotent, FK-safe)
    retirement:     Init::upgrade() → retireTarifCatalogoViewPage() → fs_page row deleted

### File Changes

#### `plugins/catalogo_core/` (commit 1)

| File | Action | Description |
|------|--------|-------------|
| `model/tarif_catalogo.php` | Create (moved) | Verbatim move; `parent::__construct('tarif_catalogos')`, FQCN unchanged |
| `model/tarif_catalogo_articulo.php` | Create (moved) | Verbatim move; `tarif_catalogo_articulo` |
| `model/tarif_catalogo_def.php` | Create (moved) | Verbatim move; `tarif_catalogo_defs` |
| `model/tarif_catalogo_def_articulo.php` | Create (moved) | Verbatim move; `tarif_catalogo_def_articulo` |
| `model/tarif_catalogo_def_familia.php` | Create (moved) | Verbatim move; `tarif_catalogo_def_familia` |
| `model/table/tarif_catalogos.xml` … `tarif_catalogo_def_familia.xml` | Create (moved, 5) | Verbatim schemas (PKs, UNIQUEs, `id_catalogo` FKs) |
| `Controller/VentasCatalogo.php` | Create (moved + reclassed) | 5,189-line body: `extends PageController`, `getPageData()` (`ventas_catalogo`/`catalogo`), `privateCore(&$response,$user,$permissions)`, `use VentasCatalogoStateTrait`; removed `tarif_controller` require; repointed/softened tarifario couplings (AD-W5-9); template renames (AD-W5-5); `isCsrfValid()` shim (AD-W5-3); `$catalogo_config` carrier (AD-W5-6) |
| `controller/ventas_catalogo.php` | Create | Legacy slug wrapper `class ventas_catalogo extends \…\Controller\VentasCatalogo {}` + `require_once` of the PSR-4 file |
| `extras/VentasCatalogoStateTrait.php` | Create | Per-tarifa state + `loadTarifasAccesibles()` + `initUserPermissions()` + soft `roleEnTarifa()` + `ensureCatalogoTablesOnce()` (AD-W5-2) |
| `View/ventas_catalogo.html.twig` | Create (moved + updated) | Host view; `htmx.boot()` + `alpine.boot()`; `Alpine.data('catalogoShell')` behind `alpine:init`; `data-catalogo-config` (no `\|raw`); inline `on*`→`x-on:`; JS `src` → `plugins/catalogo_core/…/js/catalogo/index.js`; `partials/catalogo/*` includes unchanged |
| `View/ventas_catalogo_articulos.html.twig` | Create (moved + updated) | Fragment; `hx-target="#tbody-…"` / `hx-swap="beforeend"` parity |
| `View/ventas_catalogo_articulos_agrupados.html.twig` | Create (moved + updated) | Grouped fragment |
| `View/ventas_catalogo_search.html.twig` | Create (moved + updated) | Search fragment |
| `View/partials/catalogo/*` | Create (moved, 12) | `toolbar`, `styles`, `panel_notas`, 9 modals — verbatim + hygiene pass |
| `View/js/catalogo/*` | Create (moved, 9) | `catalogo-main`, `index`, `excel-*`, `images-*`, `json-import`, `revision-notas`, `utils` — verbatim + htmx-POST/`Alpine.data` pass |
| `View/Macro/TarifarioComponents.html.twig` | Modify | `:271` link → `page=ventas_catalogo&codtarifa=…` (AD-W5-10) |
| `Init.php` | Modify | Add `ensureCatalogoTables()` + `retireTarifCatalogoViewPage()`; wire both (AD-W5-8); frozen bodies untouched |
| `tests/CatalogoManagerOwnershipTest.php` | Create | CAT-01/02/05/06/07 ownership, table names, page identity, retirement, grep gate |
| `tests/Controller/TarifCatalogoHtmxContractTest.php` | Create (moved) | CAT-03/04 fragment parity + htmx/Alpine hygiene, retargeted |
| `tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` | Create (moved) | CAT-03 master-state export contract, retargeted |
| `tests/InitUpgradeTest.php` | Modify | Add `upgradeRetiresTarifCatalogoViewPageIdempotently` + `ensureCatalogoTables()` wiring (CAT-01/05) |
| `tests/ArticuloModelosExtOwnershipTest.php` | Modify | Require-map entry `tarif_catalogo_view.php` → `Controller/VentasCatalogo.php` |

#### `plugins/tarifario/` (commit 2)

| File | Action | Description |
|------|--------|-------------|
| `model/tarif_catalogo*.php` (5) | Delete | `git rm` after the move (CAT-01) |
| `model/table/tarif_catalogo*.xml` (5) | Delete | `git rm` after the move |
| `controller/tarif_catalogo_view.php` | Delete | Retired with no alias (CAT-05) |
| `View/tarif_catalogo_view.html.twig` + `tarif_catalogo_articulos*.html.twig` + `tarif_catalogo_search.html.twig` (4) | Delete | Retired with no alias (CAT-05) |
| `View/partials/catalogo/*` (12) | Delete | Moved to catalogo_core (CAT-05) |
| `View/js/catalogo/*` (9) | Delete | Moved to catalogo_core (CAT-05) |
| `extras/tarifario_init.php` | No edit | The five `class_exists` bootstrap blocks stay as idempotent no-ops (catalogo_core boots first as a declared dependency; WU-4 precedent) |
| `tests/Controller/TarifCatalogoHtmxContractTest.php`, `TarifCatalogoOpcionalMasterExportTest.php` | Delete | Moved to catalogo_core (AD-W5-11) |
| `tests/Integration/FamiliaOverrideRemovalTest.php` | Modify | Audited site path → `plugins/catalogo_core/Controller/VentasCatalogo.php` (assertions unchanged) |
| `tests/Integration/TarifFamiliaWriteRetirementTest.php` | Modify | Writer #3 path → `plugins/catalogo_core/Controller/VentasCatalogo.php` |
| `tests/Integration/LegacyImportRegressionTest.php` | Modify | URL → `page=ventas_catalogo&action=import_excel_chunk` |
| `process_excel_wizard.php` | No edit | `:477` `descartadas_url` stays `page=tarif_catalogo_view` — explicit WU-7 item (AD-W5-10) |

### Interfaces / Contracts

**Canonical page (CAT-02):**

```php
// plugins/catalogo_core/Controller/VentasCatalogo.php
namespace FSFramework\Plugins\catalogo_core\Controller;
class VentasCatalogo extends \FSFramework\Controller\PageController {
    use \VentasCatalogoStateTrait;              // extras/VentasCatalogoStateTrait.php
    public array $catalogo_config = [];         // replaces the inline window.catalogoConfig block
    public function getPageData(): array;        // name=ventas_catalogo, menu=catalogo
    public function privateCore(&$response, $user, $permissions): void;  // initCatalogoState() + former private_core() body
    protected function isCsrfValid(): bool;      // AD-W5-3 shim
}
// plugins/catalogo_core/controller/ventas_catalogo.php
class ventas_catalogo extends \FSFramework\Plugins\catalogo_core\Controller\VentasCatalogo {}
```

**Fragment / action endpoint parity (CAT-03) — names, methods, templates and swap targets frozen:**

| `action=` | Method | Response | Template / envelope | Swap target (consumer) |
|---|---|---|---|---|
| `htmx_articulos` | `htmx_articulos()` | HTML | `ventas_catalogo_articulos` | `#tbody-{codfamilia}` `innerHTML` (expand) / `beforeend` (load-more) |
| `htmx_articulos_agrupados` | `htmx_articulos_agrupados()` | HTML | `ventas_catalogo_articulos_agrupados` | `#grouped-view-{codfamilia}` `innerHTML` |
| `htmx_search` | `htmx_search()` | HTML | `ventas_catalogo_search` | search region (no live consumer; preserved) |
| `toggle_en_tarifa` / `toggle_en_catalogo` | `ajax_toggle_en_*()` | JSON | `{success, en_tarifa\|en_catalogo}` | `htmx.ajax('POST')` + `htmx:after:request` shim |
| `reorder_articulos` | `ajax_reorder_articulos()` | JSON | `{success, message}` | save-order bar (htmx POST) |
| `inline_edit_articulo` | `ajax_inline_edit_articulo()` | JSON | `{success, …}` | inline editor (htmx POST) |
| `get_articulos` / `search` | `ajax_get_articulos()` / `ajax_search()` | JSON | legacy envelopes | preserved |
| `export_catalogo_json`, `export_excel*`, `export_images_zip` | download methods | binary/JSON | `template = false` | preserved |
| `preview_excel` / `get_preview` | `preview_excel()` / `get_preview()` | JSON | wizard step 1/2 | WU-7 internals (CAT-08) |
| `get_notas_*`, `crear_nota`, `resolver_nota`, `cancelar_nota`, `count_notas_pendientes`, `get_todas_notas` | `ajax_*` | JSON | notes envelopes | WU-6 model via soft seam (AD-W5-9) |
| *default* | `process_action()` | JSON | `{success:false, error}` | unchanged |

**Standalone bootstrap + retirement (CAT-01/05):**

```php
// plugins/catalogo_core/Init.php
public static function ensureCatalogoTables(): void
{
    require_once FS_FOLDER . '/base/fs_model.php';
    foreach (['tarif_catalogo', 'tarif_catalogo_def', 'tarif_catalogo_articulo',
              'tarif_catalogo_def_articulo', 'tarif_catalogo_def_familia'] as $modelName) {
        $file = FS_FOLDER . '/plugins/catalogo_core/model/' . $modelName . '.php';
        if (is_file($file)) { require_once $file; }
        $fqcn = 'FSFramework\\model\\' . $modelName;
        if (class_exists($fqcn, false) && is_subclass_of($fqcn, \fs_model::class)) { new $fqcn(); }
    }
}
private static function retireTarifCatalogoViewPage(): void;  // fs_page::get('tarif_catalogo_view') → gated delete()
```

### Testing Strategy

| CAT | Layer | What is proven | Test file / method |
|---|---|---|---|
| CAT-01 | Unit/source + class contract | The five models + XMLs resolve only from `plugins/catalogo_core/model/`, FQCNs and `parent::__construct('<table>')` byte-stable; `ensureCatalogoTables()` is `public static`, `is_file`/`class_exists(...,false)`/`is_subclass_of`-guarded, FK-safe, wired from `init()`/`upgrade()`, frozen bodies untouched | `tests/CatalogoManagerOwnershipTest.php` — `test_catalog_models_and_xml_live_only_in_catalogo_core`, `test_catalog_table_names_are_byte_stable`, `test_catalogo_core_bootstraps_the_catalog_tables_standalone` |
| CAT-02 | Unit/source | `VentasCatalogo` exists only in catalogo_core, `extends \FSFramework\Controller\PageController`, `getPageData()` = `ventas_catalogo`/`catalogo`, wrapper `controller/ventas_catalogo.php`, no `tarif_controller` require; soft `roleEnTarifa()` + `initUserPermissions()` preserved and fail-closed | `CatalogoManagerOwnershipTest` — `test_canonical_page_is_ventas_catalogo_on_pagecontroller`, `test_page_registry_wrapper_and_getpagedata`, `test_role_permissions_are_resolved_through_the_soft_seam` |
| CAT-03 | Unit/source (moved test) | Fragment parity (`htmx_articulos`/`htmx_articulos_agrupados`/`htmx_search` templates + swap targets) and the `action=` map survive the move; `$this->template = 'ventas_catalogo_*'`; no retired-slug assertion | moved `tests/Controller/TarifCatalogoHtmxContractTest.php` — `test_view_boots_htmx_macro_and_marks_main_region`, `test_family_headers_lazy_load_via_htmx_attributes`, `test_load_more_row_appends_via_hx_beforeend`, `test_fragment_actions_keep_serving_the_pinned_templates` |
| CAT-04 | Unit/source (moved test) | `htmx.boot`/`alpine.boot` single host boot; `Alpine.data(` behind `alpine:init` + `csp_nonce_attr()`; colon htmx events and v2-name ban; `hx-post`/`htmx.ajax('POST')` mutations only (no `hx-delete/put/patch`); no `bootbox`/`\|raw`; toolbar/tree + notes/images entry points present | moved `TarifCatalogoHtmxContractTest` — `test_toolbar_and_tree_entry_points_are_preserved`, `test_alpine_and_htmx_hygiene`, `test_mutations_are_post_only`, `test_catalogo_js_bridges_view_mode_and_sort_refetch_through_htmx_ajax` |
| CAT-05 | Unit/source + `fs_page` | `tarif_catalogo_view` controller/view/partials/JS absent from both plugins (no alias); `TarifarioComponents:271` + catalog inbound links target `ventas_catalogo`; `retireTarifCatalogoViewPage()` wired idempotently | `CatalogoManagerOwnershipTest` — `test_retired_catalog_surfaces_leave_no_alias`, `test_inbound_links_repoint_to_ventas_catalogo`, `test_retired_fs_page_row_is_deleted_idempotently`; `tests/InitUpgradeTest.php` — `upgradeRetiresTarifCatalogoViewPageIdempotently` |
| CAT-06 | Grep gate + locked tests | catalogo_core production has zero `plugins/tarifario/`/`@tarifario/` (the WU-3 gate now covers the moved `Controller/`); RBAC listener + role tables stay in tarifario and the listener stays live on `ArticlePermissionFilterEvent::NAME` | `ArticuloListaCanonicaOwnershipTest` (**KEEP, unedited**), `CatalogoManagerOwnershipTest` — `test_catalogo_core_has_no_tarifario_path_for_the_catalog_surface`, `test_rbac_boundary_stays_in_tarifario`; tarifario `Integration/HookRegistrationTest` (**KEEP**) |
| CAT-07 | Suite gate | `CatalogoCoreHookMarkersTest` green unedited; moved tests green and assert no retired slug; catalogo_core ≥ **611 tests / 2422 assertions**; tarifario green after its 18 methods relocate | `Integration/CatalogoCoreHookMarkersTest` (**KEEP**), moved `TarifCatalogo*Test.php`, `CatalogoManagerOwnershipTest`; suite run of both plugin configs |
| CAT-08 | Unit/source | No Excel/JSON wizard service/SSE has moved; `process_excel_wizard.php` still references `page=tarif_catalogo_view`; the catalog page keeps the `preview_excel`/`get_preview`/`import_excel_chunk` entry points; `descartadas_url` repoint recorded as WU-7 | `CatalogoManagerOwnershipTest` — `test_wizard_internals_stay_in_tarifario`, `test_descartadas_url_repoint_is_a_wu7_item`, `test_catalog_entry_points_still_resolve` |

Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
(floor **611 / 2422**), `ddev exec php vendor/bin/phpunit -c
plugins/tarifario/phpunit.xml` (180 / 637 minus the 18 relocated methods), and the
root `ddev exec php vendor/bin/phpunit --testsuite Plugins`.

### Threat Matrix

| Boundary | Applicability | Expected safe behavior | Failure behavior | RED test |
|---|---|---|---|---|
| Dual-class autoload collision | Applicable | Each moved model/XML/template/JS exists in exactly one plugin; catalogo_core commit lands before tarifario removal | Fatal `Cannot declare class` / stale class wins the autoloader | `CatalogoManagerOwnershipTest::test_catalog_models_and_xml_live_only_in_catalogo_core` |
| Coupling grep gate (`plugins/tarifario/` literal) | Applicable | Moved controller/views/JS/extras contain zero `plugins/tarifario/`/`@tarifario/`; tarifario classes resolve by FQCN behind `class_exists` | catalogo_core silently re-depends on tarifario (forbidden direction) | `ArticuloListaCanonicaOwnershipTest` (KEEP) + `CatalogoManagerOwnershipTest::test_catalogo_core_has_no_tarifario_path_for_the_catalog_surface` |
| Orphan `fs_page` row | Applicable | `retireTarifCatalogoViewPage()` deletes the retired slug once; rerun is a no-op; no alias resolves it | Broken menu item (`fs_user::get_menu()` does not filter dead pages) | `InitUpgradeTest::upgradeRetiresTarifCatalogoViewPageIdempotently` |
| htmx v2 event names / inline-eval Alpine | Applicable | Colon htmx events only; `Alpine.data(` registered behind `alpine:init` from a nonce'd classic script; no `bootbox` | CSP violation / silent htmx breakage | moved `TarifCatalogoHtmxContractTest::test_alpine_and_htmx_hygiene` |
| Stored/reflected XSS in the config carrier | Applicable | `$catalogo_config` is emitted via `json_encode\|e('html_attr')` and parsed client-side; no `\|raw` | Script/data injection through a family/articulo description | moved `TarifCatalogoHtmxContractTest` `\|raw` ban + `CatalogoManagerOwnershipTest` view scan |
| CSRF on mutations | Applicable | POST mutations keep the token through `htmx.boot()`'s inherited `X-CSRF-TOKEN` and `isCsrfValid()` (body/header); non-POST is exempt | Mutating action executes without a valid token | moved `TarifCatalogoOpcionalMasterExportTest` / `TarifCatalogoHtmxContractTest` CSRF source contracts; `isCsrfValid()` shim assertion |
| SQL injection (moved catalog methods) | Applicable | Moved methods keep their `var2str()`/`intval()`/raw-SQL patterns byte-for-byte; no new SQL authored | Injected SQL through `referencia`/`codfamilia`/`orden` | byte-stable move + `CatalogoManagerOwnershipTest` diff/ownership assertions |
| Authorization / RBAC | Applicable | Roles resolve through the fail-closed soft seam; RBAC tables + listener stay in tarifario; mutations still gated by `can_edit`/`can_import_export`/`has_access` | Privilege escalation or inverted host/guest contract | tarifario `Integration/HookRegistrationTest` (KEEP) + `CatalogoManagerOwnershipTest::test_role_permissions_are_resolved_through_the_soft_seam` |
| File upload (Excel/images) | Applicable | The moved upload branches are byte-stable (`fs_chunked_upload`, `move_uploaded_file`, `IOFactory`, ext caps); no new upload surface in WU-5 | Unrestricted upload / path traversal | byte-stable move; WU-7 owns the internals |
| Shell / subprocess / VCS-PR automation / executable-file classification | N/A | WU-5 is a PHP/Twig/JS ownership move; no shell, subprocess, VCS automation or file-classification surface changes | — | — |

### Migration / Rollout

No data migration, no table/schema rename, no API change, no Composer dependency
(no `vendor/` commit required). Rollout is the two-commit atomic move (AD-W5-12):
the catalogo_core commit adds the five moved models + XMLs, the reclassed
`Controller/VentasCatalogo.php` + wrapper + `VentasCatalogoStateTrait`, the moved
views/partials/JS with the hygiene pass, `Init::ensureCatalogoTables()` +
`retireTarifCatalogoViewPage()` and the new/updated tests; the tarifario commit
`git rm`s every moved asset, retires the two moved test files and repoints the
three audit tests. No deploy between the two. `Init::ensureCatalogoTables()`
creates the five catalog tables idempotently on standalone installs;
`tarifario_init.php` stays an idempotent no-op. Plugin `fsframework.ini` version
bumps are deferred to the `fsframework-plugin-release` skill. Post-rollout cache
clear: `CacheManager::clearAll()` + `tmp/twig_cache`.

**Intended behavior change (documented, not silent):** the catalog route changes
from `page=tarif_catalogo_view` to `page=ventas_catalogo` (menu folder
`catalogo`), and the migrated view adds Alpine/nonce/hx-post hygiene. Everything
else is a source relocation (autoloader/template-loader resolved). **Temporary
404 (WU-7 boundary):** `process_excel_wizard.php`'s `descartadas_url` still points
at the retired `page=tarif_catalogo_view`, so the SSE "download discarded rows"
link 404s until WU-7 repoints it — explicitly accepted by CAT-08.

**Rollback (AD-W5-12):** revert the two commits in reverse order (tarifario, then
catalogo_core) and clear the Twig cache. The tarifario-owned controller, models,
views, partials and JS are restored byte-for-byte; the `tarif_catalogo_view`
`fs_page` row is recreated on the first request after the revert. No table/data
was renamed or dropped.

### Open Questions

- [ ] `htmx.ajax('POST', …)` in htmx 4 resolves a promise without exposing the
      JSON body in this vendored build; the four mutation sites must confirm the
      response is read through `htmx:after:request` (existing shim) during RED
      (recommended default: keep the same JSON envelopes and read them from the
      shim's `evt.detail.ctx`; fallback a tiny `postJson()` wrapper built on
      `htmx.ajax` — still htmx POST, never `$.ajax`).
- [ ] Deep jQuery DOM layer inside the ES modules (Sortable, `$(...)`, `fadeOut`)
      is preserved for CAT-03 parity; the full de-jQuery/Alpine rewrite of
      `catalogo-main.js`, `revision-notas.js`, `excel-export.js`, `images-zip.js`
      is WU-8 debt (recommended default: leave byte-stable in WU-5).
- [ ] `tarif_revision_nota` stays in tarifario (WU-6); with tarifario inactive the
      notes counters stay at 0 and the notes flags are false via the soft seam
      (recommended default: accept, since notes require tarifario roles anyway).
- [ ] `process_excel_wizard.php:477` `descartadas_url` keeps the retired slug until
      WU-7 (accepted temporary 404, CAT-08) (recommended default: record in the
      WU-7 task list, do not touch here).
- [ ] The legacy JSON `search` action reads an undeclared `$this->familia` (latent
      pre-existing bug); it is byte-stable in WU-5 (recommended default: flag for
      WU-8, do not fix silently in a move).
- [ ] Standalone `ensureCatalogoTables()` is proven at source-contract level; the
      DB-backed boot smoke belongs to `sdd-verify` (recommended default: add the
      five tables to the WU-5 dev-DB smoke checklist).

---

## WU-6 — `catalogo_core` owns revision notes and article price history

> **Scope.** Sixth slice of the program (depends on WU-5, applied in the working
> tree). Delta spec: `specs/articulo-notas-historial/spec.md` (ANH-01..ANH-07).
> `tarif_revision_nota` + `tarif_precio_historial` (+ XMLs) move into
> `catalogo_core`; the notes soft seam left by WU-5 (AD-W5-9) and the WU-1
> `class_exists` history guard (AD-4) are hardened to local references;
> `tarif_historial_precios` (both branches) becomes a catalogo_core-owned page and
> its `fs_page` row is retired idempotently. No table rename, no data migration, no
> schema/API change, no Composer dependency. Runner:
> `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
> (baseline **641 tests / 2741 assertions**; tarifario **162 / 555**). Strict TDD;
> both plugin suites plus the root `Plugins` suite green after the slice.

### Technical Approach

WU-6 is the same **atomic two-repo move** as WU-2/WU-4/WU-5 (AD-W2-10, AD-W4-7,
AD-W5-12): a `catalogo_core` commit (verbatim model/XML copies + reclassed history
controller/view + the two softenings + bootstrap + retirement + tests) immediately
followed by a `tarifario` commit (`git rm` of the moved files + require repoints),
with **no deploy between**. WU-6 adds before it removes, so catalogo_core lands
first.

The two **softenings** WU-1/WU-5 deliberately deferred are the core of the slice:
`Controller/VentasCatalogo.php::init_revision_notas()` stops resolving the notes
model through `loadTarifarioModel()` (AD-W5-9) and the WU-1
`tarif_articulo_precio::save()` stops guarding the history write with
`class_exists()` (AD-4); both become local references. The history page is a
456-line read-only controller and is **reclassed, not rewritten**: `extends
tarif_controller` → catalogo_core's `fbase_controller` + the existing
`TarifarioOpcionalStateTrait` (which already supplies `$tarifas`,
`$tarifa_defecto`, `tarif_paginas()`), keeping its class basename and
`page=tarif_historial_precios` slug so `tarif_precio_historial::url()` and the
catalogo_core-owned `tarif_opcional_precio_historial::url()` (`…&tipo=opcionales`)
stay byte-stable (ANH-04). The only view edit is the `|raw` removal plus a
nonce'd Alpine component; the notes/images partials and `revision-notas.js`
already satisfy ANH-05 and stay byte-stable.

### Architecture Decisions

| # | Decision | Choice | Alternatives rejected | Rationale |
|---|----------|--------|----------------------|-----------|
| AD-W6-1 | Exact model moves | Move **verbatim** into `catalogo_core/model/` (+ `model/table/`): `tarif_revision_nota.php` (`tarif_revision_notas`), `tarif_precio_historial.php` (`tarif_precio_historial`), `tarif_revision_notas.xml`, `tarif_precio_historial.xml`; `git rm` the tarifario paths. FQCNs (`FSFramework\model\*`) + table names byte-stable; no class/XML in both. Repoint the two hard `require_once`s `tarifario/extras/tarifario_init.php:24,25` → catalogo_core; the moved controller's own requires travel with it. Keep the `class_exists` boot blocks (`:150-152`, `:174-177`) as idempotent no-ops. | Keep `tarif_revision_nota` in tarifario (ANH-01 fails; notes dead standalone); move only the history model (ANH-01 fails); rename tables/classes (data-at-rest + SQL break) | ANH-01/ANH-02; byte-stable FQCN+table keeps every `var2str()`/FK reference valid with zero SQL change; matches the WU-4/WU-5 `tarifario_init.php` no-op precedent. |
| AD-W6-2 | Notes seam hardening | Add `protected function revision_nota_model(): \FSFramework\model\tarif_revision_nota { return new tarif_revision_nota(); }` to `Controller/VentasCatalogo.php`; `init_revision_notas()` calls it, drops the `loadTarifarioModel('FSFramework\\model\\tarif_revision_nota')` call and the `=== null` degradation, and removes the now-dead `process_action()` `revision_nota_model === null` notes guard (`:578`). `loadTarifarioModel()` is retained **only** for `tarif_grupo_articulo`/`tarif_grupo_rol`/`tarif_grupo_tarifa`. | Keep the soft seam (ANH-01 forbids); inline `new tarif_revision_nota()` (not overridable — the DB-free tests need a seam); move the `tarif_grupo_*` models too (ANH-06 / exploration §3.4 reject) | Mirrors the WU-1 `precio_model()` seam; the notes model is always local so counters/lists/CRUD work standalone, while RBAC stays soft and in tarifario. |
| AD-W6-3 | History guard hardening (ANH-03) | In `model/tarif_articulo_precio.php::save()` replace the `if (class_exists('FSFramework\\model\\tarif_precio_historial')) { … }` wrapper with the direct local call `tarif_precio_historial::registrar_cambio(...)`. **Invert the pinned test** `ArticuloTarifaPrecioOwnershipTest::test_history_write_is_class_exists_soft` → `test_history_write_uses_the_local_reference` (guard string absent; call present; still emitted only when the price actually changed). | Keep the guard (ANH-03 explicitly forbids); keep it additively + add a second test (leaves dead code; AD-4's Open Question scheduled the removal for WU-6) | `ArticuloTarifaPrecioOwnershipTest:152-177` **pins** the WU-1 guard body, so this is an intentional pinned-contract migration in the same WU: the assertion flips from "guarded" to "local", no coverage is lost. |
| AD-W6-4 | Price-history page/controller + base class + `fs_page` | Move `controller/tarif_historial_precios.php` + `View/tarif_historial_precios.html.twig` into catalogo_core **keeping the class basename and `page=tarif_historial_precios`**; reclass `extends tarif_controller` → `extends fbase_controller` + `use TarifarioOpcionalStateTrait;` + `$this->init_tarifario_opcional_state();`; ctor menu folder `tarifario` → `catalogo`; replace the `tarif_controller`/`tarifario model` requires with `plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php` + `plugins/catalogo_core/model/tarif_precio_historial.php` + `plugins/catalogo_core/model/tarif_opcional_precio_historial.php`. Keep both `tipo=articulos`/`tipo=opcionales` branches (article → local model, opcional → catalogo_core model), `action=export_excel`/`action=purge`, `paginas()`, and the WU-2/WU-3 link repoints. Add idempotent `Init::retireTarifHistorialPreciosPage()` (`fs_page::get('tarif_historial_precios')` → gated `->delete()`) wired in `upgrade()`; catalogo_core re-registers the row under `catalogo` on the next discovery. | Reclass to `PageController` (mandates `getPageData()` + `privateCore(&$response,$user,$permissions)` + an `isCsrfValid()` shim for a read-only page whose `$tarifas`/`$tarifa_defecto`/`tarif_paginas()` already come free from `fbase_controller`+trait — WU-5's `PageController` was a CAT-02 mandate ANH-04 does not impose); rename the slug + repoint `tarif_precio_historial::url()`/`tarif_opcional_precio_historial::url()`/all inbound links (ANH-04 names the file and catalogo_core's own model URL already targets the slug — extra churn, no benefit); keep it in tarifario (ANH-04 forbids) | Smallest reclass that removes the `tarif_controller`/`plugins/tarifario/` dependency while preserving the page contract; the retirement is the ANH-04-scenario-2 idempotent cleanup (folder refresh to `catalogo`). |
| AD-W6-5 | Standalone bootstrap | Add `public static function ensureNotasHistorialTables(): void` (mirror `ensureCatalogoTables()`): `require_once base/fs_model.php`; FK-safe order `self::ensureFamiliasTarifaTables()` (ensures `tarif_tarifas` for the notes FK) → touch `fs_users` (`class_exists('fs_user')` + instantiate) → require+instantiate `tarif_revision_nota` → `tarif_precio_historial`, each behind `is_file` + `class_exists($fqcn, false)` + `is_subclass_of($fqcn, \fs_model::class)`. Wire in `init()` after `ensureCatalogoTables()` (own `try/catch` + `error_log`) and in `upgrade()` after it. **Frozen bodies stay untouched.** | Fold into `ensureCatalogoTables()`/`ensureArticuloTarifaTables()` (mutates bodies pinned by `CatalogoManagerOwnershipTest`/`ArticuloTarifaPrecioOwnershipTest`); skip (standalone never creates the tables; ANH-01 scenario 2 fails) | Mirrors AD-W4-9/AD-W5-8; `fs_model` creates only when missing so a second boot/`upgrade()` is a no-op. |
| AD-W6-6 | UI hygiene (ANH-05) | The moved history view drops `\|raw`: `get_change_icon()` (HTML `<i>`) becomes `get_change_icon_class()` returning the CSS class, rendered as `<i class="{{ … }}"></i>` (autoescaped). The jQuery datepicker/inline `<script>` becomes a nonce'd classic script registering `Alpine.data('historialPrecios')` behind `alpine:init` (datepicker init + purge confirm); colon events only; purge stays POST + `csrf_field()`; the bootstrap modal `data-toggle` is preserved. The notes/images partials + `revision-notas.js` are byte-stable (already no `\|raw`/`bootbox`, `fetch` POST). | Keep `\|raw` (ANH-05 forbids); de-jQuery the deep `revision-notas.js`/catalog ES modules (WU-5 residual, WU-8 debt); rewrite the history page to htmx swaps (no fragment contract exists; the page is a full render) | ANH-05; mirrors the WU-2/WU-5 view-level hygiene interpretation without touching the CAT-03 JS contracts. |
| AD-W6-7 | Test ownership + pinned-contract migration | New `tests/ArticuloNotasHistorialOwnershipTest.php` (ANH-01/02/03/05/06). Repoint `tests/TarifHistorialPreciosControllerTest.php` (`CONTROLLER_RELATIVE` → `plugins/catalogo_core/controller/tarif_historial_precios.php`) and add the article-branch assertions. Invert `tests/ArticuloTarifaPrecioOwnershipTest.php` (AD-W6-3). Extend `tests/InitUpgradeTest.php` (retirement + bootstrap wiring). Repoint the three hardcoded view paths in `tests/ArticuloListaCanonicaOwnershipTest.php`, `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`, `tests/CatalogoOpcionalesUnifiedControllerTest.php` → `plugins/catalogo_core/View/tarif_historial_precios.html.twig`. **KEEP unedited**: `Integration/CatalogoCoreHookMarkersTest`, `VentasArticulosControllerTest`, `VentasArticuloControllerTest`, tarifario `Integration/HookRegistrationTest`. | Keep the new test in tarifario (the new owner is catalogo_core); leave the pinned guard test asserting the removed guard (impossible); recreate notes coverage in tarifario (the code is catalogo_core's) | ANH-07 + house convention "tests travel with their code"; the three path-list repoints are mandatory because those tests `assertFileExists()` the moved view. |
| AD-W6-8 | Atomic landing + rollback | Two coordinated commits: **catalogo_core first** (moved models/XML/controller/view + the two hardenings + bootstrap + retirement + test updates), **tarifario immediately after** (`git rm` the four moved model/XML files + the controller/view + `tarifario_init.php` require repoints), no deploy between. **Rollback**: revert the two commits in reverse order (tarifario, then catalogo_core) and clear the Twig cache; the restored tarifario controller re-registers the `tarif_historial_precios` `fs_page` row on the first request. | Separate PRs (broken runtime window where catalogo_core serves the controller while tarifario still owns the models); per-file manual restore | Same mechanics as WU-2/WU-4/WU-5; the parent repo tracks no plugin bytes; the move is code-only so a revert is byte-exact. |
| AD-W6-9 | No half-moved state + RBAC boundary + grep gate | Every moved class/XML/controller/view exists in exactly one plugin; catalogo_core production has zero `plugins/tarifario/`/`@tarifario/` literals for the notes/history surfaces; `loadTarifarioModel()` remains **only** for `tarif_grupo_articulo`/`tarif_grupo_rol`/`tarif_grupo_tarifa`; `ArticlePermissionListener` + the role tables stay in tarifario and the listener stays live on `ArticlePermissionFilterEvent::NAME`. | Move the RBAC seam with the models (ANH-06 + exploration §3.4 reject); big-bang move | ANH-06; the ownership + grep tests are the machine check; tarifario `HookRegistrationTest` stays green unedited. |

### Data Flow

    page=tarif_historial_precios  (plugins/catalogo_core/controller/tarif_historial_precios.php)
      └─ fbase_controller + TarifarioOpcionalStateTrait  (init_tarifario_opcional_state)
           ├─ tipo=articulos  → local tarif_precio_historial (all_by_fecha/count/get_estadisticas/purge)
           ├─ tipo=opcionales → catalogo_core tarif_opcional_precio_historial (same API)
           ├─ action=export_excel → PhpSpreadsheet stream
           └─ action=purge (POST, admin + isCsrfValid) → purge_by_fecha on both models
      view: nonce'd Alpine.data('historialPrecios') behind alpine:init; no |raw; links → ventas_articulo(s)

    notes (Controller/VentasCatalogo.php): init_revision_notas() → revision_nota_model() (local)
      get_notas_*/crear/resolver/cancelar/count/get_todas → local tarif_revision_nota (no loadTarifarioModel)

    price save: tarif_articulo_precio::save() → tarif_precio_historial::registrar_cambio() (hard local)
    bootstrap:  Init::init()/upgrade() → ensureNotasHistorialTables() (tarif_tarifas + fs_users first)
    retirement: Init::upgrade() → retireTarifHistorialPreciosPage() → fs_page row deleted (idempotent)

### File Changes

#### `plugins/catalogo_core/` (commit 1)

| File | Action | Description |
|------|--------|-------------|
| `model/tarif_revision_nota.php` | Create (moved) | Verbatim move; FQCN + table `tarif_revision_notas` byte-stable; `install()` FK deps unchanged |
| `model/table/tarif_revision_notas.xml` | Create (moved) | Verbatim schema (PK `id`; FK `codtarifa → tarif_tarifas`, `creado_por → fs_users`) |
| `model/tarif_precio_historial.php` | Create (moved) | Verbatim move; table `tarif_precio_historial` byte-stable; `install()` indexes unchanged |
| `model/table/tarif_precio_historial.xml` | Create (moved) | Verbatim schema (PK `id`) |
| `controller/tarif_historial_precios.php` | Create (moved + reclassed) | `extends fbase_controller` + `use TarifarioOpcionalStateTrait`; both branches; menu folder `catalogo`; `get_change_icon_class()`; catalogo_core requires (AD-W6-4) |
| `View/tarif_historial_precios.html.twig` | Create (moved + hygiene) | No `\|raw`; nonce'd `Alpine.data('historialPrecios')` behind `alpine:init`; POST purge + `csrf_field()`; links preserved (AD-W6-6) |
| `model/tarif_articulo_precio.php` | Modify | Replace the `class_exists` wrapper with the direct local `tarif_precio_historial::registrar_cambio(...)` (AD-W6-3) |
| `Controller/VentasCatalogo.php` | Modify | `revision_nota_model()` factory; `init_revision_notas()` resolves locally; remove the `=== null` degradation + the `process_action()` notes null-guard (AD-W6-2) |
| `Init.php` | Modify | Add `ensureNotasHistorialTables()` + `retireTarifHistorialPreciosPage()`; wire in `init()`/`upgrade()`; frozen bodies untouched (AD-W6-5) |
| `tests/ArticuloNotasHistorialOwnershipTest.php` | Create | ANH-01/02/03/05/06 ownership, local seam, guard removal, grep gate, standalone bootstrap (AD-W6-7) |
| `tests/TarifHistorialPreciosControllerTest.php` | Modify | `CONTROLLER_RELATIVE` → catalogo_core; add article-branch assertions; `extends fbase_controller` + trait; no tarifario twin (ANH-04) |
| `tests/ArticuloTarifaPrecioOwnershipTest.php` | Modify | Invert `test_history_write_is_class_exists_soft` (AD-W6-3) |
| `tests/InitUpgradeTest.php` | Modify | Add `upgradeRetiresTarifHistorialPreciosPageIdempotently` + `ensureNotasHistorialTables()` wiring (ANH-04) |
| `tests/ArticuloListaCanonicaOwnershipTest.php` | Modify | `REPOINTED_LINKS` key `plugins/tarifario/View/tarif_historial_precios.html.twig` → catalogo_core path (AD-W6-7) |
| `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | Modify | View-scan path → catalogo_core (AD-W6-7) |
| `tests/CatalogoOpcionalesUnifiedControllerTest.php` | Modify | Repoint loop view path → catalogo_core (AD-W6-7) |

#### `plugins/tarifario/` (commit 2)

| File | Action | Description |
|------|--------|-------------|
| `model/tarif_revision_nota.php` | Delete | `git rm` after the move (AD-W6-1) |
| `model/table/tarif_revision_notas.xml` | Delete | `git rm` after the move |
| `model/tarif_precio_historial.php` | Delete | `git rm` after the move |
| `model/table/tarif_precio_historial.xml` | Delete | `git rm` after the move |
| `controller/tarif_historial_precios.php` | Delete | Moved + reclassed into catalogo_core (AD-W6-4) |
| `View/tarif_historial_precios.html.twig` | Delete | Moved into catalogo_core (AD-W6-4) |
| `extras/tarifario_init.php` | Modify | `:24,:25` `require_once` → catalogo_core model paths; keep the `class_exists` boot blocks `:150-152`,`:174-177` as idempotent no-ops |

**No edit required:** `tarifario` has no other hard require of either moved model (grep-verified: only `tarifario_init.php` + the moved controller). No tarifario test references the moved controller, models or view. `tarif_grupo_*`/`ArticlePermissionListener` are untouched (ANH-06).

### Interfaces / Contracts

**Notes seam** (`plugins/catalogo_core/Controller/VentasCatalogo.php`):

```php
protected function revision_nota_model(): \FSFramework\model\tarif_revision_nota
{
    return new tarif_revision_nota();      // local; never null
}
// init_revision_notas(): $this->revision_nota_model = $this->revision_nota_model();
//                        (no loadTarifarioModel('…tarif_revision_nota'); no === null degradation)
// process_action():      the `$this->revision_nota_model === null` notes guard is removed
// loadTarifarioModel()   kept only for tarif_grupo_articulo / tarif_grupo_rol / tarif_grupo_tarifa
```

**History page** (`plugins/catalogo_core/controller/tarif_historial_precios.php`):

```php
class tarif_historial_precios extends fbase_controller
{
    use TarifarioOpcionalStateTrait;                  // $tarifas, $tarifa_defecto, tarif_paginas()
    public $tipo; public $fecha_desde; public $fecha_hasta; public $b_codtarifa;
    public $resultados; public $total_resultados; public $offset; public $estadisticas; public $b_url;

    public function __construct() { parent::__construct(__CLASS__, 'Historial de Precios', 'catalogo'); }
    protected function private_core() { parent::private_core(); $this->init_tarifario_opcional_state(); … }
    public function get_change_icon_class($a, $b): string;   // 'fa fa-arrow-up text-danger' | '…-down text-success' | 'fa-minus text-muted'
    // unchanged: ini_filters(), load_data(), get_articulo(), get_opcional(),
    // calcular_porcentaje_cambio(), get_badge_class(), format_fecha(), paginas(),
    // export_excel(), purge_historial()
}
```

**History guard** (`plugins/catalogo_core/model/tarif_articulo_precio.php::save()`):

```php
if ($result && abs($precio_anterior - $this->precio) >= 0.0001) {
    $coreLog = new \fs_core_log();
    $usuario = $coreLog->user_nick() ?: null;
    tarif_precio_historial::registrar_cambio(
        $this->referencia, $this->codtarifa, $precio_anterior, $this->precio, $usuario
    );
}
```

**Bootstrap + retirement** (`plugins/catalogo_core/Init.php`):

```php
public static function ensureNotasHistorialTables(): void
{
    require_once FS_FOLDER . '/base/fs_model.php';

    self::ensureFamiliasTarifaTables();               // FK: tarif_revision_notas.codtarifa → tarif_tarifas
    if (!class_exists('fs_user', false)) { require_once FS_FOLDER . '/model/fs_user.php'; }  // FK destino fs_users
    new \fs_user();

    foreach (['tarif_revision_nota', 'tarif_precio_historial'] as $modelName) {
        $file = FS_FOLDER . '/plugins/catalogo_core/model/' . $modelName . '.php';
        if (is_file($file)) { require_once $file; }
        $fqcn = 'FSFramework\\model\\' . $modelName;
        if (class_exists($fqcn, false) && is_subclass_of($fqcn, \fs_model::class)) { new $fqcn(); }
    }
}
private static function retireTarifHistorialPreciosPage(): void;   // fs_page::get('tarif_historial_precios') → gated delete()
```

### Testing Strategy

| ANH | Layer | What is proven | Test file / method |
|---|---|---|---|
| ANH-01 | Unit/source + class contract | `tarif_revision_nota` + `tarif_revision_notas.xml` resolve only from `plugins/catalogo_core/model/`, FQCN/table byte-stable; `revision_nota_model()` is the local seam and `init_revision_notas()` no longer calls `loadTarifarioModel('…tarif_revision_nota')`; the notes actions run with a fake DB (counters/lists/CRUD) | `tests/ArticuloNotasHistorialOwnershipTest.php` — `test_notes_model_and_xml_live_only_in_catalogo_core`, `test_notes_seam_resolves_locally_without_load_tarifario_model`, `test_notes_actions_use_the_local_model` |
| ANH-01 | Unit/source (bootstrap) | `Init::ensureNotasHistorialTables()` is `public static`, `is_file`/`class_exists(...,false)`/`is_subclass_of`-guarded, touches the `tarif_tarifas` + `fs_users` FK destinations first, is wired from `init()` after `ensureCatalogoTables()` and from `upgrade()`, and does not mutate the frozen bodies | `ArticuloNotasHistorialOwnershipTest` — `test_catalogo_core_bootstraps_the_notes_table_standalone`; `tests/InitUpgradeTest.php` (wiring) |
| ANH-02 | Unit/behavior | `tarif_precio_historial` + XML owned only by catalogo_core; date-filtered list/counters/statistics/purge + `registrar_cambio()` resolve locally; unchanged values are skipped | `ArticuloNotasHistorialOwnershipTest` — `test_history_model_and_xml_live_only_in_catalogo_core`, `test_history_reads_and_writes_use_the_local_model` |
| ANH-03 | Contract/source | `save()` writes history through the **hard local** reference (no `class_exists` guard); a standalone save records the row (no fatal, no skipped write) | `tests/ArticuloTarifaPrecioOwnershipTest.php` — `test_history_write_uses_the_local_reference` (inverted pin); `ArticuloNotasHistorialOwnershipTest` — `test_price_save_writes_history_without_the_soft_guard` |
| ANH-04 | Contract/source | Both `tipo=articulos` (local model) and `tipo=opcionales` (catalogo_core model) branches served; `extends fbase_controller` + `use TarifarioOpcionalStateTrait`; no tarifario controller/view twin; `fs_page` row retired once with a no-op rerun; inbound links target the canonical page | `tests/TarifHistorialPreciosControllerTest.php` — `test_controller_serves_articulos_mode_from_local_model`, `test_controller_serves_opcionales_mode_from_catalogo_core_model`, `test_controller_extends_fbase_controller_with_shared_state_trait`, `test_retired_tarifario_history_surface_leaves_no_alias`; `tests/InitUpgradeTest.php` — `upgradeRetiresTarifHistorialPreciosPageIdempotently` |
| ANH-05 | Source hygiene | Moved history view: no `\|raw`, nonce'd `Alpine.data(` behind `alpine:init`, colon events only, POST-only mutations; notes/images partials + `revision-notas.js` free of `\|raw`/`bootbox`; `CatalogoCoreHookMarkersTest` green unedited | `ArticuloNotasHistorialOwnershipTest` — `test_history_view_keeps_htmx_alpine_hygiene`, `test_notes_images_ui_hygiene_is_preserved`; `tests/Integration/CatalogoCoreHookMarkersTest.php` **KEEP (no edits)** |
| ANH-06 | Grep gate + boundary | catalogo_core production has zero `plugins/tarifario/`/`@tarifario/` hits for the notes/history surface; `loadTarifarioModel()` present only for `tarif_grupo_*`; RBAC models + listener stay in tarifario and the listener stays live | `ArticuloNotasHistorialOwnershipTest` — `test_catalogo_core_has_zero_tarifario_paths_for_notes_history`, `test_rbac_seam_stays_soft_and_in_tarifario`; tarifario `tests/Integration/HookRegistrationTest.php` **KEEP green (no edits)** |
| ANH-07 | Suite gate | `Integration/CatalogoCoreHookMarkersTest` green unedited; moved/repointed tests green; no test references the retired tarifario history controller path; catalogo_core ≥ **641 tests / 2741 assertions** | `Integration/CatalogoCoreHookMarkersTest` (**KEEP**), `TarifHistorialPreciosControllerTest` + `ArticuloNotasHistorialOwnershipTest` + `InitUpgradeTest` + the three repointed view-path tests; both plugin suites + root `Plugins` suite |

Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
(floor **641 / 2741**), `ddev exec php vendor/bin/phpunit -c
plugins/tarifario/phpunit.xml` (162 / 555), and the root
`ddev exec php vendor/bin/phpunit --testsuite Plugins`.

### Threat Matrix

| Boundary | Applicability | Expected safe behavior | Failure behavior | RED test |
|---|---|---|---|---|
| Dual-class autoload collision | Applicable | Each moved model/XML/controller/view exists in exactly one plugin; catalogo_core commit lands before tarifario removal | Fatal `Cannot declare class` / stale class wins the autoloader | `ArticuloNotasHistorialOwnershipTest::test_notes_model_and_xml_live_only_in_catalogo_core` + `…history…` |
| Coupling grep gate (`plugins/tarifario/` literal) | Applicable | Moved controller/view + notes seam contain zero `plugins/tarifario/`/`@tarifario/`; RBAC classes resolve by FQCN behind `loadTarifarioModel()` | catalogo_core silently re-depends on tarifario (forbidden direction) | `ArticuloNotasHistorialOwnershipTest::test_catalogo_core_has_zero_tarifario_paths_for_notes_history` |
| CSRF (history purge) | Applicable | `purge_historial()` checks `$this->user->admin` then `isCsrfValid()` **before** any `purge_by_fecha()`; invalid/absent token ⇒ error, no delete; non-POST is exempt | CSRF-free mass delete of history rows | `TarifHistorialPreciosControllerTest` (admin + `isCsrfValid` order source contract) |
| Authorization (notes + purge) | Applicable | `can_create/resolve/cancel_notes` stay fail-closed through the soft RBAC seam (tarifario inactive ⇒ non-admins denied, admins allowed); purge is admin-only; the notes seam is local but **not** an authorization bypass | Privilege escalation on notes CRUD / history purge | `ArticuloNotasHistorialOwnershipTest::test_rbac_seam_stays_soft_and_in_tarifario` + `TarifHistorialPreciosControllerTest` |
| XSS (moved view + swapped fragments) | Applicable | Twig autoescape; the history `\|raw` is removed (`get_change_icon_class()` + escaped `class`); nonce'd `Alpine.data()`; `x-text` only; no `bootbox`; notes partials already clean | Script/data injection via icon HTML or note fields | `ArticuloNotasHistorialOwnershipTest::test_history_view_keeps_htmx_alpine_hygiene` |
| Orphan `fs_page` row | Applicable | `retireTarifHistorialPreciosPage()` deletes the stale row once; rerun is a no-op; catalogo_core re-registers it under the `catalogo` folder | Broken menu item (`fs_user::get_menu()` does not filter dead pages) | `InitUpgradeTest::upgradeRetiresTarifHistorialPreciosPageIdempotently` |
| SQL injection (moved models) | Applicable | Moved models keep their `var2str()`/`intval()` patterns byte-for-byte; no new SQL authored | Injected SQL through `referencia`/`codtarifa`/`titulo` | byte-stable move + ownership/diff assertions |
| File upload | N/A | WU-6 moves no upload surface (article images landed in WU-2) | — | — |
| Shell / subprocess / VCS-PR automation / executable classification | N/A | WU-6 is a PHP/Twig ownership move; no shell, subprocess, VCS automation or file-classification surface changes | — | — |

### Migration / Rollout

No data migration, no table/schema rename, no API change, no Composer dependency
(no `vendor/` commit required). Rollout is the two-commit atomic move (AD-W6-8):
the catalogo_core commit adds the two moved models + XMLs, the reclassed
history controller/view, the two hardenings, `Init::ensureNotasHistorialTables()`
+ `retireTarifHistorialPreciosPage()` and the new/updated tests; the tarifario
commit `git rm`s the moved files and repoints the two `tarifario_init.php`
requires. No deploy between the two. `ensureNotasHistorialTables()` creates
`tarif_revision_notas` + `tarif_precio_historial` idempotently on standalone
installs (FK-safe: `tarif_tarifas` + `fs_users` first). Post-rollout cache clear:
`CacheManager::clearAll()` + `tmp/twig_cache`.

**Behavior change (documented, not silent):** the notes module no longer degrades
to "Módulo de notas no disponible" when tarifario is inactive — the counters and
lists now work standalone for users with access; non-admin standalone users still
fail closed because the role resolution stays in tarifario (ANH-01/ANH-06). The
history page keeps its slug/URL but moves to the `catalogo` menu folder.

**Rollback (AD-W6-8):** revert the two commits in reverse order (tarifario, then
catalogo_core) and clear the Twig cache. The tarifario-owned models, controller
and view are restored byte-for-byte; the retired `fs_page` row is recreated by the
restored controller on the first request. No table/data was renamed or dropped.

### Open Questions

- [ ] `get_change_icon_class()` replaces `get_change_icon()`; grep shows no other consumer (only the moved view), but if a downstream plugin calls it, keep `get_change_icon()` as a deprecated alias (recommended default: replace, since the view is the only caller).
- [ ] Exact catalogo_core count after WU-6 (floor 641/2741); the new ownership test + the relocated/updated assertions are expected to raise it — record the observed number in `tasks.md`/`verify-report.md` (recommended default: floor only, no hard ceiling).
- [ ] Standalone notes permissions: with tarifario inactive the RBAC seam fails closed, so only admins can create/resolve/cancel notes. Whether catalogo_core should define a neutral note-permission event is a WU-8 RBAC-audit item (recommended default: keep the boundary, flag for WU-8).
- [ ] `tarifario_init.php`'s `class_exists` boot blocks (`:150-152`, `:174-177`) stay as idempotent no-ops per the WU-4/WU-5 precedent; whether to delete them in WU-8 with the rest of the retirement audit (recommended default: keep through WU-8).
