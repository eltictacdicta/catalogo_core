```yaml
schema: gentle-ai.archive-result/v1
change: opcionales-tarifa-selector-htmx4
archived: 2026-09-12
archive_path: plugins/catalogo_core/openspec/changes/archive/2026-09-12-opcionales-tarifa-selector-htmx4/
canonical_spec: plugins/catalogo_core/openspec/specs/opcionales-tarifa-selector/spec.md
verify_verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 10/10
scenarios: 16/16
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_result: 475 tests / 1517 assertions / 25 warnings / 1 skipped / exit 0
baseline: 455 tests / 1381 assertions
delivery: human-owned (no commit, branch, push or PR performed)
core_openspec_entries: none
```

# Archive Report — `opcionales-tarifa-selector-htmx4`

**Change**: `opcionales-tarifa-selector-htmx4` (plugin-local SDD, `plugins/catalogo_core/openspec/`)
**Archived**: 2026-09-12
**Delta capability**: `opcionales-tarifa-selector` (new, ADDED-only: 10 requirements / 16 scenarios)
**Disposition**: **archived** — verify verdict `pass_with_warnings`, 0 CRITICAL, 0 blockers, all implementation tasks complete. The two open warnings recorded by verify are carried forward verbatim into this report as accepted, non-blocking residuals.
**Mode**: Strict TDD (`strict_tdd: true`); runner `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`.

## Executive Summary (Closing)

The `tarif_opcional_edit` view gained a tarifa selector that scopes the editable "Precios por tarifa" panel to the selected tarifa while retaining a compact all-tarifas read-only overview, with a safe fallback for unknown/absent `codtarifa`. The three opcionales views (`tarif_opcional_edit`, `tarif_opcional_precios`, `tarif_opcionales`) migrated to htmx 4 + Alpine CSP: body-swap selectors and filters with URL push, colon-event names only, nonce'd `Alpine.data()` registration behind the `alpine:init` guard, and idempotent re-init on `htmx:after:swap`. No model, configurator, `tpvmod` or `ventas_opcionales` file changed, and the test-locked contracts (`extends fbase_controller`, the bulk `guardar_precios_tarifas` body, master `effective(`/`set_*` reads, locked view strings, POST toggles + CSRF) stayed intact.

Every automatable gate is green: the full plugin suite is **475 tests / 1517 assertions** (baseline 455 / 1381; warnings 25 and skipped 1 unchanged from baseline), the 5-class regression gate is 63 tests / 242 assertions, the two new test files are 20 tests / 136 assertions, and the static source/grep audits are clean. Two non-blocking warnings remain open and are recorded below.

## Final State (Final-State Authority)

| Item | Final value at close | Source |
|------|----------------------|--------|
| CRITICAL findings | 0 | verify-report `critical_findings: 0` |
| Blockers | 0 | verify-report frontmatter |
| Requirements / scenarios compliant | 10/10 / 16/16 | verify-report §Spec Compliance Matrix |
| Implementation tasks (Phases 1–5) | 19/19 complete | verify-report §Completeness; `tasks.md` all `[x]` |
| Plugin suite | exit 0; 475 tests / 1517 assertions / 25 warnings / 1 skipped | verify-report §Build & Tests |
| Baseline | 455 tests / 1381 assertions | apply-progress §1 / verify-report |
| Regression gate (5 locked classes) | OK — 63 tests / 242 assertions | verify-report §Build & Tests |
| New test files | OK — 20 tests / 136 assertions | verify-report §Build & Tests |
| `ddev exec composer phpstan` | exit 0, `[OK] No errors` (but does not scan the plugin — see OPEN WARNING-1) | verify-report §Build & Tests |
| Test-locked contracts | green and unmodified | verify-report §Test-locked contracts |
| OTS-10 protected surfaces | untouched | verify-report §OTS-10 diff audit |
| TDD compliance | 6/6 checks present | verify-report §TDD Compliance |
| Core `openspec/` entries | none | this report §Core Containment Check |

