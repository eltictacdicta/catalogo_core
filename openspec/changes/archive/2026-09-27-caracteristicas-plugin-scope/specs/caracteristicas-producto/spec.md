# Delta for caracteristicas-producto

> Change: `caracteristicas-plugin-scope` (`catalogo_core`, `ownership: plugin-local`,
> `strict_tdd: true`). Canonical target (read-only in this phase):
> `plugins/catalogo_core/openspec/specs/caracteristicas-producto/spec.md`.
> This delta ADDs CAR-20 and MODIFIES CAR-06, CAR-11, CAR-12, CAR-16, CAR-17,
> CAR-18 and CAR-19. Every `MODIFIED` block below is the FULL requirement block
> (all scenarios copied from the canonical spec, then edited) so the archive phase
> can replace each requirement wholesale.
>
> Deletion of definitions and the "no delete button" rendering (CAR-11) are
> explicitly OUT of scope: they are recorded as observations only.

## ADDED Requirements

### Requirement: CAR-20 — Enabled-plugin ownership scope

A feature definition is **active** when its `origen` is empty (operator-created) or
when its non-empty `origen` names a plugin that the framework's enabled-plugin
registry reports as enabled. A definition whose non-empty `origen` names a plugin
that is not enabled MUST be **inert across the whole feature system**:

- it MUST be absent from the management panel listing;
- it MUST contribute no `listable` product-list column, no `importable` field and
  no `exportable` column;
- it MUST resolve to "no value" (`null`) through the resolver, exactly like a
  missing or inactive definition.

The ownership rule MUST be plugin-agnostic: it MUST derive its decision solely from
the definition's `origen` and the framework enabled-plugin registry, and MUST NOT
reference the `tarifario` namespace or hardcode any plugin name. It MUST NOT
introduce a `catalogo_core → tarifario` dependency.

Inert MUST be non-destructive. Definition rows and their assigned values MUST be
preserved and MUST NOT be written, deleted or mutated while the definition is
inert. Re-enabling the owning plugin MUST restore the definition and its assigned
values unchanged, with no writes having occurred during the inert period.

Operator-created definitions (`origen = ''`) MUST remain active, listed, resolvable
and writable regardless of any plugin's state.

The rule MUST fail closed: when the enabled-plugin registry cannot be consulted,
plugin-owned definitions MUST be treated as inert, and `origen = ''` definitions
are the only unconditional exception.

#### Scenario: Owner disabled makes the definition inert everywhere

- GIVEN a definition with a non-empty `origen` whose owning plugin is not enabled
- WHEN the management panel lists definitions
- THEN the definition is absent from the listing
- AND it contributes no import field and no export column
- AND it produces no `listable` product-list column
- AND `resolve()` returns `null` for it
- Test: `plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php`, `plugins/catalogo_core/tests/Controller/VentasCaracteristicasControllerTest.php`, `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`, `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`

#### Scenario: Re-enabling the owner restores the definition and its values unchanged

- GIVEN a plugin-owned definition with assigned values while the owning plugin is disabled (the inert period)
- WHEN the owning plugin is enabled again
- THEN the definition and its assigned values are returned byte-identically to their pre-inert state
- AND no definition row or value row was written, deleted or mutated during the inert period
- Test: `plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php`, `plugins/catalogo_core/tests/CaracteristicaValorStoreTest.php`

#### Scenario: Operator-created definition is unaffected by plugin state

- GIVEN a definition with `origen = ''`
- WHEN every plugin's enabled state changes
- THEN the definition remains listed, resolvable and writable
- Test: `plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php`

#### Scenario: Registry unavailable fails closed for plugin-owned definitions

- GIVEN the enabled-plugin registry cannot be consulted (no enabled set is available)
- WHEN definitions are read
- THEN every non-empty `origen` definition is treated as inert
- AND only `origen = ''` definitions remain active
- Test: `plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php`

#### Scenario: Ownership check uses the framework registry with no tarifario coupling

- GIVEN the ownership rule
- WHEN its inputs, imports and dependency declarations are inspected
- THEN it reads only the definition's `origen` and the framework enabled-plugin registry
- AND no `catalogo_core → tarifario` dependency is introduced and no plugin name is hardcoded
- Test: `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php`

