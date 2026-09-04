# Archive Report: ventas-articulos-quick-create-gate

**Change**: ventas-articulos-quick-create-gate
**Plugin**: `plugins/catalogo_core/`
**Archived**: 2026-09-04
**Status**: ✅ Complete — SDD cycle closed (verdict PASS)
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-09-04-ventas-articulos-quick-create-gate/`

## Architectural Note: OpenSpec Ownership

This change was archived under the **plugin openspec** at
`plugins/catalogo_core/openspec/changes/archive/`, NOT under the core openspec
at `openspec/changes/archive/`. Verified: the core `openspec/changes/` tree has
**no** `ventas-articulos-quick-create*` entry and `find openspec -iname
'*quick-create*'` returns 0 hits (see "Step 9 check" below). The change is 100%
internal to the plugin and respects the "OpenSpec per plugin" convention
documented in `AGENTS.md`, `plugins/catalogo_core/openspec/config.yaml`
(`ownership: plugin-local`, `strict_tdd: true`), and the
`fsframework-plugin-sdd` skill.

## Executive Summary

Closed the quick-create article form price-gate SDD: the article **list page**
quick-create form (`POST nreferencia` → `VentasArticulos::nuevoArticulo()`) was
the last un-gated `pvp` write surface in `catalogo_core`. The change routes it
through the frozen neutral permission contract `ArticlePermissionFilterEvent`
(`catalogo_core.article_permission_filter`, action `ACTION_EDIT_ARTICLE`):
dispatch before any `pvp` mutation/save for non-admin users (single dispatch
point after validation + duplicate-reference check), admin skip-dispatch (zero
dispatch, `ArticuloExcelPermissionGate` AD-5 dominance precedent), deny blocks
the whole create with a user-facing `new_error_msg` carrying
`getDenialReason()`, fail-closed `\Throwable` catch with generic
`permission filter error` and **no `error_log`** (processIsolation ban), zero
listeners ⇒ default allow (host neutrality), CSRF unchanged, and no new
surface (no constants/tables/grants/templates/JS) — orthogonal to the Excel
gate and the article-page path.

It closes review finding **RISK-2** (WARNING, security-relevant, frozen in the
archived `2026-09-04-catalogo-excel-access-control` verify-report) and the
**R-TAR-HOOK-003 deferral** for the quick-create path. The change was
implemented RED→GREEN under STRICT TDD (behavioral suite
`tests/VentasArticulosQuickCreateGateTest.php`, 9 tests / 34 assertions) plus a
cross-plugin composition suite in the tarifario repo
(`VentasArticulosQuickCreateGateCompositionTest`, 2 tests / 7 assertions).
Verification verdict: **PASS** — no CRITICAL/WARNING findings; 11/14 scenarios
behaviorally pinned, 2 diff-audit/proxy, 1 meta-audit (honestly marked); live
HTTP walkthrough a–d all PASS.

## Specs Synced (canonical source of truth updated)

Per the ESTABLISHED RULING for this plugin (see the archived
`2026-09-04-catalogo-excel-access-control` archive-report), the plugin's
canonical specs are in **Spanish** (existing canonical file language wins), so
the sync was done **in Spanish** (neutral/professional register), translating
the English delta content.

### `plugins/catalogo_core/openspec/specs/articulos-quick-create-permission/spec.md` — CREATED (new canonical, Spanish)

The delta (`specs/articulos-quick-create-permission/spec.md`, 8 requirements
R-QCRT-001..007 + behavioral test discipline, 14 scenarios) was merged as a
**new canonical file** — Spanish, for consistency with the plugin's other
canonical specs (`articulos-excel-import-export`, `articulos-excel-access-settings`).
All content translated to neutral professional Spanish:

| Requirement (canonical) | Scenarios |
|---|---|
| `Dispatch de creación rápida no-admin antes de la mutación de pvp (R-QCRT-001)` | 1 |
| `Dominancia admin — cero dispatch (R-QCRT-002)` | 1 |
| `La denegación bloquea toda la creación con un motivo visible para el usuario (R-QCRT-003)` | 3 |
| `Excepción de listener fail-closed, sin error_log (R-QCRT-004)` | 1 |
| `Neutralidad del host — cero listeners, allow por defecto (R-QCRT-005)` | 1 |
| `CSRF sin cambios (R-QCRT-006)` | 1 |
| `Sin superficie nueva — ortogonalidad con el gate Excel (R-QCRT-007)` | 2 |
| `Disciplina de test comportamental (STRICT TDD)` | 4 |

Delta-only scaffolding stripped: the `# Spec:` preamble, the change-scoped
"delivered by change …" note, and the `## 2. Test-scope conventions` table
(replaced by the canonical `## Convenciones de test` section, matching the
style of the plugin's other canonicals). Purpose + context (RISK-2 closure,
R-TAR-HOOK-003 deferral, frozen-contract-consumed note) retained and
translated. Translation notes: technical identifiers, the RFC-style `MUST`
keyword, suite tags (`host-suite`/`root-suite`), and the scenario keywords
(`GIVEN`/`WHEN`/`THEN`/`AND`) were kept in English, matching the pre-existing
canonical style (Spanish prose + English technical keywords); all narrative
prose was translated to neutral professional Spanish. The canonical is
self-contained.

