# Apply Progress: opcionales-tarifa-selector-htmx4

- **Change**: `opcionales-tarifa-selector-htmx4`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Mode**: **Strict TDD** (RED before GREEN)
- **Scope applied**: Phases 1–3 (WU-1, WU-2, WU-3)
- **Runner**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- **Baseline (pre-apply)**: 455 tests / 1381 assertions, 25 warnings, 1 skipped
- **Delivery**: human-owned. No `git commit`, push, branch or PR was created.

---

## 1. Files changed

| File | Action | What changed |
|------|--------|--------------|
| `plugins/catalogo_core/controller/tarif_opcional_edit.php` | Modified | Added `public $tarifa_seleccionada;`; added `resolver_tarifa_seleccionada()` + `guard_mutating_action()`; call the resolver in `private_core()` right after `init_tarifario_opcional_state()`; moved `add_familia` / `remove_familia` / `add_articulo` / `remove_articulo` dispatch from `$_GET` to guarded `$_POST`. Bulk `guardar_precios_tarifas()`, `guardar_precio_tarifa()`, `load_precios_tarifas()`, accessors and the master seam are byte-identical. |
| `plugins/catalogo_core/View/tarif_opcional_edit.html.twig` | Modified | Added `Macro/Htmx` + `Macro/Alpine` imports and `htmx.boot({'allowScriptTags': false})` / `alpine.boot()`; added `[x-cloak]`; added the tarifa `<select name="codtarifa">` with the htmx-4 body-swap attribute set; replaced the bulk per-tarifa forms with one scoped editable panel over `fsc.tarifa_seleccionada` plus a read-only `fsc.precios_tarifas` overview; replaced bootbox/onclick/jQuery-UI autocomplete with Alpine CSP components and a nonce'd classic registration script + guarded `htmx:after:swap` re-init. `{% import 'Macro/TarifarioComponents.html.twig' as tarif %}` kept byte-identical. |
| `plugins/catalogo_core/tests/TarifOpcionalEditTarifaSelectorTest.php` | Created | Behaviour (Reflection on an anonymous `extends \tarif_opcional_edit` subclass) + source-contract tests for the selector, resolution fallbacks, scoped panel and all-tarifas overview. |
| `plugins/catalogo_core/tests/TarifOpcionalesHtmxContractTest.php` | Created | htmx 4 / Alpine CSP / colon-event / POST-only / idempotent re-init contract tests. |

Out-of-scope files were not touched: `model/tarif_tarifa_opcional.php`, `model/tarif_opcional_precio.php`, `controller/tarif_configurador_opcionales.php`, `View/tarif_opcional_precios.html.twig`, `View/tarif_opcionales.html.twig`, `tpvmod`, `ventas_opcionales`, the core `openspec/`. (Other dirty paths visible in `git status` are pre-existing WIP from the sibling change `opcionales-por-tarifa`.)

---

## 2. TDD Cycle Evidence

| Task | Test file | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1 | `tests/TarifOpcionalEditTarifaSelectorTest.php` | Unit (behaviour, DB-free) | ✅ 455/455 baseline | ✅ Written (17 tests failed/errored) | ✅ Passed | ✅ 5 resolution cases (requested/unknown/absent/no-default/no-tarifas) | ➖ None needed |
| 1.2 | `tests/TarifOpcionalEditTarifaSelectorTest.php` | Contract (source) | ✅ 455/455 | ✅ Written | ✅ Passed | ✅ 4 contract methods + 1 scoped-form count | ➖ None needed |
| 1.3 | `tests/TarifOpcionalesHtmxContractTest.php` | Contract (source) | ✅ 455/455 | ✅ Written | ✅ Passed | ✅ Controller resolver + guard + GET-removed cases | ➖ None needed |
| 1.4 | `controller/tarif_opcional_edit.php` | Implementation | ✅ 455/455 | ✅ (1.1–1.3 RED) | ✅ Passed | ✅ 5 fallback cases | ➖ None needed |
| 1.5 | `View/tarif_opcional_edit.html.twig` | Implementation | ✅ 455/455 | ✅ (1.2 RED) | ✅ Passed | ✅ Selector + scoped panel + overview | ➖ None needed |
| 1.6 | full suite | Regression gate | ✅ 455/455 | n/a | ✅ Passed (472/472) | ✅ 5 locked classes green | ➖ None needed |
| 2.1 | `tests/TarifOpcionalesHtmxContractTest.php` + `TarifOpcionalEditTarifaSelectorTest.php` | Contract (source) | ✅ 455/455 | ✅ Written | ✅ Passed | ✅ 7 hx attributes asserted on the selector block | ➖ None needed |
| 2.2 | `View/tarif_opcional_edit.html.twig` | Implementation | ✅ 455/455 | ✅ (2.1 RED) | ✅ Passed | ✅ boot + selector swap | ➖ None needed |
| 2.3 | full suite | Regression gate | ✅ 455/455 | n/a | ✅ Passed (472/472) | ✅ locked classes green | ➖ None needed |
| 3.1 | `tests/TarifOpcionalesHtmxContractTest.php` | Contract (source) | ✅ 455/455 | ✅ Written | ✅ Passed | ✅ 5 Alpine/event/mutation methods + directive inventory | ➖ None needed |
| 3.2 | `controller/tarif_opcional_edit.php` | Implementation | ✅ 455/455 | ✅ (1.3 RED) | ✅ Passed | ✅ guard body asserted | ➖ None needed |
| 3.3 | `View/tarif_opcional_edit.html.twig` | Implementation | ✅ 455/455 | ✅ (3.1 RED) | ✅ Passed | ✅ 3 Alpine components + swap listener | ➖ None needed |
| 3.4 | full suite | Regression gate | ✅ 455/455 | n/a | ✅ Passed (472/472) | ✅ `MasterStateTest` 511–570 green | ➖ None needed |

