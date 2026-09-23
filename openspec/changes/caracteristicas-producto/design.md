# Design: caracteristicas-producto

- **Change**: `caracteristicas-producto`
- **Phase**: `design`
- **SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`)
- **Secondary plugin**: `plugins/tarifario` (registers its two default boolean features only)
- **Artifact store**: `openspec`
- **Core `openspec/`**: reference only — no entry created there, in any phase.
- **Authoritative inputs**: `proposal.md` (authoritative), `specs/**` (48 requirements / 151 scenarios), `specs/README.md` (spec-phase decisions 1–11), `preproposal.md` (D1–D12), `explore.md`, `research.md`.
- **Supersedes**: `plugins/tarifario/openspec/changes/mover-tarifa-catalogo-opcionales-a-tarifario/` (never applied).
- **Delivery**: `delivery_strategy: single-pr` is **not feasible** — see §15.

This design is implementable as written. Any decision not forced by the proposal or the
delta specs is marked **[DECISION]** with a one-line rationale. Two spec/proposal
divergences discovered against the live tree are recorded in §8.6 and must be carried
into `tasks`/`verify`.

---

## 1. Pinned names (README decision 5 + names the specs left open)

Specs lock *behavior*, not identifiers. The following identifiers are pinned here and
MUST be used verbatim by `tasks`/`apply`/`verify`.

| Concept | Pinned identifier | Note |
|---|---|---|
| Definition model | `FSFramework\model\catalogo_caracteristica` | table `catalogo_caracteristicas`, file `model/core/catalogo_caracteristica.php`, XML `model/table/catalogo_caracteristicas.xml` |
| Predefined value model | `FSFramework\model\catalogo_caracteristica_valor` | table `catalogo_caracteristica_valores` |
| Scope value base | `FSFramework\model\caracteristica_scope_value` | **abstract, no table, no XML** — shared triad + clone contract |
| Global scope value model | `FSFramework\model\catalogo_caracteristica_global` | table `catalogo_caracteristica_global` |
| Family scope value model | `FSFramework\model\catalogo_caracteristica_familia` | table `catalogo_caracteristica_familia` |
| Article scope value model | `FSFramework\model\catalogo_caracteristica_articulo` | table `catalogo_caracteristica_articulo` |
| Resolver | `FSFramework\Plugins\catalogo_core\Services\CaracteristicaResolver` | read path |
| Batch reader | `FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorBatchReader` | one query set per page |
| Registry | `FSFramework\Plugins\catalogo_core\Services\CaracteristicaRegistry` | defaults upsert |
| Backfill migration | `FSFramework\Plugins\catalogo_core\Services\CaracteristicaBackfillMigration` | idempotent legacy → feature |
| Column-drop migration | `FSFramework\Plugins\catalogo_core\Services\CaracteristicaColumnDropMigration` | **[DECISION]** CAR-15 needs two gated, introspective drops; a dedicated service keeps them out of the backfill and independently revertible |
| Value store (write façade) | `FSFramework\Plugins\catalogo_core\Services\CaracteristicaValorStore` | **[DECISION]** `CAR-08`'s own test file is `CaracteristicaValorStoreTest.php`, and panel + import + family toggle + article tab + clone all need one materialization + dual-write point |
| Read-through flag helper | `FSFramework\Plugins\catalogo_core\Services\CaracteristicaConfig` | single place that resolves the constant |
| Read-through constant | `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` | **pinned name** (README decision 5); boolean; **feature path when undefined**; only an explicit `FALSE` opts out to legacy |
| DEF tarifa code | `'DEF'` (`CaracteristicaResolver::DEF_TARIFA`) | existing convention |
| Hook context key | `caracteristicas` | map `codigo => effective value`; **pinned name** (already stated in `catalogo-render-hooks` delta) |
| Panel controller | `FSFramework\Plugins\catalogo_core\Controller\VentasCaracteristicas` | wrapper `controller/ventas_caracteristicas.php` → global class `ventas_caracteristicas` |
| Panel view | `View/ventas_caracteristicas.html.twig` | loaded through the `@catalogo_core` Twig namespace |
| Panel slug / menu / order | `ventas_caracteristicas` / `catalogo` / `ordernum = 109` | between opcionales (108) and artículos (120) |
| Permission event | `FSFramework\Plugins\catalogo_core\Event\CaracteristicaPermissionFilterEvent` | **[DECISION]** mirrors `ArticlePermissionFilterEvent`'s neutral default-allow filter; `CAR-18` requires gating global/family assignment "through the permission mechanism" |

**[DECISION] `CaracteristicaConfig::READ_THROUGH_FLAG`** exists so the literal constant
string appears in exactly one file; every consumer calls
`CaracteristicaConfig::read_through(): bool`, which delegates to
`legacy_read_explicitly_enabled(): bool` (`defined(READ_THROUGH_FLAG) && constant(...) === false`).
Rationale: prevents `defined()`/constant-name drift across ~10 call sites.

**[DECISION — amended] The feature path is the DEFAULT.** `read_through()` returns `TRUE`
when the constant is undefined and when it is explicitly `TRUE`; only an explicit `FALSE`
selects the legacy columns. Production must need **no flag at all**: WU-7's gated drop
removes the 12 legacy `en_catalogo`/`en_tarifa` columns, so an undefined constant must keep
reading the feature tables or the catalog would silently fall back to "not visible". The
constant survives only as a documented **emergency opt-out** (rollback), and the drop gate
tests that opt-out precisely via `legacy_read_explicitly_enabled()`.

---

## 2. Data model — 5 tables + 5 XML + 1 abstract base

All models live under `plugins/catalogo_core/model/core/` (namespace `FSFramework\model`),
consistent with `catalogo_opcional*`. All XML live under `plugins/catalogo_core/model/table/`.
`fs_model` creates the table from the XML when missing; every `install()` returns `''`
(no `seed_if_empty` anywhere in this capability).

### 2.1 `catalogo_caracteristicas` — definition (CAR-01, CAR-02)

| Column | Type | Null | Default | Notes |
|---|---|---|---|---|
| `id` | `serial` | NO | — | PK |
| `codigo` | `character varying(50)` | NO | — | UNIQUE; stable registration/import key |
| `nombre` | `character varying(100)` | NO | — | display label (export header, dropdown label) |
| `tipo` | `character varying(10)` | NO | — | whitelist `bool|string` |
| `activo` | `boolean` | NO | `TRUE` | |
| `importable` | `boolean` | NO | `FALSE` | |
| `exportable` | `boolean` | NO | `FALSE` | |
| `listable` | `boolean` | NO | `FALSE` | |
| `orden` | `integer` | NO | `0` | |
| `origen` | `character varying(50)` | NO | `''` | registering plugin; non-empty ⇒ non-deletable |
| `valor_defecto` | `character varying(255)` | YES | `NULL` | terminal fallback |

Restrictions: `catalogo_caracteristicas_pkey PRIMARY KEY (id)`,
`catalogo_caracteristicas_codigo_unique UNIQUE (codigo)`.

Model API:

```php
final class catalogo_caracteristica extends \fs_model
{
    public const TIPO_BOOL = 'bool';
    public const TIPO_STRING = 'string';
    /** @var list<string> closed whitelist, iterable without instantiation (CAR-02) */
    public const TIPOS = [self::TIPO_BOOL, self::TIPO_STRING];

    public $id, $codigo, $nombre, $tipo, $activo, $importable, $exportable,
           $listable, $orden, $origen, $valor_defecto;

    public function get(string $codigo);                  // ?self by codigo (CAR-01)
    public function get_by_id(int $id);                   // ?self
    public function all(bool $onlyActive = false): array;  // ORDER BY orden ASC, codigo ASC
    public function listable(): array;                     // active + listable, ordered
    public function importable(): array;                   // active + importable, ordered
    public function exportable(): array;                   // active + exportable, ordered
    public function is_deletable(): bool;                  // trim((string)$origen) === ''
    public function exists(); public function test(); public function save(); public function delete();
    protected function existing_by_codigo(string $codigo);  // test seam (DB-free unit tests)
}
```

`test()` contract:
- `codigo = no_html(trim($codigo))`, required, length 1..50; a second row with the same
  `codigo` and a different `id` is rejected with an explicit error (CAR-01 duplicate scenario).
- `nombre = no_html(trim($nombre))`, required, length 1..100.
- `tipo ∈ self::TIPOS`; otherwise explicit whitelist error, no persist (CAR-02).
- `valor_defecto = no_html(trim((string)$valor_defecto))`; `''` normalizes to `NULL`.
- if `tipo === 'bool'` and `valor_defecto` is neither `NULL` nor `'1'` nor `'0'` ⇒ reject
  (**[DECISION]** keeps `bool` closed at the definition level too).
- `orden = intval($orden)`; `origen = no_html(trim($origen))` (may be empty).

`delete()` refuses when `!is_deletable()` (CAR-11); the panel checks the same before
calling it (defense in depth). The persisted `origen` is authoritative: `delete()`
re-reads it (`persisted_origen()`) and the DELETE re-enforces `(origen IS NULL OR
TRIM(origen) = '')`, so a plugin-owned row cannot be unlocked by clearing the
in-memory property without saving.

### 2.2 `catalogo_caracteristica_valores` — predefined catalog (CAR-03)

| Column | Type | Null | Default |
|---|---|---|---|
| `id` | `serial` | NO | — |
| `id_caracteristica` | `integer` | NO | — |
| `valor` | `character varying(255)` | NO | — |
| `orden` | `integer` | NO | `0` |
| `activo` | `boolean` | NO | `TRUE` |

Restrictions: PK `(id)`; `UNIQUE (id_caracteristica, valor)`;
`FK id_caracteristica → catalogo_caracteristicas(id) ON DELETE CASCADE ON UPDATE CASCADE`.

Model: `catalogo_caracteristica_valor`, `all_from_caracteristica(int $id): array`
(ordered by `orden`, `id`), `get(int $id)`, `exists()`, `test()`, `save()`, `delete()`.
`test()` requires `id_caracteristica`, non-empty `valor`, and rejects a duplicate
`(id_caracteristica, valor)` through an `existing_valor()` seam. No seeding here — the
seed is owned by `CaracteristicaRegistry` (targeted upsert, not `seed_if_empty`).

### 2.3 Scope value tables (CAR-05)

Common columns for all three:

| Column | Type | Null | Notes |
|---|---|---|---|
| `codtarifa` | `character varying(20)` | NO | FK `tarif_tarifas(codtarifa)` CASCADE |
| `id_caracteristica` | `integer` | NO | FK `catalogo_caracteristicas(id)` CASCADE |
| `id_valor` | `integer` | YES | FK `catalogo_caracteristica_valores(id)` CASCADE |
| `valor` | `character varying(255)` | YES | custom value |
| `custom` | `boolean` | NO | default `FALSE`; derived, see §3 |

| Table / model | Scope key | PK |
|---|---|---|
| `catalogo_caracteristica_global` | *(none)* | `(codtarifa, id_caracteristica)` |
| `catalogo_caracteristica_familia` | `codfamilia` | `(codtarifa, codfamilia, id_caracteristica)` |
| `catalogo_caracteristica_articulo` | `referencia` | `(codtarifa, referencia, id_caracteristica)` |

Extra FKs: `catalogo_caracteristica_familia.codfamilia → familias(codfamilia)` CASCADE;
`catalogo_caracteristica_articulo.referencia → articulos(referencia)` CASCADE. All
`ON DELETE CASCADE ON UPDATE CASCADE`, written in the XML exactly as
`model/table/tarif_tarifa_opcional.xml` does.

**[DECISION] shared abstract base** `caracteristica_scope_value extends \fs_model`
(`model/core/caracteristica_scope_value.php`, no table): the triad invariant, the
`copy_from_tarifa()` transaction and the keyed `exists()/save()/delete()` are identical
across the three scopes; triplicating them would be three places to drift.
Each concrete model declares `protected const TABLE = '<name>';` and
`protected const SCOPE = 'articulo|familia|global';`; the base constructor uses
`parent::__construct(static::TABLE)`.

Base API:

```php
abstract class caracteristica_scope_value extends \fs_model
{
    public const DEF_TARIFA = 'DEF';
    public $codtarifa, $id_caracteristica, $id_valor, $valor, $custom;

