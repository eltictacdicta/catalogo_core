# Delta spec: tarifa-idiomas (nuevo dominio)

## ADDED Requirements

### R-TAR-IDI-001 — Idiomas por tarifa
Cada tarifa PUEDE guardar un subconjunto de los idiomas activos y marcar uno de
ellos como idioma por defecto. Una tarifa sin configuración usa todos los
idiomas activos y el idioma por defecto general.

#### Scenario: tarifa existente sin configurar
- GIVEN idiomas activos es_ES (por defecto), en_GB y fr_FR
- AND la tarifa T no tiene filas en `tarif_tarifa_idiomas`
- THEN T va en es_ES, en_GB y fr_FR con es_ES por defecto

#### Scenario: subconjunto
- GIVEN la tarifa T configurada con en_GB (por defecto) y fr_FR
- THEN T va en en_GB y fr_FR, en ese orden, con en_GB por defecto

### R-TAR-IDI-002 — Resolución total
La resolución nunca devuelve una lista vacía ni un idioma por defecto fuera de
la lista. Un idioma configurado que ya no está activo se ignora; si ninguno
sigue activo, la tarifa se comporta como no configurada. Si el idioma por
defecto de la tarifa deja de estar habilitado, se usa el general si la tarifa
va en él y, si no, el primero por código.

### R-TAR-IDI-003 — Formulario de tarifa
El modal de Tarifas muestra cada idioma activo con "Incluir" y "Por defecto".
Se rechaza guardar sin ningún idioma, con un por defecto no incluido o con un
idioma que no está activo, sin guardar la tarifa. Una tarifa nueva parte con
todos los idiomas y el por defecto general. Todos los idiomas con el por
defecto general se guardan como "sin configuración". El listado muestra los
idiomas de cada tarifa con el por defecto resaltado.

### R-TAR-IDI-004 — Tarifario en el idioma de la tarifa
`tarif_controller` y sus subclases trabajan con los idiomas de la tarifa
seleccionada y, salvo petición, en su idioma por defecto. Un `codidioma` pedido
solo se respeta si la tarifa va en él. `tarif_catalogo_view` vuelve a aplicar
los idiomas tras resolver su tarifa en `ini_filters()`.

### R-TAR-IDI-005 — Borrado
Borrar una tarifa borra sus idiomas. Borrar un idioma lo quita de todas las
tarifas, en la misma transacción.

### R-TAR-IDI-006 — Excel de la tarifa
Las exportaciones Excel de artículos de la tarifa (catálogo, plantilla y
gestor) llevan una columna `Descripción XX` por idioma de la tarifa, el de por
defecto primero, en lugar de "Descripción" + "Descripción EN". El importador
Excel de la tarifa solo escribe descripciones en idiomas de la tarifa.