### Test summary

- Tests written: **17** (9 in `TarifOpcionalEditTarifaSelectorTest`, 8 in `TarifOpcionalesHtmxContractTest`).
- Assertions added: **76**.
- Layers: Unit (behaviour) 5, Contract (source) 12, Integration/E2E 0 (DB-free suite; runtime smoke deferred to verify per design §6/§10).
- Pure functions created: 0 (the resolver is a state-mutating controller method; its branching is covered by 5 behaviour cases).
- Approval tests: none — no refactoring-only tasks.

---

## 3. Work Unit Evidence

### WU-1 — selector resolution + scoped panel

| Evidence | Value |
|---|---|
| Focused test command and result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/TarifOpcionalEditTarifaSelectorTest.php plugins/catalogo_core/tests/TarifOpcionalesHtmxContractTest.php` → **OK (17 tests, 76 assertions)** |
| Runtime harness | N/A at apply scope — DB-backed page render is deferred to verify (design §6/§10). Behaviour is proven DB-free via the Reflection seam. |
| Rollback boundary | Revert `controller/tarif_opcional_edit.php` + `View/tarif_opcional_edit.html.twig` + the two new test files. Bulk `guardar_precios_tarifas` and the master model stay intact, so the legacy full-page flow keeps working. |

### WU-2 — htmx 4 boot + selector body swap

| Evidence | Value |
|---|---|
| Focused test command and result | `... TarifOpcionalesHtmxContractTest.php` `test_edit_view_boots_htmx_and_alpine_macros` + `TarifOpcionalEditTarifaSelectorTest.php` `test_edit_view_exposes_selector_listing_tarifas_with_default_marked` → part of the **17/17 OK** run |
| Runtime harness | N/A — browser body-swap smoke deferred to verify (§10 open questions 1–2). |
| Rollback boundary | Revert the view's macro imports/boot + selector hx attributes; no controller change. |

### WU-3 — Alpine CSP flows + idempotent re-init + POST/CSRF

| Evidence | Value |
|---|---|
| Focused test command and result | `... TarifOpcionalesHtmxContractTest.php` (Alpine/colon/POST/idempotency methods) + `... TarifOpcionalesControllerMasterStateTest.php` → `63 tests, 242 assertions OK` on the 5 locked classes; **17/17** on the two new files |
| Runtime harness | N/A — confirm modal + article search under CSP deferred to verify (§10 open questions 1–4). |
| Rollback boundary | Revert the controller POST guards + view Alpine script/directives + tests. Locked POST toggles and CSRF fields untouched. |

---

## 4. Exact RED → GREEN evidence

### RED

Command:

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
  plugins/catalogo_core/tests/TarifOpcionalEditTarifaSelectorTest.php \
  plugins/catalogo_core/tests/TarifOpcionalesHtmxContractTest.php
```

Observed:

```
ERRORS!
Tests: 17, Assertions: 21, Errors: 5, Failures: 11.
```

Root causes (expected): `resolver_tarifa_seleccionada()` / `$tarifa_seleccionada` did not exist (Reflection errors), the selector/macros/Alpine contracts were absent from the view, and the controller still dispatched the four mutations from `$_GET`.

### GREEN

