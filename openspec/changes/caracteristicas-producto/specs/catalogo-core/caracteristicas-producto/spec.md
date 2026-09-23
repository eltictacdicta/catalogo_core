# Delta for caracteristicas-producto

New capability owned by `catalogo_core`. All requirements are ADDED; there is no
prior canonical `caracteristicas-producto` spec and no MODIFIED/REMOVED block for
this domain. The locked specs that consume this capability
(`articulo-lista-canonica`, `articulos-excel-import-export`,
`opcionales-tarifa-management`, `opcionales-management`,
`familias-tarifa-management`, `articulo-tarifa-tab-management`,
`articulo-detalle-canonico`, `opcionales-tarifa-selector`,
`catalogo-render-hooks`, tarifario `catalogo-excel-import-export`,
`tarifario/catalogo-integration`) carry their own deltas in this change.

Scope of this capability: the feature definition entity, the predefined value
catalog, the three tarifa-keyed scope value tables, the uniform resolver, the
lazy `DEF` inheritance, the assignment scopes, the tarifa-copy clone step, the
plugin-registered defaults, the D12 opcional visibility derivation, the legacy
supersession (backfill / read-through / dual-write / gated drop), and the
list + import/export + panel integration.

Type support is `bool` + `string` only. No type beyond those two may be accepted
until a later change extends the whitelist.

## ADDED Requirements

### Requirement: CAR-01 — Feature definition entity

`catalogo_core` MUST own a `catalogo_caracteristicas` table/model with PK `id`,
UNIQUE `codigo`, and columns `nombre`, `tipo`, `activo`, `importable`,
`exportable`, `listable`, `orden`, `origen`, `valor_defecto`. `codigo` MUST be
the stable key used by plugin registration and by import mapping. The model MUST
expose the definitions ordered by `orden` then `codigo`.

#### Scenario: Definition persists and is addressable by codigo

- GIVEN a definition payload with a unique `codigo` and a valid `tipo`
- WHEN `save()` runs
- THEN the row persists with its flags, `orden` and `origen`
- AND reading it back by `codigo` returns the same definition
- Test: `plugins/catalogo_core/tests/CaracteristicaModelTest.php`

#### Scenario: Duplicate codigo is rejected

- GIVEN an existing definition `codigo = 'medidas'`
- WHEN a second definition with `codigo = 'medidas'` is saved
- THEN the save fails, an error is reported, and only one row exists
- Test: `plugins/catalogo_core/tests/CaracteristicaModelTest.php`

#### Scenario: Ordered listing

- GIVEN definitions with `orden` 20, 0 and 10
- WHEN the definitions are listed
- THEN they come back ordered by `orden` ascending, ties broken by `codigo`
- Test: `plugins/catalogo_core/tests/CaracteristicaModelTest.php`

### Requirement: CAR-02 — Value type whitelist (`bool` | `string`)

`tipo` MUST be validated against the closed whitelist `['bool', 'string']`.
Any other value MUST be rejected by `test()` with an explicit error and MUST NOT
persist. The whitelist MUST be exposed as a class constant so tests can iterate
it without instantiating the model.

#### Scenario: Whitelist accepts bool and string

- GIVEN the two whitelisted `tipo` values
- WHEN `test()` runs for each
- THEN both return TRUE
- Test: `plugins/catalogo_core/tests/CaracteristicaModelTest.php`

#### Scenario: Unknown type is rejected

- GIVEN `tipo = 'int'`
- WHEN `test()` runs
- THEN it returns FALSE, reports a whitelist error, and `save()` persists nothing
- Test: `plugins/catalogo_core/tests/CaracteristicaModelTest.php`

### Requirement: CAR-03 — Predefined value catalog

`catalogo_core` MUST own a `catalogo_caracteristica_valores` table with PK `id`,
FK `id_caracteristica` → `catalogo_caracteristicas(id)` CASCADE, UNIQUE
`(id_caracteristica, valor)`, and columns `valor`, `orden`, `activo`. The
catalog MUST be per definition and MUST be seeded with the pair
`valor = '1'` / `valor = '0'` (rendered `Sí` / `No`) for every `bool` definition.

#### Scenario: Catalog values are per definition and unique

