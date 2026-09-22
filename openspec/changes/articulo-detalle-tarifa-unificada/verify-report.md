```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:cb3af21619aee4c3621650a045cbca1a4fc1320a8fd90be94502f890253d77f0
verdict: fail
blockers: 1
critical_findings: 0
requirements: 9/9
scenarios: 26/26
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:6edde033b918652d8194fd4f247d138da1a75a24f0cfd82e1ae25a9ff97a58ee
build_command: ddev exec composer phpstan
build_exit_code: 1
build_output_hash: sha256:b1dc13b928eb6816c2a8618bb87e14a4a7a9aa3e00777459e31c9301a29c9220
```

## Verification Report

**Change**: `articulo-detalle-tarifa-unificada`
**Version**: N/A (delta spec, no version field; catalogo_core `fsframework.ini` untouched by this change)
**Mode**: Standard (plugin `config.yaml` declares `strict_tdd: true`, but the RED→GREEN record is audited from `apply-progress.md` and the runtime evidence; no `strict-tdd-verify.md` runner is wired into this phase)
**SDD root**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `config.yaml`). Core `openspec/` holds **no** entry for this change (`ls openspec/changes/ | grep -c articulo-detalle-tarifa-unificada` → `0`). The canonical specs under `plugins/catalogo_core/openspec/specs/` were **not** edited.
**Evidence artifacts** (gitignored scratch): `tmp/verify_art/cc_suite.txt`, `tmp/verify_art/root_suite.txt`, `tmp/verify_art/phpstan.txt`; `evidence_revision` is the SHA-256 of the sorted `basename:sha256` manifest of those three files.

### Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 25 |
| Tasks complete | 16 |
| Tasks incomplete | 9 |

Derived from `tasks.md` with `grep -cE '^- \[[ x~]\]'` → **25** total, `'^- \[x\]'` → **16**, `'^- \[ \]'` → **9**. Every implementation task in Units 1–5 (1.1–1.3, 2.1–2.3, 3.1–3.2, 4.1–4.6, 5.1–5.2) is `[x]`. The 9 unchecked rows are **Task 0** (0.1–0.5, the sibling-archive prerequisite) and **Unit 6** (6.1–6.4, the regression gate). Three of the four Unit-6 rows are verified in this report; **6.3 (manual smoke) is MANUAL / NOT EXECUTED** (no live HTTP harness). There are **no unchecked implementation tasks**.

### Build & Tests Execution

**Plugin suite (owner, the config-declared runner)**: ✅ `exit 0`
```text
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
OK, but there were issues!
Tests: 814, Assertions: 3567, Warnings: 26, Skipped: 1.
```
`test_output_hash: sha256:6edde033b918652d8194fd4f247d138da1a75a24f0cfd82e1ae25a9ff97a58ee` (`tmp/verify_art/cc_suite.txt`). Meets the required `≥ 814 tests / 3567 assertions`: plugin suite **814 / 3567, exit 0**. The 26 warnings and 1 skip are the pre-existing plugin-wide set (identical counts at every earlier gate).

**Root suite (cross-plugin regression)**: ✅ `exit 0`
```text
$ ddev exec php vendor/bin/phpunit
OK, but some tests were skipped!
Tests: 2390, Assertions: 8011, Skipped: 24.
```
`sha256:bba1f109215051a1ce99177487e0f4ba9ddf81d2bfce71d06e5e61133583f48f` (`tmp/verify_art/root_suite.txt`). **Zero failures**, exactly the documented baseline (`2390 / 8011`), so **no NEW failures**. The 24 skips are the pre-existing conditional skips.

