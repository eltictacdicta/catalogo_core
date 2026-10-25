# Proposal: la spec de la divisa de tarifa vuelve a vivir con su código

## Intent

Cerrar la deriva de propiedad entre código y especificación que dejó la
migración `migrar-tarif-tarifas-a-catalogo-core` (2026-09-13): el modelo
`tarif_tarifa` y el controlador `tarif_tarifas` se movieron a `catalogo_core`,
pero sus requirements (`R-TAR-CUR-001` … `R-TAR-CUR-013`) siguieron en
`plugins/tarifario/openspec/specs/tarifario/tarifa/spec.md`.

Consecuencia práctica: cualquier cambio en la divisa de tarifa —incluido el
whitelist cerrado que `R-TAR-CUR-003`/`R-TAR-CUR-005` imponen— exigía editar el
store de un plugin que ya no es dueño del modelo, o contradecir una spec sin
tocarla.

## Scope

### In Scope

- Crear `plugins/catalogo_core/openspec/specs/tarif-tarifa-divisa/spec.md` con los
  11 requirements movidos, **cuerpos byte-idénticos** y los ids `R-TAR-CUR-*`
  intactos.
- Reemplazar el archivo de `tarifario` por un puntero al nuevo hogar canónico.
- Registrar el movimiento y verificarlo.

### Out of Scope

- Cambiar comportamiento: el whitelist cerrado sigue vigente tal como lo manda
  `R-TAR-CUR-003`/`R-TAR-CUR-005`.
- Ampliar divisas permitidas, validar contra el catálogo `divisas` o tocar
  modelo, controlador o vista. Eso es un change posterior, ahora sí con la spec
  en el store correcto.
- Normalizar el resto de las specs del plugin que arrastran marcadores de delta.

## Approach

1. **Mover, no reescribir.** Los requirements se extraen del archivo original
   con un rango de líneas y se ensamblan con una cabecera canónica nueva. Un
   `diff` entre el bloque movido y el original debe ser vacío.
2. **Puntero, no borrado.** El archivo de `tarifario` queda como nota de
   migración: referencias existentes a `R-TAR-CUR-010` (por ejemplo en el archive
   de `tarifario-catalogo-hook-integration`) siguen resolviendo a un lugar que
   dice dónde está la fuente de verdad.
3. **IDs preservados.** No se renombra ningún `R-TAR-CUR-*`: son el contrato de
   referencia entre stores.

## Risks

- **Referencias externas al path viejo.** Fuera de este repositorio no hay forma
  de verificarlas; el puntero las cubre en lectura, no en herramienta.
- **Doble fuente si alguien edita el puntero.** El archivo de `tarifario` lo dice
  explícitamente: no agregar requirements ahí.
