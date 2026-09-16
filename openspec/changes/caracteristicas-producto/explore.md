# Explore — caracteristicas-producto

> Phase: `explore` (read-only). No source code was modified.
> SDD root: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`).
> Core `openspec/` is reference only — no entries created there.

## 1. Goal restated

Introduce a new generic **features/characteristics** entity in `catalogo_core`,
conceptually modeled on PrestaShop's Feature/FeatureValue:

- A `caracteristica` has a **name** and a **value type** (for now `bool` or `string`).
- `catalogo_core` registers a default **string** feature `medidas`.
- `tarifario` registers two default **boolean** features `en_catalogo` and `en_tarifa`.
- A **features panel** to create, edit and **assign** features to products with scopes
  **individual product**, **all products**, and **family/subfamily**.
- Definitions are **shared across tarifas**; values **may vary per tarifa**.
- **Cloning a tarifa** carries features and values from source to target.
- Per-feature flags: **importable/exportable** and **listable in the product listing**.

## 2. Current-state map

### 2.1 Existing similar concepts (do not duplicate)

| Concept | Files | What it is | Relation to a "feature" |
|---|---|---|---|
| `atributo` / `atributo_valor` | `model/core/atributo.php`, `model/core/atributo_valor.php`, XML `atributos.xml`, `atributos_valores.xml` | PrestaShop-style attribute definition + enumerated values. Used by `articulo_combinacion` (variants/combinations). | PrestaShop's *Attribute* family, not *Feature*. Enumerated values + combinations, not per-product descriptive characteristics. **Not the target.** |
| `articulo_propiedad` | `model/core/articulo_propiedad.php`, XML `articulo_propiedades.xml` | Free-form `name → text` key/value per article reference (`array_get`/`array_save`/`simple_get`). Explicit docblock: "permite añadir propiedades a un artículo sin necesidad de modificar la clase artículo". | Closest primitive to a feature value, but has **no definition entity, no types, no scopes, no per-tarifa dimension**. Used by `fs_var`-style integrations, not by the catalog UI. |
| `catalogo_opcional` family | `model/core/catalogo_opcional.php`, `catalogo_articulo_opcional.php`, `catalogo_opcional_familia.php`, `catalogo_opcional_precio.php`, `catalogo_opcional_grupo.php` | Optional/extra sellable items with price, family scope + propagation, per-article assignment, per-lista price. | The **architectural precedent** for scopes + propagation + per-tarifa/per-lista values (see 2.4). |
| Opcionales per tarifa | `model/tarif_opcional*.php`, `tarif_tarifa_opcional*.php` | Absorbed from tarifario into catalogo_core: per-tarifa master state (`en_catalogo`/`en_tarifa`/`activa`/`orden`) with lazy inheritance, family/article overrides, tags, history. | Direct template for "shared definition, per-tarifa value with lazy inheritance + copy_from_tarifa". |

**`caracteristica` does not exist anywhere in the repo** (grep over `plugins/`, `src/`, `base/`, `model/`, `controller/`, twig/xml/yaml returns zero hits). `medidas` also does not exist anywhere — it is a genuinely new default string feature.

### 2.2 Product core model

`plugins/catalogo_core/model/core/articulo.php` (`class articulo extends fs_model`, table `articulos`):
- PK `referencia` (varchar 18). Public fields: `referencia`, `tipo`, `codfamilia`, `codfabricante`, `descripcion`, `pvp`, `costemedio`, `preciocoste`, `codimpuesto`, `bloqueado`, `secompra`, `sevende`, `publico`, `equivalencia`, `partnumber`, `stockfis`, `stockmin`, `stockmax`, `controlstock`, `nostock`, `codbarras`, `observaciones`, `codsubcuentacom`, `codsubcuentairpfcom`, `trazabilidad`.
- Extension mechanism: **`articulo_propiedad`** (external `name → text`) and **related table models** (`catalogo_articulo_opcional`, `articulo_combinacion`, `articulo_descripcion`). There is no column-based extension hook on the class. `articulo::get_opcionales()` (line 1466) is the in-model aggregation pattern (`get_opcionales_from_articulo`).
- `familia` is a **hierarchy** (`familias.xml`: `codfamilia` PK, `madre` self-reference, `descripcion`). So "family/subfamily" = same table, depth via `madre`.

### 2.3 Product listing (`ventas_articulos`)

- `Controller/VentasArticulos.php` (PageController) + legacy wrapper `controller/ventas_articulos.php`.
- List logic lives in `extras/VentasArticulosListTrait.php`. It already injects **per-tarifa dynamic columns** for the selected tarifa:
  - batched read `load_articulo_tarifa_columns(array $referencias)` via `Services/ArticuloTarifaPrecioBatchReader::for_referencias()` (one query, never N+1);
  - per-row accessors `get_precio_articulo_tarifa`, `articulo_activo_tarifa`, `articulo_en_tarifa_flag`, `articulo_en_catalogo`, `mostrar_precio_tarifa`, `simbolo_divisa_tarifa`.
- This is **exactly the seam** where "listable" feature columns should be injected (batched, same shape).
- Locked by spec `articulo-lista-canonica` (ALC-02: per-tarifa columns MUST stay) and `tests/Controller/VentasArticulosListAbsorptionTest.php`.

### 2.4 Precedent: opcionales scopes + per-tarifa values

- `catalogo_opcional_familia::add_with_propagation($id_opcional, $codfamilia)` — assign to a family and **propagate** to every article of that family (or to the group relation). `get_articulos_from_familia()` reads `articulos WHERE codfamilia = ?`.
- `catalogo_articulo_opcional` — individual product assignment.
- `catalogo_opcional_precio` — value per `(id_opcional, codlista)` (price/percentage/en_catalogo).
- `tarif_tarifa_opcional` — per-`(codtarifa, id_opcional)` master with **lazy inheritance** (`effective()` returns `source='inherit'` when no row; first write materializes), whitelisted single-column setters, and `copy_from_tarifa()` (transactional DELETE + INSERT…SELECT; no-op when origin==destination).
- `controller/tarif_tarifas.php::heredar_estructura($origen, $destino)` — the **clone orchestrator**: 11 ordered copy steps, each delegating to a model `copy_from_tarifa()` or an inline `DELETE`+`INSERT…SELECT`. New feature values must be added here.

### 2.5 Plugin registration seam

- **No `config/services.php`** in `catalogo_core` or `tarifario`; registration happens in `Init.php`.
- `catalogo_core/Init.php`:
  - `init()` — boot: legacy migrations + `ensure*Tables()` (idempotent `require_once` + `new FQCN()`; `fs_model` creates the table only if missing) + `registerViewExtensions()`.
  - `upgrade()` — activation: same ensures + `DEFAULT_SEED_MODELS` seeded through `seedNamespacedModel()` → `fs_model::seed_if_empty()`.
  - `DEFAULT_SEED_MODELS = ['impuesto','familia','fabricante','catalogo_idioma','catalogo_lista_precio']`.
  - `touchNamespacedModel()` / `seedNamespacedModel()` load `plugins/catalogo_core/model/core/<name>.php` under `FSFramework\model\`.
  - `migrateOpcionalExtension()` / `migrateLegacyTables()` are the precedent for **targeted, idempotent migrations** (not `seed_if_empty`).
  - `registerHooks()` + `registerViewExtensions()` register the frozen Twig hooks via `ViewHookRegistry` behind static guards.
- `tarifario/Init.php::init()` — sweeps temp files, registers a permission listener, an `ArticuloListActionRegistry` handler, and Twig loader paths. It uses `class_exists(hostClass)` guards plus static guards, i.e. **the plugin-to-plugin registration pattern** (tarifario depends on catalogo_core).
- Dependency direction is enforced: `tarifario/fsframework.ini` → `require = "catalogo_core"`; `catalogo_core/fsframework.ini` → `require = ""`.

> **Critical nuance:** `seed_if_empty()` only inserts when the table is **empty**. Registering `medidas`/`en_catalogo`/`en_tarifa` via `DEFAULT_SEED_MODELS` would fail to add them on existing installs that already have feature rows, and deleting a default would not restore it. Defaults must use a **targeted idempotent upsert** (`INSERT … WHERE NOT EXISTS (… WHERE codigo = ?)`), mirroring `migrateOpcionalExtension`.

### 2.6 Import/export

- `Services/ArticuloExcelExportService.php`: `public const EXPORT_HEADERS = [Referencia, Descripción, Precio, Cód. Familia, Cód. Fabricante, Impuesto, Bloqueado]`; `buildSpreadsheet(array $articulos, bool $includeExampleRow)`; `writeRow()` reads article fields positionally.
- `Services/ArticuloExcelImportWizardService.php`: `public const FIELD_CATALOG = [...]` (referencia, descripcion, pvp, codfamilia, codfabricante, codimpuesto, bloqueado) with labels/aliases; `suggestMapping()`, `fieldOptions()`, `preview()`, `apply()`, `createArticuloFromRow()`, `applyMapping()`.
- Both are `const`-based → adding feature columns requires making the header/field sets **dynamic** (feature flags drive extra columns) while keeping the base columns byte-identical.
- Entry points in `Controller/VentasArticulos.php`: `preview_excel`, `get_preview`, `export_excel`, `export_excel_filtered`, `export_excel_template`.
- Locked by spec `articulos-excel-import-export` (headers list is normative).

### 2.7 Current `en_catalogo` / `en_tarifa` flags (supersession candidates)

| Table | Columns | Scope | Notes |
|---|---|---|---|
| `tarif_opcional_ext` | `ref_sap`, `en_catalogo`, `en_tarifa` | global (1:1 per opcional) | Fallback; NOT promoted into `catalogo_opcionales` (spec-locked). |
| `tarif_tarifa_opcional` | `en_catalogo`, `en_tarifa`, `activa`, `orden` | per `(codtarifa, id_opcional)` | **Authoritative** master; lazy inheritance from `tarif_opcional_ext`. |
| `tarif_articulo_precio` (`tarif_articulo_precios.xml`) | `precio`, `activo`, `en_tarifa`, `en_catalogo`, `en_sap` | per `(referencia, codtarifa)` | PK `(referencia, codtarifa)`. Read by `VentasArticulosListTrait` + `ArticuloTarifaPrecioBatchReader`. |
| `tarif_tarifa_articulo` | `en_tarifa`, `en_catalogo`, `orden`, `codfamilia` | per `(codtarifa, referencia)` | Article visibility within a tarifa. |
| `tarif_tarifa_familia` / `tarif_familia_ext` | `en_catalogo`, `en_tarifa`, `activa` (+ order) | per `(codtarifa, codfamilia)` / global | Family-level toggles (`tarif_familias` page). |

These flags are consumed by: the article list (`ALC-02`), the export service, `tarif_catalogo_view`, family/article toggle controllers, and the opcional resolver. So `en_catalogo`/`en_tarifa` are **pervasive, per-tarifa boolean visibility semantics**, not a single column.

## 3. Candidate data model (options + tradeoffs)

All options share one **definition** table:

```
catalogo_caracteristica
  id            PK
  codigo        varchar(20) UNIQUE      -- stable key for plugin registration/upsert
  nombre        varchar(100)
  tipo          varchar(10)             -- 'bool' | 'string' (whitelist const)
  activo        boolean DEFAULT TRUE
  importable    boolean DEFAULT FALSE
  exportable    boolean DEFAULT FALSE
  listable      boolean DEFAULT FALSE
  orden         integer DEFAULT 0
  origen        varchar(20)             -- 'catalogo_core' | 'tarifario' (provenance, optional)
  valor_defecto varchar(255) NULL       -- optional default when no assignment exists
