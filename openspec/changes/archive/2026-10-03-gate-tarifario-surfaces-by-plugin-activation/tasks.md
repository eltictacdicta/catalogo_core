# Tasks: Gate visibility characteristics by plugin activation

- Change: `gate-tarifario-surfaces-by-plugin-activation`
  (rename recommended, **NOT applied**: `gate-visibility-characteristics-by-plugin-activation`)
- SDD owner: `plugins/catalogo_core/openspec/` (plugin-local, `strict_tdd: true`)
- Artifact store: openspec
- Inputs: `proposal.md`, `design.md`, `rescope-explore.md`,
  `specs/visibility-characteristic-gating/spec.md` (VCG-01..08),
  `specs/articulo-lista-canonica/spec.md` (ALC-02),
  `specs/opcionales-management/spec.md` (OUM-03)
- Core `openspec/` entry: NONE — this change is 100% within
  `plugins/catalogo_core/`.

Goal: remove the hardcoded `Tarifa` / `Catálogo` view surfaces for the two
visibility characteristics `en_catalogo` / `en_tarifa` when their owning
definition is inactive for the current enabled-plugin set, driven only by
`CaracteristicaResolver`'s existing CAR-20 ownership filter. No new service, no
plugin-name literal, no endpoint guard, no menu sync, no schema/data change.

---

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~370–430 |
| 400-line budget risk | Medium |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 |
| Delivery strategy | auto-chain |
| Chain strategy | stacked-to-main |

```text
Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: Medium
```

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Resolver accessor + `visibilidad_activa()` seams in the four render servers + accessor/trait/read-mode/boundaries tests. No view behavior change. | PR 1 (base `main`) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CaracteristicaResolverTest\|CaracteristicaReadThroughTest\|CaracteristicaBoundariesTest\|VentasArticulosListCaracteristicasTest'` | N/A — unit/host tests only; no user-facing surface changes in this slice. | Revert `plugins/catalogo_core/Services/CaracteristicaResolver.php`, the four seam files and the new tests; no view was touched. |
| 2 | 4 view gates + empty-state colspan + partial/list/edit behavior tests + resolver-double updates. First slice that makes the gates fail-closed. | PR 2 (base = PR 1 branch) | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | Twig `ArrayLoader` render of the real `articulo_precios_rows` partial in `tests/TarifTabPreciosTest.php` and `tests/Controller/VentasArticuloPerTarifaPaneTest.php`. | Revert the 4 templates + the 5 test files; PR 1 stays green and independently revertable. |

Both slices stay under the 400-line budget. PR 2 is **stacked on PR 1** and must
be retargeted/rebased if PR 1 changes.

---

## Scope and locked constraints (read before implementing)

### Ownership constraint

- The gate answers solely from `CaracteristicaResolver::VISIBILITY_CODIGOS`
  (`['en_catalogo', 'en_tarifa']`) and the existing CAR-20 ownership filter inside
  `definitions()`. No production `catalogo_core` file may add a `'tarifario'`
  literal, a `plugins/tarifario/` path, an `@tarifario/` reference, or a
  `catalogo_core → tarifario` dependency. `fsframework.ini` `require` stays empty.
- `plugins/tarifario/Init.php` (read-only) registers these definitions; that is
  the owner, not a dependency.

### Locked decision Q3 — uniform across read modes

- The gate is driven by the definition's **activity**, never by
  `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH`. It hides the characteristic
  surfaces identically when the flag is undefined/`TRUE` and when it is
  explicitly `FALSE`. The template condition is evaluated **before** the value
  helper, so the legacy branch cannot re-introduce a hidden column.
- The value helpers (`articulo_visibility_bool()` / `articulo_en_*`,
  `opcional_en_*`) keep their return shape. Only the view decides whether to
  render.

### Exact empty-state `colspan` formula

Replace the constant per-tarifa term `4` in
`plugins/catalogo_core/View/ventas_articulos.html.twig` (line 232) with the
active-visibility-aware term:

```twig
<td colspan="{{ 7 + (fsc.tarifa_seleccionada ? 2 + fsc.visibilidad_activa|length + fsc.listable_caracteristicas()|length : 0) }}">
```

- `7` = fixed base (reference, description, family, manufacturer, price, Stock,
  Actions).
- `2` = the always-rendered multitarifa `precio` + `Activo` columns.
- `fsc.visibilidad_activa|length` = the active visibility codigos (0..2).
- When `fsc.tarifa_seleccionada` is falsy the whole per-tarifa block is inside the
  `{% if %}`, so the term is `0` and the base `7` is unchanged.

