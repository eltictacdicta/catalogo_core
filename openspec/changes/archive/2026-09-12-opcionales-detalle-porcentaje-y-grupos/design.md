# Design: Percentage pricing, opcional groups, unified single-opcional detail

## Technical Approach

Restore percentage pricing and groups on unified `ventas_opcionales` and finish the `tarif_opcional_precios` consolidation, without schema change. Percentage keeps existing authority: global mode (`catalogo_opcionales.tipo_precio`) + per-tarifa value (`catalogo_opcional_precios.porcentaje`) that wins, read via `get_porcentaje()`/`etiqueta_precio_lista()`. Groups return as a column (controller-side map, no N+1), a `b_id_grupo` filter (optional trailing search param) and create-modal assignment. `tarif_opcional_edit` stays canonical; `tarif_opcional_precios` is deleted, links repointed, `fs_page` retired idempotently, locked coverage re-targeted. Strict TDD across WU-1 list → WU-2 detail → WU-3 htmx/Alpine → WU-4 writers/tab/export; each a revert point; floor **511/1756**.

## Architecture Decisions

| ID | Decision | Tradeoff rejected / rationale |
|---|---|---|
| AD-1 | Global `tipo_precio` mode + per-lista `porcentaje` value (wins) with global fallback; one reader `get_porcentaje($codlista)`. | Per-lista-only rewrites the fallback; global-only loses migrated data. |
| AD-2 | Add `set_porcentaje_tarifa()` (`porcentaje=pct`, `precio=0.0`); make `set_precio_tarifa()` clear the row's percentage. | Naive `porcentaje=null` is resurrected by the adapter's read-modify-write. |
| AD-3 | `tarif_opcional_precio::limpiar_porcentaje()` force flag; `save()` preserves stored value only when absent. | Legacy callers keep preserving; explicit clearing is expressible. |
| AD-4 | `tarif_opcional::opcional_precio_model()` factory used by writers. | Hardcoded `new tarif_opcional_precio()` is untestable. |
| AD-5 | Append optional trailing `$id_grupo=''` to `tarif_opcional::search()`/`count_filtered()` and `catalogo_opcional::search()`; join branch opens when `$id_grupo!==''`. | Reordering/requiring params breaks the locked stubs. |
| AD-6 | `b_id_grupo`: `''`=all, `'0'`=Sin grupo (`IS NULL OR =0`), numeric=group. | Mirrors `all_sin_grupo()`; `'0'` cannot collide with autoincrement ids. |
| AD-7 | `all_activos()` once → `$grupos_opcional` + `nombre_grupo_opcional($id)` map. | Avoids `etiqueta_grupo()` N+1 and risky JOIN in `search()`. |
| AD-8 | Create fields `tipo_precio`/`porcentaje`/`id_grupo`; global mode + per-tarifa rows via `set_porcentaje_tarifa`. | Mirrors `VentasOpcional`; grouping relies on `save()` deleting direct article relations. |
| AD-9 | Export `get_porcentaje($codtarifa)` numeric with `0.00"%"` via `valor_precio_export()`. | String label breaks sorting; headers stay frozen. |
| AD-10 | Delete precios controller+view, no alias; repoint 4 links preserving `codtarifa`; drop redundant edit `:79`. | Edit already duplicates the needed panels (verify, then delete). |
| AD-11 | Idempotent `retireTarifOpcionalPreciosPage()` wired in `Init::upgrade()`. | `fs_user::get_menu()` does not filter dead pages. |
| AD-12 | Keep `htmx.boot({'allowScriptTags': false})`, colon events, nonce'd `Alpine.data()` behind `alpine:init`, `[x-cloak]`, `hx-post` only, no `\|raw`/bootbox; CSRF per host (`validateFormToken` list, `requireCsrf` edit/tab). | CSP forbids inline eval; new controls join `Alpine.data()`. |
| AD-13 | No schema change (both `porcentaje` columns exist) and no Composer dependency. | Both columns already migrated. |

## Data Flow

