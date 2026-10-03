# Design: Gate visibility characteristics by plugin activation

- Change: `gate-tarifario-surfaces-by-plugin-activation`
- Rename recommended (NOT applied this pass): `gate-visibility-characteristics-by-plugin-activation`
- SDD owner: `plugins/catalogo_core/openspec/` (plugin-local, `strict_tdd: true`)
- Artifact store: openspec
- Inputs: `proposal.md`, `rescope-explore.md`, `SUPERSEDED.md`, delta specs
  `visibility-characteristic-gating` (VCG-01..08), `articulo-lista-canonica`
  (ALC-02), `opcionales-management` (OUM-03)
- Core `openspec/` entry: NONE (100% within `plugins/catalogo_core/`)

---

## Technical Approach

Gate the **two visibility characteristics** `en_catalogo` / `en_tarifa` in
exactly four view families, driven by whether each definition is active for the
current enabled-plugin set. The activity signal is not new logic: it is the
existing CAR-20 ownership filter already applied inside
`CaracteristicaResolver::definitions()`. The change adds one read-only resolver
accessor, exposes it to Twig through a public controller/trait method, and wraps
the hardcoded header/cell/label renderings in a template condition.

The data path already degrades correctly (`definitions()` drops inert rows;
`CaracteristicaValorBatchReader::columns()` / `definition_rows()` inherit the
filter, so values resolve to `null` / `false`). This change only removes the
**surface hardcoding** — the stale `✗` / "No" columns that rendered while the
owner plugin was inactive.

No new service, no endpoint guard, no menu sync, no plugin-name literal, no
schema or data change. Every edit is additive view gating.

## Architecture Decisions

### Decision: Gate source is a resolver accessor derived from `definitions()`

**Choice**: Add to `Services/CaracteristicaResolver.php`:

```php
/** @return list<string> Active visibility codigos for the enabled-plugin set (CAR-20). */
public function active_visibility_codigos(): array
{
    $active = $this->definitions(); // CAR-20 filter already applied inside
    $codigos = [];
    foreach (self::VISIBILITY_CODIGOS as $codigo) {
        if (isset($active[$codigo])) {
            $codigos[] = $codigo;
        }
    }

    return $codigos;
}

public function is_visibility_active(string $codigo): bool
{
    return in_array($codigo, $this->active_visibility_codigos(), true);
}
```

**Alternatives considered**:
- A `TarifarioGate` service keyed on the bare string `'tarifario'` — rejected: it
  re-introduces a plugin name where the resolver already answers the question,
  and enlarges the test blast radius.
- A new query / SQL filter on `origen` — rejected: the enabled set is
  runtime-only (`$GLOBALS['plugins']`) and cannot be expressed in SQL; the
  existing PHP filter (`CaracteristicaOwnership::is_active()`) is the canonical
  CAR-20 implementation.
- A Twig global — rejected: every affected template already receives `fsc`; a
  global buys nothing and enlarges test surface.

**Rationale**: `definitions()` already returns only active rows (model
`all($onlyActive = true)` + CAR-20 ownership filter) keyed by `codigo`, memoized
per instance in `$definitionCache`. The accessor is therefore fail-closed for
free: an empty `$GLOBALS['plugins']` yields `[]`, so both codigos hide.
`VISIBILITY_CODIGOS` order (`['en_catalogo', 'en_tarifa']`) is preserved; the
view gate uses membership, so order is immaterial.

### Decision: Accessor is fail-closed and query-free

**Choice**: `active_visibility_codigos()` reads the memoized `definitions()`
map without issuing a new query; `is_visibility_active()` delegates to it.

**Alternatives considered**: a fresh `SELECT ... WHERE codigo IN
('en_catalogo','en_tarifa')` — rejected (duplicates the CAR-20 pass and adds a
round-trip).

**Rationale**: `definitions()` queries `catalogo_caracteristicas` once and
memoizes the filtered map in `$definitionCache['active']`. The accessor is a
pure array-key intersection over that map. Per-request caching of the *list* is
additionally guaranteed at the controller seam (next decision), because the
controller seams construct a **new** resolver per call and the view evaluates
the gate multiple times (headers + one per row).

### Decision: Controller→Twig seam is a public no-arg `visibilidad_activa(): array` method

**Choice**: Each of the four gated-template servers exposes:

```php
private ?array $visibilidad_activa_cache = null;

public function visibilidad_activa(): array
{
    if ($this->visibilidad_activa_cache === null) {
        $this->visibilidad_activa_cache = $this->caracteristica_visibilidad_resolver()
            ->active_visibility_codigos();
    }

    return $this->visibilidad_activa_cache;
}
```

