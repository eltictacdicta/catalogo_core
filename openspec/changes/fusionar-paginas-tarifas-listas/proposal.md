# Proposal: fusionar-paginas-tarifas-listas

## Intent

`catalogo_core` ofrecía dos conceptos para lo mismo: **tarifas**
(`tarif_tarifas`, `codtarifa`) y **listas de precio**
(`catalogo_listas_precio`, `codlista`), con las mismas columnas (`nombre`,
`activa`, `por_defecto`, `coddivisa`), dos páginas de gestión y dos editores de
precio por artículo. `PriceListAvailability::listUiEnabled()` hacía que nunca
estuvieran operativas a la vez, pero el operador seguía viendo dos conceptos.

**Decisión de producto (2026-11-17): solo existen tarifas de precio.** La lista
de precio deja de ser un concepto visible: queda como **espejo interno** de
cada tarifa en `catalogo_listas_precio`, porque las tablas canónicas de
opcionales, grupos y estructura de familias, el resolver de precios y `tpvmod`
están indexados por `codlista` (con `codlista = codtarifa`).

Sin fusionar tablas ni renombrar claves.

## Evidence (verified 2026-11-17)

- Esquemas equivalentes: `model/table/tarif_tarifas.xml` y
  `model/table/catalogo_listas_precio.xml` solo difieren en el nombre de la clave.
- 5 tablas con FK `ON DELETE CASCADE` a `catalogo_listas_precio.codlista`:
  `catalogo_articulo_precios`, `catalogo_opcional_precios`,
  `catalogo_grupo_tarifas`, `catalogo_familia_estructura`,
  `catalogo_articulo_familia_estructura`.
- **Espejo incompleto (defecto corregido en F1)**: `tarif_tarifa::save()` y
  `delete()` no escribían `catalogo_listas_precio`; el único puente
  (`syncMissingPriceLists()`) solo creaba listas referenciadas por
  `tarif_opcional_precios`.
- La opción `multi_tariff` de `opciones_catalogo` solo gobierna la UI de listas
  (`PriceListAvailability`); las tarifas no dependen de ella.
- Superficies de lista fuera de la página de gestión: sección "Precios por
  lista" de la ficha de artículo (`catalogo_articulo_precios`), botón "Listas de
  precio" del listado de artículos, destino `lista` de
  `catalogo_actualizar_precios`.
- El destino `tarifa` de la actualización masiva vive en `tarifario`
  (`Services/TarifaPriceUpdateTarget.php`, `TarifaArticlePriceBook.php`), pero
  solo usa modelos de `catalogo_core` (`tarif_tarifa`, `tarif_articulo_precio`);
  el historial ya es opcional (`class_exists`).
- BD local (ddev): `catalogo_articulo_precios` vacía; `tarif_articulo_precios`
  con 2535 filas de `INF2026`; una lista sin tarifa (`DEF`).

## Scope

### In Scope

- **F0 — Moneda en el listado de tarifas** (R-TAR-CUR-008). Hecho.
- **F1 — Espejo síncrono tarifa→lista** (R-MT-007) y backfill (R-MT-008). Hecho.
- **F2 — Solo tarifas en la gestión.** `tarif_tarifas` en el menú `catalogo`,
  sin sección de listas. Migración idempotente que **convierte cada lista sin
  tarifa en tarifa** con el mismo código, nombre, moneda y estado (R-MT-010).
- **F3 — Retiro de la UI de listas.** `ventas_listas_precio` redirige a
  `tarif_tarifas`; fuera la sección "Precios por lista" de la ficha y el botón
  del listado; el selector de la asignación de familias pasa a decir "Tarifa".
- **F4 — Destino `tarifa` en `catalogo_core`.** `TarifaPriceUpdateTarget` y
  `TarifaArticlePriceBook` pasan a `catalogo_core` y son el destino base;
  se retira `ListaPriceUpdateTarget`. `tarifario` deja de registrarlo
  (change acoplado en `tarifario`, release 3.7.0).
- **F5 — `opciones_catalogo` en el menú `catalogo`**, con `#[AdminOnly]`, y
  retirada de la opción multi-tarifa, que ya no gobierna nada (R-CO-003/004).
- Release: `catalogo_core` 1.22.0.

### Out of Scope

- Fusionar `tarif_tarifas` y `catalogo_listas_precio` en una tabla o renombrar
  `codtarifa`/`codlista`.
- Borrar `catalogo_articulo_precios`, `CatalogoPriceResolver` o
  `CanonicalArticlePriceBook`: dejan de tener escritor desde la UI, pero se
  conservan los datos; su retirada es un change posterior.
- Conversión de divisas.

## Decisions (2026-11-17)

1. **Solo tarifas.** Las listas sin tarifa se convierten en tarifas (no se
   ocultan ni se borran).
2. **Menú `catalogo`** para `tarif_tarifas` y `opciones_catalogo`. `fs_pages`
   y `fs_roles_access` van por nombre de página, no por carpeta: los permisos se
   conservan y `updateExistingPage()` actualiza `folder` en el siguiente arranque.
3. **El destino `tarifa` es de `catalogo_core`**, de modo que la actualización
   masiva funciona sin `tarifario`.

## Risks

| Riesgo | Mitigación |
|---|---|
| Borrar un espejo con datos en las 5 tablas en cascada | `delete()` desactiva el espejo si está referenciado (R-MT-007) |
| Dos `por_defecto` distintos | Upsert y backfill exclusivos en ambas tablas; la conversión lista→tarifa no promueve `por_defecto` si ya hay tarifa por defecto |
| Instalaciones con precios en `catalogo_articulo_precios` | Se conservan; quedan sin editor (Out of Scope documentado) |
| Doble registro del destino `tarifa` mientras `tarifario` < 3.7.0 | El registro es "first wins" y ambas implementaciones son equivalentes |
