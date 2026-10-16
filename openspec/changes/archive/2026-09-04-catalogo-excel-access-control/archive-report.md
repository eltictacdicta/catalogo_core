# Archive Report: catalogo-excel-access-control

**Change**: catalogo-excel-access-control
**Plugin**: `plugins/catalogo_core/`
**Archived**: 2026-09-04
**Status**: ✅ Complete — SDD cycle closed (FAIL → corrected PASS via Resolution Addendum)
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-09-04-catalogo-excel-access-control/`

## Architectural Note: OpenSpec Ownership

This change was archived under the **plugin openspec** at
`plugins/catalogo_core/openspec/changes/archive/`, NOT under the core openspec
at `openspec/changes/archive/`. Verified: the core `openspec/changes/` tree has
**no** `catalogo-excel-access*` entry (see "Step 9 check" below). The change is
100% internal to the plugin and respects the "OpenSpec per plugin" convention
documented in `AGENTS.md`, `plugins/catalogo_core/openspec/config.yaml`
(`ownership: plugin-local`), and the `fsframework-plugin-sdd` skill.

## Executive Summary

Closed the catalog Excel access-control SDD: **only administrators may
import/export the article catalog by default**, with an admin-controlled
setting (`catalogo_excel_roles`, comma-separated `fs_roles.codrol` list via
`fs_settings`/`config2.ini`) granting additional user roles. The change
replaced the tautological `admin || have_access_to('ventas_articulos')` gate
with a central policy verdict (`Services/ArticleExcelAccessPolicy.php`)
enforced at EVERY entry point (embedded controller actions, export service
paths, import-log download, embedded SSE, and the standalone
`process_excel_wizard.php` direct-URL endpoint — closing the direct-URL
security hole), added a per-row permission-filter dispatch on `pvp` writes
during import (`Services/ArticuloExcelPermissionGate.php` + denied-rows CSV
reporting), a per-SSE-event authorization re-check
(`CatalogoExcelAccessDeniedException`), and an admin-only settings page
(`controller/catalogo_excel_settings.php` + Twig view).

The change shipped as **3 stacked PRs + 1 post-verify correction** (two
one-line class-resolution runtime fixes, see "Correction"). The original
verification verdict was **FAIL** with CRITICALs C-1/C-2/C-3; the dated
Resolution Addendum records the fixes, the re-run suites, the full HTTP
walkthrough a–d re-execution (all PASS) and the **corrected verdict: PASS**.
Both the original FAIL report and the addendum are preserved verbatim in the
archived `verify-report.md`.

## Specs Synced (canonical source of truth updated)

Per the archive RULING for this change, the plugin's existing canonical spec is
in **Spanish** (existing canonical file language wins), so the merge was done
**in Spanish** (neutral/professional register), translating the English delta
content. Delta-only headers (`# Delta for …`, `Change …`, `Conventions: …`,
`Test-scope: …`) were stripped so the canonical files are self-contained.

### 1. `plugins/catalogo_core/openspec/specs/articulos-excel-import-export/spec.md` — MODIFIED (merged in Spanish)

| Requirement in canonical | Delta action | Outcome |
|---|---|---|
| `Permiso can_import_export (R-CEXC-001, R-CEXC-004)` | MODIFIED | Block replaced in place; full English block (incl. entry-point table + 5 scenarios) **translated to Spanish**; added `(R-CEXC-001, R-CEXC-004)` IDs to the header |
| `Persistencia (R-CEXC-003)` | MODIFIED | Block replaced in place; per-row permission-filter semantics (5 scenarios) **translated to Spanish**; added `(R-CEXC-003)` ID |
| `Verificación de autorización SSE por evento de stream (R-CEXC-005)` | ADDED | Appended at end of `## Requirements`; **translated to Spanish** (2 scenarios) |
| `Los gates de UI reflejan la política (R-CEXC-007)` | ADDED | Appended at end of `## Requirements`; **translated to Spanish** (2 scenarios) |
| `Export Excel`, `Import wizard 3 pasos` | not in delta | **Preserved verbatim** (Spanish, unchanged) |

Translation notes: technical identifiers, the RFC-style `MUST` keyword, suite
tags (`host-suite`), and the scenario keywords (`GIVEN`/`WHEN`/`THEN`) were
kept in English, matching the pre-existing canonical style (Spanish prose +
English technical keywords); all narrative prose was translated to neutral
professional Spanish. A short `## Convenciones de test` section was added so
the suite tags used by the merged scenario blocks are self-defined.

### 2. `plugins/catalogo_core/openspec/specs/articulos-excel-access-settings/spec.md` — CREATED (new canonical, Spanish)

The NEW capability delta (`specs/articulos-excel-access-settings/spec.md`) was
merged as a **new canonical file** — Spanish, for consistency with the plugin's
other canonical spec. All content translated to neutral professional Spanish:

