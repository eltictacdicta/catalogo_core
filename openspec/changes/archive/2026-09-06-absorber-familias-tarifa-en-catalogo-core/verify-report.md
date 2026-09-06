```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:02460209dfffbbcac88b3950e77d02d33aaafcb52fc85520d74a1ffb8fef00c8
verdict: fail
blockers: 0
critical_findings: 0
requirements: 6/9
scenarios: 13/18
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:bacd58f1897a9e52d901387e650550da955053e18de890477c871fb2539e7c0b
build_command: ddev exec composer phpstan
build_exit_code: 0
build_output_hash: sha256:cfe61f5ac2478f7e33e253a56b5da1e31cbacf02c210baeb46c429b6664a0763
```

# Verification Report — absorber-familias-tarifa-en-catalogo-core

**Change**: absorber-familias-tarifa-en-catalogo-core (plugin SDD, `plugins/catalogo_core/openspec/changes/`)
**Spec**: `specs/familias-tarifa-management/spec.md` — 9 requirements / 18 scenarios (counts verified against actual headings)
**Mode**: Strict TDD (runner `ddev exec php vendor/bin/phpunit`)
**Verify date**: 2026-09-06 (round 1; re-verified same day after remediation `b38fdbc`)

## Executive Summary

The coordinated trio landed as designed (tarifario `9321af5`, catalogo_core `8149ee8`, parent `9386bf87`) and the working trees match the apply-progress record exactly. The anchored grep gate is clean, the catalogo_core suite is plain exit 0 (233 tests / 479 assertions), and both suites containing the WIP multitarifa test show exactly the 1 maintainer-approved baseline-RED failure and zero new failures. CLI smokes against the dev DB prove the standalone bootstrap is idempotent, the four tables exist with the schema-expected PK, `find_controller` resolves `page=tarif_familias` to `plugins/catalogo_core/controller/tarif_familias.php` (both orders and standalone), the moved Macro resolves through the framework's own Twig loader chain, and the read-only `count_articulos` / `get_familias_flat` flows match direct SQL on live data. Re-verify (round 2): the PHPStan gate now completes with `[OK] No errors` (177 files, exit 0) after remediation commit `b38fdbc` in `plugins/clientes_facturacion` (duplicate-trait rename, maintainer-owned fourth repo, outside this change), so W1 is resolved. Final machine verdict: **FAIL — verification-state encoding only**. Every gate that could execute is green and there are zero CRITICAL findings; the schema's admission contract refuses any passing verdict while 5 spec scenarios (+ R3-S5 row persistence) lack runtime evidence, and the byte-identical-move argument — while strong — does not convert unexercised HTTP flows into covered scenarios. Exactly what remains is listed in the Residual section.

## Completeness

| Metric | Value |
|--------|-------|
| Tasks total (verify scope: Phase 6) | 5 (6.1–6.5) |
| Tasks executed this phase | 5 (all; evidence below) |
| Tasks incomplete | 0 |
| tasks.md checkbox state for 6.x | left unchecked by apply for verify to execute; tick at archive time per plugin convention |
| Implementation tasks (Phases 1–5) | all checked `[x]` in tasks.md (verified by read) |

## Build & Tests Execution

**Grep gate (6.1)**: ✅ GATE OK — zero hits.

```text
grep -rEn --include='*.php' --include='*.twig' \
  'plugins/tarifario/model/(tarif_tarifa|tarif_familia|tarif_tarifa_familia|tarif_tarifa_etiqueta_familia|tarif_familia_ext)\.php' \
  --exclude-dir=vendor --exclude-dir=openspec --exclude-dir=node_modules . \
  && echo "GATE FAILED" || echo "GATE OK"
→ GATE OK   (sha256 of captured output: 8e7256d66e651b8b48faeb241d55d082705d24724a9aa0497326303e765a9c5b;
   re-run at re-verify with byte-identical output — same digest, GATE OK)
```

**Build / static analysis (6.2)**: ✅ Passed after re-verify remediation.

