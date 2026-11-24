# Proposal: idiomas-por-tarifa

## Intent

Cada tarifa tiene que poder elegir **en qué idiomas va** y **cuál es su idioma
por defecto**. Con tres idiomas activos en el catálogo, una tarifa concreta
puede ir solo en dos de ellos.

## Scope

Change híbrido. El dato y la gestión viven en `catalogo_core` (dueño de
`tarif_tarifas` y `catalogo_idiomas`); `tarifario` lo consume. El SDD vive aquí
y los toques a `tarifario` se listan en `tasks.md`.

- `catalogo_core`: tabla `tarif_tarifa_idiomas`, modelo `tarif_tarifa_idioma`,
  API en `tarif_tarifa`, formulario y listado de `tarif_tarifas`, limpieza al
  borrar un idioma.
- `tarifario`: el controlador base usa los idiomas de la tarifa; las
  exportaciones Excel de la tarifa llevan una columna de descripción por idioma
  de la tarifa; el importador Excel solo escribe esos idiomas.

Fuera de alcance: la exportación/importación JSON de la tarifa (formato de
copia, mantiene `descripcion_es`/`descripcion_en`), la ficha y el listado de
artículos de `catalogo_core` (las descripciones son del artículo, no de la
tarifa) y un selector de idioma en la vista del tarifario (no existía).

## Approach

- Tabla `tarif_tarifa_idiomas (codtarifa, codidioma, por_defecto)`, PK
  compuesta, FK en cascada a `tarif_tarifas`, sin FK a `catalogo_idiomas` (D12).
- **Una tarifa sin filas no está configurada** y usa todos los idiomas activos
  y el por defecto general: las tarifas existentes no cambian. Guardar "todos
  los idiomas + el por defecto general" vuelve a ese estado, para que la tarifa
  siga incorporando los idiomas que se den de alta después.
- Resolución total en `tarif_tarifa_idioma::resolve()` (pura): habilitados =
  configurados ∩ activos (vacío ⇒ todos los activos); por defecto = el de la
  tarifa si está habilitado, si no el general si está habilitado, si no el
  primero; orden = por defecto primero y el resto por código.
- Cabeceras Excel `Descripción XX`: el asistente de importación ya reconoce
  `descripción es/en/fr`, así el fichero exportado se reimporta.
