# Tasks: opcionales-versatilidad

- **Change**: `opcionales-versatilidad`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Artifact store**: `openspec`
- **Inputs read**: `proposal.md`, `specs/opcionales-versatilidad/spec.md`,
  `specs/flujos-condicionales/spec.md`, `specs/gestion-idiomas/spec.md`, `design.md`

## Session configuration (verbatim)

- `execution_mode: auto`
- `artifact_store: openspec`
- `delivery_strategy: auto-chain`
- `chain_strategy: NOT YET CHOSEN` (orchestrator asks because the forecast below is over budget)
- `review_budget_lines: 800`
- **STRICT TDD MODE IS ACTIVE for `catalogo_core`.** Do not fall back to standard mode.

## Test runners

| Scope | Command |
|---|---|
| catalogo_core (primary) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` |
| Root regression | `ddev exec php vendor/bin/phpunit` |

Tests are DB-free (fake `fs_db2` / anonymous subclasses), mirroring existing tests.

## Confirmed scope decisions (do NOT re-litigate)

- `flujos_por_codigo`: every flow reference (subject, assignment) is by `codigo`; no DB FK across the two possible subject parents.
- `imagen_unica`: one image per opcional; no gallery.
- `i18n_nombre_descripcion`: name **and** description per language.
- `flujos_consumer_side`: the core exposes; `tpvmod`/`clientes_core` evaluate.
- `cantidad_max_ambos`: applies to loose opcionales and grouped values.
- `boundary_concurrent_change`: no edit to the files owned by `gate-tarifario-surfaces-by-plugin-activation`.

## How to read this file

- Work units are `WU-1` … `WU-6`; each maps to the design's rollout table.
- Every task ID is `WU-x.Tn`.
- Strict-TDD rule per WU: listed tests are written/extended FIRST and MUST fail
  (RED), then implementation, then the run (GREEN).
- Revert boundaries apply in reverse WU order. WU-1 is the only schema-changing
  unit; later units revert independently.

---

## Work-unit overview

| WU | Goal | Depends on | Est. lines |
|---|---|---|---|
| WU-1 | Schema + models + migration + boot ensure (qty, image column, i18n table, flow tables) | — | ~700–900 |
| WU-2 | i18n read chain + opcional accessors | WU-1 | ~250–350 |
| WU-3 | Image service + canonical editor integration | WU-1 | ~300–400 |
| WU-4 | Flujo models + resolver + cycle detection + precedence | WU-1 | ~450–600 |
| WU-5 | Bulk export/import (opcionales + flujos) | WU-2, WU-4 | ~500–700 |
| WU-6 | Verify pass (suites, boundary audit, phpstan, smoke) — no production change | all | ~0 |

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~2200–2950 |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | 5 slices (WU-1 … WU-5) |
| Delivery strategy | `auto-chain` |
| Chain strategy | NOT YET CHOSEN |

```text
Decision needed before apply: No (auto-chain)
Chained PRs recommended: Yes
Chain strategy: NOT YET CHOSEN
400-line budget risk: High
```

### Suggested slices

| Slice | Work units | Focused test command | Rollback boundary |
|------|-----------|----------------------|-------------------|
| PR 1 | WU-1 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CatalogoOpcionalVersatilidadTest\|CatalogoFlujoModelTest\|CatalogLegacyTableMigrationTest\|InitOpcionalesTablesTest'` | Revert the 7 schema XML + 6 models + migration/Init; no reader changed. |
| PR 2 | WU-2 | `... --filter 'CatalogoOpcionalIdiomaTest\|VentasOpcionalIdiomaTest'` | Revert the i18n chain + editor language selector; WU-1 stays. |
| PR 3 | WU-3 | `... --filter 'OpcionalImagenServiceTest\|VentasOpcionalImagenTest'` | Revert the image service + editor image block. |
| PR 4 | WU-4 | `... --filter 'CatalogoFlujoCycleTest\|FlujoResolverTest'` | Revert the flow models + resolver. |
| PR 5 | WU-5 | `... --filter 'OpcionalExcelExportTest\|OpcionalExcelImportWizardTest\|FlujoExcelImportTest'` | Revert the two I/O services. |

