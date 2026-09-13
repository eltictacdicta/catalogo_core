# Design: Absorb tarifario's opcional domain into catalogo_core

## Technical Approach

Atomic multi-repo move (D1/D11) mirroring `archive/2026-09-06-absorber-familias-tarifa-en-catalogo-core`: `git rm` in `plugins/tarifario` + byte-identical copy + explicit-path `git add` in `plugins/catalogo_core` (cross-repo `git mv` is impossible; both plugins are independent repos). Slugs, class names and table names stay stable. The moved controllers reclass `tarif_controller` → `fbase_controller` (D2). `tarif_opcional_precio` becomes a thin adapter over the canonical `catalogo_opcional_precios` mapping `codtarifa ↔ codlista` (D3). Moved controllers/resolver stop loading tarifario PHP (D4/D6). A standalone bootstrap in `catalogo_core/Init.php` creates the five moved tables FK-safe (D7). Four frozen `render_hook` markers expose the moved opcional UI to tarifario when active (D8). Strict TDD + grep gate cover ownership (D9).

## Architecture Decisions

| # | Decision | Choice | Alternatives rejected | Rationale |
|---|----------|--------|----------------------|-----------|
| D1 | Move mechanics | One atomic work unit = **two coordinated commits** (`catalogo_core` addition + `tarifario` removal/repoint) run back-to-back with no intermediate deploy. Byte-identical copy first, then `git rm`, then explicit-path `git add`. Parent repo untouched (no tracked core file changes). | `git mv` across repos (impossible); independent PRs (broken runtime window) | Familias precedent proven; parent `.gitignore` ignores `/plugins/*` and tracks no plugin bytes. |
| D2 | Controller base | Moved controllers `extends fbase_controller` + `use TarifarioOpcionalStateTrait` (new additive trait in `plugins/catalogo_core/extras/`). | Keep `tarif_controller` (violates dependency direction); new intermediate controller class (breaks the direct-parent assertion) | `fbase_controller::private_core()` supplies `allow_delete` + `multi_almacen`; the trait supplies exactly the `tarif_controller` state the 4 controllers consume (`$tarifas`, `$tarifas_defecto`, `$codtarifa`, `$codidioma`/`$idiomas`, `tarif_paginas()`, `tarif_buscar_articulo()`) plus the guarded `Init::ensureOpcionalesTarifaTables()` once-flag. Trait keeps `get_parent_class() === fbase_controller`. |
| D3 | Price unification (CRITICAL) | `tarif_opcional_precio extends \FSFramework\model\catalogo_opcional_precio`: canonical table `catalogo_opcional_precios`, key `(id_opcional, codlista)`, `codtarifa` aliased to `codlista` via `__get/__set`. No physical `tarif_opcional_precios`; `CatalogLegacyTableMigration::migrateOptionalPrices` is the single one-time copier. | (b) keep legacy table + sync; (c) add `codtarifa` column to canonical | Canonical already has every column the adapter needs (`precio`, `en_catalogo`, `porcentaje`); `catalogo_opcional` already exposes `precio_en_lista()/get_porcentaje($codlista)`; `migrateOptionalPrices` already maps `codtarifa AS codlista`. Sync (b) creates double-write drift and violates the spec's "single price source". |
| D4 | Configurator/resolver fork (CRITICAL) | Raw **parameterized** SQL against stable tarifario table names via declared `const` classes on the moved controllers; no tarifario PHP class dependency. | Repoint to catalogo/base equivalents (none exist for per-tarifa article relations); port/interface (over-engineering for two tables) | Mirrors familias `TARIFA_ARTICULO_TABLE` precedent; honors "moved controllers MUST NOT require tarifario files". |
| D5 | Etiquetas storage/API | Move `tarif_tarifa_opcional_etiqueta` verbatim (standalone `fs_model`, 4-tuple PK `(codtarifa,id_opcional,codfamilia,etiqueta)`). No catalogo base/subclass: the key carries `codtarifa` (tarifario-only) so no base class applies. | Ext-table/subclass coupling | The table has no catalogo equivalent; subclassing would invent a phantom base. |
| D6 | Activation/order relation | `tarif_tarifa_opcional_familia` / `tarif_tarifa_articulo_opcional` stay **standalone per-tarifa override tables** (`fs_model`), never subclasses of `catalogo_opcional_familia`/`catalogo_articulo_opcional`. Missing row ⇒ active (inherit); explicit `activo=FALSE` ⇒ disabled for that tarifa. | Subclass the catalogo base | Override tables are keyed by `(codtarifa, …)` and must not duplicate base rows; inheritance is a query concern. Base relations are read via `catalogo_*` joins. |
| D7 | Standalone bootstrap | New `Init::ensureOpcionalesTarifaTables()` (public static) invoked from `init()`, `upgrade()`, and the state trait once-flag. FK-safe order, `require_once` + `class_exists` guards, idempotent. | Extend `ensureCatalogTables()` (hardcodes `model/core/`) | Mirrors `ensureFamiliasTarifaTables()`; safe on fresh standalone installs. |
| D8 | Hook render points | Four frozen markers in catalogo_core host views with the frozen context; `render_hook` already `is_safe:['html']` and `ViewHookRegistry::render` returns `''` on empty registry/throw. | Add a new hook mechanism | Spec-frozen; zero core edits. |
| D9 | Test ownership | New catalogo_core tests per spec scenario; move `TarifOpcionalExtMigrationTest`; retire the wrapper consumer test; repoint two staying tests; grep gate with a false-positive guard. | Keep tests in tarifario | Plugin-local ownership. |
| D10 | Rollback | `git revert` the two commits in reverse order (`tarifario` → `catalogo_core`), then `CacheManager::clearAll()` + `tmp/twig_cache`. | Per-file manual restore | Single-commit revert restores both trees; canonical/legacy data untouched. |

