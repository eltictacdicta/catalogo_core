# Verify Report: ventas-listas-precio-divisa-catalogo

**Date:** 2026-10-24
**Status:** PASS

Verificación de cierre del trabajo recuperado (implementación previa, sin SDD).

## Evidencia por escenario del delta

| Escenario | Evidencia |
|---|---|
| Currency must belong to the catalog | `test_only_configured_currencies_are_selectable` |
| New list defaults to the session currency | `test_controller_loads_divisas_and_its_default` |
| Stored currency removed from the catalog stays selectable | `test_only_configured_currencies_are_selectable` + vista: opción `(no configurada)` en el `{% if coddivisa_actual not in fsc.coddivisas %}` |
| No catalog configured degrades to format validation | `test_currency_validation_degrades_to_length_check_without_catalog` |
| View renders catalog-driven selects | `test_view_renders_currency_selects_from_configured_divisas` |

## Suites

```
ddev exec php vendor/bin/phpunit -c plugins/catalogo_core/phpunit.xml
OK, but there were issues!
Tests: 1378, Assertions: 5673, Warnings: 2, Skipped: 1

--filter VentasListaPrecio
OK (16 tests, 37 assertions)
```

Los 2 warnings y el test skipped son preexistentes y ajenos a este change.

## Revisión de seguridad

- El `coddivisa` del POST se normaliza (`strtoupper` + `trim`) y se valida contra
  el catálogo; no llega al modelo sin pasar por el whitelist.
- La vista usa `{{ }}` en todos los valores; `test_view_has_no_raw_output`
  protege contra salidas sin escapar.
- El CSRF ya estaba cubierto por `test_post_checks_csrf_before_persistence` y no
  se modificó.

## Limitaciones

- **Sin cobertura de análisis estático en este change.** `phpstan-plugins.neon`
  indexa `catalogo_core` en `scanDirectories` (solo resolución de símbolos) pero
  no analiza `controller/`, así que este controlador queda fuera del gate. Es una
  limitación conocida del perfil, no de este change.
- **Sin smoke en navegador.** La verificación es de suite y de estructura de la
  vista.

## Deuda detectada y no incluida

`controller/tarif_tarifas.php` y `model/tarif_tarifa.php` siguen validando contra
`tarif_tarifa::ALLOWED_CURRENCIES` (`EUR`, `MXN`, `USD`), un whitelist hardcodeado
para la misma clase de dato. La columna `tarifas.coddivisa` es `varchar(3)` sin
constraint, así que ampliarlo es viable en la base, pero implica tocar el modelo
(el `test()` también valida contra esa constante) y la vista de tarifas. Requiere
su propio change.
