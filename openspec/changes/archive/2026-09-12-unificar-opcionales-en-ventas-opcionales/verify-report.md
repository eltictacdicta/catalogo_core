```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:7cbc3024c9a325efd673d21423235b86346fa20428f7167c54aee023dd13c7c5
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 12/12
scenarios: 24/24
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:e46407f937713a45a0959a50c20c238aff87cd510abcb2c146abdca6844aec37
build_command: ddev exec composer phpstan
build_exit_code: 0
build_output_hash: sha256:0b9439133e886daed09f416d29a5f87f002a1780c9b614256acc883c748ff6c9
```

# Verification Report — `unificar-opcionales-en-ventas-opcionales`

**Change**: `unificar-opcionales-en-ventas-opcionales` (plugin-local SDD, `plugins/catalogo_core/openspec/changes/unificar-opcionales-en-ventas-opcionales/`)
**Delta spec**: `specs/opcionales-management/spec.md` — 12 requirements / 24 scenarios (native `### Requirement:` / `#### Scenario:` count)
**Secondary plugin**: `plugins/tarifario` (retirement/repointing only; separate git repo, branch `master`)
**Mode**: Strict TDD, runner `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
**Verification type**: independent. Every artifact re-read, every command re-executed, every apply claim re-checked against source and git state. No apply claim was trusted.

**Evidence digest definition**: `evidence_revision` is the sha256 of the sorted changed-files listing for **this change** across both plugin repos (37 files; the WU-1..WU-5 target set from `tasks.md`/`apply-progress.md`), recorded at `/tmp/opencode/sdd-verify/changed-files.sorted.txt`:

```text
sha256:7cbc3024c9a325efd673d21423235b86346fa20428f7167c54aee023dd13c7c5  changed-files.sorted.txt (37 lines)
```

`test_output_hash` / `build_output_hash` are the sha256 of the full captured stdout with the volatile `Time:` line removed (same digest convention as the sibling `opcionales-tarifa-selector-htmx4` report; the `build_output_hash` is byte-identical to the sibling value on the same host, confirming the convention). Raw captures and normalized copies live in `/tmp/opencode/sdd-verify/`.

## Completeness

| Metric | Value |
|--------|-------|
| Requirements in delta spec | 12 (OUM-01..OUM-12) |
| Scenarios in delta spec | 24 |
| Tasks in `tasks.md` (Phases 1–6) | 26 |
| Implementation tasks (Phases 1–5) | 21 |
| Implementation tasks complete | 21/21 (`[x]`) |
| Phase 6 checkboxes | 6.1 `[x]`, 6.2 `[ ]` (command green but does not analyze plugin code — see WARNING-1), 6.3 `[x]`, 6.4 `[ ]` OPEN (no authenticated session — see WARNING-2), 6.5 `[x]` annotated |
| New/modified harness files | 13 catalogo_core tests + 2 tarifario tests (all green) |

## Build & Tests Execution

**1. catalogo_core suite (primary)** — `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`

```text
OK, but there were issues!
Tests: 511, Assertions: 1756, Warnings: 25, Skipped: 1.
exit 0
sha256:e46407f937713a45a0959a50c20c238aff87cd510abcb2c146abdca6844aec37
```

Meets the expected target exactly (`>= 511 tests / 1756 assertions`, zero failures). Warnings (25) and skipped (1) are unchanged from the pre-change baseline (475/1517, same 25 warnings / 1 skip) — pre-existing, not introduced by this change; hence "OK, but there were issues!".

**2. tarifario suite** — `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml`

```text
OK, but some tests were skipped!
Tests: 191, Assertions: 705, Skipped: 3.
exit 0
sha256:ed8b4c0467f2f95528342df2716a41f107f857fb9056b72a7e1b9cf2cbad94e9
```

Matches the expected target exactly (191 / 705, Skipped 3), zero failures. Note: the entering baseline was 198/759 — the drop is the relocation of the tarifario opcional behavior coverage into catalogo_core (`TarifOpcionalTabEndpointTest`, 6 methods), as designed by AD-7; no assertion was dropped, it moved with the endpoint.

**3. PHPStan (build gate)** — `ddev exec composer phpstan`

```text
Note: Using configuration file /var/www/html/phpstan.neon.
 188/188 [============================] 100%
 [OK] No errors
