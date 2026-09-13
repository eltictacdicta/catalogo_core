# Apply Progress — `absorber-opcionales-tarifa-en-catalogo-core`

Plugin-local SDD. Artifact store: `openspec`. Mode: **Strict TDD** (`config.yaml` → `strict_tdd: true`).

**Batches**:
- **S1a** (Phase 1, tasks 1.1–1.12) — models / XML / standalone bootstrap + latent table fixes.
- **S1b** (Phase 2, tasks 2.1–2.6) — controllers / views / reclass + `TarifarioOpcionalStateTrait`.
- **S2** (Phase 3, tasks 3.1–3.8) — unified per-tarifa opcional prices on `codlista` (adapter + raw-SQL repoints).
- **S3** (Phase 4, tasks 4.1–4.6) — configurator/resolver dependency fork + additive relocation methods.
- **S4** (Phase 5, tasks 5.1–5.4) — four frozen hook render markers + marker/registration tests.
- **S5** (Phase 6, tasks 6.1–6.4, 6.6, 6.7, 6.8, 6.9; **6.5 DEFERRED — tpvmod out of scope**) — etiquetas / activation / resolver-tags / override-sync + gate + test retirement/repoint + the `tarifa_excel_to_json.py` dead-table fix. The `tpvmod` leg was not touched and not run.

**Status**: S1a complete; S1b complete; S2 complete; S3 complete; S4 complete; **S5 complete (tpvmod leg deferred by user)**. Dead-table grep gate GREEN (zero hits). catalogo_core suite `354 tests / 0 failures`; tarifario suite `187 tests / 0 errors / 0 failures`.
**Runner**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
**Linter**: `ddev exec composer phpstan`

---

## Task Checklist (S1a)

- [x] 1.1 RED `tests/OpcionalDomainModelOwnershipTest.php` — 8 models + 5 XMLs owned by catalogo_core; `ref_sap`/`en_catalogo`/`en_tarifa` confined to `tarif_opcional_ext`.
- [x] 1.2 RED `tests/InitOpcionalesTablesTest.php` — FK-safe 5-model bootstrap list, on-disk model/XML presence, guard structure, Init wiring, **and the canonical-dependency touch order** (added).
- [x] 1.3 RED (moved) `tests/Services/TarifOpcionalExtMigrationTest.php` — namespace `Tests\CatalogoCore\Services`, FQCN + paths repointed to catalogo_core.
- [x] 1.4 RED `tests/DeadOpcionalTableReferenceTest.php` — grep gate with whole-token false-positive guard + FK XML assertions + dead-artifact removal.
- [x] 1.5 RED confirmation recorded (failing counts / missing catalogo_core paths, no syntax/setup errors).
- [x] 1.6 Moved S1a payload (8 models + 5 XMLs + 1 service); deleted the 14 tarifario originals.
- [x] 1.7 FK repoints on the two moved XMLs → `REFERENCES catalogo_opcionales (id)`.
- [x] 1.8 Dead-table SQL repoints + resolver `const TARIFA_ARTICULO_TABLE` + parameterized query.
- [x] 1.9 Deleted dead artifacts (4 XMLs + 2 deprecated wrappers).
- [x] 1.10 `Init::ensureOpcionalesTarifaTables()` + `migrateOpcionalExtension()` wiring + tarifario `migrateOpcionalExtension()` removal. **Ordering fix folded in**: `catalogo_lista_precio` → `catalogo_opcional` → `catalogo_articulo_opcional`.
- [x] 1.11 Repointed/pruned staying tarifario requires + `tarifario_init.php` moved-table instantiations + `codtarifa` table-list entries.
- [x] 1.12 Unit acceptance: 1.1–1.3 GREEN; gate reduced 11 → 4 hits (deferred to S3/S5).

---

## Task Checklist (S1b)

- [x] 2.1 RED `tests/TarifOpcionalesControllerContractTest.php` — the four slugs are declared at `plugins/catalogo_core/controller/`, extend `fbase_controller`, have zero `tarif_controller` references and zero `plugins/tarifario/model/tarif_*opcional*` requires, keep slug/class name, and use `TarifarioOpcionalStateTrait`.
- [x] 2.2 `extras/TarifarioOpcionalStateTrait.php` created (additive, D2): `$tarifas`, `$tarifa_defecto`/`$tarifas_defecto`, `$codtarifa`, `$codidioma`/`$idiomas`, `tarif_paginas()`, `tarif_buscar_articulo()` and the guarded `Init::ensureOpcionalesTarifaTables()` once-flag.
- [x] 2.3 Moved S1b payload: 4 controllers → `controller/`; 7 views/partials → `View/` (incl. `View/partials/configurador/styles.html.twig`); byte-identical `cp` + `git rm` of the 11 tarifario originals.
- [x] 2.4 In-place reclass of the 4 controllers: `tarif_controller` → `fbase_controller` + `use TarifarioOpcionalStateTrait;` + `$this->init_tarifario_opcional_state()`; moved-model requires repointed to catalogo_core; deleted-wrapper requires removed; `parent::private_core()` kept; docblock `part of tarifario` → catalogo_core.
- [x] 2.5 View asset audit: zero `plugins/tarifario/...` references in the moved `.twig`/partial (no repoint needed); menu folder label `tarifario` kept.
- [x] 2.6 Unit acceptance: `TarifOpcionalesControllerContractTest` GREEN; `php -l` clean; runtime class-load harness confirms `parent=fbase_controller` + trait for all four.

---

## Task Checklist (S2)

- [x] 3.1 RED `tests/OpcionalPriceUnificationTest.php` — with only `tarif_opcional_precios` rows, `CatalogLegacyTableMigration::migrateIfNeeded` twice ⇒ canonical table holds the values with `codtarifa → codlista`, the default tarifa has a matching price list, second run is a no-op; plus the adapter-extends-canonical assertion.
- [x] 3.2 RED `tests/TarifOpcionalPreciosControllerTest.php` — adapter extends the canonical model, `get()` reads by `codlista` (no `codtarifa` column), no physical legacy table, moved controller persists through the catalogo_core adapter.
- [x] 3.3 RED `tests/TarifOpcionalPrecioHistorialTest.php` — a changed price appends exactly one history row (old/new values); the same value appends none (`abs(old-new) >= 0.0001`), via the `registrar_cambio_historial()` seam.
- [x] 3.4 RED `tests/TarifHistorialPreciosControllerTest.php` — the catalogo_core-owned history model serves `tipo=opcionales` (list/counters/stats/purge) safely when the table is absent.
- [x] 3.5 Rewrote `model/tarif_opcional_precio.php` as a thin adapter `extends \FSFramework\model\catalogo_opcional_precio` (D3): canonical `catalogo_opcional_precios`, key `(id_opcional, codlista)`, `codtarifa` via `__get/__set/__isset`, `save()` read-modify-write + history append seam, delegated `all_from_*`/`delete_*`/`delete_precio`/`set_precio`/`all`/`aplicar_porcentaje_masivo`/`all_by_familias`/`count_by_familias` on `codlista`. No physical `tarif_opcional_precios` created.
- [x] 3.6 Repointed `model/tarif_opcional.php::search()` canonical join (`catalogo_opcional_precios` + `op.codlista`); `get_precio_tarifa`/`set_precio_tarifa`/`precio_en_tarifa`/`delete_precio_tarifa` keep their public signatures and now read/write canonical through the adapter.
- [x] 3.7 Repointed raw price SQL: `tarifario/controller/tarif_actualizar_precios.php` (`op.codtarifa` → `op.codlista`, `FROM catalogo_opcional_precios op`); `tarifario/controller/tarif_articulos.php:2152,2386,2390,2454,2458` (canonical table; `:2454,:2458` `codtarifa` → `codlista`); `tarifario/controller/tarif_tarifas.php::copy_precios_opcionales()` (same dead-table break).
- [x] 3.8 Unit acceptance: S2 targeted suite GREEN; `php -l` + PHPStan clean; catalogo_core full suite shows **no new failures** (only the intentionally-deferred grep gate hits owned by S3/S5). Rollback boundary recorded below.

---

## Task Checklist (S3)

- [x] 4.1 RED `tests/TarifConfiguradorOpcionalesTest.php` — configurator with zero `plugins/tarifario/` requires/uses, fork consts, private parameterized SQL helpers (`articulos_from_familia`/`etiquetas_articulo`/`precio_articulo_tarifa`) guarded by `table_exists()`; hierarchy actions exposed; `htmx_tree` renders the tree fragment; push actions return the `{success,count}` JSON; additive relocation methods hydrate `tarif_opcional`; resolver drops the tarifario etiqueta class in favor of the const + private helper. RED = 12/15 failures (missing consts/helpers/methods + residual `plugins/tarifario/` string), no syntax/setup error.
- [x] 4.2 Forked `controller/tarif_configurador_opcionales.php` per D4: deleted all `plugins/tarifario/model/tarif_*` requires + `use`s + the `tarif_tarifa_articulo`/`tarif_tarifa_articulo_etiqueta`/`tarif_articulo_precio` properties; declared the three `TARIFA_ARTICULO_*_TABLE` consts and the three private parameterized helpers; repointed `tarif_articulo_opcional` → `catalogo_articulo_opcional`, `tarif_opcional_familia` → `catalogo_opcional_familia`, `tarif_articulo` → `articulo`, `precio_en_tarifa()` → `precio_articulo_tarifa()`, `all_from_familia` → `articulos_from_familia`, `get_etiquetas_articulo` → `etiquetas_articulo`.
- [x] 4.3 Added additive relocation methods in `model/core/`: `catalogo_articulo_opcional::get_opcionales_directos_from_articulo($referencia)` and `catalogo_opcional_familia::get_opcionales_tarifario_from_familia($codfamilia)`, both parameterized and hydrating `tarif_opcional`.
- [x] 4.4 Resolver `model/tarif_tarifa_opcional_resolver.php`: deleted the `new tarif_tarifa_articulo_etiqueta()` install call; added `const TARIFA_ARTICULO_ETIQUETA_TABLE` + private parameterized `etiquetas_articulo()`; kept the S1a `const TARIFA_ARTICULO_TABLE` + parameterized query.
- [x] 4.5 Repointed the remaining dead-table/raw consumers: `tarifario/controller/tarif_articulos.php` (`deleteTable` + 4 raw SQL sites) → `catalogo_articulo_opcional`; `tarifario/model/tarif_articulo.php:119-120` → `catalogo_articulo_opcional` + `get_opcionales_directos_from_articulo`; `tarifario/controller/tarif_articulo_precios.php` → `catalogo_articulo_opcional`/`catalogo_opcional_familia::get_opcionales_tarifario_from_familia`; `tarifario/controller/tarif_catalogo_view.php` → `catalogo_articulo_opcional` + `catalogo_opcional_familia`. **Same-root-cause superset**: the moved `catalogo_core/controller/tarif_opcional_edit.php` still used the deleted `tarif_articulo_opcional` wrapper (`use` + `add_articulo`/`remove_articulo`), the exact dependency-fork defect flagged in Residual Risk 3; it now uses `catalogo_articulo_opcional` + the catalogo_core require (byte-faithful to the verified reference). Dead-table gate now reports **zero hits** and no whole-token deleted-wrapper reference remains outside the intentional `CatalogLegacyTableMigration` map/comment.
- [x] 4.6 Unit acceptance + **rollback boundary**: `TarifConfiguradorOpcionalesTest` GREEN (15 tests, 79 assertions); `php -l` clean (9 files); `DeadOpcionalTableReferenceTest` GREEN (4 tests, 21 assertions).

