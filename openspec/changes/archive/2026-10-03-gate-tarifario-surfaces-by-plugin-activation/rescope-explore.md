# Re-scope Exploration: gate visibility characteristics by plugin activation

- Change (current name): `gate-tarifario-surfaces-by-plugin-activation`
- Status: RE-SCOPE. This file supersedes the broad scope in `explore.md` /
  `proposal.md`. Do **not** overwrite `explore.md`.
- Scope verdict: the real target is the **two visibility characteristics**
  `en_catalogo` / `en_tarifa` (registered by `tarifario` via
  `plugins/tarifario/Init.php:122-129`, `origen='tarifario'`). Everything else
  the previous pass tried to gate is OUT OF SCOPE.
- Recommended rename (do **not** rename yet):
  `gate-visibility-characteristics-by-plugin-activation`.

---

## 0. The heart of the matter (verified)

`en_catalogo` / `en_tarifa` are **feature definitions**, not the multitarifa DB
columns of the same name. They are registered only by `tarifario`:

- `plugins/tarifario/Init.php:122-129` → `registerDefault('en_catalogo', 'En Catálogo', 'bool', 'tarifario', ...)` and `registerDefault('en_tarifa', 'En Tarifa', 'bool', 'tarifario', ...)`.
- catalogo_core defaults only `medidas` (`Services/CaracteristicaRegistry.php`).

The **data path already self-gates** through CAR-20 ownership:

- `Services/CaracteristicaResolver::definitions()` (`:82-102`) drops any
  definition whose non-empty `origen` is not in `Plugins::enabled()`
  (`CaracteristicaOwnership::is_active()`, `:49-57`).
- `Services/CaracteristicaValorBatchReader::definition_rows()` (`:121-141`)
  applies the same filter in one PHP pass; `columns()` (`:98-111`) inherits it.
- `CaracteristicaResolver::resolve()` / `resolve_bool()` return `null` / `false`
  for a dropped definition; the opcional D12 derivation
  (`resolve_opcionales_visibility()`) likewise yields `false`.

**So the VALUES already degrade.** What the maintainer sees (columns still
rendered showing ✗ / "No") is the **hardcoded VIEW surface** that renders the
label/cell unconditionally instead of consulting the resolver activity.

Important distinction (do NOT confuse the two): the DB columns named
`en_catalogo` / `en_tarifa` on `tarif_articulo_precio`, `tarif_tarifa_articulo`,
`tarif_tarifa_familia`, `catalogo_opcional_precio` belong to the **multitarifa /
opcionales data model** and are OUT OF SCOPE. `CaracteristicaColumnDropMigration`
explicitly keeps `catalogo_opcional_precio.en_catalogo` as the per-lista price
inclusion flag (`:206-207`) and `tarif_tarifa_*` columns as the tariff
membership flags.

---

## 1. Verified hardcoded occurrences

Legend: (a) header/label · (b) cell/value · (c) filter select · (d) create/import
input · (e) other. `AUTO` = value already self-gates but the surface still
renders; `LEAK` = hardcoded surface that still renders while tarifario is off.

### 1.1 Article list — `View/ventas_articulos.html.twig`

| Line(s) | Kind | What | Verdict |
|---|---|---|---|
| `:146` | (a) | `<th ...>Tarifa</th>` — the `en_tarifa` characteristic column header | **LEAK** |
| `:147` | (a) | `<th ...>Catálogo</th>` — the `en_catalogo` characteristic column header | **LEAK** |
| `:191-197` | (b) | cell `fsc.articulo_en_tarifa_flag(...)` → ok/remove glyph | **LEAK** (renders ✗) |
| `:198-204` | (b) | cell `fsc.articulo_en_catalogo(...)` → ok/remove glyph | **LEAK** (renders ✗) |
| `:148-150` | (a) | `{% for def in fsc.listable_caracteristicas() %}` feature columns | AUTO (CAR-16/20; not the leak) |
| `:177-183` | (b) | `get_precio_articulo_tarifa` price column | OUT — multitarifa |
| `:184-190` | (b) | `articulo_activo_tarifa` "Activo" column | OUT — multitarifa |
| `:83-92` | (c) | `b_codtarifa` filter select | OUT — multitarifa selector, **not** the `en_tarifa` characteristic filter |
| `:141`/`:176` | (e) | `{% if fsc.tarifa_seleccionada %}` guard wraps all 4 per-tarifa columns | multitarifa guard; visibility columns are nested inside but not characteristic-gated |
| `:232` | (e) | empty-state `colspan` hardcodes `4 + listable` | cosmetic adjustment if visibility columns hide |

