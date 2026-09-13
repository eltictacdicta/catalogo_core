# Delta for opcionales-tarifa-management

New capability owned by catalogo_core after absorbing tarifario's opcional domain. No MODIFIED/REMOVED requirements: `familias-tarifa-management` and `articulos-excel-import-export` are unaffected. Slugs, class names and table names stay stable; no data migration is performed.

## ADDED Requirements

### Requirement: Opcional pages served by catalogo_core

`page=tarif_opcionales`, `page=tarif_opcional_edit`, `page=tarif_opcional_precios` and `page=tarif_configurador_opcionales` MUST be served from `plugins/catalogo_core/controller/` with unchanged slugs, class names and `fs_pages` registry. The moved controllers MUST extend catalogo_core's `fbase_controller`, MUST NOT require `plugins/tarifario/extras/tarif_controller.php`, and MUST NOT require any `plugins/tarifario/model/tarif_*opcional*.php`. Views MUST move under `plugins/catalogo_core/View/` and keep the menu folder label `tarifario`.

#### Scenario: Pages render after the move

- GIVEN catalogo_core active and the existing `fs_pages` rows
- WHEN each of the four slugs is requested (smoke flow)
- THEN the catalogo_core controller serves it without fatal error
- Test: `plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php` + dev-DB smoke

#### Scenario: Moved controllers decoupled from tarifario opcional code

- GIVEN the four moved controller sources
- WHEN the contract test inspects their parent class and require statements (grep gate)
- THEN each extends `fbase_controller` and zero `tarif_controller` / `plugins/tarifario/model/tarif_*opcional*` requires exist
- Test: `plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php`

### Requirement: Opcional domain models owned by catalogo_core

Models `tarif_opcional`, `tarif_opcional_ext`, `tarif_opcional_precio`, `tarif_opcional_precio_historial`, `tarif_tarifa_opcional_etiqueta`, `tarif_tarifa_opcional_familia`, `tarif_tarifa_articulo_opcional` and `tarif_tarifa_opcional_resolver` MUST live in `plugins/catalogo_core/model/` with unchanged class names, namespaces and `table_name` values. `ref_sap`, `en_catalogo` and `en_tarifa` MUST stay in the 1:1 `tarif_opcional_ext` table and MUST NOT be promoted into `catalogo_opcionales`; the `catalogo_opcionales` XML schema and public API MUST remain unchanged (additive only).

#### Scenario: Models load from catalogo_core

- GIVEN catalogo_core active and tarifario inactive
- WHEN an integration test instantiates each moved model class
- THEN every class resolves from `plugins/catalogo_core/model/` with its original FQCN and table name
- Test: `plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php`

#### Scenario: catalogo_opcionales XML stays untouched

- GIVEN the `catalogo_opcionales` XML
- WHEN the ext-migration test asserts its columns
- THEN `ref_sap`, `codigo2`, `en_catalogo` and `en_tarifa` are absent and `tarif_opcional_ext` is the only home of those fields
- Test: `plugins/catalogo_core/tests/Services/TarifOpcionalExtMigrationTest.php`

### Requirement: Tags per (tarifa, opcional, familia)

`tarif_tarifa_opcional_etiqueta` MUST store tags keyed by `(codtarifa, id_opcional, codfamilia, etiqueta)`. `tarif_opcional_edit`'s families tab MUST persist tags through `replace_etiquetas_opcional`, normalizing blanks and enforcing idempotence, and `tarif_tarifa_opcional_resolver` MUST read them through `get_etiquetas_opcional`.

#### Scenario: Tag replacement is normalized and idempotent

- GIVEN an opcional/familia/tarifa triple with existing tags
- WHEN `replace_etiquetas_opcional` is called with duplicates, blanks and new values
- THEN the stored set is the normalized deduplicated set and a second identical call changes nothing
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalEtiquetaTest.php`

#### Scenario: Tags are isolated per tarifa

- GIVEN the same opcional and familia under two different tarifas
- WHEN tags are saved for the first tarifa
- THEN the second tarifa has no tags and its `get_etiquetas_opcional` returns an empty set
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalEtiquetaTest.php`

### Requirement: Unified per-tarifa opcional prices on codlista

