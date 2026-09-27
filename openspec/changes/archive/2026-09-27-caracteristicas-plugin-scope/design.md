# Design: caracteristicas-plugin-scope

> Change: `caracteristicas-plugin-scope` — plugin `catalogo_core`, SDD root
> `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`).
> Artifact store: `openspec`. This design implements the proposal at
> `proposal.md` and the delta spec at
> `specs/caracteristicas-producto/spec.md` (ADD CAR-20; MODIFY CAR-06/11/12/16/17/18/19).
>
> **Boundary statement (CAR-19).** Every production and test file named in this
> design lives under `plugins/catalogo_core/`. No file under `base/`, `src/`,
> `controller/` or `model/` (repository root), no file under `plugins/tarifario/`,
> and no entry in the repository-root `openspec/` is created or modified. No new
> Composer dependency is introduced.

## Technical Approach

Adopt the exploration's **Approach B**: one small, plugin-agnostic ownership
predicate consumed by all three independent definition read paths, plus
fail-closed guards on the panel's direct definition/value actions.

The predicate answers one question — *is this definition's owner enabled?* — from
two inputs only: the row's `origen` and the framework plugin registry
(`FSFramework\Core\Plugins`). It is wired into:

1. `CaracteristicaResolver::definitions()` — the effective-value choke point, so
   `resolve()`, `resolve_bool()`, `resolve_opcional_visibility()`,
   `listable_definitions()` / `importable_definitions()` / `exportable_definitions()`
   and `CaracteristicaValorStore::definitions()` all inherit the rule.
2. `VentasCaracteristicas::load_definiciones()` — the management-panel listing.
3. `CaracteristicaValorBatchReader::definition_rows()` — the batched path behind
   the product-list visibility columns, the CAR-16 `listable` columns and the hook
   context.

The panel's definition-level actions (`save_definition`, `delete_definition`,
`toggle_flag`, `save_catalogo_valor`) and its predefined-value delete
(`delete_catalogo_valor`) additionally resolve their target and refuse an inert
one. Value assignment (`assign_value`, `clear_value`) is refused transitively: it
goes through `CaracteristicaValorStore`, whose `definitions()` is now the
resolver-filtered map, so an inert `codigo` resolves to `null` and the store
returns `false` before any write.

Everything is additive and non-destructive: no schema change, no data migration,
no new return value. Inert definitions and their assigned values are preserved
untouched and restored the moment the owning plugin is re-enabled.

## Architecture Decisions

### Decision D1: One ownership helper — `CaracteristicaOwnership`

**Choice**:

- Class: `FSFramework\Plugins\catalogo_core\Services\CaracteristicaOwnership`
  (concrete class, **not** `final`, so its seam can be overridden in tests).
- File: `plugins/catalogo_core/Services/CaracteristicaOwnership.php`.
- Contract:

  ```php
  public function is_active(string $origen): bool
  {
      $owner = trim($origen);
      if ($owner === '') {
          return true; // operator-created row: unconditional exception
      }

      return in_array($owner, $this->enabled_plugins(), true);
  }

  /** @return list<string> */
  protected function enabled_plugins(): array
  {
      return \FSFramework\Core\Plugins::enabled();
  }
  ```

- `\FSFramework\Core\Plugins::enabled()` returns `$GLOBALS['plugins'] ?? []`, i.e.
  exactly the set `\FSFramework\Core\Plugins::isEnabled($origen)` tests against
  (`src/Core/Plugins.php:72-80`). The local `in_array` is the literal body of
  `isEnabled()`; the predicate is the faithful decomposition of
  `origen === '' ? true : Plugins::isEnabled($origen)`.

**Alternatives considered**:

- `is_active()` delegating directly to `Plugins::isEnabled($origen)` with the seam
  being `plugin_enabled(string): bool`. Rejected: the seam then models a per-name
  boolean rather than "the enabled set", which is more awkward to seed in a test
  and duplicates `Plugins::isEnabled()`.
