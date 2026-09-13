```yaml
schema: gentle-ai.archive-result/v1
change: opcionales-detalle-porcentaje-y-grupos
plugin: catalogo_core
ownership: plugin-local
archived_at: 2026-09-12
archived_to: plugins/catalogo_core/openspec/changes/archive/2026-09-12-opcionales-detalle-porcentaje-y-grupos/
verify_verdict: PASS
blockers: 0
critical_findings: 0
requirements: 12/12
scenarios: 20/20
tasks_checked: 25
tasks_open: 1  # deliberate, non-blocking OPEN smoke annotation
catalogo_core_suite: 528 tests / 1826 assertions OK (floor 511/1756)
tarifario_suite: 191 tests / 705 assertions OK
composer_dependency_added: false
delivery: human-owned (no commit/push performed by this phase)
core_openspec_entry: none
```

# Archive Report: `opcionales-detalle-porcentaje-y-grupos`

- **Change**: `opcionales-detalle-porcentaje-y-grupos` (plugin-local SDD, `plugins/catalogo_core/openspec/`)
- **Secondary plugin**: `plugins/tarifario/` (one link repoint in `View/tarif_articulo_precios.html.twig`)
- **Archive location**: `plugins/catalogo_core/openspec/changes/archive/2026-09-12-opcionales-detalle-porcentaje-y-grupos/`
- **Source-of-truth specs merged**: `plugins/catalogo_core/openspec/specs/opcionales-management/spec.md`, `plugins/catalogo_core/openspec/specs/opcionales-tarifa-selector/spec.md`
- **Delivery ownership**: commit / PR / push are **human-owned and were NOT performed** by this archive phase.

## Verdict

**PASS → ARCHIVED.** The change was independently verified (verdict `PASS`, 0 blockers, 0 critical, 12/12 requirements, 20/20 scenarios), the delta specs were merged into the plugin's canonical specs, and the change folder was moved mechanically to the plugin archive with a byte-identical readback (`diff -r` empty). The single unchecked task is a deliberate, non-blocking OPEN smoke annotation explicitly acknowledged by the orchestrator.

---

## Pre-Archive Checks

| Check | Result |
|---|---|
| All artifacts present (proposal, design, exploration, specs/, tasks, apply-progress, verify-report) | PASS |
| `verify-report.md` verdict / blockers / critical / coverage | PASS — `PASS`, 0 blockers, 0 critical, 12/12 requirements, 20/20 scenarios |
| Tasks checked vs. open | 25 `[x]` / 1 `[ ]` — the open item is the deliberate OPEN authenticated smoke annotation, not an implementation task |
| Composer dependency added | No — no `composer.json` / `composer.lock` change in either plugin, so no `vendor/` step |
| Core `openspec/` entry for this change | None (see Core OpenSpec Cleanliness) |

### Task Completion Gate

`tasks.md` has **25 checked** and **1 unchecked**. The unchecked line is:

```
- [ ] Phase-5 note: authenticated browser/DEV-DB smoke (...) NOT run — no session/fixture. OPEN, non-blocking; annotated in verify-report.md.
```

It is a verification note, not an implementation task, and it is annotated `OPEN` rather than marked complete. The orchestrator explicitly acknowledged this as a "deliberate OPEN smoke annotation". This exceptional reconciliation is recorded here: **archive proceeded with an intentional, non-blocking OPEN smoke item**; all 25 implementation/verify tasks are `[x]`.

---

## Specs Synced to Canonical Plugin Specs

### `plugins/catalogo_core/openspec/specs/opcionales-management/spec.md`

