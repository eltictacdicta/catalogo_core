# Tasks: Opcionales organized by tarifa

Runners: `R(C)` = `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`; `R(T)` = same with `plugins/tarifario/phpunit.xml`. Strict TDD: RED before GREEN.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1,600–2,400 (tests dominate) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 → PR 4 → PR 5 |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

### Suggested Work Units

| Unit | Goal | PR | Focused test | Runtime harness | Rollback boundary |
|------|------|----|--------------|-----------------|-------------------|
| 1 | Master schema + `effective()`/`resolve_*` + CRUD | 1 | `R(C) --filter TarifTarifaOpcionalTest` | Standalone catalogo_core bootstrap on scratch DB | `model/tarif_tarifa_opcional.php`, `model/table/tarif_tarifa_opcional.xml`, its test |
| 2 | Seed/inherit/copy/toggles | 2 | `R(C) --filter TarifTarifaOpcional(Lifecycle\|Precedence)Test` | Fresh install seed; tarifa copy | lifecycle methods + 2 tests |
| 3 | Bootstrap step + `solo_activos` repoint | 3 | `R(C) --filter (InitTarifTarifaOpcionalBootstrap\|TarifTarifaOpcionalPrecedence)Test` | Activate catalogo_core, tarifario inactive | `Init.php`, `model/tarif_opcional.php`, bootstrap test |
| 4 | Controllers + Twig matrices | 4 | `R(C) --filter TarifOpcionalesControllerMasterStateTest` | Render list/edit/precios matrices | 3 controllers + 3 views + its test |
| 5 | Hybrid export + heredar copy | 5 | `R(T) --filter (TarifCatalogoOpcionalMasterExport\|TarifTarifasHeredarOpcionalMaster)Test` | Export tarifa differing from ext flags | `tarif_catalogo_view.php`, `tarif_tarifas.php`, 2 tests |

## Phase 1: Foundation

- [x] 1.1 RED: create `plugins/catalogo_core/tests/TarifTarifaOpcionalTest.php` (CRUD, `effective()`, `resolve_*`).
- [x] 1.2 Create `plugins/catalogo_core/model/table/tarif_tarifa_opcional.xml`: PK `(codtarifa,id_opcional)`; FK CASCADE to `tarif_tarifas`/`catalogo_opcionales`; defaults TRUE/FALSE/TRUE/0.
- [x] 1.3 Create `plugins/catalogo_core/model/tarif_tarifa_opcional.php`: props, `get`/`exists`/`save`/`delete`, `all_from_tarifa`/`count_from_tarifa`, `effective()`, `resolve_*`, `ext_defaults()` seam.
- [x] 1.4 GREEN: Unit 1 command. Self-contained PR 1.

## Phase 2: Lifecycle

- [x] 2.1 RED: create `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php` (master wins; missing inherits; price never activates; `orden` override).
- [x] 2.2 RED: create `plugins/catalogo_core/tests/TarifTarifaOpcionalLifecycleTest.php` (seed SQL, `copy_from_tarifa`, toggle create/update).
- [x] 2.3 Implement `install()` seed (`catalogo_opcionales LEFT JOIN tarif_opcional_ext`), `copy_from_tarifa`, `set_activa`/`set_en_catalogo`/`set_en_tarifa`/`set_orden`.
- [x] 2.4 GREEN: Unit 2 command. Self-contained PR 2.

## Phase 3: Bootstrap and repoint

- [x] 3.1 RED: create `plugins/catalogo_core/tests/InitTarifTarifaOpcionalBootstrapTest.php` (master ensured after the 5; idempotent; class-safe).
- [x] 3.2 Modify `plugins/catalogo_core/Init.php`: dedicated FK-safe ensure step after the 5-table loop in `ensureOpcionalesTarifaTables()`. Do NOT edit the pinned `FK_SAFE_SEQUENCE` literal in `plugins/catalogo_core/tests/InitOpcionalesTablesTest.php` (read-only).
- [x] 3.3 RED: add `search`/`count_filtered` `solo_activos` assertion to `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php`.
- [x] 3.4 Modify `plugins/catalogo_core/model/tarif_opcional.php`: `solo_activos` → master `activa`; signatures unchanged.
- [x] 3.5 GREEN: Unit 3 command. Self-contained PR 3.

