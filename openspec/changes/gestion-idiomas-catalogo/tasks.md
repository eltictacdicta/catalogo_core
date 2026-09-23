# Tasks: gestion-idiomas-catalogo (multi-language catalog descriptions)

| Field | Value |
|---|---|
| **Change** | `gestion-idiomas-catalogo` |
| **SDD owner** | `plugins/catalogo_core/openspec/` — `ownership: plugin-local` |
| **Secondary plugin** | `plugins/tarifario` (its delta is authored under this change) |
| **Artifact store** | `openspec` (core `openspec/` receives nothing, in any phase) |
| **delivery_strategy** | `auto-chain` |
| **chain_strategy** | `stacked-to-main` |
| **review_budget_lines** | `800` |
| **Runner** | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (slice 5 additionally: `-c plugins/tarifario/phpunit.xml`) |
| **strict_tdd** | `true` |
| **Lint gate** | `ddev exec composer phpstan` |

> **Note on artifact size.** The `sdd-tasks` phase skill's 530-word soft budget is exceeded
> deliberately. This phase is mandated to carry a PR chain plan with per-slice
> start/finish/verification/rollback, a full task breakdown, a dependency graph, a
> coverage matrix over 19 requirements / 54 scenarios, and the constraints handed to
> `apply`. No section is padding; the same exceedance precedent is recorded in `design.md`.

---

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated authored changed lines (additions + deletions) | **≈3,300–4,200** across the whole change |
| Session budget (`review_budget_lines`) | **800** |
| 800-line budget risk | **High** |
| Chained PRs recommended | **Yes** |
| Suggested split | PR 1 → PR 2 → PR 3 → PR 4a → PR 4b → PR 4c → PR 5 (7 slices, `stacked-to-main`) |
| Delivery strategy | `auto-chain` |
| Chain strategy | `stacked-to-main` |

```text
Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High
```

**Session budget: `review_budget_lines: 800`** (not the framework default of 400). This
matters for the guard: at 400 lines, **every** slice except 4c would be over budget at
once. At 800 lines, the chain below is sized so that each slice fits, with two slices
declared borderline.

Per-slice authored estimates:

| Slice | Estimate | Fits 800? |
|---|---|---|
| 1 — Model foundation | ≈700–820 | **Borderline** (see 1-split note) |
| 2 — Language-management UI | ≈480–600 | Yes |
| 3 — Article editor selector | ≈360–520 | Yes |
| 4a — Search + cache invalidation | ≈480–620 | Yes |
| 4b — Excel export/import | ≈700–800 | **Borderline** |
| 4c — No-context consumers (API + SQL joins + Opcional) | ≈240–330 | Yes |
| 5 — tarifario consumer migration | ≈420–560 | Yes |

**Honest overage statement.** The base five-slice split from `proposal.md` §"Size
Forecast" / `explore.md` §8 cannot fit in five PRs under this budget. Two decisions,
stated explicitly rather than silently overshot:

1. **Slice 4 must split into three, not two.** The orchestrator's `4a` (search + cache
   invalidation) is confirmed. But `4b` as literally scoped ("Excel export/import + API +
   SQL joins") measured at **≈870 authored lines** — over the 800 budget, because
   `tests/Services/ArticuloExcelIdiomasTest.php` alone carries 14 scenarios. The Excel
   unit and the no-context-reader unit are cohesive and independent, so `4b` is carved
   into **4b (Excel export/import)** ≈700–800 and **4c (API + SQL joins + VentasOpcional
   + trait)** ≈240–330. That is the one honest slicing pass; further splitting is not
   attempted.
2. **Slice 1 is borderline (≈700–820).** Moving D-03/D-04 (search + cache invalidation)
   out of slice 1 into 4a is what brings it down from a certain overage. If `apply`'s
   first diff measurement exceeds 800, cut at the **pre-declared 1a/1b boundary** below
   rather than shipping over. Do not shrink code, comments, or tests to reach the number.

**Pre-declared split boundaries** (use only if a diff measurement exceeds 800):

- **Slice 1 → 1a / 1b.** `1a` = tasks 1.1–1.9 (`catalogo_idioma` registry + last-language
  guard + orphan purge, with `tests/CatalogoIdiomaInvariantsTest.php`,
  `tests/CatalogoIdiomaDeleteCleanupTest.php`, `tests/CatalogoIdiomaTest.php`). `1b` =
  tasks 1.10–1.17 (`articulo_descripcion` clearing + `articulo` language API, with
  `tests/ArticuloDescripcionClearingTest.php`, `tests/ArticuloDescripcionFrozenBaseTest.php`,
  `tests/ArticuloMultiidiomaTest.php`). `1b` depends on `1a`'s
  `get_effective_default_code()`.
- **Slice 4b → 4b₁ / 4b₂.** `4b₁` = export (4b.1, 4b.2, 4b.9-export) + the three Export
  Excel scenarios. `4b₂` = import (4b.3–4b.8) + the seven Import/Persistencia scenarios.

---

## PR Chain Plan

`stacked-to-main`: every slice's PR targets `main` and merges in order. Each slice is
independently test-green and reviewable; every slice is non-destructive (no column
dropped, no legacy data rewritten).

### Slice 1 — Model foundation

- **Start**: `catalogo_idioma::get_default()` returns `false` when no `por_defecto` row
  exists; nothing calls `save()`/`delete()`; the last language is deletable; deleting a
  language orphans its `articulo_descripciones` rows (defect **c**); `articulo_descripcion::test()`
  rejects an empty `descripcion` (defect **b**); `articulo::get_descripcion_idioma()` hard-codes
  `'es'`; `set_descripcion_idioma()` mirrors into `$this->descripcion`.
- **Finish**: exactly one **active** default always resolves
  (`get_effective_default_code()`), `set_default()` is a flag flip that touches no
  description row, the default cannot be deactivated, the last language cannot be
  deleted, deleting a language removes its description rows, clearing semantics live in
  `articulo_descripcion::save()`, the read chain is requested → configured default →
  base → `''` and materialises nothing, and the base column is never mirrored.
- **Autonomous scope**: the whole model contract (GDI-01 model half, GDI-02, GDI-03,
  GDI-05, GDI-06, GDI-07) plus the idempotent orphan purge. No UI, no search, no
  Excel, no tarifario.
- **Verification**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
  (full plugin suite, not a filter) + `ddev exec composer phpstan`.
- **Rollback**: revert the slice's commits. The orphan purge is the only irreversible
  step and removes only rows whose `codidioma` has no registry row (unreachable through
  every surface); the operator-level pre-migration dump is the safety net.
- **Estimated authored lines**: ≈700–820. **Borderline** → uses the 1a/1b boundary if measured over.

### Slice 2 — Language-management UI  *(independent of slice 3)*

- **Start**: `catalogo_idioma::url()` returns `index.php?page=ventas_articulos#idiomas`
  and that anchor does not exist; no controller action mutates `catalogo_idiomas`; no
  CRUD translation keys exist.
- **Finish**: an administrator can create, rename, activate, deactivate, set-default and
  delete a language from the `#idiomas` section on `ventas_articulos`; a non-administrator
  or a bad CSRF token persists nothing; a GET cannot mutate; the page carries no
  `#[AdminOnly]`; `catalogo_idioma::url()` resolves to a real section.
- **Autonomous scope**: GDI-01 surface half and GDI-04 completely. Consumes slice 1's
  model invariants; introduces no model change.
- **Verification**: `tests/CatalogoIdiomaManagementTest.php` +
  `tests/CatalogoIdiomaPermissionTest.php` green; `tests/VentasArticulosListAbsorptionTest.php`
  stays green; full plugin suite + PHPStan.
- **Rollback**: revert the slice's commits (controller dispatch, trait, `#idiomas`
  panel, translation keys). Clear the Twig cache after reverting the view.