```

Differing only in the **value/assignment storage**:

### Option A′ — Tarifa-keyed scope value tables, lazy inheritance from `DEF` (RECOMMENDED)

```
catalogo_caracteristica_global   (codtarifa, id_caracteristica, valor)                       PK(codtarifa,id_caracteristica)
catalogo_caracteristica_familia  (codtarifa, codfamilia, id_caracteristica, valor)            PK(codtarifa,codfamilia,id_caracteristica)
catalogo_caracteristica_articulo (codtarifa, referencia, id_caracteristica, valor)            PK(codtarifa,referencia,id_caracteristica)
```
- 4 tables total. Scope = which table; tarifa = PK column; base = the `DEF` tarifa.
- Resolution: per-tarifa row → fallback to `DEF` row → `valor_defecto` / null. Scope precedence: `articulo > familia > global`.
- Clone: `copy_from_tarifa($origen,$destino)` per table (DELETE + INSERT…SELECT), mirroring `copy_precios_articulos` / `copy_precios_opcionales` / `copy_tarifa_opcionales`.
- FKs: `id_caracteristica → catalogo_caracteristica` CASCADE; `codtarifa → tarif_tarifas` CASCADE; `referencia → articulos` CASCADE; `codfamilia → familias` (SET NULL/keep app-managed, as `tarif_tarifa_articulo` does).
- **Pros:** fewest tables; "values vary per tarifa" native; clone is 3 statements; resolution is a single uniform rule; matches `catalogo_opcional_precio` (value keyed by the list/tarifa code).
- **Cons:** puts the tarifa dimension *inside* every scope table (the panel must always resolve a tarifa for every write); "all products default across all tarifas" is only expressible via `DEF`.

### Option B — Default scope tables + separate per-tarifa override tables (maximal normalization)

```
catalogo_caracteristica_global        (id_caracteristica, valor)
catalogo_caracteristica_familia       (codfamilia, id_caracteristica, valor)
catalogo_caracteristica_articulo      (referencia, id_caracteristica, valor)
tarif_tarifa_caracteristica_global    (codtarifa, id_caracteristica, valor)
tarif_tarifa_caracteristica_familia   (codtarifa, codfamilia, id_caracteristica, valor)
tarif_tarifa_caracteristica_articulo  (codtarifa, referencia, id_caracteristica, valor)
```
- 7 tables total. Mirrors the opcionales master (base relations) + per-tarifa override model literally (`catalogo_opcional_familia` + `tarif_tarifa_opcional_familia`, `catalogo_articulo_opcional` + `tarif_tarifa_articulo_opcional`).
- **Pros:** clean semantic split default vs override; per-scope FKs; explicit lazy inheritance; each table gets its own `copy_from_tarifa`.
- **Cons:** 6 value tables + definition; more migrations/bootstrap steps; more code surfaces; the clone step becomes 6 calls; heavier for a first slice.

### Option C — Reuse `articulo_propiedad` + thin definition table (NOT recommended)

- Keep values in the legacy `articulo_propiedades` (`name`=`codigo`, `text`=value) and add only `catalogo_caracteristica` for metadata.
- **Cons:** no family/global scope, no per-tarifa dimension, no batched typed reads, and mixing feature values with unrelated article properties; you would still need a parallel per-tarifa table. Fails three requirements outright. Rejected.

**Recommendation:** **Option A′**. It satisfies every requirement with the smallest schema, keeps a single uniform resolver, and gives a 3-statement clone step consistent with the existing tarifa-copy code. If the product owner wants strict separation of "global default" vs "per-tarifa override" (mirroring the opcionales master), Option B is the fallback — at the cost of 3 extra tables and a 6-call clone.

> Open sub-decision inside A′: store `valor` as `varchar(255)` for both types (`bool` normalized to `'1'`/`'0'` via `str2bool`), or add a typed `valor_bool`/`valor_text` pair. Recommend a single `valor varchar(255)` + type-aware normalization in the model (`test()`), since the type is defined on the definition row.

## 4. Integration points per requirement

| Requirement | Integration point | Notes |
|---|---|---|
| Definition + `bool`/`string` type | new `catalogo_caracteristica` model + XML; const `TIPOS = ['bool','string']` | Follow `catalogo_opcional` model shape (`get_by_codigo`, `get_new_codigo`, `test`, `save`, `search`). |
| Default `medidas` (catalogo_core) | `catalogo_core/Init.php` targeted upsert by `codigo` | Idempotent; NOT `seed_if_empty`. New `ensureCaracteristicas()` helper + call in `init()` and `upgrade()`. |
| Default `en_catalogo`, `en_tarifa` (tarifario) | `tarifario/Init.php::init()` behind `class_exists` + static guard | Tarifario only registers definitions; no tarifario-owned value tables. |
| Features panel (create/edit/assign) | new page: `Controller/VentasCaracteristicas.php` + legacy `controller/ventas_caracteristicas.php` + view + `getPageData()` | `PageController`, menu `catalogo`; assignments for scope articulo/familia/global. |
| Scope: individual product | `catalogo_caracteristica_articulo` + detail-tab or panel form | Individual assignment keyed by `referencia`. |
| Scope: all products | `catalogo_caracteristica_global` | One row, never per-product fan-out. |
| Scope: family/subfamily | `catalogo_caracteristica_familia` + propagation helpers | Mirror `catalogo_opcional_familia::add_with_propagation` / `get_articulos_from_familia`. |
| Per-tarifa values | `codtarifa` in value tables + resolver (`effective()`-style) | Lazy inheritance from `DEF`; first write materializes. |
| Tarifa clone | `controller/tarif_tarifas.php::heredar_estructura()` + `copy_caracteristicas()` | New step in the ordered copy list; use model `copy_from_tarifa`. |
| `listable` | `VentasArticulosListTrait` + `View/ventas_articulos.html.twig` | New batched feature reader; dynamic columns after the existing per-tarifa columns. Locked by `articulo-lista-canonica` ALC-02/ALC-04. |
| `exportable` | `ArticuloExcelExportService` dynamic headers | Base `EXPORT_HEADERS` must stay byte-identical; append feature columns. Locked by `articulos-excel-import-export`. |
| `importable` | `ArticuloExcelImportWizardService::FIELD_CATALOG`/`fieldOptions()`/`apply()` dynamic fields | Feature mapping keyed by `codigo`; base catalog unchanged. |
| Article detail (optional editing) | `View/ventas_articulo.html.twig` tab structure | Must preserve the four frozen hook markers and positions (`CatalogoCoreHookMarkersTest`). |

## 5. Migration / supersession analysis — `en_catalogo` / `en_tarifa`

**Question:** do the new boolean features `en_catalogo`/`en_tarifa` *supersede* the existing hardcoded columns?

**Evidence:** the flags are not one column but five tables + multiple consumers:
- `tarif_articulo_precio.en_catalogo/en_tarifa` (article × tarifa) — read by the canonical list (`ALC-02`, locked) and the filtered export.
- `tarif_tarifa_articulo.en_catalogo/en_tarifa` (article × tarifa visibility) — written by the article detail absorption.
- `tarif_opcional_ext` + `tarif_tarifa_opcional` — opcional catalog/export visibility (spec-locked precedence: master wins).
- `tarif_tarifa_familia` / `tarif_familia_ext` — family toggles.
- Consumers: `VentasArticulosListTrait`, `ArticuloTarifaPrecioBatchReader`, `ArticuloExcelExportService`, `tarif_catalogo_view`, `tarif_familias`, opcional resolver.

**Full supersession in this change would require:** backfilling all five surfaces into feature values, rewriting every consumer to read the resolver, migrating the opcional master precedence, and updating locked tests/specs (`ALC-02`, `OUM-03`, `Precedence`, export specs). Blast radius is the entire catalog visibility model — disproportionate for a first slice and guaranteed to break `strict_tdd`.

**Recommendation (D3): coexist, defer supersession.**
- Phase 1 (this change): implement the generic entity + panel + scopes + per-tarifa values + clone + flags. Register `en_catalogo`/`en_tarifa` as **new** boolean features whose values live only in the new tables. Leave every legacy column untouched.
- Optional bridge: a one-way **mirror** (legacy column → feature value) guarded by a setting, only if the product owner wants the new features pre-populated; never a destructive migration.
- Phase 2 (separate SDD): backfill feature values from legacy columns, add a read-through switch, then drop the columns. This is a core-visibility change and should be scoped/verified on its own.

Documented migration sketch (Phase 2, not this change):
1. `INSERT INTO catalogo_caracteristica_articulo (codtarifa, referencia, id_caracteristica, valor)` from `tarif_articulo_precio` / `tarif_tarifa_articulo` for the `en_catalogo` / `en_tarifa` definitions (idempotent, `NOT EXISTS`).
2. Flip consumers to the resolver behind a constant/flag; compare against legacy in tests.
3. Stop writing legacy columns; after a soak period, drop them and retire the flag readers.

**Risk if superseded prematurely:** operator-visible drift between the feature panel and the list/export, plus broken locked contracts. **Risk if never superseded:** duplicated `en_catalogo`/`en_tarifa` semantics may confuse operators (two places to set "en tarifa"). Mitigate with clear labels and by making the legacy columns the documented source of truth until Phase 2.

## 6. Proposed routing (catalogo_core-owned, tarifario registration)

```
plugins/catalogo_core/openspec/
├── config.yaml                                  # ownership: plugin-local (already present)
└── changes/caracteristicas-producto/
    ├── proposal.md, design.md, tasks.md         # produced by later phases
    ├── specs/caracteristicas-producto/spec.md    # delta
    ├── explore.md                                # THIS artifact
    └── verify-report.md