### Test-scope correction (honest scope)

The proposal's "~8 tests must seed `['tarifario']`" is **overstated**. Only the
two tests that render the **real partial** need the active resolver list; the
rest are source-contract or helper-value tests and need **no seeding**:

| Test file | Real render? | Required change |
|---|---|---|
| `plugins/catalogo_core/tests/TarifTabPreciosTest.php` | Yes (real partial via Twig `ArrayLoader`) | Resolver double must implement `active_visibility_codigos()`; add inactive-render assertions. |
| `plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php` | Yes (real partial) | Same double update; add inactive-render assertions. |
| `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php` | No (source/contract) | Update colspan expectation + add gate-source assertions. NO seeding. |
| `plugins/catalogo_core/tests/CaracteristicaReadThroughTest.php` | No (trait host) | Add read-mode uniformity assertion. NO seeding. |
| `plugins/catalogo_core/tests/Controller/VentasArticulosListAbsorptionTest.php` | No (helper values) | NO change (helpers are not gated). |
| `plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php` | No (source/link contract) | NO change. |
| `plugins/catalogo_core/tests/Controller/VentasOpcionalesControllerMasterStateTest.php` | No (source contract) | Add gate-source assertions. NO seeding. |
| `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php` | No (source contract) | Add gate-source assertions. NO seeding. |
| `plugins/catalogo_core/tests/TarifOpcionalEditCaracteristicaTest.php` | No (source contract) | Update the visibility-columns test to assert the gate. NO seeding. |
| `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` | No (partial source contract) | Add gate assertion on the partial source. NO seeding. |

`$GLOBALS['plugins']` seeding is therefore needed only if a test exercises the
real resolver/ownership path; the two partial tests avoid it by using a DB-free
resolver double. Existing seeding pattern (reference only):
`plugins/catalogo_core/tests/CaracteristicaOwnershipTest.php:158,181,367`
(read-only).

### DB-free resolver double contract

Any anonymous resolver double injected through `caracteristica_resolver()` /
`opcional_visibility_resolver()` that will serve a gated template MUST expose the
new accessor in addition to `resolve_bool()`:

```php
public function active_visibility_codigos(): array
{
    return $this->outer->activeVisibility; // e.g. ['en_catalogo', 'en_tarifa'] or []
}
```

The doubled controllers' `visibilidad_activa()` seam delegates to it, so a double
without the method will fatal. This is the fail-closed path, not a test bug.

### Verification command (every task)

```bash
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
```

---

## Phase 1 — PR1: Resolver accessor (TDD)

### 1.1 RED — accessor unit tests

- [x] Add to `plugins/catalogo_core/tests/CaracteristicaResolverTest.php`,
      using the existing `buildResolver(array $definitions, array $enabledPlugins = [])`
      harness (read-only reference: `:94`):
  - [x] `test_active_visibility_codigos_returns_only_enabled_owner_codigos`
  - [x] `test_active_visibility_codigos_empty_when_owner_disabled`
  - [x] `test_active_visibility_codigos_ignores_operator_owned_rows`
  - [x] `test_is_visibility_active_rejects_unknown_and_inert_codigos`
  - [x] `test_active_visibility_codigos_reads_definitions_once`
        (definition-model call counter; proves no extra query)
- [x] Run the plugin suite; the new tests fail because the methods do not exist
      (RED). Do not implement yet.

### 1.2 GREEN — resolver accessor

- [x] In `plugins/catalogo_core/Services/CaracteristicaResolver.php`, add:
  - [x] `public function active_visibility_codigos(): array` — intersect
        `self::VISIBILITY_CODIGOS` with `array_keys($this->definitions())`
        (order preserved, no new query).
  - [x] `public function is_visibility_active(string $codigo): bool` — strict
        `in_array` over `active_visibility_codigos()`.
- [x] Re-run the plugin suite; Phase 1 tests pass (GREEN).

### 1.3 REFACTOR — accessor

- [x] Keep `definitions()` the single query source; confirm
      `active_visibility_codigos()` issues no query (assertion from 1.1 still
      green). Re-run the plugin suite.

---

## Phase 2 — PR1: `visibilidad_activa()` seams (TDD)

The method is **no-arg** and returns the active list; Twig uses membership
(`'en_tarifa' in fsc.visibilidad_activa`). Each server memoizes it per request.

