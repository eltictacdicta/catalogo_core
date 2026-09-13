```yaml
schema: gentle-ai.archive-result/v1
change: absorber-articulos-tarifa-en-catalogo-core
archived_to: plugins/catalogo_core/openspec/changes/archive/2026-09-13-absorber-articulos-tarifa-en-catalogo-core/
archive_date: "2026-09-13"
delivered_units: [WU-1, WU-2, WU-3]
reverted_units: [WU-4, WU-5]
cancelled_units: [WU-6, WU-7, WU-8]
verdict_at_verify: pass_with_warnings
blockers: 0
critical_findings: 0
requirements_delivered: 23/23
scenarios_delivered: 46/46
implementation_tasks: WU-1/2/3 complete; WU-4/5 landed then reverted; WU-6/7/8 cancelled
composer_dependency_added: false
delivery: human-owned (not committed, not pushed)
final_suites:
  catalogo_core: "585 tests / 2247 assertions (Warnings 26, Skipped 1) OK"
  tarifario: "197 tests / 726 assertions (Skipped 3) OK"
```

# Archive Report — `absorber-articulos-tarifa-en-catalogo-core`

**Change**: `absorber-articulos-tarifa-en-catalogo-core` (plugin-local SDD)
**SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`) — the core `openspec/` is NOT a tracker for this change.
**Secondary plugin**: `plugins/tarifario` (retirement / repointing / hook-ownership flip)
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-09-13-absorber-articulos-tarifa-en-catalogo-core/`
**Delivery**: commit / PR / push are human-owned and were **NOT** performed by this archive phase.

## Final-State Authority

This archive report is the terminal record of the change. `apply-progress.md` and `verify-report.md` are intermediate snapshots valid at their time. The absorption program was delivered in slices and then **partially corrected by an ownership-boundary decision**, so the orchestrator's explicit final-state facts outrank the snapshots:

| Unit | Final state | Evidence |
|---|---|---|
| **WU-1** — article Tarifas tab + `tarif_articulo_precio` | **DELIVERED** and independently verified | `verify-report.md` WU-1 section (`pass_with_warnings`, 9/9 req, 19/19 scen) |
| **WU-2** — canonical `ventas_articulo` detail absorption | **DELIVERED** and independently verified | `verify-report.md` WU-2 section (`pass_with_warnings`, 8/8 req, 15/15 scen) |
| **WU-3** — canonical `ventas_articulos` list absorption | **DELIVERED** and independently verified | `verify-report.md` WU-3 section (`pass_with_warnings`, 8/8 req, 12/12 scen) |
| **WU-4** — `tarif_articulo` / `tarif_articulos_ext` ownership move | **REVERTED to tarifario** | `apply-progress.md` "Revert WU-4 + WU-5 (boundary correction)" |
| **WU-5** — catalog manager ownership | **REVERTED to tarifario** | same revert section |
| **WU-6/WU-7/WU-8** — revision notes, article price history, rich Excel wizard | **CANCELLED** (tarifario-exclusive) | `tasks.md` phases 33–38 (never delivered) |
| opcionales **configurator** | **REVERTED to tarifario** | `apply-progress.md` "Revert configurador (boundary correction)" |

All three delivered slices carry `verdict: pass_with_warnings` with **0 CRITICAL, 0 blockers**; no CRITICAL issue blocked archive.

## Boundary Correction (ownership)

The user corrected the ownership boundary after the WU-4/WU-5 slices and the opcionales configurator had landed:

- The article **extension fields** (`tarif_articulo`, `tarif_articulos_ext` → Ref SAP / Ref Catálogo / Configurador) and the **catalog manager** (`tarif_catalogo*`) are **tarifario-exclusive** and MUST NOT live in `catalogo_core`.
- The hierarchical opcionales **configurator** (`tarif_configurador_opcionales`) is likewise **tarifario-exclusive**.

WU-4 + WU-5 were surgically reverted (`apply-progress.md`), the `Init.php` bootstrap/retirement additions and every wiring line were dropped, and the opcionales configurator fork was relocated back to tarifario. WU-1/WU-2/WU-3 and the sibling opcionales slice were left intact. Consequently the `catalogo-gestion` and `articulo-modelos-ext` delta specs are **superseded/reverted and were NOT merged** into the canonical specs, and `articulo-notas-historial` (WU-6) was **NOT merged** because WU-6 was cancelled.

## Archive-time Spec Sync

### Canonical specs created (delivered slices only)