Same command after implementation:

```
OK (17 tests, 76 assertions)
```

### Full suite (final)

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
→ Tests: 472, Assertions: 1457, Warnings: 25, Skipped: 1.   (OK, but there were issues!)
```

- Baseline 455 tests / 1381 assertions → final **472 tests / 1457 assertions** (+17 / +76).
- Warnings (25) and skipped (1) are identical to the baseline; no new warnings.

### Explicit regression gate

Command (five locked classes passed as file paths, since `--filter` pipes are mangled by the wrapper shell):

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
  plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php \
  plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php \
  plugins/catalogo_core/tests/TarifOpcionalPreciosControllerTest.php \
  plugins/catalogo_core/tests/TarifConfiguradorOpcionalesTest.php \
  plugins/catalogo_core/tests/TarifarioOpcionalStateTraitTest.php
→ OK (63 tests, 242 assertions)
```

All five regression classes are green and unmodified.

PHP lint: `php -l` clean on the controller and both new test files.

---

## 5. Deviations from design

1. **`tarifa_seleccionada` terminal value is `false`, not `null`.** Design §5.1's pseudocode assigns `$selected` (which is `null` when no active tarifa exists), while its type comment and OTS-03 describe `tarif_tarifa|false`. To satisfy `test_no_active_tarifas_yields_false_selection` and the documented `|false` contract, the implementation assigns `$selected !== null ? $selected : false;`. All other resolution branches follow design §5.1 exactly.
2. **`remove_familia()` order:** the `guard_mutating_action()` check runs before the `allow_delete` permission check, so an unauthorized non-POST request is rejected as invalid-token rather than permission-denied. This matches the AD-6 "CSRF first" posture; `remove_articulo()` does the same.
3. **Directive additions beyond the minimum test list:** `test_edit_view_migrates_confirm_and_search_off_bootbox` also asserts the full CSP directive inventory (`x-model`, `x-for`, `x-text`, `x-cloak`, `x-on:input.debounce.300ms`, `:disabled`), and `test_edit_view_scoped_panel_preserves_locked_save_contract` asserts exactly one `name="guardar_precio_tarifa"` (bulk forms actually replaced). These are stronger OTS-02/OTS-06 checks, not a scope change.

No other deviation. Unchanged/locked contracts verified: `extends fbase_controller`, `use TarifarioOpcionalStateTrait;`, bulk `guardar_precios_tarifas` body, `guardar_precio_tarifa`, `opcional_master_state()`, `tarif_buscar_articulo()` (GET JSON), `tarif.toggle_button_group(`, `guardar_precio_tarifa`, `{{ csrf_field() }}`.

---

## 6. Blockers

None. Phases 4–5 (WU-4/WU-5) remain `[ ]` by design — this apply batch was scoped to WU-1..WU-3. Phase 6 (verify: PHPStan, OTS-10 diff audit, browser smoke) is deferred to `sdd-verify`.

Runtime smoke items carried forward from design §10 (not blockers, verify-time): htmx keydown-filter under no-`unsafe-eval`, the exact `htmx:after:swap` detail shape (implementation already falls back to `document.body`), and `allowScriptTags:false` on the secondary views (not touched here).

## 7. Next recommended

`sdd-verify` (Phases 4–5 still pending: WU-4 precios selector, WU-5 list filters; then Phase 6 verify).

---

# Apply batch 2 — Phases 4–5 (WU-4, WU-5)

- **Scope applied**: Phases 4–5 of `tasks.md` (WU-4 `tarif_opcional_precios` selector, WU-5 `tarif_opcionales` filters). Phase 6 (verify) is untouched.
- **Mode**: **Strict TDD** (RED before GREEN).
- **Runner**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- **Baseline entering this batch**: 472 tests / 1457 assertions, 25 warnings, 1 skipped.
- **Delivery**: human-owned. No `git commit`, push, branch or PR was created.

## 8. Files changed (batch 2)