- GIVEN a definition with predefined values `Sí` and `No`
- WHEN a second row with the same `(id_caracteristica, valor)` is inserted
- THEN the insert is rejected
- AND the values list for a different definition is unaffected
- Test: `plugins/catalogo_core/tests/CaracteristicaValorCatalogoTest.php`

#### Scenario: Bool definitions get the seeded pair

- GIVEN a newly registered `bool` definition
- WHEN registration completes
- THEN exactly one `'1'` (Sí) and one `'0'` (No) catalog value exist for it
- Test: `plugins/catalogo_core/tests/CaracteristicaValorCatalogoTest.php`

### Requirement: CAR-04 — Custom value per product and value triad invariant

A value row MUST store exactly one of: `id_valor` (predefined, `custom = FALSE`)
or `valor` (custom, `custom = TRUE`). A row with both or with neither MUST be
rejected by `test()`. For `tipo = bool` definitions, custom values MUST be
rejected and only predefined catalog values accepted. String values MUST be
normalized with `no_html`; an empty custom string is a stored value, while the
absence of a row means "no value".

#### Scenario: Exactly one value representation is enforced

- GIVEN a value payload with both `id_valor` and `valor` (or with neither)
- WHEN `test()` runs
- THEN it returns FALSE and `save()` persists nothing
- Test: `plugins/catalogo_core/tests/CaracteristicaValorTriadTest.php`

#### Scenario: Bool rejects custom values

- GIVEN a `bool` definition and a row carrying `valor` with `custom = TRUE`
- WHEN `test()` runs
- THEN it returns FALSE and no row persists
- Test: `plugins/catalogo_core/tests/CaracteristicaValorTriadTest.php`

#### Scenario: Empty string and absence stay distinguishable

- GIVEN a stored custom value of `''` for a string definition and no row for another
- WHEN the resolver reads both
- THEN the first returns the stored empty string and the second returns "no value"
- Test: `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`

### Requirement: CAR-05 — Scope value tables keyed by tarifa and scope

Three value tables MUST exist, one per scope, each keyed by `codtarifa` plus the
scope reference and `id_caracteristica`:

| Scope | Table | PK |
|---|---|---|
| global | `catalogo_caracteristica_global` | `(codtarifa, id_caracteristica)` |
| familia | `catalogo_caracteristica_familia` | `(codtarifa, codfamilia, id_caracteristica)` |
| articulo | `catalogo_caracteristica_articulo` | `(codtarifa, referencia, id_caracteristica)` |

Each table MUST carry the value triad (`id_valor`, `valor`, `custom`). FKs MUST
be CASCADE: `codtarifa → tarif_tarifas`, `referencia → articulos`,
`codfamilia → familias`, `id_caracteristica`/`id_valor` → the feature tables.

#### Scenario: Keys and cascades resolve

- GIVEN the three scope tables
- WHEN columns, primary keys and foreign keys are inspected
- THEN each PK matches the table above and every FK target resolves with CASCADE
- Test: `plugins/catalogo_core/tests/CaracteristicaScopeTableTest.php`

#### Scenario: Deleting a product cascades its feature values only

- GIVEN product `A` and product `B` each carrying an article-scope value
- WHEN product `A` is deleted
- THEN only `A`'s value rows are removed and `B`'s remain
- Test: `plugins/catalogo_core/tests/CaracteristicaScopeTableTest.php`

#### Scenario: Standalone bootstrap is FK-safe and idempotent

- GIVEN a database without the feature tables and `tarifario` inactive
- WHEN `Init::ensureCaracteristicasTables()` runs, after `articulos`/`familias`/`tarif_tarifas` are available
- THEN all five tables exist with every FK target resolving
- AND a second run changes no schema and does not fatal when a model class is absent
- Test: `plugins/catalogo_core/tests/InitCaracteristicasTablesTest.php`

### Requirement: CAR-06 — Effective-value precedence

The resolver MUST compute the effective value in this order:

1. Scope walk, nearest-first: `articulo` → `familia` (the product's own
   `codfamilia`, then `madre` recursively) → `global`.
2. Within each scope: the **requested tarifa** row, then the **`DEF`** tarifa row.
   The first scope yielding either wins.
3. Terminal fallback after every scope failed: the definition's `valor_defecto`,
   otherwise "no value" (`null`).

The scope walk MUST stop at the first scope that yields a value; a value found at
`articulo` scope MUST NOT be overridden by a `global` row. A family-scope row on
a nearer ancestor MUST win over a row on a farther ancestor.

