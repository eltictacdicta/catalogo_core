# Apply Progress: opcional-en-varios-grupos

- **Change**: `opcional-en-varios-grupos`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
- **Artifact store**: `openspec`
- **Mode**: Strict TDD
- **Slice implemented**: **Slice A — WU-0 (docs) + WU-1 (bridge foundation)**
- **Status**: Slice A complete and green. WU-2…WU-6 intentionally NOT implemented in this batch.

## Overview

Slice A is **purely additive**. The M:N bridge `catalogo_opcional_grupo_rel`, its
model, the create-if-missing + idempotent backfill migration, and the boot
ensure are added. No existing reader/writer is switched: the frozen
`catalogo_opcionales.id_grupo` column is still read and written exactly as
before, and nothing is removed. The subtree stays green after the slice.

Session configuration (verbatim): `execution_mode: auto`, `delivery_strategy:
auto-chain`, `chain_strategy: stacked-to-main`, `review_budget_lines: 800`,
Strict TDD active.

## Task checkboxes updated in `tasks.md`

`tasks.md` used prose bullets (`- \`WU-x.Tn\`: …`), not `- [ ]` checkboxes. Each
completed task now carries an explicit `- [x]` marker.

- **WU-0** (docs): `WU-0.T1`, `WU-0.T2`, `WU-0.T3` → `[x]`.
- **WU-1** (bridge foundation): `WU-1.T1`…`WU-1.T10` → `[x]`.
- **WU-2…WU-6**: left unchecked (out of scope for Slice A).

## WU-0 — Documentation reconciliation (docs only)

1. `WU-0.T1` — `proposal.md` now names **both** tarifario bulk-delete paths
   (`ArticuloListActionHandler::limpiar_opcionales()` **and** `::limpiar_todo()`)
   in the In-Scope bullet, the consumer header line, and the External-consumer
   table row, mirroring `design.md` AD-7/AD-14 and `compatibility-tarifario.md`
   C2/C2b.
2. `WU-0.T2` — added the three inline `- Test:` annotations to the `OPG-09`
   scenarios in `specs/opcionales-management/spec.md`:
   - "Resolved-for-sale read yields one occurrence" →
     `plugins/catalogo_core/tests/CatalogoOpcionalGrupoTest.php`
   - "Group-scoped presentation still lists each group" →
     `plugins/catalogo_core/tests/CatalogoOpcionalGrupoTest.php`
   - "Second selection of the same id is deduped" →
     `plugins/tpvmod/tests/TpvmodOpcionalDedupeTest.php`
3. `WU-0.T3` — verified every artifact of the change lives under
   `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/` and that
   the core tree has no entry.

### WU-0 verification command + result

```bash
test ! -e openspec/changes/opcional-en-varios-grupos && echo "core tree clean"
grep -c "Test:" plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/specs/opcionales-management/spec.md
```

Result: `core tree clean`; `Test:` annotation count went from 6 to **9** (+3, the
three `OPG-09` scenarios).

## WU-1 — Bridge table, model, migration and boot ensure

### Files changed

| File | Action | What was done |
|---|---|---|
| `plugins/catalogo_core/model/table/catalogo_opcional_grupo_rel.xml` | Created | Columns `id` (`serial`), `id_opcional` (`integer`), `id_grupo` (`integer`); `catalogo_opcional_grupo_rel_pkey PRIMARY KEY (id)` + `catalogo_opcional_grupo_rel_unique UNIQUE (id_opcional, id_grupo)`; no FK. |
| `plugins/catalogo_core/model/core/catalogo_opcional_grupo_rel.php` | Created | `FSFramework\model\catalogo_opcional_grupo_rel` with the pinned surface: `add` (idempotent via `exists_relation`), `remove`, `exists_relation`, `group_ids_for_opcional`, `map_for_opcionales` (single batched query), `delete_all_from_opcional`, `delete_all_from_grupo`, `exists`, `save`, `delete`, `install`. |
| `plugins/catalogo_core/Services/CatalogLegacyTableMigration.php` | Modified | Added `migrateOpcionalGroupRelations()` (create-if-missing PG + MySQL, then the `LEFT JOIN (id_opcional, id_grupo)` pre-check, then the idempotent backfill) and `hasPendingGroupRelations()`; wired the call in `migrateIfNeeded()` **between** `syncArticuloOpcionalGrupoTable()` and `migrateGroupedOptionalAssignments()`; rewrote `migrateGroupedOptionalAssignments()` to join `catalogo_opcional_grupo_rel` and added it to the guard; documented `syncOptionalGroupColumns()` as keeping the frozen column alive but NOT being the source of truth. |
| `plugins/catalogo_core/Init.php` | Modified | Added `ensureOpcionalGrupoRelTable()` called from `init()` next to `ensureArticuloOpcionalGrupoTable()`; added `catalogo_opcional_grupo_rel` to `ensureCatalogTables()`. |
| `plugins/catalogo_core/tests/CatalogoOpcionalGrupoRelModelTest.php` | Created | Bridge model contract (11 tests): add idempotency, remove, exists_relation, group ids, one-query map, both bulk deletes, and the PG/MySQL DDL source contract (PK + UNIQUE, no FK/REFERENCES/CASCADE). |
| `plugins/catalogo_core/tests/Integration/CatalogoOpcionalGroupMigrationTest.php` | Created | Migration source contract (5 tests): call ordering, create-if-missing without early return, pair-keyed pre-check, both dialect backfills, bridge-backed grouped-assignment rewrite. |
| `plugins/catalogo_core/tests/Services/CatalogLegacyTableMigrationTest.php` | Modified | Fake DB now emulates the bridge CREATE (registers the table), the pair pre-check and the backfill; `baseTables()` seeds the bridge; 3 new RED tests (create when missing, idempotent backfill, grouped-assignment bridge join). |
| `plugins/catalogo_core/tests/InitOpcionalesTablesTest.php` | Modified | 2 new tests: `ensureCatalogTables()` includes the bridge and `init()` wires `ensureOpcionalGrupoRelTable()`. |
| `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/proposal.md` | Modified | WU-0.T1 drift fix (both bulk-delete paths). |
| `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/specs/opcionales-management/spec.md` | Modified | WU-0.T2 inline `- Test:` annotations. |
| `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/tasks.md` | Modified | WU-0/WU-1 tasks marked `[x]`. |
| `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/apply-progress.md` | Created | This artifact. |

