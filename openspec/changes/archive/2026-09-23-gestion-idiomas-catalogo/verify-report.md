```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:3615b67bb5ffba1d0ef2dd24dfc4be58e693fda6221906acae9de196f256c4b4
verdict: fail
blockers: 1
critical_findings: 0
requirements: 18/18
scenarios: 54/54
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:431c4251f4decbe882d0cc3613e479326dea97ae090d94ff08e4332a9c33c4a9
build_command: ddev exec composer phpstan
build_exit_code: 1
build_output_hash: sha256:0c97c33e9ab251cbad32980fc19b42765173af52ee847914e5b70e9d1dd9bb1c
```

## Verification Report

**Change**: `gestion-idiomas-catalogo`
**Version**: N/A (delta spec; catalogo_core `fsframework.ini` untouched by this change)
**Mode**: Standard (plugin `config.yaml` declares `strict_tdd: true`; the RED→GREEN record is audited from `apply-progress.md`, but no `strict-tdd-verify.md` runner is wired into this phase — same disposition the sibling report recorded)
**SDD root**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`). Core `openspec/` holds **no** entry for this change (`grep -rl gestion-idiomas-catalogo openspec/` at the repo root → 0). The canonical specs under `plugins/catalogo_core/openspec/specs/**` and `plugins/tarifario/openspec/specs/**` were **not** edited (deltas merge only at archive).
**Evidence artifacts** (gitignored scratch): `tmp/verify_art/{cc_suite,tarifario_suite,hook_markers,root_plugins,phpstan}.txt`; `evidence_revision` is the SHA-256 of the sorted `basename:sha256` manifest of those five files.

### Completeness

| Metric | Value |
|--------|-------|
| Tasks total | 73 |
| Tasks complete | 65 |
| Tasks incomplete | 8 |

Derived from `tasks.md` with `grep -cE '^- \[[ x]\]'` → **73** total, `'^- \[x\]'` → **65**, `'^- \[ \]'` → **8**. The 8 unchecked rows are exactly the **No-Drift Checklist** at the bottom of `tasks.md` (the items this phase ticks/refutes). **No unchecked implementation task remains**; `tasks.md` was **not** edited (read-only phase).

### Build & Tests Execution

**Plugin suite (owner, config-declared runner)**: ✅ `exit 0`
```text
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
OK, but there were issues!
Tests: 911, Assertions: 3973, Warnings: 2, Skipped: 1.
```
`test_output_hash: sha256:431c4251f4decbe882d0cc3613e479326dea97ae090d94ff08e4332a9c33c4a9` (`tmp/verify_art/cc_suite.txt`). Matches the delivered claim exactly (**911 tests, 0 failures**). The 2 warnings and 1 skip are the pre-existing plugin-wide set.

**tarifario suite**: ✅ `exit 0`
```text
$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
OK, but some tests were skipped!
Tests: 266, Assertions: 1093, Skipped: 2.
```
`sha256:53f73fe2a9a7c4585881b3850fd0f46cb067c744882239fc0ad0f97943196d9a` (`tmp/verify_art/tarifario_suite.txt`). Matches the delivered claim (**266 tests, 0 failures**).

**Frozen-marker guard (unedited)**: ✅ `exit 0`
```text
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter CatalogoCoreHookMarkersTest
OK (12 tests, 68 assertions)
```
`sha256:24d103c77c23d4e6cb516c8db473f4af899a96cc5b6c20d8117a1ccbe11bdef8` (`tmp/verify_art/hook_markers.txt`). `git diff 1399f58d..HEAD -- tests/Integration/CatalogoCoreHookMarkersTest.php` is empty: the test is **unmodified** and green, so the four frozen names/positions are proven by the pre-change contract itself.

**Root `Plugins` suite (cross-plugin regression)**: ❌ `exit 1` — **pre-existing, unrelated**
```text
$ ddev exec php vendor/bin/phpunit --testsuite Plugins
FAILURES!
Tests: 2155, Assertions: 8563, Failures: 7, Warnings: 1, Skipped: 46.
```
`sha256:4b471a4d66660aae98165c243774deb086f1703f40ad09389287a194eaef354a` (`tmp/verify_art/root_plugins.txt`). The failure set is **exactly the pre-existing `OidcProvider` set** (`OidcRegisterControllerMinimalClienteTest` ×2, `OidcLegacySchemaParityTest`, `OidcSchemaContractTest`, `migration011_cliente_gruposTest` ×4). A `grep -E "^[0-9]+\) "` over the failure output lists **only** `Tests\OidcProvider\...`; a grep for `catalogo_core`/`tarifario` in the failure output returns **0**. The failure count is order/state-sensitive in that untouched plugin (adjacent runs report 7 or 8 with the same set), which is the same behaviour every apply slice recorded.

**Build (type gate)**: ❌ `exit 1` — **pre-existing, out of this change's scope**
```text
$ ddev exec composer phpstan
   208/208 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 ------ -----------------------------------------------------------------------
  Line   tests/Core/PluginEnableAjaxSafetyTest.php
  308    Method Tests\Core\AjaxGuardTestPluginManager::applyPluginSchemaUpdates()
         should return array{success: bool, changes: list<string>, errors: list<string>}
         but returns array{success: true, errors: array{}}.
         🪪  return.type
 ------ -----------------------------------------------------------------------
 [ERROR] Found 1 error
```
`build_output_hash: sha256:0c97c33e9ab251cbad32980fc19b42765173af52ee847914e5b70e9d1dd9bb1c` (`tmp/verify_art/phpstan.txt`). **Scope limitation (recorded, not assumed)**: `phpstan.neon` declares `paths: [src, tests]`, so this command **does not analyse `plugins/`** and is **not** plugin type-safety evidence. The single error is in a committed core test file this plugin-local change does not touch; root `git status --porcelain` carries only `M opencode.json` (unrelated agent config), so the offending bytes are the core `HEAD` bytes and the error exists at `HEAD` independently of this change. Recorded as pre-existing, not a regression of `gestion-idiomas-catalogo`. The plugin's real gates are the two suites above plus `php -l` (the suites load and execute the touched production files).

**Coverage**: ➖ Not available — no coverage driver is installed (`php -m` reports neither xdebug nor pcov). Unchanged from prior verifications.

### Real-DB Composed-SQL Smoke (the deferred proof — EXECUTED)

The apply phase deferred a real-DB smoke of the new `COALESCE(d.descripcion, a.descripcion)` joins to this phase. It was **executed on both engines** with controlled rows inside a rolled-back transaction (no persistent change):

- **MySQL/MariaDB 10.11** (the project's `ddev` DB, `config.php` `FS_DB_TYPE=MYSQL`): `ddev exec mysql --table --force db < tmp/…` over seeded rows.
- **PostgreSQL 16** (a throwaway `docker run postgres:16` container, removed afterwards; no project config touched).

| Check | MySQL | PostgreSQL |
|---|---|---|
| Non-default (`en`) term findable via `SELECT DISTINCT a.* … LEFT JOIN articulo_descripciones d … OR lower(d.descripcion) LIKE` | ✅ 1 hit | ✅ 1 hit |
| Base-shim term findable via `lower(a.descripcion)` (third leg) | ✅ 1 hit | ✅ 1 hit |
| Row multiplication: raw join rows vs `DISTINCT` rows for a 2-translation article | ✅ 2 → 1 | ✅ 2 → 1 |
| `COALESCE` fallback: article with no default row → base text; article with a default row → that text | ✅ | ✅ |
| Exact `tarif_tarifa_articulo::get` composed query executes and returns the fallback value | ✅ | ✅ |
| Exact `tarif_articulo_precio::all_by_familias` composed query executes (no ambiguity) | ✅ | ✅ |
| `tarif_actualizar_precios` composed query executes | ✅ | — (same shape as the previous row) |
| `tarif_grupo_articulo` search shape over the same join matches the resolved text | ✅ | ✅ |

No "column is ambiguous" error and no `DISTINCT`+`ORDER BY` rejection on either engine; the join does not multiply rows (the `UNIQUE (referencia, codidioma)` key guarantees ≤1 joined row); the fallback returns the frozen base column when the configured default has no row. **The deferred proof is closed.** (Note: `tarif_grupo_articulos` does not exist in the dev DB, so the exact `FROM tarif_grupo_articulos ga` variant could not run; the identical join semantics were proven over `articulos a`.)

### No-Drift Checklist (`tasks.md` bottom) — ticked / refuted

Every item was checked against `git` and the code, not against the claim. All eight are **TRUE (ticked)**.

| # | Item | Result | Evidence |
|---|---|---|---|
| 1 | No entry in the repository-root `openspec/` | ✅ TRUE | `grep -rl gestion-idiomas-catalogo openspec/` → 0; root `git status --short` shows only `M opencode.json` (unrelated) |
| 2 | No `#[AdminOnly]` on `Controller/VentasArticulos.php` | ✅ TRUE | `grep -n AdminOnly Controller/VentasArticulos.php` → no token at all; `CatalogoIdiomaPermissionTest::test_get_cannot_mutate_and_the_page_has_no_admin_only` is green |
| 3 | No backfill and no mirror of `articulos.descripcion` | ✅ TRUE | `set_descripcion_idioma()` has no `$this->descripcion =` write; `saveMultiidiomaDescriptions()` writes only `descripcion_<cod>`; the Excel locale path routes through `set_descripcion_idioma()` (`ArticuloExcelRowUpdater::applyDescripcionIdioma`); `process_excel_wizard_dispatch.php` only calls `applyDescripcionIdioma`; the only migration addition is the orphan purge (`git diff 1399f58d..HEAD -- Services/CatalogLegacyTableMigration.php` shows only `purgeOrphanDescriptions`) |
| 4 | No new Composer dependency | ✅ TRUE | `git log 1399f58d..HEAD -- composer.json composer.lock .gitignore` empty; `git diff --stat 1399f58d..HEAD -- vendor/` empty |
| 5 | Delta specs merged only at archive | ✅ TRUE | `git status --short plugins/catalogo_core/openspec/specs/` empty; tarifario `git status --short openspec/specs/` empty |
| 6 | Schema files unchanged | ✅ TRUE | `git diff 1399f58d..HEAD -- model/table/articulo_descripciones.xml model/table/catalogo_idiomas.xml` empty (no `codidioma` FK, D-01) |
| 7 | Frozen markers unchanged | ✅ TRUE | `git diff 1399f58d..HEAD` over both views shows **no** `render_hook` line changed; `CatalogoCoreHookMarkersTest` unmodified and 12/12 green |
| 8 | `EXPORT_HEADERS` / `FIELD_CATALOG` not edited | ✅ TRUE | The two constant blocks hash byte-identically to `git show 1399f58d:<file>` (`EXPORT_HEADERS` `b83859bb…301a` both; `FIELD_CATALOG` `eee93555…8afdb` both) |

**Nuance (not a refutation).** The absolute line number of `ventas_articulo_tab_pane_after` moved from `:384` to `:373` because slice 3 removed the 11-line base `sdescripcion` block above it. The contract that matters is the *structural* position — "immediately before the `.tab-content` balancing `</div>`" — which `CatalogoCoreHookMarkersTest` asserts and which is unchanged (the test is green **unedited**). The `render_hook(...)` lines themselves are byte-unchanged.

### Spec Compliance Matrix

Statuses per `references/report-format.md`: ✅ COMPLIANT · ⚠️ PARTIAL · ❌ UNTESTED/FAILING. Counts are the native `### Requirement:` / `#### Scenario:` totals from the four deltas: `articulo-detalle-canonico` 2/6, `articulos-excel-import-export` 3/10, `gestion-idiomas` 12/33, `tarifario/catalogo-integration` 1/5 → **18 requirements, 54 scenarios**. (Note: `tasks.md`'s prose says "19 requirements / 54 scenarios"; the actual delta total is **18**, so the envelope uses the file-derived 18. The prose is a documentation slip — see SUGGESTION 4.)

| # | Requirement (delta) | Scen. | Result | Covering evidence (executed this pass, all green) |
|---|---|---|---|---|
| 1 | **GDI-01** registry lifecycle + management surface | 2/2 | ✅ | `CatalogoIdiomaManagementTest::{test_full_lifecycle_round_trip_persists_each_mutation, test_invalid_payload_is_rejected_and_persists_nothing, test_url_anchor_resolves_to_a_real_section, test_management_surface_dispatches_the_four_language_actions}`; `catalogo_idioma::url()` → `index.php?page=ventas_articulos#idiomas`; the `#idiomas` panel exists at `View/ventas_articulos.html.twig:267` |
| 2 | **GDI-02** one active default; total deterministic resolution | 4/4 | ✅ | `CatalogoIdiomaInvariantsTest::{test_setting_new_default_is_a_flag_flip, test_deactivating_the_default_is_rejected, test_default_resolution_is_total, test_default_resolution_falls_back_to_lowest_active_code, test_default_resolution_never_returns_false_on_empty_registry, test_invariant_holds_after_every_mutation}`; `catalogo_idioma::get_effective_default_code()` (`:97-112`), transactional `set_default()`/`save()`/`normalize_default()` |
| 3 | **GDI-03** last-language guard + delete cleanup | 2/2 | ✅ | `CatalogoIdiomaDeleteCleanupTest::{test_deleting_the_last_language_is_refused, test_deleting_a_language_removes_its_descriptions}`; `catalogo_idioma::delete()` `COUNT(*) <= 1` guard + transactional row delete (`:246-293`); idempotent `CatalogLegacyTableMigration::purgeOrphanDescriptions` |
| 4 | **GDI-04** admin-only, POST-only, CSRF mutations | 3/3 | ✅ | `CatalogoIdiomaPermissionTest::{test_non_administrator_with_a_valid_token_persists_nothing, test_csrf_failure_persists_nothing_and_rejects_explicitly, test_get_cannot_mutate_and_the_page_has_no_admin_only}`; `VentasArticulos::shouldDispatchIdioma()` reads the **POST** body only (`:613-616`), `gestionarIdioma()` gates `validateFormToken()` → `empty($this->user->admin)` (`:623-633`) |
| 5 | **GDI-05** read chain, no fallback materialisation | 4/4 | ✅ | `ArticuloMultiidiomaTest::{test_requested_language_wins, test_fallback_uses_the_configured_default_not_a_hard_coded_es, test_base_column_and_empty_terminal, test_reads_materialise_nothing}`; `articulo::get_descripcion_idioma()` (`:1451-1476`) is pure-read |
| 6 | **GDI-06** `articulos.descripcion` frozen shim | 2/2 | ✅ | `ArticuloDescripcionFrozenBaseTest::{test_default_language_save_does_not_write_the_base_column, test_no_backfill_runs_at_plugin_boot}`; the mirror branch is gone from `set_descripcion_idioma()` (`:1527-1543`) |
| 7 | **GDI-07** clearing semantics | 4/4 | ✅ | `ArticuloDescripcionClearingTest::{test_both_fields_empty_deletes_the_row, test_short_description_only_row_is_preserved, test_empty_description_is_accepted_by_validation, test_a_cleared_language_falls_back_at_read_time, test_empty_pair_with_no_existing_row_is_a_noop}`; `articulo_descripcion::test()` accepts empty, `save()`'s `isEmptyPair()` deletes (`:96-146`) |
| 8 | **GDI-08** search-cache invalidation on every write | 2/2 | ✅ | `ArticuloSearchCacheInvalidationTest::{test_non_default_language_write_invalidates_the_cache, test_clearing_path_delete_invalidates_the_cache, test_delete_invalidates_the_cache, test_language_delete_invalidates_the_cache, test_language_delete_without_descriptions_keeps_the_cache}` — the **real** `fs_cache` adapter is exercised; `articulo_descripcion::save()`/`delete()` and `catalogo_idioma::delete()` call `articulo::invalidate_search_cache()` (`articulo.php:1148-1161`) |
| 9 | **GDI-09** language-agnostic search as deliberate deviation | 3/3 | ✅ | `ArticuloSearchMultiidiomaTest::{test_a_term_that_lives_only_in_a_non_default_language_is_findable, test_a_cleared_translation_stops_matching, test_exactly_one_language_agnostic_predicate_exists_and_carries_the_deviation_note}`; the single predicate + verbatim D11 note live on `ArticuloSearchQueryBuilder::languageDescriptionPredicate()` (`:53-57`) |
| 10 | **GDI-10** no-context consumers resolve the configured default | 3/3 | ✅ | `ConsumidoresIdiomaDefaultTest::{test_api_serialises_the_configured_default_language, test_opcional_search_returns_the_configured_default_language, test_articulo_precio_reader_joins_the_configured_default_language, test_tarifa_articulo_readers_join_the_configured_default_language, test_tarifa_articulo_search_matches_the_resolved_description, test_quick_create_keeps_writing_only_the_base_column, test_opcional_state_trait_already_reads_through_the_language_api}`; `plugins/tarifario/tests/Controller/TarifControllerLanguageCallsTest::test_dead_language_calls_are_gone` |
| 11 | **GDI-11** article editor language selector | 3/3 | ✅ | `VentasArticuloControllerTest::{testTwigViewRendersTheLanguageSelectorForTheSelectedLanguage, testTwigViewNoLongerRendersTheDuplicatedBaseDescription}`; `VentasArticuloArticleEditAbsorptionTest::{test_selector_save_persists_the_posted_default_language_value, test_selector_save_never_writes_another_language_slot, test_selector_save_clears_the_language_row_on_an_empty_pair}`; `saveMultiidiomaDescriptions()` reads only `descripcion_<resolved>` (`:884-899`) |
| 12 | **GDI-12** locked anchors, markers, re-expressed ART contracts | 1/1 | ✅ | `CatalogoCoreHookMarkersTest` 12/12 **unedited**; `#multiidioma` panel (`tab_multiidioma.html.twig:8`) and the include survive |
| 13 | **ART-01** MODIFIED — canonical detail absorbs edit behavior | 4/4 | ✅ | `VentasArticuloArticleEditAbsorptionTest::{test_reference_change_uses_the_article_reference_setter_and_rejects_invalid, test_per_tarifa_sync_uses_tarif_tarifa_articulo_for_the_active_tarifa, test_etiquetas_persist_through_tarif_tarifa_articulo_etiqueta, test_etiqueta_failure_does_not_discard_the_article_save, test_images_entry_point_is_owned_by_the_canonical_detail, test_selector_save_persists_the_posted_default_language_value, test_selector_save_clears_the_language_row_on_an_empty_pair}` |
| 14 | **ART-05** MODIFIED — tabs + frozen hook markers | 2/2 | ✅ | `CatalogoCoreHookMarkersTest` (four frozen names + structural positions) 12/12 unedited; `VentasArticuloArticleEditAbsorptionTest::test_view_preserves_tabs_and_frozen_markers` |
| 15 | **Export Excel** — additive locale columns | 3/3 | ✅ | `ArticuloExcelIdiomasTest::{test_export_base_headers_stay_byte_identical_with_locale_columns, test_export_locale_columns_append_in_deterministic_order_and_omit_inactive, test_export_writes_each_language_text_under_its_suffix, test_export_orders_the_default_language_first}`; `EXPORT_HEADERS` byte-identical |
| 16 | **Import wizard 3 pasos** — explicit target language | 4/4 | ✅ | `ArticuloExcelIdiomasTest::{test_target_language_defaults_to_the_configured_default_and_explicit_overrides, test_unknown_locale_column_is_ignored_without_touching_the_registry, test_deactivated_language_column_is_ignored, test_legacy_workbook_without_locale_columns_writes_no_description_row}`; `process_excel_wizard_dispatch.php` reads `target_codidioma` read-only |
| 17 | **Persistencia** — language path never writes the base | 3/3 | ✅ | `ArticuloExcelIdiomasTest::{test_mapped_pair_persists_on_the_target_language_row, test_empty_mapped_pair_deletes_the_language_row, test_the_base_column_is_never_written_by_the_language_path, test_a_description_write_never_mutates_the_language_registry}` |
| 18 | **R-TAR-HOOK-013** tarifario no-context readers | 5/5 | ✅ | `TarifGrupoArticuloIdiomaTest::{test_group_article_reads_use_the_configured_default, test_group_search_matches_the_resolved_description}`; `TarifHistorialPreciosIdiomaTest::{test_export_uses_the_configured_default_language, test_export_source_no_longer_reads_the_raw_base_column}`; `TarifControllerLanguageCallsTest::{test_dead_language_calls_are_gone, test_default_language_resolution_is_total, test_the_four_subclasses_still_load_and_instantiate}`; `TarifIdiomaLegacyAliasTest` (2); the "already-correct readers unchanged" scenario is proven by an **empty** `git diff` for `ExcelRowUpdater.php`, `ArticuloListActionHandler.php`, `tarif_catalogo_view.php`, `tarif_configurador_opcionales.php`, `tarif_articulo.php` |

**Compliance summary**: **54/54 scenarios compliant**; **18/18 requirements compliant**. No UNTESTED, no FAILING, no PARTIAL.

### Correctness (Static Evidence) — implementation vs. delta contract

| Contract point | Status | Code evidence |
|---|---|---|
| GDI-01 `codidioma` PK stable; `nombre` 1–50; `test()` bounds | ✅ | `catalogo_idioma::test()` (`:182-198`) |
| GDI-02 default resolution total (`active default → lowest active → 'es'`) | ✅ | `get_effective_default_code()` (`:97-112`); `get_default()` gains `AND activo = TRUE` (`:82`) |
| GDI-02 default change touches no description row | ✅ | `set_default()` issues only `catalogo_idiomas` UPDATEs (`:118-143`) |
| GDI-03 delete removes the language's rows, leaves others | ✅ | `delete()` transactional `DELETE … WHERE codidioma = :cod` (`:269-273`) |
| GDI-04 page stays accessible, no page-level attribute | ✅ | `shouldDispatchIdioma()` POST-only (`:613-616`); no `AdminOnly` token |
| GDI-05 read chain pure (requested → default → base → `''`) | ✅ | `get_descripcion_idioma()` (`:1451-1476`); no write leg |
| GDI-06 no mirror, no backfill | ✅ | `set_descripcion_idioma()` (`:1527-1543`); migration only purges |
| GDI-07 `descripcion_corta`-only row preserved | ✅ | `isEmptyPair()` (`:125-129`); labelled LOCAL DECISION |
| GDI-08 one public static invalidator | ✅ | `articulo::invalidate_search_cache()` (`:1148-1161`); called from `articulo_descripcion::save()`/`delete()` and `catalogo_idioma::delete()` |
| GDI-09 one isolated predicate + deviation note | ✅ | `languageDescriptionPredicate()` (`:53-57`), `languageDescriptionJoin()` (`:62-65`); all base columns `a.`-qualified |
| GDI-09 `DISTINCT` + `ORDER BY a.referencia` valid on both engines | ✅ | `articulo::search()` (`:1191-1198`); proven by the real-DB smoke |
| GDI-10 API/Opcional/SQL consumers resolve the default | ✅ | `CatalogoApiService.php:66`; `VentasOpcional.php:426,429`; `tarif_articulo_precio.php:436-438`; `tarif_tarifa_articulo.php` six joins |
| GDI-11 one pair per selected language; no cross-language write | ✅ | `tab_multiidioma.html.twig` renders one `descripcion_<selected>` pair; `saveMultiidiomaDescriptions()` reads only that slot (`:884-899`) |
| GDI-12 anchor + four markers keep names/positions | ✅ | `CatalogoCoreHookMarkersTest` 12/12 unedited |
| ART-01 selector-driven save + base never overwritten | ✅ | `saveMultiidiomaDescriptions()` has no `$art->descripcion` copy and no empty-input skip |
| Export base headers byte-identical; locale order deterministic | ✅ | `ArticuloExcelExportService::descriptionHeaders()` additive; const untouched |
| Import ignores unknown/inactive locale columns; registry immutable | ✅ | `process_excel_wizard_dispatch.php` `$idiomaModel` is read-only; no `catalogo_idioma::save()` on the path |
| R-TAR-HOOK-013 dead calls corrected; subclasses load | ✅ | `extras/tarif_controller.php:236,239`; `TarifControllerLanguageCallsTest` |

### Coherence (Design) — the ten resolved mechanisms

| Decision | Followed? | Notes |
|---|---|---|
| D-01 app-level orphan cleanup; no XML FK | ✅ | `catalogo_idioma::delete()` + idempotent `purgeOrphanDescriptions`; schema untouched |
| D-02 `get_effective_default_code()` + transactional invariants | ✅ | As designed; `normalize_default()` split into two statements to avoid MySQL 1093 |
| D-03 one public static invalidator | ✅ | Mechanism as designed; the writer enumeration was **corrected** by slice 4a-fix (deviation 19) — mechanism unchanged |
| D-04 isolated predicate + `DISTINCT` + `ORDER BY a.referencia` | ✅ | As designed; valid on MySQL and PostgreSQL (smoke) |
| D-05 server-rendered link selector, one dynamic pair | ✅ | As designed; `#multiidioma` anchor preserved |
| D-06 `#idiomas` section, `idioma_action` POST dispatch, no `#[AdminOnly]` | ✅ | As designed |
| D-07 additive locale columns, explicit import target | ✅ | As designed; the `4b` wiring (`4b.7`) landed in the follow-up |
| D-08 `get_descripcion_corta_idioma()` same-language only | ✅ | `articulo.php:1500-1517`; LOCAL DECISION comment present |
| D-09 `$codidioma = null` default; unknown code falls through | ✅ | `:1451-1476`; default resolved once into `$defaultCode` |
| D-10 mirror removed; `ExcelRowUpdater:256-259` preserved | ✅ | `git diff` empty for the tarifario canonical readers |
| D-11 clearing inside `articulo_descripcion::save()` | ✅ | `:131-177` |
| D-12 `test()` accepts empty `descripcion` | ✅ | `:111-114` |
| D-13 quick-create base-only | ✅ | `ConsumidoresIdiomaDefaultTest::test_quick_create_keeps_writing_only_the_base_column` |
| D-14 `EXISTS` plan-B | ➖ N/A | Not needed; the `DISTINCT` shape is valid on both engines |

### Deviation Review (1–32)

Classification: **A** = acceptable (local decision / bookkeeping, no design or spec violation), **F** = needs a follow-up, **D** = real defect.

| # | Deviation (short) | Class | Note |
|---|---|---|---|
| 1 | Task 1.17 pulled into `1a` | A | Bookkeeping; both parts delivered and green |
| 2 | `language_registry()`/`description_model()` test seams | A | Behaviour-preserving protected seams (production returns the real models) |
| 3 | `1b` ordering: read chain before clearing | A | Both units green and committed |
| 4 | GDI-08 not implemented in `1b` | A | Assigned to slice 4a; delivered there |
| 5 | No production mechanism differs from design | A | Confirmed by the coherence table |
| 6 | `idioma_model()` seam on the list trait | A | Behaviour-preserving |
| 7 | Mutation target read from the loaded registry | A | Unknown code is a silent no-op = "persists nothing" |
| 8 | Slice-2 mutations delegate invariants to slice 1 | A | Model remains the authority |
| 9 | `#idiomas` panel forms rendered unconditionally | A | Server gate is the authority; GDI-04 does not require hiding |
| 10 | Controller docblock avoids the literal `AdminOnly` | A | Keeps the source gate strict; see SUGGESTION 5 for the brittleness |
| 11 | Stale line numbers in the artifacts | A | Documentation drift (the sibling commit rewrote the files); work driven from current bytes |
| 12 | `resolve_codidioma()` validates against `$this->idiomas` | A | `$this->idiomas` **is** `all_activos()`; no second query |
| 13 | `VentasArticuloPerTarifaPaneTest` collateral update | A | Intent preserved (`sobservaciones` exemplar); no assertion weakened |
| 14 | Dead `description-default-language-hint` key removed | A | Its only consumer was the removed hint |
| 15 | `make_articulo()` seam on `articulo` | A | Behaviour-preserving; production returns `new \articulo($data)` |
| 16 | D-03 writer gate incomplete | A → **closed** | Real gap found; **closed by slice 4a-fix** (`catalogo_idioma::delete()` now invalidates) |
| 17 | `$quote` parameter accepted but unused | A | Intentional contract symmetry; see SUGGESTION 2 |
| 18 | Numeric search path now lower-cases the description leg | A | Required to keep exactly one predicate; no-op on MySQL, case-insensitive on PG |
| 19 | D-03's proof incomplete; fix corrects the enumeration | A | Mechanism unchanged; the gate now splits invalidating vs exempt writers |
| 20 | Invalidate call conditional and post-commit | A | Proven by mutation (unconditional invalidate fails the "keeps the cache" case) |
| 21 | The gate is structural, not behavioural | A | Documented; behavioural proof lives in the dynamic cache tests (see "Deferred Proof" below) |
| 22 | Task 4b.7 deferred | A → **closed** | **Closed by slice 4b-wiring** — controller pass-through + modal select + JS param all present |
| 23 | `resolveLocalePair()` factored out | A | Keeps the pure decision in the service |
| 24 | `applyDescripcionIdioma()` preserves an unmapped component | A | `null` = not mapped (preserve), `''` = mapped empty (clear) |
| 25 | Locale short label disambiguated | A | No scenario pins the label text |
| 26 | Deviation 22 closed; mapping dropdown gap noted | A → **closed** | **Closed by slice 4b-mapping** — `loadPreview()` consumes `json.field_options`, `optionList()` renders it |
| 27 | Deviation 26b closed | A → **closed** | `renderMapping()` renders from `optionList()`; negative assertion forbids `FIELD_OPTIONS.forEach` |
| 28 | Raw-SQL join resolves the default in PHP, not `:def` | A | Same quoting style as every other value; `var2str()`-quoted; DB-free testable. Safe |
| 29 | Task 4c.5 deferred | A → **closed** | **Closed by slice 4c5** — trait `resolve_codidioma()` delegates to `get_effective_default_code()`; 3 behavioural cases |
| 30 | `tarif_roles.php:718` resolved at the model boundary | A | `$a` is a `tarif_grupo_articulo`; its `descripcion` **is** the COALESCE result — verified |
| 31 | `model/tarif_articulo.php` not edited | A | Artifacts mark it "Verify only"; empty diff confirmed |
| 32 | `tarif_catalogo_view.php` `es`/`en` literals intact | A | Fixed two-language column shape, not a default-resolution bug |

**The two orchestrator scoping errors are genuinely closed:** the deferred wiring (deviation 22 → closed by `cf02b7f2`, deviation 26b → closed by `ed340ef9`) and the deferred `4c.5` (deviation 29 → closed by `1e86a4e6`). Confirmed against the tree, not the claim: the controller passes `idiomas:`/`codidioma_defecto:` on both export call sites (`VentasArticulos.php:388-407`), the modal renders `name="target_codidioma"` (`:47`), the JS pushes `target_codidioma=` (`:591`), and the trait delegates to `get_effective_default_code()` (`TarifarioOpcionalStateTrait.php:132-138`). **No deviation remains open.**

### Deferred Proof — what is now proven and what is not

1. **Real-DB composed-SQL smoke — PROVEN.** Executed on MySQL/MariaDB 10.11 and PostgreSQL 16 (see the smoke table above). The `COALESCE` joins in `tarif_tarifa_articulo`, `tarif_articulo_precio`, `tarif_grupo_articulo` and `tarif_actualizar_precios` are now proven at the real-engine level, not only at SQL-emission level: valid SQL, no ambiguity, no row multiplication, correct fallback.

2. **Slice-4a search-cache writer gate (GDI-08) — the structural gate is adequate, but it is not the proof.** `ArticuloSearchCacheInvalidationTest::test_every_description_writer_invalidates_or_is_explicitly_exempt` is a **source-text allowlist** (`assertStringContainsString`); it cannot detect a commented-out call whose literal survives, and its `$exempt` list is human-reviewed. It is a **regression guard**, correctly labelled as such (deviation 21). The **behavioural proof for GDI-08 lives in the dynamic tests** — `test_non_default_language_write_invalidates_the_cache`, `test_clearing_path_delete_invalidates_the_cache`, `test_delete_invalidates_the_cache`, `test_language_delete_invalidates_the_cache`, `test_language_delete_without_descriptions_keeps_the_cache` — which seed a real cached `articulos_search_*` key through the **real `fs_cache` adapter**, run the real `save()`/`delete()`/language-delete bodies, and assert the key is gone (and, for the no-description language, that it survives). Mutation checks in `apply-progress.md` confirm the gate has teeth. **Conclusion: adequate evidence for GDI-08.** The only untested corner is a future writer that calls the invalidator inside a conditional the test cannot see; the allowlist will catch a new file, and the dynamic tests cover the two known writers.

3. **Authenticated real-browser smoke — NOT EXECUTED (evidence gap, honestly stated).** The repository has no live authenticated HTTP harness and the dev DB's admin password is an unknown `argon2id` hash, so the editor-selector round trip, the `#idiomas` POST mutations, the Excel export/import with a configured default ≠ `es`, and the language-delete → search-invalidation path were **not** exercised in a real browser. What **was** done instead, safely: `curl` against `https://panel-ab.ddev.site/index.php?page=login` → `200`, and `…?page=ventas_articulo` unauthenticated → `302` with **no** fatal/exception markers, so the application boots with the change and the route redirects. The UI behaviour is covered by the suite at render/source level: the real modal partial is rendered through a minimal Twig environment (`VentasArticulosExcelIdiomasWiringTest`), the view tests read the shipped bytes (`VentasArticuloControllerTest`, `VentasArticuloArticleEditAbsorptionTest`), and the controller actions execute their real bodies through the DB-free seams. **The real-browser smoke remains unproven and is the one evidence gap in this report.**

4. **The D11 deviation is documented in both the code and the spec — CONFIRMED.** The spec (`GDI-09`) states the deviation, names the systems that scope search, justifies it from the ~25 legacy readers and the frozen base column, and forbids a silent "fix". The code carries the **verbatim** note on `ArticuloSearchQueryBuilder::languageDescriptionPredicate()` (PrestaShop/Akeneo/Odoo/Magento, `DELIBERATE DEVIATION`, `Do NOT "fix" this into a locale-scoped search (GDI-09)`), and the grep gate asserts the note's presence and the single-predicate invariant. A later contributor cannot silently re-scope the search without failing the test.

### Issues Found

**CRITICAL**: None.

**WARNING**
1. **ARCHIVE-BLOCKING — sibling merge ordering.** The two sibling changes are still active and each carries `verdict: fail` / `blockers: 1`: `caracteristicas-producto` (47/48 req, 153/154 scen) and `articulo-detalle-tarifa-unificada` (9/9, 26/26). **Both carry an `articulo-detalle-canonico` delta**, and `caracteristicas-producto` additionally carries `articulos-excel-import-export` and `tarifario/catalogo-integration` deltas. This change's ART-01/ART-05 delta, its `articulos-excel-import-export` delta and its tarifario `catalogo-integration` delta therefore **must merge AFTER** both siblings archive, or the ART-01/ART-05 block would re-apply pre-sibling wording and the excel/integration blocks would collide. This blocks the **delta merge / archive**, not the implementation or the code review.
2. **`ddev exec composer phpstan` exits `1` on a pre-existing, out-of-scope core-test error.** `tests/Core/PluginEnableAjaxSafetyTest.php:308` (`return.type`) fails at core `HEAD`; the root tree is clean apart from the unrelated `opencode.json`. `phpstan.neon` does not analyse plugins, so this is the repository-root gate only, **not** plugin type-safety. Recorded rather than hidden because the gate did not return `exit 0` as the design's Testing Strategy expected.
3. **The authenticated real-browser smoke was not executed.** See "Deferred Proof" item 3. The UI scenarios (selector round trip, `#idiomas` mutations, Excel export/import with default ≠ `es`, delete → invalidation) rest on render/source-level and DB-free dynamic tests, not on a live browser session.

**SUGGESTION**
1. **`get_effective_default_code()` is not memoized → an N+1 read pattern in `CatalogoApiService`.** `articulo::get_descripcion_idioma()` resolves the default on every call (`articulo.php:1457`), and `CatalogoApiService.php:57-66` loops over the search page calling it per article; each call runs 1–2 registry queries. Remedy: cache the resolved code per request (a static/request-scoped memo on `catalogo_idioma`), or resolve it once in the consumer and pass it down. Bounded (page-limited) and not a spec violation, but a real added cost on the public API path.
2. **`ArticuloSearchQueryBuilder::languageDescriptionPredicate(string $like, callable $quote)` accepts `$quote` but never uses it** (deviation 17). Remedy: drop the parameter, or actually quote inside the helper; leaving an unused parameter invites a future caller to believe it is applied.
3. **`catalogo_idioma::save()` runs `exists()` twice and `get()` once** (`:206-221`) — three redundant round-trips per save. Remedy: read the current row once and reuse it.
4. **`tasks.md` prose says "19 requirements / 54 scenarios"; the deltas contain 18 requirements.** Remedy: correct the coverage-matrix preamble so the native count matches the files (the envelope uses the file-derived 18/54).
5. **`CatalogoIdiomaPermissionTest` asserts the literal token `AdminOnly` is absent from the controller source**, so even a docblock reference fails the test (deviation 10). This is deliberate and strict, but brittle: a future legitimate mention would produce a confusing failure. Remedy: assert the absence of the *attribute usage* (e.g. a regex for `#[...AdminOnly]`) rather than the bare token.

### Archive Readiness

- **Implementation: READY.** 18/18 requirements and 54/54 scenarios are covered by passing tests; the plugin suite (911), the tarifario suite (266) and the frozen-marker filter (12) are green; the root `Plugins` suite fails only on the pre-existing `OidcProvider` set; the real-DB composed-SQL smoke passed on MySQL and PostgreSQL; all eight no-drift items are TRUE; no deviation remains open.
- **Archive: HOLD (blocker).** The deltas must **not** be merged until the sibling changes `caracteristicas-producto` and `articulo-detalle-tarifa-unificada` archive (both currently `fail` / 1 blocker, both touching `articulo-detalle-canonico`). Merge this change's `articulo-detalle-canonico`, `articulos-excel-import-export` and tarifario `catalogo-integration` deltas **after** them; the new `gestion-idiomas` spec has no ordering dependency but travels in the same archive step.
- **Process reminders for archive:** never create an entry in the repository-root `openspec/`; the `sdd-archive` agent must receive the explicit plugin paths (`plugins/catalogo_core/openspec/` and `plugins/tarifario/openspec/`) because `gentle-ai sdd-status` only knows the root `openspec/`.

### Envelope Semantics (read this before routing)

`verdict: fail` is **not** an implementation failure. `gentle-ai sdd-verify-validate` admits a passing verdict only when `blockers: 0` **and** both command exit codes are `0`. This change carries a genuine **archive blocker** (`blockers: 1`, the sibling merge ordering) and the prescribed repository gate `composer phpstan` exits `1` on a **pre-existing, out-of-scope** core-test error, so a passing envelope is inadmissible by construction. Both facts are recorded honestly instead of green-washed.

- **Implementation verification: PASS.** Plugin suite `911` `exit 0`; tarifario suite `266` `exit 0`; root `Plugins` suite failures are exactly the pre-existing `OidcProvider` set; all 18 requirements / 54 scenarios covered by passing tests; real-DB smoke green on MySQL and PostgreSQL; no-drift checklist fully TRUE.
- **`blockers: 1` = ARCHIVE-only.** The sibling merge ordering is unmet; nothing about the code blocks candidate review.
- **`build_exit_code: 1` = pre-existing repo-gate failure**, in a core test file this plugin-local change does not touch (`phpstan.neon` does not analyse plugins).

### Verdict

**Envelope: FAIL (not archive-ready) — Implementation: PASS.**
Implementation evidence is complete and green (plugin suite `911/911` `exit 0`; tarifario suite `266/266` `exit 0`; all 18 requirements / 54 scenarios covered; all eight no-drift items TRUE; the deferred real-DB composed-SQL smoke executed and passed on MySQL and PostgreSQL). The envelope cannot be `pass` because the sibling merge ordering is an unmet ARCHIVE blocker and the prescribed repo gate `composer phpstan` exits `1` on a pre-existing out-of-scope core error. The one unproven item is the authenticated real-browser smoke, stated plainly rather than assumed. **Archive gate: HOLD** until the siblings archive; the implementation itself is ready.