```

- **catalogo_core owns** the entity, the XML/schema, the resolver, the features panel page, all scope assignment, per-tarifa value tables, the clone step in `heredar_estructura()`, and the list/export/import integrations.
- **tarifario owns only the idempotent registration** of its two default boolean features in `plugins/tarifario/Init.php` (guarded by `class_exists` on the catalogo_core models + a static guard), consistent with its existing `ArticuloListActionHandler` registration pattern.
- Core `openspec/` receives **nothing**.

> **Routing caveat (see D1).** The task brief credits tarifario with "per-tarifa values + tarifa clone integration". However, `tarif_tarifas` and `heredar_estructura()` were **absorbed into `catalogo_core`** (specs `familias-tarifa-management`, `opcionales-tarifa-management`), and there is **no tarifa-clone event seam** (`tarif_tarifas` dispatches no events; no `TarifaCreated`/`tarifa.copied` listener exists). So either catalogo_core owns the value store + clone step directly (recommended, mirrors `copy_tarifa_opcionales()`), or this change must introduce a new event seam for tarifario to hook the clone. The former keeps the core clean of plugin remainders and matches the dependency direction.

## 7. Open product decisions (D1..D11)

| # | Decision | Recommendation |
|---|---|---|
| **D1** | Who owns the per-tarifa value store + clone step? | **catalogo_core.** The clone orchestrator and `tarif_tarifas` already live there; tarifario only registers default features. Adding an event seam for tarifario to own the clone would be a new architectural addition. |
| **D2** | Value storage shape. | **Option A′** (1 definition + 3 tarifa-keyed scope tables with `DEF` inheritance). Fallback **Option B** if strict default/override separation is required. |
| **D3** | Supersede legacy `en_catalogo`/`en_tarifa` columns? | **No — coexist and defer supersession** to a separate SDD (see §5). |
| **D4** | Predefined value catalog (PrestaShop `FeatureValue`) vs free text? | **Free text now**, typed by `tipo` (`bool`: Sí/No; `string`: free text). A predefined-value catalog can be added later additively; bool/string do not need it now. |
| **D5** | Effective-value precedence. | **`articulo > familia > global`**, and within a scope **requested tarifa row > `DEF` row > `valor_defecto`/null**. State it explicitly in the spec. |
| **D6** | Deletion policy for plugin-registered defaults (`medidas`, `en_catalogo`, `en_tarifa`). | **Non-deletable, deactivatable.** Persist `origen` provenance so the panel can block deletion of plugin-owned defaults. |
| **D7** | `listable` rendering. | **Dynamic columns appended after the existing per-tarifa columns**, resolved with one batched query (`ArticuloTarifaPrecioBatchReader` pattern). Bool → `Sí`/`No`. Base columns/behaviour locked by ALC-02. |
| **D8** | Import/export column contract. | **Additive dynamic columns** gated by `importable`/`exportable`. Base header list must remain byte-identical. Export header = feature `nombre`; import mapping keyed by `codigo` + aliases. |
| **D9** | Panel page slug + menu. | `ventas_caracteristicas`, `menu => 'catalogo'`, `showonmenu => true`, ordernum near opcionales (108) / artículos (120). New `fs_page` row self-registers via `getPageData()` + legacy `check_fs_page`. |
| **D10** | Type support scope. | **`bool` + `string` only** (const whitelist, like `tarif_tarifa::ALLOWED_CURRENCIES`). Extension = new SDD. |
| **D11** | Referential behaviour on product/family delete. | **FK CASCADE** on `referencia`/`codfamilia` value rows; app-level cleanup when a family is reassigned. Recompute "effective" lazily; never fan out a global value into per-product rows. |

## 8. Risks

1. **Locked contracts.** `articulo-lista-canonica` (ALC-02/ALC-04), `articulos-excel-import-export` (normative header list), `opcionales-tarifa-management` precedence, `CatalogoCoreHookMarkersTest` (four frozen markers), `VentasArticulosListAbsorptionTest`, `ArticuloPropiedadModelTest`, `InitUpgradeTest` (excluded from the root suite but still run via the plugin suite). All feature additions must be **additive and gated**.
2. **`seed_if_empty` trap.** Using `DEFAULT_SEED_MODELS` for defaults will not register features on existing installs and will not restore deleted defaults. Use targeted `INSERT … NOT EXISTS` upserts.
3. **N+1 on listable columns.** A naive per-row feature lookup would regress the canonical list. Must reuse the batched-reader pattern (one query per page).
4. **Cross-plugin boot order.** `tarifario` may boot before `catalogo_core` classes are loaded; registration must be `class_exists`-guarded and re-attempted in `upgrade()`, as the existing tarifario handler does.
5. **No services.php / no event seam.** Registration and the clone step have no DI/event hooks today; the clone integration must be added directly to `heredar_estructura()` or require a new event seam (D1).
6. **Semantic duplication** of `en_catalogo`/`en_tarifa` (new feature vs legacy columns) → operator confusion and drift. Mitigate with labels and the deferred-supersession plan (D3).
7. **Value-type storage edge cases.** Empty string vs missing/null must stay distinguishable to avoid masking a "no value" as `false`; bool normalization must go through `str2bool`/`no_html` and reject non-whitelisted types in `test()`.
8. **Test isolation.** `plugins/catalogo_core/phpunit.xml` uses `processIsolation="true"`; static idempotency guards and `$GLOBALS['plugins']` state must be handled as the existing tests do. New tests must pass in isolation and in the root Plugins suite.
9. **Panel scope/permission.** Assignment to "all products" and "all families" is high-impact; requires CSRF + delete-permission gating consistent with `ventas_opcionales` (CSRF-guarded POST mutations).
10. **Clone semantics.** `copy_from_tarifa` must remain transactional (DELETE + INSERT…SELECT in one transaction), no-op on same origin/destination, and must not resurrect deleted features.

## 9. Relevant files (for later phases)

- `plugins/catalogo_core/model/core/articulo.php` — product model (PK `referencia`).
- `plugins/catalogo_core/model/core/familia.php`, `model/table/familias.xml` — family/subfamily hierarchy (`madre`).
- `plugins/catalogo_core/model/core/catalogo_opcional.php`, `catalogo_opcional_familia.php`, `catalogo_articulo_opcional.php`, `catalogo_opcional_precio.php` — scope/propagation/per-value precedent.
- `plugins/catalogo_core/model/tarif_tarifa_opcional.php`, `tarif_opcional_ext.php` — lazy inheritance + `copy_from_tarifa` precedent.
- `plugins/catalogo_core/model/tarif_tarifa.php`, `controller/tarif_tarifas.php::heredar_estructura()` — clone orchestrator.
- `plugins/catalogo_core/Init.php` — table ensure + seed + migration seam.
- `plugins/tarifario/Init.php` — plugin-to-plugin registration pattern.
- `plugins/catalogo_core/Controller/VentasArticulos.php`, `extras/VentasArticulosListTrait.php`, `View/ventas_articulos.html.twig` — list columns.
- `plugins/catalogo_core/Services/ArticuloExcelExportService.php`, `ArticuloExcelImportWizardService.php`, `ArticuloTarifaPrecioBatchReader.php`.
- `plugins/catalogo_core/openspec/specs/{articulo-lista-canonica,articulos-excel-import-export,opcionales-tarifa-management,catalogo-render-hooks,articulo-detalle-canonico}/spec.md` — constraints.
- `plugins/catalogo_core/tests/` — locked contracts.

## 10. skill_resolution

- **`fsframework-plugin-sdd` — LOADED and APPLIED (primary).** Routing decision: the change is 100% plugin-local (`plugins/catalogo_core/` + `plugins/tarifario/`, both plugin openspecs) → SDD root is `plugins/catalogo_core/openspec/` with `ownership: plugin-local`, `change_root: plugins/catalogo_core/openspec/changes/{name}/`, `archive_root: …/changes/archive/{YYYY-MM-DD}-{name}/`. **No entry created or planned in core `openspec/`** (anti-pattern avoided). Dispatcher limitation acknowledged: `gentle-ai sdd-status` only sees the root openspec, so future archive must be handed the explicit plugin path.
- Supporting skills that will apply in later phases (not executed in explore): `fsframework-model-crud` (definition + value models, XML, tests), `fsframework-test-writing` (plugin suite under `plugins/catalogo_core/tests/`), `fsframework-security-review` (CSRF/permission-gated panel mutations), `fsframework-instruction-sync` (only if shared conventions change — not expected).
- No `config/services.php` and no `test` capability cache were found for this change; `strict_tdd: true` per plugin config.