## Files Changed by This Change

Implementation and test files (the six-file evidence digest listed in `design.md §4` / verify-report), all under `plugins/catalogo_core/`:

| File | Action | What changed |
|------|--------|--------------|
| `controller/tarif_opcional_edit.php` | Modified | Added `public $tarifa_seleccionada;`, `resolver_tarifa_seleccionada()` and `guard_mutating_action()`; resolver called from `private_core()`; `add_familia`/`remove_familia`/`add_articulo`/`remove_articulo` moved from `$_GET` dispatch to guarded `$_POST`. Bulk `guardar_precios_tarifas()`, `guardar_precio_tarifa()`, `load_precios_tarifas()`, accessors and the master seam are byte-identical. |
| `View/tarif_opcional_edit.html.twig` | Modified | Tarifa `<select name="codtarifa">` with the htmx-4 body-swap attribute set; scoped editable panel over `fsc.tarifa_seleccionada` plus read-only `fsc.precios_tarifas` overview; `Macro/Htmx` + `Macro/Alpine` boot; bootbox/onclick/jQuery-UI autocomplete replaced by Alpine CSP components registered from a nonce'd script behind `alpine:init`, with guarded idempotent `htmx:after:swap` re-init. |
| `View/tarif_opcional_precios.html.twig` | Modified | Tarifa selector converted from `onchange="this.form.submit()"` to the htmx-4 body-swap set; save form, `guardar_precio_tarifa`, `{{ csrf_field() }}` and `tarif.toggle_button_group(` byte-identical. |
| `View/tarif_opcionales.html.twig` | Modified | `query` / `b_codfamilia` / `b_codtarifa` / `b_solo_activos` filters converted to `hx-get` + body swap + URL push, with a `type="button"` fallback; POST row toggles, hidden names, `offset`, CSRF and the new-opcional modal byte-identical. |
| `tests/TarifOpcionalEditTarifaSelectorTest.php` | Created | DB-free behaviour tests (Reflection on an anonymous `extends \tarif_opcional_edit` subclass) for selector resolution and fallbacks, plus source-contract tests for the selector, scoped panel and all-tarifas overview. |
| `tests/TarifOpcionalesHtmxContractTest.php` | Created (extended in batch 2) | htmx 4 / Alpine CSP / colon-event / POST-only / idempotent re-init contract tests, plus the precios and list filter migration contracts. |

OpenSpec artifacts reconciled/archived:

- `plugins/catalogo_core/openspec/specs/opcionales-tarifa-selector/spec.md` — **canonical spec source of truth (created)**.
- `plugins/catalogo_core/openspec/changes/archive/2026-09-12-opcionales-tarifa-selector-htmx4/` — archived change (proposal, exploration, design, delta spec, tasks, apply-progress, verify-report, this report).

Out-of-scope surfaces were not touched: `model/tarif_tarifa_opcional.php`, `model/tarif_opcional_precio.php`, `controller/tarif_configurador_opcionales.php`, `View/tarif_configurador_*`, `tpvmod`, `ventas_opcionales`, and the core `openspec/`.

## Post-Verify Notes / Open Warnings (accepted, non-blocking)

1. **OPEN — PHPStan does not analyze the plugin code.** `ddev exec composer phpstan` exits 0 but the root `phpstan.neon` declares `paths: [src, tests]` only; there is no `plugins/catalogo_core/phpstan.neon`, no plugin Composer script, and no plugin path in the root config. Therefore the green PHPStan run is **vacuous for the plugin** and Phase 6.2's stated intent is not actually met. Recorded as task 6.2 non-applicable as configured. **Suggested remediation: wire `plugins/*/` (or a plugin-local PHPStan config + Composer script) into PHPStan before the next plugin SDD** so "static analysis green" yields real signal.
2. **OPEN — Authenticated browser/CSP smoke not executed.** The delta is a UI/htmx/Alpine capability; compliance is proven by DB-free behaviour tests plus source contracts. A live authenticated render exercising (a) selector body swap, (b) Alpine confirm/search under the CSP build, (c) `hx-push-url`, and (d) the browser console (no CSP/`unsafe-eval` errors, no duplicate handlers after swap) was not run — no authenticated session or seeded opcional/tarifas fixture was available in the verify pass. Task 6.4 stays open and non-blocking, matching the sibling `opcionales-por-tarifa` deferral. The `hx-trigger="keydown[key === 'Enter']"` CSP caveat remains folded into this item.

