# Verify Report: ventas-articulos-quick-create-gate

- **Change**: `ventas-articulos-quick-create-gate` (plugin-local SDD, `plugins/catalogo_core/openspec/`)
- **Scope**: R-QCRT-001..007 + behavioral-test discipline — route the quick-create `pvp` write through the frozen `ArticlePermissionFilterEvent` contract (dispatch before mutation/save for non-admin, admin zero-dispatch, deny blocks whole create, fail-closed, host-neutral allow, CSRF unchanged, Excel/article-page orthogonality)
- **Specs (binding)**: `specs/articulos-quick-create-permission/spec.md` (**14 scenarios**; 8 requirements R-QCRT-001..007 + behavioral-discipline scenarios)
- **Design**: `design.md` (D1..D7); **Tasks**: `tasks.md` (4 phases, single PR, 26/27 ticks; 5.2 = this report)
- **Verify mode**: standard (Strict TDD inactive at verify; the change was implemented RED→GREEN per tasks Phase 2/3); DDEV; PHPUnit 11
- **Verifier**: sdd-verify executor (independent runtime verification, no code changes)
- **Date**: 2026-09-04
- **Committed range**: catalogo_core `e258154..8bffb37` (docs 3715c49, RED 4d65a20, GREEN 73bddae, tick 8bffb37) + tarifario `6a807d9..87b8a4a` (composition test 87b8a4a)

---

## Verdict: **PASS**

The change is fully compliant with the binding spec. All 14 scenarios are covered: **11 behaviorally pinned by passing runtime tests**, **2 by diff audit (R-QCRT-007 S1/S2 — honestly marked proxy/audit, no runtime test exists by design)**, **1 by meta-audit (Beh-S3 — no structural pins)**. The live HTTP walkthrough — the change's whole point — proves the deny path end-to-end in the real application: admin allow with price, unassigned-editor deny with the exact user-facing reason and no article row, gestor allow with price, CSRF block. The article-page edit path (deferred R-TAR-HOOK-003) and the Excel import path are untouched and confirmed working (orthogonality spot check).

No new CRITICAL or WARNING findings. One new SUGGESTION observation (CSRF single-layer enforcement on modern `PageController` pages — pre-existing architecture, spec-accurate, documented). The host suite exits non-zero solely on the **2 known pre-existing `testTwigViewUsesAutoEscape` failures** (baseline 290/628; the change touches zero template files — provably unrelated). Both reviews APPROVED; their findings are restated as follow-up backlog below (not fixed, per instruction).

---

## 1. Suites (fresh runs via DDEV, 2026-09-04)

| Suite | Command | Tests | Assertions | Failures | Skipped | Warnings | Baseline match |
|---|---|---|---|---|---|---|---|
| host (catalogo_core) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | 290 | 628 | **2** (known `testTwigViewUsesAutoEscape` x2) | 4 | 25 | ✅ exact (290/628, 2 failures) |
| root | `ddev exec php vendor/bin/phpunit` | 1349 | 3458 | 0 | 11 | 20 deprecations | ✅ exact (1349/3458) |
| tarifario | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | 144 | 510 | 0 | 3 | — | ✅ exact (144/510) |
| gate suite (filter) | host `--filter VentasArticulosQuickCreateGateTest` | 9 | 34 | 0 | 0 | — | new file, all green |
| composition (filter) | tarifario `--filter VentasArticulosQuickCreateGateCompositionTest` | 2 | 7 | 0 | 0 | — | new file, all green |