**Build (type gate)**: ❌ `exit 1` — **pre-existing, out of this change's scope**
```text
$ ddev exec composer phpstan
   208/208 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 ------ -----------------------------------------------------------------
  Line   tests/Core/PluginEnableAjaxSafetyTest.php
  308    Method Tests\Core\AjaxGuardTestPluginManager::applyPluginSchemaUpdates()
         should return array{success: bool, changes: list<string>, errors: list<string>}
         but returns array{success: true, errors: array{}}.
         🪪  return.type
 ------ -----------------------------------------------------------------
 [ERROR] Found 1 error
```
`build_output_hash: sha256:b1dc13b928eb6816c2a8618bb87e14a4a7a9aa3e00777459e31c9301a29c9220` (`tmp/verify_art/phpstan.txt`).
**Scope limitation (recorded, not assumed)**: `phpstan.neon` declares `paths: [src, tests]`. It therefore does **not** analyse `plugins/`, so this command is the **repository-root gate only and is NOT plugin type-safety evidence**. The single error is in a **committed core test file** (`tests/Core/PluginEnableAjaxSafetyTest.php`) that this plugin-local change does not touch: root `git status --porcelain` is **empty**, so the offending bytes are exactly the core `HEAD` bytes and the error exists at `HEAD` independently of this change. This is not a regression of `articulo-detalle-tarifa-unificada`; it is a pre-existing repo-gate failure. The plugin's real type/syntax gate is the plugin suite plus `php -l` on the touched files.

**Syntax gate** (`ddev exec php -l`, all clean): ✅ 8/8 touched PHP files — `Controller/VentasArticulo.php`, `Init.php`, `controller/tarif_tab_precios.php`, `tests/Controller/VentasArticuloPerTarifaPaneTest.php`, `tests/Controller/VentasArticuloTarifaSelectionTest.php`, `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`, `tests/Integration/CatalogoArticuloHookOwnershipTest.php`, `tests/TarifTabPreciosTest.php` → `No syntax errors detected`.

**Focused re-run (locked + flipped suites)**: ✅ `OK (42 tests, 186 assertions)`, `exit 0` — `--filter "CatalogoCoreHookMarkersTest\|CatalogoArticuloHookOwnershipTest\|VentasArticuloControllerTest"` (12 + 12 + 18).

**Cross-plugin scenario (tarifario)**: ✅ `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter HookRegistrationTest` → `OK (4 tests, 9 assertions)`. The root `Plugins` testsuite (`plugins/*/tests`) also includes it and the root suite exited 0.

**Coverage**: ➖ Not available — no coverage driver is installed (`php -m` reports neither xdebug nor pcov). Unchanged from prior verifications.

### Locked-Contract Audit (byte-identity + frozen surfaces)

| Check | Result | Evidence |
|---|---|---|
| `tests/VentasArticuloControllerTest.php` byte-identical to plugin `HEAD` | ✅ | `git diff HEAD -- <file>` empty; working-tree `sha256:e838b7a3…5067b` == `git show HEAD:<file>` `sha256:e838b7a3…5067b` |
| `tests/Integration/CatalogoCoreHookMarkersTest.php` byte-identical to plugin `HEAD` | ✅ | `git diff HEAD -- <file>` empty; working `sha256:93f37e24…1205` == `git show HEAD:<file>` `sha256:93f37e24…1205` |
| Plugin repo `HEAD` | ✅ | `1399f58d368e09714b26a9491e5af77d7a9e1e78` |
| Frozen marker names + positions intact | ✅ | `View/ventas_articulo.html.twig:90` `render_hook('ventas_articulo_tabs_after', {…'caracteristicas': fsc.caracteristicas_context()})` immediately before the `#tab_articulo` `</ul>` (L91); L384 `render_hook('ventas_articulo_tab_pane_after', …)` immediately before the `.tab-content` balancing `</div>` (L385). Proven green unedited by `CatalogoCoreHookMarkersTest` (12/12 in this pass). |
| Five locked literals present in the view | ✅ | `#multiidioma` (3), `#opcionales` (1), `tab_multiidioma.html.twig` (1), `tab_opcionales.html.twig` (1), `fsc.articulo.pvp` (1) — asserted by the unedited `VentasArticuloControllerTest` L206/L244–247 (18/18 green) |
| ART-02 intact — no `guardar_precio_tab` in `Controller/VentasArticulo.php` | ✅ | `grep -c guardar_precio_tab Controller/VentasArticulo.php` → `0`; the only writer is `page=tarif_tab_precios` |
| ART-04 hygiene on touched artifacts | ✅ | `bootbox` 0 · `\|raw` 0 · `onclick=` 0 · `onchange=` 0 · `oninput=` 0 · `onsubmit=` 0 · `hx-on:` 0 · `htmx:afterSwap` 0 · `htmx:afterRequest` 0 · `htmx:beforeSwap` 0 · `htmx:configRequest` 0 · `htmx:load` 0. Only htmx 4 colon events present (`htmx:after:swap`, `htmx:after:request`). Inline scripts are `csp_nonce_attr()`-guarded and `allowScriptTags:false` is set. |

