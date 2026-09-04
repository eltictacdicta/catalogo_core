# Artículos — Permiso de creación rápida (plugin catalogo_core)

## Purpose

El formulario de creación rápida de la página de lista de artículos (`POST nreferencia` → `VentasArticulos::nuevoArticulo()`) escribe `pvp` sin gate de permisos ni dispatch de `ArticlePermissionFilterEvent` — solo se requiere acceso a la página. Un editor sin concesión (o con concesión pero sin asignación) puede fijar precios de artículo aquí, aunque la misma escritura de precio en la página de artículo (`VentasArticulo`) y en cada fila de import Excel ya está gateada por el contrato de permisos neutral congelado. Esta capacidad enruta la escritura de `pvp` de creación rápida a través del mismo contrato: dispatch antes de la mutación/save para usuarios no-admin, admin sin dispatch, la denegación bloquea toda la creación con un motivo visible para el usuario, semántica fail-closed ante excepción de listener y allow por defecto neutral al host.

El contrato congelado `ArticlePermissionFilterEvent` se consume, nunca se modifica. Cierra el hallazgo de review RISK-2 (congelado en el verify-report archivado de `2026-09-04-catalogo-excel-access-control`) y la deferencia R-TAR-HOOK-003 para el camino de creación rápida.

## Convenciones de test

