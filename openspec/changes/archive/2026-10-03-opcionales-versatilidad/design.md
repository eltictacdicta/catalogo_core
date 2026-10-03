# Design: opcionales-versatilidad

- **Change**: `opcionales-versatilidad`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Artifact store**: `openspec`
- **Inputs**: `proposal.md`, `specs/opcionales-versatilidad/spec.md`,
  `specs/flujos-condicionales/spec.md`, `specs/gestion-idiomas/spec.md`
- **Strict TDD**: active for `catalogo_core`. Runner:
  `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`

## Overview

Additive extension of the opcionales domain in four axes (quantity, image, i18n,
conditional flows) plus bulk I/O. No existing column is dropped or rewritten. All
new cross-entity references use the natural `codigo` (D1) so configurations stay
portable. The core stores and exposes; consumers evaluate flows (D4).

## AD-1 — Data model (additive DDL)

### `catalogo_opcionales` (columns added)

```
cantidad_min   integer   NOT NULL DEFAULT 1
cantidad_max   integer   NOT NULL DEFAULT 1
imagen         varchar(255) NULL
```

### New table `catalogo_opcional_idiomas`

```
id           serial PRIMARY KEY
codigo       varchar(20)  NOT NULL
codidioma    varchar(5)   NOT NULL
nombre       varchar(100) NOT NULL
descripcion  text         NULL
UNIQUE (codigo, codidioma)
FOREIGN KEY (codigo) REFERENCES catalogo_opcionales (codigo)
  ON DELETE CASCADE ON UPDATE CASCADE
```

Mirrors `articulo_descripciones` (`model/table/articulo_descripciones.xml`): natural
key + language discriminator + UNIQUE + FK cascade.

### New tables `catalogo_flujos`, `catalogo_flujo_condiciones`, `catalogo_flujo_acciones`

```
catalogo_flujos
  id serial PK, codigo varchar(20) NOT NULL UNIQUE, nombre varchar(100) NOT NULL,
  descripcion text NULL, activo boolean NOT NULL DEFAULT true,
  prioridad integer NOT NULL DEFAULT 0

catalogo_flujo_condiciones
  id serial PK, id_flujo integer NOT NULL, grupo_and_or varchar(3) NOT NULL DEFAULT 'AND',
  sujeto_tipo varchar(10) NOT NULL, sujeto_codigo varchar(20) NOT NULL,
  operador varchar(20) NOT NULL, valor varchar(255) NULL
  FK id_flujo -> catalogo_flujos(id) ON DELETE CASCADE

catalogo_flujo_acciones
  id serial PK, id_flujo integer NOT NULL, accion varchar(20) NOT NULL,
  sujeto_tipo varchar(10) NOT NULL, sujeto_codigo varchar(20) NOT NULL
  FK id_flujo -> catalogo_flujos(id) ON DELETE CASCADE
```

### New tables `catalogo_flujo_articulos`, `catalogo_flujo_familias`

```
catalogo_flujo_articulos  (id PK, id_flujo, referencia,  UNIQUE(id_flujo, referencia))
catalogo_flujo_familias   (id PK, id_flujo, codfamilia,  UNIQUE(id_flujo, codfamilia))
FKs to catalogo_flujos + articulos/familias, ON DELETE CASCADE
```

`sujeto_tipo`/`sujeto_codigo` have **no DB FK** (two possible parents:
`catalogo_opcionales.codigo` or `catalogo_opcional_grupos.codigo`). Integrity is
app-side, matching the plugin's existing no-FK / app-cascade convention
(`catalogo_opcional_grupo_rel.xml`). A validation helper resolves a subject to
its parent type before saving.

## AD-2 — Models

- `model/core/catalogo_opcional.php`: add `$cantidad_min`, `$cantidad_max`,
  `$imagen`; validate `cantidad_min >= 0`, `cantidad_max >= 1` and
  `cantidad_min <= cantidad_max`; add `imagen_url()`, `get_nombre_idioma($codidioma = null)`,
  `get_descripcion_idioma($codidioma = null)`, `get_idiomas()`, `set_idioma(...)`.
