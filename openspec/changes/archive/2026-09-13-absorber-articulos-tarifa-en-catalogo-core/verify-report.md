```yaml
schema: gentle-ai.verify-result/v1
change: absorber-articulos-tarifa-en-catalogo-core
delivery_unit: WU-1
evidence_revision: sha256:cdc7938e27019f1a5b40643390de45d50adec136cd55186fee4b4d21d134393a
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 9/9
scenarios: 19/19
test_command: |-
  ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
  ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:807ec460c00958217d04b6d5d6d9be476d546fd813ff782db200424aa1840d3e
build_command: |-
  ddev exec php -l plugins/catalogo_core/model/tarif_articulo_precio.php
  ddev exec php -l plugins/catalogo_core/controller/tarif_tab_precios.php
  ddev exec php -l plugins/catalogo_core/Init.php
  ddev exec php -l plugins/tarifario/Init.php
build_exit_code: 0
build_output_hash: sha256:daf78f229d7666ba6f5d1052fd4263a5cce7443cad1adfa2442102d8c0c9d9c4
```

# Verify Report — `absorber-articulos-tarifa-en-catalogo-core` (WU-1)

- **Mode**: standard verify (Strict TDD was used during apply; no additional TDD artefact required at verify).
- **Scope**: this report verifies **only the WU-1 slice** (article Tarifas tab + `tarif_articulo_precio` ownership). It does **not** verify the whole WU-1..WU-8 absorption program; WU-2..WU-8 are unstarted follow-ups.
- **Independence**: all commands below were executed by the verify actor; apply claims were not trusted. The apply actor was interrupted after landing the move; verify re-ran every gate.
- **Repository state**: working tree only (no commit/push, human-owned). Evidence collected against the uncommitted catalogo_core + tarifario trees.

## Hash provenance

| Field | Value | How computed |
|---|---|---|
| `evidence_revision` | `sha256:cdc7938e27019f1a5b40643390de45d50adec136cd55186fee4b4d21d134393a` | `sort` of the 28 WU-1 changed/added/deleted paths (both plugin repos) → `sha256sum`. Listing: `/tmp/opencode/wu1-files.txt`. |
| `test_output_hash` | `sha256:807ec460c00958217d04b6d5d6d9be476d546fd813ff782db200424aa1840d3e` | `sha256sum` of the concatenated stdout of the two plugin suite runs. |
| `build_output_hash` | `sha256:daf78f229d7666ba6f5d1052fd4263a5cce7443cad1adfa2442102d8c0c9d9c4` | `sha256sum` of the four `php -l` outputs. |
| root regression hash | `sha256:ab7877b21037004496ab0e8bd36a84864cea83f2b4653fa30ca5d35e01656ccf` | `sha256sum` of the root `Plugins` suite stdout. |

## Completeness table (WU-1)

`tasks.md` checkbox census: **40 checked / 3 unchecked**. The 3 unchecked are Phase 7.1/7.2/7.3 (the atomic commit + cache-clear landing steps), which the change explicitly marks **NOT EXECUTED — delivery is human-owned**; they are not implementation tasks and are annotated as deferred in `tasks.md`.

| Phase | Task | Verify status |
|---|---|---|
| 1 (1.1–1.6) | Ownership tests + atomic model/XML move | ✅ all checked; independently re-verified (see ATT-01) |
| 2 (2.1–2.7) | Endpoint relocation to `fbase_controller` | ✅ all checked; independently re-verified (see ATT-02) |
| 3 (3.1–3.7) | Hook ownership transfer to catalogo_core | ✅ all checked; independently re-verified (see ATT-03) |
| 4 (4.1–4.7) | htmx 4 + Alpine CSP rewrite | ✅ all checked; independently re-verified (see ATT-05) |
| 5 (5.1–5.7) | tarifario removals/repoints + test flip | ✅ all checked; independently re-verified (see ATT-06/07) |
| 6 (6.1–6.6) | Dual-suite gate + grep audit | ✅ all checked; re-executed by verify |
| 7 (7.1–7.3) | Atomic two-repo commit + Twig cache clear | ⏸ intentionally deferred (human-owned); non-blocking WARNING |

## Build & tests — raw evidence

### catalogo_core suite (primary, exit 0)

```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.3.33
Configuration: /var/www/html/plugins/catalogo_core/phpunit.xml

......................WW...................................W...  63 / 554 ( 11%)
...
Time: 01:11.580, Memory: 10.00 MB

OK, but there were issues!
Tests: 554, Assertions: 1972, Warnings: 25, Skipped: 1.
```

- Expected: **554 / 1972 OK**. Observed: **554 tests / 1972 assertions, Warnings: 25, Skipped: 1, 0 failures**. ✅
- Above the 528/1826 regression floor.

### tarifario suite (exit 0)

```
$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.3.33
Configuration: /var/www/html/plugins/tarifario/phpunit.xml

.............................................SS......S.........  63 / 180 ( 35%)
...
Tests: 180, Assertions: 637, Skipped: 3.
```

- Expected: **180 / 637 OK, Skipped 3**. Observed: **180 tests / 637 assertions, Skipped: 3, 0 failures**. ✅

### Build / type-check

No PHPStan configuration exists for `catalogo_core` (no `phpstan.neon`/`phpstan.dist.neon`, no `composer.json`). PHPStan therefore does **not** cover this plugin, so the build step used PHP lint instead (`php -l` on every moved/edited PHP entry point + both `Init.php`):

```
No syntax errors detected in plugins/catalogo_core/model/tarif_articulo_precio.php
No syntax errors detected in plugins/catalogo_core/controller/tarif_tab_precios.php
No syntax errors detected in plugins/catalogo_core/Init.php
No syntax errors detected in plugins/tarifario/Init.php
```

**Gap recorded**: the proposal's success criterion "PHPStan clean" is not enforceable in this slice because no PHPStan config covers either plugin. Non-blocking (the same gap exists on `main`).

## Spec Compliance Matrix

### `articulo-tarifa-tab-management` (ATT-01..ATT-07)

| Req | Scenario | Covering evidence (runtime, passed) | Result |
|---|---|---|---|
| ATT-01 | Model resolves locally, tarifario inactive | `ArticuloTarifaPrecioOwnershipTest::test_model_lives_in_catalogo_core_with_original_fqcn_and_stable_table_name` (requires `plugins/catalogo_core/model/tarif_articulo_precio.php`, `class_exists(FQCN, false)`, `is_subclass_of(fs_model)`, `parent::__construct('tarif_articulo_precios')`; both catalogo_core consumers pin the table name) | ✅ PASS |
| ATT-01 | No dual class / no tarifario path | `::test_tarifario_no_longer_ships_the_model_file` + `::test_catalogo_core_has_zero_tarifario_paths_for_the_moved_surface`; independent grep: exact class defs exist only in catalogo_core (`model/tarif_articulo_precio.php:26`, `controller/tarif_tab_precios.php:49`); tarifario's only `tarif_articulo_precio*` hit is the distinct `class tarif_articulo_precios` (plural listing page) | ✅ PASS |
| ATT-02 | GET rows fragment | `TarifTabPreciosTest::test_rows_fragment_renders_row_tariff_coddivisa` (+ `test_no_bare_simbolo_divisa_calls_in_hook_partials`) | ✅ PASS |
| ATT-02 | CSRF + permission precede persistence | `::test_csrf_failure_rejects_before_model_access`, `::test_permission_gate_dispatches_neutral_event_and_defaults_allow`; source order confirmed `requireCsrf()` (L118) → `puede_editar_articulo()` (L139) → model (L144+) | ✅ PASS |
| ATT-02 | Denied save leaves stored values unchanged | `::test_denied_save_leaves_stored_values_unchanged` (+ `test_deny_blocks_price_save_and_responds_json_error`) | ✅ PASS |
| ATT-03 | catalogo_core registers article pair exactly once | `CatalogoArticuloHookOwnershipTest::test_catalogo_core_registers_article_hooks_once_across_rebuilds`; source: `ARTICULO_HOOK_TEMPLATES` (Init.php:37) iterated under the single `$hooksRegistered` guard (Init.php:167-182) | ✅ PASS |
| ATT-03 | tarifario registers no article hook | `Tests\Tarifario\Integration\HookRegistrationTest::test_tarifario_registers_zero_article_hooks`; `tarifario/Init.php` has no `HOOK_TEMPLATES`/`registerHooks`/`TwigInitEvent` listener (only a doc comment mentioning the event) | ✅ PASS |
| ATT-04 | Saved article injects tab at frozen markers | `CatalogoArticuloHookOwnershipTest::test_catalogo_core_renders_article_tab_and_pane_without_tarifario`, `::test_article_pair_renders_at_frozen_markers_with_tipo_articulo`, `::test_unsaved_article_renders_no_article_tab` | ✅ PASS |
| ATT-04 | Save and delete persist per-tarifa state | `TarifTabPreciosTest::test_allow_saves_price_via_articulo_precio_get_and_save`, `::test_delete_branch_response_rerenders_rows_with_real_context` (asserts `deleteCount === 1` and the deleted price gone from re-rendered HTML) | ✅ PASS |
| ATT-05 | htmx 4 hygiene | `CatalogoArticuloHookOwnershipTest::test_moved_article_tab_mutations_use_hx_post`, `::test_moved_article_tab_uses_colon_events_and_no_v2_names`; source scan: `hx-post` only, no `hx-delete`, no `bootbox`, no `|raw`, `htmx:after:request`/`htmx:after:swap` colon events only, no v2 names | ✅ PASS |
| ATT-05 | Alpine CSP registration + currency | `::test_moved_article_tab_registers_alpine_data_with_nonce_and_x_cloak`, `::test_moved_article_rows_render_each_tariff_currency`; source: `Alpine.data('articuloTabPrecios')` behind `alpine:init`, `<script {{ csp_nonce_attr() }}>`, `[x-cloak]`, rows use `fsc.simbolo_divisa(tarifa.coddivisa)`, zero bare `fsc.simbolo_divisa()` | ✅ PASS |
| ATT-06 | Moved endpoint test passes in catalogo_core | `TarifTabPreciosTest` (10 tests) relocated to `plugins/catalogo_core/tests/`, namespace `Tests\CatalogoCore` | ✅ PASS |
| ATT-06 | Hook ownership assertions flipped | `HookRegistrationTest::test_tarifario_registers_zero_article_hooks` + `CatalogoArticuloHookOwnershipTest` | ✅ PASS |
| ATT-07 | Zero tarifario coupling for moved surface | Independent grep (`--include=*.php --include=*.twig --exclude-dir=vendor --exclude-dir=tests --exclude-dir=openspec`) over catalogo_core: **zero** `plugins/tarifario/` hits; `ArticuloTarifaPrecioOwnershipTest::test_catalogo_core_has_zero_tarifario_paths_for_the_moved_surface`; endpoint defaults allow with zero listeners (`test_permission_gate_dispatches_neutral_event_and_defaults_allow`) | ✅ PASS |
| ATT-07 | RBAC ownership stays in tarifario | `HookRegistrationTest::test_rbac_listener_and_grupo_articulo_stay_in_tarifario`, `::test_permission_listener_is_registered_on_filter_event`; files present: `plugins/tarifario/Services/ArticlePermissionListener.php`, `plugins/tarifario/model/tarif_grupo_articulo.php`; catalogo_core keeps the neutral `Event/ArticlePermissionFilterEvent.php` | ✅ PASS |

### `catalogo-render-hooks` (ADDED + MODIFIED)

| Req | Scenario | Covering evidence (runtime, passed) | Result |
|---|---|---|---|
| Article hook pair registration owned by catalogo_core (ADDED) | catalogo_core registers the pair exactly once | `CatalogoArticuloHookOwnershipTest::test_catalogo_core_registers_article_hooks_once_across_rebuilds` (both names, `@catalogo_core/Hooks/`, rebuild duplicates none) | ✅ PASS |
| Article hook pair registration owned by catalogo_core (ADDED) | tarifario registers zero article hooks | `HookRegistrationTest::test_tarifario_registers_zero_article_hooks` | ✅ PASS |
| Hooks render when a listener is registered (MODIFIED) | Registered fragment appears at the marker, unescaped | `CatalogoCoreHookMarkersTest::test_registered_fragment_renders_unescaped_at_the_host_marker` (+ the other 10 frozen-marker tests) | ✅ PASS |
| Hooks render when a listener is registered (MODIFIED) | Article Tarifas tab injected when owner registers | `CatalogoArticuloHookOwnershipTest::test_catalogo_core_renders_article_tab_and_pane_without_tarifario`, `::test_article_pair_renders_at_frozen_markers_with_tipo_articulo` | ✅ PASS |

### Frozen host markers (ADDED constraint, `catalogo-render-hooks`)

- `CatalogoCoreHookMarkersTest` — **11/11 green** (unchanged file, not in either plugin's `git status`).
- Independent marker check on `plugins/catalogo_core/View/ventas_articulo.html.twig`: `ventas_articulo_tabs_after` at **line 129**, `ventas_articulo_tab_pane_after` at **line 341** — positions preserved.
- `git status --porcelain -- View/ventas_articulo.html.twig View/ventas_opcional.html.twig` → **empty** (both host views byte-unchanged). ✅

## Issues Found

### CRITICAL

None.

### WARNING

1. **Dev-DB authenticated E2E smoke not executed (OPEN, non-blocking).** The following were not run because no authenticated session / DEV-DB fixture was provided to the verify actor:
   - a saved article injects the Tarifas tab in a real `ventas_articulo` render;
   - save persists `precio`/`activo`/`en_tarifa`/`en_catalogo` and a blank price deletes the row against the real DB;
   - standalone catalogo_core (tarifario inactive) creates `tarif_articulo_precios` via `Init::ensureArticuloTarifaTables()`.
   Current coverage for these is source-contract/unit level (`ArticuloTarifaPrecioOwnershipTest::test_catalogo_core_bootstraps_the_article_price_table_standalone`, `TarifTabPreciosTest` with a mocked model). This is the only gap between the specs' integration claims and executable runtime proof. It does not block the WU-1 slice verdict (specs' tests exist and pass), but it should be closed before release.