    public function scope(): string;                       // 'articulo'|'familia'|'global'
    public function key_columns(): array;                  // [] | ['codfamilia'] | ['referencia']
    public function key_values(): array;                   // parallel property values
    public function get(string $codtarifa, array $key, int $idCaracteristica); // ?static, pure read
    public function exists(); public function test(); public function save(); public function delete();
    public function copy_from_tarifa(string $origen, string $destino): bool;    // CAR-10, §11
    protected function definition_for(int $idCaracteristica); // test seam
    protected function catalogo_value_for(int $idValor);       // test seam
}
```

`install()` (per concrete model) touches its FK targets before returning `''`
(mirrors `tarif_tarifa_opcional::install()`):
- global: `new tarif_tarifa(); new catalogo_caracteristica();`
- familia: `+ new familia();`
- articulo: `+ new articulo();`

---

## 3. Value triad invariant (CAR-04)

A scope row stores **exactly one** of `id_valor` (predefined, `custom = FALSE`) or
`valor` (custom, `custom = TRUE`). Presence is defined by `!== null`, not by emptiness —
this is what keeps `''` (stored custom value) distinguishable from "no row" (no value).

`caracteristica_scope_value::test()`:

1. `$hasIdValor = $this->id_valor !== null && (int) $this->id_valor > 0`.
2. `$hasValor = $this->valor !== null` (**`''` counts as present**).
3. `$hasIdValor === $hasValor` ⇒ reject ("exactly one value representation is required").
4. **`custom` is derived**: `$this->custom = $hasValor`. **Ambiguity resolved:**
   a row with `valor` set and `custom = TRUE`, and a row with `id_valor` set and
   `custom = FALSE`, both satisfy CAR-04. **[DECISION]** `custom` is not accepted as
   caller input; deriving it makes the invariant unbreakable instead of merely
   validated. (CAR-04's "bool rejects custom" scenario uses `valor` + `custom = TRUE`,
   which is still rejected by rule 6, so the scenario passes.)
5. `codtarifa = no_html(trim((string) $codtarifa))`, required, non-empty.
6. Load the definition via `definition_for((int) $this->id_caracteristica)`:
   - definition missing ⇒ reject.
   - `tipo === 'bool'`: require `$hasIdValor` (predefined only); reject any custom
     `valor` with an explicit error. `bool` is closed to the seeded `'1'`/`'0'` pair
     because every bool definition gets exactly that pair seeded (§9); a predefined id
     is additionally verified to belong to `id_caracteristica`.
   - `tipo === 'string'`: `$hasIdValor` must reference a catalog value owned by the
     definition; `$hasValor` is normalized with `no_html((string) $this->valor)` and
     **kept as `''` when empty**.
7. Scope key: every `key_columns()` property must be non-empty (familia/articulo);
   global has no key.

`''` vs `null` vs `false` handling (summary):

| Stored | Meaning | Resolver | bool renderer |
|---|---|---|---|
| no row | "no value" | `null` | placeholder (list feature cell) / legacy FALSE for ALC-02 |
| row, `valor = ''` (string) | stored empty value | `''` (distinct from `null`) | n/a |
| row, `id_valor = <'0'>` (bool) | `FALSE` | `'0'` → `str2bool` false | `No` |
| row, `id_valor = <'1'>` (bool) | `TRUE` | `'1'` → `str2bool` true | `Sí` |

`save()` always calls `test()` first; `delete()` deletes by the PK columns.

---

## 4. Resolver algorithm (CAR-06, CAR-07)

`CaracteristicaResolver` — **read-only**. It exposes no `save`/`assign`/`materialize`
method; writes live in `CaracteristicaValorStore` and the models (CAR-07).

```php
final class CaracteristicaResolver
{
    public const SCOPE_ARTICULO = 'articulo';
    public const SCOPE_FAMILIA  = 'familia';
    public const SCOPE_GLOBAL   = 'global';
    public const DEF_TARIFA     = 'DEF';

    /** Ordered definitions (orden ASC, codigo ASC). In-request memoized. */
    public function definitions(bool $onlyActive = true): array;
    /** Active + listable, ordered — drives CAR-16 columns. */
    public function listable_definitions(): array;
    /** Active + importable/exportable, ordered — drives CAR-17. */
    public function importable_definitions(): array;
    public function exportable_definitions(): array;

    /**
     * Effective raw value (predefined catalog `valor` or custom `valor`), or NULL
     * for "no value". Pure read: SELECT only, never persists (CAR-07).
     */
    public function resolve(string $codigo, string $codtarifa, ?string $referencia = null, ?string $codfamilia = null): ?string;

    /** Typed convenience. NULL = no value; bool via str2bool('1'|'0'). */
    public function resolve_bool(string $codigo, string $codtarifa, ?string $referencia = null, ?string $codfamilia = null): ?bool;

    /** Nearest-first `madre` chain, cycle-guarded. In-request memoized. */
    public function familia_chain(?string $codfamilia): array;

    /** D12 (§7). NULL = no parent carries a value. */
    public function resolve_opcional_visibility(int $id_opcional, string $codtarifa, string $codigo): ?bool;
    /** D12 batch: [id_opcional => ?bool] for a page, bounded query count. */
    public function resolve_opcionales_visibility(array $id_opcionales, string $codtarifa, string $codigo): array;

    // Test/DI seams (protected): definition_model(), scope_model(string $scope),
    // familia_model(), articulo_family_lookup().
}
```

### 4.1 Exact algorithm for `resolve()`

```
input: codigo, codtarifa, referencia|null, codfamilia|null
def = definitions()[codigo]                # memoized map
if def missing or !def.activo: return null   # [DECISION] inactive definition ⇒ no value
tarifa_probes = dedupe([codtarifa, DEF])

# 1. scope walk, nearest-first
if referencia !== null:
    for t in tarifa_probes:
        row = SELECT id_valor, valor, custom FROM catalogo_caracteristica_articulo
              WHERE codtarifa = t AND referencia = ? AND id_caracteristica = ?
        if row: return value_of(row)
for fam in familia_chain(codfamilia):        # own codfamilia first, then madre recursively
    for t in tarifa_probes:
        row = SELECT ... FROM catalogo_caracteristica_familia
              WHERE codtarifa = t AND codfamilia = fam AND id_caracteristica = ?
        if row: return value_of(row)
for t in tarifa_probes:
    row = SELECT ... FROM catalogo_caracteristica_global
          WHERE codtarifa = t AND id_caracteristica = ?
    if row: return value_of(row)

# 2. terminal fallback — outside the scope/scope loop
return normalize_default(def.valor_defecto)  # NULL or '' → null; else the string

value_of(row) = row.custom ? row.valor : catalog_value(row.id_valor)
```

`familia_chain(null)` returns `[]` (CAR-06 "absent family does not block the global
fallback"). The chain is built with `SELECT madre FROM familias WHERE codfamilia = ?`
per hop, memoized per request, with a visited-set guard (a cycle or a >32-depth chain
stops the walk).

### 4.2 `valor_defecto` short-circuit ordering — resolved explicitly

The proposal's one-line D5 (`requested > DEF > valor_defecto/null`) and the
`specs/README.md` risk flag could be read as "consult `valor_defecto` inside each
scope". **This design follows README decision 1 and `CAR-06`:** the scope walk runs
first; each scope probes requested tarifa then `DEF`; the first scope that yields a
row wins; `valor_defecto` is consulted **only after all three scopes (and all family
ancestors) failed**. The scope loop never reads `def.valor_defecto`.

Rationale (one line): consulting `valor_defecto` inside the loop would make scope
precedence inert whenever a default is set, and `CAR-06`'s "Scope precedence beats
tarifa fallback" scenario (articulo `DEF` row must beat a global `T2` row) is only
satisfiable with the terminal reading.

`normalize_default()` returns `null` for `null` and for `''`, satisfying
`CAR-06`'s "with `valor_defecto` empty the result is 'no value', never `FALSE`".

`resolve_bool()` returns `null` for "no value" and `str2bool('1')/str2bool('0')`
otherwise; it never returns `false` for absence — the *renderer* decides what null
looks like (ALC-02 legacy cells map null → ✗; CAR-16 feature cells use the placeholder).

---

## 5. Batched read for a page of `listable` columns (CAR-16, no N+1)

`CaracteristicaValorBatchReader::for_referencias()` mirrors
`ArticuloTarifaPrecioBatchReader`: the query count is a constant independent of the
page size N, and the scope/tarifa precedence of §4 is replayed in PHP over pre-loaded
maps.

```php
final class CaracteristicaValorBatchReader
{
    /**
     * @param array<int,string>          $refs
     * @param array<string,string>|null  $familias ref => codfamilia (already known
     *        by the list rows); when null the reader loads it with one extra query.
     * @param array<int,string>|null     $codigos  restrict to these definitions
     *        (default: every active + listable definition)
     * @return array<string,array<string,?string>> ref => codigo => raw value|null
     */
    public function for_referencias(array $refs, string $codtarifa, ?array $familias = null, ?array $codigos = null): array;