`catalogo_opcional_precios` MUST be the single price source, keyed by `(id_opcional, codlista)`, and the legacy `tarif_opcional_precios` rows (`codtarifa`) MUST be unified into it by `CatalogLegacyTableMigration` when the canonical table is absent. The price UI and moved price actions MUST read/write by `codlista`. No new `tarif_opcional_precios` table may be created.

#### Scenario: Legacy prices are unified once

- GIVEN a database with only `tarif_opcional_precios` rows
- WHEN `CatalogLegacyTableMigration::migrateIfNeeded` runs twice
- THEN `catalogo_opcional_precios` holds the legacy values with `codtarifa` mapped to `codlista`, the default tarifa has a matching price list, and the second run is a no-op
- Test: `plugins/catalogo_core/tests/OpcionalPriceUnificationTest.php`

#### Scenario: Price save/read uses codlista

- GIVEN a canonical price list code
- WHEN the moved price action saves an opcional price and reads it back
- THEN the value is stored and returned for that `codlista`
- Test: `plugins/catalogo_core/tests/TarifOpcionalPreciosControllerTest.php`

### Requirement: Per-tarifa activation and order with inheritance

`tarif_tarifa_opcional_familia` (family level) and `tarif_tarifa_articulo_opcional` (article level) MUST store per-tarifa overrides (`activo`, `orden`) inheriting from `catalogo_opcional_familias` / `catalogo_articulo_opcional`: a missing row means active (inherited), an explicit `activo = FALSE` row disables the relation for that tarifa, and `orden` orders the effective set. `tarif_tarifa_opcional_resolver` MUST compute the effective opcional set applying family tags and article tags, and MUST persist it through `sync_articulo_overrides` / `sync_familia_overrides`.

#### Scenario: Inheritance defaults to active and override disables

- GIVEN a base relation and no per-tarifa row
- WHEN the activation check runs for a tarifa
- THEN it returns active; after storing an explicit `activo = FALSE` row it returns inactive for that tarifa while other tarifas stay active
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalFamiliaTest.php`

#### Scenario: Resolver applies tag intersection rules

- GIVEN a familia with tags and an article with tags
- WHEN `get_opcionales_activos_articulo` runs
- THEN untagged opcionales are included and tagged opcionales only when their tag set intersects the article tags
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalResolverTest.php`

#### Scenario: Override sync persists the effective set

- GIVEN an article with base relations in a tarifa
- WHEN `sync_articulo_overrides` / `sync_familia_overrides` run
- THEN per-tarifa override rows are written for the effective set with activation counts reported
- Test: `plugins/catalogo_core/tests/TarifTarifaArticuloOpcionalTest.php`

### Requirement: Opcional price history

`tarif_opcional_precio_historial` MUST record every effective price change with `id_opcional`, `codtarifa`, previous/new price, timestamp and user, and MUST skip unchanged values. `tarif_historial_precios` with `tipo=opcionales` MUST serve the history page with date filtering, statistics and purge actions.

#### Scenario: Changed price writes one history row

- GIVEN an existing price
- WHEN it is saved with a different value
- THEN exactly one history row is appended with the old and new values; saving the same value appends none
- Test: `plugins/catalogo_core/tests/TarifOpcionalPrecioHistorialTest.php`

#### Scenario: History page renders opcionales mode

- GIVEN the moved history controller active
- WHEN `page=tarif_historial_precios&tipo=opcionales` is requested
- THEN the page renders opcional rows and exposes stats/purge without fatal error
- Test: `plugins/catalogo_core/tests/TarifHistorialPreciosControllerTest.php`

### Requirement: Hierarchical configurator

`page=tarif_configurador_opcionales` and its tree views/partials/JS MUST move to catalogo_core unchanged, preserving the hierarchy of raiz familias → subfamilias → etiquetas → productos → opcionales and the actions `htmx_tree`, `push_to_products`, `push_to_tags`, `push_to_children`, `push_all_down`, `search_opcionales`, `create_opcional`, `edit_opcional`.

#### Scenario: Configurator renders roots and tree fragments

