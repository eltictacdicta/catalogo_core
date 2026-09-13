```yaml
schema: gentle-ai.archive-result/v1
change: absorber-opcionales-tarifa-en-catalogo-core
archived_to: plugins/catalogo_core/openspec/changes/archive/2026-09-13-absorber-opcionales-tarifa-en-catalogo-core/
archive_date: "2026-09-13"
verdict_at_verify: pass_with_warnings   # SNAPSHOT ONLY — superseded, see "Verdict reconciliation"
archive_verdict: intentional-with-warnings (partial: reverted + deferred)
blockers: 0
critical_findings: 0
requirements_delivered_at_close: "catalogo-render-hooks 6/6; opcionales-tarifa-management 9/10"
scenarios_delivered_at_close: "catalogo-render-hooks 12/12; opcionales-tarifa-management 19/21"
reverted: [Hierarchical configurator -> tarifario]
deferred_unverified: [tpvmod opcional consumption preserved]
composer_dependency_added: false
delivery: human-owned (not committed, not pushed by archive)
final_suites:
  catalogo_core: "585 tests / 2247 assertions (Warnings 26, Skipped 1) OK"
  tarifario: "197 tests / 726 assertions (Skipped 3) OK"
```

# Archive Report — `absorber-opcionales-tarifa-en-catalogo-core`

**Change**: `absorber-opcionales-tarifa-en-catalogo-core` (plugin-local SDD)
**SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`) — the core `openspec/` is NOT a tracker for this change.
**Secondary plugin**: `plugins/tarifario` (removals / repoints / hybrid consumers)
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-09-13-absorber-opcionales-tarifa-en-catalogo-core/`
**Delivery**: commit / PR / push are human-owned and were **NOT** performed by this archive phase.

## Verdict reconciliation (CodeRabbit finding)

The persisted `verify-report.md` envelope carries `verdict: pass_with_warnings` with
`requirements: 15/16` / `scenarios: 31/33`. CodeRabbit correctly flagged that a
**passing verdict with incomplete counts is not truthful**. This archive reconciles
the label to the final state:

- **The change is archived as `intentional-with-warnings` (partial), NOT as a clean pass.**
- Two independent causes reduce coverage, and both are recorded, not hidden:
  1. **Reverted scope (ownership-boundary correction).** The hierarchical opcionales
     **configurator** fork was reverted to `tarifario`; the requirement
     `Hierarchical configurator` (2 scenarios) is **no longer catalogo_core-delivered**.
     The canonical `opcionales-tarifa-management` spec was rewritten so the configurator
     is explicitly **tarifario-owned** (see "Archive-time Spec Sync").
  2. **Deferred scope (user decision).** The `tpvmod opcional consumption preserved`
     requirement (2 scenarios) was **explicitly deferred / out of scope**; `plugins/tpvmod`
     was not modified and its suite was not run. It remains **UNVERIFIED**.

**Delivered and verified at close** (against the merged canonical specs):
catalogo-render-hooks **6/6 requirements, 12/12 scenarios**;
opcionales-tarifa-management **9/10 requirements, 19/21 scenarios**.
**Reverted:** 1 requirement / 2 scenarios (configurator). **Unverified:** 1 requirement / 2 scenarios (tpvmod).

## Final-State Authority

`apply-progress.md` and `verify-report.md` are intermediate snapshots. The final state recorded here is:

| Area | Final state | Evidence |
|---|---|---|
| Opcional domain models + 5 XMLs + bootstrap | **DELIVERED** (`catalogo_core`) | `verify-report.md` + scratch-DB fresh-install smoke (`SMOKE_RESULT=PASS`) |
| Controllers / views reclass (`tarif_opcionales`, `tarif_opcional_edit`, `tarif_opcional_precios`) | **DELIVERED** (`catalogo_core`) | `TarifOpcionalesControllerContractTest` |
| Unified per-tarifa prices on `codlista` | **DELIVERED** | `OpcionalPriceUnificationTest`, `TarifOpcionalPreciosControllerTest` |
| Tags / activation / resolver | **DELIVERED** | `TarifTarifaOpcional{Etiqeta,Familia,Resolver,ArticuloOpcional}Test` |
| Dead-table refs removed + FK repoints | **DELIVERED** | `DeadOpcionalTableReferenceTest` (zero hits) |
| 4 frozen `render_hook` markers | **DELIVERED** | `Integration/CatalogoCoreHookMarkersTest` (11/11) |
| Opcional price history | **DELIVERED** | `TarifOpcionalPrecioHistorialTest`, `TarifHistorialPreciosControllerTest` |
| `Hierarchical configurator` | **REVERTED to tarifario** | `apply-progress.md` "Revert configurador (boundary correction)" |
| `tpvmod opcional consumption preserved` | **UNVERIFIED (deferred)** | `tasks.md` 6.5; `verify-report.md` WARNING-1 |

The change was committed as a 5-slice chain (`catalogo_core` `8a8b063…e42b54c`; `tarifario` `e469f06…96a52e3`). The configurator revert is a later working-tree correction; the **human re-lands** the corrected tree (delivery is human-owned).

## Boundary Correction

The opcionales **domain** (list / detail / per-tarifa prices / tags / activation / history / tab) is **catalogo_core-owned**, but the hierarchical **configurator** (`tarif_configurador_opcionales` + tree views/partials/JS) is **tarifario-exclusive** and was reverted to `plugins/tarifario/`. The canonical `opcionales-tarifa-management` spec states this boundary explicitly.

## Archive-time Spec Sync

### Canonical spec created / merged