### Spec Compliance Matrix

Statuses per `references/report-format.md`: ✅ COMPLIANT · ⚠️ PARTIAL · ❌ UNTESTED/FAILING. Counts below are the native `### Requirement:` / `#### Scenario:` totals from the three deltas: `articulo-detalle-canonico` 5/15, `articulo-tarifa-tab-management` 2/7, `catalogo-render-hooks` 2/4 → **9 requirements, 26 scenarios**.

| # | Requirement (delta file) | Scen. | Result | Covering evidence (executed this pass) |
|---|---|---|---|---|
| 1 | ART-05 Canonical detail preserves tabs and frozen hook markers (`articulo-detalle-canonico`) | 3/3 | ✅ | `VentasArticuloArticleEditAbsorptionTest::test_view_preserves_tabs_and_frozen_markers`; unedited `VentasArticuloControllerTest` (locked literals); unedited `CatalogoCoreHookMarkersTest` (frozen positions) |
| 2 | ART-01 Canonical detail absorbs article edit behavior (`articulo-detalle-canonico`) | 5/5 | ✅ | `test_reference_change_uses_the_article_reference_setter_and_rejects_invalid`, `test_familia_and_flags_are_assigned_on_save`, `test_per_tarifa_sync_uses_tarif_tarifa_articulo_for_the_active_tarifa`, `test_etiquetas_persist_through_tarif_tarifa_articulo_etiqueta`, `test_etiqueta_failure_does_not_discard_the_article_save`, `test_images_entry_point_is_owned_by_the_canonical_detail`, `test_image_mutations_stay_scoped_to_the_loaded_article`; plus `VentasArticuloPerTarifaPaneTest::test_with_a_tarifa_selected_the_base_price_is_not_written` and `test_with_no_active_tarifas_the_base_price_is_written` |
| 3 | ART-09 Tarifa selector with validated default (`articulo-detalle-canonico`) | 2/2 | ✅ | `VentasArticuloTarifaSelectionTest::test_default_tarifa_is_preselected_on_first_load`; `test_stale_codtarifa_falls_back_to_default`, `test_unknown_codtarifa_without_default_falls_back_to_first_active`, `test_inactive_default_is_skipped_for_the_first_active`, `test_empty_codtarifa_falls_back_to_default`; render `test_view_scopes_the_selector_to_the_resolved_active_tarifa` |
| 4 | ART-10 Per-tarifa read path for price, state and visibility (`articulo-detalle-canonico`) | 3/3 | ✅ | `VentasArticuloPerTarifaPaneTest::test_scoped_rows_render_only_the_requested_tarifa` (USD/EUR datasets), `test_scoped_rows_read_precio_and_activo_from_the_price_model`, `test_scoped_rows_check_activo_when_the_model_row_is_active`, `test_scoped_rows_resolve_visibility_without_persisting` (0 writes); `test_price_editing_reuses_the_wu1_tab_endpoint` |
| 5 | ART-11 No-active-tarifas degradation branch (`articulo-detalle-canonico`) | 2/2 | ✅ | `VentasArticuloTarifaSelectionTest::test_view_degrades_to_the_editable_base_price_with_no_active_tarifas` (no selector, editable `spvp` inside `#precios-tarifa`, page complete); `test_zero_active_tarifas_yields_empty_code_and_null_selection` |
| 6 | ATT-03 Hook ownership transfers idempotently to catalogo_core (`articulo-tarifa-tab-management`) | 3/3 | ✅ | `CatalogoArticuloHookOwnershipTest::test_catalogo_core_registers_zero_article_hooks_across_rebuilds`, `test_retired_article_hook_templates_and_init_mappings_are_gone`; `plugins/tarifario/tests/Integration/HookRegistrationTest::test_tarifario_registers_zero_article_hooks` (4/4 green) |
| 7 | ATT-04 Canonical detail renders the tab and persists per-tarifa state (`articulo-tarifa-tab-management`) | 4/4 | ✅ | `test_host_renders_the_unified_pane_without_an_injected_tarifas_surface`, `test_unsaved_article_renders_no_price_surface`, `test_host_pane_embeds_the_scoped_wu1_rows_placeholder`; `TarifTabPreciosTest::test_allow_saves_price_via_articulo_precio_get_and_save`, `test_visibility_controls_write_articulo_scope_feature_values`, `test_blank_price_deletes_the_row_and_keeps_the_feature_values`, `test_rows_fragment_renders_row_tariff_coddivisa`, `test_delete_branch_response_rerenders_rows_with_real_context`; unedited `CatalogoCoreHookMarkersTest` |
| 8 | `catalogo-render-hooks` MODIFIED "Article hook pair registration owned by catalogo_core" | 4/4 | ✅ | `CatalogoArticuloHookOwnershipTest` (zero registration across 2 builds + opcional pair stays); unedited `CatalogoCoreHookMarkersTest` (markers render empty, opcional pair registered); tarifario `HookRegistrationTest` (zero tarifario article hooks) |
| 9 | `catalogo-render-hooks` REMOVED "Article hook tab renders derived feature values" | 0/0 | ✅ (no-op by design) | The removal targets a requirement ADDED by the still-active sibling delta. Both retired templates are deleted and both markers render empty (`CatalogoArticuloHookOwnershipTest`, `CatalogoCoreHookMarkersTest`). The **merge** is archive-gated — see the ARCHIVE-BLOCKING item below. |