## Move Inventory (D1)

**Models → `plugins/catalogo_core/model/` (root, `FSFramework\model\`):**
`tarif_opcional.php`, `tarif_opcional_ext.php`, `tarif_opcional_precio.php` (adapter, D3), `tarif_opcional_precio_historial.php`, `tarif_tarifa_opcional_etiqueta.php`, `tarif_tarifa_opcional_familia.php`, `tarif_tarifa_articulo_opcional.php`, `tarif_tarifa_opcional_resolver.php`.

**XML → `plugins/catalogo_core/model/table/` (5):** `tarif_opcional_ext.xml`, `tarif_opcional_precio_historial.xml`, `tarif_tarifa_opcional_etiqueta.xml`, `tarif_tarifa_opcional_familia.xml` (FK fix D7), `tarif_tarifa_articulo_opcional.xml` (FK fix D7).

**Deleted, never moved (dead):** `model/table/tarif_opcionales.xml`, `tarif_opcional_familia.xml` (the XML, not the model), `tarif_articulo_opcional.xml`, `tarif_opcional_precios.xml` (canonical is `catalogo_opcional_precios.xml`); models `tarif_opcional_familia.php`, `tarif_articulo_opcional.php` (deprecated wrappers).

**Controllers → `plugins/catalogo_core/controller/` (4):** `tarif_opcionales.php`, `tarif_opcional_edit.php`, `tarif_opcional_precios.php`, `tarif_configurador_opcionales.php`.

**Views → `plugins/catalogo_core/View/`:** `tarif_opcionales.html.twig`, `tarif_opcional_edit.html.twig`, `tarif_opcional_precios.html.twig`, `tarif_configurador_opcionales.html.twig`, `tarif_configurador_tree.html.twig`, `tarif_opcional.html.twig` (currently unreferenced — move, verify at apply), `partials/configurador/styles.html.twig`.

**Service → `plugins/catalogo_core/Services/`:** `TarifOpcionalExtMigration.php` (FQCN → `FSFramework\Plugins\catalogo_core\Services\TarifOpcionalExtMigration`), invoked from catalogo_core `Init` (after `migrateLegacyTables()`); `tarifario/Init.php::migrateOpcionalExtension()` is removed and only triggers `Init::upgrade()`/its own sweep.

**FK-safe order (D7):** `catalogo_opcionales` → `catalogo_lista_precio` → `tarif_tarifas` → `tarif_opcional_ext` → `tarif_tarifa_opcional_etiqueta` → `tarif_opcional_precio_historial` → `tarif_tarifa_opcional_familia` → `tarif_tarifa_articulo_opcional`.

## Price Unification Contract (D3)

`tarif_opcional_precios(codtarifa)` ≡ `catalogo_opcional_precios(codlista)`; `codlista = codtarifa`. `ref_sap`/`en_catalogo`/`en_tarifa` remain in `tarif_opcional_ext` (1:1); `en_catalogo` also exists in the canonical price row and passes straight through.

| Legacy member | Adapter behaviour |
|---|---|
| `get($id, $codtarifa)` / `exists()` / `delete()` | delegate to canonical by `(id_opcional, codlista)` |
| `all_from_opcional` / `all_from_tarifa` / `all` | delegate (`all_from_tarifa` → `all_from_lista`) |
| `delete_from_opcional` / `delete_from_tarifa` / `delete_precio` | delegate |
| `save()` | read-modify-write canonical; write `precio` + `en_catalogo`; preserve stored `porcentaje` unless the instance explicitly carries one; append `tarif_opcional_precio_historial` when `abs(old-new) >= 0.0001` (unchanged semantics) |
| `set_precio` / `aplicar_porcentaje_masivo` | rewritten on canonical via `get`/`save` |
| `all_by_familias` / `count_by_familias` | `op.codtarifa` → `op.codlista` |
| `codtarifa` property | `__get/__set` alias of `codlista` |

`tarif_opcional` (`get_precio_tarifa/set_precio_tarifa/precio_en_tarifa/delete_precio_tarifa`) keeps its public signatures and now reads/writes canonical; `es_precio_porcentaje()`/`get_porcentaje($codtarifa)` already resolve via `get_precio_lista()` (canonical per-lista `porcentaje`). `tarif_tab_precios` keeps using `tarif_opcional_precio` unchanged.

**Raw-SQL repoints required by the unification** (the greeting gate does not cover the price table, correctness does):
- `plugins/tarifario/controller/tarif_actualizar_precios.php:269,276` → `FROM catalogo_opcional_precios op`; `:261` `op.codtarifa` → `op.codlista`.
- `plugins/tarifario/controller/tarif_articulos.php:2152` `deleteTable('catalogo_opcional_precios', …)`; `:2386,2390,2454,2458` `catalogo_opcional_precios`; `:2454,2458` `codtarifa` → `codlista`.

## Dependency Fork (D4) — exact replacements

New private helpers/constants declared on `tarif_configurador_opcionales` (moved):
`const TARIFA_ARTICULO_TABLE = 'tarif_tarifa_articulo';`, `const TARIFA_ARTICULO_ETIQUETA_TABLE = 'tarif_tarifa_articulo_etiqueta';`, `const TARIFA_ARTICULO_PRECIO_TABLE = 'tarif_articulo_precios';`
`articulos_from_familia($codtarifa,$codfamilia): array` (SELECT `referencia`…, parameterized), `etiquetas_articulo($codtarifa,$referencia): array` (SELECT `etiqueta`…, parameterized), `precio_articulo_tarifa($referencia,$codtarifa): float` (SELECT `precio`…, fallback `articulo->pvp`). All guarded with `db->table_exists()` and returning empty/fallback so standalone catalogo_core never fatals.

| Site (current `tarifario/controller/tarif_configurador_opcionales.php`) | Replacement |
|---|---|
| `:22` require + `:37` use + `:64-65` property + `:89` `new tarif_tarifa_articulo()` | delete; `:224,:481,:629,:659,:754` `$this->tarifa_articulo->all_from_familia(...)` → `$this->articulos_from_familia($this->codtarifa,$codfamilia)` |
| `:30` require + `:40` use | delete; `:254,:275,:488,:654,:760` `new tarif_tarifa_articulo_etiqueta()->get_etiquetas_articulo(...)` → `$this->etiquetas_articulo($this->codtarifa,$referencia)` |
| `:32` require `tarif_articulo_precio` | delete (unused) |
| `:34` require `tarif_articulos_ext` | delete (unused) |
| `:39` use + `:253` `new tarif_articulo()` + `:268` `precio_en_tarifa()` | `use ...\articulo;`, `new articulo()`, `$this->precio_articulo_tarifa($ref,$this->codtarifa)`; `:260` `get_descripcion_idioma($this->codidioma)` unchanged |
| `:25` require + `:48` use + `:256,:408,:480,:653,:753,:1050,:1132,:1195` `new tarif_articulo_opcional()` | `catalogo_articulo_opcional` (namespace + class) |
| `:26` require + `:47` use + `:558,:702,:984,:1012,:1158,:1172` `new tarif_opcional_familia()` | `catalogo_opcional_familia` |
| `:20` `tarif_controller` + `:59` class | `fbase_controller` + `use TarifarioOpcionalStateTrait` |

| Site (`tarif_tarifa_opcional_resolver.php`) | Replacement |
|---|---|
| `:34` `new tarif_tarifa_articulo_etiqueta()` (install) | delete |
| `:66` `new tarif_tarifa_articulo_etiqueta()` | private `etiquetas_articulo()` helper (raw parameterized SQL, `const TARIFA_ARTICULO_ETIQUETA_TABLE`) |
| `:120` `FROM tarif_articulo_opcional` | `FROM catalogo_articulo_opcional` |
| `:159` `FROM tarif_tarifa_articulo` | keep; extract to `const TARIFA_ARTICULO_TABLE` + parameterized query |

| Site | Replacement |
|---|---|
| `tarif_tarifa_opcional_familia.php:85,121` `new tarif_opcional_familia()` | `new catalogo_opcional_familia()` |
| `tarif_tarifa_articulo_opcional.php:85,121` `new tarif_articulo_opcional()` | `new catalogo_articulo_opcional()`; `:190,:217` `tarif_articulo_opcional` → `catalogo_articulo_opcional` |
| `model/tarif_articulo.php:119-120`, `controller/tarif_articulo_precios.php:246,266`, `controller/tarif_articulos.php:1176,1891`, `controller/tarif_catalogo_view.php:2125` | `catalogo_articulo_opcional` |
| `model/tarif_articulo.php:120`, `configurador:271` `get_opcionales_from_articulo` (direct-only) | additive `catalogo_articulo_opcional::get_opcionales_directos_from_articulo($referencia)` hydrating `tarif_opcional` |
| `tarif_articulo_precios.php:286-287` `tarif_opcional_familia::get_opcionales_from_familia` | additive `catalogo_opcional_familia::get_opcionales_tarifario_from_familia($codfamilia)` hydrating `tarif_opcional` |
| `controller/tarif_articulos.php:1989,2267,2271,2368,2372` raw SQL | `catalogo_articulo_opcional` |
| `controller/tarif_tarifas.php:29-31,334,345,356`, `tarif_catalogo_view.php:24-30,42`, `tarif_actualizar_precios.php:24-25`, `tarif_articulos.php:25`, `tarif_articulo_precios.php:23`, `tarif_tab_precios.php:22`, `tarif_historial_precios.php:22`, `extras/tarifario_init.php:26,90,110-115,149-180` | repoint requires to `plugins/catalogo_core/model/...`; prune moved-table instantiations/wrapper from `tarifario_init.php` (catalogo_core `Init` owns them) |

## Hook Render Points (D8)

| Host view | Marker | Insertion |
|---|---|---|
| `catalogo_core/View/ventas_opcional.html.twig` | `ventas_opcional_tabs_after` | between `:126 {% endif %}` and `:127 </ul>` |
| `catalogo_core/View/ventas_opcional.html.twig` | `ventas_opcional_tab_pane_after` | between `:419 {% endif %}` and `:420 </div>` (`.tab-content`) |
| `catalogo_core/View/ventas_articulo.html.twig` | `ventas_articulo_tabs_after` | between `:128 </li>` and `:129 </ul>` |
| `catalogo_core/View/ventas_articulo.html.twig` | `ventas_articulo_tab_pane_after` | between `:339 {% include %}` and `:340 </div>` (`.tab-content`) |

Each marker: `{{- render_hook('<name>', {'fsc': fsc, 'user': user, 'empresa': empresa, 'i18n': i18n}) -}}`. Hosts are rendered by catalogo_core PSR-4 controllers via `src/Core/Base/Controller.php:432-437`, which already passes `user`, `empresa`, `i18n` (plus `fsc`), so the context resolves. `render_hook` is `is_safe:['html']` (`src/Core/Html.php:416-422`) — no `|raw`. When tarifario is inactive the registry is empty → `ViewHookRegistry::render` returns `''` (`src/View/ViewHookRegistry.php:37-53`); when active, `@tarifario/Hooks/*` resolves through the `TwigLoaderEvent` namespace registered in `tarifario/Init.php:132-137`. Whitespace control (`{{- … -}}`) keeps an empty marker zero-byte.

## Bootstrap, FK Fixes, Cleanup (D7)

`Init::ensureOpcionalesTarifaTables()` requires `base/fs_model.php`, touches `catalogo_opcional` + `catalogo_lista_precio` (canonical deps), relies on `ensureFamiliasTarifaTables()`/`tarif_tarifa` for `tarif_tarifas`, then instantiates each moved model with `is_file` + `class_exists(..., false)` + `is_subclass_of(..., fs_model)` guards. FK fixes: `tarif_tarifa_opcional_familia.xml:49` and `tarif_tarifa_articulo_opcional.xml:55` → `REFERENCES catalogo_opcionales (id)`. Dead XML/wrappers and `tarif_actualizar_precios`/`tarif_articulos` raw-SQL fixes as in D3/D4.

## Testing Strategy (D9)

| Layer | What | Approach |
|---|---|---|
| Unit | adapter price semantics (D3), etiquetas normalization/idempotence + per-tarifa isolation (D5), inheritance + resolver tag intersection + override sync (D6), bootstrap guard path (D7) | New catalogo_core tests: `OpcionalPriceUnificationTest`, `TarifTarifaOpcionalEtiquetaTest`, `TarifTarifaOpcionalFamiliaTest`, `TarifTarifaOpcionalResolverTest`, `TarifTarifaArticuloOpcionalTest`, `TarifOpcionalPrecioHistorialTest`, `InitOpcionalesTablesTest`, `OpcionalDomainModelOwnershipTest` |
| Integration / contract | moved controllers extend `fbase_controller` + zero tarifario requires; model ownership; hooks markers/context/no-op; configurator tree + pushes; history page; ext migration; grep gate | `TarifOpcionalesControllerContractTest`, `TarifOpcionalPreciosControllerTest`, `TarifHistorialPreciosControllerTest`, `TarifConfiguradorOpcionalesTest`, `DeadOpcionalTableReferenceTest`, `Integration/CatalogoCoreHookMarkersTest`, moved `Services/TarifOpcionalExtMigrationTest`; `tarifario/tests/Integration/HookRegistrationTest` |
| E2E | dev-DB smoke of the four slugs, standalone activation creates the 5 tables, hook renders with tarifario active/inactive | `sdd-verify` smoke checklist |

**Test disposition:** move `TarifOpcionalExtMigrationTest` (namespace → `Tests\CatalogoCore\Services`, paths → catalogo_core, FQCN → moved service); retire `TarifOpcionalFamiliaConsumerReadTest` (covers the deleted wrapper); repoint requires in staying `TarifTabPreciosTest` (`:79`, tracked model `table_name` → `catalogo_opcional_precios`) and `TarifArticulosFamiliaImportTest` (`:59`). No root `phpunit.xml` change (no moved/retired test is in the exclude list); the catalogo_core suite auto-discovers `tests/`.

**Grep gate** (`DeadOpcionalTableReferenceTest`): scan `plugins/catalogo_core` + `plugins/tarifario`, excluding `vendor/`, `openspec/`, `tests`; assert zero hits from `grep -rnE '(FROM|JOIN|INTO|REFERENCES|UPDATE)[[:space:]]+`?(tarif_articulo_opcional|tarif_opcionales)`?([^_a-zA-Z0-9]|$)'`. The whole-token boundary is the false-positive guard: it must not match live deprecated-class prefixes (`tarif_tarifa_articulo_opcional`, `tarif_tarifa_opcional_familia`) nor live tables (`tarif_opcional_ext`), and not the intentional legacy read `FROM tarif_opcional_precios` in `CatalogLegacyTableMigration`. It also asserts the two moved FK XMLs reference `catalogo_opcionales (id)`.

## Delivery Slicing (ask-on-risk, 400-line review budget)

Chained work units (each maps to one commit/PR; tests travel with their code):

1. **S1a — models/XML/bootstrap** (both repos): move models + 5 XMLs, adapter scaffold, FK fixes, dead-XML/wrapper removal, `Init::ensureOpcionalesTarifaTables()`, repoint all requires; delete dead XMLs. Runtime-equivalent (tarifario still serves its controllers; models autoload from active catalogo_core).
2. **S1b — controllers/views/reclass** (both repos): move 4 controllers + views + partials, `fbase_controller` + `TarifarioOpcionalStateTrait`, delete dead XMLs/`tarif_opcional_precios.xml`, S1 tests.
3. **S2 — price unification**: adapter contract + `tarif_actualizar_precios`/`tarif_articulos` SQL repoints + `OpcionalPriceUnificationTest`.
4. **S3 — dependency fork**: configurator/resolver raw-SQL helpers + additive relocation methods.
5. **S4 — hooks**: markers + `CatalogoCoreHookMarkersTest` + `HookRegistrationTest`.
6. **S5 — tags/activation/grep gate**: remaining scenario tests + `DeadOpcionalTableReferenceTest`.

S1a+S1b are the mechanical move (large authored diff); on `ask-on-risk`, request `size:exception` for S1a/S1b or keep the pair as one atomic work unit (the runtime is only complete after both). S2–S5 are each independently reviewable and reversible. SDD artifacts are committed separately at archive time.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary is introduced. New SQL is parameterized (D4) or dialect-neutral DDL via `fs_model` (D7).

## Migration / Rollout

No schema change to `catalogo_opcionales` (additive only) and no renames. Price rows unify via `CatalogLegacyTableMigration::migrateOptionalPrices` + `syncDefaultPriceListFromTarifario` (idempotent; legacy table left in place as accepted residual). Rollback (D10): revert the two commits in reverse order, then clear Twig cache; no down-migration exists. MySQL/PostgreSQL: DDL is XML-driven by `fs_model`, same mechanism as the familias bootstrap.

## Open Questions

- None blocking. The direct-only read relocation (D4 additive methods) is behavior-preserving; verify-phase smoke must confirm the configurator tree/`get_opcionales()` output matches pre-move.
