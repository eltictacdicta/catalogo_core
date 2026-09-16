# Delta for tarifario/catalogo-excel-import-export

The `En Tarifa` / `En Catálogo` mapping is superseded by feature values
(`caracteristicas-producto` D3/D12). Four requirements change:
`Import Excel unificado reconoce En SAP, En Tarifa y En Catálogo`,
`Field catalog — mappable field options (R8)`,
`Create path on match-key miss (R6)` and
`Canary contract del create path (extensión del precedente ExcelHierarchyService)`.

`En SAP`, the three unified modals, the regular export, the visual SAP indicator,
the modal copy, the 3-step wizard, the first-N preview and the apply/audit counter
are unchanged: `En SAP` keeps reading and writing
`tarif_articulo_precios.en_sap`.

## MODIFIED Requirements

### Requirement: Import Excel unificado reconoce En SAP, En Tarifa y En Catálogo

El importador Excel regular (wizard `modal_importar_excel_wizard.html.twig` vía
`process_excel_wizard.php`; `import_excel_chunk` se mantiene como thin wrapper
Resumable.js) MUST seguir aceptando la columna `En SAP` (alias `en sap`, `en_sap`)
y persistir en `tarif_articulo_precios.en_sap` para la tarifa de destino. Además
MUST persistir `en_tarifa` y `en_catalogo` cuando estén mapeadas y pobladas (A4),
**como valores de característica de ámbito artículo para la tarifa seleccionada**
(`catalogo_caracteristica_articulo`, `codigo` de la definición = la clave interna),
no como columnas de `tarif_articulo_precios`. MUST soportar el modo
`default_action=create_if_missing` que crea artículos nuevos desde la misma
pantalla cuando `ref_catalogo` no está en el catálogo (A1 + A2). El match key deja
de ser obligatorio; ver R6 para la semántica completa del create path.

La lista completa de columnas Excel mapeables para `tarif_articulo_precios`:

| Internal key | Excel header (canonical) | Aliases (lowercased, trimmed) | Persisted to | Default for create |
|---|---|---|---|---|
| `precio` | `Precio` | `precio` | `tarif_articulo_precios.precio` | `0.0` |
| `en_sap` | `En SAP` | `en sap`, `en_sap` | `tarif_articulo_precios.en_sap` | `false` |
| `en_tarifa` | `En Tarifa` | `en tarifa`, `en_tarifa` | feature value (article scope, tarifa) | `true` (A4) |
| `en_catalogo` | `En Catálogo` | `en catalogo`, `en_catalogo` | feature value (article scope, tarifa) | `true` (A4) |

(Previously: the two visibility keys persisted to `tarif_articulo_precios`.)

#### Scenario: Import sigue reconociendo En SAP

- GIVEN un Excel con columna `En SAP` y valores `Sí`/`No`
- WHEN el operador importa vía el wizard "Importar Excel"
- THEN `en_sap` se persiste en `tarif_articulo_precios` para la tarifa seleccionada

#### Scenario: Import ahora persiste En Tarifa + En Catálogo como valores de característica

- GIVEN un Excel con columnas `En Tarifa` + `En Catálogo` pobladas
- WHEN el operador importa vía el wizard
- THEN los valores se persisten como valores de característica de ámbito artículo para la tarifa seleccionada
- AND `tarif_articulo_precios` no gana ninguna columna de visibilidad
- AND las filas sin esas columnas mapeadas (legacy 8-col export) siguen importando sin error (no-op para esos campos)
- Test: `plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php`

#### Scenario: Retrocompatibilidad con Excel 8-col legacy

- GIVEN un Excel exportado de una versión previa al wizard (8 columnas, sin `En Tarifa` / `En Catálogo`)
- WHEN el operador lo sube al wizard
- THEN las 8 cabeceras se reconocen por el alias map existente
- AND `en_tarifa` / `en_catalogo` se tratan como vacías en filas existentes (no-op, no error)
- AND para filas nuevas (create path) los defaults `true/true` se aplican como valores de característica por A4

### Requirement: Field catalog — mappable field options (R8)