- A `CaracteristicaOwnershipInterface` + implementation. Rejected: two types for
  one three-line predicate; the codebase already uses concrete classes with
  protected seams (`CaracteristicaResolver`, `CaracteristicaValorStore`).
- `final class`. Rejected: `final` forbids the overridable test seam.

**Rationale**: minimal surface, zero core coupling beyond the already-public
registry, generic by construction (reads the row's `origen`, never a plugin
name), and cheap on the hot paths (a single `in_array` over a small list).

### Decision D2: Test seam — `protected function enabled_plugins(): array`

**Choice**: the helper exposes one overridable protected method returning the
enabled list; every wiring site exposes a `protected function ownership()` seam
returning a helper instance (untyped, like the existing `db()` / `definition_model()`
/ `valor_store()` seams), so tests can inject a helper with a controlled set.

Two control mechanisms, both DB-free:

- **Integration (preferred)**: set `$GLOBALS['plugins']` in the test and use the
  real helper through the default seam. `tests/bootstrap.php:74-76` already pins
  `$GLOBALS['plugins'] = []`, and `plugins/catalogo_core/phpunit.xml` sets
  `processIsolation="true"`, so a test can safely mutate it without leaking.
  This exercises the real `Plugins::enabled()` path and is the strongest proof of
  the fail-closed behavior.
- **Unit**: an anonymous subclass overrides `enabled_plugins()` and returns a
  fixed list, pinning `is_active()` in isolation without touching globals.

**Alternatives considered**: a static override / static mutable set on the helper
(rejected: hidden global state, harder to reset, against the codebase's instance
seam convention); an interface injection only (rejected: heavier than needed).

**Rationale**: matches the established seam style (`CaracteristicaResolverTest`,
`VentasCaracteristicasControllerTest`, `ArticuloExcelCaracteristicaTest` all use
anonymous subclasses with protected-method overrides), production-cheap (one
method call per definition), and no new static state.

### Decision D3: `CaracteristicaResolver::definitions()` is the single value choke point

**Choice**: add `'origen'` to the normalized definition shape and drop inert
definitions while building the map:

```php
foreach ((array) $this->definition_model()->all($onlyActive) as $row) {
    $definition = $this->to_definition($row);
    if ($definition['codigo'] === '') {
        continue;
    }
    if (!$this->ownership()->is_active((string) ($definition['origen'] ?? ''))) {
        continue; // CAR-20: owner-disabled definitions are inert
    }
    $map[$definition['codigo']] = $definition;
}
```

`to_definition()` gains `'origen'` in both branches (object rows read
`$row->origen`; array rows default to `''` via `$row + [..., 'origen' => '']`).

`flagged_definitions()` needs **no change**: it iterates `$this->definitions()`,
so `listable_definitions()`, `importable_definitions()` and
`exportable_definitions()` inherit the filter.

The `origen` filter is orthogonal to `onlyActive`: it applies to both the
`'active'` and `'all'` cache keys.

**Alternatives considered**: filtering only in `resolve()` (rejected: leaves the
flagged helpers and the store unfiltered, so writes and import/export would still
see inert definitions); filtering inside the model `fetch_list()` (Approach C,
rejected in exploration: couples the data model to the registry and still misses
the raw-SQL batch reader).

**Rationale**: one change covers the widest blast radius (effective values, typed
values, D12 visibility, flagged helpers, the write path, import wizard) with no
call-site contract change — an inert definition simply becomes absent from the
map, which is the existing "missing or inactive" path.

### Decision D4: `VentasCaracteristicas::load_definiciones()` filters the raw listing

**Choice**:

```php
protected function load_definiciones(): void
{
    $definiciones = (array) $this->caracteristica_model()->all();
    $this->definiciones = array_values(array_filter(
        $definiciones,
        fn ($def): bool => $this->ownership()->is_active((string) ($def->origen ?? ''))
    ));
}
```

`View/ventas_caracteristicas.html.twig` is **unchanged**: once the controller
filters `definiciones`, the listing renders only active rows. The CAR-11
delete-button observation is untouched (out of scope).