    /** Definition metadata for the column headers, ordered by orden then codigo. */
    public function columns(?array $codigos = null): array;   // [['codigo','nombre','tipo'], ...]
}
```

Query shape (constant count per page, never per row):

1. `SELECT id, codigo, nombre, tipo, orden, valor_defecto FROM catalogo_caracteristicas WHERE activo = TRUE [AND listable = TRUE] ORDER BY orden, codigo;`
2. `SELECT id, id_caracteristica, valor FROM catalogo_caracteristica_valores WHERE id_caracteristica IN (<ids>);`
3. `SELECT referencia, codfamilia FROM articulos WHERE referencia IN (<refs>);` — skipped when `$familias` is supplied.
4. `SELECT codfamilia, madre FROM familias WHERE codfamilia IN (<all chain nodes of the page>);` — one query; the page's family set is the union of the `codfamilia` values from step 3 plus their ancestors, resolved in PHP with the same cycle guard (an all-families snapshot is an acceptable alternative).
5. `SELECT referencia, id_caracteristica, id_valor, valor, custom FROM catalogo_caracteristica_articulo WHERE codtarifa IN (requested, DEF) AND referencia IN (<refs>);`
6. `SELECT codfamilia, id_caracteristica, id_valor, valor, custom FROM catalogo_caracteristica_familia WHERE codtarifa IN (requested, DEF) AND codfamilia IN (<chain nodes>);`
7. `SELECT id_caracteristica, id_valor, valor, custom FROM catalogo_caracteristica_global WHERE codtarifa IN (requested, DEF);`

Five queries when `$familias` is supplied (steps 3 and 4 collapse to the caller's map),
seven otherwise — **independent of N**. All statements use `IN (...)` with
`var2str`-escaped literals or the existing parameterized `select($sql, $params)` form;
no per-row query is permitted.

The list trait then exposes:

```php
public function load_caracteristica_columns(array $referencias): void;   // one batched read
public function listable_caracteristicas(): array;                        // ordered columns
public function caracteristica_cell(string $referencia, string $codigo): string;
```

`caracteristica_cell()` rendering contract: `bool` with a value ⇒ `Sí`/`No`;
`bool` with `null` ⇒ the neutral placeholder `-`; `string` with a value ⇒ the
value escaped by Twig (`{{ }}`, never `|raw`); `string` with `null` ⇒ `-`.
**[DECISION]** `null` bool renders `-` (not `No`) so "no value" stays visually distinct
from an explicit `No`; `CAR-16`'s "bool MUST render Sí/No" applies whenever a value exists.

---

## 6. `CaracteristicaValorStore` — the only write path (CAR-08, CAR-09, CAR-14)

```php
final class CaracteristicaValorStore
{
    public function assign_predefined(string $scope, string $codtarifa, array $key, string $codigo, int $idValor): bool;
    public function assign_custom(string $scope, string $codtarifa, array $key, string $codigo, string $valor): bool;
    /** bool convenience: maps to the definition's seeded '1'/'0' catalog value. */
    public function assign_bool(string $scope, string $codtarifa, array $key, string $codigo, bool $valor): bool;
    /** Deletes the row; row absence is "no value" (NOT the same as assign_custom(..., '')). */
    public function clear(string $scope, string $codtarifa, array $key, string $codigo): bool;
    /** Assign + legacy dual-write for the soak window (CAR-14). */
    public function assign_with_dual_write(string $scope, string $codtarifa, array $key, string $codigo, bool $valor): bool;

    // Test/DI seams: resolver(), scope_model($scope), legacy_writer($scope).
}
```

`assign_*` resolves the definition by `codigo`, loads the existing `(scope, key,
tarifa, id_caracteristica)` row, mutates only the value triad, and saves. **First write
materializes exactly one row** for the requested `(codtarifa, scope, key)` — never a
fan-out to other tarifas, other keys or other scopes (CAR-08). An existing row is
updated in place (single row). `clear()` deletes it.

`CaracteristicaValorStore` is also the surface used by:
the panel (§10), the family toggle (`tarif_familias`), the article tab
(`tarif_tab_precios` / article detail), the Excel import (both plugins), and the clone
step is the models' `copy_from_tarifa()` (§11).

---

## 7. D12 opcional visibility derivation (CAR-12)

An opcional owns **no** visibility flag. Its effective catalog/tarifa visibility is the
**existential union** over its parents' effective value for `(codigo, codtarifa)`.

Parent discovery (`resolve_opcionales_visibility()` pre-loads these for the page; the
single method issues the same three queries):

1. **Article-attached (direct):** `SELECT referencia FROM catalogo_articulo_opcional WHERE id_opcional = ?`.
2. **Article-attached (via group):** when `catalogo_opcionales.id_grupo > 0`,
   `SELECT g.referencia FROM catalogo_articulo_opcional_grupo g WHERE g.id_grupo = ?`.
   **[DECISION]** the spec's literal list mentions only `catalogo_articulo_opcional`,
   but group opcionales are article-attached through the group relation (see
   `catalogo_opcional_familia::add_with_propagation()`); omitting them would break the
   CAR-12 parity test for grouped opcionales.
3. **Family-assigned:** `SELECT codfamilia FROM catalogo_opcional_familia WHERE id_opcional = ?`.
4. **Unassigned:** neither (1)/(2)/(3) yields a parent ⇒ the **global** scope only.

Resolution per parent:
- article parent ⇒ `resolve($codigo, $codtarifa, $referencia, familia_of($referencia))`
  (the article's own `codfamilia` seeds the family walk).
- family parent ⇒ `resolve($codigo, $codtarifa, null, $codfamilia)` (the family walk
  starts at the assigned family, then `madre`).
- unassigned ⇒ `resolve($codigo, $codtarifa, null, null)` (global only).

Union: `true` if **any** parent's value is `true`; `false` if none is true and at least
one parent yields a present value; `null` if no parent yields a present value at all
(rendered as the neutral derived indicator). This is the any-parent existential union
of README decision 9 / CAR-12's multi-parent scenario.

Query count for a page of opcionales: 3 parent queries + the resolver's batched scope
reads (steps 1–2, 5–7 of §5 over the parent keys) — bounded, no N+1.

Consumers:
- `ventas_opcionales` list: `OUM-03` derived read-only indicator per row (the
  `VentasOpcionalesListTrait` replaces the `en_catalogo`/`en_tarifa` state reads with
  `resolve_opcionales_visibility()`; **no toggle**).
- `OUM-04`: `TOGGLE_ACTIONS` becomes `['toggle_activa']` only;
  `toggle_en_catalogo`/`toggle_en_tarifa` and `set_en_catalogo()`/`set_en_tarifa()` are
  deleted, not aliased.
- `OTS-02`/`OTS-05`: the tarifario-injected panel renders the derived value read-only
  and ignores posted visibility fields.
- `tarif_catalogo_view` export: derives the per-tarifa set from the parent products.
- `tarif_catalogo_view` per-row and export visibility: reads through the resolver when
  the read-through flag is enabled (§8.4). The SQL **membership** filters of that view
  (and of the `all_en_catalogo*` / `count_en_*` model helpers) are legacy-by-design
  during the soak — see the DEV-17 ratification in §8.4.
- `tarif_configurador_opcionales`: **no visibility read after WU-4** (DEV-16). Its
  `activa`/`visible` state is association state, a different concept, and the
  opcional-owned flags are gone; the derived D12 visibility is rendered by the opcional
  list/panel (WU-4) and exported by `build_opcionales_export` (WU-6).
- `tarif_articulo`: **no visibility read** either — the model maps the import payload
  into article-scope feature values (`factory_caracteristicas`, CAR-17) and no longer
  writes the price-row flags.

`tarif_opcional_ext` / `tarif_tarifa_opcional`: `en_catalogo`/`en_tarifa` are removed
(CAR-15 clause 1, `opcionales-tarifa-management` delta). `tarif_tarifa_opcional` keeps
`activa`/`orden`; its `effective()` keeps working with the two flags removed.
**[DECISION] `catalogo_opcional_precios.en_catalogo` stays**: it is a per-lista price
inclusion flag, not an opcional-visibility flag, and CAR-15 does not list it. Removing
it would be out of scope.

---

## 8. Supersession, backfill, read-through, dual-write, drop

### 8.1 Ordering vs boot

```
catalogo_core::init()
  existing ensures ...
  ensureCaracteristicasTables()        # 5 tables + 5 XML
  CaracteristicaRegistry::registerDefaults()   # medidas (+ bool pair if any)
  CaracteristicaBackfillMigration::migrateIfNeeded(db)  # no-op until en_* defs exist

tarifario::init()
  existing boot work ...
  registerDefaultFeatures()            # en_catalogo, en_tarifa (class_exists + static guard)
  CaracteristicaBackfillMigration::migrateIfNeeded(db)  # second attempt, now the defs exist