## TDD Cycle Evidence

| Task | Test file | Layer | Safety net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| WU-1.T1 | `tests/CatalogoOpcionalGrupoRelModelTest.php` | Unit | N/A (new) | ✅ Written (DDL contract fails without `migrateOpcionalGroupRelations`) | ✅ Passed (11/11) | ✅ 11 cases (idempotency, one-query map, both cascades, empty ids) | ✅ Clean |
| WU-1.T2 | `tests/Integration/CatalogoOpcionalGroupMigrationTest.php` | Integration (source contract) | N/A (new) | ✅ Written (method/ordering absent) | ✅ Passed (5/5) | ✅ 5 cases (ordering, pre-check, both dialects, rewrite) | ✅ Clean |
| WU-1.T3 | `tests/Services/CatalogLegacyTableMigrationTest.php` | Unit (fake DB) | ✅ 8/8 existing (baseline green) | ✅ Written (3 new tests fail without production) | ✅ Passed (create + idempotent backfill + bridge join) | ✅ 3 new cases + rerun-is-noop | ✅ Clean |
| WU-1.T4 | `tests/InitOpcionalesTablesTest.php` | Unit (source contract) | ✅ 7/7 existing | ✅ Written (helper + list absent) | ✅ Passed (2/2) | ✅ 2 cases (list + wiring) | ✅ Clean |
| WU-0.T1/T2/T3 | docs only | — | N/A | N/A (docs) | ✅ Verified (path audit + annotation count 6→9) | ➖ Docs | ➖ Docs |

**RED evidence (executed)** — with `Init.php` and `Services/CatalogLegacyTableMigration.php`
temporarily stashed (tests + model present), the focused suite reported
`Tests: 949, Failures: 11` — every new/extended contract failed
(`test_bridge_ddl_declares_pk_and_unique_without_foreign_keys`,
2 × `InitOpcionalesTablesTest`, 5 × `CatalogoOpcionalGroupMigrationTest`,
3 × `CatalogLegacyTableMigrationTest`). Restoring production turned the same
suite GREEN.

**GREEN execution gates**

| Command | Result |
|---|---|
| `ddev exec php -l <each changed file>` | no syntax errors (7/7) |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **OK** — Tests: 949, Assertions: 4181, Warnings: 2, Skipped: 1 (baseline 928 → **+21**) |
| `ddev exec php vendor/bin/phpunit` (root regression floor) | **OK** — Tests: 2740, Assertions: 9900, Skipped: 24 (baseline 2694 → +46) |

Plugin baseline was captured before any edit: `928 tests, 4104 assertions,
Warnings: 2, Skipped: 1` (OK). Root baseline: `2694 tests, 9563 assertions,
Skipped: 24` (OK). No pre-existing failures.

## Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → exit 0, `OK`, Tests: 949, Assertions: 4181, Warnings: 2, Skipped: 1 |
| Runtime harness command/scenario and exact result | **N/A** — the plugin test suite is DB-free by project convention (fake `fs_db2` / anonymous subclasses); the live DB bridge create + backfill path is exercised by the verify-phase manual smoke (WU-6). The migration SQL shape is pinned by source-contract tests instead. |
| Rollback boundary | Delete `model/table/catalogo_opcional_grupo_rel.xml`, `model/core/catalogo_opcional_grupo_rel.php`; revert `migrateOpcionalGroupRelations()`/`hasPendingGroupRelations()`, the `migrateGroupedOptionalAssignments()` rewrite, and the two `Init` additions. The bridge becomes inert; `id_grupo` remains readable. No existing reader/writer was switched. |

## Deviations from Design

1. **Omitted the migration's post-create model touch**
   (`new \FSFramework\model\catalogo_opcional_grupo_rel();`) that `design.md`
   §Bridge DDL mentions as mirroring `syncArticuloOpcionalGrupoTable()`.
   Reason: on a real DB `tableExists()` is already true right after
   `CREATE TABLE`, so that touch is a failed-create-only fallback (dead in
   production, exactly like the article template). Including it would force a
   **real** `fs_db2` connection inside the DB-free migration tests whose fakes
   do not simulate the table (e.g. `OpcionalPriceUnificationTest`), coupling an
   unrelated suite to the live database and risking a red tree. The intent —
   columns reconciled at boot — is still met by
   `Init::ensureOpcionalGrupoRelTable()` (WU-1.T9), which touches the namespaced
   model. **No behavioral loss.**
2. **Extra drift lines in `proposal.md`**: beyond the `:42-43` bullet named by
   `WU-0.T1`, the same "one bulk-delete cleanup" drift was also corrected in the
   consumer header line and the External-consumer table row so the proposal is
   internally consistent with AD-7/AD-14.

## What remains (WU-2…WU-6 — NOT implemented in this batch)

