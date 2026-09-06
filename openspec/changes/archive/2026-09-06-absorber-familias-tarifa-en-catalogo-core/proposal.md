# Proposal: Absorb the familias tarifa package into catalogo_core

## Intent

Familias management (family hierarchy + tarifa-family relations) lives in `plugins/tarifario`, but the domain data — the familia hierarchy — belongs to `catalogo_core`'s catalog nucleus. `tarifario` is a consumer of `catalogo_core` (`fsframework.ini` require); owning catalog-domain management from a consumer inverts the conceptual ownership and breaks standalone `catalogo_core` installs: the 4 familias/tarifa relational tables are only created by `tarifario_init.php`, so with `tarifario` inactive the data model is absent. Secondary pain: hardcoded cross-plugin require paths (`ArticlePermissionListener.php:113`, ~15 in tarifario tests) couple the plugins by filesystem path instead of class names.

## Scope

Slug `page=tarif_familias`, class names (`tarif_*`), and DB table names stay unchanged. No data migration.

### In Scope

| File (plugins/tarifario/...) | Action | Target / Notes |
|---|---|---|
| `controller/tarif_familias.php` | move + modify | Reclass to extend `fbase_controller`; drop requires of `extras/tarif_controller.php` (:20) and `model/tarif_tarifa_articulo.php` (:22); replace `count_from_familia` call sites (:698, :719) with raw SQL COUNT |
| `View/tarif_familias.html.twig` | move + modify | Rewrite JS src at :758 to `plugins/catalogo_core/View/js/familias/index.js`; include/import paths unchanged (global Twig loader) |
| `View/Macro/TarifarioComponents.html.twig` | move | 5 remaining tarifario importers keep working via global template loader (Html.php:161-217) |
| `View/partials/familias/modal_exportar_excel.html.twig` | move | Only referenced by the moved view |
| `View/partials/familias/modal_importar_excel.html.twig` | move | Only referenced by the moved view |
| `View/js/familias/{index,excel-export,excel-import,utils}.js` | move | All URLs derive from `familiasConfig.baseUrl` — no internal changes |
| `model/tarif_tarifa.php` + `model/table/tarif_tarifas.xml` | move | tarif_tarifas.xml ≠ legacy `catalogo_core/model/table/tarifas.xml` |
| `model/tarif_familia.php` | move | No own XML; internal require of `catalogo_core/model/core/familia.php` (:21) stays valid |
| `model/tarif_familia_ext.php` + `model/table/tarif_familia_ext.xml` | move | |
| `model/tarif_tarifa_familia.php` + `model/table/tarif_tarifa_familia.xml` | move | |
| `model/tarif_tarifa_etiqueta_familia.php` + `model/table/tarif_tarifa_etiqueta_familia.xml` | move | |
| `tests/Integration/FamiliaOverrideRemovalTest.php` | move + split | Familias assertions → `catalogo_core/tests/`; `tarif_catalogo_view` assertions stay in `tarifario/tests` (Q4) |
| `Services/ArticlePermissionListener.php` (stays) | update refs | Drop require :113 (`model/tarif_tarifa.php`); model autoloader resolves the global alias used at :114 |
| 11 tarifario test files (~15 requires) | update refs | Point `require_once` at `plugins/catalogo_core/model/...` (TarifTarifaTest:31, TarifTabPreciosTest:80, TarifTarifasGuardarTest:53, ArticlePermissionListenerTest:36, VentasArticulosQuickCreateGateCompositionTest:69, PermissionListenerInitRegistrationTest:41, ArticlePermissionListenerImportContextTest:49, 4x ExcelHierarchyService*:30-44, FamiliaOverrideRemovalTest:131) |
| `catalogo_core/tests/FrameworkAutoloaderOverrideTest.php`, `PluginOverrideIntegrationTest.php` | update refs | Pre-broken paths (deleted `model/familia.php`); update to new `tarif_familia` path or retire |
| New: familias table bootstrap in `catalogo_core` | create | FK-safe creation of the 4 moved tables for standalone installs (Q2) |

### Out of Scope

- Tarifas CRUD (`tarif_tarifas` view/controller) — stays in tarifario.
- `tarif_tarifa_articulo` and all article/price-per-tarifa models — stay in tarifario.
- `ExcelHierarchyService` / `ExcelImportWizardService` extraction — explicit follow-up SDD (Q6); they stay in tarifario using class-name refs + stable table names.
- Any rename of slug, class names, table names, or menu folder label 'tarifario' (Q5, cosmetic).
- Data migration — none needed (identical table names).

## Capabilities

> Contract with the spec phase. Existing spec space: `articulos-excel-import-export`.

### New Capabilities
- `familias-tarifa-management`: familias management page (`page=tarif_familias`), familia hierarchy + tarifa-family relation models, the 4 relational tables, standalone table bootstrap, and the `count_articulos` macro contract — owned by catalogo_core.

### Modified Capabilities
- None. `articulos-excel-import-export` requirements are unaffected (moved macro resolves globally; no spec-level behavior change).

## Approach

Atomic move in a single commit: `git mv` files tarifario → catalogo_core with the small modifications above, no intermediate state where files exist in both plugins (prevents autoloader double-declaration). Dependency direction preserved: tarifario → catalogo_core. Template resolution needs no work — Twig loader is global across active plugin View dirs (`src/Core/Html.php:161-217`) and page dispatch scans active plugins' `controller/` dirs (`base/fs_functions.php:124-146`); existing `fs_page` rows keep working with the unchanged slug. Staying-code references to the moved classes resolve via `fs_model_autoloader` (scans model dirs of every active plugin). The last tarifario-model dependency of the moved code (`tarif_tarifa_articulo`, COUNT-only at :698/:719) is decoupled with local raw SQL against the stable table name `tarif_tarifa_articulo`, keeping the public `count_articulos($codfamilia)` contract required by the macro (`TarifarioComponents.html.twig:443`).