#### Scenario: Product scope wins over family and global

- GIVEN a value at articulo scope, a different one at familia scope and a third at global scope
- WHEN the effective value is resolved for that product and tarifa
- THEN the articulo value is returned
- Test: `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`

#### Scenario: Nearest family ancestor wins

- GIVEN a value on the product's own family and another on its `madre`
- WHEN the effective value is resolved
- THEN the own-family value is returned
- AND removing it resolves the `madre` value
- Test: `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`

#### Scenario: Requested tarifa row beats the DEF row

- GIVEN an articulo-scope row for tarifa `T2` and a different one for `DEF`
- WHEN the effective value is resolved for `T2`
- THEN the `T2` value is returned
- AND resolving for an unrelated tarifa `T9` returns the `DEF` value
- Test: `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`

#### Scenario: Scope precedence beats tarifa fallback

- GIVEN an articulo-scope row for `DEF` only and a global-scope row for `T2`
- WHEN the effective value is resolved for `T2`
- THEN the articulo `DEF` value is returned (articulo > global)
- Test: `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`

#### Scenario: valor_defecto is the terminal fallback

- GIVEN a definition with `valor_defecto = 'X'` and no value rows at any scope
- WHEN the effective value is resolved
- THEN `'X'` is returned
- AND with `valor_defecto` empty the result is "no value", never `FALSE`
- Test: `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`

#### Scenario: Absent family does not block the global fallback

- GIVEN a product with no `codfamilia` and a global-scope value for the tarifa
- WHEN the effective value is resolved
- THEN the global value is returned without error
- Test: `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`

### Requirement: CAR-07 — Reads never persist

Resolving an effective value MUST be a pure read: no scope row, no `DEF` row and
no materialized row may be written as a side effect. Only an explicit assignment
write may create or update a value row.

#### Scenario: Resolution issues no writes

- GIVEN a product/spec with no scope rows
- WHEN the effective value is resolved for several tarifas
- THEN the three scope tables are byte-identical before and after
- Test: `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`

#### Scenario: Reading does not materialize an inherited value

- GIVEN a `DEF`-only value resolved for tarifa `T2`
- WHEN the resolution completes
- THEN no row for `(T2, …)` was created
- Test: `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`

### Requirement: CAR-08 — Lazy `DEF` inheritance with first-write materialization

The requested tarifa MUST inherit from `DEF` lazily: a missing row is resolved
through `DEF` without a mandatory backfill. The first write for that
`(tarifa, scope, key)` MUST materialize a row for the requested tarifa only; it
MUST NOT fan out into other tarifas or other scope rows.

#### Scenario: First write materializes only the requested tarifa

- GIVEN a `DEF` value inherited by tarifas `T2` and `T3`
- WHEN `T2` is assigned a value
- THEN exactly one new row exists for `T2`, `DEF` is unchanged and `T3` still inherits `DEF`
- Test: `plugins/catalogo_core/tests/CaracteristicaValorStoreTest.php`

#### Scenario: Existing databases resolve without backfill

- GIVEN an existing database with no feature value rows
- WHEN the resolver runs after deployment
- THEN values resolve by `DEF`/`valor_defecto` and no backfill was required
- Test: `plugins/catalogo_core/tests/CaracteristicaValorStoreTest.php`

### Requirement: CAR-09 — Assignment scopes

Feature values MUST be assignable at exactly three scopes: **individual product**
(`referencia`), **all products / global**, and **family/subfamily**
(`codfamilia`), each per tarifa. "All products" MUST be expressed as a single
global row and MUST NOT fan out per product. Family assignment MUST accept any
node of the `madre` hierarchy, and propagation to that family's products MUST be
resolved lazily (read-time walk), never by copying rows into article scope.

#### Scenario: Global assignment stores one row

- GIVEN an operator assigns a value to "all products" for tarifa `T2`
- WHEN the assignment persists
- THEN exactly one global row exists and zero article rows were created
- Test: `plugins/catalogo_core/tests/CaracteristicaAssignmentScopeTest.php`

#### Scenario: Family assignment covers descendants lazily