with a resolver seam per server (reusing the existing one where present):

| Server | Resolver seam |
|---|---|
| `extras/VentasArticulosListTrait.php` | new `protected function caracteristica_resolver(): CaracteristicaResolver` |
| `extras/VentasOpcionalesListTrait.php` | reuse existing `opcional_visibility_resolver()` |
| `controller/tarif_tab_precios.php` | reuse existing `caracteristica_resolver()` |
| `controller/tarif_opcional_edit.php` | reuse existing `opcional_visibility_resolver()` |

Twig consumes it as:

```twig
{% if 'en_tarifa' in fsc.visibilidad_activa %}
```

**Alternatives considered**:
- A public **property** `$this->visibilidad_activa` (closest to the
  `tarif_tarifas::$tarifario_activo` precedent) — rejected: it forces eager
  population in every bootstrap, and a name shared with the gate method is
  confusing. The **precedent we follow is the pattern** (a public controller
  member consumed by the view as `fsc.<member>`), not the property-ness.
- Per-codigo `visibilidad_activa(string $codigo): bool` — rejected for the view
  seam: the required expression is membership-based
  (`'en_tarifa' in visibilidad_activa`), which needs a collection, and a
  per-codigo method would re-evaluate the accessor per row. `is_visibility_active()`
  remains available on the resolver for callers that prefer it.
- A Twig global/function — rejected (same as above).

**Rationale**: Twig resolves `fsc.visibilidad_activa` to the zero-argument public
method (property → exact method → `get*`/`is*`/`has*`). The render context passes
only `fsc` (`index.php:353-357`, `Html::render()`), so the working expression is
`fsc.visibilidad_activa`; a bare `visibilidad_activa` is **not** in scope for the
full-page templates and MUST NOT be assumed. The method is lazy and memoized:
one `definitions()` call per request at most, independent of row count, even
though each seam builds a fresh resolver.

### Decision: The empty-state `colspan` is computed from the active visibility count

**Choice**: Replace the constant `4` with `2 + visibilidad_activa|length`:

```twig
<td colspan="{{ 7 + (fsc.tarifa_seleccionada ? 2 + fsc.visibilidad_activa|length + fsc.listable_caracteristicas()|length : 0) }}">
```

**Alternatives considered**: keep `4` (misaligns when a visibility column
hides); compute the count in PHP (adds a controller helper and a second source of
truth).

**Rationale**: The per-tarifa block always renders `precio` and `Activo` (2),
plus one column per active visibility codigo, plus the `listable` feature loop.
`7` stays the fixed base count (reference, description, family, manufacturer,
price, Stock, Actions). The formula is correct for all four combinations
(`both active` = today's `7 + 4 + feature`, `one active` = `7 + 3 + feature`,
`none active` = `7 + 2 + feature`, no tarifa = `7`).

### Decision: The gate sits in the template condition, never in the value helper

**Choice**: Check `fsc.visibilidad_activa` before calling any value helper.
Leave `articulo_visibility_bool()` / `articulo_tarifa_row()` untouched.

**Alternatives considered**: make the helpers return a sentinel / suppress the
column from the trait — rejected: it changes value semantics and risks the
legacy read branch.

**Rationale**: The gate must be uniform across
`FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` (VCG-06, Q3). `active_visibility_codigos()`
depends only on definition activity (CAR-20), never on the read flag. Because the
condition is evaluated in the template *before* the value helper runs, the legacy
branch (`READ_THROUGH=false`, which reads the same-named multitarifa
`tarif_articulo_precio` columns) cannot re-introduce a hidden column. The value
helpers keep their current return shape; only the view decides whether to render.

### Decision: No new production file; per-server method duplication follows the existing pattern

**Choice**: Add `visibilidad_activa()` + cache + seam inline in the four existing
servers. No `CaracteristicaVisibilityGateTrait`, no new service.

**Alternatives considered**: a shared `extras/` trait — rejected: it introduces a
new production artifact outside the proposal's frozen blast radius, and this repo
already duplicates thin helpers per seam (e.g. `simbolo_divisa_tarifa()` in both
list traits).

**Rationale**: Keeps the diff inside files the proposal already lists, honors
"No new Symfony service registration", and reuses existing resolver/ownership
classes.

### Decision: `Controller/VentasArticulo.php` (singular) is NOT a seam site

**Choice**: Do not touch `Controller/VentasArticulo.php`.