## Design Decisions

| # | Question | Decision |
|---|---|---|
| Q1 | Controller base class | Extend `fbase_controller` (plugins/catalogo_core/extras/fbase_controller.php:25). Drop requires of `extras/tarif_controller.php` and `model/tarif_tarifa_articulo.php`. The view/controller only consume `allow_delete` from the chain; tarifa/codtarifa loading already exists in the controller body (:86, :92, :95-99). |
| Q2 | Table creation ownership | catalogo_core-side bootstrap check creates the 4 moved tables in FK-safe order: `tarif_tarifas` → `tarif_familia_ext` → `tarif_tarifa_familia` / `tarif_tarifa_etiqueta_familia`. `tarifario_init` keeps its checks — they are class_exists-guarded and idempotent (guards still resolve via autoloader while tarifario is active). |
| Q3 | Article counts | Replace `count_from_familia` with local raw SQL COUNT against stable table `tarif_tarifa_articulo`. Keep public `count_articulos($codfamilia)` — the macro calls `fsc.count_articulos` (TarifarioComponents.html.twig:443). |
| Q4 | Test split | Familias-related assertions move to `catalogo_core/tests/`; `tarif_catalogo_view`-related assertions stay in `tarifario/tests`. Adjust namespaces/paths accordingly. |
| Q5 | Menu folder label | Keep 'tarifario' (cosmetic, zero risk). |
| Q6 | Excel services | `ExcelHierarchyService` / `ExcelImportWizardService` stay in tarifario using class-name refs + stable table names (`tarif_familia_ext` JOINs). Recorded as explicit follow-up, not this change's scope. |

## Impact / Blast Radius

- Reference holders updated: `ArticlePermissionListener.php:113`; ~15 require paths across 11 tarifario test files; 2 pre-broken catalogo_core tests; JS src in the moved view (:758).
- Staying-code class references to the 5 moving models (~20 sites in models/, Services/, extras/) need no edits — autoloader-resolved. `tarif_familia_ext` is referenced by table-name strings only outside its own file.
- Moved macro keeps serving 5 remaining tarifario views via global loader (catalogo_core is always active: tarifario requires it).

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Missed hardcoded require path to a moved model → fatal at runtime | Med | Grep gate after apply: `plugins/tarifario/model/(tarif_tarifa\|tarif_familia\|tarif_tarifa_familia\|tarif_tarifa_etiqueta_familia\|tarif_familia_ext)` must return zero hits (excluding intentionally updated refs); explore inventory is the checklist |
| Macro contract break (`fsc.count_articulos` at :443) → familias tree rows break | Med | Keep `count_articulos` with raw SQL; controller-level test asserting the method exists (mirror `tarif_catalogo_view.php:1682` precedent) |
| Standalone catalogo_core missing tables when tarifario inactive | Med | Q2 bootstrap with FK-safe order; smoke test with tarifario inactive |
| Partial move / class collision from non-atomic commits | Med | Atomic single-commit move; keep both-activation-orders coverage from FamiliaOverrideRemovalTest |
| Stale Twig cache referencing old macro path | Low | `auto_reload` is on (Html.php:147); run `CacheManager::clearAll()` / clear `tmp/twig_cache` on deploy |

## Rollback Plan

Single-commit atomic move: `git revert` of that commit restores both plugins byte-for-byte (tables, `fs_page` rows, and data are untouched — no migration). If reverted after deploy, clear the Twig cache again so no stale template reference persists.

## Dependencies

- `tarifario` requires `catalogo_core` (`fsframework.ini`) — dependency direction must be preserved.
- ddev environment; PHP 8.2+/8.3; Symfony 7.4; Twig 3; PHPUnit 11.

## Testing Approach

- PHPUnit 11 via ddev (`ddev exec php vendor/bin/phpunit`); plugin-isolated suite `plugins/catalogo_core/phpunit.xml`; root **Plugins** suite auto-discovers `plugins/*/tests/` (moved test stays discovered; no `phpunit.xml` change needed).
- Split FamiliaOverrideRemovalTest per Q4; keep its `$GLOBALS['plugins']` / autoloader state guards intact.
- PHPStan level 5 (`ddev exec composer phpstan`); grep gate as a verify-phase audit.

## Review Workload Forecast (input for sdd-tasks)

- Estimated changed lines: ~4000, overwhelmingly file moves (`git mv`) plus small modifications and reference updates.
- Decision needed before apply: Yes
- Chained PRs recommended: Yes
- 400-line budget risk: High

The default 400-line review budget will be exceeded (~10x) even though authored-diff risk is low; the chained-PR question must be resolved before apply (e.g., PR 1: move + reference updates; PR 2: standalone bootstrap + test split).

## Success Criteria

- [ ] All listed files exist only under `plugins/catalogo_core/`; grep gate returns zero stale `plugins/tarifario/model/...` paths to moved models.
- [ ] `page=tarif_familias` renders with unchanged slug, class name, and table names; no data migration.
- [ ] Moved macro's `fsc.count_articulos` contract works from the moved controller.
- [ ] Standalone `catalogo_core` (tarifario inactive) creates the 4 tables in FK-safe order.
- [ ] Plugin suite + root Plugins suite green; PHPStan level 5 clean.
