# Apply Progress: articulo-detalle-tarifa-unificada

Plugin-local change (`ownership: plugin-local`, `strict_tdd: true`). Artifacts live
under `plugins/catalogo_core/openspec/`; the canonical specs and the core
`openspec/` are untouched. This file is append-only: later units merge into it.

## Batch 1 — Unit 1 (PR 1 of 5): validated tarifa selection (AD-4)

**Status**: COMPLETE (verified).
**Scope assigned**: Unit 1 only (tasks 1.1–1.3). View, WU-1 endpoint, `Init.php`
and every other test file were NOT touched.

### RED evidence (before GREEN)

Command: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloTarifaSelectionTest`
Result: `Tests: 7, Assertions: 7, Errors: 2, Failures: 5` (exit 2).

Failures for the stated reasons only:

| Test | Observed failure | Reason |
|------|------------------|--------|
| `test_default_tarifa_is_preselected_on_first_load` | `Error: Call to undefined method ...::resolveTarifaSeleccionada()` | `resolveTarifaSeleccionada()` / `$tarifa_seleccionada` do not exist yet |
| `test_valid_active_codtarifa_is_kept` | `Error: Call to undefined method ...::resolveTarifaSeleccionada()` | same |
| `test_stale_codtarifa_falls_back_to_default` | expected `T2`, got `STALE` | unchecked passthrough |
| `test_unknown_codtarifa_without_default_falls_back_to_first_active` | expected `T1`, got `UNKNOWN` | unchecked passthrough |
| `test_inactive_default_is_skipped_for_the_first_active` | expected `T1`, got `UNKNOWN` | unchecked passthrough |
| `test_empty_codtarifa_falls_back_to_default` | expected `T2`, got `''` | unchecked passthrough |
| `test_zero_active_tarifas_yields_empty_code_and_null_selection` | expected `''`, got `T1` | unchecked passthrough |

### GREEN evidence (after implementation)

| Command | Result |
|---------|--------|
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloTarifaSelectionTest` | `OK (7 tests, 13 assertions)`, exit 0 |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | `Tests: 801, Assertions: 3463, Warnings: 26, Skipped: 1` — `OK`, exit 0 |
| `ddev exec php -l plugins/catalogo_core/Controller/VentasArticulo.php` | `No syntax errors detected` |

Baseline was `794 tests / 3450 assertions`; the delta is exactly `+7 tests / +13
assertions` from the new Unit-1 test file. Warnings (26) and skipped (1) are
unchanged.

### Files touched

| File | Action | Lines | What |
|------|--------|-------|------|
| `plugins/catalogo_core/Controller/VentasArticulo.php` | Modified | L86–94 (property), L224 (call), L239–310 (resolver block) | `public ?tarif_tarifa $tarifa_seleccionada`; validated `resolveCodtarifa()`; `suppliedCodtarifa()`; `findActiveTarifa()`; `resolveTarifaSeleccionada()`; call from `loadTarifarioState()` |
| `plugins/catalogo_core/tests/Controller/VentasArticuloTarifaSelectionTest.php` | Created | 281 lines | RED-first selection matrix (ART-09 / ART-11) |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1 | `tests/Controller/VentasArticuloTarifaSelectionTest.php` | Unit | ✅ 794/3450 | ✅ Written (7 cases) | ✅ Passed 7/7 | ✅ 7 cases | ✅ `suppliedCodtarifa()`/`findActiveTarifa()` extracted |
| 1.2 | `tests/Controller/VentasArticuloTarifaSelectionTest.php` | Unit | ✅ 794/3450 | ✅ Written | ✅ Passed 7/7 | ✅ 7 cases | ✅ Clean |
| 1.3 | — | Gate | ✅ 794/3450 | ✅ Confirmed RED first | ✅ Passed | ➖ N/A | ➖ N/A |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloTarifaSelectionTest` → `OK (7 tests, 13 assertions)`, exit 0 |
| Runtime harness command/scenario and exact result | N/A — DB-free anonymous-subclass unit tests; the plugin suite has no live HTTP harness. Integration/contract coverage for this change arrives in Units 4–5. |
| Rollback boundary | Revert the resolver block (L86–94, L224, L239–310) in `Controller/VentasArticulo.php` and delete `tests/Controller/VentasArticuloTarifaSelectionTest.php`. Nothing else is touched. |

### Notes / deviations

- None — implementation matches design AD-4 and the `tarif_opcional_edit::resolver_tarifa_seleccionada()` semantics.
- `resolveCodtarifa()` now validates the supplied code instead of passing it through; with a stale/unknown code the pane falls back to a real active tarifa (fail-closed). This is the intended AD-4 behavior change.

## Batch 2 — Unit 2 (PR 2 of 5): scoped per-tarifa rows read (AD-3)

**Status**: COMPLETE.
**Scope assigned**: Unit 2 only (tasks 2.1–2.3). The view,
`Controller/VentasArticulo.php`, `Init.php`, `tests/VentasArticuloControllerTest.php`
and every other test file were NOT touched.

### RED evidence (before GREEN)

Command: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloPerTarifaPaneTest`
Result: `Tests: 5, Assertions: 11, Failures: 5` (exit 1). All five cases fail
because the additive scope is ignored today, so every active tariff row (and
both the `get()` and `resolve_bool()` per-tarifa calls) leak into the fragment:

| Test | Observed failure | Reason |
|------|------------------|--------|
| `test_scoped_rows_render_only_the_requested_tarifa` (dataset "USD scope hides EUR") | fragment still contains `data-codtarifa="EUR1"` | the request `codtarifa` is ignored; all rows render |
| `test_scoped_rows_render_only_the_requested_tarifa` (dataset "EUR scope hides USD") | fragment still contains `data-codtarifa="USD1"` | same |
| `test_scoped_rows_read_precio_and_activo_from_the_price_model` | `getTarifas` is `['EUR1','USD1']`, expected `['USD1']` | both tariffs read from the price model |
| `test_scoped_rows_check_activo_when_the_model_row_is_active` | `getTarifas` is `['EUR1','USD1']`, expected `['USD1']` | same |
| `test_scoped_rows_resolve_visibility_without_persisting` | fragment still contains `data-codtarifa="EUR1"` / EUR1 resolved | both tariffs resolved and rendered |

