# Spec: articulos-excel-access-settings

Scope: exactly the requirements below (R-CEXC-002, R-CEXC-006, settings UI), delivered by change `catalogo-excel-access-control`. Companion delta in the same change: `specs/articulos-excel-import-export/spec.md` — it consumes this capability's policy verdict at every import/export entry point.

## 1. Purpose

New capability of `catalogo_core`: admin-only configuration of which **user roles** (`fs_roles`) may additionally import/export the article catalog Excel. Persisted as setting `catalogo_excel_roles` (comma-separated `codrol` list) via `fs_settings` get/set over `$GLOBALS['config2']` (INI-backed `tmp/{FS_TMP_NAME}config2.ini`). No new permission tables; no per-user grants.

Hard constraints:

- **Independence**: this change MUST NOT depend on the sibling tarifario UI-fix change (rows_ref re-render, delete false success). No shared artifacts, no ordering constraint.
- **Naming hazard**: nothing introduced by this change may be named after "grupos de cliente" / customer groups (`gruposclientes`, clientes_core, is an unrelated concept). All new identifiers use role vocabulary (`rol`, `role`) only.

## 2. Test-scope conventions

Strict TDD active (PHPUnit 11, DDEV). Every scenario below is automatable.

| Tag | Suite |
|---|---|
| `host-suite` | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` |
| `root-suite` | `ddev exec php vendor/bin/phpunit` (auto-discovers plugin tests; a green root run is the cross-plugin regression signal) |

## 3. Requirements

### Requirement: Admin-controlled role grant setting (R-CEXC-002)

The system MUST provide an admin-only setting `catalogo_excel_roles` whose value is a comma-separated list of role codes (`fs_roles.codrol`) granting non-admin users catalog Excel import/export rights. It MUST be persisted via the existing `fs_settings` get/set over `$GLOBALS['config2']`; MUST NOT create new tables; and the granted list MUST be validated against existing `fs_roles` rows at evaluation time.

#### Scenario: Admin persists a role list

- GIVEN an admin on the settings page
- WHEN they save role codes `A,B`
- THEN `catalogo_excel_roles` reads back as the list `A,B`
- Test scope: `host-suite`

#### Scenario: Absent setting grants no roles

- GIVEN `catalogo_excel_roles` is absent (fresh install)
- WHEN the grant list is evaluated for any non-admin user
- THEN zero roles are granted (fail-closed default; admin-only access per R-CEXC-001 in the companion delta)
- Test scope: `host-suite`

#### Scenario: Invalid or unparsable setting value grants no roles

- GIVEN `catalogo_excel_roles` holds a value that is not a parseable comma-separated list
- WHEN the grant list is evaluated
- THEN zero roles are granted (fail-closed) and evaluation does not error
- Test scope: `host-suite`

#### Scenario: Naming discipline (grep-auditable)

- GIVEN the files introduced by this change
- WHEN `grep -riE 'grupocliente|grupo_clientes|customer[_ ]?group' <new files>` runs
- THEN it returns zero hits
- Test scope: `host-suite`

### Requirement: Grant evaluation semantics (R-CEXC-006)

Grants MUST be evaluated per request: (a) a granted role deleted from `fs_roles` afterwards is treated as **not granted** (grant list intersected with existing `fs_roles`); (b) admin users are allowed through the admin path regardless of any grant (admin dominance); (c) a non-admin user whose roles intersect the validated granted list is granted, otherwise not. Any caching MUST be per-request only (no stale grants across requests).

#### Scenario: Granted role later deleted

- GIVEN role `B` granted and non-admin user U holding `B`
- WHEN role `B` is deleted from `fs_roles` and U issues the next request
- THEN U evaluates as not granted
- Test scope: `host-suite`

#### Scenario: Admin dominance

- GIVEN an admin user whose role also appears in the granted list
- WHEN the policy evaluates the user
- THEN the admin path dominates and access is allowed independent of grant state
- Test scope: `host-suite`

#### Scenario: Non-admin with granted role

- GIVEN role `A` granted and non-admin user U holding `A`
- WHEN the policy evaluates U
- THEN U is granted
- Test scope: `host-suite`

#### Scenario: No cross-request staleness

- GIVEN a grant or revocation change between two HTTP requests
- WHEN each request evaluates the policy
- THEN each reflects the setting and role state at its own evaluation time
- Test scope: `host-suite`

### Requirement: Admin-only settings page

The system MUST expose a dedicated settings page `catalogo_excel_settings` for this setting, following the tpvmod precedent (`plugins/tpvmod/controller/tpvmod_settings.php`): framework-level admin gate (`parent::__construct(__CLASS__, ..., 'admin', TRUE, TRUE)`), CSRF-protected POST, input whitelist (only the `catalogo_excel_roles` field accepted), and set+save persistence. The page MUST NOT be reachable or writable by non-admin users.

#### Scenario: Admin can save the setting

- GIVEN an admin user
- WHEN they POST a role list to `catalogo_excel_settings` with a valid CSRF token
- THEN the setting is persisted and a success response is returned
- Test scope: `host-suite`

#### Scenario: Non-admin cannot save the setting

- GIVEN a non-admin user (with or without an Excel grant)
- WHEN they request the page or POST the setting
- THEN the framework admin gate denies access (no page render, no persistence)
- Test scope: `host-suite`

#### Scenario: Input whitelist

- GIVEN a POST to the settings page
- WHEN the request contains fields other than the whitelisted setting field
- THEN they are ignored; only `catalogo_excel_roles` is persisted
- Test scope: `host-suite`

#### Scenario: CSRF-protected mutation

- GIVEN a POST without a valid CSRF token
- WHEN it reaches the settings page
- THEN the save is rejected and nothing is persisted
- Test scope: `host-suite`
