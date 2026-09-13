# Apply Progress — Migrate `tarif_tarifas` page to catalogo_core

## Status

Complete. Both plugin suites green. Working tree only — no commit/push.
CodeRabbit remediation applied (see "CodeRabbit remediation" below): 4/4
findings fixed, catalogo_core suite `605/2348`.

**Mode**: Strict TDD (`plugins/catalogo_core/openspec/config.yaml: strict_tdd: true`).

## TDD Cycle Evidence

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| 1.1 | `catalogo_core/tests/Controller/TarifTarifasSoftSeamTest.php` | Unit (behavior + source contract) | ✅ catalogo 585/2247, tarifario 197/726 | ✅ Written — errored `missing catalogo_core controller` | ✅ Passed — 11/11 `--filter TarifTarifas` | ✅ 2 seam cases (absent → null, present → instance) + 3 group-fail-closed/ownership/view cases | ✅ Seam extracted to `loadTarifarioModel()`; catalog model imports unchanged |
| 2.1–2.4 | `TarifTarifasSoftSeamTest` + moved `TarifTarifasGuardarTest` / `TarifTarifasHeredarOpcionalMasterTest` | Unit + source contract | ✅ same baselines | ✅ (same file) | ✅ 11/11 | ✅ moved tests preserve prior 2 + 5 cases | ➖ None needed (byte-preserving move) |
| 3.1–3.2 | moved tests | Unit | ✅ 585/2247 + 197/726 | n/a (approval tests moved) | ✅ both suites green | ✅ 7 relocated cases still assert the same behavior | ➖ None needed |