- `host-suite` = `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- `root-suite` = `ddev exec php vendor/bin/phpunit` (auto-descubre los tests del plugin; una corrida root en verde es la señal de regresión cross-plugin, incl. el listener real de tarifario)

## Requirements

### Requirement: Dispatch de creación rápida no-admin antes de la mutación de pvp (R-QCRT-001)

Para cada request de creación rápida de un usuario no-admin, el sistema MUST despachar el contrato congelado `ArticlePermissionFilterEvent` (nombre de evento `catalogo_core.article_permission_filter`, acción `ACTION_EDIT_ARTICLE`, portando la `referencia` enviada y el `nick` del usuario actual, sin tarifa) en un único punto de dispatch en `VentasArticulos::nuevoArticulo()` ubicado después de la validación de referencia/descripción y de la comprobación de referencia duplicada y ANTES de cualquier mutación de `pvp` y antes de `save()`, espejando el orden de dispatch de la página de artículo (`Controller/VentasArticulo.php`).

#### Scenario: El dispatch no-admin se dispara antes de la mutación de pvp y del save

- GIVEN un usuario no-admin enviando el formulario de creación rápida (`POST nreferencia`) con un token CSRF válido y una referencia única
- WHEN `nuevoArticulo()` procesa el request
- THEN el evento congelado se despacha una vez antes de cualquier mutación de `pvp` y antes de `save()`
- AND una denegación en ese punto hace inalcanzable el `save`
- Test scope: `host-suite`

### Requirement: Dominancia admin — cero dispatch (R-QCRT-002)

El sistema MUST omitir por completo el dispatch para usuarios admin en `nuevoArticulo()`: el evento nunca se construye ni se despacha, y el resultado del admin MUST NO depender de la correctitud de los listeners (dominancia admin, precedente `ArticuloExcelPermissionGate` AD-5).

#### Scenario: Creación rápida admin con cero dispatch

- GIVEN un usuario admin
- WHEN crea rápidamente un artículo con precio vía el formulario de la lista
- THEN el artículo se crea exactamente como hoy y el evento nunca se construye ni se despacha (cero dispatch), independientemente de los listeners registrados
- Test scope: `host-suite`

### Requirement: La denegación bloquea toda la creación con un motivo visible para el usuario (R-QCRT-003)

Ante un veredicto de denegación, el sistema MUST bloquear toda la creación rápida: sin `save()`, sin fila de artículo, sin estado parcial — el objeto artículo nunca se persiste. El sistema MUST exponer un `new_error_msg` visible para el usuario que incluya `getDenialReason()` (en español, acorde a la convención de mensajes del controller) y la lista MUST re-renderizarse con el mensaje (el re-render del formulario no cambia). No existe fallback de "crear sin precio": `npvp` por defecto es `0.0`, por lo que una creación denegada se bloquea por completo en lugar de producir un artículo sin precio.

#### Scenario: Editor sin asignación denegado, el artículo nunca se crea

- GIVEN un editor no-admin sin concesión (un artículo nuevo aún no puede tener una fila `tarif_grupo_articulos`)
- WHEN envía el formulario de creación rápida con un precio
- THEN el listener deniega con su motivo y toda la creación se bloquea: el save del artículo nunca se llama y no se crea ninguna fila de artículo (el modelo no se guarda / el estado de la BD no cambia)
- AND `new_error_msg` lleva el motivo de la denegación y la lista re-renderiza con el mensaje
- Test scope: `host-suite`

#### Scenario: npvp por defecto 0.0 aún deniega toda la creación

- GIVEN un editor no-admin denegado enviando el formulario sin `npvp` explícito (por defecto 0.0)
- WHEN el request se procesa
- THEN la creación se bloquea igualmente por completo (sin fila de artículo sin precio, sin estado parcial) — la denegación bloquea toda la creación, nunca crea-sin-precio
- Test scope: `host-suite`

#### Scenario: Gestor permitido

- GIVEN un gestor en cualquier tarifa activa
- WHEN crea rápidamente con un precio
- THEN el listener resuelve allow y el artículo se crea con el `pvp` enviado exactamente como antes del cambio
- Test scope: `host-suite`

### Requirement: Excepción de listener fail-closed, sin error_log (R-QCRT-004)

El sistema MUST fallar cerrado cuando un listener lanza: capturando `\Throwable` alrededor del dispatch en `nuevoArticulo()` y denegando con el motivo genérico `permission filter error`. El sistema MUST NO escribir en `error_log` en este camino (el host suite corre con `processIsolation=true`; según el precedente `ArticuloExcelPermissionGate`, un `error_log` aparece como error de test).

#### Scenario: Un listener que lanza deniega fail-closed

- GIVEN un listener registrado que lanza al evaluar
- WHEN se procesa una creación rápida no-admin
- THEN la creación se deniega con el motivo genérico `permission filter error`, no se produce salida `error_log` y el suite sigue verde bajo `processIsolation=true`
- Test scope: `host-suite`

### Requirement: Neutralidad del host — cero listeners, allow por defecto (R-QCRT-005)

El sistema MUST resolver a allow cuando no hay ningún listener registrado para el evento: un despliegue sin tarifario conserva el comportamiento de creación rápida previo al cambio.

#### Scenario: Cero listeners, allow por defecto

- GIVEN un despliegue con cero listeners para `catalogo_core.article_permission_filter` (tarifario inactivo)
- WHEN un usuario no-admin crea rápidamente un artículo con precio
- THEN la creación tiene éxito exactamente como antes del cambio
- Test scope: `host-suite`

### Requirement: CSRF sin cambios (R-QCRT-006)

El sistema MUST NO agregar mecanismos de token: el formulario de creación rápida ya renderiza `{{ csrf_field() }}` (`View/partials/articulos/modal_nuevo_articulo.html.twig:5`) y `nuevoArticulo()` ya valida vía `validateFormToken()` como su primera sentencia (`Controller/VentasArticulos.php:446`), antes de cualquier lógica de creación. Un token inválido MUST seguir bloqueando antes de cualquier dispatch o trabajo de creación.

#### Scenario: El fallo CSRF sigue bloqueando antes de cualquier lógica de creación

- GIVEN un POST sin token CSRF válido
- WHEN llega a `nuevoArticulo()`
- THEN `validateFormToken()` lo rechaza antes de cualquier dispatch o trabajo de creación y no se crea ningún artículo (flujo existente, sin cambios)
- Test scope: `host-suite`

### Requirement: Sin superficie nueva — ortogonalidad con el gate Excel (R-QCRT-007)

El dispatch de creación rápida MUST vivir SOLO en `VentasArticulos::nuevoArticulo()`. El cambio MUST NO introducir nuevas constantes de evento, tablas de permisos, concesiones por usuario, ediciones de plantilla ni cambios JS. Las demás rutas de creación — la creación por página de artículo (`Controller/VentasArticulo.php`, ya gateada) y la rama Excel `create_if_missing` (`process_excel_wizard_dispatch.php`, ya gateada) — MUST permanecer intactas; el gate de creación rápida y el gate Excel se mantienen ortogonales.

#### Scenario: El gate Excel y la página de artículo intactos

- GIVEN el cambio implementado
- WHEN se ejecutan las rutas de import/export Excel y la ruta de edición por página de artículo
- THEN se comportan exactamente como antes (el gate Excel no se toca y no se aplica ningún dispatch a rutas distintas de `nuevoArticulo`)
- Test scope: `host-suite`

#### Scenario: Sin constantes, tablas, concesiones ni ediciones de UI nuevas

- GIVEN el diff del cambio
- WHEN se auditan las líneas cambiadas
- THEN no se introducen constantes de evento, tablas de permisos, concesiones por usuario, ediciones de plantilla ni cambios JS
- Test scope: `host-suite`

### Requirement: Disciplina de test comportamental (STRICT TDD)

Los tests de esta capacidad MUST ser comportamentales: un listener real registrado en `FSEventDispatcher` y conducido a través del camino real de dispatch de `nuevoArticulo` (siguiendo el patrón de `tests/ArticlePermissionFilterDispatchTest.php`). El camino de denegación MUST afirmarse ejecutándolo — save nunca llamado, sin artículo persistido; el camino admin MUST afirmar cero dispatch (evento nunca construido). Los tests MUST NO fijar estructura de código fuente (sin pins strpos/línea/string sobre el controller) — la lección REL-2 del verify-report archivado.

#### Scenario: El camino de denegación se ejecuta, el save nunca se llama

- GIVEN `tests/VentasArticulosQuickCreateGateTest.php` con un listener denegante registrado
- WHEN el camino de creación rápida se conduce a través del dispatch real
- THEN el test afirma que la creación nunca persiste (el save del artículo es inalcanzable / no hay fila de artículo) — orden probado por ejecución, no por pins de string
- Test scope: `host-suite`

#### Scenario: El camino admin afirma cero dispatch

- GIVEN el mismo archivo de test conduciendo el camino de creación rápida admin
- WHEN corre
- THEN afirma que el evento nunca se construye/despacha (cero dispatch), no meramente que ningún listener deniega
- Test scope: `host-suite`

#### Scenario: Sin pins estructurales

- GIVEN el suite de tests
- WHEN cualquier test inspecciona el gate
- THEN ejercita el comportamiento de dispatch con listeners reales y nunca afirma strings de código fuente ni números de línea (lección REL-2)
- Test scope: `host-suite`

#### Scenario: Composición cross-plugin con el listener de tarifario

- GIVEN el `ArticlePermissionListener` real de tarifario activo en el entorno root
- WHEN corre el root-suite
- THEN la creación rápida resuelve gestor = allow y editor sin asignación = deny a través del dispatcher compuesto, y la corrida root sigue verde (señal de regresión cross-plugin)
- Test scope: `root-suite`