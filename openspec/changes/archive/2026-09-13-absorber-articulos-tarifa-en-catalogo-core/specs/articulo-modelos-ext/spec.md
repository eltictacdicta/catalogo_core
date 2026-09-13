# Delta for articulo-modelos-ext

New capability owned by `catalogo_core`: the remaining tarifario article
extension models (`tarif_articulo`, `tarif_articulos_ext` + its XML) move into
`catalogo_core`, and the deprecated `tarif_descripcion` wrapper is deleted. This
is delivery unit **WU-4** of the absorption program (depends on WU-2/WU-3, both
already applied in the working tree).

**Supersession note (design `AD-W2-1` re-slice).** WU-2 already moved
`tarif_tarifa_articulo`, `tarif_tarifa_articulo_etiqueta` and
`tarif_articulo_imagen` into catalogo_core. WU-4 is therefore limited to
`tarif_articulo`, `tarif_articulos_ext` + `model/table/tarif_articulos.xml`, and
the `tarif_descripcion` deletion. Nothing else in the program changes.

The FQCNs (`FSFramework\model\tarif_articulo`,
`FSFramework\model\tarif_articulos_ext`) and the physical table name
(`tarif_articulos`) MUST stay byte-stable. `tarif_articulo` has no table of its
own and MUST keep extending `\FSFramework\model\articulo`. No data migration, no
table rename, no schema or API change. The RBAC boundary stays in `tarifario`.
Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
(baseline **602 tests / 2343 assertions**; tarifario **180 tests / 637
assertions**).

**Out of scope (flagged for the WU-8 audit):** the legacy
`plugins/tarifario/model/table/tarif_descripciones.xml` dead table (already
mapped to `articulo_descripciones` by
`catalogo_core/Services/CatalogLegacyTableMigration.php`) is not removed by
WU-4; only the PHP wrapper class is deleted.

## ADDED Requirements

### Requirement: AME-01 — `catalogo_core` owns `tarif_articulo` and `tarif_articulos_ext`

`FSFramework\model\tarif_articulo` MUST live in
`plugins/catalogo_core/model/tarif_articulo.php`, and
`FSFramework\model\tarif_articulos_ext` + its schema
(`plugins/catalogo_core/model/table/tarif_articulos.xml`) MUST live in
`catalogo_core`. The FQCNs MUST NOT change; `tarif_articulo` MUST keep extending
`\FSFramework\model\articulo` (no own table); `tarif_articulos_ext` MUST keep
`table_name = 'tarif_articulos'` byte-stable. Neither class file MAY exist in
both plugins, and a standalone `catalogo_core` (tarifario inactive) MUST ensure
the `tarif_articulos` table exists idempotently during boot.

_Strength: MUST._

#### Scenario: Models and schema resolve locally from catalogo_core

- GIVEN catalogo_core active and tarifario inactive
- WHEN `FSFramework\model\tarif_articulo` and `FSFramework\model\tarif_articulos_ext` are loaded
- THEN both resolve from `plugins/catalogo_core/model/`, `tarif_articulo` is a subclass of `\FSFramework\model\articulo`, and `tarif_articulos_ext` reports `table_name = 'tarif_articulos'`
- AND the schema exists at `plugins/catalogo_core/model/table/tarif_articulos.xml`
- Test: `plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php`

#### Scenario: Standalone catalogo_core ensures the extension table idempotently

- GIVEN catalogo_core active with tarifario inactive and its Init booted twice
- WHEN the extension bootstrap runs
- THEN `tarif_articulos` is ensured on the first run and the rerun is a harmless no-op (the model only creates the table when missing)
- Test: `plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php`

### Requirement: AME-02 — Deprecated `tarif_descripcion` wrapper is deleted

`plugins/tarifario/model/tarif_descripcion.php` MUST be deleted, and no class
file defining `FSFramework\model\tarif_descripcion` MAY remain in either plugin.
The canonical `articulo_descripcion` model
(`plugins/catalogo_core/model/core/articulo_descripcion.php` +
`model/table/articulo_descripciones.xml`) MUST remain the single multiidioma
description model. Tarifario boot (`extras/tarifario_init.php`) MUST no longer
reference or instantiate the deleted class.

_Strength: MUST / MUST NOT._

#### Scenario: Wrapper gone, canonical description model retained

- GIVEN the applied change
- WHEN both plugins are searched for the description model
- THEN no `tarif_descripcion` class file exists in either plugin
- AND the canonical `articulo_descripcion` model and its XML remain in catalogo_core
- Test: `plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php`

#### Scenario: Tarifario boot no longer instantiates the deleted wrapper

- GIVEN tarifario booting through `extras/tarifario_init.php`
- WHEN its table-ensure sequence runs
- THEN no `tarif_descripcion` instantiation or class reference remains and the boot stays idempotent
- Test: `plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php`

### Requirement: AME-03 — Require paths repointed to catalogo_core

Every hardcoded `require_once` for `tarif_articulo.php` / `tarif_articulos_ext.php`
in tarifario production (`controller/tarif_actualizar_precios.php`,
`controller/tarif_catalogo_view.php`, `Services/ArticuloListActionHandler.php`
and any other production consumer), in `extras/tarifario_init.php`, and in the
tarifario tests that load them (`Model/TarifArticuloFactoryForImportTest`,
`Services/ExcelImportWizardServiceTest`) MUST target the catalogo_core model
paths. The `use FSFramework\model\…` imports and FQCNs MUST stay unchanged, and
`extras/tarifario_init.php` MUST keep its stable-table handling for
`tarif_articulos_ext` as an idempotent no-op.

_Strength: MUST._

#### Scenario: Tarifario production consumers repoint