Command: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifTabPreciosTest`
Result: `Tests: 14, Assertions: 80, Failures: 2` (exit 1). Exactly the two
migrated tests fail; the CSRF, permission-gate, currency-hygiene (S28),
structure and single-tariff save-response tests stay green:

- `test_rows_fragment_renders_row_tariff_coddivisa` — the unscoped render still
  emits `data-codtarifa="EUR1"`.
- `test_delete_branch_response_rerenders_rows_with_real_context` — the save
  response still re-renders the sibling `EUR1` row (`9.99`) and
  `data-codtarifa="EUR1"`.

### GREEN evidence (after implementation)

| Command | Result |
|---------|--------|
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'TarifTabPreciosTest\|VentasArticuloPerTarifaPaneTest'` | `OK (19 tests, 115 assertions)`, exit 0 |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | `Tests: 807, Assertions: 3503, Warnings: 26, Skipped: 1` — `OK`, exit 0 |
| `ddev exec php -l plugins/catalogo_core/controller/tarif_tab_precios.php` | `No syntax errors detected` |

Baseline was `801 tests / 3463 assertions`; the delta is exactly `+6 tests /
+40 assertions` (5 scoped-read cases + 1 legacy scope-less guard). Warnings (26)
and skipped (1) are unchanged.

### Files touched

| File | Action | Lines | What |
|------|--------|-------|------|
| `plugins/catalogo_core/controller/tarif_tab_precios.php` | Modified | property + `render_rows_action()` + `render_rows_fragment()` + `guardar_precio_articulo()` | `protected string $rows_scope`; set from `$_REQUEST['codtarifa']` and the saved row's tariff; additive narrowing with the scope-less path unchanged |
| `plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php` | Created | 486 lines | RED-first scoped-read matrix (ART-10 / ATT-04) |
| `plugins/catalogo_core/tests/TarifTabPreciosTest.php` | Modified | migrated 2 tests + 1 legacy guard | scoped single-row fragment assertions; scope-less output guarded |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 2.1 | `tests/Controller/VentasArticuloPerTarifaPaneTest.php` | Unit | ✅ 13/75 (`TarifTabPreciosTest`) | ✅ Written (5 cases, 5 failed) | ✅ Passed 5/5 | ✅ 5 cases, USD+EUR datasets | ✅ `twoTarifaFixture()`/`checkboxInput()` extracted |
| 2.2 | `controller/tarif_tab_precios.php` | Unit | ✅ 13/75 | ✅ Confirmed scope ignored | ✅ Passed 19/115 | ✅ scoped + scope-less paths | ✅ `$tarifas` narrowing kept additive |
| 2.3 | `tests/TarifTabPreciosTest.php` | Unit | ✅ 13/75 | ✅ Migrated (2 failed) | ✅ Passed 14/14 | ✅ scoped row + legacy guard | ✅ Clean |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'TarifTabPreciosTest\|VentasArticuloPerTarifaPaneTest'` → `OK (19 tests, 115 assertions)`, exit 0 |
| Runtime harness command/scenario and exact result | N/A — DB-free anonymous-subclass seam tests rendering the REAL rows partial; the plugin suite has no live HTTP harness. Integration/contract coverage for the unified host pane arrives in Units 4–5. |
| Rollback boundary | Revert the `$rows_scope` property + the two setter sites + the narrowing in `render_rows_fragment()` in `controller/tarif_tab_precios.php`, revert the two migrated assertions in `tests/TarifTabPreciosTest.php` (keep `test_rows_fragment_without_scope_keeps_every_active_tarifa`), and delete `tests/Controller/VentasArticuloPerTarifaPaneTest.php`. The scope-less legacy output is untouched, so no consumer is affected. |

### Notes / deviations

- None — implementation matches design AD-3 and keeps `page=tarif_tab_precios`
  the only writer (AD-2/ART-02 intact; no `guardar_precio_tab` in
  `Controller/VentasArticulo.php`).
- The additive scope is applied with `array_values(array_filter(...))`; a scope
  that matches nothing falls through to the partial's existing "No hay tarifas
  activas." row, as the design's Interfaces section states.
- `test_save_response_rerenders_rows_with_real_referencia_and_saved_value`
  (single tariff) stays green unedited and exercises the save path setting
  `rows_scope` from the saved row.
- The legacy scope-less contract is guarded by the new
  `test_rows_fragment_without_scope_keeps_every_active_tarifa`; a byte-for-byte
  golden was not added (the fragment depends on live currency symbols), so the
  guard asserts the full structural invariants instead.

## Batch 3 — Unit 3 (PR 3 of 5): conditional `articulos.pvp` write (AD-5, C1)

**Status**: COMPLETE.
**Scope assigned**: Unit 3 only (tasks 3.1–3.2). The view,
`controller/tarif_tab_precios.php`, `Init.php`, `tests/VentasArticuloControllerTest.php`
and every other test file were NOT touched. `VentasArticuloControllerTest.php` is
locked (ART-08) and unedited.

### RED evidence (before GREEN)

Command: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloPerTarifaPaneTest`
Result: `Tests: 8, Assertions: 34, Failures: 2` (exit 1). Exactly the two
"tarifa selected" datasets fail; the zero-tarifa regression guard and the five
Unit-2 scoped-read cases stay green:

| Test | Observed failure | Reason |
|------|------------------|--------|
| `test_with_a_tarifa_selected_the_base_price_is_not_written` (data set "non-zero spvp" `99.99`) | `Failed asserting that 99.99 is identical to 42.0` | `$art->pvp = (float) $request->request->get('spvp', 0)` runs unconditionally, so the stored base price is overwritten |
| `test_with_a_tarifa_selected_the_base_price_is_not_written` (data set "zero spvp" `0`) | `Failed asserting that 0.0 is identical to 42.0` | same unconditional write |

`test_with_no_active_tarifas_the_base_price_is_written` passes both before and
after the change: with `codtarifa === ''` the base-price branch is behaviorally
identical to today, which is exactly the AD-5 invariant.

### GREEN evidence (after implementation)

| Command | Result |
|---------|--------|
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloPerTarifaPaneTest` | `OK (8 tests, 40 assertions)`, exit 0 |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloTarifaSelectionTest` | `OK (7 tests, 13 assertions)`, exit 0 |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | `Tests: 810, Assertions: 3515, Warnings: 26, Skipped: 1` — `OK`, exit 0 |
| `ddev exec php -l plugins/catalogo_core/Controller/VentasArticulo.php` | `No syntax errors detected` |

Baseline was `807 tests / 3503 assertions`; the delta is exactly `+3 tests /
+12 assertions` (2 selected-tarifa datasets + 1 zero-tarifa branch). Warnings (26)
and skipped (1) are unchanged.

### Files touched

