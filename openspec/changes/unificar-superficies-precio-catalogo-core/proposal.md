# Proposal: unificar-superficies-precio-catalogo-core

## Intent

`catalogo_core` ya absorbió las superficies de catálogo que vivían en `tarifario`
(`tarif_tarifas`, familias, opcionales, ficha por tarifa, características), pero la
**actualización masiva de precios** sigue existiendo dos veces, con dos motores, dos
páginas y dos storages. El operador ve dos entradas de menú para la misma tarea
("Actualizar precios" en `catalogo_core`, "Actualizar Precios" en `tarifario`) y cada
una cubre un subconjunto distinto del problema.

La causa raíz no es cosmética: hay **dos storages paralelos de precio**
(`catalogo_articulo_precios` + `catalogo_listas_precio` frente a
`tarif_articulo_precios` + `tarif_tarifas`) y el puente entre ambos es parcial:
`CatalogLegacyTableMigration::syncMissingPriceLists()` crea las entidades de lista
a partir de las tarifas, pero **nunca** copia `tarif_articulo_precios` a
`catalogo_articulo_precios`. Mientras la UI de precios siga duplicada, cada
escritura nueva puede caer en el storage equivocado, y cualquier redirect de
tarifa a lista leería un precio base que no es el que el operador editó.

**Decisión de ownership**: `catalogo_core` es dueño de *toda* superficie de precio
(página, motor, storage canónico, presentación, hooks). `tarifario` conserva solo
**política de tarifas** (roles/grupos, historial, catálogo por tarifa,
configurador de opcionales) y contribuye extensiones, no páginas.

Este change cierra la última superficie duplicada de precio sin fusionar storages ni
migrar datos.

## Evidence (verified inventory)

| Superficie | Ubicación actual | Tamaño | Estado |
|---|---|---|---|
| Motor de actualización masiva | `catalogo_core/Services/CatalogoPriceUpdateService.php` + `CatalogoPriceResolver.php` | 300 + 115 | Solo artículos y listas (`codlista`) |
| Página de actualización masiva (core) | `controller/catalogo_actualizar_precios.php` + `view/catalogo_actualizar_precios.html.twig` | 219 + 130 | Menú "Actualizar precios", grupo `ventas` |
| Página de actualización masiva (consumer) | `tarifario/controller/tarif_actualizar_precios.php` + `View/tarif_actualizar_precios.html.twig` | 506 + 347 | Menú "Actualizar Precios", grupo `tarifario`; artículos + opcionales, filtro por grupo de rol, paginación propia |
| Colección de precios A | `catalogo_core/controller/tarif_tarifas.php` + `View/tarif_tarifas.html.twig` | 813 + 553 | Menú "Tarifas" |
| Colección de precios B | `catalogo_core/controller/ventas_listas_precio.php` + `view/ventas_listas_precio.html.twig` | 298 + 155 | Menú "Listas de precio" |
| Vista muerta | `tarifario/View/tarif_articulo.html.twig` | 224 | Ningún controlador la renderiza; no existe página `tarif_articulo` |

**Verificado, y relevante para el diseño**: la página legacy de `tarifario` ya escribe
los **opcionales** en `catalogo_opcional_precios` usando `codlista = codtarifa`, pero
los **artículos** en `tarif_articulo_precios`. Es decir, el storage ya es mixto y la
propia página legacy participa del storage canónico para un scope. Eso confirma que
generalizar el motor por `scope × target` es el camino correcto y que **no** hace falta
fusionar tablas.

**Verificado**: `ventas_listas_precio` queda inerte cuando hay tarifas activas y
`tarif_tarifas` es la superficie vigente, o viceversa, según
`Services/PriceListAvailability::listUiEnabled()` (multi-tarifa activa y ninguna
tarifa activa). Ambas páginas son hoy de `catalogo_core`; la duplicación es de
presentación, no de ownership.

## Scope

### In Scope

- Regla de ownership de superficies de precio, formalizada en spec y protegida por
  test (F0, sin cambio funcional).
- Generalización del motor a `scope (articulos | opcionales) × target
  (lista | tarifa)` (F1), sin cambio funcional.