- **`specs/opcionales-tarifa-management/spec.md`** — canonicalised from this change's delta, then corrected and extended:
  - **Corrected** `Opcional pages served by catalogo_core`: three moved slugs (`tarif_opcionales`, `tarif_opcional_edit`, `tarif_opcional_precios`); `tarif_configurador_opcionales` explicitly **excluded** (tarifario-owned).
  - **Reverted** `Hierarchical configurator` → **"Hierarchical configurator remains tarifario-owned"** (tarifario-exclusive; no catalogo_core copy).
  - The 6 master-state requirements from the sibling `opcionales-por-tarifa` change were merged in the same archive batch (see that change's archive-report).
  - Result: **16 requirements / 35 scenarios** shared with `opcionales-por-tarifa`.
- **`specs/catalogo-render-hooks/spec.md`** — created from this change's delta (6 requirements / 12 scenarios); the article-hook ownership delta from `absorber-articulos-tarifa-en-catalogo-core` was merged on top in the same batch (final: 7 requirements / 15 scenarios).

## Task Completion Gate / Checkbox Reconciliation

`tasks.md` census before reconciliation: **45 checked / 11 unchecked**. Archive-time exceptional mechanical reconciliation (proof in `verify-report.md` / `apply-progress.md`), recorded here per the skill contract:

| Box | Final | Reason |
|---|---|---|
| 7.1–7.4 (landing) | `[x]` | Landed as the committed 5-slice chain; the configurator revert is a later human re-land |
| 8.1, 8.3, 8.4, 8.5, 8.6 | `[x]` | Executed by the verify actor (suites, PHPStan, gate, scratch-DB smoke, core-openspec check) |
| 6.5 (tpvmod) | `[ ]` | Explicitly deferred / out of scope by the user — **UNVERIFIED** |
| 8.2 (root `--testsuite Plugins`) | `[ ]` | Not re-run in this cycle — open item |

A "checkbox reconciliation" note was added at the top of the archived `tasks.md`. The archived audit trail contains no stale unchecked box for completed work.

## CodeRabbit Review Outcome

CodeRabbit raised **41 findings on `catalogo_core` and 5 on `tarifario`** across this changeset. All **still-valid code findings were fixed** (opcionales review commits `9a30bb6` / `313fad0`; `opcionales-por-tarifa` rounds 1/2/residual). The **documentation-only findings are reconciled by this archive-report**, including the verdict-label finding: the `pass_with_warnings` snapshot for 31/33 coverage is superseded by the truthful `intentional-with-warnings (partial)` closing verdict above.

## Final Suite Counts (final-state, re-run at archive)

| Suite | Command | Final |
|---|---|---|
| `plugins/catalogo_core` | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **585 tests / 2247 assertions OK** (Warnings 26, Skipped 1) |
| `plugins/tarifario` | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **197 tests / 726 assertions OK** (Skipped 3) |

These supersede the counts inside `verify-report.md` (354/1018 and 187/702 at verify time); the later article slices and the configurator revert changed the totals. Root `--testsuite Plugins` was not re-run in this cycle.

## Dev-DB Smoke (recorded evidence)

The scratch-DB fresh-install smoke proved the standalone bootstrap creates the 5 moved tables FK-safe and is idempotent (`SMOKE_RESULT=PASS`, second run byte-identical). The full authenticated HTTP render of the four slugs (now three catalogo_core + one tarifario configurator) was **not** executed and remains an open, non-blocking item.

## Open Items / Warnings (carried forward)

1. **tpvmod requirement UNVERIFIED** — run `plugins/tpvmod/tests/TpvmodOpcionalesTest.php` (read-only) or consciously accept the deferral (recorded).
2. **Configurator reverted** — human re-lands the corrected tree; the catalogo_core fork must not reappear.
3. **Authenticated HTTP smoke not executed** — three moved slugs proven by contract/class-load/scratch-DB only.
4. **Existing DB keeps the pre-change FK to the legacy dead table** — the two repointed FK XMLs only affect freshly created tables; non-breaking while `tarif_opcionales` remains.
5. **Pre-existing root `Plugins` failures** (10 known/unrelated) — not re-run in this cycle.
6. **PHPStan clean** at verify time (`188/188`); note the root PHPStan config does not cover the plugin tree (same gap as the sibling changes).

## Core `openspec/` Cleanliness

- No `openspec/changes/absorber-opcionales-tarifa-en-catalogo-core/` in the core `openspec/`.
- `find openspec -iname '*absorber-opcionales*'` → no matches.
- `git status --porcelain -- openspec/` at the repo root → empty.
- The plugin-local `plugins/catalogo_core/openspec/` is the only SDD location for this change.

## Archive Verification (mechanical)

```text
$ diff -r "<snapshot>/source" "openspec/changes/archive/2026-09-13-absorber-opcionales-tarifa-en-catalogo-core"
DIFF-EMPTY-ARCHIVE-BYTE-IDENTICAL
SOURCE-ABSENT-OK
```

Empty `diff -r` = byte-identical archive; the active change dir is gone. `archive-report.md` is additive and excluded from the comparison.

## Closing Summary

`catalogo_core` now owns the opcional domain (models, bootstrap, list/detail/precios pages, unified `codlista` prices, tags/activation/resolver, price history) and the four frozen `render_hook` markers, with the dead-table references removed and FKs repointed. The hierarchical **configurator** was **reverted to tarifario** and the `tpvmod` requirement remains **unverified**; both are recorded as intentional partial-archive items rather than a clean pass. The change is archived; commit / PR / push remain human-owned.
