# Tasks: unificar-superficies-precio-catalogo-core

> **Plugin-local SDD.** Change root:
> `plugins/catalogo_core/openspec/changes/unificar-superficies-precio-catalogo-core/`.
> El `openspec/` raíz del core no recibe ninguna entrada. `config.yaml` declara
> `ownership: plugin-local` y `strict_tdd: true`.
>
> **Fuentes (no re-derivar):** `proposal.md`; `design.md` (D1–D12, §7 estrategia de
> pruebas, §7.1 fixture de paridad, §12 slices, §13 releases, §14 contradicciones,
> §15 decisiones abiertas); deltas `specs/price-surface-ownership/spec.md`
> (R-PSO-001..007), `specs/batch-price-update/spec.md`
> (R-BU-001..004, R-BU-006..008; **R-BU-005 sin cambios**),
> `specs/catalogo-render-hooks/spec.md` (R-CRH-001..002).
>
> **Estado:** en ejecución. WU-0.1 (PR-1a), WU-1.1..1.4 (PR-1b) y WU-1.5 (PR-2), WU-2.1 y WU-2.2 (PR-3) completadas; el resto pendiente.
> Evidencia y desviaciones: `apply-progress.md`.
>
> **Versiones de partida:** `catalogo_core` 1.15.2, `tarifario` 3.4.2.
> **Baselines verificados:** `catalogo_core` 1593 tests / 6503 assertions
> (2 warnings, 1 skipped); `tarifario` 278 tests / 1174 assertions (2 skipped).
> Cualquier slice cierra sin bajar de esos pisos.

## Review Workload Forecast

```text
Decision needed before apply: No (delivery_strategy: auto-chain)
Chained PRs recommended: Yes
800-line budget risk: High (F1 y F3 se parten en dos slices; F4 es intensivo en borrados)
```

El presupuesto de este SDD es **800 líneas cambiadas por slice** (adición + borrado),
por encima del default de 400 de `chained-pr`. La estimación por slice está en
`## Plan de slices`.

## Reglas de ejecución (obligatorias)

- **Unidades de trabajo, no temas.** Cada unidad nombra los archivos que toca, los
  requisitos que satisface y su comando de verificación. La unidad, no el archivo, es
  la unidad de commit/review.
- **Runner exacto de `catalogo_core`** (`RUN-CC`):
  `ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml`
- **Runner exacto de `tarifario`** (`RUN-TAR`):
  `ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml`
- **Forma abreviada en tablas.** `RUN-CC <ruta>` significa RUN-CC con la ruta
  relativa al plugin agregada al final (filtro de un archivo). `RUN-TAR <ruta>`,
  idem.
- **TDD estricto.** Toda unidad que cambia comportamiento escribe primero un test
  RED **con nombre propio**, lo ejecuta y registra la evidencia de fallo (fallo por
  contrato ausente, nunca por error de setup), y recién entonces implementa GREEN.
- **Evidencia RED obligatoria.** Para una unidad de guardrail cuya aserción ya es
  verdadera de partida (F0), el RED se demuestra con una **sonda de mutación**:
  introducir temporalmente la violación, ver el test fallar, revertir con
  `git checkout -- <archivo>`. Registrar el resultado de las tres corridas.
- **Análisis estático.** `catalogo_core` está en el gate de plugins:
  `ddev exec composer phpstan` y `ddev exec composer phpstan:plugins`.
  `tarifario` **no** está en `tools/phpstan-plugins.php`; para sus archivos usar
  `ddev exec php -l <archivo>`. No se edita la lista de plugins analizados.
- **Sin dependencias Composer nuevas.** No se toca `composer.json` ni
  `composer.lock` de ninguno de los dos plugins y **no se commitea `vendor/`** en
  este change.
- **Sin core.** Ningún archivo bajo `base/`, `src/`, `controller/`, `model/` ni
  `VERSION` de la raíz se modifica.

## Índice de unidades de trabajo

