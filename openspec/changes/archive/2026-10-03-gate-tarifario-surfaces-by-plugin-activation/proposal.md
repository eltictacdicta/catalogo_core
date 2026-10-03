# Proposal: Gate visibility characteristics by plugin activation

- Change: `gate-tarifario-surfaces-by-plugin-activation` (see "Rename recommendation")
- SDD owner: `plugins/catalogo_core/openspec/` (plugin-local, strict TDD)
- Artifact store: openspec
- Inventory source: `rescope-explore.md` (narrow scope, supersedes `explore.md`)
- Core `openspec/` entry: NONE (see "No core openspec entry")

---

## Intent

`tarifario` registers exactly two feature definitions, `en_catalogo` and
`en_tarifa`, with `origen='tarifario'` (`plugins/tarifario/Init.php:122-129`).
catalogo_core only defaults `medidas`, so those two definitions exist solely
while the owning plugin is active.

The **data path already degrades correctly**. `CaracteristicaResolver::definitions()`
drops any definition whose `origen` is not in `Plugins::enabled()`
(CAR-20 / `CaracteristicaOwnership::is_active()`), and
`CaracteristicaValorBatchReader::columns()` inherits that filter, so the values
resolve to `null` / `false` while `tarifario` is inactive.

The problem is the **hardcoded view surface**: four view families render the
`Tarifa` / `Catálogo` headers, cells and labels unconditionally and call helpers
that now return `false`, so the UI shows stale `✗` / "No" columns that no longer
correspond to a real, editable characteristic. This is a **migration
side-effect**: the views were written when the characteristics were always
present, and deactivating `tarifario` leaves the surface emitting misleading
data.

This proposal gates ONLY those two characteristics' hardcoded surfaces, driven
by the resolver's existing ownership filter — no plugin-name literal, no new
service, no endpoint guards, no menu sync.

## Scope

### In Scope

The two visibility characteristics `en_catalogo` / `en_tarifa` disappear from
exactly four view families when their definition is inactive:

- **Article list** `View/ventas_articulos.html.twig`: headers `:146-147`
  (`Tarifa` / `Catálogo`) and cells `:191-204` (both render `✗`).
- **Article detail partial** `View/Hooks/partials/articulo_precios_rows.html.twig`:
  `en_tarifa` checkbox `:41-49`, `en_catalogo` checkbox `:50-58` (served by
  `controller/tarif_tab_precios.php:134-165`; the pane itself stays).
- **Opcionales list** `View/ventas_opcionales.html.twig`: headers `:392-398`,
  cells `:467-484`.
- **Opcional detail** `View/tarif_opcional_edit.html.twig`: labels `:294-307`,
  summary headers `:336`, values `:346-347`.
- **Gate mechanism**: a resolver accessor (`active_visibility_codigos()` /
  `is_visibility_active()`) + a `visibilidad_activa()` pass-through on the
  controller/list seams the views already have.
- **Empty-state colspan** `View/ventas_articulos.html.twig:232`: recompute when
  the two visibility columns hide.

### Out of Scope (explicitly NOT touched)

Everything below was wrongly included by the superseded broad proposal. It stays
exactly as it is:

- **Multitarifa**: `tarif_tarifas`, `tarif_familias`, the `b_codtarifa` tarifa
  selector (`ventas_articulos.html.twig:83-92`, `ventas_opcionales.html.twig:319-334`),
  the per-tarifa prices pane, `tarif_tab_precios`, the `Tarifas`/`Precio`/`Activo`
  columns, the new-article price block, the per-tarifa price inputs and the
  `activa` master toggle. Owner: catalogo_core — KEEP.
- **Opcionales pages as a whole**: `ventas_opcionales`, `tarif_opcional_edit`,
  `tarif_opcional_*` — KEEP, EXCEPT the two visibility headers/cells/labels.
- **`clientes_core` `codtarifa`**: not in scope.
- **Menu hiding**: `fs_pages.show_on_menu` sync and the constructor `$shmenu`
  gate — DROPPED.
- **Endpoint 404s / empty fragments**: `tarif_tarifas`, `tarif_familias`,
  `tarif_opcional_edit`, `tarif_tab_precios`, `tarif_opcional_tab` guards —
  DROPPED.
- **`TarifarioGate` service, `Init::init()` fs_pages sync, shared trait** — DROPPED.
- **DB-column lookalikes**: `catalogo_opcional_precio.en_catalogo` (per-lista
  price inclusion; `opcional_precios_rows.html.twig:50-51`) and
  `tarif_tarifa_familia.en_catalogo/en_tarifa` (tariff membership) are
  data-model flags, not characteristics — KEEP.
