# Proposal: Migrar Familias de Tarifario a Catalogo Core (Migración Acotada)

## Intent

La gestión del catálogo hoy vive dividida: `tarifario` expone una UI jerárquica completa de familias (árbol madre/hija con capítulos, niveles, asignación de artículos y vista de catálogo por tarifa), mientras `catalogo_core` tiene la jerarquía en la capa de datos (`familias.madre`, `nivel`, `hijas()`) pero una UI plana (`VentasFamilias` con dropdown de madres). Además, la estructura de familias por tarifa (`tarif_tarifa_familia`) y la asignación de artículos por tarifa (`tarif_tarifa_articulo`) quedan cautivas del plugin de precios.

**Decisión del usuario**: MIGRACIÓN ACOTADA — `catalogo_core` absorbe la superficie de catálogo (gestión jerárquica de familias con estructura por lista de precios, asignación de artículos a familia, vistas de catálogo); `tarifario` queda como capa delgada de precios/roles/grupos. NO es disolución total ni una unificación solo-UI.

## Scope

### In Scope
- **UI jerárquica de familias en `catalogo_core`**: reemplazar/extendes `VentasFamilias` con árbol madre/hija (capítulo, nivel, orden, activa), reutilizando la jerarquía ya presente en `model/core/familia.php` (`madre`, `nivel`, `hijas()`, `aux_all()`).
- **Estructura por lista de precios**: adaptar el modelo `tarif_tarifa_familia` (codtarifa→codlista, madre, capitulo, nivel, en_catalogo, en_tarifa, activa, orden) como tabla de extensión sobre familias, apuntando a `catalogo_listas_precio` (ya instalada por multitarifa), sin tocar la tabla base `familias` (patrón extension-table, mismo que `tarif_familia_ext`).
- **Asignación de artículos a familia por lista**: adaptar `tarif_tarifa_articulo` como tabla de extensión canónica `catalogo_*` referenciando artículos y listas.
- **Vistas de catálogo**: absorber la vista de catálogo por familia/lista que hoy renderizan los hooks congelados de tarifario en páginas de catalogo_core.
- **Migración de datos no destructiva**: siguiendo el precedente `CatalogLegacyTableMigration` (renombró 5 tablas `tarif_*` a `catalogo_*`), migrar `tarif_tarifa_familia` → tabla `catalogo_*` canónica y `tarif_tarifa_articulo` → tabla `catalogo_*` canónica, con orden FK correcto (familias y listas primero).
- **Compatibilidad de accesos**: mantener nombres de página/controladores existentes de `VentasFamilias` y derivados cuando sea posible para preservar filas `fs_access`; cualquier rename obligatorio incluye paso de migración de `fs_access`.
- **Traducciones**: claves con prefijo plugin en `plugins/catalogo_core/translations/*.yaml` (tarifario no tiene traducciones; no se copian textos sin prefijo).
- **Compat de hooks**: los 4 view hooks que `tarifario` registra via `ViewHookRegistry` en páginas de `catalogo_core` deben seguir resolviendo sin romper `HookRegistrationTest` (los hooks pasan a apuntar a la UI absorbida o se declaran obsoletos con documento de deprecación — decisión en fase spec).

### Out of Scope / Non-Goals
- NO es disolución total de `tarifario`: el plugin conserva precios por artículo/lista, roles, grupos, historial de precios y sus ~14 controladores `tarif_*` restantes.
- NO se migra la lógica de precios (por-artículo-por-lista, historial) — eso queda en `tarifario`.
- NO se toca la tabla base `familias` ni ningún archivo del core (`base/`, `src/`, `model/`, `controller/` raíz).
- NO se agregan `require` de `catalogo_core` hacia `tarifario` (ni viceversa más allá del estado actual): la integración sigue siendo event/hook-based (`ArticlePermissionFilterEvent`, `ViewHookRegistry`).
- NO se implementan las tareas de deprecación de `tarifario` en este change — se listan como sección de follow-up referenciado.
- NO se eliminan las tres coexistencias de precio: legacy `tarifa` (tabla `tarifas`) y `tarif_tarifas` quedan intocados; este change no consolida conceptos de precio, solo superficie de catálogo.

## Capabilities

### New Capabilities
- `familias-jerarquia-catalogo`: gestión jerárquica de familias (árbol madre/hija, capítulos, niveles) con estructura por lista de precios vía tablas de extensión, y asignación de artículos a familia por lista.

### Modified Capabilities
- `multi-tariff-pricing`: la asignación de estructura de familias por lista pasa a tablas canónicas `catalogo_*`; las listas (`catalogo_listas_precio`) permanecen como fuente de verdad de tarifas.

## Approach

