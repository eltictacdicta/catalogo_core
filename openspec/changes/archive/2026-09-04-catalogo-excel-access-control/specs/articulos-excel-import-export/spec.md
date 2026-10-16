# Delta for articulos-excel-import-export

Change `catalogo-excel-access-control`. Modifies the canonical spec `plugins/catalogo_core/openspec/specs/articulos-excel-import-export/spec.md`. Companion new capability in the same change: `specs/articulos-excel-access-settings/spec.md` — this delta consumes its policy verdict.

Conventions: English per change instruction; requirement IDs per proposal preview; delta keeps the canonical header names so archive merge is unambiguous. Independence from the sibling tarifario UI-fix change is preserved: no requirement here depends on it.

Test-scope: Strict TDD active (PHPUnit 11, DDEV). `host-suite` = `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`; `root-suite` = `ddev exec php vendor/bin/phpunit` (auto-discovers the plugin tests; green root run is the cross-plugin regression signal).

## MODIFIED Requirements

### Requirement: Permiso can_import_export (R-CEXC-001, R-CEXC-004)

The page `ventas_articulos` MUST expose import/export only when `can_import_export === true`, and `can_import_export` MUST be redefined as the **central access policy verdict**: `true` for admin users; otherwise `true` only if the user holds a role granted via the `articulos-excel-access-settings` capability (setting `catalogo_excel_roles`). **Default (no setting configured): admin-only**, for BOTH import and export directions. (Previously: `admin || have_access_to('ventas_articulos')` — tautological, since the page itself requires that access.)

The policy MUST be enforced at EVERY entry point below, and unauthorized attempts MUST get an explicit denied response (403 or redirect — never a silent no-op or fall-through):

| Entry point | Gate today |
|---|---|
| Embedded actions via `VentasArticulos::processExcelAction()` — `preview_excel`, `get_preview`, `export_excel`, `export_excel_filtered`, `export_excel_template`, `download_import_log`, `excel_import_sse` | Tautological `can_import_export` |
| Export service paths (`exportExcel()` / `exportExcelTemplate()`) | Reachable only via gated actions today |
| Import-log download (`download_import_log`) | Tautological |
| Embedded SSE stream (`excel_import_sse`) | Tautological at stream start |
| Standalone direct-URL endpoint `process_excel_wizard.php` → `catalogo_excel_wizard_run(false)` (`process_excel_wizard_dispatch.php`; actions `start`, `progress`, `status`) | Login + CSRF-on-`start` only — **the hole**: any logged-in user can run imports by direct URL |

The standalone endpoint MUST apply the same policy fail-closed for all its actions, answering a proper 403/denied response instead of falling through to wizard handling. Twig UI gates (`{% if %}` blocks keyed on `can_import_export`) MUST match the server-side checks (see R-CEXC-007).

#### Scenario: Default-deny for non-admin with no setting

- GIVEN no `catalogo_excel_roles` setting configured and a logged-in non-admin user
- WHEN the user invokes ANY entry point in the table above
- THEN each returns an explicit denied response (403/redirect, never silent) and no import/export executes
- Test scope: `host-suite`

#### Scenario: Granted-role user allowed

- GIVEN a role granted via `catalogo_excel_roles` and a non-admin user holding that role
- WHEN the user invokes each entry point
- THEN each proceeds with the pre-change import/export behavior
- Test scope: `host-suite`

#### Scenario: Admin allowed everywhere

- GIVEN an admin user and no setting configured
- WHEN the admin invokes each entry point (embedded actions, exports, log download, embedded SSE, standalone `start`/`progress`/`status`)
- THEN all proceed
- Test scope: `host-suite`

#### Scenario: Grant revoked mid-session

- GIVEN a granted non-admin with a live session whose grant is removed from `catalogo_excel_roles` (or whose granted role is deleted from `fs_roles`)
- WHEN their NEXT request reaches any entry point
- THEN it is denied
- Test scope: `host-suite`

#### Scenario: Standalone endpoint 403 for non-granted direct URL

- GIVEN a logged-in non-admin not covered by the policy
- WHEN `process_excel_wizard.php?action=start` (or `progress`, `status`) is called by direct URL with a valid session and — for `start` — a valid CSRF token
- THEN the endpoint responds 403 JSON with a clear denied error and never reaches wizard handling
- Test scope: `host-suite`