### 2.1 RED — trait seam value + memoization tests

- [x] Extend the real-trait host in
      `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`
      with a resolver double and assert:
  - [x] `visibilidad_activa()` returns the accessor list.
  - [x] the accessor is evaluated once across repeated `visibilidad_activa()`
        calls (memoization).
  - [x] with no active codigos it returns `[]`.
- [x] Run the suite; fails: method missing (RED).

### 2.2 RED — read-mode uniformity test

- [x] In `plugins/catalogo_core/tests/CaracteristicaReadThroughTest.php`, extend
      `ReadThroughListHost` (read-only reference: `:68`) with a
      `visibilidad_activa()` assertion: the returned list is identical with
      `read_through` `TRUE` and explicitly `FALSE` (VCG-06). Fails (RED).

### 2.3 RED — boundaries guard additions

- [x] Extend `plugins/catalogo_core/tests/CaracteristicaBoundariesTest.php`
      (source scan, comments stripped) with:
  - [x] `test_visibility_gate_has_no_plugin_name_literal` — no `'tarifario'`
        literal / `plugins/tarifario/` path in the accessor or the four seams.
  - [x] `test_the_four_render_servers_expose_visibilidad_activa` — the four
        servers expose the seam.
- [x] Run the suite; both fail until seams exist (RED).

### 2.4 GREEN — article list trait seam

- [x] In `plugins/catalogo_core/extras/VentasArticulosListTrait.php`:
  - [x] add `require_once` + import for `CaracteristicaResolver`;
  - [x] add `protected function caracteristica_resolver()` (new seam);
  - [x] add `private ?array $visibilidad_activa_cache = null;` and
        `public function visibilidad_activa(): array` delegating to
        `active_visibility_codigos()`, memoized.

### 2.5 GREEN — opcionales list trait seam

- [x] In `plugins/catalogo_core/extras/VentasOpcionalesListTrait.php`, add the
      cache property + `public function visibilidad_activa(): array` via the
      existing `opcional_visibility_resolver()` seam (read-only reference:
      `:180`).

### 2.6 GREEN — `tarif_tab_precios` seam

- [x] In `plugins/catalogo_core/controller/tarif_tab_precios.php`, add the cache
      property + `public function visibilidad_activa(): array` via the existing
      `caracteristica_resolver()` seam (read-only reference: `:102`).

### 2.7 GREEN — `tarif_opcional_edit` seam

- [x] In `plugins/catalogo_core/controller/tarif_opcional_edit.php`, add the
      cache property + `public function visibilidad_activa(): array` via the
      existing `opcional_visibility_resolver()` seam (read-only reference:
      `:330`).

### 2.8 REFACTOR + PR1 GREEN gate

- [x] Re-run the full plugin suite: Phases 1–2 tests green, the boundaries guard
      green, and **no view behavior changed** (all pre-existing view/behavior
      suites unchanged).
- [x] `Controller/VentasArticulo.php` (singular) is intentionally **NOT** a seam
      site (it hosts the AJAX fragment; `tarif_tab_precios` carries the seam).
      Confirm it is untouched.

---

## Phase 3 — PR2: Article list view gate (TDD)

### 3.1 RED — colspan + source-gate assertions

- [x] In `plugins/catalogo_core/tests/Controller/VentasArticulosListCaracteristicasTest.php`,
      update `test_empty_state_colspan_matches_the_rendered_column_count`
      (read-only reference: `:376`) to the exact formula
      `7 + (tarifa ? 2 + visibilidad_activa|length + listable|length : 0)`.
- [x] Add source-gate assertions for the two headers and two cells
      (`'en_tarifa' in fsc.visibilidad_activa` / `'en_catalogo' in ...`).
- [x] Run the suite; fails against the ungated view (RED). No `$GLOBALS['plugins']`
      seeding here (source contract).

### 3.2 GREEN — apply article list gates + colspan

- [x] In `plugins/catalogo_core/View/ventas_articulos.html.twig`:
  - [x] wrap the `Tarifa` header (`:146`) and the `Catálogo` header (`:147`) in
        the matching gate;
  - [x] wrap the `en_tarifa` cell (`:191-197`, calls
        `articulo_en_tarifa_flag`) and the `en_catalogo` cell (`:198-204`, calls
        `articulo_en_catalogo`) in the matching gate; leave the `precio`
        (`:177-183`), `Activo` (`:184-190`) and `listable` loop (`:205-207`)
        untouched;
  - [x] replace the empty-state colspan (`:232`) with the formula.