**Rationale**: The article detail page (`ventas_articulo.html.twig`) hosts the
per-tarifa tab as an **AJAX fragment** loaded from
`index.php?page=tarif_tab_precios&action=rows&...`. It does not server-render
`articulo_precios_rows.html.twig`; the endpoint (`tarif_tab_precios`) does, and
that endpoint carries the seam. Adding a seam to the detail controller would be
dead code. (The proposal's blast-radius table lists `Controller/VentasArticulo.php`;
this design corrects that to the actual render servers. The list controller
`Controller/VentasArticulos.php`, plural, receives the seam through its trait.)

## Data Flow

```
$GLOBALS['plugins']  ─┐
                      ▼
       CaracteristicaOwnership::is_active($origen)
                      │  (CAR-20 filter, already inside definitions())
                      ▼
       CaracteristicaResolver::definitions()  ── memoized ──► array<codigo, definition>
                      │
                      ▼
       CaracteristicaResolver::active_visibility_codigos()   ← NEW
                      │  list<string> ∈ VISIBILITY_CODIGOS
                      ▼
   Controller/Trait::visibilidad_activa()  ── memoized in $visibilidad_activa_cache ──┐
                      │                                                               │
                      ▼                                                               │
   Twig: {% if 'en_tarifa' in fsc.visibilidad_activa %}                               │
        ├── ventas_articulos.html.twig       (headers, cells, colspan)               │
        ├── articulo_precios_rows.html.twig  (en_tarifa / en_catalogo controls)       │
        ├── ventas_opcionales.html.twig      (headers, cells)                         │
        └── tarif_opcional_edit.html.twig    (labels, summary th/td)                  │
                                                                                      │
   Value helpers (articulo_en_*, opcional_en_*, resolve_*) stay unchanged ─────────────┘
```

## File Changes

| File | Action | Description |
|------|--------|-------------|
| `plugins/catalogo_core/Services/CaracteristicaResolver.php` | Modify | Add `active_visibility_codigos()` + `is_visibility_active()` (VCG-01). |
| `plugins/catalogo_core/extras/VentasArticulosListTrait.php` | Modify | Add resolver require/import + `caracteristica_resolver()` seam + `visibilidad_activa()` + cache. |
| `plugins/catalogo_core/extras/VentasOpcionalesListTrait.php` | Modify | Add `visibilidad_activa()` + cache via existing `opcional_visibility_resolver()`. |
| `plugins/catalogo_core/controller/tarif_tab_precios.php` | Modify | Add `visibilidad_activa()` + cache via existing `caracteristica_resolver()`. |
| `plugins/catalogo_core/controller/tarif_opcional_edit.php` | Modify | Add `visibilidad_activa()` + cache via existing `opcional_visibility_resolver()`. |
| `plugins/catalogo_core/View/ventas_articulos.html.twig` | Modify | Gate 2 headers (`:146-147`), 2 cells (`:191-204`); recompute empty-state colspan (`:232`). |
| `plugins/catalogo_core/View/Hooks/partials/articulo_precios_rows.html.twig` | Modify | Gate the `en_tarifa` (`:41-49`) and `en_catalogo` (`:50-58`) controls; keep price/activo. |
| `plugins/catalogo_core/View/ventas_opcionales.html.twig` | Modify | Gate 2 headers (`:392-398`) and 2 cells (`:467-484`). |
| `plugins/catalogo_core/View/tarif_opcional_edit.html.twig` | Modify | Gate labels (`:294-307`), summary th (`:336`) and values (`:346-347`). |
| `plugins/catalogo_core/tests/…` | Modify | See Testing Strategy. |

No file is created or deleted. `Controller/VentasArticulo.php` is untouched.

## Interfaces / Contracts

```php
namespace FSFramework\Plugins\catalogo_core\Services;

class CaracteristicaResolver
{
    public const VISIBILITY_CODIGOS = ['en_catalogo', 'en_tarifa'];

    /** @return list<string> codigos whose definition is active for the enabled-plugin set */
    public function active_visibility_codigos(): array;

    public function is_visibility_active(string $codigo): bool;
}
```

Controller/trait seam (identical shape in the four servers):

```php
/** @return list<string> active visibility codigos for the current request */
public function visibilidad_activa(): array;
```

Twig contract (working expression; `fsc` is the only context object):

```twig
{% if 'en_tarifa' in fsc.visibilidad_activa %}
{% if 'en_catalogo' in fsc.visibilidad_activa %}
```