- `model/core/catalogo_opcional_idioma.php`: mirror `articulo_descripcion`
  (`get_by_opcional_idioma`, `all_from_opcional`, empty-pair → `delete()` on save).
- `model/core/catalogo_flujo.php`, `catalogo_flujo_condicion.php`,
  `catalogo_flujo_accion.php`, `catalogo_flujo_articulo.php`,
  `catalogo_flujo_familia.php`: CRUD + closed-vocabulary validation (FLC-02/03) +
  app-side cascade on article/family/opcional/group delete.

## AD-3 — i18n read chain

Mirror `articulo::get_descripcion_idioma()` (`model/core/articulo.php:1451-1475`):

```
if codidioma empty -> base column
row = table.get_by_opcional_idioma(codigo, codidioma)
if row -> row.nombre / row.descripcion
if codidioma != default -> defaultRow; if found -> defaultRow value
-> base column
```

No fallback materialisation (GDI-05 precedent). Export columns:
`nombre_<cod>` / `descripcion_<cod>`, default language first, reusing
`ArticuloExcelExportService::orderedCodes()` ordering logic.

## AD-4 — Image service

New `Services/OpcionalImagenService.php` with
`OPCIONAL_IMAGES_DIR = 'imgs/opcionales/'`, replicating the article mechanics:

- `upload(string $codigo, array $file): ?string` — MIME allow-list via `finfo`
  (`image/jpeg|png|gif|webp`), `ensure_images_dir()`, `generar_nombre_archivo()`,
  atomic write with the `move_uploaded_file → copy → file_put_contents` fallback
  (DDEV-safe), returns the filename.
- `upload_from_base64(string $codigo, string $base64, ...)` — bulk import path.
- `delete_file(string $filename): void` — physical delete.

