# Delta for opcionales-tarifa-management

Per-(tarifa, opcional) master state, ADDED alongside the active absorption delta. Complements — never renames or replaces — its model, activation, and bootstrap requirements. No MODIFIED/REMOVED.

## ADDED Requirements

### Requirement: Per-tarifa opcional master table

A `tarif_tarifa_opcional` table/model MUST exist: PK `(codtarifa, id_opcional)`; columns `en_catalogo(TRUE)`, `en_tarifa(FALSE)`, `activa(TRUE)`, `orden(0)`; mirroring `tarif_tarifa_familia`. `codtarifa`/`id_opcional` MUST FK `tarif_tarifas`/`catalogo_opcionales`, CASCADE.

#### Scenario: Master schema and keys

- GIVEN the table definition
- WHEN columns, PK and FKs are inspected
- THEN PK is `(codtarifa, id_opcional)` and both FKs resolve

### Requirement: Opcional master table bootstrap

`Init::ensureOpcionalesTarifaTables()` MUST create the master table when missing, after `tarif_tarifas`/`catalogo_opcionales`, in FK-safe order. It MUST be idempotent and no-op when the class is absent.

#### Scenario: Fresh standalone install creates the table

- GIVEN no master table and tarifario inactive
- WHEN the catalogo_core bootstrap runs
- THEN the table and all FK targets exist

#### Scenario: Bootstrap is idempotent and class-safe

- WHEN the bootstrap reruns and when the class is missing
- THEN it changes no schema and does not fatal

### Requirement: Master state precedence

Master `activa` MUST be authoritative: a price row MUST NOT activate an opcional. Master `en_catalogo` MUST win for catalog/export; price `en_catalogo` stays a per-lista inclusion and `tarif_opcional_ext` flags become fallback. Master `orden` is the default, overridden by family/article `orden`.

#### Scenario: Activation ignores price-row presence

- GIVEN a price row with master `activa = FALSE`
- WHEN activation is resolved
- THEN it is inactive; `activa = TRUE` is active without any price row

#### Scenario: Catalog flag precedence

- GIVEN master `en_catalogo = FALSE` with price/ext `TRUE`
- WHEN catalog/export visibility is resolved
- THEN the master value wins

#### Scenario: Order defaults with scoped override

- GIVEN master `orden` and a scoped family/article `orden`
- WHEN effective order is computed
- THEN the scoped value overrides the master default

### Requirement: Master lifecycle: seed, lazy inherit, copy

`install()` MUST seed the default tarifa from `catalogo_opcionales LEFT JOIN tarif_opcional_ext`, inheriting both ext flags. A missing master row MUST inherit ext/defaults (no mandatory backfill). `copy_from_tarifa($origen, $destino)` MUST copy the master and be called from `heredar_estructura()`.

#### Scenario: Install seed inherits ext flags

- GIVEN the default tarifa and existing ext flags
- WHEN `install()` runs
- THEN one row per opcional holds the inherited flags

#### Scenario: Missing row lazy-inherits

- GIVEN a `(tarifa, opcional)` without a master row
- WHEN its state is resolved
- THEN it returns ext/default values without persisting

#### Scenario: Tarifa copy inherits the master

- GIVEN a source tarifa with master rows
- WHEN `heredar_estructura()` creates a tarifa
- THEN the destination copies the source master state

### Requirement: Master consumption in UI and export

List "Estado" MUST show per-tarifa `activa`. Edit/precios matrices MUST read and write master flags. `tarif_catalogo_view` export MUST read per-tarifa master flags.

#### Scenario: List Estado is per tarifa

- GIVEN one opcional with different `activa` per tarifa
- WHEN the list renders
- THEN "Estado" reflects each tarifa's `activa`

#### Scenario: Matrices persist master flags

- WHEN the edit/precios matrix toggles `activa`, `en_catalogo` or `orden`
- THEN the master row persists the values

#### Scenario: Export uses per-tarifa flags

- GIVEN a tarifa whose master differs from global ext flags
- WHEN `tarif_catalogo_view` exports opcionales
- THEN the exported set follows the master flags

### Requirement: Unchanged boundaries and non-destructive migration

`catalogo_opcionales` XML/API MUST stay unchanged (`ref_sap` stays global in `tarif_opcional_ext`); `tpvmod` MUST NOT change or depend on the master. Migration MUST be additive/lazy with no mandatory backfill.

#### Scenario: Catalog ownership and tpvmod untouched

- WHEN ownership and TPV tests inspect the catalog model and `tpvmod`
- THEN neither references the master nor changes its schema/API

#### Scenario: Existing database stays valid

- GIVEN an existing database without master rows
- WHEN the change deploys
- THEN state resolves by inheritance and no backfill is needed
