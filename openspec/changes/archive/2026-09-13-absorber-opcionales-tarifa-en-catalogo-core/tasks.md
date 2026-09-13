# Tasks: Absorb tarifario's opcional domain into catalogo_core

> **Archive-time checkbox reconciliation (2026-09-13).** Per the archive phase's
> exceptional mechanical repair, boxes were reconciled against
> `verify-report.md` / `apply-progress.md` so the audit trail carries no stale
> unchecked box for work that provably completed:
> - 7.1–7.4 → `[x]`: the change landed as the committed 5-slice chain
>   (`catalogo_core` `8a8b063`…`e42b54c`; `tarifario` `e469f06`…`96a52e3`).
>   A later ownership-boundary correction reverted the configurator half to
>   `tarifario` in the working tree (human re-lands the corrected tree).
> - 8.1, 8.3, 8.4, 8.5, 8.6 → `[x]`: executed by the verify actor and recorded
>   in `verify-report.md`.
> - 6.5 (tpvmod) remains `[ ]` — explicitly deferred / out of scope by the user.
> - 8.2 (root `--testsuite Plugins` regression) remains `[ ]` — not re-run in
>   this cycle; recorded as an open item in `archive-report.md`.

> Plugin-local SDD (`plugins/catalogo_core/openspec/`). Sources of truth: `proposal.md`,
> `design.md` (D1–D10 + Delivery Slicing), delta specs `opcionales-tarifa-management` and
> `catalogo-render-hooks`. `config.yaml` → `strict_tdd: true`; runner
> `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`; linter
> `ddev exec composer phpstan`. Slugs, class names and table names are frozen; no data
> migration; additive only; no new Composer dependency (so no `vendor/` commit).
>
> **RED-before-GREEN**: every unit authors its RED test(s) first and records a RED run
> (missing catalogo_core path/class — not a syntax/setup error) before any implementation.
> Cross-repo `git mv` is impossible (independent plugin repos): copy byte-identical, then
> `git rm` in tarifario, then explicit-path `git add` in catalogo_core (D1).

## Work Unit Map (dependency-ordered, per design "Delivery Slicing")

| Unit | Depends on | Files touched (summary) | Acceptance check | Rollback boundary |
|---|---|---|---|---|
| **S1a** models/XML/bootstrap | — | 8 models + 5 XMLs + 1 service moved; 4 dead XMLs + 2 wrappers deleted; 2 FK XMLs + 2 model SQLs fixed; `catalogo_core/Init.php`; `tarifario_init.php` | `InitOpcionalesTablesTest` + `OpcionalDomainModelOwnershipTest` + `Services/TarifOpcionalExtMigrationTest` + `DeadOpcionalTableReferenceTest` green | revert `catalogo_core` commit (S1a paths) — canonical tables/data untouched |
| **S1b** controllers/views/reclass | S1a | 4 controllers + 7 views/partials moved; `extras/TarifarioOpcionalStateTrait.php` new | `TarifOpcionalesControllerContractTest` green; `php -l` clean | revert `catalogo_core` commit (S1b paths) |
| **S2** price adapter | S1a | moved `tarif_opcional_precio.php` + `tarif_opcional.php`; `tarif_actualizar_precios.php`; `tarif_articulos.php` | `OpcionalPriceUnificationTest` + `TarifOpcionalPreciosControllerTest` + `TarifOpcionalPrecioHistorialTest` + `TarifHistorialPreciosControllerTest` green | revert S2 files — canonical price table untouched, no down-migration |
| **S3** configurator/resolver fork | S1a, S1b | moved `tarif_configurador_opcionales.php` + `tarif_tarifa_opcional_resolver.php`; additive catalogo methods; staying `tarif_articulos.php`/`tarif_articulo_precios.php`/`tarif_catalogo_view.php`/`model/tarif_articulo.php` | `TarifConfiguradorOpcionalesTest` green; `php -l` clean | revert S3 files |
| **S4** hooks | S1b | `catalogo_core/View/ventas_opcional.html.twig`; `catalogo_core/View/ventas_articulo.html.twig` | `Integration/CatalogoCoreHookMarkersTest` + `tarifario/tests/Integration/HookRegistrationTest` green | revert the 2 marker edits (4 lines) |
| **S5** etiquetas/activation/gate | S1a–S4 | 5 new catalogo_core tests; `tpvmod/tests/TpvmodOpcionalesTest.php`; test retirements/repoints | `TarifTarifaOpcional{Etiqeta,Familia,Resolver,ArticuloOpcional}Test` + `DeadOpcionalTableReferenceTest` + `TpvmodOpcionalesTest` green — **tpvmod leg deferred (6.5 skipped, not run in 6.9)** | revert S5 test files |