| File | Action | Lines | What |
|------|--------|-------|------|
| `plugins/catalogo_core/Controller/VentasArticulo.php` | Modified | `editarArticulo()`: the `pvp` assignment only | guarded the base-price write with `if ($this->codtarifa === '')`; the save body, CSRF order, permission gate, `syncTarifaArticulo()`, multi-idioma and etiquetas are byte-identical |
| `plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php` | Modified | extended (host harness + 3 cases) | tracked anonymous `articulo` (`pvp` seeded at `42.0`), anonymous `VentasArticulo` host harness, `selectedTarifaSpvpProvider` and the two `pvp` cases |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 3.1 | `tests/Controller/VentasArticuloPerTarifaPaneTest.php` | Unit | ✅ 5/29 (`VentasArticuloPerTarifaPaneTest`) | ✅ Written (3 runs, 2 failed) | ✅ Passed 8/8 | ✅ 2 `spvp` datasets + the no-tarifa branch | ✅ host harness kept minimal by overriding the post-save side steps |
| 3.2 | `Controller/VentasArticulo.php` | Unit | ✅ 5/29 | ✅ Confirmed unconditional write | ✅ Passed 8/40 | ✅ selected vs no-tarifa branches | ✅ Clean (one guard, no restructuring) |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloPerTarifaPaneTest` → `OK (8 tests, 40 assertions)`, exit 0 |
| Runtime harness command/scenario and exact result | N/A — DB-free anonymous-subclass seam tests running the REAL `editarArticulo()` body with the CSRF token, permission gate and post-save per-tarifa/etiqueta steps stubbed; the plugin suite has no live HTTP harness. Integration/contract coverage for the unified host pane arrives in Units 4–5. |
| Rollback boundary | Revert the one `if ($this->codtarifa === '')` guard around the `pvp` assignment in `editarArticulo()` and its two `pvp` test cases (plus the host harness helpers) in `tests/Controller/VentasArticuloPerTarifaPaneTest.php`. With zero tarifas the base-price path is byte-identical to today, so no consumer is affected. |

### Notes / deviations

- `Controller/VentasArticulo.php` MUST NOT contain `guardar_precio_tab` — verified
  `grep -c guardar_precio_tab` → `0`. The WU-1 endpoint remains the only
  per-tarifa price writer (ART-02 intact).
- The test cases land in `VentasArticuloPerTarifaPaneTest.php` per the Unit-3
  deliverable and the orchestrator instruction. The `ART-01` delta spec annotates
  the "With no active tarifas the base price is written" scenario with
  `VentasArticuloTarifaSelectionTest.php` (design AD-9 item 1 repeats it); the
  scenario itself is covered here, in the same file as its sibling branch, so
  only the file annotation drifts. Flagged for the verify phase; the spec text,
  the behavior and the assertions are unchanged.
- Task 0 (sibling `caracteristicas-producto` archive gate) is still NOT satisfied.
  Unit 3 is code/test-only and does not consume the sibling's post-merge spec
  wording (the AD-5 rule is about the base-price write, not about per-tarifa
  visibility), so it was implemented as assigned. The gate still MUST close
  before Units 4–5.

## Batch 4 — Unit 4 (PR 4 of 5): unified `#datos` pane, selector and boot re-init (AD-1, AD-7, AD-8)

**Status**: COMPLETE.
**Scope assigned**: Unit 4 only (tasks 4.1–4.6). `Controller/VentasArticulo.php`,
`controller/tarif_tab_precios.php`, `Init.php`, `View/Hooks/ventas_articulo_*.html.twig`,
`View/partials/articulos/tab_opcionales.html.twig` and every other test file were NOT
touched. `tests/VentasArticuloControllerTest.php` (ART-08) and
`tests/Integration/CatalogoCoreHookMarkersTest.php` are byte-identical to HEAD.

### RED evidence (before GREEN)

Command: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloTarifaSelectionTest`
Result: `Tests: 9, Assertions: 16, Failures: 2` (exit 1). Exactly the two new view
cases fail; the seven Unit-1 selection cases stay green:

| Test | Observed failure | Reason |
|------|------------------|--------|
| `test_view_degrades_to_the_editable_base_price_with_no_active_tarifas` | `Failed asserting that false is not false` at `assertNotFalse($precios)` | the five-tab view has no `#precios-tarifa` unified-pane section |
| `test_view_scopes_the_selector_to_the_resolved_active_tarifa` | `Failed asserting that ... contains "name=\"codtarifa\""` | the five-tab view emits no tarifa selector |

Command: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloPerTarifaPaneTest`
Result: `Tests: 9, Assertions: 41, Failures: 1` (exit 1).

| Test | Observed failure | Reason |
|------|------------------|--------|
| `test_host_pane_embeds_the_scoped_wu1_rows_placeholder` | `Failed asserting that '<view source>' contains "id=\"tab_tarifario_precios_rows\""` | the host view has no embedded scoped rows placeholder (only the injected hook does) |

Command: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloArticleEditAbsorptionTest`
Result: `Tests: 16, Assertions: 98, Failures: 1` (exit 1).

| Test | Observed failure | Reason |
|------|------------------|--------|
| `test_view_preserves_tabs_and_frozen_markers` (migrated) | `Failed asserting that 5 is identical to 2` (`substr_count($view, 'role="presentation"')`) | the view still declares five tabs |

### GREEN evidence (after implementation)

