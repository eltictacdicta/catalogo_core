# Exploration: `opcionales-por-tarifa`

Read-only exploration for a NEW plugin-local SDD change in `plugins/catalogo_core`.
Goal: make opcionales organized **by tarifa** the same way familias are, mirroring the
per-tarifa master `tarif_tarifa_familia`.

- **Change name**: `opcionales-por-tarifa`
- **SDD location**: `plugins/catalogo_core/openspec/changes/opcionales-por-tarifa/`
- **Owner**: `catalogo_core` (plugin-local). Cross-plugin touch to `tarifario`
  (`tarif_catalogo_view` export) would make it hybrid; see Routing.
- **Store**: `openspec` (+ Engram artifact `sdd/opcionales-por-tarifa/explore`).
- **Prior art in-tree**: active change `absorber-opcionales-tarifa-en-catalogo-core`
  (domain already moved into catalogo_core) and archived
  `2026-09-06-absorber-familias-tarifa-en-catalogo-core` (+ spec
  `familias-tarifa-management`). This change builds on top of both.

> Correction to the brief: there is **no** `copy_from_default()` method. The real
> familias precedent is `tarif_tarifa_familia::migrate_existing_familias()` (seed the
> default tarifa on `install()`) plus `copy_from_tarifa($origen, $destino)` for
> tarifa→tarifa inheritance.

---

## 1. Familias per-tarifa lifecycle (the model to mirror)

| Aspect | Evidence |
|---|---|
| Master schema | `plugins/catalogo_core/model/table/tarif_tarifa_familia.xml` — `codtarifa, codfamilia, madre, capitulo, nivel, en_catalogo(TRUE), en_tarifa(FALSE), activa(TRUE), orden(0)`; PK `(codtarifa, codfamilia)`; FK → `tarif_tarifas`, `familias` |
| Seed on install (default tarifa) | `model/tarif_tarifa_familia.php:126-131` `install()` → `migrate_existing_familias()`; body `:155-176`: `INSERT ... SELECT codfamilia, madre, ... FROM familias LEFT JOIN tarif_familia_ext WHERE NOT EXISTS`, hard-coded `en_catalogo=TRUE, en_tarifa=FALSE, activa=TRUE` for the **default tarifa** (`tarif_tarifa()->get_default()`) |
| Lazy column migration | `model/tarif_tarifa_familia.php:136-149` `add_orden_column_if_not_exists()` (once per session via static flag in ctor `:93-98`) |
| Create row for a tarifa | `model/tarif_tarifa_familia.php:636-651` `add_familia_to_tarifa(...)` (uses `suggest_capitulo`); controller path `controller/tarif_familias.php:681-772` `add_familia_to_tarifa()` — links/creates the base familia then calls the model with POST `en_catalogo/en_tarifa/activa` (`:745-752`) |
| Tarifa→tarifa inheritance | `model/tarif_tarifa_familia.php:588-601` `copy_from_tarifa()`; invoked by `plugins/tarifario/controller/tarif_tarifas.php:207-238` `heredar_estructura()` step 1 (`:210`) |
| Excel import creates rows | `plugins/tarifario/controller/tarif_articulos.php:1471-1472,1722-1723`; `plugins/tarifario/Services/ExcelHierarchyService.php:489-490` (pinned `en_catalogo=TRUE, en_tarifa=TRUE` — diverges from CSV defaults) |
| Toggle per tarifa | `controller/tarif_familias.php:486` `ajax_toggle_en_catalogo`, `:493-505` `ajax_toggle_en_tarifa`, and `ajax_toggle_activa`; tests `tests/TarifFamiliasToggleTest.php:148-216` |
| List selectors | `model/tarif_tarifa_familia.php` `all_from_tarifa:386`, `all_activas_from_tarifa:407`, `all_en_catalogo_from_tarifa:429`, `all_en_tarifa_from_tarifa:609`, `all_jerarquico:451`, `all_by_capitulo:498`, `count_from_tarifa:571` |
| List consumer | `controller/tarif_familias.php:933-940` (diff of `all_from_tarifa` vs base `familias`); `plugins/tarifario/controller/tarif_catalogo_view.php:1262,1458` uses `all_en_catalogo_from_tarifa` |
| Bootstrap (standalone) | `Init.php:77-95` `ensureFamiliasTarifaTables()` — FK-safe list `tarif_tarifa → tarif_familia_ext → tarif_tarifa_etiqueta_familia → tarif_tarifa_familia`; called from `init()` `:32` and `upgrade()` `:56`; controller once-guard `controller/tarif_familias.php:96` |
| SDD precedent | `openspec/specs/familias-tarifa-management/spec.md` (toggle/add/edit/hierarchy) and archive `changes/archive/2026-09-06-absorber-familias-tarifa-en-catalogo-core/` |

