```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:1d7119b07369ac16ec8e4a51653a0e6260454067349f22529289d6b8ffabcf34
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 10/10
scenarios: 16/16
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:4c9fe90e8354df1a36ca5885974e62e3ba5ab120fa308029ae2b4fa58ee4ddcf
build_command: ddev exec composer phpstan
build_exit_code: 0
build_output_hash: sha256:0b9439133e886daed09f416d29a5f87f002a1780c9b614256acc883c748ff6c9
```

# Verification Report — `opcionales-tarifa-selector-htmx4`

**Change**: `opcionales-tarifa-selector-htmx4` (plugin-local SDD, `plugins/catalogo_core/openspec/changes/opcionales-tarifa-selector-htmx4/`)
**Delta spec**: `opcionales-tarifa-selector` — 10 requirements / 16 scenarios (native `### Requirement:` / `#### Scenario:` count)
**Mode**: Strict TDD (`strict_tdd: true`), runner `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
**Verification type**: independent. Every artifact re-read, every command re-executed, every apply claim re-checked against source and git state. No apply claim was trusted.

**Evidence digest definition**: `evidence_revision` is the sha256 of the sorted changed-files listing for **this change** (the six implementation/test files in `design.md §4`), recorded at `/tmp/opencode/verify-htmx4/changed-files.txt`:

```text
plugins/catalogo_core/controller/tarif_opcional_edit.php
plugins/catalogo_core/tests/TarifOpcionalEditTarifaSelectorTest.php
plugins/catalogo_core/tests/TarifOpcionalesHtmxContractTest.php
plugins/catalogo_core/View/tarif_opcional_edit.html.twig
plugins/catalogo_core/View/tarif_opcionales.html.twig
plugins/catalogo_core/View/tarif_opcional_precios.html.twig
```

`test_output_hash` / `build_output_hash` are the sha256 of the full captured stdout with the volatile `Time:` line and the harness `EXIT=` marker removed (same digest convention as the sibling `opcionales-por-tarifa` report; the `build_output_hash` is byte-identical to the sibling value, confirming the convention). Raw captures live in `/tmp/opencode/verify-htmx4/`.

## Completeness

| Metric | Value |
|--------|-------|
| Requirements in delta spec | 10 (OTS-01..OTS-10) |
| Scenarios in delta spec | 16 |
| Implementation tasks (`tasks.md` Phases 1–5) | 19 |
| Implementation tasks complete | 19/19 |
| Unchecked implementation tasks | 0 |
| Phase 6 checkboxes | 6.1 `[x]`, 6.2 `[ ]` (stated intent not satisfiable — see WARNING-1), 6.3 `[x]`, 6.4/6.5 deferred/annotated |
| New tests added | 20 (9 behaviour+contract selector, 11 htmx/Alpine contract) / 136 assertions |

## Build & Tests Execution

**1. Full plugin suite** — `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`

```text
OK, but there were issues!
Tests: 475, Assertions: 1517, Warnings: 25, Skipped: 1.
exit 0
sha256:4c9fe90e8354df1a36ca5885974e62e3ba5ab120fa308029ae2b4fa58ee4ddcf
```

Matches the expected target exactly (475 tests / 1517 assertions / 25 warnings / 1 skipped); baseline was 455/1381. Warnings (25) and skipped (1) are unchanged from the baseline (pre-existing, not introduced by this change) — hence "OK, but there were issues!".

**2. PHPStan** — `ddev exec composer phpstan`

```text
Note: Using configuration file /var/www/html/phpstan.neon.
 188/188 [============================] 100%
 [OK] No errors