| Requirement | Action | Notes |
|---|---|---|
| OUM-02 — Filters and real-total pagination | MODIFIED | Added `b_id_grupo` filter + "Sin grupo" sentinel; scenario extended |
| OUM-03 — Per-tarifa state and price display | MODIFIED | Mode-aware value (fixed price or effective percentage label) |
| OUM-05 — Opcional creation with validated prices | MODIFIED | Price mode `fijo`/`porcentaje`, validated percentage, optional group |
| OUM-07 — Excel export parity | MODIFIED | Mode-aware export value + percentage number format |
| OUM-11 — Surviving opcional controllers preserved | MODIFIED | `tarif_opcional_edit` canonical; `tarif_opcional_precios` deleted + `fs_page` retired idempotently |
| OUM-12 — Locked contracts stay green | MODIFIED | Exactly two survivor slugs; precios portions repointed/removed; historical table refs untouched |
| OPG-01 — Percentage rendering and write integrity across surfaces | ADDED | Full requirement + 3 scenarios |
| OPG-02 — Grupo column in the unified list | ADDED | Full requirement + 1 scenario |

OUM-01, OUM-04, OUM-06, OUM-08, OUM-09 and OUM-10 were preserved untouched.

### `plugins/catalogo_core/openspec/specs/opcionales-tarifa-selector/spec.md`

| Requirement | Action | Notes |
|---|---|---|
| Purpose | REWRITTEN | No longer names the deleted `tarif_opcional_precios` page as a surviving view; scope now `tarif_opcional_edit` + `ventas_opcionales` |
| OTS-09 — Test-locked contracts intact | MODIFIED | Surviving set = `tarif_configurador_opcionales` + `tarif_opcional_edit`; precios not locked |
| OTS-10 — Unchanged boundaries | MODIFIED + RECONCILED | See "W3 reconciliation" below |
| OTS-08 — List and precios filters migrate with locked names | REMOVED | Its reason/migration notes remain in the archived delta: it locked the deleted page's filter names; surviving behavior is owned by OUM-02 |
| OPG-03 — htmx 4 and Alpine CSP on the canonical detail | ADDED | Full requirement + 2 scenarios |

OTS-01 through OTS-07 were preserved untouched.

### W3 reconciliation (DONE in this archive)

The delta `OTS-10` stated `model/tarif_opcional_precio.php` MUST NOT change, while design AD-3 and task 1.3 additively added `limpiar_porcentaje()` to that adapter. The canonical `OTS-10` was narrowed so it is factually accurate:

- The adapter MUST NOT change its **table identity** or **`codlista` key**, and its stored-value semantics remain intact.
- **Additive, behavior-preserving members are explicitly allowed** (e.g. the percentage force flag introduced by OPG-01).
- The scenario now asserts "none of those files is modified" for the still-frozen surfaces (`model/tarif_tarifa_opcional.php`, `controller/tarif_configurador_opcionales.php`, `tpvmod`, migration/dead-table references) **and** that the adapter keeps its table identity/`codlista` key with only additive members.

---

## Files Added / Changed / Deleted

### `plugins/catalogo_core/`

| File | Action |
|---|---|
| `model/tarif_opcional.php` | Changed — `opcional_precio_model()`, `set_porcentaje_tarifa()`, percentage-clearing `set_precio_tarifa()`, trailing `$id_grupo` in `search()`/`count_filtered()` |
| `model/tarif_opcional_precio.php` | Changed — additive `limpiar_porcentaje()` force flag; `save()` preserves stored `porcentaje` only when not explicitly cleared |
| `model/core/catalogo_opcional.php` | Changed — trailing `$id_grupo` in `search()`; shared `where_id_grupo()` helper (`''`/`'0'`/numeric, `intval()` guarded) |
| `extras/VentasOpcionalesListTrait.php` | Changed — percentage cache/accessors, `show_precio_opcional()` mode branch, `valor_precio_export()`; `new_opcional` mode/percentage/group; `load_grupos_cache()` + `nombre_grupo_opcional()`; `b_id_grupo` wiring |
| `Controller/VentasOpcionales.php` | Changed — `privateCore()` loads `init_opcionales_list()` before create/delete dispatch (create-ordering fix) |
| `controller/tarif_opcional_edit.php` | Changed — percentage read + percentage-aware atomic scoped/bulk saves |
| `controller/tarif_opcional_tab.php` | Changed — `opcional_model()` seam; percentage rows; `guardar_precio_tab` percentage branch |
| `Init.php` | Changed — idempotent `retireTarifOpcionalPreciosPage()` + `upgrade()` wiring |
| `View/ventas_opcionales.html.twig` | Changed — percentage price cell; create-modal mode/percentage/group; `Grupo` column; `b_id_grupo` filter; 2 links repointed |
| `View/tarif_opcional_edit.html.twig` | Changed — percentage input branch + overview; dropped redundant "Precios por tarifa" button; WU-4 posture re-verified |
| `View/Hooks/partials/opcional_precios_rows.html.twig` | Changed — renders `porcentaje` input + `%` addon + `modo_porcentaje` marker |
| `controller/tarif_opcional_precios.php` | **Deleted** — consolidated into `tarif_opcional_edit`, no alias |
| `View/tarif_opcional_precios.html.twig` | **Deleted** — consolidated into `tarif_opcional_edit`, no alias |

