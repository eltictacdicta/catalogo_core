# visibility-characteristic-gating Specification

## Purpose

Owned by `catalogo_core`: when a visibility characteristic (`en_catalogo` /
`en_tarifa`) has no active definition for the current enabled-plugin set, its
hardcoded surface — header, label, cell or control — MUST NOT render in the four
view families that render it: `ventas_articulos`, `articulo_precios_rows`
(served by `tarif_tab_precios`), `ventas_opcionales` and `tarif_opcional_edit`.

The gate source is `CaracteristicaResolver`'s active-visibility list, derived
from the existing CAR-20 ownership filter. The characteristic **values** already
degrade by themselves: `CaracteristicaResolver::definitions()` drops definitions
whose owner plugin is not enabled and `CaracteristicaValorBatchReader::columns()`
inherits that filter, so values resolve to `null` / `false` while the owner is
inactive. What this capability governs is the **surface** that keeps rendering
regardless of that already-correct data degradation.

This capability references `caracteristicas-producto` `CAR-12` (D12
existential-union derivation), `CAR-19` (plugin-local boundaries) and `CAR-20`
(enabled-plugin ownership scope) without redefining them; those requirements
already own the ownership rule the gate reuses. This capability adds no
plugin-name literal, no new service, no endpoint guard and no menu sync.

## Requirements

### Requirement: VCG-01 — Active-visibility derivation

`CaracteristicaResolver` MUST expose `active_visibility_codigos(): array` and
`is_visibility_active(string $codigo): bool`. `active_visibility_codigos()` MUST
return exactly the visibility characteristic codigos (`en_catalogo`,
`en_tarifa`) whose definition is active for the current enabled-plugin set, and
MUST derive that set from the same ownership filter already applied by
`definitions()` (`CAR-20`). `is_visibility_active($codigo)` MUST return `true` if
and only if `$codigo` is a member of `active_visibility_codigos()`. No production
file in `catalogo_core` MAY introduce a `'tarifario'` literal, a
`plugins/tarifario/` path or an `@tarifario/` reference to answer this question.

_Strength: MUST._

#### Scenario: Visibility codigos present when the owner is active

- GIVEN the `tarifario` plugin enabled and its two visibility definitions registered
- WHEN `active_visibility_codigos()` runs
- THEN it returns exactly `en_catalogo` and `en_tarifa`, and no other codigo
- AND `is_visibility_active('en_catalogo')` and `is_visibility_active('en_tarifa')` are `true`

#### Scenario: Visibility codigos absent when the owner is inactive

- GIVEN no active definition owns the two visibility codigos for the current plugin set
- WHEN `active_visibility_codigos()` runs
- THEN it returns an empty list
- AND `is_visibility_active('en_catalogo')` and `is_visibility_active('en_tarifa')` are `false`

#### Scenario: No plugin-name literal answers the gate

- GIVEN the `catalogo_core` production tree
- WHEN the accessor and its callers are inspected
- THEN no `'tarifario'` literal, `plugins/tarifario/` path or `@tarifario/` reference exists in production code
- Test: `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php` (grep gate), `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`

### Requirement: VCG-02 — Article list gate

When a visibility codigo is inactive, the `ventas_articulos` list MUST omit that
codigo's header and its per-row cells. The empty-state row's `colspan` MUST be
recomputed from the active visibility set so the empty row stays aligned with the
header. When the codigo is active, the header and cells MUST render exactly as
today. The per-tarifa `precio`/`activo` columns and the `listable` feature-column
append/order rule MUST NOT change.

_Strength: MUST._

#### Scenario: Inactive visibility column disappears

- GIVEN a visibility codigo inactive for the current plugin set
- WHEN `ventas_articulos` renders
- THEN that codigo's header and its per-row cells are absent
- AND the remaining base, per-tarifa and active feature columns keep their relative order
- Test: `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`

#### Scenario: Empty-state colspan follows the active visibility set

- GIVEN the empty-state row and an inactive visibility codigo
- WHEN the list renders with no data rows
- THEN the empty row's `colspan` equals the number of visible columns
- AND the empty row stays aligned with the header
- Test: `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`

#### Scenario: Active visibility column renders as before

- GIVEN a visibility codigo active
- WHEN `ventas_articulos` renders
- THEN its `Tarifa`/`Catálogo` header and cells render exactly as today
- Test: `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`

### Requirement: VCG-03 — Article detail gate

When a visibility codigo is inactive, the corresponding `en_tarifa` /
`en_catalogo` checkbox MUST be absent from the `articulo_precios_rows` partial
served by `tarif_tab_precios`. The multitarifa price input and the `activo`
checkbox MUST remain rendered. When the codigo is active, the checkbox MUST
render exactly as today.

_Strength: MUST / MUST NOT._

#### Scenario: Inactive visibility controls are absent while multitarifa controls stay

- GIVEN a visibility codigo inactive
- WHEN `articulo_precios_rows` renders for a tarifa
- THEN no `en_tarifa`/`en_catalogo` checkbox exists in the fragment
- AND the price input and the `activo` checkbox remain rendered
- Test: `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php`

