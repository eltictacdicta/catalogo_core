# SUPERSEDED artifacts

The maintainer corrected the scope after the first proposal pass. The broad
"gate every tarifario-owned surface" framing was WRONG.

## Narrow scope (authoritative)

Gate ONLY the two visibility characteristics `en_catalogo` / `en_tarifa`
(registered by `tarifario` with `origen='tarifario'`) across four view families.
Everything else stays. Authoritative source: `rescope-explore.md`.

## Superseded artifacts

| Artifact | Status | Reason |
|----------|--------|--------|
| `proposal.md` (prior broad version) | **SUPERSEDED / OVERWRITTEN** | Gated all tarifario-owned surfaces, endpoints and menu — out of scope after maintainer correction. |
| `specs/tarifario-surface-gating/spec.md` (TSG-01..07) | **SUPERSEDED** | TSG-01 used a bare `'tarifario'` gate; TSG-03 endpoints, TSG-04 menu and TSG-07 clientes_core are OUT. Replaced by the narrower `visibility-characteristic-gating` domain. |
| `explore.md` | **SUPERSEDED (kept for history, do not overwrite)** | Broad-scope exploration; `rescope-explore.md` is authoritative. |

## Superseded delta specs (dropped — out of scope)

| Delta | Reason dropped |
|-------|----------------|
| `articulo-detalle-canonico` | ART-09/10/11 are multitarifa (selector, per-tarifa read path, no-active-tarifas). |
| `articulo-tarifa-tab-management` | ATT-02 endpoint and ATT-04 pane are multitarifa; the endpoint stays. |
| `opcionales-tarifa-selector` | Selector/panel are multitarifa. |
| `familias-tarifa-management` | Menu + multitarifa families, explicitly out of scope. |
| `catalogo-render-hooks` | Hook context contract and opcional hooks are multitarifa; the visibility fields are endpoint partials, not hook templates. |

## Kept from the prior pass (narrowed)

- `articulo-lista-canonica` — KEEP-NARROWED to `ALC-02` (visibility columns only;
  `ALC-01` dropped, `b_codtarifa` is multitarifa).
- `opcionales-management` — KEEP-NARROWED to `OUM-03` (visibility indicators
  only; `activa`/price stay).
- `caracteristicas-producto` — reference only (CAR-19 / CAR-20), no delta.

## Explicitly OUT of scope

Multitarifa (`tarif_tarifas`, `tarif_familias`, `b_codtarifa`, per-tarifa
prices pane, `tarif_tab_precios`, `activa`), opcionales pages as a whole,
`clientes_core` `codtarifa`, menu hiding, endpoint 404s, `TarifarioGate`
service, `Init` fs_pages sync.
