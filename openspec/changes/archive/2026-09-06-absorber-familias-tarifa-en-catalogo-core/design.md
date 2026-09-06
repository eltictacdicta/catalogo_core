# Design: Absorb the familias tarifa package into catalogo_core

## Technical Approach

Atomic work unit (D7: three coordinated commits, one per repository) moving the familias package (controller, view, macro, 2 partials, 4 JS modules, 5 models, 4 XMLs, integration test) from `plugins/tarifario` to `plugins/catalogo_core`, plus four surgical modifications: (1) controller reclass `tarif_controller` → `fbase_controller`, (2) raw SQL COUNT replacing the `tarif_tarifa_articulo` model dependency, (3) a standalone table bootstrap in `catalogo_core/Init.php`, (4) reference updates disposing all 43 anchored-gate hits across 27 files (10 staying controllers, 1 staying model, 1 listener, 12 test files, 2 retired tests, the moved controller), plus 1 JS src rewrite. Slug, class names, table names, folder label unchanged; no data migration. Implements proposal Q1–Q6 and the `familias-tarifa-management` delta spec.

## Architecture Decisions

| # | Decision | Choice | Alternatives rejected | Rationale |
|---|----------|--------|----------------------|-----------|
| D1 | Controller base class | `class tarif_familias extends fbase_controller` | fs_controller + inline `allow_delete`; keep `tarif_controller` (violates dependency direction) | `fbase_controller::private_core()` (fbase_controller.php:44-52) provides exactly `allow_delete` (:47) + `multi_almacen` (:51). View/controller consume only `allow_delete` (delete guard :681); codtarifa/tarifas already loaded in-body (:86,:92,:95-99); idiomas/codidioma/tarifa_defecto/grupo models unused (verified fsc.* inventory). |
| D2 | Article count | Private instance helper `count_tarifa_articulos($codtarifa, $codfamilia)` + public `count_articulos($codfamilia)` (macro contract, TarifarioComponents.html.twig:443); parameterized SQL via `fs_db2::select($sql, $params)` (base/fs_db2.php:415; both drivers bind `?` — fs_mysql.php:1022) | Count method on tarif_tarifa_familia model (cross-plugin table coupling in model API); inject callable from tarifario (runtime coupling) | Mirrors `count_from_familia` semantics (tarif_tarifa_articulo.php:454-464) without the class. |
| D3 | Table-name centralization | `public const TARIFA_ARTICULO_TABLE = 'tarif_tarifa_articulo';` on the `tarif_familias` controller class | Constant on tarif_tarifa_familia model; bare literals | Single consumer owns the single cross-plugin table reference; a tarifario-side rename now breaks one declared constant + one source-contract test instead of silent string drift. |
| D4 | Standalone bootstrap | New public static `Init::ensureFamiliasTarifaTables()` in `FSFramework\Plugins\catalogo_core\Init`, invoked from `init()` (per boot, src/Core/Plugins.php:56-70), from `upgrade()` (activation, PluginSchemaSynchronizer), and from the moved controller's `private_core()` behind a static once-flag (mirrors tarif_controller:90-93) | Extend `ensureCatalogTables()` list (hardcodes `model/core/` path); only controller-time check (no guarantee on activation) | Mirrors the proven tarifario_init.php:41-202 pattern: explicit `require_once` + `class_exists` guards + FK-safe instantiation. Idempotent by construction (`require_once`; fs_model instantiation creates only when missing). |
| D5 | Test split | Familias/base-resolution assertions → new `catalogo_core/tests/Integration/FamiliaTarifaResolutionTest.php` (namespace `Tests\CatalogoCore\Integration`); `FamiliaOverrideRemovalTest` stays in tarifario reduced to S24 (tarifario-tree scan) + `test_catalogo_view_call_sites_are_read_only` (reads `tarif_catalogo_view.php`, which stays) | Move whole test (keeps cross-plugin test coupling) | Clean plugin ownership per Q4; no FS_FOLDER reads across plugin trees. |
| D6 | Pre-broken catalogo_core tests | Retire (delete) `FrameworkAutoloaderOverrideTest.php` and `PluginOverrideIntegrationTest.php` + remove the root phpunit.xml:107 exclusion | Update paths | Both reference `plugins/tarifario/model/familia.php`, deleted in AD-6 (PluginOverrideIntegrationTest:100 — unsalvageable require); FrameworkAutoloaderOverrideTest is already excluded as process-order dependent; their contract is superseded by the split tests (stronger both-orders coverage). |
| D7 | Move strategy | ONE atomic WORK UNIT = THREE coordinated commits executed back-to-back with no intermediate deploy: (a) tarifario repo — `git rm` the 18 moved files + repoints/require-drop in staying files + reduced FamiliaOverrideRemovalTest; (b) catalogo_core repo — add moved files + moved-file edits + new tests + split + retirements; (c) parent repo — root phpunit.xml edit only (~−8 lines). Cross-repo `git mv` is impossible (independent repos): `git rm` (tarifario) + byte-identical copy + explicit-path `git add` (catalogo_core) | Fragmented sequencing where the three commits are not executed back-to-back (a reachable broken runtime state between them) | Prevents autoloader double-declaration / missing-class windows at every runtime state; rollback = revert all three in reverse order (parent → catalogo_core → tarifario). Verified topology (apply preflight): parent `.gitignore:38` `/plugins/*` — parent tracks 0 plugin files but tracks root `phpunit.xml`; `plugins/tarifario/.git` (remote eltictacdicta/tarifario) and `plugins/catalogo_core/.git` (remote eltictacdicta/catalogo_core) are independent repos; `git check-ignore -v` confirms plugin paths ignored. |