- **Macro** `View/Macro/TarifarioComponents.html.twig` `toggle_button_group`:
  only used with `show_*: true` by multitarifa family surfaces — no change.
- No data deletion, no schema change, no destructive migration.

## Capabilities

### New Capabilities

- `visibility-characteristic-gating`: when a visibility characteristic
  (`en_catalogo` / `en_tarifa`) has no active definition for the current enabled
  plugin set, its hardcoded surface (header/label/cell/control) MUST NOT render
  in the four view families. The gate source is the resolver's active-visibility
  list derived from the CAR-20 ownership filter. The article-edit partial gate is
  a surface requirement inside this capability.

### Modified Capabilities

Canonical names verified in `plugins/catalogo_core/openspec/specs/`:

- `articulo-lista-canonica` — **KEEP-NARROWED** `ALC-02` (Per-tarifa columns):
  narrow its scenario to the **two visibility columns only** (`en_catalogo` /
  `en_tarifa`); the price and `Activo` columns stay. `ALC-01` (filter
  absorption) is DROPPED — `b_codtarifa` is multitarifa.
- `opcionales-management` — **KEEP-NARROWED** `OUM-03` (Per-tarifa state and
  price display): narrow to "the two visibility indicators/columns hide while
  the definition is inactive, while `activa` / price stay".
- `caracteristicas-producto` — **reference only, NO delta**: `CAR-19`
  (Plugin-local boundaries) and `CAR-20` (Enabled-plugin ownership scope) already
  define the owner-plugin activity rule the gate reuses. Duplicating the rule
  would drift.

Everything else from the superseded broad proposal is DROPPED from the delta set
(`articulo-detalle-canonico`, `articulo-tarifa-tab-management`,
`opcionales-tarifa-selector`, `familias-tarifa-management`,
`catalogo-render-hooks`, and the old `tarifario-surface-gating` domain).

## Approach

**Signal: "the characteristic definition is active for the current enabled
plugin set." Reuse the resolver's existing CAR-20 ownership filter instead of a
new plugin-name gate.**

### Resolver accessor (single new production API)

```php
// Services/CaracteristicaResolver.php
/** @return list<string> Active visibility codigos for the enabled-plugin set (CAR-20). */
public function active_visibility_codigos(): array
{
    return array_values(array_intersect(
        self::VISIBILITY_CODIGOS,
        array_keys($this->definitions())   // definitions() already applies CAR-20
    ));
}

public function is_visibility_active(string $codigo): bool
{
    return in_array($codigo, $this->active_visibility_codigos(), true);
}
```

### View-facing seam (one implementation per list seam, no new service)

- `extras/VentasArticulosListTrait.php`: expose the existing resolver seam and add
  `public function visibilidad_activa(string $codigo): bool` delegating to the
  resolver.
- `extras/VentasOpcionalesListTrait.php`: same method via its existing
  `opcional_visibility_resolver()` seam.
- `Controller/VentasArticulo.php`, `controller/tarif_tab_precios.php`,
  `controller/tarif_opcional_edit.php`: same public method via their existing
  `caracteristica_resolver()` seams.

### View gating

Replace each hardcoded render with a gate:

```twig
{% if fsc.visibilidad_activa('en_tarifa') %}
  <th class="text-center" width="70">Tarifa</th>
{% endif %}
```

```twig
{% if fsc.visibilidad_activa('en_tarifa') %}
  <td class="text-center"> ... articulo_en_tarifa_flag(...) ... </td>
{% endif %}
```

### Why this is correct and minimal

- **Removes activity hardcoding, not the codigos.** The strings `en_catalogo` /
  `en_tarifa` are canonical characteristic codigos (existing shared vocabulary,
  `VentasArticulosListTrait::VISIBILITY_CODIGOS:48`), not a plugin path or
  namespace — ownership-safe by construction.
- **CAR-20 reuse.** `definitions()` already filters by owner-plugin activity, so
  the accessor is fail-closed for free and adds no query and no new plugin-name
  literal.
- **Value-shape compatible.** Helpers keep returning the value; only the view
  decides whether to render at all.
- **Keeps the surgical boundary.** The price/`activo` columns, the `b_codtarifa`
  selector, the per-tarifa price inputs and the opcional master `activa` are
  multitarifa and MUST stay.

### Locked decision: uniform across read modes (Q3)

The gate is driven by "visibility definition is active", independent of
`FS_CATALOGO_CARACTERISTICAS_READ_THROUGH`. Even in legacy read mode
(`READ_THROUGH=false`, where `articulo_visibility_bool()` reads the multitarifa
`tarif_articulo_precio` DB columns), the characteristic surfaces hide when the
definition is inactive. The gate is uniform in both read modes.