| Command | Result |
|---------|--------|
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloArticleEditAbsorptionTest` | `OK (16 tests, 109 assertions)`, exit 0 |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloControllerTest` | `OK (18 tests, 25 assertions)`, exit 0 (UNEDITED) |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoCoreHookMarkersTest` | `OK (12 tests, 68 assertions)`, exit 0 (UNEDITED) |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloTarifaSelectionTest` | `OK (9 tests, 35 assertions)`, exit 0 |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloPerTarifaPaneTest` | `OK (9 tests, 48 assertions)`, exit 0 |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | `Tests: 813, Assertions: 3553, Warnings: 26, Skipped: 1` — `OK`, exit 0 |
| `ddev exec php vendor/bin/phpunit` (root, regression) | `Tests: 2389, Assertions: 7997, Skipped: 24` — exit 0 |
| `ddev exec php -l plugins/catalogo_core/View/ventas_articulo.html.twig` | `No syntax errors detected` |

Baseline was `810 tests / 3515 assertions`; the delta is exactly `+3 tests / +38
assertions` (2 selection view cases + 1 host-placeholder case + the widened migrated
absorption assertion). Warnings (26) and skipped (1) are unchanged.

### Files touched

| File | Action | Lines | What |
|------|--------|-------|------|
| `plugins/catalogo_core/View/ventas_articulo.html.twig` | Modified (rewritten) | full | `htmx.boot({'allowScriptTags': false})` moved to the top; gated selector cloning `tarif_opcional_edit` L28–62; ONE `#datos` pane with the section-nav anchors (`#datos-ficha`, `#precios-tarifa`, `#stock-articulo`, `#multiidioma`, `#imagenes`) holding Datos, per-tarifa Precios, Stock, Idiomas and Imágenes; `#opcionales` the only secondary tab; per-tarifa box keeps `id="tab_tarifario_precios"`, `data-tipo="articulo"`, `x-data="articuloTabPrecios" x-cloak` and the scoped `hx-get` (`action=rows&tipo=articulo&ref=…&codtarifa=…`, `hx-trigger="load"`); base `pvp` panel only in the no-tarifa branch; both frozen markers + all locked literals preserved; host-owned guarded `htmx:after:swap` → `Alpine.initTree` |
| `plugins/catalogo_core/View/partials/articulos/tab_multiidioma.html.twig` | Modified | root + wrapper | root changed from `role="tabpanel" class="tab-pane"` to a visible `panel panel-default` section keeping `id="multiidioma"` (a nested `.tab-pane` would be hidden by Bootstrap CSS inside `#datos`) |
| `plugins/catalogo_core/View/Hooks/partials/tab_save_script.html.twig` | Modified | removed tail IIFE | duplicate `htmx:after:swap` block removed (host owns re-init); `Alpine.data` + `htmx:after:request` kept; docblock updated |
| `plugins/catalogo_core/tests/Controller/VentasArticuloTarifaSelectionTest.php` | Modified | extended | view-render harness + 2 render cases (zero-tarifa degradation, scoped selector) |
| `plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php` | Modified | extended | host scoped-rows-placeholder case |
| `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | Modified | migrated 1 test | unified-pane tab contract + locked literals; markers, WU-1 reuse, opcional and hygiene assertions untouched |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 4.1 | `tests/Controller/VentasArticuloTarifaSelectionTest.php` + `.../VentasArticuloPerTarifaPaneTest.php` | Integration (view render + source contract) | ✅ 810/3515 | ✅ Written (3 cases, 3 failed) | ✅ Passed 9/35 + 9/48 | ✅ zero-tarifa vs two-tarifa renders; source + rendered | ✅ shared `renderView()`/`inputTag()` helpers |
| 4.2 | `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | Contract (view source) | ✅ 810/3515 | ✅ Migrated (5 tabs ≠ 2) | ✅ Passed 16/109 | ✅ tab count + anchors + locked literals + markers | ✅ Clean |
| 4.3 | `View/ventas_articulo.html.twig` | View | ✅ 810/3515 | ✅ Confirmed RED first | ✅ Passed | ✅ no-tarifa branch + tarifa branch | ✅ section markup kept flat and source-div-balanced |
| 4.4 | `View/ventas_articulo.html.twig` + `View/Hooks/partials/tab_save_script.html.twig` | View | ✅ 810/3515 | ✅ Confirmed (hygiene/swap assertions) | ✅ Passed | ✅ host boot + single re-init | ✅ duplicate swap binder removed |
| 4.5 | `View/partials/articulos/tab_multiidioma.html.twig` | View | ✅ 810/3515 | ✅ Confirmed (markers/render tests) | ✅ Passed | ✅ anchor + visible section | ✅ single panel instead of nested panel |
| 4.6 | — | Gate | ✅ 810/3515 | ✅ Confirmed RED first | ✅ Passed (byte-identical to HEAD) | ➖ N/A | ➖ N/A |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloArticleEditAbsorptionTest` → `OK (16 tests, 109 assertions)`, exit 0; `--filter VentasArticuloControllerTest` → `OK (18/25)`; `--filter CatalogoCoreHookMarkersTest` → `OK (12/68)` |
| Runtime harness command/scenario and exact result | `ddev exec php vendor/bin/phpunit` (root, shared process) → `Tests: 2389, Assertions: 7997, Skipped: 24`, exit 0. The view is additionally rendered for real through Twig in `VentasArticuloTarifaSelectionTest` (no-tarifa and two-tarifa branches) and in both frozen-marker/ownership suites. |
| Rollback boundary | Revert `View/ventas_articulo.html.twig`, `View/partials/articulos/tab_multiidioma.html.twig` and `View/Hooks/partials/tab_save_script.html.twig` to HEAD, and revert the migrated assertion in `VentasArticuloArticleEditAbsorptionTest.php` (plus the two new view cases). The hook injection still exists until Unit 5, so reverting restores the five-tab layout. |

### Notes / deviations

- **Defect found and fixed during GREEN (test hygiene)**: the new view-render harness
  first registered `render_hook` without `is_safe: ['html']`. Twig reuses the compiled
  template class process-wide, so under the ROOT suite (shared process; the plugin
  config runs `processIsolation="true"`) that declaration baked HTML escaping into the
  compiled `ventas_articulo.html.twig` class and broke
  `CatalogoCoreHookMarkersTest::test_registered_fragment_renders_unescaped_at_the_host_marker`
  and `CatalogoArticuloHookOwnershipTest::test_article_pair_renders_at_frozen_markers_with_tipo_articulo`.
  The harness now mirrors `src/Core/Html.php` (`is_safe: ['html']`) and resets
  `ViewHookRegistry` in `setUp()`. Root suite is back to exit 0. This was a *test*
  defect, never a production one; both locked integration suites stayed unedited.
- **Locked-literal survival mechanism (AD-7)**: `#multiidioma` is carried by the
  in-pane section anchor `href="#multiidioma"` whose target is the now-visible
  `tab_multiidioma.html.twig` panel; `#precios`/`#stock` survive only as
  `#precios-tarifa`/`#stock-articulo` anchors (never as tabs); `#opcionales`,
  both includes and `fsc.articulo.pvp` are unchanged.
- **Defensive `activarDesdeHash()` change (in scope of the view rewrite)**: the
  previous implementation activated any `window.location.hash` value. With the new
  in-pane section anchors a reload carrying `#multiidioma`/`#imagenes` would have
  removed `.active` from `#datos` and hidden the whole pane. `activarDesdeHash()` now
  only activates hashes that resolve to a real `#tab_articulo` tab link; tab behavior
  (`#datos`, `#opcionales`) is unchanged. No test asserts the old JS internals; the
  ART-04 hygiene assertions stay green.
- **Transitional duplicate id until Unit 5**: the persisted
  `View/Hooks/ventas_articulo_tab_pane_after.html.twig` still injects
  `id="tab_tarifario_precios"` at the pane marker, so until Unit 5 retires the
  injection the rendered page carries that id twice. Unit 4's contract tests are
  satisfied because the host box deliberately omits `class="tab-pane"` (which is what
  `CatalogoArticuloHookOwnershipTest` discriminates on) and Unit 5 removes the
  injection. This is the planned transitional state, not a defect.