## Work Unit Evidence

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifTarifas` → `OK (11 tests, 53 assertions)` (exit 0) |
| Runtime harness command/scenario and exact result | Twig full compile of the moved view with the real include/import chain: `$twig->load('tarif_tarifas.html.twig')` with loader `[plugins/catalogo_core/View, themes/AdminLTE/view]` → `TWIG COMPILE OK` |
| Rollback boundary | tarifario: restore `controller/tarif_tarifas.php`, `View/tarif_tarifas.html.twig`, `tests/Controller/TarifTarifas{Guardar,HeredarOpcionalMaster}Test.php`. catalogo_core: delete `controller/tarif_tarifas.php`, `View/tarif_tarifas.html.twig`, `tests/Controller/TarifTarifas{SoftSeam,Guardar,HeredarOpcionalMaster}Test.php`, revert `tests/ArticuloListaCanonicaOwnershipTest.php:46`. No model/table/schema change to revert. |

## Files Changed

### catalogo_core (added)
- `controller/tarif_tarifas.php` — migrated controller; `extends fbase_controller`;
  soft seam `loadTarifarioModel()`; group paths fail closed.
- `View/tarif_tarifas.html.twig` — migrated view; group/roles UI gated by
  `fsc.tarifario_activo`.
- `tests/Controller/TarifTarifasSoftSeamTest.php` — new soft-seam contract.
- `tests/Controller/TarifTarifasGuardarTest.php` — moved from tarifario.
- `tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php` — moved from tarifario.

### catalogo_core (modified)
- `tests/ArticuloListaCanonicaOwnershipTest.php:46` — `REPOINTED_LINKS` entry
  `plugins/tarifario/View/tarif_tarifas.html.twig` → `plugins/catalogo_core/...`.

### catalogo_core (openspec)
- `openspec/changes/migrar-tarif-tarifas-a-catalogo-core/{proposal,tasks,apply-progress}.md`.

### tarifario (removed)
- `controller/tarif_tarifas.php`
- `View/tarif_tarifas.html.twig`
- `tests/Controller/TarifTarifasGuardarTest.php`
- `tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php`

## Soft-Seam Design

```php
protected function loadTarifarioModel(string $fqcn): ?object
{
    if (!class_exists($fqcn)) {
        return null;          // tarifario inactive → fail closed
    }
    $model = new $fqcn();
    return is_object($model) ? $model : null;
}
```

- `private_core()` resolves `tarif_grupo_tarifa` + `tarif_grupo_rol`; sets
  `tarifario_activo` and `grupos_disponibles = []` when absent.
- `guardar_tarifa()` only assigns a group when `tarifario_activo`.
- `copy_grupos()` no-op without `grupo_tarifa_model`.
- `count_usuarios_tarifa()` resolves `tarif_tarifa_rol` lazily → `0` when absent.
- `get_grupos_tarifa()` → `[]`; `ajax_grupos_tarifa()` → `{"grupos":[]}`.
- `asignar_grupo()` emits an error and returns without touching any model.
- View: group column, group modal, group select, and both `page=tarif_roles`
  links render only when `fsc.tarifario_activo`.

`class_exists()` is a soft probe: with tarifario active the model autoloader
resolves `FSFramework\model\tarif_grupo_*`; with tarifario inactive it returns
false and catalogo_core stays self-contained (zero `plugins/tarifario/` paths).

## Gates and Observed Counts

| Command | Result |
|---|---|
| `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` | `OK, but there were issues! Tests: 596, Assertions: 2300, Warnings: 26, Skipped: 1` (baseline 585/2247 + 11 moved/new) |
| `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` | `OK, but some tests were skipped! Tests: 190, Assertions: 685, Skipped: 3` (baseline 197/726 − 7 moved) |
| `grep -rn "plugins/tarifario/" catalogo_core/{controller,Controller,View,Services,extras,model}` | `matches=0` |
| no half-moved file | tarifario controller+view absent; catalogo_core controller+view present |
| tarifario group/role models remain | `tarif_grupo_tarifa`, `tarif_grupo_rol`, `tarif_grupo_usuario`, `tarif_grupo_articulo`, `tarif_tarifa_rol` present |
| Twig cache | `tmp/twig_cache` cleared and recreated |
| `php -l` | OK on controller + all three catalogo tests |

## Security Audit (fsframework-security-review)

| Category | Verdict |
|---|---|
| SQL injection | PASS — all copy SQL escapes `$origen`/`$destino` via `$model->var2str()`; no new query. |
| XSS | PASS — view uses `{{ }}`; `msg\|raw` is pre-existing and carries controller-built HTML. |
| CSRF | PASS — both POST forms keep `{{ csrf_field() }}`; framework auto-validates in `pre_private_core()`. Unchanged. |
| Password / uploads / redirects / session | N/A — not touched by this surface. |
| Error exposure | PASS — no `var_dump`/`print_r`/`debug_backtrace`. |
| Input validation | PASS — unchanged `trim()`/whitelist `coddivisa` validation. |

## Deviations from the plan

- None material. The plan suggested mirroring a
  `VentasCatalogoStateTrait::loadTarifarioModel`; that file/method does not
  exist. The seam was modeled on the established catalogo_core pattern in
  `tarif_tab_precios.php` / `tarif_opcional_tab.php` (lazy `class_exists`),
  generalized into the `loadTarifarioModel()` helper requested.
- The Usuarios column (a `page=tarif_roles` link) was also gated behind
  `fsc.tarifario_activo` so the page degrades coherently, not just the Grupo
  column.
- Model tests `TarifTarifaTest` / `TarifTarifaMigrateCoddivisaTest` stayed in
  tarifario: they target the `tarif_tarifa` model, which did not move in this
  change (it already lives in catalogo_core).

## Caveats

- `heredar_estructura()` step order and `copy_precios_opcionales()` semantics
  (precio + porcentaje + en_catalogo) are unchanged; the pinned contracts pass.
- With tarifario inactive the page loses group assignment and the users count
  by design; the page otherwise works (catalogo_core owns model, table and
  related tables).
- `tarifario_check_tables()` is no longer triggered by opening this page;
  catalogo_core `Init` already bootstraps `tarif_tarifas` and the absorbed
  tables, and tarifario `Init`/`upgrade()` ensure its own tables when active.
- No `tarif_tarifas_ext` table was created (confirmed unnecessary).

## Next

`next_recommended: sdd-verify` — the change is plugin-local and both suites are
green; independent verification should confirm the grep gate, the Twig compile
and the seal against the shared specs.

---

## CodeRabbit remediation (post-verify hardening)

Four review findings on the migrated `tarif_tarifas` page. Each one was
re-validated against the current code and fixed; none were stale.

| # | Severity | Finding | Fix |
|---|----------|---------|-----|
| 1 | CRITICAL | `delete` / `set_default` / `copy_structure` were dispatched from GET query params | Moved into the POST `action` switch, each guarded by `requireCsrf()`; handlers now read `$_POST`; the view submits `set_default` as an inline CSRF form and `delete` / `copy_structure` through a shared `#tarif_mutacion_form` + `ejecutar_mutacion()`. |
| 2 | CRITICAL | `copy_structure()` allowed `copy_from === copy_to` | Added an equality guard before `heredar_estructura()` that emits an error and returns. |
| 3 | MAJOR | inline `onclick` handlers interpolated `codtarifa` / `nombre` unescaped | All four handlers (`abrirAsignarGrupo` ×2, `copiar_estructura`, `editar_tarifa`, `eliminar_tarifa`) now pass each string argument as `{{ value\|json_encode\|e('html_attr') }}`, preserving arguments and behavior. |
| 4 | MAJOR | `asignar_grupo` AJAX error opened the modal with cleared selections | The error branch now shows `bootbox.alert(...)` and keeps `#modal_asignar_grupo` closed; success path unchanged. |