### Exact intended edit per render point

**1. `View/ventas_articulos.html.twig` — headers (`:146-147`)**

```twig
{% if 'en_tarifa' in fsc.visibilidad_activa %}
<th class="text-center" width="70">Tarifa</th>
{% endif %}
{% if 'en_catalogo' in fsc.visibilidad_activa %}
<th class="text-center" width="70">Catálogo</th>
{% endif %}
```

**2. `View/ventas_articulos.html.twig` — cells (`:191-204`)**: wrap each `<td>`
(Tarifa cell calls `fsc.articulo_en_tarifa_flag(...)`, Catálogo cell calls
`fsc.articulo_en_catalogo(...)`) in the matching gate. The price (`:177-183`) and
`Activo` (`:184-190`) cells and the `listable` loop (`:205-207`) stay ungated, in
place.

**3. `View/ventas_articulos.html.twig` — empty-state colspan (`:232`)** as in the
decision above. When `fsc.tarifa_seleccionada` is falsy the term is `0` (all four
per-tarifa columns are inside the `{% if %}` block), so the base `7` is unchanged.

**4. `View/Hooks/partials/articulo_precios_rows.html.twig` — controls (`:41-58`)**:
wrap the `col-sm-3` column holding the `en_tarifa` checkbox in
`{% if 'en_tarifa' in fsc.visibilidad_activa %}` and the `en_catalogo` column in
`{% if 'en_catalogo' in fsc.visibilidad_activa %}`. The price input (`:16-29`),
the `activo` checkbox (`:30-40`) and the hidden `guardar_precio_tab`/save button
(`:59-71`) stay ungated. `fsc` here is the `tarif_tab_precios` controller, so the
same `fsc.visibilidad_activa` expression applies.

**5. `View/ventas_opcionales.html.twig` — headers (`:392-398`) and cells (`:467-484`)**:
wrap the `Tarifa` `<th>`/`<td>` (`en_tarifa`) and the `Catálogo` `<th>`/`<td>`
(`en_catalogo`) in the matching gate. The `b_codtarifa` selector (`:319-334`),
the `Precio` (`:385-390` / `:439-448`) and `Estado` (`:391` / `:449-466`)
columns stay ungated.

**6. `View/tarif_opcional_edit.html.twig` — labels (`:294-307`), summary
(`:336`, `:346-347`)**:

```twig
<span class="text-muted" style="margin-left: 8px;">
   {% if 'en_catalogo' in fsc.visibilidad_activa %}
   Catálogo:
   {% if fsc.opcional_en_catalogo_tarifa(fsc.tarifa_seleccionada.codtarifa) %}...{% endif %}
   {% endif %}
   {% if 'en_tarifa' in fsc.visibilidad_activa %}
   &nbsp;En tarifa:
   {% if fsc.opcional_en_tarifa_flag(fsc.tarifa_seleccionada.codtarifa) %}...{% endif %}
   {% endif %}
</span>
```

The summary `<th>Catálogo</th>` and `<th>En tarifa</th>` (`:336`) and their value
`<td>`s (`:346`, `:347`) are each wrapped in the matching gate. `Tarifa`
(`:340-344`), `Activa` (`:345`) and `Precio` (`:348-356`) stay ungated; the
`activa` toggle and price panel (`:289-329`) stay.

## Testing Strategy

