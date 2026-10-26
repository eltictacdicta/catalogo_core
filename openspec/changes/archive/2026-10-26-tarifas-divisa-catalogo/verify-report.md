# Verify Report: tarifas-divisa-catalogo

**Change**: `tarifas-divisa-catalogo`
**SDD home**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
**Verdict**: **PASS** — 0 CRITICAL
**Date**: 2026-10-26

## Resumen

La moneda de una tarifa dejó de elegirse de un select con tres opciones
hardcodeadas y pasó a construirse desde el catálogo de `admin_divisas`. El
whitelist cerrado `tarif_tarifa::ALLOWED_CURRENCIES` desapareció: el modelo valida
solo la forma ISO de tres caracteres y el controlador valida contra lo configurado,
con el mismo criterio que la página de listas de precio.

## Criterios y resultados

| # | Requisito | Resultado | Evidencia |
|---|---|---|---|
| 1 | R-TAR-CUR-001 — normalización y default `EUR` | ✅ | `TarifTarifaCoddivisaFormatTest::test_any_iso_shaped_currency_passes_the_model` |
| 2 | R-TAR-CUR-003/005 — forma ISO, sin whitelist | ✅ | `test_codes_that_are_not_exactly_three_alphanumeric_chars_are_rejected`, `test_closed_currency_whitelist_constant_is_gone` |
| 3 | R-TAR-CUR-007 — validación contra catálogo | ✅ | `TarifTarifasGuardarTest::test_guardar_tarifa_rejects_a_currency_missing_from_the_catalog` |
| 4 | Moneda configurada fuera del viejo whitelist | ✅ | `test_guardar_tarifa_accepts_a_catalog_currency_outside_the_old_whitelist` |
| 5 | Moneda histórica editable | ✅ | `test_guardar_tarifa_keeps_a_stored_currency_dropped_from_the_catalog` |
| 6 | Degradación sin catálogo | ✅ | `test_guardar_tarifa_degrades_to_format_validation_without_catalog` |
| 7 | Select desde el catálogo | ✅ | `test_view_builds_the_currency_options_from_the_catalog` |
| 8 | Resto del contrato (`R-TAR-CUR-002/004/006/008..013`) intacto | ✅ | sin cambios en schema, `install()`, migración, copias ni vistas de precios |

## Evidencia ejecutada

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
Tests: 1385, Assertions: 5687, Warnings: 2, Skipped: 1
```

Los 2 warnings y el skipped son preexistentes (idénticos antes del change:
1378/5673 → +7 tests del change: 6 de controlador + 3 de modelo, menos 2
reescritos).

```
ddev exec composer phpstan:plugins
[OK] No errors
```

```
php -l controller/tarif_tarifas.php     -> No syntax errors
php -l model/tarif_tarifa.php           -> No syntax errors
Twig 3 parse de View/tarif_tarifas.html.twig -> PARSE OK
```

## Auditoría de restos

```
grep -rn "ALLOWED_CURRENCIES" plugins/  -> 0 resultados
grep -rn "EUR, MXN o USD" plugins/     -> 0 resultados
```

## Limitaciones

- Sin smoke en navegador (modal admin con datos reales); validado por parse de
  Twig 3 y test estructural de la vista.
- `phpstan-plugins.neon` indexa `catalogo_core` en `scanDirectories`: sin
  errores, pero eso no prueba tipado completo del controlador.

## Revisión de CodeRabbit

`coderabbit review --agent --base-commit <padre>` sobre el commit del change:
1 hallazgo `minor`, corregido.

- **Selector de moneda — opciones históricas persistentes.** La opción
  `(no configurada)` que agregaba la edición sobrevivía al abrir "Nueva
  tarifa", ofreciendo una moneda que el controlador rechaza para tarifas
  nuevas. `seleccionar_coddivisa()` ahora elimina las opciones marcadas con
  `data-historica` antes de seleccionar y solo agrega la de la tarifa en
  edición.

## Riesgos residuales

- `simbolo_divisa()` resuelve primero contra el catálogo y cae a un mapa fijo
  (`EUR`, `USD`, `GBP`, `MXN`) para códigos no catalogados: una tarifa histórica
  con una moneda borrada mostrará `€` hasta que se elija una configurada.
- Retirar `ALLOWED_CURRENCIES` es un cambio de API del modelo; `grep` confirma
  que ningún consumidor del repo lo usaba.
