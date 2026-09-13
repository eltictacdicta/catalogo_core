```yaml
schema: gentle-ai.archive-result/v1
change: unificar-opcionales-en-ventas-opcionales
archived_to: plugins/catalogo_core/openspec/changes/archive/2026-09-12-unificar-opcionales-en-ventas-opcionales/
archive_date: "2026-09-12"
verdict_at_verify: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 12/12
scenarios: 24/24
implementation_tasks: 21/21
composer_dependency_added: false
delivery: human-owned (not committed, not pushed)
```

# Archive Report — `unificar-opcionales-en-ventas-opcionales`

**Change**: `unificar-opcionales-en-ventas-opcionales` (plugin-local SDD)
**SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`) — the core `openspec/` is NOT a tracker for this change.
**Secondary plugin**: `plugins/tarifario` (retirement/repointing only)
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-09-12-unificar-opcionales-en-ventas-opcionales/`
**Verified**: `verify-report.md` — `verdict: pass_with_warnings`, 0 CRITICAL, 0 blockers, 12/12 requirements, 24/24 scenarios, all backed by passing runtime coverage.
**Delivery**: commit / PR / push are human-owned and were **NOT** performed by this archive phase.

## Final-State Authority

This archive report is the terminal record of the change. The `verify-report.md` and `apply-progress.md` inside the archive are intermediate snapshots at the time they were written. Where the orchestrator's explicit final-state facts supersede a snapshot, the final state is recorded here:

- The one archive-blocking item from `verify-report` (WARNING-4: canonical-spec reconciliation) is **resolved** in this archive phase (see "Archive-time spec sync"). It is no longer open.
- The two unchecked Phase 6 tasks are **verification** tasks, not implementation tasks: 6.2 (PHPStan plugin coverage gap — non-applicable) and 6.4 (authenticated browser/DEV-DB smoke — deliberately deferred, non-blocking). Both were explicitly approved as non-blocking by the orchestrator. They are recorded as OPEN warnings below; the SDD closes intentional-with-warnings by design, and no implementation task remains unchecked (Phases 1–5 = 21/21 `[x]`).
- No `verify-report`/`apply-progress` "pending"/"blocked" claim about implemented work is echoed as current. Test counts are carried from the highest-ranked final source (verify re-execution + apply phase finals agree: catalogo_core 511/1756, tarifario 191/705).

## Pre-archive Checks

| # | Check | Result |
|---|-------|--------|
| 1 | All artifacts present (`proposal.md`, `exploration.md`, `design.md`, `specs/opcionales-management/spec.md`, `tasks.md`, `apply-progress.md`, `verify-report.md`) | ✅ present |
| 2 | `verify-report.md` verdict `pass_with_warnings`, 0 CRITICAL, 0 blockers, 12/12 requirements, 24/24 scenarios | ✅ |
| 3 | Implementation tasks complete | ✅ Phases 1–5 = 21/21 `[x]`; Phase 6: 6.1 `[x]`, 6.2 `[ ]` non-applicable, 6.3 `[x]`, 6.4 `[ ]` OPEN non-blocking, 6.5 `[x]` |
| 4 | Composer dependency added? | ✅ No → no `vendor/` step required |
| 5 | Task Completion Gate | ✅ No unchecked **implementation** task. 6.2/6.4 are verification gaps explicitly approved non-blocking (recorded below). |

## Archive-time Spec Sync

### Created canonical spec

- **`plugins/catalogo_core/openspec/specs/opcionales-management/spec.md`** — created from the delta `specs/opcionales-management/spec.md`.
  - Canonical format: title `opcionales-management Specification`, `## Purpose`, `## Requirements`.
  - 12 requirements (OUM-01..OUM-12) + 24 scenarios.
  - The requirement/scenario block was extracted mechanically from the delta; the readback
    `diff <(sed -n '/^### Requirement:/,$p' delta) <(sed -n '/^### Requirement:/,$p' canonical)` is **empty**
    (`REQUIREMENTS-SECTION-IDENTICAL`). Only the delta framing (title/preamble + `## ADDED Requirements`)
    was converted; no requirement text was altered.
  - The sibling-owned `opcionales-tarifa-management` capability was not touched.