2. **Phase 7 commit tasks remain unchecked (deferred, human-owned).** `tasks.md` 7.1–7.3 are intentionally not executed by apply/verify. The slice is delivered as a working tree only; the atomic back-to-back landing (catalogo_core commit then tarifario commit, no intermediate deploy) is still pending and is the human's call. Delivered tree is not deployable until both commits land.
3. **PHPStan gap.** No PHPStan config covers `catalogo_core`, so the proposal's "PHPStan clean" criterion is unverifiable in this slice; PHP lint was substituted.

### SUGGESTION

1. **ATT-01 "resolves locally" proof is source-contract, not autoloader-runtime.** `test_model_lives_in_catalogo_core_with_original_fqcn_and_stable_table_name` proves the FQCN is defined by the moved file (`require_once` + `class_exists(..., false)`). The actual framework autoload resolution when tarifario is inactive is only exercised by the open dev-DB smoke (WARNING #1).
2. **Root `Plugins` suite has 10 pre-existing failures** unrelated to this slice (see below). Consider pinning them in the baseline so future slices can assert "zero new failures" mechanically.

## Root regression (`--testsuite Plugins`)

```
$ ddev exec php vendor/bin/phpunit --testsuite Plugins
FAILURES!
Tests: 1456, Assertions: 4918, Failures: 10, Warnings: 1, PHPUnit Deprecations: 12, Skipped: 37.
```

Exit code **1**. The 10 failures are exactly the known pre-existing/unrelated set — **none caused by this slice**:

| # | Test | Plugin | Relation to slice |
|---|---|---|---|
| 1–7 | `OidcRegisterControllerMinimalClienteTest`, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` (×4) | OidcProvider | None (DB/schema/backfill) |
| 8–9 | `TarifTarifaOpcionalPrecedenceTest` (×2) | catalogo_core | Pre-existing, from the already-landed opcionales change; no process isolation |
| 10 | `LegacySupportTest::testPluginVersionIsBumpedTo120ForTheBaseJsMove` | legacy_support | Version drift `1.2.0` → actual `1.3.0` |

## Core `openspec/` isolation

- `openspec/changes/absorber-articulos-tarifa-en-catalogo-core/` → **does not exist** (checked). ✅
- Plugin-local SDD fully contained in `plugins/catalogo_core/openspec/`. ✅

## Verdict

**PASS WITH WARNINGS**

All 9 requirements and all 19 scenarios of the WU-1 delta specs are covered by passing runtime tests; both plugin suites are green at the expected counts; the root `Plugins` suite adds zero new failures; the frozen host markers are byte-unchanged; and the grep audits confirm no dual class and zero `plugins/tarifario/` coupling in catalogo_core production.

This verdict verifies **only the WU-1 slice** (`tarif_articulo_precio` ownership, the relocated `tarif_tab_precios` endpoint, hook ownership transfer, and the htmx 4 + Alpine tab). It is **not** a verification of the full WU-1..WU-8 absorption program; WU-2 through WU-8 remain unimplemented and unverified. The open warnings are non-blocking for the slice but must be tracked (dev-DB smoke + human-owned atomic landing).

```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:3fc087e10cf02169e994c65234d31f797f6b81dbad031e5fe9af29cf6e2f5a1a
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 8/8
scenarios: 15/15
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml ; ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:7b7bdab690c6f71d44897a093f8af8d567485fa1a041901df5c5448e9753d4b4
build_command: ddev exec php -l plugins/catalogo_core/model/tarif_tarifa_articulo.php ; ddev exec php -l plugins/catalogo_core/model/tarif_tarifa_articulo_etiqueta.php ; ddev exec php -l plugins/catalogo_core/model/tarif_articulo_imagen.php ; ddev exec php -l plugins/catalogo_core/model/tarif_articulo_precio.php ; ddev exec php -l plugins/catalogo_core/Init.php ; ddev exec php -l plugins/catalogo_core/Controller/VentasArticulo.php ; ddev exec php -l plugins/tarifario/model/tarif_articulo.php ; ddev exec php -l plugins/tarifario/controller/tarif_tarifas.php ; ddev exec php -l plugins/tarifario/controller/tarif_catalogo_view.php
build_exit_code: 0
build_output_hash: sha256:8af0a458595e01770fa01e6507120322e2921288253b18044fe0268d2c22e852
```

# WU-2 Verification

- **Mode**: standard verify (Strict TDD was used during apply; no additional TDD artefact required at verify).
- **Scope**: this section verifies **only the WU-2 slice** (canonical `ventas_articulo` detail absorbs the tarifario article edit surface; 3 models + 3 XMLs move to catalogo_core; `tarif_articulo_edit` / `tarif_articulo_precios` retired with links repointed; htmx 4 + Alpine CSP view migration). It does **not** verify the whole WU-1..WU-8 absorption program; WU-3..WU-8 remain unimplemented and unverified.
- **Independence**: every command below was executed by the verify actor; apply claims were not trusted. The apply actor's counts (576/2147, 180/637, 1478/10) were independently reproduced.
- **Repository state**: working tree only (no commit/push, human-owned). Evidence collected against the uncommitted catalogo_core + tarifario trees.
- **Validator note**: `gentle-ai sdd-verify-validate` validates a single report envelope whose first non-empty line is the opening fence. Because this WU-2 result is **appended** to the existing WU-1 report (which already carries the file's first envelope), the WU-2 candidate bytes were validated as a standalone envelope before the append.

## Hash provenance

| Field | Value | How computed |
|---|---|---|
| `evidence_revision` | `sha256:3fc087e10cf02169e994c65234d31f797f6b81dbad031e5fe9af29cf6e2f5a1a` | `sort` of the 33 WU-2 changed/added/deleted paths (both plugin repos) → `sha256sum`. Listing: `/tmp/opencode/wu2-verify/wu2-files.sorted.txt`. |
| `test_output_hash` | `sha256:7b7bdab690c6f71d44897a093f8af8d567485fa1a041901df5c5448e9753d4b4` | `sha256sum` of the concatenated stdout of the two plugin suite runs (`catalogo_core.out` + `tarifario.out`). |
| `build_output_hash` | `sha256:8af0a458595e01770fa01e6507120322e2921288253b18044fe0268d2c22e852` | `sha256sum` of the nine `php -l` outputs. |
| root `Plugins` suite hash | `sha256:ac9dd1af9b8321b675b0541ef48883cfb4e911c5f0f8d3d09ae1195df28f0436` | `sha256sum` of the root `--testsuite Plugins` stdout. |

## Completeness table (WU-2)

`tasks.md` WU-2 checkbox census (phases 8–13): **36 checked / 3 unchecked**. The 3 unchecked are Phase 13.1/13.2/13.3 (the atomic two-repo commit + cache-clear landing steps), which the change explicitly annotates **NOT EXECUTED — delivery is human-owned**; they are not implementation tasks.

| Phase | Task | Verify status |
|---|---|---|
| 8 (8.1–8.7) | Ownership tests + atomic model/XML move + `ensureArticuloDetalleTables()` + `fs_page` retirement | ✅ all checked; independently re-verified (see ART-06/ART-07) |
| 9 (9.1–9.9) | `VentasArticulo` edit-surface absorption + htmx fragment responses + POST delete | ✅ all checked; independently re-verified (see ART-01/02/03) |
| 10 (10.1–10.6) | htmx 4 + Alpine rewrite + boot-ownership flip | ✅ all checked; independently re-verified (see ART-04/ART-05); `CatalogoCoreHookMarkersTest` edit disclosed as deviation |
| 11 (11.1–11.8) | Retirement + repoints + `fs_page` retirement wiring | ✅ all checked; independently re-verified (see ART-06) |
| 12 (12.1–12.6) | Dual-suite gate + grep audit + atomicity | ✅ all checked; re-executed by verify |
| 13 (13.1–13.3) | Atomic two-repo commit + Twig cache clear | ⏸ intentionally deferred (human-owned); non-blocking WARNING |

## Build & tests — raw evidence

### catalogo_core suite (exit 0)

```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.3.33
Configuration: /var/www/html/plugins/catalogo_core/phpunit.xml

...
Time: 01:13.971, Memory: 10.00 MB

OK, but there were issues!
Tests: 576, Assertions: 2147, Warnings: 25, Skipped: 1.
```

- Expected: **576 / 2147 OK**. Observed: **576 tests / 2147 assertions, Warnings: 25, Skipped: 1, 0 failures**. ✅
- At/above the WU-2 floor of 554 / 1972. ✅

### tarifario suite (exit 0)

```
$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
...
Time: 00:28.787, Memory: 8.00 MB

OK, but some tests were skipped!
Tests: 180, Assertions: 637, Skipped: 3.
```

- Expected: **180 / 637 OK, Skipped 3**. Observed: **180 tests / 637 assertions, Skipped: 3, 0 failures**. ✅

### Focused WU-2 + regression gate (exit 0)

```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
  tests/ArticuloDetalleCanonicoOwnershipTest.php \
  tests/Controller/VentasArticuloArticleEditAbsorptionTest.php \
  tests/VentasArticuloControllerTest.php \
  tests/Integration/CatalogoCoreHookMarkersTest.php \
  tests/Integration/CatalogoArticuloHookOwnershipTest.php \
  tests/CatalogoOpcionalesUnifiedControllerTest.php
OK (80 tests, 432 assertions)
```

```
$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml tests/Integration/HookRegistrationTest.php
OK (4 tests, 9 assertions)
```

### Build / type-check

No PHPStan configuration covers `catalogo_core` (same gap as WU-1), so the build step used PHP lint on every moved/edited PHP entry point:

```
No syntax errors detected in plugins/catalogo_core/model/tarif_tarifa_articulo.php
No syntax errors detected in plugins/catalogo_core/model/tarif_tarifa_articulo_etiqueta.php
No syntax errors detected in plugins/catalogo_core/model/tarif_articulo_imagen.php
No syntax errors detected in plugins/catalogo_core/model/tarif_articulo_precio.php
No syntax errors detected in plugins/catalogo_core/Init.php
No syntax errors detected in plugins/catalogo_core/Controller/VentasArticulo.php
No syntax errors detected in plugins/tarifario/model/tarif_articulo.php
No syntax errors detected in plugins/tarifario/controller/tarif_tarifas.php
No syntax errors detected in plugins/tarifario/controller/tarif_catalogo_view.php
```

### Independent grep audits (raw)

```
$ grep -rn 'page=tarif_articulo_edit\|page=tarif_articulo_precios' \
    plugins/catalogo_core plugins/tarifario \
    --include='*.php' --include='*.twig' --include='*.js' \
    --exclude-dir=vendor --exclude-dir=openspec
(no output; exit 1 = zero matches)  ✅

$ grep -rn 'plugins/tarifario/\|@tarifario/' plugins/catalogo_core \
    --include='*.php' --include='*.twig' \
    --exclude-dir=vendor --exclude-dir=tests --exclude-dir=openspec
(no output; exit 1 = zero matches)  ✅

$ for f in 6 moved model/XML paths; do test -e catalogo_core/$f; test -e tarifario/$f; done
model/tarif_tarifa_articulo.php            catalogo=Y tarifario=N
model/table/tarif_tarifa_articulo.xml      catalogo=Y tarifario=N
model/tarif_tarifa_articulo_etiqueta.php   catalogo=Y tarifario=N
model/table/tarif_tarifa_articulo_etiqueta.xml  catalogo=Y tarifario=N
model/tarif_articulo_imagen.php            catalogo=Y tarifario=N
model/table/tarif_articulo_imagenes.xml    catalogo=Y tarifario=N
(no file exists in both plugins)  ✅

$ ls plugins/tarifario/Services/ArticlePermissionListener.php plugins/tarifario/model/tarif_grupo_articulo.php
both present  ✅

$ ls -d openspec/changes/absorber-articulos-tarifa-en-catalogo-core
No such file or directory  ✅
```

### View hygiene (independent scan of `View/ventas_articulo.html.twig`)

| Probe | Observed |
|---|---|
| `htmx.boot` / `alpine.boot` | 1 / 1 (host owns boot) |
| Pane `View/Hooks/ventas_articulo_tab_pane_after.html.twig` boot | 0 / 0 (pane does not re-boot) |
| `Alpine.data(` | 5 (`articuloDetalle`, `articuloTabs`, `articuloPrecios`, `articuloImagenes`, `articuloOpcionales`) |
| `alpine:init` / `csp_nonce_attr()` / `[x-cloak]` | present / present (1 nonce'd script) / present |
| `hx-post` / `hx-delete` / `hx-get` | 6 / 0 / 0 |
| `bootbox` / `|raw` / v2 names (`htmx:afterSwap`, `htmx:afterRequest`, …) | 0 / 0 / 0 |
| inline `onclick=` / `onchange=` / `oninput=` | 0 / 0 / 0 |
| Frozen markers | `ventas_articulo_tabs_after` @ L57, `ventas_articulo_tab_pane_after` @ L311, whitespace control + frozen context intact |

## Spec Compliance Matrix — `articulo-detalle-canonico` (ART-01..ART-08)

Authoritative totals: **8 requirements / 15 scenarios**. All 15 scenarios are covered by tests that passed at runtime (`576/2147` full suite; `80/432` focused gate).

| Req | Scenario | Covering evidence (runtime, passed) | Result |
|---|---|---|---|
| ART-01 | Reference change and familia persist | `AbsorptionTest::test_reference_change_uses_the_article_reference_setter_and_rejects_invalid` (asserts `set_referencia` at `articulo.php:670`, rejects invalid, aborts before save), `::test_familia_and_flags_are_assigned_on_save` | ✅ PASS |
| ART-01 | Per-tarifa sync and etiquetas persist | `::test_per_tarifa_sync_uses_tarif_tarifa_articulo_for_the_active_tarifa`, `::test_etiquetas_persist_through_tarif_tarifa_articulo_etiqueta`, `::test_etiqueta_failure_does_not_discard_the_article_save`; source order `syncTarifaArticulo()` (L400) → `saveEtiquetasArticulo()` (L406) after `$art->save()` (L395) | ✅ PASS |
| ART-01 | Images entry point operates on the loaded article | `::test_images_entry_point_is_owned_by_the_canonical_detail`, `::test_image_mutations_stay_scoped_to_the_loaded_article`; ported `loadImagenes`/`uploadImagen`/`deleteImagen`/`destacarImagen` present | ✅ PASS |
| ART-02 | Per-article per-tarifa price/state saves and deletes | WU-2 does **not** duplicate the row/save logic: `::test_price_editing_reuses_the_wu1_tab_endpoint` asserts no `guardar_precio_tab` in the controller and `page=tarif_tab_precios` in the rows partial; behavior runs in the reused WU-1 endpoint `TarifTabPreciosTest::test_allow_saves_price_via_articulo_precio_get_and_save` + `::test_delete_branch_response_rerenders_rows_with_real_context` (green in the full suite) | ✅ PASS |
| ART-02 | CSRF or permission denial persists nothing | `::test_csrf_failure_runs_no_model_factory_or_save` (CSRF asserted before gate; 0 factory/get/save), `::test_mutations_gate_through_the_neutral_permission_event` (denied ⇒ 0 get/save, stored value unchanged); reused endpoint `TarifTabPreciosTest::test_csrf_failure_rejects_before_model_access`, `::test_denied_save_leaves_stored_values_unchanged` | ✅ PASS |
| ART-03 | Add and remove keep the existing opcional contract | `::test_article_opcional_contract_is_preserved` (`articulo_opcionales`, `addOpcionalArticulo`, `catalogo_articulo_opcional`, `#opcionales`, `tab_opcionales.html.twig`) + `VentasArticuloControllerTest::testControllerExposesMultiLanguageAndOptionals` green **unmodified**; `addOpcionalArticulo`/`removeOpcionalArticulo` enforce CSRF (L765/L876) then the neutral gate | ✅ PASS (SHOULD; contract-level) |
| ART-04 | htmx 4 and Alpine hygiene on the migrated view | `::test_view_uses_htmx4_and_alpine_hygiene`, `::test_view_has_no_legacy_jquery_or_bootbox_flows`, `CatalogoArticuloHookOwnershipTest::test_moved_article_tab_mutations_use_hx_post`, `::test_moved_article_tab_uses_colon_events_and_no_v2_names`, `::test_moved_article_tab_registers_alpine_data_with_nonce_and_x_cloak`; independent scan (above) confirms 0 bootbox/`|raw`/`hx-delete`/v2 names | ✅ PASS |
| ART-05 | Tabs and markers survive the migration | `::test_view_preserves_tabs_and_frozen_markers` (`#datos`,`#precios`,`#stock`,`#multiidioma`,`#opcionales` + both frozen markers), `CatalogoCoreHookMarkersTest` (source + render contract, marker names/positions), `CatalogoArticuloHookOwnershipTest::test_article_pair_renders_at_frozen_markers_with_tipo_articulo`; independent grep: markers @ L57/L311 | ✅ PASS |
| ART-06 | Retired controllers and views leave no alias | `ArticuloDetalleCanonicoOwnershipTest::test_retired_edit_surfaces_leave_no_alias` (both plugins); independent file-existence probe: all 4 retired surfaces absent in both plugins; independent slug grep = 0 | ✅ PASS |
| ART-06 | Inbound links repoint to the canonical detail | `AbsorptionTest::test_inbound_links_repoint_to_the_canonical_detail`; independent grep = 0 retired slugs; `tarif_articulo_precio::url()` → `page=ventas_articulo` (null → `ventas_articulos`), `tarif_tarifa_articulo::url()` → `ventas_articulo&ref&codtarifa`, `tarif_articulo::url()`/`url_tarifario()` → `ventas_articulo` | ✅ PASS |
| ART-06 | menu rows retire idempotently | `::test_retired_fs_page_rows_are_deleted_idempotently` (source contract: both methods exposed, wired in `upgrade()`, `get('<slug>')` → gated `->delete()`); **deviation**: source contract, not a live `fs_page` behavior run (see WARNING 4) | ✅ PASS (with WARNING) |
| ART-07 | Zero tarifario paths for the absorbed detail surface | `::test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_detail_surface`, `::test_moved_models_live_only_in_catalogo_core`, `::test_moved_model_xml_schemas_live_in_catalogo_core`; independent grep = 0; 6/6 moved files in exactly one plugin | ✅ PASS |
| ART-07 | RBAC boundary stays in tarifario | `Tarifario\Integration\HookRegistrationTest::test_rbac_listener_and_grupo_articulo_stay_in_tarifario`, `::test_permission_listener_is_registered_on_filter_event`, `::test_tarifario_registers_zero_article_hooks` (4 tests / 9 assertions OK); `ArticlePermissionListener.php` + `tarif_grupo_articulo.php` present in tarifario; catalogo_core keeps the neutral event and `puedeEditarArticulo()` default-allow (0 listeners) | ✅ PASS |
| ART-08 | Canonical controller test stays green | `VentasArticuloControllerTest` runs green in the focused gate with **no edits** (`git status --porcelain -- tests/VentasArticuloControllerTest.php` empty) | ✅ PASS |
| ART-08 | Edit-surface tests travel with their code | `Controller/VentasArticuloArticleEditAbsorptionTest` + `ArticuloDetalleCanonicoOwnershipTest` green (22 tests / 171 assertions reported by apply; reproduced inside the 80/432 gate); no tarifario test asserts a retired slug (grep = 0); catalogo_core suite **576/2147 ≥ 554/1972** | ✅ PASS |

## Root regression (`--testsuite Plugins`)

```
$ ddev exec php vendor/bin/phpunit --testsuite Plugins
FAILURES!
Tests: 1478, Assertions: 5095, Failures: 10, Warnings: 1, PHPUnit Deprecations: 12, Skipped: 37.
```

Exit code **1**. The 10 failures are exactly the known pre-existing/unrelated set — **none caused by WU-2**:

| # | Test | Plugin | Relation to WU-2 |
|---|---|---|---|
| 1–7 | `OidcRegisterControllerMinimalClienteTest`, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` (×4) | OidcProvider | None (DB/schema/backfill); same 7 as the WU-1 baseline |
| 8–9 | `TarifTarifaOpcionalPrecedenceTest` (×2, `:396` size 0 vs 1) | catalogo_core | Pre-existing from the already-landed opcionales change; the test file is untouched by WU-2 |
| 10 | `LegacySupportTest::testPluginVersionIsBumpedTo120ForTheBaseJsMove` | legacy_support | Version drift `1.2.0` → `1.3.0` |

Test count moved 1456 (WU-1 baseline) → **1478** = exactly the **+22** WU-2 tests; failure count stayed at 10 with the identical failure set. Zero new failures. ✅

## Core `openspec/` isolation

- `openspec/changes/absorber-articulos-tarifa-en-catalogo-core/` → **does not exist** (checked). ✅
- Plugin-local SDD fully contained in `plugins/catalogo_core/openspec/`. ✅

## Issues Found

### CRITICAL

None.

### WARNING

1. **`CatalogoCoreHookMarkersTest` was edited (test-infra only), against task 10.6 / AD-W2-9 "unedited".** The disclosed edit (`git diff` verified) chains the theme `Macro/Htmx.html.twig` + `Macro/Alpine.html.twig` sources into the `ArrayLoader` and registers `csrf_token()`, `csp_nonce_attr()`, `csrf_meta()` so the rewritten host view can compile. Every frozen-marker assertion is byte-identical and all 11 tests pass. Deviation from the task's literal "unedited" wording; functionally non-breaking and correctly documented by apply. No spec break (ART-05 still verified).
2. **`hx-confirm` instead of an Alpine `window.confirm`** on the delete button (L318) and image actions — a deviation from AD-W2-3's "Alpine confirm". htmx-native, CSP-safe, preserves the confirm-before-POST behavior, and ART-04 does not mandate the Alpine form (it mandates htmx 4 + Alpine CSP, `hx-post`, no bootbox, no `|raw`). Design deviation only; not a spec break.
3. **Image delete/feature are state-changing GETs without a form-body CSRF token.** `delete_imagen` / `destacar_imagen` read query params and skip `validateFormToken()`; they are gated by `allow_delete` (`delete` only) + the neutral permission gate and scoped to the loaded `referencia` (cross-article images rejected). ART-02's CSRF MUST is scoped to per-tarifa price/state + opcionales — both of which do enforce CSRF — so this is **not** an ART-01/ART-02 spec violation, but it is a genuine CSRF-hardening gap worth closing (POST + token).
4. **Retirement idempotency is a source contract, not a live behavior test.** Task 8.1 asked for `Init::upgrade()` to run twice against a live `fs_page`; the implemented test asserts the guarded `get()` → gated `delete()` source shape instead. Documented reason: a global `fs_page` double leaks from the PHPUnit parent process and fatally collides with the real `model/fs_page.php` (`Cannot declare class fs_page`) — the same constraint the `unificar-opcionales` change hit. The scenario's declared test path passes; the behavior gap is real but bounded.
5. **Authenticated browser / DEV-DB smoke NOT executed (OPEN, non-blocking).** No authenticated session or DEV-DB fixture was provided to the verify actor, so the following are unproven end-to-end: detail edit save (rename/familia/flags), etiquetas persistence, per-tarifa sync writing `tarif_tarifa_articulo`, images list/upload/delete/feature, the injected tab still loading from the host boot, and the two retired `fs_page` rows being deleted on a real `upgrade()`. Coverage is source-contract + DB-free behavior (stubbed seams). This is the only gap between the specs' integration claims and executable runtime proof; close before release.
6. **Phase 13.1–13.3 commit tasks remain unchecked (deferred, human-owned).** The slice is a working tree only; the atomic back-to-back landing (catalogo_core commit **first**, tarifario immediately after, no intermediate deploy) is pending. The delivered tree is not deployable until both commits land.
7. **PHPStan gap.** No PHPStan config covers `catalogo_core`, so the program's "PHPStan clean" criterion is unverifiable in this slice; PHP lint was substituted.

### SUGGESTION

1. **ART-03 is verified at contract level.** `test_article_opcional_contract_is_preserved` asserts the surviving API/source contract rather than exercising a live add→remove round trip; ART-03 is a SHOULD and the underlying flow is unchanged and green, but a behavior test would harden it.
2. **Pin the 10 pre-existing root `Plugins` failures in a baseline** so future WU slices can assert "zero new failures" mechanically instead of by enumerated comparison.
3. **Consider a single boot-ownership source assertion** (host boots, injected panes never boot) as a reusable harness for WU-3..WU-8, since each new pane repeats this contract.

## Verdict

**PASS WITH WARNINGS**

All 8 requirements and all 15 scenarios of the WU-2 `articulo-detalle-canonico` delta are covered by tests that passed at runtime; both plugin suites are green at the expected counts (catalogo_core **576/2147**, tarifario **180/637 Skipped 3**); the root `Plugins` suite adds **zero new failures** (same 10 pre-existing); the 3 moved models + 3 XMLs exist in exactly one plugin with zero `plugins/tarifario/` coupling in catalogo_core production; the retired slugs and files leave no alias or live link; the four frozen markers survive at their names/positions with `CatalogoCoreHookMarkersTest` green; and `VentasArticuloControllerTest` stays green unmodified.

This verdict verifies **only the WU-2 slice** (canonical detail absorption + the 3-model move + htmx/Alpine view migration + edit-surface retirement). It is **not** a verification of the full WU-1..WU-8 absorption program; WU-3 through WU-8 remain unimplemented and unverified. The open warnings are non-blocking for the slice (dev-DB smoke, human-owned landing, design deviations) but must be tracked.

**`next_recommended`**: continue with **WU-3** (the tarifario list/import slice, which depends on WU-2) rather than archiving — the program is explicitly sequenced and WU-1's and WU-2's delivery commits have not yet landed, so archiving now would freeze an unlanded tree. Alternatively, land the WU-1 + WU-2 commits (Phase 13.1–13.3) and close the dev-DB smoke before starting WU-3.


```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:f681f784e26d7a1f22913f7811bfeba417c947e478066ff31bf1959aff8629c3
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 8/8
scenarios: 12/12
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml ; ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:ccbc1e81d9083ceed7f2d46a20cb9506eff0945d0cba6ab3d5298119ab560037
build_command: ddev exec php -l plugins/catalogo_core/extras/VentasArticulosListTrait.php ; ddev exec php -l plugins/catalogo_core/Services/ArticuloTarifaPrecioBatchReader.php ; ddev exec php -l plugins/catalogo_core/Services/ArticuloListActionRegistry.php ; ddev exec php -l plugins/catalogo_core/model/tarif_articulo_precio.php ; ddev exec php -l plugins/catalogo_core/Controller/VentasArticulos.php ; ddev exec php -l plugins/catalogo_core/Init.php ; ddev exec php -l plugins/tarifario/Services/ArticuloListActionHandler.php ; ddev exec php -l plugins/tarifario/Init.php ; ddev exec php -l plugins/tarifario/tests/Controller/TarifArticulosFamiliaImportTest.php ; ddev exec php -l plugins/tarifario/tests/Integration/TarifFamiliaWriteRetirementTest.php
build_exit_code: 0
build_output_hash: sha256:e9ef754d23d56b1e5208aeb64ddece76c6b3e77e8044bb6b3a9f9d323ace9977
```

# WU-3 Verification

- **Mode**: standard verify (Strict TDD was used during apply; no additional TDD artefact required at verify).
- **Scope**: this section verifies **only the WU-3 slice** (the canonical `ventas_articulos` list absorbing the tarifario `tarif_articulos` filters, per-tarifa columns, quick-create and Excel/JSON entry points; `tarif_articulos` retired with links repointed and its `fs_page` row deleted idempotently). It does **not** verify the whole WU-1..WU-8 absorption program; WU-4..WU-8 remain unimplemented and unverified.
- **Independence**: every command below was executed by the verify actor; apply claims were not trusted. The apply actor's counts (601/2338, 180/637, 1502/10) were independently reproduced.
- **Repository state**: working tree only (no commit/push, human-owned). Evidence collected against the uncommitted catalogo_core + tarifario trees.
- **Validator note**: `gentle-ai sdd-verify-validate` validates a single report envelope whose first non-empty line is the opening fence. Because this WU-3 result is **appended** to the existing WU-1/WU-2 report (which already carries the file's first envelope), the WU-3 candidate bytes were validated as a standalone envelope before the append.

## Hash provenance

| Field | Value | How computed |
|---|---|---|
| `evidence_revision` | `sha256:f681f784e26d7a1f22913f7811bfeba417c947e478066ff31bf1959aff8629c3` | `sort` of the 27 WU-3 changed/added/deleted paths (both plugin repos) → `sha256sum`. Listing: `/tmp/opencode/wu3-verify/wu3-files.sorted.txt`. Includes the 2 adjusted tests disclosed under Issues (WARNING 3). |
| `test_output_hash` | `sha256:ccbc1e81d9083ceed7f2d46a20cb9506eff0945d0cba6ab3d5298119ab560037` | `sha256sum` of the concatenated stdout of the two plugin suite runs (`catalogo_core.out` + `tarifario.out`, exit-code markers stripped). |
| `build_output_hash` | `sha256:e9ef754d23d56b1e5208aeb64ddece76c6b3e77e8044bb6b3a9f9d323ace9977` | `sha256sum` of the ten `php -l` outputs. |
| root `Plugins` suite hash | `sha256:57a427673f1990ab16b79f98b253a2b825631b08ea48b01afefefbe29287368e` | `sha256sum` of the root `--testsuite Plugins` stdout. |

## Completeness table (WU-3)

`tasks.md` WU-3 checkbox census (phases 14–19): **37 checked / 3 unchecked**. The 3 unchecked are Phase 19.1/19.2/19.3 (the atomic two-repo commit + Twig cache-clear landing steps), which the change explicitly annotates **NOT EXECUTED — delivery is human-owned**; they are not implementation tasks.

| Phase | Task | Verify status |
|---|---|---|
| 14 (14.1–14.8) | WU-3.A — list trait + filter aliases + one-query batch reader | ✅ all checked; independently re-verified (see ALC-01/ALC-02) |
| 15 (15.1–15.7) | WU-3.B — quick-create prices + neutral action seam + POST delete | ✅ all checked; independently re-verified (see ALC-03/ALC-05) |
| 16 (16.1–16.5) | WU-3.C — htmx 4 + Alpine CSP canonical list rewrite | ✅ all checked; independently re-verified (see ALC-04) |
| 17 (17.1–17.10) | WU-3.D — `tarif_articulos` retirement + repoints + `fs_page` | ✅ all checked; independently re-verified (see ALC-06/ALC-07) |
| 18 (18.1–18.7) | WU-3.E — dual-suite gate + grep audit + frozen markers | ✅ all checked; re-executed by verify |
| 19 (19.1–19.3) | Atomic two-repo commit + Twig cache clear | ⏸ intentionally deferred (human-owned); non-blocking WARNING |

## Build & tests — raw evidence

### catalogo_core suite (primary, exit 0)

```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
PHPUnit 11.5.56 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.3.33
Configuration: /var/www/html/plugins/catalogo_core/phpunit.xml

...
Time: 01:15.773, Memory: 10.00 MB

OK, but there were issues!
Tests: 601, Assertions: 2338, Warnings: 26, Skipped: 1.
```

- Expected: **601 / 2338 OK**. Observed: **601 tests / 2338 assertions, Warnings: 26, Skipped: 1, 0 failures**, exit **0**. ✅
- Above the WU-3 regression floor of 576 / 2147 (ALC-08). ✅

### tarifario suite (exit 0)

```
$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
...
Time: 00:28.062, Memory: 8.00 MB

OK, but some tests were skipped!
Tests: 180, Assertions: 637, Skipped: 3.
```

- Expected: **180 / 637 OK, Skipped 3**. Observed: **180 tests / 637 assertions, Skipped: 3, 0 failures**, exit **0**. ✅

### Focused WU-3 + locked-contract gate (exit 0)

```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
  plugins/catalogo_core/tests/Services/ArticuloTarifaPrecioBatchReaderTest.php \
  plugins/catalogo_core/tests/Controller/VentasArticulosListAbsorptionTest.php \
  plugins/catalogo_core/tests/Integration/CatalogoArticuloListHtmxContractTest.php \
  plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php \
  plugins/catalogo_core/tests/InitUpgradeTest.php \
  plugins/catalogo_core/tests/VentasArticulosControllerTest.php
OK, but there were issues!
Tests: 42, Assertions: 221, Warnings: 1.
```

- The four new WU-3 test files + the updated `InitUpgradeTest` + the **unedited** `VentasArticulosControllerTest` are green (42/221, exit 0). ✅
- `git status --porcelain -- tests/VentasArticulosControllerTest.php` → **empty** (unedited, ALC-08). ✅
- tarifario `tests/Integration/VentasArticulosQuickCreateGateCompositionTest.php` → **empty** git status (unedited); green inside the 180/637 suite (ALC-03/ALC-08). ✅
- `git status --porcelain -- tests/Integration/HookRegistrationTest.php` → `M`, but that edit belongs to the already-landed **WU-1** hook flip (documented in the WU-1 apply-progress), not WU-3. ✅

### Build / type-check

No PHPStan configuration covers `catalogo_core` (same gap as WU-1/WU-2), so the build step used PHP lint on every WU-3 PHP entry point (10 files):

```
No syntax errors detected in plugins/catalogo_core/extras/VentasArticulosListTrait.php
No syntax errors detected in plugins/catalogo_core/Services/ArticuloTarifaPrecioBatchReader.php
No syntax errors detected in plugins/catalogo_core/Services/ArticuloListActionRegistry.php
No syntax errors detected in plugins/catalogo_core/model/tarif_articulo_precio.php
No syntax errors detected in plugins/catalogo_core/Controller/VentasArticulos.php
No syntax errors detected in plugins/catalogo_core/Init.php
No syntax errors detected in plugins/tarifario/Services/ArticuloListActionHandler.php
No syntax errors detected in plugins/tarifario/Init.php
No syntax errors detected in plugins/tarifario/tests/Controller/TarifArticulosFamiliaImportTest.php
No syntax errors detected in plugins/tarifario/tests/Integration/TarifFamiliaWriteRetirementTest.php
```

## Spec Compliance Matrix — `articulo-lista-canonica` (ALC-01..ALC-08)

Authoritative totals: **8 requirements / 12 scenarios**. All 12 scenarios are covered by tests that passed at runtime (`601/2338` full suite; `42/221` focused gate).

| Req | Scenario | Covering evidence (runtime, passed) | Result |
|---|---|---|---|
| ALC-01 | Aliases match | `VentasArticulosListAbsorptionTest::test_query_and_b_codfamilia_alias_search_and_codfamilia` (asserts `query`→`search`, `b_codfamilia`→`codfamilia`, **canonical wins on conflict**), `::test_b_codtarifa_resolves_default_then_first_active`, `::test_b_solo_activos_default_true_and_false_includes_blocked`; source: `extras/VentasArticulosListTrait.php` `codfamilia_filter()`/`list_search_args()`/`solo_activos_filter()`; controller normalizes via `init_list_filters()` before `articulo::search()` | ✅ PASS |
| ALC-02 | Rows render | `ArticuloTarifaPrecioBatchReaderTest::test_single_query_maps_all_referencias` (stub engine records exactly **1** `select()`), `::test_missing_row_defaults_to_0_true_false_false`, `::test_empty_referencias_makes_no_query`; `VentasArticulosListAbsorptionTest::test_per_tarifa_columns_read_the_batch_map`; `model/tarif_articulo_precio.php::all_for_referencias()` issues one `WHERE codtarifa = <var2str> AND referencia IN (<var2str…>)`; `mostrar_precio_tarifa()` prices via `CatalogoCurrencyFormatter::format(…, $tarifa->coddivisa)`. No per-row query on the list path (one `load_articulo_tarifa_columns()` call per page). | ✅ PASS |
| ALC-03 | Prices persist | `VentasArticulosListAbsorptionTest::test_quick_create_persists_fixed_and_percentage_prices` (fixed persists; percentage = `pvp * (1 + p/100)`; empty ⇒ no row; fixed wins); contractual order verified in `Controller/VentasArticulos.php::nuevoArticulo()`: `validateFormToken()` (L446) → `puedeCrearArticulo($ref, $this->tarifa_actual())` (L471) → `$art->save()` (L488) → `persist_quick_create_tarifa_prices()` (L491). `grep` confirms **no** `begin_transaction`/`rollback`/`commit(` in the controller or trait. | ✅ PASS |
| ALC-03 | Denial persists nothing | `::test_quick_create_denied_or_csrf_invalid_persists_nothing`, `::test_quick_create_save_failure_persists_no_prices`; tarifario `Integration/VentasArticulosQuickCreateGateCompositionTest` green **unedited** | ✅ PASS |
| ALC-04 | htmx hygiene | `CatalogoArticuloListHtmxContractTest::test_mutations_are_hx_post_only`, `::test_alpine_and_htmx_hygiene`; independent scan of `View/ventas_articulos.html.twig`: `htmx.boot` 1, `alpine.boot` 1, `Alpine.data(` 2 (`articulosList`, `articuloConfirm`) behind `alpine:init` + `<script {{ csp_nonce_attr() }}>`, `[x-cloak]` present, colon events only (`x-on:click`, `htmx:after:request` ×2, `htmx:after:swap`), **0** `hx-delete`, **0** `bootbox`, **0** `\|raw`, **0** v2 names (`htmx:afterSwap`/`afterRequest`), **0** inline `onclick=`/`onchange=`/`oninput=`. Locked strings `include('header.html.twig')`, `include('footer.html.twig')`, `csrf_field()` present. | ✅ PASS |
| ALC-04 | Filter navigation | `::test_filters_use_hx_get_with_push_url`; independent scan: **8** `hx-get` filter/pagination attributes each paired with `hx-push-url="true"` (only other `hx-get` match is the explanatory comment at L59), targeting `#articulos-list` via `hx-select`/`hx-swap="outerHTML"`; `::test_quick_create_modal_renders_per_tarifa_price_inputs`, `::test_locked_view_strings_are_preserved` | ✅ PASS |
| ALC-05 | Entry points | `VentasArticulosListAbsorptionTest::test_action_entry_points_delegate_through_the_neutral_seam`, `::test_filtered_export_query_preserves_the_new_filters`; `Controller/VentasArticulos.php::processExcelAction()` runs its canonical cases then `ArticuloListActionRegistry::dispatch($action, $this->request, $this->exportState())` (L211); `Services/ArticuloListActionRegistry.php` defines the interface + `register()`/`reset()`/`dispatch()`; tarifario `Services/ArticuloListActionHandler.php` implements it with `ACTIONS = [export_template, export_articulos, import_articulos, import_json, import_json_upload, import_json_process, limpiar_todo, limpiar_articulos, limpiar_opcionales, limpiar_precios_tarifa]`, registered from `tarifario/Init.php::registerArticuloListActionHandler()` behind `class_exists` + a static guard; `TarifArticulosFamiliaImportTest` (9 handler refs) + `TarifFamiliaWriteRetirementTest` (7 handler refs) green. See WARNING 1 for the alias-execution caveat. | ✅ PASS (with WARNING) |
| ALC-06 | No alias | `ArticuloListaCanonicaOwnershipTest::test_retired_list_leaves_no_alias_in_either_plugin`; independent probe: `plugins/tarifario/controller/tarif_articulos.php` and `View/tarif_articulos.html.twig` **do not exist**; both plugins searched for `page=tarif_articulos` → **0 hits** (exit 1) | ✅ PASS |
| ALC-06 | Links repointed | `::test_retired_slug_has_zero_inbound_links`; independent grep: the 7 inbound links now target `ventas_articulos`, with `Macro/TarifarioComponents.html.twig:259` mapping `codtarifa` → `b_codtarifa`; `tarif_articulo::url()`/`url_tarifario()` emit `ventas_articulos` (null) / `ventas_articulo&ref=…` | ✅ PASS |
| ALC-06 | Menu row | `::test_retired_fs_page_row_is_deleted_idempotently`; `InitUpgradeTest::upgradeRetiresTarifArticulosPageIdempotently`; source `catalogo_core/Init.php::retireTarifArticulosPage()` (L466) → `fs_page::get('tarif_articulos')` gated `->delete()`, wired in `upgrade()` (L145) inside its own `try/catch` + `error_log`. **Deviation**: source contract, not a live `fs_page` behavior run (see WARNING 2). | ✅ PASS (with WARNING) |
| ALC-07 | Zero paths + RBAC | `ArticuloListaCanonicaOwnershipTest::test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_list_surface`, `::test_tarif_articulo_and_ext_stay_in_tarifario_for_wu4`; independent grep over catalogo_core production (tests/vendor/openspec excluded) for `plugins/tarifario/\|@tarifario/` → **0 hits**; `model/tarif_articulo.php` + `model/tarif_articulos_ext.php` exist **only** in tarifario (absent from catalogo_core); `plugins/tarifario/Services/ArticlePermissionListener.php` + `plugins/tarifario/model/tarif_grupo_articulo.php` present; tarifario `Integration/HookRegistrationTest` green | ✅ PASS |
| ALC-08 | Locked tests | `VentasArticulosControllerTest` green in the focused gate with **no edits** (empty git status); `VentasArticulosQuickCreateGateCompositionTest` green **unedited**; no tarifario test asserts the retired slug (`page=tarif_articulos` grep = 0; the only `tarif_articulos` strings left are the distinct `tarif_articulos_ext` class and stale comments — see SUGGESTION 1); catalogo_core suite **601/2338 ≥ 576/2147** | ✅ PASS |

## Root regression (`--testsuite Plugins`)

```
$ ddev exec php vendor/bin/phpunit --testsuite Plugins
FAILURES!
Tests: 1502, Assertions: 5279, Failures: 10, Warnings: 1, PHPUnit Deprecations: 12, Skipped: 37.
```

Exit code **1**. The 10 failures are exactly the known pre-existing/unrelated set — **none caused by WU-3**:

| # | Test | Plugin | Relation to WU-3 |
|---|---|---|---|
| 1–7 | `OidcRegisterControllerMinimalClienteTest`, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` (×4) | OidcProvider | None (DB/schema/backfill); same 7 as the WU-1/WU-2 baseline |
| 8–9 | `TarifTarifaOpcionalPrecedenceTest` (×2) | catalogo_core | Pre-existing from the already-landed opcionales change; the test file is untouched by WU-3 |
| 10 | `LegacySupportTest::testPluginVersionIsBumpedTo120ForTheBaseJsMove` | legacy_support | Version drift `1.2.0` → actual `1.3.0` |

Test count moved 1478 (WU-2 baseline) → **1502** (+24 WU-3 suite deltas); failure count stayed at 10 with the identical failure set. Zero new failures. ✅

## Core `openspec/` isolation

- `openspec/changes/absorber-articulos-tarifa-en-catalogo-core/` → **does not exist** (checked). ✅
- Plugin-local SDD fully contained in `plugins/catalogo_core/openspec/`. ✅

## Issues Found

### CRITICAL

None.

### WARNING

1. **ACL-05 filtered-export: the new alias filters are preserved in the export URL but not applied on the canonical export execution path.** `View/partials/articulos/modal_exportar_excel.html.twig:25` builds `action=export_excel_filtered{{ fsc.getExportFilteredQuery() }}`, and `getListQueryParams()` correctly carries `query`/`b_codfamilia`/`b_codtarifa`/`b_solo_activos` (asserted by `test_filtered_export_query_preserves_the_new_filters`). However `Controller/VentasArticulos.php::loadArticulosForExportFiltered()` (L414) reads only the **canonical** raw keys (`search`, `codfamilia`, `codfabricante`, `con_stock`, `bloqueados`) — it never calls `list_search_args()`. The neutral seam has the same gap: `exportState()` (L219) normalizes `codfamilia` through `codfamilia_filter()` but reads `search` raw (`$this->request->query->get('search', '')`), so a `query`-only search is dropped before the handler runs. Consequence: a URL carrying only `query=T`, `b_codfamilia=F` or `b_solo_activos=FALSE` renders a correctly filtered list but exports the unfiltered result set. The ALC-05 scenario is covered at carrier level (its test passes) and WU-7 is the declared unification point, so this is non-blocking **but it is a real behavioral gap** the scenario's wording ("filtered export keeps the filters") does not fully satisfy. Recommend `search` in both `loadArticulosForExportFiltered()` and `exportState()` be sourced from `list_search_args()['search']`. **RESOLVED post-verify**: both methods now use `list_search_args()`; covered by `test_filtered_export_applies_the_resolved_alias_filters`; suite 602/2343 OK.
2. **`fs_page` retirement idempotency is a source contract, not a live behavior test.** `run upgrade()` twice against a live `fs_page` was not exercised; `ArticuloListaCanonicaOwnershipTest::test_retired_fs_page_row_is_deleted_idempotently` + `InitUpgradeTest::upgradeRetiresTarifArticulosPageIdempotently` assert the guarded `get('tarif_articulos')` → gated `->delete()` shape instead. Same global-`fs_page`-double constraint documented in WU-1/WU-2. The scenario's declared test path passes; the behavior gap is bounded but real.
3. **Two catalogo_core tests were adjusted beyond task 17.9's repoint list (behavior-preserving).** `tests/CatalogoOpcionalesUnifiedControllerTest.php` and `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` iterated the now-deleted `plugins/tarifario/View/tarif_articulos.html.twig`; the deleted view was dropped from both loops (independent grep confirms **no** remaining `tarif_articulos.html.twig` reference in either file). Every other assertion is intact. Disclosed by apply; the design's WU-3 file list did not name these two files.
4. **Delete confirmation uses htmx-native `hx-confirm` instead of an Alpine `window.confirm`.** `View/ventas_articulos.html.twig:212` carries `hx-confirm="{{ 'confirm-delete'|trans }}"` on the CSRF-guarded `hx-post` delete button; `articuloConfirm` is still registered behind `alpine:init`. AD-W3-9 calls for an Alpine confirm; htmx-native is CSP-safe and preserves the confirm-before-POST behavior, and ALC-04 does not mandate the Alpine form. Design deviation only; not a spec break.
5. **Authenticated browser / DEV-DB smoke NOT executed (OPEN, non-blocking).** No authenticated session or DEV-DB fixture was provided to the verify actor, so the following are unproven end-to-end: filter navigation with `hx-push-url`, per-tarifa columns reading one query against a real DB, quick-create fixed/percentage persistence, POST delete, `tarif_articulos` resolving nowhere, and the `fs_page` row being retired on a real `upgrade()`. Coverage is source-contract + DB-free behavior (stubbed seams). This is the only gap between the specs' integration claims and executable runtime proof; close before release.
6. **Phase 19.1–19.3 commit tasks remain unchecked (deferred, human-owned).** The slice is a working tree only; the atomic back-to-back landing (catalogo_core commit **first**, tarifario immediately after, no intermediate deploy, then Twig cache clear) is pending. The delivered tree is not deployable until both commits land.
7. **PHPStan gap.** No PHPStan config covers `catalogo_core`, so the program's "PHPStan clean" criterion is unverifiable in this slice; PHP lint was substituted (10 files, exit 0).

### SUGGESTION

1. **Stale comments reference the retired surface.** `plugins/tarifario/tests/Controller/TarifArticulosFamiliaImportTest.php:382` ("Find process_familias_batch in tarif_articulos.php") and `:484` ("anonymous subclass of tarif_articulos") describe the pre-WU-3 file even though the subject is now `Services/ArticuloListActionHandler.php` and the harness extends `ArticuloListActionHandler`. No assertion depends on the retired slug, so ALC-08 holds; clean the comments for grep hygiene.
2. **Pin the 10 pre-existing root `Plugins` failures in a baseline** so future WU slices can assert "zero new failures" mechanically instead of by enumerated comparison (repeated from WU-1/WU-2).
3. **`VentasArticulosListTrait` ships two seams the controller does not use** (`articulo_precio_batch_reader()` is used, but the `tarifa_seleccionada()` accessor named in the design interfaces block is a public property `$tarifa_seleccionada` instead of a method). Non-blocking; note only for API-contract tidiness.
4. **`getListQueryParams()` preserves every raw query key**, including `b_codfamilia`/`query` alongside the canonical `search`/`codfamilia`. That is intentional for ALC-01, but combined with WARNING 1 it means the export URL can carry both an alias and a canonical key; normalizing once at the carrier would remove the ambiguity.

## Verdict

**PASS WITH WARNINGS**

All 8 requirements and all 12 scenarios of the WU-3 `articulo-lista-canonica` delta are covered by tests that passed at runtime; both plugin suites are green at the expected counts (catalogo_core **601/2338 ≥ 576/2147**, tarifario **180/637 Skipped 3**, both exit 0); the root `Plugins` suite adds **zero new failures** (same 10 pre-existing); the absorbed filters/columns/quick-create/POST-delete and the neutral Excel/JSON action seam are implemented and independently grepped; `tarif_articulos` controller + view are deleted with no alias and zero inbound slug links; the `fs_page` retirement and RBAC boundary are in place; and `VentasArticulosControllerTest` + `VentasArticulosQuickCreateGateCompositionTest` stay green unedited.

This verdict verifies **only the WU-3 slice** (canonical list absorption + `tarif_articulos` retirement + htmx/Alpine view migration + the neutral action seam). It is **not** a verification of the full WU-1..WU-8 absorption program; WU-4 through WU-8 remain unimplemented and unverified. The open warnings are non-blocking for the slice (dev-DB smoke, human-owned landing, the filtered-export alias caveat, design deviations) but must be tracked.

**`next_recommended`**: continue with **WU-4** (`tarif_articulo`, `tarif_articulos_ext`, `tarif_descripcion` deletion), or first land the WU-1 + WU-2 + WU-3 atomic commits (Phase 19.1–19.3) and close the dev-DB smoke plus the WARNING 1 export-alias gap.

---

```yaml
schema: gentle-ai.verify-result/v1
change: absorber-articulos-tarifa-en-catalogo-core
delivery_unit: WU-4
evidence_revision: sha256:8e1ea1bb4a07e6ff007c270a2a1a76915925117c5c1fda11fcad0c149498fbfb
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 6/6
scenarios: 12/12
test_command: |-
  ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
  ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:5ec193a95eba838db641c33b682dbe153fffe66e2a0c6acc49621152b03e8802
build_command: |-
  ddev exec php -l <12 WU-4 touched PHP files>   # per-file loop, see Build & Tests Execution
build_exit_code: 0
```

# WU-4 Verification

- **Mode**: standard verify (Strict TDD was used during apply; no additional TDD artefact required at verify).
- **Scope**: this section verifies **only the WU-4 slice** (ownership move of `tarif_articulo` + `tarif_articulos_ext` + `model/table/tarif_articulos.xml` into `catalogo_core`, deletion of the deprecated `tarif_descripcion` wrapper, the six require repoints, the standalone `ensureArticuloExtTables()` bootstrap, and the two pinned-boundary test updates). It is **not** a verification of the whole WU-1..WU-8 absorption program.
- **Independence**: every command below was executed by the verify actor against the current working tree; the apply claims in `apply-progress.md` were not trusted as evidence.
- **Repository state**: working tree only, uncommitted (no commit/push, human-owned Phase 25 landing). The moved `catalogo_core` files are untracked (`??`); the `tarifario` originals are staged deletions (`D`). `evidence_revision` is the sha256 of the sorted WU-4 changed-file manifest (17 entries: 7 catalogo_core paths + 10 tarifario paths; deleted tarifario entries use the pre-delete HEAD blob hash).

## Completeness

| Metric | Value |
|--------|-------|
| WU-4 tasks total (Phases 20–24) | 27 |
| WU-4 tasks complete | 27 |
| WU-4 tasks incomplete | 0 |
| Phase 25 landing tasks (human-owned, out of verify scope) | 3 (deferred) |

All Phase 20–24 checkboxes are `[x]`; the only unchecked items are `25.1`–`25.3` (atomic two-repo commit), explicitly marked `verify: deferred - human-owned landing; NOT part of the WU-4 verification scope` in `tasks.md`. They are not counted as WU-4 incompleteness.

| AME requirement | Implemented | Covered by passing test |
|---|---|---|
| AME-01 — catalogo_core owns `tarif_articulo` + `tarif_articulos_ext` | ✅ | ✅ `ArticuloModelosExtOwnershipTest` (9/9) |
| AME-02 — deprecated `tarif_descripcion` wrapper deleted | ✅ | ✅ `ArticuloModelosExtOwnershipTest` |
| AME-03 — require paths repointed to catalogo_core | ✅ | ✅ ownership grep + tarifario focused suites |
| AME-04 — no half-moved class / no tarifario coupling | ✅ | ✅ exact-path + coupling grep gates |
| AME-05 — model behavior preserved; RBAC stays in tarifario | ✅ | ✅ behavior pair + `HookRegistrationTest` |
| AME-06 — locked contracts + suite baseline | ✅ | ✅ 5 locked files + both plugin suites |

## Build & Tests Execution

**Build / lint**: ✅ Passed (exit 0)

```text
$ ddev exec php -l <each of the 12 WU-4 touched PHP files>
No syntax errors detected in plugins/catalogo_core/model/tarif_articulo.php
No syntax errors detected in plugins/catalogo_core/model/tarif_articulos_ext.php
No syntax errors detected in plugins/catalogo_core/Init.php
No syntax errors detected in plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php
No syntax errors detected in plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php
No syntax errors detected in plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php
No syntax errors detected in plugins/tarifario/controller/tarif_actualizar_precios.php
No syntax errors detected in plugins/tarifario/controller/tarif_catalogo_view.php
No syntax errors detected in plugins/tarifario/Services/ArticuloListActionHandler.php
No syntax errors detected in plugins/tarifario/extras/tarifario_init.php
No syntax errors detected in plugins/tarifario/tests/Model/TarifArticuloFactoryForImportTest.php
No syntax errors detected in plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php
# exit 0 | output sha256:7e5296388bb240e48f96c8c8b1b5df4ceeba9b5d0d31113f40cdc6e37856e4e2
```

**Tests (catalogo_core)**: ✅ 611 passed / 0 failed / 1 skipped (Warnings: 26) — exit **0**

```text
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
OK, but there were issues!
Tests: 611, Assertions: 2422, Warnings: 26, Skipped: 1.
# expected 611 / 2422 OK  →  MATCH (floor 602 / 2343)
# exit 0 | output sha256:5ec193a95eba838db641c33b682dbe153fffe66e2a0c6acc49621152b03e8802
```

**Tests (tarifario)**: ✅ 180 passed / 0 failed / 3 skipped — exit **0**

```text
$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
OK, but some tests were skipped!
Tests: 180, Assertions: 637, Skipped: 3.
# expected 180 / 637 OK, Skipped 3  →  MATCH
# exit 0 | output sha256:5bbe638b6c27b73d10f8007a8e6ae040cd8517ada522c4a408f60c9a2e30ab02
```

**Focused WU-4 gates** (all exit 0):

| Command (config = plugin phpunit.xml) | Result | Output sha256 |
|---|---|---|
| `tests/ArticuloModelosExtOwnershipTest.php` | `OK (9 tests, 79 assertions)` | `eb750535…b69b8db` |
| `ArticuloTarifaPrecioOwnershipTest` + `ArticuloDetalleCanonicoOwnershipTest` + `ArticuloListaCanonicaOwnershipTest` + `Controller/VentasArticuloArticleEditAbsorptionTest` | `OK (33 tests, 234 assertions)` | `b8006524…ea804e22` |
| catalogo_core locked 3: `VentasArticulosControllerTest`, `VentasArticuloControllerTest`, `Integration/CatalogoCoreHookMarkersTest` | `OK (43 tests, 107 assertions)` | `5c2c17b8…f89e819c` |
| tarifario locked 2: `Integration/HookRegistrationTest`, `Integration/VentasArticulosQuickCreateGateCompositionTest` | `OK (6 tests, 16 assertions)` | `8982483f…a2a3b89f` |
| tarifario behavior pair: `Model/TarifArticuloFactoryForImportTest` + `Services/ExcelImportWizardServiceTest` | included in a 4-file run `OK (54 tests, 209 assertions)` → pair = **48 / 193** | `bc090007…6ab0a1fe` |

**Root regression (`--testsuite Plugins`)**: ❌ exit **1** — 1512 tests, **10 failures**, 0 caused by WU-4

```text
$ ddev exec php vendor/bin/phpunit --testsuite Plugins
FAILURES!
Tests: 1512, Assertions: 5365, Failures: 10, Warnings: 1, PHPUnit Deprecations: 12, Skipped: 37.
# exit 1 | output sha256:8f0861556a6580b0baffe96937b0773670ed85cdc2e98ad026476f5b4f733e32
```

The 10 failures are exactly the known pre-existing set (identical tally to the WU-1/WU-2/WU-3 baselines) and none is related to WU-4:

| # | Test | Plugin | Relation to WU-4 |
|---|---|---|---|
| 1 | `OidcRegisterControllerMinimalClienteTest` | OidcProvider | None |
| 2 | `OidcLegacySchemaParityTest` | OidcProvider | None |
| 3 | `OidcSchemaContractTest` | OidcProvider | None |
| 4–7 | `migration011_cliente_gruposTest` (×4) | OidcProvider | None (DB/schema/backfill) |
| 8–9 | `TarifTarifaOpcionalPrecedenceTest` (×2) | catalogo_core | Pre-existing opcionales-domain failures; no `tarif_articulo` surface involved |
| 10 | `LegacySupportTest::testPluginVersionIsBumpedTo120ForTheBaseJsMove` | legacy_support | Version drift `1.2.0` → actual `1.3.0` |

Zero new failures; the WU-4 suites add only green tests (catalogo_core 602→**611**, tarifario stays **180**; root 1502→**1512**).

## Spec Compliance Matrix (AME-01..AME-06)

| Req | Scenario | Covering test (all passed) | Result |
|-----|----------|----------------------------|--------|
| AME-01 | Models and schema resolve locally from catalogo_core | `ArticuloModelosExtOwnershipTest::test_moved_models_live_only_in_catalogo_core`, `::test_tarif_articulo_extends_core_articulo_without_own_table`, `::test_moved_model_xml_schema_lives_only_in_catalogo_core` | ✅ COMPLIANT |
| AME-01 | Standalone catalogo_core ensures the extension table idempotently | `ArticuloModelosExtOwnershipTest::test_catalogo_core_bootstraps_the_article_ext_table_standalone` (source contract: `public static : void`, `is_file` guard, `class_exists($fqcn, false)`, `is_subclass_of(..., \fs_model::class)`, `touchNamespacedModel('familia')` first, `init()` after `ensureArticuloDetalleTables()` in its own `try/catch`, `upgrade()` wired) | ✅ COMPLIANT (runtime double-boot smoke is an OPEN warning) |
| AME-02 | Wrapper gone, canonical description model retained | `ArticuloModelosExtOwnershipTest::test_tarif_descripcion_wrapper_is_deleted_and_canonical_description_remains`; independent grep `class\s+tarif_descripcion\b` in both plugins = 0; `catalogo_core/model/core/articulo_descripcion.php` + `model/table/articulo_descripciones.xml` present; `tarif_descripciones.xml` kept (AD-W4-10) | ✅ COMPLIANT |
| AME-02 | Tarifario boot no longer instantiates the deleted wrapper | `ArticuloModelosExtOwnershipTest::test_tarifario_boot_no_longer_references_the_deleted_wrapper`; independent grep `tarif_descripcion` in `extras/tarifario_init.php` = 0 | ✅ COMPLIANT |
| AME-03 | Tarifario production consumers repoint | `ArticuloModelosExtOwnershipTest::test_zero_tarifario_model_requires_for_the_moved_models`; independent grep of the 3 production files: `controller/tarif_actualizar_precios.php:22`, `controller/tarif_catalogo_view.php:34`, `Services/ArticuloListActionHandler.php:31` require the catalogo_core paths; zero `plugins/tarifario/model/tarif_articulo.php` / `tarif_articulos_ext.php` requires remain | ✅ COMPLIANT |
| AME-03 | Tests and `tarifario_init.php` repoint with suites green | Same gate (five expected repoints incl. `TarifArticuloFactoryForImportTest:46,48` and `ExcelImportWizardServiceTest:62,64`; FQCN `use` unchanged) + tarifario `TarifArticuloFactoryForImportTest`/`ExcelImportWizardServiceTest` = **48 tests / 193 assertions OK** | ✅ COMPLIANT |
| AME-04 | No dual class after the move | `ArticuloModelosExtOwnershipTest::test_moved_models_live_only_in_catalogo_core`, `::test_moved_model_xml_schema_lives_only_in_catalogo_core`; `find plugins -name tarif_articulos.xml` → only `plugins/catalogo_core/model/table/tarif_articulos.xml` | ✅ COMPLIANT |
| AME-04 | Zero tarifario references in catalogo_core production | `ArticuloModelosExtOwnershipTest::test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_models`; independent grep of `plugins/catalogo_core` (tests/vendor/openspec excluded) for `plugins/tarifario/` and `@tarifario/` = **0 hits** | ✅ COMPLIANT |
| AME-05 | Import factory behavior preserved after the move | `TarifArticuloFactoryForImportTest` + `ExcelImportWizardServiceTest` green with repointed requires and unchanged assertions; source contract pins `factory_for_import()` (`tarif_articulo.php:611`) + `tarif_articulos_ext` `get/exists/save/delete/update_field/get_extra_fields` on `tarif_articulos` | ✅ COMPLIANT |
| AME-05 | RBAC boundary stays in tarifario | tarifario `Integration/HookRegistrationTest` green unedited (WU-4); `plugins/tarifario/Services/ArticlePermissionListener.php` + `model/tarif_grupo_articulo.php` + `tarif_grupo_articulos/roles/tarifas/usuarios.xml` present; listener registered at boot on `$filterEventClass::NAME` (`tarifario/Init.php:115-120`); no catalogo_core RBAC consumer | ✅ COMPLIANT |
| AME-06 | Locked contracts stay green | catalogo_core locked 3 = **43 tests OK**; tarifario locked 2 = **6 tests OK**; independent review of working-tree deltas confirms the `CatalogoCoreHookMarkersTest` and `HookRegistrationTest` diffs are pre-existing WU-1/WU-2 working-tree changes (host boot ownership / zero article hooks), not WU-4 weakening | ✅ COMPLIANT |
| AME-06 | WU-3 boundary assertion updated and suites at baseline | `ArticuloListaCanonicaOwnershipTest::test_tarif_articulo_and_ext_are_owned_by_catalogo_core_after_wu4` (inverted; RBAC guard kept) + `Controller/VentasArticuloArticleEditAbsorptionTest.php:935` reads the catalogo_core path; catalogo_core **611/2422 ≥ 602/2343**, tarifario **180/637** | ✅ COMPLIANT |

**Compliance summary**: 12/12 scenarios compliant (all covered by tests that passed at runtime).

## Correctness (Static Evidence)

| Requirement | Status | Notes |
|---|---|---|
| AME-01 FQCN / extends / table byte-stability | ✅ Verified | `catalogo_core/model/tarif_articulo.php` keeps `namespace FSFramework\model;` + `class tarif_articulo extends \FSFramework\model\articulo` with no own table; `tarif_articulos_ext` keeps `parent::__construct('tarif_articulos')`; sha256 of `tarif_articulos_ext.php` and `tarif_articulos.xml` are **identical** to the tarifario HEAD blob. |
| AME-02 wrapper removal | ✅ Verified | Class file absent from both plugins; `tarifario_init.php` has zero `tarif_descripcion` references; canonical `articulo_descripcion` retained. |
| AME-03 requires | ✅ Verified | All six hardcoded requires target `plugins/catalogo_core/model/…`; `use FSFramework\model\…` imports unchanged; `tarifario_init.php:123-124` keeps the idempotent `tarif_articulos_ext` `class_exists` no-op. |
| AME-04 no-half-moved | ✅ Verified | Exact-path gate + `class\s+tarif_articulo\b` / `class\s+tarif_articulos_ext\b` / `parent::__construct('tarif_articulos')` anchors; prefix siblings excluded. |
| AME-05 behavior | ✅ Verified | Public method surface present on both moved models; RBAC listener/tables in tarifario. |
| AME-06 baseline | ✅ Verified | catalogo_core 611/2422, tarifario 180/637, locked contracts green. |

## Coherence (Design)

| Decision | Followed? | Notes |
|---|---|---|
| AD-W4-1 exact model moves (verbatim, FQCN/table stable) | ✅ Yes | `tarif_articulos_ext.php` + `tarif_articulos.xml` are byte-identical to tarifario HEAD; `tarif_articulo.php` differs from HEAD by **exactly** the documented WU-2 `url()`/`url_tarifario()` repoint (`page=ventas_articulo` / `ventas_articulos`), i.e. it matches the pre-move working tree. |
| AD-W4-2 wrapper deletion (only PHP, not the XML) | ✅ Yes | Wrapper deleted; `tarif_descripciones.xml` kept (AD-W4-10). |
| AD-W4-3 six require repoints, FQCN unchanged | ✅ Yes | Confirmed by grep. |
| AD-W4-4/AD-W4-9 `ensureArticuloExtTables()` shape + FK-safe order + frozen bodies | ✅ Yes | Source matches the design interface block; `touchNamespacedModel('familia')` precedes the ext instantiation; `init()`/`upgrade()` wired after `ensureArticuloDetalleTables()`; frozen bodies contain no `tarif_articulos_ext`. |
| AD-W4-5 pinned-test inversion | ✅ Yes | Method renamed/inverted; docblock updated; `:935` path fixed; `url()` assertions untouched. |
| AD-W4-6 grep-gate precision | ✅ Yes | Exact paths + class tokens; prefix-sibling exclusion list present; bare `tarif_articulo` never used as the anchor. |
| AD-W4-7 atomic landing | ⏳ Deferred | Working tree only; Phase 25 (catalogo_core first, tarifario immediately after) is human-owned and unexecuted. |
| AD-W4-8 RBAC stays in tarifario | ✅ Yes | Listener + `tarif_grupo_articulo` + role tables in tarifario; registered at boot. |
| AD-W4-10 `tarif_descripciones.xml` out of scope | ✅ Yes | XML present; flagged for the WU-8 audit. |
| AD-W4-11 test ownership + locked contracts | ✅ Yes | New test in catalogo_core; five locked files green; the two boundary tests updated. |

## Issues Found

### CRITICAL

None.

### WARNING

1. **Authenticated browser / DEV-DB smoke NOT executed (OPEN, non-blocking).** No authenticated session or DEV-DB fixture was available to the verify actor, so the following are unproven end-to-end: (a) a standalone catalogo_core boot (tarifario inactive) creating `tarif_articulos` idempotently on first run and no-op on rerun via `Init::ensureArticuloExtTables()`; (b) `tarif_articulo::factory_for_import()` persisting against the moved classes with a real DB; (c) absence of a runtime half-moved class. AME-01 scenario 2 is covered at source-contract level (the spec's declared test path `ArticuloModelosExtOwnershipTest.php`, green), and the tarifario behavior pair exercises `factory_for_import()` DB-free with stubs, but the DB-backed boot idempotency remains a real (bounded) gap. Close before release.
2. **`git rm -f` was used to delete `plugins/tarifario/model/tarif_articulo.php`.** The file carried the uncommitted WU-2 `url()` repoint, so plain `git rm` refused. The apply used copy-then-remove: the byte-identical copy into catalogo_core (sha256 `abf9feb6…`) was landed first and only then the force-removal. Independent verification confirms no byte was lost (the catalogo copy equals the tarifario HEAD blob + exactly the WU-2 delta) and the deletion is a staged `D`. Non-blocking, but the forced removal is a destructive-operation flag worth knowing during the human landing/revert (AD-W4-7 rollback must restore from the two commits, not from a working-tree copy).
3. **Phase 25.1–25.3 remains unchecked (deferred, human-owned).** The slice is a working tree only; the atomic back-to-back landing (catalogo_core commit **first**, tarifario immediately after, no intermediate deploy, then Twig cache clear) is pending. The delivered tree is not deployable until both commits land.

### SUGGESTION

1. **Guard-test invariant (not a RED driver).** `ArticuloModelosExtOwnershipTest::test_moved_paths_are_exact_and_do_not_swallow_prefix_siblings` and `::test_catalogo_core_has_zero_tarifario_paths_for_the_absorbed_models` assert invariants that already held before the move (the WU-1..WU-3 tree was coupling-free). They are the AD-W4-6 machine check and should stay green, but they did not carry the RED signal — the RED drivers were the missing-catalogo_core-path / present-tarifario-twin / present-wrapper / live-require assertions. Worth recording so future WU slices do not mistake an invariant guard for move evidence.
2. **Two "locked" test files carry pre-existing WU-1/WU-2 working-tree deltas.** `Integration/CatalogoCoreHookMarkersTest.php` (host owns htmx/Alpine boot) and tarifario `Integration/HookRegistrationTest.php` (zero article hooks) are `M` relative to HEAD, but the diffs are WU-1/WU-2 domain. WU-4 did **not** weaken any locked assertion; once the atomic commits land, the WU-4 boundary will be provable mechanically.
3. **Pin the 10 pre-existing root `Plugins` failures in a baseline** (repeated from WU-1/WU-2/WU-3) so future WU slices assert "zero new failures" mechanically instead of by enumerated comparison.
4. **`tarif_descripciones.xml` dead-table removal still pending** — deliberately out of WU-4 scope (AD-W4-10); track it for the WU-8 audit.
5. **`tarif_articulo` method overlap cleanup** (`search_tarifario`, `get_imagenes`, `get_opcionales`, per-tarifa price helpers) is retained byte-stable; whether to fold or retire belongs to the WU-8 audit.

## Core `openspec/` isolation

- `openspec/changes/absorber-articulos-tarifa-en-catalogo-core/` in the **core** → **does not exist** (checked). ✅
- `find openspec -iname '*absorber-articulos*'` → no hits. ✅
- Plugin-local SDD fully contained in `plugins/catalogo_core/openspec/`. ✅

## Verdict

**PASS WITH WARNINGS**

All 6 requirements and all 12 scenarios of the WU-4 `articulo-modelos-ext` delta are covered by tests that passed at runtime; the catalogo_core suite is green at **611/2422 ≥ 602/2343** and the tarifario suite at **180/637, Skipped 3** (both exit 0, matching the expected counts); the root `Plugins` suite adds **zero new failures** (same 10 pre-existing, none WU-4-related); the moved files are byte-stable apart from the documented WU-2 `url()` delta; `tarif_descripcion` is deleted with its boot block removed; the six requires repoint to catalogo_core; the exact-path/class-token grep gate finds no half-moved class and zero `plugins/tarifario/` coupling in catalogo_core production; the standalone `ensureArticuloExtTables()` bootstrap is present, FK-safe, wired and does not touch the frozen WU-1/WU-2 bodies; the RBAC boundary stays in tarifario; and the five locked contracts are green with the two WU-3 boundary tests updated coherently.

This verdict verifies **only the WU-4 slice** (article extension model ownership move + `tarif_descripcion` deletion + require repoints + standalone bootstrap + pinned-test updates). It is **not** a verification of the full WU-1..WU-8 absorption program; WU-5 through WU-8 remain unimplemented and unverified. The open warnings are non-blocking for the slice (unavailable DEV-DB boot smoke, the `git rm -f` landing note, the human-owned Phase 25 commits, and the guard-test invariant) but must be tracked.

**`next_recommended`**: land the WU-4 atomic commits (Phase 25.1–25.3: catalogo_core first, tarifario immediately after, then clear the Twig cache) and close the standalone `ensureArticuloExtTables()` DEV-DB boot smoke; then proceed to **WU-5** (catalog manager ownership).

---

```yaml
schema: gentle-ai.verify-result/v1
change: absorber-articulos-tarifa-en-catalogo-core
delivery_unit: WU-5
evidence_revision: sha256:5610c9f31350528a36aa76443105a9d57b0d286b8d4ef41e57935e287ed64eb2
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 8/8
scenarios: 16/16
test_command: |-
  ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
  ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:3320b1daa434180db95dda28a6dfca47833ee7aaa1a8e889ad25bfc393aed36c
build_command: |-
  ddev exec php -l <18 WU-5 touched PHP files>   # per-file loop, see Build & Tests Execution
build_exit_code: 0
```

# WU-5 Verification

- **Mode**: standard verify (Strict TDD was used during apply; no additional TDD artefact is required at verify).
- **Scope**: this section verifies **only the WU-5 slice** — the ownership move of the five `tarif_catalogo*` models/XMLs, the reclass of `tarif_catalogo_view` into the catalogo_core-owned `ventas_catalogo` page, the views/partials/JS move with htmx 4 + Alpine hygiene, the soft RBAC seam, the standalone `ensureCatalogoTables()` bootstrap, the `tarif_catalogo_view` `fs_page` retirement, and the test-ownership move/repoints. It is **not** a verification of the whole WU-1..WU-8 absorption program.
- **Independence**: every command below was executed by the verify actor against the current working tree; the apply claims in `apply-progress.md` and the checked boxes in `tasks.md` were **not** trusted as evidence. Counts, hashes, byte comparisons and greps are reproduced here.
- **Repository state**: working tree only, uncommitted (no commit/push; human-owned Phase 32 landing). `plugins/catalogo_core` and `plugins/tarifario` are independent git repos; the moved catalogo_core assets are untracked (`??`), the tarifario originals are staged deletions (`D`). `evidence_revision` is the sha256 of the sorted 87-entry WU-5 changed-file manifest (46 catalogo_core paths + 41 tarifario paths), listing persisted at `/tmp/opencode/wu5-verify/wu5-files.txt`.

## Completeness

| Phase | Tasks | Status | Notes |
|---|---|---|---|
| 26 — WU-5.A ownership + 5-model/XML move (CAT-01) | 26.1–26.5 | ✅ 5/5 | 10 files byte-identical to the tarifario HEAD blobs; `php -l` clean |
| 27 — WU-5.B canonical page reclass + wrapper + endpoint map (CAT-02/CAT-03) | 27.1–27.8 | ✅ 8/8 | `VentasCatalogo extends PageController`, wrapper, trait, `isCsrfValid()` shim |
| 28 — WU-5.C views/partials/JS + htmx/Alpine hygiene (CAT-03/CAT-04/CAT-05) | 28.1–28.9 | ✅ 9/9 | 4 renamed views + 12 partials + 9 JS; hygiene contract green |
| 29 — WU-5.D soft seam + bootstrap + slug retirement (CAT-01/CAT-02/CAT-05/CAT-06) | 29.1–29.8 | ✅ 8/8 | `ensureCatalogoTables()` + `retireTarifCatalogoViewPage()` wired |
| 30 — WU-5.E test-ownership move + audit repoints (CAT-07) | 30.1–30.8 | ✅ 8/8 | 2 contracts relocated; 3 tarifario audit tests repointed |
| 31 — WU-5.F dual-suite gate + grep audit (CAT-06/CAT-07/CAT-08) | 31.1–31.8 | ✅ 8/8 | 31.7 is verify-owned and recorded as an **open warning** below |
| 32 — WU-5 delivery (atomic two-repo landing) | 32.1–32.3 | ⏳ 0/3 (by design) | **Human-owned** commit/push; explicitly out of this verification's authority (no commit/push allowed) |
| **Total** | **46/49 checked; 3 intentionally open** | | Phase 32 is delivery, not slice implementation |

| Requirement | Scenarios | Status |
|---|---|---|
| CAT-01 — catalogo_core owns the catalog models and schemas | 2 | ✅ covered (source + class contracts) |
| CAT-02 — canonical catalog page is catalogo_core-owned | 2 | ✅ covered (source contracts) |
| CAT-03 — catalog behaviors preserved | 2 | ✅ covered (source contracts) |
| CAT-04 — htmx 4 + Alpine CSP and catalog entry points | 2 | ✅ covered (hygiene contract) |
| CAT-05 — `tarif_catalogo_view` retired with no alias | 3 | ✅ covered |
| CAT-06 — no half-moved state; RBAC stays in tarifario | 2 | ✅ covered |
| CAT-07 — locked contracts and suite baseline | 2 | ✅ covered |
| CAT-08 — WU-5 boundary: Excel/JSON internals stay out | 1 | ✅ covered |

## Build & Tests Execution

### Test suites (independent re-run)

```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
...
OK, but there were issues!
Tests: 641, Assertions: 2741, Warnings: 26, Skipped: 1.
# exit 0

$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
...
OK, but some tests were skipped!
Tests: 162, Assertions: 555, Skipped: 3.
# exit 0
```

| Field | Value |
|---|---|
| `test_command` | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml ; ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` |
| `test_exit_code` | **0** (both suites) |
| `test_output_hash` | `sha256:3320b1daa434180db95dda28a6dfca47833ee7aaa1a8e889ad25bfc393aed36c` = `sha256sum` of the concatenated stdout of both runs (`/tmp/opencode/wu5-verify/catalogo_core.out` + `tarifario.out`) |
| catalogo_core actual | **641 tests / 2741 assertions**, 0 failures — expected 641/2741 ✅; floor 611/2422 exceeded by +30/+319 |
| tarifario actual | **162 tests / 555 assertions**, 0 failures, Skipped 3 — expected 162/555, Skipped 3 ✅ |

Focused WU-5 runtime evidence (both green, exit 0):

```
$ ddev exec bash -lc "php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    --filter 'CatalogoManagerOwnershipTest|TarifCatalogoHtmxContractTest|TarifCatalogoOpcionalMasterExportTest|InitUpgradeTest|CatalogoCoreHookMarkersTest'"
OK (45 tests, 402 assertions)

$ ddev exec bash -lc "php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml \
    --filter 'FamiliaOverrideRemovalTest|TarifFamiliaWriteRetirementTest|LegacyImportRegressionTest|HookRegistrationTest'"
OK, but some tests were skipped!
Tests: 20, Assertions: 52, Skipped: 1.
```

### Build / lint

```
$ ddev exec php -l <5 moved models> ; ... ; <reclassed controller/wrapper/trait/Init.php>
      ; ; <5 catalogo_core WU-5 tests> ; <3 repointed tarifario audit tests>      # 18 files
No syntax errors detected in <18/18 files>
# exit 0
```

| Field | Value |
|---|---|
| `build_command` | `ddev exec php -l` on the 5 moved models + `Controller/VentasCatalogo.php` + `controller/ventas_catalogo.php` + `extras/VentasCatalogoStateTrait.php` + `Init.php` + the 5 catalogo_core WU-5 tests + the 3 repointed tarifario audit tests (18 files) |
| `build_exit_code` | **0** (18/18 `No syntax errors`) |
| `build_output_hash` | `sha256:898302e4a6e6f2296a8d6258d454ce1e8f2b9dca11b806842d4cadad85695829` (`/tmp/opencode/wu5-verify/lint.out`) |

### Root regression — `ddev exec php vendor/bin/phpunit --testsuite Plugins`

```
Tests: 1522, Assertions: 5574, Failures: 10, Warnings: 1, PHPUnit Deprecations: 12, Skipped: 37.
# exit 1  | output sha256:33c3b04160587aa87be9b3b25332ecfe9b201ff3a16530a305b40060de25a608
```

The 10 failures are **exactly the known pre-existing set** (identical tally to the WU-1/WU-2/WU-3/WU-4 baselines) and none is caused by WU-5:

| # | Test | Plugin | Class |
|---|---|---|---|
| 1 | `OidcRegisterControllerMinimalClienteTest` | OidcProvider | unrelated (registration) |
| 2 | `OidcLegacySchemaParityTest` | OidcProvider | unrelated (schema) |
| 3 | `OidcSchemaContractTest` | OidcProvider | unrelated (schema) |
| 4–7 | `migration011_cliente_gruposTest` (×4) | OidcProvider | unrelated (DB/backfill) |
| 8–9 | `TarifTarifaOpcionalPrecedenceTest` (×2) | catalogo_core | pre-existing from the already-landed opcionales change; no catalog/`tarif_catalogo*` surface involved |
| 10 | `LegacySupportTest::testPluginVersionIsBumpedTo120ForTheBaseJsMove` | legacy_support | version drift `1.2.0` → actual `1.3.0` |

## Spec Compliance Matrix (CAT-01..CAT-08)

| Req | Scenario | Independent evidence (verify-run) | Result |
|---|---|---|---|
| CAT-01 | Models/schemas resolve only from catalogo_core | 5 models + 5 XMLs present under `plugins/catalogo_core/model[/table]/`; tarifario twins absent; **sha256 identical** to the tarifario HEAD blobs (10/10); `CatalogoManagerOwnershipTest::test_catalog_models_and_xml_live_only_in_catalogo_core` + `::test_catalog_table_names_are_byte_stable` green | ✅ PASS |
| CAT-01 | Standalone catalog bootstrap idempotent | `Init::ensureCatalogoTables()` (`public static … : void`) FK-safe order `tarif_catalogo → tarif_catalogo_def → tarif_catalogo_articulo → tarif_catalogo_def_articulo → tarif_catalogo_def_familia`, guarded by `is_file` + `class_exists($fqcn, false)` + `is_subclass_of(\fs_model::class)`, wired in `init()`/`upgrade()` after `ensureArticuloExtTables()` in its own `try/catch`; FK parents precede the three FK children in the XMLs; `test_catalogo_core_bootstraps_the_catalog_tables_standalone` + `InitUpgradeTest::test_ensure_catalogo_tables_is_wired_and_fk_safe` green. **DEV-DB two-boot idempotency not exercised → OPEN warning (source-contract only)** | ✅ PASS (source) / ⚠️ runtime smoke open |
| CAT-02 | Canonical page resolves | `Controller/VentasCatalogo.php` `namespace FSFramework\Plugins\catalogo_core\Controller`, `class VentasCatalogo extends \FSFramework\Controller\PageController`, `use \VentasCatalogoStateTrait`, **no** `tarif_controller` require, no `private_core()`; wrapper `controller/ventas_catalogo.php extends \…\Controller\VentasCatalogo`; `getPageData()` = `ventas_catalogo`/`Catálogo`/`catalogo`/`true`/`130`; ctor `setTemplate('ventas_catalogo')`; `test_canonical_page_is_ventas_catalogo_on_pagecontroller` + `::test_page_registry_wrapper_and_getpagedata` green. Actual authenticated render not exercised → OPEN warning | ✅ PASS (source) / ⚠️ runtime smoke open |
| CAT-02 | Role/permission checks preserved | `roleEnTarifa()` resolves `FSFramework\model\tarif_grupo_usuario` via `class_exists($class)` and returns `false` when absent (fail-closed); `initUserPermissions()` keeps `gestor`/`editor`/`revisor`/`visualizador` + notes flags + `has_access`; `test_role_permissions_are_resolved_through_the_soft_seam` green. Mutation-refusal runtime not exercised → OPEN warning | ✅ PASS (source) / ⚠️ runtime smoke open |
| CAT-03 | Tree, search, action dispatch preserved | All dispatch cases survive: `htmx_articulos`, `htmx_articulos_agrupados`, `htmx_search`, `toggle_en_tarifa`, `toggle_en_catalogo`, `reorder_articulos`, `inline_edit_articulo`, `get_articulos`, `search`, `preview_excel`, `get_preview`, notes CRUD (`crear_nota`/`resolver_nota`/`cancelar_nota`), download actions, `default` JSON error; `test_catalog_entry_points_still_resolve` green | ✅ PASS |
| CAT-03 | Fragment endpoints preserved | Fragment templates renamed to `ventas_catalogo_articulos` / `ventas_catalogo_articulos_agrupados` / `ventas_catalogo_search`; swap targets intact — `#tbody-{codfamilia}` `innerHTML` (tree), `#tbody-…` `beforeend` (pagination), `#grouped-view-{codfamilia}` `innerHTML` (grouped), search region; `test_fragment_actions_keep_serving_the_pinned_templates` green. Live htmx fragment round-trip not exercised → OPEN warning | ✅ PASS (source) / ⚠️ runtime smoke open |
| CAT-04 | htmx/Alpine hygiene | Host view: `htmx.boot()` + `alpine.boot()`; `Alpine.data('catalogoShell')` behind `document.addEventListener('alpine:init')` from one `{{ csp_nonce_attr() }}` classic script; config via `data-catalogo-config="{{ …|json_encode|e('html_attr') }}"`; `x-cloak`/`x-text`/`x-on:`; mutations `htmx.ajax('POST')` + `postJson()`; colon names `htmx:after:swap`/`htmx:after:request`; zero `|raw`, zero `bootbox`, zero `hx-delete/put/patch`, zero v2 event names, zero inline `on*=` in the 4 views + 12 partials; `test_alpine_and_htmx_hygiene` + `::test_mutations_are_post_only` + `::test_catalogo_js_bridges_view_mode_and_sort_refetch_through_htmx_ajax` green. Residual deep-jQuery layer is WU-8 debt (see WARNING 4) | ✅ PASS |
| CAT-04 | Toolbar/tree/notes/images entry points | `partials/catalogo/toolbar.html.twig` included at view L53; `panel_notas` L322; modal includes for JSON/Excel-wizard/images/images-zip L299–314; tree `x-on:click` handlers present; `test_toolbar_and_tree_entry_points_are_preserved` green | ✅ PASS |
| CAT-05 | No alias | Retired controller/views/partials/JS absent from tarifario (`? no retired controller`, no `partials/catalogo`, no `js/catalogo`, no `tarif_catalogo*.html.twig`) and no catalogo_core twin under the retired names; `test_retired_catalog_surfaces_leave_no_alias` green | ✅ PASS |
| CAT-05 | Inbound links repointed | `View/Macro/TarifarioComponents.html.twig:271` → `index.php?page=ventas_catalogo&codtarifa={{ fsc.codtarifa }}`; the only production `page=tarif_catalogo_view` left is `plugins/tarifario/process_excel_wizard.php:477` (accepted WU-7 item); remaining bare `tarif_catalogo_view` hits are provenance comments (`VentasCatalogo.php`, `ExcelRowUpdater.php`, `ExcelHierarchyService.php`, a dev tool); `test_inbound_links_repoint_to_ventas_catalogo` green | ✅ PASS |
| CAT-05 | Menu row deleted idempotently | `retireTarifCatalogoViewPage()` gates `->delete()` behind `fs_page::get('tarif_catalogo_view')`, wired in `upgrade()` in its own `try/catch`; `InitUpgradeTest::upgradeRetiresTarifCatalogoViewPageIdempotently` green. Two-`upgrade()` DEV-DB proof not exercised → OPEN warning | ✅ PASS (source) / ⚠️ runtime smoke open |
| CAT-06 | Zero tarifario paths, no duplicate | Independent grep of catalogo_core production (tests/vendor/openspec/.git excluded) for `plugins/tarifario` / `@tarifario` → **zero hits**; `test_catalogo_core_has_no_tarifario_path_for_the_catalog_surface` green; each moved asset exists in exactly one plugin | ✅ PASS |
| CAT-06 | RBAC boundary stays in tarifario | `plugins/tarifario/Services/ArticlePermissionListener.php` present; registration in `Init.php:115-119` on catalogo_core's `\FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent`; role tables `tarif_grupo_{usuario,rol,tarifa,articulo}` stay in tarifario; `test_rbac_boundary_stays_in_tarifario` + tarifario `Integration/HookRegistrationTest` green | ✅ PASS |
| CAT-07 | Locked hook-markers test retained | `Integration/CatalogoCoreHookMarkersTest` green (part of the 641); it is **not** edited by WU-5 (its working-tree delta is the disclosed WU-2 macro/nonce chain) | ✅ PASS |
| CAT-07 | Moved tests green; baseline held | `tests/Controller/TarifCatalogoHtmxContractTest.php` (8 methods) + `TarifCatalogoOpcionalMasterExportTest.php` (6 methods) exist only in catalogo_core, target `Controller/VentasCatalogo.php` / `page=ventas_catalogo`, and never assert the retired slug; tarifario copies gone (1 `git rm` + 1 untracked `rm`); catalogo_core suite **641 ≥ 611** tests and **2741 ≥ 2422** assertions | ✅ PASS |
| CAT-08 | Wizard internals and SSE URL untouched | `ExcelHierarchyService`, `ExcelImportWizardService`, `ExcelRowUpdater` stay in tarifario with no catalogo_core twin (only the distinct pre-existing basic `ArticuloExcel*` stack); tarifario `process_excel_wizard.php` tracked + unmodified; moved controller keeps the guarded `use …tarifario\Services\{ExcelHierarchyService,ExcelRowUpdater}` entry points; `test_wizard_internals_stay_in_tarifario` + `::test_descartadas_url_repoint_is_a_wu7_item` green | ✅ PASS |

## Design & frozen-contract coherence (AD-W5-1..AD-W5-14)

Independent checks, not apply claims:

- **AD-W5-1/AD-W5-2/AD-W5-3/AD-W5-4**: class/wrapper/`getPageData`/trait/soft seam/CSRF shim verified by direct source read; the 10 moved files are sha256-identical to the tarifario HEAD blobs.
- **AD-W5-5/AD-W5-6/AD-W5-7/AD-W5-8**: endpoint names/templates/swap targets, hygiene, asset move and bootstrap/retirement verified as above.
- **AD-W5-8/AD-W5-14 frozen bodies**: the incremental `Init.php` diff (worktree vs index) is **purely additive `+404 / -0`** — no pre-existing line is deleted or modified — and `CatalogoManagerOwnershipTest::test_catalogo_core_bootstraps_the_catalog_tables_standalone` asserts `tarif_catalogo` does not appear inside the `ensureArticuloTarifaTables()` / `ensureArticuloDetalleTables()` / `ensureArticuloExtTables()` bodies (green). Caveat: the plugin git index is stale (HEAD + 13 lines), so the diff covers the cumulative WU-1..WU-5 additions, not WU-5 in isolation; the per-method guard test is the WU-5-specific control.
- **AD-W5-10/AD-W5-11**: macro repoint confirmed at `:271`; `ArticuloModelosExtOwnershipTest` require-map points at `Controller/VentasCatalogo.php`; the 3 tarifario audit tests repointed and green.
- **AD-W5-12/AD-W5-13**: not executed (delivery, human-owned); RBAC boundary confirmed staying in tarifario.

## Issues Found

### CRITICAL

None. Both expected plugin suites are green at the expected counts, the root suite adds zero new failures, and no spec scenario lacks a passing covering test.

### WARNING (open, non-blocking for this slice)

1. **`InitUpgradeTest` seed stubs relocated (test-infra).** The `FSFramework\model\{CatalogoCoreSeedStub,impuesto,familia,fabricante}` stubs moved out of `tests/InitUpgradeTest.php` file scope into `tests/Support/CatalogoCoreSeedStubs.php` and are `require_once`d at runtime in `setUp()`. Reason (apply deviation 1): with `processIsolation`, PHPUnit replays parent-included files into every child, so the file-scope `familia` stub fataled when the relocated master-export test loaded the real `model/core/familia.php`. Assertions are unchanged and both `InitUpgradeTest` (6) and the relocated contract (6) are green. A locked test file was edited; the edit is test-infra-only and disclosed.
2. **The tarifario master-export test was deleted with `rm`, not `git rm`.** `plugins/tarifario/tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` was untracked, so `git rm` could not apply; it was removed from the working tree (apply deviation 2). No index entry remains and no test file exists in both plugins, but the human Phase 32.2 landing must rely on the explicit `rm` path, not a staged `D`.
3. **WU-7 SSE 404 (accepted boundary).** `plugins/tarifario/process_excel_wizard.php:477` keeps `'descartadas_url' => 'index.php?page=tarif_catalogo_view&action=download_import_log&file=…'`, so the "download discarded rows" SSE link returns 404 until WU-7 repoints it. CAT-08 explicitly forbids WU-5 from pulling the wizard internals in; `test_descartadas_url_repoint_is_a_wu7_item` pins it.
4. **Residual jQuery/layer debt (WU-8).** `json-import.js`, `images-import.js` and `images-zip.js` keep `$.ajax` fan-outs outside the four `catalogo-main.js` sites named by AD-W5-6, and `revision-notas.js` keeps 9 inline `onclick=` handlers inside JS-generated HTML strings. The pinned CAT-04 hygiene contract only inspects the host view, toolbar and `catalogo-main.js`, so it is green. This is the byte-stable residual the design assigns to WU-8, not new WU-5 surface.
5. **DEV-DB / authenticated runtime smoke unavailable (OPEN).** No session/fixture was available, so the following are proven only by source/class contracts, not live execution: standalone `ensureCatalogoTables()` two-boot idempotency over the five tables; catalog tree/search/fragment runtime parity; the two-`upgrade()` `fs_page` deletion; the authenticated page render/permission refusal. Recorded as open, non-blocking (per the task's "NOT available" clause). Task 31.7 is the verify-owned slot for this.

### SUGGESTION

1. **CAT-08 wording collision on `process_excel_wizard.php`.** catalogo_core ships its own distinct pre-existing basic `process_excel_wizard.php` (tracked, unmodified, WU-3 surface). CAT-08's literal "`process_excel_wizard.php` MUST remain in `tarifario`" is satisfied in intent (the rich tarifario SSE entry and its services are unmoved), but the wording could be disambiguated ("the tarifario rich wizard `process_excel_wizard.php`") to avoid a future false-positive grep.
2. **"Unedited" locked tests carry pre-existing WU-1/WU-2/WU-3 working-tree deltas.** `Integration/CatalogoCoreHookMarkersTest.php` (WU-2 macro/nonce chain) and tarifario `Integration/HookRegistrationTest.php` are `M` relative to HEAD but were **not** touched by WU-5; `ArticuloListaCanonicaOwnershipTest`, `VentasArticulosControllerTest`, `VentasArticuloControllerTest` and `InitFamiliasTablesTest` are green unedited by WU-5. Once the atomic commits land, the WU-5 boundary becomes mechanically provable.
3. **Pin the 10 pre-existing root `Plugins` failures as an explicit baseline** (repeated from WU-1..WU-4) so future slices assert "zero new failures" mechanically.
4. **`ensureCatalogoTables()` end-to-end smoke belongs in the WU-5 landing checklist**, alongside the WU-4 `ensureArticuloExtTables()` smoke, to close the last source-contract-only gap.

## Core `openspec/` isolation

- `git status --porcelain -- openspec/` at the repo root → **empty**. ✅
- `openspec/changes/` contains **no** `absorber-articulos-tarifa-en-catalogo-core/` entry (core). ✅
- The change's SDD is fully contained in `plugins/catalogo_core/openspec/changes/absorber-articulos-tarifa-en-catalogo-core/`. ✅

## Verdict

**PASS WITH WARNINGS**

All 8 requirements and all 16 scenarios of the WU-5 `catalogo-gestion` delta are covered by tests that passed at runtime; both plugin suites are green at the expected counts (catalogo_core **641/2741 ≥ 611/2422**, tarifario **162/555, Skipped 3**, both exit 0); the root `Plugins` suite adds **zero new failures** (the same 10 pre-existing, none WU-5-related); the five catalog models + five XMLs are byte-identical moves owned solely by catalogo_core; `VentasCatalogo` is a `PageController` page with the required registry/menu contract and no `tarif_controller` base; the fragment endpoints, swap targets and action names are preserved; the htmx 4 + Alpine hygiene contract is green with the documented WU-8 jQuery residual; `tarif_catalogo_view` is retired with no alias and every inbound link repointed; the standalone bootstrap and `fs_page` retirement are present, FK-safe and idempotent by source contract; the RBAC listener and role tables stay in tarifario; and the wizard internals stay out.

This verdict verifies **only the WU-5 slice** (catalog manager ownership move into catalogo_core + `ventas_catalogo` reclass + htmx/Alpine hygiene + soft RBAC seam + standalone bootstrap + slug retirement + test-ownership move). It is **not** a verification of the full WU-1..WU-8 absorption program; WU-6 through WU-8 remain unimplemented and unverified. The open warnings (unavailable DEV-DB/authenticated runtime smoke, the relocated `InitUpgradeTest` stubs, the untracked master-export test removal, the accepted WU-7 SSE 404, and the residual WU-8 jQuery layer) are non-blocking for the slice but must be tracked.

**`next_recommended`**: land the WU-5 atomic commits (Phase 32.1–32.3: catalogo_core first, tarifario immediately after, no deploy between, then clear the Twig cache) and close the standalone `ensureCatalogoTables()` DEV-DB two-boot smoke plus the catalog tree/fragment runtime parity checklist; then proceed to **WU-6** (revision notes and article price history), which hardens the soft `class_exists` seam.
