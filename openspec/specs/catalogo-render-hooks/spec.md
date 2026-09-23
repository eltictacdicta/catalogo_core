# catalogo-render-hooks Specification

## Purpose

Owned by `catalogo_core`: four `render_hook` insertion points in the opcional and
article host views so consumers (tarifario) can inject tabs when active, and
render nothing otherwise. After the article absorption program (WU-1) the article
hook pair is also registered by `catalogo_core`; `tarifario` registers no article
hook. The four frozen marker names and their positions in
`ventas_opcional.html.twig` and `ventas_articulo.html.twig` MUST NOT change.

## Requirements

### Requirement: Frozen hook insertion points

`plugins/catalogo_core/View/ventas_opcional.html.twig` MUST call `render_hook('ventas_opcional_tabs_after', ctx)` immediately before the `#ul_tabs` `</ul>` and `render_hook('ventas_opcional_tab_pane_after', ctx)` before the `.tab-content` closing `</div>`. `plugins/catalogo_core/View/ventas_articulo.html.twig` MUST call `render_hook('ventas_articulo_tabs_after', ctx)` before the tab `</ul>` and `render_hook('ventas_articulo_tab_pane_after', ctx)` before its `.tab-content` closing `</div>`. Exactly those four names MUST appear; no `ventas_articulos_*` hooks in this change. Markers MUST use Twig whitespace control so an empty marker contributes zero content bytes.

#### Scenario: Markers exist at frozen positions

- GIVEN the two host views
- WHEN `CatalogoCoreHookMarkersTest` inspects their source
- THEN each view contains its two frozen marker calls at the documented positions
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: Only the four frozen names

- GIVEN the host views
- WHEN their `render_hook` calls are enumerated
- THEN exactly the four frozen names appear and no `ventas_articulos_*` name exists
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

### Requirement: Hooks render when a listener is registered

When a plugin registers the hook templates, rendering a host page MUST emit the
registered fragment at the marker position, unescaped (the core `render_hook`
Twig function is `is_safe: ['html']`). For the article pair the registering plugin
is `catalogo_core`; the opcional pair is already catalogo_core-owned. The four
frozen marker names and their positions in the host views MUST NOT change.

#### Scenario: Registered fragment appears at the marker

- GIVEN a Twig environment with the hook registered
- WHEN the host view renders
- THEN the fragment appears where the marker sits and is not HTML-escaped
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: Tarifas tab is injected when tarifario is active

- GIVEN tarifario active with its opcional hook registrations
- WHEN `ventas_opcional` (saved opcional) renders
- THEN the Tarifas tab header and pane appear at the frozen positions
- Test: `plugins/tarifario/tests/Integration/HookRegistrationTest.php` + smoke

#### Scenario: Article Tarifas tab is injected when its owner registers

- GIVEN catalogo_core active with its article hook registrations
- WHEN `ventas_articulo` (existing article) renders
- THEN the Tarifas tab header and pane appear at the frozen positions
- Test: `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php`

### Requirement: No-op when no listener is registered

With no hook registered (tarifario inactive), every marker MUST render the empty string, and the page MUST render without fatal error or blank content.

#### Scenario: Empty registry returns empty string

- GIVEN a Twig environment where `ViewHookRegistry::has` is false for the four names
- WHEN each marker renders
- THEN each contributes exactly `''`
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: Host pages render with tarifario inactive

- GIVEN catalogo_core active and tarifario inactive
- WHEN `ventas_opcional` and `ventas_articulo` render
- THEN both return a full page with no injected tab and no fatal error
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` + smoke

### Requirement: Hook template resolution is isolated and safe

The `@tarifario/Hooks/*` templates MUST resolve through the TwigLoaderEvent namespace when tarifario is active. `ViewHookRegistry::render` MUST catch template errors, log them and return the HTML accumulated so far instead of propagating the failure.

#### Scenario: Namespace resolves

- GIVEN tarifario active and its Init listeners fired during the Twig build
- WHEN a hook template renders
- THEN the `@tarifario/Hooks/...` path resolves without loader error
- Test: `plugins/tarifario/tests/Integration/HookRegistrationTest.php`

#### Scenario: Broken template is swallowed

- GIVEN a registered hook whose template throws
- WHEN the marker renders
- THEN the marker returns the accumulated HTML without propagating the error and the error is logged
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

### Requirement: Extensible naming contract

Hook names and injected DOM ids MUST follow `{page}_tabs_after` / `{page}_tab_pane_after` and MUST NOT contain the bare token `tarifa`, so a later `ventas_articulos_*` slice can be added without touching the frozen four.

#### Scenario: Naming pattern is derivable

- GIVEN the frozen names
- WHEN a future `ventas_articulos_tabs_after` / `ventas_articulos_tab_pane_after` is derived
- THEN it conforms to the pattern and requires no change to the four frozen names
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: No bare tarifa token

- WHEN hook names and injected ids are inspected
- THEN none uses the bare `tarifa` token
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

### Requirement: Hook context contract

Every marker MUST pass the context `{'fsc': fsc, 'user': user, 'empresa': empresa, 'i18n': i18n}`
so a registered guest template can guard on host state (for example
`fsc.is_new`, `fsc.articulo.referencia`, `fsc.opcional.id`). In addition, every
marker MUST pass a `caracteristicas` map (definition `codigo` → effective value
for the host entity and the selected tarifa) resolved through the
`caracteristicas-producto` resolver with a batched read, never per definition and
never persisting. The four base keys and their values MUST remain byte-identical.
(Previously: the context carried only `fsc`, `user`, `empresa` and `i18n`.)

#### Scenario: Context keys reach the hook template

- GIVEN a registered hook that reads `fsc`, `user`, `empresa` and `i18n`
- WHEN the host view renders
- THEN all four keys are available to the hook template
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: Feature context reaches the hook template

- GIVEN a host article with effective feature values for the selected tarifa
- WHEN the host view renders
- THEN the hook template receives the `caracteristicas` map keyed by definition `codigo`
- AND the map is resolved without persisting any value row
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: Unsaved opcional renders no tab

- GIVEN a new (unsaved) opcional rendered by `ventas_opcional` with tarifario active
- WHEN the hook templates render
- THEN their `fsc.is_new` guard produces empty output and no Tarifas tab appears
- Test: `plugins/tarifario/tests/Integration/HookRegistrationTest.php`

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

### Requirement: Article hook tab renders derived feature values

The catalogo_core-owned `ventas_articulo_tabs_after` /
`ventas_articulo_tab_pane_after` templates MUST render the per-tarifa
`en_tarifa`/`en_catalogo` visibility from the resolved `caracteristicas` context
as a read-only indicator, MUST NOT read the dropped legacy columns, and MUST NOT
persist anything while rendering. The four frozen marker names and their
positions, and the hook-pair ownership, MUST NOT change.

#### Scenario: Tab renders resolver-driven visibility

- GIVEN an article whose effective feature values differ from any historical column value
- WHEN `ventas_articulo` renders with the article tab registered
- THEN the tab shows the resolver-derived visibility
- Test: `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php`

#### Scenario: Rendering writes nothing

- GIVEN a rendered article tab
- WHEN the feature value tables are inspected before and after the render
- THEN they are byte-identical
- Test: `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php`