Unit acceptance is a SUBSET of the Phase 8 final verification; each unit's rollback is
independent of commit creation (a revert of that unit's files must not require reverting
unrelated work — pure moves revert cleanly because names are unchanged).

---

## Phase 1: S1a — models / XML / standalone bootstrap + latent table fixes (RED → GREEN)

### RED (author FIRST; run before the move)

- [x] 1.1 Write `plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php` (DB-free): the 8 models (`tarif_opcional`, `tarif_opcional_ext`, `tarif_opcional_precio`, `tarif_opcional_precio_historial`, `tarif_tarifa_opcional_etiqueta`, `tarif_tarifa_opcional_familia`, `tarif_tarifa_articulo_opcional`, `tarif_tarifa_opcional_resolver`) and their 5 XMLs resolve from `plugins/catalogo_core/model/` with original FQCN + `table_name`; `ref_sap`/`en_catalogo`/`en_tarifa` exist only in `tarif_opcional_ext` (spec "Opcional domain models owned by catalogo_core"). Verify RED: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php`.
- [x] 1.2 Write `plugins/catalogo_core/tests/InitOpcionalesTablesTest.php` (mirror `InitFamiliasTablesTest.php`): `Init::ensureOpcionalesTarifaTables()` iterates the FK-safe list `tarif_opcional_ext` → `tarif_tarifa_opcional_etiqueta` → `tarif_opcional_precio_historial` → `tarif_tarifa_opcional_familia` → `tarif_tarifa_articulo_opcional`; every listed model + XML exists at catalogo_core paths; `is_file`/`class_exists(..., false)`/`is_subclass_of(fs_model::class)` guards present; no-op when a class is absent (spec "Standalone FK-safe table bootstrap"). Verify RED: `... plugins/catalogo_core/tests/InitOpcionalesTablesTest.php`.
- [x] 1.3 Move `plugins/tarifario/tests/Services/TarifOpcionalExtMigrationTest.php` → `plugins/catalogo_core/tests/Services/TarifOpcionalExtMigrationTest.php`: namespace `Tests\Tarifario\Services` → `Tests\CatalogoCore\Services`, import FQCN → `FSFramework\Plugins\catalogo_core\Services\TarifOpcionalExtMigration`, every `plugins/tarifario/...` path → `plugins/catalogo_core/...` (design D9 test disposition). Verify RED: `... plugins/catalogo_core/tests/Services/TarifOpcionalExtMigrationTest.php`.
- [x] 1.4 Write `plugins/catalogo_core/tests/DeadOpcionalTableReferenceTest.php` (grep gate, D9): recursively scan `plugins/catalogo_core` + `plugins/tarifario` (exclude `vendor/`, `openspec/`, `tests`) with regex `(FROM|JOIN|INTO|REFERENCES|UPDATE)[[:space:]]+\`?(tarif_articulo_opcional|tarif_opcionales)\`?([^_a-zA-Z0-9]|$)`; assert zero hits. The `([^_a-zA-Z0-9]|$)` whole-token boundary is the false-positive guard: it must NOT match `tarif_tarifa_articulo_opcional`, `tarif_tarifa_opcional_familia`, `tarif_opcional_ext`, nor the intentional legacy `FROM tarif_opcional_precios` in `CatalogLegacyTableMigration`. Also assert the two moved FK XMLs reference `catalogo_opcionales (id)`. Verify RED today: 11 hits (2 model SQL, 2 moved FK, 2 dead XML FK, 4 `tarif_articulos.php`).
- [x] 1.5 Confirm RED for 1.1–1.4: every failure is "missing catalogo_core path/class" or a counted gate hit, never a syntax/setup error. Record RED evidence for the apply notes.

### GREEN (move + latent fixes + bootstrap)

