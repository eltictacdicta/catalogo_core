# Delta: price-surface-ownership

## MODIFIED Requirements

### Requirement: Una sola página de actualización masiva (R-PSO-005)

Sin cambios en la garantía (una sola página publicada, `catalogo_actualizar_precios`,
y una sola entrada de menú). Cambia el producto cubierto: `scope (articulos |
opcionales) × target (tarifa)`, porque el target `lista` se retira (R-MT-009) y
el target `tarifa` pasa a ser propiedad de `catalogo_core` (R-MT-011).

#### Scenario: La página cubre el producto completo

- GIVEN la página `catalogo_actualizar_precios` y al menos una tarifa activa
- WHEN se inspeccionan sus selectores
- THEN ofrece `scope` con `articulos` y `opcionales`
- AND ofrece el target `tarifa`, esté o no activo el consumidor