- Port del `scope = opcionales` al motor base (F2).
- Registro del `target = tarifa` desde `tarifario` + filtro por grupo de rol vía
  hook nuevo en la página base (F3), con test de paridad preview/apply contra la
  página legacy sobre el mismo fixture.
- Retiro de `tarif_actualizar_precios`: redirect a
  `catalogo_actualizar_precios`, borrado de controlador y vista, limpieza de
  fixtures de test y del menú (F4).
- Deduplicación de presentación: macros compartidos, `ventas_listas_precio` como
  vista de storage, borrado de `tarif_articulo.html.twig` (F5).
- Releases: `catalogo_core` minor por fase; `tarifario` 3.5.0 al retirar la página
  (F6). Sin cambios de core.

### Out of Scope

- **Fusionar los dos storages** (`catalogo_articulo_precios` /
  `tarif_articulo_precios`) o ejecutar cualquier migración de datos.
- **Fusionar `tarif_tarifas` y `ventas_listas_precio`** en una sola entidad o página.
- Cualquier cambio bajo `base/`, `src/`, `controller/` o `model/` de la raíz del
  repo. Este SDD vive completo en `plugins/catalogo_core/openspec/`.
- Renombrar cualquier `render_hook` congelado por R-TAR-HOOK-003
  (`ventas_articulo_*`, `ventas_opcional_*`).

## Ownership Rule

| Capa | Dueño | Contenido |
|---|---|---|
| Superficie de precio | `catalogo_core` | Página de actualización masiva, motor, storage canónico, presentación, hooks |
| Política de tarifas | `tarifario` | Asignación rol/grupo (`tarif_roles`, `tarif_grupo_*`), historial (`tarif_historial_precios`), catálogo por tarifa (`tarif_catalogo_view`), configurador de opcionales (`tarif_configurador_opcionales`) |

Dirección de dependencia: **consumer → `catalogo_core`**. `catalogo_core` declara
seams neutrales; `tarifario` se registra en ellos. Ningún archivo nuevo de
`catalogo_core` puede referenciar el plugin consumidor: el guardrail
`tests/CatalogoCoreHookMarkersTest.php` (`TARIFARIO_ALLOWLIST` congelada) debe
seguir fallando para un archivo nuevo que mencione `tarifario`.

## Approach

Solo se reutilizan mecanismos existentes: `ViewHookRegistry` + Twig `render_hook`,
registro de extensiones desde `Init::init()` (patrón
`ArticuloListActionRegistry::register()`), y `PriceListAvailability` como gate.

### Registry de targets (nuevo, neutral)

`Services/PriceUpdateTargetRegistry.php` con la misma forma que
`ArticuloListActionRegistry`: una interfaz de target que expone
`supports(string $target)`, `label()`, `preview(array $filters, float $pct)` y
`apply(array $rows, array $filters)`, más un registro estático con `register()`,
`reset()` y `dispatch()`. Un target encapsula **su** acceso al storage, de modo que
`target = lista` sigue leyendo/escribiendo `catalogo_articulo_precios` y
`target = tarifa` (registrado por `tarifario`) usa `tarif_articulo_precios` sin que
el motor conozca ninguna de las dos tablas.

El **scope** (`articulos | opcionales`) es ortogonal al target y vive en el motor
base: selecciona filas, lee precio efectivo y valida el formulario. Así F1 no cambia
comportamiento, F2 solo agrega el scope que hoy falta en el motor, y F3 solo suma un
target.

El filtro por grupo de rol entra por un **punto de hook nuevo** en la vista base
(propuesto: `catalogo_actualizar_precios_filters_after`), neutral — sin el token
`tarifa` en el nombre, conforme al patrón de R-TAR-HOOK-003. Los cuatro hooks
congelados no se tocan.

### Phases

