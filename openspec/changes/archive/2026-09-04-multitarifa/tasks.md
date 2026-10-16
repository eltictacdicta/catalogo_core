# Tasks: Multi-Tariff Pricing (multitarifa)

Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` — STRICT TDD (RED → GREEN per logic task).

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~2600–3200 (6 XML tables, 7 models, 3 services, 5 controllers, 5+ views, ~12 test classes) |
| 400-line budget risk | High (well above budget) |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (schema/models/options store) → PR 2 (listener + shim + pages) → PR 3 (batch update + regression) |
| Delivery strategy | ask-on-risk |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Tables, models, CatalogoOptions, transactional list save | PR 1 | phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CatalogoListaPrecio|CatalogoArticuloPrecio|CatalogoOptions|CatalogoGrupo' | N/A — unit-only (no DB flows outside models; DDEV test bootstrap) | New files + catalogo_lista_precio.php; additive, droppable |
| 2 | Listener, shim, Init ordering, 3 pages + excel page removal | PR 2 | phpunit -c plugins/catalogo_core/phpunit.xml --filter 'GroupPermissionListener|ExcelAccess|InitUpgrade|OpcionesCatalogo|VentasListaPrecio' | Existing plugin test fixtures; smoke: load opciones_catalogo page in DDEV | Revert PR 2; Excel access still falls back to legacy key |
| 3 | Batch update controller + full regression suite | PR 3 | phpunit -c plugins/catalogo_core/phpunit.xml | Full suite green in DDEV | Revert PR 3 only (controller + its tests) |

## Phase 1: Schema & Models (foundation)

- [x] 1.1 **[TDD-RED]** Write `tests/CatalogoArticuloPrecioTest.php`: upsert (A/L1, A/L2 → 2 rows; re-save A/L1 updates, no dup), FK cascade on list delete (R-MT-002). GREEN: create `model/table/catalogo_articulo_precios.xml` (composite PK `(referencia, codlista)`, FK→`catalogo_listas_precio` CASCADE, FK→`articulos` CASCADE, mirror `model/table/catalogo_opcional_precios.xml`) and `model/core/catalogo_articulo_precio.php`.
- [x] 1.2 **[TDD-RED]** Write `tests/CatalogoListaPrecioSingleDefaultTest.php`: second default demotes the first; failed save leaves previous default intact. GREEN: wrap demote+upsert in transaction in `model/core/catalogo_lista_precio.php::save()` (D1: beginTransaction/commit/rollback); add `get_default()` fallback to `DEFAULT_CODE='DEF'` (R-MT-001).
- [x] 1.3 Create 4 group tables `model/table/catalogo_grupo_roles.xml`, `catalogo_grupo_usuarios.xml`, `catalogo_grupo_tarifas.xml`, `catalogo_grupo_articulos.xml` + models `model/core/catalogo_grupo.php`, `catalogo_grupo_rol.php`, `catalogo_grupo_usuario.php`, `catalogo_grupo_tarifa.php`, `catalogo_grupo_articulo.php` (FKs + CASCADE, opcional_precios pattern). **[TDD-RED]** `tests/CatalogoGrupoModelsTest.php`: persist group+member(rol gestor)+list+article rows; delete group cascades (R-RG-001).
- [x] 1.4 **[TDD-RED]** Write `tests/Services/CatalogoOptionsTest.php`: flag round-trip (multi-tariff on), safe defaults when keys absent (multi-tariff off, groups off, excelRoles null), excelRoles set/read, legacy fallback when new key absent (R-CO-001, R-CO-002, R-CO-003). GREEN: create `Services/CatalogoOptions.php` per design contract (namespaced `catalogo_core.*` fs_settings keys over `$GLOBALS['config2']`; sole writer; injectable raw override preserving `setSettingRaw()` test pattern).
- [x] 1.5 **[TDD-RED]** Grep-audit test `tests/CodlistaNamingAuditTest.php`: `grep -riE 'codtarifa'` over new files returns zero (D7); extend to `grupocliente|customer group` per R-CEXC-002 scenario.

## Phase 2: Services, Listener & Shim

- [x] 2.1 **[TDD-RED]** Write `tests/Services/GroupPermissionListenerTest.php`: master-off → short-circuit allow (no DB reads); admin dominance; gestor full edit on scoped; editor limited to assigned articles; revisor/visualizador read-only deny; fail-closed on Throwable (deny with reason) (R-RG-002..005). GREEN: create `Services/GroupPermissionListener.php` (invokable, lazy models, silent return = allow; reads master via CatalogoOptions).
- [x] 2.2 Modify `Services/ArticleExcelAccessPolicy.php`: `settingRaw()` reads via `CatalogoOptions::excelRoles()` (new key wins, legacy `catalogo_excel_roles` fallback). **[TDD-RED first]** Extend `tests/ExcelAccessControlBootstrapRegressionTest.php`: new-store-wins, legacy fallback, fail-closed unchanged (R-CO-005). Event class `Event/ArticlePermissionFilterEvent.php` untouched (D2, R-RG-002).
- [x] 2.3 Create `Services/CatalogoRoleListNormalizer.php` (extract `catalogo_excel_roles_normalize()` into shared helper); **[TDD-RED]** `tests/Services/CatalogoRoleListNormalizerTest.php` (valid lists, unparsable → empty/fail-closed).
- [x] 2.4 Modify `Init.php`: register `GroupPermissionListener` unconditionally in `init()` on `ArticlePermissionFilterEvent::NAME` (D4); extend `ensureCatalogTables()` order: `catalogo_articulo_precio` immediately after `catalogo_lista_precio`, then `catalogo_grupo`, then the 4 group dependent tables (D5). **[TDD-RED]** Extend `tests/InitUpgradeTest.php`: assert FK ordering (listas before dependents) and listener registration present.

## Phase 3: Controllers & Views

- [x] 3.1 Create `Services/CatalogoPriceResolver.php` implementing design `PriceResolver` contract (effective = per-list row ?? default-list fallback to `articulos.pvp`; never rewrites pvp). **[TDD-RED]** `tests/Services/CatalogoPriceResolverTest.php` (R-MT-003: fallback + explicit-row-wins).
- [x] 3.2 Create `controller/ventas_listas_precio.php` + `View/ventas_listas_precio.html.twig`: admin-gated CRUD (create/edit/activate/deactivate/set-default/delete), CSRF POST, inactive lists editable only here. **[TDD-RED]** `tests/VentasListaPrecioControllerTest.php` (R-MT-004: admin creates, non-admin denied, CSRF).
- [x] 3.3 Modify `Controller/VentasArticulo.php` + `Controller/VentasArticulos.php` + `view/ventas_articulo.html.twig`: "Precios por lista" tab (active lists only, fallback marker), dispatch `ArticlePermissionFilterEvent` with `ACTION_EDIT_ARTICLE` + referencia on price save (D2). **[TDD-RED]** Extend `tests/ArticlePermissionFilterDispatchTest.php` (R-MT-005, R-RG-002 dispatch semantics).
- [x] 3.4 Create `controller/opciones_catalogo.php` + `View/opciones_catalogo.html.twig`: admin gate, CSRF, whitelist (multi-tariff flag, groups master, Excel roles via `CatalogoRoleListNormalizer`). **[TDD-RED]** `tests/OpcionesCatalogoControllerTest.php` (R-CO-004: persists all fields, success message; multi-tariff off hides tariff UI per R-CO-003).
- [x] 3.5 Update `tests/CatalogoExcelSettingsPageTest.php` to target `opciones_catalogo`; **delete** `controller/catalogo_excel_settings.php` and `view/catalogo_excel_settings.html.twig` (no redirect; menu registers new page).
- [x] 3.6 Hide tariff UI when multi-tariff off: `VentasArticulo` tab, `ventas_listas_precio` entry, and list selectors hidden entirely (R-MT-001 inactive-list exclusion; R-CO-003). **[TDD-RED]** inert-when-off test `tests/MultiTariffFlagInertTest.php`.

## Phase 4: Batch Price Update

- [x] 4.1 Create `Services/CatalogoPriceUpdateService.php`: filter resolution (codlista required + codfamilia + incluir_subfamilias via `familia::hijas()/aux_all()` recursion — no model change — + codgrupo over `catalogo_grupo_articulos`, AND-combined) and formula `round(p * (1 + pct/100), 2)` half-up. **[TDD-RED]** `tests/Services/CatalogoPriceUpdateServiceTest.php` (R-BU-002 recursion/non-recursive/list-scoping; R-BU-003 rounding incl. 10.005→11.01 and −25%→7.50).
- [x] 4.2 Create `controller/catalogo_actualizar_precios.php` + Twig view: preview POST (CSRF, zero write) → confirm checkbox → apply POST (CSRF); zero matches → `new_message()` warning, no write; after apply → `new_message(count)` + affected references, failures → `new_error_msg()` (R-BU-001, R-BU-004, R-BU-005). **[TDD-RED]** `tests/CatalogoActualizarPreciosControllerTest.php`: preview-does-not-persist, apply persists, missing-CSRF rejected, non-admin denied, resumen, empty-selection warning.

## Phase 5: Regression & Verification

- [x] 5.1 Run full suite green: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`; fix fallout from excel-page removal (`CatalogoExcelWizardAccessTest`, `Services/ArticuloExcelPermissionGateTest`).
- [x] 5.2 Verify inert-when-off: full flow with flags off produces zero denies and zero tariff UI (R-RG-005, R-CO-003 tests green).
- [x] 5.3 Verify grep audits pass (D7 codlista-only naming; R-CEXC-002 naming scenario) and no core files touched outside `plugins/catalogo_core/`.
- [x] 5.4 Update change docs: mark spec scenarios covered by test classes; note verify-report readiness.

