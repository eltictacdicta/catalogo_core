# Delta: multi-tariff-pricing

## ADDED Requirements

### Requirement: Synchronous tariff→list mirror (R-MT-007)

Every row of `tarif_tarifas` MUST have a mirror row in `catalogo_listas_precio`
with `codlista = codtarifa` and the same `nombre`, `activa`, `por_defecto` and
`coddivisa`. The mirror is what the canonical readers consume
(`CatalogoPriceResolver`, `ListaPriceUpdateTarget`, `CanonicalPriceBooks`) and
what the FKs of `catalogo_opcional_precios`, `catalogo_grupo_tarifas`,
`catalogo_articulo_precios`, `catalogo_familia_estructura` and
`catalogo_articulo_familia_estructura` point to.

`tarif_tarifa::save()` MUST upsert the mirror after the tariff row is written
successfully. When the tariff is the default, `por_defecto` MUST be cleared on
every other row of **both** tables, so the two tables never disagree on which
entry is the default. A failed tariff write MUST NOT touch the mirror.

`tarif_tarifa::delete()` MUST NOT hard-delete a mirror that any of the five
dependent tables references, because their FKs cascade and would silently drop
option prices, group access and family structure. In that case the mirror MUST
be deactivated (`activa = FALSE`, `por_defecto = FALSE`). A mirror with no
dependents MUST be deleted together with the tariff.

The mirror never flows back: editing `catalogo_listas_precio` does not write
`tarif_tarifas`, and a list without a tariff is never promoted to a tariff
(decision 1 of the proposal).

#### Scenario: New tariff creates its mirror

- GIVEN no row `X` in either table
- WHEN `tarif_tarifa(codtarifa=X, nombre='Exportación', coddivisa='USD')->save()` succeeds
- THEN `catalogo_listas_precio` has `codlista = X`, `nombre = 'Exportación'`, `coddivisa = 'USD'`

#### Scenario: Editing a tariff updates its mirror

- GIVEN tariff `X` and its mirror, both with `coddivisa = 'EUR'`
- WHEN the tariff is saved with `coddivisa = 'USD'` and a new name
- THEN the mirror carries `coddivisa = 'USD'` and the new name

#### Scenario: Default is exclusive across both tables

- GIVEN `A` is the default in both tables
- WHEN tariff `B` is saved with `por_defecto = TRUE`
- THEN only `B` has `por_defecto = TRUE` in `tarif_tarifas`
- AND only `B` has `por_defecto = TRUE` in `catalogo_listas_precio`

#### Scenario: Failed tariff write leaves the mirror alone

- GIVEN a tariff whose `test()` fails (invalid currency)
- WHEN `save()` runs
- THEN no statement targets `catalogo_listas_precio`

#### Scenario: Delete keeps a referenced mirror, deactivated

- GIVEN tariff `X` whose mirror has rows in `catalogo_opcional_precios`
- WHEN `delete()` runs
- THEN the tariff row is deleted
- AND the mirror still exists with `activa = FALSE` and `por_defecto = FALSE`
- AND the option prices of `X` still exist

#### Scenario: Delete removes an unreferenced mirror

- GIVEN tariff `X` whose mirror has no dependent rows
- WHEN `delete()` runs
- THEN neither `tarif_tarifas` nor `catalogo_listas_precio` contains `X`

### Requirement: Mirror backfill (R-MT-008)

`CatalogLegacyTableMigration` MUST realign existing mirrors on every run, for
every tariff, independently of `tarif_opcional_precios`: it inserts the
missing mirrors, updates `nombre`, `activa`, `por_defecto` and `coddivisa`
of the ones that differ from their tariff, and demotes any list that is the
default while a different tariff is the default. Because the migration runs on
every boot (`Init.php`), steady state MUST cost at most two `LIMIT 1`
pre-check SELECTs and no write. The step MUST skip silently when either table
is missing.

#### Scenario: Stale mirror realigned

- GIVEN tariff `X` with `coddivisa = 'USD'` and a mirror with `coddivisa = 'EUR'`
- WHEN the migration runs
- THEN the mirror has `coddivisa = 'USD'`

#### Scenario: Tariff without option prices gets its mirror

- GIVEN tariff `X` with no row in `tarif_opcional_precios` and no mirror
- WHEN the migration runs
- THEN `catalogo_listas_precio` contains `X` with the tariff's data

#### Scenario: Default realigned across tables

- GIVEN tariff `B` is the default and list `DEF` is the default
- WHEN the migration runs
- THEN only `B` is the default in `catalogo_listas_precio`

