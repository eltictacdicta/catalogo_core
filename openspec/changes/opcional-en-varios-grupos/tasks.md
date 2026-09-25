# Tasks: opcional-en-varios-grupos

- **Change**: `opcional-en-varios-grupos`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Artifact store**: `openspec`
- **This artifact**: `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/tasks.md`
- **Consumers touched**: `plugins/tarifario` (two bulk-delete cleanups + one shared-read predicate), `plugins/tpvmod` (cart-add dedupe + grouped detection)
- **Inputs read**: `proposal.md`, `specs/opcionales-management/spec.md` (delta, 12 requirements), `design.md` (AD-1..AD-16), `compatibility-tarifario.md`, `pending-decisions.md` (both resolution tables)

## Session configuration (verbatim)

- `execution_mode: auto`
- `artifact_store: openspec`
- `delivery_strategy: auto-chain`
- `chain_strategy: NOT YET CHOSEN` (orchestrator asks only because the forecast below flags over-budget)
- `review_budget_lines: 800`
- **STRICT TDD MODE IS ACTIVE for `catalogo_core`.** Do not fall back to standard mode.

## Test runners

| Scope | Command |
|---|---|
| catalogo_core (focused/primary) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` |
| tpvmod | `ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml` |
| tarifario | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` |
| Root regression | `ddev exec php vendor/bin/phpunit` |

Tests are DB-free (fake `fs_db2` / anonymous subclasses), mirroring the existing patterns in `plugins/catalogo_core/tests/`.

## Confirmed scope decisions (do NOT re-litigate)

- `scope_include_companions`: this change includes the `tpvmod` companion edits (cart-add dedupe AD-12/AD-13) and BOTH `tarifario` bulk-delete cleanups (`limpiar_opcionales()` and `limpiar_todo()`). Commits land per plugin repo.
- `membership_preserve_all`: checkbox = active ∪ already-assigned; the "Grupo" column lists ALL memberships (including inactive groups); `set_grupos()` is lossless.
- `column_freeze_then_drop`: NO `ALTER TABLE ... DROP COLUMN id_grupo` in this change. The physical column and its data survive.

## How to read this file

- Work units are `WU-0` … `WU-6`. `WU-1`…`WU-6` map 1:1 to the design's "Migration / Rollout (work units)" revert boundaries (`design.md` § Migration / Rollout).
- `WU-0` is a docs-only pre-apply unit; it changes no code and does not alter any revert boundary.
- Every task ID is `WU-x.Tn`.
- Strict-TDD rule per WU: the listed tests are written/extended FIRST and MUST fail (RED) before the implementation tasks; then implementation; then the run (GREEN).
- Revert boundaries are applied in REVERSE WU order (a later unit's revert assumes the earlier units' bridge/API still exist).

---

## Work-unit overview

| WU | Goal | Design revert boundary | Depends on | Est. lines |
|---|---|---|---|---|
| WU-0 | Reconcile SDD drift (proposal, delta Test annotations, path audit) | docs only | — | ~15 |
| WU-1 | Bridge table + model + migration + boot ensure | WU-1 | — | ~700 |
| WU-2 | `catalogo_opcional` membership API + `save()` freeze + cascade | WU-2 | WU-1 | ~450 |
| WU-3 | Dependent readers (group model, articulo_opcional, familia, VentasArticulo, resolver) | WU-3 | WU-1, WU-2 | ~600 |
| WU-4 | Controllers + trait + views (checkbox list, batched label map, VentasOpcionalGrupo 4 sites) | WU-4 | WU-1, WU-2 | ~550 |
| WU-5 | tpvmod dedupe + grouped detection + tarifario two bulk-delete cleanups | WU-5 | WU-2 (AD-6/AD-13) | ~250 |
| WU-6 | Verify pass (suites, grep audit, phpstan, smoke) — no production change | WU-6 | all | ~0 |

**Dependency note**: WU-2, WU-3 and WU-4 form a single *green commit boundary* (the
"membership cutover"). AD-4 (arity change / method removal) and AD-6 (property
removal) make every reader and the `VentasOpcionalGrupo` controller
compile/runtime-coupled: after WU-2 lands, the repo only returns to a green suite
once WU-3 and WU-4 land. Author them in WU order, but commit/PR them together
(see Review Workload Forecast slices).

---

## WU-0 — Pre-apply documentation reconciliation (docs only)

**Goal**: fix the known non-blocking drift before implementation so the delta and
proposal match the design (`AD-7`, `AD-14`) and the change stays plugin-local.

**Files**: `proposal.md`, `specs/opcionales-management/spec.md`.

**Dependency order**: none; run before WU-1.

**Tasks (RED/GREEN does not apply — docs)**:

- [x] `WU-0.T1`: `proposal.md:42-43` names only the `limpiar_opcionales()` bulk-delete
  path (citing `:2182-2214`). Update that bullet to name **BOTH** `tarifario`
  bulk-delete paths — `Services/ArticuloListActionHandler.php::limpiar_opcionales()`
  **and** `::limpiar_todo()` — mirroring `design.md` § tarifario compatibility
  (AD-7/AD-14) and `compatibility-tarifario.md` C2/C2b.
- [x] `WU-0.T2`: add inline `- Test:` annotations to the three `OPG-09` scenarios in
  `specs/opcionales-management/spec.md` (OPG-06/OPG-07 already have them):
  - "Resolved-for-sale read yields one occurrence" →
    `plugins/catalogo_core/tests/CatalogoOpcionalGrupoTest.php`
  - "Group-scoped presentation still lists each group" →
    `plugins/catalogo_core/tests/CatalogoOpcionalGrupoTest.php`
  - "Second selection of the same id is deduped" →
    `plugins/tpvmod/tests/TpvmodOpcionalDedupeTest.php`
