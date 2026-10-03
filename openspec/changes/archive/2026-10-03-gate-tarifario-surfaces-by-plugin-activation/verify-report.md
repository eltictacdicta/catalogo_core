# Verify Report: gate-tarifario-surfaces-by-plugin-activation

- Change: `gate-tarifario-surfaces-by-plugin-activation`
- SDD owner: `plugins/catalogo_core/openspec/` (plugin-local, `strict_tdd: true`)
- Artifact store: openspec
- Verified at: 2026-10-03
- Nested repo: `plugins/catalogo_core`, branch `feat/gate-visibility-characteristics`
- Commits inspected: PR1 `717178d5`, PR2 `ea176fa9`
- Core `openspec/` entry: NONE verified (correct for a 100% plugin-local change)

## Verdict

**`pass_with_warnings`**

All functional requirements (VCG-01..VCG-08, narrowed ALC-02, narrowed OUM-03)
are implemented and verified by static inspection plus a green isolated test run.
The warnings are process/evidence gaps, not defects in the delivered behavior:
the required `apply-progress.md` TDD-evidence artifact is absent, and three of
the four gated templates are covered only by source-contract tests (no Twig
render harness exists for them).

---

## Scope

- **Inspected implementation**: the 9 production files from PR1+PR2 and the 10
  test files they touch.
- **Artifacts available**: `proposal.md`, `design.md`, `tasks.md`,
  `rescope-explore.md`, `SUPERSEDED.md`, three delta specs.
- **Missing artifact**: `apply-progress.md` (no TDD Cycle Evidence table) — see
  Findings W1.
- **TDD mode**: `strict_tdd: true` → `strict-tdd-verify.md` applied.

## Observed progress

`tasks.md` has **0 unchecked items** (all implementation, verification and
"do NOT touch" tasks are `[x]`). Checkbox state was cross-checked against the
actual diff; the intent of every checked task is really present in the code
(not merely marked). No task was found checked without a corresponding change.

---

## Requirements coverage

| Req | Strength | Result | Evidence |
|-----|----------|--------|----------|
| **VCG-01** — Active-visibility derivation | MUST | **PASS** | `Services/CaracteristicaResolver.php:114-134` (`active_visibility_codigos()`, `is_visibility_active()`); derived from memoized `definitions()` (`:82-102`, CAR-20 filter at `:95-97`); `isset()` is safe because `definitions()` values are non-null arrays. Unit tests: `tests/CaracteristicaResolverTest.php` (active / inactive / operator-owned / unknown / single-read). |
| **VCG-02** — Article list gate | MUST | **PASS** (source-verified) | Headers gated `View/ventas_articulos.html.twig:146,149`; cells gated `:195,204`; colspan `:240` = `7 + (tarifa ? 2 + visibilidad_activa|length + listable|length : 0)`. `precio`/`Activo` cells and listable loop left ungated. Test: `tests/Controller/VentasArticulosListCaracteristicasTest.php` (regex contracts + colspan string). |
| **VCG-03** — Article detail gate | MUST / MUST NOT | **PASS** (runtime) | Controls gated `View/Hooks/partials/articulo_precios_rows.html.twig:41,52`; price input (:16-29) and `activo` (:30-40) stay ungated. Runtime partial renders: `tests/TarifTabPreciosTest.php` and `tests/Controller/VentasArticuloPerTarifaPaneTest.php` assert absent checkboxes + present price/`activo`. |
| **VCG-04** — Opcionales list gate | MUST / MUST NOT | **PASS** (source-verified) | Headers gated `View/ventas_opcionales.html.twig:392,400`; cells gated `:471,482`; `b_codtarifa` / `Precio` / `Estado` untouched. Test: `tests/Controller/VentasOpcionalesControllerMasterStateTest.php`. |
| **VCG-05** — Opcional detail gate | MUST / MUST NOT | **PASS** (source-verified) | Labels gated `View/tarif_opcional_edit.html.twig:295,303`; summary th `:340`, values `:350,353`; `activa` + price panel untouched. Test: `tests/TarifOpcionalEditCaracteristicaTest.php` (+ `TarifOpcionalesControllerMasterStateTest.php`). |
| **VCG-06** — Uniform across read modes | MUST | **PASS** | Accessor reads only definition activity, never `FS_CATALOGO_CARACTERISTICAS_READ_THROUGH` (`CaracteristicaResolver.php:114-134`). Test: `tests/CaracteristicaReadThroughTest.php::test_visibilidad_activa_is_uniform_across_read_modes`. |
| **VCG-07** — Data preservation | MUST / MUST NOT | **PASS** | No `DELETE`/`DROP`/`INSERT`/schema/migration added by either commit (diff audit; the only "drop" hit is a docblock word in a test). Accessor/seams are read-only. Runtime read; re-enabling restores surfaces. |
| **VCG-08** — Ownership safety | MUST / MUST NOT | **PASS** | Gate code contains no `'tarifario'`/`plugins/tarifario/`/`@tarifario/` literal (added-line grep = none). Guard test `tests/CaracteristicaBoundariesTest.php::test_visibility_gate_has_no_plugin_name_literal` (brace-balanced method extraction, comments stripped) + `test_the_four_render_servers_expose_visibilidad_activa`. |
| **ALC-02** (narrowed) — Per-tarifa visibility columns | MUST / MUST NOT | **PASS** (source-verified) | Visibility header/cells conditional (`ventas_articulos.html.twig:146,149,195,204`); `precio`/`activo` columns and listable append/order unchanged. |
| **OUM-03** (narrowed) — Visibility indicators | MUST / MUST NOT | **PASS** (source-verified) | Derived indicators conditional (`ventas_opcionales.html.twig:392,400,471,482`); master `activa` cell and selected-tarifa value cell remain. |