---

## WU-1 — Schema, models, migration and boot ensure

**Goal**: the three new columns and six new tables exist (PG + MySQL), their
models are usable, and boot ensures them.

**Files**:
- `model/table/catalogo_opcionales.xml` (add `cantidad_min`, `cantidad_max`, `imagen`)
- NEW `model/table/catalogo_opcional_idiomas.xml`
- NEW `model/table/catalogo_flujos.xml`, `catalogo_flujo_condiciones.xml`, `catalogo_flujo_acciones.xml`, `catalogo_flujo_articulos.xml`, `catalogo_flujo_familias.xml`
- NEW `model/core/catalogo_opcional_idioma.php`, `catalogo_flujo.php`, `catalogo_flujo_condicion.php`, `catalogo_flujo_accion.php`, `catalogo_flujo_articulo.php`, `catalogo_flujo_familia.php`
- `model/core/catalogo_opcional.php` (columns + validation)
- `Services/CatalogLegacyTableMigration.php`, `Init.php`
- NEW/updated tests

**Tasks**:
- [ ] `WU-1.T1` RED: `tests/CatalogoOpcionalVersatilidadTest.php` — defaults `cantidad_min = 1` / `cantidad_max = 1`; `cantidad_max < 1` and `cantidad_min > cantidad_max` rejected; `imagen` bare filename + `imagen_url()`.
- [ ] `WU-1.T2` RED: `tests/CatalogoFlujoModelTest.php` — unique `codigo`; unknown operator/action/tipo rejected; AND/OR preserved; article-delete cascade.
- [ ] `WU-1.T3` RED: `tests/Services/CatalogLegacyTableMigrationTest.php` — new tables created idempotently; column-add guarded.
- [ ] `WU-1.T4` RED: `tests/InitOpcionalesTablesTest.php` — boot ensures the new models.
- [ ] `WU-1.T5` GREEN: write the XML schemas (PG + MySQL variants), models, migration method, `Init` list.
- [ ] `WU-1.T6` REFACTOR: re-run the WU-1 suites green.

**Verification**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CatalogoOpcionalVersatilidadTest|CatalogoFlujoModelTest|CatalogLegacyTableMigrationTest|InitOpcionalesTablesTest'`

**Revert boundary**: revert the XML + models + migration/Init; no reader changed.

---

## WU-2 — i18n read chain

**Goal**: `nombre`/`descripcion` resolve by language with the article fallback
chain; empty pair removes the row.

**Files**: `model/core/catalogo_opcional.php`, `model/core/catalogo_opcional_idioma.php`, `Controller/VentasOpcional.php`, `View/ventas_opcional.html.twig`, tests.

**Tasks**:
- [ ] `WU-2.T1` RED: `tests/CatalogoOpcionalIdiomaTest.php` — requested wins; default fallback; base fallback; empty pair deletes.
- [ ] `WU-2.T2` RED: `tests/Controller/VentasOpcionalIdiomaTest.php` — editor language selector + CSRF save.
- [ ] `WU-2.T3` GREEN: implement accessors + editor language block.
- [ ] `WU-2.T4` REFACTOR: re-run green.

**Verification**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CatalogoOpcionalIdiomaTest|VentasOpcionalIdiomaTest'`

**Revert boundary**: revert the i18n chain + editor block; WU-1 stays.

---

## WU-3 — Image service and editor integration

**Goal**: single image upload/replace/remove through the canonical editor, reusing
the article mechanics.

**Files**: NEW `Services/OpcionalImagenService.php`, `Controller/VentasOpcional.php`, `View/ventas_opcional.html.twig`, tests.

**Tasks**:
- [ ] `WU-3.T1` RED: `tests/OpcionalImagenServiceTest.php` — MIME allow-list; bare filename returned; base64 path; physical delete.
- [ ] `WU-3.T2` RED: `tests/Controller/VentasOpcionalImagenTest.php` — replace deletes the previous file; CSRF-guarded.
- [ ] `WU-3.T3` GREEN: implement service + editor image block.
- [ ] `WU-3.T4` REFACTOR: re-run green.

