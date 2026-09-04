# Spec: articulos-quick-create-permission

Scope: exactly the requirements below (R-QCRT-001..007 plus the test-discipline requirement), delivered by change `ventas-articulos-quick-create-gate`. New capability: no existing canonical at `plugins/catalogo_core/openspec/specs/articulos-quick-create-permission/spec.md` — this English spec is the change's delta artifact; the archive phase translates it into the Spanish canonical. It closes review finding RISK-2 (frozen in the archived `2026-09-04-catalogo-excel-access-control` verify-report) and the R-TAR-HOOK-003 deferral for the quick-create path. The frozen `ArticlePermissionFilterEvent` contract is consumed, never modified.

## 1. Purpose

The article **list page** quick-create form (`POST nreferencia` → `VentasArticulos::nuevoArticulo()`) writes `pvp` with no permission gate and no `ArticlePermissionFilterEvent` dispatch — only page access is required. A non-granted (or granted-but-unassigned) editor can set article prices here even though the same price write on the article page (`VentasArticulo`) and on every Excel import row is already gated by the frozen neutral permission contract. This capability routes the quick-create `pvp` write through the same contract: dispatch before mutation/save for non-admin users, admin skip-dispatch, deny blocks the whole create with a user-facing reason, fail-closed listener-exception semantics, and host-neutral default allow.

## 2. Test-scope conventions

Strict TDD active (PHPUnit 11, DDEV); RED → GREEN. Every scenario below is automatable.

