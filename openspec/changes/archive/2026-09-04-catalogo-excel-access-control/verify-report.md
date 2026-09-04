# Verify Report: catalogo-excel-access-control

- **Change**: `catalogo-excel-access-control` (plugin-local SDD, `plugins/catalogo_core/openspec/`)
- **Scope**: R-CEXC-001/002/003/004/005/006/007 — admin-only Excel import/export default, role grants via `catalogo_excel_roles`, per-row permission gate, SSE re-check, settings page
- **Specs (binding)**: `specs/articulos-excel-access-settings/spec.md` (12 scenarios) + `specs/articulos-excel-import-export/spec.md` (14 scenarios) = **26 scenarios**
- **Design**: `design.md` (AD-1..AD-8); **Tasks**: `tasks.md` (4 phases, 3 PRs, all implementation tasks ticked; 4.4 archive constraint intentionally open)
- **Verify mode**: standard (Strict TDD inactive); DDEV; PHPUnit 11
- **Verifier**: sdd-verify executor (independent runtime verification)
- **Date**: 2026-09-04

---

## Verdict: **FAIL**

Two CRITICAL runtime bugs make the non-admin surface of this change **non-functional over HTTP**, and the settings page (admin surface) **unusable**:

1. **CRITICAL-1 — Settings page fatals for admins**: `controller/catalogo_excel_settings.php:88` instantiates `new ArticleExcelAccessPolicy()` (global namespace) but the class is `FSFramework\Plugins\catalogo_core\Services\ArticleExcelAccessPolicy`; the controller has no `use` statement and no FQCN. Runtime: `Fatal error: Class "ArticleExcelAccessPolicy" not found` on GET **and** POST — the admin can never open or save the settings page (R-CEXC-002 admin UX: scenarios 1, 9, 11, 12 broken at runtime).

2. **CRITICAL-2 — Every non-admin policy evaluation fatals**: `Services/ArticleExcelAccessPolicy.php:241` (`settingRaw()`) calls `new \fs_settings()` without the lazy `require_once` it uses for every other model (fs_user/fs_rol_user/fs_rol). `fs_settings` is not loaded by the standard bootstrap (`index.php`/`fs_app.php`/`Kernel.php` do not require it; the only legacy autoloader mapping it, `base/fs_autoload.php`, is not registered in the normal bootstrap; not in composer classmap). Runtime: any non-admin verdict evaluation — embedded `ventas_articulos` page render **and** all Excel actions, plus the standalone `process_excel_wizard.php` policy check — dies with `Fatal error: Class "fs_settings" not found` (HTTP 200 + HTML fatal page, not the spec-mandated 403 JSON/text). Admin flows only survive because `isAdmin()` short-circuits before `grantedRoles()`/`settingRaw()`.