**Verification**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'OpcionalImagenServiceTest|VentasOpcionalImagenTest'`

**Revert boundary**: revert the service + editor block.

---

## WU-4 — Flow resolver, cycle detection and precedence

**Goal**: expose applicable flows read-only, ordered; reject cycles at save.

**Files**: `model/core/catalogo_flujo*.php`, NEW `Services/FlujoResolver.php`, tests.

**Tasks**:
- [ ] `WU-4.T1` RED: `tests/FlujoResolverTest.php` — active-only; article/family scope; order (family → article, prioridad, id); read-only.
- [ ] `WU-4.T2` RED: `tests/CatalogoFlujoCycleTest.php` — direct cycle rejected at save.
- [ ] `WU-4.T3` GREEN: implement resolver + cycle DFS + app-side cascade.
- [ ] `WU-4.T4` REFACTOR: re-run green.

**Verification**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CatalogoFlujoCycleTest|FlujoResolverTest'`

**Revert boundary**: revert the flow models + resolver; WU-1 tables become inert.

---

## WU-5 — Bulk export/import

**Goal**: opcionales and flows export/import by código, idempotent, with orphan
reporting.

**Files**: NEW `Services/OpcionalExcelExportService.php`, `Services/OpcionalExcelImportWizardService.php`, tests.

**Tasks**:
- [ ] `WU-5.T1` RED: `tests/OpcionalExcelExportTest.php` — new columns present, default language first, read-only.
- [ ] `WU-5.T2` RED: `tests/OpcionalExcelImportWizardTest.php` — idempotent update; orphan reported; base64 image.
- [ ] `WU-5.T3` RED: `tests/FlujoExcelImportTest.php` — flow import idempotent by código; orphan subject reported.
- [ ] `WU-5.T4` GREEN: implement the two services.
- [ ] `WU-5.T5` REFACTOR: re-run green.

**Verification**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'OpcionalExcelExportTest|OpcionalExcelImportWizardTest|FlujoExcelImportTest'`

**Revert boundary**: revert the two I/O services.

---

## WU-6 — Verification and boundary audit (no production change)

- [ ] `WU-6.T1` `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` — all green.
- [ ] `WU-6.T2` `ddev exec php vendor/bin/phpunit` — root suites green.
- [ ] `WU-6.T3` `ddev exec composer phpstan` — no new errors.
- [ ] `WU-6.T4` Boundary audit: the diff contains none of the concurrent change's
      files and no core `openspec/` entry (`tests/CatalogoOpcionalVersatilidadBoundaryTest.php`).
- [ ] `WU-6.T5` Manual smoke checklist: create/edit opcional (qty, image, two
      languages), create a flow, export and re-import.

**Revert boundary**: none (no production change).

---

## Do NOT touch (concurrent-change boundary)

- `View/ventas_opcionales.html.twig`
- `Controller/VentasOpcionales.php`
- `extras/VentasOpcionalesListTrait.php`
- `Services/CaracteristicaResolver.php`
- `controller/tarif_opcional_edit.php`
- `View/tarif_opcional_edit.html.twig`
- `openspec/specs/opcionales-management/spec.md`
- core `openspec/`

## Success criteria (mirror of proposal/specs/design)

- [ ] `cantidad_min`/`cantidad_max` default 1, validated (`min >= 0`, `max >= 1`,
      `min <= max`), honored loose and grouped.
- [ ] Single image stored as a bare filename, served from `imgs/opcionales/`,
      upload/replace/remove reusing the article mechanics.
- [ ] `nombre`/`descripcion` resolve per language with the article fallback
      chain; empty pair removes the row.
- [ ] Flows reference by código, are assignable to articles/families, exposed
      read-only and ordered (family → article, prioridad, id); cycles rejected.
- [ ] Opcionales and flows export/import by código, idempotent, orphan-reported.
- [ ] Additive migration only; concurrent-change files and core `openspec/` untouched.