| Tag | Suite |
|---|---|
| `host-suite` | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` |
| `root-suite` | `ddev exec php vendor/bin/phpunit` (auto-discovers plugin tests; a green root run is the cross-plugin regression signal, incl. tarifario's real listener) |

## 3. Requirements

### Requirement: Non-admin quick-create dispatch before pvp mutation (R-QCRT-001)

For every non-admin quick-create request, the system MUST dispatch the frozen `ArticlePermissionFilterEvent` (event name `catalogo_core.article_permission_filter`, action `ACTION_EDIT_ARTICLE`, carrying the submitted `referencia` and the current user's `nick`, no tarifa) at a single dispatch point in `VentasArticulos::nuevoArticulo()` located after the referencia/descripcion validation and the duplicate-reference check and BEFORE any `pvp` mutation and before `save()`, mirroring the article-page dispatch ordering (`Controller/VentasArticulo.php`).

#### Scenario: Non-admin dispatch fires before pvp mutation and save

- GIVEN a non-admin user submitting the quick-create form (`POST nreferencia`) with a valid CSRF token and a unique referencia
- WHEN `nuevoArticulo()` processes the request
- THEN the frozen event is dispatched once before any `pvp` mutation and before `save()`
- AND a denial at that point makes the save unreachable
- Test scope: `host-suite`

### Requirement: Admin dominance — zero dispatch (R-QCRT-002)

The system MUST skip the dispatch entirely for admin users in `nuevoArticulo()`: the event is never constructed and never dispatched, and the admin outcome MUST NOT depend on listener correctness (admin dominance, `ArticuloExcelPermissionGate` AD-5 precedent).

#### Scenario: Admin quick-create with zero dispatch

- GIVEN an admin user
- WHEN they quick-create an article with a price via the list form
- THEN the article is created exactly as today and the event is never constructed or dispatched (zero dispatch), regardless of registered listeners
- Test scope: `host-suite`

### Requirement: Deny blocks the whole create with a user-facing reason (R-QCRT-003)

On a denied verdict the system MUST block the entire quick-create: no `save()`, no article row, no partial state — the article object is never persisted. The system MUST surface a user-facing `new_error_msg` including `getDenialReason()` (Spanish, matching the controller's message convention) and the list MUST re-render with the message (form re-render unchanged). There is no "create without price" fallback: `npvp` defaults to `0.0`, so a denied create is blocked entirely rather than producing a price-less article.

#### Scenario: Unassigned editor denied, article never created

- GIVEN a non-admin editor with no grant (a new article cannot hold a `tarif_grupo_articulos` row yet)
- WHEN they submit the quick-create form with a price
- THEN the listener denies with its reason and the whole create is blocked: the article's save is never called and no article row is created (model not saved / DB state unchanged)
- AND `new_error_msg` carries the denial reason and the list re-renders with the message
- Test scope: `host-suite`

#### Scenario: npvp-defaults-0.0 still denies the whole create

- GIVEN a denied non-admin editor submitting the form without an explicit `npvp` (defaults to 0.0)
- WHEN the request is processed
- THEN the create is still blocked entirely (no price-less article row, no partial state) — deny blocks whole create, never create-without-price
- Test scope: `host-suite`

#### Scenario: Gestor allowed

- GIVEN a gestor on any active tarifa
- WHEN they quick-create with a price
- THEN the listener resolves allow and the article is created with the submitted `pvp` exactly as before the change
- Test scope: `host-suite`

### Requirement: Fail-closed listener exception, no error_log (R-QCRT-004)

The system MUST fail closed when a listener throws: catching `\Throwable` around the dispatch in `nuevoArticulo()` and denying with the generic reason `permission filter error`. The system MUST NOT write to `error_log` on this path (the host suite runs `processIsolation=true`; per the `ArticuloExcelPermissionGate` precedent an `error_log` surfaces as a test error).

#### Scenario: Throwing listener denies fail-closed

- GIVEN a registered listener that throws while evaluating
- WHEN a non-admin quick-create is processed
- THEN the create is denied with the generic `permission filter error` reason, no `error_log` output is produced, and the suite stays green under `processIsolation=true`
- Test scope: `host-suite`

### Requirement: Host neutrality — zero listeners default allow (R-QCRT-005)

The system MUST resolve to allow when no listener is registered for the event: a deployment without tarifario keeps the pre-change quick-create behavior.

#### Scenario: Zero listeners default allow

- GIVEN a deployment with zero listeners for `catalogo_core.article_permission_filter` (tarifario inactive)
- WHEN a non-admin user quick-creates an article with a price
- THEN the create succeeds exactly as before the change
- Test scope: `host-suite`

### Requirement: CSRF unchanged (R-QCRT-006)

The system MUST NOT add token machinery: the quick-create form already renders `{{ csrf_field() }}` (`View/partials/articulos/modal_nuevo_articulo.html.twig:5`) and `nuevoArticulo()` already validates via `validateFormToken()` as its first statement (`Controller/VentasArticulos.php:446`), before any create logic. An invalid token MUST keep blocking before any dispatch or create work.

#### Scenario: CSRF failure still blocks before any create logic

- GIVEN a POST without a valid CSRF token
- WHEN it reaches `nuevoArticulo()`
- THEN `validateFormToken()` rejects it before any dispatch or create work and no article is created (existing flow, unchanged)
- Test scope: `host-suite`

### Requirement: No new surface — orthogonality with the Excel gate (R-QCRT-007)

The quick-create dispatch MUST live ONLY in `VentasArticulos::nuevoArticulo()`. The change MUST NOT introduce new event constants, permission tables, per-user grants, template edits, or JS changes. The other creation paths — the article-page create (`Controller/VentasArticulo.php`, already gated) and the Excel `create_if_missing` branch (`process_excel_wizard_dispatch.php`, already gated) — MUST remain untouched; the quick-create gate and the Excel gate stay orthogonal.

#### Scenario: Excel gate and article page untouched

- GIVEN the change implemented
- WHEN the Excel import/export paths and the article-page edit path execute
- THEN they behave exactly as before (the Excel gate is untouched and no dispatch is applied to any path other than `nuevoArticulo`)
- Test scope: `host-suite`

#### Scenario: No new constants, tables, grants, or UI edits

- GIVEN the change's diff
- WHEN the changed lines are audited
- THEN no new event constants, permission tables, per-user grants, template edits, or JS changes are introduced
- Test scope: `host-suite`

### Requirement: Behavioral test discipline (STRICT TDD)

The tests for this capability MUST be behavioral: a real listener registered on `FSEventDispatcher` and driven through the actual `nuevoArticulo` dispatch path (following the `tests/ArticlePermissionFilterDispatchTest.php` pattern). The deny path MUST be asserted by executing it — save never called, no article persisted; the admin path MUST assert zero dispatch (event never constructed). Tests MUST NOT pin source structure (no strpos/line/string pins on the controller) — the REL-2 lesson from the archived verify-report.

#### Scenario: Deny path executed, save never called

- GIVEN `tests/VentasArticulosQuickCreateGateTest.php` with a registered denying listener
- WHEN the quick-create path is driven through the real dispatch
- THEN the test asserts the create never persists (the article save is unreachable / no article row) — ordering proven by execution, not by string pins
- Test scope: `host-suite`

#### Scenario: Admin path asserts zero dispatch

- GIVEN the same test file driving the admin quick-create path
- WHEN it runs
- THEN it asserts the event is never constructed/dispatched (zero dispatch), not merely that no listener denies
- Test scope: `host-suite`

#### Scenario: No structural pins

- GIVEN the test suite
- WHEN any test inspects the gate
- THEN it exercises dispatch behavior with real listeners and never asserts source strings or line numbers (REL-2 lesson)
- Test scope: `host-suite`

#### Scenario: Cross-plugin composition with tarifario listener

- GIVEN tarifario's real `ArticlePermissionListener` active in the root environment
- WHEN the root-suite run executes
- THEN quick-create resolves gestor = allow and unassigned editor = deny through the composed dispatcher, and the root run stays green (cross-plugin regression signal)
- Test scope: `root-suite`