- **Estimated authored lines**: ≈480–600. Fits.

### Slice 3 — Article editor language selector  *(independent of slice 2)*

- **Start**: the `#multiidioma` pane renders the base `sdescripcion` textarea plus a
  per-language loop, so the default language renders twice and the posted
  `descripcion_<default>` is discarded (defect **g**); empty input is skipped (defect **b**).
- **Finish**: exactly one description / short-description pair is rendered for the
  selected language (`ordernum`/`codidioma` carried in the query param and a hidden
  field); the posted default-language value is persisted; a both-empty pair clears that
  language's row; no cross-language write is possible; `#multiidioma`, the
  `tab_multiidioma.html.twig` include and the two article hook markers keep their names
  and positions.
- **Autonomous scope**: GDI-11, GDI-12, the ART-01/ART-05 re-expression, and the two
  locked controller/view tests. No model, no consumer change.
- **Verification**: `tests/VentasArticuloControllerTest.php` +
  `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` green;
  `tests/Integration/CatalogoCoreHookMarkersTest.php` green **unedited**; full plugin
  suite + PHPStan.
- **Rollback**: revert the slice's commits. Clear the Twig cache (view change).
- **Estimated authored lines**: ≈360–520. Fits.

### Slice 4a — Language-agnostic search + cache invalidation