- `Requirement: Ajuste de concesión de roles controlado por admin (R-CEXC-002)` — 4 scenarios
- `Requirement: Semántica de evaluación de concesiones (R-CEXC-006)` — 4 scenarios
- `Requirement: Página de ajustes solo-admin` — 4 scenarios

Delta-only scaffolding stripped: the `# Spec:` preamble, the change-scoped
"delivered by change …" / "Companion delta …" notes. Purpose + hard
constraints (independence, naming hazard) + test-scope conventions retained
and translated.

**Net canonical effect**: 2 requirements modified, 2 requirements added
(import-export spec), 1 new capability spec created (12 scenarios) — all in
Spanish.

## Archive Contents

- `proposal.md` ✅
- `design.md` ✅ (AD-1..AD-8, PR split, data flow, constraints incl. archive-translation carry)
- `specs/articulos-excel-import-export/spec.md` ✅ (delta, English — archived as-is for audit trail)
- `specs/articulos-excel-access-settings/spec.md` ✅ (delta, English — archived as-is for audit trail)
- `tasks.md` ✅ (all implementation tasks ticked; 4.4 archive constraint intentionally open — executed here)
- `verify-report.md` ✅ (original FAIL verdict + findings + **Resolution Addendum 2026-09-04** with corrected PASS — both preserved verbatim)

## Review Lineages & Receipts (approved)

| Lineage | Scope | Terminal state | Receipt digest |
|---|---|---|---|
| `review-05dafbc689bed6b7` | catalogo_core main chain (PR1-3) | **approved** (risk: high) | sha256:a9775c48cff0e38188bab494f9725c6b8985f2d6f07372921429e122689cde7e |
| `review-8e51fcf1191afa7e` | tarifario composition test | **approved** (risk: medium) | sha256:2ef8f4d00b7561fc0c79b3f1fef25418e98c32cca27b313e1d9676069cfd490b |
| `review-20914d0afaee838f` | correction delta (C-1/C-2 fixes) | **approved** (risk: high) | sha256:322c92ea17d9d56e03180512d08df02d97de5074152bb662ffc447ae7297dfa7 |

Receipts verified at:
- `plugins/catalogo_core/.git/gentle-ai/review-transactions/v2/review-05dafbc689bed6b7/review-receipt.json` (terminal_state: approved)
- `plugins/tarifario/.git/gentle-ai/review-transactions/v2/review-8e51fcf1191afa7e/review-receipt.json` (terminal_state: approved)
- `plugins/catalogo_core/.git/gentle-ai/review-transactions/v2/review-20914d0afaee838f/review-receipt.json` (terminal_state: approved)

Review findings were NOT fixed in this change by design (follow-up backlog,
see below); the reviews were approved with those findings recorded as
follow-up work. The correction-delta review (`review-20914d0afaee838f`)
approved the C-1/C-2 fix transaction.

## Chain Summary

| Unit | PR / commit range | Contents |
|---|---|---|
| SDD artifacts | 9421092 | proposal/spec/design/tasks/verify scaffolding |
| PR1 | d722507, 63be87a, e2f7969 (+ docs tick c12561d) | Policy service + all entry-point gates + standalone dispatch fix + SSE stream-start gate |
| PR2 | 401b46f, 35d9bd8, 015b4a7 (+ docs tick b8682fe) | Per-row permission gate + denied-rows CSV + SSE per-event re-check + tarifario composition test |
| PR3 | b9cc76c (+ docs tick 7473c23, c4ba7d7) | Admin-only settings page + view + tests |
| Correction | e7353ee (C-1 `use` fix), b67c085 (C-2 lazy `require_once` fix), 09b4c59 (bootstrap regression tests), e258154 (verify correction link) | Post-verify CRITICAL resolution |
| tarifario (test-only) | 6a807d9 | Composition test (root suite) |

## Verification Summary (mirror of verify-report + addendum)

| Metric | Value |
|--------|-------|
| Original verdict | **FAIL** — CRITICAL C-1 (settings page fatal for admins), C-2 (every non-admin policy evaluation fatals), C-3 (non-admin runtime surface untested green) |
| Corrected verdict (Resolution Addendum 2026-09-04) | **PASS** — C-1/C-2 resolved, C-3 addressed, walkthrough a–d all PASS |
| Spec scenarios | 26 total (settings 12 + import-export 14) |
| Behavioral coverage | 15/26 behavioral, 11/26 structural-pin (honest audit in verify-report §2) |
| Suites (post-fix) | host **281/594** (2 pre-existing `testTwigViewUsesAutoEscape` failures only) · root **1338/3417** (0) · tarifario **142/503** (0) |
| Regression tests added | `tests/ExcelAccessControlBootstrapRegressionTest.php` (2 tests, RED class-not-found → GREEN), exercising the real class-loading path without test setters |
| Archive smoke gate | host suite re-run at archive time: **281 tests / 594 assertions / 2 failures** (the 2 known pre-existing) — gate PASS |
| Composer dependency check | N/A — no new dependencies (no `composer.json`/`composer.lock` touched in 9421092..e258154) |

