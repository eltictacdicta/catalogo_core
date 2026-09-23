```yaml
schema: gentle-ai.archive-result/v1
change: articulo-detalle-tarifa-unificada
sdd_root: plugins/catalogo_core/openspec/   # owner (ownership: plugin-local)
secondary_root: none                        # no delta targeted plugins/tarifario/openspec/
archived_to: plugins/catalogo_core/openspec/changes/archive/2026-09-23-articulo-detalle-tarifa-unificada/
archive_date: "2026-09-23"
verdict_at_verify: fail
blockers_at_verify: 1
critical_findings: 0
requirements_at_verify: 9/9
scenarios_at_verify: 26/26
delta_requirements: 9
delta_scenarios: 26
implementation_tasks_checked: 22
implementation_tasks_unchecked: 0
non_implementation_unchecked: 3   # 0.1 (relocated sibling work), 0.5 (standing coordination note), 6.3 (manual smoke, no live harness)
composer_dependency_added: false
delivery: human-owned (committed locally only; not pushed, not PR'd, not tagged, not released)
final_suites:
  catalogo_core: "920 tests / 4013 assertions — 0 failures, 2 warnings, 1 skipped, exit 0"
  tarifario: "266 tests / 1093 assertions — 0 failures, 2 skipped, exit 0"
canonical_specs_synced:
  articulo-detalle-canonico: "f848862b… → 51981d1f…  (8 req / 15 scen → 11 req / 26 scen)"
  articulo-tarifa-tab-management: "783d92d0… → a5bc83e6…  (7 req / 16 scen → 7 req / 19 scen)"
  catalogo-render-hooks: "d7618ce8… → a1658902…  (8 req / 18 scen → 7 req / 18 scen)"
```

# Archive Report — `articulo-detalle-tarifa-unificada`

**Change**: `articulo-detalle-tarifa-unificada` (plugin-local SDD, `ownership: plugin-local`, `strict_tdd: true`)
**SDD owner**: `plugins/catalogo_core/openspec/` — the repository-root `openspec/` is **not** a tracker for this change and holds **no** entry for it (`ls openspec/changes/ | grep -c articulo-detalle-tarifa-unificada` → `0`).
**Cross-plugin touch**: none. All three deltas target `catalogo_core` canonical specs; no delta targeted `plugins/tarifario/openspec/specs/`, so no second-repository spec merge was required.
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-09-23-articulo-detalle-tarifa-unificada/`
**Delivery**: commit / PR / push / tag / release are **human-owned** and were **NOT** performed by this archive phase. `plugins/catalogo_core/fsframework.ini` stays at `1.6.3`.

## Final-State Authority

`apply-progress.md` and `verify-report.md` are **intermediate snapshots**. The archived `verify-report.md` was written while the sibling `caracteristicas-producto` change was still active; its envelope (`verdict: fail`, `blockers: 1`) and its ARCHIVE-BLOCKING warning describe the change **at that time**. The final state is:

| Fact | Snapshot (`verify-report.md`) | Final state at archive |
|---|---|---|
| Task 0 (sibling archive prerequisite) | `blockers: 1` — unmet | **Satisfied** — sibling archived 2026-09-23 (see below) |
| `composer phpstan` gate | `exit 1`, recorded as pre-existing/out-of-scope | **Explicitly waived by the maintainer** — out-of-scope core test, unchanged |
| Requirements / scenarios | 9/9 · 26/26 | 9/9 · 26/26 (unchanged) |
| CRITICAL findings | 0 | 0 |

Per the Final-State Authority hierarchy, the launch prompt's explicit final-state facts outrank the intermediate snapshot. No unrankable contradiction remains: every snapshot "blocked/open" claim is accounted for below.

## What Shipped

The article detail (`page=ventas_articulo`) moved from five article tabs plus a hook-injected Tarifas tab to **one unified `#datos` pane** scoped by a **validated tarifa selector**, with `#opcionales` as the only secondary tab.

