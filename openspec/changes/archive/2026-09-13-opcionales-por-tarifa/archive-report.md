```yaml
schema: gentle-ai.archive-result/v1
change: opcionales-por-tarifa
archived_to: plugins/catalogo_core/openspec/changes/archive/2026-09-13-opcionales-por-tarifa/
archive_date: "2026-09-13"
verdict_at_verify: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 6/6
scenarios: 14/14
implementation_tasks: 20/20
composer_dependency_added: false
delivery: human-owned (not committed, not pushed by archive)
final_suites:
  catalogo_core: "585 tests / 2247 assertions (Warnings 26, Skipped 1) OK"
  tarifario: "197 tests / 726 assertions (Skipped 3) OK"
```

# Archive Report — `opcionales-por-tarifa`

**Change**: `opcionales-por-tarifa` (plugin-local SDD)
**SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`) — the core `openspec/` is NOT a tracker for this change.
**Hybrid touch**: `plugins/tarifario` (two controllers + two tests, per the confirmed product decisions)
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-09-13-opcionales-por-tarifa/`
**Delivery**: commit / PR / push are human-owned and were **NOT** performed by this archive phase.

## Final-State Authority

`apply-progress.md` and `verify-report.md` are intermediate snapshots. The final state is:

- All **6/6 requirements and 14/14 scenarios** are backed by passing coverage; the last independent re-verification (residual pass) closed WARNING-1, WARNING-3 and WARNING-5 and accepted WARNING-2; WARNING-4 (authenticated HTTP render) remains open and non-blocking.
- The change shares the `opcionales-tarifa-management` capability with the sibling `absorber-opcionales-tarifa-en-catalogo-core`; both were archived in the same batch, so the "archive the sibling first" dependency is satisfied.
- Final suite counts (re-run at archive) supersede the verify snapshot (`446/1350` and `198/759`): `catalogo_core` **585/2247**, `tarifario` **197/726** — the deltas come from the later article slices and the opcionales configurator revert.

## Delivered Behavior

The per-`(tarifa, opcional)` master state (`tarif_tarifa_opcional`), mirroring `tarif_tarifa_familia`:

- **Master table**: PK `(codtarifa, id_opcional)`, `en_catalogo`/`en_tarifa`/`activa`/`orden`, CASCADE FKs to `tarif_tarifas`/`catalogo_opcionales`.
- **Bootstrap**: FK-safe `Init::ensureOpcionalesTarifaTables()` step after the five moved opcional tables; idempotent and class-safe (live scratch-DB smoke `SMOKE_RESULT=PASS`).
- **Precedence**: master `activa` is authoritative (price rows never activate); master `en_catalogo` wins for catalog/export; master `orden` is the default with family/article scoped override (now consumed by the per-tarifa listing with `COALESCE(mto.orden, 999999)`).
- **Lifecycle**: install seed from `catalogo_opcionales LEFT JOIN tarif_opcional_ext`, lazy inheritance without persisting, transactional `copy_from_tarifa()` (with self-copy no-op) wired from `heredar_estructura()`.
- **Consumption**: list "Estado" per tarifa, edit/precios matrices read and write master flags, `tarif_catalogo_view` export follows per-tarifa master flags.
- **Boundaries**: `catalogo_opcionales` XML/API untouched; `tpvmod` untouched; additive/lazy with no mandatory backfill.

## Archive-time Spec Sync

