# Delta: catalogo-render-hooks — punto de hook aditivo en filtros

Puramente aditivo: agrega un punto de inserción en la página de actualización
masiva de precios para que un consumidor aporte controles de filtro. No cambia el
nombre, la posición ni el contexto de los cuatro hooks congelados por
R-TAR-HOOK-003.

## ADDED Requirements

### Requirement: Punto de hook en los filtros de actualización de precios (R-CRH-001)

`plugins/catalogo_core/view/catalogo_actualizar_precios.html.twig` MUST llamar
`render_hook('catalogo_actualizar_precios_filters_after', {'fsc': fsc, 'user': user, 'empresa': empresa, 'i18n': i18n})`
dentro del formulario de filtros, después del último control de filtro existente
y antes de su cierre. El nombre MUST quedar congelado como
`catalogo_actualizar_precios_filters_after` y MUST NOT contener el token
`tarifa`. El marcador MUST usar control de espacios de Twig de modo que, sin
registro, aporte cero bytes.

#### Scenario: Sin registro el marcador rinde vacío

- GIVEN un entorno Twig donde el hook no está registrado
- WHEN la página de actualización de precios renderiza
- THEN el marcador aporta exactamente `''`
- AND la página renderiza completa, sin error fatal
- Test: `plugins/catalogo_core/tests/CatalogoCoreHookMarkersTest.php`

#### Scenario: Con registro el fragmento aparece en el panel de filtros

- GIVEN un hook registrado que rinde un control de filtro
- WHEN la página renderiza
- THEN el fragmento aparece dentro del panel de filtros, en la posición del marcador
- AND no se escapa el HTML del fragmento
- Test: `plugins/catalogo_core/tests/CatalogoCoreHookMarkersTest.php`

#### Scenario: El nombre es neutral

- GIVEN el nombre del hook nuevo
- WHEN se inspecciona en busca del token `tarifa`
- THEN no lo contiene
- AND el nombre es exactamente `catalogo_actualizar_precios_filters_after`

### Requirement: Aditividad estricta sobre los hooks existentes (R-CRH-002)

Este change MUST NOT cambiar el nombre, la posición ni el contexto de
`ventas_articulo_tabs_after`, `ventas_articulo_tab_pane_after`,
`ventas_opcional_tabs_after` ni `ventas_opcional_tab_pane_after`. El hook nuevo
MUST declararse solo en `catalogo_actualizar_precios.html.twig` y MUST NOT
agregarse a `ventas_articulo.html.twig` ni a `ventas_opcional.html.twig`. Las
cuatro claves base del contexto (`fsc`, `user`, `empresa`, `i18n`) MUST
conservar sus valores y el contexto existente, incluida `caracteristicas`, MUST
permanecer sin cambios.

#### Scenario: Las vistas anfitrionas conservan exactamente sus cuatro hooks

- GIVEN `ventas_articulo.html.twig` y `ventas_opcional.html.twig`
- WHEN se enumeran sus llamadas a `render_hook`
- THEN aparecen solo los cuatro nombres congelados
- AND el hook nuevo no aparece en ninguna de las dos vistas
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: El contexto existente no cambia

- GIVEN los cuatro marcadores congelados
- WHEN se inspecciona su contexto
- THEN las cuatro claves base conservan sus valores
- AND el resto del contexto, incluida `caracteristicas`, no se altera
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`
