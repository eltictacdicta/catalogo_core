# Artículos — Import/Export Excel (plugin catalogo_core)

## Purpose

Importación y exportación Excel de artículos en `index.php?page=ventas_articulos`.
Campos principales: referencia, descripción, precio (PVP sin IVA). Columnas
opcionales mapeables: familia, fabricante, impuesto, bloqueado.

## Requirements

### Requirement: Permiso can_import_export

La página `ventas_articulos` MUST exponer import/export solo cuando
`can_import_export === true`: administradores o usuarios con acceso a la página.

### Requirement: Export Excel

MUST ofrecer tres descargas:

1. **Export filtrado** — artículos visibles con los filtros activos de la lista.
2. **Export completo** — todos los artículos.
3. **Plantilla** — columnas base con fila de ejemplo.

Columnas exportadas (orden): Referencia, Descripción, Precio, Cód. Familia,
Cód. Fabricante, Impuesto, Bloqueado.

### Requirement: Import wizard 3 pasos

1. Subir `.xlsx` → token temporal en `tmp/catalogo_excel_wizard_{token}.xlsx`.
2. Mapear columnas → auto-suggest por alias; dropdown por columna.
3. Aplicar vía SSE (`process_excel_wizard.php?action=start`).

Modos: `create_if_missing` (default) y `update_only`. Match key: `referencia`.

### Requirement: Persistencia

- Precio Excel → campo `pvp` (sin IVA).
- Crear: defaults del modelo `articulo` para campos no mapeados.
- Actualizar: solo campos mapeados con valor no vacío.