#### Scenario: Second run is a no-op

- GIVEN all mirrors already aligned
- WHEN the migration runs again
- THEN it issues no write on `catalogo_listas_precio`

### Requirement: Only tariffs are a price concept (R-MT-009)

The price-header entity MUST be presented to operators only as **tariffs**.
`tarif_tarifas` MUST be its single management page, registered in the
`catalogo` menu, and it MUST NOT render, list or mutate price lists. A
`catalogo_listas_precio` row is the internal mirror of a tariff (R-MT-007) and
MUST NOT be offered by any page as an editable entity.

No page MUST offer per-list article prices, a link to a price-list page, or a
`lista` batch-update target. `ventas_listas_precio` MUST remain only as a
redirect to `tarif_tarifas` that renders nothing, reads no request data and
writes nothing, so old bookmarks keep working. Selectors that are keyed by
`codlista` for technical reasons (family structure per tariff) MUST label the
choice as "Tarifa".

#### Scenario: Tariffs page has no list section

- GIVEN tariffs and their mirrors
- WHEN `tarif_tarifas` renders
- THEN only tariffs are listed and no list action exists

#### Scenario: Article editor has no per-list prices

- GIVEN any article
- WHEN `ventas_articulo` renders or receives a POST
- THEN there is no "Precios por lista" section and no `guardar_precios_lista` handling

#### Scenario: Old list URL redirects

- GIVEN a request to `index.php?page=ventas_listas_precio`, GET or POST
- WHEN it is processed
- THEN it redirects to `index.php?page=tarif_tarifas` without writing

### Requirement: Lists without tariff become tariffs (R-MT-010)

`CatalogLegacyTableMigration` MUST, on every boot and before the mirror
backfill (R-MT-008), insert into `tarif_tarifas` one tariff per
`catalogo_listas_precio` row that has no tariff with its code, with the same
code, name (the code when empty, truncated to 50), `activa` and `coddivisa`
(`EUR` when empty). A promoted list MUST NOT become the default tariff when a
default tariff already exists; when none exists, the first promoted default
list keeps the default. The step MUST be non-destructive (no list or price row
is deleted), MUST cost a single `LIMIT 1` read when nothing is pending and MUST
skip when either table is missing.

#### Scenario: Orphan list promoted

- GIVEN list `MAY` (`Mayoristas`, inactive, `USD`) with no tariff
- WHEN the migration runs
- THEN tariff `MAY` exists, inactive, named `Mayoristas`, in `USD`

#### Scenario: Default is never stolen

- GIVEN default tariff `DEF` and a stale default list `OLD` without tariff
- WHEN the migration runs
- THEN tariff `OLD` is not the default and its mirror is demoted by R-MT-008

#### Scenario: Promotion runs once

- GIVEN the migration already promoted every orphan list
- WHEN it runs again
- THEN no `INSERT INTO tarif_tarifas` is issued

### Requirement: Tariff is the base batch-update target (R-MT-011)

`catalogo_core` MUST own and register the `tarifa` price update target
(`Services/TarifaPriceUpdateTarget.php`, `Services/TarifaArticlePriceBook.php`)
from `PriceUpdateTargetRegistry::registerDefaults()`, and it MUST be the base
target returned by `defaultCode()`. The `lista` target MUST NOT be registered.
For the `articulos` scope the target reads and writes only
`tarif_articulo_precios`; for `opcionales` it provides no book and the scope's
canonical book (`catalogo_opcional_precios`, keyed by the tariff code) is used.
The batch update MUST therefore work without `tarifario`; `tarifario` MUST NOT
register its own `tarifa` target and keeps contributing only the role-group
filter, the filter hook and the option price history listener.

#### Scenario: Base target without the consumer

- GIVEN `catalogo_core` active and `tarifario` absent, with one active tariff
- WHEN `catalogo_actualizar_precios` renders
- THEN the `tarifa` target is offered and selected by default

#### Scenario: No targets points to the tariffs page

- GIVEN no active tariff
- WHEN `catalogo_actualizar_precios` renders
- THEN it shows that no target is available and links to `tarif_tarifas`

## REMOVED Requirements

### Requirement: Admin CRUD UI for price lists (R-MT-004)

Removed by R-MT-009: price lists are not managed by operators. The currency
rules live on in `tarif_tarifas` (R-TAR-CUR-007).

### Requirement: Per-article price editor (R-MT-005)

Removed by R-MT-009: per-article prices are edited per tariff
(`tarif_articulo_precios`). Existing `catalogo_articulo_precios` rows are kept
but no page writes them.