---

## Task Checklist (S4)

- [x] 5.1 RED `tests/Integration/CatalogoCoreHookMarkersTest.php` (spec `catalogo-render-hooks`): 11 tests — the four frozen markers at their frozen positions (tabs marker immediately before the `#ul_tabs`/`#tab_articulo` `</ul>`; pane marker immediately before the balancing `.tab-content` `</div>`); exactly the four names and no `ventas_articulos_*`; whitespace-control `{{- … -}}` yields `''` with an empty registry; the registered fragment renders unescaped in the real host view; a throwing template is swallowed + logged; context `{'fsc','user','empresa','i18n'}` reaches the hook template; naming pattern derivable; no bare `tarifa` token. RED = 11/11 failures, every one a missing-marker failure (no syntax/setup error).
- [x] 5.2 Extend `tarifario/tests/Integration/HookRegistrationTest.php`: renders the real `ventas_opcional` (saved) + `ventas_articulo` catalogo_core host views with the Init-registered `@tarifario` namespace and the four view hooks, asserting the Tarifas tab header sits inside the tab list and the pane inside `.tab-content`; an unsaved opcional (`fsc.is_new`) renders no `tab_tarifario_precios`. RED = `test_catalogo_host_views_render_the_tarifas_tab_when_tarifario_is_active` fails with no injected header.
- [x] 5.3 Inserted the four markers exactly per design D8 (line numbers verified first): `View/ventas_opcional.html.twig` before the `#ul_tabs` `</ul>` and before the `.tab-content` `</div>`; `View/ventas_articulo.html.twig` before the tab `</ul>` and before its `.tab-content` `</div>` — each `{{- render_hook('<name>', {'fsc': fsc, 'user': user, 'empresa': empresa, 'i18n': i18n}) -}}`, no `|raw`. `grep -c render_hook` == 2 in each host view.
- [x] 5.4 Unit acceptance + **rollback boundary**: `CatalogoCoreHookMarkersTest` GREEN (11 tests, 67 assertions); `HookRegistrationTest` GREEN (9 tests, 45 assertions); catalogo_core full suite 336 tests / 0 failures; tarifario full suite unchanged baseline (6 errors + 1 failure, all pre-existing S5 stale-move files); PHPStan `[OK] No errors` (188 files). Revert the 4 marker lines only — host views otherwise untouched.

---

## Task Checklist (S5)

- [x] 6.1 RED `tests/TarifTarifaOpcionalEtiquetaTest.php` — `replace_etiquetas_opcional` normalizes blanks/duplicates and is idempotent; tags isolated per `(codtarifa, id_opcional, codfamilia)`. RED = missing `normalizar_etiquetas()` seam (1 failing test), no syntax/setup error.
- [x] 6.2 RED `tests/TarifTarifaOpcionalFamiliaTest.php` — missing per-tarifa row ⇒ active (inherited); explicit `activo = FALSE` disables only that tarifa; query-level inheritance predicates + per-tarifa `set_activo` write. RED = missing `base_relation_exists()` seam (3 failing tests).
- [x] 6.3 RED `tests/TarifTarifaOpcionalResolverTest.php` — `get_opcionales_activos_articulo` includes untagged and tagged-on-intersection only; pure filter seam + wiring contract. RED = missing `filtrar_por_etiquetas()` seam (4 failing tests).
- [x] 6.4 RED `tests/TarifTarifaArticuloOpcionalTest.php` — `sync_articulo_overrides`/`sync_familia_overrides` persist per-tarifa override rows for the effective set with activation counts. RED = missing `tarifa_articulo_opcional_model()` factory seam (2 failing tests).
- [ ] 6.5 **DEFERRED by user** — `plugins/tpvmod/tests/TpvmodOpcionalesTest.php` hardening. `plugins/tpvmod/` was not modified; no tpvmod suite was run in 6.9.
- [x] 6.6 GREEN the moved tag/activation/resolver implementations: added three behavior-preserving testability seams — `tarif_tarifa_opcional_etiqueta::normalizar_etiquetas()`, `tarif_tarifa_opcional_familia::base_relation_exists()`, `tarif_tarifa_opcional_resolver::{filtrar_por_etiquetas(), tarifa_articulo_opcional_model()}` — and wired the call sites. No behavior, slug, class name or table name changed.
- [x] 6.7 Test retirement/repoint (design D9): `git rm` `tests/Model/TarifOpcionalFamiliaConsumerReadTest.php` (deleted wrapper) and the stale `tests/Services/TarifOpcionalExtMigrationTest.php` (moved to catalogo_core); repointed `tests/Controller/TarifTabPreciosTest.php` (require → catalogo_core + tracked `table_name` → `catalogo_opcional_precios`) and `tests/Controller/TarifArticulosFamiliaImportTest.php` (require → catalogo_core). tarifario suite now `187 tests / 0 errors / 0 failures`.
- [x] 6.8 Gate GREEN + false-positive guard: `DeadOpcionalTableReferenceTest` GREEN (4 tests, 21 assertions); raw grep zero hits; both moved FK XMLs still reference `catalogo_opcionales (id)`.
- [x] 6.9 Unit acceptance + **rollback boundary**: S5 targeted `OK (37 tests, 173 assertions)` (4 new tests + `TarifConfiguradorOpcionalesTest` + gate); catalogo_core full suite `354 tests / 1018 assertions / 0 failures`; PHPStan `[OK] No errors` (188 files). **tpvmod leg NOT run (deferred).** Revert the 4 S5 test files + the 3 seam edits + the tarifario repoint/retire + the python tool fix independently.
- [x] **EXTRA FIX** — `plugins/tarifario/tools/tarifa_excel_to_json.py` `export_precios_opcionales` repointed from the dead `tarif_opcionales`/`tarif_opcional_precios` schema to `catalogo_opcionales` + `tarif_opcional_ext` + `catalogo_opcional_precios (codlista)`. Verified with `python3 -m py_compile`.

---

## TDD Cycle Evidence (Strict TDD)