**Compliance summary**: **26/26 scenarios complete**; **9/9 requirements complete**.

### Behavioural Audit — implementation vs. delta contract

| Contract point | Status | Code evidence |
|---|---|---|
| ART-09 validated fallback (`unknown/inactive → default → first active → ''`) | ✅ | `Controller/VentasArticulo.php:249-296` `resolveCodtarifa()` / `suppliedCodtarifa()` / `findActiveTarifa()`; default only accepted when active (L256-259) |
| ART-09 selector rendered only with active tarifas, default preselected, ratified htmx pattern | ✅ | `View/ventas_articulo.html.twig:41` `{% if fsc.tarifas|length > 0 %}`, L50-61 `hx-get="{{ fsc.url() }}"` + `change/body/body/outerHTML/push-url/boost`, L59 `selected` from `fsc.tarifa_seleccionada.codtarifa` |
| ART-09 `$tarifa_seleccionada` exposed, null when none | ✅ | `Controller/VentasArticulo.php:94` (property), `:224` (call), `:304-309` (`null` iff `codtarifa === ''`) |
| ART-10 per-tarifa read via `tarif_articulo_precio::get` + `CaracteristicaResolver::resolve_bool`, no persist | ✅ | `controller/tarif_tab_precios.php:150-156`; test asserts `getTarifas === ['USD1']`, exact `resolve_bool` call tuples, and `storeAssignments === [] / saveCount 0 / deleteCount 0` |
| ART-10 price editing stays on `page=tarif_tab_precios`, host has no duplicate save | ✅ | `View/Hooks/partials/articulo_precios_rows.html.twig` posts to `page=tarif_tab_precios`; `grep -c guardar_precio_tab Controller/VentasArticulo.php` → 0 |
| ART-10 additive `codtarifa` read scope; legacy scope-less output unchanged | ✅ | `controller/tarif_tab_precios.php:67` `$rows_scope`, `:122-124` rows action, `:197` save response, `:142-148` `array_filter` narrowing; `TarifTabPreciosTest::test_rows_fragment_without_scope_keeps_every_active_tarifa` |
| ART-11 no-tarifa branch: no selector, editable base `pvp` | ✅ | `View/ventas_articulo.html.twig:269-299` `{% else %}` panel with `name="spvp"` (L282) and `fsc.articulo.pvp` (L282) |
| ART-01 conditional `pvp` write | ✅ | `Controller/VentasArticulo.php:466-468` `if ($this->codtarifa === '') { $art->pvp = (float) … }` |
| ART-01 reference/familia/flags/images/etiquetas absorbed (WU-2) | ✅ | `Controller/VentasArticulo.php:453-459` (`set_referencia`), `:470-492`, `:494-513`, `:529-550`, `:573-598`, `:635-673`, `:715-822` |
| ATT-03 / D4 retirement | ✅ | `Init.php` has zero `ARTICULO_HOOK_TEMPLATES`/article-hook references (grep 0); `OPCIONAL_HOOK_TEMPLATES` kept (L28-30, L217); both `View/Hooks/ventas_articulo_*.html.twig` deleted |
| ATT-04 surface rendered by the host unified pane | ✅ | `View/ventas_articulo.html.twig:245-268` `#precios-tarifa` box with `id="tab_tarifario_precios"`, `data-tipo="articulo"`, `x-data="articuloTabPrecios" x-cloak`, scoped `hx-get` (L257) |
| ART-05 unified `#datos`; `#precios`/`#stock` not tabs; `#opcionales` only secondary tab | ✅ | one `id="datos"` pane; section anchors L103-107; `role="presentation"` count == 2; no `href="#precios"`/`href="#stock"` |
| AD-6 save-wiring include restored (correction) | ✅ | `View/ventas_articulo.html.twig:268` `{% include '@catalogo_core/Hooks/partials/tab_save_script.html.twig' %}` inside the tarifa branch, after the rows placeholder, before `{% else %}` |
| AD-8 boot placement + single guarded re-init | ✅ | `htmx.boot({'allowScriptTags': false})` at L10 (top); `alpine.boot()` at L537; one `__ventasArticuloSwapBound`-guarded `htmx:after:swap` (L524-533); the duplicate block is removed from `tab_save_script.html.twig` |
| AD-7 multi-idioma visible in-pane panel | ✅ | `View/partials/articulos/tab_multiidioma.html.twig:5` `<div class="panel panel-default" id="multiidioma">` (no `role="tabpanel"`/`tab-pane`) |

