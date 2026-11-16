# Delta: batch-price-update — scope × target y retiro de la página legacy

Extiende la actualización masiva de precios de "solo artículos contra una lista"
a `scope (articulos | opcionales) × target (lista | tarifa)` sin fusionar
storages ni migrar datos. La página sigue siendo una sola
(`catalogo_actualizar_precios`); el consumidor aporta el target por registro.

Sin cambios: R-BU-005 mantiene su contrato de resumen y mensajes. Las
modificaciones de abajo se limitan a la aplicabilidad del flujo, la resolución
del target, los scopes y el retiro de la superficie legacy.

## MODIFIED Requirements

### Requirement: Flujo preview/apply (R-BU-001)

El sistema MUST implementar la actualización de precios en dos fases sobre el
producto `scope × target`: una fase de preview que calcula las filas afectadas y
los precios prospectivos sin persistir, y una fase de apply que persiste solo
tras una confirmación POST explícita del usuario. El preview MUST NOT persistir
nada para ningún scope: MUST NOT mutar `catalogo_articulo_precios`,
`catalogo_opcional_precios`, `articulos.pvp` ni el storage del target.

El `scope` selecciona las filas (artículos u opcionales); el `target` resuelve el
precio actual y el storage. El preview MUST producir las mismas filas que el
apply va a persistir, sin escribirlas.
(Previamente: el flujo solo cubría artículos contra una lista de precio.)

#### Scenario: El preview no persiste en ningún scope

- GIVEN filtros que seleccionan 3 artículos y, en otra ejecución, 2 opcionales
- WHEN se genera cada preview
- THEN se muestran las filas con precio actual y prospectivo
- AND cero filas cambian en `catalogo_articulo_precios`, `catalogo_opcional_precios` y `articulos.pvp`

#### Scenario: El apply persiste exactamente el preview

- GIVEN un preview de 3 filas afectadas
- WHEN el usuario confirma el apply por POST
- THEN se persisten exactamente esas 3 filas en el storage del target
- AND se muestra el resumen con el conteo de filas actualizadas

### Requirement: Criterios de filtro (R-BU-002)

La actualización MUST aceptar los filtros existentes para el target `lista`:
lista de precio (`codlista`), familia (`codfamilia`), flag de inclusión de
subfamilias resuelto recursivamente sobre la jerarquía `familia`
(`madre`/`hijas()`/`aux_all()`, sin cambio de modelo) y grupo de artículo. Los
filtros MUST combinarse con AND. Con la inclusión de subfamilias apagada, MUST
afectar solo a los miembros directos de la familia.

Los filtros del motor base son un conjunto cerrado y neutral. Un contribuyente
registrado MAY aportar filtros adicionales que acoten la selección de filas: el
motor MUST pasarlos al contribuyente y MUST ignorar cualquier clave que no
reconozca, sin fallar y sin ampliar el conjunto de filas por defecto.

El target MUST resolverse a través del registro de targets de `catalogo_core` y
no por ramas de código en el motor. Un target desconocido o no soportado MUST
fallar cerrado: mensaje de error, cero escrituras y ninguna excepción no
capturada.

Para `scope = opcionales` aplican los filtros de lista y familia (incluidas
subfamilias) sobre las tablas del scope; el filtro de grupo de artículo aplica
solo a `scope = articulos`.
(Previamente: el target era implícitamente la lista y no había resolución por
registro.)

#### Scenario: Inclusión recursiva de subfamilias

- GIVEN la familia F con las hijas F1 (con el artículo A1) y F2 (con A2), y el artículo A3 directamente en F
- WHEN el preview corre para F con inclusión de subfamilias activa
- THEN A1, A2 y A3 quedan afectados

#### Scenario: Límite no recursivo

- GIVEN la misma jerarquía
- WHEN el preview corre para F con inclusión de subfamilias apagada
- THEN solo A3 queda afectado

#### Scenario: El filtro de lista acota las filas del target

- GIVEN el artículo A con filas en las listas L1 y L2
- WHEN la actualización se aplica a L2 con +10%
- THEN solo cambia la fila A/L2; L1 y `articulos.pvp` quedan intactos

#### Scenario: Target desconocido falla cerrado

- GIVEN un POST de apply con un target que el registro no soporta
- WHEN el controlador procesa la petición
- THEN se emite un error y ninguna fila se escribe en ningún storage

#### Scenario: Un filtro adicional aportado por un contribuyente acota la selección

- GIVEN un contribuyente registrado que declara un filtro adicional
- WHEN el preview se ejecuta con ese filtro informado
- THEN solo se seleccionan las filas que ese filtro admite
- AND sin el filtro informado la selección es la del conjunto base
- AND el motor base no interpreta ni valida el nombre del filtro

### Requirement: Fórmula de precio (R-BU-003)