- **Start**: `articulo::search()` matches only `articulos.descripcion` (a non-default
  language's article is unfindable); `articulo_descripcion::save()`/`delete()` invalidate
  nothing, so cached `articulos_search_*` results survive description writes (defect **f**).
- **Finish**: one isolated language-agnostic predicate (`LEFT JOIN articulo_descripciones`
  `OR`) drives text search; every description write invalidates `articulos_search_*`; the
  D11 deviation note is inline in the predicate; every base column reference is
  `a.`-qualified and `ORDER BY a.referencia ASC` is DISTINCT-safe.
- **Autonomous scope**: GDI-08 + GDI-09 (D-03, D-04) completely, including the
  "no writer outside `articulo_descripcion`" invariant.
- **Verification**: `tests/ArticuloSearchCacheInvalidationTest.php` +
  `tests/ArticuloSearchMultiidiomaTest.php` green;
  `tests/Services/ArticuloSearchQueryBuilderTest.php` updated and green; full plugin
  suite + PHPStan.
- **Rollback**: revert the slice's commits. The predicate and the invalidator are
  additive; the `ORDER BY` change reverts to `lower(referencia)`.
- **Estimated authored lines**: ≈480–620. Fits.

### Slice 4b — Excel export/import with per-language columns  *(depends on 4a)*

- **Start**: the export emits exactly seven base columns and reads `articulos.descripcion`;
  the import wizard maps a single `descripcion` field and has no target language.
- **Finish**: the export appends one `descripcion_<cod>` + one `descripcion_corta_<cod>`
  column per **active** language (default first, then by `codidioma`), base headers
  byte-identical, base `Descripción` cell resolved through the configured default; the
  import targets an explicit language (defaulting to the configured default); unknown or
  deactivated locale columns are ignored with a byte-identical registry; a mapped pair
  persists to the target language's row and an empty pair clears it; the language path
  never writes `articulos.descripcion`.
- **Autonomous scope**: the `articulos-excel-import-export` delta (Export Excel, Import
  wizard 3 pasos, Persistencia) and GDI-10's Excel leg. Depends on slice 1's
  `set_descripcion_idioma()` and on slice 4a's D-03 for the per-row invalidation claim.
- **Verification**: `tests/Services/ArticuloExcelIdiomasTest.php` green; the three
  existing Excel service tests updated and green; full plugin suite + PHPStan.
- **Rollback**: revert the slice's commits; `EXPORT_HEADERS` and `FIELD_CATALOG` were
  never edited, so the base shape returns intact.
- **Estimated authored lines**: ≈700–800. **Borderline** → uses the 4b₁/4b₂ boundary if measured over.

### Slice 4c — `catalogo_core` no-context consumers

- **Start**: `CatalogoApiService`, `tarif_articulo_precio`, `tarif_tarifa_articulo`,
  `VentasOpcional` and `TarifarioOpcionalStateTrait` read the raw base column; two SQL
  joins fetch base descriptions directly.
- **Finish**: every no-context `catalogo_core` reader resolves the configured default
  language (`get_descripcion_idioma()`, or `COALESCE(d.descripcion, a.descripcion)` in
  SQL); quick-create deliberately keeps writing only the base column (D-13).
- **Autonomous scope**: GDI-10's `catalogo_core` legs.
- **Verification**: `tests/ConsumidoresIdiomaDefaultTest.php` green; full plugin suite +
  PHPStan; a DB-backed smoke of the composed SQL in `tarif_tarifa_articulo` (`test scope:
  plugin-suite`).
- **Rollback**: revert the slice's commits; each reader is an isolated substitution.
- **Estimated authored lines**: ≈240–330. Fits.

### Slice 5 — `tarifario` consumer migration  *(depends on slice 4)*

- **Start**: `tarif_grupo_articulo`, `tarif_historial_precios`, `tarif_actualizar_precios`
  and `tarif_roles` read the raw base column; `extras/tarif_controller.php:240,243` calls
  the misused `descripcion($this->codidioma, 50)` and the **nonexistent**
  `get_descripcion($this->codidioma)` (defect **a**).
- **Finish**: every tarifario no-context reader resolves the configured default through
  the language API; both dead calls are corrected; the four `tarif_controller` subclasses
  still instantiate; `tarif_idioma`/`tarif_descripcion` stay loadable; the already-correct
  readers are byte-unchanged.
- **Autonomous scope**: `R-TAR-HOOK-013` completely.
- **Verification**: the four new `plugins/tarifario/tests/**` files green via
  `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml`; the root Plugins
  suite green (`ddev exec php vendor/bin/phpunit --testsuite Plugins`).
- **Rollback**: revert the slice's commits; no shared state with slices 1–4.
- **Estimated authored lines**: ≈420–560. Fits.

---

## Task Breakdown

`RED` = write the failing test first. `GREEN` = make it pass. `VERIFY` = assertion task
with no production edit. Requirement tags: `GDI-xx`, `ART-xx`, the three
`articulos-excel-import-export` requirements, `R-TAR-HOOK-013`. Decision tags: `D-xx`
(design), `D1`–`D12` (preproposal).

### Slice 1 — Model foundation

- [x] 1.1 **RED** — Author `plugins/catalogo_core/tests/CatalogoIdiomaInvariantsTest.php` with the four GDI-02 scenarios (setting a new default is a flag flip and moves no `articulo_descripciones` row; deactivating the default is rejected; default resolution is total and deterministic; the invariant holds after every mutation). Run `--filter CatalogoIdiomaInvariantsTest` → RED. `[GDI-02; D-02]`
- [x] 1.2 **GREEN** — In `plugins/catalogo_core/model/core/catalogo_idioma.php` add `public function get_effective_default_code(): string` (configured **active** default → lowest active `codidioma` → `self::DEFAULT_CODE`), add `AND activo = TRUE` to `get_default()`, and add the private `normalize_default()` helper. `[GDI-02; D-02]`
- [x] 1.3 **RED** — Extend `tests/CatalogoIdiomaInvariantsTest.php` with `set_default()` assertions: explicit error and `false` for an unknown code and for an inactive target; success clears the previous flag in the same operation. `[GDI-02; D-02]`
- [x] 1.4 **GREEN** — In `catalogo_idioma.php` add `public function set_default(string $codidioma): bool` wrapped in `begin_transaction()`/`commit()` with `rollback()` on failure (clear previous `por_defecto`, force `activo = TRUE` on the target, then `normalize_default()`); issue **no** `articulo_descripciones` statement. `[GDI-02; D-02]`
- [x] 1.5 **GREEN** — In `catalogo_idioma.php::save()` reject deactivating the current default with an explicit error and `false`, force `activo = TRUE` when `por_defecto` is set, clear other flags, upsert, then `normalize_default()` — all inside one transaction (two PHP statements to avoid MySQL 1093). `[GDI-02; D-02]`
- [x] 1.6 **RED** — Author `plugins/catalogo_core/tests/CatalogoIdiomaDeleteCleanupTest.php` with the two GDI-03 scenarios (last-language deletion refused with an explicit error and the row survives; deleting a language leaves no readable `articulo_descripciones` row for it and leaves the other language's rows untouched). `[GDI-03; D-01, D-02]`
- [x] 1.7 **GREEN** — In `catalogo_idioma.php::delete()` add the `COUNT(*) <= 1` last-language guard, keep the "default cannot be deleted" guard, and inside one transaction `DELETE FROM articulo_descripciones WHERE codidioma = :cod` → `DELETE FROM catalogo_idiomas WHERE codidioma = :cod` → `normalize_default()`; commit. `[GDI-03; D-01, D-02]`
- [x] 1.8 **CONFIRM** — Do **not** add a `codidioma` FK to `plugins/catalogo_core/model/table/articulo_descripciones.xml` (D-01 rejects it: the `FS_FOREIGN_KEYS` gate, `ADD CONSTRAINT` failure on installs with orphans, and MySQL constraint drift). Completion condition: `git diff -- plugins/catalogo_core/model/table/articulo_descripciones.xml` is empty. `[GDI-03; D-01]`
- [x] 1.9 **RED → GREEN** — Extend `plugins/catalogo_core/tests/Services/CatalogLegacyTableMigrationTest.php` with an orphan-purge case (orphan rows removed once; a second run is a no-op) → RED; then add `Services/CatalogLegacyTableMigration::purgeOrphanDescriptions(\fs_db2 $db)` (table-existence guard, `LIMIT 1` pre-check, per-driver `DELETE`) and call it from `migrateIfNeeded()`. `[GDI-03; D-01]`
- [x] 1.10 **RED** — Author `plugins/catalogo_core/tests/ArticuloDescripcionClearingTest.php` with the four GDI-07 scenarios (both fields empty deletes the row; a `descripcion_corta`-only row is preserved — tagged as a **local decision** in a comment; an empty `descripcion` is accepted by `test()`; a cleared language falls back at read time with no empty row materialised). `[GDI-07; D-11, D-12]`
- [x] 1.11 **GREEN** — In `plugins/catalogo_core/model/core/articulo_descripcion.php` make `test()` accept an empty `descripcion`, and make `save()` delete the row when the submitted `descripcion` and `descripcion_corta` are both empty (no-op returning `true` when no row exists). `[GDI-07; D-11, D-12]`
- [x] 1.12 **RED** — Modify `plugins/catalogo_core/tests/ArticuloMultiidiomaTest.php` for GDI-05 (requested language wins; the fallback uses the configured default and **not** a hard-coded `'es'`; base column then `''` terminal; repeated resolution leaves `articulo_descripciones` byte-identical). `[GDI-05; D-09]`
- [x] 1.13 **GREEN** — In `plugins/catalogo_core/model/core/articulo.php` change `get_descripcion_idioma($codidioma = null)` and `descripcion_idioma($codidioma = null, $len = 120)` to resolve `get_effective_default_code()` **once** into a local variable, keep every leg a pure read, and let an unknown code fall through the chain. `[GDI-05; D-09]`
- [x] 1.14 **RED** — Author `plugins/catalogo_core/tests/ArticuloDescripcionFrozenBaseTest.php` with the two GDI-06 scenarios (a default-language save leaves `articulos.descripcion` unchanged and writes only the description row; no backfill happens at plugin boot). `[GDI-06; D-01, D-10]`
- [x] 1.15 **GREEN** — In `articulo.php::set_descripcion_idioma()` remove the mirror branch (`:1451-1455`), drop the trailing `$art->save()`, reset `$this->descripciones_catalogo = null`, and `return $d->save();` so clearing + cache ownership stays in `articulo_descripcion`. `[GDI-06; D-01, D-10]`
- [x] 1.16 **GREEN** — In `articulo.php` add `get_descripcion_corta_idioma($codidioma = null)`: resolve the language via `get_effective_default_code()`, iterate the cached `get_descripciones()`, return that language's `descripcion_corta` or `''` — **same-language only, no fallback chain** (carry the local-decision comment). `[GDI-11 prefill; D-08, R2]`
- [x] 1.17 **RED → GREEN** — Extend `plugins/catalogo_core/tests/CatalogoIdiomaTest.php` for `get_default()`, `all_activos()`, `get_effective_default_code()`, `set_default()` and the registry `test()` bounds (`codidioma` 2–5 chars, `nombre` 1–50 chars). `[GDI-01 model half, GDI-02; D-02]`

### Slice 2 — Language-management UI

- [x] 2.1 **RED** — Author `plugins/catalogo_core/tests/CatalogoIdiomaManagementTest.php` with the two GDI-01 scenarios: the full lifecycle round trip (create, rename, deactivate, reactivate, each mutation persisted and reflected) with `catalogo_idioma::url()` resolving to a real section, and the invalid-payload rejection (`test()` `FALSE`, an error reported, nothing persisted). `[GDI-01; D-06]`
- [x] 2.2 **GREEN** — In `plugins/catalogo_core/Controller/VentasArticulos.php::privateCore()` dispatch language mutations only when `$this->request->isMethod('POST') && $this->request->request->has('idioma_action')`, after `load_list_idiomas()`. `[GDI-01, GDI-04; D-06]`
- [x] 2.3 **GREEN** — In `VentasArticulos.php` add `gestionarIdioma(Request $request)`: `validateFormToken()` gate (explicit `language-csrf-invalid` error on failure), then `empty($this->user->admin)` gate (`language-admin-only`), then dispatch `save` | `toggle_active` | `set_default` | `delete`; never read `idioma_action` from the query string. `[GDI-01, GDI-04; D-06, D-12]`
- [x] 2.4 **RED** — Author `plugins/catalogo_core/tests/CatalogoIdiomaPermissionTest.php` with the three GDI-04 scenarios (non-administrator with a valid token persists nothing; missing/invalid CSRF persists nothing and the rejection is explicit; a GET cannot mutate) plus a source assertion that `Controller/VentasArticulos.php` carries **no** `#[AdminOnly]`. `[GDI-04; D-12]`
- [x] 2.5 **GREEN** — In `plugins/catalogo_core/extras/VentasArticulosListTrait.php` add `public array $idiomas_todos = []` and load `(new catalogo_idioma())->all()` inside `load_list_idiomas()`; expose `public array $idiomas_todos = []` on `VentasArticulos`. `[GDI-01; D-06]`
- [x] 2.6 **GREEN** — In `plugins/catalogo_core/View/ventas_articulos.html.twig` add the `#idiomas` panel (between the list and the modals): a table of all languages with `activo` / `por_defecto` badges, one form per row and an add form, each with `{{ csrf_field() }}` and a hidden `idioma_action`. `[GDI-01; D-06]`
- [x] 2.7 **GREEN** — In `plugins/catalogo_core/translations/messages.es_ES.yaml` and `messages.en_EN.yaml` add the 18 `language-*` keys listed in design D-06; reuse `languages`, `default-language`, `article-multi-language`, `short-description`, `no-languages-configured` — do not redefine them. `[GDI-01; D-06]`
- [x] 2.8 **VERIFY** — `tests/CatalogoIdiomaManagementTest.php` + `tests/CatalogoIdiomaPermissionTest.php` green; `tests/VentasArticulosListAbsorptionTest.php` stays green; `ddev exec composer phpstan` clean. `[GDI-01, GDI-04]`

### Slice 3 — Article editor language selector

- [x] 3.1 **RED** — Modify `plugins/catalogo_core/tests/VentasArticuloControllerTest.php`: rewrite the `fsc.articulo.descripcion` assertion to assert the selector (`fsc.idiomas` iteration, `&codidioma=`, exactly one `descripcion_<selected>` / `descripcion_corta_<selected>` pair) and keep the four locked literals (`#multiidioma`, `tab_multiidioma.html.twig`, `public array $idiomas`, `saveMultiidiomaDescriptions`). `[GDI-11, GDI-12; D-05]`
- [x] 3.2 **RED** — Modify `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`: replace every `sdescripcion` POST with `codidioma` + `descripcion_<cod>`; add the posted-default-not-discarded, no-cross-language-write and empty-pair-clears assertions; keep `tab_multiidioma.html.twig` in its locked marker list. `[GDI-11, ART-01; D-05]`
- [x] 3.3 **GREEN** — Rewrite `plugins/catalogo_core/View/partials/articulos/tab_multiidioma.html.twig` **in place** (the include path is asserted by two locked tests): a `btn-group` of links over `fsc.idiomas` targeting `{{ fsc.url() }}&codidioma=<cod>#multiidioma`, an `active` class on the selected one, a hidden `codidioma`, and exactly one `descripcion_<selected>` textarea + `descripcion_corta_<selected>` input prefilled with `get_descripcion_idioma(fsc.codidioma)` / `get_descripcion_corta_idioma(fsc.codidioma)`; keep the `id="multiidioma"` panel. `[GDI-11, GDI-12; D-05, D-08]`
- [x] 3.4 **GREEN** — In `plugins/catalogo_core/View/ventas_articulo.html.twig` remove the base `sdescripcion` textarea (`:121`) and its `description-default-language-hint` (`:122-124`); leave `#multiidioma` (`:106`), the `partials/articulos/tab_multiidioma.html.twig` include (`:342`), `render_hook('ventas_articulo_tabs_after', …)` (`:90`) and `render_hook('ventas_articulo_tab_pane_after', …)` (`:384`) byte-unchanged. `[GDI-11, GDI-12, ART-05; D-05]`
- [x] 3.5 **GREEN** — In `plugins/catalogo_core/Controller/VentasArticulo.php` add `public string $codidioma = ''` and `resolve_codidioma(Request $request)` (posted `codidioma` → GET `codidioma` → validate against `(new catalogo_idioma())->all_activos()` → `get_effective_default_code()`); set it from `loadCatalogData()`; remove `$art->descripcion = sdescripcion` (`:461`). `[GDI-11; D-05]`
- [x] 3.6 **GREEN** — Rewrite `VentasArticulo.php::saveMultiidiomaDescriptions(\articulo $art, Request $request)` per design D-05: return early when `referencia` is empty; resolve the single destination language with `resolve_codidioma()`; read only `descripcion_<cod>` / `descripcion_corta_<cod>`; call `$art->set_descripcion_idioma(...)`; remove both `continue` branches (`:833-836`, `:847-849`) and the trailing `$art->save()`; never iterate `descripcion_*` keys. `[GDI-11, ART-01; D-05, D-01]`
- [x] 3.7 **VERIFY** — `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` passes **unchanged** (no edit): it is the regression guard for the four frozen names (`ventas_articulo_tabs_after`, `ventas_articulo_tab_pane_after`, `ventas_opcional_tabs_after`, `ventas_opcional_tab_pane_after`) and their positions. If it fails, the view change is wrong — do not edit the test. `[GDI-12, ART-05]`
- [x] 3.8 **VERIFY** — The `articulo-detalle-canonico` ART-01/ART-05 MODIFIED delta is **already authored** at `plugins/catalogo_core/openspec/changes/gestion-idiomas-catalogo/specs/catalogo-core/articulo-detalle-canonico/spec.md`; implement to it and never edit it. `apply` must not write into `plugins/catalogo_core/openspec/specs/**` (archive-time merge only). Completion: `git status --short plugins/catalogo_core/openspec/specs/` is empty. `[ART-01, ART-05]`

### Slice 4a — Language-agnostic search + cache invalidation

- [x] 4a.1 **RED** — Author `plugins/catalogo_core/tests/ArticuloSearchCacheInvalidationTest.php` with the two GDI-08 scenarios (a non-default-language description write invalidates a cached `articulos_search_<query>`; the clearing path's delete invalidates it). Reset `articulo::$search_tags` / `$cleaned_cache` by Reflection in `setUp()`. `[GDI-08; D-03]`
- [x] 4a.2 **GREEN** — In `plugins/catalogo_core/model/core/articulo.php` add `public static function invalidate_search_cache(): void` (read the `articulos_searches` array from a static `new \fs_cache()`, `delete('articulos_search_' . $tag)` per entry, return early when absent) and make `clean_cache()` delegate to it while keeping the `self::$cleaned_cache` once-per-request guard. `[GDI-08; D-03]`
- [x] 4a.3 **GREEN** — In `plugins/catalogo_core/model/core/articulo_descripcion.php` call `articulo::invalidate_search_cache()` from `save()` after a successful insert/update **and** from the empty-pair → `delete()` branch, and from `delete()`. `[GDI-08; D-03]`
- [x] 4a.4 **RED** — Author `plugins/catalogo_core/tests/ArticuloSearchMultiidiomaTest.php` with the three GDI-09 scenarios (a term living only in a non-default language is findable; a cleared translation stops matching; exactly one language-agnostic predicate exists and carries the deviation note — grep gate). `[GDI-09; D-04]`
- [x] 4a.5 **GREEN** — In `plugins/catalogo_core/Services/ArticuloSearchQueryBuilder.php` add `languageDescriptionPredicate(string $like, callable $quote)` (the single `(lower(a.descripcion) LIKE … OR lower(d.descripcion) LIKE …)` predicate carrying the **verbatim D11 deviation note**) and `languageDescriptionJoin()` (`LEFT JOIN articulo_descripciones d ON d.referencia = a.referencia`); qualify every base reference with `a.`; replace each `lower(descripcion) LIKE` term with the helper so the single-word and multi-word paths share it. `[GDI-09; D-04, D11]`
- [x] 4a.6 **GREEN** — In `articulo.php::search()` emit `SELECT DISTINCT a.* FROM <table> a` + `ArticuloSearchQueryBuilder::languageDescriptionJoin()`, change the ordering to `ORDER BY a.referencia ASC` (DISTINCT-safe), and `a.`-qualify `referencia`, `codfamilia`, `stockfis` and `bloqueado` in `buildSearchWhereClause()`; note the collation rationale in a comment. `[GDI-09; D-04]`
- [x] 4a.7 **RED → GREEN** — Update `plugins/catalogo_core/tests/Services/ArticuloSearchQueryBuilderTest.php`: expected strings become `a.`-qualified, plus assertions for the `ORDER BY a.referencia ASC` form and the single language predicate. `[GDI-09; D-04]`
- [x] 4a.8 **VERIFY** — Grep gate for the D-03 invariant: no `INSERT`/`UPDATE`/`DELETE` against `articulo_descripciones` outside `model/core/articulo_descripcion.php` (the activation-time copy in `CatalogLegacyTableMigration` is the only allowed reader/writer elsewhere). `[GDI-08; D-03]`

> **Annotated (slice 4a-fix, `slice-4a-fix-writer-gate`).** The gate's original single allowlist
> silently **permitted** `model/core/catalogo_idioma.php` — the slice-1a language-delete cleanup,
> added for GDI-03 — to write `articulo_descripciones` without invalidating the search cache. That
> was a real GDI-08 gap and the reason D-03's proof ("the only remaining writer is
> `CatalogLegacyTableMigration`", design.md:169) was incomplete: it assumed a single writer file.
> Task 4a.8 is now satisfied by (a) the fix that makes `catalogo_idioma::delete()` invalidate the
> search cache after a successful commit, and (b) a gate that splits `$invalidating` writers (must
> call `articulo::invalidate_search_cache()`) from the explicitly exempt activation migration,
> instead of one permissive list. See `apply-progress.md` → "Slice-4a-fix task status". `[GDI-08; D-03]`

### Slice 4b — Excel export/import with per-language columns

- [x] 4b.1 **RED** — Author `plugins/catalogo_core/tests/Services/ArticuloExcelIdiomasTest.php` with the three **Export Excel** scenarios (base seven headers byte-identical; locale columns appended in deterministic order with a deactivated language omitted; a non-default language's text exported under its suffix). `[Export Excel; D-07]`
- [x] 4b.2 **GREEN** — In `plugins/catalogo_core/Services/ArticuloExcelExportService.php` leave `EXPORT_HEADERS` untouched and add `descriptionHeaders(array $idiomas, string $codidioma_defecto): array` (default language first, then the remaining active languages by `codidioma`; `descripcion_<cod>` then `descripcion_corta_<cod>` per language); add trailing optional params to `buildSpreadsheet()`; write the locale pairs after `Bloqueado`; shift `writeFeatureCells()`'s offset by the locale column count; resolve the base `Descripción` cell with `get_descripcion_idioma(null)` for an `articulo` row. `[Export Excel, GDI-10; D-07]`
- [x] 4b.3 **RED** — Extend `tests/Services/ArticuloExcelIdiomasTest.php` with the four **Import wizard 3 pasos** scenarios (target language defaults to the configured default and an explicit target overrides it; an unknown `descripcion_zz` column is ignored with a byte-identical registry; a deactivated language's column is ignored; a legacy workbook with only the seven base columns imports unchanged). `[Import wizard 3 pasos; D-07]`
- [x] 4b.4 **GREEN** — In `plugins/catalogo_core/Services/ArticuloExcelImportWizardService.php` leave `FIELD_CATALOG` untouched and add `languageFieldCatalog(array $idiomas): array` (`descripcion_<cod>` / `descripcion_corta_<cod>`, label `Descripción (<nombre>)`), extending `preview(..., array $idiomas = [])` to feed those aliases into `suggestMapping()`'s `$extraFields` and merge their options into `fieldOptions()`; `$idiomas = []` must reproduce today's behaviour exactly. `[Import wizard 3 pasos; D-07]`
- [x] 4b.5 **GREEN** — In `plugins/catalogo_core/Services/ArticuloExcelRowUpdater.php` add `applyDescripcionIdioma()` (route the mapped locale value through `articulo::set_descripcion_idioma()`); leave the base `applyDescripcion` case untouched. `[Persistencia; D-07, D-10]`
- [x] 4b.6 **GREEN** — In `plugins/catalogo_core/process_excel_wizard_dispatch.php` read `target_codidioma`, validate it against `(new catalogo_idioma())->all_activos()` (absent/unknown/inactive → `get_effective_default_code()`), split mapped rows into base vs locale fields, apply locale fields to the **single target language's** row (suffix equal to the target wins, otherwise the first mapped locale column in default-first order), ignore unknown/inactive suffixes silently, and never call `catalogo_idioma::save()`. `[Import wizard 3 pasos, Persistencia; D-07]`
- [x] 4b.7 **GREEN** — In `plugins/catalogo_core/View/partials/articulos/modal_importar_excel_wizard.html.twig` add the target-language `<select name="target_codidioma">` (active languages, configured default first and preselected — the name matches what `process_excel_wizard_dispatch.php` reads) and in `plugins/catalogo_core/View/js/articulos-excel-import-wizard.js` push `target_codidioma=` alongside `default_action` / `round_price` in `startApply()`. The controller pass-through of `$this->idiomas` to `buildSpreadsheet()` / `preview()` landed in the same work unit (`slice-4b-wiring`), so the locale columns and the target language are live. `[Import wizard 3 pasos; D-07]`
- [x] 4b.8 **RED** — Extend `tests/Services/ArticuloExcelIdiomasTest.php` with the three **Persistencia** scenarios (a mapped populated pair persists on the target language's row; an empty mapped pair deletes that language's row; `articulos.descripcion` is unchanged when the default language is written). `[Persistencia, GDI-06; D-07, D-01]`
- [x] 4b.9 **VERIFY** — Update `plugins/catalogo_core/tests/Services/ArticuloExcelExportServiceTest.php` for the additive locale layout while asserting the seven base headers stay byte-identical; touch `ArticuloExcelImportWizardServiceTest.php` and `ArticuloExcelRowUpdaterTest.php` only where the additive shape changes expectations; full plugin suite + PHPStan green. `[Export Excel, Import wizard 3 pasos; D-07]`

> **Annotated (slice 4b, applied).** The measured authored diff was **853 lines**
> (`4b₁` 144 + `4b₂` 709) against the 800-line session budget, so the pre-declared
> `4b₁`/`4b₂` boundary **was used**: the export work unit (`8aa5f420`) and the import
> work unit (`5a36de09`) landed as two review units, each under budget. No code,
> comment, blank line, doc or test was shrunk to fit. Two additive test methods and
> the new `ArticuloExcelIdiomasTest.php` carry 19 tests over 10 spec scenarios.
> **Annotated (slice-4b-wiring, applied).** `4b.7` was deferred in the first `4b`
> run because that launch prompt excluded any view/controller change, which left the
> locale columns and the target-language parameter inert in production. The follow-up
> work unit `slice-4b-wiring` closed it: the controller now forwards `$this->idiomas`
> and the resolved `$this->codidioma_defecto` to `buildSpreadsheet()` (filtered, full
> and template call sites) and `$this->idiomas` to `preview()`, the modal renders the
> `target_codidioma` select (default first, preselected) and the wizard JS sends
> `target_codidioma` with the apply request. See `apply-progress.md` → "Slice-4b
> task status" and "Deviations".

### Slice 4c — `catalogo_core` no-context consumers

- [ ] 4c.1 **RED** — Author `plugins/catalogo_core/tests/ConsumidoresIdiomaDefaultTest.php` with the two `catalogo_core` GDI-10 scenarios (with a configured default different from `es`, `CatalogoApiService` serialises the configured default language's text; `VentasOpcional::buscarArticulo()` returns each article's configured default text). Keep the spec-named Spanish path for traceability. `[GDI-10; D-10]`
- [ ] 4c.2 **GREEN** — In `plugins/catalogo_core/Model/CatalogoApiService.php:50` emit `'descripcion' => $a->get_descripcion_idioma()`; in `plugins/catalogo_core/Controller/VentasOpcional.php:394,397` use `$art->descripcion_idioma(null, 50)` / `$art->get_descripcion_idioma()`. `[GDI-10; D-10]`
- [ ] 4c.3 **GREEN** — In `plugins/catalogo_core/model/tarif_articulo_precio.php:421` add `LEFT JOIN articulo_descripciones d ON d.referencia = a.referencia AND d.codidioma = :def` with `COALESCE(d.descripcion, a.descripcion)` in the select list, binding the configured default. `[GDI-10; D-10]`
- [ ] 4c.4 **GREEN** — In `plugins/catalogo_core/model/tarif_tarifa_articulo.php` apply the same driver-specific join + `COALESCE` to the six queries (`:171,269,295,321,347,376`) and replace the `lower(a.descripcion) LIKE` at `:385` with `COALESCE(d.descripcion, a.descripcion)`. `[GDI-10; D-10]`
- [ ] 4c.5 **GREEN** — In `plugins/catalogo_core/extras/TarifarioOpcionalStateTrait.php:111` replace the `get_default()` + hard-coded `'es'` resolution with `get_effective_default_code()`. `[GDI-10; D-02, D-10]`
- [ ] 4c.6 **VERIFY** — `Controller/VentasArticulos.php::nuevoArticulo()` keeps writing only `articulos.descripcion` (D-13) and adds no language row; `git diff` shows no language write on that path. `[GDI-06; D-13]`

### Slice 5 — `tarifario` consumer migration

- [ ] 5.1 **RED** — Author `plugins/tarifario/tests/Model/TarifGrupoArticuloIdiomaTest.php` with the "group-article reads use the configured default" scenario. `[R-TAR-HOOK-013; D-10]`
- [ ] 5.2 **GREEN** — In `plugins/tarifario/model/tarif_grupo_articulo.php` add the join + `COALESCE` at `:150` and make `:401-405` run `LOWER(...) LIKE` over the resolved description value. `[R-TAR-HOOK-013; D-10]`
- [ ] 5.3 **RED** — Author `plugins/tarifario/tests/Controller/TarifHistorialPreciosIdiomaTest.php` with the "price-history export uses the configured default" scenario. `[R-TAR-HOOK-013; D-10]`
- [ ] 5.4 **GREEN** — In `plugins/tarifario/controller/tarif_historial_precios.php:366` resolve through the language API with the configured default; in `controller/tarif_roles.php:718` replace `$a->descripcion` with the language API; in `controller/tarif_actualizar_precios.php:228` add the join + `COALESCE`. `[R-TAR-HOOK-013; D-10]`
- [ ] 5.5 **RED** — Author `plugins/tarifario/tests/Controller/TarifControllerLanguageCallsTest.php`: a grep gate asserting neither `descripcion($this->codidioma, 50)` nor `get_descripcion($this->codidioma)` remains in `plugins/tarifario/extras/tarif_controller.php`, plus a load assertion that `tarif_catalogo_view`, `tarif_historial_precios`, `tarif_roles` and `tarif_actualizar_precios` instantiate without a fatal. `[R-TAR-HOOK-013, GDI-10 defect a]`
- [ ] 5.6 **GREEN** — In `plugins/tarifario/extras/tarif_controller.php` change `:240` to `$art->descripcion_idioma($this->codidioma, 50)` and `:243` to `$art->get_descripcion_idioma($this->codidioma)` (or remove the dead method without breaking the four subclasses); change the `:104` resolution to `get_effective_default_code()`. `[R-TAR-HOOK-013, GDI-10 defect a; D-02, D-10]`
- [ ] 5.7 **RED → GREEN** — Author `plugins/tarifario/tests/Integration/TarifIdiomaLegacyAliasTest.php`: `tarif_idioma` and `tarif_descripcion` resolve and extend `catalogo_idioma` / `articulo_descripcion`. `[R-TAR-HOOK-013]`
- [ ] 5.8 **VERIFY** — "Already-correct readers are unchanged" (`test scope: plugin-suite`): `git diff` is empty for `plugins/tarifario/Services/ExcelRowUpdater.php:234-290`, `Services/ArticuloListActionHandler.php`, `controller/tarif_catalogo_view.php`, `controller/tarif_configurador_opcionales.php` and `model/tarif_articulo.php:234-329` (`search_tarifario`). `[R-TAR-HOOK-013; D-10]`
- [ ] 5.9 **VERIFY** — `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` green, then `ddev exec php vendor/bin/phpunit --testsuite Plugins` (root) green. `[R-TAR-HOOK-013]`

---

## Task Dependency Graph

```text
1.1/1.2 ──> 1.3/1.4 ──> 1.5 ────────────> 2.x
   │            │                        3.x
   │            │                        4a.x  (search + cache)
   │            │                        4c.x  (no-context consumers)
   │            └──> 1.6/1.7 ──> 1.9      (purge needs the delete path)
   │                                     
   └──> 1.10–1.17  (description clearing + articulo language API; needs 1.2's
                    get_effective_default_code())
4a.x ──> 4b.x   (per-row Excel invalidation is claimed via D-03)
4a.x + 4b.x + 4c.x ──> 5.x
```

Hard blockers:

- **1.1–1.17 → everything.** Slices 2, 3, 4a, 4b, 4c all consume slice 1. `1b` (1.10–1.17)
  depends on `1a` (1.1–1.9) for `get_effective_default_code()`.
- **1.4 → 1.5** (`set_default()` and the `save()` invariant share `normalize_default()`).
- **1.7 → 1.9** (the purge test posts orphans that only the delete path can otherwise create).
- **4a → 4b** (4b's per-row invalidation claim rests on D-03 being in place).
- **4a + 4b + 4c → 5** (slice 5 mirrors the catalogo_core no-context pattern and its
  tests assume the `catalogo_core` side is migrated).
- **1.2 → 1.12/1.13** and **1.15 → 5.6** (the language API is a prerequisite of its readers).

**Parallel-safe within slice 1**: after 1.1/1.2 land, the pairs
`{1.3, 1.4}` (registry), `{1.6, 1.7}` (delete cleanup) and `{1.10, 1.11}` (clearing) touch
disjoint files and may proceed concurrently; 1.9 (migration) is independent of 1.10–1.16.

**Parallel-safe across slices**: after slice 1 merges, **2, 3, 4a and 4c are mutually
independent** (disjoint files) and may be chained in any order or in parallel. 4b must
follow 4a. 5 must follow 4c. This is the single largest scheduling freedom in the chain:
if review throughput matters more than slice order, land 2 → 3 → 4a → 4b → 4c → 5 is
recommended only because 5 depends on 4c.

---

## Coverage Matrix

Every requirement and scenario from the four deltas, mapped to the task that satisfies
it. **Zero requirements are unmapped.**

| Requirement | Scenario | Task(s) | Spec-named test |
|---|---|---|---|
| **GDI-01** Language registry lifecycle + management surface | Full lifecycle round trip | 1.17, 2.1–2.7 | `tests/CatalogoIdiomaManagementTest.php` |
| GDI-01 | Invalid payload is rejected | 1.17, 2.1 | `tests/CatalogoIdiomaManagementTest.php` |
| **GDI-02** One active default; total, deterministic resolution | Setting a new default is a flag flip | 1.1, 1.3, 1.4 | `tests/CatalogoIdiomaInvariantsTest.php` |
| GDI-02 | Deactivating the default is rejected | 1.1, 1.5 | `tests/CatalogoIdiomaInvariantsTest.php` |
| GDI-02 | Default resolution is total | 1.1, 1.2, 1.17 | `tests/CatalogoIdiomaInvariantsTest.php` |
| GDI-02 | The invariant holds after every mutation | 1.1, 1.2, 1.5, 1.7 | `tests/CatalogoIdiomaInvariantsTest.php` |
| **GDI-03** Last-language guard + delete cleanup | Deleting the last language is refused | 1.6, 1.7 | `tests/CatalogoIdiomaDeleteCleanupTest.php` |
| GDI-03 | Deleting a language removes its descriptions | 1.6, 1.7, 1.9 | `tests/CatalogoIdiomaDeleteCleanupTest.php` |
| **GDI-04** Admin-only, POST-only, CSRF mutations | Non-administrator persists nothing | 2.3, 2.4 | `tests/CatalogoIdiomaPermissionTest.php` |
| GDI-04 | CSRF failure persists nothing | 2.3, 2.4 | `tests/CatalogoIdiomaPermissionTest.php` |
| GDI-04 | GET cannot mutate and the page stays accessible | 2.2, 2.4 | `tests/CatalogoIdiomaPermissionTest.php` |
| **GDI-05** Read chain, no fallback materialisation | Requested language wins | 1.12, 1.13 | `tests/ArticuloMultiidiomaTest.php` |
| GDI-05 | Fallback uses the configured default, not a hard-coded `'es'` | 1.2, 1.12, 1.13 | `tests/ArticuloMultiidiomaTest.php` |
| GDI-05 | Base column and empty terminal | 1.12, 1.13 | `tests/ArticuloMultiidiomaTest.php` |
| GDI-05 | Reads materialise nothing | 1.12, 1.13 | `tests/ArticuloMultiidiomaTest.php` |
| **GDI-06** `articulos.descripcion` frozen shim | Saving a default-language description does not write the base column | 1.14, 1.15 | `tests/ArticuloDescripcionFrozenBaseTest.php` |
| GDI-06 | No backfill runs | 1.14, 1.15 | `tests/ArticuloDescripcionFrozenBaseTest.php` |
| **GDI-07** Clearing semantics | Both fields empty deletes the row | 1.10, 1.11 | `tests/ArticuloDescripcionClearingTest.php` |
| GDI-07 | Short-description-only row is preserved | 1.10, 1.11 | `tests/ArticuloDescripcionClearingTest.php` |
| GDI-07 | Empty description is accepted by validation | 1.10, 1.11 | `tests/ArticuloDescripcionClearingTest.php` |
| GDI-07 | A cleared language falls back at read time | 1.11, 1.13 | `tests/ArticuloDescripcionClearingTest.php` |
| GDI-07 (editor leg) | Both-empty pair clears through the editor | 3.2, 3.6 | `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` |
| **GDI-08** Search-cache invalidation on every write | Non-default language write invalidates the cache | 4a.1–4a.3 | `tests/ArticuloSearchCacheInvalidationTest.php` |
| GDI-08 | Clearing a description invalidates the cache | 4a.1–4a.3 | `tests/ArticuloSearchCacheInvalidationTest.php` |
| **GDI-09** Language-agnostic search deviation | A non-default-language term is findable | 4a.4–4a.6 | `tests/ArticuloSearchMultiidiomaTest.php` |
| GDI-09 | A cleared translation stops matching | 4a.3, 4a.4, 4a.6 | `tests/ArticuloSearchMultiidiomaTest.php` |
| GDI-09 | One isolated predicate | 4a.4, 4a.5, 4a.7 | `tests/ArticuloSearchMultiidiomaTest.php` (grep gate) |
| **GDI-10** No-context consumers resolve the default | API returns the configured default language | 4c.1, 4c.2 | `tests/ConsumidoresIdiomaDefaultTest.php` |
| GDI-10 | Opcional search uses the configured default | 4c.1, 4c.2 | `tests/ConsumidoresIdiomaDefaultTest.php` |
| GDI-10 | Dead language calls are fixed | 5.5, 5.6 | `plugins/tarifario/tests/Controller/TarifControllerLanguageCallsTest.php` |
| GDI-10 (Excel leg) | Base `Descripción` cell resolves the configured default | 4b.1, 4b.2 | `tests/Services/ArticuloExcelIdiomasTest.php` |
| **GDI-11** Article editor language selector | One pair per selected language | 3.1, 3.3, 3.4 | `tests/VentasArticuloControllerTest.php` |
| GDI-11 | The posted default-language value is never discarded | 3.2, 3.6 | `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` |
| GDI-11 | No cross-language write | 3.2, 3.5, 3.6 | `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` |
| **GDI-12** Locked anchors, markers, re-expressed ART | Anchor and markers survive | 3.4, 3.7 | `tests/Integration/CatalogoCoreHookMarkersTest.php` |
| **ART-01** MODIFIED — detail absorbs edit behavior | Reference change and familia persist | 3.1, 3.2 (unchanged paths re-asserted) | `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` |
| ART-01 | Per-tarifa sync and etiquetas persist | 3.2 (unchanged paths re-asserted) | `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` |
| ART-01 | Images entry point operates on the loaded article | 3.2 (unchanged paths re-asserted) | `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` |
| ART-01 | Selector-driven multi-language save persists the posted value | 3.2, 3.6 | `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` |
| **ART-05** MODIFIED — tabs + frozen markers | Tabs and markers survive the migration | 3.4, 3.7 | `tests/Integration/CatalogoCoreHookMarkersTest.php` |
| ART-05 | The selector work leaves the frozen markers untouched | 3.4, 3.7 | `tests/Integration/CatalogoCoreHookMarkersTest.php` |
| **Export Excel** | Base headers stay byte-identical | 4b.1, 4b.2, 4b.9 | `tests/Services/ArticuloExcelIdiomasTest.php` |
| Export Excel | Locale columns append in deterministic order | 4b.1, 4b.2 | `tests/Services/ArticuloExcelIdiomasTest.php` |
| Export Excel | A non-default language's text is exported under its suffix | 4b.1, 4b.2 | `tests/Services/ArticuloExcelIdiomasTest.php` |
| **Import wizard 3 pasos** | Target language defaults to the configured default | 4b.3, 4b.4, 4b.6, 4b.7 | `tests/Services/ArticuloExcelIdiomasTest.php` |
| Import wizard 3 pasos | Unknown or removed locale column is ignored | 4b.3, 4b.4, 4b.6 | `tests/Services/ArticuloExcelIdiomasTest.php` |
| Import wizard 3 pasos | Deactivated language column is ignored | 4b.3, 4b.4, 4b.6 | `tests/Services/ArticuloExcelIdiomasTest.php` |
| Import wizard 3 pasos | Legacy workbook without locale columns imports unchanged | 4b.3, 4b.4, 4b.9 | `tests/Services/ArticuloExcelIdiomasTest.php` |
| **Persistencia** | Mapped description persists for the target language | 4b.5, 4b.6, 4b.8 | `tests/Services/ArticuloExcelIdiomasTest.php` |
| Persistencia | Clearing through import deletes the row | 4b.5, 4b.6, 4b.8 | `tests/Services/ArticuloExcelIdiomasTest.php` |
| Persistencia | The base column is never written by the language path | 4b.6, 4b.8, 4c.6 | `tests/Services/ArticuloExcelIdiomasTest.php` |
| **R-TAR-HOOK-013** Tarifario no-context readers | Group-article reads use the configured default | 5.1, 5.2 | `plugins/tarifario/tests/Model/TarifGrupoArticuloIdiomaTest.php` |
| R-TAR-HOOK-013 | Price-history export uses the configured default | 5.3, 5.4 | `plugins/tarifario/tests/Controller/TarifHistorialPreciosIdiomaTest.php` |
| R-TAR-HOOK-013 | Dead language calls are corrected and subclasses still load | 5.5, 5.6 | `plugins/tarifario/tests/Controller/TarifControllerLanguageCallsTest.php` |
| R-TAR-HOOK-013 | Already-correct readers are unchanged (`test scope: plugin-suite`) | 5.8 | `plugins/tarifario/phpunit.xml` suite (no new file) |
| R-TAR-HOOK-013 | Legacy aliases stay loadable | 5.7 | `plugins/tarifario/tests/Integration/TarifIdiomaLegacyAliasTest.php` |

**Unmapped requirements: none.** Two requirement halves are deliberately split across
slices and are flagged so `verify` does not read them as gaps: **GDI-01** (model half =
slice 1, management surface = slice 2) and **GDI-10** (Excel leg = 4b, API/SQL/Opcional
leg = 4c, tarifario leg = 5). `GDI-07` intentionally appears in three slices (model,
editor, import) because each write path must clear independently.

### Open-question resolutions (design delegates these to `tasks`; `verify` follows them)

1. **Test seam for `invalidate_search_cache()`** → `tests/ArticuloSearchCacheInvalidationTest.php`
   resets `articulo::$search_tags` / `$cleaned_cache` by Reflection in `setUp()` and
   exercises the real `fs_cache` path, **plus** a grep assertion that
   `articulo_descripcion::save()` and `delete()` call the invalidator (task 4a.8).
2. **`ConsumidoresIdiomaDefaultTest.php` naming** → **keep the spec-named path**
   (`plugins/catalogo_core/tests/ConsumidoresIdiomaDefaultTest.php`) for scenario
   traceability; do not rename to `NoContextConsumersLanguageTest.php`.
3. **Excel test grouping** → **keep one new file**,
   `plugins/catalogo_core/tests/Services/ArticuloExcelIdiomasTest.php` (14 scenarios),
   and modify the three existing Excel service tests only where the additive shape
   changes expectations (tasks 4b.1, 4b.3, 4b.8, 4b.9).
4. **Import tie-break messaging** → **omitted** (no scenario requires a UI warning when
   several locale columns target one language). `apply` must not add one.

---

## Constraints Carried Into `apply`

1. **D11 deviation note is mandatory and verbatim.** Task 4a.5 must place the
   language-agnostic deviation comment (PrestaShop `id_lang`, Akeneo working locale, Odoo
   `COALESCE(lang, en_US)`, Magento store view; justified by ~25 legacy readers and the
   frozen base column; "Do NOT fix this into a locale-scoped search") inline on
   `ArticuloSearchQueryBuilder::languageDescriptionPredicate()`. It is discoverable from
   the code so it cannot be silently "fixed".
2. **Never materialise fallbacks (R2).** No leg of the read chain writes; no read creates
   an empty row (no Sylius write-on-read); `get_descripcion_corta_idioma()` is
   **same-language only** and returns `''` rather than another language's short text; the
   editor never copies `$art->descripcion` into a language slot.
3. **`articulos.descripcion` is a frozen compatibility shim.** It is never backfilled,
   never mirrored, and never dropped. **Removing its editor is an accepted consequence of
   D1, not a bug** — do not re-add a base description field. Two explicit non-violations:
   `plugins/tarifario/Services/ExcelRowUpdater.php:256-259` writes the base only when
   `$codidioma === 'es'` **and** the base is empty, under an explicit tarifario rule — it
   is compliant, not a mirror; and `Controller/VentasArticulos.php::nuevoArticulo()` keeps
   seeding the base column only (D-13, task 4c.6) because no language row exists at create
   time.
4. **`#multiidioma` anchor + four frozen hook markers.** Preserve `id="multiidioma"`
   (`View/ventas_articulo.html.twig:106`), the
   `partials/articulos/tab_multiidioma.html.twig` include (`:342`), and the four frozen
   names exact and in position: `ventas_articulo_tabs_after`, `ventas_articulo_tab_pane_after`,
   `ventas_opcional_tabs_after`, `ventas_opcional_tab_pane_after`. Slice 3 touches only the
   two **article** markers and `View/ventas_opcional.html.twig` — never add, rename, move
   or remove a marker.
5. **Es/en translation keys.** Add the 18 `language-*` keys from design D-06 to
   `translations/messages.es_ES.yaml` **and** `messages.en_EN.yaml`; reuse the existing
   `languages`, `default-language`, `article-multi-language`, `short-description`,
   `no-languages-configured` keys instead of redefining them.
6. **`#[AdminOnly]` stays off `ventas_articulos`.** The guard is per-action
   (`validateFormToken()` + `!empty($this->user->admin)` + POST-only). The page must remain
   accessible to authorized non-administrators.
7. **No schema change.** `model/table/articulo_descripciones.xml` and
   `model/table/catalogo_idiomas.xml` are unchanged (no `codidioma` FK, D-01). One
   idempotent orphan purge is the only migration.
8. **Runner discipline.** Every slice runs the plugin suite at
   `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`; slice 5 also
   runs `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` and the root
   `--testsuite Plugins`. `ddev exec composer phpstan` before each slice's PR.

---

## No-Drift Checklist (`apply` and `verify` tick this)

- [ ] **No entry in the repository-root `openspec/`.** `git status --short openspec/` is
      empty across the whole change (project anti-pattern; the SDD root is
      `plugins/catalogo_core/openspec/`).
- [ ] **No `#[AdminOnly]` on `Controller/VentasArticulos.php`** (the page stays accessible;
      the guard is per-action).
- [ ] **No backfill and no mirror of `articulos.descripcion`**: no commit adds a write to
      that column from a language path; `git diff` shows no `INSERT`/`UPDATE` of
      `articulos.descripcion` in `set_descripcion_idioma()`, `saveMultiidiomaDescriptions()`,
      the Excel locale columns, or `process_excel_wizard_dispatch.php`.
- [ ] **No new Composer dependency** → `plugins/catalogo_core/composer.json`,
      `composer.lock` and `plugins/catalogo_core/vendor/` are unchanged, so **no `vendor/`
      commit is needed**. (Symfony `fs_cache`, `fs_db2` and Twig are used, not added.)
- [ ] **Delta specs are merged only at archive.** `plugins/catalogo_core/openspec/specs/**`
      and `plugins/tarifario/openspec/specs/**` are untouched during propose/spec/tasks/apply;
      no canonical spec file appears in any slice's diff.
- [ ] **Schema files unchanged**: `git diff` is empty for
      `plugins/catalogo_core/model/table/articulo_descripciones.xml` and
      `plugins/catalogo_core/model/table/catalogo_idiomas.xml`.
- [ ] **Frozen markers unchanged**: `git diff` on the four `render_hook(...)` lines
      (two in `View/ventas_articulo.html.twig`, two in `View/ventas_opcional.html.twig`) is
      empty, and `CatalogoCoreHookMarkersTest` is green **without being edited**.
- [ ] **`EXPORT_HEADERS` and `FIELD_CATALOG` constants are not edited** (base headers and
      the base field catalog stay byte-identical; locale columns are additive).