```

Both `init()` and `upgrade()` run the same trio, so boot order cannot skip the
registration or the backfill (CAR-11 "survives boot order"). The backfill is
re-runnable and cheap-skips in the steady state via a pre-check query, mirroring
`TarifOpcionalExtMigration::hasPendingLegacyRows()`.

### 8.2 `CaracteristicaBackfillMigration::migrateIfNeeded(\fs_db2 $db): void`

Uses the driver-aware introspection already proven in `TarifOpcionalExtMigration`
(`tableExists`, `columnExists`, MySQL vs PostgreSQL branch). Every statement is an
`INSERT ... SELECT ... WHERE NOT EXISTS` (MySQL: `INSERT IGNORE ... LEFT JOIN ...
WHERE <target> IS NULL`; PostgreSQL: `... WHERE NOT EXISTS (...) ON CONFLICT DO
NOTHING`). The `NOT EXISTS`/`LEFT JOIN` guard is on the **target PK + id_caracteristica**
so a second run is a no-op and operator edits are never overwritten (README decision 10).

**Decision: materialize the *stored* legacy value for every legacy row that exists**
(TRUE *and* FALSE), because the pre-change semantics are "row present with a value" vs
"row absent ⇒ that table's default". Materializing both makes the read-through parity
tests exact; absence stays absence. **[DECISION]**

Resolve the `en_catalogo`/`en_tarifa` definition ids and their `'1'`/`'0'` catalog
value ids up front; if either definition is absent, return early (the tarifario boot
attempt covers it later).

**(a) `tarif_articulo_precio` → articulo scope** (per flag, `:id_carac` bound per flag):

```sql
INSERT INTO catalogo_caracteristica_articulo
    (codtarifa, referencia, id_caracteristica, id_valor, valor, custom)
SELECT p.codtarifa, p.referencia, :id_carac, v.id, NULL, FALSE
FROM tarif_articulo_precios p
INNER JOIN catalogo_caracteristica_valores v
        ON v.id_caracteristica = :id_carac
       AND v.valor = CASE WHEN p.en_catalogo THEN '1' ELSE '0' END
WHERE p.en_catalogo IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM catalogo_caracteristica_articulo c
       WHERE c.codtarifa = p.codtarifa
         AND c.referencia = p.referencia
         AND c.id_caracteristica = :id_carac);
```

The `en_tarifa` pass is identical with `p.en_tarifa` and the `en_tarifa` definition id.

**(b) `tarif_tarifa_articulo` → articulo scope, price row wins** (same `INNER JOIN`
shape over `tarif_tarifa_articulo t`), guarded by an **additional** predicate so an
existing price row keeps precedence:

```sql
WHERE t.en_catalogo IS NOT NULL
  AND NOT EXISTS (SELECT 1 FROM tarif_articulo_precios p
                   WHERE p.referencia = t.referencia AND p.codtarifa = t.codtarifa)
  AND NOT EXISTS (SELECT 1 FROM catalogo_caracteristica_articulo c
                   WHERE c.codtarifa = t.codtarifa
                     AND c.referencia = t.referencia
                     AND c.id_caracteristica = :id_carac)
```

**(c) `tarif_tarifa_familia` → familia scope** (per flag; `codfamilia`, `codtarifa`):

```sql
INSERT INTO catalogo_caracteristica_familia
    (codtarifa, codfamilia, id_caracteristica, id_valor, valor, custom)
SELECT f.codtarifa, f.codfamilia, :id_carac, v.id, NULL, FALSE
FROM tarif_tarifa_familia f
INNER JOIN catalogo_caracteristica_valores v
        ON v.id_caracteristica = :id_carac
       AND v.valor = CASE WHEN f.en_catalogo THEN '1' ELSE '0' END
WHERE f.en_catalogo IS NOT NULL
  AND NOT EXISTS (
      SELECT 1 FROM catalogo_caracteristica_familia c
       WHERE c.codtarifa = f.codtarifa
         AND c.codfamilia = f.codfamilia
         AND c.id_caracteristica = :id_carac);
```

**(d) `tarif_familia_ext` → `DEF` familia rows** — **DIVERGENCE (see §8.6)**: the live
`tarif_familia_ext` table has **only** `codfamilia`, `capitulo`, `nivel` (it was retired
as a historical read-only table by `retire-tarif-familia-ext`); it carries no visibility
columns. The literal statement is therefore emitted **only when
`columnExists('tarif_familia_ext','en_catalogo'|'en_tarifa')` is true**, otherwise it is
skipped. Its intent is already covered by (c) plus the resolver's `DEF` fallback: a
family that only has a `DEF`-tarifa `tarif_tarifa_familia` row resolves for every other
tarifa through the `DEF` probe.

**(e) Opcional flags are never backfilled as opcional rows** (CAR-13). Where an opcional
had a legacy `en_catalogo`/`en_tarifa` flag and its parent had no value row, seed the
parent/global value so visibility survives:

```sql
-- article-attached opcional whose parent article has no value row
INSERT INTO catalogo_caracteristica_articulo
    (codtarifa, referencia, id_caracteristica, id_valor, valor, custom)
SELECT :codtarifa, ao.referencia, :id_carac, v.id, NULL, FALSE
FROM catalogo_articulo_opcional ao
INNER JOIN catalogo_caracteristica_valores v
        ON v.id_caracteristica = :id_carac
       AND v.valor = CASE WHEN :legacy_flag THEN '1' ELSE '0' END
WHERE ao.id_opcional = :id_opcional
  AND NOT EXISTS (SELECT 1 FROM catalogo_caracteristica_articulo c
                   WHERE c.codtarifa = :codtarifa AND c.referencia = ao.referencia
                     AND c.id_caracteristica = :id_carac);