```text
ddev exec composer phpstan
→ 177/177 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
→ [OK] No errors
(exit 0; sha256: cfe61f5ac2478f7e33e253a56b5da1e31cbacf02c210baeb46c429b6664a0763)

Round-1 state (superseded): exit 255 — "Cannot declare trait factura … already in use"
(plugins/facturacion_base/extras/factura.php:25, duplicated in clientes_facturacion),
resolved by remediation commit b38fdbc in plugins/clientes_facturacion: trait renames
factura→factura_clientes, documento_venta→documento_venta_clientes,
linea_documento_venta→linea_documento_venta_clientes + 9 consumer refs (8 in-repo +
factura_cliente.php:30). Outside this change's blast radius (maintainer-owned fourth
repo). Re-verify checks: `trait factura_clientes` present at extras/factura.php:27, old
`trait factura` name gone from that file, repo clean at b38fdbc.

Scope note (honest framing): phpstan.neon analyzes paths `src/` + `tests/` (root) — the
project's canonical gate, unchanged by this work; plugin trees (including this change's
code) were never in its configured paths, before or after the change. What round 2 proves:
the gate's bootstrap now loads every plugin model file without the collision, and the
configured scope is error-free. The change's plugin-side correctness is carried by the
32 new/moved tests, the suites, and the runtime smokes (see SUGGESTION S-D).
```

**Tests (6.3)**: primary suite ✅ exit 0; baseline-criterion suites show exactly the 1 approved known-RED, zero new.

```text
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
→ OK, but there were issues!  Tests: 233, Assertions: 479, Warnings: 25, Skipped: 1.  EXIT=0
  (sha256: bacd58f1897a9e52d901387e650550da955053e18de890477c871fb2539e7c0b)

ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
→ Tests: 140, Assertions: 496, Failures: 1, Skipped: 3. EXIT=1
→ sole failure: Tests\Tarifario\Integration\VentasArticulosQuickCreateGateCompositionTest
  ::testUnassignedEditorQuickCreateIsDeniedByRealListener   ← BASELINE EXCEPTION (WIP multitarifa, pre-existing, commit 87b8a4a; NOT a defect of this change)
  (sha256: ef400d79948fdfa0ceafe4a014a7c6fa80d53847d8501c5ce324d895ce9de563)

ddev exec php vendor/bin/phpunit --testsuite Plugins
→ Tests: 712, Assertions: 1871, Failures: 1, Skipped: 10. EXIT=1
→ sole failure: same baseline test. Zero new failures/errors.
  (sha256: 0f5eb66820f8566212c7051ea62d5a04ce17372641e1066da5e36b78f9572946)
```

