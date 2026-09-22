# Delta for catalogo-render-hooks

Decision D4 (proposal.md:38) retires catalogo_core's article hook pair
registration. The frozen four marker names, their positions, the
guest-registration/no-op behavior, the resolution isolation and the naming
contract are unchanged. The opcional pair requirement is untouched: catalogo_core
keeps owning and registering `ventas_opcional_tabs_after` /
`ventas_opcional_tab_pane_after`.

The sibling change `caracteristicas-producto` ADDS the requirement
`Article hook tab renders derived feature values`, which presumes the article
pair is registered and renders. Because D4 deletes both templates, that
requirement is listed under REMOVED below; it MUST be reconciled only under the
sibling-first merge/archive order stated in the change summary.

## MODIFIED Requirements

### Requirement: Article hook pair registration owned by catalogo_core

`catalogo_core` MUST NOT register `ventas_articulo_tabs_after` or
`ventas_articulo_tab_pane_after` during the Twig build. It MUST remove the
`ARTICULO_HOOK_TEMPLATES` mapping and the article entries of `registerHooks()`,
and MUST delete both `@catalogo_core/Hooks/ventas_articulo_tabs_after.html.twig`
and `@catalogo_core/Hooks/ventas_articulo_tab_pane_after.html.twig`. The two
markers MUST remain declared in `ventas_articulo.html.twig` at their frozen names
and positions as inert insertion points that render the empty string when no
registrant exists. The opcional pair MUST remain registered by `catalogo_core`,
and `tarifario` MUST still register neither article hook.
(Previously: catalogo_core registered the article pair from `@catalogo_core/Hooks/`
behind its static idempotency guard.)

_Strength: MUST / MUST NOT._

#### Scenario: catalogo_core registers zero article hooks across rebuilds

- GIVEN catalogo_core's Init and two consecutive simulated Twig builds
- WHEN the hook registry is inspected for the article pair
- THEN neither hook is registered and no rebuild registers it
- Test: `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` (flipped)

#### Scenario: Article markers render empty with no registrant

- GIVEN the two frozen article markers and no registrant
- WHEN `ventas_articulo.html.twig` renders
- THEN each marker contributes the empty string and the page renders without fatal error
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: Opcional pair stays registered

- GIVEN catalogo_core's Init and a simulated Twig build
- WHEN the hook registry is inspected for the opcional pair
- THEN both opcional hooks are registered once from `@catalogo_core/Hooks/`
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: tarifario registers zero article hooks

- GIVEN tarifario booted and a simulated Twig build
- WHEN the registry is inspected for `ventas_articulo_tabs_after` and `ventas_articulo_tab_pane_after`
- THEN neither is registered by tarifario
- Test: `plugins/tarifario/tests/Integration/HookRegistrationTest.php`

## REMOVED Requirements

### Requirement: Article hook tab renders derived feature values

(Reason: D4 deletes both `@catalogo_core/Hooks/ventas_articulo_*.html.twig`
templates and drops the registration, so the pair can no longer render any
indicator; the per-tarifa visibility indicator renders inside the unified
`#datos` pane instead (ART-10). This requirement was ADDED by the still-active
sibling change `caracteristicas-producto`; this removal applies only under the
sibling-first archive order. Applying this block before the sibling archives is a
no-op.)
(Migration: the resolver-driven `en_tarifa` / `en_catalogo` indicator is rendered
by the unified `#datos` pane; no consumer template remains registered for either
article marker.)