- `View/partials/articulos/tab_opcionales.html.twig` is untouched (still
  `role="tabpanel"`, non-active `tab-pane`, `id="opcionales"`).
- **Task 0 hard gate is still NOT satisfied** (sibling `caracteristicas-producto`
  archive). Unit 4 is view/test-only and consumes no sibling spec wording; the gate
  still MUST close before Unit 5.

## Batch 5 — Unit 5 (PR 5 of 5): hook retirement with inert markers (AD-6, D4)

**Status**: COMPLETE.
**Scope assigned**: Unit 5 only (tasks 5.1–5.2). `Controller/VentasArticulo.php`,
`controller/tarif_tab_precios.php`, `View/ventas_articulo.html.twig`, the kept
partials and every other test file were NOT touched. The two `render_hook(...)`
calls in `View/ventas_articulo.html.twig` keep their exact names, whitespace
control, context and positions. `tests/VentasArticuloControllerTest.php` (ART-08)
and `tests/Integration/CatalogoCoreHookMarkersTest.php` are byte-identical to the
plugin-repo HEAD.

### RED evidence (before GREEN)

Command: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoArticuloHookOwnershipTest`
Result: `Tests: 12, Assertions: 75, Failures: 3` (exit 1). Exactly the three
flipped expectations fail; the nine preserved endpoint/rows/hygiene contracts
stay green:

| Test | Observed failure | Reason |
|------|------------------|--------|
| `test_catalogo_core_registers_zero_article_hooks_across_rebuilds` | `Failed asserting that true is false` | `ARTICULO_HOOK_TEMPLATES` still registers both article hooks behind the static guard |
| `test_retired_article_hook_templates_and_init_mappings_are_gone` | `Failed asserting that file ".../ventas_articulo_tabs_after.html.twig" does not exist` | the two hook templates are still present |
| `test_host_renders_the_unified_pane_without_an_injected_tarifas_surface` | `does not contain "href="#tab_tarifario_precios"` (inverted assertion tripped) | the retired tab header is still injected at `ventas_articulo_tabs_after` |

### GREEN evidence (after implementation)

| Command | Result |
|---------|--------|
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoArticuloHookOwnershipTest` | `OK (12 tests, 93 assertions)`, exit 0 |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoCoreHookMarkersTest` | `OK (12 tests, 68 assertions)`, exit 0 (UNEDITED, byte-identical to HEAD) |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | `Tests: 813, Assertions: 3561, Warnings: 26, Skipped: 1` — `OK`, exit 0 |
| `ddev exec php vendor/bin/phpunit` (root, regression) | `Tests: 2389, Assertions: 8005, Skipped: 24` — exit 0 |
| `ddev exec php -l plugins/catalogo_core/Init.php` | `No syntax errors detected` |
| `ddev exec php -l plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` | `No syntax errors detected` |

Baseline was `813 tests / 3553 assertions`; the test count is unchanged and the
delta is `+8 assertions` from the flipped file. Warnings (26) and skipped (1) are
unchanged. `git status --porcelain` on the two locked tests is empty and their
sha256 equals the plugin-repo `HEAD` blob.

### Files touched

| File | Action | What |
|------|--------|------|
| `plugins/catalogo_core/Init.php` | Modified | deleted the `ARTICULO_HOOK_TEMPLATES` const + docblock and its `registerHooks()` loop; `registerHooks()` now registers only the opcional pair under the unchanged `$hooksRegistered` guard; the `registerHooks()`/`registerViewExtensions()` docblocks reflect the article retirement |
| `plugins/catalogo_core/View/Hooks/ventas_articulo_tabs_after.html.twig` | Deleted | retired (D4) |
| `plugins/catalogo_core/View/Hooks/ventas_articulo_tab_pane_after.html.twig` | Deleted | retired (D4) |
| `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` | Modified (flipped) | zero article registration across two builds + opcional pair stays; grep gate for both templates and the `Init.php` mapping; host unified-pane render (no injected header/pane, scoped WU-1 rows request); unsaved article renders no price surface; endpoint/rows/hygiene tests re-pointed off the deleted pane template |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 5.1 | `tests/Integration/CatalogoArticuloHookOwnershipTest.php` | Integration (boot + registry + real host render) | ✅ 813/3553 | ✅ Flipped (12 tests, 3 failed) | ✅ Passed 12/93 | ✅ two builds + grep gate + rendered host (tarifa/no-tarifa) | ✅ `retired-pane` re-point, dead `twigFor()` helper removed |
| 5.2 | `Init.php` + 2 template deletions | Integration | ✅ 813/3553 | ✅ Confirmed hooks registered / files present | ✅ Passed 12/93 + 12/68 (frozen) | ✅ zero-registration + opcional-still-registered + markers inert | ✅ docblocks trimmed, no dead constant |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CatalogoArticuloHookOwnershipTest\|CatalogoCoreHookMarkersTest'` → `OK (12/93)` + `OK (12/68)`, exit 0 |
| Runtime harness command/scenario and exact result | `ddev exec php vendor/bin/phpunit` (root, shared process) → `Tests: 2389, Assertions: 8005, Skipped: 24`, exit 0. The real `ventas_articulo.html.twig` is rendered through Twig with (a) an active tarifa (unified pane + scoped WU-1 rows request, no injected surface) and (b) an unsaved article (no price surface) in the flipped suite. |
| Rollback boundary | Restore the `ARTICULO_HOOK_TEMPLATES` const + its `registerHooks()` loop in `Init.php`, restore both deleted hook templates, and revert the flipped `CatalogoArticuloHookOwnershipTest.php`. The frozen markers, the kept partials and every other file are untouched, so nothing else is affected. |

### Notes / deviations

- The flipped `test_retired_article_hook_templates_and_init_mappings_are_gone`
  covers both spec scenarios (retired templates + no remaining mapping) and
  asserts the opcional mapping survives.
- `test_unsaved_article_renders_no_price_surface` passes before and after the
  change (the retired hooks were already guarded on `fsc.articulo.referencia`);
  it is kept as a regression guard.
- The nine preserved tests keep the endpoint (`extends fbase_controller`,
  `requireCsrf()`, `puede_editar_articulo(`, rows partial), the rows/currency
  contract, the resolver-driven visibility read, the no-write render path and the
  htmx 4/Alpine CSP hygiene. Three of them previously read the now-deleted
  `ventas_articulo_tab_pane_after.html.twig`; they are re-pointed at
  `View/ventas_articulo.html.twig` (where the `[x-cloak]` shell and the boot now
  live) and at the kept `tab_save_script.html.twig`.
