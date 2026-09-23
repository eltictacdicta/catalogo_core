# Tasks: articulo-detalle-tarifa-unificada

Plugin-local change (`ownership: plugin-local`, `strict_tdd: true`). Every artifact
lives under `plugins/catalogo_core/openspec/`; the canonical specs under
`plugins/catalogo_core/openspec/specs/` are NOT edited and the core `openspec/` gets
NO entry. Decisions A1 + B2 + C1 + D4 (design AD-1..AD-10).

## Review Workload Forecast (guard lines — full section at the end)

```text
Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High
```

`review_budget_lines: 800` — the estimate below does NOT fit it. See the full
`## Review Workload Forecast` section at the end.

## Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|------|------|-----------|----------------------|-----------------|-------------------|
| 1 | Validated tarifa selection + `$tarifa_seleccionada` | PR 1 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloTarifaSelectionTest` | N/A — DB-free anonymous-subclass unit tests (no live HTTP harness in the plugin suite) | `Controller/VentasArticulo.php` resolver block + delete Unit-1 test file (ADR-4) |
| 2 | Scoped per-tarifa rows read (additive `codtarifa` scope) | PR 2 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'TarifTabPreciosTest\|VentasArticuloPerTarifaPaneTest'` | N/A — same DB-free seam tests | `controller/tarif_tab_precios.php` `$rows_scope` block + scoped test cases (PR 2 only) |
| 3 | Conditional `articulos.pvp` write (C1) | PR 3 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'VentasArticuloPerTarifaPaneTest\|VentasArticuloTarifaSelectionTest'` | N/A — same DB-free seam tests | `editarArticulo()` pvp condition + its two test cases |
| 4 | Unified `#datos` pane + selector + boot re-init | PR 4 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'VentasArticuloArticleEditAbsorptionTest\|VentasArticuloControllerTest\|VentasArticuloTarifaSelectionTest\|VentasArticuloPerTarifaPaneTest'` | Manual smoke: `index.php?page=ventas_articulo&ref=<ref>` with active tarifas → selector swaps body, pane shows one tariff | `View/ventas_articulo.html.twig` + the two partials + migrated assertion (revert restores the five-tab layout) |
| 5 | Hook retirement (D4) with markers inert | PR 5 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CatalogoArticuloHookOwnershipTest\|CatalogoCoreHookMarkersTest'` | Manual smoke: article page renders with two empty markers and no injected Tarifas tab | `Init.php` const+loop, restore 2 deleted hook templates, revert ownership test |
| 6 | Regression gate (no diff unless a fix is needed) | chain gate | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` + `ddev exec composer phpstan` | Manual smoke checklist in `verify` phase | N/A — verification only |

## Task 0 — Prerequisite (SATISFIED at archive): archive sibling `caracteristicas-producto` first

Satisfies AD-10. This change's ART-01/ART-05 and `catalogo-render-hooks`/ATT
deltas are authored against the sibling's **post-merge** canonical text, so the
sibling MUST archive first. This was the hard gate before Unit 1.

> **Archive-time reconciliation (2026-09-23).** The blocking prerequisite is
> satisfied: the sibling archived on 2026-09-23 to
> `changes/archive/2026-09-23-caracteristicas-producto/` (commit `c49ffc68`) and
> its deltas were merged into the canonical specs (commit `f369faf7`). Items
> `0.2`, `0.3` and `0.4` are checked with that evidence. Item `0.1` is **not**
> checked as written: the sibling's open items (`7.5` soak, `7.6` gated drop)
> were **relocated**, not closed, by an explicit maintainer decision — the
> sibling delivered CAR-15 clause 1 automatically (`Init::upgrade()` →
> `CaracteristicaColumnDropMigration::migrateIfNeeded()`, commit `c4fa9ecd`) and
> staged clause 2 into the new active change
> `changes/caracteristicas-post-soak-drop/`. No claim is made that `7.5`/`7.6`
> were completed. Item `0.5` is a standing coordination note, not a blocker. See
> `archive-report.md` for the full record.

- [ ] 0.1 NOT CLOSED as written — the sibling's `WU-7 7.5` (soak) and `7.6` (gated drop) were **relocated**, not completed, by maintainer decision (see the reconciliation note above and `archive-report.md`). The prerequisite itself (sibling archived) holds.
- [x] 0.2 Done — the sibling archived to `plugins/catalogo_core/openspec/changes/archive/2026-09-23-caracteristicas-producto/`; its deltas were merged into the canonical `articulo-detalle-canonico`, `catalogo-render-hooks` and `articulo-tarifa-tab-management` specs.
- [x] 0.3 **Acceptance check passed** — `ls plugins/catalogo_core/openspec/changes/` lists no `caracteristicas-producto` entry, and `grep -n "articulo-scope feature values" plugins/catalogo_core/openspec/specs/articulo-detalle-canonico/spec.md` returns ART-01 (L24, L49, L64).
- [x] 0.4 Confirmed — `ls openspec/changes/ | grep -c articulo-detalle-tarifa-unificada` → `0` at archive time; no core `openspec/` entry was ever created.
- [ ] 0.5 Standing coordination note (not a blocker) — `remove-fs-demo` still shares `Controller/VentasArticulo.php`; keep any future rebase narrow.

## Unit 1 — Validated tarifa selection (AD-4) → PR 1

**Goal**: `resolveCodtarifa()` validates against `all_activas()` (unknown/inactive → default → first active → `''`) and exposes `public ?tarif_tarifa $tarifa_seleccionada` via a new `resolveTarifaSeleccionada()` called from `loadTarifarioState()`.
**Files**: `plugins/catalogo_core/Controller/VentasArticulo.php`; `plugins/catalogo_core/tests/Controller/VentasArticuloTarifaSelectionTest.php` (create).
**Depends on**: Task 0.
**Satisfies**: ART-09 (both scenarios), ART-11 (`codtarifa === ''` / `tarifa_seleccionada === null` signal).
**Verification**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloTarifaSelectionTest`

