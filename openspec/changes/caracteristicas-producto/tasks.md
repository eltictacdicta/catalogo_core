# Tasks: caracteristicas-producto

- **Change**: `caracteristicas-producto`
- **Phase**: `tasks`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`)
- **Secondary plugin**: `plugins/tarifario`
- **Artifact store**: `openspec`. Core `openspec/` is reference only — NEVER create an entry there.
- **Authoritative inputs**: `proposal.md`, `design.md` (pinned names §1, test plan §14, WU decomposition §15), `specs/**` (48 requirements / 153 scenarios — 151 before WU-8 added the DEV-17 and DEV-18 scenarios to `tarifario/catalogo-integration`), `preproposal.md` (D1–D12).
- **Supersedes**: `plugins/tarifario/openspec/changes/mover-tarifa-catalogo-opcionales-a-tarifario/` (never applied).
- **TDD rule**: every production task below is preceded by its paired RED test named from design §14.1. No test-after-the-fact.

---

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | 4,000–7,000 (proposal §Size Forecast) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR-1 (WU-1+WU-2) → PR-2 (WU-3) → PR-3 (WU-4) → PR-4 (WU-5+WU-6) → PR-5 (WU-7, post-soak) |
| Delivery strategy | `single-pr` (session default — **infeasible, see below**) |
| Chain strategy | pending — maintainer must choose |

```text
Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High
```

**Honest delivery statement.** The session `delivery_strategy` is `single-pr` with a
400-line review budget. This change is multi-thousand lines across two plugins plus
locked-spec rewrites, so `single-pr` **cannot** contain it. Landing it as one PR
requires a maintainer-approved **`size:exception`**. The alternative is switching to a
chained/stacked strategy (recommended: feature-branch chain on tracker
`caracteristicas-producto`, PR #1 base = tracker, each child rebased onto its
predecessor). Even chained, WU-2/WU-3/WU-4 each exceed 400 lines, so every oversized PR
in the chain also carries a `size:exception`; the chain only shrinks review blast radius
and keeps each work unit independently revertible. WU-4 MUST NOT start before WU-3's
parity evidence exists.

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| WU-1 | Definition + value catalog + 3 scope tables + defaults + panel shell | PR-1 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'Caracteristica(Model|ValorCatalogo|ValorTriad|ScopeTable|Defaults|Boundaries)\|InitCaracteristicasTables\|VentasCaracteristicasController'` | Plugin suite + root **Plugins** suite; panel smoke `page=ventas_caracteristicas` | Drop the 5 new tables + revert `Init.php`; no legacy data touched |
| WU-2 | Resolver + value store + batch reader + list/export feature columns | PR-1 | `... --filter 'CaracteristicaResolver\|CaracteristicaValorStore\|CaracteristicaAssignmentScope\|VentasArticulosListCaracteristicas\|ArticuloExcelCaracteristica'` | List renders with tarifa + `listable` defs (manual); export workbook diff | Remove new Services + revert 5 modified files; legacy columns authoritative |
| WU-3 | Backfill + read-through flag + dual-write + consumer rewrites | PR-2 | `... --filter 'CaracteristicaBackfill\|CaracteristicaReadThrough'` | Apply backfill on a scratch DB copy; compare flag-off/flag-on outputs | Disable read-through flag; backfill is `NOT EXISTS`-guarded and re-runnable |
| WU-4 | D12 derivation + opcional flags/toggles removed + migrated tests | PR-3 | `... --filter 'OpcionalVisibilityDerivation\|OpcionalVisibilityParity\|CaracteristicaColumnDrop'` | Opcional list shows derived read-only indicator; parity test vs pre-change | Re-add nullable columns, re-derive from feature values; requires parity evidence first |
| WU-5 | Clone step 12 in `heredar_estructura()` | PR-4 | `... --filter 'CaracteristicaClone\|TarifTarifasHeredarCaracteristica'` | Create a tarifa with inherit; inspect the 3 scope tables | Revert `tarif_tarifas.php` only; no other WU depends on it |
| WU-6 | tarifario defaults + consumer rewrites + tarifario deltas | PR-4 | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | Wizard import `En Tarifa`/`En Catálogo`; canary create path | Revert `plugins/tarifario/Init.php` + consumers; catalogo_core defaults stay |
| WU-7 | Post-soak gated `DROP COLUMN` (destructive) | PR-5 | `... --filter 'CaracteristicaColumnDrop\|CaracteristicaReversibility'` | Runner `plugins/catalogo_core/tools/run_caracteristica_column_drop.php` (dry-run default; `--apply --dev17-rewritten` on a pre-drop dump); re-derive restore via `CaracteristicaReversibilityTest` | Pre-drop dump + re-add nullable columns + re-derive from resolver |
| WU-8 | Close the soak blockers (DEV-17/DEV-18), staleness + SUGGESTION 2/4 | PR-5 (pre-soak) | `plugins/tarifario/phpunit.xml --filter 'TarifCatalogoJsonImportVisibilityTest\|TarifCatalogoMembershipFilterRatificationTest'` + `plugins/catalogo_core/phpunit.xml --filter 'ArticuloExcelCaracteristicaTest\|CaracteristicaColumnDropTest'` | N/A — the JSON import batch methods need the live DB and `exec()` auto-commits; the DB-free closed loop (real store write → real resolver read) is the evidence | Revert the two `tarif_catalogo_view` helpers + batch call sites, `dropDeadOpcionalColumns()`, `ordered_exportable()`, and the doc/spec edits independently |

---

## Locked-contract regression set (run unedited, MUST stay green)

These suites lock ALC-02/ALC-04, the export header list, the opcional precedence and the
frozen hook markers. They are updated **only** where a delta explicitly migrates them.

- `plugins/catalogo_core/tests/Controller/VentasArticulosListAbsorptionTest.php` (read-only)
- `plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php` (read-only)
- `plugins/catalogo_core/tests/Services/ArticuloExcelExportServiceTest.php` (read-only)
- `plugins/catalogo_core/tests/Services/ArticuloExcelImportWizardServiceTest.php` (read-only)
- `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` (read-only)
- `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` (read-only)
- `plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php` (read-only)
- `plugins/catalogo_core/tests/TarifTarifaOpcionalTest.php`, `TarifTarifaOpcionalPrecedenceTest.php`, `TarifTarifaOpcionalLifecycleTest.php` (read-only)
- `plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php` (read-only)

Explicitly migrated by the deltas (edit allowed, same commit as its WU):
WU-4 — `tests/TarifFamiliaToggleTest.php`, `tests/TarifTabPreciosTest.php`,
`tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`,
`tests/TarifOpcionalesControllerMasterStateTest.php`,
`tests/TarifOpcionalesControllerContractTest.php`, `tests/TarifOpcionalEditCaracteristicaTest.php`,
`tests/TarifTarifaOpcional*.php`.
WU-6 — `plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php`,
`ExcelImportWizardFieldCatalogTest.php`, `Controller/TarifCatalogoOpcionalMasterExportTest.php`.
WU-6 also migrated (delta MODIFIED requirement wins over a stale read-only listing):
`tests/Model/TarifArticuloFactoryForImportTest.php` (the A4 visibility is no longer a
price-row flag), `tests/Services/ExcelHierarchyServiceCreateFamiliaRowTest.php` (the
family visibility also rides the feature store) and `tests/TarifConfiguradorOpcionalesTest.php`
(the removed opcional-owned writes). New WU-6 files:
`tests/CaracteristicaDefaultsRegistrationTest.php`, `tests/Services/ExcelImportWizardFieldCatalogTest.php`,
`tests/Services/ExcelRowUpdaterFeatureVisibilityTest.php`, `tests/Integration/ExcelWizardCreatePathFeatureTest.php`,
`tests/Controller/TarifCatalogoVisibilityReadThroughTest.php`, `tests/Integration/OpcionalOwnedVisibilityRemovalTest.php`.
New WU-8 files (soak blockers + staleness): `plugins/tarifario/tests/Controller/TarifCatalogoJsonImportVisibilityTest.php`
(DEV-18), `plugins/tarifario/tests/Controller/TarifCatalogoMembershipFilterRatificationTest.php` (DEV-17); extended
`plugins/catalogo_core/tests/CaracteristicaColumnDropTest.php` (dead-column clause, SUGGESTION 2) and
`plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php` (export ordering, SUGGESTION 4).

`plugins/catalogo_core/tests/InitUpgradeTest.php` is excluded from the root suite but MUST pass in the plugin suite.
Root gates every WU: root **Plugins** suite + `ddev exec composer phpstan`.

---

## Design-verified divergences (do NOT re-litigate)

1. **`tarif_familia_ext` has NO `en_catalogo`/`en_tarifa` columns** (live table is
   `codfamilia`, `capitulo`, `nivel` — a retired read-only surface; the flags live on
   `tarif_tarifa_familia`). The CAR-13 backfill surface (d) and the CAR-15 clause-2 drop
   for that table are **introspection-guarded no-ops** (`CaracteristicaColumnDropMigration::hasColumn()`
   / `columnExists()`), never literal table operations. The real surface is covered by the
   `tarif_tarifa_familia` statements plus the resolver's `DEF` probe. `verify` asserts the
   intent (family visibility parity), not the literal table.
2. **`catalogo_opcional_precios.en_catalogo` STAYS.** It is a per-lista price inclusion
   flag, not an opcional-visibility flag, and is not in CAR-15's drop list.

---

## Grep gate (CAR-19)

- [x] G1.1 Add the boundary scan to `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php`: extract tarifario-owned class names from `plugins/tarifario` (production, non-vendor, non-tests) and fail on any reference from `plugins/catalogo_core` (production) with the frozen **pre-existing** baseline only (observed 2026-09-14 during PR-1 apply; reconciled here, MUST NOT grow): `tarif_grupo_rol`, `tarif_grupo_tarifa`, `tarif_grupo_usuario`, `tarif_historial_precios`, `tarif_precio_historial`, `tarif_tarifa_rol` (string args to `loadTarifarioModel()` / pre-existing page strings). Any other hit fails the gate.
- [x] G1.2 Assert no file under `base/`, `src/`, root `controller/`, root `model/` changed; no entry under `openspec/changes/caracteristicas-producto/`; no new Composer requirement in either plugin.
- [x] G1.3 Run the same scan as a shell gate after every WU (`grep -rnE` over `plugins/catalogo_core --include='*.php'` excluding `/openspec/`, `/tests/`).

---

## WU-1 — Foundation: definition, value catalog, scope tables, defaults, panel shell

**Objective**: land the 5 tables + models + XMLs, the defaults registry and the panel
skeleton (definition CRUD, flags, predefined values) behind CSRF + permission gates.
**Depends on**: none.
**New files**: `model/core/catalogo_caracteristica.php`, `model/core/catalogo_caracteristica_valor.php`,
`model/core/caracteristica_scope_value.php`, `model/core/catalogo_caracteristica_global.php`,
`model/core/catalogo_caracteristica_familia.php`, `model/core/catalogo_caracteristica_articulo.php`,
`model/table/catalogo_caracteristicas.xml`, `model/table/catalogo_caracteristica_valores.xml`,
`model/table/catalogo_caracteristica_global.xml`, `model/table/catalogo_caracteristica_familia.xml`,
`model/table/catalogo_caracteristica_articulo.xml`, `Services/CaracteristicaRegistry.php`,
`Event/CaracteristicaPermissionFilterEvent.php`, `Controller/VentasCaracteristicas.php`,
`controller/ventas_caracteristicas.php`, `View/ventas_caracteristicas.html.twig`.
**Modified**: `Init.php` (`ensureCaracteristicasTables()` + `registerDefaults()` in `init()`/`upgrade()`).

- [x] 1.1 RED: `plugins/catalogo_core/tests/CaracteristicaModelTest.php` — CAR-01 (3 scenarios), CAR-02 (2).
- [x] 1.2 GREEN: create `plugins/catalogo_core/model/core/catalogo_caracteristica.php` (pinned API §2.1, `TIPOS` const, `test()` contract) + `plugins/catalogo_core/model/table/catalogo_caracteristicas.xml`.
- [x] 1.3 RED: `plugins/catalogo_core/tests/CaracteristicaValorCatalogoTest.php` (CAR-03, 2) and `plugins/catalogo_core/tests/CaracteristicaValorTriadTest.php` (CAR-04 triad, 2).
- [x] 1.4 GREEN: `plugins/catalogo_core/model/core/catalogo_caracteristica_valor.php` + `plugins/catalogo_core/model/table/catalogo_caracteristica_valores.xml`; derive `custom` in the triad (never caller input).
- [x] 1.5 RED: `plugins/catalogo_core/tests/CaracteristicaScopeTableTest.php` — CAR-05 keys/cascades (2).
- [x] 1.6 GREEN: `plugins/catalogo_core/model/core/caracteristica_scope_value.php` (abstract, no table/XML) + the 3 concrete models + 3 XMLs; per-model `install()` FK-touch order (§2.3). Do NOT add `copy_from_tarifa()` here (WU-5).
- [x] 1.7 RED: `plugins/catalogo_core/tests/InitCaracteristicasTablesTest.php` — CAR-05 standalone FK-safe/idempotent bootstrap.
- [x] 1.8 GREEN: `plugins/catalogo_core/Init.php` — add `ensureCaracteristicasTables()` (FK-safe order §9.1) to `init()` and `upgrade()`; do NOT extend `DEFAULT_SEED_MODELS`.
- [x] 1.9 RED: `plugins/catalogo_core/tests/CaracteristicaDefaultsTest.php` — CAR-11 fresh/existing/deleted/non-deletable.
- [x] 1.10 GREEN: `plugins/catalogo_core/Services/CaracteristicaRegistry.php` (targeted upsert, `seedBoolPair()`, never `seed_if_empty`) + wire `registerDefaults()` in `Init.php`.
- [x] 1.11 RED: `plugins/catalogo_core/tests/Controller/VentasCaracteristicasControllerTest.php` — CAR-18 scenarios 1–3 and 5 (render, create/edit, CSRF failure, defaults blocked/deactivable).
- [x] 1.12 GREEN: `plugins/catalogo_core/Event/CaracteristicaPermissionFilterEvent.php` (default-allow `deny(reason)`; actions `delete_definition`, `assign_global`, `assign_familia`), `plugins/catalogo_core/Controller/VentasCaracteristicas.php`, `plugins/catalogo_core/controller/ventas_caracteristicas.php`, `plugins/catalogo_core/View/ventas_caracteristicas.html.twig` (slug `ventas_caracteristicas`, menu `catalogo`, `ordernum 109`). Ship definition/predefined-value management; the three-scope assignment actions land in WU-2 with the store (their RED tests are 2.11).
- [x] 1.13 RED+GREEN: `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php` — CAR-19 grep gate (G1.1/G1.2).
- [x] 1.14 REFACTOR: run plugin suite + root Plugins suite + `ddev exec composer phpstan`.

**Acceptance**: CAR-01, CAR-02, CAR-03, CAR-04 (triad), CAR-05 (keys + standalone bootstrap), CAR-11 (fresh/existing/deleted/non-deletable), CAR-18 (render/create-edit/CSRF/defaults), CAR-19 (boundaries + grep gate).

---

## WU-2 — Resolver, value store, batch reader, list/export feature columns

**Objective**: the uniform read path, the single write path, and the `listable`/`importable`/`exportable` integration.
**Depends on**: WU-1.
**New files**: `Services/CaracteristicaResolver.php`, `Services/CaracteristicaValorStore.php`, `Services/CaracteristicaValorBatchReader.php`, `Services/CaracteristicaConfig.php`.
**Modified**: `extras/VentasArticulosListTrait.php`, `Controller/VentasArticulos.php`, `View/ventas_articulos.html.twig`, `Services/ArticuloExcelExportService.php`, `Services/ArticuloExcelImportWizardService.php`, `Controller/VentasCaracteristicas.php` + `View/ventas_caracteristicas.html.twig`.

- [x] 2.1 RED: `plugins/catalogo_core/tests/CaracteristicaResolverTest.php` — CAR-04 empty-vs-absent, CAR-06 (6), CAR-07 (2).
- [x] 2.2 GREEN: `plugins/catalogo_core/Services/CaracteristicaResolver.php` (§4 algorithm: scope walk nearest-first, requested > `DEF` per scope, `valor_defecto` terminal, `familia_chain` cycle-guarded, read-only).
- [x] 2.3 RED: `plugins/catalogo_core/tests/CaracteristicaValorStoreTest.php` — CAR-08 (2).
- [x] 2.4 GREEN: `plugins/catalogo_core/Services/CaracteristicaValorStore.php` (`assign_*`, `clear`, single-row materialization, no fan-out) + `plugins/catalogo_core/Services/CaracteristicaConfig.php` (`READ_THROUGH_FLAG`, `read_through()` default FALSE).
- [x] 2.5 RED: `plugins/catalogo_core/tests/CaracteristicaAssignmentScopeTest.php` — CAR-09 (3).
- [x] 2.6 GREEN: wire the three scopes through the store; keep global as exactly one row, family propagation lazy.
- [x] 2.7 RED: `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php` — CAR-16 (4) + ALC-02 resolver branch.
- [x] 2.8 GREEN: `plugins/catalogo_core/Services/CaracteristicaValorBatchReader.php` (§5, constant query count), `plugins/catalogo_core/extras/VentasArticulosListTrait.php` (`load_caracteristica_columns()`, `listable_caracteristicas()`, `caracteristica_cell()`), `plugins/catalogo_core/Controller/VentasArticulos.php`, `plugins/catalogo_core/View/ventas_articulos.html.twig` (after `Catálogo`, before `Stock`; only within `{% if fsc.tarifa_seleccionada %}`; `colspan` may adjust).
- [x] 2.9 RED: `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php` — CAR-17 (2).
- [x] 2.10 GREEN: `plugins/catalogo_core/Services/ArticuloExcelExportService.php` (`buildSpreadsheet(..., array $exportable = [])`, `EXPORT_HEADERS` unedited) and `plugins/catalogo_core/Services/ArticuloExcelImportWizardService.php` (instance `fieldCatalog(array $importable = [])`, static `suggestMapping($headers, $extraFields = [])`, `fieldOptions()`, `__construct(?array $importable = null)`; feature persistence via the store for the wizard tarifa; `createArticuloFromRow()` base-only).
- [x] 2.11 RED+GREEN: extend `plugins/catalogo_core/tests/Controller/VentasCaracteristicasControllerTest.php` and `plugins/catalogo_core/Controller/VentasCaracteristicas.php` with the three-scope `assign_value`/`clear_value` actions through the store (CAR-18 scenario 4: denied global assignment blocked; CSRF failure persists nothing).
- [x] 2.12 RED+GREEN: `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` (migrated) + `plugins/catalogo_core/Controller/VentasArticulo.php`/host controllers expose `caracteristicas_context()` and pass the `caracteristicas` map (frozen four keys byte-identical). **LANDED IN WU-4 (PR-3 apply):** `extras/CaracteristicaHookContextTrait.php` (new) implements `caracteristicas_context()` as a single batched read (`CaracteristicaValorBatchReader`) memoized per host view; `Controller/VentasArticulo.php` + `Controller/VentasOpcional.php` compose the trait and expose the host seams; all four frozen markers now pass `'caracteristicas': fsc.caracteristicas_context()` after the four frozen keys. Migrated with it: `tests/Integration/CatalogoCoreHookMarkersTest.php` (marker regex + `hostContext()`/stub + new feature-context scenario), `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` (byte-level marker line), and the new `tests/CaracteristicaHookContextTest.php` (4 tests: batched map, memoization, article-less host, authored markers). A host with no article identity resolves an **empty** map and issues zero queries (documented deviation: the D12-derived opcional visibility is exposed by the opcional list/panel surfaces, not by the hook context).
- [x] 2.13 REFACTOR: run plugin suite + root Plugins suite + phpstan.

**Acceptance**: CAR-04 (empty vs absent), CAR-06, CAR-07, CAR-08, CAR-09, CAR-16, CAR-17, CAR-18 (assignment), ALC-02 (resolver branch), catalogo-render-hooks `Hook context contract`.

---

## WU-3 — Backfill, read-through flag, dual-write soak, consumer rewrites

**Objective**: migrate legacy flags into feature values idempotently and switch consumers behind the flag while dual-writing.
**Depends on**: WU-2.
**New files**: `Services/CaracteristicaBackfillMigration.php`.
**Modified**: `Init.php` (backfill call), `extras/VentasArticulosListTrait.php`, `Services/ArticuloExcelExportService.php`, `Services/ArticuloExcelImportWizardService.php`, `Services/ArticuloTarifaPrecioBatchReader.php` (read seam), `Services/CaracteristicaValorStore.php` (`assign_with_dual_write`, `legacy_writer`).

- [x] 3.1 RED: `plugins/catalogo_core/tests/CaracteristicaBackfillTest.php` — CAR-13 (4).
- [x] 3.2 GREEN: `plugins/catalogo_core/Services/CaracteristicaBackfillMigration.php` — (a) `tarif_articulo_precio` → articulo, (b) `tarif_tarifa_articulo` → articulo when price row absent, (c) `tarif_tarifa_familia` → familia, (d) `tarif_familia_ext` **introspection-guarded no-op** (divergence 1), (e) opcional flags recovered through parents/global. Every statement `INSERT … WHERE NOT EXISTS`, operator edits never overwritten, early return when a definition is absent.
- [x] 3.3 GREEN: call `migrateIfNeeded($db)` from `plugins/catalogo_core/Init.php` `init()` and `upgrade()` (try/catch + error_log, `$db` from Container).
- [x] 3.4 RED: `plugins/catalogo_core/tests/CaracteristicaReadThroughTest.php` — CAR-14 (3).
- [x] 3.5 GREEN: flag-off/flag-on branches in list trait, export/import, `tarif_catalogo_view`, `tarif_configurador_opcionales`, `tarif_articulo`, detail tab (§8.4 table) via `CaracteristicaConfig::read_through()`; `assign_with_dual_write` mapping table with introspected column existence and the pinned ALC-02 INSERT defaults; global scope dual-write is a no-op; `coddivisa` never touched. **Scope boundary:** the catalogo_core consumers landed (list trait read-through; import via `assign_with_dual_write`); the tarifario-side branches (`tarif_catalogo_view`, `tarif_configurador_opcionales`, `tarif_articulo`, detail tab) belong to WU-6 and MUST NOT be touched from WU-3. `Services/ArticuloExcelExportService.php` needed no read-through branch: its feature columns are already resolver-driven (CAR-17) and its seven base headers are frozen; the §8.4 "filtered export visibility" row is tarifario's `tarif_catalogo_view` export.
- [x] 3.6 Run the locked ALC-02/export set unedited (`ArticuloListaCanonicaOwnershipTest.php`, `ArticuloExcelExportServiceTest.php`, `ArticuloExcelImportWizardServiceTest.php`) plus plugin/root suites + phpstan.

**Acceptance**: CAR-13, CAR-14, ALC-02 (legacy + resolver parity), `articulos-excel-import-export` (`Persistencia`/`Export Excel` additive behavior), `R-TAR-CUR-008` untouched.

---

## WU-4 — D12 opcional derivation, flag/toggle removal, spec rewrites

**Objective**: remove opcional-owned visibility and derive it from the parent product (existential union), dropping the opcional columns under the parity gate.
**Depends on**: WU-3 (parity evidence MUST exist before this WU starts).
**New/modified files**: `Services/CaracteristicaResolver.php` (D12 methods), `Services/CaracteristicaColumnDropMigration.php` (clause 1), `extras/VentasOpcionalesListTrait.php`, `Controller/VentasOpcionales.php`, `View/ventas_opcionales.html.twig`, `model/tarif_opcional_ext.php` + XML, `model/tarif_tarifa_opcional.php` + XML, `controller/tarif_familias.php`, `controller/tarif_opcional_edit.php`, `controller/tarif_opcional_tab.php`, `controller/tarif_tab_precios.php`, `Controller/VentasArticulo.php`, related views/macros.

- [x] 4.1 RED: `plugins/catalogo_core/tests/OpcionalVisibilityDerivationTest.php` — CAR-12 (4).
- [x] 4.2 GREEN: add `resolve_opcional_visibility()`/`resolve_opcionales_visibility()` to `plugins/catalogo_core/Services/CaracteristicaResolver.php` (parents: `catalogo_articulo_opcional`, group relation `catalogo_articulo_opcional_grupo` when `id_grupo > 0`, `catalogo_opcional_familias`; any-parent union; bounded query count). NOTE: the live family table is `catalogo_opcional_familias` (plural), not the design's `catalogo_opcional_familia`; the real model constant was used.
- [x] 4.3 RED: `plugins/catalogo_core/tests/OpcionalVisibilityParityTest.php` — CAR-12 parity (blocks the drop).
- [x] 4.4 RED: `plugins/catalogo_core/tests/CaracteristicaColumnDropTest.php` — CAR-15 clause 1 + gating.
- [x] 4.5 GREEN: `plugins/catalogo_core/Services/CaracteristicaColumnDropMigration.php` — `dropD12OpcionalColumns()` (idempotent via `hasColumn()`, gated only on the CAR-12 parity test), `dropLegacyArticleFamilyColumns()` stub returning `false`, `hasColumn()`. Clause 2 returns `false` (refuses) while the read-through flag is off; it is intentionally not wired to `Init::init()`.
- [x] 4.6 GREEN: drop `en_catalogo`/`en_tarifa` from `plugins/catalogo_core/model/tarif_opcional_ext.php` + XML and `plugins/catalogo_core/model/tarif_tarifa_opcional.php` + XML (keep `activa`/`orden`; `effective()` keeps working). **LANDED (PR-3 apply, WU-4 part 2).** `tarif_opcional.php` also dropped both properties, their hydration, the legacy column probe entries, `save_extension()`'s flag writes and the `e.en_catalogo, e.en_tarifa` selections. `effective()` now returns `{activa, orden, source}`; `ext_defaults()`, `resolve_en_catalogo()`, `set_en_catalogo()`/`set_en_tarifa()` and the two flag entries in `update_single_field()` are deleted (not aliased). `Services/TarifOpcionalExtMigration` copies only `ref_sap`. `dropD12OpcionalColumns()` stays operator-gated and is NOT wired to `Init::init()`: the class docblock now carries the deploy runbook (pre-drop dump → parity test green → run once → clear Twig cache) and states that schema parity requires running it.
- [x] 4.7 GREEN: consumers — `plugins/catalogo_core/extras/VentasOpcionalesListTrait.php` (derived read-only indicator), `plugins/catalogo_core/Controller/VentasOpcionales.php` (`TOGGLE_ACTIONS = ['toggle_activa']`, delete `toggle_en_catalogo`/`toggle_en_tarifa` and `set_en_catalogo()`/`set_en_tarifa()`), `plugins/catalogo_core/View/ventas_opcionales.html.twig`, `plugins/catalogo_core/controller/tarif_familias.php` (AJAX contract stable; toggles write familia-scope feature values), `plugins/catalogo_core/controller/tarif_opcional_edit.php`, `plugins/catalogo_core/controller/tarif_opcional_tab.php`, `plugins/catalogo_core/controller/tarif_tab_precios.php`, `plugins/catalogo_core/Controller/VentasArticulo.php` + views/macros. **LANDED.** List: `load_opcionales_visibility_cache()` (one batched resolver call per indicator) + `opcional_visibility_resolver()` seam; the two indicators are read-only labels. `tarif_familias`: `ajax_toggle_familia_visibility()` reads the effective familia value and writes through `CaracteristicaValorStore::assign_bool()` (familia scope), JSON shape unchanged, legacy columns untouched. `tarif_opcional_edit`: matrix reads the derived value via `opcional_visibility_resolver()`, both saves dropped `set_en_catalogo`/`set_en_tarifa` and the opcional-level `en_tarifa`/`en_catalogo` aggregation; the scoped panel keeps only the `activa` control plus read-only indicators. `tarif_tab_precios`: visibility controls now `assign_bool(SCOPE_ARTICULO, …)` best-effort and the rows partial renders the resolved `visibilidad` map. `VentasArticulo::syncTarifaArticulo()` persists posted visibility as articulo-scope feature values via a `caracteristica_store()` seam, skipping when the request carries no visibility control. `tarif_opcional_tab` needed no change: its `en_catalogo` is the per-lista `catalogo_opcional_precios` flag that stays (constraint 2). **Known dependency:** the `en_catalogo`/`en_tarifa` definitions are registered by tarifario (WU-6), so on a catalogo_core-only install the family/article visibility writes are best-effort no-ops.
- [x] 4.8 Migrate the 7 named tests (WU-4 list in the regression set) in the same commit, each paired with the change it verifies; do NOT re-assert removed accessors. **LANDED.** Migrated: `tests/TarifTarifaOpcionalTest.php`, `tests/TarifTarifaOpcionalPrecedenceTest.php`, `tests/TarifTarifaOpcionalLifecycleTest.php`, `tests/TarifFamiliasToggleTest.php` (real file name; `TarifFamiliaToggleTest.php` does not exist), `tests/TarifFamiliasFragmentTest.php`, `tests/TarifTabPreciosTest.php`, `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`, `tests/TarifOpcionalesControllerMasterStateTest.php`, `tests/CatalogoOpcionalesUnifiedControllerTest.php`, `tests/Services/TarifOpcionalExtMigrationTest.php`, `tests/OpcionalDomainModelOwnershipTest.php` (delta MODIFIED requirement wins over its "read-only" listing — documented), `tests/Integration/CatalogoCoreHookMarkersTest.php`, `tests/Integration/CatalogoArticuloHookOwnershipTest.php`. Created (they did NOT exist): `tests/TarifOpcionalEditCaracteristicaTest.php` (OTS-02/05/09), `tests/Controller/VentasOpcionalesControllerMasterStateTest.php` (OUM-03/04), `tests/Controller/VentasOpcionalesExportParityTest.php` (OUM-07), plus the verify fix-up `tests/Services/ArticuloExcelFeaturePersistenciaTest.php` ("invalid feature value does not corrupt the base save"). Also created `tests/CaracteristicaHookContextTest.php` (2.12). No removed accessor is re-asserted; each removal now has an explicit absence assertion.
- [x] 4.9 GREEN: catalogo-render-hooks `Article hook tab renders derived feature values` (read-only indicator from the `caracteristicas` context; rendering persists nothing). **LANDED.** `View/Hooks/partials/articulo_precios_rows.html.twig` renders `visibilidad[tarifa.codtarifa].en_tarifa|en_catalogo` and no longer reads `precio.en_tarifa`/`precio.en_catalogo`; the endpoint's `render_rows_fragment()` builds that map through the resolver. `tests/Integration/CatalogoArticuloHookOwnershipTest.php` gained the two ADDED-requirement scenarios (`test_tab_renders_resolver_driven_visibility`, `test_rendering_the_tab_writes_nothing`).
- [x] 4.10 REFACTOR: plugin suite + root Plugins suite + phpstan + grep gate G1.3. **LANDED, all gates re-run:** plugin suite **749 tests / 3091 assertions, 0 failures** (was 715/2921 ⇒ +34 tests, 26 warnings + 1 skipped unchanged); root **Plugins** suite **1370 tests / 4853 assertions, 2 failures** (both pre-existing documented baseline: `TarifTarifasGuardarTest::test_guardar_tarifa_normalizes_lowercase_coddivisa`, `LegacySupportTest::testPluginVersionIsBumpedTo120ForTheBaseJsMove`; the verify baseline's 2 `TarifTarifaOpcionalPrecedenceTest` failures are gone and a root-suite leakage defect was fixed); `ddev exec composer phpstan` → **[OK] No errors**; CAR-19 shell gate → no `plugins/tarifario/` or tarifario-namespace hit; `CaracteristicaBoundariesTest` 5/5 OK (frozen 6-name baseline not grown); no core production file changed; no core `openspec/changes/` entry. Note: `phpstan.neon` paths are `src`+`tests`, so it does not analyse `plugins/` — the plugin suite is the plugin gate.

**Acceptance**: CAR-12 (4 + parity), CAR-15 clause 1, `opcionales-tarifa-management` (6 requirements), `opcionales-management` OUM-03/04/07, `familias-tarifa-management` `Toggle state management`, `articulo-tarifa-tab-management` ATT-02/ATT-04, `articulo-detalle-canonico` ART-01/ART-02, `opcionales-tarifa-selector` OTS-02/05/09, catalogo-render-hooks ADDED.

---

## WU-5 — Clone step on tarifa copy

**Objective**: replicate the three scopes when a tarifa is copied and wire it into `heredar_estructura()`.
**Depends on**: WU-2.
**New/modified files**: `model/core/caracteristica_scope_value.php` (add `copy_from_tarifa()`), `controller/tarif_tarifas.php` (step 12 + `copy_caracteristicas()` + `caracteristica_value_model()` seam).

- [x] 5.1 RED: `plugins/catalogo_core/tests/CaracteristicaCloneTest.php` — CAR-10 (3). **LANDED.** 7 tests / 36 assertions. DB-free via `CaracteristicaCloneFakeDb` (an in-memory `fs_db2` double that understands the two emitted statements) so the three scenarios are behavioural: carried scopes, replace-not-merge, same origin/destination no-op, plus the failure paths (begin/exec/commit) that prove the rollback restores the destination.
- [x] 5.2 GREEN: add `copy_from_tarifa($origen, $destino)` to `plugins/catalogo_core/model/core/caracteristica_scope_value.php` (`$origen === $destino` no-op; transactional DELETE + INSERT…SELECT with `$transaction = false`; `coddivisa` untouched). **LANDED.** Shared `protected const VALUE_COLUMNS = ['id_caracteristica','id_valor','valor','custom']`. The per-scope key columns come from `key_columns()`, so the global scope copies `codtarifa` + the triad and the familia/articulo scopes add their key. No new seam needed: the test subclass sets the inherited `protected $db`.
- [x] 5.3 RED: `plugins/catalogo_core/tests/Controller/TarifTarifasHeredarCaracteristicaTest.php` — step-12 wiring on both call paths. **LANDED.** 5 tests / 17 assertions (behaviour via the seam spy + source contracts for the eleven legacy steps, the seam and the `match`).
- [x] 5.4 GREEN: `plugins/catalogo_core/controller/tarif_tarifas.php` — ordered step 12 after the eleven existing steps + `copy_caracteristicas()` + overridable `caracteristica_value_model(string $scope)` seam. **LANDED.** Both call paths (`create-with-inherit` and `copy_structure`) inherit the clone because it lives inside `heredar_estructura()`.
- [x] 5.5 REFACTOR: plugin suite + phpstan. **LANDED.** catalogo_core **761 tests / 3172 assertions, 0 failures** (was 749/3091 ⇒ +12 tests); `ddev exec composer phpstan` → `[OK] No errors`; root **Plugins** suite unchanged (only the 2 documented pre-existing failures).

**Acceptance**: CAR-10 (3 scenarios) + step-12 wiring.

---

## WU-6 — tarifario defaults and consumer rewrites

**Objective**: register tarifario's two bool features boot-order-safely and switch its consumers to feature values.
**Depends on**: WU-2.
**New/modified files**: `plugins/tarifario/Init.php`, `plugins/tarifario/Services/ExcelImportWizardService.php`, `plugins/tarifario/Services/ExcelHierarchyService.php`, `plugins/tarifario/Services/ExcelRowUpdater.php`, `plugins/tarifario/Services/ArticuloListActionHandler.php`, `plugins/tarifario/process_excel_wizard.php`, `plugins/tarifario/model/tarif_articulo.php`, `plugins/tarifario/controller/tarif_catalogo_view.php`, `plugins/tarifario/controller/tarif_configurador_opcionales.php` + views. (`ExcelRowUpdater.php` and `ArticuloListActionHandler.php` were missing from the original list — reconciled from the WU-6 apply record, 2026-09-15.)

- [x] 6.1 RED: `plugins/tarifario/tests/CaracteristicaDefaultsRegistrationTest.php` — CAR-11 boot order. **LANDED (new file; it did NOT exist).** 6 tests / 28 assertions: registration payload (design §9.3 pins), backfill re-attempt, static-guard idempotency, missing-host fatal-free + retried later, no-DB boot, and the `upgrade()` static entry point.
- [x] 6.2 GREEN: `plugins/tarifario/Init.php` — `registerCaracteristicaDefaults()` with `class_exists` + static guard, called from `init()` and `upgrade()`, re-attempting the backfill with `Container::db()`. **LANDED.** The registry/migration classes resolve through overridable `caracteristica_registry_class()` / `caracteristica_migration_class()` seams and the boot DB through `caracteristica_db()` (→ `plugin_db()`), because the literal design body is untestable DB-free (same class of deviation as the WU-1 registry seam). `upgrade()` is a new **static** method — `PluginSchemaSynchronizer::runInitMigrations()` calls `is_callable([$initClass,'upgrade'])`, so this is the framework's plugin-upgrade hook. The backfill is skipped when the DB seam is null (catalogo_core precedent), never fatal.
- [x] 6.3 RED: migrate `plugins/tarifario/tests/Services/ExcelImportWizardFieldCatalogTest.php` — FIELD_CATALOG keeps literal `en_tarifa`/`en_catalogo` keys as feature-backed (`'column' => null, 'feature' => ...`). **LANDED (new file; it did NOT exist).** 6 tests / 90 assertions.
- [x] 6.4 RED: migrate `plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php` — create path + canary (`R6`, create-path canary) asserts article-scope feature values `en_tarifa=true`/`en_catalogo=true` for `$codtarifa` and `en_sap=false` on the price row. **LANDED.** `testCreateArticuloFromRowDefaultsEnTarifaAndEnCatalogoAsFeatureValues`, `testCreatePathFeatureValuesHonourMappedAndEmptyColumns` and the canary `testCanaryCreatePathWritesFeatureDefaults` (recording store). The pre-existing environment-dependent failure `testCreateArticuloFromRowFallsBackToGetNewReferencia` was fixed by adding an optional `?\fs_db2 $db` to `createArticuloFromRow()` and injecting the mock (it read the live catalog before). Also migrated `plugins/tarifario/tests/Model/TarifArticuloFactoryForImportTest.php` (the delta MODIFIED requirement wins over its "read-only" listing — same class as DEV-8).
- [x] 6.5 GREEN: `plugins/tarifario/Services/ExcelImportWizardService.php`, `plugins/tarifario/Services/ExcelHierarchyService.php`, `plugins/tarifario/process_excel_wizard.php`, `plugins/tarifario/model/tarif_articulo.php` — persist/read through the store and resolver; `en_sap` untouched; unmapped/empty feature column is a no-op. **LANDED.** `factory_for_import()` now produces `factory_caracteristicas` (`['en_tarifa'=>bool,'en_catalogo'=>bool]`, A4 defaults true/true, mapped value wins) and no longer writes the price-row visibility columns; `ExcelImportWizardService::persistFeatureValues()` writes them through `CaracteristicaValorStore::assign_with_dual_write()` AFTER the article exists (the scope table cascades from `articulos`); `process_excel_wizard.php` calls it on the create path; `ExcelRowUpdater::applyEnTarifa/applyEnCatalogo` (wizard update path + legacy import path) write article-scope feature values through the store, comparing via `resolve_bool` and keeping the legacy column consistent; `ExcelHierarchyService::createFamiliaRow()` writes the two familia-scope values (best-effort, `setCaracteristicaStore()` seam). `en_sap` untouched everywhere.
- [x] 6.6 RED: migrate `plugins/tarifario/tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` — `R-TAR-HOOK-011` export set from derived visibility. **LANDED.** 8 tests / 30 assertions; the export now takes `en_catalogo`/`en_tarifa` from `opcional_visibilidad()` (the resolver's D12 union) and the test asserts the state's removed flag keys are NOT read. A NULL derivation keeps the pre-change inherited defaults (`en_catalogo` TRUE / `en_tarifa` FALSE) so the exported set is preserved.
- [x] 6.7 GREEN: `plugins/tarifario/controller/tarif_catalogo_view.php`, `plugins/tarifario/controller/tarif_configurador_opcionales.php` + views — read through `CaracteristicaConfig::read_through()`. **LANDED.** New seams `caracteristica_read_through()` / `caracteristica_resolver()` / `caracteristica_store()` and `visibilidad_efectiva(referencia, codfamilia, codigo, legacy)` (flag off ⇒ the legacy column byte-identical; flag on ⇒ the resolver value, NULL ⇒ FALSE). Wired into `htmx_articulos`, `htmx_articulos_agrupados` and `build_export_data` (family + article rows); the visibility toggles persist through the store (dual-write) so the page keeps working while the soak flag is off; the dead opcional-owned visibility writes were removed from the catalog view, the configurator and `ArticuloListActionHandler`.
- [x] 6.8 REFACTOR: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` + root Plugins suite + phpstan. **LANDED.** tarifario **229 tests / 929 assertions, 0 failures** (was 190/685 with 1 pre-existing failure ⇒ +39 tests and the failure fixed); root **Plugins** suite **1419 / 5166, 2 failures** (both documented pre-existing: `TarifTarifasGuardarTest::test_guardar_tarifa_normalizes_lowercase_coddivisa`, `LegacySupportTest::testPluginVersionIsBumpedTo120ForTheBaseJsMove`); `ddev exec composer phpstan` → `[OK] No errors`; `ddev exec php tmp/verify_wu6_caracteristicas.php` → **19/19 live-DB checks PASS** (registration idempotency, store write + dual-write + resolver read-back, D12 parent-derived visibility, clone, and `assign_with_dual_write` returning TRUE — see the runtime evidence note below).

**Acceptance**: CAR-11 (boot order), tarifario `catalogo-excel-import-export` (4 requirements), tarifario `catalogo-integration` R-TAR-HOOK-002 + ADDED R-TAR-HOOK-011.

### WU-6 apply findings (carry forward to verify)

1. **DEFECT FIXED — the `tarif_tarifa_articulo` dual-write was failing against the live schema.** `CaracteristicaValorStore::dual_write_articulo()` inserted a `madre` column that `tarif_tarifa_articulo` does NOT have (live columns: `codtarifa, referencia, codfamilia, en_tarifa, en_catalogo, orden`; `madre` lives on `tarif_tarifa_familia`). Design §8.4 lists `madre` for that insert — the live schema diverges (same class as divergence 1). Because `exec` with the default `$transaction` auto-commits, the feature value WAS persisted while the store returned **FALSE** (`assign_with_dual_write`'s `&&` with `legacy_writer`). Every WU-6 caller that checks the return value (the catalog toggles, `applyEnTarifa`/`applyEnCatalogo`, `persistFeatureValues`) would have mis-reported failure. Fixed by dropping `madre` from the article insert; `CaracteristicaReadThroughTest` gained the regression assertions (`madre` absent, `orden` present).
2. **`tarif_familia_ext` divergence (already pinned) plus a NEW one**: `catalogo_opcionales` still carries unused `en_catalogo`/`en_tarifa` columns, but the model no longer declares the properties, so the removed assignments were creating PHP 8.3 dynamic properties. They were dead code; removed, not aliased.
3. **Design §8.4's "configurator reads switch on the flag" row is stale for `tarif_configurador_opcionales`.** After WU-4 removed the opcional-owned flags, the configurator has NO visibility surface left to read (its `visible`/`seleccionable` state is association-based, a different concept). The WU-6 change there is the removal of the two dead opcional-owned writes; the D12 derived visibility of opcionales is rendered by the opcional list/panel (WU-4) and exported by `build_opcionales_export` (this WU). Recorded as a documented deviation, not silently ignored.
4. **Delta R8 lists a `grupo` legacy field that `FIELD_CATALOG` deliberately does not expose** (`process_excel_wizard.php` pins the group column as a follow-up SDD: a mappable-but-ignored field would be worse than its absence). `ExcelImportWizardFieldCatalogTest` pins the fields that ARE mappable and documents the divergence in its docblock.
5. **`persistFeatureValues` MUST run after the article save** (the scope table cascades from `articulos`), so the raw `createArticuloFromRow` cannot persist by itself. The canary drives both halves (`createArticuloFromRow` + `persistFeatureValues`) in one scenario, mirroring the rowHook's order.
6. **Runtime harness caveat**: `CaracteristicaValorStore` writes use `fs_db2::exec($sql)` with the default `$transaction`, which **auto-commits** — a wrapping `begin_transaction`/`rollback` does NOT isolate them. The harness works only on synthetic tarifas/opcionales and deletes them in a `finally` (plus an idempotent pre-clean). (An earlier harness version relied on rollback and leaked two `catalogo_caracteristica_articulo` rows + a `tarif_articulo_precios` row + a `tarif_tarifa_articulo` row for `DEF`/`0011`; all were removed and the pre-harness counts restored — final state: 0 feature rows, 0 price rows, 0 tarifa-articulo rows, 1 tarifa.)

---

## WU-7 — Post-soak gated column drop (destructive)

**Objective**: drop the legacy article/family visibility columns only after the read-through flag is verified stable, reversibly.
**Depends on**: WU-4 soak + stable read-through flag (owner decision to start).
**Modified files**: `Services/CaracteristicaColumnDropMigration.php` (clause 2), gated entry point.

> **RECONCILED 2026-09-15 (verify WARNING 3).** The previous text described 7.2 as a
> stub to implement ("stub returning `false`" per 4.5). That was stale: `dropLegacyArticleFamilyColumns()`
> was already implemented in WU-4 and is unit-covered by `CaracteristicaColumnDropTest`
> (refusal while the flag is off; drop + idempotency once enabled; `tarif_familia_ext`
> introspection-guarded no-op). WU-7's real remaining scope is **the reversibility test,
> the closed-loop entry point, the soak, and the actual gated drop** — nothing of clause 2
> itself has to be re-implemented.

- [x] 7.1 RED: `CaracteristicaColumnDropTest` clause 2 — refusal while `read_through()` is FALSE (no schema change), drop of `tarif_articulo_precios` / `tarif_tarifa_articulo` / `tarif_tarifa_familia` once it is TRUE, `tarif_familia_ext` no-op, idempotent second run. **LANDED in WU-4 (PR-3), re-confirmed by verify.**
- [x] 7.1b RED: add `plugins/catalogo_core/tests/CaracteristicaReversibilityTest.php` — the CAR-15 reversibility scenario (pre-drop dump identity → re-add the columns as nullable → re-derive every previously stored legacy value from the feature tables/resolver). **LANDED (2026-09-15 pre-soak apply).** 10 tests / 126 assertions. The proof is the closed loop per clause: the real `CaracteristicaResolver` walks an in-memory feature state and every legacy value is compared against a pre-drop snapshot, after the drop and after the nullable restore. Two coupled assertions make it non-vacuous: the restored columns come back **NULL** (`restoredValues`), so the re-derived values can only come from the feature tables; and the pre-drop column set is restored exactly. Covers `dropD12OpcionalColumns()`, `dropLegacyArticleFamilyColumns()` and `dropDeadOpcionalColumns()`, plus the retired `tarif_familia_ext` no-op and constraint 2 (`catalogo_opcional_precios.en_catalogo` survives every clause). DB-free (the `\fs_db2` double skips `parent::__construct()`; nothing needs cleaning up because nothing is written). RED first: the two entry-point-coupled tests errored on the missing `pendingVisibilityColumns()` and one assertion wrongly assumed clause 1 was soak-gated — see the WU-7 pre-soak findings below.
- [x] 7.2 GREEN: `dropLegacyArticleFamilyColumns()` implemented — refuses unless `CaracteristicaConfig::read_through()` is TRUE; `hasColumn()` before each `ALTER`; `tarif_familia_ext` introspection-guarded no-op; idempotent second run; **NOT wired to `Init::init()`**. **LANDED in WU-4.**
- [x] 7.3 GREEN: runbook + gated entry point. Runbook documented in the service docblock (clause 1; clause 2 + dead-column cleanup with the pre-drop dump, the DEV-17 membership-filter prerequisite and the Twig-cache step). **LANDED (2026-09-15 pre-soak apply): the closed-loop entry point now exists.**
  `CaracteristicaColumnDropMigration::runPostSoak(\fs_db2 $db, bool $dev17MembershipFiltersRewritten): array` runs the declared order (`POST_SOAK_STEPS` = clause 1 → clause 2 → dead-column cleanup) and reports the live schema (`status`, `read_through`, `dev17_membership_filters_rewritten`, `reason`, `steps`, `dropped`, `remaining`) from `pendingVisibilityColumns()`. It refuses **atomically** (no step runs, no DDL) while the read-through gate is OFF, and again while the DEV-17 rewrite is unattested — `$dev17MembershipFiltersRewritten` has **no permissive default**. Operator runner: `plugins/catalogo_core/tools/run_caracteristica_column_drop.php` (dry-run by default; `--apply --dev17-rewritten` for the loop). Never boot-wired: enforced by `test_the_entry_point_is_never_wired_into_a_boot_or_request_path()`.
- [~] 7.4 Confirm reversibility evidence and update `verify-report.md` before opening PR-5. **Reversibility evidence landed** (7.1b — `CaracteristicaReversibilityTest` 10/10) and the apply-progress record carries the exact commands/results. The `verify-report.md` update stays with the verify phase: the change is still incomplete (7.5/7.6 open), so a new envelope would be premature.
- [ ] 7.5 Run the soak: enable `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` in a staging DB, compare flag-off/flag-on output, and watch `visibilidad_sin_sincronizar` during a JSON import. **Prerequisite now closed:** DEV-18 (JSON import mirror, WU-8.1) and DEV-17 (membership-filter ratification, WU-8.2).
- [ ] 7.6 Run the gated drop on a pre-drop dump: `runPostSoak($db, true)` once the readiness gates hold (or `dropLegacyArticleFamilyColumns($db)` **then** `dropDeadOpcionalColumns($db)`) — but only **after** the DEV-17 membership filters are rewritten to the feature tables (the ordered prerequisite recorded in the service docblock and enforced as the required attestation). Never executes at boot.

**Acceptance**: CAR-15 clause 2 + reversibility scenario + the closed-loop entry point.
**Rollback boundary**: WU-7 is destructive by design; the rollback is a pre-drop dump plus the re-add-nullable/re-derive path (7.1b). Nothing else in the change depends on WU-7.

### WU-7 open-item inventory (explicit)

| Item | State | Evidence |
|---|---|---|
| Clause 2 code | DONE (WU-4) | `CaracteristicaColumnDropMigration::dropLegacyArticleFamilyColumns()`, `CaracteristicaColumnDropTest` **17/17** (+6 from the pre-WU-8 baseline of 11 — verify WARNING 3 / SUGGESTION 2 arithmetic corrected here) |
| Dead `catalogo_opcionales` columns | DONE (WU-8.4) | `dropDeadOpcionalColumns()`, `CaracteristicaColumnDropTest` (5 of the +6 tests) |
| Reversibility test | DONE (7.1b) | `tests/CaracteristicaReversibilityTest.php` 10/10, 126 assertions |
| Closed-loop entry point | DONE (7.3) | `runPostSoak()` + `POST_SOAK_STEPS` + `pendingVisibilityColumns()`; 8 new entry-point tests in `CaracteristicaColumnDropTest` (**25/25** total); runner `tools/run_caracteristica_column_drop.php` |
| Soak | OPEN | flag has never been enabled (runner dry-run confirms: `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` OFF) |
| Gated drop | OPEN | operator step, never executed (live introspection: 12 gated columns still present) |
| DEV-18 (soak blocker) | DONE (WU-8.1) | `TarifCatalogoJsonImportVisibilityTest` 8/8 |
| DEV-17 (soak blocker) | DONE (WU-8.2) | `TarifCatalogoMembershipFilterRatificationTest` 5/5; the closure **rewrite** remains part of 7.6's ordered prerequisite |

### WU-7 pre-soak apply findings (2026-09-15 — carry forward to verify)

1. **The CAR-15 reversibility scenario's `Test:` pointer was wrong and is now fixed.** `specs/catalogo-core/caracteristicas-producto/spec.md` line 535 pointed at `CaracteristicaColumnDropTest.php`; it now points at `CaracteristicaReversibilityTest.php`. The scenario count is unchanged (48 requirements / 153 scenarios): the pointer was corrected, no scenario was added.
2. **Clause 1 is parity-gated, NOT soak-gated — confirmed by a RED assertion that had to be corrected.** The first version of `test_a_closed_gate_changes_no_schema_...` asserted `dropD12OpcionalColumns()` returned FALSE with `read_through()` off; the drop service returned TRUE. That is correct per CAR-15 ("D12 drop is gated on parity, not on soak"), so the test now asserts the real contract: with the soak gate closed, clause 1 still runs and only clause 2 + the dead-column cleanup refuse.
3. **The DEV-17 guard is an operator attestation, not a machine check — recorded, not hidden.** The entry point requires `$dev17MembershipFiltersRewritten` with no default. It cannot be machine-verified from catalogo_core: the membership-filter surface is tarifario-owned (`tarif_catalogo_view.php`, which CAR-19 forbids referencing) and catalogo_core legitimately still reads the legacy columns during the soak (CAR-13 backfill, CAR-14 dual-write, the models), so a source scan could not tell a membership filter apart from a soak-window read. The ordering is frozen instead by `TarifCatalogoMembershipFilterRatificationTest` (tarifario) plus the required attestation.
4. **The dead-column clause's reversal is shape-level, and that is the honest contract.** `catalogo_opcionales.en_catalogo` / `.en_tarifa` were never a derivation input (no model declares them, nothing reads or writes them), so nothing can be re-derived *from* them. The test asserts what is true: the opcional visibility derived from the feature tables is unchanged by the drop, the columns come back as nullable empties, and `catalogo_opcional_precios.en_catalogo` is untouched.
5. **The runner is dry-run by default.** `--apply` alone refuses (exit 1) without `--dev17-rewritten`, and the service independently refuses while the flag is OFF — two independent refusals before any DDL. Verified against the live DB: the dry run reported 12 gated columns and emitted no DDL; the `--apply` refusal also emitted no DDL.
6. **Test arithmetic (re-derived from executed runs).** `CaracteristicaColumnDropTest` 17 → **25** (+8 entry-point tests); the new `CaracteristicaReversibilityTest` **10**; plugin suite 768 → **786** (+18 = +8 +10), assertions 3196 → **3405** (+209). The pre-WU-8 `CaracteristicaColumnDropTest` delta is **+6** (11 → 17), not +5 — see verify WARNING 3, corrected in the inventory table above.

---

## WU-8 — Close the soak blockers (verify WARNING 2/3/5 + SUGGESTION 2/4)

**Objective**: make the read-through soak startable and remove the stale-artifact drift that
blocked WU-7.
**Depends on**: WU-6.
**Modified files**: `plugins/tarifario/controller/tarif_catalogo_view.php` (JSON import mirror +
DEV-17 ratification consts), `plugins/catalogo_core/Services/CaracteristicaColumnDropMigration.php`
(dead-column clause + clause-2 runbook), `plugins/catalogo_core/Services/ArticuloExcelExportService.php`
(export `orden` contract), the delta specs, `design.md`, this file.

- [x] 8.1 RED+GREEN (DEV-18): the JSON import dual-writes feature visibility. `visibilidad_importada($row)` derives the row visibility once; `sincronizar_visibilidad_import()` materializes it through `CaracteristicaValorStore::assign_with_dual_write()` after the legacy `save()`; a failed mirror is counted in `$stats['visibilidad_sin_sincronizar']` (additive stat key, never a row error). The legacy writes stay (soak-window path). **Evidence**: `plugins/tarifario/tests/Controller/TarifCatalogoJsonImportVisibilityTest.php` — 8 tests / 33 assertions, RED first (5 errors + 3 failures before the change). Closed loop: the real `assign_with_dual_write()` write is read back through the real `CaracteristicaResolver` scope walk, so an imported *visible* row resolves TRUE (not NULL) and an imported *hidden* row resolves FALSE (explicitly not NULL).
- [x] 8.2 RED+GREEN (DEV-17): the SQL membership filters are **ratified as legacy-by-design** for the soak, with the closure plan recorded. Chosen option (b) over (a): see "DEV-17 decision" below. `MEMBERSHIP_FILTERS_LEGACY_BY_DESIGN` (11 filter literals) + `MEMBERSHIP_HELPER_CALL_SITES_LEGACY_BY_DESIGN` (5 call sites) freeze the surface; `TarifCatalogoMembershipFilterRatificationTest` (5 tests) is the gate. **Evidence**: RED first (consts absent).
- [x] 8.3 (DEV-16 + doc drift): `design.md` §8.4 rows for `tarif_configurador_opcionales` **and** `tarif_articulo` (both have no visibility read after WU-4) + the two prose references (§7 consumer list, §12.3) + the WU-6 file list (`Services/ExcelRowUpdater.php`, `Services/ArticuloListActionHandler.php`); delta spec test pointers fixed (`opcionales-tarifa-management` → `catalogo_core/tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php`, `articulos-excel-import-export` → `ArticuloExcelFeaturePersistenciaTest`); `R-TAR-HOOK-011` restated with its explicit surface and testable scenarios.
- [x] 8.4 RED+GREEN (SUGGESTION 2): the dead `catalogo_opcionales.en_catalogo` / `.en_tarifa` columns get a dedicated gated post-soak step, `CaracteristicaColumnDropMigration::dropDeadOpcionalColumns()`, gated on the same read-through switch, idempotent, never boot-wired. `catalogo_opcional_precios.en_catalogo` stays (constraint 3). **Evidence**: `CaracteristicaColumnDropTest` +6 tests for the WU-8 batch (refusal, drop, per-lista untouched, idempotency, out of `LEGACY_TABLES`, plus the source contract) — recorded as "+5" before verify re-derived the arithmetic (**11 → 17 = +6**, verify WARNING 3 / SUGGESTION 2), RED first.
- [x] 8.5 RED+GREEN (SUGGESTION 4): the export layer owns the `orden` contract. `ArticuloExcelExportService::ordered_exportable()` sorts `exportable` by `orden` then `codigo` (stable), so a second-then-first definition list still emits first-then-second columns. The scenario already existed and was already precise — `articulos-excel-import-export` "Exportable feature columns append" ("GIVEN two `exportable` definitions ordered second-then-first … THEN exactly two columns are appended … in the definitions' `orden` order") — so no restatement was needed; CAR-17 now merely points at it as the owner. **Evidence**: `ArticuloExcelCaracteristicaTest::test_export_orders_feature_columns_by_orden`, RED first.

**Acceptance**: DEV-18 closed, DEV-17 ratified + gated, DEV-16/doc drift closed, SUGGESTION 2 and 4 implemented. Both plugin suites green.
**Focused test commands**: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter 'TarifCatalogoJsonImportVisibilityTest|TarifCatalogoMembershipFilterRatificationTest'` and `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'ArticuloExcelCaracteristicaTest|CaracteristicaColumnDropTest'`.
**Runtime harness**: explicit N/A for the JSON import mirror — the batch methods need the live DB and `fs_db2::exec()` auto-commits, so the DB-free closed loop (real store write → real resolver read) is the executable evidence; no live write is performed in this run.
**Rollback boundary**: `tarif_catalogo_view.php` (the two new helpers + the two batch call sites) reverts alone; `CaracteristicaColumnDropMigration::dropDeadOpcionalColumns()` is additive and callable-nowhere; `ordered_exportable()` reverts alone; the doc/spec edits revert alone.

### DEV-17 decision (option (b), justified against DEV-18)

**Decision**: ratify the SQL membership filters as legacy-by-design during the soak instead of
rewriting them to the resolver now (option **(b)**).

**Why (b) beats (a) here**:
1. **DEV-18 was the actual defect.** The membership filters were only wrong because one write
   path (the JSON import) left the legacy columns and the feature values divergent. With the
   DEV-18 mirror closed, every write path keeps both sides equal (CAR-13 backfill + CAR-14
   dual-write + the import mirror), so the legacy filter is *equivalent* to the resolver for
   every row — the framing problem, not the query, was the finding.
2. **(a) would rewrite frozen SQL before the soak for no semantic gain.** The filters are
   SQL-level pre-filters on columns that are still the flag-OFF source of truth; moving them
   to per-row resolver calls changes the query shape and cost of the catalog during the exact
   window the soak is supposed to prove parity without behavior change.
3. **(a) has an ordering hazard the ratification makes explicit.** The filters *must* stop
   reading the legacy columns before CAR-15 clause 2 drops them, otherwise the drop leaves
   invalid SQL. That dependency is now recorded in the service docblock, in `design.md` §8.4
   and in WU-7 (7.6) instead of being implicit.
4. **The ratification is not a blank cheque**: the inventory is frozen (11 filter literals, 5
   call sites) and gated by `TarifCatalogoMembershipFilterRatificationTest`, so a new legacy
   membership read fails the suite.

---

## Archive handoff note

Archive MUST be handed the explicit paths `plugins/catalogo_core/openspec/` and
`plugins/tarifario/openspec/` because `gentle-ai sdd-status` only sees the repository-root
`openspec/`. Merge `specs/catalogo-core/**` → `plugins/catalogo_core/openspec/specs/`
(ADD `caracteristicas-producto`; MODIFIED the 9 listed) and `specs/tarifario/**` →
`plugins/tarifario/openspec/specs/` (MODIFIED 2), only after a clean `verify-report.md`.
Apply the archive-time Purpose rewrite for `opcionales-tarifa-management` (spec-phase
decision 11), run the dead-column grep gate before/after the merge, and confirm NO entry
exists in the repository-root `openspec/`.

## skill_resolution

- **`fsframework-plugin-sdd`** — LOADED. Routing confirmed plugin-local; core `openspec/` untouched; archive handoff path noted.
- **`work-unit-commits`** — LOADED. Tasks grouped as 7 reviewable work units; tests land with the behavior they verify; each WU states rollback boundary and focused test command.
- **`chained-pr`** — LOADED. `400-line budget risk: High` → chained PRs recommended; `single-pr` declared infeasible pending a maintainer `size:exception` or a chain-strategy decision.
- **`sdd-tasks`** — LOADED. Review Workload Forecast + guard lines + work-unit evidence present. NOTE: the skill's 530-word artifact budget is exceeded by the requested 7-section deliverable (7 WUs × paired tests + regression set + divergences + gates); completeness was prioritized and the overage is surfaced as a risk.