- **Flake protocol**: root run completed green on the first attempt (no SSE flake encountered; rerun once would have been the protocol, not needed).
- **Known-baseline failures**: `VentasArticuloControllerTest::testTwigViewUsesAutoEscape` (CPV-06) — pins templates to avoid `|raw`; the `ventas_articulos`/`ventas_articulo` templates legitimately use `|raw` for the JS config block. Pre-existing and provably unrelated to this change: the change diff touches **zero** template files (`git diff e258154..8bffb37 --name-status` = `Controller/VentasArticulos.php`, `tests/VentasArticulosQuickCreateGateTest.php`, openspec docs only).
- **processIsolation note**: both plugin phpunit.xml run `processIsolation="true"`; the gate suite redirects `error_log` via `ini_set` (CSRF scenario logs "CSRF: Invalid token in (VentasArticulos)" from `validateFormToken`, and R-QCRT-004 asserts the captured log stays empty of listener lines). Root phpunit.xml has no processIsolation — the gate tests carry `#[RunTestsInSeparateProcesses]` + `#[PreserveGlobalState(false)]` and passed cleanly in the shared root run.

---

## 2. Spec coverage audit (S1–S14)

| # | Scenario | Evidence | Status |
|---|---|---|---|
| S1 | R-QCRT-001 — non-admin dispatch fires before pvp mutation and save | `testNonAdminDispatchesFilterOnceBeforeCreate` (host, PASSED): count==1, event carries referencia/nick/`ACTION_EDIT_ARTICLE` | ✅ behavioral |
| S2 | R-QCRT-002 — admin quick-create with zero dispatch | `testAdminSkipsDispatchEntirely` (host, PASSED): recording listener count==0 + success msg | ✅ behavioral |
| S3 | R-QCRT-003 S1 — unassigned editor denied, article never created | `testDeniedEditorCreateIsBlockedAndSaveNeverCalled` (host, PASSED): message stack == exactly the denial msg, no success/save-fail msg, `execCalls==0` | ✅ behavioral |
| S4 | R-QCRT-003 S2 — npvp defaults 0.0, still blocked | `testDenyWithoutExplicitNpvpStillBlocksWholeCreate` (host, PASSED): same invariant, no DB write | ✅ behavioral |
| S5 | R-QCRT-003 S3 — gestor allowed | `testAllowedCreatePersistsWithSuccessMessage` (host, PASSED): `isAllowed()`, success msg, `execCalls>=1` | ✅ behavioral |
| S6 | R-QCRT-004 — throwing listener denies fail-closed, no error_log | `testThrowingListenerDeniesFailClosedWithoutErrorLog` (host, PASSED): generic `permission filter error` reason, empty captured log, `execCalls==0` | ✅ behavioral |
| S7 | R-QCRT-005 — zero listeners default allow | `testZeroListenersDefaultAllow` (host, PASSED): success msg | ✅ behavioral |
| S8 | R-QCRT-006 — CSRF failure still blocks before any create logic | `testCsrfFailureBlocksBeforeAnyDispatchOrCreate` (host, PASSED): CSRF msg, listener count==0, `execCalls==0` | ✅ behavioral |
| S9 | R-QCRT-007 S1 — Excel gate + article page untouched | **Diff audit** (verify): `VentasArticulo.php`, `Services/ArticuloExcelPermissionGate.php`, `process_excel_wizard_dispatch.php` — zero changes in `e258154..8bffb37`; unchanged suites (incl. Excel wizard tests) green at baseline; live spot check of article page PASS | ⚠️ audit-only (no runtime test exists — a diff cannot be runtime-tested) |
| S10 | R-QCRT-007 S2 — no new constants, tables, grants, UI edits | **Diff audit**: controller +27 lines only, no new `const`/tables/grants/`.twig`/`.js`; proxy test `testActionEditArticleConstantIsFrozen` (host, PASSED): `ACTION_EDIT_ARTICLE === 'edit_article'` | ⚠️ proxy + audit |
| S11 | Beh-S1 — deny path executed, save never called | `testDeniedEditorCreateIsBlockedAndSaveNeverCalled` (host, PASSED): save unreachable proven by message-stack invariant + zero exec — ordering proven by execution, no string pins | ✅ behavioral |
| S12 | Beh-S2 — admin path asserts zero dispatch | `testAdminSkipsDispatchEntirely` (host, PASSED): asserts event never constructed/dispatched (count==0), not merely "no deny" | ✅ behavioral |
| S13 | Beh-S3 — no structural pins | **Meta-audit** of both test files: `rg` for `strpos`/line-number/`file_get_contents` pins → empty; tests drive the real dispatch with real listeners only (REL-2 lesson) | ⚠️ meta-audit (verified by construction + review) |
| S14 | Beh-S4 — cross-plugin composition with tarifario listener | `VentasArticulosQuickCreateGateCompositionTest` (tarifario 2/2 + root, PASSED): gestor→allow (success msg, `execCalls>=1`), unassigned editor→deny (exact real-listener reason, `execCalls==0`) | ✅ behavioral |

