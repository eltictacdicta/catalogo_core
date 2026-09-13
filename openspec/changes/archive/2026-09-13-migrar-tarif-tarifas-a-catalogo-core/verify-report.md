```yaml
schema: gentle-ai.verify-result/v1
evidence_revision: sha256:bda2c2229eefda2aaf7c96f5986822bb25fa6c92aa81f7fef87cabe438d8e23b
verdict: pass_with_warnings
blockers: 0
critical_findings: 0
requirements: 0/0
scenarios: 0/0
test_command: ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml && ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
test_exit_code: 0
test_output_hash: sha256:8a8f4dde825dba34bc7e8e90fe512fff543aa3501a7b12e12f00fef07f8d8e0e
build_command: ddev exec composer phpstan
build_exit_code: 0
build_output_hash: sha256:aa8f5b1f12073416ecfbdd25eeeec34517ca9191d7d009d2ebbcf6050296b525
```

# Verify Report — `migrar-tarif-tarifas-a-catalogo-core`

- **Change**: `plugins/catalogo_core/openspec/changes/migrar-tarif-tarifas-a-catalogo-core/` (plugin-local).
- **Mode**: Strict TDD (`plugins/catalogo_core/openspec/config.yaml: strict_tdd: true`, runner present) — standard verify run; the apply-side TDD cycle evidence was reviewed, not re-driven.
- **Artifacts available**: `proposal.md`, `tasks.md`, `apply-progress.md`. **No delta `specs/` and no `design.md`** exist for this change, so the requirement/scenario dimension is skipped (`0/0`) and design coherence is not applicable (see WARNING-1).
- **Independence**: every check below was re-executed by the verify actor against the current working trees; apply claims were not trusted. No source code was modified. No commit/push performed.
- **Repository state**: both plugins are independent git repos. `plugins/catalogo_core` shows the controller/view/tests as untracked additions plus one modified test; `plugins/tarifario` shows the four old files staged as deletions:
  ```text
  catalogo_core:  M tests/ArticuloListaCanonicaOwnershipTest.php
                  ?? View/tarif_tarifas.html.twig
                  ?? controller/tarif_tarifas.php
                  ?? tests/Controller/TarifTarifas{SoftSeam,MutationSecurity,Guardar,HeredarOpcionalMaster}Test.php
  tarifario:      D  View/tarif_tarifas.html.twig
                  D  controller/tarif_tarifas.php
                  D  tests/Controller/TarifTarifas{Guardar,HeredarOpcionalMaster}Test.php
  ```

## Hash provenance

| Field | Value | How computed |
|---|---|---|
| `evidence_revision` | `sha256:bda2c2229eefda2aaf7c96f5986822bb25fa6c92aa81f7fef87cabe438d8e23b` | `sort` of the 11 changed/added/deleted code paths across both plugin repos → `sha256sum`. Listing: `/tmp/opencode/verify/changed_paths.txt`. |
| `test_output_hash` | `sha256:8a8f4dde825dba34bc7e8e90fe512fff543aa3501a7b12e12f00fef07f8d8e0e` | `sha256sum` of the concatenated stdout of the two plugin suite runs (`catalogo_test.txt` + `tarifario_test.txt`). |
| `build_output_hash` | `sha256:aa8f5b1f12073416ecfbdd25eeeec34517ca9191d7d009d2ebbcf6050296b525` | `sha256sum` of `ddev exec composer phpstan` stdout. |

## Completeness

`tasks.md` checkbox census: **13 checked / 0 unchecked** (Phases 1–4, all `[x]`). No incomplete task.

| Metric | Value |
|--------|-------|
| Tasks total | 13 |
| Tasks complete | 13 |
| Tasks incomplete | 0 |

## Build & Tests Execution

**Build / type-check**: ✅ Passed — `ddev exec composer phpstan` (project `phpstan.neon`: paths `src`, `tests`; plugins are out of the configured scope and analysed separately only on demand).
```text
Note: Using configuration file /var/www/html/phpstan.neon.
 188/188 [▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓▓] 100%
 [OK] No errors
```

