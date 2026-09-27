# Tasks: caracteristicas-plugin-scope

> Change: `caracteristicas-plugin-scope` — plugin `catalogo_core`
> (`ownership: plugin-local`, `strict_tdd: true`).
> SDD root: `plugins/catalogo_core/openspec/`.
> Implements `proposal.md`, `design.md` (D1–D9) and the delta at
> `specs/caracteristicas-producto/spec.md` (ADD CAR-20; MODIFY CAR-06/11/12/16/17/18/19).
>
> **Boundary.** Every edit target below lives under `plugins/catalogo_core/`.
> No file under `base/`, `src/`, root `controller/`, root `model/`, no file under
> `plugins/tarifario/`, and no entry in the repository-root `openspec/` is created
> or modified. No new Composer dependency, so no `vendor/` commit step is needed.

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~850–1000 (≈110–130 production + ≈700–850 tests, authored additions + deletions) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 → PR 4 (stacked to main, in order) |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

```text
Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High
```

> Rationale: strict TDD adds RED tests before each production change, and two of
> the modified test files (`VentasCaracteristicasControllerTest.php`,
> `VentasArticulosListCaracteristicasTest.php`) are already 300–440 lines. The new
> `CaracteristicaOwnershipTest.php` plus the modifications push the authored diff
> well past 400. Under `auto-chain` the orchestrator proceeds autonomously with
> slice PR 1; no pre-apply decision is required.

### Suggested Work Units

| Unit | Goal | Likely PR / base | Focused test command | Runtime harness | Rollback boundary |
|------|------|------------------|----------------------|-----------------|-------------------|
| 1 | Ownership predicate + resolver choke point (inert definitions absent from the resolver map and its inherited surfaces) | PR 1 / `main` | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CaracteristicaOwnership\|CaracteristicaResolver\|OpcionalVisibility\|CaracteristicaValorStore'` | Real helper with `$GLOBALS['plugins']` seeded to `[]` then `['tarifario']` through the default seam | Revert `Services/CaracteristicaOwnership.php`, `Services/CaracteristicaResolver.php`, `tests/CaracteristicaOwnershipTest.php`, and the resolver-side test edits; the two later wiring sites are untouched |
| 2 | Batch reader filters inert rows with a constant query count; product-list/`listable` columns exclude inert definitions | PR 2 / base = PR 1 branch | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'VentasArticulosListCaracteristicas'` | Real helper + counting DB double, mixed inert/active rows, N=2 vs N=10 | Revert `Services/CaracteristicaValorBatchReader.php` and the list-column test edits; resolver and panel wiring stay |
| 3 | Panel listing hides inert rows; the five direct definition/value actions refuse inert targets and persist nothing | PR 3 / base = PR 2 branch | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'VentasCaracteristicasController'` | Controller anon subclass + spy model/store with `$GLOBALS['plugins'] = []`, crafted CSRF-valid POST per action | Revert `Controller/VentasCaracteristicas.php` and the panel test edits; read paths stay filtered |
| 4 | Import/export + asserted surfaces, boundary gate, cross-path agreement, full regression | PR 4 / base = PR 3 branch | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` then `ddev exec php vendor/bin/phpunit` | Full plugin suite + root suite; boundary token-stripped source scan | Revert the excel/boundaries test edits and the cross-path test addition; production behavior is already delivered by PR 1–3 |

## Phase 1 — Foundation: ownership predicate (D1, D2, D7)

- [x] 1.1 RED — create `plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php`.
  Add a `Tests\CatalogoCore` `TestCase` with a DB-free anonymous subclass of
  `CaracteristicaOwnership` overriding `enabled_plugins()` and a set of cases
  asserting: `is_active('')` ⇒ `true` (CAR-20 c); a name present in the enabled
  set ⇒ `true`; a non-empty name absent from the set ⇒ `false`; surrounding
  whitespace in `origen` is trimmed before the check. Must fail (class not found).
  Test: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CaracteristicaOwnership`.
- [x] 1.2 RED — extend `plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php`
  with CAR-20 (d) fail-closed: with the real helper and
  `$GLOBALS['plugins'] = []` (as pinned by `tests/bootstrap.php` (read-only)),
  every non-empty `origen` is inert while `origen = ''` stays active. Must fail.