### Reconciled canonical spec (verify WARNING-4)

- **`plugins/catalogo_core/openspec/specs/opcionales-tarifa-selector/spec.md`** — every reference to the retired page `tarif_opcionales` removed and repointed; OTS requirement intent unchanged:
  - Purpose (view list): `tarif_opcionales` → `ventas_opcionales`.
  - OTS-08 body/scenario: list filter wording `tarif_opcionales` → `ventas_opcionales`.
  - OTS-09 scenario: retired controller `tarif_opcionales` → surviving controller `tarif_configurador_opcionales`.
  - Post-check: `grep -n 'tarif_opcionales'` on the reconciled spec → exit 1, **0 matches**.

## Final Suite Counts (final-state)

| Suite | Baseline | Final | Delta |
|-------|----------|-------|-------|
| `plugins/catalogo_core` (`phpunit -c plugins/catalogo_core/phpunit.xml`) | 475 tests / 1517 assertions OK | **511 tests / 1756 assertions OK** (Warnings 25, Skipped 1 — unchanged from baseline) | +36 tests / +239 assertions |
| `plugins/tarifario` (`phpunit -c plugins/tarifario/phpunit.xml`) | 198 tests / 759 assertions (Skipped 3) | **191 tests / 705 assertions OK** (Skipped 3) | −7 tests / −54 assertions, **zero failures** |

The tarifario drop is the designed relocation of the opcional behavior coverage into `catalogo_core`
(`TarifOpcionalTabEndpointTest`, 6 methods) plus the retirement of the opcional hook surface; no assertion
was dropped — it moved with the endpoint (AD-7).

## Files Added / Changed / Deleted (both plugins)

All paths below are the change's authored working-tree deltas. Both working trees remain **uncommitted**
(delivery is human-owned).

### `plugins/catalogo_core/`

