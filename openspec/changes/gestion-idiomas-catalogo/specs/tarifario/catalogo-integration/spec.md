# Delta for tarifario/catalogo-integration

One requirement is **ADDED** covering tarifario's no-context description readers
(D10). The canonical spec scopes itself to `R-TAR-HOOK-001…010` and none of those
requirements covers a description read, so there is nothing to re-express as a
`MODIFIED` block — ADDED is the convention-correct section here. The frozen hook
names, the neutral permission-filter extension point, the host-neutrality
requirements, the article/opcional tab surfaces, the familia-override removal, the
currency contract and the strict-TDD mapping are unchanged.

`R-TAR-HOOK-013` is the next free number: `R-TAR-HOOK-011` is reserved by the
unarchived `caracteristicas-producto` delta and `R-TAR-HOOK-012` by the archived
`2026-09-16-mover-tarifa-catalogo-opcionales-a-tarifario` delta. Neither is present
in this canonical spec today.

`R-TAR-CUR-010` of `tarifario/tarifa` is referenced, not redefined.

## ADDED Requirements

### Requirement: R-TAR-HOOK-013 — Tarifario no-context description readers resolve the configured default language

Tarifario readers that have **no** language context MUST resolve descriptions
through the language API (`articulo::get_descripcion_idioma()` with the configured
default language, `gestion-idiomas` `GDI-10`) instead of reading the raw
`articulos.descripcion` column:

- `model/tarif_grupo_articulo.php:150,401-405`;
- `controller/tarif_historial_precios.php:366` (Excel export);
- `controller/tarif_actualizar_precios.php:228` (raw SQL join);
- `controller/tarif_roles.php:718`.

The misused calls in `extras/tarif_controller.php:240,243`
(`$art->descripcion($this->codidioma, 50)` and the nonexistent
`$art->get_descripcion($this->codidioma)`, defect **a**) MUST be corrected to
`descripcion_idioma($this->codidioma, 50)` / `get_descripcion_idioma($this->codidioma)`
or removed, without breaking the four controllers that still `extends
tarif_controller` (`tarif_catalogo_view`, `tarif_historial_precios`, `tarif_roles`,
`tarif_actualizar_precios`).

The readers that are already correct MUST be **verified, not rewritten**:
`Services/ExcelRowUpdater.php:234-290` (`applyDescripcion($value, $art, $codidioma, …)`,
the canonical per-language implementation), `Services/ArticuloListActionHandler.php:685-715,1078-1090,1596-1605`,
`controller/tarif_catalogo_view.php` (`get_descripcion_idioma($this->codidioma)`),
`controller/tarif_configurador_opcionales.php:412`, and the language-agnostic
search in `model/tarif_articulo.php:234-329` (`search_tarifario`, the proven
`LEFT JOIN articulo_descripciones` + `OR` precedent).

The `tarif_idioma` / `tarif_descripcion` legacy aliases (which already extend
`catalogo_idioma` / `articulo_descripcion`) MUST stay loadable and are not
superseded by this change.

#### Scenario: Group-article reads use the configured default

- GIVEN a configured default language different from `es` and a matching description row
- WHEN `tarif_grupo_articulo` returns its articles
- THEN each description is the configured default language's text
- Test: `plugins/tarifario/tests/Model/TarifGrupoArticuloIdiomaTest.php`

#### Scenario: Price-history export uses the configured default

- GIVEN a configured default language and a matching description row
- WHEN `tarif_historial_precios` exports its Excel
- THEN the exported description is the configured default language's text
- Test: `plugins/tarifario/tests/Controller/TarifHistorialPreciosIdiomaTest.php`

#### Scenario: Dead language calls are corrected and subclasses still load

- GIVEN `extras/tarif_controller.php` after the change
- WHEN its source is inspected and the four subclasses are loaded
- THEN neither `descripcion($this->codidioma, 50)` nor `get_descripcion($this->codidioma)` remains
- AND all four subclasses still instantiate without fatal
- Test: `plugins/tarifario/tests/Controller/TarifControllerLanguageCallsTest.php` (grep gate)

#### Scenario: Already-correct readers are unchanged

- GIVEN the already-correct reader set after the change
- WHEN `Services/ExcelRowUpdater.php`, `Services/ArticuloListActionHandler.php`, `controller/tarif_catalogo_view.php`, `controller/tarif_configurador_opcionales.php` and `model/tarif_articulo.php` are inspected
- THEN their description reads and the language-agnostic `search_tarifario` predicate are unchanged
- Test scope: `plugin-suite`

#### Scenario: Legacy aliases stay loadable

- GIVEN tarifario active with its init booted
- WHEN `tarif_idioma` and `tarif_descripcion` are loaded
- THEN both resolve and extend the catalogo_core models
- Test: `plugins/tarifario/tests/Integration/TarifIdiomaLegacyAliasTest.php`