- [x] 1.3 GREEN — create `plugins/catalogo_core/Services/CaracteristicaOwnership.php`:
  concrete (not `final`) class
  `FSFramework\Plugins\catalogo_core\Services\CaracteristicaOwnership` with
  `public function is_active(string $origen): bool` (`trim`; `''` ⇒ `true`; else
  `in_array($owner, $this->enabled_plugins(), true)`) and
  `protected function enabled_plugins(): array` returning
  `\FSFramework\Core\Plugins::enabled()`. Add the CAR-20/D7 docblock (fail-closed,
  `origen = ''` unconditional exception, request-stable enabled set per D9).
  Verify: `--filter CaracteristicaOwnership` green.
- [x] 1.4 RED — extend `plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php`
  with the CAR-11 scenario: a plugin-owned definition (non-empty `origen`) is inert
  while its owner is disabled **and** `is_deletable()` is `false`; enabling the
  owner (via `$GLOBALS['plugins']`) makes it active on a **fresh** helper instance
  while still non-deletable. Must fail until the helper exists in the test path.
- [x] 1.5 Verify Phase 1 in isolation: run
  `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CaracteristicaOwnership`
  and record the exact result (all green, no DB access).

## Phase 2 — Resolver choke point (D3, D8, D9; inherited store/excel/D12)

- [x] 2.1 RED — extend `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`:
  a definition with a non-empty `origen` whose owner is not enabled is absent from
  `definitions()` and from `listable_definitions()` / `importable_definitions()` /
  `exportable_definitions()`, and `resolve()` / `resolve_bool()` return `null`
  through the same contract as a missing or inactive definition (CAR-06, CAR-17
  flagged helpers). Assert no value row is created or modified. Use the existing
  `ownership()`-style anon subclass seam. Must fail.
- [x] 2.2 GREEN — modify `plugins/catalogo_core/Services/CaracteristicaResolver.php`:
  `require_once` the ownership helper; add an untyped `protected function ownership()`
  returning a `CaracteristicaOwnership`; add `'origen'` to **both** branches of
  `to_definition()` (object branch reads `$row->origen`; array branch defaults
  `'origen' => ''`); in `definitions()`, skip a row when
  `$definition['codigo'] === ''` **or** when
  `!$this->ownership()->is_active((string) ($definition['origen'] ?? ''))`. Leave
  `flagged_definitions()` unchanged. Verify: `--filter CaracteristicaResolver` green.
- [x] 2.3 RED — extend `plugins/catalogo_core/tests/OpcionalVisibilityDerivationTest.php`:
  when the `en_catalogo`/`en_tarifa` definitions are inert under CAR-20,
  `resolve_opcional_visibility()` resolves "not visible" through the existing
  no-value contract for every tarifa and writes no value row (CAR-12). Must fail.
- [x] 2.4 Verify 2.2 satisfies 2.3:
  `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter OpcionalVisibility` green.
- [x] 2.5 RED — extend `plugins/catalogo_core/tests/CaracteristicaValorStoreTest.php`
  with a recording `definitions()` override: an inert `codigo` makes `assign()`,
  `assign_bool()` and `clear()` return `false` with **zero** scope models created;
  after re-enabling on a **fresh** store instance the write path is restored while
  no row was written during the inert period (CAR-20 b). Must fail until 2.2 lands.
- [x] 2.6 Verify Phase 2 combined:
  `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CaracteristicaResolver|OpcionalVisibility|CaracteristicaValorStore'`.
  The store and the resolver are unchanged in contract (D8): inert simply reuses
  the missing/inactive `null` path.

## Phase 3 — Batch reader: constant-query inert filter (D5; CAR-16 no N+1)

- [x] 3.1 RED — extend `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`:
  an inert `listable` definition emits **no** column; the number of feature queries
  is constant when the page size grows (assert `queries(N=2) === queries(N=10)`)
  with mixed inert/active rows; base header strings stay byte-identical; no tarifa
  selected emits no feature column (CAR-16 owner-disabled scenario). Seed
  `$GLOBALS['plugins'] = []` and use a counting DB double with the real helper
  through the default seam. Must fail.
- [x] 3.2 GREEN — modify `plugins/catalogo_core/Services/CaracteristicaValorBatchReader.php`:
  `require_once` the ownership helper; add an untyped `protected function ownership()`;
  add `origen` to the `definition_rows()` `SELECT`
  (`SELECT id, codigo, nombre, tipo, orden, valor_defecto, origen FROM catalogo_caracteristicas WHERE activo = TRUE ...`);
  return `array_values(array_filter($rows, fn (array $row): bool => $this->ownership()->is_active((string) ($row['origen'] ?? ''))))`.
  Keep it a single PHP pass over the existing one fetch — **no per-row query, no
  SQL `IN (...)` of plugin names, no extra DB round-trip**. Verify:
  `--filter VentasArticulosListCaracteristicas` green, query count unchanged.