- **WU-2** — `catalogo_opcional` membership API (`add_to_grupo`, `set_grupos`,
  `get_grupos`, `grupo_ids`, `grupos_labels`, `is_grouped`,
  `all_not_in_grupo`, `all_activos_sin_grupo`), `save()` freeze, `delete()`
  cascade, `where_id_grupo`/`all_sin_grupo` bridge bodies.
- **WU-3** — dependent readers (`catalogo_opcional_grupo`,
  `catalogo_articulo_opcional`, `catalogo_opcional_familia`,
  `VentasArticulo`, `CaracteristicaResolver`).
- **WU-4** — controllers, trait and views (checkbox list, batched label map,
  `VentasOpcionalGrupo` sites).
- **WU-5** — `tpvmod` dedupe + grouped detection; `tarifario` two bulk-delete
  cleanups.
- **WU-6** — verify pass (`phpstan`, grep audit, manual smoke, suites) and the
  `verify-report.md` (owned by `sdd-verify`, not written here).

## Workload / PR boundary

- **Mode**: chained PR slice (`auto-chain`, `stacked-to-main`).
- **Current work unit**: Slice A — Bridge foundation (WU-0 docs + WU-1).
- **Boundary**: starts from the untouched master baseline; ends with the bridge
  table + model + migration + boot ensure added and fully covered. No existing
  reader/writer switched.
- **Review budget impact**: production ~230 authored lines + tests ~470 +
  docs. Comfortably inside the 800-line budget for this slice.

## Composer / vendor note

This change adds **NO** Composer dependency. No `vendor/` step is required.

---

# Slice B1 — WU-2 membership API (stage-safe green intermediate)

- **Slice implemented**: **Slice B1 — WU-2** (`catalogo_opcional` membership API,
  AD-7 cascades, dual-write `save()`), deliberately staged so the tree stays
  **GREEN** with no big-bang removal.
- **Status**: Slice B1 complete and green. WU-3…WU-6 intentionally NOT
  implemented in this batch.

## B1 scope vs the WU-2 big-bang

The batch redefines WU-2 as a green intermediate slice. Delivered here:

1. **Membership API** on `FSFramework\model\catalogo_opcional`, exactly as the
   batch/design AD-4 pins the surface: `add_to_grupo(int): bool`,
   `remove_from_grupo(int $idGrupo = 0): bool` (new int arg; the 0 default is the
   legacy no-arg shim), `set_grupos(array): bool`, `get_grupos(): array`,
   `grupo_ids(): array`, `grupos_labels(): array`, `is_grouped(): bool`, plus the
   bridge-backed `all_sin_grupo()` / `all_not_in_grupo(int, …)` /
   `all_activos_sin_grupo(int, …)`.
2. **Dual-write (stage-safe)**: `save()` keeps writing
   `catalogo_opcionales.id_grupo` **and** reconciles the bridge through
   `syncBridgeFromLegacyGroup()` so both sources agree. The
   "grouped ⇒ delete direct article relations" side effect is preserved on the
   0 → ≥1 membership transition (in `add_to_grupo()` / `set_grupos()` and kept in
   `save()`).
3. **Legacy API kept as deprecated shims** (so WU-3/WU-4 code keeps working
   unchanged): `get_grupo()`, `etiqueta_grupo()`, `assign_to_grupo(int)` and the
   no-arg `remove_from_grupo()`. `public $id_grupo` and its constructor mapping
   are **kept**; no reader/controller/view was switched.
4. **AD-7 cascades**: `catalogo_opcional::delete()` deletes its bridge rows;
   `catalogo_opcional_grupo::delete()` deletes that group's bridge rows **and**
   keeps the legacy `id_grupo = NULL` update for unmigrated readers.
5. **Docs reconciliation**: `design.md` §Bridge DDL now states the post-create
   model touch is intentionally omitted and `Init::ensureOpcionalGrupoRelTable()`
   owns model provisioning on `init()`/`upgrade()`.

## Files changed (B1)

| File | Action | What was done |
|---|---|---|
| `plugins/catalogo_core/model/core/catalogo_opcional.php` | Modified | New bridge-backed membership API (`add_to_grupo`, `remove_from_grupo(int = 0)`, `set_grupos`, `get_grupos`, `grupo_ids`, `grupos_labels`, `is_grouped`, `all_sin_grupo`, `all_activos_sin_grupo`, `all_not_in_grupo`); `where_id_grupo()` → `EXISTS`/`NOT EXISTS` bridge bodies; `save()` dual-write; `delete()` bridge cascade; deprecated `get_grupo()`/`etiqueta_grupo()`/`assign_to_grupo()` shims; `grupo_rel_model()`/`articulo_opcional_model()` seams. |
| `plugins/catalogo_core/model/core/catalogo_opcional_grupo.php` | Modified | `delete()` deletes the group's bridge rows (AD-7) while keeping the legacy `id_grupo = NULL` update; `grupo_rel_model()`/`articulo_opcional_grupo_model()` seams. |
| `plugins/catalogo_core/tests/CatalogoOpcionalMembershipTest.php` | Created | 20 DB-free contract tests: add/remove/set writes, first-membership cleanup, projection/set sync, `is_grouped`/`grupo_ids`/`grupos_labels`/`get_grupos` ordering, bridge anti-join bodies, `where_id_grupo` `EXISTS`/`NOT EXISTS` (injection-safe), shim delegation, dual-write `save()`, both AD-7 cascades, and a source contract for the loose/filter predicates. |
| `plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php` | Modified | `test_where_id_grupo_treats_sentinel_and_rejects_injection` migrated to the new `EXISTS`/`NOT EXISTS` strings; `loadTrait()` requires the bridge model so the assertion is DB-free. |
| `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/design.md` | Modified | §Bridge DDL wording reconciled with the implemented deviation. |
| `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/tasks.md` | Modified | WU-2 B1 status note; `WU-2.T1`/`T6`/`T7` ticked with B1 annotations on `T2`/`T3`/`T4`/`T5` and `WU-3.T4`'s cascade half. |
| `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/apply-progress.md` | Modified | This merge. |

