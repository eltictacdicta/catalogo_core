# Proposal: Import/Export Excel de artículos

## Intent

Añadir importador wizard y exportador Excel en `ventas_articulos` del plugin
`catalogo_core`, alineado con el patrón de `tarifario` pero simplificado al
dominio de artículos ERP.

## Scope

- Servicios en `plugins/catalogo_core/Services/`
- Controller `VentasArticulos`, vistas Twig, JS vanilla
- Entry SSE `process_excel_wizard.php`
- Tests PHPUnit + fixtures xlsx

## Out of scope

- Extracción de utilidades genéricas al core
- Integración con tarifario