- [x] 1.1 **RED** — create `tests/Controller/VentasArticuloTarifaSelectionTest.php` (anonymous `VentasArticulo` subclass, empty constructor, stubbed `tarifa_model()`, `callResolveCodtarifa()` / `callResolveTarifaSeleccionada()` seams). Cases that MUST fail first: default preselected; stale `codtarifa` → default; unknown code with no default → first active; zero tarifas → `codtarifa === ''` and `tarifa_seleccionada === null` (property does not exist yet).
- [x] 1.2 **GREEN** — port `tarif_opcional_edit::resolver_tarifa_seleccionada()` semantics into `resolveCodtarifa()`; add `resolveTarifaSeleccionada()` + the `public ?tarif_tarifa $tarifa_seleccionada` property; call it from `loadTarifarioState()`.
- [x] 1.3 Confirm the selection matrix is green and the test file fails only for the reasons stated in 1.1 before 1.2.

## Unit 2 — Scoped per-tarifa read for the price rows (AD-3, R3) → PR 2

**Goal**: additive `codtarifa` read-scope on the unchanged WU-1 endpoint; no writer change.
**Files**: `plugins/catalogo_core/controller/tarif_tab_precios.php`; `plugins/catalogo_core/tests/TarifTabPreciosTest.php` (migrate); `plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php` (create — scoped-read cases).
**Depends on**: Unit 1.
**Satisfies**: ART-10 (per-tarifa price/state read, no persist, WU-1 route), ATT-04 (rows stay served by the unchanged endpoint).
**Verification**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'TarifTabPreciosTest|VentasArticuloPerTarifaPaneTest'`

- [x] 2.1 **RED** — add scoped-read cases to `VentasArticuloPerTarifaPaneTest.php`: `codtarifa=USD1` fragment shows only the `USD1` row (`data-codtarifa`) and not `EUR1`; `precio`/`activo` come from `tarif_articulo_precio::get()`; visibility from `CaracteristicaResolver::resolve_bool()` with zero `assign_bool`/`save`/`delete` calls. They MUST fail (scope ignored today → all rows rendered).
- [x] 2.2 **GREEN** — add `protected string $rows_scope = '';`; set it in `render_rows_action()` from `$_REQUEST['codtarifa']` (optional) and in `guardar_precio_articulo()` from the saved row's `codtarifa`; narrow `render_rows_fragment()` to the matching tariff when non-empty. Legacy scope-less output stays byte-for-byte.
- [x] 2.3 **RED→GREEN migrate** `tests/TarifTabPreciosTest.php`: the rows-fragment and two-tariff save/delete response tests now assert the scoped single-row fragment; keep the CSRF, permission-gate, currency and structure tests green.

## Unit 3 — Conditional `articulos.pvp` write (AD-5, C1) → PR 3

**Goal**: `editarArticulo()` writes `articulos.pvp` from `spvp` ONLY when `$this->codtarifa === ''`; the base-price input stays in the no-tarifa branch.
**Files**: `plugins/catalogo_core/Controller/VentasArticulo.php`; `plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php` (extend).
**Depends on**: Units 1, 2.
**Satisfies**: ART-01 ("With a tarifa selected the base price is not written"), ART-11.
**Verification**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'VentasArticuloPerTarifaPaneTest|VentasArticuloTarifaSelectionTest'`