- [x] 1.6 Move S1a payload: byte-identical `cp` to `plugins/catalogo_core/model/` (8 models) + `plugins/catalogo_core/model/table/` (5 XMLs: `tarif_opcional_ext`, `tarif_opcional_precio_historial`, `tarif_tarifa_opcional_etiqueta`, `tarif_tarifa_opcional_familia`, `tarif_tarifa_articulo_opcional`) + `plugins/catalogo_core/Services/TarifOpcionalExtMigration.php`; then `git rm` the same 14 tarifario paths (cwd `plugins/tarifario`). Verify: catalogo_core `git status --short` shows the 14 additions; tarifario shows the 14 deletions.
- [x] 1.7 FK repoints (latent fix) on the moved XMLs: `tarif_tarifa_opcional_familia.xml:49` and `tarif_tarifa_articulo_opcional.xml:55` → `REFERENCES catalogo_opcionales (id)`. Verify: `grep -n 'catalogo_opcionales (id)' plugins/catalogo_core/model/table/tarif_tarifa_opcional_familia.xml plugins/catalogo_core/model/table/tarif_tarifa_articulo_opcional.xml`.
- [x] 1.8 Dead-table SQL repoints (latent fix) on the moved models: `tarif_tarifa_articulo_opcional.php:190,217` `tarif_articulo_opcional` → `catalogo_articulo_opcional`; `tarif_tarifa_opcional_resolver.php:120` `FROM tarif_articulo_opcional` → `FROM catalogo_articulo_opcional`; extract `tarif_tarifa_opcional_resolver.php:159` `FROM tarif_tarifa_articulo` to a `const TARIFA_ARTICULO_TABLE` + parameterized query (D4). Verify: `ddev exec php -l` per moved model; gate task 1.4 reduces to the `tarif_articulos.php` hits only.
- [x] 1.9 Delete dead artifacts (`git rm`, cwd `plugins/tarifario`): XMLs `model/table/tarif_opcionales.xml`, `model/table/tarif_opcional_familia.xml`, `model/table/tarif_articulo_opcional.xml`, `model/table/tarif_opcional_precios.xml`; deprecated wrappers `model/tarif_opcional_familia.php`, `model/tarif_articulo_opcional.php` (design "Deleted, never moved"). Verify: all 6 paths gone from `git ls-files`.
- [x] 1.10 Add `public static function ensureOpcionalesTarifaTables(): void` to `plugins/catalogo_core/Init.php` (D7): `require_once fs_model.php`; touch `catalogo_opcional` + `catalogo_lista_precio`; rely on `ensureFamiliasTarifaTables()`/`tarif_tarifa` for `tarif_tarifas`; instantiate the five moved models in FK-safe order with `is_file` + `class_exists(..., false)` + `is_subclass_of(..., fs_model)`; invoke from `init()` (after `ensureArticuloOpcionalGrupoTable()`) and `upgrade()` wrapped in try/catch + `error_log`; move `TarifOpcionalExtMigration::migrateIfNeeded()` here (after `migrateLegacyTables()`); remove `tarifario/Init.php::migrateOpcionalExtension()` (:89, :161-173) and its `use`. Verify: `ddev exec php -l plugins/catalogo_core/Init.php plugins/tarifario/Init.php`; tasks 1.2 + 1.3 GREEN.
- [x] 1.11 Repoint/prune tarifario staying requires to catalogo_core model paths and prune moved-table instantiations from `plugins/tarifario/extras/tarifario_init.php` (:26 require; :90,:110-115,:149-180 model instantiations for moved tables; :258-265 table list entries for moved tables) — catalogo_core `Init` now owns them. Repoint requires in `controller/tarif_tarifas.php:29-31,334,345,356`, `controller/tarif_catalogo_view.php:24-30,42`, `controller/tarif_actualizar_precios.php:24-25`, `controller/tarif_articulos.php:25`, `controller/tarif_articulo_precios.php:23`, `controller/tarif_tab_precios.php:22`, `controller/tarif_historial_precios.php:22` (design D4 final row). Verify: `ddev exec php -l` per file.
- [x] 1.12 Unit acceptance: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php plugins/catalogo_core/tests/InitOpcionalesTablesTest.php plugins/catalogo_core/tests/Services/TarifOpcionalExtMigrationTest.php` (all green) and `DeadOpcionalTableReferenceTest` no longer reports moved-model/dead-XML hits. **Rollback boundary**: revert the S1a paths in the catalogo_core commit and the corresponding `git rm`s in tarifario; canonical tables/data untouched, safe.

---

## Phase 2: S1b — controllers / views / reclass + `TarifarioOpcionalStateTrait` (RED → GREEN)

### RED

- [x] 2.1 Write `plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php` (source-contract over the moved controller sources): each of `tarif_opcionales`, `tarif_opcional_edit`, `tarif_opcional_precios`, `tarif_configurador_opcionales` is declared at `plugins/catalogo_core/controller/`, extends `fbase_controller`, has zero `tarif_controller` requires and zero `plugins/tarifario/model/tarif_*opcional*` requires, and keeps its slug/class name (spec "Moved controllers decoupled from tarifario opcional code"). Verify RED: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php`.

### GREEN