## Move Mechanics (multi-repo: copy + git rm + explicit git add — cross-repo `git mv` impossible)

Verified topology (apply preflight, re-verified by design phase): parent `.gitignore:38` is `/plugins/*` — the parent repo tracks ZERO plugin files but tracks root `phpunit.xml`; `plugins/tarifario/.git` and `plugins/catalogo_core/.git` are independent repos (remotes `eltictacdicta/tarifario`, `eltictacdicta/catalogo_core`); `git check-ignore -v` confirms both plugin paths ignored by the parent. Therefore the 18-file move is: byte-identical `cp` to catalogo_core paths FIRST, then `git rm` of the same 18 paths in the tarifario repo, then explicit-path `git add` in the catalogo_core repo. Git rename detection cannot fire across repos — the "18 moved files" number is file-move accounting, not rename detection.

```bash
# (0) destination dirs (repo root cwd)
mkdir -p plugins/catalogo_core/View/Macro plugins/catalogo_core/View/partials/familias \
         plugins/catalogo_core/View/js/familias plugins/catalogo_core/tests/Integration

# (a) tarifario repo — copy the 18 files out FIRST (byte-identical), then git rm them
cd plugins/tarifario
cp controller/tarif_familias.php ../catalogo_core/controller/
cp View/tarif_familias.html.twig ../catalogo_core/View/
cp View/Macro/TarifarioComponents.html.twig ../catalogo_core/View/Macro/
cp View/partials/familias/modal_exportar_excel.html.twig View/partials/familias/modal_importar_excel.html.twig ../catalogo_core/View/partials/familias/
cp View/js/familias/index.js View/js/familias/excel-export.js View/js/familias/excel-import.js View/js/familias/utils.js ../catalogo_core/View/js/familias/
cp model/tarif_tarifa.php model/tarif_familia.php model/tarif_familia_ext.php model/tarif_tarifa_familia.php model/tarif_tarifa_etiqueta_familia.php ../catalogo_core/model/
cp model/table/tarif_tarifas.xml model/table/tarif_familia_ext.xml model/table/tarif_tarifa_familia.xml model/table/tarif_tarifa_etiqueta_familia.xml ../catalogo_core/model/table/
git rm -q controller/tarif_familias.php View/tarif_familias.html.twig View/Macro/TarifarioComponents.html.twig \
      View/partials/familias/modal_exportar_excel.html.twig View/partials/familias/modal_importar_excel.html.twig \
      View/js/familias/index.js View/js/familias/excel-export.js View/js/familias/excel-import.js View/js/familias/utils.js \
      model/tarif_tarifa.php model/tarif_familia.php model/tarif_familia_ext.php \
      model/tarif_tarifa_familia.php model/tarif_tarifa_etiqueta_familia.php \
      model/table/tarif_tarifas.xml model/table/tarif_familia_ext.xml \
      model/table/tarif_tarifa_familia.xml model/table/tarif_tarifa_etiqueta_familia.xml
# + apply the staying-file Reference Updates (repoints / listener drop / reduced test) — staged for tarifario commit (a)

# (b) catalogo_core repo — explicit-path staging ONLY
cd ../catalogo_core
git add controller/tarif_familias.php View/tarif_familias.html.twig View/Macro/TarifarioComponents.html.twig \
        View/partials/familias/ View/js/familias/ \
        model/tarif_tarifa.php model/tarif_familia.php model/tarif_familia_ext.php \
        model/tarif_tarifa_familia.php model/tarif_tarifa_etiqueta_familia.php \
        model/table/tarif_tarifas.xml model/table/tarif_familia_ext.xml \
        model/table/tarif_tarifa_familia.xml model/table/tarif_tarifa_etiqueta_familia.xml
# WARN: do NOT `git add -A` in this repo — it would sweep the untracked openspec/changes/absorber-familias-tarifa-en-catalogo-core/;
# SDD artifacts are committed separately at archive time per plugin convention.

# (c) parent repo (repo root) — phpunit.xml only
git add phpunit.xml
```