- [x] `WU-0.T3`: verify ALL artifacts of this change live under
  `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/` and that no
  `openspec/changes/opcional-en-varios-grupos/` entry exists in the core tree.

**Verification command**:
```bash
test ! -e openspec/changes/opcional-en-varios-grupos && echo "core tree clean"
grep -c "Test:" plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/specs/opcionales-management/spec.md
```

**Revert boundary**: revert the two docs files; no production impact.

---

## WU-1 — Bridge table, model, migration and boot ensure (design WU-1)

**Goal**: `catalogo_opcional_grupo_rel` exists (PG + MySQL), the bridge model is
usable, and `migrateOpcionalGroupRelations()` creates + idempotently backfills it
from `id_grupo` before any future drop.

**Files**:
- NEW `model/table/catalogo_opcional_grupo_rel.xml`
- NEW `model/core/catalogo_opcional_grupo_rel.php`
- `Services/CatalogLegacyTableMigration.php`
- `Init.php`
- `tests/CatalogoOpcionalGrupoRelModelTest.php` (new)
- `tests/Integration/CatalogoOpcionalGroupMigrationTest.php` (new)
- `tests/Services/CatalogLegacyTableMigrationTest.php` (update)
- `tests/InitOpcionalesTablesTest.php` (update)

**Dependency order**: none (purely additive); first production unit.

### Tests first (must fail / RED)

- [x] `WU-1.T1`: write `tests/CatalogoOpcionalGrupoRelModelTest.php` (failing):
  bridge model `add` idempotency (second identical pair is a no-op via
  `exists_relation`), `remove`, `exists_relation`, `group_ids_for_opcional`,
  `map_for_opcionales` returning `[id_opcional => list<int>]` from **one** query,
  `delete_all_from_opcional`, `delete_all_from_grupo`. DDL source contract: both
  PG and MySQL create statements declare `PRIMARY KEY (id)` and
  `UNIQUE (id_opcional, id_grupo)` and the **absence** of
  `FOREIGN KEY` / `REFERENCES` / `ON DELETE CASCADE`. Covers **OPG-03**.
- [x] `WU-1.T2`: write `tests/Integration/CatalogoOpcionalGroupMigrationTest.php`
  (failing): `migrateOpcionalGroupRelations` exists and is called from
  `migrateIfNeeded()` **before** `migrateGroupedOptionalAssignments`; it uses the
  `LEFT JOIN` pre-check keyed on `(id_opcional, id_grupo)`; it emits the PG
  (`ON CONFLICT (id_opcional, id_grupo) DO NOTHING`) and MySQL (`INSERT IGNORE`)
  backfills; `migrateGroupedOptionalAssignments` joins
  `catalogo_opcional_grupo_rel`. Covers **OPG-03**.
- [x] `WU-1.T3`: extend `tests/Services/CatalogLegacyTableMigrationTest.php`
  (failing): seed the bridge table; assert the create + backfill statements and
  the rewritten grouped-assignment join. Covers **OPG-03**.
- [x] `WU-1.T4`: extend `tests/InitOpcionalesTablesTest.php` (failing):
  `ensureCatalogTables()` includes `catalogo_opcional_grupo_rel` and `init()`
  ensures it. Covers **OPG-03**.

**Run (RED)**:
```bash
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CatalogoOpcionalGrupoRelModel|CatalogoOpcionalGroupMigration|CatalogLegacyTableMigration|InitOpcionalesTables'
```

### Implementation

- [x] `WU-1.T5`: add `model/table/catalogo_opcional_grupo_rel.xml` — columns `id`
  (`serial`), `id_opcional` (`integer`), `id_grupo` (`integer`); constraints
  `catalogo_opcional_grupo_rel_pkey PRIMARY KEY (id)` and
  `catalogo_opcional_grupo_rel_unique UNIQUE (id_opcional, id_grupo)`
  (`design.md` § Bridge DDL).
- [x] `WU-1.T6`: add `model/core/catalogo_opcional_grupo_rel.php`
  (`FSFramework\model\catalogo_opcional_grupo_rel`) with the pinned surface:
  `add`, `remove`, `exists_relation`, `group_ids_for_opcional`,
  `map_for_opcionales`, `delete_all_from_opcional`, `delete_all_from_grupo`,
  `exists`, `save`, `delete`, `install` (`design.md` § Model API surface).
  `map_for_opcionales()` is the single batched reader:
  `SELECT id_opcional, id_grupo FROM catalogo_opcional_grupo_rel WHERE id_opcional IN (<ids>)`.
- [x] `WU-1.T7`: add
  `CatalogLegacyTableMigration::migrateOpcionalGroupRelations(\fs_db2 $db)`
  (create-if-missing, does **not** early-return) + private
  `hasPendingGroupRelations(\fs_db2 $db)`; call it in `migrateIfNeeded()`
  between `syncArticuloOpcionalGrupoTable($db)` and
  `migrateGroupedOptionalAssignments($db)` (`design.md` § Migration and backfill).
- [x] `WU-1.T8`: rewrite `migrateGroupedOptionalAssignments()` to join
  `catalogo_opcional_grupo_rel r ON r.id_opcional = ao.id_opcional` (PG + MySQL
  variants in `design.md` § `migrateGroupedOptionalAssignments()` rewrite); add
  `catalogo_opcional_grupo_rel` to the `tableExists` guard conjunction.
- [x] `WU-1.T9`: add `Init::ensureOpcionalGrupoRelTable()` called from `init()` next
  to `ensureArticuloOpcionalGrupoTable()` (`Init.php:60`, `:613-620`); add
  `catalogo_opcional_grupo_rel` to `ensureCatalogTables()` (`Init.php:622-637`).
  AD-15.