- [x] 2.2 Create `plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php` (additive, D2): supplies `$tarifas`, `$tarifas_defecto`, `$codtarifa`, `$codidioma`/`$idiomas`, `tarif_paginas()`, `tarif_buscar_articulo()` and the guarded `Init::ensureOpcionalesTarifaTables()` once-flag (`private static bool $tables_ensured = false;` + `class_exists(Init::class)`). Verify: `ddev exec php -l plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php`.
- [x] 2.3 Move S1b payload: byte-identical `cp` of the 4 controllers → `plugins/catalogo_core/controller/`; the 7 views → `plugins/catalogo_core/View/` (`tarif_opcionales.html.twig`, `tarif_opcional_edit.html.twig`, `tarif_opcional_precios.html.twig`, `tarif_configurador_opcionales.html.twig`, `tarif_configurador_tree.html.twig`, `tarif_opcional.html.twig`) + `View/partials/configurador/styles.html.twig`; then `git rm` the same paths in tarifario. Verify: 11 additions / 11 deletions in the two repos.
- [x] 2.4 In-place reclass of the moved controllers (one root cause: `tarif_controller` → `fbase_controller`): delete the `require_once .../extras/tarif_controller.php` line; add `use TarifarioOpcionalStateTrait;` + `extends fbase_controller`; keep `parent::private_core()`; header docblock `part of tarifario` → catalogo_core. Verify: task 2.1 GREEN; `ddev exec php -l` per controller.
- [x] 2.5 In-place view asset repoints: audit each moved `.twig` + `partials/configurador/styles.html.twig` for hardcoded `plugins/tarifario/...` asset/`{% include %}`/`{% import %}` paths and repoint to `plugins/catalogo_core/...`; keep the menu folder label `tarifario`; keep global Twig loader includes that already resolve. Verify: repo grep `grep -rn "plugins/tarifario/" plugins/catalogo_core/View --include='*.twig'` returns zero (or only intentional cross-plugin includes, documented).
- [x] 2.6 Unit acceptance + **rollback boundary**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php`; revert the S1b files (controllers/views/trait) without touching S1a models.

---

## Phase 3: S2 — unified per-tarifa opcional prices on `codlista` (RED → GREEN)

### RED

- [x] 3.1 Write `plugins/catalogo_core/tests/OpcionalPriceUnificationTest.php`: with only `tarif_opcional_precios` rows, `CatalogLegacyTableMigration::migrateIfNeeded` twice ⇒ `catalogo_opcional_precios` holds the values with `codtarifa → codlista`, the default tarifa has a matching price list, second run is a no-op (spec "Legacy prices are unified once"). Verify RED.
- [x] 3.2 Write `plugins/catalogo_core/tests/TarifOpcionalPreciosControllerTest.php`: the moved price action saves + reads back by `codlista` (spec "Price save/read uses codlista"). Verify RED.
- [x] 3.3 Write `plugins/catalogo_core/tests/TarifOpcionalPrecioHistorialTest.php`: a changed price appends exactly one history row with old/new values; saving the same value appends none (`abs(old-new) >= 0.0001`) (spec "Changed price writes one history row"). Verify RED.
- [x] 3.4 Write `plugins/catalogo_core/tests/TarifHistorialPreciosControllerTest.php`: `page=tarif_historial_precios&tipo=opcionales` renders opcional rows + stats/purge without fatal (spec "History page renders opcionales mode"). Verify RED.

### GREEN

- [x] 3.5 Rewrite moved `plugins/catalogo_core/model/tarif_opcional_precio.php` as a thin adapter `extends \FSFramework\model\catalogo_opcional_precio` (D3): canonical table `catalogo_opcional_precios`, key `(id_opcional, codlista)`; `codtarifa` via `__get/__set` aliasing `codlista`; delegate every member per the design contract table (`get/exists/delete`, `all_from_*`, `delete_*`, `set_precio`, `aplicar_porcentaje_masivo`, `all_by_familias`/`count_by_familias` → `codlista`); `save()` read-modify-write with price-history append. No physical `tarif_opcional_precios` created. Verify: tasks 3.1–3.3 GREEN.
- [x] 3.6 Repoint `plugins/catalogo_core/model/tarif_opcional.php` price methods (`get_precio_tarifa`/`set_precio_tarifa`/`precio_en_tarifa`/`delete_precio_tarifa`) to the canonical adapter, public signatures unchanged; `es_precio_porcentaje()`/`get_porcentaje($codtarifa)` stay on canonical per-lista `porcentaje`. Verify: `ddev exec php -l`; task 3.3 GREEN.
- [x] 3.7 Repoint raw price SQL: `plugins/tarifario/controller/tarif_actualizar_precios.php:261,269,276` (`op.codtarifa` → `op.codlista`, `FROM catalogo_opcional_precios op`); `plugins/tarifario/controller/tarif_articulos.php:2152,2386,2390,2454,2458` (`catalogo_opcional_precios`; `:2454,:2458` `codtarifa` → `codlista`) (design D3 raw-SQL repoints). Verify: `ddev exec php -l` per file + grep `tarif_opcional_precios` in controllers returns only the intentional legacy-read/comment.
- [x] 3.8 Unit acceptance + **rollback boundary**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/OpcionalPriceUnificationTest.php plugins/catalogo_core/tests/TarifOpcionalPreciosControllerTest.php plugins/catalogo_core/tests/TarifOpcionalPrecioHistorialTest.php plugins/catalogo_core/tests/TarifHistorialPreciosControllerTest.php`; revert S2 files — canonical price table and legacy residual are untouched.

