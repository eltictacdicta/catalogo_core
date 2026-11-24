# Tasks: fusionar-paginas-tarifas-listas

## F0 — Moneda en el listado de tarifas

- [x] 0.1 Test `tests/TarifTarifasMonedaColumnTest.php` (R-TAR-CUR-008), en rojo
- [x] 0.2 Columna "Moneda" en `View/tarif_tarifas.html.twig`

## F1 — Espejo síncrono tarifa→lista

- [x] 1.1 Delta spec (R-MT-007, R-MT-008)
- [x] 1.2 Tests: `tests/TarifTarifaListMirrorTest.php` (10) y casos en `tests/Services/CatalogLegacyTableMigrationTest.php`
- [x] 1.3 `model/tarif_tarifa.php`: `saveListMirror()`, `deleteListMirror()`
- [x] 1.4 `CatalogLegacyTableMigration::syncTariffListMirrors()`

## F2 — Solo tarifas en la gestión (R-MT-009, R-MT-010)

- [x] 2.1 `tarif_tarifas` en el menú `catalogo`
- [x] 2.2 Retirada la sección "Listas de precio sin tarifa" (implementada y revertida tras la decisión "solo tarifas")
- [x] 2.3 `CatalogLegacyTableMigration::promoteListsWithoutTariff()` antes del backfill del espejo, con 4 tests

## F3 — Retiro de la UI de listas (R-MT-009)

- [x] 3.1 `ventas_listas_precio`: redirect a `tarif_tarifas`, sin menú ni plantilla; vista borrada
- [x] 3.2 Ficha de artículo: fuera "Precios por lista" (`loadPreciosPorLista`, `guardarPreciosPorLista`, `dispatchPriceEditFilter` y sus 2 tests)
- [x] 3.3 Selector de asignación de familias rotulado "Tarifa"
- [x] 3.4 Listado de artículos: fuera el botón "Listas de precio" y `multi_tariff`
- [x] 3.5 `MultiTariffFlagInertTest`, `VentasListaPrecioControllerTest`, `CodlistaNamingAuditTest` adaptados

## F4 — Destino `tarifa` en `catalogo_core` (R-MT-011)

- [x] 4.1 `Services/TarifaPriceUpdateTarget.php` y `Services/TarifaArticlePriceBook.php` movidos desde `tarifario`, con su test
- [x] 4.2 `PriceUpdateTargetRegistry`: base `tarifa`; retirado `ListaPriceUpdateTarget`
- [x] 4.3 `catalogo_actualizar_precios` sin `multi_tariff`/`tariffs_active`; aviso sin destinos enlaza a Tarifas
- [x] 4.4 Retirado `PriceListAvailability` y su test (sin consumidores)
- [x] 4.5 `tarifario` 3.7.0: no registra target; borrados sus `TarifaPriceUpdateTarget`/`TarifaArticlePriceBook`; tests de registro y paridad adaptados. Requiere `catalogo_core` 1.22.0

## F5 — `opciones_catalogo`

- [x] 5.1 Menú `catalogo` y `#[AdminOnly]` (el 4.º argumento `TRUE` es obsoleto y nunca restringió)
- [x] 5.2 Retirada la opción multi-tarifa (bloque de la vista, POST, `CatalogoOptions::KEY_MULTI_TARIFF`, `multiTariffEnabled()`, `setMultiTariff()`); `saveAll()` borra la clave antigua de `config2.ini` (R-CO-003/004)

## Release

- [x] R.1 `catalogo_core` 1.22.0, `tarifario` 3.7.0

## Verify

- [x] V.1 `catalogo_core` 1720 tests en verde (2 warnings y 1 skip preexistentes, ajenos al change); `tarifario` 331 en verde (2 skips preexistentes). Twig compilado; `php -l` limpio. PHPStan no analiza `catalogo_core` (solo `scanned`)
- [ ] V.2 Smoke en ddev: Tarifas y Opciones bajo Catálogo; `DEF` convertida en tarifa tras el primer arranque; ficha sin "Precios por lista"; Actualizar precios con destino Tarifa; `?page=ventas_listas_precio` redirige