- [x] 3.3 Verify Phase 3 in isolation and record that `columns()` and
  `for_referencias()` both inherit the filter (CAR-16, hook context).

## Phase 4 — Panel listing + fail-closed action guards (D4, D6)

- [x] 4.1 RED — extend `plugins/catalogo_core/tests/Controller/VentasCaracteristicasControllerTest.php`:
  with `$GLOBALS['plugins'] = []`, inert rows are absent from `definiciones`; and a
  CSRF-valid, authorized direct POST to each of `save_definition`,
  `delete_definition`, `toggle_flag`, `save_catalogo_valor` and
  `delete_catalogo_valor` targeting an inert definition persists nothing and is
  refused (error reported), including when the request bypasses the rendered
  listing (CAR-18). Also assert an operator row (`origen = ''`) remains listed and
  writable. Must fail.
- [x] 4.2 GREEN — modify `plugins/catalogo_core/Controller/VentasCaracteristicas.php`:
  `require_once` the ownership helper; add an untyped `protected function ownership()`;
  add `private function definition_is_inert($definition): bool` returning
  `!$this->ownership()->is_active((string) ($definition->origen ?? ''))`; filter
  `load_definiciones()` with `array_values(array_filter(...))` over
  `caracteristica_model()->all()`. Leave `View/ventas_caracteristicas.html.twig`
  unchanged (read-only).
- [x] 4.3 GREEN — add the guards after CSRF/target resolution, before any mutation:
  `save_definition` (after resolving `$existing`, before mutating);
  `delete_definition` (after resolving `$definition`, before the permission check);
  `toggle_flag` (after resolving `$definition`, before toggling);
  `save_catalogo_valor` (after resolving the target definition by
  `id_caracteristica` via `get_by_id()`, else by `codigo` via `get()`, before
  `$valor->save()`); `delete_catalogo_valor` (after loading the value row,
  resolving its `id_caracteristica` via the definition model's `get_by_id()`,
  before `$valor->delete()`). Each guard emits `new_error_msg(...)` and returns,
  persisting nothing. Do **not** add guards to `assign_value`/`clear_value`: they
  are refused transitively by `CaracteristicaValorStore` (D6). A brand-new
  definition never reads `origen`, so it is always operator-owned and active.
- [x] 4.4 Verify Phase 4: `--filter VentasCaracteristicasController` green, and
  confirm each refusal left zero rows written.

## Phase 5 — Inherited surfaces, boundary gate, cross-path agreement

- [x] 5.1 RED — extend `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php`:
  an owner-disabled `importable`/`exportable` definition contributes no import
  field, no alias and no export column, and the base headers stay byte-identical
  (CAR-17 owner-disabled scenario). The behavior is inherited from the Phase 2
  resolver filter; the test fails until Phase 2 is present.
- [x] 5.2 GREEN/verify 5.1 passes against the Phase 2 production code; no wizard
  production change is expected. If it fails, the fix belongs in the resolver
  filter (Phase 2), not in the wizard.
- [x] 5.3 RED — extend `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php`
  with the source-scan gate (CAR-19, CAR-20 e): token-stripped source of
  `plugins/catalogo_core/Services/CaracteristicaOwnership.php` references only the
  row `origen` and `\FSFramework\Core\Plugins`, contains no `FSFramework\Plugins\tarifario`
  namespace and no hardcoded plugin-name literal; the three wiring sites
  (`CaracteristicaResolver.php`, `Controller/VentasCaracteristicas.php`,
  `CaracteristicaValorBatchReader.php`) call the shared rule; and
  `openspec/changes/caracteristicas-plugin-scope` (read-only) does not exist at the
  repository root. Keep the existing frozen tarifario-reference baseline unchanged.
  Must fail until the scan helpers/assertions are added and Phase 1–4 are in place.
- [x] 5.4 RED — add `test_all_three_read_paths_agree_on_the_active_set()`
  to `plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php`: one shared
  fixture (operator row `origen = ''` + plugin row `origen = 'tarifario'`) fed to
  (1) a resolver with a fixture definition model, (2) the panel
  `load_definiciones()` with a fixture definition model and (3) the batch reader
  with a fixture DB; the three active `codigo` sets must be identical for the
  disabled state and again for the re-enabled state (build fresh instances after
  the toggle, per D9). Must fail until all three read paths are wired.
