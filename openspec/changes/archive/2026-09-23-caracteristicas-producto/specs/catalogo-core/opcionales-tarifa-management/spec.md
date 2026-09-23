# Delta for opcionales-tarifa-management

The opcional-level `en_catalogo`/`en_tarifa` flags are removed by D12 of
`caracteristicas-producto`. This delta rewrites only the six requirements that
lock those flags, their master columns, their precedence, their seeding, their UI
consumption and their boundary clause. Everything else in the canonical spec
(tags, unified prices, activation/order inheritance, history, configurator
ownership, FK-safe bootstrap, dead-table references, tpvmod, master bootstrap)
is unchanged.

**Archive-time Purpose rewrite**: the canonical Purpose statement that
`familias-tarifa-management` and `articulos-excel-import-export` are unaffected
is no longer true after this change and MUST be rewritten at archive; the
boundary sentence claiming the two flags live in `tarif_opcional_ext` MUST be
dropped.

## MODIFIED Requirements

### Requirement: Opcional domain models owned by catalogo_core

Models `tarif_opcional`, `tarif_opcional_ext`, `tarif_opcional_precio`,
`tarif_opcional_precio_historial`, `tarif_tarifa_opcional_etiqueta`,
`tarif_tarifa_opcional_familia`, `tarif_tarifa_articulo_opcional` and
`tarif_tarifa_opcional_resolver` MUST live in `plugins/catalogo_core/model/` with
unchanged class names, namespaces and `table_name` values. The 1:1
`tarif_opcional_ext` table MUST keep only `ref_sap` and `codigo2` (and its
existing non-flag columns); `en_catalogo` and `en_tarifa` MUST be removed from it
and MUST NOT be promoted into `catalogo_opcionales`; the `catalogo_opcionales`
XML schema and public API MUST remain unchanged (additive only).
(Previously: `en_catalogo` and `en_tarifa` were locked into `tarif_opcional_ext`
alongside `ref_sap`.)

#### Scenario: Models load from catalogo_core

- GIVEN catalogo_core active and tarifario inactive
- WHEN an integration test instantiates each moved model class
- THEN every class resolves from `plugins/catalogo_core/model/` with its original FQCN and table name
- Test: `plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php`

#### Scenario: catalogo_opcionales XML stays untouched

- GIVEN the `catalogo_opcionales` XML
- WHEN the ext-migration test asserts its columns
- THEN `ref_sap`, `codigo2`, `en_catalogo` and `en_tarifa` are absent from it
- Test: `plugins/catalogo_core/tests/Services/TarifOpcionalExtMigrationTest.php`

#### Scenario: Opcional flags leave tarif_opcional_ext

- GIVEN the `tarif_opcional_ext` schema after the change
- WHEN its columns are inspected
- THEN `en_catalogo` and `en_tarifa` are absent and `ref_sap`/`codigo2` remain
- Test: `plugins/catalogo_core/tests/Services/TarifOpcionalExtMigrationTest.php`

### Requirement: Per-tarifa opcional master table

A `tarif_tarifa_opcional` table/model MUST exist: PK `(codtarifa, id_opcional)`;
columns `activa(TRUE)`, `orden(0)`; mirroring the surviving `tarif_tarifa_familia`
columns. `codtarifa`/`id_opcional` MUST FK `tarif_tarifas`/`catalogo_opcionales`,
CASCADE. `en_catalogo` and `en_tarifa` MUST NOT exist on this table: opcional
catalog/tarifa visibility is derived from the parent product through
`caracteristicas-producto` (`CAR-12`).
(Previously: the master table carried `en_catalogo(TRUE)` and `en_tarifa(FALSE)`.)

#### Scenario: Master schema and keys

- GIVEN the table definition
- WHEN columns, PK and FKs are inspected
- THEN PK is `(codtarifa, id_opcional)`, both FKs resolve, and only `activa`/`orden` state columns exist
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalTest.php`

#### Scenario: No visibility flags on the master

- GIVEN the `tarif_tarifa_opcional` schema
- WHEN its columns are searched for `en_catalogo`/`en_tarifa`
- THEN neither exists
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalTest.php`

### Requirement: Master state precedence

Master `activa` MUST be authoritative: a price row MUST NOT activate an opcional.
Catalog/tarifa visibility MUST NOT be resolved from any opcional-owned flag:
master `en_catalogo` no longer exists, and visibility MUST be derived from the
parent product's effective feature value for the tarifa (D12 existential union).
Master `orden` is the default, overridden by family/article `orden`.
(Previously: master `en_catalogo` won for catalog/export, the price
`en_catalogo` was a per-lista inclusion and `tarif_opcional_ext` flags were the
fallback.)