The 4-line header/cell pair at `:146-147` + `:191-204` is the confirmed
article-list leak the maintainer reported.

### 1.2 Article detail — `View/ventas_articulo.html.twig`

No direct characteristic input exists in this file. `:214` (`name="spublico"`)
is `articulo.publico`, a legacy product field — NOT `en_catalogo` (OUT). The
tarifa selector `:40-75` and the per-tarifa pane `:238-257` are multitarifa
(OUT). The visibility controls are served by the endpoint partial below.

`View/Hooks/partials/articulo_precios_rows.html.twig` (served by
`controller/tarif_tab_precios.php:134-165`):

| Line(s) | Kind | What | Verdict |
|---|---|---|---|
| `:41-49` | (d) | `en_tarifa` checkbox + label, value from `visibilidad[codtarifa].en_tarifa` | **LEAK** (renders unchecked while off) |
| `:50-58` | (d) | `en_catalogo` checkbox + label, value from `visibilidad[codtarifa].en_catalogo` | **LEAK** (renders unchecked while off) |
| `:30-40` | (d) | `activo` checkbox | OUT — multitarifa |
| `:16-29` | (d) | price input | OUT — multitarifa |

`tarif_tab_precios.php:152-155` builds `visibilidad` with
`$resolver->resolve_bool(...)`, which returns `false` when the definition is
inactive — value AUTO, control **LEAK**.

### 1.3 Opcionales list — `View/ventas_opcionales.html.twig`

| Line(s) | Kind | What | Verdict |
|---|---|---|---|
| `:392-397` | (a) | `<th>Tarifa</th>` (with selected-tarifa sublabel) | **LEAK** |
| `:398` | (a) | `<th>Catálogo</th>` | **LEAK** |
| `:467-475` | (b) | cell `fsc.opcional_en_tarifa_flag(value.id)` → Sí/No | **LEAK** (renders "No") |
| `:476-484` | (b) | cell `fsc.opcional_en_catalogo_tarifa(value.id)` → Sí/No | **LEAK** (renders "No") |
| `:385-390` + `:439-448` | (b) | "Precio" header/cell | OUT — multitarifa |
| `:391` + `:449-466` | (b) | "Estado" (`opcional_activo_en_tarifa`, master `activa`) | OUT — multitarifa |
| `:319-334` | (c) | `b_codtarifa` filter select | OUT — multitarifa |
| `:117-157` | (d) | create-modal "Precios por tarifa" (`precio_tarifa_*`) | OUT — multitarifa |
| `:203-254` | (e) | export modal | OUT — no characteristic rendered |
| `:463` | (e) | `toggle_button_group` with `show_catalogo:false, show_en_tarifa:false` | OUT — only `activa` |
| `:404` | (e) | row warning class `opcional_activo_en_tarifa` | OUT — multitarifa |

### 1.4 Opcional detail (moved page) — `View/tarif_opcional_edit.html.twig`