| ID | Fase | Archivos tocados | Acción | Reqs | RED (test nombrado) | GREEN | Verificación |
|---|---|---|---|---|---|---|---|
| WU-0.1 | F0 | `tests/PriceSurfaceOwnershipTest.php` (nuevo) | Guardrail de ownership | R-PSO-001, R-PSO-003, R-PSO-004, R-CRH-002 | `testSeamFilesContainNoConsumerTableTokens`, `testAllowlistIsFrozenAtFiftyTwoEntries`, `testHostViewsKeepOnlyTheFourFrozenHooks` | Test-only; sin producción | RUN-CC `tests/PriceSurfaceOwnershipTest.php` |
| WU-1.1 | F1 | `Services/PriceUpdateTargetRegistry.php` (nuevo), `tests/Services/PriceUpdateTargetRegistryTest.php` (nuevo) | Registro de targets + contratos `PriceBookInterface` / `PriceUpdateTargetInterface` | R-BU-002, R-PSO-002 | `testUnknownCodeResolvesToNull`, `testDuplicateRegistrationKeepsFirst`, `testRegisterDefaultsIsIdempotent`, `testDefaultCodePrefersLista` | Registro estático `register/reset/registerDefaults/resolve/available/defaultCode` | RUN-CC `tests/Services/PriceUpdateTargetRegistryTest.php` |
| WU-1.2 | F1 | `Services/PriceUpdateScopes.php` (nuevo), `Services/CanonicalPriceBooks.php` (nuevo), `tests/Services/ArticulosPriceUpdateScopeTest.php`, `tests/Services/CanonicalArticlePriceBookTest.php` (nuevos) | Scope `articulos` + libro canónico de artículos | R-BU-001, R-BU-002, R-BU-003, R-BU-007, R-PSO-001 | `testFamilyLargerThanItemLimitIsFullyPaginated`, `testRowsAreOrderedByReferenciaAcrossFamilies`, `testCanonicalRowWinsAndDefaultListFallsBackToPvp` | Paginación hasta agotar familia, orden global por `referencia`, lectura batch canónica | RUN-CC ambos archivos |
| WU-1.3 | F1 | `Services/ListaPriceUpdateTarget.php` (nuevo), `tests/Services/PriceListAvailabilityTest.php` (modificado) | Target base `lista` | R-BU-002, R-BU-003, R-BU-007 | `testListTargetAvailabilityUsesListUiEnabled`, `testListTargetDeclaresCodlistaKeyAndActiveOptions` | Target `lista` con `bookFor` canónico | RUN-CC `tests/Services/PriceListAvailabilityTest.php` |
| WU-1.4 | F1 | `Services/CatalogoPriceUpdateService.php` (modificado), `Init.php` (modificado), `tests/Services/CatalogoPriceUpdateServiceTest.php` (migrado) | Motor `scope × target` + `registerDefaults()` | R-BU-001, R-BU-002, R-BU-003, R-BU-005 (regresión), R-BU-007 | `testCompositionPrefersCanonicalBook`, `testApplyPersistsExactlyPreviewRows`, `testComputePriceIsUnchanged` | Motor recibe colaboradores resueltos; delegados actuales conservados | RUN-CC `tests/Services/CatalogoPriceUpdateServiceTest.php` + RUN-TAR |
| WU-1.5 | F1 | `controller/catalogo_actualizar_precios.php`, `view/catalogo_actualizar_precios.html.twig`, `tests/CatalogoActualizarPreciosControllerTest.php` | Selección target/scope, `#[AdminOnly]`, reposteo, sustantivos neutrales | R-BU-002, R-BU-004, R-BU-005, R-PSO-005 | `testControllerDeclaresAdminOnlyAttribute`, `testApplyFormRepostsEveryFilterAndPercent`, `testEmptyMatchMessageIsScopeNeutral` | Controlador resuelve y falla cerrado; vista con selectores y `hidden` | RUN-CC `tests/CatalogoActualizarPreciosControllerTest.php` + RUN-TAR |
| WU-2.1 | F2 | `Services/PriceUpdateScopes.php` (extendido), `Services/CanonicalPriceBooks.php` (extendido), `tests/Services/OpcionalesPriceUpdateScopeTest.php`, `tests/Services/CanonicalOpcionalPriceBookTest.php` (nuevos) | Scope `opcionales` + libro canónico de opcionales | R-BU-006, R-BU-001, R-BU-002, R-BU-003 | `testOptionalPaginationBeyondItemLimit`, `testRowsAreOrderedByCodigo`, `testOptionalBookOmitsMissingRows` | Matching por `catalogo_opcional_familias`, orden por `codigo`, libro `(id_opcional, codlista)` | RUN-CC ambos archivos |
| WU-2.2 | F2 | `plugins/tarifario/tests/Support/{PriceUpdateParityFixture,LegacyPriceUpdateFakeDb,LegacyPriceUpdateDriver}.php`, `plugins/tarifario/tests/PriceUpdateParityTest.php` (nuevos) | Arnes de paridad sin BD | R-BU-006, R-BU-007, R-BU-001 | `testOptionalPreviewParityWithLegacyPage`, `testOptionalApplyWritesSameCanonicalRows` | Fixture + `fs_db2` falso + driver por reflexión | RUN-TAR `tests/PriceUpdateParityTest.php` + RUN-CC |
| WU-3.1 | F3 | `Services/PriceUpdateFilterRegistry.php` (nuevo), `view/catalogo_actualizar_precios.html.twig`, `controller/catalogo_actualizar_precios.php`, `tests/Services/PriceUpdateFilterRegistryTest.php`, `tests/CatalogoCoreHookMarkersTest.php` | Registro de filtros + hook `catalogo_actualizar_precios_filters_after` | R-CRH-001, R-BU-002 | `testNewHookMarkerIsNeutralZeroByteForm`, `testFilterKeysAreRepostedAsHiddenInputs`, `testRegistryKeysViewContextAndNarrow` | Marcador aditivo, contexto del hook y reposteo acotado a `keys()` | RUN-CC `tests/CatalogoCoreHookMarkersTest.php` + `tests/Services/PriceUpdateFilterRegistryTest.php` |
| WU-3.2 | F3 | `plugins/tarifario/Services/{TarifaPriceUpdateTarget,TarifaArticlePriceBook,TarifaRoleGroupPriceFilter}.php`, `plugins/tarifario/View/Hooks/catalogo_actualizar_precios_filters_after.html.twig`, `plugins/tarifario/Init.php`, tests de `tarifario` | Target `tarifa` + filtro de grupo de rol + registro | R-PSO-002, R-BU-002, R-BU-007, R-CRH-001 | `testBookForArticulosReadsTarifArticuloPrecios`, `testFilterSupportsOnlyArticulos`, `testInitRegistersTargetFilterAndHookOnce` | Target/libro/filtro y registro idempotente en `init()` | RUN-TAR `tests/Services/TarifaPriceUpdateTargetTest.php` + RUN-TAR completo |
| WU-3.3 | F3 | `plugins/tarifario/tests/PriceUpdateParityTest.php` (ampliado), `plugins/tarifario/tests/Fixtures/price_update_parity_legacy.json` (generado) | Paridad de artículos + golden | R-BU-007, R-BU-001, R-BU-003 | `testArticlePreviewParityWithLegacyPage`, `testArticleParityWhenCanonicalAndTariffRowsCoexist`, `testPreviewDoesNotWriteInEitherPath` | Driver de artículos + captura del golden | RUN-TAR `tests/PriceUpdateParityTest.php` + RUN-CC |
| WU-4.1 | F4 | `controller/catalogo_actualizar_precios.php`, `tests/CatalogoActualizarPreciosControllerTest.php` | Aviso `seleccion_requerida` (lado core) | R-BU-008, R-BU-002 | `testSelectionRequiredNoticeIsWhitelisted`, `testNoticeDoesNotPreselectAnyTarget` | Whitelist de avisos, mensaje neutral | RUN-CC `tests/CatalogoActualizarPreciosControllerTest.php` |
| WU-4.2 | F4 | `plugins/tarifario/controller/tarif_actualizar_precios.php` (reescrito), `plugins/tarifario/tests/LegacyRedirectShimTest.php` (nuevo) | Shim de redirect | R-BU-008, R-PSO-005, R-PSO-006, R-PSO-002 | `testAvailableTariffTargetPreservesTargetAndCodtarifa`, `testNeverEmitsCodlistaDerivedFromCodtarifa`, `testLegacyControllerHasNoPreviewOrApply` | Shim ~55 líneas, `template = FALSE`, menú oculto | RUN-TAR `tests/LegacyRedirectShimTest.php` |
| WU-4.3 | F4 | `plugins/tarifario/View/tarif_actualizar_precios.html.twig` (borrado), `tests/ArticuloListaCanonicaOwnershipTest.php`, `tests/CatalogoOpcionalesUnifiedControllerTest.php`, `tests/Controller/VentasArticuloArticleEditAbsorptionTest.php` | Borrado de vista + limpieza de fixtures (atómico con WU-4.2) | R-PSO-006, R-PSO-001 | `testRetiredPriceUpdateViewIsAbsent` + fixtures que dejan de exigir la vista | Vista borrada; entradas eliminadas en el mismo commit | RUN-CC + RUN-TAR + grep de referencias |
| WU-4.4 | F4 | `plugins/tarifario/Services/LegacyPricePageRetirement.php` (nuevo), `plugins/tarifario/Init.php`, `plugins/tarifario/tests/LegacyPricePageRetirementTest.php` (nuevo) | Retiro idempotente de la fila `fs_pages` | R-PSO-005 | `testDeletesPreexistingPageRowAndClearsCache`, `testDoesNotTouchRolesAccess`, `testUpgradeInvokesRetirementNotInit` | `fs_page::delete()` + limpieza de `m_fs_page_all` desde `upgrade()` | RUN-TAR `tests/LegacyPricePageRetirementTest.php` |
| WU-4.5 | F4 | `plugins/tarifario/tests/PriceUpdateParityTest.php`, `plugins/tarifario/tests/Support/` (borrados parciales), `tests/Fixtures/price_update_parity_legacy.json` (conservado) | Replay del golden tras el retiro | R-BU-007, R-BU-006 | `testLegacyDriverIsGone`, `testGoldenReplayMatchesLegacyOutput` | Driver legacy eliminado; replay contra el golden | RUN-TAR `tests/PriceUpdateParityTest.php` + RUN-CC |
| WU-5.1 | F5a | `plugins/tarifario/View/tarif_articulo.html.twig` (borrado), `tests/ArticuloListaCanonicaOwnershipTest.php`, `tests/PriceSurfaceOwnershipTest.php` | Borrado de la vista muerta | R-PSO-006, R-PSO-001 | `testDeadArticleViewIsAbsent` | Vista borrada + fixture sin la entrada | RUN-CC + RUN-TAR + grep |
| WU-5.2 | F5b (diferible) | A determinar en el gate: `view/ventas_listas_precio.html.twig`, `controller/ventas_listas_precio.php` y el macro que se extraiga | Dedup de presentación (opcional) | Sin requisito en los deltas; motivación R-PSO-001, design §15.3 | A definir en el gate (`testPriceListPresentationUsesSharedMacro`) | Extracción de macro sin cambio semántico | RUN-CC + smoke visual |

**Total: 18 unidades de trabajo** (WU-0.1, WU-1.1..1.5, WU-2.1..2.2, WU-3.1..3.3,
WU-4.1..4.5, WU-5.1..5.2). Los gates `GATE-3` y `GATE-5b` no cuentan como unidad.

## Trazabilidad requisito → unidad → test

| Requisito | Unidades | Test principal |
|---|---|---|
| R-PSO-001 | WU-0.1, 1.2, 1.3, 3.2, 4.3, 5.1, 5.2 | `PriceSurfaceOwnershipTest` |
| R-PSO-002 | WU-3.2, 4.2 | `PriceUpdateTargetRegistrationTest`, `LegacyRedirectShimTest` |
| R-PSO-003 | WU-0.1 + guardrails G-1/G-2 | `PriceSurfaceOwnershipTest::testAllowlistIsFrozenAtFiftyTwoEntries` |
| R-PSO-004 | WU-0.1 + guardrail G-3 | `Integration/CatalogoCoreHookMarkersTest` (regresión) |
| R-PSO-005 | WU-1.5, 4.2, 4.4 | `LegacyRedirectShimTest`, `LegacyPricePageRetirementTest` |
| R-PSO-006 | WU-4.2, 4.3, 4.5, 5.1 | `testRetiredPriceUpdateViewIsAbsent`, `testDeadArticleViewIsAbsent` |
| R-PSO-007 | `GATE-3` + guardrail G-5 | Inspección de rutas del change |
| R-BU-001 | WU-1.2, 1.4, 2.2, 3.3 | `CatalogoPriceUpdateServiceTest`, `PriceUpdateParityTest` |
| R-BU-002 | WU-1.1, 1.2, 1.4, 1.5, 3.1, 3.2 | `PriceUpdateTargetRegistryTest`, controller test |
| R-BU-003 | WU-1.2, 1.4, 3.3 | `testComputePriceIsUnchanged` |
| R-BU-004 | WU-1.5 | `testControllerDeclaresAdminOnlyAttribute` |
| R-BU-005 (sin cambios) | WU-1.4, 1.5, 4.2 | `summarizeApply()` regresión |
| R-BU-006 | WU-2.1, 2.2, 4.5 | `CanonicalOpcionalPriceBookTest`, paridad de opcionales |
| R-BU-007 | WU-1.2, 2.2, 3.3, 4.5 | `PriceUpdateParityTest` |
| R-BU-008 | WU-4.1, 4.2, 4.3 | `LegacyRedirectShimTest` |
| R-CRH-001 | WU-3.1, 3.2 | `CatalogoCoreHookMarkersTest` |
| R-CRH-002 | WU-0.1, 3.1 + guardrail G-3 | `Integration/CatalogoCoreHookMarkersTest` |

## Plan de slices

Estrategia `auto-chain`. Cada slice es independiente y verde por sí mismo; el orden
es el de `design.md` §12 y §13.