- [x] `WU-1.T10`: add a doc comment to `syncOptionalGroupColumns()`
  (`CatalogLegacyTableMigration.php:209-228`) stating it keeps the frozen column
  alive for rollback and is **NOT** the source of truth.

### Run (GREEN)

```bash
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
ddev exec php vendor/bin/phpunit
```

**Revert boundary (design WU-1)**: delete `model/table/catalogo_opcional_grupo_rel.xml`,
`model/core/catalogo_opcional_grupo_rel.php`, `migrateOpcionalGroupRelations()`,
`hasPendingGroupRelations()`, the `migrateGroupedOptionalAssignments()` rewrite,
and the two `Init` additions. The bridge becomes inert and `id_grupo` remains
readable.

---

## WU-2 — `catalogo_opcional` membership API, `save()` freeze, cascade (design WU-2)

**Goal**: set-valued membership API on the opcional; `id_grupo` is no longer read
or written by the model; delete cascades bridge rows.

> **B1 stage-safe status (apply batch 2)**: implemented as a **green intermediate
> slice**. The new bridge-backed membership API (`add_to_grupo`, `set_grupos`,
> `get_grupos`, `grupo_ids`, `grupos_labels`, `is_grouped`, `all_sin_grupo` /
> `all_not_in_grupo` / `all_activos_sin_grupo`, `where_id_grupo`) and the AD-7
> cascades landed. The WU-2 big-bang removals are **deliberately staged out of
> B1** to keep the tree green: `public $id_grupo` and the legacy
> `get_grupo()` / `etiqueta_grupo()` / `assign_to_grupo()` shims are kept, and
> `save()` **dual-writes** (writes the frozen column and reconciles the bridge)
> instead of freezing the write. See `apply-progress.md` §B1.

**Files**:
- `model/core/catalogo_opcional.php`
- `model/tarif_opcional.php` (guard only)
- `tests/CatalogoOpcionalMembershipTest.php` (new)
- `tests/CatalogoOpcionalesUnifiedControllerTest.php` (update)
- `tests/Integration/CatalogoCoreHookMarkersTest.php` (update)

**Dependency order**: after WU-1.

### Tests first (must fail / RED)

- [x] `WU-2.T1`: write `tests/CatalogoOpcionalMembershipTest.php` (failing):
  anonymous `catalogo_opcional` subclass with fake `fs_db2`. Covers **OPG-04,
  OPG-05, OPG-08**:
  - `add_to_grupo` inserts the bridge row and fires
    `catalogo_articulo_opcional::delete_all_from_opcional()` on the 0→≥1
    transition only;
  - `remove_from_grupo(int)` deletes only that row and does **not** restore
    direct relations;
  - `set_grupos()` diffs (`intval`, `>0`, `array_unique`), adds missing / removes
    unchecked, single first-membership cleanup;
  - `is_grouped()` / `grupos_labels()` / `grupo_ids()`; `get_grupos()` ordering;
  - `all_sin_grupo()` and `where_id_grupo()` render the `NOT EXISTS` / `EXISTS`
    bridge bodies; `all_not_in_grupo()` renders its anti-join;
  - source assertion: the class source reads/writes no `id_grupo`.
  **B1**: delivered green (20 tests) with the dual-write variant; the final
  "reads/writes no `id_grupo`" source assertion is deferred to the WU-3/WU-4
  cutover because B1 intentionally dual-writes the frozen column.
- [x] `WU-2.T2`: extend `tests/CatalogoOpcionalesUnifiedControllerTest.php`
  (failing): `test_new_opcional_persists_percentage_and_group` (`:706-732`) posts
  `grupos[]` and asserts `set_grupos()`; `test_where_id_grupo_treats_sentinel_and_rejects_injection`
  (`:804-823`) asserts the new `EXISTS`/`NOT EXISTS` strings; the stub (`:1066`)
  drops `$id_grupo` and gains `set_grupos()`. Covers **OUM-02, OUM-05, OPG-04**.
  **B1**: only the `where_id_grupo` assertion migrated (green); the `grupos[]`
  payload and the stub `$id_grupo` drop landed in **B3 (WU-4)** — the test is now
  `test_new_opcional_persists_percentage_and_multiple_groups`.
- [x] `WU-2.T3`: extend `tests/Integration/CatalogoCoreHookMarkersTest.php`
  (failing): drop `'id_grupo' => ''` from the host fixture (`:483`).
  **B1**: deferred to the removal batch. **B3 (WU-4)**: the fixture now seeds
  `grupos_asignados` / `grupos_asignados_ids` and no `id_grupo`.

**Run (RED)**:
```bash
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CatalogoOpcionalMembership|CatalogoOpcionalesUnifiedController|CatalogoCoreHookMarkers'
```

### Implementation

- [x] `WU-2.T4`: `catalogo_opcional` — remove the `public $id_grupo` property and its
  constructor mapping (AD-6); ADD `add_to_grupo(int)`, change
  `remove_from_grupo(int)`, ADD `set_grupos(array)`, `get_grupos()`,
  `grupo_ids()`, `grupos_labels()`, `is_grouped()`, `all_not_in_grupo(int, int, int)`,
  `all_activos_sin_grupo(int, int)`; CHANGE `all_sin_grupo()` and
  `where_id_grupo()` bodies to the bridge anti-join / `EXISTS`/`NOT EXISTS`
  (`design.md` § Model API surface, § Filters); REMOVE `get_grupo()`,
  `etiqueta_grupo()`, `assign_to_grupo()`.
  **B1**: the full set API and every bridge body landed (green); removing
  `$id_grupo` and the `get_grupo()` / `etiqueta_grupo()` / `assign_to_grupo()`
  shims landed in **B3 (WU-4)**.
