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

The base header list above MUST stay byte-identical in content and order. Every
definition with `exportable = TRUE` MUST append one additional column, after
`Bloqueado`, labelled with the definition `nombre`, ordered by `orden` then
`codigo`. A `bool` feature cell MUST render `Sí`/`No`; a `string` feature cell
MUST render its escaped value or be empty when there is no value. Non-exportable
definitions MUST NOT add a column. When no definition is exportable, the emitted
workbook MUST be byte-identical to the pre-change workbook. The base `Descripción`
cell MUST resolve the configured default language (`gestion-idiomas` `GDI-10`).
After `Bloqueado` and after any exportable feature columns, the export MUST
append, for every **active** language, one `descripcion_<codidioma>` column
followed by one `descripcion_corta_<codidioma>` column, ordered **default language
first, then the remaining active languages by `codidioma`** (deterministic). A
**deactivated** language MUST be omitted from new exports. When no extra active
language exists beyond the default, the appended set is the default language's
pair only and the base headers remain unchanged.
(Previously: the export emitted exactly the seven base columns, with `Descripción`
read from `articulos.descripcion`, and — after `caracteristicas-producto` — the
exportable feature columns.)

#### Scenario: Base headers stay byte-identical

- GIVEN any set of feature definitions
- WHEN the export is generated
- THEN the first seven headers are exactly `Referencia, Descripción, Precio, Cód. Familia, Cód. Fabricante, Impuesto, Bloqueado` in that order
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php`

#### Scenario: Exportable feature columns append

- GIVEN two `exportable` definitions ordered second-then-first and one non-exportable definition
- WHEN the export is generated
- THEN exactly two columns are appended after `Bloqueado`, in the definitions' `orden` order, labelled with their `nombre`
- AND the non-exportable definition adds no column
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php`

#### Scenario: No exportable definitions reproduces the base workbook

- GIVEN no `exportable` definition
- WHEN the export is generated
- THEN the workbook is byte-identical to the pre-change export
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php`

#### Scenario: Locale columns append in deterministic order

- GIVEN active languages `es` (default) and `en`, and a deactivated `fr`
- WHEN the export is generated
- THEN `descripcion_es`, `descripcion_corta_es`, `descripcion_en`, `descripcion_corta_en` appear after `Bloqueado` and the feature columns in that order
- AND no `descripcion_fr` / `descripcion_corta_fr` column is emitted
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelIdiomasTest.php`

#### Scenario: A non-default language's text is exported under its suffix

- GIVEN an article whose `en` row differs from its `es` row
- WHEN the export is generated
- THEN each cell carries that language's text
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelIdiomasTest.php`

### Requirement: Import wizard 3 pasos

1. Subir `.xlsx` → token temporal en `tmp/catalogo_excel_wizard_{token}.xlsx`.
2. Mapear columnas → auto-suggest por alias; dropdown por columna.
3. Aplicar vía SSE (`process_excel_wizard.php?action=start`).

Modos: `create_if_missing` (default) y `update_only`. Match key: `referencia`.

Every definition with `importable = TRUE` MUST add one mappable field to the
per-column dropdown, keyed by its `codigo` with its `nombre` plus a lowercase
alias, and MUST be auto-suggested from a matching Excel header. Feature fields
MUST NOT change the auto-suggest, the dropdown contract, the wizard steps, the
match key or the persistence of the base fields, and an unmapped or empty feature
column MUST be a no-op. The wizard MUST also accept an explicit **target language**
for description writes (R5), defaulting to the configured default language
(`gestion-idiomas` `GDI-02`). Every active language's `descripcion_<codidioma>` /
`descripcion_corta_<codidioma>` columns MUST be mappable per column. A header whose
locale suffix does not correspond to a language row, or corresponds to a
**deactivated** language, MUST be **ignored**: it MUST NOT raise an error, MUST NOT
create a language row, and MUST NOT mutate the language registry in any way. The
three steps, the two modes and the `referencia` match key are unchanged.
(Previously: the wizard mapped only the base article fields and a single
`descripcion` field, with no target language, and — after `caracteristicas-producto`
— the `importable` feature fields.)

#### Scenario: Feature fields appear in the mapping dropdown

- GIVEN an `importable` definition
- WHEN step 2 renders a column's `<select>`
- THEN the definition appears as a mappable option keyed by its `codigo`
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php`