---

## Phase 4: S3 — configurator/resolver dependency fork (RED → GREEN)

### RED

- [x] 4.1 Write `plugins/catalogo_core/tests/TarifConfiguradorOpcionalesTest.php`: roots + `htmx_tree` fragment render without fatal; each push action (`push_to_products`, `push_to_tags`, `push_to_children`, `push_all_down`) mutates the hierarchy and returns the expected fragment/JSON; `search_opcionales`/`create_opcional`/`edit_opcional` exposed (spec "Hierarchical configurator"). Verify RED.

### GREEN

- [x] 4.2 Fork the moved `plugins/catalogo_core/controller/tarif_configurador_opcionales.php` per the D4 replacement table: delete all `plugins/tarifario/model/tarif_*` requires + `use`s + the `tarif_tarifa_articulo`/`tarif_tarifa_articulo_etiqueta`/`tarif_articulo_precio` properties; declare `const TARIFA_ARTICULO_TABLE`, `const TARIFA_ARTICULO_ETIQUETA_TABLE`, `const TARIFA_ARTICULO_PRECIO_TABLE` + private parameterized helpers `articulos_from_familia()`, `etiquetas_articulo()`, `precio_articulo_tarifa()` (guarded by `db->table_exists()`, empty/fallback return); repoint `new tarif_articulo_opcional()` → `catalogo_articulo_opcional`, `new tarif_opcional_familia()` → `catalogo_opcional_familia`, `new tarif_articulo()` → `articulo`, `precio_en_tarifa()` → `precio_articulo_tarifa()` (design D4 sites). Verify: `ddev exec php -l`; task 4.1 GREEN.
- [x] 4.3 Add the additive relocation methods: `catalogo_articulo_opcional::get_opcionales_directos_from_articulo($referencia)` (hydrating `tarif_opcional`) and `catalogo_opcional_familia::get_opcionales_tarifario_from_familia($codfamilia)` (hydrating `tarif_opcional`) in `plugins/catalogo_core/model/core/` (design D4 additive). Verify: `ddev exec php -l` + task 4.1 GREEN.
- [x] 4.4 Forsake the resolver's tarifario classes: `tarif_tarifa_opcional_resolver.php:34` delete install call; `:66` private `etiquetas_articulo()` helper (parameterized SQL + `const TARIFA_ARTICULO_ETIQUETA_TABLE`); keep `:159` extracted `const` + parameterized (done in 1.8). Verify: `ddev exec php -l`.
- [x] 4.5 Repoint the remaining dead-table/raw consumers: `plugins/tarifario/controller/tarif_articulos.php:1989,2267,2271,2368,2372` → `catalogo_articulo_opcional`; `plugins/tarifario/model/tarif_articulo.php:119-120` → `catalogo_articulo_opcional` + `get_opcionales_directos_from_articulo`; `plugins/tarifario/controller/tarif_articulo_precios.php:246,266,286-287` → `catalogo_articulo_opcional`/`catalogo_opcional_familia::get_opcionales_tarifario_from_familia`; `plugins/tarifario/controller/tarif_catalogo_view.php:2125` → `catalogo_articulo_opcional`; `plugins/tarifario/controller/tarif_articulo_precios.php:23` etc. requires already repointed in 1.11. Verify: `ddev exec php -l` per file; task 1.4 gate now reports zero hits.
- [x] 4.6 Unit acceptance + **rollback boundary**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/TarifConfiguradorOpcionalesTest.php`; revert S3 files (configurator/resolver + additive methods + staying repoints). Behavior-preserving per design; verify-phase smoke confirms tree output matches pre-move.

---

## Phase 5: S4 — four frozen hook render markers (RED → GREEN)

### RED

- [x] 5.1 Write `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` (spec `catalogo-render-hooks`): markers exist at the frozen positions in `View/ventas_opcional.html.twig` (`ventas_opcional_tabs_after` before the `#ul_tabs` `</ul>`; `ventas_opcional_tab_pane_after` before the `.tab-content` `</div>`) and `View/ventas_articulo.html.twig` (`ventas_articulo_tabs_after` before the tab `</ul>`; `ventas_articulo_tab_pane_after` before its `.tab-content` `</div>`); exactly those four names and no `ventas_articulos_*`; whitespace-control `{{- … -}}` yields `''` when `ViewHookRegistry::has` is false; registered fragment renders unescaped at the marker; a throwing template is swallowed + logged; context `{'fsc','user','empresa','i18n'}` reaches the template; naming pattern derivable; no bare `tarifa` token. Verify RED.
- [x] 5.2 Extend/repoint `plugins/tarifario/tests/Integration/HookRegistrationTest.php`: `@tarifario/Hooks/*` resolves through the `TwigLoaderEvent` namespace; `ventas_opcional` (saved) + `ventas_articulo` (existing) render the Tarifas tab header + pane; unsaved opcional (`fsc.is_new`) renders no tab. Verify RED for the new render assertions.

