# Design: Migrar Familias de Tarifario a Catalogo Core (Migración Acotada)

## Technical Approach

`catalogo_core` absorbs the family-tree catalog surface (hierarchical family UI, per-list family structure, per-list article assignment, catalog view) using the **extension-table pattern** already proven by `tarif_familia_ext`: the base `familias` table and `model/core/familia.php` stay read-only. Two new canonical tables (`catalogo_familia_estructura`, `catalogo_articulo_familia_estructura`) map 1:1 from the legacy `tarif_tarifa_familia` / `tarif_tarifa_articulo` schemas (verified in `plugins/tarifario/model/table/`), with `codtarifa` renamed to `codlista` and FK retargeted from `tarif_tarifas` to `catalogo_listas_precio`. A non-destructive, idempotent migration service (following `CatalogLegacyTableMigration`) copies legacy data without dropping anything. The tree UI extends the existing `VentasFamilias` page (name preserved → `fs_access` intact). Integration with `tarifario` stays event/hook-based; no `require` in either direction.

## Architecture Decisions

### D1: Extension tables, not base-table or `familia.php` changes

**Choice**: per-list structure lives in two new tables, `catalogo_familia_estructura` and `catalogo_articulo_familia_estructura`. The `familias` base table keeps only `madre`/`nivel` (hierarchy already there); per-list display state (`capitulo`, `orden`, `en_catalogo`, `en_tarifa`, `activa`) lives in the extension rows keyed `(codlista, codfamilia)`. This is exactly the `tarif_familia_ext` pattern (1:N extension with FK CASCADE) promoted from tarifa-scoped to list-scoped.

**Alternatives**: adding columns to `familias` (rejected — spec R-FJ-001 "Base table untouched"; also columns would be per-list in nature and `familias` is list-agnostic); reusing `tarif_familia_ext` (rejected — `catalogo_core` must not write tarifario tables).

### D2: Exact column mapping (verified against legacy XML)

**`tarif_tarifa_familia` → `catalogo_familia_estructura`** (new file `model/table/catalogo_familia_estructura.xml`):

| Legacy column (`tarif_tarifa_familia`) | New column (`catalogo_familia_estructura`) | Type | Notes / risk |
|---|---|---|---|
| `codtarifa` varchar(20) NOT NULL | `codlista` varchar(20) NOT NULL | FK → `catalogo_listas_precio.codlista` ON DELETE CASCADE | Rename D7 (multitarifa); values migrated as-is because `syncDefaultPriceListFromTarifario` seeds lists using the same `codtarifa` codes |
| `codfamilia` varchar(8) NOT NULL | `codfamilia` varchar(8) NOT NULL | FK → `familias.codfamilia` ON DELETE CASCADE | unchanged |
| `madre` varchar(8) NULL | `madre` varchar(8) NULL | no FK (application-level, mirrors legacy) | legacy had no FK here; keep same to tolerate rows where the per-list mother is created after children |
| `capitulo` varchar(50) NULL DEFAULT '' | `capitulo` varchar(50) NULL DEFAULT '' | — | verbatim |
| `nivel` varchar(50) NULL DEFAULT '' | `nivel` varchar(50) NULL DEFAULT '' | — | verbatim. **Risk (semantic)**: legacy `tarif_familia_ext.nivel` is a *category label* while `familia::aux_all()` derives a *display-depth* prefix (`'· '`, `'&nbsp;&nbsp;· '`). The extension `nivel` column is carried verbatim as display metadata and MUST NOT be conflated with `familias.nivel`; the tree UI renders depth from the `madre` chain, not from this string |
| `en_catalogo` boolean NOT NULL DEFAULT TRUE | `en_catalogo` boolean NOT NULL DEFAULT TRUE | — | verbatim |
| `en_tarifa` boolean NOT NULL DEFAULT FALSE | `en_tarifa` boolean NOT NULL DEFAULT FALSE | — | verbatim. **Risk (semantic)**: `en_tarifa` historically means "export to tariff PDF" — in `catalogo_core` it has no pricing effect (spec R-MT-006: price resolution never joins family structure). It is carried verbatim as a *view-visibility flag* only; the catalog view honors `en_catalogo`, not `en_tarifa`. Documented in the model PHPDoc to prevent future misuse |
| `activa` boolean NOT NULL DEFAULT TRUE | `activa` boolean NOT NULL DEFAULT TRUE | — | verbatim |
| `orden` integer NOT NULL DEFAULT 0 | `orden` integer NOT NULL DEFAULT 0 | — | verbatim |
| PK `(codtarifa, codfamilia)` | PK `(codlista, codfamilia)` | — | — |
| FK to `tarif_tarifas` | FK to `catalogo_listas_precio` | — | retargeted; CASCADE per spec R-FJ-002 |