#### Scenario: Matching header is auto-suggested

- GIVEN an Excel header matching the definition `nombre` or its lowercase alias
- WHEN step 2 renders
- THEN that column is pre-selected to the feature field
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php`

#### Scenario: Legacy workbook without feature columns imports unchanged

- GIVEN a workbook with no feature column mapped
- WHEN the apply step runs
- THEN the base fields persist exactly as before and feature values are a no-op
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php`

#### Scenario: Target language defaults to the configured default

- GIVEN no explicit target language in the request
- WHEN the import applies a mapped `descripcion_<codidioma>` column
- THEN it writes the configured default language's row
- AND an explicit target language overrides that default for the whole import
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelIdiomasTest.php`

#### Scenario: Unknown or removed locale column is ignored

- GIVEN a file with a `descripcion_zz` column whose locale has no `catalogo_idiomas` row
- WHEN the import is applied
- THEN the column is ignored with no error
- AND no language row is created and the registry is byte-identical
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelIdiomasTest.php`

#### Scenario: Deactivated language column is ignored

- GIVEN a file with a column for a deactivated language
- WHEN the import is applied
- THEN the column is ignored and the registry is unchanged
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelIdiomasTest.php`

#### Scenario: Legacy workbook without locale columns imports unchanged

- GIVEN a workbook with only the seven base columns mapped
- WHEN the apply step runs
- THEN the base fields persist exactly as before and no description row is written
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelIdiomasTest.php`

### Requirement: Persistencia (R-CEXC-003)

- Precio Excel → campo `pvp` (sin IVA).
- Crear: defaults del modelo `articulo` para campos no mapeados.
- Actualizar: solo campos mapeados con valor no vacío.

Additionally, mapped `importable` feature values MUST persist for the tariff
selected in the wizard, using the same scope and normalization rules as the panel
(`caracteristicas-producto` `CAR-04`/`CAR-09`). A rejected feature value MUST
abort that row's feature write with an explicit motivo and MUST NOT corrupt or
drop the base article persistence. Mapped description columns MUST persist to the
**target language's** `articulo_descripciones` row through the language API
(`articulo::set_descripcion_idioma()` or the equivalent), and an empty mapped pair
for a language MUST clear that language's row (`gestion-idiomas` `GDI-07`). The
base `articulos.descripcion` column MUST NOT be written by the language path: it
is the deprecated frozen shim (`gestion-idiomas` `GDI-06`, D1) and is never
mirrored.
(Previously: `Persistencia` covered only the base article fields and — after
`caracteristicas-producto` — the `importable` feature values.)

#### Scenario: Feature values persist for the selected tariff

- GIVEN a mapped, populated `importable` feature column and a selected tariff
- WHEN the apply step runs
- THEN the feature value is stored for that tariff and scope
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php`

#### Scenario: Invalid feature value does not corrupt the base save

- GIVEN a feature value that fails validation for its type
- WHEN the row is applied
- THEN the feature write is rejected with an explicit motivo and the base article fields persist per their own rules
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelFeaturePersistenciaTest.php`

#### Scenario: Mapped description persists for the target language

- GIVEN a mapped, populated `descripcion_<codidioma>` column and a target language
- WHEN the apply step runs
- THEN the text is stored on that language's `articulo_descripciones` row
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelIdiomasTest.php`

#### Scenario: Clearing through import deletes the row

- GIVEN an article with a description row and an empty mapped pair for that language
- WHEN the apply step runs
- THEN the language's row is deleted
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelIdiomasTest.php`

#### Scenario: The base column is never written by the language path

- GIVEN a mapped description for the default language
- WHEN the apply step runs
- THEN `articulos.descripcion` is unchanged
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelIdiomasTest.php`

#### Permiso por fila (R-CEXC-003)

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
