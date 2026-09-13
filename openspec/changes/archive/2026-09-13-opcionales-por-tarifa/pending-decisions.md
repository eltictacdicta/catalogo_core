# Pre-Proposal Handoff: opcionales-por-tarifa

Status: **CONFIRMED** (2026-09-11). All 9 product decisions resolved.
Source: `exploration.md` §9.
Store: `openspec` — plugin-local, `catalogo_core`.

Mode: Option A — full per-`(tarifa, opcional)` master `tarif_tarifa_opcional`,
mirroring `tarif_tarifa_familia`.

| # | Decision | Confirmed answer |
|---|---|---|
| 1 | Activation source of truth | **Master authoritative.** `tarif_tarifa_opcional.activa` is the only source of truth; price-row existence no longer defines activation. |
| 2 | `tarif_opcional_ext.en_catalogo/en_tarifa` | **Kept as fallback/default.** Retained columns, no longer authoritative; `ref_sap` stays global. |
| 3 | `en_catalogo` precedence | **Master wins for catalog/export.** Price-level `en_catalogo` stays as per-lista price inclusion. |
| 4 | Master `orden` | **Yes — Option A.** Master carries `orden` = default order; family/article `orden` = scoped override. |
| 5 | Seeding strategy | **Install seed (default tarifa) + lazy inherit + `copy_from_tarifa` on tarifa creation.** Familias-style. |
| 6 | `tarif_catalogo_view` export (tarifario) | **Repoint to per-tarifa flags** — hybrid touch to `plugins/tarifario/`. |
| 7 | Existing-data migration | **Additive/lazy; no physical backfill.** Missing row resolves by default/inheritance. |
| 8 | List "Estado" column | **Per-tarifa `activa`.** |
| 9 | Configurador scope | **Unchanged** — keeps managing family/article-level `activo` only. |

## Handoff contract for `sdd-propose`

- Change name: `opcionales-por-tarifa`
- Plugin root: `plugins/catalogo_core/openspec/`
- Proposal path: `plugins/catalogo_core/openspec/changes/opcionales-por-tarifa/proposal.md`
- References: this file + `exploration.md`
- Artifacts language: English
- Plugin config: `strict_tdd: true`; runner `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- Hybrid scope note: decision 6 touches `plugins/tarifario/controller/tarif_catalogo_view.php`; SDD ownership stays in `catalogo_core` (main beneficiary).
