# Delta for articulos-excel-import-export

The three requirements below gain additive, locale-suffixed description columns
and an explicit import target language (D8/R4/R5). The base header list, the three
download modes, the wizard steps, the match key and the persistence rules for the
base fields are **unchanged**. `Permiso can_import_export` is unchanged.

The locale-suffixed header shape is a **local convention**: no retrieved system
uses locale-suffixed headers normatively (PrestaShop and Odoo use language-keyed
containers, S4/S1); the fork's own precedent (`descripcion_es` /
`descripcion_en` in tarifario) does.

## MODIFIED Requirements

### Requirement: Export Excel

MUST ofrecer tres descargas:

1. **Export filtrado** — artículos visibles con los filtros activos de la lista.
2. **Export completo** — todos los artículos.
3. **Plantilla** — columnas base con fila de ejemplo.

Columnas exportadas (orden): Referencia, Descripción, Precio, Cód. Familia,
Cód. Fabricante, Impuesto, Bloqueado.

The seven base headers above MUST stay byte-identical in content and order. The
base `Descripción` cell MUST resolve the configured default language
(`gestion-idiomas` `GDI-10`). After `Bloqueado`, the export MUST append, for every
**active** language, one `descripcion_<codidioma>` column followed by one
`descripcion_corta_<codidioma>` column, ordered **default language first, then the
remaining active languages by `codidioma`** (deterministic). A **deactivated**
language MUST be omitted from new exports. When no extra active language exists
beyond the default, the appended set is the default language's pair only and the
base headers remain unchanged.
(Previously: the export emitted exactly the seven base columns, with `Descripción`
read from `articulos.descripcion`.)

#### Scenario: Base headers stay byte-identical

- GIVEN any set of languages
- WHEN the export is generated
- THEN the first seven headers are exactly `Referencia, Descripción, Precio, Cód. Familia, Cód. Fabricante, Impuesto, Bloqueado` in that order
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelIdiomasTest.php`

#### Scenario: Locale columns append in deterministic order

- GIVEN active languages `es` (default) and `en`, and a deactivated `fr`
- WHEN the export is generated
- THEN `descripcion_es`, `descripcion_corta_es`, `descripcion_en`, `descripcion_corta_en` appear after `Bloqueado` in that order
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

The wizard MUST accept an explicit **target language** for description writes
(R5), defaulting to the configured default language (`gestion-idiomas` `GDI-02`).
Every active language's `descripcion_<codidioma>` / `descripcion_corta_<codidioma>`
columns MUST be mappable per column. A header whose locale suffix does not
correspond to a language row, or corresponds to a **deactivated** language, MUST be
**ignored**: it MUST NOT raise an error, MUST NOT create a language row, and MUST
NOT mutate the language registry in any way. The three steps, the two modes and the
`referencia` match key are unchanged.
(Previously: the wizard mapped only a single `descripcion` field and had no target
language.)

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

### Requirement: Persistencia

- Precio Excel → campo `pvp` (sin IVA).
- Crear: defaults del modelo `articulo` para campos no mapeados.
- Actualizar: solo campos mapeados con valor no vacío.

Additionally, mapped description columns MUST persist to the **target language's**
`articulo_descripciones` row through the language API (`articulo::set_descripcion_idioma()`
or the equivalent), and an empty mapped pair for a language MUST clear that
language's row (`gestion-idiomas` `GDI-07`). The base `articulos.descripcion`
column MUST NOT be written by the language path: it is the deprecated frozen shim
(`gestion-idiomas` `GDI-06`, D1) and is never mirrored.
(Previously: `Persistencia` covered only base article fields.)

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