**Net canonical effect**: 1 new capability spec created (8 requirements, 14
scenarios), all in Spanish.

## Archive Contents

- `proposal.md` ✅
- `design.md` ✅ (D1..D7, incl. D7 archive-translation carry)
- `specs/articulos-quick-create-permission/spec.md` ✅ (delta, English — archived as-is for audit trail)
- `tasks.md` ✅ (all implementation tasks ticked; 5.2 verify-record reconciled at archive time — see below)
- `verify-report.md` ✅ (verdict PASS preserved verbatim)

## Review Lineages & Receipts (approved)

| Lineage | Scope | Terminal state | Receipt digest |
|---|---|---|---|
| `review-b815a238b85c1299` | catalogo_core main chain (gate feat + RED suite) | **approved** | sha256:100eff… (full digest in receipt) |
| `review-5f3927aa1e9c84a3` | tarifario composition test | **approved** | sha256:c6c15c… (full digest in receipt) |

Both reviews approved with findings recorded as follow-up backlog (NOT fixed in
this change, per instruction — see "Follow-up Backlog" below).

## Chain Summary (single PR, work-unit commits)

| Unit | Commit (catalogo_core) | Contents |
|---|---|---|
| Phase 1 docs | `3715c49 docs(sdd): correct scenario count and article-page cite` | 1.1–1.3 |
| Phase 2 RED | `4d65a20 test(catalogo_core): quick-create gate suite (RED)` | 2.1–2.12 behavioral suite |
| Phase 3 GREEN | `73bddae feat(catalogo_core): gate quick-create pvp write` | 3.1–3.4 (+27 lines `Controller/VentasArticulos.php`) |
| Phase 4/5 verify tick | `8bffb37 docs(sdd): tick quick-create gate tasks through apply` | 4.1–4.6, 5.1 (apply note: PR not opened — no-push/no-PR apply; work-unit commits on default branch) |
| tarifario (test-only) | `87b8a4a test(tarifario): quick-create gate composition suite (RED)` | Beh-S4 composition (root suite) |

Committed range: catalogo_core `e258154..8bffb37` + tarifario `6a807d9..87b8a4a`.
The change diff touches zero template files and zero schema/config — rollback
is a revert of the controller diff + test files.

## Verification Summary (mirror of verify-report)

| Metric | Value |
|--------|-------|
| Verdict | **PASS** — no CRITICAL, no WARNING; 1 SUGGESTION (CSRF single-layer on modern `PageController` pages — pre-existing architecture, spec-accurate) + 1 INFO |
| Spec scenarios | 14 total (8 requirements R-QCRT-001..007 + behavioral discipline) |
| Behavioral coverage | 11/14 behavioral (runtime-pinned) · 2/14 diff-audit/proxy (S9, S10 — honestly marked, no runtime test by design) · 1/14 meta-audit (S13) |
| Suites | host **290/628** (2 pre-existing `testTwigViewUsesAutoEscape` failures only) · root **1349/3458** (0) · tarifario **144/510** (0) |
| Gate suite (host filter) | `VentasArticulosQuickCreateGateTest` — 9 tests / 34 assertions, 0 failures |
| Composition (tarifario filter) | `VentasArticulosQuickCreateGateCompositionTest` — 2 tests / 7 assertions, 0 failures |