- **Discovered integration gap (NOT fixed — out of Unit 5 scope)**: deleting the
  pane template removes the only `{% include '@catalogo_core/Hooks/partials/tab_save_script.html.twig' %}`.
  AD-6 says the save wiring must be "included from the host view instead of the
  deleted pane", but Unit 4's host rewrite never added that include and no PHP
  path renders it, so after this unit the page no longer registers
  `Alpine.data('articuloTabPrecios')` nor binds the `htmx:after:request` rows
  swap for `tarif_tab_precios`. The `x-data="articuloTabPrecios" x-cloak` box
  would stay hidden. The spec scenarios do not assert the client wiring, so the
  suite is green, but the manual smoke (Unit 6.3) would fail. Flagged for the
  orchestrator; the fix belongs with the host view (plus a stub key in the two
  Twig render harnesses) or in Unit 6.



## Remaining units

| Unit | Goal | Status |
|------|------|--------|
| Task 0 | Archive sibling `caracteristicas-producto` first (AD-10) | **OPEN — NOT SATISFIED** (see Risks) |
| 2 | Scoped per-tarifa rows read (AD-3) | COMPLETE (Batch 2) |
| 3 | Conditional `articulos.pvp` write (C1) | COMPLETE (Batch 3) |
| 4 | Unified `#datos` pane + selector + boot re-init | COMPLETE (Batch 4) |
| 5 | Hook retirement (D4) with inert markers | COMPLETE (Batch 5) |
| 6 | Regression gate (full suite + phpstan) | Not started |

## Risks

- **Task 0 hard gate is NOT satisfied.** `plugins/catalogo_core/openspec/changes/caracteristicas-producto/`
  is still active (`verify-report.md` `verdict: fail`, `blockers: 1`) and the
  canonical `articulo-detalle-canonico` spec does NOT yet contain the sibling's
  post-merge wording (`grep "articulo-scope feature values"` → no match). Units 1
  and 2 are code/test-only and do not consume that spec text (Unit 2 is an
  additive read scope over the already feature-value-backed visibility), so they
  were implemented as assigned; the gate MUST still be closed before Units 3–5,
  whose ART-01/ART-05 and `catalogo-render-hooks` deltas are authored against the
  sibling post-merge text.
- `remove-fs-demo` shares `Controller/VentasArticulo.php`; keep future rebases narrow.
- **HIGH — the article row-save wiring lost its only includer after Unit 5.**
  `View/Hooks/partials/tab_save_script.html.twig` was included exclusively by the
  retired `View/Hooks/ventas_articulo_tab_pane_after.html.twig`; the host
  `View/ventas_articulo.html.twig` never includes it and no PHP path renders it.
  AD-6 requires it to be included "from the host view instead of the deleted
  pane", but neither Unit 4 nor Unit 5 did it. Consequence: the rendered page no
  longer registers `Alpine.data('articuloTabPrecios')` nor binds the
  `htmx:after:request` handler that swaps `#tab_tarifario_precios_rows`, so the
  `x-data="articuloTabPrecios" x-cloak` box stays hidden and the one-rail row
  save does not work. No current test asserts the client wiring (the suite is
  green: plugin `813/3561` exit 0, root `2389/8005` exit 0), so this is caught
  only by the Unit 6.3 manual smoke. Fix belongs with the host view, plus a stub
  key in the Twig render harnesses (`VentasArticuloTarifaSelectionTest::renderView()`
  and `CatalogoArticuloHookMarkersTest` only if the branch executes there), and
  should land before verify/archive.
- **Task 0 hard gate is still NOT satisfied for Unit 5.** The change's
  `catalogo-render-hooks` REMOVED block is declared a no-op until the sibling
  archives, so the code/test work landed as assigned; the sibling must still
  archive before this change's deltas are merged into the canonical specs.

## Correction Batch — Host view save-wiring include (AD-6 gap)

**Status**: COMPLETE (scoped corrective `sdd-apply`).
**Scope assigned**: fix the Unit 5-discovered HIGH risk — the per-tarifa save
wiring lost its only includer when the pane template was retired. Deliverables:
one RED case in `tests/Controller/VentasArticuloPerTarifaPaneTest.php`, the
include in `View/ventas_articulo.html.twig`, and the `@catalogo_core` stub in the
`VentasArticuloTarifaSelectionTest` render harness. No other production file was
touched; the two deleted hook templates stay deleted and the article hooks stay
unregistered; `tests/VentasArticuloControllerTest.php` and
`tests/Integration/CatalogoCoreHookMarkersTest.php` are byte-identical to HEAD.

### RED evidence (before GREEN)

