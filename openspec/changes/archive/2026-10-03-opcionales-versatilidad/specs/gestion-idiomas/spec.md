# Delta for gestion-idiomas

Delta on the canonical `gestion-idiomas` capability owned by `catalogo_core`. It
**adds** one requirement extending the language read chain to the opcional
`nombre`/`descripcion`. No existing requirement (`GDI-01`…`GDI-12`) is changed or
removed. The `catalogo_idiomas` registry and its lifecycle are reused as-is.

## ADDED Requirements

### Requirement: GDI-13 — Opcional name and description multi-language read chain

Opcionales MUST support per-language `nombre` and `descripcion` through a child
table `catalogo_opcional_idiomas` keyed by the opcional's natural `codigo` plus
`codidioma`, with `UNIQUE (codigo, codidioma)` and an app-side cascade on
`codigo` change/delete. The read chain MUST mirror
`articulo::get_descripcion_idioma()`: when no `codidioma` is given, return the
base column; otherwise return the row for the requested language; if absent, the
row for the configured default language; if absent, the base `nombre` /
`descripcion`. Absence MUST NOT materialise a row (no fallback write). A row
whose `nombre` and `descripcion` are both empty MUST be removed on save, mirroring
`articulo_descripcion::save()`.

#### Scenario: Requested language wins

- **GIVEN** an opcional with a Spanish base `nombre` and an English row
- **WHEN** the name is read for `en`
- **THEN** the English name is returned
- Test: `plugins/catalogo_core/tests/CatalogoOpcionalIdiomaTest.php`

#### Scenario: Fallback to the configured default

- **GIVEN** an opcional with rows for the configured default language only
- **WHEN** the name is read for a language with no row
- **THEN** the default-language value is returned
- Test: `plugins/catalogo_core/tests/CatalogoOpcionalIdiomaTest.php`

#### Scenario: Fallback to the base column

- **GIVEN** an opcional with no rows in the child table
- **WHEN** the name is read for any language
- **THEN** the base `catalogo_opcionales.nombre` is returned
- Test: `plugins/catalogo_core/tests/CatalogoOpcionalIdiomaTest.php`

#### Scenario: Empty pair removes the row

- **GIVEN** an existing language row
- **WHEN** both `nombre` and `descripcion` are saved empty
- **THEN** the row is deleted and no empty row remains
- Test: `plugins/catalogo_core/tests/CatalogoOpcionalIdiomaTest.php`

#### Scenario: Editor exposes the language selector without a new page

- **GIVEN** an administrator editing an opcional in `ventas_opcional`
- **WHEN** the editor renders
- **THEN** the language selector is present and saves per-language values through
  the existing CSRF-guarded form
- Test: `plugins/catalogo_core/tests/Controller/VentasOpcionalIdiomaTest.php`