### GREEN

- [x] 5.3 Insert the four markers exactly per design D8: `catalogo_core/View/ventas_opcional.html.twig` between `:126 {% endif %}` and `:127 </ul>`, and between `:419 {% endif %}` and `:420 </div>`; `catalogo_core/View/ventas_articulo.html.twig` between `:128 </li>` and `:129 </ul>`, and between `:339 {% include %}` and `:340 </div>`. Each as `{{- render_hook('<name>', {'fsc': fsc, 'user': user, 'empresa': empresa, 'i18n': i18n}) -}}` (no `|raw`; `render_hook` is `is_safe:['html']`). Verify: tasks 5.1 + 5.2 GREEN; `grep -c render_hook plugins/catalogo_core/View/ventas_opcional.html.twig` == 2 and `.../ventas_articulo.html.twig` == 2.
- [x] 5.4 Unit acceptance + **rollback boundary**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` and `... -c plugins/tarifario/phpunit.xml plugins/tarifario/tests/Integration/HookRegistrationTest.php`; revert the 4 marker lines only — host views otherwise untouched.

---

## Phase 6: S5 — etiquetas / activation / resolver tags / gate + tpvmod (RED → GREEN)

### RED

- [x] 6.1 Write `plugins/catalogo_core/tests/TarifTarifaOpcionalEtiquetaTest.php`: `replace_etiquetas_opcional` normalizes blanks + duplicates and is idempotent; tags isolated per `(codtarifa, id_opcional, codfamilia)` (spec "Tag replacement is normalized and idempotent" + "Tags are isolated per tarifa"). Verify RED.
- [x] 6.2 Write `plugins/catalogo_core/tests/TarifTarifaOpcionalFamiliaTest.php`: missing per-tarifa row ⇒ active (inherited); explicit `activo = FALSE` disables only that tarifa (spec "Inheritance defaults to active and override disables"). Verify RED.
- [x] 6.3 Write `plugins/catalogo_core/tests/TarifTarifaOpcionalResolverTest.php`: `get_opcionales_activos_articulo` includes untagged opcionales and includes tagged ones only on tag intersection with the article tags (spec "Resolver applies tag intersection rules"). Verify RED.
- [x] 6.4 Write `plugins/catalogo_core/tests/TarifTarifaArticuloOpcionalTest.php`: `sync_articulo_overrides`/`sync_familia_overrides` persist per-tarifa override rows for the effective set with activation counts (spec "Override sync persists the effective set"). Verify RED.
- [ ] 6.5 Harden `plugins/tpvmod/tests/TpvmodOpcionalesTest.php` (spec "tpvmod opcional consumption preserved"): add assertions that `tpvmod_opcionales.php` references only `catalogo_*` opcional classes (no removed `tarif_*opcional*` wrapper) and that `tpvmod_opcionales_for_articulo` groups/sueltos + prices match pre-move behavior. Verify RED/strengthened. **DEFERRED by user — tpvmod out of scope for this session.**

### GREEN

- [x] 6.6 GREEN the moved tag/activation/resolver implementations: adjust `tarif_tarifa_opcional_etiqueta.php`, `tarif_tarifa_opcional_familia.php`, `tarif_tarifa_articulo_opcional.php`, `tarif_tarifa_opcional_resolver.php` only where the RED tests require (verbatim move should satisfy most; fixes must stay within the moved files). Verify: tasks 6.1–6.4 GREEN. **Applied**: three behavior-preserving testability seams inside the moved files — `tarif_tarifa_opcional_etiqueta::normalizar_etiquetas()`, `tarif_tarifa_opcional_familia::base_relation_exists()`, `tarif_tarifa_opcional_resolver::{filtrar_por_etiquetas(), tarifa_articulo_opcional_model()}`; `tarif_tarifa_articulo_opcional.php` needed no change.
- [x] 6.7 Test retirement/repoint (design D9): `git rm plugins/tarifario/tests/Model/TarifOpcionalFamiliaConsumerReadTest.php` (covers the deleted wrapper); repoint `plugins/tarifario/tests/Controller/TarifTabPreciosTest.php:79` tracked model `table_name` → `catalogo_opcional_precios`; repoint `plugins/tarifario/tests/Controller/TarifArticulosFamiliaImportTest.php:59`; **also `git rm` the stale `plugins/tarifario/tests/Services/TarifOpcionalExtMigrationTest.php`** (already moved to catalogo_core, task 1.3). Verify: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → `187 tests, 0 errors, 0 failures`; retired paths absent.
- [x] 6.8 Gate GREEN + false-positive guard: run `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/DeadOpcionalTableReferenceTest.php`; assert zero hits and assert the two moved FK XMLs reference `catalogo_opcionales (id)`; re-run the raw `grep -rnE '(FROM|JOIN|INTO|REFERENCES|UPDATE)[[:space:]]+\`?(tarif_articulo_opcional|tarif_opcionales)\`?([^_a-zA-Z0-9]|$)' plugins/catalogo_core plugins/tarifario --exclude-dir=vendor --exclude-dir=openspec` returns zero (live class names are exempt; the intentional legacy `FROM tarif_opcional_precios` is not matched). Verify: `GATE OK`.
- [x] 6.9 Unit acceptance + **rollback boundary**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/TarifTarifaOpcionalEtiquetaTest.php plugins/catalogo_core/tests/TarifTarifaOpcionalFamiliaTest.php plugins/catalogo_core/tests/TarifTarifaOpcionalResolverTest.php plugins/catalogo_core/tests/TarifTarifaArticuloOpcionalTest.php` → `OK (18 tests, 73 assertions)`; catalogo_core full suite `354 tests / 0 failures`; PHPStan `[OK] No errors`. **tpvmod leg deferred by user — no tpvmod suite was run.** Revert S5 test files + repoints independently.
- [x] 6.10-EXTRA (user-mandated) Repoint the dead-table SQL in `plugins/tarifario/tools/tarifa_excel_to_json.py::export_precios_opcionales` to the canonical schema (`catalogo_opcionales` + `tarif_opcional_ext` + `catalogo_opcional_precios (codlista)`), preserving the downstream column aliases. Verify: `python3 -m py_compile`.

