# Delta for flujos-condicionales

New capability owned by `catalogo_core`. All requirements are ADDED; there is no
prior canonical `flujos-condicionales` spec. Scope: a reusable, named
conditional-logic entity ("flujo") that relates opcionales and grupos by
**código**, is assignable to artículos and familias, and is **exposed read-only**
by the core so consumers (`tpvmod`, `clientes_core`) evaluate it (decision D4).
The core never renders a flow decision itself.

The operator and action vocabularies are **closed**. Adding one is a spec change,
not an ad-hoc consumer change. This is the explicit guard against the
TM Extra Product Options rule-engine sprawl.

## ADDED Requirements

### Requirement: FLC-01 — Flow entity

A flujo MUST have a stable `codigo` (unique), a `nombre`, an `activo` flag and an
integer `prioridad`. It MAY have a description. A flow is reusable: the same flow
can be assigned to many artículos and familias. `codigo` is the portable identity
used by export/import and by every cross-entity reference.

#### Scenario: Code is unique and portable

- **GIVEN** two flows
- **WHEN** the second is saved with the first's `codigo`
- **THEN** validation rejects the duplicate
- Test: `plugins/catalogo_core/tests/CatalogoFlujoModelTest.php`

### Requirement: FLC-02 — Conditions reference subjects by código with a closed operator set

A flow's conditions MUST reference their subject by `codigo`, with
`sujeto_tipo` ∈ {`opcional`, `grupo`}, a boolean `grupo_and_or` (AND/OR) and an
operator from the closed set: `is`, `isnot`, `isempty`, `isnotempty`,
`startswith`, `endswith`, `greaterthan`, `lessthan`, `greaterthanequal`,
`lessthanequal`. A condition value MAY be empty for the `isempty`/`isnotempty`
operators. An unknown `sujeto_tipo` or operator MUST be rejected at save.

#### Scenario: Unknown operator is rejected

- **GIVEN** a condition whose operator is not in the closed set
- **WHEN** the flow is saved
- **THEN** validation fails and nothing is persisted
- Test: `plugins/catalogo_core/tests/CatalogoFlujoModelTest.php`

#### Scenario: AND / OR grouping is preserved

- **GIVEN** a flow with an AND group and an OR group
- **WHEN** it is persisted and reloaded
- **THEN** each condition keeps its `grupo_and_or`
- Test: `plugins/catalogo_core/tests/CatalogoFlujoModelTest.php`

### Requirement: FLC-03 — Actions use a closed vocabulary

A flow's actions MUST use `accion` from the closed set {`mostrar`, `ocultar`,
`requerir`, `deshabilitar`} and MUST reference their target by `codigo` with
`sujeto_tipo` ∈ {`opcional`, `grupo`}. An unknown action MUST be rejected at save.

#### Scenario: Unknown action is rejected

- **GIVEN** a flow action whose `accion` is not in the closed set
- **WHEN** the flow is saved
- **THEN** validation fails and nothing is persisted
- Test: `plugins/catalogo_core/tests/CatalogoFlujoModelTest.php`

### Requirement: FLC-04 — Assignment to artículos and familias

A flow MUST be assignable to zero or more artículos and zero or more familias,
through dedicated M:N relations (mirroring the opcional assignment surfaces). A
flow with no assignment MUST be inert. Assignment MUST NOT create a new page or
menu row.

#### Scenario: Flow applies only to its assigned scope

- **GIVEN** a flow assigned to article `A` only
- **WHEN** applicable flows are requested for article `B`
- **THEN** the flow is not returned
- Test: `plugins/catalogo_core/tests/FlujoResolverTest.php`

### Requirement: FLC-05 — Core exposes applicable flows read-only; the consumer evaluates

The core MUST expose, for a `(referencia, codfamilia)` pair, the active flows that
apply, each with its conditions and actions in a stable, documented shape. The
core MUST NOT evaluate conditions or apply actions. Consumers (`tpvmod`,
`clientes_core`) evaluate against the user's current selection and apply the
actions. Exposing applicable flows MUST NOT issue a query per opcional.

#### Scenario: Exposure is read-only

- **GIVEN** an article with applicable flows
- **WHEN** the resolver returns them
- **THEN** no row is written, altered or deleted
- Test: `plugins/catalogo_core/tests/FlujoResolverTest.php`

#### Scenario: Inactive flows are never exposed

- **GIVEN** a flow with `activo = false` assigned to an article
- **WHEN** applicable flows are requested
- **THEN** it is not returned
- Test: `plugins/catalogo_core/tests/FlujoResolverTest.php`

### Requirement: FLC-06 — Deterministic order and conflict precedence

Exposed flows MUST be ordered deterministically: family-scoped flows before
article-scoped flows, then by `prioridad` ascending, then by `id` ascending. When
two flows act on the same subject, the **later** flow in that order wins, so an
article-scoped flow overrides a family-scoped one. This rule is part of the
contract so both consumers resolve conflicts identically.

#### Scenario: Article scope overrides family scope

- **GIVEN** a family flow and an article flow acting on the same subject with
  different outcomes
- **WHEN** the resolver orders them for an article in that family
- **THEN** the article-scoped flow comes later and wins
- Test: `plugins/catalogo_core/tests/FlujoResolverTest.php`

### Requirement: FLC-07 — Cycle detection at save

A flow graph that would require oscillating visibility (a subject that both
triggers and is acted upon by a cycle) MUST be rejected at save, with an error,
so no consumer can hang or oscillate. Evaluation is a single bounded pass in the
deterministic order; cycle safety is enforced on write, not on read.

#### Scenario: Direct cycle is rejected

- **GIVEN** flow `F1` shows optional `B` when `A` is selected, and flow `F2` shows
  `A` when `B` is selected
- **WHEN** the second flow is saved
- **THEN** validation detects the cycle and rejects it
- Test: `plugins/catalogo_core/tests/CatalogoFlujoCycleTest.php`

### Requirement: FLC-08 — Flow export/import by código

Flows MUST be exportable and importable by `codigo`, including their conditions,
actions and article/family assignments (referenced by código). Import MUST be
idempotent by flow `codigo`, and rows whose referenced subjects do not exist MUST
be reported in a rejected-rows output, never silently dropped.

#### Scenario: Orphan subject is reported

- **GIVEN** an import row referencing an opcional code that does not exist
- **WHEN** the import runs
- **THEN** the row is written to the rejected-rows output
- Test: `plugins/catalogo_core/tests/FlujoExcelImportTest.php`

### Requirement: FLC-09 — App-side integrity and cascade

Flow references MUST keep app-side integrity (no DB foreign key across the two
possible subject parents). Deleting an opcional, a grupo, an artículo or a familia
MUST remove the assignments that reference it and MUST NOT leave a dangling
subject reference that a consumer would misread. A flow whose conditions/actions
lose all their referenced subjects MAY remain with zero effective subjects, but
its export MUST report the dangling reference.

#### Scenario: Deleting a subject cleans assignments

- **GIVEN** a flow assigned to article `A`
- **WHEN** article `A` is deleted
- **THEN** the flow's article assignment for `A` is removed
- Test: `plugins/catalogo_core/tests/CatalogoFlujoModelTest.php`