### Archive smoke gate (re-run at archive time, DDEV, 2026-09-04)

`ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` →
**290 tests / 628 assertions / 2 failures / 25 warnings / 4 skipped** — the
**2 known pre-existing `testTwigViewUsesAutoEscape` failures**
(`VentasArticulosControllerTest.php:111`; pins templates against `|raw`, and
the `ventas_articulos`/`ventas_articulo` templates legitimately use `|raw` for
the JS config block). The change diff touches zero template files — provably
unrelated. **Gate PASS** (baseline match).

### Composer dependency check

**N/A** — no new Composer dependencies introduced (`composer.json` /
`composer.lock` untouched in `e258154..8bffb37`); nothing to commit under
`plugins/catalogo_core/vendor/`.

## Manual Walkthrough Summary (live deny path — the change's whole point)

Executed over authenticated HTTP (DDEV, `https://tarifario-07-2026.ddev.site`),
fixtures created additively (users `qc_admin`/`qc_editor`/`qc_gestor`, role
`VERIFYQC`, tarifario group `VERIFYQC` → tarifa `INF2026`) and **deleted
after**; DB verified pristine:

- **a. Admin quick-create** `VERIFY-QC-A` pvp=111.11 — PASS: 200 + success msg, row with pvp exactly 111.11 (allow live, zero dispatch).
- **b. Unassigned editor quick-create** `VERIFY-QC-B` pvp=222.22 — PASS: 200 + exact denial "No tienes permisos para crear este artículo: editor: el artículo no está asignado a tus grupos en ninguna tarifa", **0 rows** (whole create blocked, no partial state).
- **c. Gestor quick-create** `VERIFY-QC-C` pvp=333.33 — PASS: 200 + success msg, row with pvp exactly 333.33 (through the real production listener).
- **d. CSRF failure** `VERIFY-QC-D` invalid token — PASS: 200 + "Token de seguridad inválido…", ddev log confirms `CSRF: Invalid token in (VentasArticulos)`, **0 rows** (blocked before any dispatch/create).

Orthogonality spot check: article-PAGE edit path renders the full edit form for
`VERIFY-QC-C` with no denial; Excel import path untouched by the diff (audit)
with its suites green at baseline. Cleanup verified: `articulos` back to 1 row
(original "Ref"), `fs_users` only `admin`, 0 leftover `VERIFYQC`/`VERIFY-QC%`
rows across all touched tables.

## Task Reconciliation at Archive Time