The list of mappable fields exposed in the per-column `<select>` (R7) SHALL be the
union of selected columns from `articulos` (33 cols), `tarif_articulos` (15 cols),
`tarif_articulo_precios` (5 cols, after losing `en_tarifa`/`en_catalogo`),
`familias` (4 cols), `tarif_familia_ext` (3 cols), the 6 family cells
(cap_familia, cod_familia, familia, cap_subfamilia, cod_subfamilia, subfamilia)
collapsed from the 14-col export, and the `importable` feature definitions keyed
by their `codigo` (`caracteristicas-producto` `CAR-17`). Auto-managed fields MUST
be excluded (`id`, `codtarifa`, `created_at`, `updated_at`, and internal flags).
The list SHALL be exposed as a public readonly array
`ExcelImportWizardService::FIELD_CATALOG` so tests can iterate it without
instantiating the service.
(Previously: `tarif_articulo_precios` contributed 7 columns, including the two
visibility flags; no feature-derived fields existed.)

#### Scenario: testFieldCatalog_includesLegacyFields

- GIVEN the operator inspects `ExcelImportWizardService::FIELD_CATALOG`
- WHEN the test iterates the array
- THEN the 8 legacy fields are present: `ref`, `ref_catalogo`, `configurador`, `descripcion`, `descripcion_en`, `precio`, `grupo`, `en_sap`

#### Scenario: testFieldCatalog_includesEnTarifaEnCatalogo

- GIVEN the operator inspects `ExcelImportWizardService::FIELD_CATALOG`
- WHEN the test iterates the array
- THEN `en_tarifa` and `en_catalogo` are present as feature-backed fields (per A4)
- AND they are not presented as `tarif_articulo_precios` columns
- Test: `plugins/tarifario/tests/Services/ExcelImportWizardFieldCatalogTest.php`

#### Scenario: testFieldCatalog_includesFamilyCells

- GIVEN the operator inspects `ExcelImportWizardService::FIELD_CATALOG`
- WHEN the test iterates the array
- THEN the 6 family cells are present: `cap_familia`, `cod_familia`, `familia`, `cap_subfamilia`, `cod_subfamilia`, `subfamilia`

#### Scenario: testFieldCatalog_includesCodigo

- GIVEN the operator inspects `ExcelImportWizardService::FIELD_CATALOG`
- WHEN the test iterates the array
- THEN `codigo` is present (used as PK source per A3)

#### Scenario: testFieldCatalog_excludesAutoManaged

- GIVEN the operator inspects `ExcelImportWizardService::FIELD_CATALOG`
- WHEN the test iterates the array
- THEN `id`, `codtarifa`, `created_at`, `updated_at`, and internal boolean flags are NOT present

#### Scenario: testFieldCatalog_iterable

- GIVEN a PHPUnit test iterating `ExcelImportWizardService::FIELD_CATALOG`
- WHEN the test asserts the expected set of field keys is present
- THEN the assertion passes without instantiating the service (the field is a public class constant, `public const FIELD_CATALOG`)

### Requirement: Create path on match-key miss (R6)

The wizard SHALL support a `default_action` field with two values:
`create_if_missing` (default per A1) and `update_only` (legacy behavior). When
`default_action=create_if_missing` AND the match key (`ref_catalogo`) is mapped
but absent from the catálogo, the wizard MUST create a new article via
`ExcelImportWizardService::createArticuloFromRow($row, $mapping, $codtarifa): tarif_articulo`.
The `referencia` (PK) MUST be set from Excel `codigo` when mapped and non-empty,
otherwise MUST be generated via `articulo::get_new_referencia()` (per A3). For new
articles, the defaults SHALL be `en_tarifa=true`, `en_catalogo=true` (per A4)
**persisted as article-scope feature values for `$codtarifa`**, plus
`nostock=true`, `sevende=true`, `secompra=false`, `bloqueado=false`. The
`tarif_articulo_precios` row keeps only `precio`/`activo`/`en_sap`. When
`default_action=update_only` AND the match key is unmapped or empty, the row MUST
be discarded with motivo `match_key_ausente` (per A2). The match for update
remains by `ref_catalogo` (NOT by `referencia`).
(Previously: the create path wrote `en_tarifa=true` + `en_catalogo=true` as
`tarif_articulo_precios` columns.)

