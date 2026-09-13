# Tasks — Migrate `tarif_tarifas` page to catalogo_core

Delivery: plugin-local, both plugin suites must stay green.
Runner:
- `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml`

## Phase 1 — Soft-seam contract (RED)

- [x] 1.1 Add `catalogo_core/tests/Controller/TarifTarifasSoftSeamTest.php`
      (RED) asserting: controller exists at the catalogo_core path, has no
      `plugins/tarifario/` production require, `loadTarifarioModel()` returns
      `null` for an absent class and an instance for a present class, and group
      methods fail closed when the seam is inactive.

## Phase 2 — Move controller + view

- [x] 2.1 Copy `tarifario/controller/tarif_tarifas.php` →
      `catalogo_core/controller/tarif_tarifas.php`.
- [x] 2.2 Reclass to `extends fbase_controller`; drop the
      `tarifario/extras/tarif_controller.php` require; keep the catalogo_core
      model requires and the `use FSFramework\model\*` imports.
- [x] 2.3 Replace the four hard tarifario group/role requires + `use`s with the
      `loadTarifarioModel()` soft seam; guard `private_core`,
      `guardar_tarifa`, `copy_grupos`, `count_usuarios_tarifa`,
      `get_grupos_tarifa`, `ajax_grupos_tarifa`, `asignar_grupo`.
- [x] 2.4 Copy `tarifario/View/tarif_tarifas.html.twig` →
      `catalogo_core/View/tarif_tarifas.html.twig`; conditionally render the
      group column, group modal, group select and `tarif_roles` link behind
      `fsc.tarifario_activo`.
- [x] 2.5 Remove the tarifario controller + view copies.

## Phase 3 — Test repoints

- [x] 3.1 Move `tests/Controller/TarifTarifasGuardarTest.php` to
      `catalogo_core/tests/Controller/`; namespace
      `Tests\CatalogoCore\Controller`; require the catalogo_core controller path.
- [x] 3.2 Move `tests/Controller/TarifTarifasHeredarOpcionalMasterTest.php` to
      `catalogo_core/tests/Controller/`; namespace and `CONTROLLER` const repointed.

## Phase 4 — Gates

- [x] 4.1 `catalogo_core` suite green (baseline 585/2247).
- [x] 4.2 `tarifario` suite green (baseline 197/726, Skipped 3).
- [x] 4.3 `grep -rn "plugins/tarifario/" catalogo_core/{controller,Controller,View,Services,extras,model}`
      = 0 matches.
- [x] 4.4 No half-moved file: tarifario controller/view gone, catalogo_core
      controller/view present; group/role models remain in tarifario.
- [x] 4.5 Clear the Twig cache.