## Coverage Map

| Spec reqs | Tasks |
|-----------|-------|
| R-MT-001..005 | 1.1, 1.2, 3.1–3.3, 3.6 |
| R-BU-001..005 | 4.1, 4.2 |
| R-CO-001..005 | 1.4, 1.5, 2.2, 2.3, 3.4, 3.5, 3.6 |
| R-RG-001..005 | 1.3, 2.1, 2.2, 2.4, 3.3, 5.2 |
| R-CEXC-002 | 1.4, 1.5, 2.2, 3.5 |

### Phase 5 completion notes (PR3)

- 5.1: full suite 409 tests — only the 2 documented pre-existing failures
  (VentasArticulo(s)ControllerTest::testTwigViewUsesAutoEscape, `|raw` in views
  predating this change; present on main). No fallout from the excel-page removal
  (CatalogoExcelWizardAccessTest, Services/ArticuloExcelPermissionGateTest green).
- 5.2: inert-when-off verified — MultiTariffFlagInertTest, GroupPermissionListenerTest,
  ExcelAccessControlBootstrapRegressionTest, InitUpgradeTest all green (23 tests).
- 5.3: CodlistaNamingAuditTest fixed (path resolved one directory too high, making
  the PR1 scan vacuous) and extended to PR1-PR3 production files; D7 + R-CEXC-002
  pass on real scans. No files outside plugins/catalogo_core touched.
- 5.4 spec scenario coverage: R-BU-001 → CatalogoActualizarPreciosControllerTest
  (preview_before_apply, CSRF, resumen); R-BU-002 → CatalogoPriceUpdateServiceTest
  (recursive/non-recursive/list-scoping/AND) + controller codlista-required test;
  R-BU-003 → CatalogoPriceUpdateServiceTest rounding cases; R-BU-004 → controller
  CSRF/admin-gate structural tests; R-BU-005 → summarizeApply functional test +
  zero-match warning test. Ready for sdd-verify.