- [x] Re-run the suite; Phase 3 green (GREEN).

### 3.3 REFACTOR — article list

- [x] Keep gate expressions byte-consistent with the other templates; re-run the
      suite.

---

## Phase 4 — PR2: Article partial gate (TDD)

### 4.1 RED — real-partial tests + resolver doubles

- [x] In `plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php`,
      extend the anonymous resolver double returned by
      `caracteristica_resolver()` (read-only reference: `:187`) with
      `active_visibility_codigos()` reading an `$outer->activeVisibility`
      property; add an **inactive** case asserting the checkboxes are absent.
- [x] In `plugins/catalogo_core/tests/TarifTabPreciosTest.php`, do the same for
      its `caracteristica_resolver()` double (read-only reference: `:249`); add
      active vs inactive render assertions while asserting the price input and
      `activo` checkbox remain.
- [x] Run the suite; the inactive assertions fail (RED).

### 4.2 GREEN — gate the partial controls

- [x] In `plugins/catalogo_core/View/Hooks/partials/articulo_precios_rows.html.twig`:
  - [x] wrap the `col-sm-3` column holding the `en_tarifa` checkbox (`:41-49`) in
        `{% if 'en_tarifa' in fsc.visibilidad_activa %}`;
  - [x] wrap the `en_catalogo` column (`:50-58`) in
        `{% if 'en_catalogo' in fsc.visibilidad_activa %}`;
  - [x] leave the price input (`:16-29`), the `activo` checkbox (`:30-40`) and the
        `guardar_precio_tab` hidden/save block (`:59-71`) untouched.
- [x] Re-run the suite; PASS (GREEN). The double now supplies the active list, so
      active behavior is unchanged.

### 4.3 REFACTOR — partial

- [x] Re-run the suite; keep the doubles minimal and reuse the same
      `activeVisibility` fixture shape across both tests.

---

## Phase 5 — PR2: Opcionales list + opcional detail gates (TDD)

### 5.1 RED — source-gate assertions

- [x] Add gate-source assertions (no seeding) to:
  - [x] `plugins/catalogo_core/tests/Controller/VentasOpcionalesControllerMasterStateTest.php`
        — `Tarifa`/`Catálogo` headers and per-row cells wrapped by the gate;
        `b_codtarifa`/`Precio`/`Estado` stay.
  - [x] `plugins/catalogo_core/tests/TarifOpcionalesControllerMasterStateTest.php`
        — list/edit views expose the gate.
  - [x] `plugins/catalogo_core/tests/TarifOpcionalEditCaracteristicaTest.php` —
        update `test_view_keeps_the_visibility_columns_as_read_only_cells`
        (read-only reference: `:160`) to assert the gate wraps the labels and the
        summary `<th>`/`<td>`.
  - [x] `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php`
        — assert the partial source wraps exactly the two controls (read-only
        reference: `:387-410`).
- [x] Run the suite; fails against the ungated views (RED).

### 5.2 GREEN — gate opcionales list

- [x] In `plugins/catalogo_core/View/ventas_opcionales.html.twig`:
  - [x] wrap the `Tarifa` header (`:392-397`) and the `Catálogo` header (`:398`)
        in the matching gate;
  - [x] wrap the `en_tarifa` cell (`:467-475`, `opcional_en_tarifa_flag`) and the
        `en_catalogo` cell (`:476-484`, `opcional_en_catalogo_tarifa`) in the
        matching gate;
  - [x] leave `b_codtarifa` (`:319-334`), `Precio` (`:385-390` / `:439-448`) and
        `Estado` (`:391` / `:449-466`) untouched.

### 5.3 GREEN — gate opcional detail

- [x] In `plugins/catalogo_core/View/tarif_opcional_edit.html.twig`:
  - [x] wrap the `Catálogo:` / `En tarifa:` read-only labels (`:294-307`) each in
        the matching gate;
  - [x] wrap the summary `<th>Catálogo</th>` / `<th>En tarifa</th>` (`:336`) and
        their value `<td>`s (`:346`, `:347`) in the matching gate;
  - [x] leave `Tarifa` (`:340-344`), `Activa` (`:345`), `Precio` (`:348-356`), the
        `activa` toggle and the price panel (`:289-329`) untouched.

### 5.4 REFACTOR — opcionales