**Lifecycle summary**: rows exist only for tarifas that were seeded (default-tarifa
install migration), explicitly added, copied via `copy_from_tarifa`, or imported.
Missing row ⇒ the familia is not in that tarifa at all. `en_catalogo`/`en_tarifa`/
`activa`/`orden` are **per (tarifa, familia)** and directly editable.

---

## 2. Every `en_catalogo` / `en_tarifa` read/write for opcionales

### 2a. Global `tarif_opcional_ext.en_catalogo` / `en_tarifa` (1:1 ext table)

| Site | Kind |
|---|---|
| `model/table/tarif_opcional_ext.xml:17-28` | schema (`default false`) |
| `model/tarif_opcional_ext.php:17-18,27-33,83-92` | model fields, ctor, UPDATE/INSERT |
| `model/tarif_opcional.php:22-26,50-54,107-126,169-174,198-199,218,253,342` | `tarif_opcional` hydrates/saves them via ext; SELECTs join `e.en_catalogo, e.en_tarifa` |
| **Write (derived)** `controller/tarif_opcional_edit.php:295-305` | `en_tarifa = count(precios)>0`; `en_catalogo = any(precio.en_catalogo)`; then `$this->opcional->save()` |
| **Write (derived)** `controller/tarif_opcional_precios.php:197-205` | same derivation after saving prices |
| Write (create) `controller/tarif_configurador_opcionales.php:1017-1018` | new opcional starts `en_catalogo=false, en_tarifa=false` |
| **Write (import)** `plugins/tarifario/controller/tarif_catalogo_view.php:2769-2770` | import defaults both `TRUE` per `opt_data` |
| **Read (export)** `plugins/tarifario/controller/tarif_catalogo_view.php:2106-2107` | `opcionales_export[]` per **this tarifa** but reads the **global** flag — the core mismatch |
| Legacy column fallback | `model/tarif_opcional.php:131-175` reads `ref_sap/codigo2/en_catalogo/en_tarifa` from the base table if present |
| Test lock | `tests/OpcionalDomainModelOwnershipTest.php:185-211` asserts the ext table owns `ref_sap/en_catalogo/en_tarifa` and `catalogo_opcionales` XML stays clean |

### 2b. Per-lista price `catalogo_opcional_precios.en_catalogo`

| Site | Kind |
|---|---|
| `model/core/catalogo_opcional_precio.php:19,32-38,111,115-120` | schema/model/UPDATE/INSERT |
| `model/table/catalogo_opcional_precios.xml` | canonical table (`id_opcional, codlista, precio, porcentaje, en_catalogo`) |
| `controller/tarif_opcional_edit.php:243,270-280` | per-tarifa matrix reads/writes `en_catalogo` (`catalogo_tarifa_<codtarifa>`) |
| `controller/tarif_opcional_precios.php:142-154,173-183,227` | `esta_en_catalogo()`, POST write, JSON payload |
| `controller/tarif_opcionales.php:154-179,214-220` | list cache: `activo = price row exists`, `en_catalogo = price.en_catalogo` |
| `controller/tarif_configurador_opcionales.php:979,1027,1084` | price read/write per tarifa |
| `Services/CatalogLegacyTableMigration` + `tests/OpcionalPriceUnificationTest.php` | `codtarifa → codlista` map |