- [x] `WU-2.T5`: `save()` freeze (AD-5): drop `, id_grupo = ...` from the UPDATE
  (was `:389`); drop `id_grupo` from the INSERT column list and value
  (was `:392-400`); remove the post-save cleanup block
  `if ($this->id_grupo) { ... }` (was `:408-411`). The cleanup now lives in
  `add_to_grupo()` / `set_grupos()` (WU-2.T4).
  **B1**: staged as **dual-write** instead — `save()` kept writing `id_grupo`
  and reconciled the bridge (`syncBridgeFromLegacyGroup()`). **B3 (WU-4)**: the
  freeze and the block removal landed; the dual-write machinery is gone.
- [x] `WU-2.T6`: `delete()` gains the cascade (AD-7):
  `(new catalogo_opcional_grupo_rel())->delete_all_from_opcional((int) $this->id)`
  before the master `DELETE` (`design.md` § `save()`).
- [x] `WU-2.T7`: `model/tarif_opcional.php` guard — confirm `where_id_grupo()`
  inherits the new body, `search()` / `count_filtered()` keep their signatures, and
  no `id_grupo` write is added. **B1**: confirmed, no change required.

### Run (GREEN)

```bash
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
```

**Revert boundary (design WU-2)**: revert `model/core/catalogo_opcional.php` (and
`tarif_opcional.php` if touched). Readers still work on the frozen column once
WU-3/WU-4 are reverted in reverse order.

---

## WU-3 — Dependent readers (design WU-3)

**Goal**: every behavior reader resolves membership through the bridge, and the
resolver unions all groups with a constant query count.

**Files**:
- `model/core/catalogo_opcional_grupo.php`
- `model/core/catalogo_articulo_opcional.php`
- `model/core/catalogo_opcional_familia.php`
- `Controller/VentasArticulo.php`
- `Services/CaracteristicaResolver.php`
- `tests/fixtures/opcional_visibility_fakes.php` (update)
- `tests/OpcionalVisibilityDerivationTest.php` (update)
- `tests/CatalogoOpcionalGrupoTest.php` (update + extend)

**Dependency order**: after WU-1 and WU-2.

> **B2 stage-safe status (apply batch 3, Slice B2)**: implemented as a **green
> intermediate slice**. Every WU-3 reader now resolves membership through the
> bridge while the B1 dual-write, the `$id_grupo` property and the legacy shims
> stay in place, so the tree never leaves GREEN. One narrow test seam
> (`catalogo_articulo_opcional::articulo_opcional_grupo_model()`) was added so
> the behavior-preserved `get_opcionales_from_articulo()` dedupe (OPG-09 s1) is
> covered DB-free. See `apply-progress.md` §B2.

### Tests first (must fail / RED)

- [x] `WU-3.T1`: update `tests/fixtures/opcional_visibility_fakes.php` (failing):
  replace the `FROM catalogo_opcionales` branch with `FROM catalogo_opcional_grupo_rel`;
  `$groups` becomes `array<int, list<int>>` (`id_opcional => [id_grupo...]`) and
  emits **one row per pair** (`['id_opcional' => 7, 'id_grupo' => 3]`);
  `inInts()` filters on `id_opcional`; keep `groupArticles`, `articles`,
  `families`. Covers **OPG-10**.
- [x] `WU-3.T2`: extend `tests/OpcionalVisibilityDerivationTest.php` (failing):
  update `test_grouped_opcional_uses_the_group_article_parents` to seed
  `groups: [7 => [3]]`; ADD
  `test_multi_group_opcional_unions_all_group_article_parents`
  (`groups: [7 => [3, 4]]`, `groupArticles: [3 => ['REF-G1'], 4 => ['REF-G2']]`,
  assert both parents union) = **OPG-10** scenario 1; keep
  `test_query_count_is_bounded_and_independent_of_the_page_size`
  (`assertLessThanOrEqual(4, ...)`, equal counts) = **OPG-10** scenario 2.
- [x] `WU-3.T3`: extend `tests/CatalogoOpcionalGrupoTest.php` (failing): remove
  `testOpcionalStoresIdGrupo` / `testGroupedOptionalHasIdGrupo`; keep
  `testUrlNuevoEnGrupoIncludesQueryParam`, `testGrupoModelGeneratesCodigoPrefix`,
  `testTpvmodBuildOpcionalItemIncludesGroupMetadata`,
  `testTpvmodOpcionalesForArticuloReturnsGroupedPayloadWhenEmpty`; ADD:
  - **OPG-06**: `test_multi_group_opcional_appears_under_each_group`,
    `test_activos_read_is_bridge_backed`
  - **OPG-07**: `test_available_list_offers_member_of_another_group`,
    `test_available_list_excludes_current_member`
  - **OPG-09** (scenarios 1-2): `test_resolved_for_sale_read_yields_one_occurrence`,
    `test_group_scoped_presentation_lists_each_group`
  - **OPG-03**: bridge-model rows-per-group assertions

**Run (RED)**:
```bash
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'OpcionalVisibilityDerivation|CatalogoOpcionalGrupo'
```

### Implementation