- [x] 3.1 **RED** — add the pvp cases: with a tarifa selected and a POST carrying `spvp`, `articulos.pvp` is left unchanged (tracked anonymous `articulo` subclass; fail today because the write is unconditional); with zero tarifas, `spvp` IS written.
- [x] 3.2 **GREEN** — make `$art->pvp = (float) $request->request->get('spvp', 0)` conditional on `$this->codtarifa === ''`; leave the rest of the save untouched (ART-02 WU-1 reuse intact, no `guardar_precio_tab`).

## Unit 4 — Unified `#datos` pane, selector and boot re-init (AD-1, AD-7, AD-8) → PR 4

**Goal**: one server-rendered `#datos` pane (Datos, Stock, Idiomas, Imágenes, per-tarifa Precios) preceded by the ratified selector; `#opcionales` stays the only secondary tab; locked literals and the four frozen markers survive.
**Files**: `plugins/catalogo_core/View/ventas_articulo.html.twig`; `plugins/catalogo_core/View/partials/articulos/tab_multiidioma.html.twig`; `plugins/catalogo_core/View/Hooks/partials/tab_save_script.html.twig`; `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` (migrate); `plugins/catalogo_core/tests/Controller/VentasArticuloTarifaSelectionTest.php` (extend — view degradation); `plugins/catalogo_core/tests/Controller/VentasArticuloPerTarifaPaneTest.php` (extend — host `hx-get`).
**Depends on**: Units 1, 2, 3.
**Satisfies**: ART-05 (both scenarios), ART-09 (render/`selected`), ART-10 (embed via scoped `hx-get`), ART-11, ART-04 (unchanged, must stay green).
**Verification**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'VentasArticuloArticleEditAbsorptionTest|VentasArticuloControllerTest|VentasArticuloTarifaSelectionTest|VentasArticuloPerTarifaPaneTest'`

- [x] 4.1 **RED** — add view cases to `VentasArticuloTarifaSelectionTest.php` (zero tarifas → no selector, editable `pvp` input present) and to `VentasArticuloPerTarifaPaneTest.php` (host `hx-get` contains `page=tarif_tab_precios&action=rows&tipo=articulo&ref=` plus `codtarifa`). MUST fail against the current five-tab view.
- [x] 4.2 **RED→GREEN migrate** `VentasArticuloArticleEditAbsorptionTest.php::test_view_preserves_tabs_and_frozen_markers` to the unified-pane contract (`#datos` single pane; `#precios`/`#stock` only as in-pane anchors; `#opcionales` only secondary tab; both frozen markers with the four keys + `caracteristicas` unchanged). Keep `test_price_editing_reuses_the_wu1_tab_endpoint`, `test_article_opcional_contract_is_preserved` and `test_view_uses_htmx4_and_alpine_hygiene` green.
- [x] 4.3 **GREEN** — rewrite `View/ventas_articulo.html.twig`: selector behind `{% if fsc.tarifas|length > 0 %}` cloning `tarif_opcional_edit` (lines 28-62: `hx-get fsc.url()`, change trigger, body target/select/swap, push-url, boost, `selected` from `$tarifa_seleccionada`); one `#datos` pane with the section-nav anchors (`#datos-ficha`, `#precios-tarifa`, `#stock-articulo`, `#multiidioma`, `#imagenes`); per-tarifa box keeping `id="tab_tarifario_precios"`, `data-tipo="articulo"`, `x-data="articuloTabPrecios"`, `[x-cloak]` and the scoped `hx-get` placeholder; base `pvp` panel only in the no-tarifa branch; keep markers, `#multiidioma`, `#opcionales`, both includes and `fsc.articulo.pvp` literals.
- [x] 4.4 **GREEN** — move `htmx.boot({'allowScriptTags': false})` to the top of the view and add the guarded `htmx:after:swap` → `Alpine.initTree` block (AD-8) to the host inline script; remove the duplicate `htmx:after:swap` block from `View/Hooks/partials/tab_save_script.html.twig` (keep `Alpine.data` + `htmx:after:request`).
- [x] 4.5 **GREEN** — change `View/partials/articulos/tab_multiidioma.html.twig` root from `role="tabpanel" class="tab-pane"` to a visible `<div class="panel panel-default" id="multiidioma">`; `tab_opcionales.html.twig` stays unchanged.
- [x] 4.6 **Locked tests stay green UNEDITED**: `plugins/catalogo_core/tests/VentasArticuloControllerTest.php` (ART-08 MUST — no edits) and `plugins/catalogo_core/tests/Integration/CatalogoCoreHookMarkersTest.php` (markers preserved). Any needed change there means the unit is wrong, not the test.

