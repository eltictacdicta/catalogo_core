```yaml
schema: gentle-ai.archive-result/v1
change: migrar-familias-tarifario-a-catalogo-core
sdd_home: plugins/catalogo_core/openspec/
ownership: plugin-local
archived_at: 2026-10-26
archive_path: plugins/catalogo_core/openspec/changes/archive/2026-10-26-migrar-familias-tarifario-a-catalogo-core/
verify_verdict: pass
critical_findings: 0
archive_status: complete
specs_created: 1   # familias-jerarquia-catalogo (8 requirements)
specs_merged: 1    # multi-tariff-pricing (R-MT-002 modified, R-MT-006 added)
in_repo_tasks: 22/22
deferred_resolved: 4   # 3.1/3.2/3.3/3.5 retargeted to tarif_familias
```

## Archive Report

**Change**: `migrar-familias-tarifario-a-catalogo-core`
**SDD home**: `plugins/catalogo_core/openspec/` (`ownership: plugin-local`)
**Archived to**: `plugins/catalogo_core/openspec/changes/archive/2026-10-26-migrar-familias-tarifario-a-catalogo-core/`
**Archive status**: **COMPLETE** — 0 CRITICAL, all 22 tasks closed.

### Resumen

La jerarquía de familias del tarifario dejó de ser datos por tarifa en tablas
legacy y pasa a ser estructura canónica por lista de precio, sin destruir nada y
detrás de un flag que el operador enciende cuando decide salir a producción.

### Qué se entregó, por fase

| Fase | Contenido |
|---|---|
| 1 | Tablas y modelos canónicos `catalogo_familia_estructura` y `catalogo_articulo_familia_estructura`, flag `familyStructureEnabled()`, orden de instalación |
| 2 | `FamiliaEstructuraMigration` (set-based, idempotente, dual-engine, no destructivo, transacción por paso) y `FamiliaTreeGuard` (ciclos) |
| 3 | Asignación de artículos por lista en `VentasArticulo`; árbol y estructura por lista en `tarif_familias`, con guard de ciclos y evento de permisos |
| 4 | Página nueva `ventas_catalogo_view` con `CatalogoViewProvider`; hooks congelados; 11 claves de traducción; auditoría de desacople |
| 5 | Evidencia de regresión, idempotencia e inercia con el flag apagado |

### Las cuatro tareas diferidas, resueltas

3.1, 3.2, 3.3 y 3.5 quedaron bloqueadas a mitad del change porque apuntaban a
`VentasFamilias`, retirada el 2026-09-06 (dos días después de escribirse el
design). El usuario eligió `tarif_familias` como destino, y al inspeccionarlo
apareció que **ya implementaba el árbol de familias** sobre la tabla legacy
`tarif_tarifa_familia`. Por eso el trabajo real fue un **cambio de fuente de
datos** en un controlador de 1.492 líneas, no "agregar columnas".

Se resolvió con un **dual-source** gobernado por el flag:

- **Flag off** = comportamiento actual intacto. Garantía verificada de la forma
  más exigente disponible: `TarifFamiliasControllerContractTest` pasa **sin
  modificarse** (último cambio `f0513271`, anterior a este change).
- **Flag on** = misma UX sobre `catalogo_familia_estructura` por `codlista`, con
  una consulta por render, upsert por modelo canónico, guard de ciclos antes de
  cualquier escritura de `madre`, y evento de permisos fail-closed.

### Specs sincronizadas

| Dominio | Acción | Detalle |
|---|---|---|
| `familias-jerarquia-catalogo` | **CREADA** | 8 requirements / 20 escenarios, cuerpo byte-idéntico al delta |
| `multi-tariff-pricing` | **MERGE** | `R-MT-002` reemplazado y `R-MT-006` agregado; ambos byte-idénticos |

Ningún canónico quedó con marcadores de delta. Se corrigió en el proceso un
encabezado `## ADDED Requirements` que se colaba al extraer el bloque modificado.

### Evidencia

- Plugin: **1530 tests / 6325 assertions** (2 warnings + 1 skipped preexistentes).
- Raíz: **4994 tests / 19326 assertions**, 12 fallos **todos** `Tests\OidcProvider\*`
  por estado de base local (FK ausente, collation `utf8mb3` vs `utf8mb4`),
  preexistentes y ajenos a este change.
- `phpstan:plugins`: `[OK] No errors`.
- `HookRegistrationTest` de `tarifario`: 4/4, los hooks congelados siguen vivos.

### Limitaciones documentadas

1. **Sin smoke autenticado** del árbol con el flag encendido. Verificado por
   render real del template más tests de controlador.
2. **Excel queda legacy-only** con el flag on (el controlador lo rechaza con
   error explícito). Pasarlo a la estructura canónica es otro change.
3. La convivencia post-migración es asimétrica y aceptada por D6: las escrituras
   van solo a las tablas canónicas; las tablas `tarif_*` quedan congeladas y la UI
   de `tarifario` seguirá mostrando su copia hasta su propio change de deprecación.

### Deuda ajena detectada (no incluida)

Lecturas legacy preexistentes de `tarif_tarifa_*` en `Init.php`, los servicios de
características y `VentasArticulo`. No las introduce este change; quedaron
pineadas con nombre y motivo en `LEGACY_REFERENCE_BASELINE` del test de auditoría.

### Mecánica de archivo

Snapshot `sha256sum` de los 6 archivos antes del `mv`, comparado con el destino:
**idéntico**. `archive-report.md` es aditivo y se escribió después del movimiento.
