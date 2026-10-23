# Verify Report — `migrar-familias-tarifario-a-catalogo-core` (Phase 2 + Phase 3 slice)

```yaml
schema: gentle-ai.verify-result/v1
verdict: pass_with_warnings
scope_verified: Phase 2 (2.1–2.4) + Phase 3 (3.1–3.5) + post-verify remediation R1/R2; Phase 1 regression re-run
reverify_round: 2
remediation_commits: 4854f55, a541c63
scope_deferred: Phase 4 (4.1–4.4) — explicitly out of scope, left unchecked
blockers: 0
critical_findings: 0
requirements: 6/10 fully verified (2 partial, 2 deferred to Phase 4)
scenarios: 21/26 verified (5 deferred to Phase 4)
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_result: "Tests: 1427, Assertions: 5853, Failures: 0, Errors: 0, Warnings: 2, Skipped: 1"
test_output_hash: sha256:012e9b27d765dc10c878395f819ab03b3d447d00177b5cc5900640d9a10e7c60
build_command: ddev exec composer phpstan
build_result: "NOT AVAILABLE — vendor/dev-tools/bin/phpstan missing in this environment"
evidence_revision: sha256:5b073f15d337bdacd26bd3e3dce7fc650800f0844d05aef463f60675eb1fa3a4
```

- **Change**: `plugins/catalogo_core/openspec/changes/migrar-familias-tarifario-a-catalogo-core/` (plugin-local, `strict_tdd: true`).
- **Branch**: `feature/familias-estructura-pr3` (own repo), based on `feature/familias-estructura-pr2b`; based on `main` at `d26d436`.
- **Verification type**: independent, two rounds. Round 1 verified `fdcab83`; **round 2 re-verified the remediation commits `4854f55` and `a541c63`** on the same branch against the current working tree. Apply claims were not trusted in either round. No production behavior was changed by this verification.
- **Not performed**: no commit of code, no push, no PR, no merge, no archive. Phase 4 tasks remain unchecked by design.
- **Remediation re-verified in round 2**: (R1) family-structure writes no longer dispatch `ArticlePermissionFilterEvent` — authorization is page access; (R2) `tarif_familias` exposes a flag-gated link to the canonical `ventas_familia` structure editor. Artifacts were amended accordingly in `design.md` (D6 + Data Flow + D5 discoverability), the delta spec (R-FJ-001 authorization note), `tasks.md` (Remediation section) and this report (appendix + round-2 annotations). Phase 4 remains unchecked and no Phase 4 artifact exists.
- **Artifact reconciliation performed in round 1**: page identity (`ventas_familias` → surviving `ventas_familia` / `tarif_familias`) is now reflected in the delta spec (R-FJ-001, R-FJ-006), `design.md` (Technical Approach, amendment note, D5, data flow, file changes, testing strategy, assumption 9), `tasks.md` (Phase 3 bullets 3.1–3.5, work-unit row, deferred 4.1 menu reference) and a historical note in `proposal.md`. The retired page is never resurrected; no Phase 4 artifact was created.

## Re-verification round 2 — post-remediation (2026-10-23)

Base previously verified: `fdcab83`. Two remediation commits landed on `feature/familias-estructura-pr3` and were re-verified independently against the current working tree.

| Commit | Role |
|---|---|
| `4854f55` | Removed the family-structure `ArticlePermissionFilterEvent` dispatch; authorization is page access |
| `a541c63` | Flag-gated discoverability link from `tarif_familias` to the canonical `ventas_familia` editor |

**Commands re-run**: full plugin suite; focused `FamiliaEstructuraTreeTest`, `ArticleFamilyAssignmentTest`, `TarifFamiliasStructureLinkTest`, `FamiliaPageNamePreservationTest`, `VentasFamiliaCycleGuardTest`, and the Phase 2 service classes; `HookRegistrationTest` (tarifario); a runtime event-payload probe for the assignment path; static greps for the event symbol, the retired/new page files and Phase 4 artifacts.

**Event boundary audit**:

| Path | Dispatches `ArticlePermissionFilterEvent`? | Evidence |
|---|---|---|
| Family-structure save (`VentasFamilia::guardarEstructuraFamilia`) | **No** | symbol absent from `Controller/VentasFamilia.php`; `test_save_is_not_gated_by_the_article_permission_event` registers a denying listener, asserts **0 listener calls**, persists the row and reports no error |
| Family detail / re-parent / delete (`VentasFamilia`) | **No** | same file has no event references; authorization is `enforcePageAccessOrExit()` (`src/Core/Base/Controller.php:152,195-207`) plus `allow_delete` for deletes |
| Article assignment (`VentasArticulo::guardarAsignacionFamilia`) | **Yes, with a real article `referencia`** | runtime probe: `{"dispatched":true,"referencia":"REF-42","action":"edit_article","fourth_arg":"L1","nick":"juan"}`; `ArticleFamilyAssignmentTest` 7/28 green including the deny path |
| Price / article edit / import paths | Yes, unchanged | pre-existing dispatches in `VentasArticulo` / `VentasArticulos` / `ArticuloExcelPermissionGate` |

**Discoverability and page identity**:
- `controller/tarif_familias.php` exposes `family_structure` through `loadFamilyStructureFlag()` (default `false`); `View/tarif_familias.html.twig` merges the toolbar entry only inside `{% if fsc.family_structure %}`.
- `TarifFamiliasStructureLinkTest` 5/11 green: flag-on reaches the view data contract, an absent setting resets to false, the link is inside the guard, exactly one link exists, and `page=ventas_familias` is never linked.
- `Controller/VentasFamilias.php` and `Controller/VentasCatalogoView.php` do not exist; Phase 4 tasks 4.1–4.4 remain `[ ]`.