### 2c. "Active in tarifa" is currently **derived from price-row existence**

- `controller/tarif_opcionales.php:135-220`: `search(...)` + `load_precios_cache` ⇒
  `opcional_activo_en_tarifa($id)` = a `catalogo_opcional_precios` row exists for the
  selected tarifa; `opcional_en_catalogo_tarifa($id)` = that row's `en_catalogo`.
- `model/tarif_opcional.php:245-286` `search()` joins prices **only** when
  `$codfamilia != ''` or (`$codtarifa != ''` && `$solo_activos`) — `:252-264`.
- `controller/tarif_opcional_edit.php:354-387` same rule per tarifa (`precio !== null`).
- `View/tarif_opcionales.html.twig:130-199` columns "Estado" (global base `activo`),
  "Tarifa" (`opcional_activo_en_tarifa`), "Catálogo" (`opcional_en_catalogo_tarifa`).
- `View/tarif_opcional_edit.html.twig:318-323` per-tarifa row matrix (activo/catalogo/precio).

**Conclusion**: opcionales have a global master + a per-tarifa activation inferred from
prices, plus family/article scoped overrides; there is **no** per-(tarifa, opcional)
master carrying `en_catalogo`/`en_tarifa`/`activa`/`orden`. Confirmed: no
`tarif_tarifa_opcional` table or class exists anywhere.

---

## 3. Interaction with the existing per-tarifa layers

| Layer | Key | Carries | Semantics |
|---|---|---|---|
| `tarif_tarifa_opcional_familia` | `(codtarifa, id_opcional, codfamilia)` | `activo`, `orden` | Family-scoped activation/order; missing row ⇒ inherits base `catalogo_opcional_familias` as active; `activo=FALSE` disables only that tarifa. `model/tarif_tarifa_opcional_familia.php:117-152,196-216,286-298` |
| `tarif_tarifa_articulo_opcional` | `(codtarifa, referencia, id_opcional)` | `activo`, `orden` | Article-scoped activation/order; same inheritance. `model/tarif_tarifa_articulo_opcional.php:118-138,182-205,242-254` |
| `catalogo_opcional_precios` | `(id_opcional, codlista)` | `precio`, `porcentaje`, `en_catalogo` | Row presence = opcional active in that tarifa (implicit); `en_catalogo` per lista |
| `tarif_opcional_ext` | `id_opcional` | `ref_sap`, `en_catalogo`, `en_tarifa` | Global, cross-tarifa aggregate (derived) |
| Resolver | — | — | Combines family activation + tags into effective set; persists overrides via `sync_articulo_overrides/sync_familia_overrides`. `model/tarif_tarifa_opcional_resolver.php:86-249` |

**Overlap / redundancy analysis**

- A new `tarif_tarifa_opcional(codtarifa, id_opcional, …)` would make **explicit** what
  is today implicit ("active in tarifa" = a price row exists) — a real semantic
  collision, not just an added layer. Price-row existence and a master `activa` flag
  could disagree; one must be the source of truth.
- `en_catalogo` at the opcional level would coexist with `catalogo_opcional_precios.en_catalogo`
  (per lista) and with the global `tarif_opcional_ext.en_catalogo`. That is **three**
  catalog flags unless the change defines precedence.
- `activa` at the opcional level is a **different granularity** from the family/article
  `activo` overrides — not strictly redundant, but a third activation layer. Cleanest
  framing: opcional-level master = "is this opcional part of this tarifa / its catalog
  / its export / its default order"; family/article overrides = finer scoped exceptions
  that can only *narrow* the master, not widen it.
- `orden`: currently only family/article-scoped. Adding a master `orden` introduces a
  second ordering key; precedence must be defined.

---

## 4. Design options