| Line(s) | Kind | What | Verdict |
|---|---|---|---|
| `:294-307` | (a) | read-only labels `Catálogo:` / `En tarifa:` + Sí/No (`opcional_en_catalogo_tarifa` / `opcional_en_tarifa_flag`) | **LEAK** |
| `:336` | (a) | summary `<th>Catálogo</th><th>En tarifa</th>` | **LEAK** |
| `:346` | (b) | `<td>{% if datos.en_catalogo %}...` | **LEAK** (renders ✗) |
| `:347` | (b) | `<td>{% if datos.en_tarifa %}...` | **LEAK** (renders ✗) |
| `:277-328` | (d) | editable panel: only `activa` toggle + price | OUT — multitarifa |
| `:345` | (b) | `<td>datos.activa</td>` | OUT — multitarifa |

`controller/tarif_opcional_edit.php:355-365` builds `precios_tarifas` with
`resolve_opcional_visibility(...)` — value AUTO, labels **LEAK**.

The comment at `:289-292` already documents that the visibility is derived
read-only (no input targets `en_catalogo`/`en_tarifa`); only display remains.

### 1.5 Macro — `View/Macro/TarifarioComponents.html.twig`

`toggle_button_group` (`:336-362`) renders `en_catalogo` (`:343-348`) and
`en_tarifa` (`:349-354`) buttons. Its **only** `show_*: true` usages are
multitarifa family surfaces:

- `View/partials/familias/edit_modal.html.twig:71`
- `View/tarif_familias.html.twig:279`

Both write the multitarifa `tarif_tarifa_familia.en_catalogo/en_tarifa` DB
columns. All characteristic-related usages pass `show_catalogo:false,
show_en_tarifa:false` (`ventas_opcionales:463`, `tarif_opcional_edit:293`).
→ **Macro needs NO change** under the narrow scope.

### 1.6 Traits — confirmed helper surface

`extras/VentasArticulosListTrait.php`:

- `:48` `private const VISIBILITY_CODIGOS = ['en_catalogo', 'en_tarifa'];` — the canonical codigo list (shared vocabulary; keep).
- `:299-312` `load_articulo_tarifa_columns()` batches both visibility features; map is empty while off → value AUTO.
- `:337-345` `articulo_en_tarifa_flag()` / `articulo_en_catalogo()` — public helpers that always exist and return `false` while off; they are what the leaky view calls. **These are the gate hinge.**
- `:315-325` / `:352-361` legacy fallback reads `tarif_articulo_precio.en_catalogo/en_tarifa` DB columns when `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH=false` (see Q3).

`extras/VentasOpcionalesListTrait.php`:

- `:392-420` `load_opcionales_visibility_cache()` iterates `CaracteristicaResolver::VISIBILITY_CODIGOS`; values AUTO.
- `:475` `precios_cache[...]['en_catalogo']` is `catalogo_opcional_precio.en_catalogo` (per-lista inclusion; OUT — not rendered in this list).
- `:538-552` `opcional_en_catalogo_tarifa()` / `opcional_en_tarifa_flag()` — the leaky view calls. **Gate hinge.**

### 1.7 Write path (already auto-gates)

`Controller/VentasArticulo.php:614-648` `persist_articulo_visibility()`
delegates to `CaracteristicaValorStore`, which resolves `definitions()` and
ignores inert definitions. No change required for the write path.

---

## 2. Recommended minimal gate

**Signal: "the characteristic definition is active for the current plugin set."**
Use the resolver that already implements CAR-20 rather than a new
plugin-name gate. There is no need for a `TarifarioGate` service, no
`Plugins::isEnabled('tarifario')` literal, no `Init` sync, no endpoints.

### Smallest change that removes the hardcoding

Add one **resolver accessor** and expose it to the views through the existing
controller seams:

```php
// Services/CaracteristicaResolver.php
/**
 * Active visibility codigos for the current enabled-plugin set (CAR-20).
 * Empty while tarifario is inactive. Reuses definitions(); no new query.
 *
 * @return list<string>
 */
public function active_visibility_codigos(): array
{
    return array_values(array_intersect(
        self::VISIBILITY_CODIGOS,
        array_keys($this->definitions())
    ));
}

public function is_visibility_active(string $codigo): bool
{
    return in_array($codigo, $this->active_visibility_codigos(), true);
}
```