```

(the family-assigned case uses `catalogo_opcional_familia` + the `DEF` tarifa; the fully
unassigned case uses the global table). **[DECISION]** this step runs **after** (a)–(c)
so it only fills gaps; when the opcional's parent already got a value from (a)–(c), the
`NOT EXISTS` guard makes it a no-op.

### 8.3 Idempotency

Every statement above is guarded. Double-run = zero inserts (CAR-13 "double run is a
no-op"). Operator edits survive because the guard is `NOT EXISTS`, never an `UPDATE`
(CAR-13 "operator edits are never overwritten").

### 8.4 Read-through flag and dual-write soak (CAR-14)

- `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` is read **only** through
  `CaracteristicaConfig::read_through()`. **The feature path is the default**: an
  undefined constant resolves to the feature tables, and only an explicit `FALSE`
  opts out to the legacy columns (the emergency rollback switch). This is what lets
  production require no flag once WU-7 drops the legacy columns.
- Consumers and their two branches:

| Consumer | flag off (legacy) | flag on (resolver) |
|---|---|---|
| `VentasArticulosListTrait` (`articulo_en_tarifa_flag`, `articulo_en_catalogo`) | `ArticuloTarifaPrecioBatchReader` columns | `CaracteristicaValorBatchReader` values |
| `ArticuloExcelExportService` filtered export visibility | legacy columns | resolver |
| `tarif_catalogo_view` export | legacy columns | resolver |
| `tarif_configurador_opcionales` | **no visibility read** — after WU-4 its `activa`/`visible` state is association state, a different concept (DEV-16) | same |
| `tarif_articulo` | **no visibility read** — the model only maps the import payload into article-scope feature values (`factory_caracteristicas`) and no longer writes the price-row flags | same |
| `ventas_opcionales` derived indicator | **D12 derived in both branches** (the flags are gone) | same |
| `ventas_familia` toggle response JSON | writes feature value, returns the flipped value | same |
| article detail tab | reads feature value (flags gone by WU-4) | same |

  Note the D12 consumers have no legacy branch after WU-4: their flags are dropped in
  the same work unit that introduces the derivation, gated on the CAR-12 parity test
  only (CAR-15 clause 1).

  **[DECISION — DEV-17] The catalog *membership* filters stay legacy-by-design for the
  soak.** The `en_catalogo = TRUE` / `en_tarifa = TRUE` SQL pre-filters of
  `tarif_catalogo_view` and the `all_en_*` / `count_en_*` helpers of
  `tarif_tarifa_articulo` / `tarif_tarifa_familia` keep reading the legacy per-tarifa
  columns. This choice is coherent **only** because every write path keeps both sides
  consistent: CAR-13 backfill + CAR-14 dual-write + the DEV-18 JSON import mirror
  (`process_familias_batch()` / `process_articulos_batch()` now derive the row
  visibility once and feed both the legacy column and `CaracteristicaValorStore`).
  With that parity the pre-filters are equivalent to the resolver, and the flag-OFF
  output stays byte-identical. The alternative (resolver-driven membership) would
  rewrite frozen catalog SQL into per-row resolver calls before the soak for no
  semantic gain. The ratified surface is frozen by
  `plugins/tarifario/controller/tarif_catalogo_view.php::MEMBERSHIP_FILTERS_LEGACY_BY_DESIGN`
  and gated by `TarifCatalogoMembershipFilterRatificationTest`.
  **Closure plan (ordered prerequisite for CAR-15 clause 2):** before
  `dropLegacyArticleFamilyColumns()` drops those columns, the filters MUST be rewritten
  to the feature tables (or removed) — otherwise the drop leaves invalid SQL. This
  ordering is recorded in `CaracteristicaColumnDropMigration`'s clause-2 runbook and in
  WU-7's tasks.

- **Dual-write** (`assign_with_dual_write`): during the soak, a feature-value write for
  `en_catalogo`/`en_tarifa` also updates the legacy column when that column still
  exists (introspected, not flag-gated). Mapping:

| Feature scope | Legacy target(s) | Write shape |
|---|---|---|
| `articulo` | `tarif_articulo_precios` (PK referencia, codtarifa) | INSERT-or-UPDATE; INSERT defaults `precio = 0`, `activo = TRUE` (exactly the ALC-02 missing-row defaults) |
| `articulo` | `tarif_tarifa_articulo` (PK codtarifa, referencia) | INSERT-or-UPDATE; INSERT defaults `orden = 0`, `codfamilia` = the article's family (no `madre` — see §8.6 divergence 3) |
| `familia` | `tarif_tarifa_familia` (PK codtarifa, codfamilia) | INSERT-or-UPDATE; INSERT defaults `madre` from `familias`, `capitulo`/`nivel` empty, `activa = TRUE`, `orden = 0` |
| `global` | — | **no-op** |

  **[DECISION]** global-scope assignments have no legacy representation (the legacy flags
  are always per-key), so they are not dual-written; operators flipping the flag after
  the soak must expect pre-existing global assignments to become visible at flip time.
  **[DECISION]** the article dual-write materializes a price row when absent using the
  pinned ALC-02 defaults, because CAR-14's dual-write scenario requires the legacy
  columns to carry the equivalent value even for a key that had no legacy row.
  **`coddivisa` is never copied or altered** (`R-TAR-CUR-008`).

  **Compatibility rule for global-scope visibility during the soak (DEV-17 closure
  requirement).** Because a global assignment has no legacy per-key row, a key whose
  only visibility source is a global `en_catalogo = TRUE` (or `en_tarifa = TRUE`) is
  **eligible for resolution** (the resolver probes articulo → familia → global and
  returns TRUE) but **not** for the legacy membership pre-filters: the
  `en_catalogo = TRUE` / `en_tarifa = TRUE` SQL predicates of `tarif_catalogo_view` and
  the `all_en_*` / `count_en_*` helpers of `tarif_tarifa_articulo` /
  `tarif_tarifa_familia` can only see per-key columns, so they exclude that key. The
  documented rule for the soak is therefore:

  1. **flag OFF (legacy opt-out)** — catalog candidate membership stays exactly the
     pre-change output (byte-identical): the legacy pre-filters keep defining the
     candidate set, and a global-only key stays excluded until the filter rewrite lands.
     This is the emergency opt-out, not the default; the feature path is the default.
  2. **flag ON (the default)** — resolver-driven reads may include a global-only key, so the two
     surfaces are intentionally **not** interchangeable for global-only visibility
     during the soak.
  3. **No filter rewrite happens in this change.** The DEV-17 rewrite (or removal) of
     those filters is an **ordered prerequisite of CAR-15 clause 2**, recorded in §8.5:
     `dropLegacyArticleFamilyColumns()` MUST NOT run while the filters still read the
     legacy columns, and `runPostSoak()` refuses without the operator's
     `dev17MembershipFiltersRewritten` attestation.

  Parity for the pre-change data set is unaffected because every legacy per-key flag is
  backfilled (CAR-13) and dual-written (CAR-14), so the pre-filters and the resolver
  agree on every key that has a legacy representation; only a post-soak global-only
  assignment can diverge.

### 8.5 Gated reversible post-soak drop (CAR-15)

`CaracteristicaColumnDropMigration` exposes three independent, idempotent, introspective
methods:

```php
public static function migrateIfNeeded(\fs_db2 $db): bool;             // clause 1 deploy-time entry (auto-wired)
public static function dropD12OpcionalColumns(\fs_db2 $db): bool;      // clause 1, gated on CAR-12 parity
public static function dropLegacyArticleFamilyColumns(\fs_db2 $db): bool; // clause 2, gated on the soak
public static function dropDeadOpcionalColumns(\fs_db2 $db): bool;     // dead-column cleanup, same soak gate
public static function hasColumn(\fs_db2 $db, string $table, string $column): bool;
```

**[DECISION] Two-release delivery (2026-09-23).** CAR-15's two clauses ship in two releases,
because clause 2 needs a real soak window and clause 1 does not:

- **Clause 1 is delivered now and auto-wired.** Its gate is *only* the `CAR-12`
  behavior-preservation parity test, which is live, and the derived read-only indicator
  already replaces the opcional flags. `Init::upgrade()` — the version-change path run by
  `PluginSchemaSynchronizer`, never `Init::init()` — calls `migrateIfNeeded()`, so
  production needs no console and no flag. This mirrors the sibling boot migrations
  (`CatalogLegacyTableMigration` / `CaracteristicaBackfillMigration` /
  `TarifOpcionalExtMigration`).
- **Clause 2 is staged for a later release.** It needs the soak first; the drop stays the
  operator path (`runPostSoak()` / `tools/run_caracteristica_column_drop.php`) and will be
  auto-wired in the release that follows the soak, exactly as clause 1 is now.

- Clause 1 (D12, not soaked): `ALTER TABLE tarif_opcional_ext DROP COLUMN en_catalogo`,
  `... en_tarifa`, `ALTER TABLE tarif_tarifa_opcional DROP COLUMN en_catalogo`, `...
  en_tarifa`. Gate: `CAR-12` behavior-preservation parity test green. Delivered
  automatically through `migrateIfNeeded()` from `Init::upgrade()`. It refuses (returns
  `false`, no schema change) while the legacy read path was explicitly selected — the same
  emergency opt-out clause 2 honours, because the legacy columns are authoritative in that
  state. Idempotent via `hasColumn()` before each `ALTER`. `DROP COLUMN IF EXISTS` is used
  where the driver supports it, behind the `hasColumn()` guard.
- Clause 2 (post-soak): drops `en_catalogo`/`en_tarifa` from `tarif_articulo_precios`,
  `tarif_tarifa_articulo`, `tarif_tarifa_familia` and `tarif_familia_ext` **only when
  the legacy read path was NOT explicitly selected**. The feature path is the default, so
  the precondition holds while the constant is undefined or `TRUE`. Implemented as an
  explicit pre-condition check (`CaracteristicaConfig::legacy_read_explicitly_enabled()`
  must be `FALSE`) plus `hasColumn()` guards; when the emergency opt-out is set the
  method returns `false` and changes no schema. `tarif_familia_ext` may already lack the
  columns ⇒ no-op for that table.
  Idempotent: a second run finds no columns and does nothing.
  **Ordered prerequisite (DEV-17):** the legacy membership filters must be rewritten to
  the feature tables before this clause runs — see §8.4.
- Dead-column cleanup (post-soak, same gate): `dropDeadOpcionalColumns()` drops the
  leftover `catalogo_opcionales.en_catalogo` / `.en_tarifa`. They are present in deployed
  databases, no model declares them and nothing reads or writes them; they are outside
  CAR-15 clause 2's pinned table list, so they get their own gated step rather than
  lingering. `catalogo_opcional_precios.en_catalogo` is deliberately excluded (it stays).
- **Reversibility proof**: feature values remain the source of truth. Re-adding the
  columns as nullable and re-deriving from the resolver reproduces every previously
  stored legacy value — `CaracteristicaReversibilityTest.php` is the executable proof
  (the closed loop per clause: drop → re-add nullable → re-derive → compare against the
  pre-drop snapshot), with the parity scenarios in `CaracteristicaColumnDropTest.php`,
  `CaracteristicaReadThroughTest.php` and `CaracteristicaBackfillTest.php` as the
  gate/derivation evidence next to it. A pre-drop dump is the operator-level safety net
  (documented, not automated).
- **Closed-loop entry point (WU-7 7.3)**: `CaracteristicaColumnDropMigration::runPostSoak($db,
  $dev17MembershipFiltersRewritten)` runs the declared order (`POST_SOAK_STEPS`:
  clause 1 → clause 2 → dead-column cleanup) and reports the live schema via
  `pendingVisibilityColumns()`. It refuses atomically while the legacy read path was
  explicitly selected (the emergency opt-out) **or** the DEV-17 rewrite is unattested
  (`$dev17MembershipFiltersRewritten` has no permissive default). The DEV-17 guard is an explicit operator attestation, not a
  machine check: the membership-filter surface is tarifario-owned (CAR-19 forbids the
  reference) and catalogo_core legitimately still reads the legacy columns during the
  soak (backfill, dual-write, models), so a source scan could not discriminate them.
  Operator runner: `plugins/catalogo_core/tools/run_caracteristica_column_drop.php`
  (dry-run by default; `--apply --dev17-rewritten` runs the loop).
- **Boot wiring**: clause 1 runs from `Init::upgrade()` (the version-change path) via
  `migrateIfNeeded()`, and **never** from `Init::init()` — a schema DROP belongs to a deploy,
  not to every request. Clause 2 and the dead-column cleanup remain operator-only: they run
  from the gated WU-7 entry point `runPostSoak()` (or the operator runner above) and are
  never boot-wired. `test_upgrade_wires_the_clause_1_drop_and_init_does_not()` pins the
  clause-1 wiring, and `test_the_entry_point_is_never_wired_into_a_boot_or_request_path()`
  pins that `runPostSoak()` stays out of every boot/request path.

### 8.6 Divergences found against the live tree (must be carried forward)

1. **`tarif_familia_ext` carries no visibility columns.** Proposal/D3 and CAR-13/D3's
   fourth bullet and CAR-15's drop list both name `tarif_familia_ext.en_catalogo` /
   `en_tarifa`. The live XML/model has only `codfamilia`, `capitulo`, `nivel`; the table
   is a retired historical read-only surface (`TarifFamiliaWriteRetirementTest`,
   `TarifFamiliaHistoricalExtReadTest`), and the flags live on `tarif_tarifa_familia`.
   Resolution: the `tarif_familia_ext` backfill statement and drop are **guarded
   no-ops** (introspection); the real surface is covered by the `tarif_tarifa_familia`
   statements and the `DEF` probe. `verify` must assert the intent (family visibility
   parity), not the literal table.
2. **`catalogo_opcional_precios.en_catalogo` is out of scope.** It is a per-lista price
   inclusion flag and is not in CAR-15's drop list; it stays (stated in §7).
3. **`tarif_tarifa_articulo` has no `madre` column.** §8.4's dual-write table originally
   listed `madre` NULL as an article-target INSERT default, mirroring the
   `tarif_tarifa_familia` shape. The live table is `(codtarifa, referencia, codfamilia,
   en_tarifa, en_catalogo, orden)` and `madre` lives on `tarif_tarifa_familia`; emitting
   it made every article dual-write fail with "Unknown column 'madre'", so
   `assign_with_dual_write()` reported FALSE even though the feature value had been
   persisted (same class as divergence 1, and the same `&&` composition in
   `legacy_writer()`). Resolution: `dual_write_articulo()` inserts only `orden = 0` and
   `codfamilia` = the article's family, and §8.4 no longer lists `madre` for this
   target. `verify` must assert the omitted column, not the literal pre-fix §8.4 shape.

---

## 9. Bootstrap / registration seam

### 9.1 `catalogo_core/Init.php`

```php
public static function ensureCaracteristicasTables(): void
{
    require_once FS_FOLDER . '/base/fs_model.php';
    // FK-safe order: definitions, then FK targets, then the three scope tables.
    self::touchNamespacedModel('catalogo_caracteristica');
    self::touchNamespacedModel('catalogo_caracteristica_valor');
    self::touchNamespacedModel('articulo');
    self::touchNamespacedModel('familia');
    self::ensureFamiliasTarifaTables();                  // tarif_tarifas
    self::touchNamespacedModel('catalogo_caracteristica_global');
    self::touchNamespacedModel('catalogo_caracteristica_familia');
    self::touchNamespacedModel('catalogo_caracteristica_articulo');
}
```

- `init()` adds three guarded blocks after the existing ensures:
  `ensureCaracteristicasTables()`, `CaracteristicaRegistry::registerDefaults()`,
  `CaracteristicaBackfillMigration::migrateIfNeeded($db)` (the `$db` comes from
  `Container::db()` exactly as `migrateLegacyTables()` does today). Each is wrapped in
  its own `try/catch` + `error_log` so a missing model class or a cold DB never fatals
  the boot (mirrors the existing `ensure*` blocks).
- `upgrade()` calls the same trio after the existing ensures (before/after the seed loop
  is immaterial; place after `ensureArticuloDetalleTables()`).
- `DEFAULT_SEED_MODELS` is **not** extended: these models have no `seed_if_empty` seed.

### 9.2 `CaracteristicaRegistry`

```php
final class CaracteristicaRegistry
{
    /** catalogo_core-owned defaults. */
    public const DEFAULTS = [
        ['codigo' => 'medidas', 'nombre' => 'Medidas', 'tipo' => 'string',
         'origen' => 'catalogo_core', 'orden' => 10,
         'importable' => false, 'exportable' => false, 'listable' => false,
         'valor_defecto' => null],
    ];

