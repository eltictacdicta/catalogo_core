# Delta for multi-tariff-pricing

## MODIFIED Requirements

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

## ADDED Requirements

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
