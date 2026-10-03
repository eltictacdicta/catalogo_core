# Proposal: Optional versatility (quantity, image, i18n, conditional flows)

- **Change**: `opcionales-versatilidad`
- **Owner (SDD)**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Artifact store**: `openspec` (plugin-local; core `openspec/` untouched)
- **Source of truth**: this change's `specs/` + `design.md`
- **Research**: not required — no external evidence; the reference study (TM Extra
  Product Options 7.6.2) was reviewed and the maintainer explicitly cut the scope.
- **Consumers**: `tpvmod` and `clientes_core` evaluate flows (decision D4); no
  source change is planned in them by this proposal beyond the documented contract.

## Intent

Extend `catalogo_core` opcionales with the four capabilities the maintainer
selected after evaluating TM Extra Product Options (EPO 7.6.2):

1. A per-opcional **quantity range** (`cantidad_min`, `cantidad_max`), both
   defaulting to `1`, extendable.
2. A **single image** per opcional.
3. **Multiidioma** `nombre` + `descripcion`, following the existing
   `articulo_descripciones` + `catalogo_idiomas` pattern.
4. Reusable **conditional-logic flows ("flujos")** between opcionales and
   grupos, assignable to artículos and familias exactly like opcionales.

The result MUST stay **mass-importable and mass-exportable**, reusing the
article Excel wizard pattern, so richer configurations can move between
environments.

## Scope

### In Scope

- `cantidad_min` and `cantidad_max` columns on `catalogo_opcionales` (integer,
  `NOT NULL DEFAULT 1`; `min >= 0`, `max >= 1`, `min <= max`), applying to
  **loose opcionales and values inside groups** alike (decision D5). Defaults
  mean "exactly one unit"; raising `min`/`max` sets the selectable range.
- Single image per opcional: one filename column, stored under `imgs/opcionales/`,
  reusing the article upload mechanics (MIME validation, atomic write,
  `upload_from_base64` for bulk import, physical delete).
- Multiidioma for opcionales: a child table keyed by the opcional's natural
  `codigo` + `codidioma`, holding `nombre` and `descripcion`, with the article
  read chain (requested language → configured default → base column). Empty pair
  means "absent" and removes the row (mirror of `articulo_descripcion::save()`).
- New **flujos** domain: `catalogo_flujos`, `catalogo_flujo_condiciones`,
  `catalogo_flujo_acciones`, `catalogo_flujo_articulos`,
  `catalogo_flujo_familias`. Everything references opcionales/grupos **by
  código** (decision D1). The core stores and exposes applicable flows plus a
  stable read-only rule schema; the **consumer evaluates** (decision D4).
- Bulk export/import for opcionales covering the new fields, and a separate
  export/import for flujos (rules), both referencing by código.
- Additive, non-destructive migration (new columns/tables only; no data loss on
  `git revert`).

### Out of Scope / Non-goals

- **Field types** (`select`/`radio`/`text`/`date`/…) — already handled by
  `tpvmod`/`clientes_core` through groups + exclusive selection. Rejected.
- **Per-value price / sale price / weight** — rejected by the maintainer.
- Secciones, repeaters, math engine, lookup tables, uploads (EPO P1/P2) — rejected.
- No Composer/external dependency.
- No new page/menu row.
- No core `openspec/` entry.

## Capabilities

### New Capabilities

- `opcionales-versatilidad`: `cantidad_max` store + semantics, single image
  store/serve/editor, and the opcionales bulk export/import parity.
- `flujos-condicionales`: the flow entity, conditions, actions, product/family
  assignment, core read-only exposure contract, cycle/order handling, and the
  flow export/import.

### Modified Capabilities

- `gestion-idiomas`: a new requirement extends the language read chain and the
  default-language fallback to the opcional `nombre`/`descripcion`. No existing
  requirement text is changed or removed.

## Approach

1. **Data layer (additive).** Add `cantidad_max` and `imagen` to
   `catalogo_opcionales`; create `catalogo_opcional_idiomas` and the five
   `catalogo_flujo*` tables. DDL ships as PG + MySQL variants, created through
   `Services/CatalogLegacyTableMigration` and ensured at boot by
   `Init.php::ensureCatalogTables()`, mirroring the existing table-bootstrap
   pattern. No `DROP`, no destructive DDL.
2. **Models.** Extend `model/core/catalogo_opcional.php`; add
   `model/core/catalogo_opcional_idioma.php` and the flow models under
   `model/core/`. Flow subjects use `sujeto_codigo` with app-side integrity
   (no DB FK across two possible parents), matching the plugin's existing
   no-FK / app-cascade convention.
3. **i18n read chain.** Mirror `articulo::get_descripcion_idioma()`: requested
   language → configured default (`catalogo_idioma`) → base `nombre`/`descripcion`.
   Export adds `nombre_<codidioma>` / `descripcion_<codidioma>` columns, default
   language first; import reuses `resolveTargetCodidioma()` / `resolveLocalePair()`.
4. **Image.** New `Services/OpcionalImagenService.php` with
   `OPCIONAL_IMAGES_DIR = 'imgs/opcionales/'`, replicating the article upload
   mechanics (`upload`, `upload_from_base64`, atomic write, MIME allow-list,
   physical delete). Deliberately does not refactor the article model, to keep
   the change disjoint from the concurrent change. Editor: `ventas_opcional`
   (`Controller/VentasOpcional.php` + `View/ventas_opcional.html.twig`) only.
5. **Flows exposure.** A core read-only service resolves the flows applicable to
   a `(referencia, codfamilia)` pair and returns them in a deterministic order
   (specificity then `prioridad`), with a closed operator/action vocabulary.
   `tpvmod`/`clientes_core` evaluate conditions against the user's selection and
   apply the actions. Cycle detection runs at flow save (validation), not at
   evaluation.