## Unit 5 — Hook retirement with inert markers (AD-6, D4) → PR 5

**Goal**: catalogo_core registers zero article hooks; both templates deleted; the two frozen markers remain inert.
**Files**: `plugins/catalogo_core/Init.php`; delete `plugins/catalogo_core/View/Hooks/ventas_articulo_tabs_after.html.twig` and `plugins/catalogo_core/View/Hooks/ventas_articulo_tab_pane_after.html.twig`; `plugins/catalogo_core/tests/Integration/CatalogoArticuloHookOwnershipTest.php` (flip).
**Depends on**: Unit 4 (the host must render the surface before the injection is removed).
**Satisfies**: `catalogo-render-hooks` MODIFIED "Article hook pair registration owned by catalogo_core"+ REMOVED "Article hook tab renders derived feature values"; ATT-03 (both scenarios), ATT-04 (no injected tab).
**Verification**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter 'CatalogoArticuloHookOwnershipTest|CatalogoCoreHookMarkersTest'`

- [x] 5.1 **RED** — flip `CatalogoArticuloHookOwnershipTest.php`: assert zero article registration across two simulated Twig builds, both templates gone (grep gate), host renders the unified pane, unsaved article renders no price surface; keep the endpoint/rows/hygiene assertions.
- [x] 5.2 **GREEN** — remove the `ARTICULO_HOOK_TEMPLATES` const + docblock and its `registerHooks()` loop from `Init.php` (keep the static guard and the opcional pair); delete both hook templates. Keep `View/Hooks/partials/articulo_precios_rows.html.twig` and `tab_save_script.html.twig`.

## Unit 6 — Regression gate (final)

**Goal**: prove the whole change against the plugin suite and the type gate.
**Files**: none (fixes only, if a gate fails).
**Depends on**: Units 0–5.
**Satisfies**: ART-08 (suite ≥ 794 tests / 3450 assertions, exit 0).
**Verification**: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` and `ddev exec composer phpstan` and `ddev exec php -l` on every touched plugin PHP file.

- [x] 6.1 Done at archive — `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → **`920 tests / 4013 assertions`, 2 warnings, 1 skipped, `exit 0`** (re-run 2026-09-23 after the archive move). The literal `794/3450` baseline in the goal line is the apply-time figure; the suite grew to `920/4013` because the sibling `caracteristicas-producto` tests landed first. `≥ 794/3450` holds. `plugins/tarifario`: `266 tests / 1093 assertions`, 2 skipped, `exit 0`.
- [x] 6.2 Done at archive — `ddev exec composer phpstan` exits `1` on a **pre-existing, out-of-scope** core-test error (`tests/Core/PluginEnableAjaxSafetyTest.php:308`, added by core release `14a4c7b7`). `phpstan.neon` declares `paths: [src, tests]`, so it never analyses `plugins/`; the maintainer chose not to touch core and **explicitly waived** this gate for this change. The plugin's real type gate is the plugin suite plus `php -l` on the 8 touched PHP files (all clean, per `verify-report.md`).
- [ ] 6.3 **MANUAL / NOT EXECUTED** — no live HTTP harness exists in the repo (the plugin suite is DB-free seam/render tests). The command-level checklist is recorded verbatim in `verify-report.md` §"Manual Smoke Checklist". Not a blocker: no automated contract depends on it.
- [x] 6.4 Done at archive — `VentasArticuloControllerTest.php` and `CatalogoCoreHookMarkersTest.php` are byte-identical to the plugin-repo `HEAD` (sha256 match; `git diff HEAD` empty), per `verify-report.md` §"Locked-Contract Audit".

## Coherent rewrites

| File | Change | Kept green |
|---|---|---|
| `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | unified-pane tab assertion (Unit 4.2) | frozen-marker + WU-1-reuse + opcional/hygiene tests |
| `tests/Integration/CatalogoArticuloHookOwnershipTest.php` | zero article registration (Unit 5.1) | endpoint/rows/hygiene assertions |
| `tests/TarifTabPreciosTest.php` | scoped response/rows (Unit 2.3) | CSRF, permission gate, currency, structure |
| `tests/VentasArticuloControllerTest.php` | **UNEDITED** (ART-08 MUST) | all assertions |
| `tests/Integration/CatalogoCoreHookMarkersTest.php` | **UNEDITED** (markers preserved) | all assertions |