| File | Action | What changed |
|------|--------|--------------|
| `plugins/catalogo_core/View/tarif_opcional_precios.html.twig` | Modified | Added `Macro/Htmx.html.twig` import + `{{ htmx.boot({'allowScriptTags': false}) }}`; converted the tarifa `<select name="codtarifa">` from `onchange="this.form.submit()"` to the htmx-4 body-swap set (`hx-get="{{ fsc.url() }}&id={{ fsc.opcional.id }}"`, `hx-trigger="change"`, `hx-target="body"`, `hx-select="body"`, `hx-swap="outerHTML"`, `hx-push-url="true"`, `hx-boost="true"`). Save form, `guardar_precio_tarifa`, `{{ csrf_field() }}` and `tarif.toggle_button_group(` byte-identical. |
| `plugins/catalogo_core/View/tarif_opcionales.html.twig` | Modified | Added `Macro/Htmx.html.twig` import + scrubbed `htmx.boot()`; converted the `query` / `b_codfamilia` / `b_codtarifa` / `b_solo_activos` filters to `hx-get` + body swap + URL push; added a `type="button"` search fallback with `hx-get` + `hx-include="#input_query"` + `hx-trigger="click"`; added cross-filter Twig `set` fragments so the *other* active filters ride the URL. POST row toggles (`action=toggle_*`), hidden names (`query`, `b_codfamilia`, `b_codtarifa`, `b_solo_activos`, `offset`), `{{ csrf_field() }}`, the new-opcional modal and `tarif.toggle_button_group(` are byte-identical. |
| `plugins/catalogo_core/tests/TarifOpcionalesHtmxContractTest.php` | Modified (extended) | Added `PRECIOS_VIEW` / `LIST_VIEW` constants, `preciosView()` / `listView()` / `openTag()` / `assertHxBodySwap()` helpers, and the three methods `test_precios_selector_uses_hx_get_with_url_push`, `test_list_filters_use_hx_get_with_url_push`, `test_list_and_precios_views_preserve_locked_names_csrf_and_toggles`. |
| `plugins/catalogo_core/openspec/changes/opcionales-tarifa-selector-htmx4/tasks.md` | Modified | Marked tasks 4.1–4.3 and 5.1–5.3 `[x]`. |

Out-of-scope files were not touched: `model/tarif_tarifa_opcional.php`, `model/tarif_opcional_precio.php`, `controller/tarif_configurador_opcionales.php`, `tpvmod`, `ventas_opcionales`, the core `openspec/`. Other dirty paths visible in `git status` are pre-existing WIP from the sibling change `opcionales-por-tarifa` (staged before this batch).

## 9. TDD Cycle Evidence (batch 2)

| Task | Test file | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 4.1 | `tests/TarifOpcionalesHtmxContractTest.php` | Contract (source) | ✅ 472/472 | ✅ Written (`test_precios_selector_uses_hx_get_with_url_push` failed: selector had no `hx-get`) | ✅ Passed | ✅ Selector-tag extraction isolated from the rest of the template | ➖ None needed |
| 4.2 | `View/tarif_opcional_precios.html.twig` | Implementation | ✅ 472/472 | ✅ (4.1 RED) | ✅ Passed | ✅ Absence of `onchange="this.form.submit()"` + locked save-form strings | ➖ None needed |
| 4.3 | full suite | Regression gate | ✅ 472/472 | n/a | ✅ Passed (475/475) | ✅ locked classes green | ➖ None needed |
| 5.1 | `tests/TarifOpcionalesHtmxContractTest.php` | Contract (source) | ✅ 472/472 | ✅ Written (`test_list_filters_use_hx_get_with_url_push` failed: query input had no `hx-get`); the preservation method was green by design (latch) | ✅ Passed | ✅ 4 controls extracted by `name`, plus button `hx-include` | ➖ None needed |
| 5.2 | `View/tarif_opcionales.html.twig` | Implementation | ✅ 472/472 | ✅ (5.1 RED) | ✅ Passed | ✅ Cross-filter URL fragments + checkbox unchecked-value guard | ➖ None needed |
| 5.3 | full suite | Regression gate | ✅ 472/472 | n/a | ✅ Passed (475/475) | ✅ locked classes green | ➖ None needed |

### Test summary (batch 2)

- Tests added: **3** (all in `TarifOpcionalesHtmxContractTest`: 2 migration drivers + 1 locked-contract latch).
- Assertions added: **+60** (1457 → 1517).
- Layers: Contract (source) 3, Integration/E2E 0 (browser body-swap smoke deferred to verify per design §10).

## 10. Work Unit Evidence (batch 2)

### WU-4 — `tarif_opcional_precios` selector hx-get + URL push

