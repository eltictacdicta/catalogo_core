# Artículos — Ajustes de acceso al Excel (plugin catalogo_core)

## Purpose

Nueva capacidad de `catalogo_core`: configuración solo-admin de qué **roles de
usuario** (`fs_roles`) pueden además importar/exportar el Excel del catálogo de
artículos. Persistida como ajuste `catalogo_excel_roles` (lista de `codrol`
separada por comas) vía `fs_settings` get/set sobre `$GLOBALS['config2']`
(respaldado por INI `tmp/{FS_TMP_NAME}config2.ini`). Sin tablas de permisos
nuevas; sin concesiones por usuario.

Restricciones:

- **Independencia**: esta capacidad no depende de cambios de UI del plugin
  tarifario (rows_ref re-render, delete false success). Sin artefactos
  compartidos, sin restricción de orden.
- **Riesgo de nomenclatura**: nada introducido por esta capacidad puede
  nombrarse como "grupos de cliente" / customer groups (`gruposclientes`,
  clientes_core, es un concepto no relacionado). Todos los identificadores
  nuevos usan vocabulario de roles (`rol`, `role`) únicamente.

## Convenciones de test

- `host-suite` = `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- `root-suite` = `ddev exec php vendor/bin/phpunit` (auto-descubre los tests del plugin; una corrida root en verde es la señal de regresión cross-plugin)

## Requirements

### Requirement: Ajuste de concesión de roles controlado por admin (R-CEXC-002)

El sistema MUST leer la concesión de roles de Excel (lista de `codrol` separada
por comas) a través de `Services/CatalogoOptions` (store `opciones_catalogo`,
claves namespaced). Para compatibilidad hacia atrás, cuando la clave nueva del
store esté ausente, la política MUST hacer fallback a la clave legacy
`catalogo_excel_roles` (shim de lectura; sin doble escritura: `CatalogoOptions`
es la única fuente de escritura). MUST persistirse vía el `fs_settings`
existente get/set sobre `$GLOBALS['config2']`; MUST NO crear tablas nuevas; y
la lista concedida MUST validarse contra las filas `fs_roles` existentes en
tiempo de evaluación. Semántica fail-closed sin cambios: valor ausente o no
parseable concede cero roles.
(Previously: el ajuste se leía y escribía únicamente en la clave
`catalogo_excel_roles` desde la página dedicada `catalogo_excel_settings`.)

#### Scenario: Admin persiste una lista de roles

- GIVEN un admin en la página `opciones_catalogo`
- WHEN guarda los códigos de rol `A,B`
- THEN `CatalogoOptions` lee de vuelta la lista `A,B`
- Test scope: `host-suite`

#### Scenario: Ajuste ausente no concede roles

- GIVEN la clave del store y la legacy `catalogo_excel_roles` están ausentes
- WHEN la lista de concesiones se evalúa para cualquier usuario no-admin
- THEN cero roles se conceden (default fail-closed)
- Test scope: `host-suite`

#### Scenario: Valor de ajuste inválido o no parseable no concede roles

- GIVEN la concesión contiene un valor no parseable como lista separada por comas
- WHEN la lista de concesiones se evalúa
- THEN cero roles se conceden (fail-closed) y la evaluación no produce error
- Test scope: `host-suite`

#### Scenario: Fallback a la clave legacy

- GIVEN la clave nueva del store ausente y legacy `catalogo_excel_roles = "A"`
- WHEN un no-admin con el rol A se evalúa
- THEN el usuario está concedido (shim de compatibilidad)
- Test scope: `host-suite`

#### Scenario: Nomenclatura (auditable por grep)

- GIVEN los archivos introducidos por esta capacidad
- WHEN se ejecuta `grep -riE 'grupocliente|grupo_clientes|customer[_ ]?group' <archivos nuevos>`
- THEN devuelve cero coincidencias
- Test scope: `host-suite`

## REMOVED Requirements



### Requirement: Semántica de evaluación de concesiones (R-CEXC-006)

Las concesiones MUST evaluarse por request: (a) un rol concedido que se borra
después de `fs_roles` se trata como **no concedido** (la lista de concesiones
se intersecta con los `fs_roles` existentes); (b) los usuarios admin pasan por
la ruta admin independientemente de cualquier concesión (dominancia admin);
(c) un usuario no-admin cuyos roles intersectan la lista concedida validada
está concedido, en otro caso no. Cualquier caché MUST ser solo por request (sin
concesiones caducas entre requests).

#### Scenario: Rol concedido borrado después

- GIVEN el rol `B` concedido y el usuario no-admin U que posee `B`
- WHEN el rol `B` se borra de `fs_roles` y U emite el siguiente request
- THEN U se evalúa como no concedido
- Test scope: `host-suite`

#### Scenario: Dominancia admin

- GIVEN un usuario admin cuyo rol también aparece en la lista concedida
- WHEN la política evalúa al usuario
- THEN la ruta admin domina y el acceso se permite independiente del estado de la concesión
- Test scope: `host-suite`

#### Scenario: No-admin con rol concedido

- GIVEN el rol `A` concedido y el usuario no-admin U que posee `A`
- WHEN la política evalúa a U
- THEN U está concedido
- Test scope: `host-suite`

#### Scenario: Sin caducidad cross-request

- GIVEN un cambio de concesión o revocación entre dos requests HTTP
- WHEN cada request evalúa la política
- THEN cada uno refleja el estado del ajuste y de los roles en su propio tiempo de evaluación
- Test scope: `host-suite`