### Requirement: Persistencia (R-CEXC-003)

- Precio Excel → campo `pvp` (sin IVA).
- Crear: defaults del modelo `articulo` para campos no mapeados.
- Actualizar: solo campos mapeados con valor no vacío.
- Per-row permission filter: for a non-admin caller, EVERY imported row whose mapping writes `pvp` (create or update path) MUST dispatch the host's own `ArticlePermissionFilterEvent` (frozen name `catalogo_core.article_permission_filter`, carrying row `referencia`, user `nick`, and the gated action; the import action constant is an sdd-design decision alongside the frozen `ACTION_EDIT_ARTICLE`) **before** the `pvp` mutation/save. Denied rows are SKIPPED (not fatal): the row counts as discarded and the listener-provided `getDenialReason()` is written to the discarded-rows CSV (`catalogo_import_*_descartadas.csv`). The existing fail-closed listener-exception semantics apply per row: a listener throwing while evaluating a row denies that row only; the import continues. Admin callers' rows pass by the admin path of their own permission semantics (never denied by this filter).

(Previously: `pvp` writes persisted unconditionally with zero permission-filter dispatch — a granted (or, pre-change, any) user could rewrite prices the per-row filter would deny on the article page.)

#### Scenario: Denied row skipped with reason in import log

- GIVEN a non-admin importer whose mapping includes `pvp` and a listener denying row R with reason `X`
- WHEN the import loop processes R
- THEN R is skipped (no create/update, no save for R), the import continues with remaining rows, and R appears in the discarded CSV with reason `X`
- Test scope: `host-suite`

#### Scenario: Filter fires before pvp mutation and save

- GIVEN a `pvp`-mapped row processed by a non-admin importer
- WHEN the row is processed
- THEN the event is dispatched before any `pvp` mutation and before `save()`, and a denial makes the row's save unreachable
- Test scope: `host-suite`

#### Scenario: Allowed row applies pvp

- GIVEN no listener denies
- WHEN a `pvp`-mapped row is processed by a non-admin importer
- THEN the row applies exactly as in the pre-change behavior
- Test scope: `host-suite`

#### Scenario: Admin rows pass by admin path

- GIVEN an admin importer
- WHEN `pvp`-mapped rows are processed
- THEN no row is denied by the per-row filter (admin path dominates), regardless of listeners
- Test scope: `host-suite`

#### Scenario: Listener exception fails closed per row

- GIVEN a listener throwing while evaluating row R
- WHEN R is processed by a non-admin importer
- THEN R is treated as denied (skipped + reported with the fail-closed reason) and remaining rows continue
- Test scope: `host-suite`

## ADDED Requirements

### Requirement: SSE authorization re-check per stream event (R-CEXC-005)

The embedded SSE import stream (`excel_import_sse`) and the standalone SSE flow MUST re-check the access policy per stream event — at minimum at stream start AND per subsequent event — so a long-running stream cannot outlive a revocation. A failed re-check MUST terminate the stream with a clear denial. Per-request caching of the evaluated role set is permitted; cross-request staleness is not.

#### Scenario: Denial at stream start

- GIVEN a non-granted user
- WHEN the SSE stream starts
- THEN it is denied before any import event is emitted
- Test scope: `host-suite`

#### Scenario: Revocation mid-stream

- GIVEN a granted user with a stream in progress whose grant is revoked
- WHEN the next stream event's re-check runs
- THEN the stream terminates with a clear denial and no further import processing occurs
- Test scope: `host-suite`

### Requirement: UI gates reflect the policy (R-CEXC-007)

The Twig templates of `ventas_articulos` (Excel menu and the export/import modals, keyed on `can_import_export`) MUST reflect the real policy: hidden exactly when the server-side policy denies, visible when it grants. Template gates and server gates MUST never disagree.

#### Scenario: Hidden when denied

- GIVEN a user the policy denies
- WHEN the list page renders
- THEN the Excel import/export menu and modals are not rendered (template gate agrees with server gate)
- Test scope: `host-suite`

#### Scenario: Visible when granted

- GIVEN a granted or admin user
- WHEN the list page renders
- THEN the Excel menu and modals render
- Test scope: `host-suite`
