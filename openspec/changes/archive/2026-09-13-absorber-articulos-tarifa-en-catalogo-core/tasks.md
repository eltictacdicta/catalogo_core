# Tasks: catalogo_core owns the article Tarifas tab and per-tarifa price/state (WU-1)

> **Archive-time final-state note (2026-09-13).** The absorption program was
> delivered in slices and then partially corrected, so the checkbox state below
> is read alongside this note:
> - **WU-1, WU-2, WU-3** (phases 1–19) are **delivered** and independently
>   verified (`verify-report.md`); the Phase 7 / 13 / 19 landing boxes stay
>   `[ ]` because commit/push is human-owned.
> - **WU-4 and WU-5** (phases 20–32) were implemented, then **reverted to
>   tarifario** by the ownership-boundary correction (see `apply-progress.md`
>   "Revert WU-4 + WU-5"). Their checked implementation boxes describe a state
>   that no longer exists; the `catalogo-gestion` and `articulo-modelos-ext`
>   delta specs are **superseded/reverted and were NOT merged** into the
>   canonical specs.
> - **WU-6/WU-7/WU-8** (phases 33–38) were **cancelled** — revision notes,
>   article price history and the rich Excel wizard remain tarifario-exclusive.
>   The `articulo-notas-historial` delta spec was **NOT merged**.
> - The opcionales **configurator** was also reverted to tarifario.

> Plugin-local SDD (`plugins/catalogo_core/openspec/`). Sources of truth: `proposal.md`,
> `design.md` (AD-1..AD-11 + Testing Strategy), delta specs
> `specs/articulo-tarifa-tab-management/spec.md` (ATT-01..ATT-07) and
> `specs/catalogo-render-hooks/spec.md`. `config.yaml` → `strict_tdd: true`.
>
> **Delivery units**: WU-1 shipped (phases 1–7); **WU-2 is this slice
> (phases 8–13)**. WU-3..WU-8 remain the sequenced program follow-ups (see
> `exploration.md` §5) and are **not** implemented by this change.
>
> **Runners / baselines**
> - `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **528 tests / 1826 assertions** (regression floor).
> - `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → **191 tests / 705 assertions** (regression floor).
> - Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins`.
>
> **RED-before-GREEN**: every phase authors its RED test(s) first and records a RED
> run (failure is a missing catalogo_core path/class, never a syntax/setup error)
> before any implementation.
>
> **Atomic move (AD-1/AD-11)**: cross-repo `git mv` is impossible (independent plugin
> repos). Copy byte-identical into catalogo_core, `git rm` the tarifario path, then
> explicit-path `git add` in catalogo_core. A class file/XML/endpoint/hook template
> must never exist in both plugins, and no deploy happens between the catalogo_core
> and tarifario commits. FQCN (`FSFramework\model\tarif_articulo_precio`) and
> `table_name` (`tarif_articulo_precios`) stay byte-stable.
>
> **Slug frozen (AD-2/AD-7)**: the endpoint keeps class basename `tarif_tab_precios`
> and `page=tarif_tab_precios`; `plugins/catalogo_core/View/ventas_articulo.html.twig`
> is NOT edited (frozen markers at lines 129 and 341 stay).

## Suggested Work Units

Review slices for WU-1. The chain lands on `main` (`stacked-to-main`), each unit
independently reviewable and reversible; the catalogo_core and tarifario commits of
a unit land back-to-back with no intermediate deploy (AD-1).

| Unit | Goal | PR | Focused test | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| **WU-1.A** Ownership | `tarif_articulo_precio` + XML owned by catalogo_core; AD-4 soft history guard; AD-8 standalone bootstrap | PR 1 | `plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php` | catalogo_core suite + `php -l` on moved model/`Init.php` | revert model + XML move and the `Init::ensureArticuloTarifaTables()` addition — tables/data untouched |
| **WU-1.B** Endpoint | Relocate `tarif_tab_precios` to catalogo_core on `fbase_controller`; neutral `ArticlePermissionFilterEvent` gate | PR 2 | `plugins/catalogo_core/tests/TarifTabPreciosTest.php` (migrated) | catalogo_core suite + `php -l` on endpoint | revert endpoint move + the migrated test — tarifario endpoint restored |
| **WU-1.C** Hooks + htmx | catalogo_core registers the article pair; 4 templates moved + rewritten to htmx 4 + Alpine CSP | PR 3 | `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` | catalogo_core suite + `php -l` on `Init.php` | revert `Init` hook map and the 4 templates — tarifario hook ownership restored byte-for-byte |
| **WU-1.D** Flip + gate | tarifario repoints/flips to zero article hooks; dual-suite + grep audit green | PR 4 | `plugins/tarifario/tests/Integration/HookRegistrationTest.php` + grep gate in `ArticuloTarifaPrecioOwnershipTest` | tarifario suite + catalogo_core suite + root `Plugins` suite | revert tarifario repoints + restore the moved test; no data or `fs_page` rows touched in WU-1 |

---

## Phase 1: WU-1.A — ownership tests + atomic model/XML move (AD-1, AD-4, AD-8, AD-9)

### RED (author FIRST; run before the move)

- [x] 1.1 Write `plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php` (namespace `Tests\CatalogoCore`, DB-free, mirror `OpcionalDomainModelOwnershipTest.php`). Methods (ATT-01/ATT-07 + AD-4/AD-8):
  - `test_model_lives_in_catalogo_core_with_original_fqcn_and_stable_table_name` — file at `plugins/catalogo_core/model/tarif_articulo_precio.php`, `namespace FSFramework\model;`, `class tarif_articulo_precio`, `parent::__construct('tarif_articulo_precios')`, `class_exists(FQCN, false)` + `is_subclass_of(\fs_model::class)`; table name byte-stable because `model/tarif_tarifa.php:207` and `controller/tarif_configurador_opcionales.php:63` hardcode it.
  - `test_model_xml_schema_lives_in_catalogo_core` — `plugins/catalogo_core/model/table/tarif_articulo_precios.xml` exists, keyed by `(referencia, codtarifa)`.
  - `test_tarifario_no_longer_ships_the_model_file` — `plugins/tarifario/model/tarif_articulo_precio.php` and `plugins/tarifario/model/table/tarif_articulo_precios.xml` do **not** exist (no dual class).
  - `test_history_write_is_class_exists_soft` — the moved `save()` wraps `tarif_precio_historial::registrar_cambio(...)` in `class_exists('FSFramework\model\tarif_precio_historial')` (AD-4), so standalone catalogo_core never fatals.
  - `test_catalogo_core_has_zero_tarifario_paths_for_the_moved_surface` — grep gate (ATT-07): recursively scan the catalogo_core **production** tree (`controller/`, `model/`, `View/`, `Services/`, `extras/`, `Init.php`), excluding `tests/`, `vendor/`, `openspec/`, and assert **zero** `plugins/tarifario/` hits for the moved article model/tab surface.
  - `test_catalogo_core_bootstraps_the_article_price_table_standalone` — AD-8 source contract: `Init::ensureArticuloTarifaTables()` exists (public static), is called from `init()` (after `ensureOpcionalesTarifaTables()`) and `upgrade()`, uses `is_file` + `class_exists(..., false)` + `is_subclass_of(..., \fs_model::class)` for `tarif_articulo_precio`.
- [x] 1.2 Confirm RED for 1.1: failures are "missing catalogo_core path/class" and the counted grep hit, never a syntax/setup error. Record RED evidence for the apply notes.

### GREEN (atomic move + authorized delta + bootstrap)

- [x] 1.3 Byte-identical move: `plugins/tarifario/model/tarif_articulo_precio.php` → `plugins/catalogo_core/model/tarif_articulo_precio.php`; `plugins/tarifario/model/table/tarif_articulo_precios.xml` → `plugins/catalogo_core/model/table/tarif_articulo_precios.xml`. Then `git rm` both tarifario paths (cwd `plugins/tarifario`). Verify: catalogo_core `git status --short` shows 2 additions; tarifario shows 2 deletions; neither file exists in both plugins.
- [x] 1.4 Apply the single authorized model delta (AD-4/AD-8): in `plugins/catalogo_core/model/tarif_articulo_precio.php::save()` wrap the history write in `if (class_exists('FSFramework\\model\\tarif_precio_historial')) { ... }`. Keep FQCN, `table_name`, `install()`, `url()` and every SQL string unchanged (`url()` still points at `tarif_articulos`/`tarif_articulo_edit`; repoint is deferred to WU-2 per design Open Questions).
- [x] 1.5 Add `public static function ensureArticuloTarifaTables(): void` to `plugins/catalogo_core/Init.php` (AD-8, mirror `ensureOpcionalesTarifaTables()`): `require_once FS_FOLDER . '/base/fs_model.php'`; ensure `tarif_tarifa` via the existing `ensureFamiliasTarifaTables()`; instantiate `tarif_articulo_precio` behind `is_file` + `class_exists(..., false)` + `is_subclass_of(..., \fs_model::class)`; call it from `init()` (after the `ensureOpcionalesTarifaTables()` try/catch) and from `upgrade()`, each wrapped in try/catch + `error_log`.
- [x] 1.6 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php` → green; `ddev exec php -l plugins/catalogo_core/model/tarif_articulo_precio.php plugins/catalogo_core/Init.php`. **Rollback boundary**: revert the 2 moved files and the `Init` edit; `tarif_articulo_precios` data/table untouched.

---

## Phase 2: WU-1.B — endpoint relocation to `fbase_controller` (AD-2, AD-3)

### RED

