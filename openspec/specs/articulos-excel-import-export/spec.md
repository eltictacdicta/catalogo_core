# Artículos — Import/Export Excel (plugin catalogo_core)

## Purpose

Importación y exportación Excel de artículos en `index.php?page=ventas_articulos`.
Campos principales: referencia, descripción, precio (PVP sin IVA). Columnas
opcionales mapeables: familia, fabricante, impuesto, bloqueado.

## Convenciones de test

- `host-suite` = `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- `root-suite` = `ddev exec php vendor/bin/phpunit` (auto-descubre los tests del plugin; una corrida root en verde es la señal de regresión cross-plugin)

## Requirements

### Requirement: Permiso can_import_export (R-CEXC-001, R-CEXC-004)

La página `ventas_articulos` MUST exponer import/export solo cuando
`can_import_export === true`, y `can_import_export` MUST redefinirse como el
**veredicto central de la política de acceso**: `true` para usuarios admin; en
otro caso `true` solo si el usuario posee un rol concedido mediante la
capacidad `articulos-excel-access-settings` (ajuste `catalogo_excel_roles`).
**Por defecto (sin ajuste configurado): solo-admin**, para ambas direcciones,
import y export. (Antes: `admin || have_access_to('ventas_articulos')` —
tautológico, porque la propia página ya exige ese acceso.)

La política MUST aplicarse en CADA punto de entrada de la tabla siguiente, y
los intentos no autorizados MUST recibir una respuesta explícita de denegación
(403 o redirect — nunca un no-op silencioso ni un fall-through):

| Punto de entrada | Gate hoy |
|---|---|
| Acciones embebidas vía `VentasArticulos::processExcelAction()` — `preview_excel`, `get_preview`, `export_excel`, `export_excel_filtered`, `export_excel_template`, `download_import_log`, `excel_import_sse` | `can_import_export` tautológico |
| Rutas del servicio de export (`exportExcel()` / `exportExcelTemplate()`) | Hoy solo alcanzables vía acciones con gate |
| Descarga del log de import (`download_import_log`) | Tautológico |
| Stream SSE embebido (`excel_import_sse`) | Tautológico al inicio del stream |
| Endpoint standalone por URL directa `process_excel_wizard.php` → `catalogo_excel_wizard_run(false)` (`process_excel_wizard_dispatch.php`; acciones `start`, `progress`, `status`) | Solo login + CSRF-on-`start` — **el agujero**: cualquier usuario logueado puede ejecutar imports por URL directa |

El endpoint standalone MUST aplicar la misma política fail-closed en todas sus
acciones, respondiendo con un 403/denegación adecuado en lugar de caer en el
manejo del wizard. Los gates de UI en Twig (bloques `{% if %}` condicionados a
`can_import_export`) MUST coincidir con las comprobaciones del servidor (ver
R-CEXC-007).

#### Scenario: Default-deny para no-admin sin ajuste

- GIVEN ningún ajuste `catalogo_excel_roles` configurado y un usuario no-admin logueado
- WHEN el usuario invoca CUALQUIER punto de entrada de la tabla anterior
- THEN cada uno devuelve una respuesta explícita de denegación (403/redirect, nunca silenciosa) y no se ejecuta ningún import/export
- Test scope: `host-suite`

#### Scenario: Usuario con rol concedido permitido

- GIVEN un rol concedido vía `catalogo_excel_roles` y un usuario no-admin que posee ese rol
- WHEN el usuario invoca cada punto de entrada
- THEN cada uno procede con el comportamiento de import/export previo al cambio
- Test scope: `host-suite`

#### Scenario: Admin permitido en todas partes

- GIVEN un usuario admin y ningún ajuste configurado
- WHEN el admin invoca cada punto de entrada (acciones embebidas, exports, descarga de log, SSE embebido, `start`/`progress`/`status` standalone)
- THEN todos proceden
- Test scope: `host-suite`

#### Scenario: Concesión revocada a mitad de sesión

- GIVEN un no-admin concedido con sesión viva cuya concesión se elimina de `catalogo_excel_roles` (o cuyo rol concedido se borra de `fs_roles`)
- WHEN su SIGUIENTE request llega a cualquier punto de entrada
- THEN es denegado
- Test scope: `host-suite`

#### Scenario: Endpoint standalone 403 para URL directa no concedida

- GIVEN un no-admin logueado no cubierto por la política
- WHEN se llama a `process_excel_wizard.php?action=start` (o `progress`, `status`) por URL directa con sesión válida y — para `start` — un token CSRF válido
- THEN el endpoint responde 403 JSON con un error de denegación claro y nunca alcanza el manejo del wizard
- Test scope: `host-suite`

### Requirement: Export Excel

MUST ofrecer tres descargas:

1. **Export filtrado** — artículos visibles con los filtros activos de la lista.
2. **Export completo** — todos los artículos.
3. **Plantilla** — columnas base con fila de ejemplo.

Columnas exportadas (orden): Referencia, Descripción, Precio, Cód. Familia,
Cód. Fabricante, Impuesto, Bloqueado.

### Requirement: Import wizard 3 pasos

1. Subir `.xlsx` → token temporal en `tmp/catalogo_excel_wizard_{token}.xlsx`.
2. Mapear columnas → auto-suggest por alias; dropdown por columna.
3. Aplicar vía SSE (`process_excel_wizard.php?action=start`).

Modos: `create_if_missing` (default) y `update_only`. Match key: `referencia`.

### Requirement: Persistencia (R-CEXC-003)

- Precio Excel → campo `pvp` (sin IVA).
- Crear: defaults del modelo `articulo` para campos no mapeados.
- Actualizar: solo campos mapeados con valor no vacío.
- Filtro de permiso por fila: para un llamador no-admin, TODA fila importada
  cuyo mapeo escriba `pvp` (ruta de creación o actualización) MUST despachar el
  `ArticlePermissionFilterEvent` del host (nombre congelado
  `catalogo_core.article_permission_filter`, portando la `referencia` de la
  fila, el `nick` del usuario y la acción con gate; la constante de acción de
  import es una decisión de sdd-design junto con la congelada
  `ACTION_EDIT_ARTICLE`) **antes** de la mutación/`save` de `pvp`. Las filas
  denegadas se OMITEN (no es fatal): la fila cuenta como descartada y el
  `getDenialReason()` provisto por el listener se escribe en el CSV de filas
  descartadas (`catalogo_import_*_descartadas.csv`). La semántica fail-closed
  existente de excepción de listener se aplica por fila: un listener que lanza
  al evaluar una fila deniega solo esa fila; el import continúa. Las filas de
  llamadores admin pasan por la ruta admin de su propia semántica de permisos
  (nunca denegadas por este filtro).

(Antes: las escrituras de `pvp` se persistían incondicionalmente con cero
dispatch del filtro de permisos — un usuario concedido (o, pre-cambio,
cualquiera) podía reescribir precios que el filtro por fila denegaría en la
página de artículo.)

#### Scenario: Fila denegada omitida con motivo en el log de import

- GIVEN un importador no-admin cuyo mapeo incluye `pvp` y un listener que deniega la fila R con motivo `X`
- WHEN el bucle de import procesa R
- THEN R se omite (sin create/update, sin `save` para R), el import continúa con las filas restantes, y R aparece en el CSV de descartadas con el motivo `X`
- Test scope: `host-suite`

#### Scenario: El filtro se dispara antes de la mutación de pvp y del save

- GIVEN una fila con `pvp` mapeado procesada por un importador no-admin
- WHEN la fila se procesa
- THEN el evento se despacha antes de cualquier mutación de `pvp` y antes de `save()`, y una denegación hace inalcanzable el `save` de la fila
- Test scope: `host-suite`

#### Scenario: Fila permitida aplica pvp

- GIVEN ningún listener deniega
- WHEN una fila con `pvp` mapeado es procesada por un importador no-admin
- THEN la fila se aplica exactamente como en el comportamiento previo al cambio
- Test scope: `host-suite`

#### Scenario: Las filas admin pasan por la ruta admin

- GIVEN un importador admin
- WHEN se procesan filas con `pvp` mapeado
- THEN ninguna fila es denegada por el filtro por fila (la ruta admin domina), independientemente de los listeners
- Test scope: `host-suite`

#### Scenario: La excepción de listener falla cerrado por fila

- GIVEN un listener que lanza al evaluar la fila R
- WHEN R es procesada por un importador no-admin
- THEN R se trata como denegada (omitida + reportada con el motivo fail-closed) y las filas restantes continúan
- Test scope: `host-suite`

### Requirement: Verificación de autorización SSE por evento de stream (R-CEXC-005)

El stream SSE de import embebido (`excel_import_sse`) y el flujo SSE standalone
MUST re-verificar la política de acceso por evento de stream — como mínimo al
inicio del stream Y en cada evento posterior — de modo que un stream de larga
duración no pueda sobrevivir a una revocación. Una re-verificación fallida MUST
terminar el stream con una denegación clara. Se permite cachear por request el
conjunto de roles evaluado; la caducidad cross-request no está permitida.

#### Scenario: Denegación al inicio del stream

- GIVEN un usuario no concedido
- WHEN el stream SSE comienza
- THEN se deniega antes de emitir cualquier evento de import
- Test scope: `host-suite`

#### Scenario: Revocación a mitad de stream

- GIVEN un usuario concedido con un stream en curso cuya concesión se revoca
- WHEN la re-verificación del siguiente evento del stream se ejecuta
- THEN el stream termina con una denegación clara y no ocurre ningún procesamiento de import adicional
- Test scope: `host-suite`

### Requirement: Los gates de UI reflejan la política (R-CEXC-007)

Las plantillas Twig de `ventas_articulos` (menú Excel y los modales de
export/import, condicionados a `can_import_export`) MUST reflejar la política
real: ocultos exactamente cuando la política del servidor deniega, visibles
cuando concede. Los gates de plantilla y los gates del servidor MUST nunca
discrepar.

#### Scenario: Oculto cuando se deniega

- GIVEN un usuario al que la política deniega
- WHEN la página de listado se renderiza
- THEN el menú de import/export Excel y los modales no se renderizan (el gate de plantilla coincide con el gate del servidor)
- Test scope: `host-suite`

#### Scenario: Visible cuando se concede

- GIVEN un usuario concedido o admin
- WHEN la página de listado se renderiza
- THEN el menú Excel y los modales se renderizan
- Test scope: `host-suite`