- [x] `WU-3.T4`: `catalogo_opcional_grupo` (AD-8): `get_opcionales()` (`:172-189`)
  and `get_opcionales_activos()` (`:224-242`) INNER JOIN
  `catalogo_opcional_grupo_rel`; `count_opcionales()` (`:191-204`) counts the
  bridge; `delete()` (`:160-162`) replaces
  `UPDATE catalogo_opcionales SET id_grupo = NULL ...` with
  `(new catalogo_opcional_grupo_rel())->delete_all_from_grupo((int) $this->id)`
  (AD-7). `count_articulos()` untouched.
  **B1**: the `delete()` AD-7 cascade half already landed (bridge rows deleted
  **and** the legacy `id_grupo = NULL` update kept). The bridge-backed reads
  (`get_opcionales`, `get_opcionales_activos`, `count_opcionales`) remain for
  WU-3.
  **B2**: the three bridge-backed reads landed; `delete()` kept as delivered in B1.
- [x] `WU-3.T5`: `catalogo_articulo_opcional` (AD-8):
  `validate_opcional_for_articulo()` (`:47-60`) → `if ($item->is_grouped())`;
  `get_opcionales_sueltos_from_articulo()` (`:114-129`) and
  `get_opcionales_directos_from_articulo()` (`:175-190`) replace
  `AND (o.id_grupo IS NULL OR o.id_grupo = 0)` with
  `AND NOT EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r WHERE r.id_opcional = o.id)`;
  `get_opcionales_from_articulo()` (`:136-165`) unchanged (behavior-preserved,
  locked by the OPG-09 tests).
  **B2**: the anti-joins landed. `get_opcionales_from_articulo()` stays
  behavior-preserved; only a `articulo_opcional_grupo_model()` test seam was
  added (no behavior change) so its dedupe is covered DB-free.
- [x] `WU-3.T6`: `catalogo_opcional_familia` (AD-8): in
  `add_with_propagation()` (`:52-88`) and `remove_with_propagation()` (`:90-120`)
  replace `if ($op && $op->id_grupo)` with
  `$grupoIds = $op ? $op->grupo_ids() : [];` and iterate **every** `$idGrupo` for
  the article↔group propagation.
- [x] `WU-3.T7`: `VentasArticulo::loadOpcionalesDisponibles()` (`:950-967`): replace
  `if ($opcional->id_grupo) continue;` + `all_activos(0, 500)` with
  `$opcionalModel->all_activos_sin_grupo(0, 500)`.
- [x] `WU-3.T8`: `CaracteristicaResolver::opcional_parents()`
  (`Services/CaracteristicaResolver.php:267-338`) (AD-10, OPG-10): add
  `private const OPCIONAL_GRUPO_REL_TABLE = 'catalogo_opcional_grupo_rel';`;
  query 1 reads `catalogo_opcional_grupo_rel` into `$memberships[$id] = list<int>`;
  the union loop merges parents of **all** groups; update the docblock to
  "four queries (three without memberships)". Query count stays exactly **4** with
  at least one membership, **3** when none — never per-opcional.

### Run (GREEN)

```bash
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
ddev exec php vendor/bin/phpunit
```

**Revert boundary (design WU-3)**: revert the five reader files + the fixture/test
updates. The bridge may be populated but unused; `id_grupo` becomes the reader
source again only after WU-2 is also reverted.

---

## WU-4 — Controllers, trait and views (design WU-4)

**Goal**: checkbox-list assignment UI, lossless `set_grupos()`, the multi-label
"Grupo" column from one batched map, and the four `VentasOpcionalGrupo`
membership sites adapted to AD-4/AD-6.

**Files**:
- `Controller/VentasOpcional.php`
- `Controller/VentasOpcionalGrupo.php`
- `extras/VentasOpcionalesListTrait.php`
- `View/ventas_opcional.html.twig`
- `View/ventas_opcionales.html.twig`
- `View/partials/articulos/tab_opcionales.html.twig` (if it references `id_grupo`)
- `tests/CatalogoOpcionalesHtmxContractTest.php` (update)
- `tests/VentasOpcionalesControllerTest.php` (update)
- `tests/Controller/VentasOpcionalesExportParityTest.php` (keep — assert unchanged signatures)

**Dependency order**: after WU-1 and WU-2 (uses `set_grupos()` / `is_grouped()`
and `map_for_opcionales()`).

> **B3 status (apply batch 4, Slice B3 = WU-4)**: implemented as three reviewable
> work units and fully GREEN. The deferred WU-2 removals (AD-5 freeze, AD-6
> property + shims) landed here. Consumer commits: controllers/trait/views
> checkbox list first, then the model freeze/removals, then the group-editor
> call-site contract. See `apply-progress.md` §B3.

### Tests first (must fail / RED)

- [x] `WU-4.T1`: extend `tests/CatalogoOpcionalesHtmxContractTest.php` (failing):
  assert `name="grupos[]"` (not `sid_grupo`), `fsc.nombres_grupo_opcional(`,
  no `etiqueta_grupo(`, no `id_grupo` in the list/edit views. Covers
  **OUM-05, OPG-02**.
- [x] `WU-4.T2`: extend `tests/VentasOpcionalesControllerTest.php` (failing):
  `CreateOrderingOpcionalStub` (`:232-280`) drops `$id_grupo` and gains
  `set_grupos(array): bool`; create payload (`:243`) migrates to `grupos[]`.
  Also adds the group-editor call-site source contract. Covers **OUM-05**.
- [x] `WU-4.T3`: extend `tests/CatalogoOpcionalesUnifiedControllerTest.php`
  (failing): the group-map test (`:789-801`) asserts the array accessor
  `nombres_grupo_opcional()` from one batched map. Covers **OPG-02**.

**Run (RED)**:
```bash
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CatalogoOpcionalesHtmxContract|VentasOpcionalesController|CatalogoOpcionalesUnifiedController'
```

### Implementation