| Layer | What to Test | Approach |
|-------|-------------|----------|
| Unit | `active_visibility_codigos()` / `is_visibility_active()` active / inactive / operator-owned / unknown / memoized | Extend `tests/CaracteristicaResolverTest.php` (existing DB-free `buildResolver` harness with the `ownership()` seam). |
| Unit | Trait seam `visibilidad_activa()` returns the accessor list and memoizes across calls | Extend `tests/Controller/VentasArticulosListCaracteristicasTest.php` host; extend `tests/CaracteristicaReadThroughTest.php`. |
| Unit | Read-mode uniformity: same list with `read_through` true and false | `tests/CaracteristicaReadThroughTest.php`. |
| Guard | No `'tarifario'` literal / `plugins/tarifario/` path in the accessor or the four seams; the four seams expose `visibilidad_activa` | Extend `tests/CaracteristicaBoundariesTest.php` (source scan, comments stripped). |
| Behavior | Article list headers/cells hide, colspan follows the active set, price/Activo/feature columns unchanged | Extend `tests/Controller/VentasArticulosListCaracteristicasTest.php` (source-gate + colspan contract; the repo's established style for full-page templates). |
| Behavior | Partial checkboxes absent while price + `activo` stay; present when active | Extend `tests/TarifTabPreciosTest.php` and `tests/Controller/VentasArticuloPerTarifaPaneTest.php` (these already render the real partial through a Twig `ArrayLoader`). |
| Behavior | Opcionales list + edit labels/summary gates; multitarifa surfaces stay | Extend `tests/Controller/VentasOpcionalesControllerMasterStateTest.php`, `tests/TarifOpcionalesControllerMasterStateTest.php`, `tests/TarifOpcionalEditCaracteristicaTest.php` (source contracts, matching the existing style). |
| Behavior | Partial gate wraps exactly the two controls | Extend `tests/Integration/CatalogoArticuloHookOwnershipTest.php` (partial source contract). |
| Integration/E2E | None | No E2E harness for these plugin pages; N/A. |

### Existing tests affected (the ~8 from the proposal), refined

| Test file | Effect of the gate | Required change |
|---|---|---|
| `tests/Controller/VentasArticuloPerTarifaPaneTest.php` | Renders the real partial and asserts `en_tarifa`/`en_catalogo` checkboxes present (`:501-506`). | Resolver double must return the active list (e.g. `['en_tarifa','en_catalogo']`); add an inactive case. This is the DB-free equivalent of seeding `['tarifario']`. |
| `tests/TarifTabPreciosTest.php` | Renders the real partial and asserts all four inputs present (`:832-838`). | Same resolver-double update; add active vs inactive render assertions. |
| `tests/Controller/VentasArticulosListCaracteristicasTest.php` | Asserts the colspan formula (`:376-394`) and header order. | Update the colspan expectation to `2 + fsc.visibilidad_activa|length + …`; add gate source assertions. |
| `tests/CaracteristicaReadThroughTest.php` | Trait host, no view render. | Add the read-mode uniformity seam test. No seeding. |
| `tests/Controller/VentasArticulosListAbsorptionTest.php` | Tests `articulo_en_*` helper values only. | No change (helpers are not gated). |
| `tests/CatalogoOpcionalesUnifiedControllerTest.php` | Source/link contracts. | No change. |
| `tests/Controller/VentasOpcionalesControllerMasterStateTest.php` | Source contract on `fsc.opcional_en_*` strings. | Add gate source assertions; strings stay present. No seeding. |
| `tests/TarifOpcionalesControllerMasterStateTest.php` | Source contract on list/edit views. | Add gate source assertions. No seeding. |
| `tests/TarifOpcionalEditCaracteristicaTest.php` | Source contract on labels/`<th>` strings. | Update `test_view_keeps_the_visibility_columns_as_read_only_cells` to assert the gate wraps them. No seeding. |
| `tests/Integration/CatalogoArticuloHookOwnershipTest.php` | Partial source contract; host render does not server-render the partial. | Add gate assertion on the partial source; no seeding. |

Ownership tests that MUST keep passing unchanged:
`tests/ArticuloTarifaPrecioOwnershipTest.php`,
`tests/ArticuloListaCanonicaOwnershipTest.php` (`:214`),
`tests/ArticuloDetalleCanonicoOwnershipTest.php`,
`tests/OpcionalDomainModelOwnershipTest.php`.

Seeding pattern already exists: `tests/CaracteristicaOwnershipTest.php:158,181,367`
and `tests/Services/ArticuloExcelCaracteristicaTest.php:307` set
`$GLOBALS['plugins'] = ['tarifario']` and restore in `tearDown()`.

### Strict TDD — RED-first sequence

**PR1 (accessor + seams + unit tests; no view behavior change)**

1. RED — add to `tests/CaracteristicaResolverTest.php`:
   `test_active_visibility_codigos_returns_only_enabled_owner_codigos`,
   `test_active_visibility_codigos_empty_when_owner_disabled`,
   `test_active_visibility_codigos_ignores_operator_owned_rows`,
   `test_is_visibility_active_rejects_unknown_and_inert_codigos`,
   `test_active_visibility_codigos_reads_definitions_once` (definition-model call
   counter). Fails: methods missing.
2. GREEN — implement `active_visibility_codigos()` / `is_visibility_active()`.
3. RED — add the trait seam tests (host using the real
   `VentasArticulosListTrait` with a resolver double; and
   `ReadThroughListHost` in `tests/CaracteristicaReadThroughTest.php`). Fails:
   `visibilidad_activa()` missing.
4. GREEN — add the seam + cache to `extras/VentasArticulosListTrait.php` and
   `extras/VentasOpcionalesListTrait.php`.
5. RED/GREEN — add the boundaries guard
   (`test_visibility_gate_has_no_plugin_name_literal`,
   `test_the_four_render_servers_expose_visibilidad_activa`) to
   `tests/CaracteristicaBoundariesTest.php`; passes once the seams exist, fails if
   a literal or a missing seam is introduced.
6. GREEN gate — run the plugin suite: no view behavior changed, all existing
   suites stay green.

**PR2 (view gates + colspan + behavior tests; the fail-closed slice)**

1. RED — update the colspan expectation in
   `tests/Controller/VentasArticulosListCaracteristicasTest.php` to the new
   formula; add source-gate assertions for the 2 headers + 2 cells. Fails against
   the ungated view.
2. GREEN — apply gates 1–3 and the colspan in `ventas_articulos.html.twig`.
3. RED — extend `TarifTabPreciosTest` / `VentasArticuloPerTarifaPaneTest` with
   an inactive resolver double; assert the checkboxes are absent while price +
   `activo` remain. Fails.
4. GREEN — apply the partial gate (edit 4).
5. RED — add gate source tests to
   `VentasOpcionalesControllerMasterStateTest` / `TarifOpcionalesControllerMasterStateTest` /
   `TarifOpcionalEditCaracteristicaTest` / `CatalogoArticuloHookOwnershipTest`.
6. GREEN — apply gates 5–6.
7. GREEN gate — the existing partial-render tests now supply the active list via
   their resolver doubles (previously implicit) so active behavior is unchanged.
8. Full plugin suite green; root PHPUnit regression.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file
classification, or process-integration boundary is touched. The change is
read-only view gating over an existing resolver.

## Ownership Safety

- The accessor reads only `VISIBILITY_CODIGOS` and the existing CAR-20 ownership
  filter (`definitions()` → `CaracteristicaOwnership::is_active()`).
- No production file gains a `'tarifario'` literal, a `plugins/tarifario/` path
  or an `@tarifario/` / `FSFramework\Plugins\tarifario` reference.
- No `catalogo_core → tarifario` dependency: `fsframework.ini` `require` stays
  empty; `CaracteristicaBoundariesTest` keeps enforcing both the class-name
  baseline and the frozen Composer baseline.
- The four seams delegate to the resolver; they add no plugin knowledge.

## Data Preservation

- No schema change, no `DROP`, no `DELETE`, no destructive migration.
- The two characteristics' stored values stay in `catalogo_caracteristicas*`;
  deactivation only hides their surfaces. Re-activating the owner restores them
  immediately (runtime read, not persisted state).
- The same-named multitarifa DB columns (`tarif_articulo_precio`,
  `tarif_tarifa_articulo`, `tarif_tarifa_familia`,
  `catalogo_opcional_precio`) are a **different concept** and are untouched.
- The partial's save path (`persist_articulo_visibility()` →
  `CaracteristicaValorStore`) already ignores inert definitions per CAR-20, so a
  hidden control posted as false writes nothing for an inactive definition. No
  save-path change is needed.