- [x] Re-run the full plugin suite; PR2 behavior tests green.

---

## Phase 6 — PR2: Verification and negative audit

### 6.1 Full plugin suite

- [x] `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` —
      all green.
- [x] Confirm the ownership tests pass **unchanged**:
      `plugins/catalogo_core/tests/ArticuloTarifaPrecioOwnershipTest.php`,
      `plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php`,
      `plugins/catalogo_core/tests/ArticuloDetalleCanonicoOwnershipTest.php`,
      `plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php`.

### 6.2 Root regression

- [x] `ddev exec php vendor/bin/phpunit` — root suites (including the `Plugins`
      discovery) green; no `catalogo_core` regression.

### 6.3 Negative audit — do NOT touch was honored

- [x] Grep production `plugins/catalogo_core` (comments stripped) for `'tarifario'`
      literal / `plugins/tarifario/` / `@tarifario/` → zero hits in the accessor
      and the four seams.
- [x] Confirm no watch-list file changed:
      `plugins/catalogo_core/View/tarif_tarifas.html.twig`,
      `plugins/catalogo_core/View/tarif_familias.html.twig`,
      `plugins/catalogo_core/View/Hooks/partials/opcional_precios_rows.html.twig`,
      `plugins/catalogo_core/View/Macro/TarifarioComponents.html.twig`, the
      `b_codtarifa` selectors, `Controller/VentasArticulo.php` (all read-only
      reference / must remain byte-identical).
- [x] Confirm no core `openspec/changes/gate-tarifario-surfaces-by-plugin-activation/`
      entry exists.

---

## Do NOT touch (explicit out-of-scope)

- [x] Multitarifa: the `b_codtarifa` selectors
      (`View/ventas_articulos.html.twig:83-92`,
      `View/ventas_opcionales.html.twig:319-334`), the per-tarifa
      `precio`/`Activo` pane and columns, the create/export modals
      (`View/ventas_opcionales.html.twig:117-157`, `:203-254`),
      `controller/tarif_tarifas.php`, `controller/tarif_familias.php`,
      `View/tarif_tarifas.html.twig`, `View/tarif_familias.html.twig` (all
      read-only — KEEP).
- [x] Opcionales domain beyond the two visibility headers/cells/labels: the
      master `activa` toggle, the selected-tarifa value cell, the price panel,
      `opcional_precios_rows.html.twig:50-51`
      (`catalogo_opcional_precio.en_catalogo` per-lista flag — KEEP).
- [x] `plugins/clientes_core/` (read-only — not in scope).
- [x] Endpoint 404s / empty-fragment guards for `tarif_tarifas`, `tarif_familias`,
      `tarif_opcional_edit`, `tarif_tab_precios`, `tarif_opcional_tab` — DROPPED.
- [x] `TarifarioGate` service — DROPPED; do not create it.
- [x] `Init::init()` `fs_pages.show_on_menu` sync / constructor `$shmenu` gate —
      DROPPED.
- [x] `View/ventas_articulo.html.twig:214` `spublico` (`articulo.publico`
      legacy field — unrelated) and `Controller/VentasArticulo.php` — untouched.
- [x] DB-column lookalikes `tarif_articulo_precio.en_catalogo/en_tarifa`,
      `tarif_tarifa_articulo`, `tarif_tarifa_familia.en_catalogo/en_tarifa` —
      different concept, KEEP.
- [x] No `'tarifario'` literal or `plugins/tarifario/` path in production code.
- [x] No schema change, no `DROP`, no `DELETE`, no destructive migration; no
      data row is written, altered or deleted by this change.

---

## Success criteria (mirror of proposal/design)

- [x] With the owner inactive, `Tarifa`/`Catálogo` headers and cells do not render
      in `ventas_articulos`, `ventas_opcionales`, `tarif_opcional_edit` or
      `articulo_precios_rows`; the `en_catalogo`/`en_tarifa` checkboxes are absent
      from the partial while price/`activo` remain.
- [x] With the owner inactive, multitarifa surfaces are unchanged.
- [x] With the owner active, all prior behavior is unchanged.
- [x] The gate is uniform in both `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` modes
      (VCG-06).
- [x] The empty-state row stays aligned when the visibility columns hide.
- [x] No data row is deleted or altered; `git revert` cannot lose data.
- [x] Ownership tests pass unchanged; no plugin-name literal in production code.
- [x] No core `openspec/` entry for this change.
