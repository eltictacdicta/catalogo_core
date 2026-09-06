# Archive Report — absorber-familias-tarifa-en-catalogo-core

**Change**: absorber-familias-tarifa-en-catalogo-core (plugin SDD, `plugins/catalogo_core/openspec/`)
**Archived**: 2026-09-06
**Disposition**: intentional-with-waiver — maintainer explicitly ordered direct archive ("archiva directamente") with the pending-user HTTP smokes recorded as MAINTAINER-ACCEPTED RESIDUALS.
**verify-report final machine verdict**: FAIL — verification-state encoding only (NOT an implementation defect; zero CRITICAL, zero blockers).

## Executive Summary (Closing)

The familias-tarifa package (4 models, controller `tarif_familias`, view + JS, moved Macro, 5 test files, standalone bootstrap) was absorbed from `plugins/tarifario` into `plugins/catalogo_core` as a coordinated trio of commits, followed by Amendment 1 (menu repoint + plain list retirement) in a fourth catalogo_core commit. Every automatable gate is green: anchored grep gate GATE OK (re-run byte-identical), catalogo_core suite exit 0 (227 tests after the amendment suite churn; 233/479 at verify time), tarifario and root Plugins suites exactly at the single maintainer-approved baseline RED (`testUnassignedEditorQuickCreateIsDeniedByRealListener`, pre-existing WIP multitarifa), PHPStan `[OK] No errors` (177 files, exit 0), standalone bootstrap idempotent with 4 tables + PK, `find_controller` + Twig loader resolve to catalogo_core in both activation orders, and the SQL menu proof recorded (`ventas_familias` row deleted idempotently; `tarif_familias` → title `Familias`, folder `catalogo`). The only unexecuted items are 10 authenticated-HTTP UI smokes, which the maintainer formally waived at settlement; they are confirmation checks of pre-existing behavior in the moved location (byte-identical move, flows untouched by the diff), not new-behavior verifications.

## Final State (Final-State Authority)

Ranked sources: this archive closes from the orchestrator launch facts (most recent account) over the verify-report snapshot. Where the snapshot's numbers were later superseded, the final state is recorded here and the snapshot value is attributed as history.

| Item | Final value at close | Source |
|------|----------------------|--------|
| CRITICAL findings | 0 | verify-report `critical_findings: 0`; launch facts |
| Blockers | 0 | verify-report frontmatter |
| Machine verdict | FAIL — encoding-only (validator requires 18/18 scenarios; 10 pending-user HTTP smokes unexercised) | verify-report verdict; maintainer waiver at archive |
| Maintainer-accepted residuals | 10 HTTP checks (4 amendment + 6 trio) — see Residuals | launch facts (authoritative) |
| catalogo_core suite | exit 0; 227 tests after Amendment 1 (233 tests / 479 assertions at verify time) | launch facts over verify-report §Tests |
| tarifario suite / root Plugins | at approved baseline: exactly 1 known-RED WIP multitarifa failure, zero new (140/496 and 712/1871 at verify time) | launch facts over verify-report §Tests |
| Grep gate | GATE OK, re-run byte-identical | verify-report §Build & Tests |
| PHPStan | `[OK] No errors` (177 files, exit 0) | verify-report round 2 |
| Standalone bootstrap | idempotent; 4 tables + schema-expected PK | verify-report smokes A1/A2/B1/B2 |
| Controller/Twig resolution | `find_controller` + framework Twig loader resolve to catalogo_core in both activation orders | verify-report C1–C4, D1 |
| Menu SQL proof | `ventas_familias` fs_pages row deleted idempotently; `tarif_familias` → title `Familias`, folder `catalogo` | verify-report §Residual corroboration + task 7.8(c) record |
| TDD compliance | 6/6 checks; 32 new/moved tests in 5 files, all green | verify-report §TDD Compliance |
| Scenario compliance at verify | 13/18 compliant, 5 PENDING-USER + R3-S5 row persistence → waived as residuals at archive | verify-report §Spec Compliance Matrix (historical snapshot) |

## Commit Hashes

| Repo | Commit | Content |
|------|--------|---------|
| tarifario | `9321af5` | trio: extract familias-tarifa package (18 paths removed) |
| catalogo_core | `8149ee8` | trio: absorb familias-tarifa package (18 destination paths + edits) |
| parent (fs-framework root) | `9386bf87` | trio: root `phpunit.xml` retirement (~−6 lines) |
| catalogo_core | `3a5a42e` | Amendment 1: menu repoint to catálogo + `ventas_familias` retirement (tasks 7.1–7.9) |
| clientes_facturacion | `b38fdbc` | post-verify remediation OUTSIDE this change (see below) |

## Post-Verify Fixes

- **`b38fdbc` (plugins/clientes_facturacion, maintainer-owned fourth repo)** — duplicate-trait rename (`factura`→`factura_clientes`, `documento_venta`→`documento_venta_clientes`, `linea_documento_venta`→`linea_documento_venta_clientes` + 9 consumer refs) that unblocked the PHPStan gate (`[OK] No errors`, 177 files, exit 0), resolving verify warning W1. Landed after verify round 1 and outside this change's blast radius; recorded here because the archived verify-report's round-1 FAIL-255 phpstan state is superseded by this fix.

## MAINTAINER-ACCEPTED RESIDUALS (explicit waiver)

The maintainer explicitly waived the following 10 authenticated-HTTP UI smokes at archive settlement ("archiva directamente"). They remain the only unexercised checks; rationale: byte-identical move + surgical edits (the modification inventory touches none of these flows), suites + 32 unit/integration tests + resolution proofs green, and the maintainer visually confirmed pages render in ddev.