| Task | RED (test written first) | GREEN (implementation) | REFACTOR |
|---|---|---|---|
| 1.1 | `OpcionalDomainModelOwnershipTest` authored before any move; RED = missing catalogo_core paths/classes | Models moved + requires repointed; 5/5 tests GREEN | none needed |
| 1.2 | `InitOpcionalesTablesTest` authored before `Init.php` change; RED = missing `ensureOpcionalesTarifaTables()` | Method + wiring added (incl. catalog-dependency order); 7/7 tests GREEN | comment clarifies why `catalogo_lista_precio` must precede `catalogo_opcional` |
| 1.3 | `Services/TarifOpcionalExtMigrationTest` moved before service move; RED = missing catalogo_core service | Service moved + namespace/path repointed; 6/6 tests GREEN | none needed |
| 1.4 | `DeadOpcionalTableReferenceTest` authored before fixes; RED = 11 gate hits | 7 hits resolved by S1a; 4 remain (tarifario `tarif_articulos.php`, S3 scope) | gate pattern documented with false-positive guard |
| 2.1 | `TarifOpcionalesControllerContractTest` authored before the move; RED = 5/5 failures, every one "missing catalogo_core path", no syntax/setup error | 4 controllers moved + reclassed + 7 views moved; trait added; 5/5 tests GREEN (28 assertions) | none needed (byte-identical move + surgical reclass) |
| 3.1 | `OpcionalPriceUnificationTest` authored before the adapter rewrite; RED = `test_adapter_reads_the_unified_canonical_table` fails (`tarif_opcional_precio` still extends `fs_model`). The 3 migration asserts are green-on-authoring: they lock the pre-existing `CatalogLegacyTableMigration::migrateOptionalPrices` bridge (spec lock, not new behavior). | Adapter rewritten on canonical; 4/4 tests GREEN (25 assertions) | none needed |
| 3.2 | `TarifOpcionalPreciosControllerTest` authored before the rewrite; RED = 3/4 failures (not subclass of canonical; `get()` selects dead `codtarifa`; source still binds `parent::__construct('tarif_opcional_precios')`). The controller-persists-through-adapter assert is green-on-authoring (S1b already repointed the require). | Adapter + controller GREEN; 4/4 tests GREEN (11 assertions) | none needed |
| 3.3 | `TarifOpcionalPrecioHistorialTest` authored before the rewrite; RED = 2/2 failures (`registrar_cambio_historial` seam missing). | `save()` read-modify-write + history-append seam implemented; 2/2 tests GREEN (4 assertions) | history seam extracted (`registrar_cambio_historial`) for DB-free testability |
| 3.4 | `TarifHistorialPreciosControllerTest` authored before the S2 model work; GREEN-on-authoring (4/4) because the history model was already moved to catalogo_core and the controller require repointed in S1a — this test is the S2 regression lock for the spec scenario (no fatal, stats/purge) | No production change required; 4/4 tests GREEN (14 assertions) | none needed |
| 3.8 | S2 + flipped `OpcionalDomainModelOwnershipTest` row authored before implementation; full RED = `Tests: 19, Assertions: 91, Failures: 7` (all "extends fs_model / dead codtarifa", no syntax/setup error) | `php -l` clean (5 files); S2 targeted `OK (19 tests, 102 assertions)`; full catalogo_core suite `Tests: 310, Assertions: 799, Failures: 1` (only the deferred gate, S3/S5); PHPStan `[OK] No errors` | ownership row flipped `tarif_opcional_precios`/`fs_model` → `catalogo_opcional_precios`/`catalogo_opcional_precio` |
| 4.1 | `TarifConfiguradorOpcionalesTest` authored before the fork; RED = `Tests: 15, Assertions: 31, Failures: 12` — every failure is a missing const/helper/method or the residual `plugins/tarifario/` dependency string, no syntax/setup error (the 3 behavior-lock asserts of 4.1 pass on authoring) | Controller/resolver/core-model fork applied; `OK (15 tests, 79 assertions)` | none needed (byte-faithful fork) |
| 4.2 | Same RED file, `test_configurator_*` rows authored before the fork (`plugins/tarifario/` + missing consts/helpers) | `TARIFA_ARTICULO_{TABLE,ETIQUETA_TABLE,PRECIO_TABLE}` + `articulos_from_familia`/`etiquetas_articulo`/`precio_articulo_tarifa` declared; `php -l` clean; 15/15 GREEN | helper names chosen to not collide with the `all_from_familia`/`get_etiquetas_articulo` grep asserts |
| 4.3 | RED rows `test_catalogo_{articulo_opcional,opcional_familia}_exposes_*` authored before the additive methods (missing methods) | Both additive methods added, parameterized, hydrating `tarif_opcional`; `OK` | none needed |
| 4.4 | RED rows `test_resolver_*` authored before the resolver fork (install call present, const/helper missing) | Install call deleted; const + private helper added; `OK` | none needed |
| 4.5 | Gate RED-by-design until this task: 4 hits in `tarif_articulos.php` (2267/2271/2368/2372) | Repointed to `catalogo_articulo_opcional` (+ `deleteTable`, + `tarif_articulo.php`/`tarif_articulo_precios.php`/`tarif_catalogo_view.php`); `DeadOpcionalTableReferenceTest` → `OK (4 tests, 21 assertions)`; raw grep → zero | none needed |
| 4.6 | Unit acceptance authored before the fork (the whole 4.1 file) | `TarifConfiguradorOpcionalesTest` GREEN; `php -l` clean (9 files); full catalogo_core suite `OK (325 tests, 878 assertions, 25 warnings, 1 skipped)` — the previous single gate failure is gone; tarifario suite unchanged at `164 tests, 6 errors, 1 failure` (pre-existing stale move-test files, S5 scope) | none needed |
| 5.1 | `Integration/CatalogoCoreHookMarkersTest` authored before any marker; RED = `Tests: 11, Assertions: 25, Failures: 11` — every failure a missing-marker failure ("No line containing 'render_hook(...'", "All four frozen markers must be declared"), no syntax/setup error | Four markers inserted into the two host views; `OK (11 tests, 67 assertions)` | host render harness stubbed (header/footer/partials shells) so only marker output is observed; the marker expression is extracted from the authored line, not duplicated |
| 5.2 | `HookRegistrationTest` host-render rows authored before the markers; RED = `test_catalogo_host_views_render_the_tarifas_tab_when_tarifario_is_active` fails ("must inject the Tarifas tab header"). The unsaved guard row is an absence lock (passes pre/post: no markers → no tab; markers + `fsc.is_new` guard → still no tab) | Same four markers make the active-render row GREEN; file `OK (9 tests, 45 assertions)` | ChainLoader ([stubbed shells, Init-registered `@tarifario` namespace]) renders the real host views without duplicating the catalogo_core harness |
| 5.3 | Marker source assertions (5.1) authored before insertion; RED as above | Markers inserted byte-exact per D8; `grep -c render_hook` == 2 per host view; 5.1 GREEN | none needed |
| 5.4 | Unit acceptance authored with the whole 5.1 file and the 5.2 additions | catalogo_core full suite `336 tests, 945 assertions, 0 failures`; tarifario full suite `166 tests, 6 errors, 1 failure` (identical pre-existing S5 baseline, +2 new passing tests); PHPStan `[OK] No errors` (188 files) | none needed |
| 6.1 | `TarifTarifaOpcionalEtiquetaTest` authored before the seam; RED = `Tests: 18, ..., Failures: 10` overall, this file's `test_normalizer_trims_blanks_and_deduplicates` failed with `missing normalization seam: ...::normalizar_etiquetas()` (no syntax/setup error) | `normalizar_etiquetas()` extracted from `replace_etiquetas_opcional` and wired; file GREEN | extraction preserves the exact trim/dedupe/insertion-order semantics |
| 6.2 | `TarifTarifaOpcionalFamiliaTest` authored before the seam; RED = 3 failures (`test_is_activo_inherits...`, `test_explicit_false...`, `test_base_relation_absent...`) all `missing inheritance seam: ...::base_relation_exists()` | `base_relation_exists()` seam added and used by `is_activo_en_tarifa`; file GREEN; query-level inheritance predicates asserted | inheritance semantics unchanged (missing row ⇒ active; explicit FALSE ⇒ disabled) |
| 6.3 | `TarifTarifaOpcionalResolverTest` authored before the seam; RED = 4 failures (`test_untagged...`, `test_tagged...`, `test_every_opcional_without...`, `test_get_opcionales_activos_articulo_applies_the_filter_helper`) — missing `filtrar_por_etiquetas()` / wiring string | Pure `filtrar_por_etiquetas()` extracted and `get_opcionales_activos_articulo` wired to it; file GREEN | filter extracted verbatim from the inline loop; untagged ⇒ included, tagged ⇒ intersection only |
| 6.4 | `TarifTarifaArticuloOpcionalTest` authored before the seam; RED = 2 failures (`test_sync_articulo_overrides_persists...`, `test_sync_articulo_overrides_short_circuits...`) both `missing factory seam: ...::tarifa_articulo_opcional_model()` | `tarifa_articulo_opcional_model()` factory seam added and used by `sync_articulo_overrides`; file GREEN; counts/INSERT values asserted | sync algorithm unchanged; only the model construction moved behind a seam |
| 6.5 | **DEFERRED by user** — not authored, not run | N/A | N/A — `plugins/tpvmod/` untouched |
| 6.6 | Covered by the 6.1–6.4 RED files (authored before the seams) | All three moved files adjusted within their own bodies only; `php -l` clean on all three; `TarifConfiguradorOpcionalesTest` (which pins the resolver source) still GREEN | none needed |
| 6.7 | The retired/repointed test failures were the S5 RED baseline: tarifario `Tests: 166, Errors: 6, Failures: 1` from exactly these 4 files | 2 `git rm` + 2 require repoints + 1 `table_name` repoint; tarifario suite `OK, but some tests were skipped! Tests: 187, Assertions: 702, Skipped: 3` (0 errors/failures) | none needed |
| 6.8 | Gate was already GREEN after S3; re-run as an acceptance lock | `DeadOpcionalTableReferenceTest` GREEN (4 tests, 21 assertions); raw grep zero hits; FK XMLs `catalogo_opcionales (id)` | none needed |
| 6.9 | Unit acceptance authored with the 4 S5 files; RED as recorded for 6.1–6.4 | Targeted `OK (37 tests, 173 assertions)`; catalogo_core full suite `354 tests, 1018 assertions, 0 failures`; PHPStan `[OK] No errors` | none needed |
| EXTRA | No test (out-of-gate tooling); verified with `python3 -m py_compile` | SQL repointed to the canonical schema; compile OK | none needed |

No task was completed without a test written first.

> S2 honesty note: the 3 migration asserts in 3.1, the controller-require assert in 3.2 and the whole 3.4 history-page test are **green-on-authoring** because they lock behavior already delivered by the pre-existing `CatalogLegacyTableMigration` and the S1a move. Every behavior *introduced* by S2 (adapter base/table, `codlista` reads, history seam, raw-SQL repoints) produced a RED failure before implementation.

### RED evidence (before implementation)

S1a:
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php \
    plugins/catalogo_core/tests/InitOpcionalesTablesTest.php \
    plugins/catalogo_core/tests/DeadOpcionalTableReferenceTest.php \
    plugins/catalogo_core/tests/Services/TarifOpcionalExtMigrationTest.php
FAILURES!
Tests: 22, Assertions: 42, Failures: 20.
```

Every failure was a missing `plugins/catalogo_core/...` path/class or a counted gate hit — no syntax/setup errors. The gate test reported the full 11 hits:

```
plugins/tarifario/controller/tarif_articulos.php:2267,2271,2368,2372   (4)
plugins/tarifario/model/table/tarif_articulo_opcional.xml:38           (1)
plugins/tarifario/model/table/tarif_opcional_familia.xml:32            (1)
plugins/tarifario/model/table/tarif_tarifa_articulo_opcional.xml:55    (1)
plugins/tarifario/model/table/tarif_tarifa_opcional_familia.xml:49     (1)
plugins/tarifario/model/tarif_tarifa_articulo_opcional.php:190,217     (2)
plugins/tarifario/model/tarif_tarifa_opcional_resolver.php:120         (1)
```

S1b (task 2.1, authored BEFORE the move):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php
FAILURES!
Tests: 5, Assertions: 5, Failures: 5.

1) ...test_all_four_controllers_are_declared_in_catalogo_core
   Failed asserting that file ".../plugins/catalogo_core/controller/tarif_opcionales.php" exists.
2) ...test_all_four_controllers_extend_fbase_controller
   missing catalogo_core path: plugins/catalogo_core/controller/tarif_opcionales.php (moved controller not present)
3) ...test_all_four_controllers_have_zero_tarif_controller_references
4) ...test_all_four_controllers_have_zero_tarifario_opcional_requires
5) ...test_all_four_controllers_use_the_opcional_state_trait
```

