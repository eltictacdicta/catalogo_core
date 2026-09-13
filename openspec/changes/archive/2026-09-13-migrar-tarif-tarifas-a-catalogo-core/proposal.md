# Migrate `tarif_tarifas` page to catalogo_core

## Intent

Complete the ownership move of the tarifas CRUD surface. The base model
`FSFramework\model\tarif_tarifa` and `model/table/tarif_tarifas.xml` already live
in `catalogo_core`; the controller and the view still live in `tarifario`. This
change moves them so the tarifas page is owned by the same plugin that owns its
model, table, families, articles and opcionales surfaces.

Only the RBAC/groups mechanism stays in `tarifario`; the moved page consumes it
through a soft seam.

## Scope

### In scope
- Move `tarifario/controller/tarif_tarifas.php` → `catalogo_core/controller/tarif_tarifas.php`.
- Move `tarifario/View/tarif_tarifas.html.twig` → `catalogo_core/View/tarif_tarifas.html.twig`.
- Reclass the controller: `extends tarif_controller` → `extends fbase_controller`
  (catalogo_core) and drop the `tarifario/extras/tarif_controller.php` require.
- Replace the hard `plugins/tarifario/model/*` requires (`tarif_grupo_rol`,
  `tarif_grupo_usuario`, `tarif_grupo_tarifa`, `tarif_tarifa_rol`) with a soft
  seam (`loadTarifarioModel()`), fail-closed when `tarifario` is inactive.
- Repoint the `tarif_tarifas`-targeting tarifario tests to catalogo_core.
- Keep the slug `tarif_tarifas` and the menu folder `tarifario` unchanged.
- Preserve `heredar_estructura()` step order and the review-fixed
  `copy_precios_opcionales()` semantics (precio + porcentaje + en_catalogo).

### Out of scope
- The model `tarif_tarifa` and `model/table/tarif_tarifas.xml` (already in catalogo_core).
- The tarifario RBAC tables/models (`tarif_grupo_*`, `tarif_tarifa_rol`) — they stay.
- `tarif_roles`, `tarif_actualizar_precios`, `tarif_catalogo_view`, `tarif_historial_precios`.
- No `tarif_tarifas_ext` table (confirmed not needed).

## Approach

1. Byte-copy controller + view to catalogo_core, then edit the copied files
   (cross-repo `git mv` is impossible; both plugins are independent git repos).
2. Soft seam: a `protected function loadTarifarioModel(string $fqcn): ?object`
   helper that resolves the FQCN lazily and returns `null` when the class is
   absent. Every group/user path is guarded and degrades gracefully:
   `grupos_disponibles = []`, `get_grupos_tarifa() = []`,
   `count_usuarios_tarifa() = 0`, `copy_grupos()` no-op, `asignar_grupo()`
   emits an error without touching a model.
3. The view hides the group column, the group modal, the group select and the
   `tarif_roles` link when `fsc.tarifario_activo` is false, so catalogo_core
   renders a coherent page with tarifario inactive.
4. Remove the tarifario controller + view copies; keep the slug resolving from
   catalogo_core (page discovery scans active plugins' `controller/`).
5. Move the two controller tests to catalogo_core and update namespaces/paths.

## Acceptance

- `catalogo_core` production surface has zero `plugins/tarifario/` reference.
- Both plugin suites stay green.
- `heredar_estructura()` step order and `copy_precios_opcionales()` unchanged.