El precio prospectivo MUST calcularse como `round(p * (1 + pct/100), 2)`, donde
`p` es el precio efectivo actual para el par (fila, target) y `pct` el
porcentaje. El resultado MUST redondear half-up a 2 decimales. La fórmula MUST
ser la misma para los targets `lista` y `tarifa`, y para ambos scopes.
(Previamente: la fórmula se especificaba solo para el target lista.)

#### Scenario: Aumento porcentual con redondeo

- GIVEN un precio efectivo de 10.005 y pct 10
- WHEN se aplica la fórmula
- THEN el precio prospectivo es exactamente 11.01

#### Scenario: Disminución porcentual

- GIVEN un precio efectivo de 10.00 y pct -25
- WHEN se aplica la fórmula
- THEN el precio prospectivo es 7.50

#### Scenario: La misma fórmula en ambos targets

- GIVEN el mismo precio efectivo y el mismo pct sobre los targets `lista` y `tarifa`
- WHEN se calcula el precio prospectivo en cada target
- THEN ambos resultados son idénticos

### Requirement: Mutación protegida por CSRF (R-BU-004)

Preview y apply MUST ser peticiones POST validadas con CSRF (`isCsrfValid()`)
en la página `catalogo_actualizar_precios`. Un token inválido MUST rechazar la
operación sin persistencia. Los usuarios no administradores MUST ser rechazados
por la compuerta de administrador. El apply MUST exigir además la confirmación
explícita del usuario.
(Previamente: la protección se especificaba para la página base original.)

#### Scenario: Token CSRF ausente bloquea el apply

- GIVEN un POST de apply sin token CSRF válido
- WHEN el controlador lo procesa
- THEN la operación se rechaza y ninguna fila de precio cambia

#### Scenario: Usuario no administrador rechazado

- GIVEN un usuario no administrador que envía un apply por POST
- WHEN el controlador lo procesa
- THEN el acceso se deniega y no hay escritura

## ADDED Requirements

### Requirement: Scope opcionales sobre el storage canónico (R-BU-006)

Cuando el `scope` seleccionado es `opcionales`, el sistema MUST leer y escribir
`catalogo_opcional_precios` con clave `(id_opcional, codlista)`. El preview MUST
leer las filas de esa tabla unidas a `catalogo_opcionales` por `id_opcional`,
filtrando por `codlista`; cuando se indique familia, MUST filtrar por
`catalogo_opcional_familias` (`id_opcional`, `codfamilia`) con la misma
recursividad de subfamilias. El apply MUST persistir exactamente las filas del
preview, sin tocar el storage de artículos ni `articulos.pvp`. La tabla ya es la
que usa la página legacy a través de `tarif_opcional_precio`.

#### Scenario: Preview de opcionales lee el storage canónico

- GIVEN el opcional O con una fila en `catalogo_opcional_precios` para la lista L2 y otra para L1
- WHEN el preview corre con scope `opcionales` y target L2 al +10%
- THEN aparece una fila para O con su precio actual y el prospectivo
- AND no se escribe ninguna fila

#### Scenario: Apply de opcionales escribe solo la lista objetivo

- GIVEN el preview anterior confirmado por POST
- WHEN el apply se ejecuta
- THEN solo cambia la fila `(O, L2)`
- AND la fila `(O, L1)` y el storage de artículos quedan intactos

### Requirement: Paridad con la página legacy de tarifa (R-BU-007)

Dado el mismo fixture, el motor base a través del target `tarifa` y la página
legacy de actualización de precios MUST producir filas de preview idénticas
(mismo conjunto, mismo orden y mismos valores), totales idénticos y el mismo
storage destino persistido. El fixture MUST partir del mismo precio de origen en
ambos caminos. La paridad MUST cubrir `scope = articulos` y `scope = opcionales`.

Ninguno de los dos caminos MUST divergir en la fórmula ni en la clave escrita.

#### Scenario: Preview idéntico sobre el mismo fixture

- GIVEN un fixture compartido de artículos con filas en `tarif_articulo_precios` para la tarifa T y el mismo precio de origen en ambos caminos
- WHEN el motor base (target `tarifa`) y la página legacy generan el preview con el mismo pct
- THEN las filas son idénticas en conjunto, orden y valores
- AND el total de filas y la suma de precios prospectivos coinciden

#### Scenario: Apply escribe el mismo storage

- GIVEN la confirmación del preview anterior en ambos caminos
- WHEN cada camino aplica
- THEN ambos escriben `tarif_articulo_precios` con clave `(referencia, codtarifa)`
- AND los valores persistidos son iguales

#### Scenario: Paridad de opcionales

- GIVEN un fixture de opcionales con filas en `catalogo_opcional_precios` para la tarifa T
- WHEN ambos caminos generan el preview y aplican
- THEN las filas y los totales coinciden
- AND ambos escriben la clave `(id_opcional, codlista)`

