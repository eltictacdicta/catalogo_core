# Proposal: Quick-Create Article Form Price Gate (ventas-articulos-quick-create-gate)

## Intent

The article **list page** quick-create form (`POST nreferencia`) writes `pvp`
with **no permission gate and no `ArticlePermissionFilterEvent` dispatch** —
only page access is required. A non-granted (or granted-but-unassigned) editor
can set article prices through `VentasArticulos::nuevoArticulo()` even though
the same price write on the article page (`editarArticulo`) and on every Excel
import row is already gated by the frozen neutral permission contract. This
change closes review finding **RISK-2** (WARNING, frozen in `verify-report.md`
§6 of the archived `2026-09-04-catalogo-excel-access-control` change) by
routing the quick-create `pvp` write through the same contract, and closes the
**R-TAR-HOOK-003 deferral** for this specific path: slice-1's spec
(`plugins/tarifario/openspec/specs/tarifario/catalogo-integration/spec.md:162`)
and design AD-2 explicitly deferred `ventas_articulos` and stated "a future
slice can dispatch the same event there — the event/action design allows it".

### Business Problem
- `VentasArticulos.php:101` reaches `nuevoArticulo()` on any POST carrying
  `nreferencia`; `:344` reads `$pvp = (float) $request->request->get('npvp', 0);`,
  `:357` assigns `$art->pvp = $pvp;`, `:363` persists via `$art->save()`.
- No `ArticlePermissionFilterEvent` dispatch on this path; the Excel policy
  (`ArticleExcelAccessPolicy` / `EXCEL_ACTIONS`, `VentasArticulos.php:71-77`)
  covers only the Excel entry points, not the quick-create form.
- Result: a non-admin editor who cannot set prices anywhere else can set them
  here — a single-row, no-bulk vector that bypasses every other gate.

### Target Users and Situations
- **Editors** (tarifario `editor` role): quick-create a new article with a
  price from the list page — currently allowed for any page-access user;
  after the change, denied unless granted (see matrix).
- **Gestores**: any-tarifa gestor keeps full quick-create-with-price.
- **Admins**: unchanged, always allowed.
- **Deployments without tarifario**: unchanged (zero listeners ⇒ default allow).

### Business Rules (permission matrix on quick-create `pvp` write)

| User / situation | Verdict | Mechanism |
|---|---|---|
| admin | **allow** | skip dispatch entirely (admin-dominance precedent, `ArticuloExcelPermissionGate` AD-5) |
| gestor on any active tarifa | allow | dispatch → tarifario listener resolves gestor ⇒ allow |
| editor (new article cannot be assigned yet) | **deny** | listener: no `tarif_grupo_articulos` row can exist for a not-yet-created article ⇒ deny with reason |
| non-granted (no role in any tarifa) | **deny** | listener: `sin permisos de edición` |
| listener throws | **deny (fail-closed)** | host catch ⇒ generic `permission filter error`, no `error_log` (processIsolation ban) |
| zero listeners (tarifario inactive) | allow | default-allow host neutrality |

Denied ⇒ **no save and no article creation** (block whole create — see
Tradeoffs), plus a user-facing `new_error_msg` carrying the denial reason
(mirrors `VentasArticulo.php:222`).

### Product Outcome
Every `pvp` write on an article — page edit, Excel import row, and now
quick-create — flows through the same neutral contract. Non-granted users get
an explicit denial message instead of silently persisting prices; admins and
gestores see zero behavior change.

### Current-State Gap
The article-page path dispatches before mutation (`VentasArticulo.php:208-225`:
event → try/catch Throwable → deny → message → return before `pvp`/save); the
Excel per-row gate skips dispatch for admins and gates `pvp`-mapped rows
(`ArticuloExcelPermissionGate.php:45-93`, wired at
`process_excel_wizard_dispatch.php:361` before create/update). Quick-create is
the **only** remaining un-gated `pvp` write surface in `catalogo_core`.

## Scope

### In Scope
- Dispatch `ArticlePermissionFilterEvent` (frozen `ACTION_EDIT_ARTICLE`) in
  `VentasArticulos::nuevoArticulo()` for non-admin users, **before** the `pvp`
  mutation and `save()`; admin skips dispatch (AD-5 precedent).