**Alternatives considered**: filtering in the view (rejected: logic in a template,
untestable at the controller contract level).

**Rationale**: the panel is the one read path that bypasses the resolver; a
one-line filter at the source keeps the view a pure renderer.

### Decision D5: `CaracteristicaValorBatchReader::definition_rows()` adds `origen` and filters in PHP

**Choice**:

```php
$sql = 'SELECT id, codigo, nombre, tipo, orden, valor_defecto, origen FROM catalogo_caracteristicas'
    . ' WHERE activo = TRUE';
// ... unchanged listable / codigo IN (...) predicates ...

$rows = (array) $this->db()->select($sql);

return array_values(array_filter(
    $rows,
    fn (array $row): bool => $this->ownership()->is_active((string) ($row['origen'] ?? ''))
));
```

`columns()` and `for_referencias()` both consume `definition_rows()`, so all
batched surfaces (product-list visibility columns, CAR-16 `listable` columns, hook
context) inherit the filter.

**Query count is constant.** The enabled set is runtime-only and not expressible
in SQL, so the filter runs as one PHP pass over the single existing fetch. The
number of feature queries is unchanged and stays independent of the page size N
and of the number of inert rows; `in_array` is O(enabled), not a DB round-trip.

**Alternatives considered**: `AND origen = ''` in SQL (rejected: would hide
*enabled* plugin-owned definitions too); an `IN (...)` of enabled plugin names
(rejected: same reason, plus it embeds runtime state into SQL and duplicates the
registry); per-row ownership queries (rejected: N+1, violates CAR-16).

**Rationale**: preserves the reader's constant-query contract while making it
agree with the resolver and the panel.

### Decision D6: Panel action guards — refuse with a user-visible error (fail-closed)

**Choice**: a private helper and per-action guards:

```php
private function definition_is_inert($definition): bool
{
    return !$this->ownership()->is_active((string) ($definition->origen ?? ''));
}
```

| Action | Guard placement | Behavior on an inert target |
|--------|-----------------|-----------------------------|
| `save_definition` | after resolving `$existing`, before mutating it | `new_error_msg(...)`, return; nothing saved |
| `delete_definition` | after resolving `$definition`, before permission | `new_error_msg(...)`, return (kept alongside the existing `is_deletable()` refusal) |
| `toggle_flag` | after resolving `$definition`, before toggling | `new_error_msg(...)`, return; nothing saved |
| `save_catalogo_valor` | after resolving the target definition (by `id_caracteristica` via `get_by_id()`, else by `codigo` via `get()`), before `$valor->save()` | `new_error_msg(...)`, return; nothing saved |
| `delete_catalogo_valor` | after loading the value row, resolving its `id_caracteristica` via `get_by_id()`, before `$valor->delete()` | `new_error_msg(...)`, return; nothing deleted |
| `assign_value` / `clear_value` | none added — transitive via `CaracteristicaValorStore::definitions()` | store returns `false` (inert `codigo` absent from the map); existing "No se pudo asignar/eliminar el valor" path; no write |

A **new** definition can never be inert at creation time: `save_definition` never
reads `origen` from the request, so a new model gets `origen = ''` (operator-owned,
always active). The guard only ever triggers for an existing plugin-owned row.

**User-visible vs silent**: the guards emit `new_error_msg()` rather than silently
ignoring. Rationale: consistent with every other refusal in this controller
(CSRF, permission, non-deletable), auditable, and directly assertable in tests.
The listing is silent by nature (an inert row is simply absent).

**Scope note**: the proposal's "Inert means inert" bullet lists four actions; the
delta spec CAR-18 says "value assignment **or delete**". This design follows the
spec and adds `delete_catalogo_valor`, which is the one value path that bypasses
the store and would otherwise delete a predefined value of an inert definition.
This is a deliberate, minimal superset, not scope creep: no tarifario file, no
schema, no deletion of definitions.