- GIVEN a value assigned to a parent family
- WHEN a product of a descendant subfamily resolves the value with no nearer row
- THEN the parent-family value is returned
- AND no article-scope row was created by the resolution
- Test: `plugins/catalogo_core/tests/CaracteristicaAssignmentScopeTest.php`

#### Scenario: Product assignment overrides family and global

- GIVEN global and family values for the tarifa
- WHEN the product is assigned its own value
- THEN the product value wins for that product only, other products keep the family/global value
- Test: `plugins/catalogo_core/tests/CaracteristicaAssignmentScopeTest.php`

### Requirement: CAR-10 — Clone of feature values on tarifa copy

Each of the three scope tables MUST expose a transactional
`copy_from_tarifa($origen, $destino)` (DELETE destination rows + INSERT…SELECT
from the origin in one transaction), and `controller/tarif_tarifas.php::heredar_estructura()`
MUST call them as an ordered step after the eleven existing copy steps. The step
MUST be a no-op when `$origen === $destino` and MUST NOT copy or alter
`tarif_tarifas.coddivisa` (destination currency respected, `R-TAR-CUR-008`).

#### Scenario: Copy carries the three scopes

- GIVEN a source tarifa with global, familia and articulo feature values
- WHEN `heredar_estructura($origen, $destino)` runs
- THEN the destination holds the same three sets and the origin is unchanged
- Test: `plugins/catalogo_core/tests/CaracteristicaCloneTest.php`

#### Scenario: Destination rows are replaced, not merged

- GIVEN a destination with stale feature rows for the same scope keys
- WHEN the copy runs
- THEN stale rows for those keys are deleted and replaced by the origin rows
- Test: `plugins/catalogo_core/tests/CaracteristicaCloneTest.php`

#### Scenario: Same origin and destination is a no-op

- GIVEN `$origen === $destino`
- WHEN the copy step runs
- THEN no row is deleted or inserted and `coddivisa` is untouched
- Test: `plugins/catalogo_core/tests/CaracteristicaCloneTest.php`

### Requirement: CAR-11 — Default feature registration

`catalogo_core` MUST register the `medidas` (`string`) definition and `tarifario`
MUST register the `en_catalogo` and `en_tarifa` (`bool`) definitions. Registration
MUST be a targeted idempotent upsert (`INSERT … WHERE NOT EXISTS (codigo = ?)`),
NOT `seed_if_empty()`, and MUST run on **both** fresh installs and existing
installs (from `init()` and `upgrade()`), seeding the bool predefined pair.
`origen` MUST persist the registering plugin name. A definition with a non-empty
`origen` MUST NOT be deletable but MAY be deactivated and reactivated. `tarifario`
registration MUST be guarded by `class_exists()` plus a static guard and MUST be
re-attempted from `upgrade()` so boot order cannot skip it.

#### Scenario: Fresh install registers all three defaults

- GIVEN an empty features schema
- WHEN catalogo_core and tarifario boot
- THEN `medidas`, `en_catalogo` and `en_tarifa` exist with their `origen` and types, and each bool has its pair
- Test: `plugins/catalogo_core/tests/CaracteristicaDefaultsTest.php`

#### Scenario: Existing install gains the defaults

- GIVEN an install that already has operator-created feature rows
- WHEN the upgrade runs
- THEN the three defaults are added without touching the existing rows
- AND running the upgrade twice changes nothing
- Test: `plugins/catalogo_core/tests/CaracteristicaDefaultsTest.php`

#### Scenario: Deleted default is restored

- GIVEN a default definition deleted out of band
- WHEN the upgrade runs again
- THEN the default is re-registered
- Test: `plugins/catalogo_core/tests/CaracteristicaDefaultsTest.php`

#### Scenario: Registered defaults cannot be deleted

- GIVEN a definition with non-empty `origen`
- WHEN a delete is requested
- THEN it is blocked with an explicit error, while deactivation and reactivation succeed
- Test: `plugins/catalogo_core/tests/CaracteristicaDefaultsTest.php`

#### Scenario: Tarifario registration survives boot order

- GIVEN tarifario booting before catalogo_core classes are available
- WHEN tarifario's init and later upgrade run
- THEN no fatal occurs and both bool defaults are registered exactly once
- Test: `plugins/tarifario/tests/CaracteristicaDefaultsRegistrationTest.php`

### Requirement: CAR-12 — D12 opcional visibility derivation

