# Proposal: Absorb tarifario's article package into catalogo_core

## Intent

`catalogo_core` must become the single owner of the article experience. Today
`tarifario` holds every per-tarifa article capability (prices/state, catalogs,
families/tags/groups, Excel import/export, images, revision notes, multiidioma,
the injected "Tarifas" tab) while `catalogo_core` owns the canonical article
pages but has none of it. That inverts the dependency (a consumer owns catalog
domain), blocks standalone `catalogo_core` installs, and leaves two competing
article UIs. This change absorbs everything `tarifario` contributes for
artículos into `catalogo_core`, keeps one canonical experience
(`ventas_articulos` list + `ventas_articulo` detail, htmx 4 + Alpine), and
retires the duplicated `tarif_*` article pages/services once absorbed —
preserving all functionality.

## Scope

### Program Scope (WU-1 … WU-8)

The whole absorption is one program, delivered as the exploration's sequenced
work units. **This change's current delivery unit is WU-1.** WU-2…WU-8 are
recorded here so the program is not lost; each is its own sized delivery with its
own rollback and suite gate.

| WU | Slice | Depends on |
|---|---|---|
| **WU-1 (this delivery)** | catalogo_core owns the article Tarifas tab + `tarif_articulo_precio`; htmx 4 + Alpine | — |
| WU-2 | Canonical `ventas_articulo` detail absorbs `tarif_articulo_edit`; retire it + `tarif_articulo_precios` | WU-1 |
| WU-3 | Canonical `ventas_articulos` list absorbs `tarif_articulos` filters/columns/quick-create | WU-1 |
| WU-4 | Move extension models: `tarif_articulo`, `tarif_articulos_ext`, `tarif_tarifa_articulo`, `…_etiqueta`; delete `tarif_descripcion` | WU-2/3 |
| WU-5 | Catalog manager ownership (`tarif_catalogo*`, views, partials, JS) → catalogo_core page | WU-4 |
| WU-6 | Images, revision notes, article price history | WU-5 |
| WU-7 | Rich Excel wizard + JSON import unification; repoint SSE | WU-5/6 |
| WU-8 | Retirement audit, RBAC boundary confirmation, verify pass | all |

### In Scope (WU-1)

- Move `model/tarif_articulo_precio.php` + `model/table/tarif_articulo_precios.xml` → catalogo_core.
- Add `controller/tarif_articulo_tab.php` (mirror of `tarif_opcional_tab`); keep the `page=tarif_tab_precios` slug for a minimal diff.
- Move the 4 article hook templates/partials to `catalogo_core/View/Hooks/`.
- Register `ventas_articulo_tabs_after` / `ventas_articulo_tab_pane_after` from `catalogo_core/Init.php` (extend the hook map + `@catalogo_core`); remove the article hooks from `tarifario/Init.php` (tarifario registers zero hooks).
- Migrate the tab to htmx 4 + Alpine CSP, mirroring `ventas_opcional_tab_pane_after`.
- Repoint hardcoded `require_once` paths; migrate `TarifTabPreciosTest` to catalogo_core and flip the article half of tarifario `HookRegistrationTest`.

### Out of Scope

- Tarifas CRUD (`tarif_tarifas`), `tarif_actualizar_precios`, `tarif_roles`, the RBAC role tables (`tarif_grupo_rol/usuario/tarifa`, `tarif_tarifa_rol`), `tarif_idioma` alias, and the already-absorbed familias/opcionales packages.
- RBAC: `ArticlePermissionListener` + `tarif_grupo_articulo` stay in tarifario (exploration §3.4 boundary).
- No data migration, no table renames, no schema/API changes.

## Capabilities

### New Capabilities

- `articulo-tarifa-tab-management`: catalogo_core-owned article Tarifas tab — the two `ventas_articulo_*` hooks, the relocated `/` endpoint on `fbase_controller` (CSRF-guarded rows + save, currency), and `tarif_articulo_precio` per-tarifa price/state ownership.

### Modified Capabilities

- `catalogo-render-hooks`: additive note — the `ventas_articulo_*` hook registration/ownership transfers from tarifario to catalogo_core; tarifario registers no article hook. The four frozen marker names/positions are unchanged.

## Approach

