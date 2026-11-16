# Verify Report: unificar-superficies-precio-catalogo-core

**Change**: `unificar-superficies-precio-catalogo-core`
**SDD home**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
**Verdict**: **PASS** — 0 CRITICAL
**Date**: 2026-11-16

## Resumen

La actualización masiva de precios queda en una sola página propiedad de
`catalogo_core` (`catalogo_actualizar_precios`) con `scope (articulos | opcionales)
× target (lista | tarifa)`. El consumidor (`tarifario`) aporta el target `tarifa`,
su filtro y el historial de opcionales por registro; su página legacy es un
redirect y sus vistas muertas están borradas.

## Criterios y resultados

| Requisito | Resultado | Evidencia |
|---|---|---|
| R-PSO-001, 003, 004 — dueño único, guardrail de acoplamiento, hooks congelados | ✅ | `PriceSurfaceOwnershipTest` |
| R-PSO-002 — el consumidor se registra | ✅ | `Services/PriceUpdateTargetRegistryTest`, tarifario `LegacyRedirectShimTest` |
| R-PSO-005 — una sola página | ✅ | tarifario `LegacyRedirectShimTest`, `LegacyPricePageRetirementTest` |
| R-PSO-006 — superficies retiradas ausentes | ✅ | `PriceSurfaceOwnershipTest::testRetiredPriceUpdateViewIsAbsent`, `::testDeadArticleViewIsAbsent` |
| R-PSO-007 — propiedad cross-plugin del spec | ✅ | G-5: artefactos solo bajo este change; el delta del consumidor vive en `aportar-target-tarifa-a-actualizar-precios` (tarifario) |
| R-CRH-001, 002 — hook de filtros aditivo | ✅ | `CatalogoCoreHookMarkersTest`, `Services/PriceUpdateFilterRegistryTest`, `CatalogoActualizarPreciosControllerTest` |
| R-BU-001..004 — preview/apply, filtros, fórmula, CSRF | ✅ | `Services/CatalogoPriceUpdateServiceTest`, `Services/ArticulosPriceUpdateScopeTest`, `Services/CanonicalArticlePriceBookTest`, `CatalogoActualizarPreciosControllerTest` |
| R-BU-006 — scope opcionales | ✅ | `Services/OpcionalesPriceUpdateScopeTest`, `Services/CanonicalOpcionalPriceBookTest` |
| R-BU-007 — paridad con la página legacy | ✅ | tarifario `PriceUpdateParityTest` (golden replay) |
| R-BU-008 — retiro con redirect | ✅ | tarifario `LegacyRedirectShimTest`, `CatalogoActualizarPreciosControllerTest` |
| R-BU-009 — evento `price_update_persisted` | ✅ | `Services/CatalogoPriceUpdateServiceTest` |
| R-BU-005 — resumen y mensajes | ✅ | sin cambios |

## Evidencia ejecutada

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
Tests: 1706, Assertions: 6771, Warnings: 2, Skipped: 1
ddev exec php vendor/bin/phpunit -c plugins/tarifario/phpunit.xml
Tests: 345, Assertions: 1423, Skipped: 2
ddev exec composer phpstan:plugins
[OK] No errors
```

Los warnings y skipped son preexistentes (baseline del change: 1593 tests).

## Guardrails

| | Resultado |
|---|---|
| G-1 | allowlist congelada en 52 (`testAllowlistIsFrozenAtFiftyTwoEntries`); el diff de `CatalogoCoreHookMarkersTest.php` solo añade los tests del hook nuevo |
| G-2 | 0 hits de `tarifario` en los cinco `Services/` nuevos |
| G-3 | cuatro hooks congelados intactos (`CatalogoCoreHookMarkersTest`) |
| G-4 | 0 hits de `tarifa` en los seams; hook `catalogo_actualizar_precios_filters_after` |
| G-5 | ver R-PSO-007 |
| G-6 | `git status -- base src controller model openspec VERSION` vacío; `VERSION` 0.35.11 |
| G-7 | sin diff en `composer.json`/`composer.lock`/`vendor/` de ambos plugins |

## Auditoría de restos

```
grep -rn "tarif_articulo.html.twig" plugins --include="*.php" --include="*.twig" --exclude-dir=vendor --exclude-dir=openspec -> 0
```

## Desviaciones y limitaciones

- **WU-5.2 diferida** (GATE-5b): dedup de presentación sin requisito funcional.
- **Releases colapsadas**: no se cortaron 1.16.0–1.19.0; una única 1.20.0.
- Desviaciones de diseño 1–7 documentadas en `apply-progress.md`.
- Sin smoke en navegador en este cierre: verificado por suites, golden replay y
  tests estructurales.