### Coherence (Design)

| Decision | Followed? | Notes |
|---|---|---|
| AD-1 full-body htmx swap selector (A1) | ✅ | Clone of the ratified `tarif_opcional_edit` attribute set, gated on active tarifas |
| AD-2 WU-1 endpoint remains the only price/state writer (B2) | ✅ | No `guardar_precio_tab` in the host controller; rows partial still posts to the endpoint |
| AD-3 scoped read (option a) + `TarifTabPreciosTest` migration | ✅ | `$rows_scope` additive narrowing; the two migrated tests assert the scoped single-row fragment and a scope-less guard keeps the legacy output |
| AD-4 validated selection + new public API | ✅ | `resolveCodtarifa()`/`resolveTarifaSeleccionada()`/`?tarif_tarifa $tarifa_seleccionada` at the pinned names |
| AD-5 conditional `pvp` | ✅ | Exact `if ($this->codtarifa === '')` guard |
| AD-6 hook retirement with inert markers | ✅ | Const + loop removed, templates deleted, markers untouched, kept partials preserved; the host include (correction) closes the AD-6 wiring gap |
| AD-7 unified pane + locked-literal survival | ✅ | All five literals alive; `#multiidioma` carried by the in-pane section |
| AD-8 boot to top + guarded `Alpine.initTree` once | ✅ | Verified above |
| AD-9 strict-TDD plan (RED-first files, migrations) | ✅ | Both new files present; `TarifTabPreciosTest` migrated; absorption migrated; ownership flipped; the two locked files untouched. TDD RED→GREEN record audited in `apply-progress.md` (RED evidence per unit matches the current test names/counts) |
| AD-10 sibling merge ordering | ⚠️ | **NOT satisfied yet** — see ARCHIVE-BLOCKING item. Code/test work is order-independent, but the delta merges must wait for the sibling archive |
| Security (CSRF GET selector, `requireCsrf()` on save, neutral permission event, CSP hygiene, no `pvp` clobber with a tarifa) | ✅ | Selector is a read-only GET; the price save keeps `requireCsrf()` + `puede_editar_articulo()`; article save keeps `puedeEditarArticulo()`; ART-04 hygiene scan clean |

### Manual Smoke Checklist (Unit 6.3) — MANUAL / NOT EXECUTED

No live HTTP harness exists in the repo (the plugin suite is DB-free seam/render tests), so this is a command-level checklist, **not executed** in this phase.