```
catalogo_opcionales.tipo_precio | porcentaje          (global mode + fallback)
   └─ get_porcentaje(codlista) → etiqueta_precio_lista(codlista)
        → list cell / detail panel / tab row / export
catalogo_opcional_precios.porcentaje                  (per-tarifa value wins)
   ▲ set_porcentaje_tarifa(codtarifa,pct) → adapter(porcentaje=pct, precio=0) → save (+history)
   ▲ set_precio_tarifa(codtarifa,precio)  → limpiar_porcentaje()             → save (+history)

catalogo_opcional_grupo::all_activos() → $grupos_opcional + nombre map (once/request)
b_id_grupo → search(...,$id_grupo) → WHERE o.id_grupo = ? | (IS NULL OR =0)
id_grupo (create) → opcional.save() (deletes direct article relations when grouped)
```

## File Changes

| File | Action | Description |
|---|---|---|
| `extras/VentasOpcionalesListTrait.php` | Modify | Mode/percentage cache; percentage-aware `show_precio_opcional`; group map + `b_id_grupo`; create-modal percentage/group; export branch + `valor_precio_export()` |
| `model/tarif_opcional.php` | Modify | `set_porcentaje_tarifa()`, clearing `set_precio_tarifa()`, `opcional_precio_model()`, optional `$id_grupo` in `search()`/`count_filtered()` |
| `model/tarif_opcional_precio.php` | Modify | `limpiar_porcentaje()` + guarded read-modify-write |
| `model/core/catalogo_opcional.php` | Modify | Optional trailing `$id_grupo` in `search()` |
| `View/ventas_opcionales.html.twig` | Modify | Grupo column/filter, percentage price cell, create-modal toggle+group, repoint `:371`/`:431` |
| `controller/tarif_opcional_edit.php` | Modify | Percentage read/write in scoped + bulk saves |
| `View/tarif_opcional_edit.html.twig` | Modify | Percentage input branch; drop `:79`; repoint links |
| `controller/tarif_opcional_tab.php` | Modify | Persist `porcentaje` in percentage mode; zero `precio` |
| `View/Hooks/partials/opcional_precios_rows.html.twig` | Modify | Percentage input branch |
| `controller/tarif_opcional_precios.php` | Delete | Consolidated into `tarif_opcional_edit` |
| `View/tarif_opcional_precios.html.twig` | Delete | Consolidated; no alias |
| `Init.php` | Modify | `retireTarifOpcionalPreciosPage()` + `upgrade()` wiring |
| `plugins/tarifario/View/tarif_articulo_precios.html.twig` | Modify | Repoint `:184` to `tarif_opcional_edit&codtarifa=..` |

## Interfaces / Contracts

```php
// model/tarif_opcional.php
public function opcional_precio_model(): tarif_opcional_precio;
public function set_porcentaje_tarifa($codtarifa, $porcentaje): bool; // porcentaje=pct, precio=0.0
public function set_precio_tarifa($codtarifa, $precio): bool;         // limpiar_porcentaje()
public function search($query='', $offset=0, $codfamilia='', $codtarifa='', $solo_activos=false, $id_grupo='');
public function count_filtered($query='', $codfamilia='', $codtarifa='', $solo_activos=false, $id_grupo='');

// model/tarif_opcional_precio.php
public function limpiar_porcentaje(): void;

// extras/VentasOpcionalesListTrait.php
public array $grupos_opcional = [];
public function nombre_grupo_opcional($id_opcional): string; // map-backed, no N+1
public function show_precio_opcional($id_opcional): string;  // % label when percentage
protected function load_grupos_cache(): void;
protected function valor_precio_export($opc): array;         // ['value'=>float,'format'=>string]
```

Create fields `tipo_precio` ∈ {`fijo`,`porcentaje`}, `porcentaje`, `id_grupo`; filter `b_id_grupo`; detail reuses the single `guardar_precio_tarifa` form with a `tipo_precio` toggle.

## Testing Strategy