## Review Workload Forecast

| Field | Value |
|-------|-------|
| Estimated changed lines | ~1,150 (range 950–1,350; additions + deletions, tests included) |
| 400-line budget risk | High |
| Chained PRs recommended | Yes |
| Suggested split | PR 1 → PR 2 → PR 3 → PR 4 → PR 5 |
| Delivery strategy | auto-chain |
| Chain strategy | pending |

```text
Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High
```

**Justification**: the view rewrite alone (~240), the two new verbose RED-first
test files (~650 combined), the migrated tests (~235) and the endpoint/helpers
(~90) put the change far past 400 and past the session
`review_budget_lines: 800`. The estimate does **NOT** fit inside 800 — it is
roughly 150–550 lines over. Per `auto-chain` the orchestrator needs no
per-slice approval, but `chain_strategy` is explicitly undeclared and MUST be
confirmed before apply; `Decision needed before apply: Yes` reflects that open
strategy choice, not a budget exception.

**Proposed slices** (each independently reviewable, tests travelling with code):

- **PR #1 — Validated selection** (AD-4). Scope: `Controller/VentasArticulo.php` resolver + `VentasArticuloTarifaSelectionTest.php`. Verify: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter VentasArticuloTarifaSelectionTest`. Rollback: revert the resolver block + delete the test file; view/endpoint/Init untouched.
- **PR #2 — Scoped rows read** (AD-3). Scope: `controller/tarif_tab_precios.php` `$rows_scope` + `TarifTabPreciosTest.php` migration + scoped-read cases in `VentasArticuloPerTarifaPaneTest.php`. Verify: `--filter 'TarifTabPreciosTest|VentasArticuloPerTarifaPaneTest'`. Rollback: revert the scope property/setter/narrowing; the scope-less legacy output is unchanged, so consumers are unaffected.
- **PR #3 — Conditional base price** (AD-5). Scope: `editarArticulo()` pvp condition + pvp cases in `VentasArticuloPerTarifaPaneTest.php`. Verify: `--filter 'VentasArticuloPerTarifaPaneTest|VentasArticuloTarifaSelectionTest'`. Rollback: revert the one condition + its cases; base-price path identical with zero tarifas.
- **PR #4 — Unified pane** (AD-1, AD-7, AD-8). Scope: `View/ventas_articulo.html.twig`, `View/partials/articulos/tab_multiidioma.html.twig`, `View/Hooks/partials/tab_save_script.html.twig`, migrated absorption test, view cases. Verify: `--filter 'VentasArticuloArticleEditAbsorptionTest|VentasArticuloControllerTest|VentasArticuloTarifaSelectionTest|VentasArticuloPerTarifaPaneTest'`. Rollback: revert the view + partials + assertion (restores the five-tab layout; the injection still exists until PR #5).
- **PR #5 — Hook retirement** (AD-6). Scope: `Init.php`, delete both `View/Hooks/ventas_articulo_*.html.twig`, flipped `CatalogoArticuloHookOwnershipTest.php`. Verify: `--filter 'CatalogoArticuloHookOwnershipTest|CatalogoCoreHookMarkersTest'`. Rollback: restore the const/loop + both templates + revert the test; markers never changed, so nothing else is affected.

**Chain gate**: Unit 6 runs the full plugin suite + `composer phpstan` before the
chain is considered done.

## skill_resolution

- **`fsframework-plugin-sdd`** — LOADED. Routing confirmed plugin-local (`config.yaml:27`); canonical specs and core `openspec/` untouched; Task 0 owns the sibling archive handoff.
- **`sdd-tasks`** — LOADED. Review Workload Forecast + literal guard lines + work-unit evidence (focused command, runtime harness, rollback boundary) present. NOTE: the skill's 530-word artifact cap is exceeded by the requested per-unit deliverable; completeness was prioritized and the overage is surfaced, not hidden.
- **`fsframework-test-writing`** — LOADED. RED-first files use anonymous `fs_model` subclasses, seam factories, Reflection resets and DDEV execution per the plugin conventions.
- **`work-unit-commits`** — LOADED. Tests land with the behavior they verify; each unit states its own rollback boundary and is a chained-PR candidate.
- **`chained-pr`** — NOT loaded; `chain_strategy` is undeclared, so only the slice proposal is recorded. Load it once the strategy is confirmed.