- GIVEN the applied change
- WHEN tarifario production requires for the moved models are searched
- THEN `controller/tarif_actualizar_precios.php`, `controller/tarif_catalogo_view.php` and `Services/ArticuloListActionHandler.php` require the catalogo_core paths
- AND zero `plugins/tarifario/model/tarif_articulo.php` / `tarif_articulos_ext.php` require remains in tarifario
- Test: `plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php` (grep gate) + `plugins/tarifario/tests/Model/TarifArticuloFactoryForImportTest.php`

#### Scenario: Tests and `tarifario_init.php` repoint with suites green

- GIVEN `Model/TarifArticuloFactoryForImportTest`, `Services/ExcelImportWizardServiceTest` and `extras/tarifario_init.php`
- WHEN their requires/instantiations are inspected
- THEN they target catalogo_core (or the `tarif_descripcion` block is removed) with the FQCNs unchanged
- AND both plugin suites run green
- Test: `plugins/tarifario/tests/Model/TarifArticuloFactoryForImportTest.php`, `plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php`

### Requirement: AME-04 — No half-moved class and no tarifario coupling in catalogo_core

No moved class file or XML MAY exist in both plugins, and no `catalogo_core`
production file MAY reference `plugins/tarifario/` or `@tarifario/` for the
moved models. `model/table/tarif_articulos.xml` MUST be the only
`tarif_articulos` schema in either plugin after the move.

_Strength: MUST / MUST NOT._

#### Scenario: No dual class after the move

- GIVEN the applied change
- WHEN the three moved paths are checked in both plugins
- THEN `model/tarif_articulo.php`, `model/tarif_articulos_ext.php` and `model/table/tarif_articulos.xml` exist only in catalogo_core and their tarifario counterparts are absent
- Test: `plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php`

#### Scenario: Zero tarifario references in catalogo_core production

- GIVEN the catalogo_core production tree (tests/vendor/openspec excluded)
- WHEN `plugins/tarifario/` and `@tarifario/` references for the moved models are searched
- THEN zero hits remain
- Test: `plugins/catalogo_core/tests/ArticuloModelosExtOwnershipTest.php` (grep gate)

### Requirement: AME-05 — Moved models keep their behavior; RBAC boundary stays in tarifario

The moved models MUST keep the public behavior consumed by the list/detail/import
surfaces: `tarif_articulo` (`get`, `search`, `url`, `url_tarifario`,
`get_precio_tarifa` / `set_precio_tarifa` / `delete_precio_tarifa`,
`get_imagenes` / `get_imagen_destacada` / `imagen_destacada_url`,
`get_tarif_extra` / `save_tarif_field` / `get_tarif_field`,
`factory_for_import`) and `tarif_articulos_ext` (`get`, `exists`, `save`,
`delete`, `update_field`, `get_extra_fields`), with table names and columns
unchanged. `ArticlePermissionListener`, `tarif_grupo_articulo` and the RBAC role
tables MUST remain in `tarifario`, with the listener still registered on
catalogo_core's neutral `ArticlePermissionFilterEvent`.

_Strength: MUST._

#### Scenario: Import factory behavior preserved after the move

- GIVEN the repointed `TarifArticuloFactoryForImportTest` and `ExcelImportWizardServiceTest`
- WHEN `tarif_articulo::factory_for_import()` builds an article from a mapped row
- THEN it returns a `tarif_articulo` with `factory_precio` and `factory_tarif_ext` attached
- AND `tarif_articulos_ext::get/save/update_field/get_extra_fields` behave as before against `tarif_articulos`
- Test: `plugins/tarifario/tests/Model/TarifArticuloFactoryForImportTest.php`, `plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php`

#### Scenario: RBAC boundary stays in tarifario

- GIVEN tarifario active with its Init booted
- WHEN the permission listener and `tarif_grupo_articulo` are inspected
- THEN both remain in tarifario and the listener is live on `ArticlePermissionFilterEvent::NAME`
- AND catalogo_core owns no RBAC consumer for the moved models
- Test: `plugins/tarifario/tests/Integration/HookRegistrationTest.php`

### Requirement: AME-06 — Locked contracts and suite baseline

`VentasArticulosControllerTest`, `VentasArticuloControllerTest`,
`Integration/CatalogoCoreHookMarkersTest`, tarifario
`Integration/HookRegistrationTest` and
`Integration/VentasArticulosQuickCreateGateCompositionTest` MUST stay green with
their assertions intact. Every test that pinned the WU-3 boundary
(`ArticuloListaCanonicaOwnershipTest::test_tarif_articulo_and_ext_stay_in_tarifario_for_wu4`
and the `VentasArticuloArticleEditAbsorptionTest` source read of the tarifario
model path) MUST be updated coherently so no test asserts the moved models stay
in tarifario. The catalogo_core suite MUST run at or above **602 tests / 2343
assertions** and the tarifario suite MUST stay green.

_Strength: MUST._

#### Scenario: Locked contracts stay green

- GIVEN the migrated models and the updated tests
- WHEN the five locked test files run
- THEN all pass with their existing assertions (the four marked locked are not weakened)
- Test: `plugins/catalogo_core/tests/VentasArticulosControllerTest.php`, `plugins/catalogo_core/tests/VentasArticuloControllerTest.php`, `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`, `plugins/tarifario/tests/Integration/HookRegistrationTest.php`, `plugins/tarifario/tests/Integration/VentasArticulosQuickCreateGateCompositionTest.php`

#### Scenario: WU-3 boundary assertion updated and suites at baseline

- GIVEN the applied change
- WHEN both plugin suites run
- THEN no test asserts `tarif_articulo` / `tarif_articulos_ext` / `tarif_articulos.xml` stay in tarifario
- AND catalogo_core is at or above 602 tests / 2343 assertions and tarifario is green
- Test: `plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php`, `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`