- Deny ⇒ no create, `new_error_msg` with `getDenialReason()` (existing message
  convention; Spanish, matching the controller's language).
- Fail-closed catch (`\Throwable`) with generic reason, **no `error_log`**
  (`phpunit.xml` runs `processIsolation=true`; per `ArticuloExcelPermissionGate`
  precedent this surfaces as a test error).
- Behavioral unit tests (STRICT TDD, RED → GREEN) driving a real listener
  through the dispatch — no source-string pins (REL-2 lesson from the archived
  verify-report).

### Out of Scope / Non-Goals
- **List-page EDIT of existing articles' `pvp`** (any future inline-edit
  surface) remains deferred — this change gates only the quick-create form.
- No UI changes beyond the denial message; no JS, no template edits.
- No changes to the frozen event contract; **no new constants**, no new
  permission tables, no per-user grants.
- No changes to the Excel policy/gate; quick-create is NOT the import path —
  the two gates stay orthogonal.
- No tarifario-side changes (the listener is already action-independent and
  inert until registered; no change needed).
- Gating article creation in other plugins (tpvmod, facturacion_base) — out of
  `catalogo_core` ownership.

## Capabilities

### New Capabilities
- `articulos-quick-create-permission`: gates the quick-create `pvp` write via
  the neutral permission contract (admin skip-dispatch, non-admin dispatch,
  deny ⇒ no create, fail-closed, host-neutral default).

### Modified Capabilities
- None at spec level. `articulos-excel-access-settings` /
  `articulos-excel-import-export` are untouched; the deferred hook lives in the
  tarifario spec (`catalogo-integration`, R-TAR-HOOK-003) which is not modified
  here.

## Requirements Preview (IDs follow the plugin's R-CEXC / R-TAR-HOOK style)

- **R-QCRT-001** Quick-create `pvp` write gated for non-admin: dispatch
  `ArticlePermissionFilterEvent` with the frozen `ACTION_EDIT_ARTICLE` and the
  current user's `nick` before any `pvp` mutation.
- **R-QCRT-002** Admin dominance: admins skip the dispatch entirely
  (`ArticuloExcelPermissionGate` AD-5 precedent); outcome never depends on
  listener correctness.
- **R-QCRT-003** Deny ⇒ **no save, no article creation** (block whole create),
  user-facing `new_error_msg` including `getDenialReason()`; the list still
  renders with the message (form re-render unchanged).
- **R-QCRT-004** Fail-closed: listener `\Throwable` ⇒ deny with generic
  `permission filter error`; no `error_log` on this path (processIsolation).
- **R-QCRT-005** Host neutrality: zero listeners ⇒ default allow; quick-create
  behavior unchanged in deployments without tarifario.
- **R-QCRT-006** CSRF unchanged: the form already renders `{{ csrf_field() }}`
  (`View/partials/articulos/modal_nuevo_articulo.html.twig:5`) and
  `nuevoArticulo()` validates via `validateFormToken()` — no new token
  machinery.
- **R-QCRT-007** No new surface/constants/tables/grants; create via article
  page (`VentasArticulo.php:201-204`, already gated) and Excel
  `create_if_missing` (`process_excel_wizard_dispatch.php:361-402`, already
  gated) remain the only other creation paths, unchanged.

## Approach

1. In `nuevoArticulo()`, after referencia/descripcion validation and the
   duplicate-reference check (so a denied create never persists), dispatch the
   frozen event — mirroring `VentasArticulo.php:208-225` ordering (before
   mutation/save) with the Excel gate's catch style (no `error_log`).
2. Admin short-circuit before dispatch (AD-5).
3. Deny branch: `new_error_msg` + `return`; article object is never saved.
4. Tests: host-suite behavioral tests with a registered denying/accepting
   listener driving `nuevoArticulo`'s dispatch path; RED first (STRICT TDD).

## Affected Areas (changed-lines forecast vs 400-line budget)