Both bugs are **new** (introduced by this change's commits d722507 / b9cc76c) and **invisible to the suites** because every policy test drives the class through the `setSettingRaw()`/`setExistingRoles()`/injected-policy test setters, which bypass `settingRaw()` entirely, and the settings-page/ordering contracts are pinned with source-string assertions (the exact structural-vs-behavioral gaps the approved reviews flagged — REL-1/REL-2/REL-4).

Suites alone would say PASS-with-warnings; the mandated manual walkthrough (task 4.1) exposes the runtime failures. Per sdd-verify decision gates: **spec scenarios without a passing covering test at runtime = CRITICAL → FAIL**.

---

## 1. Suites (fresh runs via DDEV, 2026-09-04)

| Suite | Command | Tests | Assertions | Failures | Skipped | Warnings | Baseline match |
|---|---|---|---|---|---|---|---|
| Host (catalogo_core) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | 279 | 591 | **2** (pre-existing `testTwigViewUsesAutoEscape` in VentasArticuloControllerTest + VentasArticulosControllerTest) | 4 | 25 | ✅ 279/591, same 2 known failures |
| Tarifario plugin | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | 142 | 503 | 0 | 3 | 0 | ✅ 142/503 |
| Root | `ddev exec php vendor/bin/phpunit` | 1336 | 3414 | 0 | 11 | 0 | ✅ 1336/3414 |

- Flake protocol: root-suite `LegacyImportRegressionTest` SSE flake (~20%) did **not** trigger; green on first run — no rerun needed.
- No suite regression attributable to the change. All three suites match their documented baselines exactly.

## 2. Spec Coverage Audit S1–S26 (honest classification)

Legend: **B** = behavioral test (executes the function/service under test and asserts the verdict/behavior); **S** = structural pin (source-string/strpos/constructor-regex assertion; the pinned contract is NOT executed); **HTTP** = runtime evidence from the walkthrough (this report). Gaps cross-reference the review-backlog section (backlog = approved review findings, not fixed here).

| # | Scenario | Coverage | Covering test(s) | Runtime (walkthrough) | Gap → review finding |
|---|---|---|---|---|---|
| 1 | Admin persists a role list | S | `CatalogoExcelSettingsPageTest::testSavePersistsListAndReadsBack` (pure normalize + `$GLOBALS['config2']` round-trip; controller POST never executed) | ❌ CRITICAL-1 fatal | REL-1 (catalogo_core) |
| 2 | Absent setting grants no roles | B | `ArticleExcelAccessPolicyTest::testAbsentSettingGrantsNoRoles` | n/a (policy-level) | — |
| 3 | Invalid/unparsable grants no roles | B | `testUnparsableSettingGrantsNoRoles`, `testCommaOnlySettingGrantsNoRoles`, `testUnparsableSettingDoesNotError` | n/a | — |
| 4 | Naming discipline (grep-auditable) | S (partial) | `testNamingGrepAudit` — audits only 3 of 12 new files | ✅ independent audit: `grep -riE 'grupocliente\|grupo_clientes\|customer[_ ]?group'` over **all 12** change files → 0 hits | REL-3 (catalogo_core) |
| 5 | Granted role later deleted | B | `testGrantedRoleDeletedFromFsRolesIsNotGranted` | n/a | — |
| 6 | Admin dominance | B | `testAdminDominatesRegardlessOfGrants`, `testAdminDominatesEvenWithNoHeldRoles` | n/a | — |
| 7 | Non-admin with granted role | B | `testNonAdminWithGrantedRoleIsAllowed` | n/a | — |
| 8 | No cross-request staleness | B | `testNewInstanceReevaluatesAfterRevocation` (+ `testVerdictIsMemoizedWithinInstance`) | n/a | — |
| 9 | Admin can save the setting | S | same round-trip as S1 | ❌ CRITICAL-1 fatal | REL-1 |
| 10 | Non-admin cannot save the setting | S (+HTTP) | `testSettingsControllerUsesAdminGateConstructorArgs` (constructor regex only) | ✅ HTTP: framework `Acceso denegado` rendered (no fatal, no page logic reachable) | REL-1 (no behavioral test; walkthrough supplies the runtime proof) |
| 11 | Input whitelist | S | `testPostReadsOnlyWhitelistedField` (`preg_match` on source) | ❌ CRITICAL-1 fatal (unreachable) | REL-1 |
| 12 | CSRF-protected mutation | S | `testPostChecksCsrfBeforePersist` (strpos ordering) + `testSettingsViewIncludesCsrfField` | ❌ CRITICAL-1 fatal (unreachable) | REL-1 |
| 13 | Default-deny for non-admin with no setting | B (policy) / S (entry-point wiring) | `testNonAdminWithoutRolesIsDeniedByDefault`, `testProcessExcelActionDeniesKnownExcelActionsExplicitly`, `testExcelActionsListCoversEveryEntryPoint` | ❌ CRITICAL-2 fatal (page + all actions) | REL-2 (wiring not executed), REL-4 (anon-session test) |
| 14 | Granted-role user allowed | B (function-level) | `testPolicyDenialReturnsNullForGrantedUser` | ❌ blocked: granted non-admin fatals before grant logic (CRITICAL-2) | REL-5 (catalogo_core) / REL-1 (tarifario) composition bypass |
| 15 | Admin allowed everywhere | B (function-level) + HTTP | `testPolicyDenialReturnsNullForAdmin` | ✅ HTTP (partial): admin export produced xlsx; admin standalone start passed gate → wizard `Token inválido` SSE error; admin page renders Excel UI | — |
| 16 | Grant revoked mid-session | B (policy-level) | `testNewInstanceReevaluatesAfterRevocation` | ⚠️ HTTP impossible: revoke requires settings page (CRITICAL-1); config2 in-process limitation documented (see S24) | RISK-1/RES-2 (config2 vector) |
| 17 | Standalone 403 for non-granted direct URL | B (function-level) / S (wiring) | `testPolicyDenialReturnsPayloadForNonGrantedUser`, `testStandaloneRunAppliesPolicyGateAfterLogin`, `testStandaloneDenialResponds403JsonAndExits` | ❌ CRITICAL-2: standalone `start` with valid CSRF → HTML fatal page (`Class "fs_settings" not found`) instead of 403 JSON | REL-2 |
| 18 | Denied row skipped with reason in import log | S (partial B for CSV helper) | `testDenyRowWritesCsvRowAndBumpsStats` (behavioral helper), `testRowHookDenyPathSkipsRowAndContinues` (strpos `return;`) | ❌ unreachable for non-admin (CRITICAL-2) | REL-2 |
| 19 | Filter fires before pvp mutation and save | S | `testRowHookGatesBeforeCreateAndUpdateBranches` (strpos ordering); gate dispatch itself behavioral (`testPvpMappedDenyingListenerReturnsReason`) | ❌ unreachable (CRITICAL-2) | REL-2 |
| 20 | Allowed row applies pvp | B (gate-level) | `testPvpMappedZeroListenersAllows`; row application covered by pre-existing `ArticuloExcelImportWizardServiceTest` | ❌ unreachable for non-admin (CRITICAL-2); admin path skips dispatch (AD-5) | REL-5 (composition bypasses gate) |
| 21 | Admin rows pass by admin path | B | `testAdminAllowsWithoutDispatch` (AD-5 skip, zero dispatch) | ✅ HTTP: admin import path proceeds past policy gate | REL-2 (tarifario: `test_admin_import_row_is_allowed` mislabels the context) |
| 22 | Listener exception fails closed per row | B | `testListenerExceptionFailsClosedPerRow` | ❌ unreachable (CRITICAL-2) | — |
| 23 | Denial at stream start | S | `testSseStreamStartDeniedBeforeAnyImportEvent` (strpos) | ❌ CRITICAL-2 fatal instead of 403 | REL-2 |
| 24 | Revocation mid-stream | B (re-check fn) / S (wiring) | `testSsePolicyRecheckThrowsAccessDeniedExceptionOnVerdictFlip`, `testSsePolicyRecheckCarriesDenialText`, `testSsePolicyRecheckPassesWhenStillAllowed` (behavioral); `testSseRecheckWiredIntoProgressCallbackAndComplete` (strpos, READ-3 flaw) | ⚠️ HTTP unreachable; config2-based revocation invisible mid-stream (known limitation) | REL-2, READ-3, RISK-1/RES-2 (per-request config2 snapshot; GRANULARITY_LARGE_STEP=1000) |
| 25 | Hidden when denied (UI parity) | S | `testTwigGatesKeyOnCanImportExportFlag` (template strings) | ❌ FAIL: non-admin `ventas_articulos` fatals (CRITICAL-2) — UI "hidden" only because the page dies | REL-2 |
| 26 | Visible when granted (UI parity) | S (+HTTP) | same template test | ✅ HTTP (admin path): admin page renders Excel dropdown + import/export modals (27 modal/wizard hits) | — |

**Totals (honest)**: **15/26 behavioral** (S2,3,5,6,7,8,13*,14,15,16,17*,20,21,22,24* — *function-level, entry-point wiring structural) · **11/26 structural-pin** (S1,4,9,10,11,12,18,19,23,25,26) · **0 scenarios with zero coverage** (every scenario has at least a structural pin), but **the behavioral dimension is missing exactly where the reviews said it was**: settings POST contract (1,9,11,12 → REL-1), ordering/flow execution (18,19,23,24 → REL-2), grep scope (4 → REL-3), composition end-to-end gate (20,21 → REL-5 catalogo_core / REL-1 tarifario), mid-stream config2 revocation (24 → RISK-1/RES-2).

**And the runtime walkthrough proves the structural pins were hiding real breakage**: 8 scenarios fail at runtime (1,9,11,12 by CRITICAL-1; 13,17,23,25 by CRITICAL-2), 4 are unreachable for non-admin users (14,18,19,22), and 2 are only HTTP-provable on the admin path (15,26).

## 3. Manual Walkthrough (task 4.1) — executed over authenticated HTTP, DDEV

Base: `https://tarifario-07-2026.ddev.site`. Method precedent followed: GET login → extract `_csrf_token`/`_token` → POST `user`/`password` → session cookies (`FSSESS_*`, `user`, `logkey`, `auth_sig`). Fixtures were created additively and **deleted afterward** (DB verified back to pristine: only `admin` user, only `gestor_programa` role, all verify tables empty, git status clean).

Walkthrough fixture set (all removed post-run): role `ROL_EXCEL_VF`; non-admin user `verify_user` (holder of `ROL_EXCEL_VF`, `editor` in tarifa `INF2026` via `GRUPO_VF`); non-admin user `vf_nouser` (page access to `ventas_articulos` via role `ROL_PAGE_VF`, **no** Excel grant); admin user `verify_admin`; test article `VERIFY-ART-001` (pre-existing pvp 1.00) + `tarif_grupo_articulos` assignment; walkthrough xlsx with `VERIFY-ART-001` (10,50) and `VERIFY-ART-002` (25, unassigned → must be denied).

| Item | Expected (spec) | Actual | Result |
|---|---|---|---|
| **a. Admin saves a role list on settings page (POST + CSRF) → grants role** | Page renders; POST persists `ROL_EXCEL_VF`; success message | GET `index.php?page=catalogo_excel_settings` → `Fatal error: Class "ArticleExcelAccessPolicy" not found` (`catalogo_excel_settings.php:88`); POST unreachable (fatal occurs in `private_core()` before the POST branch) | ❌ **FAIL (CRITICAL-1)** |
| **b. Non-admin WITHOUT grant** | | | |
| b1. settings page (GET) | Framework `access_denied`, no page logic | HTTP 200, template renders `Acceso denegado` ×2 (framework admin gate) | ✅ PASS (scenario 10) |
| b2. `ventas_articulos` (page render) | Renders with Excel UI hidden (`can_import_export=false`) | `Fatal error: Class "fs_settings" not found` (`ArticleExcelAccessPolicy.php:241`) — page does not render | ❌ FAIL (CRITICAL-2) |
| b3. embedded `excel_import_sse` start | 403 JSON `{success:false,error}` | Same fatal (page dies in constructor before the action) | ❌ FAIL (CRITICAL-2) |
| b4. embedded `preview_excel` (POST) | 403 JSON | Same fatal | ❌ FAIL (CRITICAL-2) |
| b5. embedded `export_excel` | 403 text | Same fatal | ❌ FAIL (CRITICAL-2) |
| b6. embedded `download_import_log` | 403 text | Same fatal | ❌ FAIL (CRITICAL-2) |
| b7. standalone `process_excel_wizard.php?action=start` (valid CSRF) | 403 JSON, never reaches wizard | Valid CSRF obtained from rendered page; request → `Fatal error: Class "fs_settings" not found` (HTTP 200, HTML fatal page, size 1368) | ❌ FAIL (CRITICAL-2) |
| **c. Granted non-admin (verify_user)** | Excel UI visible; import with pvp rows applies; unassigned row skipped with reason in discarded CSV | **Unreachable**: `ventas_articulos` fatals in the constructor for ANY non-admin (CRITICAL-2) before any grant logic runs; the grant itself cannot be configured because the settings page fatals (CRITICAL-1). The import/CSV/SSE flows could not be exercised over HTTP for non-admin users. | ❌ **BLOCKED** (CRITICAL-1 + CRITICAL-2) |
| **d. Revoke mid-session (admin removes role from setting) → next request denied** | Settings page POST empty list → next request 403 | Settings page fatal (CRITICAL-1) → revoke via UI impossible. Known limitation documented: `fs_settings::get()` reads the per-request `$GLOBALS['config2']` snapshot, so an in-flight SSE stream cannot observe a settings-page revocation mid-stream (only DB-backed revocations) — the next *request* would re-read config2.ini and deny (RISK-1/RES-2 backlog). | ❌ **BLOCKED** via UI; limitation documented |

Admin-path positive controls (sanity, not in spec walkthrough but evidence the breakage is non-admin-only): admin `export_excel` → HTTP 200 `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet` (6772 B); admin standalone `start` with valid CSRF → HTTP 200 SSE stream, `event: error {"message":"Token inválido.","percent":0}` (wizard handling reached — policy passed); admin `ventas_articulos` renders the Excel dropdown + import/export modals (27 modal/wizard markup hits).

## 4. Traceability

- **Tasks ticks vs reality**: all implementation tasks (1.1–1.14, 2.1–2.10, 3.1–3.7, 4.1–4.3) ticked `[x]`; commits exist and match the work units: PR1 = d722507 (policy+test) + 63be87a (standalone gate+test) + e2f7969 (controller gate+test); PR2 = 401b46f (row gate+test) + 35d9bd8 (rowHook deny+CSV+test) + 015b4a7 (SSE re-check+exception+test); PR3 = b9cc76c (settings page+test+view); tarifario = 6a807d9 (composition test). Per-commit file attribution verified via `git show --stat`.
- **26/26 scenarios mapped**: the task traceability table lists all 26; every scenario has at least one test name in the 4 catalogo_core test files + the tarifario composition test (see coverage table).
- **Escape hatch 1.9 → PR2**: documented move verified — the SSE stream-start **gate** shipped in PR1 (`excel_import_sse` in `EXCEL_ACTIONS` → `emitExcelAccessDenied`, covered by `testProcessExcelActionDeniesKnownExcelActionsExplicitly` in e2f7969); only the **ordering test** `testSseStreamStartDeniedBeforeAnyImportEvent` moved to PR2 (added in 015b4a7). Tasks.md 1.9 note is accurate. (Note: READ-2 backlog flags that PR1 still blew the 400-line budget overall — the escape hatch as documented could not have brought it under; that is a governance deviation, restated in the backlog.)
- **4.4 archive constraint**: correctly left unchecked for sdd-archive; canonical spec merge into Spanish at archive time.
- **No core openspec entry**: verified `openspec/changes/` has no `catalogo-excel-access` entry — plugin-local SDD rule respected.

## 5. Findings (NEW — this verification, not previously in the reviews)

### CRITICAL
- **C-1 — Settings page is unusable (fatal) for admins**: `controller/catalogo_excel_settings.php:88` `new ArticleExcelAccessPolicy()` resolves to the global namespace; the class lives in `FSFramework\Plugins\catalogo_core\Services` and the controller has no `use`/FQCN. `Fatal error: Class "ArticleExcelAccessPolicy" not found` on every GET and POST → R-CEXC-002 admin UX (scenarios 1, 9, 11, 12) fails at runtime. Introduced by b9cc76c (PR3). Fix (not applied): add `use FSFramework\Plugins\catalogo_core\Services\ArticleExcelAccessPolicy;` or use the FQCN — one line.
- **C-2 — Every non-admin policy evaluation fatals**: `Services/ArticleExcelAccessPolicy.php:241` `settingRaw()` does `new \fs_settings()` with no lazy `require_once`, unlike its other model wiring. `fs_settings` is absent from the standard bootstrap (not required by `index.php`/`fs_app.php`/`Kernel.php`; the only autoloader mapping it, `base/fs_autoload.php`, is not registered; 0 hits in `vendor/composer/autoload_classmap.php`). Result: any non-admin verdict (embedded `ventas_articulos` render and every Excel action; standalone `process_excel_wizard.php` policy check) dies with `Class "fs_settings" not found` → HTTP 200 HTML fatal page instead of the spec-mandated explicit 403. Admin flows survive only via the `isAdmin()` short-circuit. Introduced by d722507 (PR1). Fix (not applied): lazy `require_once FS_FOLDER . '/base/fs_settings.php';` in `settingRaw()` (mirroring the class's own lazy model wiring) — one line.
- **C-3 (consequence) — Non-admin runtime surface is untested green**: the host/root suites pass 100% while the change is non-functional for every non-admin user over HTTP, because the tests drive the policy via `setSettingRaw()`/`setExistingRoles()`/injected-policy setters (bypassing `settingRaw()`) and pin controller wiring via source strings. This is the concrete manifestation of review REL-1/REL-2: the structural pins did not catch two class-resolution errors that only execute in the real bootstrap. Until C-1/C-2 are fixed, no test can be considered proof of scenarios 1, 9–14, 17–25.

### WARNING
- **W-1 — Walkthrough blocked, runtime behavior of scenarios 18–24 unverified**: the per-row gate → listener → denied-row CSV and the SSE mid-stream termination could not be exercised over HTTP for any non-admin user (blocked by C-2). The only HTTP-provable positive paths are admin (15, 26) and the framework settings denial (10). After C-1/C-2 are fixed, the walkthrough must be re-run in full (a–d).
- **W-2 — Re-test-after-fix protocol needed**: C-1/C-2 are exactly the class of bug the review's structural pins cannot see; after the one-line fixes, the walkthrough steps b2–b7 and c must pass before archive.

### SUGGESTION
- **S-1 — Root-cause hygiene**: both fatals are missing-import errors of the same shape (namespaced class + unqualified reference / non-autoloaded legacy class). A lint gate (`php -l` per new file plus a smoke instantiation of each new controller under the real bootstrap) would have caught both.
- **S-2 — Walkthrough harness**: the HTTP walkthrough is the only artifact that caught C-1/C-2; consider documenting it as a repeatable script (login → settings POST → import → CSV assertions) in the plugin `tools/` for future access-control changes.

## 6. Review Backlog (restated — approved findings, NOT fixed here; per instruction these are follow-up, not this verifier's scope)

### catalogo_core review `review-05dafbc689bed6b7` (APPROVED, sha256:a9775c48cff0…)
- **RISK-1 / RES-2 (WARNING)**: SSE per-event re-check cannot detect a config2-based grant revocation mid-stream (per-request `$GLOBALS['config2']` snapshot); only DB-backed revocations detected. Plus `GRANULARITY_LARGE_STEP=1000` allows up to ~999 pvp mutations before termination on ≥10000-row imports. (S24)
- **RISK-2 (WARNING)**: quick-create article form (`POST nreferencia`) writes `pvp` with no policy gate and no filter dispatch — pre-existing single-row vector outside R-CEXC-003.
- **RISK-3 (WARNING)**: settings page not admin-only by construction — `fs_controller` `$admin` arg is documented OBSOLETO; real gate is `have_access_to('catalogo_excel_settings')`. Design claim "constructor args block non-admins at the framework level" overstated. (Walkthrough b1 shows the default no-access-row case works, but an admin granting the page to a role would open it.)
- **RISK-4 (SUGGESTION)**: numeric `fs_roles.codrol` persisted unquoted → re-parsed as int → silently ineffective.
- **RISK-5 (SUGGESTION)**: explicit-403 contract degrades to 200 when headers already sent.
- **RES-1 / REL-6 (WARNING)**: fresh policy instance per SSE progress event → ~3 SELECTs/event; fine granularity (<10000 rows) emits per-row → ~15000 extra queries for a 5000-row import; AD-3 per-request caching not realized on this path.
- **RES-3 (WARNING)**: denial observability asymmetric — entry-point 403s user-facing only; listener exceptions reduced to generic CSV reason, real exception swallowed/unlogged.
- **RES-4 (SUGGESTION)**: DB failure (`fs_mysql::select` returns false) → verdict DENY indistinguishable from a real revocation.
- **RES-5 (SUGGESTION)**: final pre-`complete` re-check runs after the row loop committed and after `@unlink`; a throw presents a fully-committed import as failed (committed-but-reported-failed edge).
- **RES-6 (SUGGESTION)**: rollback is sound LIFO; note that "revert PR1 alone" only restores the tautological gate when PR2/PR3 are absent (merged chain code calls PR1 symbols).
- **READ-1 (WARNING)**: three new classes in one namespace use three prefixes + two languages (`Article`/`Articulo`/`Catalogo`, `Access`/`Permission`).
- **READ-2 (WARNING)**: PR1 measured ~1086 authored lines vs ~390 forecast; escape hatch (moving only the 1.9 ordering test) could not bring it under budget; deviation unrecorded in tasks.md.
- **READ-3 (SUGGESTION)**: `testSseRecheckWiredIntoProgressCallbackAndComplete` uses whole-file `strpos` → trivially true first occurrence; never verifies the final re-check precedes `complete`.
- **READ-4 (SUGGESTION)**: composition test not in the 16-file candidate diff (lives in the separate tarifario repo) — diff/spec file inventory disagreement.
- **READ-5 (SUGGESTION)**: proposal.md vs design.md cite different line numbers (111-114 vs 99-103) for the same pre-change tautological gate.
- **READ-6 (SUGGESTION)**: mixed client-facing language — English admin-menu title vs Spanish body; Spanish `denialMessage()` vs English CSRF/session denial strings parsed by the same wizard client.
- **REL-1 (WARNING)**: settings-page POST contract pinned structurally, not behaviorally — `testPostReadsOnlyWhitelistedField` (`preg_match $_POST[` count) and `testPostChecksCsrfBeforePersist` (strpos ordering); controller POST branch never executed. **C-1/C-2 prove the consequence.**
- **REL-2 (WARNING)**: mutation-ordering contracts (S18/19/23/24) pinned by source-position strpos; no executed import loop proving denial ⇒ save unreachable; no driven SSE catch proving `error` event terminates the stream.
- **REL-3 (SUGGESTION)**: naming grep-audit covers 3 of ~8 new files (policy/gate/dispatch/VentasArticulos/other tests never audited). (Independent all-files audit by this verifier: 0 hits.)
- **REL-4 (WARNING)**: `testPolicyDenialWithoutExplicitPolicyDeniesAnonymousSession` deterministic only under host-suite `processIsolation`; in root suite, singleton/session/global state can flip the verdict.
- **REL-5 (SUGGESTION)**: composition test bypasses `ArticuloExcelPermissionGate` — full gate → event → real-listener import chain never exercised end-to-end.

### tarifario review `review-8e51fcf1191afa7e` (APPROVED, sha256:2ef8f4d00b75…)
- **REL-1 (WARNING)**: composition test bypasses the real host per-row gate (`ArticuloExcelPermissionGate::check`, `process_excel_wizard_dispatch.php:361`) and the init registration path; manually instantiates the listener → same dispatch shape as `ArticlePermissionListenerTest::dispatch()`.
- **REL-2 (WARNING)**: `test_admin_import_row_is_allowed` mislabels the import context — in the real per-row flow an admin row never reaches the listener (AD-5 short-circuit); the pinned scenario cannot occur on an import row.
- **REL-3 (SUGGESTION)**: the only novel assertion (action independence) uses a fabricated `'import_article'` action the host gate never emits (AD-2 hardcodes `ACTION_EDIT_ARTICLE`); import-relevant property is pinned by catalogo_core's gate dispatch-count tests.
- **REL-4 (SUGGESTION)**: `Init` statics (`hooksRegistered`/`listenerRegistered`) not reset in this file — harmless today, latent leak if the file ever calls `Init::init()`.

## 7. Decision-Gate Record

| Gate | Result |
|---|---|
| All implementation tasks complete | ✅ (4.4 archive constraint intentionally deferred) |
| Suites fresh + baseline match | ✅ host 279/591 (2 known), tarifario 142/503, root 1336/3414 |
| Spec scenario has passing runtime covering test | ❌ scenarios 1,9,11,12,13,17,23,25 FAIL at runtime; 14,18,19,22 unreachable — **CRITICAL** |
| Design deviation | ❌ design AD-4 promised explicit 403s "never silent" — runtime delivers fatals (200 + HTML error) for every non-admin surface; AD-7 settings page unusable |
| New CRITICAL findings | 2 class-resolution runtime fatals (C-1, C-2) |

## 8. Next Recommended

- **`sdd-archive`: NOT yet.** Archive is blocked while CRITICAL C-1/C-2 are open (archive workflow requires "verify-report has no CRITICAL issues"). Recommended path: orchestrator schedules a fix change (two one-line import fixes), re-runs the full manual walkthrough a–d, and re-verifies before archive.
- After fix: re-run all three suites + the complete HTTP walkthrough (a–d); then archive per the plugin-SDD skill (archive to `plugins/catalogo_core/openspec/changes/archive/YYYY-MM-DD-catalogo-excel-access-control/`, merge delta specs into the Spanish canonical spec, confirm no core `openspec/` entry).

## 9. Risks (residual)

- The two fatals mask each other's blast radius: fixing C-1 alone leaves the settings page fatal-free but the non-admin surface still dead (C-2); fixing C-2 alone leaves the admin unable to configure grants (C-1).
- Review backlog (RISK-1/RES-1/RISK-3/RISK-2, etc.) remains unaddressed by design of this phase; several (RISK-2 quick-create pvp vector) are independent security-relevant gaps that should be scheduled separately.
- The walkthrough fixture process (role/user/tarifa-group/article creation + teardown) worked cleanly and left the DB pristine; re-running it post-fix requires recreating the same fixture set.
---

## Resolution Addendum — 2026-09-04 (post-verify correction)

> Original FAIL verdict, findings (C-1/C-2/C-3, W-1/W-2, S-1/S-2) and §1–§9 above
> are preserved verbatim. This addendum records the correction transaction, the
> re-run evidence and the corrected verdict.

### Correction scope

Post-verify correction change executed by the sdd-apply correction agent
(strict TDD RED → GREEN per fix). Scope: the two CRITICAL class-resolution
runtime fatals (C-1, C-2) plus bootstrap-level regression tests that exercise
the real class-loading path (C-3). No spec/design changes; review backlog
(§6) untouched by design.

### Fixes applied (2 one-line runtime fixes)

| Bug | File:line | Fix |
|---|---|---|
| C-1 | `controller/catalogo_excel_settings.php:88` | Added `use FSFramework\Plugins\catalogo_core\Services\ArticleExcelAccessPolicy;` — file-scoped alias so `new ArticleExcelAccessPolicy()` in the global-namespace controller resolves to the namespaced class (matches the plugin's `use` convention in `Controller/VentasArticulo.php` and the FQCN convention in `process_excel_wizard_dispatch.php`) |
| C-2 | `Services/ArticleExcelAccessPolicy.php:241` (`settingRaw()`) | Added lazy `require_once FS_FOLDER . '/base/fs_settings.php';` before `new \fs_settings()` — mirrors the class's own lazy model wiring (`userModel()`/`rolUserModel()`/`rolModel()`) and the plugin's `base/` lazy-load pattern (`ArticuloExcelRowUpdater::normalizePrice`) |

### Regression tests (RED → GREEN, host suite)

New file `tests/ExcelAccessControlBootstrapRegressionTest.php` (2 tests).
Deliberately does NOT preload `base/fs_settings.php` and does NOT use the
`@internal` test setters — the exact seams that let C-1/C-2 escape the
original suite (C-3).

| Test | Real path exercised | RED (pre-fix) | GREEN (post-fix) |
|---|---|---|---|
| `testGrantedRolesLazyLoadsFsSettingsWithoutTestSetters` | `grantedRoles()` → `computeGrantedRoles()` → `settingRaw()` → `new \fs_settings()` via composer autoload only | `Error: Class "fs_settings" not found` at `ArticleExcelAccessPolicy.php:241` | `[]` fail-closed with clean config2, no fatal |
| `testSettingsPrivateCoreResolvesPolicyClass` | controller file's own class resolution: `new ArticleExcelAccessPolicy()` inside `private_core()` (constructor-free instance via Reflection, `REQUEST_METHOD=GET`) | `Error: Class "ArticleExcelAccessPolicy" not found` at `catalogo_excel_settings.php:88` | runs clean; `roles` populated; `granted_codes == []` |

RED run: `Tests: 2, Errors: 1, Failures: 1`. GREEN run: `OK (2 tests, 3 assertions)`.

Note on the C-1 test construction: the legacy `fs_controller` constructor
requires a booted Kernel + DB, so `new catalogo_excel_settings()` cannot run
in the unit context; the test executes the exact failing statement
(`private_core()` line 88) via `ReflectionClass::newInstanceWithoutConstructor`
+ reflection invocation, which is the faithful unit-level reproduction of the
runtime fatal.

### Suites (fresh runs via DDEV, post-fix, 2026-09-04)

| Suite | Command | Tests | Assertions | Failures | Skipped | Warnings | Baseline match |
|---|---|---|---|---|---|---|---|
| Host (catalogo_core) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | 281 | 594 | **2** (same pre-existing `testTwigViewUsesAutoEscape` ×2, unchanged) | 4 | 25 | ✅ +2 tests / +3 assertions from the regression file; no new failures |
| Tarifario plugin | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | 142 | 503 | 0 | 3 | 0 | ✅ exact baseline (plugin untouched) |
| Root | `ddev exec php vendor/bin/phpunit` | 1338 | 3417 | 0 | 11 | 0 | ✅ +2 tests / +3 assertions; `failOnWarning`/`failOnRisky` clean |

### Manual walkthrough re-run (a–d) — same §method + fixture set

Base `https://tarifario-07-2026.ddev.site`; login GET → `_csrf_token` →
POST `user`/`password` → session cookies. Fixture set recreated additively
(role `ROL_EXCEL_VF`, `ROL_PAGE_VF`; users `verify_admin`/`verify_user`/
`vf_nouser`; tarifa group `GRUPO_VF` → tarifa `INF2026` `editor` + article
assignment; articles `VERIFY-ART-001` pvp 1.00 / `VERIFY-ART-002` unassigned;
walkthrough xlsx `VERIFY-ART-001`(10,50) + `VERIFY-ART-002`(25)) and deleted
afterward — DB verified pristine (users=1 admin, roles=1 `gestor_programa`,
`fs_roles_users`=0, `articulos`=1, all `tarif_grupo_*`=0, `fs_roles_access`=10,
`config2.ini` md5 identical to pre-run backup, no `catalogo_excel_roles` key left).

| Item | Expected (spec) | Actual (post-fix) | Result |
|---|---|---|---|
| **a. Admin saves a role list** | Page renders; POST persists `ROL_EXCEL_VF`; success message | GET 200 (23.7 KB, no fatal, page title ×2); POST 200 `Configuración guardada.`, granted list shows `ROL_EXCEL_VF`; `config2.ini` contains `catalogo_excel_roles = 'ROL_EXCEL_VF'` | ✅ **PASS** (C-1 resolved) |
| **b. Non-admin WITHOUT grant (vf_nouser)** | | | |
| b1. settings page (GET) | Framework `access_denied` | 200, `Acceso denegado` rendered, no page logic | ✅ PASS |
| b2. `ventas_articulos` (page render) | Renders, Excel UI hidden | 200 (22.4 KB), 0 hits of `modal-importar-articulos-excel` / `modal-exportar-articulos-excel` / wizard script — UI HIDDEN, no fatal | ✅ **PASS** (C-2 resolved) |
| b3. embedded `excel_import_sse` start | 403 JSON | HTTP 403 `{"success":false,"error":"No tienes permiso..."}` | ✅ PASS |
| b4. embedded `preview_excel` (POST) | 403 JSON | HTTP 403, same JSON payload | ✅ PASS |
| b5. embedded `export_excel` | 403 text | HTTP 403, plain-text denial message | ✅ PASS |
| b6. embedded `download_import_log` | 403 text | HTTP 403, plain-text denial message | ✅ PASS |
| b7. standalone `process_excel_wizard.php?action=start` (valid CSRF) | 403 JSON, never reaches wizard | HTTP 403 (146 B) `{"success":false,"error":"No tienes permiso..."}` — no HTML fatal, wizard not reached | ✅ **PASS** (C-2 resolved) |
| **c. Granted non-admin (verify_user)** | Excel UI visible; import with pvp applies; unassigned row skipped with reason in discarded CSV | UI visible (2 import + 2 export modal hits + wizard script); `preview_excel` upload → `{"success":true,"token":...}`; SSE stream: `start` → `progress` ×2 → `complete` with `stats {"actualizados":1,"descartadas":1,"errores":1}`; DB: `VERIFY-ART-001` pvp 1.00 → **10.5** (applied), `VERIFY-ART-002` **not created** (denied before create); discarded CSV: `permiso_denegado;VERIFY-ART-002;"editor: el artículo no está asignado a tus grupos en ninguna tarifa"` | ✅ **PASS** (previously BLOCKED; first successful HTTP execution of scenarios 18/19/20) |
| **d. Revoke mid-session** | Admin saves empty role list → next request denied | Admin POST empty list → `Configuración guardada.`; verify_user next request: `ventas_articulos` renders with UI HIDDEN (0 modal hits) and `export_excel` → HTTP 403 text | ✅ **PASS** |
| config2 in-process limitation | Documented | Confirmed and documented unchanged: `fs_settings::get()` reads the per-request `$GLOBALS['config2']` snapshot, so an in-flight SSE stream cannot observe a settings-page revocation mid-stream; the **next request** re-reads `config2.ini` and denies (demonstrated in d) | documented |

### Verdict update (corrected)

- **CRITICAL C-1 — RESOLVED**: settings page renders and persists for admins over HTTP; unit regression `testSettingsPrivateCoreResolvesPolicyClass` guards the class-resolution path.
- **CRITICAL C-2 — RESOLVED**: every non-admin surface now returns the spec-mandated explicit 403 (JSON for fetch/EventSource surfaces, text for link-navigation surfaces) instead of a fatal HTML page; unit regression `testGrantedRolesLazyLoadsFsSettingsWithoutTestSetters` guards the lazy-load path.
- **CRITICAL C-3 — ADDRESSED**: the new regression tests exercise the real class-loading path (no setters, no `fs_settings` preload, controller's own file-scoped resolution) in the host suite's fresh-process isolation; the root suite adds the behavioral assertions in the shared-process context.
- **Spec scenario runtime coverage**: scenarios 1, 9, 11, 12 (settings) and 13, 17, 23, 25 (non-admin denial/UI parity) now PASS at runtime; 14, 18, 19, 22 (granted/denied-row paths) now PASS over HTTP (c); 15, 26 (admin paths) re-confirmed; 16/24 revocation coverage per §6 backlog (config2 in-process limitation) documented in d.
- **Corrected overall verdict: PASS** for this change's runtime surface. Suites match baselines (only the 2 pre-existing `testTwigViewUsesAutoEscape` failures remain in the host suite). Archive gate (§8) may now proceed: verify-report has no open CRITICAL issues.

### Residual (unchanged)

Review backlog §6 (RISK-1..RES-6, READ-1..6, REL-1..5 both reviews) remains
unaddressed by design of this correction; W-1/W-2 are superseded by the
walkthrough re-run above; S-1/S-2 (lint gate, walkthrough harness) remain
suggestions for future changes. No core `openspec/` entry created; no
`plugins/tarifario` files touched.
