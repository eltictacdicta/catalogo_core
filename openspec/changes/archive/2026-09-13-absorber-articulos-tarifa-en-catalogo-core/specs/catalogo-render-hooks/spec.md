# Delta for catalogo-render-hooks

Additive note to the `catalogo-render-hooks` capability, whose canonical delta is
currently carried by the unarchived change
`absorber-opcionales-tarifa-en-catalogo-core`. Only the ownership of the
`ventas_articulo_*` pair changes: registration moves from `tarifario` to
`catalogo_core`. The four frozen marker names and their positions in
`ventas_articulo.html.twig` and `ventas_opcional.html.twig` MUST NOT change
(host markers untouched). No scenario of this delta alters marker placement.

## ADDED Requirements

### Requirement: Article hook pair registration owned by catalogo_core

`catalogo_core` MUST register `ventas_articulo_tabs_after` and
`ventas_articulo_tab_pane_after` during the Twig build behind its static
idempotency guard, resolving templates from `@catalogo_core/Hooks/`. `tarifario`
MUST register neither article hook after this change.

_Strength: MUST._

#### Scenario: catalogo_core registers the article pair exactly once

- GIVEN catalogo_core's Init and two consecutive simulated Twig builds
- WHEN the hook registry is inspected
- THEN both article hooks are registered once with their `@catalogo_core/Hooks/` templates and a rebuild duplicates none
- Test: `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php`

#### Scenario: tarifario registers zero article hooks

- GIVEN tarifario booted and a simulated Twig build
- WHEN the registry is inspected for `ventas_articulo_tabs_after` and `ventas_articulo_tab_pane_after`
- THEN neither is registered by tarifario
- Test: `plugins/tarifario/tests/Integration/HookRegistrationTest.php`

## MODIFIED Requirements

### Requirement: Hooks render when a listener is registered

When a plugin registers the hook templates, rendering a host page MUST emit the
registered fragment at the marker position, unescaped (the core `render_hook`
Twig function is `is_safe: ['html']`). For the article pair the registering
plugin is `catalogo_core`; the opcional pair is already catalogo_core-owned. The
four frozen marker names and their positions in the host views MUST NOT change.

(Previously: the requirement named `tarifario` as the registering plugin for the
article pair; ownership of that pair now transfers to `catalogo_core`.)

#### Scenario: Registered fragment appears at the marker

- GIVEN a Twig environment with the hook registered
- WHEN the host view renders
- THEN the fragment appears where the marker sits and is not HTML-escaped
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: Article Tarifas tab is injected when its owner registers

- GIVEN catalogo_core active with its article hook registrations
- WHEN `ventas_articulo` (existing article) renders
- THEN the Tarifas tab header and pane appear at the frozen positions
- Test: `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php`
