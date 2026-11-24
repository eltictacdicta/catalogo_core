# Delta: catalog-options-store

## MODIFIED Requirements

### Requirement: New flags (R-CO-003)

The store MUST manage at least: the role-groups master setting on/off and the
Excel granted-roles list. Each option MUST be a plain boolean or comma list
persisted as scalar strings. The multi-tariff flag
(`catalogo_core.multi_tariff`) is retired with the price-list UI it gated
(R-MT-009): the store MUST NOT expose it, and `saveAll()` MUST drop a stale
value from `config2.ini`.

#### Scenario: Retired flag is gone

- GIVEN `CatalogoOptions`
- WHEN its API is inspected
- THEN there is no `KEY_MULTI_TARIFF`, `multiTariffEnabled()` or `setMultiTariff()`

#### Scenario: Stale value dropped on save

- GIVEN `config2` holds `catalogo_core.multi_tariff = 'true'`
- WHEN the options page is saved
- THEN the key is no longer present

#### Scenario: Role-groups master off

- GIVEN the role-groups master setting off
- WHEN gating evaluates
- THEN role-group behavior is provably inert (see role-based-price-access spec)

### Requirement: General options page replaces catalogo_excel_settings (R-CO-004)

The plugin MUST expose an `opciones_catalogo` page in the `catalogo` menu,
gated by `#[AdminOnly]` (the obsolete fourth constructor argument is not a
gate), that edits all store-managed options in one form (input whitelist
`groups_enabled` and `catalogo_excel_roles`, CSRF-protected POST). The
`catalogo_excel_settings` page and its controller MUST stay REMOVED.

#### Scenario: Admin edits all options in one form

- GIVEN an admin POSTing the options form with CSRF token
- WHEN the controller processes it
- THEN both whitelisted option fields persist and a success message is shown

#### Scenario: Page lives in the catalog menu

- GIVEN the menu of an administrator
- WHEN the catalog submenu is rendered
- THEN "Opciones de catálogo" appears there and not under Administración
