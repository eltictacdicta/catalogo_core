# Tasks: Migrar Familias de Tarifario a Catalogo Core (migración acotada)

Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` — STRICT TDD (RED → GREEN per logic task).

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1800–2400 (2 XML tables, 2 models, 2 services, 1 options/Init modify, 2 controller+view modifies, 1 new page+view, translations, ~8 test classes) |
| 400-line budget risk | High (well above budget) |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 (schema + models + options flag) → PR 2 (migration service + Init + tree guard) → PR 3 (tree UI + article assignment) → PR 4 (catalog view + translations + regression) |
| Delivery strategy | auto-forecast (delivery: auto) |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Extension tables + models + `familyStructureEnabled()` flag (D1, D2, D4) | PR 1 | phpunit -c plugins/catalogo_core/phpunit.xml --filter 'FamiliaEstructura|ArticuloFamiliaEstructura|CatalogoOptions' | N/A — unit-only (mock `fs_db2`; no DB flows) | New files + Init ensure-order line; additive, droppable |
| 2 | `FamiliaEstructuraMigration` + flag-gated Init call + `FamiliaTreeGuard` (D3) | PR 2 (base = PR 1 branch) | phpunit -c plugins/catalogo_core/phpunit.xml --filter 'FamiliaEstructuraMigration|FamiliaTreeGuard|InitUpgrade' | DDEV integration: seed legacy rows → run migration twice → row counts stable | Revert PR 2; migration flag default-off means zero behavior change |
| 3 | `VentasFamilias` tree UI + `VentasArticulo` assignment block (D5) | PR 3 (base = PR 2 branch) | phpunit -c plugins/catalogo_core/phpunit.xml --filter 'VentasFamilias|ArticleAssignment|HookPoints' | DDEV smoke: open ventas_familias with `?codlista=...`, re-parent POST, cycle POST | Revert PR 3 only; page name unchanged, fs_access intact |
| 4 | `VentasCatalogoView` page + translations + full regression (D5, D7) | PR 4 (base = PR 3 branch) | phpunit -c plugins/catalogo_core/phpunit.xml | Full suite green in DDEV; HookRegistrationTest (tarifario) green | Revert PR 4 only (new page + translation keys) |

## Phase 1: Extension Tables & Models (foundation)

- [x] 1.1 **[TDD-RED]** Write `tests/CatalogoFamiliaEstructuraTest.php`: upsert by composite PK `(codlista, codfamilia)` (F1/L1 + F1/L2 → 2 rows; re-save F1/L1 updates, no duplicate — R-FJ-002 scenario 1), `test()` validation, XML parse asserts FK→`catalogo_listas_precio` CASCADE + FK→`familias` CASCADE (D2). GREEN: create `model/table/catalogo_familia_estructura.xml` (column mapping D2 table 1; `madre` FK-less legacy parity) + `model/core/catalogo_familia_estructura.php` (fs_model, `strict_types`, PHPDoc on `en_tarifa`/`nivel` semantics per D2 risk notes).
- [x] 1.2 **[TDD-RED]** Write `tests/CatalogoArticuloFamiliaEstructuraTest.php`: PK `(codlista, referencia)`, `codfamilia` NULL-able with NO FK (legacy parity — D2 table 2), FK→`articulos` + FK→`catalogo_listas_precio` CASCADE, re-assign updates (R-FJ-003 scenario 1). GREEN: create `model/table/catalogo_articulo_familia_estructura.xml` + `model/core/catalogo_articulo_familia_estructura.php`.
- [x] 1.3 Modify `Services/CatalogoOptions.php`: add `familyStructureEnabled(): bool` (default **false**, multitarifa flag pattern D3). **[TDD-RED]** Extend `tests/Services/CatalogoOptionsTest.php`: flag round-trip, safe default when key absent (R-FJ-002 install precondition; D3 lifecycle gate).
- [x] 1.4 Modify `Init.php` `ensureCatalogTables()`: install order `catalogo_familia_estructura` → `catalogo_articulo_familia_estructura` immediately after `catalogo_articulo_precios` (D4; R-MT-006 scenario "Structure install depends on lists"). **[TDD-RED]** Extend `tests/InitUpgradeTest.php`: assert FK ordering (listas before estructura before articulo_estructura).

## Phase 2: Migration Service & Tree Guard

- [x] 2.1 **[TDD-RED]** Write `tests/Services/FamiliaEstructuraMigrationTest.php` (mock `fs_db2` recording SQL, dual-engine): step1 before step2 (FK order — R-FJ-005 scenario 1), `INSERT IGNORE`/`ON CONFLICT DO NOTHING` present (idempotency), **zero DELETE/DROP/UPDATE statements** in generated SQL (non-destructive), orphan-skip `JOIN catalogo_listas_precio` present, early-return when `tarif_tarifa_familia` missing. GREEN: create `Services/FamiliaEstructuraMigration.php` per D3 (static `migrateIfNeeded(\fs_db2 $db)`, set-based SQL, transaction per step with rollback-on-Throwable, orphan count → `error_log('[catalogo_core] familia-estructura migration: N orphan rows skipped')`).
- [x] 2.2 **[TDD-RED]** Write `tests/Services/FamiliaTreeGuardTest.php` (mock db chain-walk): direct cycle, deep cycle, self-parent → `RuntimeException`; valid chain passes (R-FJ-001 cycle scenario). GREEN: create `Services/FamiliaTreeGuard.php` with `assertAcyclic(\fs_db2 $db, string $codfamilia, ?string $newMadre): void` (D5 controller-level cycle check).
- [x] 2.3 Modify `Init.php`: call `FamiliaEstructuraMigration::migrateIfNeeded()` in boot path alongside `CatalogLegacyTableMigration`, **guarded by** `CatalogoOptions::familyStructureEnabled()` (D3 lifecycle hook; flag-off → zero rows copied). **[TDD-RED]** Extend `tests/InitUpgradeTest.php`: flag-off → no migration call; flag-on → call present after ensure-tables.
- [x] 2.4 **[TDD-RED + GREEN]** Integration test `tests/FamiliaEstructuraMigrationIdempotencyTest.php` (DDEV DB): seed legacy rows incl. one orphan `codtarifa`, run migration **twice**, assert canonical row counts stable, legacy `tarif_*` tables untouched, orphan logged (R-FJ-005 idempotency / legacy-preserved / orphan scenarios). This is the final-verification seam for 5.2 re-run. Skips with an explicit reason when no DB/tables are reachable; canonical tables are restored to their pre-test snapshot in `tearDown()`.

## Phase 3: Tree UI & Article Assignment

> **DEFERRED (2026-10-26): 3.1, 3.2, 3.3 and 3.5 are blocked, not pending.**
> They target `Controller/VentasFamilias.php` / `View/ventas_familias.html.twig`
> (page `ventas_familias`, menu `ventas`) and assert that page still exists.
> That page was **retired** two days after this design was written
> (`3a5a42ef`, 2026-09-06, "repuntar menu familias a catalogo y retirar
> ventas_familias"), and `Init::retireVentasFamiliasPage()` deletes its
> `fs_pages` row on every boot, so 3.5 asks to verify the opposite of what the
> code does.
>
> Current families UI candidates: `controller/tarif_familias.php` (1492 lines,
> legacy `HtmxCrudController`, the real families CRUD) and
> `Controller/VentasFamilia.php` (160 lines, PSR-4, single-family detail page).
> Choosing between them changes the cost and the architecture of this change, so
> it is an explicit decision, not an implementation detail.
>
> **Decision taken:** deliver the unblocked work first — 3.4 plus Phase 4 — and
> settle the tree-UI target before touching 3.1/3.2/3.3/3.5. *(That work landed:
> 3.4 in `8c9be872`, Phase 4 in `216172e0` + `7be4c659`.)*
>
> **Target settled (2026-10-26): `tarif_familias`.** The user chose the real
> families CRUD over the PSR-4 detail page. What the design did not know:
> `controller/tarif_familias.php` **already implements the family tree** —
> reorder, promote/demote, toggles, `save_familia()`, HTMX row fragments — but
> it reads and writes the **legacy** `tarif_tarifa_familia` table through
> `$this->codtarifa`. So 3.1/3.2 are a **data-source change on a 1,492-line
> controller**, not an "add columns" extension.
>
> **Consequence for the implementation:** the page becomes dual-source, selected
> by `familyStructureEnabled()`. Flag **off** → today's behaviour, legacy table,
> byte-for-byte (safe rollback). Flag **on** → canonical
> `catalogo_familia_estructura` keyed by `codlista`, plus `FamiliaTreeGuard`,
> plus the `ArticlePermissionFilterEvent` dispatch. `tarif_*` stops receiving
> writes in the flag-on path (D6 item 4: accepted coexistence divergence).
>
> **Landed (2026-10-28):** 3.1 + 3.3 (`Services/FamiliaEstructuraStore.php`, the
> dual-source seams in the controller) and 3.2 + 3.5 (the per-list tree view in
> `View/tarif_familias.html.twig` + `controller::source_query()`, and the
> page-name/access preservation test). Phase 3 is complete.

- [x] 3.1 Modify `controller/tarif_familias.php` (target retargeted from the retired `VentasFamilias`): list selector (active `catalogo_listas_precio` only, flag-gated), batch read `catalogo_familia_estructura WHERE codlista = ?` (no N+1, D5), structure-column save upsert via model, dispatch `ArticlePermissionFilterEvent` on structure writes (D6). Flag off keeps the legacy model byte-for-byte. **[TDD-RED]** Extend controller tests: flag-off hides selector/structure UI (inert), flag-off POST → structure not written. *(Landed: `Services/FamiliaEstructuraStore.php` + dual-source seams in the controller; `tests/Services/FamiliaEstructuraStoreTest.php`, `tests/Controller/TarifFamiliasDualSourceTest.php`.)*
- [x] 3.2 Modify `View/tarif_familias.html.twig` (target retargeted from the retired `VentasFamilias`): per-list tree view with the active-list selector, structure columns per node and list-scoped action URLs (`?codlista=`), CSRF on every POST form, explicit empty states. *(Landed: `View/partials/familias/estructura_header.html.twig` for the selector; `controller::source_query()` + `get_lista_actual()` as the single source-of-truth seam the template consumes, so no template branches on codlista/codtarifa; legacy (flag off) render verified byte-equivalent against the previous templates — whitespace-only diff — with `tests/View/TarifFamiliasTreeViewTest.php` pinning both modes. Excel JS stays legacy-only because the canonical path rejects Excel; it is not loaded with the flag on.)*
- [x] 3.3 Cycle-rejection in save path: `save_familia` → `FamiliaTreeGuard::assertAcyclic()` before write; on `RuntimeException` → `new_error_msg()`, no `familias` row modified (R-FJ-001 cycle scenario). **[TDD-RED]** Controller test: cycle POST rejected with error message + row unchanged. *(Landed: the guard runs on the canonical save/promote/demote path, plus a list-scoped `FamiliaEstructuraStore::assertAcyclicInList()` for cycles that only exist in `catalogo_familia_estructura.madre`.)*
- [x] 3.4 Modify `Controller/VentasArticulo.php` + view: "Asignación a familias" block per selected list writing `catalogo_articulo_familia_estructura` (D5, R-FJ-003), flag-gated, event dispatch on save (D6). **[TDD-RED]** Extend article controller tests: assignment upsert per list, L2 scoped view excludes L1 assignment (R-FJ-003 scenario 2).
- [x] 3.5 Page-name preservation, retargeted to the live page: `tarif_familias` keeps the page name `tarif_familias`, title `Familias` and menu `catalogo` (R-FJ-006 access rows resolve by page name), and `ventas_catalogo_view` registers under the `ventas` menu as a distinct name (no `fs_access` copy migration needed). **[TDD]** `tests/FamiliasPageAccessPreservationTest.php`. *(`VentasFamilias` / `ventas_familias` no longer exists: retired in `3a5a42ef` and deleted on boot by `Init::retireVentasFamiliasPage()`, so the original wording of this task asserted the opposite of the code. Asserted against the settled target instead; see "DEFERRED" note above.)*

## Phase 4: Catalog View & Translations

- [x] 4.1 Create `Controller/VentasCatalogoView.php` (page `ventas_catalogo_view`, menu `ventas`, admin-only showonmenu — D5) + `View/ventas_catalogo_view.html.twig`: tree filtered `activa = TRUE AND en_catalogo = TRUE` for selected `codlista`, articles from `catalogo_articulo_familia_estructura WHERE en_catalogo = TRUE` (R-FJ-004 both scenarios). List identity ONLY `catalogo_listas_precio.codlista` (R-MT-002 modified scenario). **[TDD-RED]** `tests/VentasCatalogoViewControllerTest.php`: L1-only rendering, inactive structures hidden, empty state message.
- [x] 4.2 Frozen-hook compatibility: add `tests/ViewHookPointsTest.php` asserting the 4 hook points (`ventas_articulo_tabs_after`, `ventas_articulo_tab_pane_after`, `ventas_opcional_tabs_after`, `ventas_opcional_tab_pane_after`) still present in `ventas_articulo`/`ventas_opcional` templates (R-FJ-007; absorbed UI adds its own blocks, touches no hook point). **[TDD-RED]** before 4.1 template edits.
- [x] 4.3 Translations: add prefixed keys to `translations/messages.es.yaml` (+ fallback locale) — D7 seed set (`catalogo-core-familias-tree-title`, `-lista-selector`, `-estructura-capitulo`, `-estructura-orden`, `-estructura-en-catalogo`, `-estructura-en-tarifa`, `-estructura-activa`, `-ciclo-error`, `-articulos-asignacion-tab`, `-catalogo-view-title`, `-catalogo-view-empty`). **[TDD-RED]** `tests/TranslationAuditTest.php`: every key used by new templates starts with `catalogo-core-` and resolves (R-FJ-008).
- [x] 4.4 Dep-free audit: extend grep-audit test — no `require`/`require_once`/class reference to `plugins/tarifario/` anywhere in `plugins/catalogo_core/`; no `tarif_tarifa_familia`/`tarif_tarifa_articulo` reads outside `FamiliaEstructuraMigration` (D6 hard rule; R-MT-002 "no legacy codtarifa consulted"). No `fsframework.ini` `require` toward tarifario.

## Phase 5: Regression & Verification

- [x] 5.1 Full suite green: **1462 tests / 6025 assertions**, 2 warnings + 1 skipped (all pre-existing).
- [x] 5.2 Idempotency re-run in DDEV: `FamiliaEstructuraMigrationIdempotencyTest` passes 4/4 against the real database (double run, counts stable, `tarif_*` untouched, orphan logged).
- [x] 5.3 `HookRegistrationTest` (tarifario) **4/4 OK**, and `tests/ViewHookPointsTest` (catalogo_core) green: the 4 host hook points survive.
- [x] 5.4 Inert when off: `disabledCapabilityReportsItselfAndQueriesNothing` (0 queries), `test_flag_off_exposes_no_block_state_and_reads_nothing`, `test_flag_off_assignment_post_writes_nothing`, `test_template_gates_the_block_on_the_family_structure_flag` and `familiaEstructuraMigrationIsGatedByTheFamilyStructureFlag` all green.
- [x] 5.5 Coverage map updated below. **Verify-report readiness: ready to archive** — 3.2 and 3.5 landed 2026-10-28 (per-list tree view + page/access preservation), so every Phase 3 task is closed.

## Coverage Map

| Spec reqs | Tasks |
|-----------|-------|
| R-FJ-001 | 2.2, 3.1–3.3, 3.5 |
| R-FJ-002 | 1.1, 1.3, 1.4 |
| R-FJ-003 | 1.2, 3.4 |
| R-FJ-004 | 4.1 |
| R-FJ-005 | 2.1, 2.3, 2.4, 5.2 |
| R-FJ-006 | 3.5 |
| R-FJ-007 | 4.2, 5.3 |
| R-FJ-008 | 4.3 |
| R-MT-002 (modified) | 1.1, 1.2, 4.1, 4.4 |
| R-MT-006 | 1.4, 2.1, 2.3 |