| Fase | Entregable | Cambio funcional | Gate |
|---|---|---|---|
| **F0** | Spec de ownership + guardrail test (nuevo archivo sin token `tarifario`; allowlist intacta) | No | Suite verde, guardrail falla si un archivo nuevo menciona el consumer |
| **F1** | `CatalogoPriceUpdateService` generalizado a `scope × target`, con `target = lista` como único target y `scope = articulos` como único scope, más las correcciones obligatorias de `design.md` D11 (corte de filas del preview, `#[AdminOnly]` en la página base, orden determinista, reposteo de filtros en el apply) | Sí (correcciones) | Se preservan fórmula, filtros, claves de storage y mensajes de resumen; los tests de `batch-price-update` siguen verdes y las correcciones tienen test propio |
| **F2** | `scope = opcionales` dentro del motor base (selección de filas, precio efectivo, preview/apply) | Sí (nueva cobertura en la página base) | Tests de parity de cálculo con la lógica legacy de opcionales |
| **F3** | `tarifario` registra `target = tarifa` en `Init::init()` y aporta el filtro de grupo por hook; paridad preview/apply contra `tarif_actualizar_precios` sobre el mismo fixture | Sí | Test de paridad byte a byte en filas y totales; ambos caminos escriben el mismo storage |
| **F4** | Retiro: redirect `tarif_actualizar_precios → catalogo_actualizar_precios`, borrado de controlador y vista, limpieza de `REPOINTED_LINKS` / fixtures, baja de entrada de menú | Sí (retiro) | Ningún test referencia el archivo borrado; redirect conserva `codtarifa` como `codlista` solo si el espejo existe, si no abre la página sin lista preseleccionada con aviso |
| **F5** | Dedup de presentación: macros compartidos, `ventas_listas_precio` como vista de storage, borrado de `tarif_articulo.html.twig` | Sí (visual) | Suite verde; cero referencias a la vista muerta |
| **F6** | Release: `catalogo_core` minor por fase; `tarifario` 3.5.0 al retirar la página | — | Tags independientes; sin release de core |

**Restricción de F4 que evita un bug de datos**: el espejo de
`CatalogLegacyTableMigration` es parcial (solo tarifa por defecto). El redirect NO
puede asumir que todo `codtarifa` tiene `codlista`; cuando no exista, debe abrir la
página base sin lista preseleccionada y avisar, nunca aplicar sobre una lista
arbitraria.

## Capabilities

### New Capabilities

- `price-surface-ownership`: regla de ownership de superficies de precio
  (`catalogo_core` dueño, `tarifario` extensiones), con guardrail ejecutable que
  impide nuevas referencias del owner al consumer.

### Modified Capabilities

- `batch-price-update`: R-BU-001/R-BU-002 se extienden de "listas" a
  `scope × target`, conservando preview/apply, fórmula y CSRF.
- `batch-price-update`: `R-BU-002` incorpora filtros adicionales aportados por contribuyentes registrados, sin que el motor base conozca claves del consumidor.
- `catalogo-render-hooks`: se agrega un punto de hook aditivo en la página de
  actualización de precios; los nombres congelados por R-TAR-HOOK-003 no cambian.
- `tarifario/catalogo-integration` (spec del consumer): `tarifario` registra un
  target y aporta filtro, en lugar de publicar su propia página.

## Affected Areas

| Area | Impact | Description |
|---|---|---|
| `Services/PriceUpdateTargetRegistry.php` | New | Interfaz + registro estático neutral de targets |
| `Services/CatalogoPriceUpdateService.php` | Modified | Seam `scope × target`; storage delegado al target |
| `Services/CatalogoPriceResolver.php` | Modified | Lectura de precio efectivo por scope |
| `controller/catalogo_actualizar_precios.php` | Modified | Selección de target/scope, hook de filtros |
| `view/catalogo_actualizar_precios.html.twig` | Modified | Selector de target/scope, macro compartido, `render_hook` de filtros |
| `plugins/tarifario/Init.php` | Modified | Registro del target `tarifa` en `init()` |
| `plugins/tarifario/Services/` (target) | New | Implementación del target sobre `tarif_articulo_precios` |
| `plugins/tarifario/controller/tarif_actualizar_precios.php` + `View/...` | Removed | Retiro con redirect (F4) |
| `plugins/tarifario/View/tarif_articulo.html.twig` | Removed | Vista muerta (F5) |
| `catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php` y fixtures de absorción | Modified | Quitar entradas apuntando a la vista retirada |
| `tests/CatalogoCoreHookMarkersTest.php` | Modified | Sólo si F0 agrega archivos; la allowlist no debe crecer para archivos nuevos |

## Blast Radius