exit 0
sha256:0b9439133e886daed09f416d29a5f87f002a1780c9b614256acc883c748ff6c9
```

See WARNING-1: this command is green but `phpstan.neon` declares `paths: [src, tests]` only — it does **not** analyze `plugins/catalogo_core/`, so the run is vacuous for the changed files.

**4. Root Plugins suite (integration signal)** — `ddev exec php vendor/bin/phpunit --testsuite Plugins`

```text
FAILURES!
Tests: 1425, Assertions: 4777, Failures: 10, Warnings: 1, PHPUnit Deprecations: 12, Skipped: 37.
exit 1
sha256:8f83c99dd70f2ec9f69305ac865328b8cd48a5c306c02c0f3545fb9d276e1d3f
```

10 failures, all independently classified as pre-existing and unrelated to this change (see WARNING-3). The task text anticipated "11 pre-existing failures"; the observed count is **10** (7 OidcProvider + 2 `TarifTarifaOpcionalPrecedenceTest` + 1 `LegacySupportTest`). The apply report itself also states 10.

## Root Plugins failure classification (task check 3)

| # | Failure(s) | Plugin / owner | Independent proof | Class |
|---|-----------|----------------|-------------------|-------|
| 1–7 | 7× OidcProvider DB/schema (`OidcSchemaContractTest`, `OidcLegacySchemaParityTest`, `migration011_cliente_gruposTest` ×4, `OidcRegisterControllerMinimalClienteTest`) | `OidcProvider` (untouched) | Not referenced by this change; schema/DB fixture dependent | Pre-existing / unrelated |
| 8–9 | `TarifTarifaOpcionalPrecedenceTest::test_missing_row_without_ext_row_falls_back_to_master_defaults`, `::test_first_explicit_toggle_creates_the_master_row_and_overrides_inherited_activation` | `catalogo_core` — **sibling** file (git status `A  tests/TarifTarifaOpcionalPrecedenceTest.php`), not this change | Ran `--filter TarifTarifaOpcionalPrecedenceTest` alone under the root (non-isolated) config → **exactly the same 2 failures**; ran the same file under `plugins/catalogo_core/phpunit.xml` (`processIsolation="true"`) → `OK (12 tests, 49 assertions)`. Root config has no `processIsolation`; the isolated config does. | Pre-existing `processIsolation` incompatibility |
| 10 | `LegacySupportTest::testPluginVersionIsBumpedTo120ForTheBaseJsMove` (`'1.2.0'` vs actual `'1.3.0'`) | `legacy_support` (untouched) | Version-drift assertion in an unrelated plugin repo | Pre-existing / unrelated |

**None of the four new/modified WU-4/5 files cause any failure.** Each was executed under the root non-isolated suite and passed:

```text
CatalogoOpcionalesHookOwnershipTest  → OK (7 tests, 40 assertions)
TarifOpcionalTabEndpointTest         → OK (6 tests, 38 assertions)
TarifTabPreciosTest                  → OK (8 tests, 50 assertions)
HookRegistrationTest (tarifario)     → OK (7 tests, 27 assertions)
```

Also confirmed: the 2 `TarifTarifaOpcionalPrecedenceTest` failures reproduce **with no new files in play** (filter run of the single file), so they are not seeded by this change.

## Static source audits (independent grep)

| Audit | Result | Raw evidence |
|-------|--------|--------------|
| No `page=tarif_opcionales` page link remains | ✅ | `grep -rn 'page=tarif_opcionales' plugins/{catalogo_core,tarifario} --include='*.php' --include='*.twig' --include='*.js' --exclude-dir=vendor --exclude-dir=openspec` → exit 1, 0 matches |
| Deleted files absent | ✅ | `catalogo_core/controller/tarif_opcionales.php`, `catalogo_core/View/tarif_opcionales.html.twig`, `tarifario/View/Hooks/ventas_opcional_tabs_after.html.twig`, `tarifario/View/Hooks/ventas_opcional_tab_pane_after.html.twig`, `tarifario/View/Hooks/partials/opcional_precios_rows.html.twig` → all absent |
| ARTICLE surface byte-identical | ✅ | `git -C plugins/tarifario diff --stat -- View/Hooks/partials/articulo_precios_rows.html.twig View/Hooks/ventas_articulo_tabs_after.html.twig View/Hooks/ventas_articulo_tab_pane_after.html.twig` → empty |
| `Init.php` registers the 2 opcional hooks behind an idempotency guard | ✅ | `OPCIONAL_HOOK_TEMPLATES` L26-29; `if (self::$hooksRegistered) return;` L147-149; sets flag L155; listener guard `$viewExtensionsRegistered` L122-125; `retireTarifOpcionalesPage()` wired into `upgrade()` L101-105 |
| Endpoint contract | ✅ | `controller/tarif_opcional_tab.php`: `class tarif_opcional_tab extends fbase_controller` L44, `use TarifarioOpcionalStateTrait;` L46, `requireCsrf()` L116, `puede_editar_opcional()` L130/L171, renders `@catalogo_core/Hooks/partials/opcional_precios_rows.html.twig` L101 |
| No bare `fsc.simbolo_divisa()` under `catalogo_core/View/Hooks` | ✅ | `grep -rnE 'fsc\.simbolo_divisa\(\s*\)' plugins/catalogo_core/View/Hooks` → exit 1 |
| Unified list htmx 4 + Alpine CSP hygiene | ✅ | `hx-get`×7, `hx-target="body"`×10, `hx-select="body"`×10, `hx-swap`×10, `hx-push-url="true"`×6, `hx-boost="true"`×6, `hx-post`×5, `Alpine.data(`×3, `alpine:init`×1, `csp_nonce_attr()`×1, `[x-cloak]`×1, `htmx:after:request`×2, `htmx:after:swap`×1; `hx-delete`/`bootbox`/`\|raw`/`innerHTML` → 0, v2 names → 0 |
| Moved tab hygiene | ✅ | `Hooks/**`: `hx-post` + `hx-include` + `Alpine.data(` + `alpine:init` + `csp_nonce_attr()` + `[x-cloak]` + `htmx:after:request`/`htmx:after:swap`; `hx-delete`/`bootbox`/`\|raw`/v2 names → 0 |
| Locked names/CSRF/toggle macro in unified view | ✅ | `name="query"`, `name="b_codfamilia"`, `name="b_codtarifa"`, `name="b_solo_activos"`, `name="offset"` present; `{{ csrf_field() }}`×5; `tarif.toggle_button_group(`×3 |
| Core `openspec/` has no entry for this change/spec | ✅ | `ls openspec/changes/ \| grep -i unificar-opcionales` → none; `ls openspec/specs/ \| grep -i opcionales-management` → none; `grep -rl 'unificar-opcionales-en-ventas-opcionales' openspec/` → exit 1 |

## Test-locked contracts (task check 9)

| Contract class | Result | Runner |
|----------------|--------|--------|
| `VentasOpcionalesControllerTest` | ✅ OK (5 tests, 7 assertions) | catalogo_core isolated |
| `TarifOpcionalesControllerContractTest` (3 survivors) | ✅ OK (5 tests, 21 assertions) | catalogo_core isolated |
| `TarifOpcionalesControllerMasterStateTest` | ✅ OK (17 tests, 96 assertions) | catalogo_core isolated |
| `TarifOpcionalesHtmxContractTest` | ✅ OK (11 tests, 108 assertions) | catalogo_core isolated |
| `TarifOpcionalEditTarifaSelectorTest` | ✅ OK (9 tests, 28 assertions) | catalogo_core isolated |
| `TarifOpcionalPreciosControllerTest` | ✅ OK (4 tests, 7 assertions) | catalogo_core isolated |
| `TarifConfiguradorOpcionalesTest` | ✅ OK (17 tests, 89 assertions) | catalogo_core isolated |
| `OpcionalDomainModelOwnershipTest` | ✅ OK (5 tests, 66 assertions) | catalogo_core isolated |
| `Integration/CatalogoCoreHookMarkersTest` | ✅ OK (11 tests, 67 assertions) | catalogo_core isolated |
| `HookRegistrationTest` (tarifario, article pair) | ✅ OK (7 tests, 27 assertions) | root non-isolated |

## Spec Compliance Matrix (OUM-01..OUM-12)

| # | Requirement | Scenarios | Covering evidence (re-executed, all green) | Result |
|---|-------------|-----------|--------------------------------------------|--------|
| OUM-01 | Canonical unified page + validated selector fallback | 2 | `VentasOpcionalesControllerTest` (file existence, `privateCore`, page-data `name=ventas_opcionales`/`showonmenu`, legacy-wrapper subclass); `CatalogoOpcionalesUnifiedControllerTest::test_tarifa_selector_prefers_requested_then_default_then_first_active`, `::test_no_active_tarifas_yields_null_selection` | ✅ COMPLIANT |
| OUM-02 | Filters (`query`+`search` alias, `b_*`, `offset`) + true filtered pagination | 2 | `CatalogoOpcionalesUnifiedControllerTest::test_filters_query_primary_and_search_alias_are_carried_in_url`, `::test_pagination_uses_count_filtered_true_total_and_preserves_filters`; `CatalogoOpcionalesHtmxContractTest::test_list_filters_use_hx_get_body_swap_and_url_push`, `::test_list_view_preserves_locked_names_csrf_and_toggle_macro` | ✅ COMPLIANT |
| OUM-03 | Per-tarifa master state via `effective()` + selected-tarifa price | 2 | `CatalogoOpcionalesUnifiedControllerTest::test_state_cache_reads_effective_master_without_persistence`, `::test_price_cache_maps_selected_tarifa_price`; `TarifOpcionalesControllerMasterStateTest` (17/96) | ✅ COMPLIANT |
| OUM-04 | CSRF-guarded POST toggles persisting via `set_*` | 2 | `CatalogoOpcionalesUnifiedControllerTest::test_valid_toggle_persists_through_set_accessors`, `::test_missing_or_invalid_csrf_blocks_toggle_and_persists_nothing`; `TarifOpcionalesControllerMasterStateTest::test_list_declares_csrf_guarded_toggle_endpoints`, `::test_list_toggle_redirect_preserves_active_filters`, `::test_list_toggle_forms_carry_the_active_filters` | ✅ COMPLIANT |
| OUM-05 | Creation with code gen, duplicate rejection, normalized per-tarifa prices, CSRF | 2 | `CatalogoOpcionalesUnifiedControllerTest::test_new_opcional_normalizes_prices_and_persists`, `::test_new_opcional_rejects_duplicate_or_bad_price` | ✅ COMPLIANT |
| OUM-06 | Permission-gated delete | 2 | `CatalogoOpcionalesUnifiedControllerTest::test_delete_is_blocked_without_allow_delete` | ✅ COMPLIANT |
| OUM-07 | Excel export parity (exact headers, selected-tarifa price, filter inheritance) | 2 | `CatalogoOpcionalesUnifiedControllerTest::test_export_headers_and_selected_tarifa_price_and_filter_inheritance` | ✅ COMPLIANT |
| OUM-08 | htmx 4 + Alpine CSP on list and moved tab | 3 | `CatalogoOpcionalesHtmxContractTest` (7 methods); `CatalogoOpcionalesHookOwnershipTest::test_moved_tab_pane_boots_htmx_and_alpine`, `::test_moved_tab_mutations_use_hx_post_and_never_hx_delete`, `::test_moved_tab_registers_alpine_data_with_nonce_and_init_guard`, `::test_moved_tab_uses_colon_events_and_no_v2_names`; independent grep audit above | ✅ COMPLIANT |
| OUM-09 | `tarif_opcionales` eliminated + links repointed + idempotent `fs_page` retirement | 2 | `CatalogoOpcionalesUnifiedControllerTest::test_model_url_fallbacks_point_to_ventas_opcionales`, `::test_deleted_page_has_no_controller_or_view`, `::test_catalogo_and_tarifario_views_repoint_to_ventas_opcionales`; `InitUpgradeTest::upgradeRetiresTarifOpcionalesPageIdempotently` (source contract + idempotency regex); deleted files absent; page-link grep 0 matches | ✅ COMPLIANT |
| OUM-10 | catalogo_core owns the opcional tab + endpoint; tarifario article-only/byte-identical | 2 | `CatalogoOpcionalesHookOwnershipTest::test_catalogo_core_registers_opcional_hooks_once_across_rebuilds`, `::test_catalogo_core_renders_opcional_tab_and_pane_without_tarifario`, `::test_tarif_opcional_tab_endpoint_contract_has_csrf_permission_and_no_bare_simbolo_divisa`; `TarifOpcionalTabEndpointTest` (6/38); tarifario `HookRegistrationTest::test_init_registers_the_two_frozen_article_hooks`, `::test_catalogo_host_view_renders_the_articulo_tarifas_tab_when_tarifario_is_active`; article diff empty | ✅ COMPLIANT |
| OUM-11 | Surviving controllers preserved on `fbase_controller` and reachable | 1 | `TarifOpcionalesControllerContractTest` (3 survivors, extends `fbase_controller`, uses trait); `TarifOpcionalEditTarifaSelectorTest` (9/28); `TarifOpcionalPreciosControllerTest` (4/7); `TarifConfiguradorOpcionalesTest` (17/89) | ✅ COMPLIANT |
| OUM-12 | Locked contracts stay green | 2 | `VentasOpcionalesControllerTest` (5/7); `CatalogoCoreHookMarkersTest` (11/67, four frozen markers); `TarifOpcionalesControllerMasterStateTest` (17/96); `TarifOpcionalesHtmxContractTest` (11/108); `TarifOpcionalesControllerContractTest` (5/21, retired slug absent) | ✅ COMPLIANT |

**Compliance summary**: 12/12 requirements, 24/24 scenarios compliant, each backed by a covering test that passed at runtime. No requirement is covered only by apply notes.

## TDD Compliance (Strict TDD active)

| Check | Result | Details |
|-------|--------|---------|
| TDD evidence reported | ✅ | Per-phase "TDD Cycle Evidence" tables in `apply-progress.md` (Phases 1–5), each with RED and GREEN transcripts |
| All tasks have tests | ✅ | Phase 1–5 tasks (21/21) map to `CatalogoOpcionalesUnifiedControllerTest`, `CatalogoOpcionalesHtmxContractTest`, `CatalogoOpcionalesHookOwnershipTest`, `TarifOpcionalTabEndpointTest`, `InitUpgradeTest`, `CatalogoOpcionalesUnifiedControllerTest` (OUM-09), plus the repointed locked contracts |
| RED confirmed (tests exist) | ⚠️ Reported, not independently replayable | The new/modified test files exist with the claimed method counts; `apply-progress.md` records RED for each phase (e.g. `Tests: 12, Errors: 11, Failures: 1 — Trait not found`; `Tests: 7, Failures: 7`; `Tests: 22, Failures: 4`; `Tests: 7, Failures: 4`). Delivery is human-owned (working trees uncommitted, no change commits/branches), so RED→GREEN cannot be replayed from git; RED is apply-attested. |
| GREEN confirmed (tests pass) | ✅ | Every named test file was re-executed independently and passed; full suites green (511/1756, 191/705) |
| Triangulation adequate | ✅ | Multiple distinct methods per requirement; OUM-01 fallback has 3 cases; OUM-08 has 7 list + 4 moved-tab contracts; OUM-09 has 3 behavior/source methods + retirement contract; list contracts extract controls by locked `name`, not whole-file substrings |
| Safety Net for modified files | ✅ | Baseline 475/1517 recorded; final catalogo suite 511/1756; tarifario baseline 198/759 → 191/705 with the delta explained by the designed relocation |
| Assertion quality | ✅ | Behavior tests exercise the trait seams (`opcional_master_state()`/`opcional_precio_model()`/`opcional_model()`) with DB-free doubles; source contracts assert tagged markup and real tokens, no tautologies |

**TDD Compliance**: 6/6 checks present; the RED leg is apply-attested rather than independently replayable (noted, not a blocker).

## Issues Found

**CRITICAL**: None. 0 blockers; 0 critical findings; all 12 requirements and 24 scenarios have passing runtime coverage.

**WARNING**:

1. **OPEN — PHPStan does not analyze the plugin code.** `ddev exec composer phpstan` exits 0 but `phpstan.neon` declares `paths: [src, tests]` only; there is no plugin-local PHPStan config or plugin path in the root config. Therefore Phase 6.2's stated intent ("must pass on the new trait/service/controller/endpoint and all new/updated test files") is **not actually met** by the green run — it is vacuous for the changed files. This is the same gap recorded by the prior `opcionales-tarifa-selector-htmx4` change. Recommended remediation: add `plugins/*/` coverage (or a plugin-local config + Composer script). Non-blocking for the spec.

2. **OPEN — Authenticated browser/DEV-DB smoke not executed** (tasks.md 6.4). The delta spec is a UI/htmx/Alpine capability; compliance is proven by DB-free behavior tests plus source contracts. A live authenticated render exercising (a) `page=ventas_opcionales` selector + per-tarifa state/price, (b) filter body-swap + URL push, (c) toggle/create/delete with CSRF (accept + reject), (d) Excel export headers/filters, (e) `page=tarif_opcionales` 404 + no dangling menu item after `upgrade()`, (f) `ventas_opcional` tab render with tarifario inactive + row save, (g) no CSP/console errors — was **not** run: no authenticated session/fixture was available in this pass. Recorded as an explicit OPEN warning, same deferral as the sibling change. Non-blocking.

3. **Root Plugins suite is red on 10 pre-existing failures** (see classification table). Two are `catalogo_core` files (`TarifTarifaOpcionalPrecedenceTest`) but belong to the sibling `opcionales-por-tarifa` change and reproduce independently of this change under the non-isolated root config. Not introduced here; the root Plugins suite is not a valid gate for this change (it also lacks `processIsolation`).

4. **Canonical-spec reconciliation required at archive.** `plugins/catalogo_core/openspec/specs/opcionales-tarifa-selector/spec.md` still names the retired page `tarif_opcionales` at lines 10, 120, 124 and 141. Per `tasks.md §Dependencies`, that canonical spec MUST be reconciled at **archive time** to drop the `tarif_opcionales` references (keeping `tarif_opcional_precios` + surviving-controllers wording). This change's delta is correctly confined to `opcionales-management`; no apply-time rewrite was performed, so the staleness remains by design. **Archive blocker if not fixed before moving the change.**

5. **Task-text vs implementation naming drift (minor).** `tasks.md` WU-1b references `tests/CatalogoOpcionalesUnifiedControllerTest.php::test_canonical_page_controller_and_wrapper_serve_ventas_opcionales`, but no such method exists by that name. The canonical page + wrapper contract (file existence, `privateCore`, page-data `name=ventas_opcionales`/`showonmenu`, legacy-wrapper subclass) is verified by `VentasOpcionalesControllerTest` (5/7 green) and the trait behavior methods. No functional gap; documentation wording only.

**SUGGESTION**:

1. Wire `plugins/catalogo_core/` into PHPStan (WARNING-1) before the next plugin SDD, so "static analysis green" is meaningful for plugin code.
2. Execute the Phase 6.4 smoke checklist once an authenticated dev session exists, and record the observed selector swap / Alpine / URL-push / CSP-console results in `archive-report.md`.
3. Reconcile `openspec/specs/opcionales-tarifa-selector/spec.md` (WARNING-4) as the first step of archive.
4. Fix the `test_canonical_page_controller_and_wrapper_serve_ventas_opcionales` reference in `tasks.md` (WARNING-5) so tracking text matches the shipped harness.
5. The change is well over the 400-line review budget; the 6-slice chained-PR split in `tasks.md` remains the right delivery boundary. No `vendor/` delta is required (no Composer dependency added).

## Verdict

**PASS WITH WARNINGS** — 12/12 requirements and 24/24 scenarios are backed by passing runtime coverage; the full catalogo_core suite (511 tests / 1756 assertions, Warnings 25 / Skipped 1 unchanged from baseline, exit 0), the tarifario suite (191 / 705, Skipped 3, exit 0), all 10 named test-locked contracts, and the endpoint/ownership/moved-tab contract suites are green; the static source audits (page-link grep 0, deleted files absent, article surface byte-identical, hooks/endpoint/CSRF/permission, htmx/Alpine hygiene, core-`openspec` cleanliness) are clean. The root Plugins suite's 10 failures are independently confirmed pre-existing/unrelated and not caused by the new files. Not fully archive-ready because four non-blocking warnings remain open (PHPStan plugin-coverage gap, unexecuted authenticated smoke, pre-existing root-suite failures, canonical-spec reconciliation). `next_recommended`: **archive after reconciling `opcionales-tarifa-selector/spec.md`** and recording the two open warnings in `archive-report.md`; no remediation work unit is required by the verified artifacts.