## TDD Cycle Evidence (B1)

| Task | Test file | Layer | Safety net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| WU-2.T1 | `tests/CatalogoOpcionalMembershipTest.php` | Unit (fake DB) | N/A (new) | ✅ 20 written, 11 errors + 9 failures | ✅ 20/20 (64 assertions) | ✅ set/add/remove, dual-write, cascades, SQL bodies, shims | ✅ Clean |
| WU-2.T2 (part) | `tests/CatalogoOpcionalesUnifiedControllerTest.php` | Unit (source/behavior) | ✅ existing suite green | ✅ updated assertion failed against the old body | ✅ 1/1 | ✅ sentinel + numeric + injection | ✅ Clean |

**RED evidence (executed)**

```bash
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoOpcionalMembership
# Tests: 20, Assertions: 18, Errors: 11, Failures: 9  (exit 2)

ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter test_where_id_grupo_treats_sentinel_and_rejects_injection
# Tests: 1, Failures: 1  (exit 1)
```

**GREEN execution gates**

| Command | Result |
|---|---|
| `ddev exec php -l <each changed PHP file>` | no syntax errors (4/4) |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoOpcionalMembership` | **OK** — Tests: 20, Assertions: 64 (exit 0) |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **OK** — Tests: 969, Assertions: 4245, Warnings: 2, Skipped: 1 (exit 0; baseline 949/4181 → **+20**) |
| `ddev exec php vendor/bin/phpunit` (root regression floor) | **OK** — Tests: 2760, Assertions: 9964, Skipped: 24 (exit 0; baseline 2742/9948) |

Baselines were captured before any edit (plugin `949/4181`, root `2742/9948`, both
exit 0). No pre-existing failures.

## Work Unit Evidence (B1)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → exit 0, `OK` (with the 2 pre-existing warnings), Tests: 969, Assertions: 4245 |
| Runtime harness command/scenario and exact result | **N/A** — the plugin suite is DB-free by convention (fake `fs_db2` / anonymous subclasses); the live bridge writes are exercised by the verify-phase manual smoke (WU-6). |
| Rollback boundary | Revert `model/core/catalogo_opcional.php` and `model/core/catalogo_opcional_grupo.php`; readers still work on the frozen `id_grupo` column and the bridge is inert. No schema change, no `id_grupo` drop. |

## Deviations from the WU-2 big-bang (deliberate, stage-safe)

1. **`save()` dual-writes instead of freezing.** B1 keeps the legacy column write
   and reconciles the bridge so unmigrated WU-4 controllers/views read a value
   consistent with membership. The AD-5 freeze (drop the `id_grupo` write and the
   post-save block) is deferred to the WU-3/WU-4 cutover.
2. **`$id_grupo` and the legacy shims are kept.** AD-6's property removal and the
   `get_grupo()`/`etiqueta_grupo()`/`assign_to_grupo()` removals are deferred;
   the shims now delegate to the bridge and stay green.
3. **`catalogo_opcional_grupo::delete()` keeps the legacy UPDATE.** AD-7 only
   required the bridge cascade; B1 adds it *and* keeps the column update so
   not-yet-migrated readers do not see stale memberships.
4. **Two test-only model seams added** (`grupo_rel_model()`,
   `articulo_opcional_model()`, `articulo_opcional_grupo_model()`) so the
   cascades and membership writes are DB-free-testable, following the plugin's
   existing seam convention.
5. **Test harness require.** `CatalogoOpcionalesUnifiedControllerTest::loadTrait()`
   now requires the bridge model, because `extras/VentasOpcionalesListTrait.php`
   (a forbidden path in this batch) does not. Production relies on the plugin
   autoloader, so no production file needed the same line.

## What remains (WU-3…WU-6 — NOT implemented in this batch)

- **WU-3** — dependent readers (`catalogo_opcional_grupo` bridge reads,
  `catalogo_articulo_opcional` anti-joins, `catalogo_opcional_familia`,
  `VentasArticulo`, `CaracteristicaResolver`).
- **WU-4** — controllers, trait and views (checkbox list, batched label map,
  `VentasOpcionalGrupo` sites) **plus** the deferred WU-2 removals:
  `$id_grupo`, the legacy shims, the `save()` freeze and the `grupos[]` payload.
- **WU-5** — `tpvmod` dedupe + grouped detection; `tarifario` two bulk-delete
  cleanups.
- **WU-6** — verify pass (`phpstan`, grep audit, manual smoke, suites) and the
  `verify-report.md` (owned by `sdd-verify`, not written here).

## Workload / PR boundary (B1)

- **Mode**: chained PR slice (`auto-chain`, `stacked-to-main`).
- **Current work unit**: Slice B1 — WU-2 membership API (stage-safe).
- **Boundary**: starts from the Slice A tree (bridge present, no writer
  switched); ends with the membership API, dual-write `save()`, AD-7 cascades
  and their DB-free tests green. No reader/controller/view switched.
- **Review budget impact**: **over the 800-line slice budget** — the code+tests
  commit is ~1239 authored lines (production ~320 + the new 880-line DB-free
  contract suite + the existing-test edit). This is a cohesive strict-TDD work
  unit (tests ship with the behavior they verify); it was **not** shrunk by
  dropping tests. Recommend an explicit `size:exception` for this slice, or a
  future split of the test file if the reviewer prefers.

---

# Slice B2 — WU-3 dependent readers (stage-safe green intermediate)

- **Slice implemented**: **Slice B2 — WU-3** (every behavior reader resolves
  opcional↔group membership through the `catalogo_opcional_grupo_rel` bridge).
- **Status**: Slice B2 complete and green. WU-4…WU-6 intentionally NOT
  implemented in this batch.

## B2 scope

WU-3 moves the **dependent readers** onto the bridge while the B1 dual-write, the
`$id_grupo` property and the legacy shims stay in place, so the tree never leaves
GREEN:

1. `catalogo_opcional_grupo::{get_opcionales, get_opcionales_activos}` now
   `INNER JOIN catalogo_opcional_grupo_rel r ON r.id_opcional = o.id` filtered by
   `r.id_grupo`; `count_opcionales()` counts the bridge. An opcional in two
   groups appears under each of them (OPG-06). `delete()` stays as delivered in
   B1 and `count_articulos()` is untouched.
2. `catalogo_articulo_opcional::validate_opcional_for_articulo()` now asks
   `$item->is_grouped()`; `get_opcionales_sueltos_from_articulo()` and
   `get_opcionales_directos_from_articulo()` replace the frozen predicate with the
   bridge anti-join `AND NOT EXISTS (SELECT 1 FROM catalogo_opcional_grupo_rel r
   WHERE r.id_opcional = o.id)` — a grouped opcional is still **not** loose
   (tarifario compatibility guard preserved exactly, OPG-08/OPG-11).
3. `catalogo_opcional_familia::{add_with_propagation, remove_with_propagation}`
   iterate **every** membership via `$op->grupo_ids()` instead of the single
   `$op->id_grupo`.
4. `VentasArticulo::loadOpcionalesDisponibles()` delegates to
   `all_activos_sin_grupo(0, 500)` instead of `all_activos(0, 500)` + a per-row
   `id_grupo` skip. The article↔group add/remove/toggle logic is untouched.
5. `CaracteristicaResolver::opcional_parents()` reads the memberships from
   `catalogo_opcional_grupo_rel` and unions the article parents of **all** groups
   (OPG-10). Query budget stays exactly **4** when at least one opcional has a
   membership and **3** when none — never per-opcional.

## Files changed (B2)

| File | Action | What was done |
|---|---|---|
| `plugins/catalogo_core/model/core/catalogo_opcional_grupo.php` | Modified | `get_opcionales()` / `get_opcionales_activos()` join the bridge; `count_opcionales()` counts bridge rows (OPG-06). |
| `plugins/catalogo_core/model/core/catalogo_articulo_opcional.php` | Modified | `validate_opcional_for_articulo()` → `is_grouped()`; two loose reads → bridge anti-join; new `articulo_opcional_grupo_model()` test seam (no behavior change). |
| `plugins/catalogo_core/model/core/catalogo_opcional_familia.php` | Modified | `add_with_propagation()` / `remove_with_propagation()` iterate `grupo_ids()`. |
| `plugins/catalogo_core/Controller/VentasArticulo.php` | Modified | `loadOpcionalesDisponibles()` uses `all_activos_sin_grupo()`; bridge model added to the explicit require chain. |
| `plugins/catalogo_core/Services/CaracteristicaResolver.php` | Modified | New `OPCIONAL_GRUPO_REL_TABLE` const; `opcional_parents()` reads the bridge and unions all groups; docblock updated to "four (three without memberships)". |
| `plugins/catalogo_core/tests/fixtures/opcional_visibility_fakes.php` | Modified | `$groups` is now `id_opcional => list<int>` emitting one row per pair from `catalogo_opcional_grupo_rel`. |
| `plugins/catalogo_core/tests/OpcionalVisibilityDerivationTest.php` | Modified | `groups: [7 => [3]]` seed + new `test_multi_group_opcional_unions_all_group_article_parents` (4-query assertion). |
| `plugins/catalogo_core/tests/CatalogoOpcionalGrupoTest.php` | Rewritten | New `GrupoReaderFakeDb` + model doubles; `testOpcionalStoresIdGrupo` / `testGroupedOptionalHasIdGrupo` removed; OPG-06/07/09/03 tests added; two source contracts (familia propagation, VentasArticulo available list). |
| `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/tasks.md` | Modified | WU-3 tasks `[x]` + B2 status note. |
| `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/apply-progress.md` | Modified | This merge. |

## TDD Cycle Evidence (B2)

| Task | Test file | Layer | Safety net | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|---|
| WU-3.T1 | `tests/fixtures/opcional_visibility_fakes.php` | Fixture | ✅ existing | ✅ fixture re-keyed before production | ✅ | ✅ one row per pair | ✅ Clean |
| WU-3.T2 | `tests/OpcionalVisibilityDerivationTest.php` | Unit | ✅ existing 12 | ✅ 2 failures (`null is true`) | ✅ 13/13 | ✅ single-group + multi-group + 4-query count | ✅ Clean |
| WU-3.T3 | `tests/CatalogoOpcionalGrupoTest.php` | Unit (fake DB) | ✅ existing 6 | ✅ 6 failures (OPG-06/09, familia, VentasArticulo) | ✅ 13/13 | ✅ 2-group seeds for OPG-06/07/09 | ✅ Clean |
| WU-3.T4/T5/T6/T7/T8 | production readers | Unit (fake DB) | ✅ existing suite | ✅ Driven by the RED above | ✅ | ✅ | ✅ Clean |

**RED evidence (executed)**

```bash
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoOpcionalGrupo
# Tests: 24, Assertions: 55, Failures: 6 (exit 1)

ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter OpcionalVisibilityDerivation
# Tests: 13, Assertions: 20, Failures: 2 (exit 1)
```

**GREEN execution gates**

| Command | Result |
|---|---|
| `ddev exec php -l <each changed PHP file>` | no syntax errors (8/8) |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoOpcionalGrupo` | **OK** — 24 tests, 64 assertions (exit 0) |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **OK** — Tests: 977, Assertions: 4264, Warnings: 2, Skipped: 1 (exit 0; baseline 969/4245 → **+8**) |
| `ddev exec php vendor/bin/phpunit` (root regression floor) | **OK** — Tests: 2773, Assertions: 10291, Skipped: 24 (exit 0) |