Opcionals MUST NOT own `en_catalogo`/`en_tarifa` flags. An opcional's effective
catalog/tarifa visibility MUST be derived from its parent product's effective
feature value for the selected tarifa, with the D12 existential union: when an
opcional is attached to several parents, it is visible if **any** parent is
visible. Parent resolution is: article-attached → that article's effective value
(article → family → global); family-assigned → that family's effective value
(family walk with `madre`); unassigned → the global scope value.

#### Scenario: Opcional follows its single parent product

- GIVEN an opcional attached to a product whose effective `en_catalogo` is FALSE
- WHEN the opcional's catalog visibility is resolved
- THEN it is not visible, and it becomes visible when the parent is set TRUE
- Test: `plugins/catalogo_core/tests/OpcionalVisibilityDerivationTest.php`

#### Scenario: Multi-parent existential union

- GIVEN an opcional attached to two products, one visible and one not
- WHEN its visibility is resolved
- THEN it is visible (any-parent union)
- Test: `plugins/catalogo_core/tests/OpcionalVisibilityDerivationTest.php`

#### Scenario: Family-assigned opcional uses the family value

- GIVEN an opcional assigned to a family with no article attachment
- WHEN visibility is resolved and the family value is set FALSE
- THEN the opcional is not visible for that tarifa, and other tarifas are unaffected
- Test: `plugins/catalogo_core/tests/OpcionalVisibilityDerivationTest.php`

#### Scenario: Unassigned opcional follows the global value

- GIVEN an opcional with no article and no family assignment
- WHEN visibility is resolved
- THEN the global scope value for the tarifa is used
- Test: `plugins/catalogo_core/tests/OpcionalVisibilityDerivationTest.php`

#### Scenario: Behavior is preserved against the pre-change flags

- GIVEN the pre-change visibility of every opcional (legacy flags) for a tarifa
- WHEN the derived visibility is compared after the backfill
- THEN both sets are identical
- Test: `plugins/catalogo_core/tests/OpcionalVisibilityParityTest.php`

### Requirement: CAR-13 — Legacy visibility supersession backfill

`catalogo_core` MUST provide an idempotent backfill
(`Services/CaracteristicaBackfillMigration::migrateIfNeeded($db)`, called from
`init()` and `upgrade()`) that maps the legacy visibility columns into feature
values, using `INSERT … WHERE NOT EXISTS` for every statement:

- `tarif_articulo_precios` (`referencia`, `codtarifa`) → articulo scope;
- `tarif_tarifa_articulo` (`referencia`, `codtarifa`) → articulo scope, when the
  price row is absent;
- `tarif_tarifa_familia` (`codfamilia`, `codtarifa`) → familia scope;
- `tarif_familia_ext` global family rows → `DEF` familia-scope rows, **only when
  that table carries the visibility columns**: the live schema has just
  `codfamilia`, `capitulo` and `nivel` (design §8.6 divergence 1), so the step is
  an introspection-guarded no-op on the live tree and its intent is covered by the
  `tarif_tarifa_familia` step plus the resolver's `DEF` fallback.

`tarif_articulo_precios` is the live table name (the model class is
`tarif_articulo_precio`); every backfill and drop reference uses the table name.

Opcional flags MUST NOT be backfilled as opcional rows (D12); where an opcional
legacy flag had no parent article/family value, the backfill MUST seed the
corresponding parent/global value so pre-change visibility is preserved.

**Opcional conflict policy (deterministic, first parent value wins).** The
opcional-derived seeds are keyed by parent (`codtarifa` + `referencia`/`codfamilia`/
global + `id_caracteristica`, at the `DEF` tarifa) and every statement is guarded by
`INSERT … WHERE NOT EXISTS` on that key, so at most one value exists per parent key.
When several opcionales share a parent and carry **differing** legacy visibility:

1. the backfill MUST NOT create a second value for a conflicted parent key — the
   `NOT EXISTS` guard leaves exactly one value per key — and MUST NOT abort the
   whole migration: the remaining keys and legacy surfaces keep being processed;
2. the backfill MUST NOT overwrite the already-seeded value, and MUST NOT create a
   temporary per-opcional override row (D12 removes opcional-owned visibility);
3. the seeded value is the legacy value of whichever conflicting sibling reached the
   empty key first, so the outcome is deterministic and re-running the migration
   never changes it;