Command: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloPerTarifaPaneTest`
Result: `Tests: 10, Assertions: 49, Failures: 1` (exit 1). The new case fails for
the stated reason only; the nine pre-existing endpoint/pvp cases stay green:

| Test | Observed failure | Reason |
|------|------------------|--------|
| `test_host_view_includes_the_per_tarifa_save_wiring_in_the_tarifa_branch` | `Failed asserting that false is not false` (`strpos` returned `false`) | the host view never includes the save-script partial |

### GREEN evidence (after implementation)

| Command | Result |
|---------|--------|
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloPerTarifaPaneTest` | `OK (10 tests, 54 assertions)`, exit 0 |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloTarifaSelectionTest` | `OK (9 tests, 35 assertions)`, exit 0 |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoCoreHookMarkersTest` | `OK (12 tests, 68 assertions)`, exit 0 (UNEDITED, byte-identical to HEAD) |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoArticuloHookOwnershipTest` | `OK (12 tests, 93 assertions)`, exit 0 |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | `Tests: 814, Assertions: 3567, Warnings: 26, Skipped: 1` — `OK`, exit 0 |
| `ddev exec php vendor/bin/phpunit` (root, regression) | `Tests: 2390, Assertions: 8011, Skipped: 24` — exit 0 |

Baseline was `813 tests / 3561 assertions`; the delta is exactly `+1 test / +6
assertions` (the new host-wiring case, run in both suites). Warnings (26) and
skipped (1) are unchanged. The root suite baseline was `2389 / 8005`; delta `+1
test / +6 assertions`.

### Files touched

| File | Action | What |
|------|--------|------|
| `plugins/catalogo_core/View/ventas_articulo.html.twig` | Modified | added `{% include '@catalogo_core/Hooks/partials/tab_save_script.html.twig' %}` inside the `fsc.tarifas`-gated `#precios-tarifa` branch, after the per-tarifa box and before the no-tarifa `{% else %}` (mirrors the retired pane include) |
| `plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php` | Modified | new red-first case `test_host_view_includes_the_per_tarifa_save_wiring_in_the_tarifa_branch` (source contract: include present exactly once, after the rows placeholder and before the no-tarifa `{% else %}`) |
| `plugins/catalogo_core/tests/Controller/VentasArticuloTarifaSelectionTest.php` | Modified | `renderView()` ArrayLoader resolves `@catalogo_core/Hooks/partials/tab_save_script.html.twig` (empty stub; the harness has no namespace loader). Existing assertions unchanged |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| Correction (AD-6 host include) | `tests/Controller/VentasArticuloPerTarifaPaneTest.php` | Contract (view source) | ✅ 813/3561 | ✅ Written (10 tests, 1 failed) | ✅ Passed 10/54 | ✅ present-once + position vs rows placeholder + position vs `{% else %}` | ✅ comment kept path-free so the `substr_count === 1` guard stays exact |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloPerTarifaPaneTest` → `OK (10 tests, 54 assertions)`, exit 0 |
| Runtime harness command/scenario and exact result | The include is exercised for real by `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoArticuloHookOwnershipTest` → `OK (12/93)`, exit 0: that suite renders the real host view with an active tarifa through a ChainLoader whose `@catalogo_core` namespace resolves the save-script partial, so the include is proven loadable, not just present in source. Root regression: `ddev exec php vendor/bin/phpunit` → `Tests: 2390, Assertions: 8011, Skipped: 24`, exit 0. |
| Rollback boundary | Remove the added `{% include '@catalogo_core/Hooks/partials/tab_save_script.html.twig' %}` line from `View/ventas_articulo.html.twig`, delete the new `test_host_view_includes_the_per_tarifa_save_wiring_in_the_tarifa_branch` case from `VentasArticuloPerTarifaPaneTest.php`, and remove the stub key from `VentasArticuloTarifaSelectionTest::renderView()`. Nothing else is affected. |

### Notes / deviations

- The include is placed inside the `fsc.tarifas|length > 0` branch (not the outer
  `fsc.articulo.referencia` gate), so a zero-tarifa article does not pull in the
  save wiring — the new case asserts the position explicitly.
  `CatalogoCoreHookMarkersTest` never executes that branch (its host stub exposes
  no tarifas), which is why it stays green unedited.
- The `CatalogoArticuloHookOwnershipTest` render harness already resolves the
  `@catalogo_core` namespace via Init's `TwigLoaderEvent` listener, so it needed
  no change; only the pure-`ArrayLoader` selection harness needed the stub.
- The `VentasArticuloTarifaSelectionTest` stub is empty by design and mirrors the
  existing stubbed partials (`tab_multiidioma`, `tab_opcionales`); it resolves the
  include without pulling the client script into a selection-only harness. No
  existing assertion was weakened.
- No task row in `tasks.md` corresponds to this corrective slice, so no checkbox
  was added or changed.

### Risk resolution

- **RESOLVED — the HIGH risk recorded for Unit 5** ("the article row-save wiring
  lost its only includer"). The host view now includes
  `tab_save_script.html.twig` inside the tarifa branch, so the page registers
  `Alpine.data('articuloTabPrecios')` and binds the `htmx:after:request` rows
  swap; the `x-data="articuloTabPrecios" x-cloak` box is no longer structurally
  hidden. Task 0 (sibling `caracteristicas-producto` archive) and Unit 6
  (regression gate / manual smoke) remain open and unchanged by this correction.

## Correction Batch — Product-scoped price block (owner UI adjustment)

**Status**: COMPLETE (scoped UI-adjustment `sdd-apply`).
**Scope assigned**: replace the TABLE in
`View/Hooks/partials/articulo_precios_rows.html.twig` with a single
product-scoped price block so the surface reads as the price of THIS product in
the selected tarifa, not as a tariffs editor. Deliverables: two RED-first
contract tests in `tests/TarifTabPreciosTest.php` and the partial rewrite. No
other production file was touched. The endpoint (`controller/tarif_tab_precios.php`),
the host view (`View/ventas_articulo.html.twig`), the save wiring
(`tab_save_script.html.twig`), the opcional partial and every other test file are
byte-identical to their pre-adjustment state. `tests/VentasArticuloControllerTest.php`
(ART-08) and `tests/Integration/CatalogoCoreHookMarkersTest.php` are byte-identical
to the plugin-repo HEAD. `Controller/VentasArticulo.php` still has zero
`guardar_precio_tab` (ART-02 intact).

### RED evidence (before GREEN)

Command: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifTabPreciosTest`
Result: `Tests: 16, Assertions: 89, Failures: 2` (exit 1). Both new cases fail for
the stated reasons only; the 14 pre-existing endpoint/rows/CSRF/permission/S28
cases stay green:

| Test | Observed failure | Reason |
|------|------------------|--------|
| `test_rows_fragment_renders_a_product_scoped_price_block_without_a_table` | `Failed asserting that '<div class="box" id="tab_tarifario_precios_rows" ...> ... <h3 class="box-title">Precios por tarifa</h3> ... </table>' does not contain "<table"` | the fragment is still the per-tarifa table with its plural title |
| `test_rows_fragment_save_button_includes_the_block_that_holds_the_hidden_inputs` | `Failed asserting that '<... hx-include="closest tr" ...>' does not contain "closest tr"` | the save wiring still includes the removed table row |

### GREEN evidence (after implementation)

| Command | Result |
|---------|--------|
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifTabPreciosTest` | `OK (16 tests, 116 assertions)`, exit 0 |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'VentasArticuloPerTarifaPaneTest\|CatalogoArticuloHookOwnershipTest\|VentasArticuloArticleEditAbsorptionTest\|VentasArticuloControllerTest\|CatalogoCoreHookMarkersTest'` | `OK (68 tests, 349 assertions)`, exit 0 |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | `Tests: 816, Assertions: 3596, Warnings: 26, Skipped: 1` — `OK`, exit 0 |
| `ddev exec php vendor/bin/phpunit` (root) | `Tests: 2392, Assertions: 8040, Failures: 2, Skipped: 24` — exit 1. The 2 failures are PRE-EXISTING and unrelated (see "Root suite" below); the delta vs the recorded `2390 / 8011` baseline is exactly `+2 tests / +29 assertions` and both new cases pass in the root process. |

Baseline was `814 tests / 3567 assertions`; the delta is exactly `+2 tests / +29
assertions` (the two new block-contract cases). Warnings (26) and skipped (1) are
unchanged.