- [x] 2.1 Move `plugins/tarifario/tests/Controller/TarifTabPreciosTest.php` → `plugins/catalogo_core/tests/TarifTabPreciosTest.php`: namespace `Tests\CatalogoCore`; `setUpBeforeClass()` requires `plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php` + `plugins/catalogo_core/model/tarif_articulo_precio.php` + `plugins/catalogo_core/controller/tarif_tab_precios.php`; tracked-model/controller anonymous subclasses target the catalogo_core FQCN; Twig partial key `@catalogo_core/Hooks/partials/articulo_precios_rows.html.twig`; `injectTwigWithRealPartials()` reads `plugins/catalogo_core/View/Hooks/partials`.
- [x] 2.2 Update the moved test's permission seam to the AD-3 signature `puede_editar_articulo(string $referencia, string $codtarifa): bool` and flip `test_controller_structure_enforces_guard_before_save` to assert `class tarif_tab_precios extends fbase_controller`, `use TarifarioOpcionalStateTrait;`, `requireCsrf`, `template = FALSE`, and guard-before-`->save(`. Add `test_permission_gate_dispatches_neutral_event_and_defaults_allow` (ATT-07: zero listeners ⇒ allow) and `test_denied_save_leaves_stored_values_unchanged` (ATT-02 scenario 3). Keep `test_deny_blocks_price_save_and_responds_json_error`, `test_allow_saves_price_via_articulo_precio_get_and_save`, `test_csrf_failure_rejects_before_model_access`, `test_rows_fragment_renders_row_tariff_coddivisa`, `test_save_response_rerenders_rows_with_real_referencia_and_saved_value`, `test_delete_branch_response_rerenders_rows_with_real_context`, `test_no_bare_simbolo_divisa_calls_in_hook_partials`.
- [x] 2.3 Verify RED: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/TarifTabPreciosTest.php` fails per test because `plugins/catalogo_core/controller/tarif_tab_precios.php` does not exist yet (class not found), not because of a fixture/setup error.

### GREEN

- [x] 2.4 Byte-copy `plugins/tarifario/controller/tarif_tab_precios.php` → `plugins/catalogo_core/controller/tarif_tab_precios.php`; `git rm plugins/tarifario/controller/tarif_tab_precios.php` (cwd `plugins/tarifario`).
- [x] 2.5 Reclass in place (AD-2): `extends tarif_controller` → `extends fbase_controller`; replace the `require_once 'plugins/tarifario/extras/tarif_controller.php'` + `'plugins/tarifario/model/tarif_articulo_precio.php'` with `plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php` + `plugins/catalogo_core/model/tarif_articulo_precio.php`; add `use TarifarioOpcionalStateTrait;`; in `private_core()` call `$this->init_tarifario_opcional_state();` after `parent::private_core()`; render `@catalogo_core/Hooks/partials/articulo_precios_rows.html.twig`. Keep class basename, `page=tarif_tab_precios`, ctor `(FALSE, FALSE)` and `use FSFramework\Core\Html;`.
- [x] 2.6 Neutral permission gate (AD-3): implement `protected function puede_editar_articulo(string $referencia, string $codtarifa): bool` — admin short-circuit (`!empty($this->user->admin)`), else `$event = new ArticlePermissionFilterEvent($referencia, ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE, (string) ($this->user->nick ?? ''), $codtarifa); FSEventDispatcher::getInstance()->dispatch($event, ArticlePermissionFilterEvent::NAME); return $event->isAllowed();`. Import `FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent` + `FSFramework\Event\FSEventDispatcher`; update the call site to `puede_editar_articulo($referencia, $codtarifa)`. Preserve the contractual order `requireCsrf()` → `puede_editar_articulo()` → model `get()/save()/delete()`.
- [x] 2.7 Unit acceptance: moved `TarifTabPreciosTest` green; `ddev exec php -l plugins/catalogo_core/controller/tarif_tab_precios.php`. **Rollback boundary**: revert the endpoint move + the test move; tarifario endpoint/behavior restored.

---

## Phase 3: WU-1.C — hook ownership transfers to catalogo_core (AD-5)

### RED

- [x] 3.1 Write `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` (namespace `Tests\CatalogoCore\Integration`, mirror `CatalogoOpcionalesHookOwnershipTest.php`; boot catalogo_core `Init` + simulate `TwigLoaderEvent`/`TwigInitEvent`):
  - `test_catalogo_core_registers_article_hooks_once_across_rebuilds` (ATT-03: both names registered once with their `@catalogo_core/Hooks/` templates, second rebuild duplicates none).
  - `test_catalogo_core_renders_article_tab_and_pane_without_tarifario` (ATT-04: header carries `page=tarif_tab_precios&action=rows&tipo=articulo&ref=<ref>`; pane carries `id="tab_tarifario_precios"`).
  - `test_unsaved_article_renders_no_article_tab` (ATT-04: `fsc.articulo` empty ⇒ empty header + pane).
  - `test_article_pair_renders_at_frozen_markers_with_tipo_articulo` (ATT-04/`catalogo-render-hooks`: real `ventas_articulo.html.twig` injected inside the tab list and `.tab-content`).
  - `test_endpoint_extends_fbase_controller_and_owns_its_partials` (ATT-02: `class tarif_tab_precios extends fbase_controller`, `use TarifarioOpcionalStateTrait;`, `@catalogo_core/Hooks/partials/articulo_precios_rows.html.twig`, zero `plugins/tarifario/` in source).
- [x] 3.2 Flip `plugins/tarifario/tests/Integration/HookRegistrationTest.php` RED: `test_init_registers_the_two_frozen_article_hooks` becomes `test_tarifario_registers_zero_article_hooks` (both article names unregistered after the Twig build).
- [x] 3.3 Verify RED: catalogo_core integration test fails on unregistered hooks; tarifario flip fails because tarifario still registers the article pair.

### GREEN

- [x] 3.4 Add `private const ARTICULO_HOOK_TEMPLATES = ['ventas_articulo_tabs_after' => '@catalogo_core/Hooks/ventas_articulo_tabs_after.html.twig', 'ventas_articulo_tab_pane_after' => '@catalogo_core/Hooks/ventas_articulo_tab_pane_after.html.twig'];` to `plugins/catalogo_core/Init.php`; make `registerHooks()` iterate `OPCIONAL_HOOK_TEMPLATES` then `ARTICULO_HOOK_TEMPLATES` under the existing single `$hooksRegistered` guard (`@catalogo_core` namespace already maps `View/`); update the const/`registerHooks` docblocks (article pair now catalogo_core-owned).
- [x] 3.5 Byte-move the 4 hook templates: `plugins/tarifario/View/Hooks/ventas_articulo_tabs_after.html.twig` + `..._tab_pane_after.html.twig` → `plugins/catalogo_core/View/Hooks/`; `plugins/tarifario/View/Hooks/partials/articulo_precios_rows.html.twig` + `tab_save_script.html.twig` → `plugins/catalogo_core/View/Hooks/partials/`; `git rm` the 4 tarifario paths (the `plugins/tarifario/View/Hooks/` tree becomes empty). Repoint the pane's `{% include '@tarifario/Hooks/partials/tab_save_script.html.twig' %}` → `@catalogo_core/Hooks/partials/tab_save_script.html.twig`.
- [x] 3.6 Remove article hook ownership from `plugins/tarifario/Init.php` (AD-5): delete `HOOK_TEMPLATES`, `$hooksRegistered`, `registerHooks()`, the `TwigInitEvent` listener, and the now-unused `TwigInitEvent`/`ViewHookRegistry` imports. Keep `TwigLoaderEvent`/`@tarifario` for remaining views, `registerPermissionListener()` (RBAC boundary §3.4), and both temp sweeps.
- [x] 3.7 Unit acceptance: catalogo_core `CatalogoArticuloHookOwnershipTest` green; tarifario `HookRegistrationTest` green (zero article hooks); `ddev exec php -l plugins/catalogo_core/Init.php plugins/tarifario/Init.php`. **Rollback boundary**: revert the `Init` maps + the 4 template moves — tarifario tab ownership restored byte-for-byte.

---

## Phase 4: WU-1.C — htmx 4 + Alpine CSP rewrite of the tab (AD-6)

### RED

- [x] 4.1 Extend `CatalogoArticuloHookOwnershipTest` with the ATT-05 hygiene methods (mirror the opcional twins' assertions):
  - `test_moved_article_tab_pane_boots_htmx_and_alpine` (`htmx.boot(`, `'allowScriptTags': false`, `alpine.boot()`).
  - `test_moved_article_tab_mutations_use_hx_post` (rows partial + save script: `hx-post`, never `hx-delete`).
  - `test_moved_article_tab_registers_alpine_data_with_nonce_and_x_cloak` (`Alpine.data(`, `alpine:init`, `csp_nonce_attr()`, `[x-cloak]`, no `innerHTML`).
  - `test_moved_article_tab_uses_colon_events_and_no_v2_names` (`htmx:after:(swap|request)`; banned `htmx:afterSwap`/`htmx:afterRequest`; no `bootbox`, no `|raw`).
  - `test_moved_article_rows_render_each_tariff_currency` (every price uses `fsc.simbolo_divisa(tarifa.coddivisa)`; no bare `fsc.simbolo_divisa()`).
- [x] 4.2 Verify RED against the byte-moved jQuery templates: assertions fail on `data-ajax-url`/`FSAjaxLoader`/jQuery wiring, `bootbox`, and missing `Alpine.data(`.

### GREEN

- [x] 4.3 Rewrite `plugins/catalogo_core/View/Hooks/ventas_articulo_tabs_after.html.twig` mirroring `ventas_opcional_tabs_after.html.twig`: keep the `fsc.articulo is defined and fsc.articulo and fsc.articulo.referencia` guard (unsaved article ⇒ empty); `hx-get="index.php?page=tarif_tab_precios&action=rows&tipo=articulo&ref={{ fsc.articulo.referencia }}"`, `hx-target="#tab_tarifario_precios_rows"`, `hx-swap="outerHTML"`; keep `href="#tab_tarifario_precios"`/`data-toggle="tab"`; drop `data-ajax-url`. The lazy-load contract is preserved (rows load on tab click, triggered by htmx instead of `FSAjaxLoader`).
- [x] 4.4 Rewrite `plugins/catalogo_core/View/Hooks/ventas_articulo_tab_pane_after.html.twig` mirroring `ventas_opcional_tab_pane_after.html.twig`: `{% import 'Macro/Htmx.html.twig' as htmx %}` + `{% import 'Macro/Alpine.html.twig' as alpine %}`, same `fsc.articulo` guard, `[x-cloak]` style, `<div class="tab-pane" id="tab_tarifario_precios" data-tipo="articulo" x-data="articuloTabPrecios" x-cloak>`, Alpine message callout (`x-show`/`:class`/`x-text`), placeholder `#tab_tarifario_precios_rows`, then `{{ htmx.boot({'allowScriptTags': false}) }}`, `{% include '@catalogo_core/Hooks/partials/tab_save_script.html.twig' %}`, `{{ alpine.boot() }}`.
- [x] 4.5 Rewrite `plugins/catalogo_core/View/Hooks/partials/articulo_precios_rows.html.twig`: keep the 6 columns (Tarifa / Precio / Activo / En tarifa / En catálogo / action) and `fsc.simbolo_divisa(tarifa.coddivisa)`; each row adds hidden inputs (`guardar_precio_tab=1`, `codtarifa`, `referencia`), named checkboxes `activo`/`en_tarifa`/`en_catalogo`, and a save button `hx-post="index.php?page=tarif_tab_precios"` + `hx-include="closest tr"` + `hx-swap="none"`; keep `id="tab_tarifario_precios_rows"` + `data-referencia="{{ referencia }}"` (F2 re-render contract).
- [x] 4.6 Rewrite `plugins/catalogo_core/View/Hooks/partials/tab_save_script.html.twig` mirroring `opcional_tab_save_script.html.twig`: nonce'd `<script {{ csp_nonce_attr() }}>`; register `Alpine.data('articuloTabPrecios')` (state `message`/`messageClass`/`notify(ok,text)`) behind `alpine:init`; window flags `__tarifarioArticuloTabRegistered` / `__tarifarioArticuloTabRequestBound` / `__tarifarioArticuloTabSwapBound` for htmx re-execution idempotency; on `htmx:after:request` parse `{ok,message,html}` and swap `#tab_tarifario_precios_rows` (plain DOM `outerHTML`, never `innerHTML`) + call `notify`; detect the tab request via `hx-post`/`pathInfo.requestPath` containing `tarif_tab_precios`; on `htmx:after:swap` call `Alpine.initTree(target)`. Colon events only.
- [x] 4.7 Unit acceptance: `CatalogoArticuloHookOwnershipTest` + moved `TarifTabPreciosTest` green (including the S28 no-bare-currency and per-row currency assertions); `ddev exec php -l` not applicable to Twig — assert via the tests. **Rollback boundary**: revert the 4 templates to the byte-moved jQuery versions (Phase 3 state).

---

## Phase 5: WU-1.D — tarifario removals/repoints + locked-contract test flip (AD-1, AD-9)

- [x] 5.1 Confirm the tarifario artifacts are gone (already `git rm`'d in Phases 1–3): `plugins/tarifario/model/tarif_articulo_precio.php`, `plugins/tarifario/model/table/tarif_articulo_precios.xml`, `plugins/tarifario/controller/tarif_tab_precios.php`, and the `plugins/tarifario/View/Hooks/` tree.
- [x] 5.2 Repoint the 4 hardcoded requires of the moved model to catalogo_core (keep the `use FSFramework\model\tarif_articulo_precio;` FQCN unchanged): `plugins/tarifario/controller/tarif_articulos.php:24`, `plugins/tarifario/controller/tarif_catalogo_view.php:26`, `plugins/tarifario/controller/tarif_actualizar_precios.php:23`, `plugins/tarifario/controller/tarif_articulo_precios.php:22`.
- [x] 5.3 Repoint the 3 listed tarifario tests' requires: `plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php:63`, `plugins/tarifario/tests/Model/TarifArticuloFactoryForImportTest.php:47`, `plugins/tarifario/tests/Controller/TarifArticulosFamiliaImportTest.php:58`.
- [x] 5.4 `git rm plugins/tarifario/tests/Controller/TarifTabPreciosTest.php` (ownership moved to catalogo_core in Phase 2).
- [x] 5.5 Flip `plugins/tarifario/tests/Integration/HookRegistrationTest.php` (AD-9/ATT-06/ATT-07): keep `test_tarifario_registers_zero_article_hooks` (assert both article and both opcional names unregistered); keep `test_permission_listener_is_registered_on_filter_event`; reduce `test_init_is_idempotent_across_twig_rebuilds` to listener idempotency (hook duplication no longer applies); add `test_rbac_listener_and_grupo_articulo_stay_in_tarifario` (`ArticlePermissionListener` live on `ArticlePermissionFilterEvent::NAME` + `tarif_grupo_articulo` present in tarifario); delete `test_mapped_hook_templates_exist_on_disk`, `test_articulo_hooks_render_tab_shell_and_pane`, `test_catalogo_host_view_renders_the_articulo_tarifas_tab_when_tarifario_is_active`, `test_no_bare_simbolo_divisa_calls_in_hooks_tree` (hook render/S28 coverage relocated to `CatalogoArticuloHookOwnershipTest`).
- [x] 5.6 Leave untouched (no explicit require — class autoloads from active catalogo_core): `extras/tarifario_init.php:134` (`class_exists` instantiation stays an idempotent no-op per AD-8), `:241` (stable table-name list), `Services/ExcelRowUpdater.php`, `Services/ExcelImportWizardService.php`, `process_excel_wizard.php:385`, `model/tarif_articulo.php`, `model/tarif_tarifa_articulo.php`, `controller/tarif_tarifas.php`.
- [x] 5.7 Acceptance: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → green at/above **191 tests / 705 assertions**, zero failures; `ddev exec php -l` on each repointed controller/test. **Rollback boundary**: revert the repoints + restore the moved test — no data or `fs_page` rows touched.

---

## Phase 6: WU-1.D — dual-suite gate + grep audit (AD-11, ATT-07, `catalogo-render-hooks`)

- [x] 6.1 catalogo_core suite: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **554 tests / 1972 assertions OK** (above the 528/1826 floor).
- [x] 6.2 tarifario suite: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → **180 tests / 637 assertions OK** (Skipped 3; count dropped because the moved test left tarifario — zero failures).
- [x] 6.3 Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins` → 1456 tests, **10 failures, all pre-existing/unrelated** (7× OidcProvider DB/schema, 2× `TarifTarifaOpcionalPrecedenceTest` without process isolation, 1× `LegacySupportTest` version drift 1.2.0→1.3.0). Zero new failures vs baseline.
- [x] 6.4 Grep audit: no `plugins/tarifario/model/tarif_articulo_precio` / `plugins/tarifario/controller/tarif_tab_precios` reference remains in the catalogo_core production tree (tests/vendor/openspec excluded); `ArticuloTarifaPrecioOwnershipTest::test_catalogo_core_has_zero_tarifario_paths_for_the_moved_surface` green.
- [x] 6.5 Frozen markers: `Integration/CatalogoCoreHookMarkersTest.php` green unchanged; `git status --porcelain -- View/ventas_articulo.html.twig View/ventas_opcional.html.twig` empty (host views untouched).
- [x] 6.6 Atomicity sanity: catalogo_core shows the additions/edits; tarifario shows the deletions/repoints; no class exists in both plugins (1 in catalogo_core / 0 in tarifario); no core `openspec/` entry.

> **Verify annotation (independent, `sdd-verify`)**: Phase 6 gates were re-executed by the verify actor and are green — catalogo_core **554/1972 OK**, tarifario **180/637 OK (Skipped 3)**, root `Plugins` 10 known pre-existing/unrelated failures (0 new), grep audit zero `plugins/tarifario/` hits, frozen markers 11/11 green, host views byte-unchanged. Full evidence: `verify-report.md`.

---

## Phase 7: atomic two-repo commit + Twig cache clear (AD-1, AD-10)

> **NOT EXECUTED — delivery is human-owned.** No `git commit`/`push` is performed by apply; the 7.x steps are the documented back-to-back landing order for the human. The Twig cache WAS cleared during apply.

- [ ] 7.1 Stage the catalogo_core side (cwd `plugins/catalogo_core`, **explicit paths only** — never `git add -A`): moved `model/tarif_articulo_precio.php` + `model/table/tarif_articulo_precios.xml`, new `controller/tarif_tab_precios.php`, 4 moved/rewritten `View/Hooks/**` templates, edited `Init.php`, new/migrated tests (`ArticuloTarifaPrecioOwnershipTest`, `TarifTabPreciosTest`, `Integration/CatalogoArticuloHookOwnershipTest`). Conventional commit, no AI attribution. _<!-- verify: deferred - human-owned landing; NOT part of the WU-1 verification scope -->_
- [ ] 7.2 Stage the tarifario side (cwd `plugins/tarifario`, explicit paths only): the `git rm`s (model, XML, endpoint, 4 hook templates, moved test), edited `Init.php`, the 4 repointed controllers, the 3 repointed tests, the flipped `HookRegistrationTest`. Matching conventional commit. _<!-- verify: deferred - human-owned landing; NOT part of the WU-1 verification scope -->_
- [ ] 7.3 7.1 and 7.2 land **back-to-back with no intermediate deploy** — the runtime is complete only after both (AD-1). Twig cache clear after both commits: `CacheManager::clearAll()` (or delete `tmp/twig_cache`). **Rollback (AD-10)**: revert the tarifario commit first, then the catalogo_core commit, then clear the Twig cache again; the tarifario-owned tab is restored byte-for-byte. _<!-- verify: deferred - human-owned landing; NOT part of the WU-1 verification scope -->_

---

## Program Follow-ups (sequenced; NOT part of this change's delivery unit)

Each is its own sized delivery with its own rollback and suite gate (from `exploration.md` §5 / `design.md` WU Program Context).

- **WU-2** (depends WU-1): canonical `ventas_articulo` detail absorbs `tarif_articulo_edit`; retire it + `tarif_articulo_precios`; htmx 4 + Alpine migration of the detail. **Active slice — tasks in phases 8–13 below.**
- **WU-3** (depends WU-1): canonical `ventas_articulos` list absorbs `tarif_articulos` filters/columns/quick-create; htmx 4 + Alpine; retire the duplicated list page.
- **WU-4** (depends WU-2/3): move `tarif_articulo`, `tarif_articulos_ext` + XMLs; delete `tarif_descripcion`. Shrunk by AD-W2-1 — `tarif_tarifa_articulo` and `tarif_tarifa_articulo_etiqueta` move in WU-2.
- **WU-5** (depends WU-4): catalog manager ownership (`tarif_catalogo*` models, views, partials, JS) as a catalogo_core-owned page (Option A); retire the slug + `fs_page`.
- **WU-6** (depends WU-5): revision notes and article price history (`tarif_precio_historial`); AD-4's soft `class_exists` guard becomes a hard reference here. Shrunk by AD-W2-7 — `tarif_articulo_imagen` moves in WU-2.
- **WU-7** (depends WU-5/6): rich Excel wizard + JSON import unification; repoint SSE; decide unify-vs-coexist with the basic `ArticuloExcel*` stack.
- **WU-8** (depends all): retirement audit, RBAC boundary confirmation (`ArticlePermissionListener` + role tables stay in tarifario), grep audit of retired slugs, PHPStan + both plugin suites + root `Plugins`, produce `verify-report.md`.

---

## Acceptance Notes

- WU-1 succeeds when: catalogo_core serves the article Tarifas tab and owns `tarif_articulo_precio`; `page=tarif_tab_precios` still resolves with the frozen host markers unchanged; tarifario registers zero article hooks while catalogo_core registers both `ventas_articulo_*` hooks idempotently; the tab uses htmx 4 + Alpine CSP (colon events only, nonce'd `alpine:init`, `[x-cloak]`, no `|raw`, no `bootbox`, no bare `simbolo_divisa`); the POST gate is `requireCsrf()` → neutral `ArticlePermissionFilterEvent` → model, with denial/CSRF persisting nothing; no class exists in both plugins and catalogo_core has zero `plugins/tarifario/` paths for the moved surface; catalogo_core ≥ 528/1826, tarifario ≥ 191/705, root `Plugins` green; no core `openspec/` entry.
- The dev-DB smoke (owned by `sdd-verify`) must confirm: a saved article injects the tab, save persists flags, blank price deletes the row, and a standalone catalogo_core (tarifario inactive) creates `tarif_articulo_precios` via `Init::ensureArticuloTarifaTables()`.
- RBAC boundary is intentionally preserved: `ArticlePermissionListener` + `tarif_grupo_articulo` + role tables stay in tarifario; catalogo_core stays neutral (default-allow with zero listeners).

## Dependencies / Follow-up Notes

- `tarifario` requires `catalogo_core` (`fsframework.ini`); the consumer → catalogo_core direction is preserved. Plugin `fsframework.ini` version bumps are deferred to the `fsframework-plugin-release` skill at release time.
- No Composer dependency is added by WU-1, so no `vendor/` commit is required (plugin `vendor/` stays versioned per `AGENTS.md`).
- Deferred from design Open Questions (do **not** do in WU-1): `tarif_articulo_precio::url()` repoint (WU-2), `TarifarioComponents.html.twig:271` `tarif_catalogo_view` link repoint (WU-5), `TarifarioOpcionalStateTrait` rename/generalize (WU-8), AD-4 guard → hard reference (WU-6), Excel unify-vs-coexist (WU-7).
- The proposal mentions `controller/tarif_articulo_tab.php`; AD-2 supersedes it — the relocated file keeps the `tarif_tab_precios` basename/slug (rejected alternative: new class, extra diff with no value).

---

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~3.3k line-events across the two plugin repos: catalogo_core additions ≈1.9k (model 424, XML ~44, endpoint 175, 4 templates ~150 rewritten, 3 tests ~1.07k, `Init` ~25); tarifario deletions ≈1.4k (moved source ~798, deleted endpoint test ~570, `Init` removals ~60). Authored (excluding byte-identical moves) ≈1.3k: new/migrated tests ~1.07k, htmx/Alpine rewrites ~150, endpoint gate/reclass ~40, `Init` map+bootstrap ~25, 8 repoints. Cross-repo rename detection cannot fire (copy + `git rm`). |
| 400-line budget risk | Medium (WU-1 is move-heavy and mostly byte-identical; the full WU-2..WU-8 program is High) |
| Chained PRs recommended | Yes |
| Suggested split | WU-1.A (ownership + bootstrap) → WU-1.B (endpoint relocation) → WU-1.C (hook ownership + htmx/Alpine tab) → WU-1.D (tarifario flip + dual-suite/grep gate). Atomicity note: the catalogo_core and tarifario commits of a unit coordinate one deliverable and MUST land back-to-back with no intermediate deploy (AD-1); the units are review boundaries, not independent deploys. |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: Medium

> Delivery is pre-decided: `auto-chain` + `stacked-to-main`. The orchestrator proceeds
> without an apply-guard question; the units above are the review/rollback boundaries.
> Atomicity note: the two plugin-repo commits of each unit coordinate one deliverable
> and MUST land back-to-back regardless of the slice mapping.

---

# WU-2: canonical `ventas_articulo` detail absorbs the tarifario edit surface

> Sources of truth: `specs/articulo-detalle-canonico/spec.md` (ART-01..ART-08),
> `design.md` WU-2 (AD-W2-1..AD-W2-11), `exploration.md` §5 WU-2. Delivery unit
> **WU-2** depends on WU-1 (shipped phases 1–7). Strict TDD; `strict_tdd: true`.
>
> **Runners / baselines**
> - `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **554 tests / 1972 assertions** (regression floor).
> - `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → **180 tests / 637 assertions** (regression floor).
> - Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins` (zero new failures).
>
> **RED-before-GREEN**: every phase authors its RED test(s) first and records a RED
> run (failure is a missing catalogo_core path/class, never a syntax/setup error)
> before any implementation.
>
> **Atomic move (AD-W2-10)**: cross-repo `git mv` is impossible (independent plugin
> repos). Copy byte-identical into catalogo_core, `git rm` the tarifario path, then
> explicit-path `git add`. The catalogo_core commit lands **first**, the tarifario
> commit **immediately after, no deploy between** (reverse of WU-1, because WU-2 adds
> before it removes). A class file/XML must never exist in both plugins (AD-W2-11).
>
> **Scope guard (AD-W2-1)**: WU-2 moves exactly `tarif_tarifa_articulo`,
> `tarif_tarifa_articulo_etiqueta`, `tarif_articulo_imagen` (+ their XMLs).
> `tarif_articulo`, `tarif_articulos_ext` and the `tarif_descripcion` deletion stay
> for WU-4.

## Suggested Work Units (WU-2)

Each unit is independently reviewable and reversible; the catalogo_core and tarifario
commits of a unit coordinate one deliverable and land back-to-back (AD-W2-10).

| Unit | Goal | PR | Focused test | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| **WU-2.A** Ownership + models | 3 models/XMLs owned by catalogo_core; `ensureArticuloDetalleTables()`; `fs_page` retirement methods | PR 5 | `plugins/catalogo_core/tests/ArticuloDetalleCanonicoOwnershipTest.php` | catalogo_core suite + `php -l` on 3 moved models/`Init.php` | revert the 6 moved files + the `Init` additions — tables/data untouched |
| **WU-2.B** Controller absorption | `VentasArticulo` edit behavior, per-tarifa sync, etiquetas, images, htmx responses | PR 6 | `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | catalogo_core suite + `php -l` on controller | revert controller + new test — canonical detail restored |
| **WU-2.C** View migration | htmx 4 + Alpine rewrite; boot-ownership flip to the host | PR 7 | `CatalogoArticuloHookOwnershipTest` (additive) + absorption hygiene methods | catalogo_core suite (Twig asserted via tests) | revert view + pane — jQuery detail restored |
| **WU-2.D** Retirement + repoints | retire 2 slugs/views; repoint links/`url()`; retire `fs_page` rows | PR 8 | ownership + absorption grep gates | catalogo_core + tarifario suites + root `Plugins` | revert deletions + repoints; `fs_page` rows recreated on next access |

## Phase 8: WU-2.A — RED tests + atomic model/XML move + bootstrap (AD-W2-1, AD-W2-6, AD-W2-7, AD-W2-8, AD-W2-11)

### RED (author FIRST; run before the move)

- [x] 8.1 Write `plugins/catalogo_core/tests/ArticuloDetalleCanonicoOwnershipTest.php` (namespace `Tests\CatalogoCore`, DB-free, mirror `ArticuloTarifaPrecioOwnershipTest.php`). Methods:
  - `test_moved_models_live_only_in_catalogo_core` — `plugins/catalogo_core/model/{tarif_tarifa_articulo,tarif_tarifa_articulo_etiqueta,tarif_articulo_imagen}.php` + `model/table/{tarif_tarifa_articulo,tarif_tarifa_articulo_etiqueta,tarif_articulo_imagenes}.xml` exist with FQCN `FSFramework\model\*` and byte-stable table names; tarifario counterparts do **not** exist (ART-07, AD-W2-11).
  - `test_retired_edit_surfaces_leave_no_alias` — neither `plugins/tarifario/controller/tarif_articulo_edit.php` / `View/tarif_articulo_edit.html.twig` / `controller/tarif_articulo_precios.php` / `View/tarif_articulo_precios.html.twig` nor catalogo_core twins exist (ART-06).
  - `test_retired_fs_page_rows_are_deleted_idempotently` — `Init::upgrade()` run twice leaves `fs_page::get('tarif_articulo_edit')` + `fs_page::get('tarif_articulo_precios')` FALSE (ART-06 scenario 3, AD-W2-6).
  - `test_catalogo_core_bootstraps_the_absorbed_detail_tables_standalone` — `Init::ensureArticuloDetalleTables()` exists (public static), is called from `init()` after `ensureArticuloTarifaTables()` and from `upgrade()`, uses `is_file` + `class_exists(..., false)` + `is_subclass_of(..., \fs_model::class)`, instantiates FK-safe (`tarif_tarifa_articulo` → `..._etiqueta` → `tarif_articulo_imagen`), and does **not** modify `ensureArticuloTarifaTables()` (AD-W2-8).
  - `test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_detail_surface` — grep gate (ART-07): recursively scan catalogo_core production (`controller/`, `Controller/`, `model/`, `View/`, `Services/`, `extras/`, `Init.php`), excluding `tests/`, `vendor/`, `openspec/`; assert zero `plugins/tarifario/` and `@tarifario/` hits for the moved model/tab/image surface.
- [x] 8.2 Confirm RED for 8.1: failures are "missing catalogo_core path/class" plus the counted grep hits, never a syntax/setup error. Record RED evidence for the apply notes.

### GREEN (atomic move + bootstrap)

- [x] 8.3 Byte-identical move (AD-W2-1): `plugins/tarifario/model/tarif_tarifa_articulo.php` + `model/table/tarif_tarifa_articulo.xml`, `plugins/tarifario/model/tarif_tarifa_articulo_etiqueta.php` + `model/table/tarif_tarifa_articulo_etiqueta.xml`, `plugins/tarifario/model/tarif_articulo_imagen.php` + `model/table/tarif_articulo_imagenes.xml` → `plugins/catalogo_core/model/` (+ `model/table/`). Then `git rm` the 6 tarifario paths (cwd `plugins/tarifario`). Verify no file exists in both plugins. Do **not** touch `tarif_articulo`, `tarif_articulos_ext` or `tarif_descripcion` (WU-4).
- [x] 8.4 Apply the single authorized model delta (AD-W2-5, ART-06): in `plugins/catalogo_core/model/tarif_tarifa_articulo.php::url()` repoint the emitted slug to `index.php?page=ventas_articulo&ref=…&codtarifa=…`. Keep FQCN, table name and every SQL string byte-stable; `tarif_articulo_imagen` keeps `IMAGES_DIR = 'imgs/tarifario/'` and `install()` untouched (AD-W2-7).
- [x] 8.5 Add `public static function ensureArticuloDetalleTables(): void` to `plugins/catalogo_core/Init.php` (AD-W2-8, mirror `ensureArticuloTarifaTables()`): require the 3 moved `FS_FOLDER . '/plugins/catalogo_core/model/*.php'`; instantiate `tarif_tarifa_articulo` → `tarif_tarifa_articulo_etiqueta` → `tarif_articulo_imagen` behind `is_file` + `class_exists(..., false)` + `is_subclass_of(..., \fs_model::class)`; call it from `init()` (after the `ensureArticuloTarifaTables()` try/catch) and from `upgrade()`, each wrapped in try/catch + `error_log`.
- [x] 8.6 Add idempotent `private static function retireTarifArticuloEditPage()` + `retireTarifArticuloPreciosPage()` to `plugins/catalogo_core/Init.php` (AD-W2-6, mirror `retireTarifOpcionalPreciosPage()`); wire each in `upgrade()` inside its own try/catch + `error_log`.
- [x] 8.7 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/ArticuloDetalleCanonicoOwnershipTest.php` → green; `ddev exec php -l` on the 3 moved models + `Init.php`. **Rollback boundary**: revert the 6 moved files + the `Init` additions — `tarif_tarifa_articulo*`/`tarif_articulo_imagenes` data/table untouched.

---

## Phase 9: WU-2.B — controller absorption (AD-W2-2, AD-W2-4; ART-01, ART-02, ART-03)

### RED

- [x] 9.1 Write `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` (namespace `Tests\CatalogoCore\Controller`, DB-free via an anonymous `VentasArticulo` subclass that skips the constructor and stubs the protected seams `articulo_model()`, `tarifa_model()`, `tarifa_articulo_model()`, `etiqueta_model()`, `imagen_model()`). Methods:
  - `test_reference_change_uses_the_article_reference_setter_and_rejects_invalid` (ART-01: `articulo::set_referencia()` rename; invalid/duplicate ⇒ error + no rename).
  - `test_familia_and_flags_are_assigned_on_save` (ART-01).
  - `test_per_tarifa_sync_uses_tarif_tarifa_articulo_for_the_active_tarifa` (ART-01).
  - `test_etiquetas_persist_through_tarif_tarifa_articulo_etiqueta` (ART-01).
  - `test_etiqueta_failure_does_not_discard_the_article_save` (ART-01: error reported, successful article save kept).
  - `test_images_entry_point_is_owned_by_the_canonical_detail` (ART-01 scenario 3).
  - `test_price_editing_reuses_the_wu1_tab_endpoint` (ART-02: no duplicated row/save logic; defers to `page=tarif_tab_precios`).
  - `test_mutations_gate_through_the_neutral_permission_event` (ART-02 + threat table authorization: `puedeEditarArticulo()` default-allow with zero listeners; deny ⇒ no factory/`save()` and stored values unchanged).
  - `test_csrf_failure_runs_no_model_factory_or_save` (threat table CSRF: order `validateFormToken()` → `puedeEditarArticulo()` → model).
  - `test_article_opcional_contract_is_preserved` (ART-03: `public array $articulo_opcionales`, `addOpcionalArticulo`, reuse catalogo_core opcional models + `#opcionales`).
  - `test_image_mutations_stay_scoped_to_the_loaded_article` (threat table file upload: `delete_imagen` scoped to the loaded `referencia`; `upload()` MIME/`imgs/tarifario/` guards preserved).
  - `test_article_delete_is_csrf_guarded_post_only` (threat table destructive delete: `eliminar_articulo` POST + `allow_delete`/`FS_DEMO` guards; no `?delete=` GET branch).
- [x] 9.2 Verify RED: the new test fails because the absorption methods/seams do not exist on `plugins/catalogo_core/Controller/VentasArticulo.php`, not because of a fixture/setup error. Record RED evidence.
- [x] 9.3 Reconfirm `plugins/catalogo_core/tests/VentasArticuloControllerTest.php` stays green **unedited** (ART-08) and note the locked strings to preserve: `public array $idiomas`, `public array $articulo_opcionales`, `saveMultiidiomaDescriptions`, `addOpcionalArticulo`, `#multiidioma`, `#opcionales`, `partials/articulos/tab_multiidioma.html.twig`, `partials/articulos/tab_opcionales.html.twig`, `ref`, `->get(`, `'name' => 'ventas_articulo'`.

### GREEN

- [x] 9.4 Add the protected seam factories (`articulo_model()`, `tarifa_model()`, `tarifa_articulo_model()`, `etiqueta_model()`, `imagen_model()`) + `protected function puedeEditarArticulo(string $referencia, string $codtarifa): bool` (admin short-circuit, else `new ArticlePermissionFilterEvent($referencia, ACTION_EDIT_ARTICLE, nick, $codtarifa)` + `FSEventDispatcher::dispatch(..., NAME)` → `isAllowed()`) to `plugins/catalogo_core/Controller/VentasArticulo.php` (AD-W2-2).
- [x] 9.5 Port the absorption methods into `VentasArticulo`: `resolveCodtarifa()`, `syncTarifaArticulo(\articulo)`, `ensureTarifaFamilyAvailable(?string)`, `saveEtiquetasArticulo(\articulo)`, `loadImagenes`/`uploadImagen`/`deleteImagen`/`destacarImagen`, public `getEtiquetasDisponiblesArticulo()`/`getEtiquetasSeleccionadasArticulo()`; public view state `$codtarifa`, `$tarifas`, `$imagenes`, `$articulo_etiquetas_disponibles`, `$articulo_etiquetas_seleccionadas`, `$puede_editar` (1:1 from `plugins/tarifario/controller/tarif_articulo_edit.php`).
- [x] 9.6 Enforce the contractual save order in `privateCore()` (AD-W2-2): `validateFormToken()` → `puedeEditarArticulo()` → field assignment/rename (`snueva_referencia`) → `articulo::save()` → multiidioma → `syncTarifaArticulo()` → `saveEtiquetasArticulo()`; a per-tarifa/etiqueta failure reports an error and **never discards** the successful article save. Convert `eliminarArticulo` to the POST `eliminar_articulo` mutation (CSRF + `allow_delete` + `FS_DEMO`, redirect to `ventas_articulos`); remove the `?delete=` GET branch.
- [x] 9.7 Add htmx responses (AD-W2-4): handle `upload_imagen` (multipart POST), `query delete_imagen`, `query destacar_imagen`, and the opcional/image mutations; when `$request->headers->get('HX-Request')` is set, echo the re-rendered `plugins/catalogo_core/View/partials/articulos/tab_opcionales.html.twig` (or `#imagenes`) fragment raw with `Content-Type: text/html` + message reflection, guarded by `$this->htmxHandled` so `run()` skips the full-page template. Keep the `isAjaxRequest()` JSON branch (`respondAjax()`/`sendAjaxJson()`) unchanged.
- [x] 9.8 Rewrite `plugins/catalogo_core/View/partials/articulos/tab_opcionales.html.twig` controls to `hx-post` forms targeting `#opcionales` (`hx-swap="outerHTML"`), replacing the jQuery class-name wiring.
- [x] 9.9 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php plugins/catalogo_core/tests/VentasArticuloControllerTest.php` → green; `ddev exec php -l plugins/catalogo_core/Controller/VentasArticulo.php`. **Rollback boundary**: revert the controller edit + the new test.

---

## Phase 10: WU-2.C — htmx 4 + Alpine rewrite + boot-ownership flip (AD-W2-3; ART-04, ART-05)

### RED

- [x] 10.1 Add to `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`: `test_view_uses_htmx4_and_alpine_hygiene` (ART-04: `htmx.boot({'allowScriptTags': false})` + `alpine.boot()`, `Alpine.data(` under `alpine:init`, `csp_nonce_attr()`, `[x-cloak]`, colon event names only, `hx-post` only; no `bootbox`, no `|raw`, no htmx v2 names `htmx:afterSwap`/`htmx:afterRequest`), `test_view_has_no_legacy_jquery_or_bootbox_flows`, `test_view_preserves_tabs_and_frozen_markers` (ART-05: `#datos`, `#precios`, `#stock`, `#multiidioma`, `#opcionales` + the four frozen markers).
- [x] 10.2 Update `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` **additively** (AD-W2-9): flip `test_moved_article_tab_pane_boots_htmx_and_alpine` so the pane no longer boots (`htmx.boot(`/`alpine.boot()` absent, keeps `[x-cloak]`/`x-data`) and add the host-boot assertion; extend `hostFsc()` with the new `tarifas`/`imagenes`/etiquetas keys. Do not touch its other methods.
- [x] 10.3 Verify RED: the hygiene assertions fail on the current jQuery/bootbox view and the pane boot flip fails because the pane still boots htmx/Alpine. Record RED evidence.

### GREEN

- [x] 10.4 Rewrite `plugins/catalogo_core/View/ventas_articulo.html.twig` (AD-W2-3): import `Macro/Htmx.html.twig` + `Macro/Alpine.html.twig`; emit `{{ htmx.boot({'allowScriptTags': false}) }}` + `{{ alpine.boot() }}` once; register `articuloDetalle`, `articuloPrecios`, `articuloTabs`, `articuloOpcionales`, `articuloImagenes` via a nonce'd `alpine:init` script with `Alpine.data()`/`[x-cloak]`/`x-text`; replace `delete_articulo()` bootbox, the global `cambiar_pvp`/`cambiar_pvpi`/`cambiar_margen`/`calcular_margen` + `oninput` handlers, the `$(document).ready` jQuery `.tab('show')`, the 365-line `#opcionales` jQuery block, and every inline `onclick=`/`onchange=` with colon-form `x-on:*` + `hx-post`. Preserve the five tabs and the exact four frozen markers at their current names/positions (`ventas_articulo_tabs_after` at line 129, `ventas_articulo_tab_pane_after` at line 341) with whitespace control + the frozen context; insert new `<li>`/panes **before** the markers (ART-05).
- [x] 10.5 Edit `plugins/catalogo_core/View/Hooks/ventas_articulo_tab_pane_after.html.twig` (AD-W2-3): drop its `htmx.boot()`/`alpine.boot()` (the host now owns boot); keep the `[x-cloak]`/`x-data` shell and the frozen-marker host contract intact.
- [x] 10.6 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` → green (`CatalogoCoreHookMarkersTest` **unedited**, ART-05). **Rollback boundary**: revert the view + pane edits.

---

## Phase 11: WU-2.D — retirement + repoints + `fs_page` (AD-W2-5, AD-W2-6; ART-06)

### RED

- [x] 11.1 Add to `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`: `test_inbound_links_repoint_to_the_canonical_detail` (ART-06 scenario 2 grep gate) — every inbound link/`url()` that targeted the retired slugs points at `page=ventas_articulo` preserving `ref`/`id` + `codtarifa`; `tarif_articulo_precio::url()` emits no retired slug.
- [x] 11.2 Verify RED: live hits remain in `plugins/catalogo_core/model/tarif_articulo_precio.php::url()`, `plugins/tarifario/model/tarif_articulo.php::url()`/`url_tarifario()`, `plugins/tarifario/View/tarif_actualizar_precios.html.twig:255`, `plugins/tarifario/View/tarif_historial_precios.html.twig:188`, `plugins/tarifario/View/tarif_articulos.html.twig:357,373,392`. Record RED evidence.

### GREEN

- [x] 11.3 `git rm` the four retired surfaces (no alias, AD-W2-5): `plugins/tarifario/controller/tarif_articulo_edit.php`, `plugins/tarifario/View/tarif_articulo_edit.html.twig`, `plugins/tarifario/controller/tarif_articulo_precios.php`, `plugins/tarifario/View/tarif_articulo_precios.html.twig`.
- [x] 11.4 Repoint the catalogo_core model: `plugins/catalogo_core/model/tarif_articulo_precio.php::url()` → `index.php?page=ventas_articulo&ref=…` (null → `page=ventas_articulos`).
- [x] 11.5 Repoint the tarifario links: `plugins/tarifario/model/tarif_articulo.php::url()` + `url_tarifario()` → `ventas_articulo&ref=…` (null → `ventas_articulos`); `plugins/tarifario/View/tarif_actualizar_precios.html.twig:255` and `plugins/tarifario/View/tarif_historial_precios.html.twig:188` → `ventas_articulo&ref=…`; `plugins/tarifario/View/tarif_articulos.html.twig:357,373,392` → `ventas_articulo&ref=…&codtarifa=…`.
- [x] 11.6 Repoint the moved-model requires in tarifario (keep the `use FSFramework\model\*` FQCNs): `plugins/tarifario/controller/tarif_tarifas.php:22,:27` and `plugins/tarifario/controller/tarif_catalogo_view.php:22,:33,:2994,:3093` → `plugins/catalogo_core/model/…`.
- [x] 11.7 Update `plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php` (AD-W2-9): drop the retired `plugins/tarifario/View/tarif_articulo_precios.html.twig` from its repoint loop (line ~906); leave the remaining assertions intact.
- [x] 11.8 Unit acceptance: `VentasArticuloArticleEditAbsorptionTest` + `ArticuloDetalleCanonicoOwnershipTest` → green; `ddev exec php -l` on each repointed controller/model. **Rollback boundary**: revert the deletions + repoints; the two `fs_page` rows are recreated on next access (AD-W2-10).

---

## Phase 12: WU-2.E — dual-suite gate + grep audit (AD-W2-11; ART-07, ART-08)

- [x] 12.1 catalogo_core suite: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **≥ 554 tests / 1972 assertions OK**, zero failures.
- [x] 12.2 tarifario suite: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → green, zero failures; no tarifario test asserts a retired slug (ART-08).
- [x] 12.3 Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins` → zero new failures vs baseline.
- [x] 12.4 Grep audit: zero `plugins/tarifario/` + `@tarifario/` hits in the catalogo_core production tree for the absorbed surface (tests/vendor/openspec excluded) — `ArticuloDetalleCanonicoOwnershipTest::test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_detail_surface` green; no moved class file/XML exists in both plugins.
- [x] 12.5 Frozen markers + locked test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` and `plugins/catalogo_core/tests/VentasArticuloControllerTest.php` green **unchanged**; confirm the four marker names/positions survived the rewrite.
- [x] 12.6 Atomicity sanity: catalogo_core shows the additions/edits; tarifario shows the deletions/repoints; no core `openspec/` entry.

---

## Phase 13: WU-2 delivery — atomic two-repo landing (AD-W2-10)

> **NOT EXECUTED — delivery is human-owned.** No `git commit`/`push` by apply; the
> 13.x steps are the documented back-to-back landing order for the human.

- [ ] 13.1 Stage the catalogo_core side (cwd `plugins/catalogo_core`, **explicit paths only** — never `git add -A`): the 3 moved models + 3 XMLs, edited `Controller/VentasArticulo.php`, `View/ventas_articulo.html.twig`, `View/partials/articulos/tab_opcionales.html.twig`, `View/Hooks/ventas_articulo_tab_pane_after.html.twig`, `model/tarif_articulo_precio.php`, `Init.php`; new tests `tests/ArticuloDetalleCanonicoOwnershipTest.php` + `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`; updated `tests/Integration/CatalogoArticuloHookOwnershipTest.php` + `tests/CatalogoOpcionalesUnifiedControllerTest.php`. Conventional commit, no AI attribution. _<!-- verify: deferred - human-owned landing; NOT part of the WU-2 verification scope -->_
- [ ] 13.2 Stage the tarifario side (cwd `plugins/tarifario`, explicit paths only): the 6 model/XML `git rm`s, the 4 retired controller/view deletions, edited `model/tarif_articulo.php`, `controller/tarif_tarifas.php`, `controller/tarif_catalogo_view.php`, `View/tarif_actualizar_precios.html.twig`, `View/tarif_historial_precios.html.twig`, `View/tarif_articulos.html.twig`. Matching conventional commit. _<!-- verify: deferred - human-owned landing; NOT part of the WU-2 verification scope -->_
- [ ] 13.3 13.1 lands **first**, 13.2 **immediately after with no intermediate deploy** (AD-W2-10, reverse of WU-1: WU-2 adds before it removes). Clear the Twig cache after both: `CacheManager::clearAll()` (or delete `tmp/twig_cache`). **Rollback (AD-W2-10)**: revert 13.2 then 13.1, clear the Twig cache again; the two retired `fs_page` rows stay gone and are recreated on next access. _<!-- verify: deferred - human-owned landing; NOT part of the WU-2 verification scope -->_

---

## WU-2 Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | High: catalogo_core adds ≈2 new test files (~0.9k), controller absorption ≈+300, 744-line view rewrite (~1.1k in-place), `Init` ~+80, 3 moved models/XMLs ≈+3.4k byte-identical; tarifario deletes ≈6 moved files + 4 retired surfaces ≈1.4k. Authored (excluding byte-identical moves) ≈2.3k. |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | WU-2.A (ownership + models/bootstrap) → WU-2.B (controller absorption) → WU-2.C (view htmx/Alpine) → WU-2.D (retirement/repoints + dual-suite gate). Atomicity note: each unit's catalogo_core and tarifario commits coordinate one deliverable and MUST land back-to-back (AD-W2-10). |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

> Delivery is pre-decided: `auto-chain` + `stacked-to-main`. The orchestrator proceeds
> without an apply-guard question; the units above are the review/rollback boundaries.
> Rollback is the reverse-order revert of the two commits (13.3).

---

# WU-3: canonical `ventas_articulos` list absorbs the tarifario article list

> Sources of truth: `specs/articulo-lista-canonica/spec.md` (ALC-01..ALC-08),
> `design.md` WU-3 (AD-W3-1..AD-W3-11), `exploration.md` §5 WU-3. Delivery unit
> **WU-3** depends on WU-1 (WU-2 already landed). Strict TDD; `strict_tdd: true`.
>
> **Runners / baselines**
> - `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **576 tests / 2147 assertions** (regression floor).
> - `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → **180 tests / 637 assertions** (regression floor).
> - Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins` (zero new failures).
>
> **RED-before-GREEN**: every phase authors its RED test(s) first and records a RED
> run (failure is a missing catalogo_core path/class or a missing assertion target,
> never a syntax/setup error) before any implementation.
>
> **Atomic move (AD-W3-10)**: cross-repo `git mv` is impossible (independent plugin
> repos). The catalogo_core commit lands **first**, the tarifario commit
> **immediately after, no deploy between** (same order as WU-2: WU-3 catalogo_core
> adds the trait/services/absorption before tarifario removes the list). A
> controller/view must never exist in both plugins (AD-W3-11).
>
> **Scope guard (AD-W3-1)**: WU-3 moves **nothing new**. Rows come from the
> canonical `\articulo` model; per-tarifa price/state from the already-moved WU-1
> `tarif_articulo_precio`. `tarif_articulo` + `tarif_articulos_ext` stay in
> tarifario for WU-4. `View/ventas_articulo.html.twig` is NOT edited; the four
> frozen hook markers stay untouched.

## Suggested Work Units (WU-3)

Each unit is independently reviewable and reversible; the catalogo_core and tarifario
commits of a unit coordinate one deliverable and land back-to-back (AD-W3-10).

| Unit | Goal | PR | Focused test | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| **WU-3.A** Trait + batch reader | list trait, filter aliases, one-query-per-page `all_for_referencias` map | PR 9 | `plugins/catalogo_core/tests/Services/ArticuloTarifaPrecioBatchReaderTest.php` | catalogo_core suite + `php -l` on trait/service/model/controller | revert the trait + service + model method + controller alias block — data untouched |
| **WU-3.B** Quick-create + seam | per-tarifa fixed/percentage prices, neutral `ArticuloListActionRegistry` dispatch, POST delete | PR 10 | `plugins/catalogo_core/tests/Controller/VentasArticulosListAbsorptionTest.php` | catalogo_core suite + `php -l` on controller/registry | revert controller quick-create/delete/registry + service — canonical list restored |
| **WU-3.C** htmx view | htmx 4 + Alpine rewrite of the canonical list + per-tarifa modal inputs | PR 11 | `plugins/catalogo_core/tests/Integration/CatalogoArticuloListHtmxContractTest.php` | catalogo_core suite (Twig asserted via tests) | revert the view + modal partial — jQuery list restored |
| **WU-3.D** Retirement + repoints | delete `tarif_articulos`, relocate handler, repoint links/`fs_page`, repoint locked tests | PR 12 | `plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php` + `InitUpgradeTest` | catalogo_core + tarifario suites + root `Plugins` | revert deletions + repoints; `fs_page` row recreated on next access |
| **WU-3.E** Gate | dual-suite + root `Plugins` + grep audit + frozen markers + entry-point resolution | PR 13 | all WU-3 tests | catalogo_core + tarifario suites + root `Plugins` | n/a (verification only) |

---

## Phase 14: WU-3.A — list trait + filter aliases + batched per-tarifa reader (AD-W3-1, AD-W3-2, AD-W3-3, AD-W3-4; ALC-01, ALC-02)

### RED (author FIRST; run before implementation)

- [x] 14.1 Write `plugins/catalogo_core/tests/Services/ArticuloTarifaPrecioBatchReaderTest.php` (namespace `Tests\CatalogoCore\Services`, DB-free via a stub `fs_db2`/engine that records `select()` calls, mirror `ArticuloSearchQueryBuilderTest.php`). Methods:
  - `test_single_query_maps_all_referencias` — one `SELECT … WHERE codtarifa = ? AND referencia IN (…)` call for N refs; result map keyed by referencia with `precio`/`activo`/`en_tarifa`/`en_catalogo`; N rows never produce N queries (ALC-02, AD-W3-4).
  - `test_missing_row_defaults_to_0_true_false_false` — a referencia absent from the result maps to `['precio' => 0.0, 'activo' => true, 'en_tarifa' => false, 'en_catalogo' => false]` (ALC-02 default contract).
  - `test_empty_referencias_makes_no_query` — `[]` ⇒ `[]` with zero `select()` calls.
- [x] 14.2 Write `plugins/catalogo_core/tests/Controller/VentasArticulosListAbsorptionTest.php` (namespace `Tests\CatalogoCore\Controller`, DB-free via an anonymous `VentasArticulos` subclass that skips the constructor and stubs the request/trait seams, mirror `VentasArticuloArticleEditAbsorptionTest.php` harness). ALC-01/ALC-02 methods:
  - `test_query_and_b_codfamilia_alias_search_and_codfamilia` — `query`/`b_codfamilia` produce the identical `articulo::search()` args as `search`/`codfamilia` (canonical key wins on conflict); scenario "Aliases match".
  - `test_b_codtarifa_resolves_default_then_first_active` — requested active member wins, else `tarif_tarifa::get_default()`, else first `all_activas()`, else `''`.
  - `test_b_solo_activos_default_true_and_false_includes_blocked` — default TRUE; `b_solo_activos=FALSE` sets `bloqueados = true` for the search.
  - `test_per_tarifa_columns_read_the_batch_map` — the trait accessors (`get_precio_articulo_tarifa`, `articulo_activo_tarifa`, `articulo_en_tarifa_flag`, `articulo_en_catalogo`) read the single cached map and fall back to the ALC-02 defaults.
  - `test_filter_query_params_preserve_the_new_keys` — `getListQueryParams()` keeps `query`/`b_codfamilia`/`b_codtarifa`/`b_solo_activos` for pagination/export.
- [x] 14.3 Confirm RED for 14.1/14.2: failures are "missing `ArticuloTarifaPrecioBatchReader` / `all_for_referencias` / trait accessors", never a syntax/setup error. Record RED evidence for the apply notes.

### GREEN

- [x] 14.4 Create `plugins/catalogo_core/Services/ArticuloTarifaPrecioBatchReader.php` (`FSFramework\Plugins\catalogo_core\Services\ArticuloTarifaPrecioBatchReader`): overridable `precio_model()` seam returning `tarif_articulo_precio`; public `for_referencias(array $refs, string $codtarifa): array` delegating to the model's new `all_for_referencias()`; returns the referencia-keyed map with the ALC-02 defaults; display formatting stays with `CatalogoCurrencyFormatter` (AD-W3-4).
- [x] 14.5 Additive model method only (AD-W3-4): add `public function all_for_referencias(array $refs, string $codtarifa): array` to `plugins/catalogo_core/model/tarif_articulo_precio.php` — one `WHERE codtarifa = <var2str> AND referencia IN (<var2str-quoted list>)` query, map keyed by referencia, `precio`/`activo`/`en_tarifa`/`en_catalogo` cast. Do **not** touch `save()`, `url()`, `install()` or the frozen `Init::ensureArticuloTarifaTables()` body (WU-1 contract).
- [x] 14.6 Create `plugins/catalogo_core/extras/VentasArticulosListTrait.php` (global trait, no namespace, mirroring `VentasOpcionalesListTrait`; PageController-only surface `$this->request`, `$this->db`, `$this->url()`, `$this->validateFormToken()`, `$this->new_message()`/`new_error_msg()`): filter aliases (`query`→`search`, `b_codfamilia`→`codfamilia`), `b_codtarifa` fallback chain, `b_solo_activos` default TRUE / `bloqueados` merge, request-scoped batch-map cache, and public accessors `get_precio_articulo_tarifa($ref)`, `articulo_activo_tarifa($ref)`, `articulo_en_tarifa_flag($ref)`, `articulo_en_catalogo($ref)`, `tarifa_seleccionada()`, `mostrar_precio_tarifa($ref)`, `simbolo_divisa_tarifa(string $coddivisa)` (AD-W3-2, AD-W3-4).
- [x] 14.7 Modify `plugins/catalogo_core/Controller/VentasArticulos.php`: `require_once FS_FOLDER . '/plugins/catalogo_core/extras/VentasArticulosListTrait.php';` + `use \VentasArticulosListTrait;`; normalize the aliases at the top of `privateCore()` before `articulo::search()` (canonical `search`/`codfamilia` win); replace the raw `bloqueados` read with the `b_solo_activos` contract; keep `codfabricante`/`con_stock` and the locked source strings (`search`, `codfamilia`, `codfabricante`, `->search(`, `var2str` or `->search(`) byte-present (AD-W3-3, ALC-08).
- [x] 14.8 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/Services/ArticuloTarifaPrecioBatchReaderTest.php plugins/catalogo_core/tests/Controller/VentasArticulosListAbsorptionTest.php` → green; `ddev exec php -l` on `Services/ArticuloTarifaPrecioBatchReader.php`, `extras/VentasArticulosListTrait.php`, `model/tarif_articulo_precio.php`, `Controller/VentasArticulos.php`. **Rollback boundary**: revert the trait + service + model method + controller alias block — table/data untouched.

---

## Phase 15: WU-3.B — quick-create prices + neutral action-handler seam (AD-W3-5, AD-W3-6, AD-W3-8; ALC-03, ALC-05)

### RED

- [x] 15.1 Create `plugins/catalogo_core/Services/ArticuloListActionRegistry.php` contract (`ArticuloListActionHandlerInterface` + `final class ArticuloListActionRegistry` in the same file): `interface` with `supports(string $action): bool` and `handle(string $action, Request $request, array $state): bool`; registry `register()`, `reset()` (test seam), `dispatch()` (unknown action ⇒ `false`, only handlers whose `supports()` is true run) — AD-W3-6.
- [x] 15.2 Extend `plugins/catalogo_core/tests/Controller/VentasArticulosListAbsorptionTest.php` (same file/harness from 14.2) with the ALC-03/ALC-05/AD-W3-8 RED methods:
  - `test_quick_create_persists_fixed_and_percentage_prices` — `precio_tarifa_<codtarifa>` (fixed) and `porcentaje_tarifa_<codtarifa>` (derives `pvp * (1 + p/100)`) each write their per-tarifa `tarif_articulo_precio` row; fixed wins for the same tarifa; empty ⇒ no row.
  - `test_quick_create_denied_or_csrf_invalid_persists_nothing` — invalid CSRF or a denied `ArticlePermissionFilterEvent` ⇒ no `articulo::save()` and no price writes; the dispatcher carries the resolved `codtarifa` (event constructor 4th arg).
  - `test_quick_create_save_failure_persists_no_prices` — `articulo::save()` returns false ⇒ prices are never attempted; existing `nreferencia`/`ndescripcion`/`npvp`, the duplicate check and the `'No tienes permisos para crear este artículo: '` prefix stay byte-identical.
  - `test_action_entry_points_delegate_through_the_neutral_seam` — `processExcelAction()` runs its canonical cases first and falls through to `ArticuloListActionRegistry::dispatch($action, $request, $state)`; an unknown action returns `false`; `$state` carries `search`, `codfamilia`, `codfabricante`, `con_stock`, `bloqueados`, `b_codtarifa`, `b_solo_activos`, `idiomas`, `tarifas`, `allow_delete` (ALC-05).
  - `test_filtered_export_query_preserves_the_new_filters` — `getExportFilteredQuery()`/`export_excel_filtered` keep the new alias filters via `getListQueryParams()` (ALC-05).
  - `test_list_delete_is_csrf_guarded_post_only` — `eliminarArticulo()` runs `validateFormToken()` → `allow_delete`/`FS_DEMO` guards → `articulo::delete()`; the dead `articulo.url()&delete=` GET link is gone (AD-W3-8).
- [x] 15.3 Confirm RED for 15.2: the new methods fail on the missing registry dispatch / missing per-tarifa price writes / missing POST delete, never on a fixture/setup error. Record RED evidence.

### GREEN

- [x] 15.4 Modify `plugins/catalogo_core/Controller/VentasArticulos.php` quick-create (AD-W3-5): resolve `codtarifa` through the trait, keep the contractual order **`validateFormToken()` → `ArticlePermissionFilterEvent($referencia, ACTION_EDIT_ARTICLE, $nick, $codtarifa)` → `articulo::save()` → per-tarifa writes**; after a successful save write `precio_tarifa_<codtarifa>` (fixed, parsed with the trait/`parse_price_input` semantics) and `porcentaje_tarifa_<codtarifa>` (`pvp * (1 + p/100)`, mirroring `aplicar_porcentaje_masivo`'s factor); fixed wins for the same tarifa; empty ⇒ no row; `save()` failure ⇒ no price writes. No DB transaction wrapper (the locked `VentasArticulosQuickCreateGateCompositionTest` engine stub has no `begin_transaction`).
- [x] 15.5 Modify `plugins/catalogo_core/Controller/VentasArticulos.php` `processExcelAction()`: after its canonical cases, `return ArticuloListActionRegistry::dispatch($action, $this->request, $this->exportState());` — private `exportState()` builds the ALC-05 `$state` array; unknown action still returns `false` (AD-W3-6).
- [x] 15.6 Modify `plugins/catalogo_core/Controller/VentasArticulos.php` delete: add `private function eliminarArticulo(Request $request): void` (`delete=<ref>` field) wired from `privateCore()` on POST; `validateFormToken()` → `allow_delete` + `FS_DEMO` guards → `articulo::delete()`; remove/retire the `articulo.url()&delete=` GET link path from the list (view side lands in Phase 16) (AD-W3-8).
- [x] 15.7 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/Controller/VentasArticulosListAbsorptionTest.php` green; tarifario `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter VentasArticulosQuickCreateGateCompositionTest` green **unedited** (listener ignores `getCodtarifa()`, iterates `all_activas()`); `ddev exec php -l plugins/catalogo_core/Services/ArticuloListActionRegistry.php plugins/catalogo_core/Controller/VentasArticulos.php`. **Rollback boundary**: revert the controller quick-create/delete/dispatch + the registry service — canonical list restored.

---

## Phase 16: WU-3.C — htmx 4 + Alpine CSP rewrite of the canonical list (AD-W3-9; ALC-04)

### RED

- [x] 16.1 Write `plugins/catalogo_core/tests/Integration/CatalogoArticuloListHtmxContractTest.php` (namespace `Tests\CatalogoCore\Integration`, mirror `CatalogoOpcionalesHtmxContractTest.php`; read `View/ventas_articulos.html.twig` + `View/partials/articulos/modal_nuevo_articulo.html.twig`). Methods:
  - `test_filters_use_hx_get_with_push_url` — filters are `hx-get` to the same page with `hx-target="#articulos-list"`, `hx-select="#articulos-list"`, `hx-swap="outerHTML"`, `hx-push-url="true"`; scenario "Filter navigation".
  - `test_mutations_are_hx_post_only` — quick-create + delete are `hx-post`; never `hx-delete` (AD-W3-8/AD-W3-9).
  - `test_alpine_and_htmx_hygiene` — `htmx.boot({'allowScriptTags': false})` + `alpine.boot()`; `Alpine.data(` registered nonce'd (`csp_nonce_attr()`) behind `alpine:init`; `[x-cloak]`; `x-text`/`x-show` only; colon events only (`htmx:after:swap`/`htmx:after:request`; banned `htmx:afterSwap`/`htmx:afterRequest`); no `bootbox`, no `|raw`, no inline `onclick=`/`onchange=`.
  - `test_quick_create_modal_renders_per_tarifa_price_inputs` — the modal partial renders `precio_tarifa_<codtarifa>` (fixed) and `porcentaje_tarifa_<codtarifa>` (percentage) inputs per active tarifa, keeps `{{ csrf_field() }}` and `nreferencia`/`ndescripcion`/`npvp` (AD-W3-5/AD-W3-9).
  - `test_locked_view_strings_are_preserved` — ALC-04/ALC-08 locked strings remain: `{{ include('header.html.twig') }}`, `{{ include('footer.html.twig') }}`, `{{ csrf_field() }}`, the modal partial includes, and the Excel/JSON config carrier (`#catalogo-articulos-excel-config`) + `articulos-excel-import-wizard.js` entry point.
- [x] 16.2 Confirm RED against the current jQuery view: the htmx/Alpine assertions and the per-tarifa modal inputs fail, never on a file/setup error. Record RED evidence.

### GREEN

- [x] 16.3 Rewrite `plugins/catalogo_core/View/ventas_articulos.html.twig` (AD-W3-9): import `Macro/Htmx.html.twig` + `Macro/Alpine.html.twig`, emit `htmx.boot({'allowScriptTags': false})` + `alpine.boot()` once; wrap the results table in `id="articulos-list"`; convert the filter form to `hx-get` + `hx-target="#articulos-list"` + `hx-select="#articulos-list"` + `hx-swap="outerHTML"` + `hx-push-url="true"` (no separate rows-fragment route); add per-tarifa price/state columns (`precio` currency-formatted via the trait, `activo`, `en_tarifa`, `en_catalogo`); register Alpine components `articulosList` (modal + search focus) and `articuloConfirm` (delete confirm → `hx-post`) nonce'd behind `alpine:init` with `[x-cloak]`; replace `show_nuevo_articulo()`/jQuery wiring and the `articulo.url()&delete=` link with Alpine + `hx-post`; keep every locked string from 16.1 (ALC-04, ALC-08).
- [x] 16.4 Edit `plugins/catalogo_core/View/partials/articulos/modal_nuevo_articulo.html.twig`: add the per-tarifa fixed (`precio_tarifa_<codtarifa>`) + percentage (`porcentaje_tarifa_<codtarifa>`) inputs (currency via `CatalogoCurrencyFormatter`); keep `{{ csrf_field() }}`, `nreferencia`/`ndescripcion`/`npvp`, `action="{{ fsc.url() }}"` and `method="post"` (AD-W3-5/AD-W3-9). The modal form is submitted via `hx-post` by `articulosList`.
- [x] 16.5 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/Integration/CatalogoArticuloListHtmxContractTest.php plugins/catalogo_core/tests/VentasArticulosControllerTest.php` → green (`VentasArticulosControllerTest` **unedited**, ALC-08); no `php -l` for Twig — asserted via the tests. **Rollback boundary**: revert the view + modal partial to the jQuery versions.

---

## Phase 17: WU-3.D — `tarif_articulos` retirement + repoints + `fs_page` (AD-W3-6, AD-W3-7; ALC-06, ALC-07)

### RED

- [x] 17.1 Write `plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php` (namespace `Tests\CatalogoCore`, DB-free, mirror `ArticuloDetalleCanonicoOwnershipTest.php`). Methods:
  - `test_retired_list_leaves_no_alias_in_either_plugin` — neither `plugins/tarifario/controller/tarif_articulos.php` / `View/tarif_articulos.html.twig` nor catalogo_core twins exist; no dispatch alias resolves the slug (ALC-06 scenario 1).
  - `test_retired_slug_has_zero_inbound_links` — grep gate across catalogo_core + tarifario production: zero `page=tarif_articulos`; each former inbound link targets `page=ventas_articulos` mapping `codtarifa` → `b_codtarifa` (ALC-06 scenario 2; enumerates `ventas_opcionales.html.twig:190`, `Macro/TarifarioComponents.html.twig:259`, `tarif_tarifas.html.twig:53`, `tarif_actualizar_precios.html.twig:71`, `tarif_historial_precios.html.twig:51`, `tarif_articulo.html.twig:23,219`).
  - `test_retired_fs_page_row_is_deleted_idempotently` — `Init::upgrade()` run twice leaves `fs_page::get('tarif_articulos')` FALSE; the `retireTarifArticulosPage()` body gates `->delete()` behind the `fs_page::get()` result (ALC-06 scenario 3, AD-W3-7).
  - `test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_list_surface` — recursively scan catalogo_core production (`Controller/`, `controller/`, `model/`, `View/`, `Services/`, `extras/`, `Init.php`), excluding `tests/`, `vendor/`, `openspec/`; assert zero `plugins/tarifario/` and `@tarifario/` hits for the absorbed list (ALC-07).
  - `test_tarif_articulo_and_ext_stay_in_tarifario_for_wu4` — `plugins/tarifario/model/tarif_articulo.php` + `tarif_articulos_ext.php` (+ XMLs) still exist in tarifario and have no catalogo_core twin (AD-W3-1, ALC-07).
- [x] 17.2 Update `plugins/catalogo_core/tests/InitUpgradeTest.php` (assertion): add `upgradeRetiresTarifArticulosPageIdempotently()` mirroring `upgradeRetiresTarifOpcionalesPageIdempotently()` — asserts the `retireTarifArticulosPage(): void` signature, its wiring in `upgrade()`, `get('tarif_articulos')` and the gated `->delete()` regex (ALC-06).
- [x] 17.3 Confirm RED for 17.1/17.2: live hits remain in `plugins/tarifario/controller/tarif_articulos.php`, the tarifario/catalogo_core view links and the tarifario locked tests; `Init` lacks the retirement. Record RED evidence.

### GREEN

- [x] 17.4 `git rm` the retired list surfaces (no alias, AD-W3-7): `plugins/tarifario/controller/tarif_articulos.php`, `plugins/tarifario/View/tarif_articulos.html.twig` (cwd `plugins/tarifario`).
- [x] 17.5 Create `plugins/tarifario/Services/ArticuloListActionHandler.php` (`FSFramework\Plugins\tarifario\Services\ArticuloListActionHandler implements ArticuloListActionHandlerInterface`): verbatim relocation of `tarif_articulos`'s non-list methods (`export_template`, `export_articulos`, `import_articulos`, `import_tarifa_json`, `import_json_upload`, `import_json_process`, `limpiar_todo`, `limpiar_articulos`, `limpiar_opcionales`, `limpiar_precios_tarifa` + their private helpers) with `supports()` covering exactly those actions; internals logic unchanged (AD-W3-6/ALC-05).
- [x] 17.6 Modify `plugins/tarifario/Init.php`: add `private static bool $actionHandlerRegistered = false;` + `private function registerArticuloListActionHandler(): void` (`class_exists(ArticuloListActionRegistry::class)` guard, `register(new ArticuloListActionHandler())` once); wire it in `init()` after `registerPermissionListener()` (AD-W3-6).
- [x] 17.7 Repoint the inbound `page=tarif_articulos` links to `page=ventas_articulos` mapping `codtarifa` → `b_codtarifa` (AD-W3-7): catalogo_core `View/ventas_opcionales.html.twig:190` (no tarifa), `View/Macro/TarifarioComponents.html.twig:259` (keep `codtarifa` as `b_codtarifa`); tarifario `View/tarif_tarifas.html.twig:53`, `View/tarif_actualizar_precios.html.twig:71`, `View/tarif_historial_precios.html.twig:51`, `View/tarif_articulo.html.twig:23,219`.
- [x] 17.8 Add idempotent `private static function retireTarifArticulosPage(): void` to `plugins/catalogo_core/Init.php` (mirror `retireTarifOpcionalPreciosPage()`: `fs_page::get('tarif_articulos')` → gated `->delete()`) and wire it in `upgrade()` inside its own try/catch + `error_log` (AD-W3-7).
- [x] 17.9 Repoint the tarifario locked tests (ALC-08): `plugins/tarifario/tests/Controller/TarifArticulosFamiliaImportTest.php` (source/require assertions → `Services/ArticuloListActionHandler.php`; no `tarif_articulos.php` / retired-slug assertion) and `plugins/tarifario/tests/Integration/TarifFamiliaWriteRetirementTest.php` (writer #1/#2 source assertions → the handler; the AD-8 `limpiar_todo` ext-DELETE exception moves with the handler).
- [x] 17.10 Unit acceptance: `ArticuloListaCanonicaOwnershipTest` + `InitUpgradeTest` green; `ddev exec php -l` on the handler, `Init.php`, and each edited model/test; `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter 'TarifArticulosFamiliaImportTest|TarifFamiliaWriteRetirementTest'` green. **Rollback boundary**: revert the deletions + handler + repoints; the `fs_page` row is recreated on next access (AD-W3-10).

---

## Phase 18: WU-3.E — dual-suite gate + grep audit (AD-W3-11; ALC-07, ALC-08)

- [x] 18.1 catalogo_core suite: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **≥ 576 tests / 2147 assertions OK**, zero failures.
- [x] 18.2 tarifario suite: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → green at/above the **180 tests / 637 assertions** floor (counts may shift with the retired list test files), zero failures.
- [x] 18.3 Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins` → zero new failures vs baseline.
- [x] 18.4 Grep audit (ALC-07): zero `plugins/tarifario/` + `@tarifario/` hits in the catalogo_core production tree for the absorbed list surface (tests/vendor/openspec excluded) — `ArticuloListaCanonicaOwnershipTest::test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_list_surface` green; zero `page=tarif_articulos` hits; `ArticuloTarifaPrecioOwnershipTest` and tarifario `Integration/HookRegistrationTest` stay green **unedited**.
- [x] 18.5 No half-moved state (AD-W3-11): every moved/retired file exists in exactly one plugin; `tarif_articulo` + `tarif_articulos_ext` remain tarifario-only (`test_tarif_articulo_and_ext_stay_in_tarifario_for_wu4` green); no class file/XML exists in both plugins.
- [x] 18.6 Frozen markers + locked tests: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` and `plugins/catalogo_core/tests/VentasArticulosControllerTest.php` green **unchanged**; `git status --porcelain -- plugins/catalogo_core/View/ventas_articulo.html.twig` empty (host detail view untouched, four markers at their positions).
- [x] 18.7 Excel/JSON entry points resolve (ALC-05): with tarifario active the `action=` cases (`export_template`, `export_articulos`, `import_articulos`, `import_json`, `import_json_upload`, `import_json_process`, `limpiar_*`) dispatch through `ArticuloListActionRegistry` to the tarifario handler; with tarifario inactive the registry is empty and the canonical basic `ArticuloExcel*` stack serves `export_excel`/`export_excel_filtered`/`preview_excel`/`get_preview`/`excel_import_sse` — `test_action_entry_points_delegate_through_the_neutral_seam` + the repointed `TarifArticulosFamiliaImportTest` green.

> **Verify annotation**: Phase 18 gates are re-executed by the independent `sdd-verify` actor; the report cites catalogo_core ≥ 576/2147, tarifario ≥ 180/637, root `Plugins` zero new failures, the grep/frozen-marker results and the dev-DB smoke (filtered list, quick-create price persistence, POST delete).

---

## Phase 19: WU-3 delivery — atomic two-repo landing (AD-W3-10)

> **NOT EXECUTED — delivery is human-owned.** No `git commit`/`push` by apply; the
> 19.x steps are the documented back-to-back landing order for the human.

- [ ] 19.1 Stage the catalogo_core side (cwd `plugins/catalogo_core`, **explicit paths only** — never `git add -A`): new `extras/VentasArticulosListTrait.php`, `Services/ArticuloTarifaPrecioBatchReader.php`, `Services/ArticuloListActionRegistry.php`; edited `Controller/VentasArticulos.php`, `View/ventas_articulos.html.twig`, `View/partials/articulos/modal_nuevo_articulo.html.twig`, `model/tarif_articulo_precio.php`, `Init.php`, `View/ventas_opcionales.html.twig`, `View/Macro/TarifarioComponents.html.twig`; new tests `tests/Services/ArticuloTarifaPrecioBatchReaderTest.php`, `tests/Controller/VentasArticulosListAbsorptionTest.php`, `tests/Integration/CatalogoArticuloListHtmxContractTest.php`, `tests/ArticuloListaCanonicaOwnershipTest.php`; updated `tests/InitUpgradeTest.php`. Conventional commit, no AI attribution. _<!-- verify: deferred - human-owned landing; NOT part of the WU-3 verification scope -->_
- [ ] 19.2 Stage the tarifario side (cwd `plugins/tarifario`, explicit paths only): the `git rm` of `controller/tarif_articulos.php` + `View/tarif_articulos.html.twig`, new `Services/ArticuloListActionHandler.php`, edited `Init.php`, the 4 repointed views, the 2 repointed locked tests. Matching conventional commit. _<!-- verify: deferred - human-owned landing; NOT part of the WU-3 verification scope -->_
- [ ] 19.3 19.1 lands **first**, 19.2 **immediately after with no intermediate deploy** (AD-W3-10). Clear the Twig cache after both: `CacheManager::clearAll()` (or delete `tmp/twig_cache`). **Rollback (AD-W3-10)**: revert 19.2 then 19.1, clear the Twig cache again; the tarifario list and its action entry points are restored byte-for-byte and the removed `fs_page` row is recreated on next access. _<!-- verify: deferred - human-owned landing; NOT part of the WU-3 verification scope -->_

---

## WU-3 Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | Medium-High: catalogo_core adds ≈3 new production files (trait ~250, batch reader ~90, registry ~90), controller absorption ≈+220, list view rewrite (~250 in-place), modal partial ≈+40, model method ≈+40, `Init` retirement ≈+25; 4 new test files ≈1.6k. tarifario deletes the 2.5k-line list controller + view and relocates the non-list methods into a handler (≈1.2k moved). Authored (excluding the verbatim handler relocation) ≈2.6k. |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | WU-3.A (trait + batch reader + filters) → WU-3.B (quick-create + action seam + POST delete) → WU-3.C (htmx/Alpine view) → WU-3.D (retirement + repoints + `fs_page`) → WU-3.E (dual-suite gate). Atomicity note: each unit's catalogo_core and tarifario commits coordinate one deliverable and MUST land back-to-back (AD-W3-10). |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

> Delivery is pre-decided: `auto-chain` + `stacked-to-main`. The orchestrator proceeds
> without an apply-guard question; the units above are the review/rollback boundaries.
> Atomicity note: the two plugin-repo commits coordinate one deliverable and MUST land
> back-to-back regardless of the slice mapping (19.3).

---

# WU-4: catalogo_core owns the article extension models; `tarif_descripcion` deleted

> Sources of truth: `specs/articulo-modelos-ext/spec.md` (AME-01..AME-06),
> `design.md` WU-4 (AD-W4-1..AD-W4-11). Delivery unit **WU-4** depends on WU-2 + WU-3
> (both applied in the working tree). Strict TDD; `strict_tdd: true`.
>
> **Runners / baselines**
> - `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **602 tests / 2343 assertions** (floor).
> - `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → **180 tests / 637 assertions** (floor, Skipped 3).
> - Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins` (zero new failures vs the known pre-existing 10).
>
> **RED-before-GREEN**: author each RED test first; a RED run must fail on a missing or
> misplaced catalogo_core path/class/schema/bootstrap, never a syntax/setup error.
>
> **Atomic move (AD-W4-7)**: WU-4 **adds before it removes**, so the catalogo_core commit
> lands **first** and the tarifario commit **immediately after, no deploy between** (WU-2
> order). No class file/XML may exist in both plugins at any point.
>
> **Scope guard**: only `tarif_articulo`, `tarif_articulos_ext` +
> `model/table/tarif_articulos.xml` move, plus the `tarif_descripcion` wrapper deletion.
> `plugins/tarifario/model/table/tarif_descripciones.xml` is **NOT** deleted (AD-W4-10,
> WU-8 audit). No data migration, table rename, `fs_page` or Composer change.

## Suggested Work Units (WU-4)

| Unit | Goal | PR | Focused test | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| **WU-4.A** Ownership test + atomic move + wrapper deletion | catalogo_core owns the 2 models + XML; `tarif_descripcion` gone | PR 14 | `plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php` | catalogo_core suite + `php -l` on the moved models | revert the move + wrapper deletion — byte-exact restore |
| **WU-4.B** Require repoints + boot no-op | six hardcoded requires target catalogo_core; no `tarif_descripcion` boot ref | PR 15 | `ArticuloModelosExtOwnershipTest::test_zero_tarifario_model_requires_for_the_moved_models` + `plugins/tarifario/tests/Model/TarifArticuloFactoryForImportTest.php` | tarifario suite + `php -l` on touched files | revert the repoints — tarifario paths restored |
| **WU-4.C** Standalone bootstrap | `Init::ensureArticuloExtTables()` creates `tarif_articulos` idempotently, FK-safe | PR 16 | `ArticuloModelosExtOwnershipTest::test_catalogo_core_bootstraps_the_article_ext_table_standalone` | catalogo_core suite + standalone boot smoke (sdd-verify) | remove the method + wiring — WU-1/WU-2 contracts untouched |
| **WU-4.D** Pinned-test updates | the 2 WU-3 boundary tests stop asserting tarifario ownership | PR 17 | `plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php` + `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | catalogo_core suite | revert the 2 test edits — WU-3 assertions restored |
| **WU-4.E** Gate | dual-suite + root `Plugins` + grep/no-half-moved/frozen markers | PR 18 | all WU-4 tests | catalogo_core + tarifario suites + root `Plugins` | n/a (verification only) |

---

## Phase 20: WU-4.A — RED ownership test + atomic model/XML move + `tarif_descripcion` deletion (AD-W4-1, AD-W4-2, AD-W4-6, AD-W4-10, AD-W4-11; AME-01, AME-02, AME-04)

### RED (author FIRST; run before the move)

- [x] 20.1 Write `plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php` (namespace `Tests\CatalogoCore`, DB-free source+class contracts mirroring `ArticuloDetalleCanonicoOwnershipTest.php`; `MOVED_MODELS` map + prefix-sibling exclusion list). Methods:
  - `test_moved_models_live_only_in_catalogo_core` — `plugins/catalogo_core/model/tarif_articulo.php` + `plugins/catalogo_core/model/tarif_articulos_ext.php` exist and the tarifario twins are absent; `class_exists(FQCN, false)` + `is_subclass_of(\fs_model::class)` (AME-01 scenario 1, AME-04 scenario 1).
  - `test_tarif_articulo_extends_core_articulo_without_own_table` — source pins `namespace FSFramework\model;` + `class tarif_articulo extends \FSFramework\model\articulo` (no own `__construct`/table) (AME-01).
  - `test_moved_model_xml_schema_lives_only_in_catalogo_core` — `plugins/catalogo_core/model/table/tarif_articulos.xml` exists, `plugins/tarifario/model/table/tarif_articulos.xml` absent; source pins `parent::__construct('tarif_articulos')` (AME-01, AME-04).
  - `test_tarif_descripcion_wrapper_is_deleted_and_canonical_description_remains` — no `tarif_descripcion` class file in either plugin; `plugins/catalogo_core/model/core/articulo_descripcion.php` + `model/table/articulo_descripciones.xml` remain (AME-02 scenario 1).
  - `test_moved_paths_are_exact_and_do_not_swallow_prefix_siblings` — the moved-path guard matches exact paths/class tokens and never `tarif_articulo_precio.php`, `tarif_articulo_imagen.php` or `tarif_articulo_edit` (AD-W4-6, AME-04).
- [x] 20.2 Confirm RED for 20.1: failures are "missing catalogo_core model/XML" / "tarifario twin still present" / "wrapper still exists", never a syntax/setup error. Record RED evidence for the apply notes.

### GREEN (atomic move + authorized delta)

- [x] 20.3 Move **verbatim** (byte-identical; FQCN + `table_name` stable) `plugins/tarifario/model/tarif_articulo.php` → `plugins/catalogo_core/model/tarif_articulo.php`; no `use`/FQCN or method-signature edit, keep `extends \FSFramework\model\articulo` (AD-W4-1).
- [x] 20.4 Move **verbatim** `plugins/tarifario/model/tarif_articulos_ext.php` → `plugins/catalogo_core/model/tarif_articulos_ext.php` (keep `parent::__construct('tarif_articulos')`) and `plugins/tarifario/model/table/tarif_articulos.xml` → `plugins/catalogo_core/model/table/tarif_articulos.xml` (PK `referencia`, FK `codfamilia → familias`). `git rm` the three tarifario originals — no class file/XML in both plugins.
- [x] 20.5 Delete `plugins/tarifario/model/tarif_descripcion.php` (`git rm`) and remove its boot block `plugins/tarifario/extras/tarifario_init.php:128-131` (AD-W4-2, AME-02 scenario 2). Do **NOT** delete `plugins/tarifario/model/table/tarif_descripciones.xml` (AD-W4-10, WU-8).
- [x] 20.6 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php` → green; `ddev exec php -l` on the two moved model files. **Rollback boundary**: revert the move + the wrapper deletion (byte-exact).

---

## Phase 21: WU-4.B — repoint the six hardcoded requires + keep the boot no-op (AD-W4-3; AME-02, AME-03, AME-05)

### RED

- [x] 21.1 Extend `plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php` (same file from 20.1) with the AME-03/AME-02 RED methods:
  - `test_zero_tarifario_model_requires_for_the_moved_models` — grep gate over tarifario production + tests: zero `plugins/tarifario/model/tarif_articulo.php` / `tarif_articulos_ext.php` requires; the catalogo_core paths are present in `controller/tarif_actualizar_precios.php:22`, `controller/tarif_catalogo_view.php:34`, `Services/ArticuloListActionHandler.php:31` and the two test requires (AME-03 scenario 1).
  - `test_tarifario_boot_no_longer_references_the_deleted_wrapper` — `plugins/tarifario/extras/tarifario_init.php` contains no `tarif_descripcion` instantiation/class reference; the `tarif_articulos_ext` `class_exists` block stays as an idempotent no-op (AME-02 scenario 2, AME-03 scenario 2).
- [x] 21.2 Confirm RED for 21.1: failures are the six live tarifario requires + the live `tarif_descripcion` boot block, never a fixture error. Record RED evidence.

### GREEN

- [x] 21.3 Repoint exactly the three production requires to the catalogo_core paths (keep every `use FSFramework\model\…` and FQCN unchanged, AD-W4-3): `plugins/tarifario/controller/tarif_actualizar_precios.php:22` and `plugins/tarifario/Services/ArticuloListActionHandler.php:31` → `plugins/catalogo_core/model/tarif_articulo.php`; `plugins/tarifario/controller/tarif_catalogo_view.php:34` → `plugins/catalogo_core/model/tarif_articulos_ext.php`.
- [x] 21.4 Repoint exactly the three test requires (assertions/FQCN unchanged, AME-03/AME-05): `plugins/tarifario/tests/Model/TarifArticuloFactoryForImportTest.php:46,:48` and `plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php:62,:64` → the catalogo_core model paths.
- [x] 21.5 Keep `plugins/tarifario/extras/tarifario_init.php:123-125` (`tarif_articulos_ext` `class_exists` block) as the spec-mandated idempotent no-op — no hardcoded require exists there for either moved model, so no repoint (AD-W4-3, AME-03). Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter 'TarifArticuloFactoryForImportTest|ExcelImportWizardServiceTest'` green with assertions intact; `ddev exec php -l` on the 5 touched PHP files. **Rollback boundary**: revert the repoints — tarifario paths restored.

---

## Phase 22: WU-4.C — standalone bootstrap `Init::ensureArticuloExtTables()` (AD-W4-4, AD-W4-9; AME-01 scenario 2)

### RED

- [x] 22.1 Extend `plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php` with `test_catalogo_core_bootstraps_the_article_ext_table_standalone` — source contract: `ensureArticuloExtTables()` is `public static function …: void`, calls `self::touchNamespacedModel('familia')` **before** the `tarif_articulos_ext` instantiation, guards the catalogo_core path with `is_file` + `require_once`, instantiates behind `class_exists($fqcn, false)` + `is_subclass_of($fqcn, \fs_model::class)`, is wired in `init()` **after** `ensureArticuloDetalleTables()` inside its own `try/catch` + `error_log`, and in `upgrade()` after `self::ensureArticuloDetalleTables();`; the `ensureArticuloTarifaTables()` / `ensureArticuloDetalleTables()` bodies are byte-stable (AME-01 scenario 2, AD-W4-9).
- [x] 22.2 Confirm RED: the method/wiring is absent, failure is not a syntax/setup error. Record RED evidence.

### GREEN

- [x] 22.3 Add `public static function ensureArticuloExtTables(): void` to `plugins/catalogo_core/Init.php` exactly per the design interface block (AD-W4-4/AD-W4-9): `require_once FS_FOLDER . '/base/fs_model.php'`; `self::touchNamespacedModel('familia')` first (FK `codfamilia → familias`); `is_file` + `require_once` of the catalogo_core `model/tarif_articulos_ext.php`; `class_exists($fqcn, false)` + `is_subclass_of($fqcn, \fs_model::class)` before `new $fqcn()`. Do **NOT** modify the frozen `ensureArticuloTarifaTables()` / `ensureArticuloDetalleTables()` bodies.
- [x] 22.4 Wire `self::ensureArticuloExtTables();` in `plugins/catalogo_core/Init.php` `init()` after the `ensureArticuloDetalleTables()` block, inside its own `try { … } catch (\Throwable $e) { error_log('[catalogo_core] articulo ext tables ensure failed: ' . $e->getMessage()); }`; and in `upgrade()` after `self::ensureArticuloDetalleTables();` (AD-W4-4).
- [x] 22.5 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php` green; `ddev exec php -l plugins/catalogo_core/Init.php`; `plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php` + `plugins/catalogo_core/tests/ArticuloDetalleCanonicoOwnershipTest.php` **KEEP green unchanged** (frozen bodies). **Rollback boundary**: remove the method + both wirings — WU-1/WU-2 contracts untouched.

---

## Phase 23: WU-4.D — update the two pinned boundary tests (AD-W4-5, AD-W4-11; AME-06)

### RED

- [x] 23.1 Rewrite `plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php` method `test_tarif_articulo_and_ext_stay_in_tarifario_for_wu4` (`:227`) → `test_tarif_articulo_and_ext_are_owned_by_catalogo_core_after_wu4`: for the 3 paths `model/tarif_articulo.php`, `model/tarif_articulos_ext.php`, `model/table/tarif_articulos.xml`, assert the catalogo_core copy `assertFileExists` and the tarifario twin `assertFileDoesNotExist`; keep the RBAC guard `plugins/tarifario/Services/ArticlePermissionListener.php` exists. Update the class docblock (`:32`) from the "stay tarifario-only / WU-4 boundary" wording to catalogo_core ownership (AD-W4-5).
- [x] 23.2 Update `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php:935` — `$this->source('/plugins/tarifario/model/tarif_articulo.php')` → `$this->source('/plugins/catalogo_core/model/tarif_articulo.php')`; the `url()` assertions stay unchanged (AD-W4-5, AME-06 scenario 2).
- [x] 23.3 Confirm RED for 23.1/23.2: with the move in place (Phase 20) the old method fails on the inverted ownership; the old `:935` path read no longer resolves. No test may assert the moved models stay in tarifario after WU-4.

### GREEN

- [x] 23.4 Run `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` → green. **Rollback boundary**: revert the two test edits — WU-3 assertions restored.

---

## Phase 24: WU-4.E — dual-suite gate + grep audit (AD-W4-6, AD-W4-7, AD-W4-8, AD-W4-11; AME-04, AME-05, AME-06)

- [x] 24.1 catalogo_core suite: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **≥ 602 tests / 2343 assertions OK**, zero failures.
- [x] 24.2 tarifario suite: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → green at/above the **180 tests / 637 assertions** floor, zero failures.
- [x] 24.3 Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins` → zero new failures vs the known pre-existing 10.
- [x] 24.4 Grep audit (AD-W4-6, AME-04 scenario 2): `ArticuloModelosExtOwnershipTest::test_moved_paths_are_exact_and_do_not_swallow_prefix_siblings` + `ArticuloModelosExtOwnershipTest::test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_models` green — zero `plugins/tarifario/` + `@tarifario/` hits in the catalogo_core production tree (tests/vendor/openspec excluded) for the moved models; the gate anchors on exact paths + `class\s+tarif_articulo\b` / `class\s+tarif_articulos_ext\b` / `parent::__construct('tarif_articulos')` and excludes `tarif_articulo_precio`, `tarif_articulo_imagen`, `tarif_articulo_edit`.
- [x] 24.5 No half-moved state (AME-01/AME-04 scenario 1): `ArticuloModelosExtOwnershipTest::test_moved_models_live_only_in_catalogo_core` + `::test_moved_model_xml_schema_lives_only_in_catalogo_core` green — each of the 3 moved paths exists in exactly one plugin; `tarif_articulos.xml` is the only `tarif_articulos` schema in either plugin.
- [x] 24.6 Locked contracts + frozen markers + RBAC (AD-W4-8, AD-W4-11, AME-05 scenario 2, AME-06 scenario 1): `plugins/catalogo_core/tests/VentasArticulosControllerTest.php`, `plugins/catalogo_core/tests/VentasArticuloControllerTest.php`, `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`, `plugins/tarifario/tests/Integration/HookRegistrationTest.php`, `plugins/tarifario/tests/Integration/VentasArticulosQuickCreateGateCompositionTest.php` green **unedited**; the listener stays live on `ArticlePermissionFilterEvent::NAME` and `tarif_grupo_articulo` stays in tarifario.
- [x] 24.7 Behavior preserved (AME-05 scenario 1): `plugins/tarifario/tests/Model/TarifArticuloFactoryForImportTest.php` + `plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php` green with repointed requires and unchanged assertions; `factory_for_import()` still returns a `tarif_articulo` with `factory_precio` + `factory_tarif_ext`.

> **Verify annotation**: Phase 24 gates are re-executed by the independent `sdd-verify` actor; the report cites catalogo_core ≥ 602/2343, tarifario ≥ 180/637, root `Plugins` zero new failures, the exact-path grep / no-half-moved / frozen-marker results and the standalone `ensureArticuloExtTables()` dev-DB smoke (WU-4 Open Question).

---

## Phase 25: WU-4 delivery — atomic two-repo landing (AD-W4-7)

> **NOT EXECUTED — delivery is human-owned.** No `git commit`/`push` by apply; the
> 25.x steps are the documented back-to-back landing order for the human.

- [ ] 25.1 Stage the catalogo_core side (cwd `plugins/catalogo_core`, **explicit paths only** — never `git add -A`): the moved `model/tarif_articulo.php`, `model/tarif_articulos_ext.php`, `model/table/tarif_articulos.xml`; edited `Init.php`; new `tests/ArticuloModelosExtOwnershipTest.php`; updated `tests/ArticuloListaCanonicaOwnershipTest.php`, `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`. Conventional commit, no AI attribution. _<!-- verify: deferred - human-owned landing; NOT part of the WU-4 verification scope -->_
- [ ] 25.2 Stage the tarifario side (cwd `plugins/tarifario`, explicit paths only): the `git rm` of `model/tarif_articulo.php`, `model/tarif_articulos_ext.php`, `model/table/tarif_articulos.xml`, `model/tarif_descripcion.php`; edited `controller/tarif_actualizar_precios.php`, `controller/tarif_catalogo_view.php`, `Services/ArticuloListActionHandler.php`, `extras/tarifario_init.php`; the 2 repointed test files. Matching conventional commit. _<!-- verify: deferred - human-owned landing; NOT part of the WU-4 verification scope -->_
- [ ] 25.3 25.1 lands **first**, 25.2 **immediately after with no intermediate deploy** (AD-W4-7). Clear the Twig cache after both: `CacheManager::clearAll()` (or delete `tmp/twig_cache`). **Rollback (AD-W4-7)**: revert 25.2 then 25.1 and clear the Twig cache again; the tarifario-owned models, the wrapper and the previous require paths are restored byte-for-byte; no data/table/`fs_page` rows were touched (the `tarif_descripciones` legacy table included). Plugin `fsframework.ini` version bumps are deferred to the `fsframework-plugin-release` skill at release time. _<!-- verify: deferred - human-owned landing; NOT part of the WU-4 verification scope -->_

---

## WU-4 Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | High: the verbatim moves alone are ~1.05k lines added in the catalogo_core commit (~750 `tarif_articulo.php` + ~230 `tarif_articulos_ext.php` + ~70 `tarif_articulos.xml`) and ~1.05k deleted in the tarifario commit; authored delta is small — `Init` bootstrap/wiring ≈+35, 1 new ownership test ≈400, the 2 pinned-test edits ≈±15, the wrapper + boot block deletion ≈−20, the 6 require repoints ≈±6. |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | WU-4.A (RED test + atomic move + wrapper deletion) → WU-4.B (repoints + boot no-op) → WU-4.C (standalone bootstrap) → WU-4.D (pinned-test updates) → WU-4.E (dual-suite gate). Atomicity note: the catalogo_core and tarifario commits of a unit coordinate one deliverable and MUST land back-to-back (AD-W4-7). |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

> Delivery is pre-decided: `auto-chain` + `stacked-to-main`. The orchestrator proceeds
> without an apply-guard question; the units above are the review/rollback boundaries.
> Atomicity note: the two plugin-repo commits coordinate one deliverable and MUST land
> back-to-back regardless of the slice mapping (25.3). The prior WU-1/WU-2/WU-3 phases
> and the Program Follow-ups list above are unchanged.

---

# WU-5: `catalogo_core` owns the catalog manager; `tarif_catalogo_view` retired

> Sources of truth: `specs/catalogo-gestion/spec.md` (CAT-01..CAT-08),
> `design.md` WU-5 section (AD-W5-1..AD-W5-14 + the fragment/action endpoint-parity
> table), `exploration.md` §5 WU-5. Delivery unit **WU-5** depends on WU-1..WU-4
> (all applied in the working tree). Strict TDD; `strict_tdd: true`.
>
> **Runners / baselines**
> - `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **611 tests / 2422 assertions** (floor).
> - `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → **180 tests / 637 assertions** (floor, Skipped 3).
> - Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins` (zero new failures vs the known pre-existing 10).
>
> **RED-before-GREEN**: every phase authors its RED test(s) first; a RED run must fail on a
> missing/misplaced catalogo_core path, class, schema, bootstrap or assertion target,
> never a syntax/setup error.
>
> **Atomic move (AD-W5-12)**: WU-5 **adds before it removes**, so the catalogo_core commit
> lands **first** and the tarifario commit **immediately after, no deploy between**.
> Cross-repo `git mv` is impossible (independent plugin repos): byte-identical copy into
> catalogo_core, then `git rm` the tarifario path. No class file/XML/template/partial/JS
> may exist in both plugins at any point; explicit-path `git add` only.
>
> **Scope guard (CAT-08)**: WU-5 moves catalog ownership only. `ExcelHierarchyService`,
> `ExcelImportWizardService`, `ExcelRowUpdater`, `process_excel_wizard.php` and the
> `:477` `descartadas_url` repoint stay in tarifario for **WU-7**. `tarif_revision_nota`
> (WU-6) and the RBAC role tables/listener (WU-8) stay in tarifario.
>
> **Interim RED note**: because the tarifario controller/views are `git rm`'d in phases
> 27–28 while the two moved contract tests and the three audit tests are retired/repointed
> in phase 30, the tarifario suite intentionally carries those expected failures between
> phase 27 and phase 30 (this is the phase-30 RED). Phase-local gates use the catalogo_core
> suite + the phase's focused tests; the dual-suite floor gate is phase 31.

## Suggested Work Units (WU-5)

Each unit is independently reviewable and reversible; the catalogo_core and tarifario
commits of a unit coordinate one deliverable and land back-to-back (AD-W5-12).

| Unit | Goal | PR | Focused test | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| **WU-5.A** Ownership + models | RED 14-method ownership test; 5 models + 5 XMLs owned by catalogo_core | PR 19 | `plugins/catalogo_core/tests/CatalogoManagerOwnershipTest.php` | catalogo_core suite + `php -l` on the 5 moved models | revert the 10 moved files — tables/data untouched |
| **WU-5.B** Canonical page | `VentasCatalogo` on `PageController` + wrapper + `getPageData()`; endpoint-parity map | PR 20 | `CatalogoManagerOwnershipTest` (CAT-02/CAT-08 subset) | catalogo_core suite + `php -l` on controller/wrapper | revert controller + wrapper — tarifario controller restored |
| **WU-5.C** Views/partials/JS + hygiene | 4 renamed views + 12 partials + 9 JS; htmx 4 + Alpine CSP | PR 21 | `plugins/catalogo_core/tests/Controller/TarifCatalogoHtmxContractTest.php` | catalogo_core suite (Twig/JS asserted via tests) | revert the 25 moved/renamed assets + hygiene edits |
| **WU-5.D** Soft seam + bootstrap + retirement | `VentasCatalogoStateTrait`, `ensureCatalogoTables()`, `retireTarifCatalogoViewPage()` | PR 22 | `CatalogoManagerOwnershipTest` + `tests/InitUpgradeTest.php` | catalogo_core suite + `php -l` on trait/`Init.php` | remove trait/bootstrap/retirement + restore the softened couplings |
| **WU-5.E** Test ownership + repoints | 2 contract tests relocated; 3 audit tests + `ArticuloModelosExtOwnershipTest` repointed | PR 23 | moved `TarifCatalogo*Test.php` + `ArticuloModelosExtOwnershipTest` | catalogo_core + tarifario suites | revert the repoints + restore the tarifario test files |
| **WU-5.F** Gate | dual-suite + root `Plugins` + grep/no-half-moved/frozen markers | PR 24 | all WU-5 tests | catalogo_core + tarifario suites + root `Plugins` | n/a (verification only) |

---

## Phase 26: WU-5.A — RED ownership test + atomic 5-model/XML move (AD-W5-4, AD-W5-14; CAT-01)

### RED (author FIRST; run before the move)

- [x] 26.1 Write `plugins/catalogo_core/tests/CatalogoManagerOwnershipTest.php` (namespace `Tests\CatalogoCore`, DB-free source+class contracts, mirror `ArticuloModelosExtOwnershipTest.php`: `source()` reader + `productionFiles()` recursive scan excluding `tests/`, `vendor/`, `openspec/`). Author the **14 methods** from the design Testing Strategy:
  - `test_catalog_models_and_xml_live_only_in_catalogo_core` — `MOVED_MODELS` map `['tarif_catalogo' => ['FSFramework\\model\\tarif_catalogo', 'tarif_catalogos'], 'tarif_catalogo_articulo' => [..., 'tarif_catalogo_articulo'], 'tarif_catalogo_def' => [..., 'tarif_catalogo_defs'], 'tarif_catalogo_def_articulo' => [..., 'tarif_catalogo_def_articulo'], 'tarif_catalogo_def_familia' => [..., 'tarif_catalogo_def_familia']]`; each `plugins/catalogo_core/model/{name}.php` + `model/table/{xml}` exists and the tarifario twin is absent; `class_exists(FQCN, false)` + `is_subclass_of(\fs_model::class)` (CAT-01 scenario 1, AD-W5-14).
  - `test_catalog_table_names_are_byte_stable` — each `parent::__construct('<table>')` matches the map (CAT-01).
  - `test_catalogo_core_bootstraps_the_catalog_tables_standalone` — `Init::ensureCatalogoTables()` is `public static function ...: void`, FK-safe order (`tarif_catalogo` → `tarif_catalogo_def` → `tarif_catalogo_articulo` → `tarif_catalogo_def_articulo` → `tarif_catalogo_def_familia`), `is_file` + `class_exists($fqcn, false)` + `is_subclass_of`, wired in `init()` after `ensureArticuloExtTables()` and in `upgrade()`; frozen WU-1/WU-2/WU-4 bodies untouched (CAT-01 scenario 2, AD-W5-8).
  - `test_canonical_page_is_ventas_catalogo_on_pagecontroller` — `VentasCatalogo` only in catalogo_core, `extends \FSFramework\Controller\PageController`, `use \VentasCatalogoStateTrait;`, no `tarif_controller` require (CAT-02, AD-W5-1).
  - `test_page_registry_wrapper_and_getpagedata` — `controller/ventas_catalogo.php` wrapper extends the PSR-4 class; `getPageData()` = `name=ventas_catalogo`/`menu=catalogo`/`title=Catálogo`/`showonmenu=true`/`ordernum=130`; ctor `setTemplate('ventas_catalogo')` (CAT-02).
  - `test_role_permissions_are_resolved_through_the_soft_seam` — `roleEnTarifa()` uses `class_exists('FSFramework\\model\\tarif_grupo_usuario')` and returns `false` when absent; `initUserPermissions()` semantics (`gestor`/`editor`/`revisor`/`visualizador`, notes flags, `has_access`) preserved; zero listeners/inactive tarifario ⇒ fail-closed (CAT-02/CAT-06, AD-W5-2).
  - `test_retired_catalog_surfaces_leave_no_alias` — neither `plugins/tarifario/controller/tarif_catalogo_view.php` / `View/tarif_catalogo_view.html.twig` / `View/tarif_catalogo_articulos*.html.twig` / `View/tarif_catalogo_search.html.twig` / `View/partials/catalogo/*` / `View/js/catalogo/*` nor catalogo_core twins under the retired names (CAT-05 scenario 1).
  - `test_inbound_links_repoint_to_ventas_catalogo` — grep gate: zero `page=tarif_catalogo_view` in catalogo_core + tarifario production; `catalogo_core/View/Macro/TarifarioComponents.html.twig:271` targets `page=ventas_catalogo&codtarifa=…` (CAT-05 scenario 2).
  - `test_retired_fs_page_row_is_deleted_idempotently` — `Init::upgrade()` run twice leaves `fs_page::get('tarif_catalogo_view')` FALSE; the `retireTarifCatalogoViewPage()` body gates `->delete()` behind the `fs_page::get()` result (CAT-05 scenario 3, AD-W5-8).
  - `test_catalogo_core_has_no_tarifario_path_for_the_catalog_surface` — recursive production scan (`Controller/`, `controller/`, `model/`, `View/`, `Services/`, `extras/`, `Init.php`; excluding `tests/`, `vendor/`, `openspec/`): zero `plugins/tarifario/` + `@tarifario/` hits for the catalog surface (CAT-06 scenario 1, AD-W5-14).
  - `test_rbac_boundary_stays_in_tarifario` — `plugins/tarifario/Services/ArticlePermissionListener.php` exists and is live on `ArticlePermissionFilterEvent::NAME`; RBAC role tables (`tarif_grupo_usuario`/`tarif_grupo_rol`/`tarif_grupo_tarifa`/`tarif_grupo_articulo`) stay in tarifario; catalogo_core owns no RBAC consumer (CAT-06 scenario 2, AD-W5-13).
  - `test_wizard_internals_stay_in_tarifario` — `ExcelHierarchyService`, `ExcelImportWizardService`, `ExcelRowUpdater`, `process_excel_wizard.php` have no catalogo_core twin (CAT-08 scenario 1).
  - `test_descartadas_url_repoint_is_a_wu7_item` — `plugins/tarifario/process_excel_wizard.php:477` still contains `page=tarif_catalogo_view` (CAT-08).
  - `test_catalog_entry_points_still_resolve` — endpoint-parity map pinned on the moved `Controller/VentasCatalogo.php`: fragment templates renamed to `ventas_catalogo_articulos` / `ventas_catalogo_articulos_agrupados` / `ventas_catalogo_search`; `action=` names/methods/response shapes/swap targets preserved (`htmx_articulos`, `htmx_articulos_agrupados`, `htmx_search`, `toggle_en_tarifa|toggle_en_catalogo`, `reorder_articulos`, `inline_edit_articulo`, legacy `get_articulos`/`search`, notes CRUD, `preview_excel`/`get_preview`, downloads); `url()` yields `page=ventas_catalogo`; `process_action()` default JSON error unchanged (CAT-03/CAT-08, AD-W5-5).
- [x] 26.2 Confirm RED: `test_catalog_models_and_xml_live_only_in_catalogo_core` + `test_catalog_table_names_are_byte_stable` fail because the five catalogo_core models/XMLs are absent; the page/bootstrap/retirement/grep methods stay RED until their phases. Record RED evidence for the apply notes.

### GREEN (atomic model/XML move)

- [x] 26.3 Byte-identical move (AD-W5-4) into `plugins/catalogo_core/model/` (+ `model/table/`): `tarif_catalogo.php` (`tarif_catalogos`), `tarif_catalogo_articulo.php` (`tarif_catalogo_articulo`), `tarif_catalogo_def.php` (`tarif_catalogo_defs`), `tarif_catalogo_def_articulo.php` (`tarif_catalogo_def_articulo`), `tarif_catalogo_def_familia.php` (`tarif_catalogo_def_familia`) + the 5 matching XMLs. Then `git rm` the 10 tarifario paths (cwd `plugins/tarifario`). Keep FQCNs (`FSFramework\model\*`) and every `parent::__construct('<table>')` byte-stable; no class file/XML exists in both plugins.
- [x] 26.4 No require repoint (AD-W5-4): confirm `grep -rn "model/tarif_catalogo" plugins/tarifario` returns only `extras/tarifario_init.php` `class_exists` bootstrap blocks (kept as idempotent no-ops per the WU-4 precedent, **no edit**); zero hardcoded `require_once` of the moved models remains.
- [x] 26.5 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoManagerOwnershipTest plugins/catalogo_core/tests/CatalogoManagerOwnershipTest.php` → the two model methods green; `ddev exec php -l` on the 5 moved models. **Rollback boundary**: revert the 10 moved files — `tarif_catalogos`/`tarif_catalogo_defs`/… tables and data untouched.

---

## Phase 27: WU-5.B — canonical page reclass + wrapper + endpoint-parity map (AD-W5-1, AD-W5-3, AD-W5-5; CAT-02, CAT-03)

### RED

- [x] 27.1 Confirm RED for the B-owned methods (authored in 26.1): `--filter 'test_canonical_page_is_ventas_catalogo_on_pagecontroller|test_page_registry_wrapper_and_getpagedata|test_catalog_entry_points_still_resolve'` fails because `plugins/catalogo_core/Controller/VentasCatalogo.php` and `controller/ventas_catalogo.php` do not exist. Never a fixture/setup error. Record RED evidence.
- [x] 27.2 Confirm the endpoint-parity RED: the four `hx-get`/`action=` consumers still point at `page=tarif_catalogo_view` (macro + tarifario views) and no `ventas_catalogo_*` fragment template exists. Record.

### GREEN

- [x] 27.3 Byte-copy the 5,189-line `plugins/tarifario/controller/tarif_catalogo_view.php` → `plugins/catalogo_core/Controller/VentasCatalogo.php`; then `git rm` the tarifario original (catalogo_core lands first, AD-W5-12; add-before-remove). Keep every method body byte-stable except the edits in 27.4–27.7.
- [x] 27.4 Reclass in place (AD-W5-1): add `namespace FSFramework\Plugins\catalogo_core\Controller;`; `class VentasCatalogo extends \FSFramework\Controller\PageController` (drops `extends tarif_controller`); remove `require_once 'plugins/tarifario/extras/tarif_controller.php'` (line 20); `require_once FS_FOLDER . '/src/Controller/PageController.php'`; add `use \VentasCatalogoStateTrait;` + `public array $catalogo_config = [];`; add `public function getPageData(): array` returning `['name' => 'ventas_catalogo', 'title' => 'Catálogo', 'menu' => 'catalogo', 'showonmenu' => true, 'ordernum' => 130]`; ctor `parent::__construct('VentasCatalogo'); $this->setTemplate('ventas_catalogo');`; rename `protected function private_core()` (line 301) → `public function privateCore(&$response, $user, $permissions): void` and replace its first statement (`parent::private_core()`) with `$this->initCatalogoState();`. No `private_core()` remains.
- [x] 27.5 Create the legacy slug wrapper `plugins/catalogo_core/controller/ventas_catalogo.php`: `declare(strict_types=1); require_once FS_FOLDER . '/plugins/catalogo_core/Controller/VentasCatalogo.php'; class ventas_catalogo extends \FSFramework\Plugins\catalogo_core\Controller\VentasCatalogo {}` (mirror `controller/ventas_opcionales.php`).
- [x] 27.6 Endpoint-parity map (AD-W5-5): rename the fragment templates to `ventas_catalogo_articulos`, `ventas_catalogo_articulos_agrupados`, `ventas_catalogo_search` (`$this->template = '…'` at the current lines 829/1044/1096); keep every `action=` name/method/response/swap target from the design's parity table (`htmx_articulos` → `#tbody-{codfamilia}` innerHTML/beforeend; `htmx_articulos_agrupados` → `#grouped-view-{codfamilia}` innerHTML; `htmx_search` → search region; `toggle_en_*`/`reorder_articulos`/`inline_edit_articulo` JSON; legacy `get_articulos`/`search`; downloads `template = FALSE`; `preview_excel`/`get_preview`; notes CRUD; `process_action()` default unchanged). `$this->url()` now yields `index.php?page=ventas_catalogo`, so every `hx-get="{{ fsc.url() }}&…"` keeps working.
- [x] 27.7 AD-W5-3 CSRF shim: add `protected function isCsrfValid(): bool` (in the trait or class) mirroring `fs_controller::validateCsrf()` — `false` for non-POST; otherwise `\FSFramework\Security\CsrfManager::isValid()` over the `_csrf_token`/`_token` body field or the `X-CSRF-TOKEN` header, honoring `FS_CSRF_SOFT`; keep the `preview_excel()` call site unchanged.
- [x] 27.8 Unit acceptance: the three B-owned methods green; `ddev exec php -l plugins/catalogo_core/Controller/VentasCatalogo.php plugins/catalogo_core/controller/ventas_catalogo.php`. **Rollback boundary**: revert the moved/reclassed controller + wrapper; the tarifario original is restored byte-for-byte.

---

## Phase 28: WU-5.C — views/partials/JS move + htmx 4 + Alpine CSP hygiene (AD-W5-6, AD-W5-7; CAT-03, CAT-04, CAT-05)

### RED

- [x] 28.1 Author the relocation-ready `plugins/catalogo_core/tests/Controller/TarifCatalogoHtmxContractTest.php` (namespace `Tests\CatalogoCore\Controller`, class name kept, mirror `CatalogoOpcionalesHtmxContractTest.php`): `source()` reads `plugins/catalogo_core/Controller/VentasCatalogo.php` + `View/ventas_catalogo.html.twig`; `setUpBeforeClass()` requires the catalogo_core controller + `PageController`; the `url()` stub returns `index.php?page=ventas_catalogo`; `renderRequest()` uses `page=ventas_catalogo`. Methods (TCP-01..TCP-08 retargeted, no retired-slug assertion): `test_view_boots_htmx_macro_and_marks_main_region`, `test_family_headers_lazy_load_via_htmx_attributes`, `test_load_more_row_appends_via_hx_beforeend`, `test_fragment_actions_keep_serving_the_pinned_templates`, `test_toolbar_and_tree_entry_points_are_preserved`, `test_alpine_and_htmx_hygiene`, `test_mutations_are_post_only`, `test_catalogo_js_bridges_view_mode_and_sort_refetch_through_htmx_ajax`.
- [x] 28.2 Confirm RED: the hygiene/entry-point methods fail because `View/ventas_catalogo.html.twig`, the `View/partials/catalogo/*` and `View/js/catalogo/*` assets do not exist in catalogo_core yet, the JS `src` still points at tarifario, and `window.catalogoConfig` is still emitted via `|raw`. Never a fixture/setup error. Record RED evidence.
- [x] 28.3 Confirm the repoint RED: `View/Macro/TarifarioComponents.html.twig:271` still targets `page=tarif_catalogo_view`. Record.

### GREEN

- [x] 28.4 Move + rename the 4 top-level views into catalogo_core: `View/tarif_catalogo_view.html.twig` → `View/ventas_catalogo.html.twig`; `View/tarif_catalogo_articulos.html.twig` → `View/ventas_catalogo_articulos.html.twig`; `View/tarif_catalogo_articulos_agrupados.html.twig` → `View/ventas_catalogo_articulos_agrupados.html.twig`; `View/tarif_catalogo_search.html.twig` → `View/ventas_catalogo_search.html.twig`; `git rm` the 4 tarifario paths (CAT-05, AD-W5-7).
- [x] 28.5 Move the 12 `View/partials/catalogo/*` (`toolbar`, `styles`, `panel_notas`, 9 modals) + the 9 `View/js/catalogo/*` (`catalogo-main`, `index`, `excel-export`, `excel-import-wizard`, `images-import`, `images-zip`, `json-import`, `revision-notas`, `utils`) **verbatim** into catalogo_core (relative names kept so `{% include 'partials/catalogo/…' %}` and ES-module relative `import './x.js'` resolve); `git rm` the 21 tarifario paths (both tarifario trees become empty).
- [x] 28.6 Repoint the JS load URL in `View/ventas_catalogo.html.twig`: `<script type="module" src="plugins/tarifario/View/js/catalogo/index.js">` → `plugins/catalogo_core/View/js/catalogo/index.js`.
- [x] 28.7 htmx 4 + Alpine CSP hygiene (AD-W5-6): keep the single `htmx.boot({'allowScriptTags': false})` host ownership; add `{% import 'Macro/Alpine.html.twig' as alpine %}` + `{{ alpine.boot() }}`; register `Alpine.data('catalogoShell', …)` from one nonce'd classic `<script {{ csp_nonce_attr() }}>` behind `document.addEventListener('alpine:init', …)`; add `[x-cloak]`/`x-text`; move the tree/toolbar/notes/images inline `on*=` handlers to `x-on:` colon bindings delegating to the existing JS globals. Replace the `window.catalogoConfig` block (remove `|raw`) with the controller-built `public array $catalogo_config` emitted as `data-catalogo-config="{{ fsc.catalogo_config|json_encode|e('html_attr') }}"` on `#catalogo-main-region` (parse once in the nonce'd script). Mutations POST-only: convert the four `$.ajax({type:'POST'})` fan-outs in `catalogo-main.js` (`reorder_articulos`, `toggle_en_tarifa`, `toggle_en_catalogo`, `inline_edit_articulo`) to `htmx.ajax('POST', …)` keeping the same `action=`/payload/JSON envelope, read through the existing `htmx:after:request` shim; ban `hx-delete`/`hx-put`/`hx-patch`; colon event names only (`htmx:after:swap`/`htmx:after:request`; ban the v2 names); no `bootbox`, no `|raw`. **Residual (documented)**: the deep jQuery DOM layer (`$(...)`, Sortable, `fadeOut`) stays byte-stable — de-jQuery rewrite is WU-8 debt.
- [x] 28.8 Repoint `plugins/catalogo_core/View/Macro/TarifarioComponents.html.twig:271` → `index.php?page=ventas_catalogo&codtarifa={{ fsc.codtarifa }}` (AD-W5-10); confirm zero `page=tarif_catalogo_view` in catalogo_core + tarifario production outside `plugins/tarifario/process_excel_wizard.php:477` (WU-7) and the tests repointed in phase 30.
- [x] 28.9 Unit acceptance: the C-owned `TarifCatalogoHtmxContractTest` methods green; Twig/JS asserted via the tests (no `php -l`). **Rollback boundary**: revert the 4 renamed views + 21 moved partials/JS + the hygiene edits; the tarifario views/JS are restored byte-for-byte.

---

## Phase 29: WU-5.D — soft RBAC seam + standalone bootstrap + slug retirement (AD-W5-2, AD-W5-8, AD-W5-9, AD-W5-13; CAT-01, CAT-02, CAT-05, CAT-06)

### RED

- [x] 29.1 Confirm RED for the D-owned ownership methods (`--filter 'test_role_permissions_are_resolved_through_the_soft_seam|test_catalogo_core_bootstraps_the_catalog_tables_standalone|test_retired_fs_page_row_is_deleted_idempotently|test_retired_catalog_surfaces_leave_no_alias|test_catalogo_core_has_no_tarifario_path_for_the_catalog_surface|test_rbac_boundary_stays_in_tarifario'`): the trait/`Init` methods are absent and the softened couplings still hard-require tarifario. Never a syntax/setup error. Record.
- [x] 29.2 Extend `plugins/catalogo_core/tests/InitUpgradeTest.php` (mirror `upgradeRetiresTarifArticulosPageIdempotently`): add `upgradeRetiresTarifCatalogoViewPageIdempotently()` (asserts `private static function retireTarifCatalogoViewPage(): void`, its wiring in `upgrade()`, `get('tarif_catalogo_view')`, the gated `->delete()` regex) + `test_ensure_catalogo_tables_is_wired_and_fk_safe()` (asserts the `ensureCatalogoTables(): void` signature, the FK-safe order, `is_file`/`class_exists(..., false)`/`is_subclass_of`, and the `init()`/`upgrade()` wiring after `ensureArticuloExtTables()`). RED because `Init.php` lacks both. Record.
- [x] 29.3 Confirm RED for 29.1/29.2. Record RED evidence.

### GREEN

- [x] 29.4 Create `plugins/catalogo_core/extras/VentasCatalogoStateTrait.php` (global trait, no namespace; mirror `TarifarioOpcionalStateTrait`, which stays untouched): load `$idiomas`/`$codidioma` from `catalogo_idioma`, `$tarifas` (active) from `tarif_tarifa`, `$is_admin = $this->user->admin`; port `loadTarifasAccesibles()` + `initUserPermissions()` verbatim except `$this->grupo_usuario_model->get_rol_en_tarifa(...)` → soft `roleEnTarifa(string $codtarifa): string|false` (`$class = 'FSFramework\\model\\tarif_grupo_usuario'; if (!class_exists($class)) { return false; } return (new $class())->get_rol_en_tarifa($codtarifa, (string) $this->user->nick);`); add `initCatalogoState()` (called from `privateCore()`) + `ensureCatalogoTablesOnce()` delegating to `Init::ensureCatalogoTables()`. All role/permission semantics preserved; with tarifario inactive non-admins fail closed (`has_access = false`, restricted view) while the page still renders (AD-W5-2).
- [x] 29.5 Soften the cross-WU couplings (AD-W5-9): remove the hard requires of `plugins/tarifario/model/tarif_grupo_articulo.php` / `tarif_grupo_rol.php` / `tarif_grupo_tarifa.php` (controller lines 3480-3483 + 4041-4045) and `require_once 'plugins/tarifario/model/tarif_revision_nota.php'` (line 35); add `loadTarifarioModel(string $fqcn): ?object` resolving via `fs_model_autoloader` + `class_exists`, degrading gracefully (group columns omitted; notes counters at 0; notes flags false) when tarifario is inactive. Keep `use FSFramework\model\tarif_revision_nota;` (FQCN only, no `plugins/tarifario/` literal) and keep the two `use FSFramework\Plugins\tarifario\Services\{ExcelHierarchyService,ExcelRowUpdater}` imports guarded by `class_exists` returning a JSON error envelope when tarifario is inactive (CAT-08).
- [x] 29.6 Add `public static function ensureCatalogoTables(): void` to `plugins/catalogo_core/Init.php` (AD-W5-8, mirror `ensureArticuloExtTables()`): `require_once FS_FOLDER . '/base/fs_model.php'`; iterate the FK-safe order `['tarif_catalogo', 'tarif_catalogo_def', 'tarif_catalogo_articulo', 'tarif_catalogo_def_articulo', 'tarif_catalogo_def_familia']`, each via `is_file` + `require_once` + `class_exists($fqcn, false)` + `is_subclass_of($fqcn, \fs_model::class)` before `new $fqcn()`. Wire `self::ensureCatalogoTables();` in `init()` after the `ensureArticuloExtTables()` block, inside its own `try/catch` + `error_log`, and in `upgrade()` after `self::ensureArticuloExtTables();`. **Frozen WU-1/WU-2/WU-4 bodies untouched.**
- [x] 29.7 Add idempotent `private static function retireTarifCatalogoViewPage(): void` (`fs_page::get('tarif_catalogo_view')` → gated `->delete()`, mirror `retireTarifArticulosPage()`); wire it in `upgrade()` inside its own `try/catch` + `error_log`. Retire the slug with **no alias** (CAT-05).
- [x] 29.8 Unit acceptance: the D-owned methods + `InitUpgradeTest` green; `ddev exec php -l plugins/catalogo_core/extras/VentasCatalogoStateTrait.php plugins/catalogo_core/Init.php plugins/catalogo_core/Controller/VentasCatalogo.php`; `ArticuloListaCanonicaOwnershipTest` + `ArticuloModelosExtOwnershipTest` + `InitFamiliasTablesTest` keep green (frozen contracts). **Rollback boundary**: remove the trait/bootstrap/retirement + restore the strengthened requires; the tarifario RBAC coupling is restored.

---

## Phase 30: WU-5.E — test-ownership move + audit repoints (AD-W5-10, AD-W5-11; CAT-07)

### RED

- [x] 30.1 Author the relocation-ready `plugins/catalogo_core/tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` (namespace `Tests\CatalogoCore\Controller`, class name kept): `private const CONTROLLER = 'plugins/catalogo_core/Controller/VentasCatalogo.php'`; `setUpBeforeClass()` for `PageController`; the master-model require/import assertions retargeted; `build_opcionales_export` reflection on `VentasCatalogo`; no retired-slug assertion (TCP-05/06/07 bridge contracts kept, TCP-08 re-expressed for the modern base).
- [x] 30.2 Repoint the three tarifario audit tests (AD-W5-10): `plugins/tarifario/tests/Integration/FamiliaOverrideRemovalTest.php:94` source read → `plugins/catalogo_core/Controller/VentasCatalogo.php` (the `new familia()` count/read-only assertions unchanged); `plugins/tarifario/tests/Integration/TarifFamiliaWriteRetirementTest.php:149` writer #3 path → the same; `plugins/tarifario/tests/Integration/LegacyImportRegressionTest.php:88` URL → `page=ventas_catalogo&action=import_excel_chunk`.
- [x] 30.3 Update `plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php` (`:356` require-map entry): `plugins/tarifario/controller/tarif_catalogo_view.php` → `plugins/catalogo_core/Controller/VentasCatalogo.php` (AD-W5-11); confirm `InitUpgradeTest` already carries the phase-29 additions. _(Already landed in WU-5.A–D deviation 4; verified repointed and green.)_
- [x] 30.4 Confirm RED: the three tarifario audit tests fail because the tarifario controller was `git rm`'d in phase 27 and they still read the retired path; the relocated master-export test fails until its `CONTROLLER`/`setUpBeforeClass` retarget lands. Never a syntax/setup error. Record RED evidence.

### GREEN

- [x] 30.5 `git rm plugins/tarifario/tests/Controller/TarifCatalogoHtmxContractTest.php plugins/tarifario/tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` (ownership moved to catalogo_core; the htmx contract was authored at destination in phase 28, the master-export test in 30.1) — no test file exists in both plugins. _(The master-export tarifario copy was untracked, so it was removed from the working tree instead of `git rm`; no index entry existed.)_
- [x] 30.6 Confirm the relocated `TarifCatalogoOpcionalMasterExportTest` + `TarifCatalogoHtmxContractTest` green at their new catalogo_core location, asserting `page=ventas_catalogo` and never `tarif_catalogo_view`. _(Requires `tests/Support/CatalogoCoreSeedStubs.php` — the `InitUpgradeTest` stubs were moved out of file scope; see apply-progress WU-5.E–F deviation 1.)_
- [x] 30.7 Confirm the three repointed tarifario audit tests + `ArticuloModelosExtOwnershipTest` green with their assertions unchanged (writer-audit paths follow the moved controller byte-for-byte, AD-W5-10).
- [x] 30.8 Unit acceptance: the 4 test files green; `ddev exec php -l` on the repointed test files; `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter 'FamiliaOverrideRemovalTest|TarifFamiliaWriteRetirementTest|LegacyImportRegressionTest'` green. **Rollback boundary**: revert the test repoints + restore the two tarifario test files; the moved catalogo_core tests revert.

---

## Phase 31: WU-5.F — dual-suite gate + grep audit (AD-W5-12, AD-W5-14; CAT-06, CAT-07, CAT-08)

- [x] 31.1 catalogo_core suite: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **≥ 611 tests / 2422 assertions OK**, zero failures. _(Observed **OK 641 tests / 2741 assertions** — Warnings 26, Skipped 1.)_
- [x] 31.2 tarifario suite: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → green at/above the **180 tests / 637 assertions** floor (counts shift with the 2 relocated test files), zero failures. _(Observed **OK 162 tests / 555 assertions**, Skipped 3; the 18 relocated methods moved to catalogo_core per AD-W5-11, so the pre-relocation floor no longer applies verbatim.)_
- [x] 31.3 Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins` → zero new failures vs the known pre-existing 10. _(Observed 1522 tests, **10 failures = exactly the known set** — 7× OidcProvider, 2× `TarifTarifaOpcionalPrecedenceTest`, 1× `LegacySupportTest`.)_
- [x] 31.4 Grep audit (CAT-06/AD-W5-14): `CatalogoManagerOwnershipTest::test_catalogo_core_has_no_tarifario_path_for_the_catalog_surface` + `ArticuloListaCanonicaOwnershipTest` (**KEEP unedited**) green — zero `plugins/tarifario/` + `@tarifario/` hits in the catalogo_core production tree (tests/vendor/openspec excluded); `page=tarif_catalogo_view` survives only in `plugins/tarifario/process_excel_wizard.php:477` (accepted WU-7 item).
- [x] 31.5 No half-moved state (AD-W5-14): `test_catalog_models_and_xml_live_only_in_catalogo_core` + `test_retired_catalog_surfaces_leave_no_alias` + `test_inbound_links_repoint_to_ventas_catalogo` green — each moved model/XML/view/partial/JS exists in exactly one plugin; `fs_model_autoloader` resolves `FSFramework\model\tarif_catalogo*` only from catalogo_core; no class file/XML in both plugins.
- [x] 31.6 Locked contracts + frozen markers + RBAC (CAT-07/CAT-08): `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`, `VentasArticulosControllerTest.php`, `VentasArticuloControllerTest.php` green **unedited**; tarifario `Integration/HookRegistrationTest.php` green unedited; the listener stays live on `ArticlePermissionFilterEvent::NAME` and `tarif_grupo_articulo` stays in tarifario (`test_rbac_boundary_stays_in_tarifario` green); wizard internals unmoved (`test_wizard_internals_stay_in_tarifario` + `test_descartadas_url_repoint_is_a_wu7_item` green).
- [x] 31.7 Standalone boot smoke (owned by `sdd-verify`): standalone catalogo_core boots and `ensureCatalogoTables()` ensures the 5 catalog tables idempotently on a second boot (dev-DB). _<!-- verify: deferred - sdd-verify-owned dev-DB smoke; source-contract coverage green in `CatalogoManagerOwnershipTest` -->_
- [x] 31.8 No core `openspec/` entry: `git status --porcelain -- openspec/` empty; the change lives only under `plugins/catalogo_core/openspec/changes/absorber-articulos-tarifa-en-catalogo-core/`.

---

## Phase 32: WU-5 delivery — atomic two-repo landing (AD-W5-12)

> **NOT EXECUTED — delivery is human-owned.** Phases 30–31 were executed by apply
> (working tree only); the 32.x steps below are the documented landing order for the
> human and are intentionally left `[ ]`. **No `git commit`/`push` by apply.**
>
> **Landing order (AD-W5-12, add-before-remove)**: the **catalogo_core commit FIRST**,
> the **tarifario commit IMMEDIATELY AFTER with no deploy between them**; the runtime is
> complete only after both. Clear the Twig cache after both. Revert in reverse order
> (tarifario, then catalogo_core) and clear the cache again.

- [ ] 32.1 Stage the catalogo_core side (cwd `plugins/catalogo_core`, **explicit paths only** — never `git add -A`): the 5 moved models + 5 XMLs; `Controller/VentasCatalogo.php`; `controller/ventas_catalogo.php`; `extras/VentasCatalogoStateTrait.php`; the 4 renamed views + 12 partials + 9 JS; edited `View/Macro/TarifarioComponents.html.twig`; edited `Init.php`; new `tests/CatalogoManagerOwnershipTest.php`; moved `tests/Controller/TarifCatalogoHtmxContractTest.php` + new `tests/Controller/TarifCatalogoOpcionalMasterExportTest.php`; new `tests/Support/CatalogoCoreSeedStubs.php`; updated `tests/InitUpgradeTest.php` + `tests/ArticuloModelosExtOwnershipTest.php`. Conventional commit, no AI attribution.
- [ ] 32.2 Stage the tarifario side (cwd `plugins/tarifario`, explicit paths only): the `git rm`s of the 5 models + 5 XMLs + `controller/tarif_catalogo_view.php` + the 4 views + the 12 partials + the 9 JS + the tracked `tests/Controller/TarifCatalogoHtmxContractTest.php`; the untracked `tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` copy was already deleted from the working tree (no index entry); edited `tests/Integration/FamiliaOverrideRemovalTest.php`, `tests/Integration/TarifFamiliaWriteRetirementTest.php`, `tests/Integration/LegacyImportRegressionTest.php`. Matching conventional commit. `process_excel_wizard.php` is **not** edited (CAT-08).
- [ ] 32.3 32.1 lands **first**, 32.2 **immediately after with no intermediate deploy** (AD-W5-12, add-before-remove). Clear the Twig cache after both: `CacheManager::clearAll()` (or delete `tmp/twig_cache`). **Rollback (AD-W5-12)**: revert 32.2 then 32.1 and clear the Twig cache again; the tarifario-owned controller, models, views, partials and JS are restored byte-for-byte and the `tarif_catalogo_view` `fs_page` row is recreated on the first request after the revert. No table/data/Composer change (only tables `ensureCatalogoTables()` creates, data-preserving). Plugin `fsframework.ini` version bumps are deferred to the `fsframework-plugin-release` skill.

> **WU-5/WU-7 boundary (accepted, CAT-08)**: `plugins/tarifario/process_excel_wizard.php:477`
> `descartadas_url` keeps `page=tarif_catalogo_view`, so the SSE "download discarded rows"
> link returns 404 until WU-7 repoints it. WU-5 intentionally does not pull the Excel/JSON
> wizard internals in to satisfy it.

---

## WU-5 Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | High: the verbatim moves are ~5.2k (controller) + ~25 catalog assets (views/partials/JS ≈2.5k) added in the catalogo_core commit and deleted in the tarifario commit; authored delta — ownership test ~600, moved contract tests ~700 retargeted, `Init` bootstrap/retirement ≈+40, state trait ≈+90, hygiene pass on views/`catalogo-main.js` ~+150/−120, repoints/audit ≈±20, `InitUpgradeTest`/`ArticuloModelosExtOwnershipTest` edits ≈+60. Authored (excluding byte-identical moves) ≈1.7k. |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | WU-5.A (RED ownership test + model/XML move) → WU-5.B (canonical page reclass + wrapper + endpoint parity) → WU-5.C (views/partials/JS + htmx/Alpine hygiene) → WU-5.D (soft RBAC seam + bootstrap + `fs_page` retirement) → WU-5.E (test-ownership move + audit repoints) → WU-5.F (dual-suite gate). Atomicity note: each unit's catalogo_core and tarifario commits coordinate one deliverable and MUST land back-to-back (AD-W5-12). |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

> Delivery is pre-decided: `auto-chain` + `stacked-to-main`. The orchestrator proceeds
> without an apply-guard question; the units above are the review/rollback boundaries.
> Atomicity note: the two plugin-repo commits coordinate one deliverable and MUST land
> back-to-back regardless of the slice mapping (32.3). Delivery is human-owned (no commit
> by apply); the accepted `descartadas_url` WU-7 404 boundary is recorded in 32.

---

# WU-6: `catalogo_core` owns revision notes and article price history

> Sources of truth: `specs/articulo-notas-historial/spec.md` (ANH-01..ANH-07),
> `design.md` WU-6 (AD-W6-1..AD-W6-9 + File Changes + Testing Strategy + Threat Matrix),
> `exploration.md` §5 WU-6. Delivery unit **WU-6** depends on WU-5 (applied in the
> working tree). Strict TDD; `strict_tdd: true`.
>
> **Runners / baselines**
> - `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **641 tests / 2741 assertions** (floor).
> - `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → **162 tests / 555 assertions** (floor, Skipped 3).
> - Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins` (zero new failures vs the known pre-existing 10).
>
> **RED-before-GREEN**: author each RED test first; a RED run must fail on a missing or
> misplaced catalogo_core path/class/schema/bootstrap or a live tarifario path, never a
> syntax/setup error.
>
> **Atomic move (AD-W6-8)**: WU-6 **adds before it removes**, so the catalogo_core commit
> lands **first** and the tarifario commit **immediately after, no deploy between**.
> Cross-repo `git mv` is impossible (independent plugin repos): byte-identical copy into
> catalogo_core, then `git rm` the tarifario path; explicit-path `git add` only. No class
> file/XML/controller/view may exist in both plugins at any point.
>
> **Scope guard**: only `tarif_revision_nota`, `tarif_precio_historial` (+XMLs), the
> `tarif_historial_precios` controller/view and the two softening hardenings move. Article
> images landed in WU-2; the RBAC role tables (`tarif_grupo_articulo`/`tarif_grupo_rol`/
> `tarif_grupo_tarifa`) + `ArticlePermissionListener` stay in tarifario (ANH-06) resolved
> only through `loadTarifarioModel()`. No table rename, no data migration, no Composer
> dependency, no core `openspec/` entry.

## Suggested Work Units (WU-6)

Each unit is independently reviewable and reversible; the catalogo_core and tarifario
commits of a unit coordinate one deliverable and land back-to-back (AD-W6-8).

| Unit | Goal | PR | Focused test | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| **WU-6.A** Ownership + models | RED ownership test; `tarif_revision_nota` + `tarif_precio_historial` (+XMLs) owned by catalogo_core; `tarifario_init.php` require repoints | PR 25 | `plugins/catalogo_core/tests/ArticuloNotasHistorialOwnershipTest.php` | catalogo_core suite + `php -l` on the 2 moved models/`tarifario_init.php` | revert the 4 moved files + the 2 require repoints — tables/data untouched |
| **WU-6.B** Seam + guard hardening | local `revision_nota_model()` factory; hard local history write; pinned-test inversion | PR 26 | `ArticuloNotasHistorialOwnershipTest` + `plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php` | catalogo_core suite + `php -l` on `Controller/VentasCatalogo.php` + `model/tarif_articulo_precio.php` | revert the seam + guard edits + restore the pinned test |
| **WU-6.C** Page + bootstrap + retirement | reclassed history controller/view; `\|raw` removed; `ensureNotasHistorialTables()`; `retireTarifHistorialPreciosPage()` | PR 27 | `ArticuloNotasHistorialOwnershipTest` + `plugins/catalogo_core/tests/InitUpgradeTest.php` | catalogo_core suite + `php -l` on controller/`Init.php` | revert the controller/view move + the `Init` additions — tables/data untouched |
| **WU-6.D** Test repoints | 3 view-path repoints + `TarifHistorialPreciosControllerTest` retarget + `InitUpgradeTest` cases | PR 28 | `TarifHistorialPreciosControllerTest` + `ArticuloListaCanonicaOwnershipTest` + `Controller/VentasArticuloArticleEditAbsorptionTest` + `CatalogoOpcionalesUnifiedControllerTest` | catalogo_core suite | revert the test edits — WU-5 assertions restored |
| **WU-6.E** Gate | dual-suite + root `Plugins` + grep/no-half-moved/frozen markers/RBAC boundary | PR 29 | all WU-6 tests | catalogo_core + tarifario suites + root `Plugins` | n/a (verification only) |

---

## Phase 33: WU-6.A — RED ownership test + atomic 2-model/XML move + require repoints (AD-W6-1; ANH-01, ANH-02)

### RED (author FIRST; run before the move)

- [ ] 33.1 Write `plugins/catalogo_core/tests/ArticuloNotasHistorialOwnershipTest.php` (namespace `Tests\CatalogoCore`, DB-free source+class contracts, mirror `ArticuloModelosExtOwnershipTest.php`: `source()` reader + `productionFiles()` recursive scan excluding `tests/`, `vendor/`, `openspec/`). Author the ANH methods:
  - `test_notes_model_and_xml_live_only_in_catalogo_core` — `plugins/catalogo_core/model/tarif_revision_nota.php` + `model/table/tarif_revision_notas.xml` exist; tarifario twins absent; `namespace FSFramework\model;` + `class tarif_revision_nota` + `parent::__construct('tarif_revision_notas')` byte-stable; `class_exists(FQCN, false)` + `is_subclass_of(\fs_model::class)` (ANH-01 scenario 1, AD-W6-1).
  - `test_history_model_and_xml_live_only_in_catalogo_core` — same contract for `plugins/catalogo_core/model/tarif_precio_historial.php` + `model/table/tarif_precio_historial.xml` (`tarif_precio_historial` byte-stable) (ANH-02 scenario 1, AD-W6-1).
  - `test_notes_seam_resolves_locally_without_load_tarifario_model` — `Controller/VentasCatalogo.php` declares `protected function revision_nota_model(): \FSFramework\model\tarif_revision_nota`; `init_revision_notas()` calls it; `loadTarifarioModel('FSFramework\\model\\tarif_revision_nota')` and the `=== null` degradation are gone; `loadTarifarioModel()` survives only for `tarif_grupo_articulo`/`tarif_grupo_rol`/`tarif_grupo_tarifa` (ANH-01 scenario 2, AD-W6-2).
  - `test_price_save_writes_history_without_the_soft_guard` — `model/tarif_articulo_precio.php::save()` contains no `class_exists('FSFramework\\model\\tarif_precio_historial')` and calls `tarif_precio_historial::registrar_cambio(` directly (ANH-03 scenario 1, AD-W6-3).
  - `test_notes_actions_use_the_local_model` — the notes entry points (`get_notas_*`, `crear_nota`, `resolver_nota`, `cancelar_nota`, `count_notas_pendientes`) operate on the injected `revision_nota_model()` seam with correct counters/lists under a stub DB (ANH-01 scenario 2).
  - `test_history_reads_and_writes_use_the_local_model` — date-filtered list/counters/statistics/purge + `registrar_cambio()` resolve the local `tarif_precio_historial`; an unchanged value is skipped (ANH-02 scenario 2).
  - `test_history_view_keeps_htmx_alpine_hygiene` — `View/tarif_historial_precios.html.twig` has no `|raw`/`bootbox`, registers nonce'd `Alpine.data('historialPrecios')` behind `alpine:init`, colon events only, POST-only purge (ANH-05 scenario 1, AD-W6-6).
  - `test_notes_images_ui_hygiene_is_preserved` — `View/partials/catalogo/panel_notas.html.twig` (+ the notes modals) and `View/js/catalogo/revision-notas.js` stay free of `|raw`/`bootbox` with POST mutations (ANH-05 scenario 2).
  - `test_catalogo_core_has_zero_tarifario_paths_for_notes_history` — grep gate: zero `plugins/tarifario/` + `@tarifario/` hits for the notes/history surface across catalogo_core production (ANH-06 scenario 1).
  - `test_rbac_seam_stays_soft_and_in_tarifario` — `loadTarifarioModel()` retained for `tarif_grupo_*` only; `plugins/tarifario/model/tarif_grupo_articulo.php` + `tarif_grupo_rol.php` + `tarif_grupo_tarifa.php` and `plugins/tarifario/Services/ArticlePermissionListener.php` exist in tarifario; the listener is live on `ArticlePermissionFilterEvent::NAME` (ANH-06 scenario 2, AD-W6-9).
  - `test_catalogo_core_bootstraps_the_notes_table_standalone` — `Init::ensureNotasHistorialTables()` is `public static function …: void`, FK-safe order (`ensureFamiliasTarifaTables()` → `fs_users` touch → `tarif_revision_nota` → `tarif_precio_historial`), `is_file` + `class_exists($fqcn, false)` + `is_subclass_of`, wired in `init()` after `ensureCatalogoTables()` and in `upgrade()`; frozen WU-1..WU-5 bodies untouched (ANH-01 scenario 2, AD-W6-5).
- [ ] 33.2 Confirm RED for 33.1: the two model methods + the grep method fail on the missing catalogo_core paths and the live `plugins/tarifario/` requires; the seam/guard/bootstrap/hygiene methods stay RED until their phases. Never a syntax/setup error. Record RED evidence in `apply-progress.md`.

### GREEN (atomic move + require repoints)

- [ ] 33.3 Byte-identical move (AD-W6-1) into `plugins/catalogo_core/model/` (+ `model/table/`): `tarif_revision_nota.php` (`tarif_revision_notas`), `tarif_precio_historial.php` (`tarif_precio_historial`), `model/table/tarif_revision_notas.xml`, `model/table/tarif_precio_historial.xml`. Then `git rm` the 4 tarifario paths (cwd `plugins/tarifario`). Keep FQCNs (`FSFramework\model\*`) and every `parent::__construct('<table>')` byte-stable; no class file/XML exists in both plugins.
- [ ] 33.4 Repoint the two hard requires in `plugins/tarifario/extras/tarifario_init.php:24,:25` → `plugins/catalogo_core/model/tarif_revision_nota.php` + `plugins/catalogo_core/model/tarif_precio_historial.php` (AD-W6-1); keep the `class_exists` boot blocks `:150-152` (`tarif_precio_historial`) and `:174-177` (`tarif_revision_nota`) as idempotent no-ops (WU-4/WU-5 precedent), no edit.
- [ ] 33.5 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter ArticuloNotasHistorialOwnershipTest plugins/catalogo_core/tests/ArticuloNotasHistorialOwnershipTest.php` → the 2 model methods + the grep method green; `ddev exec php -l plugins/catalogo_core/model/tarif_revision_nota.php plugins/catalogo_core/model/tarif_precio_historial.php plugins/tarifario/extras/tarifario_init.php`. **Rollback boundary**: revert the 4 moved files + the 2 require repoints — `tarif_revision_notas`/`tarif_precio_historial` tables and data untouched.

---

## Phase 34: WU-6.B — notes seam hardening + history guard hardening + pinned-test inversion (AD-W6-2, AD-W6-3; ANH-01, ANH-03)

### RED

- [ ] 34.1 Confirm RED for the B-owned methods (authored in 33.1): `--filter 'test_notes_seam_resolves_locally_without_load_tarifario_model|test_price_save_writes_history_without_the_soft_guard|test_notes_actions_use_the_local_model|test_history_reads_and_writes_use_the_local_model'` fails because the seam still calls `loadTarifarioModel('…tarif_revision_nota')` (`Controller/VentasCatalogo.php:4470`) and `save()` still carries the WU-1 `class_exists` guard (`model/tarif_articulo_precio.php`). Never a fixture/setup error. Record.
- [ ] 34.2 Invert the pinned contract in `plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php:152-177`: rename `test_history_write_is_class_exists_soft` → `test_history_write_uses_the_local_reference`; assert the `class_exists('FSFramework\\model\\tarif_precio_historial')` guard string is **absent** and `tarif_precio_historial::registrar_cambio(` is **present**, still emitted only when `abs($precio_anterior - $this->precio) >= 0.0001` (AD-W6-3). Confirm RED against the current WU-1 body. Record.

### GREEN

- [ ] 34.3 Harden the notes seam (AD-W6-2) in `plugins/catalogo_core/Controller/VentasCatalogo.php`: add `protected function revision_nota_model(): \FSFramework\model\tarif_revision_nota { return new tarif_revision_nota(); }`; change `init_revision_notas()` (`:4468-4475`) to `$this->revision_nota_model = $this->revision_nota_model();`; remove the `loadTarifarioModel('FSFramework\\model\\tarif_revision_nota')` call and the `=== null` branch; delete the dead `process_action()` guard at `:578`; keep `use FSFramework\model\tarif_revision_nota;` (`:62`) and `loadTarifarioModel()` only for `tarif_grupo_articulo`/`tarif_grupo_rol`/`tarif_grupo_tarifa` (`:3465-3466`, `:4024-4026`).
- [ ] 34.4 Harden the history write (AD-W6-3) in `plugins/catalogo_core/model/tarif_articulo_precio.php::save()`: replace the `if (class_exists('FSFramework\\model\\tarif_precio_historial')) { … }` wrapper with the direct local `tarif_precio_historial::registrar_cambio($this->referencia, $this->codtarifa, $precio_anterior, $this->precio, $usuario);` inside the existing `$result && abs(…) >= 0.0001` branch; keep every SQL/`url()`/`install()` string byte-stable.
- [ ] 34.5 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/ArticuloNotasHistorialOwnershipTest.php plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php` → green (the inverted pin included); `ddev exec php -l plugins/catalogo_core/Controller/VentasCatalogo.php plugins/catalogo_core/model/tarif_articulo_precio.php`. **Rollback boundary**: revert the seam + guard edits and restore the pinned test — the WU-1 guard contract returns.

---

## Phase 35: WU-6.C — history controller/view move + reclass + `|raw` hygiene + bootstrap + retirement (AD-W6-4, AD-W6-5, AD-W6-6; ANH-04, ANH-05)

### RED

- [ ] 35.1 Confirm RED for the C-owned methods (authored in 33.1): `--filter 'test_catalogo_core_bootstraps_the_notes_table_standalone|test_history_view_keeps_htmx_alpine_hygiene'` fails because `plugins/catalogo_core/controller/tarif_historial_precios.php`, `plugins/catalogo_core/View/tarif_historial_precios.html.twig` and `Init::ensureNotasHistorialTables()`/`retireTarifHistorialPreciosPage()` do not exist yet and the tarifario view still renders `get_change_icon(...)|raw` (`:224`). Never a syntax/setup error. Record.

### GREEN

- [ ] 35.2 Byte-copy `plugins/tarifario/controller/tarif_historial_precios.php` → `plugins/catalogo_core/controller/tarif_historial_precios.php` and `plugins/tarifario/View/tarif_historial_precios.html.twig` → `plugins/catalogo_core/View/tarif_historial_precios.html.twig`; then `git rm` the 2 tarifario originals (AD-W6-4). Keep the class basename and `page=tarif_historial_precios` byte-stable; the two `use FSFramework\model\tarif_precio_historial` / `tarif_opcional_precio_historial` FQCNs and the `tipo=articulos`/`tipo=opcionales` branch bodies stay byte-stable.
- [ ] 35.3 Reclass in place (AD-W6-4): `extends tarif_controller` → `extends fbase_controller`; `use TarifarioOpcionalStateTrait;` + `$this->init_tarifario_opcional_state();` after `parent::private_core()` in `private_core()` (`:118`); ctor menu folder `'tarifario'` → `'catalogo'` (`:113`); replace the requires `:20-22` with `plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php` + `plugins/catalogo_core/model/tarif_precio_historial.php` + `plugins/catalogo_core/model/tarif_opcional_precio_historial.php`. Preserve `ini_filters()`, `load_data()`, `get_articulo()`, `get_opcional()`, `calcular_porcentaje_cambio()`, `get_badge_class()`, `format_fecha()`, `paginas()`, `export_excel()`, `purge_historial()` and the WU-2/WU-3 link repoints.
- [ ] 35.4 View hygiene (AD-W6-6): in the moved controller rename `get_change_icon($a, $b)` (`:283`) → `get_change_icon_class($a, $b): string` returning the CSS class (`'fa fa-arrow-up text-danger'` / `'fa fa-arrow-down text-success'` / `'fa-minus text-muted'`); in the moved view replace `{{ fsc.get_change_icon(...)|raw }}` at `:224` with `<i class="{{ fsc.get_change_icon_class(item.precio_anterior, item.precio_nuevo) }}"></i>`; replace the jQuery datepicker/inline `<script>` with one nonce'd classic `<script {{ csp_nonce_attr() }}>` registering `Alpine.data('historialPrecios')` behind `alpine:init` (datepicker init + purge confirm); colon events only; purge stays POST + `{{ csrf_field() }}`; keep the bootstrap modal `data-toggle`. Notes partials + `revision-notas.js` stay byte-stable.
- [ ] 35.5 Bootstrap (AD-W6-5): add `public static function ensureNotasHistorialTables(): void` to `plugins/catalogo_core/Init.php` mirroring `ensureCatalogoTables()` — `require_once FS_FOLDER . '/base/fs_model.php'`; `self::ensureFamiliasTarifaTables()` → `if (!class_exists('fs_user', false)) { require_once FS_FOLDER . '/model/fs_user.php'; } new \fs_user();` → foreach `['tarif_revision_nota', 'tarif_precio_historial']` require the catalogo_core file behind `is_file` and instantiate behind `class_exists($fqcn, false)` + `is_subclass_of($fqcn, \fs_model::class)`. Wire `self::ensureNotasHistorialTables();` in `init()` after the `ensureCatalogoTables()` block (`:94-98`) and in `upgrade()` after `self::ensureCatalogoTables();` (`:124`), each in its own `try/catch` + `error_log`. **Frozen bodies untouched.**
- [ ] 35.6 Retirement (AD-W6-4): add idempotent `private static function retireTarifHistorialPreciosPage(): void` (`fs_page::get('tarif_historial_precios')` → gated `->delete()`, mirror `retireTarifCatalogoViewPage()`); wire it in `upgrade()` after `self::retireTarifCatalogoViewPage();` (`:161-165`) inside its own `try/catch` + `error_log`; catalogo_core re-registers the row under the `catalogo` folder on the next discovery.
- [ ] 35.7 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter ArticuloNotasHistorialOwnershipTest plugins/catalogo_core/tests/ArticuloNotasHistorialOwnershipTest.php` → green; `ddev exec php -l plugins/catalogo_core/controller/tarif_historial_precios.php plugins/catalogo_core/Init.php`. **Rollback boundary**: revert the controller/view move + the `Init` additions and restoration of the two tarifario files — tables/data untouched.

---

## Phase 36: WU-6.D — test repoints + `InitUpgradeTest` additions (AD-W6-7; ANH-04, ANH-07)

### RED

- [ ] 36.1 Repoint `plugins/catalogo_core/tests/TarifHistorialPreciosControllerTest.php`: `CONTROLLER_RELATIVE` (`:34`) → `'plugins/catalogo_core/controller/tarif_historial_precios.php'`; keep `HISTORY_RELATIVE`; add `test_controller_serves_articulos_mode_from_local_model` (article branch reads the local `tarif_precio_historial`), `test_controller_extends_fbase_controller_with_shared_state_trait` (`class tarif_historial_precios extends fbase_controller`, `use TarifarioOpcionalStateTrait;`), `test_retired_tarifario_history_surface_leaves_no_alias` (neither tarifario controller/view exists) (ANH-04).
- [ ] 36.2 Repoint the three hardcoded view paths (AD-W6-7): `plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php:48` `REPOINTED_LINKS` key → `'plugins/catalogo_core/View/tarif_historial_precios.html.twig'`; `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php:951` view-scan → the catalogo_core path; `plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php:906` repoint-loop entry → the catalogo_core path.
- [ ] 36.3 Extend `plugins/catalogo_core/tests/InitUpgradeTest.php` (mirror `upgradeRetiresTarifCatalogoViewPageIdempotently`): add `upgradeRetiresTarifHistorialPreciosPageIdempotently()` (asserts the `retireTarifHistorialPreciosPage(): void` signature, its `upgrade()` wiring, `get('tarif_historial_precios')` and the gated `->delete()` regex) + `test_ensure_notas_historial_tables_is_wired_and_fk_safe()` (asserts the `ensureNotasHistorialTables(): void` signature, the FK-safe order `ensureFamiliasTarifaTables()` → `fs_user` → the 2 models, `is_file`/`class_exists(..., false)`/`is_subclass_of`, and the `init()`/`upgrade()` wiring after `ensureCatalogoTables()`) (ANH-04, AD-W6-5).
- [ ] 36.4 Confirm RED: the three repointed view-path tests fail because the tarifario view was `git rm`'d in 35.2 and they still `assertFileExists()` the retired path; `TarifHistorialPreciosControllerTest` fails on the retired `CONTROLLER_RELATIVE` until 36.1 lands; `InitUpgradeTest` lacks both new methods. Never a syntax/setup error. Record.

### GREEN

- [ ] 36.5 Run `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/TarifHistorialPreciosControllerTest.php plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php plugins/catalogo_core/tests/InitUpgradeTest.php` → green; no test references `plugins/tarifario/controller/tarif_historial_precios.php` or `plugins/tarifario/View/tarif_historial_precios.html.twig` (ANH-07 scenario 2). **Rollback boundary**: revert the 4 test edits — WU-5 assertions restored.

---

## Phase 37: WU-6.E — dual-suite gate + grep audit + RBAC boundary (AD-W6-9; ANH-06, ANH-07)

- [ ] 37.1 catalogo_core suite: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **≥ 641 tests / 2741 assertions OK**, zero failures.
- [ ] 37.2 tarifario suite: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → green at/above the **162 tests / 555 assertions** floor (Skipped 3), zero failures.
- [ ] 37.3 Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins` → zero new failures vs the known pre-existing 10 (7× OidcProvider, 2× `TarifTarifaOpcionalPrecedenceTest`, 1× `LegacySupportTest`).
- [ ] 37.4 Grep audit (ANH-06 scenario 1): `ArticuloNotasHistorialOwnershipTest::test_catalogo_core_has_zero_tarifario_paths_for_notes_history` green — zero `plugins/tarifario/` + `@tarifario/` hits in the catalogo_core production tree (tests/vendor/openspec excluded) for the notes/history surface; `loadTarifarioModel()` survives only for `tarif_grupo_articulo`/`tarif_grupo_rol`/`tarif_grupo_tarifa`.
- [ ] 37.5 No half-moved state + RBAC boundary (ANH-06 scenario 2, AD-W6-9): `test_notes_model_and_xml_live_only_in_catalogo_core` + `test_history_model_and_xml_live_only_in_catalogo_core` + `test_retired_tarifario_history_surface_leaves_no_alias` + `test_rbac_seam_stays_soft_and_in_tarifario` green — each moved class/XML/controller/view exists in exactly one plugin; `tarif_grupo_*` + `ArticlePermissionListener` stay in tarifario and the listener stays live on `ArticlePermissionFilterEvent::NAME`.
- [ ] 37.6 Frozen markers + locked contracts (ANH-07 scenario 1): `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`, `plugins/catalogo_core/tests/VentasArticulosControllerTest.php`, `plugins/catalogo_core/tests/VentasArticuloControllerTest.php` and tarifario `Integration/HookRegistrationTest.php` green **unedited**; `git status --porcelain -- plugins/catalogo_core/View/ventas_articulo.html.twig` empty (host view untouched).
- [ ] 37.7 No core `openspec/` entry: `git status --porcelain -- openspec/` empty; the change lives only under `plugins/catalogo_core/openspec/changes/absorber-articulos-tarifa-en-catalogo-core/`.

> **Verify annotation**: Phase 37 gates are re-executed by the independent `sdd-verify` actor; the report cites catalogo_core ≥ 641/2741, tarifario ≥ 162/555, root `Plugins` zero new failures, the grep/no-half-moved/frozen-marker/RBAC results, and the dev-DB smoke of the standalone `ensureNotasHistorialTables()` + the reclassed page on `tipo=articulos`/`tipo=opcionales`.

---

## Phase 38: WU-6 delivery — atomic two-repo landing (AD-W6-8)

> **NOT EXECUTED — delivery is human-owned.** Phases 33–37 were executed by apply
> (working tree only); the 38.x steps below are the documented landing order for the
> human and are intentionally left `[ ]`. **No `git commit`/`push` by apply.**
>
> **Landing order (AD-W6-8, add-before-remove)**: the **catalogo_core commit FIRST**,
> the **tarifario commit IMMEDIATELY AFTER with no deploy between them**; the runtime is
> complete only after both. Clear the Twig cache after both. Revert in reverse order
> (tarifario, then catalogo_core) and clear the cache again.

- [ ] 38.1 Stage the catalogo_core side (cwd `plugins/catalogo_core`, **explicit paths only** — never `git add -A`): the 2 moved models + 2 XMLs (`model/tarif_revision_nota.php`, `model/tarif_precio_historial.php`, `model/table/tarif_revision_notas.xml`, `model/table/tarif_precio_historial.xml`); moved/reclassed `controller/tarif_historial_precios.php` + `View/tarif_historial_precios.html.twig`; edited `model/tarif_articulo_precio.php`, `Controller/VentasCatalogo.php`, `Init.php`; new `tests/ArticuloNotasHistorialOwnershipTest.php`; updated `tests/TarifHistorialPreciosControllerTest.php`, `tests/ArticuloTarifaPrecioOwnershipTest.php`, `tests/InitUpgradeTest.php`, `tests/ArticuloListaCanonicaOwnershipTest.php`, `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`, `tests/CatalogoOpcionalesUnifiedControllerTest.php`. Conventional commit, no AI attribution. _<!-- verify: deferred - human-owned landing; NOT part of the WU-6 verification scope -->_
- [ ] 38.2 Stage the tarifario side (cwd `plugins/tarifario`, explicit paths only): the `git rm`s of the 4 moved model/XML files + `controller/tarif_historial_precios.php` + `View/tarif_historial_precios.html.twig`; edited `extras/tarifario_init.php` (the `:24,:25` require repoints; the `class_exists` boot blocks stay no-ops). Matching conventional commit. _<!-- verify: deferred - human-owned landing; NOT part of the WU-6 verification scope -->_
- [ ] 38.3 38.1 lands **first**, 38.2 **immediately after with no intermediate deploy** (AD-W6-8, add-before-remove). Clear the Twig cache after both: `CacheManager::clearAll()` (or delete `tmp/twig_cache`). **Rollback (AD-W6-8)**: revert 38.2 then 38.1 and clear the Twig cache again; the tarifario-owned models, controller and view are restored byte-for-byte and the retired `tarif_historial_precios` `fs_page` row is recreated by the restored controller on the first request. No table/data rename or drop; no Composer change (no `vendor/` commit). Plugin `fsframework.ini` version bumps are deferred to the `fsframework-plugin-release` skill. _<!-- verify: deferred - human-owned landing; NOT part of the WU-6 verification scope -->_

> **WU-6/WU-8 boundary (accepted)**: the standalone notes permissions stay fail-closed
> (RBAC role resolution remains in tarifario), so non-admins cannot create/resolve/cancel
> notes with tarifario inactive; whether catalogo_core defines a neutral note-permission
> event is the WU-8 RBAC-audit item. `tarifario_init.php`'s `class_exists` boot blocks stay
> as idempotent no-ops through WU-8 (design Open Questions).

---

## WU-6 Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | High: the verbatim moves are ~1.2k added in the catalogo_core commit (~769 `tarif_revision_nota.php` + ~412 `tarif_precio_historial.php` + ~60 XMLs) + ~765 (456-line controller + 309-line view) deleted in the tarifario commit; authored delta (excluding byte-identical moves) ≈0.8k — new ownership test ≈450, `TarifHistorialPreciosControllerTest` additions ≈120, `InitUpgradeTest` additions ≈70, `Init` bootstrap/retirement ≈+45, notes seam ≈+15/−15, history guard removal ≈±5, view hygiene (`get_change_icon_class()` + Alpine) ≈+40/−30, pinned-test inversion ≈25, 3 view-path repoints ≈±10. |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | WU-6.A (RED ownership test + atomic model/XML move + require repoints) → WU-6.B (notes seam + history guard hardening + pinned-test inversion) → WU-6.C (history controller/view move + reclass + hygiene + bootstrap + retirement) → WU-6.D (test repoints + `InitUpgradeTest` cases) → WU-6.E (dual-suite gate). Atomicity note: each unit's catalogo_core and tarifario commits coordinate one deliverable and MUST land back-to-back (AD-W6-8). |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

> Delivery is pre-decided: `auto-chain` + `stacked-to-main`. The orchestrator proceeds
> without an apply-guard question; the units above are the review/rollback boundaries.
> Atomicity note: the two plugin-repo commits coordinate one deliverable and MUST land
> back-to-back regardless of the slice mapping (38.3). Delivery is human-owned (no commit
> by apply); the accepted WU-8 notes-permission boundary is recorded in 38.

---

# Revert WU-4 + WU-5 (boundary correction)

> **Trigger**: ownership correction — the article extension fields
> (`tarif_articulo`, `tarif_articulos_ext` → Ref SAP / Ref Catálogo /
> Configurador) and the catalog manager are **tarifario-exclusive** and must
> NOT live in catalogo_core. WU-4 and WU-5 are reverted; WU-1/WU-2/WU-3 (and the
> sibling `absorber-opcionales-tarifa-en-catalogo-core` change) are untouched.
>
> **Working tree only** — no commit/push.
>
> **Runners / baselines after the revert**
> - `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **602 tests / 2343 assertions OK** (exact WU-3 end state; Warnings 26, Skipped 1).
> - `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → **180 tests / 637 assertions OK** (pre-WU-4/WU-5 baseline; Skipped 3).
> - Root `Plugins` → 1503 tests, **10 failures = exactly the known pre-existing set** (7× OidcProvider, 2× `TarifTarifaOpcionalPrecedenceTest`, 1× `LegacySupportTest`); **zero new**.

## R-A. WU-5 revert — catalog manager returns to tarifario

### Restored (tarifario, from HEAD)
- `controller/tarif_catalogo_view.php` (reconstructed: HEAD + the WU-1/WU-2/opcionales keeps — see R-D note 1), the 4 `View/tarif_catalogo*.html.twig`, 12 `View/partials/catalogo/*`, 9 `View/js/catalogo/*`, 5 `model/tarif_catalogo*.php` + 5 `model/table/tarif_catalogo*.xml`, `tests/Controller/TarifCatalogoHtmxContractTest.php`.
- `tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` recreated from the catalogo_core copy, retargeted to `plugins/tarifario/controller/tarif_catalogo_view.php` and the `tarif_catalogo_view` FQCN (the tarifario copy was untracked).

### Removed (catalogo_core)
- `Controller/VentasCatalogo.php`, `controller/ventas_catalogo.php`, `extras/VentasCatalogoStateTrait.php`.
- 5 `model/tarif_catalogo*.php` + 5 `model/table/tarif_catalogo*.xml`.
- `View/ventas_catalogo.html.twig` + the 3 renamed fragments, `View/partials/catalogo/*` (12), `View/js/catalogo/*` (9).
- `tests/CatalogoManagerOwnershipTest.php`, `tests/Controller/TarifCatalogoHtmxContractTest.php`, `tests/Controller/TarifCatalogoOpcionalMasterExportTest.php`, `tests/Support/CatalogoCoreSeedStubs.php`.

### Reverted (catalogo_core / tarifario)
- `Init.php`: removed `ensureCatalogoTables()` + `retireTarifCatalogoViewPage()` and their `init()`/`upgrade()` wiring.
- `View/Macro/TarifarioComponents.html.twig:271` → `page=tarif_catalogo_view&codtarifa=…`.
- `tests/InitUpgradeTest.php`: removed the 2 WU-5 cases; restored the file-scope seed stubs and the 3 keep retirement cases (`tarif_opcionales`, `tarif_opcional_precios`, `tarif_articulos`).
- tarifario audit tests back to the tarifario catalog paths: `FamiliaOverrideRemovalTest.php`, `TarifFamiliaWriteRetirementTest.php` (writer #3), `LegacyImportRegressionTest.php`.
- `tarif_catalogo_view` slug + `fs_page` semantics restored (no retirement, no alias).

## R-B. WU-4 revert — article extension models return to tarifario

### Restored (tarifario)
- `model/tarif_articulo.php`, `model/tarif_articulos_ext.php`, `model/table/tarif_articulos.xml`, `model/tarif_descripcion.php`.
  - `tarif_articulo.php` was copied back from the catalogo_core move (not HEAD) to preserve the WU-2 `url()` repoint and the opcionales `catalogo_articulo_opcional` repoint (R-D note 2).

### Removed (catalogo_core)
- `model/tarif_articulo.php`, `model/tarif_articulos_ext.php`, `model/table/tarif_articulos.xml`, `tests/ArticuloModelosExtOwnershipTest.php`.

### Reverted (catalogo_core / tarifario)
- `Init.php`: removed `ensureArticuloExtTables()` and its `init()`/`upgrade()` wiring.
- tarifario requires back to the tarifario model paths: `extras/tarifario_init.php` (restored the `tarif_descripcion` boot block), `controller/tarif_actualizar_precios.php`, `controller/tarif_catalogo_view.php`, `Services/ArticuloListActionHandler.php`, `tests/Model/TarifArticuloFactoryForImportTest.php`, `tests/Services/ExcelImportWizardServiceTest.php`.
- pinned tests: `tests/ArticuloListaCanonicaOwnershipTest.php` inverted back to `test_tarif_articulo_and_ext_stay_in_tarifario_for_wu4`; `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php:935` path back to `plugins/tarifario/model/tarif_articulo.php`.
- No `InitUpgradeTest` cases were added by WU-4, so none were reverted.

## R-C. Audits / evidence

| Check | Result |
|---|---|
| catalogo_core suite | **OK (602 tests, 2343 assertions)** — Warnings 26, Skipped 1 (WU-3 end state) |
| tarifario suite | **OK (180 tests, 637 assertions)** — Skipped 3 (pre-WU-4/WU-5 state) |
| Root `Plugins` | 1503 tests, 10 failures = exactly the known set (7× OidcProvider, 2× `TarifTarifaOpcionalPrecedenceTest`, 1× `LegacySupportTest`); zero new |
| No half-moved state | `tarif_articulo` (+ext, +XML), the 5 `tarif_catalogo*` models/XMLs, the controller and the 4 views + 21 assets exist in **tarifario only**; catalog/VentasCatalogo exist nowhere |
| catalogo_core production tarifario coupling | `grep` count **0** for `plugins/tarifario/` + `@tarifario/` (tests/vendor/openspec excluded) |
| `page=tarif_catalogo_view` | resolves again in tarifario (controller + macro + tests); `process_excel_wizard.php:477` link now resolves |
| WU-1/WU-2/WU-3 surfaces | intact and green: `ArticuloTarifaPrecioOwnershipTest`, `ArticuloDetalleCanonicoOwnershipTest`, `ArticuloListaCanonicaOwnershipTest`, `TarifTabPreciosTest`, `CatalogoArticuloHookOwnershipTest`, `CatalogoCoreHookMarkersTest`, `VentasArticulo(s)ControllerTest` |
| Twig cache | cleared (64 → 0 entries) |

## R-D. Reconciliation notes

1. **`controller/tarif_catalogo_view.php` was reconstructed, not blind-restored.** The WU-1/WU-2 model require repoints and the opcionales `build_opcionales_export()`/`opcional_master_state()` work landed in this file before WU-5; a literal `git restore` from HEAD would have dropped them and broken WU-1/WU-2 plus the opcionales suite. The file is the catalogo_core WU-5 copy with the WU-5 reclass/soft-seam edits reversed; `tarif_articulos_ext` is back on the tarifario path (WU-4 revert).
2. **`model/tarif_articulo.php` was copied back from the catalogo_core move**, not restored from HEAD, for the same reason (WU-2 `url()` repoint to `page=ventas_articulo` + opcionales repoint).
3. **`tests/InitUpgradeTest.php`** keeps the 3 non-WU-5 retirement cases and the file-scope seed stubs; the WU-5.E `tests/Support/CatalogoCoreSeedStubs.php` extraction is reverted (its motivating collision disappeared with the relocated test).
4. **No reconciliation was impossible**: after the revert, catalogo_core production has no reference to `tarif_articulo`/`tarif_articulos_ext`; the tarifario-exclusive `ref_sap`/`ref_catalogo`/`configurador` surfaces live only in the tarifario catalog controller. No soft seam was needed and no tarifario-exclusive field was dragged back.

---

# Revert configurador (boundary correction)

> **Rationale (user boundary correction)**: the hierarchical opcionales
> configurator (`tarif_configurador_opcionales`) is **tarifario-exclusive** and
> must NOT live in catalogo_core. This section reverts the configurator half of
> the `opcionales-por-tarifa` absorption (catalogo_core `683d57c` /
> tarifario `e469f06`) without touching the opcionales list/detail/precios
> (`tarif_opcionales` → `ventas_opcionales`, `tarif_opcional_edit`,
> `tarif_opcional_precios`), the opcional tab, the master models, or the
> WU-1/2/3 article work.
>
> **Delivery**: working tree only — no `git commit` / `git push`.

## RC-1. Relocation (tarifario)

- [x] RC-1.1 Byte-identical copies (sha256-equal to the catalogo_core source) into tarifario:
  - [x] `plugins/tarifario/controller/tarif_configurador_opcionales.php` (50447 B)
  - [x] `plugins/tarifario/View/tarif_configurador_opcionales.html.twig` (25498 B)
  - [x] `plugins/tarifario/View/tarif_configurador_tree.html.twig` (15978 B)
  - [x] `plugins/tarifario/View/partials/configurador/styles.html.twig` (9767 B)
- [x] RC-1.2 Page slug frozen: class basename `tarif_configurador_opcionales`,
  `parent::__construct(__CLASS__, 'Configurador Opcionales', 'tarifario')`,
  `$this->template = 'tarif_configurador_tree'`. The controller keeps extending
  catalogo_core's `fbase_controller` and keeps requiring the catalogo_core
  models/trait (allowed direction tarifario → catalogo_core; `TarifarioOpcionalStateTrait`
  already requires `fbase_controller`). No `tarif_controller` reclass was needed —
  the forked base loads and preserves behavior.

## RC-2. Removal (catalogo_core)

- [x] RC-2.1 `git rm` the 4 configurator assets + the configurator test:
  `controller/tarif_configurador_opcionales.php`,
  `View/tarif_configurador_opcionales.html.twig`,
  `View/tarif_configurador_tree.html.twig`,
  `View/partials/configurador/styles.html.twig`,
  `tests/TarifConfiguradorOpcionalesTest.php`.

## RC-3. Test relocation + repoints

- [x] RC-3.1 Moved `TarifConfiguradorOpcionalesTest` to
  `plugins/tarifario/tests/TarifConfiguradorOpcionalesTest.php`
  (namespace `Tests\CatalogoCore` → `Tests\Tarifario`; `CONTROLLER_RELATIVE` and
  the view path repointed to tarifario; the catalogo_core model constants —
  resolver, `catalogo_articulo_opcional`, `catalogo_opcional_familia` — stay
  catalogo_core). **17 tests / 89 assertions.**
- [x] RC-3.2 `plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php`:
  dropped `tarif_configurador_opcionales` from `CONTROLLER_SLUGS` (only
  `tarif_opcional_edit` remains); docblock updated.
- [x] RC-3.3 `plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php:111`:
  the hardcoded-consumer audit repointed to
  `plugins/tarifario/controller/tarif_configurador_opcionales.php`.
- [x] RC-3.4 No `Init`/menu registration referenced the configurator (page
  discovery scans active plugins' `controller/`), so no `Init` change was needed.

## RC-4. Audits / evidence

| Check | Result |
|---|---|
| catalogo_core suite | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **OK (585 tests, 2247 assertions)** — Warnings 26, Skipped 1 |
| tarifario suite | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → **OK (197 tests, 726 assertions)** — Skipped 3 |
| moved test (focused) | `… -c plugins/tarifario/phpunit.xml plugins/tarifario/tests/TarifConfiguradorOpcionalesTest.php` → **OK (17 tests, 89 assertions)** |
| Root `Plugins` | 1503 tests, **10 failures = exactly the known pre-existing set** (7× OidcProvider, 2× `TarifTarifaOpcionalPrecedenceTest`, 1× `LegacySupportTest`); zero new |
| Half-moved audit | the 4 assets + the test exist in **tarifario only**; none remain in catalogo_core |
| Reference audit | 0 hits for `catalogo_core/controller/tarif_configurador*`, `catalogo_core/View/tarif_configurador*`, `catalogo_core/View/partials/configurador*` |
| Slug resolution | `page=tarif_configurador_opcionales` resolves from `plugins/tarifario/controller/` (constructor folder `tarifario`); runtime class load proven by the moved test's anonymous subclass |
| Lint | `php -l` clean on the moved controller/test + the 2 repointed tests |
| Twig cache | cleared (`rm -rf tmp/twig_cache/*` → 0 entries) |

## RC-5. Caveats

1. **Byte-identical relocation kept the catalogo_core license header** in the
   moved controller/views (`This file is part of catalogo_core`). The relocation
   was required byte-identical; a follow-up attribution touch (→ tarifario) is
   optional and was intentionally not folded into this revert.
2. The moved test's D4 asserts are preserved: the configurator still carries zero
   `plugins/tarifario/` require/`use`/instantiation for its per-tarifa reads
   (forked to raw parameterized SQL); the allowed tarifario → catalogo_core
   requires are unchanged.