### Rejected alternatives

- `TarifarioGate` service keyed on the bare `'tarifario'` string — re-introduces
  the plugin name where the resolver already answers the question; larger test
  blast radius. DROPPED.
- Twig global `visibilidad_activa` — every affected template already receives
  `fsc`; adds test surface for no gain.
- Gating the whole per-tarifa pane — wrong; the pane serves multitarifa data.

## Data-preservation statement

Deactivation MUST NOT delete or alter any data. Specifically:

- No schema change, no `DROP`, no `DELETE`, no destructive migration.
- The two characteristics' stored values remain in the database; deactivation
  only hides their view surfaces. Re-enabling `tarifario` restores them
  immediately (runtime read, not persisted state).
- The multitarifa DB columns of the same name (`tarif_articulo_precio`,
  `tarif_tarifa_articulo`, `tarif_tarifa_familia`, `catalogo_opcional_precio`)
  are untouched — they are a different concept and remain in use.
- `git revert` of this change cannot lose a row.

## Blast radius

### Production files

| File | Change | Est. lines |
|------|--------|-----------|
| `Services/CaracteristicaResolver.php` | add `active_visibility_codigos()` + `is_visibility_active()` | ~18 |
| `extras/VentasArticulosListTrait.php` | resolver seam + `visibilidad_activa()` | ~18 |
| `extras/VentasOpcionalesListTrait.php` | `visibilidad_activa()` via existing seam | ~12 |
| `Controller/VentasArticulo.php` | `visibilidad_activa()` | ~10 |
| `controller/tarif_tab_precios.php` | `visibilidad_activa()` | ~10 |
| `controller/tarif_opcional_edit.php` | `visibilidad_activa()` | ~10 |
| `View/ventas_articulos.html.twig` | gate 2 headers + 2 cells (+ colspan fix) | ~10 |
| `View/ventas_opcionales.html.twig` | gate 2 headers + 2 cells | ~8 |
| `View/tarif_opcional_edit.html.twig` | gate labels + summary th/td | ~10 |
| `View/Hooks/partials/articulo_precios_rows.html.twig` | gate 2 controls | ~6 |
| **Subtotal production** | | **~112** |

### Affected canonical specs by ID

- **KEEP-NARROWED**: `articulo-lista-canonica` ALC-02;
  `opcionales-management` OUM-03.
- **Reference only (no delta)**: `caracteristicas-producto` CAR-19 / CAR-20.
- **DROPPED**: ALC-01; all `articulo-detalle-canonico`, `articulo-tarifa-tab-management`,
  `opcionales-tarifa-selector`, `familias-tarifa-management`,
  `catalogo-render-hooks`; the old `tarifario-surface-gating` domain.

### Tests

| Area | Est. lines |
|------|-----------|
| `tests/CaracteristicaResolverTest.php` — new accessor unit tests (active / inactive / fail-closed) | ~60 |
| View/controller behavior updates that must seed `$GLOBALS['plugins'] = ['tarifario']` | ~120-160 |
| **Subtotal tests** | **~180-220** |

**Tests affected (~8)** — must seed `['tarifario']` in `setUp()` and restore in
`tearDown()`, or assert the gated-off state:

- `tests/Controller/VentasArticulosListCaracteristicasTest.php` (`:368`, `:383`
  assert the 4 per-tarifa columns).
- `tests/Controller/VentasArticulosListAbsorptionTest.php` (`articulo_en_*` helpers).
- `tests/CaracteristicaReadThroughTest.php`.
- `tests/CatalogoOpcionalesUnifiedControllerTest.php`.
- `tests/Controller/VentasOpcionalesControllerMasterStateTest.php`.
- `tests/TarifOpcionalesControllerMasterStateTest.php` (`opcional_en_*`).
- `tests/Integration/CatalogoArticuloHookOwnershipTest.php` (`:387-410`, partial controls).
- `tests/TarifOpcionalEditCaracteristicaTest.php` (`:165-168`, view strings).

Ownership tests that MUST keep passing unchanged:
`tests/ArticuloTarifaPrecioOwnershipTest.php`,
`tests/ArticuloListaCanonicaOwnershipTest.php` (`:214`),
`tests/ArticuloDetalleCanonicoOwnershipTest.php`,
`tests/OpcionalDomainModelOwnershipTest.php`.

Seeding pattern already exists: `tests/CaracteristicaOwnershipTest.php:158,181,367`
and `tests/Services/ArticuloExcelCaracteristicaTest.php:307`.

### Empty-state colspan