Motor de precio, página base, registro de extensión del consumer y tres fixtures de
test de `catalogo_core` que hoy exigen que
`plugins/tarifario/View/tarif_actualizar_precios.html.twig` **exista**
(`ArticuloListaCanonicaOwnershipTest`, `VentasArticuloArticleEditAbsorptionTest`,
`CatalogoOpcionalesUnifiedControllerTest`). Ese acoplamiento test-only es el punto
de fricción real de F4 y debe corregirse en el mismo slice que borra la vista.

Rollback por fase: F0–F3 son aditivas y se revierten con revert de commit; F4–F5
requieren restaurar el `_back` del plugin o revertir el commit, más el revert del
redirect.

## Risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Redirect de F4 apunta a una lista equivocada | High | High | No mapear `codtarifa` a `codlista` en ningún caso: se preserva `target=tarifa&codtarifa`. Sin target disponible, abrir sin preselección + aviso (R-BU-008) |
| La generalización F1 cambia comportamiento sin querer | Med | High | F1 es no funcional: los tests de `batch-price-update` no cambian de expectativas |
| Divergencia de cálculo entre la página base y la legacy | Med | High | Test de paridad sobre el mismo fixture (filas, totales, storage escrito) en F3 |
| Archivo nuevo de `catalogo_core` menciona `tarifario` y rompe el guardrail | Med | Med | Vocabulario neutral ("consumer", "target"); F0 deja el guardrail activo desde el primer commit |
| Fixtures de `catalogo_core` siguen exigiendo la vista borrada | High | Med | Limpieza explícita en F4, mismo slice que el borrado |
| `ventas_listas_precio` sigue inerte y la dedup F5 no aporta valor real | Low | Low | F5 es visual; no se toca la semántica de `PriceListAvailability` |
| Churn de tests en dos suites con releases independientes | Med | Low | `tarifario` 3.5.0 solo en F4; `catalogo_core` minor por fase |

## Dependencies

- `catalogo_core` 1.15.2 (baseline actual) y `tarifario` 3.4.2 (baseline actual).
- `PriceListAvailability` ya entregado en 1.15.2 — no se modifica su semántica.
- Seams existentes: `ViewHookRegistry`, `ArticuloListActionRegistry` (como patrón y
  como precedente de registro desde `Init::init()`).
- Sin nuevas dependencias Composer; no hace falta commit de `vendor/`.

## Constraints

- **strict_tdd: true**. Cada fase escribe tests primero.
- Baseline verde antes de empezar: `ddev exec php vendor/bin/phpunit -c
  plugins/catalogo_core/phpunit.xml` → **1593 tests, 6503 assertions** (2 warnings,
  1 skipped); `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml` →
  **278 tests, 1174 assertions** (2 skipped). Ambos verificados en esta propuesta.
- `delivery_strategy: auto-chain`; presupuesto de review **800 líneas cambiadas por
  slice** (política de este SDD, por encima del default de 400 de `chained-pr`). F4 y
  F5 probablemente requieran slices separados por archivo borrado + fixtures.
- Los dos plugins versionan y tagean de forma independiente. Sin release de core:
  no se toca `base/`, `src/`, `controller/` ni `model/` raíz.
- Ningún archivo nuevo de `catalogo_core` puede nombrar el consumer; la allowlist de
  `CatalogoCoreHookMarkersTest` no se amplía para código nuevo.

## Success Criteria

- [ ] Existe una única página de actualización masiva (`catalogo_actualizar_precios`)
      que cubre `articulos` y `opcionales`, con `target = lista` y `target = tarifa`.
- [ ] El test de paridad de F3 demuestra filas, totales y storage idénticos entre la
      página base y la legacy sobre el mismo fixture.
- [ ] `tarif_actualizar_precios` deja de existir como página; su URL redirige y no
      aplica sobre una lista no espejada.
- [ ] `catalogo_core` no contiene referencias nuevas al consumer; el guardrail
      sigue fallando para archivos no allowlisted.
- [ ] Los cuatro hooks congelados de R-TAR-HOOK-003 conservan nombre y semántica.
- [ ] Sin migración de datos ni cambio de esquema: los dos storages quedan como están.
- [ ] Ambas suites verdes al cierre: `catalogo_core` ≥ 1593 tests y `tarifario`
      ≥ 278 tests, sin nuevas omisiones.