6. **Conflict precedence (locked).** Article-level flows override family-level
   flows for the same subject; within a scope, `prioridad` (asc) then `id` (asc),
   later application wins. Documented in the spec/design so both consumers agree.
7. **Import/export.** New opcionales export/import service built on the article
   wizard primitives (field catalog, header mapping, preview, locale columns),
   plus a dedicated flujos export/import. Orphan references are reported in a
   `descartadas.csv` (existing catalog convention).

## Coordination Boundary (CRITICAL)

A concurrent SDD change, `gate-tarifario-surfaces-by-plugin-activation`, is being
implemented in the same worktree by another agent. This change MUST NOT edit:

- `View/ventas_opcionales.html.twig`
- `Controller/VentasOpcionales.php`
- `extras/VentasOpcionalesListTrait.php`
- `Services/CaracteristicaResolver.php`
- `controller/tarif_opcional_edit.php`
- `View/tarif_opcional_edit.html.twig`
- the canonical spec `openspec/specs/opcionales-management/spec.md`

Therefore the list view keeps no new columns in this change (new fields surface
in the canonical opcional editor `ventas_opcional`), and this change uses the new
domains `opcionales-versatilidad` / `flujos-condicionales` plus a delta on
`gestion-idiomas` (untouched by the concurrent change). Deferred to a follow-up
(once the concurrent change archives): opcionales **list** columns and any
`opcionales-management` delta.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `model/table/catalogo_opcionales.xml` | Modified | Add `cantidad_min`, `cantidad_max`, `imagen` |
| `model/table/catalogo_opcional_idiomas.xml` | New | Multiidioma name+description |
| `model/table/catalogo_flujos.xml` + 4 flow tables | New | Flow entity, conditions, actions, article/family M:N |
| `model/core/catalogo_opcional.php` | Modified | `cantidad_max`, `imagen`, i18n accessors |
| `model/core/catalogo_opcional_idioma.php` | New | i18n row model (mirror `articulo_descripcion`) |
| `model/core/catalogo_flujo*.php` | New | Flow models + integrity validation |
| `Services/CatalogLegacyTableMigration.php` | Modified | Create new tables (PG+MySQL) |
| `Init.php` | Modified | `ensureCatalogTables()` ensures new tables |
| `Services/OpcionalImagenService.php` | New | Image upload/serve/delete (reused mechanics) |
| `Services/OpcionalExcelExportService.php` | New | Bulk export incl. qty/image/i18n/memberships |
| `Services/OpcionalExcelImportWizardService.php` | New | Bulk import (wizard pattern) |
| `Services/FlujoResolver.php` | New | Read-only applicable-flow exposure |
| `Controller/VentasOpcional.php` + `View/ventas_opcional.html.twig` | Modified | Qty, image, i18n fields (canonical editor) |
| `openspec/specs/opcionales-versatilidad/spec.md` | New | Delta (new domain) |
| `openspec/specs/flujos-condicionales/spec.md` | New | Delta (new domain) |
| `openspec/specs/gestion-idiomas/spec.md` | Modified | Delta (opcional i18n read chain) |

## Risks

| # | Risk | Likelihood | Mitigation |
|---|---|---|---|
| R1 | Flow engine grows unbounded (the EPO trap). | Med | Closed operator/action vocabulary fixed in the spec; no ad-hoc additions. |
| R2 | Cycle or contradictory flows hang/oscillate the consumer. | Med | Save-time cycle detection + deterministic order + explicit precedence. |
| R3 | Two consumers (tpvmod, clientes_core) diverge in evaluation. | Med | Core exposes a single read-only rule schema + resolver; consumers only apply. |
| R4 | Dangling flow references after opcional/grupo delete. | Med | App-side cascade + validation; orphan report on import. |
| R5 | Image duplication instead of reuse. | Low | Reuse the article mechanics verbatim; only the dir constant differs. |
| R6 | Collision with the concurrent change. | Med | Hard file boundary above; no list-view edits this change. |
| R7 | Additive migration ordering/dialect. | Low | New tables only; `CREATE TABLE IF NOT EXISTS`; PG+MySQL variants; idempotent. |

## Rollback Plan

Additive only: new tables and two new nullable/defaulted columns; no `DROP`, no
`DELETE`, no data rewrite. Rollback = `git revert` the change commits; the new
tables/columns become inert and existing opcionales data is untouched. Bulk
import is idempotent by `codigo`.

## Dependencies

- `catalogo_core` is consumed by `tarifario`, `tpvmod`, `clientes_core`; direction
  unchanged. The flow exposure contract is additive.
- ddev, PHP 8.2+, Symfony 7.4, Twig 3, PHPUnit 11; no new Composer dependency.

## Open Questions

- Exact consumer hook where `tpvmod`/`clientes_core` will apply flow actions
  (resolved during design; the core contract is fixed regardless).

## Success Criteria

- [ ] An opcional has an effective `cantidad_min`/`cantidad_max` range (default
      `1`/`1`) honored for loose and grouped cases.
- [ ] An opcional stores one image, uploads through `ventas_opcional`, and reuses
      the article upload mechanics (including base64 for import).
- [ ] An opcional resolves `nombre`/`descripcion` by language with the article
      fallback chain, and clears a language row when both fields are emptied.
- [ ] A flow references opcionales/grupos by código, is assignable to articles
      and families, is exposed read-only with a deterministic order, and is
      evaluated by the consumer.
- [ ] Opcionales and flows are mass exportable/importable by código; orphans are
      reported, not silently dropped.
- [ ] No file owned by the concurrent change is touched; core `openspec/` stays
      clean.