**`tarif_tarifa_articulo` → `catalogo_articulo_familia_estructura`** (new file `model/table/catalogo_articulo_familia_estructura.xml`):

| Legacy column | New column | Notes |
|---|---|---|
| `codtarifa` varchar(20) NOT NULL | `codlista` varchar(20) NOT NULL | FK → `catalogo_listas_precio` CASCADE |
| `referencia` varchar(18) NOT NULL | `referencia` varchar(18) NOT NULL | FK → `articulos` CASCADE |
| `codfamilia` varchar(8) NULL | `codfamilia` varchar(8) NULL | **No FK on purpose** — the legacy XML documents exactly why (family may not exist yet in the list; partial-composite SET NULL impossible). Integrity is application-level, mirroring the legacy comment. FK only to `articulos` and `catalogo_listas_precio` |
| `en_tarifa` boolean NOT NULL DEFAULT TRUE | `en_tarifa` boolean NOT NULL DEFAULT TRUE | verbatim; view-visibility flag only (same semantic risk as above) |
| `en_catalogo` boolean NOT NULL DEFAULT TRUE | `en_catalogo` boolean NOT NULL DEFAULT TRUE | verbatim |
| `orden` integer NOT NULL DEFAULT 0 | `orden` integer NOT NULL DEFAULT 0 | verbatim |
| PK `(codtarifa, referencia)` | PK `(codlista, referencia)` | — |

**Deliberate legacy gap**: `tarif_tarifa_articulo.codfamilia` gets NO FK to `catalogo_familia_estructura`, same as legacy — the migration still skips/log assignment rows whose `(codlista, codfamilia)` have no migrated structure row (orphan handling, D5), but runtime saves may create assignment rows for a family without a structure row (matches legacy behavior 2–3 in the XML comment).

**Models**: `model/core/catalogo_familia_estructura.php` and `catalogo_articulo_familia_estructura.php` — `fs_model` subclasses with `test()`, `save()`, `delete()`, `exists()`; upsert by composite PK (no duplicate on re-save, spec R-FJ-002/003); `declare(strict_types=1)`, namespace `FSFramework\model` (matches siblings like `catalogo_lista_precio`).

### D3: Migration service — new `Services/FamiliaEstructuraMigration`, copy-not-rename

**Choice**: unlike the multitarifa renames, these are **copies with column rename + FK retarget**, so a new service `FamiliaEstructuraMigration` (static `migrateIfNeeded(\fs_db2 $db)`, same shape as `CatalogLegacyTableMigration`) instead of extending `TABLE_RENAMES`. SQL is set-based (`INSERT ... SELECT`), dual-engine (Postgres / MySQL), no ORM row loops.

Ordering (FK-safe, spec R-FJ-005):

1. **Precondition — lists exist**: ensure `catalogo_listas_precio` is populated (it already is via multitarifa install + `syncDefaultPriceListFromTarifario`; no new list sync here).
2. **`tarif_tarifa_familia` → `catalogo_familia_estructura`**: `INSERT INTO catalogo_familia_estructura (codlista, codfamilia, madre, capitulo, nivel, en_catalogo, en_tarifa, activa, orden) SELECT ... FROM tarif_tarifa_familia t` joined against `catalogo_listas_precio` on `t.codtarifa = l.codlista`. Orphans (`codtarifa` with no matching list) are **skipped by the inner join** and counted via a preceding `SELECT COUNT(*)` diff → written to `error_log('[catalogo_core] familia-estructura migration: N orphan rows skipped (unmatched codlista)')` + migration log table row if precedent exists. `madre` carried verbatim (no FK).
3. **`tarif_tarifa_articulo` → `catalogo_articulo_familia_estructura`**: same join pattern against `catalogo_listas_precio` (orphan `codtarifa` → skip + log). `codfamilia` NULL rows are migrated as NULL (no FK); non-NULL rows whose `(codlista, codfamilia)` lacks a structure row are migrated anyway (mirrors legacy FK-less behavior) but counted in the log.

