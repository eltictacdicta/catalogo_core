# Delta for batch-price-update

## ADDED Requirements

### Requirement: Preview/apply flow (R-BU-001)

The system MUST implement the "actualizar precios" flow in two phases: a preview phase that computes the affected articles and their prospective prices without persisting, and an apply phase that persists only after an explicit user confirmation POST. Preview MUST NOT mutate `catalogo_articulo_precios` or `articulos.pvp`.

#### Scenario: Preview does not persist

- GIVEN filters that match 3 articles
- WHEN the preview is generated
- THEN the response shows the 3 articles with current and prospective prices and zero rows change in `catalogo_articulo_precios`

#### Scenario: Apply persists previewed result

- GIVEN a preview of 3 affected articles
- WHEN the user confirms apply via POST
- THEN exactly the previewed price rows are updated and a resumen with the count of updated rows is shown

### Requirement: Filter criteria (R-BU-002)

The update MUST accept filters: target price list (`codlista`), family (`codfamilia`), include-subfamilies flag resolved recursively via the existing `familia` hierarchy (`madre`/`hijas()`/`aux_all()` recursion — no model change), and article group. Multiple filters MUST combine with AND. When include-subfamilies is off, only direct members of the family are affected.

#### Scenario: Recursive subfamily inclusion

- GIVEN family F with child families F1 (containing articles A1) and F2 (containing A2), and article A3 directly in F
- WHEN the preview runs for F with include-subfamilies on
- THEN A1, A2 and A3 are all affected

#### Scenario: Non-recursive limit

- GIVEN the same hierarchy
- WHEN the preview runs for F with include-subfamilies off
- THEN only A3 is affected

#### Scenario: List filter scopes target rows

- GIVEN article A with rows on lists L1 and L2
- WHEN the update applies to list L2 with +10%
- THEN only the A/L2 row changes; L1 and `articulos.pvp` remain untouched

### Requirement: Price formula (R-BU-003)

The prospective price MUST be computed as `round(p * (1 + pct/100), 2)` where `p` is the current effective price (per-list row when present, otherwise the default-list fallback) and `pct` is the percentage. The result MUST round half-up to 2 decimals.

#### Scenario: Percentage increase rounds to 2 decimals

- GIVEN an effective price of 10.005 and pct 10
- WHEN the formula is applied
- THEN the prospective price is exactly 11.01 (rounded to 2 decimals)

#### Scenario: Negative percentage decrease

- GIVEN an effective price of 10.00 and pct -25
- WHEN the formula is applied
- THEN the prospective price is 7.50

### Requirement: CSRF-gated mutation (R-BU-004)

Both preview and apply MUST be POST requests validated with CSRF (`isCsrfValid()`). An invalid token MUST reject the operation with no persistence. Non-admin users MUST be denied by the admin gate.

#### Scenario: Missing CSRF token blocks apply

- GIVEN an apply POST without a valid CSRF token
- WHEN the controller processes it
- THEN the operation is rejected and no price row changes

#### Scenario: Non-admin denied

- GIVEN a non-admin user POSTing an apply request
- WHEN the controller processes it
- THEN access is denied

### Requirement: Resumen and flash messages (R-BU-005)

After apply, the system MUST render a resumen with the count of updated rows and list of affected articles, and MUST report success/failure via the plugin flash message system (`new_message` / `new_error_msg`).

#### Scenario: Resumen after successful apply

- GIVEN an apply that updated 3 rows
- WHEN the page re-renders
- THEN the resumen shows "3 rows updated" and the affected article list

#### Scenario: Empty selection message

- GIVEN filters that match zero articles
- WHEN apply is confirmed
- THEN a warning message states nothing was updated and zero rows change