Preconditions: `ddev start`; an active user with article-edit permission; at least one saved article `REF`; the `tarifario` plugin active for feature definitions.

A. With active tarifas
1. `ddev exec php -r '…'`-free path: open `http://<ddev-host>/index.php?page=ventas_articulo&ref=<REF>`.
2. Assert a `<select name="codtarifa">` is rendered and its default option (`★`) is `selected`; the "Tarifa activa" label shows the default tarifa.
3. Assert a single `#datos` tab is active, `#opcionales` is the only other tab, and there is no `#precios`/`#stock` tab.
4. Change the selector: assert htmx swaps `<body>` and the URL gains `&codtarifa=<code>`; assert the `#precios-tarifa` box shows exactly one row whose `data-codtarifa` equals the selection.
5. In `#precios-tarifa`, set a price + `activo` + visibility, save: assert exactly one `tarif_articulo_precio` row for `(REF, <code>)` is written/updated and the re-rendered row reflects it; blank the price and save: assert the row is deleted and the feature values survive.
6. Assert the browser console shows no `bootbox`, no htmx v2 events, and no CSP violations.

B. With zero active tarifas
7. Deactivate every tarifa (or use an install with none), reload `index.php?page=ventas_articulo&ref=<REF>`.
8. Assert no `name="codtarifa"` selector is emitted; the `#precios-tarifa` section shows the base `pvp` input, editable (not `readonly`/`disabled`), carrying the stored price.
9. Submit the form with a new `spvp`: assert `articulos.pvp` is updated.

C. Cross-check after each mutation
10. `ddev exec php -r` query or phpMyAdmin: confirm `articulos.pvp` is unchanged while a tarifa is selected (step 5) and changed with zero tarifas (step 9).

### Issues Found

**CRITICAL**: None.

**WARNING**
1. **ARCHIVE-BLOCKING — Task 0 is unmet.** The sibling change `caracteristicas-producto` is still active (`plugins/catalogo_core/openspec/changes/caracteristicas-producto/verify-report.md` → `verdict: fail`, `blockers: 1`), and the canonical `articulo-detalle-canonico` spec does **not** yet contain the sibling post-merge wording (`grep -c "articulo-scope feature values"` → `0`). Task 0.1/0.2/0.3 are all unmet (0.4 holds: no core `openspec/` entry). **This blocks the DELTA MERGE / ARCHIVE, not the implementation**: this change's ART-01/ART-05 and `catalogo-render-hooks` REMOVED blocks must be merged **after** the sibling archives, otherwise the ART-01 block re-applies the sibling wording and the REMOVED block targets a requirement that exists only in the sibling's active delta. Task 0.5 (`remove-fs-demo` shares `Controller/VentasArticulo.php`) is a recorded rebase risk with no blocking gate.
2. **`composer phpstan` exits 1 on a pre-existing, out-of-scope error.** `tests/Core/PluginEnableAjaxSafetyTest.php:308` (`return.type`) fails at the current core `HEAD`; the root tree is clean, so it is not caused by this plugin-local change, and `phpstan.neon` does not analyse plugins at all. Recorded rather than hidden because the gate did not return `exit 0` as the design's Testing Strategy expected. It is the repo gate, **not** plugin type-safety; the plugin's real gate (suite + `php -l`) is green.

**SUGGESTION**
1. **Spec pointer drift (ART-01).** The delta annotates the scenario "With no active tarifas the base price is written" with `tests/Controller/VentasArticuloTarifaSelectionTest.php`, but the covering test lives in `tests/Controller/VentasArticuloPerTarifaPaneTest.php::test_with_no_active_tarifas_the_base_price_is_written`. The behaviour and assertions are green; only the `Test:` file annotation is wrong. Already self-reported in `apply-progress.md` Batch 3.
2. **ART-11 literal wording not asserted verbatim.** The scenario "Empty rows surface degrades gracefully" says the surface "reports no active tarifas"; the covering test asserts the base-price degradation, `id="tab_articulo"` page completeness and a successful render (no fatal error) but not an explicit "no active tarifas" string. Behaviourally compliant; consider asserting the literal or rewording the scenario.
3. **`apply-progress.md` evidence hygiene.** (a) Batch 4 claims `php -l plugins/catalogo_core/View/ventas_articulo.html.twig` → "No syntax errors detected"; the claim is true but **vacuous** (a `.twig` file has no `<?php` tag, so PHP treats it as inline HTML and always reports no syntax errors) — real syntax evidence is the 8 `.php` files. (b) Batch 4's per-unit rollback sentence ("The hook injection still exists until Unit 5, so reverting restores the five-tab layout") is chain-scoped and stale post-Unit-5; the top-level `design.md` rollback remains accurate.