1. **Extension-table pattern, no base-table changes**: nuevas tablas `catalogo_familia_estructura` (equivalente canónico de `tarif_tarifa_familia`, FK a `familias.codfamilia` + `catalogo_listas_precio.codlista`) y `catalogo_articulo_familia_estructura` (equivalente de `tarif_tarifa_articulo`). Migración de datos con orden FK: familias → listas → estructura → artículos.
2. **UI en `Controller/` PSR-4** con wrappers legacy si se requiere; la vista de árbol reutiliza los datos jerárquicos ya provistos por `familia::hijas()`/`aux_all()`.
3. **Preservación de `fs_access`**: el change prefiere extender `VentasFamilias` (mismo nombre de página) sobre crear páginas nuevas; cualquier página nueva se registra con `menu => 'ventas'` consistente. Si un rename es inevitable, tarea de migración de `fs_access` explícita.
4. **Integración con tarifario por eventos/hooks**: sin `require`. Los hooks congelados de `ViewHookRegistry` se mantienen funcionales (regresión cubierta por `HookRegistrationTest`); la delegación a datos de tarifario ocurre vía `ArticlePermissionFilterEvent` y lecturas de tablas, no por acoplamiento de código.
5. **TDD estricto** (strict_tdd en config.yaml): tests primero para migración de tablas, árbol jerárquico, y preservación de accesos.

## Affected Areas

| Area | Impact | Description |
|------|--------|-------------|
| `model/table/catalogo_familia_estructura.xml` | New | Estructura jerárquica por lista (ex `tarif_tarifa_familia`) |
| `model/table/catalogo_articulo_familia_estructura.xml` | New | Asignación artículo-familia por lista (ex `tarif_tarifa_articulo`) |
| `model/core/catalogo_familia_estructura.php`, `catalogo_articulo_familia_estructura.php` | New | Modelos con test/save/delete/exists |
| `Services/` (migración de tablas) | New | Migrador no destructivo estilo `CatalogLegacyTableMigration` |
| `Controller/` + `view/` | Modified/New | UI de árbol de familias, catálogo por lista |
| `model/core/familia.php` | Unchanged (o lectura only) | La jerarquía ya existe; no se modifica |
| `Init.php` | Modified | Registro de migración (si aplica) |
| `translations/*.yaml` | Modified | Claves con prefijo para nueva UI |
| `tests/` | New/Modified | Migración, árbol, fs_access, hooks |
| `plugins/tarifario/*` | Referenced only | Follow-up de deprecación, NO implementado aquí |

## Risks

| Risk | Likelihood | Impact | Mitigation |
|------|------------|--------|------------|
| Migración de datos rompe FK (orden incorrecto) | Med | High | Orden explícito: familias → `catalogo_listas_precio` → estructura → artículos; transacción + rollback; tests de migración |
| Renames rompen `fs_access` de usuarios | Med | Med | Preferir extender páginas existentes; si renombra, paso de migración `fs_access` con test |
| Colisión de nombres `tarif_grupo_*` vs `catalogo_grupo_*` | Low | Low | Este change no toca grupos; tablas nuevas usan nombres inequívocos `catalogo_familia_estructura` |
| Dirección de dependencia invertida (catalogo_core → tarifario) | Low | High | Prohibido por diseño: solo eventos/hooks; verificación en review que no haya `require` ni `require_once` cruzado |
| Hooks congelados dejan de resolver tras absorber UI | Med | Med | `HookRegistrationTest` como regresión; decision documentada en spec |
| Traducciones sin prefijo colisionan | Low | Low | Prefijo plugin obligatorio en toda clave nueva |
| Test churn en tarifario (40+ tests) | Med | Low | Este change no modifica tarifario; solo documenta follow-up |

## Rollback Plan

Plugin-local y aditivo: las tablas nuevas pueden dropearse; las tablas `tarif_*` originales NO se eliminan (migración copia, como el precedente). Rollback = restaurar `_back` del plugin o revertir el commit. La UI anterior de `VentasFamilias` se recupera con el revert.

## Dependencies

- `catalogo_listas_precio` ya instalada (change `multitarifa`) — prerequisito FK.
- `familias.madre`/`nivel` presentes en la capa de datos — sin cambio de esquema base.
- No hay nuevas dependencias Composer; no hace falta commit de `vendor/`.

## Follow-up Referenciado (tarifario — NO en este change)

- Deprecar/remover controladores y vistas de familia de `tarifario` (`tarif_familias*`, vistas de árbol) una vez la UI de `catalogo_core` esté validada.
- Consolidar (o documentar) la triple coexistencia de precios (`tarifa` legacy, `catalogo_listas_precio`, `tarif_tarifas`) en un change posterior.
- Retirar hooks congelados ya absorbidos, con período de gracia.

## Success Criteria

- [ ] La UI de familias en `catalogo_core` muestra y edita el árbol jerárquico (madre/hija, capítulo, nivel) sin modificar la tabla `familias`.
- [ ] La estructura de familias y la asignación de artículos funcionan por lista de precios (`catalogo_listas_precio`) vía tablas de extensión `catalogo_*`.
- [ ] La migración de datos desde `tarif_tarifa_familia`/`tarif_tarifa_articulo` es idempotente, no destructiva, y respeta el orden FK.
- [ ] Ningún usuario pierde accesos: páginas existentes conservan nombre, o hay migración de `fs_access` probada.
- [ ] `HookRegistrationTest` y suite de `catalogo_core` pasan: `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`.
- [ ] `catalogo_core` sigue sin `require` (dep-free) y sin referencias estáticas a código de `tarifario`.
