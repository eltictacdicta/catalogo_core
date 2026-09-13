# Delta for catalogo-render-hooks

New capability owned by catalogo_core. It exposes four `render_hook` insertion points in the opcional/article views so consumers (tarifario) can inject tabs when active, and renders nothing otherwise. No MODIFIED/REMOVED requirements.

## ADDED Requirements

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

When a plugin (tarifario) registers the four hook templates, rendering a host page MUST emit the registered fragment at the marker position, unescaped (the core `render_hook` Twig function is `is_safe: ['html']`).

#### Scenario: Registered fragment appears at the marker

- GIVEN a Twig environment with the four hooks registered
- WHEN the host view renders
- THEN the fragment appears where the marker sits and is not HTML-escaped
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: Tarifas tab is injected when tarifario is active

- GIVEN tarifario active with its hook registrations
- WHEN `ventas_opcional` (saved opcional) and `ventas_articulo` (existing article) render
- THEN the Tarifas tab header and pane appear at the frozen positions
- Test: `plugins/tarifario/tests/Integration/HookRegistrationTest.php` + smoke

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

Every marker MUST pass the context `{'fsc': fsc, 'user': user, 'empresa': empresa, 'i18n': i18n}` so a registered guest template can guard on host state (for example `fsc.is_new`, `fsc.articulo.referencia`, `fsc.opcional.id`).

#### Scenario: Context keys reach the hook template

- GIVEN a registered hook that reads `fsc`, `user`, `empresa` and `i18n`
- WHEN the host view renders
- THEN all four keys are available to the hook template
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: Unsaved opcional renders no tab

- GIVEN a new (unsaved) opcional rendered by `ventas_opcional` with tarifario active
- WHEN the hook templates render
- THEN their `fsc.is_new` guard produces empty output and no Tarifas tab appears
- Test: `plugins/tarifario/tests/Integration/HookRegistrationTest.php`