## Manual Walkthrough Summary (Resolution Addendum, re-run post-fix)

Executed over authenticated HTTP (DDEV, `https://tarifario-07-2026.ddev.site`),
fixtures created additively and deleted afterward (DB verified pristine):

- **a. Admin saves role list** — PASS: settings page GET 200 (no fatal), POST 200 `Configuración guardada.`, `config2.ini` contains `catalogo_excel_roles = 'ROL_EXCEL_VF'`.
- **b. Non-admin WITHOUT grant** — PASS: settings page framework-denied (b1); `ventas_articulos` renders with Excel UI hidden, 0 modal hits (b2); `excel_import_sse` start → HTTP 403 JSON (b3); `preview_excel` → 403 JSON (b4); `export_excel` → 403 text (b5); `download_import_log` → 403 text (b6); standalone `process_excel_wizard.php?action=start` → HTTP 403 JSON, wizard never reached (b7).
- **c. Granted non-admin** — PASS (previously BLOCKED): UI visible; `preview_excel` → `{"success":true,"token":...}`; SSE stream `start` → `progress` ×2 → `complete` with `stats {"actualizados":1,"descartadas":1,"errores":1}`; DB: `VERIFY-ART-001` pvp 1.00 → 10.5 (applied), `VERIFY-ART-002` not created (denied); discarded CSV row `permiso_denegado;VERIFY-ART-002;"editor: el artículo no está asignado a tus grupos en ninguna tarifa"`. First successful HTTP execution of scenarios 18/19/20.
- **d. Revoke mid-session** — PASS: admin saves empty list; verify_user next request renders UI hidden and `export_excel` → 403 text.
- **config2 in-process limitation**: documented unchanged — `fs_settings::get()` reads the per-request `$GLOBALS['config2']` snapshot; an in-flight SSE stream cannot observe a settings-page revocation mid-stream (next request re-reads `config2.ini`). Backlog RISK-1/RES-2.

## Follow-up Backlog (approved review findings — NOT fixed in this change, by design)

Restated from verify-report §6 so the backlog survives the archive:

### catalogo_core review `review-05dafbc689bed6b7` (approved, sha256:a9775c…)
- **RISK-1** (WARNING): SSE per-event re-check cannot detect config2-based grant revocation mid-stream (per-request `$GLOBALS['config2']` snapshot); `GRANULARITY_LARGE_STEP=1000` allows up to ~999 pvp mutations before termination on ≥10000-row imports.
- **RISK-2** (WARNING, **security-relevant**): quick-create article form (`POST nreferencia`) writes `pvp` with no policy gate and no filter dispatch — pre-existing single-row vector outside R-CEXC-003. **Should be scheduled separately.**
- **RISK-3** (WARNING): settings page not admin-only by construction — `fs_controller` `$admin` arg is documented OBSOLETO; real gate is `have_access_to('catalogo_excel_settings')`. Design claim "constructor args block non-admins at the framework level" overstated.
- **RISK-4** (SUGGESTION): numeric `fs_roles.codrol` persisted unquoted → re-parsed as int → silently ineffective.
- **RISK-5** (SUGGESTION): explicit-403 contract degrades to 200 when headers already sent.
- **RES-1** (WARNING): fresh policy instance per SSE progress event → ~3 SELECTs/event; ~15000 extra queries for a 5000-row import; AD-3 per-request caching not realized on this path.
- **RES-2** (WARNING): same vector as RISK-1 (config2 in-process snapshot).
- **RES-3** (WARNING): denial observability asymmetric — entry-point 403s user-facing only; listener exceptions reduced to generic CSV reason, real exception swallowed/unlogged.
- **RES-4** (SUGGESTION): DB failure (`fs_mysql::select` false) → verdict DENY indistinguishable from real revocation.
- **RES-5** (SUGGESTION): final pre-`complete` re-check runs after row loop committed + `@unlink`; a throw presents a fully-committed import as failed.
- **RES-6** (SUGGESTION): "revert PR1 alone" only restores the tautological gate when PR2/PR3 are absent (merged chain code calls PR1 symbols).
- **READ-1** (WARNING): three new classes in one namespace use three prefixes + two languages (`Article`/`Articulo`/`Catalogo`, `Access`/`Permission`).
- **READ-2** (WARNING): PR1 measured ~1086 authored lines vs ~390 forecast; escape hatch could not bring it under budget; deviation unrecorded in tasks.md.
- **READ-3** (SUGGESTION): `testSseRecheckWiredIntoProgressCallbackAndComplete` uses whole-file `strpos` — never verifies the final re-check precedes `complete`.
- **READ-4** (SUGGESTION): composition test not in the 16-file candidate diff (lives in separate tarifario repo) — diff/spec file inventory disagreement.
- **READ-5** (SUGGESTION): proposal.md vs design.md cite different line numbers (111-114 vs 99-103) for the same pre-change tautological gate.
- **READ-6** (SUGGESTION): mixed client-facing language — English admin-menu title vs Spanish body; Spanish `denialMessage()` vs English CSRF/session denial strings parsed by the same wizard client.
- **REL-1** (WARNING): settings-page POST contract pinned structurally, not behaviorally (controller POST never executed). **C-1/C-2 proved the consequence.**
- **REL-2** (WARNING): mutation-ordering contracts (S18/19/23/24) pinned by source-position strpos; no executed import loop / driven SSE catch.
- **REL-3** (SUGGESTION): naming grep-audit covers 3 of ~8 new files (independent all-files audit by verifier: 0 hits).
- **REL-4** (WARNING): `testPolicyDenialWithoutExplicitPolicyDeniesAnonymousSession` deterministic only under host-suite `processIsolation`; root-suite shared state can flip the verdict.
- **REL-5** (SUGGESTION): composition test bypasses `ArticuloExcelPermissionGate` — full gate → event → real-listener import chain never exercised end-to-end.