- [x] `WU-4.T4`: `Controller/VentasOpcional` (AD-11): add
  `public array $grupos_asignados_ids = []` and `public array $grupos_asignados = []`;
  `loadGruposAsignados()` renders `all_activos()` **unioned with** the opcional's
  current groups (lossless); the `?id_grupo=<n>` preset (`:87-93`) seeds
  `grupos_asignados_ids = [$n]` without writing the model; `guardarOpcional()`
  reads `$request->request->all('grupos')`, drops the `sid_grupo`/`id_grupo`
  writes (`:216-227`), and calls `$this->opcional->set_grupos($ids)` after
  `save()`; `addArticulo()` (`:319-322`) uses `$this->opcional->is_grouped()`.
- [x] `WU-4.T5`: `View/ventas_opcional.html.twig`: replace the single
  `<select name="sid_grupo">` (`:192-204`) with the `name="grupos[]"` checkbox
  list over `fsc.grupos_opcional` (checked from `fsc.grupos_asignados_ids`);
  gating at `:121-123`, `:333-340` uses `fsc.opcional.is_grouped()`; one
  "view group" link per `fsc.grupos_asignados`.
- [x] `WU-4.T6`: `View/ventas_opcionales.html.twig`: replace
  `<select name="sid_grupo">` (`:104-114`) with the same `name="grupos[]"`
  checkbox list over `fsc.grupos_opcional`.
- [x] `WU-4.T7`: `extras/VentasOpcionalesListTrait`:
  - `new_opcional()` drops the `sid_grupo` read (`:772-775`) and the
    `$opcional->id_grupo = ...` write (`:787`); calls
    `$opcional->set_grupos((array) $this->request->request->all('grupos'))`
    **inside** the existing `run_in_transaction()` (OUM-05 scenario 2).
  - `load_grupos_cache()` (`:313-330`) loads all groups once, builds
    `$nombres[grupo_id] = nombre` from **all** groups, keeps
    `$this->grupos_opcional` (active only) for filter/create, and builds
    `$this->nombres_grupo_opcional[$id] = list<string>` from **one**
    `map_for_opcionales($ids)` call (seam `opcional_grupo_rel_model()` for tests).
  - renames the accessor `nombre_grupo_opcional(int): string` →
    `nombres_grupo_opcional(int): array` (returns `[]` for loose rows); the view
    cell renders badges, `-` for loose (OPG-02).
  - adds the bridge model to this file's `require_once` load chain (flagged by
    the B1 validator).
- [x] WU-2 deferred removals (the cutover owned by WU-4): `catalogo_opcional`
  drops `public $id_grupo` and its constructor mapping (AD-6), removes
  `get_grupo()` / `etiqueta_grupo()` / `assign_to_grupo()`, changes
  `remove_from_grupo()` arity to `(int)` (AD-4), freezes `save()` (AD-5: no
  `id_grupo` write, no post-save block, no `syncBridgeFromLegacyGroup()` /
  `syncLegacyProjection()`), and `catalogo_opcional_grupo::delete()` drops the
  legacy `UPDATE catalogo_opcionales SET id_grupo = NULL` (AD-7). The physical
  column stays; no `DROP COLUMN`.

### Run (GREEN)

```bash
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
ddev exec php vendor/bin/phpunit
```

**Revert boundary (design WU-4)**: revert the controller/trait/view files; the
bridge is inert.

---

## WU-5 — tpvmod and tarifario companions (design WU-5)

**Goal**: TPV charges a cross-group opcional once; tarifario's two bulk deletes
leave no orphan memberships.

**Files**:
- `plugins/tpvmod/view/js/tpvmod.js`
- `plugins/tpvmod/lib/tpvmod_opcionales_ajax.php`
- `plugins/tpvmod/lib/tpvmod_opcionales.php`
- `plugins/tpvmod/tests/TpvmodOpcionalRapidoTest.php` (update)
- `plugins/tpvmod/tests/TpvmodOpcionalDedupeTest.php` (new)
- `plugins/tarifario/Services/ArticuloListActionHandler.php`
- `plugins/catalogo_core/tests/Integration/OpcionalGrupoTarifarioCompatTest.php` (new)

**Dependency order**: after WU-2 (AD-13 depends on `is_grouped()`, AD-6 removes
the property). Distinct plugin repos; commit per repo.

### Tests first (must fail / RED)

- `WU-5.T1`: add `plugins/tpvmod/tests/TpvmodOpcionalDedupeTest.php` (failing):
  source contract on `view/js/tpvmod.js` — `tpvmod_pick_opcional` checks
  `tpvmod_get_added_opcional_ids(...)` **before** the exclusive replacement
  branch; `tpvmod_opcionales_ajax.php` contains no `id_grupo` read;
  `tpvmod_match_opcional_by_nombre` uses `grouped`. Covers **OPG-09** scenario 3.
- `WU-5.T2`: update `plugins/tpvmod/tests/TpvmodOpcionalRapidoTest.php`
  (failing): assertions migrate from `id_grupo` to `grouped` / `grupo_id => null`
  (`:133`, `:151`, `:230-260`, `:425`, `:491-523`, `:688`). Covers
  **OUM-05 / AD-13**.
- `WU-5.T3`: add
  `plugins/catalogo_core/tests/Integration/OpcionalGrupoTarifarioCompatTest.php`
  (failing): the 2-group seed proves `get_opcionales_directos_from_articulo()`
  excludes a grouped opcional identically with 1 or 2 groups (fake db); a
  per-method source contract asserts `ArticuloListActionHandler::limpiar_opcionales()`
  **and** `::limpiar_todo()` each contain `DELETE FROM catalogo_opcional_grupo_rel`,
  that `limpiar_todo()` adds `relaciones_grupo` to `$stats`, and that the
  direct-read predicate is a bridge anti-join (not `id_grupo`); documents the
  `catalogo_articulo_opcional_grupo` pre-existing orphan as out of scope.
  Covers **OPG-11**.

