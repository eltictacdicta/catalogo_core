# Compatibility review — `plugins/tarifario` (consumer)

- **Change**: `opcional-en-varios-grupos` (plugin-local, `catalogo_core`)
- **Reviewed**: 2026-09-24
- **Method**: grep audit across `plugins/tarifario/{model,controller,View,Services,extras,tools}` (`.php`, `.twig`, `.xml`, `.yaml`) + call-path verification of every catalogo_core method tarifario reaches.

## Verdict

**Compatible.** tarifario needs no signature change, no query rewrite, and no new dependency. One required edit (a bulk-delete cleanup) plus one shared-model method that must keep its semantics.

## Direct references — NONE

A full grep for `id_grupo`, `catalogo_opcional_grupo*`, `etiqueta_grupo`, `get_grupo`, `all_sin_grupo`, `assign_to_grupo` across tarifario returns **zero** opcional-group hits. The only `grupo` matches belong to the unrelated role/user-group subsystem (`tarif_grupo_rol`, `tarif_grupo_usuario`, `tarif_grupo_tarifa`, `tarif_editor_familia`). tarifario's opcional surface is **familia + tarifa** based.

## Indirect coupling (catalogo_core methods tarifario does call)

| # | tarifario site | catalogo_core method | Reads `id_grupo`? | Required change | Semantics preserved? |
|---|---|---|---|---|---|
| C1 | `model/tarif_articulo.php:130-134` `get_opcionales()` | `catalogo_articulo_opcional::get_opcionales_directos_from_articulo()` (`:175-190`, anti-join at `:181`) | yes | anti-join must become a bridge `NOT EXISTS` | yes — "grouped ⇒ not loose" unchanged |
| C2 | `Services/ArticuloListActionHandler.php::limpiar_opcionales()` `:2208-2213` (bulk delete of `catalogo_opcionales`) and `:2182-2206` (raw deletes of `catalogo_articulo_opcional`, `catalogo_opcional_familias`) | raw SQL, no model | n/a | **must also `DELETE FROM catalogo_opcional_grupo_rel`** (and opcional-scoped rows) or memberships orphan | yes |
| C2b | `Services/ArticuloListActionHandler.php::limpiar_todo()` `:1742` (dispatched from `:67`, `:162-163`); deletes `catalogo_opcionales` at `:1992` | raw SQL, no model | n/a | **second bulk-delete path** — must also `DELETE FROM catalogo_opcional_grupo_rel` (missed in the first pass of this note) | yes |
| C3 | `controller/tarif_catalogo_view.php:3198-3199`, `controller/tarif_configurador_opcionales.php:708-1323` | `catalogo_opcional_familia::exists_relation()/add()/remove()` | no | none | yes |
| C4 | `controller/tarif_catalogo_view.php:957,1221,2202,2456` | `tarif_tarifa_opcional_familia::get_opcionales_activos()`, `tarif_tarifa_opcional_resolver::get_opcionales_activos_articulo()` | no | none | yes |
| C5 | `Services/ArticuloListActionHandler.php`, `controller/tarif_catalogo_view.php:3111`, `controller/tarif_configurador_opcionales.php:956-1104` create/save `tarif_opcional` | `tarif_opcional::save()` → `catalogo_opcional::save()` (writes `id_grupo` at `:389/:392-400`) | yes (write) | freeze: parent `save()` stops writing `id_grupo`; the "grouped ⇒ delete direct relations" side effect moves to membership add | yes — tarifario never sets `id_grupo` on import/create (no `id_grupo` hits), so rows keep `NULL` |
| C6 | `model/tarif_tarifa_opcional_resolver.php:188-189` `sync_articulo_overrides()` | raw `SELECT id_opcional FROM catalogo_articulo_opcional` | no (relies on C1 semantics) | none | yes |
| C7 | install/activation (`extras/tarifario_init.php:76,95`) | delegates opcional tables to catalogo_core `Init::ensureCatalogTables()` | n/a | new bridge must be ensured by `catalogo_core/Init.php::ensureCatalogTables()` so it exists whenever catalogo_core boots (tarifario depends on catalogo_core) | yes |

Note: `catalogo_opcional_familia::add_with_propagation()` / `remove_with_propagation()` (the only other `id_grupo` readers, `:67`, `:97`) are reachable **only** from catalogo_core controllers (`VentasOpcionalesListTrait:802`, `VentasOpcional:263-305`, `controller/tarif_opcional_edit.php:200-249`). They are still in scope for the bridge rewrite (iterate ALL groups) but add no tarifario coupling.

## Required guardrails for this change

1. `catalogo_articulo_opcional::get_opcionales_directos_from_articulo()` must keep returning exactly the same set for tarifario (grouped opcionales are not loose) — bridge anti-join, not a behavior change.
2. `plugins/tarifario/Services/ArticuloListActionHandler.php` **both** bulk-delete paths must delete `catalogo_opcional_grupo_rel` rows: `limpiar_opcionales()` and `limpiar_todo()`.
3. `catalogo_core/Init.php::ensureCatalogTables()` must ensure the new bridge (covers tarifario boot).
4. A compatibility test must prove: (a) tarifario's direct-opcionales read is unchanged when an opcional is in one or two groups; (b) the bulk-delete leaves no orphan bridge rows.

## SDD ownership

Principal beneficiary is `catalogo_core`; the tarifario touch is a single cleanup plus a shared-model method. Per `AGENTS.md` → "OpenSpec per Plugin", the SDD stays in `plugins/catalogo_core/openspec/` and references `plugins/tarifario` as a consumer. No entry is created in tarifario's or the core's `openspec/`.