---

## Phase 7: Atomic two-repo commit + Twig cache clear (D1 / D10)

- [x] 7.1 Stage the **catalogo_core addition** (cwd `plugins/catalogo_core`, explicit paths only — **never** `git add -A`, it would sweep the untracked `openspec/changes/absorber-opcionales-tarifa-en-catalogo-core/`): 14 S1a additions (8 models + 5 XMLs + `Services/TarifOpcionalExtMigration.php`), 11 S1b additions (4 controllers + 7 views/partials), `extras/TarifarioOpcionalStateTrait.php`, edited `Init.php`, `model/tarif_opcional.php`, `model/core/catalogo_articulo_opcional.php`, `model/core/catalogo_opcional_familia.php`, the 2 marker-edited views, the moved/edited tests (`OpcionalDomainModelOwnershipTest`, `InitOpcionalesTablesTest`, `DeadOpcionalTableReferenceTest`, `TarifOpcionalesControllerContractTest`, `OpcionalPriceUnificationTest`, `TarifOpcionalPreciosControllerTest`, `TarifOpcionalPrecioHistorialTest`, `TarifHistorialPreciosControllerTest`, `TarifConfiguradorOpcionalesTest`, `TarifTarifaOpcionalEtiquetaTest`, `TarifTarifaOpcionalFamiliaTest`, `TarifTarifaOpcionalResolverTest`, `TarifTarifaArticuloOpcionalTest`, `Integration/CatalogoCoreHookMarkersTest`, `Services/TarifOpcionalExtMigrationTest`). Commit (conventional, no AI attribution, e.g. `feat: absorber dominio opcionales desde tarifario`).
- [x] 7.2 Stage the **tarifario removal/repoint** (cwd `plugins/tarifario`, explicit paths only): the S1a + S1b `git rm`s (25 moved + 6 dead artifacts), the retired `tests/Model/TarifOpcionalFamiliaConsumerReadTest.php`, `Init.php` (`migrateOpcionalExtension` removal), `extras/tarifario_init.php` prune, the repointed controllers (`tarif_actualizar_precios.php`, `tarif_articulos.php`, `tarif_articulo_precios.php`, `tarif_catalogo_view.php`, `tarif_tarifas.php`, `tarif_tab_precios.php`, `tarif_historial_precios.php`), `model/tarif_articulo.php`, and repointed tests (`Controller/TarifTabPreciosTest.php`, `Controller/TarifArticulosFamiliaImportTest.php`, `Integration/HookRegistrationTest.php`). Commit a matching conventional message (e.g. `refactor: mover dominio opcionales a catalogo_core`). **7.1 and 7.2 land back-to-back with NO intermediate deploy** — the runtime is complete only after both.
- [x] 7.3 Twig cache clear (D10 deploy hygiene): run `CacheManager::clearAll()` (or delete `tmp/twig_cache`) after both commits so moved template paths are never served stale. Rollback = revert tarifario commit first, then catalogo_core commit, then clear the Twig cache again.
- [x] 7.4 Verify atomicity: `git status --porcelain` clean in both plugin repos (except the intentional ` M openspec/config.yaml` + untracked `openspec/changes/absorber-opcionales-tarifa-en-catalogo-core/` in catalogo_core); parent repo untouched (`plugins/*` ignored). No `^R` rename entries anywhere (cross-repo rename detection cannot fire).