All three suite counts match the apply-phase GREEN evidence (obs #569) digit for digit (233/479, 140/496, 712/1871).
Re-verify (round 2) confirmed the trio repos unchanged since those runs — HEADs still
tarifario `9321af5` / catalogo_core `8149ee8` / parent `9386bf87` with identical working-tree
state — so the recorded suite evidence stands without re-execution (per re-verify contract;
the remediation commit `b38fdbc` landed in clientes_facturacion, a fourth repo outside the trio).

**Coverage**: ➖ skipped — no coverage tool detected in project capabilities (informational only).

## TDD Compliance (Strict TDD active)

| Check | Result | Details |
|-------|--------|---------|
| TDD Evidence reported | ✅ | apply-progress obs #569 carries the per-task RED/GREEN table with failure-reason evidence |
| All tasks have tests | ✅ | 4 new test files + 1 split integration file exist on disk and are suite-discovered |
| RED confirmed (tests exist + RED state) | ✅ | RED recorded pre-move as "missing catalogo_core path/class" failures, zero syntax/setup errors; files exist in tree; post-move re-RED is impossible by construction |
| GREEN confirmed (tests pass now) | ✅ | all 32 new/moved tests pass inside the 233-test catalogo_core run and the 712-test root Plugins run |
| Triangulation adequate | ✅ | toggles: empty/unknown/known×2 flips/save-failure per toggle; count: 7 and 0 cases + SQL/param-shape assertions; hierarchy: 3-depth fixture asserting order, nesting, and levels; split test: both activation orders |
| Safety Net for modified files | ✅ | pre-edit baseline captured in apply attempt 2 (fresh-baseline step 0) before any modification |

**TDD Compliance**: 6/6 checks passed.

## Test Layer Distribution

| Layer | Tests | Files | Tools |
|-------|-------|-------|-------|
| Unit (DB-free: source-contract, fake-db, Reflection) | 28 | 4 | PHPUnit 11 |
| Integration (autoloader both-activation-orders) | 4 | 1 | PHPUnit 11 |
| E2E | 0 | 0 | not installed (HTTP UI flows → PENDING-USER smokes) |
| **Total** | **32** | **5** | |

## Assertion Quality

All 32 test cases in the 5 files (`InitFamiliasTablesTest`, `TarifFamiliasControllerContractTest`, `TarifFamiliasHierarchyTest`, `TarifFamiliasToggleTest`, `Integration/FamiliaTarifaResolutionTest`) were audited line-by-line: no tautologies, no assertions without production-code calls, no ghost loops (the one file-existence loop iterates a collection proven non-empty by a sibling sequence-equality assertion), no implementation-detail-only assertions. The single `Skipped: 1` is the documented conditional design skip (`test_noop_when_model_files_absent` — the live create path is the verify smoke per design).

**Assertion quality**: ✅ All assertions verify real behavior (0 CRITICAL, 0 WARNING)

## Spec Compliance Matrix

Statuses: ✅ COMPLIANT (covering test/check passed at runtime) · ⏳ PENDING-USER (planned verify-phase smoke; authenticated HTTP UI required) · ⚠️ PARTIAL.

| # | Requirement | Scenario | Evidence | Result |
|---|-------------|----------|----------|--------|
| R1 | Tarif familias page served by catalogo_core | Page renders after the move | user-confirmed: maintainer rendered `page=tarif_familias` (and `ventas_familias`) in ddev (browser-cache caveat); automated: CLI `find_controller` C1–C4, Twig view resolution D1, view :758 JS src | ✅ COMPLIANT |
| R1 |  | Controller decoupled from tarifario | `TarifFamiliasControllerContractTest` (extends `fbase_controller`, 0 `tarif_controller`, 0 `plugins/tarifario` refs) green + GATE OK | ✅ COMPLIANT |
| R2 | Familias hierarchy management | Reorder moves the subtree | unit: build_tree nesting/`nivel_tree` green; read-only live-data E2 (233 rows → 233 flat, 12 roots); the SQL-bound `ajax_reorder` flow itself needs the UI | ⏳ PENDING-USER |
| R2 |  | Promote/demote and next capitulo | unit: `get_next_capitulo` action registered (:166) green; promote/demote are SQL-bound UI flows | ⏳ PENDING-USER |
| R3 | Toggle state management | Toggle flips state and answers JSON | `TarifFamiliasToggleTest` (10 tests: success + flipped value, array shape, `json_encode` guarantee) green; row-level DB persistence → smoke step 3 | ✅ COMPLIANT |
| R3 |  | Toggle rejects unknown familia | same file: empty + unknown → `success=false` array, no HTML | ✅ COMPLIANT |
| R4 | Add/edit familia in tarifa | Modal creation with posibles madres | API surface asserted (`get_posibles_madres`, `get_etiquetas_familia_text/full`) green; creation flow is SQL-bound UI | ⏳ PENDING-USER |
| R4 |  | Etiqueta visibility is per tarifa | structural: PK `(codtarifa, codfamilia, etiqueta)` proven live (A3) and in committed XML (:27–30); save flow is SQL-bound UI (design defers: no unit test for this scenario) | ⏳ PENDING-USER |
| R5 | Article count contract | Count returns the raw SQL total | behavioral contract tests (7 / 0 cases, param order) green + read-only live match E1: controller=8, direct SQL=8, MATCH=YES | ✅ COMPLIANT |
| R5 |  | Delete guard blocks non-empty familia | contract test `test_delete_guard_routes_through_count_helper` (2 call sites) green; guard source :707–711 | ✅ COMPLIANT |
| R6 | Excel export and import | Excel actions respond | actions registered at :146–157; exercising them requires authenticated HTTP | ⏳ PENDING-USER |
| R6 |  | CSRF meta present | moved view source: `{{ csrf_meta() }}` at :740 (inspect-check, verified by verify phase) | ✅ COMPLIANT |
| R7 | Standalone table bootstrap | Fresh standalone install creates the tables | smoke A1 (bootstrap ran clean) + A2 (all 4 tables EXISTS) + unit FK-safe-order test green; caveat: dev DB already carried the tables, so the literal create-on-empty branch is proven structurally (order + guards + same fs_model mechanism as `tarifario_init`), not on a bare DB → SUGGESTION S1 | ✅ COMPLIANT (caveat noted) |
| R7 |  | Bootstrap is idempotent | smoke B1 (re-run OK) + B2 (`SHOW CREATE TABLE` identical after re-run: YES) | ✅ COMPLIANT |
| R8 | Reference integrity after the move | Grep gate passes | GATE OK (anchored command from design, run by verify phase) | ✅ COMPLIANT |
| R8 |  | Macro still serves tarifario views | D1: framework's own `Html::buildFilesystemLoader` (both plugins active) resolves `Macro/TarifarioComponents.html.twig` → `/var/www/html/plugins/catalogo_core/View/Macro/…` plus view and both modals; user-confirmed page renders corroborate | ✅ COMPLIANT |
| R9 | Test ownership split | Split suites pass | `FamiliaTarifaResolutionTest` green in catalogo_core suite; reduced `FamiliaOverrideRemovalTest` green in tarifario suite; root Plugins suite discovers both | ✅ COMPLIANT |
| R9 |  | Both activation orders safe | both-orders tests (`..._with_tarifario_first`, `..._with_catalogo_core_first`) green; state guards verbatim | ✅ COMPLIANT |

**Compliance summary**: 13/18 scenarios compliant · 5/18 pending-user · 0 failing/untested.

## Correctness (Static Evidence)

| Requirement | Status | Notes |
|------------|--------|-------|
| R1 page ownership | ✅ Implemented | controller :20/:21/:22 catalogo_core requires; :40 `class tarif_familias extends fbase_controller`; view :758 new JS src; folder label `tarifario` kept (:76 area) |
| R2 hierarchy API | ✅ Implemented (verbatim) | :567 `get_familias_flat`, :549 `build_tree`, :575 `flatten_tree`, :386/:196/:236 reorder/promote/demote, :166 `get_next_capitulo` |
| R3 toggles | ✅ Implemented (verbatim) | :278/:303/:328; :162–178 `json_encode` switch |
| R4 add/edit + etiquetas | ✅ Implemented (verbatim) | :750 `get_posibles_madres`, :831/:843 etiqueta API, XML PK intact |
| R5 count contract | ✅ Implemented | :43 const, :726–729 public delegate, :736–745 parameterized helper; 0 model traces outside const (contract test) |
| R6 Excel + CSRF | ✅ Implemented | :146–157 actions; :740 `csrf_meta()`; :1067 chunked upload require kept |
| R7 bootstrap | ✅ Implemented | `Init.php:63–81` matches design contract verbatim; invoked from `init()` (:29–33 try/catch) and `upgrade()` (:48) |
| R8 reference integrity | ✅ Implemented | GATE OK; 18/18 files exist only in catalogo_core; root `phpunit.xml` retirement verified (`grep -c FrameworkAutoloaderOverrideTest` = 0, unrelated InitUpgradeTest exclusion kept at :95) |
| R9 test ownership | ✅ Implemented | 5 files in catalogo_core (28 unit + 4 integration tests), reduced test stays in tarifario with verbatim guards |

## Coherence (Design D1–D7)

| Decision | Followed? | Notes |
|----------|-----------|-------|
| D1 reclass to `fbase_controller` | ✅ | :40; fbase require :20 |
| D2 raw COUNT helper | ✅ | helper verbatim vs design contract (parameterized, int cast) |
| D3 table-name const | ✅ | :43; single reference site |
| D4 standalone bootstrap | ✅ | Init.php verbatim; controller guarded once-flag :46/:86–91 |
| D5 test split | ✅ | namespaces/guards as designed |
| D6 retirements | ✅ | 2 tests gone (commit `8149ee8`), root phpunit.xml rows removed (parent `9386bf87`, −6 lines) |
| D7 trio atomicity | ✅ | commits verified at HEADs; repos carry only the pre-existing/intentional dirt; no rename entries; 18/18 audit clean |

## Issues Found

**CRITICAL**: None.

**RESOLVED at re-verify**:
- **W1 — PHPStan gate (task 6.2): RESOLVED** by remediation commit `b38fdbc` (plugins/clientes_facturacion — duplicate-trait rename `factura`→`factura_clientes`, `documento_venta`→`documento_venta_clientes`, `linea_documento_venta`→`linea_documento_venta_clientes` + 9 consumer refs; maintainer-owned plugin, outside this change). `ddev exec composer phpstan` now exits `[OK] No errors` (177 files). The round-1 attribution analysis is retained in the Build section as history: the collision predates the trio and none of the three trio diffs touched any `factura`/phpstan file.

**WARNING**:
- **W2 — 5 scenarios remain PENDING-USER** (R2-S3, R2-S4, R4-S7, R4-S8, R6-S11) plus R3-S5 row-level persistence: all are SQL-bound/authenticated-HTTP flows the design explicitly deferred to verify-phase smoke; unit-level and read-only CLI evidence for each is recorded in the matrix. Round-2 weighing: the moved code is behavior-identical by construction — the 18-file move was byte-identical (apply evidence: every copy cmp-equal to its tarifario HEAD blob) and the change's modification inventory touches none of `ajax_reorder`/`ajax_promote`/`ajax_demote`/`save_familia`/`add_familia_to_tarifa`/`replace_etiquetas_familia`/the Excel actions — so these checks CONFIRM pre-existing behavior in its new location rather than verify new behavior. They remain the only open residuals; exact steps in the Residual section below.

**SUGGESTION**:
- **S-A (R7 caveat):** On a staging environment with a clean database, deploy catalogo_core with tarifario inactive once to exercise the literal create-on-empty branch end-to-end (the dev DB already carried the four tables, so the branch was proven structurally, not empirically).
- **S-B (baseline RED):** `testUnassignedEditorQuickCreateIsDeniedByRealListener` is the pre-existing WIP multitarifa baseline failure (tarifario `87b8a4a`), not a defect of this change; schedule its fix in the multitarifa workstream.
- **S-C (tasks.md bookkeeping):** tick tasks 6.1–6.5 in tasks.md at archive time, referencing this report.
- **S-D (phpstan scope, informational):** `phpstan.neon` analyzes root `src/` + `tests/` only; plugin trees (including this change's code) were never in the configured paths. Optionally extend the scope so future plugin SDDs get direct static analysis of plugin code.

## Residual (PENDING-USER) Confirmation Checks — dev DB, authenticated UI

All URLs assume login as an admin and `index.php?page=…`. None of these were exercised by the verify phase (explicitly not faked). Round-2 weighing: the moved code is behavior-identical by construction — the 18-file move was byte-identical (apply evidence: every copy cmp-equal to its tarifario HEAD blob) and the change's diff does not touch any flow below (modification inventory: header/requires/reclass, ensure call, count sites, view :758) — so these are confirmation checks of pre-existing behavior in its new location, supported by the green suites, the 32 new/moved tests, the resolution proofs, and the maintainer's visual confirmation of `page=tarif_familias`. They remain the only open items between this report and full scenario compliance.

1. **R2-S3 — Reorder moves the subtree (capitulo recalc):** open `index.php?page=tarif_familias&codtarifa=DEF` → drag a familia that has descendants to a new position → EXPECT: descendants follow the new order and `capitulo` strings renumber automatically in the tree.
2. **R2-S4 — Promote/demote + next capitulo:** on the same page use the promote/demote controls on a mid-level familia → EXPECT: parent/level change, `capitulo` recalculated; open the add modal and check the suggested `capitulo` (calls `get_next_capitulo`) returns the next free number.
3. **R3-S5 — Toggle row persistence:** flip `en_catalogo`, `en_tarifa`, and `activa` on one familia → EXPECT: JSON success response with the new value; reload the page → EXPECT flipped state persisted per row.
4. **R4-S7 — Modal creation with posibles madres:** in the add/edit modal for tarifa DEF create a familia under a chosen madre → EXPECT: it appears in `get_familias_flat` under that madre.
5. **R4-S8 — Per-tarifa etiqueta isolation:** for one familia under two different tarifas set different etiqueta visibility → EXPECT: two independent rows in `tarif_tarifa_etiqueta_familia`, each with its own `visible` value (PK already proven `(codtarifa, codfamilia, etiqueta)`).
6. **R6-S11 — Excel actions:** from the moved view: download template (`action=export_excel_template`), import a filled template chunk (`action=import_excel_chunk`), and export (`action=export_excel`) → EXPECT: template file, chunk acknowledgement, exported file — no fatal errors.
7. **R8-S16 corroboration (macro importers):** with cache cleared (`CacheManager::clearAll()` or delete `tmp/twig_cache`), open `page=tarif_roles`, `page=tarif_tarifas`, `page=tarif_catalogo_view`, and `page=tarif_configurador_opcionales` → EXPECT: no Twig errors (CLI loader proof D1 already covers resolution).

Already user-confirmed (recorded, no action needed): `page=tarif_familias` and `page=ventas_familias` rendered by the maintainer in ddev (browser-cache caveat).

## Deploy Hygiene (task 6.5)

- **On deploy:** run `CacheManager::clearAll()` (or delete `tmp/twig_cache`). `auto_reload=true` (`src/Core/Html.php:147`) self-heals mtime drift, but clearing avoids stale macro paths in `tmp/` caches (twig_cache, test_model_class_map.php, test_symfony_cache, routes_cache are present in the dev `tmp/`).
- **Rollback:** revert the trio in reverse order — parent `9386bf87` → catalogo_core `8149ee8` → tarifario `9321af5` — then clear the Twig cache again.

## Success Criteria (proposal.md checklist)

- [x] All listed files exist only under `plugins/catalogo_core/`; grep gate returns zero stale `plugins/tarifario/model/...` paths — 18/18 audit clean, GATE OK.
- [x] `page=tarif_familias` renders with unchanged slug, class name, and table names; no data migration — user-confirmed render; slug/class/table names unchanged (source evidence); `fs_page` rows untouched.
- [x] Moved macro's `fsc.count_articulos` contract works from the moved controller — D1 loader resolution + E1 live count MATCH + contract tests.
- [x] Standalone `catalogo_core` (tarifario inactive) creates the 4 tables in FK-safe order — bootstrap exercised clean + tables exist + FK-safe order unit-proven; create-on-empty branch caveat → SUGGESTION S-A.
- [x] Plugin suite + root Plugins suite green; PHPStan level 5 clean — suites green under the approved baseline exception; PHPStan `[OK] No errors` (177 files, exit 0) after remediation `b38fdbc` (round 2); scope note → S-D.

## Verdict

**FAIL — verification-state encoding (round 2), NOT an implementation defect.** Everything executable is green: grep gate re-run byte-identical (GATE OK); catalogo_core suite exit 0 (233/479); baseline-criterion suites exactly at the approved exception (140/496 and 712/1871, single known-RED `testUnassignedEditorQuickCreateIsDeniedByRealListener`); PHPStan `[OK] No errors` (177 files, exit 0) after remediation `b38fdbc`; zero CRITICAL findings; zero blockers. The fail encodes exactly one thing: the schema's admission contract refuses a passing verdict while `requirements: 6/9` / `scenarios: 13/18` — the 5 spec scenarios (R2-S3, R2-S4, R4-S7, R4-S8, R6-S11) plus R3-S5 row persistence remain PENDING-USER confirmation checks (exact steps above). The byte-identical-move argument (documented in W2 and the Residual section) makes these confirmations of pre-existing behavior in its new location, but it does not fabricate runtime coverage, so the counts were not inflated. Path to PASS: the maintainer executes the residual checks (or accepts the residual at settlement) → re-verify updates the counts → PASS → sdd-archive.