#### Scenario: Activation ignores price-row presence

- GIVEN a price row with master `activa = FALSE`
- WHEN activation is resolved
- THEN it is inactive; `activa = TRUE` is active without any price row
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php`

#### Scenario: Visibility comes from the parent product, not the opcional

- GIVEN a master `activa = TRUE` opcional whose parent product's effective `en_catalogo` is FALSE
- WHEN catalog/export visibility is resolved
- THEN the opcional is not visible, and setting the parent value TRUE makes it visible
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php`

#### Scenario: Order defaults with scoped override

- GIVEN master `orden` and a scoped family/article `orden`
- WHEN effective order is computed
- THEN the scoped value overrides the master default
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php`

### Requirement: Master lifecycle: seed, lazy inherit, copy

`install()` MUST seed the default tarifa from `catalogo_opcionales`
(`tarif_opcional_ext` no longer carries visibility flags), seeding `activa` and
`orden`. A missing master row MUST inherit defaults (no mandatory backfill).
`copy_from_tarifa($origen, $destino)` MUST copy the master and be called from
`heredar_estructura()`.
(Previously: the seed inherited both ext visibility flags.)

#### Scenario: Install seed inherits the surviving state

- GIVEN the default tarifa and existing opcionales
- WHEN `install()` runs
- THEN one row per opcional holds the inherited `activa`/`orden`
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalLifecycleTest.php`

#### Scenario: Missing row lazy-inherits

- GIVEN a `(tarifa, opcional)` without a master row
- WHEN its state is resolved
- THEN it returns default values without persisting
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalTest.php`

#### Scenario: Tarifa copy inherits the master

- GIVEN a source tarifa with master rows
- WHEN `heredar_estructura()` creates a tarifa
- THEN the destination copies the source master state
- Test: `plugins/catalogo_core/tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php`

### Requirement: Master consumption in UI and export

List "Estado" MUST show per-tarifa `activa`. Edit matrices MUST read and write
master `activa`/`orden`. Catalog/tarifa visibility MUST be rendered as a derived
read-only indicator resolved from the parent product (no opcional-owned toggle),
and `tarif_catalogo_view` export MUST read the derived visibility for the
per-tarifa set.
(Previously: matrices persisted `en_catalogo` and the export read per-tarifa
master visibility flags.)

#### Scenario: List Estado is per tarifa

- GIVEN one opcional with different `activa` per tarifa
- WHEN the list renders
- THEN "Estado" reflects each tarifa's `activa`
- Test: `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php`

#### Scenario: Matrices persist the surviving master state

- GIVEN a master row for an opcional and a tarifa
- WHEN the edit matrix toggles `activa` or `orden`
- THEN the master row persists the values
- AND no opcional-owned visibility flag is written
- Test: `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php`

#### Scenario: Export uses derived visibility

- GIVEN a tarifa whose parent-product values differ from any historical opcional flag
- WHEN `tarif_catalogo_view` exports opcionales
- THEN the exported set follows the derived visibility
- Test: `plugins/tarifario/tests/Controller/TarifCatalogoOpcionalMasterExportTest.php`

### Requirement: Unchanged boundaries and non-destructive migration

`catalogo_opcionales` XML/API MUST stay unchanged (`ref_sap` and `codigo2` stay
global in `tarif_opcional_ext`); `tpvmod` MUST NOT change or depend on the master.
Migration MUST be additive/lazy with no mandatory backfill. The
`en_catalogo`/`en_tarifa` removal from the opcional tables MUST be idempotent and
reversible and MUST be gated on the `CAR-12` behavior-preservation parity test;
it is owned by `caracteristicas-producto` (`CAR-15` clause 1) and does not wait
for the article/family soak.
(Previously: the clause also asserted the two visibility flags stayed in
`tarif_opcional_ext`.)

#### Scenario: Catalog ownership and tpvmod untouched

- GIVEN the applied change
- WHEN ownership and TPV tests inspect the catalog model and `tpvmod`
- THEN neither references the master nor changes its schema/API
- Test: `plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php`

#### Scenario: Existing database stays valid

- GIVEN an existing database without master rows
- WHEN the change deploys
- THEN state resolves by inheritance and no backfill is needed
- Test: `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php`