**Tests**: ✅ catalogo_core `605 / 2348` OK (Warnings 26, Skipped 1) · ✅ tarifario `190 / 685` OK (Skipped 3) — both match the expected counts exactly.
```text
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
.........................W.W..................S................ 189 / 605 ( 31%)
......................................                          605 / 605 (100%)
Time: 01:16.603, Memory: 10.00 MB
OK, but there were issues!
Tests: 605, Assertions: 2348, Warnings: 26, Skipped: 1.

$ ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
......................................SS......S................  63 / 190 ( 33%)
.                                                               190 / 190 (100%)
Time: 00:29.636, Memory: 8.00 MB
OK, but some tests were skipped!
Tests: 190, Assertions: 685, Skipped: 3.
```

**Focused suites** (all green, exit 0):
```text
$ ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifTarifas
OK (20 tests, 101 assertions)

  TarifTarifasSoftSeamTest ............... OK (4 tests, 12 assertions)
  TarifTarifasMutationSecurityTest ....... OK (9 tests, 48 assertions)
  TarifTarifasGuardarTest ................ OK (2 tests,  8 assertions)
  TarifTarifasHeredarOpcionalMasterTest .. OK (5 tests, 33 assertions)
```

**Runtime harness (Twig compile, independent)**: ✅ Passed. A scratch harness (created under the repo root, executed, then deleted) built a `Twig\Environment` with loader paths `[plugins/catalogo_core/View, themes/AdminLTE/view]`, registered the `csrf_field`/`csrf_token`/`csrf_meta`/`csp_nonce_attr` stubs and the `trans` filter, then `$twig->load('tarif_tarifas.html.twig')` with the real `header`/`footer`/`Macro/TarifarioComponents.html.twig` include-import chain:
```text
$ ddev exec php .verify-twig-harness.php
TWIG COMPILE OK
```

**Coverage**: ➖ Not available (no coverage threshold configured for this plugin suite).

## Compliance Matrix

No delta specs exist for this change, so the matrix is built from the `proposal.md` acceptance criteria and the task gates plus the requested verification checks. Statuses use the SDD verify vocabulary.

| # | Criterion (proposal acceptance / task gate / requested check) | Evidence | Result |
|---|---|---|---|
| 1 | Both plugin suites green | catalogo `605/2348` OK, tarifario `190/685` OK | ✅ COMPLIANT |
| 2 | Ownership: controller + view exist ONLY in catalogo_core | `ls` shows catalogo_core controller/view present; tarifario copies absent; single `class tarif_tarifas` definition repo-wide, in catalogo_core; focused tests load the class from the catalogo_core path | ✅ COMPLIANT |
| 3 | Base model `FSFramework\model\tarif_tarifa` + `model/table/tarif_tarifas.xml` remain catalogo_core-only | `plugins/catalogo_core/model/tarif_tarifa.php`, `plugins/catalogo_core/model/table/tarif_tarifas.xml`; no twin in tarifario | ✅ COMPLIANT |
| 4 | Zero tarifario coupling in catalogo_core production dirs | `grep -rn "plugins/tarifario/" catalogo_core/{controller,Controller,View,Services,extras,model}` → no matches (exit 1) | ✅ COMPLIANT |
| 5 | Soft seam resolves group/user models via `class_exists`, fail-closed when tarifario inactive | `loadTarifarioModel()` (controller L156–165) returns `null` for absent class; `guardar_tarifa` skips group (L226); `copy_grupos` no-op (L331–335); `count_usuarios_tarifa` → `0` (L584–589); `get_grupos_tarifa` → `[]` (L597–600); `ajax_grupos_tarifa` → `{"grupos":[]}` (L606–621) | ✅ COMPLIANT (read paths unit-tested; `copy_grupos` no-op and AJAX empty-JSON are static-only) |
| 6 | View UI gated by `fsc.tarifario_activo` | Twig L65–70 (`tarif_roles`), L123–125 (Grupo column), L129–131 + L200–206 (Usuarios column), L277–288 (group select), L324–392 (group modal) | ✅ COMPLIANT |
| 7 | Group/user models stay in tarifario only | `tarif_grupo_tarifa`, `tarif_grupo_rol`, `tarif_grupo_usuario`, `tarif_grupo_articulo`, `tarif_tarifa_rol` found only under `plugins/tarifario/model/`; none in catalogo_core | ✅ COMPLIANT |
| 8 | Review fix: delete/set_default/copy_structure POST-only + CSRF-guarded, no GET mutation | Controller `private_core` POST `action` switch with `requireCsrf()` (L112–140); handlers read `$_POST` (L455–456, L487, L503); no `$_GET[...]` mutation reads; view submits via inline POST form + `#tarif_mutacion_form` | ✅ COMPLIANT |
| 9 | Review fix: `copy_structure` rejects equal codes | Equality guard emits error and returns before `heredar_estructura()` (L458–461) | ✅ COMPLIANT |
| 10 | Review fix: inline `onclick` args JSON/HTML-attr encoded | `{{ ...|json_encode|e('html_attr') }}` on all inline handlers (view L176, L182, L212, L215, L219) | ✅ COMPLIANT |
| 11 | Review fix: AJAX error keeps the modal closed | `abrirAsignarGrupo` error branch shows `bootbox.alert(...)`, no `modal('show')` (view L524–528); single `modal('show')` on success path | ✅ COMPLIANT |
| 12 | `TarifTarifasMutationSecurityTest` green | `OK (9 tests, 48 assertions)` | ✅ COMPLIANT |
| 13 | No `tarif_tarifas_ext` table created | `grep -rn "tarif_tarifas_ext"` over `plugins base src model controller` → no matches; no such file anywhere | ✅ COMPLIANT |
| 14 | No core `openspec/` entry | `grep -rln "migrar-tarif-tarifas-a-catalogo-core" openspec/` → no matches (exit 1) | ✅ COMPLIANT |
| 15 | `heredar_estructura()` step order + `copy_precios_opcionales()` semantics preserved | `TarifTarifasHeredarOpcionalMasterTest` OK (5 tests); controller L253–287 eleven steps and L312–323 (precio + porcentaje + en_catalogo) unchanged | ✅ COMPLIANT |

