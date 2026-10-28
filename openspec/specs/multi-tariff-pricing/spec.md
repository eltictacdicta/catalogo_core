# Capability: multi-tariff-pricing

## Requirements

### Requirement: Named price lists (R-MT-001)

The system MUST expose the existing `catalogo_listas_precio` entity (PK `codlista`; columns `nombre`, `activa`, `por_defecto`, `coddivisa`) as the tariff entity. It MUST NOT modify the legacy `tarifa` model (`model/core/tarifa.php`, table `tarifas`).

#### Scenario: List creation enforces single default

- GIVEN an admin creating a second price list with `por_defecto = true`
- WHEN the list is saved
- THEN the save succeeds and only one list in the table has `por_defecto = true` (previous default demoted)

#### Scenario: Inactive lists excluded from public flows

- GIVEN a list with `activa = false`
- WHEN article price editors and batch update list selectable lists
- THEN the inactive list is not offered

### Requirement: Per-article per-list prices (R-MT-002)

The system MUST persist per-list article prices in `catalogo_articulo_precios` with PRIMARY KEY `(referencia, codlista)`, FK to `catalogo_listas_precio` (ON DELETE CASCADE) and FK to `articulos` (referencia, ON DELETE CASCADE), following the pattern of `catalogo_opcional_precios.xml`. Install sequence MUST create `catalogo_listas_precio` before dependent tables.

`catalogo_listas_precio` MUST remain the single source of truth for price lists: the family-structure capability (ADDED `familias-jerarquia-catalogo`) MUST reference lists exclusively through `catalogo_listas_precio.codlista` in its extension tables (`catalogo_familia_estructura`, `catalogo_articulo_familia_estructura`) and MUST NOT reintroduce `codtarifa`-scoped family structures (`tarif_tarifa_familia`, `tarif_tarifa_articulo`) as canonical state. Family-structure assignment is therefore canonicalized under `catalogo_*` extension tables owned by `catalogo_core`; `tarifario`'s legacy tables remain frozen read-only sources until the follow-up deprecation.

#### Scenario: One price per list per article

- GIVEN article `A` and lists `L1`, `L2`
- WHEN prices are saved for A on L1 and L2
- THEN two rows exist and re-saving A/L1 updates the existing row (no duplicate)

#### Scenario: Deleting a list cascades

- GIVEN prices exist on list L1
- WHEN L1 is deleted
- THEN its `catalogo_articulo_precios` rows are deleted

#### Scenario: Family structure references the same list source

- GIVEN a family structure created for list `L1` via `catalogo_familia_estructura`
- WHEN any multi-tariff read resolves lists for the catalog view
- THEN only `catalogo_listas_precio` rows are used as list identities and no legacy `tarif_tarifa_familia.codtarifa` is consulted

#### Scenario: No dual family-structure state

- GIVEN the migration from `tarif_tarifa_familia` has completed
- WHEN a user edits family structure for list `L1` in `catalogo_core`
- THEN only `catalogo_familia_estructura` is written; the frozen `tarif_*` tables receive no writes

### Requirement: Default-list fallback to articulos.pvp (R-MT-003)

The effective price of an article on the default list MUST fall back to `articulos.pvp` when no explicit row exists in `catalogo_articulo_precios`. `articulos.pvp` MUST remain the authoritative default-list price and MUST NOT be rewritten by multi-tariff reads.

#### Scenario: Missing row falls back

- GIVEN article A with `pvp = 10.00` and no row on the default list
- WHEN the effective price on the default list is requested
- THEN it resolves to `10.00`

#### Scenario: Explicit row wins

- GIVEN article A with a `catalogo_articulo_precios` row of `12.50` on the default list
- WHEN the effective price on the default list is requested
- THEN it resolves to `12.50`

### Requirement: Admin CRUD UI for price lists (R-MT-004)

The plugin MUST provide an admin-only controller/page for CRUD of price lists
(create, edit, activate/deactivate, set default, delete) with CSRF-protected POST
and framework admin gating.

The list currency MUST be selectable only among the currencies configured in
`admin_divisas` (the `divisa` model). A new list MUST default to the
session/company currency, falling back to `EUR` and then to the first configured
currency. A currency already stored in a list MUST remain selectable even after it
was removed from the catalog, so an unrelated edit is never blocked. When no
currency is configured at all, the controller MUST degrade to the model's
three-character format check instead of refusing to create lists.

#### Scenario: Admin creates a list

- GIVEN an admin submitting a valid list form with CSRF token
- WHEN the POST is processed
- THEN the list persists and a success message is shown

#### Scenario: Non-admin denied

- GIVEN a non-admin user requesting the price-list page
- WHEN the request reaches the controller
- THEN access is denied by the admin gate

#### Scenario: Currency must belong to the catalog

- GIVEN currencies `EUR` and `USD` configured in `admin_divisas`
- WHEN the admin submits a list with `coddivisa = GBP`
- THEN the save is rejected with an explicit message
- AND no row is written

#### Scenario: New list defaults to the session currency

- GIVEN the session/company currency is `USD` and it is configured
- WHEN the admin opens the create form
- THEN `USD` is preselected

#### Scenario: Stored currency removed from the catalog stays selectable

- GIVEN a list stored with `coddivisa = MXN` after `MXN` was removed from the catalog
- WHEN the admin edits only that list's name
- THEN the save is accepted and the currency is preserved
- AND `MXN` is not offered when creating a new list

#### Scenario: No catalog configured degrades to format validation

- GIVEN no currencies configured in `admin_divisas`
- WHEN the admin submits a list with a three-character currency
- THEN the save is accepted
- AND a value with any other length is rejected

### Requirement: Per-article price editor (R-MT-005)

The article editing page MUST offer an editor showing, for each active list, either the stored per-list price or the effective fallback, and MUST save per-list rows through the model with validation.

#### Scenario: Editor shows fallback

- GIVEN article A without explicit default-list price
- WHEN the editor renders
- THEN the default list column shows the `articulos.pvp` value marked as fallback

#### Scenario: Editor persists list price

- GIVEN an admin entering `9.99` for list L2 on article A
- WHEN the editor form is saved (CSRF-valid POST)
- THEN `catalogo_articulo_precios` contains (A, L2, 9.99)

### Requirement: Canonical family-structure extension tables under multi-tariff (R-MT-006)

Within the multi-tariff domain, per-list family structure and per-list article-family assignment MUST be owned by `catalogo_core` through the canonical extension tables `catalogo_familia_estructura` and `catalogo_articulo_familia_estructura` (defined in the `familias-jerarquia-catalogo` capability), both keyed to `catalogo_listas_precio.codlista`. Price logic (per-article per-list prices, default-list fallback) remains governed by R-MT-002/R-MT-003 and is untouched by family structure.

#### Scenario: Structure install depends on lists

- GIVEN a fresh install of `catalogo_core`
- WHEN the install sequence creates the extension tables
- THEN `catalogo_listas_precio` is created before `catalogo_familia_estructura`, which is created before `catalogo_articulo_familia_estructura`

#### Scenario: Price behavior unaffected by family structure

- GIVEN article `A` with a per-list price and a family-structure assignment on list `L1`
- WHEN the effective price of `A` on `L1` is resolved
- THEN it follows R-MT-002/R-MT-003 exactly (explicit row, else `articulos.pvp` fallback) with no family-structure join involved
