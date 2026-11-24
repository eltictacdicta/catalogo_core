# Tasks: idiomas-por-tarifa

## F1 — Modelo (catalogo_core)

- [x] 1.1 `model/table/tarif_tarifa_idiomas.xml` y `model/tarif_tarifa_idioma.php` con `resolve()` puro
- [x] 1.2 `tarif_tarifa`: `idiomas_config()`, `idiomas_efectivos()`, `idiomas_codes()`, `codidioma_defecto()`, `idiomas()`, `save_idiomas()`; `delete()` borra sus idiomas
- [x] 1.3 `catalogo_idioma::delete()` quita el idioma de las tarifas (R-TAR-IDI-005)
- [x] 1.4 Tests: `tests/TarifTarifaIdiomasTest.php` (9), 2 casos en `tests/CatalogoIdiomaDeleteCleanupTest.php`; `IdiomaRegistryFake` gana `table_exists()` y filas de `tarif_tarifa_idiomas`

## F2 — Gestión de tarifas (catalogo_core)

- [x] 2.1 `tarif_tarifas`: `idiomas_formulario()` (validación antes de guardar), `idiomas_tarifa()`, `nombre_idioma()`
- [x] 2.2 Vista: columna "Idiomas" y bloque Incluir/Por defecto en el modal
- [x] 2.3 Tests: `tests/TarifTarifasIdiomasFormTest.php` (10)

## F3 — Consumidores (tarifario)

- [x] 3.1 `extras/tarif_controller.php`: `aplicar_idiomas_tarifa()`, `resolver_codidioma()`; `tarif_catalogo_view` lo reaplica tras `ini_filters()`
- [x] 3.2 `Services/TarifaIdiomasExcel.php`; `export_excel`, `export_excel_template` y `export_excel_gestor` con una columna por idioma
- [x] 3.3 `TarifaExcelRowImporter::idiomaActivo()` limitado a los idiomas de la tarifa
- [x] 3.4 Tests: `tests/Services/TarifaIdiomasExcelTest.php` (5), `tests/Controller/TarifControllerIdiomaTarifaTest.php` (7)

## Verify

- [x] V.1 `catalogo_core` 1741 tests en verde (2 warnings y 1 skip preexistentes); `tarifario` 343 en verde (2 skips preexistentes). `tarif_tarifas.html.twig` compila; `php -l` limpio
- [ ] V.2 Smoke en ddev: crear tarifa con 2 de 3 idiomas y por defecto en_GB; el listado lo muestra; el catálogo de la tarifa sale en inglés; los Excel tienen "Descripción EN" + "Descripción ES"; reimportar el Excel exportado; borrar un idioma y ver que desaparece de la tarifa