**Compliance summary**: 15/15 applicable criteria compliant (spec-scenario dimension skipped — no delta specs).

## Issues Found

**CRITICAL**: None.

**WARNING**:
- **W-1 — Open DEV-DB smoke (authenticated, tarifario inactive) not run.** No authenticated browser session existed (`chrome-devtools list_pages` → only `about:blank`) and no reusable authenticated smoke harness for the tarifas page was found. The graceful-degradation claim when tarifario is inactive is therefore backed by static inspection + the fail-closed unit contracts, not by a real DEV-DB page load. Non-blocking per the task contract; operator to run before archive if desired.
- **W-2 — SDD artifact completeness.** The change carries `proposal.md` + `tasks.md` + `apply-progress.md` but no delta `specs/` and no `design.md`. Requirement/scenario verification is consequently skipped (`0/0`) and compliance is judged against the proposal acceptance criteria and task gates instead. The move itself is fully covered by runtime tests.

**SUGGESTION**:
- **S-1 — Stale focused-count note in `apply-progress.md`.** The TDD cycle row records `--filter TarifTarifas` → `OK (11 tests, 53 assertions)`. That predates the CodeRabbit remediation; the current combined filter is `OK (20 tests, 101 assertions)` (SoftSeam 4 + MutationSecurity 9 + Guardar 2 + Heredar 5). The full-suite arithmetic (`605 = 596 + 9`) is correct. Documentation nuance only.
- **S-2 — `copiar_estructura()` option list still interpolates raw model values** into a JS-built `<option>` (view L470–474). Acknowledged out of scope in `apply-progress.md`: stored values already pass `tarif_tarifa::test()` → `no_html()`, so they cannot break the JS string. Left as-is to keep the remediation minimal.

## Verdict

**PASS WITH WARNINGS**

All 13 tasks complete; both plugin suites pass at the exact expected counts (`605/2348` and `190/685, Skipped 3`); the ownership move, zero-coupling grep gate, soft-seam fail-closed contract, review fixes and absence of `tarif_tarifas_ext` / core `openspec/` entries are independently confirmed. The only outstanding items are the non-executable authenticated DEV-DB smoke (W-1) and the pre-existing absence of delta spec/design artifacts (W-2); neither contradicts an acceptance criterion or a task gate.
