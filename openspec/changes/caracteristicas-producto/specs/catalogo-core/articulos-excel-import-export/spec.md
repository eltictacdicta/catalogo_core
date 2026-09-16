# Delta for articulos-excel-import-export

The three requirements below gain additive, flag-driven feature columns. The base
header list, the base field catalog, the three download modes, the wizard steps,
the match key and the persistence rules are **unchanged**; the feature columns are
declared by `caracteristicas-producto` (`CAR-17`) and only referenced here.

`Permiso can_import_export` is unchanged.

## MODIFIED Requirements

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
workbook MUST be byte-identical to the pre-change workbook.
(Previously: the export emitted exactly the seven base columns.)

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
column MUST be a no-op.
(Previously: the wizard mapped only the base article fields.)

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

### Requirement: Persistencia

- Precio Excel → campo `pvp` (sin IVA).
- Crear: defaults del modelo `articulo` para campos no mapeados.
- Actualizar: solo campos mapeados con valor no vacío.

Additionally, mapped `importable` feature values MUST persist for the tariff
selected in the wizard, using the same scope and normalization rules as the panel
(`caracteristicas-producto` `CAR-04`/`CAR-09`). A rejected feature value MUST
abort that row's feature write with an explicit motivo and MUST NOT corrupt or
drop the base article persistence.
(Previously: `Persistencia` covered only base article fields.)

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
