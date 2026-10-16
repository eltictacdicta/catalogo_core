# Archive Report: multitarifa

**Change**: multitarifa
**Plugin**: `plugins/catalogo_core/`
**Archived**: 2026-09-04
**Status**: ✅ Complete — SDD cycle closed (verify PASS with 1 non-attributable warning)
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-09-04-multitarifa/`

## Architectural Note: OpenSpec Ownership

This change was archived under the **plugin openspec** at
`plugins/catalogo_core/openspec/changes/archive/`, NOT under the core openspec
at `openspec/changes/archive/`. Verified: the core `openspec/changes/` tree has
**no** `multitarifa` entry. The change is 100% internal to the plugin and
respects the "OpenSpec per plugin" convention documented in `AGENTS.md`,
`plugins/catalogo_core/openspec/config.yaml` (`ownership: plugin-local`), and
the `fsframework-plugin-sdd` skill.

## Executive Summary

Closed the multi-tariff pricing SDD: named price lists (`catalogo_lista_precio`)
with a single enforced default, per-article per-list prices
(`catalogo_articulo_precio` + XML schema with composite PK and CASCADE FKs),
default-list resolution with pvp fallback (`Services/CatalogoPriceResolver`),
admin CRUD UI for price lists, a per-article price editor with permission-event
dispatch, a two-phase preview/apply batch price update with filters and
half-up 2-decimal rounding, and a namespaced options store
(`Services/CatalogoOptions`, keys `catalogo_core.*` over `$GLOBALS['config2']`)
that absorbs the legacy `catalogo_excel_roles` key with a read-only shim and
introduces the multi-tariff and price-group-master flags.

Net result: el catálogo soporta múltiples listas de precios con fallback al
`pvp` por defecto, actualizaciones masivas auditables con preview, y las
opciones del plugin viven en un store único namespaced en lugar de claves
sueltas.

## Specs Synced

| Domain | Action | Details |
|---|---|---|
| `multi-tariff-pricing` | **Created** | Full spec copied from delta (R-MT-001..005: named lists, per-article prices, fallback, admin CRUD, per-article editor) |
| `batch-price-update` | **Created** | Full spec copied from delta (R-BU-001..005: preview/apply, filters, half-up rounding, CSRF gate, resumen) |
| `catalog-options-store` | **Created** | Full spec copied from delta (R-CO-001..005: namespaced store, legacy absorb, new flags, opciones_catalogo page, fail-closed defaults) |
| `role-based-price-access` | **Created** | Full spec copied from delta (role-based price access requirements) |
| `articulos-excel-access-settings` | **Updated** | MODIFIED: R-CEXC-002 block replaced in full (settings now read via `Services/CatalogoOptions` with legacy `catalogo_excel_roles` read-shim fallback; page context changed from `catalogo_excel_settings` to `opciones_catalogo`). REMOVED: "Página de ajustes solo-admin" requirement (Reason/Migration present in delta: replaced by `opciones_catalogo`, regressions covered by retargeted `CatalogoExcelSettingsPageTest`). R-CEXC-006 preserved untouched. |

## Verification Snapshot

- verify-report.md: **PASS** with 1 warning (S-1: 25 suite warnings / 4 skipped
  pre-existing, not attributable to this change; flagged for inventory in the
  next change's tasks).
- tasks.md: 21/21 tasks checked. No unchecked implementation tasks; no
  reconciliation was needed.
- Host suite at verify time: 409 tests, 893 assertions, 2 failures (resolved
  during verify) — final state green per report.

## Archive Contents

- proposal.md ✅
- design.md ✅ (decisions D1–D7)
- specs/ ✅ (5 deltas)
- tasks.md ✅ (21/21 complete)
- verify-report.md ✅

## Outstanding / Follow-ups

- S-1 (carried): inventory the 25 suite warnings / 4 skipped tests in the next
  change's tasks to avoid silent growth. Not a defect of this change.
- SDD artifacts were previously untracked; included in this archive's docs
  commit for audit-trail completeness (consistent with the plugin's practice
  of committing openspec artifacts).

## Step 9 Check (no core leakage)

`openspec/changes/` (repo root) contains no `multitarifa` entry — plugin-local
ownership confirmed.
