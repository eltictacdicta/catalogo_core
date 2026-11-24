# Delta: batch-price-update

## MODIFIED Requirements

### Requirement: Criterios de filtro (R-BU-002)

La actualización MUST aceptar, para el target base `tarifa` (R-MT-011): la
tarifa (`codtarifa`), familia (`codfamilia`), flag de inclusión de subfamilias
resuelto recursivamente sobre la jerarquía `familia` (`madre`/`hijas()`/
`aux_all()`, sin cambio de modelo) y los filtros declarados por consumidores en
`PriceUpdateFilterRegistry`. Los filtros MUST combinarse con AND. Con la
inclusión de subfamilias apagada, MUST afectar solo a los miembros directos de
la familia. El target `lista` y su filtro `codlista` se retiran (R-MT-009).

### Requirement: Fórmula de precio (R-BU-003)

El precio prospectivo MUST calcularse como `round(p * (1 + pct/100), 2)`, donde
`p` es el precio efectivo actual para el par (fila, target) y `pct` el
porcentaje. El resultado MUST redondear half-up a 2 decimales y MUST ser la
misma para cualquier target registrado y para ambos scopes.

#### Scenario: Aumento porcentual con redondeo

- GIVEN un precio efectivo de 10.005 y pct 10
- WHEN se aplica la fórmula
- THEN el precio prospectivo es exactamente 11.01

#### Scenario: Disminución porcentual

- GIVEN un precio efectivo de 10.00 y pct -25
- WHEN se aplica la fórmula
- THEN el precio prospectivo es 7.50