The in-place modifications below are applied to the copied files in the catalogo_core working tree before commit (b); the Reference Updates land in tarifario commit (a); the 2 retired tests are `git rm`'d in the catalogo_core repo within commit (b). No theme overrides exist for the moved templates (verified: `themes/AdminLTE/view/` has neither `tarif_familias.html.twig` nor `Macro/TarifarioComponents.html.twig`).

### In-place modifications — controller (moved `controller/tarif_familias.php`)

| Site | Change |
|------|--------|
| :20 | DELETE `require_once 'plugins/tarifario/extras/tarif_controller.php';` |
| — | ADD `require_once 'plugins/catalogo_core/extras/fbase_controller.php';` (repo precedent: presupuestos_y_pedidos controllers require their base's fbase_controller directly; today tarif_controller.php:20 does it transitively) |
| :21, :23 | Rewrite to `plugins/catalogo_core/model/tarif_tarifa_familia.php` / `.../tarif_tarifa_etiqueta_familia.php` |
| :22, :26, :47-48, :84 | DELETE all `tarif_tarifa_articulo` traces (require, use, property, instantiation) |
| :42 | `class tarif_familias extends fbase_controller` |
| :81 | KEEP `parent::private_core();` — now resolves to `fbase_controller::private_core()` |
| after :81 | ADD guarded ensure: `private static bool $tables_ensured = false;` flag + `if (!self::$tables_ensured) { self::$tables_ensured = true; if (class_exists(\FSFramework\Plugins\catalogo_core\Init::class)) { Init::ensureFamiliasTarifaTables(); } }` |
| :698 | `$num_articulos = $this->count_tarifa_articulos($this->codtarifa, $codfamilia);` |
| :717-720 | `count_articulos($codfamilia)` body → `return $this->count_tarifa_articulos($this->codtarifa, $codfamilia);` (public signature unchanged) |
| :76 | unchanged (folder label `tarifario`, Q5) |
| :1042-1045 | unchanged (core `base/fs_chunked_upload.php`; temp dir name cosmetic) |
| header | docblock "part of tarifario" → catalogo_core |
| :606, :769, :1140 | familia.php requires untouched (intra-plugin, remain valid; S26 assertion depends on them) |

### In-place modifications — view (moved `View/tarif_familias.html.twig`)

- :758 → `<script type="module" src="plugins/catalogo_core/View/js/familias/index.js"></script>`.
- :740 `{{ csrf_meta() }}` already present (spec "CSRF meta present" satisfied, no edit). Imports/includes (:5, :7, :761, :764, :767) unchanged — global loader.

### What the reclass loses (and compensation)

`tarif_controller::private_core()` (:85-119) adds: `tarifario_check_tables()` once per process (:90-93) and idiomas/tarifas defaults loading. The moved controller needs neither loading (it reloads tarifa/codtarifa itself); the table-check trigger is replaced by the guarded `Init::ensureFamiliasTarifaTables()` call. **Nothing else is needed** — confirmed against fbase_controller source (D1) and the view's fsc.* inventory.

## Interfaces / Contracts

```php
// Moved controller (public contract — macro TarifarioComponents.html.twig:443)
public function count_articulos($codfamilia);              // int, unchanged signature
public const TARIFA_ARTICULO_TABLE = 'tarif_tarifa_articulo';

private function count_tarifa_articulos($codtarifa, $codfamilia): int
{
    $data = $this->db->select(
        'SELECT COUNT(*) AS total FROM ' . self::TARIFA_ARTICULO_TABLE
        . ' WHERE codtarifa = ? AND codfamilia = ?',
        [$codtarifa, $codfamilia]
    );
    return ($data && isset($data[0]['total'])) ? (int) $data[0]['total'] : 0;
}
```

```php
// catalogo_core Init — standalone bootstrap (public static, idempotent)
public static function ensureFamiliasTarifaTables(): void
{
    require_once FS_FOLDER . '/base/fs_model.php';
    foreach ([                       // FK-safe order, mirrors tarifario_init.php:57-59, :93-95, :103-105, :118-120
        'tarif_tarifa',              // tarif_tarifas — no plugin FK deps
        'tarif_familia_ext',         // extends core familias
        'tarif_tarifa_etiqueta_familia',
        'tarif_tarifa_familia',      // FK → tarif_tarifas; install() also re-creates tarif_tarifas (model:128, defense in depth)
    ] as $modelName) {
        $file = FS_FOLDER . '/plugins/catalogo_core/model/' . $modelName . '.php';
        if (is_file($file)) { require_once $file; }
        $fqcn = 'FSFramework\\model\\' . $modelName;
        if (class_exists($fqcn, false) && is_subclass_of($fqcn, \fs_model::class)) { new $fqcn(); }
    }
}
```

Called (try/catch + `error_log`, never breaking boot, like sibling Init methods) from `Init::init()` and `Init::upgrade()`.

## Data Flow

    page=tarif_familias ──► find_controller scans controller/ dirs ──► catalogo_core/controller/tarif_familias.php
        │                                                                       │
        │ parent::private_core() ──► fbase_controller: allow_delete             │
        │ Init::ensureFamiliasTarifaTables() ──► 4× fs_model (create-if-missing, XML-driven, both drivers)
        │                                                                       │
        ▼                                                                       ▼
    Twig render (global loader, Html.php:161-217) ◄──────────── count_articulos() ──► raw COUNT on tarif_tarifa_articulo
        └── View/Macro/TarifarioComponents.html.twig serves moved view AND 5 tarifario importers

## Test Split Design

| Assertion group | New home | Changes |
|---|---|---|
| S25 both activation orders (`..._with_tarifario_first`, `..._with_catalogo_core_first`) | `plugins/catalogo_core/tests/Integration/FamiliaTarifaResolutionTest.php`, namespace `Tests\CatalogoCore\Integration` | Verbatim bodies; guards kept |
| S25 `test_tarif_familia_remains_loadable_with_extension_surface` | same | require :131 → `plugins/catalogo_core/model/tarif_familia.php` |
| S26 `test_tarif_familias_call_site_keeps_base_semantics` | same | read path :142 → `plugins/catalogo_core/controller/tarif_familias.php` |
| S24 `test_no_global_familia_declaration_in_plugin_tree` | stays `plugins/tarifario/tests/Integration/FamiliaOverrideRemovalTest.php` | none (scans tarifario tree) |
| S26 `test_catalogo_view_call_sites_are_read_only` | stays (same file) | none (reads staying controller) |

- **State guards preserved verbatim** in both files: setUp saves `$GLOBALS['plugins']` + `fs_model_autoloader::clearCache()`; tearDown restores + clears; `registerAutoloaderWithPlugins()` (clearCache → register → refreshModelDirs) drives both-activation-orders coverage.
- **Bootstrap needs**: none new — `plugins/catalogo_core/phpunit.xml` bootstraps root `tests/bootstrap.php` (defines `FS_FOLDER`); `<directory>tests</directory>` discovers the new `Integration/` subdir; root **Plugins** suite auto-discovers both files. Keep `FamiliaTarifaResolutionTest` unexcluded (it passes in the shared process today under its current name; verify monitors it).
- **New tests (written FIRST — strict TDD)**: (1) `catalogo_core/tests/InitFamiliasTablesTest.php` — asserts the bootstrap's model list equals the FK-safe sequence and every listed file+XML exists at catalogo_core paths; plus no-op safety when model classes are absent (guard path); DB-free, root-suite safe (no fs_model instantiation — that needs a DB; the create path is a verify smoke). (2) `catalogo_core/tests/TarifFamiliasControllerContractTest.php` — source-contract test: extends `fbase_controller`; zero `tarif_controller`/`tarif_tarifa_articulo` references; `count_articulos` exists; constant == `tarif_tarifa_articulo`; delete guard routes through the helper (mirrors tarif_catalogo_view.php:1682 precedent).

## Verbatim-Preserved Requirements (Spec 2–4) & Test Disposition

These three requirements have NO enumerated modification site in this design: the only controller touches are header/requires/reclass, the :81-area ensure call, the :698/:719 count sites, and the view :758 JS src. The code moves byte-identical, and the model autoloader resolves the same class names against the same model files with unchanged schema — move-only preservation is sufficient for correctness. Disposition below is decisive per requirement.

### Familias hierarchy management — verbatim + unit tests (TDD-first) + smoke for SQL-bound paths

Preserved verbatim: `get_familias_flat` (:558), `build_tree` (:540), `flatten_tree` (:566), `ajax_reorder` (:377), `ajax_promote` (:187), `ajax_demote` (:227), capitulo recalculation (`recalculate_children_capitulos` :361, `renumber_siblings_group` :450, `renumber_children_recursive` :499), and the `get_next_capitulo` action (:157; consumed by the view at :364 and :450). Nothing here is touched by a listed modification, and the moved models/XMLs are unchanged, so reordering, subtree movement, and capitulo semantics cannot drift.

Tests (NEW, `plugins/catalogo_core/tests/TarifFamiliasHierarchyTest.php`, written FIRST in apply): DB-free, no fs_model instantiation — anonymous subclass `extends \tarif_familias` with an empty constructor (AGENTS.md anonymous-subclass precedent), explicit `require_once` of the moved controller file, Reflection-invocation of private methods. Asserts:
- `build_tree` on a fixture list of familia-like objects: children nest under their madre and `nivel_tree` increments per level (subtree/nesting semantics).
- `flatten_tree`: output preserves hierarchical order and assigns `nivel_visual` per depth (the flat tree the view renders).
- Public surface: `get_familias_flat` exists; the `get_next_capitulo` action is registered in the `process_action` switch.
Deferred to verify-phase smoke (SQL-bound: `hijas()`/`get()`/`save()` require a real DB): `ajax_reorder` descendant-following, `ajax_promote`/`ajax_demote`, and capitulo string renumbering — exercised in the dev-DB page flow.

### Toggle state management — verbatim + unit tests (TDD-first)

Preserved verbatim: `ajax_toggle_catalogo` (:269), `ajax_toggle_en_tarifa` (:294), `ajax_toggle_activa` (:319); `process_action` JSON-encodes the returned arrays (:170-178), structurally guaranteeing JSON-only responses (no HTML).

Tests (NEW, `plugins/catalogo_core/tests/TarifFamiliasToggleTest.php`, written FIRST, same DB-free mechanics): anonymous subclass with an empty constructor; public properties `tarifa_familia` (:45) and `codtarifa` (:57) set directly — `tarifa_familia` injected as a fake (anonymous class whose `get()` returns a fixture object or null and whose `save()` returns true/false); `$_POST['codfamilia']` driven per case; Reflection-invokes each private toggle. Asserts per toggle: empty `codfamilia` → `success=false`; unknown familia → `success=false` (array shape — the no-HTML guarantee); known familia → `success=true` plus the flipped `en_catalogo` / `en_tarifa` / `activa` value; save failure → `success=false`. Row-level persistence of the flip → verify-phase smoke.

### Add/edit familia in tarifa — verbatim + schema guarantee + smoke (no new unit tests)

Preserved verbatim: `save_familia` (:585, including the `etiqueta_visible` map at :626-630 and `replace_etiquetas_familia` at :632), `add_familia_to_tarifa` (:647), `get_posibles_madres` (:725). Per-tarifa etiqueta isolation is structurally enforced by the moved schema itself: `tarif_tarifa_etiqueta_familia_pkey PRIMARY KEY (codtarifa, codfamilia, etiqueta)` (tarif_tarifa_etiqueta_familia.xml:27-30) — each tarifa necessarily stores its own `visible` value, and the XML moves unchanged.

Disposition (decisive: defer to verify-phase smoke, no new unit tests): the flows are SQL-bound end to end (familia base save :612, tarifa_familia save :619, `replace_etiquetas_familia` :632) — a DB-free unit test would exercise mocks, not code. Smoke (dev DB): create a familia under a chosen madre via the modal POST and confirm it appears in `get_familias_flat`; save etiquetas for one familia under two tarifas and assert two independent rows, each with its own `visible` value. The controller-contract test additionally asserts the public API surface: `get_posibles_madres`, `get_etiquetas_familia_text`, `get_etiquetas_familia_full` exist (additions to the already-planned `TarifFamiliasControllerContractTest`).

Strict-TDD sequencing: all NEW tests (Hierarchy, Toggle, plus the already-planned bootstrap and controller-contract tests) are authored at the start of apply and run RED before the move (missing catalogo_core paths/classes), GREEN after; the trio's catalogo_core commit (b) ships tests and code together.

## Reference Updates & Grep Gate

Full anchored-gate inventory — 43 hits, all disposed (gate math: 20+1+1+16+3+2 = 43):

**10 STAYING tarifario controllers, 20 requires — REPOINT to `plugins/catalogo_core/model/...`** (decision: repoint, not drop-for-autoloader — minimal diff preserving each controller's explicit dependency declaration; these requires are unconditional and would fatal post-move. Autoloader resolution remains the runtime safety net but is only adopted where a require is being deleted anyway, i.e. the listener.):

| File (plugins/tarifario/controller/) | Sites → moved model |
|---|---|
| tarif_tarifas.php | :21 tarif_tarifa_familia, :28 tarif_tarifa_etiqueta_familia |
| tarif_articulo_edit.php | :21 tarif_familia, :23 tarif_tarifa_familia, :24 tarif_tarifa_etiqueta_familia |
| tarif_articulos.php | :21 tarif_familia, :22 tarif_tarifa |
| tarif_catalogo_view.php | :21 tarif_tarifa_familia, :23 tarif_familia, :31 tarif_tarifa_etiqueta_familia |
| tarif_configurador_opcionales.php | :21 tarif_tarifa_familia, :23 tarif_familia, :29 tarif_tarifa_etiqueta_familia |
| tarif_opcionales.php | :21 tarif_familia |
| tarif_opcional_edit.php | :21 tarif_familia, :23 tarif_tarifa_etiqueta_familia |
| tarif_opcional_precios.php | :21 tarif_tarifa, :23 tarif_familia |
| tarif_actualizar_precios.php | :21 tarif_familia |
| tarif_articulo_precios.php | :21 tarif_tarifa |

**1 STAYING model, 1 require — REPOINT**: `plugins/tarifario/model/tarif_opcional_familia.php:11` (→ `plugins/catalogo_core/model/tarif_familia.php`).

**Listener — DROP (1 hit, validated)**: `plugins/tarifario/Services/ArticlePermissionListener.php:113`; the global alias used at :114 resolves via `fs_model_autoloader::ensureGlobalAlias` (fs_model_autoloader.php:269-282). This is the only intentional drop-style site: the require guards an alias lookup the autoloader already covers.

**12 tarifario test files, ~16 requires — REPOINT to `plugins/catalogo_core/model/...`**: TarifTarifaTest:31, TarifTabPreciosTest:80, TarifTarifasGuardarTest:53, ArticlePermissionListenerTest:36, VentasArticulosQuickCreateGateCompositionTest:69, PermissionListenerInitRegistrationTest:41, ArticlePermissionListenerImportContextTest:49, 4× ExcelHierarchyService*:30-44 (2 hits each), and FamiliaOverrideRemovalTest:131 — the last is carried by the split: the staying file drops the require (reduced to S24 + catalogo_view call sites), while the catalogo_core copy (`FamiliaTarifaResolutionTest`) carries the repointed path.

**2 retired catalogo_core tests — DELETE with their 3 hits**: FrameworkAutoloaderOverrideTest:141,193; PluginOverrideIntegrationTest:99 (NOT excluded — runs today). Also remove the root phpunit.xml:107 exclusion row AND its matching comment fragment (comment item 4, phpunit.xml:72-75); the unrelated InitUpgradeTest exclusion (:100) stays.

**Moved controller — REWRITE in place (2 gate hits)**: :21/:23 → catalogo_core model paths; :22 (tarif_tarifa_articulo) dropped (see In-place modifications).
- **Grep gate (verify)** — note the `\.php` anchor, absent from the spec scenario; unanchored, the pattern false-positives on staying prefix-sharing models (`tarif_tarifa_articulo.php`, `tarif_tarifa_rol.php`, ...):

```bash
grep -rEn --include='*.php' --include='*.twig' \
  'plugins/tarifario/model/(tarif_tarifa|tarif_familia|tarif_tarifa_familia|tarif_tarifa_etiqueta_familia|tarif_familia_ext)\.php' \
  --exclude-dir=vendor --exclude-dir=openspec --exclude-dir=node_modules . \
  && echo "GATE FAILED" || echo "GATE OK"
```

Expected: zero hits (`GATE OK`). Spec intent unchanged; the anchor is a correctness refinement — tasks must use this version.

## Twig / Cache

No template work: `buildFilesystemLoader` (Html.php:161-188) + `addPluginViewPaths` (:193-217) prepend every active plugin's `view/` and `View/` dirs, theme re-prepended last (:177-180). Same template names (`tarif_familias.html.twig`, `Macro/TarifarioComponents.html.twig`, `partials/familias/*`) resolve to catalogo_core paths after the move; the macro keeps serving the 5 tarifario importers (`tarif_roles`, `tarif_tarifas`, `tarif_catalogo_view`, `partials/catalogo/toolbar.html.twig`, `tarif_configurador_opcionales`) because tarifario requires catalogo_core. `auto_reload=true` (Html.php:147) self-heals mtime drift; deploy still runs `CacheManager::clearAll()` (or clears `tmp/twig_cache`) as cheap insurance.

## Threat Matrix

N/A — no routing, shell, subprocess, VCS/PR automation, executable-file classification, or process-integration boundary is introduced. The only SQL added is a parameterized COUNT (safe-by-design, D2).

## Migration / Rollout

No data migration (identical table names; `fs_page` rows untouched — slug unchanged; page re-registration resolves same basename). Rollback: revert all THREE commits in reverse order — parent (phpunit.xml) → catalogo_core → tarifario — then clear Twig cache again. The runtime working tree is only complete after all three commits land (the parent loads the plugins as plain working-tree files; parent git state does not carry them). MySQL/PostgreSQL: bootstrap DDL is XML-driven via fs_model (engine-agnostic, same mechanism tarifario_init uses today); the only new SQL is the dialect-neutral parameterized COUNT.

## Risks & Edge Cases

| Risk | Mitigation |
|---|---|
| Missed hardcoded require → fatal at runtime | Grep gate (above, anchored) + migration inventory as checklist; controller-contract test |
| Macro contract break (`fsc.count_articulos`) | Signature unchanged; contract test asserts method + constant; verify smoke renders page |
| Silent rename of `tarif_tarifa_articulo` in tarifario breaks COUNT | Accepted residual: constant centralizes it (D3); tarifario-side renames are out of scope and grep-able |
| `fs_model::$base_dir` static cache per table | Resolved from `$GLOBALS['plugins']` scan on first use; catalogo_core active in both orders (both-orders tests prove it) |
| Relative `require_once 'plugins/...'` assumes cwd == FS_FOLDER | Preserved by framework boot and test bootstrap; moved files keep the existing style |
| Class collision `FSFramework\model\tarifa` (legacy, catalogo_core/model/core/) vs moved `tarif_tarifa` | None — distinct class names; moved files live in `plugins/catalogo_core/model/` root (autoloader scans model/ + model/core/), XMLs in existing `model/table/` (no name clash with legacy `tarifas.xml`) |
| Double table creation (Init + tarifario_init both run) | None: both instantiations are create-if-missing on the same XML definitions; `tarifario_check_tables()` calls `Init::upgrade()` first (tarifario_init.php:43) and its class_exists guards resolve to the moved files via autoloader while tarifario is active |
| `tarif_tarifa_familia::add_orden_column_if_not_exists` / migrations re-run | Already static-guarded per process and idempotent (checks column existence first) |

## Compliance Hooks

- **PHPStan level 5**: `ddev exec composer phpstan` after apply; new code (`ensureFamiliasTarifaTables`, count helper, tests) must pass.
- **Strict TDD**: tests 1–2 of "New tests" are written before their production code (bootstrap method, count helper); split tests are moved/assert-equivalent, so they run against the moved code in the same commit.
- **Commands**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`; `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml`; `ddev exec php vendor/bin/phpunit --testsuite Plugins`; smoke: standalone catalogo_core activation creates the 4 tables; `page=tarif_familias` renders with the new JS src.

## Open Questions

- None blocking. (Delivery decision resolved at apply preflight: single delivery unit, maintainer-approved `size:exception` — the trio of coordinated commits (D7) IS the unit. PR/push semantics belong to the plugin repos' own remotes; push is an explicit user decision outside apply. The parent-repo commit carries only the phpunit.xml edit (~−8 lines).)

## Amendment 1 (menu repoint + plain list retirement)

Maintainer decision after seeing the migrated page live. All facts re-verified against the post-trio tree (catalogo_core commit `8149ee8 feat: absorber paquete familias-tarifa desde tarifario`):

- The empty-state link is the ONLY hardcoded `page=ventas_familias` link in the tree (`View/tarif_familias.html.twig:566` — grep-verified across base/, src/, controller/, model/, themes/, all plugin controller/Controller/View/view/extras dirs, including `fsc.url()`-built and form-action patterns).
- Constructor site: `controller/tarif_familias.php:77` → `parent::__construct(__CLASS__, 'Familias Tarifario', 'tarifario');`.
- `page=ventas_familia` (singular) HAS a real create flow: no `cod` query param → empty model (`Controller/VentasFamilia.php:59-70`); `editarFamilia` creates when `get()` fails (:113-116). So `index.php?page=ventas_familia` is a working create entry for the empty-state link. NOT a menu page (`showonmenu => false`, getPageData :46-55).
- TWO extra consumers of the retired page discovered beyond the gathered facts (both would break silently at retirement): `Controller/VentasFamilia.php:159` (`$this->redirect('ventas_familias')` after delete) and `model/core/familia.php:88-95` (`url()`'s null-code branch returns `index.php?page=ventas_familias`). Both are repointed in the table below.
- Menu machinery: `fs_user::get_menu()` (model/core/fs_user.php:332-351) returns `fs_page->all()` (admin) or role-filtered rows with NO controller-existence filter — an orphaned `ventas_familias` row WOULD render a dead menu item → the `fs_pages` cleanup is REQUIRED, not optional. `folders()` groups by `folder != '' && show_on_menu` (base/fs_controller.php:494-504).
- Self-update verified: legacy `check_fs_page` (base/fs_controller.php:816-885) updates title/folder/show_on_menu whenever constructor args change, at visit time; the PSR-4 equivalent is `resolveOrCreatePage` (src/Core/Base/Controller.php:159-186).
- Cleanup mechanism (grounded): `new \fs_page()` → `get('ventas_familias')` (fs_page.php:150) → `->delete()` when present — `fs_page::delete()` (:186-189) runs `DELETE FROM fs_pages WHERE name = ...` and `clean_cache()` (:191-194) clears `m_fs_page_all` (the `fs_page::all()` cache key, :201-216). Idempotent by construction. Orphaned `fs_rol_access` rows referencing the dead page are inert (the menu filters through `page->all()`).
- Root phpunit.xml needs NO change: `VentasFamiliasControllerTest` was never excluded (root exclude list verified).

### Amendment 1 file changes

| File (plugins/catalogo_core/...) | Action | Detail |
|---|---|---|
| `controller/tarif_familias.php` :77 | modify | `parent::__construct(__CLASS__, 'Familias', 'catalogo');` — menu renders catálogo → Familias (replaces 'Familias Tarifario' under 'tarifario'; row self-updates on next visit) |
| `View/tarif_familias.html.twig` :566 | modify | href → `index.php?page=ventas_familia` (create form, no `cod`) |
| `Controller/VentasFamilia.php` :159 | modify | `$this->redirect('tarif_familias');` — `resolveRedirectUrl` normalizes bare page names (src/Core/Base/Controller.php:488-504) |
| `model/core/familia.php` :91 | modify | null-code `url()` branch → `"index.php?page=ventas_familia"` |
| `Init.php` | modify | `upgrade()` adds guarded private static `retireVentasFamiliasPage()`: `fs_page::get` + conditional `->delete()` (model-managed `DELETE FROM fs_pages` + `m_fs_page_all` cache clear), wrapped in try/catch + `error_log` (sibling style), idempotent |
| `controller/ventas_familias.php` | delete | legacy shim (extends the PSR-4 class) |
| `Controller/VentasFamilias.php` | delete | PSR-4 list controller (`showonmenu => true`, list + inline edit/delete) |
| `View/ventas_familias.html.twig` | delete | list view |
| `tests/VentasFamiliasControllerTest.php` | delete | page test |
| `tests/TarifFamiliasControllerContractTest.php` | modify | ADD assertions: constructor args `'Familias'`/`'catalogo'`; moved view contains zero `page=ventas_familias` and :566 targets `page=ventas_familia` |
| `tests/InitFamiliasTablesTest.php` | modify | ADD source assertion: `Init::upgrade()` wires the retirement cleanup |

### Interaction with D7 trio semantics (decision)

The trio is already committed, so Amendment 1 lands as a SEPARATE FOURTH COMMIT in the catalogo_core repo: `feat: repuntar menu familias a catalogo y retirar ventas_familias` (conventional style, no AI attribution), explicit-path `git add` only (same no-`git add -A` rule; never stages `openspec/`). Parent repo unaffected (no phpunit.xml change — the deleted test was never excluded); tarifario repo unaffected. The retirement is safe precisely because the list page has no other inbound links (verified) and its remaining consumers (redirect + `url()`) are repointed in the same commit.

### Amendment 1 risks

| Risk | Mitigation |
|---|---|
| Orphaned menu item if the `fs_pages` row survives | Cleanup REQUIRED (get_menu does not filter dead pages) — hooked in `upgrade()` so dev AND production self-clean on next activation/upgrade; smoke asserts the row is gone |
| Broken post-delete redirect / broken `familia->url()` after retirement | Both repointed in the same commit (VentasFamilia.php:159, familia.php:91); contract test covers the view repoint |
| Create path silently broken by the repoint | Verified create flow exists (no `cod` → empty model; crear on submit); Phase 7 smoke exercises create (no cod) and edit (cod) end-to-end |