Every S1b RED failure is "missing catalogo_core path" — no syntax/setup error.

S2 (tasks 3.1–3.4 + flipped ownership row, authored BEFORE implementation):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/OpcionalPriceUnificationTest.php \
    plugins/catalogo_core/tests/TarifOpcionalPreciosControllerTest.php \
    plugins/catalogo_core/tests/TarifOpcionalPrecioHistorialTest.php \
    plugins/catalogo_core/tests/TarifHistorialPreciosControllerTest.php \
    plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php
FAILURES!
Tests: 19, Assertions: 91, Failures: 7.

1) OpcionalPriceUnificationTest::test_adapter_reads_the_unified_canonical_table
2) TarifOpcionalPreciosControllerTest::test_adapter_extends_the_canonical_price_model
3) TarifOpcionalPreciosControllerTest::test_adapter_get_reads_by_codlista
4) TarifOpcionalPreciosControllerTest::test_adapter_does_not_create_a_physical_legacy_price_table
5) TarifOpcionalPrecioHistorialTest::test_changed_price_appends_exactly_one_history_row
6) TarifOpcionalPrecioHistorialTest::test_unchanged_price_appends_no_history_row
7) OpcionalDomainModelOwnershipTest::test_moved_models_keep_their_table_names
```

Every S2 RED failure is "adapter still extends `fs_model` / queries the dead `codtarifa` column / missing history seam" — no syntax or setup error. The 3 migration tests of 3.1 and all 4 of 3.4 passed on authoring (behaviour lock, see honesty note).

S3 (task 4.1, authored BEFORE the fork):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/TarifConfiguradorOpcionalesTest.php
FAILURES!
Tests: 15, Assertions: 31, Failures: 12.

1)  test_configurator_has_zero_tarifario_requires_and_uses
2)  test_configurator_declares_the_fork_consts
3)  test_configurator_declares_the_private_sql_helpers
4)  test_configurator_all_from_familia_call_sites_use_the_helper
5)  test_articulos_from_familia_reads_parameterized_from_the_stable_table
6)  test_articulos_from_familia_returns_empty_without_the_table
7)  test_etiquetas_articulo_reads_parameterized_from_the_stable_table
8)  test_precio_articulo_tarifa_reads_parameterized_price
9)  test_catalogo_articulo_opcional_exposes_the_direct_relocation_method
10) test_catalogo_opcional_familia_exposes_the_tarifario_relocation_method
11) test_resolver_forgoes_tarifario_classes
12) test_resolver_etiquetas_helper_reads_parameterized
```

Every S3 RED failure is a missing const/helper/method or the residual `plugins/tarifario/` dependency string — never a syntax or setup error. The 3 passing tests (`test_configurator_exposes_the_hierarchy_actions`, `test_htmx_tree_renders_the_tree_fragment`, `test_push_actions_return_json_payloads`) are behavior locks on the already-moved S1b controller.

S4 (tasks 5.1 + 5.2, authored BEFORE the markers):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php
FAILURES!
Tests: 11, Assertions: 25, Failures: 11.

1)  test_ventas_opcional_tabs_marker_sits_immediately_before_the_ul_tabs_close
2)  test_ventas_opcional_pane_marker_sits_immediately_before_the_tab_content_close
3)  test_ventas_articulo_tabs_marker_sits_immediately_before_the_ul_close
4)  test_ventas_articulo_pane_marker_sits_immediately_before_the_tab_content_close
5)  test_views_declare_exactly_the_four_frozen_names
6)  test_every_marker_uses_whitespace_control_and_the_frozen_context
7)  test_naming_pattern_is_derivable_and_has_no_bare_tarifa_token
8)  test_empty_registry_marker_contributes_exactly_zero_bytes
9)  test_registered_fragment_renders_unescaped_at_the_host_marker
10) test_frozen_context_keys_reach_the_hook_template
11) test_throwing_hook_template_is_swallowed_and_logged

$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml \
    --filter 'test_catalogo_host' plugins/tarifario/tests/Integration/HookRegistrationTest.php
FAILURES!
Tests: 2, Assertions: 3, Failures: 1.

1) test_catalogo_host_views_render_the_tarifas_tab_when_tarifario_is_active
   Saved opcional host view must inject the Tarifas tab header
```

Every S4 RED failure is a missing-marker failure (`No line containing 'render_hook(...'`, `All four frozen markers must be declared`, `must inject the Tarifas tab header`) — never a syntax or setup error. The catalogo_core host render already worked pre-marker, proving the harness itself is sound.

S5 (tasks 6.1–6.4, authored BEFORE the seams):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/TarifTarifaOpcionalEtiquetaTest.php \
    plugins/catalogo_core/tests/TarifTarifaOpcionalFamiliaTest.php \
    plugins/catalogo_core/tests/TarifTarifaOpcionalResolverTest.php \
    plugins/catalogo_core/tests/TarifTarifaArticuloOpcionalTest.php
FAILURES!
Tests: 18, Assertions: 56, Failures: 10.

1)  TarifTarifaOpcionalEtiquetaTest::test_normalizer_trims_blanks_and_deduplicates
    missing normalization seam: tarif_tarifa_opcional_etiqueta::normalizar_etiquetas()
2)  TarifTarifaOpcionalFamiliaTest::test_is_activo_inherits_active_when_no_per_tarifa_row
3)  TarifTarifaOpcionalFamiliaTest::test_explicit_false_disables_only_that_tarifa
4)  TarifTarifaOpcionalFamiliaTest::test_base_relation_absent_is_inactive
    (all three) missing inheritance seam: tarif_tarifa_opcional_familia::base_relation_exists()
5)  TarifTarifaOpcionalResolverTest::test_untagged_opcionales_are_included_and_tagged_only_on_intersection
6)  TarifTarifaOpcionalResolverTest::test_tagged_opcionales_are_excluded_when_the_article_has_no_tags
7)  TarifTarifaOpcionalResolverTest::test_every_opcional_without_a_tag_entry_is_treated_as_untagged
8)  TarifTarifaOpcionalResolverTest::test_get_opcionales_activos_articulo_applies_the_filter_helper
    missing resolver seam: tarif_tarifa_opcional_resolver::filtrar_por_etiquetas()
9)  TarifTarifaArticuloOpcionalTest::test_sync_articulo_overrides_persists_the_effective_set_with_counts
10) TarifTarifaArticuloOpcionalTest::test_sync_articulo_overrides_short_circuits_without_base_relations
    missing factory seam: tarif_tarifa_opcional_resolver::tarifa_articulo_opcional_model()
```

Every S5 RED failure is a missing-seam/helper failure — never a syntax or setup error. The 8 non-seam tests in the four files (replace behavior, query predicates, `set_activo` persistence, `sync_familia_overrides` aggregation, wiring) passed on authoring because S1a's verbatim move already implemented those behaviors; the seam-dependent tests are the ones that drive new authored production change.

### GREEN evidence (after implementation)

S1a tasks 1.1–1.3 (all GREEN):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php \
    plugins/catalogo_core/tests/InitOpcionalesTablesTest.php \
    plugins/catalogo_core/tests/Services/TarifOpcionalExtMigrationTest.php
OK (18 tests, 129 assertions)
```

S1a task 1.4 / 1.12 (gate — reduced to the deferred hits):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/DeadOpcionalTableReferenceTest.php
Tests: 4, Assertions: 21, Failures: 1.
Remaining hits (S3/S5 scope):
  plugins/tarifario/controller/tarif_articulos.php:2267
  plugins/tarifario/controller/tarif_articulos.php:2271
  plugins/tarifario/controller/tarif_articulos.php:2368
  plugins/tarifario/controller/tarif_articulos.php:2372
```

S1b task 2.1 (GREEN):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php
OK (5 tests, 28 assertions)
```

S1b full catalogo_core suite (regression):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
Tests: 296, Assertions: 762, Failures: 1, Warnings: 25, Skipped: 1.
```

Baseline before S1a: `Tests: 269, ... 0 failures`. After S1a: 291 tests, 1 failure (the deferred gate). After S1b: **296 tests** (291 + 5 new contract tests), still exactly **1 failure** — the same intentionally-deferred grep gate (4 tarifario `tarif_articulos.php` hits, S3/S5 scope). S1a+S1b introduce no new failures.

S1b syntax lint (4 controllers + trait): `php -l` → ALL LINT OK.

S1b runtime class-load harness (DB-free, `base/fs_controller.php` preloaded):
```json
{
  "tarif_opcionales":            {"exists": true, "parent": "fbase_controller", "trait": true, "has_init": true},
  "tarif_opcional_edit":         {"exists": true, "parent": "fbase_controller", "trait": true, "has_init": true},
  "tarif_opcional_precios":      {"exists": true, "parent": "fbase_controller", "trait": true, "has_init": true},
  "tarif_configurador_opcionales": {"exists": true, "parent": "fbase_controller", "trait": true, "has_init": true}
}
```

Raw gate audit after S1a:
```
$ grep -rnE '(FROM|JOIN|INTO|REFERENCES|UPDATE)[[:space:]]+`?(tarif_articulo_opcional|tarif_opcionales)`?([^_a-zA-Z0-9]|$)' \
    plugins/catalogo_core plugins/tarifario --include='*.php' --include='*.xml' \
    --exclude-dir=vendor --exclude-dir=openspec --exclude-dir=tests
=> 4 hits (tarifario/controller/tarif_articulos.php)
```

FK XMLs:
```
plugins/catalogo_core/model/table/tarif_tarifa_opcional_familia.xml:49   REFERENCES catalogo_opcionales (id)
plugins/catalogo_core/model/table/tarif_tarifa_articulo_opcional.xml:55  REFERENCES catalogo_opcionales (id)
```