Task **5.2** (verify record: "Record in verify-report: 14 scenarios; baselines
4.1-4.3; D7 note — archive translates English delta into Spanish canonical…")
was the only unchecked tick in `tasks.md` (26/27). Per the sdd-archive gate,
the archived audit trail MUST NOT contain stale unchecked tasks for completed
work; the verify-report.md it required **exists and records all mandated
items** (14 scenarios, baselines 4.1–4.3, D7 archive-translation note), and the
D7 translation is **executed by this archive** (see "Specs Synced"). This is an
**exceptional mechanical reconciliation** explicitly authorized by the
orchestrator instruction ("task 5.2 = verify record, done"); the tick was
applied to `tasks.md` BEFORE the archive move and annotated with the closure
reason. No other tasks were touched; all implementation tasks were already
ticked by apply.

## Follow-up Backlog (approved review findings — NOT fixed in this change, by design)

Restated from verify-report §5 so the backlog survives the archive:

### catalogo_core review `review-b815a238b85c1299` (approved, sha256:100eff…)
- **RISK-1** (WARNING): R-QCRT-004's "no error_log" holds only for the controller's own catch block — the real production listener (`plugins/tarifario/Services/ArticlePermissionListener.php:50`) catches `\Throwable` internally, writes `error_log('[tarifario] ArticlePermissionListener: …')`, then denies with the generic reason without rethrowing; a stderr line IS emitted on a real listener failure on the composed path (deny still happens — secure; the spec scenario overclaims the no-log property on the composed path).
- **RISK-2** (SUGGESTION): deny UX funnels `getDenialReason()` into the raw-rendered error slot (`themes/AdminLTE/view/header.html.twig:260` `{{ error|raw }}`); a listener reflecting request data into its reason would produce reflected HTML. Current producers (static strings, fail-closed constant) are safe; hardening: HTML-encode at the controller or auto-escape the error box.
- **RISK-3** (SUGGESTION): the composition test registers the real listener directly on `FSEventDispatcher`, bypassing tarifario's production `Init::init()` registration; a silent registration regression would re-open the pvp vector while this test and the host suite stay green (separately covered by `PermissionListenerInitRegistrationTest`).
- **RISK-4** (SUGGESTION): the composition test's deny assertion rides on stubbed collaborators (`mockArticuloModel::get()`/`mockUserModel::get()` hard-return `false`); the duplicate-reference early return and the listener's real DB-backed admin-flag load are never exercised on the deny path.

### tarifario review `review-5f3927aa1e9c84a3` (approved, sha256:c6c15c…)
- **RISK-1** (WARNING): `stubDbEngine()` mutates core statics (`fs_db2::$engine`, `$table_list`, `$auto_transactions`) and `$GLOBALS['plugins']` without restoring in `tearDown()`; containment relies entirely on per-test process isolation (root `phpunit.xml` sets none — the attributes `#[RunTestsInSeparateProcesses]`/`#[PreserveGlobalState(false)]` are the only guard).
- **RISK-2** (SUGGESTION): allow-path docblock overclaims "article created with the submitted pvp": the test asserts only the success message and `execCalls >= 1`, never the persisted pvp value (the live walkthrough steps a/c empirically close this gap for now — pvp 111.11/333.33 persisted exactly — but the test-level gap remains).
- **RISK-3** (SUGGESTION): the cross-plugin skip guard checks only `catalogo_core/Controller/VentasArticulos.php` but the test also hard-depends on `Event/ArticlePermissionFilterEvent.php` and `model/core/articulo.php`+`impuesto.php`; a present-but-incomplete checkout would hard-fail instead of skip (low likelihood — files ship together).

### New observations from verification (recorded, no action)
- **SUGGESTION — CSRF enforcement on modern `PageController` pages is single-layer**: the modern flow (`src/Core/Base/Controller.php:run()` → `privateCore()`) bypasses `fs_controller::pre_private_core()`, so the global `validateCsrf()` layer does not apply to `ventas_articulos`/`ventas_articulo`; CSRF on the quick-create path rests entirely on the in-controller `validateFormToken()` (first statement of `nuevoArticulo`). Pre-existing architecture, spec-accurate (R-QCRT-006), zero impact on this change's compliance.
- **INFO — R-QCRT-004 error_log nuance confirmed at HTTP level**: the `error_log` redirected in the gate suite's CSRF test is real (observed ddev log line matches), validating the test's `ini_set` redirect necessity.

## Step 9 check — no core openspec entry

Verified: `openspec/changes/` (repo root) contains NO
`ventas-articulos-quick-create*` entry; `find openspec -iname '*quick-create*'`
→ 0 hits. The plugin-local SDD rule is respected; nothing to clean up.

## Outstanding / Deferred

- Review backlog above (catalogo_core RISK-1..4; tarifario RISK-1..3) —
  follow-up work, not part of this change's scope. Suggested future changes:
  listener no-log hardening on the composed path, denial-reason HTML encoding,
  composition-test wiring proof (init registration), deny-path real-DB coverage.
- The `catalogo-articulos-excel` change in
  `plugins/catalogo_core/openspec/changes/` is a SEPARATE, still-active change;
  untouched by this archive.

## Archive Integrity

- Change folder moved via `git mv` (rename tracked); the untracked
  `verify-report.md` was moved alongside; no file contents modified during the
  move except the mechanical 5.2 tick applied to `tasks.md` BEFORE the move
  (see "Task Reconciliation at Archive Time").
- Archived `tasks.md` has NO unchecked tasks (26/26 after the 5.2
  reconciliation — the verify-record tick is closed by the verify-report it
  required plus the D7 translation executed in this archive).
- Archived `verify-report.md` preserves the PASS verdict verbatim — the audit
  trail is complete.
- Canonical spec created BEFORE the archive move per sdd-archive ordering
  (canonical file written, then the change dir moved; both committed in the
  same change and reflect the final state).
- Commit: `docs: archive ventas-articulos-quick-create-gate SDD with spanish canonical spec`