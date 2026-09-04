# Delta for catalog-options-store

## ADDED Requirements

### Requirement: Namespaced options store (R-CO-001)

The system MUST provide `Services/CatalogoOptions.php`, a central options store backed by namespaced `fs_settings` keys (all keys prefixed, e.g. `catalogo_core.*`) persisted via the existing fs_settings get/set over `$GLOBALS['config2']`. All catalog option reads MUST go through this store (single read path).

#### Scenario: Flag round-trip

- GIVEN an admin saves the multi-tariff flag as enabled
- WHEN `CatalogoOptions` reads the flag in a new request
- THEN it returns enabled

#### Scenario: Absent options yield safe defaults

- GIVEN a fresh installation with no option keys
- WHEN `CatalogoOptions` reads each option
- THEN multi-tariff is disabled and the role-groups master setting is disabled (safe defaults)

### Requirement: Absorbs catalogo_excel_roles (R-CO-002)

`CatalogoOptions` MUST absorb the existing `catalogo_excel_roles` setting (comma-separated role list) as one of its managed keys, preserving its value and semantics.

#### Scenario: Existing value readable through the new store

- GIVEN `catalogo_excel_roles = "A,B"` persisted by the legacy page
- WHEN `CatalogoOptions` reads the Excel-granted roles
- THEN it returns the list `A,B`

#### Scenario: Write through new store is readable by legacy path

- GIVEN the options page saves role list `C`
- WHEN the legacy reader of `catalogo_excel_roles` evaluates
- THEN it sees `C` (single source of truth, no dual write divergence)

### Requirement: New flags (R-CO-003)

The store MUST manage at least: multi-tariff pricing on/off, role-groups master setting on/off, and the Excel granted-roles list. Each flag MUST be a plain boolean or comma list persisted as scalar strings.

#### Scenario: Multi-tariff off hides tariff UI

- GIVEN multi-tariff flag off
- WHEN admin pages render
- THEN price-list CRUD and per-article editors are not offered

#### Scenario: Role-groups master off

- GIVEN the role-groups master setting off
- WHEN gating evaluates
- THEN role-group behavior is provably inert (see role-based-price-access spec)

### Requirement: General options page replaces catalogo_excel_settings (R-CO-004)

The plugin MUST expose an admin-only `opciones_catalogo` page that edits all store-managed options in one form (input whitelist, CSRF-protected POST, admin gate). The `catalogo_excel_settings` page and its controller MUST be REMOVED, with its capability migrated to `opciones_catalogo`.

#### Scenario: Old page removed

- GIVEN the change is applied
- WHEN the codebase is inspected
- THEN the `catalogo_excel_settings` controller/view is gone and `opciones_catalogo` handles the Excel role setting

#### Scenario: Admin edits all options in one form

- GIVEN an admin POSTing the options form with CSRF token
- WHEN the controller processes it
- THEN all whitelisted option fields persist and a success message is shown

### Requirement: Backward-compat shim in ArticleExcelAccessPolicy (R-CO-005)

`ArticleExcelAccessPolicy` MUST read the granted roles through `CatalogoOptions` and, when the new store has no value, fall back to the legacy `catalogo_excel_roles` key (shim). Fail-closed semantics (absent/unparsable grants zero roles), admin dominance, and per-request evaluation MUST be preserved unchanged.

#### Scenario: Shim falls back to legacy key

- GIVEN the new store key is absent but legacy `catalogo_excel_roles = "A"` exists
- WHEN the policy evaluates a non-admin user holding role A
- THEN the user is allowed

#### Scenario: Regression — new store wins over legacy

- GIVEN the new store grants `B` and legacy grants `A`
- WHEN the policy evaluates a user holding B but not A
- THEN the user is allowed (new store is authoritative)