**INFO** — Apply batch-2 deviations (`apply-progress.md §13`) were not re-approved by design: preservation assertion is a regression latch, not a RED driver; `type="submit"`→`type="button"`; added `id="input_query"`; cross-filter Twig `set` fragments; `allowScriptTags:false` on the secondary views. None breaks a spec scenario.

## Task Reconciliation (archive-time)

Implementation tasks Phases 1–5 are all `[x]` (19/19). Phase 6 check disposition at archive: **6.1 `[x]`** (475/1517, exit 0), **6.2 `[ ]`** (documented non-applicable — see OPEN WARNING-1), **6.3 `[x]`** (OTS-10 diff audit clean), **6.4 `[ ]`** (open non-blocking — see OPEN WARNING-2), **6.5 `[ ]`** (procedural deploy/rollback hygiene: clear the Twig cache after deploy/`git revert`; delivery is human-owned). No phase-6 checkbox was ticked dishonestly.

## Dependency / Composer Check

**Not applicable.** This change added no Composer dependency. `git status --porcelain` in the plugin repo shows no `composer.*` or `vendor/` path touched by this change; no `vendor/` commit step is required.

## Delivery Status

**Human-owned and NOT performed.** No `git commit`, branch, push or PR was created by apply, verify or this archive phase. Delivery of the change (and of this archive/spec reconciliation) is left to the maintainer.

## Spec Source of Truth

- Canonical spec created: `plugins/catalogo_core/openspec/specs/opcionales-tarifa-selector/spec.md` (`# opcionales-tarifa-selector Specification`, Purpose, OTS-01..OTS-10 requirements + 16 scenarios), converted from the archived delta `specs/opcionales-tarifa-selector/spec.md` (delta framing — "Delta for …" / "## ADDED Requirements" table — removed; requirement and scenario text preserved).
- The delta is ADDED-only for `opcionales-tarifa-selector`; no `ADDED`/`MODIFIED`/`REMOVED` requirements were declared for the sibling `opcionales-tarifa-management` capability, whose requirement set remains owned by the still-unarchived `plugins/catalogo_core/openspec/changes/opcionales-por-tarifa/`. That capability and its pending reconciliation were deliberately **not** touched.

## Core Containment Check

- Core `openspec/changes/opcionales-tarifa-selector-htmx4/` — **does not exist**.
- Core `openspec/specs/opcionales-tarifa-selector/` — **does not exist**.
- The plugin is the sole SDD owner, per `AGENTS.md → "OpenSpec per Plugin (SDD ownership)"` and `plugins/catalogo_core/openspec/config.yaml`.

## Archive Contents

- `proposal.md` — intent, scope, approach.
- `exploration.md` — prior-art and constraint exploration.
- `design.md` — decisions, htmx/Alpine contracts, open questions.
- `specs/opcionales-tarifa-selector/spec.md` — delta (ADDED-only, 10 requirements / 16 scenarios).
- `tasks.md` — 19/19 implementation tasks `[x]`; Phase 6 checkboxes annotated (6.2/6.4 open, 6.5 procedural).
- `apply-progress.md` — batch 1 + batch 2 TDD evidence and deviations.
- `verify-report.md` — independent verification, `pass_with_warnings`, 10/10 / 16/16.
- `archive-report.md` — this file.