- **Validated selection (ART-09, AD-4)** — `Controller/VentasArticulo.php` `resolveCodtarifa()` validates the supplied `codtarifa` against the active set (unknown/inactive → default → first active → `''`) and exposes `public ?tarif_tarifa $tarifa_seleccionada` via `resolveTarifaSeleccionada()`, mirroring `tarif_opcional_edit::resolver_tarifa_seleccionada()`.
- **Unified pane (ART-05, AD-1/AD-7/AD-8)** — `View/ventas_articulo.html.twig` renders a single `#datos` pane holding Datos, Stock, Idiomas (multi-idioma), Imágenes and per-tarifa Precios; the selector clones the ratified `tarif_opcional_edit` htmx pattern (full-body swap, push-url, boost); `htmx.boot({'allowScriptTags': false})` moved to the top with one guarded `htmx:after:swap` → `Alpine.initTree` re-init; `tab_multiidioma.html.twig` became a visible in-pane panel.
- **Per-tarifa read (ART-10, AD-3)** — the pane renders `precio`/`activo` from `tarif_articulo_precio` for `(referencia, codtarifa)` and visibility through `CaracteristicaResolver::resolve_bool()` without persisting; `controller/tarif_tab_precios.php` gained an **additive** `$rows_scope` narrowing (legacy scope-less output byte-unchanged), and `page=tarif_tab_precios` stays the **only** writer (ART-02 intact; `grep -c guardar_precio_tab Controller/VentasArticulo.php` → `0`).
- **Conditional base price (ART-01, AD-5, C1)** — `editarArticulo()` writes `articulos.pvp` from the form ONLY when `$this->codtarifa === ''`.
- **Hook retirement (D4, ATT-03/ATT-04, `catalogo-render-hooks`)** — `Init.php` no longer registers the article hook pair; both `View/Hooks/ventas_articulo_*.html.twig` templates were deleted; the four frozen markers stay inert at their frozen names/positions. The host view keeps the per-tarifa save wiring via `{% include '@catalogo_core/Hooks/partials/tab_save_script.html.twig' %}` inside the tarifa branch (correction batch).
- **UI adjustment** — `View/Hooks/partials/articulo_precios_rows.html.twig` renders a single product-scoped price block instead of a tariffs table.

## Archive-time Spec Sync

Three delta files were merged into their canonical targets using the mandated native composer `gentle-ai sdd-archive-compose` (requirements matched by name; unrelated requirements preserved). The `## MODIFIED/REMOVED Requirements` headers were stripped by the composition; the canonical file is the post-change spec.

| Delta (archived) | Canonical target | Action | Canonical after |
|---|---|---|---|
| `specs/articulo-detalle-canonico/spec.md` | `plugins/catalogo_core/openspec/specs/articulo-detalle-canonico/spec.md` | MODIFIED (ART-01, ART-05) + ADDED (ART-09, ART-10, ART-11) | 11 req / 26 scen |
| `specs/articulo-tarifa-tab-management/spec.md` | `.../specs/articulo-tarifa-tab-management/spec.md` | MODIFIED (ATT-03, ATT-04) | 7 req / 19 scen |
| `specs/catalogo-render-hooks/spec.md` | `.../specs/catalogo-render-hooks/spec.md` | MODIFIED (Article hook pair registration) + REMOVED (Article hook tab renders derived feature values) | 7 req / 18 scen |

Composition evidence (one invocation per delta; a nonzero exit writes nothing):

```text
$ gentle-ai sdd-archive-compose \
    --canonical plugins/catalogo_core/openspec/specs/<domain>/spec.md \
    --delta plugins/catalogo_core/openspec/changes/articulo-detalle-tarifa-unificada/specs/<domain>/spec.md \
    --output <tmp>.spec.md
exit 0   # all three deltas
```

- **No requirement was lost or added by mistake.** Requirement/scenario counts were compared before and after every composition: each MODIFIED target kept its requirement count and grew only in scenarios (a MODIFIED block is a full requirement block, replaced wholesale); the three ADDED requirements grew `articulo-detalle-canonico` by exactly three requirements; the REMOVED requirement deleted exactly one requirement and its two scenarios from `catalogo-render-hooks` (`grep -c "Article hook tab renders derived feature values"` → `0`). Canonical sha256 before → after: `articulo-detalle-canonico` `f848862b…` → `51981d1f…`; `articulo-tarifa-tab-management` `783d92d0…` → `a5bc83e6…`; `catalogo-render-hooks` `d7618ce8…` → `a1658902…`.
- **Post-composition whitespace normalization.** The composer glued one `### Requirement:` heading to the preceding scenario line in two files (`articulo-detalle-canonico` → `ART-09`; `articulo-tarifa-tab-management` → `ATT-05`). A single blank line was re-inserted per file; the normalization diff was verified to add **only** blank lines (`added-lines: 1 / removed-lines: 0` per file) and change no content. `catalogo-render-hooks` needed no normalization.
- **REMOVED-note normalization (recorded honestly).** The `catalogo-render-hooks` delta's `(Reason: …)` note is wrapped across seven source lines. The native composer admits a `(Reason: …)` note only on a **single line** and refused the delta with `unapplied REMOVED delta … missing required "(Reason: ...)" note`. The delta artifact was therefore **not** edited; the composer was run against a temporary copy of the delta whose `(Reason: …)` paragraph was joined onto one line (whitespace only). Because a REMOVED reason is metadata and is **not** carried into the canonical spec, the resulting canonical file is provably identical to what the original delta would have produced. The archived delta remains byte-identical to the verified artifact.