- [x] 5.5 Audit asserted-green fixtures without editing them: run
  `plugins/catalogo_core/tests/CaracteristicaDefaultsTest.php` (read-only),
  `plugins/catalogo_core/tests/CaracteristicaModelTest.php` (read-only),
  `plugins/catalogo_core/tests/CaracteristicaHookContextTest.php` (read-only) and
  `plugins/catalogo_core/tests/Services/ArticuloExcelFeaturePersistenciaTest.php`
  (read-only); they must stay green because their fixtures omit `origen` (⇒ `''`)
  or read the model/registry directly.

## Phase 6 — Verification and boundary confirmation

- [x] 6.1 Run the plugin suite and record the exact result:
  `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`.
- [x] 6.2 Run the root suite for regression and record the exact result:
  `ddev exec php vendor/bin/phpunit`.
- [x] 6.3 Run the plugin linter declared in `plugins/catalogo_core/openspec/config.yaml`
  (read-only): `ddev exec composer phpstan`. Never weaken types or delete tests to
  satisfy it.
- [x] 6.4 Confirm the boundary from the applied tree: `git status`/`git diff --stat`
  shows changed files only under `plugins/catalogo_core/`; `plugins/tarifario/`
  (read-only) and the repository-root `openspec/` (read-only) are untouched;
  `plugins/catalogo_core/openspec/changes/caracteristicas-plugin-scope/specs/caracteristicas-producto/spec.md`
  (read-only) and the canonical spec
  `plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md`
  (read-only) are not modified in this phase.
- [x] 6.5 Confirm the constant-query contract explicitly: the CAR-16 test asserts
  the feature query count is independent of page size N and of the number of inert
  rows (one PHP pass over the existing single fetch; no per-row query, no extra
  round-trip).

## Phase 7 — Remediation (W1)

> Closes the verify WARNING W1: `CaracteristicaBackfillMigration::migrateIfNeeded()`
> is called on every boot (`Init.php:96` init, `:131` upgrade) and could
> `INSERT … WHERE NOT EXISTS` mirror value rows for a definition whose owning
> plugin is not enabled (inert under CAR-20), contradicting CAR-20's absolute
> "no writes during the inert period". This makes the migration CAR-20-aware via
> the existing `CaracteristicaOwnership` predicate. It does not touch the delta
> spec or the canonical spec (the requirement already states the invariant).

- [x] 7.1 RED — extend `plugins/catalogo_core/tests/CaracteristicaBackfillTest.php`
  proving that an inert definition (non-empty `origen` whose owner plugin is not
  enabled, `$GLOBALS['plugins'] = []`) causes the backfill to write **zero** rows,
  and that re-enabling the owner (`$GLOBALS['plugins'] = ['tarifario']`) lets a
  fresh run proceed normally with no writes having occurred during the inert
  period. Triangulate: an operator-owned definition (`origen = ''`) still
  backfills with an empty registry, and the skip is per-definition (one active +
  one inert writes only the active one). Must fail against the non-CAR-20-aware
  migration.
- [x] 7.2 GREEN — make `plugins/catalogo_core/Services/CaracteristicaBackfillMigration.php`
  CAR-20-aware using `CaracteristicaOwnership` (same rule as the rest of the
  change: `origen = ''` ⇒ active, otherwise the framework enabled-plugin
  registry): resolve the definition `origen`, skip inert definitions before any
  `INSERT`, and keep the migration additive-only, idempotent and
  non-destructive. Do not change the legacy-column mapping semantics for active
  definitions, do not hardcode a plugin literal, and do not import any
  `tarifario` namespace (CAR-19). Verify: `--filter CaracteristicaBackfill` green.
- [x] 7.3 Verify — run the plugin suite and record the exact result:
  `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`; then
  re-confirm the boundary (all changes under `plugins/catalogo_core/`, no
  `plugins/tarifario/` change, no core change, no repository-root `openspec/`
  entry).

## Out of scope (do not add tasks)

- Deletion of definitions.
- The "no delete button" rendering observation in CAR-11 /
  `plugins/catalogo_core/View/ventas_caracteristicas.html.twig` (read-only).
- Any change under `plugins/tarifario/` (read-only).
- The optional tarifario-side `R-TAR-HOOK-007` spec follow-up.
- Adding guards to `assign_value` / `clear_value` (refused transitively).
- Any new Composer dependency or `vendor/` commit.
