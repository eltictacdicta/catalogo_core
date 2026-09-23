# Pre-proposal — caracteristicas-producto

> Schema: `gentle-ai.sdd-preproposal/v1`
> Revision: 3
> Change: `caracteristicas-producto`
> SDD root: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`)
> Core `openspec/`: reference only — no entries.
> Supersedes: `mover-tarifa-catalogo-opcionales-a-tarifario` (never applied).

## Exploration

- Outcome: `done`
- Reference: `plugins/catalogo_core/openspec/changes/caracteristicas-producto/explore.md`
- Engram: `sdd/caracteristicas-producto/explore` (id 808)

## Research

- Requested classes: `documentation`
- Admission: GRANTED — `gentle-ai.sdd-research-capability/v1`, `class=documentation`, `provider=context7`, `library=/prestashop/docs`, `authorized_by=user`
- Outcome: `done` (revision 2)
- Reference: `plugins/catalogo_core/openspec/changes/caracteristicas-producto/research.md`
- Engram: `sdd/caracteristicas-producto/research` (id 809)

## Product decisions

### Confirmed by the user (2026-09-14)

| # | Decision | Confirmed choice |
|---|---|---|
| D3 | Supersede legacy `en_catalogo`/`en_tarifa` | **REPLACE NOW** — backfill legacy article + family flags into feature values and rewrite their consumers. Locked specs (ALC-02, export spec, precedence) must be updated. |
| D4 | Value model | **PREDEFINED VALUE CATALOG** (PrestaShop-style `feature_value`) plus an optional custom value per product. |
| D5 | Effective-value precedence | **Product > family/subfamily > global**; within a scope: requested tarifa row > `DEF` row > default/null. |
| D12 | Opcional-level `en_catalogo`/`en_tarifa` | **REMOVE from opcionales.** An opcional's catalog/tarifa visibility is **derived from its parent product**: if the product is in the tarifa/catalog, its opcionales are too. Drops those columns from `tarif_opcional_ext` / `tarif_tarifa_opcional` and updates the `ventas_opcionales` columns/consumers accordingly. |

### Proposal-settled (recommendations carried)

| # | Decision | Recommendation |
|---|---|---|
| D1 | Ownership of per-tarifa value store + clone step | catalogo_core (clone orchestrator is `controller/tarif_tarifas.php::heredar_estructura()`) |
| D2 | Value storage shape | Definition + predefined value catalog + scope value tables keyed by `(codtarifa, scope_ref)` |
| D6 | Deletion of plugin-registered defaults | Non-deletable, deactivatable (`origen`) |
| D7 | `listable` rendering | Dynamic columns after per-tarifa columns; bool → Sí/No |
| D8 | Import/export contract | Additive dynamic columns; base headers byte-identical |
| D9 | Panel slug + menu | `ventas_caracteristicas`, menu `catalogo` |
| D10 | Type support scope | `bool` + `string` only |
| D11 | Referential behaviour | FK CASCADE on `referencia`/`codfamilia` value rows |

## Default features

- `catalogo_core` registers `medidas` (type `string`).
- `tarifario` registers `en_catalogo` and `en_tarifa` (type `bool`).

## Readiness

`proposal_ready`: **true** — research `done`, evidence references valid, all product decisions confirmed, OpenSpec store ready.