| # | Option | Pros | Cons | Effort |
|---|---|---|---|---|
| **A** | Full master `tarif_tarifa_opcional(codtarifa, id_opcional, en_catalogo, en_tarifa, activa, orden)` mirroring `tarif_tarifa_familia` | Exact parity with familias (the stated goal); single explicit place for per-tarifa opcional state; list/edit/configurador read one table; clean seeding/copy story mirroring `migrate_existing_familias`/`copy_from_tarifa` | Largest migration; collides with implicit price-row activation and with the two `en_catalogo` copies; `orden` overlaps family/article scopes | High |
| **B** | Per-tarifa flags only, no `orden`: `tarif_tarifa_opcional(codtarifa, id_opcional, en_catalogo, en_tarifa, activa)` | Meets per-tarifa parity for visibility/activation; avoids the ordering-key collision (ordering stays where it is); smaller diff/test surface | Not a literal schema mirror; still needs precedence vs price `en_catalogo` and price-row activation | Medium |
| **C** | Reuse / simplify existing layers: keep price-row = activation, add only a per-tarifa `en_tarifa` (e.g. column on `catalogo_opcional_precios`), deprecate global ext flags | Smallest change; no new table; directly fixes the export mismatch | Does not deliver "organized by tarifa like familias"; no explicit per-tarifa `activa`/`orden`; keeps implicit semantics | Low |