### `plugins/tarifario/`

| File | Action |
|---|---|
| `View/tarif_articulo_precios.html.twig` | Changed — opcional edit link repointed to `opcional.url()&codtarifa=..` (no `page=tarif_opcional_precios`) |

### Tests added / updated

| File | Action |
|---|---|
| `tests/CatalogoOpcionalesPercentageTest.php` | **Added** — 11 tests (writer symmetry, force flag, fallback, label, export format, tab percentage save) |
| `tests/TarifOpcionalTabEndpointTest.php` | Updated — DB-free `opcional_model()` seam + percentage-row persistence test |
| `tests/CatalogoOpcionalesUnifiedControllerTest.php` | Updated — group filter assertions, one-map load (no N+1), `where_id_grupo()` sentinel/injection, percentage+group create, deletion guard |
| `tests/CatalogoOpcionalesHtmxContractTest.php` | Updated — `b_id_grupo` filter/column + percentage create-modal contract |
| `tests/TarifOpcionalesControllerContractTest.php` | Updated — `CONTROLLER_SLUGS` 3 → 2 |
| `tests/TarifOpcionalPreciosControllerTest.php` | Updated — dropped moved-controller test; adapter contract kept |
| `tests/TarifOpcionalesControllerMasterStateTest.php` | Updated — dropped `PRECIOS_*` + 3 precios tests; master/macro/CSRF assertions repointed |
| `tests/TarifOpcionalesHtmxContractTest.php` | Updated — dropped `PRECIOS_VIEW` + 2 precios tests; added absorbed-panel + no-`|raw`/bootbox contracts |
| `tests/InitUpgradeTest.php` | Updated — added `upgradeRetiresTarifOpcionalPreciosPageIdempotently` |
| `tests/VentasOpcionalesControllerTest.php` | Updated — added create-ordering source + runtime regression tests |

---

## Create-Ordering Fix

`Controller/VentasOpcionales.php::privateCore()` previously dispatched `new_opcional()` **before** `init_opcionales_list()`, so `$this->tarifas` / `$this->tarifa_seleccionada` were unset at creation time and the create-modal per-tarifa values were silently dropped. The fix hoists `init_opcionales_list()` to the top of `privateCore()`, ahead of the create/delete dispatch. It is locked by:

- `VentasOpcionalesControllerTest::testPrivateCoreLoadsTheListBeforeDispatchingCreate` (source-order assertion).
- `VentasOpcionalesControllerTest::testCreateModalPerTarifaValuesPersistWhenTarifasAreLoaded` (runtime persistence).

---

## Final Test & Static State