**Root-suite note**: the batch-start root run reported `Tests: 2765, Failures: 4`,
all in `plugins/tpvmod/tests/TpvmodTwigTemplatesTest.php` against an **uncommitted
in-flight tpvmod change** present in the working tree (out of scope, never
touched). That external work was committed during the batch; the final root run is
fully green with no catalogo_core-caused failures.

## Work Unit Evidence (B2)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → exit 0, `OK` (2 pre-existing warnings), Tests: 977, Assertions: 4264 |
| Runtime harness command/scenario and exact result | **N/A** — the plugin suite is DB-free by convention (fake `fs_db2` / anonymous subclasses); the live bridge reads are exercised by the verify-phase manual smoke (WU-6). |
| Rollback boundary | Revert the five reader files (`catalogo_opcional_grupo.php`, `catalogo_articulo_opcional.php`, `catalogo_opcional_familia.php`, `VentasArticulo.php`, `CaracteristicaResolver.php`) + the fixture/test updates. The bridge may stay populated but unused; `id_grupo` becomes the reader source again only after WU-2 is also reverted. No schema change, no `id_grupo` drop. |

## B1 limitation (intentional, documented after the B1 validator — doc-gap fix)

> A legacy `save()` on a multi-membership opcional reconciles the bridge from the
> single legacy value and drops the other memberships (idempotent; removed by the
> WU-4 cutover).