exit 0
sha256:0b9439133e886daed09f416d29a5f87f002a1780c9b614256acc883c748ff6c9
```

See WARNING-1: this command is green but its config only scans `src/` and `tests/`, not `plugins/catalogo_core/`.

**3. Regression gate (5 test-locked classes)** — same runner + file paths

```text
Tests: 63, Assertions: 242.
OK (63 tests, 242 assertions)
exit 0
sha256:9a19edcaed4bb44a15566d7aec31235f6ce94a30b9d6ba709873e445f27afc8a
```

**4. New test files** — `TarifOpcionalEditTarifaSelectorTest.php` + `TarifOpcionalesHtmxContractTest.php`

```text
Tests: 20, Assertions: 136.
OK (20 tests, 136 assertions)
exit 0
sha256:a9582f58d9dcafdd8281c12444c1718c587d5c83327a9a4c3a4d76c5284a73bb
```

**Coverage**: ➖ Not available — no coverage tool configured (not a failure).

## Test-locked contracts (check 4)

The five regression classes pass **and** none is modified by this change's working-tree layer:

| Class | Result | `git diff` on the file |
|-------|--------|------------------------|
| `TarifOpcionalesControllerContractTest` | ✅ green (part of 63/242) | empty (untracked-diff) |
| `TarifOpcionalesControllerMasterStateTest` | ✅ green | empty (staged `A ` only — sibling add) |
| `TarifOpcionalPreciosControllerTest` | ✅ green | empty |
| `TarifConfiguradorOpcionalesTest` | ✅ green | empty |
| `TarifarioOpcionalStateTraitTest` | ✅ green | empty (staged `A ` only — sibling add) |

`git -C plugins/catalogo_core diff --name-only -- <the five>` returns empty: this change did not touch them.

## Static source audits (independent grep)

| Audit | Result | Raw evidence |
|-------|--------|--------------|
| No `hx-delete` in the three views | ✅ | `grep -n hx-delete <3 views>` → no matches |
| No v2 names `htmx:afterSwap` / `htmx:afterRequest` | ✅ | `grep -nE 'htmx:afterSwap\|htmx:afterRequest' <3 views>` → no matches |
| htmx 4 colon names present | ✅ | edit view L471/L474 `htmx:after:request`, L495 `htmx:after:swap` |
| No `bootbox` / `onchange="this.form.submit()"` in migrated edit view | ✅ | `grep -nE 'bootbox\|onchange="this.form.submit\(\)"' View/tarif_opcional_edit.html.twig` → no matches |
| No `bootbox` / full-page submit in precios view | ✅ | no matches |
| `Alpine.data(` + `alpine:init` + `{{ csp_nonce_attr() }}` + `__opcionalesAlpineRegistered` | ✅ | edit view counts: 3 / 1 / 1 / 3 (plus `__opcionalesSwapBound` 2, `Alpine.initTree(` 1) |
| `guardar_precio_tarifa` + `{{ csrf_field() }}` + `tarif.toggle_button_group(` in all three views | ✅ | edit 1/1/1, precios 1/1/1, list `{{ csrf_field() }}` 3 + `tarif.toggle_button_group(` 3 (`guardar_precio_tarifa` is not a list-view contract) |
| `extends fbase_controller` + no `HtmxCrudController` in the opcional controllers | ✅ | `tarif_opcionales:39`, `tarif_opcional_edit:39`, `tarif_opcional_precios:36`; `HtmxCrudController` 0 hits in each; `use TarifarioOpcionalStateTrait;` present in all three |
| Cross-check: list view preserves its locked `bootbox` delete (AD-7 out of scope) | ℹ️ | `View/tarif_opcionales.html.twig:18` still `bootbox.confirm(` — intentional per AD-7; OTS-06 does not cover the list view |

## OTS-10 diff audit (check 3)

`git -C plugins/catalogo_core` state: the plugin repo is dirty with the pre-existing **sibling** `opcionales-por-tarifa` slice (staged: `Init.php`, `model/*`, `extras/TarifarioOpcionalStateTrait.php`, controllers, tests) plus an unrelated `absorber-opcionales-tarifa-en-catalogo-core` untracked slice and `Services/*` working-tree edits. This change's layer is the **unstaged/untracked** set. The OTS-10 protected surfaces were exercised against that layer:

| Protected surface | Verdict | Evidence |
|---|---|---|
| `model/tarif_tarifa_opcional.php` | ✅ untouched by this change | `git diff --name-only -- model/tarif_tarifa_opcional.php` empty (file is staged `A ` from sibling, working tree matches index) |
| `model/tarif_opcional_precio.php` | ✅ untouched | absent from all status/diff lists |
| `controller/tarif_configurador_opcionales.php` | ✅ untouched | absent from all status/diff lists |
| `View/tarif_configurador_*` (`_opcionales`, `_tree`) | ✅ untouched | absent from all status/diff lists |
| `tpvmod` | ✅ untouched | not present in the plugin repo; no reference added in this change's diff |
| `ventas_opcionales` | ✅ untouched | not present in the plugin repo; no reference added in this change's diff |
| Core `openspec/` has no entry for this change | ✅ | `ls openspec/changes/` (core) lists no `opcionales-tarifa-selector-htmx4`; `grep -rl 'opcionales-tarifa-selector-htmx4' openspec/` → no match; no match anywhere in the repo outside `plugins/catalogo_core/` |

Independent grep of this change's working-tree view/controller diff for `tpvmod|ventas_opcionales` on added lines → 0 hits.

## Spec Compliance Matrix (OTS-01..OTS-10)

| # | Requirement | Scenarios | Covering evidence (re-executed) | Result |
|---|-------------|-----------|--------------------------------|--------|
| OTS-01 | Selector lists `$this->tarifas` with default marked, drives `tarifa_seleccionada` | 1 | `TarifOpcionalEditTarifaSelectorTest::test_edit_view_exposes_selector_listing_tarifas_with_default_marked` (asserts `for tarifa in fsc.tarifas`, `fsc.tarifa_seleccionada.codtarifa`, `selected`) + `test_selector_resolution_prefers_requested_active_tarifa`; source `View/tarif_opcional_edit.html.twig:36-47`, `controller/tarif_opcional_edit.php:123-151` | ✅ COMPLIANT |
| OTS-02 | Selected tarifa scopes editable panel; compact all-tarifas overview retained | 2 | `test_edit_view_scoped_panel_preserves_locked_save_contract` (exactly one `name="guardar_precio_tarifa"`, `fsc.get_precio_tarifa(fsc.tarifa_seleccionada.codtarifa)`) + `test_edit_view_retains_all_tarifas_overview` (`in fsc.precios_tarifas`); source L276-320 | ✅ COMPLIANT |
| OTS-03 | Unknown/absent `codtarifa` falls back to default / first active, never unscoped | 2 | `test_unknown_codtarifa_falls_back_to_default`, `test_absent_codtarifa_falls_back_to_default`, `test_unknown_codtarifa_without_default_falls_back_to_first_active`, `test_no_active_tarifas_yields_false_selection` — all Reflection-invoke the real private resolver | ✅ COMPLIANT |
| OTS-04 | Selector updates through htmx 4 (`hx-get`/`change`/body target+select/`swap`/`push-url`), no full-page `onchange` | 2 | `test_edit_view_exposes_selector_listing_tarifas_with_default_marked` (asserts all 7 hx attributes) + `test_edit_view_selector_has_no_full_page_submit`; `test_edit_view_mutations_use_hx_post_and_never_hx_delete` | ✅ COMPLIANT |
| OTS-05 | Per-tarifa save stays CSRF-guarded, preserves `guardar_precio_tarifa` + `tarif.toggle_button_group(` | 1 | `test_edit_view_scoped_panel_preserves_locked_save_contract` + `test_edit_controller_keeps_fbase_controller_and_bulk_save` (`requireCsrf()`) + `TarifOpcionalesControllerMasterStateTest` (green, unmodified) | ✅ COMPLIANT |
| OTS-06 | Confirm/delete + article search migrate to Alpine CSP (`Alpine.data()` via nonce'd script behind `alpine:init`), no `bootbox`, idempotent re-init on `htmx:after:swap` | 2 | `test_edit_view_registers_alpine_data_with_nonce_and_init_guard`, `test_edit_view_migrates_confirm_and_search_off_bootbox`, `test_edit_view_autocomplete_reinit_is_idempotent_on_after_swap`, `test_edit_view_boots_htmx_and_alpine_macros`; grep confirms `bootbox`/`onclick="`/`autocomplete({` absent from the edit view | ✅ COMPLIANT |
| OTS-07 | htmx 4 colon names only; v2 names absent | 1 | `test_edit_view_uses_colon_events_and_no_v2_names` (`htmx:after:swap`/`htmx:after:request` present, v2 absent); independent grep confirms | ✅ COMPLIANT |
| OTS-08 | List + precios filters migrate to `hx-get` + URL push, locked names/CSRF/`action=toggle_*` preserved | 2 | `test_precios_selector_uses_hx_get_with_url_push`, `test_list_filters_use_hx_get_with_url_push` (per-control tag extraction), `test_list_and_precios_views_preserve_locked_names_csrf_and_toggles`; `TarifOpcionalesControllerMasterStateTest` green | ✅ COMPLIANT |
| OTS-09 | Test-locked contracts intact: `extends fbase_controller`, bulk `guardar_precios_tarifas` body, master `effective(`/`set_*` reads, locked view strings | 2 | `test_edit_controller_keeps_fbase_controller_and_bulk_save` + full `TarifOpcionalesControllerMasterStateTest` and `TarifarioOpcionalStateTraitTest` (63/242 green, unmodified); controller anchors confirmed by grep (`effective(` 2, `set_activa(`/`set_en_catalogo(`/`set_en_tarifa(` 2 each) | ✅ COMPLIANT |
| OTS-10 | Master model, adapter, configurator, `tpvmod`, `ventas_opcionales` MUST NOT change | 1 | Git diff audit + independent grep (see OTS-10 section) | ✅ COMPLIANT |

**Compliance summary**: 10/10 requirements, 16/16 scenarios compliant, each backed by a covering test that passed at runtime.

## TDD Compliance (Strict TDD active)

| Check | Result | Details |
|-------|--------|---------|
| TDD evidence reported | ✅ | Per-batch "TDD Cycle Evidence" tables in `apply-progress.md` (batch 1: rows 1.1–3.4; batch 2: rows 4.1–5.3) |
| All tasks have tests | ✅ | Every Phase 1–5 task maps to `TarifOpcionalEditTarifaSelectorTest` / `TarifOpcionalesHtmxContractTest` / the locked regression classes |
| RED confirmed (tests exist) | ⚠️ Reported, not independently reproducible | The two new test files exist and contain the claimed 20 methods; `apply-progress.md` records RED (`ERRORS! Tests: 17, Assertions: 21, Errors: 5, Failures: 11.`) and batch-2 RED (`Tests: 11, Assertions: 67, Failures: 2.`). There are no git commits/branches for this change (delivery is human-owned), so RED→GREEN cannot be independently replayed; it is evidenced by apply notes only. |
| GREEN confirmed (tests pass) | ✅ | 20/20 new + 475/475 full suite re-executed independently, exit 0 |
| Triangulation adequate | ✅ | OTS-03 has 5 distinct fallback cases; OTS-08/04 extract each control by its locked `name` rather than whole-file substrings; source assertions are scoped with `methodBody()`/`openTag()` helpers |
| Safety Net for modified files | ✅ | Baseline 455/1381 recorded; full suite re-run confirms +20/+136 and unchanged warnings/skips |
| Assertion quality | ✅ | Behaviour tests invoke the real private resolver via Reflection against real `tarif_opcional_edit`; no tautologies. Contract tests assert tagged control markup, not incidental file-wide substrings. |

**TDD Compliance**: 6/6 checks present; the RED leg is apply-attested rather than independently replayable (noted, not a blocker).

## Issues Found

**CRITICAL**: None. No blocker; 0 critical findings; all 10 requirements and 16 scenarios have passing runtime coverage.

**WARNING**:

1. **OPEN — PHPStan does not analyze the plugin code.** `ddev exec composer phpstan` exits 0 but `phpstan.neon` declares `paths: [src, tests]` only; there is no `plugins/catalogo_core/phpstan.neon`, no plugin Composer script, and no plugin path in the root config. Therefore Phase 6.2's stated intent ("must pass on the new controller code and both new test files") is **not** actually met by the green run — it is vacuous for the changed files. An unsupported direct invocation (`phpstan analyse <plugin files>` with the root config) emits 71 errors (undefined legacy `new_message()`/`new_error_msg()`/`$codtarifa` because `fbase_controller`/trait symbols are not bootstrapped), which are false positives of that unsupported mode, not real defects. Recommended remediation: add `plugins/*/` (or a plugin-local PHPStan config + Composer script) so the verify gate produces real signal. Recorded, non-blocking for the spec.

2. **OPEN — Full authenticated browser/HTTP smoke not executed.** The delta spec is a UI/htmx/Alpine capability; compliance is proven by DB-free behaviour tests plus source contracts. A live authenticated render exercising (a) selector body swap, (b) Alpine confirm/search under the CSP build, (c) `hx-push-url`, and (d) the browser console (no CSP/`unsafe-eval` errors, no duplicate handlers after swap) was **not** run. The app answers unauthenticated (`https://tarifario-reunion.ddev.site/index.php` → HTTP 302), but no authenticated session/credentials or seeded opcional+tarifas fixture was available in this pass, and the design's smoke checklist (tasks.md 6.4, design §10 open questions 1–4) is explicitly deferred. Same deferral as the sibling `opcionales-por-tarifa` verify report. Non-blocking.

3. **INFO — Apply batch-2 deviations were not re-approved by design.** `apply-progress.md §13` records five documented deviations (preservation method is a latch not a RED driver; `type="submit"`→`type="button"`; added `id="input_query"`; cross-filter Twig `set` fragments; `allowScriptTags:false` on secondary views). None breaks a spec scenario; the `hx-trigger="keydown[key === 'Enter']"` CSP behaviour under no-`unsafe-eval` remains a verify-time smoke item folded into WARNING-2.

**SUGGESTION**:

1. Wire the plugin into PHPStan (WARNING-1) before the next plugin SDD, so "static analysis green" is meaningful for plugin code.
2. Execute the Phase 6.4 smoke checklist once an authenticated dev session exists, and record the observed selector swap/Alpine/URL-push/CSP console results in `archive-report.md`.
3. Keep `view-hook`/`opcionales-tarifa-management` reconciliation confined to the sibling `opcionales-por-tarifa` change; this change's delta is `opcionales-tarifa-selector` only (no `ADDED`/`MODIFIED` for `opcionales-tarifa-management`).
4. The change is well over the 400-line review budget (`git diff --stat`: 434 insertions / 135 deletions on 4 files, plus 2 new test files); the 5-slice chained-PR split in `tasks.md` remains the right delivery boundary.

## Verdict

**PASS WITH WARNINGS** — 10/10 requirements and 16/16 scenarios are backed by passing runtime coverage; the full plugin suite (475/475 tests, 1517 assertions, exit 0, warnings/skips unchanged from baseline), the 5-class regression gate (63/242, exit 0), the two new test files (20/136, exit 0) and `ddev exec composer phpstan` (188/188, exit 0) all pass; the static source audits and the OTS-10 protected-surface diff audit are clean; the core `openspec/` has no entry for this change. Not fully archive-ready because two non-blocking warnings remain open: PHPStan does not actually cover plugin code, and the authenticated browser/CSP smoke was not executed. `next_recommended`: **archive** after the human accepts the two open warnings (record them in `archive-report.md`); no remediation work unit is required by the verified artifacts.