**Round-2 suite result**: `Tests: 1427, Assertions: 5853, Failures: 0, Errors: 0, Warnings: 2, Skipped: 1` — the same two pre-existing warnings and the same pre-existing skip as round 1; zero new failures.

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
| 16 | Round-2 affected focused classes | per-file runner | `FamiliaEstructuraTree` **12/56** · `ArticleFamilyAssignment` **7/28** · `TarifFamiliasStructureLink` **5/11** · `FamiliaPageNamePreservation` 4/6 · `VentasFamiliaCycleGuard` 3/11 · `FamiliaEstructuraMigration` 10/37 · `FamiliaTreeGuard` 6/12 · `FamiliaEstructuraMigrationIdempotency` 3/16 — all OK |
| 17 | Round-2 full plugin suite | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --display-warnings --display-skipped` | **Tests: 1427, Assertions: 5853, Failures: 0, Errors: 0, Warnings: 2, Skipped: 1** — OK |
| 18 | Assignment event payload (runtime) | `php` probe against `dispatchFamilyAssignmentFilter('REF-42','juan','L1')` | `{"dispatched":true,"referencia":"REF-42","action":"edit_article","fourth_arg":"L1","nick":"juan"}` — real article reference |
| 19 | Family-structure event dispatch removed | `grep -n "ArticlePermissionFilterEvent\|dispatchFamilyStructureFilter" Controller/VentasFamilia.php` | **zero hits** |
| 20 | No resurrected / Phase 4 page | `ls Controller/VentasFamilias.php Controller/VentasCatalogoView.php`; `grep "^- \[ \] 4\." tasks.md` | both files absent; 4.1–4.4 still unchecked |
| 21 | Discoverability link gated | `tests/TarifFamiliasStructureLinkTest.php` + `View/tarif_familias.html.twig:120-129` | link inside `{% if fsc.family_structure %}`; flag-off contract test green; `page=ventas_familias` not linked |
| 22 | tarifario hook regression (round 2) | `HookRegistrationTest` | **OK (4 tests, 9 assertions)** |

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
   - **Re-verify round 2: RESOLVED.** `4854f55` removed the dispatch and the `dispatchFamilyStructureFilter()` helper; `Controller/VentasFamilia.php` now has zero `ArticlePermissionFilterEvent` references, and `test_save_is_not_gated_by_the_article_permission_event` asserts 0 listener calls while the save persists. Authorization is page access (`enforcePageAccessOrExit()`) plus `allow_delete` for deletes. **Accepted residual**: the article-assignment path still passes `codlista` in the event's 4th (`codtarifa`) argument; harmless with the current listener (which ignores `getCodtarifa()`) but semantically imprecise — see S5.
2. **Task 2.4's "DDEV DB" claim is not met (WARNING).** `FamiliaEstructuraMigrationIdempotencyTest` drives `FamiliaEstructuraMigrationFakeDb`, an in-memory double that emulates the join and `INSERT IGNORE`. Real SQL, real transactions, real FK cascade and the dual-engine conflict clause were not executed against MySQL/PostgreSQL. A live DB smoke is required.
   - **Re-verify round 2: STILL-OPEN.** No real-DB test was added by the remediation; the idempotency test is still the in-memory fake (`3 tests / 16 assertions`, green). Migration execution on a real engine remains part of the live smoke checklist.
3. **Strict-TDD apply evidence missing (WARNING).** No `apply-progress.md` with a TDD Cycle Evidence table for this change. The green tests and the commit sequence (`test` before/with `feat` slices) support the claim, but it is not formally recorded.
   - **Re-verify round 2: STILL-OPEN.** The remediation commits added tests, but no `apply-progress.md` or TDD Cycle Evidence table was created; the process gap persists (not a code failure).
4. **The literal task 4.4 audit criterion is not met today (WARNING).** `tarif_tarifa_familia` / `tarif_tarifa_articulo` are still referenced/read by pre-existing absorbed surfaces (`controller/tarif_familias.php`, `controller/tarif_tarifas.php`, `model/tarif_tarifa_*.php`, `Services/Caracteristica*Migration.php`, `Init.php`). Phase 2/3 introduced **zero** new reads outside `FamiliaEstructuraMigration` (diff evidence), but task 4.4 remains open and its wording will need to distinguish pre-existing absorbed legacy from new canonical capability.
   - **Re-verify round 2: STILL-OPEN (deferred to 4.4).** The remediation diff (`4854f55`, `a541c63`) added no legacy-table reads outside the migration service; task 4.4 remains unchecked. The criterion wording still needs reconciliation before it can pass.
5. **R-FJ-007 premise is stale (WARNING).** After the WU-1 ownership transfer, `tarifario` registers **zero** article/opcional hooks; `HookRegistrationTest` asserts exactly that (4 tests, 9 assertions, green). The 4 hook points remain in the templates; catalogo_core registers the 2 opcional hooks. The requirement's intent still holds, but the statement "the 4 view hooks that tarifario registers" and the implied regression guarantee no longer match reality. Reconcile in Phase 4.2/5.3.
   - **Re-verify round 2: STILL-OPEN.** `HookRegistrationTest` re-run is still `OK (4 tests, 9 assertions)` and still asserts zero tarifario registration; neither the spec wording nor the Phase 4.2 `ViewHookPointsTest` changed.
6. **Tree entry point vs. canonical structure UX gap (WARNING).** The Phase 3 UI lives on `ventas_familia` (`showonmenu => false`, linked from `tarif_familias`), while `tarif_familias` still renders the legacy `tarif_tarifa_familia` tree. A user landing on the menu entry point sees the legacy tariff-scoped tree, not the new canonical per-list structure; the canonical UI is one click away on the detail page. This is the documented amendment, not a code defect, but it must be resolved (or explicitly accepted) before go-live.
   - **Re-verify round 2: RESOLVED for discoverability.** `a541c63` adds a flag-gated toolbar entry on `tarif_familias` (`Estructura por lista` → `index.php?page=ventas_familia`), proven by `TarifFamiliasStructureLinkTest` 5/11 and the `{% if fsc.family_structure %}` guard. **Accepted residual**: the entry page still shows the legacy tariff-scoped tree and both trees coexist by design (D6 item 3); the canonical editor is now explicitly reachable, which was the actionable gap.
7. **No live browser / real-DB smoke (WARNING, non-blocking if accepted).** See the checklist below.
   - **Re-verify round 2: STILL-OPEN (accepted as non-blocking).** The remediation was verified by unit/contract tests and static inspection; no HTTP render, authenticated POST or real-DB migration was executed. The checklist below now includes the `tarif_familias` link on/off smoke.

## Suggestions

1. Add an event-payload test at both dispatch sites (`referencia`, `action`, `codtarifa`) and a CSRF-invalid re-parent test; they would have caught WARNING-1/6.
   - **Round 2**: partially addressed — the family-path regression tests now pin the no-dispatch boundary; the assignment payload is proven by a manual runtime probe but still has no committed assertion.
2. Ensure `familias` exists before `catalogo_familia_estructura` in `ensureCatalogTables()` (or prove it via a fresh-install smoke). The current list guarantees `catalogo_listas_precio` but not the `familias` FK target; R-FJ-002 says the table must install after both.
   - **Round 2**: still-open; the remediation did not change `ensureCatalogTables()`.
3. Run `phpstan` in CI (the configured tool is absent here) and add a coverage/lint gate for the plugin tree if desired.
   - **Round 2**: still-open; `vendor/dev-tools/bin/phpstan` is still missing in this environment.
4. When Phase 4 lands, replace the hardcoded Spanish strings with the prefixed translation keys (R-FJ-008) and add `tests/ViewHookPointsTest`.
   - **Round 2**: still-open; both belong to the unchecked Phase 4 tasks (4.2/4.3).
5. Consider omitting the event's 4th argument for article-assignment writes, or renaming it from `codtarifa` to a list-neutral context, so a future listener cannot mistake `codlista` for a tariff code. (Accepted residual of WARNING-1.)

## Live smoke still required (non-blocking warning)

These are the only gaps that unit/contract tests cannot close; all require DDEV + browser/DB:

| Smoke | Why |
|---|---|
| Open `index.php?page=ventas_familia&codlista=L1` with the family-structure flag on; confirm the nested tree renders with per-node fields and the selector | R-FJ-001 tree render; no HTTP/browser execution in this verify |
| Submit a valid re-parent POST and a cycle POST through the real CSRF path on `ventas_familia` | R-FJ-001 re-parent/cycle at HTTP level; tests use reflection seams |
| Round-trip an article assignment on `ventas_articulo` for `L1`, then verify it is absent on `L2` | R-FJ-003 at HTTP/DB level |
| Run `FamiliaEstructuraMigration` twice against a real DB with legacy rows + one orphan; verify real `INSERT IGNORE`/`ON CONFLICT`, row counts, legacy tables untouched, FK cascade | R-FJ-005 real-engine behavior; task 2.4's "DDEV DB" claim |
| Fresh install/upgrade: confirm `catalogo_listas_precio` → `familias` → `catalogo_familia_estructura` → `catalogo_articulo_familia_estructura` creation succeeds | R-FJ-002/R-MT-006 install ordering |
| Open `index.php?page=tarif_familias` with the flag off, then on: the toolbar shows no `Estructura por lista` entry when off and exactly one when on, linking to `ventas_familia` | Discoverability is contract-tested only; no rendered HTTP response was asserted |

## Open issues (deferred, not failures of this slice)

- Phase 4.1 catalog view `ventas_catalogo_view` (R-FJ-004, R-FJ-002 visibility scenario, R-FJ-006 new-page scenario) — unchecked.
- Phase 4.2 frozen-hook test on the catalogo_core side — unchecked (`tests/ViewHookPointsTest` does not exist).
- Phase 4.3 translations (R-FJ-008) — unchecked; Phase 3 view has hardcoded Spanish strings.
- Phase 4.4 dep-free audit test — unchecked; the manual audit above is clean for new code but the literal legacy-read criterion needs the reconciliation described in WARNING-4.
- Phase 5 regression tasks — the commands were executed in this verify (full suite, hook test), but the tasks remain unchecked in `tasks.md` per the requested scope.

## Verdict

**PASS WITH WARNINGS**

Phase 2 and Phase 3 are implemented, wired, flag-gated and green. **Round 2** re-ran the full plugin suite after the remediation: **1427 tests / 5853 assertions / 0 failures / 0 errors / 2 pre-existing warnings / 1 skip**. The two substantive round-1 warnings that were actionable are now closed: (WARNING-1) family-structure writes no longer dispatch `ArticlePermissionFilterEvent` — authorization is page access, and the article-assignment path still dispatches with a real `referencia`; (WARNING-6) the canonical editor is now discoverable through a flag-gated `tarif_familias` toolbar link. The change is **still not archive-ready** because Phase 4 remains intentionally unimplemented and the remaining warnings are honest: the "DDEV DB" migration evidence is fake-DB only (WARNING-2), the strict-TDD apply artifact is still missing (WARNING-3), task 4.4's literal legacy-read criterion is unresolved (WARNING-4), R-FJ-007's premise is stale (WARNING-5), and no live browser/real-DB smoke was executed (WARNING-7). No CRITICAL finding.

## Remediation (post-verify, 2026-10-23)

Appended after the verifier's round-1 findings. The findings themselves are preserved; the verifier later added round-2 state annotations (`resolved` / `still-open` / `accepted`) and updated the header/verdict with the round-2 numbers. The figures below were independently re-run and confirmed by the verifier.

### Fix 1 — family-structure writes are no longer gated by the article event (WARNING-1)

**Decision**: a family code is not an article reference. Family-structure writes
are authorized by page access (`ventas_familia` in `fs_access` / role
permissions) plus `allow_delete` for deletes. Only article-assignment writes
keep dispatching `ArticlePermissionFilterEvent`, because there the subject is a
real article reference.

**Change**: removed the dispatch and the `dispatchFamilyStructureFilter()` helper
from `Controller/VentasFamilia::guardarEstructuraFamilia()` (with its unused
imports). `Controller/VentasArticulo` is unchanged.

**Evidence**:
- `tests/FamiliaEstructuraTreeTest.php::test_save_is_not_gated_by_the_article_permission_event`
  — a denying listener is registered, the listener is never invoked, the save
  persists the row and reports no error.
- `tests/FamiliaEstructuraTreeTest.php::test_save_path_keeps_zero_article_event_references`
  — regression guard against re-introducing the dispatch.
- `ArticleFamilyAssignmentTest` stays green (7 tests / 28 assertions): the
  article path still gates.
- Design D6 + Data Flow and spec R-FJ-001 carry the amendment note.

### Fix 2 — canonical structure UI is discoverable (WARNING-6)

**Change**: `controller/tarif_familias.php` exposes `family_structure` from
`CatalogoOptions::familyStructureEnabled()` via a `loadFamilyStructureFlag()`
seam. `View/tarif_familias.html.twig` renders a toolbar button
("Estructura por lista" → `index.php?page=ventas_familia`) only when the flag is
on; flag-off renders nothing and the legacy tree behavior is untouched. No page
was resurrected and no page name/menu changed.

**Evidence**:
- `tests/TarifFamiliasStructureLinkTest.php` — 5 tests / 11 assertions: flag-on
  reaches the view contract, absent flag defaults off (and clears a stale
  value), `private_core()` loads the flag, the link is inside the
  `{% if fsc.family_structure %}` guard, and `page=ventas_familias` stays
  unlinked.
- Regressions green: `TarifFamiliasControllerContract` 13/24,
  `TarifFamiliasHierarchy` 4/18, `TarifFamiliasToggle` 11/50,
  `TarifFamiliasAddFamilia` 7/9, `TarifFamiliasFragment` 13/29,
  `CatalogoCoreHookMarkers` 19/106.

### Post-remediation suite result

`ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` —
**Tests: 1427, Assertions: 5853, Failures: 0, Errors: 0, Warnings: 2, Skipped: 1**
(two pre-existing warnings and the one pre-existing skip are unchanged).

Phase 4 remains unimplemented and unchecked; this remediation created no Phase 4
artifact.

## Round 2 finding ledger (independent re-verify)

| # | Finding | Round 1 | Round 2 state | Evidence |
|---|---|---|---|---|
| W1 | D6 event mis-gates family-structure writes | open | **resolved** | `4854f55`; zero event refs in `VentasFamilia.php`; `test_save_is_not_gated...` (0 calls, save persists). Residual: assignment `codlista` in the 4th arg — **accepted** (S5). |
| W2 | Migration evidence is fake-DB, not "DDEV DB" | open | **still-open** | `FamiliaEstructuraMigrationIdempotencyTest` still uses `FamiliaEstructuraMigrationFakeDb` (3/16 green); no real-engine run. |
| W3 | Strict-TDD apply artifact missing | open | **still-open** | no `apply-progress.md`; remediation commits added tests but no TDD Cycle Evidence table. |
| W4 | Task 4.4 literal legacy-read criterion unmet | open | **still-open** | 4.4 unchecked; remediation diff added no legacy reads outside `FamiliaEstructuraMigration`. |
| W5 | R-FJ-007 premise stale | open | **still-open** | `HookRegistrationTest` still `OK (4/9)` asserting zero tarifario registration; spec wording unchanged. |
| W6 | Canonical structure UI not discoverable from the tree entry point | open | **resolved** | `a541c63`; flag-gated `Estructura por lista` link; `TarifFamiliasStructureLink` 5/11; `page=ventas_familias` unlinked. Residual coexistence **accepted**. |
| W7 | No live browser/real-DB smoke | open | **accepted** (non-blocking) | Unit/contract only; live checklist extended with the link-on/off smoke. |
| S1 | Add event-payload / CSRF-negative tests | open | **partially addressed** | family no-dispatch pinned; assignment payload proven by a manual probe but not committed as a test. |
| S2 | Ensure `familias` before the structure table | open | **still-open** | `Init::ensureCatalogTables()` unchanged; fresh-install smoke remains. |
| S3 | Run PHPStan / lint gate | open | **still-open** | `vendor/dev-tools/bin/phpstan` still absent. |
| S4 | Phase 4 translations + `ViewHookPointsTest` | open | **still-open** | Phase 4 tasks 4.2/4.3 remain unchecked. |
| S5 | Assignment event's 4th argument is `codlista` in a `codtarifa` slot | new (residual) | **accepted** | Runtime probe: `fourth_arg=L1`; current listener ignores `getCodtarifa()`, so no mis-gate today. |

**Round 2 scope note**: Phase 4 remains out of scope and unchecked; the remediation created no Phase 4 artifact. No production code was modified by this verification.
