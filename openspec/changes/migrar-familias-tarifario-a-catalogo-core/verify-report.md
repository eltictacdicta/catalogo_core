# Verify Report — `migrar-familias-tarifario-a-catalogo-core` (Phase 2 + Phase 3 slice)

```yaml
schema: gentle-ai.verify-result/v1
verdict: pass_with_warnings
scope_verified: Phase 2 (2.1–2.4) + Phase 3 (3.1–3.5); Phase 1 regression re-run
scope_deferred: Phase 4 (4.1–4.4) — explicitly out of scope, left unchecked
blockers: 0
critical_findings: 0
requirements: 6/10 fully verified (2 partial, 2 deferred to Phase 4)
scenarios: 21/26 verified (5 deferred to Phase 4)
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_result: "Tests: 1421, Assertions: 5838, Failures: 0, Errors: 0, Warnings: 2, Skipped: 1"
test_output_hash: sha256:14a398a725fb1770fb08ac2e4ff03e5e36af11b5d0ead67d81a08efe11fe8051
build_command: ddev exec composer phpstan
build_result: "NOT AVAILABLE — vendor/dev-tools/bin/phpstan missing in this environment"
evidence_revision: sha256:367708532a14de4b5a111c103482b884494bdfbbd8d2da27fb8c53576bb4a3bb
```

- **Change**: `plugins/catalogo_core/openspec/changes/migrar-familias-tarifario-a-catalogo-core/` (plugin-local, `strict_tdd: true`).
- **Branch**: `feature/familias-estructura-pr3` (own repo), based on `feature/familias-estructura-pr2b`; based on `main` at `d26d436`.
- **Verification type**: independent. All commands below were re-executed against the working tree; apply claims were not trusted. No production behavior was changed by this verify.
- **Not performed**: no commit of code, no push, no PR, no merge, no archive. Phase 4 tasks remain unchecked by design.
- **Artifact reconciliation performed in this run**: page identity (`ventas_familias` → surviving `ventas_familia` / `tarif_familias`) is now reflected in the delta spec (R-FJ-001, R-FJ-006), `design.md` (Technical Approach, amendment note, D5, data flow, file changes, testing strategy, assumption 9), `tasks.md` (Phase 3 bullets 3.1–3.5, work-unit row, deferred 4.1 menu reference) and a historical note in `proposal.md`. The retired page is never resurrected; no Phase 4 artifact was created.

## Evidence