## MODIFIED Requirements

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

A definition that is inert under CAR-20 MUST resolve to "no value" (`null`)
through exactly the same contract as a missing definition or an inactive
(`activo = FALSE`) definition: no new return value, no new error and no write.

(Previously: the precedence rules were unchanged, but the resolution contract did not state that an owner-disabled definition reuses the missing/inactive `null` path.)

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

#### Scenario: Owner-disabled definition resolves like a missing or inactive one

- GIVEN a definition whose owning plugin is not enabled, alongside a missing definition and an inactive definition
- WHEN the effective value is resolved for each for a product and tarifa
- THEN all three return "no value" (`null`) with no distinct return value and no new error
- AND no value row is created or modified
- Test: `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`

### Requirement: CAR-11 — Default feature registration

`catalogo_core` MUST register the `medidas` (`string`) definition and `tarifario`
MUST register the `en_catalogo` and `en_tarifa` (`bool`) definitions. Registration
MUST be a targeted idempotent upsert (`INSERT … WHERE NOT EXISTS (codigo = ?)`),
NOT `seed_if_empty()`, and MUST run on **both** fresh installs and existing
installs (from `init()` and `upgrade()`), seeding the bool predefined pair.
`origen` MUST persist the registering plugin name. A definition with a non-empty
`origen` MUST NOT be deletable but MAY be deactivated and reactivated. A
definition whose non-empty `origen` names a plugin that is not enabled MUST be
inert under CAR-20 — hidden, not resolvable and not writable — while remaining
non-deletable; re-enabling the owning plugin MUST restore it unchanged. `tarifario`
registration MUST be guarded by `class_exists()` plus a static guard and MUST be
re-attempted from `upgrade()` so boot order cannot skip it.