**Idempotency**: engine-native "do nothing" on PK conflict — `INSERT ... ON CONFLICT DO NOTHING` (Postgres) / `INSERT IGNORE` (MySQL). Re-running with unchanged legacy data inserts zero rows (testable). **Non-destructive**: no `DROP`, no `DELETE`, no `UPDATE` on any `tarif_*` table (grep-auditable: the service contains no DELETE statement at all).

**Transactions**: each table-copy step wrapped in `beginTransaction()/commit()` with `rollback()` on Throwable; on rollback the step is skipped, error logged, and subsequent steps still attempt (orphan-skip philosophy: partial migration is resumable because idempotent).

**Lifecycle hook**: called from `catalogo_core/Init.php` boot path alongside the existing `CatalogLegacyTableMigration::migrateIfNeeded()` call site — but **guarded by a feature flag** (`CatalogoOptions::familyStructureEnabled()`, default **false**): the migration only runs after the admin enables the family-structure capability in `opciones_catalogo` (or on fresh install where the flag is auto-on). Rationale: enabling the capability is the operator's explicit "go live" moment; before it, both data sources coexist harmlessly and zero rows are copied.

**Fresh installs**: the XML tables are created empty by the normal install sequence (D5); the migration is a no-op when `tarif_tarifa_familia` doesn't exist (early return via `tableExists()` guard, precedent exists).

### D4: Install ordering

**Choice**: extend the `Init::ensureCatalogTables()` / `DEFAULT_SEED_MODELS` precedent. Order: `catalogo_listas_precio` (already installed by multitarifa) → `catalogo_familia_estructura` → `catalogo_articulo_familia_estructura` (spec R-MT-006 scenario). XMLs declare the FKs; the models are instantiated in that order so `fs_model` creates dependent tables after their parents. Add `catalogo_familia_estructura` and `catalogo_articulo_familia_estructura` to the ensure-list immediately after `catalogo_articulo_precios`.

### D5: UI design — extend `VentasFamilias`, selector for list, no new primary page

**Choice**: the existing `Controller/VentasFamilias.php` (page name `ventas_familias`, menu `ventas`, PSR-4 `PageController`) is extended, NOT renamed — `fs_access` rows keep working (spec R-FJ-006). `familia.php` and the `familias` table are untouched.