#### Scenario: Active visibility controls render as before

- GIVEN a visibility codigo active
- WHEN `articulo_precios_rows` renders for a tarifa
- THEN its checkbox renders exactly as today

### Requirement: VCG-04 — Opcionales list gate

When a visibility codigo is inactive, the `ventas_opcionales` list MUST omit that
codigo's `Tarifa`/`Catálogo` header and its per-row cells. The multitarifa
`b_codtarifa` filter and the `Precio`/`Estado` columns MUST remain. When the
codigo is active, header and cells MUST render exactly as today.

_Strength: MUST / MUST NOT._

#### Scenario: Inactive visibility columns are absent while multitarifa surfaces stay

- GIVEN a visibility codigo inactive
- WHEN `ventas_opcionales` renders
- THEN that codigo's header and its per-row cell are absent
- AND the `b_codtarifa` filter and the `Precio`/`Estado` columns remain
- Test: `plugins/catalogo_core/tests/Controller/VentasOpcionalesControllerMasterStateTest.php`

#### Scenario: Active visibility columns render as before

- GIVEN a visibility codigo active
- WHEN `ventas_opcionales` renders
- THEN the `Tarifa`/`Catálogo` header and cells render exactly as today
- Test: `plugins/catalogo_core/tests/Controller/VentasOpcionalesControllerMasterStateTest.php`

### Requirement: VCG-05 — Opcional detail gate

When a visibility codigo is inactive, the `tarif_opcional_edit` page MUST omit
the corresponding `Catálogo:` / `En tarifa:` read-only labels and their summary
values. The multitarifa `activa` toggle and price panel MUST remain. When the
codigo is active, labels and values MUST render exactly as today.

_Strength: MUST / MUST NOT._

#### Scenario: Inactive labels and values are absent

- GIVEN a visibility codigo inactive
- WHEN `tarif_opcional_edit` renders
- THEN no `Catálogo:`/`En tarifa:` label and no matching summary value is present
- AND the `activa` toggle and the price panel remain rendered
- Test: `plugins/catalogo_core/tests/TarifOpcionalEditCaracteristicaTest.php`

#### Scenario: Active labels and values render as before

- GIVEN a visibility codigo active
- WHEN `tarif_opcional_edit` renders
- THEN its label and value render exactly as today
- Test: `plugins/catalogo_core/tests/TarifOpcionalEditCaracteristicaTest.php`

### Requirement: VCG-06 — Uniform across read modes

The gate MUST be driven by the definition's activity, not by the read path. It
MUST apply identically when `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` is
undefined or `TRUE` and when it is explicitly `FALSE`.

_Strength: MUST._

#### Scenario: Gate holds with read-through enabled

- GIVEN `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` undefined or `TRUE` and a visibility codigo inactive
- WHEN any gated surface renders
- THEN the surface is hidden

#### Scenario: Gate holds with read-through explicitly disabled

- GIVEN `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` explicitly `FALSE` and the same visibility codigo inactive
- WHEN the same surface renders
- THEN the surface is hidden, identically to the enabled read mode
- Test: `plugins/catalogo_core/tests/CaracteristicaReadThroughTest.php`

### Requirement: VCG-07 — Data preservation

Gating MUST NOT delete, insert or mutate any definition row or value row.
Re-enabling the owning plugin MUST restore every gated surface: the
`ventas_articulos` visibility headers/cells, the recomputed empty-state colspan,
the `articulo_precios_rows` visibility controls, the `ventas_opcionales`
visibility headers/cells, and the `tarif_opcional_edit` visibility labels/values.

_Strength: MUST / MUST NOT._

#### Scenario: An inactive gate performs no write

- GIVEN a visibility codigo inactive
- WHEN every gated surface renders
- THEN no definition row and no value row is written, deleted or mutated

#### Scenario: Re-enabling restores all five surfaces

- GIVEN the owning plugin disabled and then enabled again
- WHEN the five gated surfaces render
- THEN each surface renders its visibility header/label/cell/control exactly as before the inert period
- Test: `plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php`

### Requirement: VCG-08 — Ownership safety

The gate accessor and its callers MUST be plugin-agnostic: no `plugins/tarifario/`
path or `@tarifario/` reference MAY be added to `catalogo_core`, and no
`catalogo_core → tarifario` dependency MAY be introduced. The accessor MUST
answer solely from the canonical visibility codigos and the existing `CAR-20`
ownership filter.

_Strength: MUST / MUST NOT._

#### Scenario: No tarifario reference is added

- GIVEN the applied change
- WHEN `catalogo_core` production is inspected
- THEN no `plugins/tarifario/` path and no `@tarifario/` reference exists
- AND no `catalogo_core → tarifario` dependency was introduced
- Test: `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php` (grep gate)

#### Scenario: Accessor is plugin-agnostic

- GIVEN the gate accessor
- WHEN its inputs, imports and dependency declarations are inspected
- THEN it reads only the canonical visibility codigos and the `CAR-20` ownership filter
- AND it hardcodes no plugin name
- Test: `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php`