## Task Completion Gate

Census of the archived `tasks.md`: **22 checked (`- [x]`) / 3 unchecked (`- [ ]`)**. **Zero unchecked implementation tasks** — every Unit 1–5 implementation row (1.1–1.3, 2.1–2.3, 3.1–3.2, 4.1–4.6, 5.1–5.2) is `[x]`. The three unchecked rows are the Task 0 prerequisite/coordination items and the Unit 6 manual smoke; each is annotated in place.

### Task 0 — satisfied (with an honest relocation caveat)

Task 0's blocking prerequisite — the sibling `caracteristicas-producto` archive — is **satisfied**:

- **0.2 / 0.3 / 0.4 — checked.** The sibling archived on 2026-09-23 to `changes/archive/2026-09-23-caracteristicas-producto/` (commit `c49ffc68`); its deltas were merged into the canonical specs (commit `f369faf7`). `ls plugins/catalogo_core/openspec/changes/` lists no `caracteristicas-producto` entry, and the canonical `articulo-detalle-canonico` ART-01 carries the sibling post-merge wording (`grep -n "articulo-scope feature values"` → L24, L49, L64). No core `openspec/` entry exists.
- **0.1 — NOT checked as written (relocated, not closed).** The item asked to confirm the sibling's open items (`WU-7 7.5` soak, `7.6` gated drop) were **closed**. They were **relocated** instead, by an explicit maintainer decision: the sibling delivered CAR-15 clause 1 automatically (`Init::upgrade()` → `CaracteristicaColumnDropMigration::migrateIfNeeded()`, commit `c4fa9ecd`) and **staged clause 2** — the soak plus the gated drop — into the new **active** change `plugins/catalogo_core/openspec/changes/caracteristicas-post-soak-drop/`. **This archive makes no claim that `7.5`/`7.6` were completed.** `caracteristicas-post-soak-drop` was **not** archived here and remains active.
- **0.5 — standing coordination note, not a blocker.** See the coordination note below.

### Unit 6 — regression gate (verified at archive)

