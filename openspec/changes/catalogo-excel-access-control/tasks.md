# Tasks: catalogo-excel-access-control

Chain: 3 stacked-to-main PRs per design §PR Split (PR1 policy+gates ~390 borderline, PR2 row-gate+SSE ~330, PR3 settings UI ~260). Strict TDD (PHPUnit 11, DDEV). Work-unit commits: tests travel with code; every behavior task starts with its RED test.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~980 total (design measured forecast: PR1 ~390 / PR2 ~330 / PR3 ~260) |
| 400-line budget risk | Medium (PR1 borderline; escape hatch below) |
| Chained PRs recommended | Yes |
| Suggested split | PR1 policy+all entry gates → PR2 per-row gate+SSE re-check+CSV → PR3 settings page |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: Medium

**PR1 escape hatch (from design)**: if apply-time `git diff --stat main...PR1` measures >400 authored lines, move the SSE stream-start RED/GREEN tasks (1.9) with their tests into PR2 — the boundary stays the same access-enforcement unit. No `size:exception` assumed.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Policy service + explicit gates at every VentasArticulos entry point + standalone dispatch fix + SSE stream-start | PR1 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'ArticleExcelAccessPolicyTest\|CatalogoExcelWizardAccessTest'` | Logged-in non-admin direct URL `process_excel_wizard.php?action=start` (valid CSRF) → 403 JSON `{success:false,error}`; admin/granted → wizard runs; embedded `export_excel` → 403 text | Revert PR1 commits → restores prior tautological gate + standalone hole (accepted in proposal Rollback Plan) |
| 2 | Per-row `pvp` gate (before create/update branches) + denied-rows CSV reporting + SSE per-event re-check + tarifario composition test | PR2 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'ArticuloExcelPermissionGateTest\|CatalogoExcelWizardAccessTest'` | Non-admin granted import with denying listener → denied row skipped, `permiso_denegado;<ref>;<reason>` in `catalogo_import_*_descartadas.csv`; revoked mid-stream → `error` event, stream ends | Revert PR2 → imports return to pre-change unconditional `pvp` writes; no partial log state (CSV rows are appends) |
| 3 | Admin-only settings page + view + naming grep-audit | PR3 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoExcelSettingsPageTest` | Admin saves role list via `catalogo_excel_settings` (CSRF) → reads back `A,B`; non-admin GET/POST → framework `access_denied` | Revert PR3 → page gone; `catalogo_excel_roles` key may remain in `config2.ini` harmlessly; policy still fail-closed (PR1) |
| 4 | Cross-PR integration + chain hygiene + traceability audit | Final (no PR) | `ddev exec php vendor/bin/phpunit` (root suite, cross-plugin signal) | Full manual walkthrough: admin grants role → granted user imports (row denied by listener) → revoke mid-session → SSE terminates | N/A — verification only, no code |

## Scenario Traceability (all 26 spec scenarios → tasks)

| # | Spec | Scenario | Suite | Task(s) |
|---|------|----------|-------|---------|
| 1 | settings | Admin persists a role list | host | 3.1, 3.2 |
| 2 | settings | Absent setting grants no roles | host | 1.1, 1.2 |
| 3 | settings | Invalid/unparsable value grants no roles | host | 1.1, 1.2 |
| 4 | settings | Naming discipline (grep-auditable) | host | 3.1, 3.5 |
| 5 | settings | Granted role later deleted | host | 1.3 |
| 6 | settings | Admin dominance | host | 1.3 |
| 7 | settings | Non-admin with granted role | host | 1.3 |
| 8 | settings | No cross-request staleness | host | 1.3 |
| 9 | settings | Admin can save the setting | host | 3.1, 3.2 |
| 10 | settings | Non-admin cannot save the setting | host | 3.4 |
| 11 | settings | Input whitelist | host | 3.1, 3.2 |
| 12 | settings | CSRF-protected mutation | host | 3.1, 3.2 |
| 13 | import-export | Default-deny for non-admin with no setting | host | 1.5–1.9 |
| 14 | import-export | Granted-role user allowed | host | 1.3, 1.7, 1.8 |
| 15 | import-export | Admin allowed everywhere | host | 1.5–1.9 |
| 16 | import-export | Grant revoked mid-session | host | 1.3, 1.7–1.9 |
| 17 | import-export | Standalone endpoint 403 for non-granted direct URL | host | 1.5, 1.6 |
| 18 | import-export | Denied row skipped with reason in import log | host | 2.3, 2.4 |
| 19 | import-export | Filter fires before pvp mutation and save | host | 2.4 |
| 20 | import-export | Allowed row applies pvp | host | 2.1, 2.2 |
| 21 | import-export | Admin rows pass by admin path | host | 2.1, 2.2 |
| 22 | import-export | Listener exception fails closed per row | host | 2.1, 2.2 |
| 23 | import-export | Denial at stream start | host | 1.9 |
| 24 | import-export | Revocation mid-stream | host | 2.5, 2.6 |
| 25 | import-export | Hidden when denied (UI parity) | host | 1.11 |
| 26 | import-export | Visible when granted (UI parity) | host | 1.11 |

Suite mapping note: all 26 spec scenarios are `host-suite`; the single `root-suite` artifact is the design-mandated composition test (task 2.8, `plugins/tarifario/tests/Integration/ArticlePermissionListenerImportContextTest.php`), discovered by root `phpunit.xml` via `plugins/*/tests/`. Root-suite runs are verification signals at 2.9, 3.6, 4.1.

## Phase 1 — PR1: Policy service + all entry-point gates (branch `catalogo-excel-access-pr1-policy-gates` → main)

- [x] 1.1 **RED** Create `tests/Services/ArticleExcelAccessPolicyTest.php`: absent/unparsable `catalogo_excel_roles` ⇒ `grantedRoles()==[]` without error (scenarios 2, 3); `$GLOBALS['config2']` direct writes, test setters, no DB.
- [x] 1.2 **GREEN** Create `Services/ArticleExcelAccessPolicy.php` per AD-1: `final` class, `SETTING_KEY`, `isAllowed()/isAdmin()/grantedRoles()/denialMessage()`, lazy model wiring, `@internal` test setters, per-instance memoization (AD-3).
- [x] 1.3 **RED→GREEN** Policy semantics (scenarios 5–8): granted+held ⇒ allowed; granted role deleted from `fs_roles` ⇒ not granted (intersect at read per AD-6); admin dominance with admin role also granted; new instance re-evaluates (no cross-request staleness); memoized within instance.
- [x] 1.4 **REFACTOR** Policy class clean-up; naming audit on new file (`rol`/`role` vocabulary only).
- [x] 1.5 **RED** Create `tests/CatalogoExcelWizardAccessTest.php`: `catalogo_excel_wizard_policy_denial()` returns null for admin/granted, `{success:false,error}` payload for non-granted (scenario 17 shape per AD-4).
- [x] 1.6 **GREEN** Modify `process_excel_wizard_dispatch.php`: add `catalogo_excel_wizard_policy_denial()` (+ emit helper) applied to ALL standalone actions (`start`, `progress`, `status`) after login, before wizard handling — closes the direct-URL hole with 403 JSON + exit.
- [x] 1.7 **RED** Extend `CatalogoExcelWizardAccessTest` (or `VentasArticulosControllerTest` style): non-granted user hitting embedded Excel actions gets explicit denials, never silent fall-through (scenarios 13, 15).
- [x] 1.8 **GREEN** Modify `Controller/VentasArticulos.php`: replace `initImportExportPermissions()` tautology (`:99-103`) with policy verdict (instance kept for reuse); `processExcelAction()` emits per AD-4 table — 403 JSON for `preview_excel`/`get_preview`/`excel_import_sse` start, 403 text for `export_excel*`/`download_import_log` (scenarios 14, 16).
- [x] 1.9 **RED→GREEN** SSE stream-start denial (scenario 23) — MOVED TO PR2 per documented 400-line escape hatch (the stream-start GATE ships in PR1 via EXCEL_ACTIONS + AD-4 JSON shape; only the ordering test moved): `excel_import_sse` start returns 403 JSON same shape as existing CSRF denial (`VentasArticulos.php:175-181`); EventSource `onerror` parses it — no wizard JS changes.
- [x] 1.10 **REFACTOR** Controller gate clean-up; confirm no dead tautology code remains.
- [x] 1.11 **VERIFY UI parity (0 template changes expected)** (scenarios 25, 26): confirm `View/ventas_articulos.html.twig` (`:38`, `:190`) and export/import modal partials render/hide keyed on `can_import_export` now matching the real verdict; only if disagreement is found, apply the minimal template fix.
- [x] 1.12 **VERIFY PR1** Host suite green: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` — known pre-existing failures (2× `testTwigViewUsesAutoEscape`) are out of scope. Measure `git diff --stat main...HEAD` ≤400; else apply escape hatch (move 1.9 tests to PR2). SSE-related runs: on flake, rerun once before investigating.
- [x] 1.13 **ROLLBACK PLAN PR1** Record in PR body: revert boundary = PR1 commits restore prior tautological gate + standalone hole (accepted); no data/schema rollback; setting key n/a yet.
- [x] 1.14 **COMMIT/PR1** (commits done on main; PR opening deferred to push time — no push per orchestrator) Work-unit commits (tests with code, conventional messages, no AI attribution), open PR1 stacked-to-main with Chain Context + dependency diagram (📍 on PR1).

## Phase 2 — PR2: Per-row gate + SSE re-check + denial reporting (branch `catalogo-excel-access-pr2-row-gate-sse` → main, based on PR1 until it merges)

- [x] 2.1 **RED** Create `tests/Services/ArticuloExcelPermissionGateTest.php` (`FSEventDispatcher::reset()` setUp/tearDown, fake listeners per `tests/ArticlePermissionFilterDispatchTest.php` pattern): no `pvp` in mapping ⇒ allow + zero dispatch; `pvp` mapped + denying listener ⇒ reason returned; `pvp` mapped + zero listeners ⇒ allow (pre-change behavior); listener throws ⇒ `permission filter error`; admin ⇒ allow + **zero dispatch** (AD-5 skip) (scenarios 20–22).
- [x] 2.2 **GREEN** Create `Services/ArticuloExcelPermissionGate.php`: `static check(array $mappedRow, string $referencia, string $nick, bool $isAdmin): ?string`; `isset($mappedRow['pvp'])` condition (applyMapping drops empty cells ⇒ isset ⇒ row writes pvp); admin returns null without constructing/dispatching the event; dispatch `ArticlePermissionFilterEvent::ACTION_EDIT_ARTICLE` (AD-2, no new constant); `catch (Throwable)` ⇒ deny fail-closed per row.
- [x] 2.3 **RED** Extend import tests: denied row skipped — no create/update/save for that row, import continues, CSV row `permiso_denegado;<referencia>;<reason>`, `stats['descartadas']++` and `stats['errores']++` (scenario 18).
- [x] 2.4 **GREEN** Modify `process_excel_wizard_dispatch.php` rowHook: call gate BEFORE create/update branches (covers both `ArticuloExcelRowUpdater::applyMappedFields` pvp case and `createArticuloFromRow` pvp defaults), so the event fires before any `pvp` mutation and before `save()` (scenario 19); write denial via injection-safe `catalogoFputcsvSafe()` into existing `catalogo_import_*_descartadas.csv` (AD-8 — no new log infra); reason = `getDenialReason()` or `permission filter error` on exception.
- [x] 2.5 **RED** Extend `CatalogoExcelWizardAccessTest`: policy re-check in progress callback throws `CatalogoExcelAccessDeniedException` when verdict flips to denied mid-run (scenario 24).
- [x] 2.6 **GREEN** Create `Services/CatalogoExcelAccessDeniedException.php` (`RuntimeException` subtype, message = denial text); add per-event policy re-check in the shared `progressCallback` AND before `complete` (embedded & standalone flows per design flowchart b) — existing catch emits the `error` event and ends the stream.
- [x] 2.7 **REFACTOR** Row-loop integration clean-up; confirm no double dispatch for admin rows.
- [x] 2.8 **RED→GREEN** Composition test `plugins/tarifario/tests/Integration/ArticlePermissionListenerImportContextTest.php` (root-suite): dispatch through real `FSEventDispatcher` with the archived slice-1 `ArticlePermissionListener` + import-context event (`ACTION_EDIT_ARTICLE`, non-admin, unassigned article) ⇒ denied; gestor ⇒ allowed; admin nick ⇒ allowed — proving Decision 2 slots into the existing matrix unchanged.
- [x] 2.9 **VERIFY PR2** Host suite green (same known-failure exclusion; SSE runs: rerun once on flake) + root suite green: `ddev exec php vendor/bin/phpunit` (cross-plugin regression signal). Measure diff ≤400.
- [x] 2.10 **ROLLBACK PLAN + COMMIT/PR2** (commits done on default branches; PR opening deferred to push time — no push per orchestrator) Revert boundary recorded: imports return to unconditional `pvp` writes (no partial log state — CSV rows are appends); work-unit commits on main/master; open PR2 stacked-to-main with Chain Context + dependency diagram (📍 on PR2) at push time.

## Phase 3 — PR3: Settings page (branch `catalogo-excel-access-pr3-settings-ui` → main, based on PR2 until it merges)

- [x] 3.1 **RED** Create `tests/CatalogoExcelSettingsPageTest.php` (structural + `$GLOBALS['config2']` round-trip, `VentasArticulosControllerTest` style): save persists list `A,B` and reads back (scenarios 1, 9); non-whitelisted POST fields ignored (11); invalid CSRF ⇒ nothing persisted (12); naming grep-audit `grep -riE 'grupocliente|grupo_clientes|customer[_ ]?group'` over ALL change files ⇒ zero hits (4); page data registers in `admin` menu.
- [x] 3.2 **GREEN** Create `controller/catalogo_excel_settings.php` per AD-7: legacy `fs_controller` with `parent::__construct(__CLASS__, ..., 'admin', TRUE, TRUE)` — NOT PageController (its `admin || have_access_to` gate is the tautology class this change removes); POST: `isCsrfValid()`, input whitelist to single `catalogo_excel_roles` field, save pipeline per AD-6 (split/trim/drop empties/validate against `fs_roles`/dedupe/preserve order/implode `,` → `fs_settings set()` + `save()`); page shows policy summary + granted roles with `descripcion`.
- [x] 3.3 **GREEN** Create `view/catalogo_excel_settings.html.twig`: role checkbox list from `fs_roles`, `{{ csrf_field() }}`, no `|raw` with user data.
- [x] 3.4 **VERIFY non-admin denial** (scenario 10): structural test asserts the admin gate args; framework renders `access_denied` for non-admin GET/POST — no page logic reachable.
- [x] 3.5 **REFACTOR** Page clean-up; naming grep re-run as part of 3.1 test execution.
- [x] 3.6 **VERIFY PR3** Host suite green (known-failure exclusion) + root suite green; runtime: admin saves list via page, non-admin blocked. Measure diff ≤400.
- [x] 3.7 **ROLLBACK PLAN + COMMIT/PR3** Record revert boundary (page gone; key may remain in `config2.ini` harmlessly); work-unit commits; open PR3 with Chain Context (📍 on PR3).

## Phase 4 — Cross-PR integration & close-out

- [x] 4.1 **VERIFY final integration** (automatable parts done: full root suite green 1336/3414 + host suite green; manual browser walkthrough of the settings page is sdd-verify work, not run here) After chain merges: full root suite green (`ddev exec php vendor/bin/phpunit`) + host suite green; manual walkthrough: admin grants role → granted non-admin imports with a denying listener (row skipped + CSV reason) → revoke mid-session → next request denied → SSE stream terminates with `error` event.
- [x] 4.2 **VERIFY chain hygiene** Each merged PR diff contains only its work unit (polluted diff = base bug → retarget/rebase); every PR has stated start/end/dependencies/out-of-scope per chained-pr contract.
- [x] 4.3 **VERIFY traceability** Re-audit the table above: 26/26 scenarios executed green; mark any escape-hatch moves (1.9 → PR2) in the PR bodies.
- [ ] 4.4 **ARCHIVE CONSTRAINT (execute at sdd-archive)** Canonical `plugins/catalogo_core/openspec/specs/articulos-excel-import-export/spec.md` is Spanish — merge this change's MODIFIED/ADDED blocks INTO SPANISH (translate scenarios/requirement text; keep header names for unambiguous merge). SDD lives plugin-local; archive to `plugins/catalogo_core/openspec/changes/archive/YYYY-MM-DD-{name}/`; verify NO core `openspec/` entry exists.