**Amendment-1 residuals (4):**
1. Menu renders catálogo → Familias after visiting the page (SQL row state proven; HTTP render of the repointed menu not exercised).
2. `page=ventas_familias` returns framework not-found behavior (`find_controller` fails).
3. `page=ventas_familia` create flow (form render + submit creates the familia) and post-delete redirect lands on `page=tarif_familias`.
4. `page=tarif_familias` full page render with JS served from `plugins/catalogo_core/View/js/familias/index.js` (CLI resolution proofs D1 + view :758 stand in for the browser check).

**Trio residuals (6):**
5. Reorder moves the subtree + `capitulo` recalculation (R2-S3).
6. Promote/demote + `get_next_capitulo` next free number (R2-S4).
7. Toggle persistence at row level for `en_catalogo`/`en_tarifa`/`activa` (R3-S5; JSON response shape unit-proven).
8. Modal add with posibles madres (R4-S7).
9. Per-tarifa etiqueta isolation — two independent rows in `tarif_tarifa_etiqueta_familia` (R4-S8; PK `(codtarifa, codfamilia, etiqueta)` proven live and in XML).
10. Excel template download / chunk import / export (R6-S11; actions registered at :146–157).

Exact reproduction steps for each trio residual: archived `verify-report.md` §Residual. Anyone exercising them later should clear cache first (`CacheManager::clearAll()` or delete `tmp/twig_cache`).

## Known Follow-Ups (recorded, out of scope)

1. `plugins/factura_pdf1/Services/GestorProgramaRoleDefinition.php:40` — inert role entry still names the retired page `ventas_familias`; a future SDD may trim it.
2. `phpstan.neon` configured scope (root `src/` + `tests/`) excludes plugin trees — no direct static analysis of plugin code (verify S-D; optionally extend scope for future plugin SDDs).
3. `tarifario` master still carries the load-bearing WIP multitarifa RED test (`testUnassignedEditorQuickCreateIsDeniedByRealListener`, baseline exception from commit `87b8a4a`) — fix belongs to the multitarifa workstream.
4. DDEV infra flakiness lesson: a PHP command exiting 143 (SIGTERM) during verify was container teardown, not a code failure — check container health (`ddev ps`, restart) before re-running long suites.

## Task Reconciliation (archive-time)

Tasks 6.1–6.5 and 7.8 were left unchecked by apply for verify to execute; they are ticked at archive per plugin convention with annotations referencing verify-report evidence. Reconciliation authority: maintainer's explicit archive order, backed by the verify-report records (grep gate, suites, phpstan, smokes, SQL menu proof). The HTTP parts of 6.4(e) and 7.8(c) are annotated as maintainer-accepted residuals, keeping the ticks honest. Final checkbox state: 39/39 checked, 0 unchecked.

## Round-Trip / Smoke Disposition

- The maintainer-accepted residual record (above) IS the documented smoke disposition; no runtime work was launched at archive.
- **Composer dependency check: SKIPPED (not applicable)** — this change added no Composer dependency; `git show --stat 8149ee8` and `git show --stat 3a5a42e` contain zero composer/vendor paths, and `plugins/catalogo_core/composer.json` is untouched by the change.

## Spec Source of Truth

- Delta (ADDED-only, 10 requirements / 22 scenarios incl. "Menu integration & plain list retirement") synced to main spec: `plugins/catalogo_core/openspec/specs/familias-tarifa-management/spec.md` (created — no prior main spec existed for this domain).
- Mechanical copy verified: `diff -r` delta vs main spec → empty (byte-identical).
- Unaffected domain `articulos-excel-import-export` untouched (delta declares no MODIFIED/REMOVED for it).

## Mechanical Archive Contract Evidence

- Pre-move recursive snapshot taken; change dir moved with `mv` after `git mv` refusal (dir was untracked in the catalogo_core repo; per-skill fallback verified source unchanged vs snapshot before the move).
- Post-move readback: `diff -r` snapshot vs archive → **empty** (byte-identical; phase-passing evidence).
- Archive is additive-audited: this report is the only file created after the readback.

## Archive Contents

- `proposal.md` — intent, scope, approach (trio plan).
- `design.md` — D1–D7 decisions, Amendment 1 grounding, compliance hooks.
- `specs/familias-tarifa-management/spec.md` — delta (ADDED-only, 10 requirements / 22 scenarios).
- `tasks.md` — 39/39 checked incl. archive-time ticks with annotations.
- `verify-report.md` — round-1 + round-2 records; 0 CRITICAL; machine FAIL encoding-only.
- `archive-report.md` — this file.

## Core Containment Check

- Parent `openspec/changes/` (repo root) contains **NO** entry for `absorber-familias-tarifa-en-catalogo-core` — verified before and after the move. The plugin is the sole SDD owner, per `AGENTS.md → "OpenSpec per Plugin (SDD ownership)"` and `plugins/catalogo_core/openspec/config.yaml`.

## Archive Commit

- Repo: `plugins/catalogo_core` (nested repo; git run from that cwd). Explicit paths only: `openspec/config.yaml`, `openspec/specs/familias-tarifa-management/`, `openspec/changes/archive/2026-09-06-absorber-familias-tarifa-en-catalogo-core/`. No `git add -A`; nothing outside `openspec/` staged. Message: `docs: archivar SDD absorber-familias-tarifa-en-catalogo-core (residual aceptado por maintainer)`. No push.
