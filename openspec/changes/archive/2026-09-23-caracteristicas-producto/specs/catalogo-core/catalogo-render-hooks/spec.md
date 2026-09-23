# Delta for catalogo-render-hooks

The frozen four marker names, their positions, the guest-registration/no-op
behavior and the owned-article-pair requirement are unchanged. `Hook context
contract` gains the resolved feature values so guest templates can render
visibility without querying or persisting, and one requirement is ADDED covering
the catalogo_core-owned article tab.

## MODIFIED Requirements

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

## ADDED Requirements

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