| Suite | Command | Result |
|---|---|---|
| catalogo_core | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | **OK — 528 tests / 1826 assertions** (25 warnings, 1 skipped), exit 0; floor was 511/1756 |
| tarifario | `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | **OK — 191 tests / 705 assertions** (3 skipped), exit 0 |
| core static analysis | `ddev exec composer phpstan` | `[OK] No errors` (188 files), exit 0 — does **not** scan the plugin (W2) |

---

## Open Warnings Carried Forward

- **W1 — TDD partially retrospective.** WU-2's RED was reconstructed by temporarily neutering production seams (the plugin tree is gitignored at the repo root, so revert-to-HEAD was unavailable), and WU-4's tests were green on first run ("no production change needed"). Strict TDD is therefore only partially demonstrable for those units. Non-blocking; shipped behavior is covered by meaningful tests.
- **W2 — Root PHPStan does not scan the plugin.** `phpstan.neon` `paths` = `src`, `tests` only. `ddev exec composer phpstan` is green but proves nothing about `plugins/catalogo_core` / `plugins/tarifario`. Recommendation: add a plugin-level PHPStan config or document the plugin suite (528 tests) as the effective static gate.
- **W3 — OTS-10 spec wording conflict — RECONCILED IN THIS ARCHIVE.** The canonical `OTS-10` was narrowed so the additive `limpiar_porcentaje()` force flag is explicitly permitted while table identity / `codlista` key / stored-value semantics stay frozen. See "W3 reconciliation" above.
- **W4 — Working-tree attribution note.** `plugins/catalogo_core` carries concurrent uncommitted SDD work; `Services/CatalogLegacyTableMigration.php` shows as modified. The `tarif_opcional_precios` hunks belong to the concurrent **unarchived** change `opcionales-por-tarifa` (`BOTH_EXIST_COPY` handling + `codtarifa → codlista` backfill), **not** this change. This change's declared file set excludes that file, and all historical `tarif_opcional_precios` references remain. Recorded for transparency, not attributed to this change. The sibling unarchived dirs `absorber-opcionales-tarifa-en-catalogo-core/` and `opcionales-por-tarifa/` were left untouched.
- **OPEN — authenticated browser / DEV-DB smoke.** No session/fixture was available. Percentage round-trip, `Grupo` column + `b_id_grupo` filter, canonical detail selector, create-modal `sid_grupo`, and Excel percentage cell were **not** exercised end-to-end. All are covered by automated tests; marked OPEN, non-blocking.

---

## Mechanical Archive Verification

Archival was a shell-only mechanical move (`git mv` refused the untracked plugin tree with "fuente está vacío", so the plain `mv` fallback ran). A pre-move recursive snapshot was taken and compared against the destination after the move. The `diff -r` readback produced **empty output** (no differences) with exit status **0** — byte-identical. The `archive-report.md` you are reading is additive and was excluded from the comparison.

```
=== diff -r snapshot vs destination (empty = pass) ===
=== diff exit status: 0 ===
```

Archived contents:

- `proposal.md`
- `design.md`
- `exploration.md`
- `specs/opcionales-management/spec.md` (delta)
- `specs/opcionales-tarifa-selector/spec.md` (delta)
- `tasks.md`
- `apply-progress.md`
- `verify-report.md`
- `archive-report.md` (this file)

---

## Core OpenSpec Cleanliness

- `find openspec -iname '*opcionales-detalle*'` → no matches.
- `grep -rl 'opcionales-detalle-porcentaje' openspec/` → no matches (exit 1).
- No `openspec/changes/opcionales-detalle-porcentaje-y-grupos/` exists in the core `openspec/`.

The core `openspec/` has **no entry** for this change. Plugin-local ownership holds; nothing in the core was created or modified.

---

## Closing Summary

This plugin-local SDD is closed. The change restored percentage pricing and opcional groups on the unified `ventas_opcionales` surface, made every percentage write path symmetric, and completed the single-opcional consolidation by retiring `tarif_opcional_precios` into the canonical `tarif_opcional_edit` detail. The delta specs were merged into the plugin's canonical specs (`opcionales-management`, `opcionales-tarifa-selector`), including the OTS-10 reconciliation, and the change folder was moved mechanically into the plugin archive with a byte-identical readback.

**Delivery (commit / PR / push) is human-owned and was NOT performed by this phase.**