## Phase 4: UI integration

- [x] 4.1 RED: create `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php`.
- [x] 4.2 Modify `plugins/catalogo_core/controller/tarif_opcionales.php`: master cache; per-tarifa `activa`; `toggle_*`.
- [x] 4.3 Modify `plugins/catalogo_core/controller/tarif_opcional_edit.php`: master read/write in matrix.
- [x] 4.4 Modify `plugins/catalogo_core/controller/tarif_opcional_precios.php`: `esta_activo_en_tarifa()`/`esta_en_catalogo()` read master; save master.
- [x] 4.5 Modify `plugins/catalogo_core/View/tarif_opcionales.html.twig` ("Estado" per tarifa), `plugins/catalogo_core/View/tarif_opcional_edit.html.twig`, `plugins/catalogo_core/View/tarif_opcional_precios.html.twig`; reuse `toggle_button_group`.
- [x] 4.6 GREEN: Unit 4 command, then `R(C) --filter TarifOpcionalesControllerContractTest`. Self-contained PR 4.

## Phase 5: Hybrid tarifario

- [x] 5.1 RED: create `plugins/tarifario/tests/Controller/TarifCatalogoOpcionalMasterExportTest.php`.
- [x] 5.2 RED: create `plugins/tarifario/tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php`.
- [x] 5.3 Modify `plugins/tarifario/controller/tarif_catalogo_view.php`: export reads `effective()`; skip `activa === false`.
- [x] 5.4 Modify `plugins/tarifario/controller/tarif_tarifas.php`: step 11 master copy in `heredar_estructura()`.
- [x] 5.5 GREEN: Unit 5 command. Self-contained PR 5.

## Phase 6: Verification

- [x] 6.1 Run `R(C)`; keep `plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php` (read-only), `plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php` (read-only), `plugins/catalogo_core/tests/InitOpcionalesTablesTest.php` (read-only), `plugins/catalogo_core/tests/TarifTarifaOpcionalFamiliaTest.php` (read-only) green. → initial run `Tests: 414, Assertions: 1270, Warnings: 25, Skipped: 1` exit 0; intermediate `442 / 1335`; **final re-verified result `Tests: 446, Assertions: 1350, Warnings: 25, Skipped: 1`** (see `verify-report.md`).
- [x] 6.2 Run `R(T)`. → final re-verified `Tests: 198, Assertions: 759, Skipped: 3` exit 0 (intermediate `197 / 755`).
- [x] 6.3 Run `ddev exec composer phpstan`. → `[OK] No errors`, 188/188, exit 0.
- [x] 6.4 Audit: `catalogo_opcionales` XML/API untouched; `tpvmod` untouched; additive, no backfill. If any Composer dep is added, commit `plugins/catalogo_core/vendor/` with `composer.json`/`composer.lock`. → audit clean; no Composer dep added.

## Acceptance Notes

- `activa` master is the sole activation source; price-row presence never activates.
- Master `en_catalogo` wins for catalog/export; `tarif_opcional_ext` flags are fallback only.
- Additive/lazy: missing rows inherit and never persist until an explicit toggle.

## Dependency and Follow-up Notes

- Active unarchived `absorber-opcionales-tarifa-en-catalogo-core` shares this capability; archive it first (or clean-merge) before archiving this change.
- ~~Deferred follow-up: `copy_precios_opcionales()` drops `en_catalogo`/`porcentaje` on tarifa copy~~ — **resolved** by the residuals remediation (item D): the copy now preserves `precio`, `porcentaje` and `en_catalogo`.