Deliberately **does not refactor** `tarif_articulo_imagen` (avoids touching a
file outside this change's purpose and any coupling with the concurrent change).
A later consolidation may extract a shared uploader; out of scope here.

## AD-5 — Flow exposure contract

`Services/FlujoResolver.php` (read-only):

```php
/**
 * @return list<array{
 *   codigo:string, prioridad:int, scope:'familia'|'articulo',
 *   condiciones:list<array{grupo_and_or:'AND'|'OR', sujeto_tipo:string,
 *     sujeto_codigo:string, operador:string, valor:?string}>,
 *   acciones:list<array{accion:string, sujeto_tipo:string, sujeto_codigo:string}>
 * }>
 */
public function get_aplicables(string $referencia, string $codfamilia): array;
```

Order (FLC-06): family-scope first, then article-scope; within scope `prioridad`
ascending, then `id` ascending; later wins. Constant query count (no per-opcional
query): two batched reads (assignments + their conditions/actions) then merge.

`velocidad`/evaluation is the consumer's. The core exposes a documented shape and
nothing more.

## AD-6 — Cycle detection (FLC-07)

Build a directed graph: an edge `S1 -> S2` when a flow's action on `S2` is
conditioned by `S1`. Detect a cycle with a DFS over the full flow set on write
(save of any condition/action). Reject with an error identifying the cycle.
Evaluation stays a single bounded pass; no runtime fixpoint.

## AD-7 — Precedence (locked)

`familia` scope < `articulo` scope; then `prioridad` asc; then `id` asc. The last
applied flow wins for a given subject. Both consumers implement the same order by
consuming the resolver's already-ordered list — they never re-sort.

## AD-8 — Migration and boot ensure

- `Services/CatalogLegacyTableMigration.php`: add `syncOpcionalVersatilidad(\fs_db2 $db)`
  creating the six new tables (PG + MySQL variants) and adding the three
  `catalogo_opcionales` columns (`cantidad_min`, `cantidad_max`, `imagen`) guarded
  by a column-exists pre-check. Called from `migrateIfNeeded()`.
- `Init.php::ensureCatalogTables()`: add the new models so boot ensures them.
- Additive only; re-runnable; no `DROP`/`DELETE`.

## AD-9 — UI (canonical editor only)

`Controller/VentasOpcional.php` + `View/ventas_opcional.html.twig`:
`cantidad_max` numeric field, image upload/replace/remove (htmx + CSRF),
language selector with per-language `nombre`/`descripcion` inputs. The list view
`ventas_opcionales.html.twig` and `tarif_opcional_edit.*` are **untouched**
(concurrent-change boundary).

## AD-10 — Bulk export/import

- `Services/OpcionalExcelExportService.php` and
  `Services/OpcionalExcelImportWizardService.php`, built on the article wizard
  primitives (`fieldCatalog`, `suggestMapping`, `preview`, `resolveTargetCodidioma`,
  `resolveLocalePair`).
- Flujos export/import in the same pair or a dedicated small service; orphans →
  rejected-rows output, mirroring `catalogo_import_*_descartadas.csv`.
- Idempotent by `codigo`.

## Affected files

| File | Impact |
|---|---|
| `model/table/catalogo_opcionales.xml` | Modified (2 columns) |
| `model/table/catalogo_opcional_idiomas.xml`, `catalogo_flujos.xml`, `catalogo_flujo_condiciones.xml`, `catalogo_flujo_acciones.xml`, `catalogo_flujo_articulos.xml`, `catalogo_flujo_familias.xml` | New |
| `model/core/catalogo_opcional.php`, `catalogo_opcional_idioma.php`, `catalogo_flujo*.php` | New/Modified |
| `Services/CatalogLegacyTableMigration.php`, `Init.php` | Modified |
| `Services/OpcionalImagenService.php`, `OpcionalExcelExportService.php`, `OpcionalExcelImportWizardService.php`, `FlujoResolver.php` | New |
| `Controller/VentasOpcional.php`, `View/ventas_opcional.html.twig` | Modified |

## Boundary and concurrency

Owned by the concurrent change and thus **not in this diff**:
`View/ventas_opcionales.html.twig`, `Controller/VentasOpcionales.php`,
`extras/VentasOpcionalesListTrait.php`, `Services/CaracteristicaResolver.php`,
`controller/tarif_opcional_edit.php`, `View/tarif_opcional_edit.html.twig`,
`openspec/specs/opcionales-management/spec.md`. A boundary test asserts this.

## Test strategy (strict TDD)

DB-free patterns only (fake `fs_db2` / anonymous subclasses), mirroring existing
tests. Each WU writes/extend tests first (RED), then implements (GREEN). Primary
suite per WU:
`ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`.

## Migration / Rollout (work units)

| WU | Goal | Revert boundary |
|---|---|---|
| WU-1 | Schema + models + migration + boot ensure (qty, image col, i18n table, flow tables) | WU-1 |
| WU-2 | i18n read chain + opcional accessors | WU-2 |
| WU-3 | Image service + editor integration | WU-3 |
| WU-4 | Flujo models + resolver + cycle/precedence | WU-4 |
| WU-5 | Bulk export/import (opcionales + flujos) | WU-5 |
| WU-6 | Verify pass (suites, boundary audit, smoke) — no production change | WU-6 |

WU-1 is the only unit that changes the schema; every later unit is code-only and
reverts independently. No later unit is a prerequisite for reverting an earlier one.

## Risks

| # | Risk | Mitigation |
|---|---|---|
| R1 | Rule-engine sprawl | Closed vocabularies; adding one is a spec change. |
| R2 | Cycle/oscillation | Save-time DFS cycle detection; single bounded evaluation. |
| R3 | Consumer divergence | Resolver returns a pre-ordered, documented shape; consumers never re-sort. |
| R4 | Dangling references | App-side cascade + validation + orphan report. |
| R5 | Concurrent-change collision | Hard owned-file list + boundary test. |
| R6 | Migration dialect drift | PG + MySQL variants, `IF NOT EXISTS`, column-exists guard. |
