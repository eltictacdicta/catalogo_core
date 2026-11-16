# price-surface-ownership Specification

## Purpose

Owned by `catalogo_core`: una única regla de propiedad sobre las superficies de
precio del catálogo. `catalogo_core` es dueño de la página de actualización
masiva, del motor de cálculo, del storage canónico, de la presentación y de los
puntos de hook; `tarifario` conserva solo la política de tarifas y contribuye
extensiones a través de los registros de `catalogo_core`.

La dirección de dependencia es **consumer → `catalogo_core`**. `catalogo_core`
declara seams neutrales y no nombra al consumidor; el consumidor se registra en
ellos. Este capability no crea servicios ni páginas: formaliza el límite y lo
protege con guardrails ejecutables.

Referencias que este capability no redefine: `batch-price-update`
(R-BU-001…R-BU-008, con el detalle de flujo, filtros, fórmula y CSRF) y
`catalogo-render-hooks` (nombres y semántica de los hooks). Los cuatro hooks
congelados por R-TAR-HOOK-003 conservan su contrato sin cambios.

## Requirements

### Requirement: Un único dueño por superficie de precio (R-PSO-001)

`catalogo_core` MUST ser el único dueño de toda superficie de precio del
catálogo: la página de actualización masiva, el motor de cálculo, el storage
canónico (`catalogo_articulo_precios`, `catalogo_listas_precio` y
`catalogo_opcional_precios`), la presentación y los puntos de hook. `tarifario`
MUST limitarse a la política de tarifas (asignación rol/grupo, historial,
catálogo por tarifa y configurador de opcionales) y MUST NOT publicar páginas,
motores ni storages paralelos de precio.

Todo acceso a una tabla que el motor base no trata como canónica MUST quedar
encapsulado en el target registrado que la usa; el motor base MUST NOT conocer
tablas del consumidor.

#### Scenario: Las superficies de precio viven solo en el owner

- GIVEN el árbol de producción de ambos plugins
- WHEN se enumeran la página de actualización masiva, el motor y el storage canónico
- THEN la página y el motor existen solo bajo `plugins/catalogo_core/`
- AND el consumidor no declara ningún controlador ni vista de actualización masiva de precios

#### Scenario: El consumidor aporta política, no superficie

- GIVEN `tarifario` activo
- WHEN se enumeran sus controladores y vistas
- THEN no existe en `tarifario` ninguna página de actualización masiva de precios
- AND su aporte se limita a los registros y hooks que `catalogo_core` expone

### Requirement: El consumidor se registra, nunca publica (R-PSO-002)

Un plugin de extensión MUST contribuir targets y handlers a través del registro
que `catalogo_core` expone (registro de targets de actualización de precios,
invocado desde su `Init::init()`). Ese plugin MUST NOT publicar una página que
`catalogo_core` pueda servir, ni una entrada de menú para la misma tarea.

La contribución MUST ser opcional y desmontable: con el consumidor inactivo el
registro queda vacío y la página base sigue operando con los targets de
`catalogo_core`.

#### Scenario: El target registrado se resuelve desde la página base

- GIVEN un consumidor que registró un target durante su `Init::init()`
- WHEN la página base resuelve ese target
- THEN el target aparece como opción válida y la actualización se despacha a su handler

#### Scenario: Sin consumidor la página sigue operando

- GIVEN el consumidor inactivo y ningún target de consumidor registrado
- WHEN la página base renderiza y ejecuta un preview
- THEN solo están disponibles los targets de `catalogo_core` y no hay error fatal

### Requirement: Guardrail de acoplamiento al consumidor (R-PSO-003)

Ningún archivo de producción bajo `plugins/catalogo_core/` MUST contener el
nombre del plugin consumidor, salvo que ya figure en la lista congelada
`TARIFARIO_ALLOWLIST` de
`plugins/catalogo_core/tests/CatalogoCoreHookMarkersTest.php`. El escaneo excluye
`vendor/`, `tests/`, `openspec/` y `.git`.

La lista MUST NOT crecer para archivos nuevos: una entrada nueva solo es
admisible para un archivo preexistente y con una razón documentada.
`catalogo_core` MUST usar vocabulario neutral ("consumer", "target") en el código
nuevo de este change.

#### Scenario: Un archivo nuevo que nombra al consumidor falla el guardrail

- GIVEN un archivo nuevo bajo `plugins/catalogo_core/` que contiene el token del consumidor
- WHEN se ejecuta `testNoTarifarioReferencesOutsideDocumentedAllowlist`
- THEN el test falla y lista ese archivo como violación

#### Scenario: La allowlist no se amplía

- GIVEN la allowlist congelada al inicio del change
- WHEN se agregan los archivos nuevos de este change
- THEN ninguna entrada nueva corresponde a esos archivos

#### Scenario: El puente legacy existente sigue permitido

- GIVEN `/Services/CatalogLegacyTableMigration.php`, ya presente en la allowlist
- WHEN se ejecuta el guardrail
- THEN ese archivo no se reporta como violación
- Test: `plugins/catalogo_core/tests/CatalogoCoreHookMarkersTest.php`

### Requirement: Hooks congelados (R-PSO-004)

Los cuatro hooks `ventas_articulo_tabs_after`, `ventas_articulo_tab_pane_after`,
`ventas_opcional_tabs_after` y `ventas_opcional_tab_pane_after` MUST conservar
nombre, posición y semántica conforme a R-TAR-HOOK-003. Este change MUST NOT
renombrar, mover ni eliminar ninguno de los cuatro, ni alterar su contexto.