(Previously: non-deletability was stated only for registration; the enabled-scope rule linking `origen` to the owner plugin's enabled state was absent.)

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

#### Scenario: A plugin-owned definition is inert while its owner is disabled

- GIVEN a definition whose non-empty `origen` names a plugin that is not enabled
- WHEN the definition is registered and then read
- THEN it remains non-deletable and is treated as inert under CAR-20
- AND enabling the owning plugin makes it active again unchanged
- Test: `plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php`

### Requirement: CAR-12 — D12 opcional visibility derivation

Opcionals MUST NOT own `en_catalogo`/`en_tarifa` flags. An opcional's effective
catalog/tarifa visibility MUST be derived from its parent product's effective
feature value for the selected tarifa, with the D12 existential union: when an
opcional is attached to several parents, it is visible if **any** parent is
visible. Parent resolution is: article-attached → that article's effective value
(article → family → global); family-assigned → that family's effective value
(family walk with `madre`); unassigned → the global scope value.

When the catalog/tarifa visibility definitions are inert under CAR-20 (their
owning plugin is not enabled), the derived visibility MUST resolve to "not
visible" through the existing "no value" contract, with no new return value and no
write performed by the resolution.

(Previously: the derivation contract did not state the owner-disabled outcome; the owner-disabled path is now pinned to the existing not-visible contract.)

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

#### Scenario: Owner-disabled visibility definitions resolve not visible

- GIVEN the catalog/tarifa visibility definitions owned by a plugin that is not enabled
- WHEN an opcional's catalog or tarifa visibility is resolved for any tarifa
- THEN it resolves "not visible" through the existing no-value contract, with no new return value
- AND no value row is written during the resolution
- Test: `plugins/catalogo_core/tests/OpcionalVisibilityDerivationTest.php`

### Requirement: CAR-16 — `listable` columns in the product list

`listable` definitions MUST render as dynamic columns in
`View/ventas_articulos.html.twig`, inside the existing per-tarifa conditional
block, after the `Catálogo` column and before the `Stock` column, ordered by
`orden` then `codigo`. A `bool` MUST render `Sí`/`No`; a `string` MUST render its
escaped value or a neutral placeholder when there is no value. The values MUST be
resolved with one batched read per page (no per-row query, no N+1), and the
existing base header strings and their order MUST stay byte-identical.

A definition that is inert under CAR-20 MUST emit no column, and excluding it MUST
NOT change the number of feature queries issued for the page (the query count
stays independent of the number of products).

(Previously: only active `listable` definitions were addressed; the owner-disabled case and its constant query count were not stated.)

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

#### Scenario: Owner-disabled listable definition emits no column

- GIVEN a `listable` definition whose owning plugin is not enabled and a selected tarifa
- WHEN the product list renders that page
- THEN no column is emitted for that definition
- AND the number of feature queries stays independent of the number of products
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

A definition that is inert under CAR-20 MUST contribute no import field (and no
resulting alias) and no export column, and excluding it MUST leave the base headers
byte-identical.

(Previously: only the additive flag-driven behavior was stated; the owner-disabled definition contributing nothing was not.)

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

#### Scenario: Owner-disabled definition contributes no import field or export column

- GIVEN an `importable` and/or `exportable` definition whose owning plugin is not enabled
- WHEN the wizard field catalog and options and the Excel export are built
- THEN the definition contributes no import field, no alias and no export column
- AND the base headers stay byte-identical
- Test: `plugins/catalogo_core/tests/Services/ArticuloExcelCaracteristicaTest.php`

### Requirement: CAR-18 — Feature management panel

`catalogo_core` MUST serve a `ventas_caracteristicas` page (modern controller +
legacy wrapper + view) registered under menu `catalogo`, exposing definition
create/edit, flag editing (`importable`/`exportable`/`listable`/`activo`/`orden`),
predefined value management, and value assignment at the three scopes including
"all products". Every mutating action MUST validate CSRF before touching any
model, MUST gate deletion and global/family assignment through the permission
mechanism, and a CSRF failure or denied permission MUST persist nothing.

A definition that is inert under CAR-20 MUST NOT be listed, and every direct
mutating action targeting it (definition save/edit, flag toggle, value assignment
or delete) MUST be refused/ignored and MUST persist nothing, including when the
request arrives directly by POST or URL rather than through the rendered listing.

> **Recorded observation (out of scope).** A non-empty `origen` already makes a
> definition non-deletable (CAR-11), so the panel renders no delete button for any
> plugin-owned row today. This change records that observation; it does not alter
> the delete-button rendering, and deletion of definitions is out of scope.

(Previously: the panel contract did not scope the listing or the direct actions by the definition's owning-plugin enabled state, and the delete-button observation was not recorded.)

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

#### Scenario: Owner-disabled definitions are hidden and their direct actions refused

- GIVEN a definition whose owning plugin is not enabled
- WHEN the panel listing is rendered and, separately, a CSRF-valid authorized POST targets that definition's save/edit, flag toggle, value assignment or delete action
- THEN the definition does not appear in the listing
- AND each direct action persists nothing and is refused/ignored
- AND no value row or definition row is written
- Test: `plugins/catalogo_core/tests/Controller/VentasCaracteristicasControllerTest.php`

### Requirement: CAR-19 — Plugin-local boundaries

The capability MUST be entirely plugin-local: no file in `base/`, `src/`,
`controller/` or `model/` (repository root) MAY change, and no entry MAY be
created in the repository-root `openspec/`. `catalogo_core` MUST NOT gain a
dependency on `tarifario`; `tarifario` keeps depending on `catalogo_core`. The
existing frozen hook markers, locked classes and table names touched by this
change MUST remain valid, and no new Composer dependency MAY be introduced.

The enabled-plugin ownership rule (CAR-20) MUST derive its decision solely from the
definition's `origen` and the framework enabled-plugin registry, and MUST NOT
reference the `tarifario` namespace or hardcode any plugin name.

(Previously: the ownership rule and its boundary condition (registry + `origen`, no tarifario coupling, no hardcoded plugin name) were not stated.)

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

#### Scenario: Ownership check introduces no tarifario coupling or hardcoded plugin name

- GIVEN the applied ownership rule
- WHEN its source, imports and dependency declarations are inspected
- THEN it reads only the definition's `origen` and the framework enabled-plugin registry
- AND it contains no tarifario namespace reference and no hardcoded plugin name
- Test: `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php`