This is deliberate and stage-safe: B1 keeps the legacy `id_grupo` write and mirrors
it into the bridge, so a not-yet-migrated writer that only knows the single value
re-derives the bridge from it. Any membership added directly through the new API
(`add_to_grupo()` / `set_grupos()`) survives until such a legacy `save()` runs; the
WU-4 cutover removes the dual-write and the lossy reconciliation.

## Deviations from Design (B2)

1. **One narrow test seam added**
   (`catalogo_articulo_opcional::articulo_opcional_grupo_model()`), used by
   `get_opcionales_from_articulo()`. The design pinned that method as
   "behavior-preserved, no code change"; the seam changes **no** behavior and only
   lets the OPG-09 scenario-1 dedupe be covered DB-free (the plugin's established
   seam convention). Documented for the reviewer.
2. **Familia propagation covered by a source contract, not a behavior test.**
   `add_with_propagation()` / `remove_with_propagation()` construct
   `new catalogo_opcional()` internally with no seam, so a DB-free behavior test
   would require extra production surface. The contract asserts both methods
   iterate `grupo_ids()` and read no `->id_grupo`.
3. **`OPCIONAL_TABLE` const removed** from `CaracteristicaResolver` (its only
   reader — the old query 1 — was replaced by `OPCIONAL_GRUPO_REL_TABLE`).

## What remains (WU-4…WU-6 — NOT implemented in this batch)

- **WU-4** — controllers, trait and views (checkbox list, batched label map,
  `VentasOpcionalGrupo` sites) **plus** the deferred WU-2 removals: `$id_grupo`,
  the legacy shims, the `save()` freeze and the `grupos[]` payload.
- **WU-5** — `tpvmod` dedupe + grouped detection; `tarifario` two bulk-delete
  cleanups.
- **WU-6** — verify pass (`phpstan`, grep audit, manual smoke, suites) and the
  `verify-report.md` (owned by `sdd-verify`, not written here).

## Workload / PR boundary (B2)

- **Mode**: chained PR slice (`auto-chain`, `stacked-to-main`).
- **Current work unit**: Slice B2 — WU-3 dependent readers (stage-safe).
- **Boundary**: starts from the Slice B1 tree (membership API + dual-write); ends
  with every WU-3 reader bridge-backed and its DB-free tests green. No controller
  (`VentasOpcional*`), trait or view switched; `$id_grupo` and the shims remain.
- **Review budget impact**: **at the ~800-line budget** — production + tests total
  **802** changed lines (`git diff --numstat` over `Services`, `model`,
  `Controller`, `tests`; 713 added + 89 removed). The test file is a near-complete
  rewrite of a 109-line file, so part of the count is re-emitted kept tests, not
  new surface. The slice was **not** shrunk by dropping tests.

---

# Slice B3 — WU-4 controllers, trait and views (the membership cutover)

- **Slice implemented**: **Slice B3 — WU-4** (checkbox-list assignment UI, lossless
  `set_grupos()`, the multi-label "Grupo" column from one batched map, the four
  `VentasOpcionalGrupo` membership sites) **plus the deferred WU-2 removals**
  (AD-5 `save()` freeze, AD-6 `$id_grupo` + shims, AD-7 group-delete legacy reset).
- **Status**: Slice B3 complete and green. WU-5/WU-6 intentionally NOT implemented
  in this batch.

## B3 scope

WU-4 closes the membership cutover the earlier slices staged:

1. **Checkbox-list assignment (AD-11, `ui_checkbox_list`)**. The opcional edit form
   (`View/ventas_opcional.html.twig`) and the unified-list create modal
   (`View/ventas_opcionales.html.twig`) replace the single `<select name="sid_grupo">`
   with a `name="grupos[]"` checkbox list. `Controller/VentasOpcional` renders the
   list as **active groups ∪ the opcional's current memberships** (lossless:
   `membership_preserve_all`; an inactive-group membership still renders and
   survives), and `guardarOpcional()` persists the whole checked set through
   `catalogo_opcional::set_grupos()` after `save()`. `addArticulo()` gates on
   `is_grouped()`.
