# Proposal: Tarifa selector + htmx 4 / Alpine for opcionales

## Intent

`tarif_opcional_edit` lacks a tarifa selector: users cannot view/edit prices scoped to a tarifa despite existing per-tarifa accessors. The opcionales views still use legacy jQuery/bootbox reloads instead of htmx 4 + Alpine.

## Scope

### In Scope
- Htmx tarifa selector + scoped price/state panel in `tarif_opcional_edit`; compact all-tarifas overview.
- htmx 4 + Alpine CSP in `tarif_opcional_edit`, `tarif_opcional_precios`, `tarif_opcionales`.
- Reuse existing accessors, `tarif.toggle_button_group`; no model changes; strict TDD.

### Out of Scope
- `tarif_configurador_opcionales`, `tpvmod`, `ventas_opcionales`.
- `tarif_tarifa_opcional` master and `tarif_opcional_precio` adapter.
- Migrating controllers to `HtmxCrudController` (locked to `extends fbase_controller`); retiring bulk `guardar_precios_tarifas`.

## Capabilities

### New Capabilities
- `opcionales-tarifa-selector`: selected-tarifa control, scoped price/state panel, htmx/Alpine behavior.

### Modified Capabilities
- `opcionales-tarifa-management`: adds selector/scoped-consumption UI requirements (in-flight delta; reconcile at archive).

## Approach

Keep `fbase_controller`; add `tarif_catalogo_view`-style fragments (`template=false` + echo fragment). Load `htmx.boot()`/`alpine.boot()`; use colon events only (`htmx:after:swap`, `htmx:after:request`). Register logic via `Alpine.data()` with an `alpine:init` guard; nonce inline scripts via `csp_nonce_attr()`. Selector: `hx-get` + `hx-trigger="change"` + `hx-target/select="body"` + `hx-swap` + `hx-push-url`. Validate `codtarifa` vs `$this->tarifas`, else default. Reuse `guardar_precio_tarifa` + `tarif.toggle_button_group`; preserve locked strings.

## Affected Areas

All under `plugins/catalogo_core/`.

| Area | Impact | Description |
|------|--------|-------------|
| `controller/tarif_opcional_edit.php` | Modified | Validate/expose `tarifa_seleccionada`; fragment |
| `controller/tarif_opcional_precios.php` | Modified | Selector/save via htmx |
| `controller/tarif_opcionales.php` | Modified | Filters via `hx-get` + URL push |
| `View/tarif_opcional_edit.html.twig` | Modified | Selector, scoped panel, Alpine flows |
| `View/tarif_opcional_precios.html.twig` | Modified | htmx selector/save |
| `View/tarif_opcionales.html.twig` | Modified | htmx filters |
| `tests/TarifOpcionalEditTarifaSelectorTest.php` | New | Selector + `codtarifa` behavior |
| `tests/TarifOpcionalesHtmxContractTest.php` | New | htmx/Alpine/CSP contracts |

## Risks

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| R1 Broken `fbase_controller` contract | Med | Keep parent; forbid `HtmxCrudController` |
| R2 Locked view strings change | High | Preserve; run master-state test |
| R3 Bulk `guardar_precios_tarifas` removed | Med | Keep method/body |
| R4 htmx v2 event names | Med | Assert colon names; forbid v2 |
| R5 Alpine CSP silent failures | Med | Logic in `Alpine.data()`; `[x-cloak]` |
| R6 Unknown `codtarifa` | Med | Validate vs `$tarifas`; fallback |
| R7 Full reload vs fragment | Med | `hx-select="body"` first |
| R8 jQuery autocomplete on swap | Med | Idempotent re-init on swap |
| R9 bootbox removal side effects | Low | Migrate in-scope flows only |
| R10 Missing nonce | Low | Nonce all new inline scripts |
| R11 Cross-cutting JS double-binding | Med | Idempotent guards; contract tests |
| R12 List POST toggles break | Med | Keep locked names/CSRF |

## Rollback Plan

Additive, no DB migration. `git revert` the view/controller/test edits; bulk path and master model stay untouched. Clear the Twig cache after revert.

## Success Criteria

- [ ] Selector scopes the editable panel to the chosen tarifa; all-tarifas overview retained.
- [ ] Unknown `codtarifa` falls back safely; behavior test green.
- [ ] Three views use htmx 4 + Alpine CSP; colon events only; no v2 names.
- [ ] Test-locked contracts intact; plugin suite green (baseline 455 OK).
- [ ] Model, configurator, `tpvmod`, `ventas_opcionales` untouched.