**Alternatives considered**: silent ignore (weaker observability, harder to test);
guarding only the four proposal actions (leaves `delete_catalogo_valor` as a real
hole against "inert means inert"); adding explicit controller guards to
`assign_value`/`clear_value` (redundant — the store already refuses, and the extra
queries/duplication are not justified).

**Rationale**: fail-closed at every entry point that can mutate a definition row,
reusing the store's existing refusal for the value-store paths.

### Decision D7: Fail-mode — registry unavailable ⇒ plugin-owned rows inert

**Choice**: `Plugins::enabled()` returns `$GLOBALS['plugins'] ?? []`, so an
unpopulated registry yields an empty enabled set: every non-empty `origen`
definition is inert, and `origen = ''` is the unconditional exception. No new
branch, no defensive default. In normal requests `base/config2.php:116-128`
populates the registry before any controller/model runs.

**Alternatives considered**: treating an empty registry as "everyone enabled"
(fail-open — rejected: a transient registry failure would resurrect plugin-owned
definitions the operator cannot see, the opposite of the confirmed decision);
throwing (rejected: turns a visibility rule into a boot failure).

**Rationale**: the confirmed decision is inert-is-correct; fail-closed is the
safe direction and falls out of the registry's own semantics. It is made testable
by the empty default in `tests/bootstrap.php` and by direct `$GLOBALS['plugins']`
control.

### Decision D8: No call-site contract change — reuse the existing `null` path

**Choice**: `resolve()` needs **no change**. Its first line already returns `null`
when `definitions()[$codigo] ?? null` is absent (`CaracteristicaResolver.php:129-132`).
An owner-disabled definition is now absent from that map, so it takes the exact
existing missing/inactive path: `resolve()` → `null`, `resolve_bool()` → `null`.

This is verified safe at every consumer (no new return value, no new error, no
write):

| Consumer | `null` handling today (unchanged) |
|----------|-----------------------------------|
| `CaracteristicaResolver::resolve_bool()` | `to_bool(null)` → `null` |
| `VentasArticulosListTrait::articulo_visibility_bool()` | `null` → `false` (`:355-357`) |
| `VentasOpcionalesListTrait` D12 union | every parent `null` → `union_bool` → `null`; caller casts `(bool)` → `false` |
| `Controller/VentasArticulo::persist_articulo_visibility()` | store `assign_bool` → `false` for absent `codigo`; best-effort, already documented (`:614-648`) |
| `CaracteristicaValorStore::assign()/assign_bool()/clear()` | absent `codigo` → `return false`, no write |
| `ArticuloExcelImportWizardService::load_importable_definitions()` | inert defs absent from `importable_definitions()` |
| `CaracteristicaHookContextTrait::caracteristicas_context()` | inert codigos absent from `definitions()` keys and from the batch read |

**Rationale**: the change is purely subtractive on the active-set boundary; no
consumer learns a new contract, which is what keeps CAR-06/CAR-12/CAR-16/CAR-17
BEHAVIOR-preserving except for the intended inert case.

### Decision D9: Caching is safe because the enabled set is request-stable

`definitions()` memoizes per resolver instance (`definitionCache`) and the helper
holds no cache. The enabled set is fixed for the lifetime of a request (a plugin
toggle requires a new request / re-bootstrap), so caching the filtered map is
correct. A test that toggles the enabled set within one case must build a **fresh**
resolver/controller/reader instance after the toggle; the re-enable scenario
asserts behavior on a new instance. Documented in the helper docblock.

## Data Flow

Read paths (all three now consult `CaracteristicaOwnership::is_active(origen)`):