| Status | File | Requirements | Approach |
|---|---|---|---|
| New | `tests/CatalogoOpcionalesPercentageTest.php` | OPG-01, OUM-03/07 | Writer symmetry via `opcional_precio_model()` fake (fixed clears %, % zeroes price); `get_porcentaje` fallback; label branch; `valor_precio_export` |
| Updated | `tests/CatalogoOpcionalesUnifiedControllerTest.php` | OPG-02, OUM-02/03/05 | Group column/filter/create; percentage cache/display; extend stub signatures+assertions; drop precios view from repoint list |
| Updated | `tests/CatalogoOpcionalesHtmxContractTest.php` | OPG-02, OUM-05 | Group column/filter + percentage create-modal contract |
| Updated | `tests/TarifOpcionalesControllerContractTest.php` | OUM-11/12 | `CONTROLLER_SLUGS` 3 → 2 |
| Updated | `tests/TarifOpcionalesControllerMasterStateTest.php` | OTS-09, OUM-11 | Drop `PRECIOS_*`/`buildPreciosController` + 3 precios tests; re-target master reads at edit |
| Updated | `tests/TarifOpcionalesHtmxContractTest.php` | OPG-03, OTS-07 | Drop `PRECIOS_VIEW`/`preciosView()` + 2 precios tests; assert new controls |
| Updated | `tests/TarifOpcionalPreciosControllerTest.php` | OUM-11 | Delete controller test method; keep adapter tests |
| Updated | `tests/TarifOpcionalTabEndpointTest.php` | OPG-01 | Percentage row persists `porcentaje`, zeroes `precio` |
| Updated | `tests/InitUpgradeTest.php` | OUM-11 | Retirement idempotent across two runs |
| Guard | `tests/TarifOpcionalEditTarifaSelectorTest.php` | OTS-05, OPG-01 | Keep single `guardar_precio_tarifa` form |
| Guard | `DeadOpcionalTableReferenceTest`, `OpcionalPriceUnificationTest`, `Services/*`, `CatalogoOpcionalGrupoTest`, `CatalogoCoreHookMarkersTest` | boundaries | Unchanged |

Deleted test files: **none** (one method removed from `TarifOpcionalPreciosControllerTest`).

## Threat Matrix

The skill's process threat matrix is **N/A** (no routing/shell/subprocess/VCS/executable-classification/process boundary). Domain threats:

| Threat | Surface | Control | Test |
|---|---|---|---|
| CSRF | create/toggle/delete; edit/tab saves | Host guard; `hx-post`; `{{ csrf_field() }}` | Existing + new create/tab |
| XSS | Group names, percentage label, código/nombre | Twig autoescape, `x-text`, no `\|raw` | `CatalogoOpcionalesHtmxContractTest` |
| Data integrity | fixed ↔ percentage switch | `limpiar_porcentaje()`; percentage zeroes `precio` | `CatalogoOpcionalesPercentageTest` |
| SQL injection | New `id_grupo` WHERE | `intval()`/`var2str()` | Group-filter test |
| Broken menu | Orphan `fs_page` | Idempotent retirement | `InitUpgradeTest` |
| N+1 | Group column | One `all_activos()` per request | Unified test |

## Migration / Rollout

No schema or data migration. Sequence WU-1 → WU-2 → WU-3 → WU-4. Rollback per WU: revert its commit(s); WU-1 restores only the regression, WU-2 restores precios + links (retirement idempotent), WU-3/WU-4 restore prior edit/tab/export. Clear the Twig cache after any revert. Archive only when the suite is green at/above baseline.

## Open Questions

- [x] Group source map; filter key `b_id_grupo`; create = global % then per-tarifa editing; export numeric `0.00"%"`; retire `fs_page` (resolved in proposal).
- [ ] Confirm the "Sin grupo" sentinel literal (`'0'` chosen, consistent with `all_sin_grupo()`).
- [ ] Confirm no percentage upper bound (model allows ≥ 0; UI will not cap at 100 unless requested).