S2 targeted GREEN (after the adapter rewrite + repoints):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/OpcionalPriceUnificationTest.php \
    plugins/catalogo_core/tests/TarifOpcionalPreciosControllerTest.php \
    plugins/catalogo_core/tests/TarifOpcionalPrecioHistorialTest.php \
    plugins/catalogo_core/tests/TarifHistorialPreciosControllerTest.php \
    plugins/catalogo_core/tests/OpcionalDomainModelOwnershipTest.php
OK (19 tests, 102 assertions)
```

S2 full catalogo_core suite (regression):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
Tests: 310, Assertions: 799, Failures: 1, Warnings: 25, Skipped: 1.
```
296 → **310 tests** (S2 adds 14 tests), still exactly **1 failure** — the same intentionally-deferred grep gate (4 tarifario `tarif_articulos.php` `tarif_articulo_opcional` hits, S3/S5 scope). S2 introduces no new failures.

S2 syntax lint (adapter + model + 3 tarifario controllers): `php -l` → ALL LINT OK.

S2 raw-SQL audit (task 3.7):
```
$ grep -rn "tarif_opcional_precios" plugins/tarifario/controller/
(no output)  →  zero dead-table references left in controllers

$ grep -n "catalogo_opcional_precios" plugins/tarifario/controller/{tarif_actualizar_precios,tarif_articulos,tarif_tarifas}.php
tarif_actualizar_precios.php:269,276  (SELECT ... FROM catalogo_opcional_precios op)
tarif_articulos.php:2152,2386,2390    (deleteTable/COUNT/DELETE catalogo_opcional_precios)
tarif_articulos.php:2454,2458         (WHERE codlista = ...)
tarif_tarifas.php:267,270,272         (COPY: DELETE/INSERT/SELECT canonical on codlista)
```

PHPStan: `[OK] No errors` (188/188 files).

S2 adapter contract (source):
```
class tarif_opcional_precio extends catalogo_opcional_precio   (model/tarif_opcional_precio.php:35)
parent::__construct('tarif_opcional_precios')                 → ABSENT (no physical legacy table)
model/tarif_opcional.php:247  INNER JOIN catalogo_opcional_precios op
model/tarif_opcional.php:248  WHERE op.codlista = ...
```

S3 targeted GREEN (after the fork):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/TarifConfiguradorOpcionalesTest.php
OK (15 tests, 79 assertions)
```

S3 gate GREEN (task 4.5):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/DeadOpcionalTableReferenceTest.php
OK (4 tests, 21 assertions)

$ grep -rnE '(FROM|JOIN|INTO|REFERENCES|UPDATE)[[:space:]]+`?(tarif_articulo_opcional|tarif_opcionales)`?([^_a-zA-Z0-9]|$)' \
    plugins/catalogo_core plugins/tarifario --include='*.php' --include='*.xml' \
    --exclude-dir=vendor --exclude-dir=openspec --exclude-dir=tests
(no output)  →  zero hits
```

S3 full catalogo_core suite (regression):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
OK, but there were issues!
Tests: 325, Assertions: 878, Warnings: 25, Skipped: 1.
```
310 → **325 tests** (S3 adds 15 tests), and the previously expected single failure (the deferred gate) is now **0 failures**.

S3 syntax lint (9 files): `php -l` → ALL LINT OK.

S3 tarifario suite (regression check on the repointed files):
```
$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
Tests: 164, Assertions: 535, Errors: 6, Failures: 1.
```
Identical to the pre-S3 baseline recorded in Residual Risk 7 (stale move-test files owned by S5 task 6.7): S3 introduces **zero new** tarifario failures.

S4 targeted GREEN (after the four markers):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php
OK (11 tests, 67 assertions)

$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml \
    plugins/tarifario/tests/Integration/HookRegistrationTest.php
OK (9 tests, 45 assertions)
```

S4 marker audit:
```
$ grep -c render_hook plugins/catalogo_core/View/ventas_opcional.html.twig
2
$ grep -c render_hook plugins/catalogo_core/View/ventas_articulo.html.twig
2
```

S4 full catalogo_core suite (regression):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
OK, but there were issues!
Tests: 336, Assertions: 945, Warnings: 25, Skipped: 1.
```
325 → **336 tests** (S4 adds 11), still **0 failures**.

S4 tarifario suite (regression):
```
$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
Tests: 166, Assertions: 558, Errors: 6, Failures: 1.
```
164 → **166 tests** (S4 adds 2, both passing), **identical** 6 errors + 1 failure baseline (pre-existing stale move-test files, S5 task 6.7). Zero new failures.

PHPStan (S4): `[OK] No errors` (188/188 files).

S5 targeted GREEN (after the seams):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    <4 S5 test files>
OK (18 tests, 73 assertions)
```

S5 acceptance GREEN (4 S5 tests + `TarifConfiguradorOpcionalesTest` + gate):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    <4 S5 tests> \
    plugins/catalogo_core/tests/TarifConfiguradorOpcionalesTest.php \
    plugins/catalogo_core/tests/DeadOpcionalTableReferenceTest.php
OK (37 tests, 173 assertions)
```

S5 full catalogo_core suite (regression):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
OK, but there were issues!
Tests: 354, Assertions: 1018, Warnings: 25, Skipped: 1.
```
336 → **354 tests** (S5 adds 18), still **0 failures**.

S5 tarifario suite (task 6.7 regression):
```
$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
OK, but some tests were skipped!
Tests: 187, Assertions: 702, Skipped: 3.
```
Baseline `166 tests, 6 errors, 1 failure` → **187 tests, 0 errors, 0 failures** (the two retired files removed 3 tests; the two repointed files now execute fully, +20 passing; net +21 tests).

S5 gate (task 6.8):
```
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml \
    plugins/catalogo_core/tests/DeadOpcionalTableReferenceTest.php
OK (4 tests, 21 assertions)

$ grep -rnE '(FROM|JOIN|INTO|REFERENCES|UPDATE)[[:space:]]+`?(tarif_articulo_opcional|tarif_opcionales)`?([^_a-zA-Z0-9]|$)' \
    plugins/catalogo_core plugins/tarifario --include='*.php' --include='*.xml' \
    --exclude-dir=vendor --exclude-dir=openspec --exclude-dir=tests
(no output)  →  zero hits
```

S5 syntax lint (3 moved files): `php -l` → ALL LINT OK. PHPStan: `[OK] No errors` (188/188 files).

EXTRA FIX:
```
$ python3 -m py_compile plugins/tarifario/tools/tarifa_excel_to_json.py
PY_COMPILE OK
```

---

## Work Unit Evidence

### S1a

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml <3 S1a test files>` → `OK (18 tests, 129 assertions)`; `DeadOpcionalTableReferenceTest` → `4 tests, 21 assertions, 1 failure` (the 4 deferred tarifario hits) |
| Runtime harness command/scenario and exact result | `N/A` — S1a establishes DB-free ownership/bootstrap contracts; the live create path is the verify-phase dev-DB smoke (design D9: `InitOpcionalesTablesTest` is DB-free by design). No runtime boundary is introduced in this slice. |
| Rollback boundary | Revert the staged catalogo_core S1a paths (8 models + 5 XMLs + service + `Init.php` + 4 tests) and the corresponding `git rm` in tarifario (14 moved + 6 dead); canonical `catalogo_opcionales` tables/data untouched. |

### S1b

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/TarifOpcionalesControllerContractTest.php` → `OK (5 tests, 28 assertions)`. Full suite → `296 tests, 762 assertions, 1 failure` (the same deferred gate as S1a; zero new failures). |
| Runtime harness command/scenario and exact result | DB-free class-load harness preloading `base/fs_controller.php`: all 4 moved controllers `class_exists=true`, `get_parent_class()=fbase_controller`, `class_uses() contains TarifarioOpcionalStateTrait`, `method_exists('init_tarifario_opcional_state')=true`. Full HTTP render is the verify-phase smoke (authenticated session), tracked in Residual Risks. |
| Rollback boundary | Revert the staged S1b catalogo_core paths (4 controllers + 7 views/partials + trait + contract test) and restore the 11 `git rm`'d tarifario paths; S1a models/bootstrap and canonical tables/data are untouched. |

### S2

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml <4 S2 tests + OpcionalDomainModelOwnershipTest>` → `OK (19 tests, 102 assertions)`. Full catalogo_core suite → `310 tests, 799 assertions, 1 failure` (the same deferred grep gate owned by S3/S5; zero new failures). `php -l` clean on the adapter + model + 3 tarifario controllers; `ddev exec composer phpstan` → `[OK] No errors` (188 files). |
| Runtime harness command/scenario and exact result | DB-free adapter/history harnesses: `PrecioQuerySpyDb` proves `get()` emits `codlista` and never `codtarifa`; the anonymous tracked model proves `save()` appends exactly one history row for a changed price and none for an unchanged one; `PriceUnificationFakeDb` runs `CatalogLegacyTableMigration::migrateIfNeeded` twice and proves `codtarifa → codlista` mapping + the default price list + no-op second run. Live HTTP render of the price page is the verify-phase smoke. |
| Rollback boundary | Revert the S2 files only: catalogo_core `model/tarif_opcional_precio.php`, `model/tarif_opcional.php`, the 4 new tests + the `OpcionalDomainModelOwnershipTest` row flip; tarifario `controller/tarif_actualizar_precios.php`, `controller/tarif_articulos.php` (5 price sites), `controller/tarif_tarifas.php`. Canonical `catalogo_opcional_precios` table/data and the legacy residual table are untouched (no down-migration). |

### S3

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/TarifConfiguradorOpcionalesTest.php` → `OK (15 tests, 79 assertions)`. Gate `DeadOpcionalTableReferenceTest` → `OK (4 tests, 21 assertions)`. Full catalogo_core suite → `325 tests, 878 assertions, 0 failures` (the deferred gate failure is gone). |
| Runtime harness command/scenario and exact result | DB-free `ConfiguradorQuerySpyDb` + anonymous controller/model subclasses: the three helpers emit parameterized SQL against the stable table names and return `[]`/fallback without `table_exists()`; the additive relocation methods emit `?`-parameterized SELECTs joined to `catalogo_opcionales` and hydrate `tarif_opcional`; the resolver helper reads `tarif_tarifa_articulo_etiqueta` parameterized. Full authenticated HTTP render of the four slugs remains the verify-phase dev-DB smoke. |
| Rollback boundary | Revert the S3 files only: catalogo_core `controller/tarif_configurador_opcionales.php`, `controller/tarif_opcional_edit.php`, `model/tarif_tarifa_opcional_resolver.php`, `model/core/catalogo_articulo_opcional.php`, `model/core/catalogo_opcional_familia.php` and the new `tests/TarifConfiguradorOpcionalesTest.php`; tarifario `controller/tarif_articulos.php`, `controller/tarif_articulo_precios.php`, `controller/tarif_catalogo_view.php`, `model/tarif_articulo.php`. Pure fork + additive methods; canonical tables/data untouched. |

