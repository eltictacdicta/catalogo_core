# Proposal: la moneda de una tarifa sale del catálogo de divisas

## Intent

Que la moneda de una tarifa del tarifario se elija entre las divisas
configuradas en `admin_divisas` (modelo `divisa`) en lugar de un select con tres
opciones hardcodeadas (`EUR`, `MXN`, `USD`) respaldado por el whitelist cerrado
`tarif_tarifa::ALLOWED_CURRENCIES`. Hoy una instalación con `ARS`, `COP` o `GBP`
configuradas no puede asignarle esa moneda a una tarifa, aunque el resto del
sistema (`show_precio`, `simbolo_divisa`) ya resuelve el símbolo contra el
catálogo.

Es la deuda que dejó registrada el archive de
`ventas-listas-precio-divisa-catalogo`: la misma clase de dato (una columna
`coddivisa`) con dos contratos distintos según la página.

## Scope

### In Scope

- `model/tarif_tarifa.php`: retirar `ALLOWED_CURRENCIES` y el chequeo de
  whitelist de `test()`; conservar normalización y el formato ISO de tres
  caracteres.
- `controller/tarif_tarifas.php`: cargar el catálogo de divisas, resolver la
  moneda por defecto, validar el POST contra lo configurado y exponer
  `divisas` / `coddivisa_defecto` a la vista.
- `View/tarif_tarifas.html.twig`: opciones del select desde el catálogo y JS
  que tolera una moneda histórica ausente del catálogo.
- `tests/Controller/TarifTarifasGuardarTest.php`: validación contra catálogo,
  moneda fuera del viejo whitelist, normalización y moneda histórica.
- `tests/TarifTarifaCoddivisaFormatTest.php`: contrato de formato del modelo y
  desaparición del whitelist.
- `openspec/specs/tarif-tarifa-divisa/spec.md`: R-TAR-CUR-001/003/005/007.

### Out of Scope

- Conversión automática de divisas (R-TAR-CUR-012 sigue vigente: no se toca).
- Migración de datos: las filas existentes conservan su `coddivisa`; solo cambia
  qué valores acepta una escritura nueva.
- El importador PHP y la herramienta Python (`tarifa_coddivisa`), que siguen
  documentando `EUR`/`MXN`/`USD` como ejemplos, no como válidos exclusivos.
- Versionado/release del plugin.

## Approach

1. El catálogo `admin_divisas` es la única fuente de monedas seleccionables; el
   modelo deja de tener una lista hardcodeada y valida solo la forma ISO
   (`^[A-Z0-9]{3}$`), que es lo que la columna `varchar(3)` puede almacenar.
2. El controlador valida el POST contra los códigos configurados antes de
   `save()`, con el mismo criterio que la página de listas de precio: se acepta
   además el valor que la tarifa ya guarda, para que renombrar una tarifa no
   quede bloqueado porque su moneda se borró del catálogo después.
3. La moneda por defecto de una tarifa nueva es la de la sesión/empresa
   (`fs_default_items->coddivisa()`), con respaldo `EUR` y luego la primera
   configurada — euro en la práctica.
4. Si no hay ninguna divisa configurada, la validación degrada al formato ISO
   del modelo en lugar de impedir crear tarifas.
5. La vista resuelve el valor histórico con JS: si la moneda de la tarifa no
   está entre las opciones, se agrega como `(no configurada)` ya seleccionada.

## Risks

- **Retirar una constante pública.** `ALLOWED_CURRENCIES` desaparece; el
  `grep` del repo confirma que solo la usaban el modelo y este controlador. Si
  un plugin de terceros la referenciaba, deja de compilar: es el precio de no
  mentir con una lista que ya no es la verdad.
- **Instalación sin divisas.** Se degrada al formato ISO; cubierto por test y
  consistente con la página de listas de precio.
- **Tarifas con moneda histórica.** Siguen editables; no se pueden elegir para
  tarifas nuevas.
- **Símbolos no catalogados.** `simbolo_divisa()` ya resuelve primero contra el
  catálogo, así que una moneda configurada nueva renderiza su símbolo real.