---

## Phase 8: Final verification (plugin suites + root Plugins suite + PHPStan)

- [x] 8.1 Plugin suites: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` and `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` and `ddev exec php vendor/bin/phpunit -c plugins/tpvmod/phpunit.xml` — record exit codes and failure names; zero new failures vs the pre-change baseline.
- [ ] 8.2 Root regression: `ddev exec php vendor/bin/phpunit --testsuite Plugins` (auto-discovers catalogo_core/tarifario/tpvmod test trees) — zero new failures vs baseline.
- [x] 8.3 Linter: `ddev exec composer phpstan` — new code (bootstrap, adapter, trait, relocation methods, fork helpers, 16 tests) must pass within the configured scope; record `[OK] No errors` or the scoped result.
- [x] 8.4 Gate re-run + grep audit: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/DeadOpcionalTableReferenceTest.php` → `GATE OK`; confirm the FK XMLs reference `catalogo_opcionales (id)`.
- [x] 8.5 Dev-DB smoke (record in `verify-report.md`): activate `catalogo_core` alone (tarifario inactive) → the 5 moved tables created FK-safe; re-run bootstrap → idempotent no-op; the four slugs (`tarif_opcionales`, `tarif_opcional_edit`, `tarif_opcional_precios`, `tarif_configurador_opcionales`) render without fatal; hooks render the Tarifas tab with tarifario active and no-op with it inactive.
- [x] 8.6 Confirm the SDD artifact rule: no entry was created/updated in the core `openspec/`; all artifacts live under `plugins/catalogo_core/openspec/changes/absorber-opcionales-tarifa-en-catalogo-core/`.

---

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~15,700 line-events across the two plugin repos: catalogo_core additions ≈7,800 moved + authored; tarifario deletions ≈7,900; cross-repo rename detection cannot fire (copy + `git rm`). Authored (excluding pure moves) ≈2,800–4,200: new tests ~1,600–2,200, adapter rewrite ~200, configurator/resolver fork ~250, latent SQL/FK fixes ~40, trait ~120, bootstrap ~40, repoints/prunes ~180, dead-artifact deletions ~−450, retired test ~−60. |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | S1a → S1b → S2 → S3 → S4 → S5. S1a+S1b are the mechanical move (the runtime is only complete after both) — keep them as one atomic work unit or request `size:exception`; S2–S5 are independently reviewable and reversible. Proposed slices: **PR 1** = S1a+S1b (models/XML/bootstrap/controllers/views/reclass + MoveMap tests), **PR 2** = S2 (price adapter + unification tests), **PR 3** = S3 (configurator/resolver fork + test), **PR 4** = S4 (hooks + marker/registration tests), **PR 5** = S5 (etiquetas/activation/resolver/tpvmod + grep gate). |
| Delivery strategy | ask-on-risk |
| Chain strategy | pending (user chooses at the apply guard) |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

> Because the session `delivery_strategy` is `ask-on-risk` and `review_budget_lines` is 400,
> the chain strategy is NOT auto-selected: the orchestrator must ask the user at the apply
> guard (Stacked PRs to main vs Feature Branch Chain vs `size:exception`). Atomicity note:
> the two plugin-repo commits of Phase 7 coordinate one deliverable and MUST land
> back-to-back regardless of the chosen slice mapping; the slices above are review
> boundaries, not independent deploys.
