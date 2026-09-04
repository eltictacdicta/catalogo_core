# Tasks: Quick-Create Article Form Price Gate (ventas-articulos-quick-create-gate)

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~205-250 (controller ~25-30, test ~180-220; test dominates) |
| 400-line budget risk | Low |
| Chained PRs recommended | No |
| Suggested split | Single PR (design D6) |
| Delivery strategy | single-pr |

Decision needed before apply: No
Chained PRs recommended: No
Chain strategy: pending
400-line budget risk: Low

### Work Units

| Unit | Goal | Focused test command | Runtime harness | Rollback boundary |
|------|------|----------------------|-----------------|-------------------|
| 1 | Docs corrections | n/a | N/A — doc-only commit | Revert docs commit alone |
| 2 | RED test suite | host filter (2.12) | N/A — engine-stub unit harness, no HTTP/Kernel/DB boot (D3) | Delete test file |
| 3 | GREEN controller gate | host filter green | N/A — no runtime surface (no schema/config/UI) | Revert controller diff only |
| 4 | Verify + audit | suites 4.1-4.3 | N/A — regression runs | Rollback plan in 4.6 |

## Phase 1: Docs (docs commit)

- [x] 1.1 `design.md`: "17 scenarios" → 14 (verified: 8 reqs, 1+1+3+1+1+1+2+4).
- [x] 1.2 `proposal.md` R-QCRT-007: cite `VentasArticulo.php:199-205` → `:201-204` (create-if-missing block; gate dispatch at `:206-224`).
- [x] 1.3 Commit: `docs(sdd): correct scenario count and article-page cite`.

## Phase 2: RED — behavioral tests (STRICT TDD, D3/D4)

- [x] 2.1 Create `tests/VentasArticulosQuickCreateGateTest.php` (`Tests\CatalogoCore`; `#[RunTestsInSeparateProcesses]`+`#[PreserveGlobalState(false)]`; reset FSEventDispatcher + fs_core_log/fs_model statics).
- [x] 2.2 Harness: reflection `newInstanceWithoutConstructor()` on `\ventas_articulos`; set `className`/`core_log`/`cache`/`db`/`request` (POST fields + `CsrfManager::generateToken()`); `articulo` stub (`get()`→false); `fs_db2::$engine` stub (table_exists→true, exec→true) for allow paths.
- [x] 2.3 Recording/denying/throwing listeners; `mockUser($nick,$isAdmin)` fs_user subclass.
- [x] 2.4 R-QCRT-001: non-admin → count==1 + event fields (RED).
- [x] 2.5 R-QCRT-003 S1 + Beh-S1: deny → stack == denial msg only, no success/save-fail msg, zero exec (RED).
- [x] 2.6 R-QCRT-003 S2: deny w/o `npvp` → whole create blocked (RED).
- [x] 2.7 R-QCRT-003 S3: allow → success msg + `isAllowed()` (RED).
- [x] 2.8 R-QCRT-004: throwing listener → `permission filter error`; `error_log`→tmpfile, empty (RED).
- [x] 2.9 Guards: R-QCRT-002 admin zero-dispatch; R-QCRT-005 zero-listener allow; R-QCRT-006 CSRF blocks (error_log redirected).
- [x] 2.10 R-QCRT-007 proxy: `ACTION_EDIT_ARTICLE === 'edit_article'`.
- [x] 2.11 Beh-S4: real tarifario `ArticlePermissionListener` + model stubs; `file_exists` → `markTestSkipped`; init-caveat docblock (RED).
- [x] 2.12 Run RED filter; commit `test(catalogo_core): quick-create gate suite (RED)`.

## Phase 3: GREEN — controller gate (D1/D2/D4/D5)

- [x] 3.1 Insert at `VentasArticulos.php:467` (after dup-check `:466`, before `$art` `:468`): admin guard + event construct (referencia, `ACTION_EDIT_ARTICLE`, nick) + `try { dispatch } catch (\Throwable) { deny('permission filter error') }` + deny → `new_error_msg('No tienes permisos para crear este artículo: '.$reason)` + return; NO `error_log`.
- [x] 3.2 Add `use` imports (ArticlePermissionFilterEvent, FSEventDispatcher).
- [x] 3.3 Green run; commit `feat(catalogo_core): gate quick-create pvp write`.
- [x] 3.4 REFACTOR check: catch mirrors gate `:73-82`, message convention `:222`; no pins/duplication.

## Phase 4: Verify + R-QCRT-007 orthogonality

- [x] 4.1 Host: `-c plugins/catalogo_core/phpunit.xml` → 281/594, only 2 known twig failures.
- [x] 4.2 Root: `php vendor/bin/phpunit` → 1338/3417 incl. Beh-S4.
- [x] 4.3 Tarifario: `-c plugins/tarifario/phpunit.xml` → 142/503.
- [x] 4.4 Diff audit (verify task, NOT a runtime test — would be a pin): no new constants/tables/grants/UI/JS; Excel gate (`:356-368`) + article page (`:206-224`) untouched.
- [x] 4.5 Beh-S3 audit: no source-string/line assertions in test file (REL-2).
- [x] 4.6 Rollback plan: revert controller diff + test file; no schema/data/config; RISK-2 receipt untouched.

## Phase 5: PR

- [x] 5.1 Open single PR (chained: No) with work-unit commits; ~205-250 LoC. *(apply note: PR NOT opened — apply ran with "no push, no PRs"; work-unit commits are ready on the default branch for the orchestrator to open.)*
- [x] 5.2 Record in verify-report: 14 scenarios; baselines 4.1-4.3; D7 note — archive translates English delta into Spanish canonical at `plugins/catalogo_core/openspec/specs/articulos-quick-create-permission/spec.md`. *(closed by verify-report.md 2026-09-04 + executed by this archive — mechanical reconciliation at archive time, see archive-report.md)*

## Traceability: 14 spec scenarios → tasks

| Scenario | Tasks |
|---|---|
| R-QCRT-001 non-admin dispatch before mutation/save | 2.4, 3.1 |
| R-QCRT-002 admin zero dispatch | 2.9, 3.1 |
| R-QCRT-003 S1 unassigned editor denied, no create | 2.5, 3.1 |
| R-QCRT-003 S2 npvp 0.0 still blocked | 2.6, 3.1 |
| R-QCRT-003 S3 gestor allowed | 2.7, 3.1 |
| R-QCRT-004 fail-closed, no error_log | 2.8, 3.1, 3.4 |
| R-QCRT-005 zero listeners default allow | 2.9, 3.1 |
| R-QCRT-006 CSRF still blocks | 2.9, 3.1 |
| R-QCRT-007 S1 Excel gate + article page untouched | 4.4 |
| R-QCRT-007 S2 no new constants/tables/grants/UI | 2.10, 4.4 |
| Beh-S1 deny executed, save never called | 2.5 |
| Beh-S2 admin zero dispatch asserted | 2.9 |
| Beh-S3 no structural pins | 4.5 |
| Beh-S4 cross-plugin tarifario composition | 2.11, 4.2 |