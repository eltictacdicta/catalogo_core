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