    public static function registerDefault(
        string $codigo, string $nombre, string $tipo, string $origen,
        array $flags = [], ?\fs_db2 $db = null
    ): bool;

    public static function registerDefaults(?\fs_db2 $db = null): void;

    /** Idempotent '1'/'0' seed for a bool definition. */
    public static function seedBoolPair(int $idCaracteristica, ?\fs_db2 $db = null): void;
}
```

Targeted upsert (NOT `seed_if_empty`) — MySQL / PostgreSQL branches, mirroring
`TarifOpcionalExtMigration::copyLegacyRows()`:

```sql
-- PostgreSQL
INSERT INTO catalogo_caracteristicas
    (codigo, nombre, tipo, activo, importable, exportable, listable, orden, origen, valor_defecto)
VALUES (:codigo, :nombre, :tipo, TRUE, :imp, :exp, :list, :orden, :origen, :defecto)
ON CONFLICT (codigo) DO NOTHING;

-- MySQL
INSERT IGNORE INTO catalogo_caracteristicas
    (codigo, nombre, tipo, activo, importable, exportable, listable, orden, origen, valor_defecto)
VALUES (:codigo, :nombre, :tipo, TRUE, :imp, :exp, :list, :orden, :origen, :defecto);
```

`DO NOTHING`/`INSERT IGNORE` never overwrite operator edits to a default (name,
flags, `orden`, `valor_defecto`); they only restore a missing/out-of-band-deleted row
(CAR-11 "deleted default is restored", "existing install gains the defaults", "running
twice changes nothing"). `seedBoolPair()` inserts `'1'` (`orden 0`) and `'0'`
(`orden 1`) with the same guard on `UNIQUE (id_caracteristica, valor)`.

### 9.3 Default flags — pinned

| codigo | tipo | origen | importable | exportable | listable | valor_defecto |
|---|---|---|---|---|---|---|
| `medidas` | `string` | `catalogo_core` | FALSE | FALSE | FALSE | NULL |
| `en_catalogo` | `bool` | `tarifario` | TRUE | FALSE | FALSE | NULL |
| `en_tarifa` | `bool` | `tarifario` | TRUE | FALSE | FALSE | NULL |

**[DECISION]** `en_catalogo`/`en_tarifa` are `importable = TRUE` because the tarifario
wizard must keep mapping `En Tarifa`/`En Catálogo` as feature-backed fields
(`catalogo-excel-import-export` delta). They are **not** `exportable`/`listable` so the
catalogo_core article export stays byte-identical and the canonical list gains no
default column. `medidas` is inert until an operator opts in.
**[DECISION]** all three keep `valor_defecto = NULL`: a non-null default on
`en_catalogo` would make every product visible by default, contradicting the pinned
ALC-02 missing-row defaults (`en_tarifa FALSE` / `en_catalogo FALSE`).

### 9.4 `tarifario/Init.php`

```php
private static bool $caracteristicaDefaultsRegistered = false;

private function registerCaracteristicaDefaults(): void
{
    if (self::$caracteristicaDefaultsRegistered) { return; }
    $registry = \FSFramework\Plugins\catalogo_core\Services\CaracteristicaRegistry::class;
    $migration = \FSFramework\Plugins\catalogo_core\Services\CaracteristicaBackfillMigration::class;
    if (!class_exists($registry)) { return; }            // host absent ⇒ stay fatal-free
    $registry::registerDefault('en_catalogo', 'En Catálogo', 'bool', 'tarifario',
        ['importable' => true, 'orden' => 20], null);
    $registry::registerDefault('en_tarifa', 'En Tarifa', 'bool', 'tarifario',
        ['importable' => true, 'orden' => 21], null);
    if (class_exists($migration)) {
        $migration::migrateIfNeeded(\FSFramework\DependencyInjection\Container::db());
    }
    self::$caracteristicaDefaultsRegistered = true;
}
```

Called from both `init()` and `upgrade()`. The static guard makes it idempotent; the
`class_exists` guard makes boot order irrelevant (CAR-11 "tarifario registration survives
boot order"). `null` `$db` lets the registry resolve `Container::db()` itself (the
static seam keeps the tests able to inject a mock).

---

## 10. Panel — `ventas_caracteristicas` (CAR-18)

Files:
- `plugins/catalogo_core/Controller/VentasCaracteristicas.php` —
  `class VentasCaracteristicas extends PageController` (modern base, like
  `VentasOpcionales`).
- `plugins/catalogo_core/controller/ventas_caracteristicas.php` — global wrapper
  `class ventas_caracteristicas extends \FSFramework\Plugins\catalogo_core\Controller\VentasCaracteristicas {}`
  (byte-for-byte the `ventas_opcionales.php` pattern; supplies `check_fs_page`
  self-registration through the legacy path).
- `plugins/catalogo_core/View/ventas_caracteristicas.html.twig`.

`getPageData()`: `['name' => 'ventas_caracteristicas', 'title' => 'Características', 'menu' => 'catalogo', 'showonmenu' => true, 'ordernum' => 109]`.

State and actions (all mutations are POST with `validateFormToken()` first):

| Action | Effect | Gate |
|---|---|---|
| `save_definition` | create/update definition + flags (`nombre`, `tipo`, `activo`, `importable`, `exportable`, `listable`, `orden`, `valor_defecto`) | page access; `tipo` immutable after creation when value rows exist |
| `delete_definition` | delete definition (blocked when `origen !== ''` and when value rows exist) | CSRF + `allow_delete` + neutral permission event; **refused** for `origen !== ''` |
| `toggle_flag` | flip `activo`/`importable`/`exportable`/`listable` | CSRF + page access |
| `save_catalogo_valor` / `delete_catalogo_valor` | manage `catalogo_caracteristica_valores` | CSRF + `allow_delete` for delete |
| `assign_value` | `scope ∈ {articulo, familia, global}`, `codigo`, `codtarifa`, `key`, `id_valor` XOR `valor`; writes through `CaracteristicaValorStore` | CSRF + neutral permission event; global/family require the `assign_global`/`assign_familia` verdict |
| `clear_value` | delete the scope row | CSRF + the matching assign verdict |

- `CaracteristicaPermissionFilterEvent` mirrors `ArticlePermissionFilterEvent`
  (default-allow, `deny(reason)`), with actions `delete_definition`, `assign_global`,
  `assign_familia`; listeners are optional add-ons (tarifario may register one), zero
  listeners ⇒ allow. CSRF failure or a denied verdict MUST persist nothing (CAR-18).
- UX for the three scopes: a definition picker + tarifa picker; an **article** scope tab
  keyed by `referencia` (picker/autocomplete), a **familia** scope tab keyed by
  `codfamilia` (family tree), and an "**all products**" global form that writes exactly
  one row and is labelled as such (CAR-09: no fan-out).
- Per-tarifa values: the panel always operates on an explicitly selected tarifa; the
  "inherit from `DEF`" state is shown as a read-only derived indicator when no row exists
  for the requested tarifa. Saving materializes only the selected tarifa (CAR-08).
- The panel is the only place that calls `delete_definition`; the model re-checks
  `is_deletable()`.
- The view uses `{{ csrf_field() }}` in every `method="post"` form and Twig escaping
  everywhere (no `|raw` over user data).

---

## 11. Clone on tarifa copy (CAR-10)

`heredar_estructura()` gains one ordered step, **step 12**, after the eleven existing
steps (the "11. master opcional" step is currently last):

```php
// 12. Copiar valores de características (global, familia, articulo) por tarifa
$this->copy_caracteristicas($origen, $destino);
```

```php
private function copy_caracteristicas($origen, $destino): void
{
    foreach (['articulo', 'familia', 'global'] as $scope) {
        $this->caracteristica_value_model($scope)->copy_from_tarifa($origen, $destino);
    }
}