4. every sibling whose legacy flag contradicts the seeded parent value is the
   documented CAR-12 exception: it is reported by the parity comparison run before
   the optional columns are dropped (CAR-15 clause 1), not silently reconciled.

CAR-12 parity therefore holds for all parents whose seeded value matches the legacy
flag of the opcionales that resolve through them; the exception set is empty for the
pre-change data set exercised by the parity fixture.

#### Scenario: Backfill maps all four legacy surfaces

- GIVEN a database with legacy article, tarifa-article and family flags
- WHEN `migrateIfNeeded` runs
- THEN the corresponding articulo/familia feature values exist for the right tarifas
- Test: `plugins/catalogo_core/tests/CaracteristicaBackfillTest.php`

#### Scenario: Double run is a no-op

- GIVEN a database already backfilled by a first `migrateIfNeeded` run
- WHEN `migrateIfNeeded` runs again
- THEN it inserts nothing and the value tables are unchanged
- Test: `plugins/catalogo_core/tests/CaracteristicaBackfillTest.php`

#### Scenario: Operator edits are never overwritten

- GIVEN an operator-set feature value for a key the backfill would also map
- WHEN the backfill runs
- THEN the operator value survives
- Test: `plugins/catalogo_core/tests/CaracteristicaBackfillTest.php`

#### Scenario: Legacy opcional flags are recovered through parents

- GIVEN an opcional with legacy `en_catalogo = TRUE` whose parent had no value row
- WHEN the backfill completes
- THEN the parent/global value is seeded so the derived visibility stays TRUE
- Test: `plugins/catalogo_core/tests/CaracteristicaBackfillTest.php`

### Requirement: CAR-14 — Read-through flag and dual-write soak

The consumers that still have a legacy visibility surface (the article list
`ALC-02`, export/import and `tarif_catalogo_view`) MUST read visibility through the
resolver when the read-through flag is enabled, and through the legacy columns when
it is disabled (default).

The remaining consumers are **feature-backed in both flag states and MUST NOT be
folded into that legacy/resolver branch**: the D12 opcional indicator
(`ventas_opcionales`) derives visibility from the optionals' parents in both states
because CAR-15 clause 1 drops the opcional-owned flags together with the derivation,
and the article detail tab (ATT-02/ATT-04) reads and writes articulo-scope feature
values only — it never reads or writes a legacy per-tarifa visibility column. The
same holds for the consumers design §8.4 lists as "no visibility read"
(`tarif_configurador_opcionales`, `tarif_articulo`) and for the `ventas_familia`
toggle, which writes a feature value in both states.

During the soak window the legacy columns MUST keep being written (dual-write) so
both paths stay consistent, and the two paths MUST agree for the same database state.

#### Scenario: Flag off keeps legacy behavior

- GIVEN the read-through flag disabled and legacy columns populated
- WHEN the list and export consumers run
- THEN each returns exactly the legacy-driven result
- AND the D12 opcional indicator and the detail tab still read the feature-backed values
- Test: `plugins/catalogo_core/tests/CaracteristicaReadThroughTest.php`

#### Scenario: Flag on switches to the resolver

- GIVEN the read-through flag enabled and backfilled feature values
- WHEN the list and export consumers run
- THEN each returns the resolver-driven result
- Test: `plugins/catalogo_core/tests/CaracteristicaReadThroughTest.php`

#### Scenario: Dual-write keeps both paths consistent

- GIVEN a visibility write through the feature panel
- WHEN the legacy columns are read for the same key and tarifa
- THEN they carry the equivalent value during the soak window
- Test: `plugins/catalogo_core/tests/CaracteristicaReadThroughTest.php`

### Requirement: CAR-15 — Gated reversible post-soak column drop

> **DELIVERY STAGING (amended 2026-09-23).** Both clauses exist and keep their
> gates; only the *delivery* is split across two releases. Clause 1 ships now and
> runs automatically at deploy time through `Init::upgrade()` (no console, no
> flag); clause 2 is explicitly staged for a later release because it needs a real
> soak window. The requirement's substance is unchanged: both drops exist, both are
> idempotent, both are reversible, and neither runs before its gate.

Two idempotent drops MUST exist and MUST be reversible (feature values stay the
source of truth, so re-adding nullable columns and re-deriving from the resolver
restores the legacy representation; a pre-drop dump MUST be kept as the
operator-level restore path):

