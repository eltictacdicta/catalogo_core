# Delta for articulos-excel-access-settings

## MODIFIED Requirements

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

### Requirement: Página de ajustes solo-admin

(Reason: la página dedicada `catalogo_excel_settings` se reemplaza por la
página general `opciones_catalogo` (spec `catalog-options-store`), que edita
todas las opciones del plugin con el mismo gate admin, CSRF y whitelist.)
(Migration: `opciones_catalogo` hereda la gestión del ajuste de roles Excel;
regresiones cubiertas por `CatalogoExcelSettingsPageTest` actualizado a la
nueva página y por el shim de compatibilidad en `ArticleExcelAccessPolicy`.)