- **6.1 — checked.** `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → `920 tests / 4013 assertions`, 2 warnings, 1 skipped, `exit 0` (re-run after the archive move). The literal `794/3450` baseline in the goal line is the apply-time figure; the suite grew because the sibling change's tests landed first. `≥ 794/3450` holds.
- **6.2 — checked (with the waived out-of-scope gate).** See "Waived / Out-of-scope Gates".
- **6.3 — unchecked: MANUAL / NOT EXECUTED.** No live HTTP harness exists in the repo (the plugin suite is DB-free seam/render tests). The command-level checklist is recorded verbatim in `verify-report.md` §"Manual Smoke Checklist". This is a verification-only residual, not an implementation task.
- **6.4 — checked.** `VentasArticuloControllerTest.php` and `CatalogoCoreHookMarkersTest.php` are byte-identical to the plugin-repo `HEAD` (`c3ab3b12`), per `verify-report.md` §"Locked-Contract Audit".

## Waived / Out-of-scope Gates (explicit)

| Gate | State | Why it does not block this change |
|---|---|---|
| Root type gate `ddev exec composer phpstan` → `exit 1` | **WAIVED by the maintainer** | The single error is `tests/Core/PluginEnableAjaxSafetyTest.php:308` (`AjaxGuardTestPluginManager::applyPluginSchemaUpdates()` return-shape drift), a **core** test added by core release commit `14a4c7b7`. `phpstan.neon` declares `paths: [src, tests]`, so it never analysed `plugins/`; neither plugin change touches any root `tests/` file, and the root tree is clean. The maintainer chose **not to touch core**. This is a pre-existing, out-of-scope gate failure — **never** a regression of `articulo-detalle-tarifa-unificada`. |
| Root `Plugins` suite — `OidcProvider` failures | Out-of-scope residual | Pre-existing and unrelated; no failure involves `catalogo_core` or `tarifario`. |
| `plugins/system_updater` DB-state failures (seen in an intermediate root run) | Out-of-scope residual | Environmental (live DB rows); reproducible without any `catalogo_core` code loaded. |
| Destructive DDL | **Never executed** | This change adds no migration and mutates no schema; `catalogo_core` stays at version `1.6.3`. |

## Coordination Note — `remove-fs-demo`

`plugins/catalogo_core/openspec/changes/remove-fs-demo/` (still active) shares `Controller/VentasArticulo.php` and the absorption test. This is a **standing coordination note, not a blocker**: the rebase was kept deliberate and the deltas narrow, and the two changes do not assert conflicting contracts. Any future rebase of `remove-fs-demo` should stay narrow against the files this change touched.

## Final Suite Counts (re-run after the archive move)

| Suite | Command | Result |
|---|---|---|
| `plugins/catalogo_core` | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **920 tests / 4013 assertions — 0 failures**, 2 warnings, 1 skipped, `exit 0` |
| `plugins/tarifario` | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **266 tests / 1093 assertions — 0 failures**, 2 skipped, `exit 0` |

**No archive-induced test regression.** The sibling archive broke `tests/CaracteristicaBoundariesTest.php` by pinning the *active* change path; that was fixed in `c3ab3b12` (the boundary gate is now lifecycle-safe and asserts the plugin-local SDD root owns the change in either its active or archived location). No test in either suite pins this change's active path (`grep -rn "articulo-detalle-tarifa-unificada" plugins/catalogo_core/tests plugins/tarifario/tests` → no matches), and both suites are green after the move.

## Mechanical Move Evidence

The change directory was moved with `git mv` in the `plugins/catalogo_core` repository (a separate git repository), then compared against a pre-move recursive snapshot with `diff -r`:

```text
$ git mv openspec/changes/articulo-detalle-tarifa-unificada \
         openspec/changes/archive/2026-09-23-articulo-detalle-tarifa-unificada
git mv: OK

$ diff -r "$snapshot_root/source" "$destination"
[empty diff — byte-identical]
```

The empty `diff -r` is the passing evidence of byte-identity; this `archive-report.md` is additive (it did not exist in the source snapshot).

## Residuals (carried forward, not blocking)

1. **`catalogo-render-hooks` Purpose sentence is stale.** The canonical Purpose still reads "After the article absorption program (WU-1) the article hook pair is also registered by `catalogo_core`". After this change the article pair is **not** registered. The delta declares no Purpose rewrite, and the composer only merges requirement blocks, so the Purpose was left untouched here — flagged for a follow-up Purpose correction in the canonical spec.
2. **ART-01 `Test:` pointer drift.** The delta annotates the scenario "With no active tarifas the base price is written" with `VentasArticuloTarifaSelectionTest.php`, but the covering test is `VentasArticuloPerTarifaPaneTest.php::test_with_no_active_tarifas_the_base_price_is_written`. Behaviour and assertions are green; only the annotation is wrong. (Carried from `verify-report.md` SUGGESTION 1.)
3. **ART-11 literal wording not asserted verbatim.** The scenario "Empty rows surface degrades gracefully" says the surface "reports no active tarifas"; the covering test asserts base-price degradation and page completeness but not that literal string. Behaviourally compliant. (Carried from `verify-report.md` SUGGESTION 2.)
4. **Manual smoke (6.3) never executed.** No live HTTP harness exists; the checklist is preserved verbatim in the archived `verify-report.md`.
5. **`caracteristicas-post-soak-drop` remains active.** The relocated CAR-15 clause 2 (soak + gated drop) is owned there and must not be archived with this change.

## Core `openspec/` Cleanliness

`ls openspec/changes/ | grep -c articulo-detalle-tarifa-unificada` → `0`. No entry for this change exists (or ever existed) in the repository-root `openspec/`; the core remains free of plugin remains.

## SDD Cycle Complete

The change was fully planned, implemented, verified, and archived. Final state: **9/9 requirements · 26/26 scenarios · 0 CRITICAL findings · 0 unchecked implementation tasks · both plugin suites green**. The archive blocker (Task 0) is satisfied; the `composer phpstan` gate is explicitly waived as a pre-existing out-of-scope core failure.
