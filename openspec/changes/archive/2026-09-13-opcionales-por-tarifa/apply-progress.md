# Apply Progress: opcionales-por-tarifa

## Delivery Context

- Change: `opcionales-por-tarifa` (plugin-local SDD, `catalogo_core`).
- Artifact store: `openspec` (file-based).
- Strict TDD: `true` (`plugins/catalogo_core/openspec/config.yaml`).
- Delivery strategy: `ask-on-risk`; chain strategy `pending`.
- Assigned slice: **Unit 1 / PR 1 — Master schema + `effective()`/`resolve_*` + CRUD**.
  The orchestrator resolved the chained PR boundary by assigning only Unit 1 for this batch.

## Work Unit: Unit 1 / PR 1

Self-contained slice: the per-`(tarifa, opcional)` master table/model plus its
contract tests. No seed, toggles, `copy_from_tarifa`, `Init.php`, controller,
view or hybrid `tarifario` work is included (Units 2–5).

### TDD Cycle Evidence

| Task | RED | GREEN | REFACTOR |
|------|-----|-------|----------|
| 1.1 test `tests/TarifTarifaOpcionalTest.php` | Test written first; run before implementation errored on missing `model/tarif_tarifa_opcional.php` (1 error, exit 2) | Test passes after 1.2/1.3 | None needed |
| 1.2 XML schema | Covered by `test_master_xml_declares_*` (RED with model/XML absent) | `assertColumnWithDefault` / FK + PK assertions green | None needed |
| 1.3 model `model/tarif_tarifa_opcional.php` | Same RED run | 19/19 tests green | Extracted private `fetch_master_row()` so `effective()` is spy-DB testable; protected `ext_defaults()` seam |
| 1.4 GREEN (focused command) | — | `OK (19 tests, 71 assertions)`, exit 0 | — |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifTarifaOpcionalTest` |
| Focused result | `OK (19 tests, 71 assertions)`, exit 0 |
| Regression command | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` |
| Regression result | `Tests: 381, Assertions: 1115, Warnings: 25, Skipped: 1` — OK (read-only `InitOpcionalesTablesTest`, `OpcionalDomainModelOwnershipTest`, `TarifOpcionalesControllerContractTest`, `TarifTarifaOpcionalFamiliaTest` stay green) |
| Runtime harness | `N/A` — Unit 1 is model/schema only; the standalone bootstrap that reaches a live DB is `Init::ensureOpcionalesTarifaTables()` and lands in Unit 3. No runtime path is reachable in this slice. |
| Rollback boundary | `plugins/catalogo_core/model/tarif_tarifa_opcional.php`, `plugins/catalogo_core/model/table/tarif_tarifa_opcional.xml`, `plugins/catalogo_core/tests/TarifTarifaOpcionalTest.php` (plus this progress file and the `tasks.md` checkboxes). Reverting them restores prior state with no unrelated work. |

### Files

| File | Action | What |
|------|--------|------|
| `plugins/catalogo_core/tests/TarifTarifaOpcionalTest.php` | Created | Unit 1 contract: XML PK/FK/defaults, CRUD SQL shape, `all_from_tarifa`/`count_from_tarifa`, `effective()` master vs inherit (no persist), `resolve_*`, `ext_defaults()` protected seam |
| `plugins/catalogo_core/model/table/tarif_tarifa_opcional.xml` | Created | PK `(codtarifa, id_opcional)`; FK CASCADE → `tarif_tarifas`, `catalogo_opcionales`; defaults `en_catalogo=TRUE`, `en_tarifa=FALSE`, `activa=TRUE`, `orden=0` |
| `plugins/catalogo_core/model/tarif_tarifa_opcional.php` | Created | `FSFramework\model\tarif_tarifa_opcional extends \fs_model`: props, `get`/`exists`/`save`/`delete`, `all_from_tarifa`/`count_from_tarifa`, protected `ext_defaults()`, `effective()`, `resolve_activa`/`resolve_en_catalogo`/`resolve_orden`, minimal FK-safe `install()` |

### Design / Interface Notes

- `effective()` returns `{activa, en_catalogo, en_tarifa, orden, source}` with
  `source = 'master'` when a row exists and `'inherit'` otherwise; a missing row
  performs zero writes (asserted).