### Files changed

- `controller/tarif_tarifas.php` — POST-only, CSRF-guarded action switch;
  `delete_tarifa()` / `set_default()` / `copy_structure()` read `$_POST`;
  equal source/destination guard.
- `View/tarif_tarifas.html.twig` — `set_default` inline POST form; shared
  `#tarif_mutacion_form` + `ejecutar_mutacion()` for `delete` /
  `copy_structure`; `|json_encode|e('html_attr')` on every inline-handler
  argument; AJAX error path shows an alert instead of opening the modal.
- `tests/Controller/TarifTarifasMutationSecurityTest.php` — new contract
  (9 tests / 48 assertions): RED → GREEN. The 9th test is a regression guard
  added after review caught that the first `ejecutar_mutacion()` draft blanked
  the form's CSRF hidden inputs along with the mutation fields.

Out of scope (not part of the four findings): the `copiar_estructura()` JS body
still builds its `<option>` list from raw model values. Those values pass
`tarif_tarifa::test()` → `no_html()` (escapes `& " ' < >`) before persistence,
so the JS string cannot be broken with stored data; left untouched to keep the
remediation minimal.

### TDD Cycle Evidence (remediation)

| Task | Test File | Layer | Safety Net | RED | GREEN | TRIANGULATE | REFACTOR |
|------|-----------|-------|------------|-----|-------|-------------|----------|
| F1 dispatch | `TarifTarifasMutationSecurityTest` | Source contract (\`private_core\` body) | ✅ catalogo 596/2300 | ✅ `Tests: 8, Assertions: 10, Errors: 1, Failures: 7` | ✅ 9/9 | ✅ no-GET + POST cases + POST param reads + view POST forms | ➖ none |
| F2 equal codes | same | Unit (behavior + source ordering) | ✅ same | ✅ (same RED run) | ✅ 9/9 | ✅ behavior (no inherit) + guard-before-inherit ordering | ➖ none |
| F3 onclick encoding | same | Source contract | ✅ same | ✅ (same RED run) | ✅ 9/9 | ✅ four handler patterns + no-raw-onclick negative | ➖ none |
| F4 AJAX error | same | Source contract (JS body) | ✅ same | ✅ (same RED run) | ✅ 9/9 | ✅ single modal-show + error-branch assertion | ➖ none |

### Work Unit Evidence (remediation)

| Evidence | Required value |
|---|---|
| Focused test command and exact result | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml --filter TarifTarifasMutationSecurityTest` → RED `Tests: 8, Assertions: 10, Errors: 1, Failures: 7`; GREEN `OK (9 tests, 48 assertions)` (exit 0) |
| Runtime harness command/scenario and exact result | Twig compile of the edited view with the real loader chain (`plugins/catalogo_core/View` + `themes/AdminLTE/view`): `TWIG COMPILE OK` |
| Rollback boundary | revert the two production files (`controller/tarif_tarifas.php`, `View/tarif_tarifas.html.twig`) and delete `tests/Controller/TarifTarifasMutationSecurityTest.php`; no model/table/schema change |
| `php -l` | `No syntax errors detected` on the controller and the new test |
| Full suite | `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml` → `Tests: 605, Assertions: 2348, Warnings: 26, Skipped: 1` (596/2300 baseline + 9 tests / 48 assertions) |
| Twig cache | `tmp/twig_cache` cleared and recreated |
| Residual GET mutation callers | none — only `contabilidad_impuestos` (a different page/controller) still uses its own `set_default` GET link |