```
                         catalogo_caracteristicas (row.origen)
                                       │
        ┌──────────────────────────────┼──────────────────────────────┐
        │                              │                              │
 [1] Panel                     [2] Resolver                  [3] Batch reader
 VentasCaracteristicas         CaracteristicaResolver        CaracteristicaValorBatchReader
 load_definiciones()           definitions()                 definition_rows()
   model->all()                  model->all($onlyActive)       SELECT ... origen ...
        │                              │                              │
        └── filter is_active(origen) ──┴── filter is_active(origen) ──┴── filter is_active(origen) (PHP)
        │                              │                              │
   definiciones[]                definitions map                rows[] (columns + values)
        │                              │                              │
   view listing                resolve()->null (inert)         listable columns / hook ctx
                                resolve_bool()->null
                                flagged helpers drop inert
                                       │
                              CaracteristicaValorStore::definitions()
                                       │
                             assign/clear -> false (no write)
```

Panel write path (fail-closed):

```
POST action=save_definition|delete_definition|toggle_flag|save_catalogo_valor|delete_catalogo_valor
        │
   validateFormToken()  ── fail ──> error, nothing persisted
        │ ok
   resolve target definition (get / get_by_id)
        │
   definition_is_inert(target)? ── yes ──> new_error_msg(...), return (nothing persisted)
        │ no
   existing permission / is_deletable / save logic (unchanged)
```

Re-enable path (no writes during the inert period):

```
$GLOBALS['plugins'] = []            → definition inert: absent from all three paths, resolve()===null,
                                      store.assign_bool()===false (no row written/deleted)
$GLOBALS['plugins'] = ['tarifario'] → NEW request -> definition and its values returned unchanged
                                      (rows were never touched)
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `plugins/catalogo_core/Services/CaracteristicaOwnership.php` | Create | `CaracteristicaOwnership::is_active(string $origen): bool` + `protected enabled_plugins(): array` seam. Reads `origen` + `\FSFramework\Core\Plugins`. |
| `plugins/catalogo_core/Services/CaracteristicaResolver.php` | Modify | `require_once` the helper; add `protected ownership()`; add `'origen'` to `to_definition()`; skip inert rows in `definitions()`. `flagged_definitions()` unchanged. |
| `plugins/catalogo_core/Controller/VentasCaracteristicas.php` | Modify | `require_once` the helper; add `protected ownership()` and `private definition_is_inert()`; filter `load_definiciones()`; guard `save_definition`, `delete_definition`, `toggle_flag`, `save_catalogo_valor`, `delete_catalogo_valor`. |
| `plugins/catalogo_core/Services/CaracteristicaValorBatchReader.php` | Modify | `require_once` the helper; add `protected ownership()`; add `origen` to the `definition_rows()` SELECT and filter in PHP (constant query count). |
| `plugins/catalogo_core/View/ventas_caracteristicas.html.twig` | Unchanged | Listing is filtered in the controller; CAR-11 delete-button observation untouched. |
| `plugins/catalogo_core/Services/CaracteristicaValorStore.php` | Unchanged (inherited) | Refuses inert `codigo` via resolver-filtered `definitions()`. |
| `plugins/catalogo_core/Services/ArticuloExcelImportWizardService.php` | Unchanged (inherited) | Inert defs absent from `importable_definitions()`. |
| `plugins/catalogo_core/extras/VentasArticulosListTrait.php` | Unchanged (asserted) | Product-list visibility `null` ⇒ `false`. |
| `plugins/catalogo_core/extras/VentasOpcionalesListTrait.php` | Unchanged (asserted) | D12 visibility `null` ⇒ not visible. |
| `plugins/catalogo_core/Controller/VentasArticulo.php` | Unchanged (asserted) | Persist no-ops for inert definitions (existing best-effort write). |
| `plugins/catalogo_core/extras/CaracteristicaHookContextTrait.php` | Unchanged (asserted) | Hook context excludes inert codigos. |
| `plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php` | Create | Predicate unit tests + fail-closed + operator exception + re-enable + three-path agreement. |
| `plugins/catalogo_core/tests/CaracteristicaResolverTest.php` | Modify | Owner-disabled ⇒ `null` like missing/inactive; flagged helpers drop inert. |
| `plugins/catalogo_core/tests/Controller/VentasCaracteristicasControllerTest.php` | Modify | Inert rows absent from listing; direct actions refused and persist nothing. |
| `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php` | Modify | Inert `listable` emits no column; query count constant across N. |
| `plugins/catalogo_core/tests/CaracteristicaValorStoreTest.php` | Modify | Inert `codigo` writes nothing; re-enable restores the write path; no row touched during inert period. |
| `plugins/catalogo_core/tests/OpcionalVisibilityDerivationTest.php` | Modify | Inert visibility definitions ⇒ not visible, no write. |
| `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php` | Modify | Owner-disabled definition adds no import field / alias / export column; base headers byte-identical. |
| `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php` | Modify | Ownership helper uses the registry + `origen` with no tarifario coupling; the three sites call the shared rule; no root openspec entry for this change. |
| `plugins/catalogo_core/tests/CaracteristicaDefaultsTest.php`, `CaracteristicaModelTest.php`, `CaracteristicaHookContextTest.php`, `Services/ArticuloExcelFeaturePersistenciaTest.php` | Asserted (likely green) | Fixtures omit `origen` (⇒ `''`) or read the registry/model directly, so they stay active; audit and keep green. |

No file outside `plugins/catalogo_core/` changes.

## Interfaces / Contracts

### New helper

```php
<?php
declare(strict_types=1);