WU-1 mirrors the opcionales unification precedent with a small, bounded surface:
`git mv` the model + XML atomically (never leave a class in both plugins),
relocate the tab endpoint to catalogo_core on `fbase_controller` (`requireCsrf()`,
`simbolo_divisa()`, soft `class_exists` permission gate), move the 4 hook
templates/partials and rewrite them to htmx 4 + Alpine (colon-only events, nonce'd
`alpine:init`, `x-cloak`, no `|raw`), and flip hook ownership in the two `Init.php`
files behind static idempotency guards. The endpoint keeps the `page=tarif_tab_precios`
slug: legacy dispatch resolves `page` → `{page}.php`, so slug and class basename
stay together and the frozen host markers in `ventas_articulo.html.twig` never change.
The table name `tarif_articulo_precios` stays byte-stable (already referenced by
catalogo_core at `model/tarif_tarifa.php:207`). Strict TDD; both plugin suites plus
the root Plugins suite green after the slice.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `plugins/tarifario/model/tarif_articulo_precio.php` | Moved | → `catalogo_core/model/` |
| `plugins/tarifario/model/table/tarif_articulo_precios.xml` | Moved | → `catalogo_core/model/table/` |
| `plugins/catalogo_core/controller/tarif_articulo_tab.php` | New | Rows + save endpoint mirroring `tarif_opcional_tab`; slug `tarif_tab_precios` |
| `plugins/tarifario/controller/tarif_tab_precios.php` | Removed | Relocated (endpoint, not a menu page) |
| `plugins/tarifario/View/Hooks/ventas_articulo_tabs_after.html.twig` | Moved + rewritten | → `catalogo_core/View/Hooks/`; htmx 4 |
| `plugins/tarifario/View/Hooks/ventas_articulo_tab_pane_after.html.twig` | Moved + rewritten | → `catalogo_core/View/Hooks/`; Alpine CSP |
| `plugins/tarifario/View/Hooks/partials/{articulo_precios_rows,tab_save_script}.html.twig` | Moved | → `catalogo_core/View/Hooks/partials/` |
| `plugins/catalogo_core/Init.php` | Modified | Register `ventas_articulo_*` hooks (idempotent) |
| `plugins/tarifario/Init.php` | Modified | Remove article hook registration |
| Tarifario article controllers/tests | Modified | Repoint `require_once` to moved model |
| `catalogo_core/tests/` (migrated `TarifTabPreciosTest`) | Moved | Repoint base + paths |
| Program groups (WU-2…8) | Later | `tarif_articulo*`, `tarif_catalogo*`, images/notes/history, Excel/JS, retirements |

## Risks

| # | Risk | L | Mitigation |
|---|---|---|---|
| R1 | ~26k LOC program; chained-PR budget blown | High | Slice by WU; measure authored diff per WU |
| R2 ★ | Autoloader class collision in half-moved state | Med | Move class + XML + requires atomically per WU; suite after each |
| R3 | `tarif_articulo_precios` hardcoded in catalogo_core | Med | Keep table name byte-stable; relocate class only |
| R4 | Rich vs basic Excel wizard collision | High | Resolve unify-vs-coexist in design (WU-7) |
| R5 | SSE entry + JS coupling to retired route | Med | Move entry + JS together (WU-7) |
| R6 ★ | Locked source-string tests break on moves | High | Update paths/assertions in the same commit; keep 4 frozen markers |
| R7 | htmx v2 / inline-eval Alpine creep | Med | Use Htmx/Alpine macros; assert colon events + `Alpine.data()` |
| R8 | Feature loss in canonical merge | High | Feature-parity checklist + contract tests per feature |
| R9 | Orphan `fs_page` rows | Med | Idempotent `Init::upgrade()` retirements |
| R10 | Stale links to retired slugs | Med | Grep audit before archive; repoint |
| R11 | RBAC listener boundary regression | Med | Keep listener in tarifario; keep gate composition test green |
| R12 ★ | tarifario suite (~191 methods) breaks while tests move | High | Move tests with code per WU; run both suites + root Plugins |
| R13 | Nonces/CSP lost on moved scripts | Med | All scripts via `htmx.boot()`/`alpine.boot()` + `csp_nonce_attr()` |

## Rollback Plan

**WU-1:** single revert of the slice commit — the move, the two `Init.php` edits,
the endpoint relocation, and the test migration — restores the tarifario-owned
tab byte-for-byte. Tables/data and `fs_page` rows are untouched (no retirement in
WU-1); clear the Twig cache after revert. **Program:** each WU is an independent
revert boundary in dependency order; later WUs never need to be reverted to
restore an earlier one.

## Dependencies

- `tarifario` requires `catalogo_core` (`fsframework.ini`) — direction preserved (consumer → catalogo_core).
- Runner: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`; baseline **528 tests / 1826 assertions**.
- ddev, PHP 8.2+/8.3, Symfony 7.4, Twig 3, PHPUnit 11.

## Review Workload Forecast (input for sdd-tasks)

- WU-1 estimated authored diff is small (moves + 4 templates + 2 Init edits + test migration); the program is multi-PR.
- Decision needed before apply: Yes
- Chained PRs recommended: Yes
- 400-line budget risk: Medium (per-WU); High for the program

## Success Criteria

- [ ] `catalogo_core` serves the article Tarifas tab and owns `tarif_articulo_precio`; `page=tarif_tab_precios` still resolves with the frozen host markers unchanged.
- [ ] `tarifario` registers zero article hooks; `catalogo_core` registers both `ventas_articulo_*` hooks idempotently.
- [ ] Tab UI uses htmx 4 + Alpine CSP (colon events only, nonce'd `alpine:init`, no `|raw`, no bare `simbolo_divisa` in hooks).
- [ ] No class exists in both plugins; no catalogo_core file references a `plugins/tarifario/` path for the moved model.
- [ ] catalogo_core suite green at/above 528/1826; tarifario suite + root Plugins suite green; PHPStan clean.
- [ ] No entry for this change exists in the core `openspec/`.