2. **Create path**. `VentasOpcionalesListTrait::new_opcional()` reads
   `$request->request->all('grupos')` and calls `set_grupos()` **inside** the
   existing `run_in_transaction()`, so memberships persist with the opcional.
3. **"Grupo" column — one batched map (OPG-02)**. `load_grupos_cache()` loads all
   groups once (names from **all** groups, active-only kept for the filter/create),
   then builds `nombres_grupo_opcional($id) = list<string>` from a single
   `map_for_opcionales($ids)` call (seam `opcional_grupo_rel_model()`). The list view
   renders one badge per label, `-` for a loose row.
4. **Group editor (OPG-07, AD-4/AD-6)**. `VentasOpcionalGrupo` uses
   `all_not_in_grupo((int) $this->grupo->id, 0, 500)` for the available list,
   `add_to_grupo(int)`, a `grupo_ids()` set test, and
   `remove_from_grupo((int) $this->grupo->id)`.
5. **Freeze / removals (the deferred WU-2 work)**. `catalogo_opcional` drops
   `public $id_grupo` and its constructor mapping, removes `get_grupo()` /
   `etiqueta_grupo()` / `assign_to_grupo()` and the no-arg legacy
   `remove_from_grupo()`, freezes `save()` (no `id_grupo` write, no post-save block)
   and deletes the dual-write machinery (`syncLegacyProjection()`,
   `syncBridgeFromLegacyGroup()`). `catalogo_opcional_grupo::delete()` drops the
   legacy `UPDATE catalogo_opcionales SET id_grupo = NULL`. The physical column
   stays; **no `DROP COLUMN`**. The "grouped ⇒ delete direct article relations"
   side effect stays on the 0 → ≥1 transition in `add_to_grupo()` / `set_grupos()`.
   `model/tarif_opcional.php` needed no change (its `where_id_grupo()` is inherited
   bridge-backed and its `save()` delegates to `parent::save()`).

## Files changed (B3)

| File | Action | What was done |
|---|---|---|
| `plugins/catalogo_core/Controller/VentasOpcional.php` | Modified | `grupos_asignados` / `grupos_asignados_ids`; new `loadGruposAsignados()` (active ∪ current, `?id_grupo` preset seeds the checked set without writing the model); `guardarOpcional()` reads `grupos[]` and calls `set_grupos()`; `addArticulo()` uses `is_grouped()`; bridge model added to the require chain. |
| `plugins/catalogo_core/Controller/VentasOpcionalGrupo.php` | Modified | Four membership sites migrated: `all_not_in_grupo()`, `add_to_grupo()`, `grupo_ids()` set test, `remove_from_grupo((int) …)`. Bridge model required. |
| `plugins/catalogo_core/extras/VentasOpcionalesListTrait.php` | Modified | `opcional_grupo_rel_model()` seam; `load_grupos_cache()` → all-groups names + one batched `map_for_opcionales()`; accessor `nombre_grupo_opcional()` → `nombres_grupo_opcional(): array`; `new_opcional()` reads `grupos[]` and calls `set_grupos()` inside the transaction; bridge model required. |
| `plugins/catalogo_core/View/ventas_opcional.html.twig` | Modified | Checkbox membership list (`name="grupos[]"`, checked from `fsc.grupos_asignados_ids`); `is_grouped()` gating; one view-group link per `fsc.grupos_asignados`. |
| `plugins/catalogo_core/View/ventas_opcionales.html.twig` | Modified | Create-modal checkbox list; "Grupo" cell renders every label from `fsc.nombres_grupo_opcional()`. |
| `plugins/catalogo_core/model/core/catalogo_opcional.php` | Modified | AD-5/AD-6: property + shims + dual-write removed; `remove_from_grupo(int)`; `save()` frozen. |
| `plugins/catalogo_core/model/core/catalogo_opcional_grupo.php` | Modified | AD-7: dropped the legacy `id_grupo = NULL` reset from `delete()`. |
| `plugins/catalogo_core/tests/CatalogoOpcionalesHtmxContractTest.php` | Modified | List-view contract migrated to `grupos[]` / `nombres_grupo_opcional()`; new edit-view checkbox contract. |
| `plugins/catalogo_core/tests/VentasOpcionalesControllerTest.php` | Modified | Stub gains `set_grupos()`; create payload → `grupos[]`; new group-editor call-site source contract. |
| `plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php` | Modified | Batched-map group-column test; stub `set_grupos()`; create payload → `grupos[]`; `opcional_grupo_rel_model()` seam. |
| `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` | Modified | Host fixture seeds `grupos_asignados` / `grupos_asignados_ids`, drops `id_grupo`. |
| `plugins/catalogo_core/tests/CatalogoOpcionalMembershipTest.php` | Modified | Freeze contracts (no legacy write; shims/arity removed; source freeze) replace the B1 dual-write/shim tests. |
| `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/tasks.md` | Modified | WU-4 tasks `[x]`; WU-2.T2..T5 marked complete with B3 notes. |
| `plugins/catalogo_core/openspec/changes/opcional-en-varios-grupos/apply-progress.md` | Modified | This merge. |

## TDD Cycle Evidence (B3)