| Capability | Action | Content | Verification |
|---|---|---|---|
| `specs/articulo-tarifa-tab-management/spec.md` | **Created** (WU-1) | 7 requirements / 15 scenarios | Requirement/scenario block byte-identical to the delta (`REQUIREMENTS-SECTION-IDENTICAL`), then one final-state reconciliation: ATT-01's parenthetical consumer paths were repointed to `plugins/catalogo_core/model/tarif_tarifa.php` + `plugins/tarifario/controller/tarif_configurador_opcionales.php` (the configurator reverted to tarifario). Normative text otherwise unchanged. |
| `specs/articulo-detalle-canonico/spec.md` | **Created** (WU-2) | 8 requirements / 15 scenarios | `REQUIREMENTS-SECTION-IDENTICAL` |
| `specs/articulo-lista-canonica/spec.md` | **Created** (WU-3) | 8 requirements / 12 scenarios | `REQUIREMENTS-SECTION-IDENTICAL` |

For each copy the delta was first copied mechanically (`cp` + `diff -r` → empty), then the framing was converted (`# Delta for …` + `## ADDED Requirements` → `# … Specification` + `## Purpose` + `## Requirements`). No normative requirement or scenario text was altered; the only content edit is the ATT-01 consumer-path parenthetical reconciled to final state (configurator reverted to tarifario).

### Canonical spec merged

- **`specs/catalogo-render-hooks/spec.md`** — the article delta's additive ownership note was merged on top of the capability canonicalised by the sibling `absorber-opcionales-tarifa-en-catalogo-core` change:
  - Added requirement **"Article hook pair registration owned by catalogo_core"** (2 scenarios).
  - Modified requirement **"Hooks render when a listener is registered"** to name `catalogo_core` as the article-pair registering plugin (and added the "Article Tarifas tab is injected when its owner registers" scenario).
  - Result: **7 requirements / 15 scenarios**. The four frozen marker names/positions are unchanged.

### Delta specs deliberately NOT merged (supersession record)

| Delta spec | Reason | Disposition |
|---|---|---|
| `catalogo-gestion` (WU-5) | WU-5 reverted to tarifario | **SUPERSEDED / REVERTED** — not merged; the catalog manager stays tarifario-owned |
| `articulo-modelos-ext` (WU-4) | WU-4 reverted to tarifario | **SUPERSEDED / REVERTED** — not merged; `tarif_articulo`/`tarif_articulos_ext` stay tarifario-owned |
| `articulo-notas-historial` (WU-6/7/8) | WU-6/7/8 cancelled | **CANCELLED** — not merged; revision notes, article price history and the rich Excel wizard stay tarifario-exclusive |

## Task Completion Gate / Checkbox Reconciliation

`tasks.md` census: **193 checked / 47 unchecked**. The 47 unchecked boxes are **not** undone implementation work:

- Phases 7 / 13 / 19 (WU-1/2/3 atomic landing) — annotated `deferred - human-owned landing`; delivery is human-owned.
- Phases 25 / 32 (WU-4/WU-5 landing) — **reverted**; the work no longer exists.
- Phases 33–38 (WU-6) — **cancelled**; never implemented.

A "final-state note" was added at the top of the archived `tasks.md` recording this mapping. No `sdd-apply` re-run was required; the unchecked boxes are truthful for reverted/cancelled/human-owned work, and the delivered slices' implementation boxes are all `[x]`.

## CodeRabbit Review Outcome

The changeset was reviewed by CodeRabbit with **41 findings on `catalogo_core` and 5 on `tarifario`**. Every **still-valid code finding was fixed** (notably the opcionales review commits `9a30bb6 fix(opcionales): address code review findings` and `313fad0 fix(catalogo): drop vestigial js_common call`, plus the `opcionales-por-tarifa` remediation rounds 1/2/residual). The **documentation-only findings are reconciled by this archive-report**: the reverted WU-4/WU-5 capabilities are explicitly marked superseded, the configurator boundary is recorded in the canonical `opcionales-tarifa-management` spec, and no stale "delivered" claim survives for reverted/cancelled work.

## Final Suite Counts (final-state, re-run at archive)

| Suite | Command | Final |
|---|---|---|
| `plugins/catalogo_core` | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **585 tests / 2247 assertions OK** (Warnings 26, Skipped 1) |
| `plugins/tarifario` | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **197 tests / 726 assertions OK** (Skipped 3) |