**Recommendation**: **Option A** — it is the exact analogue the goal names ("the same way
familias are"), gives one unambiguous per-tarifa opcional source of truth, and reuses the
proven `migrate_existing_familias()` + `copy_from_tarifa()` + `ensureFamiliasTarifaTables()`
patterns. Resolve the collisions explicitly (product decisions §9): make `tarif_tarifa_opcional.activa`
authoritative over price-row activation, define master vs price `en_catalogo` precedence
(recommend: master opcional-level flag for catalog/export; keep price-level `en_catalogo`
as the per-lista price inclusion, or collapse them — decide), and define master `orden`
precedence vs family/article `orden` (recommend: master = default order; family/article
`orden` = scoped override). If ordering parity is truly not needed, Option B is a valid
cheaper slice.

---

## 5. Migration / seeding

- **Mirror the familias seed**: add `tarif_tarifa_opcional::install()` → seed the **default
  tarifa** from `catalogo_opcionales LEFT JOIN tarif_opcional_ext` (`en_catalogo`/`en_tarifa`
  inherited from the ext flags), exactly like `migrate_existing_familias()`
  (`model/tarif_tarifa_familia.php:155-176`). Fresh standalone install also gets rows.
- **Tarifa→tarifa**: add a `copy_from_tarifa($origen, $destino)` and a step in
  `plugins/tarifario/controller/tarif_tarifas.php::heredar_estructura()` (steps 1–10,
  `:207-238`) alongside the existing opcional copies (`:263-274,332-347,354+`). This is a
  cross-plugin (hybrid) touch.
- **Lazy / on-demand**: rows can also be created when a list/edit/configurador touches a
  (tarifa, opcional) with no row, defaulting from the ext flags — the "missing row ⇒
  inherit" pattern used by the existing override tables avoids a mandatory backfill.
- **Optional one-time backfill**: mirror `Services/TarifOpcionalExtMigration` /
  `CatalogLegacyTableMigration` if a physical backfill is wanted; not required for
  correctness if inheritance is implemented.
- **No destructive migration**: existing DBs stay valid; `catalogo_opcionales` and
  `tarif_opcional_ext` untouched/retained.
- **Bootstrap**: extend `Init::ensureOpcionalesTarifaTables()` (`Init.php:103-140`) FK-safe
  list with the new table (after `tarif_tarifas` + `catalogo_opcionales`).

---

## 6. UI impact

| Surface | Change |
|---|---|
| `controller/tarif_opcionales.php` + `View/tarif_opcionales.html.twig` | "Tarifa"/"Catálogo" columns switch from price-row-derived to the per-tarifa master (or master becomes authoritative); "Estado" column today shows global base `activo` — decide whether it becomes per-tarifa `activa` |
| `controller/tarif_opcional_edit.php` + `View/tarif_opcional_edit.html.twig:318-323` | Per-tarifa matrix (`activo_tarifa_*`, `catalogo_tarifa_*`, `precio_tarifa_*`) writes the master flags + price; global ext derivation (`:295-305`) becomes legacy/derived |
| `controller/tarif_opcional_precios.php` + view | Same matrix for a single opcional (writes price `en_catalogo` today); repoint catalog/active flags |
| `controller/tarif_configurador_opcionales.php` | Largely unchanged (it manages family/article scoped relations + tags); may gain master toggles |
| `View/Macro/TarifarioComponents.html.twig:334-351` | `toggle_button_group(en_catalogo/en_tarifa/activa)` macro already exists and is reusable for the master |
| `plugins/tarifario/controller/tarif_catalogo_view.php:2106-2107` | Catalog/export read of global ext flags; repointing to per-tarifa flags is a **hybrid** (tarifario) decision |

**Stays**: family/article override semantics, tags, configurator hierarchy, price history,
`catalogo_opcionales` XML/API, slugs/class names.

---

## 7. Retrocompatibility

- **`catalogo_opcionales` API/XML**: MUST remain untouched (`OpcionalDomainModelOwnershipTest:185-211`
  locks it; the absorption change declared `additive only`).
- **`tarif_opcional_ext`**: `ref_sap` stays global; `en_catalogo`/`en_tarifa` become
  legacy/derived (or a cross-tarifa default). Retaining the table avoids breaking
  `tarif_opcional` hydration (`:107-126,169-174,198-199`) and the ext-migration test.
- **`tarif_opcional` public API**: keep `get_precio_tarifa`, `precio_en_tarifa`,
  `search(...)/count_filtered(...)` signatures; changing `$solo_activos` from "has price"
  to "master activa" is a behavior change — lock it with tests.
- **`tpvmod` (read-only, DO NOT modify)**: `plugins/tpvmod/lib/tpvmod_opcionales.php:120-121`
  requires only `catalogo_core/model/core/catalogo_opcional*.php`; it consumes base catalog
  classes, not the tarifa master. Assess only; expected no change.
- **Existing DBs**: inheritance + optional lazy seeding means no mandatory data migration.

---

## 8. Tests currently covering per-tarifa opcional behavior

| Test | Asserts |
|---|---|
| `tests/TarifTarifaOpcionalFamiliaTest.php` | Inheritance (missing row ⇒ active; explicit `activo=FALSE` disables only that tarifa); query predicates; `set_activo` |
| `tests/TarifTarifaArticuloOpcionalTest.php` | `sync_articulo_overrides`/`sync_familia_overrides` persist effective set + counts; short-circuits |
| `tests/TarifTarifaOpcionalResolverTest.php` | Tag intersection rules (`filtrar_por_etiquetas`), untagged included, tagged only on overlap |
| `tests/TarifTarifaOpcionalEtiquetaTest.php` | `replace_etiquetas_opcional` normalization/idempotence; per-tarifa isolation |
| `tests/TarifOpcionalesControllerContractTest.php` | 4 controllers in catalogo_core, extend `fbase_controller`, zero tarifario requires, use the state trait |
| `tests/TarifConfiguradorOpcionalesTest.php` | Configurator fork consts/helpers, hierarchy actions, CSRF/POST guard, resolver uses no tarifario classes |
| `tests/OpcionalDomainModelOwnershipTest.php` | Moved models/XMLs + `ref_sap/en_catalogo/en_tarifa` confined to `tarif_opcional_ext`; `catalogo_opcionales` clean |
| `tests/OpcionalPriceUnificationTest.php` / `tests/TarifOpcionalPreciosControllerTest.php` | Canonical `catalogo_opcional_precios`, `codlista` reads, no legacy table |
| `tests/InitOpcionalesTablesTest.php` | FK-safe standalone bootstrap of the 5 moved tables; idempotence |

**Gap**: no test exists for a per-(tarifa, opcional) master (none exists yet). New tests
will be needed for master CRUD, inheritance/defaulting, list derivation, and copy/seeding.

---

## 9. Explicit product decisions to confirm before propose

These semantics are **not inferable** from the code:

1. **Activation source of truth**: does the new master `activa` **replace** "active = a
   price row exists", or complement it? What happens when they disagree?
2. **Global ext flags**: are `tarif_opcional_ext.en_catalogo/en_tarifa` deprecated, kept as
   a cross-tarifa default/fallback, or removed (they are test-locked to the ext table)?
3. **Two/three catalog flags**: per-tarifa master `en_catalogo` vs per-lista
   `catalogo_opcional_precios.en_catalogo` vs global ext `en_catalogo` — which wins, or
   should some be collapsed?
4. **`orden`**: does the master carry `orden`? If yes, what is its precedence vs
   `tarif_tarifa_opcional_familia.orden` and `tarif_tarifa_articulo_opcional.orden`?
5. **Seeding strategy**: default-tarifa seed at install (familias-style), lazy per tarifa,
   explicit copy on tarifa creation, or a one-time backfill?
6. **`tarif_catalogo_view` export (tarifario)**: repoint to per-tarifa flags (hybrid change)
   or keep reading global ext flags?
7. **Migration**: backfill master rows from existing price rows (mirror
   `TarifOpcionalExtMigration`) or purely additive/lazy with inheritance?
8. **List "Estado" column**: becomes per-tarifa `activa`, or stays global base `activo`?
9. **Configurador scope**: keep managing only family/article-level `activo`, or also expose
   the new opcional-level `activa`?

---

## Affected Areas

- `plugins/catalogo_core/model/table/` — new `tarif_tarifa_opcional.xml` (+ model).
- `plugins/catalogo_core/model/` — new `tarif_tarifa_opcional.php`; `tarif_opcional.php`,
  `tarif_opcional_ext.php`, `tarif_tarifa_opcional_familia.php`, `tarif_tarifa_articulo_opcional.php`.
- `plugins/catalogo_core/controller/` — `tarif_opcionales.php`, `tarif_opcional_edit.php`,
  `tarif_opcional_precios.php`, `tarif_configurador_opcionales.php`.
- `plugins/catalogo_core/View/` — `tarif_opcionales.html.twig`, `tarif_opcional_edit.html.twig`,
  `tarif_opcional_precios.html.twig`, `Macro/TarifarioComponents.html.twig`.
- `plugins/catalogo_core/Init.php` — bootstrap list + optional seed.
- `plugins/catalogo_core/tests/` — new master tests + repointed list/edit tests.
- **Hybrid (only if decided)**: `plugins/tarifario/controller/tarif_catalogo_view.php`
  (export read), `plugins/tarifario/controller/tarif_tarifas.php` (`heredar_estructura` copy).

---

## Risks

- Semantic collision between price-row activation and a new `activa` flag → divergent
  states if precedence is undefined.
- Triple `en_catalogo` (master / price / ext) → data drift and UI confusion.
- Cross-plugin coupling: the only real per-tarifa consumer of the global flags is
  `tarif_catalogo_view` (tarifario); leaving it global means the change does not fully fix
  the export mismatch.
- Additive-only constraint is test-locked (`OpcionalDomainModelOwnershipTest`): promoting
  flags into `catalogo_opcionales` would break the absorption contract.
- `tpvmod` must not be modified; any behavior coupling must be assessed read-only.
- `copy_precios_opcionales` already drops `en_catalogo`/`porcentaje` on tarifa copy
  (`tarif_tarifas.php:270-272`) — a copy path that must be revisited for consistency.

## Ready for Proposal

**Yes**, provided the 9 product decisions above are resolved first. Recommended next step:
`sdd-propose` for `opcionales-por-tarifa`, landing on **Option A** (full per-tarifa master)
with the collision rules (activation/catalog/order precedence) written into the proposal.
