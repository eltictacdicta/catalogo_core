# Archive Report — `migrar-tarif-tarifas-a-catalogo-core`

- **Change**: `migrar-tarif-tarifas-a-catalogo-core` (plugin-local, `plugins/catalogo_core/openspec/`)
- **Ownership**: catalogo_core (main beneficiary); `tarifario` keeps the groups/users mechanisms.
- **Archived**: 2026-09-13
- **Verdict**: `pass_with_warnings` (0 blockers, 0 critical)

## Summary

Migrated the `tarif_tarifas` page (controller + view) from `tarifario` to `catalogo_core`, leaving the groups/users mechanisms (`tarif_grupo_tarifa`, `tarif_grupo_rol`, `tarif_grupo_usuario`, `tarif_grupo_articulo`, `tarif_tarifa_rol`) as a tarifario extension accessed via a soft seam. No `tarif_tarifas_ext` table was created (per the user's confirmed decision).

## Final state (delivered)

| Artifact | Action |
|---|---|
| `plugins/catalogo_core/controller/tarif_tarifas.php` | Migrated; `extends fbase_controller` (was `tarif_controller`); soft seam `loadTarifarioModel()` via `class_exists`; POST-only + CSRF-guarded mutations |
| `plugins/catalogo_core/View/tarif_tarifas.html.twig` | Migrated; group/roles UI gated by `fsc.tarifario_activo`; inline-handler args `json_encode|e('html_attr')`; AJAX error keeps the modal closed |
| `plugins/tarifario/controller/tarif_tarifas.php`, `View/tarif_tarifas.html.twig` | Removed (moved) |
| `plugins/catalogo_core/tests/Controller/TarifTarifasSoftSeamTest.php` | New |
| `plugins/catalogo_core/tests/Controller/TarifTarifasMutationSecurityTest.php` | New (CodeRabbit remediation) |
| `plugins/catalogo_core/tests/Controller/TarifTarifasGuardarTest.php`, `TarifTarifasHeredarOpcionalMasterTest.php` | Moved from tarifario |
| `plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php` | Repointed link path |

- The base model `FSFramework\model\tarif_tarifa` + `model/table/tarif_tarifas.xml` were already catalogo_core-owned (moved earlier by the familias absorber) and were NOT touched.
- Soft-seam fail-closed behavior (tarifario inactive): `guardar_tarifa` skips group assignment, `copy_grupos` no-op, `count_usuarios_tarifa`→0, `get_grupos_tarifa`→`[]`, `ajax_grupos_tarifa`→`{"grupos":[]}`; the view hides the Grupo/Usuarios columns, group modal/select and `page=tarif_roles` links.

## Evidence

| Suite | Result |
|---|---|
| catalogo_core | **605 tests / 2348 assertions OK** (Warnings 26, Skipped 1) |
| tarifario | **190 tests / 685 assertions OK** (Skipped 3) |
| Focused `--filter TarifTarifas` | OK (20 tests, 101 assertions) |
| PHPStan | `[OK] No errors` (188 files) |
| Grep coupling | **0** `plugins/tarifario/` references in catalogo_core production |
| Ownership | `tarif_tarifas` controller+view exist only in catalogo_core; group/user models only in tarifario |
| Core openspec | no entry |

## Review + remediation

- CodeRabbit (uncommitted): catalogo_core **4 findings** (2 critical, 2 major), tarifario **0**. All fixed: GET→POST/CSRF mutations; `copy_from === copy_to` guard; inline-handler JS encoding; AJAX error keeps the modal closed.
- Follow-up review of the `abrirAsignarGrupo` AJAX error path flagged during implementation was addressed with a dedicated regression test.

## Open warnings (non-blocking)

1. Authenticated DEV-DB smoke with tarifario inactive was not executed (no session/harness); graceful degradation is proven by static inspection + fail-closed unit contracts.
2. No delta `specs/`/`design.md` for this change (straight migration); verification used the proposal acceptance criteria + task gates.
3. `copiar_estructura()` still builds `<option>` from raw model values (out of scope; values are `no_html()`-escaped before persistence).
4. `apply-progress.md` records a stale focused count (11/53) vs the current combined 20/101 (full-suite arithmetic is correct).

## Delivery

- **Human-owned.** The change was committed/pushed as part of the catalogo_core `v1.5.0` / tarifario `v3.1.0` release flow (single commit per repo, tag, push). No core `openspec/` change.