Expose a view-facing convenience (one implementation, reused):

- `VentasArticulosListTrait`: add a `caracteristica_resolver()` seam + `public function visibilidad_activa(string $codigo): bool` delegating to the resolver.
- `VentasOpcionalesListTrait`: same method using the existing `opcional_visibility_resolver()` seam (`:180`).
- `Controller/VentasArticulo.php`, `controller/tarif_tab_precios.php`, `controller/tarif_opcional_edit.php`: same public method via their existing `caracteristica_resolver()` seams.

Then replace each hardcoded render with a gate:

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

Why this is the smallest correct fix:

- It removes the **activity hardcoding**; the codigo strings stay (canonical
  codigos, not a plugin path/namespace → ownership-safe by construction).
- It reuses `definitions()` (CAR-20), so it is fail-closed for free and needs
  no new plugin-name literal.
- It is value-shape compatible: the same helpers keep returning the value; the
  view decides whether to render at all.
- Do **not** suppress the price/`activo` columns, the `b_codtarifa` filter, the
  per-tarifa price inputs, or the opcional master `activa` — those are
  multitarifa.

Rejected alternatives:

- A `TarifarioGate` service keyed on the bare string `'tarifario'` — re-introduces
  the plugin name where the resolver already answers the question; broader test
  blast radius.
- A Twig global `visibilidad_activa` — every affected template already receives
  `fsc`; a global buys nothing and enlarges test surface (same conclusion as the
  broad pass).
- Gating the entire per-tarifa pane — wrong; the pane serves multitarifa
  price/`activo` and MUST stay.

---

## 3. Auto-gated vs hardcoded leak

| Surface | Value resolution | Surface render | Action |
|---|---|---|---|
| `listable_caracteristicas()` feature columns (not the 2 visibility) | AUTO | AUTO | none |
| Article list `en_tarifa`/`en_catalogo` headers+cells | AUTO (`articulo_en_*`) | **hardcoded LEAK** | gate |
| Article per-tarifa `en_tarifa`/`en_catalogo` checkboxes | AUTO (`resolve_bool`) | **hardcoded LEAK** | gate controls only |
| Opcionales list `Tarifa`/`Catálogo` columns | AUTO (`resolve_opcionales_visibility`) | **hardcoded LEAK** | gate |
| Opcional detail read-only labels + summary columns | AUTO (`resolve_opcional_visibility`) | **hardcoded LEAK** | gate |
| Excel import/export characteristic fields | AUTO (`importable_definitions`) | AUTO | none |
| Article save write path | AUTO (`CaracteristicaValorStore`) | n/a | none |

Confirmed: the leak is exclusively the four hardcoded view families
(`ventas_articulos`, `ventas_opcionales`, `tarif_opcional_edit`,
`articulo_precios_rows`), never the feature registry.

---

## 4. Deltas: KEEP vs DROP

Existing change dir: `specs/{domain}/spec.md`. Decision under the narrow scope:

| Delta domain | Verdict | Reason |
|---|---|---|
| `tarifario-surface-gating` (TSG-01..07) | **SUPERSEDE / REWRITE** → `visibility-characteristic-gating` | TSG-01 uses a bare `'tarifario'` gate; narrow scope uses the resolver. TSG-03 endpoints, TSG-04 menu, TSG-07 clientes_core are OUT. Keep only: gate source (active visibility characteristics), article list columns, article per-tarifa visibility controls, opcionales list/detail labels+columns, ownership-safety, non-destructive. |
| `articulo-lista-canonica` (ALC) | **KEEP-NARROWED** | Drop `ALC-01` scenario "Tarifa filter is gated by plugin activation" (b_codtarifa is multitarifa). Keep `ALC-02` but narrow "Per-tarifa columns absent while inactive" to the **two visibility columns only** (price/`Activo` stay). |
| `articulo-detalle-canonico` (ART) | **DROP** | ART-09 selector, ART-10 per-tarifa read path, ART-11 no-active-tarifas are multitarifa. The article-edit visibility controls are covered by the partial requirement in the new domain. |
| `articulo-tarifa-tab-management` (ATT) | **DROP** | ATT-02 endpoint and ATT-04 pane are multitarifa; the endpoint stays. Visibility controls inside the fragment are covered by the new domain. |
| `opcionales-management` (OUM) | **KEEP-NARROWED** | Keep only `OUM-03` with a narrowed scenario: the two visibility indicators/columns hide while the definition is inactive, while `activa`/price stay. Drop `OUM-02` (filters), `OUM-05` (creation prices), `OUM-07` (export), `OUM-10` (tab), `OUM-11` (page blocking) — all multitarifa. |
| `opcionales-tarifa-selector` (OTS) | **DROP** | Selector/panel are multitarifa. |
| `familias-tarifa-management` | **DROP** | Menu + multitarifa families, explicitly out of scope. |
| `catalogo-render-hooks` | **DROP** | Hook context contract and opcional hooks are multitarifa; the visibility fields are endpoint partials, not hook templates. |
| `caracteristicas-producto` | **NO DELTA** (reference only) | CAR-19/CAR-20 already define owner-plugin activity. The new domain references them; duplicating the rule here would drift. |

Minimal delta set under the narrow scope: **NEW `visibility-characteristic-gating`**
+ **MODIFIED `articulo-lista-canonica` (ALC-02)** +
**MODIFIED `opcionales-management` (OUM-03)**. The article-edit partial gate is
listed as a surface requirement inside the new domain.

Rename warranted: yes — `tarifario-surface-gating` and the change name both
overstate the scope. Do not rename in this pass.

---

## 5. Blast radius + Review Workload Forecast (narrow)

### Production files

| File | Change | Est. lines |
|---|---|---|
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

### Tests

| Area | Est. lines |
|---|---|
| `tests/CaracteristicaResolverTest.php` — new accessor unit tests (active/inactive/fail-closed) | ~60 |
| View/controller behavior updates that must seed `$GLOBALS['plugins'] = ['tarifario']` | ~120-160 |
| **Subtotal tests** | **~180-220** |

### Specs/docs

| Area | Est. lines |
|---|---|
| New domain + 2 narrowed MODIFIED deltas | ~80-100 |

### Total

**~370-430 changed lines.** Borderline at the 400-line budget.

- `Decision needed before apply: No` (no product decision remains; Q1/Q2 below are confirmations).
- `Chained PRs recommended: Yes` (borderline; 2 slices).
- `400-line budget risk: Medium`.

Suggested chain:

1. Resolver accessor + `visibilidad_activa()` seams + unit tests (autonomous, no UI behavior change yet).
2. View gates + the behavior-test seeding updates (the slice that first makes the tests fail-closed).

### Tests affected (must seed `['tarifario']` / assert the gated-off state)

- `tests/Controller/VentasArticulosListCaracteristicasTest.php` (`:368`, `:383` assert the 4 per-tarifa columns).
- `tests/Controller/VentasArticulosListAbsorptionTest.php` (`articulo_en_*` helpers).
- `tests/CaracteristicaReadThroughTest.php`.
- `tests/CatalogoOpcionalesUnifiedControllerTest.php`.
- `tests/Controller/VentasOpcionalesControllerMasterStateTest.php`.
- `tests/TarifOpcionalesControllerMasterStateTest.php` (`opcional_en_*`).
- `tests/Integration/CatalogoArticuloHookOwnershipTest.php` (`:387-410`, partial controls).
- `tests/TarifOpcionalEditCaracteristicaTest.php` (`:165-168`, view strings).