- **Tree rendering**: reuse `familia::all()` (already returns the flattened hierarchical list via `aux_all()` with depth-prefixed `nivel`) for the default view, and `hijas()`/`madres()` for lazy re-parenting forms — exactly what the current controller does. The template switches from the flat search list to a nested `<ul>` tree (depth derived from `madre` chain client-side via the aux-prefixed `nivel`, or server-side by counting chain hops — implementation detail, depth is derived, never stored). Cycle rejection (R-FJ-001) is enforced in the **controller's save path** (`editarFamilia`): before saving, walk the `madre` chain of the target mother; if it reaches the child, `$this->new_error_msg()` and abort. (Model-level would be cleaner but `familia.php` is read-only per spec; the controller-level check is the extension point available. A dedicated service `Services/FamiliaTreeGuard::assertAcyclic()` keeps it testable.)
- **Per-list structure editing**: a **list selector** (dropdown of `catalogo_listas_precio`, only `activa = TRUE`, multitariff-flag-gated like D6 of multitarifa) at the top of the page. The selected `codlista` drives an "Estructura por lista" column set (capitulo, orden, en_catalogo, en_tarifa, activa) rendered next to each tree node, reading `catalogo_familia_estructura` rows for that list (single `SELECT ... WHERE codlista = ?` batch, no N+1 per node). Saving a node's structure upserts the extension row via the new model. No tabs-per-list — one list in context at a time keeps the tree readable and the template simple; switching lists is a GET param reload (`?codlista=...`).
- **Article assignment**: absorbed into the existing `VentasArticulo` page as a new "Asignación a familias" section/tab (per-list, same selector pattern), writing `catalogo_articulo_familia_estructura` — mirrors how multitarifa added the "Precios por lista" tab. Bulk family-side assignment (list all articles under a family node) lives in the `VentasFamilias` node detail, using `articulo::all_from_familia()`.
- **Catalog view**: new page `Controller/VentasCatalogoView.php` (page name `ventas_catalogo_view`, menu `ventas`, `showonmenu` admin-only) rendering the tree filtered by the selected list: nodes where `activa = TRUE` AND `en_catalogo = TRUE`, articles from `catalogo_articulo_familia_estructura` where `en_catalogo = TRUE`, scoped strictly to `codlista` (spec R-FJ-004). This is a NEW page (the old tarifario `tarif_catalogo_view` keeps its own URL and is tarifario's problem until follow-up deprecation), so no `fs_access` risk — first admin visit creates the row.
- **Frozen hooks untouched**: `VentasArticulo` / `VentasOpcional` keep rendering `ViewHookRegistry` hook points (`ventas_articulo_tabs_after`, etc.) exactly as today; the absorbed UI adds tabs/blocks in catalogo_core's own templates and does not depend on the `@tarifario` hooks (spec R-FJ-007). `HookRegistrationTest` stays green because hook names, registration side, and template paths don't change in this change.
- **Views**: Twig templates under `plugins/catalogo_core/View/` (`ventas_familias.html.twig` extended with tree + structure columns; `ventas_catalogo_view.html.twig` new; `ventas_articulo` gains the assignment block).

### D6: Cross-plugin contract — catalogo_core stays dep-free

**Hard rule (grep-auditable)**: no `require`, `require_once`, `use`, or class reference to anything under `plugins/tarifario/` in `catalogo_core`, and no `require` declaration toward `tarifario` in `fsframework.ini`. Direction of knowledge is by event only:

1. **`ArticlePermissionFilterEvent`** (existing, `FSFramework\Plugins\catalogo_core\Event\ArticlePermissionFilterEvent`): the new write paths (family-structure saves, article assignment saves, catalog view actions that mutate) dispatch the event with the affected action/referencia so `tarifario`'s `ArticlePermissionListener` (registered at boot, class_exists-guarded) keeps gating — zero coupling, already proven by multitarifa D2.
2. **`ViewHookRegistry` compatibility (R-FJ-007)**: `catalogo_core` MUST NOT remove/rename/shadow the 4 hook points in `ventas_articulo`/`ventas_opcional` templates. The absorbed UI renders from catalogo_core's own views; the tarifario hooks keep resolving via `@tarifario/Hooks/*.html.twig` during Twig build (TwigLoaderEvent namespace + TwigInitEvent registration, verified in `plugins/tarifario/Init.php`). Deprecating the hooks is a tarifario follow-up change.
3. **Data reads**: `catalogo_core` reads ONLY its own tables (`familias`, `catalogo_*`). It never queries `tarif_tarifa_familia`/`tarif_tarifa_articulo` outside the migration service (which reads legacy tables one-way, copy-only, during migration). `tarifario` keeps reading/writing its own tables (frozen, read-only after migration); its family UI continues working until its follow-up deprecation — the two UIs coexist showing the same data through the two tables.
4. **No dual-write (R-MT-006)**: writes go only to `catalogo_familia_estructura`. The frozen `tarif_*` tables receive no writes from this change; the coexistence divergence (edits after migration only in canonical tables) is accepted and documented as the migration-cutover semantics.

### D7: Translations

All new keys prefixed `catalogo-core-` in `plugins/catalogo_core/translations/messages.es.yaml` (+ fallback locale), per spec R-FJ-008. Seed set: `catalogo-core-familias-tree-title`, `catalogo-core-familias-lista-selector`, `catalogo-core-familias-estructura-capitulo`, `catalogo-core-familias-estructura-orden`, `catalogo-core-familias-estructura-en-catalogo`, `catalogo-core-familias-estructura-en-tarifa`, `catalogo-core-familias-estructura-activa`, `catalogo-core-familias-ciclo-error`, `catalogo-core-articulos-asignacion-tab`, `catalogo-core-catalogo-view-title`, `catalogo-core-catalogo-view-empty`. No unprefixed keys; no copied tarifario texts (tarifario ships no translations — the UI copy is authored fresh, prefixed).

## Data Flow

```
Init.php boot
  └─ ensureCatalogTables(): ... → catalogo_familia_estructura → catalogo_articulo_familia_estructura
  └─ flag on (CatalogoOptions::familyStructureEnabled()):
       FamiliaEstructuraMigration::migrateIfNeeded($db)
         step 1: tarif_tarifa_familia  → catalogo_familia_estructura   (JOIN catalogo_listas_precio, orphan skip+log)
         step 2: tarif_tarifa_articulo → catalogo_articulo_familia_estructura (JOIN catalogo_listas_precio, orphan skip+log)
         each step: transaction + idempotent INSERT (ON CONFLICT DO NOTHING / INSERT IGNORE)
         zero DELETE/DROP/UPDATE on tarif_*

VentasFamilias (page ventas_familias, unchanged name)
  familia::all()/hijas()/madres()  → tree render (depth derived from madre chain)
  + list selector (activa lists from catalogo_listas_precio)
  + estructura columns per selected codlista ← catalogo_familia_estructura (batch read)
  save node: CSRF → FamiliaTreeGuard::assertAcyclic → familia save (base) ; estructura save (extension upsert)
  re-parent cycle → new_error_msg, no write

VentasArticulo → "Asignación a familias" block → catalogo_articulo_familia_estructura upsert
                → dispatch ArticlePermissionFilterEvent → tarifario listener (gates, no coupling)

VentasCatalogoView (new page) → tree WHERE activa ∧ en_catalogo (per codlista) + assignments → render
  list identity: ONLY catalogo_listas_precio.codlista (never tarif_tarifas)

Frozen hooks: ViewHookRegistry points unchanged; @tarifario/Hooks/* keep rendering (R-FJ-007)
```

## File Changes

| File | Action |
|---|---|
| `model/table/catalogo_familia_estructura.xml` | Create (mapping D2) |
| `model/table/catalogo_articulo_familia_estructura.xml` | Create (mapping D2) |
| `model/core/catalogo_familia_estructura.php`, `model/core/catalogo_articulo_familia_estructura.php` | Create (fs_model CRUD, composite-PK upsert) |
| `Services/FamiliaEstructuraMigration.php` | Create (D3) |
| `Services/FamiliaTreeGuard.php` | Create (cycle check, unit-testable) |
| `Services/CatalogoOptions.php` | Modify (`familyStructureEnabled()` flag, default false) |
| `Init.php` | Modify (ensure-tables order D4; flag-gated migration call) |
| `Controller/VentasFamilias.php` + `View/ventas_familias.html.twig` | Modify (tree UI, list selector, structure editor) |
| `Controller/VentasArticulo.php` + view | Modify (asignación block, per-list) |
| `Controller/VentasCatalogoView.php` + `View/ventas_catalogo_view.html.twig` | Create (catalog view per list) |
| `controller/` legacy wrappers if page discovery requires them | Create (mirror pattern of existing pages) |
| `translations/messages.es.yaml` (+ fallback) | Modify (D7 prefixed keys) |
| `tests/` | Create (see Testing Strategy) |

## Interfaces / Contracts

```php
final class FamiliaEstructuraMigration {
    public static function migrateIfNeeded(\fs_db2 $db): void;
    // reads legacy tables ONLY here; never deletes them
}
final class FamiliaTreeGuard {
    /** @throws \RuntimeException on cycle */ public static function assertAcyclic(\fs_db2 $db, string $codfamilia, string $newMadre): void;
}
final class CatalogoOptions { public function familyStructureEnabled(): bool; } // default false
// models:
catalogo_familia_estructura:          PK (codlista, codfamilia), FKs → catalogo_listas_precio CASCADE, familias CASCADE
catalogo_articulo_familia_estructura: PK (codlista, referencia), FKs → catalogo_listas_precio CASCADE, articulos CASCADE; codfamilia NULL-able, NO FK (legacy parity)
```

## Testing Strategy

Run via `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`; plugin tests under `plugins/catalogo_core/tests/` (root Plugins suite also discovers them). Conventions: `Tests\` namespace, anonymous `fs_model` subclasses for pure-method tests, mock `fs_db2` for query builders, reflection reset of static state.

| Layer | Test | Covers |
|---|---|---|
| Unit | `FamiliaEstructuraMigrationTest` — dual-engine SQL assertions with mock db (records executed SQL): FK order (step1 before step2), `INSERT IGNORE`/`ON CONFLICT` present (idempotency), zero DELETE/DROP/UPDATE statements in generated SQL, orphan-skip join present, early-return when legacy table missing | R-FJ-005 (idempotency, non-destructive, FK order, orphans) |
| Unit | `FamiliaTreeGuardTest` — chain-walk with mock db: direct cycle, deep cycle, self-parent, valid chain passes | R-FJ-001 cycle scenario |
| Unit | Extension model tests — upsert by composite PK (re-save updates, no duplicate), `test()` validation, cascade declarations present in XML parse; anonymous fs_model subclass pattern | R-FJ-002/003 one-row-per-key |
| Integration (ddev, DB) | Migration idempotency round-trip: seed legacy rows (incl. one orphan codtarifa), run migration twice, assert row counts stable + legacy tables untouched + orphan logged | R-FJ-005 full scenarios |
| Integration | Tree rendering: seed familias with madre chain, assert `familia::all()` flattened order + controller tree data; re-parent POST (CSRF) updates `familias.madre`; cycle POST rejected with error, no row modified | R-FJ-001 scenarios |
| Integration | fs_access preservation: page name `ventas_familias` unchanged in `getPageData()`; new `ventas_catalogo_view` under `ventas` menu | R-FJ-006 |
| Regression | Hook compat: existing `HookRegistrationTest` (tarifario) stays green — asserted in verify, not modified; add catalogo_core test asserting the 4 hook points still present in `ventas_articulo`/`ventas_opcional` templates | R-FJ-007 |
| Unit | Translation audit test — every key used by new templates starts with `catalogo-core-` and resolves in `messages.es.yaml` | R-FJ-008 |

## Assumptions

1. **`catalogo_listas_precio.codlista` values match legacy `tarif_tarifas.codtarifa` codes** for migrated lists (true today: `syncDefaultPriceListFromTarifario` inserts lists keyed by `codtarifa`). If a legacy tarifa has no list, its family-structure rows are orphans → skipped + logged (accepted data loss for lists never migrated; recoverable by creating the list and re-running the idempotent migration).
2. **Feature flag default off**: family-structure capability is opt-in via `CatalogoOptions` (multitarifa precedent D6). On fresh installs without tarifario, migration early-returns; tables install empty.
3. **`madre` inside `catalogo_familia_estructura` stays FK-less** (legacy parity) — the tree's authoritative hierarchy is `familias.madre`; the extension `madre` is per-list display metadata only. If schema review disagrees, adding a deferrable FK is a follow-up, not a scope change here.
4. **Cycle check is controller/service-level** (not in `familia.php`) because the spec forbids modifying the base model; `familia::fix_db()` self-heal behavior remains as a secondary net.
5. **`en_tarifa` carries no pricing semantics** in catalogo_core (R-MT-006); it's a view flag. Any future meaning change belongs to tarifario follow-ups.
6. **Depth in the tree is derived** from the `madre` chain, never persisted beyond the legacy `nivel` display string carried verbatim.
7. **No new Composer dependencies**; no `vendor/` commit step needed.
8. **tarifario's family UI keeps working unchanged** during coexistence; post-migration edits diverge (canonical tables win). Deprecation is the referenced follow-up, out of scope.
9. **Controller/view placement mirrors current catalogo_core practice**: PSR-4 `Controller/` pages with legacy wrappers only where page discovery requires them (verified pattern in `Controller/VentasFamilias.php`).
10. **Hook templates and names are frozen** — this change adds no ViewHookRegistry registrations and alters no existing template hook points.