**Run (RED)**:
```bash
ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'OpcionalGrupoTarifarioCompat'
```

### Implementation

- `WU-5.T4`: `tpvmod/view/js/tpvmod.js::tpvmod_pick_opcional()` (`:675-707`):
  move the dedupe-by-id check (`tpvmod_get_added_opcional_ids(parentUid)[String(opcional.id)]`)
  **before** the `opcional.grupo_id && opcional.grupo_exclusivo` replacement
  branch (`design.md` § Cart-add dedupe).
- `WU-5.T5`: `tpvmod/lib/tpvmod_opcionales_ajax.php` (AD-13):
  `tpvmod_opcional_candidate_array()` (`:31-51`) exposes
  `'grouped' => ... is_grouped() ...` instead of `'id_grupo'`;
  `tpvmod_match_opcional_by_nombre()` (`:59-86`) skips when
  `!empty($item['grouped'])`; `tpvmod_opcionales_ajax_opcional_payload()`
  (`:151-174`) pins `'grupo_id' => null`; `tpvmod_opcionales_ajax_persist()`
  (`:254`, `:262`) drops both `$opcional->id_grupo = ...` assignments.
- `WU-5.T6`: `tpvmod/lib/tpvmod_opcionales.php:111`
  (`tpvmod_normalize_opcional_input()`): rename the `'id_grupo' => null` data key
  to `'grouped' => false`.
- `WU-5.T7`: `tarifario/Services/ArticuloListActionHandler.php::limpiar_opcionales()`
  (`:2167-2238`) (AD-7): insert a **new step 4**
  `$db->exec("DELETE FROM catalogo_opcional_grupo_rel;")` immediately **before**
  the existing `catalogo_opcionales` delete (`:2207-2214`, now step 5); count it
  into `$stats['relaciones_grupo']`, append to `$errores` on failure, and report
  it in the success message (`:2220-2228`).
- `WU-5.T8`: same class, `limpiar_todo()` (`:1742-2050`) (AD-7): insert
  `$deleteTable('catalogo_opcional_grupo_rel', 'relaciones_grupo')` between step
  10 (`catalogo_opcional_familias`, `:1987`) and step 11 (`catalogo_opcionales`,
  `:1992`); seed `'relaciones_grupo' => 0` in `$stats` (`:1747-1759`); add the
  count to the success message (`:2036+`).

### Run (GREEN)

```bash
ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml
ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
```

**Revert boundary (design WU-5)**: revert the tpvmod JS/PHP files and the
tarifario handler edits; no schema change.

---

## WU-6 — Verify pass (design WU-6, no production change)

**Goal**: prove the change against the spec/design and produce the evidence for
`sdd-verify` (this tasks artifact does NOT write `verify-report.md`).

