# opcionales-tarifa-management Specification

## Purpose

Owned by `catalogo_core`: the opcional domain absorbed from tarifario (list,
detail, per-tarifa prices, tags, activation and history) plus the per-`(tarifa,
opcional)` master state. Slugs, class names and table names stay stable; no data
migration is performed; `familias-tarifa-management` and
`articulos-excel-import-export` are unaffected. Boundary (ownership-boundary
correction): the hierarchical opcionales configurator remains
**tarifario-owned** and is not part of this capability.

## Requirements

### Requirement: Opcional pages served by catalogo_core

`page=tarif_opcionales`, `page=tarif_opcional_edit` and
`page=tarif_opcional_precios` MUST be served from
`plugins/catalogo_core/controller/` with unchanged slugs, class names and
`fs_pages` registry. The moved controllers MUST extend catalogo_core's
`fbase_controller`, MUST NOT require `plugins/tarifario/extras/tarif_controller.php`,
and MUST NOT require any `plugins/tarifario/model/tarif_*opcional*.php`. Views
MUST move under `plugins/catalogo_core/View/` and keep the menu folder label
`tarifario`. `page=tarif_configurador_opcionales` is **not** part of this move: the
configurator stays tarifario-owned (see the configurator boundary requirement).

#### Scenario: Pages render after the move

- GIVEN catalogo_core active and the existing `fs_pages` rows
- WHEN each of the three moved slugs is requested (smoke flow)
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

### Requirement: Hierarchical configurator remains tarifario-owned

The hierarchical opcionales configurator (`page=tarif_configurador_opcionales`
plus its tree views/partials/JS) is **tarifario-exclusive** and MUST NOT move to
`catalogo_core`. It stays in `plugins/tarifario/` preserving the hierarchy of
raiz familias → subfamilias → etiquetas → productos → opcionales and the actions
`htmx_tree`, `push_to_products`, `push_to_tags`, `push_to_children`,
`push_all_down`, `search_opcionales`, `create_opcional`, `edit_opcional`. This is
the ownership-boundary correction applied after the absorption slice (the
configurator fork was reverted to tarifario).

#### Scenario: Configurator stays in tarifario and renders

- GIVEN tarifario active with a familia hierarchy
- WHEN the configurator page and the `htmx_tree` action are requested
- THEN tarifario serves root familias and the tree fragment without fatal error, and no catalogo_core copy exists
- Test: `plugins/tarifario/tests/TarifConfiguradorOpcionalesTest.php`

#### Scenario: Push actions mutate the hierarchy in tarifario

- GIVEN a selected node
- WHEN each push action runs
- THEN descendant/product/tag assignments are updated and the response is the expected fragment/JSON
- Test: `plugins/tarifario/tests/TarifConfiguradorOpcionalesTest.php`

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

### Requirement: Per-tarifa opcional master table

A `tarif_tarifa_opcional` table/model MUST exist: PK `(codtarifa, id_opcional)`; columns `en_catalogo(TRUE)`, `en_tarifa(FALSE)`, `activa(TRUE)`, `orden(0)`; mirroring `tarif_tarifa_familia`. `codtarifa`/`id_opcional` MUST FK `tarif_tarifas`/`catalogo_opcionales`, CASCADE.

#### Scenario: Master schema and keys