### tarifario review `review-8e51fcf1191afa7e` (approved, sha256:2ef8f4…)
- **REL-1** (WARNING): composition test bypasses the real host per-row gate and the init registration path; manually instantiates the listener.
- **REL-2** (WARNING): `test_admin_import_row_is_allowed` mislabels the import context — an admin row never reaches the listener in the real per-row flow (AD-5 short-circuit).
- **REL-3** (SUGGESTION): the only novel assertion uses a fabricated `'import_article'` action the host gate never emits (AD-2 hardcodes `ACTION_EDIT_ARTICLE`).
- **REL-4** (SUGGESTION): `Init` statics (`hooksRegistered`/`listenerRegistered`) not reset in this file — latent leak if the file ever calls `Init::init()`.

### Correction-delta review `review-20914d0afaee838f` (approved, sha256:322c92…)
- **WARNING — RISK-1**: no automated 403 assertion for the fixed non-admin surfaces (the HTTP walkthrough b2–b7 provides the runtime proof, but no test pins the HTTP 403 shape post-fix).
- **WARNING — RISK-3**: settings admin gate is default-only (walkthrough b1 proves the default no-access-row case; granting the page to a role would open it — no test covers that).
- **WARNING — RES-1**: the C-1 regression test uses `ReflectionClass::newInstanceWithoutConstructor` — a bare `require` of the controller file would fatal (constructor needs Kernel+DB); the reflection construction is load-bearing.
- **WARNING — REL-1**: `require_all_models` landmine — a future bootstrap change that preloads legacy `base/` models could mask the C-2 regression test (it deliberately does NOT preload `fs_settings`).

## Step 9 check — no core openspec entry

Verified: `openspec/changes/` (repo root) contains NO `catalogo-excel-access*`
entry. `find openspec -iname '*catalogo-excel-access*'` → 0 hits. The
plugin-local SDD rule is respected; nothing to clean up.

## Outstanding / Deferred

- Review backlog above (RISK-1..5, RES-1..6, READ-1..6, REL-1..5 catalogo_core;
  REL-1..4 tarifario; correction-delta WARNINGs) — follow-up work, not part of
  this change's scope.
- **RISK-2 (quick-create pvp vector)** is flagged security-relevant and should
  be scheduled as its own change.
- S-1/S-2 from verify-report (lint gate `php -l` per new file + repeatable
  walkthrough harness in plugin `tools/`) remain suggestions for future
  access-control changes.
- The `catalogo-articulos-excel` change in
  `plugins/catalogo_core/openspec/changes/` is a SEPARATE, still-active change;
  untouched by this archive.

## Archive Integrity

- Change folder moved via `git mv` (rename tracked); no files modified during
  the move.
- Archived `tasks.md` has NO unchecked implementation tasks: 4.4 (archive
  constraint) was intentionally left open for this phase and is executed by
  this archive; the correction-link section is ticked resolved.
- Archived `verify-report.md` preserves the original FAIL verdict AND the
  Resolution Addendum verbatim — the audit trail is complete.
- Canonical specs updated BEFORE the archive move per sdd-archive ordering
  (move happened first physically, but the canonical update is committed in the
  same change; both reflect the final state).
- Commit: `docs: archive catalogo-excel-access-control SDD with spanish canonical spec merge`