| File | Action |
|------|--------|
| `extras/VentasOpcionalesListTrait.php` | **Added** — controller-agnostic list trait (selector, filters, filtered pagination, state/price caches, accessors, CSRF-guarded toggles/create/delete, filtered Excel export, native pagination). |
| `Services/CatalogoCurrencyFormatter.php` | **Added** — `symbol()` / `format()` over `extras/fs_divisa_tools.php`. |
| `Controller/VentasOpcionales.php` | **Changed** — `use \VentasOpcionalesListTrait`, `TOGGLE_ACTIONS`, rewritten `privateCore()` dispatch; page-data/signature frozen. |
| `View/ventas_opcionales.html.twig` | **Changed** — htmx 4 + Alpine CSP rewrite (body-swap filters, `hx-post` toggles, nonce'd `Alpine.data()`, no bootbox/no `\|raw`/no `hx-delete`). |
| `controller/tarif_opcional_tab.php` | **Added** — `fbase_controller` + `TarifarioOpcionalStateTrait`; GET rows fragment, POST save with `requireCsrf()` + `puede_editar_opcional()`. |
| `View/Hooks/ventas_opcional_tabs_after.html.twig` | **Added** (moved from tarifario) — tab header with lazy-load `hx-get`. |
| `View/Hooks/ventas_opcional_tab_pane_after.html.twig` | **Added** (moved) — pane, htmx/Alpine boot. |
| `View/Hooks/partials/opcional_precios_rows.html.twig` | **Added** (moved) — per-tarifa rows, `hx-post` + inherited CSRF. |
| `View/Hooks/partials/opcional_tab_save_script.html.twig` | **Added** (split) — nonce'd `Alpine.data()` behind `alpine:init`; `htmx:after:request` JSON swap. |
| `Init.php` | **Changed** — registers the 2 opcional hooks behind an idempotency guard; `retireTarifOpcionalesPage()` wired into `upgrade()`. |
| `model/tarif_opcional.php` | **Changed** — `url()` no-id fallback → `ventas_opcionales`. |
| `model/tarif_opcional_precio.php` | **Changed** — `url()` no-id fallback → `ventas_opcionales`. |
| `View/tarif_opcional_edit.html.twig` | **Changed** — back-links repointed (list link carries `b_codtarifa`). |
| `View/tarif_opcional.html.twig` | **Changed** — back-links repointed. |
| `View/tarif_opcional_precios.html.twig` | **Changed** — back-links repointed. |
| `controller/tarif_opcionales.php` | **Deleted** — retired page controller, no redirect alias. |
| `View/tarif_opcionales.html.twig` | **Deleted** — retired page view. |

### `plugins/tarifario/`

| File | Action |
|------|--------|
| `Init.php` | **Changed** — `HOOK_TEMPLATES` drops the 2 opcional entries (keeps the `ventas_articulo_*` pair). |
| `View/Hooks/ventas_opcional_tabs_after.html.twig` | **Deleted** — moved to catalogo_core. |
| `View/Hooks/ventas_opcional_tab_pane_after.html.twig` | **Deleted** — moved to catalogo_core. |
| `View/Hooks/partials/opcional_precios_rows.html.twig` | **Deleted** — moved to catalogo_core. |
| `View/Hooks/partials/tab_save_script.html.twig` | **Changed** — article-only copy (opcional fields/branch removed). |
| `controller/tarif_tab_precios.php` | **Changed** — opcional methods/require removed; article path byte-identical. |
| `View/tarif_actualizar_precios.html.twig` | **Changed** — opcionales link repointed. |
| `View/tarif_articulo_precios.html.twig` | **Changed** — opcionales link repointed. |
| `View/tarif_articulos.html.twig` | **Changed** — opcionales link repointed. |
| `View/tarif_historial_precios.html.twig` | **Changed** — opcionales link repointed. |

## Tests Added / Updated / Deleted

| Test file | Plugin | Action |
|-----------|--------|--------|
| `tests/CatalogoOpcionalesUnifiedControllerTest.php` | catalogo_core | **Added** — unified list behavior/source contracts (OUM-01..OUM-07, OUM-09). |
| `tests/CatalogoOpcionalesHtmxContractTest.php` | catalogo_core | **Added** — htmx 4 + Alpine CSP source contracts for the unified view (OUM-08). |
| `tests/Integration/CatalogoOpcionalesHookOwnershipTest.php` | catalogo_core | **Added** — hook registration/idempotency, render-without-tarifario, endpoint contract, moved-tab htmx/Alpine (OUM-10). |
| `tests/TarifOpcionalTabEndpointTest.php` | catalogo_core | **Added (relocated)** — endpoint behavior (rows currency, deny, CSRF, save, delete), 6 methods. |
| `tests/TarifOpcionalesControllerMasterStateTest.php` | catalogo_core | **Updated** — list contracts repointed to the unified controller/view/trait; edit/precios methods untouched. |
| `tests/TarifOpcionalesHtmxContractTest.php` | catalogo_core | **Updated** — `LIST_VIEW` repointed to `View/ventas_opcionales.html.twig`. |
| `tests/TarifOpcionalesControllerContractTest.php` | catalogo_core | **Updated** — slug dropped to 3 survivors; `test_all_surviving_controllers_*` renames. |
| `tests/InitUpgradeTest.php` | catalogo_core | **Updated** — added `test_upgrade_retires_tarif_opcionales_page_idempotently`. |
| `tests/Integration/HookRegistrationTest.php` | tarifario | **Updated** — frozen hooks reduced to the article pair; opcional assertions/coverage relocated. |
| `tests/Controller/TarifTabPreciosTest.php` | tarifario | **Updated** — opcional surface removed; article/CSRF contract kept. |

**Deleted tests**: none as files. Coverage was **relocated**, not dropped: the removed tarifario
`test_opcional_hooks_render_guarded_by_is_new` and the opcional half of `TarifTabPreciosTest` moved into
`TarifOpcionalTabEndpointTest` + `CatalogoOpcionalesHookOwnershipTest`. This accounts for the tarifario
−7 test delta.

## Open Warnings (recorded by verify, carried forward)

1. **(a) Root PHPStan does not analyze `plugins/catalogo_core`.** `ddev exec composer phpstan` exits 0 but `phpstan.neon` declares `paths: [src, tests]` only, so the green run is **vacuous for the plugin code**. Phase 6.2 is therefore non-applicable under the current config (no plugin-local PHPStan config exists). Non-blocking; remediation proposed: add `plugins/*/` coverage or a plugin-local config + Composer script. Same gap as the prior `opcionales-tarifa-selector-htmx4` change.
2. **(b) Authenticated browser/DEV-DB smoke not executed** (tasks.md 6.4). No authenticated session/seeded fixture was available during verify. Delta-spec compliance is proven by DB-free behavior tests + source contracts + static audits. Items (a)–(g) of the smoke checklist remain deferred; non-blocking. Run once a dev session exists and record results here.
3. **(c) Root Plugins suite has 10 pre-existing/unrelated failures** (`ddev exec php vendor/bin/phpunit --testsuite Plugins`): 7× OidcProvider (DB/schema, order-dependent), 2× `TarifTarifaOpcionalPrecedenceTest` (pre-existing `processIsolation` incompatibility under the non-isolated root config; green under the isolated plugin config), 1× `LegacySupportTest` version drift. None introduced by this change; the root suite is not a valid gate here.
4. **(d) Canonical-spec reconciliation** — **RESOLVED in this archive** (see "Archive-time spec sync"). The `opcionales-tarifa-selector/spec.md` `tarif_opcionales` references are gone; the archive blocker is cleared.
5. **(minor, verify WARNING-5)** `tasks.md` WU-1b references a test method name that does not exist; the canonical page/wrapper contract is actually covered by `VentasOpcionalesControllerTest`. Documentation wording only, no functional gap.

## Tarifario Companion Note

`plugins/tarifario`'s edits are **uncommitted** (separate git repo, branch `master`). Landing order is
**additive-then-subtractive**: the catalogo_core replacement (hook templates + `tarif_opcional_tab` endpoint)
must land **before** the tarifario removal/usplit, so the opcional tab never goes dark. Never land the
tarifario subtraction first.

## Core `openspec/` Cleanliness

- No `openspec/changes/unificar-opcionales-en-ventas-opcionales/` in the core `openspec/`.
- No `openspec/specs/opcionales-management/` in the core `openspec/`.
- `grep -rn 'unificar-opcionales|opcionales-management' openspec/` → no matches.
- The plugin-local `plugins/catalogo_core/openspec/` is the only SDD location for this change.

## Archive Verification (mechanical)

The change folder was moved with a shell transaction (`git mv`, falling back to `mv` because the change dir
was untracked), against a pre-move recursive snapshot. The mandatory readback:

```text
$ diff -r "<snapshot>/source" "openspec/changes/archive/2026-09-12-unificar-opcionales-en-ventas-opcionales"
DIFF-EMPTY-ARCHIVE-BYTE-IDENTICAL
```

Empty `diff -r` = byte-identical archive. The active change dir is gone
(`SOURCE-ABSENT-OK`). `archive-report.md` is additive and excluded from the source/destination comparison.

## Closing Summary

`ventas_opcionales` is now the single canonical opcionales management page, absorbing the tarifa selector,
filters, per-tarifa state/price, CSRF-guarded toggles, creation, deletion, Excel export and native filtered
pagination. `tarif_opcionales` is eliminated (controller, view, page links, idempotent `fs_page` retirement,
no redirect alias), and the opcional "Tarifas" tab is owned by `catalogo_core` (hooks + endpoint). The unified
list and the moved tab use htmx 4 + Alpine CSP with locked contracts preserved. The change is planned,
implemented, independently verified (`pass_with_warnings`), and archived. Two non-blocking verification gaps
(plugin PHPStan coverage, authenticated smoke) and the pre-existing root-suite failures remain recorded, not
silently resolved.

**Delivery status**: commit / PR / push are human-owned and were **NOT** performed.