- Inheritance uses the protected `ext_defaults()` seam: `tarif_opcional_ext`
  flags when present, else `en_catalogo=TRUE` / `en_tarifa=FALSE` (matching the
  seed SQL's COALESCE), `activa=TRUE`, `orden=0`.
- A private `fetch_master_row()` helper keeps `effective()` testable without
  constructing a live model; `get()`/`all_from_tarifa()` wrap it with
  `new static(...)`.
- `install()` intentionally only touches the FK targets and returns `''`; the
  default-tarifa seed is Unit 2 and the `Init.php` bootstrap step is Unit 3.

### Deviations from Design

- `resolve_en_tarifa()` was **not** added: the design interface enumerates only
  `resolve_activa`, `resolve_en_catalogo` and `resolve_orden`; export reads
  `effective()['en_tarifa']` directly (Unit 5).
- `install()` is implemented as the FK-safe dependency touch returning `''`
  (design's final form returns `$this->seed_default_tarifa()`), because the seed
  is explicitly out of Unit 1 scope (Unit 2). No behavioral gap for this slice.

### Status

Unit 1 / PR 1 complete: `4/4` Phase 1 tasks (`1.1`–`1.4`). Ready for the next
batch (Unit 2 — seed/inherit/copy/toggles).

## Work Unit: Unit 2 / PR 2

Self-contained slice: the lifecycle of the per-`(tarifa, opcional)` master —
default-tarifa install seed, `copy_from_tarifa()`, the four explicit toggles
and the precedence contract that ties them together. No `Init.php` bootstrap
step or `solo_activos` repoint (Unit 3), controllers/views (Unit 4) or hybrid
tarifario touches (Unit 5) are included.

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 2.1 `tests/TarifTarifaOpcionalPrecedenceTest.php` | same | Unit | ✅ 396/396 full suite (pre-change 381/381) | ✅ Written first; `set_activa()` undefined → 1 error / 7 tests | ✅ `OK (7 tests, 25 assertions)` | ✅ master-inactive + master-active activation; ext-inherit + no-ext fallback; catalog 0/1; orden 7/0; scoped family SQL override | ➖ None needed |
| 2.2 `tests/TarifTarifaOpcionalLifecycleTest.php` | same | Unit | ✅ same baseline | ✅ Written first; seed/copy/setters missing → 6 errors + 2 failures / 8 tests | ✅ `OK (8 tests, 37 assertions)` | ✅ seed with/without default; copy DELETE+INSERT; toggle create vs update; `set_orden` create vs update | ➖ None needed |
| 2.3 Production (`model/tarif_tarifa_opcional.php`) | — | Unit | ✅ Unit 1 `TarifTarifaOpcionalTest` 19/19 before change | Covered by 2.1/2.2 RED | Covered by 2.1/2.2 GREEN | Covered by 2.1/2.2 | Extracted `persist_toggle()` + `inherited_row()` so the four setters share one create-or-update path; `seed_default_tarifa()`/`default_tarifa_code()` split for the testable default-tarifa seam |
| 2.4 GREEN (focused command) | — | Unit | — | — | `OK (7 tests, 25 assertions)` + `OK (8 tests, 37 assertions)`, exit 0 | — | — |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifTarifaOpcionalPrecedenceTest` and `... --filter TarifTarifaOpcionalLifecycleTest` |
| Focused result | `OK (7 tests, 25 assertions)` and `OK (8 tests, 37 assertions)`, exit 0 |
| Regression command | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` |
| Regression result | `Tests: 396, Assertions: 1177, Warnings: 25, Skipped: 1` — OK (baseline was `381 / 1115 / 25 / 1`; +15 tests, +62 assertions). Unit 1 `TarifTarifaOpcionalTest` stays `OK (19 tests, 71 assertions)`; read-only guards stay green: `InitOpcionalesTablesTest` 7/41, `OpcionalDomainModelOwnershipTest` 5/66, `TarifOpcionalesControllerContractTest` 5/28, `TarifTarifaOpcionalFamiliaTest` 6/28 |
| Runtime harness | `N/A` — Unit 2 is model/SQL-shape only; the live-DB bootstrap (`Init::ensureOpcionalesTarifaTables()`) and the fresh-install/copy smoke land in Units 3 and 5. No runtime path is reachable in this slice. |
| Rollback boundary | `plugins/catalogo_core/model/tarif_tarifa_opcional.php` (seed/copy/setters + seams), `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php`, `plugins/catalogo_core/tests/TarifTarifaOpcionalLifecycleTest.php` (plus this progress file and the `tasks.md` Phase 2 checkboxes). Reverting them restores the Unit 1 state with no unrelated work. |

### Files

| File | Action | What |
|------|--------|------|
| `plugins/catalogo_core/model/tarif_tarifa_opcional.php` | Modified | `install()` now returns the seed; added `seed_default_tarifa()` (INSERT…SELECT from `catalogo_opcionales o LEFT JOIN tarif_opcional_ext e`, `COALESCE(e.en_catalogo, TRUE)`, `COALESCE(e.en_tarifa, FALSE)`, `TRUE`, `0`, `NOT EXISTS` guard) and the `default_tarifa_code()` seam; added `copy_from_tarifa($origen, $destino): bool` (DELETE destination then INSERT…SELECT all six columns); added `set_activa`/`set_en_catalogo`/`set_en_tarifa`/`set_orden` with shared private `persist_toggle()`/`inherited_row()` (first write creates the master row, later writes update it) |
| `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php` | Created | Precedence contract: master `activa` authoritative and price rows never queried for activation; master `en_catalogo` wins over ext; missing row inherits ext/flags without writing; master `orden` default + scoped family `orden` override preserved; first explicit toggle creates the row and overrides the inherited activation |
| `plugins/catalogo_core/tests/TarifTarifaOpcionalLifecycleTest.php` | Created | Lifecycle contract: seed SQL shape and no-default-tarifa empty seed; `install()` keeps the FK guards and returns the seed; `copy_from_tarifa` DELETE-then-copy; toggles create on first write and update afterwards (including `orden`) |

### Design / Interface Notes

- `install()` matches the design interface: touches the FK targets behind
  `class_exists` guards (kept for `TarifTarifaOpcionalEtiquetaTest`) and returns
  `$this->seed_default_tarifa()`.
- `seed_default_tarifa()` is `protected` and delegates the default-tarifa lookup
  to a new protected `default_tarifa_code()` seam (mirrors the existing
  `ext_defaults()` seam). Production behavior is unchanged: `default_tarifa_code()`
  uses `new tarif_tarifa()->get_default()` exactly like
  `tarif_tarifa_familia::migrate_existing_familias()`; the seam only makes the
  seed unit-testable without a live DB.
- `copy_from_tarifa()` matches the design: `DELETE` destination, then
  `INSERT ... SELECT` of `codtarifa, id_opcional, en_catalogo, en_tarifa, activa,
  orden`.
- Toggles are explicit and persist: an existing row is loaded and updated
  in place (unrelated flags survive), a missing row is materialized from the
  inheritance defaults on the first write. They are deliberately separate from
  the non-persisting lazy inherit in `effective()`.

### Deviations from Design

- Added the protected `default_tarifa_code()` seam (not enumerated in design).
  It is an implementation seam over the design's stated
  `new tarif_tarifa()->get_default()` behavior, not a behavioral deviation; it
  exists so the seed SQL is unit-testable (the design's testing strategy lists a
  seed unit test but the inherited `migrate_existing_familias()` had none).
- `set_*`/`copy_from_tarifa` declare `: bool` per the design interface; the
  analogous `tarif_tarifa_familia` methods are untyped.
- Design open question (`copy_precios_opcionales()` dropping
  `en_catalogo`/`porcentaje`) remains untouched — no task in this change.

### Status

Unit 2 / PR 2 complete: `4/4` Phase 2 tasks (`2.1`–`2.4`). Ready for the next
batch (Unit 3 — bootstrap step + `solo_activos` repoint).

## Work Unit: Unit 3 / PR 3

Self-contained slice: the standalone bootstrap step that creates the
per-`(tarifa, opcional)` master table after the five moved `tarif_*` opcional
tables, and the `solo_activos` repoint of `tarif_opcional::search()` /
`count_filtered()` onto the master `activa`. No controllers/views (Unit 4) or
hybrid tarifario touches (Unit 5) are included.

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 3.1 `tests/InitTarifTarifaOpcionalBootstrapTest.php` | same | Contract (source) | ✅ `InitOpcionalesTablesTest` 7/41 before change | ✅ Written first; ordering + class-safe guards failed against the pre-change `Init.php` (`Tests: 4, Failures: 2`) | ✅ `OK (4 tests, 16 assertions)` | ✅ files-exist + ordering-after-5 + guarded instantiation + non-destructive/idempotent | ➖ None needed |
| 3.2 Production `Init.php` | — | Contract | ✅ same baseline | Covered by 3.1 RED | Covered by 3.1 GREEN | Covered by 3.1 | ➖ None needed |
| 3.3 `tests/TarifTarifaOpcionalPrecedenceTest.php` (`solo_activos`) | same | Unit (spy SQL) | ✅ `TarifTarifaOpcionalPrecedenceTest` 7/25 before change | ✅ Written first; `search`/`count_filtered` still emitted the price `INNER JOIN` (`Tests: 10, Failures: 2`) | ✅ `OK (10 tests, 41 assertions)` | ✅ search + count_filtered + a negative (no-`solo_activos`) case | ➖ None needed |
| 3.4 Production `model/tarif_opcional.php` | — | Unit | ✅ same baseline | Covered by 3.3 RED | Covered by 3.3 GREEN | Covered by 3.3 | Symmetric predicate in both methods with an AD2 comment; no helper extracted |
| 3.5 GREEN (Unit 3 command) | — | — | — | — | `OK (14 tests, 57 assertions)`, exit 0 | — | — |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `ddev exec bash -c "php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'InitTarifTarifaOpcionalBootstrap\|TarifTarifaOpcionalPrecedence'Test"` |
| Focused result | `OK (14 tests, 57 assertions)`, exit 0 — `InitTarifTarifaOpcionalBootstrapTest` 4/16; `TarifTarifaOpcionalPrecedenceTest` 10/41 |
| Regression command | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` |
| Regression result | `Tests: 403, Assertions: 1209, Warnings: 25, Skipped: 1` — OK (Unit 2 baseline `396 / 1177 / 25 / 1`; +7 tests, +32 assertions). Read-only guards stay green: `InitOpcionalesTablesTest` 7/41, `OpcionalDomainModelOwnershipTest`, `TarifOpcionalesControllerContractTest`, `TarifTarifaOpcionalFamiliaTest`, `TarifOpcionalPreciosControllerTest`, `OpcionalPriceUnificationTest`. |
| Runtime harness | `N/A` in this apply slice — the DB-free contract/unit suite cannot exercise the live bootstrap (instantiating the master reaches a live database). The designated runtime proof is the verify-phase disposable scratch-DB fresh-install smoke (`catalogo_core` alone, tarifario inactive), per design "Testing Strategy" and the sibling change's verify smoke. |
| Rollback boundary | `plugins/catalogo_core/Init.php` (master ensure step), `plugins/catalogo_core/model/tarif_opcional.php` (`solo_activos` predicate ×2), `plugins/catalogo_core/tests/InitTarifTarifaOpcionalBootstrapTest.php`, the `solo_activos` additions in `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php` (plus this progress file and the `tasks.md` Phase 3 checkboxes). Reverting restores the Unit 2 state with no unrelated work. |

### Files

| File | Action | What |
|------|--------|------|
| `plugins/catalogo_core/tests/InitTarifTarifaOpcionalBootstrapTest.php` | Created | Contract: master model/XML exist; master ensured after the pinned 5-table loop; `is_file`+`require_once` guarded; `class_exists`/`is_subclass_of` guarded instantiation; no destructive DDL/DML and no forced `seed_if_empty` (idempotent ensure) |
| `plugins/catalogo_core/Init.php` | Modified | `ensureOpcionalesTarifaTables()` now ensures the master with a dedicated FK-safe step after the 5-table `foreach`: `$masterFile` require guarded by `is_file`, `$masterFqcn` instantiation guarded by `class_exists`/`is_subclass_of`. The pinned `FK_SAFE_SEQUENCE` literal is untouched |
| `plugins/catalogo_core/model/tarif_opcional.php` | Modified | `search()`/`count_filtered()` `solo_activos` branch drops the `INNER JOIN catalogo_opcional_precios` and filters with `NOT EXISTS (... tarif_tarifa_opcional tto ... activa = FALSE)` instead. Signatures unchanged |
| `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php` | Modified | Spy gained `select_limit()`; `setUpBeforeClass` loads `tarif_opcional.php`; added 3 `solo_activos` tests (search uses master not prices; count_filtered uses master not prices; no-`solo_activos` skips the master predicate) |

### Design / Interface Notes

- Bootstrap follows AD6: the master is a **dedicated step after** the existing
  5-table loop, so `InitOpcionalesTablesTest::FK_SAFE_SEQUENCE` stays exactly
  the five moved models. The master's FK targets (`tarif_tarifas`,
  `catalogo_opcionales`) are ensured earlier in the same method.
- Class-safe / idempotent: the master step mirrors the moved-table guard
  (`is_file` → `require_once`, `class_exists(..., false) && is_subclass_of(...)`
  → `new`). An absent class is a no-op, and `fs_model` only creates the table
  when missing, so re-runs do not mutate schema.
- `solo_activos` semantics follow AD2/AD5 and the
  `tarif_tarifa_opcional_familia` convention: include opcionales with no master
  row (lazy inherit ⇒ active) or `activa=TRUE`; exclude only rows explicitly
  set `activa=FALSE`. Price rows (`catalogo_opcional_precios`) are no longer
  consulted. `tto.activa = FALSE` is emitted as a token; both MySQL and
  PostgreSQL accept `FALSE`.
- Branch condition and signatures are unchanged, so callers (`tarif_opcionales`
  controller, configurator) keep working; `solo_activos` without a `codtarifa`
  still delegates to the parent list.

### Deviations from Design

- None — implementation matches design (AD6 dedicated bootstrap step; AD2
  master-`activa` activation with price rows never activating).

### Issues Found

- None.

### Status

Unit 3 / PR 3 complete: `5/5` Phase 3 tasks (`3.1`–`3.5`). Ready for the next
batch (Unit 4 — controllers + Twig matrices).

## Work Unit: Unit 4 / PR 4

Self-contained slice: the three opcional controllers consume the
per-`(tarifa, opcional)` master instead of price-row existence, and the three
Twig matrices reuse `toggle_button_group`. No hybrid `tarifario` export/heredar
touches (Unit 5).

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 4.1 `tests/TarifOpcionalesControllerMasterStateTest.php` | same | Contract + runtime (spy seams) | ✅ full suite 403/1209 before change | ✅ Written first against pre-change controllers: `Tests: 11, Assertions: 10, Errors: 2, Failures: 9` (exit 2) | ✅ `OK (11 tests, 61 assertions)` | ✅ master inactive over an existing price row; master active with no price row; catalog/en_tarifa from master; list toggle/csrf source contract; view macro + csrf contract | ➖ None needed |
| 4.2 Production `controller/tarif_opcionales.php` | — | Controller | ✅ Unit 1–3 model tests green | Covered by 4.1 RED | Covered by 4.1 GREEN | Covered by 4.1 | Extracted protected `opcional_master_state()`/`opcional_precio_model()` seams; split `load_opcionales_state_cache()` from `load_precios_cache()`; shared `guard_mutating_action()`/`redirect_to_list()` |
| 4.3 Production `controller/tarif_opcional_edit.php` | — | Controller | ✅ same baseline | Covered by 4.1 RED | Covered by 4.1 GREEN | Covered by 4.1 | Added the master seam, single-tarifa `guardar_precio_tarifa()` and master-aware bulk path; readers now read `activa`/`en_catalogo`/`en_tarifa` from the master cache |
| 4.4 Production `controller/tarif_opcional_precios.php` | — | Controller | ✅ same baseline | Covered by 4.1 RED | Covered by 4.1 GREEN | Covered by 4.1 | `esta_activo_en_tarifa()`/`esta_en_catalogo()` resolve `resolve_*`; `guardar_precio_tarifa()` persists master flags under CSRF; `get_precios_todas_tarifas()` reads `effective()` |
| 4.5 Production views ×3 | — | Twig | ✅ templates parsed before change | Covered by 4.1 RED | Covered by 4.1 GREEN | Covered by 4.1 | Replaced raw checkboxes with `tarif.toggle_button_group`; added `csrf_field()` to new POST forms; removed now-unused JS (`toggleTarifaRow`, `toggleActivoTarifa`) |
| 4.6 GREEN (Unit 4 command) | — | — | — | — | `OK (11 tests, 61 assertions)`, exit 0 | — | — |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifOpcionalesControllerMasterStateTest` |
| Focused result | `OK (11 tests, 61 assertions)`, exit 0 (RED before implementation: `Tests: 11, Assertions: 10, Errors: 2, Failures: 9`, exit 2) |
| Contract command | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifOpcionalesControllerContractTest` |
| Contract result | `OK (5 tests, 28 assertions)` — the four controllers still extend `fbase_controller`, zero `tarif_controller`, trait intact |
| Regression command | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` |
| Regression result | `Tests: 414, Assertions: 1270, Warnings: 25, Skipped: 1` — OK (Unit 3 baseline `403 / 1209 / 25 / 1`; +11 tests, +61 assertions). Read-only guards stay green. `ddev exec composer phpstan` → `[OK] No errors` |
| Runtime harness | Twig compile/parse of the three modified templates with a standalone `Twig\Environment` (stubbed undefined functions/filters): `OK tarif_opcionales.html.twig`, `OK tarif_opcional_edit.html.twig`, `OK tarif_opcional_precios.html.twig`. A live DB render is not reachable from the DB-free suite; the designated runtime proof remains the verify-phase disposable scratch-DB smoke (catalogo_core alone, tarifario inactive). |
| Rollback boundary | `plugins/catalogo_core/controller/tarif_opcionales.php`, `controller/tarif_opcional_edit.php`, `controller/tarif_opcional_precios.php`, `View/tarif_opcionales.html.twig`, `View/tarif_opcional_edit.html.twig`, `View/tarif_opcional_precios.html.twig`, `tests/TarifOpcionalesControllerMasterStateTest.php` (plus this progress file and the `tasks.md` Phase 4 checkboxes). Reverting restores the Unit 3 state with no unrelated work. |

### Files

| File | Action | What |
|------|--------|------|
| `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php` | Created | Unit 4 contract/runtime: list/edit/precios behavior via fake master seams (master `activa` wins over a price row; master active without a price row; catalog/en_tarifa from master) plus source contracts (master require/use, reader bodies, CSRF `toggle_*`, matrix master writes, macro reuse + `csrf_field()` in the three views) |
| `plugins/catalogo_core/controller/tarif_opcionales.php` | Modified | Requires/uses the master; `TOGGLE_ACTIONS` + per-row `toggle_opcional_state()` (POST + `requireCsrf()`) persisting via `set_activa`/`set_en_catalogo`/`set_en_tarifa`; `opcional_master_state()`/`opcional_precio_model()` seams; `load_opcionales_state_cache()` built from `effective()`; readers `opcional_activo_en_tarifa`/`opcional_en_catalogo_tarifa`/`opcional_en_tarifa_flag` read the master cache; `load_precios_cache()` contributes price only |
| `plugins/catalogo_core/controller/tarif_opcional_edit.php` | Modified | Master seam; `load_precios_tarifas()` seeds `activa`/`en_catalogo`/`en_tarifa` from `effective()`; readers read the master cache and expose `opcional_en_tarifa_flag()`; new CSRF-guarded single-tarifa `guardar_precio_tarifa()`; bulk `guardar_precios_tarifas()` now also writes the master |
| `plugins/catalogo_core/controller/tarif_opcional_precios.php` | Modified | Master seam; `esta_activo_en_tarifa()`/`esta_en_catalogo()` resolve `resolve_activa`/`resolve_en_catalogo`; new `esta_en_tarifa()`; `guardar_precio_tarifa()` persists the master flags under POST + CSRF; `get_precios_todas_tarifas()` reads `effective()` |
| `plugins/catalogo_core/View/tarif_opcionales.html.twig` | Modified | Imports the macro; Estado/Tarifa/Catálogo columns are per-tarifa master state with `toggle_button_group` + `csrf_field()` + POST `toggle_*` forms |
| `plugins/catalogo_core/View/tarif_opcional_edit.html.twig` | Modified | Imports the macro; "Precios por tarifa" matrix is now one per-tarifa form (`guardar_precio_tarifa` + hidden `codtarifa` + `csrf_field()`) using `toggle_button_group`; removed unused `toggleTarifaRow` JS |
| `plugins/catalogo_core/View/tarif_opcional_precios.html.twig` | Modified | Imports the macro; single-tarifa panel uses `toggle_button_group` + `csrf_field()`; summary table shows the new `en_tarifa` master flag; removed unused `toggleActivoTarifa` JS |

### Design / Interface Notes

- AD2 is enforced at the controller level: `opcional_activo_en_tarifa()`
  (list + edit) and `esta_activo_en_tarifa()` (precios) read the master
  `activa`; price-row existence is never consulted for activation.
- AD3: catalog/export visibility comes from the master `effective()`;
  `load_precios_cache()` now carries only the price value.
- AD5: reading builds state from `effective()` without persisting; explicit
  writes go through `set_activa`/`set_en_catalogo`/`set_en_tarifa`, so the
  first toggle materializes the row.
- The list `toggle_*` endpoints mirror the sibling `tarif_familias` pattern
  (`requireCsrf()` + POST-only), but use a full-page PRG redirect because this
  controller is not HTMX.

### Deviations from Design

- Added protected `opcional_master_state()` seams (untyped, docblock-typed) in
  the three controllers and `opcional_precio_model()` in the list controller.
  These are DB-free test seams over the design's stated `new
  tarif_tarifa_opcional()` behavior, not behavioral deviations.
- Added `opcional_en_tarifa_flag()` (list + edit) and `esta_en_tarifa()`
  (precios) readers, not enumerated in the design interface, so the per-tarifa
  `en_tarifa` flag is visible in the UI.
- The edit matrix was restructured into per-tarifa forms posting the new
  single-tarifa `guardar_precio_tarifa` action: `toggle_button_group` uses fixed
  checkbox names (`activa`/`en_catalogo`/`en_tarifa`), so a single multi-tarifa
  form could not reuse the macro. The legacy bulk `guardar_precios_tarifas()`
  path is retained (still master-aware) for backward compatibility.
- Design open question (`copy_precios_opcionales()`) remains untouched.

### Issues Found

- None.

### Status

Unit 4 / PR 4 complete: `6/6` Phase 4 tasks (`4.1`–`4.6`). Ready for the next
batch (Unit 5 — hybrid tarifario export + heredar copy).

## Work Unit: Unit 5 / PR 5 — Hybrid tarifario: export + `heredar_estructura` copy

This unit changed the **standalone `plugins/tarifario` git repository** (hybrid
slice), not `catalogo_core`. Self-contained slice: the JSON export consumes the
per-`(tarifa, opcional)` master `effective()` (skip `activa === false`;
`en_catalogo`/`en_tarifa` from the effective state) and `heredar_estructura()`
copies the master as step 11 via `copy_from_tarifa($origen, $destino)`. No
`catalogo_core`, `catalogo_opcionales` or `tpvmod` code is touched.

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 5.1 `tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` | same | Hybrid contract + runtime (seam) | ✅ full tarifario suite `187/702/3` before change | ✅ Written first; `build_opcionales_export()` absent + no master require/use → `Tests: 6, Errors: 4, Failures: 2` (exit 2) | ✅ `OK (6 tests, 24 assertions)` | ✅ inactive skipped; master `en_catalogo`/`en_tarifa` beat the conflicting legacy ext flags; missing row lazy-inherits active (`en_catalogo=TRUE`, `en_tarifa=FALSE`); dedupe resolves each id once | ➖ None needed (one test expectation corrected to the documented inherit default) |
| 5.2 `tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php` | same | Hybrid contract + runtime (seam) | ✅ same baseline | ✅ Written first; `copy_tarifa_opcionales()` absent + no master require/use → `Tests: 4, Errors: 1, Failures: 3` (exit 2) | ✅ `OK (4 tests, 29 assertions)` | ✅ copy delegates `copy_from_tarifa(origen,destino)`; step 11 after the ten legacy steps (all asserted intact); seam + copy call contracts | ➖ None needed |
| 5.3 Production `controller/tarif_catalogo_view.php` | — | Controller | ✅ same baseline | Covered by 5.1 RED | Covered by 5.1 GREEN | Covered by 5.1 | Extracted private `build_opcionales_export()` (delegated from `build_export_data()`) + protected `opcional_master_state()` seam |
| 5.4 Production `controller/tarif_tarifas.php` | — | Controller | ✅ same baseline | Covered by 5.2 RED | Covered by 5.2 GREEN | Covered by 5.2 | Added protected `opcional_master_state()` seam + private `copy_tarifa_opcionales()` helper; `heredar_estructura()` step 11 |
| 5.5 GREEN (Unit 5 command) | — | — | — | — | `OK (6 tests, 24 assertions)` + `OK (4 tests, 29 assertions)` = `10/53`, exit 0 | — | — |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test command | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter TarifCatalogoOpcionalMasterExportTest` and `... --filter TarifTarifasHeredarOpcionalMasterTest` (separate invocations: `ddev exec` wraps `bash -c`, so `\|` filters are not passed through) |
| Focused result | `OK (6 tests, 24 assertions)` and `OK (4 tests, 29 assertions)`, exit 0. RED first: `Tests: 6, Errors: 4, Failures: 2` and `Tests: 4, Errors: 1, Failures: 3`, exit 2 |
| Regression command | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` |
| Regression result | `Tests: 197, Assertions: 755, Skipped: 3` — OK (baseline `187 / 702 / 3`; +10 tests, +53 assertions). No pre-existing tarifario test changed or removed |
| Runtime harness | DB-free behavioural harness through the new `opcional_master_state()` seam: the export resolves the per-tarifa state (master-inactive opcional excluded; `en_catalogo`/`en_tarifa` taken from the master over conflicting global ext flags) and the copy helper records `copy_from_tarifa('T-ORIGEN','T-DESTINO')`. A live-DB export/`heredar_estructura` smoke is not reachable from the DB-free suite; the designated runtime proof remains the verify-phase disposable scratch-DB smoke (same deferral as Units 1–4) |
| Linter | `ddev exec composer phpstan` → `[OK] No errors` |
| Rollback boundary | `plugins/tarifario/controller/tarif_catalogo_view.php`, `plugins/tarifario/controller/tarif_tarifas.php`, `plugins/tarifario/tests/Controller/TarifCatalogoOpcionalMasterExportTest.php`, `plugins/tarifario/tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php` (plus this progress file and the `tasks.md` Phase 5 checkboxes). Reverting them restores the Unit 4 state with no unrelated work |

### Files

| File | Action | What |
|------|--------|------|
| `plugins/tarifario/tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` | Created | Hybrid contract/runtime: export skips master `activa === false`; `en_catalogo`/`en_tarifa` come from `effective()` even when the legacy ext flags disagree; a missing row lazy-inherits active; dedupe resolves each id once; source contracts for the master require/use and the `effective()` consumption |
| `plugins/tarifario/tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php` | Created | Hybrid contract/runtime: `copy_tarifa_opcionales()` delegates `copy_from_tarifa(origen, destino)`; `heredar_estructura()` calls it as step 11 after the ten legacy steps (all asserted intact); master require/use and seam contracts |
| `plugins/tarifario/controller/tarif_catalogo_view.php` | Modified | Requires/uses the master; protected `opcional_master_state()` seam; extracted private `build_opcionales_export()` (called by `build_export_data()`), which resolves each distinct opcional through `effective()` at `$this->codtarifa`, skips `activa === false`, and emits `en_tarifa`/`en_catalogo` from the effective state (the pre-existing `activo` global flag is preserved) |
| `plugins/tarifario/controller/tarif_tarifas.php` | Modified | Requires/uses the master; step 11 `copy_tarifa_opcionales($origen, $destino)` in `heredar_estructura()`; protected `opcional_master_state()` seam + private `copy_tarifa_opcionales()` delegating to `copy_from_tarifa()` |

### Design / Interface Notes

- AD2 is enforced in the export: the master `activa` decides inclusion; an
  opcional explicitly set `activa = FALSE` for the tarifa is never exported.
- AD3: `en_catalogo`/`en_tarifa` are taken from the master `effective()` state,
  not from the legacy global `tarif_opcional_ext` flags carried by the opcional
  object (the tests pin this by making the two disagree).
- AD5: a missing master row still exports, using the inherited defaults
  (`activa=TRUE`, `en_catalogo=TRUE`, `en_tarifa=FALSE`); reading never persists.
- Copy mirrors the existing `copy_opcional_familias()` step pattern (model
  helper behind a seam) and runs last so FK-target rows (familias/artículos)
  already exist in the destination.
- The `opcional_master_state()` seam name is identical to the one introduced in
  the `catalogo_core` controllers (Unit 4), keeping the cross-plugin convention.

### Deviations from Design

- Added the protected `opcional_master_state()` seam (not enumerated in design)
  in both tarifario controllers, consistent with the Unit 4 `catalogo_core`
  seams; it is a DB-free test seam over the design's stated
  `new tarif_tarifa_opcional()` behavior, not a behavioural deviation.
- The export keeps the legacy `'activo'` JSON field from the global opcional
  (`$opc->activo`). The design line "`en_tarifa`/`en_catalogo`/`activa` from
  `$s`" is read as: the effective `activa` is the inclusion/skip decision, while
  `en_tarifa`/`en_catalogo` are surfaced from the effective state. Setting
  `activo` from the master would be unobservable (inactive rows are skipped) and
  would conflate the per-tarifa activation with the global catalog active flag.
- Design open question (`copy_precios_opcionales()` dropping
  `en_catalogo`/`porcentaje`) remains untouched — no task in this change.

### Issues Found

- None.

### Status

Unit 5 / PR 5 complete: `5/5` Phase 5 tasks (`5.1`–`5.5`). All five apply
units are implemented. Ready for verify (Phase 6).

## Post-verify — CodeRabbit remediation (findings 1–6)

Scoped correction work unit over `catalogo_core` only. All six CodeRabbit
findings were re-validated against the current working tree; all six are real
and were fixed minimally. No `tarifario` code changed.

### Per-finding verdicts

| # | Finding | Verdict | Reason |
|---|---------|---------|--------|
| 1 | `model/tarif_tarifa_opcional.php` `persist_toggle()` routed every setter through the full-row `save()` UPDATE, rewriting `en_catalogo`/`en_tarifa`/`activa`/`orden` from possibly-stale in-memory values | **FIXED** | Existing rows are now updated with a single-column, whitelisted `UPDATE` (`update_single_field()`); only the first write (missing row) still inserts the full inherited row. The full-row `save()` UPDATE is now unreachable in production (no direct caller). |
| 2 | `copy_from_tarifa()` ran `DELETE` then `INSERT … SELECT` with no transaction, leaving the destination empty on insert failure | **FIXED** | Both statements now run with `$transaction = false` inside `begin_transaction()`/`commit()`, with `rollback()` on any failure and the `bool` contract preserved. |
| 3 | `controller/tarif_opcional_edit.php::guardar_precio_tarifa()` ignored every setter/price result and was not atomic | **FIXED** | The three master setters and the price save/delete run in `run_in_transaction()`; every write result is checked, the change rolls back on failure, and success is reported only after commit. |
| 4 | `controller/tarif_opcional_edit.php` parsed prices with `floatval()` (partial parse / silent 0) | **FIXED** | New `parse_price_input()` validates the complete normalized value before any write and rejects partial (`12abc`), mixed/repeated-separator (`1.234,56`, `1.2.3`) and non-numeric input; empty still means `0.0`. |
| 5 | `controller/tarif_opcional_precios.php::guardar_precio_tarifa()` was not atomic | **FIXED** | Same `run_in_transaction()` wrap as finding 3; success is reported only after commit and failures report an error. The validated price parser is reused there too (same field, method already rewritten). |
| 6 | `controller/tarif_opcionales.php::redirect_to_list()` dropped `query`, `b_codfamilia`, `b_solo_activos` and `offset` | **FIXED** | The three toggle forms now post those values as hidden inputs, and `redirect_to_list()` appends the scalar ones back (urlencoded) to the redirect, so a toggle returns to the same filtered/paginated page. |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| PVR.1 finding 1 (`update_single_field`) | `tests/TarifTarifaOpcionalLifecycleTest.php` | Unit (spy SQL) | ✅ 414/1270 full suite before change | ✅ `test_set_activa_updates_only_the_requested_column` failed: update still contained `en_catalogo = 1, en_tarifa = 1, activa = 0, orden = 3` (3 failures total in the class) | ✅ `OK (11 tests, 49 assertions)` | ✅ single-flag update per `activa`/`en_catalogo`/`en_tarifa`/`orden`; unrelated columns asserted absent | Extracted `update_single_field()` with a field whitelist |
| PVR.2 finding 2 (`copy_from_tarifa`) | same | Unit (spy transaction) | ✅ same baseline | ✅ both new tests failed: transaction log was `[]` (no `BEGIN`/`COMMIT`/`ROLLBACK`) | ✅ `OK (11 tests, 49 assertions)` | ✅ commit path + rollback-on-failed-insert path; both statements asserted `$transaction = false` | Extracted the transaction guard; `DELETE`/`INSERT` results checked |
| PVR.3 findings 3/5 (`run_in_transaction`) | `tests/TarifarioOpcionalStateTraitTest.php` (new) | Unit (runtime spy) | ✅ same baseline | ✅ 18 errors: `run_in_transaction()`/`parse_price_input()` undefined on the trait subject | ✅ `OK (18 tests, 24 assertions)` | ✅ success/commit, work-false rollback, exception rollback, begin-failure abort, commit-failure rollback, auto-transaction restored | Transaction helper extracted to `TarifarioOpcionalStateTrait` and reused by both controllers |
| PVR.4 finding 4 (`parse_price_input`) | same | Unit (runtime) | ✅ same baseline | ✅ same 18-error RED (method absent) | ✅ `OK (18 tests, 24 assertions)` | ✅ 6 valid inputs (empty/int/dot/comma/negative/leading-comma) + 7 rejected (partial, non-numeric, double separator, mixed separators ×2, repeated, inner spaces) | Regex + single normalization, `floatval` removed from the write path |
| PVR.5 finding 3 wiring (edit) | `tests/TarifOpcionalesControllerMasterStateTest.php` | Contract (source) | ✅ 414/1270 baseline | ✅ `test_edit_single_tarifa_save_is_atomic_and_validates_the_price` failed (no `run_in_transaction(`, no `parse_price_input(`) | ✅ `OK (15 tests, 75 assertions)` | ✅ atomic wrap + price-parse + error path asserted; existing `set_*`/`requireCsrf` contracts intact | Result checks + error branch; bulk path untouched |
| PVR.6 finding 5 wiring (precios) | same | Contract (source) | ✅ same baseline | ✅ `test_precios_single_tarifa_save_is_atomic` failed | ✅ `OK (15 tests, 75 assertions)` | ✅ atomic wrap + error path asserted; original success messages preserved | Result checks + success/error branching |
| PVR.7 finding 6 (redirect + view) | same | Contract (source) | ✅ same baseline | ✅ `test_list_toggle_redirect_preserves_active_filters` and `test_list_toggle_forms_carry_the_active_filters` failed (no filter params / no `name="offset"`) | ✅ `OK (15 tests, 75 assertions)` | ✅ controller body carries all four keys; view posts the offset/filter hidden inputs | `is_scalar()` guard added against array-typed POST values |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test commands | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifarioOpcionalStateTraitTest`; `… --filter TarifTarifaOpcionalLifecycleTest`; `… --filter TarifOpcionalesControllerMasterStateTest` (separate invocations) |
| Focused results | `OK (18 tests, 24 assertions)`, `OK (11 tests, 49 assertions)`, `OK (15 tests, 75 assertions)` — all exit 0. RED first: trait `18 errors`; lifecycle `3 failures`; controller `4 failures` |
| Regression command | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` |
| Regression result | `Tests: 439, Assertions: 1320, Warnings: 25, Skipped: 1` — OK (pre-change `414 / 1270 / 25 / 1`; +25 tests, +50 assertions). Read-only guards stay green |
| Cross-plugin regression | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → `Tests: 197, Assertions: 755, Skipped: 3` (unchanged) |
| Linter | `ddev exec composer phpstan` → `188/188`, `[OK] No errors` |
| Runtime harness | Real runtime spies exercise the transaction primitive end-to-end: `TarifarioOpcionalStateTraitTest` drives `run_in_transaction()` through begin/commit, failure-rollback, exception-rollback, begin-failure and commit-failure paths; `TarifTarifaOpcionalLifecycleTest` verifies the `copy_from_tarifa()` `BEGIN`/`COMMIT` and `BEGIN`/`ROLLBACK` sequences with `$transaction = false` statements. Controller wiring is locked by source contracts (the DB-free suite cannot instantiate a live controller/price model) |
| Rollback boundary | `model/tarif_tarifa_opcional.php`, `controller/tarif_opcional_edit.php`, `controller/tarif_opcional_precios.php`, `controller/tarif_opcionales.php`, `extras/TarifarioOpcionalStateTrait.php`, `View/tarif_opcionales.html.twig`, `tests/TarifTarifaOpcionalLifecycleTest.php`, `tests/TarifOpcionalesControllerMasterStateTest.php`, `tests/TarifarioOpcionalStateTraitTest.php` (plus this progress section). Reverting them restores the verified Unit 5 state with no unrelated work |

### Files

| File | Action | What |
|------|--------|------|
| `plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php` | Modified | Added `run_in_transaction(callable): bool` (disables per-statement auto-transactions, begins, commits only on `true`, rolls back on failure/exception, always restores the previous setting) and `parse_price_input($raw): ?float` (complete-decimal validation) |
| `plugins/catalogo_core/model/tarif_tarifa_opcional.php` | Modified | `persist_toggle()` now issues a single-column `update_single_field()` UPDATE for existing rows; new whitelisted `update_single_field()`. `copy_from_tarifa()` wraps `DELETE` + `INSERT … SELECT` in `begin_transaction()`/`commit()`/`rollback()` with `$transaction = false` and result checks |
| `plugins/catalogo_core/controller/tarif_opcional_edit.php` | Modified | Validates `precio` via `parse_price_input()` before writing; wraps the three setters + price save/delete in `run_in_transaction()`; checks every result and reports success only after commit |
| `plugins/catalogo_core/controller/tarif_opcional_precios.php` | Modified | Same atomic wrap and validated price parsing; preserves the original active/inactive success messages and adds an explicit error branch |
| `plugins/catalogo_core/controller/tarif_opcionales.php` | Modified | `redirect_to_list()` re-appends the scalar `query`/`b_codfamilia`/`b_solo_activos`/`offset` POST values (urlencoded, `is_scalar`-guarded) |
| `plugins/catalogo_core/View/tarif_opcionales.html.twig` | Modified | The three `toggle_*` forms post `query`, `b_codfamilia`, `b_solo_activos` and `offset` as hidden inputs |
| `plugins/catalogo_core/tests/TarifarioOpcionalStateTraitTest.php` | Created | Runtime contract for `run_in_transaction()` (5 paths) and `parse_price_input()` (13 data sets) |
| `plugins/catalogo_core/tests/TarifTarifaOpcionalLifecycleTest.php` | Modified | Spy records transaction calls/`$transaction` flags and can fail inserts; added single-column-update + `copy_from_tarifa` commit/rollback tests |
| `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php` | Modified | Added atomicity/price-validation source contracts for edit/precios and the redirect/view filter-preservation contracts |

### Notes / residuals

- Transaction support was confirmed before use: `fs_db2::begin_transaction()/commit()/rollback()` and the documented `get_auto_transactions()/set_auto_transactions()` pair, with the in-repo precedent `tarif_tarifa_opcional_etiqueta::replace_etiquetas_opcional()`. No fake transactions were introduced.
- `tarif_opcional_edit::guardar_precios_tarifas()` (legacy bulk matrix path) still writes each tarifa without a surrounding transaction. It was **not** in the flagged line range (finding 3 targets the per-tarifa `guardar_precio_tarifa()`), so it is left as a residual risk, not a regression.
- `parse_price_input()` was also applied to `tarif_opcional_precios::guardar_precio_tarifa()` because the same field was being rewritten for finding 5; the earlier `floatval()` sites in `tarif_opcional_edit::new_opcional()`/`guardar_precios_tarifas()` are unchanged.
- **Historical (round 1).** At the time of round 1 the verify report described the pre-remediation implementation; this was superseded by the round-1 and round-2 re-verifications. The current `verify-report.md` contains fresh hashes.

### Status

All six findings remediated. `439/439` catalogo_core tests + `197/197` tarifario tests pass; PHPStan `188/188 [OK]`. Ready for re-verify.

## Post-verify — CodeRabbit remediation round 2 (findings 7–10)

Scoped correction work unit over `catalogo_core` only. All four findings were
re-validated against the current working tree; all four are real and were fixed
minimally. Findings 9 and 10 (trait + model) are covered by new behavioral
tests; findings 7/8 (legacy bulk path) are locked by source contracts on the
existing DB-free suite, the same layer used for the round-1 controller wiring.

### Per-finding verdicts

| # | Finding | Verdict | Reason |
|---|---------|---------|--------|
| 7 | `guardar_precios_tarifas()` bulk path lacked the POST + CSRF guard of `guardar_precio_tarifa()` | **FIXED** | The bulk method now starts with the same `strtoupper($request->getMethod()) !== 'POST' \|\| !$this->requireCsrf()` guard and reports an error instead of proceeding. The path is only reachable via `isset($_POST['guardar_precios_tarifas'])` (POST-only) and has no view form left, so adding `requireCsrf()` is backward-safe: it cannot break a live caller and closes the missing-token gap. |
| 8 | Bulk path was not transactional and ignored setter/price results | **FIXED** | The whole per-tarifa loop plus the legacy aggregate recompute now runs inside the existing `run_in_transaction()` helper (no duplicated transaction logic). Every `set_activa`/`set_en_catalogo`/`set_en_tarifa` and the price `save()`/`delete_precio_tarifa()` result is checked; any failure returns `false`, rolls back, and reports an error before the success message. |
| 9 | `run_in_transaction()` began the transaction outside `try/finally`; a throwing `begin_transaction()` left auto-transactions disabled | **FIXED** | `begin_transaction()` moved inside the protected `try`; a `$began`/`$committed` pair gates `rollback()` so only a started-and-uncommitted transaction rolls back, the catch absorbs the begin exception, and `finally` always restores the saved auto-transaction setting. |
| 10 | `copy_from_tarifa($origen, $destino)` wipes the tarifa when source == destination | **FIXED** | An equality check before the transaction returns a successful no-op (no DELETE, no INSERT, no BEGIN). A self-copy is idempotent and cannot destroy the rows it would copy. Regression test added. |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| PVR2.1 finding 9 (begin throws) | `tests/TarifarioOpcionalStateTraitTest.php` | Unit (runtime spy) | ✅ 439/1320 full suite | ✅ `RuntimeException: begin failed` propagated out of `run_in_transaction()` (1 error / 19 tests, exit 2) | ✅ `OK (19 tests, 27 assertions)` | ✅ Existing begin/commit/rollback/commit-fail paths re-asserted alongside the new throwing-begin path | Spy gained a `throwOnBegin` switch; no production helper extracted |
| PVR2.2 finding 10 (self-copy) | `tests/TarifTarifaOpcionalLifecycleTest.php` | Unit (spy SQL) | ✅ same baseline | ✅ `test_copy_from_tarifa_is_a_noop_when_source_equals_destination` failed: the DELETE + INSERT ran (1 failure / 12 tests) | ✅ `OK (12 tests, 52 assertions)` | ✅ Existing DELETE+INSERT commit/rollback tests still pin the non-equal path | Early equality guard placed before the transaction |
| PVR2.3 findings 7/8 (bulk wiring) | `tests/TarifOpcionalesControllerMasterStateTest.php` | Contract (source) | ✅ same baseline | ✅ `test_edit_bulk_matrix_save_is_csrf_guarded_and_atomic` failed: `guardar_precios_tarifas` body contained no `requireCsrf()` (1 failure / 16 tests) | ✅ `OK (16 tests, 84 assertions)` | ✅ Asserts `run_in_transaction(`, all three `!$master->set_*` result checks, `!$precio->save()`, `!$opcional->delete_precio_tarifa(`, and the error branch | Bulk body moved inside a closure; no duplicate transaction logic |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test commands | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifarioOpcionalStateTraitTest`; `… --filter TarifTarifaOpcionalLifecycleTest`; `… --filter TarifOpcionalesControllerMasterStateTest` (separate invocations) |
| Focused results | `OK (19 tests, 27 assertions)`, `OK (12 tests, 52 assertions)`, `OK (16 tests, 84 assertions)` — all exit 0. RED first: trait `1 error`; lifecycle `1 failure`; controller `1 failure` |
| Regression command | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` |
| Regression result | `Tests: 442, Assertions: 1335, Warnings: 25, Skipped: 1` — OK (pre-round-2 `439 / 1320 / 25 / 1`; +3 tests, +15 assertions). Read-only guards re-run individually and green: `InitOpcionalesTablesTest` 7/41, `OpcionalDomainModelOwnershipTest` 5/66, `TarifOpcionalesControllerContractTest` 5/28, `TarifTarifaOpcionalFamiliaTest` 6/28 |
| Cross-plugin regression | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → `Tests: 197, Assertions: 755, Skipped: 3` (unchanged; `copy_from_tarifa` is consumed by `heredar_estructura`) |
| Linter | `ddev exec composer phpstan` → `188/188`, `[OK] No errors` |
| Runtime harness | Real runtime spies exercise both new behaviors end-to-end: the trait spy throws from `begin_transaction()` and asserts the auto-transaction setting is restored (`['auto:0','begin','auto:1']`); the lifecycle spy asserts a self-copy issues zero `exec` statements and zero transaction statements while the non-equal copy still runs `BEGIN`/`COMMIT`. The bulk controller path stays covered by source contracts (the DB-free suite cannot instantiate a live controller/price model), same layer as round 1 |
| Rollback boundary | `extras/TarifarioOpcionalStateTrait.php`, `model/tarif_tarifa_opcional.php`, `controller/tarif_opcional_edit.php`, `tests/TarifarioOpcionalStateTraitTest.php`, `tests/TarifTarifaOpcionalLifecycleTest.php`, `tests/TarifOpcionalesControllerMasterStateTest.php` (plus this progress section). Reverting them restores the round-1 remediation state with no unrelated work |

### Files

| File | Action | What |
|------|--------|------|
| `plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php` | Modified | `run_in_transaction()`: `begin_transaction()` moved inside the protected `try`; `$began`/`$committed` flags gate `rollback()` so a successful commit is never rolled back and a throw from begin still restores the saved auto-transaction setting |
| `plugins/catalogo_core/model/tarif_tarifa_opcional.php` | Modified | `copy_from_tarifa()` returns a successful no-op when `$origen === $destino`, before opening the transaction |
| `plugins/catalogo_core/controller/tarif_opcional_edit.php` | Modified | `guardar_precios_tarifas()` now enforces POST + `requireCsrf()` and runs the entire bulk matrix inside `run_in_transaction()`, checking every master setter and price save/delete; success is reported only after the commit, failure reports an error |
| `plugins/catalogo_core/tests/TarifarioOpcionalStateTraitTest.php` | Modified | Spy gained `throwOnBegin`; new test for the throwing-begin restore path |
| `plugins/catalogo_core/tests/TarifTarifaOpcionalLifecycleTest.php` | Modified | New regression test: `copy_from_tarifa('T1','T1')` is a transaction-free no-op |
| `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php` | Modified | New source contract: bulk matrix save is CSRF-guarded, transactional and result-checked |

### Notes / residuals

- Finding 7 was safe to change: `guardar_precios_tarifas()` is dispatched only from `isset($_POST['guardar_precios_tarifas'])` (POST-only by construction) and no view posts that field anymore, so requiring a valid CSRF token cannot break a live caller.
- The bulk path still parses prices with `floatval()`. The assigned findings 7–10 do not cover parser precision (that was verify SUGGESTION-4); the round-1 `parse_price_input()` sites are unchanged.
- **Historical (round 2).** At the time of round 2 the verify report described the pre-round-2 implementation; this was superseded by the round-2 re-verification. The current `verify-report.md` contains fresh hashes.

### Status

All four findings remediated. `442/442` catalogo_core tests + `197/197` tarifario tests pass; PHPStan `188/188 [OK]`. Ready for re-verify.

## Post-verify — Residual remediation (verify WARNING-5/1/3, SUG-2/3/4/5/7)

Scoped residual-remediation work unit closing the actionable issues from the
round-2 `verify-report.md`. Two standalone repos: `catalogo_core`
(items A, B, C, E, F) and `tarifario` (item D only). No framework/core file was
touched (WARNING-2 stays accepted). All work is uncommitted/staged by design;
delivery is human-owned.

### Per-item verdicts

| # | Issue | Verdict | Reason |
|---|-------|---------|--------|
| A | WARNING-5 / SUG-4: legacy bulk matrix parses prices with `floatval(str_replace(',', '.', ...))` | **FIXED** | `guardar_precios_tarifas()` now pre-validates every submitted price with the trait's `parse_price_input()` before opening the transaction (`$precios_validados` map). An invalid value reports a targeted error and writes nothing; the closure reuses the validated value. No `floatval()` remains in the bulk body. |
| B | SUG-5: full-row `save()` UPDATE unreachable in production | **FIXED (documentation only)** | `model/tarif_tarifa_opcional.php::save()` gained a class-level docblock plus an inline comment marking the UPDATE branch as a defensive fallback that has no production caller (the `set_*` toggles use the whitelisted `update_single_field()`), with an explicit "do not add new callers" note. No behavior change, no new callers. |
| C | SUG-7: `parse_price_input()` treats `1.234` as `1.234` (single separator = decimal) | **FIXED** | The docblock now documents the locale convention explicitly: a single `.`/`,` is ALWAYS the decimal separator, never a thousands separator (so `1.234`/`1,234` = 1.234 and `1234` = 1234.0); thousands grouping is intentionally unsupported. A dedicated characterization test asserts the behavior. |
| D | SUG-2: `copy_precios_opcionales()` drops `en_catalogo`/`porcentaje` | **FIXED** | `tarif_tarifas::copy_precios_opcionales()` now copies `precio, porcentaje, en_catalogo`, so a tarifa copy no longer loses per-lista opcional price data. Live `information_schema` confirms all three columns exist. |
| E | WARNING-1 / SUG-3: master `orden` has no production consumer | **FIXED** | `tarif_opcional::search()` now orders the per-tarifa listing by the master default (`COALESCE(mto.orden, 999999)`) and, when the listing is family-scoped, by the family override (`COALESCE(fto.orden, mto.orden, 999999)`). `count_filtered()` was widened to the same branch so pagination stays consistent. The family/article resolvers (`tarif_tarifa_opcional_familia::get_opcionales_activos()`, `tarif_tarifa_articulo_opcional`) are untouched. Public signatures unchanged. |
| F | WARNING-3: fresh-install standalone bootstrap only contract-verified | **FIXED (executed for real)** | Disposable scratch DB (`smoke_cc_fresh`, utf8mb4_general_ci, `db` user granted) → `Init::ensureOpcionalesTarifaTables()` run twice. Master created from an empty DB with composite PK + 2 CASCADE FKs, after the five moved tables; second run byte-identical (idempotent). `SMOKE_RESULT=PASS`, exit 0. Commands below. |
| G | WARNING-2: physical boolean defaults are `0`, not XML `TRUE` | **ACCEPTED (no code)** | Pre-existing framework `TypeNormalizer` behavior; the live smoke reproduces `en_catalogo/en_tarifa/activa tinyint(1) NOT NULL DEFAULT 0`. Runtime is unaffected because inserts set the columns explicitly; the human accepted it. No core/framework file was modified. Recorded below as an accepted residual. |

### TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| R-A bulk parser | `tests/TarifOpcionalesControllerMasterStateTest.php` | Contract (source) | ✅ 442/1335 catalogo_core before change | ✅ `test_edit_bulk_matrix_parses_prices_with_the_validated_parser` failed: body contained no `parse_price_input(` (1 failure / 17 tests) | ✅ `OK (17 tests, 88 assertions)` | ✅ Asserts the parser is used, `floatval(` is gone and an error branch exists; parser behaviour already covered by 13 datasets in the trait test | Extracted the pre-validation loop; renamed the captured map `$precios_validados` to avoid shadowing the later `$precios` aggregate |
| R-C locale convention | `tests/TarifarioOpcionalStateTraitTest.php` | Unit (runtime) | ✅ same baseline | ➖ Characterization test — passed immediately because `parse_price_input()` already behaved as documented (the gap was missing documentation/assertion, not behavior) | ✅ `OK (20 tests, 30 assertions)` | ✅ Single dot, single comma and separator-free values asserted together with the existing 6 valid + 7 invalid datasets | Docblock updated only |
| R-E master orden consumer | `tests/TarifTarifaOpcionalPrecedenceTest.php` | Unit (spy SQL) | ✅ same baseline | ✅ `test_per_tarifa_listing_orders_by_master_orden_default` + `..._scoped_family_orden_overrides_the_master_default` failed against the pre-change `search()` (2 failures / 12 tests) | ✅ `OK (12 tests, 49 assertions)` | ✅ Master-only order, family-scoped override, and the no-`solo_activos` negative (no activation predicate) | Built the SELECT list, JOINs and ORDER BY from one `$order_expr` to keep the DISTINCT/alias rule (MySQL 3065) |
| R-D price-copy columns | `plugins/tarifario/tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php` | Contract (source) | ✅ 197/755 tarifario before change | ✅ `test_copy_precios_opcionales_preserves_the_per_lista_columns` failed: body contained no `porcentaje` (1 failure / 5 tests) | ✅ `OK (5 tests, 33 assertions)` | ✅ Asserts `precio`, `porcentaje` and `en_catalogo` are all carried; live `information_schema` confirms the columns exist | None needed |
| R-B defensive save doc | — | docs only | ✅ same baseline | ➖ No test (no behavior change; the request is an explicit documentation note on the unreachable branch) | ✅ PHPStan `188/188 [OK]` | ➖ | Docblock + inline comment |
| R-F fresh-install smoke | `tmp/smoke_opcionales_fresh_install.php` (scratch harness, gitignored) | Runtime (live DB) | ✅ same baseline | ➖ Runtime smoke, not a unit test (the DB-free suite cannot exercise a live bootstrap) | ✅ `SMOKE_RESULT=PASS`, exit 0 | ✅ Empty DB start + second idempotent run; PK + 2 FK + moved-table sequence asserted | None needed |

### Work Unit Evidence

| Evidence | Value |
|---|---|
| Focused test commands | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifOpcionalesControllerMasterStateTest`; `… --filter TarifTarifaOpcionalPrecedenceTest`; `… --filter TarifarioOpcionalStateTraitTest`; `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml --filter TarifTarifasHeredarOpcionalMasterTest` (separate invocations — `ddev exec` wraps `bash -c`) |
| Focused results | `OK (17 tests, 88 assertions)`, `OK (12 tests, 49 assertions)`, `OK (20 tests, 30 assertions)`, `OK (5 tests, 33 assertions)` — all exit 0. RED first: bulk `1 failure`; precedence `2 failures`; tarifario copy `1 failure`; trait locale test passed immediately (characterization) |
| Regression command | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` |
| Regression result | `Tests: 446, Assertions: 1350, Warnings: 25, Skipped: 1` — OK (pre-remediation `442 / 1335 / 25 / 1`; +4 tests, +15 assertions). Read-only guards stay green |
| Cross-plugin regression | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → `Tests: 198, Assertions: 759, Skipped: 3` — OK (pre-remediation `197 / 755 / 3`; +1 test, +4 assertions) |
| Linter | `ddev exec composer phpstan` → `188/188`, `[OK] No errors`, exit 0 |
| Runtime harness (E) | Live MariaDB execution of the new listing SQL (dummy `codtarifa`/`codfamilia`) exits 0 — the `SELECT DISTINCT … COALESCE(…) AS orden_tarifa … ORDER BY orden_tarifa` shape is accepted (no MySQL 3065), for both the master-only and the family-scoped variants |
| Runtime harness (F) | Disposable scratch-DB fresh-install smoke — see exact commands and output below |
| Runtime harness (D) | `information_schema.COLUMNS` for `catalogo_opcional_precios` returns `precio double`, `porcentaje double`, `en_catalogo tinyint` — the widened copy columns exist |
| Rollback boundary | `catalogo_core`: `controller/tarif_opcional_edit.php`, `extras/TarifarioOpcionalStateTrait.php`, `model/tarif_opcional.php`, `model/tarif_tarifa_opcional.php`, `tests/TarifOpcionalesControllerMasterStateTest.php`, `tests/TarifTarifaOpcionalPrecedenceTest.php`, `tests/TarifarioOpcionalStateTraitTest.php`. `tarifario`: `controller/tarif_tarifas.php`, `tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php`. Reverting them restores the round-2 re-verified state with no unrelated work |

### WARNING-3 scratch-DB smoke — exact commands and observed output

```bash
# 1. Disposable scratch DB with the dev schema collation (utf8mb4_general_ci;
#    a different collation silently drops the FKs).
ddev exec mysql -uroot -proot -e "DROP DATABASE IF EXISTS smoke_cc_fresh; CREATE DATABASE smoke_cc_fresh CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci; GRANT ALL PRIVILEGES ON smoke_cc_fresh.* TO 'db'@'%'; FLUSH PRIVILEGES;"

# 2. Standalone bare-base bootstrap (no Kernel, tarifario inactive).
#    The harness defines the framework constants with FS_DB_NAME=smoke_cc_fresh,
#    sets $GLOBALS['plugins'] = ['catalogo_core'], registers
#    fs_model_autoloader so FSFramework\model\* resolves, then calls
#    Init::ensureOpcionalesTarifaTables() twice.
ddev exec php tmp/smoke_opcionales_fresh_install.php smoke_cc_fresh

# 3. Cleanup.
ddev exec mysql -uroot -proot -e "DROP DATABASE IF EXISTS smoke_cc_fresh;"
```

Observed output (exit 0):

```text
scratch_db=smoke_cc_fresh
tables_before=0
master_before=absent
run1_master=present
run1_sequence=["tarif_opcional_ext","tarif_tarifa_opcional_etiqueta","tarif_opcional_precio_historial","tarif_tarifa_opcional_familia","tarif_tarifa_articulo_opcional","tarif_tarifa_opcional"]
run1_table[0]=tarif_opcional_ext:present
run1_table[1]=tarif_tarifa_opcional_etiqueta:present
run1_table[2]=tarif_opcional_precio_historial:present
run1_table[3]=tarif_tarifa_opcional_familia:present
run1_table[4]=tarif_tarifa_articulo_opcional:present
run1_table[5]=tarif_tarifa_opcional:present
run1_fk_target[tarif_tarifas]=present
run1_fk_target[catalogo_opcionales]=present
run1_master_pk=composite
run1_master_fks=2
run1_master_ddl=CREATE TABLE `tarif_tarifa_opcional` ( `codtarifa` varchar(20) NOT NULL, `id_opcional` int(11) NOT NULL, `en_catalogo` tinyint(1) NOT NULL DEFAULT 0, `en_tarifa` tinyint(1) NOT NULL DEFAULT 0, `activa` tinyint(1) NOT NULL DEFAULT 0, `orden` int(11) NOT NULL DEFAULT 0, PRIMARY KEY (`codtarifa`,`id_opcional`), KEY `ca_tarif_tarifa_opcional_opcional` (`id_opcional`), CONSTRAINT `ca_tarif_tarifa_opcional_opcional` FOREIGN KEY (`id_opcional`) REFERENCES `catalogo_opcionales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE, CONSTRAINT `ca_tarif_tarifa_opcional_tarifa` FOREIGN KEY (`codtarifa`) REFERENCES `tarif_tarifas` (`codtarifa`) ON DELETE CASCADE ON UPDATE CASCADE ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
run2_master=present
run2_tables_identical=true
run2_ddl_identical=true
SMOKE_RESULT=PASS
```

Result: the master is created FK-safe after the five moved tables, with the
composite PK and both CASCADE FKs; the second ensure is a byte-identical no-op.
The harness lives at `tmp/smoke_opcionales_fresh_install.php` (gitignored) and
the scratch DB is dropped after the run.

### Accepted residual — WARNING-2 (physical boolean defaults)

The live DDL above confirms `en_catalogo`, `en_tarifa` and `activa` are
`tinyint(1) NOT NULL DEFAULT 0` while `model/table/tarif_tarifa_opcional.xml`
declares `TRUE/FALSE/TRUE`. Root cause is the pre-existing framework
`TypeNormalizer` (`boolean` → `TINYINT(1)`, default dropped); the mirrored
`tarif_tarifa_familia` has identical physical defaults. Runtime behavior is
unaffected because application inserts always set the columns explicitly. The
human accepted this residual; **no framework/core file was modified** for it.

### Files

| File | Action | What |
|------|--------|------|
| `plugins/catalogo_core/controller/tarif_opcional_edit.php` | Modified | Bulk matrix pre-validates every price with `parse_price_input()` into `$precios_validados`, reports a targeted error on invalid input, and drops the `floatval(str_replace(...))` parse from the closure |
| `plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php` | Modified | `parse_price_input()` docblock documents the single-separator-as-decimal locale convention |
| `plugins/catalogo_core/model/tarif_opcional.php` | Modified | `search()` orders the per-tarifa listing by master `orden` (default) with a family-scoped override in the SELECT list + ORDER BY; `count_filtered()` uses the same branch. Resolver semantics untouched |
| `plugins/catalogo_core/model/tarif_tarifa_opcional.php` | Modified | Docblock + inline comment mark the full-row `save()` UPDATE as an unreachable defensive fallback (no new callers) |
| `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php` | Modified | New source contract: the bulk matrix uses `parse_price_input()`, no `floatval(`, and reports errors |
| `plugins/catalogo_core/tests/TarifTarifaOpcionalPrecedenceTest.php` | Modified | Reworked the no-`solo_activos` negative and added the master-default + scoped-family effective-order contracts |
| `plugins/catalogo_core/tests/TarifarioOpcionalStateTraitTest.php` | Modified | New explicit locale-convention test for `parse_price_input()` |
| `tmp/smoke_opcionales_fresh_install.php` (project root, gitignored) | Created | Disposable scratch-DB fresh-install smoke harness |
| `plugins/tarifario/controller/tarif_tarifas.php` | Modified | `copy_precios_opcionales()` also copies `porcentaje` and `en_catalogo` |
| `plugins/tarifario/tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php` | Modified | New source contract: the opcional price copy preserves the per-lista columns |

### Notes / residuals

- The bulk matrix's aggregate recompute (`$opcional->en_tarifa`/`en_catalogo`
  from `get_precios_tarifas()`) is unchanged; only the price parse was hardened.
- `count_filtered()` was widened together with `search()` so the per-tarifa
  listing pages against the same row set (both now apply the ext join + ref_sap
  query filter when `codtarifa` is set).
- The family/article scoped order resolvers were intentionally NOT modified
  (`tarif_tarifa_opcional_familia::get_opcionales_activos()` still emits
  `COALESCE(tof.orden, 999999)`); the master default is consumed only by the
  opcionales listing, which is the per-tarifa surface.
- No Composer dependency added; no `vendor/` change.
- Core `openspec/` remains untouched (plugin-local SDD).

### Status

All actionable residuals closed: A, C, D, E, F fixed; B documented; G accepted
(no code). `446/446` catalogo_core tests + `198/198` tarifario tests pass;
PHPStan `188/188 [OK]`; fresh-install smoke `PASS`. Ready for re-verify.