- **`specs/opcionales-tarifa-management/spec.md`** — the change's 6 master-state requirements were merged into the canonical capability (created from the sibling `absorber-opcionales` delta in the same batch):
  - `Per-tarifa opcional master table`, `Opcional master table bootstrap`, `Master state precedence`, `Master lifecycle: seed, lazy inherit, copy`, `Master consumption in UI and export`, `Unchanged boundaries and non-destructive migration` (14 scenarios).
  - The delta scenarios carried no explicit `Test:` lines; the canonical spec maps each to its named covering test from `verify-report.md` (`TarifTarifaOpcionalTest`, `InitTarifTarifaOpcionalBootstrapTest`, `TarifTarifaOpcionalPrecedenceTest`, `TarifTarifaOpcionalLifecycleTest`, `TarifOpcionalesControllerMasterStateTest`, `TarifCatalogoOpcionalMasterExportTest`, `TarifTarifasHeredarOpcionalMasterTest`).
  - The **configurator** requirement inherited from the sibling change was corrected to **tarifario-owned** in the same merge (ownership-boundary correction).
  - Canonical result: **16 requirements / 35 scenarios**.

## Task Completion Gate

`tasks.md` census: **28 checked / 0 unchecked** — Phases 1–5 (20/20 implementation tasks) and Phase 6 (6.1–6.4 verification) are all complete. No reconciliation was required.

## CodeRabbit Review Outcome

CodeRabbit findings on this change were remediated across the post-verify rounds recorded in `apply-progress.md`: **round 1 (findings 1–6)**, **round 2 (findings 7–10)** and the **residual pass (items A–G)**. All still-valid code findings were fixed (atomic single-field updates, transactional `copy_from_tarifa`, `run_in_transaction()`/`parse_price_input()` hardening, CSRF/redirect/view fixes, master-`orden` production consumer, per-lista price-copy columns). Documentation-only findings are reconciled by this archive-report. This change contributes to the changeset-wide CodeRabbit tally (**41 findings `catalogo_core` / 5 `tarifario`**), all still-valid code findings fixed.

## Final Suite Counts (final-state, re-run at archive)

| Suite | Command | Final |
|---|---|---|
| `plugins/catalogo_core` | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **585 tests / 2247 assertions OK** (Warnings 26, Skipped 1) |
| `plugins/tarifario` | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **197 tests / 726 assertions OK** (Skipped 3) |

PHPStan was clean at verify time (`188/188`, `[OK] No errors`); the root PHPStan config does not cover the plugin tree (same gap as the sibling changes).

## Open Items / Warnings (carried forward)

1. **Authenticated HTTP render not executed** (WARNING-4) — the list/edit/precios matrices and export are covered by DB-free behavioral seam tests + source contracts; a live authenticated render remains open and non-blocking.
2. **Physical boolean defaults are `0`, not XML `TRUE`** (WARNING-2) — accepted residual; pre-existing framework `TypeNormalizer` behavior, identical to the mirrored `tarif_tarifa_familia`, runtime unaffected; no core file modified.
3. **Full-row `save()` UPDATE branch** remains an unreachable defensive fallback (documented; no new callers).
4. **Delivery is human-owned** — the verify report describes the work as uncommitted/staged in two standalone repos; the human lands the corrected tree.

## Core `openspec/` Cleanliness

- No `openspec/changes/opcionales-por-tarifa/` in the core `openspec/`.
- `find openspec -iname '*opcionales-por-tarifa*'` → no matches.
- `git status --porcelain -- openspec/` at the repo root → empty.
- The plugin-local `plugins/catalogo_core/openspec/` is the only SDD location for this change.

## Archive Verification (mechanical)

```text
$ diff -r "<snapshot>/source" "openspec/changes/archive/2026-09-13-opcionales-por-tarifa"
DIFF-EMPTY-ARCHIVE-BYTE-IDENTICAL
SOURCE-ABSENT-OK
```

Empty `diff -r` = byte-identical archive; the active change dir is gone. `archive-report.md` is additive and excluded from the comparison.

## Closing Summary

The per-`(tarifa, opcional)` master state is delivered, bootstrap-safe, precedence-tested and consumed by the list, matrices and export, with the additive/lazy migration boundary preserved. All 6 requirements / 14 scenarios are backed by passing coverage. The change is archived together with its sibling `absorber-opcionales-tarifa-en-catalogo-core`, and the capability's canonical spec reflects both. Commit / PR / push remain human-owned.
