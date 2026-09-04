# Proposal: Catalogo Excel Access Control (admin-only default, role-based grants)

## Intent

Bulk price rewrite of the article catalog via the Excel import/export wizard is
currently reachable by **any logged-in user who can open `ventas_articulos`** —
and by direct URL through the standalone SSE endpoint. Product decision (owner
approved): **only administrators may import/export by default**, with an
admin-controlled setting granting additional **user roles** (`fs_roles`)
import/export rights. This change closes both the tautological gate and the
direct-URL hole, and enforces the per-row permission filter
(`ArticlePermissionFilterEvent`) on imported `pvp` writes — closing review
finding RISK-1 (CRITICAL) frozen as follow-up from slice 1.

### Business Problem
- `VentasArticulos.php:111-114` gate is tautological: `admin || have_access_to('ventas_articulos')` — the page itself requires that access, so the check is always true.
- **Security hole**: standalone `process_excel_wizard.php` → `catalogo_excel_wizard_run()` (`process_excel_wizard_dispatch.php:59-77`) requires only login + CSRF-on-start; ANY logged-in user can run imports by direct URL (no embedded-mode `can_import_export` re-check).
- Import path (`catalogoApplyWizardFields` → `ArticuloExcelRowUpdater::applyMappedFields` case `pvp`, `Services/ArticuloExcelRowUpdater.php:171-175` → `$art->save()`) dispatches **zero** permission-filter events: a non-admin granted (or un-granted, today) user can rewrite prices the per-row filter would deny on the article page.

### Target Users
- **Administrators**: full import/export, plus configuration of role grants.
- **Granted non-admin users** (via role): import/export, subject to the per-row filter on `pvp` writes.

### Business Rules
1. Default policy: admin-only, both directions (import and export).
2. Grant mechanism: admin setting selecting which roles may also import/export. No new permission tables, no per-user grants.
3. Non-admin granted importers remain subject to the per-row permission filter: every imported row that writes `pvp` dispatches `ArticlePermissionFilterEvent`; denied rows are **skipped** and reported in the import log with the denial reason.
4. Export is gated **equally** — data exfiltration matters even without writes.
5. Naming discipline: nothing is named after "grupos de cliente" — customer groups (`gruposclientes`, clientes_core) are unrelated; the mechanism is USER ROLES.

### Product Outcome
Admins control who rewrites and downloads catalog prices; granted users get the same UX as today but every `pvp` write during import respects the same per-row permission policy as the article page; denied rows are visible in the import log instead of silently skipped or wrongly applied.

### Current-State Gap
Policy exists only as UI gating (`View/ventas_articulos.html.twig:38,190`, `View/partials/articulos/modal_exportar_excel.html.twig:22,25,28`); the embedded controller funnels actions through `processExcelAction()` (`VentasArticulos.php:135-183`), exports `exportExcel()`/`exportExcelTemplate()` (308-325), log download (327-345), SSE `handleExcelImportSse()` (197-206) — all behind the tautological flag; the standalone dispatch bypasses even that.

## Scope

### In Scope
- Central access policy (admin OR granted-role) applied to: embedded controller actions, exports, import-log download, embedded SSE, and the standalone dispatch endpoint.
- Admin setting `catalogo_excel_roles` (comma-separated `codrol` list) persisted via `base/fs_settings.php` get/set over `$GLOBALS['config2']` (INI-backed `tmp/{FS_TMP_NAME}config2.ini`).
- Settings UI: new admin-only page `catalogo_excel_settings` following the tpvmod precedent (`plugins/tpvmod/controller/tpvmod_settings.php`: `parent::__construct(__CLASS__, ..., 'admin', TRUE, TRUE)` = framework-level admin gate, CSRF, whitelist input, set+save). **Justification**: `ventas_articulos` is a catalog list page — burying security config there is surprising and only reachable through list UX; a dedicated admin page matches the existing framework precedent and keeps the setting discoverable in the admin menu. Tradeoff: one extra menu entry vs. discoverability and a single obvious home for the policy.
- Per-row filter dispatch in the import row loop for `pvp` writes; denied rows skipped + reason written to the existing discarded-rows CSV (`catalogo_import_*_descartadas.csv`).
- Authorization re-check per SSE stream event (a long stream must not outlive a revocation).
- Role-list evaluation semantics (see Edge Cases).
- Unit tests for the policy, the dispatch endpoint gate, and the per-row denial path.

### Out of Scope / Non-Goals
- Tarifario UI fixes (rows_ref re-render bug, delete false success) — separate sibling change; **this change must not depend on it**.
- No new permission tables; no per-user grants (roles only).
- No changes to `ArticlePermissionFilterEvent` contract (default-allow, fail-closed on listener exception, `getDenialReason()`); reuse as-is, possibly adding an import action constant alongside the frozen `ACTION_EDIT_ARTICLE` (decision at spec/design).
- No changes to role management UI (`admin_rol.php` editor, `admin_user.php` roles tab).

## Capabilities

### New Capabilities
- `articulos-excel-access-settings`: admin configuration page + setting persistence for granted roles.

### Modified Capabilities
- `articulos-excel-import-export`: `Requirement: Permiso can_import_export` redefined (admin-only default + role grants enforced at every entry point, including standalone dispatch); `Requirement: Persistencia` gains per-row filter dispatch on `pvp` writes with denial logging.

## Requirements Preview

