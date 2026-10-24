# Delta: multi-tariff-pricing — divisa desde el catálogo

## MODIFIED Requirements

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