**Coverage tally: 11/14 behavioral (runtime-pinned) · 2/14 diff-audit/proxy (S9, S10) · 1/14 meta-audit (S13)** — matches the review's 11/14 confirmation; S9/S10/S13 marked honestly as audit-only.

---

## 3. HTTP walkthrough (live deny path — the change's whole point)

Environment: `https://tarifario-07-2026.ddev.site` (ddev up, PHP 8.3, MariaDB). Method: login POST (`user`/`password` + `_csrf_token` from login page) → session cookie jar → GET `index.php?page=ventas_articulos` → CSRF token from `<meta name="csrf-token">` → POST quick-create (`nreferencia`/`ndescripcion`/`npvp` + `_csrf_token`). Fixtures created additively (users `qc_admin`/`qc_editor`/`qc_gestor` with ARGON2ID hashes, role `VERIFYQC` → page access `ventas_articulos`, tarifario group `VERIFYQC` → tarifa `INF2026` with editor+gestor roles) and **deleted after**; DB verified pristine.

| Step | Scenario | HTTP result | DB result | Verdict |
|---|---|---|---|---|
| **a** | **Admin** quick-create `VERIFY-QC-A` pvp=111.11 | 200, "Artículo VERIFY-QC-A guardado correctamente.", no denial | row exists, **pvp=111.11** (exact submitted price) | ✅ PASS — allow path live, zero dispatch |
| **b** | **Unassigned editor** quick-create `VERIFY-QC-B` pvp=222.22 | 200, **"No tienes permisos para crear este artículo: editor: el artículo no está asignado a tus grupos en ninguna tarifa"**, no fatal (0 PHP errors) | **0 rows** for VERIFY-QC-B (whole create blocked) | ✅ PASS — deny path live, no partial state |
| **c** | **Gestor** quick-create `VERIFY-QC-C` pvp=333.33 | 200, "Artículo VERIFY-QC-C guardado correctamente.", no denial | row exists, **pvp=333.33** (through the real production listener) | ✅ PASS — gestor allow with price |
| **d** | **CSRF failure**: POST `VERIFY-QC-D` with invalid token | 200, "Token de seguridad inválido. Recarga la página e inténtalo de nuevo." (in-controller `validateFormToken`; ddev log confirms `CSRF: Invalid token in (VentasArticulos)`), no fatal | **0 rows** for VERIFY-QC-D | ✅ PASS — existing flow, blocked before any dispatch/create |

**Orthogonality spot check (R-TAR-HOOK-003 deferral)**: the article-PAGE edit path still works — `index.php?page=ventas_articulo&ref=VERIFY-QC-C` renders the full edit form (`sreferencia=VERIFY-QC-C`, `spvp=333,33` locale-formatted) with no denial, no fatal. The Excel import path is untouched by the diff (audit) and its suites stay green at baseline. The quick-create gate is additive; both other creation paths behave exactly as before.