- GIVEN the table definition
- WHEN columns, PK and FKs are inspected
- THEN PK is `(codtarifa, id_opcional)` and both FKs resolve
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalTest.php`

### Requirement: Opcional master table bootstrap

`Init::ensureOpcionalesTarifaTables()` MUST create the master table when missing, after `tarif_tarifas`/`catalogo_opcionales`, in FK-safe order. It MUST be idempotent and no-op when the class is absent.

#### Scenario: Fresh standalone install creates the table

- GIVEN no master table and tarifario inactive
- WHEN the catalogo_core bootstrap runs
- THEN the table and all FK targets exist
- Test: `plugins/catalogo_core/tests/InitTarifTarifaOpcionalBootstrapTest.php`

#### Scenario: Bootstrap is idempotent and class-safe

- WHEN the bootstrap reruns and when the class is missing
- THEN it changes no schema and does not fatal
- Test: `plugins/catalogo_core/tests/InitTarifTarifaOpcionalBootstrapTest.php`

### Requirement: Master state precedence

Master `activa` MUST be authoritative: a price row MUST NOT activate an opcional. Master `en_catalogo` MUST win for catalog/export; price `en_catalogo` stays a per-lista inclusion and `tarif_opcional_ext` flags become fallback. Master `orden` is the default, overridden by family/article `orden`.

#### Scenario: Activation ignores price-row presence

- GIVEN a price row with master `activa = FALSE`
- WHEN activation is resolved
- THEN it is inactive; `activa = TRUE` is active without any price row
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php`

#### Scenario: Catalog flag precedence

- GIVEN master `en_catalogo = FALSE` with price/ext `TRUE`
- WHEN catalog/export visibility is resolved
- THEN the master value wins
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php`

#### Scenario: Order defaults with scoped override

- GIVEN master `orden` and a scoped family/article `orden`
- WHEN effective order is computed
- THEN the scoped value overrides the master default
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php`

### Requirement: Master lifecycle: seed, lazy inherit, copy

`install()` MUST seed the default tarifa from `catalogo_opcionales LEFT JOIN tarif_opcional_ext`, inheriting both ext flags. A missing master row MUST inherit ext/defaults (no mandatory backfill). `copy_from_tarifa($origen, $destino)` MUST copy the master and be called from `heredar_estructura()`.

#### Scenario: Install seed inherits ext flags

- GIVEN the default tarifa and existing ext flags
- WHEN `install()` runs
- THEN one row per opcional holds the inherited flags
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalLifecycleTest.php`

#### Scenario: Missing row lazy-inherits

- GIVEN a `(tarifa, opcional)` without a master row
- WHEN its state is resolved
- THEN it returns ext/default values without persisting
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalTest.php`

#### Scenario: Tarifa copy inherits the master

- GIVEN a source tarifa with master rows
- WHEN `heredar_estructura()` creates a tarifa
- THEN the destination copies the source master state
- Test: `plugins/tarifario/tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php`

### Requirement: Master consumption in UI and export

List "Estado" MUST show per-tarifa `activa`. Edit/precios matrices MUST read and write master flags. `tarif_catalogo_view` export MUST read per-tarifa master flags.

#### Scenario: List Estado is per tarifa

- GIVEN one opcional with different `activa` per tarifa
- WHEN the list renders
- THEN "Estado" reflects each tarifa's `activa`
- Test: `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php`

#### Scenario: Matrices persist master flags

- WHEN the edit/precios matrix toggles `activa`, `en_catalogo` or `orden`
- THEN the master row persists the values
- Test: `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php`

#### Scenario: Export uses per-tarifa flags

- GIVEN a tarifa whose master differs from global ext flags
- WHEN `tarif_catalogo_view` exports opcionales
- THEN the exported set follows the master flags
- Test: `plugins/tarifario/tests/Controller/TarifCatalogoOpcionalMasterExportTest.php`

### Requirement: Unchanged boundaries and non-destructive migration

`catalogo_opcionales` XML/API MUST stay unchanged (`ref_sap` stays global in `tarif_opcional_ext`); `tpvmod` MUST NOT change or depend on the master. Migration MUST be additive/lazy with no mandatory backfill.

#### Scenario: Catalog ownership and tpvmod untouched

- WHEN ownership and TPV tests inspect the catalog model and `tpvmod`
- THEN neither references the master nor changes its schema/API
- Test: `plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php`

#### Scenario: Existing database stays valid

- GIVEN an existing database without master rows
- WHEN the change deploys
- THEN state resolves by inheritance and no backfill is needed
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php`