namespace FSFramework\Plugins\catalogo_core\Services;

use FSFramework\Core\Plugins;

/**
 * CAR-20 — enabled-plugin ownership scope for feature definitions.
 *
 * Active when `origen` is empty, or when the non-empty `origen` names a plugin
 * the framework registry reports as enabled. Plugin-agnostic: reads only the
 * row's `origen` and \FSFramework\Core\Plugins, never a plugin name.
 *
 * Fail-closed: an unavailable registry (empty $GLOBALS['plugins']) makes every
 * plugin-owned definition inert. `origen = ''` is the unconditional exception.
 */
class CaracteristicaOwnership
{
    public function is_active(string $origen): bool
    {
        $owner = trim($origen);
        if ($owner === '') {
            return true;
        }

        return in_array($owner, $this->enabled_plugins(), true);
    }

    /**
     * Enabled-plugin registry seam (overridable in DB-free tests).
     *
     * @return list<string>
     */
    protected function enabled_plugins(): array
    {
        return Plugins::enabled();
    }
}
```

### Wiring seams (signatures only)

```php
// CaracteristicaResolver
protected function ownership()              // @return CaracteristicaOwnership
private function to_definition($row): array // now includes ['origen' => string]

// VentasCaracteristicas (Controller)
protected function ownership()              // @return CaracteristicaOwnership
private function definition_is_inert($definition): bool