1. **D12 drop (not soaked, delivered automatically).** `en_catalogo`/`en_tarifa`
   MUST be dropped from `tarif_opcional_ext` and `tarif_tarifa_opcional` together
   with the D12 derivation work unit, gated only on the `CAR-12`
   behavior-preservation parity test passing — no article/family soak is required
   because the derived indicator replaces the flags immediately. It MUST run
   automatically at deploy time through `Init::upgrade()` (the version-change
   path), MUST be idempotent, and MUST refuse to run while the legacy read path
   was explicitly selected (the emergency opt-out) — the same safety property
   clause 2 honours.
2. **Legacy article/family drop (post-soak, gated — STAGED for a later release).**
   `en_catalogo`/`en_tarifa` MUST be dropped from `tarif_articulo_precios`,
   `tarif_tarifa_articulo`, `tarif_tarifa_familia` and `tarif_familia_ext` (the
   latter when it carries them) **only after** the read-through flag has been
   enabled and verified stable, and after the DEV-17 membership-filter rewrite
   (design §8.4/§8.5). It is **not** delivered in the clause-1 release: it keeps
   its soak prerequisite and stays the operator path until a later release
   auto-wires it exactly as clause 1 is now.

Both drops MUST be idempotent and MUST NOT run before their gate.

#### Scenario: Post-soak drop is gated

- GIVEN the read-through flag disabled
- WHEN the legacy article/family drop migration runs
- THEN it refuses to run and changes no schema
- AND once the flag is enabled and verified, running it twice leaves the columns dropped
- Test: `plugins/catalogo_core/tests/CaracteristicaColumnDropTest.php`

#### Scenario: D12 drop is gated on parity, not on soak

- GIVEN the `CAR-12` behavior-preservation parity test passing
- WHEN the D12 work unit runs
- THEN the opcional columns are dropped without waiting for the article/family soak
- Test: `plugins/catalogo_core/tests/CaracteristicaColumnDropTest.php`

#### Scenario: Reversibility from feature values

- GIVEN dropped columns and intact feature values
- WHEN nullable columns are re-added and re-derived
- THEN every previously stored legacy value is reproduced
- Test: `plugins/catalogo_core/tests/CaracteristicaReversibilityTest.php`

### Requirement: CAR-16 — `listable` columns in the product list

`listable` definitions MUST render as dynamic columns in
`View/ventas_articulos.html.twig`, inside the existing per-tarifa conditional
block, after the `Catálogo` column and before the `Stock` column, ordered by
`orden` then `codigo`. A `bool` MUST render `Sí`/`No`; a `string` MUST render its
escaped value or a neutral placeholder when there is no value. The values MUST be
resolved with one batched read per page (no per-row query, no N+1), and the
existing base header strings and their order MUST stay byte-identical.

#### Scenario: Columns render after the per-tarifa columns

- GIVEN two `listable` definitions and a page of products with a selected tarifa
- WHEN the list renders
- THEN both columns appear after `Catálogo` and before `Stock` in `orden` order
- AND bool cells show `Sí`/`No` and string cells show the value or the placeholder
- Test: `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`

#### Scenario: No N+1 read

- GIVEN a page of N products and K `listable` definitions
- WHEN the batched feature reader (`CaracteristicaValorBatchReader`) loads that page
- THEN the number of feature queries is independent of N
- Test: `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`

#### Scenario: Base columns unchanged

- GIVEN the migrated view
- WHEN the base header strings and their relative order are inspected
- THEN they are byte-identical to the pre-change list
- Test: `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`

#### Scenario: No tarifa selected renders no feature column

- GIVEN no selected tarifa
- WHEN the list renders
- THEN no `listable` feature column is emitted
- Test: `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`

### Requirement: CAR-17 — `importable`/`exportable` feature columns

Excel export/import MUST gain dynamic feature columns driven by the flags,
additively: `exportable` definitions append an export column labelled with the
definition `nombre`, and `importable` definitions append an importable field keyed
by `codigo` (plus lowercase aliases) in the wizard mapping. The export column order
is owned by the `articulos-excel-import-export` delta (`orden` then `codigo`), which
is where the ordering scenario lives. Base header lists MUST stay byte-identical;
unmapped or empty feature columns MUST be a no-op, and no feature column MAY alter
the matching or the persistence of the base fields. A feature definition whose
`codigo` collides with a base field key MUST be skipped on every wizard surface
(catalog, options, aliases and feature persistence): the base catalog entry stays
authoritative and the colliding definition contributes nothing.