### Requirement: Retiro con redirect de la URL legacy (R-BU-008)

Navegar a la URL legacy `index.php?page=tarif_actualizar_precios` MUST redirigir
a `catalogo_actualizar_precios` sin publicar una segunda página ni una entrada de
menú. El redirect MUST preservar la intención del operador: cuando el target
`tarifa` esté registrado, la página destino MUST abrirse con `target=tarifa` y el
`codtarifa` entrante preseleccionado.

El redirect MUST NOT mapear `codtarifa` a `codlista`.
`CatalogLegacyTableMigration::syncMissingPriceLists()` crea entidades de lista a
partir de las tarifas, pero **nunca** copia `tarif_articulo_precios` a
`catalogo_articulo_precios`: mapear a lista haría que el preview leyera el fallback
`articulos.pvp` y que el apply escribiera una fila canónica que la UI de tarifa no
lee.

Cuando el target `tarifa` no esté disponible (consumidor inactivo), la página
destino MUST abrirse sin target ni tarifa preseleccionados, con un aviso, y MUST
NOT aplicar nada.

#### Scenario: Con el target disponible se conserva la tarifa

- GIVEN el target `tarifa` registrado y un `codtarifa` válido
- WHEN se navega a la URL legacy con ese `codtarifa` y un porcentaje
- THEN se redirige a `catalogo_actualizar_precios` con `target=tarifa` y esa tarifa preseleccionada
- AND no se aplica ningún cambio de precio

#### Scenario: Sin el target disponible no se aplica nada

- GIVEN un entorno donde el target `tarifa` no está registrado
- WHEN se navega a la URL legacy con un `codtarifa` y un porcentaje
- THEN la página destino abre sin target ni tarifa preseleccionados y muestra un aviso
- AND no se escribe ninguna fila de precio

#### Scenario: El redirect no mapea tarifa a lista

- GIVEN un `codtarifa` con una fila homónima en `catalogo_listas_precio`
- WHEN se navega a la URL legacy
- THEN la página destino recibe `target=tarifa` con ese `codtarifa`
- AND en ningún caso recibe `codlista` derivada del `codtarifa`

### Requirement: Evento neutro de precios persistidos (R-BU-009)

Tras aplicar una actualización, el motor MUST despachar **una sola vez** el evento
neutro `catalogo_core.price_update_persisted` con las filas que el libro persistió
con éxito: `scope`, código del `target`, clave del target y, por fila, la clave, el
precio anterior y el precio nuevo. El precio anterior MUST leerse del libro en el
momento del apply (nunca del cliente). El evento MUST NOT poder cancelar ni
alterar la operación; es solo informativo. Una excepción de un listener MUST NOT
cambiar el resultado `updated`/`failed` ni interrumpir el apply: se registra con
`error_log()` etiquetado. Sin listeners el motor MUST NOT hacer ninguna lectura
adicional ni despachar nada. Una fila que falla al persistir MUST NOT aparecer en
el evento, y si ninguna fila se persistió no se despacha nada. El evento y su
nombre MUST NOT contener nombres de plugins consumidores ni de storages del
consumidor.

El historial de precios de los opcionales de una tarifa es política del
consumidor: `catalogo_core` solo emite el hecho; no escribe historial desde este
flujo.

#### Scenario: Se emite una vez con las filas persistidas

- GIVEN un listener registrado y un apply de tres filas, una de las cuales falla al persistir
- WHEN se aplica
- THEN el listener recibe un único evento con las dos filas persistidas y sus precios anterior y nuevo
- AND `updated` es 2 y `failed` contiene la fila fallida
- Test: `plugins/catalogo_core/tests/Services/CatalogoPriceUpdateServiceTest.php`

#### Scenario: Sin listeners no hay coste

- GIVEN ningún listener registrado
- WHEN se aplica
- THEN el libro no recibe ninguna lectura adicional de precios
- AND el resultado es idéntico al de antes del evento
- Test: `plugins/catalogo_core/tests/Services/CatalogoPriceUpdateServiceTest.php`

#### Scenario: Un listener que lanza no rompe el apply

- GIVEN un listener que lanza una excepción
- WHEN se aplica
- THEN el resultado `updated`/`failed` es el mismo que sin listener
- AND se registra un `error_log()` etiquetado
- Test: `plugins/catalogo_core/tests/Services/CatalogoPriceUpdateServiceTest.php`

#### Scenario: El precio anterior no viene del cliente

- GIVEN filas con un `precio_actual` manipulado en el repost
- WHEN se aplica
- THEN el evento lleva el precio anterior leído del libro, no el repostado
- Test: `plugins/catalogo_core/tests/Services/CatalogoPriceUpdateServiceTest.php`