> The orchestrator's launch note quoted catalogo_core as `585/2245`; the archive re-run recorded the exact observed value **585/2247** (the assertion count moved by +2 between the last apply run and archive). Tarifario matches `197/726`.

Root regression (`ddev exec php vendor/bin/phpunit --testsuite Plugins`) was last recorded at WU-3 as 1502 tests / 10 failures — exactly the known pre-existing/unrelated set (7× OidcProvider DB/schema, 2× `TarifTarifaOpcionalPrecedenceTest` without process isolation, 1× `LegacySupportTest` version drift). It was **not** re-run in this archive cycle; the count is carried forward as a recorded baseline, not re-asserted as final.

## Delivered Behavior Summary (WU-1/2/3)

- `catalogo_core` owns `tarif_articulo_precio` + its XML and serves the `page=tarif_tab_precios` endpoint on `fbase_controller` (CSRF → neutral `ArticlePermissionFilterEvent` → model), with htmx 4 + Alpine CSP and per-row tariff currency.
- The canonical `ventas_articulo` detail absorbs the `tarif_articulo_edit` behavior (reference change, familia, per-tarifa sync, etiquetas, images, POST-only delete) and is migrated to htmx 4 + Alpine; `tarif_articulo_edit` and `tarif_articulo_precios` are retired with links repointed and `fs_page` rows retired idempotently.
- The canonical `ventas_articulos` list absorbs the `tarif_articulos` filter aliases (`query`, `b_codfamilia`, `b_codtarifa`, `b_solo_activos`), per-tarifa columns (one-query batch reader), quick-create prices and the neutral Excel/JSON action seam; `tarif_articulos` is retired with links repointed.
- Post-verify correction: the WU-3 filtered-export alias gap (verify WARNING 1) was fixed and covered by `test_filtered_export_applies_the_resolved_alias_filters`.
- RBAC boundary preserved: `ArticlePermissionListener` + `tarif_grupo_articulo` + role tables stay in tarifario; catalogo_core stays neutral (default-allow with zero listeners).

## Open Items / Warnings (carried forward, non-blocking)

1. **Human-owned landing** — WU-1/WU-2/WU-3 commits were not performed; the atomic back-to-back landing order is documented in the archived `tasks.md` (Phases 7/13/19).
2. **Authenticated DEV-DB / browser smoke not executed** — the delivered slices are proven by DB-free behavior tests + source contracts + grep audits; the authenticated end-to-end smoke remains open (per `verify-report.md`).
3. **PHPStan gap** — no PHPStan config covers `catalogo_core`; PHP lint was substituted.
4. **Pre-existing root `Plugins` failures** — 10 known/unrelated; pin a baseline so future slices assert "zero new failures" mechanically.
5. **Reverted/cancelled capabilities** — WU-4/WU-5 and WU-6/7/8; tracked above and intended to be re-opened as tarifario-owned work if ever needed.

## Core `openspec/` Cleanliness

- No `openspec/changes/absorber-articulos-tarifa-en-catalogo-core/` in the core `openspec/`.
- `find openspec -iname '*absorber-articulos*'` → no matches.
- `git status --porcelain -- openspec/` at the repo root → empty.
- The plugin-local `plugins/catalogo_core/openspec/` is the only SDD location for this change.

## Archive Verification (mechanical)

The change folder was moved with a shell transaction (`git mv`, falling back to `mv` because the change dir was untracked), against a pre-move recursive snapshot. The mandatory readback:

```text
$ diff -r "<snapshot>/source" "openspec/changes/archive/2026-09-13-absorber-articulos-tarifa-en-catalogo-core"
DIFF-EMPTY-ARCHIVE-BYTE-IDENTICAL
SOURCE-ABSENT-OK
```

Empty `diff -r` = byte-identical archive; the active change dir is gone. `archive-report.md` is additive and excluded from the comparison.

## Closing Summary

`catalogo_core` now owns the article Tarifas tab and per-tarifa price/state (`articulo-tarifa-tab-management`), the canonical `ventas_articulo` detail (`articulo-detalle-canonico`) and the canonical `ventas_articulos` list (`articulo-lista-canonica`), plus the article hook pair (`catalogo-render-hooks`). The WU-4/WU-5 slices and the opcionales configurator were **reverted to tarifario** by the ownership-boundary correction, and WU-6/7/8 were cancelled; their delta specs were deliberately not merged and are recorded as superseded/cancelled above. The change is archived; commit / PR / push remain human-owned.
