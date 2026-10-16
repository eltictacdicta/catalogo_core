# Delta for multi-tariff-pricing

## ADDED Requirements

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

The system MUST persist per-list article prices in a new table `catalogo_articulo_precios` with PRIMARY KEY `(referencia, codlista)`, FK to `catalogo_listas_precio` (ON DELETE CASCADE) and FK to `articulos` (referencia, ON DELETE CASCADE), following the pattern of `catalogo_opcional_precios.xml`. Install sequence MUST create `catalogo_listas_precio` before dependent tables.

#### Scenario: One price per list per article

- GIVEN article `A` and lists `L1`, `L2`
- WHEN prices are saved for A on L1 and L2
- THEN two rows exist and re-saving A/L1 updates the existing row (no duplicate)

#### Scenario: Deleting a list cascades

- GIVEN prices exist on list L1
- WHEN L1 is deleted
- THEN its `catalogo_articulo_precios` rows are deleted

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

The plugin MUST provide an admin-only controller/page for CRUD of price lists (create, edit, activate/deactivate, set default, delete) with CSRF-protected POST and framework admin gating.

#### Scenario: Admin creates a list

- GIVEN an admin submitting a valid list form with CSRF token
- WHEN the POST is processed
- THEN the list persists and a success message is shown

#### Scenario: Non-admin denied

- GIVEN a non-admin user requesting the price-list page
- WHEN the request reaches the controller
- THEN access is denied by the admin gate

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
