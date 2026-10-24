# Tasks: ventas-listas-precio-divisa-catalogo

Registro retroactivo: la implementación existía sin commitear ni documentar.
Todas las tareas se verificaron contra el código y la suite antes de marcarlas.

## T1 — Controlador

- [x] **T1.1** Cargar el catálogo de divisas (`\divisa()->all()`) en
      `private_core()` y exponer `divisas`, `coddivisas` y `coddivisa_defecto`.
- [x] **T1.2** Resolver el default desde `fs_default_items->coddivisa()`, con
      respaldo `EUR` y luego la primera configurada.
- [x] **T1.3** Validar el `coddivisa` del POST contra lo configurado, aceptando
      siempre el valor que la lista ya guarda.
- [x] **T1.4** Degradar al chequeo de formato de tres caracteres cuando el
      catálogo está vacío.

## T2 — Vista

- [x] **T2.1** Select de divisas en el formulario de alta, con la divisa por
      defecto preseleccionada y una opción de respaldo si el catálogo está
      vacío.
- [x] **T2.2** Select por fila en el listado de edición, incluyendo la divisa
      histórica como `(no configurada)` cuando ya no está en el catálogo.

## T3 — Pruebas

- [x] **T3.1** `test_view_renders_currency_selects_from_configured_divisas`.
- [x] **T3.2** `test_controller_loads_divisas_and_its_default`.
- [x] **T3.3** `test_only_configured_currencies_are_selectable`.
- [x] **T3.4** `test_currency_validation_degrades_to_length_check_without_catalog`.

## T4 — Verificación

- [x] **T4.1** Suite del plugin verde: `1378 tests, 5673 assertions` (2 warnings y
      1 skipped preexistentes).
- [x] **T4.2** Delta sincronizado a `openspec/specs/multi-tariff-pricing/spec.md`.

## Definition of done

1. La divisa no se puede escribir a mano en ninguna lista de precio.
2. Una divisa histórica no bloquea la edición de una lista existente.
3. Sin catálogo configurado, el alta sigue siendo posible.
4. La spec canónica de `multi-tariff-pricing` describe la regla.
