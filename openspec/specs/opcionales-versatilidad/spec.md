# opcionales-versatilidad Specification

## Purpose

New capability owned by `catalogo_core`. All requirements are ADDED; there is no
prior canonical `opcionales-versatilidad` spec. Scope: the per-opcional
`cantidad_max` store and semantics, the single image store/serve/editor flow, and
the bulk export/import parity that keeps richer opcionales movable between
environments. Multiidioma `nombre`/`descripcion` is specified as a delta on
`gestion-idiomas`; conditional rules are specified in `flujos-condicionales`.

The opcional list surface (`View/ventas_opcionales.html.twig`) and the
`opcionales-management` spec are **out of bounds** for this change: a concurrent
change owns them. New fields surface in the canonical opcional editor
(`ventas_opcional`) only.

## Requirements

### Requirement: OVE-01 — `cantidad_min` / `cantidad_max` store and validation

`catalogo_opcionales` MUST have `cantidad_min` and `cantidad_max` integer
columns, both `NOT NULL` defaulting to `1`. The model MUST normalize an absent
value to `1`, MUST reject `cantidad_min < 0`, MUST reject `cantidad_max < 1`, and
MUST reject `cantidad_min > cantidad_max`. The defaults mean "exactly one unit";
raising `cantidad_max` enables selecting up to that many units, and raising
`cantidad_min` requires at least that many. `cantidad_min = 0` means "no minimum".

#### Scenario: Defaults are one

- **GIVEN** an opcional created without explicit quantities
- **WHEN** it is persisted and reloaded
- **THEN** `cantidad_min` is `1` and `cantidad_max` is `1`
- Test: `plugins/catalogo_core/tests/CatalogoOpcionalVersatilidadTest.php`

#### Scenario: Invalid ranges are rejected

- **GIVEN** an opcional with `cantidad_max = 0` (or negative), or with
  `cantidad_min > cantidad_max`
- **WHEN** `test()` runs
- **THEN** validation fails with an error and nothing is persisted
- Test: `plugins/catalogo_core/tests/CatalogoOpcionalVersatilidadTest.php`

### Requirement: OVE-02 — `cantidad_min` / `cantidad_max` apply to loose and grouped opcionales

Both quantities MUST be properties of the opcional itself and MUST apply
identically whether the opcional is loose (directly assigned to an article) or a
value inside a group. The effective range MUST be exposed to consumers (TPV /
clientes) through the existing opcional read paths, without a new page or query
per opcional.

#### Scenario: Grouped value exposes the same range

- **GIVEN** an opcional with `cantidad_min = 2`, `cantidad_max = 5` that belongs to
  a group assigned to an article
- **WHEN** the article's selectable opcionales are resolved
- **THEN** the opcional reports `cantidad_min = 2` and `cantidad_max = 5`
- **AND** a loose assignment of the same opcional reports the same values
- Test: `plugins/catalogo_core/tests/CatalogoOpcionalVersatilidadTest.php`

### Requirement: OVE-03 — Single image store and serve

An opcional MUST store at most one image as a filename on
`catalogo_opcionales.imagen`, physically held under `imgs/opcionales/`. The value
MUST be a bare filename (no path, no URL). An `imagen_url()` accessor MUST return
the relative `imgs/opcionales/<filename>` path, or an empty string when absent.
The upload MUST accept only `image/jpeg`, `image/png`, `image/gif` and
`image/webp`, validated by content (`finfo`), never by extension alone.

#### Scenario: Stored value is a bare filename

- **GIVEN** an opcional with an uploaded image
- **WHEN** it is persisted and reloaded
- **THEN** `imagen` contains only the filename and `imagen_url()` returns the
  `imgs/opcionales/` relative path
- Test: `plugins/catalogo_core/tests/OpcionalImagenServiceTest.php`

#### Scenario: Non-image content is rejected

- **GIVEN** an upload whose detected MIME type is not in the allow-list
- **WHEN** the image service processes it
- **THEN** it is rejected and no file or row is written
- Test: `plugins/catalogo_core/tests/OpcionalImagenServiceTest.php`