/** Overridable seam so the copy step is unit-testable without a live database. */
protected function caracteristica_value_model(string $scope): \FSFramework\model\caracteristica_scope_value
{
    return match ($scope) {
        'articulo' => new \FSFramework\model\catalogo_caracteristica_articulo(),
        'familia'  => new \FSFramework\model\catalogo_caracteristica_familia(),
        default    => new \FSFramework\model\catalogo_caracteristica_global(),
    };
}
```

Per-scope `copy_from_tarifa($origen, $destino)` contract (identical to
`tarif_tarifa_opcional::copy_from_tarifa()`):

1. `$origen === $destino` ⇒ return `true` (no-op; nothing deleted or inserted).
2. `begin_transaction()`; on failure return `false`.
3. `DELETE FROM <table> WHERE codtarifa = <destino>;`
4. `INSERT INTO <table> (codtarifa, <scope key cols>, id_caracteristica, id_valor, valor, custom) SELECT <destino>, <scope key cols>, id_caracteristica, id_valor, valor, custom FROM <table> WHERE codtarifa = <origen>;`
5. `commit()`; any failure ⇒ `rollback()` + `false`.

Both statements run with `$transaction = false` (no auto-commit), exactly as
`tarif_tarifa_opcional` does. The step never touches `tarif_tarifas.coddivisa`
(`R-TAR-CUR-008` untouched). Because `heredar_estructura()` is called from both the
create-with-inherit path (line 219) and `copy_structure()` (line 476), both paths get
the clone for free. Destination rows are replaced, not merged.

---

## 12. Import/export and listable integration (CAR-16, CAR-17)

### 12.1 Base headers stay byte-identical

- `ArticuloExcelExportService::EXPORT_HEADERS` is **not edited**. The signature becomes
  `buildSpreadsheet(array $articulos, bool $includeExampleRow = false, string $codtarifa = '', array $exportable = []): Spreadsheet`.
  Headers = `self::EXPORT_HEADERS` followed by each `exportable` definition's `nombre`
  (ordered `orden`, `codigo`); rows append one resolved cell per exportable definition,
  keyed by `codigo`, using the same renderer contract as §5. With `$exportable = []` the
  emitted workbook is byte-identical to the pre-change output (all existing callers and
  `ArticuloExcelExportServiceTest` stay green because the new parameters default to no
  feature columns).
- `ArticuloExcelImportWizardService`: `FIELD_CATALOG` stays a byte-identical
  `public const` (the base field catalog). Dynamic fields are added by a new
  **instance** surface:
  - `public function fieldCatalog(array $importable = []): array` — `self::FIELD_CATALOG` plus one entry per `importable` definition keyed by `codigo` with `['label' => nombre, 'column' => codigo, 'type' => tipo, 'aliases' => [mb_strtolower(nombre), codigo], 'feature' => true]`.
  - `public static function suggestMapping(array $headers, array $extraFields = []): array` — the existing static call site keeps working; `$extraFields` carries the feature aliases.
  - `public function fieldOptions(array $importable = []): array` — base options + feature options after the base ones.
  - `public function __construct(?array $importable = null)` — when `null`, loads `CaracteristicaResolver::importable_definitions()`; tests inject an explicit array (DB-free).
  - Feature persistence happens in the apply/row path through `CaracteristicaValorStore` for the wizard's selected tarifa; an unmapped/empty feature column is a no-op and never alters base matching/persistence (CAR-17).
- `ArticuloExcelImportWizardService::createArticuloFromRow()` (static) stays base-only:
  the article row persists first, then feature values are written. A rejected feature
  value reports an explicit motivo without corrupting/dropping the base save (CAR-17
  "invalid feature value does not corrupt the base save").

### 12.2 Listable columns (CAR-16)

- Placement (README decision 6): inside the existing
  `{% if fsc.tarifa_seleccionada %}` block of `View/ventas_articulos.html.twig`,
  **after the `Catálogo` `<th>`/`<td>` and before the `Stock`** one; `<thead>` loops
  `{% for def in fsc.listable_caracteristicas() %}` and `<tbody>` renders
  `{{ fsc.caracteristica_cell(articulo.referencia, def.codigo) }}`.
- The empty-row `colspan="10"` MAY adjust to account for the appended columns (ALC-02
  delta explicitly allows only this); every base header string and its relative order
  stay byte-identical.
- No tarifa selected ⇒ no feature column emitted (`listable` columns are per-tarifa).
- `load_caracteristica_columns()` is called once per page from `privateCore()` right
  after `load_articulo_tarifa_columns()`.

### 12.3 tarifario consumers

- `ExcelImportWizardService::FIELD_CATALOG` keeps its literal keys `en_tarifa` /
  `en_catalogo`; their metadata stops pointing at `tarif_articulo_precios` columns and
  gains `'column' => null, 'feature' => 'en_tarifa'|'en_catalogo'`. The persistence path
  (`createArticuloFromRowStatic`, the row handler) writes article-scope feature values
  for `$codtarifa`, with the create-path defaults `en_tarifa = true` /
  `en_catalogo = true` stored as feature values (tarifario delta spec).
- `tarif_catalogo_view`: per-row and export visibility switch on
  `CaracteristicaConfig::read_through()` (§8.4); its SQL membership filters stay
  legacy-by-design (DEV-17). `tarif_configurador_opcionales` and `tarif_articulo` have
  no visibility read at all after WU-4 (DEV-16): the configurator operates on
  association state and `tarif_articulo` only maps the import payload into feature
  values. `en_sap` and every unrelated read are untouched.

---

## 13. Hook context (catalogo-render-hooks delta)

Every frozen marker keeps passing `{'fsc','user','empresa','i18n'}` byte-identical and
in addition passes `'caracteristicas' => $map`, where `$map` is
`codigo => effective value` for the host entity and the selected tarifa. Resolution uses
one batched read (never per definition, never persisting). The host controllers expose:

```php
/** @return array<string,?string> codigo => raw effective value */
public function caracteristicas_context(?string $referencia = null, ?string $codfamilia = null, ?string $codtarifa = null): array;
```

which internally calls `CaracteristicaValorBatchReader`/`CaracteristicaResolver`. The
four frozen marker names, their positions and the owned hook-pair contract are unchanged.

---

## 14. Strict-TDD test strategy

`strict_tdd: true`. Each work unit lands its contract tests **first** (failing), then the
implementation. Tests run under
`ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (note
`processIsolation="true"` — static guards and `$GLOBALS['plugins']` state must be handled
as the existing tests do) and under the root **Plugins** suite.

### 14.1 Contract tests to write first, per work unit

| WU | Test file (new unless marked migrated) | Spec scenarios covered |
|---|---|---|
| WU-1 | `tests/CaracteristicaModelTest.php` | CAR-01 (3), CAR-02 (2) |
| WU-1 | `tests/CaracteristicaValorCatalogoTest.php` | CAR-03 (2) |
| WU-1 | `tests/CaracteristicaValorTriadTest.php` | CAR-04 triad (2) |
| WU-1 | `tests/CaracteristicaScopeTableTest.php` | CAR-05 keys/cascades (2) |
| WU-1 | `tests/InitCaracteristicasTablesTest.php` | CAR-05 standalone bootstrap |
| WU-1 | `tests/CaracteristicaDefaultsTest.php` | CAR-11 (fresh/existing/deleted/non-deletable) |
| WU-1 | `tests/Controller/VentasCaracteristicasControllerTest.php` | CAR-18 (5) |
| WU-1 | `tests/CaracteristicaBoundariesTest.php` | CAR-19 (grep gate, §14.3) |
| WU-2 | `tests/CaracteristicaResolverTest.php` | CAR-04 empty-vs-absent, CAR-06 (6), CAR-07 (2) |
| WU-2 | `tests/CaracteristicaValorStoreTest.php` | CAR-08 (2) |
| WU-2 | `tests/CaracteristicaAssignmentScopeTest.php` | CAR-09 (3) |
| WU-2 | `tests/Controller/VentasArticulosListCaracteristicasTest.php` | CAR-16 (4) |
| WU-2 | `tests/Services/ArticuloExcelCaracteristicaTest.php` | CAR-17 (2) |
| WU-3 | `tests/CaracteristicaBackfillTest.php` | CAR-13 (4) |
| WU-3 | `tests/CaracteristicaReadThroughTest.php` | CAR-14 (3) |
| WU-4 | `tests/OpcionalVisibilityDerivationTest.php` | CAR-12 (4) |
| WU-4 | `tests/OpcionalVisibilityParityTest.php` | CAR-12 parity (1) |
| WU-4 | `tests/CaracteristicaColumnDropTest.php` | CAR-15 clause 1 + gating |
| WU-5 | `tests/CaracteristicaCloneTest.php` | CAR-10 (3) |
| WU-5 | `tests/Controller/TarifTarifasHeredarCaracteristicaTest.php` | CAR-10 step 12 wiring |
| WU-6 | `plugins/tarifario/tests/CaracteristicaDefaultsRegistrationTest.php` | CAR-11 boot order |
| WU-6 | `plugins/tarifario/tests/Services/ExcelImportWizardServiceTest.php` (migrated) | tarifario create path + canary |
| WU-6 | `plugins/tarifario/tests/Services/ExcelImportWizardFieldCatalogTest.php` (migrated) | FIELD_CATALOG feature-backed keys |
| WU-6 | `plugins/tarifario/tests/Controller/TarifCatalogoOpcionalMasterExportTest.php` (migrated) | R-TAR-HOOK-011 export set |
| WU-7 | `tests/CaracteristicaColumnDropTest.php` (clause 2 + dead-column step) + `tests/CaracteristicaReversibilityTest.php` | CAR-15 post-soak + reversibility |
| WU-8 | `plugins/tarifario/tests/Controller/TarifCatalogoJsonImportVisibilityTest.php` (DEV-18) + `TarifCatalogoMembershipFilterRatificationTest.php` (DEV-17) + `ArticuloExcelCaracteristicaTest`/`CaracteristicaColumnDropTest` extensions | soak blockers + SUGGESTION 2/4 |

Additionally migrated by WU-4 (spec-reference name → new file where the delta lists one):
`tests/TarifFamiliaToggleTest.php` (familia toggles write feature values),
`tests/TarifTabPreciosTest.php`, `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`,
`tests/TarifOpcionalesControllerMasterStateTest.php`,
`tests/TarifOpcionalesControllerContractTest.php`,
`tests/TarifOpcionalEditCaracteristicaTest.php`,
`tests/TarifTarifaOpcional*.php`.

### 14.2 Locked-contract regression set (run unedited, must stay green)

`tests/Controller/VentasArticulosListAbsorptionTest.php`,
`tests/ArticuloListaCanonicaOwnershipTest.php`,
`tests/Services/ArticuloExcelExportServiceTest.php`,
`tests/Services/ArticuloExcelImportWizardServiceTest.php`,
`tests/Integration/CatalogoCoreHookMarkersTest.php`,
`tests/Integration/CatalogoArticuloHookOwnershipTest.php`,
`tests/OpcionalDomainModelOwnershipTest.php`,
`tests/TarifTarifaOpcionalTest.php`, `tests/TarifTarifaOpcionalPrecedenceTest.php`,
`tests/TarifTarifaOpcionalLifecycleTest.php`,
`tests/ArticuloTarifaPrecioOwnershipTest.php`, plus the root **Plugins** suite and
`ddev exec composer phpstan`.

