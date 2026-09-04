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

El sistema MUST proporcionar un ajuste solo-admin `catalogo_excel_roles` cuyo
valor sea una lista de códigos de rol (`fs_roles.codrol`) separada por comas que
conceda a usuarios no-admin derechos de import/export Excel del catálogo. MUST
persistirse vía el `fs_settings` existente get/set sobre `$GLOBALS['config2']`;
MUST NO crear tablas nuevas; y la lista concedida MUST validarse contra las
filas `fs_roles` existentes en tiempo de evaluación.

#### Scenario: Admin persiste una lista de roles

- GIVEN un admin en la página de ajustes
- WHEN guarda los códigos de rol `A,B`
- THEN `catalogo_excel_roles` se lee de vuelta como la lista `A,B`
- Test scope: `host-suite`

#### Scenario: Ajuste ausente no concede roles

- GIVEN `catalogo_excel_roles` está ausente (instalación nueva)
- WHEN la lista de concesiones se evalúa para cualquier usuario no-admin
- THEN cero roles se conceden (default fail-closed; acceso solo-admin según R-CEXC-001 en el spec complementario)
- Test scope: `host-suite`

#### Scenario: Valor de ajuste inválido o no parseable no concede roles

- GIVEN `catalogo_excel_roles` contiene un valor que no es una lista separada por comas parseable
- WHEN la lista de concesiones se evalúa
- THEN cero roles se conceden (fail-closed) y la evaluación no produce error
- Test scope: `host-suite`

#### Scenario: Disciplina de nomenclatura (auditable por grep)

- GIVEN los archivos introducidos por esta capacidad
- WHEN se ejecuta `grep -riE 'grupocliente|grupo_clientes|customer[_ ]?group' <archivos nuevos>`
- THEN devuelve cero coincidencias
- Test scope: `host-suite`

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

### Requirement: Página de ajustes solo-admin

El sistema MUST exponer una página de ajustes dedicada `catalogo_excel_settings`
para este ajuste, siguiendo el precedente tpvmod
(`plugins/tpvmod/controller/tpvmod_settings.php`): gate admin a nivel de
framework (`parent::__construct(__CLASS__, ..., 'admin', TRUE, TRUE)`), POST
protegido por CSRF, whitelist de entrada (solo se acepta el campo
`catalogo_excel_roles`) y persistencia set+save. La página MUST NO ser
alcanzable ni escribible por usuarios no-admin.

#### Scenario: Admin puede guardar el ajuste

- GIVEN un usuario admin
- WHEN hace POST de una lista de roles a `catalogo_excel_settings` con un token CSRF válido
- THEN el ajuste se persiste y se devuelve una respuesta de éxito
- Test scope: `host-suite`

#### Scenario: No-admin no puede guardar el ajuste

- GIVEN un usuario no-admin (con o sin concesión Excel)
- WHEN solicita la página o hace POST del ajuste
- THEN el gate admin del framework deniega el acceso (sin render de página, sin persistencia)
- Test scope: `host-suite`

#### Scenario: Whitelist de entrada

- GIVEN un POST a la página de ajustes
- WHEN el request contiene campos distintos del campo de ajuste en whitelist
- THEN se ignoran; solo se persiste `catalogo_excel_roles`
- Test scope: `host-suite`

#### Scenario: Mutación protegida por CSRF

- GIVEN un POST sin token CSRF válido
- WHEN llega a la página de ajustes
- THEN el guardado se rechaza y nada se persiste
- Test scope: `host-suite`