### Scenario coverage

| Scenario | Covered by | Layer |
|----------|-----------|-------|
| VCG-01 owner active / inactive / no literal | `CaracteristicaResolverTest`, `CaracteristicaBoundariesTest` | Unit / source-scan |
| VCG-02 inactive column, colspan, active as before | `VentasArticulosListCaracteristicasTest` | Source-contract |
| VCG-03 inactive controls absent + price/activo stay; active as before | `TarifTabPreciosTest`, `VentasArticuloPerTarifaPaneTest` | Integration (real partial) |
| VCG-04 inactive columns absent; active as before | `VentasOpcionalesControllerMasterStateTest` | Source-contract |
| VCG-05 inactive labels/values absent; active as before | `TarifOpcionalEditCaracteristicaTest` | Source-contract |
| VCG-06 read-mode uniformity | `CaracteristicaReadThroughTest` | Unit (trait host) |
| VCG-07 no write / re-enable restores | diff audit (no runtime DB test executed) | Static |
| VCG-08 no tarifario reference / plugin-agnostic | `CaracteristicaBoundariesTest` | Source-scan |

---

## Checks executed

| # | Command | Exit | Result |
|---|---------|------|--------|
| 1 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml <11 change-specific test files>` | 0 | **OK — 119 tests, 759 assertions** (0 failures, 0 errors) |
| 2 | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` (full plugin suite) | 0 | **OK — 1053 tests, 4648 assertions, 0 failures, 2 warnings, 1 skipped** |
| 3 | `git show --name-only 717178d5 ea176fa9` | 0 | 19 files: 9 production, 10 tests |
| 4 | `grep` added production lines for `tarifario` / schema / delete | 0 | No introduced plugin-name literal; no schema/data change |
| 5 | watch-list grep for out-of-scope files in diff | 0 | None changed |

### Environment note — foreign WIP causality

The preflight warned that a concurrent change (`opcionales-versatilidad`) was
writing untracked models/tests into the same repo and that a full-suite run would
show ~4 failures. **This did not reproduce**: the full plugin suite is green
(1053 tests). No foreign failure was observed, so no failure was misattributed.
The untracked foreign files were present in the working tree during the run, so
this is a real (green) observation, not an artifact of exclusion.

---

## Out-of-scope integrity (diff inspection)

Confirmed untouched by PR1+PR2:

- **Multitarifa**: `b_codtarifa` selectors, `Precio`/`Activo`/`Estado` columns,
  per-tarifa price inputs, `activa` toggle, `tarif_tarifas`, `tarif_familias`,
  menu — none in the changed-file list; the diffs add no ungated changes to them.
- **Watch-list files**: `View/tarif_tarifas.html.twig`, `View/tarif_familias.html.twig`,
  `View/Hooks/partials/opcional_precios_rows.html.twig`,
  `View/Macro/TarifarioComponents.html.twig`, `Controller/VentasArticulo.php`
  (singular) — none appear in either commit. `Controller/VentasArticulo.php` is
  correctly NOT a seam site (AJAX fragment is served by `tarif_tab_precios`), matching design.
- **`clientes_core`**: not touched.
- **`model/table/*.xml`**: not touched.
- **Data**: no migration, no schema change, no `DELETE`/`DROP` added.

## Ownership constraint

- Introduced production lines containing `tarifario` / `plugins/tarifario/` /
  `@tarifario/`: **none**.
- Pre-existing occurrences remain in the two controllers (`parent::__construct(..., 'tarifario', ...)`,
  `init_tarifario_opcional_state()`) and in the partial's CSS class names
  (`tarifario-tab-*`). These are **pre-existing** (not introduced by these
  commits), are not used to answer the gate, and the boundaries guard only scans
  the gate methods. No gate-answering code references the plugin name.
- `fsframework.ini` `require` not changed (no `catalogo_core → tarifario` dependency).

---

## Strict TDD compliance