### S4

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` → `OK (11 tests, 67 assertions)`; `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml plugins/tarifario/tests/Integration/HookRegistrationTest.php` → `OK (9 tests, 45 assertions)`. Full catalogo_core suite → `336 tests, 945 assertions, 0 failures`; full tarifario suite → `166 tests, 6 errors, 1 failure` (identical pre-existing S5 baseline). PHPStan `[OK] No errors` (188 files). |
| Runtime harness command/scenario and exact result | Real host-view render (no DB): `CatalogoCoreHookMarkersTest` compiles the authored marker expressions and renders both real host views through a Twig environment wired like `src/Core/Html.php` (`render_hook` `is_safe:['html']`, stubbed theme/partial shells): empty registry → `''`; registered `<b>` fragment renders unescaped; a throwing template is swallowed and `[ViewHookRegistry] Error rendering hook ...` is logged; `CTX[7\|USR-SENTINEL\|EMP-SENTINEL\|I18N-SENTINEL]` proves the frozen context reaches the hook. `HookRegistrationTest` renders the same real host views with the Init-registered `@tarifario` namespace + four hooks: saved opcional + existing article inject the Tarifas header inside the tab list and the pane inside `.tab-content`; unsaved opcional injects nothing. Full authenticated HTTP render remains the verify-phase smoke. |
| Rollback boundary | Revert the 4 marker lines in `View/ventas_opcional.html.twig` and `View/ventas_articulo.html.twig` and the two test files (`tests/Integration/CatalogoCoreHookMarkersTest.php`, the `HookRegistrationTest.php` additions). Host views are otherwise untouched; no core, model, DB or config change. |

### S5

| Evidence | Value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml <4 S5 tests>` → `OK (18 tests, 73 assertions)`. Acceptance (`+ TarifConfiguradorOpcionalesTest + DeadOpcionalTableReferenceTest`) → `OK (37 tests, 173 assertions)`. Full catalogo_core suite → `354 tests, 1018 assertions, 0 failures`. Full tarifario suite → `187 tests, 702 assertions, 0 errors, 0 failures`. `php -l` clean on the 3 moved files; PHPStan `[OK] No errors` (188 files). |
| Runtime harness command/scenario and exact result | DB-free spy-DB harnesses: `EtiquetaQuerySpyDb` proves `replace_etiquetas_opcional` delete-then-insert of the normalized set and per-tarifa isolation in the emitted SQL; `FamiliaQuerySpyDb` proves the inheritance seam + the `tof.activo IS NULL OR tof.activo = TRUE` / `NOT EXISTS (... tof.activo = FALSE)` predicates and the per-tarifa `set_activo` INSERT; the resolver pure `filtrar_por_etiquetas` proves untagged-included + tag-intersection; `ArticuloOpcionalSpyDb` + the factory seam prove `sync_articulo_overrides` writes one row per base relation with `activado/desactivado` counts and `sync_familia_overrides` aggregates per-article counts with a parameterized reference query. The full authenticated HTTP render remains the verify-phase smoke. |
| Rollback boundary | Revert the S5 files independently: catalogo_core `tests/TarifTarifaOpcional{Etiqueta,Familia,Resolver,ArticuloOpcional}Test.php` + the 3 seam edits (`model/tarif_tarifa_opcional_etiqueta.php`, `model/tarif_tarifa_opcional_familia.php`, `model/tarif_tarifa_opcional_resolver.php`); tarifario `tests/Controller/{TarifTabPrecios,TarifArticulosFamiliaImport}Test.php`, the restored `tests/Model/TarifOpcionalFamiliaConsumerReadTest.php` + `tests/Services/TarifOpcionalExtMigrationTest.php`, and `tools/tarifa_excel_to_json.py`. Canonical tables/data untouched. |

---

## Files

### S1a — added / moved into `plugins/catalogo_core` (staged, explicit paths)

Models (`model/`): `tarif_opcional.php`, `tarif_opcional_ext.php`, `tarif_opcional_precio.php`, `tarif_opcional_precio_historial.php`, `tarif_tarifa_opcional_etiqueta.php`, `tarif_tarifa_opcional_familia.php`, `tarif_tarifa_articulo_opcional.php`, `tarif_tarifa_opcional_resolver.php`.

XML (`model/table/`): `tarif_opcional_ext.xml`, `tarif_opcional_precio_historial.xml`, `tarif_tarifa_opcional_etiqueta.xml`, `tarif_tarifa_opcional_familia.xml`, `tarif_tarifa_articulo_opcional.xml`.

Service (`Services/`): `TarifOpcionalExtMigration.php` (namespace → `FSFramework\Plugins\catalogo_core\Services`; require → catalogo_core ext model).

Tests (`tests/`): `OpcionalDomainModelOwnershipTest.php`, `InitOpcionalesTablesTest.php`, `DeadOpcionalTableReferenceTest.php`, `Services/TarifOpcionalExtMigrationTest.php`.

Modified: `Init.php` (+72 lines: `use` import, `migrateOpcionalExtension()` in `init()`/`upgrade()`, `ensureOpcionalesTarifaTables()` with the catalog-dependency order fix).

### S1a — removed from `plugins/tarifario` (`git rm`, staged)

Moved (14): the 8 models, 5 XMLs and the service above.
Dead (6): `model/table/tarif_opcionales.xml`, `model/table/tarif_opcional_familia.xml`, `model/table/tarif_articulo_opcional.xml`, `model/table/tarif_opcional_precios.xml`, `model/tarif_opcional_familia.php`, `model/tarif_articulo_opcional.php`.

### S1a — modified in `plugins/tarifario` (unstaged)

- `Init.php` — removed `use ...TarifOpcionalExtMigration`, the `init()` call and the `migrateOpcionalExtension()` method.
- `extras/tarifario_init.php` — repointed the historial require; pruned moved-table instantiations and `codtarifa` table-list entries.
- Controllers (require repoints to catalogo_core): `tarif_tarifas.php`, `tarif_catalogo_view.php`, `tarif_actualizar_precios.php`, `tarif_articulos.php`, `tarif_articulo_precios.php`, `tarif_tab_precios.php`, `tarif_historial_precios.php`.

### S1a — in-place latent fixes on the moved files

- `tarif_opcional.php` — internal requires repointed to catalogo_core.
- `tarif_tarifa_opcional_familia.php` — `new tarif_opcional_familia()` → `new catalogo_opcional_familia()` (install + inheritance check).
- `tarif_tarifa_articulo_opcional.php` — `new tarif_articulo_opcional()` → `new catalogo_articulo_opcional()` (install + inheritance check); two raw SQL hits → `catalogo_articulo_opcional`; docblock.
- `tarif_tarifa_opcional_resolver.php` — added `const TARIFA_ARTICULO_TABLE`; `:120` → `catalogo_articulo_opcional`; `:159` SQL extracted to the const + parameterized query.
- Two moved XMLs — FK `id_opcional` repointed to `catalogo_opcionales (id)`.

### S1b — added / moved into `plugins/catalogo_core` (staged, explicit paths)

Controllers (`controller/`, moved + reclassed): `tarif_opcionales.php`, `tarif_opcional_edit.php`, `tarif_opcional_precios.php`, `tarif_configurador_opcionales.php`.

Views (`View/`, byte-identical move): `tarif_opcionales.html.twig`, `tarif_opcional_edit.html.twig`, `tarif_opcional_precios.html.twig`, `tarif_configurador_opcionales.html.twig`, `tarif_configurador_tree.html.twig`, `tarif_opcional.html.twig`, `partials/configurador/styles.html.twig`.

New: `extras/TarifarioOpcionalStateTrait.php` (matches the prior verified artifact byte-for-byte).

Test: `tests/TarifOpcionalesControllerContractTest.php` (matches the prior verified artifact byte-for-byte).

### S1b — removed from `plugins/tarifario` (`git rm`, staged)

11 paths: the 4 controllers + the 6 named views + `View/partials/configurador/styles.html.twig`.

### S1b — in-place reclass edits on the moved controllers

Each of the four controllers: docblock `part of tarifario` → `part of catalogo_core`; `require_once .../tarif_controller.php` deleted and replaced by `require_once .../TarifarioOpcionalStateTrait.php`; moved-model requires repointed to `plugins/catalogo_core/model/...`; deleted-wrapper requires (`tarif_articulo_opcional.php`, `tarif_opcional_familia.php`) removed; `extends tarif_controller` → `extends fbase_controller` + `use TarifarioOpcionalStateTrait;`; `$this->init_tarifario_opcional_state();` added right after the kept `parent::private_core();`. No other body line changed (S2/S3/S4/S5 edits deliberately out of scope).

### S2 — modified in `plugins/catalogo_core`

