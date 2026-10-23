# Proposal: remove-fs-demo

**Change**: `remove-fs-demo`
**SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`) — the core `openspec/` is NOT a tracker for this change.
**Artifact store**: `openspec`.
**Depends on**: core change `admin-only-pages` (archived 2026-09-19) and the core commit `fix(security): stop FS_DEMO from granting authority`.
**Delivery**: `delivery_strategy: single-pr`, `review_budget_lines: 400`.

---

## Intent

`FS_DEMO` is being retired. The core already removed the part that mattered for
security: `FS_DEMO` used to make `fs_user::get_menu()` return every page
(including `admin_users` and `admin_rol`) and `allow_delete_on()` return `TRUE`
for everything, so a demo deployment on real data exposed the whole permission
system and let anyone delete. That widening is gone and `compose_menu()` no
longer even accepts a `demo` flag, so it cannot come back by accident.

What remains is the flag itself. This change removes it end to end so no code
path can branch on it again.

## Critical distinction: the plugin guard is PROTECTIVE

Unlike the core, every `FS_DEMO` read in this plugin **blocks** a destructive
action rather than granting one:

```php
// Controller/VentasFamilia.php:144 — and the same shape in six more controllers
if (defined('FS_DEMO') && FS_DEMO) {
    $this->new_error_msg('En el modo demo no puedes eliminar familias. Otro usuario podría necesitarlas.');
    return;
}
```

Removing the flag therefore removes a **safety net for shared demo data**. This
is the one decision in this change that is not mechanical, and it must be
resolved explicitly rather than by deleting the guard.

## Scope

### In Scope

- Remove the seven `defined('FS_DEMO') && FS_DEMO` delete guards in
  `Controller/` (`VentasFamilia`, `VentasFabricante`, `VentasFabricantes`,
  `VentasArticulos`, `VentasOpcional`, `VentasArticulo`, `AdminPaises`).
- Update the two tests that assert the guard must exist:
  - `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php:856`
  - `tests/Controller/VentasArticulosListAbsorptionTest.php:728`
- Re-run the plugin suite and the root `Plugins` suite for regression.

### Out of Scope

- The core's remaining `FS_DEMO` reads. They are a separate core change (see
  "Core counterpart" below) and live in the core `openspec/`.
- Any new read-only/demo setting. If protection is wanted, it is a new,
  explicitly named setting — not a resurrected `FS_DEMO`.

## Decision required

**D1 — what replaces the demo delete protection?**

| Option | Behaviour | Cost |
|---|---|---|
| **A. Drop it** | Demo behaves exactly like production; deletes are governed only by `allow_delete` and the role system. | Loses the "shared demo data" safety net. Simplest, and consistent with the core, which no longer treats demo as a special posture. |
| **B. Replace with a named setting** | e.g. `FS_READONLY` (or a plugin setting) blocks destructive actions when enabled. | Keeps the protection under an honest name; adds a setting and its own tests. |

**Recommendation: A**, with B available later if a real deployment needs it.
Rationale: `FS_DEMO` was doing two unrelated jobs (granting authority in the
core, blocking deletes here). Only the second is legitimate, and it is better
served by a setting that says what it does. Keeping the guard alive under a new
name inside this change would grow the surface this change exists to shrink.

> This decision is the reason this proposal is written before any edit: the
> guard cannot be deleted mechanically.

## Core counterpart (tracked in the core, not here)

The core still reads `FS_DEMO` in four places. All are protective or part of the
demo login, none grant authority:

| Location | Behaviour |
|---|---|
| `base/fs_login.php:140` | `log_in_demo()` — password-less demo login |
| `model/core/fs_user.php:580` | `rotate_logkey()` skips rotation in demo |
| `controller/admin_users.php:117`, `controller/admin_user.php:196`, `controller/admin_home.php:288`, `controller/admin_agentes.php:243` | block destructive actions in demo |
| `tests/bootstrap.php:59` | defines the constant for tests |

Removing them is a core change with its own SDD (core `openspec/`), because it
touches `base/`, `model/` and `controller/`. Sequencing: this plugin change can
land independently — the plugin guards are written as
`defined('FS_DEMO') && FS_DEMO`, so they become inert (never true) the moment
the core stops defining the constant, without raising an error.

## Approach

1. Resolve D1.
2. Remove the seven guards (and, if B is chosen, introduce the replacement).
3. Update the two source-scan assertions to pin the NEW contract instead of
   deleting the assertion outright — a removed assertion is a removed guard.
4. Run `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
   and the root `Plugins` suite.

## Risks

| Risk | Likelihood | Mitigation |
|---|---|---|
| Removing the guard silently drops the demo protection | High if done mechanically | D1 must be resolved first; the two tests are updated to pin the chosen contract, not deleted |
| A demo deployment on real data becomes deletable | Med | Same as above; if that deployment exists, choose B |
| Core and plugin land out of order | Low | The plugin guards are `defined()`-guarded, so an undefined constant is inert, never fatal |

## Success Criteria

- [ ] No `FS_DEMO` read remains in `plugins/catalogo_core/Controller/`.
- [ ] The two absorption tests pin the chosen D1 contract.
- [ ] `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` is green.
- [ ] Root `Plugins` suite green.
- [ ] The plugin's `vendor/` is committed with any dependency change (it is not
      changing here, but the rule applies to every plugin SDD).