#### Scenario: testCreatePathOnMatchKeyMiss_createIfMissing

- GIVEN `default_action=create_if_missing` AND `ref_catalogo` is mapped + present + absent from catálogo AND the row has `descripcion`
- WHEN the row is processed
- THEN a new `articulo` is created
- AND a new `tarif_articulo` is created for the selected `codtarifa`
- AND a new `tarif_articulo_precios` is created with `en_sap=false` and `activo=true`
- AND article-scope feature values `en_tarifa=true` and `en_catalogo=true` are stored for `$codtarifa`
- AND the `creados` counter in the result modal increments by 1

#### Scenario: testCreatePathOnMatchKeyMiss_updateOnly

- GIVEN `default_action=update_only` AND `ref_catalogo` is mapped + present + absent from catálogo
- WHEN the row is processed
- THEN the row is discarded with motivo `pendiente_alta_tarif` (legacy behavior preserved)
- AND a row is appended to `descartadas.csv` with that motivo

#### Scenario: testCreatePath_refCatalogoNotMapped_createIfMissing

- GIVEN `default_action=create_if_missing` AND `ref_catalogo` is NOT mapped (column set to "Ignorar esta columna")
- WHEN the row is processed
- THEN a new article is created directly (no update attempted)
- AND the `creados` counter increments by 1

#### Scenario: testCreatePath_refCatalogoNotMapped_updateOnly

- GIVEN `default_action=update_only` AND `ref_catalogo` is NOT mapped
- WHEN the row is processed
- THEN the row is discarded with motivo `match_key_ausente` (new motivo, per A2)
- AND a row is appended to `descartadas.csv` with that motivo

#### Scenario: testCreatePath_referenciaFromExcelCodigo

- GIVEN the create path runs AND `codigo` is mapped AND non-empty (value `8412345`)
- WHEN the article is created
- THEN `articulos.referencia = '8412345'`
- AND `get_new_referencia()` is NOT called

#### Scenario: testCreatePath_referenciaFallbackGetNew

- GIVEN the create path runs AND `codigo` is empty or not mapped
- WHEN the article is created
- THEN `articulos.referencia` is set to the numeric string returned by `articulo::get_new_referencia()`
- AND the new `referencia` is unique (no collision with existing articles)

#### Scenario: testCreatePath_defaultsEnTarifaEnCatalogo

- GIVEN the create path runs AND `en_tarifa` / `en_catalogo` are not mapped or empty
- WHEN the article is created
- THEN article-scope feature values `en_tarifa = true` and `en_catalogo = true` exist for `$codtarifa`
- AND `tarif_articulo_precios.en_sap = false`
- Test: `plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php`

### Requirement: Canary contract del create path (extensión del precedente ExcelHierarchyService)

A canary test for `ExcelImportWizardService::createArticuloFromRow` MUST exist at
`plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php` mirroring the
precedent in `ExcelHierarchyServiceTest`. The canary MUST assert the create path
writes to `articulos`, `tarif_articulos`, and `tarif_articulo_precios` for the
selected `codtarifa`, with `en_tarifa=true` + `en_catalogo=true` stored as
article-scope feature values for that tarifa and `en_sap=false` on the price row.
If the canary fails, the change MUST NOT be archived.
(Previously: the canary asserted `en_tarifa`/`en_catalogo` as
`tarif_articulo_precios` defaults.)

#### Scenario: testCanaryCreatePathWritesFeatureDefaults

- GIVEN a valid create-path row for a selected `codtarifa`
- WHEN `createArticuloFromRow` runs
- THEN `articulos`, `tarif_articulos` and `tarif_articulo_precios` rows exist for that tarifa with `en_sap=false`
- AND `en_tarifa=true` and `en_catalogo=true` are stored as article-scope feature values for that tarifa
- Test: `plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php`