## Chained PR Split (review policy 400 changed lines)

Forecast ~370–430 changed lines (borderline). 2-PR chain on `stacked-to-main`:

| Slice | Contents | Targets | Independently green |
|---|---|---|---|
| **PR1** (~180–210 lines) | `CaracteristicaResolver` accessor; `visibilidad_activa()` seams in the 4 servers; accessor/trait/read-mode/boundaries tests. | `main` | Yes — no view behavior changes; all existing suites unchanged. |
| **PR2** (~170–200 lines) | 4 view gates + colspan; partial + list/edit behavior tests; resolver-double updates. | PR1 branch | Yes — stacked on PR1, which is already green. |

Each slice has a clear start/finish, autonomous scope, verification (plugin
suite), and `git revert` rollback. Neither slice exceeds the 400-line budget.

Forecast lines:
```
Decision needed before apply: No
Chained PRs recommended: Yes
400-line budget risk: Medium
```

## Rollback

Purely additive view gating:

1. Re-activate `tarifario` — every surface returns immediately, because the gate
   is a runtime read of the resolver, not persisted state.
2. Revert the code — remove the `visibilidad_activa` gates; no migration to
   unwind, no schema to restore, no rows to re-create.
3. `git revert` of either PR slice cannot lose a row.

## Open Questions

- None blocking. The rename to
  `gate-visibility-characteristics-by-plugin-activation` remains a recorded
  recommendation only; directories are NOT renamed in this phase.
