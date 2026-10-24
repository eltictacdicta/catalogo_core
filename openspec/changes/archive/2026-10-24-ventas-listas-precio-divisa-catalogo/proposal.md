# Proposal: la divisa de las listas de precio sale del catálogo

## Intent

Que la divisa de una lista de precio se elija entre las configuradas en
`admin_divisas` (modelo `divisa`) en lugar de escribirse a mano en un input de
tres caracteres. Un input libre permitía crear listas con divisas inexistentes en
el sistema, que después rompen cualquier conversión o símbolo.

## Nota de recuperación

Este change se escribe **a posteriori**. La implementación se hizo el 2026-10-24
y quedó sin commitear ni registrar en el SDD del plugin; al retomarla se verificó
contra la suite (1378 tests verdes) y se documentó acá. No hay tareas inventadas:
el alcance refleja exactamente el código que existe.

## Scope

### In Scope

- `controller/ventas_listas_precio.php`: cargar el catálogo de divisas, resolver
  la divisa por defecto y validar el POST contra lo configurado.
- `view/ventas_listas_precio.html.twig`: `<select>` de divisas en el alta y en
  cada fila de edición, con la divisa ya guardada siempre seleccionable.
- `tests/VentasListaPrecioControllerTest.php`: cobertura del catálogo, del
  default, del whitelist y de la degradación sin catálogo.

### Out of Scope

- `controller/tarif_tarifas.php` y `model/tarif_tarifa.php`, que mantienen su
  propio whitelist `ALLOWED_CURRENCIES` (EUR/MXN/USD). Es una página distinta
  con su propio modelo; requiere su change.
- Migración de datos: no hay filas que corregir, la validación solo aplica a
  escrituras nuevas desde la UI.

## Approach

1. El catálogo es la única fuente de divisas seleccionables.
2. La divisa por defecto de una lista nueva es la de la sesión/empresa
   (`fs_default_items->coddivisa()`), con EUR como respaldo y, si tampoco está,
   la primera configurada.
3. La divisa que una lista ya guarda sigue siendo seleccionable aunque se haya
   borrado del catálogo: así una edición no relacionada (cambiar el nombre) no
   queda bloqueada por un dato histórico.
4. Si no hay ninguna divisa configurada, la validación degrada al formato de tres
   caracteres del modelo, para no impedir crear listas en una instalación vacía.

## Risks

- **Instalación sin `divisas` configuradas.** Se degrada al chequeo de formato en
  lugar de bloquear el alta; es una decisión explícita, cubierta por test.
- **Divisas históricas fuera del catálogo.** Quedan seleccionables solo en la
  lista que ya las tenía; no se pueden elegir para listas nuevas.