| Area | Impact | Est. lines |
|------|--------|-----------|
| `Controller/VentasArticulos.php` (`nuevoArticulo`) | Modified | ~30 |
| `tests/VentasArticulosQuickCreateGateTest.php` (new) | New | ~140-160 |
| Event / gate / listener / Twig / config | Untouched | 0 |

**Total ~170-190 lines — well under budget; single PR.**

## Implications / Impact

- Editors lose the ability to quick-create articles **with a price** (they were
  never entitled to set prices; the create itself can be done by a gestor/admin
  or via the gated article page). The denial message states why.
- Host deployments without tarifario see no change (R-QCRT-005).
- The `articulos` table gets no new rows from denied creates — no partial
  state, no cleanup.

## Edge Cases

- **Created-without-pvp**: rejected. The form has no "no price" concept
  (`npvp` defaults to 0.0), so create-without-pvp would still write a price
  (0) and silently produce price-less articles — worse semantics.
- **Unassigned editor**: always denied on quick-create (new article cannot be
  assigned to a group yet); message explains.
- **Admin**: zero dispatch, unchanged.
- **Listener exception**: fail-closed deny, generic reason, no stderr write.
- **Form re-render on deny**: standard POST flow — message on top, modal
  closed; no JS changes (non-goal).
- **Zero listeners**: default allow (host neutrality).

## Tradeoffs

- **Block whole create vs create-without-pvp**: chose **block whole create**
  — consistent with both gated precedents (article page gates the whole save;
  Excel deny happens before the create branch, verified: `VERIFY-ART-002` was
  "denied before create" in the archived walkthrough) and avoids the
  price-less-article data-quality trap. Cost: an editor cannot quick-create at
  all; acceptable and intended (price-setting is the gated operation).
- **Admin skip-dispatch vs dispatch-and-trust-listener**: skip-dispatch
  matches the frozen Excel precedent and guarantees admin dominance by
  construction.

## Risks

| Risk | Likelihood | Mitigation |
|------|-----------|------------|
| Excel-policy conflation (gate applied to import, or vice versa) | Low | Gates stay separate: quick-create dispatch lives only in `nuevoArticulo`; Excel gate untouched; R-QCRT-007 pins no new surface |
| Behavior change for editors surfaces as support load | Medium | Intended fix (RISK-2); clear denial message; gestor/admin path documented |
| Structural-pin tests repeat the REL-2 trap (dispatch order pinned by strpos, not executed) | Medium | STRICT TDD behavioral tests with real listeners; ordering asserted by executing the deny path (save never called) |
| `error_log` in tested path breaks `processIsolation=true` suite | Low | Explicit no-`error_log` rule (R-QCRT-004), Excel gate precedent |
| Bypass via other create paths | Low | Verified: article-page edit and Excel import creates are already gated; other plugins' `articulo` instantiation out of scope |

## Rollback Plan

Revert the change commit(s). No schema, data, or config changes — nothing to
roll back beyond the controller diff and its test file. Reverting restores the
pre-change un-gated quick-create behavior; the archived RISK-2 receipt and
slice-1 receipts remain untouched.

## Dependencies

- Frozen `ArticlePermissionFilterEvent` contract (post-slice-1, archived
  `2026-09-03-tarifario-catalogo-hook-integration`).
- `tarifario` listener already action-independent (`ArticlePermissionListener`)
  — no dependency on tarifario changes; gate is host-neutral when absent.
- Suite baselines to preserve: host **281/594** (2 known
  `testTwigViewUsesAutoEscape` failures), root **1338/3417**, tarifario
  **142/503**.

## Success Criteria

- [ ] Non-admin editor quick-create with `pvp` → no article row created, denial
      message with the listener reason (behavioral test + optional walkthrough).
- [ ] Admin quick-create → created exactly as today, zero dispatch.
- [ ] Zero-listener host → create succeeds unchanged (host neutrality).
- [ ] Fail-closed: throwing listener denies, no `error_log`, suite stays green
      under `processIsolation=true`.
- [ ] Host suite at 281/594 with only the 2 known failures; root 1338/3417;
      tarifario 142/503.
- [ ] No new constants, tables, grants, template or JS changes.