`InitUpgradeTest.php` is excluded from the root suite but must still pass in the plugin
suite.

### 14.3 Grep gate definition (CAR-19)

Hard gate: **no catalogo_core production file may reference a tarifario-owned class**,
with a frozen, pre-existing exception set.

```
# 1. Tarifario-owned short names (production, non-test, non-vendor)
grep -rhoE '^(final |abstract )?(class|interface|trait|enum) [A-Za-z_][A-Za-z0-9_]*' \
     plugins/tarifario --include='*.php' \
  | grep -v '/vendor/' | grep -v '/tests/' | awk '{print $NF}' | sort -u

# 2. catalogo_core references (production only)
grep -rnE '\b(<names>)\b|FSFramework\\Plugins\\tarifario' plugins/catalogo_core \
     --include='*.php' | grep -v '/openspec/' | grep -v '/tests/'
```

Baseline allowlist (pre-existing, observed 2026-09-14 during PR-1 apply; referenced
ONLY as string arguments to `tarif_tarifas.php::loadTarifarioModel()` or pre-existing
page strings, and MUST NOT grow; reconciled with `CaracteristicaBoundariesTest::BASELINE`):
`tarif_grupo_rol`, `tarif_grupo_tarifa`, `tarif_grupo_usuario`, `tarif_historial_precios`,
`tarif_precio_historial`, `tarif_tarifa_rol`. Any other hit fails the gate.
`CaracteristicaBoundariesTest.php` implements this scan in PHP (file reads + regex) so
the gate runs in CI, and also asserts: no file under `base/`, `src/`, root
`controller/` or root `model/` changed by this change (diff-based check), no entry under
the repository-root `openspec/changes/caracteristicas-producto/`, and no new Composer
requirement in either plugin.

---

## 15. Work-unit decomposition and delivery

The proposal's seven work units are refined below with dependencies, file scope and test
scope. WU-1 + WU-2 are the atomic foundation (the layer is unusable until both land).

| WU | Depends on | File-level scope (production) | Test scope | Size (est.) |
|---|---|---|---|---|
| **WU-1** Definition + catalog + 3 scope tables + defaults + panel | — | 5 models (`model/core/caracteristica_scope_value.php`, `catalogo_caracteristica*.php`), 5 XML, `Services/CaracteristicaRegistry.php`, `Controller/VentasCaracteristicas.php`, `controller/ventas_caracteristicas.php`, `View/ventas_caracteristicas.html.twig`, `Event/CaracteristicaPermissionFilterEvent.php`, `Init.php` (ensure + registry) | 14.1 WU-1 (8 files) | ~1,400 lines |
| **WU-2** Resolver + store + batch reader + list/export integration | WU-1 | `Services/CaracteristicaResolver.php`, `CaracteristicaValorStore.php`, `CaracteristicaValorBatchReader.php`, `CaracteristicaConfig.php`, `extras/VentasArticulosListTrait.php`, `Controller/VentasArticulos.php`, `View/ventas_articulos.html.twig`, `Services/ArticuloExcel{Export,ImportWizard}Service.php` | 14.1 WU-2 (5 files) | ~1,600 lines |
| **WU-3** Backfill + read-through flag + consumer rewrites + ALC-02/export spec rewrites | WU-2 | `Services/CaracteristicaBackfillMigration.php`, `Init.php` (backfill call), `extras/VentasArticulosListTrait.php` + export/import read-through branches, `Services/ArticuloTarifaPrecioBatchReader.php` (read seam) | 14.1 WU-3 (2 files) + locked ALC-02/export tests | ~1,200 lines |
| **WU-4** D12 derivation + opcional flag/toggle removal + spec rewrites | WU-3 | `Services/CaracteristicaResolver.php` (D12 methods), `Services/CaracteristicaColumnDropMigration.php` (clause 1), `extras/VentasOpcionalesListTrait.php`, `Controller/VentasOpcionales.php`, `View/ventas_opcionales.html.twig`, `model/tarif_opcional_ext.php`+XML, `model/tarif_tarifa_opcional.php`+XML, `controller/tarif_familias.php`, `controller/tarif_opcional_edit.php`, `controller/tarif_opcional_tab.php`, `Controller/VentasArticulo.php`, `controller/tarif_tab_precios.php`, views/macros | 14.1 WU-4 (3 new + 7 migrated) | ~1,500 lines |
| **WU-5** Clone step + clone tests | WU-2 | `controller/tarif_tarifas.php` (step 12 + `copy_caracteristicas()` + seam) | 14.1 WU-5 (2 files) | ~250 lines |
| **WU-6** tarifario defaults + consumer rewrites + tarifario spec deltas | WU-2 | `plugins/tarifario/Init.php`, `Services/ExcelImportWizardService.php`, `Services/ExcelHierarchyService.php`, `Services/ExcelRowUpdater.php`, `Services/ArticuloListActionHandler.php`, `process_excel_wizard.php`, `model/tarif_articulo.php`, `controller/tarif_catalogo_view.php`, `controller/tarif_configurador_opcionales.php`, views | 14.1 WU-6 (4 files) | ~1,000 lines |
| **WU-7** Post-soak gated `DROP COLUMN` (destructive) | WU-4 soak + WU-8 (DEV-17/DEV-18 closed) | `Services/CaracteristicaColumnDropMigration.php` (clause 2 + dead-column step), gated entry point | 14.1 WU-7 (2 files) | ~200 lines |

Total authored: **~4,000–7,000 lines**, ~56 production files across two plugins
(consistent with the proposal's size forecast).

### 15.1 `single-pr` vs `size:exception` vs chained — explicit implication

- `delivery_strategy: single-pr` with `review_budget_lines: 400` **cannot** fit this
  change. Landing it as one PR requires a maintainer-approved **`size:exception`**.
- Chained PRs are the recommendation. Even chained, only WU-5 (~250) and WU-7 (~200)
  come near the 400-line budget; WU-2/WU-3/WU-4 each exceed it, so **each oversized PR
  in the chain also carries a `size:exception`** — the chain reduces review blast radius
  and keeps work units independently revertible, it does not eliminate the exception.
- Recommended chain (PR #1 targets the tracker branch `caracteristicas-producto`, then
  each subsequent PR targets its predecessor):
  1. **PR-1**: WU-1 + WU-2 (atomic foundation; `size:exception`).
  2. **PR-2**: WU-3 (backfill + read-through; `size:exception`).
  3. **PR-3**: WU-4 (D12 removal; `size:exception`).
  4. **PR-4**: WU-5 + WU-6 (clone + tarifario; may fit with a smaller exception).
  5. **PR-5**: WU-7, opened only after the soak and a clean `verify-report.md`; the most
     decisive revert point of the change.
- `tasks.md` MUST record the chosen option (single `size:exception` vs chained) before
  apply starts, and MUST NOT start WU-4 before WU-3's parity evidence exists.

### 15.2 Rollback per WU

WU-1/WU-2/WU-3 are additive: revert the commits, drop the five new tables, set the
read-through constant explicitly to `FALSE` (the emergency opt-out); legacy columns stay
authoritative. WU-4's opcional drop is reversible
only after its parity evidence; WU-5/WU-6 are independently revertible. WU-7 is a
separate commit with a pre-drop dump and a re-derive-from-feature-values restore path.
Clear the Twig cache after any revert (frozen markers/templates).

---

## 16. References

- `plugins/catalogo_core/Services/ArticuloTarifaPrecioBatchReader.php` — batch-read shape.
- `plugins/catalogo_core/model/tarif_tarifa_opcional.php` — lazy `effective()` + transactional `copy_from_tarifa()`.
- `plugins/catalogo_core/model/core/{catalogo_opcional,catalogo_opcional_familia,catalogo_articulo_opcional,catalogo_articulo_opcional_grupo}.php` — scopes + propagation + group parents.
- `plugins/catalogo_core/Services/TarifOpcionalExtMigration.php` — driver-aware introspection + `NOT EXISTS` migration.
- `plugins/catalogo_core/Init.php` — ensure/seed/migrate seams; `touchNamespacedModel`, `ensureFamiliasTarifaTables`.
- `plugins/catalogo_core/controller/tarif_tarifas.php::heredar_estructura()` — 11-step clone orchestrator.
- `plugins/catalogo_core/Event/ArticlePermissionFilterEvent.php` — neutral default-allow permission filter.
- `plugins/catalogo_core/extras/VentasArticulosListTrait.php`, `Services/ArticuloExcel{Export,ImportWizard}Service.php`, `View/ventas_articulos.html.twig` — list/export/import seams.
- `src/View/ViewHookRegistry.php` — frozen hook renderer.
- `plugins/catalogo_core/model/core/familia.php` — `madre` hierarchy.

## 17. skill_resolution

- **`fsframework-plugin-sdd` — LOADED and APPLIED.** Routing confirmed: the change is
  100% plugin-local (`plugins/catalogo_core/` + `plugins/tarifario/`) → SDD root is
  `plugins/catalogo_core/openspec/` (`ownership: plugin-local`,
  `change_root: plugins/catalogo_core/openspec/changes/{name}/`). No entry created or
  planned in the repository-root `openspec/` (anti-pattern avoided). Dispatcher
  limitation acknowledged: `sdd-archive` must receive the explicit plugin path.
- **`fsframework-model-crud` — LOADED and APPLIED.** The five models follow the
  `model/table/*.xml` + `model/core/*.php` convention, `parent::__construct($table)`,
  `test()/save()/delete()/exists()`, `str2bool`/`no_html`/`var2str` hygiene, and the
  anonymous-subclass + mock-`fs_db2` test pattern; the shared abstract base and the
  `install()` FK-touch ordering mirror `tarif_tarifa_opcional.php`.
- Supporting skills expected in later phases (not executed here): `fsframework-test-writing`,
  `fsframework-security-review` (CSRF/permission-gated panel), `chained-pr` (delivery),
  `work-unit-commits`. No `fsframework-instruction-sync` needed (no shared convention
  change). No new Composer dependency ⇒ no `vendor/` commit concern.
- **CodeGraph note**: the project index does not cover `plugins/**` (queries returned
  core files only), so the reference implementations were read directly; this is a
  tooling limitation, not a repo change.