**Tasks**:
- `WU-6.T1`: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- `WU-6.T2`: `ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml`
- `WU-6.T3`: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml`
- `WU-6.T4`: `ddev exec php vendor/bin/phpunit` (root regression floor)
- `WU-6.T5`: grep audit — every residual `id_grupo` reference MUST be confined to
  the allowed residual set: the migration service (`syncOptionalGroupColumns`,
  `migrateOpcionalGroupRelations` pre-check/backfill,
  `migrateGroupedOptionalAssignments`), the XML column, and archived SDD docs.
  No behavior code may read `catalogo_opcionales.id_grupo` (OPG-03 "frozen but
  still present").
- `WU-6.T6`: `ddev exec composer phpstan` (plugin `phase_rules.linter`).
- `WU-6.T7`: manual smoke checklist — multi-group checkbox list saves losslessly;
  "Grupo" column shows all labels from one map; TPV shows the opcional under each
  group and charges once.

**Revert boundary (design WU-6)**: no production change; nothing to revert.

---

## Requirement → task traceability

| Requirement | Covered by |
|---|---|
| OUM-02 (group filter via bridge) | WU-2.T1, WU-2.T2 |
| OUM-05 (multi-membership creation) | WU-2.T2, WU-4.T1, WU-4.T2, WU-5.T2 |
| OPG-02 (`Grupo` column, one batched map) | WU-4.T1, WU-4.T3, WU-4.T7 |
| OPG-03 (store + idempotent backfill + freeze) | WU-1.T1-T4, WU-1.T5-T10 |
| OPG-04 (loose ⇔ no bridge row) | WU-2.T1, WU-2.T4 |
| OPG-05 (set writes + cascade) | WU-2.T1, WU-2.T5, WU-2.T6 |
| OPG-06 (group master bridge reads) | WU-3.T3, WU-3.T4 |
| OPG-07 (group editor available list) | WU-3.T3, WU-3.T4, WU-4.T4 |
| OPG-08 (grouped ⇒ no direct relations) | WU-2.T1, WU-3.T5 |
| OPG-09 (single charge, dedupe) | WU-3.T3, WU-5.T1, WU-5.T4 |
| OPG-10 (group-union parents, constant queries) | WU-3.T1, WU-3.T2, WU-3.T8 |
| OPG-11 (tarifario guard + both bulk deletes) | WU-5.T3, WU-5.T7, WU-5.T8 |

## Test-file coverage map (design AD-16)

**Existing updated**:
`tests/fixtures/opcional_visibility_fakes.php` (WU-3.T1),
`tests/OpcionalVisibilityDerivationTest.php` (WU-3.T2),
`tests/CatalogoOpcionalGrupoTest.php` (WU-3.T3),
`tests/CatalogoOpcionalesUnifiedControllerTest.php` (WU-2.T2, WU-4.T3),
`tests/CatalogoOpcionalesHtmxContractTest.php` (WU-4.T1),
`tests/VentasOpcionalesControllerTest.php` (WU-4.T2),
`tests/Services/CatalogLegacyTableMigrationTest.php` (WU-1.T3),
`tests/Integration/CatalogoCoreHookMarkersTest.php` (WU-2.T3),
`tests/InitOpcionalesTablesTest.php` (WU-1.T4),
`tests/Controller/VentasOpcionalesExportParityTest.php` (keep; assert unchanged
`search`/`count_filtered` signatures — AD-9),
`plugins/tpvmod/tests/TpvmodOpcionalRapidoTest.php` (WU-5.T2).

**New**:
`tests/CatalogoOpcionalGrupoRelModelTest.php` (WU-1.T1),
`tests/CatalogoOpcionalMembershipTest.php` (WU-2.T1),
`tests/Integration/CatalogoOpcionalGroupMigrationTest.php` (WU-1.T2),
`tests/Integration/OpcionalGrupoTarifarioCompatTest.php` (WU-5.T3),
`plugins/tpvmod/tests/TpvmodOpcionalDedupeTest.php` (WU-5.T1).

**2-group seed** (opcional `X` in `G1`+`G2`, article `A` assigned to both, `A`
directly related to `X` before grouping) is shared by
`OpcionalGrupoTarifarioCompatTest` (WU-5.T3) and `CatalogoOpcionalGrupoTest`
(WU-3.T3).

## Composer / vendor note

This change adds **NO** Composer dependency to any plugin (`catalogo_core`,
`tpvmod`, `tarifario`). Therefore **no `vendor/` commit step is required** for
this change. The "commit `vendor/` with `composer.json`/`composer.lock`" rule does
not apply here; do not add a `git add plugins/*/vendor/` step.

## Documentation reconciliation (drift items)

1. `WU-0.T1` — `proposal.md:42-43` names only `limpiar_opcionales()`
   (`:2182-2214`); update it to name **both** tarifario bulk-delete paths
   (`limpiar_opcionales()` and `limpiar_todo()`).
2. `WU-0.T2` — add the three `- Test:` annotations to the `OPG-09` scenarios in
   the delta spec.
3. `WU-0.T3` — keep every artifact under
   `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/`; core
   `openspec/` gets **no** entry for this change.

## Out of scope (do NOT touch)

- No `ALTER TABLE ... DROP COLUMN id_grupo` (follow-up soak-drop change owns it).
- No change to the articulo↔group bridge (`catalogo_articulo_opcional_grupo`)
  semantics. Its pre-existing orphan debt in `limpiar_todo()`'s deleted-article
  side is documented and deferred (design § tarifario compatibility).
- No new capability; no core `openspec/` entry; no new Composer dependency.

## Open follow-ups (non-blocking, recorded by design)

- `design.md` Open Questions 1-4 are pinned to the confirmed default
  (`membership_preserve_all`): all-group labels, lossless checkbox union, tpvmod
  companion edit in this change, `relaciones_grupo` stats key.

---

## Review Workload Forecast

- **Estimated changed lines**: ~2050 (catalogo_core production ~815 + catalogo_core tests ~1000 + tpvmod ~200 + tarifario ~30, summed across catalogo_core + tpvmod + tarifario).
- **400-line budget risk**: High
- **Chained PRs recommended**: Yes
- **Suggested slices**:
  1. **Slice A — Bridge foundation (WU-0 docs + WU-1)**: `model/table/catalogo_opcional_grupo_rel.xml`, `model/core/catalogo_opcional_grupo_rel.php`, `Services/CatalogLegacyTableMigration.php`, `Init.php`, plus `CatalogoOpcionalGrupoRelModelTest`, `CatalogoOpcionalGroupMigrationTest`, `CatalogLegacyTableMigrationTest`, `InitOpcionalesTablesTest`. Purely additive, green standalone. (~700 lines)
  2. **Slice B — Membership cutover (WU-2 + WU-3 + WU-4)**: `model/core/catalogo_opcional.php`, `tarif_opcional.php`, `catalogo_opcional_grupo.php`, `catalogo_articulo_opcional.php`, `catalogo_opcional_familia.php`, `VentasArticulo.php`, `CaracteristicaResolver.php`, `VentasOpcional.php`, `VentasOpcionalGrupo.php`, `extras/VentasOpcionalesListTrait.php`, the three Twig views, and their tests. Must be one green commit/PR because AD-4/AD-6 removal couples every reader. **Over the 800 budget on its own (~1600 lines)** — the honest split within it is by AD group, but none is independently green while `$id_grupo` is removed; flag a `size:exception` for this slice if it must be a single PR, or split it as Slice B1 (WU-2 model API + WU-3 readers, still reads/writes `id_grupo` in controllers) / Slice B2 (WU-4 controllers+views) and accept an intermediate non-green controller state. (~1600 lines)
  3. **Slice C — Consumer companions (WU-5)**: `plugins/tpvmod/view/js/tpvmod.js`, `lib/tpvmod_opcionales_ajax.php`, `lib/tpvmod_opcionales.php`, `plugins/tarifario/Services/ArticuloListActionHandler.php`, plus `TpvmodOpcionalDedupeTest`, `TpvmodOpcionalRapidoTest`, `OpcionalGrupoTarifarioCompatTest`. Separate repo commits. (~250 lines)
  4. **Slice D — Verify pass (WU-6)**: no production change; produces the verify evidence. (~0 lines)
- **Decision needed before apply**: Yes — `chain_strategy` is `NOT YET CHOSEN` and this forecast is over the 800-line budget; the orchestrator must pick the chain strategy (and whether Slice B becomes B1+B2 with an intermediate non-green controller state, or a `size:exception`).