`View/ventas_articulos.html.twig:232` hardcodes `4 + listable` per-tarifa
columns. It must be computed from the active visibility set so the empty row
stays aligned when the two visibility columns hide. Cosmetic but required.

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| **Fail-closed test breakage**: view/behavior tests that render without seeding `$GLOBALS['plugins']` will hide the columns | High | Seed `['tarifario']` in the ~8 listed files in the same change (strict TDD); keep pure-resolver tests seam-injected |
| **Mixed-concept regression**: touching the multitarifa `en_catalogo`/`en_tarifa` DB columns or the per-tarifa price/`activo` would violate scope | Med | Keep every edit surgical per Section 1 of `rescope-explore.md`; never gate price/`activo`/selector |
| **Legacy-mode ambiguity (Q3)**: `READ_THROUGH=false` reads multitarifa DB columns of the same name | Low | Locked decision: gate uniformly; the characteristic surface hides in both read modes. Documented and tested |
| **Ownership-test breakage** from an accidental `plugins/tarifario/` or `@tarifario/` string | Low | Accessor uses only canonical codigo strings and existing APIs; ownership tests fail loudly in CI |
| **Empty-state misalignment** if colspan is not recomputed | Low | Recompute `:232` from the active visibility set |
| `$GLOBALS['plugins']` unseeded in unrelated tests silently changes behavior | Med | Fail-closed is the intended default; only the listed visibility-UI tests need seeding |

## Rollback Plan

The change is **purely additive view gating**:

1. Re-activate the `tarifario` plugin — all surfaces return immediately because
   the gate is a runtime read of the resolver, not persisted state.
2. To revert the code, remove the `visibilidad_activa()` gates; no migration to
   unwind, no schema to restore, no rows to re-create.
3. Data is untouched throughout: `git revert` of the change cannot lose rows.

## Dependencies

- Core `\FSFramework\Core\Plugins` (transitively, via `CaracteristicaResolver` /
  `CaracteristicaOwnership`) — already available; no new dependency.
- No Composer dependency added, so no `vendor/` commit step is required.

## Review Workload Forecast

| Work unit | Est. changed lines |
|-----------|--------------------|
| Resolver accessor + `visibilidad_activa()` seams + unit tests | ~130 |
| View gates (4 templates) + colspan fix | ~40 |
| Behavior-test seeding updates (~8 files) | ~120-160 |
| Spec deltas (new domain + 2 narrowed MODIFIED) + docs | ~80-100 |

- **Total**: roughly **370–430 changed lines**, borderline at the 400-line budget.
- **Decision needed before apply**: **No** — no product decision remains; the
  scope is locked and Q3 is resolved (gate uniformly in both read modes).
- **Chained PRs recommended**: **Yes** (borderline; 2 slices):
  1. Resolver accessor + `visibilidad_activa()` seams + unit tests (autonomous,
     no UI behavior change yet).
  2. View gates + behavior-test seeding updates (the slice that first makes the
     tests fail-closed).
- **400-line budget risk**: **Medium**.

## Rename recommendation (note only)

The change name `gate-tarifario-surfaces-by-plugin-activation` and the old
`tarifario-surface-gating` domain overstate the scope. Recommend renaming to
`gate-visibility-characteristics-by-plugin-activation`. **Do NOT rename in this
pass** — this proposal only records the recommendation.

## No core openspec entry

This change is 100% within `plugins/catalogo_core/`. Per the project rule in
`AGENTS.md` → "OpenSpec per Plugin (SDD ownership)" and
`plugins/catalogo_core/openspec/config.yaml`, **no entry is created in the core
`openspec/`**. Creating one would be the explicit anti-pattern.

## Success Criteria

- [ ] With `tarifario` inactive: `Tarifa` / `Catálogo` headers and cells do not
      render in `ventas_articulos`, `ventas_opcionales`, `tarif_opcional_edit` or
      `articulo_precios_rows`.
- [ ] With `tarifario` inactive: the `en_catalogo` / `en_tarifa` checkboxes are
      absent from the article-edit partial, while the pane's price/`activo`
      controls remain.
- [ ] With `tarifario` inactive: multitarifa surfaces (selector `b_codtarifa`,
      price/`Activo` columns, `tarif_tarifas`, `tarif_familias`) are unchanged.
- [ ] With `tarifario` active: all prior behavior is unchanged (existing suites
      green after seeding `['tarifario']`).
- [ ] The gate is uniform in both `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH`
      modes.
- [ ] Empty-state row alignment is correct when the visibility columns hide.
- [ ] No data row is deleted or altered; no schema change; `git revert` cannot
      lose data.
- [ ] Ownership tests pass unchanged (no `plugins/tarifario/` or `@tarifario/`
      substring in production code).
- [ ] No entry exists under the core `openspec/changes/` for this change.