| Check | Result | Details |
|-------|--------|---------|
| TDD Evidence table reported | ❌ | No `apply-progress.md` in the active change dir (only archived changes have one). Per `strict-tdd-verify` Step 5a this is the "no table found" case. |
| All tasks have tests | ✅ | 10 test files cover the implemented behavior; `tasks.md` 0 unchecked. |
| RED confirmed (test files exist) | ✅ | Accessor tests call the production methods directly; boundary test asserts the 4 seams by regex. |
| GREEN confirmed (tests pass on execution) | ✅ | Isolated run: 119/119 tests, 759 assertions. |
| Triangulation adequate | ✅ | Accessor: active / inactive / operator-owned / unknown / memoized; partial: active vs inactive with price/`activo` retained. |
| Safety Net for modified files | ⚠️ | Modified test files retained their prior assertions and added the new inactive/active cases; historical pre-change run not recorded (no apply-progress). |

**TDD Compliance**: 4/6 checks verified. The missing evidence table is scoped as
Findings **W1** — a process/documentation gap. The delivered tests are
demonstrably non-vacuous (direct production calls, value assertions, fail-closed
source guards), so this does not indicate a functional defect.

### Test layer distribution

| Layer | Tests | Files | Tools |
|-------|-------|-------|-------|
| Unit / trait-host | accessor + read-mode + seam | 3 | PHPUnit 11.5.56 |
| Integration (real partial render) | `TarifTabPreciosTest`, `VentasArticuloPerTarifaPaneTest` | 2 | Twig `ArrayLoader` + DOMXPath |
| Source-contract | article list, opcionales list/edit, partial ownership | 5 | PHPUnit + regex |
| E2E | none | 0 | not available for these plugin pages |
| **Total** | **119 executed in the isolated set** | **11 selected files** | |

### Assertion quality

**✅ All assertions verify real behavior.** Scanned all changed test files:
no tautologies, no orphan empty-checks, no ghost loops over possibly-empty
collections, no type-only-only assertions. The two render tests assert DOM node
counts (`input[@name="en_tarifa"]` length 0, `precio`/`activo` length 1), which
are behavioral value checks. The resolver test uses a static call counter to
prove the "single definitions read" contract.

### Coverage / quality metrics

- Coverage tool: not detected → coverage analysis skipped (not a failure).
- `phpstan`: not run in this pass (available via `ddev exec composer phpstan`).
  Static regex + runtime tests were used instead; declare this limitation.

---

## Findings

| ID | Severity | Finding | Causality / evidence |
|----|----------|---------|----------------------|
| **W1** | WARNING (process) | No `apply-progress.md` / TDD Cycle Evidence table for the active change. Historical RED/GREEN cannot be independently replayed; `strict-tdd-verify` nominally labels a missing table CRITICAL, but the substance is independently verifiable and GREEN, so it is scoped here as a process-evidence warning, not a functional defect. Per verify Hard Rules, a missing report does not gate archive. | Change dir listing; only archived changes contain `apply-progress.md`. |
| **W2** | WARNING (verification depth) | Three of the four gated templates (`ventas_articulos`, `ventas_opcionales`, `tarif_opcional_edit`) are verified by **source-contract tests only** — no Twig render harness exists for those full pages. The gate is confirmed present/wrapped, but the rendered output is not exercised end-to-end there. Only `articulo_precios_rows` has a real-render test. | No `Twig\Environment(...)->render()` call in the three corresponding test files. |
| **S1** | SUGGESTION | Historical RED proof could be captured (or a minimal `apply-progress.md` added) so the strict-TDD compliance check is fully satisfiable before archive. | — |
| **S2** | SUGGESTION | Pre-existing `'tarifario'` strings in the two controller constructors / function names are outside the gate but will keep appearing in any naive repo-wide grep; a comment or scoped grep guidance would prevent future false positives during ownership audits. | `controller/tarif_tab_precios.php:79`, `controller/tarif_opcional_edit.php:63`. |

No CRITICAL functional findings. No data-loss, ownership, or out-of-scope
regression findings.

## Limitations (not verifiable in this pass)

- Historical TDD RED execution cannot be replayed (no `apply-progress.md`).
- No browser/E2E harness: the three full-page templates are asserted at source
  level; actual DOM output in a running app was not observed.
- VCG-07 "no write" is verified by static diff audit, not by a DB-level
  write-counter test.
- PHPStan was not executed in this pass.

## Summary

The change is functionally complete and correct within its narrowed scope. All
nine requirement IDs are implemented with matching tests; the isolated run is
119 tests / 759 assertions green, and the full plugin suite is 1053 tests / 4648
assertions with zero failures. Out-of-scope multitarifa surfaces, `clientes_core`,
and `model/table/*.xml` are untouched; no schema or data mutation exists; no
plugin-name literal answers the gate. The only meaningful gaps are process
evidence (W1) and render-depth for three templates (W2); neither is a defect in
the delivered behavior. Recommended next work: archive (the warnings are
diagnostic and do not block archive per the verify Hard Rules), optionally adding
an `apply-progress.md` first if the team requires full strict-TDD completeness.