**Cleanup**: all users/roles/group rows/articles deleted; pristine check — `articulos` back to 1 row (original "Ref"), `fs_users` only `admin`, 0 leftover `VERIFYQC` rows in `fs_roles`/`fs_roles_access`/`fs_roles_users`/`tarif_grupo_roles`/`tarif_grupo_tarifas`/`tarif_grupo_usuarios`, 0 leftover `VERIFY-QC%` rows in `articulos`/`tarif_articulos`/`tarif_articulo_precios`.

---

## 4. Traceability (tasks ↔ commits)

| Task phase | Tasks | Commit | Verified |
|---|---|---|---|
| Phase 1 docs | 1.1, 1.2, 1.3 | `3715c49 docs(sdd): correct scenario count and article-page cite` | ✅ |
| Phase 2 RED tests | 2.1–2.12 | `4d65a20 test(catalogo_core): quick-create gate suite (RED)` | ✅ |
| Phase 3 GREEN gate | 3.1–3.4 | `73bddae feat(catalogo_core): gate quick-create pvp write` (+27 lines `VentasArticulos.php`) | ✅ |
| Phase 4 verify | 4.1–4.6 | recorded in `8bffb37 docs(sdd): tick quick-create gate tasks through apply` | ✅ |
| Phase 5 PR | 5.1 (apply note: PR not opened — no-push/no-PR apply; work-unit commits on default branch), 5.2 (this report) | — | 🔲 5.2 now closed by this report |
| tarifario composition | Beh-S4 (2.11 pattern) | `87b8a4a test(tarifario): quick-create gate composition suite (RED)` | ✅ |

26/27 task ticks `[x]`; the only open tick (5.2) is this verify report — correct. All four catalogo_core commits plus the tarifario composition commit exist in the expected order on the default branch.

---

## 5. Findings

### New observations (from this verification; not in the reviews)

- **SUGGESTION — CSRF enforcement on modern `PageController` pages is single-layer**: the modern flow (`src/Core/Base/Controller.php:run()` → `privateCore()`) bypasses `fs_controller::pre_private_core()`, so the global `validateCsrf()` layer does not apply to `ventas_articulos`/`ventas_articulo`; CSRF on the quick-create path rests entirely on the in-controller `validateFormToken()` (first statement of `nuevoArticulo`). Proved live (step d): the block message and the `CSRF: Invalid token in (VentasArticulos)` log line come from `validateFormToken`, not the global layer. Pre-existing architecture, spec-accurate (R-QCRT-006: "validateFormToken as its first statement"), zero impact on this change's compliance — recorded because any future refactor of that first statement would silently drop the only CSRF guard on this page.
- **INFO — R-QCRT-004 error_log nuance confirmed at HTTP level**: the `error_log` redirected in the gate suite's CSRF test is real (the observed ddev log line matches), which validates the test's `ini_set` redirect necessity. No action.

### Backlog restatement (approved review findings — NOT fixed, per instruction)

**catalogo_core review `b815a238b85c1299`** (sha256 `100effb2…`, state: approved):

- **RISK-1 (WARNING)** — R-QCRT-004's "no error_log" holds only for the controller's own catch block. The real production listener (`plugins/tarifario/Services/ArticlePermissionListener.php:50`) catches `\Throwable` internally, writes `error_log('[tarifario] ArticlePermissionListener: …')`, then denies with the generic reason **without rethrowing** — so on the real path the controller's fail-closed catch never runs and a stderr line IS emitted on a real listener failure. The deny still happens (secure); the spec scenario overclaims the no-log property on the composed path.
- **RISK-2 (SUGGESTION)** — Deny UX funnels `getDenialReason()` into the raw-rendered error slot (`themes/AdminLTE/view/header.html.twig:260` `{{ error|raw }}`). The event carries request-controlled `referencia`; a current/future listener reflecting request data into its reason would produce reflected HTML. Current producers (static strings, fail-closed constant) are safe; hardening: HTML-encode at the controller or auto-escape the error box.
- **RISK-3 (SUGGESTION)** — The composition test registers the real listener directly on `FSEventDispatcher`, bypassing tarifario's production `Init::init()` registration. Because the gate is default-allow with zero listeners, a silent registration regression would re-open the pvp vector while this test and the host suite stay green (separately covered by `PermissionListenerInitRegistrationTest`).
- **RISK-4 (SUGGESTION)** — The composition test's deny assertion rides on stubbed collaborators (`mockArticuloModel::get()`/`mockUserModel::get()` hard-return `false`), so the duplicate-reference early return and the listener's real DB-backed admin-flag load are never exercised on the deny path.