| PR | Unidades | Estimación (cambiadas) | Base | Gate de cierre |
|---|---|---|---|---|
| **PR-1a** | WU-0.1 | ~120–160 | `main` | Guardrail activo antes de cualquier archivo nuevo de core |
| **PR-1b** | WU-1.1, 1.2, 1.3, 1.4 | ~650–720 | PR-1a | RUN-CC verde; delegados públicos conservados (design §12) |
| **PR-2** | WU-1.5 | ~430–520 | PR-1b | RUN-CC + RUN-TAR verdes; `#[AdminOnly]` probado por reflexión |
| **PR-3** | WU-2.1, 2.2 | ~400–480 | PR-2 | Paridad de opcionales verde |
| **PR-4** | WU-3.1 | ~300–380 | PR-3 | Hook neutral, cero bytes sin registro |
| **PR-5** | WU-3.2, 3.3 | ~570–650 | PR-4 | `GATE-3` cerrado + paridad de artículos + golden capturado |
| **PR-6** | WU-4.1..4.5 (atómico, dos repos) | adiciones ~180–240; borrados ~750–850; **total ~930–1090** | PR-5 | `size:exception`; indivisible por R-PSO-006 |
| **PR-7** | WU-5.1 | ~240–280 | PR-6 | Cero referencias a la vista muerta |
| **PR-8** | WU-5.2 (opcional) | ~250–400 | PR-7 | `GATE-5b` decide ejecutar o diferir |