| Task | Test file | Layer | RED | GREEN | TRIANGULATE | REFACTOR |
|---|---|---|---|---|---|---|
| WU-4.T1 | `tests/CatalogoOpcionalesHtmxContractTest.php` | Source contract (view) | ✅ list/edit view assertions failed | ✅ 55/55 focused | ✅ list view + edit view + no-shim/`.id_grupo` | ✅ Clean |
| WU-4.T2 | `tests/VentasOpcionalesControllerTest.php` | Unit + source contract | ✅ `grupos[]` payload failed | ✅ `set_grupos` args `[3,4]` | ✅ payload + 4 call sites | ✅ Clean |
| WU-4.T3 | `tests/CatalogoOpcionalesUnifiedControllerTest.php` | Unit (fake seams) | ✅ batched-map test failed | ✅ one map call, all labels | ✅ loose/single/inactive/unknown | ✅ Clean |
| WU-4.T4…T7 | production (controller/trait/views) | Unit + source contract | ✅ Driven by the RED above | ✅ full plugin suite | ✅ | ✅ Clean |
| WU-2.T2/T3/T4/T5 (deferred) | `MembershipTest`, `UnifiedControllerTest`, `HookMarkersTest` | Unit + source contract | ✅ 7 failures | ✅ 20/20 membership | ✅ freeze + shims + arity + source | ✅ Clean |

**RED evidence (executed)**

```bash
ddev exec "php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CatalogoOpcionalesHtmxContract|VentasOpcionalesController|CatalogoOpcionalesUnifiedController|CatalogoCoreHookMarkers'"
# Tests: 55, Assertions: 293, Failures: 5  (exit 1)

ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoOpcionalMembership
# Tests: 20, Assertions: 55, Failures: 7  (exit 2)
```

**GREEN execution gates**

| Command | Result |
|---|---|
| `ddev exec php -l <each changed PHP file>` | no syntax errors (6/6) |
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **OK** — Tests: 978, Assertions: 4277, Warnings: 2, Skipped: 1 (exit 0; baseline 977/4264) |
| `ddev exec php vendor/bin/phpunit` (root regression floor) | **OK** — Tests: 2778, Assertions: 10435, Skipped: 24 (exit 0) |

## Work Unit Evidence (B3)

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → exit 0, `OK` (2 pre-existing warnings), Tests: 978, Assertions: 4277 |
| Runtime harness command/scenario and exact result | **N/A** — the plugin suite is DB-free by convention (fake `fs_db2` / anonymous subclasses); the live checkbox save and batched map are exercised by the verify-phase manual smoke (WU-6). |
| Rollback boundary | Revert the three B3 commits in reverse order: (1) the group-editor contract test, (2) the model freeze/removals (`catalogo_opcional.php`, `catalogo_opcional_grupo.php`), (3) the controllers/trait/views. Reverting (3) first restores the single-select UI; reverting (2) restores the property/shims/dual-write. No schema change, no `id_grupo` drop. |

## Commits (B3, nested `plugins/catalogo_core` repo)

| Commit | Subject |
|---|---|
| `80d7e874` | `feat(catalogo_core): switch opcional group assignment to a checkbox list` |
| `3a0f3018` | `refactor(catalogo_core): freeze the opcional group column and drop the legacy shims` |
| `478be229` | `test(catalogo_core): lock the group-editor membership call sites` |

Not pushed; no PR opened.

## Deviations from Design (B3)

1. **`loadGruposAsignados()` extracted** instead of folding the union into the
   existing `loadGruposOpcional()`. `loadGruposOpcional()` runs before the opcional
   is loaded (it only needs the active catalog), so a separate method called after
   the opcional load is the smallest correct seam. Behaviour matches the design
   (active ∪ current memberships; `?id_grupo` preset seeds ids without writing the
   model).
2. **Three work units instead of one.** WU-4 is a single green boundary; it was
   split into consumer-switch → model-freeze → call-site-contract so each commit is
   independently reviewable and green. The model freeze cannot land before the
   consumers stop reading the property; the reverse order keeps every commit green.
3. **The group-editor call-site contract is a source contract** (no DB-free seam in
   `VentasOpcionalGrupo`), mirroring the plugin's established source-contract
   convention for controllers without an injectable seam.
4. **View "exclusive" hint** in the create modal uses a static `(exclusivo)` label
   (the modal has no translation key wired for it), while the edit form keeps the
   existing `optional-group-exclusive-short` key.

## What remains (WU-5, WU-6 — NOT implemented in this batch)

- **WU-5** — `tpvmod` cart-add dedupe + grouped detection (`'grouped'` flag instead
  of `id_grupo`, `grupo_id => null`); `tarifario` two bulk-delete cleanups
  (`limpiar_opcionales()` + `limpiar_todo()`) + the new compatibility test. Note:
  `tpvmod/lib/tpvmod_opcionales_ajax.php` still reads/writes `$candidate->id_grupo`
  on the real model (now an undefined-property warning, not a fatal) until WU-5.
- **WU-6** — verify pass (`phpstan`, grep audit, manual smoke, all three suites) and
  the `verify-report.md` (owned by `sdd-verify`, not written here).

## Workload / PR boundary (B3)

- **Mode**: chained PR slice (`auto-chain`, `stacked-to-main`).
- **Current work unit**: Slice B3 — WU-4 + the deferred WU-2 removals.
- **Boundary**: starts from the Slice B2 tree (all readers bridge-backed, model still
  dual-writes); ends with checkbox-list UI, lossless set writes, the batched label
  map, the four group-editor sites and a frozen legacy column with no PHP access.
- **Review budget impact**: production + tests total **~694** changed lines across
  the three commits (commit 1: 322+/84- = 406; commit 2: 81+/177- = 258; commit 3:
  ~30). Under the 800-line session budget; the individual commits are reviewable
  work units. The diff was **not** shrunk by dropping tests.