**tarifario review `5f3927aa1e9c84a3`** (sha256 `c6c15ca4…`, state: approved):

- **RISK-1 (WARNING)** — `stubDbEngine()` mutates core statics (`fs_db2::$engine`, `$table_list`, `$auto_transactions`) and `$GLOBALS['plugins']` without restoring in `tearDown()`; containment relies entirely on per-test process isolation (root `phpunit.xml` sets none — the attributes `#[RunTestsInSeparateProcesses]`/`#[PreserveGlobalState(false)]` are the only guard).
- **RISK-2 (SUGGESTION)** — Allow-path docblock overclaims "article created with the submitted pvp": the test asserts only the success message and `execCalls >= 1`, never the persisted pvp value. (Note: the live walkthrough step a/c empirically closes this gap for now — pvp 111.11/333.33 persisted exactly — but the test-level gap remains.)
- **RISK-3 (SUGGESTION)** — The cross-plugin skip guard checks only `catalogo_core/Controller/VentasArticulos.php` but the test also hard-depends on `Event/ArticlePermissionFilterEvent.php` and `model/core/articulo.php`+`impuesto.php`; a present-but-incomplete checkout would hard-fail instead of skip (low likelihood — files ship together).

---

## 6. Design coherence & correctness

- **D1 (dispatch placement)**: implemented exactly — dispatch block at `VentasArticulos.php:467-490` (after duplicate check, before `new \articulo()`), event constructed with `referencia`/`ACTION_EDIT_ARTICLE`/`nick`, `try { dispatch } catch (\Throwable) { deny('permission filter error') }`, deny → `new_error_msg('No tienes permisos para crear este artículo: '.getDenialReason())` + return. No deviation.
- **D2 (admin short-circuit)**: `if (!$this->user->admin)` wraps the entire dispatch — zero construction/dispatch for admins (R-QCRT-002 by construction, live-proven in step a).
- **D3 (behavioral harness)**: reflection `newInstanceWithoutConstructor` + injected `user`/`articulo`/`request`/`core_log` + `fs_db2::$engine` stub; real `FSEventDispatcher` + real listeners; "save never called" proven by message-stack invariant + zero `exec()` — matches design.
- **D4 (cross-plugin composition)**: direct listener registration + model stubs + `file_exists` skip guard; deviation-from-init caveat documented; deterministic in both suites.
- **D5 (deny UX)**: `new_error_msg` → core_log → `header.html.twig:258-260` on re-rendered list — confirmed live in step b.
- **D7 (archive note)**: recorded in tasks.md 5.2 — the archive phase must translate the English delta spec into the Spanish canonical at `plugins/catalogo_core/openspec/specs/articulos-quick-create-permission/spec.md`. No canonical exists yet (delta-only) → the merge/translation is an sdd-archive responsibility.

---

## 7. Next recommended

**sdd-archive** (`plugins/catalogo_core/openspec/changes/archive/2026-09-04-ventas-articulos-quick-create-gate/`) — all prerequisites met: verify-report has no CRITICAL issues, no unchecked implementation tasks, all three suites at baseline, no Composer dependency added (nothing to commit under `vendor/`), and the delta spec must be translated into the Spanish canonical during sync (D7). The two reviews' findings remain a follow-up backlog for future changes (e.g., listener no-log hardening, denial-reason encoding, composition test wiring proof) — they do not block archive.