### Requirement: OVE-04 — Image upload, replace and remove in the canonical editor

The canonical opcional editor (`page=ventas_opcional`,
`Controller/VentasOpcional.php` + `View/ventas_opcional.html.twig`) MUST support
uploading, replacing and removing the opcional image. Mutations MUST be
CSRF-guarded and MUST be served through the existing htmx flow. Removing or
replacing an image MUST delete the previous physical file. The list surface
(`ventas_opcionales`) MUST NOT be modified by this change.

#### Scenario: Replace removes the previous file

- **GIVEN** an opcional with an existing image
- **WHEN** a new image is uploaded
- **THEN** the new filename is persisted and the previous file is deleted
- Test: `plugins/catalogo_core/tests/Controller/VentasOpcionalImagenTest.php`

### Requirement: OVE-05 — Bulk export parity for opcionales

The opcionales export MUST include every new field: `cantidad_max`, `imagen`,
`nombre_<codidioma>` / `descripcion_<codidioma>` (default language first), plus
group memberships, family assignments and per-lista prices. The export MUST be
read-only, MUST NOT write any row, and MUST keep the deterministic column order
used by the article export.

#### Scenario: Export round-trips the new fields

- **GIVEN** an opcional with quantity, image, groups, families and two language
  descriptions
- **WHEN** it is exported
- **THEN** every one of those values appears in its expected column
- **AND** no row is written or modified by the export
- Test: `plugins/catalogo_core/tests/OpcionalExcelExportTest.php`

### Requirement: OVE-06 — Bulk import wizard parity and idempotency

The opcionales import MUST reuse the article wizard pattern (field catalog,
header mapping, preview, locale resolution) and MUST be idempotent by the
opcional `codigo`: an existing opcional is updated, a new one is created. Rows
whose referenced opcional/grupo/idioma does not exist MUST be reported in a
rejected-rows output, never silently dropped. Import MUST accept a base64 image
cell and persist it through the image service.

#### Scenario: Re-import updates instead of duplicating

- **GIVEN** an opcional `OPC0001` already exists
- **WHEN** a row for `OPC0001` is imported again
- **THEN** the existing opcional is updated and no second row is created
- Test: `plugins/catalogo_core/tests/OpcionalExcelImportWizardTest.php`

#### Scenario: Orphan reference is reported

- **GIVEN** an import row referencing a group code that does not exist
- **WHEN** the import runs
- **THEN** the row is written to the rejected-rows output and the import reports it
- Test: `plugins/catalogo_core/tests/OpcionalExcelImportWizardTest.php`

### Requirement: OVE-07 — Additive migration, no destructive change

The change MUST add only new columns and new tables. It MUST NOT drop, rename or
rewrite an existing column, and MUST NOT delete or alter existing rows. Every new
table MUST be created idempotently (`CREATE TABLE IF NOT EXISTS`) on both
PostgreSQL and MySQL, ensured at boot by `Init.php::ensureCatalogTables()`.

#### Scenario: Revert loses no data

- **GIVEN** the change applied to a populated database
- **WHEN** the change commits are reverted
- **THEN** existing opcionales, groups and relations are intact
- Test: `plugins/catalogo_core/tests/Services/CatalogLegacyTableMigrationTest.php`

### Requirement: OVE-08 — Change boundary

This change MUST NOT modify any file owned by the concurrent
`gate-tarifario-surfaces-by-plugin-activation` change, and MUST NOT add an entry
to the core `openspec/` tree. The opcionales list view
(`View/ventas_opcionales.html.twig`) and the canonical
`openspec/specs/opcionales-management/spec.md` MUST remain untouched.

#### Scenario: Boundary is honored

- **GIVEN** the change's diff
- **WHEN** it is audited against the owned-file list
- **THEN** none of the concurrent change's files appear in the diff
- Test: `plugins/catalogo_core/tests/CatalogoOpcionalVersatilidadBoundaryTest.php`
