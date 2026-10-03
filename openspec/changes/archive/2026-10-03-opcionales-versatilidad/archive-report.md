# Archive Report: opcionales-versatilidad

- **Change**: `opcionales-versatilidad`
- **SDD owner**: `plugins/catalogo_core/openspec/` (plugin-local, `ownership: plugin-local`, `strict_tdd: true`)
- **Artifact store**: openspec
- **Archived at**: 2026-10-03
- **Archive path**: `plugins/catalogo_core/openspec/changes/archive/2026-10-03-opcionales-versatilidad/`
- **Branch**: `feat/opcionales-versatilidad`
- **Core `openspec/` entry**: NONE (verified clean; correct for a 100% plugin-local change)

## Verdict

**`pass_with_warnings`** — carried from `verify-report.md`.

All functional requirements (OVE-01..OVE-08, FLC-01..FLC-09, GDI-13) are
implemented and verified by a green focused + full plugin suite and a green root
regression. No CRITICAL findings. The single warning is a process/evidence gap
(the live-browser manual smoke was not run because the maintainer was away) and
does not gate archive.

### Final-state test evidence

| Run | Result |
|-----|--------|
| Full plugin suite | **1106 tests, 4844 assertions, 0 failures** (2 warnings, 1 skipped — pre-existing) |
| Root regression | **2913 tests, 11190 assertions, 0 failures** (24 skipped — pre-existing) |
| Boundary (OVE-08) | OK (2 tests, 43 assertions) |
| PHPStan | 1 pre-existing error in core `tests/Core/PluginEnableAjaxSafetyTest.php` (unrelated) |

## Delivered work units (branch `feat/opcionales-versatilidad`)

| Commit | Work unit |
|--------|-----------|
| `3f26cb31` | SDD change artifacts |
| `b04d4482` | WU-1 — quantity range (`cantidad_min`/`cantidad_max`), image column, flow schema + models, migration, boot ensure |
| `554db616` | WU-2 — optional editor language fields |
| `84bd9335` | WU-3 — single-image service + editor upload/replace/remove |
| `ecc2b363` | WU-4 — flow resolver, cycle detection, cascade helpers |
| `8d0b5e5c` | WU-4b — flow cascade wiring into delete paths |
| `f0134be9` | WU-5 — bulk export/import (opcionales + flows) |
| `4d16a453` | WU-6 — boundary test, verify report, task closure |

## Spec sync (performed at archive)

| Domain | Action |
|--------|--------|
| `opcionales-versatilidad` | NEW canonical `openspec/specs/opcionales-versatilidad/spec.md` (OVE-01..08) |
| `flujos-condicionales` | NEW canonical `openspec/specs/flujos-condicionales/spec.md` (FLC-01..09) |
| `gestion-idiomas` | GDI-13 appended to the canonical spec (optional name/description read chain) |

## Warnings (non-blocking)

1. **Manual smoke pending (WU-6.T5).** The live-browser flow (create/edit an
   opcional with quantity, image and two languages; create a flow; export and
   re-import) requires an interactive session. The htmx image fragment swap is
   asserted structurally and Twig-parses; the live swap is not yet browser-proven.
2. **Pre-existing PHPStan error** in core `tests/Core/PluginEnableAjaxSafetyTest.php:308`
   — outside this plugin and this change.

## Boundary

- No file owned by `gate-tarifario-surfaces-by-plugin-activation` was modified
  (asserted by `CatalogoOpcionalVersatilidadBoundaryTest`).
- The migration keeps the FK to the article table via a documented
  frozen-source-guard constant (`REFERENCED_PRODUCT_TABLE`); flagged at WU-1 and
  left for follow-up review.
- No core `openspec/` entry was created.
