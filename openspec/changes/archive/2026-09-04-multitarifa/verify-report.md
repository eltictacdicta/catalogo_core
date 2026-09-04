# Verify Report: Multi-Tariff Pricing (multitarifa)

**Change**: `multitarifa` (plugin-local, `plugins/catalogo_core/openspec/changes/multitarifa/`)
**Branch verified**: `feature/multitarifa-pr3` (stacked pr1 → pr2 → pr3; confirmed checked out)
**Date**: 2026-09-04
**Verifier**: sdd-verify executor (independent final verification)

---

## 1. Completeness

| Artifact | Present | Notes |
|---|---|---|
| proposal.md | ✅ | 4 new capabilities + 1 modified |
| design.md | ✅ | Decisions D1–D7 |
| specs/ (5 deltas) | ✅ | 26 requirements-level scenarios counted |
| tasks.md | ✅ | All Phase 1–5 tasks checked [x]; coverage map present |

**Task completion**: 19/19 tasks checked. No incomplete tasks → full verification proceeds.

## 2. Requirements Traceability Matrix

### multi-tariff-pricing (R-MT-001..005)

| Req | Scenario(s) | Implementation | Covering test (passing) |
|---|---|---|---|
| R-MT-001 Named price lists / single default | Single default enforced; inactive excluded | `model/core/catalogo_lista_precio.php` (transactional demote+upsert, `get_default()` DEF fallback) | `CatalogoListaPrecioSingleDefaultTest` |
| R-MT-002 Per-article per-list prices | One row per (ref, list); cascade on list delete | `model/core/catalogo_articulo_precio.php` + `model/table/catalogo_articulo_precios.xml` (composite PK, FKs CASCADE) | `CatalogoArticuloPrecioTest` |
| R-MT-003 Default-list fallback to pvp | Missing row → pvp; explicit row wins | `Services/CatalogoPriceResolver.php` | `CatalogoPriceResolverTest` |
| R-MT-004 Admin CRUD UI | Admin creates; non-admin denied | `controller/ventas_listas_precio.php` + `View/ventas_listas_precio.html.twig` (CSRF @ line 62) | `VentasListaPrecioControllerTest` |
| R-MT-005 Per-article price editor | Fallback shown; save dispatches permission event | `Controller/VentasArticulo.php` / `VentasArticulos.php` + views | `ArticlePermissionFilterDispatchTest`, `MultiTariffFlagInertTest` |

### batch-price-update (R-BU-001..005)

| Req | Implementation | Covering test (passing) |
|---|---|---|
| R-BU-001 Preview/apply flow | `controller/catalogo_actualizar_precios.php` two-phase | `CatalogoActualizarPreciosControllerTest` (preview-not-persist, apply persists) |
| R-BU-002 Filters (list/family/subfamilies recursive/group, AND) | `Services/CatalogoPriceUpdateService.php` (`hijas()` recursion @ L193; AND-combined) | `CatalogoPriceUpdateServiceTest` (recursive/non-recursive/scoping) |
| R-BU-003 Formula half-up 2 decimals | `round(p * (1 + pct/100), 2, PHP_ROUND_HALF_UP)` @ L166 | `CatalogoPriceUpdateServiceTest` (10.005→11.01, −25%→7.50) |
| R-BU-004 CSRF-gated mutation | `isCsrfValid()` both phases @ L75; admin gate | `CatalogoActualizarPreciosControllerTest` (missing-CSRF, non-admin) |
| R-BU-005 Resumen & flash | `new_message(count)` + `summarizeApply`; failures `new_error_msg`; zero-match warning, no write | `CatalogoActualizarPreciosControllerTest` (resumen, empty-selection warning) |

### catalog-options-store (R-CO-001..005)

| Req | Implementation | Covering test (passing) |
|---|---|---|
| R-CO-001 Namespaced store, safe defaults | `Services/CatalogoOptions.php` (`catalogo_core.*` over `$GLOBALS['config2']`) | `CatalogoOptionsTest` |
| R-CO-002 Absorbs catalogo_excel_roles | `CatalogoOptions` legacy-key fallback (`LEGACY_KEY_EXCEL_ROLES` @ L42) | `CatalogoOptionsTest` |
| R-CO-003 New flags (multi-tariff, groups master) | same | `MultiTariffFlagInertTest`, `CatalogoOptionsTest` |
| R-CO-004 Options page replaces excel settings page | `controller/opciones_catalogo.php` (CSRF @ L64); `catalogo_excel_settings.*` deleted | `OpcionesCatalogoControllerTest`, `CatalogoExcelSettingsPageTest` (retargeted) |
| R-CO-005 Backward-compat shim | `Services/ArticleExcelAccessPolicy.php` reads via `CatalogoOptions::excelRoles()` | `ExcelAccessControlBootstrapRegressionTest` (new-store-wins, legacy fallback, fail-closed) |

### role-based-price-access (R-RG-001..005)