### Root suite — pre-existing unrelated failures (evidence)

`plugins/system_updater/tests/MaintenanceModeCompatTest.php` fails with two
DB-state-dependent assertions (`operationWarningsIncludeStealthWhenNotReady`,
`recentActiveUsersReturnsEmptyWithoutFrameworkFolder`); the latter expects `[]`
but the local DB returns the recent active user `joaquin`
(`last_login = 22-09-2026 15:26:56`). Proof of independence:

| Command | Result |
|---------|--------|
| `ddev exec php vendor/bin/phpunit plugins/system_updater/tests/MaintenanceModeCompatTest.php` (standalone, no catalogo_core code loaded) | `Tests: 3, Assertions: 7, Failures: 1` — same DB-driven failure |
| `ddev exec php vendor/bin/phpunit -c plugins/system_updater/phpunit.xml` (plugin's own isolated suite) | `Tests: 209, Assertions: 414, Failures: 1, Warnings: 2, Skipped: 1` — reproduces without any catalogo_core involvement |

`plugins/system_updater` has a clean working tree (`git status --porcelain`
empty). The failure is environmental (live DB rows) and predates this adjustment.

### Files touched

| File | Action | What |
|------|--------|------|
| `plugins/catalogo_core/View/Hooks/partials/articulo_precios_rows.html.twig` | Modified (rewritten) | Table/thead/"Tarifa" column/plural title removed; each tariff now renders a `.tarifario-tab-precio-bloque` product-scoped block carrying `data-codtarifa` on its root. Kept: root `id="tab_tarifario_precios_rows"`, `data-tipo="articulo"`, `data-referencia`, the price input `name="precio"` + `tarifario-tab-precio` class + currency addon via `fsc.simbolo_divisa(tarifa.coddivisa)`, the `activo`/`en_tarifa`/`en_catalogo` toggles with their `name`/`data-codtarifa`, the hidden `guardar_precio_tab`/`codtarifa`/`referencia` inputs, the `tarifario-tab-guardar` button `hx-post="index.php?page=tarif_tab_precios"` + `hx-swap="none"`, and the zero-tarifa empty state. `hx-include` moved from `closest tr` to `closest .tarifario-tab-precio-bloque` so it still serializes the block that holds the hidden inputs. |
| `plugins/catalogo_core/tests/TarifTabPreciosTest.php` | Modified | Two RED-first contract cases + three private helpers (`fragmentDocument()`, `closestMatchingAncestor()`, `matchesSimpleSelector()`) that parse the rendered fragment with DOM/XPath so tag absence and hx-include ancestor containment are asserted structurally. |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| UI adjustment (block shape) | `tests/TarifTabPreciosTest.php` | Contract (real partial render + DOM) | ✅ 814/3567 | ✅ Written (2 cases, 2 failed) | ✅ Passed 16/116 | ✅ tag-absence + root attrs + single scoped block + named controls + currency addon + hx-include ancestor resolution | ✅ DOM/XPath helpers extracted; comment kept free of the S28 bare-call pattern |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifTabPreciosTest` → `OK (16 tests, 116 assertions)`, exit 0 |
| Runtime harness command/scenario and exact result | The real partial is rendered through Twig by `TarifTabPreciosTest` (scoped + unscoped paths) and by `VentasArticuloPerTarifaPaneTest`; `CatalogoArticuloHookOwnershipTest` renders the real host view with an active tarifa through its `@catalogo_core` namespace loader → `OK (12/93)`. Root regression: `ddev exec php vendor/bin/phpunit` → `Tests: 2392, Assertions: 8040`, with the only 2 failures being the pre-existing `system_updater` DB-state cases proven above. |
| Rollback boundary | Revert `View/Hooks/partials/articulo_precios_rows.html.twig` to its pre-adjustment (table) content and delete the two new cases + three helpers from `tests/TarifTabPreciosTest.php`. The endpoint, the host view, the save wiring, the opcional partial and every other file are untouched, so nothing else is affected. |

### Notes / deviations

- **No table-structure assertion needed migration.** A full sweep of the plugin
  tests found no assertion locking this partial's `<table>`/`<thead>`/column
  count; the only `thead`/`colspan` assertions in the suite belong to
  `VentasArticulosListCaracteristicasTest.php` (the articles LIST view, a
  different file). The scoped-read assertions (`data-codtarifa="USD1"` present,
  `data-codtarifa="EUR1"` absent) and the currency assertions
  (`fsc.simbolo_divisa(tarifa.coddivisa)`, `€`, `$`) stay meaningful and green
  unedited.
- **The `{% for tarifa in tarifas %}` loop is kept.** The scoped endpoint read
  narrows `$tarifas` to one entry, so the owner sees exactly one
  product-scoped block; the scope-less legacy path still renders one block per
  active tariff, which keeps
  `test_rows_fragment_without_scope_keeps_every_active_tarifa` (the AD-3
  additive-scope guard) green and preserves the endpoint's scope-less contract.
- **`data-codtarifa` kept on the block root AND on the inputs/button.** The
  orchestrator asked for the marker on the block root; the inputs/button keep
  theirs because `VentasArticuloPerTarifaPaneTest::checkboxInput()` extracts a
  scoped input by `name` + `data-codtarifa`, and the existing save wiring is
  unchanged.
- **ART-02 intact**: `grep -c guardar_precio_tab Controller/VentasArticulo.php`
  → `0`; the partial still posts to `page=tarif_tab_precios` exactly once.
- **No `tasks.md` row corresponds to this UI-adjustment slice**, so no checkbox
  was added or changed (same convention as the previous corrective batch).
- **Spec deltas unaffected**: the change's delta specs
  (`articulo-detalle-canonico`, `articulo-tarifa-tab-management`,
  `catalogo-render-hooks`) constrain scoping, endpoint ownership, gates and
  hygiene, never the table shape; no requirement is contradicted, so no delta
  edit was authored by apply.
- Task 0 (sibling `caracteristicas-producto` archive) and Unit 6 (regression
  gate / manual smoke) remain open and unchanged by this adjustment.

### Remaining units (updated view)

| Unit | Goal | Status |
|------|------|--------|
| Task 0 | Archive sibling `caracteristicas-producto` first (AD-10) | **OPEN — NOT SATISFIED** |
| 2–5 | Scoped rows / conditional pvp / unified pane / hook retirement | COMPLETE (Batches 2–5) |
| Correction | Host view save-wiring include (AD-6 gap) | COMPLETE |
| Correction | Product-scoped price block (owner UI adjustment) | COMPLETE (this batch) |
| 6 | Regression gate (full suite + phpstan) | Not started |