// CaracteristicaValorBatchReader
protected function ownership()              // @return CaracteristicaOwnership
private function definition_rows(?array $codigos): array // SELECT gains `origen`; PHP filter
```

All `ownership()` seams are untyped (docblock-typed) to match the existing
`db()`, `definition_model()`, `valor_store()` test-double seams.

## Testing Strategy

Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
(`processIsolation="true"`; root suite for regression).

| Layer | What to test | Approach |
|-------|--------------|----------|
| Unit (helper) | `is_active`: `''` ⇒ true; enabled ⇒ true; disabled ⇒ false; fail-closed with empty registry; `origen` trimmed | Anon subclass overriding `enabled_plugins()` + real helper with `$GLOBALS['plugins']` |
| Unit (resolver) | Inert definition absent from `definitions()` and all flagged helpers; `resolve()`/`resolve_bool()` ⇒ `null` like missing/inactive; no writes | `CaracteristicaResolverTest` anon subclass, `ownership()` controlled |
| Unit (store) | Inert `codigo` ⇒ `assign_*`/`clear` return `false`, zero scope models; re-enable restores the write | `CaracteristicaValorStoreTest` with a recording `definitions()` override |
| Integration (batch) | Inert `listable` emits no column; query count independent of N with mixed inert/active rows | Counting DB double + real helper with `$GLOBALS['plugins']` |
| Integration (panel) | Inert rows absent from listing; each direct action refused and persists nothing | Controller anon subclass with spy model/store; `$GLOBALS['plugins']=[]` |
| Integration (D12) | Inert visibility definitions ⇒ not visible, no writes | `OpcionalVisibilityDerivationTest` with `origen`-seeded definition |
| Integration (excel) | Inert definition adds no import/alias/export entry; base headers byte-identical | `ArticuloExcelCaracteristicaTest` with resolver-filtered (empty) defs |
| Boundary (source) | Helper reads `origen` + `FSFramework\Core\Plugins`; no tarifario namespace/literal; three sites call the rule; no root openspec entry | `CaracteristicaBoundariesTest` token-stripped source scan |

### Per-delta-scenario → test mapping (strict TDD)

RED is written first for every row; GREEN only after the production change.

| Spec scenario | Test file | Test intent |
|---------------|-----------|-------------|
| CAR-20 (a) owner disabled ⇒ inert everywhere | `CaracteristicaOwnershipTest`, `VentasCaracteristicasControllerTest`, `CaracteristicaResolverTest`, `VentasArticulosListCaracteristicasTest` | Absent from listing/columns; no import/export field; `resolve()` ⇒ `null` |
| CAR-20 (b) re-enable restores, no writes | `CaracteristicaOwnershipTest`, `CaracteristicaValorStoreTest` | Fresh instance after toggle returns the definition/values unchanged; zero scope models created during the inert period |
| CAR-20 (c) operator row unaffected | `CaracteristicaOwnershipTest` | `origen=''` active with any enabled set, including empty |
| CAR-20 (d) registry unavailable fails closed | `CaracteristicaOwnershipTest` | Empty `$GLOBALS['plugins']` ⇒ non-empty `origen` inert, `''` active |
| CAR-20 (e) no tarifario coupling | `CaracteristicaBoundariesTest` | Helper source: registry + `origen`, no `FSFramework\Plugins\tarifario`, no `'tarifario'` literal |
| CAR-06 owner-disabled = missing/inactive | `CaracteristicaResolverTest` | Three-way: inert, missing, inactive all `null`; no row written |
| CAR-11 plugin-owned inert, non-deletable, restorable | `CaracteristicaOwnershipTest` | `is_active('tarifario')===false` while `is_deletable()===false`; enabling makes it active, still non-deletable |
| CAR-12 owner-disabled visibility ⇒ not visible | `OpcionalVisibilityDerivationTest` | `resolve_opcional_visibility(...)===null` for any tarifa; no write |
| CAR-16 inert `listable` ⇒ no column; constant query count | `VentasArticulosListCaracteristicasTest` | `columns()` excludes inert; `queries(N=2) === queries(N=10)` |
| CAR-17 inert ⇒ no import field/alias/export column | `CaracteristicaResolverTest` (flagged helpers), `ArticuloExcelCaracteristicaTest` | Flagged helpers empty; wizard field catalog/options/export append nothing, base headers byte-identical |
| CAR-18 listing hidden + direct actions refused | `VentasCaracteristicasControllerTest` | Absent from `definiciones`; `save_definition`/`toggle_flag`/`delete_definition`/`save_catalogo_valor`/`delete_catalogo_valor` persist nothing |
| CAR-19 no core change / dependency direction / no coupling | `CaracteristicaBoundariesTest` | Existing baseline scan + new helper assertion + no `openspec/changes/caracteristicas-plugin-scope` at root |

### Cross-layer agreement (Success Criteria)

`CaracteristicaOwnershipTest::test_all_three_read_paths_agree_on_the_active_set()`:
one shared fixture (operator row `origen=''` + plugin row `origen='tarifario'`) fed
to (1) a resolver with a fixture definition model, (2) the panel `load_definiciones()`
with a fixture definition model, and (3) the batch reader with a fixture DB; all
three active `codigo` sets must be identical for the same enabled state (disabled
and re-enabled). This is the "single-rule consistency" proof.

### Existing-suite regression audit

- `CaracteristicaDefaultsTest` reads `CaracteristicaRegistry::DEFAULTS` and a spy
  DB (not the resolver) → unaffected.
- `CaracteristicaModelTest` exercises the model read methods directly; the model
  is **not** filtered (Approach B) → unaffected.
- Every other definition fixture omits `origen` (⇒ `''`) or overrides
  `definitions()`/uses a fake resolver → active → green.
- `CaracteristicaHookContextTest` uses a fake resolver and fake batch reader →
  unaffected.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file
classification, or process-integration boundary. The change adds an
authorization/visibility decision on an existing POST surface; it is covered by
the CAR-18 fail-closed guard tests (crafted POST to an inert target persists
nothing) rather than by a process/routing threat matrix.

Security note: the guards are **access decisions, not just UX**. A request that
bypasses the rendered listing and POSTs directly to an action targeting an inert
definition is refused before any model mutation, and CSRF validation still runs
first in every mutating action (unchanged).

## Migration / Rollout

No migration required: no schema change, no data change, no backfill. The rule is
pure read-path scoping plus write refusal; it activates immediately on deploy and
is reversible at runtime by toggling the owning plugin's state.

No feature flag. The behavior is intended and unconditional; a `catalogo_core`-only
install (tarifario disabled) resolves the D12 visibility as "not visible", which
is the accepted consequence (asserted by tests, not assumed).

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| Crafted POST mutates an inert definition if only the listing is filtered | Med | Guard all definition/value actions (D6); test each refusal persists nothing |
| Registry unavailable in CLI/test ⇒ all plugin-owned definitions inert | Med | Documented fail-closed (D7); `tests/bootstrap.php` pins `$GLOBALS['plugins']=[]`; `config2.php` populates it in real requests; operator rows are the unconditional exception |
| D12 opcional visibility changes in a catalogo_core-only install | High (intended) | Accepted; asserted `null` ⇒ not visible with no write |
| Existing fixtures with non-empty `origen` now go inert | Med | Audited: `CaracteristicaDefaultsTest` / `CaracteristicaModelTest` do not read through the filtered paths; all others omit `origen` |
| Batch-reader regression (per-row query) | Low | Filter is one PHP pass; N-independence asserted; add `origen` to the existing single fetch |
| Boundary violation (hardcoded plugin / tarifario import) | Low | Helper reads only `origen` + `Plugins`; `CaracteristicaBoundariesTest` extended |
| Cache returns a stale active set after an in-process toggle | Low | Enabled set is request-stable (D9); tests build a fresh instance after toggling |
| Scope creep into deletion / delete-button observation | Med | Both out of scope; only the five named actions are guarded |

## Rollback Plan

- **Code.** Revert the commit. The change is additive and plugin-local: one new
  helper, three wiring sites, the controller guards. No consumer contract changed
  (D8), so a revert restores the previous "list everything" behavior exactly.
- **Data.** Zero destructive operations. Inert rows and their values are never
  deleted or mutated. Re-enabling the owning plugin restores the definition and
  its values with no writes, so the change is reversible at runtime by toggling
  plugin state alone (the emergency path before a redeploy).
- **Spec.** The canonical `openspec/specs/caracteristicas-producto/spec.md` is
  updated only at archive time; reverting the delta leaves it unaffected.
- **No migration** to reverse.

## Handoff note for `sdd-tasks`

- Delivery strategy is `auto-chain` and the review policy is 400 changed lines.
  The authored diff (one helper + three wiring sites + controller guards + the
  test classes above, many of which are large existing files) is expected to
  exceed the 400-line budget once strict-TDD tests are included; `sdd-tasks`
  should forecast this and recommend chained work units (e.g. helper+resolver,
  batch reader+list, panel guards) if it does.
- No Composer dependency is added, so no `vendor/` commit step is required for
  this change.

## Open Questions

None blocking. The one proposal/spec ambiguity (whether `delete_catalogo_valor`
is guarded) is resolved in D6 in favor of the spec's "value assignment or delete"
wording, with rationale.