| Req | Implementation | Covering test (passing) |
|---|---|---|
| R-RG-001 Group storage + cascade | `model/core/catalogo_grupo*.php` + 4 XML tables | `CatalogoGrupoModelsTest` |
| R-RG-002 First-party listener | `Services/GroupPermissionListener.php` on `ArticlePermissionFilterEvent` | `ArticlePermissionFilterDispatchTest`, `InitUpgradeTest` (registration) |
| R-RG-003 Admin dominance | Listener silent-return (allow) @ L27–28 | `GroupPermissionListenerTest` |
| R-RG-004 Role matrix (gestor/editor/revisor) | Listener lazy models, fail-closed on Throwable | `GroupPermissionListenerTest` |
| R-RG-005 Master-off inert | First-statement short-circuit before any DB read (L22–28 docblock + code) | `GroupPermissionListenerTest`, `MultiTariffFlagInertTest` |

### articulos-excel-access-settings (R-CEXC-002 delta)

| Scenario | Covering test (passing) |
|---|---|
| Admin persists roles / absent / unparsable | `OpcionesCatalogoControllerTest`, `CatalogoRoleListNormalizerTest` |
| Legacy fallback | `CatalogoOptionsTest`, `ExcelAccessControlBootstrapRegressionTest` |
| Nomenclature (grep-auditable) | `CodlistaNamingAuditTest` (fixed to scan real PR1–PR3 production files; `codtarifa` appears only in out-of-scope legacy `model/core/tarifa.php`) |

**Traceability verdict**: all 21 requirements across 5 capability deltas have implementing files and passing covering tests. No requirement without evidence.

## 3. Runtime Evidence

**Command**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
**Exit code**: 1 (expected — 2 documented pre-existing failures)

```
FAILURES!
Tests: 409, Assertions: 893, Failures: 2, Warnings: 25, Skipped: 4.
```

**Pre-existing failure classification (verified, not assumed)**: the two failures (`VentasArticuloControllerTest::testTwigViewUsesAutoEscape`, `VentasArticulosControllerTest::testTwigViewUsesAutoEscape` — `|raw` usage in views predating this change) were reproduced on a clean `main` worktree (`git worktree add ../catalogo_core_main_verify main` + vendor symlink, same filter run: `Tests: 7, Failures: 2`, identical failure message). Worktree removed after verification. **Confirmed: not introduced by this change.**

**Focused spot-check run** (all requirement-relevant suites):
`--filter 'SingleDefault|Inert|Resolver|OptionsTest|Listener|ExcelAccessBootstrap|ActualizarPrecios|CodlistaNaming|Normalizer|GrupoModels|ListaPrecioController|Dispatch|ArticuloPrecio|PrecioUpdate'`
→ `OK (115 tests, 275 assertions)`, exit 0.

## 4. Scenario Spot-Checks (source-inspected, evidence-based)

| Check | Evidence | Result |
|---|---|---|
| Single-default transactional save | `beginTransaction/commit/rollback` in `catalogo_lista_precio.php` (L145/171/173); rollback preserves previous default | PASS |
| Groups master-off inert | `GroupPermissionListener` short-circuits on first statement, zero DB reads, silent return = only allow path | PASS |
| Excel access legacy-key fallback | `CatalogoOptions::LEGACY_KEY_EXCEL_ROLES` + shim via `excelRoles()`; regression test green | PASS |
| Batch preview writes nothing; zero-match warns without write | `preview()` zero-write service + controller `new_message()` warning path; controller tests green | PASS |
| CSRF on all new POST flows | `isCsrfValid()` in `catalogo_actualizar_precios.php:75`, `ventas_listas_precio.php:62`, `opciones_catalogo.php:64`, plus VentasArticulo price save dispatch (covered by controller tests) | PASS |

## 5. Non-Goals Audit

- `git diff main..HEAD` → **42 files, all under `plugins/catalogo_core/`**. Zero files outside the plugin.
- `model/core/tarifa.php`: identical blob on both refs (`100644 blob 4eb09a5f…`), no diff, no commits touching it. Legacy model untouched. ✅
- Naming audit: `codtarifa` present only in legacy `tarifa.php` (out of scope by design); new code is `codlista`-only. ✅

## 6. Issues

### CRITICAL
None.

### WARNING
- **W-1**: Full suite exit code is 1 due to the 2 pre-existing `testTwigViewUsesAutoEscape` failures. Classification as pre-existing is proven (reproduced on `main`), but they are real open defects in the plugin lineage and will keep gating every future verify/archive at exit 1. Recommend a follow-up change to remove `|raw` from `View/ventas_articulo(s).html.twig`.

### SUGGESTION
- **S-1**: 25 warnings / 4 skipped in the suite — not related to this change, but worth a one-line inventory in the next change's tasks to avoid silent growth.

## 7. Verdict

**PASS WITH WARNINGS**

- 409 tests, 893 assertions, only the 2 documented pre-existing failures (proven present on `main`).
- All 21 requirements across 5 capability deltas: implemented + covered by passing tests.
- Non-goals respected: nothing outside `plugins/catalogo_core/` modified; legacy `tarifa` untouched.
- No CRITICAL issues; one WARNING (pre-existing `|raw` failures, pre-change lineage) and one SUGGESTION.

**Next step**: `sdd-archive` (plugin-local, under `plugins/catalogo_core/openspec/`).
