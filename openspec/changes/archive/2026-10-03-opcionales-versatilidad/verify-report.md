# Verify Report: opcionales-versatilidad

- **Change**: `opcionales-versatilidad`
- **SDD owner**: `plugins/catalogo_core/openspec/` (plugin-local, `strict_tdd: true`)
- **Artifact store**: openspec
- **Branch**: `feat/opcionales-versatilidad`
- **Verified at**: 2026-10-03
- **Verifier**: orchestrated implementation verification (WU-1..WU-5 + WU-6 automated checks)

## Verdict

**`pass_with_warnings`**

All functional requirements are implemented and verified by the automated
evidence below. The only gaps are process/evidence gaps (a live-browser manual
smoke not run because the maintainer was away) and a pre-existing, unrelated
PHPStan error — neither is a defect in the delivered behavior.

## Evidence

| Run | Command | Result |
|-----|---------|--------|
| WU-1 focused | `phpunit --filter 'CatalogoOpcionalVersatilidadTest\|CatalogoFlujoModelTest\|CatalogLegacyTableMigrationTest\|InitOpcionalesTablesTest'` | OK (44 tests, 189 assertions) |
| WU-2 focused | `--filter 'CatalogoOpcionalIdiomaTest\|VentasOpcionalIdiomaTest'` | OK (16 tests, 49 assertions) |
| WU-3 focused | `--filter 'OpcionalImagenServiceTest\|VentasOpcionalImagenTest'` | OK (20 tests, 74 assertions) |
| WU-4 focused | `--filter 'FlujoResolverTest\|CatalogoFlujoCycleTest\|CatalogoFlujoModelTest'` | OK (19 tests, 44 assertions) |
| Cascade wiring | `--filter 'CatalogoFlujoCascadeWiringTest\|...'` | OK (16 tests, 54 assertions) |
| WU-5 focused | `--filter 'OpcionalExcelExportTest\|OpcionalExcelImportWizardTest\|FlujoExcelImportTest'` | OK (13 tests, 68 assertions) |
| Boundary (OVE-08) | `--filter CatalogoOpcionalVersatilidadBoundaryTest` | OK (2 tests, 43 assertions) |
| Full plugin suite | `phpunit -c plugins/catalogo_core/phpunit.xml` | **1106 tests, 4844 assertions, 0 failures** (2 warnings, 1 skipped — pre-existing) |
| Root regression | `phpunit` | **2913 tests, 11190 assertions, 0 failures** (24 skipped — pre-existing) |
| Static analysis | `composer phpstan` | 1 error, **unrelated** and pre-existing (`tests/Core/PluginEnableAjaxSafetyTest.php:308`, outside this plugin) |

## Requirements coverage

| Req | Strength | Result |
|-----|----------|--------|
| OVE-01 quantities store/validation | must | pass — defaults 1/1; `max < 1` and `min > max` rejected; `min = 0` allowed |
| OVE-02 loose + grouped | must | pass — column on the opcional; same value both ways |
| OVE-03 single image store/serve | must | pass — bare filename; `imagen_url()`; MIME allow-list by `finfo` |
| OVE-04 image editor | must | pass — upload/replace/remove, CSRF, previous file deleted |
| OVE-05 export parity | must | pass — new columns, default language first, read-only |
| OVE-06 import idempotency + orphans | must | pass — upsert by `codigo`, base64 image, rejected-rows output |
| OVE-07 additive migration | must | pass — new columns/tables only, PG+MySQL, idempotent |
| OVE-08 change boundary | must | pass — boundary test green; core `openspec/` clean |
| FLC-01..FLC-09 flow engine | must | pass — entity, closed vocabularies, código refs, assignment, read-only exposure, order/precedence, cycle detection, cascade, export/import |
| GDI-13 optional i18n read chain | must | pass — requested → default → base; empty pair deletes; no materialisation |

## Warnings (non-blocking)

1. **Manual smoke not run.** WU-6.T5 (live browser: create/edit opcional with
   quantity, image and two languages; create a flow; export and re-import)
   requires an interactive session; the maintainer was away. The htmx image
   fragment swap is asserted structurally and Twig-parses, but the live swap is
   not browser-proven.
2. **Pre-existing PHPStan error** in `tests/Core/PluginEnableAjaxSafetyTest.php`
   (core, unrelated to this plugin/change). This plugin is not in the PHPStan
   analysed paths.

## Boundary

- No file owned by `gate-tarifario-surfaces-by-plugin-activation` was modified
  (asserted by `CatalogoOpcionalVersatilidadBoundaryTest`).
- No core `openspec/changes/opcionales-versatilidad/` entry exists.

## Delivered commits (branch `feat/opcionales-versatilidad`)

| Commit | Slice |
|--------|-------|
| `3f26cb31` | SDD change artifacts |
| `b04d4482` | WU-1 foundation (quantity range, image column, flow schema/models) |
| `554db616` | WU-2 editor language fields |
| `84bd9335` | WU-3 single-image service + editor |
| `ecc2b363` | WU-4 flow resolver, cycle detection, cascade helpers |
| `8d0b5e5c` | Flow cascade wiring into delete paths |
| `f0134be9` | WU-5 bulk export/import |
| (pending) | WU-6 boundary test + this report |