- GIVEN a tarifa with a familia hierarchy
- WHEN the configurator page and the `htmx_tree` action are requested
- THEN root familias and the tree fragment render without fatal error
- Test: `plugins/catalogo_core/tests/TarifConfiguradorOpcionalesTest.php`

#### Scenario: Push actions mutate the hierarchy

- GIVEN a selected node
- WHEN each push action runs
- THEN descendant/product/tag assignments are updated and the response is the expected fragment/JSON
- Test: `plugins/catalogo_core/tests/TarifConfiguradorOpcionalesTest.php`

### Requirement: Standalone FK-safe table bootstrap

With tarifario inactive, `plugins/catalogo_core/Init.php` MUST bootstrap the moved `tarif_*` opcional tables when missing, in FK-safe order: `tarif_opcional_ext` → `tarif_tarifa_opcional_etiqueta` → `tarif_opcional_precio_historial` → `tarif_tarifa_opcional_familia` → `tarif_tarifa_articulo_opcional`, with `catalogo_opcionales` and `tarif_tarifas` available first. The check MUST be idempotent and MUST no-op safely when a model class is absent.

#### Scenario: Fresh standalone install creates the tables

- GIVEN a database without the moved tables and tarifario inactive
- WHEN the catalogo_core bootstrap runs
- THEN all five tables exist and every FK target resolves
- Test: `plugins/catalogo_core/tests/InitOpcionalesTablesTest.php`

#### Scenario: Bootstrap is idempotent and class-safe

- WHEN the bootstrap runs again on an up-to-date database and when a listed model class is missing
- THEN it completes without error, changes no schema, and does not fatal
- Test: `plugins/catalogo_core/tests/InitOpcionalesTablesTest.php`

### Requirement: Dead table references removed and FKs repointed

Raw SQL against the dead `tarif_articulo_opcional` table MUST be repointed to `catalogo_articulo_opcional` and XML FKs referencing the dead `tarif_opcionales` MUST be repointed to `catalogo_opcionales`. The dead XMLs `tarif_opcionales.xml`, `tarif_opcional_familia.xml` and `tarif_articulo_opcional.xml`, and the deprecated wrappers, MUST be removed. After the change zero table-level references to `tarif_articulo_opcional` or `tarif_opcionales` may remain (live PHP class names are exempt).

#### Scenario: Grep gate passes

- GIVEN the applied change
- WHEN the verify gate runs a raw-table-reference grep (e.g. `grep -rnE '(FROM|JOIN|INTO|REFERENCES)[[:space:]]+`?tarif_(articulo_)?opcionales?`?'`) over `plugins/catalogo_core` and `plugins/tarifario`, excluding `vendor/`, `openspec/` and tests
- THEN it returns zero hits
- Test: `plugins/catalogo_core/tests/DeadOpcionalTableReferenceTest.php` (grep gate)

#### Scenario: FK XMLs target catalogo_opcionales

- GIVEN the moved `tarif_tarifa_opcional_familia.xml` and `tarif_tarifa_articulo_opcional.xml`
- WHEN their FKs are inspected
- THEN `id_opcional` references `catalogo_opcionales (id)` and no `tarif_opcionales` reference remains
- Test: `plugins/catalogo_core/tests/DeadOpcionalTableReferenceTest.php`

### Requirement: tpvmod opcional consumption preserved

`plugins/tpvmod/lib/tpvmod_opcionales.php` MUST keep working against the catalogo_core `catalogo_*` classes without changing its public behavior (`tpvmod_opcionales_for_articulo`, `tpvmod_build_opcional_item`, `tpvmod_default_lista_precio`), and MUST NOT gain a dependency on any removed wrapper.

#### Scenario: TPV resolution is unchanged

- GIVEN an article with grouped and ungrouped active opcionales and a default price list
- WHEN `tpvmod_opcionales_for_articulo` runs
- THEN groups/sueltos and prices match the pre-move behavior
- Test: `plugins/tpvmod/tests/TpvmodOpcionalesTest.php`

#### Scenario: No wrapper dependency

- WHEN `tpvmod_opcionales.php` is inspected
- THEN it references only `catalogo_*` opcional classes and no removed `tarif_*opcional*` wrapper
- Test: `plugins/tpvmod/tests/TpvmodOpcionalesTest.php`
