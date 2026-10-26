# Tasks: tarifas-divisa-catalogo

Strict TDD: los tests RED se escribieron y verificaron antes de tocar el código
de producción (`TarifTarifasGuardarTest` 4 fallos, `TarifTarifaCoddivisaFormatTest`
2 fallos), después se implementó hasta GREEN.

## T1 — Modelo `tarif_tarifa`

- [x] **T1.1** Retirar `public const ALLOWED_CURRENCIES` y su validación de
      `test()`; conservar `trim()` + `strtoupper()` y agregar el chequeo de forma
      ISO `^[A-Z0-9]{3}$` con mensaje explícito.
- [x] **T1.2** Actualizar docblocks de la clase y de `$coddivisa` (el conjunto
      seleccionable es el catálogo, no una lista hardcodeada).

## T2 — Controlador `tarif_tarifas`

- [x] **T2.1** `require_once` del modelo `divisa` y propiedades `divisas` /
      `coddivisa_defecto` expuestas a la vista.
- [x] **T2.2** `loadDivisas()` y `configuredDivisaCodes()` (seam
      `protected` para test sin base de datos).
- [x] **T2.3** `resolveDefaultDivisa()`: divisa de sesión/empresa, respaldo
      `EUR`, luego la primera del catálogo.
- [x] **T2.4** Validación del POST contra el catálogo en `guardar_tarifa()`,
      con `isSelectableDivisa()`: acepta la moneda ya guardada por la tarifa y
      degrada al formato ISO cuando no hay ninguna configurada.

## T3 — Vista `tarif_tarifas`

- [x] **T3.1** Opciones del select de moneda generadas desde `fsc.divisas`, con
      opción de respaldo si el catálogo está vacío y `data-default` con la
      moneda por defecto.
- [x] **T3.2** JS `seleccionar_coddivisa()`: tolera una moneda histórica
      ausente del catálogo agregándola como `(no configurada)` antes de
      seleccionarla; la usan tanto el alta como la edición, y las opciones
      históricas se limpian en cada apertura para que una tarifa nueva no pueda
      elegirlas (hallazgo de CodeRabbit).
- [x] **T3.3** Enlace y aclaración en el `help-block` hacia `admin_divisas`.

## T4 — Pruebas

- [x] **T4.1** `TarifTarifaCoddivisaFormatTest` (nuevo): cualquier código ISO
      pasa el modelo, los malformados se rechazan, la constante desapareció.
- [x] **T4.2** `TarifTarifasGuardarTest`: rechazo de moneda fuera del catálogo,
      aceptación de una moneda configurada fuera del viejo whitelist (ARS),
      normalización de minúsculas, moneda histórica editable, degradación sin
      catálogo y estructura de la vista.

## T5 — Verificación y cierre

- [x] **T5.1** Suite del plugin verde: `1384 tests, 5685 assertions`
      (2 warnings y 1 skipped preexistentes).
- [x] **T5.2** `composer phpstan:plugins` sin errores.
- [x] **T5.3** Parse de `View/tarif_tarifas.html.twig` con Twig 3 (sin render de
      navegador).
- [x] **T5.4** Delta sincronizado a `openspec/specs/tarif-tarifa-divisa/spec.md`.

## Definition of done

1. La moneda de una tarifa no se puede elegir fuera de lo configurado en
   `admin_divisas`.
2. No queda ningún whitelist hardcodeado de divisas en el plugin.
3. Una tarifa con moneda histórica sigue siendo editable.