| # | Check | Command | Result |
|---|---|---|---|
| 1 | Phase 2/3 focused classes (re-run) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml <file>` | `FamiliaEstructuraMigration` 10/37 OK · `FamiliaTreeGuard` 6/12 OK · `FamiliaEstructuraMigrationIdempotency` 3/16 OK · `InitUpgrade` 10/49 OK · `FamiliaEstructuraTree` 11/52 OK · `FamiliaPageNamePreservation` 4/6 OK · `VentasFamiliaCycleGuard` 3/11 OK · `ArticleFamilyAssignment` 7/28 OK |
| 2 | Phase 1 regression classes | same runner, per file | `CatalogoFamiliaEstructura` 15/55 OK · `CatalogoArticuloFamiliaEstructura` 14/43 OK · `CatalogoOptions` 12/23 OK · `VentasFamiliaController` 11/12 OK |
| 3 | Phase 2/3 + related filter | `--filter 'Familia\|ArticleFamilyAssignment\|ArticlePermissionFilter\|InitUpgrade'` | **208 tests, 673 assertions, 0 failures, 0 errors, 1 skipped** |
| 4 | Full plugin suite | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --display-warnings --display-skipped` | **Tests: 1421, Assertions: 5838, Failures: 0, Errors: 0, Warnings: 2, Skipped: 1** — OK |
| 5 | Skipped test reason | `--display-skipped` | `InitFamiliasTablesTest::test_noop_when_model_files_absent` — shipped tree has model files; live create path is the verify-phase smoke |
| 6 | Warnings (pre-existing, unrelated) | `--display-warnings` | `model/core/catalogo_opcional.php:39` undefined array key `id`; `model/core/articulo.php:713` `foreach()` null — both triggered by pre-existing `CatalogoOpcionalPrecioTest` / `VentasArticulosListAbsorptionTest` |
| 7 | tarifario hook regression | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter HookRegistrationTest` | **OK (4 tests, 9 assertions)** |
| 8 | Dep-free cross-plugin audit | `grep -rn "plugins/tarifario\|Plugins\\tarifario"` over `.php/.twig/.xml/.ini/.yaml` (excluding `.git`, `tests/`) | **zero hits** in production code |
| 9 | Plugin dependency declaration | `grep '^require' fsframework.ini` | `require = ""` — no dependency toward tarifario |
| 10 | Legacy-table reads added by Phase 2/3 | `git diff main..HEAD -- Controller/ Services/ Init.php \| grep '^+.*tarif_tarifa'` | only the migration service header/constants (`LEGACY_FAMILIES`, `LEGACY_ARTICLES`); **no new reads outside `FamiliaEstructuraMigration`** |
| 11 | Base table untouched | `git diff --name-only main..HEAD -- model/core/familia.php model/table/familias.xml` | empty — `familias` schema and `familia.php` unchanged (R-FJ-001 "Base table untouched") |
| 12 | Flag-off inertness (data paths) | focused tests | `Load is inert when flag off`, `Save is inert when flag off and writes nothing` (both `FamiliaEstructuraTreeTest` and `ArticleFamilyAssignmentTest`), `Family structure migration is inert when flag is off` (`InitUpgradeTest`) — all pass |
| 13 | Flag-off inertness (view) | `View/ventas_familia.html.twig`, `View/ventas_articulo.html.twig` + tests | structure panel wrapped in `{% if fsc.family_structure %}`; `View gates structure ui behind the flag` / `View gates the assignment block behind the flag` pass |
| 14 | Static analysis | `ddev exec composer phpstan` | **not available**: `Could not open input file: vendor/dev-tools/bin/phpstan` |
| 15 | Coverage tool | — | not configured / not available |

**Interpretation of the full-suite numbers**: the two warnings are pre-existing and unrelated to this change (both in the optional/pricing surfaces, not in the family-structure code). There are zero failures and zero errors.

## Task verification (2.1–2.4, 3.1–3.5)

| Task | Checkbox | Evidence found | Verdict |
|---|---|---|---|
| 2.1 Migration service | `[x]` | `Services/FamiliaEstructuraMigration.php`; 10 tests assert step order, `INSERT IGNORE` / `ON CONFLICT DO NOTHING`, zero `DELETE/DROP/UPDATE/TRUNCATE/ALTER`, orphan inner-join, early return, one transaction per step, rollback on failure | ✅ Verified |
| 2.2 Tree guard | `[x]` | `Services/FamiliaTreeGuard.php`; 6 tests: self-parent, direct cycle, deep cycle → `RuntimeException`; valid chain walks to root; missing mother allowed; root move performs no query | ✅ Verified |
| 2.3 Init wiring | `[x]` | `Init::upgrade()` calls `migrateFamiliaEstructura()` after all ensure-tables; seam returns early when `familyStructureEnabled()` is false; `InitUpgradeTest` proves flag-off inert, flag-on runs, and boot ordering | ✅ Verified |
| 2.4 Migration idempotency | `[x]` | `FamiliaEstructuraMigrationIdempotencyTest` (3 tests / 16 assertions): second run stable, orphan skipped + logged twice, no destructive statements | ⚠️ **Weaker than claimed** — the task says "DDEV DB" but the test uses `FamiliaEstructuraMigrationFakeDb` (in-memory). Real MySQL/PostgreSQL SQL execution, transaction semantics and FK cascade are **not** proven. See WARNING-2. |
| 3.1 Structure UI + event | `[x]` | `Controller/VentasFamilia.php` (surviving page); active-list selector, batch `all_from_list` read, upsert save, event dispatch before write; `FamiliaEstructuraTreeTest` covers tree build, flag-off inert, batch read, upsert/no-duplicate, deny path, view gate | ✅ Verified (page identity amended) |
| 3.2 Tree view + CSRF | `[x]` | `View/ventas_familia.html.twig`: nested `<ul class="familia-tree">`, recursive `hijas_tree`, `nivel_visual` derived depth, per-node fields, CSRF count equals POST count, no `|raw` | ✅ Verified |
| 3.3 Cycle rejection in save path | `[x]` | `editarFamilia()` calls `assertFamilyAcyclic()` before `save()`; `VentasFamiliaCycleGuardTest` (3/11): cycle and self-parent rejected with error and no write; valid re-parent saves | ✅ Verified |
| 3.4 Article assignment | `[x]` | `Controller/VentasArticulo.php` per-list assignment block + `View/ventas_articulo.html.twig`; `ArticleFamilyAssignmentTest` (7/28): flag-off inert, list scoping, upsert, empty-family delete, deny path, view gate | ✅ Verified |
| 3.5 Page-name preservation | `[x]` | `FamiliaPageNamePreservationTest` (4/6): `ventas_familia` name + `catalogo` menu; `tarif_familias` class-derived under `catalogo`; `Controller/VentasFamilias.php` absent; retirement cleanup wired; no Phase 4 page | ✅ Verified after reconciliation |

No task marked `[x]` lacks a test file and a passing runtime result. The only qualification is 2.4's real-DB claim.

## Requirements coverage

Delta specs: `familias-jerarquia-catalogo` (R-FJ-001…008, 20 scenarios) + `multi-tariff-pricing` (modified R-MT-002, added R-MT-006, 6 scenarios) = **10 requirements / 26 scenarios**.

| Requirement | Scenarios | Verdict | Evidence / gap |
|---|---|---|---|
| R-FJ-001 Hierarchical tree UI | 4/4 | ✅ PASS (unit/contract) | Tree nesting/depth/ordering + save path + cycle + no base-table change. Live browser render not executed (WARNING-6). |
| R-FJ-002 Per-list structure table | 2/3 | ⚠️ PARTIAL | Upsert/no-duplicate and XML FK→`catalogo_listas_precio`/`familias` CASCADE pass; **"Structure flags drive visibility" needs the catalog view (4.1) and is deferred**; cascade is static XML evidence only, not an executed DB cascade. |
| R-FJ-003 Per-list article assignment | 2/2 | ✅ PASS | Upsert and list-scoped read proven at controller level; catalog-view rendering deferred with R-FJ-004. |
| R-FJ-004 Catalog view | 0/2 | ⛔ DEFERRED | Phase 4.1 (`Controller/VentasCatalogoView.php`) is intentionally not implemented and must stay unchecked. |
| R-FJ-005 Non-destructive migration | 4/4 | ✅ PASS (fake-DB evidence) | FK order, idempotency, legacy preservation, orphan skip+log all pass; real DB execution still required (WARNING-2). |
| R-FJ-006 Page identity / fs_access | 1/2 | ⚠️ PARTIAL | Surviving page names + menus pass; retired page absent; **"new page registered consistently" is deferred to 4.1**. Requirement text reconciled to reality. |
| R-FJ-007 Hook compatibility | 2/2 | ✅ PASS (stale premise) | All 4 hook markers present in `ventas_articulo`/`ventas_opcional`; `HookRegistrationTest` green; absorbed UI has zero `@tarifario` dependency. **The premise that tarifario registers these hooks is stale** (WARNING-5). |
| R-FJ-008 Prefixed translations | 0/1 | ⛔ DEFERRED | Phase 4.3. New Phase 3 view currently uses hardcoded Spanish strings; expected until 4.3. |
| R-MT-002 (modified) List source / no dual state | 4/4 | ✅ PASS | Controllers use only `catalogo_listas_precio.codlista`; writes only `catalogo_familia_estructura`; no legacy `codtarifa` consulted by the new capability; existing per-list price tests remain green. Cascade scenario is static XML evidence. |
| R-MT-006 (added) Canonical extension tables | 2/2 | ✅ PASS | `InitUpgradeTest` proves listas → familia-estructura → articulo-estructura; `CatalogoPriceResolverTest` + full regression prove price resolution unchanged. |

**Score**: 6 requirements fully verified, 2 partial, 2 deferred. 21/26 scenarios have passing runtime evidence; the 5 deferred scenarios all trace to Phase 4 (R-FJ-004 ×2, R-FJ-002 visibility ×1, R-FJ-006 new page ×1, R-FJ-008 ×1).

## Design coherence

| Decision | Status | Evidence |
|---|---|---|
| D1 extension tables, base untouched | ✅ | XMLs + `git diff` on `familias.xml`/`familia.php` empty |
| D2 column mapping / legacy parity | ✅ | XMLs match D2; `madre` FK-less; `codfamilia` in article assignment FK-less |
| D3 migration copy-not-rename, flag-gated | ✅ | service + `Init::migrateFamiliaEstructura()` gate; no destructive SQL |
| D4 install order | ✅ | `ensureCatalogTables()` order + `InitUpgradeTest` assertion |
| D5 UI | ⚠️ amended | Landed on `ventas_familia` (surviving page), not the retired `VentasFamilias`; documented in the amendment note and task bullets. `tarif_familias` remains the tree entry point. |
| D6 dep-free + event gate | ⚠️ | Dep-free is clean; event dispatch exists but the payload semantics are questionable (CRITICAL-risk analysis below, rated WARNING) |
| D7 translations | ⛔ deferred | Phase 4.3 |

## D6 event-contract risk (explicit finding)

**What the code does**
- `VentasFamilia::guardarEstructuraFamilia()` dispatches `ArticlePermissionFilterEvent($codfamilia, ACTION_EDIT_ARTICLE, $nick, $codlista)` — family code in the `referencia` slot, list code in the `codtarifa` slot (`Controller/VentasFamilia.php:343-383`).
- `VentasArticulo::guardarAsignacionFamilia()` dispatches the real `referencia` + `$codlista` in the `codtarifa` slot (`Controller/VentasArticulo.php:1106,1149-1164`).

**Event contract** (`Event/ArticlePermissionFilterEvent.php`): `referencia` is documented as the *article* reference; `codtarifa` (optional) is a tariff context. Default allow with zero listeners.

**tarifario listener** (`plugins/tarifario/Services/ArticlePermissionListener.php`): admin → allow; any active tarifa with `GESTOR` → allow; with `EDITOR` → allow only if `puede_editar_articulo_en_tarifa($codtarifa, $nick, $referencia)`; otherwise deny, and it never reads `getCodtarifa()` (it iterates all active tarifas).

**Finding (WARNING)**: the family-structure dispatch violates the event's documented `referencia` contract and can **mis-gate family writes**. A non-admin catalogo_core user who is an `EDITOR` on any tarifa but not a `GESTOR` will be denied when editing a family structure, because the listener resolves `puede_editar_articulo_en_tarifa(..., 'F1')` against an article-assignment table that knows nothing about family codes. A non-admin with no tarifario role at all is also denied. The article-assignment path is defensible (it carries a real article reference); the `codlista`→`codtarifa` slot is harmless today only because the current listener ignores it. The feature is flag-off by default, so no existing user is affected until opt-in — hence WARNING, not CRITICAL. Do not change behavior in verify; decide before enabling the flag: either a dedicated action/event for family-structure writes, or dispatch only on the true article path and gate family edits with `fs_access`, or make the listener treat unknown references as non-applicable. Add payload assertions for both dispatch sites when fixing.

## TDD compliance (Strict TDD active)

| Check | Result | Details |
|---|---|---|
| TDD evidence reported | ❌ | **No `apply-progress.md` / TDD Cycle Evidence table exists in this change directory.** Evidence is reconstructed from commits and test files. |
| All Phase 2/3 tasks have tests | ✅ | 9/9 (2.1–2.4, 3.1–3.5) map to existing test files |
| RED confirmed (tests exist) | ✅ | All named test files exist on disk |
| GREEN confirmed (tests pass) | ✅ | Every Phase 2/3 class passes on independent re-run; full suite 0 failures / 0 errors |
| Triangulation adequate | ⚠️ | Most behaviours have multiple cases; a few are single-case (e.g. one valid re-parent, one orphan shape) |
| Safety net for modified files | ➖ | Not reconstructable without apply-progress |
| Assertion quality | ✅ | No tautologies, ghost loops or implementation-detail-only assertions found in the seven changed test files; expectations assert concrete values |

**TDD compliance**: 5/7 checks pass; the missing apply-progress artifact is a process gap, not a code failure (WARNING-3).

## Warnings

1. **D6 mis-gating risk (WARNING).** Family code in the `referencia` slot + `codlista` in the `codtarifa` slot, with `ACTION_EDIT_ARTICLE`, can deny legitimate non-admin family-structure edits when the tarifario listener is active. Flag-off default limits current impact. Evidence: `Controller/VentasFamilia.php:343-383`, `Controller/VentasArticulo.php:1106,1149-1164`, `plugins/tarifario/Services/ArticlePermissionListener.php:56-88` (article check at line 72).
2. **Task 2.4's "DDEV DB" claim is not met (WARNING).** `FamiliaEstructuraMigrationIdempotencyTest` drives `FamiliaEstructuraMigrationFakeDb`, an in-memory double that emulates the join and `INSERT IGNORE`. Real SQL, real transactions, real FK cascade and the dual-engine conflict clause were not executed against MySQL/PostgreSQL. A live DB smoke is required.
3. **Strict-TDD apply evidence missing (WARNING).** No `apply-progress.md` with a TDD Cycle Evidence table for this change. The green tests and the commit sequence (`test` before/with `feat` slices) support the claim, but it is not formally recorded.
4. **The literal task 4.4 audit criterion is not met today (WARNING).** `tarif_tarifa_familia` / `tarif_tarifa_articulo` are still referenced/read by pre-existing absorbed surfaces (`controller/tarif_familias.php`, `controller/tarif_tarifas.php`, `model/tarif_tarifa_*.php`, `Services/Caracteristica*Migration.php`, `Init.php`). Phase 2/3 introduced **zero** new reads outside `FamiliaEstructuraMigration` (diff evidence), but task 4.4 remains open and its wording will need to distinguish pre-existing absorbed legacy from new canonical capability.
5. **R-FJ-007 premise is stale (WARNING).** After the WU-1 ownership transfer, `tarifario` registers **zero** article/opcional hooks; `HookRegistrationTest` asserts exactly that (4 tests, 9 assertions, green). The 4 hook points remain in the templates; catalogo_core registers the 2 opcional hooks. The requirement's intent still holds, but the statement "the 4 view hooks that tarifario registers" and the implied regression guarantee no longer match reality. Reconcile in Phase 4.2/5.3.
6. **Tree entry point vs. canonical structure UX gap (WARNING).** The Phase 3 UI lives on `ventas_familia` (`showonmenu => false`, linked from `tarif_familias`), while `tarif_familias` still renders the legacy `tarif_tarifa_familia` tree. A user landing on the menu entry point sees the legacy tariff-scoped tree, not the new canonical per-list structure; the canonical UI is one click away on the detail page. This is the documented amendment, not a code defect, but it must be resolved (or explicitly accepted) before go-live.
7. **No live browser / real-DB smoke (WARNING, non-blocking if accepted).** See the checklist below.

## Suggestions

1. Add an event-payload test at both dispatch sites (`referencia`, `action`, `codtarifa`) and a CSRF-invalid re-parent test; they would have caught WARNING-1/6.
2. Ensure `familias` exists before `catalogo_familia_estructura` in `ensureCatalogTables()` (or prove it via a fresh-install smoke). The current list guarantees `catalogo_listas_precio` but not the `familias` FK target; R-FJ-002 says the table must install after both.
3. Run `phpstan` in CI (the configured tool is absent here) and add a coverage/lint gate for the plugin tree if desired.
4. When Phase 4 lands, replace the hardcoded Spanish strings with the prefixed translation keys (R-FJ-008) and add `tests/ViewHookPointsTest`.

## Live smoke still required (non-blocking warning)

These are the only gaps that unit/contract tests cannot close; all require DDEV + browser/DB:

| Smoke | Why |
|---|---|
| Open `index.php?page=ventas_familia&codlista=L1` with the family-structure flag on; confirm the nested tree renders with per-node fields and the selector | R-FJ-001 tree render; no HTTP/browser execution in this verify |
| Submit a valid re-parent POST and a cycle POST through the real CSRF path on `ventas_familia` | R-FJ-001 re-parent/cycle at HTTP level; tests use reflection seams |
| Round-trip an article assignment on `ventas_articulo` for `L1`, then verify it is absent on `L2` | R-FJ-003 at HTTP/DB level |
| Run `FamiliaEstructuraMigration` twice against a real DB with legacy rows + one orphan; verify real `INSERT IGNORE`/`ON CONFLICT`, row counts, legacy tables untouched, FK cascade | R-FJ-005 real-engine behavior; task 2.4's "DDEV DB" claim |
| Fresh install/upgrade: confirm `catalogo_listas_precio` → `familias` → `catalogo_familia_estructura` → `catalogo_articulo_familia_estructura` creation succeeds | R-FJ-002/R-MT-006 install ordering |

## Open issues (deferred, not failures of this slice)

- Phase 4.1 catalog view `ventas_catalogo_view` (R-FJ-004, R-FJ-002 visibility scenario, R-FJ-006 new-page scenario) — unchecked.
- Phase 4.2 frozen-hook test on the catalogo_core side — unchecked (`tests/ViewHookPointsTest` does not exist).
- Phase 4.3 translations (R-FJ-008) — unchecked; Phase 3 view has hardcoded Spanish strings.
- Phase 4.4 dep-free audit test — unchecked; the manual audit above is clean for new code but the literal legacy-read criterion needs the reconciliation described in WARNING-4.
- Phase 5 regression tasks — the commands were executed in this verify (full suite, hook test), but the tasks remain unchecked in `tasks.md` per the requested scope.

## Verdict

**PASS WITH WARNINGS**

Phase 2 and Phase 3 are implemented, wired, flag-gated and green: the full plugin suite reports **1421 tests / 5838 assertions / 0 failures / 0 errors / 2 pre-existing warnings / 1 skip**, every Phase 2/3 task has a named passing test, the base `familias` schema is untouched, the dep-free rule holds for production code, and the page-identity artifacts now describe the surviving `ventas_familia`/`tarif_familias` reality without resurrecting the retired page. The change is **not archive-ready** because Phase 4 remains intentionally unimplemented and because two substantive warnings must be resolved or explicitly accepted first: the D6 event payload can mis-gate family-structure writes (WARNING-1), and the "DDEV DB" migration evidence is fake-DB only (WARNING-2). No CRITICAL finding.