- `model/tarif_opcional_precio.php` — rewritten as the thin canonical adapter (428 → 338 lines): `extends \FSFramework\model\catalogo_opcional_precio`, `codtarifa` aliased to `codlista` (`__get`/`__set`/`__isset`), `save()` read-modify-write + `registrar_cambio_historial()` seam, `all_from_tarifa`/`delete_from_tarifa`/`delete_precio`/`all`/`aplicar_porcentaje_masivo`/`all_by_familias`/`count_by_familias` repointed to `codlista`. No physical `tarif_opcional_precios`.
- `model/tarif_opcional.php` — `search()` canonical join (`catalogo_opcional_precios` + `op.codlista`) instead of the dead `tarif_opcional_precios`/`codtarifa`.
- `tests/OpcionalDomainModelOwnershipTest.php` — flipped the `tarif_opcional_precio` row to `catalogo_opcional_precios` / base `catalogo_opcional_precio` (executes the S1a slice-note contract).

### S2 — added tests (`plugins/catalogo_core/tests/`)

`OpcionalPriceUnificationTest.php`, `TarifOpcionalPreciosControllerTest.php`, `TarifOpcionalPrecioHistorialTest.php`, `TarifHistorialPreciosControllerTest.php`.

### S2 — modified in `plugins/tarifario` (raw-SQL repoints)

- `controller/tarif_actualizar_precios.php:260,269,276` — `op.codtarifa` → `op.codlista`; `FROM catalogo_opcional_precios op`.
- `controller/tarif_articulos.php:2152,2386,2390,2454,2458` — `catalogo_opcional_precios`; `:2454,:2458` `codtarifa` → `codlista`.
- `controller/tarif_tarifas.php::copy_precios_opcionales()` (:267,270,272) — canonical DELETE/INSERT/SELECT on `codlista`.

### S3 — modified in `plugins/catalogo_core`

- `controller/tarif_configurador_opcionales.php` — D4 fork: dropped the four `plugins/tarifario/model/*` requires + the three tarifario `use`s + the `tarif_tarifa_articulo` property/instantiation; declared `TARIFA_ARTICULO_TABLE`/`TARIFA_ARTICULO_ETIQUETA_TABLE`/`TARIFA_ARTICULO_PRECIO_TABLE` + the private parameterized helpers `articulos_from_familia()`/`etiquetas_articulo()`/`precio_articulo_tarifa()` (`table_exists()`-guarded); repointed `tarif_articulo_opcional` → `catalogo_articulo_opcional`, `tarif_opcional_familia` → `catalogo_opcional_familia`, `tarif_articulo` → `articulo`, `precio_en_tarifa()` → `precio_articulo_tarifa()`, `all_from_familia()` → `articulos_from_familia()`, `get_etiquetas_articulo()` → `etiquetas_articulo()`, direct read → `get_opcionales_directos_from_articulo()`.
- `model/tarif_tarifa_opcional_resolver.php` — install() no longer instantiates the tarifario etiqueta class; added `const TARIFA_ARTICULO_ETIQUETA_TABLE` + private parameterized `etiquetas_articulo()`; kept the S1a `const TARIFA_ARTICULO_TABLE` + parameterized query.
- `model/core/catalogo_articulo_opcional.php` — additive `get_opcionales_directos_from_articulo($referencia)` (parameterized, hydrates `tarif_opcional`).
- `model/core/catalogo_opcional_familia.php` — additive `get_opcionales_tarifario_from_familia($codfamilia)` (parameterized, hydrates `tarif_opcional`).
- `controller/tarif_opcional_edit.php` — same dependency-fork root cause (Residual Risk 3): `use`/instantiation of the deleted `tarif_articulo_opcional` wrapper → `catalogo_articulo_opcional` + the catalogo_core require.

### S3 — added test (`plugins/catalogo_core/tests/`)

`TarifConfiguradorOpcionalesTest.php` (15 tests / 79 assertions) — D4 fork contract, hierarchy action dispatch, helper behavior via `ConfiguradorQuerySpyDb`, additive relocation methods, resolver fork.

### S3 — modified in `plugins/tarifario` (dead-table repoints)

- `controller/tarif_articulos.php` — `use` + `deleteTable` + 4 raw-SQL sites → `catalogo_articulo_opcional`.
- `controller/tarif_articulo_precios.php` — `use`s → `catalogo_articulo_opcional`/`catalogo_opcional_familia`; `add_opcional`/`remove_opcional` → `catalogo_articulo_opcional`; family read → `get_opcionales_tarifario_from_familia`.
- `controller/tarif_catalogo_view.php` — `use`s + `$art_opc_model`/`$rel_model` → `catalogo_articulo_opcional`/`catalogo_opcional_familia`.
- `model/tarif_articulo.php` — `get_opcionales()` → `catalogo_articulo_opcional` + `get_opcionales_directos_from_articulo`.

### S4 — modified in `plugins/catalogo_core`

- `View/ventas_opcional.html.twig` — inserted `{{- render_hook('ventas_opcional_tabs_after', {'fsc': fsc, 'user': user, 'empresa': empresa, 'i18n': i18n}) -}}` immediately before the `#ul_tabs` `</ul>` and `{{- render_hook('ventas_opcional_tab_pane_after', ...) -}}` immediately before the `.tab-content` `</div>` (+2 lines).
- `View/ventas_articulo.html.twig` — inserted `{{- render_hook('ventas_articulo_tabs_after', ...) -}}` immediately before the `#tab_articulo` `</ul>` and `{{- render_hook('ventas_articulo_tab_pane_after', ...) -}}` immediately before its `.tab-content` `</div>` (+2 lines).

### S4 — added tests

- `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` (new, 11 tests / 67 assertions) — frozen positions, exactly four names, whitespace control + frozen context, naming derivation + no bare `tarifa`, empty-registry zero bytes, unescaped registered fragment in the real host views, swallowed + logged throwing template, context propagation.
- `plugins/tarifario/tests/Integration/HookRegistrationTest.php` (+199 lines, 2 new tests) — renders the real catalogo_core host views with the Init-registered `@tarifario` namespace and the four hooks: saved opcional + existing article inject the Tarifas header/pane; unsaved opcional injects nothing.

### S5 — added tests (`plugins/catalogo_core/tests/`)

- `TarifTarifaOpcionalEtiquetaTest.php` (4 tests / 15 assertions) — normalization seam, replace persistence + idempotence, per-tarifa isolation, stored-set read.
- `TarifTarifaOpcionalFamiliaTest.php` (6 tests / 27 assertions) — inheritance seam (missing row ⇒ active; explicit FALSE ⇒ disabled; base absent ⇒ inactive), query-level inheritance predicates, per-tarifa `set_activo` write.
- `TarifTarifaOpcionalResolverTest.php` (4 tests / 15 assertions) — pure tag-intersection filter (untagged included; tagged only on intersection; empty article tags exclude tagged) + wiring contract.
- `TarifTarifaArticuloOpcionalTest.php` (4 tests / 16 assertions) — `sync_articulo_overrides` effective-set persistence with counts, short-circuit without base relations, `sync_familia_overrides` aggregation + parameterized reference query.

### S5 — modified in `plugins/catalogo_core` (behavior-preserving seams)

- `model/tarif_tarifa_opcional_etiqueta.php` — extracted private `normalizar_etiquetas($etiquetas)` and wired `replace_etiquetas_opcional()` to it.
- `model/tarif_tarifa_opcional_familia.php` — added protected `base_relation_exists($id_opcional, $codfamilia)` and wired `is_activo_en_tarifa()` to it.
- `model/tarif_tarifa_opcional_resolver.php` — extracted private `filtrar_por_etiquetas()` and wired `get_opcionales_activos_articulo()` to it; added protected `tarifa_articulo_opcional_model()` factory and wired `sync_articulo_overrides()` to it.

### S5 — modified/removed in `plugins/tarifario`

- `tests/Controller/TarifTabPreciosTest.php` — require → `plugins/catalogo_core/model/tarif_opcional_precio.php`; tracked model `table_name` → `catalogo_opcional_precios`.
- `tests/Controller/TarifArticulosFamiliaImportTest.php` — require → `plugins/catalogo_core/model/tarif_opcional_precio.php`.
- `tests/Model/TarifOpcionalFamiliaConsumerReadTest.php` — **removed** (`git rm`; covered the deleted `tarif_opcional_familia` wrapper).
- `tests/Services/TarifOpcionalExtMigrationTest.php` — **removed** (`git rm`; already moved to `plugins/catalogo_core/tests/Services/`).
- `tools/tarifa_excel_to_json.py` — `export_precios_opcionales` SQL repointed from `tarif_opcionales`/`tarif_opcional_precios (codtarifa)` to `catalogo_opcionales` + `LEFT JOIN tarif_opcional_ext` + `catalogo_opcional_precios (codlista)`; downstream column aliases preserved.

---

## Deviations from Design