| Evidence | Value |
|---|---|
| Focused test command and result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/TarifOpcionalesHtmxContractTest.php` → **OK (11 tests, 108 assertions)** |
| Runtime harness | N/A at apply scope — browser body-swap smoke deferred to verify (design §10). |
| Rollback boundary | Revert `View/tarif_opcional_precios.html.twig` (import/boot + selector attrs) and the three new test methods; the save form and controller are untouched. |

### WU-5 — `tarif_opcionales` filters hx-get + URL push

| Evidence | Value |
|---|---|
| Focused test command and result | same focused run → **OK (11 tests, 108 assertions)**; explicit regression gate → **OK (63 tests, 242 assertions)** |
| Runtime harness | N/A at apply scope — browser filter swap + POST toggle smoke deferred to verify (design §10). |
| Rollback boundary | Revert `View/tarif_opcionales.html.twig` (boot + filter attrs + `set` fragments) and the three new test methods; POST toggles, names and CSRF fields untouched. |

## 11. Exact RED → GREEN evidence (batch 2)

### RED

Command:

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
  plugins/catalogo_core/tests/TarifOpcionalesHtmxContractTest.php
```

Observed:

```
........FF.   11 / 11 (100%)
Tests: 11, Assertions: 67, Failures: 2.
```

Both failures were the expected drivers: the precios `<select name="codtarifa">` still carried `onchange="this.form.submit()"` with no `hx-get`, and the list `query` input had no `hx-get`. The third new method (`test_list_and_precios_views_preserve_locked_names_csrf_and_toggles`) passed at RED by design: it is a characterization latch that guards the locked names/CSRF/toggles while the migration happens, not a driver.

### GREEN

Same focused command after implementation:

```
OK (11 tests, 108 assertions)
```

### Full suite (final)

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
→ Tests: 475, Assertions: 1517, Warnings: 25, Skipped: 1.   (OK, but there were issues!)
```

- Baseline 472 tests / 1457 assertions → final **475 tests / 1517 assertions** (+3 / +60).
- Warnings (25) and skipped (1) are identical to the pre-batch baseline; no new warnings.

### Explicit regression gate

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
  plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php \
  plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php \
  plugins/catalogo_core/tests/TarifOpcionalPreciosControllerTest.php \
  plugins/catalogo_core/tests/TarifConfiguradorOpcionalesTest.php \
  plugins/catalogo_core/tests/TarifarioOpcionalStateTraitTest.php
→ OK (63 tests, 242 assertions)
```

All five regression classes are green and unmodified.

## 12. `hx-trigger` decision for the text filter

**Chosen: `hx-trigger="keydown[key === 'Enter']"` + a `type="button"` search button carrying `hx-get` + `hx-include="#input_query"` + `hx-trigger="click"`.** This mirrors the already-shipped, already-tested pattern in `plugins/tarifario/View/partials/catalogo/toolbar.html.twig:168-200` (asserted by `TarifCatalogoHtmxContractTest::test_toolbar_filters_use_hx_get_with_url_push`).

Rationale: the pilot is the proven contract this change mirrors, and the button fallback covers mouse users regardless of the keydown filter. The design's CSP caveat (design §10, open question 1: htmx may evaluate the trigger filter with `Function`, which the no-`unsafe-eval` CSP could block) is unchanged and remains a **verify-time smoke item**. Documented fallback if smoke observes a CSP block: drop the filter expression and keep the button/`change` trigger only (both already present in the markup, so the fallback is a one-attribute edit in the input).

## 13. Deviations from design (batch 2)

1. **The preservation assertion is a latch, not a RED driver.** `test_list_and_precios_views_preserve_locked_names_csrf_and_toggles` was green before the view edits because every locked string already existed. It is included exactly as tasks.md 5.1 requires; its role is regression protection during the migration, while the two hx-get methods are the strict-TDD drivers.
2. **Search button `type="submit"` → `type="button"`.** Mirrors the tarifario pilot and avoids a native POST fallback when htmx is active; `type` is not a test-locked string.
3. **Added `id="input_query"` to the query input.** Required by the pilot's `hx-include="#input_query"` fallback; not a locked name.
4. **Cross-filter preservation via Twig `set` fragments.** Each control's `hx-get` appends only the *other* active filters, never its own value, so an unchecked `b_solo_activos` cannot inherit a stale `TRUE` from the URL (the trap in the naive append-own-value approach).
5. **`allowScriptTags: false` applied to both secondary views.** Per design §10 open question 4 (uniform body-swap posture); smoke must confirm their preserved jQuery/Bootstrap scripts still behave after a swap.

## 14. Blockers (batch 2)

None. Phase 6 (verify: PHPStan, OTS-10 diff audit, browser smoke) remains pending.

## 15. Next recommended

`sdd-verify` (Phases 4–5 complete; run Phase 6: full plugin suite, `ddev exec composer phpstan`, OTS-10 diff audit, manual smoke checklist, Twig cache hygiene).