Ownership tests that MUST keep passing unchanged (no change expected):
`tests/ArticuloTarifaPrecioOwnershipTest.php`,
`tests/ArticuloListaCanonicaOwnershipTest.php` (`:214`),
`tests/ArticuloDetalleCanonicoOwnershipTest.php`,
`tests/OpcionalDomainModelOwnershipTest.php`.

Excel tests are NOT affected as UI: `importable_definitions()` already
auto-gates (`tests/Services/ArticuloExcelCaracteristicaTest.php:253-266`).

Seeding pattern already exists: `tests/CaracteristicaOwnershipTest.php:158,181,367`
and `tests/Services/ArticuloExcelCaracteristicaTest.php:307` set
`$GLOBALS['plugins'] = ['tarifario']`; restore in `tearDown()`.

---

## 6. Open questions / risks

1. **`b_codtarifa` filter** — verified it is the multitarifa tariff selector, not
   an `en_tarifa` characteristic filter. Recommendation: KEEP it unchanged.
   Confirm.
2. **Per-tarifa pane** — recommendation: keep `Precio`/`Activo`/price inputs and
   the pane itself; hide only the `en_tarifa`/`en_catalogo` controls. Confirm.
3. **Legacy read branch** (`FS_CATALOGO_CARACTERISTICAS_READ_THROUGH=false`) —
   `articulo_visibility_bool()` (`:352-361`) then reads the
   `tarif_articulo_precio.en_catalogo/en_tarifa` DB columns, which are
   multitarifa, not the characteristic. Should the visibility columns still hide
   in this emergency-opt-out mode? The maintainer's intent ("gate the two
   visibility characteristics") implies hide always; but then the legacy columns
   (a different concept) are hidden too. Confirm.
4. **DB-column lookalikes that stay** — `catalogo_opcional_precio.en_catalogo`
   (`opcional_precios_rows.html.twig:50-51`) and
   `tarif_tarifa_familia.en_catalogo/en_tarifa` (`Macro` used by familias) are
   data-model flags, not characteristics. Recommendation: KEEP. Confirm.
5. **Empty-state colspan** — `ventas_articulos.html.twig:232` hardcodes the `4`
   per-tarifa column count; it must be computed from the active visibility set
   or the empty row will misalign. Cosmetic.
6. **Helper placement** — resolver method + controller pass-through (recommended)
   vs a Twig global. Confirm no objection to adding a public method to
   `CaracteristicaResolver`.
7. **Rename** — change name and `tarifario-surface-gating` domain are stale for
   the narrow scope. Confirm renaming to `gate-visibility-characteristics-by-plugin-activation`
   in the propose/spec pass.
8. **`ventas_articulo:214` `spublico`** — confirmed `articulo.publico` legacy
   field, unrelated to `en_catalogo`. No action.

### Risks

- **Fail-closed test breakage**: view/behavior tests that render without seeding
  `$GLOBALS['plugins']` will hide the columns. They must seed `['tarifario']` in
  the same change (strict TDD). Bounded to the listed files.
- **Mixed concept regression**: touching the multitarifa `en_catalogo/en_tarifa`
  DB columns or the per-tarifa price/`activo` would be a scope violation. Keep
  the edit surgical per Section 1.
- **Ownership tests**: never write `plugins/tarifario/` or `@tarifario/`; the
  resolver accessor uses only the canonical codigo strings and existing APIs.
- **Legacy-mode ambiguity** (Q3): if unconfirmed, the legacy branch may render
  hidden columns inconsistently with the feature branch.

---

## 7. Ready for proposal

Yes. The narrow scope is verified, the minimal gate is identified, and the
out-of-scope domains are enumerated. `sdd-propose` should rewrite the proposal
to: (a) the two visibility characteristics only; (b) the resolver accessor +
view gates; (c) the KEEP/DROP table from Section 4; (d) the ~370-430 line
forecast and 2-PR chain. The rename decision (Q7) should be made there.