1. **Ownership test price row is slice-scoped (S1a).** `OpcionalDomainModelOwnershipTest` pins `tarif_opcional_precio` to its S1a (byte-identical legacy) table `tarif_opcional_precios` / `extends fs_model`. Design D3 / task 3.5 (S2) rewrites that model as the canonical adapter (`extends catalogo_opcional_precio`, table `catalogo_opcional_precios`); **S2 MUST flip that single `MODELS` row** (documented in the test's class docblock). Without this scoping, S1a could not be green while keeping the move byte-identical per task 1.6.
2. **Added one test beyond the literal 1.2 list (S1a)**: `test_bootstrap_touches_canonical_dependencies_in_fk_safe_order` locks the user-mandated ordering fix (`catalogo_lista_precio` before `catalogo_opcional` before `catalogo_articulo_opcional`), which was WARNING-3 in the prior verify report.
3. **S1b state init call is explicit.** Task 2.4 lists the reclass edits but not the `$this->init_tarifario_opcional_state();` line. PHP trait precedence makes a trait-level `private_core()` unreachable when the class declares its own `private_core()` (the class method wins over the trait, and `parent::private_core()` resolves to `fbase_controller`), so the trait exposes an explicitly-called additive initializer. This matches the prior verified implementation (trait docblock: "Additive only: it never replaces fbase_controller::private_core() nor the host controller's own private_core()"). No behavior is lost relative to `tarif_controller::private_core()`.
4. **S2 executes the S1a slice-note flip (by design).** The ownership test row for `tarif_opcional_precio` was authored slice-scoped in S1a (legacy `tarif_opcional_precios`/`fs_model`) and is flipped here to `catalogo_opcional_precios`/`catalogo_opcional_precio`, exactly as the S1a slice note and design D3 required. The S2 batch also repoints `tarif_tarifas.php::copy_precios_opcionales()`, which the design's raw-SQL list implied but task 3.7 did not spell out (same dead-table break: `tarif_opcional_precios`/`codtarifa`); without it the price-copy path would silently target the dead table.

5. **S3 follows the design and adds one same-root-cause repoint beyond the literal task 4.5 list.** The fork, the three `const`s, the three private helpers, both additive relocation methods and the resolver fork match design D4's replacement tables and the byte-faithful reference implementation. The one addition is `catalogo_core/controller/tarif_opcional_edit.php` (a moved controller still referencing the deleted `tarif_articulo_opcional` wrapper, flagged as Residual Risk 3): task 4.5 was scoped to "the remaining dead-table/raw consumers" and the dead-table gate reaches zero without it, but the moved controller cannot run with a deleted class and the byte-faithful reference includes the fix. It is a superset of the same dependency-fork root cause, not a new capability. The two `catalogo_*` additive methods are named `get_opcionales_directos_from_articulo` / `get_opcionales_tarifario_from_familia` so they do not collide with the existing catalogo-typed `get_opcionales_from_articulo` / `get_opcionales_from_familia` base methods. The D4 helper queries return `stdClass{referencia}` rows (the configurator only reads `->referencia`), preserving the `all_from_familia()` consumer contract without instantiating the removed `tarif_tarifa_articulo` model.

6. **S4 renders the real host views with a stubbed shell instead of a full framework boot.** Design D8 states the hosts are rendered by the catalogo_core PSR-4 controllers with `user`/`empresa`/`i18n` from `src/Core/Base/Controller.php`. The S4 tests load the real host view bodies (so the authored markers are exercised verbatim) and render them in a Twig environment wired exactly like `src/Core/Html.php` (`render_hook` `is_safe:['html']`), with the theme/partial includes stubbed and a minimal `fsc` stand-in. The `HookRegistrationTest` extension uses a `ChainLoader` ([stubbed shells, Init-registered `@tarifario` namespace]) so the real `@tarifario/Hooks/*` templates resolve end-to-end. This keeps the assertions DB-free and deterministic; the full authenticated HTTP render remains the verify-phase smoke (Residual Risk 6). No spec requirement is weakened — the marker render contract is exercised against the real view bytes.

7. **S5 adds three behavior-preserving testability seams inside the moved files.** The moved tag/activation/resolver code was a verbatim move whose spec behaviors were already satisfied, so the S5 RED tests target small extractions that make those behaviors observable without a live database: `normalizar_etiquetas()`, `base_relation_exists()`, `filtrar_por_etiquetas()` and `tarifa_articulo_opcional_model()`. Each extraction is a pure refactor of the exact inline logic it replaces (same SQL, same predicates, same ordering) and stays within the moved files per task 6.6. No slug, class name, table name, public signature or behavior changed. Seam-dependent tests drive the new authored change; the remaining 8 tests are behavior locks on the S1a move (consistent with the accepted verify-report note on S5 green-on-authoring locks).

8. **S5 retires (not repoints) the stale `tarifario/tests/Services/TarifOpcionalExtMigrationTest.php`.** That file was already copied into catalogo_core with its namespace/paths repointed in S1a (task 1.3); the tarifario original still referenced the moved service + moved `plugins/tarifario/model/...` paths and was the sole source of one error + one failure in the tarifario suite. Design D9 says the test "moves"; the duplicate was therefore removed rather than repointed. Same disposition for `TarifOpcionalFamiliaConsumerReadTest` (covers the deleted wrapper).

9. **EXTRA FIX beyond the literal task list (user-mandated).** `plugins/tarifario/tools/tarifa_excel_to_json.py::export_precios_opcionales` was repointed to the canonical schema (it was SUGGESTION 1 of the prior verify report and outside the grep gate's php/xml scope). No test covers it (it is an offline tool); `python3 -m py_compile` passes.

Everything else matches the design and `tasks.md` for S1b, S2, S3, S4 and S5 (excluding the user-deferred 6.5).

## Residual Risks

1. ~~**Gate RED-by-design**~~ — **RESOLVED by S3 (task 4.5)**: the 4 raw-SQL hits (`tarif_articulos.php:2267/2271/2368/2372`) plus the `deleteTable` site and the model/controller repoints bring the gate to **zero hits**; `DeadOpcionalTableReferenceTest` GREEN (4 tests, 21 assertions) and the catalogo_core suite has 0 failures.
2. ~~**Deleted wrappers still referenced by staying tarifario controllers**~~ — **RESOLVED by S3 (task 4.5)**: `tarif_articulos.php`, `tarif_articulo_precios.php`, `tarif_catalogo_view.php` and `model/tarif_articulo.php` now use `catalogo_articulo_opcional`/`catalogo_opcional_familia` (plus the additive relocation methods).
3. ~~**Moved controllers keep S3-owned model dependencies**~~ — **RESOLVED by S3 (task 4.2 + 4.5 superset)**: `tarif_configurador_opcionales.php` no longer requires/uses any `plugins/tarifario/model/tarif_*` class (per-tarifa relations read through the three D4 raw-SQL helpers), and `tarif_opcional_edit.php` no longer references the deleted `tarif_articulo_opcional` wrapper. A full authenticated render of the four slugs remains a verify-phase smoke item (see risk 6).
4. **`ref_sap`/`en_catalogo`/`en_tarifa` remain in `tarif_opcional_ext`** — additive/no-migration scope preserved; existing databases keep the pre-change FK to `tarif_opcionales` (documented separately in the prior verify report).
5. **`plugins/tpvmod/` was NOT touched.** Pre-existing unstaged tpvmod changes from another session remain in the worktree; they were neither staged nor modified by this batch.
6. **Full authenticated HTTP render not executed** for the four slugs — covered here by the DB-free class-load harness; the verify-phase dev-DB smoke remains the deeper check (WARNING-4 of the prior verify report).
7. ~~**Pre-existing stale tarifario test files (S1a move defect, NOT introduced by S2).**~~ — **RESOLVED by S5 (task 6.7)**: the two stale files were retired (`git rm`) and the two repointed; tarifario suite is now `187 tests, 0 errors, 0 failures`. Original note: the committed S1a/S1b slice left four tarifario test files broken: `tests/Services/TarifOpcionalExtMigrationTest.php` (requires the moved model + service), `tests/Controller/TarifTabPreciosTest.php` and `tests/Controller/TarifArticulosFamiliaImportTest.php` (require the moved `plugins/tarifario/model/tarif_opcional_precio.php`), and `tests/Model/TarifOpcionalFamiliaConsumerReadTest.php` (covers the deleted wrapper). Result: `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` → `Tests: 164, Errors: 6, Failures: 1` (4 files). Design D9 assigns their retirement/repoint to the move (task 1.3) / S5 (task 6.7).
8. **S2 leaves the dead-table grep gate RED-by-design (unchanged from S1b).** Replaced by S5's zero-hit re-run; original note kept only for the batch history.

## Workload / PR Boundary

- Mode: **stacked PR slice** (PR4 = S4, per design Delivery Slicing / `ask-on-risk`; PR1 = S1a+S1b, PR2 = S2 and PR3 = S3 already committed).
- Current work unit: **S4** (four frozen hook render markers + marker/registration tests). Committed base: `catalogo_core 683d57c` (S3), `tarifario bac412c` (S3), on top of `653f754`/`db07e55` (S2) and `8a8b063`/`e469f06` (S1a+S1b).
- Boundary: starts from the committed post-S3 tree; ends with the four D8 markers in the two catalogo_core host views, `CatalogoCoreHookMarkersTest` and the extended `HookRegistrationTest` authored and GREEN. S5 remains.
- Review budget: S4 changes **4 files** across the two repos — catalogo_core: 2 host views (+4 lines) and the new `tests/Integration/CatalogoCoreHookMarkersTest.php` (512 lines); tarifario: `tests/Integration/HookRegistrationTest.php` (+199 lines). Authored additions ≈ **715 lines**, above the 400-line budget. The overage is test harness, not production: the production diff is 4 lines. The two test files carry their own DB-free host-render harness (stub `fsc`/entity + stubbed theme shells) because the catalogo_core suite has no existing host-view render helper and plugin isolation forbids sharing one across the two repos. Shrinking further would mean deleting assertions the spec scenario list requires, so this slice lands honestly and requests **`size:exception`** for PR4 (or the orchestrator may accept the slice as-is).
- **No commit / no push performed** — the orchestrator commits the S4 slice (Phase 7, PR4).

### S5 slice (PR5)

- Mode: **stacked PR slice** (PR5 = S5).
- Current work unit: **S5** (etiquetas / activation / resolver tags / override-sync + gate + test retirement/repoint + python tool fix). Committed base: `catalogo_core 76de6df` (S4), `tarifario d628e03` (S4).
- Boundary: starts from the committed post-S4 tree; ends with the 4 new catalogo_core tests + 3 seam edits staged, the tarifario repoints/retires staged, and the `tarifa_excel_to_json.py` fix staged. **`tpvmod` was not touched and task 6.5 was not run.**
- Review budget: S5 changes **11 files** across the two repos. catalogo_core: 3 model seams (~95 authored lines incl. docblocks) + 4 test files (~860 lines). tarifario: 2 repointed tests, 2 retired tests, 1 tool fix (~20 lines). Authored additions ≈ **1,000 lines**, above the 400-line budget; the overage is DB-free test harness plus the seam docblocks, and the production diff is ~90 lines. A cohesive work unit with its verification; requests **`size:exception`** for PR5 (or the orchestrator may accept the slice as-is).
- **No commit / no push performed** — the orchestrator commits the S5 slice (Phase 7, PR5).