IDs: `R-CEXC-NNN` (new prefix for this change; the existing spec uses plain `### Requirement:` headers without IDs — delta specs will keep that header style with the ID as prefix).

- **R-CEXC-001** Default policy: only administrators import/export (both directions).
- **R-CEXC-002** Admin setting selects granted roles (`fs_roles`); no new tables.
- **R-CEXC-003** Per-row filter on `pvp` writes during import for non-admin users; denied rows skipped with reason in import log.
- **R-CEXC-004** All entry points enforce the policy (embedded actions, exports, log download, SSE, standalone dispatch).
- **R-CEXC-005** SSE re-checks authorization per stream event.
- **R-CEXC-006** Edge-case semantics (deleted role, admin dominance, absent setting).
- **R-CEXC-007** UI gates reflect the policy (Excel menu, export/import modals hidden when denied).

## Approach

1. Single policy source (e.g. `Services/ArticleExcelAccessPolicy.php`) evaluating `user->admin` OR `user's roles ∩ setting roles`; consumed by `VentasArticulos` (replacing `initImportExportPermissions()`) and by `catalogo_excel_wizard_run()` in non-embedded mode — one rule, no drift.
2. Setting via `fs_settings` (scalar, INI-safe: comma-separated `codrol`); validated against existing `fs_roles` rows at evaluation time.
3. Import loop: dispatch `ArticlePermissionFilterEvent` before persisting any row whose mapping includes `pvp`; on deny, skip row, append reason to discarded-rows CSV.
4. Twig: gates already key off `can_import_export`; they now reflect the real policy with no template changes expected beyond none/minor.

## Affected Areas (changed-lines forecast vs 400-line budget)

| Area | Impact | Est. lines |
|------|--------|-----------|
| `Services/ArticleExcelAccessPolicy.php` (new) | New | ~70 |
| `Controller/VentasArticulos.php` (gate + SSE re-check) | Modified | ~30 |
| `process_excel_wizard_dispatch.php` (standalone auth check) | Modified | ~25 |
| Import loop + import-log denial reporting (`Services/ArticuloExcel*`) | Modified | ~100 |
| Settings page controller + view + tests | New | ~180 |
| Twig gates | Modified | ~0-10 |

**Total ~400-415: borderline.** If apply exceeds budget, split into two work units within the change: (1) policy + all gates + per-row dispatch, (2) settings UI. Prefer one PR if ≤400.

## Edge Cases

- Role deleted after being granted → treated as **not granted** (grant list intersected with existing `fs_roles`).
- Role granted that includes an admin user → admin path dominates (always allowed).
- Setting absent/unparsable → default admin-only.
- SSE flow → authorization re-checked per stream event, not only at start.
- Direct-URL standalone endpoint → same policy enforced; this is **in scope**, not a risk.

## Tradeoffs

- Role-granularity (not per-user) is coarser but matches the framework's existing role-based access model and avoids new tables.
- Per-event SSE re-check adds per-row session/role lookups; mitigate with per-request caching of the role set (single query), accepting staleness within one request only.
- Denial reasons in the import log expose listener-provided text to the importing user; acceptable — the log covers rows from the user's own import, and the log download remains policy-gated.

## Risks

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Standalone endpoint forgotten during apply → bypass persists | Low (explicitly in scope, own task + test) | Dedicated requirement R-CEXC-004 + endpoint test |
| SSE stream outlives revocation without per-event check | Medium | R-CEXC-005 per-event re-check |
| Role-set caching returns stale grants across requests | Low | Per-request cache only; re-evaluate each request |
| Filter dispatch inside row loop degrades large imports | Medium | Single cached policy evaluation; dispatch is cheap (default-allow, zero listeners) |
| Import-log denial reasons leak internal details | Low | Reasons are user-facing strings by contract (`getDenialReason()`); log remains policy-gated |

## Rollback Plan

Revert the change commits. No schema changes → no data rollback. Reverting restores the previous tautological gate and dispatch behavior; the `catalogo_excel_roles` setting key may remain in `config2.ini` harmlessly or be deleted. Slice-1 receipts (`tarifario-catalogo-hook-integration`, review lineage `review-7f8e126ee8a89495`) are untouched.

## Dependencies & Coordination

- **Sequencing**: starts after slice 1 (`plugins/tarifario/openspec/changes/tarifario-catalogo-hook-integration`) verifies and archives; slice-1 receipts stay intact. This change is a **new review target** with its own lifecycle.
- **Sibling change**: tarifario review-backlog UI fixes (rows_ref re-render, delete false success) are independent — no shared artifacts, no ordering constraint.
- **Verified facts relied upon**: `fs_roles` table via `model/fs_rol.php:35` (codrol, descripcion); `fs_user::$admin` (`model/core/fs_user.php:81`); `have_access_to()` (`model/core/fs_user.php:390`); per-row filter only dispatched today in `VentasArticulo::editarArticulo()` (~208-217).

## Success Criteria

- [ ] Non-admin, non-granted user: every import/export entry point returns 403/redirect, including direct-URL standalone dispatch.
- [ ] Granted-role non-admin: can import/export; `pvp` rows denied by a listener are skipped with reason in the import log.
- [ ] Admin: full access + can grant/revoke roles from `catalogo_excel_settings`.
- [ ] Absent setting ⇒ admin-only (fresh install safe by default).
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` green, including new policy/endpoint/denial tests.