### Phase-Contract Conformance of `apply-progress.md`

| Batch | Claimed | Tree reflects it? |
|---|---|---|
| 1 (Unit 1) | `Controller/VentasArticulo.php` resolver + `VentasArticuloTarifaSelectionTest.php` | ✅ present, names/counts match |
| 2 (Unit 2) | `controller/tarif_tab_precios.php` `$rows_scope` + `TarifTabPreciosTest` migration + `VentasArticuloPerTarifaPaneTest.php` | ✅ present |
| 3 (Unit 3) | `editarArticulo()` pvp guard + 3 test cases | ✅ present |
| 4 (Unit 4) | view rewrite + 2 partials + 3 migrated/extended tests | ✅ present |
| 5 (Unit 5) | `Init.php` + 2 template deletions + flipped ownership test | ✅ present |
| Correction | host include + 1 RED case + harness stub | ✅ present |

No hallucinated path was found: every artifact named in `apply-progress.md` exists in the tree, and the final numbers (`814/3567` plugin, `2390/8011` root) reproduce exactly. The rollback boundaries are accurate as chain-scoped slices (SUGGESTION 3b notes the one stale sentence). No requirement drifted during apply: the implemented behaviour matches the deltas, and the only spec/implementation mismatch is the ART-01 `Test:` pointer (SUGGESTION 1).

### Unchecked-Task Inventory

`tasks.md` still lists **9** unchecked rows: Task 0 `[ ]` 0.1–0.5 and Unit 6 `[ ]` 6.1–6.4. Unit 6 is the regression-gate unit this phase executes: **6.1, 6.2 and 6.4 are verified here** (plugin suite 814/3567 exit 0; phpstan recorded with its scope limitation; both locked files byte-identical to `HEAD`); **6.3 is MANUAL / NOT EXECUTED** (no live HTTP harness). `tasks.md` was **not** edited. Task 0 is the archive prerequisite, not an implementation task. Therefore **no unchecked implementation task remains**.

### Envelope Semantics (read this before routing)

The envelope `verdict: fail` is **not** an implementation failure. `gentle-ai sdd-verify-validate` admits a passing verdict only when `blockers: 0` **and** both command exit codes are `0`. This change carries a genuine **ARCHIVE blocker** (`blockers: 1`, Task 0) and the prescribed repository gate `composer phpstan` exits `1` on a **pre-existing, out-of-scope** core-test error — so a passing envelope is inadmissible by construction. Both facts are recorded honestly instead of green-washed:

- **Implementation verification: PASS.** Plugin suite `814/3567` `exit 0`, root suite `2390/8011` `exit 0`, all 9 requirements / 26 scenarios covered by passing tests, both locked contract files byte-identical to plugin `HEAD`, ART-02/ART-04 hygiene clean.
- **`blockers: 1` = ARCHIVE-only.** Task 0 (sibling `caracteristicas-producto` archive) is unmet; nothing about the code blocks candidate review.
- **`build_exit_code: 1` = pre-existing repo-gate failure**, in a core test file this plugin-local change does not touch (`phpstan.neon` does not analyse plugins).

### Verdict

**Envelope: FAIL (not archive-ready) — Implementation: PASS.**
Implementation evidence is complete and green (plugin suite `814/3567` `exit 0`; root suite `2390/8011` `exit 0`; all 9 requirements / 26 scenarios covered; both locked contract files byte-identical to `HEAD`). The envelope cannot be `pass` because Task 0 (sibling `caracteristicas-producto` archive) is an unmet ARCHIVE blocker and the prescribed repo gate `composer phpstan` exits `1` on a pre-existing out-of-scope core error. **Archive gate: HOLD** until Task 0 closes; the implementation itself is ready.