#### Scenario: Los cuatro nombres siguen en sus vistas anfitrionas

- GIVEN `ventas_articulo.html.twig` y `ventas_opcional.html.twig`
- WHEN se enumeran sus llamadas a `render_hook`
- THEN aparecen exactamente los cuatro nombres congelados, en sus posiciones documentadas
- Test: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php`

#### Scenario: El hook nuevo no reemplaza ni toca a los congelados

- GIVEN el punto de hook aditivo de la página de actualización de precios
- WHEN se inspecciona el registro de hooks
- THEN el hook nuevo es adicional y los cuatro congelados mantienen su registro y su contexto

### Requirement: Una sola página de actualización masiva (R-PSO-005)

El sistema MUST publicar exactamente una página de actualización masiva titulada
`Actualizar precios`. Esa página MUST ser `catalogo_actualizar_precios`, propiedad
de `catalogo_core` (carpeta `ventas`), y MUST cubrir el producto
`scope (articulos | opcionales) × target (lista | tarifa)`. MUST NOT existir una
segunda página publicada ni una segunda entrada de menú visible para la misma
tarea, incluida la antigua `Actualizar Precios` de `tarifario`.

Una fila en `fs_pages` para un controlador legacy resoluble es inevitable: el
constructor de `fs_controller` invoca `check_fs_page()`. La garantía durable es
**una sola página publicada y una sola entrada de menú renderizada**, no la
ausencia de filas en el registro.

#### Scenario: Exactamente una página publicada

- GIVEN las páginas publicadas para un usuario administrador
- WHEN se buscan títulos equivalentes a `Actualizar precios`
- THEN hay exactamente una, con nombre `catalogo_actualizar_precios` y carpeta `ventas`
- AND la fila de `fs_pages` del shim de redirect, si existe, está oculta del menú

#### Scenario: Una sola entrada de menú

- GIVEN el menú resuelto para un usuario administrador
- WHEN se enumeran las entradas de actualización de precios
- THEN aparece una sola entrada, la de `catalogo_actualizar_precios`

#### Scenario: La página cubre el producto completo

- GIVEN la página `catalogo_actualizar_precios`
- WHEN se inspeccionan sus selectores
- THEN ofrece `scope` con `articulos` y `opcionales`
- AND ofrece los targets que el registro expone (con el consumidor activo, `lista` y `tarifa`)

### Requirement: Superficies retiradas ausentes (R-PSO-006)

Las vistas `plugins/tarifario/View/tarif_actualizar_precios.html.twig` y
`plugins/tarifario/View/tarif_articulo.html.twig` MUST NOT existir. El controlador
`plugins/tarifario/controller/tarif_actualizar_precios.php` MUST NOT implementar la
página: no MUST registrar entrada de menú, no MUST contener flujo de preview/apply
y, si existe, MUST limitarse a redirigir a `catalogo_actualizar_precios` conforme a
R-BU-008. Ningún test, fixture ni constante MUST afirmar la presencia de las vistas
retiradas; toda entrada que las referencie (por ejemplo en `REPOINTED_LINKS` o en
listas de fixtures de absorción) MUST eliminarse en el mismo slice que borra el
archivo.

#### Scenario: Las vistas retiradas ya no existen

- GIVEN el árbol de trabajo tras el retiro
- WHEN se comprueba la existencia de las dos vistas
- THEN ninguna existe

#### Scenario: El controlador legacy sólo redirige

- GIVEN `plugins/tarifario/controller/tarif_actualizar_precios.php` tras el retiro
- WHEN se inspecciona su cuerpo
- THEN no contiene lógica de preview ni de apply
- AND no registra entrada de menú
- AND su única respuesta es el redirect de R-BU-008

#### Scenario: Ningún test exige la vista retirada

- GIVEN las suites de `catalogo_core` y `tarifario`
- WHEN se buscan referencias a las vistas retiradas fuera de `openspec/`
- THEN no queda ninguna referencia que exija su existencia
- Test: `plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php`, `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`, `plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php`

### Requirement: Propiedad cross-plugin del spec (R-PSO-007)

El delta de consumidor para `tarifario/catalogo-integration` MUST vivir en
`plugins/tarifario/openspec/` y MUST abrirse como su propio change cuando se
implemente el registro del consumidor (F3). Este change MUST NOT escribir en el
árbol `openspec` de otro plugin. `catalogo_core` MUST poseer únicamente el
contrato de la API de registro descrito en este capability; la semántica de la
política de tarifas MUST permanecer del lado del consumidor.

#### Scenario: Este change no escribe en otro plugin

- GIVEN los artefactos de `unificar-superficies-precio-catalogo-core`
- WHEN se enumeran sus rutas
- THEN todas viven bajo `plugins/catalogo_core/openspec/changes/unificar-superficies-precio-catalogo-core/`
- AND no se creó ningún archivo bajo `plugins/tarifario/openspec/changes/` para este change

#### Scenario: El delta del consumidor se abre por separado

- GIVEN la implementación del registro del consumidor (F3)
- WHEN se documenta el cambio del lado del consumidor
- THEN existe un change propio en `plugins/tarifario/openspec/changes/`
- AND referencia el contrato de registro de `catalogo_core` sin redefinirlo