#### Scenario: Export appends feature columns only

- GIVEN one `exportable` and one non-exportable definition
- WHEN the export is generated
- THEN the base headers are byte-identical and only the exportable feature column is appended
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php`

#### Scenario: Import maps feature fields additively

- GIVEN an Excel with a mapped `importable` feature column
- WHEN the apply step runs
- THEN the feature value is persisted for the selected tarifa
- AND a workbook without that column imports exactly as before
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php`

#### Scenario: A colliding codigo never shadows a base field

- GIVEN an `importable` definition whose `codigo` is a base field key (for example `pvp`)
- WHEN the wizard builds the field catalog, the field options, the extra aliases and persists a mapped row
- THEN the base catalog entry, label, type and aliases stay authoritative
- AND the colliding definition contributes no option, no alias and no feature value
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php`, `plugins/catalogo_core/tests/Services/ArticuloExcelFeaturePersistenciaTest.php`

### Requirement: CAR-18 — Feature management panel

`catalogo_core` MUST serve a `ventas_caracteristicas` page (modern controller +
legacy wrapper + view) registered under menu `catalogo`, exposing definition
create/edit, flag editing (`importable`/`exportable`/`listable`/`activo`/`orden`),
predefined value management, and value assignment at the three scopes including
"all products". Every mutating action MUST validate CSRF before touching any
model, MUST gate deletion and global/family assignment through the permission
mechanism, and a CSRF failure or denied permission MUST persist nothing.

#### Scenario: Panel serves definitions and assignments

- GIVEN catalogo_core active
- WHEN `page=ventas_caracteristicas` is requested
- THEN the page renders the definitions list and the assignment forms under menu `catalogo`
- Test: `plugins/catalogo_core/tests/Controller/VentasCaracteristicasControllerTest.php`

#### Scenario: Create and edit persist valid definitions

- GIVEN a CSRF-valid, authorized payload for a new or existing definition
- WHEN the save action runs
- THEN the definition and its flags persist
- Test: `plugins/catalogo_core/tests/Controller/VentasCaracteristicasControllerTest.php`

#### Scenario: CSRF failure persists nothing

- GIVEN a mutating POST with a missing or invalid CSRF token
- WHEN the action runs
- THEN no definition and no value row is written and an explicit rejection is reported
- Test: `plugins/catalogo_core/tests/Controller/VentasCaracteristicasControllerTest.php`

#### Scenario: Denied delete or global assignment is blocked

- GIVEN a user without the required permission
- WHEN deleting a definition or assigning the global scope is requested
- THEN neither action persists and no destructive side effect occurs
- Test: `plugins/catalogo_core/tests/Controller/VentasCaracteristicasControllerTest.php`

#### Scenario: Defaults are blocked from deletion but can be deactivated

- GIVEN the panel showing the plugin-registered defaults
- WHEN delete is attempted and then deactivation is submitted with a valid CSRF token
- THEN the delete is refused and the deactivation persists
- Test: `plugins/catalogo_core/tests/Controller/VentasCaracteristicasControllerTest.php`

### Requirement: CAR-19 — Plugin-local boundaries

The capability MUST be entirely plugin-local: no file in `base/`, `src/`,
`controller/` or `model/` (repository root) MAY change, and no entry MAY be
created in the repository-root `openspec/`. `catalogo_core` MUST NOT gain a
dependency on `tarifario`; `tarifario` keeps depending on `catalogo_core`. The
existing frozen hook markers, locked classes and table names touched by this
change MUST remain valid, and no new Composer dependency MAY be introduced.

#### Scenario: No core change and no core openspec entry

- GIVEN the applied change
- WHEN the repository-root `openspec/` and the core trees are inspected
- THEN no change entry exists for this change and no core production file was modified
- Test: `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php` (grep gate)

#### Scenario: Dependency direction unchanged

- GIVEN both plugins configured
- WHEN the dependency declarations are inspected
- THEN tarifario requires catalogo_core and catalogo_core requires neither tarifario nor a new Composer package
- Test: `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php`
