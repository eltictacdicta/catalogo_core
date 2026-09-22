# Pre-proposal — gestion-idiomas-catalogo

**Change**: `gestion-idiomas-catalogo`
**SDD owner**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`, `strict_tdd: true`) — core `openspec/` is NOT a tracker for this change.
**Secondary plugin**: `plugins/tarifario` (consumer deltas are authored under THIS change, same routing as `caracteristicas-producto`).
**Artifact store**: `openspec`. **Reference only**: core `openspec/` — no entries created there.
**Delivery**: `delivery_strategy: auto-chain`, `review_budget_lines: 800`.
**Inputs**: `explore.md`.

---

## Purpose

This file is the **confirmed pre-proposal handoff**. The product decisions below were
made explicitly by the maintainer after `explore.md`. The `sdd-propose` phase MUST NOT
interview or re-open them; it receives them as settled input.

## CONFIRMED decisions

| ID | Decision | Consequence |
|---|---|---|
| **D1** | **No re-sync of `articulos.descripcion`.** The base column is never backfilled from or mirrored to `articulo_descripciones`; it existed to serve the era when descriptions were Spanish-only. | `set_descripcion_idioma()` loses its mirror branch; `articulos.descripcion` stays frozen as a legacy fallback. |
| **D2** | **Single description / short-description pair driven by a language selector** in `ventas_articulo`. | Removes the duplicated default-language field and the discarded-input branch in `saveMultiidiomaDescriptions()`. The `#multiidioma` anchor is preserved. |
| **D3** | **Migrate every direct consumer of `articulos.descripcion`** to `articulo::get_descripcion_idioma()` (or an explicit-language equivalent). | ~25 call sites across `catalogo_core` and `tarifario`; the base column stops being the read path. |
| **D4** | **`articulo_descripciones` is the single source of truth for ALL languages**, including the default one. | The default language has an ordinary row, exactly like any other language. |
| **D5** | **`catalogo_idiomas.por_defecto` is the only default pointer.** Changing the default is a flag flip that touches no description data. | The default cannot be bound to the base column — doing so would force a data move to change it, which D1 forbids. |
| **D6** | **Read chain**: requested language → configured default → `articulos.descripcion` → `''`. | `get_descripcion_idioma()` must resolve a dynamic default, not a hard-coded `'es'`. |
| **D7** | **Research lane selected: run `sdd-research` before `propose`.** Selection makes completion mandatory. | Proposed lanes: (1) how established systems model multi-language *content* vs UI locale, including the default-language pointer, fallback chain and clearing a translation; (2) multi-language export/import shape in those systems. |
| **D8** | **Excel shape: one column per active language** (locale-suffixed headers). | Produces a delta on the locked `articulos-excel-import-export` spec and requires reworking the `catalogo_core` import wizard (which today has a single `descripcion` field). |
| **D9** | **Language-management UI lives in a `#idiomas` section on `ventas_articulos`.** No new page, no new menu row. | Confirms the existing `catalogo_idioma::url()` seam. Reuses the page's already-loaded `idiomas` and `allow_delete`. |
| **D10** | **No-context consumers resolve to the configured default language.** | Excel export, `CatalogoApiService`, the model SQL joins, `VentasOpcional`, `tarif_historial_precios`, `tarif_actualizar_precios`, `tarif_roles` all read the default language. |
| **D11** | **Article search is language-agnostic.** | `LEFT JOIN articulo_descripciones` + `OR` across languages, following the proven `tarif_articulo::search_tarifario` pattern. **Requires** invalidating the `articulos_search_*` cache on description writes (explore defect **f**), which `articulo_descripcion::save()` currently never does. |
| **D12** | **Language mutations are administrator-only** (`$user->admin`), POST-only and CSRF-validated. | The `ventas_articulos` page itself stays accessible: `#[AdminOnly]` is NOT warranted and must not be applied. |

## Research and readiness (`gentle-ai.sdd-preproposal/v1`)

| Field | Value |
|---|---|
| Preproposal revision | 2 |
| Exploration outcome | `success` — `explore.md` |
| Research request | Lanes 1–3: content-language modelling + default pointer; multi-language export/import shape; language-agnostic search |
| Research classes | `documentation`, `open-web` |
| Research admission | **ADMITTED** — `documentation=[context7]`, `open-web=[webfetch, websearch]` (`opencode.json`, agent `sdd-research`) |
| Research outcome | `partial` — `research.md` revision 2 (supersedes revision 1 `blocked`); 31 mapped sources |
| Evidence references | OpenSpec: `research.md` (revision 2). Engram: `sdd/gestion-idiomas-catalogo/preproposal` |
| Product decisions | `confirmed` (D1–D12) |
| Carried-forward gaps | **G1, G3, G6, G8** — all low impact; to be closed during `spec`, not by another research round |
| Maintainer acceptance | **Explicitly accepted** the `partial` outcome on 2026-09-22, on the record that no confirmed decision depends on the four gaps |
| `proposal_ready` | **true** |

### Evidence-backed refinements the proposal MUST carry

- **D11 is a deliberate deviation.** Every retrieved system (PrestaShop `id_lang` index words, Akeneo working locale, Odoo `COALESCE(lang, en_US)`, Magento store view) scopes search to a language. The proposal and spec MUST state the deviation and justify it from the frozen base column + ~25 legacy readers, or it will be "fixed" later as a bug. It MUST also require `articulos_search_*` invalidation on **every** description write (explore defect **f**).
- **D1 has a real cost.** PrestaShop (`formatFields`) and Odoo (always persists `en_US`) keep a source-language write-through that D1 forbids here. `articulos.descripcion` can only go stale. The spec MUST NOT present it as an equivalent of the default language; it is a deprecated compatibility shim.
- **Clearing rule is evidence-backed (R3).** PrestaShop updates the `_lang` row in place (never deletes it on empty); Odoo drops the language key. Both define "absence for that language", with the fallback supplying the visible text. The "keep the row when only `descripcion_corta` is set" part is a **local** decision and must be marked as such.
- **Default resolution must be total (R1).** PrestaShop resolves an invalid/removed language to `PS_LANG_DEFAULT` and documents it as an invariant — the evidence basis for fixing explore defect **d**.
- **Do not materialise fallbacks (R2).** Sylius creates an empty translation on read; copying that would create empty rows and break the "absence = fallback" invariant.
- **Export headers (R4/D8) are a local convention.** No retrieved system uses locale-suffixed headers normatively; the fork's own precedent (`descripcion_es`/`descripcion_en` in tarifario) does. The spec must define ordering, deactivated-language handling, and that unknown locale columns on import are ignored rather than creating languages.
- **UI locale is untouched (R9).** The plugin's UI i18n (Symfony Translation, es/en) is a separate concern from description content.

## Deferred to later phases

- **Slice boundaries** — decided in `tasks` + the Review Workload Guard, working from the five slices proposed in `explore.md` §8.
- **Export header naming and ordering** — decided in `spec`.
- **FK vs application-level cleanup for orphan descriptions** — decided in `design`.
- **Whether `descripcion_corta` gets an explicit language accessor** — decided in `design`.