**Slice por encima de 800: PR-6 (F4).** Se marca explícitamente y se propone
`size:exception` con esta justificación: el diff está dominado por **borrados
obligatorios e indivisibles** (vista legacy de 347 líneas, controlador de 506 que
pasa a ~55, y las tres fixtures de absorción). Partirlo violaría la regla de
R-PSO-006 ("toda entrada que referencie la vista retirada se elimina en el mismo
slice que borra el archivo") y dejaría suites rojas entre slices. Medición
alternativa para el reviewer: **~200 líneas de adiciones autoradas** vs ~800 de
borrados.

**Reglas de no-merge (vinculantes):**

1. **PR-1b no puede mergear sin PR-1a.** El guardrail debe nacer antes que el
   primer archivo nuevo de `catalogo_core`.
2. **PR-2 no puede mergear sin PR-1b.** La UI nueva exige el seam `scope × target`.
3. **PR-3 no puede mergear sin PR-2.** El scope de opcionales se apoya en el motor
   ya migrado.
4. **PR-4 no puede mergear sin PR-3.** El hook vive en la vista ya generalizada.
5. **PR-5 no puede mergear sin PR-4 ni sin `GATE-3`.** El consumidor necesita
   `PriceUpdateFilterRegistry` (1.18.0) y su propio change de `tarifario`.
6. **PR-6 es atómico y no se parte.** WU-4.2 (shim), WU-4.3 (borrado + fixtures),
   WU-4.4 (fila `<fs_pages>`) y WU-4.5 (replay) viajan juntos, en dos commits de
   repo (`catalogo_core` + `tarifario`) que aterrizan consecutivos **sin deploy
   intermedio**. Además, PR-6 no puede mergear sin PR-5: sin el target `tarifa`
   registrado, el shim siempre cae al aviso y se pierde la intención del operador.
7. **PR-7 no puede mergear antes de PR-6**, porque ambos editan
   `tests/ArticuloListaCanonicaOwnershipTest.php`.
8. **PR-8 no se mezcla con ningún otro slice** y no bloquea releases.

## Fase F0 — Guardrail de ownership (sin cambio funcional)

**Gate de fase:** RUN-CC y RUN-TAR verdes con el test nuevo; `TARIFARIO_ALLOWLIST`
sin cambios; la sonda de mutación demuestra que el guardrail falla ante una
violación.

- [x] **WU-0.1 — Test de contrato de ownership.**
  Archivos: `plugins/catalogo_core/tests/PriceSurfaceOwnershipTest.php` (nuevo, solo
  test; el `tests/` del plugin queda excluido del escaneo por R-PSO-003, por eso el
  propio test puede contener el token del consumidor en sus fixtures).
  Requisitos: R-PSO-001, R-PSO-003, R-PSO-004, R-CRH-002.
  - [x] **RED (sonda de mutación 1):** escribir el test con
        `testSeamFilesContainNoConsumerTableTokens` (lista inicial `SEAM_FILES` =
        solo `Services/CatalogoPriceUpdateService.php`; se amplía en F1–F3 con cada
        archivo nuevo), `testAllowlistIsFrozenAtFiftyTwoEntries` (la allowlist real
        tiene **52 entradas**, verificado) y
        `testHostViewsKeepOnlyTheFourFrozenHooks`. Insertar temporalmente el token
        `tarif_grupo_articulos` como comentario en
        `Services/CatalogoPriceUpdateService.php`, correr el test, confirmar el
        fallo, revertir con
        `git checkout -- plugins/catalogo_core/Services/CatalogoPriceUpdateService.php`.
  - [x] **RED (sonda de mutación 2):** agregar temporalmente una entrada falsa a
        `TARIFARIO_ALLOWLIST`, confirmar que
        `testAllowlistIsFrozenAtFiftyTwoEntries` falla, revertir con
        `git checkout -- plugins/catalogo_core/tests/CatalogoCoreHookMarkersTest.php`.
  - [x] **GREEN:** revertidas ambas sondas, el test queda verde **sin tocar
        producción**. No se crea ningún archivo de producción en esta fase.
  - [x] **Verify:** RUN-CC `tests/PriceSurfaceOwnershipTest.php`; luego RUN-CC y
        RUN-TAR completos.
  - [x] **Rollback:** revertir el commit deja la suite como estaba; no hay estado
        persistido.
  - Notas: la allowlist de `tests/CatalogoCoreHookMarkersTest.php` es uno de los
        dos gemelos; solo ese archivo tiene allowlist (design C7). La lista
        `SEAM_FILES` se extiende exclusivamente con archivos **nuevos** de este
        change.

## Fase F1 — Seam `scope × target` y correcciones obligatorias de D11

**Esta fase NO es de cambio funcional cero** (design C4/D11). Preserva fórmula,
filtros, claves de storage y el contrato de resumen de R-BU-005, y asume las cinco
correcciones de D11. Cuatro de ellas son funcionales y cada una tiene su RED propio:

| # | Corrección (D11) | Unidad | RED propio |
|---|---|---|---|
| 1 | Corte silencioso de 50 filas: `articulo::all_from_familia()` usa `FS_ITEM_LIMIT` por defecto y el motor lo llama sin límite en `Services/CatalogoPriceUpdateService.php:210` (verificado) | WU-1.2 | `ArticulosPriceUpdateScopeTest::testFamilyLargerThanItemLimitIsFullyPaginated` (120 artículos en una familia) |
| 2 | Apply roto: el formulario de `view/catalogo_actualizar_precios.html.twig` solo envía `action=apply` y `confirm`, sin `codlista` ni `pct` (verificado) | WU-1.5 | `CatalogoActualizarPreciosControllerTest::testApplyFormRepostsEveryFilterAndPercent` |
| 3 | `#[AdminOnly]` ausente: el constructor actual pasa `TRUE` como 4.º argumento (obsoleto e ignorado; verificado en `controller/catalogo_actualizar_precios.php:65`) | WU-1.5 | `CatalogoActualizarPreciosControllerTest::testControllerDeclaresAdminOnlyAttribute` |
| 4 | Orden no determinista al agregar familias: el orden actual es familia-mayor | WU-1.2 | `ArticulosPriceUpdateScopeTest::testRowsAreOrderedByReferenciaAcrossFamilies` |
| 5 | Sustantivos de mensaje: "Ningún artículo coincide…" en `controller/catalogo_actualizar_precios.php:154` y `:181`, y "Vista previa: N artículos" en la vista | WU-1.5 | `CatalogoActualizarPreciosControllerTest::testEmptyMatchMessageIsScopeNeutral` |

- [x] **WU-1.1 — Registro de targets y contratos.**
  Archivos: `plugins/catalogo_core/Services/PriceUpdateTargetRegistry.php` (nuevo:
  `PriceBookInterface`, `PriceUpdateTargetInterface` y el registro en un solo
  archivo, como `Services/ArticuloListActionRegistry.php`),
  `plugins/catalogo_core/tests/Services/PriceUpdateTargetRegistryTest.php` (nuevo).
  Requisitos: R-BU-002, R-PSO-002.
  - [x] **RED:** `testRegisterResolveReset`, `testUnknownCodeResolvesToNull`,
        `testDuplicateRegistrationKeepsFirst`, `testAvailablePreservesRegistrationOrder`,
        `testDefaultCodePrefersLista`, `testRegisterDefaultsIsIdempotent`.
  - [x] **GREEN:** `register()`, `reset()`, `registerDefaults()`, `resolve()`,
        `available()`, `defaultCode()`; sin `dispatch()` ni `supports()` (design D3).
  - [x] **Verify:** RUN-CC `tests/Services/PriceUpdateTargetRegistryTest.php`.
  - [x] **Rollback:** archivo nuevo aislado; revertir no afecta comportamiento.
  - Notas: vocabulario neutral obligatorio ("target", "price book", "scope"); este
        archivo entra en `SEAM_FILES` de WU-0.1 en el mismo commit.

- [x] **WU-1.2 — Scope `articulos` y libro canónico de artículos.**
  Archivos: `plugins/catalogo_core/Services/PriceUpdateScopes.php` (nuevo),
  `plugins/catalogo_core/Services/CanonicalPriceBooks.php` (nuevo),
  `plugins/catalogo_core/tests/Services/ArticulosPriceUpdateScopeTest.php` (nuevo),
  `plugins/catalogo_core/tests/Services/CanonicalArticlePriceBookTest.php` (nuevo).
  Requisitos: R-BU-001, R-BU-002, R-BU-003, R-BU-007, R-PSO-001.
  - [x] **RED (D11.1 y D11.4):**
        `testFamilyLargerThanItemLimitIsFullyPaginated` (120 filas; hoy se cortan en
        `FS_ITEM_LIMIT`) y `testRowsAreOrderedByReferenciaAcrossFamilies` (dos
        familias con referencias intercaladas). Además
        `testEmptyFiltersReturnEmptyList`, `testRecursiveSubfamilies`,
        `testNonRecursiveFamilyLimit`, `testGroupFilterIsAnded`.
  - [x] **RED (libro):** `testCanonicalRowWins`,
        `testDefaultListFallsBackToPvp`, `testNonDefaultListMissingKeyIsOmitted`,
        `testAbsentKeyIsNotZero`, `testPersistWritesPerRow`.
  - [x] **GREEN:** paginar con
        `all_from_familia($code, $offset, FS_ITEM_LIMIT)` hasta que el lote sea menor
        que el límite; ordenar el conjunto por `referencia`; implementar
        `ArticulosPriceScope::canonicalBook()` (nunca devuelve `null` para artículos
        con target) y la lectura batch canónica con la misma regla que
        `CatalogoPriceResolver` (fila canónica, fallback `pvp` solo en la lista por
        defecto, clave ausente = sin precio).
  - [x] **Verify:** RUN-CC `tests/Services/ArticulosPriceUpdateScopeTest.php` y
        `tests/Services/CanonicalArticlePriceBookTest.php`.
  - [x] **Rollback:** revertir ambos servicios y sus tests; no hay datos escritos.
  - Notas: **no** se modifica `Services/CatalogoPriceResolver.php` (design C9). El
        scope no conoce `codlista`/`codtarifa` ni recibe el target.

- [x] **WU-1.3 — Target base `lista`.**
  Archivos: `plugins/catalogo_core/Services/ListaPriceUpdateTarget.php` (nuevo),
  `plugins/catalogo_core/tests/Services/PriceListAvailabilityTest.php` (modificado).
  Requisitos: R-BU-002, R-BU-003, R-BU-007.
  - [x] **RED:** `testListTargetDeclaresCodlistaKeyAndActiveOptions`,
        `testListTargetAvailabilityUsesListUiEnabled` (migrado desde el controlador),
        `testListTargetBookForArticulosReturnsCanonicalBook`,
        `testListTargetBookForOpcionalesReturnsNull`.
  - [x] **GREEN:** clave `codlista`, opciones = listas activas, disponibilidad =
        `PriceListAvailability::listUiEnabled()`, `bookFor('articulos')` = libro
        canónico, `bookFor('opcionales')` = `null` (la composición canónico-primero
        del motor nunca lo consulta para opcionales).
  - [x] **Verify:** RUN-CC `tests/Services/PriceListAvailabilityTest.php`.
  - [x] **Rollback:** archivo nuevo; revertir restaura la compuerta en el controlador.
  - Notas: cambio de contrato de test deliberado (design C8):
        `testEveryPriceListSurfaceUsesTheSameRule` pasa a auditar
        `Services/ListaPriceUpdateTarget.php`. `Services/PriceListAvailability.php`
        no se modifica.

- [x] **WU-1.4 — Motor `scope × target`.**
  Archivos: `plugins/catalogo_core/Services/CatalogoPriceUpdateService.php`
  (modificado), `plugins/catalogo_core/Init.php` (modificado:
  `PriceUpdateTargetRegistry::registerDefaults()` con guarda estática),
  `plugins/catalogo_core/tests/Services/CatalogoPriceUpdateServiceTest.php`
  (migrado).
  Requisitos: R-BU-001, R-BU-002, R-BU-003, R-BU-005 (regresión), R-BU-007.
  - [x] **RED:** migrar el test existente a `preview(scope, target, filters, pct)` /
        `apply(scope, target, filters, rows)` **conservando las expectativas
        idénticas** de fórmula, filtros, claves y resumen. Agregar
        `testCompositionPrefersCanonicalBook` (un libro de target que falle si se
        consulta para `opcionales`), `testApplyPersistsExactlyPreviewRows`,
        `testFailedKeysAreReported`, `testComputePriceIsUnchanged`
        (10.005 al 10 % → 11.01; 10.00 al −25 % → 7.50).
  - [x] **GREEN:** motor que recibe colaboradores resueltos y no ramifica por código;
        `computePrice()` sin cambios; `Init.php` registra el target base de forma
        idempotente. Conservar `matchArticles()`, `preview($filters, $pct)` y
        `apply($rows, $codlista)` como delegados finos para que el slice quede verde
        (design §12).
  - [x] **Verify:** RUN-CC `tests/Services/CatalogoPriceUpdateServiceTest.php` y
        RUN-TAR completo (piso 278 tests).
  - [x] **Rollback:** revertir motor + `Init`; los delegados hacían el
        comportamiento previo, así que el rollback no deja UI huérfana.
  - Notas: los delegados se retiran en WU-1.5. R-BU-005 no cambia: el test de
        `summarizeApply()` sigue idéntico.

- [x] **WU-1.5 — Controlador y vista (F1b).**
  Archivos: `plugins/catalogo_core/controller/catalogo_actualizar_precios.php`,
  `plugins/catalogo_core/view/catalogo_actualizar_precios.html.twig`,
  `plugins/catalogo_core/tests/CatalogoActualizarPreciosControllerTest.php`.
  Requisitos: R-BU-002, R-BU-004, R-BU-005, R-PSO-005.
  - [x] **RED (D11.3):** `testControllerDeclaresAdminOnlyAttribute` — el atributo
        existe por reflexión y el 4.º argumento del constructor es `FALSE`.
  - [x] **RED (D11.2):** `testApplyFormRepostsEveryFilterAndPercent` — el formulario
        de apply contiene `hidden` para `codlista`, `pct`, `codfamilia`,
        `incluir_subfamilias` y `codgrupo`; y
        `testApplyPersistsPreviewRowsWithRepostedFilters` — el POST de apply con
        esos campos persiste exactamente las filas del preview.
  - [x] **RED (D11.5):** `testEmptyMatchMessageIsScopeNeutral` — el mensaje usa un
        sustantivo neutral ("Ningún elemento coincide…") y no "artículo".
  - [x] **RED (seam):** `testTargetSelectorRendersRegisteredTargetsAndScopeSelector`,
        `testPageIsInertWithoutTargets` (registro vacío → mensaje neutral y cero
        escrituras), `testUnknownTargetFailsClosed`.
  - [x] **GREEN:** controlador que resuelve target/scope y falla cerrado, declara
        `#[\FSFramework\Attribute\AdminOnly]`, pasa el 4.º argumento a `FALSE` (5.º
        sigue `TRUE`), reposta filtros como `hidden`, usa el campo de clave
        `target->keyField()` y sustantivos neutrales; vista con selectores de
        target/scope. Retirar los delegados de WU-1.4.
  - [x] **Verify:** RUN-CC `tests/CatalogoActualizarPreciosControllerTest.php` y
        RUN-TAR completo.
  - [x] **Rollback:** quitar el atributo degrada `fs_pages.admin_only` en la
        siguiente visita; no hay datos que deshacer.
  - Notas: `test_controller_is_admin_gated_in_ventas_folder` se reescribe para
        afirmar el atributo y el 5.º argumento (design C10). `summarizeApply()` y su
        mensaje de resumen no cambian (R-BU-005).

## Fase F2 — `scope = opcionales` en el motor base

**Gate de fase:** paridad de cálculo y de escrituras contra la lógica legacy de
opcionales; el storage es `catalogo_opcional_precios` keyed
`(id_opcional, codlista)` sin importar el target.

- [x] **WU-2.1 — Scope y libro canónico de opcionales.**
  Archivos: `plugins/catalogo_core/Services/PriceUpdateScopes.php` (extendido),
  `plugins/catalogo_core/Services/CanonicalPriceBooks.php` (extendido),
  `plugins/catalogo_core/tests/Services/OpcionalesPriceUpdateScopeTest.php` (nuevo),
  `plugins/catalogo_core/tests/Services/CanonicalOpcionalPriceBookTest.php` (nuevo).
  Requisitos: R-BU-006, R-BU-001, R-BU-002, R-BU-003.
  - [x] **RED:** `testOptionalMembershipFiltersByCatalogoOpcionalFamiliasWithRecursion`,
        `testOptionalGroupFilterIsIgnored` (el grupo canónico de artículos no aplica),
        `testRowsAreOrderedByCodigo`, `testOptionalPaginationBeyondItemLimit`
        (paginar `catalogo_opcional::all($offset, $limit)` hasta agotar y ordenar por
        `codigo`), `testCanonicalOptionalBookReadsIdOpcionalAndCodlista`,
        `testOptionalBookOmitsMissingRows`, `testOptionalBookPersistsExistingRowOnly`
        (sin inserción desde apply).
  - [x] **GREEN:** `OpcionalesPriceScope` (matching por
        `catalogo_opcional_familias` con la misma recursividad de subfamilias;
        `canonicalBook()` devuelve el libro canónico de opcionales) y el libro
        `catalogo_opcional_precios` con clave `(id_opcional, codlista)`.
  - [x] **Verify:** RUN-CC ambos archivos de test.
  - [x] **Rollback:** el scope de opcionales desaparece y la página sigue con
        artículos; las filas escritas son las mismas que escribe la página legacy.
  - Notas: composición canónico-primero (D1): para `opcionales`, el libro del target
        no se consulta nunca. `model/tarif_opcional_precio.php` ya extiende
        `catalogo_opcional_precio`, así que la tabla es la misma.

- [x] **WU-2.2 — Arnés de paridad sin BD y paridad de opcionales.**
  Archivos: `plugins/tarifario/tests/Support/PriceUpdateParityFixture.php`,
  `plugins/tarifario/tests/Support/LegacyPriceUpdateFakeDb.php`,
  `plugins/tarifario/tests/Support/LegacyPriceUpdateDriver.php`,
  `plugins/tarifario/tests/PriceUpdateParityTest.php` (todos nuevos).
  Requisitos: R-BU-006, R-BU-007, R-BU-001.
  - [x] **RED:** `testOptionalPreviewParityWithLegacyPage` — mismo fixture,
        `scope=opcionales` + `target=lista` + `codlista=T` contra la página legacy
        (`tipo=opcionales`, `codtarifa=T`, `offset=0`): mismas tripletas
        `(id_opcional, precio_actual, precio_nuevo)`, mismo orden, mismo conteo y
        misma suma. `testOptionalApplyWritesSameCanonicalRows`: ambos caminos
        escriben `(id_opcional, codlista, precio)` y el preview no escribe.
  - [x] **GREEN:** fixture con semilla única (familias y árbol, artículos,
        opcionales, filas canónicas, filas de tarifa, grupos de rol y membresías);
        `fs_db2` falso que responde las consultas exactas de la página legacy y
        registra escrituras; driver con `newInstanceWithoutConstructor()` sobre
        `tarif_actualizar_precios`, inyección de `db`, `codtarifa`, `tipo`,
        `b_codfamilia`, `incluir_subfamilias`, `b_codgrupo`, `porcentaje` y `offset`,
        e invocación por reflexión de `load_preview()` /
        `aplicar_cambios_opcionales()`; subclase anónima que anula `new_message`,
        `new_error_msg`, `var2str` y `default_codidioma`.
  - [x] **Verify:** RUN-TAR `tests/PriceUpdateParityTest.php` y RUN-CC completo.
  - [x] **Rollback:** solo tests; revertir no toca producción.
  - Notas: el fixture row-by-row se mantiene por debajo de `FS_ITEM_LIMIT` para que
        la legacy no pagine; la paridad de conteo con más filas se prueba aparte
        contra `total_resultados`. El apply legacy exige POST `ids_opcionales` con
        **todas** las filas (D10.3). Este test vive en `tarifario` porque necesita el
        consumidor; `catalogo_core` debe seguir siendo probable en standalone
        (design §7.1).

## Fase F3 — Registro del consumidor y hook de filtros

**Gate de fase:** `GATE-3` cerrado, paridad preview/apply de artículos byte a byte
en filas, orden, totales y escrituras, y golden capturado antes de F4.

- [ ] **GATE-3 — Delta del consumidor (R-PSO-007), propiedad de `tarifario`.**
  Requisitos: R-PSO-007.
  - [ ] Abrir el change del consumidor en
        `plugins/tarifario/openspec/changes/<nombre-del-consumidor>/` que documente
        `tarifario/catalogo-integration` y referencie el contrato de registro de
        `catalogo_core` sin redefinirlo. **No lo escribe este change**; se registra
        aquí como dependencia porque WU-3.2/WU-4.2 modifican producción de
        `tarifario`.
  - [ ] **Verify:** las rutas de este change siguen todas bajo
        `plugins/catalogo_core/openspec/changes/unificar-superficies-precio-catalogo-core/`;
        `git status` no muestra archivos nuevos de este change en el árbol
        `plugins/tarifario/openspec/`.

- [ ] **WU-3.1 — Registro de filtros y marcador de hook.**
  Archivos: `plugins/catalogo_core/Services/PriceUpdateFilterRegistry.php` (nuevo),
  `plugins/catalogo_core/view/catalogo_actualizar_precios.html.twig`,
  `plugins/catalogo_core/controller/catalogo_actualizar_precios.php`,
  `plugins/catalogo_core/tests/Services/PriceUpdateFilterRegistryTest.php` (nuevo),
  `plugins/catalogo_core/tests/CatalogoCoreHookMarkersTest.php` (ampliado).
  Requisitos: R-CRH-001, R-BU-002.
  - [ ] **RED:** `testRegistryKeysViewContextAndNarrow`,
        `testRegistryResetAndRegistrationOrder`,
        `testNewHookMarkerIsNeutralZeroByteForm` (expresión exacta con control de
        espacios y sin el token `tarifa`), `testFilterKeysAreRepostedAsHiddenInputs`
        (solo se repostean las claves declaradas; claves arbitrarias del cliente se
        ignoran), `testUnregisteredHookRendersEmptyAndRegisteredRendersHtml`
        (extensión del arnés existente de marcadores).
  - [ ] **GREEN:** registrar el hook con la forma exacta
        `{{- render_hook('catalogo_actualizar_precios_filters_after', {'fsc': fsc, 'user': user, 'empresa': empresa, 'i18n': i18n}) -}}`
        dentro del formulario de filtros, después de `pct` y antes del botón;
        `price_update_filter_context()` en el controlador; `hidden` solo para
        `PriceUpdateFilterRegistry::keys($scope)`.
  - [ ] **Verify:** RUN-CC `tests/CatalogoCoreHookMarkersTest.php` y
        `tests/Services/PriceUpdateFilterRegistryTest.php`.
  - [ ] **Rollback:** sin registro, el hook rinde `''` y la página sigue igual.
  - Notas: los cuatro hooks congelados no se tocan; el hook nuevo se declara **solo**
        en la vista de precios, nunca en `ventas_articulo.html.twig` ni
        `ventas_opcional.html.twig` (R-CRH-002). `$this->template` y el cuerpo de la
        vista no cambian de semántica.

- [ ] **WU-3.2 — Target `tarifa`, libro de artículos, filtro de grupo de rol y registro.**
  Archivos: `plugins/tarifario/Services/TarifaPriceUpdateTarget.php`,
  `plugins/tarifario/Services/TarifaArticlePriceBook.php`,
  `plugins/tarifario/Services/TarifaRoleGroupPriceFilter.php` (nuevos),
  `plugins/tarifario/View/Hooks/catalogo_actualizar_precios_filters_after.html.twig`
  (nuevo), `plugins/tarifario/Init.php`,
  `plugins/tarifario/tests/Services/TarifaPriceUpdateTargetTest.php`,
  `plugins/tarifario/tests/Services/TarifaRoleGroupPriceFilterTest.php`,
  `plugins/tarifario/tests/Integration/PriceUpdateTargetRegistrationTest.php`
  (nuevos).
  Requisitos: R-PSO-002, R-BU-002, R-BU-007, R-CRH-001.
  - [ ] **RED:** `testTargetKeyFieldAndOptions` (`codtarifa`, tarifas activas),
        `testTargetAvailabilityRequiresActiveTariff`,
        `testBookForArticulosReadsTarifArticuloPrecios`,
        `testBookForOpcionalesReturnsNull`,
        `testBookPersistsViaGetAndSaveToKeepHistory`,
        `testFilterKeysViewContextAndNarrow`, `testFilterSupportsOnlyArticulos`,
        `testInitRegistersTargetFilterAndHookOnce`, `testInitWithoutCoreDoesNotFatal`.
  - [ ] **GREEN:** target que lee y escribe **solo** `tarif_articulo_precios` con
        clave `(referencia, codtarifa)`; `persist()` usa
        `tarif_articulo_precio::get()` + `save()` para conservar
        `tarif_precio_historial`; filtro `codgrupo_rol` sobre
        `tarif_grupo_articulos` con `narrow()` y `viewContext()`; plantilla del hook
        con el selector de grupo (solo `scope=articulos`); registro idempotente en
        `Init::init()` con guardas estáticas y `class_exists` por pieza
        (`PriceUpdateTargetRegistry` ≥ 1.16.0, `PriceUpdateFilterRegistry` ≥ 1.18.0).
  - [ ] **Verify:** RUN-TAR `tests/Services/TarifaPriceUpdateTargetTest.php`,
        `tests/Services/TarifaRoleGroupPriceFilterTest.php`,
        `tests/Integration/PriceUpdateTargetRegistrationTest.php`; luego RUN-TAR
        completo; `ddev exec php -l` sobre los archivos nuevos y `Init.php`.
  - [ ] **Rollback:** el target deja de registrarse, el hook queda sin registrar
        (cero bytes) y las filas escritas en `tarif_articulo_precios` son las mismas
        que escribía la legacy.
  - Notas: la paridad exige que el libro de tarifa **no** consulte el canónico
        (D10, R-BU-007). El filtro de grupo de rol (`codgrupo_rol`) es distinto del
        grupo canónico de artículos (`codgrupo`); no confundirlos.

- [ ] **WU-3.3 — Paridad de artículos y captura del golden.**
  Archivos: `plugins/tarifario/tests/PriceUpdateParityTest.php` (ampliado),
  `plugins/tarifario/tests/Fixtures/price_update_parity_legacy.json` (generado),
  `plugins/tarifario/tests/Support/` (fixture y driver ampliados).
  Requisitos: R-BU-007, R-BU-001, R-BU-003.
  - [ ] **RED:** `testArticlePreviewParityWithLegacyPage` (mismo fixture,
        `target=tarifa` + `codtarifa=T` contra la legacy con `tipo=articulos`,
        `b_codfamilia` + `b_codgrupo` mapeado a `codgrupo_rol`, `offset=0`),
        `testArticleParityWhenCanonicalAndTariffRowsCoexist` (D10: el libro de tarifa
        ignora la fila canónica), `testArticleApplyWritesSameTariffRows`,
        `testPreviewDoesNotWriteInEitherPath`, `testGoldenMatchesLegacyOutput`.
  - [ ] **GREEN:** driver de artículos sobre `load_preview_articulos()` /
        `aplicar_cambios_articulos()`; capturar la salida esperada de la legacy en
        `Fixtures/price_update_parity_legacy.json` una vez verde.
  - [ ] **Verify:** RUN-TAR `tests/PriceUpdateParityTest.php` y RUN-CC completo.
  - [ ] **Rollback:** tests + golden; revertir no toca producción.
  - Notas: todo caso de paridad incluye al menos un filtro de acotado (el motor base
        devuelve `[]` sin filtros por decisión de seguridad, D10.4). El golden se
        graba **antes** de F4 porque F4 elimina el oráculo.

## Fase F4 — Retiro de `tarif_actualizar_precios` (slice atómico)

**Gate de fase:** no queda ninguna referencia a la vista retirada; el redirect
nunca emite `codlista` derivada de `codtarifa`; la fila `fs_pages` se retira de
forma idempotente; el replay del golden sigue verde. WU-4.2 y WU-4.3 viajan en el
mismo slice, sin deploy intermedio entre los dos commits de repo.

- [ ] **WU-4.1 — Aviso de selección requerida (lado core).**
  Archivos: `plugins/catalogo_core/controller/catalogo_actualizar_precios.php`,
  `plugins/catalogo_core/tests/CatalogoActualizarPreciosControllerTest.php`.
  Requisitos: R-BU-008, R-BU-002.
  - [ ] **RED:** `testSelectionRequiredNoticeIsWhitelisted` (`aviso=seleccion_requerida`
        pinta el mensaje neutral; códigos desconocidos se ignoran) y
        `testNoticeDoesNotPreselectAnyTarget`.
  - [ ] **GREEN:** whitelist del código de aviso y mensaje neutral; el aviso nunca
        aplica ni escribe.
  - [ ] **Verify:** RUN-CC `tests/CatalogoActualizarPreciosControllerTest.php`.
  - [ ] **Rollback:** revertir el aviso no cambia el flujo de preview/apply.
  - Notas: el aviso es de solo lectura; los códigos desconocidos no se reflejan en el
        HTML ni en el estado.

- [ ] **WU-4.2 — Shim de redirect (lado consumidor).**
  Archivos: `plugins/tarifario/controller/tarif_actualizar_precios.php` (reescrito),
  `plugins/tarifario/tests/LegacyRedirectShimTest.php` (nuevo).
  Requisitos: R-BU-008, R-PSO-005, R-PSO-006, R-PSO-002.
  - [ ] **RED:** `testEmptyCodtarifaRedirectsWithoutPreselection`,
        `testAvailableTariffTargetPreservesTargetAndCodtarifa`,
        `testUnavailableTargetOpensWithoutPreselectionAndWithNotice`,
        `testNeverEmitsCodlistaDerivedFromCodtarifa`,
        `testRedirectTargetIsFixedCatalogPage`,
        `testLegacyControllerHasNoPreviewOrApply`.
  - [ ] **GREEN:** constructor exacto
        `parent::__construct(__CLASS__, 'Actualizar Precios', 'tarifario', FALSE, FALSE)`;
        `$this->template = FALSE`; guarda `is_file()` del controlador destino y
        `class_exists()` del registro (si falta, `header('Location: index.php')`);
        `header('Location: ' . self::build_redirect(...))`; helper puro y testeable
        sin BD. La URL destino siempre es
        `index.php?page=catalogo_actualizar_precios`; `codtarifa` y `codlista` solo
        se interpolan con `rawurlencode()`.
  - [ ] **Verify:** RUN-TAR `tests/LegacyRedirectShimTest.php` y `ddev exec php -l
        plugins/tarifario/controller/tarif_actualizar_precios.php`.
  - [ ] **Rollback:** revertir restaura controlador y vista; `check_fs_page()`
        recrea la fila `fs_pages` con el menú original. Las filas de
        `fs_roles_access` quedan intactas.
  - Notas: **contradicción resuelta a favor del spec.** La tabla D8 (filas 3–4)
        todavía describe un fallback `target=lista&codlista=<codtarifa>`, pero
        R-BU-008 y la resolución C2 lo **prohíben explícitamente**. La conducta
        implementada es: `codtarifa` vacío → sin preselección; target `tarifa`
        disponible → `target=tarifa&codtarifa=X`; en cualquier otro caso →
        `aviso=seleccion_requerida`, sin preselección. La firma de
        `build_redirect()` **no** necesita `$mirrorExists`: si se conserva, no puede
        influir en la URL (test lo prohíbe).

- [ ] **WU-4.3 — Borrado de la vista y limpieza de fixtures (atómico con WU-4.2).**
  Archivos: `plugins/tarifario/View/tarif_actualizar_precios.html.twig` (borrado),
  `plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php` (entrada de
  `REPOINTED_LINKS` en la línea 47), `plugins/catalogo_core/tests/CatalogoOpcionalesUnifiedControllerTest.php`
  (referencia en la línea 1129),
  `plugins/catalogo_core/tests/Controller/VentasArticuloArticleEditAbsorptionTest.php`
  (referencia en la línea 1122), `plugins/catalogo_core/tests/PriceSurfaceOwnershipTest.php`
  (ampliado).
  Requisitos: R-PSO-006, R-PSO-001.
  - [ ] **RED:** `PriceSurfaceOwnershipTest::testRetiredPriceUpdateViewIsAbsent` y
        las tres fixtures reescritas para afirmar la **ausencia** de la vista en vez
        de leerla; todas fallan mientras el archivo exista.
  - [ ] **GREEN:** borrar la vista y eliminar las tres entradas en el mismo commit;
        agregar la aserción de ausencia.
  - [ ] **Verify:** RUN-CC + RUN-TAR; y
        `grep -rn "tarif_actualizar_precios.html.twig" plugins --include="*.php" --include="*.twig" --exclude-dir=vendor --exclude-dir=openspec`
        → 0 resultados.
  - [ ] **Rollback:** revertir el commit restaura la vista y las entradas de fixture.
  - Notas: **no se puede partir de WU-4.2.** Las fixtures de `catalogo_core` exigen
        la vista; si el borrado aterriza solo, la suite del core queda roja. Ver la
        restricción de tagging en `## Secuencia de releases`.

- [ ] **WU-4.4 — Retiro idempotente de la fila `fs_pages`.**
  Archivos: `plugins/tarifario/Services/LegacyPricePageRetirement.php` (nuevo),
  `plugins/tarifario/Init.php` (`upgrade()`),
  `plugins/tarifario/tests/LegacyPricePageRetirementTest.php` (nuevo).
  Requisitos: R-PSO-005.
  - [ ] **RED:** `testDeletesPreexistingPageRowAndClearsCache` (con `fs_db2` falso:
        `fs_page::delete()` y limpieza de `m_fs_page_all`),
        `testIdempotentWhenRowMissing`, `testDoesNotTouchRolesAccess`,
        `testUpgradeInvokesRetirementNotInit`.
  - [ ] **GREEN:** servicio idempotente y llamada desde `Init::upgrade()` (nunca
        desde `init()`), con `try/catch` + `error_log` etiquetado.
  - [ ] **Verify:** RUN-TAR `tests/LegacyPricePageRetirementTest.php`.
  - [ ] **Rollback:** la visita a la URL legacy recrea la fila oculta; el rollback
        del plugin restaura el menú.
  - Notas: no se tocan `fs_roles_access`. `fs_pages.show_on_menu = FALSE` es la
        garantía durable; una fila oculta puede recrearse al visitar la URL legacy
        (design D12/C1).

- [ ] **WU-4.5 — Replay del golden tras el retiro.**
  Archivos: `plugins/tarifario/tests/PriceUpdateParityTest.php`,
  `plugins/tarifario/tests/Support/LegacyPriceUpdateDriver.php` y
  `LegacyPriceUpdateFakeDb.php` (borrados),
  `plugins/tarifario/tests/Fixtures/price_update_parity_legacy.json` (conservado).
  Requisitos: R-BU-007, R-BU-006.
  - [ ] **RED:** `testLegacyDriverIsGone` y
        `testGoldenReplayMatchesLegacyOutput` (el primero falla mientras el driver
        exista; el segundo falla si el replay no reproduce el golden).
  - [ ] **GREEN:** el motor base, con `scope=articulos|opcionales` y `target=tarifa`,
        reproduce el golden sin el oráculo legacy.
  - [ ] **Verify:** RUN-TAR `tests/PriceUpdateParityTest.php` y RUN-CC completo.
  - [ ] **Rollback:** el golden es un archivo de test; revertir restaura el driver.
  - Notas: preserva la garantía de paridad después de borrar la página legacy. Si la
        reflexión resultó demasiado acoplada, la contingencia de design §7.1 es
        grabar el golden desde `ddev` sobre una BD sembrada y conservar solo el
        replay; debe registrarse esa decisión en el apply-progress.

## Fase F5 — Vistas retiradas y dedup opcional

- [ ] **WU-5.1 (F5a) — Borrado de la vista muerta `tarif_articulo.html.twig`.**
  Archivos: `plugins/tarifario/View/tarif_articulo.html.twig` (borrado),
  `plugins/catalogo_core/tests/ArticuloListaCanonicaOwnershipTest.php` (entrada de
  la línea 49), `plugins/catalogo_core/tests/PriceSurfaceOwnershipTest.php`
  (ampliado).
  Requisitos: R-PSO-006, R-PSO-001.
  - [ ] **RED:** `PriceSurfaceOwnershipTest::testDeadArticleViewIsAbsent` y la
        fixture sin la entrada; falla mientras la vista exista. Sonda de mutación
        equivalente si se prefiere aislar el RED: restaurar el archivo y ver el test
        fallar.
  - [ ] **GREEN:** borrar la vista y quitar la entrada de `REPOINTED_LINKS`. La vista
        no tiene controlador ni página que la renderice (verificado: solo la nombra
        `ArticuloListaCanonicaOwnershipTest:49`).
  - [ ] **Verify:** RUN-CC + RUN-TAR; y
        `grep -rn "tarif_articulo.html.twig" plugins --include="*.php" --include="*.twig" --exclude-dir=vendor --exclude-dir=openspec`
        → 0 resultados.
  - [ ] **Rollback:** revertir restaura la vista; no hay datos.
  - Notas: requerido por R-PSO-006; no depende de F5b.

- [ ] **WU-5.2 (F5b, diferible) — Dedup de presentación.**
  Archivos: a determinar en el gate; superficie mínima
  `plugins/catalogo_core/view/ventas_listas_precio.html.twig`,
  `plugins/catalogo_core/controller/ventas_listas_precio.php` y el macro compartido
  que se extraiga.
  Requisitos: **ninguno en los deltas.** Motivación R-PSO-001 (presentación propiedad
  del owner) y design §15.3; no se inventa un requisito nuevo.
  - [ ] **GATE-5b:** decisión explícita de ejecutar o diferir. Si no hay requisito
        funcional, el default es **diferir** a un change propio.
  - [ ] **RED (si se ejecuta):** test estructural de macro compartido y de que
        `ventas_listas_precio` sigue siendo vista de storage; a definir en el gate.
  - [ ] **GREEN:** extraer el macro y reutilizarlo sin cambiar la semántica de
        `PriceListAvailability` ni de `ventas_listas_precio`.
  - [ ] **Verify:** RUN-CC + smoke visual con el tema AdminLTE.
  - [ ] **Rollback:** revertir el commit restaura las dos vistas independientes.
  - Notas: **no se mezcla con otro slice** y no bloquea releases. Si la estimación
        supera 400 líneas sin requisito que la respalde, se difiere.

## Guardrails como tareas

Cada guardrail es una tarea verificable, no una intención. Se ejecutan al cierre de
**cada** slice.

- [ ] **G-1 — La allowlist no crece.** Archivo:
      `plugins/catalogo_core/tests/CatalogoCoreHookMarkersTest.php`. La lista
      `TARIFARIO_ALLOWLIST` tiene **52 entradas** al inicio del change; ninguna
      entrada nueva puede corresponder a
      `Services/PriceUpdateTargetRegistry.php`, `Services/PriceUpdateScopes.php`,
      `Services/CanonicalPriceBooks.php`, `Services/ListaPriceUpdateTarget.php` ni
      `Services/PriceUpdateFilterRegistry.php`. **Verify:** RUN-CC
      `tests/PriceSurfaceOwnershipTest.php` (fija el recuento) y
      `git diff -- plugins/catalogo_core/tests/CatalogoCoreHookMarkersTest.php` no
      debe mostrar entradas nuevas en el array.
- [ ] **G-2 — Ningún archivo nuevo de `catalogo_core` nombra al consumidor.**
      Archivos: todos los nuevos de `Services/`, `controller/`, `view/` e
      `Init.php`. **Verify:**
      `grep -rn "tarifario" plugins/catalogo_core/Services plugins/catalogo_core/controller plugins/catalogo_core/view plugins/catalogo_core/Init.php --exclude-dir=vendor --exclude-dir=openspec`
      → cada hit debe corresponder a un archivo preexistente de la allowlist; cero
      hits de archivos nuevos.
- [ ] **G-3 — Hooks congelados intactos.** Archivos:
      `plugins/catalogo_core/View/ventas_articulo.html.twig`,
      `plugins/catalogo_core/View/ventas_opcional.html.twig`. Los cuatro nombres
      `ventas_articulo_tabs_after`, `ventas_articulo_tab_pane_after`,
      `ventas_opcional_tabs_after` y `ventas_opcional_tab_pane_after` conservan
      nombre, posición, contexto y la forma `{{- -}}`. **Verify:** RUN-CC
      `tests/Integration/CatalogoCoreHookMarkersTest.php`.
- [ ] **G-4 — Vocabulario neutral de `RendererHook` y de los seams.** El hook nuevo
      se llama exactamente `catalogo_actualizar_precios_filters_after` (sin el token
      `tarifa`); los docblocks de los seams usan "target", "consumer", "price book"
      y "scope", nunca el nombre del plugin consumidor. **Verify:**
      `grep -n "tarifa" plugins/catalogo_core/Services/PriceUpdateTargetRegistry.php plugins/catalogo_core/Services/PriceUpdateScopes.php plugins/catalogo_core/Services/CanonicalPriceBooks.php plugins/catalogo_core/Services/ListaPriceUpdateTarget.php plugins/catalogo_core/Services/PriceUpdateFilterRegistry.php`
      → 0 hits; RUN-CC `tests/CatalogoCoreHookMarkersTest.php`.
- [ ] **G-5 — Propiedad cross-plugin del spec.** Ningún archivo de este change se
      escribe bajo `plugins/tarifario/openspec/`; el delta del consumidor vive en su
      propio change (`GATE-3`). **Verify:** `git status --short` del repo y listado
      de rutas del change: todo bajo
      `plugins/catalogo_core/openspec/changes/unificar-superficies-precio-catalogo-core/`.
- [ ] **G-6 — Cero cambios de core.** **Verify:**
      `git status --short -- base src controller model openspec VERSION` → sin
      cambios. `VERSION` del core no se toca (0.35.11 se queda).
- [ ] **G-7 — Sin dependencias nuevas.** **Verify:**
      `git diff -- plugins/catalogo_core/composer.json plugins/catalogo_core/composer.lock plugins/tarifario/composer.json plugins/tarifario/composer.lock`
      → vacío. No hay commit de `vendor/`.

## Secuencia de releases

Tags **independientes** por plugin; **sin release de core**. Detalle y justificación
en design §13.

| Release | Versión | Contenido | Orden |
|---|---|---|---|
| `catalogo_core` | 1.16.0 | F0 + F1 (registro, scopes de artículos, target `lista`, motor, UI) | 1.º |
| `catalogo_core` | 1.17.0 | F2 (opcionales) | 2.º |
| `catalogo_core` | 1.18.0 | F3a (hook + registro de filtros) | 3.º |
| `catalogo_core` | 1.19.0 | F4 lado core (aviso, limpieza de fixtures) | 4.º |
| `tarifario` | 3.5.0 | F3b + F4 lado consumidor (target, filtro, retiro, shim, golden replay) | 5.º (después de 1.19.0) |
| `catalogo_core` | 1.20.0 | F5a (vista muerta + dedup si se ejecuta) | 6.º |

- [ ] **REL-1 — `catalogo_core` 1.16.0.** Archivo:
      `plugins/catalogo_core/fsframework.ini` (`version = 1.16.0`, de 1.15.2). Tag:
      `(cd plugins/catalogo_core && git tag -a v1.16.0 -m "Release v1.16.0" && git push origin v1.16.0)`.
- [ ] **REL-2 — `catalogo_core` 1.17.0.** Archivo:
      `plugins/catalogo_core/fsframework.ini` (`version = 1.17.0`). Tag:
      `(cd plugins/catalogo_core && git tag -a v1.17.0 -m "Release v1.17.0" && git push origin v1.17.0)`.
- [ ] **REL-3 — `catalogo_core` 1.18.0.** Archivo:
      `plugins/catalogo_core/fsframework.ini` (`version = 1.18.0`). Tag:
      `(cd plugins/catalogo_core && git tag -a v1.18.0 -m "Release v1.18.0" && git push origin v1.18.0)`.
- [ ] **REL-4 — `catalogo_core` 1.19.0.** Archivo:
      `plugins/catalogo_core/fsframework.ini` (`version = 1.19.0`). Tag:
      `(cd plugins/catalogo_core && git tag -a v1.19.0 -m "Release v1.19.0" && git push origin v1.19.0)`.
      **Orden obligatorio:** los dos commits de PR-6 (core y consumidor) ya están en
      el árbol de trabajo **antes** de cortar este tag. Si se corta 1.19.0 mientras
      la vista legacy sigue presente en `plugins/tarifario/`, la suite standalone de
      `catalogo_core` queda roja por las fixtures de WU-4.3. Taggear 1.19.0 después
      del commit del consumidor y antes del tag de `tarifario`.
- [ ] **REL-5 — `tarifario` 3.5.0.** Archivo: `plugins/tarifario/fsframework.ini`
      (`version = 3.5.0`, de 3.4.2). Tag:
      `(cd plugins/tarifario && git tag -a v3.5.0 -m "Release v3.5.0" && git push origin v3.5.0)`.
      Condición: REL-4 ya publicado.
- [ ] **REL-6 — `catalogo_core` 1.20.0.** Archivo:
      `plugins/catalogo_core/fsframework.ini` (`version = 1.20.0`). Tag:
      `(cd plugins/catalogo_core && git tag -a v1.20.0 -m "Release v1.20.0" && git push origin v1.20.0)`.
      Si WU-5.2 se difiere, 1.20.0 solo contiene F5a y se documenta así.
- [ ] **REL-7 — Core sin release.** Verificar que `VERSION` de la raíz sigue en
      0.35.11 y que no se crea tag en el repo del core. `require = "catalogo_core"`
      no admite versión; la compatibilidad se garantiza con las guardas
      `class_exists` descritas en WU-3.2.

## Fuera de alcance

- **No se fusionan los dos storages de artículos** (`catalogo_articulo_precios` /
  `tarif_articulo_precios`) ni se ejecuta ninguna migración de datos.
- **No se fusionan `tarif_tarifas` y `ventas_listas_precio`** en una sola entidad ni
  página; esa fusión queda **postergada**.
- **No se tocan archivos de core**: nada bajo `base/`, `src/`, `controller/`,
  `model/` ni `openspec/` de la raíz; `VERSION` no cambia.
- **No se renombran, mueven ni re-contextualizan** los cuatro hooks congelados por
  R-TAR-HOOK-003.
- **No hay cambios de esquema**: los dos storages quedan como están.
- **No se toca `Services/CatalogoPriceResolver.php`** (design C9).
- **No se toca `Services/PriceListAvailability.php`**: su semántica se reutiliza tal
  cual.
- **No se agregan requisitos nuevos** más allá de R-PSO-001..007, R-BU-001..004,
  R-BU-006..008 y R-CRH-001..002. R-BU-005 se conserva sin cambios.

## Limitaciones conocidas / aceptadas

- **Paginación del preview (aceptada).** La página base no pagina el preview; la
  página legacy sí (`paginas()` / `offset`). Con catálogos grandes el preview puede
  ser muy largo. Se mantiene el comportamiento actual y se registra como limitación
  conocida, no como regresión (la página base ya era así). Fuente: design §15.4.
- **Selección fila a fila (fuera de alcance).** La página legacy aplica solo las
  filas marcadas; el motor base aplica el preview completo. La paridad se define con
  **todas** las filas seleccionadas (estado por defecto de la legacy). C5.
- **Fila oculta en `fs_pages` (aceptada).** Tras visitar la URL legacy, el framework
  puede recrear la fila del shim, ya con `show_on_menu = FALSE`. La garantía durable
  es **una sola página publicada y una sola entrada de menú renderizada**, no la
  ausencia de filas en el registro (design C1/D12).
- **Paridad solo sobre conjuntos acotados (diferencia deliberada).** El motor base
  devuelve `[]` sin filtros de acotado; la legacy actualiza la tarifa completa. Los
  casos de paridad siempre incluyen al menos un filtro (design D10.4). No es una
  regresión: es una decisión de seguridad.

## Dependencias y gates externos

| Gate | Dueño | Bloquea | Qué se necesita |
|---|---|---|---|
| `GATE-3` | `tarifario` | PR-5 (WU-3.2) | Change propio en `plugins/tarifario/openspec/changes/` para `tarifario/catalogo-integration` (R-PSO-007) |
| `GATE-5b` | maintainer | PR-8 (WU-5.2) | Decisión explícita de ejecutar o diferir la dedup sin requisito |
| REL-4 → REL-5 | release | tag de `tarifario` 3.5.0 | 1.19.0 ya publicado |
| Orden PR-6 | release | tags 1.19.0 / 3.5.0 | Ambos commits de PR-6 aterrizados antes de taggear core |

## Contradicciones detectadas (se reportan, no se resuelven en silencio)

1. **F1 "sin cambio funcional".** `proposal.md` (Fase F1) y `design.md` §12 lo
   describen como no funcional, pero `design.md` D11/C4 demuestra que el corte de 50
   filas, el apply roto, el `#[AdminOnly]` y el orden determinista son observables.
   **Resolución adoptada:** F1 se planifica explícitamente como **cambio funcional
   acotado** con los cinco RED de la tabla de D11; se preservan fórmula, filtros,
   claves y resumen.
2. **D8 filas 3–4 vs R-BU-008.** La tabla D8 todavía prevé
   `target=lista&codlista=<codtarifa>` cuando existe espejo, pero R-BU-008 (enmendado
   en C2) prohíbe **cualquier** mapeo de `codtarifa` a `codlista`. **Resolución
   adoptada:** gana el spec; el shim nunca emite `codlista` derivada y la rama del
   espejo colapsa en "sin preselección + aviso". Consecuencia: `$mirrorExists` no
   puede influir en `build_redirect()`.
3. **Presupuesto de F4.** `design.md` §12 estima F4 en ~350 líneas, pero el borrado
   de la vista (347) más el encogimiento del controlador (506 → ~55) supera por sí
   solo las 800 líneas bajo la regla adición + borrado. **Resolución adoptada:** se
   mantiene un único slice atómico (R-PSO-006) y se solicita `size:exception`
   documentando adiciones (~200) vs borrados (~800).
4. **Ownership del SDD vs archivos tocados.** El change vive completo en
   `plugins/catalogo_core/openspec/`, pero WU-3.2/WU-4.2/WU-4.3/WU-4.4 modifican
   producción de `tarifario`. R-PSO-007 exige un change del consumidor para F3.
   **Resolución adoptada:** `GATE-3` abre ese change en `tarifario` y estas unidades
   se planifican aquí solo por dependencia; este change no escribe el árbol
   `openspec` del consumidor.
5. **Orden de tags 1.19.0 vs 3.5.0.** `design.md` §13 ubica 1.19.0 antes de
   `tarifario` 3.5.0, pero la limpieza de fixtures de F4 deja `catalogo_core` rojo
   en standalone hasta que se borre la vista de `tarifario`. **Resolución
   adoptada:** ambos commits de PR-6 aterrizan antes de cortar 1.19.0; el tag de
   `tarifario` 3.5.0 va después. No se invierte la secuencia de tags, solo el
   momento exacto del corte.

## Definición de done

1. Existe una única página de actualización masiva publicada
   (`catalogo_actualizar_precios`) que cubre `articulos` y `opcionales` con
   `target = lista` y `target = tarifa`.
2. `PriceUpdateParityTest` demuestra filas, orden, totales y escrituras idénticas
   entre la página base y la legacy sobre el mismo fixture, para ambos scopes, y el
   replay del golden sigue verde tras el retiro.
3. `tarif_actualizar_precios` ya no es una página: solo redirige, no aparece en el
   menú y nunca mapea `codtarifa` a `codlista`.
4. `TARIFARIO_ALLOWLIST` sigue en 52 entradas y ningún archivo nuevo de
   `catalogo_core` nombra al consumidor.
5. Los cuatro hooks congelados conservan nombre, posición y contexto; el hook nuevo
   es aditivo, neutral y rinde cero bytes sin registro.
6. Sin migración de datos, sin cambios de esquema y sin dependencias Composer
   nuevas.
7. Ambas suites verdes al cierre: `catalogo_core` ≥ 1593 tests y `tarifario` ≥ 278
   tests, sin nuevas omisiones.
8. `catalogo_core` 1.16.0 → 1.20.0 y `tarifario` 3.5.0 taggeados de forma
   independiente; sin release de core.
